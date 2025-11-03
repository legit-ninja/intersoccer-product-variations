/**
 * File: variation-details.js
 * Description: Manages dynamic price updates and attendee selection for InterSoccer WooCommerce product pages.
 * Dependencies: jQuery
 * Author: Jeremy Lee
 * Changes:
 * - Initial implementation with day selection, price updates, and player selection (2025-06-09).
 * - Enhanced with MutationObserver, Elementor support, and improved logging (2025-05-26).
 * - Fixed price calculations, removed client-side price storage, and improved security (2025-05-27).
 * - Added dynamic price updates via AJAX, fixed button enabling, and resolved infinite loops (2025-05-27).
 * - Removed day selection and quantity logic for camps to avoid conflicts with elementor-widgets.php (2025-07-02).
 * - Simplified code and added logging to resolve syntax error at line 461 (2025-07-02).
 * - Fixed ReferenceError: initializePlugin is not defined by moving function to global scope (2025-07-02).
 * - Fixed duplicate attendee error by aligning field names and removing redundant event listeners (2025-08-07).
 */

(function ($) {
    // Initialize plugin
    function initializePlugin() {
        // Check if intersoccerCheckout is initialized
        if (!intersoccerCheckout || !intersoccerCheckout.ajax_url) {
            console.error("InterSoccer: intersoccerCheckout not initialized.");
            return;
        }

        // Refresh nonce if needed
        function refreshNonce() {
            return new Promise((resolve, reject) => {
                $.ajax({
                    url: intersoccerCheckout.nonce_refresh_url,
                    type: "POST",
                    data: { action: "intersoccer_refresh_nonce" },
                    success: function (response) {
                        if (response.success && response.data.nonce) {
                            intersoccerCheckout.nonce = response.data.nonce;
                            console.log("InterSoccer: Nonce refreshed:", response.data.nonce);
                            resolve();
                        } else {
                            console.error("InterSoccer: Nonce refresh failed:", response);
                            reject(new Error("Nonce refresh failed"));
                        }
                    },
                    error: function (xhr) {
                        console.error("InterSoccer: Nonce refresh error:", xhr.status, xhr.responseText);
                        reject(new Error("Nonce refresh error: " + xhr.statusText));
                    }
                });
            });
        }

        // Fetch course metadata
        function fetchCourseMetadata(productId, variationId) {
            return new Promise((resolve, reject) => {
                console.log("InterSoccer: Fetching course metadata for product:", productId, "variation:", variationId);
                $.ajax({
                    url: intersoccerCheckout.ajax_url,
                    type: "POST",
                    data: {
                        action: "intersoccer_get_course_metadata",
                        nonce: intersoccerCheckout.nonce,
                        product_id: productId,
                        variation_id: variationId
                    },
                    success: function (response) {
                        if (response.success && response.data) {
                            console.log("InterSoccer: Course metadata fetched:", response.data);
                            resolve({
                                start_date: response.data.start_date || "",
                                total_weeks: parseInt(response.data.total_weeks, 10) || 0,
                                weekly_discount: parseFloat(response.data.weekly_discount) || 0,
                                remaining_weeks: parseInt(response.data.remaining_weeks, 10) || 0
                            });
                        } else {
                            console.error("InterSoccer: No metadata in response:", response.data ? response.data.message : "Unknown error");
                            resolve({
                                start_date: "",
                                total_weeks: 0,
                                weekly_discount: 0,
                                remaining_weeks: 0
                            });
                        }
                    },
                    error: function (xhr) {
                        console.error("InterSoccer: AJAX error fetching metadata:", xhr.status, xhr.responseText);
                        if (xhr.status === 403) {
                            refreshNonce()
                                .then(() => fetchCourseMetadata(productId, variationId))
                                .then(resolve)
                                .catch(reject);
                        } else {
                            resolve({
                                start_date: "",
                                total_weeks: 0,
                                weekly_discount: 0,
                                remaining_weeks: 0
                            });
                        }
                    }
                });
            });
        }

        // Update product price
        function updateProductPrice(productId, variationId, remainingWeeks = null) {
            return new Promise((resolve, reject) => {
                const now = Date.now();
                if (now - lastPriceUpdateTime < 1000) {
                    console.log("InterSoccer: Price update throttled - too soon since last update");
                    resolve();
                    return;
                }
                lastPriceUpdateTime = now;

                console.log("InterSoccer: Updating price for product:", productId, "variation:", variationId, "weeks:", remainingWeeks);
                $.ajax({
                    url: intersoccerCheckout.ajax_url,
                    type: "POST",
                    data: {
                        action: "intersoccer_calculate_dynamic_price",
                        nonce: intersoccerCheckout.nonce,
                        product_id: productId,
                        variation_id: variationId,
                        remaining_weeks: remainingWeeks
                    },
                    success: function (response) {
                        if (response.success && response.data.price) {
                            console.log("InterSoccer: Price updated:", response.data.price);

                            // Try multiple selectors for the price element
                            const $priceSelectors = [
                                ".woocommerce-variation-price .price",
                                "form.cart .woocommerce-variation-price .price",
                                ".single_variation_wrap .woocommerce-variation-price .price",
                                ".single_variation .price",
                                ".woocommerce-variation-price",
                                ".price",
                                "[data-product_id] .price",
                                ".product .price"
                            ];

                            let priceUpdated = false;
                            for (const selector of $priceSelectors) {
                                const $priceElement = $(selector).first();
                                if ($priceElement.length) {
                                    console.log("InterSoccer: Found price element with selector:", selector, "HTML:", $priceElement.html().substring(0, 100));
                                    $priceElement.html(response.data.price);
                                    console.log("InterSoccer: Updated price element with selector:", selector, "to:", response.data.price);
                                    priceUpdated = true;
                                    break;
                                }
                            }

                            if (!priceUpdated) {
                                console.warn("InterSoccer: No price element found to update. Available price elements:");
                                $('[class*="price"]').each(function() {
                                    console.warn("Found price element:", $(this).attr('class'), "HTML:", $(this).html().substring(0, 50));
                                });
                            }

                            // Also try to update any subtotal elements
                            const $subtotalElement = $(".price-subtotal, .woocommerce-variation-price .price-subtotal");
                            if ($subtotalElement.length) {
                                $subtotalElement.html(response.data.price);
                            }
                            resolve(response.data.raw_price);
                        } else {
                            console.error("InterSoccer: Failed to update price:", response.data ? response.data.message : "Unknown error");
                            reject(new Error(response.data.message || "Failed to update price"));
                        }
                    },
                    error: function (xhr) {
                        console.error("InterSoccer: AJAX error updating price:", xhr.status, xhr.responseText);
                        if (xhr.status === 403) {
                            refreshNonce()
                                .then(() => updateProductPrice(productId, variationId, remainingWeeks))
                                .then(resolve)
                                .catch(reject);
                        } else {
                            reject(new Error("Failed to update price: " + xhr.statusText));
                        }
                    }
                });
            });
        }

        // Function to update course information display - DISABLED
        // Course info now only displays in the variation table, not at the top of the page
        function updateCourseInfo(productId, variationId) {
            console.log('InterSoccer: updateCourseInfo disabled - course info displays in variation table only');
            // Function disabled - course info container removed from page
            // Course information is now displayed in the variation details table
            return;
        }

        // Form observer setup
        let $form = $("form.cart");
        let formObserver;

        function setupFormObserver() {
            let retryCount = 0;
            const maxRetries = 10;
            const retryInterval = 1000;

            formObserver = new MutationObserver((mutations) => {
                mutations.forEach((mutation) => {
                    if (mutation.addedNodes.length) {
                        $form = $("form.cart");
                        if ($form.length) {
                            console.log("InterSoccer: Form found, setting up handlers");
                            setupFormHandlers();
                            formObserver.disconnect();
                        }
                    }
                });
            });

            $form = $("form.cart");
            if ($form.length) {
                console.log("InterSoccer: Form found on initial load");
                setupFormHandlers();
                formObserver.disconnect();
            } else {
                console.log("InterSoccer: Form not found, starting observer");
                formObserver.observe(document.body, { childList: true, subtree: true });

                const retryTimer = setInterval(() => {
                    retryCount++;
                    $form = $("form.cart");
                    if ($form.length) {
                        console.log("InterSoccer: Form found after retries");
                        setupFormHandlers();
                        formObserver.disconnect();
                        clearInterval(retryTimer);
                    } else if (retryCount >= maxRetries) {
                        console.error("InterSoccer: Product form not found after", maxRetries, "retries");
                        formObserver.disconnect();
                        clearInterval(retryTimer);
                    }
                }, retryInterval);
            }
        }

        // Run observer only once
        setupFormObserver();

        function setupFormHandlers() {
            const productId = $form.data("product_id") || $form.find('input[name="product_id"]').val() || "unknown";
            let productType = "unknown";
            let currentVariation = null;
            let lastVariationId = null;
            let lastBookingType = null;
            let lastPriceUpdateTime = 0;
            let lastValidPlayerId = "";
            let isProcessing = false;

            function fetchProductType() {
                $.ajax({
                    url: intersoccerCheckout.ajax_url,
                    type: "POST",
                    data: {
                        action: "intersoccer_get_product_type",
                        nonce: intersoccerCheckout.nonce,
                        product_id: productId
                    },
                    success: function (response) {
                        if (response.success && response.data.product_type) {
                            productType = response.data.product_type;
                            console.log("InterSoccer: Product type fetched:", productType);

                            // Check for already-selected variation after product type is known
                            checkForPreSelectedVariation();
                        }
                    },
                    error: function (xhr) {
                        console.error("InterSoccer: Failed to fetch product type:", xhr.status, xhr.responseText);
                        if (xhr.status === 403) {
                            refreshNonce().then(fetchProductType);
                        }
                    }
                });
            }

            function checkForPreSelectedVariation() {
                // Check URL parameters for pre-selected attributes
                const urlParams = new URLSearchParams(window.location.search);
                let hasPreSelectedAttributes = false;
                let preSelectedAttributes = {};

                // Get all variation attributes from the form
                const $variationSelects = $form.find('select[name^="attribute_"]');
                $variationSelects.each(function() {
                    const $select = $(this);
                    const attrName = $select.attr('name');
                    const paramName = attrName.replace('attribute_', '');
                    const paramValue = urlParams.get(attrName);

                    if (paramValue) {
                        hasPreSelectedAttributes = true;
                        preSelectedAttributes[paramName] = paramValue;
                        // Set the select value
                        $select.val(paramValue);
                    }
                });

                if (hasPreSelectedAttributes) {
                    console.log("InterSoccer: Found pre-selected attributes from URL:", preSelectedAttributes);

                    // Trigger WooCommerce's variation checking
                    $form.trigger('check_variations');

                    // For course products, try to display course info and update price immediately
                    if (productType === 'course') {
                        // Wait a bit for WooCommerce to process, then check for variation ID
                        setTimeout(() => {
                            const variationId = $form.find('input[name="variation_id"]').val();
                            if (variationId && variationId !== '0') {
                                console.log("InterSoccer: Found variation ID from pre-selected attributes, displaying course info:", variationId);
                                updateCourseInfo(productId, parseInt(variationId));

                                // Note: Price for pre-selected variations is handled by PHP filter
                            } else {
                                console.log("InterSoccer: No variation ID found for pre-selected attributes");
                            }
                        }, 500);
                    }
                }
            }

            fetchProductType();

            function updateFormData(playerId, remainingWeeks = null, variationId = null) {
                $form.find('input[name="assigned_attendee"]').remove();
                $form.find('input[name="remaining_weeks"]').remove();

                if (playerId) {
                    $form.append(`<input type="hidden" name="assigned_attendee" value="${playerId}">`);
                    console.log("InterSoccer: Updated form with assigned_attendee:", playerId);
                }
                if (remainingWeeks !== null) {
                    $form.append(`<input type="hidden" name="remaining_weeks" value="${remainingWeeks}">`);
                }
            }

            function updateAddToCartButtonState(playerId, bookingType) {
                const $addToCartButton = $form.find("button.single_add_to_cart_button");
                const hasPlayer = playerId && playerId !== "";
                const isValid = hasPlayer;
                if (hasPlayer) {
                    lastValidPlayerId = playerId;
                }
                $addToCartButton.prop("disabled", !isValid);
                if (isValid) {
                    $addToCartButton.removeClass('disabled');
                    console.log("InterSoccer: Add to cart button enabled for player:", playerId);
                } else {
                    console.log("InterSoccer: Add to cart button disabled, no player selected");
                }
            }

            function handleVariation(variation, retryCount = 0, maxRetries = 12) {
                console.log("InterSoccer: handleVariation called with productType:", productType, "variation:", variation);
                if (isProcessing) {
                    console.log("InterSoccer: Skipping handleVariation, processing in progress");
                    return;
                }

                const bookingType =
                    variation?.attributes?.attribute_pa_booking_type ||
                    $form.find('select[name="attribute_pa_booking-type"]').val() ||
                    $form.find('input[name="attribute_pa_booking-type"]').val() || "";
                const variationId = variation?.variation_id || 0;

                const bookingTypeChanged = lastBookingType !== bookingType;
                if (variationId === lastVariationId && !bookingTypeChanged && retryCount === 0) {
                    console.log("InterSoccer: No change in variation or booking type, skipping");
                    return;
                }

                isProcessing = true;
                console.log("InterSoccer: Handling variation:", variationId, "bookingType:", bookingType);

                setTimeout(() => {
                    if (!variationId) {
                        console.error("InterSoccer: Invalid variation ID:", variationId);
                        isProcessing = false;
                        return;
                    }

                    currentVariation = variation;
                    lastVariationId = variationId;
                    lastBookingType = bookingType;

                    if (productType === "course") {
                        fetchCourseMetadata(productId, variationId).then((metadata) => {
                            const remainingWeeks = metadata.remaining_weeks || null;
                            const playerId = $form.find(".player-select").val() || "";
                            updateFormData(playerId, remainingWeeks, variationId);
                            updateAddToCartButtonState(playerId, bookingType);

                            // Display course information
                            updateCourseInfo(productId, variationId);

                            // Note: Price is now handled by PHP filter woocommerce_variation_price_html
                            console.log("InterSoccer: Course price handled by PHP filter");
                        }).catch((error) => {
                            console.error("InterSoccer: Failed to fetch course metadata:", error);
                            const playerId = $form.find(".player-select").val() || "";
                            updateFormData(playerId, null, variationId);
                            updateAddToCartButtonState(playerId, bookingType);
                        });
                    } else if (productType === "camp" && bookingType.toLowerCase() === "full-week") {
                        const playerId = $form.find(".player-select").val() || "";
                        updateFormData(playerId, null, variationId);
                        $form.find('input[name="quantity"]').val(1);
                        updateAddToCartButtonState(playerId, bookingType);

                        updateProductPrice(productId, variationId)
                            .then(() => {
                                console.log("InterSoccer: Price updated for Full Week Camp");
                            })
                            .catch((error) => {
                                console.error("InterSoccer: Failed to update price for Full Week Camp:", error);
                            });
                    } else {
                        // Hide course info for non-course products
                        $('#intersoccer-course-info').hide();

                        // Camps with single-days handled by elementor-widgets.php
                        const playerId = $form.find(".player-select").val() || "";
                        updateFormData(playerId, null, variationId);
                        $form.find('input[name="quantity"]').val(1);
                        updateAddToCartButtonState(playerId, bookingType);
                    }

                    isProcessing = false;
                }, 100);
            }

            let debounceTimeout;
            let isVariationEventProcessed = false;
            $form.on("found_variation", function (event, variation) {
                if (isVariationEventProcessed) return;
                isVariationEventProcessed = true;
                clearTimeout(debounceTimeout);
                debounceTimeout = setTimeout(() => {
                    console.log("InterSoccer: Found variation event triggered for product type:", productType);
                    handleVariation(variation);
                    isVariationEventProcessed = false;
                }, 200);
            });

            $form.on("woocommerce_variation_has_changed reset_data", function () {
                if (isVariationEventProcessed) return;
                isVariationEventProcessed = true;
                console.log("InterSoccer: Variation changed or reset");
                currentVariation = null;
                lastVariationId = null;
                lastBookingType = null;
                // Hide course info when variation is reset
                $('#intersoccer-course-info').hide();
                setTimeout(() => {
                    isVariationEventProcessed = false;
                }, 600);
            });

            $form.find('select[name="attribute_pa_booking-type"], input[name="attribute_pa_booking-type"]').on("change", function () {
                console.log("InterSoccer: Booking type changed");
                $form.trigger("check_variations");
            });

            let playerChangeTimeout;
            $form.find(".player-select").on("change", function () {
                const playerId = $(this).val() || "";
                const bookingType = $form.find('select[name="attribute_pa_booking-type"]').val() || $form.find('input[name="attribute_pa_booking-type"]').val();
                console.log("InterSoccer: Player selected:", playerId);

                // For courses, remaining weeks should be calculated server-side, not from form
                const remainingWeeks = (productType === "course") ? null : ($form.find('input[name="remaining_weeks"]').val() || null);
                updateFormData(playerId, remainingWeeks, currentVariation?.variation_id);

                clearTimeout(playerChangeTimeout);
                playerChangeTimeout = setTimeout(() => {
                    if (!currentVariation) {
                        console.log("InterSoccer: No current variation, triggering check_variations");
                        $form.trigger("check_variations");
                    }

                    updateAddToCartButtonState(playerId, bookingType);

                    if (currentVariation) {
                        const variationId = currentVariation.variation_id;
                        updateProductPrice(productId, variationId, remainingWeeks)
                            .then(() => {
                                console.log("InterSoccer: Price updated after player selection");
                            })
                            .catch((error) => {
                                console.error("InterSoccer: Failed to update price after player selection:", error);
                            });
                    }

                    // Update session with attendee
                    $.ajax({
                        url: intersoccerCheckout.ajax_url,
                        type: "POST",
                        data: {
                            action: "intersoccer_update_session_data",
                            nonce: intersoccerCheckout.nonce,
                            product_id: productId,
                            assigned_attendee: playerId,
                            camp_days: [],
                            remaining_weeks: remainingWeeks
                        },
                        success: function (response) {
                            console.log("InterSoccer: Session updated with attendee:", playerId);
                        },
                        error: function (xhr) {
                            console.error("InterSoccer: Failed to update session:", xhr.status, xhr.responseText);
                        }
                    });
                }, 600);
            });

            $(document).on("click", "button.single_add_to_cart_button, .elementor-add-to-cart button", function (e) {
                const $button = $(this);
                console.log("InterSoccer: Add to cart button clicked");
                if ($button.hasClass("buy-now")) {
                    const playerId = $form.find(".player-select").val() || lastValidPlayerId;
                    const remainingWeeks = $form.find('input[name="remaining_weeks"]').val();
                    updateFormData(playerId, remainingWeeks, currentVariation?.variation_id);
                    $form.append('<input type="hidden" name="buy_now" value="1">');
                }
            });

            $form.on("submit", function (e) {
                const playerId = $form.find(".player-select").val() || lastValidPlayerId;
                const remainingWeeks = $form.find('input[name="remaining_weeks"]').val();
                console.log("InterSoccer: Form submit - assigned_attendee:", playerId);
                updateFormData(playerId, remainingWeeks, currentVariation?.variation_id);
            });

            $form.on("adding_to_cart", function (event, $button, data) {
                const playerId = $form.find(".player-select").val() || lastValidPlayerId;
                const remainingWeeks = $form.find('input[name="remaining_weeks"]').val();
                console.log("InterSoccer: Adding to cart - data:", { playerId, remainingWeeks });
                if (playerId) data.assigned_attendee = playerId;
                if (remainingWeeks !== undefined && remainingWeeks !== null) data.remaining_weeks = remainingWeeks;
                data.quantity = 1; // Enforce quantity of 1
            });

            $form.trigger("check_variations");
        }
    }

    // Initialize only once
    $(document).ready(function () {
        console.log("InterSoccer: Document ready, initializing plugin");
        initializePlugin();
    });
})(jQuery);