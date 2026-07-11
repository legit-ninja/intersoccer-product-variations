<?php
/**
 * Admin settings for age restriction grace periods.
 *
 * @package InterSoccer_Product_Variations
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Wire strict missing reference date from saved settings.
 */
add_filter('intersoccer_age_group_strict_missing_reference_date', 'intersoccer_age_restriction_strict_missing_reference_date', 10, 1);
function intersoccer_age_restriction_strict_missing_reference_date($strict) {
    $settings = intersoccer_get_age_restriction_settings();
    return !empty($settings['strict_missing_reference_date']);
}

/**
 * Add submenu under Products for age restriction settings.
 */
add_action('admin_menu', 'intersoccer_add_age_restriction_settings_menu');
function intersoccer_add_age_restriction_settings_menu() {
    add_submenu_page(
        'edit.php?post_type=product',
        __('Age Restriction Settings', 'intersoccer-product-variations'),
        __('Age Restrictions', 'intersoccer-product-variations'),
        'manage_woocommerce',
        'intersoccer-age-restrictions',
        'intersoccer_age_restriction_settings_page'
    );
}

/**
 * Register settings for the age restrictions option.
 */
add_action('admin_init', 'intersoccer_register_age_restriction_settings');
function intersoccer_register_age_restriction_settings() {
    register_setting(
        'intersoccer_age_restrictions_group',
        'intersoccer_age_restriction_settings',
        [
            'type' => 'array',
            'sanitize_callback' => 'intersoccer_sanitize_age_restriction_settings',
            'default' => intersoccer_get_age_restriction_settings_defaults(),
        ]
    );
}

/**
 * Settings page callback.
 */
function intersoccer_age_restriction_settings_page() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die(__('You do not have permission to access this page.', 'intersoccer-product-variations'));
    }

    $saved = false;
    if (isset($_POST['intersoccer_age_restriction_submit']) && check_admin_referer('intersoccer_age_restriction_nonce')) {
        $raw = [
            'grace_enabled' => isset($_POST['grace_enabled']) ? 1 : 0,
            'below_min_months' => isset($_POST['below_min_months']) ? (int) $_POST['below_min_months'] : 0,
            'above_max_months' => isset($_POST['above_max_months']) ? (int) $_POST['above_max_months'] : 0,
            'half_day_above_max_months' => isset($_POST['half_day_above_max_months']) ? (int) $_POST['half_day_above_max_months'] : 0,
            'strict_missing_reference_date' => isset($_POST['strict_missing_reference_date']) ? 1 : 0,
        ];
        update_option(
            'intersoccer_age_restriction_settings',
            intersoccer_sanitize_age_restriction_settings($raw)
        );
        $saved = true;
    }

    $settings = intersoccer_get_age_restriction_settings();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Age Restriction Settings', 'intersoccer-product-variations'); ?></h1>
        <p><?php esc_html_e('Configure grace periods for age-group validation at checkout. Limits still come from each product\'s Age Group attribute; these settings control how strictly ages are enforced near the minimum and maximum.', 'intersoccer-product-variations'); ?></p>
        <?php if ($saved) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'intersoccer-product-variations'); ?></p></div>
        <?php endif; ?>
        <form method="post">
            <?php wp_nonce_field('intersoccer_age_restriction_nonce'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Enable age grace', 'intersoccer-product-variations'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="grace_enabled" id="grace_enabled" value="1" <?php checked(!empty($settings['grace_enabled'])); ?>>
                            <?php esc_html_e('Use month-precision grace near minimum and maximum ages', 'intersoccer-product-variations'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('When disabled, validation uses completed whole years only (strict).', 'intersoccer-product-variations'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="below_min_months"><?php esc_html_e('Months below minimum', 'intersoccer-product-variations'); ?></label>
                    </th>
                    <td>
                        <input type="number" name="below_min_months" id="below_min_months" value="<?php echo esc_attr((int) $settings['below_min_months']); ?>" min="0" max="12" step="1" class="small-text">
                        <p class="description"><?php esc_html_e('Example: minimum age 3 with grace 2 allows children who are 2 years 10 or 11 months old on the program start date.', 'intersoccer-product-variations'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="above_max_months"><?php esc_html_e('Months above maximum', 'intersoccer-product-variations'); ?></label>
                    </th>
                    <td>
                        <input type="number" name="above_max_months" id="above_max_months" value="<?php echo esc_attr((int) $settings['above_max_months']); ?>" min="0" max="12" step="1" class="small-text">
                        <p class="description"><?php esc_html_e('Example: maximum age 12 with grace 1 allows a child who is 12 years and 1 month old on the program start date.', 'intersoccer-product-variations'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="half_day_above_max_months"><?php esc_html_e('Half-day months above maximum', 'intersoccer-product-variations'); ?></label>
                    </th>
                    <td>
                        <input type="number" name="half_day_above_max_months" id="half_day_above_max_months" value="<?php echo esc_attr((int) ($settings['half_day_above_max_months'] ?? 24)); ?>" min="0" max="36" step="1" class="small-text">
                        <p class="description"><?php esc_html_e('Additional months above the listed maximum age for half-day camp variations only. Requires age grace to be enabled. Example: max age 5 with 24 months allows a child up to 7 years on the program start date.', 'intersoccer-product-variations'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Strict missing start date', 'intersoccer-product-variations'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="strict_missing_reference_date" id="strict_missing_reference_date" value="1" <?php checked(!empty($settings['strict_missing_reference_date'])); ?>>
                            <?php esc_html_e('Block checkout when the program start date cannot be determined', 'intersoccer-product-variations'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('By default, bookings proceed when start date metadata is missing. Enable this to reject those bookings.', 'intersoccer-product-variations'); ?></p>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <input type="submit" name="intersoccer_age_restriction_submit" class="button button-primary" value="<?php esc_attr_e('Save Changes', 'intersoccer-product-variations'); ?>">
            </p>
        </form>
    </div>
    <?php
}

/**
 * Register strings for WPML.
 */
add_action('admin_init', 'intersoccer_register_age_restriction_settings_strings');
function intersoccer_register_age_restriction_settings_strings() {
    if (!function_exists('icl_register_string')) {
        return;
    }

    $strings = [
        'Age Restriction Settings',
        'Age Restrictions',
        'Enable age grace',
        'Use month-precision grace near minimum and maximum ages',
        'When disabled, validation uses completed whole years only (strict).',
        'Months below minimum',
        'Months above maximum',
        'Half-day months above maximum',
        'Additional months above the listed maximum age for half-day camp variations only. Requires age grace to be enabled. Example: max age 5 with 24 months allows a child up to 7 years on the program start date.',
        'Strict missing start date',
        'Block checkout when the program start date cannot be determined',
        'By default, bookings proceed when start date metadata is missing. Enable this to reject those bookings.',
        'Save Changes',
        'Settings saved.',
    ];

    foreach ($strings as $string) {
        icl_register_string('intersoccer-product-variations', $string, $string);
    }
}
