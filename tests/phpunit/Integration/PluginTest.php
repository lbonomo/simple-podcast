<?php
/**
 * Test de humo del bootstrap.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Tests\Integration;

use LbSimplePodcast\Plugin;
use WP_UnitTestCase;

/**
 * Verifica que el plugin se carga e inicializa.
 */
final class PluginTest extends WP_UnitTestCase {

	public function test_plugin_constants_are_defined(): void {
		$this->assertTrue( defined( 'LB_SP_VERSION' ) );
		$this->assertDirectoryExists( LB_SP_DIR );
	}

	public function test_boot_returns_a_single_instance(): void {
		$first = Plugin::boot();
		$this->assertSame( $first, Plugin::boot() );
		$this->assertSame( $first, Plugin::instance() );
	}

	public function test_text_domain_loader_is_hooked(): void {
		Plugin::boot();
		$this->assertNotFalse( has_action( 'init', array( Plugin::instance(), 'load_textdomain' ) ) );
	}
}
