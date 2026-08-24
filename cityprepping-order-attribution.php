<?php
/**
 * Plugin Name: City Prepping Order Attribution
 * Description: Saves attribution URL params to WooCommerce order meta using last-touch attribution, shows attribution in the order admin, and adds sortable order list columns.
 * Version: 1.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Allowed sources for cp_from. Currently supporting kit and youtube since those are the only channels we have active campaigns for, but this can be easily extended in the future as needed.
 * 
 * In the future, we will support the following sources:
 * instagram, x, tiktok, website, and other
 */
function cp_get_allowed_sources() {
    return ['kit', 'youtube'];
}

/**
 * Set a tracking cookie.
 */
function cp_set_tracking_cookie($name, $value) {
    setcookie(
        $name,
        $value,
        time() + (30 * DAY_IN_SECONDS),
        COOKIEPATH ?: '/',
        COOKIE_DOMAIN,
        is_ssl(),
        true
    );

    $_COOKIE[$name] = $value;
}

/**
 * Clear a tracking cookie.
 */
function cp_clear_tracking_cookie($name) {
    setcookie(
        $name,
        '',
        time() - 3600,
        COOKIEPATH ?: '/',
        COOKIE_DOMAIN,
        is_ssl(),
        true
    );

    unset($_COOKIE[$name]);
}

/**
 * Capture tracking params into cookies.
 * Last-touch behavior: overwrite existing cookies whenever a valid cp_from is present.
 * Also clears source-specific cookies from the previous touch so attribution stays clean.
 */
add_action('init', function () {
    if (empty($_GET['cp_from'])) {
        return;
    }

    $cp_from = sanitize_key(wp_unslash($_GET['cp_from']));
    $allowed_sources = cp_get_allowed_sources();

    if (!in_array($cp_from, $allowed_sources, true)) {
        return;
    }

    // Always update the canonical source.
    cp_set_tracking_cookie('cp_from', $cp_from);

    // Update shared short link ID if present.
    if (!empty($_GET['cp_slid'])) {
        $cp_slid = sanitize_text_field(wp_unslash($_GET['cp_slid']));
        cp_set_tracking_cookie('cp_slid', $cp_slid);
    }

    // Keep source-specific identifiers mutually exclusive.
    if ('kit' === $cp_from) {
        if (!empty($_GET['cp_email_id'])) {
            $cp_email_id = sanitize_text_field(wp_unslash($_GET['cp_email_id']));
            cp_set_tracking_cookie('cp_email_id', $cp_email_id);
        } else {
            cp_clear_tracking_cookie('cp_email_id');
        }

        cp_clear_tracking_cookie('cp_youtube_id');
    }

    if ('youtube' === $cp_from) {
        if (!empty($_GET['cp_youtube_id'])) {
            $cp_youtube_id = sanitize_text_field(wp_unslash($_GET['cp_youtube_id']));
            cp_set_tracking_cookie('cp_youtube_id', $cp_youtube_id);
        } else {
            cp_clear_tracking_cookie('cp_youtube_id');
        }

        cp_clear_tracking_cookie('cp_email_id');
    }
});

/**
 * Save cookie values to the WooCommerce order when the order is created.
 * Only saves the source-specific field that matches the current cp_from value.
 */
add_action('woocommerce_checkout_create_order', function ($order, $data) {
    $cp_from = !empty($_COOKIE['cp_from']) ? sanitize_key(wp_unslash($_COOKIE['cp_from'])) : '';
    $cp_slid = !empty($_COOKIE['cp_slid']) ? sanitize_text_field(wp_unslash($_COOKIE['cp_slid'])) : '';

    if ($cp_from) {
        $allowed_sources = cp_get_allowed_sources();

        if (in_array($cp_from, $allowed_sources, true)) {
            $order->update_meta_data('_cp_from', $cp_from);
        }
    }

    if ($cp_slid) {
        $order->update_meta_data('_cp_slid', $cp_slid);
    }

    if ('kit' === $cp_from && !empty($_COOKIE['cp_email_id'])) {
        $cp_email_id = sanitize_text_field(wp_unslash($_COOKIE['cp_email_id']));
        $order->update_meta_data('_cp_email_id', $cp_email_id);
    }

    if ('youtube' === $cp_from && !empty($_COOKIE['cp_youtube_id'])) {
        $cp_youtube_id = sanitize_text_field(wp_unslash($_COOKIE['cp_youtube_id']));
        $order->update_meta_data('_cp_youtube_id', $cp_youtube_id);
    }
}, 20, 2);

/**
 * Show attribution data on the WooCommerce admin order page.
 * Always visible for debugging, even if values are not set.
 */
add_action('woocommerce_admin_order_data_after_order_details', function ($order) {
    $cp_from = $order->get_meta('_cp_from');
    $cp_slid = $order->get_meta('_cp_slid');
    $cp_email_id = $order->get_meta('_cp_email_id');
    $cp_youtube_id = $order->get_meta('_cp_youtube_id');

    echo '<div style="padding:12px 0;">';
    echo '<h3 style="margin:0 0 8px;">Attribution</h3>';
    echo '<p><strong>Source:</strong> ' . ($cp_from ? esc_html($cp_from) : '<em>Not set</em>') . '</p>';
    echo '<p><strong>Short Link ID:</strong> ' . ($cp_slid ? esc_html($cp_slid) : '<em>Not set</em>') . '</p>';
    echo '<p><strong>Email ID:</strong> ' . ($cp_email_id ? esc_html($cp_email_id) : '<em>Not set</em>') . '</p>';
    echo '<p><strong>YouTube ID:</strong> ' . ($cp_youtube_id ? esc_html($cp_youtube_id) : '<em>Not set</em>') . '</p>';
    echo '</div>';
});

/**
 * Add attribution columns to WooCommerce orders list.
 * Only show Source and Short Link ID to keep the table clean.
 */
add_filter('manage_edit-shop_order_columns', function ($columns) {
    $new_columns = [];

    foreach ($columns as $key => $label) {
        $new_columns[$key] = $label;

        if ('order_status' === $key) {
            $new_columns['cp_from'] = 'Source';
            $new_columns['cp_slid'] = 'Short Link ID';
        }
    }

    return $new_columns;
}, 20);

/**
 * Render attribution column values.
 */
add_action('manage_shop_order_posts_custom_column', function ($column, $post_id) {
    if ('cp_from' === $column) {
        $value = get_post_meta($post_id, '_cp_from', true);
        echo $value ? esc_html($value) : '<span style="color:#999;">—</span>';
    }

    if ('cp_slid' === $column) {
        $value = get_post_meta($post_id, '_cp_slid', true);
        echo $value ? esc_html($value) : '<span style="color:#999;">—</span>';
    }
}, 20, 2);

/**
 * Make attribution columns sortable.
 */
add_filter('manage_edit-shop_order_sortable_columns', function ($columns) {
    $columns['cp_from'] = 'cp_from';
    $columns['cp_slid'] = 'cp_slid';
    return $columns;
});

/**
 * Handle sorting for attribution columns.
 */
add_action('pre_get_posts', function ($query) {
    global $pagenow;

    if (
        !is_admin() ||
        'edit.php' !== $pagenow ||
        !$query->is_main_query() ||
        'shop_order' !== $query->get('post_type')
    ) {
        return;
    }

    $orderby = $query->get('orderby');

    if ('cp_from' === $orderby) {
        $query->set('meta_key', '_cp_from');
        $query->set('orderby', 'meta_value');
    }

    if ('cp_slid' === $orderby) {
        $query->set('meta_key', '_cp_slid');
        $query->set('orderby', 'meta_value');
    }
});
