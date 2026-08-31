(function ($) {
    $(function () {
        var $list = $('#intersoccer-campaign-offers-list');
        var $template = $('#intersoccer-campaign-offer-template');
        if (!$list.length || !$template.length) {
            return;
        }

        function nextIndex() {
            return $list.find('.intersoccer-campaign-offer-card').length;
        }

        $('#intersoccer-add-campaign-offer').on('click', function (e) {
            e.preventDefault();
            var html = $template.html().replace(/__INDEX__/g, String(nextIndex()));
            $list.append(html);
        });

        $list.on('click', '.intersoccer-remove-campaign-offer', function (e) {
            e.preventDefault();
            $(this).closest('.intersoccer-campaign-offer-card').remove();
        });
    });
})(jQuery);
