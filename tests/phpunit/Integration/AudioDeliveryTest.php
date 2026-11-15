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
use LbSimplePodcast\Tracking\ErrorLog;
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

	public function test_delivery_url_uses_episode_permalink_with_static_front_page(): void {
		$front_page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front_page_id );

		$expected = add_query_arg(
			array(
				AudioDelivery::QUERY_POST       => $this->post_id,
				AudioDelivery::QUERY_ATTACHMENT => $this->attachment_id,
			),
			get_permalink( $this->post_id )
		);

		$this->assertSame( $expected, AudioDelivery::url( $this->post_id, $this->attachment_id ) );
	}

	/**
	 * Bug condition (enclosure-url-returns-html): la exclusión de caché debe declararse en `init`.
	 *
	 * Nota: las constantes no se pueden deshacer; si otra prueba ya definió DONOTCACHEPAGE, esta no detecta el bug.
	 */
	public function test_bug_condition_early_nocache_is_declared_on_init(): void {
		$this->assertFalse( defined( 'DONOTCACHEPAGE' ), 'Precondición: DONOTCACHEPAGE no debe estar definida antes de la petición.' );
		$_GET[ AudioDelivery::QUERY_POST ] = (string) $this->post_id;
		try {
			do_action( 'init' );
			$this->assertTrue( defined( 'DONOTCACHEPAGE' ), 'DONOTCACHEPAGE debe estar definida tras init (antes de template_redirect).' );
		} finally {
			unset( $_GET[ AudioDelivery::QUERY_POST ] );
		}
	}

	public function test_bug_condition_url_falls_back_to_index_php_without_permalink(): void {
		add_filter( 'post_link', '__return_false' );
		try {
			$this->assertFalse( get_permalink( $this->post_id ), 'Precondición: get_permalink() devuelve false.' );
			$url      = AudioDelivery::url( $this->post_id, $this->attachment_id );
			$expected = add_query_arg(
				array(
					AudioDelivery::QUERY_POST       => $this->post_id,
					AudioDelivery::QUERY_ATTACHMENT => $this->attachment_id,
				),
				home_url( '/index.php' )
			);
			$this->assertSame( $expected, $url );
			$this->assertStringStartsNotWith( home_url( '/?' ), $url );
			$this->assertStringStartsNotWith( trailingslashit( home_url( '/' ) ) . '?', $url );
		} finally {
			remove_filter( 'post_link', '__return_false' );
		}
	}

	public function test_bug_condition_get_returns_audio_and_valid_range_returns_206(): void {
		$delivery = new AudioDelivery();
		$size     = (int) filesize( dirname( __DIR__ ) . '/fixtures/sample.mp3' );

		$get = $delivery->response( $this->post_id, $this->attachment_id, array( 'REQUEST_METHOD' => 'GET' ) );
		$this->assertSame( 200, $get['status'] );
		$this->assertSame( 'audio/mpeg', $get['headers']['Content-Type'] );
		$this->assertSame( (string) $size, $get['headers']['Content-Length'] );

		$range = $delivery->response(
			$this->post_id,
			$this->attachment_id,
			array(
				'REQUEST_METHOD' => 'GET',
				'HTTP_RANGE'     => 'bytes=0-99',
			)
		);
		$this->assertSame( 206, $range['status'] );
		$this->assertSame( 'audio/mpeg', $range['headers']['Content-Type'] );
		$this->assertSame( 'bytes 0-99/' . $size, $range['headers']['Content-Range'] );
		$this->assertSame( '100', $range['headers']['Content-Length'] );
	}

	/**
	 * Preservation (enclosure-url-returns-html): combinaciones método/Range/If-Range observadas en el código sin corregir.
	 *
	 * Cada caso: método, Range, If-Range, estado, offset y longitud esperados (null = tamaño completo / size-N).
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: int, 4: callable, 5: callable}>
	 */
	public static function preserved_method_range_provider(): array {
		$full = static fn( int $size ): int => $size;
		$zero = static fn(): int => 0;
		return array(
			'GET sin Range'              => array( 'GET', '', '', 200, $zero, $full ),
			'HEAD sin Range'             => array( 'HEAD', '', '', 200, $zero, $full ),
			'HEAD con Range ignorado'    => array( 'HEAD', 'bytes=2-5', '', 200, $zero, $full ),
			'GET rango cerrado'          => array( 'GET', 'bytes=2-5', '', 206, static fn(): int => 2, static fn(): int => 4 ),
			'GET rango abierto'          => array( 'GET', 'bytes=0-', '', 206, $zero, $full ),
			'GET rango sufijo'           => array( 'GET', 'bytes=-3', '', 206, static fn( int $s ): int => $s - 3, static fn(): int => 3 ),
			'GET fin mayor que tamano'   => array( 'GET', 'bytes=0-99999999', '', 206, $zero, $full ),
			'GET inicio fuera de rango'  => array( 'GET', 'bytes=99999999-', '', 416, $zero, $zero ),
			'GET inicio mayor que fin'   => array( 'GET', 'bytes=10-5', '', 416, $zero, $zero ),
			'GET sufijo cero'            => array( 'GET', 'bytes=-0', '', 416, $zero, $zero ),
			'GET unidad invalida'        => array( 'GET', 'items=0-5', '', 200, $zero, $full ),
			'GET rango malformado'       => array( 'GET', 'bytes=a-b', '', 200, $zero, $full ),
			'GET rango vacio'            => array( 'GET', 'bytes=-', '', 200, $zero, $full ),
			'GET rango multiple'         => array( 'GET', 'bytes=0-1,3-4', '', 200, $zero, $full ),
			'GET con If-Range'           => array( 'GET', 'bytes=2-5', '"old"', 200, $zero, $full ),
			'GET con If-Range sin Range' => array( 'GET', '', '"old"', 200, $zero, $full ),
		);
	}

	/**
	 * @dataProvider preserved_method_range_provider
	 *
	 * @param string   $method   Método HTTP.
	 * @param string   $range    Cabecera Range.
	 * @param string   $if_range Cabecera If-Range.
	 * @param int      $status   Estado esperado.
	 * @param callable $offset   Calcula el offset esperado.
	 * @param callable $length   Calcula la longitud esperada.
	 */
	public function test_preserved_method_and_range_responses( string $method, string $range, string $if_range, int $status, callable $offset, callable $length ): void {
		$size   = (int) filesize( dirname( __DIR__ ) . '/fixtures/sample.mp3' );
		$server = array( 'REQUEST_METHOD' => $method );
		if ( '' !== $range ) {
			$server['HTTP_RANGE'] = $range;
		}
		if ( '' !== $if_range ) {
			$server['HTTP_IF_RANGE'] = $if_range;
		}

		$result = ( new AudioDelivery() )->response( $this->post_id, $this->attachment_id, $server );

		$this->assertSame( $status, $result['status'] );
		$this->assertSame( $offset( $size ), $result['offset'] );
		$this->assertSame( $length( $size ), $result['length'] );
		$this->assertSame( '', $result['redirect'] );
		if ( 416 === $status ) {
			$this->assertSame( '', $result['path'] );
			$this->assertSame( 'bytes */' . $size, $result['headers']['Content-Range'] );
			$this->assertSame( '0', $result['headers']['Content-Length'] );
			return;
		}
		$this->assertNotSame( '', $result['path'] );
		$this->assertSame( 'audio/mpeg', $result['headers']['Content-Type'] );
		$this->assertSame( 'bytes', $result['headers']['Accept-Ranges'] );
		$this->assertSame( (string) $length( $size ), $result['headers']['Content-Length'] );
		if ( 206 === $status ) {
			$start = $offset( $size );
			$this->assertSame( 'bytes ' . $start . '-' . ( $start + $length( $size ) - 1 ) . '/' . $size, $result['headers']['Content-Range'] );
		} else {
			$this->assertArrayNotHasKey( 'Content-Range', $result['headers'] );
		}
	}

	/** HEAD y GET sin Range comparten estado, tipo y longitud; HEAD no tiene rango ni offset. */
	public function test_preserved_head_matches_get_identity_headers(): void {
		$delivery = new AudioDelivery();
		$get      = $delivery->response( $this->post_id, $this->attachment_id, array( 'REQUEST_METHOD' => 'GET' ) );
		$head     = $delivery->response( $this->post_id, $this->attachment_id, array( 'REQUEST_METHOD' => 'HEAD' ) );

		$this->assertSame( $get['status'], $head['status'] );
		$this->assertSame( $get['headers'], $head['headers'] );
		$this->assertSame( 0, $head['offset'] );
	}

	/** @return array<string, array{0: string}> */
	public static function preserved_disallowed_method_provider(): array {
		return array(
			'POST'    => array( 'POST' ),
			'PUT'     => array( 'PUT' ),
			'DELETE'  => array( 'DELETE' ),
			'PATCH'   => array( 'PATCH' ),
			'OPTIONS' => array( 'OPTIONS' ),
		);
	}

	/**
	 * @dataProvider preserved_disallowed_method_provider
	 *
	 * @param string $method Método HTTP.
	 */
	public function test_preserved_disallowed_methods_return_405( string $method ): void {
		$result = ( new AudioDelivery() )->response( $this->post_id, $this->attachment_id, array( 'REQUEST_METHOD' => $method ) );

		$this->assertSame( 405, $result['status'] );
		$this->assertSame( array( 'Allow' => 'GET, HEAD' ), $result['headers'] );
		$this->assertSame( '', $result['path'] );
	}

	/** @return array<string, array{0: string}> */
	public static function preserved_invalid_ids_provider(): array {
		return array(
			'post cero'              => array( 'zero_post' ),
			'post inexistente'       => array( 'missing_post' ),
			'attachment cero'        => array( 'zero_attachment' ),
			'attachment negativo'    => array( 'negative_attachment' ),
			'attachment inexistente' => array( 'missing_attachment' ),
			'attachment ajeno'       => array( 'other_attachment' ),
			'post de otro tipo'      => array( 'page_post' ),
			'MIME no audio/mpeg'     => array( 'wrong_mime' ),
		);
	}

	/**
	 * @dataProvider preserved_invalid_ids_provider
	 *
	 * @param string $scenario Escenario.
	 */
	public function test_preserved_invalid_ids_or_mime_return_404( string $scenario ): void {
		$post_id       = $this->post_id;
		$attachment_id = $this->attachment_id;
		switch ( $scenario ) {
			case 'zero_post':
				$post_id = 0;
				break;
			case 'missing_post':
				$post_id = $this->post_id + 100000;
				break;
			case 'zero_attachment':
				$attachment_id = 0;
				break;
			case 'negative_attachment':
				$attachment_id = -1;
				break;
			case 'missing_attachment':
				$attachment_id = $this->attachment_id + 100000;
				break;
			case 'other_attachment':
				$attachment_id = (int) self::factory()->attachment->create_upload_object( dirname( __DIR__ ) . '/fixtures/sample.mp3' );
				break;
			case 'page_post':
				$post_id = self::factory()->post->create(
					array(
						'post_type'   => 'page',
						'post_status' => 'publish',
					)
				);
				update_post_meta( $post_id, AudioMeta::META_KEY, $attachment_id );
				break;
			case 'wrong_mime':
				wp_update_post(
					array(
						'ID'             => $this->attachment_id,
						'post_mime_type' => 'audio/ogg',
					)
				);
				break;
		}

		foreach ( array( 'GET', 'HEAD' ) as $method ) {
			$result = ( new AudioDelivery() )->response( $post_id, $attachment_id, array( 'REQUEST_METHOD' => $method ) );
			$this->assertSame( 404, $result['status'], $scenario . ' ' . $method );
			$this->assertSame( array(), $result['headers'] );
			$this->assertSame( '', $result['path'] );
		}
	}

	/** @return array<string, array{0: string}> */
	public static function preserved_non_public_provider(): array {
		return array(
			'privado'  => array( 'private' ),
			'borrador' => array( 'draft' ),
			'clave'    => array( 'password' ),
		);
	}

	/**
	 * @dataProvider preserved_non_public_provider
	 *
	 * @param string $scenario Escenario.
	 */
	public function test_preserved_non_public_posts_return_403( string $scenario ): void {
		$args = array( 'ID' => $this->post_id );
		if ( 'password' === $scenario ) {
			$args['post_password'] = 'secreto';
		} else {
			$args['post_status'] = $scenario;
		}
		wp_update_post( $args );
		wp_set_current_user( 0 );

		foreach ( array( 'GET', 'HEAD' ) as $method ) {
			$result = ( new AudioDelivery() )->response( $this->post_id, $this->attachment_id, array( 'REQUEST_METHOD' => $method ) );
			$this->assertSame( 403, $result['status'], $scenario . ' ' . $method );
			$this->assertSame( '', $result['path'] );
		}
	}

	/** Un audio no legible con attachment externo redirige con 302 tanto en GET como en HEAD. */
	public function test_preserved_unreadable_audio_redirects_for_get_and_head(): void {
		$original = get_post_meta( $this->attachment_id, '_wp_attached_file', true );
		update_post_meta( $this->attachment_id, '_wp_attached_file', 'unavailable.mp3' );
		$filter = static fn(): string => 'https://example.test/episode.mp3';
		add_filter( 'wp_get_attachment_url', $filter );
		try {
			foreach ( array( 'GET', 'HEAD' ) as $method ) {
				( new ErrorLog() )->clear();
				$result = ( new AudioDelivery() )->response( $this->post_id, $this->attachment_id, array( 'REQUEST_METHOD' => $method ) );
				$this->assertSame( 302, $result['status'], $method );
				$this->assertSame( 'https://example.test/episode.mp3', $result['redirect'] );
				$this->assertSame( '', $result['path'] );
				$this->assertSame( ErrorLog::SOURCE_DELIVERY, ( new ErrorLog() )->all()[0]['source'] );
			}
		} finally {
			remove_filter( 'wp_get_attachment_url', $filter );
			update_post_meta( $this->attachment_id, '_wp_attached_file', $original );
		}
	}

	/** Un HEAD válido se registra en RequestLog con método y estado. */
	public function test_preserved_head_request_is_recorded_in_request_log(): void {
		global $wpdb;
		$delivery = new AudioDelivery();
		$server   = array(
			'REQUEST_METHOD' => 'HEAD',
			'REMOTE_ADDR'    => '192.0.2.9',
			'HTTP_HOST'      => 'example.org',
			'REQUEST_URI'    => '/?lb_sp_audio=' . $this->post_id,
		);
		$response = $delivery->response( $this->post_id, $this->attachment_id, $server );

		$this->assertTrue( ( new RequestLog() )->record( $this->post_id, $this->attachment_id, $response['status'], $server, array( 'User-Agent' => 'test' ) ) );

		$table = RequestLog::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT method, status FROM `$table` WHERE post_id = %d", $this->post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( 'HEAD', $row['method'] );
		$this->assertSame( '200', $row['status'] );
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
			$entries = ( new ErrorLog() )->all();
			$this->assertCount( 1, $entries );
			$this->assertSame( ErrorLog::SOURCE_LOG_INSERT, $entries[0]['source'] );
			$this->assertSame( ErrorLog::LEVEL_ERROR, $entries[0]['level'] );
			$this->assertSame( $this->post_id, $entries[0]['post_id'] );
			$this->assertSame( $this->attachment_id, $entries[0]['attachment_id'] );
			$this->assertSame( 1, ( new ErrorLog() )->unread_error_count() );
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
			$this->assertSame( ErrorLog::SOURCE_DELIVERY, ( new ErrorLog() )->all()[0]['source'] );
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
