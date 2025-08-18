<?php
/*
Plugin Name: Gestor de Facturas WooCommerce (AJAX v2.4.0)
Plugin URI: https://www.github.com/Charlyzeta
Description: Sube y envía facturas PDF desde el pedido sin recargar la página (Compatible con HPOS).
Version: 2.4.0
Author: Gerardo Maidana
License: GPLv2 or later
WC requires at least: 7.0
WC tested up to: 9.2
*/

if (!defined('ABSPATH')) exit;

// Declarar compatibilidad HPOS
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

add_action('plugins_loaded', function() {
    if (!class_exists('WooCommerce')) return;

    class Gestor_Facturas_WC_AJAX_V2 {

        private $max_file_size = 1 * 1024 * 1024; // 1MB

        public function __construct() {
            add_action('add_meta_boxes', [$this, 'add_metabox']);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
            add_action('wp_ajax_gfwc_upload_and_send_invoice', [$this, 'ajax_handler']);
            $this->add_admin_columns_hooks();
        }

        public function add_metabox($post_type) {
            $screens = ['shop_order', 'woocommerce_page_wc-orders'];
            if (in_array($post_type, $screens)) {
                add_meta_box(
                    'gfwc_invoice_box_ajax',
                    'Gestión de Factura PDF',
                    [$this, 'render_metabox'],
                    $post_type,
                    'side',
                    'default'
                );
            }
        }

        public function render_metabox($post_or_order_object) {
            $order = ($post_or_order_object instanceof WP_Post) ? wc_get_order($post_or_order_object->ID) : $post_or_order_object;
            wp_nonce_field('gfwc_ajax_nonce', '_gfwc_nonce');
            echo '<div id="gfwc-metabox-content">';
            $this->render_metabox_inner_content($order);
            echo '</div>';
        }

        private function render_metabox_inner_content($order) {
            $order_id = $order->get_id();
            $file_path = $order->get_meta('_invoice_file_path');
            $sent = $order->get_meta('_invoice_sent');

            if ($file_path && file_exists($file_path)) {
                echo '<p><strong>Factura actual:</strong><br>📄 ' . esc_html(basename($file_path)) . '</p>';
                echo '<p>✉️ Estado de envío: ' . ($sent ? '✅ Enviado' : '❌ No enviado o fallido') . '</p>';
                echo '<hr><p>Puedes reemplazarla subiendo un nuevo archivo.</p>';
            } else {
                echo '<p>No se ha subido ninguna factura para este pedido.</p>';
            }
            ?>
            <div id="gfwc-form-wrapper">
                <p><input type="file" id="gfwc-invoice-file" name="gfwc_invoice_file" accept="application/pdf" style="width:100%;"></p>
                <button type="button" class="button button-primary" id="gfwc-upload-btn" data-order-id="<?php echo esc_attr($order_id); ?>">
                    Subir y Enviar Factura
                </button>
                <span class="spinner" style="float:none; vertical-align: middle; margin-left: 8px;"></span>
                <div id="gfwc-status-message" style="margin-top:10px; font-weight: bold;"></div>
            </div>
            <?php
        }

        public function enqueue_scripts($hook) {
            $screen = get_current_screen();
            $screen_ids = ['shop_order', 'woocommerce_page_wc-orders'];
            
            if (in_array($screen->id, $screen_ids) && $hook === 'post.php') {
                $plugin_url = plugin_dir_url(__FILE__);
                wp_enqueue_script('gfwc-ajax-script', $plugin_url . 'gfwc-script.js', ['jquery'], '2.4.0', true);
                wp_localize_script('gfwc-ajax-script', 'gfwc_ajax_object', [
                    'ajax_url' => admin_url('admin-ajax.php')
                ]);
            }
        }

        public function ajax_handler() {
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

            $email_sent = $this->send_invoice_email($order, $target_path);

            $order->update_meta_data('_invoice_file_path', $target_path);
            $order->update_meta_data('_invoice_sent', $email_sent ? '1' : '0');
            $order->add_order_note(
                'Factura (' . $file_name . ') subida y ' . 
                ($email_sent ? 'enviada al cliente.' : 'no se pudo enviar (verificar configuración SMTP).')
            );
            $order->save();

            ob_start();
            $this->render_metabox_inner_content($order);
            $new_html = ob_get_clean();

            wp_send_json_success([
                'message' => 'Factura subida ' . ($email_sent ? 'y enviada correctamente.' : 'pero el envío falló.'),
                'new_html' => $new_html
            ]);
        }

        private function send_invoice_email($order, $file_path) {
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

        private function add_admin_columns_hooks() {
            add_filter('manage_woocommerce_page_wc-orders_columns', [$this, 'add_invoice_column']);
            add_filter('manage_edit-shop_order_columns', [$this, 'add_invoice_column']);
            add_action('manage_woocommerce_page_wc-orders_custom_column', [$this, 'render_invoice_column'], 10, 2);
            add_action('manage_shop_order_posts_custom_column', [$this, 'render_invoice_column'], 10, 2);
        }

        public function add_invoice_column($columns) {
            $columns['gfwc_invoice'] = 'Factura';
            return $columns;
        }

        public function render_invoice_column($column, $post_or_order_object) {
            if ($column === 'gfwc_invoice') {
                try {
                    $order = null;
                    
                    if (is_numeric($post_or_order_object)) {
                        $order = wc_get_order($post_or_order_object);
                    } elseif ($post_or_order_object instanceof WC_Order) {
                        $order = $post_or_order_object;
                    } elseif (isset($post_or_order_object->ID)) {
                        $order = wc_get_order($post_or_order_object->ID);
                    }
                    
                    if (!$order || !is_a($order, 'WC_Order')) {
                        echo '—';
                        return;
                    }
                    
                    $file = $order->get_meta('_invoice_file_path');
                    $sent = $order->get_meta('_invoice_sent');
                    
                    if ($file && file_exists($file)) {
                        echo '<span title="Subida">📤</span>✅ / <span title="Envío">✉️</span>' . ($sent ? '✅' : '❌');
                    } else {
                        echo '—';
                    }
                } catch (Exception $e) {
                    error_log('Error en render_invoice_column: ' . $e->getMessage());
                    echo '—';
                }
            }
        }
    }

    new Gestor_Facturas_WC_AJAX_V2();
});