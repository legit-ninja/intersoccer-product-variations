<?php
/**
 * File: admin-ui.php
 * Description: Admin UI for InterSoccer WooCommerce customizations, including Discounts management and Variation Health Checker.
 * Dependencies: WooCommerce
 * Author: Jeremy Lee
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register admin submenu for Discounts, Update Orders, and Variation Health Checker.
 */
add_action('admin_menu', 'intersoccer_add_admin_submenus');
function intersoccer_add_admin_submenus() {
    // Discounts submenu
    add_submenu_page(
        'woocommerce-marketing',
        __('Manage Discounts', 'intersoccer-product-variations'),
        __('InterSoccer Discounts', 'intersoccer-product-variations'),
        'manage_woocommerce',
        'intersoccer-discounts',
        'intersoccer_render_discounts_page'
    );

    // Update Orders submenu
    add_submenu_page(
        'woocommerce',
        __('Scan Orders missing data', 'intersoccer-product-variations'),
        __('Find Order Issues', 'intersoccer-product-variations'),
        'manage_woocommerce',
        'intersoccer-update-orders',
        'intersoccer_render_update_orders_page',
        2
    );

    // Variation Health Checker submenu
    add_submenu_page(
        'edit.php?post_type=product',
        __('Variation Health Checker', 'intersoccer-product-variations'),
        __('Variation Health', 'intersoccer-product-variations'),
        'manage_woocommerce',
        'intersoccer-variation-health',
        'intersoccer_render_variation_health_page',
        1
    );

    add_submenu_page(
        'woocommerce',
        __('Bulk Repair Order Details', 'intersoccer-product-variations'),
        __('Bulk Repair Order', 'intersoccer-product-variations'),
        'manage_woocommerce',
        'intersoccer-automated-updates',
        'intersoccer_render_automated_update_orders_page',
    );

    error_log('InterSoccer: Registered admin submenus including Variation Health Checker');
}

/**
 * Register settings for Discounts.
 */
add_action('admin_init', 'intersoccer_register_discount_settings');
function intersoccer_register_discount_settings() {
    register_setting(
        'intersoccer_discounts_group',
        'intersoccer_discount_rules',
        [
            'type' => 'array',
            'sanitize_callback' => 'intersoccer_sanitize_discount_rules',
            'default' => []
        ]
    );
    error_log('InterSoccer: Registered discount settings in wp_options');
}

/**
 * Sanitize discount rules before saving.
 *
 * @param array $rules Array of discount rules.
 * @return array Sanitized rules.
 */
function intersoccer_sanitize_discount_rules($rules) {
    if (!is_array($rules)) {
        return [];
    }
    $sanitized = [];
    foreach ($rules as $rule) {
        $sanitized_rule = [
            'id' => isset($rule['id']) ? sanitize_key($rule['id']) : wp_generate_uuid4(),
            'name' => isset($rule['name']) ? sanitize_text_field($rule['name']) : '',
            'type' => in_array($rule['type'], ['camp', 'course', 'general']) ? $rule['type'] : 'general',
            'condition' => in_array($rule['condition'], ['2nd_child', '3rd_plus_child', 'same_season_course', 'none']) ? $rule['condition'] : 'none',
            'rate' => min(max(floatval($rule['rate']), 0), 100),
            'active' => isset($rule['active']) ? (bool) $rule['active'] : true
        ];
        if (!empty($sanitized_rule['name'])) {
            $sanitized[$sanitized_rule['id']] = $sanitized_rule;
        }
    }
    error_log('InterSoccer: Sanitized discount rules: ' . print_r($sanitized, true));
    return $sanitized;
}

/**
 * Custom WP_List_Table for Discounts.
 */
if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}
class InterSoccer_Discounts_Table extends WP_List_Table {
    public function __construct() {
        parent::__construct([
            'singular' => 'discount',
            'plural' => 'discounts',
            'ajax' => true
        ]);
    }

    public function get_columns() {
        return [
            'cb' => '<input type="checkbox" />',
            'name' => __('Name', 'intersoccer-product-variations'),
            'type' => __('Type', 'intersoccer-product-variations'),
            'condition' => __('Condition', 'intersoccer-product-variations'),
            'rate' => __('Discount Rate (%)', 'intersoccer-product-variations'),
            'active' => __('Active', 'intersoccer-product-variations')
        ];
    }

    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'name':
                return esc_html($item['name']);
            case 'type':
                return esc_html(ucfirst($item['type']));
            case 'condition':
                return esc_html($item['condition'] === 'none' ? __('None', 'intersoccer-product-variations') : ucwords(str_replace('_', ' ', $item['condition'])));
            case 'rate':
                return esc_html($item['rate']);
            case 'active':
                return $item['active'] ? __('Yes', 'intersoccer-product-variations') : __('No', 'intersoccer-product-variations');
            default:
                return '';
        }
    }

    public function column_cb($item) {
        return sprintf('<input type="checkbox" name="discount_ids[]" value="%s" />', esc_attr($item['id']));
    }

    public function get_bulk_actions() {
        return [
            'activate' => __('Activate', 'intersoccer-product-variations'),
            'deactivate' => __('Deactivate', 'intersoccer-product-variations'),
            'delete' => __('Delete', 'intersoccer-product-variations')
        ];
    }

    public function prepare_items() {
        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];
        $rules = get_option('intersoccer_discount_rules', []);
        $this->items = array_values($rules);

        $per_page = 20;
        $current_page = $this->get_pagenum();
        $total_items = count($this->items);

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page' => $per_page
        ]);

        $this->items = array_slice($this->items, ($current_page - 1) * $per_page, $per_page);
    }
}

/**
 * Render the Discounts admin page.
 */
function intersoccer_render_discounts_page() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die(__('You do not have permission to access this page.', 'intersoccer-product-variations'));
    }

    $table = new InterSoccer_Discounts_Table();
    $table->prepare_items();
    ?>
    <div class="wrap">
        <h1><?php _e('Manage Discounts', 'intersoccer-product-variations'); ?></h1>
        <p><?php _e('Add, edit, or delete discount rules for Camps, Courses, or other products. Rules apply automatically based on cart conditions (e.g., sibling bookings). For manual coupons, use <a href="' . admin_url('edit.php?post_type=shop_coupon') . '">WooCommerce > Coupons</a>.', 'intersoccer-product-variations'); ?></p>
        <form id="intersoccer-discounts-form" method="post">
            <h2><?php _e('Add New Discount', 'intersoccer-product-variations'); ?></h2>
            <table class="form-table">
                <tr>
                    <th><label for="discount_name"><?php _e('Name', 'intersoccer-product-variations'); ?></label></th>
                    <td><input type="text" id="discount_name" name="discount[name]" class="regular-text" required></td>
                </tr>
                <tr>
                    <th><label for="discount_type"><?php _e('Type', 'intersoccer-product-variations'); ?></label></th>
                    <td>
                        <select id="discount_type" name="discount[type]">
                            <option value="general"><?php _e('General', 'intersoccer-product-variations'); ?></option>
                            <option value="camp"><?php _e('Camp', 'intersoccer-product-variations'); ?></option>
                            <option value="course"><?php _e('Course', 'intersoccer-product-variations'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="discount_condition"><?php _e('Condition', 'intersoccer-product-variations'); ?></label></th>
                    <td>
                        <select id="discount_condition" name="discount[condition]">
                            <option value="none"><?php _e('None', 'intersoccer-product-variations'); ?></option>
                            <option value="2nd_child"><?php _e('2nd Child', 'intersoccer-product-variations'); ?></option>
                            <option value="3rd_plus_child"><?php _e('3rd or Additional Child', 'intersoccer-product-variations'); ?></option>
                            <option value="same_season_course"><?php _e('Same Season Course (Same Child)', 'intersoccer-product-variations'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="discount_rate"><?php _e('Discount Rate (%)', 'intersoccer-product-variations'); ?></label></th>
                    <td><input type="number" id="discount_rate" name="discount[rate]" min="0" max="100" step="0.1" required></td>
                </tr>
                <tr>
                    <th><label for="discount_active"><?php _e('Active', 'intersoccer-product-variations'); ?></label></th>
                    <td><input type="checkbox" id="discount_active" name="discount[active]" checked></td>
                </tr>
            </table>
            <p><button type="button" id="intersoccer-add-discount" class="button button-primary"><?php _e('Add Discount', 'intersoccer-product-variations'); ?></button></p>
            <?php wp_nonce_field('intersoccer_save_discount', 'intersoccer_discount_nonce'); ?>
        </form>
        <form id="intersoccer-discounts-table-form" method="post">
            <?php $table->display(); ?>
            <?php wp_nonce_field('intersoccer_bulk_discounts', 'intersoccer_bulk_nonce'); ?>
        </form>
        <div id="intersoccer-discount-status"></div>
    </div>
    <script>
        jQuery(document).ready(function($) {
            console.log('InterSoccer: Discounts page script loaded');
            $('#intersoccer-add-discount').on('click', function() {
                console.log('InterSoccer: Add Discount button clicked');
                $('#intersoccer-discount-status').text('<?php _e('Saving...', 'intersoccer-product-variations'); ?>').removeClass('error');
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'intersoccer_save_discount',
                        nonce: $('#intersoccer_discount_nonce').val(),
                        discount: {
                            name: $('#discount_name').val(),
                            type: $('#discount_type').val(),
                            condition: $('#discount_condition').val(),
                            rate: $('#discount_rate').val(),
                            active: $('#discount_active').is(':checked')
                        }
                    },
                    success: function(response) {
                        console.log('InterSoccer: AJAX success', response);
                        if (response.success) {
                            $('#intersoccer-discount-status').text('<?php _e('Discount saved successfully!', 'intersoccer-product-variations'); ?>');
                            window.location.reload();
                        } else {
                            $('#intersoccer-discount-status').text('<?php _e('Error: ', 'intersoccer-product-variations'); ?>' + (response.data.message || 'Unknown error')).addClass('error');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('InterSoccer: AJAX error', xhr.responseText, status, error);
                        $('#intersoccer-discount-status').text('<?php _e('An error occurred while saving the discount: ', 'intersoccer-product-variations'); ?>' + error).addClass('error');
                    }
                });
            });

            $('#intersoccer-discounts-table-form').on('submit', function(e) {
                e.preventDefault();
                console.log('InterSoccer: Bulk form submitted');
                var action = $('#bulk-action-selector-top').val() || $('#bulk-action-selector-bottom').val();
                var discountIds = $('input[name="discount_ids[]"]:checked').map(function() {
                    return $(this).val();
                }).get();

                if (!action || discountIds.length === 0) {
                    alert('<?php _e('Please select an action and at least one discount.', 'intersoccer-product-variations'); ?>');
                    return;
                }

                $('#intersoccer-discount-status').text('<?php _e('Processing...', 'intersoccer-product-variations'); ?>').removeClass('error');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'intersoccer_bulk_discounts',
                        nonce: $('#intersoccer_bulk_nonce').val(),
                        bulk_action: action,
                        discount_ids: discountIds
                    },
                    success: function(response) {
                        console.log('InterSoccer: Bulk AJAX success', response);
                        if (response.success) {
                            $('#intersoccer-discount-status').text('<?php _e('Action completed successfully!', 'intersoccer-product-variations'); ?>');
                            window.location.reload();
                        } else {
                            $('#intersoccer-discount-status').text('<?php _e('Error: ', 'intersoccer-product-variations'); ?>' + (response.data.message || 'Unknown error')).addClass('error');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('InterSoccer: Bulk AJAX error', xhr.responseText, status, error);
                        $('#intersoccer-discount-status').text('<?php _e('An error occurred while processing the action: ', 'intersoccer-product-variations'); ?>' + error).addClass('error');
                    }
                });
            });
        });
    </script>
    <style>
        #intersoccer-discount-status { margin-top: 10px; color: green; }
        #intersoccer-discount-status.error { color: red; }
    </style>
    <?php
}

/**
 * AJAX handler to save a new discount.
 */
add_action('wp_ajax_intersoccer_save_discount', 'intersoccer_save_discount_callback');
function intersoccer_save_discount_callback() {
    error_log('InterSoccer: intersoccer_save_discount AJAX called');
    check_ajax_referer('intersoccer_save_discount', 'nonce');
    error_log('InterSoccer: Nonce validated');

    if (!current_user_can('manage_woocommerce')) {
        error_log('InterSoccer: Permission denied for user ' . get_current_user_id());
        wp_send_json_error(['message' => __('You do not have permission to perform this action.', 'intersoccer-product-variations')]);
        wp_die();
    }
    error_log('InterSoccer: Permission check passed');

    $discount = isset($_POST['discount']) && is_array($_POST['discount']) ? $_POST['discount'] : [];
    if (empty($discount['name']) || empty($discount['rate'])) {
        error_log('InterSoccer: Missing name or rate in discount data: ' . print_r($discount, true));
        wp_send_json_error(['message' => __('Name and discount rate are required.', 'intersoccer-product-variations')]);
        wp_die();
    }

    $rules = get_option('intersoccer_discount_rules', []);
    $new_rule = [
        'id' => wp_generate_uuid4(),
        'name' => sanitize_text_field($discount['name']),
        'type' => in_array($discount['type'], ['camp', 'course', 'general']) ? $discount['type'] : 'general',
        'condition' => in_array($discount['condition'], ['2nd_child', '3rd_plus_child', 'same_season_course', 'none']) ? $discount['condition'] : 'none',
        'rate' => min(max(floatval($discount['rate']), 0), 100),
        'active' => isset($discount['active']) ? (bool) $discount['active'] : true
    ];

    $rules[$new_rule['id']] = $new_rule;
    update_option('intersoccer_discount_rules', $rules);
    error_log('InterSoccer: Saved new discount rule: ' . print_r($new_rule, true));

    wp_send_json_success(['message' => __('Discount saved.', 'intersoccer-product-variations')]);
    wp_die();
}

/**
 * AJAX handler for bulk discount actions.
 */
