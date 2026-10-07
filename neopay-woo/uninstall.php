<?php
/**
 * Desinstalacion: borra los ajustes. Los datos guardados en los pedidos se
 * conservan siempre (son el registro contable de las transacciones). La tabla
 * de logs solo se borra si se marco la opcion correspondiente.
 *
 * @package NeoPay_Woo
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$settings = get_option( 'woocommerce_neopay_woo_settings', array() );

if ( is_array( $settings ) && 'yes' === ( $settings['remove_data'] ?? 'no' ) ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}neopay_woo_logs" ); // phpcs:ignore
	delete_option( 'neopay_woo_audit_test' );
	delete_option( 'neopay_woo_audit_production' );
}

delete_option( 'woocommerce_neopay_woo_settings' );
delete_option( 'neopay_woo_db_version' );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'neopay\\_woo\\_lock\\_%'" ); // phpcs:ignore
wp_clear_scheduled_hook( 'neopay_woo_purge_logs' );
