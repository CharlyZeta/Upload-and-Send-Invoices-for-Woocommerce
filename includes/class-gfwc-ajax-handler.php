<?php
if (!defined('ABSPATH')) exit;

class GFWC_Ajax_Handler {

    private $max_file_size = 1 * 1024 * 1024; // 1MB
    private $mailer;
    private $admin_ui;

    public function __construct() {
        $this->mailer = new GFWC_Mailer();
        $this->admin_ui = new GFWC_Admin_UI();
    }

    public function init() {
        add_action('wp_ajax_gfwc_upload_and_send_invoice', [$this, 'handle_upload']);
        add_action('wp_ajax_gfwc_send_shipping_notification', [$this, 'handle_shipping_notification']);
        add_action('wp_ajax_gfwc_resend_invoice_email', [$this, 'handle_resend_email']);
        add_action('wp_ajax_gfwc_delete_invoice', [$this, 'handle_delete_invoice']);
        add_action('wp_ajax_gfwc_run_diagnostics', [$this, 'handle_run_diagnostics']);
    }

    public function handle_shipping_notification() {
        check_ajax_referer('gfwc_ajax_nonce', '_gfwc_nonce');        
        if (!current_user_can('edit_shop_orders')) {
            gfwc_log('Intento de envío de notificación sin permisos suficientes.', 'warning');
            wp_send_json_error(['message' => __('No tienes permisos.', 'gfwc')], 403);
        }
        
        if (empty($_POST['order_id']) || empty($_POST['template']) || empty($_POST['tracking_num']) || empty($_POST['tracking_link'])) {
            wp_send_json_error(['message' => __('Faltan datos obligatorios (Plantilla, Nro o Link).', 'gfwc')], 400);
        }

        $order_id = absint($_POST['order_id']);
        $template = wp_kses_post(stripslashes($_POST['template']));
        $tracking_num = sanitize_text_field($_POST['tracking_num']);
        $tracking_link = esc_url_raw($_POST['tracking_link']);

        $order = wc_get_order($order_id);
        if (!$order) {
            gfwc_log(sprintf('Pedido #%d no encontrado al procesar notificación de envío.', $order_id), 'error');
            wp_send_json_error(['message' => __('Pedido no encontrado.', 'gfwc')], 404);
        }

        // Guardar plantilla como global por defecto
        update_option('gfwc_shipping_template', $template);

        // Guardar datos en el pedido (preventivo, antes de enviar correo)
        $order->update_meta_data('_gfwc_tracking_number', $tracking_num);
        $order->update_meta_data('_gfwc_tracking_link', $tracking_link);
        $order->save();
        
        gfwc_log(sprintf('Datos de seguimiento guardados preventivamente para el pedido #%d.', $order_id), 'info');

        // Enviar correo
        $email_sent = $this->mailer->send_shipping_email($order, $template, $tracking_num, $tracking_link);

        if ($email_sent) {
            $order->update_meta_data('_gfwc_shipping_sent', '1');
            $order->add_order_note(
                sprintf(
                    __('Notificación de envío enviada al cliente. Seguimiento: %s', 'gfwc'),
                    $tracking_num
                )
            );
            $order->save();
            gfwc_log(sprintf('Notificación de envío enviada y registrada para el pedido #%d.', $order_id), 'info');
            wp_send_json_success(['message' => __('Notificación enviada correctamente.', 'gfwc')]);
        } else {
            $order->add_order_note(
                sprintf(
                    __('Error al enviar correo de notificación. Los datos de seguimiento (%s) se guardaron en el pedido.', 'gfwc'),
                    $tracking_num
                )
            );
            $order->save();
            gfwc_log(sprintf('Falló el envío de correo de notificación para el pedido #%d. Los datos se guardaron.', $order_id), 'error');
            wp_send_json_error(['message' => __('Los datos de seguimiento se guardaron, pero ocurrió un error al enviar el correo. Revisa los logs de WooCommerce.', 'gfwc')]);
        }
    }

