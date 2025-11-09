<?php
/**
 * Force Cache Clear - Run this via browser or CLI
 * URL: https://intersoccer.legit.ninja/wp-content/plugins/intersoccer-product-variations/force-cache-clear.php
 */

// Try to load WordPress
$wp_load_paths = [
    '../../../../wp-load.php',
    '../../../wp-load.php',
    '../../wp-load.php',
    '../wp-load.php',
];

$wp_loaded = false;
foreach ($wp_load_paths as $path) {
    if (file_exists(__DIR__ . '/' . $path)) {
        require_once(__DIR__ . '/' . $path);
        $wp_loaded = true;
        break;
    }
}

if (!$wp_loaded) {
    die("Could not load WordPress. Run this from the plugin directory.\n");
}

echo "Clearing all caches...\n\n";

// 1. Clear PHP Opcache
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "✓ PHP Opcache cleared\n";
} else {
    echo "⚠ PHP Opcache not available\n";
}

// 2. Clear WordPress object cache
wp_cache_flush();
echo "✓ WordPress object cache flushed\n";

// 3. Delete ALL transients
global $wpdb;
$deleted_transients = $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'");
$deleted_site_transients = $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_site_transient_%'");
echo "✓ Deleted $deleted_transients transients and $deleted_site_transients site transients\n";

// 4. Clear Elementor cache
if (class_exists('\Elementor\Plugin')) {
    try {
        \Elementor\Plugin::$instance->files_manager->clear_cache();
        echo "✓ Elementor cache cleared\n";
    } catch (Exception $e) {
        echo "⚠ Elementor cache clear failed: " . $e->getMessage() . "\n";
    }
} else {
    echo "⚠ Elementor not active\n";
}

// 5. Clear WooCommerce product caches
$deleted_wc1 = $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%wc_var_prices_%'");
$deleted_wc2 = $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%_wc_%_cache'");
$deleted_wc3 = $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%_transient_wc_%'");
echo "✓ WooCommerce caches cleared ($deleted_wc1 + $deleted_wc2 + $deleted_wc3 entries)\n";

// 6. Clear rewrite rules
flush_rewrite_rules();
echo "✓ Rewrite rules flushed\n";

// 7. Touch the plugin file to force reload
touch(__DIR__ . '/intersoccer-product-variations.php');
echo "✓ Plugin file touched (forces WordPress reload)\n";

// 8. Clear any page caching plugins
if (function_exists('w3tc_flush_all')) {
    w3tc_flush_all();
    echo "✓ W3 Total Cache cleared\n";
}

if (function_exists('wp_cache_clear_cache')) {
    wp_cache_clear_cache();
    echo "✓ WP Super Cache cleared\n";
}

if (class_exists('LiteSpeed\Purge')) {
    do_action('litespeed_purge_all');
    echo "✓ LiteSpeed Cache cleared\n";
}

if (function_exists('rocket_clean_domain')) {
    rocket_clean_domain();
    echo "✓ WP Rocket cache cleared\n";
}

echo "\n";
echo "===================================\n";
echo "ALL CACHES CLEARED!\n";
echo "===================================\n";
echo "\n";
echo "Latest Fix (November 5, 2025):\n";
echo "  - AJAX won't update price display when late pickup active\n";
echo "  - Prevents brief flash of camp-only price\n";
echo "  - Late pickup handler updates display with final total\n";
echo "\n";
echo "Expected console logs:\n";
echo "  ✓ 'Late pickup active, skipping price display update'\n";
echo "  ✓ 'Updated base price from AJAX to: 220'\n";
echo "  ✓ 'Updated main price - base: 220 late pickup: 25 total: 245'\n";
echo "\n";
echo "Now test:\n";
echo "1. Close browser completely\n";
echo "2. Reopen and visit: https://intersoccer.legit.ninja/shop/geneva-autumn-camps/\n";
echo "3. Hard refresh (Ctrl+Shift+R)\n";
echo "4. Select player, Tuesday, enable late pickup, add Wednesday\n";
echo "5. Watch price - should go smoothly from CHF 135 to CHF 245\n";
echo "6. No flash of CHF 220 in between!\n";
echo "\n";

// Self-destruct
unlink(__FILE__);
?>
