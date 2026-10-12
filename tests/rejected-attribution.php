<?php
/** Reconstructed v1.7.6 regressions. Run: php tests/rejected-attribution.php */
define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);
define('COOKIEPATH', '/');
define('COOKIE_DOMAIN', '');
$hooks = [];
$options = ['cp_order_attribution_settings' => ['signature_verification_mode' => 'enforce']];
function add_action($name, $callback, $priority = 10, $args = 1) { global $hooks; $hooks[$name][] = $callback; }
function add_filter($name, $callback, $priority = 10, $args = 1) {}
function register_activation_hook($file, $callback) {}
function plugin_dir_path($file) { return dirname($file) . '/'; }
function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $value)); }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function wp_unslash($value) { return $value; }
function wp_json_encode($value) { return json_encode($value); }
function wp_parse_args($args, $defaults) { return array_merge($defaults, $args); }
function get_option($name, $default = false) { global $options; return $options[$name] ?? $default; }
function update_option($name, $value, $autoload = null) { global $options; $options[$name] = $value; return true; }
function is_ssl() { return true; }
require_once dirname(__DIR__) . '/cityprepping-order-attribution.php';
function check_rejection($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
final class Rejection_Order {
    public $meta = [];
    public function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
    public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
}
foreach (['unsigned', 'expired'] as $scenario) {
    $_COOKIE = ['cp_from' => 'kit', 'cp_slid' => 'accepted-before'];
    $before = $_COOKIE;
    unset($GLOBALS['cp_order_attribution_verified_touch']);
    $_GET = ['cp_from' => 'youtube', 'cp_channel_variant' => 'rejected', 'cp_placement_label' => 'rejected'];
    if ($scenario === 'expired') {
        $_GET += ['cp_sig_v' => '1', 'cp_sig_iat' => (string) (time() - 1000), 'cp_sig_exp' => (string) (time() - 100), 'cp_sig' => str_repeat('a', 43)];
    }
    $_SERVER['QUERY_STRING'] = http_build_query($_GET);
    foreach ($hooks['init'] as $callback) { $callback(); }
    check_rejection($_COOKIE === $before, $scenario . ' request cannot replace accepted cookies.');
    ob_start();
    foreach ($hooks['wp_footer'] as $callback) { $callback(); }
    check_rejection(ob_get_clean() === '', $scenario . ' request cannot emit browser attribution mirroring.');
}
$_COOKIE = ['cp_channel_variant' => 'orphan', 'cp_placement_label' => 'orphan'];
$order = new Rejection_Order();
foreach ($hooks['woocommerce_checkout_create_order'] as $callback) { $callback($order, []); }
check_rejection($order->meta === [], 'Orphaned dimensions cannot become partial order attribution.');
$_COOKIE = ['cp_from' => 'kit', 'cp_slid' => 'accepted', 'cp_email_id' => 'broadcast', 'cp_youtube_id' => 'wrong-source'];
$order = new Rejection_Order();
foreach ($hooks['woocommerce_checkout_create_order'] as $callback) { $callback($order, []); }
check_rejection($order->meta['_cp_email_id'] === 'broadcast' && !isset($order->meta['_cp_youtube_id']), 'Valid legacy attribution preserves only matching source IDs.');
$GLOBALS['cp_order_attribution_verified_touch'] = ['cp_from' => 'kit', 'cp_channel_variant' => 'accepted'];
$_GET['cp_channel_variant'] = 'rejected';
ob_start();
foreach ($hooks['wp_footer'] as $callback) { $callback(); }
$output = ob_get_clean();
check_rejection(strpos($output, 'accepted') !== false && strpos($output, 'rejected') === false && strpos($output, 'URLSearchParams') === false, 'Footer uses the accepted request-local touch, not query dimensions.');
echo "Rejected attribution contract checks passed.\n";
