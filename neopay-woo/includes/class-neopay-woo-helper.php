<?php
/**
 * Utilidades: validacion de tarjeta, enmascarado, saneado de textos,
 * numero de auditoria y catalogo de codigos de respuesta.
 *
 * @package NeoPay_Woo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class NeoPay_Woo_Helper
 */
class NeoPay_Woo_Helper {

	/**
	 * Catalogo de codigos propios de respuesta (Manual de Integracion NeoPay, pag. 35).
	 *
	 * @var array
	 */
	protected static $response_codes = array(
		'00' => 'Aprobada',
		'01' => 'Refierase al emisor',
		'02' => 'Refierase al emisor',
		'05' => 'Transaccion no aceptada',
		'12' => 'Transaccion invalida',
		'13' => 'Monto invalido',
		'19' => 'Transaccion no realizada, intente de nuevo',
		'31' => 'Tarjeta no soportada por switch',
		'35' => 'Transaccion ya ha sido anulada',
		'36' => 'Transaccion a anular no existe',
		'37' => 'Transaccion de anulacion reversada',
		'38' => 'Transaccion a anular con error',
		'41' => 'Tarjeta extraviada',
		'43' => 'Tarjeta robada',
		'51' => 'No tiene fondos disponibles',
		'57' => 'Transaccion no permitida',
		'58' => 'Transaccion no permitida en la terminal',
		'65' => 'Limite de actividad excedido',
		'80' => 'Fecha de expiracion invalida',
		'89' => 'Terminal invalida',
		'91' => 'Emisor no disponible',
		'93' => 'Mensajes de la plataforma de NeoPay',
		'94' => 'Transaccion duplicada',
		'96' => 'Error del sistema, intente mas tarde',
	);

	/**
	 * Codigos que no deben mostrarse con detalle al cliente (riesgo/fraude).
	 *
	 * NOTA PROGRAMADOR: para estos codigos el cliente ve un mensaje generico;
	 * el codigo real queda en el pedido y en los logs.
	 *
	 * @var array
	 */
	protected static $generic_codes = array( '41', '43', '57', '58', '89', '93', '96' );

	/**
	 * Descripcion de un codigo de respuesta.
	 *
	 * @param string $code Codigo.
	 * @return string
	 */
	public static function response_label( $code ) {
		$code = (string) $code;
		return self::$response_codes[ $code ] ?? ( '' === $code ? 'Sin respuesta' : 'Codigo ' . $code );
	}

	/**
	 * Mensaje para el cliente segun el codigo.
	 *
	 * @param string $code Codigo.
	 * @return string
	 */
	public static function customer_message( $code ) {
		$code = (string) $code;
		if ( '' === $code || in_array( $code, self::$generic_codes, true ) || ! isset( self::$response_codes[ $code ] ) ) {
			return __( 'Su pago no pudo ser procesado. Verifique los datos de su tarjeta o intente con otra tarjeta.', 'neopay-woo' );
		}
		return sprintf(
			/* translators: 1: descripcion, 2: codigo */
			__( 'Pago no aprobado: %1$s (codigo %2$s).', 'neopay-woo' ),
			self::$response_codes[ $code ],
			$code
		);
	}

	/**
	 * Solo digitos.
	 *
	 * @param string $value Valor.
	 * @return string
	 */
	public static function digits( $value ) {
		return preg_replace( '/\D+/', '', (string) $value );
	}

	/**
	 * Algoritmo de Luhn (MOD10), exigido por el manual para el PAN.
	 *
	 * @param string $pan PAN.
	 * @return bool
	 */
	public static function luhn( $pan ) {
		$digits = self::digits( $pan );
		$len    = strlen( $digits );
		if ( $len < 13 || $len > 19 ) {
			return false;
		}
		$sum = 0;
		$alt = false;
		for ( $i = $len - 1; $i >= 0; $i-- ) {
			$n = (int) $digits[ $i ];
			if ( $alt ) {
				$n *= 2;
				if ( $n > 9 ) {
					$n -= 9;
				}
			}
			$sum += $n;
			$alt  = ! $alt;
		}
		return 0 === $sum % 10;
	}

	/**
	 * Detecta la marca. NeoPay procesa Visa y Mastercard; el tipo es el que
	 * espera el API REST en Card.Type.
	 *
	 * @param string $pan PAN.
	 * @return array|null { scheme, type, slug }
	 */
	public static function detect_brand( $pan ) {
		$d = self::digits( $pan );
		if ( preg_match( '/^4\d{12}(\d{3})?(\d{3})?$/', $d ) ) {
			return array( 'scheme' => 'VISA', 'type' => '001', 'slug' => 'visa' );
		}
		if ( preg_match( '/^(5[1-5]\d{14}|2(22[1-9]\d{12}|2[3-9]\d{13}|[3-6]\d{14}|7[01]\d{13}|720\d{12}))$/', $d ) ) {
			return array( 'scheme' => 'MASTERCARD', 'type' => '002', 'slug' => 'mastercard' );
		}
		return null;
	}

	/**
	 * PAN enmascarado para logs: primeros 6 y ultimos 4 (permitido por PCI DSS).
	 *
	 * @param string $pan PAN.
	 * @return string
	 */
	public static function mask_pan( $pan ) {
		$d   = self::digits( $pan );
		$len = strlen( $d );
		if ( $len < 12 ) {
			return str_repeat( '*', $len );
		}
		return substr( $d, 0, 6 ) . str_repeat( '*', $len - 10 ) . substr( $d, -4 );
	}

