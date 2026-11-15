<?php
/**
 * Registro de errores internos del plugin.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Tracking;

/**
 * Guarda los últimos errores (fecha, origen, motivo y contexto) en una opción sin autoload.
 *
 * Nunca guarda cabeceras ni cookies de las peticiones, solo el motivo del fallo.
 */
final class ErrorLog {

	public const OPTION         = 'lb_sp_errors';
	public const VERSION_OPTION = 'lb_sp_errors_version';
	public const VERSION        = '1';
	public const MAX_ENTRIES    = 50;
	public const MAX_MESSAGE    = 500;

	public const LEVEL_ERROR   = 'error';
	public const LEVEL_WARNING = 'warning';

	public const SOURCE_LOG_INSERT = 'log_insert';
	public const SOURCE_LOG_SCHEMA = 'log_schema';
	public const SOURCE_DELIVERY   = 'delivery';
	public const SOURCE_LEGACY     = 'legacy';

	/** Un error repetido no reescribe la opción más de una vez en este intervalo (segundos). */
	public const REPEAT_INTERVAL = 3600;

	/** Un error ya leído vuelve a aparecer como nuevo si se repite pasado este tiempo (segundos). */
	public const REOPEN_AFTER = 86400;

	/**
	 * Opciones del sistema anterior (un indicador sin detalle).
	 *
	 * @var array<int, string>
	 */
	private const LEGACY_OPTIONS = array( 'lb_sp_log_error', 'lb_sp_storage_warning' );

