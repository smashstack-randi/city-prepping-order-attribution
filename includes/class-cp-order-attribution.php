<?php
/**
 * Persists server-owned attribution snapshots on WooCommerce orders.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class CP_Order_Attribution {
    const FIRST_META = '_cp_first_touch';
    const LATEST_META = '_cp_latest_touch';
    const CONVERSION_META = '_cp_conversion_touch';
    const CONVERSION_TIMESTAMP_META = '_cp_conversion_touch_timestamp';

    public static function register() {
        add_action('woocommerce_checkout_create_order', [__CLASS__, 'capture_order_snapshots'], 30, 2);
    }

    /**
     * Snapshot attribution at order creation without changing legacy _cp_*
     * metadata, which remains the latest-touch compatibility contract.
     */
    public static function capture_order_snapshots($order, $data) {
        if (!CP_Attribution_State::is_enabled()) {
            return;
        }

        $first = CP_Attribution_State::read_first();
        $latest = CP_Attribution_State::read_latest();
        if ($first === null && $latest === null) {
            return;
        }

        if ($first !== null && !$order->get_meta(self::FIRST_META, true)) {
            $order->update_meta_data(self::FIRST_META, wp_json_encode($first));
        }

        if ($latest !== null) {
            $latest_json = wp_json_encode($latest);
            if (!$order->get_meta(self::LATEST_META, true)) {
                $order->update_meta_data(self::LATEST_META, $latest_json);
            }
            if (!$order->get_meta(self::CONVERSION_META, true)) {
                $order->update_meta_data(self::CONVERSION_META, $latest_json);
            }
            if (!$order->get_meta(self::CONVERSION_TIMESTAMP_META, true)) {
                $order->update_meta_data(self::CONVERSION_TIMESTAMP_META, gmdate('c'));
            }
        }
    }

    public static function read_snapshot_meta($order, $meta_key) {
        $value = $order->get_meta($meta_key, true);
        if (!is_string($value) || $value === '') {
            return null;
        }

        $snapshot = json_decode($value, true);
        if (!is_array($snapshot) || empty($snapshot['cp_from'])) {
            return null;
        }

        return $snapshot;
    }
}
