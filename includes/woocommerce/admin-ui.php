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
        'woocommerce',
        __('Manage Discounts', 'intersoccer-player-management'),
        __('Discounts', 'intersoccer-player-management'),
        'manage_woocommerce',
        'intersoccer-discounts',
        'intersoccer_render_discounts_page'
    );

    // Update Orders submenu
    add_submenu_page(
        'woocommerce',
        __('Update Order Details', 'intersoccer-player-management'),
        __('Update Order Details', 'intersoccer-player-management'),
        'manage_woocommerce',
        'intersoccer-update-orders',
        'intersoccer_render_update_orders_page'
    );

    // Variation Health Checker submenu
    add_submenu_page(
        'woocommerce',
        __('Variation Health Checker', 'intersoccer-player-management'),
        __('Variation Health', 'intersoccer-player-management'),
        'manage_woocommerce',
        'intersoccer-variation-health',
        'intersoccer_render_variation_health_page'
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
            'name' => __('Name', 'intersoccer-player-management'),
            'type' => __('Type', 'intersoccer-player-management'),
            'condition' => __('Condition', 'intersoccer-player-management'),
            'rate' => __('Discount Rate (%)', 'intersoccer-player-management'),
            'active' => __('Active', 'intersoccer-player-management')
        ];
    }

    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'name':
                return esc_html($item['name']);
            case 'type':
                return esc_html(ucfirst($item['type']));
            case 'condition':
                return esc_html($item['condition'] === 'none' ? __('None', 'intersoccer-player-management') : ucwords(str_replace('_', ' ', $item['condition'])));
            case 'rate':
                return esc_html($item['rate']);
            case 'active':
                return $item['active'] ? __('Yes', 'intersoccer-player-management') : __('No', 'intersoccer-player-management');
            default:
                return '';
        }
    }

    public function column_cb($item) {
        return sprintf('<input type="checkbox" name="discount_ids[]" value="%s" />', esc_attr($item['id']));
    }

    public function get_bulk_actions() {
        return [
            'activate' => __('Activate', 'intersoccer-player-management'),
            'deactivate' => __('Deactivate', 'intersoccer-player-management'),
            'delete' => __('Delete', 'intersoccer-player-management')
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
        wp_die(__('You do not have permission to access this page.', 'intersoccer-player-management'));
    }

    $table = new InterSoccer_Discounts_Table();
    $table->prepare_items();
    ?>
    <div class="wrap">
        <h1><?php _e('Manage Discounts', 'intersoccer-player-management'); ?></h1>
        <p><?php _e('Add, edit, or delete discount rules for Camps, Courses, or other products. Rules apply automatically based on cart conditions (e.g., sibling bookings). For manual coupons, use <a href="' . admin_url('edit.php?post_type=shop_coupon') . '">WooCommerce > Coupons</a>.', 'intersoccer-player-management'); ?></p>
        <form id="intersoccer-discounts-form" method="post">
            <h2><?php _e('Add New Discount', 'intersoccer-player-management'); ?></h2>
            <table class="form-table">
                <tr>
                    <th><label for="discount_name"><?php _e('Name', 'intersoccer-player-management'); ?></label></th>
                    <td><input type="text" id="discount_name" name="discount[name]" class="regular-text" required></td>
                </tr>
                <tr>
                    <th><label for="discount_type"><?php _e('Type', 'intersoccer-player-management'); ?></label></th>
                    <td>
                        <select id="discount_type" name="discount[type]">
                            <option value="general"><?php _e('General', 'intersoccer-player-management'); ?></option>
                            <option value="camp"><?php _e('Camp', 'intersoccer-player-management'); ?></option>
                            <option value="course"><?php _e('Course', 'intersoccer-player-management'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="discount_condition"><?php _e('Condition', 'intersoccer-player-management'); ?></label></th>
                    <td>
                        <select id="discount_condition" name="discount[condition]">
                            <option value="none"><?php _e('None', 'intersoccer-player-management'); ?></option>
                            <option value="2nd_child"><?php _e('2nd Child', 'intersoccer-player-management'); ?></option>
                            <option value="3rd_plus_child"><?php _e('3rd or Additional Child', 'intersoccer-player-management'); ?></option>
                            <option value="same_season_course"><?php _e('Same Season Course (Same Child)', 'intersoccer-player-management'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="discount_rate"><?php _e('Discount Rate (%)', 'intersoccer-player-management'); ?></label></th>
                    <td><input type="number" id="discount_rate" name="discount[rate]" min="0" max="100" step="0.1" required></td>
                </tr>
                <tr>
                    <th><label for="discount_active"><?php _e('Active', 'intersoccer-player-management'); ?></label></th>
                    <td><input type="checkbox" id="discount_active" name="discount[active]" checked></td>
                </tr>
            </table>
            <p><button type="button" id="intersoccer-add-discount" class="button button-primary"><?php _e('Add Discount', 'intersoccer-player-management'); ?></button></p>
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
                $('#intersoccer-discount-status').text('<?php _e('Saving...', 'intersoccer-player-management'); ?>').removeClass('error');
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
                            $('#intersoccer-discount-status').text('<?php _e('Discount saved successfully!', 'intersoccer-player-management'); ?>');
                            window.location.reload();
                        } else {
                            $('#intersoccer-discount-status').text('<?php _e('Error: ', 'intersoccer-player-management'); ?>' + (response.data.message || 'Unknown error')).addClass('error');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('InterSoccer: AJAX error', xhr.responseText, status, error);
                        $('#intersoccer-discount-status').text('<?php _e('An error occurred while saving the discount: ', 'intersoccer-player-management'); ?>' + error).addClass('error');
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
                    alert('<?php _e('Please select an action and at least one discount.', 'intersoccer-player-management'); ?>');
                    return;
                }

                $('#intersoccer-discount-status').text('<?php _e('Processing...', 'intersoccer-player-management'); ?>').removeClass('error');

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
                            $('#intersoccer-discount-status').text('<?php _e('Action completed successfully!', 'intersoccer-player-management'); ?>');
                            window.location.reload();
                        } else {
                            $('#intersoccer-discount-status').text('<?php _e('Error: ', 'intersoccer-player-management'); ?>' + (response.data.message || 'Unknown error')).addClass('error');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('InterSoccer: Bulk AJAX error', xhr.responseText, status, error);
                        $('#intersoccer-discount-status').text('<?php _e('An error occurred while processing the action: ', 'intersoccer-player-management'); ?>' + error).addClass('error');
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
        wp_send_json_error(['message' => __('You do not have permission to perform this action.', 'intersoccer-player-management')]);
        wp_die();
    }
    error_log('InterSoccer: Permission check passed');

    $discount = isset($_POST['discount']) && is_array($_POST['discount']) ? $_POST['discount'] : [];
    if (empty($discount['name']) || empty($discount['rate'])) {
        error_log('InterSoccer: Missing name or rate in discount data: ' . print_r($discount, true));
        wp_send_json_error(['message' => __('Name and discount rate are required.', 'intersoccer-player-management')]);
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

    wp_send_json_success(['message' => __('Discount saved.', 'intersoccer-player-management')]);
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
        wp_send_json_error(['message' => __('You do not have permission to perform this action.', 'intersoccer-player-management')]);
        wp_die();
    }

    $action = isset($_POST['bulk_action']) ? sanitize_text_field($_POST['bulk_action']) : '';
    $discount_ids = isset($_POST['discount_ids']) && is_array($_POST['discount_ids']) ? array_map('sanitize_key', $_POST['discount_ids']) : [];

    if (empty($action) || empty($discount_ids)) {
        wp_send_json_error(['message' => __('Invalid action or no discounts selected.', 'intersoccer-player-management')]);
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
    wp_send_json_success(['message' => __('Action completed.', 'intersoccer-player-management')]);
    wp_die();
}

/**
 * Register admin submenu for Discounts and Messages
 */
add_action('admin_menu', 'intersoccer_add_enhanced_admin_submenus');
function intersoccer_add_enhanced_admin_submenus() {
    // Combined Discounts and Messages submenu
    add_submenu_page(
        'woocommerce',
        __('Manage Discounts & Messages', 'intersoccer-product-variations'),
        __('Discounts & Messages', 'intersoccer-product-variations'),
        'manage_woocommerce',
        'intersoccer-discounts',
        'intersoccer_render_enhanced_discounts_page'
    );

    // Keep existing submenus
    add_submenu_page(
        'woocommerce',
        __('Update Order Details', 'intersoccer-product-variations'),
        __('Update Order Details', 'intersoccer-product-variations'),
        'manage_woocommerce',
        'intersoccer-update-orders',
        'intersoccer_render_update_orders_page'
    );

    add_submenu_page(
        'woocommerce',
        __('Variation Health Checker', 'intersoccer-product-variations'),
        __('Variation Health', 'intersoccer-product-variations'),
        'manage_woocommerce',
        'intersoccer-variation-health',
        'intersoccer_render_variation_health_page'
    );
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

/**
 * Render the Update Orders page.
 */
function intersoccer_render_update_orders_page() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die(__('You do not have permission to access this page.', 'intersoccer-player-management'));
    }

    $orders = wc_get_orders([
        'status' => 'processing',
        'limit' => -1,
        'orderby' => 'date',
        'order' => 'DESC',
    ]);
    ?>
    <div class="wrap">
        <h1><?php _e('Update Processing Orders with Parent Attributes', 'intersoccer-player-management'); ?></h1>
        <p><?php _e('Select orders to update with new visible, non-variation parent product attributes for reporting and analytics. Use "Remove Assigned Player" to delete the unwanted assigned_player field from orders. Use "Fix Incorrect Attributes" to correct orders with unwanted attributes (e.g., all days of the week).', 'intersoccer-player-management'); ?></p>
        <?php if (!empty($orders)) : ?>
            <form id="intersoccer-update-orders-form">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="select-all-orders"></th>
                            <th><?php _e('Order ID', 'intersoccer-player-management'); ?></th>
                            <th><?php _e('Customer', 'intersoccer-player-management'); ?></th>
                            <th><?php _e('Date', 'intersoccer-player-management'); ?></th>
                            <th><?php _e('Total', 'intersoccer-player-management'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order) : ?>
                            <tr>
                                <td><input type="checkbox" name="order_ids[]" value="<?php echo esc_attr($order->get_id()); ?>"></td>
                                <td><?php echo esc_html($order->get_order_number()); ?></td>
                                <td><?php echo esc_html($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()); ?></td>
                                <td><?php echo esc_html($order->get_date_created()->date_i18n('Y-m-d')); ?></td>
                                <td><?php echo wc_price($order->get_total()); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p>
                    <label>
                        <input type="checkbox" id="remove-assigned-player" name="remove_assigned_player">
                        <?php _e('Remove Assigned Player Field', 'intersoccer-player-management'); ?>
                    </label>
                </p>
                <p>
                    <label>
                        <input type="checkbox" id="fix-incorrect-attributes" name="fix_incorrect_attributes">
                        <?php _e('Fix Incorrect Attributes (e.g., remove Days-of-week)', 'intersoccer-player-management'); ?>
                    </label>
                </p>
                <p>
                    <button type="button" id="intersoccer-update-orders-button" class="button button-primary"><?php _e('Update Selected Orders', 'intersoccer-player-management'); ?></button>
                    <span id="intersoccer-update-status"></span>
                </p>
                <?php wp_nonce_field('intersoccer_update_orders_nonce', 'intersoccer_update_orders_nonce'); ?>
            </form>
        <?php else : ?>
            <p><?php _e('No Processing orders found.', 'intersoccer-player-management'); ?></p>
        <?php endif; ?>
    </div>
    <script>
        jQuery(document).ready(function($) {
            $('#select-all-orders').on('change', function() {
                $('input[name="order_ids[]"]').prop('checked', $(this).prop('checked'));
            });

            $('#intersoccer-update-orders-button').on('click', function() {
                var orderIds = $('input[name="order_ids[]"]:checked').map(function() {
                    return $(this).val();
                }).get();
                var nonce = $('#intersoccer_update_orders_nonce').val();
                var removeAssignedPlayer = $('#remove-assigned-player').is(':checked');
                var fixIncorrect = $('#fix-incorrect-attributes').is(':checked');

                if (orderIds.length === 0) {
                    alert('<?php _e('Please select at least one order.', 'intersoccer-player-management'); ?>');
                    return;
                }

                $('#intersoccer-update-status').text('<?php _e('Updating orders...', 'intersoccer-player-management'); ?>').removeClass('error');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'intersoccer_update_processing_orders',
                        nonce: nonce,
                        order_ids: orderIds,
                        remove_assigned_player: removeAssignedPlayer,
                        fix_incorrect_attributes: fixIncorrect
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#intersoccer-update-status').text('<?php _e('Orders updated successfully!', 'intersoccer-player-management'); ?>');
                        } else {
                            $('#intersoccer-update-status').text('<?php _e('Error: ', 'intersoccer-player-management'); ?>' + response.data.message).addClass('error');
                        }
                    },
                    error: function() {
                        $('#intersoccer-update-status').text('<?php _e('An error occurred while updating orders.', 'intersoccer-player-management'); ?>').addClass('error');
                    }
                });
            });
        });
    </script>
    <style>
        #intersoccer-update-status { margin-left: 10px; color: green; }
        #intersoccer-update-status.error { color: red; }
    </style>
    <?php
}

