<?php
/**
 * Generación del feed RSS de podcast.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Feed;

use LbSimplePodcast\Admin\SettingsPage;
use LbSimplePodcast\Meta\AudioMeta;
use WP_Post;
use WP_Query;

/**
 * Construye el XML y las cabeceras HTTP (Content-Type, ETag, Last-Modified, 304).
 */
final class PodcastFeedRenderer {

	public const CONTENT_TYPE = 'application/rss+xml; charset=UTF-8';

	/**
	 * Calcula la respuesta HTTP completa.
	 *
	 * @param array<string, mixed> $server Variables del servidor ($_SERVER).
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	public function response( array $server ): array {
		$version       = FeedVersion::get();
		$last_modified = FeedVersion::timestamp();
		$etag          = '"' . md5( $version . '|' . LB_SP_VERSION ) . '"';

		$headers = array(
			'Content-Type'  => self::CONTENT_TYPE,
			'Last-Modified' => gmdate( 'D, d M Y H:i:s', $last_modified ) . ' GMT',
			'ETag'          => $etag,
			'Cache-Control' => (string) apply_filters( 'lb_sp_feed_cache_control', 'public, max-age=0, must-revalidate' ),
		);

		if ( $this->is_not_modified( $server, $etag, $last_modified ) ) {
			return array(
				'status'  => 304,
				'headers' => $headers,
				'body'    => '',
			);
		}

		$method = isset( $server['REQUEST_METHOD'] ) && is_string( $server['REQUEST_METHOD'] ) ? strtoupper( $server['REQUEST_METHOD'] ) : 'GET';

		return array(
			'status'  => 200,
			'headers' => $headers,
			'body'    => 'HEAD' === $method ? '' : $this->body(),
		);
	}

	/**
	 * Cuerpo XML (con caché por versión del feed).
	 */
	public function body(): string {
		$cached = get_transient( 'lb_sp_feed_body' );
		if ( is_array( $cached ) && isset( $cached['version'], $cached['xml'] ) && FeedVersion::get() === $cached['version'] && is_string( $cached['xml'] ) ) {
			return $cached['xml'];
		}

		$xml = $this->render( $this->channel(), $this->items() );
		set_transient(
			'lb_sp_feed_body',
			array(
				'version' => FeedVersion::get(),
				'xml'     => $xml,
			),
			(int) apply_filters( 'lb_sp_feed_cache_ttl', 12 * HOUR_IN_SECONDS )
		);

		return $xml;
	}

	/**
	 * Aplica la plantilla a los datos preparados.
	 *
	 * @param array<string, mixed>             $channel Datos del canal.
	 * @param array<int, array<string, mixed>> $items   Episodios.
	 */
	public function render( array $channel, array $items ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Las usa la plantilla.
		ob_start();
		require LB_SP_DIR . 'templates/feed-podcast.php';
		return (string) ob_get_clean();
	}

	/**
	 * Datos del canal.
	 *
	 * @return array<string, mixed>
	 */
	public function channel(): array {
		$settings = SettingsPage::get_channel();
		$image    = $settings['image_id'] > 0 ? wp_get_attachment_url( $settings['image_id'] ) : false;
		$title    = Xml::plain( $settings['title'] );
		$summary  = Xml::plain( $settings['description'] );

		$channel = array(
			'title'       => $title,
			'link'        => home_url( '/' ),
			'self'        => get_feed_link( 'podcast' ),
			'description' => '' !== $summary ? $summary : $title,
			'language'    => $settings['language'],
			'copyright'   => Xml::plain( $settings['copyright'] ),
			'author'      => Xml::plain( $settings['author'] ),
			'owner_name'  => Xml::plain( $settings['owner_name'] ),
			'owner_email' => $settings['owner_email'],
			'image'       => is_string( $image ) ? $image : '',
			'category'    => $settings['category'],
			'explicit'    => $settings['explicit'],
			'last_build'  => gmdate( 'D, d M Y H:i:s +0000', FeedVersion::timestamp() ),
			'generator'   => 'LB Simple Podcast ' . LB_SP_VERSION,
		);

		/**
		 * Filtra los datos del canal antes de renderizar.
		 *
		 * @param array<string, mixed> $channel Datos del canal.
		 */
		return (array) apply_filters( 'lb_sp_feed_channel', $channel );
	}

