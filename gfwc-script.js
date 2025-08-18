jQuery(document).ready(function($) {
    $('body').on('click', '#gfwc-upload-btn', function(e) {
        e.preventDefault();

        var $button = $(this);
        var $spinner = $button.siblings('.spinner');
        var $status = $('#gfwc-status-message');
        var $fileInput = $('#gfwc-invoice-file');
        
        if ($fileInput[0].files.length === 0) {
            $status.css('color', 'red').text('Por favor, selecciona un archivo PDF.');
            return;
        }

        var file = $fileInput[0].files[0];
        if (file.type !== 'application/pdf') {
            $status.css('color', 'red').text('Solo se permiten archivos PDF.');
            return;
        }

        $button.prop('disabled', true);
        $spinner.addClass('is-active');
        $status.css('color', 'inherit').text('Subiendo factura, por favor espera...');

        var formData = new FormData();
        formData.append('action', 'gfwc_upload_and_send_invoice');
        formData.append('_gfwc_nonce', $('#_gfwc_nonce').val());
        formData.append('order_id', $button.data('order-id'));
        formData.append('invoice_file', file);

        $.ajax({
            url: gfwc_ajax_object.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            
            success: function(response) {
                if (response.success) {
                    $status.css('color', 'green').text(response.data.message);
                    $('#gfwc-metabox-content').html(response.data.new_html);
                } else {
                    $status.css('color', 'red').text('Error: ' + response.data.message);
                    $button.prop('disabled', false);
                }
            },
            
            error: function(jqXHR) {
                var errorMessage = 'Error de comunicación con el servidor';
                try {
                    var response = JSON.parse(jqXHR.responseText);
                    if (response.data && response.data.message) {
                        errorMessage = response.data.message;
                    }
                } catch (e) {
                    console.error(e);
                }
                $status.css('color', 'red').text(errorMessage);
                $button.prop('disabled', false);
            },

            complete: function() {
                $spinner.removeClass('is-active');
                $fileInput.val('');
            }
        });
    });
});