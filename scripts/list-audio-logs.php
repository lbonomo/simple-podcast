<?php
/**
 * Lista solicitudes de audio mediante WP-CLI.
 *
 * @package LbSimplePodcast
 */

( defined( 'WP_CLI' ) && WP_CLI ) || exit;

/**
 * Argumentos proporcionados por wp eval-file.
 *
 * @var array<int, string> $args
 */
$lb_sp_limit = filter_var( $args[0] ?? '50', FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
if ( false === $lb_sp_limit || count( $args ) > 1 ) {
	WP_CLI::error( 'Uso: npm run logs -- [cantidad]. La cantidad debe ser un entero positivo.' );
}

global $wpdb;
$lb_sp_fields = array( 'id', 'requested_at', 'post_id', 'attachment_id', 'user_id', 'method', 'remote_addr', 'status' );
$lb_sp_rows   = $wpdb->get_results(
	$wpdb->prepare(
		'SELECT id, requested_at, post_id, attachment_id, user_id, method, remote_addr, status FROM %i ORDER BY id DESC LIMIT %d',
		$wpdb->prefix . 'lb_sp_audio_requests',
		$lb_sp_limit
	),
	ARRAY_A
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

if ( '' !== $wpdb->last_error ) {
	WP_CLI::error( 'No se pudieron consultar los registros de audio. Comprueba que el plugin esté activo y su tabla exista. ' . $wpdb->last_error );
}
if ( empty( $lb_sp_rows ) ) {
	WP_CLI::log( 'No hay solicitudes de audio registradas.' );
} else {
	\WP_CLI\Utils\format_items( 'table', $lb_sp_rows, $lb_sp_fields );
}
