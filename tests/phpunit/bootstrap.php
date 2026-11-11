<?php
/**
 * Bootstrap de PHPUnit con la suite de tests de WordPress.
 *
 * Variables de entorno opcionales: LB_SP_DB_NAME, LB_SP_DB_USER, LB_SP_DB_PASSWORD,
 * LB_SP_DB_HOST y LB_SP_WP_ABSPATH (por defecto, los valores del entorno Lando).
 *
 * @package LbSimplePodcast
 */

$lb_sp_plugin_dir = dirname( __DIR__, 2 );

require_once $lb_sp_plugin_dir . '/vendor/autoload.php';

$lb_sp_env = static function ( string $name, string $fallback ): string {
	$value = getenv( $name );
	return false === $value || '' === $value ? $fallback : $value;
};

// Configuración de la base de datos de pruebas (se crea con prefijo propio y se resetea).
define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );
// phpcs:disable WordPress.WP.CapitalPDangit
$lb_sp_config = "<?php\n"
	. "define( 'ABSPATH', '" . rtrim( $lb_sp_env( 'LB_SP_WP_ABSPATH', '/app/wordpress' ), '/' ) . "/' );\n"
	. "define( 'DB_NAME', '" . $lb_sp_env( 'LB_SP_DB_NAME', 'wordpress_test' ) . "' );\n"
	. "define( 'DB_USER', '" . $lb_sp_env( 'LB_SP_DB_USER', 'wordpress' ) . "' );\n"
	. "define( 'DB_PASSWORD', '" . $lb_sp_env( 'LB_SP_DB_PASSWORD', 'wordpress' ) . "' );\n"
	. "define( 'DB_HOST', '" . $lb_sp_env( 'LB_SP_DB_HOST', 'database' ) . "' );\n"
	. "define( 'DB_CHARSET', 'utf8' );\n"
	. "define( 'DB_COLLATE', '' );\n"
	. "define( 'WP_TESTS_DOMAIN', 'example.org' );\n"
	. "define( 'WP_TESTS_EMAIL', 'admin@example.org' );\n"
	. "define( 'WP_TESTS_TITLE', 'Test Blog' );\n"
	. "define( 'WP_PHP_BINARY', 'php' );\n"
	. "define( 'WPLANG', '' );\n"
	. "\$table_prefix = 'wptests_';\n";
// phpcs:enable WordPress.WP.CapitalPDangit
file_put_contents( WP_TESTS_CONFIG_FILE_PATH, $lb_sp_config ); // phpcs:ignore WordPress.WP.AlternativeFunctions

$lb_sp_tests_dir = $lb_sp_plugin_dir . '/vendor/wp-phpunit/wp-phpunit';

require_once $lb_sp_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $lb_sp_plugin_dir ): void {
		require $lb_sp_plugin_dir . '/lb-simple-podcast.php';
	}
);

require $lb_sp_tests_dir . '/includes/bootstrap.php';
