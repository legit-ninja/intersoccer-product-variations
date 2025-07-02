<?php
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
                $form.on('submit', function(e) {
                    var playerId = $form.find('.player-select').val();
                    if (!playerId && intersoccerCheckout.user_id && intersoccerCheckout.user_id !== '0') {
                        e.preventDefault();
                        $form.find('.intersoccer-player-selection .error-message').text('Please Select an Attendee.').show();
                        setTimeout(() => $form.find('.intersoccer-player-selection .error-message').hide(), 5000);
                        console.log('InterSoccer: Form submission blocked - no player selected');
                    }
                    var bookingType = $form.find('select[name="attribute_pa_booking-type"]').val() || $form.find('input[name="attribute_pa_booking-type"]').val();
                    if (bookingType === 'single-days') {
                        var selectedDays = $form.find('input[name="camp_days[]"]').map(function() { return $(this).val(); }).get();
                        if (selectedDays.length === 0) {
                            e.preventDefault();
                            $form.find('.intersoccer-day-selection .error-message').text('Please select at least one day.').show();
                            setTimeout(() => $form.find('.intersoccer-day-selection .error-message').hide(), 5000);
                            console.log('InterSoccer: Form submission blocked - no days selected');
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
                }).trigger('change'); // Trigger on page load to set initial state

                // Handle variation change to update price
                $form.on('found_variation', function(event, variation) {
                    console.log('InterSoccer: found_variation event triggered, variation:', variation);
                    var selectedDays = $form.find('input[name="camp_days[]"]').map(function() { return $(this).val(); }).get();
                    var variationId = variation ? variation.variation_id : 0;
                    var productId = <?php echo json_encode($product_id); ?>;
                    console.log('InterSoccer: Triggering price update with variation_id:', variationId, 'selected days:', selectedDays);
                    updatePrice(selectedDays, variationId);
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
            } else {
                console.error('InterSoccer: Product form not found for validation and price update');
            }
        });
    </script>
<?php
});
?>