add_action('wp_ajax_intersoccer_bulk_discounts', 'intersoccer_bulk_discounts_callback');
function intersoccer_bulk_discounts_callback() {
    error_log('InterSoccer: intersoccer_bulk_discounts AJAX called');
    check_ajax_referer('intersoccer_bulk_discounts', 'nonce');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => __('You do not have permission to perform this action.', 'intersoccer-product-variations')]);
        wp_die();
    }

    $action = isset($_POST['bulk_action']) ? sanitize_text_field($_POST['bulk_action']) : '';
    $discount_ids = isset($_POST['discount_ids']) && is_array($_POST['discount_ids']) ? array_map('sanitize_key', $_POST['discount_ids']) : [];

    if (empty($action) || empty($discount_ids)) {
        wp_send_json_error(['message' => __('Invalid action or no discounts selected.', 'intersoccer-product-variations')]);
        wp_die();
    }

    $rules = get_option('intersoccer_discount_rules', []);
    foreach ($discount_ids as $id) {
        if (!isset($rules[$id])) {
            continue;
        }
        if ($action === 'delete') {
            unset($rules[$id]);
            error_log('InterSoccer: Deleted discount rule ID ' . $id);
        } elseif ($action === 'activate') {
            $rules[$id]['active'] = true;
            error_log('InterSoccer: Activated discount rule ID ' . $id);
        } elseif ($action === 'deactivate') {
            $rules[$id]['active'] = false;
            error_log('InterSoccer: Deactivated discount rule ID ' . $id);
        }
    }

    update_option('intersoccer_discount_rules', $rules);
    wp_send_json_success(['message' => __('Action completed.', 'intersoccer-product-variations')]);
    wp_die();
}

/**
 * Register enhanced settings for Discounts and Messages
 */
add_action('admin_init', 'intersoccer_register_enhanced_discount_settings');
function intersoccer_register_enhanced_discount_settings() {
    register_setting(
        'intersoccer_discounts_group',
        'intersoccer_discount_rules',
        [
            'type' => 'array',
            'sanitize_callback' => 'intersoccer_sanitize_enhanced_discount_rules',
            'default' => []
        ]
    );

    register_setting(
        'intersoccer_discounts_group',
        'intersoccer_discount_messages',
        [
            'type' => 'array',
            'sanitize_callback' => 'intersoccer_sanitize_discount_messages',
            'default' => []
        ]
    );
}

/**
 * Enhanced sanitization for discount rules
 */
function intersoccer_sanitize_enhanced_discount_rules($rules) {
    if (!is_array($rules)) {
        return [];
    }
    
    $sanitized = [];
    foreach ($rules as $rule) {
        $sanitized_rule = [
            'id' => isset($rule['id']) ? sanitize_key($rule['id']) : wp_generate_uuid4(),
            'name' => isset($rule['name']) ? sanitize_text_field($rule['name']) : '',
            'type' => in_array($rule['type'], ['camp', 'course', 'general']) ? $rule['type'] : 'general',
            'condition' => in_array($rule['condition'], ['2nd_child', '3rd_plus_child', 'same_season_course', 'none']) ? $rule['condition'] : 'none',
            'rate' => min(max(floatval($rule['rate']), 0), 100),
            'active' => isset($rule['active']) ? (bool) $rule['active'] : true,
            'message_key' => isset($rule['message_key']) ? sanitize_key($rule['message_key']) : $rule['id']
        ];
        
        if (!empty($sanitized_rule['name'])) {
            $sanitized[$sanitized_rule['id']] = $sanitized_rule;
        }
    }
    
    return $sanitized;
}

/**
 * Sanitize discount messages with WPML support
 */
function intersoccer_sanitize_discount_messages($messages) {
    if (!is_array($messages)) {
        return [];
    }
    
    $sanitized = [];
    foreach ($messages as $message_key => $languages) {
        if (!is_array($languages)) {
            continue;
        }
        
        $sanitized_languages = [];
        foreach ($languages as $lang_code => $message_data) {
            $sanitized_languages[sanitize_key($lang_code)] = [
                'cart_message' => sanitize_text_field($message_data['cart_message'] ?? ''),
                'admin_description' => sanitize_textarea_field($message_data['admin_description'] ?? ''),
                'customer_note' => sanitize_textarea_field($message_data['customer_note'] ?? '')
            ];
        }
        
        $sanitized[sanitize_key($message_key)] = $sanitized_languages;
    }
    
    return $sanitized;
}

/**
 * Get all available languages
 * Returns array of language codes and names
 * 
 * @return array Array of language_code => language_name
 */
function intersoccer_get_available_languages() {
    error_log('InterSoccer: intersoccer_get_available_languages() called');
    
    // Check for WPML
    if (function_exists('icl_get_languages')) {
        $languages = icl_get_languages('skip_missing=0');
        $available = [];
        
        foreach ($languages as $lang_code => $lang_info) {
            $available[$lang_code] = $lang_info['native_name'];
        }
        
        error_log('InterSoccer: WPML languages: ' . print_r($available, true));
        return $available;
    }
    
    // Check for Polylang
    if (function_exists('pll_languages_list')) {
        $lang_codes = pll_languages_list();
        $available = [];
        
        foreach ($lang_codes as $lang_code) {
            $lang_obj = pll_get_language($lang_code);
            $available[$lang_code] = $lang_obj ? $lang_obj->name : $lang_code;
        }
        
        if (!empty($available)) {
            error_log('InterSoccer: Polylang languages: ' . print_r($available, true));
            return $available;
        }
    }
    
    // Fallback to common languages
    $fallback = [
        'en' => 'English',
        'de' => 'Deutsch',
        'fr' => 'Français'
    ];
    
    error_log('InterSoccer: Using fallback languages: ' . print_r($fallback, true));
    return $fallback;
}

/**
 * Render combined Discounts and Messages admin page
 */
function intersoccer_render_enhanced_discounts_page() {
    $rules = get_option('intersoccer_discount_rules', []);
    $messages = get_option('intersoccer_discount_messages', []);
    $languages = intersoccer_get_available_languages();
    ?>
    <div class="wrap">
        <h1><?php _e('Manage Discounts & Messages', 'intersoccer-product-variations'); ?></h1>
        <form method="post" action="options.php">
            <?php settings_fields('intersoccer_discounts_group'); ?>
            <?php do_settings_sections('intersoccer_discounts_group'); ?>
            
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th><?php _e('Name', 'intersoccer-product-variations'); ?></th>
                        <th><?php _e('Type', 'intersoccer-product-variations'); ?></th>
                        <th><?php _e('Condition', 'intersoccer-product-variations'); ?></th>
                        <th><?php _e('Rate (%)', 'intersoccer-product-variations'); ?></th>
                        <th><?php _e('Active', 'intersoccer-product-variations'); ?></th>
                        <th><?php _e('Messages', 'intersoccer-product-variations'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rules as $rule_id => $rule): ?>
                        <tr>
                            <td>
                                <input type="text" name="intersoccer_discount_rules[<?php echo esc_attr($rule_id); ?>][name]" 
                                    value="<?php echo esc_attr($rule['name']); ?>" class="regular-text" />
                                <input type="hidden" name="intersoccer_discount_rules[<?php echo esc_attr($rule_id); ?>][id]" 
                                    value="<?php echo esc_attr($rule_id); ?>" />
                            </td>
                            <td>
                                <select name="intersoccer_discount_rules[<?php echo esc_attr($rule_id); ?>][type]">
                                    <option value="camp" <?php selected($rule['type'], 'camp'); ?>><?php _e('Camp', 'intersoccer-product-variations'); ?></option>
                                    <option value="course" <?php selected($rule['type'], 'course'); ?>><?php _e('Course', 'intersoccer-product-variations'); ?></option>
                                    <option value="general" <?php selected($rule['type'], 'general'); ?>><?php _e('General', 'intersoccer-product-variations'); ?></option>
                                </select>
                            </td>
                            <td>
                                <select name="intersoccer_discount_rules[<?php echo esc_attr($rule_id); ?>][condition]">
                                    <option value="2nd_child" <?php selected($rule['condition'], '2nd_child'); ?>><?php _e('2nd Child', 'intersoccer-product-variations'); ?></option>
                                    <option value="3rd_plus_child" <?php selected($rule['condition'], '3rd_plus_child'); ?>><?php _e('3rd+ Child', 'intersoccer-product-variations'); ?></option>
                                    <option value="same_season_course" <?php selected($rule['condition'], 'same_season_course'); ?>><?php _e('Same Season Course', 'intersoccer-product-variations'); ?></option>
                                    <option value="none" <?php selected($rule['condition'], 'none'); ?>><?php _e('None', 'intersoccer-product-variations'); ?></option>
                                </select>
                            </td>
                            <td>
                                <input type="number" step="0.01" min="0" max="100" 
                                    name="intersoccer_discount_rules[<?php echo esc_attr($rule_id); ?>][rate]" 
                                    value="<?php echo esc_attr($rule['rate']); ?>" class="small-text" />
                            </td>
                            <td>
                                <input type="checkbox" name="intersoccer_discount_rules[<?php echo esc_attr($rule_id); ?>][active]" 
                                    value="1" <?php checked($rule['active'], true); ?> />
                            </td>
                            <td>
                                <?php foreach ($languages as $lang_code => $lang_name): ?>
                                    <div class="intersoccer-message-group" style="margin-bottom: 15px;">
                                        <strong><?php echo esc_html($lang_name); ?></strong><br>
                                        <label><?php _e('Cart Message:', 'intersoccer-product-variations'); ?></label><br>
                                        <input type="text" class="regular-text" 
                                            name="intersoccer_discount_messages[<?php echo esc_attr($rule['message_key'] ?? $rule_id); ?>][<?php echo esc_attr($lang_code); ?>][cart_message]" 
                                            value="<?php echo esc_attr($messages[$rule['message_key'] ?? $rule_id][$lang_code]['cart_message'] ?? ''); ?>" /><br>
                                        <label><?php _e('Admin Description:', 'intersoccer-product-variations'); ?></label><br>
                                        <textarea class="regular-text" rows="2" 
                                                name="intersoccer_discount_messages[<?php echo esc_attr($rule['message_key'] ?? $rule_id); ?>][<?php echo esc_attr($lang_code); ?>][admin_description]"><?php echo esc_textarea($messages[$rule['message_key'] ?? $rule_id][$lang_code]['admin_description'] ?? ''); ?></textarea><br>
                                        <label><?php _e('Customer Note:', 'intersoccer-product-variations'); ?></label><br>
                                        <textarea class="regular-text" rows="2" 
                                                name="intersoccer_discount_messages[<?php echo esc_attr($rule['message_key'] ?? $rule_id); ?>][<?php echo esc_attr($lang_code); ?>][customer_note]"><?php echo esc_textarea($messages[$rule['message_key'] ?? $rule_id][$lang_code]['customer_note'] ?? ''); ?></textarea>
                                    </div>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <p>
                <button type="submit" class="button button-primary"><?php _e('Save All', 'intersoccer-product-variations'); ?></button>
            </p>
            <?php wp_nonce_field('intersoccer_save_discounts', 'intersoccer_discounts_nonce'); ?>
        </form>
        
        <div id="intersoccer-messages-status"></div>
        
        <script>
            jQuery(document).ready(function($) {
                $('form').on('submit', function(e) {
                    const $form = $(this);
                    const $button = $form.find('button[type="submit"]');
                    
                    $button.prop('disabled', true).text('<?php esc_js_e('Saving...', 'intersoccer-product-variations'); ?>');
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'intersoccer_save_discounts',
                            nonce: $form.find('input[name="intersoccer_discounts_nonce"]').val(),
                            rules: $form.find('[name^="intersoccer_discount_rules"]').serialize(),
                            messages: $form.find('[name^="intersoccer_discount_messages"]').serialize()
                        },
                        success: function(response) {
                            if (response.success) {
                                $('#intersoccer-messages-status').html('<div class="notice notice-success"><p><?php esc_js_e('Discounts and messages saved successfully!', 'intersoccer-product-variations'); ?></p></div>');
                                setTimeout(() => $('#intersoccer-messages-status').empty(), 3000);
                            } else {
                                $('#intersoccer-messages-status').html('<div class="notice notice-error"><p>' + (response.data?.message || '<?php esc_js_e('Error saving data', 'intersoccer-product-variations'); ?>') + '</p></div>');
                            }
                        },
                        error: function() {
                            $('#intersoccer-messages-status').html('<div class="notice notice-error"><p><?php esc_js_e('Network error occurred', 'intersoccer-product-variations'); ?></p></div>');
                        },
                        complete: function() {
                            $button.prop('disabled', false).text('<?php esc_js_e('Save All', 'intersoccer-product-variations'); ?>');
                        }
                    });
                    
                    e.preventDefault(); // Prevent default form submission
                });
            });
        </script>
    </div>
    <?php
}

/**
 * AJAX handler to save discount rules and messages
 */
add_action('wp_ajax_intersoccer_save_discounts', 'intersoccer_save_discounts_callback');
function intersoccer_save_discounts_callback() {
    check_ajax_referer('intersoccer_save_discounts', 'nonce');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => __('You do not have permission to perform this action.', 'intersoccer-product-variations')]);
        return;
    }

    // Parse rules
    $rules_data = [];
    if (isset($_POST['rules'])) {
        parse_str($_POST['rules'], $rules_data);
        $rules_data = $rules_data['intersoccer_discount_rules'] ?? [];
        $rules_data = intersoccer_sanitize_enhanced_discount_rules($rules_data);
    }

    // Parse messages
    $messages_data = [];
    if (isset($_POST['messages'])) {
        parse_str($_POST['messages'], $messages_data);
        $messages_data = $messages_data['intersoccer_discount_messages'] ?? [];
        $messages_data = intersoccer_sanitize_discount_messages($messages_data);
    }

    // Save to database
    update_option('intersoccer_discount_rules', $rules_data);
    update_option('intersoccer_discount_messages', $messages_data);

    wp_send_json_success(['message' => __('Discounts and messages saved successfully.', 'intersoccer-product-variations')]);
}

class InterSoccer_Order_Preview_Table extends WP_List_Table {
    public $all_items = []; // Track all items for proper statistics
    
    public function __construct() {
        parent::__construct([
            'singular' => 'order',
            'plural' => 'orders',
            'ajax' => false  // Disable AJAX for now to fix pagination
        ]);
    }

    public function get_columns() {
        return [
            'cb' => '<input type="checkbox" />',
            'order_id' => __('Order', 'intersoccer-product-variations'),
            'customer' => __('Customer', 'intersoccer-product-variations'),
            'items_summary' => __('Items & Types', 'intersoccer-product-variations'),
            'missing_summary' => __('Missing Fields Summary', 'intersoccer-product-variations'),
            'risk_level' => __('Risk Level', 'intersoccer-product-variations')
        ];
    }

    public function column_cb($item) {
        return sprintf('<input type="checkbox" name="order_ids[]" value="%s" data-risk="%s" />', 
            esc_attr($item['order_id']), 
            esc_attr($item['risk_level'])
        );
    }