	/**
	 * Episodios del feed: posts publicados con un MP3 válido.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function items(): array {
		$channel = SettingsPage::get_channel();
		$items   = array();

		foreach ( $this->query()->posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$audio = AudioMeta::get_audio_data( $post->ID );
			if ( null === $audio ) {
				continue;
			}

			$title   = Xml::plain( $post->post_title );
			$summary = $this->summary( $post );

			$items[] = array(
				'id'           => $post->ID,
				'title'        => '' !== $title ? $title : (string) $post->ID,
				'link'         => get_permalink( $post ),
				'guid'         => $this->guid( $post ),
				'pub_date'     => Xml::rfc2822( $post->post_date_gmt ),
				'summary'      => $summary,
				'content_html' => $this->content_html( $post ),
				'audio'        => $audio,
				'explicit'     => (string) apply_filters( 'lb_sp_episode_explicit', $channel['explicit'], $post ),
				'episode_type' => (string) apply_filters( 'lb_sp_episode_type', 'full', $post ),
			);
		}

		/**
		 * Filtra los episodios antes de renderizar.
		 *
		 * @param array<int, array<string, mixed>> $items Episodios.
		 */
		return (array) apply_filters( 'lb_sp_feed_items', $items );
	}

	/**
	 * Consulta de posts: publicados, sin contraseña y con `_lb_sp_audio_id` numérico > 0.
	 */
	public function query(): WP_Query {
		/**
		 * Máximo de episodios en el feed.
		 *
		 * @param int $limit Límite (por defecto 100).
		 */
		$limit = max( 1, (int) apply_filters( 'lb_sp_feed_limit', 100 ) );

		return new WP_Query(
			array(
				'post_type'              => AudioMeta::POST_TYPE,
				'post_status'            => 'publish',
				'has_password'           => false,
				'posts_per_page'         => $limit,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => AudioMeta::META_KEY,
						'value'   => 0,
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
	}

	/**
	 * GUID persistente: el `guid` original de WordPress (no cambia al editar).
	 *
	 * @param WP_Post $post Post.
	 */
	private function guid( WP_Post $post ): string {
		return '' !== $post->guid ? $post->guid : 'lb-sp-' . $post->ID;
	}

	/**
	 * Resumen en texto plano (extracto o primeras palabras).
	 *
	 * @param WP_Post $post Post.
	 */
	private function summary( WP_Post $post ): string {
		$source = '' !== trim( $post->post_excerpt ) ? $post->post_excerpt : wp_trim_words( strip_shortcodes( $post->post_content ), 55, '…' );
		return mb_substr( Xml::plain( $source ), 0, 4000 );
	}

	/**
	 * Contenido HTML completo del episodio (para content:encoded).
	 *
	 * @param WP_Post $post Post.
	 */
	private function content_html( WP_Post $post ): string {
		$html = apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Filtro de WordPress.
		return is_string( $html ) ? $html : '';
	}

	/**
	 * Evalúa If-None-Match / If-Modified-Since.
	 *
	 * @param array<string, mixed> $server        Variables del servidor.
	 * @param string               $etag          ETag actual.
	 * @param int                  $last_modified Marca temporal actual.
	 */
	private function is_not_modified( array $server, string $etag, int $last_modified ): bool {
		$none_match = isset( $server['HTTP_IF_NONE_MATCH'] ) && is_string( $server['HTTP_IF_NONE_MATCH'] ) ? trim( $server['HTTP_IF_NONE_MATCH'] ) : '';
		if ( '' !== $none_match ) {
			foreach ( explode( ',', $none_match ) as $candidate ) {
				// Se ignora el prefijo débil (W/) y el sufijo que mod_deflate/mod_brotli agregan al ETag.
				$candidate = trim( (string) preg_replace( array( '#^W/#', '#-(gzip|br|deflate)"$#' ), array( '', '"' ), trim( $candidate ) ) );
				if ( '*' === $candidate || $candidate === $etag ) {
					return true;
				}
			}
			return false;
		}

		$since = isset( $server['HTTP_IF_MODIFIED_SINCE'] ) && is_string( $server['HTTP_IF_MODIFIED_SINCE'] ) ? strtotime( $server['HTTP_IF_MODIFIED_SINCE'] ) : false;
		return false !== $since && $since >= $last_modified;
	}
}
