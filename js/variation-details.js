/**
 * File: variation-details.js
 * Description: Manages day selection, dynamic price updates, and sibling discount notification for camps, and pro-rated pricing for courses on WooCommerce product pages.
 * Dependencies: jQuery
 * Author: Jeremy Lee
 * Changes (Summarized):
 * - Fixed wc_price undefined error by using raw price and formatting client-side with fallback (2025-06-20).
 * - Ensured correct price scaling for multiple days based on base price from server (2025-06-20).
 * - Improved error handling and logging for price updates (2025-06-20).
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
            jQuery.ajax({
                url: intersoccerCheckout.ajax_url,
                type: 'POST',
                data: {
                    action: 'intersoccer_get_days_of_week',
                    nonce: intersoccerCheckout.nonce,
                    product_id: productId,
                    variation_id: variationId
                },
                success: function(response) {
                    if (response.success && response.data.days_of_week) {
                        console.log('InterSoccer: Successfully fetched days of week:', response.data.days_of_week);
                        resolve(response.data.days_of_week);
                    } else {
                        console.error('InterSoccer: Failed to fetch days:', response.data ? response.data.message : 'Unknown error');
                        reject(new Error(response.data ? response.data.message : 'Failed to fetch days'));
                    }
                },
                error: function(xhr) {
                    console.error('InterSoccer: Failed to fetch days:', xhr.status, xhr.responseText);
                    reject(new Error('Failed to fetch days: ' + xhr.status));
                }
            });
        });
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
                start_date: response.data.start_date || "",
                total_weeks: parseInt(response.data.total_weeks, 10) || 0,
                weekly_discount: parseFloat(response.data.weekly_discount) || 0,
                remaining_weeks: parseInt(response.data.remaining_weeks, 10) || 0,
              });
            } else {
              console.error("InterSoccer: No metadata in response:", response.data.message);
              resolve({
                start_date: "",
                total_weeks: 0,
                weekly_discount: 0,
                remaining_weeks: 0,
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
                remaining_weeks: 0,
              });
            }
          },
        });
      });
    }

    function updateProductPrice(productId, variationId, campDays = [], remainingWeeks = null, siblingCount = 1) {
        return new Promise((resolve, reject) => {
            $.ajax({
                url: intersoccerCheckout.ajax_url,
                type: "POST",
                data: {
                    action: "intersoccer_calculate_dynamic_price",
                    nonce: intersoccerCheckout.nonce,
                    product_id: productId,
                    variation_id: variationId,
                    camp_days: campDays,
                    remaining_weeks: remainingWeeks,
                    sibling_count: siblingCount,
                },
                success: function (response) {
                    if (response.success && typeof response.data.price === 'number') {
                        const rawPrice = response.data.price;
                        console.log("InterSoccer: Updated product price raw:", rawPrice);
                        const $form = $('.variations_form.cart');
                        const $priceElement = $form.find(".woocommerce-variation-price .price");
                        if ($priceElement.length) {
                            const formattedPrice = typeof wc_price === "function"
                                ? wc_price(rawPrice)
                                : `CHF ${rawPrice.toFixed(2)}`;
                            $priceElement.html(formattedPrice);
                            // Force Elementor to refresh the price
                            if (typeof elementorFrontend !== 'undefined') {
                                elementorFrontend.hooks.doAction('refresh_preview', { action: 'refresh' });
                            }
                        } else {
                            console.warn("InterSoccer: Price element not found");
                        }
                        resolve(rawPrice);
                    } else {
                        console.error("InterSoccer: Failed to update price:", response.data.message || "No price data");
                        reject(new Error(response.data.message || "Invalid price data"));
                    }
                },
                error: function (xhr, status, error) {
                    console.error("InterSoccer: AJAX error updating price:", xhr.status, status, error, xhr.responseText);
                    reject(new Error("Failed to update price: " + status));
                },
            });
        });
    }

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

    if (typeof elementorFrontend !== "undefined") {
      elementorFrontend.on("init", function () {
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
      let selectedAttendees = new Set();

      function updateFormData(playerId, days, remainingWeeks = null, variationId = null) {
        $form.find('input[name="player_assignment"]').remove();
        $form.find('input[name="camp_days[]"]').remove();
        $form.find('input[name="remaining_weeks"]').remove();

        if (playerId) {
          $form.append(`<input type="hidden" name="player_assignment" value="${playerId}">`);
        }
        if (days && days.length) {
          days.forEach((day) => {
            $form.append(`<input type="hidden" name="camp_days[]" value="${day}">`);
          });
        }
        if (remainingWeeks !== null) {
          $form.append(`<input type="hidden" name="remaining_weeks" value="${remainingWeeks}">`);
        }
        console.log(
          "InterSoccer: Updated form data - Player:",
          playerId,
          "Days:",
          days,
          "Remaining weeks:",
          remainingWeeks,
          "Variation ID:",
          variationId
        );
      }

      function updateAddToCartButtonState(playerId, dayCount, bookingType) {
          const $addToCartButton = $form.find("button.single_add_to_cart_button");
          if (bookingType.toLowerCase() === "single-days" && dayCount > 0) {
              $addToCartButton.prop("disabled", false);
              console.log("InterSoccer: Enabled Add to Cart for single-day camp, days selected:", dayCount);
          } else if (playerId && (bookingType.toLowerCase() === "full-week" || bookingType.toLowerCase() === "")) {
              $addToCartButton.prop("disabled", false);
              console.log("InterSoccer: Enabled Add to Cart for full-week or default, player:", playerId);
          } else {
              $addToCartButton.prop("disabled", true);
              console.log("InterSoccer: Disabled Add to Cart, player:", playerId, "days:", dayCount, "bookingType:", bookingType);
          }
      }

      function renderCheckboxes(
        days,
        $dayCheckboxes,
        $dayNotification,
        $errorMessage,
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
              <label style="margin-right: 10px; display: inline-block;">
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
            updateFormData(playerId, selectedDays, null, variationId);

            clearTimeout(window.intersoccerUpdateTimeout);
            window.intersoccerUpdateTimeout = setTimeout(() => {
              $form.find('input[name="quantity"]').val(quantity);
              updateProductPrice(productId, variationId, selectedDays)
                .then(() => {
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

                  updateAddToCartButtonState(playerId, selectedDays.length, "single-days");
                  $dayCheckboxes.find('input[type="checkbox"]').prop("disabled", false);
                })
                .catch((error) => {
                  console.error("InterSoccer: Failed to update price after day selection:", error);
                });
            }, 100);

            isProcessing = false;
            isCheckboxUpdate = false;
          });
      }

      let variationTimeout;
     function handleVariation(variation, retryCount = 0, maxRetries = 12, forceRender = false) {
          if (isProcessing || isCheckboxUpdate) return;

          const bookingType = variation?.attributes?.attribute_pa_booking_type ||
              $form.find('select[name="attribute_pa_booking-type"]').val() ||
              $form.find('input[name="attribute_pa_booking-type"]').val() || "";
          const variationId = variation?.variation_id || 0;

          const bookingTypeChanged = lastBookingType !== bookingType;
          if (variationId === lastVariationId && !forceRender && !bookingTypeChanged && retryCount === 0) {
              console.log("InterSoccer: Skipping duplicate variation handling for ID:", variationId);
              return;
          }

          isProcessing = true;

          console.log("InterSoccer: handleVariation triggered for variation ID:", variationId, "Retry:", retryCount, "Force:", forceRender, "Booking Type Changed:", bookingTypeChanged);

          clearTimeout(variationTimeout);
          variationTimeout = setTimeout(() => {
              if (!variationId) {
                  console.error("InterSoccer: Invalid variation ID:", variationId);
                  isProcessing = false;
                  return;
              }
              console.log("InterSoccer: Booking type:", bookingType);

              currentVariation = variation;
              lastVariationId = variationId;
              lastBookingType = bookingType;

              const $addToCartButton = $form.find("button.single_add_to_cart_button");
              const $daySelection = $form.find(".intersoccer-day-selection");
              const $dayCheckboxes = $form.find(".intersoccer-day-checkboxes");
              const $dayNotification = $form.find(".intersoccer-day-notification");
              const $errorMessage = $form.find(".intersoccer-day-selection .error-message");

              $daySelection.css({ "display": "block !important", "margin-top": "10px" });
              $addToCartButton.prop("disabled", true);

              if (productType === "camp" && bookingType.toLowerCase() === "single-days") {
                  if (!$daySelection.length && retryCount < maxRetries) {
                      setTimeout(() => handleVariation(variation, retryCount + 1, maxRetries, forceRender), 3500);
                      isProcessing = false;
                      return;
                  }
                  $daySelection.show();
                  fetchDaysOfWeek(productId, variationId).then((days) => {
                      availableDays = days;
                      renderCheckboxes(days, $dayCheckboxes, $dayNotification, $errorMessage, "", variationId);
                      selectedDays = $dayCheckboxes.find('input[type="checkbox"]:checked').map(function() { return $(this).val(); }).get();
                      updateFormData("", selectedDays, null, variationId);
                      updateAddToCartButtonState("", selectedDays.length, "single-days");
                      updateProductPrice(productId, variationId, selectedDays, null, 1);
                  }).catch((error) => {
                      console.error("InterSoccer: Failed to load days:", error);
                      $daySelection.hide();
                      $errorMessage.text("Failed to load days.").show().delay(5000).fadeOut();
                  });
              } else if (productType === "course") {
                  $daySelection.hide();
                  fetchCourseMetadata(productId, variationId).then((metadata) => {
                      const remainingWeeks = metadata.remaining_weeks || null;
                      updateFormData("", [], remainingWeeks, variationId);
                      updateAddToCartButtonState("", 0, bookingType);
                      updateProductPrice(productId, variationId, [], remainingWeeks, 1);
                  }).catch((error) => {
                      console.error("InterSoccer: Failed to fetch course metadata:", error);
                      updateFormData("", [], null, variationId);
                      updateAddToCartButtonState("", 0, bookingType);
                  });
              } else if (productType === "camp" && bookingType.toLowerCase() === "full-week") {
                  $daySelection.hide();
                  updateFormData("", [], null, variationId);
                  $form.find('input[name="quantity"]').val(1);
                  updateAddToCartButtonState("", 0, bookingType);
                  updateProductPrice(productId, variationId, null, null, 1);
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
        selectedDays = [];
        currentVariationId = null;
        lastBookingType = null;
        selectedAttendees.clear();
        const $daySelection = $form.find(".intersoccer-day-selection");
        $daySelection.hide();
        if (currentVariation) {
          setTimeout(() => {
            console.log("InterSoccer: Re-rendering after form event");
            handleVariation(currentVariation);
            isVariationEventProcessed = false;
          }, 600);
        } else {
          isVariationEventProcessed = false;
        }
      });

      $form
        .find('select[name="attribute_pa_booking-type"], input[name="attribute_pa_booking-type"]')
        .on("change", function () {
          console.log("InterSoccer: Booking type changed, triggering check_variations");
          $form.trigger("check_variations");
        });

      let playerChangeTimeout;
      $form.find(".player-select").on("change", function () {
        const playerId = $(this).val() || "";
        const bookingType =
          $form.find('select[name="attribute_pa_booking-type"]').val() ||
          $form.find('input[name="attribute_pa_booking-type"]').val();
        const selectedDaysCount = selectedDays.length;

        console.log(
          "InterSoccer: Player selected:",
          playerId,
          "Booking type:",
          bookingType,
          "Current variation:",
          currentVariation?.variation_id
        );

        if (playerId && !selectedAttendees.has(playerId)) {
          selectedAttendees.add(playerId);
        } else if (!playerId && selectedAttendees.has(lastValidPlayerId)) {
          selectedAttendees.delete(lastValidPlayerId);
        }

        const siblingCount = selectedAttendees.size;
        const remainingWeeks = $form.find('input[name="remaining_weeks"]').val();
        updateFormData(playerId, selectedDays, remainingWeeks, currentVariation?.variation_id);

        clearTimeout(playerChangeTimeout);
        playerChangeTimeout = setTimeout(() => {
          if (!currentVariation) {
            console.log("InterSoccer: No current variation, re-triggering check_variations");
            $form.trigger("check_variations");
          }

          updateAddToCartButtonState(playerId, selectedDaysCount, bookingType);

          if (currentVariation) {
            const variationId = currentVariation.variation_id;
            updateProductPrice(productId, variationId, selectedDays, remainingWeeks, siblingCount)
              .catch((error) => {
                console.error("InterSoccer: Failed to update price after player selection:", error);
              });
          }
        }, 600);
      });

      $(document).on("click", "button.single_add_to_cart_button, .elementor-add-to-cart button", function (e) {
        const $button = $(this);
        console.log("InterSoccer: Add to Cart button clicked, class:", $button.attr("class"));
        if ($button.hasClass("buy-now")) {
          console.log("InterSoccer: Buy Now button clicked");
          const playerId = $form.find(".player-select").val() || lastValidPlayerId;
          const remainingWeeks = $form.find('input[name="remaining_weeks"]').val();
          const siblingCount = selectedAttendees.size || (playerId ? 1 : 0);
          updateFormData(playerId, selectedDays, remainingWeeks, currentVariation?.variation_id);
          $form.append('<input type="hidden" name="buy_now" value="1">');
          updateProductPrice(productId, currentVariation?.variation_id, selectedDays, remainingWeeks, siblingCount);
        }
      });

      $form.on("adding_to_cart", function (event, $button, data) {
        console.log("InterSoccer: adding_to_cart event triggered");
        const playerId = $form.find(".player-select").val() || lastValidPlayerId;
        const selectedDays = $form.find('input[name="camp_days[]"]:checked').map(function() {
            return $(this).val();
        }).get();
        const remainingWeeks = $form.find('input[name="remaining_weeks"]').val();
        const siblingCount = selectedAttendees.size || (playerId ? 1 : 0);
        if (playerId) data.player_assignment = playerId;
        if (selectedDays.length) data.camp_days = selectedDays;
        if (remainingWeeks !== undefined && remainingWeeks !== null) data.remaining_weeks = remainingWeeks;
        data.sibling_count = siblingCount;
        console.log(
            "InterSoccer: Adding to cart - Player:",
            playerId,
            "Days:",
            selectedDays,
            "Remaining weeks:",
            remainingWeeks,
            "Sibling Count:",
            siblingCount
        );
    });

      $form.on("adding_to_cart", function (event, $button, data) {
        console.log("InterSoccer: adding_to_cart event triggered");
        const playerId = $form.find(".player-select").val() || lastValidPlayerId;
        const remainingWeeks = $form.find('input[name="remaining_weeks"]').val();
        const siblingCount = selectedAttendees.size || (playerId ? 1 : 0);
        if (playerId) data.player_assignment = playerId;
        if (selectedDays.length) data.camp_days = selectedDays;
        if (remainingWeeks !== undefined && remainingWeeks !== null) data.remaining_weeks = remainingWeeks;
        data.sibling_count = siblingCount;
        console.log(
          "InterSoccer: Adding to cart - Player:",
          playerId,
          "Days:",
          selectedDays,
          "Remaining weeks:",
          remainingWeeks,
          "Sibling Count:",
          siblingCount
        );
      });

      $form.trigger("check_variations");
      console.log("InterSoccer: Triggered check_variations on form load");

      setTimeout(() => {
        console.log("InterSoccer: Re-triggering check_variations after delay");
        $form.trigger("check_variations");
      }, 2000);
    }
  }

  setTimeout(initializePlugin, 2000);

  document.addEventListener("DOMContentLoaded", initializePlugin);
  document.addEventListener("load", initializePlugin);
});
