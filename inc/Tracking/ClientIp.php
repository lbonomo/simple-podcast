<?php
/**
 * Resolución de la IP del cliente detrás de proxies de confianza.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Tracking;

/**
 * Por defecto usa solo REMOTE_ADDR. Si REMOTE_ADDR pertenece a un proxy de confianza
 * (constante `LB_SP_TRUSTED_PROXIES` o filtro `lb_sp_trusted_proxies`), toma la IP de la
 * cabecera de reenvío recorriéndola de derecha a izquierda hasta la primera IP que no sea
 * de un proxy de confianza. Una cabecera enviada por un cliente cualquiera se ignora.
 */
final class ClientIp {

	public const DEFAULT_HEADER = 'X-Forwarded-For';

	/**
	 * IP del cliente ('' si no hay una IP válida).
	 *
	 * @param array<string, mixed> $server Variables PHP.
	 */
	public static function resolve( array $server ): string {
		$remote = isset( $server['REMOTE_ADDR'] ) && is_string( $server['REMOTE_ADDR'] ) ? self::valid( $server['REMOTE_ADDR'] ) : '';
		$ip     = $remote;
		$trust  = self::trusted_proxies();
		if ( '' !== $remote && array() !== $trust && self::in_ranges( $remote, $trust ) ) {
			$ip = self::from_header( $server, $trust, $remote );
		}
		$filtered = (string) apply_filters( 'lb_sp_client_ip', $ip, $server );
		return self::valid( $filtered );
	}

	/**
	 * Anonimiza una IP: IPv4 sin el último octeto, IPv6 recortada a /48.
	 *
	 * @param string $ip IP válida.
	 */
	public static function anonymize( string $ip ): string {
		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $packed ) {
			return '';
		}
		$keep   = 4 === strlen( $packed ) ? 3 : 6;
		$masked = substr( $packed, 0, $keep ) . str_repeat( "\0", strlen( $packed ) - $keep );
		$text   = inet_ntop( $masked );
		return false === $text ? '' : $text;
	}

	/** Indica si debe anonimizarse la IP guardada (constante `LB_SP_ANONYMIZE_IP` o filtro). */
	public static function should_anonymize(): bool {
		$default = defined( 'LB_SP_ANONYMIZE_IP' ) && (bool) constant( 'LB_SP_ANONYMIZE_IP' );
		return (bool) apply_filters( 'lb_sp_anonymize_ip', $default );
	}

	/**
	 * Rangos de proxies de confianza (IP o CIDR).
	 *
	 * @return array<int, string>
	 */
	public static function trusted_proxies(): array {
		$value = defined( 'LB_SP_TRUSTED_PROXIES' ) ? constant( 'LB_SP_TRUSTED_PROXIES' ) : array();
		$value = apply_filters( 'lb_sp_trusted_proxies', $value );
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY );
		}
		$out = array();
		foreach ( is_array( $value ) ? $value : array() as $range ) {
			if ( is_string( $range ) && self::parse_range( trim( $range ) ) ) {
				$out[] = trim( $range );
			}
		}
		return $out;
	}

	/**
	 * Indica si una IP está dentro de alguno de los rangos.
	 *
	 * @param string             $ip     IP válida.
	 * @param array<int, string> $ranges IPs o CIDR.
	 */
	public static function in_ranges( string $ip, array $ranges ): bool {
		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $packed ) {
			return false;
		}
		foreach ( $ranges as $range ) {
			$parsed = self::parse_range( $range );
			if ( false === $parsed || strlen( $parsed['net'] ) !== strlen( $packed ) ) {
				continue;
			}
			if ( self::matches( $packed, $parsed['net'], $parsed['bits'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Recorre la cabecera de derecha a izquierda.
	 *
	 * @param array<string, mixed> $server Variables PHP.
	 * @param array<int, string>   $trust  Rangos de confianza.
	 * @param string               $remote IP de la conexión (proxy de confianza).
	 */
	private static function from_header( array $server, array $trust, string $remote ): string {
		$name = defined( 'LB_SP_PROXY_HEADER' ) ? (string) constant( 'LB_SP_PROXY_HEADER' ) : self::DEFAULT_HEADER;
		$name = (string) apply_filters( 'lb_sp_proxy_header', $name );
		$key  = 'HTTP_' . strtoupper( str_replace( '-', '_', trim( $name ) ) );
		$raw  = isset( $server[ $key ] ) && is_string( $server[ $key ] ) ? $server[ $key ] : '';
		if ( '' === $raw ) {
			return $remote;
		}
		$parts = array_reverse( array_map( 'trim', explode( ',', $raw ) ) );
		foreach ( $parts as $candidate ) {
			$ip = self::valid( $candidate );
			if ( '' === $ip ) {
				// Un valor ilegible rompe la cadena de confianza: se usa la IP de la conexión.
				return $remote;
			}
			if ( ! self::in_ranges( $ip, $trust ) ) {
				return $ip;
			}
		}
		return $remote;
	}

	/**
	 * Devuelve la IP si es válida, o ''.
	 *
	 * @param string $ip Texto.
	 */
	private static function valid( string $ip ): string {
		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Descompone una IP o CIDR.
	 *
	 * @param string $range IP o CIDR.
	 * @return array{net: string, bits: int}|false
	 */
	private static function parse_range( string $range ) {
		$bits = null;
		if ( str_contains( $range, '/' ) ) {
			list( $range, $mask ) = explode( '/', $range, 2 );
			if ( '' === $mask || ! ctype_digit( $mask ) ) {
				return false;
			}
			$bits = (int) $mask;
		}
		if ( '' === self::valid( $range ) ) {
			return false;
		}
		$packed = @inet_pton( $range ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $packed ) {
			return false;
		}
		$max  = strlen( $packed ) * 8;
		$bits = $bits ?? $max;
		if ( $bits > $max ) {
			return false;
		}
		return array(
			'net'  => $packed,
			'bits' => $bits,
		);
	}

	/**
	 * Compara los primeros $bits bits de dos direcciones empaquetadas.
	 *
	 * @param string $a    Dirección.
	 * @param string $b    Red.
	 * @param int    $bits Bits de prefijo.
	 */
	private static function matches( string $a, string $b, int $bits ): bool {
		$bytes = intdiv( $bits, 8 );
		if ( substr( $a, 0, $bytes ) !== substr( $b, 0, $bytes ) ) {
			return false;
		}
		$rest = $bits % 8;
		if ( 0 === $rest ) {
			return true;
		}
		$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
		return ( ord( $a[ $bytes ] ) & $mask ) === ( ord( $b[ $bytes ] ) & $mask );
	}
}
