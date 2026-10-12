<?php
/** Private encrypted outbox. Retains no plaintext email or HTTP diagnostics. */
if (!defined('ABSPATH')) { exit; }

final class CP_Guide_Outbox {
    const HOOK = 'cp_guide_outbox_process';
    const TTL = 604800;
    const MAX_ATTEMPTS = 10;

    public static function register() {
        add_action('init', [__CLASS__, 'boot']);
        add_action(self::HOOK, [__CLASS__, 'process']);
    }

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'cp_guide_outbox';
    }

    public static function boot() {
        if (!CP_Guide_Submission::enabled()) { return; }
        self::install();
        if (!wp_next_scheduled(self::HOOK)) { wp_schedule_event(time() + 60, 'hourly', self::HOOK); }
    }

    public static function install() {
        global $wpdb;
        if (get_option('cp_guide_outbox_schema') === '2') { return true; }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $collation = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            event_id varchar(36) NOT NULL,
            payload longtext NULL,
            state varchar(16) NOT NULL DEFAULT 'pending',
            is_test_capture tinyint(1) NOT NULL DEFAULT 0,
            attempts int unsigned NOT NULL DEFAULT 0,
            last_error varchar(32) NULL,
            next_attempt datetime NOT NULL,
            created_at datetime NOT NULL,
            expires_at datetime NOT NULL,
            delivered_at datetime NULL,
            lease_token varchar(36) NULL,
            lease_until datetime NULL,
            PRIMARY KEY  (event_id),
            KEY due (state,next_attempt),
            KEY expiry (expires_at)
        ) $collation;");
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) { return false; }
        // A failed dbDelta upgrade must not be marked complete merely because
        // the previous table exists.
        if (!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s", 'is_test_capture'))) { return false; }
        update_option('cp_guide_outbox_schema', '2', false);
        return true;
    }

    public static function seal($event) {
        if (!function_exists('openssl_encrypt')) { return null; }
        $iv = random_bytes(12);
        $key = hash('sha256', 'cp-guide-outbox-v1\0' . wp_salt('auth'), true);
        $tag = '';
        $cipher = openssl_encrypt(wp_json_encode($event), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'cp-guide-outbox-v1');
        return $cipher === false ? null : base64_encode($iv . $tag . $cipher);
    }

    public static function open($sealed) {
        $bytes = base64_decode($sealed, true);
        if ($bytes === false || strlen($bytes) < 29) { return null; }
        $key = hash('sha256', 'cp-guide-outbox-v1\0' . wp_salt('auth'), true);
        $plain = openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($bytes, 0, 12), substr($bytes, 12, 16), 'cp-guide-outbox-v1');
        return $plain === false ? null : json_decode($plain, true);
    }

    public static function enqueue($event) {
        global $wpdb;
        $payload = self::seal($event);
        if ($payload === null || !self::install()) { return false; }
        $now = time();
        $saved = $wpdb->insert(self::table(), [
            'event_id' => $event['signup_event_id'], 'payload' => $payload,
            'state' => 'pending', 'attempts' => 0, 'next_attempt' => gmdate('Y-m-d H:i:s', $now),
            'is_test_capture' => !empty($event['is_test_capture']) ? 1 : 0,
            'created_at' => gmdate('Y-m-d H:i:s', $now), 'expires_at' => gmdate('Y-m-d H:i:s', $now + self::TTL),
        ]);
        if ($saved !== 1) { return false; }
        // Scheduling failure cannot discard the durable row; recurring cron recovers it.
        wp_schedule_single_event($now + 1, self::HOOK);
        return true;
    }

    public static function transport_config() {
        $url = defined('CP_GUIDE_ANALYTICS_URL') ? CP_GUIDE_ANALYTICS_URL : '';
        $key = defined('CP_GUIDE_ANALYTICS_SIGNING_KEY') ? CP_GUIDE_ANALYTICS_SIGNING_KEY : '';
        if (!is_string($url) || !is_string($key) || strlen($key) < 32 || strlen($key) > 512
            || strpos($url, 'https://') !== 0 || !wp_http_validate_url($url)
            || wp_parse_url($url, PHP_URL_QUERY) || wp_parse_url($url, PHP_URL_FRAGMENT)) { return null; }
        return ['url' => $url, 'key' => $key];
    }

    public static function sign($body, $timestamp, $nonce, $key) {
        return hash_hmac('sha256', "cp-guide-submission-v1\n" . $timestamp . "\n" . $nonce . "\n" . hash('sha256', $body), $key);
    }

    public static function deliver($event, $config) {
        $body = wp_json_encode($event);
        $timestamp = (string) time();
        $nonce = wp_generate_uuid4();
        $response = wp_safe_remote_post($config['url'], [
            'timeout' => 10, 'redirection' => 0, 'limit_response_size' => 1024,
            'headers' => [
                'Content-Type' => 'application/json', 'X-CP-Guide-Version' => '1',
                'X-CP-Guide-Timestamp' => $timestamp, 'X-CP-Guide-Nonce' => $nonce,
                'X-CP-Guide-Signature' => self::sign($body, $timestamp, $nonce, $config['key']),
            ],
            'body' => $body, 'data_format' => 'body',
        ]);
        if (is_wp_error($response)) { return 'network'; }
        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            $ack = json_decode(wp_remote_retrieve_body($response), true);
            return is_array($ack) && ($ack['signup_event_id'] ?? '') === $event['signup_event_id']
                && in_array($ack['status'] ?? '', ['accepted', 'duplicate'], true) ? 'accepted' : 'invalid_ack';
        }
        if ($code === 429 || $code >= 500) { return 'temporary_http'; }
        // Authentication/config failures are retryable until the bounded limit,
        // allowing an operator to correct configuration without new Kit enrollment.
        return 'rejected_http';
    }

    public static function process() {
        global $wpdb;
        if (!in_array(get_option('cp_guide_outbox_schema'), ['1', '2'], true)) { return; }
        $table = self::table();
        // Cleanup runs even while capture/delivery are off. Never retain raw PII
        // ciphertext indefinitely; scrub expired payloads before any delivery.
        $wpdb->query("UPDATE $table SET payload = NULL, state = 'expired', last_error = 'retention_expired', lease_token = NULL, lease_until = NULL WHERE expires_at <= UTC_TIMESTAMP() AND payload IS NOT NULL");
        $wpdb->query("UPDATE $table SET payload = NULL, state = 'failed', last_error = 'attempts_exhausted', lease_token = NULL, lease_until = NULL WHERE attempts >= 10 AND state = 'processing' AND lease_until < UTC_TIMESTAMP() AND payload IS NOT NULL");
        $wpdb->query("DELETE FROM $table WHERE payload IS NULL AND expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)");
        if (!CP_Guide_Submission::enabled()) { return; }
        if (get_option('cp_guide_outbox_schema') !== '2' && !self::install()) { return; }
        $config = self::transport_config();
        if ($config === null) { return; }
        // Changing All -> Test-only also pauses delivery of existing ordinary
        // rows; it never consumes an attempt or silently relabels a queued event.
        $mode_filter = CP_Guide_Submission::mode() === 'test_emails_only' ? ' AND is_test_capture = 1' : '';
        for ($i = 0; $i < 10; $i++) {
            $id = $wpdb->get_var("SELECT event_id FROM $table WHERE payload IS NOT NULL AND expires_at > UTC_TIMESTAMP() AND attempts < 10 AND next_attempt <= UTC_TIMESTAMP() AND (state = 'pending' OR (state = 'processing' AND lease_until < UTC_TIMESTAMP())) $mode_filter ORDER BY next_attempt LIMIT 1");
            if (!$id) { break; }
            $token = wp_generate_uuid4();
            $claimed = $wpdb->query($wpdb->prepare("UPDATE $table SET state = 'processing', lease_token = %s, lease_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 120 SECOND), attempts = attempts + 1 WHERE event_id = %s AND next_attempt <= UTC_TIMESTAMP() AND expires_at > UTC_TIMESTAMP() AND attempts < 10 AND (state = 'pending' OR (state = 'processing' AND lease_until < UTC_TIMESTAMP())) $mode_filter", $token, $id));
            if ($claimed !== 1) { continue; }
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE event_id = %s AND lease_token = %s", $id, $token), ARRAY_A);
            if (!$row) { continue; }
            try {
                $event = self::open($row['payload']);
                $valid = is_array($event) && ($event['signup_event_id'] ?? '') === $id;
                if ($valid && CP_Guide_Submission::mode() === 'test_emails_only') {
                    $valid = ($event['is_test_capture'] ?? false) === true && CP_Guide_Submission::is_test_email($event['email'] ?? null);
                }
                $result = $valid ? self::deliver($event, $config) : 'invalid_payload';
            } catch (\Throwable $error) { $result = 'processing_failure'; }
            $terminal = $result === 'accepted' || $result === 'invalid_payload' || (int) $row['attempts'] >= self::MAX_ATTEMPTS;
            $delay = min(21600, 60 * (2 ** min(9, (int) $row['attempts'] - 1)));
            $updates = [
                'state' => $result === 'accepted' ? 'delivered' : ($terminal ? 'failed' : 'pending'),
                'last_error' => $result === 'accepted' ? null : $result,
                'next_attempt' => gmdate('Y-m-d H:i:s', time() + $delay),
                'lease_token' => null, 'lease_until' => null,
            ];
            if ($terminal) { $updates['payload'] = null; }
            if ($result === 'accepted') { $updates['delivered_at'] = gmdate('Y-m-d H:i:s'); }
            $wpdb->update($table, $updates, ['event_id' => $id, 'lease_token' => $token]);
            if (!$terminal) { wp_schedule_single_event(time() + $delay, self::HOOK); }
        }
    }
}
