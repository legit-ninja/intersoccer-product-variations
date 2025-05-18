<?php

/**
 * Checkout Modifications
 * Purpose: Custom modifications to WooCommerce checkout for InterSoccer.
 */

defined('ABSPATH') or die('No script kiddies please!');

// Diagnostic log to confirm file inclusion
error_log('InterSoccer: checkout.php file loaded');

// Render player assignment section on checkout
add_action('woocommerce_checkout_after_customer_details', 'intersoccer_render_player_assignment_checkout');
function intersoccer_render_player_assignment_checkout()
{
    // Check if user is logged in
    $user_id = get_current_user_id();
    if (!$user_id) {
        error_log('InterSoccer: User not logged in, skipping player assignment section');
        return;
    }

    // Get cart items
    $cart = WC()->cart->get_cart();
    if (empty($cart)) {
        error_log('InterSoccer: Cart is empty, skipping player assignment section');
        return;
    }

    error_log('InterSoccer: Rendering player assignment section for user ID: ' . $user_id);
    error_log('InterSoccer: Cart items: ' . print_r(array_keys($cart), true));

    // Loop through cart items and render player assignment for each event product
    foreach ($cart as $cart_item_key => $cart_item) {
        $product = $cart_item['data'];
        $product_id = $product->get_id();
        $quantity = $cart_item['quantity'];

        // Check if this is an event product (e.g., Camp or Course)
        $terms = wp_get_post_terms($product->get_parent_id(), 'product_cat', ['fields' => 'slugs']);
        if (!in_array('camps', $terms, true) && !in_array('courses', $terms, true)) {
            error_log('InterSoccer: Product ID ' . $product_id . ' is not a Camp or Course, skipping');
            continue;
        }

        error_log('InterSoccer: Rendering player assignment for cart item key: ' . $cart_item_key . ', product ID: ' . $product_id);

        // Get players from user meta
        $players = get_user_meta($user_id, 'intersoccer_players', true);
        $players = is_array($players) ? $players : [];

        if (empty($players)) {
            error_log('InterSoccer: No players found for user ID: ' . $user_id . ', cart item key: ' . $cart_item_key);
            continue;
        }

        // Render player assignment section
?>
        <div class="intersoccer-player-assignment" data-cart-item-key="<?php echo esc_attr($cart_item_key); ?>">
            <h3><?php echo esc_html__('Assign Player to Event: ', 'intersoccer-player-management') . esc_html($product->get_name()); ?></h3>
            <label for="player-select-<?php echo esc_attr($cart_item_key); ?>"><?php esc_html_e('Select Player', 'intersoccer-player-management'); ?></label>
            <select id="player-select-<?php echo esc_attr($cart_item_key); ?>" class="player-select" name="player_assignments[<?php echo esc_attr($cart_item_key); ?>]">
                <option value=""><?php esc_html_e('Select a player', 'intersoccer-player-management'); ?></option>
                <?php
                foreach ($players as $index => $player) {
                    $player_name = trim($player['first_name'] . ' ' . $player['last_name']);
                    if (empty($player_name)) {
                        continue;
                    }
                    echo '<option value="' . esc_attr($index) . '">' . esc_html($player_name) . '</option>';
                }
                ?>
            </select>
            <span class="error-message" style="color: red; display: none;" aria-live="assertive"></span>
            <input type="hidden" class="player-assignments-validated" name="player_assignments_validated[<?php echo esc_attr($cart_item_key); ?>]" value="">
        </div>
    <?php
    }
}

// Add inline CSS to ensure visibility and basic styling
add_action('wp_head', 'intersoccer_checkout_inline_styles');
function intersoccer_checkout_inline_styles()
{
    if (is_checkout()) {
    ?>
        <style>
            .intersoccer-player-assignment {
                display: block !important;
                margin: 20px 0;
                padding: 10px;
                background-color: var(--wp--preset--color--bg-color, #1B1A1A);
                color: var(--wp--preset--color--text-dark, #FFFEFE);
                border: 1px solid var(--wp--preset--color--text-light, #8F8E8E);
                border-radius: 4px;
            }

            .intersoccer-player-assignment h3 {
                margin-top: 0;
                color: var(--wp--preset--color--text-dark, #FFFEFE);
            }

            .intersoccer-player-assignment label {
                display: block;
                margin-bottom: 5px;
                color: var(--wp--preset--color--text-dark, #FFFEFE);
            }

            .intersoccer-player-assignment select {
                width: 100%;
                padding: 5px;
                background-color: var(--wp--preset--color--bg-color, #1B1A1A);
                color: var(--wp--preset--color--text-dark, #FFFEFE);
                border: 1px solid var(--wp--preset--color--text-light, #8F8E8E);
                border-radius: 4px;
            }
        </style>
<?php
    }
}

?>
