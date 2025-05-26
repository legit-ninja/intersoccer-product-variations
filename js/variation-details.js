/**
 * File: variation-details.js
 * Description: Manages day selection and dynamic price updates for camps, and pro-rated pricing with subtotal for courses on WooCommerce product pages.
 * Dependencies: jQuery
 * Author: Jeremy Lee
 * Changes:
 * - Refined selectors to prioritize price elements and use parent insertion (2025-05-26).
 * - Allowed re-rendering on player selection with forceRender flag (2025-05-26).
 * - Added MutationObserver for dynamic form detection (2025-05-26).
 * - Extended initial delay to 2000ms (2025-05-26).
 * - Enhanced logging for debugging (2025-05-26).
 * - Removed redundant player fetching logic (handled by elementor-widgets.php) (2025-05-26).
 * - Prevented sub-total flicker by updating content in place (2025-05-26).
 * - Ensured remaining_weeks is sent in AJAX add-to-cart (2025-05-26).
 * - Enhanced MutationObserver with periodic retries and Elementor event listener (2025-05-26).
 * - Always send remaining_weeks for Course products to ensure Discount message display (2025-05-26).
 * - Added price display for non-discounted Courses with remaining_weeks (2025-05-26).
 */

jQuery(document).ready(function ($) {
  function initializePlugin() {
    if (!intersoccerCheckout || !intersoccerCheckout.ajax_url) {
      console.error("InterSoccer: intersoccerCheckout not initialized.");
      return;
    }

    const translations = window.intersoccerTranslations || {
      select_days: "Select days",
    };

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
          },
        });
      });
    }

    function fetchDaysOfWeek(productId, variationId) {
      return new Promise((resolve, reject) => {
        console.log("InterSoccer: Fetching days for product ID:", productId, "variation ID:", variationId);
        $.ajax({
          url: intersoccerCheckout.ajax_url,
          type: "POST",
          data: {
            action: "intersoccer_get_days_of_week",
            nonce: intersoccerCheckout.nonce,
            product_id: productId,
            variation_id: variationId,
          },
          success: function (response) {
            console.log("InterSoccer: intersoccer_get_days_of_week response:", response);
            if (response.success && response.data.days && Array.isArray(response.data.days)) {
              console.log("InterSoccer: Days fetched:", response.data.days);
              resolve(response.data.days);
            } else {
              console.error("InterSoccer: No days found in response:", response.data.message || "Unknown error");
              reject(new Error(response.data.message || "No days found in response"));
            }
          },
          error: function (xhr) {
            console.error("InterSoccer: AJAX error fetching days:", xhr.status, xhr.responseText);
            if (xhr.status === 403) {
              refreshNonce()
                .then(() => fetchDaysOfWeek(productId, variationId))
                .then(resolve)
                .catch(reject);
            } else {
              reject(new Error("Failed to fetch days: " + xhr.statusText));
            }
          },
        });
      });
    }

    function calculateProRatedPrice(basePrice, startDate, totalWeeks, weeklyDiscount) {
      const serverTime = new Date(intersoccerCheckout.server_time || new Date());
      let start;

      // Try multiple date formats
      const formats = ["YYYY-MM-DD", "DD/MM/YYYY", "MM-DD-YYYY"];
      for (const format of formats) {
        if (format === "YYYY-MM-DD") {
          start = new Date(startDate);
        } else if (format === "DD/MM/YYYY") {
          const [day, month, year] = startDate.split("/");
          start = new Date(`${year}-${month}-${day}`);
        } else if (format === "MM-DD-YYYY") {
          const [month, day, year] = startDate.split("-");
          start = new Date(`${year}-${month}-${day}`);
        }
        if (start && !isNaN(start.getTime())) {
          break;
        }
      }

      if (!start || isNaN(start.getTime())) {
        console.error("InterSoccer: Invalid start date:", startDate);
        return { price: basePrice, remainingWeeks: totalWeeks };
      }

      const weeksPassed = Math.floor((serverTime - start) / (7 * 24 * 60 * 60 * 1000));
      const remainingWeeks = Math.max(0, totalWeeks - weeksPassed);
      let discountedPrice = basePrice - (weeksPassed * weeklyDiscount);
      // Cap discounted price
      discountedPrice = Math.min(basePrice, Math.max(0, discountedPrice));

      console.log(
        "InterSoccer: Pro-rated price - Base:",
        basePrice,
        "Start:",
        startDate,
        "Weeks passed:",
        weeksPassed,
        "Remaining:",
        remainingWeeks,
        "Weekly discount:",
        weeklyDiscount,
        "Discounted price:",
        discountedPrice
      );
      return { price: discountedPrice.toFixed(2), remainingWeeks };
    }

    function fetchCourseMetadata(productId, variationId) {
      return new Promise((resolve, reject) => {
        $.ajax({
          url: intersoccerCheckout.ajax_url,
          type: "POST",
          data: {
            action: "intersoccer_get_course_metadata",
            nonce: intersoccerCheckout.nonce,
            product_id: productId,
            variation_id: variationId,
          },
          success: function (response) {
            if (response.success && response.data) {
              console.log("InterSoccer: Course metadata fetched:", response.data);
              resolve({
                start_date: response.data.start_date || "2025-01-01",
                total_weeks: parseInt(response.data.total_weeks, 10) || 1,
                weekly_discount: parseFloat(response.data.weekly_discount) || 0,
                remaining_weeks: parseInt(response.data.remaining_weeks, 10) || 1,
              });
            } else {
              console.error("InterSoccer: No metadata in response:", response.data.message);
              reject(new Error(response.data.message || "No metadata found"));
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
              reject(new Error("Failed to fetch metadata: " + xhr.statusText));
            }
          },
        });
      });
    }

    let $form = $("form.cart");
    let formObserver;

    function setupFormObserver() {
      let retryCount = 0;
      const maxRetries = 10;
      const retryInterval = 1000; // Retry every 1 second

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

      // Initial check
      $form = $("form.cart");
      if ($form.length) {
        console.log("InterSoccer: Form found on initial check");
        setupFormHandlers();
        formObserver.disconnect();
      } else {
        console.log("InterSoccer: Product form not found, starting MutationObserver");
        formObserver.observe(document.body, { childList: true, subtree: true });

        // Periodic retry
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

    // Listen for Elementor frontend initialization
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
      let lastPlayerId = null;

      function fetchProductType() {
        $.ajax({
          url: intersoccerCheckout.ajax_url,
          type: "POST",
          data: {
            action: "intersoccer_get_product_type",
            nonce: intersoccerCheckout.nonce,
            product_id: productId,
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
          },
        });
      }
      fetchProductType();

      let selectedDays = [];
      let isProcessing = false;
      let availableDays = [];
      let currentVariationId = null;
      let isCheckboxUpdate = false;
      let cachedSubtotal = null;

      // Add inline CSS for custom price container
      $form.append(`
        <style>
          .intersoccer-custom-price {
            margin-top: 5px;
            padding: 10px;
            background: #f9f9f9;
            border-radius: 4px;
            display: block !important;
            position: relative;
            top: 0;
            left: 0;
            clear: both;
            width: 100%;
            order: 2;
            flex-grow: 1;
          }
          .intersoccer-custom-price .total-price {
            font-size: 1em;
            color: #eec432;
            display: block;
          }
          .intersoccer-custom-price .savings {
            color: #27ae60;
            margin-left: 10px;
          }
          .intersoccer-custom-price .subtotal,
          .intersoccer-custom-price .weeks-remaining {
            font-size: 0.9em;
            color: #333333;
            display: block;
          }
        </style>
      `);

      function updateFormData(playerId, days, price, remainingWeeks = null, variationId = null) {
        $form.find('input[name="player_assignment"]').remove();
        $form.find('input[name="camp_days[]"]').remove();
        $form.find('input[name="adjusted_price"]').remove();
        $form.find('input[name="remaining_weeks"]').remove();

        if (playerId) {
          $form.append(`<input type="hidden" name="player_assignment" value="${playerId}">`);
        }
        if (days && days.length) {
          days.forEach((day) => {
            $form.append(`<input type="hidden" name="camp_days[]" value="${day}">`);
          });
        }
        if (price) {
          $form.append(`<input type="hidden" name="adjusted_price" value="${price}">`);
        }
        if (remainingWeeks !== null) {
          $form.append(`<input type="hidden" name="remaining_weeks" value="${remainingWeeks}">`);
        }
        console.log(
          "InterSoccer: Updated form data - Player:",
          playerId,
          "Days:",
          days,
          "Price:",
          price,
          "Remaining weeks:",
          remainingWeeks,
          "Variation ID:",
          variationId
        );
      }

      function renderCheckboxes(
        days,
        $dayCheckboxes,
        $dayNotification,
        $errorMessage,
        variationPrice,
        playerId,
        variationId
      ) {
        if (
          currentVariationId !== variationId ||
          $dayCheckboxes.find('input[type="checkbox"]').length === 0
        ) {
          console.log("InterSoccer: Rendering checkboxes for variation:", variationId);
          $dayCheckboxes.empty();
          $dayNotification.empty();
          $errorMessage.hide();
          days.forEach((day) => {
            const isChecked = selectedDays.includes(day) ? "checked" : "";
            $dayCheckboxes.append(`
              <label style="margin-right: 10px;">
                <input type="checkbox" name="camp_days_temp[]" value="${day}" ${isChecked}> ${day}
              </label>
            `);
          });
        } else {
          console.log("InterSoccer: Skipping checkbox re-render, same variation:", variationId);
        }

        $dayCheckboxes
          .find('input[type="checkbox"]')
          .off("change")
          .on("change", function (e) {
            e.stopPropagation();
            if (isProcessing) return;
            isProcessing = true;
            isCheckboxUpdate = true;

            selectedDays = $dayCheckboxes
              .find('input[type="checkbox"]:checked')
              .map(function () {
                return $(this).val();
              })
              .get();

            const quantity = 1;
            const totalPrice = selectedDays.length * variationPrice;

            clearTimeout(window.intersoccerUpdateTimeout);
            window.intersoccerUpdateTimeout = setTimeout(() => {
              $form.find('input[name="quantity"]').val(quantity);
              updateFormData(playerId, selectedDays, totalPrice, null, variationId);
              // Update custom price container
              let $priceDisplay = $form.closest('.variations_form').find(".woocommerce-variation-price .woocommerce-Price-amount, .price .woocommerce-Price-amount, .price .amount, .elementor-widget-woocommerce-product-price .amount, .basel-price, .basel-variation-price, .elementor-price-amount, .woocommerce-variation-price").first();
              let $priceParent = $priceDisplay.closest('.woocommerce-variation-price, .price, .elementor-widget-woocommerce-product-price');
              let $customPrice = $priceParent.siblings(".intersoccer-custom-price");
              const newContent = `<span class="total-price">Total: CHF ${totalPrice.toFixed(2)} for ${selectedDays.length} day${selectedDays.length !== 1 ? "s" : ""}</span>`;
              if (!$customPrice.length) {
                $priceParent.after('<div class="intersoccer-custom-price"></div>');
                $customPrice = $priceParent.siblings(".intersoccer-custom-price");
              }
              // Update content in place to prevent flicker
              if ($customPrice.html() !== newContent) {
                $customPrice.html(newContent);
              }
              cachedSubtotal = newContent;
              console.log(
                "InterSoccer: Updated custom price container - Base:",
                variationPrice,
                "Total:",
                totalPrice,
                "Days:",
                selectedDays.length,
                "Price display count:",
                $priceDisplay.length,
                "Price display element:",
                $priceDisplay[0]?.outerHTML.substring(0, 100) + "...",
                "Price display class:",
                $priceDisplay.attr('class'),
                "Price parent element:",
                $priceParent[0]?.outerHTML.substring(0, 100) + "..."
              );

              const checkboxCount = $dayCheckboxes.find('input[type="checkbox"]').length;
              console.log(
                "InterSoccer: Checkbox states after update:",
                $dayCheckboxes
                  .find('input[type="checkbox"]')
                  .map(function () {
                    return $(this).val() + ":" + $(this).prop("disabled");
                  })
                  .get(),
                "Checkbox count:",
                checkboxCount
              );

              if (checkboxCount === 0) {
                console.warn("InterSoccer: Checkboxes missing, re-rendering");
                renderCheckboxes(
                  days,
                  $dayCheckboxes,
                  $dayNotification,
                  $errorMessage,
                  variationPrice,
                  playerId,
                  variationId
                );
              }

              if (selectedDays.length === availableDays.length && availableDays.length > 0) {
                $dayNotification.text(
                  "You’ve selected all days! Consider choosing the Full Week option for potential savings."
                );
              } else {
                $dayNotification.empty();
              }

              $form
                .find("button.single_add_to_cart_button")
                .prop("disabled", !(playerId && selectedDays.length > 0));
              console.log(
                "InterSoccer: Button state - Player:",
                playerId,
                "Days selected:",
                selectedDays.length
              );

              $dayCheckboxes.find('input[type="checkbox"]').prop("disabled", false);
            }, 100);

            isProcessing = false;
            isCheckboxUpdate = false;
          });
      }

      let variationTimeout;
      function handleVariation(variation, retryCount = 0, maxRetries = 12, forceRender = false) {
        if (isProcessing || isCheckboxUpdate) return;
        if (variation?.variation_id === lastVariationId && !forceRender && retryCount === 0) {
          console.log("InterSoccer: Skipping duplicate variation handling for ID:", variation.variation_id);
          if (cachedSubtotal) {
            let $priceDisplay = $form.closest('.variations_form').find(".woocommerce-variation-price .woocommerce-Price-amount, .price .woocommerce-Price-amount, .price .amount, .elementor-widget-woocommerce-product-price .amount, .basel-price, .basel-variation-price, .elementor-price-amount, .woocommerce-variation-price").first();
            let $priceParent = $priceDisplay.closest('.woocommerce-variation-price, .price, .elementor-widget-woocommerce-product-price');
            let $customPrice = $priceParent.siblings(".intersoccer-custom-price");
            if (!$customPrice.length) {
              $priceParent.after('<div class="intersoccer-custom-price"></div>');
              $customPrice = $priceParent.siblings(".intersoccer-custom-price");
            }
            // Update content in place to prevent flicker
            if ($customPrice.html() !== cachedSubtotal) {
              $customPrice.html(cachedSubtotal);
              console.log("InterSoccer: Restored cached subtotal");
            }
          }
          return;
        }
        isProcessing = true;

        console.log("InterSoccer: handleVariation triggered for variation ID:", variation?.variation_id, "Retry:", retryCount, "Force:", forceRender);

        clearTimeout(variationTimeout);
        variationTimeout = setTimeout(() => {
          const bookingType =
            variation?.attributes?.attribute_pa_booking_type ||
            $form.find('select[name="attribute_pa_booking-type"]').val() ||
            $form.find('input[name="attribute_pa_booking-type"]').val() ||
            "";
          const variationId = variation?.variation_id || 0;
          let variationPrice = Number(variation?.display_price) || Number(variation?.display_regular_price);
          if (!variationPrice || isNaN(variationPrice)) {
            console.error("InterSoccer: Invalid or missing display_price for variation ID:", variationId);
            isProcessing = false;
            return;
          }
          console.log("InterSoccer: Variation price:", variationPrice, "Booking type:", bookingType);

          currentVariation = variation; // Store current variation
          lastVariationId = variationId;

          const $addToCartButton = $form.find("button.single_add_to_cart_button");
          let $priceDisplay = $form.closest('.variations_form').find(".woocommerce-variation-price .woocommerce-Price-amount, .price .woocommerce-Price-amount, .price .amount, .elementor-widget-woocommerce-product-price .amount, .basel-price, .basel-variation-price, .elementor-price-amount, .woocommerce-variation-price").first();
          const $daySelection = $form.find(".intersoccer-day-selection");
          const $dayCheckboxes = $form.find(".intersoccer-day-checkboxes");
          const $dayNotification = $form.find(".intersoccer-day-notification");
          const $errorMessage = $form.find(".intersoccer-day-selection .error-message");

          $addToCartButton.prop("disabled", true);

          if (productType === "camp" && bookingType.toLowerCase() === "single-days") {
            if (!$daySelection.length && retryCount < maxRetries) {
              console.log("InterSoccer: Day selection not found, retrying", retryCount + 1, "/", maxRetries);
              console.log("InterSoccer: Variations form HTML:", $form[0]?.outerHTML.substring(0, 500) + "...");
              setTimeout(() => handleVariation(variation, retryCount + 1, maxRetries, forceRender), 3500);
              isProcessing = false;
              return;
            }
            $daySelection.show();
            console.log("InterSoccer: Showing day selection row for Single Day(s) camp");
            fetchDaysOfWeek(productId, variationId)
              .then((days) => {
                availableDays = days;
                console.log("InterSoccer: Rendering checkboxes for days:", days);
                const playerId = $form.find(".player-select").val();
                renderCheckboxes(
                  days,
                  $dayCheckboxes,
                  $dayNotification,
                  $errorMessage,
                  variationPrice,
                  playerId,
                  variationId
                );
                currentVariationId = variationId;

                const quantity = 1;
                const totalPrice = selectedDays.length * variationPrice;
                console.log("InterSoccer: Initial price update to:", totalPrice);
                $addToCartButton.prop("disabled", !(playerId && selectedDays.length > 0));

                // Render price for Single Day(s) camps
                if (!$priceDisplay.length && retryCount < maxRetries) {
                  console.log("InterSoccer: Price display not found for camp, retrying", retryCount + 1, "/", maxRetries);
                  console.log("InterSoccer: Variations form HTML:", $form[0]?.outerHTML.substring(0, 500) + "...");
                  console.log("InterSoccer: Price display exists:", $form.closest('.variations_form').find(".woocommerce-variation-price .woocommerce-Price-amount, .price .woocommerce-Price-amount, .price .amount, .elementor-widget-woocommerce-product-price .amount, .basel-price, .basel-variation-price, .elementor-price-amount, .woocommerce-variation-price").length > 0);
                  setTimeout(() => handleVariation(variation, retryCount + 1, maxRetries, forceRender), 3500);
                  isProcessing = false;
                  return;
                }
                let $priceParent = $priceDisplay.closest('.woocommerce-variation-price, .price, .elementor-widget-woocommerce-product-price');
                let $customPrice = $priceParent.siblings(".intersoccer-custom-price");
                const newContent = `<span class="total-price">Total: CHF ${totalPrice.toFixed(2)} for ${selectedDays.length} day${selectedDays.length !== 1 ? "s" : ""}</span>`;
                if (!$customPrice.length) {
                  $priceParent.after('<div class="intersoccer-custom-price"></div>');
                  $customPrice = $priceParent.siblings(".intersoccer-custom-price");
                }
                // Update content in place to prevent flicker
                if ($customPrice.html() !== newContent) {
                  $customPrice.html(newContent);
                }
                cachedSubtotal = newContent;
                console.log(
                  "InterSoccer: Camp price rendered - Total:",
                  totalPrice,
                  "Days:",
                  selectedDays.length,
                  "Player:",
                  playerId,
                  "Price display count:",
                  $priceDisplay.length,
                  "Price display element:",
                  $priceDisplay[0]?.outerHTML.substring(0, 100) + "...",
                  "Price display class:",
                  $priceDisplay.attr('class'),
                  "Price parent element:",
                  $priceParent[0]?.outerHTML.substring(0, 100) + "..."
                );
              })
              .catch((error) => {
                console.error("InterSoccer: Failed to load days:", error);
                $daySelection.hide();
                $errorMessage.text("Failed to load days. Please try again.").show();
                setTimeout(() => $errorMessage.hide(), 5000);
              });
          } else {
            $daySelection.hide();
            selectedDays = [];
            currentVariationId = null;
            if (productType === "course") {
              fetchCourseMetadata(productId, variationId)
                .then((metadata) => {
                  console.log("InterSoccer: Fetched metadata for course:", metadata);
                  let totalPrice = Number(variationPrice);
                  let originalPrice = totalPrice;
                  let remainingWeeks = metadata.remaining_weeks;
                  let savings = 0;
                  let shouldShowSubtotal = false;
                  if (metadata.start_date && new Date(intersoccerCheckout.server_time) > new Date(metadata.start_date)) {
                    const pricing = calculateProRatedPrice(
                      totalPrice,
                      metadata.start_date,
                      metadata.total_weeks,
                      metadata.weekly_discount
                    );
                    totalPrice = Number(pricing.price);
                    remainingWeeks = pricing.remainingWeeks;
                    savings = originalPrice - totalPrice;
                    shouldShowSubtotal = true;
                  }
                  const playerId = $form.find(".player-select").val();
                  // Always include remaining_weeks for Courses
                  updateFormData(playerId, [], totalPrice, remainingWeeks, variationId);
                  // Add custom price container for courses with pro-rated discount
                  if (shouldShowSubtotal) {
                    if (!$priceDisplay.length && retryCount < maxRetries) {
                      console.log("InterSoccer: Price display not found for course, retrying", retryCount + 1, "/", maxRetries);
                      console.log("InterSoccer: Variations form HTML:", $form[0]?.outerHTML.substring(0, 500) + "...");
                      console.log("InterSoccer: Price display exists:", $form.closest('.variations_form').find(".woocommerce-variation-price .woocommerce-Price-amount, .price .woocommerce-Price-amount, .price .amount, .elementor-widget-woocommerce-product-price .amount, .basel-price, .basel-variation-price, .elementor-price-amount, .woocommerce-variation-price").length > 0);
                      setTimeout(() => handleVariation(variation, retryCount + 1, maxRetries, forceRender), 3500);
                      isProcessing = false;
                      return;
                    }
                    let $priceParent = $priceDisplay.closest('.woocommerce-variation-price, .price, .elementor-widget-woocommerce-product-price');
                    let $customPrice = $priceParent.siblings(".intersoccer-custom-price");
                    const newContent = `
                      <span class="subtotal">Subtotal: CHF ${totalPrice.toFixed(2)}</span>
                      <span class="weeks-remaining">${remainingWeeks} Weeks Remaining</span>
                      <span class="discounted-price">Discounted: CHF ${totalPrice.toFixed(2)}</span>
                      <span class="savings">You saved CHF ${savings.toFixed(2)}</span>
                    `;
                    if (!$customPrice.length) {
                      $priceParent.after('<div class="intersoccer-custom-price"></div>');
                      $customPrice = $priceParent.siblings(".intersoccer-custom-price");
                    }
                    // Update content in place to prevent flicker
                    if ($customPrice.html() !== newContent) {
                      $customPrice.html(newContent);
                    }
                    cachedSubtotal = newContent;
                    console.log(
                      "InterSoccer: Course price rendered - Original:",
                      originalPrice,
                      "Discounted:",
                      totalPrice,
                      "Savings:",
                      savings,
                      "Remaining weeks:",
                      remainingWeeks,
                      "Player:",
                      playerId,
                      "Price display count:",
                      $priceDisplay.length,
                      "Price display element:",
                      $priceDisplay[0]?.outerHTML.substring(0, 100) + "...",
                      "Price display class:",
                      $priceDisplay.attr('class'),
                      "Price parent element:",
                      $priceParent[0]?.outerHTML.substring(0, 100) + "..."
                    );
                  } else {
                    // Render price without discount for future courses
                    let $priceParent = $priceDisplay.closest('.woocommerce-variation-price, .price, .elementor-widget-woocommerce-product-price');
                    let $customPrice = $priceParent.siblings(".intersoccer-custom-price");
                    const newContent = `
                      <span class="total-price">Total: CHF ${totalPrice.toFixed(2)}</span>
                      <span class="weeks-remaining">${remainingWeeks} Weeks Remaining</span>
                    `;
                    if (!$customPrice.length) {
                      $priceParent.after('<div class="intersoccer-custom-price"></div>');
                      $customPrice = $priceParent.siblings(".intersoccer-custom-price");
                    }
                    if ($customPrice.html() !== newContent) {
                      $customPrice.html(newContent);
                    }
                    cachedSubtotal = newContent;
                    console.log(
                      "InterSoccer: Course price rendered (no discount) - Total:",
                      totalPrice,
                      "Remaining weeks:",
                      remainingWeeks,
                      "Player:",
                      playerId
                    );
                  }
                  $addToCartButton.prop("disabled", !playerId);
                  $addToCartButton.removeClass('disabled');
                })
                .catch((error) => {
                  console.error("InterSoccer: Failed to fetch course metadata:", error);
                  const playerId = $form.find(".player-select").val();
                  updateFormData(playerId, [], variationPrice, null, variationId);
                  console.log("InterSoccer: Set price to:", variationPrice, "Player:", playerId);
                  $addToCartButton.prop("disabled", !playerId);
                  $addToCartButton.removeClass('disabled');
                });
            } else if (productType === "camp" && bookingType.toLowerCase() === "full-week") {
              const playerId = $form.find(".player-select").val();
              updateFormData(playerId, [], variationPrice, null, variationId);
              $form.find('input[name="quantity"]').val(1);
              // Add custom price container for Full Week camps
              if (!$priceDisplay.length && retryCount < maxRetries) {
                console.log("InterSoccer: Price display not found for full-week camp, retrying", retryCount + 1, "/", maxRetries);
                console.log("InterSoccer: Variations form HTML:", $form[0]?.outerHTML.substring(0, 500) + "...");
                console.log("InterSoccer: Price display exists:", $form.closest('.variations_form').find(".woocommerce-variation-price .woocommerce-Price-amount, .price .woocommerce-Price-amount, .price .amount, .elementor-widget-woocommerce-product-price .amount, .basel-price, .basel-variation-price, .elementor-price-amount, .woocommerce-variation-price").length > 0);
                setTimeout(() => handleVariation(variation, retryCount + 1, maxRetries, forceRender), 3500);
                isProcessing = false;
                return;
              }
              let $priceParent = $priceDisplay.closest('.woocommerce-variation-price, .price, .elementor-widget-woocommerce-product-price');
              let $customPrice = $priceParent.siblings(".intersoccer-custom-price");
              const newContent = `<span class="total-price">Total: CHF ${variationPrice.toFixed(2)}</span>`;
              if (!$customPrice.length) {
                $priceParent.after('<div class="intersoccer-custom-price"></div>');
                $customPrice = $priceParent.siblings(".intersoccer-custom-price");
              }
              // Update content in place to prevent flicker
              if ($customPrice.html() !== newContent) {
                $customPrice.html(newContent);
              }
              cachedSubtotal = newContent;
              console.log(
                "InterSoccer: Full Week camp price rendered - Price:",
                variationPrice,
                "Player:",
                playerId,
                "Price display count:",
                $priceDisplay.length,
                "Price display element:",
                $priceDisplay[0]?.outerHTML.substring(0, 100) + "...",
                "Price display class:",
                $priceDisplay.attr('class'),
                "Price parent element:",
                $priceParent[0]?.outerHTML.substring(0, 100) + "..."
              );
              $addToCartButton.prop("disabled", !playerId);
              $addToCartButton.removeClass('disabled');
            }
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
        if (currentVariation) {
          setTimeout(() => {
            console.log("InterSoccer: Re-rendering price container after form event");
            handleVariation(currentVariation);
            isVariationEventProcessed = false;
          }, 600);
        } else {
          isVariationEventProcessed = false;
        }
      });

      // Trigger variation check on booking type change
      $form.find('select[name="attribute_pa_booking-type"], input[name="attribute_pa_booking-type"]').on("change", function () {
        console.log("InterSoccer: Booking type changed, triggering check_variations");
        $form.trigger("check_variations");
      });

      // Debounce player selection updates
      let playerChangeTimeout;
      $form.find(".player-select").on("change", function () {
        const playerId = $(this).val();
        const bookingType = $form.find('select[name="attribute_pa_booking-type"]').val() || $form.find('input[name="attribute_pa_booking-type"]').val();
        const selectedDaysCount = selectedDays.length;
        const $addToCartButton = $form.find("button.single_add_to_cart_button");

        console.log("InterSoccer: Player selected:", playerId, "Booking type:", bookingType, "Current variation:", currentVariation?.variation_id);

        const adjustedPrice = $form.find('input[name="adjusted_price"]').val();
        const remainingWeeks = $form.find('input[name="remaining_weeks"]').val();
        updateFormData(playerId, selectedDays, adjustedPrice, remainingWeeks, currentVariation?.variation_id);

        clearTimeout(playerChangeTimeout);
        playerChangeTimeout = setTimeout(() => {
          if (currentVariation) {
            console.log("InterSoccer: Re-rendering price container after player selection");
            handleVariation(currentVariation, 0, 12, true); // Force render
          } else {
            console.log("InterSoccer: No current variation, re-triggering check_variations");
            $form.trigger("check_variations");
          }

          if (bookingType === "single-days") {
            $addToCartButton.prop("disabled", !(playerId && selectedDaysCount > 0));
            console.log(
              "InterSoccer: Player changed, button state - Player:",
              playerId,
              "Days selected:",
              selectedDaysCount
            );
          } else {
            $addToCartButton.prop("disabled", !playerId);
            console.log("InterSoccer: Player changed, button state - Player:", playerId);
            $addToCartButton.removeClass('disabled');
          }
        }, 600);
      });

      // Handle add-to-cart button click
      $(document).on("click", "button.single_add_to_cart_button, .elementor-add-to-cart button", function (e) {
        const $button = $(this);
        console.log("InterSoccer: Add to Cart button clicked, class:", $button.attr('class'));
        if ($button.hasClass("buy-now")) {
          console.log("InterSoccer: Buy Now button clicked");
          const playerId = $form.find(".player-select").val();
          const adjustedPrice = $form.find('input[name="adjusted_price"]').val();
          const remainingWeeks = $form.find('input[name="remaining_weeks"]').val();
          updateFormData(playerId, selectedDays, adjustedPrice, remainingWeeks, currentVariation?.variation_id);
          $form.append('<input type="hidden" name="buy_now" value="1">');
        }
      });

      // Handle form submission
      $form.on("submit", function (e) {
        console.log("InterSoccer: Form submitted");
        const playerId = $form.find(".player-select").val();
        const adjustedPrice = $form.find('input[name="adjusted_price"]').val();
        const remainingWeeks = $form.find('input[name="remaining_weeks"]').val();
        console.log(
          "InterSoccer: Adding to cart via submit - Player:",
          playerId,
          "Days:",
          selectedDays,
          "Adjusted price:",
          adjustedPrice,
          "Remaining weeks:",
          remainingWeeks
        );
      });

      // Handle adding_to_cart event
      $form.on("adding_to_cart", function (event, $button, data) {
        console.log("InterSoccer: adding_to_cart event triggered");
        const playerId = $form.find(".player-select").val();
        const adjustedPrice = $form.find('input[name="adjusted_price"]').val();
        const remainingWeeks = $form.find('input[name="remaining_weeks"]').val();
        if (playerId) data.player_assignment = playerId;
        if (selectedDays.length) data.camp_days = selectedDays;
        if (adjustedPrice) data.custom_price = adjustedPrice;
        if (remainingWeeks !== undefined && remainingWeeks !== null) data.remaining_weeks = remainingWeeks;
        console.log(
          "InterSoccer: Adding to cart - Player:",
          playerId,
          "Days:",
          selectedDays,
          "Adjusted price:",
          adjustedPrice,
          "Remaining weeks:",
          remainingWeeks
        );
      });

      // Initial variation check
      $form.trigger("check_variations");
      console.log("InterSoccer: Triggered check_variations on form load");

      // Retry variation check
      setTimeout(() => {
        console.log("InterSoccer: Re-triggering check_variations after delay");
        $form.trigger("check_variations");
      }, 2000);
    }
  }

  // Initialize plugin with delay for Elementor
  setTimeout(initializePlugin, 2000);

  // Reinitialize on dynamic content load
  document.addEventListener('DOMContentLoaded', initializePlugin);
  window.addEventListener('load', initializePlugin);
});