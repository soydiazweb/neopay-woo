<?php
/**
 * Datos de facturacion (BillTo) y entrega (ShipTo) para NeoPay.
 *
 * Cada dato se busca en este orden y se usa el primero que tenga valor:
 *  1. Campo estandar de WooCommerce del mismo tipo (facturacion o envio).
 *  2. Campo estandar de WooCommerce del otro tipo.
 *  3. Campo personalizado indicado en los ajustes (clave de meta del pedido
 *     o del cliente).
 *  4. Campo personalizado detectado automaticamente en el pedido o en el
 *     perfil del cliente (plugins de campos de checkout, campos de bloques).
 *
 * Si no se encuentra en ningun lado se envia vacio: no se usan datos de
 * respaldo genericos. El origen de cada dato queda registrado en el pedido.
 *
 * NOTA NEONET (confirmar): que el API acepte BillTo/ShipTo con campos vacios
 * cuando la tienda no pide ese dato (por ejemplo, codigo postal).
 *
 * @package NeoPay_Woo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class NeoPay_Woo_Address
 */
class NeoPay_Woo_Address {

	/**
	 * Campos del API => campo de WooCommerce, longitud maxima y tipo de saneado.
	 *
	 * @var array
	 */
	protected static $fields = array(
		'FirstName'          => array( 'wc' => 'first_name', 'max' => 30, 'clean' => 'name' ),
		'LastName'           => array( 'wc' => 'last_name', 'max' => 30, 'clean' => 'name' ),
		'Company'            => array( 'wc' => 'company', 'max' => 30, 'clean' => 'name' ),
		'AddressOne'         => array( 'wc' => 'address_1', 'max' => 50, 'clean' => 'address' ),
		'AddressTwo'         => array( 'wc' => 'address_2', 'max' => 50, 'clean' => 'address' ),
		'Locality'           => array( 'wc' => 'city', 'max' => 30, 'clean' => 'address' ),
		'AdministrativeArea' => array( 'wc' => 'state', 'max' => 3, 'clean' => 'state' ),
		'PostalCode'         => array( 'wc' => 'postcode', 'max' => 10, 'clean' => 'postcode' ),
		'Country'            => array( 'wc' => 'country', 'max' => 2, 'clean' => 'country' ),
		'Email'              => array( 'wc' => 'email', 'max' => 60, 'clean' => 'email' ),
		'PhoneNumber'        => array( 'wc' => 'phone', 'max' => 8, 'clean' => 'phone' ),
	);

	/**
	 * Patrones para detectar campos personalizados por el nombre de la clave.
	 *
	 * @var array
	 */
	protected static $patterns = array(
		'FirstName'          => '/(first_?name|primer_?nombre|^_?(billing_|shipping_)?nombres?$)/i',
		'LastName'           => '/(last_?name|apellidos?)/i',
		'Company'            => '/(company|empresa|razon_?social)/i',
		'AddressOne'         => '/(address_?1|address$|direccion|domicilio|calle)/i',
		'AddressTwo'         => '/(address_?2|referencia|indicaciones|apartamento|zona)/i',
		'Locality'           => '/(city|ciudad|municipio|localidad)/i',
		'AdministrativeArea' => '/(state|departamento|provincia|region)/i',
		'PostalCode'         => '/(postcode|postal|zip|codigo_?postal)/i',
		'Country'            => '/(country|pais)/i',
		'Email'              => '/(email|correo)/i',
		'PhoneNumber'        => '/(phone|telefono|tel$|tel_|celular|movil|whatsapp)/i',
	);

	/**
	 * Claves de meta que nunca se consideran (internas o de otras funciones).
	 *
	 * @var string
	 */
	protected static $excluded = '/^(_neopay|_wp_|_edit_|_wc_order_attribution|_transaction|_payment|_customer_user|_order_|_cart_|_recorded|_download|_new_order|_date_|_created_via|_prices_include|is_vat|_billing_address_index|_shipping_address_index)/i';

	/**
	 * Ajustes de la pasarela.
	 *
	 * @var array
	 */
	protected $settings;

