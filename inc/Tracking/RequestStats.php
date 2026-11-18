<?php
/**
 * Lecturas y estadísticas de la tabla de solicitudes de audio.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Tracking;

use DateTimeImmutable;
use DateTimeZone;
use LbSimplePodcast\Meta\AudioMeta;
use WP_Post;
use WP_Query;

/** Consultas de solo lectura y métricas agregadas para el Dashboard y los Logs. */
final class RequestStats {

	/**
	 * Estados HTTP que cuentan como escucha (respuesta completa o parcial).
	 *
	 * @var array<int, int>
	 */
	public const COUNTED_STATUSES = array( 200, 206 );

	/**
	 * Períodos permitidos (en días) en el Dashboard.
	 *
	 * @var array<int, int>
	 */
	public const ALLOWED_DAYS = array( 7, 30, 90 );

	public const CACHE_TTL            = HOUR_IN_SECONDS;
	public const CACHE_VERSION_OPTION = 'lb_sp_stats_version';
	public const CACHE_KEY_PREFIX     = 'lb_sp_stats_';

	private const SCAN_BATCH_SIZE = 2000;
	private const MAX_SCAN_ROWS   = 100000;

	/**
	 * Devuelve un período válido (7, 30 o 90 días).
	 *
	 * @param int $days Días solicitados.
	 */
	public static function normalize_days( int $days ): int {
		return in_array( $days, self::ALLOWED_DAYS, true ) ? $days : 30;
	}

	/**
	 * Versión actual de la caché de estadísticas.
	 */
	public static function cache_version(): string {
		$version = get_option( self::CACHE_VERSION_OPTION, '' );
		if ( ! is_string( $version ) || '' === $version ) {
			return self::invalidate_cache();
		}
		return $version;
	}

