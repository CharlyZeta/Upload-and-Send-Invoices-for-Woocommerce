<?php
if (!defined('ABSPATH')) exit;

class GFWC_Mailer {

    public function send_invoice_email($order, $file_path) {
        if (!file_exists($file_path)) {
            error_log('El archivo adjunto no existe: ' . $file_path);
            return false;
        }

        $to = $order->get_billing_email();
        if (empty($to)) {
            error_log('No se pudo obtener el email del cliente para el pedido #' . $order->get_id());
            return false;
        }

        add_filter('wp_mail_from_name', function() {
            return get_bloginfo('name');
        });

        $subject = 'Factura de su pedido #' . $order->get_order_number();
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
        ];

        $body = $this->get_email_template($order);

        error_log('Intentando enviar email a: ' . $to);
        error_log('Archivo adjunto: ' . $file_path);

        $result = wp_mail($to, $subject, $body, $headers, [$file_path]);
        
        if (!$result) {
            error_log('Falló el envío del email. Último error: ' . print_r(error_get_last(), true));
        }

        return $result;
    }

    private function get_email_template($order) {
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
            <title><?php echo esc_html__('Factura de tu pedido', 'gfwc'); ?></title>
        </head>
        <body>
            <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
                <h2 style="color: #333;"><?php echo esc_html__('Hola', 'gfwc'); ?> <?php echo esc_html($order->get_billing_first_name()); ?>,</h2>
                <p><?php echo esc_html__('Gracias por tu compra en', 'gfwc'); ?> <?php echo esc_html(get_bloginfo('name')); ?>.</p>
                <p><?php echo esc_html__('Adjuntamos la factura de tu pedido #', 'gfwc'); ?> <?php echo esc_html($order->get_order_number()); ?>.</p>
                <h3 style="color: #333; margin-top: 20px;"><?php echo esc_html__('Detalles del pedido', 'gfwc'); ?></h3>
                <ul>
                    <li><strong><?php echo esc_html__('Fecha', 'gfwc'); ?>:</strong> <?php echo esc_html(wc_format_datetime($order->get_date_created())); ?></li>
                    <li><strong><?php echo esc_html__('Total', 'gfwc'); ?>:</strong> <?php echo wp_kses_post($order->get_formatted_order_total()); ?></li>
                </ul>
                <p><?php echo esc_html__('Si tienes alguna pregunta, no dudes en contactarnos.', 'gfwc'); ?></p>
                <p style="margin-top: 30px;">
                    <?php echo esc_html__('Saludos cordiales,', 'gfwc'); ?><br>
                    <?php echo esc_html(get_bloginfo('name')); ?>
                </p>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
}
