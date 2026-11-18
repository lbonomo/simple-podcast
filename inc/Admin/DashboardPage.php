<?php
/**
 * Dashboard con estadísticas generales y gráficas.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Admin;

use LbSimplePodcast\Tracking\RequestLog;
use LbSimplePodcast\Tracking\RequestStats;
use WP_Post;

/** Muestra KPIs, tendencia diaria, clientes y top episodios. */
final class DashboardPage {

	public const DAYS           = 30;
	public const REFRESH_ACTION = 'lb_sp_refresh_stats';
	public const REFRESH_NONCE  = 'lb_sp_refresh_stats';

	/**
	 * Paleta accesible para el desglose de clientes.
	 *
	 * @var array<int, string>
	 */
	private const CLIENT_COLORS = array(
		'#2271b1',
		'#00a32a',
		'#b26b00',
		'#7f54b3',
		'#d63638',
		'#50575e',
	);

	/**
	 * Encola estilos, script y datos JSON de las gráficas solo en el Dashboard.
	 *
	 * @param string $hook_suffix Pantalla actual.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( 'toplevel_page_' . Menu::PARENT_SLUG !== $hook_suffix ) {
			return;
		}
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			return;
		}

		$filters = $this->current_filters();
		$data    = ( new RequestStats() )->dashboard_data( $filters['days'], $filters['post_id'] );
		$payload = $this->chart_payload( $data );
		$json    = wp_json_encode( $payload );

		wp_enqueue_style( 'lb-sp-dashboard', LB_SP_URL . 'assets/css/dashboard.css', array(), LB_SP_VERSION );
		wp_enqueue_script( 'lb-sp-dashboard', LB_SP_URL . 'assets/js/dashboard.js', array(), LB_SP_VERSION, true );
		wp_add_inline_script(
			'lb-sp-dashboard',
			'window.lbSpDashboard = ' . ( false !== $json ? $json : '{}' ) . ';',
			'before'
		);
	}

	/** Invalida la caché de estadísticas y vuelve al Dashboard conservando los filtros. */
	public function handle_refresh(): void {
		Menu::authorize();
		check_admin_referer( self::REFRESH_NONCE );

		RequestStats::invalidate_cache();

		$days    = isset( $_POST['days'] ) ? RequestStats::normalize_days( absint( wp_unslash( $_POST['days'] ) ) ) : self::DAYS; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verificado arriba.
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verificado arriba.

		$args = array(
			'page' => Menu::PARENT_SLUG,
			'days' => $days,
		);
		if ( $post_id > 0 ) {
			$args['post_id'] = $post_id;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Dibuja la página. */
	public function render(): void {
		Menu::authorize();

		$filters         = $this->current_filters();
		$days            = $filters['days'];
		$post_id         = $filters['post_id'];
		$stats           = new RequestStats();
		$data            = $stats->dashboard_data( $days, $post_id );
		$summary         = $data['summary'];
		$previous        = $summary['previous'];
		$published_count = $stats->count_published_audios();
		$latest          = $stats->latest_audio();
		$episodes        = $stats->episodes_with_audio();
		$clients         = $this->decorate_clients( $data['clients'] );
		$retention_days  = (int) apply_filters( 'lb_sp_log_retention_days', RequestLog::DEFAULT_RETENTION_DAYS );
		$fmt             = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$has_trend_data  = $this->has_trend_data( $data['daily'] );
		?>
		<div class="wrap lb-sp-dashboard">
			<div class="lb-sp-dashboard__header">
				<h1><?php esc_html_e( 'Podcast: Dashboard', 'lb-simple-podcast' ); ?></h1>

				<div class="lb-sp-dashboard__toolbar">
					<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="lb-sp-dashboard__filters">
						<input type="hidden" name="page" value="<?php echo esc_attr( Menu::PARENT_SLUG ); ?>" />
						<label for="lb-sp-filter-days"><?php esc_html_e( 'Período', 'lb-simple-podcast' ); ?></label>
						<select id="lb-sp-filter-days" name="days">
							<?php foreach ( RequestStats::ALLOWED_DAYS as $option_days ) : ?>
								<option value="<?php echo esc_attr( (string) $option_days ); ?>" <?php selected( $days, $option_days ); ?>>
									<?php
									/* translators: %d: cantidad de días. */
									echo esc_html( sprintf( __( 'Últimos %d días', 'lb-simple-podcast' ), $option_days ) );
									?>
								</option>
							<?php endforeach; ?>
						</select>

						<label for="lb-sp-filter-post"><?php esc_html_e( 'Episodio', 'lb-simple-podcast' ); ?></label>
						<select id="lb-sp-filter-post" name="post_id">
							<option value="0" <?php selected( $post_id, 0 ); ?>><?php esc_html_e( 'Todos los episodios', 'lb-simple-podcast' ); ?></option>
							<?php foreach ( $episodes as $ep ) : ?>
								<option value="<?php echo esc_attr( (string) $ep['id'] ); ?>" <?php selected( $post_id, $ep['id'] ); ?>>
									<?php echo esc_html( $ep['title'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>

						<button type="submit" class="button"><?php esc_html_e( 'Aplicar', 'lb-simple-podcast' ); ?></button>
						<?php if ( $post_id > 0 || self::DAYS !== $days ) : ?>
							<a class="button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::PARENT_SLUG ) ); ?>">
								<?php esc_html_e( 'Restablecer', 'lb-simple-podcast' ); ?>
							</a>
						<?php endif; ?>
					</form>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lb-sp-dashboard__refresh">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::REFRESH_ACTION ); ?>" />
						<input type="hidden" name="days" value="<?php echo esc_attr( (string) $days ); ?>" />
						<input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $post_id ); ?>" />
						<?php wp_nonce_field( self::REFRESH_NONCE ); ?>
						<span class="description">
							<?php
							/* translators: %s: fecha y hora de actualización de la caché. */
							echo esc_html( sprintf( __( 'Caché 1 h · %s', 'lb-simple-podcast' ), (string) wp_date( $fmt, $data['generated_at'] ) ) );
							?>
						</span>
						<button type="submit" class="button button-secondary"><?php esc_html_e( 'Actualizar datos', 'lb-simple-podcast' ); ?></button>
					</form>
				</div>
			</div>

			<?php if ( $retention_days > 0 && $days > $retention_days ) : ?>
				<div class="notice notice-info inline">
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: días seleccionados, 2: días de retención de logs. */
								__( 'El período seleccionado (%1$d días) supera la retención actual de registros (%2$d días); los días anteriores ya fueron purgados.', 'lb-simple-podcast' ),
								$days,
								$retention_days
							)
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( $data['truncated'] ) : ?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'El cálculo se acotó a los registros más antiguos del tope de análisis por ventana para proteger la memoria del servidor.', 'lb-simple-podcast' ); ?></p>
				</div>
			<?php endif; ?>

			<section aria-labelledby="lb-sp-kpi-heading" class="lb-sp-kpi-section">
				<h2 id="lb-sp-kpi-heading" class="screen-reader-text"><?php esc_html_e( 'Resumen de métricas', 'lb-simple-podcast' ); ?></h2>
				<div class="lb-sp-kpi-grid">
					<?php
					$this->kpi_card(
						__( 'Escuchas estimadas', 'lb-simple-podcast' ),
						number_format_i18n( $summary['estimated_listens'] ),
						$this->delta_info( $summary['estimated_listens'], $previous['estimated_listens'], $days ),
						__( 'Sesiones únicas GET (200/206) por episodio, día, IP y agente; excluye HEAD y bots.', 'lb-simple-podcast' )
					);
					$this->kpi_card(
						__( 'Solicitudes de audio (GET)', 'lb-simple-podcast' ),
						number_format_i18n( $summary['get_requests'] ),
						$this->delta_info( $summary['get_requests'], $previous['get_requests'], $days ),
						__( 'Peticiones HTTP GET 200/206 de reproductores (incluye múltiples rangos por escucha).', 'lb-simple-podcast' )
					);
					$this->kpi_card(
						__( 'Oyentes únicos (IP)', 'lb-simple-podcast' ),
						number_format_i18n( $summary['unique_listeners'] ),
						$this->delta_info( $summary['unique_listeners'], $previous['unique_listeners'], $days ),
						__( 'Direcciones IP distintas con descargas o reproducciones GET válidas en el período.', 'lb-simple-podcast' )
					);
					$this->published_card( $published_count, $latest );
					?>
				</div>
			</section>

			<section class="lb-sp-card lb-sp-card--chart" aria-labelledby="lb-sp-trend-heading">
				<div class="lb-sp-card__head">
					<div>
						<h2 id="lb-sp-trend-heading">
							<?php
							/* translators: %d: cantidad de días. */
							echo esc_html( sprintf( __( 'Evolución diaria (últimos %d días)', 'lb-simple-podcast' ), $days ) );
							?>
						</h2>
						<p class="description">
							<?php esc_html_e( 'Pasa el cursor o navega con Tab sobre los puntos para ver el detalle diario y los episodios publicados.', 'lb-simple-podcast' ); ?>
						</p>
					</div>
					<?php if ( $has_trend_data ) : ?>
						<fieldset class="lb-sp-series-toggles">
							<legend class="screen-reader-text"><?php esc_html_e( 'Series visibles en la gráfica', 'lb-simple-podcast' ); ?></legend>
							<label class="lb-sp-toggle">
								<input type="checkbox" data-lb-sp-series="listens" checked />
								<span class="lb-sp-swatch lb-sp-swatch--listens" aria-hidden="true"></span>
								<?php esc_html_e( 'Escuchas estimadas', 'lb-simple-podcast' ); ?>
							</label>
							<label class="lb-sp-toggle">
								<input type="checkbox" data-lb-sp-series="requests" checked />
								<span class="lb-sp-swatch lb-sp-swatch--requests" aria-hidden="true"></span>
								<?php esc_html_e( 'Solicitudes GET', 'lb-simple-podcast' ); ?>
							</label>
							<label class="lb-sp-toggle">
								<input type="checkbox" data-lb-sp-series="noise" />
								<span class="lb-sp-swatch lb-sp-swatch--noise" aria-hidden="true"></span>
								<?php esc_html_e( 'Ruido (HEAD + bots)', 'lb-simple-podcast' ); ?>
							</label>
						</fieldset>
					<?php endif; ?>
				</div>

				<?php if ( ! $has_trend_data ) : ?>
					<p class="lb-sp-empty"><?php esc_html_e( 'Sin solicitudes registradas en este período.', 'lb-simple-podcast' ); ?></p>
				<?php else : ?>
					<figure class="lb-sp-chart-figure">
						<div id="lb-sp-trend-chart" class="lb-sp-chart-canvas"></div>
						<figcaption class="screen-reader-text">
							<?php esc_html_e( 'Gráfica de evolución diaria de escuchas estimadas y solicitudes de audio.', 'lb-simple-podcast' ); ?>
						</figcaption>
					</figure>
					<details class="lb-sp-table-fallback">
						<summary><?php esc_html_e( 'Ver tabla de datos diarios', 'lb-simple-podcast' ); ?></summary>
						<div class="lb-sp-table-scroll">
							<table class="widefat striped">
								<caption class="screen-reader-text"><?php esc_html_e( 'Datos diarios del período seleccionado', 'lb-simple-podcast' ); ?></caption>
								<thead>
									<tr>
										<th scope="col"><?php esc_html_e( 'Fecha', 'lb-simple-podcast' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Escuchas estimadas', 'lb-simple-podcast' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Solicitudes GET', 'lb-simple-podcast' ); ?></th>
										<th scope="col"><?php esc_html_e( 'HEAD + bots', 'lb-simple-podcast' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( array_reverse( $data['daily'] ) as $day ) : ?>
										<tr>
											<td><?php echo esc_html( $day['date'] ); ?></td>
											<td><?php echo esc_html( number_format_i18n( $day['listens'] ) ); ?></td>
											<td><?php echo esc_html( number_format_i18n( $day['requests'] ) ); ?></td>
											<td><?php echo esc_html( number_format_i18n( $day['noise'] ) ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					</details>
				<?php endif; ?>
			</section>

			<div class="lb-sp-dashboard__grid">
				<section class="lb-sp-card" aria-labelledby="lb-sp-top-heading">
					<h2 id="lb-sp-top-heading">
						<?php
						/* translators: %d: cantidad de días. */
						echo esc_html( sprintf( __( 'Top episodios más escuchados (últimos %d días)', 'lb-simple-podcast' ), $days ) );
						?>
					</h2>
					<?php if ( array() === $data['top_episodes'] ) : ?>
						<p class="lb-sp-empty"><?php esc_html_e( 'Sin solicitudes.', 'lb-simple-podcast' ); ?></p>
					<?php else : ?>
						<?php $max_listens = max( 1, (int) max( array_column( $data['top_episodes'], 'listens' ) ) ); ?>
						<div class="lb-sp-table-scroll">
							<table class="widefat striped lb-sp-top-table">
								<thead>
									<tr>
										<th scope="col">#</th>
										<th scope="col"><?php esc_html_e( 'Episodio', 'lb-simple-podcast' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Escuchas est.', 'lb-simple-podcast' ); ?></th>
										<th scope="col"><?php esc_html_e( 'GET', 'lb-simple-podcast' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $data['top_episodes'] as $index => $row ) : ?>
										<?php $pct = (int) round( ( $row['listens'] / $max_listens ) * 100 ); ?>
										<tr>
											<td><?php echo esc_html( (string) ( $index + 1 ) ); ?></td>
											<td>
												<div class="lb-sp-ep-cell">
													<span class="lb-sp-ep-title"><?php $this->episode_link( $row['post_id'] ); ?></span>
													<?php if ( $post_id !== $row['post_id'] && get_post( $row['post_id'] ) instanceof WP_Post ) : ?>
														<?php
														$filter_url = add_query_arg(
															array(
																'page'    => Menu::PARENT_SLUG,
																'days'    => $days,
																'post_id' => $row['post_id'],
															),
															admin_url( 'admin.php' )
														);
														?>
														<a class="lb-sp-ep-filter" href="<?php echo esc_url( $filter_url ); ?>">
															<?php esc_html_e( 'Filtrar', 'lb-simple-podcast' ); ?>
														</a>
													<?php endif; ?>
												</div>
											</td>
											<td>
												<div class="lb-sp-bar-cell">
													<span class="lb-sp-bar-value"><?php echo esc_html( number_format_i18n( $row['listens'] ) ); ?></span>
													<span class="lb-sp-bar-track" aria-hidden="true">
														<span class="lb-sp-bar-fill" style="width:<?php echo esc_attr( (string) $pct ); ?>%;"></span>
													</span>
												</div>
											</td>
											<td><?php echo esc_html( number_format_i18n( $row['requests'] ) ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</section>

				<div class="lb-sp-dashboard__side">
					<section class="lb-sp-card" aria-labelledby="lb-sp-clients-heading">
						<h2 id="lb-sp-clients-heading"><?php esc_html_e( 'Clientes y reproductores', 'lb-simple-podcast' ); ?></h2>
						<?php if ( array() === $clients ) : ?>
							<p class="lb-sp-empty"><?php esc_html_e( 'Sin datos de reproductores en este período.', 'lb-simple-podcast' ); ?></p>
						<?php else : ?>
							<div class="lb-sp-donut-layout">
								<div id="lb-sp-clients-chart" class="lb-sp-donut-canvas"></div>
								<ul class="lb-sp-client-legend">
									<?php foreach ( $clients as $client ) : ?>
										<li>
											<span class="lb-sp-legend-dot" style="background-color:<?php echo esc_attr( $client['color'] ); ?>;" aria-hidden="true"></span>
											<span class="lb-sp-legend-label"><?php echo esc_html( $client['label'] ); ?></span>
											<span class="lb-sp-legend-meta">
												<?php echo esc_html( number_format_i18n( $client['listens'] ) ); ?>
												(<?php echo esc_html( number_format_i18n( $client['percent'], 1 ) ); ?>%)
											</span>
										</li>
									<?php endforeach; ?>
								</ul>
							</div>
						<?php endif; ?>
					</section>

					<section class="lb-sp-card" aria-labelledby="lb-sp-health-heading">
						<h2 id="lb-sp-health-heading"><?php esc_html_e( 'Tráfico filtrado y salud de entrega', 'lb-simple-podcast' ); ?></h2>
						<dl class="lb-sp-health-list">
							<div>
								<dt><?php esc_html_e( 'Verificaciones HEAD (excluidas)', 'lb-simple-podcast' ); ?></dt>
								<dd><?php echo esc_html( number_format_i18n( $summary['head_requests'] ) ); ?></dd>
							</div>
							<div>
								<dt><?php esc_html_e( 'Bots y scripts (excluidos)', 'lb-simple-podcast' ); ?></dt>
								<dd><?php echo esc_html( number_format_i18n( $summary['bot_requests'] ) ); ?></dd>
							</div>
							<div>
								<dt><?php esc_html_e( 'Redirecciones y errores (302 / 4xx / 5xx)', 'lb-simple-podcast' ); ?></dt>
								<dd><?php echo esc_html( number_format_i18n( $summary['error_requests'] ) ); ?></dd>
							</div>
						</dl>

						<?php if ( array() !== $data['statuses'] ) : ?>
							<h3 class="lb-sp-subhead"><?php esc_html_e( 'Códigos HTTP registrados', 'lb-simple-podcast' ); ?></h3>
							<ul class="lb-sp-status-pills">
								<?php foreach ( $data['statuses'] as $status_row ) : ?>
									<li>
										<span class="lb-sp-status-code lb-sp-status-code--<?php echo esc_attr( $this->status_tone( $status_row['status'] ) ); ?>">
											<?php echo esc_html( (string) $status_row['status'] ); ?>
										</span>
										<span><?php echo esc_html( number_format_i18n( $status_row['count'] ) ); ?></span>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</section>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Lee y sanea los parámetros GET activos de filtro.
	 *
	 * @return array{days: int, post_id: int}
	 */
	private function current_filters(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Parámetros de lectura para filtrar la vista.
		$raw_days = isset( $_GET['days'] ) ? absint( wp_unslash( $_GET['days'] ) ) : self::DAYS;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Parámetros de lectura para filtrar la vista.
		$raw_post = isset( $_GET['post_id'] ) ? absint( wp_unslash( $_GET['post_id'] ) ) : 0;

		return array(
			'days'    => RequestStats::normalize_days( $raw_days ),
			'post_id' => $raw_post,
		);
	}

	/**
	 * Asigna un color de la paleta a cada categoría de cliente.
	 *
	 * @param array<int, array{key: string, label: string, listens: int, requests: int, percent: float}> $clients Clientes.
	 * @return array<int, array{key: string, label: string, listens: int, requests: int, percent: float, color: string}>
	 */
	private function decorate_clients( array $clients ): array {
		$out   = array();
		$total = count( self::CLIENT_COLORS );
		foreach ( $clients as $index => $client ) {
			$client['color'] = self::CLIENT_COLORS[ $index % $total ];
			$out[]           = $client;
		}
		return $out;
	}

	/**
	 * Prepara el payload mínimo que consume assets/js/dashboard.js.
	 *
	 * @param array<string, mixed> $data Salida de RequestStats::dashboard_data().
	 * @return array<string, mixed>
	 */
	private function chart_payload( array $data ): array {
		$clients = is_array( $data['clients'] ?? null ) ? $this->decorate_clients( $data['clients'] ) : array();
		return array(
			'daily'     => $data['daily'] ?? array(),
			'published' => $data['published'] ?? array(),
			'clients'   => $clients,
			'i18n'      => array(
				'listens'     => __( 'Escuchas estimadas', 'lb-simple-podcast' ),
				'requests'    => __( 'Solicitudes GET', 'lb-simple-podcast' ),
				'noise'       => __( 'HEAD + bots', 'lb-simple-podcast' ),
				'published'   => __( 'Publicado', 'lb-simple-podcast' ),
				'totalPlays'  => __( 'Escuchas', 'lb-simple-podcast' ),
				'trendTitle'  => __( 'Gráfica de evolución diaria de escuchas estimadas y solicitudes', 'lb-simple-podcast' ),
				'clientTitle' => __( 'Gráfica circular de distribución por reproductor o cliente', 'lb-simple-podcast' ),
			),
		);
	}

	/**
	 * Comprueba si hay al menos un valor > 0 en la serie diaria.
	 *
	 * @param array<int, array{date: string, label: string, listens: int, requests: int, noise: int}> $daily Serie diaria.
	 */
	private function has_trend_data( array $daily ): bool {
		foreach ( $daily as $day ) {
			if ( $day['listens'] > 0 || $day['requests'] > 0 || $day['noise'] > 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Calcula la variación porcentual contra el período anterior.
	 *
	 * @param int $current  Valor actual.
	 * @param int $previous Valor anterior.
	 * @param int $days     Días del período.
	 * @return array{text: string, tone: string}
	 */
	private function delta_info( int $current, int $previous, int $days ): array {
		if ( 0 === $previous && 0 === $current ) {
			return array(
				'text' => sprintf(
					/* translators: %d: cantidad de días anteriores. */
					__( 'Sin cambios vs. %d días previos', 'lb-simple-podcast' ),
					$days
				),
				'tone' => 'flat',
			);
		}
		if ( 0 === $previous ) {
			return array(
				'text' => sprintf(
					/* translators: %d: cantidad de días anteriores. */
					__( '▲ Nuevo tráfico vs. %d días previos', 'lb-simple-podcast' ),
					$days
				),
				'tone' => 'up',
			);
		}
		$pct   = (int) round( ( ( $current - $previous ) / $previous ) * 100 );
		$arrow = $pct > 0 ? '▲' : ( $pct < 0 ? '▼' : '•' );
		$tone  = $pct > 0 ? 'up' : ( $pct < 0 ? 'down' : 'flat' );
		$sign  = $pct > 0 ? '+' : '';
		return array(
			'text' => sprintf(
				/* translators: 1: flecha de dirección, 2: porcentaje con signo, 3: cantidad de días. */
				__( '%1$s %2$s%% vs. %3$d días previos', 'lb-simple-podcast' ),
				$arrow,
				$sign . number_format_i18n( $pct ),
				$days
			),
			'tone' => $tone,
		);
	}

	/**
	 * Tarjeta KPI con variación y descripción.
	 *
	 * @param string                            $label Etiqueta.
	 * @param string                            $value Valor formateado.
	 * @param array{text: string, tone: string} $delta Variación.
	 * @param string                            $hint  Explicación breve.
	 */
	private function kpi_card( string $label, string $value, array $delta, string $hint ): void {
		?>
		<article class="lb-sp-card lb-sp-kpi">
			<h3 class="lb-sp-kpi__label"><?php echo esc_html( $label ); ?></h3>
			<p class="lb-sp-kpi__value"><strong><?php echo esc_html( $value ); ?></strong></p>
			<p class="lb-sp-kpi__delta lb-sp-kpi__delta--<?php echo esc_attr( $delta['tone'] ); ?>">
				<?php echo esc_html( $delta['text'] ); ?>
			</p>
			<p class="description lb-sp-kpi__hint"><?php echo esc_html( $hint ); ?></p>
		</article>
		<?php
	}

	/**
	 * Tarjeta con el total de audios publicados y el último episodio.
	 *
	 * @param int          $published_count Total de episodios con audio.
	 * @param WP_Post|null $latest          Último episodio publicado.
	 */
	private function published_card( int $published_count, ?WP_Post $latest ): void {
		?>
		<article class="lb-sp-card lb-sp-kpi">
			<h3 class="lb-sp-kpi__label"><?php esc_html_e( 'Audios publicados', 'lb-simple-podcast' ); ?></h3>
			<p class="lb-sp-kpi__value"><strong><?php echo esc_html( number_format_i18n( $published_count ) ); ?></strong></p>
			<p class="description lb-sp-kpi__hint">
				<?php esc_html_e( 'Último audio publicado:', 'lb-simple-podcast' ); ?>
				<?php if ( null === $latest ) : ?>
					<?php esc_html_e( 'Sin audios.', 'lb-simple-podcast' ); ?>
				<?php else : ?>
					<?php $edit = get_edit_post_link( $latest->ID ); ?>
					<a href="<?php echo esc_url( is_string( $edit ) ? $edit : '' ); ?>"><?php echo esc_html( get_the_title( $latest ) ); ?></a>
					(<?php echo esc_html( (string) get_the_date( '', $latest ) ); ?>)
				<?php endif; ?>
			</p>
		</article>
		<?php
	}

	/**
	 * Tono visual para un código HTTP.
	 *
	 * @param int $status Código HTTP.
	 */
	private function status_tone( int $status ): string {
		if ( $status >= 200 && $status < 300 ) {
			return 'ok';
		}
		if ( $status >= 300 && $status < 400 ) {
			return 'warn';
		}
		return 'err';
	}

	/**
	 * Título del episodio con enlace de edición, o "(eliminado)".
	 *
	 * @param int $post_id Entrada.
	 */
	private function episode_link( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			esc_html_e( '(eliminado)', 'lb-simple-podcast' );
			return;
		}
		$edit = get_edit_post_link( $post_id );
		if ( is_string( $edit ) && '' !== $edit ) {
			echo '<a href="' . esc_url( $edit ) . '">' . esc_html( get_the_title( $post ) ) . '</a>';
			return;
		}
		echo esc_html( get_the_title( $post ) );
	}
}
