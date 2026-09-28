<?php
/**
 * Repeatable contract checks for the Cloudflare Worker attribution signature.
 * Run: php tests/signature-verification.php
 */

define('ABSPATH', __DIR__ . '/');

$test_options = [];
function get_option($name, $default = false) {
    global $test_options;
    return isset($test_options[$name]) ? $test_options[$name] : $default;
}
function update_option($name, $value, $autoload = null) {
    global $test_options;
    $test_options[$name] = $value;
    return true;
}
function wp_unslash($value) {
    return is_string($value) ? stripslashes($value) : $value;
}

final class CP_Order_Attribution_Settings {
    public static $mode = 'audit';

    public static function get() {
        return [
            'signature_verification_mode' => self::$mode,
            'signature_max_age_seconds' => 900,
        ];
    }

    public static function get_signing_keys() {
        return [
            'current' => str_repeat('a', 64),
            'previous' => str_repeat('b', 64),
        ];
    }
}

require_once dirname(__DIR__) . '/includes/class-cp-attribution-signature.php';

function expect_reason($expected, $query, $now = 1700000010) {
    $actual = CP_Attribution_Signature::verify_query($query, $now)['reason'];
    if ($expected !== $actual) {
        throw new RuntimeException("Expected {$expected}; received {$actual}");
    }
}

$valid = [
    'cp_from' => 'kit',
    'cp_slid' => 'survival-01',
    'cp_email_id' => 'broadcast-42',
    'cp_channel_variant' => 'newsletter',
    'cp_placement_label' => 'cta-top',
    'cp_sig_v' => '1',
    'cp_sig_iat' => '1700000000',
    'cp_sig_exp' => '1700000900',
    'cp_sig' => 'fgm50_uirJrV1qVN5RpPu6aUAn1-3_jFv46KSIs7fKE',
];

$_SERVER['QUERY_STRING'] = '';
expect_reason('valid_current', $valid);

$altered = $valid;
$altered['cp_from'] = 'youtube';
expect_reason('invalid', $altered);

$expired = $valid;
expect_reason('expired', $expired, 1700000901);

$unsigned = $valid;
unset($unsigned['cp_sig_v'], $unsigned['cp_sig_iat'], $unsigned['cp_sig_exp'], $unsigned['cp_sig']);
expect_reason('unsigned', $unsigned);

CP_Order_Attribution_Settings::$mode = 'enforce';
$_GET = $unsigned;
if (CP_Attribution_Signature::verify_request()['accept']) {
    throw new RuntimeException('Enforcement accepted unsigned attribution.');
}
$current_valid = $valid;
$current_valid['cp_sig_iat'] = (string) time();
$current_valid['cp_sig_exp'] = (string) (time() + 900);
$current_valid['cp_sig'] = rtrim(strtr(base64_encode(hash_hmac('sha256', CP_Attribution_Signature::canonical_payload($current_valid, $current_valid['cp_sig_iat'], $current_valid['cp_sig_exp']), str_repeat('a', 64), true)), '+/', '-_'), '=');
$_GET = $current_valid;
if (!CP_Attribution_Signature::verify_request()['accept']) {
    throw new RuntimeException('Enforcement rejected valid attribution.');
}
CP_Order_Attribution_Settings::$mode = 'audit';

$previous = $valid;
$previous['cp_sig'] = rtrim(strtr(base64_encode(hash_hmac('sha256', CP_Attribution_Signature::canonical_payload($previous, 1700000000, 1700000900), str_repeat('b', 64), true)), '+/', '-_'), '=');
expect_reason('valid_previous', $previous);

echo "Signature verification contract checks passed.\n";