    public function handle_upload() {
        check_ajax_referer('gfwc_ajax_nonce', '_gfwc_nonce');
        
        if (!current_user_can('edit_shop_orders')) {
            gfwc_log('Intento de subida de factura sin permisos suficientes.', 'warning');
            wp_send_json_error(['message' => __('No tienes permisos.', 'gfwc')], 403);
        }
        
        if (!isset($_POST['order_id']) || empty($_FILES['invoice_file'])) {
            gfwc_log('Petición de subida de factura incompleta.', 'error');
            wp_send_json_error(['message' => __('Faltan datos en la petición.', 'gfwc')], 400);
        }

        $file = $_FILES['invoice_file'];
        $order_id = absint($_POST['order_id']);
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $upload_errors = [
                UPLOAD_ERR_INI_SIZE => __('El archivo excede el tamaño máximo permitido.', 'gfwc'),
                UPLOAD_ERR_FORM_SIZE => __('El archivo excede el tamaño máximo permitido.', 'gfwc'),
                UPLOAD_ERR_PARTIAL => __('El archivo solo se subió parcialmente.', 'gfwc'),
                UPLOAD_ERR_NO_FILE => __('No se seleccionó ningún archivo.', 'gfwc'),
                UPLOAD_ERR_NO_TMP_DIR => __('Falta la carpeta temporal.', 'gfwc'),
                UPLOAD_ERR_CANT_WRITE => __('No se pudo escribir el archivo en el disco.', 'gfwc'),
                UPLOAD_ERR_EXTENSION => __('Una extensión de PHP detuvo la subida del archivo.', 'gfwc')
            ];
            $err_msg = $upload_errors[$file['error']] ?? __('Error desconocido al subir el archivo.', 'gfwc');
            gfwc_log(sprintf('Error de subida PHP para el pedido #%d: %s', $order_id, $err_msg), 'error');
            wp_send_json_error(['message' => $err_msg], 400);
        }

        if ($file['size'] > $this->max_file_size) {
            gfwc_log(sprintf('El archivo subido para el pedido #%d excede el límite de 1MB.', $order_id), 'error');
            wp_send_json_error(['message' => __('El archivo es demasiado grande. Tamaño máximo: 1MB.', 'gfwc')], 400);
        }

        $filetype = wp_check_filetype($file['name']);
        if ($filetype['ext'] !== 'pdf' || $filetype['type'] !== 'application/pdf') {
            gfwc_log(sprintf('Formato de archivo inválido para el pedido #%d: %s', $order_id, $file['name']), 'error');
            wp_send_json_error(['message' => __('Solo se permiten archivos PDF.', 'gfwc')], 400);
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            gfwc_log(sprintf('Pedido #%d no encontrado durante la subida de factura.', $order_id), 'error');
            wp_send_json_error(['message' => __('Pedido no encontrado.', 'gfwc')], 404);
        }

        $upload_dir_info = wp_upload_dir();
        $target_dir = $upload_dir_info['basedir'] . '/gfwc-invoices/' . date('Y') . '/' . date('m') . '/';
        
        if (!file_exists($target_dir)) {
            if (!wp_mkdir_p($target_dir)) {
                gfwc_log(sprintf('No se pudo crear el directorio de facturas: %s', $target_dir), 'error');
                wp_send_json_error(['message' => __('No se pudo crear el directorio de destino.', 'gfwc')], 500);
            }
        }

        if (!is_writable($target_dir)) {
            gfwc_log(sprintf('El directorio de facturas no tiene permisos de escritura: %s', $target_dir), 'error');
            wp_send_json_error(['message' => __('El directorio de destino no tiene permisos de escritura.', 'gfwc')], 500);
        }

        $file_name = 'factura-pedido-' . $order_id . '-' . time() . '.pdf';
        $target_path = $target_dir . $file_name;

