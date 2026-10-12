<?php
/** URL validation and encrypted admin credentials; no network or live options. */
define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);
define('CP_GUIDE_ANALYTICS_URL', 'https://obsolete.example.com/ignored');
define('CP_GUIDE_ANALYTICS_SIGNING_KEY', 'obsolete-server-constant-must-be-ignored');
$options = [];
$can_manage = true;
function get_option($key, $default = false) { global $options; return $options[$key] ?? $default; }
function wp_parse_args($args, $defaults) { return array_merge($defaults, $args); }
function current_user_can($capability) { global $can_manage; return $can_manage; }
function add_settings_error($name, $code, $message) {}
function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', $value)); }
function absint($value) { return abs((int) $value); }
function wp_unslash($value) { return $value; }
function wp_salt($scheme) { return 'test-only-settings-encryption-salt'; }
function wp_parse_url($url, $component) { return parse_url($url, $component); }
function wp_http_validate_url($url) { $host = parse_url($url, PHP_URL_HOST); return $host === 'analytics.example.com'; }
function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function selected($value, $expected) { if ($value === $expected) echo 'selected'; }
function checked($value) { if ($value) echo 'checked'; }
function settings_fields($group) {}
function submit_button($label) {}
require_once dirname(__DIR__) . '/includes/class-cp-settings.php';
require_once dirname(__DIR__) . '/includes/class-cp-guide-outbox.php';
function transport_check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
$name = CP_Order_Attribution_Settings::OPTION_NAME;
$key = 'test-only-dedicated-secret-for-analytics-12345';
$url = 'https://analytics.example.com/api/wordpress/guide-submissions';
transport_check(CP_Guide_Outbox::transport_config() === null, 'Legacy constants cannot bypass unconfigured admin settings.');
$options[$name] = CP_Order_Attribution_Settings::sanitize_settings(['guide_analytics_url' => $url, 'guide_analytics_signing_key' => $key]);
transport_check($options[$name]['guide_analytics_signing_key'] !== $key && strpos($options[$name]['guide_analytics_signing_key'], $key) === false, 'Signing key is encrypted at rest.');
transport_check(CP_Guide_Outbox::transport_config() === ['url' => $url, 'key' => $key], 'Worker reads and decrypts admin transport credentials.');
$cipher = $options[$name]['guide_analytics_signing_key'];
ob_start();
CP_Order_Attribution_Settings::render_settings_page();
$html = ob_get_clean();
transport_check(strpos($html, $key) === false && strpos($html, $cipher) === false, 'Admin HTML never renders the plaintext key or ciphertext.');
transport_check(strpos($html, 'id="cp-guide-analytics-key"') !== false && strpos($html, 'Status: configured') !== false, 'Admin renders an empty credential field and configuration status.');
$saved = CP_Order_Attribution_Settings::sanitize_settings(['guide_analytics_signing_key' => '']);
transport_check($saved['guide_analytics_signing_key'] === $cipher && $saved['guide_analytics_url'] === $url, 'Blank key and unrelated saves preserve current transport configuration.');
foreach (['http://analytics.example.com/api', 'https://localhost/api', $url . '?token=secret', $url . '#fragment', 'https://user:secret@analytics.example.com/api'] as $bad) {
    $saved = CP_Order_Attribution_Settings::sanitize_settings(['guide_analytics_url' => $bad, 'guide_analytics_signing_key' => 'short']);
    transport_check($saved['guide_analytics_url'] === $url && $saved['guide_analytics_signing_key'] === $cipher, 'Invalid URL/key cannot overwrite valid credentials.');
}
$saved = CP_Order_Attribution_Settings::sanitize_settings(['guide_analytics_url' => ['invalid']]);
transport_check($saved['guide_analytics_url'] === $url, 'Malformed URL input fails safely.');
$can_manage = false;
$saved = CP_Order_Attribution_Settings::sanitize_settings(['clear_guide_analytics_signing_key' => 1]);
transport_check($saved['guide_analytics_signing_key'] === $cipher, 'Unauthorized users cannot clear the credential.');
$can_manage = true;
$options[$name] = CP_Order_Attribution_Settings::sanitize_settings(['clear_guide_analytics_signing_key' => 1]);
transport_check(CP_Guide_Outbox::transport_config() === null && $options[$name]['guide_analytics_signing_key'] === '', 'Explicit key clearing stops delivery.');
echo "Guide transport settings checks passed.\n";
