<?php
/**
 * Debug script for late pickup metadata
 * Usage: Add ?debug_late_pickup=1 to any camp product admin page
 */

add_action('admin_init', function() {
    if (!isset($_GET['debug_late_pickup'])) {
        return;
    }
    
    if (!current_user_can('manage_woocommerce')) {
        wp_die('Unauthorized');
    }
    
    global $wpdb;
    
    echo '<h2>Late Pickup Debug Information</h2>';
    echo '<style>table { border-collapse: collapse; margin: 20px 0; } th, td { border: 1px solid #ddd; padding: 8px; text-align: left; } th { background: #f0f0f0; }</style>';
    
    // Get all variations with late pickup metadata
    $results = $wpdb->get_results("
        SELECT p.ID, p.post_title, p.post_parent, pm.meta_value as late_pickup_enabled
        FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_intersoccer_enable_late_pickup'
        WHERE p.post_type = 'product_variation'
        AND p.post_status = 'publish'
        ORDER BY p.post_parent, p.ID
    ");
    
    echo '<table>';
    echo '<tr><th>Variation ID</th><th>Parent ID</th><th>Title</th><th>Late Pickup Meta Value</th><th>Actions</th></tr>';
    
    foreach ($results as $row) {
        $product = wc_get_product($row->post_parent);
        $is_camp = $product ? intersoccer_is_camp($row->post_parent) : false;
        
        if (!$is_camp) {
            continue; // Only show camp variations
        }
        
        echo '<tr>';
        echo '<td>' . esc_html($row->ID) . '</td>';
        echo '<td>' . esc_html($row->post_parent) . '</td>';
        echo '<td>' . esc_html($row->post_title) . '</td>';
        echo '<td><strong>' . esc_html($row->late_pickup_enabled ?: '(empty)') . '</strong></td>';
        echo '<td>';
        echo '<a href="' . admin_url('post.php?post=' . $row->post_parent . '&action=edit') . '">Edit Parent</a> | ';
        echo '<a href="?reset_late_pickup=' . $row->ID . '&nonce=' . wp_create_nonce('reset_late_pickup_' . $row->ID) . '">Reset to No</a>';
        echo '</td>';
        echo '</tr>';
    }
    
    echo '</table>';
    
    echo '<p><a href="' . admin_url('edit.php?post_type=product') . '">Back to Products</a></p>';
    
    wp_die();
});

// Handle reset action
add_action('admin_init', function() {
    if (!isset($_GET['reset_late_pickup']) || !isset($_GET['nonce'])) {
        return;
    }
    
    $variation_id = intval($_GET['reset_late_pickup']);
    $nonce = $_GET['nonce'];
    
    if (!wp_verify_nonce($nonce, 'reset_late_pickup_' . $variation_id)) {
        wp_die('Invalid nonce');
    }
    
    if (!current_user_can('manage_woocommerce')) {
        wp_die('Unauthorized');
    }
    
    update_post_meta($variation_id, '_intersoccer_enable_late_pickup', 'no');
    wc_delete_product_transients($variation_id);
    
    wp_redirect(admin_url('edit.php?post_type=product&debug_late_pickup=1'));
    exit;
});

