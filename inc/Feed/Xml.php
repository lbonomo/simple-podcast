<?php
/**
 * Utilidades de escape para XML de podcast.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Feed;

/**
 * Escapa texto con las entidades que recomienda Apple (&amp; &lt; &gt; &apos; &quot;).
 */
final class Xml {

	/**
	 * Texto plano: sin HTML, entidades decodificadas, sin caracteres de control.
	 *
	 * @param string $value Texto de origen (puede contener HTML o entidades).
	 */
	public static function plain( string $value ): string {
		$value = html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$value = (string) preg_replace( '/[^\P{C}\t\n\r]+/u', '', $value );
		return trim( $value );
	}

	/**
	 * Escapa texto para nodos y atributos XML.
	 *
	 * @param string $value Texto plano.
	 */
	public static function text( string $value ): string {
		$value = (string) preg_replace( '/[^\P{C}\t\n\r]+/u', '', $value );
		return htmlspecialchars( $value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8' );
	}

	/**
	 * Envuelve HTML en CDATA, neutralizando secuencias de cierre.
	 *
	 * @param string $value HTML.
	 */
	public static function cdata( string $value ): string {
		$value = (string) preg_replace( '/[^\P{C}\t\n\r]+/u', '', $value );
		return '<![CDATA[' . str_replace( ']]>', ']]]]><![CDATA[>', $value ) . ']]>';
	}

	/**
	 * Duración como HH:MM:SS.
	 *
	 * @param int $seconds Segundos.
	 */
	public static function duration( int $seconds ): string {
		$seconds = max( 0, $seconds );
		return sprintf( '%02d:%02d:%02d', intdiv( $seconds, 3600 ), intdiv( $seconds % 3600, 60 ), $seconds % 60 );
	}

	/**
	 * Fecha RFC 2822 a partir de una fecha GMT de MySQL.
	 *
	 * @param string $mysql_gmt Fecha 'Y-m-d H:i:s' en GMT.
	 */
	public static function rfc2822( string $mysql_gmt ): string {
		return (string) mysql2date( 'D, d M Y H:i:s +0000', $mysql_gmt, false );
	}
}
