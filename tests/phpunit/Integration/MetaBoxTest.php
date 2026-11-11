<?php
/**
 * Tests del metabox del editor clásico.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Tests\Integration;

use LbSimplePodcast\Admin\MetaBox;
use LbSimplePodcast\Meta\AudioMeta;
use WP_UnitTestCase;

/**
 * Guardado seguro desde el formulario clásico.
 */
final class MetaBoxTest extends WP_UnitTestCase {

	/**
	 * ID de un MP3 válido.
	 *
	 * @var int
	 */
	private int $mp3_id = 0;

	public function set_up(): void {
		parent::set_up();
		( new AudioMeta() )->register_meta();
		$this->mp3_id = (int) self::factory()->attachment->create_upload_object( dirname( __DIR__ ) . '/fixtures/sample.mp3' );
	}

	public function tear_down(): void {
		$_POST = array();
		parent::tear_down();
	}

	private function submit( int $post_id, string $audio_id, bool $with_nonce = true ): void {
		$_POST = array( MetaBox::FIELD => $audio_id );
		if ( $with_nonce ) {
			$_POST[ MetaBox::NONCE_FIELD ] = wp_create_nonce( MetaBox::NONCE_ACTION );
		}
		( new MetaBox() )->save( $post_id, get_post( $post_id ) );
	}

	public function test_saves_valid_audio_with_nonce(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$post_id = self::factory()->post->create();

		$this->submit( $post_id, (string) $this->mp3_id );

		$this->assertSame( $this->mp3_id, AudioMeta::get_attachment_id( $post_id ) );
	}

	public function test_ignores_request_without_nonce(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$post_id = self::factory()->post->create();

		$this->submit( $post_id, (string) $this->mp3_id, false );

		$this->assertSame( 0, AudioMeta::get_attachment_id( $post_id ) );
	}

	public function test_ignores_user_without_edit_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$post_id = self::factory()->post->create();

		$this->submit( $post_id, (string) $this->mp3_id );

		$this->assertSame( 0, AudioMeta::get_attachment_id( $post_id ) );
	}

	public function test_invalid_attachment_is_not_saved_and_sets_error(): void {
		$user = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user );
		$post_id = self::factory()->post->create();

		$this->submit( $post_id, (string) $post_id ); // Un post, no un attachment.

		$this->assertSame( 0, AudioMeta::get_attachment_id( $post_id ) );
		$this->assertNotFalse( get_transient( 'lb_sp_err_' . $user . '_' . $post_id ) );
	}

	public function test_empty_value_removes_audio(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, AudioMeta::META_KEY, $this->mp3_id );

		$this->submit( $post_id, '' );

		$this->assertSame( 0, AudioMeta::get_attachment_id( $post_id ) );
	}

	public function test_render_outputs_nonce_and_field(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, AudioMeta::META_KEY, $this->mp3_id );

		ob_start();
		( new MetaBox() )->render( get_post( $post_id ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( MetaBox::NONCE_FIELD, $html );
		$this->assertStringContainsString( 'value="' . $this->mp3_id . '"', $html );
	}
}
