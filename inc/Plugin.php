<?php
/**
 * Bootstrap del plugin.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast;

/**
 * Registra los componentes del plugin.
 */
final class Plugin {

	/**
	 * Instancia única creada por boot().
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Servicios registrados (id => objeto).
	 *
	 * @var array<string, object>
	 */
	private array $services = array();

	/**
	 * Crea (una sola vez) la instancia y registra los servicios.
	 */
	public static function boot(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register_services();
		}
		return self::$instance;
	}

	/**
	 * Devuelve la instancia ya inicializada, o null si aún no se llamó a boot().
	 */
	public static function instance(): ?Plugin {
		return self::$instance;
	}

	/**
	 * Hook de activación.
	 */
	public static function activate(): void {
		// El feed se registra en `init`, que ya pasó cuando corre la activación.
		( new Feed\FeedRegistrar() )->add_feed();
		flush_rewrite_rules();
	}

	/**
	 * Hook de desactivación.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	/**
	 * Devuelve un servicio por id.
	 *
	 * @param string $id Identificador del servicio.
	 */
	public function get( string $id ): ?object {
		return $this->services[ $id ] ?? null;
	}

	/**
	 * Lista los ids de servicios registrados.
	 *
	 * @return array<int, string>
	 */
	public function service_ids(): array {
		return array_keys( $this->services );
	}

	/**
	 * Instancia los componentes. Se amplía en cada tarea.
	 */
	private function register_services(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		/**
		 * Servicios del plugin (id => instancia). Cada uno puede exponer register().
		 *
		 * @var array<string, object> $services
		 */
		$services = array(
			'audio_meta'    => new Meta\AudioMeta(),
			'editor_assets' => new Admin\EditorAssets(),
			'meta_box'      => new Admin\MetaBox(),
			'settings_page' => new Admin\SettingsPage(),
			'feed'          => new Feed\FeedRegistrar(),
			'player'        => new Frontend\PlayerInjector(),
			'seo_headers'   => new Integrations\SeoHeaders(),
			'mcp_ability'   => new Integrations\McpAbility(),
		);

		foreach ( $services as $id => $service ) {
			$this->services[ $id ] = $service;
			if ( method_exists( $service, 'register' ) ) {
				$service->register();
			}
		}
	}

	/**
	 * Carga las traducciones.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'lb-simple-podcast', false, dirname( plugin_basename( LB_SP_FILE ) ) . '/languages' );
	}
}
