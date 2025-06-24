jQuery(document).ready(function ($) {
  // Ensure Select2 is available (for legacy support)
  if (typeof $.fn.select2 === "undefined") {
    console.error("Select2 is not loaded. Check enqueue in woocommerce-modifications.php.");
    return;
  }

  // Initialize Select2 for camp day selection (if applicable)
  $(".camp-day-select").each(function () {
    const $select = $(this);
    try {
      $select.select2({
        placeholder: __("Select days", "intersoccer-player-management"),
        allowClear: true,
        width: "100%",
      });
      console.log("Select2 initialized for camp day select:", $select.attr("id"));
    } catch (e) {
      console.error("Failed to initialize Select2 for camp day select:", e);
    }
  });

  // Ensure day selection is required before adding to cart
  $("form.cart").on("submit", function (e) {
    const $daySelect = $(this).find(".camp-day-select");
    const $checkboxes = $(this).find(".intersoccer-day-checkboxes input[type='checkbox']:checked");
    const bookingType = $(this).find('select[name="attribute_pa_booking-type"]').val() || $(this).find('input[name="attribute_pa_booking-type"]').val();

    if ($daySelect.length && !$daySelect.val() && bookingType !== "single-days") {
      e.preventDefault();
      alert(__("Please select at least one day for the camp.", "intersoccer-player-management"));
      console.log("Form submission blocked: No days selected in dropdown");
    } else if (bookingType === "single-days" && $checkboxes.length === 0) {
      e.preventDefault();
      alert(__("Please select at least one day for the camp.", "intersoccer-player-management"));
      console.log("Form submission blocked: No checkbox days selected");
    } else {
      console.log("Form submission allowed with selected days:", $checkboxes.map(function() { return $(this).val(); }).get());
    }
  });
});
