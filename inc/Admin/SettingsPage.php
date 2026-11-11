<?php
/**
 * Página de ajustes del canal de podcast.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Admin;

use WP_Error;

/**
 * Ajustes del canal (Ajustes > Podcast), guardados en la opción `lb_sp_channel`.
 */
final class SettingsPage {

	public const OPTION     = 'lb_sp_channel';
	public const GROUP      = 'lb_sp_channel_group';
	public const PAGE_SLUG  = 'lb-simple-podcast';
	public const CAPABILITY = 'manage_options';

	public const IMAGE_MIN = 1400;
	public const IMAGE_MAX = 3000;

	/**
	 * Categorías de nivel superior de Apple Podcasts (capitalización exacta).
	 *
	 * @var array<int, string>
	 */
	public const CATEGORIES = array(
		'Arts',
		'Business',
		'Comedy',
		'Education',
		'Fiction',
		'Government',
		'Health & Fitness',
		'History',
		'Kids & Family',
		'Leisure',
		'Music',
		'News',
		'Religion & Spirituality',
		'Science',
		'Society & Culture',
		'Sports',
		'Technology',
		'True Crime',
		'TV & Film',
	);

	/**
	 * Engancha los hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register_setting' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Valores por defecto tomados del sitio.
	 *
	 * @return array{title: string, description: string, language: string, image_id: int, category: string, author: string, owner_name: string, owner_email: string, explicit: string, copyright: string}
	 */
	public static function defaults(): array {
		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		return array(
			'title'       => $site_name,
			'description' => wp_specialchars_decode( get_bloginfo( 'description' ), ENT_QUOTES ),
			'language'    => str_replace( '_', '-', get_bloginfo( 'language' ) ),
			'image_id'    => absint( get_option( 'site_icon', 0 ) ),
			'category'    => '',
			'author'      => $site_name,
			'owner_name'  => $site_name,
			'owner_email' => (string) get_option( 'admin_email', '' ),
			'explicit'    => 'false',
			'copyright'   => '',
		);
	}

	/**
	 * Ajustes efectivos del canal: lo guardado sobre los valores por defecto.
	 *
	 * @return array{title: string, description: string, language: string, image_id: int, category: string, author: string, owner_name: string, owner_email: string, explicit: string, copyright: string}
	 */
	public static function get_channel(): array {
		$defaults = self::defaults();
		$saved    = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			return $defaults;
		}

		$str = static function ( string $key, string $fallback ) use ( $saved ): string {
			return isset( $saved[ $key ] ) && is_scalar( $saved[ $key ] ) && '' !== (string) $saved[ $key ] ? (string) $saved[ $key ] : $fallback;
		};

		$image_id = isset( $saved['image_id'] ) && is_scalar( $saved['image_id'] ) ? absint( $saved['image_id'] ) : 0;

