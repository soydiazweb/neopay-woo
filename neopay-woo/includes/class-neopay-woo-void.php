<?php
/**
 * Anulacion de ventas NeoPay.
 *
 *  - Se dispara al cambiar el pedido al estado "Cancelado" de WooCommerce.
 *  - Solo dentro de la ventana permitida: menos de 20 horas desde el pago y
 *    pago hecho antes de las 22:00 (o, si fue despues, anulacion el mismo dia).
 *  - Envia MessageTypeId 0200 + ProcessingCode 020000 con la auditoria de la
 *    venta original.
 *  - Si NeoNet no la aprueba, el pedido regresa a su estado anterior.
 *
 * @package NeoPay_Woo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class NeoPay_Woo_Void
 */
class NeoPay_Woo_Void {

	const STATUS = 'cancelled';

	/**
	 * Ventana maxima en segundos (20 horas).
	 */
	const MAX_AGE = 72000;

	/**
	 * Hora del cierre automatico de NeoNet.
	 */
	const CUTOFF_HOUR = 22;

	/**
	 * Mensajes por codigo de respuesta.
	 *
	 * @var array
	 */
	protected static $messages = array(
		'35' => 'La transaccion ya ha sido anulada anteriormente.',
		'36' => 'La transaccion que intenta anular no existe.',
		'37' => 'La anulacion fue reversada y no puede procesarse.',
		'38' => 'Ocurrio un error al intentar anular la transaccion.',
		'19' => 'La transaccion no se pudo realizar, intente de nuevo.',
		'96' => 'La transaccion ha fallado, intente mas tarde.',
	);