    public function get_bulk_actions() {
        return [
            'update_selected' => __('Update Selected Orders', 'intersoccer-product-variations')
        ];
    }

    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'order_id':
                $edit_url = admin_url('post.php?post=' . $item['order_id'] . '&action=edit');
                return sprintf('<a href="%s" target="_blank"><strong>#%s</strong></a><br><small>%s</small>', 
                    $edit_url, 
                    $item['order_id'],
                    $item['order_date']
                );
                
            case 'customer':
                return sprintf('<strong>%s</strong><br><small>%s</small>', 
                    esc_html($item['customer_name']),
                    esc_html($item['customer_email'])
                );
                
            case 'items_summary':
                $total_missing_fields = 0;
                foreach ($item['missing_keys'] as $missing) {
                    $total_missing_fields += count($missing);
                }
                return sprintf('<span class="badge">%d items</span><br><small>%s</small><br><strong style="color: red;">%d missing fields</strong>', 
                    $item['total_items'],
                    implode(', ', $item['product_types']),
                    $total_missing_fields
                );
                
            case 'missing_summary':
                if (empty($item['missing_keys'])) {
                    return '<span style="color: green;">✓ Complete</span>';
                }
                
                // Collect all unique missing fields
                $all_missing = [];
                foreach ($item['missing_keys'] as $missing) {
                    $all_missing = array_merge($all_missing, $missing);
                }
                $unique_missing = array_unique($all_missing);
                
                $summary_html = '<div class="missing-summary">';
                $summary_html .= '<strong>' . count($unique_missing) . ' types of missing fields:</strong><br>';
                $summary_html .= '<small>' . implode(', ', array_slice($unique_missing, 0, 5));
                if (count($unique_missing) > 5) {
                    $summary_html .= ' <em>+' . (count($unique_missing) - 5) . ' more</em>';
                }
                $summary_html .= '</small></div>';
                return $summary_html;
                
            case 'risk_level':
                $risk_colors = [
                    'low' => 'green',
                    'medium' => 'orange', 
                    'high' => 'red'
                ];
                $color = $risk_colors[$item['risk_level']] ?? 'gray';
                $risk_reasons = implode('<br>', array_slice($item['risk_reasons'], 0, 2)); // Show only first 2 reasons
                
                return sprintf('<span style="color: %s; font-weight: bold;">%s</span><br><small>%s</small>', 
                    $color,
                    strtoupper($item['risk_level']),
                    $risk_reasons
                );
                
            default:
                return esc_html($item[$column_name] ?? '');
        }
    }

    public function prepare_items() {
        $this->_column_headers = [$this->get_columns(), [], []];
        
        $statuses = isset($_POST['order_statuses']) ? array_map('sanitize_text_field', $_POST['order_statuses']) : ['processing', 'completed'];
        $limit = isset($_POST['preview_limit']) ? intval($_POST['preview_limit']) : 25;
        
        // Get orders
        $orders = wc_get_orders([
            'status' => $statuses,
            'type' => 'shop_order',
            'limit' => $limit,
            'orderby' => 'date',
            'order' => 'DESC'
        ]);

        $this->all_items = $this->analyze_orders($orders);
        
        // Handle pagination properly
        $per_page = 10;
        $current_page = $this->get_pagenum();
        $total_items = count($this->all_items);

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page' => $per_page,
            'total_pages' => ceil($total_items / $per_page)
        ]);

        $this->items = array_slice($this->all_items, ($current_page - 1) * $per_page, $per_page);
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: Enhanced preview found ' . count($this->all_items) . ' orders with missing metadata, showing page ' . $current_page . ' (' . count($this->items) . ' items)');
        }
    }

    /**
     * Fixed analyze_orders method using the working deep debug logic
     */
    private function analyze_orders($orders) {
        $analysis = [];
        
        foreach ($orders as $order) {
            if (!($order instanceof WC_Order)) continue;
            
            $order_data = [
                'order_id' => $order->get_id(),
                'order_date' => $order->get_date_created()->format('Y-m-d H:i'),
                'customer_id' => $order->get_customer_id(),
                'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'customer_email' => $order->get_billing_email(),
                'total_items' => $order->get_item_count(),
                'missing_keys' => [],
                'proposed_updates' => [],
                'product_types' => [],
                'risk_level' => 'low',
                'risk_reasons' => []
            ];

            foreach ($order->get_items() as $item_id => $item) {
                $product_id = $item->get_product_id();
                $variation_id = $item->get_variation_id();
                $product_type = InterSoccer_Product_Types::get_product_type($product_id);
                
                if (!in_array($product_type, ['camp', 'course', 'birthday'])) {
                    continue;
                }
                
                $order_data['product_types'][] = ucfirst($product_type);
                
                // Use EXACT SAME LOGIC as working deep debug
                $user_id = $order->get_customer_id();
                $existing_meta = $item->get_meta_data();
                $existing_keys = array_map(function($meta) { return $meta->key; }, $existing_meta);
                
                $assigned_player = in_array('assigned_player', $existing_keys) ? $item->get_meta('assigned_player', true) : 0;
                $player_details = intersoccer_get_player_details($user_id, $assigned_player);
                
                $potential_updates = [
                    'Assigned Attendee' => isset($player_details['name']) ? $player_details['name'] : null,
                    'Attendee DOB' => isset($player_details['dob']) ? $player_details['dob'] : null,
                    'Attendee Gender' => isset($player_details['gender']) ? $player_details['gender'] : null,
                    'Medical Conditions' => isset($player_details['medical_conditions']) ? 
                        (!empty($player_details['medical_conditions']) ? $player_details['medical_conditions'] : 'None') : null,
                    'Activity Type' => ucfirst($product_type ?: 'Unknown'),
                    'Season' => intersoccer_get_product_season($product_id),
                    'Variation ID' => $variation_id
                ];
                
                $attributes = intersoccer_get_parent_product_attributes($product_id, $variation_id);
                $potential_updates = array_merge($potential_updates, $attributes);
                
                // Check what's missing
                $missing = [];
                $proposed = [];
                
                foreach ($potential_updates as $key => $value) {
                    if ($value === null || ($value === '' && $key !== 'Medical Conditions')) {
                        continue;
                    }
                    
                    if (!in_array($key, $existing_keys)) {
                        $missing[] = $key;
                        $proposed[$key] = $value;
                    }
                }
                
                if (!empty($missing)) {
                    $order_data['missing_keys'][$item_id] = $missing;
                }
                
                if (!empty($proposed)) {
                    $order_data['proposed_updates'][$item_id] = $proposed;
                }
            }
            
            // Only include orders that have missing metadata
            if (!empty($order_data['missing_keys'])) {
                $order_data['product_types'] = array_unique($order_data['product_types']);
                
                // Simple risk assessment
                $total_missing = 0;
                foreach ($order_data['missing_keys'] as $missing) {
                    $total_missing += count($missing);
                }
                
                if ($total_missing > 6) {
                    $order_data['risk_level'] = 'high';
                    $order_data['risk_reasons'][] = 'Many missing fields (' . $total_missing . ')';
                } elseif ($total_missing > 3) {
                    $order_data['risk_level'] = 'medium';
                    $order_data['risk_reasons'][] = 'Several missing fields (' . $total_missing . ')';
                } else {
                    $order_data['risk_reasons'][] = 'Few missing fields (' . $total_missing . ')';
                }
                
                if (!$order->get_customer_id()) {
                    $order_data['risk_level'] = 'medium';
                    $order_data['risk_reasons'][] = 'Guest checkout';
                }
                
                $analysis[] = $order_data;
            }
        }
        
        return $analysis;
    }
}

/**
 * Modified intersoccer_render_update_orders_page
 */
function intersoccer_render_update_orders_page() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die(__('You do not have permission to access this page.', 'intersoccer-product-variations'));
    }

    $message = '';
    $show_preview = isset($_POST['preview_updates']) || isset($_POST['detailed_preview']);
    $show_detailed = isset($_POST['detailed_preview']);
    
    // Handle bulk update
    if (isset($_POST['update_selected_orders'])) {
        check_admin_referer('intersoccer_update_orders', 'intersoccer_update_orders_nonce');
        
        $order_ids = isset($_POST['selected_order_ids']) ? array_map('intval', explode(',', $_POST['selected_order_ids'])) : [];
        $updated_count = 0;
        $errors = [];
        
        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if ($order && intersoccer_update_order_metadata($order)) {
                $updated_count++;
            } else {
                $errors[] = $order_id;
            }
        }
        
        $message = sprintf(__('Updated metadata for %d orders.', 'intersoccer-product-variations'), $updated_count);
        if (!empty($errors)) {
            $message .= ' ' . sprintf(__('Failed to update: %s', 'intersoccer-product-variations'), implode(', ', $errors));
        }
    }

    ?>
    <div class="wrap">
        <h1><?php _e('Order Metadata Update Tool', 'intersoccer-product-variations'); ?></h1>
        <p><?php _e('Find and update orders that are missing metadata fields needed for accurate rosters and reports.', 'intersoccer-product-variations'); ?></p>
        
        <?php if ($message) : ?>
            <div class="updated notice"><p><?php echo esc_html($message); ?></p></div>
        <?php endif; ?>

        <!-- Configuration Form -->
        <form method="post" id="preview-orders-form">
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Order Statuses to Check', 'intersoccer-product-variations'); ?></th>
                    <td>
                        <label><input type="checkbox" name="order_statuses[]" value="processing" checked> <?php _e('Processing', 'intersoccer-product-variations'); ?></label><br>
                        <label><input type="checkbox" name="order_statuses[]" value="completed" checked> <?php _e('Completed', 'intersoccer-product-variations'); ?></label><br>
                        <label><input type="checkbox" name="order_statuses[]" value="on-hold"> <?php _e('On Hold', 'intersoccer-product-variations'); ?></label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Number of Orders to Scan', 'intersoccer-product-variations'); ?></th>
                    <td>
                        <select name="preview_limit">
                            <option value="25" <?php selected($_POST['preview_limit'] ?? '', '25'); ?>>25 recent orders</option>
                            <option value="50" <?php selected($_POST['preview_limit'] ?? '', '50'); ?>>50 recent orders</option>
                            <option value="100" <?php selected($_POST['preview_limit'] ?? '', '100'); ?>>100 recent orders</option>
                            <option value="500" <?php selected($_POST['preview_limit'] ?? '', '500'); ?>>500 recent orders</option>
                            <option value="1000" <?php selected($_POST['preview_limit'] ?? '', '1000'); ?>>1000 recent orders</option>
                            <option value="2000" <?php selected($_POST['preview_limit'] ?? '', '2000'); ?>>2000 recent orders</option>
                            <option value="-1" <?php selected($_POST['preview_limit'] ?? '', '-1'); ?>>All orders (may be slow)</option>
                        </select>
                        <p class="description"><?php _e('Higher numbers may take longer to process but will find more orders with missing data.', 'intersoccer-product-variations'); ?></p>
                    </td>
                </tr>
            </table>
            
            <?php wp_nonce_field('intersoccer_update_orders', 'intersoccer_update_orders_nonce'); ?>
            <p>
                <input type="submit" name="preview_updates" class="button button-primary" value="<?php _e('Find Orders Missing Metadata', 'intersoccer-product-variations'); ?>">
            </p>
            <p class="description">
                <strong><?php _e('Note:', 'intersoccer-product-variations'); ?></strong> 
                <?php _e('This will scan your selected orders and show which ones are missing metadata fields like player details, seasons, activity types, etc.', 'intersoccer-product-variations'); ?>
            </p>
        </form>

        <?php if ($show_preview) : ?>
            <hr>
            <h2><?php _e('Orders Missing Metadata', 'intersoccer-product-variations'); ?></h2>
            
            <!-- Progress Bar Container -->
            <div id="update-progress" style="display: none;">
                <h3><?php _e('Update Progress', 'intersoccer-product-variations'); ?></h3>
                <div style="background: #f1f1f1; border-radius: 10px; padding: 3px;">
                    <div id="progress-bar" style="background: #4CAF50; height: 20px; border-radius: 8px; width: 0%; transition: width 0.3s;"></div>
                </div>
                <p id="progress-text">Preparing...</p>
            </div>

            <?php
            $table = new InterSoccer_Order_Preview_Table();
            $table->prepare_items();
            
            if (empty($table->items)) {
                echo '<div class="notice notice-success"><p>' . __('Great! No orders found that need metadata updates.', 'intersoccer-product-variations') . '</p></div>';
            } else {
                // Get actual statistics
                $total_orders_scanned = count($table->all_items);  // We'll need to track this
                $orders_with_missing = count($table->all_items);   // Orders that have missing metadata
                $total_missing_items = 0;
                foreach ($table->all_items as $order_data) {
                    foreach ($order_data['missing_keys'] as $item_missing) {
                        $total_missing_items += count($item_missing);
                    }
                }
                ?>
                <!-- Fixed Summary Statistics -->
                <div class="intersoccer-summary-stats" style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; margin: 15px 0;">
                    <h3><?php _e('Scan Results', 'intersoccer-product-variations'); ?></h3>
                    <div style="display: flex; gap: 30px; flex-wrap: wrap;">
                        <div><strong><?php echo $orders_with_missing; ?></strong> orders need updates</div>
                        <div><strong><?php echo $total_missing_items; ?></strong> total missing metadata fields</div>
                        <div><strong id="selected-count">0</strong> orders selected for update</div>
                        <div style="color: red;"><strong id="high-risk-count">0</strong> high-risk orders</div>
                        <div style="color: orange;"><strong id="medium-risk-count">0</strong> medium-risk orders</div>
                        <div style="color: green;"><strong id="low-risk-count">0</strong> low-risk orders</div>
                    </div>
                </div>

                <!-- Fixed Bulk Action Form -->
                <form method="post" id="bulk-update-form">
                    <div class="tablenav top">
                        <div class="alignleft actions">
                            <button type="button" id="select-all-low-risk" class="button"><?php _e('Select All Low Risk', 'intersoccer-product-variations'); ?></button>
                            <button type="button" id="select-none" class="button"><?php _e('Deselect All', 'intersoccer-product-variations'); ?></button>
                            <button type="submit" name="update_selected_orders" class="button button-primary" id="update-selected-btn" disabled>
                                <?php _e('Update Selected Orders', 'intersoccer-product-variations'); ?>
                            </button>
                            <button type="button" id="export-analysis" class="button button-secondary">
                                <?php _e('Export Analysis to CSV', 'intersoccer-product-variations'); ?>
                            </button>
                        </div>
                    </div>

                    <?php $table->display(); ?>
                    
                    <input type="hidden" id="selected-order-ids" name="selected_order_ids" value="">
                    <?php wp_nonce_field('intersoccer_update_orders', 'intersoccer_update_orders_nonce'); ?>
                </form>
                <?php
            }
            ?>
        <?php endif; ?>
    </div>

    <style>
        .intersoccer-summary-stats {
            border-radius: 5px;
        }
        .missing-metadata-list, .proposed-changes-list {
            max-height: 150px;
            overflow-y: auto;
            font-size: 12px;
        }
        .item-missing, .item-changes {
            padding: 5px;
            margin: 2px 0;
            background: #f9f9f9;
            border-left: 3px solid #ddd;
        }
        .badge {
            background: #0073aa;
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
        }
        #update-progress {
            margin: 20px 0;
            padding: 15px;
            background: #fff;
            border: 1px solid #ccd0d4;
        }
        .wp-list-table .column-missing_metadata { width: 25%; }
        .wp-list-table .column-proposed_changes { width: 25%; }
        .wp-list-table .column-risk_level { width: 15%; }
        .pagination-links { margin: 10px 0; }
    </style>

    <script>
        jQuery(document).ready(function($) {
            console.log('InterSoccer: Enhanced Order Update UI loaded');
            
            let selectedOrders = [];
            
            // Handle checkbox changes
            $('input[name="order_ids[]"]').on('change', function() {
                updateSelection();
                updateSummaryStats();
            });
            
            // Select all low risk
            $('#select-all-low-risk').on('click', function() {
                $('input[name="order_ids[]"][data-risk="low"]').prop('checked', true);
                updateSelection();
                updateSummaryStats();
            });
            
            // Deselect all
            $('#select-none').on('click', function() {
                $('input[name="order_ids[]"]').prop('checked', false);
                updateSelection();
                updateSummaryStats();
            });
            
            // Update selected orders list
            function updateSelection() {
                selectedOrders = $('input[name="order_ids[]"]:checked').map(function() {
                    return $(this).val();
                }).get();
                
                $('#selected-order-ids').val(selectedOrders.join(','));
                $('#update-selected-btn').prop('disabled', selectedOrders.length === 0);
                $('#selected-count').text(selectedOrders.length);
            }
            
            // Update summary statistics
            function updateSummaryStats() {
                let highRisk = $('input[name="order_ids[]"][data-risk="high"]:checked').length;
                let mediumRisk = $('input[name="order_ids[]"][data-risk="medium"]:checked').length;
                let lowRisk = $('input[name="order_ids[]"][data-risk="low"]:checked').length;
                
                $('#high-risk-count').text(highRisk);
                $('#medium-risk-count').text(mediumRisk);
                $('#low-risk-count').text(lowRisk);
            }
            
            // Initialize stats
            updateSummaryStats();
            
            // Handle bulk update with progress (your existing logic)
            $('#bulk-update-form').on('submit', function(e) {
                e.preventDefault();
                
                if (selectedOrders.length === 0) {
                    alert('<?php _e('Please select at least one order to update.', 'intersoccer-product-variations'); ?>');
                    return false;
                }
                
                let highRiskSelected = $('input[name="order_ids[]"][data-risk="high"]:checked').length;
                if (highRiskSelected > 0) {
                    if (!confirm('<?php _e('You have selected high-risk orders. These may have unexpected results. Continue?', 'intersoccer-product-variations'); ?>')) {
                        return false;
                    }
                }
                
                // Show progress bar and start batch processing (your existing logic)
                $('#update-progress').show();
                // ... rest of your batch processing code
            });
            
            console.log('InterSoccer: Enhanced UI event handlers attached');
        });
    </script>
    <?php
}

