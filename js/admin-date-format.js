(function ($) {
    'use strict';

    function parse(input) {
        if (input === null || input === undefined) {
            return '';
        }
        var raw = String(input).trim();
        if (!raw) {
            return '';
        }

        function accept(year, month, day) {
            year = parseInt(year, 10);
            month = parseInt(month, 10);
            day = parseInt(day, 10);
            if (!year || year < 1000 || year > 9999 || month < 1 || month > 12 || day < 1 || day > 31) {
                return '';
            }
            var dt = new Date(year, month - 1, day);
            if (dt.getFullYear() !== year || dt.getMonth() !== month - 1 || dt.getDate() !== day) {
                return '';
            }
            return String(year).padStart(4, '0') + '-' + String(month).padStart(2, '0') + '-' + String(day).padStart(2, '0');
        }

        var yearFirst = raw.match(/^(\d{4})\s*[.\-\/]\s*(\d{1,2})\s*[.\-\/]\s*(\d{1,2})$/);
        if (yearFirst) {
            return accept(yearFirst[1], yearFirst[2], yearFirst[3]);
        }

        var swiss = raw.match(/^(\d{1,2})\s*[.\/]\s*(\d{1,2})\s*[.\/]\s*(\d{4})$/);
        if (swiss) {
            return accept(swiss[3], swiss[2], swiss[1]);
        }

        return '';
    }

    function format(input) {
        var ymd = parse(input);
        if (!ymd) {
            return '';
        }
        var parts = ymd.split('-');
        return parts[2] + '/' + parts[1] + '/' + parts[0];
    }

    function initInputs(context) {
        var $root = context ? $(context) : $(document);
        $root.find('input.intersoccer-admin-date').each(function () {
            var $el = $(this);
            if ($el.data('intersoccerAdminDateInit')) {
                return;
            }
            $el.data('intersoccerAdminDateInit', true);
            if (!$el.attr('placeholder')) {
                $el.attr('placeholder', 'DD/MM/YYYY');
            }
            if ($.fn.datepicker) {
                $el.datepicker({
                    dateFormat: 'dd/mm/yy',
                    changeMonth: true,
                    changeYear: true,
                    yearRange: '-10:+10',
                    constrainInput: false
                });
            }
            $el.on('blur', function () {
                var value = $.trim($el.val());
                if (!value) {
                    $el.removeClass('intersoccer-admin-date-invalid');
                    return;
                }
                var ymd = parse(value);
                if (ymd) {
                    $el.val(format(ymd)).removeClass('intersoccer-admin-date-invalid');
                } else {
                    $el.addClass('intersoccer-admin-date-invalid');
                }
            });
        });
    }

    window.InterSoccerAdminDate = {
        parse: parse,
        format: format,
        initInputs: initInputs
    };

    $(function () {
        initInputs(document);
    });

    $(document).on('woocommerce_variations_loaded woocommerce_variations_added', function (event) {
        initInputs(event.target || document);
    });
})(jQuery);
