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

$intersoccer_elementor_product_page_cb = function () {
    global $product;
    if (!is_a($product, 'WC_Product') && function_exists('get_queried_object_id') && function_exists('wc_get_product')) {
        $qpid = (int) get_queried_object_id();
        if ($qpid > 0) {
            $maybe = wc_get_product($qpid);
            if (is_a($maybe, 'WC_Product')) {
                $product = $maybe;
            }
        }
    }
    if (!is_a($product, 'WC_Product')) {
        return;
    }

    if (defined('INTERSOCCER_PV_EXTRAS_EMITTED') && INTERSOCCER_PV_EXTRAS_EMITTED) {
        return;
    }

    $product_id = $product->get_id();
    $user_id = get_current_user_id();
    $is_variable = $product->is_type('variable');
    $product_type = function_exists('intersoccer_get_product_type') ? intersoccer_get_product_type($product_id) : null;

    // Preload days for Camps
    $preloaded_days = [];
    if ($product_type === 'camp') {
        $attributes = $product->get_attributes();

        if (isset($attributes['pa_days-of-week']) && $attributes['pa_days-of-week'] instanceof WC_Product_Attribute) {
            $terms = wc_get_product_terms($product_id, 'pa_days-of-week', ['fields' => 'all']);

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
                    if (isset($day_map[$slug])) {
                        $english_days[] = $day_map[$slug];
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
                } else {
                    $preloaded_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
                }
            } else {
                $preloaded_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
            }
        }
    }

    // Course info container removed - course details now only display in variation table
    // if ($product_type === 'course') {
    //     echo '<div id="intersoccer-course-info" class="intersoccer-course-info" style="display: none; margin: 20px 0; padding: 15px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px;">';
    //     echo '<h4>' . __('Course Information', 'intersoccer-product-variations') . '</h4>';
    //     echo '<div id="intersoccer-course-details"></div>';
    //     echo '</div>';
    // }

    // Player selection HTML
    $account_dashboard_url = wc_get_account_endpoint_url('dashboard');
    $manage_players_url = wc_get_account_endpoint_url('manage-players');

    $player_assignment_i18n = intersoccer_get_player_assignment_strings([
        'dashboard_url' => $account_dashboard_url,
        'manage_players_url' => $manage_players_url,
    ]);

    ob_start();
?>
    <tr class="intersoccer-player-selection intersoccer-injected" data-intersoccer-product-id="<?php echo (int) $product_id; ?>">
        <th class="label"><label for="player_assignment_select_<?php echo (int) $product_id; ?>"><?php echo esc_html($player_assignment_i18n['selectAttendee']); ?></label></th>
        <td class="value">
            <div class="intersoccer-player-content">
                <?php if (!$user_id) : ?>
                    <p class="intersoccer-login-prompt"><?php echo $player_assignment_i18n['loginPromptHtml']; ?></p>
                <?php else : ?>
                    <p class="intersoccer-loading-players"><?php echo esc_html($player_assignment_i18n['loadingPlayers']); ?></p>
                <?php endif; ?>
                <span class="intersoccer-attendee-notification" style="color: red; display: none; margin-top: 10px;"><?php echo esc_html($player_assignment_i18n['selectAttendeeToAdd']); ?></span>
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
    
    intersoccer_debug('=== Late Pickup: Starting HTML generation ===');
    intersoccer_debug('Product type: ' . $product_type);
    intersoccer_debug('Is variable: ' . ($is_variable ? 'yes' : 'no'));
    
    if ($product_type === 'camp' && $is_variable) {
        intersoccer_debug('Late Pickup: Product IS camp and IS variable, proceeding...');
        
        // Get late pickup pricing from settings
        $per_day_cost = floatval(get_option('intersoccer_late_pickup_per_day', 25));
        $full_week_cost = floatval(get_option('intersoccer_late_pickup_full_week', 90));
        
        intersoccer_debug('Late Pickup: Loaded pricing - Per Day: ' . $per_day_cost . ' CHF, Full Week: ' . $full_week_cost . ' CHF');
        
        // Get variation settings for late pickup and available days
        $variations = $product->get_available_variations();
        $variation_settings = [];
        $default_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        
        foreach ($variations as $variation) {
            $variation_id = $variation['variation_id'];
            $enable_late_pickup = get_post_meta($variation_id, '_intersoccer_enable_late_pickup', true);
            $variation_booking_type = get_post_meta($variation_id, 'attribute_pa_booking-type', true);
            if (!$variation_booking_type) {
                $variation_booking_type = get_post_meta($variation_id, 'attribute_pa_booking_type', true);
            }
            if (!$variation_booking_type) {
                $variation_booking_type = get_post_meta($variation_id, 'attribute_booking-type', true);
            }
            if (!$variation_booking_type) {
                $variation_booking_type = get_post_meta($variation_id, 'attribute_booking_type', true);
            }
            if (!$variation_booking_type && isset($variation['attributes']) && is_array($variation['attributes'])) {
                $variation_booking_type = $variation['attributes']['attribute_pa_booking-type']
                    ?? $variation['attributes']['attribute_booking-type']
                    ?? $variation['attributes']['attribute_pa_booking_type']
                    ?? $variation['attributes']['attribute_booking_type']
                    ?? '';
                if (!$variation_booking_type) {
                    foreach ($variation['attributes'] as $attr_key => $attr_value) {
                        $key_l = strtolower((string) $attr_key);
                        if (($key_l && (strpos($key_l, 'booking') !== false || strpos($key_l, 'buchung') !== false)) && !empty($attr_value)) {
                            $variation_booking_type = (string) $attr_value;
                            break;
                        }
                    }
                }
            }
            if (!$variation_booking_type && function_exists('wc_get_product')) {
                $variation_product = wc_get_product($variation_id);
                if ($variation_product && method_exists($variation_product, 'get_attributes')) {
                    $variation_attributes = $variation_product->get_attributes();
                    if (is_array($variation_attributes)) {
                        foreach ($variation_attributes as $attr_key => $attr_value) {
                            $attr_key_l = strtolower((string) $attr_key);
                            if (strpos($attr_key_l, 'booking') !== false || strpos($attr_key_l, 'buchung') !== false) {
                                if (is_string($attr_value) && $attr_value !== '') {
                                    $variation_booking_type = $attr_value;
                                    break;
                                }
                            }
                        }
                    }
                }
                if (!$variation_booking_type && $variation_product && method_exists($variation_product, 'get_variation_attributes')) {
                    $variation_attr_map = $variation_product->get_variation_attributes();
                    if (is_array($variation_attr_map)) {
                        foreach ($variation_attr_map as $attr_key => $attr_value) {
                            $attr_key_l = strtolower((string) $attr_key);
                            if ((strpos($attr_key_l, 'booking') !== false || strpos($attr_key_l, 'buchung') !== false) && !empty($attr_value)) {
                                $variation_booking_type = (string) $attr_value;
                                break;
                            }
                        }
                    }
                }
                if (!$variation_booking_type && $variation_product && method_exists($variation_product, 'get_attribute')) {
                    $attr_value = $variation_product->get_attribute('pa_booking-type');
                    if (is_string($attr_value) && $attr_value !== '') {
                        $variation_booking_type = $attr_value;
                    }
                }
            }
            $variation_booking_type = is_string($variation_booking_type) ? trim($variation_booking_type) : '';
            $variation_is_single_day = function_exists('intersoccer_is_single_day_booking_type')
                ? intersoccer_is_single_day_booking_type($variation_booking_type)
                : false;
            
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
                'booking_type' => $variation_booking_type,
                'is_single_day' => $variation_is_single_day,
            ];
        }
        
        intersoccer_debug('Late Pickup (Elementor): Total variations: ' . count($variations));
        intersoccer_debug('Late Pickup (Elementor): Variations with late pickup enabled: ' . count($variation_settings));
        intersoccer_debug('Late Pickup (Elementor): Variation settings: ' . json_encode($variation_settings));
        
        // ALWAYS generate the HTML for camp products, even if no variations have it enabled yet
        // The JavaScript will show/hide it based on the selected variation
        intersoccer_debug('Late Pickup: Generating HTML now...');
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
        intersoccer_debug('Late Pickup: HTML generated, length: ' . strlen($late_pickup_html));
        intersoccer_debug('Late Pickup: HTML content preview: ' . substr($late_pickup_html, 0, 200));
    } else {
        intersoccer_debug('Late Pickup: NOT generating HTML (product_type=' . $product_type . ', is_variable=' . ($is_variable ? 'yes' : 'no') . ')');
    }
    
    intersoccer_debug('Late Pickup: Final $late_pickup_html empty? ' . (empty($late_pickup_html) ? 'YES' : 'NO'));
