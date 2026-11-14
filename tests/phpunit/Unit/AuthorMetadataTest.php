<?php
/**
 * Bug condition exploration: metadatos de autor del plugin.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Verifica el autor y la URL del autor en la cabecera y en el POT.
 *
 * **Validates: Requirements 1.1, 1.2, 1.3, 1.4**
 */
final class AuthorMetadataTest extends TestCase {

	/**
	 * Raíz del plugin.
	 *
	 * @return string
	 */
	private function root(): string {
		return dirname( __DIR__, 3 );
	}

	/**
	 * Lee un campo de la cabecera del plugin.
	 *
	 * @param string $field Nombre del campo.
	 * @return string|null Valor, o null si no existe.
	 */
	private function header( string $field ): ?string {
		$source = (string) file_get_contents( $this->root() . '/lb-simple-podcast.php' );
		$head   = substr( $source, 0, 8192 );
		if ( preg_match( '/^[ \t\/*#@]*' . preg_quote( $field, '/' ) . ':(.*)$/mi', $head, $m ) ) {
			return trim( $m[1] );
		}
		return null;
	}

	public function test_header_author_is_lucas_bonomo(): void {
		$this->assertSame( 'Lucas Bonomo', $this->header( 'Author' ) );
	}

	public function test_header_author_uri_is_set(): void {
		$this->assertSame( 'https://lucasbonomo.com', $this->header( 'Author URI' ) );
	}

	public function test_pot_copyright_line_uses_new_name(): void {
		$lines = file( $this->root() . '/languages/lb-simple-podcast.pot', FILE_IGNORE_NEW_LINES );
		$this->assertSame( '# Copyright (C) 2026 Lucas Bonomo', $lines[0] );
	}

	public function test_pot_author_msgid_uses_new_name(): void {
		$pot = (string) file_get_contents( $this->root() . '/languages/lb-simple-podcast.pot' );
		$this->assertMatchesRegularExpression( '/#\. Author of the plugin\R(?:#.*\R)*msgid "Lucas Bonomo"/', $pot );
	}

	public function test_repository_has_no_old_author_name(): void {
		$skip     = array( 'node_modules', 'vendor', '.git', '.kiro', 'build' );
		$iterator = new RecursiveIteratorIterator(
			new RecursiveCallbackFilterIterator(
				new RecursiveDirectoryIterator( $this->root(), FilesystemIterator::SKIP_DOTS ),
				static function ( $file ) use ( $skip ): bool {
					return ! in_array( $file->getFilename(), $skip, true )
						&& ! in_array( $file->getExtension(), array( 'zip', 'map' ), true )
						&& 'AuthorMetadataTest.php' !== $file->getFilename();
				}
			)
		);

		$matches = array();
		foreach ( $iterator as $file ) {
			if ( $file->isFile() && str_contains( (string) file_get_contents( $file->getPathname() ), 'Leandro Bonomo' ) ) {
				$matches[] = substr( $file->getPathname(), strlen( $this->root() ) + 1 );
			}
		}
		$this->assertSame( array(), $matches, 'Files still containing "Leandro Bonomo".' );
	}
}
