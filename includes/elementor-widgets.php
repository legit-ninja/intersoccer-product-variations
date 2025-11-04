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
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: No valid product found');
        }
        return;
    }

    $product_id = $product->get_id();
    $user_id = get_current_user_id();
    $is_variable = $product->is_type('variable');
    $product_type = intersoccer_get_product_type($product_id);
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("InterSoccer: Initializing wp_footer for product ID: $product_id, type: $product_type");
    }

    // Preload days for Camps
    $preloaded_days = [];
    if ($product_type === 'camp') {
        $attributes = $product->get_attributes();
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: Checking pa_days-of-week attribute for product ' . $product_id);
            error_log('InterSoccer: Product attributes: ' . json_encode(array_keys($attributes)));
        }

        if (isset($attributes['pa_days-of-week']) && $attributes['pa_days-of-week'] instanceof WC_Product_Attribute) {
            // Get terms with both names and slugs for multilingual support
            $terms = wc_get_product_terms($product_id, 'pa_days-of-week', ['fields' => 'all']);
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer: Retrieved terms for pa_days-of-week: ' . json_encode($terms));
            }
            
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
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('InterSoccer: Processing term: ' . $term->name . ' (slug: ' . $term->slug . ')');
                    }
                    if (isset($day_map[$slug])) {
                        $english_days[] = $day_map[$slug];
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log('InterSoccer: Mapped to English: ' . $day_map[$slug]);
                        }
                    } else {
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log('InterSoccer: No mapping found for slug: ' . $slug);
                        }
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
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('InterSoccer: Final preloaded days: ' . json_encode($preloaded_days));
                    }
                } else {
                    // Fallback to default English days
                    $preloaded_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('InterSoccer: Using fallback days: ' . json_encode($preloaded_days));
                    }
                }
            } else {
                // Fallback to default English days
                $preloaded_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('InterSoccer: No terms found, using fallback days: ' . json_encode($preloaded_days));
                }
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer: pa_days-of-week attribute not found or not valid');
            }
        }
    } else {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: Product is not a camp, skipping day preloading');
        }
    }
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer: Preloaded days for product ' . $product_id . ': ' . json_encode($preloaded_days));
        error_log('InterSoccer: Product attributes for ' . $product_id . ': ' . json_encode(array_keys($product->get_attributes())));
        error_log('InterSoccer: Product type: ' . $product_type);
    }

    // Course info container removed - course details now only display in variation table
    // if ($product_type === 'course') {
    //     echo '<div id="intersoccer-course-info" class="intersoccer-course-info" style="display: none; margin: 20px 0; padding: 15px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px;">';
    //     echo '<h4>' . __('Course Information', 'intersoccer-product-variations') . '</h4>';
    //     echo '<div id="intersoccer-course-details"></div>';
    //     echo '</div>';
    // }

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
    
    // Late Pickup HTML - Generate for ALL camp products (show/hide based on variation)
    $late_pickup_html = '';
    
    error_log('=== InterSoccer Late Pickup: Starting HTML generation ===');
    error_log('Product type: ' . $product_type);
    error_log('Is variable: ' . ($is_variable ? 'yes' : 'no'));
    
    if ($product_type === 'camp' && $is_variable) {
        error_log('InterSoccer Late Pickup: Product IS camp and IS variable, proceeding...');
        
        // Get late pickup pricing from settings
        $per_day_cost = floatval(get_option('intersoccer_late_pickup_per_day', 25));
        $full_week_cost = floatval(get_option('intersoccer_late_pickup_full_week', 90));
        
        error_log('InterSoccer Late Pickup: Loaded pricing - Per Day: ' . $per_day_cost . ' CHF, Full Week: ' . $full_week_cost . ' CHF');
        
        // Get variation settings for late pickup and available days
        $variations = $product->get_available_variations();
        $variation_settings = [];
        $default_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        
        foreach ($variations as $variation) {
            $variation_id = $variation['variation_id'];
            $enable_late_pickup = get_post_meta($variation_id, '_intersoccer_enable_late_pickup', true);
            
            // Get available days for this variation (default to all days if not set)
            $camp_days_available = get_post_meta($variation_id, '_intersoccer_camp_days_available', true);
            if (!is_array($camp_days_available) || empty($camp_days_available)) {
                $camp_days_available = array_fill_keys($default_days, true);
            }
            
            $late_pickup_days_available = get_post_meta($variation_id, '_intersoccer_late_pickup_days_available', true);
            if (!is_array($late_pickup_days_available) || empty($late_pickup_days_available)) {
                $late_pickup_days_available = array_fill_keys($default_days, true);
            }
            
            // Filter to only include enabled days
            $available_camp_days = array_keys(array_filter($camp_days_available));
            $available_late_pickup_days = array_keys(array_filter($late_pickup_days_available));
            
            $variation_settings[$variation_id] = [
                'enabled' => ($enable_late_pickup === 'yes'),
                'per_day_cost' => $per_day_cost,
                'full_week_cost' => $full_week_cost,
                'available_camp_days' => $available_camp_days,
                'available_late_pickup_days' => $available_late_pickup_days,
            ];
        }
        
        error_log('InterSoccer Late Pickup (Elementor): Total variations: ' . count($variations));
        error_log('InterSoccer Late Pickup (Elementor): Variations with late pickup enabled: ' . count($variation_settings));
        error_log('InterSoccer Late Pickup (Elementor): Variation settings: ' . json_encode($variation_settings));
        
        // ALWAYS generate the HTML for camp products, even if no variations have it enabled yet
        // The JavaScript will show/hide it based on the selected variation
        error_log('InterSoccer Late Pickup: Generating HTML now...');
        ob_start();
?>
    <tr class="intersoccer-late-pickup-row intersoccer-injected" style="display: none;" data-variation-settings="<?php echo esc_attr(json_encode($variation_settings, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS)); ?>">
        <th class="label"><label><?php esc_html_e('Late Pick Up Options', 'intersoccer-product-variations'); ?></label></th>
        <td class="value">
            <div class="intersoccer-late-pickup-content">
                <div class="intersoccer-late-pickup-days"></div>
                <div class="intersoccer-late-pickup-cost" style="margin-top: 10px; font-weight: bold;"></div>
            </div>
        </td>
    </tr>
<?php
        $late_pickup_html = ob_get_clean();
        error_log('InterSoccer Late Pickup: HTML generated, length: ' . strlen($late_pickup_html));
        error_log('InterSoccer Late Pickup: HTML content preview: ' . substr($late_pickup_html, 0, 200));
    } else {
        error_log('InterSoccer Late Pickup: NOT generating HTML (product_type=' . $product_type . ', is_variable=' . ($is_variable ? 'yes' : 'no') . ')');
    }
    
    error_log('InterSoccer Late Pickup: Final $late_pickup_html empty? ' . (empty($late_pickup_html) ? 'YES' : 'NO'));
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
            
            // CRITICAL FIX: Intercept WooCommerce AJAX add-to-cart for camp and course products
            // We need to prevent AJAX but keep the variation form working
            // This is necessary because AJAX add-to-cart doesn't properly handle custom fields
            <?php if (in_array($product_type, ['camp', 'course']) && $is_variable): ?>
            console.log('InterSoccer: Setting up standard POST submission for <?php echo $product_type; ?> product');
            
            // Unbind WooCommerce's AJAX add-to-cart handler
            $(document).off('click', '.single_add_to_cart_button');
            
            // Prevent WooCommerce from intercepting the form submission
            $form.off('submit.wc-variation-form');
            
            console.log('InterSoccer: AJAX handlers removed, form will use standard POST');
            <?php endif; ?>
            
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
            var isSubmitting = false;
            var lastValidAvailableDays = null; // Cache last valid available days to prevent reset on player change
            
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
                    console.log('InterSoccer: Fields already injected, skipping');
                    return;
                }

                console.log('InterSoccer: Injecting fields into form');
                var $variationsTable = $form.find('.variations, .woocommerce-variation');
                console.log('InterSoccer: Variations table found:', $variationsTable.length);
                
                if ($variationsTable.length) {
                    var $tbody = $variationsTable.find('tbody, .variations_table');
                    console.log('InterSoccer: tbody found:', $tbody.length);
                    
                    if ($tbody.length) {
                        console.log('InterSoccer: Appending to tbody');
                        $tbody.append(<?php echo json_encode($player_selection_html, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>);
                        $tbody.append(<?php echo json_encode($day_selection_html, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>);
                        <?php if (!empty($late_pickup_html)): ?>
                        console.log('InterSoccer Late Pickup: Injecting late pickup HTML into tbody');
                        $tbody.append(<?php echo json_encode($late_pickup_html, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>);
                        console.log('InterSoccer Late Pickup: Late pickup row injected, checking presence:', $tbody.find('.intersoccer-late-pickup-row').length);
                        <?php else: ?>
                        console.log('InterSoccer Late Pickup: No late pickup HTML to inject (empty)');
                        <?php endif; ?>
                    } else {
                        console.log('InterSoccer: Appending to variations table directly');
                        $variationsTable.append(<?php echo json_encode($player_selection_html, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>);
                        $variationsTable.append(<?php echo json_encode($day_selection_html, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>);
                        <?php if (!empty($late_pickup_html)): ?>
                        console.log('InterSoccer Late Pickup: Injecting late pickup HTML into variations table');
                        $variationsTable.append(<?php echo json_encode($late_pickup_html, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>);
                        <?php endif; ?>
                    }
                } else {
                    console.log('InterSoccer: No variations table, prepending to form');
                    $form.prepend(<?php echo json_encode($player_selection_html, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>);
                    $form.prepend(<?php echo json_encode($day_selection_html, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>);
                    <?php if (!empty($late_pickup_html)): ?>
                    console.log('InterSoccer Late Pickup: Prepending late pickup HTML to form (no variations table)');
                    $form.prepend(<?php echo json_encode($late_pickup_html, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>);
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
                                        
                                        // Reapply late pickup cost if late pickup is active
                                        var variationId = $form.find('input[name="variation_id"]').val();
                                        if (variationId && variationId !== '0') {
                                            var latePickupSettings = getLatePickupSettings(variationId);
                                            if (latePickupSettings && selectedLatePickupOption !== 'none') {
                                                console.log('InterSoccer: Reapplying late pickup cost after player change');
                                                // Small delay to let price update from camp days complete first
                                                setTimeout(function() {
                                                    updateLatePickupCost(latePickupSettings);
                                                }, 100);
                                            }
                                        }
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

                    // Get available days from variation settings (respects admin day availability settings)
                    var variationId = $form.find('input[name="variation_id"]').val();
                    var availableDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday']; // Default
                    
                    // Ensure settings are loaded from DOM if not already in memory
                    if (Object.keys(latePickupVariationSettings).length === 0) {
                        var $latePickupRow = $form.find('.intersoccer-late-pickup-row');
                        if ($latePickupRow.length) {
                            try {
                                latePickupVariationSettings = JSON.parse($latePickupRow.attr('data-variation-settings') || '{}');
                                console.log('InterSoccer Debug: Loaded variation settings from DOM:', latePickupVariationSettings);
                            } catch (e) {
                                console.error('InterSoccer Debug: Failed to parse variation settings:', e);
                            }
                        }
                    }
                    
                    // Try to get available days from variation settings (includes camp day availability)
                    if (variationId && variationId !== '0' && latePickupVariationSettings[variationId]) {
                        var settings = latePickupVariationSettings[variationId];
                        if (settings.available_camp_days && settings.available_camp_days.length > 0) {
                            availableDays = settings.available_camp_days;
                            lastValidAvailableDays = availableDays; // Cache for future use
                            console.log('InterSoccer Debug: Using available camp days from variation settings:', availableDays);
                        } else {
                            console.log('InterSoccer Debug: No available_camp_days in settings or empty array');
                        }
                    } else {
                        console.log('InterSoccer Debug: Variation settings not found or invalid variation ID');
                        // Use cached value if available (prevents reset during player selection)
                        if (lastValidAvailableDays && lastValidAvailableDays.length > 0) {
                            availableDays = lastValidAvailableDays;
                            console.log('InterSoccer Debug: Using cached available days:', availableDays);
                        }
                    }
                    
                    // For single-day bookings, prioritize available days from admin settings
                    // Filter preloadedDays to only include days that are available
                    var daysToShow = availableDays; // Use admin-controlled available days as base
                    if (preloadedDays.length > 0) {
                        // Filter preloadedDays to only include days that are in availableDays
                        var filteredPreloaded = preloadedDays.filter(function(day) {
                            return availableDays.indexOf(day) !== -1;
                        });
                        // Only use preloadedDays if they're all valid (don't want to lose admin settings)
                        if (filteredPreloaded.length === preloadedDays.length) {
                            daysToShow = filteredPreloaded;
                        }
                    }
                    console.log('InterSoccer Debug: preloadedDays:', preloadedDays);
                    console.log('InterSoccer Debug: availableDays:', availableDays);
                    console.log('InterSoccer Debug: Final daysToShow:', daysToShow);

                    // Check multiple sources for which days should be checked
                    var currentChecked = $dayCheckboxes.find('input.intersoccer-day-checkbox:checked').map(function() { return $(this).val(); }).get();
                    var hiddenDays = $form.find('input[name="camp_days[]"]').map(function() { return $(this).val(); }).get();
                    
                    console.log('  Days state - currentChecked:', currentChecked, 'hiddenDays:', hiddenDays, 'selectedDays:', selectedDays);
                    
                    // Sync selectedDays with hidden inputs (source of truth)
                    if (hiddenDays.length > 0) {
                        selectedDays = hiddenDays;
                        console.log('  Synced selectedDays from hidden inputs:', selectedDays);
                    }
                    
                    $dayCheckboxes.empty();
                    daysToShow.forEach((day) => {
                        var translatedDay = dayTranslations[day] || day; // Fallback to English if translation not found
                        var isChecked = currentChecked.includes(day) || selectedDays.includes(day) || hiddenDays.includes(day) ? 'checked' : '';
                        $dayCheckboxes.append(`
                            <label style="margin-right: 10px; display: inline-block;">
                                <input type="checkbox" name="camp_days_temp[]" value="${day}" class="intersoccer-day-checkbox" ${isChecked}> ${translatedDay}
                            </label>
                        `);
                    });
                    $dayCheckboxes.find('input.intersoccer-day-checkbox').prop('disabled', false);
                    
                    // Update notification state after rendering checkboxes based on ACTUAL checkbox state
                    var $dayNotification = $daySelection.find('.intersoccer-day-notification');
                    var actuallyCheckedCount = $dayCheckboxes.find('input.intersoccer-day-checkbox:checked').length;
                    console.log('  After render - actuallyCheckedCount:', actuallyCheckedCount, 'isSubmitting:', isSubmitting);
                    console.log('  Current call stack:', new Error().stack);
                    
                    // Don't show notification if form is being submitted or has been submitted
                    if (isSubmitting) {
                        console.log('  Form is submitting, FORCING notification to hide');
                        $dayNotification.hide().css('display', 'none');
                    } else if (actuallyCheckedCount === 0) {
                        console.log('  Showing day notification - no days selected');
                        $dayNotification.text('Please select at least one day.').css('color', 'red').show();
                    } else {
                        console.log('  Hiding day notification - days are selected');
                        $dayNotification.hide();
                    }

                    $dayCheckboxes.find('input.intersoccer-day-checkbox').off('change').on('change', function() {
                        var $checkbox = $(this);

                        selectedDays = $dayCheckboxes.find('input.intersoccer-day-checkbox:checked').map(function() { return $(this).val(); }).get();
                        console.log('InterSoccer Day Checkbox CHANGED: Selected days:', selectedDays);
                        
                        $form.find('input[name="camp_days[]"]').remove();
                        selectedDays.forEach((day) => {
                            $form.append(`<input type="hidden" name="camp_days[]" value="${day}" class="intersoccer-camp-day-input">`);
                        });
                        
                        // Verify hidden inputs were added
                        var hiddenInputCount = $form.find('input[name="camp_days[]"]').length;
                        console.log('InterSoccer Day Checkbox: Hidden inputs in form:', hiddenInputCount, 'values:', $form.find('input[name="camp_days[]"]').map(function() { return $(this).val(); }).get());
                        
                        // Update day selection notification
                        var $dayNotification = $daySelection.find('.intersoccer-day-notification');
                        if (!isSubmitting) {
                            if (selectedDays.length === 0) {
                                $dayNotification.text('Please select at least one day.').css('color', 'red').show();
                            } else {
                                $dayNotification.hide();
                            }
                        } else {
                            console.log('  Checkbox change - form is submitting, keeping notification hidden');
                        }
                        
                        // Price update is handled by the main checkbox change handler in jQuery(document).ready()
                        // to prevent duplicate AJAX calls (removed from here to avoid race conditions)
                        
                        $form.trigger('intersoccer_update_button_state');
                        // Trigger WooCommerce variation check
                        $form.trigger('check_variations');
                    });
                } else {
                    console.log('InterSoccer Debug: Hiding day selection - conditions not met');
                    $daySelection.hide();
                    $form.find('input[name="camp_days[]"]').remove();
                    selectedDays = [];
                    // Hide the notification when day selection is hidden
                    $daySelection.find('.intersoccer-day-notification').hide();
                }
            }
            
            // Late Pickup Handling
            var latePickupVariationSettings = {};
            var selectedLatePickupOption = 'none'; // 'none', 'full-week', or 'single-days'
            var selectedLatePickupDays = []; // For 'single-days' option, stores selected days
            
            function getLatePickupRow() {
                return $form.find('.intersoccer-late-pickup-row');
            }
            
            function getLatePickupSettings(variationId) {
                if (!variationId) {
                    // Try to get current variation ID
                    variationId = $form.find('input[name="variation_id"]').val();
                }
                
                if (!variationId || variationId === '0') {
                    console.log('InterSoccer Late Pickup: No valid variation ID for settings lookup');
                    return null;
                }
                
                var settings = latePickupVariationSettings[variationId];
                if (!settings || !settings.enabled) {
                    console.log('InterSoccer Late Pickup: No settings found or late pickup not enabled for variation', variationId);
                    return null;
                }
                
                return settings;
            }
            
            function loadLatePickupSettings() {
                var $row = getLatePickupRow();
                if ($row.length) {
                    try {
                        latePickupVariationSettings = JSON.parse($row.attr('data-variation-settings') || '{}');
                        console.log('InterSoccer Late Pickup: Variation settings loaded:', latePickupVariationSettings);
                    } catch (e) {
                        console.error('InterSoccer Late Pickup: Failed to parse variation settings:', e);
                    }
                }
            }
            
            function handleLatePickupDisplay(variationId) {
                console.log('InterSoccer Late Pickup: handleLatePickupDisplay called for variation', variationId);
                
                var $latePickupRow = getLatePickupRow();
                
                if (!$latePickupRow.length) {
                    console.log('InterSoccer Late Pickup: Row element not found');
                    return;
                }
                
                // Listen for price updates from camp days changes and update base price + reapply late pickup
                $form.off('intersoccer_price_updated.latePickup').on('intersoccer_price_updated.latePickup', function(event, data) {
                    console.log('InterSoccer Late Pickup: Camp price updated, recalculating with late pickup');
                    
                    // Update stored base price with the new camp price
                    var $priceContainer = jQuery('.woocommerce-variation-price');
                    if (data && data.rawPrice) {
                        $priceContainer.data('intersoccer-base-price', parseFloat(data.rawPrice));
                        console.log('InterSoccer Late Pickup: Updated base price to:', data.rawPrice);
                    }
                    
                    // Reapply late pickup cost to the new base price
                    var latePickupSettings = getLatePickupSettings(variationId);
                    if (latePickupSettings) {
                        updateLatePickupCost(latePickupSettings);
                    }
                });
                
                console.log('InterSoccer Late Pickup: Row element found!');
                
                // Load settings if not already loaded
                if (Object.keys(latePickupVariationSettings).length === 0) {
                    loadLatePickupSettings();
                }
                
                var settings = latePickupVariationSettings[variationId];
                
                if (settings && settings.enabled) {
                    console.log('InterSoccer Late Pickup: Showing late pickup for variation', variationId);
                    $latePickupRow.show();
                    renderLatePickupRadioButtons(settings);
                } else {
                    console.log('InterSoccer Late Pickup: Hiding late pickup (not enabled for this variation)');
                    $latePickupRow.hide();
                    selectedLatePickupOption = 'none';
                    selectedLatePickupDays = [];
                }
            }
            
            function renderLatePickupRadioButtons(settings) {
                var $latePickupRow = getLatePickupRow();
                var $daysContainer = $latePickupRow.find('.intersoccer-late-pickup-days');
                var $costContainer = $latePickupRow.find('.intersoccer-late-pickup-cost');
                
                // Reset to default if not already set
                if (!selectedLatePickupOption || selectedLatePickupOption === '') {
                    selectedLatePickupOption = 'none';
                    selectedLatePickupDays = [];
                }
                
                // Get available late pickup days from settings (respects admin day availability settings)
                var days = (settings.available_late_pickup_days && settings.available_late_pickup_days.length > 0) 
                    ? settings.available_late_pickup_days 
                    : ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday']; // Default to all days
                
                console.log('InterSoccer Late Pickup: Available late pickup days from settings:', days);
                
                var dayTranslations = typeof intersoccerDayTranslations !== 'undefined' ? intersoccerDayTranslations : {
                    'Monday': 'Monday',
                    'Tuesday': 'Tuesday',
                    'Wednesday': 'Wednesday',
                    'Thursday': 'Thursday',
                    'Friday': 'Friday'
                };
                
                $daysContainer.empty();
                
                // Add optional note
                $daysContainer.append(`
                    <div style="margin-bottom: 10px; font-style: italic; color: #666;">
                        Select late pick up option (optional)
                    </div>
                `);
                
                // Create options container
                var $optionsContainer = $('<div class="intersoccer-late-pickup-options"></div>');
                
                // Add "No Late Pickup" option (default)
                var noneChecked = selectedLatePickupOption === 'none' ? 'checked' : '';
                $optionsContainer.append(`
                    <label style="display: block; margin-bottom: 8px;">
                        <input type="radio" name="late_pickup_option" value="none" class="intersoccer-late-pickup-radio" ${noneChecked}> 
                        <strong>No Late Pickup</strong>
                    </label>
                `);
                
                // Add "Full Week" option
                var fullWeekChecked = selectedLatePickupOption === 'full-week' ? 'checked' : '';
                $optionsContainer.append(`
                    <label style="display: block; margin-bottom: 8px;">
                        <input type="radio" name="late_pickup_option" value="full-week" class="intersoccer-late-pickup-radio" ${fullWeekChecked}> 
                        <strong>Full Week</strong>
                    </label>
                `);
                
                // Add "Single Days" option
                var singleDaysChecked = selectedLatePickupOption === 'single-days' ? 'checked' : '';
                $optionsContainer.append(`
                    <label style="display: block; margin-bottom: 8px;">
                        <input type="radio" name="late_pickup_option" value="single-days" class="intersoccer-late-pickup-radio" ${singleDaysChecked}> 
                        <strong>Single Days</strong>
                    </label>
                `);
                
                $daysContainer.append($optionsContainer);
                
                // Add day checkboxes container (hidden by default)
                var $dayCheckboxesContainer = $('<div class="intersoccer-late-pickup-day-checkboxes" style="margin-left: 25px; margin-top: 10px; display: none;"></div>');
                
                days.forEach(function(day) {
                    var translatedDay = dayTranslations[day] || day;
                    var isChecked = selectedLatePickupDays.includes(day) ? 'checked' : '';
                    $dayCheckboxesContainer.append(`
                        <label style="display: block; margin-bottom: 5px;">
                            <input type="checkbox" name="late_pickup_single_days[]" value="${day}" class="intersoccer-late-pickup-day-checkbox" ${isChecked}> ${translatedDay}
                        </label>
                    `);
                });
                
                $daysContainer.append($dayCheckboxesContainer);
                
                // Handle radio button changes
                $optionsContainer.find('.intersoccer-late-pickup-radio').on('change', function() {
                    selectedLatePickupOption = $(this).val();
                    console.log('InterSoccer Late Pickup: Option changed to:', selectedLatePickupOption);
                    
                    // Show/hide day checkboxes based on selection
                    if (selectedLatePickupOption === 'single-days') {
                        $dayCheckboxesContainer.show();
                    } else {
                        $dayCheckboxesContainer.hide();
                        selectedLatePickupDays = []; // Clear selected days when not in single-days mode
                    }
                    
                    updateLatePickupCost(settings);
                    updateLatePickupFormData(settings);
                });
                
                // Handle day checkbox changes
                $dayCheckboxesContainer.find('.intersoccer-late-pickup-day-checkbox').on('change', function() {
                    selectedLatePickupDays = $dayCheckboxesContainer.find('.intersoccer-late-pickup-day-checkbox:checked').map(function() {
                        return $(this).val();
                    }).get();
                    
                    console.log('InterSoccer Late Pickup: Selected days:', selectedLatePickupDays);
                    
                    updateLatePickupCost(settings);
                    updateLatePickupFormData(settings);
                });
                
                // Show day checkboxes if single-days is already selected
                if (selectedLatePickupOption === 'single-days') {
                    $dayCheckboxesContainer.show();
                }
                
                updateLatePickupCost(settings);
                updateLatePickupFormData(settings);
            }
            
            function updateLatePickupCost(settings) {
                var $latePickupRow = getLatePickupRow();
                var $costContainer = $latePickupRow.find('.intersoccer-late-pickup-cost');
                
                // Handle radio button selection
                if (selectedLatePickupOption === 'none') {
                    $costContainer.html('<span style="color: #666;">No late pick up selected</span>');
                    console.log('InterSoccer Late Pickup: No option selected');
                    
                    // Update main price display to remove late pickup cost
                    updateMainPriceWithLatePickup(0);
                    return;
                }
                
                var cost;
                var costText;
                
                if (selectedLatePickupOption === 'full-week') {
                    cost = settings.full_week_cost;
                    costText = 'Full Week (5 days): ';
                } else if (selectedLatePickupOption === 'single-days') {
                    // Calculate based on number of days selected
                    var dayCount = selectedLatePickupDays.length;
                    
                    if (dayCount === 0) {
                        $costContainer.html('<span style="color: #666;">Select at least one day</span>');
                        console.log('InterSoccer Late Pickup: Single days selected but no days checked');
                        
                        // Update main price display to remove late pickup cost
                        updateMainPriceWithLatePickup(0);
                        return;
                    }
                    
                    // Use full week price if 5 days selected
                    if (dayCount === 5) {
                        cost = settings.full_week_cost;
                        costText = 'Full Week (5 days): ';
                    } else {
                        cost = dayCount * settings.per_day_cost;
                        costText = dayCount + ' day' + (dayCount > 1 ? 's' : '') + ': ';
                    }
                } else {
                    // Fallback (shouldn't happen)
                    $costContainer.html('<span style="color: #666;">Invalid selection</span>');
                    updateMainPriceWithLatePickup(0);
                    return;
                }
                
                var formattedCost = typeof wc_price !== 'undefined' ? wc_price(cost) : 'CHF ' + cost.toFixed(2);
                $costContainer.html(costText + formattedCost);
                
                console.log('InterSoccer Late Pickup: Cost updated - option:', selectedLatePickupOption, 'days:', selectedLatePickupDays.length, 'cost:', cost);
                
                // Update main price display to include late pickup cost
                updateMainPriceWithLatePickup(cost);
            }
            
            function updateMainPriceWithLatePickup(latePickupCost) {
                var $priceContainer = jQuery('.woocommerce-variation-price');
                if (!$priceContainer.length) {
                    console.log('InterSoccer Late Pickup: Price container not found, skipping main price update');
                    return;
                }
                
                // Get the current price HTML
                var currentPriceHtml = $priceContainer.html();
                if (!currentPriceHtml) {
                    console.log('InterSoccer Late Pickup: No current price HTML, skipping update');
                    return;
                }
                
                // Extract the current numeric price from the HTML
                var priceMatch = currentPriceHtml.match(/[\d,]+\.?\d*/);
                if (!priceMatch) {
                    console.log('InterSoccer Late Pickup: Could not extract price from HTML:', currentPriceHtml);
                    return;
                }
                
                // Get or calculate the base price (price without late pickup)
                var basePrice = parseFloat($priceContainer.data('intersoccer-base-price'));
                if (!basePrice || isNaN(basePrice)) {
                    // First time or base price not stored - use current displayed price
                    basePrice = parseFloat(priceMatch[0].replace(',', ''));
                    $priceContainer.data('intersoccer-base-price', basePrice);
                    console.log('InterSoccer Late Pickup: Stored base price:', basePrice);
                }
                
                // Calculate new total price
                var totalPrice = basePrice + parseFloat(latePickupCost || 0);
                
                // Build the new price HTML maintaining WooCommerce structure
                var newPriceHtml = '<span class="price"><span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">CHF</span>' + totalPrice.toFixed(2) + '</bdi></span></span>';
                
                // Update the display
                $priceContainer.html(newPriceHtml);
                
                console.log('InterSoccer Late Pickup: Updated main price - base:', basePrice, 'late pickup:', latePickupCost, 'total:', totalPrice);
            }
            
            function updateLatePickupFormData(settings) {
                // Remove any existing late pickup hidden inputs
                $form.find('input[name="late_pickup_type"]').remove();
                $form.find('input[name="late_pickup_cost"]').remove();
                $form.find('input[name="late_pickup_days[]"]').remove();
                
                // If "none" is selected, don't add any form data
                if (selectedLatePickupOption === 'none') {
                    console.log('InterSoccer Late Pickup: No option selected, not adding form data');
                    return;
                }
                
                // Calculate cost based on selection
                var cost;
                var daysToSend = [];
                
                if (selectedLatePickupOption === 'full-week') {
                    cost = settings.full_week_cost;
                    daysToSend = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
                } else if (selectedLatePickupOption === 'single-days') {
                    // Use selected days from checkboxes
                    var dayCount = selectedLatePickupDays.length;
                    
                    if (dayCount === 0) {
                        console.log('InterSoccer Late Pickup: Single days selected but no days checked, not adding form data');
                        return;
                    }
                    
                    // Use full week price if 5 days selected
                    if (dayCount === 5) {
                        cost = settings.full_week_cost;
                    } else {
                        cost = dayCount * settings.per_day_cost;
                    }
                    
                    daysToSend = selectedLatePickupDays;
                } else {
                    console.log('InterSoccer Late Pickup: Invalid option, not adding form data');
                    return;
                }
                
                // Add hidden input for late pickup type (CRITICAL - server needs this to process late pickup)
                $form.append('<input type="hidden" name="late_pickup_type" value="' + selectedLatePickupOption + '">');
                
                // Add hidden input for cost
                $form.append('<input type="hidden" name="late_pickup_cost" value="' + cost + '">');
                
                // Add hidden inputs for each day
                daysToSend.forEach(function(day) {
                    $form.append('<input type="hidden" name="late_pickup_days[]" value="' + day + '">');
                });
                
                console.log('InterSoccer Late Pickup: Added form data - type:', selectedLatePickupOption, 'cost:', cost, 'days:', daysToSend);
            }

            // Button state update handler - ensures player selection is required for ALL products
            $form.on('intersoccer_update_button_state', function() {
                var $button = $form.find('button.single_add_to_cart_button, input[type="submit"][name="add-to-cart"]');
                var playerId = $form.find('select[name="player_assignment"], .intersoccer-player-select, select#player_assignment_select').val();
                var bookingType = $form.find('select[name="attribute_pa_booking-type"]').val() || 
                                $form.find('select[name="attribute_booking-type"]').val() || 
                                $form.find('select[id*="booking-type"]').val() || 
                                $form.find('input[name="attribute_pa_booking-type"]').val() || 
                                $form.find('input[name="attribute_booking-type"]').val() || '';
                // Fix: Check for null/undefined/empty string, but allow 0 (first player index)
                var hasPlayer = playerId !== null && playerId !== undefined && playerId !== '';
                
                console.log('InterSoccer: Updating button state');
                console.log('  Player ID value:', playerId, 'type:', typeof playerId);
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
                
                // Manage notifications based on what's missing
                var $attendeeNotification = $form.find('.intersoccer-attendee-notification');
                var $dayNotification = $form.find('.intersoccer-day-notification');
                var isSingleDayBooking = bookingType === 'single-days' || 
                                       bookingType === 'à la journée' || 
                                       bookingType === 'a-la-journee' ||
                                       bookingType.toLowerCase().includes('single') || 
                                       bookingType.toLowerCase().includes('journée') ||
                                       bookingType.toLowerCase().includes('journee');
                
                if (shouldEnable) {
                    $button.prop('disabled', false).removeClass('disabled');
                    $attendeeNotification.hide();
                    if (!isSubmitting) {
                        $dayNotification.hide();
                    }
                } else {
                    $button.prop('disabled', true).addClass('disabled');
                    
                    // Don't show notifications if form is being submitted
                    if (isSubmitting) {
                        $attendeeNotification.hide();
                        $dayNotification.hide();
                    } else {
                        // Show appropriate notification based on what's missing
                        if (!hasPlayer) {
                            $attendeeNotification.show();
                            $dayNotification.hide();
                        } else if (isSingleDayBooking && !daysSelected) {
                            $attendeeNotification.hide();
                            $dayNotification.text('Please select at least one day.').css('color', 'red').show();
                        } else {
                            // Variation not selected or other issue
                            $attendeeNotification.hide();
                            $dayNotification.hide();
                        }
                    }
                }
            });

            // Handle form submission
            $form.on('submit', function(e) {
                console.log('InterSoccer: Form submit event triggered');
                
                <?php if (in_array($product_type, ['camp', 'course']) && $is_variable): ?>
                // For camp and course products, force standard POST submission
                // Stop all other handlers from executing
                e.stopImmediatePropagation();
                
                console.log('InterSoccer: Forcing standard POST submission for <?php echo $product_type; ?> product');
                
                // Get the actual form element
                var formElement = $form[0];
                
                // Submit the form natively (bypassing all jQuery handlers)
                setTimeout(function() {
                    console.log('InterSoccer: Native form submit executed');
                    formElement.submit();
                }, 10);
                
                return;  // Don't execute any code below this
                <?php endif; ?>
                
                isSubmitting = true;
                
                // Debug: Check what data is in the form
                var campDays = $form.find('input[name="camp_days[]"]').map(function() { return $(this).val(); }).get();
                var latePickupDays = $form.find('input[name="late_pickup_days[]"]').map(function() { return $(this).val(); }).get();
                var latePickupCost = $form.find('input[name="late_pickup_cost"]').val();
                var variationId = $form.find('input[name="variation_id"]').val();
                var assignedAttendee = $form.find('input[name="assigned_attendee"]').val();
                
                console.log('InterSoccer: Form data at submission:');
                console.log('  - Camp days:', campDays);
                console.log('  - Late pickup days:', latePickupDays);
                console.log('  - Late pickup cost:', latePickupCost);
                console.log('  - Variation ID:', variationId);
                console.log('  - Assigned attendee:', assignedAttendee);
                console.log('  - Selected days variable:', selectedDays);
                
                // Hide all notifications during submission
                $form.addClass('intersoccer-form-submitting');
                $form.find('.intersoccer-attendee-notification').hide().css('display', 'none');
                $form.find('.intersoccer-day-notification').hide().css('display', 'none');
                
                console.log('InterSoccer: Form submitting, isSubmitting =', isSubmitting);
                
                // Don't automatically reset - only reset if there's an error
                // The adding_to_cart event will handle successful submissions
            });
            
            // Also handle button click directly
            $form.find('button.single_add_to_cart_button, input[type="submit"][name="add-to-cart"]').on('click', function(e) {
                console.log('=== InterSoccer: Buy Now button clicked ===');
                var $button = $(this);
                
                // Debug: Log form data BEFORE any checks
                var campDaysBeforeSubmit = $form.find('input[name="camp_days[]"]').map(function() { return $(this).val(); }).get();
                var bookingType = $form.find('select[name="attribute_pa_booking-type"]').val();
                var variationId = $form.find('input[name="variation_id"]').val();
                var $playerSelect = $form.find('select[name="player_assignment"], .intersoccer-player-select, select#player_assignment_select');
                var playerId = $playerSelect.val();
                
                console.log('InterSoccer Button Click: Player select found?', $playerSelect.length, 'selector:', $playerSelect.attr('name'));
                console.log('InterSoccer Button Click: Player select element:', $playerSelect[0]);
                
                console.log('InterSoccer Button Click: Booking type:', bookingType);
                console.log('InterSoccer Button Click: Variation ID:', variationId);
                console.log('InterSoccer Button Click: Player ID:', playerId);
                console.log('InterSoccer Button Click: Camp days in form:', campDaysBeforeSubmit);
                console.log('InterSoccer Button Click: Selected days variable:', selectedDays);
                console.log('InterSoccer Button Click: Button disabled?', $button.prop('disabled'));
                
                // Check if button is disabled
                if ($button.prop('disabled')) {
                    console.log('InterSoccer: ❌ Button is DISABLED, preventing submission');
                    e.preventDefault();
                    return false;
                }
                
                // If no camp days in form but we have selectedDays, add them
                if (campDaysBeforeSubmit.length === 0 && selectedDays.length > 0) {
                    console.log('InterSoccer: ⚠️  WARNING - No camp_days[] inputs found, but selectedDays has:', selectedDays);
                    console.log('InterSoccer: Adding camp days to form now...');
                    selectedDays.forEach(function(day) {
                        $form.append('<input type="hidden" name="camp_days[]" value="' + day + '" class="intersoccer-camp-day-input">');
                    });
                    var afterAdd = $form.find('input[name="camp_days[]"]').map(function() { return $(this).val(); }).get();
                    console.log('InterSoccer: ✅ Added camp days to form, now have:', afterAdd);
                }
                
                isSubmitting = true;
                
                // Hide all notifications and add a class to track submission
                $form.addClass('intersoccer-form-submitting');
                $form.find('.intersoccer-attendee-notification').hide().css('display', 'none');
                $form.find('.intersoccer-day-notification').hide().css('display', 'none');
                
                console.log('InterSoccer: ✅ Proceeding with form submission, isSubmitting =', isSubmitting);
            });
            
            // Handle successful add to cart
            $(document.body).on('added_to_cart', function() {
                console.log('InterSoccer: Product successfully added to cart');
                // Keep isSubmitting = true so notifications don't reappear
                // Page might redirect or cart drawer might open
            });
            
            // Handle add to cart errors
            $(document.body).on('wc_fragments_refreshed wc_cart_fragments_refreshed', function() {
                // Check if there are error notices
                if ($('.woocommerce-error, .woocommerce-message--error').length > 0) {
                    console.log('InterSoccer: Add to cart failed, resetting isSubmitting');
                    isSubmitting = false;
                    $form.removeClass('intersoccer-form-submitting');
                    $form.trigger('intersoccer_update_button_state');
                }
            });
            
            // Reset isSubmitting after a longer timeout as a safety net (5 seconds)
            // This ensures that if something goes wrong, the form becomes usable again
            $form.on('submit', function() {
                setTimeout(function() {
                    if (isSubmitting) {
                        console.log('InterSoccer: Safety timeout - resetting isSubmitting after 5 seconds');
                        isSubmitting = false;
                        $form.removeClass('intersoccer-form-submitting');
                    }
                }, 5000);
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
                
                // For single-day camps, immediately update price based on selected days
                // This prevents WooCommerce from displaying the base price
                var variationBookingType = '';
                if (variation.attributes) {
                    variationBookingType = variation.attributes['attribute_pa_booking-type'] ||
                                         variation.attributes['attribute_booking-type'] ||
                                         variation.attributes.attribute_pa_booking_type ||
                                         variation.attributes.attribute_booking_type || '';
                }
                
                var isSingleDayBooking = variationBookingType === 'single-days' ||
                                       variationBookingType === 'à la journée' ||
                                       variationBookingType === 'a-la-journee' ||
                                       (variationBookingType && (variationBookingType.toLowerCase().includes('single') || 
                                        variationBookingType.toLowerCase().includes('journée') || 
                                        variationBookingType.toLowerCase().includes('journee')));
                
                if (isSingleDayBooking) {
                    // Check if we have selected days
                    var selectedDays = [];
                    $form.find('.intersoccer-day-checkbox:checked').each(function() {
                        selectedDays.push(jQuery(this).val());
                    });
                    
                    // If days are selected, immediately update price to prevent base price flicker
                    if (selectedDays.length > 0) {
                        var $priceContainer = $('.woocommerce-variation-price');
                        if ($priceContainer.length) {
                            // Get the base price per day from the variation
                            var basePricePerDay = parseFloat(variation.display_regular_price || variation.price || variation.display_price);
                            
                            // Calculate the new price based on selected days
                            var calculatedPrice = basePricePerDay * selectedDays.length;
                            
                            // Add late pickup cost if active
                            var latePickupCost = 0;
                            if (selectedLatePickupOption && selectedLatePickupOption !== 'none') {
                                var variationId = variation.variation_id;
                                if (variationId && latePickupVariationSettings[variationId]) {
                                    var settings = latePickupVariationSettings[variationId];
                                    if (selectedLatePickupOption === 'full-week') {
                                        latePickupCost = settings.full_week_cost || 0;
                                    } else if (selectedLatePickupOption === 'single-days' && selectedLatePickupDays.length > 0) {
                                        var dayCount = selectedLatePickupDays.length;
                                        if (dayCount === 5) {
                                            latePickupCost = settings.full_week_cost || 0;
                                        } else {
                                            latePickupCost = dayCount * (settings.per_day_cost || 0);
                                        }
                                    }
                                    console.log('InterSoccer: Including late pickup cost in show_variation price:', latePickupCost);
                                }
                            }
                            
                            var totalPrice = calculatedPrice + latePickupCost;
                            
                            // Build price HTML matching WooCommerce structure
                            var priceHtml = '<span class="price"><span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">CHF</span>' + totalPrice.toFixed(2) + '</bdi></span></span>';
                            
                            // Set flag to prevent interference
                            $priceContainer.data('intersoccer-updating', true);
                            
                            // Update price immediately after WooCommerce renders (next tick)
                            // This prevents the flicker from base price to calculated price
                            setTimeout(function() {
                                // Double-check we still have selected days
                                var stillSelected = [];
                                $form.find('.intersoccer-day-checkbox:checked').each(function() {
                                    stillSelected.push(jQuery(this).val());
                                });
                                
                                if (stillSelected.length > 0 && $priceContainer.data('intersoccer-updating')) {
                                    $priceContainer.html(priceHtml);
                                    console.log('InterSoccer: Prevented WooCommerce price reset, set calculated price to CHF', totalPrice.toFixed(2), '(camp:', calculatedPrice, '+ late pickup:', latePickupCost + ')');
                                }
                                
                                // Flag will be cleared when AJAX completes in updateCampPrice success handler
                            }, 0);
                        }
                    }
                }
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
                
                // Clear stored base price for new variation so late pickup calculates from correct base
                jQuery('.woocommerce-variation-price').removeData('intersoccer-base-price');
                console.log('InterSoccer Late Pickup: Cleared stored base price for new variation');

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
                
                // Handle late pickup display
                handleLatePickupDisplay(variation.variation_id);

                // Update price for single-day bookings
                // Note: Only on initial variation load when days are already selected
                // Checkbox changes are handled by the dedicated checkbox change handler
                var isSingleDayBooking = variationBookingType === 'single-days' ||
                                       variationBookingType === 'à la journée' ||
                                       variationBookingType === 'a-la-journee' ||
                                       variationBookingType.toLowerCase().includes('single') ||
                                       variationBookingType.toLowerCase().includes('journée') ||
                                       variationBookingType.toLowerCase().includes('journee');
                if (isSingleDayBooking && selectedDays.length > 0) {
                    // Only update if days were already selected (page load with pre-selected days)
                    // Checkbox change events handle price updates for user interactions
                    if (!$form.data('intersoccer-checkbox-changed')) {
                        updateCampPrice($form, selectedDays);
                    }
                }

                // Handle course price updates (info display removed from top of page)
                // v2.0 - Use price_html from variation data if available
                if (productType === 'course') {
                    console.log('InterSoccer: Course variation selected');
                    
                    // Check if variation already has price_html from server
                    if (variation.price_html || variation.display_price_html) {
                        var priceHtml = variation.price_html || variation.display_price_html;
                        console.log('InterSoccer: Using price_html from variation data:', priceHtml);
                        
                        // Update price display - priceHtml now includes <span class="price"> wrapper
                        var $priceContainer = jQuery('.woocommerce-variation-price');
                        if ($priceContainer.length) {
                            // Replace entire content since priceHtml includes the .price wrapper
                            $priceContainer.html(priceHtml);
                            console.log('InterSoccer: Updated price display from variation data');
                        }
                    } else {
                        // Fallback to AJAX if price_html not in variation data
                        console.log('InterSoccer: No price_html in variation, calling AJAX');
                        updateCoursePrice($form, variation);
                    }
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
                    
                    // Store the original per-day price (only if not already stored)
                    if (originalPricePerDay === null) {
                        originalPricePerDay = parseFloat(variation.display_regular_price || variation.price);
                        console.log('InterSoccer: Stored original per-day price:', originalPricePerDay);
                    }
                    
                    // Get currently selected days
                    var selectedDays = [];
                    $form.find('.intersoccer-day-checkbox:checked').each(function() {
                        selectedDays.push(jQuery(this).val());
                    });
                    
                    if (selectedDays.length > 0) {
                        console.log('InterSoccer: Days already selected (' + selectedDays.length + '), will update via checkbox handler');
                        // DO NOT modify variation object here - it causes the base price to compound
                        // The checkbox change handler will update the price via optimistic update + AJAX
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

                // Course info container removed from top of page (no longer needed)

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
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('InterSoccer: WPML translation for ' . $day . ': ' . $translated);
                }
                if ($translated !== $day) { // Only include if actually translated
                    $translations[$day] = $translated;
                }
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer: icl_t function not available');
            }
        }
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: Final translations array: ' . json_encode($translations));
        }
        echo json_encode($translations);
        ?>;

        // Function to update course price display
        function updateCoursePrice($form, variation) {
            console.log('InterSoccer: updateCoursePrice called for variation:', variation.variation_id);

            // Make AJAX call to get course price
            jQuery.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: {
                    action: 'intersoccer_get_course_price',
                    nonce: '<?php echo wp_create_nonce('intersoccer_nonce'); ?>',
                    product_id: '<?php echo esc_js($product_id); ?>',
                    variation_id: variation.variation_id
                },
                success: function(response) {
                    console.log('InterSoccer: Course price AJAX success:', response);
                    if (response.success && response.data.price !== undefined && response.data.price_html) {
                        var price = parseFloat(response.data.price);
                        var priceHtml = response.data.price_html;
                        console.log('InterSoccer: Updating course price display to:', price, 'HTML:', priceHtml);

                        // Update the variation object
                        variation.display_price = price;
                        variation.price = price;
                        variation.display_regular_price = price;
                        variation.price_html = priceHtml;
                        variation.display_price_html = priceHtml;

                        // Update price display elements directly with formatted HTML from server
                        // priceHtml now includes <span class="price"> wrapper to match WooCommerce structure
                        var $priceContainer = jQuery('.woocommerce-variation-price');
                        if ($priceContainer.length) {
                            // Replace entire content since priceHtml includes the .price wrapper
                            $priceContainer.html(priceHtml);
                            console.log('InterSoccer: Updated course price display to:', priceHtml);
                        } else {
                            console.warn('InterSoccer: Price container .woocommerce-variation-price not found, cannot update display');
                        }

                        // Update variation object so cart uses correct price
                        // Don't trigger woocommerce_variation_has_changed - it tries to call wc_price() which doesn't exist
                    } else {
                        console.error('InterSoccer: Course price response failed or missing price_html:', response.data);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('InterSoccer: Course price AJAX error:', status, error, xhr.responseText);
                }
            });
        }

        // Function to update course information display - REMOVED
        // Course info now only displays in the variation table at the bottom
        // function updateCourseInfo($form, variationId) {
        //     console.log('InterSoccer: updateCourseInfo called for variation:', variationId);
        //     if (!variationId || variationId === '0') {
        //         console.log('InterSoccer: No valid variation ID for course info');
        //         $('#intersoccer-course-info').hide();
        //         return;
        //     }
        //     // AJAX call removed - course info container no longer exists at top of page
        // }

        // Shared variables for price request tracking (must be in outer scope)
        var pendingCampPriceRequest = null;
        var campPriceRequestSequence = 0;
        var originalPricePerDay = null;  // Store original per-day price to prevent compounding

        // Function to update camp price when days are selected
        function updateCampPrice($form, selectedDays) {
            console.log('InterSoccer: updateCampPrice called with days:', selectedDays);
            
            var variationId = $form.find('input[name="variation_id"]').val();
            console.log('InterSoccer: variationId found:', variationId);
            
            if (!variationId || variationId === '0') {
                console.log('InterSoccer: No valid variation selected for price update');
                return null;
            }

            // Only make AJAX call if we have days selected or if this is a single-day booking
            var bookingType = $form.find('select[name*="booking-type"]').val() || $form.find('input[name="variation_id"]').closest('.variation').find('select[name*="booking-type"]').val();
            var isSingleDayBooking = bookingType === 'single-day' || bookingType === 'single-days' || bookingType === 'à la journée' || bookingType === 'a-la-journee' || 
                                   (bookingType && (bookingType.includes('single') || bookingType.includes('journée') || bookingType.includes('journee')));
            
            if (isSingleDayBooking && selectedDays.length === 0) {
                console.log('InterSoccer: Single-day booking but no days selected, skipping AJAX');
                return null;
            }

            console.log('InterSoccer: Updating camp price for variation', variationId, 'with days:', selectedDays);
            console.log('InterSoccer: AJAX URL:', '<?php echo admin_url('admin-ajax.php'); ?>');
            console.log('InterSoccer: Nonce:', '<?php echo wp_create_nonce('intersoccer_nonce'); ?>');

            // Make AJAX call to get updated price (return jqXHR for abort capability)
            return jQuery.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: {
                    action: 'intersoccer_calculate_camp_price',
                    nonce: '<?php echo wp_create_nonce('intersoccer_nonce'); ?>',
                    variation_id: variationId,
                    camp_days: selectedDays
                },
                success: function(response, textStatus, jqXHR) {
                    // Check if this response is from the latest request (prevent stale responses)
                    var responseSequence = jqXHR.requestSequence || 0;
                    console.log('InterSoccer: Price response received for request #' + responseSequence);
                    
                    if (responseSequence < campPriceRequestSequence) {
                        console.warn('InterSoccer: Ignoring stale price response #' + responseSequence + ' (current: #' + campPriceRequestSequence + ')');
                        return;
                    }
                    
                    console.log('InterSoccer: Price update AJAX success:', response);
                    if (response.success) {
                        var priceHtml = response.data.price;  // Already formatted HTML from server
                        var rawPrice = response.data.raw_price;
                        console.log('InterSoccer: Updating price display with:', priceHtml);
                        console.log('InterSoccer: Raw price:', rawPrice);
                        
                        // Update the variation price container with properly formatted HTML
                        var $variationPriceContainer = jQuery('.woocommerce-variation-price');
                        if ($variationPriceContainer.length) {
                            $variationPriceContainer.html(priceHtml);
                            console.log('InterSoccer: Updated .woocommerce-variation-price container');
                            
                            // Clear the updating flag now that server response is applied
                            $variationPriceContainer.data('intersoccer-updating', false);
                        } else {
                            console.warn('InterSoccer: .woocommerce-variation-price not found, trying fallback');
                            // Fallback: update price span
                            var $priceElement = jQuery('.single_variation .price').first();
                            if ($priceElement.length) {
                                $priceElement.replaceWith(priceHtml);
                                console.log('InterSoccer: Updated price via fallback');
                            }
                        }
                        
                        // Trigger custom event for late pickup (do NOT trigger woocommerce_variation_has_changed to avoid loops)
                        $form.trigger('intersoccer_price_updated', {rawPrice: rawPrice});
                    } else {
                        console.error('InterSoccer: Price update failed:', response.data);
                    }
                },
                error: function(xhr, status, error) {
                    // Ignore errors from aborted requests
                    if (status === 'abort') {
                        console.log('InterSoccer: Price request aborted (expected for rapid clicking)');
                        return;
                    }
                    console.error('InterSoccer: Price update AJAX error:', status, error, xhr.responseText);
                }
            });
        }

        jQuery(document).ready(function($) {
            var $form = $('form.cart, form.variations_form, .woocommerce-product-details form, .single-product form');
            
            // Prevent WooCommerce from resetting price during our updates
            $form.on('show_variation', function(event, variation) {
                var $priceContainer = $('.woocommerce-variation-price');
                if ($priceContainer.data('intersoccer-updating')) {
                    console.log('InterSoccer: Blocking WooCommerce price reset (update in progress)');
                    // Don't let WooCommerce change the price while we're updating
                    event.stopImmediatePropagation();
                    return false;
                }
            });
            
            // Handle day checkbox changes
            $form.on('change', '.intersoccer-day-checkbox', function() {
                console.log('InterSoccer: Day checkbox changed');
                
                // Set flag to prevent found_variation handler from also calling updateCampPrice
                $form.data('intersoccer-checkbox-changed', true);
                setTimeout(function() {
                    $form.removeData('intersoccer-checkbox-changed');
                }, 100);
                
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
                
                // Optimistic update: Update DOM directly (DO NOT modify variation object or trigger WooCommerce events)
                var variationForm = $form.data('wc_variation_form');
                if (variationForm && variationForm.current_variation) {
                    var variation = variationForm.current_variation;
                    
                    // Use stored original per-day price to prevent compounding issues
                    var basePricePerDay = originalPricePerDay || parseFloat(variation.display_regular_price || variation.price);
                    
                    // If we don't have the original price stored yet, store it now
                    if (originalPricePerDay === null && selectedDays.length <= 1) {
                        originalPricePerDay = basePricePerDay;
                        console.log('InterSoccer: Stored original per-day price:', originalPricePerDay);
                    }
                    
                    var estimatedPrice = selectedDays.length > 0 ? basePricePerDay * selectedDays.length : basePricePerDay;
                    
                    // Build price HTML to match WooCommerce structure
                    var optimisticPriceHtml = '<span class="price"><span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">CHF</span>' + estimatedPrice.toFixed(2) + '</bdi></span></span>';
                    
                    // Update DOM directly (do NOT modify variation object to prevent WooCommerce interference)
                    var $variationPriceContainer = jQuery('.woocommerce-variation-price');
                    if ($variationPriceContainer.length) {
                        $variationPriceContainer.html(optimisticPriceHtml);
                        console.log('InterSoccer: Optimistic price update to CHF', estimatedPrice.toFixed(2), 'for', selectedDays.length, 'days');
                        
                        // Add temporary flag to prevent WooCommerce from overwriting during AJAX
                        $variationPriceContainer.data('intersoccer-updating', true);
                        setTimeout(function() {
                            $variationPriceContainer.data('intersoccer-updating', false);
                        }, 1000);
                    }
                    
                    // DO NOT trigger woocommerce_variation_has_changed - it causes WooCommerce to re-render and flicker
                }
                
                // Cancel any pending price request to prevent race conditions with rapid clicking
                if (pendingCampPriceRequest && pendingCampPriceRequest.abort) {
                    pendingCampPriceRequest.abort();
                    console.log('InterSoccer: Aborted previous price request (rapid clicking detected)');
                }
                
                // Increment sequence number for this request
                var currentSequence = ++campPriceRequestSequence;
                console.log('InterSoccer: Starting price request #' + currentSequence);
                
                // Call AJAX to confirm exact price from server (handles discounts, special rules, etc.)
                // Server response will overwrite optimistic update if different
                pendingCampPriceRequest = updateCampPrice($form, selectedDays);
                
                // Track this request's sequence number
                if (pendingCampPriceRequest) {
                    pendingCampPriceRequest.requestSequence = currentSequence;
                }
            });
        });
    </script>
<?php
});