/**
 * InterSoccer Late Pickup
 * Handles late pickup functionality for camp products
 */
(function($) {
    'use strict';

    // Check if late pickup data is available
    if (typeof intersoccerLatePickup === 'undefined') {
        console.warn('InterSoccer Late Pickup: Configuration not found');
        return;
    }

    var config = intersoccerLatePickup;
    var debugEnabled = config.debug || false;

    $(document).ready(function() {
        var $latePickupContainer = $('.intersoccer-late-pickup');
        var $latePickupDays = $('.intersoccer-late-pickup-days');
        var $latePickupPrice = $('.intersoccer-late-pickup-price');
        var $latePickupCostInput = $('#late_pickup_cost_input');
        var $form = $('form.cart, form.variations_form, .single-product form.cart');

        // Always log initialization attempt
        console.log('InterSoccer Late Pickup: Initialization attempt');
        console.log('InterSoccer Late Pickup: Container found:', $latePickupContainer.length > 0);
        console.log('InterSoccer Late Pickup: Form found:', $form.length > 0);
        console.log('InterSoccer Late Pickup: Config:', config);

        if (!$latePickupContainer.length) {
            console.warn('InterSoccer Late Pickup: Container not found on page - HTML may not be rendered');
            return;
        }

        if (debugEnabled) {
            console.log('InterSoccer Late Pickup: Debug mode enabled, initializing', config);
        }

        /**
         * Update late pickup visibility based on selected variation
         */
        function updateLatePickupVisibility() {
            var variationId = $form.find('input[name="variation_id"]').val();
            
            // Always log for debugging
            console.log('InterSoccer Late Pickup: updateLatePickupVisibility called');
            console.log('InterSoccer Late Pickup: Variation ID from form:', variationId);
            console.log('InterSoccer Late Pickup: Config variationSettings:', config.variationSettings);
            
            if (!variationId || !config.variationSettings) {
                console.log('InterSoccer Late Pickup: Hiding - no variation ID or settings');
                $latePickupContainer.hide();
                resetLatePickup();
                return;
            }

            variationId = parseInt(variationId);
            var isEnabled = config.variationSettings[variationId] === true;

            console.log('InterSoccer Late Pickup: Variation', variationId, 'enabled:', isEnabled);

            if (isEnabled) {
                console.log('InterSoccer Late Pickup: Showing container');
                $latePickupContainer.show();
            } else {
                console.log('InterSoccer Late Pickup: Hiding container - not enabled for this variation');
                $latePickupContainer.hide();
                resetLatePickup();
            }
        }

        /**
         * Reset late pickup selections
         */
        function resetLatePickup() {
            $latePickupDays.hide();
            $latePickupDays.find('.late-pickup-day-checkbox').prop('checked', false);
            $form.find('input[name="late_pickup_type"][value="single-days"]').prop('checked', true);
            updateLatePickupCost();
        }

        /**
         * Update late pickup cost based on selections
         */
        function updateLatePickupCost() {
            var pickupType = $form.find('input[name="late_pickup_type"]:checked').val();
            var cost = 0;

            if (pickupType === 'full-week') {
                cost = parseFloat(config.fullWeekCost) || 0;
            } else if (pickupType === 'single-days') {
                var selectedDays = $latePickupDays.find('.late-pickup-day-checkbox:checked').length;
                cost = selectedDays * (parseFloat(config.perDayCost) || 0);
            }

            // Update display
            if ($latePickupPrice.length) {
                // Use WooCommerce price formatting if available
                if (typeof wc_price !== 'undefined') {
                    $latePickupPrice.html(wc_price(cost));
                } else {
                    // Fallback formatting
                    var formattedPrice = new Intl.NumberFormat('de-CH', {
                        style: 'currency',
                        currency: 'CHF',
                        minimumFractionDigits: 2
                    }).format(cost);
                    $latePickupPrice.text(formattedPrice);
                }
            }

            // Update hidden input for form submission
            if ($latePickupCostInput.length) {
                $latePickupCostInput.val(cost.toFixed(2));
            }

            // Update base price in hidden field for cart calculation
            var $basePriceInput = $('#base_price_input');
            if (!$basePriceInput.length) {
                $form.append('<input type="hidden" name="base_price" id="base_price_input" value="0">');
                $basePriceInput = $('#base_price_input');
            }

            var variationPrice = $form.find('.woocommerce-variation-price .price .amount').text();
            if (variationPrice) {
                // Extract numeric value from price string
                var priceMatch = variationPrice.replace(/[^\d.,]/g, '').replace(',', '.');
                var basePrice = parseFloat(priceMatch) || 0;
                $basePriceInput.val(basePrice.toFixed(2));
            }

            if (debugEnabled) {
                console.log('InterSoccer Late Pickup: Cost updated to', cost, 'for type', pickupType);
            }
        }

        /**
         * Update day checkboxes visibility based on pickup type
         */
        function updateDayCheckboxesVisibility() {
            var pickupType = $form.find('input[name="late_pickup_type"]:checked').val();
            
            if (pickupType === 'single-days') {
                $latePickupDays.show();
            } else {
                $latePickupDays.hide();
                $latePickupDays.find('.late-pickup-day-checkbox').prop('checked', false);
            }
            
            updateLatePickupCost();
        }

        // Listen for variation changes
        $form.on('found_variation', function(event, variation) {
            if (debugEnabled) {
                console.log('InterSoccer Late Pickup: Variation found', variation);
            }
            updateLatePickupVisibility();
        });

        // Listen for variation reset
        $form.on('reset_data', function() {
            if (debugEnabled) {
                console.log('InterSoccer Late Pickup: Variation reset');
            }
            updateLatePickupVisibility();
        });

        // Listen for variation change (WooCommerce event)
        $form.on('woocommerce_variation_has_changed', function() {
            if (debugEnabled) {
                console.log('InterSoccer Late Pickup: Variation changed');
            }
            updateLatePickupVisibility();
        });

        // Listen for late pickup type change
        $form.on('change', 'input[name="late_pickup_type"]', function() {
            if (debugEnabled) {
                console.log('InterSoccer Late Pickup: Type changed to', $(this).val());
            }
            updateDayCheckboxesVisibility();
        });

        // Listen for day checkbox changes
        $form.on('change', '.late-pickup-day-checkbox', function() {
            if (debugEnabled) {
                console.log('InterSoccer Late Pickup: Day checkbox changed');
            }
            updateLatePickupCost();
        });

        // Also listen for variation_id changes directly (fallback)
        $form.on('change', 'input[name="variation_id"], select.variation_id', function() {
            setTimeout(function() {
                updateLatePickupVisibility();
            }, 100);
        });

        // Initial setup
        setTimeout(function() {
            updateLatePickupVisibility();
            updateLatePickupCost();
        }, 500);
    });
})(jQuery);

