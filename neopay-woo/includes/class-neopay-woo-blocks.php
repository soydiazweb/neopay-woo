<?php
/**
 * Integracion con el checkout por bloques.
 *
 * @package NeoPay_Woo
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Class NeoPay_Woo_Blocks
 */
class NeoPay_Woo_Blocks extends AbstractPaymentMethodType {

	/**
	 * Nombre del metodo.
	 *
	 * @var string
	 */
	protected $name = NeoPay_Woo_Gateway::ID;

	/**
	 * Ajustes.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_' . $this->name . '_settings', array() );
	}

	/**
	 * Activo.
	 *
	 * @return bool
	 */
	public function is_active() {
		$gateway = NeoPay_Woo::gateway();
		return $gateway ? $gateway->is_available() : false;
	}

	/**
	 * Script.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'neopay-woo-blocks',
			NEOPAY_WOO_URL . 'assets/js/blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			NEOPAY_WOO_VERSION,
			true
		);
		wp_register_style( 'neopay-woo', NEOPAY_WOO_URL . 'assets/css/checkout.css', array(), NEOPAY_WOO_VERSION );
		wp_enqueue_style( 'neopay-woo' );
		return array( 'neopay-woo-blocks' );
	}

	/**
	 * Datos para el cliente.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		$gateway = NeoPay_Woo::gateway();
		if ( ! $gateway ) {
			return array( 'title' => $this->get_setting( 'title' ) );
		}

		$total = WC()->cart ? (float) WC()->cart->get_total( 'edit' ) : 0.0;

		return array(
			'title'             => $gateway->get_title(),
			'description'       => $gateway->get_description(),
			'supports'          => array_values( array_filter( $gateway->supports, array( $gateway, 'supports' ) ) ),
			'is_test'           => 'test' === $gateway->get_environment(),
			'test_cards'        => $gateway->show_test_cards() ? NeoPay_Woo_Gateway::test_cards() : array(),
			'installments'      => $gateway->get_installment_choices( $total ),
			'installments_label' => $gateway->get_installments_label(),
			'installments_note' => $gateway->get_option( 'installments_note' ),
			'logos'             => array(
				'visa'       => NEOPAY_WOO_URL . 'assets/images/visa.svg',
				'mastercard' => NEOPAY_WOO_URL . 'assets/images/mastercard.svg',
			),
		);
	}
}