/**
 * Enhanced JavaScript for real-time batch processing
 */
function intersoccer_update_orders_scripts() {
    if (isset($_GET['page']) && $_GET['page'] === 'intersoccer-update-orders') {
        ?>
        <script>
        jQuery(document).ready(function($) {
            // Enhanced batch processing with real-time updates
            function processBatchUpdate(orderIds, startIndex = 0, batchSize = 5) {
                return $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'intersoccer_batch_update_orders',
                        nonce: $('#intersoccer_update_orders_nonce').val(),
                        order_ids: orderIds,
                        start_index: startIndex,
                        batch_size: batchSize
                    }
                });
            }

            // Override the form submission for batch processing
            $('#bulk-update-form').off('submit').on('submit', function(e) {
                e.preventDefault();
                
                let selectedOrders = $('input[name="order_ids[]"]:checked').map(function() {
                    return parseInt($(this).val());
                }).get();
                
                if (selectedOrders.length === 0) {
                    alert('<?php _e('Please select at least one order to update.', 'intersoccer-product-variations'); ?>');
                    return false;
                }
                
                let highRiskSelected = $('input[name="order_ids[]"][data-risk="high"]:checked').length;
                if (highRiskSelected > 0) {
                    if (!confirm('<?php _e('You have selected high-risk orders. These may have unexpected results. Continue?', 'intersoccer-product-variations'); ?>')) {
                        return false;
                    }
                }

                // Initialize progress tracking
                $('#update-progress').show();
                $('#update-selected-btn').prop('disabled', true).text('<?php _e('Processing...', 'intersoccer-product-variations'); ?>');
                
                let totalOrders = selectedOrders.length;
                let processedOrders = 0;
                let successCount = 0;
                let errorCount = 0;
                let allDetails = [];
                let allErrors = [];

                function processBatch(startIndex) {
                    processBatchUpdate(selectedOrders, startIndex, 3) // Smaller batches for better feedback
                        .done(function(response) {
                            if (response.success) {
                                processedOrders += response.data.processed_in_batch;
                                successCount += response.data.updated_count;
                                errorCount += response.data.errors.length;
                                allDetails = allDetails.concat(response.data.details);
                                allErrors = allErrors.concat(response.data.errors);

                                // Update progress
                                let progress = response.data.progress;
                                $('#progress-bar').css('width', progress + '%');
                                $('#progress-text').html(
                                    'Processed ' + processedOrders + ' of ' + totalOrders + ' orders<br>' +
                                    'Success: ' + successCount + ' | Errors: ' + errorCount
                                );

                                if (response.data.is_complete) {
                                    // Show completion summary
                                    $('#progress-bar').css('width', '100%');
                                    $('#progress-text').html(
                                        '<strong>Update Complete!</strong><br>' +
                                        'Successfully updated: ' + successCount + ' orders<br>' +
                                        'Errors: ' + errorCount + ' orders<br>' +
                                        '<button type="button" id="show-details" class="button">Show Details</button> ' +
                                        '<button type="button" id="reload-page" class="button button-primary">Reload Page</button>'
                                    );
                                    
                                    // Add details panel
                                    let detailsHtml = '<div id="update-details" style="display:none; margin-top: 15px;">';
                                    detailsHtml += '<h4>Successful Updates:</h4><ul>';
                                    allDetails.forEach(function(detail) {
                                        detailsHtml += '<li>Order #' + detail.order_id + ' (' + detail.customer + ') - Added ' + detail.metadata_added + ' metadata fields</li>';
                                    });
                                    detailsHtml += '</ul>';
                                    
                                    if (allErrors.length > 0) {
                                        detailsHtml += '<h4 style="color: red;">Errors:</h4><ul>';
                                        allErrors.forEach(function(error) {
                                            detailsHtml += '<li style="color: red;">Order #' + error.order_id + ' (' + error.customer + ') - ' + error.error + '</li>';
                                        });
                                        detailsHtml += '</ul>';
                                    }
                                    detailsHtml += '</div>';
                                    
                                    $('#update-progress').append(detailsHtml);
                                    
                                    // Attach event handlers for completion buttons
                                    $('#show-details').on('click', function() {
                                        $('#update-details').toggle();
                                    });
                                    
                                    $('#reload-page').on('click', function() {
                                        window.location.reload();
                                    });
                                    
                                } else {
                                    // Process next batch
                                    setTimeout(function() {
                                        processBatch(response.data.next_index);
                                    }, 500); // Small delay to prevent overwhelming the server
                                }
                            } else {
                                $('#progress-text').html('<span style="color: red;">Error: ' + (response.data?.message || 'Unknown error occurred') + '</span>');
                                $('#update-selected-btn').prop('disabled', false).text('<?php _e('Update Selected Orders', 'intersoccer-product-variations'); ?>');
                            }
                        })
                        .fail(function(xhr, status, error) {
                            $('#progress-text').html('<span style="color: red;">Network error: ' + error + '</span>');
                            $('#update-selected-btn').prop('disabled', false).text('<?php _e('Update Selected Orders', 'intersoccer-product-variations'); ?>');
                        });
                }

                // Start the batch processing
                processBatch(0);
            });

            // Export functionality
            $('#export-analysis').on('click', function(e) {
                e.preventDefault();
                
                let form = $('<form>', {
                    method: 'POST',
                    action: ajaxurl
                });
                
                form.append($('<input>', {
                    type: 'hidden',
                    name: 'action',
                    value: 'intersoccer_export_order_analysis'
                }));
                
                form.append($('<input>', {
                    type: 'hidden',
                    name: 'nonce',
                    value: $('#intersoccer_update_orders_nonce').val()
                }));
                
                // Add current filter values
                $('input[name="order_statuses[]"]:checked').each(function() {
                    form.append($('<input>', {
                        type: 'hidden',
                        name: 'order_statuses[]',
                        value: $(this).val()
                    }));
                });
                
                form.append($('<input>', {
                    type: 'hidden',
                    name: 'limit',
                    value: $('select[name="preview_limit"]').val() || 100
                }));
                
                $('body').append(form);
                form.submit();
                form.remove();
            });
        });
        </script>
        <?php
    }
}
add_action('admin_footer', 'intersoccer_update_orders_scripts');

/**
 * Detects and updates missing metadata from order item details
 */
function intersoccer_update_order_metadata($order) {
    if (!($order instanceof WC_Order)) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: Skipped order ' . $order->get_id() . ' - Not a WC_Order (type: ' . get_class($order) . ')');
        }
        return false;
    }

    $overall_updated = false;
    $order_id = $order->get_id();
    
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer: Processing metadata update for order ' . $order_id . ' (customer ID: ' . $order->get_customer_id() . ')');
    }

    foreach ($order->get_items() as $item_id => $item) {
        $product_id = $item->get_product_id();
        $variation_id = $item->get_variation_id();
        $product_type = InterSoccer_Product_Types::get_product_type($product_id);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: Order ' . $order_id . ', Item ' . $item_id . ' - Product ID: ' . $product_id . ', Variation ID: ' . $variation_id . ', Detected Type: ' . ($product_type ?: 'None'));
        }

        // Skip non-relevant products
        if (!in_array($product_type, ['camp', 'course', 'birthday'])) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer: Order ' . $order_id . ', Item ' . $item_id . ' - Skipping: Not a relevant product type.');
            }
            continue;
        }

        // Get existing metadata
        $existing_meta = $item->get_meta_data();
        $existing_keys = array_map(function($meta) { return $meta->key; }, $existing_meta);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: Order ' . $order_id . ', Item ' . $item_id . ' - Existing meta keys: ' . implode(', ', $existing_keys));
        }

        $item_updated = false;

        // Get player details
        $user_id = $order->get_customer_id();
        $assigned_player = in_array('assigned_player', $existing_keys) ? $item->get_meta('assigned_player', true) : 0;
        $player_details = intersoccer_get_player_details($user_id, $assigned_player);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: Order ' . $order_id . ', Item ' . $item_id . ' - Player details from helper: ' . (empty($player_details['name']) ? 'Empty' : $player_details['name']));
        }

        // Build potential updates array (SAME LOGIC AS WORKING DEEP DEBUG)
        $potential_updates = [
            'Assigned Attendee' => isset($player_details['name']) ? $player_details['name'] : null,
            'Attendee DOB' => isset($player_details['dob']) ? $player_details['dob'] : null,
            'Attendee Gender' => isset($player_details['gender']) ? $player_details['gender'] : null,
            'Medical Conditions' => isset($player_details['medical_conditions']) ? 
                (!empty($player_details['medical_conditions']) ? $player_details['medical_conditions'] : 'None') : null,
            'Activity Type' => ucfirst($product_type ?: 'Unknown'),
            'Season' => intersoccer_get_product_season($product_id),
            'Variation ID' => $variation_id
        ];

        // Add product attributes
        $attributes = intersoccer_get_parent_product_attributes($product_id, $variation_id);
        $potential_updates = array_merge($potential_updates, $attributes);

        // Type-specific metadata
        if ($product_type === 'camp') {
            $camp_times = get_post_meta($variation_id ?: $product_id, '_camp_times', true);
            if ($camp_times) {
                $potential_updates['Camp Times'] = $camp_times;
            }
        } elseif ($product_type === 'course') {
            $start_date = get_post_meta($variation_id ?: $product_id, '_course_start_date', true);
            if ($start_date) {
                $potential_updates['Start Date'] = date_i18n('d/m/y', strtotime($start_date));
            }
            
            $end_date = get_post_meta($variation_id ?: $product_id, '_end_date', true);
            if ($end_date) {
                $potential_updates['End Date'] = date_i18n('d/m/Y', strtotime($end_date));
            }
            
            $holidays = get_post_meta($variation_id ?: $product_id, '_course_holiday_dates', true) ?: [];
            if (!empty($holidays)) {
                $formatted_holidays = implode(', ', array_map(function($date) { return date_i18n('F j, Y', strtotime($date)); }, $holidays));
                $potential_updates['Holidays'] = $formatted_holidays;
            }
            
            $total_weeks = (int) get_post_meta($variation_id ?: $product_id, '_course_total_weeks', true);
            if ($total_weeks > 0) {
                $remaining_sessions = InterSoccer_Course::calculate_remaining_sessions($variation_id ?: $product_id, $total_weeks);
                $potential_updates['Remaining Sessions'] = $remaining_sessions;
            }
        }

        // Add Base Price
        $product = wc_get_product($variation_id ?: $product_id);
        if ($product) {
            $base_price = floatval($product->get_regular_price());
            $potential_updates['Base Price'] = wc_price($base_price);
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: Order ' . $order_id . ', Item ' . $item_id . ' - Potential updates count: ' . count($potential_updates));
        }

        // Apply updates (SAME LOGIC AS WORKING DEEP DEBUG)
        foreach ($potential_updates as $key => $value) {
            // Skip null or empty values (except for Medical Conditions which can be 'None')
            if ($value === null || ($value === '' && $key !== 'Medical Conditions')) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('InterSoccer: Order ' . $order_id . ', Item ' . $item_id . ' - Skipping ' . $key . ' - value is null/empty');
                }
                continue;
            }
            
            if (!in_array($key, $existing_keys)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('InterSoccer: Order ' . $order_id . ', Item ' . $item_id . ' - SHOULD UPDATE: ' . $key . ' = ' . $value . ' (missing from existing keys)');
                }
                
                try {
                    $item->add_meta_data($key, $value);
                    $item_updated = true;
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('InterSoccer: Order ' . $order_id . ', Item ' . $item_id . ' - ADDED: ' . $key . ' = ' . $value);
                    }
                } catch (Exception $e) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('InterSoccer: Order ' . $order_id . ', Item ' . $item_id . ' - FAILED TO ADD ' . $key . ': ' . $e->getMessage());
                    }
                }
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('InterSoccer: Order ' . $order_id . ', Item ' . $item_id . ' - Already exists: ' . $key);
                }
            }
        }

        // Save the item if updated
        if ($item_updated) {
            try {
                $item->save();
                $overall_updated = true;
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('InterSoccer: Order ' . $order_id . ', Item ' . $item_id . ' - Item saved successfully');
                }
            } catch (Exception $e) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('InterSoccer: Order ' . $order_id . ', Item ' . $item_id . ' - FAILED to save item: ' . $e->getMessage());
                }
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer: Order ' . $order_id . ', Item ' . $item_id . ' - No updates needed');
            }
        }
    }

    // Save the order if any items were updated
    if ($overall_updated) {
        try {
            $order->save();
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer: Order ' . $order_id . ' - Order saved successfully');
            }
        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer: Order ' . $order_id . ' - FAILED to save order: ' . $e->getMessage());
            }
        }
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer: Order ' . $order_id . ' - Update completed. Updated: ' . ($overall_updated ? 'YES' : 'NO'));
    }

    return $overall_updated;
}

