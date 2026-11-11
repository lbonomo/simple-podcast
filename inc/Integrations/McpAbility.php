<?php
/**
 * Ability `lb-simple-podcast/publish-post-audio` (expuesta como tool MCP por mcp-adapter).
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Integrations;

use LbSimplePodcast\Media\AudioIngestor;
use LbSimplePodcast\Meta\AudioMeta;
use WP_Error;
use WP_Post;

/**
 * Registra la ability con la Abilities API de WordPress (≥ 6.9).
 *
 * `mcp-adapter` convierte las abilities con `meta.mcp.public = true` en tools MCP; si el adapter
 * no está activo, la ability sigue disponible por la Abilities API / REST.
 */
final class McpAbility {

	public const NAME     = 'lb-simple-podcast/publish-post-audio';
	public const CATEGORY = 'podcast';

	/**
	 * Ingestor de audio.
	 *
	 * @var AudioIngestor
	 */
	private AudioIngestor $ingestor;

	/**
	 * Constructor.
	 *
	 * @param AudioIngestor|null $ingestor Ingestor.
	 */
	public function __construct( ?AudioIngestor $ingestor = null ) {
		$this->ingestor = $ingestor ?? new AudioIngestor();
	}

	/**
	 * Engancha los hooks solo si la Abilities API existe.
	 */
	public function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_ability' ) );
	}

	/**
	 * Registra la categoría de la ability.
	 */
	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) || ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( self::CATEGORY ) ) ) {
			return;
		}
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Podcast', 'lb-simple-podcast' ),
				'description' => __( 'Abilities para gestionar el audio de los episodios del podcast.', 'lb-simple-podcast' ),
			)
		);
	}

	/**
	 * Registra la ability.
	 */
	public function register_ability(): void {
		wp_register_ability(
			self::NAME,
			array(
				'label'               => __( 'Publicar audio en una entrada', 'lb-simple-podcast' ),
				'description'         => __( 'Adjunta un archivo MP3 a una entrada existente (desde una URL pública o en base64) para que aparezca como episodio en el feed de podcast. Envíe exactamente uno de audio_url o audio_base64. Si la entrada ya tenía un audio creado por este plugin, lo reemplaza.', 'lb-simple-podcast' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->input_schema(),
				'output_schema'       => $this->output_schema(),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					),
				),
			)
		);
	}

	/**
	 * Esquema de entrada.
	 *
	 * @return array<string, mixed>
	 */
	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'post_id' ),
			'properties'           => array(
				'post_id'      => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID de la entrada (post) a la que se adjunta el audio.', 'lb-simple-podcast' ),
				),
				'audio_url'    => array(
					'type'        => 'string',
					'description' => __( 'URL http(s) pública de un archivo MP3. Alternativa a audio_base64.', 'lb-simple-podcast' ),
				),
				'audio_base64' => array(
					'type'        => 'string',
					'description' => __( 'Contenido del MP3 en base64 (admite el prefijo data:audio/mpeg;base64,). Alternativa a audio_url.', 'lb-simple-podcast' ),
				),
				'filename'     => array(
					'type'        => 'string',
					'description' => __( 'Nombre de archivo sugerido (solo con audio_base64).', 'lb-simple-podcast' ),
				),
				'title'        => array(
					'type'        => 'string',
					'description' => __( 'Título del audio/episodio en la biblioteca de medios.', 'lb-simple-podcast' ),
				),
			),
		);
	}

	/**
	 * Esquema de salida.
	 *
	 * @return array<string, mixed>
	 */
	public function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id'                => array( 'type' => 'integer' ),
				'attachment_id'          => array( 'type' => 'integer' ),
				'url'                    => array( 'type' => 'string' ),
				'mime_type'              => array( 'type' => 'string' ),
				'filesize'               => array( 'type' => 'integer' ),
				'duration'               => array( 'type' => 'integer' ),
				'replaced_attachment_id' => array( 'type' => array( 'integer', 'null' ) ),
				'permalink'              => array( 'type' => 'string' ),
				'feed_url'               => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * Permisos: usuario autenticado que puede subir archivos y editar la entrada.
	 *
	 * Devuelve siempre un booleano (la Abilities API no admite filtrar el motivo a quien no tiene
	 * permisos): una entrada inexistente se trata como denegada, sin revelar si existe.
	 *
	 * @param mixed $input Entrada de la ability.
	 */
	public function check_permission( $input = null ): bool {
		if ( ! is_user_logged_in() || ! current_user_can( 'upload_files' ) ) {
			return false;
		}

		$post_id = is_array( $input ) && isset( $input['post_id'] ) && is_scalar( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		$post    = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post instanceof WP_Post || AudioMeta::POST_TYPE !== $post->post_type ) {
			return false;
		}

		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Ejecuta la ability.
	 *
	 * @param mixed $input Entrada validada contra el esquema.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( $input = null ) {
		$input   = is_array( $input ) ? $input : array();
		$post_id = isset( $input['post_id'] ) && is_scalar( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		$url     = isset( $input['audio_url'] ) && is_string( $input['audio_url'] ) ? trim( $input['audio_url'] ) : '';
		$base64  = isset( $input['audio_base64'] ) && is_string( $input['audio_base64'] ) ? $input['audio_base64'] : '';
		$title   = isset( $input['title'] ) && is_string( $input['title'] ) ? $input['title'] : null;
		$name    = isset( $input['filename'] ) && is_string( $input['filename'] ) ? $input['filename'] : null;

		if ( '' === $url && '' === trim( $base64 ) ) {
			return new WP_Error( 'lb_sp_missing_audio', __( 'Indique audio_url o audio_base64.', 'lb-simple-podcast' ), array( 'status' => 400 ) );
		}
		if ( '' !== $url && '' !== trim( $base64 ) ) {
			return new WP_Error( 'lb_sp_conflicting_audio', __( 'Indique solo uno: audio_url o audio_base64.', 'lb-simple-podcast' ), array( 'status' => 400 ) );
		}

		$result = '' !== $url
			? $this->ingestor->ingest_from_url( $post_id, $url, $title )
			: $this->ingestor->ingest_from_base64( $post_id, $base64, $name, $title );

		if ( is_wp_error( $result ) ) {
			if ( ! isset( $result->get_error_data()['status'] ) ) {
				$result->add_data( array( 'status' => 400 ) );
			}
			return $result;
		}

		return array(
			'post_id'                => $post_id,
			'attachment_id'          => $result['attachment_id'],
			'url'                    => $result['url'],
			'mime_type'              => $result['mime_type'],
			'filesize'               => $result['filesize'],
			'duration'               => $result['duration'],
			'replaced_attachment_id' => $result['replaced_attachment_id'],
			'permalink'              => (string) get_permalink( $post_id ),
			'feed_url'               => get_feed_link( 'podcast' ),
		);
	}
}
