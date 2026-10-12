<?php
/** Encryption, retry identity, acknowledgements, leases, retention. */
define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
define('CP_GUIDE_ANALYTICS_URL', 'https://analytics.example.com/api/wordpress/guide-submissions');
define('CP_GUIDE_ANALYTICS_SIGNING_KEY', 'test-only-key-never-use-in-production-123');
$options = ['cp_guide_outbox_schema' => '1'];
$scheduled = [];
$requests = [];
$responses = [];
$salt = 'test-only-wordpress-salt';
$uuid = 0;
function add_action($hook, $callback) {}
function get_option($key, $default = false) { global $options; return $options[$key] ?? $default; }
function wp_salt($scheme) { global $salt; return $salt; }
function wp_json_encode($value) { return json_encode($value); }
function wp_generate_uuid4() { global $uuid; return sprintf('00000000-0000-4000-8000-%012d', ++$uuid); }
function wp_schedule_single_event($time, $hook) { global $scheduled; $scheduled[] = [$time, $hook]; return true; }
function wp_http_validate_url($url) { return true; }
function wp_parse_url($url, $part) { return parse_url($url, $part); }
function is_wp_error($value) { return $value === 'wp_error'; }
function wp_remote_retrieve_response_code($value) { return $value['code']; }
function wp_remote_retrieve_body($value) { return $value['body']; }
function wp_safe_remote_post($url, $args) { global $requests, $responses; $requests[] = ['url' => $url, 'args' => $args]; return array_shift($responses); }
final class CP_Guide_Submission { public static $enabled = true; public static function enabled() { return self::$enabled; } }
final class Outbox_DB {
    public $prefix = 'wp_';
    public $rows = [];
    public $fail_insert = false;
    public $claim_lost = false;
    public function prepare($sql, ...$args) { foreach ($args as $arg) { $sql = preg_replace('/%s/', "'" . addslashes($arg) . "'", $sql, 1); } return $sql; }
    public function insert($table, $data) { if ($this->fail_insert) { return false; } if (isset($this->rows[$data['event_id']])) { return false; } $this->rows[$data['event_id']] = $data; return 1; }
    public function get_var($sql) {
        foreach ($this->rows as $row) {
            if ($row['payload'] !== null && strtotime($row['expires_at']) > time() && $row['attempts'] < 10 && strtotime($row['next_attempt']) <= time()
                && ($row['state'] === 'pending' || ($row['state'] === 'processing' && strtotime($row['lease_until']) < time()))) { return $row['event_id']; }
        }
        return null;
    }
    public function query($sql) {
        if (strpos($sql, "SET state = 'processing'") !== false) {
            if ($this->claim_lost) { return 0; }
            preg_match("/lease_token = '([^']+)'/", $sql, $token);
            preg_match("/event_id = '([^']+)'/", $sql, $id);
            $row = &$this->rows[$id[1]];
            $row['state'] = 'processing'; $row['lease_token'] = $token[1]; $row['lease_until'] = gmdate('Y-m-d H:i:s', time() + 120); $row['attempts']++;
            return 1;
        }
        if (strpos($sql, "state = 'expired'") !== false) {
            foreach ($this->rows as &$row) {
                if (strtotime($row['expires_at']) <= time() && $row['payload'] !== null) { $row['payload'] = null; $row['state'] = 'expired'; }
            }
        }
        return 0;
    }
    public function get_row($sql, $format) { preg_match("/event_id = '([^']+)'/", $sql, $id); return $this->rows[$id[1]]; }
    public function update($table, $data, $where) {
        $row = &$this->rows[$where['event_id']];
        if ($row['lease_token'] !== $where['lease_token']) { return 0; }
        $row = array_merge($row, $data); return 1;
    }
}
$wpdb = new Outbox_DB();
require_once dirname(__DIR__) . '/includes/class-cp-guide-outbox.php';
function check_outbox($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
$event = ['signup_event_id' => wp_generate_uuid4(), 'email' => 'test@example.com', 'event_type' => 'guide_submission'];
$id = $event['signup_event_id'];
$sealed = CP_Guide_Outbox::seal($event);
check_outbox(CP_Guide_Outbox::open($sealed) === $event && strpos($sealed, $event['email']) === false, 'Queue encrypts the full payload and roundtrips.');
$bytes = base64_decode($sealed); $bytes[30] = chr(ord($bytes[30]) ^ 1);
check_outbox(CP_Guide_Outbox::open(base64_encode($bytes)) === null, 'Tampered ciphertext is rejected.');
$salt = 'different-salt';
check_outbox(CP_Guide_Outbox::open($sealed) === null, 'Changed WordPress salts cannot decrypt queued payloads.');
$salt = 'test-only-wordpress-salt';
check_outbox(CP_Guide_Outbox::enqueue($event), 'Durable enqueue succeeds.');
check_outbox(!CP_Guide_Outbox::enqueue($event), 'Duplicate event ID cannot create a second queue row.');
$wpdb->claim_lost = true;
CP_Guide_Outbox::process();
check_outbox(count($requests) === 0, 'Lost worker claim cannot deliver.');
$wpdb->claim_lost = false;
$responses = [['code' => 503, 'body' => 'sensitive provider error']];
CP_Guide_Outbox::process();
check_outbox($wpdb->rows[$id]['state'] === 'pending' && $wpdb->rows[$id]['attempts'] === 1 && $wpdb->rows[$id]['last_error'] === 'temporary_http', 'Transient errors retain the event and only a redacted category.');
check_outbox($wpdb->rows[$id]['payload'] !== null && strtotime($wpdb->rows[$id]['next_attempt']) > time(), 'Retry retains ciphertext with backoff.');
CP_Guide_Outbox::process();
check_outbox(count($requests) === 1, 'Not-yet-due retry cannot deliver early.');
$wpdb->rows[$id]['next_attempt'] = gmdate('Y-m-d H:i:s', time() - 1);
$responses = [['code' => 200, 'body' => json_encode(['signup_event_id' => 'wrong', 'status' => 'accepted'])]];
CP_Guide_Outbox::process();
check_outbox($wpdb->rows[$id]['last_error'] === 'invalid_ack' && $wpdb->rows[$id]['payload'] !== null, 'Unrelated success response cannot discard the event.');
$wpdb->rows[$id]['next_attempt'] = gmdate('Y-m-d H:i:s', time() - 1);
$responses = [['code' => 200, 'body' => json_encode(['signup_event_id' => $id, 'status' => 'duplicate'])]];
CP_Guide_Outbox::process();
check_outbox($wpdb->rows[$id]['state'] === 'delivered' && $wpdb->rows[$id]['payload'] === null, 'Idempotent acknowledgement marks delivery and immediately removes ciphertext.');
check_outbox($requests[0]['args']['body'] === $requests[2]['args']['body'], 'Retries preserve the exact logical event body and UUID.');
$headers = $requests[2]['args']['headers'];
check_outbox($headers['X-CP-Guide-Nonce'] !== $requests[0]['args']['headers']['X-CP-Guide-Nonce'], 'Each delivery attempt has a fresh nonce.');
$expected = hash_hmac('sha256', "cp-guide-submission-v1\n" . $headers['X-CP-Guide-Timestamp'] . "\n" . $headers['X-CP-Guide-Nonce'] . "\n" . hash('sha256', $requests[2]['args']['body']), CP_GUIDE_ANALYTICS_SIGNING_KEY);
check_outbox(hash_equals($expected, $headers['X-CP-Guide-Signature']), 'Signature binds the body hash, timestamp and nonce.');
$event['signup_event_id'] = wp_generate_uuid4(); $id = $event['signup_event_id'];
CP_Guide_Outbox::enqueue($event);
$wpdb->rows[$id]['attempts'] = 9;
$responses = ['wp_error'];
CP_Guide_Outbox::process();
check_outbox($wpdb->rows[$id]['state'] === 'failed' && $wpdb->rows[$id]['payload'] === null, 'Attempt limit scrubs failed payloads.');
$event['signup_event_id'] = wp_generate_uuid4(); $id = $event['signup_event_id'];
CP_Guide_Outbox::enqueue($event);
CP_Guide_Submission::$enabled = false;
$before = count($requests);
CP_Guide_Outbox::process();
check_outbox(count($requests) === $before && $wpdb->rows[$id]['state'] === 'pending', 'Feature off pauses delivery without losing pending events.');
$wpdb->rows[$id]['expires_at'] = gmdate('Y-m-d H:i:s', time() - 1);
CP_Guide_Outbox::process();
check_outbox($wpdb->rows[$id]['state'] === 'expired' && $wpdb->rows[$id]['payload'] === null, 'Disabled delivery still expires email ciphertext.');
echo "Guide outbox contract checks passed.\n";