	/**
	 * Invalida todos los transients de estadísticas incrementando la versión.
	 */
	public static function invalidate_cache(): string {
		$version = sprintf( '%.6F', microtime( true ) );
		update_option( self::CACHE_VERSION_OPTION, $version, false );
		return $version;
	}

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
	 * Lista de episodios publicados con audio para el filtro del Dashboard.
	 *
	 * @param int $limit Máximo de entradas.
	 * @return array<int, array{id: int, title: string}>
	 */
	public function episodes_with_audio( int $limit = 200 ): array {
		$query = new WP_Query( $this->audio_query_args( max( 1, $limit ), false ) );
		$out   = array();
		foreach ( $query->posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$title = get_the_title( $post );
				$out[] = array(
					'id'    => $post->ID,
					'title' => '' !== $title ? $title : sprintf( '#%d', $post->ID ),
				);
			}
		}
		return $out;
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
	 * Métricas agregadas, series diarias, clientes y top episodios para el Dashboard.
	 *
	 * Excluye peticiones HEAD y bots conocidos de los KPIs principales y las gráficas
	 * de escuchas, reportándolos por separado en el resumen de ruido/entrega.
	 * Se cachea durante {@see self::CACHE_TTL} (1 hora).
	 *
	 * @param int $days    Ventana de días (7, 30 o 90).
	 * @param int $post_id Filtrar por una entrada concreta (0 = todas).
	 * @return array{
	 *   days: int,
	 *   post_id: int,
	 *   generated_at: int,
	 *   truncated: bool,
	 *   summary: array{
	 *     estimated_listens: int,
	 *     get_requests: int,
	 *     unique_listeners: int,
	 *     head_requests: int,
	 *     bot_requests: int,
	 *     error_requests: int,
	 *     previous: array{estimated_listens: int, get_requests: int, unique_listeners: int}
	 *   },
	 *   daily: array<int, array{date: string, label: string, listens: int, requests: int, noise: int}>,
	 *   published: array<int, array{date: string, post_id: int, title: string}>,
	 *   top_episodes: array<int, array{post_id: int, listens: int, requests: int}>,
	 *   clients: array<int, array{key: string, label: string, listens: int, requests: int, percent: float}>,
	 *   statuses: array<int, array{status: int, count: int}>
	 * }
	 */
	public function dashboard_data( int $days = 30, int $post_id = 0 ): array {
		$days    = self::normalize_days( $days );
		$post_id = max( 0, $post_id );
		$tz      = wp_timezone();
		$key     = self::CACHE_KEY_PREFIX . md5( self::cache_version() . '|' . $days . '|' . $post_id . '|' . $tz->getName() );
		$cached  = get_transient( $key );
		if ( is_array( $cached ) && isset( $cached['summary'], $cached['daily'] ) ) {
			/**
			 * Datos cacheados del Dashboard.
			 *
			 * @var array{
			 *   days: int,
			 *   post_id: int,
			 *   generated_at: int,
			 *   truncated: bool,
			 *   summary: array{
			 *     estimated_listens: int,
			 *     get_requests: int,
			 *     unique_listeners: int,
			 *     head_requests: int,
			 *     bot_requests: int,
			 *     error_requests: int,
			 *     previous: array{estimated_listens: int, get_requests: int, unique_listeners: int}
			 *   },
			 *   daily: array<int, array{date: string, label: string, listens: int, requests: int, noise: int}>,
			 *   published: array<int, array{date: string, post_id: int, title: string}>,
			 *   top_episodes: array<int, array{post_id: int, listens: int, requests: int}>,
			 *   clients: array<int, array{key: string, label: string, listens: int, requests: int, percent: float}>,
			 *   statuses: array<int, array{status: int, count: int}>
			 * } $cached
			 */
			return $cached;
		}

		$utc         = new DateTimeZone( 'UTC' );
		$now_local   = new DateTimeImmutable( 'now', $tz );
		$today_start = $now_local->setTime( 0, 0, 0 );
		$cur_start   = $today_start->modify( '-' . ( $days - 1 ) . ' days' );
		$prev_start  = $cur_start->modify( '-' . $days . ' days' );
		$cur_end     = $today_start->modify( '+1 day' );

		$current  = $this->aggregate_window( $cur_start, $cur_end, $post_id, $tz, $utc, true );
		$previous = $this->aggregate_window( $prev_start, $cur_start, $post_id, $tz, $utc, false );

		$daily = array();
		for ( $i = 0; $i < $days; $i++ ) {
			$day_dt  = $cur_start->modify( '+' . $i . ' days' );
			$ymd     = $day_dt->format( 'Y-m-d' );
			$bucket  = $current['daily'][ $ymd ] ?? array(
				'listens'  => 0,
				'requests' => 0,
				'noise'    => 0,
			);
			$daily[] = array(
				'date'     => $ymd,
				'label'    => (string) wp_date( 'j M', $day_dt->getTimestamp(), $tz ),
				'listens'  => $bucket['listens'],
				'requests' => $bucket['requests'],
				'noise'    => $bucket['noise'],
			);
		}

		$data = array(
			'days'         => $days,
			'post_id'      => $post_id,
			'generated_at' => time(),
			'truncated'    => $current['truncated'] || $previous['truncated'],
			'summary'      => array(
				'estimated_listens' => $current['estimated_listens'],
				'get_requests'      => $current['get_requests'],
				'unique_listeners'  => $current['unique_listeners'],
				'head_requests'     => $current['head_requests'],
				'bot_requests'      => $current['bot_requests'],
				'error_requests'    => $current['error_requests'],
				'previous'          => array(
					'estimated_listens' => $previous['estimated_listens'],
					'get_requests'      => $previous['get_requests'],
					'unique_listeners'  => $previous['unique_listeners'],
				),
			),
			'daily'        => $daily,
			'published'    => $this->published_dates_in_range( $cur_start, $cur_end, $post_id, $tz ),
			'top_episodes' => $current['top_episodes'],
			'clients'      => $current['clients'],
			'statuses'     => $current['statuses'],
		);

		set_transient( $key, $data, self::CACHE_TTL );
		return $data;
	}