/**
 * Fixed version of the analysis function for the enhanced preview table
 */
function intersoccer_analyze_item_metadata($order, $item, $product_type) {
    $product_id = $item->get_product_id();
    $variation_id = $item->get_variation_id();
    $user_id = $order->get_customer_id();
    
    // Get existing meta keys
    $existing_meta = $item->get_meta_data();
    $existing_keys = array_map(function($meta) { return $meta->key; }, $existing_meta);
    
    $missing = [];
    $proposed = [];
    
    // Get player details
    $assigned_player = in_array('assigned_player', $existing_keys) ? $item->get_meta('assigned_player', true) : 0;
    $player_details = intersoccer_get_player_details($user_id, $assigned_player);
    
    // Build potential updates (SAME LOGIC AS CORRECTED UPDATE FUNCTION)
    $potential_updates = [
        'Assigned Attendee' => isset($player_details['name']) ? $player_details['name'] : null,
        'Attendee DOB' => isset($player_details['dob']) ? $player_details['dob'] : null,
        'Attendee Gender' => isset($player_details['gender']) ? $player_details['gender'] : null,
        'Medical Conditions' => isset($player_details['medical_conditions']) ? 
            (!empty($player_details['medical_conditions']) ? $player_details['medical_conditions'] : 'None') : null,
        'Activity Type' => ucfirst($product_type ?: 'Unknown'),
        'Season' => intersoccer_get_product_season($product_id),
        'Variation ID' => $variation_id
    ];
    
    // Add product attributes
    $attributes = intersoccer_get_parent_product_attributes($product_id, $variation_id);
    $potential_updates = array_merge($potential_updates, $attributes);
    
    // Check each potential update
    foreach ($potential_updates as $key => $value) {
        // Skip null or empty values (except for Medical Conditions which can be 'None')
        if ($value === null || ($value === '' && $key !== 'Medical Conditions')) {
            continue;
        }
        
        if (!in_array($key, $existing_keys)) {
            $missing[] = $key;
            $proposed[$key] = $value;
        }
    }
    
    return [
        'missing' => $missing,
        'proposed' => $proposed
    ];
}

/**
 * AJAX handler to update Processing orders.
 */
add_action('wp_ajax_intersoccer_update_processing_orders', 'intersoccer_update_processing_orders_callback');
function intersoccer_update_processing_orders_callback() {
    check_ajax_referer('intersoccer_update_orders_nonce', 'nonce');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => __('You do not have permission to perform this action.', 'intersoccer-product-variations')]);
        wp_die();
    }

    $order_ids = isset($_POST['order_ids']) && is_array($_POST['order_ids']) ? array_map('intval', $_POST['order_ids']) : [];
    $remove_assigned_player = isset($_POST['remove_assigned_player']) && $_POST['remove_assigned_player'] === 'true';
    $fix_incorrect = isset($_POST['fix_incorrect_attributes']) && $_POST['fix_incorrect_attributes'] === 'true';

    if (empty($order_ids)) {
        wp_send_json_error(['message' => __('No orders selected.', 'intersoccer-product-variations')]);
        wp_die();
    }

    foreach ($order_ids as $order_id) {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_status() !== 'processing') {
            error_log('InterSoccer: Invalid or non-Processing order ID ' . $order_id);
            continue;
        }

        foreach ($order->get_items() as $item_id => $item) {
            $product_id = $item->get_product_id();
            $variation_id = $item->get_variation_id();
            $product = wc_get_product($variation_id ?: $product_id);

            if (!$product) {
                error_log('InterSoccer: Invalid product for order item ' . $item_id . ' in order ' . $order_id);
                continue;
            }

            $product_type = InterSoccer_Product_Types::get_product_type($product_id);

            if ($remove_assigned_player) {
                $item->delete_meta_data('assigned_player');
                error_log('InterSoccer: Removed assigned_player from order item ' . $item_id . ' in order ' . $order_id);
            }

            if ($fix_incorrect && $product_type === 'camp') {
                $item->delete_meta_data('Days-of-week');
                error_log('InterSoccer: Removed Days-of-week attribute from order item ' . $item_id . ' in order ' . $order_id);
            }

            $item->save();
        }

        $order->save();
        error_log('InterSoccer: Updated order ' . $order_id . ' with new parent attributes' . ($remove_assigned_player ? ' and removed assigned_player' : '') . ($fix_incorrect ? ' and fixed incorrect attributes' : ''));
    }

    wp_send_json_success(['message' => __('Orders updated successfully.', 'intersoccer-product-variations')]);
    wp_die();
}

/**
 * Custom WP_List_Table for Variation Health.
 */
class InterSoccer_Variation_Health_Table extends WP_List_Table {
    public function __construct() {
        parent::__construct([
            'singular' => 'variation',
            'plural' => 'variations',
            'ajax' => true
        ]);
    }

    public function get_columns() {
        return [
            'cb' => '<input type="checkbox" />',
            'product_id' => __('Product ID', 'intersoccer-product-variations'),
            'variation_id' => __('Variation ID', 'intersoccer-product-variations'),
            'type' => __('Type', 'intersoccer-product-variations'),
            'attributes' => __('Attributes', 'intersoccer-product-variations'),
            'status' => __('Health Status', 'intersoccer-product-variations')
        ];
    }

    public function column_cb($item) {
        return sprintf('<input type="checkbox" name="variation_ids[]" value="%s" />', esc_attr($item['variation_id']));
    }

    public function get_bulk_actions() {
        return [
            'refresh' => __('Refresh Attributes', 'intersoccer-product-variations'),
        ];
    }

    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'product_id':
                return sprintf('<a href="%s">%s</a>', get_edit_post_link($item['product_id']), esc_html($item['product_id']));
            case 'variation_id':
                return $item['variation_id'] ? sprintf('<a href="%s">%s</a>', get_edit_post_link($item['variation_id']), esc_html($item['variation_id'])) : '-';
            case 'type':
                return esc_html(ucfirst($item['type']));
            case 'attributes':
                $attr_list = [];
                foreach ($item['attributes'] as $key => $value) {
                    if ($value instanceof WC_Product_Attribute) {
                        $terms = $value->get_terms();
                        $value = $terms ? implode(', ', wp_list_pluck($terms, 'name')) : __('None', 'intersoccer-product-variations');
                    } elseif (is_array($value)) {
                        $value = implode(', ', $value);
                    }
                    $attr_list[] = esc_html($key . ': ' . $value);
                }
                return implode(', ', $attr_list) ?: __('No attributes', 'intersoccer-product-variations');
            case 'status':
                $status = $item['is_healthy'] ? 'Healthy' : 'Unhealthy';
                $color = $item['is_healthy'] ? 'green' : 'red';
                $missing = empty($item['missing']) ? '' : ' (Missing: ' . implode(', ', $item['missing']) . ')';
                return '<span style="color: ' . $color . ';">' . esc_html($status . $missing) . '</span>';
            default:
                return '';
        }
    }

    public function prepare_items() {
        $this->_column_headers = [$this->get_columns(), [], []];
        $per_page = 20;
        $current_page = $this->get_pagenum();
        $show_unhealthy_only = isset($_GET['show_unhealthy']) && $_GET['show_unhealthy'] === '1';
        $data = $this->get_variation_data($show_unhealthy_only);

        $total_items = count($data);
        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page' => $per_page
        ]);

        $this->items = array_slice($data, ($current_page - 1) * $per_page, $per_page);
    }

    /**
     * Get variation data with health check.
     *
     * @param bool $unhealthy_only Show only unhealthy variations.
     * @return array Variation data.
     */
    private function get_variation_data($unhealthy_only = false) {
        $products = wc_get_products(['limit' => -1, 'type' => ['variable', 'variation'], 'status' => ['publish', 'draft']]);
        $data = [];
        $required_attrs = [
            'camp' => ['pa_booking-type', 'pa_age-group'], // pa_days-of-week checked on parent
            'course' => ['pa_course-day', '_course_start_date', '_course_total_weeks', '_course_holiday_dates'],
            'birthday' => [] // Add if needed
        ];

        foreach ($products as $product) {
            $product_id = $product->get_id();
            $type = InterSoccer_Product_Types::get_product_type($product_id);
            $attributes = [];

            if ($product instanceof WC_Product_Variation) {
                $variation_id = $product_id;
                $parent_id = $product->get_parent_id();
                $parent_product = wc_get_product($parent_id);
                $attributes = $product->get_attributes();
                $attributes['_course_start_date'] = get_post_meta($variation_id, '_course_start_date', true);
                $attributes['_course_total_weeks'] = get_post_meta($variation_id, '_course_total_weeks', true);
                $attributes['_course_holiday_dates'] = get_post_meta($variation_id, '_course_holiday_dates', true);

                $missing = [];
                if (isset($required_attrs[$type])) {
                    foreach ($required_attrs[$type] as $req_attr) {
                        if (empty($attributes[$req_attr]) && !($req_attr === '_course_holiday_dates' && empty($attributes[$req_attr]))) {
                            $missing[] = $req_attr;
                        }
                    }
                }

                // Check pa_days-of-week on parent for camps
                if ($type === 'camp' && $parent_product) {
                    $parent_attributes = $parent_product->get_attributes();
                    if (empty($parent_attributes['pa_days-of-week'])) {
                        $missing[] = 'pa_days-of-week (parent)';
                        error_log('InterSoccer: Parent product ' . $parent_id . ' missing pa_days-of-week for camp variation ' . $variation_id);
                    } else {
                        error_log('InterSoccer: Parent product ' . $parent_id . ' has pa_days-of-week for camp variation ' . $variation_id);
                    }
                }

                $is_healthy = empty($missing);
                if ($unhealthy_only && $is_healthy) {
                    continue;
                }

                $data[] = [
                    'product_id' => $parent_id,
                    'variation_id' => $variation_id,
                    'type' => $type ?: 'Unknown',
                    'attributes' => $attributes,
                    'missing' => $missing,
                    'is_healthy' => $is_healthy
                ];
            } else {
                $parent_id = $product_id;
                $variations = $product->get_type() === 'variable' ? $product->get_children() : [];
                foreach ($variations as $var_id) {
                    $var_product = wc_get_product($var_id);
                    if (!$var_product) {
                        continue;
                    }
                    $var_attributes = $var_product->get_attributes();
                    $var_attributes['_course_start_date'] = get_post_meta($var_id, '_course_start_date', true);
                    $var_attributes['_course_total_weeks'] = get_post_meta($var_id, '_course_total_weeks', true);
                    $var_attributes['_course_holiday_dates'] = get_post_meta($var_id, '_course_holiday_dates', true);

                    $missing = [];
                    if (isset($required_attrs[$type])) {
                        foreach ($required_attrs[$type] as $req_attr) {
                            if (empty($var_attributes[$req_attr]) && !($req_attr === '_course_holiday_dates' && empty($var_attributes[$req_attr]))) {
                                $missing[] = $req_attr;
                            }
                        }
                    }

                    // Check pa_days-of-week on parent for camps
                    if ($type === 'camp') {
                        $parent_attributes = $product->get_attributes();
                        if (empty($parent_attributes['pa_days-of-week'])) {
                            $missing[] = 'pa_days-of-week (parent)';
                            error_log('InterSoccer: Parent product ' . $parent_id . ' missing pa_days-of-week for camp variation ' . $var_id);
                        } else {
                            error_log('InterSoccer: Parent product ' . $parent_id . ' has pa_days-of-week for camp variation ' . $var_id);
                        }
                    }

                    $is_healthy = empty($missing);
                    if ($unhealthy_only && $is_healthy) {
                        continue;
                    }

                    $data[] = [
                        'product_id' => $parent_id,
                        'variation_id' => $var_id,
                        'type' => $type ?: 'Unknown',
                        'attributes' => $var_attributes,
                        'missing' => $missing,
                        'is_healthy' => $is_healthy
                    ];
                }
            }
        }

        error_log('InterSoccer: Prepared ' . count($data) . ' variations for health check, unhealthy_only=' . ($unhealthy_only ? 'true' : 'false'));
        return $data;
    }
}

/**
 * Render the Variation Health Checker page.
 */
