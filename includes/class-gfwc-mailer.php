<?php
if (!defined('ABSPATH')) exit;

class GFWC_Mailer {

    public function send_shipping_email($order, $template, $tracking_num, $tracking_link) {
        $to = $order->get_billing_email();
        if (empty($to)) {
            gfwc_log(sprintf(__('No se pudo obtener el email del cliente para el pedido #%d', 'gfwc'), $order->get_id()), 'error');
            return false;
        }

        $subject = sprintf(__('Información de envío - Pedido #%s', 'gfwc'), $order->get_order_number());
        
        $tracking_html_link = sprintf(
            '<a href="%s" target="_blank" style="color: #2251d9; font-weight: 600; text-decoration: underline;">%s</a>',
            esc_url($tracking_link),
            esc_html($tracking_link)
        );

        // Reemplazo de variables
        $placeholders = [
            '{nombre}' => $order->get_billing_first_name(),
            '{nSeguimiento}' => $tracking_num,
            '{lSeguimiento}' => $tracking_html_link
        ];
        
        $message_content = str_replace(array_keys($placeholders), array_values($placeholders), $template);
        $message_content = nl2br($message_content);

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
        ];

        $body = $this->wrap_email_body(__('Información de Envío', 'gfwc'), $message_content);

        gfwc_log('Intentando enviar correo de notificación de envío a: ' . $to, 'info');
        $result = wp_mail($to, $subject, $body, $headers);
        if (!$result) {
            gfwc_log('Falló el envío del correo de notificación de envío.', 'error');
        }
        return $result;
    }

    public function send_invoice_email($order, $file_path) {
        if (!file_exists($file_path)) {
            gfwc_log('El archivo adjunto no existe: ' . $file_path, 'error');
            return false;
        }

        $to = $order->get_billing_email();
        if (empty($to)) {
            gfwc_log(sprintf(__('No se pudo obtener el email del cliente para el pedido #%d', 'gfwc'), $order->get_id()), 'error');
            return false;
        }

        add_filter('wp_mail_from_name', function() {
            return get_bloginfo('name');
        });

        $subject = sprintf(__('Factura de su pedido #%s', 'gfwc'), $order->get_order_number());
        $subject = apply_filters('gfwc_invoice_email_subject', $subject, $order);

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
        ];
        $headers = apply_filters('gfwc_invoice_email_headers', $headers, $order);

        ob_start();
        ?>
        <h2 style="color: #1e293b; margin-top: 0; margin-bottom: 20px; font-size: 20px; font-weight: 600;"><?php echo esc_html__('Hola', 'gfwc'); ?> <?php echo esc_html($order->get_billing_first_name()); ?>,</h2>
        <p style="color: #475569; font-size: 15px; line-height: 1.6; margin-bottom: 16px;"><?php echo esc_html__('Gracias por tu compra en nuestro sitio. Adjunto a este correo encontrarás la factura correspondiente a tu pedido.', 'gfwc'); ?></p>
        
        <!-- Order details card -->
        <table width="100%" border="0" cellspacing="0" cellpadding="0" style="background-color: #f8fafc; border-radius: 6px; padding: 20px; margin: 25px 0;">
            <tr>
                <td style="color: #1e293b; font-size: 16px; font-weight: 600; padding-bottom: 12px; border-bottom: 1px solid #e2e8f0;">
                    <?php echo esc_html__('Detalles del pedido', 'gfwc'); ?>
                </td>
            </tr>
            <tr>
                <td style="padding-top: 12px; color: #475569; font-size: 14px; line-height: 1.6;">
                    <strong><?php echo esc_html__('Pedido #', 'gfwc'); ?>:</strong> <?php echo esc_html($order->get_order_number()); ?><br>
                    <strong><?php echo esc_html__('Fecha', 'gfwc'); ?>:</strong> <?php echo esc_html(wc_format_datetime($order->get_date_created())); ?><br>
                    <strong><?php echo esc_html__('Total', 'gfwc'); ?>:</strong> <?php echo wp_kses_post($order->get_formatted_order_total()); ?>
                </td>
            </tr>
        </table>
        
        <p style="color: #475569; font-size: 15px; line-height: 1.6; margin-bottom: 24px;"><?php echo esc_html__('Si tienes alguna pregunta sobre tu factura o pedido, no dudes en responder a este correo.', 'gfwc'); ?></p>
        <?php
        $content = ob_get_clean();

        $body = $this->wrap_email_body(__('Factura de tu pedido', 'gfwc'), $content);
        $body = apply_filters('gfwc_invoice_email_body', $body, $order);

        $attachments = [$file_path];
        $attachments = apply_filters('gfwc_invoice_email_attachments', $attachments, $order, $file_path);

        gfwc_log('Intentando enviar email a: ' . $to, 'info');
        gfwc_log('Archivo adjunto: ' . $file_path, 'info');

        $result = wp_mail($to, $subject, $body, $headers, $attachments);
        
        if (!$result) {
            gfwc_log('Falló el envío del email. Último error: ' . print_r(error_get_last(), true), 'error');
        }

        return $result;
    }

    private function wrap_email_body($title, $content) {
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
            <title><?php echo esc_html($title); ?></title>
        </head>
        <body style="margin: 0; padding: 0; background-color: #f6f9fc; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
            <table width="100%" border="0" cellspacing="0" cellpadding="0" style="background-color: #f6f9fc; padding: 20px 0;">
                <tr>
                    <td align="center">
                        <table width="600" border="0" cellspacing="0" cellpadding="0" style="background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">
                            <!-- Header -->
                            <tr>
                                <td style="background-color: #0f172a; padding: 30px 40px; text-align: center;">
                                    <h1 style="color: #ffffff; margin: 0; font-size: 24px; font-weight: 700; letter-spacing: -0.025em;"><?php echo esc_html(get_bloginfo('name')); ?></h1>
                                </td>
                            </tr>
                            <!-- Body -->
                            <tr>
                                <td style="padding: 40px;">
                                    <div style="color: #475569; font-size: 15px; line-height: 1.6;">
                                        <?php echo $content; ?>
                                    </div>
                                    <p style="color: #64748b; font-size: 15px; line-height: 1.6; margin-top: 30px; margin-bottom: 0;">
                                        <?php echo esc_html__('Saludos cordiales,', 'gfwc'); ?><br>
                                        <strong><?php echo esc_html(get_bloginfo('name')); ?></strong>
                                    </p>
                                </td>
                            </tr>
                            <!-- Footer -->
                            <tr>
                                <td style="background-color: #f8fafc; padding: 20px 40px; border-top: 1px solid #e2e8f0; text-align: center; color: #94a3b8; font-size: 12px;">
                                    <p style="margin: 0;"><?php echo esc_html__('Este es un correo automático. Por favor, no respondas directamente a este mensaje.', 'gfwc'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
}
