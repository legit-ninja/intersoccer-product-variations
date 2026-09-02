/**
 * Show/hide campaign joining name + guardian email when a requires-group coupon is applied.
 * Persist cart values to session so Proceed to checkout can prefill.
 * Cosmetic toggle only — server-side checkout validation is authoritative.
 */
(function ($) {
    function cfg() {
        return window.intersoccerCampaignCheckout || {};
    }

    function groupCodes() {
        return cfg().groupCodes || [];
    }

    function appliedCouponCodes() {
        var codes = [];
        $('.woocommerce-remove-coupon').each(function () {
            var code = $(this).data('coupon');
            if (code) {
                codes.push(String(code).toLowerCase());
            }
        });
        return codes;
    }

    function requiresGroupField() {
        var required = groupCodes();
        if (!required.length) {
            return false;
        }
        var applied = appliedCouponCodes();
        for (var i = 0; i < required.length; i++) {
            if (applied.indexOf(required[i]) !== -1) {
                return true;
            }
        }
        return false;
    }

    function toggleJoiningField() {
        var $row = $('.intersoccer-campaign-joining');
        if (!$row.length) {
            return;
        }
        if (requiresGroupField()) {
            $row.show();
        } else {
            $row.hide();
        }
    }

    function joiningValues() {
        return {
            intersoccer_campaign_joining: $('input[name="intersoccer_campaign_joining"]').val() || '',
            intersoccer_campaign_joining_email: $('input[name="intersoccer_campaign_joining_email"]').val() || ''
        };
    }

    function persistJoining(done) {
        var settings = cfg();
        if (!settings.ajaxUrl) {
            if (done) {
                done();
            }
            return;
        }
        var payload = joiningValues();
        payload.action = 'intersoccer_save_campaign_joining';
        payload.nonce = settings.nonce || '';
        $.post(settings.ajaxUrl, payload).always(function () {
            if (done) {
                done();
            }
        });
    }

    var persistTimer;
    function schedulePersist() {
        clearTimeout(persistTimer);
        persistTimer = setTimeout(function () {
            persistJoining();
        }, 300);
    }

    $(function () {
        toggleJoiningField();
        $(document.body).on(
            'updated_checkout applied_coupon removed_coupon updated_wc_div',
            toggleJoiningField
        );
        $(document.body).on(
            'change blur',
            'input[name="intersoccer_campaign_joining"], input[name="intersoccer_campaign_joining_email"]',
            schedulePersist
        );
        $(document.body).on('click', 'a.checkout-button, a.wc-proceed-to-checkout', function (e) {
            var href = this.href;
            if (!href || !cfg().ajaxUrl) {
                return;
            }
            e.preventDefault();
            persistJoining(function () {
                window.location = href;
            });
        });
    });
})(jQuery);