function intersoccer_render_variation_health_page() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die(__('You do not have permission to access this page.', 'intersoccer-product-variations'));
    }

    // Handle recalc form submission
    if (isset($_POST['intersoccer_recalc_end_dates']) && check_admin_referer('intersoccer_recalc_nonce')) {
        intersoccer_run_course_end_date_update_callback();
        echo '<div class="notice notice-success is-dismissible"><p>' . __('Course end dates recalculated successfully.', 'intersoccer-product-variations') . '</p></div>';
    }

    $show_unhealthy = isset($_GET['show_unhealthy']) && $_GET['show_unhealthy'] == 1;
        if ($show_unhealthy) {
            // Query and display only unhealthy variations (add your logic here)
            echo '<p>Showing unhealthy variations only.</p>';
        } else {
            // Show all
            echo '<p>Showing all variations.</p>';
        }
    $table = new InterSoccer_Variation_Health_Table();
    $table->prepare_items();
    ?>
    <div class="wrap">
        <h1><?php _e('InterSoccer Variation Health Dashboard', 'intersoccer-product-variations'); ?></h1>
        <p><?php _e('Use this dashboard to check and fix variation issues, such as recalculating course end dates.', 'intersoccer-product-variations'); ?></p>

        <form method="post" action="">
            <?php wp_nonce_field('intersoccer_recalc_nonce'); ?>
            <input type="submit" name="intersoccer_recalc_end_dates" class="button button-primary" value="<?php _e('Recalculate Course End Dates', 'intersoccer-product-variations'); ?>">
        </form>

        <?php
        // Include course holiday fix functionality
        require_once plugin_dir_path(dirname(dirname(dirname(__FILE__)))) . 'fix-course-holidays.php';
        $fix_completed = intersoccer_course_holiday_fix_has_run();
        ?>
        <div style="margin: 20px 0; padding: 15px; background: #fff; border: 1px solid #ddd; border-radius: 4px;">
            <h3><?php _e('Course Holiday Fix (One-time)', 'intersoccer-product-variations'); ?></h3>
            <p><?php _e('Fix existing courses that were created with inflated session counts to work around the old holiday calculation bug.', 'intersoccer-product-variations'); ?></p>
            <?php if ($fix_completed): ?>
                <p style="color: #28a745;"><strong>✅ <?php _e('Course holiday fix has been completed.', 'intersoccer-product-variations'); ?></strong></p>
                <button type="button" class="button button-secondary" disabled><?php _e('Fix Already Completed', 'intersoccer-product-variations'); ?></button>
            <?php else: ?>
                <p style="color: #856404;"><strong>⚠️ <?php _e('This should only be run once after deploying the fixed course logic.', 'intersoccer-product-variations'); ?></strong></p>
                <button type="button" id="intersoccer-run-course-holiday-fix" class="button button-warning">
                    <?php _e('Run Course Holiday Fix', 'intersoccer-product-variations'); ?>
                </button>
                <div id="course-holiday-fix-results" style="margin-top: 10px; display: none;"></div>
            <?php endif; ?>
        </div>
        <h1><?php _e('Variation Health Checker', 'intersoccer-product-variations'); ?></h1>
        <p><?php _e('Scan and check health of product variations. Use the filter to show only unhealthy ones.', 'intersoccer-product-variations'); ?></p>

        <form method="get" action="<?php echo menu_page_url('intersoccer-variation-health', false); ?>">
            <input type="hidden" name="post_type" value="product" />
            <input type="hidden" name="page" value="intersoccer-variation-health" />
            <label>
                <input type="checkbox" name="show_unhealthy" value="1" <?php checked(isset($_GET['show_unhealthy']), 1); ?> />
                <?php _e('Show unhealthy variations only', 'intersoccer-product-variations'); ?>
            </label>
            <button type="submit" class="button"><?php _e('Filter', 'intersoccer-product-variations'); ?></button>
        </form>
        <form id="intersoccer-variation-health-form" method="post">
            <?php $table->display(); ?>
            <?php wp_nonce_field('intersoccer_variation_health_nonce', 'intersoccer_variation_health_nonce'); ?>
        </form>
    </div>
    <script>
        jQuery(document).ready(function($) {
            $('#intersoccer-variation-health-form').on('submit', function(e) {
                e.preventDefault();
                var action = $('#bulk-action-selector-top').val() || $('#bulk-action-selector-bottom').val();
                var variationIds = $('input[name="variation_ids[]"]:checked').map(function() {
                    return $(this).val();
                }).get();

                if (!action || variationIds.length === 0) {
                    alert('<?php _e('Please select an action and at least one variation.', 'intersoccer-product-variations'); ?>');
                    return;
                }

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'intersoccer_refresh_variation_attributes',
                        nonce: $('#intersoccer_variation_health_nonce').val(),
                        bulk_action: action,
                        variation_ids: variationIds
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('<?php _e('Attributes refreshed successfully!', 'intersoccer-product-variations'); ?>');
                            window.location.reload();
                        } else {
                            alert('<?php _e('Error: ', 'intersoccer-product-variations'); ?>' + (response.data.message || 'Unknown error'));
                        }
                    },
                    error: function(xhr, status, error) {
                        alert('<?php _e('An error occurred while refreshing attributes: ', 'intersoccer-product-variations'); ?>' + error);
                    }
                });
            });

            // Course Holiday Fix functionality
            $('#intersoccer-run-course-holiday-fix').on('click', function(e) {
                e.preventDefault();

                if (!confirm('<?php _e('Are you sure you want to run the course holiday fix? This will modify course data and should only be run once. Make sure you have a backup!', 'intersoccer-product-variations'); ?>')) {
                    return;
                }

                var $button = $(this);
                var $results = $('#course-holiday-fix-results');

                $button.prop('disabled', true).text('<?php _e('Running...', 'intersoccer-product-variations'); ?>');
                $results.show().html('<p><?php _e('Running course holiday fix...', 'intersoccer-product-variations'); ?></p>');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'intersoccer_run_course_holiday_fix',
                        nonce: '<?php echo wp_create_nonce('intersoccer_course_holiday_fix_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $results.html('<div class="notice notice-success"><p>' + response.data.message + '</p><div style="margin-top: 10px;">' + response.data.output + '</div></div>');
                            // Reload page after 3 seconds to show the disabled state
                            setTimeout(function() {
                                window.location.reload();
                            }, 3000);
                        } else {
                            $results.html('<div class="notice notice-error"><p><?php _e('Error:', 'intersoccer-product-variations'); ?> ' + (response.data.message || 'Unknown error') + '</p></div>');
                            $button.prop('disabled', false).text('<?php _e('Run Course Holiday Fix', 'intersoccer-product-variations'); ?>');
                        }
                    },
                    error: function(xhr, status, error) {
                        $results.html('<div class="notice notice-error"><p><?php _e('An error occurred:', 'intersoccer-product-variations'); ?> ' + error + '</p></div>');
                        $button.prop('disabled', false).text('<?php _e('Run Course Holiday Fix', 'intersoccer-product-variations'); ?>');
                    }
                });
            });
        });
    </script>
    <?php
    error_log('InterSoccer: Rendered Variation Health Checker page');
}

/**
 * AJAX handler to refresh variation attributes.
 */
add_action('wp_ajax_intersoccer_refresh_variation_attributes', 'intersoccer_refresh_variation_attributes_callback');
function intersoccer_refresh_variation_attributes_callback() {
    check_ajax_referer('intersoccer_variation_health_nonce', 'nonce');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => __('You do not have permission to perform this action.', 'intersoccer-product-variations')]);
        wp_die();
    }

    $variation_ids = isset($_POST['variation_ids']) && is_array($_POST['variation_ids']) ? array_map('intval', $_POST['variation_ids']) : [];
    $action = isset($_POST['bulk_action']) ? sanitize_text_field($_POST['bulk_action']) : '';

    if ($action !== 'refresh' || empty($variation_ids)) {
        wp_send_json_error(['message' => __('Invalid action or no variations selected.', 'intersoccer-product-variations')]);
        wp_die();
    }

    foreach ($variation_ids as $variation_id) {
        $product = wc_get_product($variation_id);
        if (!$product || !($product instanceof WC_Product_Variation)) {
            error_log('InterSoccer: Invalid variation ID ' . $variation_id);
            continue;
        }

        $parent_id = $product->get_parent_id();
        $type = InterSoccer_Product_Types::get_product_type($parent_id);
        $parent_product = wc_get_product($parent_id);

        $required_attrs = [
            'camp' => ['pa_booking-type' => 'full-week', 'pa_age-group' => '5-13y (Full Day)'],
            'course' => ['pa_course-day' => 'Monday', '_course_start_date' => date('Y-m-d'), '_course_total_weeks' => '16', '_course_holiday_dates' => '']
        ];

        if (isset($required_attrs[$type])) {
            $attributes = $product->get_attributes();
            foreach ($required_attrs[$type] as $key => $default) {
                if (empty($attributes[$key])) {
                    if (strpos($key, 'pa_') === 0) {
                        $taxonomy = $key;
                        $term = get_term_by('slug', $default, $taxonomy);
                        if ($term) {
                            wp_set_object_terms($variation_id, $term->term_id, $taxonomy);
                            error_log('InterSoccer: Set default attribute ' . $key . ' to ' . $default . ' for variation ' . $variation_id);
                        }
                    } else {
                        update_post_meta($variation_id, $key, $default);
                        error_log('InterSoccer: Set default meta ' . $key . ' to ' . $default . ' for variation ' . $variation_id);
                    }
                }
            }

            // Ensure pa_days-of-week on parent for camps
            if ($type === 'camp' && $parent_product) {
                $parent_attributes = $parent_product->get_attributes();
                if (empty($parent_attributes['pa_days-of-week'])) {
                    $default_days = 'Monday,Tuesday,Wednesday,Thursday,Friday';
                    $term = get_term_by('slug', $default_days, 'pa_days-of-week');
                    if ($term) {
                        wp_set_object_terms($parent_id, $term->term_id, 'pa_days-of-week');
                        error_log('InterSoccer: Set default pa_days-of-week to ' . $default_days . ' for parent product ' . $parent_id);
                    }
                }
            }
        }
    }

    wp_send_json_success(['message' => __('Attributes refreshed.', 'intersoccer-product-variations')]);
    wp_die();
}

/**
 * AJAX handler for exporting order analysis to CSV
 */
add_action('wp_ajax_intersoccer_export_order_analysis', 'intersoccer_export_order_analysis_callback');
function intersoccer_export_order_analysis_callback() {
    check_ajax_referer('intersoccer_update_orders_nonce', 'nonce');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => __('You do not have permission to perform this action.', 'intersoccer-product-variations')]);
        return;
    }

    $statuses = isset($_POST['order_statuses']) ? array_map('sanitize_text_field', $_POST['order_statuses']) : ['processing', 'completed'];
    $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 100;

    $orders = wc_get_orders([
        'status' => $statuses,
        'type' => 'shop_order',
        'limit' => $limit,
        'orderby' => 'date',
        'order' => 'DESC'
    ]);

    $table = new InterSoccer_Order_Preview_Table();
    $analysis_data = $table->analyze_orders($orders);

    // Generate CSV content
    $csv_data = [];
    $csv_data[] = [
        'Order ID',
        'Customer Name', 
        'Customer Email',
        'Order Date',
        'Total Items',
        'Product Types',
        'Missing Metadata Count',
        'Missing Keys',
        'Proposed Updates Count',
        'Risk Level',
        'Risk Reasons'
    ];

    foreach ($analysis_data as $order) {
        $missing_count = 0;
        $missing_keys = [];
        $proposed_count = 0;
        
        foreach ($order['missing_keys'] as $item_missing) {
            $missing_count += count($item_missing);
            $missing_keys = array_merge($missing_keys, $item_missing);
        }
        
        foreach ($order['proposed_updates'] as $item_proposed) {
            $proposed_count += count($item_proposed);
        }

        $csv_data[] = [
            $order['order_id'],
            $order['customer_name'],
            $order['customer_email'],
            $order['order_date'],
            $order['total_items'],
            implode('; ', $order['product_types']),
            $missing_count,
            implode('; ', array_unique($missing_keys)),
            $proposed_count,
            $order['risk_level'],
            implode('; ', $order['risk_reasons'])
        ];
    }

    $filename = 'intersoccer-order-analysis-' . date('Y-m-d-H-i-s') . '.csv';
    
    // Set headers for CSV download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    // Output CSV
    $output = fopen('php://output', 'w');
    foreach ($csv_data as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
    
    exit;
}

/**
 * AJAX handler for batch order updates with progress tracking
 */
add_action('wp_ajax_intersoccer_batch_update_orders', 'intersoccer_batch_update_orders_callback');
function intersoccer_batch_update_orders_callback() {
    error_log('InterSoccer: AJAX batch update called - checking permissions and nonce');
    
    // Check user permissions first
    if (!current_user_can('manage_woocommerce')) {
        error_log('InterSoccer: User ' . get_current_user_id() . ' lacks manage_woocommerce capability');
        wp_send_json_error(['message' => 'You do not have permission to perform this action.']);
        return;
    }
    
    // Check nonce - use the same nonce action as your form
    if (!check_ajax_referer('intersoccer_update_orders', 'nonce', false)) {
        error_log('InterSoccer: Nonce verification failed');
        wp_send_json_error(['message' => 'Security check failed. Please refresh the page and try again.']);
        return;
    }
    
    error_log('InterSoccer: Permissions and nonce verified successfully');

    $order_ids = isset($_POST['order_ids']) && is_array($_POST['order_ids']) ? array_map('intval', $_POST['order_ids']) : [];
    $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 5;
    $start_index = isset($_POST['start_index']) ? intval($_POST['start_index']) : 0;
    
    if (empty($order_ids)) {
        wp_send_json_error(['message' => 'No orders provided.']);
        return;
    }

    $total_orders = count($order_ids);
    $batch_orders = array_slice($order_ids, $start_index, $batch_size);
    $updated_count = 0;
    $errors = [];
    $details = [];

    foreach ($batch_orders as $order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            $errors[] = ['order_id' => $order_id, 'error' => 'Order not found'];
            continue;
        }

        $before_count = 0;
        $after_count = 0;
        
        // Count metadata before update
        foreach ($order->get_items() as $item) {
            $before_count += count($item->get_meta_data());
        }

        try {
            $success = intersoccer_update_order_metadata($order);
            
            // Count metadata after update
            foreach ($order->get_items() as $item) {
                $after_count += count($item->get_meta_data());
            }

            if ($success) {
                $updated_count++;
                $details[] = [
                    'order_id' => $order_id,
                    'status' => 'success',
                    'metadata_added' => $after_count - $before_count,
                    'customer' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name()
                ];
            } else {
                $errors[] = [
                    'order_id' => $order_id, 
                    'error' => 'No updates needed',
                    'customer' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name()
                ];
            }
        } catch (Exception $e) {
            $errors[] = [
                'order_id' => $order_id, 
                'error' => 'Exception: ' . $e->getMessage(),
                'customer' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name()
            ];
            error_log('InterSoccer: Exception updating order ' . $order_id . ': ' . $e->getMessage());
        }
    }

    $progress = round((($start_index + count($batch_orders)) / $total_orders) * 100, 1);
    $is_complete = ($start_index + count($batch_orders)) >= $total_orders;

    wp_send_json_success([
        'progress' => $progress,
        'is_complete' => $is_complete,
        'updated_count' => $updated_count,
        'errors' => $errors,
        'details' => $details,
        'next_index' => $start_index + $batch_size,
        'processed_in_batch' => count($batch_orders),
        'total_orders' => $total_orders
    ]);
}