	/**
	 * Evita reentradas al revertir el estado.
	 *
	 * @var bool
	 */
	protected $busy = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 10, 4 );
		add_action( 'admin_notices', array( $this, 'admin_notice' ) );
	}

	/**
	 * Fecha de referencia para la ventana: el pago (o la creacion del pedido
	 * si no hay fecha de pago).
	 *
	 * NOTA PROGRAMADOR: se usa la fecha de pago en vez de la de creacion.
	 *
	 * @param WC_Order $order Pedido.
	 * @return int Timestamp.
	 */
	protected static function reference_time( $order ) {
		$date = $order->get_date_paid() ? $order->get_date_paid() : $order->get_date_created();
		return $date ? $date->getTimestamp() : time();
	}

	/**
	 * Verifica la ventana de anulacion con la zona horaria del sitio.
	 *
	 * NOTA NEONET (confirmar): la regla permite anular hasta 20 h despues del
	 * pago aunque ya haya pasado el cierre de las 22:00 (p. ej. pago 10:00,
	 * anulacion 05:00 del dia siguiente); en ese caso NeoNet responderia 35/36
	 * y el pedido vuelve a su estado anterior.
	 *
	 * @param WC_Order $order Pedido.
	 * @param int|null $now   Momento a evaluar.
	 * @return bool
	 */
	public static function in_window( $order, $now = null ) {
		$now     = null === $now ? time() : $now;
		$created = self::reference_time( $order );
		$tz      = wp_timezone();

		$h_created = (int) wp_date( 'G', $created, $tz );
		$h_now     = (int) wp_date( 'G', $now, $tz );
		$same_day  = wp_date( 'Y-m-d', $created, $tz ) === wp_date( 'Y-m-d', $now, $tz );

		$allowed = ( $now - $created ) < self::MAX_AGE
			&& ( $h_created < self::CUTOFF_HOUR || ( $h_now >= self::CUTOFF_HOUR && $same_day ) );

		return (bool) apply_filters( 'neopay_woo_void_in_window', $allowed, $order, $now );
	}

	/**
	 * Indica si el pedido tiene una venta NeoPay que se puede anular.
	 *
	 * @param WC_Order $order Pedido.
	 * @return bool
	 */
	public static function is_voidable( $order ) {
		return NeoPay_Woo_Gateway::ID === $order->get_payment_method()
			&& '' !== (string) $order->get_meta( '_neopay_audit_number' )
			&& '' !== (string) $order->get_meta( '_neopay_authorization_number' )
			&& 'anulada' !== $order->get_meta( '_neopay_status' );
	}

	/**
	 * Cambio de estado.
	 *
	 * @param int      $order_id Pedido.
	 * @param string   $from     Estado anterior.
	 * @param string   $to       Estado nuevo.
	 * @param WC_Order $order    Pedido.
	 */
	public function on_status_changed( $order_id, $from, $to, $order ) {
		if ( $this->busy || self::STATUS !== $to || ! $order instanceof WC_Order ) {
			return;
		}
		if ( NeoPay_Woo_Gateway::ID !== $order->get_payment_method() ) {
			return;
		}

		if ( ! self::is_voidable( $order ) ) {
			return; // Sin venta aprobada (pedido sin pagar) o ya anulada: cancelacion normal.
		}

		$error = $this->void( $order );
		if ( '' === $error ) {
			self::notice( __( 'La anulacion fue procesada correctamente en NeoPay.', 'neopay-woo' ), 'success' );
			return;
		}

		// Revertir al estado anterior.
		$this->busy = true;
		$order->set_status( $from, __( 'NeoPay: anulacion no procesada. ', 'neopay-woo' ) . $error . ' ' );
		$order->save();
		$this->busy = false;

		self::notice( $error, 'error' );
	}

	/**
	 * Ejecuta la anulacion. Devuelve '' si fue aprobada o el mensaje de error.
	 *
	 * @param WC_Order $order Pedido.
	 * @return string
	 */
	public function void( $order ) {
		if ( ! self::is_voidable( $order ) ) {
			return __( 'No hay numero de auditoria de una venta aprobada para anular.', 'neopay-woo' );
		}
		if ( ! self::in_window( $order ) ) {
			return __( 'Fuera del horario permitido para anular esta orden.', 'neopay-woo' );
		}

		$gateway = NeoPay_Woo::gateway();
		if ( ! $gateway ) {
			return __( 'La pasarela NeoPay no esta disponible.', 'neopay-woo' );
		}

		$audit  = (string) $order->get_meta( '_neopay_audit_number' );
		$result = $gateway->get_api_for_order( $order )->rest_void(
			$order->get_id(),
			$audit,
			(string) $order->get_meta( '_neopay_amount_minor' ),
			(string) $order->get_meta( '_neopay_card_masked' )
		);
		$gateway->record( $order, 'anulacion', $result );

		if ( $result['transport_ok'] && '00' === $result['response_code'] ) {
			$order->update_meta_data( '_neopay_status', 'anulada' );
			$order->update_meta_data( '_neopay_void_time', current_time( 'mysql' ) );
			$order->update_meta_data( '_neopay_void_audit_number', $result['audit_number'] ? $result['audit_number'] : $audit );
			$order->update_meta_data( '_neopay_void_reference_number', $result['reference_number'] );
			$order->update_meta_data( '_neopay_void_authorization_number', $result['authorization_number'] );
			$order->save_meta_data();

			$order->add_order_note(
				sprintf(
					/* translators: 1: auditoria, 2: referencia, 3: autorizacion */
					__( "NeoPay: venta ANULADA.\nAuditoria: %1\$s\nReferencia: %2\$s\nAutorizacion: %3\$s", 'neopay-woo' ),
					$order->get_meta( '_neopay_void_audit_number' ),
					$result['reference_number'],
					$result['authorization_number']
				)
			);

			do_action( 'neopay_woo_payment_voided', $order, $result );
			return '';
		}

		if ( ! $result['transport_ok'] ) {
			return __( 'No se pudo procesar la anulacion (sin respuesta de NeoNet).', 'neopay-woo' );
		}
		$code = $result['response_code'];
		return self::$messages[ $code ] ?? sprintf(
			/* translators: %s: codigo */
			__( 'No se pudo procesar la anulacion. Codigo: %s', 'neopay-woo' ),
			$code . ( '' !== $code ? ' - ' . NeoPay_Woo_Helper::response_label( $code ) : '' )
		);
	}

	/**
	 * Guarda un aviso para el administrador (se muestra tras la redireccion).
	 *
	 * @param string $message Mensaje.
	 * @param string $type    success|error.
	 */
	protected static function notice( $message, $type ) {
		$user = get_current_user_id();
		if ( $user ) {
			set_transient( 'neopay_woo_void_notice_' . $user, array( $message, $type ), 120 );
		}
	}

	/**
	 * Muestra el aviso pendiente.
	 */
	public function admin_notice() {
		$key    = 'neopay_woo_void_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );
		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $notice[1] ), esc_html( $notice[0] ) );
	}
}
