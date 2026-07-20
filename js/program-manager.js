/**
 * InterSoccer Program Manager — admin JS
 *
 * Handles wizard navigation, AJAX calls, inline price editing, and duplicate flow.
 */
(function($) {
	'use strict';

	var PM = window.intersoccerPM || {};
	var Matrix = window.intersoccerPMMatrix || {};

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
			buildMatrixPreview();
		}

		if (current === 3) {
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

	function showAttrFieldsForType(type) {
		$('.intersoccer-pm-attr-row').hide();
		$('.intersoccer-pm-attr-row').each(function() {
			var types = $(this).data('types').toString().split(',');
			if (types.indexOf(type) !== -1) {
				$(this).show();
			}
		});
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

		var $container = $('#intersoccer-pm-matrix-container');
		$container.empty();

		if (rows.length === 0) {
			$container.html('<p><em>' + 'No default variations for this type.' + '</em></p>');
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
		$container.html(html);
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

	function getSelectedAttrSlugs(taxonomy) {
		var $select = $('.intersoccer-pm-attr-row:visible select[data-taxonomy="' + taxonomy + '"]');
		if (!$select.length) {
			return [];
		}
		var vals = $select.val();
		if (!vals) {
			return [];
		}
		return Array.isArray(vals) ? vals.filter(Boolean) : [vals].filter(Boolean);
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
		var selectedRows = getSelectedMatrixRows();

		var attrs = [];
		$('.intersoccer-pm-attr-row:visible').each(function() {
			var label = $(this).find('th label').text().replace(' *', '');
			var $select = $(this).find('select');
			var val = $select.find('option:selected').map(function() {
				return $(this).text();
			}).get().join(', ');
			if (val && val !== '— Select —') {
				attrs.push(label + ': ' + val);
			}
		});

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
		var matrix = getSelectedMatrixRows();
		var parentAttrs = {};

		$('.intersoccer-pm-attr-row:visible select').each(function() {
			var taxonomy = $(this).data('taxonomy');
			var val = $(this).val();
			if (val && taxonomy) {
				parentAttrs[taxonomy] = Array.isArray(val) ? val : [val];
			}
		});

		$.post(PM.ajax_url, {
			action: 'intersoccer_pm_create_product',
			nonce: PM.nonce,
			name: name,
			program_type: type,
			parent_attrs: parentAttrs,
			matrix: matrix
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
	// Duplicate program
	// =========================================================================

	$(document).on('click', '#intersoccer-pm-duplicate-btn', function() {
		var $btn = $(this);
		if ($btn.prop('disabled')) return;

		if (!confirm(PM.i18n.confirm_duplicate)) return;

		var sourceId = $btn.data('source-id');
		var newName = $('#pm-dup-name').val().trim();
		var newSeason = $('#pm-dup-season').val();

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
			season: newSeason
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