	/**
	 * Indica si un post es un episodio público apto para mostrarse en estadísticas públicas.
	 *
	 * Exige entrada publicada (`publish`), del tipo del plugin, sin contraseña y con MP3 válido.
	 * No depende del usuario conectado para evitar filtrar borradores o privados vía caché compartida.
	 *
	 * @param int $post_id ID de la entrada.
	 */
	public static function is_public_episode( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return false;
		}
		if ( AudioMeta::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return false;
		}
		if ( '' !== $post->post_password ) {
			return false;
		}
		return null !== AudioMeta::get_audio_data( $post->ID );
	}

	/**
	 * Devuelve únicamente métricas agregadas aptas para el frontend público (sin PII ni datos internos).
	 *
	 * Excluye direcciones IP, cabeceras, User-Agent crudos, errores HTTP, tráfico de bots/HEAD
	 * y cualquier episodio que no esté publicado y sin contraseña.
	 *
	 * @param int $days      Ventana de días (7, 30 o 90).
	 * @param int $post_id   Filtrar por un episodio concreto (0 = todos).
	 * @param int $top_limit Máximo de episodios en el ranking (1..10).
	 * @return array{
	 *   eligible: bool,
	 *   days: int,
	 *   post_id: int,
	 *   published_audios: int,
	 *   estimated_listens: int,
	 *   unique_listeners: int,
	 *   top_episodes: array<int, array{post_id: int, title: string, url: string, listens: int}>,
	 *   clients: array<int, array{key: string, label: string, listens: int, percent: float}>
	 * }
	 */
	public function public_stats( int $days = 30, int $post_id = 0, int $top_limit = 5 ): array {
		$days      = self::normalize_days( $days );
		$post_id   = max( 0, $post_id );
		$top_limit = min( 10, max( 1, $top_limit ) );

		if ( $post_id > 0 && ! self::is_public_episode( $post_id ) ) {
			return array(
				'eligible'          => false,
				'days'              => $days,
				'post_id'           => $post_id,
				'published_audios'  => 0,
				'estimated_listens' => 0,
				'unique_listeners'  => 0,
				'top_episodes'      => array(),
				'clients'           => array(),
			);
		}

		$data    = $this->dashboard_data( $days, $post_id );
		$summary = $data['summary'];

		$top_episodes = array();
		if ( 0 === $post_id ) {
			foreach ( $data['top_episodes'] as $row ) {
				$ep_id = (int) $row['post_id'];
				if ( ! self::is_public_episode( $ep_id ) ) {
					continue;
				}
				$ep_post = get_post( $ep_id );
				if ( ! $ep_post instanceof WP_Post ) {
					continue;
				}
				$title          = get_the_title( $ep_post );
				$url            = get_permalink( $ep_post );
				$top_episodes[] = array(
					'post_id' => $ep_post->ID,
					'title'   => '' !== $title ? $title : sprintf( '#%d', $ep_post->ID ),
					'url'     => '' !== $url ? $url : '',
					'listens' => max( 0, (int) $row['listens'] ),
				);
				if ( count( $top_episodes ) >= $top_limit ) {
					break;
				}
			}
		}

		$clients = array();
		foreach ( $data['clients'] as $client ) {
			if ( 'bot' === $client['key'] ) {
				continue;
			}
			$clients[] = array(
				'key'     => (string) $client['key'],
				'label'   => (string) $client['label'],
				'listens' => max( 0, (int) $client['listens'] ),
				'percent' => max( 0.0, min( 100.0, (float) $client['percent'] ) ),
			);
		}

		return array(
			'eligible'          => true,
			'days'              => $days,
			'post_id'           => $post_id,
			'published_audios'  => 0 === $post_id ? $this->count_published_audios() : 0,
			'estimated_listens' => max( 0, (int) $summary['estimated_listens'] ),
			'unique_listeners'  => max( 0, (int) $summary['unique_listeners'] ),
			'top_episodes'      => $top_episodes,
			'clients'           => $clients,
		);
	}

	/**
	 * Extrae el User-Agent del JSON de cabeceras (insensible a mayúsculas).
	 *
	 * @param string $headers_json Cabeceras en JSON.
	 */
	public static function extract_user_agent( string $headers_json ): string {
		if ( '' === $headers_json || '{}' === $headers_json ) {
			return '';
		}
		$decoded = json_decode( $headers_json, true );
		if ( ! is_array( $decoded ) ) {
			return '';
		}
		foreach ( $decoded as $name => $value ) {
			if ( is_string( $name ) && 'user-agent' === strtolower( $name ) && is_scalar( $value ) ) {
				return trim( (string) $value );
			}
		}
		return '';
	}

	/**
	 * Indica si un User-Agent pertenece a un bot, crawler o cliente automatizado conocido.
	 *
	 * Un User-Agent vacío no se clasifica como bot para no descartar reproductores minimalistas.
	 *
	 * @param string $user_agent Cabecera User-Agent.
	 */
	public static function is_bot_user_agent( string $user_agent ): bool {
		if ( '' === $user_agent ) {
			return false;
		}
		return 1 === preg_match(
			'/\b(bot|crawler|spider|slurp|scraper|archiver|fetcher|monitor|uptime|probe|scan|validator|check_http)\b|curl\/|wget\/|python-requests|python-urllib|aiohttp|httpx|go-http-client|java\/|libwww-perl|node-fetch|axios\/|postmanruntime|headlesschrome|phantomjs|lighthouse|pagespeed|googlebot|bingbot|bingpreview|yandexbot|baiduspider|duckduckbot|applebot|semrushbot|ahrefsbot|mj12bot|dotbot|facebookexternalhit|twitterbot|linkedinbot|whatsapp|telegrambot|slackbot|discordbot|uptimerobot|pingdom|statuscake|feedvalidator|castfeedvalidator|podcastindex/i',
			$user_agent
		);
	}

	/**
	 * Clasifica un User-Agent de oyente (no bot) en una categoría legible.
	 *
	 * @param string $user_agent Cabecera User-Agent.
	 * @return array{key: string, label: string}
	 */
	public static function classify_client( string $user_agent ): array {
		if ( '' === $user_agent ) {
			return array(
				'key'   => 'unknown',
				'label' => __( 'Sin identificar', 'lb-simple-podcast' ),
			);
		}
		if ( self::is_bot_user_agent( $user_agent ) ) {
			return array(
				'key'   => 'bot',
				'label' => __( 'Bots / Scripts', 'lb-simple-podcast' ),
			);
		}

		$podcast_apps = array(
			array( 'apple_podcasts', 'Apple Podcasts', '/AppleCoreMedia|Podcasts\/|iTunes\//i' ),
			array( 'spotify', 'Spotify', '/Spotify\//i' ),
			array( 'pocket_casts', 'Pocket Casts', '/Pocket\s?Casts/i' ),
			array( 'overcast', 'Overcast', '/Overcast/i' ),
			array( 'antennapod', 'AntennaPod', '/AntennaPod/i' ),
			array( 'podcast_addict', 'Podcast Addict', '/Podcast\s?Addict/i' ),
			array( 'castbox', 'Castbox', '/Castbox/i' ),
			array( 'castro', 'Castro', '/Castro/i' ),
			array( 'youtube_music', 'YouTube Music', '/YouTubeMusic/i' ),
			array( 'other_podcast', __( 'Otras apps de podcast', 'lb-simple-podcast' ), '/Player\s?FM|Stitcher|Downcast|Podbean|Deezer|TuneIn|iHeartRadio|Audible|Breaker|Podchaser|Feedly/i' ),
		);
		foreach ( $podcast_apps as $app ) {
			if ( 1 === preg_match( $app[2], $user_agent ) ) {
				return array(
					'key'   => $app[0],
					'label' => $app[1],
				);
			}
		}

		if ( 1 === preg_match( '/Edg(?:e|A|iOS)?\//i', $user_agent ) ) {
			return array(
				'key'   => 'browser_edge',
				'label' => __( 'Navegador (Edge)', 'lb-simple-podcast' ),
			);
		}
		if ( 1 === preg_match( '/OPR\/|Opera/i', $user_agent ) ) {
			return array(
				'key'   => 'browser_other',
				'label' => __( 'Otros navegadores', 'lb-simple-podcast' ),
			);
		}
		if ( 1 === preg_match( '/(?:Chrome|CriOS)\//i', $user_agent ) ) {
			return array(
				'key'   => 'browser_chrome',
				'label' => __( 'Navegador (Chrome)', 'lb-simple-podcast' ),
			);
		}
		if ( 1 === preg_match( '/(?:Firefox|FxiOS)\//i', $user_agent ) ) {
			return array(
				'key'   => 'browser_firefox',
				'label' => __( 'Navegador (Firefox)', 'lb-simple-podcast' ),
			);
		}
		if ( 1 === preg_match( '/Safari\//i', $user_agent ) ) {
			return array(
				'key'   => 'browser_safari',
				'label' => __( 'Navegador (Safari)', 'lb-simple-podcast' ),
			);
		}

		return array(
			'key'   => 'other',
			'label' => __( 'Otros clientes', 'lb-simple-podcast' ),
		);
	}

	/**
	 * Agrega las filas de la ventana temporal en lotes con cursor por ID.
	 *
	 * @param DateTimeImmutable $start_local  Inicio inclusivo en zona local.
	 * @param DateTimeImmutable $end_local    Fin exclusivo en zona local.
	 * @param int               $post_id      Filtro por entrada (0 = todas).
	 * @param DateTimeZone      $tz           Zona horaria del sitio.
	 * @param DateTimeZone      $utc          Zona horaria UTC.
	 * @param bool              $with_details Calcular series diarias, clientes, top y estados.
	 * @return array{
	 *   truncated: bool,
	 *   estimated_listens: int,
	 *   get_requests: int,
	 *   unique_listeners: int,
	 *   head_requests: int,
	 *   bot_requests: int,
	 *   error_requests: int,
	 *   daily: array<string, array{listens: int, requests: int, noise: int}>,
	 *   top_episodes: array<int, array{post_id: int, listens: int, requests: int}>,
	 *   clients: array<int, array{key: string, label: string, listens: int, requests: int, percent: float}>,
	 *   statuses: array<int, array{status: int, count: int}>
	 * }
	 */
	private function aggregate_window(
		DateTimeImmutable $start_local,
		DateTimeImmutable $end_local,
		int $post_id,
		DateTimeZone $tz,
		DateTimeZone $utc,
		bool $with_details
	): array {
		global $wpdb;
		$table     = RequestLog::table();
		$start_utc = $start_local->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		$end_utc   = $end_local->setTimezone( $utc )->format( 'Y-m-d H:i:s' );

		$estimated_listens = 0;
		$get_requests      = 0;
		$head_requests     = 0;
		$bot_requests      = 0;
		$error_requests    = 0;
		$truncated         = false;

		/**
		 * Claves de sesión únicas por episodio, día, IP y User-Agent.
		 *
		 * @var array<string, true> $listen_sessions
		 */
		$listen_sessions = array();
		/**
		 * IPs únicas de oyentes.
		 *
		 * @var array<string, true> $listener_ips
		 */
		$listener_ips = array();
		/**
		 * Acumuladores por fecha local.
		 *
		 * @var array<string, array{listens: int, requests: int, noise: int}> $daily
		 */
		$daily = array();
		/**
		 * Acumuladores por episodio.
		 *
		 * @var array<int, array{listens: int, requests: int}> $episode_map
		 */
		$episode_map = array();
		/**
		 * Acumuladores por cliente.
		 *
		 * @var array<string, array{key: string, label: string, listens: int, requests: int}> $client_map
		 */
		$client_map = array();
		/**
		 * Acumuladores por estado HTTP.
		 *
		 * @var array<int, int> $status_map
		 */
		$status_map = array();
		/**
		 * Caché por hora UTC -> fecha local.
		 *
		 * @var array<string, string> $hour_to_ymd
		 */
		$hour_to_ymd = array();

		$last_id = 0;
		$scanned = 0;

		while ( true ) {
			if ( $post_id > 0 ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, requested_at, post_id, method, remote_addr, headers, status FROM `$table` WHERE requested_at >= %s AND requested_at < %s AND post_id = %d AND id > %d ORDER BY id ASC LIMIT %d", $start_utc, $end_utc, $post_id, $last_id, self::SCAN_BATCH_SIZE ), ARRAY_A );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, requested_at, post_id, method, remote_addr, headers, status FROM `$table` WHERE requested_at >= %s AND requested_at < %s AND id > %d ORDER BY id ASC LIMIT %d", $start_utc, $end_utc, $last_id, self::SCAN_BATCH_SIZE ), ARRAY_A );
			}

			if ( ! is_array( $rows ) || array() === $rows ) {
				break;
			}

			$batch_count = count( $rows );
			$scanned    += $batch_count;

			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$row_id  = (int) $row['id'];
				$last_id = $row_id;

				$req_at  = (string) $row['requested_at'];
				$row_pid = (int) $row['post_id'];
				$method  = strtoupper( (string) $row['method'] );
				$ip      = trim( (string) $row['remote_addr'] );
				$status  = (int) $row['status'];
				$ua      = self::extract_user_agent( (string) $row['headers'] );
				$is_bot  = self::is_bot_user_agent( $ua );

				$ymd = $this->utc_to_local_ymd( $req_at, $utc, $tz, $hour_to_ymd );

				if ( $with_details ) {
					$status_map[ $status ] = ( $status_map[ $status ] ?? 0 ) + 1;
					if ( ! isset( $daily[ $ymd ] ) ) {
						$daily[ $ymd ] = array(
							'listens'  => 0,
							'requests' => 0,
							'noise'    => 0,
						);
					}
				}

				if ( 'HEAD' === $method ) {
					++$head_requests;
					if ( $with_details ) {
						++$daily[ $ymd ]['noise'];
					}
					continue;
				}

				if ( $status >= 400 || 302 === $status ) {
					++$error_requests;
				}

				if ( 'GET' !== $method || ! in_array( $status, self::COUNTED_STATUSES, true ) ) {
					continue;
				}

				if ( $is_bot ) {
					++$bot_requests;
					if ( $with_details ) {
						++$daily[ $ymd ]['noise'];
					}
					continue;
				}

				++$get_requests;
				if ( '' !== $ip ) {
					$listener_ips[ $ip ] = true;
				}

				$identity    = '' !== $ip ? $ip . '|' . strtolower( $ua ) : 'row_' . $row_id;
				$session_key = $row_pid . '|' . $ymd . '|' . $identity;
				$is_new_play = ! isset( $listen_sessions[ $session_key ] );
				if ( $is_new_play ) {
					$listen_sessions[ $session_key ] = true;
					++$estimated_listens;
				}

				if ( $with_details ) {
					++$daily[ $ymd ]['requests'];
					if ( $is_new_play ) {
						++$daily[ $ymd ]['listens'];
					}

					if ( $row_pid > 0 ) {
						if ( ! isset( $episode_map[ $row_pid ] ) ) {
							$episode_map[ $row_pid ] = array(
								'listens'  => 0,
								'requests' => 0,
							);
						}
						++$episode_map[ $row_pid ]['requests'];
						if ( $is_new_play ) {
							++$episode_map[ $row_pid ]['listens'];
						}
					}

					$client     = self::classify_client( $ua );
					$client_key = $client['key'];
					if ( ! isset( $client_map[ $client_key ] ) ) {
						$client_map[ $client_key ] = array(
							'key'      => $client_key,
							'label'    => $client['label'],
							'listens'  => 0,
							'requests' => 0,
						);
					}
					++$client_map[ $client_key ]['requests'];
					if ( $is_new_play ) {
						++$client_map[ $client_key ]['listens'];
					}
				}
			}

			if ( $batch_count < self::SCAN_BATCH_SIZE ) {
				break;
			}
			if ( $scanned >= self::MAX_SCAN_ROWS ) {
				$truncated = true;
				break;
			}
		}

		$top_episodes = array();
		$clients      = array();
		$statuses     = array();

		if ( $with_details ) {
			foreach ( $episode_map as $ep_id => $counts ) {
				$top_episodes[] = array(
					'post_id'  => $ep_id,
					'listens'  => $counts['listens'],
					'requests' => $counts['requests'],
				);
			}
			usort(
				$top_episodes,
				static function ( array $a, array $b ): int {
					if ( $b['listens'] !== $a['listens'] ) {
						return $b['listens'] <=> $a['listens'];
					}
					if ( $b['requests'] !== $a['requests'] ) {
						return $b['requests'] <=> $a['requests'];
					}
					return $a['post_id'] <=> $b['post_id'];
				}
			);
			$top_episodes = array_slice( $top_episodes, 0, 10 );

			$clients = $this->format_clients( $client_map, $estimated_listens );

			ksort( $status_map );
			foreach ( $status_map as $code => $count ) {
				$statuses[] = array(
					'status' => $code,
					'count'  => $count,
				);
			}
		}

		return array(
			'truncated'         => $truncated,
			'estimated_listens' => $estimated_listens,
			'get_requests'      => $get_requests,
			'unique_listeners'  => count( $listener_ips ),
			'head_requests'     => $head_requests,
			'bot_requests'      => $bot_requests,
			'error_requests'    => $error_requests,
			'daily'             => $daily,
			'top_episodes'      => $top_episodes,
			'clients'           => $clients,
			'statuses'          => $statuses,
		);
	}

	/**
	 * Ordena los clientes y agrupa la cola en "Otros clientes" si supera 6 categorías.
	 *
	 * @param array<string, array{key: string, label: string, listens: int, requests: int}> $client_map    Mapa por clave.
	 * @param int                                                                           $total_listens Total de escuchas estimadas.
	 * @return array<int, array{key: string, label: string, listens: int, requests: int, percent: float}>
	 */
	private function format_clients( array $client_map, int $total_listens ): array {
		if ( array() === $client_map ) {
			return array();
		}
		$items = array_values( $client_map );
		usort(
			$items,
			static function ( array $a, array $b ): int {
				if ( $b['listens'] !== $a['listens'] ) {
					return $b['listens'] <=> $a['listens'];
				}
				return $b['requests'] <=> $a['requests'];
			}
		);

		$max_slices = 6;
		if ( count( $items ) > $max_slices ) {
			$head        = array_slice( $items, 0, $max_slices - 1 );
			$tail        = array_slice( $items, $max_slices - 1 );
			$other_plays = 0;
			$other_reqs  = 0;
			foreach ( $tail as $row ) {
				$other_plays += $row['listens'];
				$other_reqs  += $row['requests'];
			}
			$head[] = array(
				'key'      => 'other_grouped',
				'label'    => __( 'Otros clientes', 'lb-simple-podcast' ),
				'listens'  => $other_plays,
				'requests' => $other_reqs,
			);
			$items  = $head;
		}

		$denom = max( 1, $total_listens );
		$out   = array();
		foreach ( $items as $item ) {
			$out[] = array(
				'key'      => $item['key'],
				'label'    => $item['label'],
				'listens'  => $item['listens'],
				'requests' => $item['requests'],
				'percent'  => round( ( $item['listens'] / $denom ) * 100, 1 ),
			);
		}
		return $out;
	}

	/**
	 * Episodios con audio publicados dentro del rango para marcarlos en la gráfica diaria.
	 *
	 * @param DateTimeImmutable $start_local Inicio inclusivo.
	 * @param DateTimeImmutable $end_local   Fin exclusivo.
	 * @param int               $post_id     Filtro por post (0 = todos).
	 * @param DateTimeZone      $tz          Zona horaria del sitio.
	 * @return array<int, array{date: string, post_id: int, title: string}>
	 */
	private function published_dates_in_range( DateTimeImmutable $start_local, DateTimeImmutable $end_local, int $post_id, DateTimeZone $tz ): array {
		$args = $this->audio_query_args( 50, false );
		if ( $post_id > 0 ) {
			$args['p'] = $post_id;
		}
		$args['date_query'] = array(
			array(
				'after'     => $start_local->format( 'Y-m-d H:i:s' ),
				'before'    => $end_local->modify( '-1 second' )->format( 'Y-m-d H:i:s' ),
				'inclusive' => true,
			),
		);

		$query = new WP_Query( $args );
		$out   = array();
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $post->post_date, $tz );
			if ( false === $dt ) {
				continue;
			}
			$title = get_the_title( $post );
			$out[] = array(
				'date'    => $dt->format( 'Y-m-d' ),
				'post_id' => $post->ID,
				'title'   => '' !== $title ? $title : sprintf( '#%d', $post->ID ),
			);
		}
		return $out;
	}

	/**
	 * Convierte una marca UTC a fecha local YYYY-MM-DD usando caché por hora UTC.
	 *
	 * @param string                $utc_datetime Fecha MySQL UTC.
	 * @param DateTimeZone          $utc          UTC.
	 * @param DateTimeZone          $tz           Zona local.
	 * @param array<string, string> $cache        Caché local por hora.
	 */
	private function utc_to_local_ymd( string $utc_datetime, DateTimeZone $utc, DateTimeZone $tz, array &$cache ): string {
		$hour_key = substr( $utc_datetime, 0, 13 );
		if ( isset( $cache[ $hour_key ] ) ) {
			return $cache[ $hour_key ];
		}
		$dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $utc_datetime, $utc );
		if ( false === $dt ) {
			$ymd = substr( $utc_datetime, 0, 10 );
		} else {
			$ymd = $dt->setTimezone( $tz )->format( 'Y-m-d' );
		}
		$cache[ $hour_key ] = $ymd;
		return $ymd;
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
