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
        
        foreach ($screens as $screen) {
            add_meta_box(
                'gfwc_invoice_box_ajax',
                __('Gestión de Factura PDF', 'gfwc'),
                [$this, 'render_metabox'],
                $screen,
                'side',
                'default'
            );

            add_meta_box(
                'gfwc_shipping_box_ajax',
                __('Notificación de Envío', 'gfwc'),
                [$this, 'render_shipping_metabox'],
                $screen,
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

    public function render_shipping_metabox($post_or_order_object) {
        $order = ($post_or_order_object instanceof WP_Post) ? wc_get_order($post_or_order_object->ID) : $post_or_order_object;
        $order_id = $order->get_id();
        
        $default_template = "Hola {nombre},\n\nNos complace informarte que tu pedido ha sido enviado. Puedes realizar el seguimiento de tu paquete utilizando los siguientes datos:\n\nNúmero de seguimiento: {nSeguimiento}\nEnlace de seguimiento: {lSeguimiento}\n\nGracias por confiar en nosotros.";
        $template = get_option('gfwc_shipping_template', $default_template);
        $tracking_num = $order->get_meta('_gfwc_tracking_number');
        $tracking_link = $order->get_meta('_gfwc_tracking_link');

        ?>
        <div id="gfwc-shipping-metabox-content">
            <p><strong><?php echo esc_html__('Plantilla del Mensaje:', 'gfwc'); ?></strong><br>
            <textarea id="gfwc-shipping-template" style="width:100%; height:100px;"><?php echo esc_textarea($template); ?></textarea></p>
            
            <p><strong><?php echo esc_html__('Nro Seguimiento:', 'gfwc'); ?></strong><br>
            <input type="text" id="gfwc-tracking-num" value="<?php echo esc_attr($tracking_num); ?>"></p>
            
            <p><strong><?php echo esc_html__('Link Seguimiento:', 'gfwc'); ?></strong><br>
            <input type="text" id="gfwc-tracking-link" value="<?php echo esc_attr($tracking_link); ?>">
            <a href="<?php echo esc_url($tracking_link); ?>" target="_blank" id="gfwc-tracking-link-preview" style="font-size: 11px; margin-left: 8px; text-decoration: none; display: <?php echo !empty($tracking_link) ? 'inline-block' : 'none'; ?>;">🔗 <?php echo esc_html__('Probar enlace', 'gfwc'); ?></a>
            </p>

            <button type="button" class="button button-primary" id="gfwc-send-shipping-btn" data-order-id="<?php echo esc_attr($order_id); ?>">
                <?php echo esc_html__('Enviar Notificación', 'gfwc'); ?>
            </button>
            <span class="spinner" style="float:none; vertical-align: middle; margin-left: 8px;"></span>
            <div id="gfwc-shipping-status-message" style="margin-top:10px; font-weight: bold;"></div>
            
            <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center;">
                <p style="font-size: 11px; color: #666; margin: 0;">
                    <?php echo esc_html__('Variables:', 'gfwc'); ?> {nombre}, {nSeguimiento}, {lSeguimiento}
                </p>
                <a href="#" id="gfwc-run-diagnostics-btn" data-order-id="<?php echo esc_attr($order_id); ?>" style="font-size: 11px; text-decoration: none; display: inline-flex; align-items: center; gap: 3px; color: #64748b;">
                    🔧 <?php echo esc_html__('Probar / Diagnóstico', 'gfwc'); ?>
                </a>
            </div>
            <div id="gfwc-diagnostics-result-wrapper"></div>
        </div>
        <?php
    }

    public function render_metabox_inner_content($order) {
        $order_id = $order->get_id();
        $file_path = $order->get_meta('_invoice_file_path');
        $sent = $order->get_meta('_invoice_sent');
        $upload_dir = wp_upload_dir();

        $file_url = '';
        if ($file_path && strpos($file_path, $upload_dir['basedir']) === 0) {
            $file_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $file_path);
            $file_url = str_replace('\\', '/', $file_url);
        }

        echo '<div class="gfwc-container">';

        if ($file_path && file_exists($file_path)) {
            $file_name = basename($file_path);
            ?>
            <div class="gfwc-invoice-card active">
                <div class="gfwc-card-header">
                    <span class="gfwc-icon-doc">📄</span>
                    <div class="gfwc-file-details">
                        <span class="gfwc-file-name" title="<?php echo esc_attr($file_name); ?>"><?php echo esc_html($file_name); ?></span>
                        <a href="<?php echo esc_url($file_url); ?>" target="_blank" class="gfwc-view-link">
                            <?php echo esc_html__('Ver factura', 'gfwc'); ?> ↗
                        </a>
                    </div>
                </div>

                <div class="gfwc-status-row">
                    <span class="gfwc-status-label"><?php echo esc_html__('Envío por correo:', 'gfwc'); ?></span>
                    <span class="gfwc-badge <?php echo $sent ? 'success' : 'failed'; ?>">
                        <?php echo $sent ? esc_html__('Enviado', 'gfwc') : esc_html__('No enviado', 'gfwc'); ?>
                    </span>
                </div>

                <div class="gfwc-actions-row">
                    <button type="button" class="button button-secondary gfwc-action-btn" id="gfwc-resend-btn" data-order-id="<?php echo esc_attr($order_id); ?>">
                        🔄 <?php echo esc_html__('Reenviar', 'gfwc'); ?>
                    </button>
                    <button type="button" class="button gfwc-action-btn delete" id="gfwc-delete-btn" data-order-id="<?php echo esc_attr($order_id); ?>">
                        🗑️ <?php echo esc_html__('Eliminar', 'gfwc'); ?>
                    </button>
                </div>
            </div>
            <div class="gfwc-divider"><span><?php echo esc_html__('Reemplazar factura', 'gfwc'); ?></span></div>
            <?php
        } else {
            ?>
            <div class="gfwc-invoice-card empty">
                <p class="gfwc-empty-text">ℹ️ <?php echo esc_html__('No se ha subido ninguna factura para este pedido.', 'gfwc'); ?></p>
            </div>
            <?php
        }
        ?>
        <div id="gfwc-form-wrapper">
            <div class="gfwc-file-upload-wrapper">
                <input type="file" id="gfwc-invoice-file" name="gfwc_invoice_file" accept="application/pdf" class="gfwc-file-input">
                <label for="gfwc-invoice-file" class="gfwc-file-label">
                    <span class="gfwc-upload-icon">📤</span>
                    <span class="gfwc-upload-text"><?php echo esc_html__('Seleccionar archivo PDF', 'gfwc'); ?></span>
                </label>
            </div>
            <button type="button" class="button button-primary gfwc-upload-btn-main" id="gfwc-upload-btn" data-order-id="<?php echo esc_attr($order_id); ?>">
                <?php echo esc_html__('Subir y Enviar Factura', 'gfwc'); ?>
            </button>
            <span class="spinner" style="float:none; vertical-align: middle; margin-left: 8px;"></span>
            <div id="gfwc-status-message" style="margin-top:10px; font-weight: bold;"></div>
        </div>
        </div>
        <?php
    }

    public function enqueue_scripts($hook) {
        $screen = get_current_screen();
        $screen_ids = ['shop_order', 'woocommerce_page_wc-orders'];
        
        // Cargar en pedidos tradicionales (post.php) y en HPOS (woocommerce_page_wc-orders)
        if (in_array($screen->id, $screen_ids)) {
            $plugin_url = plugin_dir_url(dirname(__FILE__)); // Subir un nivel desde includes/
            wp_enqueue_style('gfwc-admin-style', $plugin_url . 'gfwc-admin.css', [], '2.6.3');
            wp_enqueue_script('gfwc-ajax-script', $plugin_url . 'gfwc-script.js', ['jquery'], '2.6.3', true);
            wp_localize_script('gfwc-ajax-script', 'gfwc_ajax_object', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'i18n' => [
                    'select_pdf' => __('Por favor, selecciona un archivo PDF.', 'gfwc'),
                    'only_pdf' => __('Solo se permiten archivos PDF.', 'gfwc'),
                    'uploading' => __('Subiendo factura, por favor espera...', 'gfwc'),
                    'resending' => __('Reenviando factura, por favor espera...', 'gfwc'),
                    'deleting' => __('Eliminando factura, por favor espera...', 'gfwc'),
                    'confirm_delete' => __('¿Seguro que deseas eliminar la factura de este pedido? Esta acción no se puede deshacer.', 'gfwc'),
                    'comm_error' => __('Error de comunicación con el servidor', 'gfwc'),
                ]
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
        $columns['gfwc_invoice'] = __('Factura', 'gfwc');
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
                $shipping_sent = $order->get_meta('_gfwc_shipping_sent');
                
                $has_file = ($file && file_exists($file));
                
                echo '<div class="gfwc-column-icons">';
                
                // Icono 1: Factura Subida (PDF)
                if ($has_file) {
                    echo '<span class="dashicons dashicons-media-document" style="color: #ef4444;" title="' . esc_attr__('Factura subida', 'gfwc') . '"></span>';
                } else {
                    echo '<span class="dashicons dashicons-media-document" style="color: #cbd5e1;" title="' . esc_attr__('Sin factura subida', 'gfwc') . '"></span>';
                }
                
                // Icono 2: Envío de Factura por Email
                if ($has_file) {
                    if ($sent) {
                        echo '<span class="dashicons dashicons-email-alt" style="color: #10b981;" title="' . esc_attr__('Factura enviada al cliente', 'gfwc') . '"></span>';
                    } else {
                        echo '<span class="dashicons dashicons-email-alt" style="color: #f59e0b;" title="' . esc_attr__('Factura no enviada o con error', 'gfwc') . '"></span>';
                    }
                } else {
                    echo '<span class="dashicons dashicons-email-alt" style="color: #cbd5e1;" title="' . esc_attr__('Factura no disponible', 'gfwc') . '"></span>';
                }
                
                // Icono 3: Datos de Envío Enviados (Vehículo/Envío)
                if ($shipping_sent) {
                    echo '<span class="dashicons dashicons-car" style="color: #3b82f6;" title="' . esc_attr__('Datos de envío enviados al cliente', 'gfwc') . '"></span>';
                } else {
                    echo '<span class="dashicons dashicons-car" style="color: #cbd5e1;" title="' . esc_attr__('Datos de envío no enviados', 'gfwc') . '"></span>';
                }
                
                echo '</div>';
            } catch (Exception $e) {
                gfwc_log('Error en render_invoice_column: ' . $e->getMessage(), 'error');
                echo '—';
            }
        }
    }
}
