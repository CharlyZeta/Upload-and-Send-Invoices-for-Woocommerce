<?php
/*
Plugin Name: Gestor de Facturas WooCommerce (AJAX v2.6.3)
Plugin URI: https://www.github.com/Charlyzeta/gestor-facturas-hpos
Description: Sube y envía facturas PDF desde el pedido sin recargar la página (Compatible con HPOS). Incluye notificaciones de envío personalizables.
Version: 2.6.3
Author: Gerardo Maidana
Author URI: https://linkedin.com/in/gerardo-maidana
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
WC requires at least: 7.0
WC tested up to: 9.2
*/

if (!defined('ABSPATH')) exit;

/**
 * Registra logs tanto en PHP error_log (con prefijo) como en el logger de WooCommerce.
 */
if (!function_exists('gfwc_log')) {
    function gfwc_log($message, $level = 'info') {
        error_log(sprintf('[Gestor Facturas HPOS] [%s] %s', strtoupper($level), $message));
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->log($level, $message, array('source' => 'gestor-facturas-hpos'));
        }
    }
}

// Declarar compatibilidad HPOS
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

add_action('plugins_loaded', function() {
    load_plugin_textdomain('gfwc', false, dirname(plugin_basename(__FILE__)) . '/languages');

    if (!class_exists('WooCommerce')) return;

    // Cargar clases
    require_once plugin_dir_path(__FILE__) . 'includes/class-gfwc-mailer.php';
    require_once plugin_dir_path(__FILE__) . 'includes/class-gfwc-admin-ui.php';
    require_once plugin_dir_path(__FILE__) . 'includes/class-gfwc-ajax-handler.php';

    // Inicializar componentes
    $admin_ui = new GFWC_Admin_UI();
    $admin_ui->init();

    $ajax_handler = new GFWC_Ajax_Handler();
    $ajax_handler->init();
});