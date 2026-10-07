<?php
/**
 * Pagina WooCommerce > NeoPay Logs.
 *
 * @package NeoPay_Woo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class NeoPay_Woo_Logs_Page
 */
class NeoPay_Woo_Logs_Page {

	const SLUG     = 'neopay-woo-logs';
	const PER_PAGE = 50;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ), 60 );
		add_action( 'admin_post_neopay_woo_export_logs', array( $this, 'export' ) );
	}

	/**
	 * Menu.
	 */
	public function menu() {
		add_submenu_page( 'woocommerce', __( 'NeoPay Logs', 'neopay-woo' ), __( 'NeoPay Logs', 'neopay-woo' ), 'manage_woocommerce', self::SLUG, array( $this, 'render' ) );
	}

	/**
	 * Filtros desde la URL.
	 *
	 * @return array
	 */
	protected function filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		return array(
			'order_id'     => absint( $_GET['order_id'] ?? 0 ),
			'audit_number' => sanitize_text_field( wp_unslash( $_GET['audit_number'] ?? '' ) ),
			'environment'  => in_array( $_GET['environment'] ?? '', array( 'test', 'production' ), true ) ? sanitize_key( $_GET['environment'] ) : '',
			'result'       => in_array( $_GET['result'] ?? '', array( 'ok', 'ko' ), true ) ? sanitize_key( $_GET['result'] ) : '',
		);
		// phpcs:enable
	}

	/**
	 * WHERE a partir de los filtros.
	 *
	 * @param array $f Filtros.
	 * @return string
	 */
	protected function where( array $f ) {
		global $wpdb;
		$where = array( '1=1' );
		if ( $f['order_id'] ) {
			$where[] = $wpdb->prepare( 'order_id = %d', $f['order_id'] );
		}
		if ( '' !== $f['audit_number'] ) {
			$where[] = $wpdb->prepare( 'audit_number = %s', $f['audit_number'] );
		}
		if ( $f['environment'] ) {
			$where[] = $wpdb->prepare( 'environment = %s', $f['environment'] );
		}
		if ( 'ok' === $f['result'] ) {
			$where[] = 'approved = 1';
		} elseif ( 'ko' === $f['result'] ) {
			$where[] = 'approved = 0';
		}
		return implode( ' AND ', $where );
	}

	/**
	 * Pantalla.
	 */
	public function render() {
		global $wpdb;

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$table = NeoPay_Woo_Install::table();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$view = absint( $_GET['view'] ?? 0 );
		if ( $view ) {
			$this->render_detail( $view );
			return;
		}

		$f     = $this->filters();
		$where = $this->where( $f );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged  = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$offset = ( $paged - 1 ) * self::PER_PAGE;

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" ); // phpcs:ignore
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, created_at, order_id, environment, step, message_type, audit_number, card_masked, amount, http_code, response_code, type_operation, approved, duration_ms, error FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", self::PER_PAGE, $offset ) ); // phpcs:ignore

		$base = admin_url( 'admin.php?page=' . self::SLUG );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'NeoPay: logs de transacciones', 'neopay-woo' ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'neopay_woo_export_logs' ), array_filter( $f ) ), admin_url( 'admin-post.php' ) ), 'neopay_woo_export_logs' ) ); ?>"><?php esc_html_e( 'Exportar CSV', 'neopay-woo' ); ?></a>
			<p class="description"><?php esc_html_e( 'Cada llamada al API de NeoNet (venta, pasos 3-D Secure, reversas y anulaciones). Las tarjetas se guardan enmascaradas (6 primeros y 4 ultimos digitos); nunca se guardan el CVV, la fecha de vencimiento ni la contrasena del comercio.', 'neopay-woo' ); ?></p>

			<form method="get" class="neopay-log-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
				<input type="number" name="order_id" placeholder="<?php esc_attr_e( 'Pedido', 'neopay-woo' ); ?>" value="<?php echo $f['order_id'] ? esc_attr( (string) $f['order_id'] ) : ''; ?>" />
				<input type="text" name="audit_number" placeholder="<?php esc_attr_e( 'Auditoria', 'neopay-woo' ); ?>" value="<?php echo esc_attr( $f['audit_number'] ); ?>" />
				<select name="environment">
					<option value=""><?php esc_html_e( 'Todos los ambientes', 'neopay-woo' ); ?></option>
					<option value="test" <?php selected( $f['environment'], 'test' ); ?>><?php esc_html_e( 'Pruebas', 'neopay-woo' ); ?></option>
					<option value="production" <?php selected( $f['environment'], 'production' ); ?>><?php esc_html_e( 'Produccion', 'neopay-woo' ); ?></option>
				</select>
				<select name="result">
					<option value=""><?php esc_html_e( 'Todos los resultados', 'neopay-woo' ); ?></option>
					<option value="ok" <?php selected( $f['result'], 'ok' ); ?>><?php esc_html_e( 'Aprobadas', 'neopay-woo' ); ?></option>
					<option value="ko" <?php selected( $f['result'], 'ko' ); ?>><?php esc_html_e( 'No aprobadas / errores', 'neopay-woo' ); ?></option>
				</select>
				<button class="button"><?php esc_html_e( 'Filtrar', 'neopay-woo' ); ?></button>
			</form>

			<table class="widefat striped neopay-logs">
				<thead><tr>
					<?php foreach ( array( 'ID', 'Fecha (UTC)', 'Pedido', 'Ambiente', 'Paso', 'MTI', 'Auditoria', 'Tarjeta', 'Monto', 'HTTP', 'Codigo', 'Tipo op.', 'Resultado', 'ms' ) as $head ) : ?>
						<th><?php echo esc_html( $head ); ?></th>
					<?php endforeach; ?>
				</tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="14"><?php esc_html_e( 'Sin registros.', 'neopay-woo' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $row ) : ?>
					<tr class="<?php echo $row->approved ? 'neopay-ok' : ( $row->error ? 'neopay-error' : '' ); ?>">
						<td><a href="<?php echo esc_url( add_query_arg( 'view', $row->id, $base ) ); ?>"><?php echo esc_html( $row->id ); ?></a></td>
						<td><?php echo esc_html( $row->created_at ); ?></td>
						<td><?php if ( $row->order_id ) : ?><a href="<?php echo esc_url( $this->order_url( (int) $row->order_id ) ); ?>">#<?php echo esc_html( $row->order_id ); ?></a><?php endif; ?></td>
						<td><?php echo esc_html( $row->environment ); ?></td>
						<td><?php echo esc_html( $row->step ); ?></td>
						<td><?php echo esc_html( $row->message_type ); ?></td>
						<td><?php echo esc_html( $row->audit_number ); ?></td>
						<td><code><?php echo esc_html( $row->card_masked ); ?></code></td>
						<td><?php echo esc_html( $row->amount ); ?></td>
						<td><?php echo esc_html( $row->http_code ); ?></td>
						<td><strong><?php echo esc_html( $row->response_code ); ?></strong> <small><?php echo '' !== $row->response_code ? esc_html( NeoPay_Woo_Helper::response_label( $row->response_code ) ) : ''; ?></small></td>
						<td><?php echo esc_html( $row->type_operation ); ?></td>
						<td><?php echo $row->approved ? esc_html__( 'Aprobada', 'neopay-woo' ) : esc_html( $row->error ? $row->error : __( 'No aprobada', 'neopay-woo' ) ); ?></td>
						<td><?php echo esc_html( $row->duration_ms ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php
			$pages = (int) ceil( $total / self::PER_PAGE );
			if ( $pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">';
				echo paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $paged,
						'total'   => $pages,
					)
				);
				echo '</div></div>';
			}
			?>
		</div>
		<?php
	}

	/**
	 * Detalle de un registro.
	 *
	 * @param int $id ID.
	 */
	protected function render_detail( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NeoPay_Woo_Install::table() . ' WHERE id = %d', $id ) ); // phpcs:ignore
		echo '<div class="wrap"><h1>' . esc_html( sprintf( /* translators: %d: id */ __( 'NeoPay log #%d', 'neopay-woo' ), $id ) ) . '</h1>';
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">&larr; ' . esc_html__( 'Volver', 'neopay-woo' ) . '</a></p>';
		if ( ! $row ) {
			echo '<p>' . esc_html__( 'Registro no encontrado.', 'neopay-woo' ) . '</p></div>';
			return;
		}

		echo '<table class="widefat striped neopay-summary"><tbody>';
		foreach ( (array) $row as $key => $value ) {
			if ( in_array( $key, array( 'request', 'response' ), true ) ) {
				continue;
			}
			echo '<tr><th>' . esc_html( $key ) . '</th><td><code>' . esc_html( (string) $value ) . '</code></td></tr>';
		}
		echo '</tbody></table>';

		foreach ( array( 'request' => __( 'Request (enmascarado)', 'neopay-woo' ), 'response' => __( 'Response', 'neopay-woo' ) ) as $key => $label ) {
			$decoded = json_decode( (string) $row->$key, true );
			echo '<h2>' . esc_html( $label ) . '</h2><pre class="neopay-json">' . esc_html( null === $decoded ? (string) $row->$key : wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
		}
		echo '</div>';
	}

	/**
	 * URL de edicion del pedido (HPOS o clasico).
	 *
	 * @param int $order_id Pedido.
	 * @return string
	 */
	protected function order_url( $order_id ) {
		$order = wc_get_order( $order_id );
		return $order ? $order->get_edit_order_url() : admin_url( 'post.php?post=' . $order_id . '&action=edit' );
	}

	/**
	 * Exporta los registros filtrados a CSV (sin request/response).
	 */
	public function export() {
		global $wpdb;

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'neopay-woo' ) );
		}
		check_admin_referer( 'neopay_woo_export_logs' );

		$where = $this->where( $this->filters() );
		$rows  = $wpdb->get_results( 'SELECT id, created_at, order_id, environment, step, message_type, audit_number, card_masked, amount, http_code, response_code, type_operation, approved, duration_ms, error FROM ' . NeoPay_Woo_Install::table() . " WHERE {$where} ORDER BY id DESC LIMIT 20000", ARRAY_A ); // phpcs:ignore

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=neopay-logs-' . gmdate( 'Ymd-His' ) . '.csv' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $out, array( 'id', 'created_at_utc', 'order_id', 'environment', 'step', 'message_type', 'audit_number', 'card_masked', 'amount', 'http_code', 'response_code', 'type_operation', 'approved', 'duration_ms', 'error' ) );
		foreach ( $rows as $r ) {
			fputcsv( $out, $r );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}
}
