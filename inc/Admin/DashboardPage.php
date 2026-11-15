<?php
/**
 * Dashboard con estadísticas generales.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Admin;

use LbSimplePodcast\Tracking\RequestStats;

/** Muestra audios publicados, último audio y estadísticas de 30 días. */
final class DashboardPage {

	public const DAYS = 30;

	/** Dibuja la página. */
	public function render(): void {
		Menu::authorize();

		$stats  = new RequestStats();
		$latest = $stats->latest_audio();
		$top    = $stats->top_episodes( self::DAYS, 5 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Podcast: Dashboard', 'lb-simple-podcast' ); ?></h1>

			<div style="display:flex;flex-wrap:wrap;gap:16px;margin:16px 0;">
				<?php
				$this->card( __( 'Audios publicados', 'lb-simple-podcast' ), number_format_i18n( $stats->count_published_audios() ) );
				$this->card(
					/* translators: %d: cantidad de días. */
					sprintf( __( 'Solicitudes (últimos %d días)', 'lb-simple-podcast' ), self::DAYS ),
					number_format_i18n( $stats->requests_last_days( self::DAYS ) )
				);
				?>
			</div>

			<h2><?php esc_html_e( 'Último audio publicado', 'lb-simple-podcast' ); ?></h2>
			<?php if ( null === $latest ) : ?>
				<p><?php esc_html_e( 'Sin audios.', 'lb-simple-podcast' ); ?></p>
			<?php else : ?>
				<p>
					<?php $edit = get_edit_post_link( $latest->ID ); ?>
					<a href="<?php echo esc_url( is_string( $edit ) ? $edit : '' ); ?>"><?php echo esc_html( get_the_title( $latest ) ); ?></a>
					<span class="description">(<?php echo esc_html( (string) get_the_date( '', $latest ) ); ?>)</span>
				</p>
			<?php endif; ?>

			<h2>
				<?php
				/* translators: %d: cantidad de días. */
				echo esc_html( sprintf( __( 'Top 5 episodios más escuchados (últimos %d días)', 'lb-simple-podcast' ), self::DAYS ) );
				?>
			</h2>
			<?php if ( array() === $top ) : ?>
				<p><?php esc_html_e( 'Sin solicitudes.', 'lb-simple-podcast' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:700px;">
					<thead>
						<tr>
							<th scope="col">#</th>
							<th scope="col"><?php esc_html_e( 'Episodio', 'lb-simple-podcast' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Solicitudes', 'lb-simple-podcast' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $top as $index => $row ) : ?>
							<tr>
								<td><?php echo esc_html( (string) ( $index + 1 ) ); ?></td>
								<td><?php $this->episode_link( $row['post_id'] ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $row['total'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Tarjeta con una cifra.
	 *
	 * @param string $label Etiqueta.
	 * @param string $value Valor ya formateado.
	 */
	private function card( string $label, string $value ): void {
		?>
		<div class="card" style="margin:0;min-width:200px;">
			<p class="description" style="margin:0;"><?php echo esc_html( $label ); ?></p>
			<p style="font-size:2em;margin:.25em 0 0;"><strong><?php echo esc_html( $value ); ?></strong></p>
		</div>
		<?php
	}

	/**
	 * Título del episodio con enlace de edición, o "(eliminado)".
	 *
	 * @param int $post_id Entrada.
	 */
	private function episode_link( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
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
