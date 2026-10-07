<?php
/**
 * Logs de transacciones con redaccion de datos sensibles.
 *
 * Escribe en dos lugares:
 *  - La tabla {prefix}neopay_woo_logs (visible en WooCommerce > NeoPay Logs).
 *  - El log de WooCommerce (fuente "neopay-woo").
 *
 * Nunca se guarda el PAN completo, el CVV, la fecha de vencimiento, la
 * contrasena del comercio ni los JWT completos.
 *
 * @package NeoPay_Woo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class NeoPay_Woo_Logger
 */
class NeoPay_Woo_Logger {

	const SOURCE = 'neopay-woo';

	/**
	 * Reglas por clave (en minusculas).
	 *
	 * @var array
	 */
	protected static $sensitive = array(
		'primaryacctnum' => 'pan',
		'pan'            => 'pan',
		'cvv2'           => 'remove',
		'cvv'            => 'remove',
		'dateexpiration' => 'remove',
		'expdate'        => 'remove',
		'track2data'     => 'remove',
		'merchantpasswd' => 'remove',
		'merchantuser'   => 'partial',
		'accesstoken'    => 'truncate',
		'jwt'            => 'truncate',
		'cres'           => 'truncate',
		'pares'          => 'truncate',
		'retrievalrefno'      => 'keep',
		'referencenumber'     => 'keep',
		'referenceid'         => 'keep',
		'systemstraceno'      => 'keep',
		'auditnumber'         => 'keep',
		'authidresponse'      => 'keep',
		'authorizationnumber' => 'keep',
	);