	/**
	 * Constructor.
	 *
	 * @param array $settings Ajustes de la pasarela.
	 */
	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Claves de los campos del API (para los ajustes).
	 *
	 * @return array
	 */
	public static function api_fields() {
		return array_keys( self::$fields );
	}

	/**
	 * BillTo del pedido.
	 *
	 * @param WC_Order $order   Pedido.
	 * @param array    $sources Origen de cada dato (por referencia).
	 * @return array
	 */
	public function bill_to( $order, &$sources = array() ) {
		return $this->resolve( $order, 'billing', $sources );
	}

	/**
	 * ShipTo del pedido.
	 *
	 * @param WC_Order $order   Pedido.
	 * @param array    $sources Origen de cada dato (por referencia).
	 * @return array
	 */
	public function ship_to( $order, &$sources = array() ) {
		return $this->resolve( $order, 'shipping', $sources );
	}

	/**
	 * Resuelve todos los campos de un tipo.
	 *
	 * @param WC_Order $order   Pedido.
	 * @param string   $type    billing|shipping.
	 * @param array    $sources Origen de cada dato (por referencia).
	 * @return array
	 */
	protected function resolve( $order, $type, &$sources ) {
		$other    = 'billing' === $type ? 'shipping' : 'billing';
		$prefix   = 'billing' === $type ? 'BillTo' : 'ShipTo';
		$detected = null;
		$out      = array();

		foreach ( self::$fields as $api => $def ) {
			$value  = '';
			$source = '';

			// 1 y 2: campos estandar de WooCommerce.
			foreach ( array( $type, $other ) as $wc_type ) {
				$raw = $this->standard( $order, $wc_type, $def['wc'] );
				$value = $this->clean( $raw, $def );
				if ( '' !== $value ) {
					$source = 'woocommerce:' . $wc_type . '_' . $def['wc'];
					break;
				}
			}

			// 3: campo personalizado indicado en los ajustes.
			if ( '' === $value ) {
				foreach ( $this->mapped_keys( $api ) as $key ) {
					$raw = $this->meta( $order, $key );
					$value = $this->clean( $raw, $def );
					if ( '' !== $value ) {
						$source = 'ajuste:' . $key;
						break;
					}
				}
			}

			// 4: campo personalizado detectado.
			if ( '' === $value && 'yes' === ( $this->settings['detect_custom_fields'] ?? 'yes' ) ) {
				if ( null === $detected ) {
					$detected = $this->custom_candidates( $order );
				}
				foreach ( $this->rank( $detected, $api, $type ) as $key => $raw ) {
					$value = $this->clean( $raw, $def );
					if ( '' !== $value ) {
						$source = 'personalizado:' . $key;
						break;
					}
				}
			}

			$out[ $api ] = $value;
			if ( '' !== $source ) {
				$sources[ $prefix . '.' . $api ] = $source;
			}
		}

		return apply_filters( 'neopay_woo_' . ( 'billing' === $type ? 'bill_to' : 'ship_to' ), $out, $order, $sources );
	}

	/**
	 * Valor de un campo estandar del pedido.
	 *
	 * @param WC_Order $order Pedido.
	 * @param string   $type  billing|shipping.
	 * @param string   $field Campo.
	 * @return string
	 */
	protected function standard( $order, $type, $field ) {
		if ( 'shipping' === $type && 'email' === $field ) {
			return ''; // WooCommerce no tiene correo de envio.
		}
		$getter = 'get_' . $type . '_' . $field;
		return is_callable( array( $order, $getter ) ) ? (string) $order->$getter() : '';
	}

	/**
	 * Claves configuradas para un campo del API (separadas por comas).
	 *
	 * @param string $api Campo del API.
	 * @return array
	 */
	protected function mapped_keys( $api ) {
		$raw = (string) ( $this->settings[ 'map_' . $api ] ?? '' );
		return array_filter( array_map( 'trim', explode( ',', $raw ) ) );
	}

