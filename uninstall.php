<?php
/**
 * Limpieza al desinstalar el plugin.
 *
 * Borra las opciones del plugin. No elimina los archivos de audio de la biblioteca de medios
 * ni el meta de las entradas, para no perder contenido del usuario.
 *
 * @package LbSimplePodcast
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$lb_sp_cleanup = static function (): void {
	global $wpdb;
	$table  = $wpdb->prefix . 'lb_sp_audio_requests';
	$result = $wpdb->query( "DROP TABLE IF EXISTS `$table`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( false === $result ) {
		error_log( 'Simple Podcast: no se pudo eliminar la tabla de registros de audio.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
	wp_clear_scheduled_hook( 'lb_sp_purge_log' );
	delete_option( 'lb_sp_channel' );
	delete_option( 'lb_sp_feed_version' );
	delete_option( 'lb_sp_log_schema_version' );
	delete_option( 'lb_sp_log_error' );
	delete_option( 'lb_sp_storage_warning' );
	delete_option( 'lb_sp_errors' );
	delete_option( 'lb_sp_errors_version' );
	delete_option( 'lb_sp_stats_version' );
	delete_transient( 'lb_sp_feed_body' );
};

if ( is_multisite() ) {
	$lb_sp_offset = 0;
	do {
		$lb_sp_sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $lb_sp_offset,
			)
		);
		foreach ( $lb_sp_sites as $lb_sp_site_id ) {
			switch_to_blog( (int) $lb_sp_site_id );
			$lb_sp_cleanup();
			restore_current_blog();
		}
		$lb_sp_count   = count( $lb_sp_sites );
		$lb_sp_offset += $lb_sp_count;
	} while ( 100 === $lb_sp_count );
} else {
	$lb_sp_cleanup();
}