	/**
	 * Mensaje en el log de WooCommerce.
	 *
	 * @param string $message Mensaje.
	 * @param string $level   Nivel.
	 * @param array  $context Datos (se redactan).
	 */
	public static function log( $message, $level = 'info', $context = array() ) {
		if ( 'debug' === $level && ! self::debug_enabled() ) {
			return;
		}
		$logger = function_exists( 'wc_get_logger' ) ? wc_get_logger() : null;
		if ( ! $logger ) {
			return;
		}
		if ( ! empty( $context ) ) {
			$message .= "\n" . wp_json_encode( self::redact( $context ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}
		$logger->log( $level, $message, array( 'source' => self::SOURCE ) );
	}

	/**
	 * Registra una llamada al API en la tabla de logs y en el log de WC.
	 *
	 * @param array $row {order_id, environment, step, message_type, audit_number, card_masked, amount, http_code, response_code, type_operation, approved, duration_ms, error, request, response}.
	 * @return int ID insertado.
	 */
	public static function transaction( array $row ) {
		global $wpdb;

		$request  = isset( $row['request'] ) ? self::redact( $row['request'] ) : null;
		$response = isset( $row['response'] ) ? self::redact( $row['response'] ) : null;

		$data = array(
			'created_at'     => current_time( 'mysql', true ),
			'order_id'       => (int) ( $row['order_id'] ?? 0 ),
			'environment'    => substr( (string) ( $row['environment'] ?? '' ), 0, 10 ),
			'step'           => substr( (string) ( $row['step'] ?? '' ), 0, 30 ),
			'message_type'   => substr( (string) ( $row['message_type'] ?? '' ), 0, 4 ),
			'audit_number'   => substr( (string) ( $row['audit_number'] ?? '' ), 0, 12 ),
			'card_masked'    => substr( (string) ( $row['card_masked'] ?? '' ), 0, 25 ),
			'amount'         => substr( (string) ( $row['amount'] ?? '' ), 0, 20 ),
			'http_code'      => (int) ( $row['http_code'] ?? 0 ),
			'response_code'  => substr( (string) ( $row['response_code'] ?? '' ), 0, 4 ),
			'type_operation' => substr( (string) ( $row['type_operation'] ?? '' ), 0, 4 ),
			'approved'       => empty( $row['approved'] ) ? 0 : 1,
			'duration_ms'    => (int) ( $row['duration_ms'] ?? 0 ),
			'error'          => isset( $row['error'] ) ? self::scrub_string( (string) $row['error'] ) : null,
			'request'        => null === $request ? null : wp_json_encode( $request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			'response'       => null === $response ? null : wp_json_encode( $response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
		);

		$wpdb->insert( NeoPay_Woo_Install::table(), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$id = (int) $wpdb->insert_id;

		$summary = sprintf(
			'[%s] pedido #%d %s MTI=%s auditoria=%s HTTP=%d codigo=%s tipo=%s %s',
			$data['step'],
			$data['order_id'],
			$data['environment'],
			$data['message_type'],
			$data['audit_number'],
			$data['http_code'],
			$data['response_code'],
			$data['type_operation'],
			$data['approved'] ? 'APROBADA' : ( $data['error'] ? 'ERROR: ' . $data['error'] : 'NO APROBADA' )
		);

		self::log( $summary, $data['approved'] ? 'info' : 'warning' );
		self::log( $summary . ' (detalle)', 'debug', array( 'request' => $request, 'response' => $response ) );

		return $id;
	}

	/**
	 * Modo depuracion activo.
	 *
	 * @return bool
	 */
	public static function debug_enabled() {
		$settings = get_option( 'woocommerce_neopay_woo_settings', array() );
		return is_array( $settings ) && 'yes' === ( $settings['debug'] ?? 'no' );
	}

	/**
	 * Redacta recursivamente datos sensibles.
	 *
	 * @param mixed $data Datos.
	 * @return mixed
	 */
	public static function redact( $data ) {
		if ( is_object( $data ) ) {
			$data = json_decode( wp_json_encode( $data ), true );
		}

		if ( is_array( $data ) ) {
			$out = array();
			foreach ( $data as $key => $value ) {
				$rule = is_string( $key ) ? ( self::$sensitive[ strtolower( $key ) ] ?? '' ) : '';

				if ( 'keep' === $rule && is_scalar( $value ) ) {
					$out[ $key ] = $value;
					continue;
				}
				if ( '' === $rule || 'keep' === $rule ) {
					$out[ $key ] = self::redact( $value );
					continue;
				}
				if ( is_array( $value ) || is_object( $value ) ) {
					$out[ $key ] = '[redactado]';
					continue;
				}

				$value = (string) $value;
				if ( '' === $value ) {
					$out[ $key ] = '';
					continue;
				}

				switch ( $rule ) {
					case 'pan':
						$out[ $key ] = NeoPay_Woo_Helper::mask_pan( $value );
						break;
					case 'partial':
						$out[ $key ] = strlen( $value ) > 8 ? substr( $value, 0, 4 ) . '...' . substr( $value, -4 ) : '****';
						break;
					case 'truncate':
						$out[ $key ] = self::truncate( $value );
						break;
					default:
						$out[ $key ] = '[redactado]';
						break;
				}
			}
			return $out;
		}

		if ( is_string( $data ) ) {
			return self::scrub_string( $data );
		}

		return $data;
	}

	/**
	 * Corta cadenas largas (JWT, CReq...).
	 *
	 * @param string $value  Valor.
	 * @param int    $length Longitud.
	 * @return string
	 */
	public static function truncate( $value, $length = 16 ) {
		if ( strlen( $value ) <= $length ) {
			return $value;
		}
		return substr( $value, 0, $length ) . '...[' . strlen( $value ) . ' bytes, sha256:' . substr( hash( 'sha256', $value ), 0, 12 ) . ']';
	}

	/**
	 * Enmascara cualquier secuencia con forma de PAN dentro de un texto libre.
	 *
	 * @param string $value Texto.
	 * @return string
	 */
	public static function scrub_string( $value ) {
		if ( strlen( $value ) > 8000 ) {
			$value = substr( $value, 0, 8000 ) . '...[truncado]';
		}
		return preg_replace_callback(
			'/(?<!\d)(?:\d[ -]?){12,18}\d(?!\d)/',
			static function ( $m ) {
				$digits = NeoPay_Woo_Helper::digits( $m[0] );
				return NeoPay_Woo_Helper::luhn( $digits ) ? NeoPay_Woo_Helper::mask_pan( $digits ) : $m[0];
			},
			$value
		);
	}

	/**
	 * Elimina logs anteriores a la retencion configurada (tarea diaria).
	 */
	public static function purge() {
		global $wpdb;

		$settings = get_option( 'woocommerce_neopay_woo_settings', array() );
		$days     = isset( $settings['log_retention'] ) ? (int) $settings['log_retention'] : 180;
		if ( $days <= 0 ) {
			return;
		}
		$limit = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . NeoPay_Woo_Install::table() . ' WHERE created_at < %s', $limit ) ); // phpcs:ignore
	}
}