?>
    <script>
        jQuery(document).ready(function($) {
            // Debug helper function - only logs when WP_DEBUG is enabled
            var debug = <?php echo (defined('WP_DEBUG') && WP_DEBUG) ? 'function() { console.log.apply(console, arguments); }' : 'function() {}'; ?>;
            var debugWarn = <?php echo (defined('WP_DEBUG') && WP_DEBUG) ? 'function() { console.warn.apply(console, arguments); }' : 'function() {}'; ?>;
            var debugError = <?php echo (defined('WP_DEBUG') && WP_DEBUG) ? 'function() { console.error.apply(console, arguments); }' : 'function() {}'; ?>;

            // Elementor / alternate templates: intersoccer-variation-details may not load, so wp_localize_script never ran — merge PHP-safe defaults.
            window.intersoccerCheckout = $.extend(true, {}, window.intersoccerCheckout || {}, {
                ajax_url: <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,
                nonce: <?php echo wp_json_encode(wp_create_nonce('intersoccer_nonce')); ?>,
                user_id: <?php echo (int) $user_id; ?>
            });
            
            // Find the product form, excluding search forms.
            // Do not blindly use .first(): sticky/duplicate Elementor layouts can register multiple
            // `form.cart` nodes; we need the one that actually hosts the variable product UI.
            function intersoccerPickPrimaryProductForm() {
                var selectors = 'form.variations_form.cart, form.cart:not(.search_form), .woocommerce-product-details form:not(.search_form), .single-product form:not(.search_form)';
                var $candidates = $(selectors).not('.search_form');
                if (!$candidates.length) {
                    $candidates = $('form.variations_form, .woocommerce-product-details form, .single-product form').not('.search_form');
                }
                var $withVariations = $candidates.filter(function () {
                    var $el = $(this);
                    return $el.find('.variations, .woocommerce-variation-add-to-cart, input[name="variation_id"]').length > 0;
                });
                if ($withVariations.length) {
                    $candidates = $withVariations;
                }
                var $scored = $candidates.map(function () {
                    var $el = $(this);
                    var score = 0;
                    if ($el.find('input[name="variation_id"]').length) {
                        score += 5;
                    }
                    if ($el.find('button.single_add_to_cart_button').length) {
                        score += 4;
                    }
                    if ($el.find('.variations').length) {
                        score += 3;
                    }
                    if ($el.hasClass('variations_form')) {
                        score += 2;
                    }
                    if ($el.find('.woocommerce-variation-add-to-cart').length) {
                        score += 1;
                    }
                    return { $el: $el, score: score };
                }).get();
                if (!$scored.length) {
                    return $();
                }
                $scored.sort(function (a, b) {
                    return b.score - a.score;
                });
                return $scored[0].$el.first();
            }

            var $form = intersoccerPickPrimaryProductForm();
            if (!$form.length) {
                $form = $('form.variations_form.cart, form.cart:not(.search_form), .woocommerce-product-details form:not(.search_form), .single-product form:not(.search_form)').first();
            }
            if (!$form.length) {
                $form = $('form.variations_form, .woocommerce-product-details form, .single-product form').not('.search_form').first();
            }

            // Verify we have a product form (has variation_id input or add-to-cart button)
            if ($form.length > 0 && !$form.find('input[name="variation_id"], input[name="add-to-cart"], button.single_add_to_cart_button').length) {
                debugWarn('InterSoccer: Form found but does not appear to be a product form, trying alternative selector');
                $form = $('form').not('.search_form').filter(function() {
                    return $(this).find('input[name="variation_id"], input[name="add-to-cart"], button.single_add_to_cart_button').length > 0;
                }).first();
            }
            
            if (!$form.length) {
                debugError('InterSoccer: Product form not found');
                return;
            }
            
            debug('InterSoccer: Found product form:', $form[0], 'Classes:', $form.attr('class'));

            var intersoccerPvProductId = <?php echo (int) $product_id; ?>;
            var intersoccerPvPlayerSelectId = 'player_assignment_select_' + intersoccerPvProductId;
            var intersoccerPvButtonStateNs = 'intersoccer_update_button_state.intersoccerPvDispatch_' + intersoccerPvProductId;

            /** Fires on document.body so product-enhancer and other modules need not target the same form node as this script's $form. */
            function intersoccerPvDispatchUpdateButtonState() {
                $(document.body).trigger(intersoccerPvButtonStateNs);
            }

            /** Elementor/sticky clones may omit the hidden <input> and only expose a button[name=add-to-cart]. */
            function intersoccerPvFormIsForThisProduct($frm) {
                if (!$frm || !$frm.length) {
                    return false;
                }
                var pid = String(intersoccerPvProductId);
                var ok = false;
                $frm.find('[name="add-to-cart"]').each(function () {
                    var $el = $(this);
                    var v = $el.val();
                    if (v != null && String(v) === pid) {
                        ok = true;
                        return false;
                    }
                    var av = $el.attr('value');
                    if (av != null && String(av) === pid) {
                        ok = true;
                        return false;
                    }
                });
                if (ok) {
                    return true;
                }
                if ($form && $form.length && $frm[0] === $form[0]) {
                    return true;
                }
                if ($frm.find('[data-intersoccer-product-id="' + pid + '"]').length) {
                    return true;
                }
                return false;
            }

            function intersoccerPlayerIndexChosen(v) {
                return v !== null && v !== undefined && v !== '';
            }
            function intersoccerGetPlayerAssignmentSelect($f) {
                var $byRow = $f.find('.intersoccer-player-selection select[name="player_assignment"]').first();
                if ($byRow.length) {
                    return $byRow;
                }
                return $f.find('select[name="player_assignment"]').first();
            }

            /**
             * All selects for this product id. Duplicate DOM (Elementor/sticky) can repeat the same id;
             * jQuery $('#id') / getElementById only returns the first node — often not the one the shopper uses.
             */
            function intersoccerGetPlayerSelectForThisProduct() {
                var $scoped = $('[data-intersoccer-product-id="' + intersoccerPvProductId + '"]').find('select[name="player_assignment"]');
                var $cands = $scoped.length ? $scoped : $('select[name="player_assignment"]').filter(function () {
                    return this.id === intersoccerPvPlayerSelectId;
                });
                if (!$cands.length) {
                    $cands = $('form:not(.search_form)').filter(function () {
                        return intersoccerPvFormIsForThisProduct($(this));
                    }).find('select.intersoccer-player-select[name="player_assignment"]');
                }
                if (!$cands.length) {
                    return $();
                }
                var $inProduct = $cands.filter(function () {
                    var $f = $(this).closest('form').not('.search_form').first();
                    return $f.length && intersoccerPvFormIsForThisProduct($f);
                });
                var $pool = $inProduct.length ? $inProduct : $cands;
                var $withVal = $pool.filter(function () {
                    var v = $(this).val();
                    return v !== null && v !== undefined && v !== '';
                });
                if ($withVal.length) {
                    return $withVal.last();
                }
                return $pool.last();
            }

            /** Any non-empty player index on this product (scans all matching forms — layout vs enhancer may differ). */
            function intersoccerPvAnyScopedPlayerValue() {
                var found = '';
                $('[data-intersoccer-product-id="' + intersoccerPvProductId + '"] select[name="player_assignment"]').each(function () {
                    var v = $(this).val();
                    if (intersoccerPlayerIndexChosen(v)) {
                        found = v;
                        return false;
                    }
                });
                if (intersoccerPlayerIndexChosen(found)) {
                    return found;
                }
                $('form:not(.search_form)').each(function () {
                    var $f = $(this);
                    if (!intersoccerPvFormIsForThisProduct($f)) {
                        return;
                    }
                    $f.find('select[name="player_assignment"]').each(function () {
                        var v = $(this).val();
                        if (intersoccerPlayerIndexChosen(v)) {
                            found = v;
                            return false;
                        }
                    });
                    if (intersoccerPlayerIndexChosen(found)) {
                        return false;
                    }
                });
                return found;
            }

            /** Form that hosts variation_id + camp_days[] for this product (not only the scored-primary $form). */
            function intersoccerGetLayoutForm() {
                var $matches = $('form:not(.search_form)').filter(function () {
                    var $t = $(this);
                    return intersoccerPvFormIsForThisProduct($t)
                        && $t.find('input[name="variation_id"]').length;
                });
                if (!$matches.length) {
                    return $form;
                }
                var $withVar = $matches.filter(function () {
                    var vid = $(this).find('input[name="variation_id"]').val();
                    return vid && vid !== '0' && vid !== '';
                }).first();
                if ($withVar.length) {
                    return $withVar;
                }
                return $matches.first();
            }

            /** Form wrapping this product's player row (may differ from layout form with sticky/Elementor duplicates). */
            function intersoccerResolveProductForm() {
                var $ps = intersoccerGetPlayerSelectForThisProduct();
                if ($ps.length) {
                    var $vf = $ps.closest('form').not('.search_form').first();
                    if ($vf.length) {
                        return $vf;
                    }
                }
                var $fromRow = $('.intersoccer-player-selection').closest('form').not('.search_form').filter(function () {
                    return intersoccerPvFormIsForThisProduct($(this));
                }).first();
                if ($fromRow.length) {
                    return $fromRow;
                }
                $fromRow = $('.intersoccer-player-selection').first().closest('form').not('.search_form').first();
                if ($fromRow.length) {
                    return $fromRow;
                }
                return $form;
            }

            /**
             * Ensure the form that is actually submitted posts a player index.
             * Duplicate Elementor/sticky forms often include an empty <select name="player_assignment"> on the
             * submitted node while the shopper chose a player on another clone — the old early-return skipped
             * syncing in that case (only "no select at all" copied a hidden field).
             */
            function intersoccerEnsurePlayerIndexOnForm($targetForm) {
                if (!$targetForm || !$targetForm.length) {
                    return;
                }
                var $localSelects = $targetForm.find('select[name="player_assignment"]');
                var hasChosenLocal = false;
                $localSelects.each(function () {
                    if (intersoccerPlayerIndexChosen($(this).val())) {
                        hasChosenLocal = true;
                        return false;
                    }
                });
                if (hasChosenLocal) {
                    return;
                }
                var idx = '';
                var $srcSel = intersoccerGetPlayerSelectForThisProduct();
                if ($srcSel.length) {
                    idx = $srcSel.val();
                }
                if (!intersoccerPlayerIndexChosen(idx)) {
                    idx = intersoccerPvAnyScopedPlayerValue();
                }
                if (!intersoccerPlayerIndexChosen(idx) && typeof playerPersistence.getPlayer === 'function') {
                    idx = playerPersistence.getPlayer();
                }
                if (!intersoccerPlayerIndexChosen(idx)) {
                    $srcSel = intersoccerGetPlayerAssignmentSelect(intersoccerResolveProductForm());
                    if ($srcSel.length) {
                        idx = $srcSel.val();
                    }
                }
                if (!intersoccerPlayerIndexChosen(idx)) {
                    $srcSel = $('select.intersoccer-player-select[name="player_assignment"]').filter(':visible').first();
                    if ($srcSel.length) {
                        idx = $srcSel.val();
                    }
                }
                if (!intersoccerPlayerIndexChosen(idx)) {
                    return;
                }
                if ($localSelects.length) {
                    $localSelects.each(function () {
                        if (!intersoccerPlayerIndexChosen($(this).val())) {
                            $(this).val(idx);
                        }
                    });
                    return;
                }
                $targetForm.find('input[type="hidden"][name="player_assignment"]').remove();
                var h = document.createElement('input');
                h.type = 'hidden';
                h.name = 'player_assignment';
                h.value = String(idx);
                $targetForm[0].appendChild(h);
            }

            /**
             * Single-day camp: hidden camp_days[] are built on the primary $form, but another form.cart may submit
             * (sticky/Elementor clone). Copy selected days onto the submitting form so PHP sees $_POST['camp_days'].
             */
            function intersoccerEnsureCampDaysOnForm($targetForm) {
                if (!$targetForm || !$targetForm.length) {
                    return;
                }
                if ('<?php echo esc_js($product_type); ?>' !== 'camp') {
                    return;
                }
                var bt = typeof window.intersoccerGetBookingTypeFromForm === 'function' ? window.intersoccerGetBookingTypeFromForm($targetForm) : '';
                if (!bt || typeof window.intersoccerIsSingleDayBookingType !== 'function' || !window.intersoccerIsSingleDayBookingType(bt)) {
                    var $lfBt = intersoccerGetLayoutForm();
                    if ($lfBt && $lfBt.length) {
                        bt = window.intersoccerGetBookingTypeFromForm($lfBt) || bt;
                    }
                }
                if (!bt && $form && $form.length) {
                    bt = window.intersoccerGetBookingTypeFromForm($form) || '';
                }
                if (typeof window.intersoccerIsSingleDayBookingType !== 'function' || !window.intersoccerIsSingleDayBookingType(bt)) {
                    return;
                }
                if ($targetForm.find('input[name="camp_days[]"]').length > 0) {
                    return;
                }
                var days = [];
                if (typeof selectedDays !== 'undefined' && selectedDays.length) {
                    days = selectedDays.slice();
                }
                if (!days.length) {
                    $('form:not(.search_form)').each(function () {
                        var $f = $(this);
                        if (!intersoccerPvFormIsForThisProduct($f)) {
                            return;
                        }
                        var d = $f.find('input[name="camp_days[]"]').map(function () { return $(this).val(); }).get();
                        if (d.length) {
                            days = d;
                            return false;
                        }
                    });
                }
                if (!days.length && $form && $form.length) {
                    days = $form.find('input[name="camp_days[]"]').map(function () { return $(this).val(); }).get();
                }
                if (!days.length) {
                    var $layout = intersoccerGetLayoutForm();
                    if ($layout && $layout.length) {
                        days = $layout.find('input[name="camp_days[]"]').map(function () { return $(this).val(); }).get();
                    }
                }
                if (!days.length) {
                    $('form:not(.search_form)').each(function () {
                        var $f = $(this);
                        if (!intersoccerPvFormIsForThisProduct($f)) {
                            return;
                        }
                        var d = $f.find('input.intersoccer-day-checkbox:checked').map(function () { return $(this).val(); }).get();
                        if (d.length) {
                            days = d;
                            return false;
                        }
                    });
                }
                days.forEach(function (day) {
                    $targetForm.append($('<input>', { type: 'hidden', name: 'camp_days[]', value: String(day), class: 'intersoccer-camp-day-input' }));
                });
                if (days.length) {
                    debug('InterSoccer: intersoccerEnsureCampDaysOnForm attached', days.length, 'camp day(s) to submitting form');
                }
            }

            function intersoccerEnsureVariationIdOnForm($targetForm) {
                if (!$targetForm || !$targetForm.length) {
                    return;
                }
                if ('<?php echo $is_variable ? 'yes' : 'no'; ?>' !== 'yes') {
                    return;
                }
                var cur = String($targetForm.find('input[name="variation_id"]').val() || '').trim();
                if (cur && cur !== '0') {
                    return;
                }
                var found = '';
                var tryForms = [intersoccerGetLayoutForm(), $form];
                var i, $s, v;
                for (i = 0; i < tryForms.length && !found; i++) {
                    $s = tryForms[i];
                    if ($s && $s.length) {
                        v = String($s.find('input[name="variation_id"]').val() || '').trim();
                        if (v && v !== '0') {
                            found = v;
                        }
                    }
                }
                if (!found) {
                    $('form:not(.search_form)').each(function () {
                        var $f = $(this);
                        if (!intersoccerPvFormIsForThisProduct($f)) {
                            return;
                        }
                        v = String($f.find('input[name="variation_id"]').val() || '').trim();
                        if (v && v !== '0') {
                            found = v;
                            return false;
                        }
                    });
                }
                if (!found) {
                    return;
                }
                var $vid = $targetForm.find('input[name="variation_id"]');
                if ($vid.length) {
                    $vid.val(found);
                } else {
                    $targetForm.append($('<input>', { type: 'hidden', name: 'variation_id', value: found }));
                }
                debug('InterSoccer: intersoccerEnsureVariationIdOnForm set variation_id to', found);
            }

            function intersoccerEnsureVariationAttributesOnForm($targetForm) {
                if (!$targetForm || !$targetForm.length) {
                    return;
                }
                if ('<?php echo $is_variable ? 'yes' : 'no'; ?>' !== 'yes') {
                    return;
                }
                var $source = null;
                [intersoccerGetLayoutForm(), $form].forEach(function ($c) {
                    if ($source) {
                        return;
                    }
                    if ($c && $c.length && $c[0] !== $targetForm[0] && $c.find('input[type="hidden"][name^="attribute_"]').length) {
                        $source = $c;
                    }
                });
                if (!$source || !$source.length) {
                    $('form:not(.search_form)').each(function () {
                        var $f = $(this);
                        if ($f[0] === $targetForm[0] || !intersoccerPvFormIsForThisProduct($f)) {
                            return;
                        }
                        if ($f.find('input[type="hidden"][name^="attribute_"]').length) {
                            $source = $f;
                            return false;
                        }
                    });
                }
                if (!$source || !$source.length) {
                    return;
                }
                $source.find('input[type="hidden"][name^="attribute_"]').each(function () {
                    var n = this.name;
                    if (!n || $targetForm.find('input[name="' + n + '"]').length) {
                        return;
                    }
                    $targetForm.append($('<input>', { type: 'hidden', name: n, value: $(this).val() }));
                });
            }
            
            var intersoccerPlayerI18n = <?php echo wp_json_encode($player_assignment_i18n, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS); ?>;
            
            // CRITICAL FIX: Intercept WooCommerce AJAX add-to-cart for camp and course products
            // We need to prevent AJAX but keep the variation form working
            // This is necessary because AJAX add-to-cart doesn't properly handle custom fields
            <?php if (in_array($product_type, ['camp', 'course']) && $is_variable): ?>
            debug('InterSoccer: Camp/course variable — use full form POST; AJAX add-to-cart disabled in PHP (woocommerce_product_supports ajax_add_to_cart). Never $(document).off("click",".single_add_to_cart_button") — that breaks WC sitewide.');
            <?php endif; ?>
            
            var selectedDays = [];
            var lastVariation = null;
            var lastVariationId = 0;
            var productType = '<?php echo esc_js($product_type); ?>';
            window.intersoccerIsSingleDayBookingType = function (bookingType) {
                if (bookingType == null || bookingType === '') {
                    return false;
                }
                var bt = String(bookingType).toLowerCase();
                if (bt === 'full-week' || bt.indexOf('full-week') !== -1) {
                    return false;
                }
                if (bt.indexOf('ganze') !== -1 && bt.indexOf('woche') !== -1) {
                    return false;
                }
                if (bt === 'single-days' || bt === 'à la journée' || bt === 'a-la-journee') {
                    return true;
                }
                if (bt === 'tag' || /^(?:1[-_])?ein[-_]?tag$/.test(bt) || /^nur[-_]?tag$/.test(bt)) {
                    return true;
                }
                return bt.indexOf('single') !== -1 || bt.indexOf('journée') !== -1 || bt.indexOf('journee') !== -1
                    || bt.indexOf('einzel') !== -1 || bt.indexOf('ein-tag') !== -1 || bt.indexOf('eintag') !== -1 || bt.indexOf('1-tag') !== -1
                    || bt.indexOf('taeglich') !== -1 || bt.indexOf('täglich') !== -1 || bt.indexOf('nur-tag') !== -1
                    || bt.indexOf('pro-tag') !== -1 || bt.indexOf('pro_tag') !== -1
                    || bt.indexOf('pro tag') !== -1 || bt.indexOf('tagesbuchung') !== -1 || bt.indexOf('tages-buchung') !== -1;
            };
            window.intersoccerGetBookingTypeFromForm = function ($f) {
                if (!$f || !$f.length) {
                    return '';
                }
                var v = $f.find('select[name="attribute_pa_booking-type"]').val();
                if (v) {
                    return String(v);
                }
                v = $f.find('select[name="attribute_booking-type"]').val();
                if (v) {
                    return String(v);
                }
                v = $f.find('select[id*="booking-type"], select[id*="buchung"]').val();
                if (v) {
                    return String(v);
                }
                v = $f.find('input[name="attribute_pa_booking-type"]').val();
                if (v) {
                    return String(v);
                }
                v = $f.find('input[name="attribute_booking-type"]').val();
                if (v) {
                    return String(v);
                }
                $f.find('select[name^="attribute_"]').each(function () {
                    var n = (($(this).attr('name') || '')).toLowerCase();
                    if (n.indexOf('booking') !== -1 || n.indexOf('buchung') !== -1) {
                        var u = $(this).val();
                        if (u) {
                            v = u;
                            return false;
                        }
                    }
                });
                if (v) {
                    return String(v);
                }
                var $r = $f.find('input[type="radio"][name^="attribute_"]:checked').filter(function () {
                    var n = (($(this).attr('name') || '')).toLowerCase();
                    return n.indexOf('booking') !== -1 || n.indexOf('buchung') !== -1;
                }).first();
                if ($r.length) {
                    return String($r.val() || '');
                }
                return '';
            };
            var isSubmitting = false;
            var lastValidAvailableDays = null; // Cache last valid available days to prevent reset on player change
            
            // ENHANCEMENT: Player persistence state management
            var playerPersistence = {
                selectedPlayer: '',
                
                setPlayer: function(player) {
                    this.selectedPlayer = player;
                    // Store in DOM for persistence
                    $('body').attr('data-intersoccer-player', player);
                    debug('InterSoccer: Player stored:', player);
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
                    if (!intersoccerPlayerIndexChosen(targetPlayer)) {
                        return '';
                    }
                    var $targets = $('[data-intersoccer-product-id="' + intersoccerPvProductId + '"]').find('select[name="player_assignment"]');
                    if (!$targets.length) {
                        $targets = $('select[name="player_assignment"]').filter(function () {
                            var $f = $(this).closest('form').not('.search_form').first();
                            return $f.length && intersoccerPvFormIsForThisProduct($f);
                        });
                    }
                    if ($targets.length) {
                        $targets.val(String(targetPlayer));
                        debug('InterSoccer: Player restored on', $targets.length, 'select(s):', targetPlayer);
                        return targetPlayer;
                    }
                    return '';
                }
            };

            /**
             * Persist choice, mirror value onto every attendee select for this product, update assigned_attendee on layout form, refresh button state.
             * Used by delegated change so duplicate Elementor/sticky selects all stay in sync.
             */
            function intersoccerApplyPlayerChoice(selectedPlayer) {
                playerPersistence.setPlayer(selectedPlayer);
                var $syncSels = $('[data-intersoccer-product-id="' + intersoccerPvProductId + '"]').find('select.intersoccer-player-select[name="player_assignment"], select.player-select[name="player_assignment"]');
                if (!$syncSels.length) {
                    $syncSels = $('select.intersoccer-player-select[name="player_assignment"], select.player-select[name="player_assignment"]').filter(function () {
                        var $f = $(this).closest('form').not('.search_form').first();
                        return $f.length && intersoccerPvFormIsForThisProduct($f);
                    });
                }
                if ($syncSels.length) {
                    if (intersoccerPlayerIndexChosen(selectedPlayer)) {
                        $syncSels.val(String(selectedPlayer));
                    } else {
                        $syncSels.val('');
                    }
                }
                var $lf = intersoccerGetLayoutForm();
                if (!$lf || !$lf.length) {
                    $lf = $form;
                }
                var existingField = $lf[0] ? $lf[0].querySelector('input[name="assigned_attendee"]') : null;
                if (intersoccerPlayerIndexChosen(selectedPlayer)) {
                    if (existingField) {
                        existingField.value = selectedPlayer;
                    } else if ($lf[0]) {
                        var assignedAttendeeInput = document.createElement('input');
                        assignedAttendeeInput.type = 'hidden';
                        assignedAttendeeInput.name = 'assigned_attendee';
                        assignedAttendeeInput.value = selectedPlayer;
                        $lf[0].appendChild(assignedAttendeeInput);
                    }
                } else if (existingField) {
                    existingField.remove();
                }
                intersoccerPvDispatchUpdateButtonState();
                var variationId = $lf.find('input[name="variation_id"]').val();
                if (variationId && variationId !== '0') {
                    var latePickupSettings = getLatePickupSettings(variationId);
                    if (latePickupSettings && typeof selectedLatePickupOption !== 'undefined' && selectedLatePickupOption !== 'none') {
                        setTimeout(function () {
                            updateLatePickupCost(latePickupSettings);
                        }, 100);
                    }
                }
            }

            $(document.body).off('change.intersoccerPvPlayer' + intersoccerPvProductId, '[data-intersoccer-product-id="' + intersoccerPvProductId + '"] select.intersoccer-player-select[name="player_assignment"], [data-intersoccer-product-id="' + intersoccerPvProductId + '"] select.player-select[name="player_assignment"]')
                .on('change.intersoccerPvPlayer' + intersoccerPvProductId, '[data-intersoccer-product-id="' + intersoccerPvProductId + '"] select.intersoccer-player-select[name="player_assignment"], [data-intersoccer-product-id="' + intersoccerPvProductId + '"] select.player-select[name="player_assignment"]', function () {
                    intersoccerApplyPlayerChoice($(this).val());
                });

            // Inject fields
            function injectFields(retryCount = 0, maxRetries = 10) {
                if ($form.find('.intersoccer-player-selection').length > 0) {
                    debug('InterSoccer: Fields already injected, skipping');
                    return;
                }

                debug('InterSoccer: Injecting fields into form');
                var $variationsTable = $form.find('.variations, .woocommerce-variation');
                debug('InterSoccer: Variations table found:', $variationsTable.length);
                
                if ($variationsTable.length) {
                    var $tbody = $variationsTable.find('tbody, .variations_table');
                    debug('InterSoccer: tbody found:', $tbody.length);
                    
                    if ($tbody.length) {
                        debug('InterSoccer: Appending to tbody');
                        $tbody.append(<?php echo json_encode($player_selection_html, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>);
                        $tbody.append(<?php echo json_encode($day_selection_html, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>);
                        <?php if (!empty($late_pickup_html)): ?>
                        debug('InterSoccer Late Pickup: Injecting late pickup HTML into tbody');
                        $tbody.append(<?php echo json_encode($late_pickup_html, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>);
                        debug('InterSoccer Late Pickup: Late pickup row injected, checking presence:', $tbody.find('.intersoccer-late-pickup-row').length);
                        <?php else: ?>
                        debug('InterSoccer Late Pickup: No late pickup HTML to inject (empty)');
                        <?php endif; ?>
                    } else {
                        debug('InterSoccer: Appending to variations table directly');
                        $variationsTable.append(<?php echo json_encode($player_selection_html, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>);
                        $variationsTable.append(<?php echo json_encode($day_selection_html, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>);
                        <?php if (!empty($late_pickup_html)): ?>
                        debug('InterSoccer Late Pickup: Injecting late pickup HTML into variations table');
                        $variationsTable.append(<?php echo json_encode($late_pickup_html, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>);
                        <?php endif; ?>
                    }
                } else {
                    debug('InterSoccer: No variations table, prepending to form');
                    $form.prepend(<?php echo json_encode($player_selection_html, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>);
                    $form.prepend(<?php echo json_encode($day_selection_html, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>);
                    <?php if (!empty($late_pickup_html)): ?>
                    debug('InterSoccer Late Pickup: Prepending late pickup HTML to form (no variations table)');
                    $form.prepend(<?php echo json_encode($late_pickup_html, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>);
                    <?php endif; ?>
                }

                $form.find('.intersoccer-player-selection').css('display', 'table-row');
                
                // Fetch players
                if (intersoccerCheckout.user_id && intersoccerCheckout.user_id !== '0') {
                    var $playerContent = $form.find('.intersoccer-player-content');
                    var loadingTimeout = setTimeout(function() {
                        $playerContent.find('.intersoccer-loading-players').text(intersoccerPlayerI18n.errorLoadingPlayers);
                        debugError('InterSoccer: Player loading timed out after 10s');
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
                            debug('InterSoccer: AJAX success response:', response);
                            debug('InterSoccer: Response type:', typeof response);
                            debug('InterSoccer: Response success:', response && response.success);
                            debug('InterSoccer: Response data:', response && response.data);
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
                                    var $select = $('<select>', {
                                        name: 'player_assignment',
                                        id: 'player_assignment_select_<?php echo (int) $product_id; ?>',
                                        'class': 'player-select intersoccer-player-select'
                                    });
                                    $select.append(
                                        $('<option>', { value: '' }).text(intersoccerPlayerI18n.selectAttendee)
                                    );
                                    $.each(response.data.players, function(index, player) {
                                        if (player && player.first_name && player.last_name) {
                                            $select.append(
                                                $('<option>', { value: index }).text(player.first_name + ' ' + player.last_name)
                                            );
                                        } else {
                                            debugWarn('InterSoccer: Invalid player data:', player);
                                        }
                                    });
                                    
                                    // Wrap select in container to match WooCommerce variations styling
                                    var $container = $('<div>', { 'class': 'select_container' }).append($select);
                                    $playerContent.empty();
                                    $playerContent.append($container);
                                    $playerContent.append(
                                        $('<span>', {
                                            'class': 'error-message',
                                            style: 'color: red; display: none;'
                                        })
                                    );
                                    $playerContent.append(
                                        $('<span>', {
                                            'class': 'intersoccer-attendee-notification',
                                            style: 'color: red; display: none; margin-top: 10px;'
                                        }).text(intersoccerPlayerI18n.selectAttendeeToAdd)
                                    );
                                    
                                    // ENHANCEMENT: Restore player selection after loading
                                    setTimeout(function() {
                                        var restoredPlayer = playerPersistence.restorePlayer();
                                        if (intersoccerPlayerIndexChosen(restoredPlayer)) {
                                            var existingField = $form[0].querySelector('input[name="assigned_attendee"]');
                                            if (existingField) {
                                                existingField.value = restoredPlayer;
                                            } else {
                                                var assignedAttendeeInput = document.createElement('input');
                                                assignedAttendeeInput.type = 'hidden';
                                                assignedAttendeeInput.name = 'assigned_attendee';
                                                assignedAttendeeInput.value = restoredPlayer;
                                                $form[0].appendChild(assignedAttendeeInput);
                                            }
                                        }
                                        intersoccerPvDispatchUpdateButtonState();
                                    }, 100);
                                    
                                    // Player change: delegated handler on document.body (see intersoccerApplyPlayerChoice) covers duplicate layouts.
                                    
                                    intersoccerPvDispatchUpdateButtonState();
                                    // Re-trigger variation check
                                    $form.trigger('check_variations');
                                } else {
                                    debug('InterSoccer: No players found in response');
                                    $playerContent
                                        .empty()
                                        .append(
                                            $('<p></p>').html(intersoccerPlayerI18n.noPlayersRegisteredHtml)
                                        )
                                        .append(
                                            $('<span>', {
                                                'class': 'intersoccer-attendee-notification',
                                                style: 'color: red; display: block; margin-top: 10px;'
                                            }).text(intersoccerPlayerI18n.pleaseAddPlayer)
                                        );
                                    intersoccerPvDispatchUpdateButtonState();
                                }
                            } catch (e) {
                                debugError('InterSoccer: Player response parsing error:', e.message, 'response:', response);
                                var errorMessage = intersoccerPlayerI18n.errorLoadingPlayersWithMessage.replace('%s', e && e.message ? e.message : '');
                                $playerContent
                                    .empty()
                                    .append(
                                        $('<p></p>').text(errorMessage)
                                    );
                                intersoccerPvDispatchUpdateButtonState();
                            }
                        },
                        error: function(xhr, textStatus, errorThrown) {
                            clearTimeout(loadingTimeout);
                            debugError('InterSoccer: Player AJAX error details:', xhr.status, textStatus, errorThrown, 'response:', xhr.responseText);
                            var rawError = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ? xhr.responseJSON.data.message : (errorThrown || intersoccerPlayerI18n.genericRequestFailed);
                            var formattedError = intersoccerPlayerI18n.errorLoadingPlayersWithMessage.replace('%s', rawError);
                            $playerContent
                                .empty()
                                .append(
                                    $('<p></p>').text(formattedError)
                                )
                                .append(
                                    $('<span>', {
                                        'class': 'intersoccer-attendee-notification',
                                        style: 'color: red; display: block; margin-top: 10px;'
                                    }).text(intersoccerPlayerI18n.resolveError)
                                );
                            intersoccerPvDispatchUpdateButtonState();
                        }
                    });
                } else {
                    $form.find('.intersoccer-attendee-notification').show();
                    intersoccerPvDispatchUpdateButtonState();
                }

                // Trigger variation check after injection
                setTimeout(function() {
                    $form.trigger('check_variations');
                }, 500);
            }

            // Render day checkboxes
            function renderDayCheckboxes(bookingType, $daySelection, $dayCheckboxes, $form) {
                var preloadedDays = JSON.parse($daySelection.attr('data-preloaded-days') || '[]');

                // Translation map for days - English to current language
                var dayTranslations = typeof intersoccerDayTranslations !== 'undefined' ? intersoccerDayTranslations : {
                    'Monday': 'Monday',
                    'Tuesday': 'Tuesday',
                    'Wednesday': 'Wednesday',
                    'Thursday': 'Thursday',
                    'Friday': 'Friday'
                };
                if (Object.keys(latePickupVariationSettings).length === 0) {
                    var $settingsRow = $form.find('.intersoccer-late-pickup-row');
                    if ($settingsRow.length) {
                        try {
                            latePickupVariationSettings = JSON.parse($settingsRow.attr('data-variation-settings') || '{}');
                        } catch (e) {
                            debugError('InterSoccer Debug: Failed to pre-load variation settings:', e);
                        }
                    }
                }

                // Show day selection for camp products with single-day bookings only
                var isCamp = productType === 'camp';
                var isSingleDayBooking = typeof window.intersoccerIsSingleDayBookingType === 'function' && window.intersoccerIsSingleDayBookingType(bookingType);
                if (!isSingleDayBooking) {
                    var formVariationId = String($form.find('input[name="variation_id"]').val() || '');
                    if (formVariationId && latePickupVariationSettings[formVariationId] && latePickupVariationSettings[formVariationId].is_single_day) {
                        isSingleDayBooking = true;
                        if (!bookingType && latePickupVariationSettings[formVariationId].booking_type) {
                            bookingType = String(latePickupVariationSettings[formVariationId].booking_type);
                        }
                    }
                }
                
                if (isCamp && isSingleDayBooking) {
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
                            } catch (e) {
                                debugError('InterSoccer Debug: Failed to parse variation settings:', e);
                            }
                        }
                    }
                    
                    // Try to get available days from variation settings (includes camp day availability)
                    if (variationId && variationId !== '0' && latePickupVariationSettings[variationId]) {
                        var settings = latePickupVariationSettings[variationId];
                        if (settings.available_camp_days && settings.available_camp_days.length > 0) {
                            availableDays = settings.available_camp_days;
                            lastValidAvailableDays = availableDays; // Cache for future use
                        }
                    } else {
                        // Use cached value if available (prevents reset during player selection)
                        if (lastValidAvailableDays && lastValidAvailableDays.length > 0) {
                            availableDays = lastValidAvailableDays;
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
                    // Check multiple sources for which days should be checked
                    var currentChecked = $dayCheckboxes.find('input.intersoccer-day-checkbox:checked').map(function() { return $(this).val(); }).get();
                    var hiddenDays = $form.find('input[name="camp_days[]"]').map(function() { return $(this).val(); }).get();
                    
                    // Sync selectedDays with hidden inputs (source of truth)
                    if (hiddenDays.length > 0) {
                        selectedDays = hiddenDays;
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
                    // Don't show notification if form is being submitted or has been submitted
                    if (isSubmitting) {
                        $dayNotification.hide().css('display', 'none');
                    } else if (actuallyCheckedCount === 0) {
                        $dayNotification.text('Please select at least one day.').css('color', 'red').show();
                    } else {
                        $dayNotification.hide();
                    }

                    var lastCampDayChangeTime = 0;
                    $dayCheckboxes.find('input.intersoccer-day-checkbox').off('change click').on('change click', function(e) {
                        var now = Date.now();
                        if (now - lastCampDayChangeTime < 100) {
                            return;
                        }
                        lastCampDayChangeTime = now;
                        
                        var $checkbox = $(this);

                        selectedDays = $dayCheckboxes.find('input.intersoccer-day-checkbox:checked').map(function() { return $(this).val(); }).get();
                        $form.find('input[name="camp_days[]"]').remove();
                        selectedDays.forEach((day) => {
                            $form.append(`<input type="hidden" name="camp_days[]" value="${day}" class="intersoccer-camp-day-input">`);
                        });
                        // Update day selection notification
                        var $dayNotification = $daySelection.find('.intersoccer-day-notification');
                        if (!isSubmitting) {
                            if (selectedDays.length === 0) {
                                $dayNotification.text('Please select at least one day.').css('color', 'red').show();
                            } else {
                                $dayNotification.hide();
                            }
                        } else {
                            debug('  Checkbox change - form is submitting, keeping notification hidden');
                        }
                        
                        // Price update is handled by the main checkbox change handler in jQuery(document).ready()
                        // to prevent duplicate AJAX calls (removed from here to avoid race conditions)
                        
                        intersoccerPvDispatchUpdateButtonState();
                        // Trigger WooCommerce variation check
                        $form.trigger('check_variations');
                    });
                } else {
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
                    debug('InterSoccer Late Pickup: No valid variation ID for settings lookup');
                    return null;
                }
                
                var settings = latePickupVariationSettings[variationId];
                if (!settings || !settings.enabled) {
                    debug('InterSoccer Late Pickup: No settings found or late pickup not enabled for variation', variationId);
                    return null;
                }
                
                return settings;
            }
            
            function loadLatePickupSettings() {
                var $row = getLatePickupRow();
                if ($row.length) {
                    try {
                        latePickupVariationSettings = JSON.parse($row.attr('data-variation-settings') || '{}');
                        debug('InterSoccer Late Pickup: Variation settings loaded:', latePickupVariationSettings);
                    } catch (e) {
                        debugError('InterSoccer Late Pickup: Failed to parse variation settings:', e);
                    }
                }
            }
            
            function handleLatePickupDisplay(variationId) {
                debug('InterSoccer Late Pickup: handleLatePickupDisplay called for variation', variationId);
                
                var $latePickupRow = getLatePickupRow();
                
                if (!$latePickupRow.length) {
                    debug('InterSoccer Late Pickup: Row element not found');
                    return;
                }
                
                // Listen for price updates from camp days changes and update base price + reapply late pickup
                $form.off('intersoccer_price_updated.latePickup').on('intersoccer_price_updated.latePickup', function(event, data) {
                    debug('InterSoccer Late Pickup: Camp price updated, recalculating with late pickup');
                    
                    // Update stored base price with the new camp price (from AJAX response)
                    var $priceContainer = jQuery('.woocommerce-variation-price');
                    if (data && data.rawPrice) {
                        var newBasePrice = parseFloat(data.rawPrice);
                        $priceContainer.data('intersoccer-base-price', newBasePrice);
                        debug('InterSoccer Late Pickup: Updated base price from AJAX to:', newBasePrice);
                    }
                    
                    // Reapply late pickup cost to the new base price
                    var latePickupSettings = getLatePickupSettings(variationId);
                    if (latePickupSettings) {
                        updateLatePickupCost(latePickupSettings);
                    }
                });
                
                debug('InterSoccer Late Pickup: Row element found!');
                
                // Load settings if not already loaded
                if (Object.keys(latePickupVariationSettings).length === 0) {
                    loadLatePickupSettings();
                }
                
                var settings = latePickupVariationSettings[variationId];
                
                if (settings && settings.enabled) {
                    debug('InterSoccer Late Pickup: Showing late pickup for variation', variationId);
                    $latePickupRow.show();
                    renderLatePickupRadioButtons(settings);
                } else {
                    debug('InterSoccer Late Pickup: Hiding late pickup (not enabled for this variation)');
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
                
                debug('InterSoccer Late Pickup: Available late pickup days from settings:', days);
                
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
                
                // Handle radio button changes (both change and click for iOS Safari)
                var lastRadioChangeTime = 0;
                $optionsContainer.find('.intersoccer-late-pickup-radio').on('change click', function(e) {
                    var now = Date.now();
                    if (now - lastRadioChangeTime < 100) {
                        return;
                    }
                    lastRadioChangeTime = now;
                    
                    selectedLatePickupOption = $(this).val();
                    debug('InterSoccer Late Pickup: Option changed to:', selectedLatePickupOption);
                    
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
                
                // Handle day checkbox changes (both change and click for iOS Safari)
                var lastCheckboxChangeTime = 0;
                $dayCheckboxesContainer.find('.intersoccer-late-pickup-day-checkbox').on('change click', function(e) {
                    var now = Date.now();
                    if (now - lastCheckboxChangeTime < 100) {
                        return;
                    }
                    lastCheckboxChangeTime = now;
                    
                    selectedLatePickupDays = $dayCheckboxesContainer.find('.intersoccer-late-pickup-day-checkbox:checked').map(function() {
                        return $(this).val();
                    }).get();
                    
                    debug('InterSoccer Late Pickup: Selected days:', selectedLatePickupDays);
                    
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
                    debug('InterSoccer Late Pickup: No option selected');
                    
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
                        debug('InterSoccer Late Pickup: Single days selected but no days checked');
                        
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
                
                debug('InterSoccer Late Pickup: Cost updated - option:', selectedLatePickupOption, 'days:', selectedLatePickupDays.length, 'cost:', cost);
                
                // Update main price display to include late pickup cost
                updateMainPriceWithLatePickup(cost);
            }
            
            function updateMainPriceWithLatePickup(latePickupCost) {
                var $priceContainer = jQuery('.woocommerce-variation-price');
                if (!$priceContainer.length) {
                    debug('InterSoccer Late Pickup: Price container not found, skipping main price update');
                    return;
                }
                
                // Check if there's a pending AJAX price update
                // If so, skip updating display - AJAX completion handler will trigger recalculation with correct base
                var pendingUpdate = $priceContainer.data('intersoccer-updating');
                if (pendingUpdate) {
                    debug('InterSoccer Late Pickup: AJAX price update in progress, skipping display update (will recalculate after AJAX)');
                    return;
                }
                
                // Get the base price (must be already stored from variation data)
                var basePrice = parseFloat($priceContainer.data('intersoccer-base-price'));
                if (!basePrice || isNaN(basePrice)) {
                    debug('InterSoccer Late Pickup: Base price not available yet, skipping update');
                    return;
                }
                
                // Calculate new total price
                var totalPrice = basePrice + parseFloat(latePickupCost || 0);
                
                // Build the new price HTML maintaining WooCommerce structure
                var newPriceHtml = '<span class="price"><span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">CHF</span>' + totalPrice.toFixed(2) + '</bdi></span></span>';
                
                // Update the display
                $priceContainer.html(newPriceHtml);
                
                debug('InterSoccer Late Pickup: Updated main price - base:', basePrice, 'late pickup:', latePickupCost, 'total:', totalPrice);
            }
            
            function updateLatePickupFormData(settings) {
                // Remove any existing late pickup hidden inputs
                $form.find('input[name="late_pickup_type"]').remove();
                $form.find('input[name="late_pickup_cost"]').remove();
                $form.find('input[name="late_pickup_days[]"]').remove();
                
                // If "none" is selected, don't add any form data
                if (selectedLatePickupOption === 'none') {
                    debug('InterSoccer Late Pickup: No option selected, not adding form data');
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
                        debug('InterSoccer Late Pickup: Single days selected but no days checked, not adding form data');
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
                    debug('InterSoccer Late Pickup: Invalid option, not adding form data');
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
                
                debug('InterSoccer Late Pickup: Added form data - type:', selectedLatePickupOption, 'cost:', cost, 'days:', daysToSend);
            }

            // Button state update handler - ensures player selection is required for ALL products (body: syncs with product-enhancer triggers)
            $(document.body).off(intersoccerPvButtonStateNs).on(intersoccerPvButtonStateNs, function () {
                var $layoutForm = intersoccerGetLayoutForm();
                var $playerRowForm = intersoccerResolveProductForm();
                var $playerSel = intersoccerGetPlayerSelectForThisProduct();
                var playerId = $playerSel.length ? $playerSel.val() : intersoccerGetPlayerAssignmentSelect($playerRowForm).val();
                if (!intersoccerPlayerIndexChosen(playerId) && typeof playerPersistence.getPlayer === 'function') {
                    var persistedPlayer = playerPersistence.getPlayer();
                    if (intersoccerPlayerIndexChosen(persistedPlayer)) {
                        playerId = persistedPlayer;
                    }
                }
                if (!intersoccerPlayerIndexChosen(playerId)) {
                    var widenedPlayer = intersoccerPvAnyScopedPlayerValue();
                    if (intersoccerPlayerIndexChosen(widenedPlayer)) {
                        playerId = widenedPlayer;
                    }
                }
                var $button = $layoutForm.find('button.single_add_to_cart_button, input[type="submit"][name="add-to-cart"]');
                var bookingType = typeof window.intersoccerGetBookingTypeFromForm === 'function' ? window.intersoccerGetBookingTypeFromForm($layoutForm) : '';
                if (!bookingType && typeof window.intersoccerGetBookingTypeFromForm === 'function') {
                    bookingType = window.intersoccerGetBookingTypeFromForm($form) || '';
                }
                if (!bookingType && typeof window.intersoccerGetBookingTypeFromForm === 'function') {
                    $('form:not(.search_form)').each(function () {
                        var $f = $(this);
                        if (!intersoccerPvFormIsForThisProduct($f)) {
                            return;
                        }
                        bookingType = window.intersoccerGetBookingTypeFromForm($f) || '';
                        if (bookingType) {
                            return false;
                        }
                    });
                }
                var hasPlayer = intersoccerPlayerIndexChosen(playerId);
                
                // For variable products, also check if variation is selected (variation may live on a different form.cart than layout)
                var variationIdResolved = '';
                var variationSelected = true;
                if ('<?php echo $is_variable ? 'yes' : 'no'; ?>' === 'yes') {
                    variationIdResolved = $layoutForm.find('input[name="variation_id"]').val() || '';
                    if (!variationIdResolved || variationIdResolved === '0') {
                        $('form:not(.search_form)').each(function () {
                            var $f = $(this);
                            if (!intersoccerPvFormIsForThisProduct($f)) {
                                return;
                            }
                            var vid = $f.find('input[name="variation_id"]').val() || '';
                            if (vid && vid !== '0') {
                                variationIdResolved = vid;
                                return false;
                            }
                        });
                    }
                    variationSelected = !!(variationIdResolved && variationIdResolved !== '' && variationIdResolved !== '0');
                }
                
                // For single-day bookings, check if days are selected
                var daysSelected = true;
                if (typeof window.intersoccerIsSingleDayBookingType === 'function' && window.intersoccerIsSingleDayBookingType(bookingType)) {
                    var selectedDaysCount = 0;
                    $('form:not(.search_form)').each(function () {
                        var $f = $(this);
                        if (!intersoccerPvFormIsForThisProduct($f)) {
                            return;
                        }
                        selectedDaysCount += $f.find('input[name="camp_days[]"]').length;
                    });
                    daysSelected = selectedDaysCount > 0;
                }
                
                // Enable button only if player is selected AND (for variable products: variation is selected) AND (for single-day bookings: days are selected)
                var shouldEnable = hasPlayer && variationSelected && daysSelected;
                
                // Manage notifications based on what's missing (scope to this product's player row)
                var $attendeeNotification = $playerSel.closest('.intersoccer-player-selection').find('.intersoccer-attendee-notification');
                if (!$attendeeNotification.length) {
                    $attendeeNotification = $layoutForm.find('.intersoccer-attendee-notification');
                }
                var $dayNotification = $layoutForm.find('.intersoccer-day-notification');
                var isSingleDayBooking = typeof window.intersoccerIsSingleDayBookingType === 'function' && window.intersoccerIsSingleDayBookingType(bookingType);
                
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

                var pvPid = '<?php echo (int) $product_id; ?>';
                $('form').not('.search_form').each(function () {
                    var $tf = $(this);
                    if (!intersoccerPvFormIsForThisProduct($tf)) {
                        return;
                    }
                    $tf.find('button.single_add_to_cart_button, input[type="submit"][name="add-to-cart"]').prop('disabled', !shouldEnable);
                    if (!shouldEnable) {
                        $tf.find('button.single_add_to_cart_button, input[type="submit"][name="add-to-cart"]').addClass('disabled');
                    } else {
                        $tf.find('button.single_add_to_cart_button, input[type="submit"][name="add-to-cart"]').removeClass('disabled');
                    }
                });
            });

            <?php
            $intersoccer_pv_bind_forms = (in_array($product_type, ['camp', 'course'], true) && $is_variable);
            ?>
            var $pvForms = $form;
            <?php if ($intersoccer_pv_bind_forms): ?>
            $pvForms = $('form:not(.search_form)').filter(function () {
                return intersoccerPvFormIsForThisProduct($(this));
            });
            if (!$pvForms.length) {
                $pvForms = $form;
            } else if ($form.length && !$pvForms.is($form)) {
                $pvForms = $pvForms.add($form);
            }
            <?php endif; ?>

            // Handle form submission (all WC forms for this product when camp/course + variable — sticky/duplicate layouts)
            $pvForms.on('submit', function(e) {
                debug('InterSoccer: Form submit event triggered');
                
                // Verify this is the product form, not the search form
                var $currentForm = $(this);
                if ($currentForm.hasClass('search_form') || $currentForm.attr('role') === 'search') {
                    debugWarn('InterSoccer: Submit handler triggered on search form, ignoring');
                    return; // Don't prevent default, let search form submit normally
                }
                
                // Double-check: ensure this form has product form elements
                if (!$currentForm.find('input[name="variation_id"], input[name="add-to-cart"], button.single_add_to_cart_button').length) {
                    debugWarn('InterSoccer: Submit handler triggered on non-product form, ignoring');
                    return; // Don't prevent default
                }
                
                <?php if (in_array($product_type, ['camp', 'course']) && $is_variable): ?>
                // For camp and course products, validate before forcing standard POST submission
                // Ensure we're using the product form, not the search form
                var $productForm = $currentForm;
                if ($productForm.hasClass('search_form') || $productForm.attr('role') === 'search') {
                    debugError('InterSoccer: ERROR - Attempting to validate search form instead of product form!');
                    // Try to find the actual product form
                    $productForm = $('form.variations_form.cart, form.cart:not(.search_form), .woocommerce-product-details form:not(.search_form), .single-product form:not(.search_form)').first();
                    if (!$productForm.length || !$productForm.find('input[name="variation_id"], input[name="add-to-cart"], button.single_add_to_cart_button').length) {
                        debugError('InterSoccer: Could not find product form, aborting');
                        e.preventDefault();
                        e.stopImmediatePropagation();
                        return false;
                    }
                    debug('InterSoccer: Found correct product form for validation:', $productForm[0]);
                }
                intersoccerEnsurePlayerIndexOnForm($productForm);
                intersoccerEnsureCampDaysOnForm($productForm);
                intersoccerEnsureVariationIdOnForm($productForm);
                intersoccerEnsureVariationAttributesOnForm($productForm);

                // Check if button is disabled (indicates form is not ready)
                var $submitButton = $productForm.find('button.single_add_to_cart_button, input[type="submit"][name="add-to-cart"]');
                if ($submitButton.length && ($submitButton.prop('disabled') || $submitButton.hasClass('disabled') || $submitButton.hasClass('wc-variation-selection-needed'))) {
                    debug('InterSoccer: ❌ Validation failed - submit button is disabled');
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    return false;
                }
                
                // Validate required fields before submission
                var variationId = $productForm.find('input[name="variation_id"]').val();
                var $playerSelect = intersoccerGetPlayerSelectForThisProduct();
                var playerAssignment = $playerSelect.length > 0 ? $playerSelect.val() : '';
                if (!intersoccerPlayerIndexChosen(playerAssignment)) {
                    $playerSelect = intersoccerGetPlayerAssignmentSelect($productForm);
                    playerAssignment = $playerSelect.length > 0 ? $playerSelect.val() : '';
                }
                if (!intersoccerPlayerIndexChosen(playerAssignment)) {
                    var $hidPa = $productForm.find('input[type="hidden"][name="player_assignment"]');
                    if ($hidPa.length) {
                        playerAssignment = $hidPa.val();
                    }
                }
                if (!intersoccerPlayerIndexChosen(playerAssignment)) {
                    var $srcSel2 = intersoccerGetPlayerAssignmentSelect(intersoccerResolveProductForm());
                    playerAssignment = $srcSel2.length > 0 ? $srcSel2.val() : playerAssignment;
                }
                if (!intersoccerPlayerIndexChosen(playerAssignment)) {
                    var wPv = intersoccerPvAnyScopedPlayerValue();
                    if (intersoccerPlayerIndexChosen(wPv)) {
                        playerAssignment = wPv;
                    }
                }
                var bookingType = typeof window.intersoccerGetBookingTypeFromForm === 'function' ? window.intersoccerGetBookingTypeFromForm($productForm) : '';
                var campDays = $productForm.find('input[name="camp_days[]"]').map(function() { return $(this).val(); }).get();
                
                // CRITICAL: Ensure assigned_attendee field exists BEFORE reading its value
                // This must happen before validation to ensure the field is present
                if (intersoccerPlayerIndexChosen(playerAssignment)) {
                    var existingField = $productForm[0].querySelector('input[name="assigned_attendee"]');
                    if (existingField) {
                        // Field exists - just update the value
                        existingField.value = playerAssignment;
                        debug('InterSoccer: Updated existing assigned_attendee field with value:', playerAssignment);
                    } else {
                        // Field doesn't exist - create it
                        var assignedAttendeeInput = document.createElement('input');
                        assignedAttendeeInput.type = 'hidden';
                        assignedAttendeeInput.name = 'assigned_attendee';
                        assignedAttendeeInput.value = playerAssignment;
                        $productForm[0].appendChild(assignedAttendeeInput);
                        debug('InterSoccer: Created assigned_attendee field with value:', playerAssignment);
                    }
                }
                
                // NOW read the assigned_attendee value after ensuring the field exists
                var $assignedAttendeeInput = $productForm.find('input[name="assigned_attendee"]');
                var assignedAttendee = $assignedAttendeeInput.length > 0 ? $assignedAttendeeInput.val() : '';
                
                debug('InterSoccer: Validation - Variation ID:', variationId, 'Player:', playerAssignment, 'Attendee:', assignedAttendee);
                
                // Check if variation is selected
                if (!variationId || variationId === '0' || variationId === '') {
                    debug('InterSoccer: ❌ Validation failed - no variation selected');
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    alert('<?php echo esc_js(__('Please select a variation.', 'intersoccer-product-variations')); ?>');
                    return false;
                }
                
                // Note: Course attendee validation is handled server-side in cart-calculations.php
                // This prevents false positives from client-side validation timing issues
                
                // For single-day camps, require camp days
                <?php if ($product_type === 'camp'): ?>
                var isSingleDay = typeof window.intersoccerIsSingleDayBookingType === 'function' && window.intersoccerIsSingleDayBookingType(bookingType);
                
                if (isSingleDay && (!campDays || campDays.length === 0)) {
                    debug('InterSoccer: ❌ Validation failed - no camp days selected for single-day camp');
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    alert('<?php echo esc_js(__('Please select at least one day for this single-day camp.', 'intersoccer-product-variations')); ?>');
                    return false;
                }
                <?php endif; ?>
                
                // All validations passed, proceed with standard POST submission
                // Ensure we're using the product form, not the search form
                var $productForm = $currentForm;
                if ($productForm.hasClass('search_form') || $productForm.attr('role') === 'search') {
                    debugError('InterSoccer: ERROR - Attempting to submit search form instead of product form!');
                    // Try to find the actual product form
                    $productForm = $('form.variations_form.cart, form.cart:not(.search_form), .woocommerce-product-details form:not(.search_form), .single-product form:not(.search_form)').first();
                    if (!$productForm.length || !$productForm.find('input[name="variation_id"], input[name="add-to-cart"], button.single_add_to_cart_button').length) {
                        debugError('InterSoccer: Could not find product form, aborting');
                        e.preventDefault();
                        e.stopImmediatePropagation();
                        return false;
                    }
                    debug('InterSoccer: Found correct product form:', $productForm[0]);
                }
                
                // Ensure form action is set to current product page URL
                // This ensures WooCommerce can determine the correct referer for redirects
                var currentUrl = window.location.href.split('?')[0]; // Remove query params
                var formAction = $productForm.attr('action');
                
                debug('===== InterSoccer: Form Submission Debug =====');
                debug('InterSoccer Form: window.location.href:', window.location.href);
                debug('InterSoccer Form: currentUrl (cleaned):', currentUrl);
                debug('InterSoccer Form: Form action (before):', formAction);
                debug('InterSoccer Form: Form method:', $form.attr('method'));
                
                // Set form action to current page if not already set or if it's empty/invalid
                var home_url = '<?php echo esc_js(home_url()); ?>';
                if (!formAction || formAction === '' || formAction === '#' || formAction.indexOf('?') !== -1 || formAction === home_url || formAction === home_url + '/') {
                    $productForm.attr('action', currentUrl);
                    debug('InterSoccer Form: Set form action to current page:', currentUrl);
                } else {
                    debug('InterSoccer Form: Form action already set:', formAction);
                    // If form action is home page, update it to product page
                    if (formAction === home_url || formAction === home_url + '/') {
                        $productForm.attr('action', currentUrl);
                        debug('InterSoccer Form: Form action was home page, updated to product page:', currentUrl);
                    }
                }
                
                // Ensure form method is POST
                $productForm.attr('method', 'post');
                
                // Log all form data before submission
                var formData = {};
                $productForm.serializeArray().forEach(function(item) {
                    if (formData[item.name]) {
                        if (Array.isArray(formData[item.name])) {
                            formData[item.name].push(item.value);
                        } else {
                            formData[item.name] = [formData[item.name], item.value];
                        }
                    } else {
                        formData[item.name] = item.value;
                    }
                });
                debug('InterSoccer Form: Form data to be submitted:', formData);
                
                // Check for _wp_http_referer field
                var wpRefererField = $productForm.find('input[name="_wp_http_referer"]');
                if (wpRefererField.length) {
                    debug('InterSoccer Form: _wp_http_referer field found, value:', wpRefererField.val());
                } else {
                    debug('InterSoccer Form: _wp_http_referer field NOT found, adding it');
                    // Add _wp_http_referer field to ensure WooCommerce gets correct referer
                    $productForm.append($('<input>', {
                        type: 'hidden',
                        name: '_wp_http_referer',
                        value: currentUrl
                    }));
                }
                
                // Stop all other handlers from executing (like v1.11.22)
                e.stopImmediatePropagation();
                
                debug('InterSoccer Form: ✅ Validation passed - Forcing standard POST submission for <?php echo $product_type; ?> product');
                
                // Get the actual form element from the product form
                var formElement = $productForm[0];
                
                // Log final form state before submission
                debug('InterSoccer Form: Final form action:', formElement.action);
                debug('InterSoccer Form: Final form method:', formElement.method);
                debug('InterSoccer Form: Form element:', formElement);
                debug('InterSoccer Form: Form classes:', formElement.className);
                
                // Submit the product form natively (bypassing all jQuery handlers)
                // This matches v1.11.22 behavior which was working correctly
                // Note: Validation is handled server-side in cart-calculations.php
                setTimeout(function() {
                    debug('InterSoccer Form: Native form submit executed');
                    debug('InterSoccer Form: Submitting to action:', formElement.action);
                    debug('InterSoccer Form: Form method:', formElement.method);
                    debug('===== InterSoccer: Form Submission Debug End =====');
                    
                    // Catch any JavaScript errors during submission
                    try {
                        formElement.submit();
                    } catch (error) {
                        debugError('InterSoccer Form: Error during form submission:', error);
                    }
                }, 10);
                
                return;  // Don't execute any code below this
                <?php endif; ?>
                
                isSubmitting = true;
                
                // Debug: Check what data is in the form
                var campDays = $currentForm.find('input[name="camp_days[]"]').map(function() { return $(this).val(); }).get();
                var latePickupDays = $currentForm.find('input[name="late_pickup_days[]"]').map(function() { return $(this).val(); }).get();
                var latePickupCost = $currentForm.find('input[name="late_pickup_cost"]').val();
                var variationId = $currentForm.find('input[name="variation_id"]').val();
                var assignedAttendee = $currentForm.find('input[name="assigned_attendee"]').val();
                
                debug('InterSoccer: Form data at submission:');
                debug('  - Camp days:', campDays);
                debug('  - Late pickup days:', latePickupDays);
                debug('  - Late pickup cost:', latePickupCost);
                debug('  - Variation ID:', variationId);
                debug('  - Assigned attendee:', assignedAttendee);
                debug('  - Selected days variable:', selectedDays);
                
                // Hide all notifications during submission
                $currentForm.addClass('intersoccer-form-submitting');
                $currentForm.find('.intersoccer-attendee-notification').hide().css('display', 'none');
                $currentForm.find('.intersoccer-day-notification').hide().css('display', 'none');
                
                debug('InterSoccer: Form submitting, isSubmitting =', isSubmitting);
                
                // Don't automatically reset - only reset if there's an error
                // The adding_to_cart event will handle successful submissions
            });
            
            // Also handle button click directly (delegated: sticky bars / duplicate forms may place the button outside the primary $form)
            $(document.body).off('click.intersoccerPvAtc<?php echo (int) $product_id; ?>').on('click.intersoccerPvAtc<?php echo (int) $product_id; ?>', 'form:not(.search_form) button.single_add_to_cart_button, form:not(.search_form) input[type="submit"][name="add-to-cart"]', function(e) {
                debug('=== InterSoccer: Buy Now button clicked ===');
                var $button = $(this);
                var $productForm = $button.closest('form').not('.search_form');
                if (!intersoccerPvFormIsForThisProduct($productForm)) {
                    return;
                }
                if (!$productForm.length) {
                    $productForm = $form;
                }
                intersoccerEnsurePlayerIndexOnForm($productForm);
                intersoccerEnsureCampDaysOnForm($productForm);
                intersoccerEnsureVariationIdOnForm($productForm);
                intersoccerEnsureVariationAttributesOnForm($productForm);

                // Debug: Log form data BEFORE any checks
                var campDaysBeforeSubmit = $productForm.find('input[name="camp_days[]"]').map(function() { return $(this).val(); }).get();
                var bookingType = typeof window.intersoccerGetBookingTypeFromForm === 'function' ? window.intersoccerGetBookingTypeFromForm($productForm) : '';
                var variationId = $productForm.find('input[name="variation_id"]').val();
                var $playerSelect = intersoccerGetPlayerSelectForThisProduct();
                var playerId = $playerSelect.length ? $playerSelect.val() : intersoccerGetPlayerAssignmentSelect($productForm).val();
                
                debug('InterSoccer Button Click: Player select found?', $playerSelect.length, 'selector:', $playerSelect.attr('name'));
                debug('InterSoccer Button Click: Player select element:', $playerSelect[0]);
                
                debug('InterSoccer Button Click: Booking type:', bookingType);
                debug('InterSoccer Button Click: Variation ID:', variationId);
                debug('InterSoccer Button Click: Player ID:', playerId);
                debug('InterSoccer Button Click: Camp days in form:', campDaysBeforeSubmit);
                debug('InterSoccer Button Click: Selected days variable:', selectedDays);
                debug('InterSoccer Button Click: Button disabled?', $button.prop('disabled'));
                
                // Check if button is disabled
                if ($button.prop('disabled')) {
                    debug('InterSoccer: ❌ Button is DISABLED, preventing submission');
                    e.preventDefault();
                    return false;
                }
                
                // If no camp days in form but we have selectedDays, add them
                if (campDaysBeforeSubmit.length === 0 && selectedDays.length > 0) {
                    debug('InterSoccer: ⚠️  WARNING - No camp_days[] inputs found, but selectedDays has:', selectedDays);
                    debug('InterSoccer: Adding camp days to form now...');
                    selectedDays.forEach(function(day) {
                        $productForm.append('<input type="hidden" name="camp_days[]" value="' + day + '" class="intersoccer-camp-day-input">');
                    });
                    var afterAdd = $productForm.find('input[name="camp_days[]"]').map(function() { return $(this).val(); }).get();
                    debug('InterSoccer: ✅ Added camp days to form, now have:', afterAdd);
                }
                
                isSubmitting = true;
                
                // Hide all notifications and add a class to track submission
                $productForm.addClass('intersoccer-form-submitting');
                $productForm.find('.intersoccer-attendee-notification').hide().css('display', 'none');
                $productForm.find('.intersoccer-day-notification').hide().css('display', 'none');
                
                debug('InterSoccer: ✅ Proceeding with form submission, isSubmitting =', isSubmitting);
            });
            
            // Handle successful add to cart
            $(document.body).on('added_to_cart', function() {
                debug('InterSoccer: Product successfully added to cart');
                // Keep isSubmitting = true so notifications don't reappear
                // Page might redirect or cart drawer might open
            });
            
            // Handle add to cart errors
            $(document.body).on('wc_fragments_refreshed wc_cart_fragments_refreshed', function() {
                // Check if there are error notices
                if ($('.woocommerce-error, .woocommerce-message--error').length > 0) {
                    debug('InterSoccer: Add to cart failed, resetting isSubmitting');
                    isSubmitting = false;
                    $form.removeClass('intersoccer-form-submitting');
                    intersoccerPvDispatchUpdateButtonState();
                }
            });
            
            // Reset isSubmitting after a longer timeout as a safety net (5 seconds)
            // This ensures that if something goes wrong, the form becomes usable again
            $form.on('submit', function() {
                setTimeout(function() {
                    if (isSubmitting) {
                        debug('InterSoccer: Safety timeout - resetting isSubmitting after 5 seconds');
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
            
            // Initial render of day checkboxes
            var initialBookingType = typeof window.intersoccerGetBookingTypeFromForm === 'function' ? window.intersoccerGetBookingTypeFromForm($form) : '';
            renderDayCheckboxes(initialBookingType, $daySelection, $dayCheckboxes, $form);

            // Handle booking type change
            $form.on('change', 'select[name="attribute_pa_booking-type"], select[name="attribute_booking-type"], select[id*="booking-type"], select[id*="buchung"], select[name*="buchung"]', function() {
                var bookingType = typeof window.intersoccerGetBookingTypeFromForm === 'function' ? window.intersoccerGetBookingTypeFromForm($form) : $(this).val();
                // Trigger WooCommerce variation check when booking type changes
                setTimeout(function() {
                    $form.trigger('check_variations');
                    intersoccerPvDispatchUpdateButtonState();
                }, 100);
                
                renderDayCheckboxes(bookingType, $daySelection, $dayCheckboxes, $form);
            });
            $form.on('change', 'input[type="radio"][name^="attribute_"]', function() {
                var n = (($(this).attr('name') || '')).toLowerCase();
                if (n.indexOf('booking') === -1 && n.indexOf('buchung') === -1) {
                    return;
                }
                var bookingType = typeof window.intersoccerGetBookingTypeFromForm === 'function' ? window.intersoccerGetBookingTypeFromForm($form) : $(this).val();
                setTimeout(function() {
                    $form.trigger('check_variations');
                    intersoccerPvDispatchUpdateButtonState();
                }, 100);
                renderDayCheckboxes(bookingType, $daySelection, $dayCheckboxes, $form);
            });

            // Monitor all WooCommerce variation events for debugging
            $form.on('woocommerce_variation_has_changed', function() {
                debug('InterSoccer: woocommerce_variation_has_changed triggered');
            });
            
            $form.on('show_variation', function(event, variation) {
                debug('InterSoccer: show_variation triggered:', variation);
                
                // For single-day camps, immediately update price based on selected days
                // This prevents WooCommerce from displaying the base price
                var variationBookingType = '';
                if (variation.attributes) {
                    variationBookingType = variation.attributes['attribute_pa_booking-type'] ||
                                         variation.attributes['attribute_booking-type'] ||
                                         variation.attributes.attribute_pa_booking_type ||
                                         variation.attributes.attribute_booking_type || '';
                    if (!variationBookingType) {
                        var svKeys = Object.keys(variation.attributes);
                        for (var si = 0; si < svKeys.length; si++) {
                            var sk = svKeys[si];
                            var skl = sk.toLowerCase();
                            if ((skl.indexOf('booking') !== -1 || skl.indexOf('buchung') !== -1) && variation.attributes[sk]) {
                                variationBookingType = variation.attributes[sk];
                                break;
                            }
                        }
                    }
                }
                if (!variationBookingType) {
                    variationBookingType = typeof window.intersoccerGetBookingTypeFromForm === 'function' ? window.intersoccerGetBookingTypeFromForm($form) : '';
                }
                if (!variationBookingType) {
                    var showVariationId = variation && variation.variation_id ? String(variation.variation_id) : '';
                    if (showVariationId && latePickupVariationSettings[showVariationId] && latePickupVariationSettings[showVariationId].booking_type) {
                        variationBookingType = String(latePickupVariationSettings[showVariationId].booking_type);
                    }
                }
                
                var isSingleDayBooking = typeof window.intersoccerIsSingleDayBookingType === 'function' && window.intersoccerIsSingleDayBookingType(variationBookingType);
                if (!isSingleDayBooking) {
                    var showVid = variation && variation.variation_id ? String(variation.variation_id) : '';
                    if (showVid && latePickupVariationSettings[showVid] && latePickupVariationSettings[showVid].is_single_day) {
                        isSingleDayBooking = true;
                    }
                }
                
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
                                    debug('InterSoccer: Including late pickup cost in show_variation price:', latePickupCost);
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
                                    debug('InterSoccer: Prevented WooCommerce price reset, set calculated price to CHF', totalPrice.toFixed(2), '(camp:', calculatedPrice, '+ late pickup:', latePickupCost + ')');
                                }
                                
                                // Flag will be cleared when AJAX completes in updateCampPrice success handler
                            }, 0);
                        }
                    }
                }
            });
            
            $form.on('hide_variation', function() {
                debug('InterSoccer: hide_variation triggered');
            });

            // Monitor WooCommerce variation changes
            $form.on('found_variation', function(event, variation) {
                debug('InterSoccer: WooCommerce found variation:', variation);
                debug('InterSoccer: Variation attributes:', variation.attributes);

                // Only clear and reset base price if variation ID actually changed
                // Store variation ID on form (more stable than price container which may get HTML replaced)
                var previousVariationId = $form.data('intersoccer-variation-id');
                var currentVariationId = variation.variation_id;
                
                // Debug: Log the comparison
                debug('InterSoccer: Variation ID check - Previous:', previousVariationId, 'Current:', currentVariationId, 'Same?', previousVariationId == currentVariationId);
                
                if (previousVariationId != currentVariationId) {
                    // New variation - store its base price from the variation data
                    var basePrice = parseFloat(variation.display_price) || 0;
                    var $priceContainer = jQuery('.woocommerce-variation-price');
                    $priceContainer.data('intersoccer-base-price', basePrice);
                    $form.data('intersoccer-variation-id', currentVariationId); // Store ID on form (stable element)
                    debug('InterSoccer Late Pickup: New variation detected, storing base price from variation data:', basePrice, 'Variation ID:', currentVariationId);
                } else {
                    debug('InterSoccer Late Pickup: Same variation ID', currentVariationId, ', preserving stored base price');
                }

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
                    if (!variationBookingType) {
                        var fvKeys = Object.keys(variation.attributes);
                        for (var fi = 0; fi < fvKeys.length; fi++) {
                            var fk = fvKeys[fi];
                            var fkl = fk.toLowerCase();
                            if ((fkl.indexOf('booking') !== -1 || fkl.indexOf('buchung') !== -1) && variation.attributes[fk]) {
                                variationBookingType = variation.attributes[fk];
                                break;
                            }
                        }
                    }
                }
                if (!variationBookingType) {
                    variationBookingType = typeof window.intersoccerGetBookingTypeFromForm === 'function' ? window.intersoccerGetBookingTypeFromForm($form) : '';
                }
                if (!variationBookingType) {
                    var foundVariationId = variation && variation.variation_id ? String(variation.variation_id) : '';
                    if (foundVariationId && latePickupVariationSettings[foundVariationId] && latePickupVariationSettings[foundVariationId].booking_type) {
                        variationBookingType = String(latePickupVariationSettings[foundVariationId].booking_type);
                    }
                }

                debug('InterSoccer: Booking type from variation:', variationBookingType);

                // Render day checkboxes based on the variation's booking type
                renderDayCheckboxes(variationBookingType, $daySelection, $dayCheckboxes, $form);
                
                // Handle late pickup display
                // Note: If days are selected and price needs updating, late pickup will calculate
                // with the current base price (may be stale) but will recalculate when AJAX completes
                handleLatePickupDisplay(variation.variation_id);

                // Update price for single-day bookings
                // Note: Only on initial variation load when days are already selected
                // Checkbox changes are handled by the dedicated checkbox change handler
                var isSingleDayBooking = typeof window.intersoccerIsSingleDayBookingType === 'function' && window.intersoccerIsSingleDayBookingType(variationBookingType);
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
                    debug('InterSoccer: Course variation selected');
                    
                    // Check if variation already has price_html from server
                    if (variation.price_html || variation.display_price_html) {
                        var priceHtml = variation.price_html || variation.display_price_html;
                        debug('InterSoccer: Using price_html from variation data:', priceHtml);
                        
                        // Update price display - priceHtml now includes <span class="price"> wrapper
                        var $priceContainer = jQuery('.woocommerce-variation-price');
                        if ($priceContainer.length) {
                            // Replace entire content since priceHtml includes the .price wrapper
                            $priceContainer.html(priceHtml);
                            debug('InterSoccer: Updated price display from variation data');
                        }
                    } else {
                        // Fallback to AJAX if price_html not in variation data
                        debug('InterSoccer: No price_html in variation, calling AJAX');
                        updateCoursePrice($form, variation);
                    }
                }

                intersoccerPvDispatchUpdateButtonState();
            });

            // Handle variation selection
            $form.on('found_variation', function(event, variation) {
                debug('InterSoccer: Variation found:', variation);
                
                // Check if this is a single-day camp booking
                var attrsFv = variation.attributes || {};
                var bookingType = attrsFv['attribute_pa_booking-type'] || attrsFv['attribute_booking-type'] || attrsFv.attribute_pa_booking_type || attrsFv.attribute_booking_type || '';
                if (!bookingType) {
                    var attrKeys = Object.keys(attrsFv);
                    for (var ai = 0; ai < attrKeys.length; ai++) {
                        var ak = attrKeys[ai];
                        var akl = ak.toLowerCase();
                        if ((akl.indexOf('booking') !== -1 || akl.indexOf('buchung') !== -1) && attrsFv[ak]) {
                            bookingType = attrsFv[ak];
                            break;
                        }
                    }
                }
                if (!bookingType && typeof window.intersoccerGetBookingTypeFromForm === 'function') {
                    bookingType = window.intersoccerGetBookingTypeFromForm($form);
                }
                debug('InterSoccer: Booking type:', bookingType);
                
                if (typeof window.intersoccerIsSingleDayBookingType === 'function' && window.intersoccerIsSingleDayBookingType(bookingType)) {
                    debug('InterSoccer: Single-day camp detected, setting up price update');
                    
                    // Store the original per-day price (only if not already stored)
                    if (originalPricePerDay === null) {
                        originalPricePerDay = parseFloat(variation.display_regular_price || variation.price);
                        debug('InterSoccer: Stored original per-day price:', originalPricePerDay);
                    }
                    
                    // Get currently selected days
                    var selectedDays = [];
                    $form.find('.intersoccer-day-checkbox:checked').each(function() {
                        selectedDays.push(jQuery(this).val());
                    });
                    
                    if (selectedDays.length > 0) {
                        debug('InterSoccer: Days already selected (' + selectedDays.length + '), will update via checkbox handler');
                        // DO NOT modify variation object here - it causes the base price to compound
                        // The checkbox change handler will update the price via optimistic update + AJAX
                    }
                }
                
                // Debug late pickup
                debug('InterSoccer: Checking for late pickup container:', jQuery('.intersoccer-late-pickup').length > 0);
                if (jQuery('.intersoccer-late-pickup').length > 0) {
                    debug('InterSoccer: Late pickup container found');
                }
            });

            $form.on('reset_data', function() {
                debug('InterSoccer: WooCommerce reset variation data');
                lastVariation = null;
                lastVariationId = 0;

                // Hide day selection when variation is reset
                $daySelection.hide();
                $form.find('input[name="camp_days[]"]').remove();
                selectedDays = [];

                // Course info container removed from top of page (no longer needed)

                intersoccerPvDispatchUpdateButtonState();
            });

            // Monitor variation ID changes
            $form.find('input[name="variation_id"]').on('change', function() {
                var variationId = $(this).val();
                debug('InterSoccer: Variation ID changed to:', variationId);
                intersoccerPvDispatchUpdateButtonState();
            });

            // Force initial check after a delay to handle Elementor loading
            setTimeout(function() {
                debug('InterSoccer: Performing initial variation check');
                $form.trigger('check_variations');
                
                // Also try to get current booking type and render
                var currentBookingType = typeof window.intersoccerGetBookingTypeFromForm === 'function' ? window.intersoccerGetBookingTypeFromForm($form) : '';
                debug('InterSoccer: Initial booking type check:', currentBookingType);
                if (currentBookingType) {
                    renderDayCheckboxes(currentBookingType, $daySelection, $dayCheckboxes, $form);
                }
            }, 1000);
        });
    </script>
    <script>
        // Debug helper functions - defined in global scope so they're available to all functions
        // These are used by functions defined outside of jQuery(document).ready blocks
        var debug = <?php echo (defined('WP_DEBUG') && WP_DEBUG) ? 'function() { console.log.apply(console, arguments); }' : 'function() {}'; ?>;
        var debugWarn = <?php echo (defined('WP_DEBUG') && WP_DEBUG) ? 'function() { console.warn.apply(console, arguments); }' : 'function() {}'; ?>;
        var debugError = <?php echo (defined('WP_DEBUG') && WP_DEBUG) ? 'function() { console.error.apply(console, arguments); }' : 'function() {}'; ?>;
        
        // Day translations for WPML compatibility - retrieve from WPML database
        var intersoccerDayTranslations = <?php
        $translations = array();
        if (function_exists('icl_t')) {
            $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
            foreach ($days as $day) {
                $translated = icl_t('intersoccer-product-variations', $day, $day);
                intersoccer_debug('Elementor: WPML translation for ' . $day . ': ' . $translated);
                if ($translated !== $day) { // Only include if actually translated
                    $translations[$day] = $translated;
                }
            }
        } else {
            intersoccer_debug('Elementor: icl_t function not available');
        }
        intersoccer_debug('Elementor: Final translations array: ' . json_encode($translations));
        echo json_encode($translations);
        ?>;

        // Function to update course price display
        function updateCoursePrice($form, variation) {
            debug('InterSoccer: updateCoursePrice called for variation:', variation.variation_id);

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
                    debug('InterSoccer: Course price AJAX success:', response);
                    if (response.success && response.data.price !== undefined && response.data.price_html) {
                        var price = parseFloat(response.data.price);
                        var priceHtml = response.data.price_html;
                        debug('InterSoccer: Updating course price display to:', price, 'HTML:', priceHtml);

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
                            debug('InterSoccer: Updated course price display to:', priceHtml);
                        } else {
                            debugWarn('InterSoccer: Price container .woocommerce-variation-price not found, cannot update display');
                        }

                        // Update variation object so cart uses correct price
                        // Don't trigger woocommerce_variation_has_changed - it tries to call wc_price() which doesn't exist
                    } else {
                        debugError('InterSoccer: Course price response failed or missing price_html:', response.data);
                    }
                },
                error: function(xhr, status, error) {
                    debugError('InterSoccer: Course price AJAX error:', status, error, xhr.responseText);
                }
            });
        }

        // Function to update course information display - REMOVED
        // Course info now only displays in the variation table at the bottom
        // function updateCourseInfo($form, variationId) {
        //     debug('InterSoccer: updateCourseInfo called for variation:', variationId);
        //     if (!variationId || variationId === '0') {
        //         debug('InterSoccer: No valid variation ID for course info');
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
            debug('InterSoccer: updateCampPrice called with days:', selectedDays);
            
            var variationId = $form.find('input[name="variation_id"]').val();
            debug('InterSoccer: variationId found:', variationId);
            
            if (!variationId || variationId === '0') {
                debug('InterSoccer: No valid variation selected for price update');
                return null;
            }

            // Only make AJAX call if we have days selected or if this is a single-day booking
            var bookingType = typeof window.intersoccerGetBookingTypeFromForm === 'function' ? window.intersoccerGetBookingTypeFromForm($form) : ($form.find('select[name*="booking-type"]').val() || '');
            var isSingleDayBooking = typeof window.intersoccerIsSingleDayBookingType === 'function' && window.intersoccerIsSingleDayBookingType(bookingType);
            
            if (isSingleDayBooking && selectedDays.length === 0) {
                debug('InterSoccer: Single-day booking but no days selected, skipping AJAX');
                return null;
            }

            debug('InterSoccer: Updating camp price for variation', variationId, 'with days:', selectedDays);
            debug('InterSoccer: AJAX URL:', '<?php echo admin_url('admin-ajax.php'); ?>');
            debug('InterSoccer: Nonce:', '<?php echo wp_create_nonce('intersoccer_nonce'); ?>');

            // Set flag to indicate AJAX price update in progress
            // This prevents late pickup from updating display with stale base price
            var $variationPriceContainer = jQuery('.woocommerce-variation-price');
            $variationPriceContainer.data('intersoccer-updating', true);
            debug('InterSoccer: Set AJAX updating flag to prevent premature late pickup calculation');

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
                    debug('InterSoccer: Price response received for request #' + responseSequence);
                    
                    if (responseSequence < campPriceRequestSequence) {
                        debugWarn('InterSoccer: Ignoring stale price response #' + responseSequence + ' (current: #' + campPriceRequestSequence + ')');
                        return;
                    }
                    
                    debug('InterSoccer: Price update AJAX success:', response);
                    if (response.success) {
                        var priceHtml = response.data.price;  // Already formatted HTML from server
                        var rawPrice = response.data.raw_price;
                        debug('InterSoccer: Raw price from AJAX:', rawPrice);
                        
                        // Get price container (needed in both branches)
                        var $variationPriceContainer = jQuery('.woocommerce-variation-price');
                        
                        // Check if late pickup is active - if so, don't update display yet
                        // Let late pickup handler update display with final total (camp + late pickup)
                        // Check DOM for late pickup selection (not variable which may be out of scope)
                        var $latePickupOption = $form.find('input[name="late_pickup_option"]:checked');
                        var latePickupActive = $latePickupOption.length > 0 && $latePickupOption.val() !== 'none';
                        
                        if (latePickupActive) {
                            debug('InterSoccer: Late pickup active, skipping price display update (will update after late pickup calculation)');
                            // Don't update HTML yet - just trigger event with raw price
                            // Late pickup handler will update display with total
                            // Clear updating flag so late pickup can update
                            $variationPriceContainer.data('intersoccer-updating', false);
                            $form.trigger('intersoccer_price_updated', {rawPrice: rawPrice});
                        } else {
                            debug('InterSoccer: No late pickup, updating price display with:', priceHtml);
                            
                            // Update the variation price container with properly formatted HTML
                            if ($variationPriceContainer.length) {
                                $variationPriceContainer.html(priceHtml);
                                debug('InterSoccer: Updated .woocommerce-variation-price container');
                                
                                // Clear the updating flag now that server response is applied
                                $variationPriceContainer.data('intersoccer-updating', false);
                            } else {
                                debugWarn('InterSoccer: .woocommerce-variation-price not found, trying fallback');
                                // Fallback: update price span
                                var $priceElement = jQuery('.single_variation .price').first();
                                if ($priceElement.length) {
                                    $priceElement.replaceWith(priceHtml);
                                    debug('InterSoccer: Updated price via fallback');
                                }
                            }
                            
                            // Trigger custom event (even though no late pickup to keep flow consistent)
                            $form.trigger('intersoccer_price_updated', {rawPrice: rawPrice});
                        }
                    } else {
                        debugError('InterSoccer: Price update failed:', response.data);
                        // Clear updating flag on failure
                        var $variationPriceContainer = jQuery('.woocommerce-variation-price');
                        $variationPriceContainer.data('intersoccer-updating', false);
                    }
                },
                error: function(xhr, status, error) {
                    // Clear updating flag on error
                    var $variationPriceContainer = jQuery('.woocommerce-variation-price');
                    $variationPriceContainer.data('intersoccer-updating', false);
                    
                    // Ignore errors from aborted requests
                    if (status === 'abort') {
                        debug('InterSoccer: Price request aborted (expected for rapid clicking)');
                        return;
                    }
                    debugError('InterSoccer: Price update AJAX error:', status, error, xhr.responseText);
                }
            });
        }

        jQuery(document).ready(function($) {
            // Debug helper function - only logs when WP_DEBUG is enabled
            var debug = <?php echo (defined('WP_DEBUG') && WP_DEBUG) ? 'function() { console.log.apply(console, arguments); }' : 'function() {}'; ?>;
            var debugWarn = <?php echo (defined('WP_DEBUG') && WP_DEBUG) ? 'function() { console.warn.apply(console, arguments); }' : 'function() {}'; ?>;
            var debugError = <?php echo (defined('WP_DEBUG') && WP_DEBUG) ? 'function() { console.error.apply(console, arguments); }' : 'function() {}'; ?>;
            
            var $form = $('form.cart, form.variations_form, .woocommerce-product-details form, .single-product form');
            
            // Prevent WooCommerce from resetting price during our updates
            $form.on('show_variation', function(event, variation) {
                var $priceContainer = $('.woocommerce-variation-price');
                if ($priceContainer.data('intersoccer-updating')) {
                    debug('InterSoccer: Blocking WooCommerce price reset (update in progress)');
                    // Don't let WooCommerce change the price while we're updating
                    event.stopImmediatePropagation();
                    return false;
                }
            });
            
            // Handle day checkbox changes
            $form.on('change', '.intersoccer-day-checkbox', function() {
                debug('InterSoccer: Day checkbox changed');
                
                // Set flag to prevent found_variation handler from also calling updateCampPrice
                $form.data('intersoccer-checkbox-changed', true);
                setTimeout(function() {
                    $form.removeData('intersoccer-checkbox-changed');
                }, 100);
                
                var selectedDays = [];
                $form.find('.intersoccer-day-checkbox:checked').each(function() {
                    selectedDays.push(jQuery(this).val());
                });
                debug('InterSoccer: Selected days:', selectedDays);
                
                // Only update price if we have a valid variation selected
                var variationId = $form.find('input[name="variation_id"]').val();
                if (!variationId || variationId === '0') {
                    debug('InterSoccer: No valid variation selected, skipping price update');
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
                        debug('InterSoccer: Stored original per-day price:', originalPricePerDay);
                    }
                    
                    var estimatedPrice = selectedDays.length > 0 ? basePricePerDay * selectedDays.length : basePricePerDay;
                    
                    // Build price HTML to match WooCommerce structure
                    var optimisticPriceHtml = '<span class="price"><span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">CHF</span>' + estimatedPrice.toFixed(2) + '</bdi></span></span>';
                    
                    // Update DOM directly (do NOT modify variation object to prevent WooCommerce interference)
                    var $variationPriceContainer = jQuery('.woocommerce-variation-price');
                    if ($variationPriceContainer.length) {
                        $variationPriceContainer.html(optimisticPriceHtml);
                        debug('InterSoccer: Optimistic price update to CHF', estimatedPrice.toFixed(2), 'for', selectedDays.length, 'days');
                        
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
                    debug('InterSoccer: Aborted previous price request (rapid clicking detected)');
                }
                
                // Increment sequence number for this request
                var currentSequence = ++campPriceRequestSequence;
                debug('InterSoccer: Starting price request #' + currentSequence);
                
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
    if (!defined('INTERSOCCER_PV_EXTRAS_EMITTED')) {
        define('INTERSOCCER_PV_EXTRAS_EMITTED', true);
    }
};

add_action('woocommerce_before_single_product', $intersoccer_elementor_product_page_cb, 5);
add_action('woocommerce_single_product_summary', $intersoccer_elementor_product_page_cb, 0);
add_action('woocommerce_before_add_to_cart_form', $intersoccer_elementor_product_page_cb, 0);
add_action('woocommerce_after_add_to_cart_form', $intersoccer_elementor_product_page_cb, 0);
add_action('woocommerce_before_add_to_cart_button', $intersoccer_elementor_product_page_cb, 0);

add_action(
    'wp_footer',
    static function () use ($intersoccer_elementor_product_page_cb) {
        $on_product_context = (function_exists('is_product') && is_product())
            || (function_exists('is_singular') && is_singular('product'));
        if (!$on_product_context) {
            return;
        }
        if (defined('INTERSOCCER_PV_EXTRAS_EMITTED') && INTERSOCCER_PV_EXTRAS_EMITTED) {
            return;
        }
        global $product;
        if (!is_a($product, 'WC_Product') && function_exists('get_queried_object_id') && function_exists('wc_get_product')) {
            $qpid = (int) get_queried_object_id();
            if ($qpid > 0) {
                $maybe = wc_get_product($qpid);
                if (is_a($maybe, 'WC_Product')) {
                    $product = $maybe;
                }
            }
        }
        if (!is_a($product, 'WC_Product') || !$product->is_type('variable')) {
            return;
        }
        call_user_func($intersoccer_elementor_product_page_cb);
    },
    999
);