        if (!move_uploaded_file($file['tmp_name'], $target_path)) {
            gfwc_log(sprintf('move_uploaded_file falló para el pedido #%d al mover a %s', $order_id, $target_path), 'error');
            wp_send_json_error(['message' => __('Error al mover el archivo. Revisa los permisos del servidor.', 'gfwc')], 500);
        }

        // Usar la clase Mailer para enviar el correo
        $email_sent = $this->mailer->send_invoice_email($order, $target_path);

        $order->update_meta_data('_invoice_file_path', $target_path);
        $order->update_meta_data('_invoice_sent', $email_sent ? '1' : '0');
        $order->add_order_note(
            sprintf(
                __('Factura (%s) subida y %s', 'gfwc'),
                $file_name,
                $email_sent ? __('enviada al cliente.', 'gfwc') : __('no se pudo enviar (verificar configuración SMTP).', 'gfwc')
            )
        );
        $order->save();
        gfwc_log(sprintf('Factura subida y guardada para el pedido #%d. Estado de envío de correo: %s', $order_id, $email_sent ? 'Enviado' : 'Fallo'), $email_sent ? 'info' : 'error');

        ob_start();
        $this->admin_ui->render_metabox_inner_content($order);
        $new_html = ob_get_clean();

        wp_send_json_success([
            'message' => $email_sent ? __('Factura subida y enviada correctamente.', 'gfwc') : __('Factura subida pero el envío falló.', 'gfwc'),
            'new_html' => $new_html
        ]);
    }

    public function handle_resend_email() {
        check_ajax_referer('gfwc_ajax_nonce', '_gfwc_nonce');
        
        if (!current_user_can('edit_shop_orders')) {
            gfwc_log('Intento de reenvío de factura sin permisos.', 'warning');
            wp_send_json_error(['message' => __('No tienes permisos.', 'gfwc')], 403);
        }
        
        if (empty($_POST['order_id'])) {
            wp_send_json_error(['message' => __('Faltan datos en la petición.', 'gfwc')], 400);
        }

        $order_id = absint($_POST['order_id']);
        $order = wc_get_order($order_id);
        if (!$order) {
            gfwc_log(sprintf('Pedido #%d no encontrado durante el reenvío.', $order_id), 'error');
            wp_send_json_error(['message' => __('Pedido no encontrado.', 'gfwc')], 404);
        }

        $file_path = $order->get_meta('_invoice_file_path');
        if (empty($file_path) || !file_exists($file_path)) {
            gfwc_log(sprintf('Intento de reenvío de factura inexistente para el pedido #%d.', $order_id), 'error');
            wp_send_json_error(['message' => __('No hay ninguna factura subida para reenviar.', 'gfwc')], 400);
        }

        $email_sent = $this->mailer->send_invoice_email($order, $file_path);
        
        $order->update_meta_data('_invoice_sent', $email_sent ? '1' : '0');
        $order->add_order_note(
            sprintf(
                __('Factura (%s) reenviada por correo al cliente. %s', 'gfwc'),
                basename($file_path),
                $email_sent ? __('Envío exitoso.', 'gfwc') : __('Falló el envío.', 'gfwc')
            )
        );
        $order->save();
        gfwc_log(sprintf('Reenvío de factura para el pedido #%d completado. Estado: %s', $order_id, $email_sent ? 'Exitoso' : 'Falló'), $email_sent ? 'info' : 'error');

        ob_start();
        $this->admin_ui->render_metabox_inner_content($order);
        $new_html = ob_get_clean();

        if ($email_sent) {
            wp_send_json_success([
                'message' => __('Factura reenviada correctamente.', 'gfwc'),
                'new_html' => $new_html
            ]);
        } else {
            wp_send_json_error([
                'message' => __('Falló el reenvío del correo. Verifica la configuración de SMTP.', 'gfwc'),
                'new_html' => $new_html
            ]);
        }
    }

    public function handle_delete_invoice() {
        check_ajax_referer('gfwc_ajax_nonce', '_gfwc_nonce');
        
        if (!current_user_can('edit_shop_orders')) {
            gfwc_log('Intento de eliminación de factura sin permisos.', 'warning');
            wp_send_json_error(['message' => __('No tienes permisos.', 'gfwc')], 403);
        }
        
        if (empty($_POST['order_id'])) {
            wp_send_json_error(['message' => __('Faltan datos en la petición.', 'gfwc')], 400);
        }

        $order_id = absint($_POST['order_id']);
        $order = wc_get_order($order_id);
        if (!$order) {
            gfwc_log(sprintf('Pedido #%d no encontrado al eliminar factura.', $order_id), 'error');
            wp_send_json_error(['message' => __('Pedido no encontrado.', 'gfwc')], 404);
        }

        $file_path = $order->get_meta('_invoice_file_path');
        
        // Eliminar archivo físico si existe
        if (!empty($file_path) && file_exists($file_path)) {
            wp_delete_file($file_path);
            gfwc_log(sprintf('Archivo físico de factura eliminado para el pedido #%d: %s', $order_id, $file_path), 'info');
        }

        // Eliminar metadatos
        $order->delete_meta_data('_invoice_file_path');
        $order->delete_meta_data('_invoice_sent');
        
        $order->add_order_note(
            sprintf(
                __('Factura (%s) eliminada del pedido por el administrador.', 'gfwc'),
                basename($file_path)
            )
        );
        $order->save();
        gfwc_log(sprintf('Factura y metadatos eliminados del pedido #%d.', $order_id), 'info');

        ob_start();
        $this->admin_ui->render_metabox_inner_content($order);
        $new_html = ob_get_clean();

        wp_send_json_success([
            'message' => __('Factura eliminada correctamente.', 'gfwc'),
            'new_html' => $new_html
        ]);
    }

    public function handle_run_diagnostics() {
        check_ajax_referer('gfwc_ajax_nonce', '_gfwc_nonce');        
        if (!current_user_can('edit_shop_orders')) {
            gfwc_log('Intento de ejecución de diagnóstico sin permisos.', 'warning');
            wp_send_json_error(['message' => __('No tienes permisos.', 'gfwc')], 403);
        }

        $results = [];

        // 1. PHP Version
        $php_version = PHP_VERSION;
        $php_ok = version_compare($php_version, '7.4', '>=');
        $results[] = [
            'label' => __('Versión de PHP', 'gfwc'),
            'value' => $php_version . ($php_ok ? ' (✓ OK)' : ' (✗ Requerido 7.4+)'),
            'status' => $php_ok ? 'success' : 'error'
        ];

        // 2. WordPress Version
        global $wp_version;
        $results[] = [
            'label' => __('Versión de WordPress', 'gfwc'),
            'value' => $wp_version,
            'status' => 'success'
        ];

        // 3. WooCommerce Version
        $wc_version = class_exists('WooCommerce') ? WC()->version : 'No instalado';
        $results[] = [
            'label' => __('Versión de WooCommerce', 'gfwc'),
            'value' => $wc_version,
            'status' => class_exists('WooCommerce') ? 'success' : 'error'
        ];

        // 4. HPOS Status
        $hpos_active = false;
        if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil')) {
            $hpos_active = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        }
        $results[] = [
            'label' => __('Almacenamiento de pedidos (HPOS)', 'gfwc'),
            'value' => $hpos_active ? __('HPOS Activo (✓ OK)', 'gfwc') : __('Tablas tradicionales (Post-based)', 'gfwc'),
            'status' => 'success'
        ];

        // 5. Directorio de Facturas
        $upload_dir_info = wp_upload_dir();
        $target_dir = $upload_dir_info['basedir'] . '/gfwc-invoices/';
        $dir_exists = file_exists($target_dir);
        $dir_writable = is_writable($dir_exists ? $target_dir : dirname($target_dir));
        $results[] = [
            'label' => __('Directorio de Facturas', 'gfwc'),
            'value' => sprintf('%s (%s, %s)', $target_dir, $dir_exists ? 'Existe' : 'No existe', $dir_writable ? 'Escritura OK' : 'Sin permisos de escritura'),
            'status' => $dir_writable ? 'success' : 'error'
        ];

        // 6. Prueba de Envío de Correo (wp_mail)
        $current_user = wp_get_current_user();
        $to_email = $current_user->user_email;
        $subject = __('Prueba de Diagnóstico - Gestor de Facturas HPOS', 'gfwc');
        $body = sprintf(__('Hola %s, este es un correo de prueba generado por la herramienta de diagnóstico de Gestor de Facturas HPOS. Si has recibido este correo, significa que la función wp_mail() y tu configuración de correo/SMTP están funcionando correctamente.', 'gfwc'), $current_user->display_name);
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        $mail_error = '';
        $catch_mail_failed = function($error) use (&$mail_error) {
            if (is_wp_error($error)) {
                $mail_error = $error->get_error_message();
            } else {
                $mail_error = print_r($error, true);
            }
        };
        add_action('wp_mail_failed', $catch_mail_failed);

        gfwc_log(sprintf('Iniciando prueba de diagnóstico de correo a la dirección: %s', $to_email), 'info');
        $mail_sent = wp_mail($to_email, $subject, $body, $headers);
        remove_action('wp_mail_failed', $catch_mail_failed);

        if ($mail_sent) {
            $results[] = [
                'label' => sprintf(__('Envío de Prueba a %s', 'gfwc'), $to_email),
                'value' => __('Enviado correctamente (✓ OK). Revisa tu bandeja de entrada.', 'gfwc'),
                'status' => 'success'
            ];
            gfwc_log('Prueba de diagnóstico de correo completada con éxito.', 'info');
        } else {
            $err_msg = !empty($mail_error) ? $mail_error : __('Error desconocido del servidor de correo.', 'gfwc');
            $results[] = [
                'label' => sprintf(__('Envío de Prueba a %s', 'gfwc'), $to_email),
                'value' => sprintf(__('Falló (✗ Error): %s', 'gfwc'), $err_msg),
                'status' => 'error'
            ];
            gfwc_log('Falló la prueba de diagnóstico de correo. Error: ' . $err_msg, 'error');
        }

        // Render HTML del reporte para inyectar en el metabox
        ob_start();
        ?>
        <div class="gfwc-diagnostics-report" style="margin-top: 12px; padding: 10px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 12px;">
            <h4 style="margin: 0 0 8px 0; font-size: 13px; color: #1e293b; display: flex; align-items: center; justify-content: space-between;">
                <span>📋 <?php echo esc_html__('Resultado del Diagnóstico', 'gfwc'); ?></span>
                <span style="font-weight: normal; font-size: 11px; color: #64748b;"><?php echo esc_html(date_i18n('H:i:s')); ?></span>
            </h4>
            <ul style="margin: 0; padding: 0; list-style: none;">
                <?php foreach ($results as $res): ?>
                    <li style="margin-bottom: 6px; display: flex; flex-direction: column; border-bottom: 1px solid #f1f5f9; padding-bottom: 4px;">
                        <span style="font-weight: 600; color: #475569;"><?php echo esc_html($res['label']); ?>:</span>
                        <span style="color: <?php echo $res['status'] === 'success' ? '#16a34a' : '#dc2626'; ?>; font-family: monospace; font-size: 11px; margin-top: 2px; word-break: break-all;">
                            <?php echo esc_html($res['value']); ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p style="margin: 6px 0 0 0; font-size: 10px; color: #64748b; text-align: center;">
                <?php echo sprintf(esc_html__('Logs grabados en: WooCommerce > Estado > Logs (%s)', 'gfwc'), 'gestor-facturas-hpos'); ?>
            </p>
        </div>
        <?php
        $html = ob_get_clean();

        wp_send_json_success(['html' => $html]);
    }
}
