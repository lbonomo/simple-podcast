<?php
/**
 * Carga del panel de Gutenberg.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Admin;

use LbSimplePodcast\Meta\AudioMeta;

/**
 * Encola el script del Document Sidebar solo en el editor de `post`.
 */
final class EditorAssets {

	public const HANDLE = 'lb-sp-sidebar';

	/**
	 * Engancha los hooks.
	 */
	public function register(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
	}

	/**
	 * Encola el script si el editor es el de `post` y existe el build.
	 */
	public function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || AudioMeta::POST_TYPE !== $screen->post_type ) {
			return;
		}

		$asset_file = LB_SP_DIR . 'assets/build/index.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}

		/**
		 * Dependencias y versión generadas por @wordpress/scripts.
		 *
		 * @var array{dependencies: array<int, string>, version: string} $asset
		 */
		$asset = require $asset_file;

		wp_enqueue_script(
			self::HANDLE,
			LB_SP_URL . 'assets/build/index.js',
			array_unique( array_merge( $asset['dependencies'], array( 'wp-edit-post' ) ) ),
			$asset['version'],
			true
		);
		wp_set_script_translations( self::HANDLE, 'lb-simple-podcast', LB_SP_DIR . 'languages' );
	}
}
