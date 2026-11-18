<?php
/**
 * Tests de integración y seguridad para el shortcode público `[lb_podcast_stats]`.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Tests\Integration;

use LbSimplePodcast\Frontend\StatsShortcode;
use LbSimplePodcast\Meta\AudioMeta;
use LbSimplePodcast\Tracking\RequestLog;
use LbSimplePodcast\Tracking\RequestStats;
use WP_UnitTestCase;

/**
 * Renderizado, filtrado y garantías de seguridad/privacidad de `[lb_podcast_stats]`.
 */
final class StatsShortcodeTest extends WP_UnitTestCase {

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
		unset( $_GET['days'], $_GET['post_id'], $_GET['show'], $_GET['limit'] );
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
				'post_title'  => 'Episodio público',
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
				'user_id'       => $overrides['user_id'] ?? 42,
				'method'        => $overrides['method'] ?? 'GET',
				'remote_addr'   => $overrides['remote_addr'] ?? '198.51.100.42',
				'request_url'   => $overrides['request_url'] ?? 'https://example.org/?lb_sp_audio=1&token=secret_token',
				'headers'       => $overrides['headers'] ?? '{"User-Agent":"AppleCoreMedia/1.0","Cookie":"session=secret123","Authorization":"Bearer topsecret"}',
				'status'        => $overrides['status'] ?? 200,
			)
		);
	}

	public function test_shortcode_is_registered(): void {
		$this->assertTrue( shortcode_exists( StatsShortcode::TAG ) );
	}

	public function test_default_renders_summary_kpis_without_pii_or_internal_details(): void {
		$ep = $this->make_episode( array( 'post_title' => 'Ep Uno' ) );

		// Dos oyentes válidos (uno con múltiples rangos 206).
		$this->log_row(
			$ep,
			array(
				'remote_addr' => '198.51.100.42',
				'status'      => 200,
			)
		);
		$this->log_row(
			$ep,
			array(
				'remote_addr' => '198.51.100.42',
				'status'      => 206,
			)
		);
		$this->log_row(
			$ep,
			array(
				'remote_addr' => '203.0.113.77',
				'status'      => 200,
			)
		);

		// Ruido y errores que no deben contarse ni filtrarse.
		$this->log_row(
			$ep,
			array(
				'method'      => 'HEAD',
				'remote_addr' => '192.0.2.99',
				'status'      => 200,
			)
		);
		$this->log_row(
			$ep,
			array(
				'headers'     => '{"User-Agent":"Googlebot/2.1"}',
				'remote_addr' => '192.0.2.88',
				'status'      => 200,
			)
		);
		$this->log_row(
			$ep,
			array(
				'remote_addr' => '192.0.2.77',
				'status'      => 416,
			)
		);

		$html = do_shortcode( '[' . StatsShortcode::TAG . ']' );

		$this->assertStringContainsString( 'class="lb-sp-stats"', $html );
		$this->assertStringContainsString( 'Escuchas estimadas', $html );
		$this->assertStringContainsString( 'Oyentes únicos', $html );
		$this->assertStringContainsString( 'Episodios publicados', $html );
		$this->assertStringContainsString( 'Últimos 30 días', $html );
		$this->assertStringContainsString( '<strong class="lb-sp-stats__value">2</strong>', $html );
		$this->assertStringContainsString( '<strong class="lb-sp-stats__value">1</strong>', $html );

		// Por defecto no muestra top_episodes ni clients.
		$this->assertStringNotContainsString( 'Episodios más escuchados', $html );
		$this->assertStringNotContainsString( 'Aplicaciones y reproductores', $html );

		// Nunca expone PII, cabeceras, tokens, rutas internas, enlaces de edición ni métricas de ruido/error.
		$this->assertStringNotContainsString( '198.51.100.42', $html );
		$this->assertStringNotContainsString( '203.0.113.77', $html );
		$this->assertStringNotContainsString( 'secret123', $html );
		$this->assertStringNotContainsString( 'topsecret', $html );
		$this->assertStringNotContainsString( 'secret_token', $html );
		$this->assertStringNotContainsString( 'AppleCoreMedia', $html );
		$this->assertStringNotContainsString( 'Googlebot', $html );
		$this->assertStringNotContainsString( '416', $html );
		$this->assertStringNotContainsString( 'wp-admin', $html );
		$this->assertStringNotContainsString( 'action=edit', $html );
	}

	public function test_optional_top_episodes_and_clients_sections_respect_limit_and_period(): void {
		$ep1 = $this->make_episode( array( 'post_title' => 'Top Uno' ) );
		$ep2 = $this->make_episode( array( 'post_title' => 'Top Dos' ) );
		$ep3 = $this->make_episode( array( 'post_title' => 'Top Tres' ) );

		$this->log_row(
			$ep1,
			array(
				'remote_addr' => '198.51.100.1',
				'headers'     => '{"User-Agent":"AppleCoreMedia/1.0"}',
			)
		);
		$this->log_row(
			$ep1,
			array(
				'remote_addr' => '198.51.100.2',
				'headers'     => '{"User-Agent":"Spotify/8.9"}',
			)
		);
		$this->log_row(
			$ep1,
			array(
				'remote_addr' => '198.51.100.3',
				'headers'     => '{"User-Agent":"AppleCoreMedia/1.0"}',
			)
		);
		$this->log_row(
			$ep2,
			array(
				'remote_addr' => '198.51.100.4',
				'headers'     => '{"User-Agent":"Spotify/8.9"}',
			)
		);
		$this->log_row(
			$ep2,
			array(
				'remote_addr' => '198.51.100.5',
				'headers'     => '{"User-Agent":"Spotify/8.9"}',
			)
		);
		$this->log_row(
			$ep3,
			array(
				'remote_addr' => '198.51.100.6',
				'headers'     => '{"User-Agent":"PocketCasts/1.0"}',
			)
		);

		// Escucha fuera de los 7 días.
		$this->log_row(
			$ep3,
			array(
				'when'        => '-20 days',
				'remote_addr' => '198.51.100.7',
			)
		);

		$html = do_shortcode( '[' . StatsShortcode::TAG . ' days="7" show="summary,top_episodes,clients" limit="2"]' );

		$this->assertStringContainsString( 'Últimos 7 días', $html );
		$this->assertStringContainsString( 'Episodios más escuchados', $html );
		$this->assertStringContainsString( 'Top Uno', $html );
		$this->assertStringContainsString( esc_url( (string) get_permalink( $ep1 ) ), $html );
		$this->assertStringContainsString( 'Top Dos', $html );
		$this->assertStringNotContainsString( 'Top Tres', $html );
		$this->assertStringContainsString( 'Aplicaciones y reproductores', $html );
		$this->assertStringContainsString( 'Apple Podcasts', $html );
		$this->assertStringContainsString( 'Spotify', $html );
		$this->assertStringNotContainsString( 'action=edit', $html );
	}

	public function test_private_draft_and_password_protected_episodes_are_never_leaked(): void {
		// Incluso si el usuario actual es administrador, el shortcode público no debe filtrar privados ni borradores.
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$public_ep   = $this->make_episode( array( 'post_title' => 'Episodio Visible' ) );
		$draft_ep    = $this->make_episode(
			array(
				'post_title'  => 'Borrador Secreto',
				'post_status' => 'draft',
			)
		);
		$private_ep  = $this->make_episode(
			array(
				'post_title'  => 'Privado Secreto',
				'post_status' => 'private',
			)
		);
		$password_ep = $this->make_episode(
			array(
				'post_title'    => 'Con Clave Secreto',
				'post_password' => 'clave123',
			)
		);
		$no_audio_ep = $this->make_episode( array( 'post_title' => 'Post Sin Audio' ), false );

		$this->log_row( $public_ep, array( 'remote_addr' => '198.51.100.1' ) );
		$this->log_row( $draft_ep, array( 'remote_addr' => '198.51.100.2' ) );
		$this->log_row( $private_ep, array( 'remote_addr' => '198.51.100.3' ) );
		$this->log_row( $password_ep, array( 'remote_addr' => '198.51.100.4' ) );
		$this->log_row( $no_audio_ep, array( 'remote_addr' => '198.51.100.5' ) );

		$html = do_shortcode( '[' . StatsShortcode::TAG . ' show="summary,top_episodes"]' );

		$this->assertStringContainsString( 'Episodio Visible', $html );
		$this->assertStringNotContainsString( 'Borrador Secreto', $html );
		$this->assertStringNotContainsString( 'Privado Secreto', $html );
		$this->assertStringNotContainsString( 'Con Clave Secreto', $html );
		$this->assertStringNotContainsString( 'Post Sin Audio', $html );

		// El contador de episodios publicados solo incluye al público sin contraseña y con audio.
		$this->assertStringContainsString( '<span class="lb-sp-stats__label">Episodios publicados</span>', $html );
		$this->assertStringContainsString( '<strong class="lb-sp-stats__value">1</strong>', $html );

		// Si el episodio público pasa a borrador después de calentarse la caché, debe desaparecer de inmediato sin esperar TTL.
		wp_update_post(
			array(
				'ID'          => $public_ep,
				'post_status' => 'draft',
			)
		);
		$html_after = do_shortcode( '[' . StatsShortcode::TAG . ' show="top_episodes"]' );
		$this->assertStringNotContainsString( 'Episodio Visible', $html_after );
		$this->assertStringContainsString( 'Sin reproducciones registradas en este período.', $html_after );
	}

	public function test_post_id_attribute_fails_closed_for_non_public_or_invalid_posts(): void {
		$public_ep   = $this->make_episode( array( 'post_title' => 'Ep Público' ) );
		$other_ep    = $this->make_episode( array( 'post_title' => 'Otro Ep' ) );
		$draft_ep    = $this->make_episode(
			array(
				'post_title'  => 'Ep Draft',
				'post_status' => 'draft',
			)
		);
		$private_ep  = $this->make_episode(
			array(
				'post_title'  => 'Ep Privado',
				'post_status' => 'private',
			)
		);
		$password_ep = $this->make_episode(
			array(
				'post_title'    => 'Ep Password',
				'post_password' => 'pw',
			)
		);
		$no_audio    = $this->make_episode( array( 'post_title' => 'Sin MP3' ), false );
		$page_id     = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $page_id, AudioMeta::META_KEY, $this->mp3_id );

		$this->log_row( $public_ep, array( 'remote_addr' => '198.51.100.1' ) );
		$this->log_row( $other_ep, array( 'remote_addr' => '198.51.100.2' ) );
		$this->log_row( $other_ep, array( 'remote_addr' => '198.51.100.3' ) );
		$this->log_row( $draft_ep, array( 'remote_addr' => '198.51.100.4' ) );
		$this->log_row( $private_ep, array( 'remote_addr' => '198.51.100.5' ) );
		$this->log_row( $password_ep, array( 'remote_addr' => '198.51.100.6' ) );

		// Cualquier post no público o inválido falla cerrado devolviendo cadena vacía.
		$this->assertSame( '', do_shortcode( '[' . StatsShortcode::TAG . ' post_id="' . $draft_ep . '"]' ) );
		$this->assertSame( '', do_shortcode( '[' . StatsShortcode::TAG . ' post_id="' . $private_ep . '"]' ) );
		$this->assertSame( '', do_shortcode( '[' . StatsShortcode::TAG . ' post_id="' . $password_ep . '"]' ) );
		$this->assertSame( '', do_shortcode( '[' . StatsShortcode::TAG . ' post_id="' . $no_audio . '"]' ) );
		$this->assertSame( '', do_shortcode( '[' . StatsShortcode::TAG . ' post_id="' . $page_id . '"]' ) );
		$this->assertSame( '', do_shortcode( '[' . StatsShortcode::TAG . ' post_id="999999"]' ) );
		$this->assertSame( '', do_shortcode( '[' . StatsShortcode::TAG . ' post_id="abc"]' ) );
		$this->assertFalse( wp_style_is( StatsShortcode::STYLE_HANDLE, 'enqueued' ) );

		// Un episodio público válido muestra solo sus propias estadísticas y oculta KPIs globales.
		$single_html = do_shortcode( '[' . StatsShortcode::TAG . ' post_id="' . $public_ep . '"]' );
		$this->assertStringContainsString( '<strong class="lb-sp-stats__value">1</strong>', $single_html );
		$this->assertStringNotContainsString( 'Episodios publicados', $single_html );
		$this->assertStringNotContainsString( 'Episodios más escuchados', $single_html );
	}

	public function test_post_id_current_resolves_current_public_episode_only(): void {
		$ep = $this->make_episode(
			array(
				'post_title'   => 'Ep Actual',
				'post_content' => '[' . StatsShortcode::TAG . ' post_id="current"]',
			)
		);
		$this->log_row( $ep, array( 'remote_addr' => '198.51.100.10' ) );

		$this->go_to( get_permalink( $ep ) );
		$this->assertTrue( have_posts() );
		the_post();
		ob_start();
		the_content();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'class="lb-sp-stats"', $html );
		$this->assertStringContainsString( '<strong class="lb-sp-stats__value">1</strong>', $html );

		// En una entrada sin audio, `post_id="current"` falla cerrado.
		$plain = $this->make_episode(
			array(
				'post_title'   => 'Sin audio',
				'post_content' => '[' . StatsShortcode::TAG . ' post_id="current"]',
			),
			false
		);
		$this->go_to( get_permalink( $plain ) );
		$this->assertTrue( have_posts() );
		the_post();
		ob_start();
		the_content();
		$plain_html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'lb-sp-stats', $plain_html );
	}

	public function test_url_query_params_cannot_override_shortcode_attributes(): void {
		$ep1 = $this->make_episode( array( 'post_title' => 'Ep Query 1' ) );
		$ep2 = $this->make_episode( array( 'post_title' => 'Ep Query 2' ) );
		$this->log_row( $ep1, array( 'remote_addr' => '198.51.100.1' ) );
		$this->log_row(
			$ep2,
			array(
				'remote_addr' => '198.51.100.2',
				'when'        => '-20 days',
			)
		);

		$_GET['days']    = '7';
		$_GET['post_id'] = (string) $ep1;
		$_GET['show']    = 'top_episodes';

		$html = do_shortcode( '[' . StatsShortcode::TAG . ' days="30"]' );

		$this->assertStringContainsString( 'Últimos 30 días', $html );
		$this->assertStringContainsString( '<strong class="lb-sp-stats__value">2</strong>', $html );
		$this->assertStringNotContainsString( 'Episodios más escuchados', $html );
	}

	public function test_xss_in_attributes_and_titles_is_escaped(): void {
		$ep = $this->make_episode( array( 'post_title' => 'Ep "Especial" & <script>alert(3)</script>' ) );
		$this->log_row( $ep );

		$html = do_shortcode( '[' . StatsShortcode::TAG . ' show="summary,top_episodes" title=\'Stats "Podcast" & <script>alert(1)</script>\' class=\'custom" onclick="alert(2)\']' );

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( 'alert(1)', $html );
		$this->assertStringNotContainsString( 'onclick="alert(2)"', $html );
		$this->assertStringContainsString( 'Stats &quot;Podcast&quot; &amp;', $html );
		$this->assertStringContainsString( 'Ep &#8220;Especial&#8221; &amp;', $html );
		$this->assertStringContainsString( 'class="lb-sp-stats custom onclickalert2"', $html );
	}

	public function test_invalid_show_attribute_fails_closed(): void {
		$ep = $this->make_episode();
		$this->log_row( $ep );

		$this->assertSame( '', do_shortcode( '[' . StatsShortcode::TAG . ' show="raw_ips,headers"]' ) );
		$this->assertSame( '', do_shortcode( '[' . StatsShortcode::TAG . ' post_id="' . $ep . '" show="episodes,top_episodes"]' ) );
		$this->assertFalse( wp_style_is( StatsShortcode::STYLE_HANDLE, 'enqueued' ) );
	}

	public function test_context_guards_skip_feed_excerpt_and_password_protected_host_post(): void {
		$ep = $this->make_episode();
		$this->log_row( $ep );

		// En el feed RSS no se renderiza.
		$this->go_to( get_feed_link( 'podcast' ) );
		$this->assertSame( '', do_shortcode( '[' . StatsShortcode::TAG . ']' ) );

		// En un post protegido con contraseña sin desbloquear tampoco se renderiza.
		$locked_page = self::factory()->post->create(
			array(
				'post_type'     => 'page',
				'post_status'   => 'publish',
				'post_password' => 'secreto',
				'post_content'  => '[' . StatsShortcode::TAG . ']',
			)
		);
		$this->go_to( get_permalink( $locked_page ) );
		$this->assertTrue( have_posts() );
		the_post();
		$this->assertSame( '', do_shortcode( '[' . StatsShortcode::TAG . ']' ) );

		// Durante get_the_excerpt tampoco se renderiza.
		$open_page = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => 'Texto',
			)
		);
		$this->go_to( get_permalink( $open_page ) );
		$this->assertTrue( have_posts() );
		the_post();
		add_filter(
			'get_the_excerpt',
			static function (): string {
				return do_shortcode( '[' . StatsShortcode::TAG . ']' );
			}
		);
		$this->assertSame( '', apply_filters( 'get_the_excerpt', '', get_post( $open_page ) ) );
	}

	public function test_style_enqueue_filters_and_template_override(): void {
		$ep = $this->make_episode();
		$this->log_row( $ep );

		// Cuando se desactiva por filtro, no imprime nada ni encola CSS.
		add_filter( 'lb_sp_stats_enabled', '__return_false' );
		$this->assertSame( '', do_shortcode( '[' . StatsShortcode::TAG . ']' ) );
		$this->assertFalse( wp_style_is( StatsShortcode::STYLE_HANDLE, 'enqueued' ) );
		remove_filter( 'lb_sp_stats_enabled', '__return_false' );

		// Permite desactivar solo el CSS.
		add_filter( 'lb_sp_stats_enqueue_styles', '__return_false' );
		$html = do_shortcode( '[' . StatsShortcode::TAG . ']' );
		$this->assertStringContainsString( 'lb-sp-stats', $html );
		$this->assertFalse( wp_style_is( StatsShortcode::STYLE_HANDLE, 'enqueued' ) );
		remove_filter( 'lb_sp_stats_enqueue_styles', '__return_false' );

		// Override de plantilla en el tema.
		$base       = trailingslashit( get_temp_dir() ) . 'lb-sp-stats-theme-' . wp_generate_password( 8, false );
		$dir_filter = static function () use ( $base ): string {
			return $base;
		};
		add_filter( 'stylesheet_directory', $dir_filter );
		wp_set_template_globals();

		$theme_dir = $base . '/' . StatsShortcode::THEME_SUBFOLDER;
		wp_mkdir_p( $theme_dir );
		$file = $theme_dir . '/' . StatsShortcode::TEMPLATE;
		file_put_contents( $file, '<div class="tema-stats"><?php echo esc_html( (string) $stats["estimated_listens"] ); ?></div>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		try {
			$override_html = do_shortcode( '[' . StatsShortcode::TAG . ']' );
		} finally {
			remove_filter( 'stylesheet_directory', $dir_filter );
			wp_set_template_globals();
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			rmdir( $theme_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			rmdir( $base ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}

		$this->assertSame( '<div class="tema-stats">1</div>', $override_html );
		$this->assertTrue( wp_style_is( StatsShortcode::STYLE_HANDLE, 'enqueued' ) );
	}
}
