/**
 * File: variation-details.js
 * Description: Manages day selection and dynamic price updates for camps, and pro-rated pricing for courses on WooCommerce product pages.
 * Dependencies: jQuery
 * Author: Jeremy Lee
 * Changes (Summarized):
 * - Initial implementation with day selection, price updates, and player management (2025-05-26).
 * - Enhanced with MutationObserver, Elementor support, and improved logging (2025-05-26).
 * - Fixed price calculations, removed client-side price storage, and improved security (2025-05-27).
 * - Added dynamic price updates via AJAX, fixed button enabling, and resolved infinite loops (2025-05-27).
 * - Ensured days of week display for Camps and fixed player toggle issues (2025-05-27).
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

    function updateProductPrice(productId, variationId, campDays = [], remainingWeeks = null) {
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
          },
          success: function (response) {
            if (response.success && response.data.price) {
              console.log("InterSoccer: Updated product price:", response.data.price);
              // Update the displayed price
              const $priceElement = $("form.cart .woocommerce-variation-price .price");
              if ($priceElement.length) {
                $priceElement.html(response.data.price);
              } else {
                console.warn("InterSoccer: Price element not found");
              }
              // Update any subtotal field if it exists (e.g., price × quantity)
              const $subtotalElement = $("form.cart .single_variation_wrap .price-subtotal");
              if ($subtotalElement.length) {
                $subtotalElement.html(response.data.price); // Quantity is 1, so price = subtotal
              }
              resolve(response.data.raw_price);
            } else {
              console.error("InterSoccer: Failed to update price:", response.data.message);
              reject(new Error(response.data.message || "Failed to update price"));
            }
          },
          error: function (xhr) {
            console.error("InterSoccer: AJAX error updating price:", xhr.status, xhr.responseText);
            if (xhr.status === 403) {
              refreshNonce()
                .then(() => updateProductPrice(productId, variationId, campDays, remainingWeeks))
                .then(resolve)
                .catch(reject);
            } else {
              reject(new Error("Failed to update price: " + xhr.statusText));
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
      let lastBookingType = null; // Track the last booking type to detect changes
      let lastValidPlayerId = ""; // Track the last valid player ID to prevent button disabling

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
      let currentPlayerId = ""; // Store the current player ID persistently

      function updateFormData(playerId, days, remainingWeeks = null, variationId = null) {
        // Remove all custom hidden fields
        $form.find('input[name="player_assignment"]').remove();
        $form.find('input[name="camp_days[]"]').remove();
        $form.find('input[name="remaining_weeks"]').remove();

        // Add necessary data for server-side price calculation
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

      function updateAddToCartButtonState(playerId, selectedDaysCount, bookingType) {
        const $addToCartButton = $form.find("button.single_add_to_cart_button");
        const hasPlayer = playerId && playerId !== "";
        let isValid = false;

        // Update last valid player ID if a valid player is selected
        if (hasPlayer) {
          lastValidPlayerId = playerId;
        }

        // Use the last valid player ID if the current selection is empty
        const effectivePlayerId = hasPlayer ? playerId : lastValidPlayerId;
        const effectiveHasPlayer = effectivePlayerId && effectivePlayerId !== "";

        if (bookingType === "single-days") {
          // For single-days, require either a player or at least one day selected
          isValid = effectiveHasPlayer || selectedDaysCount > 0;
          $addToCartButton.prop("disabled", !isValid);
          console.log(
            "InterSoccer: Updated button state - Current Player:",
            playerId,
            "Effective Player:",
            effectivePlayerId,
            "Has Player (effective):",
            effectiveHasPlayer,
            "Days selected:",
            selectedDaysCount,
            "Enabled:",
            isValid
          );
        } else {
          isValid = effectiveHasPlayer;
          $addToCartButton.prop("disabled", !isValid);
          console.log(
            "InterSoccer: Updated button state - Current Player:",
            playerId,
            "Effective Player:",
            effectivePlayerId,
            "Has Player (effective):",
            effectiveHasPlayer,
            "Enabled:",
            isValid
          );
          if (isValid) {
            $addToCartButton.removeClass('disabled');
          }
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
              // Update price dynamically
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

        const bookingType =
          variation?.attributes?.attribute_pa_booking_type ||
          $form.find('select[name="attribute_pa_booking-type"]').val() ||
          $form.find('input[name="attribute_pa_booking-type"]').val() ||
          "";
        const variationId = variation?.variation_id || 0;

        // Force render if booking type has changed, even if variation ID is the same
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

          currentVariation = variation; // Store current variation
          lastVariationId = variationId;
          lastBookingType = bookingType; // Update last booking type

          const $addToCartButton = $form.find("button.single_add_to_cart_button");
          const $daySelection = $form.find(".intersoccer-day-selection");
          const $dayCheckboxes = $form.find(".intersoccer-day-checkboxes");
          const $dayNotification = $form.find(".intersoccer-day-notification");
          const $errorMessage = $form.find(".intersoccer-day-selection .error-message");

          // Ensure day selection is visible with !important to override theme styles
          $daySelection.css({ 'display': 'block !important', 'margin-top': '10px' });

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
                const playerId = $form.find(".player-select").val() || "";
                currentPlayerId = playerId; // Update the current player ID
                renderCheckboxes(
                  days,
                  $dayCheckboxes,
                  $dayNotification,
                  $errorMessage,
                  playerId,
                  variationId
                );
                currentVariationId = variationId;

                const quantity = 1;
                $form.find('input[name="quantity"]').val(quantity);
                updateAddToCartButtonState(playerId, selectedDays.length, "single-days");

                // Initial price update for Camps
                updateProductPrice(productId, variationId, selectedDays)
                  .catch((error) => {
                    console.error("InterSoccer: Failed to update initial price for Camp:", error);
                  });
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
                  const remainingWeeks = metadata.remaining_weeks || null;
                  const playerId = $form.find(".player-select").val() || "";
                  currentPlayerId = playerId; // Update the current player ID
                  updateFormData(playerId, [], remainingWeeks, variationId);

                  updateAddToCartButtonState(playerId, 0, bookingType);

                  // Update price for Courses
                  updateProductPrice(productId, variationId, [], remainingWeeks)
                    .catch((error) => {
                      console.error("InterSoccer: Failed to update price for Course:", error);
                    });
                })
                .catch((error) => {
                  console.error("InterSoccer: Failed to fetch course metadata:", error);
                  const playerId = $form.find(".player-select").val() || "";
                  currentPlayerId = playerId; // Update the current player ID
                  updateFormData(playerId, [], null, variationId);
                  updateAddToCartButtonState(playerId, 0, bookingType);
                });
            } else if (productType === "camp" && bookingType.toLowerCase() === "full-week") {
              const playerId = $form.find(".player-select").val() || "";
              currentPlayerId = playerId; // Update the current player ID
              updateFormData(playerId, [], null, variationId);
              $form.find('input[name="quantity"]').val(1);
              updateAddToCartButtonState(playerId, 0, bookingType);

              // Update price for Full Week Camps
              updateProductPrice(productId, variationId)
                .catch((error) => {
                  console.error("InterSoccer: Failed to update price for Full Week Camp:", error);
                });
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
        selectedDays = []; // Clear selected days on reset
        currentVariationId = null; // Reset current variation ID
        lastBookingType = null; // Reset booking type to force re-render
        const $daySelection = $form.find(".intersoccer-day-selection");
        $daySelection.hide(); // Ensure day selection is hidden on reset
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

      // Trigger variation check on booking type change
      $form.find('select[name="attribute_pa_booking-type"], input[name="attribute_pa_booking-type"]').on("change", function () {
        console.log("InterSoccer: Booking type changed, triggering check_variations");
        $form.trigger("check_variations");
      });

      // Debounce player selection updates
      let playerChangeTimeout;
      $form.find(".player-select").on("change", function () {
        const playerId = $(this).val() || "";
        currentPlayerId = playerId; // Update the current player ID
        const bookingType = $form.find('select[name="attribute_pa_booking-type"]').val() || $form.find('input[name="attribute_pa_booking-type"]').val();
        const selectedDaysCount = selectedDays.length;

        console.log("InterSoccer: Player selected:", playerId, "Booking type:", bookingType, "Current variation:", currentVariation?.variation_id);

        const remainingWeeks = $form.find('input[name="remaining_weeks"]').val();
        updateFormData(playerId, selectedDays, remainingWeeks, currentVariation?.variation_id);

        clearTimeout(playerChangeTimeout);
        playerChangeTimeout = setTimeout(() => {
          if (!currentVariation) {
            console.log("InterSoccer: No current variation, re-triggering check_variations");
            $form.trigger("check_variations");
          }

          updateAddToCartButtonState(playerId, selectedDaysCount, bookingType);

          // Update price after player selection if necessary
          if (currentVariation) {
            const variationId = currentVariation.variation_id;
            updateProductPrice(productId, variationId, selectedDays, remainingWeeks)
              .catch((error) => {
                console.error("InterSoccer: Failed to update price after player selection:", error);
              });
          }
        }, 600);
      });

      // Handle add-to-cart button click
      $(document).on("click", "button.single_add_to_cart_button, .elementor-add-to-cart button", function (e) {
        const $button = $(this);
        console.log("InterSoccer: Add to Cart button clicked, class:", $button.attr('class'));
        if ($button.hasClass("buy-now")) {
          console.log("InterSoccer: Buy Now button clicked");
          const playerId = $form.find(".player-select").val() || lastValidPlayerId;
          const remainingWeeks = $form.find('input[name="remaining_weeks"]').val();
          updateFormData(playerId, selectedDays, remainingWeeks, currentVariation?.variation_id);
          $form.append('<input type="hidden" name="buy_now" value="1">');
        }
      });

      // Handle form submission
      $form.on("submit", function (e) {
        console.log("InterSoccer: Form submitted");
        const playerId = $form.find(".player-select").val() || lastValidPlayerId;
        const remainingWeeks = $form.find('input[name="remaining_weeks"]').val();
        console.log(
          "InterSoccer: Adding to cart via submit - Player:",
          playerId,
          "Days:",
          selectedDays,
          "Remaining weeks:",
          remainingWeeks
        );
      });

      // Handle adding_to_cart event
      $form.on("adding_to_cart", function (event, $button, data) {
        console.log("InterSoccer: adding_to_cart event triggered");
        const playerId = $form.find(".player-select").val() || lastValidPlayerId;
        const remainingWeeks = $form.find('input[name="remaining_weeks"]').val();
        if (playerId) data.player_assignment = playerId;
        if (selectedDays.length) data.camp_days = selectedDays;
        if (remainingWeeks !== undefined && remainingWeeks !== null) data.remaining_weeks = remainingWeeks;
        console.log(
          "InterSoccer: Adding to cart - Player:",
          playerId,
          "Days:",
          selectedDays,
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
  document.addEventListener('load', initializePlugin);
});
