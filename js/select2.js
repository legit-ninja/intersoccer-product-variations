<?php

/**
 * File: elementor-widgets.php
 * Description: Extends Elementor’s Single Product widget to inject player and day selection fields into the product form table for the InterSoccer Player Management plugin. Ensures fields are rendered within the table, styled via Elementor custom CSS, and integrated with WooCommerce cart/order.
 * Dependencies: None
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Hook into Elementor Single Product widget
add_action('elementor/frontend/widget/after_render', function ($widget) {
    if ($widget->get_name() !== 'wc-single-product') {
        return;
    }

    // Get current product
    global $product;
    if (!is_a($product, 'WC_Product')) {
        return;
    }

    $product_id = $product->get_id();
    $user_id = get_current_user_id();
    $players = $user_id ? get_user_meta($user_id, 'intersoccer_players', true) : [];
    $is_variable = $product->is_type('variable');

    // Player selection HTML
    ob_start();
?>
    <tr class="intersoccer-player-selection-row">
        <th><label for="player_assignment_select"><?php esc_html_e('Select an Attendee', 'intersoccer-player-management'); ?></label></th>
        <td>
            <div class="intersoccer-player-selection">
                <?php if (!$user_id) : ?>
                    <p><?php esc_html_e('Please <a href="https://intersoccer.legit.ninja/membership-login/">log in</a> or <a href="https://intersoccer.legit.ninja/membership-login/">register</a>.', 'intersoccer-player-management'); ?></p>
                <?php elseif (empty($players)) : ?>
                    <p><?php esc_html_e('No players registered. <a href="/my-account/manage-players/">Add a player</a>.', 'intersoccer-player-management'); ?></p>
                <?php else : ?>
                    <select name="player_assignment" id="player_assignment_select" class="player-select intersoccer-player-select">
                        <option value=""><?php esc_html_e('Select an attendee', 'intersoccer-player-management'); ?></option>
                        <?php foreach ($players as $index => $player) : ?>
                            <option value="<?php echo esc_attr($index); ?>">
                                <?php echo esc_html($player['first_name'] . ' ' . $player['last_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="error-message" style="color: red; display: none;"></span>
                <?php endif; ?>
            </div>
        </td>
    </tr>
    <?php
    $player_selection_html = ob_get_clean();

    // Day selection HTML (for camps with Single Day(s))
    $day_selection_html = '';
    if ($product->get_type() === 'variable') {
        ob_start();
    ?>
        <tr class="intersoccer-day-selection-row" style="display: none;">
            <th><label for="camp_days"><?php esc_html_e('Select Days', 'intersoccer-player-management'); ?></label></th>
            <td>
                <div class="intersoccer-day-selection">
                    <select name="camp_days[]" id="camp_days" multiple="multiple" class="day-select intersoccer-day-select"></select>
                    <div class="selected-days-tags" style="margin-top: 10px;"></div>
                    <span class="error-message" style="color: red; display: none;"></span>
                </div>
            </td>
        </tr>
    <?php
        $day_selection_html = ob_get_clean();
    }

    // Inject into Elementor form table
    add_action('woocommerce_after_variations_form', function () use ($player_selection_html, $day_selection_html) {
    ?>
        <script>
            jQuery(document).ready(function($) {
                var $form = $('.elementor-widget-wc-single-product form.cart');
                var $table = $form.find('.variations');
                if ($table.length) {
                    $table.find('tbody').append('<?php echo addslashes($player_selection_html); ?>');
                    <?php if ($day_selection_html) : ?>
                        $table.find('tbody').append('<?php echo addslashes($day_selection_html); ?>');
                    <?php endif; ?>
                }
            });
        </script>
    <?php
    }, 10);
});

// Validate player/day selection on form submission
add_action('wp_footer', function () {
    if (!is_product()) {
        return;
    }
    ?>
    <script>
        jQuery(document).ready(function($) {
            var $form = $('.elementor-widget-wc-single-product form.cart');
            if ($form.length) {
                $form.on('submit', function(e) {
                    var playerId = $form.find('.player-select').val();
                    if (!playerId && <?php echo get_current_user_id() ? 'true' : 'false'; ?>) {
                        e.preventDefault();
                        $form.find('.intersoccer-player-selection .error-message').text('Please select an attendee.').show();
                        setTimeout(() => $form.find('.intersoccer-player-selection .error-message').hide(), 5000);
                    }
                    var bookingType = $form.find('select[name="attribute_pa_booking-type"]').val();
                    if (bookingType === 'single-days' || bookingType === 'à la journée' || bookingType === 'a-la-journee' || bookingType.toLowerCase().includes('single') || bookingType.toLowerCase().includes('journée') || bookingType.toLowerCase().includes('journee')) {
                        var selectedDays = $form.find('.day-select').val();
                        if (!selectedDays || !selectedDays.length) {
                            e.preventDefault();
                            $form.find('.intersoccer-day-selection .error-message').text('Please select at least one day.').show();
                            setTimeout(() => $form.find('.intersoccer-day-selection .error-message').hide(), 5000);
                        }
                    }
                });
            }
        });
    </script>
<?php
});
?>
