<?php
/**
 * Tests del menú, el Dashboard y los Logs.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Tests\Integration;

use LbSimplePodcast\Admin\DashboardPage;
use LbSimplePodcast\Admin\LogsPage;
use LbSimplePodcast\Admin\Menu;
use LbSimplePodcast\Admin\SettingsPage;
use LbSimplePodcast\Meta\AudioMeta;
use LbSimplePodcast\Tracking\RequestLog;
use LbSimplePodcast\Tracking\RequestStats;
use WP_UnitTestCase;

/**
 * Menú "Podcast", estadísticas y tabla de logs.
 */
final class AdminMenuTest extends WP_UnitTestCase {

	/**
	 * Attachment MP3 de prueba.
	 *
	 * @var int
	 */
	private int $mp3_id = 0;

	public function set_up(): void {
		parent::set_up();
		$this->mp3_id = (int) self::factory()->attachment->create_upload_object( dirname( __DIR__ ) . '/fixtures/sample.mp3' );
		( new RequestLog() )->upgrade();
		$this->clear_log();
		RequestStats::invalidate_cache();
	}

	public function tear_down(): void {
		$this->clear_log();
		RequestStats::invalidate_cache();
		unset( $_GET['paged'], $_GET['days'], $_GET['post_id'] );
		parent::tear_down();
	}

	private function clear_log(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM `' . RequestLog::table() . '`' ); // phpcs:ignore WordPress.DB
	}

	private function episode( string $title, string $date = 'now' ): int {
		$id = self::factory()->post->create(
			array(
				'post_title'  => $title,
				'post_status' => 'publish',
				'post_date'   => gmdate( 'Y-m-d H:i:s', strtotime( $date ) ),
			)
		);
		update_post_meta( $id, AudioMeta::META_KEY, $this->mp3_id );
		return $id;
	}

	private function log_row( int $post_id, int $status = 200, string $when = 'now' ): void {
		$this->log_row_custom(
			$post_id,
			array(
				'status' => $status,
				'when'   => $when,
			)
		);
	}

	/**
	 * Inserta una fila de registro personalizable.
	 *
	 * @param int                  $post_id   Entrada.
	 * @param array<string, mixed> $overrides Campos a sobrescribir.
	 */
	private function log_row_custom( int $post_id, array $overrides = array() ): void {
		global $wpdb;
		$when = isset( $overrides['when'] ) && is_string( $overrides['when'] ) ? $overrides['when'] : 'now';
		$wpdb->insert( // phpcs:ignore WordPress.DB
			RequestLog::table(),
			array(
				'requested_at'  => gmdate( 'Y-m-d H:i:s', strtotime( $when ) ),
				'post_id'       => $post_id,
				'attachment_id' => 123,
				'user_id'       => 0,
				'method'        => $overrides['method'] ?? 'GET',
				'remote_addr'   => $overrides['remote_addr'] ?? '127.0.0.1',
				'request_url'   => 'http://example.org/?x=<b>',
				'headers'       => $overrides['headers'] ?? '{}',
				'status'        => $overrides['status'] ?? 200,
			)
		);
	}

	private function output( callable $render ): string {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		$render();
		return (string) ob_get_clean();
	}

	public function test_menu_has_three_submenus_and_no_options_page(): void {
		global $menu, $submenu;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		( new Menu() )->add_menus();

		$slugs = wp_list_pluck( $submenu[ Menu::PARENT_SLUG ], 2 );
		$this->assertSame( array( Menu::PARENT_SLUG, SettingsPage::PAGE_SLUG, Menu::LOGS_SLUG ), array_values( $slugs ) );
		$this->assertContains( Menu::PARENT_SLUG, wp_list_pluck( $menu, 2 ) );
		$options = isset( $submenu['options-general.php'] ) ? wp_list_pluck( $submenu['options-general.php'], 2 ) : array();
		$this->assertNotContains( SettingsPage::PAGE_SLUG, $options );
	}

	public function test_row_meta_adds_setup_link_before_update_check(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$file = plugin_basename( LB_SP_FILE );

		$links = apply_filters( 'plugin_row_meta', array( 'Versión 1' ), $file, array(), '' );
		$other = apply_filters( 'plugin_row_meta', array( 'Versión 1' ), 'otro/otro.php', array(), '' );

		$this->assertCount( 2, $links );
		$this->assertStringContainsString( 'page=' . SettingsPage::PAGE_SLUG, $links[1] );
		$this->assertCount( 1, $other );
	}

