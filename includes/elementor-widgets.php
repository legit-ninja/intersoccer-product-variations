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

add_action('woocommerce_before_single_product', function () {
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
        error_log('InterSoccer: Checking pa_days-of-week attribute for product ' . $product_id);
        error_log('InterSoccer: Product attributes: ' . json_encode(array_keys($attributes)));
        
        if (isset($attributes['pa_days-of-week']) && $attributes['pa_days-of-week'] instanceof WC_Product_Attribute) {
            // Get terms with both names and slugs for multilingual support
            $terms = wc_get_product_terms($product_id, 'pa_days-of-week', ['fields' => 'all']);
            error_log('InterSoccer: Retrieved terms for pa_days-of-week: ' . json_encode($terms));
            
            if (!empty($terms)) {
                // Map of day slugs to English names (assuming slugs are in English)
                $day_map = [
                    'monday' => 'Monday',
                    'tuesday' => 'Tuesday', 
                    'wednesday' => 'Wednesday',
                    'thursday' => 'Thursday',
                    'friday' => 'Friday'
                ];
                
                $english_days = [];
                foreach ($terms as $term) {
                    $slug = strtolower($term->slug);
                    error_log('InterSoccer: Processing term: ' . $term->name . ' (slug: ' . $term->slug . ')');
                    if (isset($day_map[$slug])) {
                        $english_days[] = $day_map[$slug];
                        error_log('InterSoccer: Mapped to English: ' . $day_map[$slug]);
                    } else {
                        error_log('InterSoccer: No mapping found for slug: ' . $slug);
                    }
                }
                
                if (!empty($english_days)) {
                    $day_order = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
                    usort($english_days, function ($a, $b) use ($day_order) {
                        $pos_a = array_search($a, $day_order);
                        $pos_b = array_search($b, $day_order);
                        return $pos_a - $pos_b;
                    });
                    $preloaded_days = $english_days;
                    error_log('InterSoccer: Final preloaded days: ' . json_encode($preloaded_days));
                } else {
                    // Fallback to default English days
                    $preloaded_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
                    error_log('InterSoccer: Using fallback days: ' . json_encode($preloaded_days));
                }
            } else {
                // Fallback to default English days
                $preloaded_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
                error_log('InterSoccer: No terms found, using fallback days: ' . json_encode($preloaded_days));
            }
        } else {
            error_log('InterSoccer: pa_days-of-week attribute not found or not valid');
        }
    } else {
        error_log('InterSoccer: Product is not a camp, skipping day preloading');
    }
    error_log('InterSoccer: Preloaded days for product ' . $product_id . ': ' . json_encode($preloaded_days));
    error_log('InterSoccer: Product attributes for ' . $product_id . ': ' . json_encode(array_keys($product->get_attributes())));
    error_log('InterSoccer: Product type: ' . $product_type);

    // Player selection HTML
    ob_start();
?>
    <tr class="intersoccer-player-selection intersoccer-injected">
        <th><label for="player_assignment_select"><?php esc_html_e('Select an Attendee', 'intersoccer-product-variations'); ?></label></th>
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

    // Day selection HTML - Always generate for all products
    $day_selection_html = '';
    ob_start();
?>
    <tr class="intersoccer-day-selection intersoccer-injected" style="display: none;" data-preloaded-days="<?php echo esc_attr(json_encode($preloaded_days, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS)); ?>">
        <th><label><?php esc_html_e('Select Days', 'intersoccer-product-variations'); ?></label></th>
        <td>
            <div class="intersoccer-day-checkboxes"></div> <!-- Added this div for JS to append checkboxes -->
            <div class="intersoccer-day-notification" style="margin-top: 10px;"></div>
            <span class="error-message" style="color: red; display: none;"></span>
        </td>
    </tr>
<?php
    $day_selection_html = ob_get_clean();
?>
    <script>
        jQuery(document).ready(function($) {
            var $form = $('form.cart, form.variations_form, .woocommerce-product-details form, .single-product form');
            if (!$form.length) {
                console.error('InterSoccer: Product form not found');
                return;
            }
            
            console.log('InterSoccer Debug: Found form:', $form);
            console.log('InterSoccer Debug: Form classes:', $form.attr('class'));
            console.log('InterSoccer Debug: Form ID:', $form.attr('id'));
            console.log('InterSoccer Debug: All forms on page:', $('form').length);
            $('form').each(function(i) {
                console.log('InterSoccer Debug: Form', i, 'classes:', $(this).attr('class'), 'ID:', $(this).attr('id'));
            });
            
            console.log('InterSoccer Debug: Product type from PHP:', '<?php echo esc_js($product_type); ?>');
            console.log('InterSoccer Debug: Is variable product:', '<?php echo $is_variable ? 'yes' : 'no'; ?>');
            
            // Debug: Check all select elements in the form
            console.log('InterSoccer Debug: All select elements in form:', $form.find('select').length);
            $form.find('select').each(function() {
                console.log('InterSoccer Debug: Found select:', $(this).attr('name'), 'ID:', $(this).attr('id'), 'value:', $(this).val());
            });
            
            // Debug: Check all input elements
            console.log('InterSoccer Debug: All input elements in form:', $form.find('input').length);
            $form.find('input[type="hidden"]').each(function() {
                console.log('InterSoccer Debug: Found hidden input:', $(this).attr('name'), 'value:', $(this).val());
            });
            
            // Debug: Check for booking type specifically
            var $bookingTypeSelect = $form.find('select[name="attribute_pa_booking-type"], select[name="attribute_booking-type"]');
            console.log('InterSoccer Debug: Booking type select found:', $bookingTypeSelect.length > 0);
            if ($bookingTypeSelect.length > 0) {
                console.log('InterSoccer Debug: Booking type select value:', $bookingTypeSelect.val());
                var options = $bookingTypeSelect.find('option').map(function() { 
                    return { value: $(this).val(), text: $(this).text().trim() }; 
                }).get();
                console.log('InterSoccer Debug: Booking type options:', options);
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
                        $tbody.append(<?php echo json_encode($day_selection_html, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>);
                    } else {
                        $variationsTable.append(<?php echo json_encode($player_selection_html, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>);
                        $variationsTable.append(<?php echo json_encode($day_selection_html, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>);
                    }
                } else {
                    $form.prepend(<?php echo json_encode($player_selection_html, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>);
                    $form.prepend(<?php echo json_encode($day_selection_html, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>);
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
                            console.log('InterSoccer: AJAX success response:', response);
                            console.log('InterSoccer: Response type:', typeof response);
                            console.log('InterSoccer: Response success:', response && response.success);
                            console.log('InterSoccer: Response data:', response && response.data);
                            try {
                                // More defensive checks
                                if (!response) {
                                    throw new Error('No response received');
                                }
                                if (typeof response !== 'object') {
                                    throw new Error('Response is not an object: ' + typeof response);
                                }
                                if (!response.success) {
                                    throw new Error('Response success is false: ' + JSON.stringify(response));
                                }
                                if (!response.data) {
                                    throw new Error('Response data is missing: ' + JSON.stringify(response));
                                }
                                if (!Array.isArray(response.data.players)) {
                                    throw new Error('Response data.players is not an array: ' + typeof response.data.players + ' - ' + JSON.stringify(response.data));
                                }
                                
                                if (response.data.players.length > 0) {
                                    var $select = $('<select name="player_assignment" id="player_assignment_select" class="player-select intersoccer-player-select"></select>');
                                    $select.append('<option value=""><?php esc_html_e('Select an Attendee', 'intersoccer-product-variations'); ?></option>');
                                    $.each(response.data.players, function(index, player) {
                                        if (player && player.first_name && player.last_name) {
                                            $select.append('<option value="' + index + '">' + player.first_name + ' ' + player.last_name + '</option>');
                                        } else {
                                            console.warn('InterSoccer: Invalid player data:', player);
                                        }
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
                                    console.log('InterSoccer: No players found in response');
                                    $playerContent.html('<p>No players registered. <a href="<?php echo esc_url(wc_get_account_endpoint_url('manage-players')); ?>">Add a player</a>.</p>');
                                    $playerContent.append('<span class="intersoccer-attendee-notification" style="color: red; display: block; margin-top: 10px;">Please add a player to continue.</span>');
                                    $form.trigger('intersoccer_update_button_state');
                                }
                            } catch (e) {
                                console.error('InterSoccer: Player response parsing error:', e.message, 'response:', response);
                                $playerContent.html('<p>Error loading players: ' + e.message + '. Please try again.</p>');
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

            // Render day checkboxes
            function renderDayCheckboxes(bookingType, $daySelection, $dayCheckboxes, $form) {
                var preloadedDays = JSON.parse($daySelection.attr('data-preloaded-days') || '[]');

                console.log('InterSoccer Debug: renderDayCheckboxes called');
                console.log('  bookingType:', bookingType);
                console.log('  preloadedDays:', preloadedDays);
                console.log('  preloadedDays.length:', preloadedDays.length);
                console.log('  productType:', productType);
                console.log('  intersoccerDayTranslations available:', typeof intersoccerDayTranslations !== 'undefined');
                if (typeof intersoccerDayTranslations !== 'undefined') {
                    console.log('  intersoccerDayTranslations content:', intersoccerDayTranslations);
                }
                console.log('  $daySelection exists:', $daySelection.length > 0);
                console.log('  $dayCheckboxes exists:', $dayCheckboxes.length > 0);

                // Translation map for days - English to current language
                var dayTranslations = typeof intersoccerDayTranslations !== 'undefined' ? intersoccerDayTranslations : {
                    'Monday': 'Monday',
                    'Tuesday': 'Tuesday',
                    'Wednesday': 'Wednesday',
                    'Thursday': 'Thursday',
                    'Friday': 'Friday'
                };

                // Show day selection for camp products with single-day bookings only
                var isCamp = productType === 'camp';
                var isSingleDayBooking = bookingType === 'single-days' || 
                                       bookingType === 'à la journée' || 
                                       bookingType === 'a-la-journee' ||
                                       bookingType.toLowerCase().includes('single') || 
                                       bookingType.toLowerCase().includes('journée') ||
                                       bookingType.toLowerCase().includes('journee');
                
                console.log('  Condition: productType === "camp" ?', isCamp);
                console.log('  Condition: isSingleDayBooking ?', isSingleDayBooking);
                console.log('  Both conditions met?', isCamp && isSingleDayBooking);

                if (isCamp && isSingleDayBooking) {
                    console.log('InterSoccer Debug: Showing day selection for camp/single-days');
                    $daySelection.show();

                    // For single-day bookings, use all available days if no preloaded days
                    var daysToShow = preloadedDays.length > 0 ? preloadedDays : ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

                    var currentChecked = $dayCheckboxes.find('input.intersoccer-day-checkbox:checked').map(function() { return $(this).val(); }).get();
                    $dayCheckboxes.empty();
                    daysToShow.forEach((day) => {
                        var translatedDay = dayTranslations[day] || day; // Fallback to English if translation not found
                        var isChecked = currentChecked.includes(day) || selectedDays.includes(day) ? 'checked' : '';
                        $dayCheckboxes.append(`
                            <label style="margin-right: 10px; display: inline-block;">
                                <input type="checkbox" name="camp_days_temp[]" value="${day}" class="intersoccer-day-checkbox" ${isChecked}> ${translatedDay}
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
                        
                        // Update price for single-day camps
                        updateCampPrice($form, selectedDays);
                        
                        $form.trigger('intersoccer_update_button_state');
                        // Trigger WooCommerce variation check
                        $form.trigger('check_variations');
                    });
                } else {
                    console.log('InterSoccer Debug: Hiding day selection - conditions not met');
                    $daySelection.hide();
                    $form.find('input[name="camp_days[]"]').remove();
                    selectedDays = [];
                }
            }

            // Button state update handler - ensures player selection is required for ALL products
            $form.on('intersoccer_update_button_state', function() {
                var $button = $form.find('button.single_add_to_cart_button, input[type="submit"][name="add-to-cart"]');
                var playerId = $form.find('.player-select').val();
                var bookingType = $form.find('select[name="attribute_pa_booking-type"]').val() || 
                                $form.find('select[name="attribute_booking-type"]').val() || 
                                $form.find('select[id*="booking-type"]').val() || 
                                $form.find('input[name="attribute_pa_booking-type"]').val() || 
                                $form.find('input[name="attribute_booking-type"]').val() || '';
                var hasPlayer = playerId && playerId !== '';
                
                console.log('InterSoccer: Updating button state');
                console.log('  Player selected:', hasPlayer);
                console.log('  Booking type:', bookingType);
                
                // For variable products, also check if variation is selected
                var variationSelected = true;
                if ('<?php echo $is_variable ? 'yes' : 'no'; ?>' === 'yes') {
                    var variationId = $form.find('input[name="variation_id"]').val();
                    variationSelected = variationId && variationId !== '' && variationId !== '0';
                    console.log('  Variation selected:', variationSelected, 'ID:', variationId);
                }
                
                // For single-day bookings, check if days are selected
                var daysSelected = true;
                if (bookingType === 'single-days' || 
                    bookingType === 'à la journée' || 
                    bookingType === 'a-la-journee' ||
                    bookingType.toLowerCase().includes('single') || 
                    bookingType.toLowerCase().includes('journée') ||
                    bookingType.toLowerCase().includes('journee')) {
                    var selectedDaysCount = $form.find('input[name="camp_days[]"]').length;
                    daysSelected = selectedDaysCount > 0;
                    console.log('  Days selected for single-day booking:', daysSelected, 'count:', selectedDaysCount);
                }
                
                // Enable button only if player is selected AND (for variable products: variation is selected) AND (for single-day bookings: days are selected)
                var shouldEnable = hasPlayer && variationSelected && daysSelected;
                
                console.log('  Should enable button:', shouldEnable);
                
                if (shouldEnable) {
                    $button.prop('disabled', false).removeClass('disabled');
                    $form.find('.intersoccer-attendee-notification').hide();
                } else {
                    $button.prop('disabled', true).addClass('disabled');
                    if (!hasPlayer) {
                        $form.find('.intersoccer-attendee-notification').show();
                    }
                }
            });

            // Inject fields and initialize
            injectFields();

            // Initialize day selection elements
            var $daySelection = $form.find('.intersoccer-day-selection');
            var $dayCheckboxes = $form.find('.intersoccer-day-checkboxes');
            
            console.log('InterSoccer Debug: Day selection element found:', $daySelection.length);
            console.log('InterSoccer Debug: Day checkboxes element found:', $dayCheckboxes.length);
            
            // Initial render of day checkboxes
            var initialBookingType = $form.find('select[name="attribute_pa_booking-type"]').val() || 
                                   $form.find('select[name="attribute_booking-type"]').val() || 
                                   $form.find('select[id*="booking-type"]').val() || 
                                   $form.find('input[name="attribute_pa_booking-type"]').val() || 
                                   $form.find('input[name="attribute_booking-type"]').val() || '';
            console.log('InterSoccer Debug: Initial booking type detection:', initialBookingType);
            renderDayCheckboxes(initialBookingType, $daySelection, $dayCheckboxes, $form);

            // Handle booking type change
            $form.find('select[name="attribute_pa_booking-type"], select[name="attribute_booking-type"], select[id*="booking-type"]').on('change', function() {
                var bookingType = $(this).val();
                console.log('InterSoccer Debug: Booking type changed to:', bookingType);
                
                // Trigger WooCommerce variation check when booking type changes
                setTimeout(function() {
                    $form.trigger('check_variations');
                    $form.trigger('intersoccer_update_button_state');
                }, 100);
                
                renderDayCheckboxes(bookingType, $daySelection, $dayCheckboxes, $form);
            });

            // Monitor all WooCommerce variation events for debugging
            $form.on('woocommerce_variation_has_changed', function() {
                console.log('InterSoccer: woocommerce_variation_has_changed triggered');
            });
            
            $form.on('show_variation', function(event, variation) {
                console.log('InterSoccer: show_variation triggered:', variation);
            });
            
            $form.on('hide_variation', function() {
                console.log('InterSoccer: hide_variation triggered');
            });

            // Monitor WooCommerce variation changes
            $form.on('found_variation', function(event, variation) {
                console.log('InterSoccer: WooCommerce found variation:', variation);
                console.log('InterSoccer: Variation attributes:', variation.attributes);
                
                lastVariation = variation;
                lastVariationId = variation.variation_id;
                
                // Extract booking type from variation attributes
                var variationBookingType = '';
                if (variation.attributes) {
                    // Check for different possible attribute names
                    variationBookingType = variation.attributes['attribute_pa_booking-type'] || 
                                         variation.attributes['attribute_booking-type'] ||
                                         variation.attributes.attribute_pa_booking_type ||
                                         variation.attributes.attribute_booking_type || '';
                }
                
                console.log('InterSoccer: Booking type from variation:', variationBookingType);
                
                // Render day checkboxes based on the variation's booking type
                renderDayCheckboxes(variationBookingType, $daySelection, $dayCheckboxes, $form);
                
                // Update price for single-day bookings
                var isSingleDayBooking = variationBookingType === 'single-days' || 
                                       variationBookingType === 'à la journée' || 
                                       variationBookingType === 'a-la-journee' ||
                                       variationBookingType.toLowerCase().includes('single') || 
                                       variationBookingType.toLowerCase().includes('journée') ||
                                       variationBookingType.toLowerCase().includes('journee');
                if (isSingleDayBooking) {
                    updateCampPrice($form, selectedDays);
                }
                
                $form.trigger('intersoccer_update_button_state');
            });

            // Handle variation selection
            $form.on('found_variation', function(event, variation) {
                console.log('InterSoccer: Variation found:', variation);
                
                // Check if this is a single-day camp booking
                var bookingType = variation.attributes && variation.attributes['attribute_pa_booking-type'];
                console.log('InterSoccer: Booking type:', bookingType);
                
                if (bookingType === 'single-day' || bookingType === 'single-days' || bookingType === 'à la journée' || bookingType === 'a-la-journee' || 
                    (bookingType && (bookingType.includes('single') || bookingType.includes('journée') || bookingType.includes('journee')))) {
                    console.log('InterSoccer: Single-day camp detected, setting up price update');
                    
                    // Get currently selected days
                    var selectedDays = [];
                    $form.find('.intersoccer-day-checkbox:checked').each(function() {
                        selectedDays.push(jQuery(this).val());
                    });
                    
                    if (selectedDays.length > 0) {
                        console.log('InterSoccer: Days already selected, updating price immediately');
                        // Update the variation price for display
                        var basePrice = parseFloat(variation.display_price || variation.price);
                        var newPrice = basePrice * selectedDays.length;
                        
                        // Modify the variation object that WooCommerce will use for display
                        variation.display_price = newPrice;
                        variation.price = newPrice;
                        variation.display_regular_price = newPrice;
                        
                        console.log('InterSoccer: Modified variation price from', basePrice, 'to', newPrice, 'for', selectedDays.length, 'days');
                        
                        // Update the form data
                        $form.data('current_variation', variation);
                        var variationForm = $form.data('wc_variation_form');
                        if (variationForm) {
                            variationForm.current_variation = variation;
                        }
                    }
                }
                
                // Debug late pickup
                console.log('InterSoccer: Checking for late pickup container:', jQuery('.intersoccer-late-pickup').length > 0);
                if (jQuery('.intersoccer-late-pickup').length > 0) {
                    console.log('InterSoccer: Late pickup container found');
                }
            });

            $form.on('reset_data', function() {
                console.log('InterSoccer: WooCommerce reset variation data');
                lastVariation = null;
                lastVariationId = 0;
                
                // Hide day selection when variation is reset
                $daySelection.hide();
                $form.find('input[name="camp_days[]"]').remove();
                selectedDays = [];
                
                $form.trigger('intersoccer_update_button_state');
            });

            // Monitor variation ID changes
            $form.find('input[name="variation_id"]').on('change', function() {
                var variationId = $(this).val();
                console.log('InterSoccer: Variation ID changed to:', variationId);
                $form.trigger('intersoccer_update_button_state');
            });

            // Force initial check after a delay to handle Elementor loading
            setTimeout(function() {
                console.log('InterSoccer: Performing initial variation check');
                $form.trigger('check_variations');
                
                // Also try to get current booking type and render
                var currentBookingType = $form.find('select[name="attribute_pa_booking-type"]').val() || 
                                       $form.find('select[name="attribute_booking-type"]').val() || 
                                       $form.find('select[id*="booking-type"]').val() || '';
                console.log('InterSoccer: Initial booking type check:', currentBookingType);
                if (currentBookingType) {
                    renderDayCheckboxes(currentBookingType, $daySelection, $dayCheckboxes, $form);
                }
            }, 1000);
        });
    </script>
    <script>
        // Day translations for WPML compatibility - retrieve from WPML database
        var intersoccerDayTranslations = <?php
        $translations = array();
        if (function_exists('icl_t')) {
            $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
            foreach ($days as $day) {
                $translated = icl_t('intersoccer-product-variations', $day, $day);
                error_log('InterSoccer: WPML translation for ' . $day . ': ' . $translated);
                if ($translated !== $day) { // Only include if actually translated
                    $translations[$day] = $translated;
                }
            }
        } else {
            error_log('InterSoccer: icl_t function not available');
        }
        error_log('InterSoccer: Final translations array: ' . json_encode($translations));
        echo json_encode($translations);
        ?>;

        // Function to update camp price when days are selected
        function updateCampPrice($form, selectedDays) {
            console.log('InterSoccer: updateCampPrice called with days:', selectedDays);
            
            var variationId = $form.find('input[name="variation_id"]').val();
            console.log('InterSoccer: variationId found:', variationId);
            
            if (!variationId || variationId === '0') {
                console.log('InterSoccer: No valid variation selected for price update');
                return;
            }

            // Only make AJAX call if we have days selected or if this is a single-day booking
            var bookingType = $form.find('select[name*="booking-type"]').val() || $form.find('input[name="variation_id"]').closest('.variation').find('select[name*="booking-type"]').val();
            var isSingleDayBooking = bookingType === 'single-day' || bookingType === 'single-days' || bookingType === 'à la journée' || bookingType === 'a-la-journee' || 
                                   (bookingType && (bookingType.includes('single') || bookingType.includes('journée') || bookingType.includes('journee')));
            
            if (isSingleDayBooking && selectedDays.length === 0) {
                console.log('InterSoccer: Single-day booking but no days selected, skipping AJAX');
                return;
            }

            console.log('InterSoccer: Updating camp price for variation', variationId, 'with days:', selectedDays);
            console.log('InterSoccer: AJAX URL:', '<?php echo admin_url('admin-ajax.php'); ?>');
            console.log('InterSoccer: Nonce:', '<?php echo wp_create_nonce('intersoccer_nonce'); ?>');

            // Make AJAX call to get updated price
            jQuery.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: {
                    action: 'intersoccer_calculate_camp_price',
                    nonce: '<?php echo wp_create_nonce('intersoccer_nonce'); ?>',
                    variation_id: variationId,
                    camp_days: selectedDays
                },
                success: function(response) {
                    console.log('InterSoccer: Price update AJAX success:', response);
                    if (response.success) {
                        var price = response.data.price;
                        var rawPrice = response.data.raw_price;
                        console.log('InterSoccer: Session updated with selected days, price filters should handle display');
                        
                        // Trigger WooCommerce events to refresh price display
                        $form.trigger('woocommerce_variation_has_changed');
                        jQuery(document.body).trigger('wc_variation_form');
                        jQuery(document.body).trigger('woocommerce_variation_has_changed');
                        
                        // Force refresh of variation price display
                        if (typeof wc_variation_form !== 'undefined') {
                            wc_variation_form.trigger('woocommerce_variation_has_changed');
                        }
                        
                        // Simple fallback: try to update common price elements
                        jQuery('.woocommerce-variation-price .woocommerce-Price-amount, .price .woocommerce-Price-amount').each(function() {
                            jQuery(this).html(price);
                            console.log('InterSoccer: Updated price element as fallback:', jQuery(this).prop('tagName'), jQuery(this).attr('class'));
                        });
                        
                        // Trigger custom event for late pickup
                        $form.trigger('intersoccer_price_updated');
                    } else {
                        console.error('InterSoccer: Price update failed:', response.data);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('InterSoccer: Price update AJAX error:', status, error, xhr.responseText);
                }
            });
        }

        jQuery(document).ready(function($) {
            var $form = $('form.cart, form.variations_form, .woocommerce-product-details form, .single-product form');
            
            // Handle day checkbox changes
            $form.on('change', '.intersoccer-day-checkbox', function() {
                console.log('InterSoccer: Day checkbox changed');
                var selectedDays = [];
                $form.find('.intersoccer-day-checkbox:checked').each(function() {
                    selectedDays.push(jQuery(this).val());
                });
                console.log('InterSoccer: Selected days:', selectedDays);
                
                // Only update price if we have a valid variation selected
                var variationId = $form.find('input[name="variation_id"]').val();
                if (!variationId || variationId === '0') {
                    console.log('InterSoccer: No valid variation selected, skipping price update');
                    return;
                }
                
                // Update price immediately by modifying variation data
                var variationForm = $form.data('wc_variation_form');
                if (variationForm && variationForm.current_variation) {
                    var variation = variationForm.current_variation;
                    var bookingType = variation.attributes && variation.attributes['attribute_pa_booking-type'];
                    
                    if (bookingType === 'single-day' || bookingType === 'single-days' || bookingType === 'à la journée' || bookingType === 'a-la-journee' || 
                        (bookingType && (bookingType.includes('single') || bookingType.includes('journée') || bookingType.includes('journee')))) {
                        
                        var basePrice = parseFloat(variation.display_regular_price || variation.price);
                        var newPrice = selectedDays.length > 0 ? basePrice * selectedDays.length : basePrice;
                        
                        // Update variation data
                        variation.display_price = newPrice;
                        variation.price = newPrice;
                        
                        console.log('InterSoccer: Updated variation price to', newPrice, 'for', selectedDays.length, 'days');
                        
                        // Trigger WooCommerce price update
                        $form.trigger('woocommerce_variation_has_changed');
                        jQuery(document.body).trigger('wc_variation_form');
                        
                        // Force immediate DOM update
                        setTimeout(function() {
                            jQuery('.woocommerce-variation-price .woocommerce-Price-amount, .price .woocommerce-Price-amount').each(function() {
                                jQuery(this).html(wc_price(newPrice));
                                console.log('InterSoccer: Force updated price element:', jQuery(this).prop('tagName'), jQuery(this).attr('class'));
                            });
                        }, 100);
                    }
                }
                
                // Call AJAX for cart/session updates
                updateCampPrice($form, selectedDays);
            });
        });
    </script>
<?php
});