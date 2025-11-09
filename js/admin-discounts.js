(function ($) {
    const config = window.IntersoccerDiscounts || null;
    if (!config) {
        return;
    }

    const root = $('#intersoccer-discount-app');
    if (!root.length) {
        return;
    }

    const typeOptions = config.types || [];
    const conditionOptions = config.conditions || [];
    const strings = config.strings || {};
    const labels = config.labels || {};

    let isDirty = false;

    function escapeHtml(string) {
        return String(string || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function generateId() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        return 'isc-' + Math.random().toString(36).slice(2, 11);
    }

    function buildOptions(options, currentValue) {
        let found = false;
        const optionsHtml = options.map((option) => {
            const selected = option.value === currentValue ? ' selected' : '';
            if (selected) {
                found = true;
            }
            return `<option value="${escapeHtml(option.value)}"${selected}>${escapeHtml(option.label)}</option>`;
        }).join('');

        if (!found && currentValue && currentValue !== '') {
            return `<option value="${escapeHtml(currentValue)}" selected>${escapeHtml(currentValue)}</option>` + optionsHtml;
        }

        return optionsHtml;
    }

    function createRow(rule) {
        const id = escapeHtml(rule.id || generateId());
        const name = escapeHtml(rule.name || '');
        const type = rule.type || 'general';
        const condition = rule.condition || 'none';
        const rateValue = rule.rate !== undefined && rule.rate !== null ? Number(rule.rate) : 0;
        const rate = Number.isFinite(rateValue) ? rateValue : 0;
        const active = rule.active !== undefined ? !!rule.active : true;

        const typeOptionsHtml = buildOptions(typeOptions, type);
        const conditionOptionsHtml = buildOptions(conditionOptions, condition);

        return `
            <tr data-id="${id}">
                <td>
                    <input type="text" class="regular-text isc-field isc-field-name" value="${name}" placeholder="${escapeHtml(strings.namePlaceholder || '')}" />
                </td>
                <td>
                    <select class="isc-field isc-field-type">
                        ${typeOptionsHtml}
                    </select>
                </td>
                <td>
                    <select class="isc-field isc-field-condition">
                        ${conditionOptionsHtml}
                    </select>
                </td>
                <td>
                    <input type="number" class="isc-field isc-field-rate" min="0" max="100" step="0.1" value="${rate}" />
                </td>
                <td class="isc-col-active">
                    <label>
                        <input type="checkbox" class="isc-field isc-field-active" ${active ? 'checked' : ''} />
                        <span>${escapeHtml(strings.activeLabel || labels.active || 'Active')}</span>
                    </label>
                </td>
                <td class="isc-actions">
                    <button type="button" class="button button-link-delete isc-remove">${escapeHtml(strings.remove || 'Remove')}</button>
                </td>
            </tr>
        `;
    }

    function renderApp(rules) {
        const hasRules = Array.isArray(rules) && rules.length > 0;
        const rows = hasRules ? rules.map(createRow).join('') : `<tr class="isc-empty"><td colspan="6">${escapeHtml(strings.empty || '')}</td></tr>`;

        const table = `
            <table class="wp-list-table widefat fixed striped isc-discount-table">
                <thead>
                    <tr>
                        <th scope="col">${escapeHtml(labels.name || 'Name')}</th>
                        <th scope="col">${escapeHtml(labels.type || 'Type')}</th>
                        <th scope="col">${escapeHtml(labels.condition || 'Condition')}</th>
                        <th scope="col">${escapeHtml(labels.rate || 'Discount Rate (%)')}</th>
                        <th scope="col">${escapeHtml(labels.active || 'Active')}</th>
                        <th scope="col">${escapeHtml(labels.actions || 'Actions')}</th>
                    </tr>
                </thead>
                <tbody>
                    ${rows}
                </tbody>
            </table>
        `;

        root.html(`
            <div class="isc-discount-inner">
                <div class="notice" data-role="notice" aria-live="polite"></div>
                ${table}
                <div class="intersoccer-discount-toolbar">
                    <button type="button" class="button isc-btn-add">${escapeHtml(strings.add || 'Add Discount')}</button>
                    <button type="button" class="button button-primary isc-btn-save">${escapeHtml(strings.save || 'Save Changes')}</button>
                </div>
            </div>
        `);
        updateDirty(false);
    }

    function showNotice(message, type = 'info') {
        const $notice = root.find('[data-role="notice"]');
        const classes = ['notice'];
        if (type === 'success') {
            classes.push('notice-success');
        } else if (type === 'error') {
            classes.push('notice-error');
        } else {
            classes.push('notice-info');
        }
        $notice.attr('class', classes.join(' ') + ' is-visible');
        $notice.text(message);
    }

    function clearNotice() {
        const $notice = root.find('[data-role="notice"]');
        $notice.removeClass('is-visible notice-success notice-error notice-info');
        $notice.text('');
    }

    function updateDirty(dirty) {
        if (dirty) {
            if (!isDirty) {
                showNotice(strings.unsavedChanges || 'You have unsaved changes.', 'info');
            }
            isDirty = true;
            return;
        }
        isDirty = false;
        clearNotice();
    }

    function ensureNotEmptyRow() {
        const $tbody = root.find('tbody');
        if ($tbody.find('tr').length === 0) {
            $tbody.append(`<tr class="isc-empty"><td colspan="6">${escapeHtml(strings.empty || '')}</td></tr>`);
        }
    }

    function appendRow(rule) {
        const $tbody = root.find('tbody');
        if ($tbody.find('.isc-empty').length) {
            $tbody.find('.isc-empty').remove();
        }
        $tbody.append(createRow(rule));
    }

    function gatherRulesFromDom() {
        const rules = [];
        root.find('tbody tr').each(function () {
            const $row = $(this);
            if ($row.hasClass('isc-empty')) {
                return;
            }
            const id = $row.data('id') || generateId();
            const name = $row.find('.isc-field-name').val().trim();
            const type = $row.find('.isc-field-type').val();
            const condition = $row.find('.isc-field-condition').val();
            const rate = parseFloat($row.find('.isc-field-rate').val());
            const active = $row.find('.isc-field-active').is(':checked');

            rules.push({
                id,
                name,
                type: type || 'general',
                condition: condition || 'none',
                rate: Number.isFinite(rate) ? rate : 0,
                active
            });
        });
        return rules;
    }

    function validateRules(rules) {
        if (!Array.isArray(rules) || rules.length === 0) {
            return { valid: true, errors: [] };
        }
        const errors = [];
        rules.forEach((rule, index) => {
            if (!rule.name || rule.name.trim() === '') {
                errors.push(strings.missingName || 'Each discount must have a name.');
                return;
            }
            if (!Number.isFinite(rule.rate) || rule.rate < 0 || rule.rate > 100) {
                errors.push(strings.invalidRate || 'Discount rates must be numbers between 0 and 100.');
            }
        });
        return { valid: errors.length === 0, errors };
    }

    function toggleSaving(isSaving) {
        const $saveButton = root.find('.isc-btn-save');
        $saveButton.prop('disabled', isSaving);
        if (isSaving) {
            showNotice(strings.saving || 'Saving…', 'info');
        }
    }

    function handleSave() {
        const rules = gatherRulesFromDom();
        const validation = validateRules(rules);
        if (!validation.valid) {
            showNotice(validation.errors[0], 'error');
            return;
        }

        toggleSaving(true);
        $.ajax({
            url: config.ajax_url,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'intersoccer_save_discount_rules',
                nonce: config.nonce,
                rules: JSON.stringify(rules)
            }
        }).done((response) => {
            if (response && response.success) {
                const newRules = response.data && response.data.rules ? response.data.rules : rules;
                renderApp(newRules);
                showNotice(strings.saveSuccess || 'Discount rules saved successfully.', 'success');
            } else {
                const message = response && response.data && response.data.message ? response.data.message : (strings.saveError || 'Unable to save discount rules. Please try again.');
                showNotice(message, 'error');
            }
        }).fail(() => {
            showNotice(strings.saveError || 'Unable to save discount rules. Please try again.', 'error');
        }).always(() => {
            toggleSaving(false);
        });
    }

    function loadRules() {
        root.html(`<p class="intersoccer-discount-loading">${escapeHtml(strings.loading || 'Loading…')}</p>`);
        $.ajax({
            url: config.ajax_url,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'intersoccer_get_discount_rules',
                nonce: config.nonce
            }
        }).done((response) => {
            if (response && response.success) {
                const rules = response.data && response.data.rules ? response.data.rules : [];
                renderApp(rules);
            } else {
                const message = response && response.data && response.data.message ? response.data.message : (strings.loadError || 'Unable to load discount rules.');
                root.html(`<div class="notice notice-error is-visible"><p>${escapeHtml(message)}</p></div>`);
            }
        }).fail(() => {
            root.html(`<div class="notice notice-error is-visible"><p>${escapeHtml(strings.loadError || 'Unable to load discount rules. Please refresh and try again.')}</p></div>`);
        });
    }

    root.on('click', '.isc-btn-add', (event) => {
        event.preventDefault();
        appendRow({ id: generateId(), name: '', type: 'general', condition: 'none', rate: 0, active: true });
        updateDirty(true);
    });

    root.on('click', '.isc-remove', function (event) {
        event.preventDefault();
        $(this).closest('tr').remove();
        ensureNotEmptyRow();
        updateDirty(true);
    });

    root.on('click', '.isc-btn-save', (event) => {
        event.preventDefault();
        handleSave();
    });

    root.on('input change', '.isc-field', () => {
        updateDirty(true);
    });

    loadRules();
})(jQuery);