		return array(
			'title'       => $str( 'title', $defaults['title'] ),
			'description' => $str( 'description', $defaults['description'] ),
			'language'    => $str( 'language', $defaults['language'] ),
			'image_id'    => $image_id > 0 ? $image_id : $defaults['image_id'],
			'category'    => $str( 'category', $defaults['category'] ),
			'author'      => $str( 'author', $defaults['author'] ),
			'owner_name'  => $str( 'owner_name', $defaults['owner_name'] ),
			'owner_email' => $str( 'owner_email', $defaults['owner_email'] ),
			'explicit'    => 'true' === ( $saved['explicit'] ?? '' ) ? 'true' : 'false',
			'copyright'   => $str( 'copyright', $defaults['copyright'] ),
		);
	}

	/**
	 * Registra la opción con su sanitización.
	 */
	public function register_setting(): void {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => array(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Agrega la página bajo Ajustes.
	 */
	public function add_page(): void {
		add_options_page(
			__( 'Podcast', 'lb-simple-podcast' ),
			__( 'Podcast', 'lb-simple-podcast' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Sanitiza y valida los ajustes enviados.
	 *
	 * Los campos inválidos vuelven al valor guardado anterior y se informa el error.
	 *
	 * @param mixed $input Datos enviados.
	 * @return array<string, mixed>
	 */
	public function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$previous = get_option( self::OPTION, array() );
		$previous = is_array( $previous ) ? $previous : array();
		$out      = array();

		$text = static function ( string $key ) use ( $input ): string {
			return isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? sanitize_text_field( (string) $input[ $key ] ) : '';
		};

		$out['title']       = $text( 'title' );
		$out['author']      = $text( 'author' );
		$out['owner_name']  = $text( 'owner_name' );
		$out['copyright']   = $text( 'copyright' );
		$out['description'] = isset( $input['description'] ) && is_scalar( $input['description'] )
			? mb_substr( sanitize_textarea_field( (string) $input['description'] ), 0, 4000 )
			: '';

		// Idioma (código tipo "es", "es-AR").
		$language        = $text( 'language' );
		$out['language'] = '';
		if ( '' !== $language ) {
			if ( 1 === preg_match( '/^[a-z]{2,3}(-[A-Za-z]{2,4})?$/', $language ) ) {
				$out['language'] = $language;
			} else {
				$this->error( 'lb_sp_language', __( 'El idioma no es válido (ejemplos: es, es-AR, en-US).', 'lb-simple-podcast' ) );
				$out['language'] = (string) ( $previous['language'] ?? '' );
			}
		}

		// Categoría (lista cerrada de Apple).
		$category        = $text( 'category' );
		$out['category'] = '';
		if ( '' !== $category ) {
			if ( in_array( $category, self::CATEGORIES, true ) ) {
				$out['category'] = $category;
			} else {
				$this->error( 'lb_sp_category', __( 'La categoría no es una categoría válida de Apple Podcasts.', 'lb-simple-podcast' ) );
				$out['category'] = (string) ( $previous['category'] ?? '' );
			}
		}

		// Email del propietario.
		$email              = $text( 'owner_email' );
		$out['owner_email'] = '';
		$sanitized_email    = '' !== $email ? sanitize_email( $email ) : '';
		if ( '' !== $sanitized_email && is_email( $sanitized_email ) ) {
			$out['owner_email'] = $sanitized_email;
		} elseif ( '' !== $email ) {
			$this->error( 'lb_sp_owner_email', __( 'El email del propietario no es válido.', 'lb-simple-podcast' ) );
			$out['owner_email'] = (string) ( $previous['owner_email'] ?? '' );
		}

		// Explícito.
		$explicit        = $text( 'explicit' );
		$out['explicit'] = in_array( $explicit, array( 'true', 'false' ), true ) ? $explicit : 'false';

		// Imagen del canal.
		$image_id        = isset( $input['image_id'] ) && is_scalar( $input['image_id'] ) ? absint( $input['image_id'] ) : 0;
		$out['image_id'] = 0;
		if ( $image_id > 0 ) {
			$valid = self::validate_image( $image_id );
			if ( is_wp_error( $valid ) ) {
				$this->error( 'lb_sp_image', $valid->get_error_message() );
				$out['image_id'] = absint( $previous['image_id'] ?? 0 );
			} else {
				$out['image_id'] = $image_id;
			}
		}

		// Cambiar los ajustes invalida la caché del feed.
		\LbSimplePodcast\Feed\FeedVersion::bump();

		return $out;
	}

	/**
	 * Valida que la imagen sea JPG/PNG cuadrada de 1400 a 3000 px.
	 *
	 * @param int $attachment_id ID del attachment.
	 * @return true|WP_Error
	 */
	public static function validate_image( int $attachment_id ) {
		$mime = get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
			return new WP_Error( 'lb_sp_image_type', __( 'La imagen del canal debe ser JPG o PNG.', 'lb-simple-podcast' ) );
		}

		$meta   = (array) wp_get_attachment_metadata( $attachment_id );
		$width  = isset( $meta['width'] ) ? absint( $meta['width'] ) : 0;
		$height = isset( $meta['height'] ) ? absint( $meta['height'] ) : 0;

		if ( $width !== $height || $width < self::IMAGE_MIN || $width > self::IMAGE_MAX ) {
			return new WP_Error(
				'lb_sp_image_size',
				sprintf(
					/* translators: 1: tamaño mínimo, 2: tamaño máximo. */
					__( 'La imagen del canal debe ser cuadrada, de %1$d a %2$d píxeles por lado.', 'lb-simple-podcast' ),
					self::IMAGE_MIN,
					self::IMAGE_MAX
				)
			);
		}

		return true;
	}

	/**
	 * Registra un error visible en la página de ajustes.
	 *
	 * @param string $code    Código.
	 * @param string $message Mensaje.
	 */
	private function error( string $code, string $message ): void {
		add_settings_error( self::OPTION, $code, $message, 'error' );
	}

	/**
	 * Carga el selector de medios para la imagen del canal.
	 *
	 * @param string $hook_suffix Pantalla actual.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script( 'lb-sp-settings', LB_SP_URL . 'assets/js/settings.js', array( 'jquery', 'media-editor' ), LB_SP_VERSION, true );
		wp_localize_script(
			'lb-sp-settings',
			'lbSpSettings',
			array(
				'frameTitle'  => __( 'Imagen del canal', 'lb-simple-podcast' ),
				'frameButton' => __( 'Usar esta imagen', 'lb-simple-podcast' ),
			)
		);
	}

	/**
	 * Dibuja la página.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$channel   = self::get_channel();
		$name      = self::OPTION;
		$image_url = $channel['image_id'] > 0 ? wp_get_attachment_image_url( $channel['image_id'], 'medium' ) : false;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Podcast', 'lb-simple-podcast' ); ?></h1>
			<p>
				<?php esc_html_e( 'URL del feed:', 'lb-simple-podcast' ); ?>
				<code><?php echo esc_url( get_feed_link( 'podcast' ) ); ?></code>
			</p>
			<form action="options.php" method="post">
				<?php settings_fields( self::GROUP ); ?>
				<table class="form-table" role="presentation">
					<?php
					$this->text_row( 'title', __( 'Título', 'lb-simple-podcast' ), $channel['title'] );
					?>
					<tr>
						<th scope="row"><label for="lb-sp-description"><?php esc_html_e( 'Descripción', 'lb-simple-podcast' ); ?></label></th>
						<td><textarea id="lb-sp-description" name="<?php echo esc_attr( $name ); ?>[description]" rows="4" class="large-text"><?php echo esc_textarea( $channel['description'] ); ?></textarea></td>
					</tr>
					<?php
					$this->text_row( 'language', __( 'Idioma (ej. es-AR)', 'lb-simple-podcast' ), $channel['language'] );
					$this->text_row( 'author', __( 'Autor', 'lb-simple-podcast' ), $channel['author'] );
					$this->text_row( 'owner_name', __( 'Propietario: nombre', 'lb-simple-podcast' ), $channel['owner_name'] );
					$this->text_row( 'owner_email', __( 'Propietario: email', 'lb-simple-podcast' ), $channel['owner_email'], 'email' );
					$this->text_row( 'copyright', __( 'Copyright', 'lb-simple-podcast' ), $channel['copyright'] );
					?>
					<tr>
						<th scope="row"><label for="lb-sp-category"><?php esc_html_e( 'Categoría', 'lb-simple-podcast' ); ?></label></th>
						<td>
							<select id="lb-sp-category" name="<?php echo esc_attr( $name ); ?>[category]">
								<option value=""><?php esc_html_e( '— Seleccionar —', 'lb-simple-podcast' ); ?></option>
								<?php foreach ( self::CATEGORIES as $category ) : ?>
									<option value="<?php echo esc_attr( $category ); ?>" <?php selected( $channel['category'], $category ); ?>><?php echo esc_html( $category ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lb-sp-explicit"><?php esc_html_e( 'Contenido explícito', 'lb-simple-podcast' ); ?></label></th>
						<td>
							<select id="lb-sp-explicit" name="<?php echo esc_attr( $name ); ?>[explicit]">
								<option value="false" <?php selected( $channel['explicit'], 'false' ); ?>><?php esc_html_e( 'No', 'lb-simple-podcast' ); ?></option>
								<option value="true" <?php selected( $channel['explicit'], 'true' ); ?>><?php esc_html_e( 'Sí', 'lb-simple-podcast' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Imagen', 'lb-simple-podcast' ); ?></th>
						<td>
							<input type="hidden" id="lb-sp-image-id" name="<?php echo esc_attr( $name ); ?>[image_id]" value="<?php echo esc_attr( (string) $channel['image_id'] ); ?>" />
							<div id="lb-sp-image-preview">
								<?php if ( is_string( $image_url ) ) : ?>
									<img src="<?php echo esc_url( $image_url ); ?>" alt="" style="max-width:150px;height:auto;" />
								<?php endif; ?>
							</div>
							<button type="button" class="button" id="lb-sp-image-select"><?php esc_html_e( 'Seleccionar imagen', 'lb-simple-podcast' ); ?></button>
							<button type="button" class="button-link button-link-delete" id="lb-sp-image-remove"><?php esc_html_e( 'Quitar', 'lb-simple-podcast' ); ?></button>
							<p class="description"><?php esc_html_e( 'JPG o PNG cuadrada, de 1400 a 3000 píxeles por lado.', 'lb-simple-podcast' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Fila de texto de la tabla.
	 *
	 * @param string $key   Clave del ajuste.
	 * @param string $label Etiqueta.
	 * @param string $value Valor actual.
	 * @param string $type  Tipo de input.
	 */
	private function text_row( string $key, string $label, string $value, string $type = 'text' ): void {
		$id = 'lb-sp-' . str_replace( '_', '-', $key );
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td><input type="<?php echo esc_attr( $type ); ?>" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( self::OPTION . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( $value ); ?>" class="regular-text" /></td>
		</tr>
		<?php
	}
}
