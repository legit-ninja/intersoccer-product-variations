<?php
/**
 * Diagnose discount system - outputs to WordPress debug.log
 * 
 * Usage via WP-CLI:
 * wp eval-file wp-content/plugins/intersoccer-product-variations/utils/diagnose-discounts.php
 */

error_log('========== DISCOUNT SYSTEM DIAGNOSTIC ==========');

// 1. Check if function exists
if (function_exists('intersoccer_apply_combo_discounts_to_items')) {
    error_log('✅ Function intersoccer_apply_combo_discounts_to_items EXISTS');
} else {
    error_log('❌ Function intersoccer_apply_combo_discounts_to_items DOES NOT EXIST');
}

// 2. Check if hook is registered
$priority = has_action('woocommerce_before_calculate_totals', 'intersoccer_apply_combo_discounts_to_items');
if ($priority !== false) {
    error_log('✅ Function IS registered on woocommerce_before_calculate_totals hook at priority: ' . $priority);
} else {
    error_log('❌ Function is NOT registered on woocommerce_before_calculate_totals hook');
}

// 3. Check all hooks registered on woocommerce_before_calculate_totals
global $wp_filter;
if (isset($wp_filter['woocommerce_before_calculate_totals'])) {
    error_log('✅ woocommerce_before_calculate_totals hook EXISTS');
    $hooks = $wp_filter['woocommerce_before_calculate_totals'];
    error_log('Total callbacks registered: ' . count($hooks->callbacks));
    foreach ($hooks->callbacks as $priority => $callbacks) {
        foreach ($callbacks as $callback) {
            $function_name = is_string($callback['function']) ? $callback['function'] : 'closure/array';
            error_log('  Priority ' . $priority . ': ' . $function_name);
        }
    }
} else {
    error_log('❌ woocommerce_before_calculate_totals hook DOES NOT EXIST');
}

// 4. Check discount rules in database
$rules = get_option('intersoccer_discount_rules', []);
error_log('Total discount rules in database: ' . count($rules));

$tournament_rule_found = false;
foreach ($rules as $rule) {
    if (($rule['type'] ?? '') === 'tournament' && ($rule['condition'] ?? '') === 'same_child_multiple_days') {
        $tournament_rule_found = true;
        error_log('✅ FOUND Tournament Same-Child Rule:');
        error_log('  ID: ' . ($rule['id'] ?? 'NONE'));
        error_log('  Name: ' . ($rule['name'] ?? 'NONE'));
        error_log('  Type: ' . ($rule['type'] ?? 'NONE'));
        error_log('  Condition: ' . ($rule['condition'] ?? 'NONE'));
        error_log('  Rate: ' . ($rule['rate'] ?? 'NONE') . '%');
        error_log('  Active: ' . (($rule['active'] ?? false) ? 'YES' : 'NO'));
    }
}

if (!$tournament_rule_found) {
    error_log('❌ Tournament same-child discount rule NOT FOUND in database');
    error_log('Checking for any tournament rules...');
    foreach ($rules as $rule) {
        if (($rule['type'] ?? '') === 'tournament') {
            error_log('Found tournament rule: ' . ($rule['name'] ?? 'unnamed') . ' - Condition: ' . ($rule['condition'] ?? 'none'));
        }
    }
}

// 5. Check discount rates
if (function_exists('intersoccer_get_discount_rates')) {
    $rates = intersoccer_get_discount_rates();
    error_log('Discount rates from intersoccer_get_discount_rates():');
    error_log('  Camp rates: ' . json_encode($rates['camp'] ?? []));
    error_log('  Course rates: ' . json_encode($rates['course'] ?? []));
    error_log('  Tournament rates: ' . json_encode($rates['tournament'] ?? []));
    
    if (isset($rates['tournament']['same_child_multiple_days'])) {
        error_log('✅ Tournament same_child_multiple_days rate: ' . ($rates['tournament']['same_child_multiple_days'] * 100) . '%');
    } else {
        error_log('❌ Tournament same_child_multiple_days rate NOT FOUND in active rates');
    }
} else {
    error_log('❌ Function intersoccer_get_discount_rates DOES NOT EXIST');
}

// 6. Check admin settings
error_log('Admin settings:');
error_log('  intersoccer_enable_retroactive_course_discounts: ' . (get_option('intersoccer_enable_retroactive_course_discounts', true) ? 'enabled' : 'disabled'));
error_log('  intersoccer_enable_retroactive_camp_discounts: ' . (get_option('intersoccer_enable_retroactive_camp_discounts', true) ? 'enabled' : 'disabled'));
error_log('  intersoccer_retroactive_discount_lookback_months: ' . get_option('intersoccer_retroactive_discount_lookback_months', 6));

// 7. Test cart if available
if (function_exists('WC') && WC()->cart) {
    error_log('✅ WooCommerce cart is available');
    $cart_items = WC()->cart->get_cart();
    error_log('Cart has ' . count($cart_items) . ' items');
    
    if (count($cart_items) > 0) {
        error_log('Manually triggering discount calculation...');
        do_action('woocommerce_before_calculate_totals', WC()->cart);
        error_log('Hook triggered - check for discount messages above this line');
    }
} else {
    error_log('❌ WooCommerce cart not available');
}

error_log('========== END DIAGNOSTIC ==========');

