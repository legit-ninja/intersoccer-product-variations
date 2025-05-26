jQuery(document).ready(function($) {
    $('#event-search').on('input', function() {
        var searchTerm = $(this).val().toLowerCase();
        $('.event-select option').each(function() {
            var optionText = $(this).text().toLowerCase();
            if (optionText.indexOf(searchTerm) !== -1 || $(this).val() === '') {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
    });
});
