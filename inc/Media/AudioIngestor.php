<?php
/**
 * Ingesta de audio MP3 desde una URL o desde base64.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Media;

use LbSimplePodcast\Meta\AudioMeta;
use WP_Error;
use WP_Post;

/**
 * Descarga o decodifica un MP3, lo valida, lo agrega a la biblioteca de medios y lo asocia al post.
 */
final class AudioIngestor {

	/**
	 * Meta que marca los attachments creados por el plugin (solo esos se borran al reemplazar).
	 */
	public const INGESTED_META = '_lb_sp_ingested';

	/**
	 * Validador de audio.
	 *
	 * @var AudioValidator
	 */
	private AudioValidator $validator;

	/**
	 * Constructor.
	 *
	 * @param AudioValidator|null $validator Validador.
	 */
	public function __construct( ?AudioValidator $validator = null ) {
		$this->validator = $validator ?? new AudioValidator();
	}

	/**
	 * Descarga un MP3 y lo asocia al post.
	 *
	 * @param int         $post_id ID del post.
	 * @param string      $url     URL http(s) del MP3.
	 * @param string|null $title   Título del attachment.
	 * @return array{attachment_id: int, url: string, mime_type: string, filesize: int, duration: int, replaced_attachment_id: int|null}|WP_Error
	 */
	public function ingest_from_url( int $post_id, string $url, ?string $title = null ) {
		$post = $this->get_post( $post_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$safe = $this->validate_url( $url );
		if ( is_wp_error( $safe ) ) {
			return $safe;
		}

		AudioValidator::load_wp_admin_includes();
		$tmp = $this->download( $safe );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$name = $this->filename_from_url( $safe, $post_id );
		return $this->attach( $post, $tmp, $name, $title );
	}

	/**
	 * Decodifica un MP3 en base64 y lo asocia al post.
	 *
	 * @param int         $post_id  ID del post.
	 * @param string      $data     Contenido en base64 (admite prefijo `data:audio/mpeg;base64,`).
	 * @param string|null $filename Nombre de archivo sugerido.
	 * @param string|null $title    Título del attachment.
	 * @return array{attachment_id: int, url: string, mime_type: string, filesize: int, duration: int, replaced_attachment_id: int|null}|WP_Error
	 */
	public function ingest_from_base64( int $post_id, string $data, ?string $filename = null, ?string $title = null ) {
		$post = $this->get_post( $post_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$data = (string) preg_replace( '#^data:[^;,]*;base64,#i', '', trim( $data ) );
		$data = (string) preg_replace( '/\s+/', '', $data );
		if ( '' === $data ) {
			return new WP_Error( 'lb_sp_invalid_base64', __( 'El contenido base64 está vacío.', 'lb-simple-podcast' ) );
		}

		// Se estima el tamaño decodificado antes de decodificar para no agotar la memoria.
		$padding   = strlen( $data ) - strlen( rtrim( $data, '=' ) );
		$estimated = (int) floor( strlen( $data ) * 3 / 4 ) - min( 2, $padding );
		if ( $estimated > AudioValidator::max_bytes() ) {
			return $this->too_large();
		}

		$binary = base64_decode( $data, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Entrada del usuario, decodificación estricta.
		unset( $data );
		if ( false === $binary || '' === $binary ) {
			return new WP_Error( 'lb_sp_invalid_base64', __( 'El contenido no es base64 válido.', 'lb-simple-podcast' ) );
		}

		AudioValidator::load_wp_admin_includes();
		$tmp = wp_tempnam( 'lbsp-audio' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( '' === $tmp || false === file_put_contents( $tmp, $binary ) ) {
			return new WP_Error( 'lb_sp_write_failed', __( 'No se pudo guardar el audio temporal.', 'lb-simple-podcast' ) );
		}
		unset( $binary );

		$name = $this->sanitize_filename( $filename ?? '', $post_id );
		return $this->attach( $post, $tmp, $name, $title );
	}

	/**
	 * Valida la URL contra SSRF y devuelve la URL normalizada.
	 *
	 * Solo http/https, sin credenciales, y el host no puede resolver a una IP privada/loopback
	 * (salvo hosts incluidos en el filtro `lb_sp_audio_url_allowlist`, pensado para entornos locales).
	 *
	 * @param string $url URL de origen.
	 * @return string|WP_Error
	 */
	public function validate_url( string $url ) {
		$url   = trim( $url );
		$parts = wp_parse_url( $url );
		$error = new WP_Error( 'lb_sp_invalid_url', __( 'La URL del audio no es válida o no está permitida.', 'lb-simple-podcast' ) );

		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return $error;
		}
		if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return $error;
		}

		/**
		 * Hosts que se omiten en la comprobación anti-SSRF (p. ej. un servidor local de pruebas).
		 *
		 * @param array<int, string> $hosts Hosts permitidos.
		 */
		$allowlist = (array) apply_filters( 'lb_sp_audio_url_allowlist', array() );
		if ( in_array( strtolower( $parts['host'] ), array_map( 'strtolower', array_map( 'strval', $allowlist ) ), true ) ) {
			return esc_url_raw( $url );
		}

		$valid = wp_http_validate_url( $url );
		if ( false === $valid || ! $this->is_public_host( $parts['host'] ) ) {
			return $error;
		}

		return esc_url_raw( $url );
	}

	/**
	 * Comprueba que el host (IP literal o nombres que resuelven) sea público (IPv4 e IPv6).
	 *
	 * @param string $host Host de la URL.
	 */
	private function is_public_host( string $host ): bool {
		$host  = trim( $host, '[]' );
		$flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;

		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false !== filter_var( $host, FILTER_VALIDATE_IP, $flags );
		}

		$ips = gethostbynamel( $host );
		if ( false === $ips ) {
			return true; // No resuelve ahora: la descarga fallará con su propio error.
		}
		foreach ( $ips as $ip ) {
			if ( false === filter_var( $ip, FILTER_VALIDATE_IP, $flags ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Descarga a un archivo temporal respetando el tamaño máximo.
	 *
	 * @param string $url URL ya validada.
	 * @return string|WP_Error Ruta del archivo temporal.
	 */
	private function download( string $url ) {
		$max     = AudioValidator::max_bytes();
		$host    = (string) wp_parse_url( $url, PHP_URL_HOST );
		$allowed = in_array( strtolower( $host ), array_map( 'strtolower', array_map( 'strval', (array) apply_filters( 'lb_sp_audio_url_allowlist', array() ) ) ), true );

		$tmp = wp_tempnam( 'lbsp-audio' );
		if ( '' === $tmp ) {
			return new WP_Error( 'lb_sp_write_failed', __( 'No se pudo crear el archivo temporal.', 'lb-simple-podcast' ) );
		}

		$args = array(
			'timeout'             => max( 1, (int) apply_filters( 'lb_sp_download_timeout', 30 ) ),
			'stream'              => true,
			'filename'            => $tmp,
			'limit_response_size' => $max + 1,
			'redirection'         => $allowed ? 0 : 3,
			'reject_unsafe_urls'  => ! $allowed,
		);

		$response = $allowed ? wp_remote_get( $url, $args ) : wp_safe_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			$this->discard( $tmp );
			return new WP_Error( 'lb_sp_download_failed', __( 'No se pudo descargar el audio.', 'lb-simple-podcast' ), array( 'reason' => $response->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$this->discard( $tmp );
			return new WP_Error(
				'lb_sp_download_failed',
				/* translators: %d: código HTTP. */
				sprintf( __( 'La descarga del audio falló (HTTP %d).', 'lb-simple-podcast' ), $code )
			);
		}

		if ( is_file( $tmp ) && (int) filesize( $tmp ) > $max ) {
			$this->discard( $tmp );
			return $this->too_large();
		}

		return $tmp;
	}

	/**
	 * Valida el archivo, lo agrega a la biblioteca y reemplaza el audio anterior.
	 *
	 * @param WP_Post     $post  Post destino.
	 * @param string      $tmp   Archivo temporal.
	 * @param string      $name  Nombre de archivo final (.mp3).
	 * @param string|null $title Título.
	 * @return array{attachment_id: int, url: string, mime_type: string, filesize: int, duration: int, replaced_attachment_id: int|null}|WP_Error
	 */
	private function attach( WP_Post $post, string $tmp, string $name, ?string $title ) {
		$valid = $this->validator->validate_file( $tmp, $name );
		if ( is_wp_error( $valid ) ) {
			$this->discard( $tmp );
			return $valid;
		}

		$title         = null !== $title ? sanitize_text_field( $title ) : '';
		$attachment_id = media_handle_sideload(
			array(
				'name'     => $name,
				'tmp_name' => $tmp,
			),
			$post->ID,
			'' !== $title ? $title : null
		);

		if ( is_wp_error( $attachment_id ) ) {
			$this->discard( $tmp );
			return $attachment_id;
		}

		update_post_meta( $attachment_id, self::INGESTED_META, '1' );

		$previous = AudioMeta::get_attachment_id( $post->ID );
		$saved    = update_post_meta( $post->ID, AudioMeta::META_KEY, $attachment_id );
		if ( false === $saved ) {
			wp_delete_attachment( $attachment_id, true );
			return new WP_Error( 'lb_sp_meta_failed', __( 'No se pudo asociar el audio a la entrada.', 'lb-simple-podcast' ) );
		}

		$replaced = null;
		if ( $previous > 0 && $previous !== $attachment_id ) {
			$replaced = $previous;
			$this->delete_if_ingested( $previous, $post->ID );
		}

		$data = AudioMeta::get_audio_data( $post->ID );
		if ( null === $data ) {
			return new WP_Error( 'lb_sp_meta_failed', __( 'El audio se guardó pero no se pudo leer.', 'lb-simple-podcast' ) );
		}

		return $data + array( 'replaced_attachment_id' => $replaced );
	}

	/**
	 * Borra el audio anterior solo si lo creó este plugin para este mismo post.
	 *
	 * @param int $attachment_id Attachment anterior.
	 * @param int $post_id       Post.
	 */
	private function delete_if_ingested( int $attachment_id, int $post_id ): void {
		$parent = (int) wp_get_post_parent_id( $attachment_id );
		if ( $parent === $post_id && '1' === get_post_meta( $attachment_id, self::INGESTED_META, true ) ) {
			wp_delete_attachment( $attachment_id, true );
		}
	}

	/**
	 * Obtiene el post destino.
	 *
	 * @param int $post_id ID.
	 * @return WP_Post|WP_Error
	 */
	private function get_post( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || AudioMeta::POST_TYPE !== $post->post_type ) {
			return new WP_Error( 'lb_sp_invalid_post', __( 'La entrada indicada no existe.', 'lb-simple-podcast' ), array( 'status' => 404 ) );
		}
		return $post;
	}

	/**
	 * Error de tamaño.
	 */
	private function too_large(): WP_Error {
		return new WP_Error(
			'lb_sp_file_too_large',
			sprintf(
				/* translators: %s: tamaño máximo legible. */
				__( 'El archivo de audio supera el tamaño máximo permitido (%s).', 'lb-simple-podcast' ),
				size_format( AudioValidator::max_bytes() )
			)
		);
	}

	/**
	 * Nombre de archivo `.mp3` a partir de la ruta de la URL.
	 *
	 * @param string $url     URL.
	 * @param int    $post_id ID del post (nombre por defecto).
	 */
	private function filename_from_url( string $url, int $post_id ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		return $this->sanitize_filename( rawurldecode( basename( $path ) ), $post_id );
	}

	/**
	 * Fuerza un nombre seguro con extensión .mp3 (el contenido real se verifica aparte).
	 *
	 * @param string $filename Nombre sugerido.
	 * @param int    $post_id  ID del post.
	 */
	private function sanitize_filename( string $filename, int $post_id ): string {
		$base = sanitize_file_name( pathinfo( $filename, PATHINFO_FILENAME ) );
		$base = '' !== $base ? $base : 'episode-' . $post_id;
		return $base . '.mp3';
	}

	/**
	 * Borra un archivo temporal.
	 *
	 * @param string $path Ruta.
	 */
	private function discard( string $path ): void {
		if ( '' !== $path && is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}
}
