/**
 * GFWC AJAX Script
 * Optimized version with modern JS and internationalization support.
 */
(function($) {
    'use strict';

    $(function() {
        const $body = $('body');
        const i18n = gfwc_ajax_object.i18n;

        $body.on('click', '#gfwc-upload-btn', function(e) {
            e.preventDefault();

            const $button = $(this);
            const $spinner = $button.siblings('.spinner');
            const $status = $('#gfwc-status-message');
            const $fileInput = $('#gfwc-invoice-file');
            
            if (!$fileInput[0].files.length) {
                $status.css('color', 'red').text(i18n.select_pdf);
                return;
            }

            const file = $fileInput[0].files[0];
            if (file.type !== 'application/pdf') {
                $status.css('color', 'red').text(i18n.only_pdf);
                return;
            }

            // UI State: Loading
            $button.prop('disabled', true);
            $spinner.addClass('is-active');
            $status.css('color', 'inherit').text(i18n.uploading);

            const formData = new FormData();
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
                
                success: (response) => {
                    if (response.success) {
                        $status.css('color', 'green').text(response.data.message);
                        $('#gfwc-metabox-content').html(response.data.new_html);
                    } else {
                        $status.css('color', 'red').text(response.data.message || 'Error');
                        $button.prop('disabled', false);
                    }
                },
                
                error: (jqXHR) => {
                    let errorMessage = i18n.comm_error;
                    try {
                        const response = JSON.parse(jqXHR.responseText);
                        if (response.data && response.data.message) {
                            errorMessage = response.data.message;
                        }
                    } catch (e) {
                        console.error('GFWC Error parsing response:', e);
                    }
                    $status.css('color', 'red').text(errorMessage);
                    $button.prop('disabled', false);
                },

                complete: () => {
                    $spinner.removeClass('is-active');
                    $fileInput.val('');
                }
            });
        });

        // Shipping Notification Handler
        $body.on('click', '#gfwc-send-shipping-btn', function(e) {
            e.preventDefault();

            const $button = $(this);
            const $spinner = $button.siblings('.spinner');
            const $status = $('#gfwc-shipping-status-message');
            const template = $('#gfwc-shipping-template').val();
            const trackingNum = $('#gfwc-tracking-num').val().trim();
            const trackingLink = $('#gfwc-tracking-link').val().trim();

            if (!template) {
                $status.css('color', 'red').text('La plantilla no puede estar vacía.');
                return;
            }

            if (!trackingNum || !trackingLink) {
                $status.css('color', 'red').text('El número y el link de seguimiento son obligatorios.');
                return;
            }

            $button.prop('disabled', true);
            $spinner.addClass('is-active');
            $status.css('color', 'inherit').text('Enviando notificación...');

            $.ajax({
                url: gfwc_ajax_object.ajax_url,
                type: 'POST',
                data: {
                    action: 'gfwc_send_shipping_notification',
                    _gfwc_nonce: $('#_gfwc_nonce').val(),
                    order_id: $button.data('order-id'),
                    template: template,
                    tracking_num: trackingNum,
                    tracking_link: trackingLink
                },
                success: (response) => {
                    if (response.success) {
                        $status.css('color', 'green').text(response.data.message);
                    } else {
                        $status.css('color', 'red').text('Error: ' + response.data.message);
                    }
                },
                error: () => {
                    $status.css('color', 'red').text(i18n.comm_error);
                },
                complete: () => {
                    $button.prop('disabled', false);
                    $spinner.removeClass('is-active');
                }
            });
        });

        // Re-send Invoice Handler
        $body.on('click', '#gfwc-resend-btn', function(e) {
            e.preventDefault();

            const $button = $(this);
            const $container = $button.closest('.gfwc-container');
            const $status = $container.find('#gfwc-status-message');
            const $activeSpinner = $container.find('.spinner');

            $button.prop('disabled', true);
            $activeSpinner.addClass('is-active');
            $status.css('color', 'inherit').text(i18n.resending);

            $.ajax({
                url: gfwc_ajax_object.ajax_url,
                type: 'POST',
                data: {
                    action: 'gfwc_resend_invoice_email',
                    _gfwc_nonce: $('#_gfwc_nonce').val(),
                    order_id: $button.data('order-id')
                },
                success: (response) => {
                    if (response.success) {
                        $status.css('color', 'green').text(response.data.message);
                        $container.replaceWith(response.data.new_html);
                    } else {
                        $status.css('color', 'red').text('Error: ' + response.data.message);
                        $button.prop('disabled', false);
                    }
                },
                error: (jqXHR) => {
                    let errorMessage = i18n.comm_error;
                    try {
                        const response = JSON.parse(jqXHR.responseText);
                        if (response.data && response.data.message) {
                            errorMessage = response.data.message;
                        }
                    } catch (e) {
                        console.error('GFWC Error parsing response:', e);
                    }
                    $status.css('color', 'red').text(errorMessage);
                    $button.prop('disabled', false);
                },
                complete: () => {
                    $activeSpinner.removeClass('is-active');
                }
            });
        });

        // Delete Invoice Handler
        $body.on('click', '#gfwc-delete-btn', function(e) {
            e.preventDefault();

            const $button = $(this);
            if (!confirm(i18n.confirm_delete)) {
                return;
            }

            const $container = $button.closest('.gfwc-container');
            const $status = $container.find('#gfwc-status-message');
            const $activeSpinner = $container.find('.spinner');

            $button.prop('disabled', true);
            $activeSpinner.addClass('is-active');
            $status.css('color', 'inherit').text(i18n.deleting);

            $.ajax({
                url: gfwc_ajax_object.ajax_url,
                type: 'POST',
                data: {
                    action: 'gfwc_delete_invoice',
                    _gfwc_nonce: $('#_gfwc_nonce').val(),
                    order_id: $button.data('order-id')
                },
                success: (response) => {
                    if (response.success) {
                        $container.replaceWith(response.data.new_html);
                    } else {
                        $status.css('color', 'red').text('Error: ' + response.data.message);
                        $button.prop('disabled', false);
                    }
                },
                error: (jqXHR) => {
                    let errorMessage = i18n.comm_error;
                    try {
                        const response = JSON.parse(jqXHR.responseText);
                        if (response.data && response.data.message) {
                            errorMessage = response.data.message;
                        }
                    } catch (e) {
                        console.error('GFWC Error parsing response:', e);
                    }
                    $status.css('color', 'red').text(errorMessage);
                    $button.prop('disabled', false);
                },
                complete: () => {
                    $activeSpinner.removeClass('is-active');
                }
            });
        });

        // Run Diagnostics Handler
        $body.on('click', '#gfwc-run-diagnostics-btn', function(e) {
            e.preventDefault();

            const $button = $(this);
            const $container = $('#gfwc-shipping-metabox-content');
            const $spinner = $container.find('.spinner');
            const $status = $container.find('#gfwc-shipping-status-message');
            const $resultWrapper = $('#gfwc-diagnostics-result-wrapper');

            $button.css('pointer-events', 'none').css('opacity', '0.5');
            $spinner.addClass('is-active');
            $status.css('color', 'inherit').text('Ejecutando diagnóstico y prueba de correo...');
            $resultWrapper.html('');

            $.ajax({
                url: gfwc_ajax_object.ajax_url,
                type: 'POST',
                data: {
                    action: 'gfwc_run_diagnostics',
                    _gfwc_nonce: $('#_gfwc_nonce').val()
                },
                success: (response) => {
                    if (response.success) {
                        $status.css('color', 'green').text('Diagnóstico completado.');
                        $resultWrapper.html(response.data.html);
                    } else {
                        $status.css('color', 'red').text('Error: ' + response.data.message);
                    }
                },
                error: () => {
                    $status.css('color', 'red').text(i18n.comm_error);
                },
                complete: () => {
                    $button.css('pointer-events', 'auto').css('opacity', '1');
                    $spinner.removeClass('is-active');
                }
            });
        });

        // Update tracking link preview dynamically
        $body.on('input', '#gfwc-tracking-link', function() {
            const val = $(this).val().trim();
            const $preview = $('#gfwc-tracking-link-preview');
            if (val.startsWith('http://') || val.startsWith('https://')) {
                $preview.attr('href', val).css('display', 'inline-block');
            } else {
                $preview.css('display', 'none');
            }
        });
    });
})(jQuery);
