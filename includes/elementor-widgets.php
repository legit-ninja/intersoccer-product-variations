<?php
/**
 * File: elementor-widgets.php
 * Description: Extends Elementor’s Single Product widget to inject player and day selection fields into the product form variations table for the InterSoccer Player Management plugin.
 * Dependencies: None
 * Author: Jeremy Lee
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
                    <p class="intersoccer-login-prompt">Please <a href="<?php echo esc_url(wc_get_account_endpoint_url('dashboard')); ?>">log in</a> or <a href="<?php echo esc_url(wc_get_account_endpoint_url('dashboard')); ?>">register</a> to select an attendee.</p>
                <?php else : ?>
                    <p class="intersoccer-loading-players">Loading players...</p>
                <?php endif; ?>
                <span class="intersoccer-attendee-notification" style="color: red; display: none; margin-top: 10px;">Please select an attendee to add to cart.</span>
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

                // Fetch player content for logged-in users
                if (intersoccerCheckout.user_id && intersoccerCheckout.user_id !== '0') {
                    var $playerContent = $form.find('.intersoccer-player-content');
                    // Set a timeout to prevent indefinite loading
                    var loadingTimeout = setTimeout(function() {
                        $playerContent.find('.intersoccer-loading-players').html('<p>Error: Unable to load players. Please try refreshing the page.</p>');
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
                                    $playerContent.append('<span class="intersoccer-attendee-notification" style="color: red; display: none; margin-top: 10px;">Please select an attendee to add to cart.</span>');

                                    // Initial button state check
                                    $form.trigger('intersoccer_update_button_state');
                                } else {
                                    $playerContent.html('<p>No players registered. <a href="<?php echo esc_url(wc_get_account_endpoint_url('manage-players')); ?>">Add a player</a>.</p>');
                                    $playerContent.append('<span class="intersoccer-attendee-notification" style="color: red; display: block; margin-top: 10px;">Please add a player to continue.</span>');
                                    $form.trigger('intersoccer_update_button_state');
                                }
                                // Parse HTML for links
                                $playerContent.find('p').each(function() {
                                    var $this = jQuery(this);
                                    var htmlContent = $this.html();
                                    $this.html(htmlContent);
                                });
                            } else {
                                $playerContent.html('<p>Error loading players: ' + (response.data ? response.data.message : 'Unknown error') + '</p>');
                                $playerContent.append('<span class="intersoccer-attendee-notification" style="color: red; display: block; margin-top: 10px;">Please resolve the error to continue.</span>');
                                $form.trigger('intersoccer_update_button_state');
                            }
                        },
                        error: function(xhr) {
                            clearTimeout(loadingTimeout);
                            console.error('InterSoccer: Failed to fetch players:', xhr.status, xhr.responseText);
                            $playerContent.html('<p>Error loading players: ' + (xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data.message : 'Request failed') + '</p>');
                            $playerContent.append('<span class="intersoccer-attendee-notification" style="color: red; display: block; margin-top: 10px;">Please resolve the error to continue.</span>');
                            $form.trigger('intersoccer_update_button_state');
                        }
                    });
                } else {
                    // For logged-out users, keep notification visible
                    $form.find('.intersoccer-attendee-notification').show();
                    $form.trigger('intersoccer_update_button_state');
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

// Validate player/day selection on form submission and handle price updates
add_action('wp_footer', function () {
    if (!is_product()) {
        return;
    }
    global $product;
    $product_id = $product->get_id();
    $product_type = intersoccer_get_product_type($product_id);
?>
    <script>
        jQuery(document).ready(function($) {
            // Find the product form
            var $form = $('form.cart');
            if (!$form.length) {
                $form = $('.woocommerce-product-details form.cart, .product form.cart, .single-product form.cart');
            }
            if ($form.length) {
                // Handle form submission
                $form.on('submit', function(e) {
                    var playerId = $form.find('.player-select').val();
                    if (!playerId) {
                        e.preventDefault();
                        $form.find('.intersoccer-player-selection .error-message').text('Please select an attendee.').show();
                        $form.find('.intersoccer-attendee-notification').show();
                        setTimeout(() => $form.find('.intersoccer-player-selection .error-message').hide(), 5000);
                        console.log('InterSoccer: Form submission blocked - no attendee selected');
                        return false;
                    }
                    var bookingType = $form.find('select[name="attribute_pa_booking-type"]').val() || $form.find('input[name="attribute_pa_booking-type"]').val();
                    if (bookingType === 'single-days') {
                        var selectedDays = $form.find('input[name="camp_days[]"]').map(function() { return $(this).val(); }).get();
                        if (selectedDays.length === 0) {
                            e.preventDefault();
                            $form.find('.intersoccer-day-selection .error-message').text('Please select at least one day.').show();
                            setTimeout(() => $form.find('.intersoccer-day-selection .error-message').hide(), 5000);
                            console.log('InterSoccer: Form submission blocked - no days selected');
                            return false;
                        } else {
                            console.log('InterSoccer: Submitting with selected days:', selectedDays);
                        }
                    }
                    // Ensure quantity is 1 for camps and courses
                    var productType = '<?php echo $product_type; ?>';
                    if (productType === 'camp' || productType === 'course') {
                        var $quantityInput = $form.find('input[name="quantity"], .quantity input[type="number"], .qty');
                        if ($quantityInput.val() !== '1') {
                            console.log('InterSoccer: Forcing quantity to 1 on form submission for product type: ' + productType);
                            $quantityInput.val(1);
                        }
                    }
                    console.log('InterSoccer: Form submission allowed - attendee selected:', playerId);
                });

                // Handle click on disabled Add to Cart button
                $form.find('button.single_add_to_cart_button').on('click', function(e) {
                    if ($(this).prop('disabled')) {
                        var playerId = $form.find('.player-select').val();
                        if (!playerId) {
                            $form.find('.intersoccer-attendee-notification').show();
                            console.log('InterSoccer: Disabled Add to Cart button clicked - showing attendee notification');
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
                    console.log('InterSoccer: Booking type changed to:', bookingType);

                    if (bookingType !== 'single-days') {
                        $daySelection.hide();
                        $form.find('input[name="camp_days[]"]').remove();
                        console.log('InterSoccer: Reset days selection for booking type: ' + bookingType);
                        // Trigger price update with no days
                        updatePrice([]);
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
                                // Trigger price update
                                updatePrice(selectedDays);
                            });
                        } else {
                            console.warn('InterSoccer: No preloaded days available, triggering AJAX fetch');
                            $form.trigger('check_variations');
                        }
                    }
                    // Update button state and notification
                    $form.trigger('intersoccer_update_button_state');
                }).trigger('change'); // Trigger on page load to set initial state

                // Handle variation change to update price
                $form.on('found_variation', function(event, variation) {
                    console.log('InterSoccer: found_variation event triggered, variation:', variation);
                    var selectedDays = $form.find('input[name="camp_days[]"]').map(function() { return $(this).val(); }).get();
                    var variationId = variation ? variation.variation_id : 0;
                    var productId = <?php echo json_encode($product_id); ?>;
                    console.log('InterSoccer: Triggering price update with variation_id:', variationId, 'selected days:', selectedDays);
                    updatePrice(selectedDays, variationId);
                    // Update button state
                    $form.trigger('intersoccer_update_button_state');
                });

                // Function to update price via AJAX
                function updatePrice(selectedDays, variationId) {
                    var productId = <?php echo json_encode($product_id); ?>;
                    var bookingType = $form.find('select[name="attribute_pa_booking-type"]').val() || $form.find('input[name="attribute_pa_booking-type"]').val();
                    if (!variationId) {
                        variationId = $form.find('input[name="variation_id"]').val() || 0;
                    }

                    console.log('InterSoccer: updatePrice called with product_id:', productId, 'variation_id:', variationId, 'selectedDays:', selectedDays, 'bookingType:', bookingType);

                    if (bookingType === 'single-days' && selectedDays.length > 0 && variationId) {
                        $.ajax({
                            url: intersoccerCheckout.ajax_url,
                            type: 'POST',
                            data: {
                                action: 'intersoccer_calculate_dynamic_price',
                                nonce: intersoccerCheckout.nonce,
                                product_id: productId,
                                variation_id: variationId,
                                camp_days: selectedDays
                            },
                            success: function(response) {
                                console.log('InterSoccer: Price update response:', response);
                                if (response.success && response.data.price) {
                                    var $priceContainer = $form.find('.woocommerce-variation-price .amount, .product-price .amount, .price .amount');
                                    if ($priceContainer.length) {
                                        $priceContainer.html(response.data.price);
                                        console.log('InterSoccer: Updated price display to:', response.data.price);
                                    } else {
                                        console.error('InterSoccer: Price container not found for update. Tried selectors: .woocommerce-variation-price .amount, .product-price .amount, .price .amount');
                                    }
                                } else {
                                    console.error('InterSoccer: Failed to update price:', response.data ? response.data.message : 'Unknown error');
                                }
                            },
                            error: function(xhr) {
                                console.error('InterSoccer: Price update AJAX failed:', xhr.status, xhr.responseText);
                            }
                        });
                    } else {
                        console.log('InterSoccer: Skipping price update - conditions not met: bookingType:', bookingType, 'selectedDays:', selectedDays, 'variationId:', variationId);
                    }
                }

                // Function to update button state and notification
                function updateButtonState() {
                    var $addToCartButton = $form.find('button.single_add_to_cart_button');
                    var playerSelected = $form.find('.player-select').val() !== '' && $form.find('.player-select').val() !== null;
                    var bookingType = $form.find('select[name="attribute_pa_booking-type"]').val() || $form.find('input[name="attribute_pa_booking-type"]').val();
                    var daysSelected = bookingType === 'single-days' ? $form.find('.intersoccer-day-checkboxes input:checked').length > 0 : true;
                    var isLoggedIn = intersoccerCheckout.user_id && intersoccerCheckout.user_id !== '0';

                    if (playerSelected && daysSelected) {
                        $addToCartButton.prop('disabled', false);
                        $form.find('.intersoccer-attendee-notification').hide();
                        console.log('InterSoccer: Add to Cart button enabled - player selected:', playerSelected, ', days selected:', daysSelected);
                    } else {
                        $addToCartButton.prop('disabled', true);
                        if (!playerSelected) {
                            if (isLoggedIn) {
                                $form.find('.intersoccer-attendee-notification').text('Please select an attendee to add to cart.').show();
                            } else {
                                $form.find('.intersoccer-attendee-notification').text('Please log in or register to select an attendee.').show();
                            }
                            console.log('InterSoccer: Add to Cart button disabled - no attendee selected, logged in:', isLoggedIn);
                        } else if (!daysSelected) {
                            $form.find('.intersoccer-attendee-notification').hide();
                            console.log('InterSoccer: Add to Cart button disabled - no days selected for single-days booking');
                        }
                    }
                }

                // Custom event to update button state
                $form.on('intersoccer_update_button_state', updateButtonState);

                // Update button state on player selection change
                $form.find('.player-select').on('change', function() {
                    console.log('InterSoccer: Player selection changed, updating button state');
                    $form.trigger('intersoccer_update_button_state');
                });

                // Update button state on day selection change
                $form.find('.intersoccer-day-checkboxes input').on('change', function() {
                    console.log('InterSoccer: Day selection changed, updating button state');
                    $form.trigger('intersoccer_update_button_state');
                });

                // Initial button state check
                $form.trigger('intersoccer_update_button_state');
            } else {
                console.error('InterSoccer: Product form not found for validation and price update');
            }
        });
    </script>
<?php
});
?>
