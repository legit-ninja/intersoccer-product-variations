/**
 * File: variation-details.js
 * Description: Manages day selection and dynamic price updates for camps, and pro-rated pricing with subtotal for courses on WooCommerce product pages.
 * Dependencies: jQuery
 * Changes:
 * - Fixed subtotal disappearance after attendee selection (2025-05-25).
 * - Stabilized price display selector and added re-attachment logic (2025-05-25).
 * - Throttled events and increased debounce delay (2025-05-25).
 * - Added detailed logging for debugging (2025-05-25).
 */

jQuery(document).ready(function ($) {
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
            console.log(
              "InterSoccer: Nonce refreshed:",
              intersoccerCheckout.nonce
            );
            resolve();
          } else {
            console.error("InterSoccer: Nonce refresh failed:", response);
            reject(new Error("Nonce refresh failed"));
          }
        },
        error: function (xhr) {
          console.error(
            "InterSoccer: Nonce refresh error:",
            xhr.status,
            xhr.responseText
          );
          reject(new Error("Nonce refresh error: " + xhr.statusText));
        },
      });
    });
  }

  function fetchDaysOfWeek(productId, variationId) {
    return new Promise((resolve, reject) => {
      console.log(
        "InterSoccer: Fetching days for product ID:",
        productId,
        "variation ID:",
        variationId
      );
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
          console.log(
            "InterSoccer: intersoccer_get_days_of_week response:",
            response
          );
          if (
            response.success &&
            response.data.days &&
            Array.isArray(response.data.days)
          ) {
            console.log("InterSoccer: Days fetched:", response.data.days);
            resolve(response.data.days);
          } else {
            console.error(
              "InterSoccer: No days found in response:",
              response.data.message || "Unknown error"
            );
            reject(
              new Error(response.data.message || "No days found in response")
            );
          }
        },
        error: function (xhr) {
          console.error(
            "InterSoccer: AJAX error fetching days:",
            xhr.status,
            xhr.responseText
          );
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

  function calculateProRatedPrice(
    basePrice,
    startDate,
    totalWeeks,
    weeklyDiscount
  ) {
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

    const weeksPassed = Math.floor(
      (serverTime - start) / (7 * 24 * 60 * 60 * 1000)
    );
    const remainingWeeks = Math.max(0, totalWeeks - weeksPassed);
    let discountedPrice = basePrice - weeksPassed * weeklyDiscount;
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
            console.error(
              "InterSoccer: No metadata in response:",
              response.data.message
            );
            reject(new Error(response.data.message || "No metadata found"));
          }
        },
        error: function (xhr) {
          console.error(
            "InterSoccer: AJAX error fetching metadata:",
            xhr.status,
            xhr.responseText
          );
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

  const $form = $("form.cart");
  if (!$form.length) {
    console.error("InterSoccer: Product form not found on page");
    return;
  }

  const productId =
    $form.data("product_id") ||
    $form.find('input[name="product_id"]').val() ||
    "unknown";
  let productType = "unknown";
  let currentVariation = null;
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
        console.error(
          "InterSoccer: Failed to fetch product type:",
          xhr.status,
          xhr.responseText
        );
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

  // Add inline CSS for custom price container
  $form.append(`
    <style>
      .intersoccer-custom-price {
        margin-top: 10px;
        padding: 10px;
        background: #f9f9f9;
        border-radius: 4px;
        display: block !important;
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

  function updateFormData(playerId, days, price, remainingWeeks = null) {
    $form.find('input[name="player_assignment"]').remove();
    $form.find('input[name="camp_days[]"]').remove();
    $form.find('input[name="adjusted_price"]').remove();
    $form.find('input[name="remaining_weeks"]').remove();

    if (playerId) {
      $form.append(
        `<input type="hidden" name="player_assignment" value="${playerId}">`
      );
    }
    if (days && days.length) {
      days.forEach((day) => {
        $form.append(`<input type="hidden" name="camp_days[]" value="${day}">`);
      });
    }
    if (price) {
      $form.append(
        `<input type="hidden" name="adjusted_price" value="${price}">`
      );
    }
    if (remainingWeeks !== null) {
      $form.append(
        `<input type="hidden" name="remaining_weeks" value="${remainingWeeks}">`
      );
    }
    console.log(
      "InterSoccer: Updated form data - Player:",
      playerId,
      "Days:",
      days,
      "Price:",
      price,
      "Remaining weeks:",
      remainingWeeks
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
      console.log(
        "InterSoccer: Rendering checkboxes for variation:",
        variationId
      );
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
      console.log(
        "InterSoccer: Skipping checkbox re-render, same variation:",
        variationId
      );
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
          updateFormData(playerId, selectedDays, totalPrice);
          // Update custom price container
          const $priceDisplay = $form
            .find(".variations_form .single_variation")
            .first();
          $form.find(".intersoccer-custom-price").remove();
          let $customPrice = $priceDisplay.find(".intersoccer-custom-price");
          if (!$customPrice.length) {
            $priceDisplay.append(
              '<div class="intersoccer-custom-price"></div>'
            );
            $customPrice = $priceDisplay.find(".intersoccer-custom-price");
          }
          $customPrice.html(`
            <span class="total-price">Total: CHF ${totalPrice.toFixed(2)} for ${
            selectedDays.length
          } day${selectedDays.length !== 1 ? "s" : ""}</span>
          `);
          console.log(
            "InterSoccer: Updated custom price container - Base:",
            variationPrice,
            "Total:",
            totalPrice,
            "Days:",
            selectedDays.length,
            "Price display count:",
            $priceDisplay.length
          );

          const checkboxCount = $dayCheckboxes.find(
            'input[type="checkbox"]'
          ).length;
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

          if (
            selectedDays.length === availableDays.length &&
            availableDays.length > 0
          ) {
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
  function handleVariation(variation, retryCount = 0, maxRetries = 5) {
    if (isProcessing || isCheckboxUpdate) return;
    isProcessing = true;

    console.log(
      "InterSoccer: handleVariation triggered for variation ID:",
      variation?.variation_id,
      "Retry:",
      retryCount
    );

    clearTimeout(variationTimeout);
    variationTimeout = setTimeout(() => {
      const bookingType =
        variation?.attributes?.attribute_pa_booking_type ||
        $form.find('select[name="attribute_pa_booking-type"]').val() ||
        $form.find('input[name="attribute_pa_booking-type"]').val() ||
        "";
      const variationId = variation?.variation_id || 0;
      let variationPrice =
        Number(variation?.display_price) ||
        Number(variation?.display_regular_price);
      if (!variationPrice || isNaN(variationPrice)) {
        console.error(
          "InterSoccer: Invalid or missing display_price for variation ID:",
          variationId
        );
        isProcessing = false;
        return;
      }
      console.log(
        "InterSoccer: Variation price:",
        variationPrice,
        "Booking type:",
        bookingType
      );

      currentVariation = variation; // Store current variation

      const $addToCartButton = $form.find("button.single_add_to_cart_button");
      const $priceDisplay = $form
        .find(".variations_form .single_variation")
        .first();
      const $daySelection = $form.find(".intersoccer-day-selection");
      const $dayCheckboxes = $form.find(".intersoccer-day-checkboxes");
      const $dayNotification = $form.find(".intersoccer-day-notification");
      const $errorMessage = $form.find(
        ".intersoccer-day-selection .error-message"
      );

      // Clear all existing custom price containers
      $form.find(".intersoccer-custom-price").remove();

      $addToCartButton.prop("disabled", true);

      if (
        productType === "camp" &&
        bookingType.toLowerCase() === "single-days"
      ) {
        if (!$daySelection.length && retryCount < maxRetries) {
          console.log(
            "InterSoccer: Day selection not found, retrying",
            retryCount + 1,
            "/",
            maxRetries
          );
          setTimeout(() => handleVariation(variation, retryCount + 1), 1000);
          isProcessing = false;
          return;
        }
        $daySelection.show();
        console.log(
          "InterSoccer: Showing day selection row for Single Day(s) camp"
        );
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
            $addToCartButton.prop(
              "disabled",
              !(playerId && selectedDays.length > 0)
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
              console.log(
                "InterSoccer: Fetched metadata for course:",
                metadata
              );
              let totalPrice = Number(variationPrice);
              let originalPrice = totalPrice;
              let remainingWeeks = metadata.remaining_weeks;
              let savings = 0;
              if (
                metadata.start_date &&
                new Date(intersoccerCheckout.server_time) >
                  new Date(metadata.start_date)
              ) {
                const pricing = calculateProRatedPrice(
                  totalPrice,
                  metadata.start_date,
                  metadata.total_weeks,
                  metadata.weekly_discount
                );
                totalPrice = Number(pricing.price);
                remainingWeeks = pricing.remainingWeeks;
                savings = originalPrice - totalPrice;
              }
              const playerId = $form.find(".player-select").val();
              updateFormData(playerId, [], totalPrice, remainingWeeks);
              // Add custom price container
              if (!$priceDisplay.length && retryCount < maxRetries) {
                console.log(
                  "InterSoccer: Price display not found, retrying",
                  retryCount + 1,
                  "/",
                  maxRetries
                );
                setTimeout(
                  () => handleVariation(variation, retryCount + 1),
                  1000
                );
                isProcessing = false;
                return;
              }
              let $customPrice = $priceDisplay.find(
                ".intersoccer-custom-price"
              );
              if (!$customPrice.length) {
                $priceDisplay.append(
                  '<div class="intersoccer-custom-price"></div>'
                );
                $customPrice = $priceDisplay.find(".intersoccer-custom-price");
              }
              if (savings > 0 || totalPrice < originalPrice) {
                $customPrice.html(`
                  <span class="subtotal">Subtotal: CHF ${totalPrice.toFixed(
                    2
                  )}</span>
                  <span class="weeks-remaining">${remainingWeeks} Weeks Remaining</span>
                  <span class="discounted-price">Discounted: CHF ${totalPrice.toFixed(
                    2
                  )}</span>
                  <span class="savings">You saved CHF ${savings.toFixed(
                    2
                  )}</span>
                `);
              } else {
                $customPrice.html(`
                  <span class="total-price">Total: CHF ${totalPrice.toFixed(
                    2
                  )}</span>
                `);
              }
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
                $priceDisplay.length
              );
              $addToCartButton.prop("disabled", !playerId);
              $addToCartButton.removeClass("disabled");
            })
            .catch((error) => {
              console.error(
                "InterSoccer: Failed to fetch course metadata:",
                error
              );
              const playerId = $form.find(".player-select").val();
              updateFormData(playerId, [], variationPrice);
              console.log(
                "InterSoccer: Set price to:",
                variationPrice,
                "Player:",
                playerId
              );
              $addToCartButton.prop("disabled", !playerId);
              $addToCartButton.removeClass("disabled");
            });
        } else if (variation && variationPrice) {
          const playerId = $form.find(".player-select").val();
          updateFormData(playerId, [], variationPrice);
          $form.find('input[name="quantity"]').val(1);
          console.log(
            "InterSoccer: Set price to:",
            variationPrice,
            "Player:",
            playerId
          );
          $addToCartButton.prop("disabled", !playerId);
          $addToCartButton.removeClass("disabled");
        }
      }

      isProcessing = false;
    }, 100);
  }

  let debounceTimeout;
  $form.on("found_variation", function (event, variation) {
    clearTimeout(debounceTimeout);
    debounceTimeout = setTimeout(() => {
      console.log("InterSoccer: found_variation event triggered");
      handleVariation(variation);
    }, 100);
  });

  $form.on("woocommerce_variation_has_changed reset_data", function () {
    console.log(
      "InterSoccer: woocommerce_variation_has_changed or reset_data event triggered"
    );
    if (currentVariation) {
      setTimeout(() => {
        console.log(
          "InterSoccer: Re-rendering price container after form event"
        );
        handleVariation(currentVariation);
      }, 200);
    }
  });

  // Trigger variation check on booking type change
  $form
    .find(
      'select[name="attribute_pa_booking-type"], input[name="attribute_pa_booking-type"]'
    )
    .on("change", function () {
      console.log(
        "InterSoccer: Booking type changed, triggering check_variations"
      );
      $form.trigger("check_variations");
    });

  // Debounce player selection updates
  let playerChangeTimeout;
  $form.find(".player-select").on("change", function () {
    const playerId = $(this).val();
    const bookingType =
      $form.find('select[name="attribute_pa_booking-type"]').val() ||
      $form.find('input[name="attribute_pa_booking-type"]').val();
    const selectedDaysCount = selectedDays.length;
    const $addToCartButton = $form.find("button.single_add_to_cart_button");

    console.log(
      "InterSoccer: Player selected:",
      playerId,
      "Booking type:",
      bookingType,
      "Current variation:",
      currentVariation?.variation_id
    );

    const adjustedPrice = $form.find('input[name="adjusted_price"]').val();
    const remainingWeeks = $form.find('input[name="remaining_weeks"]').val();
    updateFormData(playerId, selectedDays, adjustedPrice, remainingWeeks);

    clearTimeout(playerChangeTimeout);
    playerChangeTimeout = setTimeout(() => {
      if (currentVariation) {
        console.log(
          "InterSoccer: Re-rendering price container after player selection"
        );
        handleVariation(currentVariation);
      } else {
        console.log(
          "InterSoccer: No current variation, re-triggering check_variations"
        );
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
        console.log(
          "InterSoccer: Player changed, button state - Player:",
          playerId
        );
        $addToCartButton.removeClass("disabled");
      }
    }, 300);
  });

  $form.find("button.single_add_to_cart_button").on("click", function (e) {
    const $button = $(this);
    if ($button.hasClass("buy-now")) {
      console.log("InterSoccer: Buy Now button clicked");
      const playerId = $form.find(".player-select").val();
      const adjustedPrice = $form.find('input[name="adjusted_price"]').val();
      const remainingWeeks = $form.find('input[name="remaining_weeks"]').val();
      updateFormData(playerId, selectedDays, adjustedPrice, remainingWeeks);
      $form.append('<input type="hidden" name="buy_now" value="1">');
    }
  });

  $form.on("adding_to_cart", function (event, $button, data) {
    const playerId = $form.find(".player-select").val();
    const adjustedPrice = $form.find('input[name="adjusted_price"]').val();
    const remainingWeeks = $form.find('input[name="remaining_weeks"]').val();
    if (playerId) data.player_assignment = playerId;
    if (selectedDays.length) data.camp_days = selectedDays;
    if (adjustedPrice) data.custom_price = adjustedPrice;
    if (remainingWeeks) data.remaining_weeks = remainingWeeks;
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
});
