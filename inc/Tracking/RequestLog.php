<?php
/**
 * Persistencia de solicitudes de audio.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Tracking;

use LbSimplePodcast\Feed\FeedVersion;

/** Almacena las solicitudes mediante una tabla por sitio. */
final class RequestLog {

	public const VERSION        = '1';
	public const VERSION_OPTION = 'lb_sp_log_schema_version';
	public const CRON_HOOK      = 'lb_sp_purge_log';

	public const DEFAULT_RETENTION_DAYS = 90;
	public const PURGE_BATCH            = 1000;
	public const MAX_URL_BYTES          = 2048;
	public const MAX_HEADERS_BYTES      = 4096;
	public const MAX_HEADER_NAME_BYTES  = 128;
	public const MAX_HEADER_VALUE_BYTES = 512;

	/** Registra los hooks. */
	public function register(): void {
		add_action( 'init', array( $this, 'upgrade' ), 1 );
		add_action( 'init', array( $this, 'schedule_purge' ) );
		add_action( self::CRON_HOOK, array( $this, 'run_purge' ) );
	}

	/** Programa la purga diaria si todavía no existe. */
	public function schedule_purge(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/** Callback de WP-Cron. */
	public function run_purge(): void {
		$this->purge();
	}

	/**
	 * Borra los registros anteriores al período de retención.
	 *
	 * Los días se ajustan con el filtro `lb_sp_log_retention_days` (0 conserva todo).
	 *
	 * @return int Filas eliminadas.
	 */
	public function purge(): int {
		global $wpdb;
		$days = (int) apply_filters( 'lb_sp_log_retention_days', self::DEFAULT_RETENTION_DAYS );
		if ( $days <= 0 ) {
			return 0;
		}
		$table   = self::table();
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$deleted = 0;
		// Lotes acotados para no bloquear la tabla; el resto se borra en la siguiente ejecución.
		for ( $batch = 0; $batch < 20; $batch++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->query( $wpdb->prepare( "DELETE FROM `$table` WHERE requested_at < %s LIMIT %d", $cutoff, self::PURGE_BATCH ) );
			if ( ! is_int( $rows ) || $rows <= 0 ) {
				break;
			}
			$deleted += $rows;
			if ( $rows < self::PURGE_BATCH ) {
				break;
			}
		}
		return $deleted;
	}

	/**
	 * Acota el tamaño de las cabeceras guardadas.
	 *
	 * @param array<string, string> $headers Cabeceras recibidas.
	 * @return array<string, string>
	 */
	private function limit_headers( array $headers ): array {
		$out  = array();
		$size = 2;
		foreach ( $headers as $name => $value ) {
			$name  = mb_strcut( (string) $name, 0, self::MAX_HEADER_NAME_BYTES, 'UTF-8' );
			$value = mb_strcut( $value, 0, self::MAX_HEADER_VALUE_BYTES, 'UTF-8' );
			$item  = wp_json_encode( array( $name => $value ), JSON_INVALID_UTF8_SUBSTITUTE );
			$size += false === $item ? 0 : strlen( $item );
			if ( $size > self::MAX_HEADERS_BYTES ) {
				break;
			}
			$out[ $name ] = $value;
		}
		return $out;
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
		$db_error = $wpdb->last_error;
		$failed   = '' !== $db_error;
		$found    = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$columns  = $table === $found ? $wpdb->get_col( "SHOW COLUMNS FROM `$table`" ) : array(); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$required = array( 'id', 'requested_at', 'post_id', 'attachment_id', 'user_id', 'method', 'remote_addr', 'request_url', 'headers', 'status' );
		if ( $failed || $table !== $found || array_diff( $required, $columns ) ) {
			$errors = new ErrorLog();
			$errors->add(
				ErrorLog::SOURCE_LOG_SCHEMA,
				'' !== $db_error
					? sprintf( /* translators: %s: error de la base de datos. */ __( 'No se pudo crear o actualizar la tabla de registros: %s', 'lb-simple-podcast' ), $db_error )
					: __( 'No se pudo crear o actualizar la tabla de registros (faltan la tabla o columnas).', 'lb-simple-podcast' )
			);
			return;
		}
		update_option( self::VERSION_OPTION, self::VERSION, false );
		( new ErrorLog() )->mark_all_read( ErrorLog::SOURCE_LOG_SCHEMA );
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
		$json   = wp_json_encode( $this->limit_headers( $headers ), JSON_INVALID_UTF8_SUBSTITUTE );
		if ( false === $json ) {
			( new ErrorLog() )->add( ErrorLog::SOURCE_LOG_INSERT, __( 'No se pudieron codificar las cabeceras de la solicitud.', 'lb-simple-podcast' ), ErrorLog::LEVEL_ERROR, $post_id, $attachment_id );
			return false;
		}
		$ip     = ClientIp::resolve( $server );
		$ip     = '' !== $ip && ClientIp::should_anonymize() ? ClientIp::anonymize( $ip ) : $ip;
		$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'requested_at'  => gmdate( 'Y-m-d H:i:s' ),
				'post_id'       => $post_id,
				'attachment_id' => $attachment_id,
				'user_id'       => get_current_user_id(),
				'method'        => substr( isset( $server['REQUEST_METHOD'] ) && is_string( $server['REQUEST_METHOD'] ) ? $server['REQUEST_METHOD'] : '', 0, 32 ),
				'remote_addr'   => substr( $ip, 0, 45 ),
				'request_url'   => mb_strcut( $scheme . $host . $uri, 0, self::MAX_URL_BYTES, 'UTF-8' ),
				'headers'       => $json,
				'status'        => $status,
			),
			array( '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d' )
		);
		if ( false === $result ) {
			$reason = '' !== $wpdb->last_error ? $wpdb->last_error : __( 'error desconocido', 'lb-simple-podcast' );
			( new ErrorLog() )->add(
				ErrorLog::SOURCE_LOG_INSERT,
				/* translators: %s: error de la base de datos. */
				sprintf( __( 'No se pudo guardar una solicitud de audio: %s', 'lb-simple-podcast' ), $reason ),
				ErrorLog::LEVEL_ERROR,
				$post_id,
				$attachment_id
			);
			return false;
		}
		return true;
	}
}