	/**
	 * Lee una clave de meta del pedido y, si no existe, del cliente.
	 *
	 * @param WC_Order $order Pedido.
	 * @param string   $key   Clave.
	 * @return string
	 */
	protected function meta( $order, $key ) {
		$value = $order->get_meta( $key );
		if ( ( '' === $value || null === $value ) && $order->get_customer_id() ) {
			$value = get_user_meta( $order->get_customer_id(), $key, true );
		}
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Metas escalares del pedido y del cliente que pueden ser datos de contacto.
	 *
	 * @param WC_Order $order Pedido.
	 * @return array clave => valor (primero las del pedido).
	 */
	protected function custom_candidates( $order ) {
		$out = array();

		foreach ( $order->get_meta_data() as $meta ) {
			$data = $meta->get_data();
			$key  = (string) $data['key'];
			if ( ! isset( $out[ $key ] ) && is_scalar( $data['value'] ) && '' !== trim( (string) $data['value'] ) && ! preg_match( self::$excluded, $key ) ) {
				$out[ $key ] = (string) $data['value'];
			}
		}

		$user_id = $order->get_customer_id();
		if ( $user_id ) {
			foreach ( (array) get_user_meta( $user_id ) as $key => $values ) {
				$value = is_array( $values ) ? reset( $values ) : $values;
				if ( ! isset( $out[ $key ] ) && is_scalar( $value ) && '' !== trim( (string) $value ) && ! preg_match( self::$excluded, $key ) && ! is_serialized( $value ) ) {
					$out[ 'usuario:' . $key ] = (string) $value;
				}
			}
		}

		return $out;
	}

	/**
	 * Candidatos de un campo, priorizando los del mismo tipo (billing/shipping).
	 *
	 * @param array  $candidates Candidatos.
	 * @param string $api        Campo del API.
	 * @param string $type       billing|shipping.
	 * @return array
	 */
	protected function rank( array $candidates, $api, $type ) {
		$same  = array();
		$other = array();
		$any   = array();

		foreach ( $candidates as $key => $value ) {
			$name = preg_replace( '/^usuario:/', '', $key );
			$name = preg_replace( '#^_?wc_(billing|shipping|other)/[^/]+/#', '$1_', ltrim( $name, '_' ) );
			if ( ! preg_match( self::$patterns[ $api ], $name ) ) {
				continue;
			}
			// "address_2" no debe tomarse como direccion principal.
			if ( 'AddressOne' === $api && preg_match( self::$patterns['AddressTwo'], $name ) ) {
				continue;
			}
			if ( false !== stripos( $name, $type ) ) {
				$same[ $key ] = $value;
			} elseif ( false !== stripos( $name, 'billing' ) || false !== stripos( $name, 'shipping' ) ) {
				$other[ $key ] = $value;
			} else {
				$any[ $key ] = $value;
			}
		}

		return $same + $any + $other;
	}

	/**
	 * Sanea un valor segun el tipo de campo. Devuelve '' si no es valido.
	 *
	 * @param string $raw Valor.
	 * @param array  $def Definicion del campo.
	 * @return string
	 */
	protected function clean( $raw, array $def ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}

		switch ( $def['clean'] ) {
			case 'name':
				return NeoPay_Woo_Helper::sanitize_name( $raw, $def['max'] );
			case 'address':
				return NeoPay_Woo_Helper::sanitize_address( $raw, $def['max'] );
			case 'state':
				$state = preg_replace( '/^[A-Z]{2}-/', '', strtoupper( NeoPay_Woo_Helper::sanitize_address( $raw, 0 ) ) );
				return substr( preg_replace( '/[^A-Z0-9]/', '', $state ), 0, $def['max'] );
			case 'postcode':
				return substr( preg_replace( '/[^A-Za-z0-9]/', '', $raw ), 0, $def['max'] );
			case 'country':
				$country = strtoupper( $raw );
				return preg_match( '/^[A-Z]{2}$/', $country ) ? $country : '';
			case 'email':
				return is_email( $raw ) ? substr( $raw, 0, $def['max'] ) : '';
			case 'phone':
				return NeoPay_Woo_Helper::sanitize_phone( $raw );
		}
		return '';
	}
}
