<?php
/**
 * Plantilla pública del shortcode `[lb_podcast_stats]`.
 *
 * Se puede sobrescribir copiando este archivo a `{tema}/lb-simple-podcast/stats.php`.
 *
 * Variables disponibles:
 *
 * @var array<string, mixed> $stats Datos públicos agregados (sin PII).
 * @var array<string, mixed> $args  Argumentos validados del shortcode.
 *
 * @package LbSimplePodcast
 */

defined( 'ABSPATH' ) || exit;

$lb_sp_sections      = is_array( $args['sections'] ?? null ) ? $args['sections'] : array();
$lb_sp_title         = is_string( $args['title'] ?? null ) ? $args['title'] : '';
$lb_sp_extra_class   = is_string( $args['class'] ?? null ) ? $args['class'] : '';
$lb_sp_days          = isset( $stats['days'] ) ? (int) $stats['days'] : 30;
$lb_sp_listens       = isset( $stats['estimated_listens'] ) ? (int) $stats['estimated_listens'] : 0;
$lb_sp_episodes      = isset( $stats['published_audios'] ) ? (int) $stats['published_audios'] : 0;
$lb_sp_listeners     = isset( $stats['unique_listeners'] ) ? (int) $stats['unique_listeners'] : 0;
$lb_sp_top_episodes  = is_array( $stats['top_episodes'] ?? null ) ? $stats['top_episodes'] : array();
$lb_sp_clients       = is_array( $stats['clients'] ?? null ) ? $stats['clients'] : array();
$lb_sp_has_kpis      = ! empty( $lb_sp_sections['listens'] ) || ! empty( $lb_sp_sections['episodes'] ) || ! empty( $lb_sp_sections['listeners'] );
$lb_sp_wrapper_class = trim( 'lb-sp-stats ' . $lb_sp_extra_class );
$lb_sp_heading_id    = '' !== $lb_sp_title ? wp_unique_id( 'lb-sp-stats-title-' ) : '';
/* translators: %d: cantidad de días del período. */
$lb_sp_period_label = sprintf( __( 'Últimos %d días', 'lb-simple-podcast' ), $lb_sp_days );
?>
<section class="<?php echo esc_attr( $lb_sp_wrapper_class ); ?>" <?php echo '' !== $lb_sp_heading_id ? 'aria-labelledby="' . esc_attr( $lb_sp_heading_id ) . '"' : 'aria-label="' . esc_attr__( 'Estadísticas del podcast', 'lb-simple-podcast' ) . '"'; ?>>
	<header class="lb-sp-stats__header">
		<?php if ( '' !== $lb_sp_title ) : ?>
			<h2 id="<?php echo esc_attr( $lb_sp_heading_id ); ?>" class="lb-sp-stats__title"><?php echo esc_html( $lb_sp_title ); ?></h2>
		<?php endif; ?>
		<p class="lb-sp-stats__period"><?php echo esc_html( $lb_sp_period_label ); ?></p>
	</header>

	<?php if ( $lb_sp_has_kpis ) : ?>
		<div class="lb-sp-stats__kpis">
			<?php if ( ! empty( $lb_sp_sections['listens'] ) ) : ?>
				<article class="lb-sp-stats__card">
					<span class="lb-sp-stats__label"><?php esc_html_e( 'Escuchas estimadas', 'lb-simple-podcast' ); ?></span>
					<strong class="lb-sp-stats__value"><?php echo esc_html( number_format_i18n( $lb_sp_listens ) ); ?></strong>
				</article>
			<?php endif; ?>

			<?php if ( ! empty( $lb_sp_sections['listeners'] ) ) : ?>
				<article class="lb-sp-stats__card">
					<span class="lb-sp-stats__label"><?php esc_html_e( 'Oyentes únicos', 'lb-simple-podcast' ); ?></span>
					<strong class="lb-sp-stats__value"><?php echo esc_html( number_format_i18n( $lb_sp_listeners ) ); ?></strong>
				</article>
			<?php endif; ?>

			<?php if ( ! empty( $lb_sp_sections['episodes'] ) ) : ?>
				<article class="lb-sp-stats__card">
					<span class="lb-sp-stats__label"><?php esc_html_e( 'Episodios publicados', 'lb-simple-podcast' ); ?></span>
					<strong class="lb-sp-stats__value"><?php echo esc_html( number_format_i18n( $lb_sp_episodes ) ); ?></strong>
				</article>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $lb_sp_sections['top_episodes'] ) ) : ?>
		<div class="lb-sp-stats__section">
			<h3 class="lb-sp-stats__subtitle"><?php esc_html_e( 'Episodios más escuchados', 'lb-simple-podcast' ); ?></h3>
			<?php if ( array() === $lb_sp_top_episodes ) : ?>
				<p class="lb-sp-stats__empty"><?php esc_html_e( 'Sin reproducciones registradas en este período.', 'lb-simple-podcast' ); ?></p>
			<?php else : ?>
				<ol class="lb-sp-stats__list">
					<?php foreach ( $lb_sp_top_episodes as $lb_sp_ep ) : ?>
						<?php
						if ( ! is_array( $lb_sp_ep ) ) {
							continue;
						}
						$lb_sp_ep_title   = is_string( $lb_sp_ep['title'] ?? null ) ? $lb_sp_ep['title'] : '';
						$lb_sp_ep_url     = is_string( $lb_sp_ep['url'] ?? null ) ? $lb_sp_ep['url'] : '';
						$lb_sp_ep_listens = isset( $lb_sp_ep['listens'] ) ? (int) $lb_sp_ep['listens'] : 0;
						?>
						<li class="lb-sp-stats__list-item">
							<span class="lb-sp-stats__ep-title">
								<?php if ( '' !== $lb_sp_ep_url ) : ?>
									<a href="<?php echo esc_url( $lb_sp_ep_url ); ?>"><?php echo esc_html( $lb_sp_ep_title ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $lb_sp_ep_title ); ?>
								<?php endif; ?>
							</span>
							<span class="lb-sp-stats__ep-count"><?php echo esc_html( number_format_i18n( $lb_sp_ep_listens ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $lb_sp_sections['clients'] ) ) : ?>
		<div class="lb-sp-stats__section">
			<h3 class="lb-sp-stats__subtitle"><?php esc_html_e( 'Aplicaciones y reproductores', 'lb-simple-podcast' ); ?></h3>
			<?php if ( array() === $lb_sp_clients ) : ?>
				<p class="lb-sp-stats__empty"><?php esc_html_e( 'Sin datos de reproductores en este período.', 'lb-simple-podcast' ); ?></p>
			<?php else : ?>
				<ul class="lb-sp-stats__clients">
					<?php foreach ( $lb_sp_clients as $lb_sp_client ) : ?>
						<?php
						if ( ! is_array( $lb_sp_client ) ) {
							continue;
						}
						$lb_sp_client_label   = is_string( $lb_sp_client['label'] ?? null ) ? $lb_sp_client['label'] : '';
						$lb_sp_client_listens = isset( $lb_sp_client['listens'] ) ? (int) $lb_sp_client['listens'] : 0;
						$lb_sp_client_pct     = isset( $lb_sp_client['percent'] ) ? max( 0.0, min( 100.0, (float) $lb_sp_client['percent'] ) ) : 0.0;
						?>
						<li class="lb-sp-stats__client-item">
							<div class="lb-sp-stats__client-row">
								<span class="lb-sp-stats__client-name"><?php echo esc_html( $lb_sp_client_label ); ?></span>
								<span class="lb-sp-stats__client-meta">
									<?php echo esc_html( number_format_i18n( $lb_sp_client_listens ) ); ?>
									(<?php echo esc_html( number_format_i18n( $lb_sp_client_pct, 1 ) ); ?>%)
								</span>
							</div>
							<span class="lb-sp-stats__bar-track" aria-hidden="true">
								<span class="lb-sp-stats__bar-fill" style="width:<?php echo esc_attr( number_format( $lb_sp_client_pct, 1, '.', '' ) ); ?>%;"></span>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</section>
