<?php
/**
 * Persistencia de solicitudes de audio.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Tracking;

use LbSimplePodcast\Admin\Menu;
use LbSimplePodcast\Feed\FeedVersion;

/** Almacena las solicitudes mediante una tabla por sitio. */
final class RequestLog {

	public const VERSION        = '1';
	public const VERSION_OPTION = 'lb_sp_log_schema_version';
	public const ERROR_OPTION   = 'lb_sp_log_error';

	/** Registra los hooks. */
	public function register(): void {
		add_action( 'init', array( $this, 'upgrade' ), 1 );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/** Nombre de tabla para el sitio actual. */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'lb_sp_audio_requests';
	}

	/** Crea o actualiza la tabla de manera idempotente. */
	public function upgrade(): void {
		if ( self::VERSION === get_option( self::VERSION_OPTION ) ) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE $table (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				requested_at datetime NOT NULL,
				post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				method varchar(32) NOT NULL,
				remote_addr varchar(45) NOT NULL,
				request_url longtext NOT NULL,
				headers longtext NOT NULL,
				status smallint(5) unsigned NOT NULL,
				PRIMARY KEY  (id),
				KEY requested_at (requested_at),
				KEY post_requested (post_id,requested_at),
				KEY attachment_requested (attachment_id,requested_at)
			) $charset;"
		);
		$failed   = '' !== $wpdb->last_error;
		$found    = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$columns  = $table === $found ? $wpdb->get_col( "SHOW COLUMNS FROM `$table`" ) : array(); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$required = array( 'id', 'requested_at', 'post_id', 'attachment_id', 'user_id', 'method', 'remote_addr', 'request_url', 'headers', 'status' );
		if ( $failed || $table !== $found || array_diff( $required, $columns ) ) {
			update_option( self::ERROR_OPTION, '1', false );
			return;
		}
		update_option( self::VERSION_OPTION, self::VERSION, false );
		FeedVersion::bump();
	}

	/**
	 * Persiste una solicitud.
	 *
	 * @param int                   $post_id Entrada.
	 * @param int                   $attachment_id Attachment.
	 * @param int                   $status Estado HTTP.
	 * @param array<string, mixed>  $server Datos de la petición.
	 * @param array<string, string> $headers Cabeceras recibidas.
	 */
	public function record( int $post_id, int $attachment_id, int $status, array $server, array $headers ): bool {
		global $wpdb;
		$scheme = is_ssl() ? 'https://' : 'http://';
		$host   = isset( $server['HTTP_HOST'] ) && is_string( $server['HTTP_HOST'] ) ? $server['HTTP_HOST'] : '';
		$uri    = isset( $server['REQUEST_URI'] ) && is_string( $server['REQUEST_URI'] ) ? $server['REQUEST_URI'] : '';
		$json   = wp_json_encode( $headers, JSON_INVALID_UTF8_SUBSTITUTE );
		if ( false === $json ) {
			update_option( self::ERROR_OPTION, '1', false );
			return false;
		}
		$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'requested_at'  => gmdate( 'Y-m-d H:i:s' ),
				'post_id'       => $post_id,
				'attachment_id' => $attachment_id,
				'user_id'       => get_current_user_id(),
				'method'        => substr( isset( $server['REQUEST_METHOD'] ) && is_string( $server['REQUEST_METHOD'] ) ? $server['REQUEST_METHOD'] : '', 0, 32 ),
				'remote_addr'   => substr( isset( $server['REMOTE_ADDR'] ) && is_string( $server['REMOTE_ADDR'] ) ? $server['REMOTE_ADDR'] : '', 0, 45 ),
				'request_url'   => $scheme . $host . $uri,
				'headers'       => $json,
				'status'        => $status,
			),
			array( '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d' )
		);
		if ( false === $result ) {
			update_option( self::ERROR_OPTION, '1', false );
			return false;
		}
		return true;
	}

	/** Muestra un aviso de error a los administradores. */
	public function notice(): void {
		if ( current_user_can( Menu::CAPABILITY ) && get_option( self::ERROR_OPTION ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Simple Podcast: no se pudo guardar una solicitud de audio. Revisa la base de datos.', 'lb-simple-podcast' ) . '</p></div>';
		}
	}
}
