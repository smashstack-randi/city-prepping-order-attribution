<?php
/**
 * Verification for the time-bounded attribution parameters issued by the
 * City Prepping short-link Worker.
 *
 * This class intentionally stores only aggregate reason-code diagnostics. It
 * never stores or logs URLs, attribution values, signatures, or signing keys.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class CP_Attribution_Signature {
    const DIAGNOSTICS_OPTION = 'cp_order_attribution_signature_diagnostics';
    const VERSION = '1';

    /**
     * Verifies the current request using the Worker contract. The return value
     * contains only a reason code and whether the touch may be accepted under
     * the configured rollout mode.
     */
    public static function verify_request() {
        $settings = CP_Order_Attribution_Settings::get();
        $mode = $settings['signature_verification_mode'];

        if ('disabled' === $mode) {
            return ['accept' => true, 'reason' => 'disabled'];
        }

        $result = self::verify_query($_GET, time());
        self::record_diagnostic($result['reason']);

        return [
            'accept' => 'enforce' !== $mode || self::is_valid_reason($result['reason']),
            'reason' => $result['reason'],
        ];
    }

    /**
     * Verify a decoded query-parameter array. Kept public for repeatable
     * staging checks without requiring a live request.
     */
    public static function verify_query($query, $now = null) {
        $query = is_array($query) ? $query : [];
        $now = null === $now ? time() : (int) $now;
        $signed_fields = array_merge(self::attribution_fields(), self::signature_fields());

        foreach ($signed_fields as $field) {
            if (isset($query[$field]) && !is_string($query[$field])) {
                return ['reason' => 'malformed'];
            }
        }

        // PHP collapses duplicate keys while URLSearchParams#get() uses the
        // first value. Reject duplicate signed fields before verification so
        // an ambiguous URL can never become a trusted touch.
        if (self::request_has_duplicate_signed_field($signed_fields)) {
            return ['reason' => 'malformed'];
        }

        $has_signature_field = false;
        foreach (self::signature_fields() as $field) {
            if (array_key_exists($field, $query)) {
                $has_signature_field = true;
                break;
            }
        }

        if (!$has_signature_field) {
            return ['reason' => 'unsigned'];
        }

        $version = self::query_value($query, 'cp_sig_v');
        $issued_at = self::parse_timestamp(self::query_value($query, 'cp_sig_iat'));
        $expires_at = self::parse_timestamp(self::query_value($query, 'cp_sig_exp'));
        $signature = self::query_value($query, 'cp_sig');

        if (self::VERSION !== $version || null === $issued_at || null === $expires_at || $expires_at <= $issued_at || !self::is_base64url_sha256($signature)) {
            return ['reason' => 'malformed'];
        }

        $settings = CP_Order_Attribution_Settings::get();
        $max_age = (int) $settings['signature_max_age_seconds'];
        if ($issued_at > $now || $expires_at < $now || ($expires_at - $issued_at) > $max_age || ($now - $issued_at) > $max_age) {
            return ['reason' => 'expired'];
        }

        $keys = CP_Order_Attribution_Settings::get_signing_keys();
        if ('' === $keys['current'] && '' === $keys['previous']) {
            return ['reason' => 'unverifiable'];
        }

        $payload = self::canonical_payload($query, $issued_at, $expires_at);
        foreach (['current' => 'valid_current', 'previous' => 'valid_previous'] as $slot => $reason) {
            if ('' === $keys[$slot]) {
                continue;
            }

            $expected = hash_hmac('sha256', $payload, $keys[$slot], true);
            $provided = self::base64url_decode($signature);
            if (false !== $provided && hash_equals($expected, $provided)) {
                return ['reason' => $reason];
            }
        }

        return ['reason' => 'invalid'];
    }

    /**
     * Worker-compatible, newline-delimited UTF-8 signing payload.
     */
    public static function canonical_payload($query, $issued_at, $expires_at) {
        $query = is_array($query) ? $query : [];

        return implode("\n", [
            'cp-attribution-v1',
            self::query_value($query, 'cp_slid'),
            self::query_value($query, 'cp_from'),
            self::query_value($query, 'cp_slid'),
            self::query_value($query, 'cp_email_id'),
            self::query_value($query, 'cp_youtube_id'),
            self::query_value($query, 'cp_channel_variant'),
            self::query_value($query, 'cp_placement_label'),
            (string) $issued_at,
            (string) $expires_at,
        ]);
    }

    public static function get_diagnostics() {
        $stored = get_option(self::DIAGNOSTICS_OPTION, []);
        return is_array($stored) ? $stored : [];
    }

    private static function record_diagnostic($reason) {
        $diagnostics = self::get_diagnostics();
        $diagnostics[$reason] = isset($diagnostics[$reason]) ? ((int) $diagnostics[$reason] + 1) : 1;
        update_option(self::DIAGNOSTICS_OPTION, $diagnostics, false);
    }

    private static function is_valid_reason($reason) {
        return in_array($reason, ['valid_current', 'valid_previous'], true);
    }

    private static function attribution_fields() {
        return ['cp_from', 'cp_slid', 'cp_email_id', 'cp_youtube_id', 'cp_channel_variant', 'cp_placement_label'];
    }

    private static function signature_fields() {
        return ['cp_sig_v', 'cp_sig_iat', 'cp_sig_exp', 'cp_sig'];
    }

    private static function query_value($query, $field) {
        if (!isset($query[$field]) || !is_string($query[$field])) {
            return '';
        }

        return wp_unslash($query[$field]);
    }

    private static function parse_timestamp($value) {
        if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,10}$/', $value)) {
            return null;
        }

        return (int) $value;
    }

    private static function is_base64url_sha256($value) {
        return is_string($value) && (bool) preg_match('/^[A-Za-z0-9_-]{43}$/', $value);
    }

    private static function base64url_decode($value) {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return false === $decoded || 32 !== strlen($decoded) ? false : $decoded;
    }

    private static function request_has_duplicate_signed_field($fields) {
        if (empty($_SERVER['QUERY_STRING']) || !is_string($_SERVER['QUERY_STRING'])) {
            return false;
        }

        $seen = [];
        foreach (explode('&', $_SERVER['QUERY_STRING']) as $pair) {
            $name = urldecode(str_replace('+', ' ', strtok($pair, '=')));
            if (!in_array($name, $fields, true)) {
                continue;
            }
            if (isset($seen[$name])) {
                return true;
            }
            $seen[$name] = true;
        }

        return false;
    }
}
