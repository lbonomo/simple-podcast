<?php
/**
 * Registro del feed `/feed/podcast/` y de la invalidación de caché.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Feed;

use LbSimplePodcast\Admin\SettingsPage;
use LbSimplePodcast\Meta\AudioMeta;

/**
 * Registra el feed con add_feed() y mantiene la versión del feed.
 */
final class FeedRegistrar {

	public const FEED = 'podcast';

	/**
	 * Renderizador.
	 *
	 * @var PodcastFeedRenderer
	 */
	private PodcastFeedRenderer $renderer;

	/**
	 * Constructor.
	 *
	 * @param PodcastFeedRenderer|null $renderer Renderizador.
	 */
	public function __construct( ?PodcastFeedRenderer $renderer = null ) {
		$this->renderer = $renderer ?? new PodcastFeedRenderer();
	}

	/**
	 * Engancha los hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'add_feed' ) );
		add_filter( 'feed_content_type', array( $this, 'filter_content_type' ), 10, 2 );
		add_filter( 'exit_on_http_head', array( $this, 'filter_exit_on_head' ) );

		// Cambios que invalidan el feed.
		add_action( 'transition_post_status', array( $this, 'on_status_change' ), 10, 3 );
		add_action( 'save_post_' . AudioMeta::POST_TYPE, array( $this, 'bump' ) );
		add_action( 'before_delete_post', array( $this, 'on_deleted_post' ) );
		add_action( 'edit_attachment', array( $this, 'bump' ) );
		add_action( 'added_post_meta', array( $this, 'on_meta_change' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'on_meta_change' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $this, 'on_meta_change' ), 10, 3 );
		add_action( 'update_option_' . SettingsPage::OPTION, array( $this, 'bump' ) );
		add_action( 'add_option_' . SettingsPage::OPTION, array( $this, 'bump' ) );
	}

	/**
	 * Registra el feed `podcast` (también se usa en la activación antes de refrescar las reglas).
	 */
	public function add_feed(): void {
		add_feed( self::FEED, array( $this, 'serve' ) );
	}

	/**
	 * Content-Type que WordPress envía para el feed `podcast` (también en HEAD).
	 *
	 * @param string $content_type Tipo actual.
	 * @param string $type         Nombre del feed.
	 */
	public function filter_content_type( $content_type, $type ): string {
		return self::FEED === $type ? 'application/rss+xml' : (string) $content_type;
	}

	/**
	 * Permite que las peticiones HEAD al feed lleguen a serve() para devolver ETag y Last-Modified.
	 *
	 * @param bool $should_exit Si WordPress debe terminar en HEAD.
	 */
	public function filter_exit_on_head( $should_exit ): bool {
		return is_feed( self::FEED ) ? false : (bool) $should_exit;
	}

	/**
	 * Envía la respuesta del feed.
	 */
	public function serve(): void {
		// WordPress agrega slashes a $_SERVER; se quitan para comparar ETag/fechas. Solo se leen cabeceras de caché.
		$server   = (array) wp_unslash( $_SERVER ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$response = $this->renderer->response( $server );

		status_header( $response['status'] );
		if ( ! headers_sent() ) {
			foreach ( $response['headers'] as $name => $value ) {
				header( $name . ': ' . $value );
			}
		}

		echo $response['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML escapado por la plantilla.
	}

	/**
	 * Invalida el feed.
	 */
	public function bump(): void {
		FeedVersion::bump();
	}

	/**
	 * Invalida al publicar, despublicar o archivar un post.
	 *
	 * @param string $new_status Estado nuevo.
	 * @param string $old_status Estado anterior.
	 * @param object $post       Post.
	 */
	public function on_status_change( $new_status, $old_status, $post ): void {
		if ( $new_status !== $old_status && is_object( $post ) && isset( $post->post_type ) && AudioMeta::POST_TYPE === $post->post_type && ( 'publish' === $new_status || 'publish' === $old_status ) ) {
			$this->bump();
		}
	}

	/**
	 * Invalida al borrar un post o un attachment (se evalúa antes de borrar para conocer el tipo).
	 *
	 * @param int $post_id ID.
	 */
	public function on_deleted_post( $post_id ): void {
		$type = get_post_type( (int) $post_id );
		if ( AudioMeta::POST_TYPE === $type || 'attachment' === $type ) {
			$this->bump();
		}
	}

	/**
	 * Invalida cuando cambia el audio asociado a un post.
	 *
	 * @param mixed  $meta_id  ID del meta (no usado).
	 * @param int    $post_id  ID del post (no usado).
	 * @param string $meta_key Clave del meta.
	 */
	public function on_meta_change( $meta_id, $post_id, $meta_key ): void {
		unset( $meta_id, $post_id );
		if ( AudioMeta::META_KEY === $meta_key ) {
			$this->bump();
		}
	}
}
