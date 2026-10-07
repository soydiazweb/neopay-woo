<?php
/**
 * Instalacion: tabla de logs de transacciones y tarea diaria de limpieza.
 *
 * @package NeoPay_Woo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class NeoPay_Woo_Install
 */
class NeoPay_Woo_Install {

	const DB_VERSION        = '1.0.1';
	const DB_VERSION_OPTION = 'neopay_woo_db_version';
	const CRON_HOOK         = 'neopay_woo_purge_logs';

	/**
	 * Nombre de la tabla de logs.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'neopay_woo_logs';
	}

	/**
	 * Activacion del plugin.
	 */
	public static function activate() {
		self::create_table();

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Desactivacion: solo se retira la tarea programada.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Crea o actualiza la tabla si cambio la version del esquema.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::create_table();
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Tabla de logs. Solo guarda datos ya enmascarados: nunca PAN completo,
	 * CVV, fecha de vencimiento ni contrasena del comercio.
	 */
	protected static function create_table() {
		global $wpdb;

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			environment varchar(10) NOT NULL DEFAULT '',
			step varchar(30) NOT NULL DEFAULT '',
			message_type varchar(4) NOT NULL DEFAULT '',
			audit_number varchar(12) NOT NULL DEFAULT '',
			card_masked varchar(25) NOT NULL DEFAULT '',
			amount varchar(20) NOT NULL DEFAULT '',
			http_code smallint(5) NOT NULL DEFAULT 0,
			response_code varchar(4) NOT NULL DEFAULT '',
			type_operation varchar(4) NOT NULL DEFAULT '',
			approved tinyint(1) NOT NULL DEFAULT 0,
			duration_ms int(10) unsigned NOT NULL DEFAULT 0,
			error text NULL,
			request longtext NULL,
			response longtext NULL,
			PRIMARY KEY  (id),
			KEY order_id (order_id),
			KEY created_at (created_at),
			KEY audit_number (audit_number)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}
}
