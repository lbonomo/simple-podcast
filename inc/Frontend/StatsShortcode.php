<?php
/**
 * Shortcode público para mostrar estadísticas agregadas del podcast en el frontend.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Frontend;

use LbSimplePodcast\Tracking\RequestStats;
use WP_Post;

/**
 * Registra y renderiza `[lb_podcast_stats]` con validación estricta y sin exponer PII.
 */
final class StatsShortcode {

	public const TAG             = 'lb_podcast_stats';
	public const STYLE_HANDLE    = 'lb-sp-stats';
	public const TEMPLATE        = 'stats.php';
	public const THEME_SUBFOLDER = 'lb-simple-podcast';
	public const DEFAULT_DAYS    = 30;
	public const DEFAULT_LIMIT   = 5;
	public const MAX_LIMIT       = 10;

	/**
	 * Secciones válidas para el atributo `show`.
	 *
	 * @var array<int, string>
	 */
	public const ALLOWED_SHOW = array(
		'summary',
		'listens',
		'episodes',
		'listeners',
		'top_episodes',
		'clients',
	);

	/**
	 * Engancha los hooks del shortcode y de sus estilos.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_style' ) );
	}

	/**
	 * Registra el shortcode `[lb_podcast_stats]`.
	 */
	public function register_shortcode(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * Registra (sin encolar) la hoja de estilos pública del shortcode.
	 */
	public function register_style(): void {
		wp_register_style(
			self::STYLE_HANDLE,
			LB_SP_URL . 'assets/css/stats.css',
			array(),
			LB_SP_VERSION
		);
	}

	/**
	 * Renderiza el shortcode `[lb_podcast_stats]`.
	 *
	 * No lee parámetros de la URL (`$_GET`/`$_POST`): solo acepta atributos estáticos
	 * definidos por quien edita la entrada o página, y solo expone datos agregados públicos.
	 *
	 * @param array<string, mixed>|string $atts          Atributos del shortcode.
	 * @param string|null                 $content       Contenido interno (ignorado).
	 * @param string                      $shortcode_tag Nombre del shortcode.
	 */
	public function render( $atts = array(), ?string $content = null, string $shortcode_tag = self::TAG ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		unset( $content, $shortcode_tag );

		if ( ! $this->is_eligible_context() ) {
			return '';
		}

		$args = $this->parse_attributes( $atts );
		if ( null === $args ) {
			return '';
		}

		/**
		 * Permite desactivar el shortcode de estadísticas públicas (global o según argumentos).
		 *
		 * @param bool                 $enabled Si se debe renderizar el shortcode.
		 * @param array<string, mixed> $args    Argumentos ya validados del shortcode.
		 */
		if ( ! (bool) apply_filters( 'lb_sp_stats_enabled', true, $args ) ) {
			return '';
		}

		$stats = ( new RequestStats() )->public_stats( $args['days'], $args['post_id'], $args['limit'] );
		if ( ! $stats['eligible'] ) {
			return '';
		}

		/**
		 * Filtra los datos públicos antes de pasarlos a la plantilla.
		 *
		 * @param array<string, mixed> $stats Datos agregados públicos (sin PII).
		 * @param array<string, mixed> $args  Argumentos validados del shortcode.
		 */
		$filtered_stats = apply_filters( 'lb_sp_stats_data', $stats, $args );
		if ( ! is_array( $filtered_stats ) ) {
			return '';
		}

		$html = $this->render_template( $filtered_stats, $args );

		/**
		 * Filtra el HTML final del shortcode de estadísticas públicas.
		 *
		 * @param string               $html  HTML renderizado.
		 * @param array<string, mixed> $stats Datos públicos.
		 * @param array<string, mixed> $args  Argumentos del shortcode.
		 */
		$html = (string) apply_filters( 'lb_sp_stats_html', $html, $filtered_stats, $args );

		if ( '' !== trim( $html ) ) {
			$this->maybe_enqueue_style();
		}

		return $html;
	}

	/**
	 * Ruta de la plantilla: `{tema}/lb-simple-podcast/stats.php` o la del plugin.
	 */
	public function locate_template(): string {
		$theme_template = locate_template( self::THEME_SUBFOLDER . '/' . self::TEMPLATE );

		return '' !== $theme_template ? $theme_template : LB_SP_DIR . 'templates/' . self::TEMPLATE;
	}

	/**
	 * Comprueba que el contexto actual permita renderizar el shortcode.
	 */
	private function is_eligible_context(): bool {
		if ( is_feed() || doing_filter( 'get_the_excerpt' ) ) {
			return false;
		}

		$current_post = get_post();
		if ( $current_post instanceof WP_Post && post_password_required( $current_post ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Sanea y valida los atributos del shortcode contra listas blancas.
	 *
	 * Devuelve `null` si el `post_id` solicitado no es un episodio público válido
	 * o si no queda ninguna sección válida para mostrar (fallo cerrado).
	 *
	 * @param array<string, mixed>|string $atts Atributos crudos.
	 * @return array{
	 *   days: int,
	 *   post_id: int,
	 *   limit: int,
	 *   title: string,
	 *   class: string,
	 *   sections: array<string, bool>
	 * }|null
	 */
	private function parse_attributes( $atts ): ?array {
		$raw_atts = is_array( $atts ) ? $atts : array();
		$parsed   = shortcode_atts(
			array(
				'days'    => (string) self::DEFAULT_DAYS,
				'post_id' => '0',
				'show'    => 'summary',
				'limit'   => (string) self::DEFAULT_LIMIT,
				'title'   => '',
				'class'   => '',
			),
			$raw_atts,
			self::TAG
		);

		$days = RequestStats::normalize_days( absint( $parsed['days'] ) );

		$raw_post_id = trim( (string) $parsed['post_id'] );
		$post_id     = 0;
		if ( '' !== $raw_post_id && '0' !== $raw_post_id ) {
			if ( 'current' === strtolower( $raw_post_id ) ) {
				$post_id = (int) get_the_ID();
			} else {
				$post_id = absint( $raw_post_id );
			}

			if ( $post_id <= 0 || ! RequestStats::is_public_episode( $post_id ) ) {
				return null;
			}
		}

		$sections = $this->parse_show( (string) $parsed['show'], $post_id );
		if ( ! in_array( true, $sections, true ) ) {
			return null;
		}

		$limit = min( self::MAX_LIMIT, max( 1, absint( $parsed['limit'] ) ) );
		$title = sanitize_text_field( (string) $parsed['title'] );
		$class = $this->sanitize_classes( (string) $parsed['class'] );

		return array(
			'days'     => $days,
			'post_id'  => $post_id,
			'limit'    => $limit,
			'title'    => $title,
			'class'    => $class,
			'sections' => $sections,
		);
	}

	/**
	 * Resuelve qué secciones mostrar a partir de la lista blanca del atributo `show`.
	 *
	 * @param string $raw_show Cadena de secciones separadas por coma o espacio.
	 * @param int    $post_id  ID del episodio (0 = global).
	 * @return array<string, bool>
	 */
	private function parse_show( string $raw_show, int $post_id ): array {
		$sections = array(
			'listens'      => false,
			'episodes'     => false,
			'listeners'    => false,
			'top_episodes' => false,
			'clients'      => false,
		);

		$tokens = preg_split( '/[\s,]+/', strtolower( trim( $raw_show ) ) );
		if ( ! is_array( $tokens ) ) {
			return $sections;
		}

		foreach ( $tokens as $token ) {
			if ( '' === $token || ! in_array( $token, self::ALLOWED_SHOW, true ) ) {
				continue;
			}

			if ( 'summary' === $token ) {
				$sections['listens']   = true;
				$sections['listeners'] = true;
				if ( 0 === $post_id ) {
					$sections['episodes'] = true;
				}
				continue;
			}

			if ( ( 'episodes' === $token || 'top_episodes' === $token ) && $post_id > 0 ) {
				continue;
			}

			$sections[ $token ] = true;
		}

		return $sections;
	}

	/**
	 * Sanea una lista de clases CSS separadas por espacios.
	 *
	 * @param string $raw_classes Clases recibidas en el atributo `class`.
	 */
	private function sanitize_classes( string $raw_classes ): string {
		$parts = preg_split( '/\s+/', trim( $raw_classes ) );
		if ( ! is_array( $parts ) ) {
			return '';
		}

		$clean = array();
		foreach ( $parts as $part ) {
			$sanitized = sanitize_html_class( $part );
			if ( '' !== $sanitized ) {
				$clean[] = $sanitized;
			}
		}

		return implode( ' ', $clean );
	}

	/**
	 * Renderiza la plantilla aislada con los datos públicos y los argumentos validados.
	 *
	 * @param array<string, mixed> $stats Datos públicos.
	 * @param array<string, mixed> $args  Argumentos validados.
	 */
	private function render_template( array $stats, array $args ): string {
		$template = $this->locate_template();

		ob_start();
		( static function ( string $lb_sp_template, array $stats, array $args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Las usa la plantilla.
			include $lb_sp_template;
		} )( $template, $stats, $args );
		return (string) ob_get_clean();
	}

	/**
	 * Encola la hoja de estilos del shortcode salvo que un filtro lo impida.
	 */
	private function maybe_enqueue_style(): void {
		/**
		 * Permite desactivar el CSS del shortcode público de estadísticas.
		 *
		 * @param bool $enqueue Si se debe encolar la hoja de estilos.
		 */
		if ( ! (bool) apply_filters( 'lb_sp_stats_enqueue_styles', true ) ) {
			return;
		}

		if ( ! wp_style_is( self::STYLE_HANDLE, 'registered' ) ) {
			$this->register_style();
		}
		wp_enqueue_style( self::STYLE_HANDLE );
	}
}
