<?php
/**
 * Panel de errores del plugin (Podcast > Setup) y aviso del administrador.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Admin;

use LbSimplePodcast\Tracking\ErrorLog;

/** Lista los errores registrados y permite marcarlos como leídos. */
final class ErrorsPanel {

	public const ACTION = 'lb_sp_errors';
	public const NONCE  = 'lb_sp_errors';

	/** Engancha los hooks. */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_request' ) );
	}

	/** Aviso con el número de errores sin leer. */
	public function notice(): void {
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			return;
		}
		$count = ( new ErrorLog() )->unread_error_count();
		if ( $count < 1 ) {
			return;
		}
		$url = admin_url( 'admin.php?page=' . Menu::SETUP_SLUG . '#lb-sp-errors' );
		echo '<div class="notice notice-error"><p>';
		echo esc_html(
			sprintf(
				/* translators: %s: cantidad de errores sin leer. */
				_n( 'Simple Podcast: hay %s error sin leer.', 'Simple Podcast: hay %s errores sin leer.', $count, 'lb-simple-podcast' ),
				number_format_i18n( $count )
			)
		);
		echo ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Ver detalles', 'lb-simple-podcast' ) . '</a>';
		echo '</p></div>';
	}

	/** Procesa el formulario (admin-post.php). */
	public function handle_request(): void {
		Menu::authorize();
		check_admin_referer( self::NONCE );
		$operation = isset( $_POST['do'] ) && is_string( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verificado arriba.
		$id        = isset( $_POST['id'] ) && is_string( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verificado arriba.
		$this->apply( $operation, $id );
		wp_safe_redirect( admin_url( 'admin.php?page=' . Menu::SETUP_SLUG . '#lb-sp-errors' ) );
		exit;
	}

	/**
	 * Ejecuta una acción sobre el registro.
	 *
	 * @param string $operation Acción: read, read_all o clear.
	 * @param string $id Identificador (solo para read).
	 */
	public function apply( string $operation, string $id = '' ): void {
		Menu::authorize();
		$log = new ErrorLog();
		if ( 'read' === $operation && '' !== $id ) {
			$log->mark_read( $id );
		} elseif ( 'read_all' === $operation ) {
			$log->mark_all_read();
		} elseif ( 'clear' === $operation ) {
			$log->clear();
		}
	}

	/** Dibuja la sección. */
	public function render(): void {
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			return;
		}
		$entries = ( new ErrorLog() )->all();
		$format  = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<h2 id="lb-sp-errors"><?php esc_html_e( 'Registro de errores', 'lb-simple-podcast' ); ?></h2>
		<?php if ( array() === $entries ) : ?>
			<p><?php esc_html_e( 'No hay errores registrados.', 'lb-simple-podcast' ); ?></p>
			<?php
			return;
		endif;
		?>
		<p>
			<?php $this->button( 'read_all', '', __( 'Marcar todos como leídos', 'lb-simple-podcast' ) ); ?>
			<?php $this->button( 'clear', '', __( 'Vaciar registro', 'lb-simple-podcast' ), __( '¿Vaciar el registro de errores?', 'lb-simple-podcast' ) ); ?>
		</p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Fecha', 'lb-simple-podcast' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Nivel', 'lb-simple-podcast' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Origen', 'lb-simple-podcast' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Motivo', 'lb-simple-podcast' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Veces', 'lb-simple-podcast' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Estado', 'lb-simple-podcast' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Acciones', 'lb-simple-podcast' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $entries as $entry ) : ?>
					<tr class="lb-sp-error-row">
						<td><?php echo esc_html( (string) wp_date( $format, $entry['last'] ) ); ?></td>
						<td><?php echo esc_html( ErrorLog::LEVEL_WARNING === $entry['level'] ? __( 'Aviso', 'lb-simple-podcast' ) : __( 'Error', 'lb-simple-podcast' ) ); ?></td>
						<td><?php echo esc_html( $this->source_label( $entry['source'] ) ); ?></td>
						<td>
							<?php echo esc_html( $entry['message'] ); ?>
							<?php if ( $entry['post_id'] > 0 || $entry['attachment_id'] > 0 ) : ?>
								<br /><span class="description">
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: ID de la entrada, 2: ID del attachment. */
											__( 'Entrada #%1$d, audio #%2$d', 'lb-simple-podcast' ),
											$entry['post_id'],
											$entry['attachment_id']
										)
									);
									?>
								</span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( number_format_i18n( $entry['count'] ) ); ?></td>
						<td><?php echo esc_html( $entry['read'] ? __( 'Leído', 'lb-simple-podcast' ) : __( 'Nuevo', 'lb-simple-podcast' ) ); ?></td>
						<td style="text-align:right;">
							<?php
							if ( ! $entry['read'] ) {
								$this->button( 'read', $entry['id'], __( 'Marcar como leído', 'lb-simple-podcast' ) );
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Formulario de un botón.
	 *
	 * @param string $operation      Acción.
	 * @param string $id      Identificador de la entrada.
	 * @param string $label   Texto del botón.
	 * @param string $confirm Mensaje de confirmación (opcional).
	 */
	private function button( string $operation, string $id, string $label, string $confirm = '' ): void {
		?>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="display:inline;">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
			<input type="hidden" name="do" value="<?php echo esc_attr( $operation ); ?>" />
			<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>" />
			<?php wp_nonce_field( self::NONCE ); ?>
			<button type="submit" class="button button-small"<?php echo '' !== $confirm ? ' onclick="' . esc_attr( 'return confirm(' . wp_json_encode( $confirm ) . ');' ) . '"' : ''; ?>><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * Etiqueta legible del origen.
	 *
	 * @param string $source Origen.
	 */
	private function source_label( string $source ): string {
		$labels = array(
			ErrorLog::SOURCE_LOG_INSERT => __( 'Registro de solicitudes', 'lb-simple-podcast' ),
			ErrorLog::SOURCE_LOG_SCHEMA => __( 'Tabla de registros', 'lb-simple-podcast' ),
			ErrorLog::SOURCE_DELIVERY   => __( 'Entrega de audio', 'lb-simple-podcast' ),
			ErrorLog::SOURCE_LEGACY     => __( 'Versión anterior', 'lb-simple-podcast' ),
		);
		return $labels[ $source ] ?? $source;
	}
}
