<?php
/**
 * Lecturas y estadísticas de la tabla de solicitudes de audio.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Tracking;

use LbSimplePodcast\Meta\AudioMeta;
use WP_Post;
use WP_Query;

/** Consultas de solo lectura para el Dashboard y los Logs. */
final class RequestStats {

	/**
	 * Estados HTTP que cuentan como escucha (respuesta completa o parcial).
	 *
	 * @var array<int, int>
	 */
	public const COUNTED_STATUSES = array( 200, 206 );

	/**
	 * Cantidad de audios publicados (mismo criterio que el feed).
	 */
	public function count_published_audios(): int {
		$query = new WP_Query( $this->audio_query_args( 1, true ) );
		return (int) $query->found_posts;
	}

	/**
	 * Última entrada publicada con audio.
	 */
	public function latest_audio(): ?WP_Post {
		$query = new WP_Query( $this->audio_query_args( 1, false ) );
		$post  = $query->posts[0] ?? null;
		return $post instanceof WP_Post ? $post : null;
	}

	/**
	 * Total de solicitudes contadas en los últimos días.
	 *
	 * @param int $days Cantidad de días.
	 */
	public function requests_last_days( int $days = 30 ): int {
		global $wpdb;
		$table    = RequestLog::table();
		$statuses = $this->statuses_sql();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `$table` WHERE requested_at >= %s AND status IN ($statuses)", $this->since( $days ) ) );
	}

	/**
	 * Episodios más escuchados en los últimos días.
	 *
	 * @param int $days  Cantidad de días.
	 * @param int $limit Máximo de episodios.
	 * @return array<int, array{post_id: int, total: int}>
	 */
	public function top_episodes( int $days = 30, int $limit = 5 ): array {
		global $wpdb;
		$table    = RequestLog::table();
		$statuses = $this->statuses_sql();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, COUNT(*) AS total FROM `$table` WHERE requested_at >= %s AND status IN ($statuses) AND post_id > 0 GROUP BY post_id ORDER BY total DESC, post_id ASC LIMIT %d", $this->since( $days ), max( 1, $limit ) ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[] = array(
					'post_id' => (int) $row['post_id'],
					'total'   => (int) $row['total'],
				);
			}
		}
		return $out;
	}

	/**
	 * Total de filas del registro.
	 */
	public function count_all(): int {
		global $wpdb;
		$table = RequestLog::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table`" );
	}

	/**
	 * Una página del registro, de la más reciente a la más antigua.
	 *
	 * @param int $page     Número de página (desde 1).
	 * @param int $per_page Filas por página.
	 * @return array<int, array{id: int, requested_at: string, post_id: int, attachment_id: int, user_id: int, method: string, remote_addr: string, request_url: string, headers: string, status: int}>
	 */
	public function get_page( int $page, int $per_page = 50 ): array {
		global $wpdb;
		$table    = RequestLog::table();
		$per_page = max( 1, $per_page );
		$offset   = ( max( 1, $page ) - 1 ) * $per_page;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, requested_at, post_id, attachment_id, user_id, method, remote_addr, request_url, headers, status FROM `$table` ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[] = array(
					'id'            => (int) $row['id'],
					'requested_at'  => (string) $row['requested_at'],
					'post_id'       => (int) $row['post_id'],
					'attachment_id' => (int) $row['attachment_id'],
					'user_id'       => (int) $row['user_id'],
					'method'        => (string) $row['method'],
					'remote_addr'   => (string) $row['remote_addr'],
					'request_url'   => (string) $row['request_url'],
					'headers'       => (string) $row['headers'],
					'status'        => (int) $row['status'],
				);
			}
		}
		return $out;
	}

	/**
	 * Fecha de corte en GMT.
	 *
	 * @param int $days Cantidad de días.
	 */
	private function since( int $days ): string {
		return gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );
	}

	/** Lista de estados lista para SQL (son enteros fijos). */
	private function statuses_sql(): string {
		return implode( ',', array_map( 'intval', self::COUNTED_STATUSES ) );
	}

	/**
	 * Argumentos de WP_Query: publicados, sin contraseña, con audio válido.
	 *
	 * @param int  $per_page   Resultados.
	 * @param bool $count_only Solo contar (usa found_posts).
	 * @return array<string, mixed>
	 */
	private function audio_query_args( int $per_page, bool $count_only ): array {
		return array(
			'post_type'              => AudioMeta::POST_TYPE,
			'post_status'            => 'publish',
			'has_password'           => false,
			'posts_per_page'         => $per_page,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => ! $count_only,
			'fields'                 => $count_only ? 'ids' : 'all',
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
			'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => AudioMeta::META_KEY,
					'value'   => 0,
					'compare' => '>',
					'type'    => 'NUMERIC',
				),
			),
		);
	}
}
