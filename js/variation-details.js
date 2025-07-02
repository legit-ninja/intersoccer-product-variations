/**
 * File: variation-details.js
 * Description: Manages dynamic price updates for courses and full-week camps on WooCommerce product pages.
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
 */

(function ($) {
    // Define initializePlugin globally
    function initializePlugin() {
        console.log("InterSoccer: Initializing variation-details.js");

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
                            console.log("InterSoccer: Nonce refreshed:", intersoccerCheckout.nonce);
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
                console.log("InterSoccer: Fetching course metadata for product ID:", productId, "variation ID:", variationId);
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
                console.log("InterSoccer: Updating price for product ID:", productId, "variation ID:", variationId, "remainingWeeks:", remainingWeeks);
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
                            console.log("InterSoccer: Updated product price:", response.data.price);
                            const $priceElement = $("form.cart .woocommerce-variation-price .price");
                            if ($priceElement.length) {
                                $priceElement.html(response.data.price);
                            } else {
                                console.warn("InterSoccer: Price element not found");
                            }
                            const $subtotalElement = $("form.cart .single_variation_wrap .price-subtotal");
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
                            console.log("InterSoccer: Form detected via MutationObserver");
                            setupFormHandlers();
                            formObserver.disconnect();
                        }
                    }
                });
            });

            $form = $("form.cart");
            if ($form.length) {
                console.log("InterSoccer: Form found on initial check");
                setupFormHandlers();
                formObserver.disconnect();
            } else {
                console.log("InterSoccer: Product form not found, starting MutationObserver");
                formObserver.observe(document.body, { childList: true, subtree: true });

                const retryTimer = setInterval(() => {
                    retryCount++;
                    $form = $("form.cart");
                    if ($form.length) {
                        console.log("InterSoccer: Form found after retry", retryCount);
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

        if (typeof elementorFrontend !== 'undefined') {
            elementorFrontend.on('init', function() {
                console.log("InterSoccer: Elementor frontend initialized, re-checking for form");
                setupFormObserver();
            });
        }

        setupFormObserver();

        function setupFormHandlers() {
            const productId = $form.data("product_id") || $form.find('input[name="product_id"]').val() || "unknown";
            let productType = "unknown";
            let currentVariation = null;
            let lastVariationId = null;
            let lastBookingType = null;
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
                            console.log("InterSoccer: Product type:", productType);
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
            fetchProductType();

            function updateFormData(playerId, remainingWeeks = null, variationId = null) {
                $form.find('input[name="player_assignment"]').remove();
                $form.find('input[name="remaining_weeks"]').remove();

                if (playerId) {
                    $form.append(`<input type="hidden" name="player_assignment" value="${playerId}">`);
                }
                if (remainingWeeks !== null) {
                    $form.append(`<input type="hidden" name="remaining_weeks" value="${remainingWeeks}">`);
                }
                console.log(
                    "InterSoccer: Updated form data - Player:",
                    playerId,
                    "Remaining weeks:",
                    remainingWeeks,
                    "Variation ID:",
                    variationId
                );
            }

            function updateAddToCartButtonState(playerId, bookingType) {
                const $addToCartButton = $form.find("button.single_add_to_cart_button");
                const hasPlayer = playerId && playerId !== "";
                const isValid = hasPlayer;
                if (hasPlayer) {
                    lastValidPlayerId = playerId;
                }
                $addToCartButton.prop("disabled", !isValid);
                console.log(
                    "InterSoccer: Updated button state - Player:",
                    playerId,
                    "Enabled:",
                    isValid
                );
                if (isValid) {
                    $addToCartButton.removeClass('disabled');
                }
            }

            function handleVariation(variation, retryCount = 0, maxRetries = 12) {
                if (isProcessing) {
                    console.log("InterSoccer: Skipping variation handling due to ongoing processing");
                    return;
                }

                const bookingType =
                    variation?.attributes?.attribute_pa_booking_type ||
                    $form.find('select[name="attribute_pa_booking-type"]').val() ||
                    $form.find('input[name="attribute_pa_booking-type"]').val() || "";
                const variationId = variation?.variation_id || 0;

                const bookingTypeChanged = lastBookingType !== bookingType;
                if (variationId === lastVariationId && !bookingTypeChanged && retryCount === 0) {
                    console.log("InterSoccer: Skipping duplicate variation handling for ID:", variationId);
                    return;
                }

                isProcessing = true;

                console.log("InterSoccer: handleVariation triggered for variation ID:", variationId, "Retry:", retryCount, "Booking Type Changed:", bookingTypeChanged);

                setTimeout(() => {
                    if (!variationId) {
                        console.error("InterSoccer: Invalid variation ID:", variationId);
                        isProcessing = false;
                        return;
                    }
                    console.log("InterSoccer: Booking type:", bookingType);

                    currentVariation = variation;
                    lastVariationId = variationId;
                    lastBookingType = bookingType;

                    if (productType === "course") {
                        fetchCourseMetadata(productId, variationId).then((metadata) => {
                            console.log("InterSoccer: Fetched metadata for course:", metadata);
                            const remainingWeeks = metadata.remaining_weeks || null;
                            const playerId = $form.find(".player-select").val() || "";
                            updateFormData(playerId, remainingWeeks, variationId);

                            updateAddToCartButtonState(playerId, bookingType);

                            updateProductPrice(productId, variationId, remainingWeeks)
                                .then(() => {
                                    console.log("InterSoccer: Price updated for course");
                                })
                                .catch((error) => {
                                    console.error("InterSoccer: Failed to update price for Course:", error);
                                });
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
                                console.log("InterSoccer: Price updated for full-week camp");
                            })
                            .catch((error) => {
                                console.error("InterSoccer: Failed to update price for Full Week Camp:", error);
                            });
                    } else {
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
                    console.log("InterSoccer: found_variation event triggered");
                    handleVariation(variation);
                    isVariationEventProcessed = false;
                }, 200);
            });

            $form.on("woocommerce_variation_has_changed reset_data", function () {
                if (isVariationEventProcessed) return;
                isVariationEventProcessed = true;
                console.log("InterSoccer: woocommerce_variation_has_changed or reset_data event triggered");
                currentVariation = null;
                lastVariationId = null;
                lastBookingType = null;
                setTimeout(() => {
                    isVariationEventProcessed = false;
                }, 600);
            });

            $form.find('select[name="attribute_pa_booking-type"], input[name="attribute_pa_booking-type"]').on("change", function () {
                console.log("InterSoccer: Booking type changed, triggering check_variations");
                $form.trigger("check_variations");
            });

            let playerChangeTimeout;
            $form.find(".player-select").on("change", function () {
                const playerId = $(this).val() || "";
                const bookingType = $form.find('select[name="attribute_pa_booking-type"]').val() || $form.find('input[name="attribute_pa_booking-type"]').val();
                console.log("InterSoccer: Player selected:", playerId, "Booking type:", bookingType, "Current variation:", currentVariation?.variation_id);

                const remainingWeeks = $form.find('input[name="remaining_weeks"]').val();
                updateFormData(playerId, remainingWeeks, currentVariation?.variation_id);

                clearTimeout(playerChangeTimeout);
                playerChangeTimeout = setTimeout(() => {
                    if (!currentVariation) {
                        console.log("InterSoccer: No current variation, re-triggering check_variations");
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
                }, 600);
            });

            $(document).on("click", "button.single_add_to_cart_button, .elementor-add-to-cart button", function (e) {
                const $button = $(this);
                console.log("InterSoccer: Add to Cart button clicked, class:", $button.attr('class'));
                if ($button.hasClass("buy-now")) {
                    console.log("InterSoccer: Buy Now button clicked");
                    const playerId = $form.find(".player-select").val() || lastValidPlayerId;
                    const remainingWeeks = $form.find('input[name="remaining_weeks"]').val();
                    updateFormData(playerId, remainingWeeks, currentVariation?.variation_id);
                    $form.append('<input type="hidden" name="buy_now" value="1">');
                }
            });

            $form.on("submit", function (e) {
                console.log("InterSoccer: Form submitted");
                const playerId = $form.find(".player-select").val() || lastValidPlayerId;
                const remainingWeeks = $form.find('input[name="remaining_weeks"]').val();
                console.log(
                    "InterSoccer: Adding to cart via submit - Player:",
                    playerId,
                    "Remaining weeks:",
                    remainingWeeks
                );
            });

            $form.on("adding_to_cart", function (event, $button, data) {
                console.log("InterSoccer: adding_to_cart event triggered");
                const playerId = $form.find(".player-select").val() || lastValidPlayerId;
                const remainingWeeks = $form.find('input[name="remaining_weeks"]').val();
                if (playerId) data.player_assignment = playerId;
                if (remainingWeeks !== undefined && remainingWeeks !== null) data.remaining_weeks = remainingWeeks;
                data.quantity = 1; // Enforce quantity of 1
                console.log(
                    "InterSoccer: Adding to cart - Player:",
                    playerId,
                    "Remaining weeks:",
                    remainingWeeks,
                    "Quantity:",
                    data.quantity
                );
            });

            $form.trigger("check_variations");
            console.log("InterSoccer: Triggered check_variations on form load");

            setTimeout(() => {
                console.log("InterSoccer: Re-triggering check_variations after delay");
                $form.trigger("check_variations");
            }, 2000);
        }

        // Initialize plugin
        $(document).ready(function() {
            console.log("InterSoccer: jQuery ready, calling initializePlugin");
            initializePlugin();
        });

        // Additional event listeners for robustness
        setTimeout(function() {
            console.log("InterSoccer: setTimeout calling initializePlugin");
            initializePlugin();
        }, 2000);

        document.addEventListener('DOMContentLoaded', function() {
            console.log("InterSoccer: DOMContentLoaded, calling initializePlugin");
            initializePlugin();
        });

        document.addEventListener('load', function() {
            console.log("InterSoccer: Window load, calling initializePlugin");
            initializePlugin();
        });
    }

})(jQuery);