	public function test_counts_published_audios_and_latest(): void {
		$this->episode( 'Viejo', '-5 days' );
		$latest = $this->episode( 'Nuevo', '-1 day' );
		self::factory()->post->create( array( 'post_status' => 'publish' ) ); // Sin audio.
		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		update_post_meta( $draft, AudioMeta::META_KEY, $this->mp3_id );

		$stats = new RequestStats();
		$this->assertSame( 2, $stats->count_published_audios() );
		$this->assertSame( $latest, $stats->latest_audio()->ID );
	}

	public function test_empty_state(): void {
		$stats = new RequestStats();
		$this->assertSame( 0, $stats->count_published_audios() );
		$this->assertNull( $stats->latest_audio() );
		$html = $this->output( array( new DashboardPage(), 'render' ) );
		$this->assertStringContainsString( 'Sin audios', $html );
		$this->assertStringContainsString( 'Sin solicitudes', $html );
	}

	public function test_requests_respect_30_day_window_and_status(): void {
		$post = $this->episode( 'A' );
		$this->log_row( $post, 200, '-29 days' );
		$this->log_row( $post, 206, '-1 day' );
		$this->log_row( $post, 200, '-31 days' );
		$this->log_row( $post, 404, '-1 day' );
		$this->log_row( $post, 302, '-1 day' );

		$this->assertSame( 2, ( new RequestStats() )->requests_last_days( 30 ) );
	}

	public function test_top_episodes_are_ordered_and_limited(): void {
		$ids = array();
		foreach ( range( 1, 6 ) as $n ) {
			$ids[ $n ] = $this->episode( 'Ep ' . $n );
			foreach ( range( 1, $n ) as $unused ) {
				$this->log_row( $ids[ $n ] );
			}
		}
		$this->log_row( $ids[1], 200, '-40 days' );

		$top = ( new RequestStats() )->top_episodes( 30, 5 );

		$this->assertCount( 5, $top );
		$this->assertSame( $ids[6], $top[0]['post_id'] );
		$this->assertSame( 6, $top[0]['total'] );
		$this->assertSame( $ids[2], $top[4]['post_id'] );
		$this->assertSame( 2, $top[4]['total'] );
	}

	public function test_dashboard_marks_deleted_episode(): void {
		$post = $this->episode( 'Borrado' );
		$this->log_row( $post );
		wp_delete_post( $post, true );

		$html = $this->output( array( new DashboardPage(), 'render' ) );
		$this->assertStringContainsString( '(eliminado)', $html );
	}

	public function test_logs_are_paginated_in_descending_order(): void {
		$post = $this->episode( 'A' );
		foreach ( range( 1, 120 ) as $unused ) {
			$this->log_row( $post );
		}
		$stats = new RequestStats();

		$this->assertSame( 120, $stats->count_all() );
		$first = $stats->get_page( 1, 50 );
		$this->assertCount( 50, $first );
		$this->assertGreaterThan( $first[1]['id'], $first[0]['id'] );
		$this->assertCount( 50, $stats->get_page( 2, 50 ) );
		$this->assertCount( 20, $stats->get_page( 3, 50 ) );
		$this->assertSame( array(), $stats->get_page( 4, 50 ) );
	}

	public function test_logs_page_escapes_output_and_clamps_page(): void {
		$post = $this->episode( 'A' );
		$this->log_row( $post );
		$_GET['paged'] = '99';

		$html = $this->output( array( new LogsPage(), 'render' ) );

		$this->assertStringContainsString( '127.0.0.1', $html );
		$this->assertStringContainsString( '&lt;b&gt;', $html );
		$this->assertStringNotContainsString( '?x=<b>', $html );
	}

	public function test_logs_page_has_expandable_details_with_headers(): void {
		global $wpdb;
		$post = $this->episode( 'A' );
		$this->log_row( $post );
		$wpdb->update( RequestLog::table(), array( 'headers' => '{"USER-AGENT":"Agente <i>"}' ), array( 'post_id' => $post ) ); // phpcs:ignore WordPress.DB

		$html = $this->output( array( new LogsPage(), 'render' ) );

		$this->assertStringContainsString( 'lb-sp-log-toggle', $html );
		$this->assertStringContainsString( 'lb-sp-log-details', $html );
		$this->assertStringContainsString( 'USER-AGENT', $html );
		$this->assertStringContainsString( 'Agente &lt;i&gt;', $html );
	}

