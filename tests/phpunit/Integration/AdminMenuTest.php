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
	}

	public function tear_down(): void {
		$this->clear_log();
		unset( $_GET['paged'] );
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
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB
			RequestLog::table(),
			array(
				'requested_at'  => gmdate( 'Y-m-d H:i:s', strtotime( $when ) ),
				'post_id'       => $post_id,
				'attachment_id' => 123,
				'user_id'       => 0,
				'method'        => 'GET',
				'remote_addr'   => '127.0.0.1',
				'request_url'   => 'http://example.org/?x=<b>',
				'headers'       => '{}',
				'status'        => $status,
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

	public function test_logs_page_without_rows(): void {
		$html = $this->output( array( new LogsPage(), 'render' ) );
		$this->assertStringContainsString( 'Sin solicitudes registradas', $html );
	}
}
