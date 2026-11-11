<?php
/**
 * Inyección automática del reproductor en el contenido del post.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Frontend;

use LbSimplePodcast\Meta\AudioMeta;
use WP_Post;

/**
 * Agrega un reproductor `<audio>` al contenido de las entradas con MP3, sin depender del tema.
 */
final class PlayerInjector {

	public const STYLE_HANDLE    = 'lb-sp-player';
	public const TEMPLATE        = 'player.php';
	public const THEME_SUBFOLDER = 'lb-simple-podcast';
	public const POSITION_BEFORE = 'before';
	public const POSITION_AFTER  = 'after';

	/**
	 * Engancha los hooks.
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_style' ) );
		add_filter( 'the_content', array( $this, 'inject' ), 20 );
	}

	/**
	 * Registra (sin encolar) la hoja de estilos del reproductor.
	 */
	public function register_style(): void {
		wp_register_style(
			self::STYLE_HANDLE,
			LB_SP_URL . 'assets/css/player.css',
			array(),
			LB_SP_VERSION
		);
	}

	/**
	 * Filtro `the_content`: agrega el reproductor si corresponde.
	 *
	 * @param mixed $content Contenido del post.
	 * @return mixed
	 */
	public function inject( $content ) {
		if ( ! is_string( $content ) ) {
			return $content;
		}

		$post = $this->eligible_post();
		if ( null === $post ) {
			return $content;
		}

		$audio = AudioMeta::get_audio_data( $post->ID );
		if ( null === $audio ) {
			return $content;
		}

		/**
		 * Permite desactivar el reproductor (global o por post).
		 *
		 * @param bool    $enabled Si se debe mostrar el reproductor.
		 * @param WP_Post $post    Post actual.
		 */
		if ( ! (bool) apply_filters( 'lb_sp_player_enabled', true, $post ) ) {
			return $content;
		}

		$html = $this->render( $audio, $post );
		if ( '' === $html ) {
			return $content;
		}

		$this->maybe_enqueue_style();

		/**
		 * Posición del reproductor respecto del contenido: `before` (por defecto) o `after`.
		 *
		 * @param string  $position Posición.
		 * @param WP_Post $post     Post actual.
		 */
		$position = apply_filters( 'lb_sp_player_position', self::POSITION_BEFORE, $post );

		return self::POSITION_AFTER === $position ? $content . $html : $html . $content;
	}

	/**
	 * Genera el HTML del reproductor usando la plantilla (sobrescribible desde el tema).
	 *
	 * @param array{attachment_id: int, url: string, mime_type: string, filesize: int, duration: int} $audio Datos del audio.
	 * @param WP_Post                                                                                 $post  Post actual.
	 */
	public function render( array $audio, WP_Post $post ): string {
		$template = $this->locate_template();

		ob_start();
		( static function ( string $lb_sp_template, array $audio, WP_Post $post ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Las usa la plantilla.
			include $lb_sp_template;
		} )( $template, $audio, $post );
		$html = (string) ob_get_clean();

		/**
		 * Filtra el HTML final del reproductor.
		 *
		 * @param string  $html  HTML del reproductor.
		 * @param array   $audio Datos del audio (`url`, `mime_type`, `filesize`, `duration`, `attachment_id`).
		 * @param WP_Post $post  Post actual.
		 */
		return (string) apply_filters( 'lb_sp_player_html', $html, $audio, $post );
	}

	/**
	 * Ruta de la plantilla: la del tema (`{tema}/lb-simple-podcast/player.php`) o la del plugin.
	 */
	public function locate_template(): string {
		$theme_template = locate_template( self::THEME_SUBFOLDER . '/' . self::TEMPLATE );

		return '' !== $theme_template ? $theme_template : LB_SP_DIR . 'templates/' . self::TEMPLATE;
	}

	/**
	 * Post actual si es una vista singular principal y apta; null en cualquier otro caso.
	 */
	private function eligible_post(): ?WP_Post {
		if ( is_feed() || ! is_singular( AudioMeta::POST_TYPE ) || ! in_the_loop() || ! is_main_query() ) {
			return null;
		}

		// Evita que la generación automática de extractos dispare la inyección.
		if ( doing_filter( 'get_the_excerpt' ) ) {
			return null;
		}

		$post = get_post();
		if ( ! $post instanceof WP_Post || AudioMeta::POST_TYPE !== $post->post_type || post_password_required( $post ) ) {
			return null;
		}

		return $post;
	}

	/**
	 * Encola la hoja de estilos, salvo que un filtro lo impida.
	 */
	private function maybe_enqueue_style(): void {
		/**
		 * Permite no cargar el CSS del plugin (por ejemplo, si el tema define los estilos).
		 *
		 * @param bool $enqueue Si se debe encolar la hoja de estilos.
		 */
		if ( ! (bool) apply_filters( 'lb_sp_player_enqueue_styles', true ) ) {
			return;
		}

		if ( ! wp_style_is( self::STYLE_HANDLE, 'registered' ) ) {
			$this->register_style();
		}
		wp_enqueue_style( self::STYLE_HANDLE );
	}
}
