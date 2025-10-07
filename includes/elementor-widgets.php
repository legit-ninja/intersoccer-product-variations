<?php
/**
 * File: elementor-widgets.php
 * Description: Extends Elementor's Single Product widget to inject player and day selection fields 
 * and display camp and course attributes.
 * Dependencies: woocommerce
 * Author: Jeremy Lee
 * 
 * MINIMAL ENHANCEMENT: Only fixes player persistence and button state issues
 */
if (!defined('ABSPATH')) exit;

add_action('wp_footer', function () {
    if (!is_product()) return;
    global $product;
    if (!is_a($product, 'WC_Product')) {
        error_log('InterSoccer: No valid product found');
        return;
    }

    $product_id = $product->get_id();
    $user_id = get_current_user_id();
    $is_variable = $product->is_type('variable');
    $product_type = intersoccer_get_product_type($product_id);
    error_log("InterSoccer: Initializing wp_footer for product ID: $product_id, type: $product_type");

    // Preload days for Camps
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
                $preloaded_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
            }
        }
    }
    error_log('InterSoccer: Preloaded days for product ' . $product_id . ': ' . json_encode($preloaded_days));

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

    // Day selection HTML
    $day_selection_html = '';
    if ($is_variable) {
        ob_start();
    ?>
        <tr class="intersoccer-day-selection intersoccer-injected" style="display: none;" data-preloaded-days="<?php echo esc_attr(json_encode($preloaded_days, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS)); ?>">
            <th><label><?php esc_html_e('Select Days', 'intersoccer-player-management'); ?></label></th>
            <td>
                <div class="intersoccer-day-checkboxes"></div> <!-- Added this div for JS to append checkboxes -->
                <div class="intersoccer-day-notification" style="margin-top: 10px;"></div>
                <span class="error-message" style="color: red; display: none;"></span>
            </td>
        </tr>
<?php
        $day_selection_html = ob_get_clean();
    }
