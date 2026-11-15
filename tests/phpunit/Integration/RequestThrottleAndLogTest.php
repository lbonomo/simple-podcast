<?php
/**
 * Tests del throttling, del registro de enlaces válidos y de la purga.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Tests\Integration;

use LbSimplePodcast\Media\AudioDelivery;
use LbSimplePodcast\Media\RequestThrottle;
use LbSimplePodcast\Meta\AudioMeta;
use LbSimplePodcast\Tracking\RequestLog;
use WP_UnitTestCase;

/** Límite por IP, topes de tamaño y retención del registro. */
final class RequestThrottleAndLogTest extends WP_UnitTestCase {

	/**
	 * Entrada con audio.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * Attachment MP3.
	 *
	 * @var int
	 */
	private int $attachment_id;

	public function set_up(): void {
		parent::set_up();
		( new RequestLog() )->upgrade();
		$this->attachment_id = (int) self::factory()->attachment->create_upload_object( dirname( __DIR__ ) . '/fixtures/sample.mp3' );
		$this->post_id       = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $this->post_id, AudioMeta::META_KEY, $this->attachment_id );
	}

	public function tear_down(): void {
		remove_all_filters( 'lb_sp_throttle_limit' );
		remove_all_filters( 'lb_sp_throttle_window' );
		remove_all_filters( 'lb_sp_log_retention_days' );
		remove_all_filters( 'lb_sp_client_ip' );
		parent::tear_down();
	}

	public function test_requests_over_the_limit_must_wait(): void {
		add_filter(
			'lb_sp_throttle_limit',
			static function (): int {
				return 3;
			}
		);
		$throttle = new RequestThrottle();

		$this->assertSame( 0, $throttle->hit( '192.0.2.10' ) );
		$this->assertSame( 0, $throttle->hit( '192.0.2.10' ) );
		$this->assertSame( 0, $throttle->hit( '192.0.2.10' ) );
		$wait = $throttle->hit( '192.0.2.10' );
		$this->assertGreaterThanOrEqual( 1, $wait );
		$this->assertLessThanOrEqual( RequestThrottle::DEFAULT_WINDOW, $wait );
		$this->assertSame( 0, $throttle->hit( '192.0.2.11' ), 'Otra IP no se ve afectada.' );
	}

	public function test_limit_zero_disables_and_unknown_ip_is_not_limited(): void {
		add_filter(
			'lb_sp_throttle_limit',
			static function (): int {
				return 0;
			}
		);
		$throttle = new RequestThrottle();
		foreach ( range( 1, 5 ) as $unused ) {
			$this->assertSame( 0, $throttle->hit( '192.0.2.20' ) );
		}

		remove_all_filters( 'lb_sp_throttle_limit' );
		add_filter(
			'lb_sp_throttle_limit',
			static function (): int {
				return 1;
			}
		);
		foreach ( range( 1, 3 ) as $unused ) {
			$this->assertSame( 0, $throttle->hit( '' ) );
		}
	}

	public function test_client_ip_uses_remote_addr_and_rejects_invalid_values(): void {
		$this->assertSame( '192.0.2.5', RequestThrottle::client_ip( array( 'REMOTE_ADDR' => '192.0.2.5' ) ) );
		$this->assertSame( '2001:db8::1', RequestThrottle::client_ip( array( 'REMOTE_ADDR' => '2001:db8::1' ) ) );
		$this->assertSame( '', RequestThrottle::client_ip( array( 'REMOTE_ADDR' => 'no-es-una-ip' ) ) );
		$this->assertSame( '', RequestThrottle::client_ip( array( 'HTTP_X_FORWARDED_FOR' => '192.0.2.9' ) ) );
	}

	public function test_only_valid_links_are_marked_for_logging(): void {
		$delivery = new AudioDelivery();

		$this->assertTrue( $delivery->response( $this->post_id, $this->attachment_id, array( 'REQUEST_METHOD' => 'GET' ) )['valid'] );
		$this->assertTrue( $delivery->response( $this->post_id, $this->attachment_id, array( 'REQUEST_METHOD' => 'HEAD' ) )['valid'] );
		$this->assertTrue( $delivery->response( $this->post_id, $this->attachment_id, array( 'REQUEST_METHOD' => 'GET', 'HTTP_RANGE' => 'bytes=999999999-' ) )['valid'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing

		$this->assertFalse( $delivery->response( $this->post_id + 999, $this->attachment_id, array( 'REQUEST_METHOD' => 'GET' ) )['valid'] );
		$this->assertFalse( $delivery->response( $this->post_id, $this->attachment_id + 1, array( 'REQUEST_METHOD' => 'GET' ) )['valid'] );
		$this->assertFalse( $delivery->response( $this->post_id, $this->attachment_id, array( 'REQUEST_METHOD' => 'POST' ) )['valid'] );
		$this->assertFalse( $delivery->response( 0, 0, array( 'REQUEST_METHOD' => 'GET' ) )['valid'] );

		wp_update_post(
			array(
				'ID'          => $this->post_id,
				'post_status' => 'draft',
			)
		);
		$this->assertFalse( $delivery->response( $this->post_id, $this->attachment_id, array( 'REQUEST_METHOD' => 'GET' ) )['valid'] );
	}

	public function test_stored_url_and_headers_are_size_capped(): void {
		global $wpdb;
		$server  = array(
			'REQUEST_METHOD' => 'GET',
			'REMOTE_ADDR'    => '192.0.2.4',
			'HTTP_HOST'      => 'example.org',
			'REQUEST_URI'    => '/?x=' . str_repeat( 'a', 10000 ),
		);
		$headers = array( 'User-Agent' => str_repeat( 'b', 5000 ) );
		foreach ( range( 1, 200 ) as $n ) {
			$headers[ 'X-Extra-' . $n ] = str_repeat( 'c', 100 );
		}

		$this->assertTrue( ( new RequestLog() )->record( $this->post_id, $this->attachment_id, 200, $server, $headers ) );

		$table = RequestLog::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT request_url, headers FROM `$table` WHERE post_id = %d", $this->post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertLessThanOrEqual( RequestLog::MAX_URL_BYTES, strlen( $row['request_url'] ) );
		$this->assertLessThanOrEqual( RequestLog::MAX_HEADERS_BYTES, strlen( $row['headers'] ) );
		$stored = json_decode( $row['headers'], true );
		$this->assertIsArray( $stored );
		$this->assertSame( RequestLog::MAX_HEADER_VALUE_BYTES, strlen( $stored['User-Agent'] ) );
	}

	public function test_purge_deletes_only_rows_older_than_retention(): void {
		global $wpdb;
		$table = RequestLog::table();
		$wpdb->query( "DELETE FROM `$table`" ); // phpcs:ignore WordPress.DB
		foreach ( array( '-100 days', '-91 days', '-89 days', 'now' ) as $when ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB
				$table,
				array(
					'requested_at'  => gmdate( 'Y-m-d H:i:s', strtotime( $when ) ),
					'post_id'       => $this->post_id,
					'attachment_id' => $this->attachment_id,
					'user_id'       => 0,
					'method'        => 'GET',
					'remote_addr'   => '192.0.2.1',
					'request_url'   => 'http://example.org/',
					'headers'       => '{}',
					'status'        => 200,
				)
			);
		}

		$this->assertSame( 2, ( new RequestLog() )->purge() );
		$this->assertSame( 2, (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table`" ) ); // phpcs:ignore WordPress.DB
	}

	public function test_purge_with_zero_days_keeps_everything(): void {
		global $wpdb;
		$table = RequestLog::table();
		$wpdb->insert( // phpcs:ignore WordPress.DB
			$table,
			array(
				'requested_at'  => '2000-01-01 00:00:00',
				'post_id'       => 1,
				'attachment_id' => 1,
				'user_id'       => 0,
				'method'        => 'GET',
				'remote_addr'   => '192.0.2.1',
				'request_url'   => 'http://example.org/',
				'headers'       => '{}',
				'status'        => 200,
			)
		);
		add_filter(
			'lb_sp_log_retention_days',
			static function (): int {
				return 0;
			}
		);

		$this->assertSame( 0, ( new RequestLog() )->purge() );
	}

	public function test_daily_purge_is_scheduled(): void {
		wp_clear_scheduled_hook( RequestLog::CRON_HOOK );
		( new RequestLog() )->schedule_purge();
		$this->assertNotFalse( wp_next_scheduled( RequestLog::CRON_HOOK ) );
		$this->assertSame( 'daily', wp_get_schedule( RequestLog::CRON_HOOK ) );
	}
}
