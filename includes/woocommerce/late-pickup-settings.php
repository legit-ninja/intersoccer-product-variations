<?php
/**
 * File: late-pickup-settings.php
 * Description: Admin settings for late pickup rates in InterSoccer plugin.
 * Dependencies: WooCommerce
 * Author: Jeremy Lee
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Add submenu under Products for Late Pick Up settings.
 */
add_action('admin_menu', 'intersoccer_add_late_pickup_settings_menu');
function intersoccer_add_late_pickup_settings_menu() {
    add_submenu_page(
        'edit.php?post_type=product',
        __('Late Pick Up Settings', 'intersoccer-product-variations'),
        __('Late Pick Up', 'intersoccer-product-variations'),
        'manage_woocommerce',
        'intersoccer-late-pickup-settings',
        'intersoccer_late_pickup_settings_page'
    );
}

/**
 * Settings page callback.
 */
function intersoccer_late_pickup_settings_page() {
    // Save settings if submitted
    if (isset($_POST['intersoccer_late_pickup_submit']) && check_admin_referer('intersoccer_late_pickup_nonce')) {
        update_option('intersoccer_late_pickup_per_day', floatval($_POST['per_day']));
        update_option('intersoccer_late_pickup_full_week', floatval($_POST['full_week']));
        echo '<div class="notice notice-success"><p>' . __('Settings saved.', 'intersoccer-product-variations') . '</p></div>';
    }

    $per_day = get_option('intersoccer_late_pickup_per_day', 25);
    $full_week = get_option('intersoccer_late_pickup_full_week', 90);
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Late Pick Up Settings', 'intersoccer-product-variations'); ?></h1>
        <form method="post">
            <?php wp_nonce_field('intersoccer_late_pickup_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="per_day"><?php esc_html_e('Per Day Cost (CHF)', 'intersoccer-product-variations'); ?></label></th>
                    <td><input type="number" name="per_day" id="per_day" value="<?php echo esc_attr($per_day); ?>" step="0.01" min="0"></td>
                </tr>
                <tr>
                    <th><label for="full_week"><?php esc_html_e('Full Week Cost (CHF)', 'intersoccer-product-variations'); ?></label></th>
                    <td><input type="number" name="full_week" id="full_week" value="<?php echo esc_attr($full_week); ?>" step="0.01" min="0"></td>
                </tr>
            </table>
            <input type="submit" name="intersoccer_late_pickup_submit" value="<?php esc_attr_e('Save Changes', 'intersoccer-product-variations'); ?>" class="button button-primary">
        </form>
    </div>
    <?php
}

/**
 * Register strings for WPML.
 */
add_action('admin_init', 'intersoccer_register_late_pickup_settings_strings');
function intersoccer_register_late_pickup_settings_strings() {
    if (function_exists('icl_register_string')) {
        icl_register_string('intersoccer-product-variations', 'Per Day Cost (CHF)', 'Per Day Cost (CHF)');
        icl_register_string('intersoccer-product-variations', 'Full Week Cost (CHF)', 'Full Week Cost (CHF)');
        icl_register_string('intersoccer-product-variations', 'Save Changes', 'Save Changes');
    }
}
?>