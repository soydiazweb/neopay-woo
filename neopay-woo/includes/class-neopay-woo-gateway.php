<?php
/**
 * Pasarela NeoPay para WooCommerce.
 *
 * @package NeoPay_Woo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class NeoPay_Woo_Gateway
 */
class NeoPay_Woo_Gateway extends WC_Payment_Gateway {

	const ID = 'neopay_woo';

	/**
	 * Cuotas que admite NeoCuotas (Manual, Productos de valor).
	 *
	 * @var array
	 */
	public static $installment_options = array( 3, 6, 10, 12, 18, 24 );

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = self::ID;
		$this->has_fields         = true;
		$this->method_title       = __( 'NeoPay (NeoNet)', 'neopay-woo' );
		$this->method_description = __( 'Cobros con tarjeta Visa y Mastercard a traves del API REST con 3-D Secure de NeoPay (NeoNet): ambientes de pruebas y produccion, NeoCuotas, reversa automatica, anulacion al cancelar el pedido y comprobante de pago.', 'neopay-woo' );
		$this->supports           = array( 'products' );
		$this->icon               = '';

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/* ------------------------------------------------------------------
	 * Ajustes
	 * --------------------------------------------------------------- */

	/**
	 * Campos de ajustes.
	 */
	public function init_form_fields() {
		$credential_fields = function ( $prefix, $label, $defaults ) {
			return array(
				$prefix . '_title'              => array(
					'title' => $label,
					'type'  => 'title',
				),
				$prefix . '_rest_endpoint'      => array(
					'title'       => __( 'URL REST (AuthorizationPaymentCommerce)', 'neopay-woo' ),
					'type'        => 'text',
					'default'     => $defaults['rest_endpoint'],
				),
				$prefix . '_merchant_user'      => array(
					'title'   => __( 'Usuario (merchantUser)', 'neopay-woo' ),
					'type'    => 'text',
					'default' => $defaults['merchant_user'],
				),
				$prefix . '_merchant_passwd'    => array(
					'title'   => __( 'Contrasena (merchantPasswd)', 'neopay-woo' ),
					'type'    => 'password',
					'default' => $defaults['merchant_passwd'],
				),
				$prefix . '_terminal_id'        => array(
					'title'   => __( 'Terminal (terminalId)', 'neopay-woo' ),
					'type'    => 'text',
					'default' => $defaults['terminal_id'],
				),
				$prefix . '_merchant'           => array(
					'title'       => __( 'Afiliacion (merchant / CardAcqId)', 'neopay-woo' ),
					'type'        => 'text',
					'default'     => $defaults['merchant'],
					'description' => __( 'Numero de afiliacion del comercio. Tambien se imprime en el comprobante.', 'neopay-woo' ),
				),
				$prefix . '_merchant_server_ip' => array(
					'title'       => __( 'IP publica del servidor (merchantServerIP)', 'neopay-woo' ),
					'type'        => 'text',
					'default'     => '',
					'placeholder' => '###.###.###.###',
					'description' => __( 'IP publica desde la que este sitio se conecta a NeoNet (la que NeoNet habilita en su firewall).', 'neopay-woo' ),
				),
				$prefix . '_paymentgw_ip'       => array(
					'title'       => __( 'IP de la pasarela (paymentgwIP)', 'neopay-woo' ),
					'type'        => 'text',
					'default'     => $defaults['paymentgw_ip'],
					'placeholder' => '###.###.###.###',
					'description' => __( 'Provista por NeoNet. Si se deja vacia se envia la IP del servidor.', 'neopay-woo' ),
				),
			);
		};

		$this->form_fields = array_merge(
			array(
				'enabled'     => array(
					'title'   => __( 'Activar/Desactivar', 'neopay-woo' ),
					'type'    => 'checkbox',
					'label'   => __( 'Activar NeoPay', 'neopay-woo' ),
					'default' => 'no',
				),
				'title'       => array(
					'title'   => __( 'Titulo', 'neopay-woo' ),
					'type'    => 'text',
					'default' => __( 'Tarjeta de credito o debito (NeoPay)', 'neopay-woo' ),
				),
				'description' => array(
					'title'   => __( 'Descripcion', 'neopay-woo' ),
					'type'    => 'textarea',
					'default' => __( 'Pague de forma segura con su tarjeta Visa o Mastercard.', 'neopay-woo' ),
				),
				'environment' => array(
					'title'       => __( 'Ambiente', 'neopay-woo' ),
					'type'        => 'select',
					'default'     => 'test',
					'options'     => array(
						'test'       => __( 'Pruebas (certificacion)', 'neopay-woo' ),
						'production' => __( 'Produccion', 'neopay-woo' ),
					),
					'description' => __( 'Cada pedido guarda el ambiente con el que se cobro; las anulaciones usan siempre ese mismo ambiente.', 'neopay-woo' ),
				),
			),
			$credential_fields(
				'test',
				__( 'Credenciales de pruebas', 'neopay-woo' ),
				array(
					'rest_endpoint'   => 'https://epaytestvisanet.com.gt:4433/V3/api/AuthorizationPaymentCommerce',
					'merchant_user'   => '',
					'merchant_passwd' => '',
					'terminal_id'     => '',
					'merchant'        => '',
					'paymentgw_ip'    => '',
				)
			),
			$credential_fields(
				'production',
				__( 'Credenciales de produccion', 'neopay-woo' ),
				array(
					'rest_endpoint'   => 'https://epayserver.neonet.com.gt/api/AuthorizationPaymentCommerce',
					'merchant_user'   => '',
					'merchant_passwd' => '',
					'terminal_id'     => '',
					'merchant'        => '',
					'paymentgw_ip'    => '',
				)
			),
			array(
				'installments_title'      => array(
					'title'       => __( 'NeoCuotas', 'neopay-woo' ),
					'type'        => 'title',
					'description' => __( 'Se envian en additionalData como VC## (por ejemplo VC06). Deben estar habilitadas en la afiliacion.', 'neopay-woo' ),
				),
				'installments_enabled'    => array(
					'title'   => __( 'Pago en cuotas', 'neopay-woo' ),
					'type'    => 'checkbox',
					'label'   => __( 'Ofrecer NeoCuotas en el checkout', 'neopay-woo' ),
					'default' => 'no',
				),
				'installments_label'      => array(
					'title'   => __( 'Etiqueta del selector', 'neopay-woo' ),
					'type'    => 'text',
					'default' => __( 'Cuotas', 'neopay-woo' ),
				),
				'installments_single_label' => array(
					'title'   => __( 'Etiqueta del pago unico', 'neopay-woo' ),
					'type'    => 'text',
					'default' => __( 'Un solo pago', 'neopay-woo' ),
				),
				'installments'            => array(
					'title'   => __( 'Cuotas disponibles', 'neopay-woo' ),
					'type'    => 'multiselect',
					'class'   => 'wc-enhanced-select',
					'default' => array( '3', '6', '10' ),
					'options' => array_combine(
						array_map( 'strval', self::$installment_options ),
						array_map(
							static function ( $n ) {
								/* translators: %d: cuotas */
								return sprintf( __( '%d cuotas', 'neopay-woo' ), $n );
							},
							self::$installment_options
						)
					),
				),
				'installments_min_amount' => array(
					'title'             => __( 'Monto minimo para cuotas', 'neopay-woo' ),
					'type'              => 'number',
					'default'           => '0',
					'custom_attributes' => array(
						'min'  => '0',
						'step' => '0.01',
					),
				),
				'installments_note'       => array(
					'title'       => __( 'Nota bajo el selector', 'neopay-woo' ),
					'type'        => 'text',
					'default'     => '',
					'description' => __( 'Opcional. Dejar vacio para no mostrar nota.', 'neopay-woo' ),
				),
				'address_title'           => array(
					'title'       => __( 'Datos de facturacion y entrega', 'neopay-woo' ),
					'type'        => 'title',
					'description' => __( 'Para BillTo (facturacion) y ShipTo (entrega) se usan primero los campos estandar de WooCommerce. Si un dato esta vacio se busca en los campos personalizados indicados abajo y, si se activa la deteccion, en los campos personalizados del pedido o del cliente. Si no aparece en ningun lado se envia vacio. El origen de cada dato queda en el pedido.', 'neopay-woo' ),
				),
				'detect_custom_fields'    => array(
					'title'   => __( 'Deteccion automatica', 'neopay-woo' ),
					'type'    => 'checkbox',
					'label'   => __( 'Buscar campos personalizados del checkout (plugins de campos, campos de bloques, perfil del cliente) cuando falte un dato estandar', 'neopay-woo' ),
					'default' => 'yes',
				),
			),
			self::address_map_fields(),
			array(
				'advanced_title'          => array(
					'title' => __( 'Opciones avanzadas', 'neopay-woo' ),
					'type'  => 'title',
				),
				'timeout'                 => array(
					'title'             => __( 'Tiempo de espera (segundos)', 'neopay-woo' ),
					'type'              => 'number',
					'default'           => '60',
					'description'       => __( 'Si NeoNet no responde en este tiempo se envia la reversa automatica (0400).', 'neopay-woo' ),
					'custom_attributes' => array(
						'min' => '15',
						'max' => '120',
					),
				),
				'show_test_cards'         => array(
					'title'   => __( 'Tarjetas de prueba', 'neopay-woo' ),
					'type'    => 'checkbox',
					'label'   => __( 'Mostrar las tarjetas de prueba en el checkout cuando el ambiente es Pruebas', 'neopay-woo' ),
					'default' => 'yes',
				),
				'log_retention'           => array(
					'title'       => __( 'Retencion de logs (dias)', 'neopay-woo' ),
					'type'        => 'number',
					'default'     => '180',
					'description' => __( '0 = no borrar nunca. Los logs nunca contienen la tarjeta completa, el CVV ni la fecha de vencimiento.', 'neopay-woo' ),
				),
				'debug'                   => array(
					'title'       => __( 'Depuracion', 'neopay-woo' ),
					'type'        => 'checkbox',
					'label'       => __( 'Escribir tambien el request/response enmascarado en WooCommerce > Estado > Registros', 'neopay-woo' ),
					'default'     => 'no',
				),
				'remove_data'             => array(
					'title'   => __( 'Desinstalacion', 'neopay-woo' ),
					'type'    => 'checkbox',
					'label'   => __( 'Borrar la tabla de logs al desinstalar el plugin (los datos de los pedidos se conservan siempre)', 'neopay-woo' ),
					'default' => 'no',
				),
			)
		);
	}