	/** Registra los hooks. */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'migrate_legacy' ) );
	}

	/**
	 * Añade un error. Si el mismo error (origen y mensaje) ya existe, solo actualiza su contador.
	 *
	 * @param string $source        Origen (constantes SOURCE_*).
	 * @param string $message       Motivo del fallo.
	 * @param string $level         LEVEL_ERROR o LEVEL_WARNING.
	 * @param int    $post_id       Entrada relacionada (0 = ninguna).
	 * @param int    $attachment_id Attachment relacionado (0 = ninguno).
	 */
	public function add( string $source, string $message, string $level = self::LEVEL_ERROR, int $post_id = 0, int $attachment_id = 0 ): void {
		$message = mb_strcut( trim( sanitize_text_field( $message ) ), 0, self::MAX_MESSAGE, 'UTF-8' );
		$level   = self::LEVEL_WARNING === $level ? self::LEVEL_WARNING : self::LEVEL_ERROR;
		$source  = sanitize_key( $source );
		$now     = time();
		$entries = $this->all();

		foreach ( $entries as $index => $entry ) {
			if ( $entry['source'] !== $source || $entry['message'] !== $message ) {
				continue;
			}
			if ( $now - $entry['last'] < self::REPEAT_INTERVAL ) {
				return;
			}
			$entry['last']  = $now;
			$entry['count'] = $entry['count'] + 1;
			if ( $entry['read'] && $now - $entry['last_seen_read'] >= self::REOPEN_AFTER ) {
				$entry['read'] = false;
			}
			$entries[ $index ] = $entry;
			$this->save( $entries );
			return;
		}

		array_unshift(
			$entries,
			array(
				'id'             => wp_generate_uuid4(),
				'time'           => $now,
				'last'           => $now,
				'last_seen_read' => $now,
				'source'         => $source,
				'level'          => $level,
				'message'        => $message,
				'post_id'        => max( 0, $post_id ),
				'attachment_id'  => max( 0, $attachment_id ),
				'count'          => 1,
				'read'           => false,
			)
		);
		$this->save( array_slice( $entries, 0, self::MAX_ENTRIES ) );
	}

	/**
	 * Todas las entradas, de la más reciente a la más antigua.
	 *
	 * @return array<int, array{id: string, time: int, last: int, last_seen_read: int, source: string, level: string, message: string, post_id: int, attachment_id: int, count: int, read: bool}>
	 */
	public function all(): array {
		$raw = get_option( self::OPTION, array() );
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['id'], $entry['message'] ) || ! is_string( $entry['id'] ) || ! is_string( $entry['message'] ) ) {
				continue;
			}
			$time  = isset( $entry['time'] ) && is_int( $entry['time'] ) ? $entry['time'] : 0;
			$out[] = array(
				'id'             => $entry['id'],
				'time'           => $time,
				'last'           => isset( $entry['last'] ) && is_int( $entry['last'] ) ? $entry['last'] : $time,
				'last_seen_read' => isset( $entry['last_seen_read'] ) && is_int( $entry['last_seen_read'] ) ? $entry['last_seen_read'] : $time,
				'source'         => isset( $entry['source'] ) && is_string( $entry['source'] ) ? $entry['source'] : '',
				'level'          => isset( $entry['level'] ) && self::LEVEL_WARNING === $entry['level'] ? self::LEVEL_WARNING : self::LEVEL_ERROR,
				'message'        => $entry['message'],
				'post_id'        => isset( $entry['post_id'] ) && is_int( $entry['post_id'] ) ? $entry['post_id'] : 0,
				'attachment_id'  => isset( $entry['attachment_id'] ) && is_int( $entry['attachment_id'] ) ? $entry['attachment_id'] : 0,
				'count'          => isset( $entry['count'] ) && is_int( $entry['count'] ) ? max( 1, $entry['count'] ) : 1,
				'read'           => ! empty( $entry['read'] ),
			);
		}
		return $out;
	}

	/** Cantidad de errores (no avisos) sin leer. */
	public function unread_error_count(): int {
		$count = 0;
		foreach ( $this->all() as $entry ) {
			if ( ! $entry['read'] && self::LEVEL_ERROR === $entry['level'] ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Marca una entrada como leída.
	 *
	 * @param string $id Identificador.
	 */
	public function mark_read( string $id ): bool {
		$entries = $this->all();
		$changed = false;
		foreach ( $entries as $index => $entry ) {
			if ( $entry['id'] === $id && ! $entry['read'] ) {
				$entries[ $index ]['read']           = true;
				$entries[ $index ]['last_seen_read'] = time();
				$changed                             = true;
			}
		}
		if ( $changed ) {
			$this->save( $entries );
		}
		return $changed;
	}

	/**
	 * Marca como leídas todas las entradas, o solo las de un origen.
	 *
	 * @param string $source Origen ('' = todos).
	 * @return int Entradas modificadas.
	 */
	public function mark_all_read( string $source = '' ): int {
		$entries = $this->all();
		$changed = 0;
		foreach ( $entries as $index => $entry ) {
			if ( ! $entry['read'] && ( '' === $source || $entry['source'] === $source ) ) {
				$entries[ $index ]['read']           = true;
				$entries[ $index ]['last_seen_read'] = time();
				++$changed;
			}
		}
		if ( $changed > 0 ) {
			$this->save( $entries );
		}
		return $changed;
	}

	/** Vacía el registro. */
	public function clear(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Convierte los indicadores del sistema anterior en una entrada de registro (una sola vez).
	 */
	public function migrate_legacy(): void {
		if ( self::VERSION === get_option( self::VERSION_OPTION ) ) {
			return;
		}
		foreach ( self::LEGACY_OPTIONS as $option ) {
			if ( false !== get_option( $option ) ) {
				$this->add(
					self::SOURCE_LEGACY,
					'lb_sp_log_error' === $option
						? __( 'Un aviso anterior indicó un fallo al guardar solicitudes de audio (sin más detalle).', 'lb-simple-podcast' )
						: __( 'Un aviso anterior indicó un problema con el almacenamiento del audio (sin más detalle).', 'lb-simple-podcast' ),
					self::LEVEL_WARNING
				);
				delete_option( $option );
			}
		}
		update_option( self::VERSION_OPTION, self::VERSION, true );
	}

	/**
	 * Guarda las entradas (sin autoload).
	 *
	 * @param array<int, array<string, mixed>> $entries Entradas.
	 */
	private function save( array $entries ): void {
		update_option( self::OPTION, array_values( $entries ), false );
	}
}
