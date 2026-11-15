<?php
/**
 * Tests del feed RSS de podcast.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Tests\Integration;

use DOMDocument;
use LbSimplePodcast\Admin\SettingsPage;
use LbSimplePodcast\Feed\FeedRegistrar;
use LbSimplePodcast\Feed\FeedVersion;
use LbSimplePodcast\Feed\PodcastFeedRenderer;
use LbSimplePodcast\Meta\AudioMeta;
use LbSimplePodcast\Media\AudioDelivery;
use SimpleXMLElement;
use WP_UnitTestCase;

/**
 * Contenido, cabeceras y caché del feed.
 */
final class FeedOutputTest extends WP_UnitTestCase {

	private const ITUNES = 'http://www.itunes.com/dtds/podcast-1.0.dtd';

	/**
	 * ID de un MP3 válido.
	 *
	 * @var int
	 */
	private int $mp3_id = 0;

	/**
	 * Renderizador bajo prueba.
	 *
	 * @var PodcastFeedRenderer
	 */
	private PodcastFeedRenderer $renderer;

	public function set_up(): void {
		parent::set_up();
		( new AudioMeta() )->register_meta();
		( new FeedRegistrar() )->add_feed();
		$this->renderer = new PodcastFeedRenderer();
		$this->mp3_id   = (int) self::factory()->attachment->create_upload_object( dirname( __DIR__ ) . '/fixtures/sample.mp3' );
		delete_transient( 'lb_sp_feed_body' );
	}

	/**
	 * Crea un post con (o sin) audio.
	 *
	 * @param array<string, mixed> $args      Argumentos de wp_insert_post.
	 * @param bool                 $with_audio Si se asocia el MP3.
	 */
	private function episode( array $args = array(), bool $with_audio = true ): int {
		$post_id = self::factory()->post->create( $args + array( 'post_status' => 'publish' ) );
		if ( $with_audio ) {
			update_post_meta( $post_id, AudioMeta::META_KEY, $this->mp3_id );
		}
		return $post_id;
	}

	private function xml( ?string $body = null ): SimpleXMLElement {
		$body = $body ?? $this->renderer->response( array() )['body'];
		$dom  = new DOMDocument();
		$this->assertTrue( $dom->loadXML( $body ), 'El feed debe ser XML bien formado.' );
		$xml = simplexml_import_dom( $dom );
		$this->assertInstanceOf( SimpleXMLElement::class, $xml );
		return $xml;
	}

	public function test_only_published_posts_with_valid_audio_are_listed(): void {
		$with_audio = $this->episode( array( 'post_title' => 'Con audio' ) );
		$this->episode(
			array(
				'post_title'  => 'Borrador',
				'post_status' => 'draft',
			)
		);
		$this->episode(
			array(
				'post_title'  => 'Privado',
				'post_status' => 'private',
			)
		);
		$this->episode(
			array(
				'post_title'    => 'Con clave',
				'post_password' => 'secreto',
			)
		);
		$this->episode( array( 'post_title' => 'Sin audio' ), false );

		$titles = array_map( 'strval', $this->xml()->xpath( '//item/title' ) );

		$this->assertSame( array( 'Con audio' ), $titles );
		$this->assertNotSame( 0, $with_audio );
	}

	public function test_post_whose_audio_is_zero_or_missing_is_excluded(): void {
		$post_id = $this->episode( array( 'post_title' => 'Cero' ), false );
		add_post_meta( $post_id, AudioMeta::META_KEY, 0 );

		$this->assertCount( 0, $this->xml()->xpath( '//item' ) );
	}

	public function test_response_is_200_with_rss_content_type(): void {
		$this->episode();

		$response = $this->renderer->response( array() );

		$this->assertSame( 200, $response['status'] );
		$this->assertSame( 'application/rss+xml; charset=UTF-8', $response['headers']['Content-Type'] );
		$this->assertStringStartsWith( '<?xml version="1.0" encoding="UTF-8"?>', $response['body'] );
		$this->assertMatchesRegularExpression( '/^[a-z]{3}, \d{2} [a-z]{3} \d{4} \d{2}:\d{2}:\d{2} GMT$/i', $response['headers']['Last-Modified'] );
		$this->assertMatchesRegularExpression( '/^"[0-9a-f]{32}"$/', $response['headers']['ETag'] );
	}

	public function test_rss_root_declares_required_namespaces(): void {
		$body = $this->renderer->response( array() )['body'];

		$this->assertStringContainsString( 'xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"', $body );
		$this->assertStringContainsString( 'xmlns:content="http://purl.org/rss/1.0/modules/content/"', $body );
		$this->assertStringContainsString( '<rss version="2.0"', $body );
	}

