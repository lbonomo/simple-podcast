<?php
/**
 * Tests del encolado del sidebar de Gutenberg.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Tests\Integration;

use LbSimplePodcast\Admin\EditorAssets;
use WP_UnitTestCase;

/**
 * El script solo debe cargarse en el editor de posts.
 */
final class EditorAssetsTest extends WP_UnitTestCase {

	public function tear_down(): void {
		wp_dequeue_script( EditorAssets::HANDLE );
		wp_deregister_script( EditorAssets::HANDLE );
		unset( $GLOBALS['current_screen'] );
		parent::tear_down();
	}

	public function test_hook_is_registered(): void {
		$this->assertNotFalse( has_action( 'enqueue_block_editor_assets', array( \LbSimplePodcast\Plugin::instance()->get( 'editor_assets' ), 'enqueue' ) ) );
	}

	public function test_script_is_enqueued_on_post_editor(): void {
		set_current_screen( 'post' );
		( new EditorAssets() )->enqueue();

		$this->assertTrue( wp_script_is( EditorAssets::HANDLE, 'enqueued' ) );
		$this->assertContains( 'wp-media-utils', wp_scripts()->registered[ EditorAssets::HANDLE ]->deps );
	}

	public function test_script_is_not_enqueued_on_page_editor(): void {
		set_current_screen( 'page' );
		( new EditorAssets() )->enqueue();

		$this->assertFalse( wp_script_is( EditorAssets::HANDLE, 'enqueued' ) );
	}

	public function test_script_is_not_enqueued_without_screen(): void {
		unset( $GLOBALS['current_screen'] );
		( new EditorAssets() )->enqueue();

		$this->assertFalse( wp_script_is( EditorAssets::HANDLE, 'enqueued' ) );
	}
}
