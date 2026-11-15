<?php
/**
 * Limitador de solicitudes de audio por IP.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Media;

/**
 * Contador de ventana fija por IP, pensado para costar casi nada.
 *
 * Se consulta antes de cualquier acceso a la base de datos del plugin. El contador vive en
 * APCu si está disponible, si no en la caché de objetos persistente y, como último recurso,
 * en un transient (que sí escribe en la base de datos).
 */
final class RequestThrottle {

	public const DEFAULT_LIMIT  = 120;
	public const DEFAULT_WINDOW = 60;
	public const CACHE_GROUP    = 'lb_sp_throttle';

	/** Máximo de solicitudes por IP y ventana (0 desactiva el límite). */
	public function limit(): int {
		return max( 0, (int) apply_filters( 'lb_sp_throttle_limit', self::DEFAULT_LIMIT ) );
	}

	/** Duración de la ventana en segundos. */
	public function window(): int {
		return max( 1, (int) apply_filters( 'lb_sp_throttle_window', self::DEFAULT_WINDOW ) );
	}

	/**
	 * IP del cliente. Usa solo REMOTE_ADDR; detrás de un proxy de confianza debe
	 * ajustarse con el filtro `lb_sp_client_ip`.
	 *
	 * @param array<string, mixed> $server Variables PHP.
	 */
	public static function client_ip( array $server ): string {
		$ip = isset( $server['REMOTE_ADDR'] ) && is_string( $server['REMOTE_ADDR'] ) ? $server['REMOTE_ADDR'] : '';
		$ip = (string) apply_filters( 'lb_sp_client_ip', $ip, $server );
		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Cuenta una solicitud.
	 *
	 * @param string $ip IP del cliente ('' = desconocida, no se limita).
	 * @return int Segundos que debe esperar el cliente; 0 si la solicitud está permitida.
	 */
	public function hit( string $ip ): int {
		$limit = $this->limit();
		if ( 0 === $limit || '' === $ip ) {
			return 0;
		}
		$window = $this->window();
		$bucket = intdiv( time(), $window );
		$count  = $this->increment( 'lb_sp_thr_' . md5( $ip ) . '_' . $bucket, $window );
		if ( $count <= $limit ) {
			return 0;
		}
		return max( 1, $window - ( time() % $window ) );
	}

	/**
	 * Incrementa el contador y devuelve su valor.
	 *
	 * @param string $key    Clave (incluye la ventana).
	 * @param int    $window Vida del contador en segundos.
	 */
	private function increment( string $key, int $window ): int {
		if ( function_exists( 'apcu_enabled' ) && apcu_enabled() ) {
			apcu_add( $key, 0, $window );
			$value = apcu_inc( $key );
			if ( is_int( $value ) ) {
				return $value;
			}
		}
		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $key, 0, self::CACHE_GROUP, $window );
			$value = wp_cache_incr( $key, 1, self::CACHE_GROUP );
			if ( is_int( $value ) ) {
				return $value;
			}
		}
		$value = (int) get_transient( $key ) + 1;
		set_transient( $key, $value, $window );
		return $value;
	}
}
