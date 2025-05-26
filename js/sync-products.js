jQuery(document).ready(function($) {
    // Existing quick edit functionality
    $('.quick-edit-link').on('click', function(e) {
        e.preventDefault();
        var $row = $(this).closest('tr');
        var $quickEditRow = $row.next('.quick-edit-row');
        $quickEditRow.toggle();
    });

    $('.cancel-quick-edit').on('click', function() {
        $(this).closest('.quick-edit-row').hide();
    });

    $('.save-attributes').on('click', function() {
        var $row = $(this).closest('tr');
        var productId = $row.data('product-id');
        var isVariation = $row.data('is-variation');
        var attributes = {};

        $row.find('.attribute-field').each(function() {
            var attribute = $(this).data('attribute');
            var value = $(this).val();
            attributes[attribute] = value;
        });

        $.ajax({
            url: intersoccerSync.ajax_url,
            type: 'POST',
            data: {
                action: 'intersoccer_save_attributes',
                nonce: intersoccerSync.nonce,
                product_id: productId,
                is_variation: isVariation,
                attributes: attributes
            },
            success: function(response) {
                if (response.success) {
                    alert('Attributes saved successfully!');
                    $row.hide();
                } else {
                    alert('Error: ' + response.data.message);
                }
            },
            error: function() {
                alert('An error occurred while saving attributes.');
            }
        });
    });

    // Ensure variations functionality
    $('.ensure-variations').on('click', function(e) {
        e.preventDefault();
        var button = $(this);
        var productId = button.data('product-id');
        button.prop('disabled', true).text('Processing...');

        $.ajax({
            url: intersoccerSync.ajax_url,
            type: 'POST',
            data: {
                action: 'intersoccer_ensure_variations',
                nonce: intersoccerSync.nonce,
                product_id: productId
            },
            success: function(response) {
                if (response.success) {
                    var message = response.data.message + '\n\nCreated:\n' + (response.data.created.length ? response.data.created.join('\n') : 'None') + '\n\nSkipped:\n' + (response.data.skipped.length ? response.data.skipped.join('\n') : 'None');
                    alert(message);
                    button.text('Variations Ensured').addClass('button-primary');
                } else {
                    alert('Error: ' + response.data.message);
                    button.prop('disabled', false).text('Ensure Variations');
                }
            },
            error: function() {
                alert('An error occurred while ensuring variations.');
                button.prop('disabled', false).text('Ensure Variations');
            }
        });
    });

    // Select all products checkbox
    $('#select-all-products').on('change', function() {
        $('.product-checkbox').prop('checked', $(this).prop('checked'));
    });
});

