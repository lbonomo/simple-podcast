<?php
/**
 * Plantilla del reproductor de la entrada.
 *
 * Se puede sobrescribir copiando este archivo a `{tema}/lb-simple-podcast/player.php`.
 *
 * Variables disponibles:
 *
 * @var array{attachment_id: int, url: string, mime_type: string, filesize: int, duration: int} $audio Datos del audio.
 * @var WP_Post                                                                                 $post  Post actual.
 *
 * @package LbSimplePodcast
 */

defined( 'ABSPATH' ) || exit;

?>
<div class="lb-sp-player" data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>">
	<audio class="lb-sp-player__audio" controls preload="none" src="<?php echo esc_url( $audio['url'] ); ?>" aria-label="<?php echo esc_attr( get_the_title( $post ) ); ?>"></audio>
</div>
