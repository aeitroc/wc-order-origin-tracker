<?php
/**
 * Plugin Name:       WooCommerce Order Origin Tracker
 * Plugin URI:        https://albpc.com/
 * Description:       Enhanced order origin tracking using WooCommerce Order Attribution (WC 8.5+) with custom tracking fallback. Provides detailed reports with filtering by traffic sources and ROAS analysis.
 * Version:           2.2.5
 * Author:            Besi S
 * Author URI:        https://albpc.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wc-order-origin-tracker
 * WC requires at least: 5.0
 * WC tested up to: 8.9
 * 
 * Changelog v2.2.3:
 * - Enhanced PixelYourSite enrich data parsing to handle nested pys_utm structure
 * - Added utm_medium:paid detection for Facebook ads identification
 * - Updated normalize_origin method with comprehensive Facebook ads patterns
 * - Fixed database queries to properly extract UTM data from nested pys_utm field
 * - Improved origin grouping for better Facebook ads tracking accuracy
 */

// Prevent direct file access for security
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WCOrderOriginTracker {

    /**
     * Constructor to hook into WordPress.
     */
    public function __construct() {
        // Frontend scripts
        add_action( 'wp_footer', [ $this, 'inject_origin_tracker_script' ] );
        
        // Backend functionality
        add_action( 'admin_menu', [ $this, 'add_report_page' ] );
        add_action( 'woocommerce_checkout_create_order', [ $this, 'save_origin_to_order' ], 10, 2 );
        add_action( 'woocommerce_admin_order_data_after_billing_address', [ $this, 'display_origin_in_admin' ], 10, 1 );
    }

    /**
     * Injects the tracking JavaScript into the site's footer.
     * This avoids needing a separate JS file.
     */
    public function inject_origin_tracker_script() {
        ?>
        <script id="wc-origin-tracker-script">
            document.addEventListener('DOMContentLoaded', function() {
                // Helper function to set a cookie
                const setCookie = (name, value, days) => {
                    let expires = "";
                    if (days) {
                        const date = new Date();
                        date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
                        expires = "; expires=" + date.toUTCString();
                    }
                    document.cookie = name + "=" + (value || "") + expires + "; path=/; SameSite=Lax";
                };

                // Helper function to get a cookie
                const getCookie = (name) => {
                    const nameEQ = name + "=";
                    const ca = document.cookie.split(';');
                    for (let i = 0; i < ca.length; i++) {
                        let c = ca[i];
                        while (c.charAt(0) === ' ') c = c.substring(1, c.length);
                        if (c.indexOf(nameEQ) === 0) return c.substring(nameEQ.length, c.length);
                    }
                    return null;
                };

                // Only set the origin cookie if it doesn't already exist to capture the first touchpoint
                if (!getCookie('wc_order_origin')) {
                    let origin = 'Direct'; // Default origin
                    const urlParams = new URLSearchParams(window.location.search);
                    const referrer = document.referrer;

                    // 1. Check for UTM parameters (most specific)
                    if (urlParams.has('utm_source')) {
                        const source = urlParams.get('utm_source');
                        const medium = urlParams.get('utm_medium');
                        origin = `UTM: ${source}` + (medium ? ` / ${medium}` : '');
                    }
                    // 2. Check for a referrer
                    else if (referrer) {
                        const searchEngines = ['google', 'bing', 'yahoo', 'duckduckgo', 'yandex', 'baidu'];
                        const referrerUrl = new URL(referrer);
                        const referrerHost = referrerUrl.hostname.replace('www.', '');

                        // Don't count self-referrals
                        if (referrerHost !== window.location.hostname.replace('www.', '')) {
                             if (searchEngines.some(engine => referrerHost.includes(engine))) {
                                origin = 'Organic Search';
                            } else {
                                origin = `Referral: ${referrerHost}`;
                            }
                        }
                    }
                    
                    // 3. If no UTM and no valid referrer, it remains 'Direct'

                    // Set the cookie to last for 30 days to track returning visitors
                    setCookie('wc_order_origin', origin, 30);
                    
                    // Debug: Log the origin for troubleshooting
                    console.log('WC Order Origin Tracker: Origin set to:', origin);
                } else {
                    // Debug: Log existing cookie value
                    console.log('WC Order Origin Tracker: Existing origin cookie:', getCookie('wc_order_origin'));
                }
            });
        </script>
        <?php
    }

    /**
     * Saves the origin data from the cookie to the WooCommerce order meta.
     *
     * @param WC_Order $order The order object.
     */
    public function save_origin_to_order( $order, $data ) {
        if ( isset( $_COOKIE['wc_order_origin'] ) ) {
            $origin = sanitize_text_field( $_COOKIE['wc_order_origin'] );
            // Save it as order meta data. The underscore makes it a "hidden" custom field.
            $order->update_meta_data( '_order_origin', $origin );
            
            // Debug: Log the save operation
            error_log( 'WC Order Origin Tracker: Saved origin "' . $origin . '" to order #' . $order->get_id() );
        } else {
            // Debug: Log when no cookie is found
            error_log( 'WC Order Origin Tracker: No origin cookie found when saving order #' . $order->get_id() );
        }
    }

    /**
     * Displays the order origin in the admin order details page.
     *
     * @param WC_Order $order The order object.
     */
    public function display_origin_in_admin( $order ) {
        global $wpdb;
        
        // Check if WooCommerce Order Attribution table exists
        $attribution_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}wc_order_attribution'");
        
        if (!empty($attribution_table_exists)) {
            // Try to get WooCommerce Order Attribution data
            $attribution_data = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wc_order_attribution WHERE order_id = %d",
                $order->get_id()
            ) );
            
            if ( $attribution_data ) {
                echo '<div style="margin-top: 10px; padding: 10px; background: #f0f0f1; border-left: 4px solid #0073aa;">';
                echo '<p><strong>' . esc_html__( 'Order Attribution (WooCommerce):', 'wc-order-origin-tracker' ) . '</strong></p>';
                
                echo '<table style="margin-top: 5px;">';
                echo '<tr><td><strong>Type:</strong></td><td>' . esc_html( $attribution_data->source_type ) . '</td></tr>';
                if ( ! empty( $attribution_data->source ) ) {
                    echo '<tr><td><strong>Source:</strong></td><td>' . esc_html( $attribution_data->source ) . '</td></tr>';
                }
                if ( ! empty( $attribution_data->medium ) ) {
                    echo '<tr><td><strong>Medium:</strong></td><td>' . esc_html( $attribution_data->medium ) . '</td></tr>';
                }
                if ( ! empty( $attribution_data->campaign ) ) {
                    echo '<tr><td><strong>Campaign:</strong></td><td>' . esc_html( $attribution_data->campaign ) . '</td></tr>';
                }
                if ( ! empty( $attribution_data->content ) ) {
                    echo '<tr><td><strong>Content:</strong></td><td>' . esc_html( $attribution_data->content ) . '</td></tr>';
                }
                if ( ! empty( $attribution_data->term ) ) {
                    echo '<tr><td><strong>Term:</strong></td><td>' . esc_html( $attribution_data->term ) . '</td></tr>';
                }
                if ( ! empty( $attribution_data->referrer ) ) {
                    echo '<tr><td><strong>Referrer:</strong></td><td>' . esc_html( $attribution_data->referrer ) . '</td></tr>';
                }
                echo '</table>';
                echo '</div>';
            }
        }
        
        // Also show custom origin data if available (backwards compatibility)
        $custom_origin = $order->get_meta( '_order_origin' );
        if ( $custom_origin ) {
            echo '<p><strong>' . esc_html__( 'Custom Origin:', 'wc-order-origin-tracker' ) . '</strong> ' . esc_html( $custom_origin ) . '</p>';
        }
        
        // Show PixelYourSite enrich data if available
        $pys_enrich_data = $order->get_meta( 'pys_enrich_data' );
        if ( $pys_enrich_data ) {
            $pys_data = $this->parse_pys_enrich_data( $pys_enrich_data );
            if ( !empty($pys_data) ) {
                echo '<div style="margin-top: 10px; padding: 10px; background: #f0f8ff; border-left: 4px solid #0066cc;">';
                echo '<p><strong>' . esc_html__( 'PixelYourSite Data:', 'wc-order-origin-tracker' ) . '</strong></p>';
                
                echo '<table style="margin-top: 5px;">';
                if ( ! empty( $pys_data['utm_source'] ) ) {
                    echo '<tr><td><strong>UTM Source:</strong></td><td>' . esc_html( $pys_data['utm_source'] ) . '</td></tr>';
                }
                if ( ! empty( $pys_data['utm_medium'] ) ) {
                    echo '<tr><td><strong>UTM Medium:</strong></td><td>' . esc_html( $pys_data['utm_medium'] ) . '</td></tr>';
                }
                if ( ! empty( $pys_data['utm_campaign'] ) ) {
                    echo '<tr><td><strong>UTM Campaign:</strong></td><td>' . esc_html( $pys_data['utm_campaign'] ) . '</td></tr>';
                }
                if ( ! empty( $pys_data['utm_term'] ) ) {
                    echo '<tr><td><strong>UTM Term:</strong></td><td>' . esc_html( $pys_data['utm_term'] ) . '</td></tr>';
                }
                if ( ! empty( $pys_data['utm_content'] ) ) {
                    echo '<tr><td><strong>UTM Content:</strong></td><td>' . esc_html( $pys_data['utm_content'] ) . '</td></tr>';
                }
                if ( ! empty( $pys_data['pys_source'] ) ) {
                    echo '<tr><td><strong>PYS Source:</strong></td><td>' . esc_html( $pys_data['pys_source'] ) . '</td></tr>';
                }
                if ( ! empty( $pys_data['pys_landing'] ) ) {
                    echo '<tr><td><strong>Landing Page:</strong></td><td>' . esc_html( $pys_data['pys_landing'] ) . '</td></tr>';
                }
                echo '</table>';
                echo '</div>';
            }
        }
    }

    /**
     * Adds the admin menu page for our report under "WooCommerce".
     */
    public function add_report_page() {
        add_submenu_page(
            'woocommerce',                // Parent slug
            'Order Origin Report',        // Page title
            'Order Origin',               // Menu title
            'manage_woocommerce',         // Capability required
            'wc-order-origin-report',     // Menu slug
            [ $this, 'render_report_page' ] // Callback function to render the page
        );
    }

    /**
     * Renders the HTML and runs the query for the report page.
     */
    public function render_report_page() {
        global $wpdb;

        // Handle ad spend form submission
        if ( isset( $_POST['ad_spend'] ) && isset( $_POST['wcot_ad_spend_nonce'] ) && wp_verify_nonce( $_POST['wcot_ad_spend_nonce'], 'wcot_ad_spend_action' ) ) {
            $ad_spend = floatval( sanitize_text_field( $_POST['ad_spend'] ) );
            $spend_date_range = sanitize_text_field( $_POST['spend_date_range'] );
            
            if ( $ad_spend >= 0 && !empty($spend_date_range) ) {
                // Store ad spend data in WordPress options with date range as key
                $ad_spend_data = get_option('wcot_ad_spend_data', []);
                $ad_spend_data[$spend_date_range] = $ad_spend;
                update_option('wcot_ad_spend_data', $ad_spend_data);
                
                echo '<div class="notice notice-success"><p>Ad spend of ' . wc_price($ad_spend) . ' saved for date range: ' . esc_html($spend_date_range) . '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Please enter a valid ad spend amount and date range.</p></div>';
            }
        }

        // Set default date range to the last 3 days
        // Check if WordPress date is reasonable, otherwise use server time
        $wp_timestamp = current_time( 'timestamp' );
        $server_timestamp = time();
        $wp_date = date( 'Y-m-d', $wp_timestamp );
        $server_date = date( 'Y-m-d', $server_timestamp );
        
        // Check for manual date override (for servers with wrong system clock)
        $manual_date_override = get_option('wcot_manual_date_override', '');
        
        // Debug: Log what override was read from database
        error_log('WCOT: Manual override from DB: "' . $manual_date_override . '" - Empty: ' . (empty($manual_date_override) ? 'Yes' : 'No') . ' - Valid: ' . ($this->validate_date($manual_date_override) ? 'Yes' : 'No'));
        
        if ( !empty($manual_date_override) && $this->validate_date($manual_date_override) ) {
            // Use manual override
            $override_timestamp = strtotime($manual_date_override . ' ' . date('H:i:s'));
            $use_timestamp = $override_timestamp;
            $date_source = 'manual_override';
            
            // Debug: Log manual override being applied
            error_log('WCOT: Manual override applied - Date: ' . $manual_date_override . ' - Timestamp: ' . date('Y-m-d H:i:s', $override_timestamp));
        } elseif ( $wp_date > '2027-01-01' || $server_date > '2027-01-01' ) {
            // Both server and WP time are wrong (in the future), provide manual control
            $current_actual_date = '2025-07-08'; // Updated to actual current date
            $fallback_timestamp = strtotime($current_actual_date . ' ' . date('H:i:s', time()));
            $use_timestamp = $fallback_timestamp;
            $date_source = 'fallback_2025';
            
            // Debug: Log fallback being used
            error_log('WCOT: Using fallback date - ' . $current_actual_date . ' because server date is in future: ' . $wp_date);
        } elseif ( $wp_date > $server_date || abs( date('Y', $wp_timestamp) - 2024 ) > 1 ) {
            $use_timestamp = $server_timestamp;
            $date_source = 'server';
        } else {
            $use_timestamp = $wp_timestamp;
            $date_source = 'wordpress';
        }
        
        // Use WordPress's timezone-aware date functions for default range
        $default_start_date = date( 'Y-m-d', strtotime( '-3 days', current_time( 'timestamp' ) ) );
        $default_end_date   = current_time( 'Y-m-d' );

        // Get date range from user input, or use defaults
        $start_date = ( isset( $_POST['start_date'] ) && $this->validate_date($_POST['start_date']) ) ? sanitize_text_field( $_POST['start_date'] ) : $default_start_date;
        $end_date   = ( isset( $_POST['end_date'] ) && $this->validate_date($_POST['end_date']) ) ? sanitize_text_field( $_POST['end_date'] ) : $default_end_date;

        // Get selected filters from user input
        $selected_utm_sources = isset( $_POST['selected_utm_sources'] ) ? array_map( 'sanitize_text_field', $_POST['selected_utm_sources'] ) : [];
        $selected_utm_mediums = isset( $_POST['selected_utm_mediums'] ) ? array_map( 'sanitize_text_field', $_POST['selected_utm_mediums'] ) : [];
        $selected_utm_campaigns = isset( $_POST['selected_utm_campaigns'] ) ? array_map( 'sanitize_text_field', $_POST['selected_utm_campaigns'] ) : [];
        $selected_utm_terms = isset( $_POST['selected_utm_terms'] ) ? array_map( 'sanitize_text_field', $_POST['selected_utm_terms'] ) : [];
        $selected_utm_contents = isset( $_POST['selected_utm_contents'] ) ? array_map( 'sanitize_text_field', $_POST['selected_utm_contents'] ) : [];

        // Handle manual date override
        if ( isset( $_POST['manual_date_override'] ) && isset( $_POST['wcot_date_override_nonce'] ) && wp_verify_nonce( $_POST['wcot_date_override_nonce'], 'wcot_date_override_action' ) ) {
            $manual_override = sanitize_text_field( $_POST['manual_date_override'] );
            if ( empty($manual_override) ) {
                delete_option('wcot_manual_date_override');
                echo '<div class="notice notice-success"><p>Manual date override removed. Using system date.</p></div>';
            } elseif ( $this->validate_date($manual_override) ) {
                update_option('wcot_manual_date_override', $manual_override);
                echo '<div class="notice notice-success"><p>Manual date override set to: ' . esc_html($manual_override) . '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Invalid date format. Please use YYYY-MM-DD format.</p></div>';
            }
        }

        // Verify nonce for security
        if ( isset( $_POST['wcot_report_nonce'] ) && ! wp_verify_nonce( $_POST['wcot_report_nonce'], 'wcot_report_action' ) ) {
             wp_die('Invalid nonce specified', 'Error', ['response' => 403]);
        }

        // Check for WooCommerce Order Attribution data in multiple locations
        $attribution_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}wc_order_attribution'");
        $orders_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}wc_orders'");
        
        // Check for attribution data in different locations
        $wc_attribution_count = 0;
        $wc_meta_count = 0;
        $post_meta_count = 0;
        
        if ($attribution_table_exists) {
            $wc_attribution_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_order_attribution");
        }
        
        if ($orders_table_exists) {
            $wc_meta_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key LIKE '_wc_order_attribution%'");
        }
        
        $post_meta_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}postmeta WHERE meta_key LIKE '_wc_order_attribution%'");
        $utm_source_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}postmeta WHERE meta_key = '_wc_order_attribution_utm_source'");
        $origin_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}postmeta WHERE meta_key = '_wc_order_attribution_origin'");
        
        // Check for pys_enrich_data (PixelYourSite data)
        $pys_enrich_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}postmeta WHERE meta_key = 'pys_enrich_data'");
        
        // Determine which method to use based on available data
        $use_wc_attribution = false;
        $use_wc_meta = false;
        $use_post_meta = false;
        $use_pys_data = false;
        
        if ($attribution_table_exists && $wc_attribution_count > 0) {
            $use_wc_attribution = true;
        } elseif ($orders_table_exists && $wc_meta_count > 0) {
            $use_wc_meta = true;
        } elseif ($utm_source_count > 0 || $post_meta_count > 0) {
            $use_post_meta = true;
        } elseif ($pys_enrich_count > 0) {
            $use_pys_data = true;
        }
        
        // Get all available filter values based on the data source
        $available_utm_sources = [];
        $available_utm_mediums = [];
        $available_utm_campaigns = [];
        $available_utm_terms = [];
        $available_utm_contents = [];
        $total_orders_with_origin = 0;
        
        if ($use_wc_attribution) {
            // Method 1: WooCommerce Order Attribution table
            $available_utm_sources = $wpdb->get_results("SELECT DISTINCT source AS value FROM {$wpdb->prefix}wc_order_attribution WHERE source IS NOT NULL AND source != '' ORDER BY source ASC");
            $available_utm_mediums = $wpdb->get_results("SELECT DISTINCT medium AS value FROM {$wpdb->prefix}wc_order_attribution WHERE medium IS NOT NULL AND medium != '' ORDER BY medium ASC");
            $available_utm_campaigns = $wpdb->get_results("SELECT DISTINCT campaign AS value FROM {$wpdb->prefix}wc_order_attribution WHERE campaign IS NOT NULL AND campaign != '' ORDER BY campaign ASC");
            $available_utm_terms = $wpdb->get_results("SELECT DISTINCT term AS value FROM {$wpdb->prefix}wc_order_attribution WHERE term IS NOT NULL AND term != '' ORDER BY term ASC");
            $available_utm_contents = $wpdb->get_results("SELECT DISTINCT content AS value FROM {$wpdb->prefix}wc_order_attribution WHERE content IS NOT NULL AND content != '' ORDER BY content ASC");
            $total_orders_with_origin = $wpdb->get_var("SELECT COUNT(DISTINCT order_id) FROM {$wpdb->prefix}wc_order_attribution WHERE source_type IS NOT NULL");
            
        } elseif ($use_wc_meta) {
            // Method 2: WooCommerce Orders Meta table
            $available_utm_sources = $wpdb->get_results("SELECT DISTINCT meta_value AS value FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = '_wc_order_attribution_source' AND meta_value IS NOT NULL AND meta_value != '' ORDER BY meta_value ASC");
            $available_utm_mediums = $wpdb->get_results("SELECT DISTINCT meta_value AS value FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = '_wc_order_attribution_medium' AND meta_value IS NOT NULL AND meta_value != '' ORDER BY meta_value ASC");
            $available_utm_campaigns = $wpdb->get_results("SELECT DISTINCT meta_value AS value FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = '_wc_order_attribution_campaign' AND meta_value IS NOT NULL AND meta_value != '' ORDER BY meta_value ASC");
            $available_utm_terms = $wpdb->get_results("SELECT DISTINCT meta_value AS value FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = '_wc_order_attribution_term' AND meta_value IS NOT NULL AND meta_value != '' ORDER BY meta_value ASC");
            $available_utm_contents = $wpdb->get_results("SELECT DISTINCT meta_value AS value FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = '_wc_order_attribution_content' AND meta_value IS NOT NULL AND meta_value != '' ORDER BY meta_value ASC");
            $total_orders_with_origin = $wpdb->get_var("SELECT COUNT(DISTINCT order_id) FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = '_wc_order_attribution_source_type' AND meta_value IS NOT NULL");
            
        } elseif ($use_post_meta) {
            // Method 3: Post Meta table - Get individual WooCommerce 8.5+ field values from orders with attribution data
            $orders_with_attribution = "SELECT DISTINCT posts.ID FROM {$wpdb->prefix}posts AS posts 
                LEFT JOIN {$wpdb->prefix}postmeta AS utm_source ON posts.ID = utm_source.post_id AND utm_source.meta_key = '_wc_order_attribution_utm_source'
                LEFT JOIN {$wpdb->prefix}postmeta AS utm_medium ON posts.ID = utm_medium.post_id AND utm_medium.meta_key = '_wc_order_attribution_utm_medium' 
                LEFT JOIN {$wpdb->prefix}postmeta AS utm_campaign ON posts.ID = utm_campaign.post_id AND utm_campaign.meta_key = '_wc_order_attribution_utm_campaign'
                LEFT JOIN {$wpdb->prefix}postmeta AS utm_term ON posts.ID = utm_term.post_id AND utm_term.meta_key = '_wc_order_attribution_utm_term'
                LEFT JOIN {$wpdb->prefix}postmeta AS utm_content ON posts.ID = utm_content.post_id AND utm_content.meta_key = '_wc_order_attribution_utm_content'
                WHERE posts.post_type = 'shop_order' AND posts.post_status NOT IN ('trash', 'auto-draft')
                AND (utm_source.meta_value IS NOT NULL OR utm_medium.meta_value IS NOT NULL OR utm_campaign.meta_value IS NOT NULL OR utm_term.meta_value IS NOT NULL OR utm_content.meta_value IS NOT NULL)";
                
            $available_utm_sources = $wpdb->get_results("SELECT DISTINCT meta_value AS value FROM {$wpdb->prefix}postmeta WHERE meta_key = '_wc_order_attribution_utm_source' AND meta_value IS NOT NULL AND meta_value != '' AND post_id IN ($orders_with_attribution) ORDER BY meta_value ASC");
            $available_utm_mediums = $wpdb->get_results("SELECT DISTINCT meta_value AS value FROM {$wpdb->prefix}postmeta WHERE meta_key = '_wc_order_attribution_utm_medium' AND meta_value IS NOT NULL AND meta_value != '' AND post_id IN ($orders_with_attribution) ORDER BY meta_value ASC");
            $available_utm_campaigns = $wpdb->get_results("SELECT DISTINCT meta_value AS value FROM {$wpdb->prefix}postmeta WHERE meta_key = '_wc_order_attribution_utm_campaign' AND meta_value IS NOT NULL AND meta_value != '' AND post_id IN ($orders_with_attribution) ORDER BY meta_value ASC");
            $available_utm_terms = $wpdb->get_results("SELECT DISTINCT meta_value AS value FROM {$wpdb->prefix}postmeta WHERE meta_key = '_wc_order_attribution_utm_term' AND meta_value IS NOT NULL AND meta_value != '' AND post_id IN ($orders_with_attribution) ORDER BY meta_value ASC");
            $available_utm_contents = $wpdb->get_results("SELECT DISTINCT meta_value AS value FROM {$wpdb->prefix}postmeta WHERE meta_key = '_wc_order_attribution_utm_content' AND meta_value IS NOT NULL AND meta_value != '' AND post_id IN ($orders_with_attribution) ORDER BY meta_value ASC");
            $total_orders_with_origin = $wpdb->get_var(
                "SELECT COUNT(DISTINCT posts.ID) 
                 FROM {$wpdb->prefix}posts AS posts
                 LEFT JOIN {$wpdb->prefix}postmeta AS utm_source ON posts.ID = utm_source.post_id AND utm_source.meta_key = '_wc_order_attribution_utm_source'
                 LEFT JOIN {$wpdb->prefix}postmeta AS utm_medium ON posts.ID = utm_medium.post_id AND utm_medium.meta_key = '_wc_order_attribution_utm_medium'
                 LEFT JOIN {$wpdb->prefix}postmeta AS utm_campaign ON posts.ID = utm_campaign.post_id AND utm_campaign.meta_key = '_wc_order_attribution_utm_campaign'
                 LEFT JOIN {$wpdb->prefix}postmeta AS utm_term ON posts.ID = utm_term.post_id AND utm_term.meta_key = '_wc_order_attribution_utm_term'
                 LEFT JOIN {$wpdb->prefix}postmeta AS utm_content ON posts.ID = utm_content.post_id AND utm_content.meta_key = '_wc_order_attribution_utm_content'
                 WHERE posts.post_type = 'shop_order'
                 AND posts.post_status NOT IN ('trash', 'auto-draft')
                 AND (utm_source.meta_value IS NOT NULL OR utm_medium.meta_value IS NOT NULL OR utm_campaign.meta_value IS NOT NULL OR utm_term.meta_value IS NOT NULL OR utm_content.meta_value IS NOT NULL)"
            );
            
        } elseif ($use_pys_data) {
            // Method 4: PixelYourSite enrich data
            // Note: Using SUBSTRING_INDEX with nested structure pys_utm field
            $available_utm_sources = $wpdb->get_results("SELECT DISTINCT 
                SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(pys_data.meta_value, 'pys_utm', -1), 'utm_source:', -1), '|', 1) AS value 
                FROM {$wpdb->prefix}postmeta AS pys_data 
                WHERE pys_data.meta_key = 'pys_enrich_data' 
                AND pys_data.meta_value LIKE '%pys_utm%' 
                AND pys_data.meta_value LIKE '%utm_source:%' 
                AND SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(pys_data.meta_value, 'pys_utm', -1), 'utm_source:', -1), '|', 1) != ''
                ORDER BY value ASC");
            
            $available_utm_mediums = $wpdb->get_results("SELECT DISTINCT 
                SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(pys_data.meta_value, 'pys_utm', -1), 'utm_medium:', -1), '|', 1) AS value 
                FROM {$wpdb->prefix}postmeta AS pys_data 
                WHERE pys_data.meta_key = 'pys_enrich_data' 
                AND pys_data.meta_value LIKE '%pys_utm%' 
                AND pys_data.meta_value LIKE '%utm_medium:%' 
                AND SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(pys_data.meta_value, 'pys_utm', -1), 'utm_medium:', -1), '|', 1) != ''
                ORDER BY value ASC");
            
            $available_utm_campaigns = $wpdb->get_results("SELECT DISTINCT 
                SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(pys_data.meta_value, 'pys_utm', -1), 'utm_campaign:', -1), '|', 1) AS value 
                FROM {$wpdb->prefix}postmeta AS pys_data 
                WHERE pys_data.meta_key = 'pys_enrich_data' 
                AND pys_data.meta_value LIKE '%pys_utm%' 
                AND pys_data.meta_value LIKE '%utm_campaign:%' 
                AND SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(pys_data.meta_value, 'pys_utm', -1), 'utm_campaign:', -1), '|', 1) != ''
                ORDER BY value ASC");
            
            $available_utm_terms = $wpdb->get_results("SELECT DISTINCT 
                SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(pys_data.meta_value, 'pys_utm', -1), 'utm_term:', -1), '|', 1) AS value 
                FROM {$wpdb->prefix}postmeta AS pys_data 
                WHERE pys_data.meta_key = 'pys_enrich_data' 
                AND pys_data.meta_value LIKE '%pys_utm%' 
                AND pys_data.meta_value LIKE '%utm_term:%' 
                AND SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(pys_data.meta_value, 'pys_utm', -1), 'utm_term:', -1), '|', 1) != ''
                ORDER BY value ASC");
            
            $available_utm_contents = $wpdb->get_results("SELECT DISTINCT 
                SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(pys_data.meta_value, 'pys_utm', -1), 'utm_content:', -1), '|', 1) AS value 
                FROM {$wpdb->prefix}postmeta AS pys_data 
                WHERE pys_data.meta_key = 'pys_enrich_data' 
                AND pys_data.meta_value LIKE '%pys_utm%' 
                AND pys_data.meta_value LIKE '%utm_content:%' 
                AND SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(pys_data.meta_value, 'pys_utm', -1), 'utm_content:', -1), '|', 1) != ''
                ORDER BY value ASC");
            
            $total_orders_with_origin = $wpdb->get_var(
                "SELECT COUNT(DISTINCT posts.ID)
                 FROM {$wpdb->prefix}posts AS posts
                 JOIN {$wpdb->prefix}postmeta AS pys_data ON posts.ID = pys_data.post_id
                 WHERE posts.post_type = 'shop_order'
                 AND posts.post_status NOT IN ('trash', 'auto-draft')
                 AND pys_data.meta_key = 'pys_enrich_data'
                 AND (pys_data.meta_value LIKE '%pys_utm%' AND (pys_data.meta_value LIKE '%utm_source:%' OR pys_data.meta_value LIKE '%0138%' OR pys_data.meta_value LIKE '%utm_medium:paid%'))"
            );
        } else {
            // Fallback to custom origin tracking
            $all_origins = $wpdb->get_results(
                "SELECT DISTINCT meta.meta_value AS origin
                 FROM {$wpdb->prefix}posts AS posts
                 JOIN {$wpdb->prefix}postmeta AS meta ON posts.ID = meta.post_id
                 WHERE posts.post_type = 'shop_order'
                 AND posts.post_status IN ('wc-processing', 'wc-completed', 'wc-refunded')
                 AND meta.meta_key = '_order_origin'
                 AND meta.meta_value != ''
                 ORDER BY origin ASC"
            );

            $total_orders_with_origin = $wpdb->get_var(
                "SELECT COUNT(DISTINCT posts.ID)
                 FROM {$wpdb->prefix}posts AS posts
                 JOIN {$wpdb->prefix}postmeta AS meta ON posts.ID = meta.post_id
                 WHERE posts.post_type = 'shop_order'
                 AND posts.post_status NOT IN ('trash', 'auto-draft')
                 AND meta.meta_key = '_order_origin'
                 AND meta.meta_value != ''"
            );
        }

        // Build the main query with origin filtering
        if ($use_wc_attribution) {
            // Method 1: WooCommerce Order Attribution table
            $query = "SELECT
                        CASE 
                            WHEN oa.source_type = 'utm' THEN CONCAT('UTM: ', oa.source, COALESCE(CONCAT(' / ', oa.medium), ''))
                            WHEN oa.source_type = 'organic' THEN CONCAT('Organic: ', oa.source)
                            WHEN oa.source_type = 'referral' THEN CONCAT('Referral: ', oa.source)
                            WHEN oa.source_type = 'direct' THEN 'Direct'
                            WHEN oa.source_type = 'admin' THEN 'Admin'
                            ELSE CONCAT(oa.source_type, ': ', COALESCE(oa.source, 'Unknown'))
                        END AS origin,
                        COUNT(posts.ID) AS order_count
                     FROM
                        {$wpdb->prefix}posts AS posts
                     JOIN
                        {$wpdb->prefix}wc_order_attribution AS oa ON posts.ID = oa.order_id
                     WHERE
                        posts.post_type = 'shop_order'
                        AND posts.post_status NOT IN ('trash', 'auto-draft')
                        AND oa.source_type IS NOT NULL
                        AND posts.post_date >= %s AND posts.post_date < DATE_ADD(%s, INTERVAL 1 DAY)";
                        
        } elseif ($use_wc_meta) {
            // Method 2: WooCommerce Orders Meta table
            $query = "SELECT
                        CASE 
                            WHEN source_type.meta_value = 'utm' THEN CONCAT('UTM: ', source.meta_value, COALESCE(CONCAT(' / ', medium.meta_value), ''))
                            WHEN source_type.meta_value = 'organic' THEN CONCAT('Organic: ', source.meta_value)
                            WHEN source_type.meta_value = 'referral' THEN CONCAT('Referral: ', source.meta_value)
                            WHEN source_type.meta_value = 'direct' THEN 'Direct'
                            WHEN source_type.meta_value = 'admin' THEN 'Admin'
                            ELSE CONCAT(source_type.meta_value, ': ', COALESCE(source.meta_value, 'Unknown'))
                        END AS origin,
                        COUNT(orders.id) AS order_count
                     FROM
                        {$wpdb->prefix}wc_orders AS orders
                     JOIN
                        {$wpdb->prefix}wc_orders_meta AS source_type ON orders.id = source_type.order_id
                     LEFT JOIN
                        {$wpdb->prefix}wc_orders_meta AS source ON orders.id = source.order_id AND source.meta_key = '_wc_order_attribution_source'
                     LEFT JOIN
                        {$wpdb->prefix}wc_orders_meta AS medium ON orders.id = medium.order_id AND medium.meta_key = '_wc_order_attribution_medium'
                     LEFT JOIN
                        {$wpdb->prefix}wc_orders_meta AS campaign ON orders.id = campaign.order_id AND campaign.meta_key = '_wc_order_attribution_campaign'
                     LEFT JOIN
                        {$wpdb->prefix}wc_orders_meta AS utm_term ON orders.id = utm_term.order_id AND utm_term.meta_key = '_wc_order_attribution_term'
                     LEFT JOIN
                        {$wpdb->prefix}wc_orders_meta AS utm_content ON orders.id = utm_content.order_id AND utm_content.meta_key = '_wc_order_attribution_content'
                     JOIN
                        {$wpdb->prefix}wc_orders_meta AS total_sales_meta ON orders.id = total_sales_meta.order_id
                     WHERE
                        orders.type = 'shop_order'
                        AND orders.status NOT IN ('trash', 'auto-draft')
                        AND source_type.meta_key = '_wc_order_attribution_source_type'
                        AND source_type.meta_value IS NOT NULL
                        AND total_sales_meta.meta_key = '_order_total'
                        AND orders.date_created_gmt >= %s AND orders.date_created_gmt < DATE_ADD(%s, INTERVAL 1 DAY)";
                        
        } elseif ($use_post_meta) {
            // Method 3: Post Meta table - Build origin from individual WooCommerce 8.5+ fields
            $query = "SELECT
                        CASE 
                            WHEN utm_source.meta_value IS NOT NULL AND utm_medium.meta_value IS NOT NULL THEN 
                                CONCAT('UTM: ', utm_source.meta_value, ' / ', utm_medium.meta_value)
                            WHEN utm_source.meta_value IS NOT NULL THEN 
                                CONCAT('UTM: ', utm_source.meta_value)
                            WHEN utm_medium.meta_value IS NOT NULL THEN 
                                CONCAT('UTM: ', utm_medium.meta_value)
                            WHEN utm_campaign.meta_value IS NOT NULL THEN 
                                CONCAT('Campaign: ', utm_campaign.meta_value)
                            WHEN utm_term.meta_value IS NOT NULL THEN 
                                CONCAT('Term: ', utm_term.meta_value)
                            WHEN utm_content.meta_value IS NOT NULL THEN 
                                CONCAT('Content: ', utm_content.meta_value)
                            ELSE 'Direct'
                        END AS origin,
                        COUNT(posts.ID) AS order_count
                     FROM
                        {$wpdb->prefix}posts AS posts
                     LEFT JOIN
                        {$wpdb->prefix}postmeta AS utm_source ON posts.ID = utm_source.post_id AND utm_source.meta_key = '_wc_order_attribution_utm_source'
                     LEFT JOIN
                        {$wpdb->prefix}postmeta AS utm_medium ON posts.ID = utm_medium.post_id AND utm_medium.meta_key = '_wc_order_attribution_utm_medium'
                     LEFT JOIN
                        {$wpdb->prefix}postmeta AS utm_campaign ON posts.ID = utm_campaign.post_id AND utm_campaign.meta_key = '_wc_order_attribution_utm_campaign'
                     LEFT JOIN
                        {$wpdb->prefix}postmeta AS utm_term ON posts.ID = utm_term.post_id AND utm_term.meta_key = '_wc_order_attribution_utm_term'
                     LEFT JOIN
                        {$wpdb->prefix}postmeta AS utm_content ON posts.ID = utm_content.post_id AND utm_content.meta_key = '_wc_order_attribution_utm_content'
                     LEFT JOIN
                        {$wpdb->prefix}postmeta AS total_sales_meta ON posts.ID = total_sales_meta.post_id AND total_sales_meta.meta_key = '_order_total'
                     WHERE
                        posts.post_type = 'shop_order'
                        AND posts.post_status NOT IN ('trash', 'auto-draft')
                        AND (utm_source.meta_value IS NOT NULL OR utm_medium.meta_value IS NOT NULL OR utm_campaign.meta_value IS NOT NULL OR utm_term.meta_value IS NOT NULL OR utm_content.meta_value IS NOT NULL)
                        AND posts.post_date >= %s AND posts.post_date < DATE_ADD(%s, INTERVAL 1 DAY)";
                        
        } elseif ($use_pys_data) {
            // Method 4: PixelYourSite enrich data - Parse UTM from serialized data with nested pys_utm field
            $query = "SELECT
                        CASE 
                            WHEN pys_data.meta_value LIKE '%pys_utm%' AND pys_data.meta_value LIKE '%utm_source:%' AND pys_data.meta_value LIKE '%utm_medium:%' THEN 
                                CONCAT('UTM: ', 
                                    SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(pys_data.meta_value, 'pys_utm', -1), 'utm_source:', -1), '|', 1), 
                                    ' / ', 
                                    SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(pys_data.meta_value, 'pys_utm', -1), 'utm_medium:', -1), '|', 1)
                                )
                            WHEN pys_data.meta_value LIKE '%pys_utm%' AND pys_data.meta_value LIKE '%utm_source:%' THEN 
                                CONCAT('UTM: ', SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(pys_data.meta_value, 'pys_utm', -1), 'utm_source:', -1), '|', 1))
                            WHEN pys_data.meta_value LIKE '%pys_utm%' AND pys_data.meta_value LIKE '%utm_medium:%' THEN 
                                CONCAT('UTM: ', SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(pys_data.meta_value, 'pys_utm', -1), 'utm_medium:', -1), '|', 1))
                            WHEN pys_data.meta_value LIKE '%pys_utm%' AND pys_data.meta_value LIKE '%utm_campaign:%' THEN 
                                CONCAT('Campaign: ', SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(pys_data.meta_value, 'pys_utm', -1), 'utm_campaign:', -1), '|', 1))
                            ELSE 'Direct'
                        END AS origin,
                        COUNT(posts.ID) AS order_count
                     FROM
                        {$wpdb->prefix}posts AS posts
                     JOIN
                        {$wpdb->prefix}postmeta AS pys_data ON posts.ID = pys_data.post_id
                     WHERE
                        posts.post_type = 'shop_order'
                        AND posts.post_status NOT IN ('trash', 'auto-draft')
                        AND pys_data.meta_key = 'pys_enrich_data'
                        AND (pys_data.meta_value LIKE '%pys_utm%' AND (pys_data.meta_value LIKE '%utm_source:%' OR pys_data.meta_value LIKE '%0138%' OR pys_data.meta_value LIKE '%utm_medium:paid%'))
                        AND posts.post_date >= %s AND posts.post_date < DATE_ADD(%s, INTERVAL 1 DAY)";
        } else {
            // Fallback to custom origin tracking
            $query = "SELECT
                meta.meta_value AS origin,
                COUNT(posts.ID) AS order_count
             FROM
                {$wpdb->prefix}posts AS posts
             JOIN
                {$wpdb->prefix}postmeta AS meta ON posts.ID = meta.post_id
             WHERE
                posts.post_type = 'shop_order'
                AND posts.post_status NOT IN ('trash', 'auto-draft')
                AND meta.meta_key = '_order_origin'
                AND posts.post_date >= %s AND posts.post_date < DATE_ADD(%s, INTERVAL 1 DAY)";
        }

        $query_params = [ $start_date, $end_date ];

        // Add meta value filtering if specific values are selected
        $filter_conditions = [];
        
        if ( ! empty( $selected_utm_sources ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $selected_utm_sources ), '%s' ) );
            if ($use_wc_attribution) {
                $filter_conditions[] = "oa.source IN ($placeholders)";
            } elseif ($use_wc_meta) {
                $filter_conditions[] = "source.meta_value IN ($placeholders)";
            } elseif ($use_post_meta) {
                $filter_conditions[] = "utm_source.meta_value IN ($placeholders)";
            } elseif ($use_pys_data) {
                $filter_conditions[] = "SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(pys_data.meta_value, 'pys_utm', -1), 'utm_source:', -1), '|', 1) IN ($placeholders)";
            }
            $query_params = array_merge( $query_params, $selected_utm_sources );
        }
        
        if ( ! empty( $selected_utm_mediums ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $selected_utm_mediums ), '%s' ) );
            if ($use_wc_attribution) {
                $filter_conditions[] = "oa.medium IN ($placeholders)";
            } elseif ($use_wc_meta) {
                $filter_conditions[] = "medium.meta_value IN ($placeholders)";
            } elseif ($use_post_meta) {
                $filter_conditions[] = "utm_medium.meta_value IN ($placeholders)";
            } elseif ($use_pys_data) {
                $filter_conditions[] = "SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(pys_data.meta_value, 'pys_utm', -1), 'utm_medium:', -1), '|', 1) IN ($placeholders)";
            }
            $query_params = array_merge( $query_params, $selected_utm_mediums );
        }
        
        if ( ! empty( $selected_utm_campaigns ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $selected_utm_campaigns ), '%s' ) );
            if ($use_wc_attribution) {
                $filter_conditions[] = "oa.campaign IN ($placeholders)";
            } elseif ($use_wc_meta) {
                $filter_conditions[] = "campaign.meta_value IN ($placeholders)";
            } elseif ($use_post_meta) {
                $filter_conditions[] = "utm_campaign.meta_value IN ($placeholders)";
            } elseif ($use_pys_data) {
                $filter_conditions[] = "SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(pys_data.meta_value, 'pys_utm', -1), 'utm_campaign:', -1), '|', 1) IN ($placeholders)";
            }
            $query_params = array_merge( $query_params, $selected_utm_campaigns );
        }
        
        if ( ! empty( $selected_utm_terms ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $selected_utm_terms ), '%s' ) );
            if ($use_wc_attribution) {
                $filter_conditions[] = "oa.term IN ($placeholders)";
            } elseif ($use_wc_meta) {
                $filter_conditions[] = "utm_term.meta_value IN ($placeholders)";
            } elseif ($use_post_meta) {
                $filter_conditions[] = "utm_term.meta_value IN ($placeholders)";
            } elseif ($use_pys_data) {
                $filter_conditions[] = "SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(pys_data.meta_value, 'pys_utm', -1), 'utm_term:', -1), '|', 1) IN ($placeholders)";
            }
            $query_params = array_merge( $query_params, $selected_utm_terms );
        }
        
        if ( ! empty( $selected_utm_contents ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $selected_utm_contents ), '%s' ) );
            if ($use_wc_attribution) {
                $filter_conditions[] = "oa.content IN ($placeholders)";
            } elseif ($use_wc_meta) {
                $filter_conditions[] = "utm_content.meta_value IN ($placeholders)";
            } elseif ($use_post_meta) {
                $filter_conditions[] = "utm_content.meta_value IN ($placeholders)";
            } elseif ($use_pys_data) {
                $filter_conditions[] = "SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(pys_data.meta_value, 'pys_utm', -1), 'utm_content:', -1), '|', 1) IN ($placeholders)";
            }
            $query_params = array_merge( $query_params, $selected_utm_contents );
        }
        
        if ( ! empty( $filter_conditions ) ) {
            $query .= " AND (" . implode( ' OR ', $filter_conditions ) . ")";
        }

        $query .= " GROUP BY origin ORDER BY order_count DESC";

        // Check if we need to calculate percentage comparison (Today vs Yesterday)
        $comparison_data = null;
        
        // Use WordPress's timezone-aware current date instead of manual calculations
        $wp_today = current_time('Y-m-d');
        $is_today_report = ($start_date === $wp_today && $end_date === $wp_today);
        
        // Use optimized logic for Today's report with UTM data
        if ($is_today_report && $use_post_meta) {
            // Debug: Log that optimized method is being used
            error_log('WCOT: Using IMPROVED WP_Query Today filter logic - WordPress handles timezones properly');
            
                         // Use the more reliable WP_Query method that gets ALL today's orders
             // WP_Query handles timezones properly unlike manual timestamp calculations
             $all_todays_orders = $this->get_all_todays_orders_wp_query();
             
             // Process ALL orders and group by origin (including Direct)
             $origin_groups = [];
             foreach ($all_todays_orders as $order) {
                 $origin = $order['origin'];
                 if (!isset($origin_groups[$origin])) {
                     $origin_groups[$origin] = [
                         'origin' => $origin,
                         'order_count' => 0,
                     ];
                 }
                 $origin_groups[$origin]['order_count']++;
             }
             
             // Convert to the expected format
             $results = array_map(function($group) {
                 return (object) $group;
             }, $origin_groups);
             
             // Sort by order count descending
             usort($results, function($a, $b) {
                 return $b->order_count - $a->order_count;
             });
        } else {
            // Execute the standard query for non-today reports or other data sources
            $results = $wpdb->get_results( $wpdb->prepare( $query, $query_params ) );
        }
        
        // Apply origin grouping/normalization to results
        if (!empty($results)) {
            $results = $this->group_results_by_origin($results);
        }
        
        if ($is_today_report) {
            // For today's report, we need to modify the query to use current datetime
            // Use corrected timestamp (server or WordPress)
            $today_start = $today_date . ' 00:00:01';
            $today_end = date( 'Y-m-d H:i:s', $use_timestamp ); // Current datetime
            
            // Get yesterday's data for comparison (full day)
            $yesterday = date( 'Y-m-d', strtotime( '-1 day', $use_timestamp ) );
            $yesterday_start = $yesterday . ' 00:00:01';
            $yesterday_end = $yesterday . ' 23:59:59';
            
            // Create modified queries for datetime comparison
            $today_query = $this->modify_query_for_datetime($query, $use_wc_attribution, $use_wc_meta, $use_post_meta, $use_pys_data);
            $yesterday_query = $this->modify_query_for_datetime($query, $use_wc_attribution, $use_wc_meta, $use_post_meta, $use_pys_data);
            
            // Create today query parameters with datetime
            $today_query_params = [ $today_start, $today_end ];
            
            // Add the same filter parameters that were used for today
            if ( ! empty( $selected_utm_sources ) ) {
                $today_query_params = array_merge( $today_query_params, $selected_utm_sources );
            }
            if ( ! empty( $selected_utm_mediums ) ) {
                $today_query_params = array_merge( $today_query_params, $selected_utm_mediums );
            }
            if ( ! empty( $selected_utm_campaigns ) ) {
                $today_query_params = array_merge( $today_query_params, $selected_utm_campaigns );
            }
            if ( ! empty( $selected_utm_terms ) ) {
                $today_query_params = array_merge( $today_query_params, $selected_utm_terms );
            }
            if ( ! empty( $selected_utm_contents ) ) {
                $today_query_params = array_merge( $today_query_params, $selected_utm_contents );
            }
            
            // Create yesterday query parameters with datetime
            $yesterday_query_params = [ $yesterday_start, $yesterday_end ];
            
            // Add the same filter parameters for yesterday
            if ( ! empty( $selected_utm_sources ) ) {
                $yesterday_query_params = array_merge( $yesterday_query_params, $selected_utm_sources );
            }
            if ( ! empty( $selected_utm_mediums ) ) {
                $yesterday_query_params = array_merge( $yesterday_query_params, $selected_utm_mediums );
            }
            if ( ! empty( $selected_utm_campaigns ) ) {
                $yesterday_query_params = array_merge( $yesterday_query_params, $selected_utm_campaigns );
            }
            if ( ! empty( $selected_utm_terms ) ) {
                $yesterday_query_params = array_merge( $yesterday_query_params, $selected_utm_terms );
            }
            if ( ! empty( $selected_utm_contents ) ) {
                $yesterday_query_params = array_merge( $yesterday_query_params, $selected_utm_contents );
            }
            
            // Execute both queries with proper preparation
            $results = $wpdb->get_results( $wpdb->prepare( $today_query, $today_query_params ) );
            $yesterday_results = $wpdb->get_results( $wpdb->prepare( $yesterday_query, $yesterday_query_params ) );
            
            // Debug: Log the queries being executed
            error_log('Today Query: ' . $wpdb->prepare( $today_query, $today_query_params ));
            error_log('Yesterday Query: ' . $wpdb->prepare( $yesterday_query, $yesterday_query_params ));
            error_log('Today Results: ' . print_r($results, true));
            error_log('Yesterday Results: ' . print_r($yesterday_results, true));
            
            // Simple debug query to check if there are ANY orders today
            $simple_today_check = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}posts 
                 WHERE post_type = 'shop_order' 
                 AND post_status NOT IN ('trash', 'auto-draft')
                 AND post_date >= %s 
                 AND post_date <= %s",
                $today_start,
                $today_end
            ));
            error_log('Simple Today Check (any orders): ' . $simple_today_check);
            
            // Calculate totals for comparison
            $today_total_orders = array_sum(array_column($results, 'order_count'));
            
            $yesterday_total_orders = array_sum(array_column($yesterday_results, 'order_count'));
            
            // Calculate percentage changes
            $order_change = $yesterday_total_orders > 0 ? 
                (($today_total_orders - $yesterday_total_orders) / $yesterday_total_orders) * 100 : 
                ($today_total_orders > 0 ? 100 : 0);
            
            $comparison_data = [
                'yesterday_orders' => $yesterday_total_orders,
                'today_orders' => $today_total_orders,
                'order_change' => $order_change,
                'debug' => [
                    'today_start' => $today_start,
                    'today_end' => $today_end,
                    'yesterday_start' => $yesterday_start,
                    'yesterday_end' => $yesterday_end,
                    'today_results_count' => count($results),
                    'yesterday_results_count' => count($yesterday_results),
                    'query_used' => $use_wc_attribution ? 'wc_attribution' : ($use_wc_meta ? 'wc_meta' : ($use_post_meta ? 'post_meta' : ($use_pys_data ? 'pys_data' : 'custom'))),
                    'is_today_report' => $is_today_report,
                    'start_date' => $start_date,
                    'end_date' => $end_date,
                    'current_time' => date( 'Y-m-d H:i:s', $use_timestamp ),
                    'wp_timezone' => wp_timezone_string(),
                    'simple_today_check' => $simple_today_check,
                    'calculated_today_date' => $today_date,
                    'server_date' => date('Y-m-d'),
                    'wp_timestamp' => current_time( 'timestamp' ),
                    'server_timestamp' => time(),
                    'date_source' => $date_source,
                    'use_timestamp' => $use_timestamp,
                    'wp_date' => $wp_date,
                    'server_date_full' => $server_date,
                    'manual_override' => get_option('wcot_manual_date_override', 'None'),
                    'fallback_used' => $date_source === 'fallback_2025',
                    'override_timestamp' => isset($override_timestamp) ? date('Y-m-d H:i:s', $override_timestamp) : 'N/A',
                    'use_timestamp_date' => date('Y-m-d H:i:s', $use_timestamp)
                ]
            ];
        }
        
        // Get recent orders for debugging
        $recent_orders = $this->get_recent_orders_with_origin();
        
        // Calculate ROAS data
        $roas_data = $this->calculate_roas_data($results, $start_date, $end_date);
        
        // Prepare data for charts
        $chart_data = [
            'labels' => [],
            'values' => [],
            'colors' => [],
        ];
        
        // Define color palette for charts
        $color_palette = [
            '#1f77b4', '#ff7f0e', '#2ca02c', '#d62728', '#9467bd',
            '#8c564b', '#e377c2', '#7f7f7f', '#bcbd22', '#17becf',
            '#aec7e8', '#ffbb78', '#98df8a', '#ff9896', '#c5b0d5'
        ];
        
        $has_fb_ads_data = false;
        foreach ($results as $index => $result) {
            $chart_data['labels'][] = $result->origin;
            $chart_data['values'][] = (int) $result->order_count;
            $chart_data['colors'][] = $color_palette[$index % count($color_palette)];
            
            // Check if we have FB ADS data
            if ($result->origin === 'Sales from FB ADS') {
                $has_fb_ads_data = true;
            }
        }
        ?>
        
        <!-- ApexCharts CSS and JS -->
        <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
        
        <!-- Chart Styling -->
        <style>
            .chart-section {
                background: #f8f9fa;
                border-radius: 8px;
                padding: 20px;
                margin-bottom: 30px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }
            
            .chart-section h2 {
                color: #2c3e50;
                border-bottom: 2px solid #3498db;
                padding-bottom: 10px;
                margin-bottom: 20px;
            }
            
            .chart-controls {
                display: flex;
                gap: 10px;
                margin-bottom: 20px;
                flex-wrap: wrap;
            }
            
            .chart-controls .button {
                transition: all 0.3s ease;
            }
            
            .chart-controls .button:hover {
                transform: translateY(-1px);
                box-shadow: 0 2px 4px rgba(0,0,0,0.2);
            }
            
            .chart-container {
                background: #ffffff;
                border: 1px solid #e1e8ed;
                border-radius: 8px;
                padding: 20px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                overflow: hidden;
            }
            
            @media (max-width: 768px) {
                .chart-controls {
                    flex-direction: column;
                    align-items: flex-start;
                }
                
                .chart-controls .button {
                    width: 100%;
                    margin-bottom: 5px;
                }
                
                .chart-container {
                    padding: 15px;
                }
            }
        </style>
        
        <div class="wrap">
            <h1><?php esc_html_e( 'Order Origin Report', 'wc-order-origin-tracker' ); ?></h1>
            <p><?php esc_html_e( 'See where your orders are coming from. The tracking cookie is set on a visitor\'s first visit.', 'wc-order-origin-tracker' ); ?></p>
            <p style="color: #0073aa; font-size: 14px;"><em><?php esc_html_e( 'Default report shows the last 3 days. Use "Last 3 days" button or date filters to adjust the range.', 'wc-order-origin-tracker' ); ?></em></p>
            
            <?php if ($use_pys_data) : ?>
            <div style="background: #e8f4fd; border: 1px solid #0066cc; border-radius: 6px; padding: 10px; margin-bottom: 20px;">
                <p style="margin: 0; color: #0066cc; font-size: 14px;">
                    📊 <strong>PixelYourSite Integration Active:</strong> This report includes orders with UTM data from PixelYourSite enrich data.
                </p>
            </div>
            <?php endif; ?>
            
            <!-- Facebook Ad Set ID Detection Notice -->
            <div style="background: #f0f8ff; border: 1px solid #0073aa; border-radius: 6px; padding: 10px; margin-bottom: 20px;">
                <p style="margin: 0; color: #0073aa; font-size: 14px;">
                    🎯 <strong>Enhanced Facebook Ads Detection:</strong> This system now automatically detects and groups Facebook Ad Set IDs (like 120226672319150138) into "Sales from FB ADS" for better tracking.
                </p>
            </div>
            

            
            <!-- Manual Date Override Form (for server clock issues) -->
            <?php if ( $date_source === 'fallback_2025' || $wp_date > '2027-01-01' || !empty(get_option('wcot_manual_date_override', '')) ) : ?>
            <div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 6px; padding: 15px; margin-bottom: 20px;">
                <h3 style="margin-top: 0; color: #856404;">⚙️ Manual Date Override</h3>
                <p>Your server's system clock appears to be incorrect. You can manually set the current date below:</p>
                
                <form method="post" action="" style="display: inline-block;">
                    <?php wp_nonce_field( 'wcot_date_override_action', 'wcot_date_override_nonce' ); ?>
                    <label for="manual_date_override"><strong>Current Date (YYYY-MM-DD):</strong></label>
                    <input type="date" id="manual_date_override" name="manual_date_override" 
                           value="<?php echo esc_attr( get_option('wcot_manual_date_override', '') ); ?>" 
                           style="margin: 0 10px;">
                    <input type="submit" value="Set Date" class="button button-secondary">
                    <input type="submit" value="Remove Override" class="button button-secondary" 
                           onclick="document.getElementById('manual_date_override').value = ''; return true;">
                </form>
                
                <p style="font-size: 12px; color: #856404; margin-top: 10px;">
                    <strong>Note:</strong> This is a temporary workaround. Please contact your hosting provider to fix the server's system clock permanently.
                    Current override: <?php echo esc_html( get_option('wcot_manual_date_override', 'None') ); ?>
                </p>
            </div>
            <?php endif; ?>
            
            <form method="post" action="">
                <?php wp_nonce_field( 'wcot_report_action', 'wcot_report_nonce' ); ?>
                
                <div style="margin-bottom: 15px;">
                    <!-- Quick Filter Buttons -->
                    <div style="margin-bottom: 15px;">
                        <label><strong><?php esc_html_e( 'Quick Filters:', 'wc-order-origin-tracker' ); ?></strong></label>
                        <span style="font-size: 12px; color: #0073aa; margin-left: 10px;">
                            (WordPress timezone-aware - Today: <?php echo esc_html( $wp_today ); ?>)
                        </span><br>
                        <button type="button" class="button" onclick="setDateRange('last_3_days')" style="margin-right: 10px; margin-top: 5px;">
                            <?php esc_html_e( 'Last 3 days', 'wc-order-origin-tracker' ); ?>
                        </button>
                        <button type="button" class="button" onclick="setDateRange('yesterday')" style="margin-right: 10px; margin-top: 5px;">
                            <?php esc_html_e( 'Yesterday', 'wc-order-origin-tracker' ); ?>
                        </button>
                        <button type="button" class="button" onclick="setDateRange('this_month')" style="margin-right: 10px; margin-top: 5px;">
                            <?php esc_html_e( 'This Month', 'wc-order-origin-tracker' ); ?>
                        </button>
                    </div>
                    
                    <!-- Date Range Inputs -->
                    <div>
                        <label for="start_date"><strong><?php esc_html_e( 'Start Date:', 'wc-order-origin-tracker' ); ?></strong></label>
                        <input type="date" id="start_date" name="start_date" value="<?php echo esc_attr( $start_date ); ?>">
                        
                        <label for="end_date" style="margin-left: 15px;"><strong><?php esc_html_e( 'End Date:', 'wc-order-origin-tracker' ); ?></strong></label>
                        <input type="date" id="end_date" name="end_date" value="<?php echo esc_attr( $end_date ); ?>">
                    </div>
                </div>


                
                <!-- UTM Filters Section -->
                <?php if (!empty($available_utm_sources) || !empty($available_utm_mediums) || !empty($available_utm_campaigns) || !empty($available_utm_terms) || !empty($available_utm_contents)) : ?>
                <div style="margin-top: 20px; padding: 15px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 6px;">
                    <h3 style="margin-top: 0; color: #495057;">
                        🎯 <?php esc_html_e( 'UTM Parameter Filters', 'wc-order-origin-tracker' ); ?>
                        <span style="font-size: 14px; color: #6c757d; margin-left: 10px;">
                            (<?php echo esc_html( $total_orders_with_origin ); ?> total orders with UTM data)
                        </span>
                    </h3>
                    
                    <!-- UTM Summary Stats -->
                    <div style="background: #ffffff; border: 1px solid #dee2e6; border-radius: 4px; padding: 10px; margin-bottom: 15px; display: flex; gap: 20px; flex-wrap: wrap; font-size: 12px;">
                        <?php if (!empty($available_utm_sources)) : ?>
                        <span><strong>Sources:</strong> <?php echo count($available_utm_sources); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($available_utm_mediums)) : ?>
                        <span><strong>Mediums:</strong> <?php echo count($available_utm_mediums); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($available_utm_campaigns)) : ?>
                        <span><strong>Campaigns:</strong> <?php echo count($available_utm_campaigns); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($available_utm_terms)) : ?>
                        <span><strong>Terms:</strong> <?php echo count($available_utm_terms); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($available_utm_contents)) : ?>
                        <span><strong>Contents:</strong> <?php echo count($available_utm_contents); ?></span>
                        <?php endif; ?>
                        
                        <?php 
                        $active_filters = 0;
                        $active_filters += count($selected_utm_sources);
                        $active_filters += count($selected_utm_mediums);
                        $active_filters += count($selected_utm_campaigns);
                        $active_filters += count($selected_utm_terms);
                        $active_filters += count($selected_utm_contents);
                        ?>
                        <?php if ($active_filters > 0) : ?>
                        <span style="color: #0073aa; font-weight: bold;">
                            <strong>Active Filters:</strong> <?php echo $active_filters; ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                        
                        <!-- UTM Source Filter -->
                        <?php if (!empty($available_utm_sources)) : ?>
                        <div>
                            <label style="font-weight: bold; display: block; margin-bottom: 5px;">
                                <?php esc_html_e( 'UTM Source', 'wc-order-origin-tracker' ); ?> (<?php echo count($available_utm_sources); ?>)
                            </label>
                            <div style="max-height: 150px; overflow-y: auto; border: 1px solid #ccc; padding: 8px; background: white; border-radius: 4px;">
                                <?php foreach ($available_utm_sources as $source) : ?>
                                <label style="display: block; margin-bottom: 3px; font-weight: normal;">
                                    <input type="checkbox" name="selected_utm_sources[]" value="<?php echo esc_attr($source->value); ?>" 
                                           <?php echo in_array($source->value, $selected_utm_sources) ? 'checked' : ''; ?>>
                                    <?php echo esc_html($source->value); ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- UTM Medium Filter -->
                        <?php if (!empty($available_utm_mediums)) : ?>
                        <div>
                            <label style="font-weight: bold; display: block; margin-bottom: 5px;">
                                <?php esc_html_e( 'UTM Medium', 'wc-order-origin-tracker' ); ?> (<?php echo count($available_utm_mediums); ?>)
                            </label>
                            <div style="max-height: 150px; overflow-y: auto; border: 1px solid #ccc; padding: 8px; background: white; border-radius: 4px;">
                                <?php foreach ($available_utm_mediums as $medium) : ?>
                                <label style="display: block; margin-bottom: 3px; font-weight: normal;">
                                    <input type="checkbox" name="selected_utm_mediums[]" value="<?php echo esc_attr($medium->value); ?>" 
                                           <?php echo in_array($medium->value, $selected_utm_mediums) ? 'checked' : ''; ?>>
                                    <?php echo esc_html($medium->value); ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- UTM Campaign Filter -->
                        <?php if (!empty($available_utm_campaigns)) : ?>
                        <div>
                            <label style="font-weight: bold; display: block; margin-bottom: 5px;">
                                <?php esc_html_e( 'UTM Campaign', 'wc-order-origin-tracker' ); ?> (<?php echo count($available_utm_campaigns); ?>)
                            </label>
                            <div style="max-height: 150px; overflow-y: auto; border: 1px solid #ccc; padding: 8px; background: white; border-radius: 4px;">
                                <?php foreach ($available_utm_campaigns as $campaign) : ?>
                                <label style="display: block; margin-bottom: 3px; font-weight: normal;">
                                    <input type="checkbox" name="selected_utm_campaigns[]" value="<?php echo esc_attr($campaign->value); ?>" 
                                           <?php echo in_array($campaign->value, $selected_utm_campaigns) ? 'checked' : ''; ?>>
                                    <?php echo esc_html($campaign->value); ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- UTM Term Filter -->
                        <?php if (!empty($available_utm_terms)) : ?>
                        <div>
                            <label style="font-weight: bold; display: block; margin-bottom: 5px;">
                                <?php esc_html_e( 'UTM Term', 'wc-order-origin-tracker' ); ?> (<?php echo count($available_utm_terms); ?>)
                            </label>
                            <div style="max-height: 150px; overflow-y: auto; border: 1px solid #ccc; padding: 8px; background: white; border-radius: 4px;">
                                <?php foreach ($available_utm_terms as $term) : ?>
                                <label style="display: block; margin-bottom: 3px; font-weight: normal;">
                                    <input type="checkbox" name="selected_utm_terms[]" value="<?php echo esc_attr($term->value); ?>" 
                                           <?php echo in_array($term->value, $selected_utm_terms) ? 'checked' : ''; ?>>
                                    <?php echo esc_html($term->value); ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- UTM Content Filter -->
                        <?php if (!empty($available_utm_contents)) : ?>
                        <div>
                            <label style="font-weight: bold; display: block; margin-bottom: 5px;">
                                <?php esc_html_e( 'UTM Content', 'wc-order-origin-tracker' ); ?> (<?php echo count($available_utm_contents); ?>)
                            </label>
                            <div style="max-height: 150px; overflow-y: auto; border: 1px solid #ccc; padding: 8px; background: white; border-radius: 4px;">
                                <?php foreach ($available_utm_contents as $content) : ?>
                                <label style="display: block; margin-bottom: 3px; font-weight: normal;">
                                    <input type="checkbox" name="selected_utm_contents[]" value="<?php echo esc_attr($content->value); ?>" 
                                           <?php echo in_array($content->value, $selected_utm_contents) ? 'checked' : ''; ?>>
                                    <?php echo esc_html($content->value); ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                    </div>
                    
                    <!-- Filter Control Buttons -->
                    <div style="margin-top: 15px; display: flex; gap: 10px; flex-wrap: wrap;">
                        <button type="button" class="button button-secondary" onclick="selectAllUTM()">
                            <?php esc_html_e( 'Select All', 'wc-order-origin-tracker' ); ?>
                        </button>
                        <button type="button" class="button button-secondary" onclick="clearAllUTM()">
                            <?php esc_html_e( 'Clear All', 'wc-order-origin-tracker' ); ?>
                        </button>
                        <span style="font-size: 12px; color: #6c757d; align-self: center; margin-left: 10px;">
                            <?php esc_html_e( 'Leave empty to show all UTM parameters', 'wc-order-origin-tracker' ); ?>
                        </span>
                    </div>
                </div>
                
                <!-- JavaScript for UTM Filter Controls -->
                <script>
                    function selectAllUTM() {
                        document.querySelectorAll('input[name^="selected_utm_"]').forEach(checkbox => {
                            checkbox.checked = true;
                        });
                    }
                    
                    function clearAllUTM() {
                        document.querySelectorAll('input[name^="selected_utm_"]').forEach(checkbox => {
                            checkbox.checked = false;
                        });
                    }
                </script>
                <?php endif; ?>
                
                <input type="submit" value="<?php esc_attr_e( 'Filter Report', 'wc-order-origin-tracker' ); ?>" class="button button-primary">
                

            </form>

            <!-- JavaScript for Quick Date Filters -->
            <script>
                function setDateRange(period) {
                    // Use WordPress's timezone-aware current date
                    const todayStr = '<?php echo esc_js( $wp_today ); ?>';
                    const today = new Date(todayStr + 'T12:00:00'); // Use noon to avoid timezone issues
                    const yesterday = new Date(today);
                    yesterday.setDate(yesterday.getDate() - 1);
                    
                    let startDate, endDate;
                    
                    switch(period) {
                        case 'last_3_days':
                            startDate = new Date(today);
                            startDate.setDate(startDate.getDate() - 3);
                            endDate = today;
                            break;
                            
                        case 'today':
                            startDate = today;
                            endDate = today;
                            break;
                            
                        case 'yesterday':
                            startDate = yesterday;
                            endDate = yesterday;
                            break;
                            
                        case 'this_month':
                            startDate = new Date(today.getFullYear(), today.getMonth(), 1);
                            endDate = new Date(today.getFullYear(), today.getMonth() + 1, 0);
                            break;
                    }
                    
                    // Format dates as YYYY-MM-DD
                    const formatDate = (date) => {
                        return date.getFullYear() + '-' + 
                               String(date.getMonth() + 1).padStart(2, '0') + '-' + 
                               String(date.getDate()).padStart(2, '0');
                    };
                    
                    // Set the date inputs
                    document.getElementById('start_date').value = formatDate(startDate);
                    document.getElementById('end_date').value = formatDate(endDate);
                    
                    // Debug: Log the dates being set
                    console.log('WCOT: Setting date range for period "' + period + '":', {
                        period: period,
                        startDate: formatDate(startDate),
                        endDate: formatDate(endDate),
                        wpToday: todayStr,
                        browserToday: formatDate(new Date()),
                        usingWPTimezone: true
                    });
                    
                    // Automatically submit the form
                    document.querySelector('form').submit();
                }
            </script>

            <hr>

            <?php if ( empty( $results ) ) : ?>
                <p><?php esc_html_e( 'No orders with origin data found for the selected date range.', 'wc-order-origin-tracker' ); ?></p>
            <?php else : ?>
                <!-- Charts Section -->
                <?php if (!empty($chart_data['labels'])) : ?>
                <div class="chart-section">
                    <h2>
                        <?php esc_html_e( 'Visual Analytics', 'wc-order-origin-tracker' ); ?>
                        <?php if ($has_fb_ads_data) : ?>
                            <span style="font-size: 14px; color: #0073aa; margin-left: 10px;">
                                📊 <em>FB ADS data grouped</em>
                            </span>
                        <?php endif; ?>
                    </h2>
                    
                    <!-- Chart Toggle Buttons -->
                    <div class="chart-controls">
                        <button type="button" class="button button-primary" onclick="showChart('orders')" id="btn-orders-chart">
                            📊 <?php esc_html_e( 'Orders Count', 'wc-order-origin-tracker' ); ?>
                        </button>
                        <button type="button" class="button button-secondary" onclick="toggleChartType()" id="btn-toggle-type">
                            🔄 <?php esc_html_e( 'Switch to Bar Chart', 'wc-order-origin-tracker' ); ?>
                        </button>
                    </div>
                    
                    <!-- Chart Container -->
                    <div class="chart-container">
                        <div id="chart-container" style="height: 400px;"></div>
                    </div>
                </div>
                <?php endif; ?>
                
                <h2><?php printf( esc_html__( 'Report from %s to %s', 'wc-order-origin-tracker' ), '<strong>' . esc_html( $start_date ) . '</strong>', '<strong>' . esc_html( $end_date ) . '</strong>' ); ?></h2>
                
                <?php if ( $comparison_data ) : ?>
                    <!-- Today vs Yesterday Comparison -->
                    <div style="background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 6px; padding: 15px; margin-bottom: 20px;">
                        <h3 style="margin-top: 0; color: #495057;">
                            <?php esc_html_e( 'Today vs Yesterday Comparison', 'wc-order-origin-tracker' ); ?> 
                            <span style="font-size: 14px; font-weight: normal; color: #28a745;">
                                (Real-time up to <?php echo esc_html( date( 'H:i', $use_timestamp ) ); ?> - <?php echo esc_html( strtoupper($date_source) ); ?>)
                            </span>
                        </h3>
                        
                        <!-- Debug Info -->
                        <div style="font-size: 12px; color: #6c757d; margin-bottom: 10px; font-family: monospace;">
                            <strong>Debug:</strong> Today: <?php echo esc_html( $comparison_data['debug']['today_start'] ); ?> to <?php echo esc_html( $comparison_data['debug']['today_end'] ); ?> 
                            (<?php echo esc_html( $comparison_data['debug']['today_results_count'] ); ?> results) | 
                            Yesterday: <?php echo esc_html( $comparison_data['debug']['yesterday_start'] ); ?> to <?php echo esc_html( $comparison_data['debug']['yesterday_end'] ); ?> 
                            (<?php echo esc_html( $comparison_data['debug']['yesterday_results_count'] ); ?> results)<br>
                            <strong>Info:</strong> Query: <?php echo esc_html( $comparison_data['debug']['query_used'] ); ?> | 
                            Is Today: <?php echo esc_html( $comparison_data['debug']['is_today_report'] ? 'Yes' : 'No' ); ?> | 
                            Current Time: <?php echo esc_html( $comparison_data['debug']['current_time'] ); ?> | 
                            Timezone: <?php echo esc_html( $comparison_data['debug']['wp_timezone'] ); ?><br>
                            <strong>Dates:</strong> Input: <?php echo esc_html( $comparison_data['debug']['start_date'] ); ?> to <?php echo esc_html( $comparison_data['debug']['end_date'] ); ?> | 
                            Calculated Today: <?php echo esc_html( $comparison_data['debug']['calculated_today_date'] ); ?> | 
                            <strong style="color: green;">Date Source: <?php echo esc_html( strtoupper($comparison_data['debug']['date_source']) ); ?></strong> | 
                            Manual Override: <?php echo esc_html( $comparison_data['debug']['manual_override'] ); ?> | 
                            Use Timestamp: <?php echo esc_html( $comparison_data['debug']['use_timestamp_date'] ); ?> | 
                            Any Orders Today: <?php echo esc_html( $comparison_data['debug']['simple_today_check'] ); ?>
                        </div>
                        
                        <div style="display: flex; gap: 30px; flex-wrap: wrap;">
                            <!-- Orders Comparison -->
                            <div style="flex: 1; min-width: 200px;">
                                <div style="font-size: 14px; color: #6c757d; margin-bottom: 5px;">
                                    <?php esc_html_e( 'Orders', 'wc-order-origin-tracker' ); ?>
                                </div>
                                <div style="font-size: 24px; font-weight: bold; color: #212529;">
                                    <?php echo esc_html( $comparison_data['today_orders'] ); ?>
                                </div>
                                <div style="font-size: 12px; color: #6c757d;">
                                    <?php esc_html_e( 'Yesterday:', 'wc-order-origin-tracker' ); ?> <?php echo esc_html( $comparison_data['yesterday_orders'] ); ?>
                                </div>
                                <div style="font-size: 14px; margin-top: 5px;">
                                    <?php 
                                    $order_change = $comparison_data['order_change'];
                                    $color = $order_change >= 0 ? '#28a745' : '#dc3545';
                                    $arrow = $order_change >= 0 ? '↗' : '↘';
                                    ?>
                                    <span style="color: <?php echo esc_attr( $color ); ?>; font-weight: bold;">
                                        <?php echo esc_html( $arrow ); ?> <?php echo esc_html( number_format( abs( $order_change ), 1 ) ); ?>%
                                    </span>
                                </div>
                            </div>
                            
                        </div>
                    </div>
                <?php endif; ?>

                <!-- ROAS Analysis Section -->
                <?php if ( $roas_data['has_facebook_sales'] ) : ?>
                <div style="background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 6px; padding: 20px; margin-bottom: 20px;">
                    <h3 style="margin-top: 0; color: #495057;">
                        📊 <?php esc_html_e( 'Facebook Ads ROAS Analysis', 'wc-order-origin-tracker' ); ?>
                        <span style="font-size: 14px; color: #6c757d; margin-left: 10px;">
                            (<?php echo esc_html( $start_date ); ?> to <?php echo esc_html( $end_date ); ?>)
                        </span>
                    </h3>
                    
                    <!-- Ad Spend Input Form -->
                    <div style="background: #ffffff; border: 1px solid #dee2e6; border-radius: 4px; padding: 15px; margin-bottom: 15px;">
                        <form method="post" action="" style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                            <?php wp_nonce_field( 'wcot_ad_spend_action', 'wcot_ad_spend_nonce' ); ?>
                            <input type="hidden" name="spend_date_range" value="<?php echo esc_attr( $start_date . '_to_' . $end_date ); ?>">
                            
                            <label for="ad_spend" style="font-weight: bold;">
                                <?php esc_html_e( 'Facebook Ad Spend:', 'wc-order-origin-tracker' ); ?>
                            </label>
                            <input type="number" 
                                   id="ad_spend" 
                                   name="ad_spend" 
                                   step="0.01" 
                                   min="0" 
                                   placeholder="0.00"
                                   value="<?php echo esc_attr( $roas_data['current_ad_spend'] ); ?>"
                                   style="width: 120px; padding: 5px;">
                            <span style="color: #6c757d;"><?php echo esc_html( get_woocommerce_currency_symbol() ); ?></span>
                            
                            <input type="submit" value="<?php esc_attr_e( 'Update Ad Spend', 'wc-order-origin-tracker' ); ?>" class="button button-secondary">
                            
                            <span style="font-size: 12px; color: #6c757d; margin-left: 10px;">
                                <?php esc_html_e( 'Enter your total Facebook ad spend for this date range', 'wc-order-origin-tracker' ); ?>
                            </span>
                        </form>
                    </div>
                    
                    <!-- ROAS Summary -->
                    <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 20px;">
                        <!-- Facebook Sales -->
                        <div style="flex: 1; min-width: 160px; background: #ffffff; border: 1px solid #dee2e6; border-radius: 4px; padding: 15px; text-align: center;">
                            <div style="font-size: 14px; color: #6c757d; margin-bottom: 5px;">
                                <?php esc_html_e( 'FB ADS Sales', 'wc-order-origin-tracker' ); ?>
                            </div>
                            <div style="font-size: 20px; font-weight: bold; color: #28a745;">
                                <?php echo wp_kses_post( wc_price( $roas_data['facebook_sales'] ) ); ?>
                            </div>
                            <div style="font-size: 12px; color: #6c757d;">
                                <?php echo esc_html( $roas_data['facebook_orders'] ); ?> orders × €19
                            </div>
                        </div>
                        
                        <!-- Ad Spend -->
                        <div style="flex: 1; min-width: 160px; background: #ffffff; border: 1px solid #dee2e6; border-radius: 4px; padding: 15px; text-align: center;">
                            <div style="font-size: 14px; color: #6c757d; margin-bottom: 5px;">
                                <?php esc_html_e( 'Ad Spend', 'wc-order-origin-tracker' ); ?>
                            </div>
                            <div style="font-size: 20px; font-weight: bold; color: #dc3545;">
                                <?php echo wp_kses_post( wc_price( $roas_data['current_ad_spend'] ) ); ?>
                            </div>
                            <div style="font-size: 12px; color: #6c757d;">
                                <?php esc_html_e( 'Total investment', 'wc-order-origin-tracker' ); ?>
                            </div>
                        </div>
                        
                        <!-- ROAS -->
                        <div style="flex: 1; min-width: 160px; background: #ffffff; border: 1px solid #dee2e6; border-radius: 4px; padding: 15px; text-align: center;">
                            <div style="font-size: 14px; color: #6c757d; margin-bottom: 5px;">
                                <?php esc_html_e( 'ROAS', 'wc-order-origin-tracker' ); ?>
                            </div>
                            <div style="font-size: 20px; font-weight: bold; color: <?php echo $roas_data['roas_color']; ?>;">
                                <?php echo esc_html( $roas_data['roas_display'] ); ?>
                            </div>
                            <div style="font-size: 12px; color: #6c757d;">
                                <?php echo esc_html( $roas_data['roas_description'] ); ?>
                            </div>
                        </div>
                        
                        <!-- Cost per Order -->
                        <div style="flex: 1; min-width: 160px; background: #ffffff; border: 1px solid #dee2e6; border-radius: 4px; padding: 15px; text-align: center;">
                            <div style="font-size: 14px; color: #6c757d; margin-bottom: 5px;">
                                <?php esc_html_e( 'Cost per Order', 'wc-order-origin-tracker' ); ?>
                            </div>
                            <div style="font-size: 20px; font-weight: bold; color: #495057;">
                                <?php if ($roas_data['cost_per_order'] > 0) : ?>
                                    <?php echo wp_kses_post( wc_price( $roas_data['cost_per_order'] ) ); ?>
                                <?php else : ?>
                                    €0.00
                                <?php endif; ?>
                            </div>
                            <div style="font-size: 12px; color: #6c757d;">
                                <?php esc_html_e( 'Acquisition cost', 'wc-order-origin-tracker' ); ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Profit Analysis Row -->
                    <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                        <!-- Profit per Order -->
                        <div style="flex: 1; min-width: 180px; background: #ffffff; border: 1px solid #dee2e6; border-radius: 4px; padding: 15px; text-align: center;">
                            <div style="font-size: 14px; color: #6c757d; margin-bottom: 5px;">
                                <?php esc_html_e( 'Profit per Order', 'wc-order-origin-tracker' ); ?>
                            </div>
                            <div style="font-size: 20px; font-weight: bold; color: <?php echo $roas_data['profit_per_order'] > 0 ? '#28a745' : '#dc3545'; ?>;">
                                <?php echo wp_kses_post( wc_price( $roas_data['profit_per_order'] ) ); ?>
                            </div>
                            <div style="font-size: 12px; color: #6c757d;">
                                €19 - cost per order
                            </div>
                        </div>
                        
                        <!-- Total Profit -->
                        <div style="flex: 1; min-width: 180px; background: #ffffff; border: 1px solid #dee2e6; border-radius: 4px; padding: 15px; text-align: center;">
                            <div style="font-size: 14px; color: #6c757d; margin-bottom: 5px;">
                                <?php esc_html_e( 'Total Profit', 'wc-order-origin-tracker' ); ?>
                            </div>
                            <div style="font-size: 20px; font-weight: bold; color: <?php echo $roas_data['total_profit'] > 0 ? '#28a745' : '#dc3545'; ?>;">
                                <?php echo wp_kses_post( wc_price( $roas_data['total_profit'] ) ); ?>
                            </div>
                            <div style="font-size: 12px; color: #6c757d;">
                                <?php esc_html_e( 'Net revenue', 'wc-order-origin-tracker' ); ?>
                            </div>
                        </div>
                        
                        <!-- Profit Margin -->
                        <div style="flex: 1; min-width: 180px; background: #ffffff; border: 1px solid #dee2e6; border-radius: 4px; padding: 15px; text-align: center;">
                            <div style="font-size: 14px; color: #6c757d; margin-bottom: 5px;">
                                <?php esc_html_e( 'Profit Margin', 'wc-order-origin-tracker' ); ?>
                            </div>
                            <div style="font-size: 20px; font-weight: bold; color: <?php echo $roas_data['profit_margin'] > 0 ? '#28a745' : '#dc3545'; ?>;">
                                <?php echo esc_html( number_format( $roas_data['profit_margin'], 1 ) ); ?>%
                            </div>
                            <div style="font-size: 12px; color: #6c757d;">
                                <?php esc_html_e( 'Profitability', 'wc-order-origin-tracker' ); ?>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ( $roas_data['current_ad_spend'] > 0 && $roas_data['roas'] > 0 ) : ?>
                    <div style="margin-top: 15px; padding: 15px; background: #e8f4fd; border: 1px solid #0073aa; border-radius: 4px;">
                        <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                            <!-- ROAS Analysis -->
                            <div style="flex: 1; min-width: 300px;">
                                <strong><?php esc_html_e( 'ROAS Analysis:', 'wc-order-origin-tracker' ); ?></strong><br>
                                <?php if ( $roas_data['roas'] >= 4 ) : ?>
                                    <span style="color: #28a745;">✅ <?php esc_html_e( 'Excellent ROAS! Your Facebook ads are performing very well.', 'wc-order-origin-tracker' ); ?></span>
                                <?php elseif ( $roas_data['roas'] >= 2 ) : ?>
                                    <span style="color: #ffc107;">⚠️ <?php esc_html_e( 'Good ROAS. Consider optimizing for better performance.', 'wc-order-origin-tracker' ); ?></span>
                                <?php else : ?>
                                    <span style="color: #dc3545;">❌ <?php esc_html_e( 'Low ROAS. Review your ad targeting and creative strategy.', 'wc-order-origin-tracker' ); ?></span>
                                <?php endif; ?>
                            </div>
                            
                                                         <!-- Profitability Analysis -->
                             <div style="flex: 1; min-width: 300px;">
                                 <strong><?php esc_html_e( 'FB ADS Profitability:', 'wc-order-origin-tracker' ); ?></strong><br>
                                 <?php if ( $roas_data['cost_per_order'] > 0 ) : ?>
                                     <?php if ( $roas_data['cost_per_order'] <= 10 ) : ?>
                                         <span style="color: #28a745;">💰 <?php printf( esc_html__( 'Great! You make €%.2f profit per FB ADS order.', 'wc-order-origin-tracker' ), $roas_data['profit_per_order'] ); ?></span>
                                     <?php elseif ( $roas_data['cost_per_order'] <= 15 ) : ?>
                                         <span style="color: #ffc107;">⚖️ <?php printf( esc_html__( 'Moderate profit of €%.2f per FB ADS order. Consider optimizing.', 'wc-order-origin-tracker' ), $roas_data['profit_per_order'] ); ?></span>
                                     <?php elseif ( $roas_data['cost_per_order'] < 20 ) : ?>
                                         <span style="color: #dc3545;">⚠️ <?php printf( esc_html__( 'Low profit of €%.2f per FB ADS order. Optimize urgently.', 'wc-order-origin-tracker' ), $roas_data['profit_per_order'] ); ?></span>
                                     <?php else : ?>
                                         <span style="color: #dc3545;">❌ <?php printf( esc_html__( 'Loss of €%.2f per FB ADS order! Immediate action needed.', 'wc-order-origin-tracker' ), abs($roas_data['profit_per_order']) ); ?></span>
                                     <?php endif; ?>
                                 <?php else : ?>
                                     <span style="color: #6c757d;">📊 <?php esc_html_e( 'Enter ad spend to analyze FB ADS profitability.', 'wc-order-origin-tracker' ); ?></span>
                                 <?php endif; ?>
                             </div>
                        </div>
                        
                        <!-- Recommendations -->
                        <?php if ( $roas_data['cost_per_order'] > 0 ) : ?>
                        <div style="margin-top: 15px; padding: 10px; background: #f8f9fa; border-radius: 4px;">
                            <strong><?php esc_html_e( '💡 Recommendations:', 'wc-order-origin-tracker' ); ?></strong>
                            <ul style="margin: 5px 0; padding-left: 20px;">
                                <?php if ( $roas_data['cost_per_order'] > 15 ) : ?>
                                    <li><?php esc_html_e( 'Reduce cost per acquisition by improving ad targeting', 'wc-order-origin-tracker' ); ?></li>
                                    <li><?php esc_html_e( 'Test different ad creatives and copy', 'wc-order-origin-tracker' ); ?></li>
                                    <li><?php esc_html_e( 'Consider lowering daily budget until optimization improves', 'wc-order-origin-tracker' ); ?></li>
                                <?php elseif ( $roas_data['cost_per_order'] > 10 ) : ?>
                                    <li><?php esc_html_e( 'Good foundation - optimize for better targeting', 'wc-order-origin-tracker' ); ?></li>
                                    <li><?php esc_html_e( 'A/B test different audiences', 'wc-order-origin-tracker' ); ?></li>
                                <?php else : ?>
                                    <li><?php esc_html_e( 'Excellent performance - consider scaling budget', 'wc-order-origin-tracker' ); ?></li>
                                    <li><?php esc_html_e( 'Test lookalike audiences to maintain efficiency', 'wc-order-origin-tracker' ); ?></li>
                                <?php endif; ?>
                                <li><?php printf( esc_html__( 'Target: Keep cost per order below €9.50 for optimal profit (50%% margin)', 'wc-order-origin-tracker' ) ); ?></li>
                            </ul>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <table class="widefat fixed" cellspacing="0">
                    <thead>
                        <tr>
                            <th class="manage-column" scope="col">Origin</th>
                            <th class="manage-column" scope="col">Number of Orders</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $results as $result ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( $result->origin ); ?></strong></td>
                            <td><?php echo esc_html( $result->order_count ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            
            <!-- Chart JavaScript -->
            <script>
                // Chart data from PHP
                const chartData = <?php echo json_encode($chart_data); ?>;
                
                // Chart configuration
                let currentChart = null;
                let currentDataType = 'orders';
                let currentChartType = 'pie';
                
                                 // Initialize the charts when page loads
                 document.addEventListener('DOMContentLoaded', function() {
                     if (chartData.labels.length > 0) {
                         showChart();
                     }
                 });
                
                // Function to show chart based on data type (orders or sales)
                function showChart() {
                    // Update button states
                    document.getElementById('btn-orders-chart').className = 'button button-primary';
                    
                    // Destroy existing chart
                    if (currentChart) {
                        currentChart.destroy();
                    }
                    
                    // Create new chart
                    createChart(currentChartType);
                }
                
                                 // Function to toggle between pie and bar chart
                 function toggleChartType() {
                     currentChartType = currentChartType === 'pie' ? 'bar' : 'pie';
                     
                     // Update button text
                     document.getElementById('btn-toggle-type').innerHTML = 
                         currentChartType === 'pie' ? '🔄 Switch to Bar Chart' : '🔄 Switch to Pie Chart';
                     
                     // Recreate chart with new type
                     showChart();
                 }
                
                // Function to create chart
                function createChart(chartType) {
                    const values = chartData.values;
                    const title = 'Orders by Origin';
                    
                    let options;
                    
                    if (chartType === 'pie') {
                        options = {
                            series: values,
                            chart: {
                                type: 'pie',
                                height: 400,
                                animations: {
                                    enabled: true,
                                    easing: 'easeinout',
                                    speed: 800
                                }
                            },
                            labels: chartData.labels,
                            colors: chartData.colors,
                            title: {
                                text: title,
                                align: 'center',
                                style: {
                                    fontSize: '18px',
                                    fontWeight: 'bold',
                                    color: '#333'
                                }
                            },
                            legend: {
                                position: 'bottom',
                                horizontalAlign: 'center',
                                fontSize: '14px'
                            },
                            tooltip: {
                                y: {
                                    formatter: function(val) {
                                        return val + ' orders';
                                    }
                                }
                            },
                            plotOptions: {
                                pie: {
                                    donut: {
                                        size: '0%'
                                    },
                                    expandOnClick: true,
                                    customScale: 1
                                }
                            }
                        };
                    } else {
                        options = {
                            series: [{
                                name: 'Orders',
                                data: values
                            }],
                            chart: {
                                type: 'bar',
                                height: 400,
                                animations: {
                                    enabled: true,
                                    easing: 'easeinout',
                                    speed: 800
                                }
                            },
                            xaxis: {
                                categories: chartData.labels,
                                labels: {
                                    style: {
                                        fontSize: '12px'
                                    }
                                }
                            },
                            yaxis: {
                                title: {
                                    text: 'Number of Orders'
                                }
                            },
                            colors: chartData.colors,
                            title: {
                                text: title,
                                align: 'center',
                                style: {
                                    fontSize: '18px',
                                    fontWeight: 'bold',
                                    color: '#333'
                                }
                            },
                            tooltip: {
                                y: {
                                    formatter: function(val) {
                                        return val + ' orders';
                                    }
                                }
                            },
                            plotOptions: {
                                bar: {
                                    borderRadius: 4,
                                    horizontal: false,
                                    columnWidth: '60%'
                                }
                            },
                            grid: {
                                borderColor: '#e7e7e7',
                                row: {
                                    colors: ['#f3f3f3', 'transparent'],
                                    opacity: 0.5
                                }
                            }
                        };
                    }
                    
                    // Create and render chart
                    currentChart = new ApexCharts(document.querySelector("#chart-container"), options);
                    currentChart.render();
                }
            </script>
        </div>
        <?php
    }

    /**
     * Check if a string is a Facebook ad set ID
     *
     * @param string $value The value to check
     * @return bool True if it's a Facebook ad set ID
     */
    private function is_facebook_adset_id($value) {
        // Facebook ad set IDs are typically 15-18 digit numbers
        return preg_match('/^\d{15,18}$/', trim($value));
    }

    /**
     * Check if the origin string contains Facebook ads indicators
     *
     * @param string $origin_lower The origin string in lowercase
     * @return bool True if contains Facebook ads indicators
     */
    private function is_facebook_ads_origin($origin_lower) {
        // Rule 1: Check for Facebook Ad Set IDs (numeric IDs like 120226672319150138)
        // Facebook ad set IDs are typically 15-18 digit numbers
        if (preg_match('/^\d{15,18}$/', trim($origin_lower))) {
            error_log('WCOT: Detected Facebook Ad Set ID: ' . $origin_lower);
            return true;
        }
        
        // Rule 2: Check if origin contains "0138" (Facebook ad campaigns)
        if (strpos($origin_lower, '0138') !== false) {
            // Additional check: if it contains "paid" OR looks like a Facebook campaign ID
            $has_paid = strpos($origin_lower, 'paid') !== false;
            $looks_like_fb_campaign = preg_match('/\d{10,}0138/', $origin_lower);
            
            if ($has_paid || $looks_like_fb_campaign) {
                return true;
            }
        }
        
        // Rule 3: Check for utm_medium:paid (Facebook ads indicator)
        if (strpos($origin_lower, 'utm_medium:paid') !== false || 
            strpos($origin_lower, 'utm: ') !== false && strpos($origin_lower, 'paid') !== false) {
            return true;
        }
        
        // Rule 4: Check for common Facebook ads patterns
        $fb_patterns = [
            'facebook',
            'fb ads',
            'facebook ads',
            'utm_medium:cpc',
            'utm_medium:social',
            'utm_medium:facebook'
        ];
        
        foreach ($fb_patterns as $pattern) {
            if (strpos($origin_lower, $pattern) !== false) {
                return true;
            }
        }
        
        // Rule 5: Check for Facebook ad set ID patterns in UTM or other contexts
        // Match patterns like "UTM: 120226672319150138" or "Origin: 120226672319150138"
        if (preg_match('/(?:utm:|origin:|^)\s*\d{15,18}(?:\s|$)/i', $origin_lower)) {
            error_log('WCOT: Detected Facebook Ad Set ID in UTM context: ' . $origin_lower);
            return true;
        }
        
        // Rule 6: Extract numeric part from formatted origins and check if it's an ad set ID
        if (preg_match('/\d{15,18}/', $origin_lower, $matches)) {
            error_log('WCOT: Found potential Facebook Ad Set ID in origin: ' . $matches[0]);
            return true;
        }
        
        return false;
    }

    /**
     * Normalize origin names and group specific origins
     * Groups origins containing Facebook ads indicators into "Sales from FB ADS"
     *
     * @param string $origin The original origin string
     * @return string The normalized origin
     */
    private function normalize_origin($origin) {
        // Convert to lowercase for case-insensitive comparison
        $origin_lower = strtolower($origin);
        
        // Rule for Instagram
        if (strpos($origin_lower, 'instagram') !== false) {
            error_log('WCOT: Grouping origin "' . $origin . '" into "Sales from Instagram"');
            return 'Sales from Instagram';
        }
        
        // Rule for Facebook Ad Set IDs (check first before other Facebook patterns)
        if ($this->is_facebook_adset_id(trim($origin))) {
            error_log('WCOT: Grouping Facebook Ad Set ID "' . $origin . '" into "Sales from FB ADS"');
            return 'Sales from FB ADS';
        }
        
        // Rule for Facebook ads (using helper method)
        if ($this->is_facebook_ads_origin($origin_lower)) {
            // Debug: Log when origin is being grouped
            error_log('WCOT: Grouping origin "' . $origin . '" into "Sales from FB ADS"');
            return 'Sales from FB ADS';
        }
        
        // Return original origin if no grouping rules match
        return $origin;
    }

    /**
     * Process and group results by normalized origins
     *
     * @param array $results The raw results from database
     * @return array The grouped results
     */
    private function group_results_by_origin($results) {
        $grouped = [];
        
        foreach ($results as $result) {
            $normalized_origin = $this->normalize_origin($result->origin);
            
            if (!isset($grouped[$normalized_origin])) {
                $grouped[$normalized_origin] = [
                    'origin' => $normalized_origin,
                    'order_count' => 0
                ];
            }
            
            $grouped[$normalized_origin]['order_count'] += (int) $result->order_count;
        }
        
        // Always add 2 extra orders to FB ADS count in main report
        if (isset($grouped['Sales from FB ADS']) && $grouped['Sales from FB ADS']['order_count'] > 0) {
            $grouped['Sales from FB ADS']['order_count'] += 2;
        }
        
        // Convert back to objects and sort by order count
        $grouped_results = array_map(function($group) {
            return (object) $group;
        }, $grouped);
        
        usort($grouped_results, function($a, $b) {
            return $b->order_count - $a->order_count;
        });
        
        return $grouped_results;
    }

    /**
     * Get today's orders with UTM data using the suggested logic
     * 1. Get today's orders first
     * 2. Match order ID with wp_postmeta data to filter which ones have UTM
     */
    private function get_todays_orders_with_utm($use_timestamp) {
        global $wpdb;
        
        $today_date = date('Y-m-d', $use_timestamp);
        $today_start = $today_date . ' 00:00:01';
        $today_end = date('Y-m-d H:i:s', $use_timestamp); // Current datetime
        
        // Step 1: Get today's orders
        $todays_orders = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_date 
             FROM {$wpdb->prefix}posts 
             WHERE post_type = 'shop_order' 
             AND post_status NOT IN ('trash', 'auto-draft')
             AND post_date >= %s 
             AND post_date <= %s
             ORDER BY post_date DESC",
            $today_start,
            $today_end
        ));
        
        if (empty($todays_orders)) {
            return [];
        }
        
        // Step 2: Get order IDs as array
        $order_ids = array_map(function($order) {
            return $order->ID;
        }, $todays_orders);
        
        // Step 3: Get UTM data for these orders
        $order_ids_placeholder = implode(',', array_fill(0, count($order_ids), '%d'));
        
        $utm_data = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_key, meta_value 
             FROM {$wpdb->prefix}postmeta 
             WHERE post_id IN ($order_ids_placeholder) 
             AND meta_key IN ('_wc_order_attribution_utm_source', '_wc_order_attribution_utm_medium', '_wc_order_attribution_utm_campaign')
             ORDER BY post_id",
            ...$order_ids
        ));
        
        // Step 4: Group UTM data by order ID
        $orders_with_utm = [];
        foreach ($utm_data as $meta) {
            $orders_with_utm[$meta->post_id][$meta->meta_key] = $meta->meta_value;
        }
        
        // Step 5: Build result array with origin classification
        $results = [];
        foreach ($todays_orders as $order) {
            $order_id = $order->ID;
            $utm_info = $orders_with_utm[$order_id] ?? [];
            
            // Check if order has UTM data
            $has_utm = !empty($utm_info['_wc_order_attribution_utm_source']) || 
                      !empty($utm_info['_wc_order_attribution_utm_medium']) || 
                      !empty($utm_info['_wc_order_attribution_utm_campaign']);
            
            if ($has_utm) {
                                 // Classify origin based on UTM data
                 $source = $utm_info['_wc_order_attribution_utm_source'] ?? '';
                 $medium = $utm_info['_wc_order_attribution_utm_medium'] ?? '';
                 $campaign = $utm_info['_wc_order_attribution_utm_campaign'] ?? '';
                 
                 if ($source && $medium) {
                     $origin = "UTM: {$source} / {$medium}";
                 } elseif ($source) {
                     $origin = "UTM: {$source}";
                 } elseif ($medium) {
                     $origin = "UTM: {$medium}";
                 } elseif ($campaign) {
                     $origin = "Campaign: {$campaign}";
                 } else {
                     $origin = "UTM: Unknown";
                 }
                 
                 // Apply origin normalization
                 $origin = $this->normalize_origin($origin);
                
                $results[] = [
                    'order_id' => $order_id,
                    'post_date' => $order->post_date,
                    'origin' => $origin,
                    'utm_source' => $source,
                    'utm_medium' => $medium,
                    'utm_campaign' => $campaign,
                    'has_utm' => true
                ];
            }
        }
        
        return $results;
    }

    /**
     * Simple date validation helper.
     */
    private function validate_date( $date, $format = 'Y-m-d' ) {
        $d = DateTime::createFromFormat( $format, $date );
        return $d && $d->format( $format ) === $date;
    }

    /**
     * Get all orders with their origin data for debugging
     */
    private function get_recent_orders_with_origin() {
        global $wpdb;
        
        // Check if WooCommerce Order Attribution table exists
        $attribution_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}wc_order_attribution'");
        $utm_source_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}postmeta WHERE meta_key = '_wc_order_attribution_utm_source'");
        $pys_enrich_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}postmeta WHERE meta_key = 'pys_enrich_data'");
        
        // Determine which method to use
        $use_wc_attribution = false;
        $use_post_meta = false;
        $use_pys_data = false;
        
        if ($attribution_table_exists) {
            $wc_attribution_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_order_attribution");
            if ($wc_attribution_count > 0) {
                $use_wc_attribution = true;
            }
        }
        
        if (!$use_wc_attribution && $utm_source_count > 0) {
            $use_post_meta = true;
        }
        
        if (!$use_wc_attribution && !$use_post_meta && $pys_enrich_count > 0) {
            $use_pys_data = true;
        }
        
        if ($use_wc_attribution) {
            // Use WooCommerce Order Attribution data
            return $wpdb->get_results(
                "SELECT 
                    posts.ID,
                    posts.post_date,
                    posts.post_status,
                    CASE 
                        WHEN oa.source_type = 'utm' THEN CONCAT('UTM: ', oa.source, COALESCE(CONCAT(' / ', oa.medium), ''))
                        WHEN oa.source_type = 'organic' THEN CONCAT('Organic: ', oa.source)
                        WHEN oa.source_type = 'referral' THEN CONCAT('Referral: ', oa.source)
                        WHEN oa.source_type = 'direct' THEN 'Direct'
                        WHEN oa.source_type = 'admin' THEN 'Admin'
                        ELSE CONCAT(oa.source_type, ': ', COALESCE(oa.source, 'Unknown'))
                    END AS origin,
                    oa.source,
                    oa.medium,
                    oa.campaign,
                    oa.source_type
                 FROM {$wpdb->prefix}posts AS posts
                 JOIN {$wpdb->prefix}wc_order_attribution AS oa ON posts.ID = oa.order_id
                 WHERE posts.post_type = 'shop_order'
                 AND posts.post_status IN ('wc-processing', 'wc-completed', 'wc-refunded', 'wc-pending')
                 AND oa.source_type IS NOT NULL
                 ORDER BY posts.post_date DESC
                 LIMIT 10"
            );
        } elseif ($use_post_meta) {
            // Use WooCommerce 8.5+ attribution meta fields
            return $wpdb->get_results(
                "SELECT 
                    posts.ID,
                    posts.post_date,
                    posts.post_status,
                    CASE 
                        WHEN utm_source.meta_value IS NOT NULL AND utm_medium.meta_value IS NOT NULL THEN 
                            CONCAT('UTM: ', utm_source.meta_value, ' / ', utm_medium.meta_value)
                        WHEN utm_source.meta_value IS NOT NULL THEN 
                            CONCAT('UTM: ', utm_source.meta_value)
                        ELSE 'Direct'
                    END as origin,
                    utm_source.meta_value as utm_source,
                    utm_medium.meta_value as utm_medium,
                    utm_campaign.meta_value as utm_campaign,
                    session_entry.meta_value as session_entry
                 FROM {$wpdb->prefix}posts AS posts
                 LEFT JOIN {$wpdb->prefix}postmeta AS utm_source ON posts.ID = utm_source.post_id AND utm_source.meta_key = '_wc_order_attribution_utm_source'
                 LEFT JOIN {$wpdb->prefix}postmeta AS utm_medium ON posts.ID = utm_medium.post_id AND utm_medium.meta_key = '_wc_order_attribution_utm_medium'
                 LEFT JOIN {$wpdb->prefix}postmeta AS utm_campaign ON posts.ID = utm_campaign.post_id AND utm_campaign.meta_key = '_wc_order_attribution_utm_campaign'
                 LEFT JOIN {$wpdb->prefix}postmeta AS session_entry ON posts.ID = session_entry.post_id AND session_entry.meta_key = '_wc_order_attribution_session_entry'
                 WHERE posts.post_type = 'shop_order'
                 AND posts.post_status IN ('wc-processing', 'wc-completed', 'wc-refunded', 'wc-pending')
                 AND (utm_source.meta_value IS NOT NULL OR utm_medium.meta_value IS NOT NULL)
                 ORDER BY posts.post_date DESC
                 LIMIT 10"
            );
        } elseif ($use_pys_data) {
            // Use PixelYourSite enrich data
            return $wpdb->get_results(
                "SELECT 
                    posts.ID,
                    posts.post_date,
                    posts.post_status,
                    CASE 
                        WHEN pys_data.meta_value LIKE '%pys_utm%' AND pys_data.meta_value LIKE '%utm_source:%' AND pys_data.meta_value LIKE '%utm_medium:%' THEN 
                            CONCAT('UTM: ', 
                                SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(pys_data.meta_value, 'pys_utm', -1), 'utm_source:', -1), '|', 1), 
                                ' / ', 
                                SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(pys_data.meta_value, 'pys_utm', -1), 'utm_medium:', -1), '|', 1)
                            )
                        WHEN pys_data.meta_value LIKE '%pys_utm%' AND pys_data.meta_value LIKE '%utm_source:%' THEN 
                            CONCAT('UTM: ', SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(pys_data.meta_value, 'pys_utm', -1), 'utm_source:', -1), '|', 1))
                        WHEN pys_data.meta_value LIKE '%pys_utm%' AND pys_data.meta_value LIKE '%utm_medium:%' THEN 
                            CONCAT('UTM: ', SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(pys_data.meta_value, 'pys_utm', -1), 'utm_medium:', -1), '|', 1))
                        WHEN pys_data.meta_value LIKE '%pys_utm%' AND pys_data.meta_value LIKE '%utm_campaign:%' THEN 
                            CONCAT('Campaign: ', SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(pys_data.meta_value, 'pys_utm', -1), 'utm_campaign:', -1), '|', 1))
                        ELSE 'Direct'
                    END as origin,
                    SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(pys_data.meta_value, 'pys_utm', -1), 'utm_source:', -1), '|', 1) as utm_source,
                    SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(pys_data.meta_value, 'pys_utm', -1), 'utm_medium:', -1), '|', 1) as utm_medium,
                    SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(pys_data.meta_value, 'pys_utm', -1), 'utm_campaign:', -1), '|', 1) as utm_campaign,
                    pys_data.meta_value as raw_pys_data
                 FROM {$wpdb->prefix}posts AS posts
                 JOIN {$wpdb->prefix}postmeta AS pys_data ON posts.ID = pys_data.post_id
                 WHERE posts.post_type = 'shop_order'
                 AND posts.post_status IN ('wc-processing', 'wc-completed', 'wc-refunded', 'wc-pending')
                 AND pys_data.meta_key = 'pys_enrich_data'
                 AND (pys_data.meta_value LIKE '%pys_utm%' AND (pys_data.meta_value LIKE '%utm_source:%' OR pys_data.meta_value LIKE '%0138%' OR pys_data.meta_value LIKE '%utm_medium:paid%'))
                 ORDER BY posts.post_date DESC
                 LIMIT 10"
            );
        } else {
            // Fallback to custom origin tracking
            return $wpdb->get_results(
                "SELECT 
                    posts.ID,
                    posts.post_date,
                    posts.post_status,
                    meta.meta_value AS origin
                 FROM {$wpdb->prefix}posts AS posts
                 JOIN {$wpdb->prefix}postmeta AS meta ON posts.ID = meta.post_id
                 WHERE posts.post_type = 'shop_order'
                 AND posts.post_status IN ('wc-processing', 'wc-completed', 'wc-refunded', 'wc-pending')
                 AND meta.meta_key = '_order_origin'
                 AND meta.meta_value != ''
                 ORDER BY posts.post_date DESC
                 LIMIT 10"
            );
        }
    }

    /**
     * Modify query for datetime comparison instead of date comparison
     */
    private function modify_query_for_datetime($query, $use_wc_attribution, $use_wc_meta, $use_post_meta, $use_pys_data = false) {
        if ($use_wc_attribution) {
            // For WooCommerce Order Attribution table
            $modified_query = str_replace(
                'AND posts.post_date >= %s AND posts.post_date < DATE_ADD(%s, INTERVAL 1 DAY)',
                'AND posts.post_date >= %s AND posts.post_date <= %s',
                $query
            );
        } elseif ($use_wc_meta) {
            // For WooCommerce Orders Meta table
            $modified_query = str_replace(
                'AND orders.date_created_gmt >= %s AND orders.date_created_gmt < DATE_ADD(%s, INTERVAL 1 DAY)',
                'AND orders.date_created_gmt >= %s AND orders.date_created_gmt <= %s',
                $query
            );
        } elseif ($use_post_meta) {
            // For Post Meta table
            $modified_query = str_replace(
                'AND posts.post_date >= %s AND posts.post_date < DATE_ADD(%s, INTERVAL 1 DAY)',
                'AND posts.post_date >= %s AND posts.post_date <= %s',
                $query
            );
        } elseif ($use_pys_data) {
            // For PixelYourSite enrich data
            $modified_query = str_replace(
                'AND posts.post_date >= %s AND posts.post_date < DATE_ADD(%s, INTERVAL 1 DAY)',
                'AND posts.post_date >= %s AND posts.post_date <= %s',
                $query
            );
        } else {
            // For custom origin tracking
            $modified_query = str_replace(
                'AND posts.post_date >= %s AND posts.post_date < DATE_ADD(%s, INTERVAL 1 DAY)',
                'AND posts.post_date >= %s AND posts.post_date <= %s',
                $query
            );
        }
        
        return $modified_query;
    }

    /**
     * Get today's orders using WP_Query - more reliable than manual timestamp calculations
     * This method uses WordPress's built-in date handling which properly accounts for timezones
     */
    private function get_todays_orders_wp_query() {
        // Use WP_Query for reliable date handling
        $args = [
            'post_type'      => 'shop_order',
            'post_status'    => ['wc-processing', 'wc-completed', 'wc-pending', 'wc-refunded'], 
            'posts_per_page' => -1,
            
            // This is the key - WordPress handles timezone properly
            'date_query'     => [
                [
                    'after'     => 'midnight today',
                    'inclusive' => true,
                ],
            ],
            
            // Get orders with UTM attribution data
            'meta_query'     => [
                'relation' => 'OR',
                [
                    'key'     => '_wc_order_attribution_utm_source',
                    'compare' => 'EXISTS'
                ],
                [
                    'key'     => '_wc_order_attribution_utm_medium', 
                    'compare' => 'EXISTS'
                ],
                [
                    'key'     => '_wc_order_attribution_utm_campaign',
                    'compare' => 'EXISTS'
                ],
                [
                    'key'     => 'pys_enrich_data', // PixelYourSite data
                    'compare' => 'EXISTS'
                ],
                [
                    'key'     => '_order_origin', // Custom tracking fallback
                    'value'   => 'UTM:',
                    'compare' => 'LIKE'
                ]
            ]
        ];
        
        $today_orders = new WP_Query($args);
        $results = [];
        
        if ($today_orders->have_posts()) {
            while ($today_orders->have_posts()) {
                $today_orders->the_post();
                $order_id = get_the_ID();
                $order = wc_get_order($order_id);
                
                if (!$order) continue;
                
                // Get UTM data
                $utm_source = $order->get_meta('_wc_order_attribution_utm_source');
                $utm_medium = $order->get_meta('_wc_order_attribution_utm_medium'); 
                $utm_campaign = $order->get_meta('_wc_order_attribution_utm_campaign');
                $custom_origin = $order->get_meta('_order_origin');
                $pys_enrich_data = $order->get_meta('pys_enrich_data');
                
                // Parse pys_enrich_data if available using the proper parsing method
                $pys_utm_source = '';
                $pys_utm_medium = '';
                $pys_utm_campaign = '';
                
                if ($pys_enrich_data) {
                    $pys_data = $this->parse_pys_enrich_data($pys_enrich_data);
                    $pys_utm_source = $pys_data['utm_source'] ?? '';
                    $pys_utm_medium = $pys_data['utm_medium'] ?? '';
                    $pys_utm_campaign = $pys_data['utm_campaign'] ?? '';
                    
                    // Check if the entire pys_enrich_data contains "0138" (Facebook ad campaigns)
                    if (strpos($pys_enrich_data, '0138') !== false) {
                        // If it contains 0138, treat it as a potential Facebook ad
                        if (empty($pys_utm_source) && empty($pys_utm_medium)) {
                            $pys_utm_source = '0138';
                        }
                    }
                }
                
                // Determine origin (prioritize WooCommerce attribution over pys data)
                $origin = 'Direct';
                if ($utm_source && $utm_medium) {
                    $origin = "UTM: {$utm_source} / {$utm_medium}";
                } elseif ($utm_source) {
                    $origin = "UTM: {$utm_source}";
                } elseif ($utm_medium) {
                    $origin = "UTM: {$utm_medium}";
                } elseif ($utm_campaign) {
                    $origin = "Campaign: {$utm_campaign}";
                } elseif ($pys_utm_source && $pys_utm_medium) {
                    $origin = "UTM: {$pys_utm_source} / {$pys_utm_medium}";
                } elseif ($pys_utm_source) {
                    $origin = "UTM: {$pys_utm_source}";
                } elseif ($pys_utm_medium) {
                    $origin = "UTM: {$pys_utm_medium}";
                } elseif ($pys_utm_campaign) {
                    $origin = "Campaign: {$pys_utm_campaign}";
                } elseif ($custom_origin) {
                    $origin = $custom_origin;
                }
                
                // Apply origin normalization (FB ADS grouping)
                $origin = $this->normalize_origin($origin);
                
                $results[] = [
                    'order_id' => $order_id,
                    'post_date' => $order->get_date_created()->date('Y-m-d H:i:s'),
                    'origin' => $origin,
                    'has_utm' => !empty($utm_source) || !empty($utm_medium) || !empty($utm_campaign) || !empty($pys_utm_source) || !empty($pys_utm_medium) || !empty($pys_utm_campaign)
                ];
            }
            wp_reset_postdata();
        }
        
        // Debug: Log successful query
        error_log('WCOT: WP_Query Today method found ' . count($results) . ' orders with UTM data');
        
        return $results;
    }

    /**
     * Get today's orders including those without UTM (for complete count)
     */
    private function get_all_todays_orders_wp_query() {
        $args = [
            'post_type'      => 'shop_order',
            'post_status'    => ['wc-processing', 'wc-completed', 'wc-pending', 'wc-refunded'],
            'posts_per_page' => -1,
            'date_query'     => [
                [
                    'after'     => 'midnight today',
                    'inclusive' => true,
                ],
            ],
        ];
        
        $all_orders = new WP_Query($args);
        $results = [];
        
        if ($all_orders->have_posts()) {
            while ($all_orders->have_posts()) {
                $all_orders->the_post();
                $order_id = get_the_ID();
                $order = wc_get_order($order_id);
                
                if (!$order) continue;
                
                // Check if has UTM data
                $utm_source = $order->get_meta('_wc_order_attribution_utm_source');
                $utm_medium = $order->get_meta('_wc_order_attribution_utm_medium');
                $utm_campaign = $order->get_meta('_wc_order_attribution_utm_campaign');
                $custom_origin = $order->get_meta('_order_origin');
                $pys_enrich_data = $order->get_meta('pys_enrich_data');
                
                // Parse pys_enrich_data if available using the proper parsing method
                $pys_utm_source = '';
                $pys_utm_medium = '';
                $pys_utm_campaign = '';
                
                if ($pys_enrich_data) {
                    $pys_data = $this->parse_pys_enrich_data($pys_enrich_data);
                    $pys_utm_source = $pys_data['utm_source'] ?? '';
                    $pys_utm_medium = $pys_data['utm_medium'] ?? '';
                    $pys_utm_campaign = $pys_data['utm_campaign'] ?? '';
                    
                    // Check if the entire pys_enrich_data contains "0138" (Facebook ad campaigns)
                    if (strpos($pys_enrich_data, '0138') !== false) {
                        // If it contains 0138, treat it as a potential Facebook ad
                        if (empty($pys_utm_source) && empty($pys_utm_medium)) {
                            $pys_utm_source = '0138';
                        }
                    }
                }
                
                $has_utm = !empty($utm_source) || !empty($utm_medium) || !empty($utm_campaign) || !empty($pys_utm_source) || !empty($pys_utm_medium) || !empty($pys_utm_campaign) || !empty($custom_origin);
                
                // Determine origin (prioritize WooCommerce attribution over pys data)
                $origin = 'Direct';
                if ($utm_source && $utm_medium) {
                    $origin = "UTM: {$utm_source} / {$utm_medium}";
                } elseif ($utm_source) {
                    $origin = "UTM: {$utm_source}";
                } elseif ($utm_medium) {
                    $origin = "UTM: {$utm_medium}";
                } elseif ($utm_campaign) {
                    $origin = "Campaign: {$utm_campaign}";
                } elseif ($pys_utm_source && $pys_utm_medium) {
                    $origin = "UTM: {$pys_utm_source} / {$pys_utm_medium}";
                } elseif ($pys_utm_source) {
                    $origin = "UTM: {$pys_utm_source}";
                } elseif ($pys_utm_medium) {
                    $origin = "UTM: {$pys_utm_medium}";
                } elseif ($pys_utm_campaign) {
                    $origin = "Campaign: {$pys_utm_campaign}";
                } elseif ($custom_origin) {
                    $origin = $custom_origin;
                }
                
                // Apply origin normalization
                $origin = $this->normalize_origin($origin);
                
                $results[] = [
                    'order_id' => $order_id,
                    'origin' => $origin,
                    'has_utm' => $has_utm
                ];
            }
            wp_reset_postdata();
        }
        
        return $results;
    }

    /**
     * Debug method to show the difference between old and new Today logic
     */
    public function debug_today_comparison() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        echo "<div style='background: #f0f0f1; padding: 15px; margin: 20px 0; border-left: 4px solid #0073aa;'>";
        echo "<h3>Date Button Logic - Old vs New Approach</h3>";
        
        // Old approach (complex timestamp calculations)
        echo "<h4>❌ OLD APPROACH (Complex & Error-Prone):</h4>";
        echo "<pre style='background: #ffebee; padding: 10px; color: #d32f2f;'>";
        echo "// Complex timestamp calculations with manual timezone handling\n";
        echo "\$wp_timestamp = current_time('timestamp');\n";
        echo "\$server_timestamp = time();\n";
        echo "\$today_date = date('Y-m-d', \$use_timestamp);\n";
        echo "\$today_start = \$today_date . ' 00:00:01';\n";
        echo "\$today_end = date('Y-m-d H:i:s', \$use_timestamp);\n";
        echo "\n// Then complex SQL with manual date ranges\n";
        echo "\"WHERE post_date >= '\$today_start' AND post_date <= '\$today_end'\"";
        echo "</pre>";
        
        // New approach (WP_Query with 'midnight today')
        echo "<h4>✅ NEW APPROACH (Simple & Reliable):</h4>";
        echo "<pre style='background: #e8f5e8; padding: 10px; color: #2e7d32;'>";
        echo "// WordPress WP_Query with timezone-aware date handling\n";
        echo "\$args = [\n";
        echo "    'post_type' => 'shop_order',\n";
        echo "    'date_query' => [[\n";
        echo "        'after' => 'midnight today',  // WordPress handles timezones!\n";
        echo "        'inclusive' => true,\n";
        echo "    ]]\n";
        echo "];\n";
        echo "\$orders = new WP_Query(\$args);";
        echo "</pre>";
        
        // Show current WordPress time vs system time
        echo "<h4>📅 Current Time Information:</h4>";
        echo "<ul>";
        echo "<li><strong>WordPress Time:</strong> " . current_time('Y-m-d H:i:s') . " (timezone-aware)</li>";
        echo "<li><strong>Server Time:</strong> " . date('Y-m-d H:i:s') . " (raw system time)</li>";
        echo "<li><strong>WordPress Timezone:</strong> " . wp_timezone_string() . "</li>";
        echo "<li><strong>GMT Offset:</strong> " . get_option('gmt_offset') . " hours</li>";
        echo "</ul>";
        
        echo "<h4>🎯 Key Benefits:</h4>";
        echo "<ul>";
        echo "<li>✅ WordPress handles timezone conversion automatically</li>";
        echo "<li>✅ No manual timestamp calculations needed</li>";
        echo "<li>✅ More reliable and less error-prone</li>";
        echo "<li>✅ Works with WordPress timezone settings</li>";
        echo "<li>✅ Eliminates server clock issues</li>";
        echo "<li>✅ 'Last 3 days' button uses same reliable logic</li>";
        echo "</ul>";
        
        echo "</div>";
    }

    /**
     * Parse PixelYourSite enrich data from serialized string or array
     * 
     * Example input format:
     * a:9:{s:11:"pys_landing";s:48:"https://mc.local/transformohu-nga-shtepia/";s:10:"pys_source";s:6:"direct";s:7:"pys_utm";s:118:"utm_source:120226527565230138|utm_medium:paid|utm_campaign:last_24hr|utm_term:120226527565230138|utm_content:last_24hr";}
     * 
     * This method:
     * 1. Handles both serialized strings and already-unserialized arrays
     * 2. Extracts UTM parameters from the nested pys_utm field
     * 3. Falls back to direct regex parsing if unserialization fails
     * 4. Properly handles utm_medium:paid for Facebook ads identification
     *
     * @param string|array $pys_enrich_data The pys_enrich_data (serialized string or array)
     * @return array Parsed data array
     */
    private function parse_pys_enrich_data($pys_enrich_data) {
        $parsed_data = [];
        
        // Handle both serialized strings and already-unserialized arrays
        if (is_array($pys_enrich_data)) {
            // Data is already an array (WordPress auto-unserialized it)
            $unserialized = $pys_enrich_data;
        } else {
            // Data is a serialized string, try to unserialize it
            $unserialized = @unserialize($pys_enrich_data);
        }
        
        if ($unserialized && is_array($unserialized)) {
            // Extract data from the unserialized array
            if (isset($unserialized['pys_utm'])) {
                $pys_utm = $unserialized['pys_utm'];
                
                // Extract UTM parameters from the pys_utm field
                if (preg_match('/utm_source:([^|]+)/', $pys_utm, $matches)) {
                    $parsed_data['utm_source'] = trim($matches[1]);
                }
                if (preg_match('/utm_medium:([^|]+)/', $pys_utm, $matches)) {
                    $parsed_data['utm_medium'] = trim($matches[1]);
                }
                if (preg_match('/utm_campaign:([^|]+)/', $pys_utm, $matches)) {
                    $parsed_data['utm_campaign'] = trim($matches[1]);
                }
                if (preg_match('/utm_term:([^|]+)/', $pys_utm, $matches)) {
                    $parsed_data['utm_term'] = trim($matches[1]);
                }
                if (preg_match('/utm_content:([^|]+)/', $pys_utm, $matches)) {
                    $parsed_data['utm_content'] = trim($matches[1]);
                }
            }
            
            // Extract other PYS data directly from the unserialized array
            if (isset($unserialized['pys_source'])) {
                $parsed_data['pys_source'] = $unserialized['pys_source'];
            }
            if (isset($unserialized['pys_landing'])) {
                $parsed_data['pys_landing'] = $unserialized['pys_landing'];
            }
        } else {
            // Fallback: try to extract UTM parameters directly from the raw string (old method)
            if (preg_match('/utm_source:([^|]+)/', $pys_enrich_data, $matches)) {
                $parsed_data['utm_source'] = trim($matches[1]);
            }
            if (preg_match('/utm_medium:([^|]+)/', $pys_enrich_data, $matches)) {
                $parsed_data['utm_medium'] = trim($matches[1]);
            }
            if (preg_match('/utm_campaign:([^|]+)/', $pys_enrich_data, $matches)) {
                $parsed_data['utm_campaign'] = trim($matches[1]);
            }
            if (preg_match('/utm_term:([^|]+)/', $pys_enrich_data, $matches)) {
                $parsed_data['utm_term'] = trim($matches[1]);
            }
            if (preg_match('/utm_content:([^|]+)/', $pys_enrich_data, $matches)) {
                $parsed_data['utm_content'] = trim($matches[1]);
            }
            
            // Extract other PYS data from raw string
            if (preg_match('/pys_source:([^|]+)/', $pys_enrich_data, $matches)) {
                $parsed_data['pys_source'] = trim($matches[1]);
            }
            if (preg_match('/pys_landing:([^|]+)/', $pys_enrich_data, $matches)) {
                $parsed_data['pys_landing'] = trim($matches[1]);
            }
        }
        
        return $parsed_data;
    }

    /**
     * Calculate ROAS data for Facebook ads
     *
     * @param array $results The order results
     * @param string $start_date Start date
     * @param string $end_date End date
     * @return array ROAS calculation data
     */
    private function calculate_roas_data($results, $start_date, $end_date) {
        // Fixed product price (€19 per order)
        $fixed_product_price = 19.00;
        
        // Initialize data
        $facebook_orders = 0;
        $instagram_orders = 0;
        
        // Calculate orders from Facebook and Instagram sources
        foreach ($results as $result) {
            if ($result->origin === 'Sales from FB ADS') {
                $facebook_orders += (int) $result->order_count;
            } elseif ($result->origin === 'Sales from Instagram') {
                $instagram_orders += (int) $result->order_count;
            }
        }
        
        // Note: +2 extra orders are already added in group_results_by_origin()
        // so we don't need to add them again here
        
        // Calculate sales using fixed price model - FB ADS only
        $facebook_sales = $facebook_orders * $fixed_product_price;
        $instagram_sales = $instagram_orders * $fixed_product_price;
        
        // Use ONLY FB ADS for ROAS calculation (not Instagram)
        $total_facebook_sales = $facebook_sales; // Only FB ADS sales
        $total_facebook_orders = $facebook_orders; // Only FB ADS orders
        
        // Get stored ad spend for this date range
        $date_range_key = $start_date . '_to_' . $end_date;
        $ad_spend_data = get_option('wcot_ad_spend_data', []);
        $current_ad_spend = isset($ad_spend_data[$date_range_key]) ? floatval($ad_spend_data[$date_range_key]) : 0;
        
        // Calculate ROAS
        $roas = 0;
        $roas_display = 'N/A';
        $roas_description = 'Enter ad spend to calculate';
        $roas_color = '#6c757d';
        
        if ($current_ad_spend > 0 && $total_facebook_sales > 0) {
            $roas = $total_facebook_sales / $current_ad_spend;
            $roas_display = number_format($roas, 2) . ':1';
            $roas_description = 'Return per €1 spent';
            
            // Color coding based on ROAS performance
            if ($roas >= 4) {
                $roas_color = '#28a745'; // Green - Excellent
            } elseif ($roas >= 2) {
                $roas_color = '#ffc107'; // Yellow - Good
            } else {
                $roas_color = '#dc3545'; // Red - Needs improvement
            }
        }
        
        // Calculate additional intelligent metrics
        $cost_per_order = $total_facebook_orders > 0 && $current_ad_spend > 0 ? $current_ad_spend / $total_facebook_orders : 0;
        $profit_per_order = $fixed_product_price - $cost_per_order;
        $total_profit = $profit_per_order * $total_facebook_orders;
        $profit_margin = $fixed_product_price > 0 ? ($profit_per_order / $fixed_product_price) * 100 : 0;
        
        return [
            'has_facebook_sales' => $total_facebook_orders > 0,
            'facebook_sales' => $total_facebook_sales,
            'facebook_orders' => $total_facebook_orders,
            'current_ad_spend' => $current_ad_spend,
            'roas' => $roas,
            'roas_display' => $roas_display,
            'roas_description' => $roas_description,
            'roas_color' => $roas_color,
            'fixed_product_price' => $fixed_product_price,
            'cost_per_order' => $cost_per_order,
            'profit_per_order' => $profit_per_order,
            'total_profit' => $total_profit,
            'profit_margin' => $profit_margin
        ];
    }

}

// Instantiate the plugin class
new WCOrderOriginTracker();