jQuery(document).ready(function($) {
    // Enhance date range picker if needed (for Event Rosters)
    if ($('#date_range').length) {
        // Add basic validation or use a library like flatpickr if included
        $('#date_range').on('change', function() {
            var value = $(this).val();
            if (value && !/^\d{4}-\d{2}-\d{2} to \d{4}-\d{2}-\d{2}$/.test(value)) {
                alert('Please use format: YYYY-MM-DD to YYYY-MM-DD');
                $(this).val('');
            }
        });
    }
});