	public function test_item_has_enclosure_guid_and_itunes_tags(): void {
		$post_id = $this->episode(
			array(
				'post_title'   => 'Episodio 1',
				'post_excerpt' => 'Un resumen',
			)
		);
		$xml     = $this->xml();
		$item    = $xml->channel->item[0];
		$file    = dirname( __DIR__ ) . '/fixtures/sample.mp3';

		$enclosure = $item->enclosure->attributes();
		$this->assertSame( AudioDelivery::url( $post_id, $this->mp3_id ), (string) $enclosure['url'] );
		$this->assertSame( (string) filesize( $file ), (string) $enclosure['length'] );
		$this->assertSame( 'audio/mpeg', (string) $enclosure['type'] );

		$this->assertSame( 'false', (string) $item->guid->attributes()['isPermaLink'] );
		$this->assertSame( get_post( $post_id )->guid, (string) $item->guid );

		$itunes = $item->children( self::ITUNES );
		$this->assertMatchesRegularExpression( '/^\d{2}:\d{2}:\d{2}$/', (string) $itunes->duration );
		$this->assertSame( 'false', (string) $itunes->explicit );
		$this->assertSame( 'Un resumen', (string) $itunes->summary );
		$this->assertSame( 'full', (string) $itunes->episodeType ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
	}

	/**
	 * Preservation (enclosure-url-returns-html): guid, length, type y duration no cambian; solo `url` puede variar.
	 */
	public function test_preserved_enclosure_guid_length_type_and_duration_for_every_item(): void {
		$ids   = array(
			$this->episode( array( 'post_title' => 'Uno' ) ),
			$this->episode( array( 'post_title' => 'Dos' ) ),
			$this->episode( array( 'post_title' => 'Tres' ) ),
		);
		$size  = (string) filesize( dirname( __DIR__ ) . '/fixtures/sample.mp3' );
		$items = $this->xml()->xpath( '//item' );

		$this->assertCount( 3, $items );
		$seen = array();
		foreach ( $items as $item ) {
			$enclosure = $item->enclosure->attributes();
			$this->assertSame( $size, (string) $enclosure['length'] );
			$this->assertSame( 'audio/mpeg', (string) $enclosure['type'] );
			$this->assertNotSame( '', (string) $enclosure['url'] );
			$this->assertSame( 'false', (string) $item->guid->attributes()['isPermaLink'] );
			$this->assertMatchesRegularExpression( '/^\d{2}:\d{2}:\d{2}$/', (string) $item->children( self::ITUNES )->duration );
			$seen[] = (string) $item->guid;
		}
		$expected = array_map( static fn( int $id ): string => get_post( $id )->guid, $ids );
		sort( $expected );
		sort( $seen );
		$this->assertSame( $expected, $seen );
	}

	public function test_bug_condition_enclosure_url_matches_delivery_url_and_is_not_site_root(): void {
		// Con permalinks planos el permalink es home_url( '/?p=N' ) y la URL empieza legítimamente por home_url( '/?' ).
		// Se fijan permalinks bonitos para que la aserción de "no raíz con query" sea válida.
		$this->set_permalink_structure( '/%postname%/' );
		flush_rewrite_rules();
		try {
			$post_id = $this->episode();

			$url = (string) $this->xml()->channel->item[0]->enclosure->attributes()['url'];

			$this->assertSame( AudioDelivery::url( $post_id, $this->mp3_id ), $url );
			$this->assertStringStartsNotWith( home_url( '/?' ), $url );
		} finally {
			$this->set_permalink_structure( '' );
			flush_rewrite_rules();
		}
	}

	public function test_bug_condition_enclosure_url_is_not_site_root_without_permalink(): void {
		$post_id = $this->episode();
		add_filter( 'post_link', '__return_false' );
		try {
			$url = (string) $this->xml()->channel->item[0]->enclosure->attributes()['url'];

			$this->assertSame( AudioDelivery::url( $post_id, $this->mp3_id ), $url );
			$this->assertStringStartsNotWith( home_url( '/?' ), $url );
			$this->assertStringStartsWith( home_url( '/index.php' ), $url );
		} finally {
			remove_filter( 'post_link', '__return_false' );
		}
	}

	public function test_pub_date_is_rfc_2822(): void {
		$this->episode(
			array(
				'post_date'     => '2024-03-05 10:00:00',
				'post_date_gmt' => '2024-03-05 13:00:00',
			)
		);

		$date = (string) $this->xml()->channel->item[0]->pubDate;

		$this->assertSame( 'Tue, 05 Mar 2024 13:00:00 +0000', $date );
		$this->assertNotFalse( strtotime( $date ) );
	}

	public function test_special_characters_are_escaped_and_roundtrip(): void {
		$this->episode( array( 'post_title' => 'Rock & Roll "vivo" <b>es</b> l\'mejor' ) );

		$body  = $this->renderer->response( array() )['body'];
		$title = (string) $this->xml( $body )->channel->item[0]->title;

		$this->assertSame( 'Rock & Roll "vivo" es l\'mejor', $title );
		$this->assertStringNotContainsString( '&rsquo;', $body );
		$this->assertStringContainsString( '<title>Rock &amp; Roll &quot;vivo&quot; es l&apos;mejor</title>', $body );
	}

	public function test_content_encoded_is_cdata_safe(): void {
		$this->episode( array( 'post_content' => 'Texto ]]> raro <strong>negrita</strong>' ) );

		$xml = $this->xml();

		$this->assertStringContainsString( 'negrita', (string) $xml->channel->item[0]->children( 'http://purl.org/rss/1.0/modules/content/' )->encoded );
	}

	public function test_channel_uses_settings(): void {
		update_option(
			SettingsPage::OPTION,
			array(
				'title'       => 'Mi Show & Co',
				'description' => 'Descripción del show',
				'language'    => 'es-AR',
				'category'    => 'Kids & Family',
				'author'      => 'Ana',
				'owner_name'  => 'Ana Dueña',
				'owner_email' => 'ana@example.org',
				'explicit'    => 'true',
				'copyright'   => '© 2024',
			)
		);
		$xml     = $this->xml();
		$channel = $xml->channel;
		$itunes  = $channel->children( self::ITUNES );

		$this->assertSame( 'Mi Show & Co', (string) $channel->title );
		$this->assertSame( 'es-AR', (string) $channel->language );
		$this->assertSame( 'Descripción del show', (string) $channel->description );
		$this->assertSame( 'Kids & Family', (string) $itunes->category->attributes()['text'] );
		$this->assertSame( 'Ana', (string) $itunes->author );
		$this->assertSame( 'ana@example.org', (string) $itunes->owner->children( self::ITUNES )->email );
		$this->assertSame( 'true', (string) $itunes->explicit );
		$this->assertSame( '© 2024', (string) $channel->copyright );
	}

	public function test_channel_image_is_rendered(): void {
		$image = (int) self::factory()->attachment->create_upload_object( dirname( __DIR__ ) . '/fixtures/sample.mp3' );
		update_option( SettingsPage::OPTION, array( 'image_id' => $image ) );

		$href = (string) $this->xml()->channel->children( self::ITUNES )->image->attributes()['href'];

		$this->assertSame( wp_get_attachment_url( $image ), $href );
	}

	public function test_returns_304_with_matching_etag(): void {
		$this->episode();
		$first = $this->renderer->response( array() );

		$second = $this->renderer->response( array( 'HTTP_IF_NONE_MATCH' => $first['headers']['ETag'] ) );

		$this->assertSame( 304, $second['status'] );
		$this->assertSame( '', $second['body'] );
		$this->assertSame( $first['headers']['ETag'], $second['headers']['ETag'] );
	}

	public function test_returns_304_with_if_modified_since(): void {
		$this->episode();
		$first = $this->renderer->response( array() );

		$second = $this->renderer->response( array( 'HTTP_IF_MODIFIED_SINCE' => $first['headers']['Last-Modified'] ) );

		$this->assertSame( 304, $second['status'] );
	}

	public function test_old_if_modified_since_returns_200(): void {
		$this->episode();

		$response = $this->renderer->response( array( 'HTTP_IF_MODIFIED_SINCE' => 'Mon, 01 Jan 2001 00:00:00 GMT' ) );

		$this->assertSame( 200, $response['status'] );
	}

	public function test_different_etag_returns_200(): void {
		$this->episode();

		$response = $this->renderer->response( array( 'HTTP_IF_NONE_MATCH' => '"otro"' ) );

		$this->assertSame( 200, $response['status'] );
		$this->assertNotSame( '', $response['body'] );
	}

	public function test_head_request_has_no_body(): void {
		$this->episode();

		$response = $this->renderer->response( array( 'REQUEST_METHOD' => 'HEAD' ) );

		$this->assertSame( 200, $response['status'] );
		$this->assertSame( '', $response['body'] );
		$this->assertSame( 'application/rss+xml; charset=UTF-8', $response['headers']['Content-Type'] );
	}

	public function test_publishing_changes_the_etag(): void {
		$this->episode();
		$before = $this->renderer->response( array() )['headers']['ETag'];

		usleep( 2000 );
		$this->episode( array( 'post_title' => 'Nuevo' ) );
		$after = $this->renderer->response( array() );

		$this->assertNotSame( $before, $after['headers']['ETag'] );
		$this->assertStringContainsString( 'Nuevo', $after['body'] );
	}

	public function test_unpublishing_and_deleting_invalidate_the_feed(): void {
		$post_id = $this->episode( array( 'post_title' => 'Se va' ) );
		$this->assertStringContainsString( 'Se va', $this->renderer->response( array() )['body'] );

		usleep( 2000 );
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			)
		);
		$this->assertStringNotContainsString( 'Se va', $this->renderer->response( array() )['body'] );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);
		$this->assertStringContainsString( 'Se va', $this->renderer->response( array() )['body'] );

		wp_delete_post( $post_id, true );
		$this->assertStringNotContainsString( 'Se va', $this->renderer->response( array() )['body'] );
	}

	public function test_removing_audio_invalidates_the_feed(): void {
		$post_id = $this->episode( array( 'post_title' => 'Sin audio luego' ) );
		$this->assertStringContainsString( 'Sin audio luego', $this->renderer->response( array() )['body'] );

		delete_post_meta( $post_id, AudioMeta::META_KEY );

		$this->assertStringNotContainsString( 'Sin audio luego', $this->renderer->response( array() )['body'] );
	}

	public function test_changing_settings_changes_the_version(): void {
		$before = FeedVersion::get();
		usleep( 2000 );

		update_option( SettingsPage::OPTION, array( 'title' => 'Otro título' ) );

		$this->assertNotSame( $before, FeedVersion::get() );
	}

	public function test_item_limit_is_filterable(): void {
		$this->episode( array( 'post_title' => 'Uno' ) );
		$this->episode( array( 'post_title' => 'Dos' ) );
		add_filter( 'lb_sp_feed_limit', static fn(): int => 1 );

		$this->assertCount( 1, $this->xml()->xpath( '//item' ) );
	}

	public function test_feed_is_registered_and_reachable_through_do_feed(): void {
		$this->episode( array( 'post_title' => 'Via do_feed' ) );
		$this->assertContains( 'podcast', $GLOBALS['wp_rewrite']->feeds );

		$this->go_to( '/?feed=podcast' );
		ob_start();
		do_feed();
		$body = (string) ob_get_clean();

		$this->assertTrue( is_feed() );
		$this->assertStringContainsString( '<title>Via do_feed</title>', $body );
	}

	public function test_pretty_permalinks_include_the_podcast_feed_rule(): void {
		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( '/%postname%/' );
		$wp_rewrite->flush_rules();

		$rules = implode( ' ', array_keys( $wp_rewrite->rewrite_rules() ) );

		$this->assertStringContainsString( 'podcast', $rules );
	}

	public function test_content_type_filter_applies_only_to_the_podcast_feed(): void {
		$this->assertSame( 'application/rss+xml', apply_filters( 'feed_content_type', 'application/octet-stream', 'podcast' ) );
		$this->assertSame( 'application/rss+xml', apply_filters( 'feed_content_type', 'application/rss+xml', 'rss2' ) );
		$this->assertSame( 'text/foo', apply_filters( 'feed_content_type', 'text/foo', 'other' ) );
	}

	public function test_head_requests_are_not_cut_short_for_the_podcast_feed(): void {
		$this->go_to( '/?feed=podcast' );
		$this->assertFalse( apply_filters( 'exit_on_http_head', true ) );

		$this->go_to( '/' );
		$this->assertTrue( apply_filters( 'exit_on_http_head', true ) );
	}

	public function test_deleting_unrelated_posts_does_not_invalidate_the_feed(): void {
		$page   = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$before = FeedVersion::get();
		usleep( 2000 );

		wp_delete_post( $page, true );

		$this->assertSame( $before, FeedVersion::get() );
	}

	public function test_304_ignores_compression_suffix_added_by_the_web_server(): void {
		$this->episode();
		$etag = $this->renderer->response( array() )['headers']['ETag'];

		$gzip = $this->renderer->response( array( 'HTTP_IF_NONE_MATCH' => substr( $etag, 0, -1 ) . '-gzip"' ) );
		$weak = $this->renderer->response( array( 'HTTP_IF_NONE_MATCH' => 'W/' . $etag ) );

		$this->assertSame( 304, $gzip['status'] );
		$this->assertSame( 304, $weak['status'] );
	}

	public function test_generator_uses_the_plugin_version_constant(): void {
		$this->episode();

		$this->assertSame( 'LB Simple Podcast ' . LB_SP_VERSION, (string) $this->xml()->channel->generator );
	}
}
