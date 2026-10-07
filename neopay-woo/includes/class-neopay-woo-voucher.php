<?php
/**
 * Comprobante de pago (venta y anulacion), obligatorio para certificar con
 * NeoNet: se muestra en la pagina de gracias, en "Ver pedido", en los correos
 * y en una version imprimible.
 *
 * @package NeoPay_Woo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class NeoPay_Woo_Voucher
 */
class NeoPay_Woo_Voucher {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'order_details' ), 5 );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'email' ), 15, 4 );
	}

	/**
	 * Filas del comprobante.
	 *
	 * @param WC_Order $order Pedido.
	 * @param string   $type  sale|void.
	 * @return array
	 */
	public static function rows( $order, $type = 'sale' ) {
		$void   = 'void' === $type;
		$amount = (float) $order->get_total() * ( $void ? -1 : 1 );
		$time   = $void ? $order->get_meta( '_neopay_void_time' ) : $order->get_meta( '_neopay_transaction_time' );
		$cuotas = (int) $order->get_meta( '_neopay_installments' );

		$rows = array(
			__( 'Metodo de pago', 'neopay-woo' )             => 'NeoNet',
			__( 'Fecha y hora', 'neopay-woo' )               => $time ? mysql2date( 'd/m/Y H:i:s', $time ) : '',
			$void ? __( 'Monto anulado', 'neopay-woo' ) : __( 'Monto de la venta', 'neopay-woo' ) => wp_strip_all_tags( wc_price( $amount, array( 'currency' => $order->get_currency() ) ) ),
			__( 'Tarjetahabiente', 'neopay-woo' )            => (string) $order->get_meta( '_neopay_cardholder' ),
			__( 'Tarjeta', 'neopay-woo' )                    => trim( $order->get_meta( '_neopay_card_brand' ) . ' ' . NeoPay_Woo_Helper::voucher_pan( $order->get_meta( '_neopay_card_last4' ) ) ),
			__( 'No. de referencia', 'neopay-woo' )          => (string) $order->get_meta( $void ? '_neopay_void_reference_number' : '_neopay_reference_number' ),
			__( 'No. de autorizacion', 'neopay-woo' )        => (string) $order->get_meta( $void ? '_neopay_void_authorization_number' : '_neopay_authorization_number' ),
			__( 'Afiliacion', 'neopay-woo' )                 => (string) $order->get_meta( '_neopay_merchant' ),
			__( 'No. de auditoria', 'neopay-woo' )           => (string) $order->get_meta( $void ? '_neopay_void_audit_number' : '_neopay_audit_number' ),
		);

		if ( $cuotas > 1 ) {
			$rows[ __( 'Cantidad de cuotas', 'neopay-woo' ) ] = (string) $cuotas;
		}
		$rows[ __( 'Pedido', 'neopay-woo' ) ] = '#' . $order->get_order_number();

		return apply_filters( 'neopay_woo_voucher_rows', $rows, $order, $type );
	}

	/**
	 * Tipos de comprobante disponibles para un pedido.
	 *
	 * @param WC_Order $order Pedido.
	 * @return array
	 */
	public static function available_types( $order ) {
		if ( ! $order || NeoPay_Woo_Gateway::ID !== $order->get_payment_method() || ! $order->get_meta( '_neopay_authorization_number' ) ) {
			return array();
		}
		$types = array( 'sale' );
		if ( 'anulada' === $order->get_meta( '_neopay_status' ) ) {
			$types[] = 'void';
		}
		return $types;
	}

	/**
	 * HTML del comprobante.
	 *
	 * @param WC_Order $order Pedido.
	 * @param string   $type  sale|void.
	 * @param bool     $email Estilos en linea para correo.
	 * @return string
	 */
	public static function html( $order, $type = 'sale', $email = false ) {
		$title = 'void' === $type ? __( 'Comprobante de anulacion', 'neopay-woo' ) : __( 'Comprobante de pago', 'neopay-woo' );
		$th    = $email ? ' style="text-align:left;padding:6px 10px;border:1px solid #e5e5e5;width:45%;"' : '';
		$td    = $email ? ' style="text-align:left;padding:6px 10px;border:1px solid #e5e5e5;"' : '';

		$out  = '<section class="neopay-voucher neopay-voucher--' . esc_attr( $type ) . '">';
		$out .= '<h2' . ( $email ? ' style="margin:18px 0 8px;"' : '' ) . '>' . esc_html( $title ) . '</h2>';
		$out .= '<table class="shop_table neopay-voucher-table"' . ( $email ? ' cellspacing="0" cellpadding="6" style="width:100%;border-collapse:collapse;margin-bottom:12px;"' : '' ) . '><tbody>';
		foreach ( self::rows( $order, $type ) as $label => $value ) {
			if ( '' === (string) $value ) {
				continue;
			}
			$out .= '<tr><th' . $th . '>' . esc_html( $label ) . '</th><td' . $td . '>' . esc_html( $value ) . '</td></tr>';
		}
		$out .= '</tbody></table>';
		$out .= '<p class="neopay-voucher-legend"><strong>(01) ' . esc_html__( 'Pagado electronicamente', 'neopay-woo' ) . '</strong></p>';
		$out .= '</section>';

		return $out;
	}

	/**
	 * Texto plano para correos sin HTML.
	 *
	 * @param WC_Order $order Pedido.
	 * @param string   $type  sale|void.
	 * @return string
	 */
	public static function plain( $order, $type = 'sale' ) {
		$title = 'void' === $type ? __( 'COMPROBANTE DE ANULACION', 'neopay-woo' ) : __( 'COMPROBANTE DE PAGO', 'neopay-woo' );
		$out   = "\n" . $title . "\n";
		foreach ( self::rows( $order, $type ) as $label => $value ) {
			if ( '' !== (string) $value ) {
				$out .= $label . ': ' . $value . "\n";
			}
		}
		return $out . '(01) ' . __( 'Pagado electronicamente', 'neopay-woo' ) . "\n";
	}

	/**
	 * Pagina de gracias y "Ver pedido".
	 *
	 * @param WC_Order $order Pedido.
	 */
	public function order_details( $order ) {
		$types = self::available_types( $order );
		if ( ! $types ) {
			return;
		}
		foreach ( $types as $type ) {
			echo self::html( $order, $type ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<p><a class="button neopay-print" target="_blank" rel="noopener" href="' . esc_url( NeoPay_Woo_3DS::url( 'voucher', $order, array( 'type' => $type ) ) ) . '">' . esc_html__( 'Imprimir comprobante', 'neopay-woo' ) . '</a></p>';
		}
	}

	/**
	 * Correos al cliente.
	 *
	 * @param WC_Order $order         Pedido.
	 * @param bool     $sent_to_admin Para el administrador.
	 * @param bool     $plain_text    Texto plano.
	 * @param WC_Email $email         Correo.
	 */
	public function email( $order, $sent_to_admin = false, $plain_text = false, $email = null ) {
		$types = self::available_types( $order );
		if ( ! $types ) {
			return;
		}
		$id = $email && isset( $email->id ) ? $email->id : '';
		if ( ! in_array( $id, array( 'customer_processing_order', 'customer_completed_order', 'customer_on_hold_order', 'customer_invoice', 'new_order' ), true ) ) {
			return;
		}
		foreach ( $types as $type ) {
			echo $plain_text ? self::plain( $order, $type ) : self::html( $order, $type, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Version imprimible (?np=voucher&type=sale|void).
	 *
	 * @param WC_Order $order Pedido.
	 */
	public static function print_page( $order ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$type  = 'void' === sanitize_key( wp_unslash( $_GET['type'] ?? '' ) ) ? 'void' : 'sale';
		$types = self::available_types( $order );
		if ( ! in_array( $type, $types, true ) ) {
			status_header( 404 );
			exit;
		}

		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex, nofollow" />
<title><?php echo esc_html( get_bloginfo( 'name' ) ); ?></title>
<style>
body{font:14px/1.45 "Courier New",monospace;color:#000;background:#fff;margin:0}
.wrap{max-width:380px;margin:20px auto;padding:16px;border:1px dashed #999}
h1{font-size:16px;text-align:center;margin:0 0 4px}
h2{font-size:14px;text-align:center;margin:8px 0 12px;text-transform:uppercase}
table{width:100%;border-collapse:collapse}
th,td{text-align:left;padding:3px 0;vertical-align:top;font-weight:normal}
th{width:48%}
td{font-weight:bold}
.legend{text-align:center;margin-top:12px}
button{display:block;margin:14px auto;padding:8px 18px;font:inherit;cursor:pointer}
@media print{button{display:none}.wrap{border:0}}
</style>
</head>
<body>
<div class="wrap">
	<h1><?php echo esc_html( get_bloginfo( 'name' ) ); ?></h1>
	<?php echo self::html( $order, $type ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</div>
<button type="button" onclick="window.print()"><?php esc_html_e( 'Imprimir', 'neopay-woo' ); ?></button>
</body>
</html>
		<?php
		exit;
	}
}
