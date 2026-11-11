<?php
/**
 * Tests de AudioValidator.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Tests\Unit;

use LbSimplePodcast\Media\AudioValidator;
use WP_Error;
use WP_UnitTestCase;

/**
 * Valida las reglas de mime, tamaño y duración.
 */
final class AudioValidatorTest extends WP_UnitTestCase {

	/**
	 * Validador bajo prueba.
	 *
	 * @var AudioValidator
	 */
	private AudioValidator $validator;

	/**
	 * Archivos temporales a limpiar.
	 *
	 * @var array<int, string>
	 */
	private array $temp_files = array();

	public function set_up(): void {
		parent::set_up();
		$this->validator = new AudioValidator();
	}

	public function tear_down(): void {
		foreach ( $this->temp_files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
		parent::tear_down();
	}

	private function fixture(): string {
		return dirname( __DIR__ ) . '/fixtures/sample.mp3';
	}

	private function temp_file( string $contents, string $extension = 'mp3' ): string {
		$path = wp_tempnam( 'lbsp' ) . '.' . $extension;
		file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->temp_files[] = $path;
		return $path;
	}

	public function test_valid_mp3_returns_metadata(): void {
		$result = $this->validator->validate_file( $this->fixture() );

		$this->assertIsArray( $result );
		$this->assertSame( 'audio/mpeg', $result['mime_type'] );
		$this->assertSame( filesize( $this->fixture() ), $result['filesize'] );
		$this->assertGreaterThanOrEqual( 1, $result['duration'] );
		$this->assertLessThanOrEqual( 3, $result['duration'] );
	}

	public function test_png_renamed_to_mp3_is_rejected(): void {
		$png  = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$path = $this->temp_file( (string) $png );

		$result = $this->validator->validate_file( $path );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'lb_sp_invalid_mime', $result->get_error_code() );
	}

	public function test_wrong_extension_is_rejected(): void {
		$path = $this->temp_file( (string) file_get_contents( $this->fixture() ), 'wav' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$result = $this->validator->validate_file( $path );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'lb_sp_invalid_mime', $result->get_error_code() );
	}

	public function test_empty_file_is_rejected(): void {
		$result = $this->validator->validate_file( $this->temp_file( '' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'lb_sp_file_empty', $result->get_error_code() );
	}

	public function test_missing_file_is_rejected(): void {
		$result = $this->validator->validate_file( '/no/existe.mp3' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'lb_sp_file_missing', $result->get_error_code() );
	}

	public function test_file_over_the_limit_is_rejected(): void {
		add_filter(
			'lb_sp_max_audio_bytes',
			static fn(): int => 100
		);

		$result = $this->validator->validate_file( $this->fixture() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'lb_sp_file_too_large', $result->get_error_code() );
	}

	public function test_max_bytes_never_exceeds_wordpress_upload_limit(): void {
		$this->assertLessThanOrEqual( wp_max_upload_size(), AudioValidator::max_bytes() );
		$this->assertGreaterThan( 0, AudioValidator::max_bytes() );
	}

	public function test_validate_attachment_rejects_non_attachment(): void {
		$post_id = self::factory()->post->create();

		$result = $this->validator->validate_attachment( $post_id );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'lb_sp_not_attachment', $result->get_error_code() );
	}

	public function test_validate_attachment_accepts_mp3_attachment(): void {
		$id = self::factory()->attachment->create_upload_object( $this->fixture() );

		$result = $this->validator->validate_attachment( (int) $id );

		$this->assertIsArray( $result );
		$this->assertSame( 'audio/mpeg', $result['mime_type'] );
	}
}
