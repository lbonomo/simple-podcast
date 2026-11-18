<?php
/**
 * Bloque dinámico de Gutenberg para mostrar estadísticas públicas del podcast.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Frontend;

use LbSimplePodcast\Tracking\RequestStats;
use WP_Block;
use WP_Block_Type_Registry;

/**
 * Registra `lb-simple-podcast/podcast-stats` y delega el renderizado seguro en `StatsShortcode`.
 */
final class StatsBlock {

	public const BLOCK_NAME    = 'lb-simple-podcast/podcast-stats';
	public const SCRIPT_HANDLE = 'lb-sp-stats-block';

	/**
	 * Handler del shortcode que comparte la validación y plantilla.
	 *
	 * @var StatsShortcode
	 */
	private StatsShortcode $shortcode;

	/**
	 * Constructor.
	 *
	 * @param StatsShortcode|null $shortcode Instancia del shortcode público.
	 */
	public function __construct( ?StatsShortcode $shortcode = null ) {
		$this->shortcode = $shortcode ?? new StatsShortcode();
	}

	/**
	 * Engancha el registro del bloque en `init`.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_block' ) );
	}

	/**
	 * Registra los assets del bloque y su metadata en WordPress.
	 */
	public function register_block(): void {
		if ( ! wp_style_is( StatsShortcode::STYLE_HANDLE, 'registered' ) ) {
			$this->shortcode->register_style();
		}

		$this->register_editor_script();

		if ( WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK_NAME ) ) {
			return;
		}

		register_block_type(
			__DIR__ . '/block.json',
			array(
				'render_callback' => array( $this, 'render' ),
			)
		);
	}

	/**
	 * Renderiza el bloque en el frontend y en el `ServerSideRender` del editor.
	 *
	 * @param array<string, mixed> $attributes Atributos del bloque.
	 * @param string               $content    Contenido interno (no usado).
	 * @param WP_Block|null        $block      Instancia del bloque.
	 */
	public function render( array $attributes = array(), string $content = '', ?WP_Block $block = null ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		unset( $content, $block );

		$scope = isset( $attributes['scope'] ) && is_string( $attributes['scope'] ) ? $attributes['scope'] : 'all';
		if ( ! in_array( $scope, array( 'all', 'current', 'episode' ), true ) ) {
			return '';
		}

		$post_id_attr = '0';
		if ( 'current' === $scope ) {
			$post_id_attr = 'current';
		} elseif ( 'episode' === $scope ) {
			$pid = isset( $attributes['postId'] ) && is_scalar( $attributes['postId'] ) ? absint( $attributes['postId'] ) : 0;
			if ( $pid <= 0 ) {
				return '';
			}
			$post_id_attr = (string) $pid;
		}

		$show_tokens = array();
		if ( ! empty( $attributes['showListens'] ) ) {
			$show_tokens[] = 'listens';
		}
		if ( ! empty( $attributes['showListeners'] ) ) {
			$show_tokens[] = 'listeners';
		}
		if ( 'all' === $scope && ! empty( $attributes['showEpisodes'] ) ) {
			$show_tokens[] = 'episodes';
		}
		if ( 'all' === $scope && ! empty( $attributes['showTopEpisodes'] ) ) {
			$show_tokens[] = 'top_episodes';
		}
		if ( ! empty( $attributes['showClients'] ) ) {
			$show_tokens[] = 'clients';
		}

		if ( array() === $show_tokens ) {
			return '';
		}

		$raw_days = isset( $attributes['days'] ) && is_scalar( $attributes['days'] ) ? absint( $attributes['days'] ) : StatsShortcode::DEFAULT_DAYS;
		$days     = RequestStats::normalize_days( $raw_days );
		$limit    = isset( $attributes['limit'] ) && is_scalar( $attributes['limit'] )
			? min( StatsShortcode::MAX_LIMIT, max( 1, absint( $attributes['limit'] ) ) )
			: StatsShortcode::DEFAULT_LIMIT;
		$title    = isset( $attributes['title'] ) && is_string( $attributes['title'] ) ? $attributes['title'] : '';

		$classes = array();
		$align   = isset( $attributes['align'] ) && is_string( $attributes['align'] ) ? $attributes['align'] : '';
		if ( in_array( $align, array( 'wide', 'full' ), true ) ) {
			$classes[] = 'align' . $align;
		}
		if ( isset( $attributes['className'] ) && is_string( $attributes['className'] ) && '' !== trim( $attributes['className'] ) ) {
			$classes[] = $attributes['className'];
		}

		return $this->shortcode->render(
			array(
				'days'    => (string) $days,
				'post_id' => $post_id_attr,
				'show'    => implode( ',', $show_tokens ),
				'limit'   => (string) $limit,
				'title'   => $title,
				'class'   => implode( ' ', $classes ),
			)
		);
	}

	/**
	 * Registra el script del editor generado por `@wordpress/scripts`.
	 */
	private function register_editor_script(): void {
		if ( wp_script_is( self::SCRIPT_HANDLE, 'registered' ) ) {
			return;
		}

		$asset_file = LB_SP_DIR . 'assets/build/stats-block.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}

		/**
		 * Dependencias y versión generadas por @wordpress/scripts.
		 *
		 * @var array{dependencies: array<int, string>, version: string} $asset
		 */
		$asset = require $asset_file;

		wp_register_script(
			self::SCRIPT_HANDLE,
			LB_SP_URL . 'assets/build/stats-block.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_set_script_translations( self::SCRIPT_HANDLE, 'lb-simple-podcast', LB_SP_DIR . 'languages' );
	}
}
