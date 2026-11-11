<?php
/**
 * Versión del feed para caché e invalidación (ETag / Last-Modified).
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Feed;

/**
 * Guarda en una opción el instante del último cambio relevante del feed.
 */
final class FeedVersion {

	public const OPTION = 'lb_sp_feed_version';

	/**
	 * Versión actual (microtime como cadena). Se inicializa si no existe.
	 */
	public static function get(): string {
		$version = get_option( self::OPTION, '' );
		if ( ! is_string( $version ) || '' === $version ) {
			$version = self::bump();
		}
		return $version;
	}

	/**
	 * Invalida el feed y devuelve la nueva versión.
	 */
	public static function bump(): string {
		$version = sprintf( '%.6F', microtime( true ) );
		update_option( self::OPTION, $version, false );
		delete_transient( 'lb_sp_feed_body' );
		return $version;
	}

	/**
	 * Marca temporal UNIX de la versión.
	 */
	public static function timestamp(): int {
		return (int) floor( (float) self::get() );
	}
}