/**
 * AJAX handler to update Processing orders.
 */
add_action('wp_ajax_intersoccer_update_processing_orders', 'intersoccer_update_processing_orders_callback');
function intersoccer_update_processing_orders_callback() {
    check_ajax_referer('intersoccer_update_orders_nonce', 'nonce');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => __('You do not have permission to perform this action.', 'intersoccer-player-management')]);
        wp_die();
    }

    $order_ids = isset($_POST['order_ids']) && is_array($_POST['order_ids']) ? array_map('intval', $_POST['order_ids']) : [];
    $remove_assigned_player = isset($_POST['remove_assigned_player']) && $_POST['remove_assigned_player'] === 'true';
    $fix_incorrect = isset($_POST['fix_incorrect_attributes']) && $_POST['fix_incorrect_attributes'] === 'true';

    if (empty($order_ids)) {
        wp_send_json_error(['message' => __('No orders selected.', 'intersoccer-player-management')]);
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

    wp_send_json_success(['message' => __('Orders updated successfully.', 'intersoccer-player-management')]);
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
            'product_id' => __('Product ID', 'intersoccer-player-management'),
            'variation_id' => __('Variation ID', 'intersoccer-player-management'),
            'type' => __('Type', 'intersoccer-player-management'),
            'attributes' => __('Attributes', 'intersoccer-player-management'),
            'status' => __('Health Status', 'intersoccer-player-management')
        ];
    }

    public function column_cb($item) {
        return sprintf('<input type="checkbox" name="variation_ids[]" value="%s" />', esc_attr($item['variation_id']));
    }

    public function get_bulk_actions() {
        return [
            'refresh' => __('Refresh Attributes', 'intersoccer-player-management'),
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
                        $value = $terms ? implode(', ', wp_list_pluck($terms, 'name')) : __('None', 'intersoccer-player-management');
                    } elseif (is_array($value)) {
                        $value = implode(', ', $value);
                    }
                    $attr_list[] = esc_html($key . ': ' . $value);
                }
                return implode(', ', $attr_list) ?: __('No attributes', 'intersoccer-player-management');
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
        wp_die(__('You do not have permission to access this page.', 'intersoccer-player-management'));
    }

    $show_unhealthy = isset($_GET['show_unhealthy']) && $_GET['show_unhealthy'] === '1';
    $table = new InterSoccer_Variation_Health_Table();
    $table->prepare_items();
    ?>
    <div class="wrap">
        <h1><?php _e('Product Variation Health Checker', 'intersoccer-player-management'); ?></h1>
        <p><?php _e('Lists product variations with their attributes. Flags unhealthy (incomplete) variations missing required attributes for pricing and calculations. For camps, pa_days-of-week is checked on the parent product. Use the filter to show only unhealthy variations.', 'intersoccer-player-management'); ?></p>
        <form method="get">
            <input type="hidden" name="page" value="intersoccer-variation-health">
            <label>
                <input type="checkbox" name="show_unhealthy" value="1" <?php checked($show_unhealthy); ?>>
                <?php _e('Show only unhealthy variations', 'intersoccer-player-management'); ?>
            </label>
            <button type="submit" class="button"><?php _e('Filter', 'intersoccer-player-management'); ?></button>
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
                    alert('<?php _e('Please select an action and at least one variation.', 'intersoccer-player-management'); ?>');
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
                            alert('<?php _e('Attributes refreshed successfully!', 'intersoccer-player-management'); ?>');
                            window.location.reload();
                        } else {
                            alert('<?php _e('Error: ', 'intersoccer-player-management'); ?>' + (response.data.message || 'Unknown error'));
                        }
                    },
                    error: function(xhr, status, error) {
                        alert('<?php _e('An error occurred while refreshing attributes: ', 'intersoccer-player-management'); ?>' + error);
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
        wp_send_json_error(['message' => __('You do not have permission to perform this action.', 'intersoccer-player-management')]);
        wp_die();
    }

    $variation_ids = isset($_POST['variation_ids']) && is_array($_POST['variation_ids']) ? array_map('intval', $_POST['variation_ids']) : [];
    $action = isset($_POST['bulk_action']) ? sanitize_text_field($_POST['bulk_action']) : '';

    if ($action !== 'refresh' || empty($variation_ids)) {
        wp_send_json_error(['message' => __('Invalid action or no variations selected.', 'intersoccer-player-management')]);
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

    wp_send_json_success(['message' => __('Attributes refreshed.', 'intersoccer-player-management')]);
    wp_die();
}
?>