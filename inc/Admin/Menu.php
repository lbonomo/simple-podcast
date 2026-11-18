<?php
/**
 * Menú de administración "Podcast".
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Admin;

/** Registra el menú principal y sus submenús: Dashboard, Setup y Logs. */
final class Menu {

	public const PARENT_SLUG = 'lb-sp-dashboard';
	public const SETUP_SLUG  = SettingsPage::PAGE_SLUG;
	public const LOGS_SLUG   = 'lb-sp-logs';
	public const CAPABILITY  = 'manage_options';

	/**
	 * Corta la petición con 403 si el usuario no es administrador.
	 *
	 * Defensa en profundidad: WordPress ya bloquea el acceso a las páginas del menú
	 * según la capacidad, pero cada pantalla lo vuelve a comprobar por sí misma.
	 */
	public static function authorize(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'No tienes permisos para acceder a esta página.', 'lb-simple-podcast' ),
				'',
				array( 'response' => 403 )
			);
		}
	}

	/** Engancha los hooks. */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menus' ) );
		add_action( 'admin_post_' . DashboardPage::REFRESH_ACTION, array( new DashboardPage(), 'handle_refresh' ) );
		// Prioridad 5: antes del enlace "Check for updates" (prioridad 10).
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 5, 2 );
	}

	/**
	 * Agrega el enlace a Setup en la fila del plugin (página Plugins).
	 *
	 * @param array<int|string, string> $links Enlaces de la fila.
	 * @param string                    $file  Archivo del plugin.
	 * @return array<int|string, string>
	 */
	public function row_meta( array $links, string $file ): array {
		if ( plugin_basename( LB_SP_FILE ) !== $file || ! current_user_can( self::CAPABILITY ) ) {
			return $links;
		}
		$links[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . self::SETUP_SLUG ) ),
			esc_html__( 'Configuración', 'lb-simple-podcast' )
		);
		return $links;
	}

	/** Crea el menú de primer nivel y los submenús. */
	public function add_menus(): void {
		$dashboard = new DashboardPage();
		$setup     = new SettingsPage();
		$logs      = new LogsPage();

		add_action( 'admin_enqueue_scripts', array( $dashboard, 'enqueue' ) );
		add_action( 'admin_enqueue_scripts', array( $logs, 'enqueue' ) );

		add_menu_page(
			__( 'Podcast', 'lb-simple-podcast' ),
			__( 'Podcast', 'lb-simple-podcast' ),
			self::CAPABILITY,
			self::PARENT_SLUG,
			array( $dashboard, 'render' ),
			'dashicons-microphone',
			26
		);
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Podcast: Dashboard', 'lb-simple-podcast' ),
			__( 'Dashboard', 'lb-simple-podcast' ),
			self::CAPABILITY,
			self::PARENT_SLUG,
			array( $dashboard, 'render' )
		);
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Podcast: Setup', 'lb-simple-podcast' ),
			__( 'Setup', 'lb-simple-podcast' ),
			self::CAPABILITY,
			self::SETUP_SLUG,
			array( $setup, 'render' )
		);
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Podcast: Logs', 'lb-simple-podcast' ),
			__( 'Logs', 'lb-simple-podcast' ),
			self::CAPABILITY,
			self::LOGS_SLUG,
			array( $logs, 'render' )
		);
	}
}