function intersoccer_ajax_scripts() {
    $current_screen = get_current_screen();
    $page_hooks = [
        'woocommerce_page_intersoccer-update-orders',
        'woocommerce_page_intersoccer-enhanced-update-orders'
    ];
    
    if (!$current_screen || !in_array($current_screen->id, $page_hooks)) {
        return;
    }
    ?>
    <script>
    jQuery(document).ready(function($) {
        console.log('InterSoccer: Fixed AJAX scripts loaded');
        
        // Add test button to verify AJAX works
        if ($('#test-ajax-btn').length === 0) {
            $('h1').after('<button type="button" id="test-ajax-btn" class="button button-secondary" style="margin-left: 10px;">Test AJAX</button>');
        }
        
        $('#test-ajax-btn').on('click', function() {
            console.log('Testing AJAX...');
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'intersoccer_test_ajax'
                },
                success: function(response) {
                    console.log('AJAX test successful:', response);
                    alert('AJAX connection working!');
                },
                error: function(xhr, status, error) {
                    console.error('AJAX test failed:', xhr, status, error);
                    alert('AJAX test failed: ' + error);
                }
            });
        });

        // Enhanced batch processing function
        function processBatchUpdate(orderIds, startIndex = 0, batchSize = 3) {
            console.log('InterSoccer: Starting batch update', {
                orderIds: orderIds.slice(startIndex, startIndex + batchSize),
                startIndex: startIndex,
                total: orderIds.length
            });
            
            return $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'intersoccer_batch_update_orders',
                    nonce: $('input[name="intersoccer_update_orders_nonce"]').val(),
                    order_ids: orderIds,
                    start_index: startIndex,
                    batch_size: batchSize
                },
                timeout: 60000, // 60 second timeout
                beforeSend: function() {
                    console.log('InterSoccer: Sending batch request...');
                }
            });
        }

        // Override existing form submission or add new handler
        function handleBulkUpdate() {
            console.log('InterSoccer: Bulk update initiated');
            
            let selectedOrders = [];
            
            // Try multiple selector patterns for checkboxes
            const checkboxSelectors = [
                'input[name="order_ids[]"]:checked',
                'input[name="selected_order_ids"]:checked',
                '.order-checkbox:checked'
            ];
            
            for (let selector of checkboxSelectors) {
                selectedOrders = $(selector).map(function() {
                    return parseInt($(this).val());
                }).get();
                
                if (selectedOrders.length > 0) {
                    console.log('Found selected orders with selector:', selector, selectedOrders);
                    break;
                }
            }
            
            if (selectedOrders.length === 0) {
                alert('Please select at least one order to update.');
                return false;
            }

            // Show progress
            if ($('#update-progress').length === 0) {
                $('h1').after(`
                    <div id="update-progress" style="margin: 20px 0; padding: 15px; background: #fff; border: 1px solid #ccd0d4;">
                        <h3>Update Progress</h3>
                        <div style="background: #f1f1f1; border-radius: 10px; padding: 3px;">
                            <div id="progress-bar" style="background: #4CAF50; height: 20px; border-radius: 8px; width: 0%; transition: width 0.3s;"></div>
                        </div>
                        <p id="progress-text">Starting...</p>
                    </div>
                `);
            }
            
            $('#update-progress').show();

            let processedOrders = 0;
            let successCount = 0;
            let errorCount = 0;
            let totalOrders = selectedOrders.length;

            function processBatch(startIndex) {
                processBatchUpdate(selectedOrders, startIndex, 3)
                    .done(function(response) {
                        console.log('Batch response:', response);
                        
                        if (response.success) {
                            processedOrders += response.data.processed_in_batch;
                            successCount += response.data.updated_count;
                            errorCount += response.data.errors.length;

                            let progress = response.data.progress;
                            $('#progress-bar').css('width', progress + '%');
                            $('#progress-text').text(`Processed ${processedOrders} of ${totalOrders} orders (${successCount} updated, ${errorCount} errors)`);

                            if (response.data.is_complete) {
                                $('#progress-text').html(`
                                    <strong>Complete!</strong> Updated ${successCount} orders, ${errorCount} errors.
                                    <button type="button" onclick="window.location.reload()" class="button button-primary">Reload Page</button>
                                `);
                            } else {
                                setTimeout(() => processBatch(response.data.next_index), 1000);
                            }
                        } else {
                            $('#progress-text').html(`<span style="color: red;">Error: ${response.data?.message || 'Unknown error'}</span>`);
                        }
                    })
                    .fail(function(xhr, status, error) {
                        console.error('Batch failed:', xhr, status, error);
                        $('#progress-text').html(`<span style="color: red;">Network error: ${error} (${xhr.status})</span>`);
                    });
            }

            processBatch(0);
        }

        // Attach to existing buttons or forms
        $('body').on('click', 'input[name="update_selected_orders"], #update-selected-btn, button[name="update_orders"]', function(e) {
            e.preventDefault();
            handleBulkUpdate();
        });

        // Also handle form submissions
        $('body').on('submit', '#bulk-update-form, #update-orders-form', function(e) {
            e.preventDefault();
            handleBulkUpdate();
        });

        console.log('InterSoccer: Enhanced event handlers attached');
    });
    </script>
    <?php
}

// Remove
// add_action('wp_ajax_test_preview_analysis', 'test_preview_analysis_callback');
// function test_preview_analysis_callback() {
//     if (!current_user_can('manage_woocommerce')) {
//         wp_die('Unauthorized');
//     }
    
//     $order_id = intval($_GET['order_id'] ?? 34934);
//     $order = wc_get_order($order_id);
    
//     if (!$order) {
//         echo "Order $order_id not found";
//         wp_die();
//     }
    
//     echo "<h3>Preview Analysis Test for Order $order_id</h3>";
    
//     $order_data = [
//         'order_id' => $order_id,
//         'missing_keys' => [],
//         'proposed_updates' => []
//     ];
    
//     foreach ($order->get_items() as $item_id => $item) {
//         $product_id = $item->get_product_id();
//         $product_type = InterSoccer_Product_Types::get_product_type($product_id);
        
//         if (!in_array($product_type, ['camp', 'course', 'birthday'])) {
//             continue;
//         }
        
//         // Use the same analysis as the working deep debug
//         $user_id = $order->get_customer_id();
//         $variation_id = $item->get_variation_id();
        
//         $existing_meta = $item->get_meta_data();
//         $existing_keys = array_map(function($meta) { return $meta->key; }, $existing_meta);
        
//         $assigned_player = in_array('assigned_player', $existing_keys) ? $item->get_meta('assigned_player', true) : 0;
//         $player_details = intersoccer_get_player_details($user_id, $assigned_player);
        
//         $potential_updates = [
//             'Assigned Attendee' => isset($player_details['name']) ? $player_details['name'] : null,
//             'Attendee DOB' => isset($player_details['dob']) ? $player_details['dob'] : null,
//             'Attendee Gender' => isset($player_details['gender']) ? $player_details['gender'] : null,
//             'Medical Conditions' => isset($player_details['medical_conditions']) ? 
//                 (!empty($player_details['medical_conditions']) ? $player_details['medical_conditions'] : 'None') : null,
//             'Activity Type' => ucfirst($product_type ?: 'Unknown'),
//             'Season' => intersoccer_get_product_season($product_id),
//             'Variation ID' => $variation_id
//         ];
        
//         $attributes = intersoccer_get_parent_product_attributes($product_id, $variation_id);
//         $potential_updates = array_merge($potential_updates, $attributes);
        
//         $missing = [];
//         $proposed = [];
        
//         foreach ($potential_updates as $key => $value) {
//             if ($value === null || ($value === '' && $key !== 'Medical Conditions')) {
//                 continue;
//             }
            
//             if (!in_array($key, $existing_keys)) {
//                 $missing[] = $key;
//                 $proposed[$key] = $value;
//             }
//         }
        
//         if (!empty($missing)) {
//             $order_data['missing_keys'][$item_id] = $missing;
//         }
        
//         if (!empty($proposed)) {
//             $order_data['proposed_updates'][$item_id] = $proposed;
//         }
        
//         echo "<h4>Item $item_id (Product: $product_id, Type: $product_type)</h4>";
//         echo "<p><strong>Existing keys:</strong> " . implode(', ', $existing_keys) . "</p>";
//         echo "<p><strong>Missing keys:</strong> " . implode(', ', $missing) . "</p>";
//         echo "<p><strong>Proposed updates:</strong></p><ul>";
//         foreach ($proposed as $key => $value) {
//             echo "<li>$key: $value</li>";
//         }
//         echo "</ul>";
//     }
    
//     if (empty($order_data['missing_keys'])) {
//         echo "<p style='color: green;'><strong>No missing metadata found - order should NOT appear in preview</strong></p>";
//     } else {
//         echo "<p style='color: red;'><strong>Missing metadata found - order SHOULD appear in preview</strong></p>";
//     }
    
//     wp_die();
// }

// Enhanced AJAX handler for automated batch processing
add_action('wp_ajax_intersoccer_automated_batch_update', 'intersoccer_automated_batch_update_callback');
function intersoccer_automated_batch_update_callback() {
    // Extend time limits for batch processing
    set_time_limit(60); // 60 seconds per batch
    ini_set('memory_limit', '512M'); // Increase memory if needed
    
    error_log('InterSoccer: Automated batch update started');
    
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
        return;
    }
    
    if (!check_ajax_referer('intersoccer_update_orders', 'nonce', false)) {
        wp_send_json_error(['message' => 'Security check failed']);
        return;
    }

    // Get parameters
    $batch_index = intval($_POST['batch_index'] ?? 0);
    $batch_size = intval($_POST['batch_size'] ?? 15); // Process 15 orders per batch
    $order_statuses = isset($_POST['order_statuses']) ? array_map('sanitize_text_field', $_POST['order_statuses']) : ['processing', 'completed'];
    $scan_limit = intval($_POST['scan_limit'] ?? 1000);
    
    // Initialize or continue processing
    if ($batch_index === 0) {
        error_log('InterSoccer: Starting new batch processing session');
        // Get all orders that need updating
        $orders_to_process = intersoccer_get_orders_needing_updates($order_statuses, $scan_limit);
        
        // Store in transient for subsequent batches
        set_transient('intersoccer_batch_orders_' . get_current_user_id(), $orders_to_process, 3600); // 1 hour expiry
        
        wp_send_json_success([
            'phase' => 'initialized',
            'total_orders' => count($orders_to_process),
            'message' => 'Found ' . count($orders_to_process) . ' orders that need updates. Starting processing...'
        ]);
        return;
    }
    
    // Continue processing from stored list
    $orders_to_process = get_transient('intersoccer_batch_orders_' . get_current_user_id());
    if (!$orders_to_process) {
        wp_send_json_error(['message' => 'Batch processing session expired. Please restart.']);
        return;
    }
    
    $total_orders = count($orders_to_process);
    $start_index = ($batch_index - 1) * $batch_size;
    $batch_orders = array_slice($orders_to_process, $start_index, $batch_size);
    
    if (empty($batch_orders)) {
        // Processing complete
        delete_transient('intersoccer_batch_orders_' . get_current_user_id());
        wp_send_json_success([
            'phase' => 'complete',
            'message' => 'All orders processed successfully!'
        ]);
        return;
    }
    
    // Process this batch
    $results = [];
    $success_count = 0;
    $error_count = 0;
    
    foreach ($batch_orders as $order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            $results[] = ['order_id' => $order_id, 'status' => 'error', 'message' => 'Order not found'];
            $error_count++;
            continue;
        }
        
        try {
            $updated = intersoccer_update_order_metadata($order);
            if ($updated) {
                $results[] = ['order_id' => $order_id, 'status' => 'success', 'message' => 'Updated'];
                $success_count++;
            } else {
                $results[] = ['order_id' => $order_id, 'status' => 'skipped', 'message' => 'No updates needed'];
            }
        } catch (Exception $e) {
            $results[] = ['order_id' => $order_id, 'status' => 'error', 'message' => $e->getMessage()];
            $error_count++;
            error_log('InterSoccer: Error processing order ' . $order_id . ': ' . $e->getMessage());
        }
    }
    
    $processed_count = $start_index + count($batch_orders);
    $progress = round(($processed_count / $total_orders) * 100, 1);
    
    error_log("InterSoccer: Processed batch {$batch_index}, orders {$start_index}-" . ($start_index + count($batch_orders)) . " of {$total_orders}");
    
    wp_send_json_success([
        'phase' => 'processing',
        'batch_index' => $batch_index,
        'processed_count' => $processed_count,
        'total_orders' => $total_orders,
        'progress' => $progress,
        'batch_results' => $results,
        'success_count' => $success_count,
        'error_count' => $error_count,
        'next_batch_index' => $batch_index + 1,
        'is_complete' => $processed_count >= $total_orders
    ]);
}

// Function to get all orders that need updates (efficient version)
function intersoccer_get_orders_needing_updates($statuses, $limit = 1000) {
    error_log('InterSoccer: Scanning for orders needing updates, limit: ' . $limit);
    
    $orders = wc_get_orders([
        'status' => $statuses,
        'type' => 'shop_order',
        'limit' => $limit,
        'orderby' => 'date',
        'order' => 'DESC'
    ]);
    
    $orders_needing_updates = [];
    
    foreach ($orders as $order) {
        if (!($order instanceof WC_Order)) continue;
        
        $needs_update = false;
        
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $product_type = InterSoccer_Product_Types::get_product_type($product_id);
            
            if (!in_array($product_type, ['camp', 'course', 'birthday'])) {
                continue;
            }
            
            // Quick check - if any relevant item is missing key metadata, include this order
            $existing_meta = $item->get_meta_data();
            $existing_keys = array_map(function($meta) { return $meta->key; }, $existing_meta);
            
            // Check for common missing fields (quick version)
            $essential_fields = ['Medical Conditions', 'Activity Type', 'Season', 'Attendee DOB', 'Attendee Gender'];
            foreach ($essential_fields as $field) {
                if (!in_array($field, $existing_keys)) {
                    $needs_update = true;
                    break 2; // Break both loops
                }
            }
        }
        
        if ($needs_update) {
            $orders_needing_updates[] = $order->get_id();
        }
    }
    
    error_log('InterSoccer: Found ' . count($orders_needing_updates) . ' orders needing updates out of ' . count($orders) . ' scanned');
    return $orders_needing_updates;
}

