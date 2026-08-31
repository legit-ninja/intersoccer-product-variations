/**
 * Show/hide campaign joining name + guardian email when a requires-group coupon is applied.
 * Cosmetic only — server-side validation is authoritative.
 */
(function ($) {
    function groupCodes() {
        var cfg = window.intersoccerCampaignCheckout || {};
        return cfg.groupCodes || [];
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

    $(function () {
        toggleJoiningField();
        $(document.body).on('updated_checkout applied_coupon removed_coupon', toggleJoiningField);
    });
})(jQuery);
