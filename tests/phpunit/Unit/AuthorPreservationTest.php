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

	/**
	 * Versión declarada en la constante LB_SP_VERSION (fuente de verdad).
	 *
	 * @return string
	 */
	private function constant_version(): string {
		$this->assertSame(
			1,
			preg_match( "/define\\( 'LB_SP_VERSION', '([^']+)' \\);/", $this->read( 'lb-simple-podcast.php' ), $m ),
			'No se encontró la definición de LB_SP_VERSION.'
		);
		return $m[1];
	}

	public function test_version_constant_is_a_semantic_version(): void {
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', $this->constant_version() );
	}

	public function test_loaded_constant_matches_the_declared_version(): void {
		if ( ! defined( 'LB_SP_VERSION' ) ) {
			$this->markTestSkipped( 'LB_SP_VERSION no está cargada en este entorno.' );
		}
		$this->assertSame( $this->constant_version(), LB_SP_VERSION );
	}

	public function test_version_header_matches_constant(): void {
		$this->assertSame( $this->constant_version(), $this->header( 'Version' ) );
	}

	public function test_package_json_version_matches_constant(): void {
		$data = json_decode( $this->read( 'package.json' ), true );
		$this->assertSame( $this->constant_version(), $data['version'] );
	}

	public function test_package_lock_versions_match_constant(): void {
		$data = json_decode( $this->read( 'package-lock.json' ), true );
		$this->assertSame( $this->constant_version(), $data['version'] );
		$this->assertSame( $this->constant_version(), $data['packages']['']['version'] );
	}

	public function test_readme_stable_tag_matches_constant(): void {
		$this->assertMatchesRegularExpression( '/^Stable tag:\s*' . preg_quote( $this->constant_version(), '/' ) . '\s*$/m', $this->read( 'readme.txt' ) );
	}

	public function test_readme_has_a_changelog_entry_for_the_version(): void {
		$this->assertMatchesRegularExpression( '/^= ' . preg_quote( $this->constant_version(), '/' ) . ' =\s*$/m', $this->read( 'readme.txt' ) );
	}

	public function test_no_hardcoded_plugin_version_in_php_sources(): void {
		$version = $this->constant_version();
		$files   = array();
		foreach ( array( 'inc', 'templates' ) as $dir ) {
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->root() . '/' . $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( $file instanceof \SplFileInfo && 'php' === $file->getExtension() ) {
					$files[] = $file->getPathname();
				}
			}
		}
		$this->assertNotEmpty( $files );
		foreach ( $files as $file ) {
			$code = (string) file_get_contents( $file );
			$this->assertStringNotContainsString( $version, $code, "Versión escrita a mano en $file; usar LB_SP_VERSION." );
			$this->assertDoesNotMatchRegularExpression( "/['\"]\\d+\\.\\d+\\.\\d+['\"]/", $code, "Literal de versión en $file; usar LB_SP_VERSION." );
		}
		// El archivo principal solo puede declarar la versión en la cabecera y en la constante.
		$main = (string) preg_replace( "/^.*(Version:|define\\( 'LB_SP_VERSION').*$/m", '', $this->read( 'lb-simple-podcast.php' ) );
		$this->assertStringNotContainsString( $version, $main );
	}

	public function test_phpstan_bootstrap_does_not_hardcode_the_version(): void {
		$this->assertDoesNotMatchRegularExpression( "/['\"]\\d+\\.\\d+\\.\\d+['\"]/", $this->read( 'tests/phpstan/bootstrap.php' ) );
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
