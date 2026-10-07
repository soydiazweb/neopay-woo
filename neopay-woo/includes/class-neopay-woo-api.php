<?php
/**
 * Cliente del API de NeoPay (NeoNet).
 *
 * API REST con 3-D Secure ("AuthorizationPaymentCommerce"):
 *  - Venta: pasos 1 (inicio), DDC, 3 (autorizacion o Step-Up), 4 (desafio del
 *    emisor) y 5 (autorizacion final).
 *  - Reversa automatica (0400) cuando no hay respuesta.
 *  - Anulacion por auditoria (MessageTypeId 0200 + ProcessingCode 020000).
 *
 * @package NeoPay_Woo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class NeoPay_Woo_API
 */
class NeoPay_Woo_API {

	const MTI_SALE     = '0200';
	const MTI_REVERSAL = '0400';

	const PROCESSING_SALE = '000000';
	const PROCESSING_VOID = '020000';

	/**
	 * Credenciales y parametros del ambiente.
	 *
	 * @var array
	 */
	protected $config;

	/**
	 * Constructor.
	 *
	 * @param array $config {environment, rest_endpoint, merchant_user, merchant_passwd, terminal_id, merchant, merchant_server_ip, paymentgw_ip, timeout}.
	 */
	public function __construct( array $config ) {
		$this->config = wp_parse_args(
			$config,
			array(
				'environment'        => 'test',
				'rest_endpoint'      => '',
				'merchant_user'      => '',
				'merchant_passwd'    => '',
				'terminal_id'        => '',
				'merchant'           => '',
				'merchant_server_ip' => '',
				'paymentgw_ip'       => '',
				'timeout'            => 60,
			)
		);
	}

	/**
	 * Valor de configuracion.
	 *
	 * @param string $key Clave.
	 * @return mixed
	 */
	public function get( $key ) {
		return $this->config[ $key ] ?? null;
	}

	/**
	 * Cuerpo REST completo con todos los nodos vacios (el API los exige).
	 *
	 * @param string $mti        Tipo de mensaje.
	 * @param string $audit      Numero de auditoria (SystemsTraceNo).
	 * @param string $processing ProcessingCode.
	 * @return array
	 */
	protected function rest_skeleton( $mti, $audit, $processing = self::PROCESSING_SALE ) {
		return array(
			'MessageTypeId'             => $mti,
			'ProcessingCode'            => $processing,
			'SystemsTraceNo'            => str_pad( (string) $audit, 6, '0', STR_PAD_LEFT ),
			'TimeLocalTrans'            => '',
			'DateLocalTrans'            => '',
			'PosEntryMode'              => '012',
			'Nii'                       => '003',
			'PosConditionCode'          => '00',
			'AdditionalData'            => '',
			'OrderInformation'          => '',
			'FormatId'                  => '1',
			'Merchant'                  => array(
				'TerminalId' => (string) $this->config['terminal_id'],
				'CardAcqId'  => (string) $this->config['merchant'],
			),
			'Card'                      => array(
				'Type'                  => '',
				'PrimaryAcctNum'        => '',
				'DateExpiration'        => '',
				'Cvv2'                  => '',
				'Track2Data'            => '',
				'CardTokenId'           => '',
				'UniqueCodeofBeneciary' => '',
			),
			'Amount'                    => array(
				'AmountTrans'       => '',
				'AmountDiscount'    => '',
				'RateDiscount'      => '',
				'AdditionalAmounts' => '',
				'TaxDetail'         => array(),
			),
			'PrivateUse60'              => array( 'BatchNumber' => '' ),
			'PrivateUse63'              => array(
				'LodgingFolioNumber14' => '',
				'NationalCard25'       => '',
				'HostReferenceData31'  => '',
				'TaxAmount1'           => '',
			),
			'TokenManagement'           => array(
				'Type'         => '',
				'ActionMethod' => '',
			),
			'Customer'                  => array(
				'CustomerTokenId'    => '',
				'FirstName'          => '',
				'LastName'           => '',
				'TaxId'              => '',
				'IdentificationType' => '',
				'PersonalId'         => '',
				'Email'              => '',
				'PhoneNumber'        => '',
			),
			'BillTo'                    => self::empty_address( false ),
			'ShipTo'                    => self::empty_address( true ),
			'PaymentInstrument'         => array( 'PaymentInstrumentTokenId' => '' ),
			'CustomerPaymentInstrument' => array(
				'CustomerPaymentInstrumentTokenId' => '',
				'DefaultCpi'                       => '',
			),
			'PayerAuthentication'       => array(
				'Step'        => '',
				'ReferenceId' => '',
			),
		);
	}

