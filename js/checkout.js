/**
 * Checkout JavaScript for Player Assignment
 */

jQuery(document).ready(function ($) {
  // Ensure dropdowns are clickable
  $(".player-select").each(function () {
    $(this)
      .off("click")
      .on("click", function (e) {
        e.stopPropagation();
      });
  });
});

