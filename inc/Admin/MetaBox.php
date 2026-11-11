<?php
/**
 * Metabox de respaldo para el editor clásico.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Admin;

use LbSimplePodcast\Media\AudioValidator;
use LbSimplePodcast\Meta\AudioMeta;
use WP_Post;

/**
 * Selector de MP3 para el editor clásico (en Gutenberg se oculta).
 */
final class MetaBox {

	public const NONCE_ACTION = 'lb_sp_save_audio';
	public const NONCE_FIELD  = 'lb_sp_audio_nonce';
	public const FIELD        = 'lb_sp_audio_id';
	public const ERROR_QUERY  = 'lb_sp_error';

	/**
	 * Validador de audio.
	 *
	 * @var AudioValidator
	 */
	private AudioValidator $validator;

	/**
	 * Constructor.
	 *
	 * @param AudioValidator|null $validator Validador.
	 */
	public function __construct( ?AudioValidator $validator = null ) {
		$this->validator = $validator ?? new AudioValidator();
	}

	/**
	 * Engancha los hooks.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes_' . AudioMeta::POST_TYPE, array( $this, 'add_meta_box' ) );
		add_action( 'save_post_' . AudioMeta::POST_TYPE, array( $this, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_notices', array( $this, 'render_error_notice' ) );
		add_filter( 'redirect_post_location', array( $this, 'add_error_to_redirect' ) );
	}

	/**
	 * Registra el metabox. `__back_compat_meta_box` lo oculta en el editor de bloques.
	 */
	public function add_meta_box(): void {
		add_meta_box(
			'lb-sp-audio',
			__( 'Podcast (audio MP3)', 'lb-simple-podcast' ),
			array( $this, 'render' ),
			AudioMeta::POST_TYPE,
			'side',
			'default',
			array( '__back_compat_meta_box' => true )
		);
	}

	/**
	 * Carga el selector de medios en el editor clásico.
	 *
	 * @param string $hook_suffix Pantalla actual.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( null === $screen || AudioMeta::POST_TYPE !== $screen->post_type || $screen->is_block_editor() ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_script(
			'lb-sp-classic',
			LB_SP_URL . 'assets/js/classic.js',
			array( 'jquery', 'media-editor' ),
			LB_SP_VERSION,
			true
		);
		wp_localize_script(
			'lb-sp-classic',
			'lbSpClassic',
			array(
				'frameTitle'  => __( 'Seleccionar MP3', 'lb-simple-podcast' ),
				'frameButton' => __( 'Usar este audio', 'lb-simple-podcast' ),
				'mime'        => AudioValidator::MIME_TYPE,
				'mimeError'   => __( 'Solo se admiten archivos MP3 (audio/mpeg).', 'lb-simple-podcast' ),
			)
		);
	}

	/**
	 * Dibuja el metabox.
	 *
	 * @param WP_Post $post Post en edición.
	 */
	public function render( WP_Post $post ): void {
		$audio = AudioMeta::get_audio_data( $post->ID );
		$id    = AudioMeta::get_attachment_id( $post->ID );

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		?>
		<p id="lb-sp-audio-summary">
			<?php
			if ( null !== $audio ) {
				echo esc_html( basename( $audio['url'] ) );
				if ( $audio['duration'] > 0 ) {
					echo ' (' . esc_html( gmdate( $audio['duration'] >= 3600 ? 'G:i:s' : 'i:s', $audio['duration'] ) ) . ')';
				}
			} else {
				esc_html_e( 'Sin audio asociado.', 'lb-simple-podcast' );
			}
			?>
		</p>
		<input type="hidden" id="lb-sp-audio-id" name="<?php echo esc_attr( self::FIELD ); ?>" value="<?php echo esc_attr( (string) $id ); ?>" />
		<p>
			<button type="button" class="button" id="lb-sp-audio-select"><?php esc_html_e( 'Seleccionar MP3', 'lb-simple-podcast' ); ?></button>
			<button type="button" class="button-link button-link-delete" id="lb-sp-audio-remove"><?php esc_html_e( 'Quitar audio', 'lb-simple-podcast' ); ?></button>
		</p>
		<?php
	}

	/**
	 * Guarda el ID del audio, validando nonce, permisos y archivo.
	 *
	 * @param int     $post_id ID del post.
	 * @param WP_Post $post    Post.
	 */
	public function save( int $post_id, WP_Post $post ): void {
		unset( $post );
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		$attachment_id = isset( $_POST[ self::FIELD ] ) ? absint( wp_unslash( $_POST[ self::FIELD ] ) ) : 0;
		if ( 0 === $attachment_id ) {
			delete_post_meta( $post_id, AudioMeta::META_KEY );
			return;
		}

		$result = $this->validator->validate_attachment( $attachment_id );
		if ( is_wp_error( $result ) ) {
			set_transient( $this->error_key( $post_id ), $result->get_error_message(), MINUTE_IN_SECONDS );
			return;
		}

		update_post_meta( $post_id, AudioMeta::META_KEY, $attachment_id );
	}

	/**
	 * Marca la redirección posterior al guardado si hubo un error.
	 *
	 * @param string $location URL de destino.
	 */
	public function add_error_to_redirect( string $location ): string {
		$post_id = isset( $_POST['post_ID'] ) ? absint( wp_unslash( $_POST['post_ID'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $post_id > 0 && false !== get_transient( $this->error_key( $post_id ) ) ) {
			return add_query_arg( self::ERROR_QUERY, '1', $location );
		}
		return $location;
	}

	/**
	 * Muestra el error de validación tras guardar.
	 */
	public function render_error_notice(): void {
		if ( ! isset( $_GET[ self::ERROR_QUERY ], $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$post_id = absint( wp_unslash( $_GET['post'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$message = get_transient( $this->error_key( $post_id ) );
		if ( ! is_string( $message ) || '' === $message ) {
			return;
		}
		delete_transient( $this->error_key( $post_id ) );

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html( $message )
		);
	}

	/**
	 * Clave del transient de error por usuario y post.
	 *
	 * @param int $post_id ID del post.
	 */
	private function error_key( int $post_id ): string {
		return 'lb_sp_err_' . get_current_user_id() . '_' . $post_id;
	}
}
