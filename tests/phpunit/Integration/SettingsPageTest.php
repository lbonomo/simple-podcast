<?php
/**
 * Tests de los ajustes del canal.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Tests\Integration;

use LbSimplePodcast\Admin\SettingsPage;
use WP_UnitTestCase;

/**
 * Defaults, sanitización y validación de imagen.
 */
final class SettingsPageTest extends WP_UnitTestCase {

	/**
	 * Archivos temporales.
	 *
	 * @var array<int, string>
	 */
	private array $files = array();

	public function tear_down(): void {
		foreach ( $this->files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
		$this->files = array();
		delete_option( SettingsPage::OPTION );
		$GLOBALS['wp_settings_errors'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		parent::tear_down();
	}

	private function square_png( int $size ): int {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			$this->markTestSkipped( 'GD no disponible.' );
		}
		$path          = wp_tempnam( 'lbsp' ) . '.png';
		$this->files[] = $path;
		$image         = imagecreatetruecolor( $size, $size );
		imagepng( $image, $path );
		return (int) self::factory()->attachment->create_upload_object( $path );
	}

	private function error_codes(): array {
		return wp_list_pluck( get_settings_errors( SettingsPage::OPTION ), 'code' );
	}

	public function test_defaults_come_from_the_site(): void {
		update_option( 'blogname', 'Mi Sitio' );
		update_option( 'blogdescription', 'Un sitio' );
		update_option( 'admin_email', 'admin@example.org' );

		$channel = SettingsPage::get_channel();

		$this->assertSame( 'Mi Sitio', $channel['title'] );
		$this->assertSame( 'Un sitio', $channel['description'] );
		$this->assertSame( 'Mi Sitio', $channel['author'] );
		$this->assertSame( 'admin@example.org', $channel['owner_email'] );
		$this->assertSame( 'false', $channel['explicit'] );
		$this->assertSame( 'en-US', $channel['language'] );
	}

	public function test_saved_values_override_defaults(): void {
		update_option(
			SettingsPage::OPTION,
			array(
				'title'    => 'Mi Podcast',
				'category' => 'Technology',
			)
		);

		$channel = SettingsPage::get_channel();

		$this->assertSame( 'Mi Podcast', $channel['title'] );
		$this->assertSame( 'Technology', $channel['category'] );
		$this->assertSame( 'false', $channel['explicit'] );
	}

	public function test_sanitize_cleans_text_fields(): void {
		$out = ( new SettingsPage() )->sanitize(
			array(
				'title'       => '  <b>Hola</b> <script>x</script>  ',
				'description' => "Línea 1\n<script>alert(1)</script>",
				'category'    => 'Technology',
				'language'    => 'es-AR',
				'owner_email' => 'dueño@example.org ',
				'explicit'    => 'true',
			)
		);

		$this->assertSame( 'Hola', $out['title'] );
		$this->assertStringNotContainsString( '<script>', $out['description'] );
		$this->assertSame( 'Technology', $out['category'] );
		$this->assertSame( 'es-AR', $out['language'] );
		$this->assertSame( 'true', $out['explicit'] );
	}

	public function test_invalid_category_is_rejected(): void {
		$out = ( new SettingsPage() )->sanitize( array( 'category' => 'Nope' ) );

		$this->assertSame( '', $out['category'] );
		$this->assertContains( 'lb_sp_category', $this->error_codes() );
	}

	public function test_invalid_category_keeps_the_previous_value(): void {
		update_option( SettingsPage::OPTION, array( 'category' => 'Music' ) );

		$out = ( new SettingsPage() )->sanitize( array( 'category' => 'apple pie' ) );

		$this->assertSame( 'Music', $out['category'] );
	}

	public function test_category_case_must_match_apple(): void {
		$out = ( new SettingsPage() )->sanitize( array( 'category' => 'kids & family' ) );

		$this->assertSame( '', $out['category'] );
	}

	public function test_invalid_language_and_email_are_rejected(): void {
		$out = ( new SettingsPage() )->sanitize(
			array(
				'language'    => 'español!!',
				'owner_email' => 'no-es-email',
			)
		);

		$this->assertSame( '', $out['language'] );
		$this->assertSame( '', $out['owner_email'] );
		$this->assertContains( 'lb_sp_language', $this->error_codes() );
		$this->assertContains( 'lb_sp_owner_email', $this->error_codes() );
	}

	public function test_explicit_defaults_to_false_for_unknown_values(): void {
		$out = ( new SettingsPage() )->sanitize( array( 'explicit' => 'maybe' ) );

		$this->assertSame( 'false', $out['explicit'] );
	}

	public function test_square_image_in_range_is_accepted(): void {
		$id = $this->square_png( 1400 );

		$out = ( new SettingsPage() )->sanitize( array( 'image_id' => (string) $id ) );

		$this->assertSame( $id, $out['image_id'] );
	}

	public function test_small_image_is_rejected(): void {
		$id = $this->square_png( 300 );

		$out = ( new SettingsPage() )->sanitize( array( 'image_id' => (string) $id ) );

		$this->assertSame( 0, $out['image_id'] );
		$this->assertContains( 'lb_sp_image', $this->error_codes() );
	}

	public function test_non_image_attachment_is_rejected(): void {
		$mp3 = (int) self::factory()->attachment->create_upload_object( dirname( __DIR__ ) . '/fixtures/sample.mp3' );

		$valid = SettingsPage::validate_image( $mp3 );

		$this->assertWPError( $valid );
		$this->assertSame( 'lb_sp_image_type', $valid->get_error_code() );
	}

	public function test_saving_bumps_the_feed_version(): void {
		delete_option( 'lb_sp_feed_version' );

		( new SettingsPage() )->sanitize( array( 'title' => 'x' ) );

		$this->assertNotFalse( get_option( 'lb_sp_feed_version' ) );
	}

	public function test_only_admins_can_manage_settings(): void {
		$this->assertSame( 'manage_options', SettingsPage::CAPABILITY );
		$this->assertFalse( user_can( self::factory()->user->create( array( 'role' => 'editor' ) ), SettingsPage::CAPABILITY ) );
		$this->assertTrue( user_can( self::factory()->user->create( array( 'role' => 'administrator' ) ), SettingsPage::CAPABILITY ) );
	}

	public function test_page_renders_form_fields(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		( new SettingsPage() )->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="lb_sp_channel[title]"', $html );
		$this->assertStringContainsString( 'Kids &amp; Family', $html );
		$this->assertStringContainsString( "value='lb_sp_channel_group'", $html );
	}
}
