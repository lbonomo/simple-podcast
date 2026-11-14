<?php
/**
 * Meta del audio de un post y su exposición en la REST API.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Meta;

use LbSimplePodcast\Media\AudioValidator;
use LbSimplePodcast\Media\AudioDelivery;
use stdClass;
use WP_Error;
use WP_Post;
use WP_REST_Request;

/**
 * Registra `_lb_sp_audio_id` y el campo REST `audio_data`.
 */
final class AudioMeta {

	public const META_KEY  = '_lb_sp_audio_id';
	public const POST_TYPE = 'post';

	/**
	 * Validador de audio.
	 *
	 * @var AudioValidator
	 */
	private AudioValidator $validator;

	/**
	 * Constructor.
	 *
	 * @param AudioValidator|null $validator Validador (por defecto uno nuevo).
	 */
	public function __construct( ?AudioValidator $validator = null ) {
		$this->validator = $validator ?? new AudioValidator();
	}

	/**
	 * Engancha los hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_meta' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_field' ) );
		add_filter( 'add_post_metadata', array( $this, 'guard_meta_write' ), 10, 4 );
		add_filter( 'update_post_metadata', array( $this, 'guard_meta_write' ), 10, 4 );
		add_filter( 'rest_pre_insert_' . self::POST_TYPE, array( $this, 'validate_rest_request' ), 10, 2 );
	}

	/**
	 * Registra el meta con sanitización y control de acceso.
	 */
	public function register_meta(): void {
		register_post_meta(
			self::POST_TYPE,
			self::META_KEY,
			array(
				'type'              => 'integer',
				'description'       => __( 'ID del archivo MP3 de la biblioteca de medios asociado a la entrada.', 'lb-simple-podcast' ),
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
				'auth_callback'     => array( $this, 'can_edit_meta' ),
			)
		);
	}

	/**
	 * Un meta protegido (`_`) solo se edita si el usuario puede editar el post.
	 *
	 * @param bool   $allowed  Valor por defecto.
	 * @param string $meta_key Clave.
	 * @param int    $post_id  ID del post.
	 * @param int    $user_id  ID del usuario.
	 */
	public function can_edit_meta( $allowed, $meta_key, $post_id, $user_id = 0 ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		unset( $allowed, $meta_key );
		return user_can( (int) $user_id, 'edit_post', (int) $post_id );
	}

	/**
	 * Bloquea escrituras de un ID que no sea un MP3 válido (0 limpia el valor).
	 *
	 * @param mixed  $check    Valor previo del filtro (null para continuar).
	 * @param int    $object_id ID del post.
	 * @param string $meta_key Clave.
	 * @param mixed  $value    Valor a guardar.
	 * @return mixed
	 */
	public function guard_meta_write( $check, $object_id, $meta_key, $value ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		unset( $object_id );
		if ( self::META_KEY !== $meta_key ) {
			return $check;
		}

		$id = is_scalar( $value ) ? absint( $value ) : 0;
		if ( 0 === $id ) {
			return $check;
		}

		return is_wp_error( $this->validator->validate_attachment( $id ) ) ? false : $check;
	}

	/**
	 * Devuelve un error REST claro antes de guardar un ID inválido.
	 *
	 * @param stdClass|WP_Error                     $prepared_post Post preparado.
	 * @param WP_REST_Request<array<string, mixed>> $request Petición.
	 * @return stdClass|WP_Error
	 */
	public function validate_rest_request( $prepared_post, $request ) {
		if ( is_wp_error( $prepared_post ) ) {
			return $prepared_post;
		}

		$meta = $request->get_param( 'meta' );
		if ( ! is_array( $meta ) || ! isset( $meta[ self::META_KEY ] ) || ! is_scalar( $meta[ self::META_KEY ] ) ) {
			return $prepared_post;
		}

		$id = absint( $meta[ self::META_KEY ] );
		if ( 0 === $id ) {
			return $prepared_post;
		}

		$result = $this->validator->validate_attachment( $id );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		return $prepared_post;
	}

	/**
	 * Agrega `audio_data` a las respuestas de `wp/v2/posts`.
	 */
	public function register_rest_field(): void {
		register_rest_field(
			self::POST_TYPE,
			'audio_data',
			array(
				'get_callback' => static function ( $post_array ): ?array {
					$post_id = is_array( $post_array ) && isset( $post_array['id'] ) ? (int) $post_array['id'] : 0;
					return self::get_audio_data( $post_id );
				},
				'schema'       => array(
					'description' => __( 'Datos del audio MP3 asociado, o null.', 'lb-simple-podcast' ),
					'type'        => array( 'object', 'null' ),
					'context'     => array( 'view', 'edit', 'embed' ),
					'readonly'    => true,
					'properties'  => array(
						'attachment_id' => array( 'type' => 'integer' ),
						'url'           => array(
							'type'   => 'string',
							'format' => 'uri',
						),
						'mime_type'     => array( 'type' => 'string' ),
						'filesize'      => array( 'type' => 'integer' ),
						'duration'      => array( 'type' => 'integer' ),
					),
				),
			)
		);
	}

	/**
	 * ID del attachment asociado al post (0 si no hay).
	 *
	 * @param int $post_id ID del post.
	 */
	public static function get_attachment_id( int $post_id ): int {
		if ( $post_id <= 0 ) {
			return 0;
		}
		return absint( get_post_meta( $post_id, self::META_KEY, true ) );
	}

	/**
	 * Datos del audio asociado al post, o null si no tiene un MP3 válido.
	 *
	 * @param int $post_id ID del post.
	 * @return array{attachment_id: int, url: string, mime_type: string, filesize: int, duration: int}|null
	 */
	public static function get_audio_data( int $post_id ): ?array {
		$attachment_id = self::get_attachment_id( $post_id );
		if ( $attachment_id <= 0 ) {
			return null;
		}

		$attachment = get_post( $attachment_id );
		if ( ! $attachment instanceof WP_Post || 'attachment' !== $attachment->post_type || AudioValidator::MIME_TYPE !== $attachment->post_mime_type ) {
			return null;
		}

		$url = wp_get_attachment_url( $attachment_id );
		if ( ! is_string( $url ) || '' === $url ) {
			return null;
		}

		/**
		 * Metadatos del attachment (para audio incluyen `length` y `filesize`).
		 *
		 * @var array<string, mixed> $metadata
		 */
		$metadata = (array) wp_get_attachment_metadata( $attachment_id );

		$filesize = isset( $metadata['filesize'] ) ? (int) $metadata['filesize'] : 0;
		if ( $filesize <= 0 ) {
			$path     = get_attached_file( $attachment_id );
			$filesize = is_string( $path ) && is_file( $path ) ? (int) filesize( $path ) : 0;
		}

		$duration = isset( $metadata['length'] ) ? (int) $metadata['length'] : 0;
		if ( $duration <= 0 ) {
			$path = get_attached_file( $attachment_id );
			if ( is_string( $path ) && is_file( $path ) ) {
				AudioValidator::load_wp_admin_includes();
				$read     = wp_read_audio_metadata( $path );
				$duration = is_array( $read ) && isset( $read['length'] ) ? (int) $read['length'] : 0;
			}
		}

		return array(
			'attachment_id' => $attachment_id,
			'url'           => AudioDelivery::url( $post_id, $attachment_id ),
			'mime_type'     => AudioValidator::MIME_TYPE,
			'filesize'      => $filesize,
			'duration'      => $duration,
		);
	}
}
