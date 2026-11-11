<?php
/**
 * Plugin Name:       LB Simple Podcast
 * Description:       Asocia un MP3 a cada entrada, publica un feed RSS de podcast, agrega metadatos SEO y expone una ability MCP.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Leandro Bonomo
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lb-simple-podcast
 * Domain Path:       /languages
 *
 * @package LbSimplePodcast
 */

defined( 'ABSPATH' ) || exit;

define( 'LB_SP_VERSION', '0.1.0' );
define( 'LB_SP_FILE', __FILE__ );
define( 'LB_SP_DIR', plugin_dir_path( __FILE__ ) );
define( 'LB_SP_URL', plugin_dir_url( __FILE__ ) );

if ( ! is_readable( LB_SP_DIR . 'vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'LB Simple Podcast: falta el autoload de Composer (vendor/autoload.php).', 'lb-simple-podcast' )
				. '</p></div>';
		}
	);
	return;
}

require_once LB_SP_DIR . 'vendor/autoload.php';

register_activation_hook( __FILE__, array( \LbSimplePodcast\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \LbSimplePodcast\Plugin::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		\LbSimplePodcast\Plugin::boot();
	}
);
