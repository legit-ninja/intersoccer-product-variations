/**
 * InterSoccer Program Manager — admin JS
 *
 * Handles wizard navigation, AJAX calls, inline price editing, and duplicate flow.
 */
(function($) {
	'use strict';

	var PM = window.intersoccerPM || {};
	var Matrix = window.intersoccerPMMatrix || {};
	/** Snapshot of selected matrix rows (Step 3 checkboxes are hidden on Step 4). */
	var lastMatrixRows = [];
	/** Snapshot of parent attrs for selected type (Step 2 rows hidden on Step 4). */
	var lastParentAttrs = {};

	// =========================================================================
	// Wizard navigation
	// =========================================================================

	function showStep(step) {
		$('.intersoccer-pm-step').hide();
		$('.intersoccer-pm-step[data-step="' + step + '"]').show();
		$('.step-dot').removeClass('active');
		$('.step-dot[data-step="' + step + '"]').addClass('active');
		$('.step-dot').each(function() {
			var s = parseInt($(this).data('step'), 10);
			if (s < step) {
				$(this).addClass('completed');
			} else {
				$(this).removeClass('completed');
			}
		});
	}

	function getSelectedType() {
		return $('input[name="program_type"]:checked').val() || '';
	}

	$(document).on('click', '.intersoccer-pm-next', function() {
		var next = parseInt($(this).data('next'), 10);
		var current = next - 1;

		if (current === 1) {
			var type = getSelectedType();
			if (!type) {
				alert(PM.i18n.select_type);
				return;
			}
			showAttrFieldsForType(type);
		}

		if (current === 2) {
			var name = $('#pm-product-name').val().trim();
			if (!name) {
				alert(PM.i18n.enter_name);
				return;
			}
			var attrs = snapshotParentAttrs();
			if (getSelectedType() === 'course' && !(attrs['pa_course-day'] || []).length) {
				alert('Please select at least one Course Day before continuing.');
				return;
			}
			buildMatrixPreview();
		}

		if (current === 3) {
			snapshotMatrixRows();
			buildReviewSummary();
		}

		showStep(next);
	});

	$(document).on('click', '.intersoccer-pm-prev', function() {
		var prev = parseInt($(this).data('prev'), 10);
		showStep(prev);
	});

	// =========================================================================
	// Step 2: Show/hide attribute fields based on type
	// =========================================================================

	function rowTypeList($row) {
		return String($row.attr('data-types') || '')
			.split(',')
			.map(function(s) { return s.trim(); })
			.filter(Boolean);
	}

	function rowMatchesType($row, type) {
		return rowTypeList($row).indexOf(type) !== -1;
	}

	function showAttrFieldsForType(type) {
		$('.intersoccer-pm-attr-row').hide();
		$('.intersoccer-pm-attr-row').each(function() {
			if (rowMatchesType($(this), type)) {
				$(this).show();
			}
		});
	}

	/**
	 * Read selected option values (works for single + multi, hidden or visible).
	 */
	function readSelectSlugs($select) {
		if (!$select || !$select.length) {
			return [];
		}
		var slugs = [];
		$select.find('option').filter(':selected').each(function() {
			var v = this.value || $(this).attr('value') || '';
			if (v) {
				slugs.push(String(v));
			}
		});
		if (slugs.length) {
			return slugs;
		}
		var val = $select.val();
		if (!val) {
			return [];
		}
		return (Array.isArray(val) ? val : [val]).map(String).filter(Boolean);
	}

	// =========================================================================
	// Step 3: Build variation matrix preview
	// =========================================================================

	function buildMatrixPreview() {
		var type = getSelectedType();
		var rows = Matrix[type] || [];

		if (type === 'camp') {
			rows = rebuildCampMatrixFromParentAttrs();
			Matrix.camp = rows;
		}

		if (type === 'course') {
			rows = rebuildCourseMatrixFromParentAttrs();
			Matrix.course = rows;
		}

		var $container = $('#intersoccer-pm-matrix-container');
		$container.empty();

		var summaryHtml = buildParentAttrSummaryHtml();
		if (summaryHtml) {
			$container.append(summaryHtml);
		}

		if (rows.length === 0) {
			var emptyMsg = type === 'course'
				? 'Select at least one Course Day in Step 2 to build variations.'
				: 'No default variations for this type.';
			$container.append('<p><em>' + emptyMsg + '</em></p>');
			lastMatrixRows = [];
			return;
		}

		var html = '<table class="widefat striped"><thead><tr><th style="width:30px;"></th><th>Variation</th></tr></thead><tbody>';
		for (var i = 0; i < rows.length; i++) {
			var label = rows[i].label || JSON.stringify(rows[i]);
			html += '<tr class="intersoccer-pm-matrix-row">';
			html += '<td><input type="checkbox" class="pm-matrix-check" data-index="' + i + '" checked /></td>';
			html += '<td>' + escHtml(label) + '</td>';
			html += '</tr>';
		}
		html += '</tbody></table>';
		$container.append(html);
		snapshotMatrixRows();
	}

	function buildParentAttrSummaryHtml() {
		var lines = collectParentAttrLabels();
		if (!lines.length) {
			return '';
		}
		var html = '<div class="intersoccer-pm-step2-summary" style="margin:0 0 16px;padding:12px;background:#f6f7f7;border-left:4px solid #2271b1;">';
		html += '<p style="margin:0 0 8px;"><strong>Selections from Step 2</strong></p><ul style="margin:0;">';
		for (var i = 0; i < lines.length; i++) {
			html += '<li>' + escHtml(lines[i]) + '</li>';
		}
		html += '</ul></div>';
		return html;
	}

	function collectParentAttrLabels() {
		var type = getSelectedType();
		var lines = [];
		$('.intersoccer-pm-attr-row').each(function() {
			var $row = $(this);
			if (!rowMatchesType($row, type)) {
				return;
			}
			var $select = $row.find('select.intersoccer-pm-attr-select').first();
			if (!$select.length) {
				return;
			}
			var labels = $select.find('option').filter(':selected').map(function() {
				return $(this).text();
			}).get().filter(function(t) {
				return t && t !== '— Select —';
			});
			if (!labels.length) {
				return;
			}
			var label = $row.find('th label').text().replace(/\s*\*\s*$/, '');
			lines.push(label + ': ' + labels.join(', '));
		});
		var start = $('#pm-course-start-date').val();
		var weeks = $('#pm-course-total-weeks').val();
		var price = $('#pm-regular-price').val();
		if (start) {
			lines.push('Course start date: ' + start);
		}
		if (weeks) {
			lines.push('Total weeks / sessions: ' + weeks);
		}
		if (price) {
			lines.push('Regular price (CHF): ' + price);
		}
		return lines;
	}

	/**
	 * Collect parent attribute selections for the active program type (works when Step 2 is hidden).
	 */
	function snapshotParentAttrs() {
		var type = getSelectedType();
		var attrs = {};
		$('.intersoccer-pm-attr-row').each(function() {
			var $row = $(this);
			if (!rowMatchesType($row, type)) {
				return;
			}
			var $select = $row.find('select.intersoccer-pm-attr-select').first();
			if (!$select.length) {
				return;
			}
			var taxonomy = $select.attr('data-taxonomy') || $select.data('taxonomy');
			var slugs = readSelectSlugs($select);
			if (taxonomy && slugs.length) {
				attrs[taxonomy] = slugs;
			}
		});
		lastParentAttrs = attrs;
		return attrs;
	}

	function snapshotMatrixRows() {
		lastMatrixRows = getSelectedMatrixRows();
		return lastMatrixRows;
	}


	/**
	 * Rebuild camp matrix from selected parent ages/times (pairs Full/Half Day with clock hours).
	 */
	function rebuildCampMatrixFromParentAttrs() {
		var defaults = window.intersoccerPMCampDefaults || {
			ages: ['5-13y-full-day', '3-5y-half-day'],
			bookings: ['full-week', 'single-days'],
			times: ['1000-1700', '1000-1230']
		};
		var ages = getSelectedAttrSlugs('pa_age-group');
		var times = getSelectedAttrSlugs('pa_camp-times');
		if (!ages.length) {
			ages = defaults.ages.slice();
		}
		if (!times.length) {
			times = defaults.times.slice();
		}
		var bookings = defaults.bookings.slice();
		var bookingLabels = {
			'full-week': 'Full Week',
			'single-days': 'Single Day(s)'
		};
		var rows = [];
		ages.forEach(function(age) {
			var time = pairCampTimeForAge(age, times);
			bookings.forEach(function(booking) {
				var row = {
					'pa_booking-type': booking,
					'pa_age-group': age
				};
				var parts = [bookingLabels[booking] || booking, age];
				if (time) {
					row['pa_camp-times'] = time;
					parts.push(time);
				}
				row.label = parts.join(' / ');
				rows.push(row);
			});
		});
		return rows;
	}

	/**
	 * Rebuild course matrix from selected Course Day(s) × ages × times × venues.
	 */
	function rebuildCourseMatrixFromParentAttrs() {
		// Course times are variation-only — not selected at program create.
		var days = getSelectedAttrSlugs('pa_course-day');
		if (!days.length) {
			return [];
		}
		var ages = getSelectedAttrSlugs('pa_age-group');
		var venues = getSelectedAttrSlugs('pa_intersoccer-venues');

		var ageList = ages.length ? ages : [''];
		var venueList = venues.length ? venues : [''];

		var rows = [];
		days.forEach(function(day) {
			ageList.forEach(function(age) {
				venueList.forEach(function(venue) {
					var row = { 'pa_course-day': day };
					var parts = [day];
					if (age) {
						row['pa_age-group'] = age;
						parts.push(age);
					}
					if (venue) {
						row['pa_intersoccer-venues'] = venue;
						parts.push(venue);
					}
					row.label = parts.join(' / ');
					rows.push(row);
				});
			});
		});
		return rows;
	}

	function getSelectedAttrSlugs(taxonomy) {
		if (lastParentAttrs[taxonomy] && lastParentAttrs[taxonomy].length) {
			return lastParentAttrs[taxonomy].slice();
		}
		var type = getSelectedType();
		var found = [];
		$('.intersoccer-pm-attr-row').each(function() {
			var $row = $(this);
			if (!rowMatchesType($row, type)) {
				return;
			}
			var $select = $row.find('select.intersoccer-pm-attr-select').filter(function() {
				return ($(this).attr('data-taxonomy') || '') === taxonomy;
			}).first();
			if (!$select.length) {
				return;
			}
			found = readSelectSlugs($select);
		});
		return found;
	}

	function pairCampTimeForAge(ageSlug, allowedTimes) {
		var age = (ageSlug || '').toLowerCase();
		var isHalf = age.indexOf('half-day') !== -1 || age.indexOf('half_day') !== -1;
		var preferred = isHalf
			? ['1000-1230', '1000-1200', '0900-1200']
			: ['1000-1700', '1000-1500', '0900-1700'];
		var pool = (allowedTimes && allowedTimes.length) ? allowedTimes : preferred;
		for (var i = 0; i < preferred.length; i++) {
			if (pool.indexOf(preferred[i]) !== -1) {
				return preferred[i];
			}
		}
		for (var j = 0; j < pool.length; j++) {
			var s = String(pool[j]).toLowerCase();
			if (isHalf && (/12[0-3]0$/.test(s) || s.indexOf('1230') !== -1 || s.indexOf('1200') !== -1)) {
				return pool[j];
			}
			if (!isHalf && (/17[0-3]0$/.test(s) || s.indexOf('1700') !== -1 || s.indexOf('1500') !== -1)) {
				return pool[j];
			}
		}
		return (allowedTimes && allowedTimes.length) ? '' : (isHalf ? '1000-1230' : '1000-1700');
	}

	// =========================================================================
	// Step 4: Review summary
	// =========================================================================

	function buildReviewSummary() {
		var type = getSelectedType();
		var name = $('#pm-product-name').val().trim();
		snapshotParentAttrs();
		var selectedRows = lastMatrixRows.length ? lastMatrixRows : getSelectedMatrixRows();
		var attrs = collectParentAttrLabels();

		var html = '<table class="form-table">';
		html += '<tr><th>Name</th><td><strong>' + escHtml(name) + '</strong></td></tr>';
		html += '<tr><th>Type</th><td>' + escHtml(type.charAt(0).toUpperCase() + type.slice(1)) + '</td></tr>';
		html += '<tr><th>Status</th><td>Draft</td></tr>';
		html += '<tr><th>Attributes</th><td>' + escHtml(attrs.join('; ') || 'None selected') + '</td></tr>';
		html += '<tr><th>Variations</th><td>' + selectedRows.length + ' will be created</td></tr>';
		html += '</table>';

		$('#intersoccer-pm-review-summary').html(html);
	}

	function getSelectedMatrixRows() {
		var type = getSelectedType();
		var allRows = Matrix[type] || [];
		var selected = [];

		$('.pm-matrix-check:checked').each(function() {
			var idx = parseInt($(this).data('index'), 10);
			if (allRows[idx]) {
				selected.push(allRows[idx]);
			}
		});

		return selected;
	}

	// =========================================================================
	// Create program (AJAX)
	// =========================================================================

	$(document).on('click', '#intersoccer-pm-create-btn', function() {
		var $btn = $(this);
		if ($btn.prop('disabled')) return;

		if (!confirm(PM.i18n.confirm_create)) return;

		$btn.prop('disabled', true).text(PM.i18n.creating);

		var type = getSelectedType();
		var name = $('#pm-product-name').val().trim();
		snapshotParentAttrs();
		snapshotMatrixRows();
		var matrix = lastMatrixRows.length ? lastMatrixRows : getSelectedMatrixRows();
		var parentAttrs = Object.keys(lastParentAttrs).length ? lastParentAttrs : {};

		if (!Object.keys(parentAttrs).length) {
			parentAttrs = snapshotParentAttrs();
		}
		if (type === 'course' && !(parentAttrs['pa_course-day'] || []).length) {
			alert('Please select at least one Course Day in Step 2 before creating.');
			$btn.prop('disabled', false).text('Create as Draft');
			return;
		}

		var courseMeta = {
			_course_start_date: $('#pm-course-start-date').val() || '',
			_course_total_weeks: $('#pm-course-total-weeks').val() || '',
			_course_holiday_dates: $('#pm-course-holiday-dates').val() || ''
		};
		var regularPrice = $('#pm-regular-price').val() || '';


		$.post(PM.ajax_url, {
			action: 'intersoccer_pm_create_product',
			nonce: PM.nonce,
			name: name,
			program_type: type,
			parent_attrs_json: JSON.stringify(parentAttrs),
			matrix_json: JSON.stringify(matrix),
			course_meta_json: JSON.stringify(courseMeta),
			regular_price: regularPrice
		}).done(function(response) {
			if (response.success) {
				$('#intersoccer-pm-create-result')
					.show()
					.html('<div class="notice notice-success"><p>Program created with ' + response.data.variations_created + ' variations. Redirecting…</p></div>');
				setTimeout(function() {
					window.location.href = response.data.redirect;
				}, 1000);
			} else {
				$('#intersoccer-pm-create-result')
					.show()
					.html('<div class="notice notice-error"><p>' + escHtml(response.data.message || 'Unknown error') + '</p></div>');
				$btn.prop('disabled', false).text('Create as Draft');
			}
		}).fail(function() {
			$('#intersoccer-pm-create-result')
				.show()
				.html('<div class="notice notice-error"><p>Request failed.</p></div>');
			$btn.prop('disabled', false).text('Create as Draft');
		});
	});

	// =========================================================================
	// Scaffold variations (detail view)
	// =========================================================================

	$(document).on('click', '#intersoccer-pm-scaffold-btn', function() {
		var $btn = $(this);
		if ($btn.prop('disabled')) return;

		var productId = $btn.data('product-id');
		var productType = $btn.data('product-type');

		$btn.prop('disabled', true).text(PM.i18n.saving);

		$.post(PM.ajax_url, {
			action: 'intersoccer_pm_scaffold_variations',
			nonce: PM.nonce,
			product_id: productId,
			product_type: productType
		}).done(function(response) {
			if (response.success) {
				alert(response.data.message);
				window.location.reload();
			} else {
				alert(PM.i18n.error + ': ' + (response.data.message || 'Unknown error'));
				$btn.prop('disabled', false).text('Auto-generate Variations');
			}
		}).fail(function() {
			alert(PM.i18n.error + ': Request failed');
			$btn.prop('disabled', false).text('Auto-generate Variations');
		});
	});

	// =========================================================================
	// Inline price editing (detail view)
	// =========================================================================

	var priceTimer = null;

	$(document).on('change', '.intersoccer-pm-price-input', function() {
		var $input = $(this);
		var variationId = $input.data('variation-id');
		var price = $input.val();
		var $status = $input.siblings('.intersoccer-pm-price-status');

		if (priceTimer) clearTimeout(priceTimer);

		priceTimer = setTimeout(function() {
			$status.text(PM.i18n.saving).css('color', '#666');

			$.post(PM.ajax_url, {
				action: 'intersoccer_pm_save_variation_price',
				nonce: PM.nonce,
				variation_id: variationId,
				price: price
			}).done(function(response) {
				if (response.success) {
					$status.text(PM.i18n.saved).css('color', 'green');
					setTimeout(function() { $status.text(''); }, 2000);
				} else {
					$status.text(PM.i18n.error).css('color', 'red');
				}
			}).fail(function() {
				$status.text(PM.i18n.error).css('color', 'red');
			});
		}, 500);
	});

	// =========================================================================
	// Variation Enabled / Disabled (publish | private)
	// =========================================================================

	function pmRowCampEnded($row) {
		var raw = $row.attr('data-camp-ended');
		return raw === '1' || raw === 1 || raw === true;
	}

	function pmUpdateVariationEnabledUi($row, enabled) {
		var campEnded = pmRowCampEnded($row);
		var needsAction = campEnded && !!enabled;
		$row.toggleClass('intersoccer-pm-variation-disabled', !enabled);
		$row.toggleClass('intersoccer-pm-variation-ended', needsAction);
		var $badge = $row.find('.intersoccer-pm-ended-badge');
		if ($badge.length) {
			$badge.prop('hidden', !needsAction);
		}
	}

	function pmShowEnabledSaving($row) {
		$row.find('.intersoccer-pm-ended-badge').prop('hidden', true);
		$row.find('.intersoccer-pm-enabled-status').text(PM.i18n.saving).css('color', '#666');
	}

	function pmShowEnabledSaved($row, enabled) {
		var $status = $row.find('.intersoccer-pm-enabled-status');
		pmUpdateVariationEnabledUi($row, enabled);
		// Keep badge hidden while "Saved" is visible so the two alternate in the same slot.
		$row.find('.intersoccer-pm-ended-badge').prop('hidden', true);
		$status.text(PM.i18n.saved).css('color', 'green');
		setTimeout(function() {
			$status.text('');
			pmUpdateVariationEnabledUi($row, enabled);
		}, 2000);
	}

	$(document).on('change', '.intersoccer-pm-enabled-toggle', function() {
		var $toggle = $(this);
		var variationId = $toggle.data('variation-id');
		var enabled = $toggle.is(':checked') ? 1 : 0;
		var $row = $toggle.closest('tr');
		var $status = $row.find('.intersoccer-pm-enabled-status');

		pmShowEnabledSaving($row);
		$toggle.prop('disabled', true);

		$.post(PM.ajax_url, {
			action: 'intersoccer_pm_save_variation_enabled',
			nonce: PM.nonce,
			variation_id: variationId,
			enabled: enabled
		}).done(function(response) {
			$toggle.prop('disabled', false);
			if (response.success) {
				var isEnabled = !!(response.data && response.data.enabled);
				$toggle.prop('checked', isEnabled);
				pmShowEnabledSaved($row, isEnabled);
			} else {
				$toggle.prop('checked', !enabled);
				pmUpdateVariationEnabledUi($row, !enabled);
				$status.text(PM.i18n.error + ': ' + ((response.data && response.data.message) || '')).css('color', 'red');
			}
		}).fail(function() {
			$toggle.prop('disabled', false);
			$toggle.prop('checked', !enabled);
			pmUpdateVariationEnabledUi($row, !enabled);
			$status.text(PM.i18n.error).css('color', 'red');
		});
	});

	$(document).on('change', '#intersoccer-pm-select-all-variations', function() {
		var checked = $(this).is(':checked');
		$('.intersoccer-pm-variations-table .intersoccer-pm-variation-select').prop('checked', checked);
	});

	$(document).on('click', '#intersoccer-pm-bulk-disable-variations-btn', function() {
		var ids = [];
		$('.intersoccer-pm-variations-table .intersoccer-pm-variation-select:checked').each(function() {
			ids.push($(this).val());
		});
		if (!ids.length) {
			window.alert(PM.i18n.select_variations || 'Select one or more variations first.');
			return;
		}
		if (!window.confirm(PM.i18n.confirm_disable_selected || 'Disable the selected variations?')) {
			return;
		}

		var $btn = $(this);
		var $status = $('#intersoccer-pm-bulk-disable-status');
		$btn.prop('disabled', true);
		$status.text(PM.i18n.saving).css('color', '#666');
		ids.forEach(function(id) {
			pmShowEnabledSaving($('.intersoccer-pm-variations-table tr[data-variation-id="' + id + '"]'));
		});

		$.post(PM.ajax_url, {
			action: 'intersoccer_pm_bulk_disable_variations',
			nonce: PM.nonce,
			variation_ids: JSON.stringify(ids)
		}).done(function(response) {
			$btn.prop('disabled', false);
			if (response.success) {
				var count = (response.data && response.data.disabled) || 0;
				ids.forEach(function(id) {
					var $row = $('.intersoccer-pm-variations-table tr[data-variation-id="' + id + '"]');
					$row.find('.intersoccer-pm-enabled-toggle').prop('checked', false);
					pmShowEnabledSaved($row, false);
				});
				$status.text((PM.i18n.disabled_count || 'Disabled %d variation(s).').replace('%d', String(count))).css('color', 'green');
			} else {
				ids.forEach(function(id) {
					var $row = $('.intersoccer-pm-variations-table tr[data-variation-id="' + id + '"]');
					var stillEnabled = $row.find('.intersoccer-pm-enabled-toggle').is(':checked');
					pmUpdateVariationEnabledUi($row, stillEnabled);
				});
				$status.text(PM.i18n.error).css('color', 'red');
			}
		}).fail(function() {
			$btn.prop('disabled', false);
			ids.forEach(function(id) {
				var $row = $('.intersoccer-pm-variations-table tr[data-variation-id="' + id + '"]');
				var stillEnabled = $row.find('.intersoccer-pm-enabled-toggle').is(':checked');
				pmUpdateVariationEnabledUi($row, stillEnabled);
			});
			$status.text(PM.i18n.error).css('color', 'red');
		});
	});

	// =========================================================================
	// Camp variation venue assignment (detail view)
	// =========================================================================

	$(document).on('change', '.intersoccer-pm-venue-select', function() {
		var $select = $(this);
		var $row = $select.closest('tr');
		var $status = $row.find('.intersoccer-pm-venue-status');
		$status.text(PM.i18n.saving).css('color', '#666');

		$.post(PM.ajax_url, {
			action: 'intersoccer_pm_save_variation_venue',
			nonce: PM.nonce,
			variation_id: $select.data('variation-id'),
			venue: $select.val() || ''
		}).done(function(response) {
			if (response.success) {
				$status.text(PM.i18n.saved).css('color', 'green');
				setTimeout(function() { $status.text(''); }, 2000);
			} else {
				$status.text(PM.i18n.error + ': ' + ((response.data && response.data.message) || '')).css('color', 'red');
			}
		}).fail(function() {
			$status.text(PM.i18n.error).css('color', 'red');
		});
	});

	// =========================================================================
	// Course variation time assignment (detail view)
	// =========================================================================

	$(document).on('change', '.intersoccer-pm-course-time-select', function() {
		var $select = $(this);
		var $row = $select.closest('tr');
		var $status = $row.find('.intersoccer-pm-course-time-status');
		$status.text(PM.i18n.saving).css('color', '#666');

		$.post(PM.ajax_url, {
			action: 'intersoccer_pm_save_variation_course_time',
			nonce: PM.nonce,
			variation_id: $select.data('variation-id'),
			course_time: $select.val() || ''
		}).done(function(response) {
			if (response.success) {
				$status.text(PM.i18n.saved).css('color', 'green');
				setTimeout(function() { $status.text(''); }, 2000);
			} else {
				$status.text(PM.i18n.error + ': ' + ((response.data && response.data.message) || '')).css('color', 'red');
			}
		}).fail(function() {
			$status.text(PM.i18n.error).css('color', 'red');
		});
	});

	// =========================================================================
	// Duplicate program
	// =========================================================================

	function rewriteTitleForYear(name, year) {
		year = String(year || '').trim();
		if (!/^(20\d{2})$/.test(year)) {
			return name;
		}
		name = String(name || '').replace(/\s*\(Copy\)\s*$/i, '').trim();
		if (/\b20\d{2}\b/.test(name)) {
			return name.replace(/\b20\d{2}\b/, year);
		}
		return name ? (name + ' ' + year) : year;
	}

	function resolveDupYear() {
		var custom = ($('#pm-dup-year-custom').val() || '').trim();
		if (/^(20\d{2})$/.test(custom)) {
			return custom;
		}
		return ($('#pm-dup-year').val() || '').trim();
	}

	$(document).on('change', '#pm-dup-year, #pm-dup-year-custom', function() {
		var $name = $('#pm-dup-name');
		if (!$name.length) return;
		var year = resolveDupYear();
		if (!year) return;
		var source = $name.data('source-name') || $name.val();
		$name.val(rewriteTitleForYear(source, year));
	});

	$(document).on('click', '#intersoccer-pm-duplicate-btn', function() {
		var $btn = $(this);
		if ($btn.prop('disabled')) return;

		if (!confirm(PM.i18n.confirm_duplicate)) return;

		var sourceId = $btn.data('source-id');
		var newName = $('#pm-dup-name').val().trim();
		var newSeason = $('#pm-dup-season').val();
		var newYear = resolveDupYear();

		if (!newName) {
			alert(PM.i18n.enter_name);
			return;
		}

		$btn.prop('disabled', true).text(PM.i18n.creating);

		$.post(PM.ajax_url, {
			action: 'intersoccer_pm_duplicate_program',
			nonce: PM.nonce,
			source_id: sourceId,
			name: newName,
			season: newSeason,
			year: newYear
		}).done(function(response) {
			if (response.success) {
				$('#intersoccer-pm-duplicate-result')
					.show()
					.html('<div class="notice notice-success"><p>Program duplicated. Redirecting…</p></div>');
				setTimeout(function() {
					window.location.href = response.data.redirect;
				}, 1000);
			} else {
				$('#intersoccer-pm-duplicate-result')
					.show()
					.html('<div class="notice notice-error"><p>' + escHtml(response.data.message || 'Unknown error') + '</p></div>');
				$btn.prop('disabled', false).text('Duplicate as Draft');
			}
		}).fail(function() {
			$('#intersoccer-pm-duplicate-result')
				.show()
				.html('<div class="notice notice-error"><p>Request failed.</p></div>');
			$btn.prop('disabled', false).text('Duplicate as Draft');
		});
	});

	// =========================================================================
	// Bulk Duplicate to year — beside Bulk Actions; only when that action is selected
	// =========================================================================

	function pmBulkActionValue($form) {
		var action = $form.find('select[name="action"]').val();
		if (!action || action === '-1') {
			action = $form.find('select[name="action2"]').val();
		}
		return action || '-1';
	}

	function pmSyncBulkActionSelects($form, value) {
		$form.find('select[name="action"], select[name="action2"]').each(function() {
			if ($(this).find('option[value="' + value + '"]').length) {
				$(this).val(value);
			}
		});
	}

	function pmToggleBulkYearRoll($form) {
		var $roll = $('#intersoccer-pm-bulk-year-roll');
		if (!$roll.length || !$form.length) {
			return;
		}
		var show = pmBulkActionValue($form) === 'duplicate_to_year';
		$roll.prop('hidden', !show);
		if (show) {
			$roll.css({ display: 'inline-block', marginLeft: '8px', verticalAlign: 'middle' });
		} else {
			$roll.css('display', 'none');
		}
	}

	function pmPlaceBulkYearRoll() {
		var $form = $('#intersoccer-pm-bulk-form');
		var $roll = $('#intersoccer-pm-bulk-year-roll');
		if (!$form.length || !$roll.length) {
			return;
		}
		var $actions = $form.find('.tablenav.top .alignleft.actions').first();
		if ($actions.length) {
			var $doaction = $actions.find('#doaction');
			if ($doaction.length) {
				$roll.insertBefore($doaction);
			} else {
				$actions.append($roll);
			}
		}
		pmToggleBulkYearRoll($form);
	}

	pmPlaceBulkYearRoll();

	$(document).on('change', '#intersoccer-pm-bulk-form select[name="action"], #intersoccer-pm-bulk-form select[name="action2"]', function() {
		var $form = $('#intersoccer-pm-bulk-form');
		var value = $(this).val();
		if (value && value !== '-1') {
			pmSyncBulkActionSelects($form, value);
		}
		pmToggleBulkYearRoll($form);
	});

	var PM_BULK_ACTIONS = {
		refresh_attrs: 'bulk_title_refresh',
		scaffold_variations: 'bulk_title_scaffold',
		duplicate_to_year: 'bulk_title_duplicate',
		sync_wpml_languages: 'bulk_title_wpml'
	};

	var pmBulkRun = {
		active: false,
		cancel: false,
		queue: [],
		index: 0,
		action: '',
		opts: {},
		tallies: { processed: 0, skipped: 0, failed: 0 },
		messages: []
	};

	function pmBulkI18n(key, fallback) {
		return (PM.i18n && PM.i18n[key]) ? PM.i18n[key] : fallback;
	}

	function pmBulkSprintf(template) {
		var args = Array.prototype.slice.call(arguments, 1);
		var i = 0;
		return String(template).replace(/%(\d+)\$[sd]|%[sd]/g, function(match, num) {
			if (num) {
				return args[parseInt(num, 10) - 1];
			}
			return args[i++];
		});
	}

	function pmEnsureBulkProgressModal() {
		var $modal = $('#intersoccer-pm-bulk-progress');
		if ($modal.length) {
			return $modal;
		}
		$modal = $(
			'<div id="intersoccer-pm-bulk-progress" class="intersoccer-pm-bulk-progress" hidden>' +
				'<div class="intersoccer-pm-bulk-progress__dialog" role="dialog" aria-modal="true" aria-labelledby="intersoccer-pm-bulk-progress-title">' +
					'<h2 id="intersoccer-pm-bulk-progress-title" class="intersoccer-pm-bulk-progress__title"></h2>' +
					'<p class="intersoccer-pm-bulk-progress__status"></p>' +
					'<div class="intersoccer-pm-bulk-progress__bar-wrap">' +
						'<div class="intersoccer-pm-bulk-progress__bar" style="width:0%"></div>' +
					'</div>' +
					'<p class="intersoccer-pm-bulk-progress__tallies"></p>' +
					'<ul class="intersoccer-pm-bulk-progress__log"></ul>' +
					'<p class="intersoccer-pm-bulk-progress__summary" hidden></p>' +
					'<div class="intersoccer-pm-bulk-progress__actions">' +
						'<button type="button" class="button" id="intersoccer-pm-bulk-progress-cancel"></button>' +
					'</div>' +
				'</div>' +
			'</div>'
		);
		$('body').append($modal);
		$modal.find('#intersoccer-pm-bulk-progress-cancel').text(pmBulkI18n('bulk_cancel', 'Cancel'));
		return $modal;
	}

	function pmUpdateBulkProgressUI(currentName) {
		var $modal = pmEnsureBulkProgressModal();
		var total = pmBulkRun.queue.length;
		var done = pmBulkRun.index;
		var pct = total ? Math.round((done / total) * 100) : 0;
		var statusTpl = pmBulkI18n('bulk_progress_of', 'Processing %1$d of %2$d: %3$s');
		var talliesTpl = pmBulkI18n('bulk_tallies', 'Processed: %1$d · Skipped: %2$d · Failed: %3$d');
		var label = currentName || ('#' + (pmBulkRun.queue[done] || ''));
		var n = Math.min(done + 1, total);
		$modal.find('.intersoccer-pm-bulk-progress__status').text(
			pmBulkSprintf(statusTpl, n, total, label)
		);
		$modal.find('.intersoccer-pm-bulk-progress__bar').css('width', pct + '%');
		$modal.find('.intersoccer-pm-bulk-progress__tallies').text(
			pmBulkSprintf(
				talliesTpl,
				pmBulkRun.tallies.processed,
				pmBulkRun.tallies.skipped,
				pmBulkRun.tallies.failed
			)
		);
	}

	function pmBulkActionTitle(action) {
		var key = PM_BULK_ACTIONS[action];
		var fallbacks = {
			refresh_attrs: 'Refresh Variation Attributes',
			scaffold_variations: 'Auto-scaffold Missing Variations',
			duplicate_to_year: 'Duplicate to year…',
			sync_wpml_languages: 'Sync all languages (WPML)'
		};
		return key ? pmBulkI18n(key, fallbacks[action] || action) : action;
	}

	function pmFinishBulkProgress(stopped) {
		var $modal = pmEnsureBulkProgressModal();
		var total = pmBulkRun.queue.length;
		$modal.find('.intersoccer-pm-bulk-progress__bar').css('width', '100%');
		$modal.find('.intersoccer-pm-bulk-progress__status').text(
			stopped
				? pmBulkI18n('bulk_cancelled', 'Bulk action stopped.')
				: pmBulkI18n('bulk_complete', 'Bulk action complete.')
		);
		var summaryParts = [
			pmBulkSprintf(
				pmBulkI18n('bulk_tallies', 'Processed: %1$d · Skipped: %2$d · Failed: %3$d'),
				pmBulkRun.tallies.processed,
				pmBulkRun.tallies.skipped,
				pmBulkRun.tallies.failed
			)
		];
		if (pmBulkRun.action === 'duplicate_to_year' && pmBulkRun.opts.year) {
			summaryParts.push(
				pmBulkSprintf(
					'Duplicated %1$d programs to Draft (year %2$s).',
					pmBulkRun.tallies.processed,
					pmBulkRun.opts.year
				)
			);
		}
		$modal.find('.intersoccer-pm-bulk-progress__summary')
			.text(summaryParts.join(' '))
			.prop('hidden', false);
		$modal.find('#intersoccer-pm-bulk-progress-cancel')
			.prop('disabled', true)
			.text(pmBulkI18n('bulk_reloading', 'Reloading…'));
		pmBulkRun.active = false;
		setTimeout(function() {
			window.location.reload();
		}, 1200);
	}

	function pmProcessNextBulkItem() {
		if (pmBulkRun.cancel) {
			pmFinishBulkProgress(true);
			return;
		}
		if (pmBulkRun.index >= pmBulkRun.queue.length) {
			pmFinishBulkProgress(false);
			return;
		}

		var productId = pmBulkRun.queue[pmBulkRun.index];
		var $row = $('input[name="product_ids[]"][value="' + productId + '"]').closest('tr');
		var name = $row.find('.row-title, .column-title a, strong a').first().text().trim() || ('#' + productId);
		pmUpdateBulkProgressUI(name);

		var payload = {
			action: 'intersoccer_pm_bulk_process_one',
			nonce: PM.nonce,
			bulk_action: pmBulkRun.action,
			product_id: productId
		};
		if (pmBulkRun.action === 'duplicate_to_year') {
			payload.pm_target_year = pmBulkRun.opts.year || '';
			payload.pm_target_year_custom = pmBulkRun.opts.yearCustom || '';
			payload.pm_target_season = pmBulkRun.opts.season || '';
		}

		$.post(PM.ajax_url, payload)
			.done(function(response) {
				var data = (response && response.data) ? response.data : {};
				var outcome = data.outcome || (response && response.success ? 'processed' : 'failed');
				if (outcome === 'skipped') {
					pmBulkRun.tallies.skipped++;
				} else if (outcome === 'failed' || (response && response.success === false)) {
					pmBulkRun.tallies.failed++;
				} else {
					pmBulkRun.tallies.processed++;
				}
				if (data.message) {
					pmBulkRun.messages.push(data.message);
					var $log = $('#intersoccer-pm-bulk-progress .intersoccer-pm-bulk-progress__log');
					$log.append($('<li></li>').text(data.message));
					if ($log.children().length > 12) {
						$log.children().first().remove();
					}
				}
				pmBulkRun.index++;
				$('#intersoccer-pm-bulk-progress .intersoccer-pm-bulk-progress__tallies').text(
					pmBulkSprintf(
						pmBulkI18n('bulk_tallies', 'Processed: %1$d · Skipped: %2$d · Failed: %3$d'),
						pmBulkRun.tallies.processed,
						pmBulkRun.tallies.skipped,
						pmBulkRun.tallies.failed
					)
				);
				var total = pmBulkRun.queue.length;
				var pct = total ? Math.round((pmBulkRun.index / total) * 100) : 0;
				$('#intersoccer-pm-bulk-progress .intersoccer-pm-bulk-progress__bar').css('width', pct + '%');
				pmProcessNextBulkItem();
			})
			.fail(function() {
				pmBulkRun.tallies.failed++;
				pmBulkRun.messages.push(pmBulkI18n('error', 'Error') + ' #' + productId);
				pmBulkRun.index++;
				pmProcessNextBulkItem();
			});
	}

	function pmStartBulkProgress(action, ids, opts) {
		var $modal = pmEnsureBulkProgressModal();
		pmBulkRun.active = true;
		pmBulkRun.cancel = false;
		pmBulkRun.queue = ids.slice();
		pmBulkRun.index = 0;
		pmBulkRun.action = action;
		pmBulkRun.opts = opts || {};
		pmBulkRun.tallies = { processed: 0, skipped: 0, failed: 0 };
		pmBulkRun.messages = [];

		$modal.find('.intersoccer-pm-bulk-progress__title').text(pmBulkActionTitle(action));
		$modal.find('.intersoccer-pm-bulk-progress__summary').prop('hidden', true).text('');
		$modal.find('.intersoccer-pm-bulk-progress__log').empty();
		$modal.find('.intersoccer-pm-bulk-progress__bar').css('width', '0%');
		$modal.find('#intersoccer-pm-bulk-progress-cancel')
			.prop('disabled', false)
			.text(pmBulkI18n('bulk_cancel', 'Cancel'));
		$modal.prop('hidden', false);
		$('#intersoccer-pm-bulk-form').find('#doaction, #doaction2').prop('disabled', true);
		pmUpdateBulkProgressUI('');
		pmProcessNextBulkItem();
	}

	$(document).on('click', '#intersoccer-pm-bulk-progress-cancel', function() {
		if (!pmBulkRun.active || pmBulkRun.cancel) {
			return;
		}
		pmBulkRun.cancel = true;
		$(this).prop('disabled', true).text(pmBulkI18n('bulk_stopping', 'Stopping after current…'));
	});

	$(document).on('submit', '#intersoccer-pm-bulk-form', function(e) {
		var $form = $(this);
		var action = pmBulkActionValue($form);

		if (!PM_BULK_ACTIONS[action]) {
			return;
		}

		e.preventDefault();

		if (pmBulkRun.active) {
			return false;
		}

		var ids = [];
		$form.find('input[name="product_ids[]"]:checked').each(function() {
			var id = parseInt($(this).val(), 10);
			if (id) {
				ids.push(id);
			}
		});
		if (!ids.length) {
			alert(
				action === 'duplicate_to_year'
					? (PM.i18n.select_programs || 'Select one or more programs in the list before applying Duplicate to year.')
					: pmBulkI18n('bulk_select_items', 'Select one or more programs before applying a bulk action.')
			);
			return false;
		}

		var opts = {};
		if (action === 'duplicate_to_year') {
			var year = ($('#pm_target_year').val() || '').trim();
			var custom = ($('#pm_target_year_custom').val() || '').trim();
			if (!/^(20\d{2})$/.test(year) && !/^(20\d{2})$/.test(custom)) {
				alert(PM.i18n.select_target_year || 'Select or enter a target program year before applying Duplicate to year.');
				return false;
			}
			if (!/^(20\d{2})$/.test(year) && /^(20\d{2})$/.test(custom)) {
				$('#pm_target_year').append($('<option>', { value: custom, text: custom, selected: true }));
				year = custom;
			}
			opts.year = year || custom;
			opts.yearCustom = custom;
			opts.season = ($('#pm_target_season').val() || '').trim();
		}

		if (action === 'sync_wpml_languages') {
			var confirmMsg = pmBulkI18n('confirm_sync_wpml', 'Sync all languages (WPML)?');
			if (!window.confirm(confirmMsg)) {
				return false;
			}
		}

		pmStartBulkProgress(action, ids, opts);
		return false;
	});

	// =========================================================================
	// Refresh Attributes (detail view — reuses existing AJAX handler)
	// =========================================================================

	$(document).on('click', '#intersoccer-pm-refresh-attrs-btn', function() {
		var $btn = $(this);
		if ($btn.prop('disabled')) return;

		var variationIds = $btn.data('variation-ids');
		if (!variationIds || !variationIds.length) {
			alert('No unhealthy variations to refresh.');
			return;
		}

		if (!confirm(PM.i18n.confirm_refresh)) return;

		$btn.prop('disabled', true).text(PM.i18n.refreshing);

		$.post(PM.ajax_url, {
			action: 'intersoccer_refresh_variation_attributes',
			nonce: PM.variation_health_nonce,
			bulk_action: 'refresh',
			variation_ids: variationIds
		}).done(function(response) {
			if (response.success) {
				alert('Attributes refreshed successfully!');
				window.location.reload();
			} else {
				alert(PM.i18n.error + ': ' + (response.data.message || 'Unknown error'));
				$btn.prop('disabled', false).text('Refresh Attributes');
			}
		}).fail(function() {
			alert(PM.i18n.error + ': Request failed');
			$btn.prop('disabled', false).text('Refresh Attributes');
		});
	});

	// =========================================================================
	// Course Holiday Fix (detail view — reuses existing AJAX handler)
	// =========================================================================

	$(document).on('click', '#intersoccer-pm-course-holiday-fix-btn', function() {
		var $btn = $(this);
		if ($btn.prop('disabled')) return;

		if (!confirm('Are you sure you want to run the course holiday fix? This should only be run once.')) {
			return;
		}

		var $results = $('#intersoccer-pm-holiday-fix-result');
		$btn.prop('disabled', true).text('Running…');
		$results.show().html('<p>Running course holiday fix…</p>');

		$.post(PM.ajax_url, {
			action: 'intersoccer_run_course_holiday_fix',
			nonce: PM.course_holiday_fix_nonce
		}).done(function(response) {
			if (response.success) {
				$results.html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
				setTimeout(function() { window.location.reload(); }, 2000);
			} else {
				$results.html('<div class="notice notice-error inline"><p>' + escHtml(response.data.message || 'Unknown error') + '</p></div>');
				$btn.prop('disabled', false).text('Run Course Holiday Fix');
			}
		}).fail(function() {
			$results.html('<div class="notice notice-error inline"><p>Request failed.</p></div>');
			$btn.prop('disabled', false).text('Run Course Holiday Fix');
		});
	});

	// =========================================================================
	// Save parent attributes (detail view)
	// =========================================================================

	$(document).on('click', '#intersoccer-pm-save-attrs-btn', function() {
		var $btn = $(this);
		if ($btn.prop('disabled')) return;

		var productId = $btn.data('product-id');
		var attrs = {};

		$('.intersoccer-pm-attr-edit').each(function() {
			var taxonomy = $(this).data('taxonomy');
			var val = $(this).val();
			if (taxonomy) {
				if (Array.isArray(val)) {
					attrs[taxonomy] = val;
				} else if (val) {
					attrs[taxonomy] = [val];
				} else {
					attrs[taxonomy] = [];
				}
			}
		});

		$btn.prop('disabled', true);
		$('#intersoccer-pm-attrs-save-status').text(PM.i18n.saving).css('color', '#666');

		$.post(PM.ajax_url, {
			action: 'intersoccer_pm_save_parent_attrs',
			nonce: PM.nonce,
			product_id: productId,
			attrs: attrs
		}).done(function(response) {
			if (response.success) {
				$('#intersoccer-pm-attrs-save-status').text(PM.i18n.saved).css('color', 'green');
				$('.intersoccer-pm-attr-edit').each(function() {
					var taxonomy = $(this).data('taxonomy');
					var val = $(this).val();
					var hasValue = Array.isArray(val) ? val.length > 0 : !!val;
					$(this).closest('tr').find('.intersoccer-pm-attr-status').html(
						hasValue
							? '<span style="color:green;">&#10003;</span>'
							: '<span style="color:red;">&#10007;</span>'
					);
				});
				setTimeout(function() { $('#intersoccer-pm-attrs-save-status').text(''); }, 3000);
			} else {
				$('#intersoccer-pm-attrs-save-status').text(PM.i18n.error + ': ' + (response.data.message || '')).css('color', 'red');
			}
			$btn.prop('disabled', false);
		}).fail(function() {
			$('#intersoccer-pm-attrs-save-status').text(PM.i18n.error).css('color', 'red');
			$btn.prop('disabled', false);
		});
	});


	// =========================================================================
	// Inline create term (detail view)
	// =========================================================================

	$(document).on('click', '.intersoccer-pm-add-term-toggle', function(e) {
		e.preventDefault();
		var $wrap = $(this).closest('.intersoccer-pm-add-term-wrap');
		$(this).hide();
		$wrap.find('.intersoccer-pm-add-term-form').show();
		$wrap.find('.intersoccer-pm-new-term-input').val('').focus();
		$wrap.find('.intersoccer-pm-add-term-status').text('');
	});

	$(document).on('click', '.intersoccer-pm-add-term-cancel', function(e) {
		e.preventDefault();
		var $wrap = $(this).closest('.intersoccer-pm-add-term-wrap');
		$wrap.find('.intersoccer-pm-add-term-form').hide();
		$wrap.find('.intersoccer-pm-add-term-toggle').show();
		$wrap.find('.intersoccer-pm-new-term-input').val('');
		$wrap.find('.intersoccer-pm-add-term-status').text('');
	});

	$(document).on('click', '.intersoccer-pm-add-term-btn', function() {
		var $btn = $(this);
		if ($btn.prop('disabled')) {
			return;
		}

		var taxonomy = $btn.data('taxonomy');
		var $wrap = $btn.closest('.intersoccer-pm-add-term-wrap');
		var $input = $wrap.find('.intersoccer-pm-new-term-input');
		var $status = $wrap.find('.intersoccer-pm-add-term-status');
		var termName = $input.val().trim();

		if (!termName) {
			$status.text(PM.i18n.enter_name || 'Please enter a term name.').css('color', 'red');
			return;
		}

		$btn.prop('disabled', true);
		$status.text(PM.i18n.saving).css('color', '#666');

		$.post(PM.ajax_url, {
			action: 'intersoccer_pm_create_term',
			nonce: PM.nonce,
			taxonomy: taxonomy,
			term_name: termName
		}).done(function(response) {
			if (response.success) {
				var slug = response.data.slug;
				var name = response.data.name;
				var $select = $('select.intersoccer-pm-attr-edit[data-taxonomy="' + taxonomy + '"]');
				if ($select.find('option[value="' + slug + '"]').length === 0) {
					$select.append($('<option>', { value: slug, text: name }));
				}
				if ($select.prop('multiple')) {
					var current = $select.val() || [];
					if (current.indexOf(slug) === -1) {
						current.push(slug);
					}
					$select.val(current);
				} else {
					$select.val(slug);
				}
				$wrap.find('.intersoccer-pm-add-term-form').hide();
				$wrap.find('.intersoccer-pm-add-term-toggle').show();
				$input.val('');
				$status.text(PM.i18n.saved || 'Added').css('color', 'green');
				setTimeout(function() { $status.text(''); }, 2000);
			} else {
				$status.text(response.data.message || PM.i18n.error).css('color', 'red');
			}
			$btn.prop('disabled', false);
		}).fail(function() {
			$status.text(PM.i18n.error).css('color', 'red');
			$btn.prop('disabled', false);
		});
	});

	$(document).on('keydown', '.intersoccer-pm-new-term-input', function(e) {
		if (e.key === 'Enter' || e.keyCode === 13) {
			e.preventDefault();
			$(this).closest('.intersoccer-pm-add-term-form').find('.intersoccer-pm-add-term-btn').trigger('click');
		}
	});

	// =========================================================================
	// Camp schedule editing (detail view)
	// =========================================================================

	var scheduleTimer = null;

	function saveCampSchedule($row) {
		var variationId = $row.data('variation-id');
		var $status = $row.find('.intersoccer-pm-schedule-status');
		$status.text(PM.i18n.saving).css('color', '#666');

		$.post(PM.ajax_url, {
			action: 'intersoccer_pm_save_camp_schedule',
			nonce: PM.nonce,
			variation_id: variationId,
			week: $row.find('.intersoccer-pm-camp-week').val(),
			start: $row.find('.intersoccer-pm-camp-start').val(),
			end: $row.find('.intersoccer-pm-camp-end').val()
		}).done(function(response) {
			if (response.success) {
				$status.text(PM.i18n.saved).css('color', 'green');
				setTimeout(function() { $status.text(''); }, 2000);
			} else {
				$status.text(PM.i18n.error).css('color', 'red');
			}
		}).fail(function() {
			$status.text(PM.i18n.error).css('color', 'red');
		});
	}

	$(document).on('change', '.intersoccer-pm-camp-week, .intersoccer-pm-camp-start, .intersoccer-pm-camp-end', function() {
		var $row = $(this).closest('tr');
		if (scheduleTimer) clearTimeout(scheduleTimer);
		scheduleTimer = setTimeout(function() {
			saveCampSchedule($row);
		}, 400);
	});

	$(document).on('click', '#intersoccer-pm-propose-weeks-btn', function() {
		var $btn = $(this);
		var productId = $btn.data('product-id');
		var week1 = $('#intersoccer-pm-week1-start').val();
		var duration = parseInt($('#intersoccer-pm-week-duration').val(), 10) || 5;
		var $status = $('#intersoccer-pm-schedule-tools-status');

		if (!week1) {
			alert('Please set Week 1 start date.');
			return;
		}

		$status.text(PM.i18n.saving).css('color', '#666');
		$.post(PM.ajax_url, {
			action: 'intersoccer_pm_prefill_camp_schedules',
			nonce: PM.nonce,
			product_id: productId,
			week1_start: week1,
			duration_days: duration,
			overwrite: 0
		}).done(function(response) {
			if (!response.success) {
				$status.text(response.data && response.data.message ? response.data.message : PM.i18n.error).css('color', 'red');
				return;
			}
			(response.data.rows || []).forEach(function(row) {
				var $tr = $('tr[data-variation-id="' + row.variation_id + '"]');
				if (!$tr.length || !row.schedule) return;
				$tr.find('.intersoccer-pm-camp-week').val(row.schedule.week || '');
				$tr.find('.intersoccer-pm-camp-start').val(row.schedule.start || '');
				$tr.find('.intersoccer-pm-camp-end').val(row.schedule.end || '');
			});
			$status.text('Updated ' + (response.data.updated || 0) + ' variations.').css('color', 'green');
		}).fail(function() {
			$status.text(PM.i18n.error).css('color', 'red');
		});
	});

	$(document).on('click', '#intersoccer-pm-apply-parsed-dates-btn', function() {
		var $btn = $(this);
		var productId = $btn.data('product-id');
		var $status = $('#intersoccer-pm-schedule-tools-status');
		if (!confirm('Seed empty camp dates from camp-terms parsing? Existing dates will not be overwritten.')) {
			return;
		}
		$status.text(PM.i18n.saving).css('color', '#666');
		$.post(PM.ajax_url, {
			action: 'intersoccer_pm_apply_parsed_camp_dates',
			nonce: PM.nonce,
			product_id: productId,
			force: 0
		}).done(function(response) {
			if (!response.success) {
				$status.text(response.data && response.data.message ? response.data.message : PM.i18n.error).css('color', 'red');
				return;
			}
			$status.text('Updated ' + (response.data.updated || 0) + ', skipped ' + (response.data.skipped || 0) + ', failed ' + (response.data.failed || 0) + '. Reloading…').css('color', 'green');
			setTimeout(function() { window.location.reload(); }, 1200);
		}).fail(function() {
			$status.text(PM.i18n.error).css('color', 'red');
		});
	});

	$(document).on('click', '#intersoccer-pm-propose-times-btn', function() {
		var $btn = $(this);
		var productId = $btn.data('product-id');
		var $status = $('#intersoccer-pm-schedule-tools-status');
		$status.text(PM.i18n.saving).css('color', '#666');
		$.post(PM.ajax_url, {
			action: 'intersoccer_pm_propose_camp_times',
			nonce: PM.nonce,
			product_id: productId,
			overwrite: 0
		}).done(function(response) {
			if (!response.success) {
				$status.text(response.data && response.data.message ? response.data.message : PM.i18n.error).css('color', 'red');
				return;
			}
			$status.text(response.data.message || ('Updated ' + (response.data.updated || 0))).css('color', 'green');
			setTimeout(function() { window.location.reload(); }, 1000);
		}).fail(function() {
			$status.text(PM.i18n.error).css('color', 'red');
		});
	});

	$(document).on('click', '#intersoccer-pm-repair-facets-btn', function() {
		var $btn = $(this);
		var productId = $btn.data('product-id');
		var $status = $('#intersoccer-pm-schedule-tools-status');
		$status.text(PM.i18n.saving).css('color', '#666');
		$btn.prop('disabled', true);
		$.post(PM.ajax_url, {
			action: 'intersoccer_pm_repair_camp_facets',
			nonce: PM.nonce,
			product_id: productId
		}).done(function(response) {
			$btn.prop('disabled', false);
			if (!response.success) {
				$status.text(response.data && response.data.message ? response.data.message : PM.i18n.error).css('color', 'red');
				return;
			}
			$status.text(response.data.message || 'Repaired').css('color', 'green');
			setTimeout(function() { window.location.reload(); }, 1000);
		}).fail(function() {
			$btn.prop('disabled', false);
			$status.text(PM.i18n.error).css('color', 'red');
		});
	});

	// =========================================================================
	// Detail view: Sync all languages (WPML)
	// =========================================================================

	$(document).on('click', '#intersoccer-pm-sync-wpml-btn', function() {
		var $btn = $(this);
		var productId = $btn.data('product-id');
		var $status = $('#intersoccer-pm-sync-wpml-status');
		var confirmMsg = (PM.i18n && PM.i18n.confirm_sync_wpml)
			? PM.i18n.confirm_sync_wpml
			: 'Sync all languages (WPML)?';
		if (!window.confirm(confirmMsg)) {
			return;
		}
		$status.text((PM.i18n && PM.i18n.syncing_wpml) || 'Syncing…').css('color', '#666');
		$btn.prop('disabled', true);
		$.post(PM.ajax_url, {
			action: 'intersoccer_pm_sync_wpml_languages',
			nonce: PM.nonce,
			product_id: productId
		}).done(function(response) {
			$btn.prop('disabled', false);
			if (!response.success) {
				$status.text(response.data && response.data.message ? response.data.message : PM.i18n.error).css('color', 'red');
				return;
			}
			$status.text(response.data.message || 'Synced').css('color', 'green');
			setTimeout(function() { window.location.reload(); }, 1200);
		}).fail(function() {
			$btn.prop('disabled', false);
			$status.text(PM.i18n.error).css('color', 'red');
		});
	});

	// =========================================================================
	// Detail view: product status (draft / publish / private)
	// =========================================================================

	var PM_STATUS_LABELS = {
		draft: 'Draft',
		publish: 'Published',
		private: 'Private'
	};

	$(document).on('click', '#intersoccer-pm-save-status-btn', function() {
		var $btn = $(this);
		if ($btn.prop('disabled')) {
			return;
		}
		var productId = $btn.data('product-id');
		var status = $('#intersoccer-pm-detail-status').val() || '';
		var $msg = $('#intersoccer-pm-status-save-msg');

		$btn.prop('disabled', true);
		$msg.text(PM.i18n.saving).css('color', '#666');

		$.post(PM.ajax_url, {
			action: 'intersoccer_pm_quick_edit',
			nonce: PM.nonce,
			product_id: productId,
			status: status
		}).done(function(response) {
			if (response.success) {
				var saved = (response.data && response.data.status) ? response.data.status : status;
				var label = PM_STATUS_LABELS[saved] || saved;
				$('#intersoccer-pm-status-badge').text(label);
				$('#intersoccer-pm-detail-status').val(saved);
				$msg.text(PM.i18n.saved).css('color', 'green');
				setTimeout(function() {
					window.location.reload();
				}, 800);
			} else {
				$msg.text(response.data && response.data.message ? response.data.message : PM.i18n.error).css('color', 'red');
				$btn.prop('disabled', false);
			}
		}).fail(function() {
			$msg.text(PM.i18n.error).css('color', 'red');
			$btn.prop('disabled', false);
		});
	});

	// =========================================================================
	// List quick edit
	// =========================================================================

	$(document).on('click', '.intersoccer-pm-quick-edit-toggle', function() {
		var id = $(this).data('product-id');
		$('.intersoccer-pm-quick-edit').hide();
		$('.intersoccer-pm-quick-edit[data-product-id="' + id + '"]').show();
	});

	$(document).on('click', '.intersoccer-pm-quick-edit-cancel', function() {
		$(this).closest('.intersoccer-pm-quick-edit').hide();
	});

	$(document).on('click', '.intersoccer-pm-quick-edit-save', function() {
		var $btn = $(this);
		var productId = $btn.data('product-id');
		var $panel = $btn.closest('.intersoccer-pm-quick-edit');
		var $msg = $panel.find('.pm-qe-status-msg');
		var attrs = {
			'pa_program-season': $panel.find('.pm-qe-season').val() || [],
			'pa_intersoccer-venues': $panel.find('.pm-qe-venues').val() || []
		};
		if ($panel.data('type') === 'camp') {
			attrs['pa_camp-terms'] = $panel.find('.pm-qe-camp-terms').val() || [];
			attrs['pa_camp-times'] = $panel.find('.pm-qe-camp-times').val() || [];
		}
		if ($panel.data('type') === 'birthday') {
			attrs = {
				'pa_city': $panel.find('.pm-qe-city').val() || []
			};
		}

		$msg.text(PM.i18n.saving).css('color', '#666');
		$.post(PM.ajax_url, {
			action: 'intersoccer_pm_quick_edit',
			nonce: PM.nonce,
			product_id: productId,
			name: $panel.find('.pm-qe-name').val(),
			status: $panel.find('.pm-qe-status').val(),
			attrs: attrs
		}).done(function(response) {
			if (response.success) {
				$msg.text(PM.i18n.saved).css('color', 'green');
				setTimeout(function() { window.location.reload(); }, 800);
			} else {
				$msg.text(response.data && response.data.message ? response.data.message : PM.i18n.error).css('color', 'red');
			}
		}).fail(function() {
			$msg.text(PM.i18n.error).css('color', 'red');
		});
	});

	// =========================================================================
	// Utility
	// =========================================================================

	function escHtml(str) {
		var div = document.createElement('div');
		div.appendChild(document.createTextNode(str));
		return div.innerHTML;
	}

})(jQuery);
