<?php
/**
 * Flujo 3-D Secure del API REST de NeoPay.
 *
 *   Paso 1  process_payment()          -> ReferenceId + JWT + URL DDC
 *   DDC     ?np=ddc                     -> iframe oculto que envia el JWT
 *   Paso 3  ?np=step3                   -> autoriza (sin friccion) o pide Step-Up
 *   Paso 4  ?np=step4                   -> iframe con el desafio del emisor
 *   ACS     ?np=acs (UrlCommerce)       -> el emisor regresa aqui
 *   Paso 5  ?np=step5                   -> autorizacion final
 *
 * El estado vive en el pedido (no en la sesion PHP): el retorno del ACS es un POST de otro dominio y las cookies de
 * sesion no viajan. Cada URL lleva el order_key como prueba de acceso.
 *
 * @package NeoPay_Woo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class NeoPay_Woo_3DS
 */
class NeoPay_Woo_3DS {

	/**
	 * Vigencia del contexto 3DS.
	 *
	 * NOTA PROGRAMADOR: 30 minutos; pasado ese tiempo el pedido queda fallido y
	 * el cliente debe reintentar.
	 */
	const CTX_TTL = 1800;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'woocommerce_api_neopay_woo', array( $this, 'route' ) );
		add_action( 'before_woocommerce_pay', array( $this, 'show_failure_on_pay_page' ), 5 );
	}

	/**
	 * URL de un paso del flujo.
	 *
	 * @param string   $step  Paso.
	 * @param WC_Order $order Pedido.
	 * @param array    $extra Parametros extra.
	 * @return string
	 */
	public static function url( $step, $order, $extra = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'np'    => $step,
					'order' => $order->get_id(),
					'key'   => $order->get_order_key(),
				),
				$extra
			),
			WC()->api_request_url( 'neopay_woo' )
		);
	}

	/**
	 * Pasarela.
	 *
	 * @return NeoPay_Woo_Gateway|null
	 */
	protected function gateway() {
		return NeoPay_Woo::gateway();
	}

	/**
	 * Enrutador de wc-api=neopay_woo.
	 */
	public function route() {
		nocache_headers();

		// phpcs:disable WordPress.Security.NonceVerification -- el acceso se valida con el order_key.
		$step     = sanitize_key( wp_unslash( $_GET['np'] ?? '' ) );
		$order_id = absint( $_GET['order'] ?? 0 );
		$key      = sanitize_text_field( wp_unslash( $_GET['key'] ?? '' ) );
		// phpcs:enable

		$order = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order || ! $key || ! hash_equals( (string) $order->get_order_key(), $key ) || NeoPay_Woo_Gateway::ID !== $order->get_payment_method() ) {
			status_header( 403 );
			$this->render_page( __( 'Acceso no valido', 'neopay-woo' ), '<p>' . esc_html__( 'El enlace de pago no es valido o ya expiro.', 'neopay-woo' ) . '</p>' );
		}

		switch ( $step ) {
			case 'ddc':
				$this->ddc_page( $order );
				break;
			case 'ddcok':
				$this->ddc_status( $order );
				break;
			case 'step3':
				$this->step3( $order );
				break;
			case 'step4':
				$this->step4_page( $order );
				break;
			case 'acs':
				$this->acs_return( $order );
				break;
			case 'step5':
				$this->step5( $order );
				break;
			case 'voucher':
				NeoPay_Woo_Voucher::print_page( $order );
				break;
		}

		status_header( 404 );
		exit;
	}

	/* ------------------------------------------------------------------
	 * Pasos
	 * --------------------------------------------------------------- */

	/**
	 * Contexto vigente o redireccion si ya no aplica.
	 *
	 * @param WC_Order $order Pedido.
	 * @return array
	 */
	protected function require_ctx( $order ) {
		if ( $order->is_paid() ) {
			$this->redirect( $this->gateway()->get_return_url( $order ) );
		}

		$ctx = $order->get_meta( '_neopay_ctx' );
		if ( ! is_array( $ctx ) || empty( $ctx['reference_id'] ) || empty( $ctx['access_token'] ) ) {
			$this->redirect( $this->failure_url( $order ) );
		}

		if ( time() - (int) ( $ctx['created'] ?? 0 ) > self::CTX_TTL ) {
			$order->update_meta_data( '_neopay_last_error', __( 'La autenticacion de la tarjeta expiro. Intente nuevamente.', 'neopay-woo' ) );
			$order->delete_meta_data( '_neopay_ctx' );
			$order->save();
			$order->update_status( 'failed', __( 'NeoPay: la sesion 3-D Secure expiro.', 'neopay-woo' ) );
			$this->redirect( $this->failure_url( $order ) );
		}

		return $ctx;
	}

	/**
	 * Recoleccion de datos del dispositivo (Device Data Collection).
	 *
	 * @param WC_Order $order Pedido.
	 */
	protected function ddc_page( $order ) {
		$ctx = $this->require_ctx( $order );

		$parts  = wp_parse_url( $ctx['ddc_url'] );
		$origin = isset( $parts['scheme'], $parts['host'] ) ? $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) : '';

		$body = '<div class="np-spinner" aria-hidden="true"></div>'
			. '<h1>' . esc_html__( 'Verificando su tarjeta...', 'neopay-woo' ) . '</h1>'
			. '<p>' . esc_html__( 'Estamos validando su pago con el banco emisor. No cierre ni actualice esta ventana.', 'neopay-woo' ) . '</p>'
			. '<p class="np-muted">' . esc_html( sprintf( /* translators: %s: pedido */ __( 'Pedido #%s', 'neopay-woo' ), $order->get_order_number() ) ) . '</p>'
			. '<iframe name="np-ddc" title="ddc" width="1" height="1" style="position:absolute;left:-9999px;top:-9999px;border:0;opacity:0"></iframe>'
			. '<form id="np-ddc-form" method="POST" target="np-ddc" action="' . esc_url( $ctx['ddc_url'] ) . '">'
			. '<input type="hidden" name="JWT" value="' . esc_attr( $ctx['access_token'] ) . '" /></form>';

		$script = '(function(){'
			. 'var NEXT=' . wp_json_encode( self::url( 'step3', $order ) ) . ',STATUS=' . wp_json_encode( self::url( 'ddcok', $order ) ) . ',ORIGIN=' . wp_json_encode( $origin ) . ',done=false;'
			. 'function go(){if(done)return;done=true;window.location.replace(NEXT);}'
			. 'function ok(){try{fetch(STATUS,{method:"POST",credentials:"same-origin",keepalive:true}).finally(go);}catch(e){go();}setTimeout(go,1500);}'
			. 'window.addEventListener("message",function(ev){if(ORIGIN&&ev.origin!==ORIGIN)return;var d=ev.data,s=false;'
			. 'if(typeof d==="string"){var l=d.toLowerCase();s=(l==="true"||l==="success"||l==="ok"||l.indexOf("profile.completed")!==-1);if(!s){try{var j=JSON.parse(d);s=!!(j&&(j.Status===true||j.MessageType==="profile.completed"));}catch(e){}}}'
			. 'else if(d&&typeof d==="object"){s=(d.Status===true||d.status===true||d.MessageType==="profile.completed");}'
			. 'if(s)ok();},false);'
			. 'document.getElementById("np-ddc-form").submit();'
			// NOTA PROGRAMADOR: si el DDC no avisa en 15 s se continua al paso 3.
			. 'setTimeout(go,15000);'
			. '})();';

		$this->render_page( __( 'Verificando su tarjeta', 'neopay-woo' ), $body, $script );
	}

	/**
	 * El navegador confirma que el DDC termino.
	 *
	 * @param WC_Order $order Pedido.
	 */
	protected function ddc_status( $order ) {
		$ctx = $order->get_meta( '_neopay_ctx' );
		if ( is_array( $ctx ) && empty( $ctx['ddc_ok'] ) ) {
			$ctx['ddc_ok'] = time();
			$order->update_meta_data( '_neopay_ctx', $ctx );
			$order->save();
		}
		wp_send_json( array( 'ok' => true ) );
	}

	/**
	 * Paso 3: autorizacion sin friccion o solicitud de Step-Up.
	 *
	 * @param WC_Order $order Pedido.
	 */
	protected function step3( $order ) {
		$ctx = $this->require_ctx( $order );

		if ( ! empty( $ctx['step4_url'] ) ) {
			$this->redirect( self::url( 'step4', $order ) );
		}
		if ( ! $this->lock( $order->get_id(), 'p3' ) ) {
			$this->wait_page( self::url( 'step3', $order ) );
		}

		// NOTA NEONET (confirmar): si el navegador no confirmo el DDC se envia el
		// paso 3 de todas formas (solo queda una advertencia en el log).
		if ( empty( $ctx['ddc_ok'] ) ) {
			NeoPay_Woo_Logger::log( sprintf( '[P3] pedido #%d: DDC no confirmado por el navegador, se continua.', $order->get_id() ), 'warning' );
		}

		$gateway = $this->gateway();
		$api     = $gateway->get_api_for_order( $order );
		$result  = $api->rest_step( $order->get_id(), $ctx, '3' );
		$gateway->record( $order, 'rest_paso3', $result );

		if ( ! $result['transport_ok'] ) {
			$gateway->record( $order, 'rest_reversa_p3', $api->rest_reversal( $order->get_id(), $ctx, '3' ) );
			$gateway->fail_payment( $order, $result, __( 'Se envio reversa automatica.', 'neopay-woo' ) );
			$this->unlock( $order->get_id(), 'p3' );
			$this->redirect( $this->failure_url( $order ) );
		}

		if ( $result['approved'] ) {
			$order->update_meta_data( '_neopay_3ds_status', 'sin_friccion' );
			$gateway->complete_payment( $order, $result );
			$this->unlock( $order->get_id(), 'p3' );
			$this->redirect( $gateway->get_return_url( $order ) );
		}

		if ( '3' === $result['type_operation'] && '00' === $result['response_code'] && '4' === $result['pa_step'] ) {
			$url = trim( $result['pa_url'] );
			$jwt = $result['pa_access_token'];

			// NOTA PROGRAMADOR: en produccion la URL del Step-Up debe ser HTTPS; en
			// pruebas se acepta HTTP para poder usar un simulador local.
			$scheme_ok = 0 === stripos( $url, 'https://' ) || ( 'test' === ( $ctx['environment'] ?? '' ) && 0 === stripos( $url, 'http://' ) );

			if ( ! $jwt || ! $scheme_ok || false !== stripos( $url, '/V1/Cruise/Collect' ) ) {
				$gateway->fail_payment( $order, $result, __( 'Parametros de Step-Up invalidos.', 'neopay-woo' ) );
				$this->unlock( $order->get_id(), 'p3' );
				$this->redirect( $this->failure_url( $order ) );
			}

			$ctx                 = $order->get_meta( '_neopay_ctx' );
			$ctx['step4_url'] = $url;
			$ctx['step4_jwt'] = $jwt;
			$order->update_meta_data( '_neopay_ctx', $ctx );
			$order->update_meta_data( '_neopay_3ds_status', 'desafio' );
			$order->save();
			$order->add_order_note( __( 'NeoPay: el emisor solicito autenticacion (Step-Up 3-D Secure).', 'neopay-woo' ) );

			$this->unlock( $order->get_id(), 'p3' );
			$this->redirect( self::url( 'step4', $order ) );
		}

		$gateway->fail_payment( $order, $result, __( '(paso 3)', 'neopay-woo' ) );
		$this->unlock( $order->get_id(), 'p3' );
		$this->redirect( $this->failure_url( $order ) );
	}

	/**
	 * Paso 4: desafio del emisor dentro de un iframe.
	 *
	 * @param WC_Order $order Pedido.
	 */
	protected function step4_page( $order ) {
		$ctx = $this->require_ctx( $order );
		if ( empty( $ctx['step4_url'] ) || empty( $ctx['step4_jwt'] ) ) {
			$this->redirect( self::url( 'step3', $order ) );
		}

		$body = '<h1>' . esc_html__( 'Autenticacion de su banco', 'neopay-woo' ) . '</h1>'
			. '<p>' . esc_html__( 'Complete la verificacion solicitada por su banco para finalizar el pago.', 'neopay-woo' ) . '</p>'
			. '<iframe name="np-stepup" title="3-D Secure" class="np-stepup"></iframe>'
			. '<form id="np-stepup-form" method="POST" target="np-stepup" action="' . esc_url( $ctx['step4_url'] ) . '">'
			. '<input type="hidden" name="JWT" value="' . esc_attr( $ctx['step4_jwt'] ) . '" />'
			. '<input type="hidden" name="MD" value="' . esc_attr( (string) $order->get_id() ) . '" /></form>';

		$this->render_page( __( 'Autenticacion 3-D Secure', 'neopay-woo' ), $body, 'document.getElementById("np-stepup-form").submit();' );
	}

	/**
	 * Retorno del ACS (UrlCommerce). Llega dentro del iframe del Step-Up.
	 *
	 * @param WC_Order $order Pedido.
	 */
	protected function acs_return( $order ) {
		$ctx = $order->get_meta( '_neopay_ctx' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- POST del ACS del emisor.
		$posted = wp_unslash( $_POST );
		if ( is_array( $ctx ) && empty( $ctx['acs_return'] ) && ! empty( $posted ) ) {
			$summary = array();
			foreach ( (array) $posted as $k => $v ) {
				$summary[ sanitize_key( $k ) ] = is_scalar( $v ) ? NeoPay_Woo_Logger::truncate( sanitize_text_field( (string) $v ), 40 ) : '[array]';
			}
			$ctx['acs_return'] = array(
				'time' => time(),
				'post' => $summary,
			);
			$order->update_meta_data( '_neopay_ctx', $ctx );
			$order->save();
			NeoPay_Woo_Logger::log( sprintf( '[ACS] pedido #%d retorno del emisor', $order->get_id() ), 'info', $summary );
		}

		$next = self::url( 'step5', $order );
		$this->render_page(
			__( 'Procesando', 'neopay-woo' ),
			'<div class="np-spinner" aria-hidden="true"></div><p>' . esc_html__( 'Procesando autenticacion...', 'neopay-woo' ) . '</p>',
			'(function(){var u=' . wp_json_encode( $next ) . ';try{if(window.top&&window.top!==window){window.top.location.href=u;}else{window.location.replace(u);}}catch(e){window.location.replace(u);}})();'
		);
	}

	/**
	 * Paso 5: autorizacion final despues del desafio.
	 *
	 * @param WC_Order $order Pedido.
	 */
	protected function step5( $order ) {
		$ctx = $this->require_ctx( $order );

		if ( ! empty( $ctx['step5_done'] ) ) {
			$this->redirect( $order->is_paid() ? $this->gateway()->get_return_url( $order ) : $this->failure_url( $order ) );
		}
		if ( ! $this->lock( $order->get_id(), 'p5' ) ) {
			$this->wait_page( self::url( 'step5', $order ) );
		}

		// Marca antes de llamar: el paso 5 nunca se envia dos veces.
		$ctx['step5_done'] = time();
		$order->update_meta_data( '_neopay_ctx', $ctx );
		$order->save();

		$gateway = $this->gateway();
		$api     = $gateway->get_api_for_order( $order );
		$result  = $api->rest_step( $order->get_id(), $ctx, '5' );
		$gateway->record( $order, 'rest_paso5', $result );

		if ( ! $result['transport_ok'] ) {
			$gateway->record( $order, 'rest_reversa_p5', $api->rest_reversal( $order->get_id(), $ctx, '5' ) );
			$gateway->fail_payment( $order, $result, __( 'Se envio reversa automatica.', 'neopay-woo' ) );
			$this->unlock( $order->get_id(), 'p5' );
			$this->redirect( $this->failure_url( $order ) );
		}

		if ( $result['approved'] && ( '' === $result['pa_step'] || '5' === $result['pa_step'] ) ) {
			$order->update_meta_data( '_neopay_3ds_status', 'autenticado' );
			$gateway->complete_payment( $order, $result );
			$this->unlock( $order->get_id(), 'p5' );
			$this->redirect( $gateway->get_return_url( $order ) );
		}

		$gateway->fail_payment( $order, $result, __( '(paso 5)', 'neopay-woo' ) );
		$this->unlock( $order->get_id(), 'p5' );
		$this->redirect( $this->failure_url( $order ) );
	}

	/* ------------------------------------------------------------------
	 * Utilidades
	 * --------------------------------------------------------------- */

	/**
	 * URL a la que vuelve el cliente cuando el pago no se completa.
	 *
	 * Ademas deja el motivo como aviso de WooCommerce en la sesion, para que se
	 * muestre en cualquier pagina a la que llegue el cliente (checkout o "Pagar
	 * pedido"), aunque la tienda lo redirija.
	 *
	 * @param WC_Order $order Pedido.
	 * @return string
	 */
	protected function failure_url( $order ) {
		$this->queue_failure_notice( $order );
		return add_query_arg( 'neopay_failed', '1', $order->get_checkout_payment_url() );
	}

	/**
	 * Motivo del rechazo para el cliente.
	 *
	 * @param WC_Order $order Pedido.
	 * @return string
	 */
	protected function failure_message( $order ) {
		$message = (string) $order->get_meta( '_neopay_last_error' );
		return $message ? $message : __( 'Su pago no pudo ser procesado. Intente nuevamente.', 'neopay-woo' );
	}

	/**
	 * Guarda el motivo del rechazo como aviso de WooCommerce (una sola vez).
	 *
	 * @param WC_Order $order Pedido.
	 */
	protected function queue_failure_notice( $order ) {
		if ( ! function_exists( 'wc_add_notice' ) || ! WC()->session ) {
			return;
		}
		$message = $this->failure_message( $order );
		if ( ! wc_has_notice( $message, 'error' ) ) {
			wc_add_notice( $message, 'error' );
		}
	}

	/**
	 * Muestra el motivo del rechazo en la pagina de pago si no llego como aviso
	 * de sesion (por ejemplo, si el cliente abre el enlace en otro navegador).
	 */
	public function show_failure_on_pay_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['neopay_failed'] ) ) {
			return;
		}
		$order = wc_get_order( absint( get_query_var( 'order-pay' ) ) );
		if ( ! $order || $order->is_paid() ) {
			return;
		}
		$message = $this->failure_message( $order );
		if ( function_exists( 'wc_has_notice' ) && WC()->session && wc_has_notice( $message, 'error' ) ) {
			return; // WooCommerce lo imprime con el resto de avisos.
		}
		wc_print_notice( $message, 'error' );
	}

	/**
	 * Bloqueo atomico (INSERT IGNORE sobre una opcion) para no repetir pasos.
	 *
	 * @param int    $order_id Pedido.
	 * @param string $name     Nombre.
	 * @return bool
	 */
	protected function lock( $order_id, $name ) {
		global $wpdb;
		$option = 'neopay_woo_lock_' . $name . '_' . $order_id;

		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $option, (string) time() ) ); // phpcs:ignore
		if ( $inserted ) {
			return true;
		}

		$since = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) ); // phpcs:ignore
		if ( time() - $since > 120 ) {
			$wpdb->update( $wpdb->options, array( 'option_value' => (string) time() ), array( 'option_name' => $option ) ); // phpcs:ignore
			return true;
		}
		return false;
	}

	/**
	 * Libera el bloqueo.
	 *
	 * @param int    $order_id Pedido.
	 * @param string $name     Nombre.
	 */
	protected function unlock( $order_id, $name ) {
		global $wpdb;
		$option = 'neopay_woo_lock_' . $name . '_' . $order_id;
		$wpdb->delete( $wpdb->options, array( 'option_name' => $option ) ); // phpcs:ignore
		wp_cache_delete( $option, 'options' );
	}

	/**
	 * Pagina de espera cuando otro proceso ya esta autorizando.
	 *
	 * @param string $url URL a reintentar.
	 */
	protected function wait_page( $url ) {
		$this->render_page(
			__( 'Procesando', 'neopay-woo' ),
			'<div class="np-spinner" aria-hidden="true"></div><p>' . esc_html__( 'Estamos procesando su autorizacion. No actualice la pagina.', 'neopay-woo' ) . '</p>',
			'setTimeout(function(){window.location.replace(' . wp_json_encode( $url ) . ');},4000);'
		);
	}

	/**
	 * Redireccion y fin.
	 *
	 * @param string $url URL.
	 */
	protected function redirect( $url ) {
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Pagina HTML independiente del tema (evita scripts del tema durante 3DS).
	 *
	 * @param string $title  Titulo.
	 * @param string $body   HTML ya escapado.
	 * @param string $script JS en linea.
	 */
	protected function render_page( $title, $body, $script = '' ) {
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex, nofollow" />
<title><?php echo esc_html( $title . ' - ' . get_bloginfo( 'name' ) ); ?></title>
<style>
body{margin:0;font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:#f6f7f9;color:#1d2327}
.np-wrap{max-width:520px;margin:6vh auto;padding:28px 20px;background:#fff;border-radius:10px;box-shadow:0 1px 3px rgba(0,0,0,.08);text-align:center}
h1{font-size:20px;margin:12px 0}
.np-muted{color:#646970;font-size:13px}
.np-spinner{width:38px;height:38px;margin:4px auto;border:4px solid #e2e4e7;border-top-color:#5b2a86;border-radius:50%;animation:np 0.9s linear infinite}
@keyframes np{to{transform:rotate(360deg)}}
.np-stepup{display:block;margin:12px auto 0;width:100%;max-width:400px;height:600px;border:0}
</style>
</head>
<body>
<div class="np-wrap"><?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado al construirlo. ?></div>
		<?php if ( $script ) : ?>
<script><?php echo $script; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></script>
		<?php endif; ?>
</body>
</html>
		<?php
		exit;
	}
}
