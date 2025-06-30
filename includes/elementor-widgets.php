<?php
/**
 * File: elementor-widgets.php
 * Description: Extends Elementor’s Single Product widget to inject player and day selection fields into the product form variations table for the InterSoccer Player Management plugin.
 * Dependencies: None
 * Author: Jeremy Lee
 * Changes:
 * - Improved DOM injection reliability with retries (2025-05-25).
 * - Updated form validation to handle booking type changes (2025-05-25).
 * - Ensured day selection row visibility for single-day camps (2025-05-25).
 * - Enhanced AJAX error handling and UI feedback for player loading (2025-05-26).
 * - Added loading timeout to prevent indefinite "Loading players..." (2025-05-26).
 * - Removed inline CSS to allow inheritance of base widget formatting (2025-05-26).
 * - Added static .intersoccer-custom-price container to DOM (2025-05-27).
 * - Adjusted placement of .intersoccer-custom-price to render after woocommerce-variation-price and added padding (2025-05-27).
 * - Adjusted placement for simple products to render after quantity (2025-05-27).
 * - Delayed injection until window.load to ensure page is fully loaded (2025-05-27).
 * - Dynamically reposition .intersoccer-custom-price on found_variation event for variable products (2025-05-27).
 * - Removed .intersoccer-custom-price injection, using WooCommerce native price display (2025-05-27).
 * - Updated to support multi-day selection validation and combo discount logging (2025-06-22).
 * - Added server-side preloading of days-of-week for Camps to speed up checkbox rendering (2025-06-26).
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
    $product_type = intersoccer_get_product_type($product_id);

    // Preload days-of-week for Camps
    $preloaded_days = [];
    if ($product_type === 'camp') {
        $attributes = $product->get_attributes();
        if (isset($attributes['pa_days-of-week']) && $attributes['pa_days-of-week'] instanceof WC_Product_Attribute) {
            $terms = wc_get_product_terms($product_id, 'pa_days-of-week', ['fields' => 'names']);
            if (!empty($terms)) {
                $day_order = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
                usort($terms, function ($a, $b) use ($day_order) {
                    $pos_a = array_search($a, $day_order);
                    $pos_b = array_search($b, $day_order);
                    if ($pos_a === false) $pos_a = count($day_order);
                    if ($pos_b === false) $pos_b = count($day_order);
                    return $pos_a - $pos_b;
                });
                $preloaded_days = $terms;
            } else {
                $preloaded_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday']; // Fallback
            }
        }
    }

    // Player selection HTML
    ob_start();
?>
    <tr class="intersoccer-player-selection intersoccer-injected">
        <th><label for="player_assignment_select"><?php esc_html_e('Select an Attendee', 'intersoccer-player-management'); ?></label></th>
        <td>
            <div class="intersoccer-player-content">
                <?php if (!$user_id) : ?>
                    <p class="intersoccer-login-prompt">Please <a href="<?php echo esc_url(wc_get_account_endpoint_url('dashboard')); ?>">log in</a> or <a href="<?php echo esc_url(wc_get_account_endpoint_url('dashboard')); ?>">register</a>.</p>
                <?php else : ?>
                    <p class="intersoccer-loading-players">Loading players...</p>
                <?php endif; ?>
            </div>
        </td>
    </tr>
    <?php
    $player_selection_html = ob_get_clean();

    // Day selection HTML with preloaded days
    $day_selection_html = '';
    if ($is_variable) {
        ob_start();
    ?>
        <tr class="intersoccer-day-selection intersoccer-injected" style="display: none;" data-preloaded-days="<?php echo esc_attr(json_encode($preloaded_days)); ?>">
            <th><label><?php esc_html_e('Select Days', 'intersoccer-player-management'); ?></label></th>
            <td>
                <div class="intersoccer-day-checkboxes"></div>
                <div class="intersoccer-day-notification" style="margin-top: 10px;"></div>
                <span class="error-message" style="color: red; display: none;"></span>
            </td>
        </tr>
    <?php
        $day_selection_html = ob_get_clean();
    }

    // Inject fields
    ?>
    <script>
        jQuery(window).on('load', function() {
            console.log('InterSoccer: Window load event fired, attempting to inject fields');

            function injectFields(retryCount = 0, maxRetries = 5) {
                console.log('InterSoccer: injectFields called, attempt:', retryCount + 1);

                // Find the product form
                var $form = jQuery('form.cart');
                if (!$form.length) {
                    $form = jQuery('.woocommerce-product-details form.cart, .product form.cart, .single-product form.cart');
                }
                if (!$form.length && retryCount < maxRetries) {
                    console.error('InterSoccer: Product form not found, retrying in 2s');
                    setTimeout(() => injectFields(retryCount + 1, maxRetries), 2000);
                    return;
                }
                if (!$form.length) {
                    console.error('InterSoccer: Product form not found after retries');
                    return;
                }
                console.log('InterSoccer: Found product form:', $form);

                // Check if already injected
                if ($form.find('.intersoccer-injected').length > 0) {
                    console.log('InterSoccer: Fields already injected, skipping');
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
                        console.log('InterSoccer: Injected fields into variations table');
                    } else {
                        console.error('InterSoccer: Variations table tbody not found, appending to variations table');
                        $variationsTable.append(<?php echo json_encode($player_selection_html, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>);
                        <?php if ($day_selection_html) : ?>
                            $variationsTable.append(<?php echo json_encode($day_selection_html, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>);
                        <?php endif; ?>
                    }
                } else {
                    // Fallback: Append to form
                    console.error('InterSoccer: Variations table not found, appending to form');
                    $form.append(<?php echo json_encode($player_selection_html, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>);
                    <?php if ($day_selection_html) : ?>
                        $form.append(<?php echo json_encode($day_selection_html, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>);
                    <?php endif; ?>
                }

                // Parse HTML content for links
                $form.find('.intersoccer-player-content').each(function() {
                    var $this = jQuery(this);
                    var htmlContent = $this.html();
                    $this.html(htmlContent);
                });

                // Disable Add to Cart button by default
                var $addToCartButton = $form.find('button.single_add_to_cart_button');
                if ($addToCartButton.length) {
                    $addToCartButton.prop('disabled', true);
                    console.log('InterSoccer: Add to Cart button disabled by default');
                }

                // Fetch player content for logged-in users
                if (intersoccerCheckout.user_id && intersoccerCheckout.user_id !== '0') {
                    var $playerContent = $form.find('.intersoccer-player-content');
                    // Set a timeout to prevent indefinite loading
                    var loadingTimeout = setTimeout(function() {
                        $playerContent.html('<p>Error: Unable to load players. Please try refreshing the page.</p>');
                        console.error('InterSoccer: Player loading timed out after 10 seconds');
                    }, 10000);

                    jQuery.ajax({
                        url: intersoccerCheckout.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'intersoccer_get_user_players',
                            nonce: intersoccerCheckout.nonce,
                            user_id: intersoccerCheckout.user_id
                        },
                        success: function(response) {
                            clearTimeout(loadingTimeout);
                            console.log('InterSoccer: Player fetch response:', response);
                            if (response.success && response.data.players) {
                                if (response.data.players.length > 0) {
                                    var $select = jQuery('<select name="player_assignment" id="player_assignment_select" class="player-select intersoccer-player-select"></select>');
                                    $select.append('<option value=""><?php esc_html_e('Select an Attendee', 'intersoccer-player-management'); ?></option>');
                                    jQuery.each(response.data.players, function(index, player) {
                                        $select.append('<option value="' + index + '">' + player.first_name + ' ' + player.last_name + '</option>');
                                    });
                                    $playerContent.html($select);
                                    $playerContent.append('<span class="error-message" style="color: red; display: none;"></span>');

                                    // Enable Add to Cart button on player selection
                                    $select.on('change', function() {
                                        if (jQuery(this).val()) {
                                            $addToCartButton.prop('disabled', false);
                                            console.log('InterSoccer: Add to Cart button enabled');
                                        } else {
                                            $addToCartButton.prop('disabled', true);
                                            console.log('InterSoccer: Add to Cart button disabled');
                                        }
                                    });
                                } else {
                                    $playerContent.html('<p>No players registered. <a href="<?php echo esc_url(wc_get_account_endpoint_url('manage-players')); ?>">Add a player</a>.</p>');
                                }
                                // Parse HTML for links
                                $playerContent.find('p').each(function() {
                                    var $this = jQuery(this);
                                    var htmlContent = $this.html();
                                    $this.html(htmlContent);
                                });
                            } else {
                                $playerContent.html('<p>Error loading players: ' + (response.data ? response.data.message : 'Unknown error') + '</p>');
                            }
                        },
                        error: function(xhr) {
                            clearTimeout(loadingTimeout);
                            console.error('InterSoccer: Failed to fetch players:', xhr.status, xhr.responseText);
                            $playerContent.html('<p>Error loading players: ' + (xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data.message : 'Request failed') + '</p>');
                        }
                    });
                }
            }

            // Initial injection after window load
            injectFields();

            // Retry injection after delay (increased to 5s for safety)
            setTimeout(() => injectFields(), 5000);
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
            // Find the product form
            var $form = $('form.cart');
            if (!$form.length) {
                $form = $('.woocommerce-product-details form.cart, .product form.cart, .single-product form.cart');
            }
            if ($form.length) {
                $form.on('submit', function(e) {
                    var playerId = $form.find('.player-select').val();
                    if (!playerId && intersoccerCheckout.user_id && intersoccerCheckout.user_id !== '0') {
                        e.preventDefault();
                        $form.find('.intersoccer-player-selection .error-message').text('Please Select an Attendee.').show();
                        setTimeout(() => $form.find('.intersoccer-player-selection .error-message').hide(), 5000);
                    }
                    var bookingType = $form.find('select[name="attribute_pa_booking-type"]').val() || $form.find('input[name="attribute_pa_booking-type"]').val();
                    if (bookingType === 'single-days') {
                        var selectedDays = $form.find('input[name="camp_days[]"]').map(function() { return $(this).val(); }).get();
                        if (selectedDays.length === 0) {
                            e.preventDefault();
                            $form.find('.intersoccer-day-selection .error-message').text('Please select at least one day.').show();
                            setTimeout(() => $form.find('.intersoccer-day-selection .error-message').hide(), 5000);
                        } else {
                            console.log('InterSoccer: Submitting with selected days:', selectedDays);
                        }
                    }
                });

                // Handle booking type change to reset days and render preloaded checkboxes
                $form.find('select[name="attribute_pa_booking-type"]').on('change', function() {
                    var bookingType = $(this).val();
                    var $daySelection = $form.find('.intersoccer-day-selection');
                    var $dayCheckboxes = $form.find('.intersoccer-day-checkboxes');
                    var $dayNotification = $form.find('.intersoccer-day-notification');
                    var $errorMessage = $form.find('.intersoccer-day-selection .error-message');
                    var preloadedDays = JSON.parse($daySelection.attr('data-preloaded-days') || '[]');

                    if (bookingType !== 'single-days') {
                        $daySelection.hide();
                        $form.find('input[name="camp_days[]"]').remove();
                        console.log('InterSoccer: Reset days selection for booking type: ' + bookingType);
                    } else {
                        $daySelection.show();
                        console.log('InterSoccer: Enabled days selection for single-days booking type with preloaded days:', preloadedDays);

                        // Render preloaded checkboxes immediately
                        if (preloadedDays.length > 0) {
                            $dayCheckboxes.empty();
                            preloadedDays.forEach((day) => {
                                $dayCheckboxes.append(`
                                    <label style="margin-right: 10px; display: inline-block;">
                                        <input type="checkbox" name="camp_days_temp[]" value="${day}"> ${day}
                                    </label>
                                `);
                            });
                            $dayCheckboxes.find('input[type="checkbox"]').on('change', function() {
                                var selectedDays = $dayCheckboxes.find('input[type="checkbox"]:checked').map(function() {
                                    return $(this).val();
                                }).get();
                                $form.find('input[name="camp_days[]"]').remove();
                                selectedDays.forEach((day) => {
                                    $form.append(`<input type="hidden" name="camp_days[]" value="${day}">`);
                                });
                                console.log('InterSoccer: Updated selected days:', selectedDays);
                                $form.trigger('check_variations');
                            });
                        } else {
                            console.warn('InterSoccer: No preloaded days available, triggering AJAX fetch');
                            $form.trigger('check_variations'); // Fallback to AJAX
                        }
                    }
                }).trigger('change'); // Trigger on page load to set initial state
            } else {
                console.error('InterSoccer: Product form not found for validation');
            }
        });
    </script>
<?php
});
?>
