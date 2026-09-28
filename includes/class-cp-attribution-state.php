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

    public static function is_enabled() {
        $settings = CP_Order_Attribution_Settings::get();
        return !empty($settings['first_latest_touch_enabled']);
    }

    public static function capture($touch) {
        if (!self::is_enabled()) {
            return;
        }

        $snapshot = self::build_snapshot($touch);
        if ($snapshot === null) {
            return;
        }

        if (self::read_snapshot(self::FIRST_COOKIE) === null) {
            self::write_snapshot(self::FIRST_COOKIE, $snapshot);
        }

        self::write_snapshot(self::LATEST_COOKIE, $snapshot);
    }

    public static function read_first() {
        return self::read_snapshot(self::FIRST_COOKIE);
    }

    public static function read_latest() {
        return self::read_snapshot(self::LATEST_COOKIE);
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
        if (!is_array($snapshot)
            || !isset($snapshot['schema_version'], $snapshot['touch_timestamp'], $snapshot['cp_from'])
            || (int) $snapshot['schema_version'] !== self::SCHEMA_VERSION
            || !is_numeric($snapshot['touch_timestamp'])
            || !in_array(sanitize_key($snapshot['cp_from']), cp_get_allowed_sources(), true)) {
            return null;
        }

        return $snapshot;
    }

    private static function write_snapshot($cookie_name, $snapshot) {
        $json = wp_json_encode($snapshot);
        if (!is_string($json)) {
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
