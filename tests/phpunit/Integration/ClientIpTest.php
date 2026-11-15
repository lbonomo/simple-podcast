<?php
/**
 * Tests de la IP del cliente con proxies de confianza.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Tests\Integration;

use LbSimplePodcast\Meta\AudioMeta;
use LbSimplePodcast\Tracking\ClientIp;
use LbSimplePodcast\Tracking\RequestLog;
use WP_UnitTestCase;

/** Resolución, falsificación y anonimización de la IP. */
final class ClientIpTest extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'lb_sp_trusted_proxies' );
		remove_all_filters( 'lb_sp_proxy_header' );
		remove_all_filters( 'lb_sp_anonymize_ip' );
		remove_all_filters( 'lb_sp_client_ip' );
		parent::tear_down();
	}

	/**
	 * Fija los proxies de confianza.
	 *
	 * @param array<int, string>|string $ranges Rangos.
	 */
	private function trust( $ranges ): void {
		add_filter(
			'lb_sp_trusted_proxies',
			static function () use ( $ranges ) {
				return $ranges;
			}
		);
	}

	public function test_without_trusted_proxies_the_header_is_ignored(): void {
		$server = array(
			'REMOTE_ADDR'          => '198.51.100.7',
			'HTTP_X_FORWARDED_FOR' => '192.0.2.99',
		);
		$this->assertSame( '198.51.100.7', ClientIp::resolve( $server ) );
	}

	public function test_header_from_an_untrusted_connection_is_ignored(): void {
		$this->trust( array( '203.0.113.0/24' ) );
		$server = array(
			'REMOTE_ADDR'          => '198.51.100.7',
			'HTTP_X_FORWARDED_FOR' => '192.0.2.99',
		);
		$this->assertSame( '198.51.100.7', ClientIp::resolve( $server ), 'Un cliente cualquiera no puede falsificar su IP.' );
	}

	public function test_trusted_proxy_exposes_the_real_client(): void {
		$this->trust( array( '203.0.113.0/24' ) );
		$server = array(
			'REMOTE_ADDR'          => '203.0.113.10',
			'HTTP_X_FORWARDED_FOR' => '192.0.2.50',
		);
		$this->assertSame( '192.0.2.50', ClientIp::resolve( $server ) );
	}

	public function test_forged_left_values_are_skipped_reading_right_to_left(): void {
		$this->trust( array( '203.0.113.0/24', '198.51.100.0/24' ) );
		$server = array(
			'REMOTE_ADDR'          => '203.0.113.10',
			'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 192.0.2.50, 198.51.100.20',
		);
		$this->assertSame( '192.0.2.50', ClientIp::resolve( $server ), 'Se salta el proxy intermedio y no se usa el valor falsificado de la izquierda.' );
	}

	public function test_unreadable_header_value_falls_back_to_the_connection_ip(): void {
		$this->trust( array( '203.0.113.0/24' ) );
		$server = array(
			'REMOTE_ADDR'          => '203.0.113.10',
			'HTTP_X_FORWARDED_FOR' => 'no-es-una-ip',
		);
		$this->assertSame( '203.0.113.10', ClientIp::resolve( $server ) );
		$this->assertSame( '203.0.113.10', ClientIp::resolve( array( 'REMOTE_ADDR' => '203.0.113.10' ) ) );
	}

	public function test_ipv6_ranges_and_clients(): void {
		$this->trust( array( '2001:db8::/32' ) );
		$server = array(
			'REMOTE_ADDR'          => '2001:db8::1',
			'HTTP_X_FORWARDED_FOR' => '2001:4860::8888',
		);
		$this->assertSame( '2001:4860::8888', ClientIp::resolve( $server ) );
		$this->assertTrue( ClientIp::in_ranges( '2001:db8:ffff::1', array( '2001:db8::/32' ) ) );
		$this->assertFalse( ClientIp::in_ranges( '2001:db9::1', array( '2001:db8::/32' ) ) );
		$this->assertFalse( ClientIp::in_ranges( '192.0.2.1', array( '2001:db8::/32' ) ), 'No mezcla IPv4 con rangos IPv6.' );
	}

	public function test_cidr_boundaries_and_invalid_ranges(): void {
		$this->assertTrue( ClientIp::in_ranges( '10.0.0.255', array( '10.0.0.0/24' ) ) );
		$this->assertFalse( ClientIp::in_ranges( '10.0.1.0', array( '10.0.0.0/24' ) ) );
		$this->assertTrue( ClientIp::in_ranges( '10.0.0.129', array( '10.0.0.128/25' ) ) );
		$this->assertFalse( ClientIp::in_ranges( '10.0.0.127', array( '10.0.0.128/25' ) ) );
		$this->assertTrue( ClientIp::in_ranges( '192.0.2.1', array( '192.0.2.1' ) ) );

		$this->trust( 'basura, 10.0.0.0/99, 203.0.113.0/24 , 10.0.0.0/' );
		$this->assertSame( array( '203.0.113.0/24' ), ClientIp::trusted_proxies() );
	}

	public function test_custom_header_name(): void {
		$this->trust( array( '203.0.113.0/24' ) );
		add_filter(
			'lb_sp_proxy_header',
			static function (): string {
				return 'CF-Connecting-IP';
			}
		);
		$server = array(
			'REMOTE_ADDR'           => '203.0.113.10',
			'HTTP_CF_CONNECTING_IP' => '192.0.2.77',
			'HTTP_X_FORWARDED_FOR'  => '192.0.2.1',
		);
		$this->assertSame( '192.0.2.77', ClientIp::resolve( $server ) );
	}

	public function test_anonymize(): void {
		$this->assertSame( '192.0.2.0', ClientIp::anonymize( '192.0.2.123' ) );
		$this->assertSame( '2001:db8:abcd::', ClientIp::anonymize( '2001:db8:abcd:1234:5678::1' ) );
		$this->assertSame( '', ClientIp::anonymize( 'no-es-una-ip' ) );
	}

	public function test_log_stores_the_resolved_ip_and_optionally_anonymizes_it(): void {
		global $wpdb;
		( new RequestLog() )->upgrade();
		$attachment = (int) self::factory()->attachment->create_upload_object( dirname( __DIR__ ) . '/fixtures/sample.mp3' );
		$post       = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $post, AudioMeta::META_KEY, $attachment );
		$this->trust( array( '203.0.113.0/24' ) );
		$server = array(
			'REQUEST_METHOD'       => 'GET',
			'REMOTE_ADDR'          => '203.0.113.10',
			'HTTP_X_FORWARDED_FOR' => '192.0.2.50',
		);
		$log    = new RequestLog();
		$table  = RequestLog::table();

		$log->record( $post, $attachment, 200, $server, array() );
		add_filter( 'lb_sp_anonymize_ip', '__return_true' );
		$log->record( $post, $attachment, 200, $server, array() );

		$ips = $wpdb->get_col( $wpdb->prepare( "SELECT remote_addr FROM `$table` WHERE post_id = %d ORDER BY id", $post ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( array( '192.0.2.50', '192.0.2.0' ), $ips );
	}
}
