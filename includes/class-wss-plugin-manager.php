<?php
/**
 * Reports installed plugins to the hub and installs any hub-pushed plugins
 * that are not yet present on the site. Active/inactive state is no longer
 * reconciled in the background — the user controls that imperatively from
 * the hub UI (activate / deactivate / remove buttons).
 *
 * @package WSS_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSS_Plugin_Manager {

	const OPT_LAST_SYNC    = 'wss_managed_plugins_last_sync';
	const OPT_LAST_RESULT  = 'wss_managed_plugins_last_result';
	const OPT_LAST_ERROR   = 'wss_managed_plugins_last_error';
	const OPT_ASSIGNMENTS  = 'wss_managed_plugins_assignments';

	private $hub_client;

	public function __construct( WSS_Hub_Client $hub_client ) {
		$this->hub_client = $hub_client;
	}

	public function register() {
		add_action( WSS_Hub_Client::CRON_SYNC_PLUGINS, array( $this, 'sync' ) );
	}

	public function status() {
		return array(
			'assignments' => (array) get_option( self::OPT_ASSIGNMENTS, array() ),
			'last_sync'   => (int) get_option( self::OPT_LAST_SYNC, 0 ),
			'last_result' => (array) get_option( self::OPT_LAST_RESULT, array() ),
			'last_error'  => get_option( self::OPT_LAST_ERROR, '' ),
		);
	}

	public function force_sync() {
		$this->sync();
	}

	public function sync() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$active_plugins = (array) get_option( 'active_plugins', array() );
		$all_plugins    = get_plugins();
		$inventory      = array();
		foreach ( $all_plugins as $basename => $data ) {
			$inventory[] = array(
				'basename' => $basename,
				'name'     => $data['Name']    ?? $basename,
				'version'  => $data['Version'] ?? '',
				'active'   => in_array( $basename, $active_plugins, true ),
			);
		}

		$resp = $this->hub_client->post( '/api/plugins', array(
			'installed_plugins' => $inventory,
		) );

		if ( ! is_array( $resp ) || ! isset( $resp['plugins'] ) ) {
			update_option( self::OPT_LAST_SYNC, time(), false );
			return;
		}
		if ( ( $resp['status'] ?? '' ) !== 'approved' ) {
			update_option( self::OPT_LAST_SYNC, time(), false );
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		WP_Filesystem();

		$assignments = array();
		$results     = array();

		foreach ( (array) $resp['plugins'] as $p ) {
			if ( empty( $p['basename'] ) ) {
				continue;
			}
			$basename = (string) $p['basename'];
			$assignments[ $basename ] = array(
				'name'        => (string) ( $p['name']    ?? $basename ),
				'version'     => (string) ( $p['version'] ?? '' ),
				'state'       => (string) ( $p['desired_state'] ?? 'active' ),
				'hub_managed' => ! empty( $p['download_path'] ),
			);

			// Only act on missing hub-managed plugins: install them.
			if ( empty( $p['download_path'] ) ) {
				continue;
			}
			if ( file_exists( WP_PLUGIN_DIR . '/' . $basename ) ) {
				continue;
			}

			$installed = $this->install_plugin( $p );
			if ( ! $installed ) {
				$results[ $basename ] = 'install_failed';
				continue;
			}
			$results[ $basename ] = 'installed';

			// Honour the initial desired state when first installing.
			if ( ( $p['desired_state'] ?? 'active' ) === 'active' ) {
				$r = activate_plugin( $basename, '', false, true );
				$results[ $basename ] = is_wp_error( $r )
					? 'activate_failed: ' . $r->get_error_message()
					: 'installed+activated';
			}
		}

		update_option( self::OPT_ASSIGNMENTS, $assignments, false );
		update_option( self::OPT_LAST_RESULT, $results, false );
		update_option( self::OPT_LAST_SYNC, time(), false );
	}

	private function install_plugin( array $p ): bool {
		$tmp = $this->hub_client->download_to_file( $p['download_path'] );
		if ( ! $tmp ) {
			update_option( self::OPT_LAST_ERROR, 'download_failed: ' . $p['download_path'], false );
			return false;
		}

		$skin     = new WP_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $tmp, array( 'overwrite_package' => true ) );

		@unlink( $tmp );

		if ( is_wp_error( $result ) ) {
			update_option( self::OPT_LAST_ERROR, 'install_failed: ' . $result->get_error_message(), false );
			return false;
		}
		if ( ! $result ) {
			update_option( self::OPT_LAST_ERROR, 'install_failed: unknown', false );
			return false;
		}

		delete_option( self::OPT_LAST_ERROR );
		return file_exists( WP_PLUGIN_DIR . '/' . $p['basename'] );
	}
}
