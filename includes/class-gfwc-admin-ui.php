<?php
if (!defined('ABSPATH')) exit;

class GFWC_Admin_UI {

    public function init() {
        add_action('add_meta_boxes', [$this, 'add_metabox']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
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

    public function render_metabox_inner_content($order) {
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
            $plugin_url = plugin_dir_url(dirname(__FILE__)); // Subir un nivel desde includes/
            wp_enqueue_script('gfwc-ajax-script', $plugin_url . 'gfwc-script.js', ['jquery'], '2.5.0', true);
            wp_localize_script('gfwc-ajax-script', 'gfwc_ajax_object', [
                'ajax_url' => admin_url('admin-ajax.php')
            ]);
        }
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