	/**
	 * PAN enmascarado para el comprobante: "xxxx xxxx xxxx 0416".
	 *
	 * @param string $last4 Ultimos 4.
	 * @return string
	 */
	public static function voucher_pan( $last4 ) {
		return 'xxxx xxxx xxxx ' . $last4;
	}

	/**
	 * Monto en unidades menores sin separadores: Q5.61 => "561".
	 *
	 * @param float|string $amount Monto.
	 * @return string
	 */
	public static function to_minor( $amount ) {
		return (string) (int) round( (float) $amount * 100 );
	}

	/**
	 * Quita tildes y caracteres no ASCII.
	 *
	 * @param string $value Valor.
	 * @return string
	 */
	protected static function ascii( $value ) {
		$value = remove_accents( trim( (string) $value ) );
		return preg_replace( '/[^\x20-\x7E]/', '', $value );
	}

	/**
	 * Nombre aceptado por NeoPay.
	 *
	 * @param string $name       Nombre.
	 * @param int    $max_length Longitud maxima.
	 * @param string $fallback   Valor por defecto.
	 * @return string
	 */
	public static function sanitize_name( $name, $max_length = 30, $fallback = '' ) {
		$name = self::ascii( $name );
		$name = preg_replace( '/[^A-Za-z0-9\s\.\']/', '', $name );
		$name = trim( preg_replace( '/\s+/', ' ', $name ) );
		$name = ucwords( strtolower( $name ) );
		if ( $max_length > 0 && strlen( $name ) > $max_length ) {
			$name = trim( substr( $name, 0, $max_length ) );
		}
		return '' === $name ? $fallback : $name;
	}

	/**
	 * Direccion aceptada por NeoPay.
	 *
	 * @param string $address    Direccion.
	 * @param int    $max_length Longitud maxima.
	 * @param string $fallback   Valor por defecto.
	 * @return string
	 */
	public static function sanitize_address( $address, $max_length = 50, $fallback = '' ) {
		$address = strtoupper( self::ascii( $address ) );
		$address = preg_replace( '/[^A-Z0-9\s\.,\-\/#]/', '', $address );
		$address = trim( preg_replace( '/\s+/', ' ', $address ) );
		if ( $max_length > 0 && strlen( $address ) > $max_length ) {
			$address = trim( substr( $address, 0, $max_length ) );
		}
		return '' === $address ? $fallback : $address;
	}

	/**
	 * Telefono de 8 digitos (Guatemala). Si no es valido devuelve el respaldo (vacio por defecto).
	 *
	 * @param string $phone    Telefono.
	 * @param string $fallback Respaldo.
	 * @return string
	 */
	public static function sanitize_phone( $phone, $fallback = '' ) {
		$d = self::digits( $phone );
		if ( strlen( $d ) > 8 && 0 === strpos( $d, '502' ) ) {
			$d = substr( $d, 3 );
		}
		return preg_match( '/^[2-9]\d{7}$/', $d ) ? $d : $fallback;
	}

	/**
	 * IP del comprador en formato ###.###.###.### (IPv4).
	 *
	 * @param WC_Order|null $order Pedido.
	 * @return string
	 */
	public static function shopper_ip( $order = null ) {
		$ip = '';
		if ( $order instanceof WC_Order ) {
			$ip = (string) $order->get_customer_ip_address();
		}
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) && class_exists( 'WC_Geolocation' ) ) {
			$ip = (string) WC_Geolocation::get_ip_address();
		}
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		}
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$ip = '127.0.0.1';
		}
		return $ip;
	}

	/**
	 * Siguiente numero de auditoria (000001..999999, ciclico), por ambiente.
	 *
	 * Se incrementa en una sola sentencia SQL con LAST_INSERT_ID() para que dos
	 * pagos simultaneos nunca obtengan el mismo numero.
	 *
	 * @param string $environment test|production.
	 * @return string
	 */
	public static function next_audit_number( $environment ) {
		global $wpdb;

		$option = 'neopay_woo_audit_' . ( 'production' === $environment ? 'production' : 'test' );
		add_option( $option, '0', '', false );

		$suppress = $wpdb->suppress_errors( true );
		$updated  = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = LAST_INSERT_ID( ( option_value % 999999 ) + 1 ) WHERE option_name = %s",
				$option
			)
		);
		$next = $updated ? (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' ) : 0;
		$wpdb->suppress_errors( $suppress );

		if ( $next < 1 ) {
			// Motores sin LAST_INSERT_ID(expr) (p. ej. SQLite): incremento y lectura.
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = ( option_value % 999999 ) + 1 WHERE option_name = %s", $option ) );
			$next = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) );
		}

		wp_cache_delete( $option, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		return str_pad( (string) max( 1, $next ), 6, '0', STR_PAD_LEFT );
	}

	/**
	 * Codigo de producto de valor NeoCuotas: VC##.
	 *
	 * @param int $installments Cuotas.
	 * @return string
	 */
	public static function installments_additional_data( $installments ) {
		$n = sprintf( '%02d', (int) $installments );
		return in_array( $n, array( '03', '06', '10', '12', '18', '24' ), true ) ? 'VC' . $n : '';
	}
}
