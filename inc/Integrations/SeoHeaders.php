<?php
/**
 * Metadatos SEO del audio: Open Graph y JSON-LD (AudioObject).
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Integrations;

use LbSimplePodcast\Feed\Xml;
use LbSimplePodcast\Meta\AudioMeta;
use WP_Post;

/**
 * Imprime og:audio y JSON-LD en `wp_head` de las entradas con audio.
 *
 * Ni Yoast SEO ni Rank Math emiten `og:audio` por su cuenta, pero para evitar duplicados con
 * cualquier plugin o tema se inspecciona lo ya impreso en `wp_head` y solo se agrega lo que falta.
 */
final class SeoHeaders {

	/**
	 * Nivel de buffer propio (null si no se inició).
	 *
	 * @var int|null
	 */
	private ?int $buffer_level = null;

	/**
	 * Engancha los hooks.
	 */
	public function register(): void {
		add_action( 'wp_head', array( $this, 'start_buffer' ), 0 );
		add_action( 'wp_head', array( $this, 'output' ), 99 );
	}

	/**
	 * Inicia la captura de `wp_head` solo cuando hay audio que anunciar.
	 */
	public function start_buffer(): void {
		if ( null !== $this->buffer_level || null === $this->audio() ) {
			return;
		}
		ob_start();
		$this->buffer_level = ob_get_level();
	}

	/**
	 * Cierra la captura, reimprime lo capturado y agrega las etiquetas que falten.
	 */
	public function output(): void {
		$audio = $this->audio();
		$post  = $this->post();

		$captured = '';
		if ( null !== $this->buffer_level ) {
			// Solo se cierra si ningún otro código dejó buffers abiertos encima del nuestro.
			if ( ob_get_level() === $this->buffer_level ) {
				$captured = (string) ob_get_clean();
			}
			$this->buffer_level = null;
		}
		echo $captured; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Salida de otros plugins, sin modificar.

		if ( null === $audio || null === $post ) {
			return;
		}

		$print_og   = (bool) apply_filters( 'lb_sp_seo_output_og', true, $post, $audio );
		$print_json = (bool) apply_filters( 'lb_sp_seo_output_jsonld', true, $post, $audio );

		if ( $print_og && 1 !== preg_match( '/property=["\']og:audio["\']/i', $captured ) ) {
			echo $this->open_graph( $audio ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escapado en open_graph().
		}
		if ( $print_json && 1 !== preg_match( '/"@type"\s*:\s*"AudioObject"/', $captured ) ) {
			echo $this->json_ld( $post, $audio ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Codificado en json_ld().
		}
	}

	/**
	 * Etiquetas Open Graph.
	 *
	 * @param array{attachment_id: int, url: string, mime_type: string, filesize: int, duration: int} $audio Datos del audio.
	 */
	public function open_graph( array $audio ): string {
		$lines   = array();
		$lines[] = sprintf( '<meta property="og:audio" content="%s" />', esc_url( $audio['url'] ) );
		if ( str_starts_with( $audio['url'], 'https://' ) ) {
			$lines[] = sprintf( '<meta property="og:audio:secure_url" content="%s" />', esc_url( $audio['url'] ) );
		}
		$lines[] = sprintf( '<meta property="og:audio:type" content="%s" />', esc_attr( $audio['mime_type'] ) );

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Bloque JSON-LD con un AudioObject.
	 *
	 * @param WP_Post                                                                                 $post  Entrada.
	 * @param array{attachment_id: int, url: string, mime_type: string, filesize: int, duration: int} $audio Datos del audio.
	 */
	public function json_ld( WP_Post $post, array $audio ): string {
		$data = array(
			'@context'       => 'https://schema.org',
			'@type'          => 'AudioObject',
			'name'           => Xml::plain( $post->post_title ),
			'contentUrl'     => $audio['url'],
			'encodingFormat' => $audio['mime_type'],
			'uploadDate'     => get_post_time( 'c', true, $post ),
		);
		if ( $audio['duration'] > 0 ) {
			$data['duration'] = self::iso8601_duration( $audio['duration'] );
		}
		if ( $audio['filesize'] > 0 ) {
			$data['contentSize'] = (string) $audio['filesize'];
		}

		/**
		 * Filtra los datos del AudioObject antes de imprimirlos.
		 *
		 * @param array<string, mixed> $data  Datos JSON-LD.
		 * @param WP_Post              $post  Entrada.
		 * @param array<string, mixed> $audio Datos del audio.
		 */
		$data = (array) apply_filters( 'lb_sp_seo_jsonld', $data, $post, $audio );

		$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP );
		if ( false === $json ) {
			return '';
		}

		return '<script type="application/ld+json">' . $json . "</script>\n";
	}

	/**
	 * Duración ISO 8601 (p. ej. 345 => PT5M45S).
	 *
	 * @param int $seconds Segundos.
	 */
	public static function iso8601_duration( int $seconds ): string {
		$seconds = max( 0, $seconds );
		$hours   = intdiv( $seconds, 3600 );
		$minutes = intdiv( $seconds % 3600, 60 );
		$secs    = $seconds % 60;

		$out = 'PT';
		if ( $hours > 0 ) {
			$out .= $hours . 'H';
		}
		if ( $minutes > 0 || $hours > 0 ) {
			$out .= $minutes . 'M';
		}
		return $out . $secs . 'S';
	}

	/**
	 * Entrada singular actual si es un `post`.
	 */
	private function post(): ?WP_Post {
		if ( ! is_singular( AudioMeta::POST_TYPE ) ) {
			return null;
		}
		$post = get_queried_object();
		return $post instanceof WP_Post ? $post : null;
	}

	/**
	 * Datos del audio de la entrada actual, o null.
	 *
	 * @return array{attachment_id: int, url: string, mime_type: string, filesize: int, duration: int}|null
	 */
	private function audio(): ?array {
		$post = $this->post();
		if ( null === $post || post_password_required( $post ) ) {
			return null;
		}
		return AudioMeta::get_audio_data( $post->ID );
	}
}