	/**
	 * Direccion vacia.
	 *
	 * @param bool $ship Es ShipTo.
	 * @return array
	 */
	protected static function empty_address( $ship ) {
		$a = array(
			'FirstName'          => '',
			'LastName'           => '',
			'Company'            => '',
			'AddressOne'         => '',
			'AddressTwo'         => '',
			'Locality'           => '',
			'AdministrativeArea' => '',
			'PostalCode'         => '',
			'Country'            => '',
			'Email'              => '',
			'PhoneNumber'        => '',
		);
		if ( $ship ) {
			$a = array( 'DefaultSt' => '' ) + $a + array( 'ShippingAddressTokenId' => '' );
		}
		return $a;
	}

	/**
	 * Paso 1: inicia la autenticacion 3DS. Devuelve ReferenceId, AccessToken
	 * (JWT) y DeviceDataCollectionUrl.
	 *
	 * @param WC_Order $order      Pedido.
	 * @param array    $card       {pan, exp_month, exp_year, cvv, brand_type}.
	 * @param array    $bill_to    BillTo ya saneado.
	 * @param array    $ship_to    ShipTo ya saneado.
	 * @param string   $audit      Numero de auditoria.
	 * @param string   $additional AdditionalData (VC##).
	 * @param string   $return_url URL de retorno del ACS.
	 * @return array Resultado normalizado.
	 */
	public function rest_step1( $order, array $card, array $bill_to, array $ship_to, $audit, $additional, $return_url ) {
		$body                     = $this->rest_skeleton( self::MTI_SALE, $audit );
		$body['AdditionalData']   = (string) $additional;
		$body['OrderInformation'] = $this->order_information( $order );
		$body['Card']['Type']           = (string) $card['brand_type'];
		$body['Card']['PrimaryAcctNum'] = NeoPay_Woo_Helper::digits( $card['pan'] );
		$body['Card']['DateExpiration'] = sprintf( '%02d%02d', (int) $card['exp_year'] % 100, (int) $card['exp_month'] );
		$body['Card']['Cvv2']           = (string) $card['cvv'];
		$body['Amount']['AmountTrans']  = NeoPay_Woo_Helper::to_minor( $order->get_total() );
		$body['BillTo']                 = array_merge( $body['BillTo'], array_intersect_key( $bill_to, $body['BillTo'] ) );
		// ShipTo: datos de entrega solo con el ajuste "Enviar datos de entrega";
		// si no, viaja vacio (ver NeoPay_Woo_Address::ship_to).
		$body['ShipTo']                 = array_merge( $body['ShipTo'], array_intersect_key( $ship_to, $body['ShipTo'] ) );
		$body['PayerAuthentication']    = array(
			'Step'        => '1',
			'UrlCommerce' => $return_url,
			'ReferenceId' => '',
		);

		return $this->rest_post(
			$body,
			array(
				'order_id'    => $order->get_id(),
				'step'        => 'rest_paso1',
				'card_masked' => NeoPay_Woo_Helper::mask_pan( $card['pan'] ),
				'amount'      => $body['Amount']['AmountTrans'],
			)
		);
	}

	/**
	 * Paso 3 o 5: consulta/autoriza usando el ReferenceId de la sesion 3DS.
	 *
	 * @param int    $order_id Pedido.
	 * @param array  $ctx      Contexto guardado en el pedido.
	 * @param string $step     '3' o '5'.
	 * @return array
	 */
	public function rest_step( $order_id, array $ctx, $step ) {
		$body                        = $this->rest_skeleton( self::MTI_SALE, $ctx['audit_number'] );
		$body['OrderInformation']    = (string) ( $ctx['order_info'] ?? '' );
		$body['PayerAuthentication'] = array(
			'Step'        => (string) $step,
			'ReferenceId' => (string) $ctx['reference_id'],
		);

		return $this->rest_post(
			$body,
			array(
				'order_id'    => $order_id,
				'step'        => 'rest_paso' . $step,
				'card_masked' => (string) ( $ctx['card_masked'] ?? '' ),
				'amount'      => (string) ( $ctx['amount_minor'] ?? '' ),
			)
		);
	}

