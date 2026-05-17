<?php
/**
 * Pulls plugin assignments from the WSS Hub and reconciles install / active state.
 *
 * @package WSS_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSS_Plugin_Manager {

	const OPT_MANAGED       = 'wss_managed_plugins';
	const OPT_LAST_SYNC     = 'wss_managed_plugins_last_sync';
	const OPT_LAST_RESULT   = 'wss_managed_plugins_last_result';
	const OPT_LAST_ERROR    = 'wss_managed_plugins_last_error';

	private $hub_client;

	public function __construct( WSS_Hub_Client $hub_client ) {
		$this->hub_client = $hub_client;
	}

	public function register() {
		add_action( WSS_Hub_Client::CRON_SYNC_PLUGINS, array( $this, 'sync' ) );
	}

	public function status() {
		return array(
			'managed'     => (array) get_option( self::OPT_MANAGED, array() ),
			'last_sync'   => (int) get_option( self::OPT_LAST_SYNC, 0 ),
			'last_result' => (array) get_option( self::OPT_LAST_RESULT, array() ),
			'last_error'  => get_option( self::OPT_LAST_ERROR, '' ),
		);
	}

	public function force_sync() {
		$this->sync();
	}

	public function sync() {
		$resp = $this->hub_client->get( '/api/plugins' );
		if ( ! is_array( $resp ) || ! isset( $resp['plugins'] ) ) {
			return;
		}
		if ( ( $resp['status'] ?? '' ) !== 'approved' ) {
			update_option( self::OPT_LAST_SYNC, time(), false );
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		WP_Filesystem();

		$managed = array();
		$results = array();

		foreach ( (array) $resp['plugins'] as $p ) {
			if ( empty( $p['main_file'] ) || empty( $p['download_path'] ) ) {
				continue;
			}
			$basename = (string) $p['main_file'];
			$managed[ $basename ] = array(
				'id'      => (int) ( $p['id'] ?? 0 ),
				'name'    => (string) ( $p['name'] ?? '' ),
				'slug'    => (string) ( $p['slug'] ?? '' ),
				'version' => (string) ( $p['version'] ?? '' ),
				'state'   => (string) ( $p['desired_state'] ?? 'active' ),
			);

			$installed = file_exists( WP_PLUGIN_DIR . '/' . $basename );

			if ( ! $installed ) {
				$installed = $this->install_plugin( $p );
				$results[ $basename ] = $installed ? 'installed' : 'install_failed';
			}

			if ( $installed ) {
				$active         = is_plugin_active( $basename );
				$desired_active = ( $p['desired_state'] ?? 'active' ) === 'active';

				if ( $desired_active && ! $active ) {
					$res = activate_plugin( $basename, '', false, true );
					$results[ $basename ] = is_wp_error( $res )
						? ( 'activate_failed: ' . $res->get_error_message() )
						: 'activated';
				} elseif ( ! $desired_active && $active ) {
					deactivate_plugins( array( $basename ), true );
					$results[ $basename ] = 'deactivated';
				} elseif ( ! isset( $results[ $basename ] ) ) {
					$results[ $basename ] = $desired_active ? 'active' : 'inactive';
				}
			}
		}

		update_option( self::OPT_MANAGED, $managed, false );
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

		// Plugin_Upgrader::install() accepts a local file path.
		$result = $upgrader->install( $tmp, array( 'overwrite_package' => true ) );

		@unlink( $tmp );

		if ( is_wp_error( $result ) ) {
			update_option( self::OPT_LAST_ERROR, 'install_failed: ' . $result->get_error_message(), false );
			return false;
		}
		if ( ! $result ) {
			update_option( self::OPT_LAST_ERROR, 'install_failed: unknown', false );
			return false;
		}

		// Successful install. Clear any prior error.
		delete_option( self::OPT_LAST_ERROR );
		return file_exists( WP_PLUGIN_DIR . '/' . $p['main_file'] );
	}
}
