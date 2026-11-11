<?php
/**
 * Tests de AudioIngestor.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Tests\Integration;

use LbSimplePodcast\Media\AudioIngestor;
use LbSimplePodcast\Meta\AudioMeta;
use WP_UnitTestCase;

/**
 * Ingesta por URL y base64, SSRF, límites y reemplazo.
 */
final class AudioIngestorTest extends WP_UnitTestCase {

	/**
	 * URLs solicitadas por el stub HTTP.
	 *
	 * @var array<int, string>
	 */
	private array $requests = array();

	/**
	 * Ingestor bajo prueba.
	 *
	 * @var AudioIngestor
	 */
	private AudioIngestor $ingestor;

	public function set_up(): void {
		parent::set_up();
		( new AudioMeta() )->register_meta();
		$this->ingestor = new AudioIngestor();
		$this->requests = array();
	}

	private function fixture(): string {
		return dirname( __DIR__ ) . '/fixtures/sample.mp3';
	}

	/**
	 * Simula la respuesta HTTP copiando un archivo al destino del stream.
	 *
	 * @param string $source Archivo a servir.
	 * @param int    $code   Código HTTP.
	 */
	private function serve_http( string $source, int $code = 200 ): void {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $source, $code ) {
				$this->requests[] = $url;
				copy( $source, $args['filename'] );
				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array(
						'code'    => $code,
						'message' => 'x',
					),
					'cookies'  => array(),
					'filename' => $args['filename'],
				);
			},
			10,
			3
		);
	}

	private function mp3_base64(): string {
		return base64_encode( (string) file_get_contents( $this->fixture() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions, WordPress.WP.AlternativeFunctions
	}

	private function attachment_count(): int {
		return (int) ( new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
			)
		) )->found_posts;
	}

	public function test_ingest_from_url_attaches_the_mp3(): void {
		$post_id = self::factory()->post->create();
		$this->serve_http( $this->fixture() );

		$result = $this->ingestor->ingest_from_url( $post_id, 'https://example.org/audio/episodio-1.mp3', 'Mi episodio' );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( AudioMeta::get_attachment_id( $post_id ), $result['attachment_id'] );
		$this->assertSame( 'audio/mpeg', $result['mime_type'] );
		$this->assertSame( $post_id, (int) wp_get_post_parent_id( $result['attachment_id'] ) );
		$this->assertSame( 'Mi episodio', get_the_title( $result['attachment_id'] ) );
		$this->assertStringEndsWith( '.mp3', (string) get_attached_file( $result['attachment_id'] ) );
		$this->assertGreaterThanOrEqual( 1, $result['duration'] );
		$this->assertSame( array( 'https://example.org/audio/episodio-1.mp3' ), $this->requests );
	}

	public function test_url_without_mp3_extension_still_works_when_content_is_mp3(): void {
		$post_id = self::factory()->post->create();
		$this->serve_http( $this->fixture() );

		$result = $this->ingestor->ingest_from_url( $post_id, 'https://example.org/download?id=7' );

		$this->assertIsArray( $result );
		$this->assertStringEndsWith( '.mp3', (string) get_attached_file( $result['attachment_id'] ) );
	}

	/**
	 * @dataProvider unsafe_urls
	 *
	 * @param string $url URL no permitida.
	 */
	public function test_unsafe_urls_are_rejected_without_any_request( string $url ): void {
		$post_id = self::factory()->post->create();
		$this->serve_http( $this->fixture() );

		$result = $this->ingestor->ingest_from_url( $post_id, $url );

		$this->assertWPError( $result );
		$this->assertSame( 'lb_sp_invalid_url', $result->get_error_code() );
		$this->assertSame( array(), $this->requests, 'No debe hacerse ninguna petición HTTP.' );
		$this->assertSame( 0, AudioMeta::get_attachment_id( $post_id ) );
	}

	/**
	 * URLs peligrosas.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function unsafe_urls(): array {
		return array(
			'loopback ipv4'    => array( 'http://127.0.0.1/a.mp3' ),
			'localhost'        => array( 'http://localhost/a.mp3' ),
			'red privada 10'   => array( 'http://10.0.0.5/a.mp3' ),
			'red privada 192'  => array( 'http://192.168.1.10/a.mp3' ),
			'red privada 172'  => array( 'http://172.16.0.1/a.mp3' ),
			'metadata cloud'   => array( 'http://169.254.169.254/latest/meta-data/' ),
			'loopback ipv6'    => array( 'http://[::1]/a.mp3' ),
			'esquema ftp'      => array( 'ftp://example.org/a.mp3' ),
			'esquema file'     => array( 'file:///etc/passwd' ),
			'javascript'       => array( 'javascript:alert(1)' ),
			'con credenciales' => array( 'http://user:pass@example.org/a.mp3' ),
			'vacía'            => array( '' ),
			'sin host'         => array( 'http:///a.mp3' ),
		);
	}

	public function test_allowlisted_host_bypasses_the_private_network_check(): void {
		$post_id = self::factory()->post->create();
		$this->serve_http( $this->fixture() );
		add_filter(
			'lb_sp_audio_url_allowlist',
			static fn(): array => array( 'localhost' )
		);

		$result = $this->ingestor->ingest_from_url( $post_id, 'http://localhost/a.mp3' );

		$this->assertIsArray( $result );
	}

	public function test_http_error_is_reported(): void {
		$post_id = self::factory()->post->create();
		$this->serve_http( $this->fixture(), 404 );

		$result = $this->ingestor->ingest_from_url( $post_id, 'https://example.org/a.mp3' );

		$this->assertWPError( $result );
		$this->assertSame( 'lb_sp_download_failed', $result->get_error_code() );
	}

	public function test_download_larger_than_the_limit_is_rejected(): void {
		$post_id = self::factory()->post->create();
		$this->serve_http( $this->fixture() );
		add_filter( 'lb_sp_max_audio_bytes', static fn(): int => 1000 );
		$before = $this->attachment_count();

		$result = $this->ingestor->ingest_from_url( $post_id, 'https://example.org/a.mp3' );

		$this->assertWPError( $result );
		$this->assertSame( 'lb_sp_file_too_large', $result->get_error_code() );
		$this->assertSame( $before, $this->attachment_count() );
	}

	public function test_ingest_from_base64_attaches_the_mp3(): void {
		$post_id = self::factory()->post->create();

		$result = $this->ingestor->ingest_from_base64( $post_id, 'data:audio/mpeg;base64,' . $this->mp3_base64(), 'Mi Episodio.mp3', 'Título' );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( AudioMeta::get_attachment_id( $post_id ), $result['attachment_id'] );
		$this->assertSame( 'Título', get_the_title( $result['attachment_id'] ) );
		$this->assertStringContainsStringIgnoringCase( 'mi-episodio', (string) get_attached_file( $result['attachment_id'] ) );
	}

	public function test_base64_with_line_breaks_is_accepted(): void {
		$post_id = self::factory()->post->create();

		$result = $this->ingestor->ingest_from_base64( $post_id, chunk_split( $this->mp3_base64(), 76, "\n" ) );

		$this->assertIsArray( $result );
	}

	public function test_invalid_base64_is_rejected(): void {
		$post_id = self::factory()->post->create();

		$result = $this->ingestor->ingest_from_base64( $post_id, '¡¡esto no es base64!!' );

		$this->assertWPError( $result );
		$this->assertSame( 'lb_sp_invalid_base64', $result->get_error_code() );
	}

	public function test_empty_base64_is_rejected(): void {
		$result = $this->ingestor->ingest_from_base64( self::factory()->post->create(), '   ' );

		$this->assertWPError( $result );
		$this->assertSame( 'lb_sp_invalid_base64', $result->get_error_code() );
	}

	public function test_oversized_base64_is_rejected_before_decoding(): void {
		$post_id = self::factory()->post->create();
		add_filter( 'lb_sp_max_audio_bytes', static fn(): int => 1000 );
		$before = $this->attachment_count();

		$result = $this->ingestor->ingest_from_base64( $post_id, $this->mp3_base64() );

		$this->assertWPError( $result );
		$this->assertSame( 'lb_sp_file_too_large', $result->get_error_code() );
		$this->assertSame( $before, $this->attachment_count() );
	}

	public function test_non_mp3_base64_is_rejected_and_leaves_no_attachment(): void {
		$post_id = self::factory()->post->create();
		$png     = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
		$before  = $this->attachment_count();

		$result = $this->ingestor->ingest_from_base64( $post_id, $png, 'falso.mp3' );

		$this->assertWPError( $result );
		$this->assertSame( 'lb_sp_invalid_mime', $result->get_error_code() );
		$this->assertSame( $before, $this->attachment_count() );
		$this->assertSame( 0, AudioMeta::get_attachment_id( $post_id ) );
	}

	public function test_executable_file_name_is_forced_to_mp3(): void {
		$post_id = self::factory()->post->create();

		$result = $this->ingestor->ingest_from_base64( $post_id, $this->mp3_base64(), '../../shell.php' );

		$this->assertIsArray( $result );
		$path = (string) get_attached_file( $result['attachment_id'] );
		$this->assertStringEndsWith( '.mp3', $path );
		$this->assertStringNotContainsString( '..', basename( $path ) );
	}

	public function test_unknown_post_is_rejected(): void {
		$result = $this->ingestor->ingest_from_base64( 999999, $this->mp3_base64() );

		$this->assertWPError( $result );
		$this->assertSame( 'lb_sp_invalid_post', $result->get_error_code() );
	}

	public function test_page_is_rejected(): void {
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$result = $this->ingestor->ingest_from_base64( $page, $this->mp3_base64() );

		$this->assertWPError( $result );
		$this->assertSame( 'lb_sp_invalid_post', $result->get_error_code() );
	}

	public function test_replacing_removes_the_previous_ingested_audio(): void {
		$post_id = self::factory()->post->create();
		$first   = $this->ingestor->ingest_from_base64( $post_id, $this->mp3_base64(), 'uno.mp3' );
		$this->assertIsArray( $first );
		$first_path = (string) get_attached_file( $first['attachment_id'] );
		$before     = $this->attachment_count();

		$second = $this->ingestor->ingest_from_base64( $post_id, $this->mp3_base64(), 'dos.mp3' );

		$this->assertIsArray( $second );
		$this->assertSame( $first['attachment_id'], $second['replaced_attachment_id'] );
		$this->assertNull( get_post( $first['attachment_id'] ), 'El attachment anterior debe borrarse.' );
		$this->assertFileDoesNotExist( $first_path );
		$this->assertSame( $before, $this->attachment_count(), 'No debe haber huérfanos.' );
		$this->assertSame( $second['attachment_id'], AudioMeta::get_attachment_id( $post_id ) );
	}

	public function test_replacing_keeps_a_library_audio_the_user_chose_manually(): void {
		$post_id = self::factory()->post->create();
		$manual  = (int) self::factory()->attachment->create_upload_object( $this->fixture(), $post_id );
		update_post_meta( $post_id, AudioMeta::META_KEY, $manual );

		$result = $this->ingestor->ingest_from_base64( $post_id, $this->mp3_base64() );

		$this->assertIsArray( $result );
		$this->assertSame( $manual, $result['replaced_attachment_id'] );
		$this->assertNotNull( get_post( $manual ), 'Un audio de la biblioteca no creado por el plugin no se borra.' );
	}
}