?>
    <script>
        jQuery(document).ready(function($) {
            var $form = $('form.cart, form.variations_form, .woocommerce-product-details form, .single-product form');
            if (!$form.length) {
                console.error('InterSoccer: Product form not found');
                return;
            }
            
            var selectedDays = [];
            var lastVariation = null;
            var lastVariationId = 0;
            var productType = '<?php echo esc_js($product_type); ?>';
            
            // ENHANCEMENT: Player persistence state management
            var playerPersistence = {
                selectedPlayer: '',
                
                setPlayer: function(player) {
                    this.selectedPlayer = player;
                    // Store in DOM for persistence
                    $('body').attr('data-intersoccer-player', player);
                    console.log('InterSoccer: Player stored:', player);
                },
                
                getPlayer: function() {
                    // Try multiple sources
                    if (this.selectedPlayer) return this.selectedPlayer;
                    var domPlayer = $('body').attr('data-intersoccer-player');
                    if (domPlayer) {
                        this.selectedPlayer = domPlayer;
                        return domPlayer;
                    }
                    return '';
                },
                
                restorePlayer: function() {
                    var targetPlayer = this.getPlayer();
                    if (targetPlayer) {
                        var $select = $form.find('.player-select');
                        if ($select.length && $select.val() !== targetPlayer) {
                            $select.val(targetPlayer);
                            console.log('InterSoccer: Player restored to:', targetPlayer);
                            return true;
                        }
                    }
                    return false;
                }
            };

            // Inject fields
            function injectFields(retryCount = 0, maxRetries = 10) {
                if ($form.find('.intersoccer-player-selection').length > 0) {
                    return;
                }

                var $variationsTable = $form.find('.variations, .woocommerce-variation');
                if ($variationsTable.length) {
                    var $tbody = $variationsTable.find('tbody, .variations_table');
                    if ($tbody.length) {
                        $tbody.append(<?php echo json_encode($player_selection_html, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>);
                        <?php if ($day_selection_html) : ?>
                            $tbody.append(<?php echo json_encode($day_selection_html, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>);
                        <?php endif; ?>
                    } else {
                        $variationsTable.append(<?php echo json_encode($player_selection_html, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>);
                        <?php if ($day_selection_html) : ?>
                            $variationsTable.append(<?php echo json_encode($day_selection_html, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>);
                        <?php endif; ?>
                    }
                } else {
                    $form.prepend(<?php echo json_encode($player_selection_html, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>);
                    <?php if ($day_selection_html) : ?>
                        $form.prepend(<?php echo json_encode($day_selection_html, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>);
                    <?php endif; ?>
                }

                $form.find('.intersoccer-player-selection').css('display', 'table-row');
                
                // Fetch players
                if (intersoccerCheckout.user_id && intersoccerCheckout.user_id !== '0') {
                    var $playerContent = $form.find('.intersoccer-player-content');
                    var loadingTimeout = setTimeout(function() {
                        $playerContent.find('.intersoccer-loading-players').html('<p>Error: Unable to load players. Please try refreshing the page.</p>');
                        console.error('InterSoccer: Player loading timed out after 10s');
                    }, 10000);

                    $.ajax({
                        url: intersoccerCheckout.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'intersoccer_get_user_players',
                            nonce: intersoccerCheckout.nonce,
                            user_id: intersoccerCheckout.user_id
                        },
                        dataType: 'json',
                        success: function(response, textStatus, xhr) {
                            clearTimeout(loadingTimeout);
                            try {
                                if (response && response.success && Array.isArray(response.data.players) && response.data.players.length > 0) {
                                    var $select = $('<select name="player_assignment" id="player_assignment_select" class="player-select intersoccer-player-select"></select>');
                                    $select.append('<option value=""><?php esc_html_e('Select an Attendee', 'intersoccer-player-management'); ?></option>');
                                    $.each(response.data.players, function(index, player) {
                                        $select.append('<option value="' + index + '">' + player.first_name + ' ' + player.last_name + '</option>');
                                    });
                                    $playerContent.html($select);
                                    $playerContent.append('<span class="error-message" style="color: red; display: none;"></span>');
                                    $playerContent.append('<span class="intersoccer-attendee-notification" style="color: red; display: none; margin-top: 10px;">Please select an attendee to add to cart.</span>');
                                    
                                    // ENHANCEMENT: Restore player selection after loading
                                    setTimeout(function() {
                                        playerPersistence.restorePlayer();
                                        $form.trigger('intersoccer_update_button_state');
                                    }, 100);
                                    
                                    // ENHANCEMENT: Player change handler with persistence
                                    $select.on('change', function() {
                                        var selectedPlayer = $(this).val();
                                        playerPersistence.setPlayer(selectedPlayer);
                                        $form.trigger('intersoccer_update_button_state');
                                    });
                                    
                                    $form.trigger('intersoccer_update_button_state');
                                    // Re-trigger variation check
                                    $form.trigger('check_variations');
                                } else {
                                    $playerContent.html('<p>No players registered. <a href="<?php echo esc_url(wc_get_account_endpoint_url('manage-players')); ?>">Add a player</a>.</p>');
                                    $playerContent.append('<span class="intersoccer-attendee-notification" style="color: red; display: block; margin-top: 10px;">Please add a player to continue.</span>');
                                    $form.trigger('intersoccer_update_button_state');
                                }
                            } catch (e) {
                                console.error('InterSoccer: Player response parsing error:', e.message, 'response:', response);
                                $playerContent.html('<p>Error loading players: Invalid response format. Please try again.</p>');
                                $form.trigger('intersoccer_update_button_state');
                            }
                        },
                        error: function(xhr, textStatus, errorThrown) {
                            clearTimeout(loadingTimeout);
                            console.error('InterSoccer: Player AJAX error details:', xhr.status, textStatus, errorThrown, 'response:', xhr.responseText);
                            $playerContent.html('<p>Error loading players: ' + (xhr.responseJSON?.data?.message || errorThrown || 'Request failed') + '</p>');
                            $playerContent.append('<span class="intersoccer-attendee-notification" style="color: red; display: block; margin-top: 10px;">Please resolve the error to continue.</span>');
                            $form.trigger('intersoccer_update_button_state');
                        }
                    });
                } else {
                    $form.find('.intersoccer-attendee-notification').show();
                    $form.trigger('intersoccer_update_button_state');
                }

                // Trigger variation check after injection
                setTimeout(function() {
                    $form.trigger('check_variations');
                }, 500);
            }

            // Check if variation is fully configured
            function isFullyConfigured(variation) {
                var requiredAttrs = ['attribute_pa_intersoccer-venues', 'attribute_pa_course-day', 'attribute_pa_age-group', 'attribute_pa_course-times'];
                return variation && requiredAttrs.every(attr => variation.attributes[attr] && variation.attributes[attr] !== '');
            }

            // Handle form submission
            $form.on('submit', function(e) {
                var playerId = $form.find('.player-select').val();
                if (!playerId) {
                    e.preventDefault();
                    $form.find('.intersoccer-player-selection .error-message').text('Please select an attendee.').show();
                    $form.find('.intersoccer-attendee-notification').show();
                    setTimeout(() => $form.find('.intersoccer-player-selection .error-message').hide(), 5000);
                    return false;
                }
                var bookingType = $form.find('select[name="attribute_pa_booking-type"]').val() || $form.find('input[name="attribute_pa_booking-type"]').val() || '';
                if (bookingType === 'single-days') {
                    var formDays = $form.find('input[name="camp_days[]"]').map(function() { return $(this).val(); }).get();
                    if (formDays.length === 0) {
                        e.preventDefault();
                        $form.find('.intersoccer-day-selection .error-message').text('Please select at least one day.').show();
                        setTimeout(() => $form.find('.intersoccer-day-selection .error-message').hide(), 5000);
                        return false;
                    }
                }
                if (productType === 'camp' || productType === 'course') {
                    var $quantityInput = $form.find('input[name="quantity"], .quantity input[type="number"], .qty');
                    if ($quantityInput.val() !== '1') {
                        $quantityInput.val(1);
                    }
                }
            });

            // Handle disabled button click
            $form.find('button.single_add_to_cart_button').on('click', function(e) {
                if ($(this).prop('disabled')) {
                    var playerId = $form.find('.player-select').val();
                    if (!playerId) {
                        $form.find('.intersoccer-attendee-notification').show();
                    }
                }
            });

            // Render day checkboxes
            function renderDayCheckboxes(bookingType, $daySelection, $dayCheckboxes, $form) {
                var preloadedDays = JSON.parse($daySelection.attr('data-preloaded-days') || '[]');
                
                if (bookingType === 'single-days' && preloadedDays.length > 0) {
                    $daySelection.show();
                    var currentChecked = $dayCheckboxes.find('input.intersoccer-day-checkbox:checked').map(function() { return $(this).val(); }).get();
                    $dayCheckboxes.empty();
                    preloadedDays.forEach((day) => {
                        var isChecked = currentChecked.includes(day) || selectedDays.includes(day) ? 'checked' : '';
                        $dayCheckboxes.append(`
                            <label style="margin-right: 10px; display: inline-block;">
                                <input type="checkbox" name="camp_days_temp[]" value="${day}" class="intersoccer-day-checkbox" ${isChecked}> ${day}
                            </label>
                        `);
                    });
                    $dayCheckboxes.find('input.intersoccer-day-checkbox').prop('disabled', false);
                    
                    $dayCheckboxes.find('input.intersoccer-day-checkbox').off('change').on('change', function() {
                        var $checkbox = $(this);
                        
                        selectedDays = $dayCheckboxes.find('input.intersoccer-day-checkbox:checked').map(function() { return $(this).val(); }).get();
                        $form.find('input[name="camp_days[]"]').remove();
                        selectedDays.forEach((day) => {
                            $form.append(`<input type="hidden" name="camp_days[]" value="${day}" class="intersoccer-camp-day-input">`);
                        });
                        updatePrice(selectedDays);
                        $form.trigger('intersoccer_update_button_state');
                    });
                } else {
                    $daySelection.hide();
                    $form.find('input[name="camp_days[]"]').remove();
                    selectedDays = [];
                }
            }

            // Handle booking type change
            $form.find('select[name="attribute_pa_booking-type"]').on('change', function() {
                var bookingType = $(this).val();
                var $daySelection = $form.find('.intersoccer-day-selection');
                var $dayCheckboxes = $form.find('.intersoccer-day-checkboxes');
                renderDayCheckboxes(bookingType, $daySelection, $dayCheckboxes, $form);
                updatePrice(selectedDays);
                $form.trigger('intersoccer_update_button_state');
            });

            // Handle variation change
            $form.on('found_variation', function(event, variation) {
                var variationId = variation && variation.variation_id ? variation.variation_id : 0;
                var productId = <?php echo json_encode($product_id); ?>;
                var productType = '<?php echo esc_js($product_type); ?>';
                var bookingType = variation && variation.attributes['attribute_pa_booking-type'] ? variation.attributes['attribute_pa_booking-type'] : ($form.find('select[name="attribute_pa_booking-type"]').val() || $form.find('input[name="attribute_pa_booking-type"]').val() || '');
                var $daySelection = $form.find('.intersoccer-day-selection');
                var $dayCheckboxes = $form.find('.intersoccer-day-checkboxes');
                
                // ENHANCEMENT: Preserve player selection during variation changes
                setTimeout(function() {
                    playerPersistence.restorePlayer();
                    $form.trigger('intersoccer_update_button_state');
                }, 50);
                
                if (bookingType === 'single-days') {
                    renderDayCheckboxes(bookingType, $daySelection, $dayCheckboxes, $form);
                }

                // Display course attributes
                var $attributesDisplay = $form.find('.intersoccer-attributes');
                if (!$attributesDisplay.length) {
                    $form.find('.single_add_to_cart_button').before('<div class="intersoccer-attributes"></div>');
                    $attributesDisplay = $form.find('.intersoccer-attributes');
                }
                if (productType === 'course' && variationId > 0 && isFullyConfigured(variation)) {
                    var html = '';
                    if (variation.course_start_date) html += '<p><strong>Start Date:</strong> ' + variation.course_start_date + '</p>';
                    if (variation.end_date) html += '<p><strong>End Date:</strong> ' + variation.end_date + '</p>';
                    if (variation.course_holiday_dates && variation.course_holiday_dates.length > 0) html += '<p><strong>Holidays (No Session):</strong> ' + variation.course_holiday_dates.join(', ') + '</p>';
                    if (variation.remaining_sessions) html += '<p><strong>Remaining Sessions:</strong> ' + variation.remaining_sessions + '</p>';
                    if (variation.discount_note) html += '<p><strong>Discount:</strong> ' + variation.discount_note + '</p>';
                    $attributesDisplay.html(html).show().css({'display': 'block', 'opacity': 1});
                } else {
                    $attributesDisplay.html('').hide();
                }

                updatePrice(selectedDays, variationId);
                $form.trigger('intersoccer_update_button_state');
                lastVariation = variation;
                lastVariationId = variationId;
            });

            // Handle reset
            $form.on('reset_data', function() {
                $form.find('.intersoccer-attributes').hide();
                $form.find('.intersoccer-dates').remove();
                selectedDays = [];
                
                // ENHANCEMENT: Restore player after reset
                setTimeout(function() {
                    playerPersistence.restorePlayer();
                    $form.trigger('intersoccer_update_button_state');
                }, 100);
            });

            // MutationObserver for re-display
            var attributesObserver = new MutationObserver(function(mutations) {
                var shouldRedisplay = false;
                mutations.forEach(function(mutation) {
                    if (mutation.type === 'childList' || mutation.type === 'attributes') {
                        if ($form.find('.intersoccer-attributes:visible').length === 0 && lastVariation && lastVariationId > 0 && productType === 'course' && isFullyConfigured(lastVariation)) {
                            shouldRedisplay = true;
                        }
                    }
                });
                if (shouldRedisplay) {
                    $form.trigger('found_variation', [lastVariation]);
                }
            });
            attributesObserver.observe($form[0], { childList: true, subtree: true, attributes: true, attributeFilter: ['style', 'class'] });

            var isUpdatingPrice = false;

            // Update price
            function updatePrice(selectedDays, variationId) {
                var productId = <?php echo json_encode($product_id); ?>;
                var bookingType = $form.find('select[name="attribute_pa_booking-type"]').val() || $form.find('input[name="attribute_pa_booking-type"]').val() || '';
                if (!variationId) {
                    variationId = $form.find('input[name="variation_id"]').val() || 0;
                }
                
                if (bookingType === 'single-days' && selectedDays.length > 0 && variationId) {
                    isUpdatingPrice = true;
                    updateButtonState();
                    
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
                        dataType: 'json',
                        success: function(response) {
                            if (response.success && response.data.price) {
                                $('.woocommerce-variation-price .price').html(response.data.price);
                                // Trigger event for late pickup integration
                                $(document).trigger('intersoccer_price_updated');
                            }
                            isUpdatingPrice = false;
                            updateButtonState();
                        },
                        error: function(xhr, textStatus, errorThrown) {
                            console.error('InterSoccer: Price update failed:', xhr.status, textStatus, errorThrown);
                            isUpdatingPrice = false;
                            updateButtonState();
                        }
                    });
                }
            }

            // ENHANCEMENT: Improved button state management
            function updateButtonState() {
                var $addToCartButton = $form.find('button.single_add_to_cart_button');
                var playerSelected = $form.find('.player-select').val() !== '' && $form.find('.player-select').val() !== null;
                var bookingType = $form.find('select[name="attribute_pa_booking-type"]').val() || $form.find('input[name="attribute_pa_booking-type"]').val() || '';
                var formDays = $form.find('input[name="camp_days[]"]').map(function() { return $(this).val(); }).get();
                var daysSelected = bookingType === 'single-days' ? formDays.length > 0 : true;
                var isLoggedIn = intersoccerCheckout.user_id && intersoccerCheckout.user_id !== '0';
                var $expressContainer = $('.wc-stripe-product-checkout-container');
                
                console.log('InterSoccer: Button state check - player:', playerSelected, 'days:', daysSelected, 'updating:', isUpdatingPrice);
                
                if (playerSelected && daysSelected && !isUpdatingPrice) {
                    $addToCartButton.prop('disabled', false);
                    $form.find('.intersoccer-attendee-notification').hide();
                    $expressContainer.show();
                } else {
                    $expressContainer.hide();
                    $addToCartButton.prop('disabled', true);
                    if (!playerSelected) {
                        $form.find('.intersoccer-attendee-notification').text(isLoggedIn ? 'Please select an attendee.' : 'Please log in or register to select an attendee.').show();
                    } else if (!daysSelected) {
                        $form.find('.intersoccer-attendee-notification').hide();
                    } else if (isUpdatingPrice) {
                        $form.find('.intersoccer-attendee-notification').text('Updating price...').show();
                    }
                }
            }

            $form.on('intersoccer_update_button_state', updateButtonState);
            $form.find('.player-select').on('change', function() {
                $form.trigger('intersoccer_update_button_state');
                $form.trigger('check_variations');
            });
            $form.find('.intersoccer-day-checkboxes input').on('change', function() {
                $form.trigger('intersoccer_update_button_state');
            });

            // Initial setup
            var selectedDays = [];
            var lastVariation = null;
            var lastVariationId = 0;
            var initialBookingType = $form.find('select[name="attribute_pa_booking-type"]').val() || $form.find('input[name="attribute_pa_booking-type"]').val() || '';
            var $daySelection = $form.find('.intersoccer-day-selection');
            var $dayCheckboxes = $form.find('.intersoccer-day-checkboxes');
            if (productType === 'camp' && initialBookingType === 'single-days') {
                renderDayCheckboxes(initialBookingType, $daySelection, $dayCheckboxes, $form);
            }

            // Initial injection
            injectFields();

            // Retry injection
            setTimeout(() => injectFields(), 10000);
        });
    </script>
<?php
});
?>