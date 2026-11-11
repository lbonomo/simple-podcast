<?php
/**
 * Limpieza al desinstalar el plugin.
 *
 * Borra las opciones del plugin. No elimina los archivos de audio de la biblioteca de medios
 * ni el meta de las entradas, para no perder contenido del usuario.
 *
 * @package LbSimplePodcast
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'lb_sp_channel' );
delete_option( 'lb_sp_feed_version' );
delete_transient( 'lb_sp_feed_body' );