// Enhanced UI with automated processing controls
function intersoccer_render_automated_update_orders_page() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die(__('You do not have permission to access this page.', 'intersoccer-product-variations'));
    }
    ?>
    <div class="wrap">
        <h1><?php _e('Automated Order Metadata Update', 'intersoccer-product-variations'); ?></h1>
        <p><?php _e('Automatically find and update orders missing metadata. This tool can process hundreds or thousands of orders efficiently.', 'intersoccer-product-variations'); ?></p>
        
        <!-- Scan Configuration -->
        <div class="intersoccer-config-section" style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0;">
            <h2><?php _e('1. Configure Scan', 'intersoccer-product-variations'); ?></h2>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Order Statuses to Process', 'intersoccer-product-variations'); ?></th>
                    <td>
                        <label><input type="checkbox" id="status-processing" checked> <?php _e('Processing', 'intersoccer-product-variations'); ?></label><br>
                        <label><input type="checkbox" id="status-completed" checked> <?php _e('Completed', 'intersoccer-product-variations'); ?></label><br>
                        <label><input type="checkbox" id="status-onhold"> <?php _e('On Hold', 'intersoccer-product-variations'); ?></label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Orders to Scan', 'intersoccer-product-variations'); ?></th>
                    <td>
                        <select id="scan-limit">
                            <option value="100">100 recent orders</option>
                            <option value="500">500 recent orders</option>
                            <option value="1000" selected>1000 recent orders</option>
                            <option value="2000">2000 recent orders</option>
                            <option value="5000">5000 recent orders</option>
                            <option value="-1">All orders (may take very long)</option>
                        </select>
                        <p class="description"><?php _e('Higher numbers find more orders but take longer to scan initially.', 'intersoccer-product-variations'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Processing Speed', 'intersoccer-product-variations'); ?></th>
                    <td>
                        <select id="batch-size">
                            <option value="10">Conservative (10 orders per batch)</option>
                            <option value="15" selected>Balanced (15 orders per batch)</option>
                            <option value="20">Fast (20 orders per batch)</option>
                            <option value="25">Aggressive (25 orders per batch - may timeout)</option>
                        </select>
                        <p class="description"><?php _e('Conservative is safer for slower servers. Fast processes more orders per batch but may timeout.', 'intersoccer-product-variations'); ?></p>
                    </td>
                </tr>
            </table>
            
            <p>
                <button type="button" id="start-automated-update" class="button button-primary button-large">
                    <?php _e('🚀 Start Automated Update', 'intersoccer-product-variations'); ?>
                </button>
                <button type="button" id="stop-automated-update" class="button button-secondary" style="display: none;">
                    <?php _e('⏹️ Stop Processing', 'intersoccer-product-variations'); ?>
                </button>
            </p>
        </div>
        
        <!-- Progress Section -->
        <div id="progress-section" style="display: none; background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0;">
            <h2><?php _e('2. Processing Progress', 'intersoccer-product-variations'); ?></h2>
            
            <div id="progress-overview" style="margin-bottom: 20px;">
                <div style="display: flex; gap: 30px; margin-bottom: 15px;">
                    <div><strong id="total-orders-count">0</strong> orders to process</div>
                    <div><strong id="processed-count">0</strong> processed</div>
                    <div style="color: green;"><strong id="success-count">0</strong> updated</div>
                    <div style="color: red;"><strong id="error-count">0</strong> errors</div>
                    <div><strong id="estimated-time">--</strong> estimated time remaining</div>
                </div>
            </div>
            
            <div class="progress-container" style="background: #f1f1f1; border-radius: 10px; padding: 3px; margin-bottom: 15px;">
                <div id="main-progress-bar" style="background: linear-gradient(90deg, #4CAF50, #45a049); height: 25px; border-radius: 8px; width: 0%; transition: width 0.3s; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold;"></div>
            </div>
            
            <div id="current-status" style="padding: 10px; background: #f9f9f9; border-radius: 5px; font-family: monospace; height: 200px; overflow-y: auto;">
                <div id="status-log"></div>
            </div>
        </div>
        
        <!-- Results Section -->
        <div id="results-section" style="display: none; background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0;">
            <h2><?php _e('3. Results', 'intersoccer-product-variations'); ?></h2>
            <div id="final-results"></div>
            <p>
                <button type="button" id="download-results" class="button button-secondary">
                    <?php _e('📥 Download Results Log', 'intersoccer-product-variations'); ?>
                </button>
                <button type="button" id="start-new-batch" class="button button-primary">
                    <?php _e('🔄 Process More Orders', 'intersoccer-product-variations'); ?>
                </button>
            </p>
        </div>
    </div>
    
    <script>
    jQuery(document).ready(function($) {
        let processingActive = false;
        let startTime = null;
        let totalOrders = 0;
        let processedOrders = 0;
        let successCount = 0;
        let errorCount = 0;
        let allResults = [];
        
        $('#start-automated-update').on('click', function() {
            if (processingActive) return;
            
            // Get configuration
            let statuses = [];
            if ($('#status-processing').is(':checked')) statuses.push('processing');
            if ($('#status-completed').is(':checked')) statuses.push('completed');
            if ($('#status-onhold').is(':checked')) statuses.push('on-hold');
            
            if (statuses.length === 0) {
                alert('Please select at least one order status to process.');
                return;
            }
            
            let scanLimit = parseInt($('#scan-limit').val());
            let batchSize = parseInt($('#batch-size').val());
            
            if (!confirm(`This will scan up to ${scanLimit === -1 ? 'ALL' : scanLimit} orders and automatically update any missing metadata. This may take several minutes. Continue?`)) {
                return;
            }
            
            startAutomatedProcessing(statuses, scanLimit, batchSize);
        });
        
        $('#stop-automated-update').on('click', function() {
            processingActive = false;
            $(this).hide();
            $('#start-automated-update').show().text('🚀 Start Automated Update');
            addStatusLog('⏹️ Processing stopped by user', 'warning');
        });
        
        $('#start-new-batch').on('click', function() {
            // Reset and allow starting new batch
            $('#progress-section').hide();
            $('#results-section').hide();
            resetCounters();
        });
        
        function startAutomatedProcessing(statuses, scanLimit, batchSize) {
            processingActive = true;
            startTime = Date.now();
            resetCounters();
            
            $('#start-automated-update').hide();
            $('#stop-automated-update').show();
            $('#progress-section').show();
            $('#results-section').hide();
            
            addStatusLog('🔍 Starting automated update process...', 'info');
            addStatusLog(`📋 Configuration: ${statuses.join(', ')} orders, ${scanLimit === -1 ? 'all' : scanLimit} scan limit, ${batchSize} per batch`, 'info');
            
            // Initialize batch processing
            processBatch(0, statuses, scanLimit, batchSize);
        }
        
        function processBatch(batchIndex, statuses, scanLimit, batchSize) {
            if (!processingActive) return;
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'intersoccer_automated_batch_update',
                    nonce: '<?php echo wp_create_nonce('intersoccer_update_orders'); ?>',
                    batch_index: batchIndex,
                    batch_size: batchSize,
                    order_statuses: statuses,
                    scan_limit: scanLimit
                },
                timeout: 120000, // 2 minute timeout per batch
                success: function(response) {
                    if (!response.success) {
                        addStatusLog('❌ Error: ' + (response.data?.message || 'Unknown error'), 'error');
                        stopProcessing();
                        return;
                    }
                    
                    let data = response.data;
                    
                    if (data.phase === 'initialized') {
                        totalOrders = data.total_orders;
                        $('#total-orders-count').text(totalOrders);
                        addStatusLog(`✅ Found ${totalOrders} orders that need updates`, 'success');
                        addStatusLog('🚀 Starting batch processing...', 'info');
                        
                        // Start actual processing
                        setTimeout(() => processBatch(1, statuses, scanLimit, batchSize), 1000);
                        
                    } else if (data.phase === 'processing') {
                        // Update progress
                        processedOrders = data.processed_count;
                        successCount += data.success_count;
                        errorCount += data.error_count;
                        allResults = allResults.concat(data.batch_results);
                        
                        updateProgress(data.progress);
                        updateCounters();
                        
                        addStatusLog(`📦 Batch ${data.batch_index}: Processed ${data.batch_results.length} orders (${data.success_count} updated, ${data.error_count} errors)`, 'info');
                        
                        // Show some individual results
                        data.batch_results.slice(0, 3).forEach(result => {
                            let icon = result.status === 'success' ? '✅' : result.status === 'error' ? '❌' : '⏭️';
                            addStatusLog(`${icon} Order #${result.order_id}: ${result.message}`, result.status);
                        });
                        
                        if (data.is_complete) {
                            completeProcessing();
                        } else {
                            // Continue with next batch
                            setTimeout(() => processBatch(data.next_batch_index, statuses, scanLimit, batchSize), 2000);
                        }
                        
                    } else if (data.phase === 'complete') {
                        completeProcessing();
                    }
                },
                error: function(xhr, status, error) {
                    addStatusLog(`❌ Network error: ${error} (${xhr.status})`, 'error');
                    addStatusLog('🔄 Retrying in 5 seconds...', 'warning');
                    
                    // Retry after 5 seconds
                    setTimeout(() => processBatch(batchIndex, statuses, scanLimit, batchSize), 5000);
                }
            });
        }
        
        function updateProgress(percentage) {
            $('#main-progress-bar').css('width', percentage + '%').text(percentage + '%');
            
            // Update estimated time
            if (startTime && processedOrders > 0) {
                let elapsed = (Date.now() - startTime) / 1000; // seconds
                let rate = processedOrders / elapsed; // orders per second
                let remaining = totalOrders - processedOrders;
                let estimatedSeconds = remaining / rate;
                
                let minutes = Math.floor(estimatedSeconds / 60);
                let seconds = Math.floor(estimatedSeconds % 60);
                $('#estimated-time').text(`${minutes}m ${seconds}s`);
            }
        }
        
        function updateCounters() {
            $('#processed-count').text(processedOrders);
            $('#success-count').text(successCount);
            $('#error-count').text(errorCount);
        }
        
        function resetCounters() {
            totalOrders = processedOrders = successCount = errorCount = 0;
            allResults = [];
            updateCounters();
            $('#total-orders-count').text('0');
            $('#estimated-time').text('--');
            $('#main-progress-bar').css('width', '0%').text('');
            $('#status-log').empty();
        }
        
        function addStatusLog(message, type = 'info') {
            let timestamp = new Date().toLocaleTimeString();
            let color = type === 'error' ? 'red' : type === 'success' ? 'green' : type === 'warning' ? 'orange' : 'black';
            let logEntry = `<div style="color: ${color}; margin-bottom: 2px;">[${timestamp}] ${message}</div>`;
            
            $('#status-log').append(logEntry);
            $('#current-status').scrollTop($('#current-status')[0].scrollHeight);
        }
        
        function completeProcessing() {
            processingActive = false;
            $('#stop-automated-update').hide();
            $('#start-automated-update').show().text('🚀 Start Automated Update');
            
            updateProgress(100);
            addStatusLog('🎉 Processing complete!', 'success');
            
            // Show results
            $('#results-section').show();
            let resultsHtml = `
                <div style="background: #f0f8ff; padding: 15px; border-radius: 5px; margin-bottom: 15px;">
                    <h3>📊 Final Statistics</h3>
                    <div style="display: flex; gap: 30px;">
                        <div><strong>${totalOrders}</strong> total orders scanned</div>
                        <div style="color: green;"><strong>${successCount}</strong> successfully updated</div>
                        <div style="color: red;"><strong>${errorCount}</strong> errors</div>
                        <div><strong>${Math.round((Date.now() - startTime) / 1000)}s</strong> total time</div>
                    </div>
                </div>
            `;
            
            if (errorCount > 0) {
                resultsHtml += '<h4>❌ Orders with Errors:</h4><ul>';
                allResults.filter(r => r.status === 'error').slice(0, 10).forEach(result => {
                    resultsHtml += `<li>Order #${result.order_id}: ${result.message}</li>`;
                });
                if (allResults.filter(r => r.status === 'error').length > 10) {
                    resultsHtml += `<li><em>... and ${allResults.filter(r => r.status === 'error').length - 10} more errors</em></li>`;
                }
                resultsHtml += '</ul>';
            }
            
            $('#final-results').html(resultsHtml);
        }
        
        function stopProcessing() {
            processingActive = false;
            $('#stop-automated-update').hide();
            $('#start-automated-update').show().text('🚀 Start Automated Update');
        }
        
        // Download results functionality
        $('#download-results').on('click', function() {
            let csvContent = "data:text/csv;charset=utf-8,";
            csvContent += "Order ID,Status,Message\n";
            
            allResults.forEach(result => {
                csvContent += `${result.order_id},${result.status},"${result.message}"\n`;
            });
            
            let encodedUri = encodeURI(csvContent);
            let link = document.createElement("a");
            link.setAttribute("href", encodedUri);
            link.setAttribute("download", `intersoccer-batch-results-${new Date().getTime()}.csv`);
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        });
    });
    </script>
    
    <style>
    .intersoccer-config-section {
        border-radius: 5px;
    }
    #current-status {
        font-size: 12px;
        line-height: 1.4;
    }
    .button-large {
        font-size: 16px !important;
        padding: 10px 20px !important;
        height: auto !important;
    }
    </style>
    <?php
}

// Cleanup function to remove expired batch data
add_action('intersoccer_cleanup_batch_data', 'intersoccer_cleanup_expired_batch_data');
function intersoccer_cleanup_expired_batch_data() {
    global $wpdb;
    
    // Clean up expired transients
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_intersoccer_batch_orders_%' AND option_value < UNIX_TIMESTAMP()");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_intersoccer_batch_orders_%' AND option_name NOT IN (SELECT CONCAT('_transient_', SUBSTRING(option_name, 19)) FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_intersoccer_batch_orders_%')");
}

// Schedule cleanup if not already scheduled
if (!wp_next_scheduled('intersoccer_cleanup_batch_data')) {
    wp_schedule_event(time(), 'daily', 'intersoccer_cleanup_batch_data');
}

// Emergency stop function (if needed)
add_action('wp_ajax_intersoccer_emergency_stop', 'intersoccer_emergency_stop_callback');
function intersoccer_emergency_stop_callback() {
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
        return;
    }
    
    // Clear all batch processing transients for this user
    delete_transient('intersoccer_batch_orders_' . get_current_user_id());
    
    wp_send_json_success(['message' => 'Batch processing stopped and cleared.']);
}

?>