<?php
/**
 * Plugin Name:          NeoPay para WooCommerce
 * Plugin URI:           https://www.soydiaz.com
 * Description:          Pasarela NeoNet NeoPay para WooCommerce por API REST con 3-D Secure (DDC + Step-Up): ambientes de pruebas y produccion, NeoCuotas, reversa automatica, anulacion al cancelar el pedido y comprobante de pago. Guarda el detalle de cada transaccion en el pedido y un log enmascarado (nunca la tarjeta completa).
 * Version:              1.0.0
 * Author:               Jonathan Diaz
 * Author URI:           https://www.soydiaz.com
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          neopay-woo
 * Domain Path:          /languages
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * WC requires at least: 7.0
 * WC tested up to:      9.6
 *
 * @package NeoPay_Woo
 */

defined( 'ABSPATH' ) || exit;

define( 'NEOPAY_WOO_VERSION', '1.0.0' );
define( 'NEOPAY_WOO_FILE', __FILE__ );
define( 'NEOPAY_WOO_PATH', plugin_dir_path( __FILE__ ) );
define( 'NEOPAY_WOO_URL', plugin_dir_url( __FILE__ ) );
define( 'NEOPAY_WOO_BASENAME', plugin_basename( __FILE__ ) );

require_once NEOPAY_WOO_PATH . 'includes/class-neopay-woo-install.php';

register_activation_hook( __FILE__, array( 'NeoPay_Woo_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'NeoPay_Woo_Install', 'deactivate' ) );

/**
 * Declara compatibilidad con HPOS y con los bloques de carrito/checkout.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			return;
		}
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', NEOPAY_WOO_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', NEOPAY_WOO_FILE, true );
	}
);

/**
 * Arranque del plugin.
 */
add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Payment_Gateway' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>';
					esc_html_e( 'NeoPay para WooCommerce requiere que WooCommerce este instalado y activo.', 'neopay-woo' );
					echo '</p></div>';
				}
			);
			return;
		}

		NeoPay_Woo_Install::maybe_upgrade();

		require_once NEOPAY_WOO_PATH . 'includes/class-neopay-woo.php';
		NeoPay_Woo::instance();
	},
	11
);

/**
 * Enlaces rapidos desde la lista de plugins.
 */
add_filter(
	'plugin_action_links_' . NEOPAY_WOO_BASENAME,
	static function ( $links ) {
		$extra = array(
			'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=neopay_woo' ) ) . '">' . esc_html__( 'Ajustes', 'neopay-woo' ) . '</a>',
			'<a href="' . esc_url( admin_url( 'admin.php?page=neopay-woo-logs' ) ) . '">' . esc_html__( 'Logs', 'neopay-woo' ) . '</a>',
		);
		return array_merge( $extra, $links );
	}
);
