<?php
/**
 * Main Plugin File: wc-origin-tracker.php
 */

// Enqueue the JavaScript on the frontend
add_action('wp_enqueue_scripts', 'wcot_enqueue_scripts');
function wcot_enqueue_scripts() {
    // Make sure to use the correct path to your JS file
    wp_enqueue_script(
        'origin-tracker',
        plugin_dir_url(__FILE__) . 'public/js/origin-tracker.js',
        [], // dependencies
        '1.0.0', // version
        true // in footer
    );
}

// Hook into order creation to save our custom data
add_action('woocommerce_checkout_create_order', 'wcot_save_origin_to_order', 10, 2);
function wcot_save_origin_to_order($order, $data) {
    if (isset($_COOKIE['wc_order_origin'])) {
        $origin = sanitize_text_field($_COOKIE['wc_order_origin']);
        // Save it as order meta data. The underscore makes it a hidden custom field.
        $order->update_meta_data('_order_origin', $origin);
    }
}

// OPTIONAL: Display the origin in the admin order details page
add_action('woocommerce_admin_order_data_after_billing_address', 'wcot_display_origin_in_admin', 10, 1);
function wcot_display_origin_in_admin($order) {
    $origin = $order->get_meta('_order_origin');
    if ($origin) {
        echo '<p><strong>Order Origin:</strong> ' . esc_html($origin) . '</p>';
    }
}