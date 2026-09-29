<?php
/**
 * WooCommerce admin display for immutable attribution snapshots.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class CP_Admin_Order_Attribution {
    public static function register() {
        add_action('woocommerce_admin_order_data_after_order_details', [__CLASS__, 'render_snapshots'], 30);
    }

    public static function render_snapshots($order) {
        $first = CP_Order_Attribution::read_snapshot_meta($order, CP_Order_Attribution::FIRST_META);
        $latest = CP_Order_Attribution::read_snapshot_meta($order, CP_Order_Attribution::LATEST_META);
        $conversion = CP_Order_Attribution::read_snapshot_meta($order, CP_Order_Attribution::CONVERSION_META);
        $conversion_timestamp = $order->get_meta(CP_Order_Attribution::CONVERSION_TIMESTAMP_META, true);

        if ($first === null && $latest === null && $conversion === null) {
            return;
        }

        echo '<div style="padding:12px 0;">';
        echo '<h3 style="margin:0 0 8px;">Attribution snapshots</h3>';
        self::render_snapshot('First touch', $first);
        self::render_snapshot('Latest touch', $latest);
        self::render_snapshot('Conversion touch', $conversion);
        echo '<p><strong>Conversion timestamp:</strong> ' . ($conversion_timestamp ? esc_html($conversion_timestamp) : '<em>Not set</em>') . '</p>';
        echo '</div>';
    }

    private static function render_snapshot($label, $snapshot) {
        if ($snapshot === null) {
            echo '<p><strong>' . esc_html($label) . ':</strong> <em>Not set</em></p>';
            return;
        }

        $parts = [];
        foreach (['cp_from', 'cp_slid', 'cp_email_id', 'cp_youtube_id', 'cp_channel_variant', 'cp_placement_label', 'touch_timestamp', 'schema_version'] as $field) {
            if (isset($snapshot[$field]) && $snapshot[$field] !== '') {
                $parts[] = esc_html($field) . ': ' . esc_html((string) $snapshot[$field]);
            }
        }

        echo '<p><strong>' . esc_html($label) . ':</strong> ' . ($parts ? implode('; ', $parts) : '<em>Not set</em>') . '</p>';
    }
}
