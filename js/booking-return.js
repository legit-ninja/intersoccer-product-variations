/**
 * Keep the product, variation, camp days, and quantity on the login and add-player links.
 * The coupon, when present, comes only from the server-provided returnCoupon value.
 */
(function ($) {
    'use strict';

    function cleanToken(value) {
        var text = String(value || '').trim();
        if (!text || text.length > 80 || /[<>"'\\]/.test(text) || /[\r\n]/.test(text)) {
            return '';
        }
        return text;
    }

    function isBookingReturn() {
        try {
            return new URLSearchParams(window.location.search).get('bf_return') === '1';
        } catch (error) {
            return false;
        }
    }

    function serverCampDays() {
        var checkout = window.intersoccerCheckout || {};
        var raw = checkout.returnCampDays;
        if (!Array.isArray(raw) || !raw.length) {
            return [];
        }
        var days = [];
        raw.forEach(function (value) {
            var day = cleanToken(value);
            if (day && days.indexOf(day) === -1) {
                days.push(day);
            }
        });
        return days;
    }

    function collectCampDays() {
        var fromServer = serverCampDays();
        if (fromServer.length) {
            return fromServer;
        }
        var days = [];
        if (!isBookingReturn()) {
            return days;
        }
        try {
            var params = new URLSearchParams(window.location.search);
            params.getAll('camp_days[]').forEach(function (value) {
                var day = cleanToken(value);
                if (day && days.indexOf(day) === -1) {
                    days.push(day);
                }
            });
            if (!days.length) {
                params.forEach(function (value, key) {
                    if (key !== 'camp_days' && key.indexOf('camp_days[') !== 0) {
                        return;
                    }
                    String(value).split(',').forEach(function (part) {
                        var day = cleanToken(part);
                        if (day && days.indexOf(day) === -1) {
                            days.push(day);
                        }
                    });
                });
            }
        } catch (error) {
            return [];
        }
        return days;
    }

    window.intersoccerPvReturnCampDays = collectCampDays();
    window.intersoccerPvReturnDaysTouched = false;
    window.intersoccerPvTouchedAttributes = {};

    function serverCoupon() {
        var checkout = window.intersoccerCheckout || {};
        var code = checkout.returnCoupon ? String(checkout.returnCoupon) : '';
        if (!/^[A-Za-z0-9_-]+$/.test(code)) {
            return '';
        }
        return code;
    }

    function productBase() {
        var checkout = window.intersoccerCheckout || {};
        if (checkout.productPermalink) {
            return String(checkout.productPermalink);
        }
        return window.location.origin + window.location.pathname;
    }

    function formFor(anchor) {
        var $anchor = $(anchor);
        var $form = $anchor.closest('form');
        if ($form.length && !$form.hasClass('search_form')) {
            return $form;
        }
        return $('form.variations_form, form.cart').not('.search_form').first();
    }

    function buildReturnUrl($form) {
        var base = productBase();
        if (!base) {
            return '';
        }
        var params = new URLSearchParams();
        if ($form && $form.length) {
            $form.find('select, input').each(function () {
                var name = this.name || '';
                if (name.indexOf('attribute_') !== 0 || this.disabled) {
                    return;
                }
                var value = cleanToken($(this).val());
                if (value) {
                    params.set(name, value);
                }
            });
            var days = [];
            $form.find('.intersoccer-day-checkbox:checked').each(function () {
                var day = cleanToken(this.value);
                if (day && days.indexOf(day) === -1) {
                    days.push(day);
                }
            });
            if (!days.length) {
                $form.find('input[name="camp_days[]"]').each(function () {
                    var day = cleanToken(this.value);
                    if (day && days.indexOf(day) === -1) {
                        days.push(day);
                    }
                });
            }
            days.forEach(function (day) {
                params.append('camp_days[]', day);
            });
            var qty = $form.find('input[name="quantity"]').first().val();
            if (/^[1-9]\d*$/.test(String(qty || ''))) {
                params.set('quantity', String(qty));
            }
        }
        var coupon = serverCoupon();
        if (coupon) {
            params.set('coupon', coupon);
        }
        params.set('bf_return', '1');
        var query = params.toString();
        if (!query) {
            return base;
        }
        return base + (base.indexOf('?') === -1 ? '?' : '&') + query;
    }

    function hrefWithRedirect(href, returnUrl) {
        if (!href || !returnUrl) {
            return href;
        }
        try {
            var url = new URL(href, window.location.origin);
            url.searchParams.set('redirect', returnUrl);
            if (href.charAt(0) === '/' && href.charAt(1) !== '/') {
                return url.pathname + url.search + url.hash;
            }
            return url.toString();
        } catch (error) {
            return href;
        }
    }

    function refreshLink(anchor) {
        var $anchor = $(anchor);
        var returnUrl = buildReturnUrl(formFor(anchor));
        var next = hrefWithRedirect($anchor.attr('href'), returnUrl);
        if (next) {
            $anchor.attr('href', next);
        }
    }

    function seedCampDays($form) {
        var days = window.intersoccerPvReturnCampDays || [];
        if (!$form || !$form.length || window.intersoccerPvReturnDaysTouched || !days.length) {
            return;
        }
        window.intersoccerPvApplyingReturnDays = true;
        var existing = $form.find('input[name="camp_days[]"]').map(function () {
            return this.value;
        }).get();
        days.forEach(function (day) {
            if (existing.indexOf(day) === -1) {
                $form.append($('<input>', {
                    type: 'hidden',
                    name: 'camp_days[]',
                    value: day,
                    'class': 'intersoccer-camp-day-input'
                }));
            }
        });
        $form.find('.intersoccer-day-checkbox').each(function () {
            if (days.indexOf(this.value) !== -1) {
                this.checked = true;
            }
        });
        window.intersoccerPvApplyingReturnDays = false;
    }

    function mappedAttribute(key) {
        var checkout = window.intersoccerCheckout || {};
        var mapped = checkout.returnAttributes;
        if (!mapped || !Object.prototype.hasOwnProperty.call(mapped, key)) {
            return '';
        }
        return cleanToken(mapped[key]);
    }

    function optionExists($field, token) {
        var found = false;
        $field.find('option').each(function () {
            if (this.value === token) {
                found = true;
            }
        });
        return found;
    }

    function restoreAttributes($form) {
        if (!isBookingReturn() || !$form || !$form.length) {
            return;
        }
        var params;
        try {
            params = new URLSearchParams(window.location.search);
        } catch (error) {
            return;
        }
        window.intersoccerPvApplyingReturnAttrs = true;
        params.forEach(function (value, key) {
            if (key.indexOf('attribute_') !== 0 || window.intersoccerPvTouchedAttributes[key]) {
                return;
            }
            var raw = cleanToken(value);
            var token = mappedAttribute(key) || raw;
            if (!token) {
                return;
            }
            var $field = $form.find('select, input').filter(function () {
                return this.name === key;
            }).first();
            if (!$field.length || $field.val()) {
                return;
            }
            if ($field.is('select')) {
                var chosen = '';
                if (optionExists($field, token)) {
                    chosen = token;
                } else if (raw && raw !== token && optionExists($field, raw)) {
                    chosen = raw;
                }
                if (!chosen) {
                    return;
                }
                token = chosen;
            }
            $field.val(token).trigger('change');
        });
        window.intersoccerPvApplyingReturnAttrs = false;
    }

    function restoreQuantity($form) {
        if (!isBookingReturn() || !$form || !$form.length) {
            return;
        }
        var qty = '';
        try {
            qty = new URLSearchParams(window.location.search).get('quantity') || '';
        } catch (error) {
            return;
        }
        if (!/^[1-9]\d*$/.test(qty)) {
            return;
        }
        var $input = $form.find('input[name="quantity"]').first();
        if ($input.length && !$input.data('intersoccerReturnQtyTouched')) {
            $input.val(qty);
        }
    }

    if (!$) {
        return;
    }

    $(document).on('click focusin', 'a.intersoccer-booking-return-link', function () {
        refreshLink(this);
    });

    $(document).on('change', 'form.cart, form.variations_form', function () {
        var $form = $(this);
        $form.find('a.intersoccer-booking-return-link').each(function () {
            refreshLink(this);
        });
    });

    $(document).on('change', '.intersoccer-day-checkbox', function () {
        if (!window.intersoccerPvApplyingReturnDays) {
            window.intersoccerPvReturnDaysTouched = true;
        }
    });

    $(document).on('change', 'select[name^="attribute_"], input[name^="attribute_"]', function () {
        if (window.intersoccerPvApplyingReturnAttrs) {
            return;
        }
        window.intersoccerPvTouchedAttributes[this.name] = true;
    });

    $(document).on('change', 'input[name="quantity"]', function () {
        $(this).data('intersoccerReturnQtyTouched', true);
    });

    $(function () {
        window.intersoccerPvReturnCampDays = collectCampDays();
        var $forms = $('form.variations_form, form.cart').not('.search_form');
        $forms.each(function () {
            var $form = $(this);
            seedCampDays($form);
            restoreAttributes($form);
            seedCampDays($form);
            restoreQuantity($form);
            $form.find('a.intersoccer-booking-return-link').each(function () {
                refreshLink(this);
            });
        });
    });

    $(document.body).on('reset_data found_variation', 'form.variations_form', function () {
        var $form = $(this);
        window.intersoccerPvReturnCampDays = collectCampDays();
        seedCampDays($form);
        restoreAttributes($form);
        seedCampDays($form);
    });
})(window.jQuery);
