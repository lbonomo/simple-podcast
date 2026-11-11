<?php
/**
 * Validación de archivos de audio MP3.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Media;

use WP_Error;

/**
 * Valida mime real, tamaño y duración de un MP3.
 */
final class AudioValidator {

	public const MIME_TYPE = 'audio/mpeg';

	/**
	 * Tipos que `finfo` puede devolver para un MP3 real.
	 *
	 * @var array<int, string>
	 */
	private const REAL_MIME_TYPES = array( 'audio/mpeg', 'audio/mp3', 'audio/x-mp3', 'audio/x-mpeg' );

	/**
	 * Carga las funciones de wp-admin que se necesitan fuera del admin (REST, MCP, cron).
	 */
	public static function load_wp_admin_includes(): void {
		if ( ! function_exists( 'wp_read_audio_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
	}

	/**
	 * Tamaño máximo permitido, en bytes.
	 *
	 * Es el menor entre el máximo de subida de PHP/WordPress y la memoria disponible
	 * (el archivo se lee completo en algunos flujos, p. ej. base64). Filtrable.
	 */
	public static function max_bytes(): int {
		$limit = (int) wp_max_upload_size();

		$memory = self::memory_limit_bytes();
		if ( $memory > 0 ) {
			$available = max( 0, $memory - memory_get_usage() );
			// El payload base64 ocupa ~1.33x y se decodifica a 1x: se reserva un factor de 3.
			$limit = min( $limit, (int) floor( $available / 3 ) );
		}

		/**
		 * Filtra el tamaño máximo de audio en bytes.
		 *
		 * @param int $limit Límite calculado.
		 */
		return max( 0, (int) apply_filters( 'lb_sp_max_audio_bytes', $limit ) );
	}

	/**
	 * Valida un archivo local.
	 *
	 * @param string      $path     Ruta absoluta.
	 * @param string|null $filename Nombre original (para la extensión); por defecto el de la ruta.
	 * @return array{mime_type: string, filesize: int, duration: int}|WP_Error
	 */
	public function validate_file( string $path, ?string $filename = null ) {
		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			return new WP_Error( 'lb_sp_file_missing', __( 'El archivo de audio no existe o no se puede leer.', 'lb-simple-podcast' ) );
		}

		$size = (int) filesize( $path );
		if ( $size <= 0 ) {
			return new WP_Error( 'lb_sp_file_empty', __( 'El archivo de audio está vacío.', 'lb-simple-podcast' ) );
		}

		if ( $size > self::max_bytes() ) {
			return new WP_Error(
				'lb_sp_file_too_large',
				sprintf(
					/* translators: %s: tamaño máximo legible. */
					__( 'El archivo de audio supera el tamaño máximo permitido (%s).', 'lb-simple-podcast' ),
					size_format( self::max_bytes() )
				)
			);
		}

		$name = $filename ?? basename( $path );
		if ( ! $this->has_valid_mime( $path, $name ) ) {
			return new WP_Error( 'lb_sp_invalid_mime', __( 'Solo se admiten archivos MP3 (audio/mpeg).', 'lb-simple-podcast' ) );
		}

		self::load_wp_admin_includes();
		$metadata = wp_read_audio_metadata( $path );
		$duration = is_array( $metadata ) && isset( $metadata['length'] ) ? (int) $metadata['length'] : 0;
		if ( $duration <= 0 ) {
			return new WP_Error( 'lb_sp_invalid_audio', __( 'No se pudo leer la duración del audio; el archivo puede estar dañado.', 'lb-simple-podcast' ) );
		}

		return array(
			'mime_type' => self::MIME_TYPE,
			'filesize'  => $size,
			'duration'  => $duration,
		);
	}

	/**
	 * Valida un attachment existente de la biblioteca de medios.
	 *
	 * @param int $attachment_id ID del attachment.
	 * @return array{mime_type: string, filesize: int, duration: int}|WP_Error
	 */
	public function validate_attachment( int $attachment_id ) {
		if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
			return new WP_Error( 'lb_sp_not_attachment', __( 'El ID no corresponde a un archivo de la biblioteca de medios.', 'lb-simple-podcast' ) );
		}

		if ( self::MIME_TYPE !== get_post_mime_type( $attachment_id ) ) {
			return new WP_Error( 'lb_sp_invalid_mime', __( 'Solo se admiten archivos MP3 (audio/mpeg).', 'lb-simple-podcast' ) );
		}

		$path = get_attached_file( $attachment_id );
		if ( ! is_string( $path ) ) {
			return new WP_Error( 'lb_sp_file_missing', __( 'El archivo de audio no existe o no se puede leer.', 'lb-simple-podcast' ) );
		}

		return $this->validate_file( $path );
	}

	/**
	 * Comprueba extensión y contenido real.
	 *
	 * @param string $path Ruta del archivo.
	 * @param string $name Nombre con extensión.
	 */
	private function has_valid_mime( string $path, string $name ): bool {
		$check = wp_check_filetype_and_ext( $path, $name, array( 'mp3' => self::MIME_TYPE ) );
		if ( self::MIME_TYPE !== $check['type'] ) {
			return false;
		}

		// wp_check_filetype_and_ext() no verifica el contenido de los no-imagen: se comprueba con finfo.
		if ( ! class_exists( 'finfo' ) ) {
			return true;
		}
		$real = ( new \finfo( FILEINFO_MIME_TYPE ) )->file( $path );
		return is_string( $real ) && in_array( $real, self::REAL_MIME_TYPES, true );
	}

	/**
	 * Límite de memoria de PHP en bytes (0 si es ilimitado).
	 */
	private static function memory_limit_bytes(): int {
		$raw = (string) ini_get( 'memory_limit' );
		if ( '' === $raw || '-1' === $raw ) {
			return 0;
		}
		return (int) wp_convert_hr_to_bytes( $raw );
	}
}
