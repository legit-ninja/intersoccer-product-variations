<?php

/**
 * File: elementor-widgets.php
 * Description: Extends Elementor’s Single Product widget to inject player and day selection fields into the product form variations table for the InterSoccer Player Management plugin. Ensures fields follow the same formatting as other variations.
 * Dependencies: None
 * Changes:
 * - Updated form validation to check hidden camp_days[] inputs instead of visible checkboxes (2025-05-15).
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Inject player and day selection fields into the variations table
add_action('wp_footer', function () {
    if (!is_product()) {
        return;
    }

    // Get current product
    global $product;
    if (!is_a($product, 'WC_Product')) {
        return;
    }

    $product_id = $product->get_id();
    $user_id = get_current_user_id();
    $is_variable = $product->is_type('variable');

    // Player selection HTML (placeholder, content loaded via AJAX for logged-in users)
    ob_start();
?>
    <tr class="intersoccer-player-selection intersoccer-injected">
        <th><label for="player_assignment_select"><?php esc_html_e('Select an Attendee', 'intersoccer-player-management'); ?></label></th>
        <td>
            <div class="intersoccer-player-content">
                <?php if (!$user_id) : ?>
                    <p class="intersoccer-login-prompt">Please <a href="https://intersoccer.legit.ninja/membership-login/">log in</a> or <a href="https://intersoccer.legit.ninja/membership-login/">register</a>.</p>
                <?php else : ?>
                    <p>Loading players...</p>
                <?php endif; ?>
            </div>
        </td>
    </tr>
    <?php
    $player_selection_html = ob_get_clean();

    // Day selection HTML (hidden by default, as a table row with checkboxes)
    $day_selection_html = '';
    if ($product->get_type() === 'variable') {
        ob_start();
    ?>
        <tr class="intersoccer-day-selection intersoccer-injected" style="display: none;">
            <th><label><?php esc_html_e('Select Days', 'intersoccer-player-management'); ?></label></th>
            <td>
                <div class="intersoccer-day-checkboxes"></div>
                <div class="intersoccer-day-notification" style="margin-top: 10px; color: #007cba;"></div>
                <span class="error-message" style="color: red; display: none;"></span>
            </td>
        </tr>
    <?php
        $day_selection_html = ob_get_clean();
    }

    // Inject fields into the variations table and load player content dynamically
    ?>
    <script>
        jQuery(document).ready(function($) {
            console.log('InterSoccer: Document ready, attempting to inject fields');

            function injectFields() {
                console.log('InterSoccer: InjectFields called');

                // Find the product form
                var $form = $('form.cart');
                if (!$form.length) {
                    $form = $('.woocommerce-product-details form.cart, .product form.cart, .single-product form.cart');
                }
                if (!$form.length) {
                    console.error('InterSoccer: Product form not found with any selector');
                    return;
                }
                console.log('InterSoccer: Found product form:', $form);

                // Check if already injected to prevent duplicates
                if ($form.find('.intersoccer-injected').length > 0) {
                    console.log('InterSoccer: Player and day selection fields already injected, skipping');
                    return;
                }

                // Find the variations table
                var $variationsTable = $form.find('.variations');
                if ($variationsTable.length) {
                    var $tbody = $variationsTable.find('tbody');
                    if ($tbody.length) {
                        $tbody.append(<?php echo json_encode($player_selection_html, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>);
                        <?php if ($day_selection_html) : ?>
                            $tbody.append(<?php echo json_encode($day_selection_html, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>);
                        <?php endif; ?>
                        console.log('InterSoccer: Injected player and day selection fields into variations table');
                    } else {
                        console.error('InterSoccer: Variations table tbody not found, appending to variations table');
                        $variationsTable.append(<?php echo json_encode($player_selection_html, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>);
                        <?php if ($day_selection_html) : ?>
                            $variationsTable.append(<?php echo json_encode($day_selection_html, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>);
                        <?php endif; ?>
                    }
                } else {
                    // Fallback: Append to form
                    console.error('InterSoccer: Variations table not found, appended to form');
                    $form.append(<?php echo json_encode($player_selection_html, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>);
                    <?php if ($day_selection_html) : ?>
                        $form.append(<?php echo json_encode($day_selection_html, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>);
                    <?php endif; ?>
                }

                // Parse HTML content after injection to ensure links render properly
                $form.find('.intersoccer-player-content').each(function() {
                    var $this = $(this);
                    var htmlContent = $this.html();
                    $this.html(htmlContent);
                });

                // Disable the Add to Cart button by default
                var $addToCartButton = $form.find('button.single_add_to_cart_button');
                if ($addToCartButton.length) {
                    $addToCartButton.prop('disabled', true);
                    console.log('InterSoccer: Add to Cart button disabled by default');
                }

                // Fetch player content dynamically only for logged-in users
                if (intersoccerCheckout.user_id && intersoccerCheckout.user_id !== '0') {
                    $.ajax({
                        url: intersoccerCheckout.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'intersoccer_get_user_players',
                            nonce: intersoccerCheckout.nonce,
                            user_id: intersoccerCheckout.user_id
                        },
                        success: function(response) {
                            console.log('InterSoccer: Player fetch response:', response);
                            var $playerContent = $form.find('.intersoccer-player-content');
                            if (response.success && response.data.players) {
                                if (response.data.players.length > 0) {
                                    var $select = $('<select name="player_assignment" id="player_assignment_select" class="player-select intersoccer-player-select"></select>');
                                    $select.append('<option value=""><?php esc_html_e('Select an Attendee', 'intersoccer-player-management'); ?></option>');
                                    $.each(response.data.players, function(index, player) {
                                        $select.append('<option value="' + index + '">' + player.first_name + ' ' + player.last_name + '</option>');
                                    });
                                    $playerContent.html($select);
                                    $playerContent.append('<span class="error-message" style="color: red; display: none;"></span>');

                                    // Enable Add to Cart button when a player is selected
                                    $select.on('change', function() {
                                        if ($(this).val()) {
                                            $addToCartButton.prop('disabled', false);
                                            console.log('InterSoccer: Add to Cart button enabled');
                                        } else {
                                            $addToCartButton.prop('disabled', true);
                                            console.log('InterSoccer: Add to Cart button disabled');
                                        }
                                    });
                                } else {
                                    $playerContent.html('<p>No players registered. <a href="/my-account/manage-players/">Add a player</a>.</p>');
                                }
                                // Parse HTML content to ensure links render properly
                                $playerContent.find('p').each(function() {
                                    var $this = $(this);
                                    var htmlContent = $this.html();
                                    $this.html(htmlContent);
                                });
                            } else {
                                $playerContent.html('<p>Error loading players.</p>');
                            }
                        },
                        error: function(xhr) {
                            console.error('InterSoccer: Failed to fetch players:', xhr.responseText);
                            $form.find('.intersoccer-player-content').html('<p>Error loading players.</p>');
                        }
                    });
                }
            }

            // Initial injection attempt
            injectFields();

            // Retry injection after a delay to ensure Elementor has fully rendered
            setTimeout(injectFields, 3000);

            // Listen for variation changes and re-inject if necessary
            $('form.cart').on('found_variation', function() {
                if (!$('form.cart').find('.intersoccer-day-selection').length) {
                    console.log('InterSoccer: Day selection container missing after variation change, re-injecting');
                    injectFields();
                }
            });
        });
    </script>
<?php
});

// Validate player/day selection on form submission
add_action('wp_footer', function () {
    if (!is_product()) {
        return;
    }
?>
    <script>
        jQuery(document).ready(function($) {
            // Use a more generic selector for the form
            var $form = $('form.cart');
            if (!$form.length) {
                $form = $('.woocommerce-product-details form.cart');
            }
            if (!$form.length) {
                $form = $('.product form.cart');
            }
            if ($form.length) {
                $form.on('submit', function(e) {
                    var playerId = $form.find('.player-select').val();
                    if (!playerId && intersoccerCheckout.user_id && intersoccerCheckout.user_id !== '0') {
                        e.preventDefault();
                        $form.find('.intersoccer-player-selection .error-message').text('Please Select an Attendee.').show();
                        setTimeout(() => $form.find('.intersoccer-player-selection .error-message').hide(), 5000);
                    }
                    var bookingType = $form.find('select[name="attribute_pa_booking-type"]').val();
                    if (bookingType === 'single-days') {
                        var selectedDays = $form.find('input[name="camp_days[]"]').length;
                        if (!selectedDays) {
                            e.preventDefault();
                            $form.find('.intersoccer-day-selection .error-message').text('Please select at least one day.').show();
                            setTimeout(() => $form.find('.intersoccer-day-selection .error-message').hide(), 5000);
                        }
                    }
                });
            } else {
                console.error('InterSoccer: Product form not found for validation');
            }
        });
    </script>
<?php
});
?>
