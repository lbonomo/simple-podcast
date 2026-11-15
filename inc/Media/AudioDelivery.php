<?php
/**
 * Entrega y registro de solicitudes de audio.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Media;

use LbSimplePodcast\Meta\AudioMeta;
use LbSimplePodcast\Tracking\RequestLog;
use WP_Post;

/** Sirve los audios asociados a entradas. */
final class AudioDelivery {

	public const QUERY_POST       = 'lb_sp_audio';
	public const QUERY_ATTACHMENT = 'lb_sp_attachment';
	public const WARNING_OPTION   = 'lb_sp_storage_warning';

	/** Registra los hooks. */
	public function register(): void {
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_filter( 'exit_on_http_head', array( $this, 'allow_head' ) );
		add_action( 'init', array( $this, 'early_nocache' ), 0 );
		add_action( 'template_redirect', array( $this, 'serve' ), 0 );
	}

	/**
	 * Registra variables públicas.
	 *
	 * @param array<int, string> $vars Variables públicas.
	 * @return array<int, string>
	 */
	public function query_vars( array $vars ): array {
		$vars[] = self::QUERY_POST;
		$vars[] = self::QUERY_ATTACHMENT;
		return $vars;
	}

	/**
	 * Impide que WordPress termine las solicitudes HEAD antes de registrarlas.
	 *
	 * @param bool $should_exit Valor anterior.
	 */
	public function allow_head( bool $should_exit ): bool {
		return isset( $_GET[ self::QUERY_POST ] ) ? false : $should_exit; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/** Excluye de las cachés las solicitudes de audio antes de cualquier salida. */
	public function early_nocache(): void {
		if ( ! isset( $_GET[ self::QUERY_POST ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		foreach ( array( 'DONOTCACHEPAGE', 'DONOTCACHEOBJECT', 'DONOTCACHEDB' ) as $constant ) {
			if ( ! defined( $constant ) ) {
				define( $constant, true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound -- Constantes estándar de plugins de caché.
			}
		}
		$this->send_cache_headers();
		do_action( 'litespeed_control_set_nocache', 'lb-simple-podcast audio' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook de LiteSpeed Cache.
	}

	/** Envía las cabeceras de exclusión de caché si todavía es posible. */
	private function send_cache_headers(): void {
		if ( headers_sent() ) {
			return;
		}
		$headers = apply_filters(
			'lb_sp_audio_nocache_headers',
			array(
				'Cache-Control'             => 'private, no-store, no-cache, must-revalidate, max-age=0',
				'X-LiteSpeed-Cache-Control' => 'no-cache',
			)
		);
		if ( ! is_array( $headers ) ) {
			return;
		}
		foreach ( $headers as $name => $value ) {
			if ( is_string( $name ) && is_string( $value ) ) {
				header( $name . ': ' . $value );
			}
		}
	}

	/**
	 * Obtiene la URL de entrega.
	 *
	 * @param int $post_id Entrada.
	 * @param int $attachment_id Attachment.
	 */
	public static function url( int $post_id, int $attachment_id ): string {
		$base = get_permalink( $post_id );
		if ( ! is_string( $base ) || '' === $base ) {
			$base = home_url( '/index.php' );
		}
		return add_query_arg(
			array(
				self::QUERY_POST       => $post_id,
				self::QUERY_ATTACHMENT => $attachment_id,
			),
			$base
		);
	}

	/** Entrega la respuesta HTTP. */
	public function serve(): void {
		$value = get_query_var( self::QUERY_POST, '' );
		if ( '' === $value ) {
			return;
		}
		$post_id       = is_scalar( $value ) ? absint( $value ) : 0;
		$attachment_id = absint( get_query_var( self::QUERY_ATTACHMENT, 0 ) );
		$server        = (array) wp_unslash( $_SERVER ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$headers       = $this->request_headers( $server );
		$wait          = ( new RequestThrottle() )->hit( RequestThrottle::client_ip( $server ) );
		$response      = $wait > 0
			? array(
				'status'   => 429,
				'headers'  => array( 'Retry-After' => (string) $wait ),
				'path'     => '',
				'redirect' => '',
				'offset'   => 0,
				'length'   => 0,
				'valid'    => false,
			)
			: $this->response( $post_id, $attachment_id, $server );
		$file          = false;
		if ( '' !== $response['path'] && 'HEAD' !== strtoupper( (string) ( $server['REQUEST_METHOD'] ?? 'GET' ) ) ) {
			$file = @fopen( $response['path'], 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( false === $file || 0 !== fseek( $file, $response['offset'] ) ) {
				if ( false !== $file ) {
					fclose( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					$file = false;
				}
				$response['status']  = 500;
				$response['headers'] = array();
				update_option( self::WARNING_OPTION, '1', false );
			}
		}
		// Solo se registran los enlaces válidos: ni 404, 405, 403 ni solicitudes limitadas.
		if ( $response['valid'] ) {
			( new RequestLog() )->record( $post_id, $attachment_id, $response['status'], $server, $headers );
		}

		status_header( $response['status'] );
		nocache_headers();
		header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0' );
		$this->send_cache_headers();
		header( 'X-Content-Type-Options: nosniff' );
		foreach ( $response['headers'] as $name => $header ) {
			header( $name . ': ' . $header );
		}
		if ( '' !== $response['redirect'] ) {
			wp_redirect( $response['redirect'], $response['status'], 'Simple Podcast' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
			exit;
		}
		if ( false !== $file ) {
				$remaining = $response['length'];
			while ( $remaining > 0 && ! feof( $file ) && ! connection_aborted() ) {
				$chunk = fread( $file, min( 65536, $remaining ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				if ( false === $chunk || '' === $chunk ) {
					update_option( self::WARNING_OPTION, '1', false );
					break;
				}
				echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$remaining -= strlen( $chunk );
			}
				fclose( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		exit;
	}

	/**
	 * Prepara los datos de respuesta.
	 *
	 * @param int                  $post_id Entrada.
	 * @param int                  $attachment_id Attachment.
	 * @param array<string, mixed> $server Datos de la petición.
	 * @return array{status: int, headers: array<string, string>, path: string, redirect: string, offset: int, length: int, valid: bool}
	 */
	public function response( int $post_id, int $attachment_id, array $server ): array {
		$response = array(
			'status'   => 404,
			'headers'  => array(),
			'path'     => '',
			'redirect' => '',
			'offset'   => 0,
			'length'   => 0,
			'valid'    => false,
		);
		$method   = strtoupper( (string) ( $server['REQUEST_METHOD'] ?? 'GET' ) );
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			$response['status']           = 405;
			$response['headers']['Allow'] = 'GET, HEAD';
			return $response;
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || AudioMeta::POST_TYPE !== $post->post_type || $attachment_id <= 0 || AudioMeta::get_attachment_id( $post_id ) !== $attachment_id ) {
			return $response;
		}
		if ( ( 'publish' !== $post->post_status && ! current_user_can( 'read_post', $post_id ) ) || post_password_required( $post ) ) {
			$response['status'] = 403;
			return $response;
		}
		$attachment = get_post( $attachment_id );
		if ( ! $attachment instanceof WP_Post || 'attachment' !== $attachment->post_type || AudioValidator::MIME_TYPE !== $attachment->post_mime_type ) {
			return $response;
		}
		// Enlace válido: entrada pública, attachment coincidente y MP3.
		$response['valid'] = true;
		$path              = get_attached_file( $attachment_id );
		if ( ! is_string( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			$url   = wp_get_attachment_url( $attachment_id );
			$url   = is_string( $url ) ? esc_url_raw( $url, array( 'http', 'https' ) ) : '';
			$parts = wp_parse_url( $url );
			if ( is_array( $parts ) && ! empty( $parts['host'] ) && isset( $parts['scheme'] ) && in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
				update_option( self::WARNING_OPTION, '1', false );
				$response['status']   = 302;
				$response['redirect'] = $url;
			}
			return $response;
		}
		$path = $this->protected_copy( $path, $attachment_id );
		$size = filesize( $path );
		if ( false === $size || $size <= 0 ) {
			$response['status'] = 500;
			return $response;
		}
		$response['status']  = 200;
		$response['headers'] = array(
			'Content-Type'   => AudioValidator::MIME_TYPE,
			'Accept-Ranges'  => 'bytes',
			'Content-Length' => (string) $size,
		);
		$response['path']    = $path;
		$response['length']  = $size;
		$range               = isset( $server['HTTP_RANGE'] ) && is_string( $server['HTTP_RANGE'] ) ? trim( $server['HTTP_RANGE'] ) : '';
		if ( 'GET' !== $method || '' === $range || str_contains( $range, ',' ) || isset( $server['HTTP_IF_RANGE'] ) ) {
			return $response;
		}
		if ( 1 !== preg_match( '/^bytes=(\d*)-(\d*)$/', $range, $matches ) || ( '' === $matches[1] && '' === $matches[2] ) ) {
			return $response;
		}
		$start = '' === $matches[1] ? max( 0, $size - (int) $matches[2] ) : (int) $matches[1];
		$end   = '' === $matches[1] || '' === $matches[2] ? $size - 1 : min( $size - 1, (int) $matches[2] );
		if ( $start >= $size || $start > $end || ( '' === $matches[1] && 0 === (int) $matches[2] ) ) {
			$response['status']                    = 416;
			$response['path']                      = '';
			$response['length']                    = 0;
			$response['headers']['Content-Range']  = 'bytes */' . $size;
			$response['headers']['Content-Length'] = '0';
			return $response;
		}
		$response['status']                    = 206;
		$response['offset']                    = $start;
		$response['length']                    = $end - $start + 1;
		$response['headers']['Content-Range']  = "bytes $start-$end/$size";
		$response['headers']['Content-Length'] = (string) $response['length'];
		return $response;
	}

	/**
	 * Recoge las cabeceras que expone PHP.
	 *
	 * @param array<string, mixed> $server Variables PHP.
	 * @return array<string, string>
	 */
	private function request_headers( array $server ): array {
		$headers = function_exists( 'getallheaders' ) ? getallheaders() : array();
		if ( ! is_array( $headers ) ) {
			$headers = array();
		}
		foreach ( $server as $key => $value ) {
			if ( is_string( $key ) && is_string( $value ) && ( str_starts_with( $key, 'HTTP_' ) || in_array( $key, array( 'CONTENT_TYPE', 'CONTENT_LENGTH' ), true ) ) ) {
				$name             = str_replace( '_', '-', str_starts_with( $key, 'HTTP_' ) ? substr( $key, 5 ) : $key );
				$headers[ $name ] = $value;
			}
		}
		return array_filter( $headers, 'is_string' );
	}

	/**
	 * Usa una copia privada o conserva el archivo original como alternativa.
	 *
	 * @param string $source Archivo original.
	 * @param int    $attachment_id Attachment.
	 */
	private function protected_copy( string $source, int $attachment_id ): string {
		$dir         = defined( 'LB_SP_PRIVATE_AUDIO_DIR' ) ? (string) LB_SP_PRIVATE_AUDIO_DIR : '';
		$dir         = (string) apply_filters( 'lb_sp_private_audio_dir', $dir );
		$root        = isset( $_SERVER['DOCUMENT_ROOT'] ) && is_string( $_SERVER['DOCUMENT_ROOT'] ) ? realpath( $_SERVER['DOCUMENT_ROOT'] ) : false;
		$dir         = '' !== $dir ? realpath( $dir ) : false;
		$wp_root     = realpath( ABSPATH );
		$uploads     = wp_upload_dir();
		$upload_root = ! empty( $uploads['basedir'] ) ? realpath( $uploads['basedir'] ) : false;
		if ( false === $dir || false === $root || false === $wp_root || false === $upload_root || ! is_writable( $dir ) || $this->inside( $dir, $root ) || $this->inside( $dir, $wp_root ) || $this->inside( $dir, $upload_root ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			update_option( self::WARNING_OPTION, '1', false );
			return $source;
		}
		$hash = hash_file( 'sha256', $source );
		if ( false === $hash ) {
			update_option( self::WARNING_OPTION, '1', false );
			return $source;
		}
		$target = $dir . '/lb-sp-' . get_current_blog_id() . '-' . $attachment_id . '-' . $hash . '.mp3';
		if ( ! is_link( $target ) && is_file( $target ) && is_readable( $target ) && hash_file( 'sha256', $target ) === $hash ) {
			return $target;
		}
		$tmp = tempnam( $dir, 'lb-sp-' );
		if ( false !== $tmp && @copy( $source, $tmp ) && hash_file( 'sha256', $tmp ) === $hash && @chmod( $tmp, 0600 ) && @rename( $tmp, $target ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged
			return $target;
		}
		if ( false !== $tmp && is_file( $tmp ) ) {
			wp_delete_file( $tmp );
		}
		update_option( self::WARNING_OPTION, '1', false );
		return $source;
	}

	/**
	 * Comprueba si una ruta está dentro de otra.
	 *
	 * @param string $path Ruta.
	 * @param string $base Directorio base.
	 */
	private function inside( string $path, string $base ): bool {
		return $path === $base || str_starts_with( $path . '/', rtrim( $base, '/' ) . '/' );
	}
}
