<?php
/**
 * Server-owned first/latest attribution snapshots.
 *
 * The snapshots are HttpOnly cookies so browser JavaScript cannot create or
 * modify attribution state. The legacy cp_* cookies remain the latest-touch
 * compatibility contract until order snapshot persistence is introduced.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class CP_Attribution_State {
    const SCHEMA_VERSION = 1;
    const FIRST_COOKIE = 'cp_attribution_first';
    const LATEST_COOKIE = 'cp_attribution_latest';
    const HISTORY_COOKIE = 'cp_attribution_subsequent_touches';
    const HISTORY_SCHEMA_VERSION = 1;
    const HISTORY_CAP = 5;
    const MAX_COOKIE_BYTES = 3500;

    public static function is_enabled() {
        $settings = CP_Order_Attribution_Settings::get();
        return !empty($settings['first_latest_touch_enabled']);
    }

    public static function is_history_enabled() {
        $settings = CP_Order_Attribution_Settings::get();
        return self::is_enabled() && !empty($settings['subsequent_touch_history_enabled']);
    }

    public static function capture($touch) {
        if (!self::is_enabled()) {
            return;
        }

        $snapshot = self::build_snapshot($touch);
        if ($snapshot === null) {
            return;
        }

        $previous_latest = self::read_snapshot(self::LATEST_COOKIE);
        if (self::read_snapshot(self::FIRST_COOKIE) === null) {
            self::write_snapshot(self::FIRST_COOKIE, $snapshot);
        }

        if (self::is_history_enabled() && $previous_latest !== null) {
            self::append_history($snapshot);
        }

        self::write_snapshot(self::LATEST_COOKIE, $snapshot);
    }

    public static function read_first() {
        return self::read_snapshot(self::FIRST_COOKIE);
    }

    public static function read_latest() {
        return self::read_snapshot(self::LATEST_COOKIE);
    }

    public static function read_history() {
        if (empty($_COOKIE[self::HISTORY_COOKIE])) {
            return null;
        }

        $encoded = wp_unslash($_COOKIE[self::HISTORY_COOKIE]);
        if (!is_string($encoded) || strlen($encoded) > self::MAX_COOKIE_BYTES) {
            return null;
        }

        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($decoded === false || strlen($decoded) > self::MAX_COOKIE_BYTES) {
            return null;
        }

        $history = json_decode($decoded, true);
        if (!is_array($history)
            || array_keys($history) !== ['schema_version', 'history_truncated', 'touches']
            || (int) $history['schema_version'] !== self::HISTORY_SCHEMA_VERSION
            || !is_bool($history['history_truncated'])
            || !is_array($history['touches'])
            || count($history['touches']) > self::HISTORY_CAP) {
            return null;
        }

        foreach ($history['touches'] as $touch) {
            if (!self::is_valid_snapshot($touch)) {
                return null;
            }
        }

        return $history;
    }

    private static function build_snapshot($touch) {
        if (!is_array($touch) || empty($touch['cp_from'])) {
            return null;
        }

        $source = sanitize_key($touch['cp_from']);
        if (!in_array($source, cp_get_allowed_sources(), true)) {
            return null;
        }

        $snapshot = [
            'schema_version' => self::SCHEMA_VERSION,
            'touch_timestamp' => time(),
            'cp_from' => $source,
        ];

        foreach (['cp_slid', 'cp_email_id', 'cp_youtube_id', 'cp_channel_variant', 'cp_placement_label'] as $field) {
            if (!empty($touch[$field])) {
                $snapshot[$field] = sanitize_text_field($touch[$field]);
            }
        }

        return $snapshot;
    }

    private static function read_snapshot($cookie_name) {
        if (empty($_COOKIE[$cookie_name])) {
            return null;
        }

        $encoded = wp_unslash($_COOKIE[$cookie_name]);
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($decoded === false) {
            return null;
        }

        $snapshot = json_decode($decoded, true);
        if (!self::is_valid_snapshot($snapshot)) {
            return null;
        }

        return $snapshot;
    }

    private static function write_snapshot($cookie_name, $snapshot) {
        self::write_cookie($cookie_name, $snapshot);
    }

    private static function append_history($snapshot) {
        $history = self::read_history();
        if ($history === null) {
            $history = [
                'schema_version' => self::HISTORY_SCHEMA_VERSION,
                'history_truncated' => false,
                'touches' => [],
            ];
        }

        $last_index = count($history['touches']) - 1;
        if ($last_index >= 0 && self::equivalent_touch($history['touches'][$last_index], $snapshot)) {
            // Keep latest_touch equal to the final retained history item even
            // when a consecutive equivalent touch is de-duplicated.
            $history['touches'][$last_index] = $snapshot;
        } else {
            $history['touches'][] = $snapshot;
        }

        if (count($history['touches']) > self::HISTORY_CAP) {
            $history['touches'] = array_slice($history['touches'], -self::HISTORY_CAP);
            $history['history_truncated'] = true;
        }

        self::write_cookie(self::HISTORY_COOKIE, $history);
    }

    private static function equivalent_touch($left, $right) {
        foreach (['cp_from', 'cp_slid', 'cp_email_id', 'cp_youtube_id', 'cp_channel_variant', 'cp_placement_label'] as $field) {
            if (($left[$field] ?? null) !== ($right[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private static function is_valid_snapshot($snapshot) {
        if (!is_array($snapshot)
            || !isset($snapshot['schema_version'], $snapshot['touch_timestamp'], $snapshot['cp_from'])
            || (int) $snapshot['schema_version'] !== self::SCHEMA_VERSION
            || !is_numeric($snapshot['touch_timestamp'])
            || (int) $snapshot['touch_timestamp'] <= 0
            || !is_string($snapshot['cp_from'])
            || !in_array(sanitize_key($snapshot['cp_from']), cp_get_allowed_sources(), true)) {
            return false;
        }

        $allowed_fields = ['schema_version', 'touch_timestamp', 'cp_from', 'cp_slid', 'cp_email_id', 'cp_youtube_id', 'cp_channel_variant', 'cp_placement_label'];
        foreach ($snapshot as $field => $value) {
            if (!in_array($field, $allowed_fields, true)
                || (in_array($field, $allowed_fields, true) && !in_array($field, ['schema_version', 'touch_timestamp'], true) && !is_string($value))
                || (is_string($value) && strlen($value) > 255)) {
                return false;
            }
        }

        return true;
    }

    private static function write_cookie($cookie_name, $payload) {
        $json = wp_json_encode($payload);
        if (!is_string($json) || strlen($json) > self::MAX_COOKIE_BYTES) {
            return;
        }

        $value = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        $settings = CP_Order_Attribution_Settings::get();
        $expires = time() + ((int) $settings['cookie_lifetime_days'] * DAY_IN_SECONDS);

        setcookie($cookie_name, $value, [
            'expires' => $expires,
            'path' => COOKIEPATH ?: '/',
            'domain' => COOKIE_DOMAIN,
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        $_COOKIE[$cookie_name] = $value;
    }
}
