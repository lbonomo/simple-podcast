<?php
/**
 * Tests del registro de errores y de su panel en Settings.
 *
 * @package LbSimplePodcast
 */

declare(strict_types=1);

namespace LbSimplePodcast\Tests\Integration;

use LbSimplePodcast\Admin\ErrorsPanel;
use LbSimplePodcast\Admin\SettingsPage;
use LbSimplePodcast\Tracking\ErrorLog;
use LbSimplePodcast\Tracking\RequestLog;
use WP_UnitTestCase;

/** Entradas, lectura, avisos y permisos del registro de errores. */
final class ErrorLogTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new ErrorLog() )->clear();
	}

	public function tear_down(): void {
		( new ErrorLog() )->clear();
		delete_option( ErrorLog::VERSION_OPTION );
		delete_option( 'lb_sp_log_error' );
		delete_option( 'lb_sp_storage_warning' );
		parent::tear_down();
	}

	private function admin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Salida de un callback.
	 *
	 * @param callable $callback Función a ejecutar.
	 */
	private function output( callable $callback ): string {
		ob_start();
		$callback();
		return (string) ob_get_clean();
	}

	public function test_entry_stores_date_source_reason_and_context(): void {
		$log = new ErrorLog();
		$log->add( ErrorLog::SOURCE_LOG_INSERT, 'Disco lleno', ErrorLog::LEVEL_ERROR, 12, 34 );

		$entries = $log->all();
		$this->assertCount( 1, $entries );
		$this->assertSame( ErrorLog::SOURCE_LOG_INSERT, $entries[0]['source'] );
		$this->assertSame( 'Disco lleno', $entries[0]['message'] );
		$this->assertSame( 12, $entries[0]['post_id'] );
		$this->assertSame( 34, $entries[0]['attachment_id'] );
		$this->assertEqualsWithDelta( time(), $entries[0]['time'], 5 );
		$this->assertFalse( $entries[0]['read'] );
		$this->assertSame( 1, $entries[0]['count'] );
	}

	public function test_option_is_not_autoloaded(): void {
		global $wpdb;
		( new ErrorLog() )->add( ErrorLog::SOURCE_DELIVERY, 'x' );
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", ErrorLog::OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertContains( $autoload, array( 'no', 'off', 'auto-off' ) );
	}

	public function test_message_is_sanitized_and_capped(): void {
		$log = new ErrorLog();
		$log->add( ErrorLog::SOURCE_DELIVERY, '<script>alert(1)</script>' . str_repeat( 'a', 2000 ) );
		$message = $log->all()[0]['message'];

		$this->assertStringNotContainsString( '<script>', $message );
		$this->assertLessThanOrEqual( ErrorLog::MAX_MESSAGE, strlen( $message ) );
	}

	public function test_repeated_error_is_collapsed_and_does_not_rewrite_inside_the_interval(): void {
		$log = new ErrorLog();
		$log->add( ErrorLog::SOURCE_LOG_INSERT, 'Mismo error' );
		$log->add( ErrorLog::SOURCE_LOG_INSERT, 'Mismo error' );
		$this->assertCount( 1, $log->all() );
		$this->assertSame( 1, $log->all()[0]['count'] );

		// Pasado el intervalo, el contador sube sin crear otra entrada.
		$raw            = get_option( ErrorLog::OPTION );
		$raw[0]['last'] = time() - ErrorLog::REPEAT_INTERVAL - 1;
		update_option( ErrorLog::OPTION, $raw, false );
		$log->add( ErrorLog::SOURCE_LOG_INSERT, 'Mismo error' );
		$this->assertCount( 1, $log->all() );
		$this->assertSame( 2, $log->all()[0]['count'] );

		$log->add( ErrorLog::SOURCE_LOG_INSERT, 'Otro error' );
		$this->assertCount( 2, $log->all() );
	}

	public function test_log_keeps_only_the_latest_entries(): void {
		$log = new ErrorLog();
		foreach ( range( 1, ErrorLog::MAX_ENTRIES + 10 ) as $n ) {
			$log->add( ErrorLog::SOURCE_DELIVERY, 'Error ' . $n );
		}
		$entries = $log->all();

		$this->assertCount( ErrorLog::MAX_ENTRIES, $entries );
		$this->assertSame( 'Error ' . ( ErrorLog::MAX_ENTRIES + 10 ), $entries[0]['message'] );
	}

	public function test_mark_read_and_mark_all_read(): void {
		$log = new ErrorLog();
		$log->add( ErrorLog::SOURCE_LOG_INSERT, 'A' );
		$log->add( ErrorLog::SOURCE_LOG_INSERT, 'B' );
		$log->add( ErrorLog::SOURCE_DELIVERY, 'C', ErrorLog::LEVEL_WARNING );
		$this->assertSame( 2, $log->unread_error_count(), 'Los avisos no cuentan como errores.' );

		$this->assertTrue( $log->mark_read( $log->all()[1]['id'] ) );
		$this->assertFalse( $log->mark_read( 'no-existe' ) );
		$this->assertSame( 1, $log->unread_error_count() );

		$this->assertSame( 2, $log->mark_all_read() );
		$this->assertSame( 0, $log->unread_error_count() );
		$this->assertCount( 3, $log->all(), 'Marcar como leído no borra.' );

		$log->clear();
		$this->assertSame( array(), $log->all() );
	}

	public function test_read_error_reopens_only_after_a_day(): void {
		$log = new ErrorLog();
		$log->add( ErrorLog::SOURCE_LOG_INSERT, 'Vuelve' );
		$log->mark_all_read();

		$raw            = get_option( ErrorLog::OPTION );
		$raw[0]['last'] = time() - ErrorLog::REPEAT_INTERVAL - 1;
		update_option( ErrorLog::OPTION, $raw, false );
		$log->add( ErrorLog::SOURCE_LOG_INSERT, 'Vuelve' );
		$this->assertTrue( $log->all()[0]['read'], 'Reciente: sigue leído.' );

		$raw                      = get_option( ErrorLog::OPTION );
		$raw[0]['last']           = time() - ErrorLog::REPEAT_INTERVAL - 1;
		$raw[0]['last_seen_read'] = time() - ErrorLog::REOPEN_AFTER - 1;
		update_option( ErrorLog::OPTION, $raw, false );
		$log->add( ErrorLog::SOURCE_LOG_INSERT, 'Vuelve' );
		$this->assertFalse( $log->all()[0]['read'], 'Pasado un día: vuelve a ser nuevo.' );
	}

	public function test_legacy_flags_are_migrated_once(): void {
		update_option( 'lb_sp_log_error', '1', false );
		update_option( 'lb_sp_storage_warning', '1', false );
		$log = new ErrorLog();

		$log->migrate_legacy();

		$this->assertCount( 2, $log->all() );
		$this->assertFalse( get_option( 'lb_sp_log_error' ) );
		$this->assertFalse( get_option( 'lb_sp_storage_warning' ) );
		$this->assertSame( ErrorLog::LEVEL_WARNING, $log->all()[0]['level'] );
		$this->assertSame( 0, $log->unread_error_count(), 'Sin detalle: no se muestra como error.' );

		$log->migrate_legacy();
		$this->assertCount( 2, $log->all() );
	}

	public function test_successful_schema_upgrade_resolves_schema_errors(): void {
		$log = new ErrorLog();
		$log->add( ErrorLog::SOURCE_LOG_SCHEMA, 'Falló antes' );
		$log->add( ErrorLog::SOURCE_LOG_INSERT, 'Otro' );
		delete_option( RequestLog::VERSION_OPTION );

		( new RequestLog() )->upgrade();

		$by_source = array();
		foreach ( $log->all() as $entry ) {
			$by_source[ $entry['source'] ] = $entry['read'];
		}
		$this->assertTrue( $by_source[ ErrorLog::SOURCE_LOG_SCHEMA ] );
		$this->assertFalse( $by_source[ ErrorLog::SOURCE_LOG_INSERT ] );
	}

	public function test_notice_shows_unread_count_to_admins_only(): void {
		( new ErrorLog() )->add( ErrorLog::SOURCE_LOG_INSERT, 'A' );
		( new ErrorLog() )->add( ErrorLog::SOURCE_LOG_INSERT, 'B' );
		$panel = new ErrorsPanel();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( '', $this->output( array( $panel, 'notice' ) ) );

		$this->admin();
		$html = $this->output( array( $panel, 'notice' ) );
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( '2', $html );
		$this->assertStringContainsString( 'page=' . SettingsPage::PAGE_SLUG, $html );

		( new ErrorLog() )->mark_all_read();
		$this->assertSame( '', $this->output( array( $panel, 'notice' ) ) );
	}

	public function test_settings_page_lists_errors_with_escaped_output_and_actions(): void {
		$this->admin();
		$log = new ErrorLog();
		$log->add( ErrorLog::SOURCE_LOG_INSERT, 'Motivo <b>x</b>', ErrorLog::LEVEL_ERROR, 7, 8 );

		$html = $this->output( array( new SettingsPage(), 'render' ) );

		$this->assertStringContainsString( 'Registro de errores', $html );
		$this->assertStringContainsString( 'Motivo', $html );
		$this->assertStringNotContainsString( '<b>x</b>', $html );
		$this->assertStringContainsString( 'Nuevo', $html );
		$this->assertStringContainsString( 'Marcar como leído', $html );
		$this->assertStringContainsString( 'Marcar todos como leídos', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$this->assertStringContainsString( 'Entrada #7, audio #8', $html );

		$log->mark_all_read();
		$html = $this->output( array( new SettingsPage(), 'render' ) );
		$this->assertStringContainsString( 'Leído', $html );
		$this->assertStringNotContainsString( 'value="read"', $html );
	}

	public function test_settings_page_shows_empty_state(): void {
		$this->admin();
		$html = $this->output( array( new SettingsPage(), 'render' ) );
		$this->assertStringContainsString( 'No hay errores registrados', $html );
	}

	public function test_actions_work_for_admins(): void {
		$this->admin();
		$log   = new ErrorLog();
		$panel = new ErrorsPanel();
		$log->add( ErrorLog::SOURCE_LOG_INSERT, 'A' );
		$log->add( ErrorLog::SOURCE_LOG_INSERT, 'B' );

		$panel->apply( 'read', $log->all()[0]['id'] );
		$this->assertSame( 1, $log->unread_error_count() );
		$panel->apply( 'read_all' );
		$this->assertSame( 0, $log->unread_error_count() );
		$panel->apply( 'clear' );
		$this->assertSame( array(), $log->all() );
	}

	/**
	 * Roles sin permiso.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function non_admin_roles(): array {
		return array(
			'editor'     => array( 'editor' ),
			'subscriber' => array( 'subscriber' ),
			'anonymous'  => array( '' ),
		);
	}

	/**
	 * @dataProvider non_admin_roles
	 * @param string $role Rol ('' = sin sesión).
	 */
	public function test_actions_are_forbidden_for_non_admins( string $role ): void {
		$log = new ErrorLog();
		$log->add( ErrorLog::SOURCE_LOG_INSERT, 'A' );
		wp_set_current_user( '' === $role ? 0 : self::factory()->user->create( array( 'role' => $role ) ) );

		try {
			( new ErrorsPanel() )->apply( 'clear' );
			$this->fail( 'Debió terminar con wp_die().' );
		} catch ( \WPDieException $e ) {
			$this->assertCount( 1, $log->all(), 'El registro no se modifica.' );
		}
	}

	public function test_request_handler_rejects_missing_nonce(): void {
		$this->admin();
		( new ErrorLog() )->add( ErrorLog::SOURCE_LOG_INSERT, 'A' );
		$_POST['do'] = 'clear';

		try {
			( new ErrorsPanel() )->handle_request();
			$this->fail( 'Debió rechazar la petición sin nonce.' );
		} catch ( \WPDieException $e ) {
			$this->assertCount( 1, ( new ErrorLog() )->all() );
		} finally {
			unset( $_POST['do'] );
		}
	}
}
