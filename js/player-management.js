/**
 * File: player-management.js
 * Description: Manages the player management table on the WooCommerce My Account page at /my-account/manage-players/. Displays players in a spreadsheet-like table with an 'Add Attendee' row. Edit toggles to Cancel/Delete with a hidden Medical Conditions row. Fixed AJAX issues.
 * Dependencies: jQuery (checked), Flatpickr (optional with fallback)
 * Changes:
 * - Removed Region validation (2025-05-16).
 * - Added hidden Medical Conditions row on Edit (2025-05-16).
 * - Fixed AJAX 403 errors and delete loop (2025-05-16).
 * Testing:
 * - Verify script loads (Network tab).
 * - Save new player, confirm new row.
 * - Edit player, toggle Medical Conditions row, save changes.
 * - Delete player, no 403 or loop.
 * - Disable jQuery/Flatpickr, ensure fallback.
 */

(function ($) {
  // Dependency checks
  if (typeof $ === "undefined") {
    console.error(
      "InterSoccer: jQuery is not loaded. Player management disabled."
    );
    return;
  }
  if (
    !window.intersoccerPlayer ||
    !intersoccerPlayer.ajax_url ||
    !intersoccerPlayer.nonce
  ) {
    console.error(
      "InterSoccer: intersoccerPlayer data not initialized. Player management disabled."
    );
    return;
  }

  // Check for container
  const $container = $(".intersoccer-player-management");
  if (!$container.length) {
    console.error(
      "InterSoccer: Player management container (.intersoccer-player-management) not found."
    );
    return;
  }

  const $table = $("#player-table");
  const $message = $container.find(".intersoccer-message");
  let isProcessing = false;
  let editingIndex = null;

  // Initialize Flatpickr with fallback
  function initFlatpickr($input) {
    if (typeof flatpickr === "undefined") {
      console.warn(
        "InterSoccer: Flatpickr not loaded. Using plain text input for DOB."
      );
      $input.removeClass("date-picker").attr("type", "text");
      return;
    }
    console.log("InterSoccer: Initializing Flatpickr for:", $input.attr("id"));
    $input.flatpickr({
      dateFormat: "Y-m-d",
      maxDate: "today",
      minDate: new Date().setFullYear(new Date().getFullYear() - 13), // Min age 2, max 13
      onOpen: function () {
        $input.closest("td").find(".error-message").hide();
      },
    });
  }
  initFlatpickr($table.find(".add-player-row #player_dob"));

  // Validation
  function validateRow($row) {
    console.log("InterSoccer: Validating row");
    let isValid = true;
    $row.find(".error-message").hide();
    const $medicalRow = $row.next(".medical-row");

    const $firstName = $row.find('[name="player_first_name"]');
    const $lastName = $row.find('[name="player_last_name"]');
    const $dob = $row.find('[name="player_dob"]');
    const $gender = $row.find('[name="player_gender"]');
    const $medical =
      $medicalRow.find('[name="player_medical"]') ||
      $row.find('[name="player_medical"]');

    const firstName = $firstName.val().trim();
    const lastName = $lastName.val().trim();
    const dob = $dob.val();
    const gender = $gender.val();
    const medical = $medical.val().trim();

    if (
      !firstName ||
      firstName.length > 50 ||
      !/^[a-zA-Z\s-]+$/.test(firstName)
    ) {
      $firstName
        .next(".error-message")
        .text("Valid first name required (max 50 chars, letters only).")
        .show();
      isValid = false;
    }
    if (!lastName || lastName.length > 50 || !/^[a-zA-Z\s-]+$/.test(lastName)) {
      $lastName
        .next(".error-message")
        .text("Valid last name required (max 50 chars, letters only).")
        .show();
      isValid = false;
    }
    if (!dob || !/^\d{4}-\d{2}-\d{2}$/.test(dob)) {
      $dob
        .next(".error-message")
        .text("Valid date required (YYYY-MM-DD).")
        .show();
      isValid = false;
    } else {
      const dobDate = new Date(dob);
      const today = new Date("2025-05-16");
      const age =
        today.getFullYear() -
        dobDate.getFullYear() -
        (today.getMonth() < dobDate.getMonth() ||
        (today.getMonth() === dobDate.getMonth() &&
          today.getDate() < dobDate.getDate())
          ? 1
          : 0);
      if (age < 2 || age > 13) {
        $dob
          .next(".error-message")
          .text("Player must be 2-13 years old.")
          .show();
        isValid = false;
      }
    }
    if (!gender) {
      $gender.next(".error-message").text("Gender required.").show();
      isValid = false;
    }
    if (medical.length > 500) {
      $medical
        .next(".error-message")
        .text("Medical conditions must be under 500 chars.")
        .show();
      isValid = false;
    }

    console.log("InterSoccer: Row validation result:", isValid);
    return isValid;
  }

  // Refresh nonce (one retry)
  function refreshNonce() {
    console.log("InterSoccer: Refreshing nonce");
    return new Promise((resolve, reject) => {
      $.ajax({
        url: intersoccerPlayer.nonce_refresh_url,
        type: "POST",
        data: { action: "intersoccer_refresh_nonce" },
        success: function (response) {
          if (response.success && response.data.nonce) {
            intersoccerPlayer.nonce = response.data.nonce;
            console.log(
              "InterSoccer: Nonce refreshed:",
              intersoccerPlayer.nonce
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

  // Save player (add or edit)
  function savePlayer($row, isAdd = false) {
    if (isProcessing) {
      console.log("InterSoccer: Processing, ignoring save");
      return;
    }
    if (!validateRow($row)) {
      console.log("InterSoccer: Validation failed");
      return;
    }

    isProcessing = true;
    const $submitLink = $row.find(".player-submit");
    $submitLink.find(".spinner").show();

    const index = isAdd ? "-1" : $row.data("player-index");
    const $medicalRow = $row.next(".medical-row");
    const firstName = $row.find('[name="player_first_name"]').val().trim();
    const lastName = $row.find('[name="player_last_name"]').val().trim();
    const dob = $row.find('[name="player_dob"]').val();
    const gender = $row.find('[name="player_gender"]').val();
    const medical = (
      $medicalRow.find('[name="player_medical"]') ||
      $row.find('[name="player_medical"]')
    )
      .val()
      .trim();

    const action = isAdd ? "intersoccer_add_player" : "intersoccer_edit_player";
    const data = {
      action: action,
      nonce: intersoccerPlayer.nonce,
      user_id: intersoccerPlayer.user_id,
      player_first_name: firstName,
      player_last_name: lastName,
      player_dob: dob,
      player_gender: gender,
      player_medical: medical,
    };
    if (!isAdd) {
      data.player_index = index;
    }

    console.log("InterSoccer: Sending AJAX request:", action, data);

    $.ajax({
      url: intersoccerPlayer.ajax_url,
      type: "POST",
      data: data,
      success: function (response) {
        console.log("InterSoccer: AJAX success:", response);
        if (response.success) {
          $message.text(response.data.message).show();
          setTimeout(() => $message.hide(), 10000);
          const player = response.data.player;

          if (isAdd) {
            // Add new row
            $table.find(".no-players").remove();
            const newIndex = $table.find("tr[data-player-index]").length;
            const $newRow = $(`
                          <tr data-player-index="${newIndex}">
                              <td class="display-first-name">${
                                player.first_name || "N/A"
                              }</td>
                              <td class="display-last-name">${
                                player.last_name || "N/A"
                              }</td>
                              <td class="display-dob">${
                                player.dob || "N/A"
                              }</td>
                              <td class="display-gender">${
                                player.gender || "N/A"
                              }</td>
                              <td class="actions">
                                  <a href="#" class="edit-player" data-index="${newIndex}" aria-label="Edit player ${
              player.first_name || ""
            }" aria-expanded="false">
                                      Edit
                                  </a>
                              </td>
                          </tr>
                      `);
            $table.find(".add-player-row").before($newRow);
            $row.find("input, select, textarea").val("");
          } else {
            // Update existing row
            $row.find(".display-first-name").text(player.first_name || "N/A");
            $row.find(".display-last-name").text(player.last_name || "N/A");
            $row.find(".display-dob").text(player.dob || "N/A");
            $row.find(".display-gender").text(player.gender || "N/A");
            $row.find(".actions").html(`
                          <a href="#" class="edit-player" data-index="${index}" aria-label="Edit player ${
              player.first_name || ""
            }" aria-expanded="false">
                              Edit
                          </a>
                      `);
            $medicalRow.remove();
            editingIndex = null;
            $table
              .find(".edit-player")
              .removeClass("disabled")
              .attr("aria-disabled", "false");
          }
        } else {
          $message
            .text(response.data.message || "Failed to save player.")
            .show();
          setTimeout(() => $message.hide(), 10000);
        }
      },
      error: function (xhr) {
        console.error("InterSoccer: AJAX error:", xhr.status, xhr.responseText);
        if (xhr.status === 403) {
          console.log(
            "InterSoccer: 403 error, attempting nonce refresh (once)"
          );
          refreshNonce()
            .then(() => {
              data.nonce = intersoccerPlayer.nonce;
              $.ajax(this);
            })
            .catch(() => {
              $message.text("Error: Failed to refresh security token.").show();
              setTimeout(() => $message.hide(), 10000);
            });
        } else {
          $message.text("Error: Unable to save player.").show();
          setTimeout(() => $message.hide(), 10000);
        }
      },
      complete: function () {
        console.log("InterSoccer: AJAX complete");
        isProcessing = false;
        $submitLink.find(".spinner").hide();
      },
    });
  }

  // Edit player
  $table.on("click", ".edit-player", function (e) {
    e.preventDefault();
    if (isProcessing || editingIndex !== null) {
      console.log(
        "InterSoccer: Edit ignored, processing or another row being edited"
      );
      return;
    }

    const index = $(this).data("index");
    console.log("InterSoccer: Edit Player clicked, index:", index);
    editingIndex = index;

    const $row = $table.find(`tr[data-player-index="${index}"]`);
    const firstName = $row.find(".display-first-name").text();
    const lastName = $row.find(".display-last-name").text();
    const dob = $row.find(".display-dob").text();
    const gender = $row.find(".display-gender").text();
    const medical =
      (
        $row.next(".medical-row").find('[name="player_medical"]') ||
        $row.find(".display-medical")
      ).text() || "None";

    $row.find("td").eq(0).html(`
          <input type="text" name="player_first_name" value="${
            firstName === "N/A" ? "" : firstName
          }" required aria-required="true" maxlength="50">
          <span class="error-message" style="display: none;"></span>
      `);
    $row.find("td").eq(1).html(`
          <input type="text" name="player_last_name" value="${
            lastName === "N/A" ? "" : lastName
          }" required aria-required="true" maxlength="50">
          <span class="error-message" style="display: none;"></span>
      `);
    $row.find("td").eq(2).html(`
          <input type="text" name="player_dob" class="date-picker chloe-brooks-date-picker" value="${
            dob === "N/A" ? "" : dob
          }" placeholder="YYYY-MM-DD" required aria-required="true">
          <span class="error-message" style="display: none;"></span>
      `);
    $row.find("td").eq(3).html(`
          <select name="player_gender" required aria-required="true">
              <option value="">Select Gender</option>
              <option value="male" ${
                gender === "male" ? "selected" : ""
              }>Male</option>
              <option value="female" ${
                gender === "female" ? "selected" : ""
              }>Female</option>
              <option value="other" ${
                gender === "other" ? "selected" : ""
              }>Other</option>
          </select>
          <span class="error-message" style="display: none;"></span>
      `);
    $row.find(".actions").html(`
          <a href="#" class="player-submit" aria-label="Save Player">Save</a> /
          <a href="#" class="cancel-edit" aria-label="Cancel Edit">Cancel</a> /
          <a href="#" class="delete-player" aria-label="Delete player ${
            firstName || ""
          }">Delete</a>
      `);

    // Insert hidden Medical Conditions row
    const $medicalRow = $(`
          <tr class="medical-row active" data-player-index="${index}">
              <td colspan="5">
                  <label for="player_medical_${index}">Medical Conditions:</label>
                  <textarea id="player_medical_${index}" name="player_medical" maxlength="500" aria-describedby="medical-instructions-${index}">${
      medical === "None" ? "" : medical
    }</textarea>
                  <span id="medical-instructions-${index}" class="screen-reader-text">Optional field for medical conditions.</span>
                  <span class="error-message" style="display: none;"></span>
              </td>
          </tr>
      `);
    $row.after($medicalRow);

    initFlatpickr($row.find('[name="player_dob"]'));
    $table
      .find(".edit-player")
      .not(this)
      .addClass("disabled")
      .attr("aria-disabled", "true");
    $(this).attr("aria-expanded", "true");
  });

  // Save player (edit or add)
  $table.on("click", ".player-submit", function (e) {
    e.preventDefault();
    const $row = $(this).closest("tr");
    const isAdd = $row.hasClass("add-player-row");
    console.log("InterSoccer: Save Player clicked, isAdd:", isAdd);
    savePlayer($row, isAdd);
  });

  // Cancel edit
  $table.on("click", ".cancel-edit", function (e) {
    e.preventDefault();
    console.log("InterSoccer: Cancel Edit clicked");
    const $row = $(this).closest("tr");
    const index = $row.data("player-index");

    const firstName = $row.find(".display-first-name").text();
    const lastName = $row.find(".display-last-name").text();
    const dob = $row.find(".display-dob").text();
    const gender = $row.find(".display-gender").text();

    $row
      .find("td")
      .eq(0)
      .html(`<span class="display-first-name">${firstName}</span>`);
    $row
      .find("td")
      .eq(1)
      .html(`<span class="display-last-name">${lastName}</span>`);
    $row.find("td").eq(2).html(`<span class="display-dob">${dob}</span>`);
    $row.find("td").eq(3).html(`<span class="display-gender">${gender}</span>`);
    $row.find(".actions").html(`
          <a href="#" class="edit-player" data-index="${index}" aria-label="Edit player ${firstName || ""}" aria-expanded="false">
              Edit
          </a>
      `);
    $row.next(".medical-row").remove();

    editingIndex = null;
    $table
      .find(".edit-player")
      .removeClass("disabled")
      .attr("aria-disabled", "false");
  });

  // Delete player
  $table.on("click", ".delete-player", function (e) {
    e.preventDefault();
    console.log("InterSoccer: Delete Player clicked");
    if (isProcessing) {
      console.log("InterSoccer: Processing, ignoring click");
      return;
    }
    const $row = $(this).closest("tr");
    const index = $row.data("player-index");
    if (!confirm("Are you sure you want to delete this player?")) {
      return;
    }

    isProcessing = true;
    $.ajax({
      url: intersoccerPlayer.ajax_url,
      type: "POST",
      data: {
        action: "intersoccer_delete_player",
        nonce: intersoccerPlayer.nonce,
        user_id: intersoccerPlayer.user_id,
        player_index: index,
      },
      success: function (response) {
        console.log("InterSoccer: Delete AJAX success:", response);
        if (response.success) {
          $message.text(response.data.message).show();
          setTimeout(() => $message.hide(), 10000);
          $row.remove();
          $row.next(".medical-row").remove();
          if (!$table.find("tr[data-player-index]").length) {
            $table.find(".no-players").remove();
            $table
              .find(".add-player-row")
              .before(
                '<tr class="no-players"><td colspan="5">No attendees added yet.</td></tr>'
              );
          }
          editingIndex = null;
          $table
            .find(".edit-player")
            .removeClass("disabled")
            .attr("aria-disabled", "false");
        } else {
          $message
            .text(response.data.message || "Failed to delete player.")
            .show();
          setTimeout(() => $message.hide(), 10000);
        }
      },
      error: function (xhr) {
        console.error(
          "InterSoccer: Delete AJAX error:",
          xhr.status,
          xhr.responseText
        );
        if (xhr.status === 403) {
          console.log(
            "InterSoccer: 403 error, attempting nonce refresh (once)"
          );
          refreshNonce()
            .then(() => {
              data.nonce = intersoccerPlayer.nonce;
              $.ajax(this);
            })
            .catch(() => {
              $message.text("Error: Failed to refresh security token.").show();
              setTimeout(() => $message.hide(), 10000);
            });
        } else {
          $message.text("Error deleting player.").show();
          setTimeout(() => $message.hide(), 10000);
        }
      },
      complete: function () {
        console.log("InterSoccer: Delete AJAX complete");
        isProcessing = false;
      },
    });
  });

  console.log("InterSoccer: player-management.js fully loaded");
})(jQuery);