	/**
	 * Reversa automatica REST (0400) con el mismo numero de auditoria.
	 *
	 * @param int    $order_id Pedido.
	 * @param array  $ctx      Contexto.
	 * @param string $step     Paso en que ocurrio el timeout.
	 * @return array
	 */
	public function rest_reversal( $order_id, array $ctx, $step ) {
		$body                        = $this->rest_skeleton( self::MTI_REVERSAL, $ctx['audit_number'] );
		$body['PayerAuthentication'] = array(
			'Step'        => (string) $step,
			'ReferenceId' => (string) ( $ctx['reference_id'] ?? '' ),
		);

		return $this->rest_post(
			$body,
			array(
				'order_id'    => $order_id,
				'step'        => 'rest_reversa_p' . $step,
				'card_masked' => (string) ( $ctx['card_masked'] ?? '' ),
				'amount'      => (string) ( $ctx['amount_minor'] ?? '' ),
			)
		);
	}

	/**
	 * Anulacion por auditoria (MessageTypeId 0200,
	 * ProcessingCode 020000 y el SystemsTraceNo de la venta original).
	 *
	 * @param int    $order_id     Pedido.
	 * @param string $audit        Auditoria de la venta original.
	 * @param string $amount_minor Monto original (solo para el log).
	 * @param string $card_masked  Tarjeta enmascarada (solo para el log).
	 * @return array
	 */
	public function rest_void( $order_id, $audit, $amount_minor = '', $card_masked = '' ) {
		$body                        = $this->rest_skeleton( self::MTI_SALE, $audit, self::PROCESSING_VOID );
		$body['PayerAuthentication'] = array(
			'Step'        => '',
			'UrlCommerce' => '',
			'ReferenceId' => '',
		);

		return $this->rest_post(
			$body,
			array(
				'order_id'    => $order_id,
				'step'        => 'anulacion',
				'card_masked' => $card_masked,
				'amount'      => $amount_minor,
			)
		);
	}

	/**
	 * Texto OrderInformation.
	 *
	 * @param WC_Order $order Pedido.
	 * @return string
	 */
	protected function order_information( $order ) {
		// NOTA NEONET (confirmar): formato "ORDER-<pedido>-A1" (max. 50 caracteres).
		return substr( 'ORDER-' . $order->get_order_number() . '-A1', 0, 50 );
	}

	/**
	 * POST JSON al endpoint REST.
	 *
	 * @param array $body Cuerpo.
	 * @param array $log  Datos para el log.
	 * @return array
	 */
	protected function rest_post( array $body, array $log ) {
		$start = microtime( true );

		// Manual NeoPay: la conexion debe usar TLS 1.2 como minimo.
		add_action( 'http_api_curl', array( $this, 'force_tls12' ), 10, 3 );

		$response = wp_remote_post(
			$this->config['rest_endpoint'],
			array(
				'timeout'     => (int) $this->config['timeout'],
				'httpversion' => '1.1',
				'sslverify'   => true,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type'     => 'application/json',
					'Accept'           => 'application/json',
					'MerchantUser'     => (string) $this->config['merchant_user'],
					'MerchantPasswd'   => (string) $this->config['merchant_passwd'],
					'ShopperIP'        => (string) ( $log['shopper_ip'] ?? NeoPay_Woo_Helper::shopper_ip( wc_get_order( $log['order_id'] ) ?: null ) ),
					'PaymentgwIP'      => (string) $this->config['paymentgw_ip'],
					'MerchantServerIP' => (string) $this->config['merchant_server_ip'],
				),
				'body'        => wp_json_encode( $body ),
			)
		);

		remove_action( 'http_api_curl', array( $this, 'force_tls12' ), 10 );

		$duration = (int) round( ( microtime( true ) - $start ) * 1000 );
		$result   = $this->empty_result();

		if ( is_wp_error( $response ) ) {
			$message           = $response->get_error_message();
			$result['error']   = $message;
			$result['timeout'] = (bool) preg_match( '/timed out|cURL error 28|Operation timed out/i', $message );
		} else {
			$result['http_code'] = (int) wp_remote_retrieve_response_code( $response );
			$raw                 = (string) wp_remote_retrieve_body( $response );
			$json                = json_decode( $raw, true );

			if ( 200 !== $result['http_code'] ) {
				$result['error'] = 'HTTP ' . $result['http_code'];
				// NOTA NEONET (confirmar): un HTTP 5xx se trata como venta en duda y
				// dispara la reversa automatica, igual que un timeout.
				$result['timeout'] = $result['http_code'] >= 500;
			}
			if ( is_array( $json ) ) {
				$result = array_merge( $result, $this->normalize_rest( $json ) );
			} elseif ( '' === $result['error'] ) {
				$result['error'] = 'Respuesta no es JSON';
			}
			if ( ! is_array( $json ) ) {
				$result['raw'] = array( 'body' => substr( $raw, 0, 4000 ) );
			}
		}