	/**
	 * Roles sin acceso al menú Podcast.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function non_admin_roles(): array {
		return array(
			'editor'      => array( 'editor' ),
			'author'      => array( 'author' ),
			'contributor' => array( 'contributor' ),
			'subscriber'  => array( 'subscriber' ),
			'anonymous'   => array( '' ),
		);
	}

	/**
	 * @dataProvider non_admin_roles
	 * @param string $role Rol ('' = sin sesión).
	 */
	public function test_menu_pages_reject_non_admins_with_403( string $role ): void {
		wp_set_current_user( '' === $role ? 0 : self::factory()->user->create( array( 'role' => $role ) ) );
		$this->log_row( $this->episode( 'A' ) );

		foreach ( array( new DashboardPage(), new LogsPage(), new SettingsPage() ) as $page ) {
			$status = null;
			add_filter(
				'wp_die_handler',
				static function () use ( &$status ) {
					return static function ( $message, $title, $args ) use ( &$status ) {
						$status = is_array( $args ) ? ( $args['response'] ?? null ) : null;
						throw new \RuntimeException( 'wp_die' );
					};
				}
			);
			ob_start();
			try {
				$page->render();
				$this->fail( 'La página debió terminar con wp_die().' );
			} catch ( \RuntimeException $e ) {
				$this->assertSame( 'wp_die', $e->getMessage() );
			} finally {
				ob_end_clean();
				remove_all_filters( 'wp_die_handler' );
			}
			$this->assertSame( 403, $status, get_class( $page ) );
		}
	}

	public function test_menu_entries_require_the_admin_capability(): void {
		global $menu, $submenu;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		( new Menu() )->add_menus();

		$this->assertSame( 'manage_options', Menu::CAPABILITY );
		$this->assertSame( Menu::CAPABILITY, SettingsPage::CAPABILITY );
		foreach ( $menu as $item ) {
			if ( Menu::PARENT_SLUG === $item[2] ) {
				$this->assertSame( Menu::CAPABILITY, $item[1] );
			}
		}
		foreach ( $submenu[ Menu::PARENT_SLUG ] as $item ) {
			$this->assertSame( Menu::CAPABILITY, $item[1] );
		}
	}

	public function test_settings_save_capability_is_admin_only(): void {
		$this->assertSame( 'manage_options', apply_filters( 'option_page_capability_' . SettingsPage::GROUP, 'edit_posts' ) );
	}

	public function test_logs_page_without_rows(): void {
		$html = $this->output( array( new LogsPage(), 'render' ) );
		$this->assertStringContainsString( 'Sin solicitudes registradas', $html );
	}

	public function test_dashboard_data_excludes_head_and_bots_and_deduplicates_listens(): void {
		$ep1 = $this->episode( 'Ep Uno', '-2 days' );
		$ep2 = $this->episode( 'Ep Dos', '-1 day' );

		// Mismo oyente (IP + UA + día + episodio): 1 GET 200 + 3 GET 206 => 1 escucha estimada, 4 GET.
		$this->log_row_custom(
			$ep1,
			array(
				'remote_addr' => '192.0.2.10',
				'headers'     => '{"User-Agent":"Mozilla/5.0 Chrome/120.0.0.0"}',
				'status'      => 200,
			)
		);
		foreach ( range( 1, 3 ) as $unused ) {
			$this->log_row_custom(
				$ep1,
				array(
					'remote_addr' => '192.0.2.10',
					'headers'     => '{"USER-AGENT":"Mozilla/5.0 Chrome/120.0.0.0"}',
					'status'      => 206,
				)
			);
		}

		// Segundo oyente en otro episodio.
		$this->log_row_custom(
			$ep2,
			array(
				'remote_addr' => '198.51.100.20',
				'headers'     => '{"USER-AGENT":"AppleCoreMedia/1.0.0"}',
				'status'      => 200,
			)
		);

		// Ruido y errores que deben apartarse de escuchas estimadas y GET válidos.
		$this->log_row_custom(
			$ep1,
			array(
				'method'      => 'HEAD',
				'remote_addr' => '192.0.2.30',
				'status'      => 200,
			)
		);
		$this->log_row_custom(
			$ep1,
			array(
				'method'      => 'GET',
				'remote_addr' => '192.0.2.40',
				'headers'     => '{"USER-AGENT":"Mozilla/5.0 (compatible; Googlebot/2.1)"}',
				'status'      => 200,
			)
		);
		$this->log_row_custom(
			$ep1,
			array(
				'method'      => 'GET',
				'remote_addr' => '192.0.2.41',
				'headers'     => '{"USER-AGENT":"curl/8.5.0"}',
				'status'      => 200,
			)
		);
		$this->log_row_custom(
			$ep1,
			array(
				'method'      => 'GET',
				'remote_addr' => '192.0.2.50',
				'status'      => 416,
			)
		);
		$this->log_row_custom(
			$ep1,
			array(
				'method'      => 'GET',
				'remote_addr' => '192.0.2.51',
				'status'      => 302,
			)
		);

		// Ventana anterior (-40 días).
		$this->log_row_custom(
			$ep1,
			array(
				'when'        => '-40 days',
				'remote_addr' => '203.0.113.5',
				'status'      => 200,
			)
		);

		$data    = ( new RequestStats() )->dashboard_data( 30, 0 );
		$summary = $data['summary'];

		$this->assertSame( 2, $summary['estimated_listens'] );
		$this->assertSame( 5, $summary['get_requests'] );
		$this->assertSame( 2, $summary['unique_listeners'] );
		$this->assertSame( 1, $summary['head_requests'] );
		$this->assertSame( 2, $summary['bot_requests'] );
		$this->assertSame( 2, $summary['error_requests'] );
		$this->assertSame( 1, $summary['previous']['estimated_listens'] );
		$this->assertCount( 30, $data['daily'] );
		$this->assertCount( 2, $data['published'] );
	}

