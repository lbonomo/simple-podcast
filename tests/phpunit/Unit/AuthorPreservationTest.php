<?php
/**
 * Preservation: metadatos que no deben cambiar al corregir el autor.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Lectura de archivos locales del plugin, no URLs remotas.

namespace LbSimplePodcast\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Verifica versión, otros campos de cabecera e identificadores de cuenta.
 *
 * **Validates: Requirements 3.1, 3.2, 3.3, 3.4**
 */
final class AuthorPreservationTest extends TestCase {

	/**
	 * Raíz del plugin.
	 *
	 * @return string
	 */
	private function root(): string {
		return dirname( __DIR__, 3 );
	}

	/**
	 * Lee un archivo del plugin.
	 *
	 * @param string $path Ruta relativa.
	 * @return string
	 */
	private function read( string $path ): string {
		return (string) file_get_contents( $this->root() . '/' . $path );
	}

	/**
	 * Lee un campo de la cabecera del plugin.
	 *
	 * @param string $field Nombre del campo.
	 * @return string|null
	 */
	private function header( string $field ): ?string {
		$head = substr( $this->read( 'lb-simple-podcast.php' ), 0, 8192 );
		if ( preg_match( '/^[ \t\/*#@]*' . preg_quote( $field, '/' ) . ':(.*)$/mi', $head, $m ) ) {
			return trim( $m[1] );
		}
		return null;
	}

	public function test_version_header_is_0_7_0(): void {
		$this->assertSame( '0.7.0', $this->header( 'Version' ) );
	}

	public function test_version_constant_is_0_7_0(): void {
		$this->assertMatchesRegularExpression( "/define\( 'LB_SP_VERSION', '0\.7\.0' \);/", $this->read( 'lb-simple-podcast.php' ) );
	}

	public function test_package_json_version_is_0_7_0(): void {
		$data = json_decode( $this->read( 'package.json' ), true );
		$this->assertSame( '0.7.0', $data['version'] );
	}

	public function test_package_lock_versions_are_0_7_0(): void {
		$data = json_decode( $this->read( 'package-lock.json' ), true );
		$this->assertSame( '0.7.0', $data['version'] );
		$this->assertSame( '0.7.0', $data['packages']['']['version'] );
	}

	public function test_readme_stable_tag_is_0_7_0(): void {
		$this->assertMatchesRegularExpression( '/^Stable tag:\s*0\.7\.0\s*$/m', $this->read( 'readme.txt' ) );
	}

	/**
	 * Campos de cabecera que no deben cambiar.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function unchanged_header_fields(): array {
		return array(
			'Plugin Name'       => array( 'Plugin Name', 'Simple Podcast' ),
			'Description'       => array( 'Description', 'Asocia un MP3 a cada entrada, publica un feed RSS de podcast, agrega metadatos SEO y expone una ability MCP.' ),
			'Requires at least' => array( 'Requires at least', '6.4' ),
			'Requires PHP'      => array( 'Requires PHP', '8.1' ),
			'License'           => array( 'License', 'GPL-2.0-or-later' ),
			'License URI'       => array( 'License URI', 'https://www.gnu.org/licenses/gpl-2.0.html' ),
			'Text Domain'       => array( 'Text Domain', 'lb-simple-podcast' ),
		);
	}

	/**
	 * @dataProvider unchanged_header_fields
	 *
	 * @param string $field    Campo.
	 * @param string $expected Valor esperado.
	 */
	public function test_header_field_is_unchanged( string $field, string $expected ): void {
		$this->assertSame( $expected, $this->header( $field ) );
	}

	public function test_composer_package_name_is_unchanged(): void {
		$data = json_decode( $this->read( 'composer.json' ), true );
		$this->assertSame( 'lbonomo/lb-simple-podcast', $data['name'] );
	}

	public function test_readme_contributors_is_unchanged(): void {
		$this->assertMatchesRegularExpression( '/^Contributors:\s*lbonomo\s*$/m', $this->read( 'readme.txt' ) );
	}

	public function test_github_updater_url_is_unchanged(): void {
		$this->assertStringContainsString( "'https://github.com/lbonomo/simple-podcast/'", $this->read( 'lb-simple-podcast.php' ) );
	}
}