	/**
	 * Campos para indicar la clave de un campo personalizado por cada dato.
	 *
	 * @return array
	 */
	protected static function address_map_fields() {
		$labels = array(
			'FirstName'          => __( 'Nombre', 'neopay-woo' ),
			'LastName'           => __( 'Apellido', 'neopay-woo' ),
			'Company'            => __( 'Empresa', 'neopay-woo' ),
			'AddressOne'         => __( 'Direccion', 'neopay-woo' ),
			'AddressTwo'         => __( 'Direccion (linea 2)', 'neopay-woo' ),
			'Locality'           => __( 'Ciudad / municipio', 'neopay-woo' ),
			'AdministrativeArea' => __( 'Departamento', 'neopay-woo' ),
			'PostalCode'         => __( 'Codigo postal', 'neopay-woo' ),
			'Country'            => __( 'Pais', 'neopay-woo' ),
			'Email'              => __( 'Correo', 'neopay-woo' ),
			'PhoneNumber'        => __( 'Telefono', 'neopay-woo' ),
		);
		$fields = array();
		foreach ( NeoPay_Woo_Address::api_fields() as $api ) {
			$fields[ 'map_' . $api ] = array(
				/* translators: 1: dato, 2: campo del API */
				'title'       => sprintf( __( 'Campo personalizado: %1$s (%2$s)', 'neopay-woo' ), $labels[ $api ], $api ),
				'type'        => 'text',
				'default'     => '',
				'placeholder' => 'billing_celular, _mi_campo',
				'description' => __( 'Opcional. Clave(s) de meta del pedido o del cliente, separadas por comas.', 'neopay-woo' ),
			);
		}
		return $fields;
	}