	public function test_dashboard_data_caches_for_one_hour_and_invalidates(): void {
		$ep    = $this->episode( 'Cache Ep' );
		$stats = new RequestStats();

		$this->log_row_custom( $ep, array( 'remote_addr' => '192.0.2.1' ) );
		$first = $stats->dashboard_data( 7, 0 );
		$this->assertSame( 1, $first['summary']['estimated_listens'] );

		// Inserta nueva fila sin invalidar caché: debe devolver el dato cacheado.
		$this->log_row_custom( $ep, array( 'remote_addr' => '192.0.2.2' ) );
		$cached = $stats->dashboard_data( 7, 0 );
		$this->assertSame( 1, $cached['summary']['estimated_listens'] );

		// Al invalidar la caché debe recalcular.
		RequestStats::invalidate_cache();
		$refreshed = $stats->dashboard_data( 7, 0 );
		$this->assertSame( 2, $refreshed['summary']['estimated_listens'] );
	}

	public function test_client_classification_recognizes_podcast_apps_browsers_and_bots(): void {
		$this->assertSame( 'apple_podcasts', RequestStats::classify_client( 'AppleCoreMedia/1.0.0.21A344' )['key'] );
		$this->assertSame( 'spotify', RequestStats::classify_client( 'Spotify/8.8.0 iOS' )['key'] );
		$this->assertSame( 'pocket_casts', RequestStats::classify_client( 'PocketCasts/1.0' )['key'] );
		$this->assertSame( 'browser_chrome', RequestStats::classify_client( 'Mozilla/5.0 AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36' )['key'] );
		$this->assertSame( 'browser_safari', RequestStats::classify_client( 'Mozilla/5.0 Version/17.0 Safari/605.1.15' )['key'] );
		$this->assertSame( 'bot', RequestStats::classify_client( 'curl/8.4.0' )['key'] );
		$this->assertSame( 'unknown', RequestStats::classify_client( '' )['key'] );
		$this->assertFalse( RequestStats::is_bot_user_agent( '' ) );
	}

	public function test_dashboard_page_renders_filters_and_enqueues_assets(): void {
		$ep1 = $this->episode( 'Episodio Filtrado' );
		$ep2 = $this->episode( 'Otro Episodio' );
		$this->log_row_custom(
			$ep1,
			array(
				'remote_addr' => '198.51.100.1',
				'headers'     => '{"USER-AGENT":"Spotify/8.9"}',
			)
		);
		$this->log_row_custom(
			$ep2,
			array(
				'remote_addr' => '198.51.100.2',
				'headers'     => '{"USER-AGENT":"PocketCasts/1.0"}',
			)
		);

		$_GET['days']    = '7';
		$_GET['post_id'] = (string) $ep1;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$page = new DashboardPage();
		$page->enqueue( 'toplevel_page_' . Menu::PARENT_SLUG );
		$this->assertTrue( wp_script_is( 'lb-sp-dashboard', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'lb-sp-dashboard', 'enqueued' ) );

		$html = $this->output( array( $page, 'render' ) );
		$this->assertStringContainsString( 'Últimos 7 días', $html );
		$this->assertStringContainsString( 'Episodio Filtrado', $html );
		$this->assertStringContainsString( 'Spotify', $html );
		$this->assertStringNotContainsString( 'Pocket Casts', $html );
		$this->assertStringContainsString( 'lb-sp-trend-chart', $html );
		$this->assertStringContainsString( 'lb-sp-clients-chart', $html );
	}
}