		$result['transport_ok'] = '' === $result['error'];

		NeoPay_Woo_Logger::transaction(
			array(
				'order_id'       => $log['order_id'] ?? 0,
				'environment'    => $this->config['environment'],
				'step'           => $log['step'] ?? '',
				'message_type'   => $body['MessageTypeId'],
				'audit_number'   => $body['SystemsTraceNo'],
				'card_masked'    => $log['card_masked'] ?? '',
				'amount'         => $log['amount'] ?? '',
				'http_code'      => $result['http_code'],
				'response_code'  => $result['response_code'],
				'type_operation' => $result['type_operation'],
				'approved'       => $result['approved'],
				'duration_ms'    => $duration,
				'error'          => $result['error'],
				'request'        => $body,
				'response'       => $result['raw'],
			)
		);

		$result['duration_ms'] = $duration;
		return $result;
	}

	/**
	 * Exige TLS 1.2 o superior en las llamadas a NeoNet.
	 *
	 * @param resource|CurlHandle $handle Manejador cURL.
	 * @param array               $args   Argumentos de la peticion.
	 * @param string              $url    URL.
	 */
	public function force_tls12( $handle, $args, $url ) {
		if ( 0 !== stripos( (string) $url, 'https://' ) || $url !== $this->config['rest_endpoint'] ) {
			return;
		}
		if ( defined( 'CURL_SSLVERSION_TLSv1_2' ) ) {
			curl_setopt( $handle, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	/**
	 * Normaliza una respuesta REST.
	 *
	 * @param array $json Respuesta.
	 * @return array
	 */
	protected function normalize_rest( array $json ) {
		$pa   = isset( $json['PayerAuthentication'] ) && is_array( $json['PayerAuthentication'] ) ? $json['PayerAuthentication'] : array();
		$type = (string) ( $json['TypeOperation'] ?? '' );
		$code = (string) ( $json['ResponseCode'] ?? '' );

		return array(
			'raw'                  => $json,
			'type_operation'       => $type,
			'response_code'        => $code,
			// Manual NeoPay: solo ResponseCode 00 es APROBADA; cualquier otro es DENEGADA.
			'approved'             => '1' === $type && '00' === $code,
			'message_type'         => (string) ( $json['MessageTypeId'] ?? '' ),
			'audit_number'         => (string) ( $json['SystemsTraceNo'] ?? '' ),
			'reference_number'     => (string) ( $json['RetrievalRefNo'] ?? '' ),
			'authorization_number' => (string) ( $json['AuthIdResponse'] ?? '' ),
			'remote_time'          => trim( (string) ( $json['DateLocalTrans'] ?? '' ) . ' ' . (string) ( $json['TimeLocalTrans'] ?? '' ) ),
			'alt_message'          => (string) ( $json['PrivateUse63']['AlternateHostResponse22'] ?? '' ),
			'pa_step'              => (string) ( $pa['Step'] ?? '' ),
			'pa_reference_id'      => (string) ( $pa['ReferenceId'] ?? '' ),
			'pa_access_token'      => (string) ( $pa['AccessToken'] ?? '' ),
			'pa_url'               => (string) ( $pa['DeviceDataCollectionUrl'] ?? '' ),
		);
	}

	/**
	 * Resultado vacio.
	 *
	 * @return array
	 */
	protected function empty_result() {
		return array(
			'transport_ok'         => false,
			'timeout'              => false,
			'http_code'            => 0,
			'error'                => '',
			'raw'                  => null,
			'type_operation'       => '',
			'response_code'        => '',
			'approved'             => false,
			'message_type'         => '',
			'audit_number'         => '',
			'reference_number'     => '',
			'authorization_number' => '',
			'remote_time'          => '',
			'alt_message'          => '',
			'pa_step'              => '',
			'pa_reference_id'      => '',
			'pa_access_token'      => '',
			'pa_url'               => '',
		);
	}
}
