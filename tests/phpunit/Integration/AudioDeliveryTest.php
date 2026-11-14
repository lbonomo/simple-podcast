<?php
/**
 * Pruebas de entrega y registro de audio.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Tests\Integration;

use LbSimplePodcast\Media\AudioDelivery;
use LbSimplePodcast\Meta\AudioMeta;
use LbSimplePodcast\Tracking\RequestLog;
use WP_UnitTestCase;

/** Prueba la entrega y los registros. */
final class AudioDeliveryTest extends WP_UnitTestCase {

	/** @var int */
	private int $post_id;
	/** @var int */
	private int $attachment_id;

	public function set_up(): void {
		parent::set_up();
		( new RequestLog() )->upgrade();
		$this->attachment_id = (int) self::factory()->attachment->create_upload_object( dirname( __DIR__ ) . '/fixtures/sample.mp3' );
		$this->post_id       = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $this->post_id, AudioMeta::META_KEY, $this->attachment_id );
	}

	public function test_ranges_and_head(): void {
		$delivery = new AudioDelivery();
		$full     = $delivery->response( $this->post_id, $this->attachment_id, array( 'REQUEST_METHOD' => 'GET' ) );
		$range    = $delivery->response(
			$this->post_id,
			$this->attachment_id,
			array(
				'REQUEST_METHOD' => 'GET',
				'HTTP_RANGE'     => 'bytes=2-5',
			)
		);
		$this->assertSame( 206, $range['status'] );
		$this->assertSame( 2, $range['offset'] );
		$this->assertSame( 4, $range['length'] );
		$this->assertSame( 'bytes 2-5/' . $full['length'], $range['headers']['Content-Range'] );

		$suffix = $delivery->response(
			$this->post_id,
			$this->attachment_id,
			array(
				'REQUEST_METHOD' => 'GET',
				'HTTP_RANGE'     => 'bytes=-3',
			)
		);
		$this->assertSame( $full['length'] - 3, $suffix['offset'] );
		$this->assertSame( 3, $suffix['length'] );
		$invalid = $delivery->response(
			$this->post_id,
			$this->attachment_id,
			array(
				'REQUEST_METHOD' => 'GET',
				'HTTP_RANGE'     => 'bytes=' . $full['length'] . '-',
			)
		);
		$this->assertSame( 416, $invalid['status'] );
		$this->assertSame( 'bytes */' . $full['length'], $invalid['headers']['Content-Range'] );
		$head = $delivery->response(
			$this->post_id,
			$this->attachment_id,
			array(
				'REQUEST_METHOD' => 'HEAD',
				'HTTP_RANGE'     => 'bytes=2-5',
			)
		);
		$this->assertSame( 200, $head['status'] );
		$this->assertSame( $full['length'], $head['length'] );
		$if_range = $delivery->response(
			$this->post_id,
			$this->attachment_id,
			array(
				'REQUEST_METHOD' => 'GET',
				'HTTP_RANGE'     => 'bytes=2-5',
				'HTTP_IF_RANGE'  => '"old"',
			)
		);
		$this->assertSame( 200, $if_range['status'] );
	}

	public function test_invalid_association_and_private_posts_are_not_public(): void {
		$delivery = new AudioDelivery();
		$this->assertSame( 404, $delivery->response( $this->post_id, $this->attachment_id + 1, array() )['status'] );
		wp_update_post(
			array(
				'ID'          => $this->post_id,
				'post_status' => 'private',
			)
		);
		wp_set_current_user( 0 );
		$this->assertSame( 403, $delivery->response( $this->post_id, $this->attachment_id, array() )['status'] );
	}

	public function test_every_request_is_stored_with_full_headers_and_url(): void {
		global $wpdb;
		$log     = new RequestLog();
		$server  = array(
			'REQUEST_METHOD' => 'GET',
			'REMOTE_ADDR'    => '192.0.2.4',
			'HTTP_HOST'      => 'example.org',
			'REQUEST_URI'    => '/?token=secret',
		);
		$headers = array(
			'Authorization' => 'Bearer secret',
			'User-Agent'    => 'test',
		);
		$this->assertTrue( $log->record( $this->post_id, $this->attachment_id, 206, $server, $headers ) );
		$this->assertTrue( $log->record( $this->post_id, $this->attachment_id, 206, $server, $headers ) );
		$table = RequestLog::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `$table` WHERE post_id = %d ORDER BY id", $this->post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertCount( 2, $rows );
		$this->assertSame( '192.0.2.4', $rows[0]['remote_addr'] );
		$this->assertStringContainsString( '?token=secret', $rows[0]['request_url'] );
		$this->assertSame( $headers, json_decode( $rows[0]['headers'], true ) );
	}

	public function test_failed_insert_sets_an_admin_warning(): void {
		global $wpdb;
		$filter = static function ( string $query ): string {
			return str_starts_with( $query, 'INSERT INTO `' . RequestLog::table() . '`' ) ? 'INVALID SQL' : $query;
		};
		add_filter( 'query', $filter );
		$previous = $wpdb->suppress_errors( true );
		try {
			$this->assertFalse( ( new RequestLog() )->record( $this->post_id, $this->attachment_id, 200, array(), array() ) );
			$this->assertSame( '1', get_option( RequestLog::ERROR_OPTION ) );
		} finally {
			remove_filter( 'query', $filter );
			$wpdb->suppress_errors( $previous );
		}
	}

	public function test_schema_upgrade_preserves_existing_records(): void {
		global $wpdb;
		$log = new RequestLog();
		$this->assertTrue( $log->record( $this->post_id, $this->attachment_id, 200, array(), array() ) );
		delete_option( RequestLog::VERSION_OPTION );
		$log->upgrade();
		$this->assertSame( RequestLog::VERSION, get_option( RequestLog::VERSION_OPTION ) );
		$table = RequestLog::table();
		$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `$table` WHERE post_id = %d", $this->post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( '1', $count );
	}

	public function test_unavailable_local_file_redirects_to_attachment_url(): void {
		$original = get_post_meta( $this->attachment_id, '_wp_attached_file', true );
		update_post_meta( $this->attachment_id, '_wp_attached_file', 'unavailable.mp3' );
		$filter = static fn(): string => 'https://example.test/episode.mp3';
		add_filter( 'wp_get_attachment_url', $filter );
		try {
			$result = ( new AudioDelivery() )->response( $this->post_id, $this->attachment_id, array() );
			$this->assertSame( 302, $result['status'] );
			$this->assertSame( 'https://example.test/episode.mp3', $result['redirect'] );
			$this->assertSame( '1', get_option( AudioDelivery::WARNING_OPTION ) );
		} finally {
			remove_filter( 'wp_get_attachment_url', $filter );
			update_post_meta( $this->attachment_id, '_wp_attached_file', $original );
		}
	}

	public function test_configured_private_copy_does_not_replace_original(): void {
		$directory = get_temp_dir() . 'lb-sp-test-' . wp_generate_password( 12, false );
		wp_mkdir_p( $directory );
		$old_root                 = $_SERVER['DOCUMENT_ROOT'] ?? null;
		$_SERVER['DOCUMENT_ROOT'] = ABSPATH;
		$filter                   = static fn(): string => $directory;
		$copy                     = '';
		add_filter( 'lb_sp_private_audio_dir', $filter );
		try {
			$source = get_attached_file( $this->attachment_id );
			$result = ( new AudioDelivery() )->response( $this->post_id, $this->attachment_id, array() );
			$this->assertSame( 200, $result['status'] );
			$this->assertNotSame( $source, $result['path'] );
			$copy = $result['path'];
			$this->assertSame( hash_file( 'sha256', $source ), hash_file( 'sha256', $result['path'] ) );
			$this->assertFileExists( $source );
		} finally {
			if ( '' !== $copy && str_starts_with( $copy, $directory . '/' ) ) {
				wp_delete_file( $copy );
			}
			remove_filter( 'lb_sp_private_audio_dir', $filter );
			if ( null === $old_root ) {
				unset( $_SERVER['DOCUMENT_ROOT'] );
			} else {
				$_SERVER['DOCUMENT_ROOT'] = $old_root;
			}
			rmdir( $directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
	}
}
