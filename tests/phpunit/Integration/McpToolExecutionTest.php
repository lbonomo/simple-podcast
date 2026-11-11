<?php
/**
 * Tests de la ability publish-post-audio.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Tests\Integration;

use LbSimplePodcast\Integrations\McpAbility;
use LbSimplePodcast\Meta\AudioMeta;
use WP_Ability;
use WP_UnitTestCase;

/**
 * Registro, permisos, validación y ejecución.
 */
final class McpToolExecutionTest extends WP_UnitTestCase {

	/**
	 * URLs pedidas por el stub HTTP.
	 *
	 * @var array<int, string>
	 */
	private array $requests = array();

	public function set_up(): void {
		parent::set_up();
		( new AudioMeta() )->register_meta();
		$this->requests = array();
	}

	private function ability(): WP_Ability {
		$ability = wp_get_ability( McpAbility::NAME );
		$this->assertInstanceOf( WP_Ability::class, $ability, 'La ability debe estar registrada.' );
		return $ability;
	}

	private function mp3_base64(): string {
		return base64_encode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/sample.mp3' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions, WordPress.WP.AlternativeFunctions
	}

	private function login( string $role ): int {
		$user = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user );
		return $user;
	}

	public function test_ability_is_registered_as_public_mcp_tool(): void {
		$ability = $this->ability();
		$meta    = $ability->get_meta();

		$this->assertTrue( $meta['mcp']['public'] );
		$this->assertSame( 'tool', $meta['mcp']['type'] );
		$this->assertSame( 'podcast', $ability->get_category() );
		$this->assertSame( array( 'post_id' ), $ability->get_input_schema()['required'] );
		$this->assertArrayHasKey( 'audio_url', $ability->get_input_schema()['properties'] );
		$this->assertArrayHasKey( 'audio_base64', $ability->get_input_schema()['properties'] );
		$this->assertArrayHasKey( 'title', $ability->get_input_schema()['properties'] );
	}

	public function test_ability_is_registered_with_the_abilities_api_action(): void {
		$this->assertNotFalse( has_action( 'wp_abilities_api_init' ) );
		$this->assertTrue( wp_has_ability( McpAbility::NAME ) );
	}

	public function test_nonexistent_post_returns_error(): void {
		$this->login( 'administrator' );

		$result = $this->ability()->execute(
			array(
				'post_id'      => 999999,
				'audio_base64' => $this->mp3_base64(),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 0, AudioMeta::get_attachment_id( 999999 ) );
	}

	public function test_nonexistent_post_returns_not_found_when_calling_the_handler_directly(): void {
		$result = ( new McpAbility() )->execute(
			array(
				'post_id'      => 999999,
				'audio_base64' => $this->mp3_base64(),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'lb_sp_invalid_post', $result->get_error_code() );
	}

	public function test_anonymous_user_is_denied(): void {
		wp_set_current_user( 0 );
		$post_id = self::factory()->post->create();

		$result = $this->ability()->execute(
			array(
				'post_id'      => $post_id,
				'audio_base64' => $this->mp3_base64(),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 0, AudioMeta::get_attachment_id( $post_id ) );
	}

	public function test_user_without_edit_permission_is_denied(): void {
		$owner   = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id = self::factory()->post->create( array( 'post_author' => $owner ) );
		$this->login( 'author' );

		$result = $this->ability()->execute(
			array(
				'post_id'      => $post_id,
				'audio_base64' => $this->mp3_base64(),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 0, AudioMeta::get_attachment_id( $post_id ) );
	}

	public function test_user_without_upload_capability_is_denied(): void {
		$user    = $this->login( 'contributor' );
		$post_id = self::factory()->post->create( array( 'post_author' => $user ) );

		$result = $this->ability()->execute(
			array(
				'post_id'      => $post_id,
				'audio_base64' => $this->mp3_base64(),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 0, AudioMeta::get_attachment_id( $post_id ) );
	}

	public function test_missing_audio_source_returns_error(): void {
		$this->login( 'administrator' );
		$post_id = self::factory()->post->create();

		$result = $this->ability()->execute( array( 'post_id' => $post_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'lb_sp_missing_audio', $result->get_error_code() );
	}

	public function test_both_audio_sources_return_error(): void {
		$this->login( 'administrator' );
		$post_id = self::factory()->post->create();

		$result = $this->ability()->execute(
			array(
				'post_id'      => $post_id,
				'audio_url'    => 'https://example.org/a.mp3',
				'audio_base64' => $this->mp3_base64(),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'lb_sp_conflicting_audio', $result->get_error_code() );
	}

	public function test_missing_post_id_fails_schema_validation(): void {
		$this->login( 'administrator' );

		$result = $this->ability()->execute( array( 'audio_base64' => $this->mp3_base64() ) );

		$this->assertWPError( $result );
	}

	public function test_unknown_properties_are_rejected_by_the_schema(): void {
		$this->login( 'administrator' );
		$post_id = self::factory()->post->create();

		$result = $this->ability()->execute(
			array(
				'post_id'      => $post_id,
				'audio_base64' => $this->mp3_base64(),
				'hack'         => true,
			)
		);

		$this->assertWPError( $result );
	}

	public function test_author_publishes_audio_from_base64_on_own_post(): void {
		$user    = $this->login( 'author' );
		$post_id = self::factory()->post->create( array( 'post_author' => $user ) );

		$result = $this->ability()->execute(
			array(
				'post_id'      => $post_id,
				'audio_base64' => $this->mp3_base64(),
				'title'        => 'Episodio por MCP',
				'filename'     => 'episodio.mp3',
			)
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( $post_id, $result['post_id'] );
		$this->assertSame( AudioMeta::get_attachment_id( $post_id ), $result['attachment_id'] );
		$this->assertSame( 'audio/mpeg', $result['mime_type'] );
		$this->assertGreaterThan( 0, $result['filesize'] );
		$this->assertNull( $result['replaced_attachment_id'] );
		$this->assertSame( get_permalink( $post_id ), $result['permalink'] );
		$this->assertSame( get_feed_link( 'podcast' ), $result['feed_url'] );
		$this->assertSame( 'Episodio por MCP', get_the_title( $result['attachment_id'] ) );
	}

	public function test_editor_publishes_audio_from_url(): void {
		$this->login( 'editor' );
		$post_id = self::factory()->post->create();
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				$this->requests[] = $url;
				copy( dirname( __DIR__ ) . '/fixtures/sample.mp3', $args['filename'] );
				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => $args['filename'],
				);
			},
			10,
			3
		);

		$result = $this->ability()->execute(
			array(
				'post_id'   => $post_id,
				'audio_url' => 'https://example.org/podcast/ep1.mp3',
			)
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( array( 'https://example.org/podcast/ep1.mp3' ), $this->requests );
		$this->assertSame( AudioMeta::get_attachment_id( $post_id ), $result['attachment_id'] );
	}

	public function test_private_network_url_is_rejected(): void {
		$this->login( 'administrator' );
		$post_id = self::factory()->post->create();

		$result = $this->ability()->execute(
			array(
				'post_id'   => $post_id,
				'audio_url' => 'http://169.254.169.254/latest/meta-data/',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'lb_sp_invalid_url', $result->get_error_code() );
	}

	public function test_non_mp3_payload_is_rejected(): void {
		$this->login( 'administrator' );
		$post_id = self::factory()->post->create();

		$result = $this->ability()->execute(
			array(
				'post_id'      => $post_id,
				'audio_base64' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'lb_sp_invalid_mime', $result->get_error_code() );
	}

	public function test_published_audio_shows_up_in_the_feed(): void {
		$this->login( 'administrator' );
		$post_id = self::factory()->post->create( array( 'post_title' => 'Aparece en el feed' ) );

		$this->ability()->execute(
			array(
				'post_id'      => $post_id,
				'audio_base64' => $this->mp3_base64(),
			)
		);

		$body = ( new \LbSimplePodcast\Feed\PodcastFeedRenderer() )->response( array() )['body'];
		$this->assertStringContainsString( 'Aparece en el feed', $body );
	}
}
