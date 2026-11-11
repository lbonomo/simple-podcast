<?php
/**
 * Test de uninstall.php.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Tests\Integration;

use LbSimplePodcast\Meta\AudioMeta;
use WP_UnitTestCase;

/**
 * La desinstalación borra opciones pero no el contenido del usuario.
 */
final class UninstallTest extends WP_UnitTestCase {

	public function test_uninstall_removes_options_but_keeps_audio_and_meta(): void {
		( new AudioMeta() )->register_meta();
		$mp3     = (int) self::factory()->attachment->create_upload_object( dirname( __DIR__ ) . '/fixtures/sample.mp3' );
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, AudioMeta::META_KEY, $mp3 );
		update_option( 'lb_sp_channel', array( 'title' => 'x' ) );
		update_option( 'lb_sp_feed_version', '1.0' );
		set_transient( 'lb_sp_feed_body', array( 'version' => '1.0' ) );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'lb-simple-podcast/lb-simple-podcast.php' );
		}
		require LB_SP_DIR . 'uninstall.php';

		$this->assertFalse( get_option( 'lb_sp_channel' ) );
		$this->assertFalse( get_option( 'lb_sp_feed_version' ) );
		$this->assertFalse( get_transient( 'lb_sp_feed_body' ) );
		$this->assertNotNull( get_post( $mp3 ), 'El audio de la biblioteca no se borra.' );
		$this->assertSame( $mp3, AudioMeta::get_attachment_id( $post_id ) );
	}
}
