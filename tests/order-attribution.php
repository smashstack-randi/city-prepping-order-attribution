<?php
/**
 * Repeatable checks for WooCommerce order attribution snapshots.
 * Run: php tests/order-attribution.php
 */

define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);
define('COOKIEPATH', '/');
define('COOKIE_DOMAIN', '');

function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $value)); }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function wp_unslash($value) { return $value; }
function wp_json_encode($value) { return json_encode($value); }
function cp_get_allowed_sources() { return ['kit', 'youtube', 'website']; }

final class CP_Order_Attribution_Settings {
    public static function get() {
        return ['first_latest_touch_enabled' => true, 'subsequent_touch_history_enabled' => true, 'cookie_lifetime_days' => 30];
    }
}

final class Fake_Order {
    private $meta = [];

    public function get_meta($key, $single = true) {
        return isset($this->meta[$key]) ? $this->meta[$key] : '';
    }

    public function update_meta_data($key, $value) {
        $this->meta[$key] = $value;
    }
}

require_once dirname(__DIR__) . '/includes/class-cp-attribution-state.php';
require_once dirname(__DIR__) . '/includes/class-cp-order-attribution.php';

function expect_order_attribution($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$_COOKIE = [];
CP_Attribution_State::capture([
    'cp_from' => 'kit',
    'cp_slid' => 'first-link',
    'cp_email_id' => 'broadcast-1',
]);
CP_Attribution_State::capture([
    'cp_from' => 'youtube',
    'cp_slid' => 'latest-link',
    'cp_youtube_id' => 'video-1',
]);

$order = new Fake_Order();
CP_Order_Attribution::capture_order_snapshots($order, []);

$first = CP_Order_Attribution::read_snapshot_meta($order, CP_Order_Attribution::FIRST_META);
$latest = CP_Order_Attribution::read_snapshot_meta($order, CP_Order_Attribution::LATEST_META);
$conversion = CP_Order_Attribution::read_snapshot_meta($order, CP_Order_Attribution::CONVERSION_META);

expect_order_attribution($first['cp_from'] === 'kit', 'Order should retain first touch.');
expect_order_attribution($latest['cp_from'] === 'youtube', 'Order latest touch should reflect the latest cookie state.');
expect_order_attribution($conversion['cp_slid'] === 'latest-link', 'Conversion touch should snapshot latest touch at order creation.');
expect_order_attribution((bool) $order->get_meta(CP_Order_Attribution::CONVERSION_TIMESTAMP_META, true), 'Order should have a conversion timestamp.');

$original_first = $order->get_meta(CP_Order_Attribution::FIRST_META, true);
$order->update_meta_data(CP_Order_Attribution::FIRST_META, '{"cp_from":"preserved"}');
CP_Order_Attribution::capture_order_snapshots($order, []);
expect_order_attribution($order->get_meta(CP_Order_Attribution::FIRST_META, true) === '{"cp_from":"preserved"}', 'Existing first-touch order metadata must not be overwritten.');
expect_order_attribution($original_first !== '', 'First touch should have been written initially.');

$history = CP_Order_Attribution::read_history_meta($order);
expect_order_attribution(count($history['touches']) === 1, 'Order retains subsequent history.');
expect_order_attribution($history['touches'][0] === $latest, 'Final history item matches order latest touch.');
$original_meta = [];
foreach ([CP_Order_Attribution::LATEST_META, CP_Order_Attribution::CONVERSION_META, CP_Order_Attribution::CONVERSION_TIMESTAMP_META, CP_Order_Attribution::SUBSEQUENT_TOUCHES_META] as $key) {
    $original_meta[$key] = $order->get_meta($key);
}
CP_Attribution_State::capture(['cp_from' => 'kit', 'cp_slid' => 'after-order']);
CP_Order_Attribution::capture_order_snapshots($order, []);
foreach ($original_meta as $key => $value) {
    expect_order_attribution($order->get_meta($key) === $value, 'Recapture cannot rewrite existing order attribution: ' . $key);
}
$_COOKIE = [];
$direct_order = new Fake_Order();
CP_Order_Attribution::capture_order_snapshots($direct_order, []);
expect_order_attribution($direct_order->get_meta(CP_Order_Attribution::FIRST_META) === '' && $direct_order->get_meta(CP_Order_Attribution::SUBSEQUENT_TOUCHES_META) === '', 'Direct checkout cannot manufacture snapshots or history.');

echo "Order attribution contract checks passed.\n";
