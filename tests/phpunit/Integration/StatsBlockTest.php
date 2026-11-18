<?php
/**
 * Tests de integración y seguridad para el bloque Gutenberg `lb-simple-podcast/podcast-stats`.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Tests\Integration;

use LbSimplePodcast\Frontend\StatsBlock;
use LbSimplePodcast\Frontend\StatsShortcode;
use LbSimplePodcast\Meta\AudioMeta;
use LbSimplePodcast\Tracking\RequestLog;
use LbSimplePodcast\Tracking\RequestStats;
use WP_Block_Type_Registry;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Registro, renderizado en frontend y seguridad en block-renderer REST.
 */
final class StatsBlockTest extends WP_UnitTestCase {

	/**
	 * Attachment MP3 válido de prueba.
	 *
	 * @var int
	 */
	private int $mp3_id = 0;

	public function set_up(): void {
		parent::set_up();
		( new AudioMeta() )->register_meta();
		$this->mp3_id = (int) self::factory()->attachment->create_upload_object( dirname( __DIR__ ) . '/fixtures/sample.mp3' );
		( new RequestLog() )->upgrade();
		$this->clear_log();
		RequestStats::invalidate_cache();
		wp_dequeue_style( StatsShortcode::STYLE_HANDLE );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		$this->clear_log();
		RequestStats::invalidate_cache();
		wp_dequeue_style( StatsShortcode::STYLE_HANDLE );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function clear_log(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM `' . RequestLog::table() . '`' ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Crea un post de prueba y opcionalmente le adjunta el MP3 válido.
	 *
	 * @param array<string, mixed> $args       Argumentos de wp_insert_post.
	 * @param bool                 $with_audio Si debe asociar el MP3.
	 */
	private function make_episode( array $args = array(), bool $with_audio = true ): int {
		$id = self::factory()->post->create(
			$args + array(
				'post_title'  => 'Episodio Bloque',
				'post_status' => 'publish',
				'post_type'   => AudioMeta::POST_TYPE,
			)
		);
		if ( $with_audio ) {
			update_post_meta( $id, AudioMeta::META_KEY, $this->mp3_id );
		}
		return $id;
	}

	/**
	 * Inserta una fila de solicitud de audio en el log.
	 *
	 * @param int                  $post_id   ID de entrada.
	 * @param array<string, mixed> $overrides Campos a sobrescribir.
	 */
	private function log_row( int $post_id, array $overrides = array() ): void {
		global $wpdb;
		$when = isset( $overrides['when'] ) && is_string( $overrides['when'] ) ? $overrides['when'] : 'now';
		$wpdb->insert( // phpcs:ignore WordPress.DB
			RequestLog::table(),
			array(
				'requested_at'  => gmdate( 'Y-m-d H:i:s', strtotime( $when ) ),
				'post_id'       => $post_id,
				'attachment_id' => $this->mp3_id,
				'user_id'       => 0,
				'method'        => 'GET',
				'remote_addr'   => $overrides['remote_addr'] ?? '198.51.100.25',
				'request_url'   => 'https://example.org/?lb_sp_audio=1&secret=abc',
				'headers'       => $overrides['headers'] ?? '{"User-Agent":"Spotify/8.9","Cookie":"sid=xyz"}',
				'status'        => 200,
			)
		);
	}

	public function test_block_and_assets_are_registered(): void {
		$registry = WP_Block_Type_Registry::get_instance();
		$this->assertTrue( $registry->is_registered( StatsBlock::BLOCK_NAME ) );

		$block = $registry->get_registered( StatsBlock::BLOCK_NAME );
		$this->assertNotNull( $block );
		$this->assertContains( StatsBlock::SCRIPT_HANDLE, $block->editor_script_handles );
		$this->assertContains( StatsShortcode::STYLE_HANDLE, $block->style_handles );
		$this->assertTrue( wp_script_is( StatsBlock::SCRIPT_HANDLE, 'registered' ) );
		$this->assertTrue( wp_style_is( StatsShortcode::STYLE_HANDLE, 'registered' ) );
	}

	public function test_block_renders_summary_and_optional_sections_via_do_blocks(): void {
		$ep = $this->make_episode( array( 'post_title' => 'Episodio Top' ) );
		$this->log_row( $ep, array( 'remote_addr' => '198.51.100.1' ) );
		$this->log_row( $ep, array( 'remote_addr' => '198.51.100.2' ) );

		$markup = '<!-- wp:lb-simple-podcast/podcast-stats {"days":7,"showTopEpisodes":true,"showClients":true,"limit":3,"title":"Métricas","align":"wide","className":"mi-clase"} /-->';
		$html   = do_blocks( $markup );

		$this->assertStringContainsString( 'class="lb-sp-stats alignwide mi-clase"', $html );
		$this->assertStringContainsString( '>Métricas</h2>', $html );
		$this->assertStringContainsString( 'Últimos 7 días', $html );
		$this->assertStringContainsString( 'Escuchas estimadas', $html );
		$this->assertStringContainsString( 'Episodio Top', $html );
		$this->assertStringContainsString( 'Spotify', $html );

		// Sin PII ni secretos en la salida.
		$this->assertStringNotContainsString( '198.51.100.1', $html );
		$this->assertStringNotContainsString( 'sid=xyz', $html );
		$this->assertStringNotContainsString( 'secret=abc', $html );
	}

	public function test_block_fails_closed_for_private_draft_or_password_protected_post_id(): void {
		$draft_ep    = $this->make_episode( array( 'post_status' => 'draft' ) );
		$private_ep  = $this->make_episode( array( 'post_status' => 'private' ) );
		$password_ep = $this->make_episode( array( 'post_password' => 'secreto' ) );

		$this->log_row( $draft_ep );
		$this->log_row( $private_ep );
		$this->log_row( $password_ep );

		$this->assertSame( '', do_blocks( '<!-- wp:lb-simple-podcast/podcast-stats {"scope":"episode","postId":' . $draft_ep . '} /-->' ) );
		$this->assertSame( '', do_blocks( '<!-- wp:lb-simple-podcast/podcast-stats {"scope":"episode","postId":' . $private_ep . '} /-->' ) );
		$this->assertSame( '', do_blocks( '<!-- wp:lb-simple-podcast/podcast-stats {"scope":"episode","postId":' . $password_ep . '} /-->' ) );
		$this->assertSame( '', do_blocks( '<!-- wp:lb-simple-podcast/podcast-stats {"scope":"episode","postId":0} /-->' ) );
	}

	public function test_rest_block_renderer_enforces_permissions_and_hides_private_episodes(): void {
		$public_ep  = $this->make_episode( array( 'post_title' => 'Ep Público REST' ) );
		$private_ep = $this->make_episode(
			array(
				'post_title'  => 'Ep Privado REST',
				'post_status' => 'private',
			)
		);
		$this->log_row( $public_ep );
		$this->log_row( $private_ep );

		// Anónimo no puede usar el endpoint de renderizado del editor.
		$anon_req = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/' . StatsBlock::BLOCK_NAME );
		$anon_req->set_param( 'context', 'edit' );
		$anon_res = rest_get_server()->dispatch( $anon_req );
		$this->assertSame( 401, $anon_res->get_status() );

		// Un colaborador autenticado tampoco puede consultar un episodio privado vía attributes[postId].
		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );
		wp_set_current_user( $contributor );

		$priv_req = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/' . StatsBlock::BLOCK_NAME );
		$priv_req->set_param( 'context', 'edit' );
		$priv_req->set_param(
			'attributes',
			array(
				'scope'  => 'episode',
				'postId' => $private_ep,
			)
		);
		$priv_res = rest_get_server()->dispatch( $priv_req );
		$this->assertSame( 200, $priv_res->get_status() );
		$this->assertSame( '', $priv_res->get_data()['rendered'] );

		// Para un episodio público, sí devuelve el HTML público seguro.
		$pub_req = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/' . StatsBlock::BLOCK_NAME );
		$pub_req->set_param( 'context', 'edit' );
		$pub_req->set_param(
			'attributes',
			array(
				'scope'  => 'episode',
				'postId' => $public_ep,
			)
		);
		$pub_res = rest_get_server()->dispatch( $pub_req );
		$this->assertSame( 200, $pub_res->get_status() );
		$this->assertStringContainsString( 'Escuchas estimadas', $pub_res->get_data()['rendered'] );
	}

	public function test_block_current_scope_resolves_published_episode_in_loop(): void {
		$ep = $this->make_episode(
			array(
				'post_title'   => 'Ep Loop Bloque',
				'post_content' => '<!-- wp:lb-simple-podcast/podcast-stats {"scope":"current"} /-->',
			)
		);
		$this->log_row( $ep );

		$this->go_to( get_permalink( $ep ) );
		$this->assertTrue( have_posts() );
		the_post();
		ob_start();
		the_content();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'class="lb-sp-stats"', $html );
		$this->assertStringContainsString( '<strong class="lb-sp-stats__value">1</strong>', $html );
		$this->assertStringNotContainsString( 'Episodios publicados', $html );
	}
}
