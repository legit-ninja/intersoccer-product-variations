<?php
/**
 * Test if discount hook is registered
 * 
 * Usage: Visit this file directly in browser or run via WP-CLI:
 * wp eval-file wp-content/plugins/intersoccer-product-variations/utils/test-discount-hook.php
 */

// Load WordPress
if (!defined('ABSPATH')) {
    require_once('../../../wp-load.php');
}

echo "<h1>Discount Hook Test</h1>";

// Check if hook is registered
global $wp_filter;

echo "<h2>1. Check if woocommerce_before_calculate_totals hook exists</h2>";
if (isset($wp_filter['woocommerce_before_calculate_totals'])) {
    echo "✅ Hook exists<br>";
    echo "<pre>";
    print_r($wp_filter['woocommerce_before_calculate_totals']);
    echo "</pre>";
} else {
    echo "❌ Hook does NOT exist<br>";
}

echo "<h2>2. Check if our function is registered</h2>";
if (has_action('woocommerce_before_calculate_totals', 'intersoccer_apply_combo_discounts_to_items')) {
    echo "✅ Function IS registered on hook (priority: " . has_action('woocommerce_before_calculate_totals', 'intersoccer_apply_combo_discounts_to_items') . ")<br>";
} else {
    echo "❌ Function is NOT registered on hook<br>";
}

echo "<h2>3. Check if function exists</h2>";
if (function_exists('intersoccer_apply_combo_discounts_to_items')) {
    echo "✅ Function exists<br>";
} else {
    echo "❌ Function does NOT exist<br>";
}

echo "<h2>4. Check discount rates</h2>";
if (function_exists('intersoccer_get_discount_rates')) {
    $rates = intersoccer_get_discount_rates();
    echo "<pre>";
    print_r($rates);
    echo "</pre>";
} else {
    echo "❌ intersoccer_get_discount_rates function not found<br>";
}

echo "<h2>5. Check discount rules in database</h2>";
$rules = get_option('intersoccer_discount_rules', []);
echo "<h3>Total rules: " . count($rules) . "</h3>";
foreach ($rules as $rule) {
    $status = ($rule['active'] ?? false) ? '✅ ACTIVE' : '❌ INACTIVE';
    echo "<div style='margin: 10px 0; padding: 10px; border: 1px solid #ccc;'>";
    echo "<strong>{$status} - {$rule['name']}</strong><br>";
    echo "Type: {$rule['type']}, Condition: {$rule['condition']}, Rate: {$rule['rate']}%<br>";
    echo "</div>";
}

echo "<h2>6. Test cart calculation trigger</h2>";
if (WC()->cart) {
    echo "✅ WooCommerce cart object exists<br>";
    echo "Cart items: " . count(WC()->cart->get_cart()) . "<br>";
    
    if (count(WC()->cart->get_cart()) > 0) {
        echo "<br><strong>Manually triggering discount calculation...</strong><br>";
        do_action('woocommerce_before_calculate_totals', WC()->cart);
        echo "✅ Hook triggered manually<br>";
    }
} else {
    echo "❌ WooCommerce cart not available<br>";
}

echo "<h2>7. Check debug.log for messages</h2>";
$log_file = WP_CONTENT_DIR . '/debug.log';
if (file_exists($log_file)) {
    $log_lines = file($log_file);
    $recent_lines = array_slice($log_lines, -50);
    $discount_lines = array_filter($recent_lines, function($line) {
        return strpos($line, 'DISCOUNT FUNCTION CALLED') !== false ||
               strpos($line, 'InterSoccer Tournament') !== false ||
               strpos($line, 'Built cart context') !== false;
    });
    
    if (!empty($discount_lines)) {
        echo "✅ Found discount messages in log:<br>";
        echo "<pre>" . implode('', $discount_lines) . "</pre>";
    } else {
        echo "❌ No discount messages in recent log entries<br>";
    }
} else {
    echo "❌ Debug log file not found at: {$log_file}<br>";
}

