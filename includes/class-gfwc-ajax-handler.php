<?php
if (!defined('ABSPATH')) exit;

class GFWC_Ajax_Handler {

    private $max_file_size = 1 * 1024 * 1024; // 1MB
    private $mailer;
    private $admin_ui;

    public function __construct() {
        $this->mailer = new GFWC_Mailer();
        // Necesitamos Admin UI para renderizar el metabox actualizado
        $this->admin_ui = new GFWC_Admin_UI();
    }

    public function init() {
        add_action('wp_ajax_gfwc_upload_and_send_invoice', [$this, 'handle_upload']);
    }

    public function handle_upload() {
        check_ajax_referer('gfwc_ajax_nonce', '_gfwc_nonce');
        
        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(['message' => 'No tienes permisos.'], 403);
        }
        
        if (!isset($_POST['order_id']) || empty($_FILES['invoice_file'])) {
            wp_send_json_error(['message' => 'Faltan datos en la petición.'], 400);
        }

        $file = $_FILES['invoice_file'];
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $upload_errors = [
                UPLOAD_ERR_INI_SIZE => 'El archivo excede el tamaño máximo permitido.',
                UPLOAD_ERR_FORM_SIZE => 'El archivo excede el tamaño máximo permitido.',
                UPLOAD_ERR_PARTIAL => 'El archivo solo se subió parcialmente.',
                UPLOAD_ERR_NO_FILE => 'No se seleccionó ningún archivo.',
                UPLOAD_ERR_NO_TMP_DIR => 'Falta la carpeta temporal.',
                UPLOAD_ERR_CANT_WRITE => 'No se pudo escribir el archivo en el disco.',
                UPLOAD_ERR_EXTENSION => 'Una extensión de PHP detuvo la subida del archivo.'
            ];
            wp_send_json_error(['message' => $upload_errors[$file['error']] ?? 'Error desconocido al subir el archivo.'], 400);
        }

        if ($file['size'] > $this->max_file_size) {
            wp_send_json_error(['message' => 'El archivo es demasiado grande. Tamaño máximo: 1MB.'], 400);
        }

        $filetype = wp_check_filetype($file['name']);
        if ($filetype['ext'] !== 'pdf' || $filetype['type'] !== 'application/pdf') {
            wp_send_json_error(['message' => 'Solo se permiten archivos PDF.'], 400);
        }

        $order_id = absint($_POST['order_id']);
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(['message' => 'Pedido no encontrado.'], 404);
        }

        $upload_dir_info = wp_upload_dir();
        $target_dir = $upload_dir_info['basedir'] . '/gfwc-invoices/' . date('Y') . '/' . date('m') . '/';
        
        if (!file_exists($target_dir)) {
            if (!wp_mkdir_p($target_dir)) {
                wp_send_json_error(['message' => 'No se pudo crear el directorio de destino.'], 500);
            }
        }

        if (!is_writable($target_dir)) {
            wp_send_json_error(['message' => 'El directorio de destino no tiene permisos de escritura.'], 500);
        }

        $file_name = 'factura-pedido-' . $order_id . '-' . time() . '.pdf';
        $target_path = $target_dir . $file_name;

        if (!move_uploaded_file($file['tmp_name'], $target_path)) {
            wp_send_json_error(['message' => 'Error al mover el archivo. Revisa los permisos del servidor.'], 500);
        }

        // Usar la clase Mailer para enviar el correo
        $email_sent = $this->mailer->send_invoice_email($order, $target_path);

        $order->update_meta_data('_invoice_file_path', $target_path);
        $order->update_meta_data('_invoice_sent', $email_sent ? '1' : '0');
        $order->add_order_note(
            'Factura (' . $file_name . ') subida y ' . 
            ($email_sent ? 'enviada al cliente.' : 'no se pudo enviar (verificar configuración SMTP).')
        );
        $order->save();

        ob_start();
        // Usar Admin UI para renderizar el contenido actualizado
        $this->admin_ui->render_metabox_inner_content($order);
        $new_html = ob_get_clean();

        wp_send_json_success([
            'message' => 'Factura subida ' . ($email_sent ? 'y enviada correctamente.' : 'pero el envío falló.'),
            'new_html' => $new_html
        ]);
    }
}