	/**
	 * Avisos en la pantalla de ajustes.
	 */
	public function admin_options() {
		if ( 'production' === $this->get_environment() && ! wc_site_is_https() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'El ambiente de produccion requiere que el sitio funcione con HTTPS (TLS 1.2).', 'neopay-woo' ) . '</p></div>';
		}
		echo '<div class="notice notice-info inline"><p>';
		printf(
			/* translators: %s: enlace a los logs */
			esc_html__( 'Logs de transacciones: %s', 'neopay-woo' ),
			'<a href="' . esc_url( admin_url( 'admin.php?page=neopay-woo-logs' ) ) . '">' . esc_html__( 'WooCommerce > NeoPay Logs', 'neopay-woo' ) . '</a>'
		);
		echo '<br>' . esc_html__( 'Anulaciones: cambie el estado del pedido a "Cancelado". Se permite dentro de las 20 horas siguientes al pago y antes del cierre de NeoNet (22:00); si NeoNet la rechaza el pedido vuelve a su estado anterior.', 'neopay-woo' );
		echo '</p></div>';

		parent::admin_options();
	}

	/* ------------------------------------------------------------------
	 * Configuracion efectiva
	 * --------------------------------------------------------------- */

	/**
	 * Ambiente actual.
	 *
	 * @return string test|production
	 */
	public function get_environment() {
		return 'production' === $this->get_option( 'environment' ) ? 'production' : 'test';
	}

	/**
	 * Cliente del API para un ambiente.
	 *
	 * @param string|null $environment Ambiente (por defecto el actual).
	 * @return NeoPay_Woo_API
	 */
	public function get_api( $environment = null ) {
		$env    = $environment ? $environment : $this->get_environment();
		$prefix = 'production' === $env ? 'production' : 'test';

		$server_ip = trim( (string) $this->get_option( $prefix . '_merchant_server_ip' ) );
		if ( ! filter_var( $server_ip, FILTER_VALIDATE_IP ) ) {
			$server_ip = isset( $_SERVER['SERVER_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) ) : '';
		}
		// NOTA NEONET (confirmar): si paymentgwIP queda vacia se envia la IP del servidor.
		$gw_ip = trim( (string) $this->get_option( $prefix . '_paymentgw_ip' ) );
		if ( ! filter_var( $gw_ip, FILTER_VALIDATE_IP ) ) {
			$gw_ip = $server_ip;
		}

		return new NeoPay_Woo_API(
			array(
				'environment'        => $env,
				'rest_endpoint'      => trim( (string) $this->get_option( $prefix . '_rest_endpoint' ) ),
				'merchant_user'      => trim( (string) $this->get_option( $prefix . '_merchant_user' ) ),
				'merchant_passwd'    => trim( (string) $this->get_option( $prefix . '_merchant_passwd' ) ),
				'terminal_id'        => trim( (string) $this->get_option( $prefix . '_terminal_id' ) ),
				'merchant'           => trim( (string) $this->get_option( $prefix . '_merchant' ) ),
				'merchant_server_ip' => $server_ip,
				'paymentgw_ip'       => $gw_ip,
				'timeout'            => max( 15, min( 120, (int) $this->get_option( 'timeout', 60 ) ) ),
			)
		);
	}

	/**
	 * Cliente del API con el ambiente con que se cobro el pedido.
	 *
	 * @param WC_Order $order Pedido.
	 * @return NeoPay_Woo_API
	 */
	public function get_api_for_order( $order ) {
		$env = (string) $order->get_meta( '_neopay_environment' );
		return $this->get_api( $env ? $env : null );
	}

	/**
	 * Verifica que las credenciales del ambiente esten completas.
	 *
	 * @return bool
	 */
	protected function has_credentials() {
		$api = $this->get_api();
		return '' !== $api->get( 'rest_endpoint' ) && '' !== $api->get( 'merchant_user' ) && '' !== $api->get( 'merchant_passwd' ) && '' !== $api->get( 'terminal_id' ) && '' !== $api->get( 'merchant' );
	}

	/**
	 * Disponibilidad en el checkout.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}
		// NOTA NEONET (confirmar): que la afiliacion acepte USD ademas de GTQ.
		$currencies = apply_filters( 'neopay_woo_supported_currencies', array( 'GTQ', 'USD' ) );
		if ( ! in_array( get_woocommerce_currency(), $currencies, true ) ) {
			return false;
		}
		return $this->has_credentials();
	}

	/* ------------------------------------------------------------------
	 * Checkout
	 * --------------------------------------------------------------- */

	/**
	 * Scripts del checkout clasico.
	 */
	public function enqueue_scripts() {
		if ( ! is_checkout() && ! is_checkout_pay_page() ) {
			return;
		}
		wp_enqueue_style( 'neopay-woo', NEOPAY_WOO_URL . 'assets/css/checkout.css', array(), NEOPAY_WOO_VERSION );
		wp_enqueue_script( 'neopay-woo-checkout', NEOPAY_WOO_URL . 'assets/js/checkout.js', array( 'jquery' ), NEOPAY_WOO_VERSION, true );
	}

	/**
	 * Cuotas disponibles para un monto.
	 *
	 * @param float $total Total.
	 * @return array [ valor => etiqueta ].
	 */
	public function get_installment_choices( $total ) {
		$single  = (string) $this->get_option( 'installments_single_label', '' );
		$choices = array( '0' => '' !== $single ? $single : __( 'Un solo pago', 'neopay-woo' ) );
		if ( 'yes' !== $this->get_option( 'installments_enabled' ) ) {
			return $choices;
		}
		if ( (float) $total < (float) $this->get_option( 'installments_min_amount', 0 ) ) {
			return $choices;
		}
		$enabled = array_map( 'intval', (array) $this->get_option( 'installments', array() ) );
		foreach ( self::$installment_options as $n ) {
			if ( in_array( $n, $enabled, true ) ) {
				$choices[ (string) $n ] = sprintf(
					/* translators: 1: cuotas, 2: monto por cuota */
					__( '%1$d cuotas (aprox. %2$s por cuota)', 'neopay-woo' ),
					$n,
					wp_strip_all_tags( wc_price( (float) $total / $n ) )
				);
			}
		}
		return $choices;
	}

	/**
	 * Etiqueta del selector de cuotas.
	 *
	 * @return string
	 */
	public function get_installments_label() {
		$label = (string) $this->get_option( 'installments_label', '' );
		return '' !== $label ? $label : __( 'Cuotas', 'neopay-woo' );
	}

	/**
	 * Total a cobrar en el contexto actual (carrito o pedido a pagar).
	 *
	 * @return float
	 */
	public function get_current_total() {
		$order_id = absint( get_query_var( 'order-pay' ) );
		if ( $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				return (float) $order->get_total();
			}
		}
		return WC()->cart ? (float) WC()->cart->get_total( 'edit' ) : 0.0;
	}

	/**
	 * Tarjetas de prueba (archivo "Neonet NEOPAY - Parametros de Prueba").
	 *
	 * @return array
	 */
	public static function test_cards() {
		return array(
			array( 'brand' => 'Visa', 'number' => '4000 0000 0000 0416' ),
			array( 'brand' => 'Visa', 'number' => '4000 0000 0000 5944' ),
			array( 'brand' => 'Mastercard', 'number' => '2223 0000 1002 5549' ),
		);
	}

	/**
	 * Indica si deben mostrarse las tarjetas de prueba.
	 *
	 * @return bool
	 */
	public function show_test_cards() {
		return 'test' === $this->get_environment() && 'yes' === $this->get_option( 'show_test_cards', 'yes' );
	}

	/**
	 * Formulario de tarjeta (checkout clasico y pagina "pagar pedido").
	 */
	public function payment_fields() {
		if ( $this->description ) {
			echo wpautop( wp_kses_post( $this->description ) ); // phpcs:ignore
		}

		if ( 'test' === $this->get_environment() ) {
			echo '<p class="neopay-test-banner"><strong>' . esc_html__( 'AMBIENTE DE PRUEBAS: no se realizaran cargos reales.', 'neopay-woo' ) . '</strong></p>';
			if ( $this->show_test_cards() ) {
				echo '<details class="neopay-test-cards"><summary>' . esc_html__( 'Tarjetas de prueba', 'neopay-woo' ) . '</summary><ul>';
				foreach ( self::test_cards() as $card ) {
					echo '<li><code>' . esc_html( $card['number'] ) . '</code> ' . esc_html( $card['brand'] ) . '</li>';
				}
				echo '<li>' . esc_html__( 'Vencimiento 01/29 (2901), CVV 123', 'neopay-woo' ) . '</li></ul></details>';
			}
		}

		$choices = $this->get_installment_choices( $this->get_current_total() );
		?>
		<fieldset id="wc-neopay_woo-cc-form" class="wc-credit-card-form wc-payment-form neopay-card-form">
			<?php if ( count( $choices ) > 1 ) : ?>
				<p class="form-row form-row-wide neopay-installments-row">
					<label for="neopay_installments"><?php echo esc_html( $this->get_installments_label() ); ?></label>
					<select id="neopay_installments" name="neopay_installments">
						<?php foreach ( $choices as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php if ( $this->get_option( 'installments_note' ) ) : ?>
						<small class="neopay-note"><?php echo esc_html( $this->get_option( 'installments_note' ) ); ?></small>
					<?php endif; ?>
				</p>
			<?php endif; ?>
			<p class="form-row form-row-wide">
				<label for="neopay_card_name"><?php esc_html_e( 'Nombre en la tarjeta', 'neopay-woo' ); ?> <span class="required">*</span></label>
				<input id="neopay_card_name" name="neopay_card_name" class="input-text" type="text" autocomplete="cc-name" maxlength="45" spellcheck="false" />
			</p>
			<p class="form-row form-row-wide">
				<label for="neopay_card_number"><?php esc_html_e( 'Numero de tarjeta', 'neopay-woo' ); ?> <span class="required">*</span></label>
				<input id="neopay_card_number" name="neopay_card_number" class="input-text neopay-card-number" type="text" inputmode="numeric" autocomplete="cc-number" maxlength="23" spellcheck="false" placeholder="&bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull;" />
			</p>
			<p class="form-row form-row-first">
				<label for="neopay_card_expiry"><?php esc_html_e( 'Vencimiento (MM / AA)', 'neopay-woo' ); ?> <span class="required">*</span></label>
				<input id="neopay_card_expiry" name="neopay_card_expiry" class="input-text neopay-card-expiry" type="text" inputmode="numeric" autocomplete="cc-exp" maxlength="7" placeholder="MM / AA" />
			</p>
			<p class="form-row form-row-last">
				<label for="neopay_card_cvc"><?php esc_html_e( 'CVV', 'neopay-woo' ); ?> <span class="required">*</span></label>
				<input id="neopay_card_cvc" name="neopay_card_cvc" class="input-text neopay-card-cvc" type="password" inputmode="numeric" autocomplete="cc-csc" maxlength="4" placeholder="&bull;&bull;&bull;" />
			</p>
			<div class="clear"></div>
			<p class="neopay-brands">
				<img src="<?php echo esc_url( NEOPAY_WOO_URL . 'assets/images/visa.svg' ); ?>" alt="Visa" width="46" height="28" />
				<img src="<?php echo esc_url( NEOPAY_WOO_URL . 'assets/images/mastercard.svg' ); ?>" alt="Mastercard" width="46" height="28" />
			</p>
		</fieldset>
		<?php
	}

	/**
	 * Lee la tarjeta enviada. Solo vive en memoria durante la peticion.
	 *
	 * @return array
	 */
	protected function get_posted_card() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce ya valida el nonce del checkout.
		$expiry = NeoPay_Woo_Helper::digits( wp_unslash( $_POST['neopay_card_expiry'] ?? '' ) );
		if ( 6 === strlen( $expiry ) ) {
			$expiry = substr( $expiry, 0, 2 ) . substr( $expiry, 4, 2 ); // MM/AAAA.
		}
		$card = array(
			'name'         => sanitize_text_field( wp_unslash( $_POST['neopay_card_name'] ?? '' ) ),
			'pan'          => NeoPay_Woo_Helper::digits( wp_unslash( $_POST['neopay_card_number'] ?? '' ) ),
			'exp_month'    => (int) substr( $expiry, 0, 2 ),
			'exp_year'     => 4 === strlen( $expiry ) ? 2000 + (int) substr( $expiry, 2, 2 ) : 0,
			'cvv'          => NeoPay_Woo_Helper::digits( wp_unslash( $_POST['neopay_card_cvc'] ?? '' ) ),
			'installments' => absint( wp_unslash( $_POST['neopay_installments'] ?? 0 ) ),
		);
		// phpcs:enable
		$brand              = NeoPay_Woo_Helper::detect_brand( $card['pan'] );
		$card['brand']      = $brand ? $brand['scheme'] : '';
		$card['brand_type'] = $brand ? $brand['type'] : '';
		return $card;
	}

	/**
	 * Valida la tarjeta. Devuelve el mensaje de error o cadena vacia.
	 *
	 * @param array $card  Tarjeta.
	 * @param float $total Total del pedido.
	 * @return string
	 */
	protected function card_error( array $card, $total ) {
		if ( strlen( trim( $card['name'] ) ) < 3 ) {
			return __( 'Ingrese el nombre como aparece en la tarjeta.', 'neopay-woo' );
		}
		if ( ! NeoPay_Woo_Helper::luhn( $card['pan'] ) ) {
			return __( 'El numero de tarjeta no es valido.', 'neopay-woo' );
		}
		if ( '' === $card['brand'] ) {
			return __( 'Solo se aceptan tarjetas Visa y Mastercard.', 'neopay-woo' );
		}
		$now_y = (int) gmdate( 'Y' );
		$now_m = (int) gmdate( 'n' );
		if ( $card['exp_month'] < 1 || $card['exp_month'] > 12 || $card['exp_year'] < $now_y || $card['exp_year'] > $now_y + 20 || ( $card['exp_year'] === $now_y && $card['exp_month'] < $now_m ) ) {
			return __( 'La fecha de vencimiento no es valida.', 'neopay-woo' );
		}
		if ( ! preg_match( '/^\d{3,4}$/', $card['cvv'] ) || preg_match( '/^(0{3,4}|9{3,4})$/', $card['cvv'] ) ) {
			return __( 'El codigo de seguridad (CVV) no es valido.', 'neopay-woo' );
		}
		if ( $card['installments'] > 1 && ! array_key_exists( (string) $card['installments'], $this->get_installment_choices( $total ) ) ) {
			return __( 'La cantidad de cuotas seleccionada no esta disponible.', 'neopay-woo' );
		}
		return '';
	}

	/**
	 * Validacion del checkout (clasico y bloques).
	 *
	 * @return bool
	 */
	public function validate_fields() {
		$error = $this->card_error( $this->get_posted_card(), $this->get_current_total() );
		if ( $error ) {
			wc_add_notice( $error, 'error' );
			return false;
		}
		return true;
	}

	/**
	 * Resolvedor de datos de facturacion y entrega.
	 *
	 * @return NeoPay_Woo_Address
	 */
	public function address() {
		return new NeoPay_Woo_Address( $this->settings );
	}

	/**
	 * Procesa el pago.
	 *
	 * @param int $order_id Pedido.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array( 'result' => 'failure' );
		}

		$card  = $this->get_posted_card();
		$error = $this->card_error( $card, (float) $order->get_total() );
		if ( $error ) {
			wc_add_notice( $error, 'error' );
			return array( 'result' => 'failure' );
		}

		if ( $order->is_paid() ) {
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}

		$environment = $this->get_environment();
		$api         = $this->get_api();
		$audit       = NeoPay_Woo_Helper::next_audit_number( $environment );
		$installs    = $card['installments'] > 1 ? $card['installments'] : 0;
		$additional  = NeoPay_Woo_Helper::installments_additional_data( $installs );

		// Datos no sensibles de la transaccion en curso.
		$order->update_meta_data( '_neopay_environment', $environment );
		$order->update_meta_data( '_neopay_audit_number', $audit );
		$order->update_meta_data( '_neopay_card_brand', $card['brand'] );
		$order->update_meta_data( '_neopay_card_last4', substr( $card['pan'], -4 ) );
		$order->update_meta_data( '_neopay_card_masked', NeoPay_Woo_Helper::mask_pan( $card['pan'] ) );
		$order->update_meta_data( '_neopay_cardholder', NeoPay_Woo_Helper::sanitize_name( $card['name'], 45 ) );
		$order->update_meta_data( '_neopay_installments', $installs );
		$order->update_meta_data( '_neopay_additional_data', $additional );
		$order->update_meta_data( '_neopay_amount_minor', NeoPay_Woo_Helper::to_minor( $order->get_total() ) );
		$order->update_meta_data( '_neopay_terminal_id', $api->get( 'terminal_id' ) );
		$order->update_meta_data( '_neopay_merchant', $api->get( 'merchant' ) );
		$order->delete_meta_data( '_neopay_ctx' );
		$order->delete_meta_data( '_neopay_last_error' );
		$order->save();

		return $this->process_rest( $order, $api, $card, $audit, $additional );
	}

	/**
	 * Paso 1 REST: si el emisor requiere 3DS se redirige a la recoleccion de
	 * datos del dispositivo (DDC).
	 *
	 * @param WC_Order       $order      Pedido.
	 * @param NeoPay_Woo_API $api        API.
	 * @param array          $card       Tarjeta.
	 * @param string         $audit      Auditoria.
	 * @param string         $additional AdditionalData.
	 * @return array
	 */
	protected function process_rest( $order, $api, array $card, $audit, $additional ) {
		$ctx = array(
			'environment'  => $api->get( 'environment' ),
			'audit_number' => $audit,
			'order_info'   => substr( 'ORDER-' . $order->get_order_number() . '-A1', 0, 50 ),
			'amount_minor' => NeoPay_Woo_Helper::to_minor( $order->get_total() ),
			'card_masked'  => NeoPay_Woo_Helper::mask_pan( $card['pan'] ),
			'created'      => time(),
		);

		// Facturacion y entrega: campos estandar de WooCommerce y, si faltan,
		// los campos personalizados de la tienda. Sin datos de respaldo genericos.
		$sources = array();
		$bill_to = $this->address()->bill_to( $order, $sources );
		$ship_to = $this->address()->ship_to( $order, $sources );
		$order->update_meta_data( '_neopay_address_sources', $sources );
		$order->save();

		$return_url = NeoPay_Woo_3DS::url( 'acs', $order );
		$result     = $api->rest_step1( $order, $card, $bill_to, $ship_to, $audit, $additional, $return_url );
		$this->record( $order, 'rest_paso1', $result );

		// NOTA NEONET (confirmar): se envia reversa 0400 si el paso 1 no responde.
		if ( ! $result['transport_ok'] && $result['timeout'] ) {
			$this->record( $order, 'rest_reversa_p1', $api->rest_reversal( $order->get_id(), $ctx, '1' ) );
		}

		if ( $result['approved'] ) {
			// NOTA NEONET (confirmar): se asume que el paso 1 puede devolver la venta
			// ya autorizada (TypeOperation 1 + 00) sin pasar por 3DS.
			$this->complete_payment( $order, $result );
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}

		if ( '3' === $result['type_operation'] && '00' === $result['response_code'] && $result['pa_reference_id'] && $result['pa_access_token'] && $result['pa_url'] ) {
			$ctx['reference_id'] = $result['pa_reference_id'];
			$ctx['access_token'] = $result['pa_access_token'];
			$ctx['ddc_url']      = $result['pa_url'];
			$order->update_meta_data( '_neopay_ctx', $ctx );
			$order->update_meta_data( '_neopay_3ds_status', 'iniciado' );
			$order->update_status( 'pending', __( 'NeoPay: autenticacion 3-D Secure iniciada (paso 1).', 'neopay-woo' ) );
			$order->save();

			return array(
				'result'   => 'success',
				'redirect' => NeoPay_Woo_3DS::url( 'ddc', $order ),
			);
		}

		$this->fail_payment( $order, $result );
		wc_add_notice( $this->failure_message( $result ), 'error' );
		return array( 'result' => 'failure' );
	}

	/**
	 * Mensaje para el cliente.
	 *
	 * @param array $result Resultado.
	 * @return string
	 */
	public function failure_message( array $result ) {
		if ( ! $result['transport_ok'] ) {
			return __( 'No fue posible comunicarse con el procesador de pagos. No se realizo ningun cargo; intente nuevamente en unos minutos.', 'neopay-woo' );
		}
		return NeoPay_Woo_Helper::customer_message( $result['response_code'] );
	}

	/**
	 * Marca el pedido como pagado y guarda los datos del comprobante.
	 *
	 * @param WC_Order $order  Pedido.
	 * @param array    $result Resultado aprobado.
	 */
	public function complete_payment( $order, array $result ) {
		if ( $order->is_paid() ) {
			return;
		}

		$audit = $result['audit_number'] ? $result['audit_number'] : (string) $order->get_meta( '_neopay_audit_number' );

		$order->update_meta_data( '_neopay_audit_number', $audit );
		$order->update_meta_data( '_neopay_reference_number', $result['reference_number'] );
		$order->update_meta_data( '_neopay_authorization_number', $result['authorization_number'] );
		$order->update_meta_data( '_neopay_response_code', $result['response_code'] );
		$order->update_meta_data( '_neopay_message_type', $result['message_type'] );
		$order->update_meta_data( '_neopay_type_operation', $result['type_operation'] );
		$order->update_meta_data( '_neopay_transaction_time', current_time( 'mysql' ) );
		if ( $result['remote_time'] ) {
			$order->update_meta_data( '_neopay_remote_time', $result['remote_time'] );
		}
		$order->update_meta_data( '_neopay_status', 'aprobada' );

		// El JWT ya no se necesita: se elimina del pedido.
		$ctx = $order->get_meta( '_neopay_ctx' );
		if ( is_array( $ctx ) ) {
			unset( $ctx['access_token'], $ctx['step4_jwt'] );
			$ctx['completed'] = time();
			$order->update_meta_data( '_neopay_ctx', $ctx );
		}

		$order->add_order_note(
			sprintf(
				/* translators: 1: autorizacion, 2: referencia, 3: auditoria, 4: tarjeta */
				__( "NeoPay: pago APROBADO.\nAutorizacion: %1\$s\nReferencia: %2\$s\nAuditoria: %3\$s\nTarjeta: %4\$s", 'neopay-woo' ),
				$result['authorization_number'],
				$result['reference_number'],
				$audit,
				$order->get_meta( '_neopay_card_brand' ) . ' ' . NeoPay_Woo_Helper::voucher_pan( $order->get_meta( '_neopay_card_last4' ) )
			)
		);

		$order->payment_complete( $result['reference_number'] ? $result['reference_number'] : $result['authorization_number'] );

		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}

		do_action( 'neopay_woo_payment_approved', $order, $result );
	}

	/**
	 * Marca el pedido como fallido.
	 *
	 * @param WC_Order $order  Pedido.
	 * @param array    $result Resultado.
	 * @param string   $extra  Detalle adicional para la nota.
	 */
	public function fail_payment( $order, array $result, $extra = '' ) {
		$detail = $result['transport_ok']
			? sprintf( '%s - %s', $result['response_code'], NeoPay_Woo_Helper::response_label( $result['response_code'] ) )
			: $result['error'];
		if ( ! empty( $result['alt_message'] ) ) {
			$detail .= ' (' . $result['alt_message'] . ')';
		}

		$order->update_meta_data( '_neopay_status', 'rechazada' );
		$order->update_meta_data( '_neopay_response_code', $result['response_code'] );
		$order->update_meta_data( '_neopay_last_error', $this->failure_message( $result ) );
		$ctx = $order->get_meta( '_neopay_ctx' );
		if ( is_array( $ctx ) ) {
			unset( $ctx['access_token'], $ctx['step4_jwt'] );
			$order->update_meta_data( '_neopay_ctx', $ctx );
		}
		$order->save();

		$order->update_status(
			'failed',
			trim(
				sprintf(
					/* translators: %s: detalle */
					__( 'NeoPay: pago NO aprobado. %s', 'neopay-woo' ),
					$detail
				) . ' ' . $extra
			)
		);

		do_action( 'neopay_woo_payment_failed', $order, $result );
	}

	/**
	 * Agrega una llamada al historial del pedido (respuesta del API enmascarada).
	 *
	 * @param WC_Order $order  Pedido.
	 * @param string   $step   Paso.
	 * @param array    $result Resultado.
	 */
	public function record( $order, $step, array $result ) {
		$history = $order->get_meta( '_neopay_audit' );
		if ( ! is_array( $history ) ) {
			$history = array();
		}

		$history[] = array(
			'time'                 => current_time( 'mysql' ),
			'step'                 => $step,
			'http_code'            => $result['http_code'],
			'duration_ms'          => $result['duration_ms'] ?? 0,
			'message_type'         => $result['message_type'],
			'type_operation'       => $result['type_operation'],
			'response_code'        => $result['response_code'],
			'response_label'       => '' !== $result['response_code'] ? NeoPay_Woo_Helper::response_label( $result['response_code'] ) : '',
			'approved'             => $result['approved'] ? 'yes' : 'no',
			'audit_number'         => $result['audit_number'],
			'reference_number'     => $result['reference_number'],
			'authorization_number' => $result['authorization_number'],
			'pa_step'              => $result['pa_step'],
			'alt_message'          => $result['alt_message'],
			'error'                => $result['error'],
			'response'             => NeoPay_Woo_Logger::redact( $result['raw'] ),
		);

		$order->update_meta_data( '_neopay_audit', array_slice( $history, -40 ) );
		$order->save();
	}
}
