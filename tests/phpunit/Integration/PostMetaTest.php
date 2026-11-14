<?php
/**
 * Tests del meta de audio y del campo REST audio_data.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Tests\Integration;

use LbSimplePodcast\Meta\AudioMeta;
use LbSimplePodcast\Media\AudioDelivery;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Cubre registro, validación, permisos y REST.
 */
final class PostMetaTest extends WP_UnitTestCase {

	/**
	 * ID de un MP3 válido en la biblioteca.
	 *
	 * @var int
	 */
	private int $mp3_id = 0;

	public function set_up(): void {
		parent::set_up();

		// WP_UnitTestCase desregistra los meta entre tests: se vuelve a registrar.
		( new AudioMeta() )->register_meta();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$this->mp3_id = (int) self::factory()->attachment->create_upload_object( dirname( __DIR__ ) . '/fixtures/sample.mp3' );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function image_attachment(): int {
		return (int) self::factory()->attachment->create(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'imagen',
			)
		);
	}

	public function test_meta_is_registered_for_rest(): void {
		$registered = get_registered_meta_keys( 'post', 'post' );

		$this->assertArrayHasKey( AudioMeta::META_KEY, $registered );
		$this->assertTrue( (bool) $registered[ AudioMeta::META_KEY ]['show_in_rest'] );
		$this->assertSame( 'integer', $registered[ AudioMeta::META_KEY ]['type'] );
	}

	public function test_valid_mp3_can_be_saved(): void {
		$post_id = self::factory()->post->create();

		$this->assertNotFalse( update_post_meta( $post_id, AudioMeta::META_KEY, $this->mp3_id ) );
		$this->assertSame( $this->mp3_id, AudioMeta::get_attachment_id( $post_id ) );
	}

	public function test_non_existent_id_is_rejected(): void {
		$post_id = self::factory()->post->create();

		$this->assertFalse( update_post_meta( $post_id, AudioMeta::META_KEY, 999999 ) );
		$this->assertSame( 0, AudioMeta::get_attachment_id( $post_id ) );
	}

	public function test_non_mp3_attachment_is_rejected(): void {
		$post_id = self::factory()->post->create();

		$this->assertFalse( update_post_meta( $post_id, AudioMeta::META_KEY, $this->image_attachment() ) );
		$this->assertSame( 0, AudioMeta::get_attachment_id( $post_id ) );
	}

	public function test_audio_data_is_null_without_audio(): void {
		$post_id = self::factory()->post->create();

		$this->assertNull( AudioMeta::get_audio_data( $post_id ) );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id ) );
		$data     = $response->get_data();
		$this->assertArrayHasKey( 'audio_data', $data );
		$this->assertNull( $data['audio_data'] );
	}

	public function test_audio_data_exposes_expected_fields_in_rest(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, AudioMeta::META_KEY, $this->mp3_id );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id ) );
		$audio    = $response->get_data()['audio_data'];

		$this->assertSame( $this->mp3_id, $audio['attachment_id'] );
		$this->assertSame( AudioDelivery::url( $post_id, $this->mp3_id ), $audio['url'] );
		$this->assertSame( 'audio/mpeg', $audio['mime_type'] );
		$this->assertSame( filesize( dirname( __DIR__ ) . '/fixtures/sample.mp3' ), $audio['filesize'] );
		$this->assertGreaterThanOrEqual( 1, $audio['duration'] );
	}

	public function test_author_can_set_meta_via_rest(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$post_id = self::factory()->post->create( array( 'post_author' => $author ) );

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
		$request->set_body_params( array( 'meta' => array( AudioMeta::META_KEY => $this->mp3_id ) ) );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( $this->mp3_id, $response->get_data()['audio_data']['attachment_id'] );
	}

	public function test_invalid_attachment_returns_400_in_rest(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$post_id = self::factory()->post->create( array( 'post_author' => $author ) );

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
		$request->set_body_params( array( 'meta' => array( AudioMeta::META_KEY => $this->image_attachment() ) ) );
		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'lb_sp_invalid_mime', $response->get_data()['code'] );
	}

	public function test_user_without_edit_capability_cannot_set_meta(): void {
		$owner = self::factory()->user->create( array( 'role' => 'author' ) );
		$other = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $other );
		$post_id = self::factory()->post->create( array( 'post_author' => $owner ) );

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
		$request->set_body_params( array( 'meta' => array( AudioMeta::META_KEY => $this->mp3_id ) ) );
		$response = rest_do_request( $request );

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
		$this->assertSame( 0, AudioMeta::get_attachment_id( $post_id ) );
	}

	public function test_auth_callback_checks_edit_post(): void {
		$owner = self::factory()->user->create( array( 'role' => 'author' ) );
		$other = self::factory()->user->create( array( 'role' => 'author' ) );
		$post  = self::factory()->post->create( array( 'post_author' => $owner ) );
		$meta  = new AudioMeta();

		$this->assertTrue( $meta->can_edit_meta( false, AudioMeta::META_KEY, $post, $owner ) );
		$this->assertFalse( $meta->can_edit_meta( true, AudioMeta::META_KEY, $post, $other ) );
	}
}
