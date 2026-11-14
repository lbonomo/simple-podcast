<?php
/**
 * Test de uninstall.php.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Tests\Integration;

use LbSimplePodcast\Meta\AudioMeta;
use LbSimplePodcast\Tracking\RequestLog;
use WP_UnitTestCase;

/**
 * La desinstalación borra opciones pero no el contenido del usuario.
 */
final class UninstallTest extends WP_UnitTestCase {

	public function test_uninstall_removes_options_but_keeps_audio_and_meta(): void {
		global $wpdb;
		( new AudioMeta() )->register_meta();
		( new RequestLog() )->upgrade();
		$table = RequestLog::table();
		$this->assertTrue( ( new RequestLog() )->record( 1, 1, 200, array(), array() ) );
		$other_table = '';
		if ( is_multisite() ) {
			$site_id = self::factory()->blog->create();
			switch_to_blog( $site_id );
			remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
			try {
				( new RequestLog() )->upgrade();
			} finally {
				add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			}
			$other_table = RequestLog::table();
			$this->assertSame( $other_table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $other_table ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			restore_current_blog();
		}
		$mp3     = (int) self::factory()->attachment->create_upload_object( dirname( __DIR__ ) . '/fixtures/sample.mp3' );
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, AudioMeta::META_KEY, $mp3 );
		update_option( 'lb_sp_channel', array( 'title' => 'x' ) );
		update_option( 'lb_sp_feed_version', '1.0' );
		set_transient( 'lb_sp_feed_body', array( 'version' => '1.0' ) );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'lb-simple-podcast/lb-simple-podcast.php' );
		}
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		try {
			require LB_SP_DIR . 'uninstall.php';
		} finally {
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}

		$this->assertFalse( get_option( 'lb_sp_channel' ) );
		$this->assertFalse( get_option( 'lb_sp_feed_version' ) );
		$this->assertFalse( get_transient( 'lb_sp_feed_body' ) );
		$this->assertNotNull( get_post( $mp3 ), 'El audio de la biblioteca no se borra.' );
		$this->assertSame( $mp3, AudioMeta::get_attachment_id( $post_id ) );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( '' !== $other_table ) {
			$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $other_table ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}
}
