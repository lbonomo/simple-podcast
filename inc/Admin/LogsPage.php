<?php
/**
 * Tabla de logs de solicitudes de audio.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Admin;

use LbSimplePodcast\Tracking\RequestStats;

/** Lista paginada de las solicitudes registradas. */
final class LogsPage {

	public const PER_PAGE = 50;

	/**
	 * Carga el script que despliega los detalles.
	 *
	 * @param string $hook_suffix Pantalla actual.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( ! str_ends_with( $hook_suffix, '_page_' . Menu::LOGS_SLUG ) ) {
			return;
		}
		wp_enqueue_script( 'lb-sp-logs', LB_SP_URL . 'assets/js/logs.js', array(), LB_SP_VERSION, true );
	}

	/** Dibuja la página. */
	public function render(): void {
		Menu::authorize();

		$stats = new RequestStats();
		$total = $stats->count_all();
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$page  = isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page  = min( max( 1, $page ), $pages );
		$rows  = $stats->get_page( $page, self::PER_PAGE );
		$fmt   = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		$pagination = paginate_links(
			array(
				'base'      => add_query_arg( 'paged', '%#%', admin_url( 'admin.php?page=' . Menu::LOGS_SLUG ) ),
				'format'    => '',
				'current'   => $page,
				'total'     => $pages,
				'prev_text' => '&laquo;',
				'next_text' => '&raquo;',
			)
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Podcast: Logs', 'lb-simple-podcast' ); ?></h1>
			<p>
				<?php
				/* translators: %s: total de solicitudes. */
				echo esc_html( sprintf( __( 'Total de solicitudes registradas: %s', 'lb-simple-podcast' ), number_format_i18n( $total ) ) );
				?>
			</p>

			<?php if ( array() === $rows ) : ?>
				<p><?php esc_html_e( 'Sin solicitudes registradas.', 'lb-simple-podcast' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Fecha', 'lb-simple-podcast' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Episodio', 'lb-simple-podcast' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Método', 'lb-simple-podcast' ); ?></th>
							<th scope="col"><?php esc_html_e( 'IP', 'lb-simple-podcast' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Estado', 'lb-simple-podcast' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Usuario', 'lb-simple-podcast' ); ?></th>
							<th scope="col"><?php esc_html_e( 'URL', 'lb-simple-podcast' ); ?></th>
							<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Detalles', 'lb-simple-podcast' ); ?></span></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td><?php echo esc_html( (string) wp_date( $fmt, (int) strtotime( $row['requested_at'] . ' UTC' ) ) ); ?></td>
								<td><?php $this->episode( $row['post_id'] ); ?></td>
								<td><?php echo esc_html( $row['method'] ); ?></td>
								<td><?php echo esc_html( $row['remote_addr'] ); ?></td>
								<td><?php echo esc_html( (string) $row['status'] ); ?></td>
								<td><?php echo esc_html( $this->user_name( $row['user_id'] ) ); ?></td>
								<td style="word-break:break-all;"><?php echo esc_html( $row['request_url'] ); ?></td>
								<td style="text-align:right;">
									<button type="button" class="button button-small lb-sp-log-toggle" aria-expanded="false" aria-controls="lb-sp-log-<?php echo esc_attr( (string) $row['id'] ); ?>" title="<?php esc_attr_e( 'Ver detalles', 'lb-simple-podcast' ); ?>">+</button>
								</td>
							</tr>
							<tr id="lb-sp-log-<?php echo esc_attr( (string) $row['id'] ); ?>" class="lb-sp-log-details" hidden>
								<td colspan="8"><?php $this->details( $row ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( is_string( $pagination ) && '' !== $pagination ) : ?>
					<div class="tablenav"><div class="tablenav-pages"><?php echo wp_kses_post( $pagination ); ?></div></div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Resto de datos del registro.
	 *
	 * @param array<string, mixed> $row Fila del registro.
	 */
	private function details( array $row ): void {
		$headers = json_decode( (string) $row['headers'], true );
		$pretty  = is_array( $headers ) ? wp_json_encode( $headers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : (string) $row['headers'];
		?>
		<dl style="margin:0;">
			<dt><strong><?php esc_html_e( 'ID del registro', 'lb-simple-podcast' ); ?></strong></dt>
			<dd><?php echo esc_html( (string) $row['id'] ); ?></dd>
			<dt><strong><?php esc_html_e( 'ID del audio (attachment)', 'lb-simple-podcast' ); ?></strong></dt>
			<dd><?php echo esc_html( (string) $row['attachment_id'] ); ?></dd>
			<dt><strong><?php esc_html_e( 'Fecha (GMT)', 'lb-simple-podcast' ); ?></strong></dt>
			<dd><?php echo esc_html( (string) $row['requested_at'] ); ?></dd>
			<dt><strong><?php esc_html_e( 'URL completa', 'lb-simple-podcast' ); ?></strong></dt>
			<dd style="word-break:break-all;"><?php echo esc_html( (string) $row['request_url'] ); ?></dd>
			<dt><strong><?php esc_html_e( 'Cabeceras', 'lb-simple-podcast' ); ?></strong></dt>
			<dd><pre style="margin:0;white-space:pre-wrap;word-break:break-all;"><?php echo esc_html( is_string( $pretty ) ? $pretty : '' ); ?></pre></dd>
		</dl>
		<?php
	}

	/**
	 * Título del episodio con enlace de edición.
	 *
	 * @param int $post_id Entrada.
	 */
	private function episode( int $post_id ): void {
		if ( $post_id <= 0 ) {
			echo '&mdash;';
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			/* translators: %d: ID de la entrada. */
			echo esc_html( sprintf( __( '(eliminado) #%d', 'lb-simple-podcast' ), $post_id ) );
			return;
		}
		$edit = get_edit_post_link( $post_id );
		if ( is_string( $edit ) && '' !== $edit ) {
			echo '<a href="' . esc_url( $edit ) . '">' . esc_html( get_the_title( $post ) ) . '</a>';
			return;
		}
		echo esc_html( get_the_title( $post ) );
	}

	/**
	 * Nombre del usuario o "Anónimo".
	 *
	 * @param int $user_id Usuario (0 = anónimo).
	 */
	private function user_name( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return __( 'Anónimo', 'lb-simple-podcast' );
		}
		$user = get_userdata( $user_id );
		return $user ? $user->display_name : '#' . $user_id;
	}
}
