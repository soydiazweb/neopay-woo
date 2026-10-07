<?php
/**
 * Carga del plugin.
 *
 * @package NeoPay_Woo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class NeoPay_Woo
 */
final class NeoPay_Woo {

	/**
	 * Instancia.
	 *
	 * @var NeoPay_Woo|null
	 */
	protected static $instance = null;

	/**
	 * Singleton.
	 *
	 * @return NeoPay_Woo
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	protected function __construct() {
		require_once NEOPAY_WOO_PATH . 'includes/class-neopay-woo-helper.php';
		require_once NEOPAY_WOO_PATH . 'includes/class-neopay-woo-logger.php';
		require_once NEOPAY_WOO_PATH . 'includes/class-neopay-woo-api.php';
		require_once NEOPAY_WOO_PATH . 'includes/class-neopay-woo-address.php';
		require_once NEOPAY_WOO_PATH . 'includes/class-neopay-woo-gateway.php';
		require_once NEOPAY_WOO_PATH . 'includes/class-neopay-woo-3ds.php';
		require_once NEOPAY_WOO_PATH . 'includes/class-neopay-woo-voucher.php';
		require_once NEOPAY_WOO_PATH . 'includes/class-neopay-woo-void.php';

		new NeoPay_Woo_3DS();
		new NeoPay_Woo_Voucher();
		new NeoPay_Woo_Void();

		if ( is_admin() ) {
			require_once NEOPAY_WOO_PATH . 'includes/class-neopay-woo-order-admin.php';
			require_once NEOPAY_WOO_PATH . 'includes/class-neopay-woo-logs-page.php';
			new NeoPay_Woo_Order_Admin();
			new NeoPay_Woo_Logs_Page();
		}

		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );
		add_action( 'woocommerce_blocks_payment_method_type_registration', array( $this, 'register_blocks' ) );
		add_action( NeoPay_Woo_Install::CRON_HOOK, array( 'NeoPay_Woo_Logger', 'purge' ) );
		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Traducciones.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'neopay-woo', false, dirname( NEOPAY_WOO_BASENAME ) . '/languages' );
	}

	/**
	 * Registra la pasarela.
	 *
	 * @param array $gateways Pasarelas.
	 * @return array
	 */
	public function register_gateway( $gateways ) {
		$gateways[] = 'NeoPay_Woo_Gateway';
		return $gateways;
	}

	/**
	 * Registra el metodo en el checkout por bloques.
	 *
	 * Se engancha directamente al registro de metodos: woocommerce_blocks_loaded
	 * ya se disparo cuando arranca este plugin (plugins_loaded, prioridad 11).
	 *
	 * @param \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry Registro.
	 */
	public function register_blocks( $registry ) {
		if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			return;
		}
		require_once NEOPAY_WOO_PATH . 'includes/class-neopay-woo-blocks.php';
		$registry->register( new NeoPay_Woo_Blocks() );
	}

	/**
	 * Instancia de la pasarela registrada.
	 *
	 * @return NeoPay_Woo_Gateway|null
	 */
	public static function gateway() {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return null;
		}
		$gateways = WC()->payment_gateways()->payment_gateways();
		return isset( $gateways[ NeoPay_Woo_Gateway::ID ] ) ? $gateways[ NeoPay_Woo_Gateway::ID ] : null;
	}
}
