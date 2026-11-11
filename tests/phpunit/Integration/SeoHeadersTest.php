<?php
/**
 * Tests de Open Graph y JSON-LD.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Tests\Integration;

use LbSimplePodcast\Integrations\SeoHeaders;
use LbSimplePodcast\Meta\AudioMeta;
use WP_UnitTestCase;

/**
 * Salida de wp_head para entradas con y sin audio.
 */
final class SeoHeadersTest extends WP_UnitTestCase {

	/**
	 * ID de un MP3 válido.
	 *
	 * @var int
	 */
	private int $mp3_id = 0;

	public function set_up(): void {
		parent::set_up();
		( new AudioMeta() )->register_meta();
		$this->mp3_id = (int) self::factory()->attachment->create_upload_object( dirname( __DIR__ ) . '/fixtures/sample.mp3' );
	}

	private function head_for( int $post_id ): string {
		$this->go_to( get_permalink( $post_id ) );
		ob_start();
		do_action( 'wp_head' );
		return (string) ob_get_clean();
	}

	private function post_with_audio( array $args = array() ): int {
		$post_id = self::factory()->post->create( $args + array( 'post_title' => 'Ep 1 & más' ) );
		update_post_meta( $post_id, AudioMeta::META_KEY, $this->mp3_id );
		return $post_id;
	}

	/**
	 * Extrae y decodifica el JSON-LD de AudioObject.
	 *
	 * @param string $html HTML de la cabecera.
	 * @return array<string, mixed>
	 */
	private function json_ld( string $html ): array {
		preg_match_all( '#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches );
		foreach ( $matches[1] as $json ) {
			$data = json_decode( $json, true );
			if ( is_array( $data ) && 'AudioObject' === ( $data['@type'] ?? '' ) ) {
				return $data;
			}
		}
		return array();
	}

	public function test_post_with_audio_outputs_open_graph(): void {
		$head = $this->head_for( $this->post_with_audio() );
		$url  = wp_get_attachment_url( $this->mp3_id );

		$this->assertStringContainsString( '<meta property="og:audio" content="' . esc_url( $url ) . '" />', $head );
		$this->assertStringContainsString( '<meta property="og:audio:type" content="audio/mpeg" />', $head );
		$this->assertSame( 1, substr_count( $head, 'property="og:audio"' ) );
	}

	public function test_post_with_audio_outputs_valid_json_ld(): void {
		$post_id = $this->post_with_audio();
		$head    = $this->head_for( $post_id );
		$data    = $this->json_ld( $head );

		$this->assertSame( 'https://schema.org', $data['@context'] );
		$this->assertSame( 'AudioObject', $data['@type'] );
		$this->assertSame( wp_get_attachment_url( $this->mp3_id ), $data['contentUrl'] );
		$this->assertSame( 'audio/mpeg', $data['encodingFormat'] );
		$this->assertMatchesRegularExpression( '/^PT(\d+M)?\d+S$/', $data['duration'] );
		$this->assertSame( 'Ep 1 & más', $data['name'] );
	}

	public function test_json_ld_cannot_break_out_of_the_script_tag(): void {
		$post_id = $this->post_with_audio( array( 'post_title' => '</script><script>alert(1)</script>' ) );

		$head = $this->head_for( $post_id );

		$this->assertStringNotContainsString( '<script>alert(1)', $head );
		$this->assertNotEmpty( $this->json_ld( $head ) );
	}

	public function test_post_without_audio_outputs_nothing(): void {
		$head = $this->head_for( self::factory()->post->create() );

		$this->assertStringNotContainsString( 'og:audio', $head );
		$this->assertStringNotContainsString( 'AudioObject', $head );
	}

	public function test_page_and_home_output_nothing(): void {
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_post_meta( $page, AudioMeta::META_KEY, $this->mp3_id );

		$this->assertStringNotContainsString( 'og:audio', $this->head_for( $page ) );

		$this->go_to( '/' );
		ob_start();
		do_action( 'wp_head' );
		$this->assertStringNotContainsString( 'og:audio', (string) ob_get_clean() );
	}

	public function test_existing_open_graph_audio_is_not_duplicated(): void {
		$other = static function (): void {
			echo '<meta property="og:audio" content="https://cdn.example.org/otro.mp3" />' . "\n";
		};
		add_action( 'wp_head', $other, 5 );

		$head = $this->head_for( $this->post_with_audio() );

		$this->assertSame( 1, substr_count( $head, 'property="og:audio"' ) );
		$this->assertStringContainsString( 'cdn.example.org/otro.mp3', $head );
		$this->assertStringContainsString( 'AudioObject', $head, 'El JSON-LD sí falta y se agrega.' );
	}

	public function test_existing_json_ld_audio_object_is_not_duplicated(): void {
		$other = static function (): void {
			echo '<script type="application/ld+json">{"@context":"https://schema.org","@type":"AudioObject","contentUrl":"https://x.test/a.mp3"}</script>' . "\n";
		};
		add_action( 'wp_head', $other, 5 );

		$head = $this->head_for( $this->post_with_audio() );

		$this->assertSame( 1, substr_count( $head, '"AudioObject"' ) );
		$this->assertStringContainsString( 'property="og:audio"', $head );
	}

	public function test_output_can_be_disabled_with_filters(): void {
		add_filter( 'lb_sp_seo_output_og', '__return_false' );
		add_filter( 'lb_sp_seo_output_jsonld', '__return_false' );

		$head = $this->head_for( $this->post_with_audio() );

		$this->assertStringNotContainsString( 'og:audio', $head );
		$this->assertStringNotContainsString( 'AudioObject', $head );
	}

	public function test_other_head_output_is_preserved_in_order(): void {
		add_action(
			'wp_head',
			static function (): void {
				echo "<!-- antes -->\n";
			},
			1
		);
		add_action(
			'wp_head',
			static function (): void {
				echo "<!-- despues -->\n";
			},
			50
		);

		$head = $this->head_for( $this->post_with_audio() );

		$this->assertLessThan( strpos( $head, '<!-- despues -->' ), strpos( $head, '<!-- antes -->' ) );
		$this->assertLessThan( strpos( $head, 'og:audio' ), strpos( $head, '<!-- despues -->' ) );
	}

	public function test_password_protected_post_does_not_leak_the_audio_url(): void {
		$post_id = $this->post_with_audio( array( 'post_password' => 'secreto' ) );

		$this->assertStringNotContainsString( 'og:audio', $this->head_for( $post_id ) );
	}

	/**
	 * @dataProvider durations
	 *
	 * @param int    $seconds  Segundos.
	 * @param string $expected Duración ISO 8601.
	 */
	public function test_iso8601_duration( int $seconds, string $expected ): void {
		$this->assertSame( $expected, SeoHeaders::iso8601_duration( $seconds ) );
	}

	/**
	 * Casos de duración.
	 *
	 * @return array<string, array{0: int, 1: string}>
	 */
	public function durations(): array {
		return array(
			'ejemplo del requerimiento' => array( 345, 'PT5M45S' ),
			'solo segundos'             => array( 45, 'PT45S' ),
			'minutos exactos'           => array( 120, 'PT2M0S' ),
			'horas'                     => array( 3723, 'PT1H2M3S' ),
			'hora exacta'               => array( 3600, 'PT1H0M0S' ),
			'cero'                      => array( 0, 'PT0S' ),
		);
	}
}
