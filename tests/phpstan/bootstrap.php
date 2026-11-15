<?php
/**
 * Constantes del plugin para el análisis estático.
 *
 * @package LbSimplePodcast
 */

// La versión se lee de la cabecera del plugin para no mantener una segunda copia.
$lb_sp_header = (string) file_get_contents( dirname( __DIR__, 2 ) . '/lb-simple-podcast.php', false, null, 0, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
define( 'LB_SP_VERSION', 1 === preg_match( '/^[ \t\/*#@]*Version:\s*(\S+)\s*$/mi', $lb_sp_header, $lb_sp_match ) ? $lb_sp_match[1] : 'unknown' );
define( 'LB_SP_FILE', dirname( __DIR__, 2 ) . '/lb-simple-podcast.php' );
define( 'LB_SP_DIR', dirname( LB_SP_FILE ) . '/' );
define( 'LB_SP_URL', 'https://example.org/wp-content/plugins/lb-simple-podcast/' );
