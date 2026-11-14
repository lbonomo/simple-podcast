<?php
/**
 * Tests de la inyección automática del reproductor.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Tests\Integration;

use LbSimplePodcast\Frontend\PlayerInjector;
use LbSimplePodcast\Meta\AudioMeta;
use LbSimplePodcast\Media\AudioDelivery;
use WP_UnitTestCase;

/**
 * Reproductor en vistas singulares, feed, archivos y override de plantilla.
 */
final class PlayerInjectorTest extends WP_UnitTestCase {

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
		wp_dequeue_style( PlayerInjector::STYLE_HANDLE );
	}

	public function tear_down(): void {
		wp_dequeue_style( PlayerInjector::STYLE_HANDLE );
		parent::tear_down();
	}

	private function post_with_audio( array $args = array() ): int {
		$post_id = self::factory()->post->create(
			$args + array(
				'post_title'   => 'Ep 1',
				'post_content' => 'Cuerpo del post.',
			)
		);
		update_post_meta( $post_id, AudioMeta::META_KEY, $this->mp3_id );
		return $post_id;
	}

	/**
	 * Visita el post y devuelve `the_content` dentro del loop principal.
	 *
	 * @param int $post_id ID del post.
	 */
	private function content_in_loop( int $post_id ): string {
		$this->go_to( get_permalink( $post_id ) );
		$this->assertTrue( have_posts() );
		the_post();
		ob_start();
		the_content();
		return (string) ob_get_clean();
	}

	public function test_player_is_injected_before_the_content(): void {
		$post_id = $this->post_with_audio();
		$html    = $this->content_in_loop( $post_id );

		$this->assertStringContainsString( 'class="lb-sp-player"', $html );
		$this->assertStringContainsString( 'class="lb-sp-player__audio"', $html );
		$this->assertStringContainsString( 'src="' . esc_url( AudioDelivery::url( $post_id, $this->mp3_id ) ) . '"', $html );
		$this->assertLessThan( strpos( $html, 'Cuerpo del post.' ), strpos( $html, 'lb-sp-player' ) );
	}

	public function test_post_without_audio_is_unchanged(): void {
		$html = $this->content_in_loop( self::factory()->post->create( array( 'post_content' => 'Solo texto.' ) ) );

		$this->assertStringNotContainsString( 'lb-sp-player', $html );
		$this->assertStringContainsString( 'Solo texto.', $html );
	}

	public function test_archive_and_home_are_unchanged(): void {
		$this->post_with_audio();
		$this->go_to( '/' );
		$this->assertTrue( have_posts() );
		the_post();

		$this->assertStringNotContainsString( 'lb-sp-player', apply_filters( 'the_content', 'Hola' ) );
	}

	public function test_feed_is_unchanged(): void {
		$post_id = $this->post_with_audio();
		$this->go_to( get_feed_link( 'podcast' ) );

		$this->assertStringNotContainsString( 'lb-sp-player', apply_filters( 'the_content', get_post( $post_id )->post_content ) );
	}

	public function test_content_outside_the_loop_is_unchanged(): void {
		$post_id = $this->post_with_audio();
		$this->go_to( get_permalink( $post_id ) );

		$this->assertStringNotContainsString( 'lb-sp-player', apply_filters( 'the_content', 'Fuera del loop' ) );
	}

	public function test_page_is_unchanged(): void {
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_post_meta( $page, AudioMeta::META_KEY, $this->mp3_id );

		$this->assertStringNotContainsString( 'lb-sp-player', $this->content_in_loop( $page ) );
	}

	public function test_password_protected_post_has_no_player(): void {
		$html = $this->content_in_loop( $this->post_with_audio( array( 'post_password' => 'secreto' ) ) );

		$this->assertStringNotContainsString( 'lb-sp-player', $html );
		$this->assertStringNotContainsString( wp_get_attachment_url( $this->mp3_id ), $html );
	}

	public function test_output_is_escaped(): void {
		$post_id = $this->post_with_audio( array( 'post_title' => '"><script>alert(1)</script>' ) );

		$html = $this->content_in_loop( $post_id );

		$this->assertStringNotContainsString( '<script>alert(1)', $html );
	}

	public function test_player_html_filter_can_change_markup(): void {
		add_filter(
			'lb_sp_player_html',
			static function (): string {
				return '<div class="custom-player"></div>';
			}
		);

		$html = $this->content_in_loop( $this->post_with_audio() );

		$this->assertStringContainsString( 'custom-player', $html );
		$this->assertStringNotContainsString( 'lb-sp-player', $html );
	}

	public function test_theme_template_override_is_used(): void {
		$base = trailingslashit( get_temp_dir() ) . 'lb-sp-theme-' . wp_generate_password( 8, false );
		add_filter(
			'stylesheet_directory',
			static function () use ( $base ): string {
				return $base;
			}
		);
		$theme_dir = $base . '/' . PlayerInjector::THEME_SUBFOLDER;
		wp_mkdir_p( $theme_dir );
		$file = $theme_dir . '/' . PlayerInjector::TEMPLATE;
		file_put_contents( $file, '<section class="tema-player"><?php echo esc_html( $post->post_title ); ?></section>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		try {
			$html = $this->content_in_loop( $this->post_with_audio() );
		} finally {
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			rmdir( $theme_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			rmdir( $base ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}

		$this->assertStringContainsString( '<section class="tema-player">Ep 1</section>', $html );
		$this->assertStringNotContainsString( 'lb-sp-player__audio', $html );
	}

	public function test_style_is_enqueued_only_when_the_player_is_rendered(): void {
		$this->content_in_loop( self::factory()->post->create() );
		$this->assertFalse( wp_style_is( PlayerInjector::STYLE_HANDLE, 'enqueued' ) );

		$this->content_in_loop( $this->post_with_audio() );
		$this->assertTrue( wp_style_is( PlayerInjector::STYLE_HANDLE, 'enqueued' ) );
	}

	public function test_style_can_be_disabled_with_filter(): void {
		add_filter( 'lb_sp_player_enqueue_styles', '__return_false' );

		$this->content_in_loop( $this->post_with_audio() );

		$this->assertFalse( wp_style_is( PlayerInjector::STYLE_HANDLE, 'enqueued' ) );
	}

	public function test_player_can_be_disabled_with_filter(): void {
		add_filter( 'lb_sp_player_enabled', '__return_false' );

		$html = $this->content_in_loop( $this->post_with_audio() );

		$this->assertStringNotContainsString( 'lb-sp-player', $html );
		$this->assertFalse( wp_style_is( PlayerInjector::STYLE_HANDLE, 'enqueued' ) );
	}

	public function test_player_can_be_disabled_for_a_single_post(): void {
		$off = $this->post_with_audio();
		$on  = $this->post_with_audio();
		add_filter(
			'lb_sp_player_enabled',
			static function ( $enabled, $post ) use ( $off ) {
				return $post->ID === $off ? false : $enabled;
			},
			10,
			2
		);

		$this->assertStringNotContainsString( 'lb-sp-player', $this->content_in_loop( $off ) );
		$this->assertStringContainsString( 'lb-sp-player', $this->content_in_loop( $on ) );
	}

	public function test_position_after(): void {
		add_filter(
			'lb_sp_player_position',
			static function (): string {
				return PlayerInjector::POSITION_AFTER;
			}
		);

		$html = $this->content_in_loop( $this->post_with_audio() );

		$this->assertGreaterThan( strpos( $html, 'Cuerpo del post.' ), strpos( $html, 'lb-sp-player' ) );
	}

	public function test_invalid_position_falls_back_to_before(): void {
		add_filter(
			'lb_sp_player_position',
			static function (): string {
				return 'cualquier-cosa';
			}
		);

		$html = $this->content_in_loop( $this->post_with_audio() );

		$this->assertLessThan( strpos( $html, 'Cuerpo del post.' ), strpos( $html, 'lb-sp-player' ) );
	}
}
