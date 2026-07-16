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
	// Utility
	// =========================================================================

	function escHtml(str) {
		var div = document.createElement('div');
		div.appendChild(document.createTextNode(str));
		return div.innerHTML;
	}

})(jQuery);
