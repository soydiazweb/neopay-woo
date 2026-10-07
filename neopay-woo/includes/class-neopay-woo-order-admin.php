<?php
/**
 * Caja "NeoPay" en la pantalla del pedido: detalle de la transaccion,
 * comprobantes y respuestas del API (enmascaradas).
 *
 * @package NeoPay_Woo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class NeoPay_Woo_Order_Admin
 */
class NeoPay_Woo_Order_Admin {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 30 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Estilos.
	 */
	public function enqueue() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && in_array( $screen->id, array( 'shop_order', 'woocommerce_page_wc-orders', 'woocommerce_page_neopay-woo-logs' ), true ) ) {
			wp_enqueue_style( 'neopay-woo-admin', NEOPAY_WOO_URL . 'assets/css/admin.css', array(), NEOPAY_WOO_VERSION );
		}
	}

	/**
	 * Registra la caja (pantalla clasica y HPOS).
	 */
	public function add_meta_box() {
		foreach ( array( 'shop_order', 'woocommerce_page_wc-orders' ) as $screen ) {
			add_meta_box( 'neopay_woo_transaction', __( 'NeoPay: detalle de la transaccion', 'neopay-woo' ), array( $this, 'render' ), $screen, 'normal', 'default' );
		}
	}

	/**
	 * Contenido.
	 *
	 * @param WP_Post|WC_Order $post_or_order Post o pedido.
	 */
	public function render( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID ?? 0 );
		if ( ! $order ) {
			return;
		}
		if ( NeoPay_Woo_Gateway::ID !== $order->get_payment_method() && ! $order->get_meta( '_neopay_audit' ) ) {
			echo '<p>' . esc_html__( 'Este pedido no se pago con NeoPay.', 'neopay-woo' ) . '</p>';
			return;
		}

		$this->render_summary( $order );
		$this->render_sources( $order );
		$this->render_history( $order );
	}

	/**
	 * Resumen.
	 *
	 * @param WC_Order $order Pedido.
	 */
	protected function render_summary( $order ) {
		$status_labels = array(
			'aprobada'  => __( 'Aprobada', 'neopay-woo' ),
			'rechazada' => __( 'No aprobada', 'neopay-woo' ),
			'anulada'   => __( 'Anulada', 'neopay-woo' ),
		);
		$tds_labels    = array(
			'iniciado'     => __( 'Iniciada (sin completar)', 'neopay-woo' ),
			'sin_friccion' => __( 'Autenticada sin friccion', 'neopay-woo' ),
			'desafio'      => __( 'Desafio del emisor en curso', 'neopay-woo' ),
			'autenticado'  => __( 'Autenticada con desafio (Step-Up)', 'neopay-woo' ),
		);

		$status = (string) $order->get_meta( '_neopay_status' );
		$tds    = (string) $order->get_meta( '_neopay_3ds_status' );
		$code   = (string) $order->get_meta( '_neopay_response_code' );
		$cuotas = (int) $order->get_meta( '_neopay_installments' );

		$rows = array(
			__( 'Estado', 'neopay-woo' )              => $status_labels[ $status ] ?? $status,
			__( 'Ambiente', 'neopay-woo' )            => 'production' === $order->get_meta( '_neopay_environment' ) ? __( 'Produccion', 'neopay-woo' ) : __( 'Pruebas', 'neopay-woo' ),
			__( 'No. de auditoria', 'neopay-woo' )    => $order->get_meta( '_neopay_audit_number' ),
			__( 'No. de referencia', 'neopay-woo' )   => $order->get_meta( '_neopay_reference_number' ),
			__( 'No. de autorizacion', 'neopay-woo' ) => $order->get_meta( '_neopay_authorization_number' ),
			__( 'Codigo de respuesta', 'neopay-woo' ) => '' !== $code ? $code . ' - ' . NeoPay_Woo_Helper::response_label( $code ) : '',
			__( 'Tipo de mensaje', 'neopay-woo' )     => $order->get_meta( '_neopay_message_type' ),
			__( '3-D Secure', 'neopay-woo' )          => $tds_labels[ $tds ] ?? $tds,
			__( 'Tarjeta', 'neopay-woo' )             => trim( $order->get_meta( '_neopay_card_brand' ) . ' ' . $order->get_meta( '_neopay_card_masked' ) ),
			__( 'Tarjetahabiente', 'neopay-woo' )     => $order->get_meta( '_neopay_cardholder' ),
			__( 'Cuotas', 'neopay-woo' )              => $cuotas > 1 ? $cuotas . ' (' . $order->get_meta( '_neopay_additional_data' ) . ')' : __( 'Contado', 'neopay-woo' ),
			__( 'Monto enviado', 'neopay-woo' )       => $order->get_meta( '_neopay_amount_minor' ),
			__( 'Afiliacion / terminal', 'neopay-woo' ) => trim( $order->get_meta( '_neopay_merchant' ) . ' / ' . $order->get_meta( '_neopay_terminal_id' ), ' /' ),
			__( 'Fecha (servidor)', 'neopay-woo' )    => $order->get_meta( '_neopay_transaction_time' ),
			__( 'Fecha (NeoNet)', 'neopay-woo' )      => $order->get_meta( '_neopay_remote_time' ),
		);

		if ( 'anulada' === $status ) {
			$rows[ __( 'Anulacion', 'neopay-woo' ) ] = sprintf(
				'%s - ref. %s - aut. %s',
				$order->get_meta( '_neopay_void_time' ),
				$order->get_meta( '_neopay_void_reference_number' ),
				$order->get_meta( '_neopay_void_authorization_number' )
			);
		}

		echo '<table class="widefat striped neopay-summary"><tbody>';
		foreach ( $rows as $label => $value ) {
			if ( '' === (string) $value ) {
				continue;
			}
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><code>' . esc_html( (string) $value ) . '</code></td></tr>';
		}
		echo '</tbody></table>';

		$links = array();
		foreach ( NeoPay_Woo_Voucher::available_types( $order ) as $type ) {
			$links[] = '<a class="button" target="_blank" rel="noopener" href="' . esc_url( NeoPay_Woo_3DS::url( 'voucher', $order, array( 'type' => $type ) ) ) . '">'
				. esc_html( 'void' === $type ? __( 'Imprimir comprobante de anulacion', 'neopay-woo' ) : __( 'Imprimir comprobante de venta', 'neopay-woo' ) ) . '</a>';
		}
		$links[] = '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=neopay-woo-logs&order_id=' . $order->get_id() ) ) . '">' . esc_html__( 'Ver logs del pedido', 'neopay-woo' ) . '</a>';
		echo '<p class="neopay-actions">' . implode( ' ', $links ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( NeoPay_Woo_Void::is_voidable( $order ) ) {
			echo '<p class="description">';
			if ( NeoPay_Woo_Void::in_window( $order ) ) {
				esc_html_e( 'Anulacion disponible: cambie el estado del pedido a "Cancelado" y guarde. Si NeoNet la rechaza, el pedido vuelve a su estado anterior.', 'neopay-woo' );
			} else {
				esc_html_e( 'Fuera del horario permitido para anular (20 horas desde el pago y antes del cierre de las 22:00). Gestione la devolucion con NeoNet.', 'neopay-woo' );
			}
			echo '</p>';
		}
	}

	/**
	 * Origen de los datos de facturacion y entrega enviados.
	 *
	 * @param WC_Order $order Pedido.
	 */
	protected function render_sources( $order ) {
		$sources = $order->get_meta( '_neopay_address_sources' );
		if ( ! is_array( $sources ) ) {
			return;
		}
		$missing = array();
		$groups = 'no' === $order->get_meta( '_neopay_ship_to_sent' ) ? array( 'BillTo' ) : array( 'BillTo', 'ShipTo' );

		foreach ( $groups as $group ) {
			foreach ( NeoPay_Woo_Address::api_fields() as $api ) {
				if ( ! isset( $sources[ $group . '.' . $api ] ) ) {
					$missing[] = $group . '.' . $api;
				}
			}
		}

		echo '<details class="neopay-sources"><summary>' . esc_html__( 'Origen de los datos de facturacion y entrega', 'neopay-woo' ) . '</summary>';
		echo '<table class="widefat striped neopay-summary"><tbody>';
		foreach ( $sources as $field => $source ) {
			echo '<tr><th>' . esc_html( $field ) . '</th><td><code>' . esc_html( $source ) . '</code></td></tr>';
		}
		echo '</tbody></table>';
		if ( $missing ) {
			echo '<p class="description">' . esc_html__( 'Enviados vacios (no se encontraron en el pedido): ', 'neopay-woo' ) . esc_html( implode( ', ', $missing ) ) . '</p>';
		}
		echo '</details>';
	}

	/**
	 * Historial de llamadas al API.
	 *
	 * @param WC_Order $order Pedido.
	 */
	protected function render_history( $order ) {
		$history = $order->get_meta( '_neopay_audit' );
		if ( ! is_array( $history ) || ! $history ) {
			return;
		}

		echo '<h4>' . esc_html__( 'Llamadas al API de NeoPay', 'neopay-woo' ) . '</h4>';
		echo '<table class="widefat striped neopay-history"><thead><tr>';
		foreach ( array( 'Fecha', 'Paso', 'HTTP', 'MTI', 'Tipo op.', 'Codigo', 'Auditoria', 'Referencia', 'Autorizacion', 'Respuesta' ) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( array_reverse( $history ) as $entry ) {
			$detail = trim( ( $entry['response_label'] ?? '' ) . ( ! empty( $entry['alt_message'] ) ? ' | ' . $entry['alt_message'] : '' ) . ( ! empty( $entry['error'] ) ? ' | ' . $entry['error'] : '' ), ' |' );

			echo '<tr class="' . ( 'yes' === ( $entry['approved'] ?? 'no' ) ? 'neopay-ok' : '' ) . '">';
			echo '<td>' . esc_html( $entry['time'] ?? '' ) . '</td>';
			echo '<td>' . esc_html( $entry['step'] ?? '' ) . ( ! empty( $entry['pa_step'] ) ? ' <small>(PA ' . esc_html( $entry['pa_step'] ) . ')</small>' : '' ) . '</td>';
			echo '<td>' . esc_html( (string) ( $entry['http_code'] ?? '' ) ) . '<br><small>' . esc_html( (string) ( $entry['duration_ms'] ?? 0 ) ) . ' ms</small></td>';
			echo '<td>' . esc_html( $entry['message_type'] ?? '' ) . '</td>';
			echo '<td>' . esc_html( $entry['type_operation'] ?? '' ) . '</td>';
			echo '<td><strong>' . esc_html( $entry['response_code'] ?? '' ) . '</strong><br><small>' . esc_html( $detail ) . '</small></td>';
			echo '<td>' . esc_html( $entry['audit_number'] ?? '' ) . '</td>';
			echo '<td>' . esc_html( $entry['reference_number'] ?? '' ) . '</td>';
			echo '<td>' . esc_html( $entry['authorization_number'] ?? '' ) . '</td>';
			echo '<td>';
			if ( ! empty( $entry['response'] ) ) {
				echo '<details><summary>' . esc_html__( 'Ver JSON', 'neopay-woo' ) . '</summary><pre>' . esc_html( wp_json_encode( $entry['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</pre></details>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
