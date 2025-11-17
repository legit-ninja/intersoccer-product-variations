<?php
/**
 * Fix Tournament Activity Type in Existing Orders
 * 
 * This script corrects orders where tournament products were incorrectly
 * saved with "Course" as the Activity Type instead of "Tournament".
 * 
 * Usage:
 *   wp eval-file fix-tournament-activity-types.php
 *   OR
 *   php fix-tournament-activity-types.php (if run from WordPress root)
 */

// Load WordPress
if (file_exists(__DIR__ . '/../../../wp-load.php')) {
    require_once __DIR__ . '/../../../wp-load.php';
} elseif (file_exists(__DIR__ . '/../../../../wp-load.php')) {
    require_once __DIR__ . '/../../../../wp-load.php';
} else {
    die("Error: Could not find wp-load.php. Please run this script from the WordPress root or via WP-CLI.\n");
}

if (!function_exists('wc_get_orders')) {
    die("Error: WooCommerce is not active.\n");
}

if (!function_exists('intersoccer_get_product_type')) {
    die("Error: InterSoccer Product Variations plugin is not active.\n");
}

echo "=== Fixing Tournament Activity Types in Existing Orders ===\n\n";

// Get all orders
$orders = wc_get_orders([
    'limit' => -1,
    'status' => 'any',
    'return' => 'ids',
]);

$total_orders = count($orders);
$orders_checked = 0;
$items_fixed = 0;
$items_already_correct = 0;
$errors = [];

echo "Found {$total_orders} orders to check.\n\n";

foreach ($orders as $order_id) {
    $orders_checked++;
    $order = wc_get_order($order_id);
    
    if (!$order) {
        $errors[] = "Order {$order_id}: Could not load order";
        continue;
    }
    
    foreach ($order->get_items() as $item_id => $item) {
        $product_id = $item->get_product_id();
        $variation_id = $item->get_variation_id();
        
        // Get the actual product type
        $product_type = intersoccer_get_product_type($product_id);
        
        // Only process tournament products
        if ($product_type !== 'tournament') {
            continue;
        }
        
        // Get existing Activity Type
        $existing_activity_type = $item->get_meta('Activity Type', true);
        $correct_activity_type = 'Tournament';
        
        // Check if Activity Type is incorrect
        if ($existing_activity_type && $existing_activity_type !== $correct_activity_type) {
            echo "Order #{$order_id}, Item #{$item_id}: Fixing Activity Type from '{$existing_activity_type}' to '{$correct_activity_type}'\n";
            
            try {
                $item->update_meta_data('Activity Type', $correct_activity_type);
                $item->save();
                $items_fixed++;
            } catch (Exception $e) {
                $errors[] = "Order {$order_id}, Item {$item_id}: " . $e->getMessage();
                echo "  ERROR: " . $e->getMessage() . "\n";
            }
        } elseif ($existing_activity_type === $correct_activity_type) {
            $items_already_correct++;
        } elseif (empty($existing_activity_type)) {
            // Missing Activity Type - add it
            echo "Order #{$order_id}, Item #{$item_id}: Adding missing Activity Type '{$correct_activity_type}'\n";
            try {
                $item->add_meta_data('Activity Type', $correct_activity_type);
                $item->save();
                $items_fixed++;
            } catch (Exception $e) {
                $errors[] = "Order {$order_id}, Item {$item_id}: " . $e->getMessage();
                echo "  ERROR: " . $e->getMessage() . "\n";
            }
        }
    }
    
    // Progress indicator
    if ($orders_checked % 100 === 0) {
        echo "Progress: {$orders_checked}/{$total_orders} orders checked...\n";
    }
}

echo "\n=== Summary ===\n";
echo "Orders checked: {$orders_checked}\n";
echo "Items fixed: {$items_fixed}\n";
echo "Items already correct: {$items_already_correct}\n";

if (!empty($errors)) {
    echo "\nErrors encountered: " . count($errors) . "\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
} else {
    echo "\nNo errors encountered.\n";
}

echo "\nDone!\n";

