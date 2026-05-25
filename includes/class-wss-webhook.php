<?php
/**
 * Inbound webhook from the hub: lets the hub force an immediate sync without
 * waiting for the 5-minute cron. HMAC-authenticated with the same per-site
 * secret used for outbound requests.
 *
 * @package WSS_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSS_Webhook {

	const NAMESPACE_PATH = 'wss-core/v1';
	const SIGN_PATH      = '/wss-core/v1/sync'; // Canonical path used in HMAC

	private $hub_client;
	private $plugin_manager;

	public function __construct( WSS_Hub_Client $hub_client, WSS_Plugin_Manager $plugin_manager ) {
		$this->hub_client     = $hub_client;
		$this->plugin_manager = $plugin_manager;
	}

	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_PATH,
			'/sync',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_sync' ),
				'permission_callback' => array( $this, 'authorize' ),
			)
		);
		register_rest_route(
			self::NAMESPACE_PATH,
			'/plugin-action',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_plugin_action' ),
				'permission_callback' => array( $this, 'authorize' ),
			)
		);
		register_rest_route(
			self::NAMESPACE_PATH,
			'/elementor-update',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_elementor_update' ),
				'permission_callback' => array( $this, 'authorize' ),
			)
		);
		register_rest_route(
			self::NAMESPACE_PATH,
			'/ping',
			array(
				'methods'             => 'GET',
				'callback'            => function () {
					return array(
						'ok'      => true,
						'version' => WSS_CORE_VERSION,
						'time'    => time(),
					);
				},
				'permission_callback' => array( $this, 'authorize' ),
			)
		);
	}

	public function authorize( WP_REST_Request $request ) {
		$uuid      = $request->get_header( 'x_wss_site' );
		$timestamp = (int) $request->get_header( 'x_wss_timestamp' );
		$nonce     = (string) $request->get_header( 'x_wss_nonce' );
		$signature = (string) $request->get_header( 'x_wss_signature' );

		if ( ! $uuid || ! $timestamp || ! $signature ) {
			return false;
		}
		if ( abs( time() - $timestamp ) > 300 ) {
			return false;
		}

		$stored_uuid   = get_option( WSS_Hub_Client::OPT_SITE_UUID );
		$stored_secret = get_option( WSS_Hub_Client::OPT_SITE_SECRET );
		if ( ! $stored_uuid || ! $stored_secret || ! hash_equals( (string) $stored_uuid, (string) $uuid ) ) {
			return false;
		}

		$method = $request->get_method();
		// Canonical signing path = the REST route itself, so URL structure
		// (pretty permalinks vs rest_route= query) doesn't change the signed string.
		$path = $request->get_route();
		$body = $request->get_body();

		$to_sign  = $method . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . $body;
		$expected = hash_hmac( 'sha256', $to_sign, $stored_secret );

		return hash_equals( $expected, $signature );
	}

	public function handle_elementor_update( WP_REST_Request $request ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';

		WP_Filesystem();

		// Force fresh update info from wp.org (free Elementor) and Elementor's
		// own license-based updater (Pro). Without this the upgrade() call below
		// would only see whatever the transient last cached (up to 12h stale).
		wp_clean_plugins_cache( true );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();

		// Known main files for the Elementor family. We try each; non-installed
		// ones are skipped silently.
		$candidates = array(
			'elementor/elementor.php',
			'elementor-pro/elementor-pro.php',
			'pro-elements/pro-elements.php',
		);

		$all_plugins = get_plugins();
		$results     = array();
		$versions    = array();

		foreach ( $candidates as $basename ) {
			if ( ! isset( $all_plugins[ $basename ] ) ) {
				continue;
			}

			$version_before = $all_plugins[ $basename ]['Version'] ?? '';
			$was_active     = is_plugin_active( $basename );

			$skin     = new WP_Upgrader_Skin();
			$upgrader = new Plugin_Upgrader( $skin );
			$upgrade  = $upgrader->upgrade( $basename );

			// Re-read plugin headers to get the version after.
			$fresh   = get_plugin_data( WP_PLUGIN_DIR . '/' . $basename, false, false );
			$version_after = $fresh['Version'] ?? '';

			if ( is_wp_error( $upgrade ) ) {
				$results[ $basename ] = array(
					'ok'      => false,
					'message' => 'upgrade_failed: ' . $upgrade->get_error_message(),
					'from'    => $version_before,
					'to'      => $version_after,
				);
			} elseif ( $upgrade === false ) {
				$results[ $basename ] = array(
					'ok'      => true,
					'message' => 'no_update_available',
					'from'    => $version_before,
					'to'      => $version_after,
				);
			} else {
				$results[ $basename ] = array(
					'ok'      => true,
					'message' => ( $version_before !== $version_after ) ? 'updated' : 'reinstalled',
					'from'    => $version_before,
					'to'      => $version_after,
				);
			}

			// Reactivate if it was active before (some upgraders deactivate during the swap).
			if ( $was_active && ! is_plugin_active( $basename ) ) {
				$reactivate = activate_plugin( $basename, '', false, true );
				$results[ $basename ]['reactivated'] = is_wp_error( $reactivate )
					? 'failed: ' . $reactivate->get_error_message()
					: 'yes';
			}

			$versions[ $basename ] = $version_after;
		}

		// Report fresh inventory back to the hub so the UI updates immediately.
		$active_plugins = (array) get_option( 'active_plugins', array() );
		$inventory      = array();
		foreach ( get_plugins() as $bn => $data ) {
			$inventory[] = array(
				'basename' => $bn,
				'name'     => $data['Name']    ?? $bn,
				'version'  => $data['Version'] ?? '',
				'active'   => in_array( $bn, $active_plugins, true ),
			);
		}
		$this->hub_client->post( '/api/plugins', array( 'installed_plugins' => $inventory ) );

		return new WP_REST_Response( array(
			'ok'       => true,
			'results'  => $results,
			'versions' => $versions,
		), 200 );
	}

	public function handle_plugin_action( WP_REST_Request $request ) {
		$action   = (string) $request->get_param( 'action' );
		$basename = (string) $request->get_param( 'basename' );
		if ( ! $basename || ! in_array( $action, array( 'activate', 'deactivate', 'remove' ), true ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'bad_request' ), 400 );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$file_exists = file_exists( WP_PLUGIN_DIR . '/' . $basename );
		$result      = '';
		$ok          = true;

		if ( $action !== 'remove' && ! $file_exists ) {
			$ok     = false;
			$result = 'not_installed';
		} else {
			switch ( $action ) {
				case 'activate':
					$r = activate_plugin( $basename, '', false, true );
					if ( is_wp_error( $r ) ) {
						$ok     = false;
						$result = 'activate_failed: ' . $r->get_error_message();
					} else {
						$result = 'activated';
					}
					break;

				case 'deactivate':
					deactivate_plugins( array( $basename ), true );
					$result = 'deactivated';
					break;

				case 'remove':
					WP_Filesystem();
					if ( is_plugin_active( $basename ) ) {
						deactivate_plugins( array( $basename ), true );
					}
					$r = delete_plugins( array( $basename ) );
					if ( is_wp_error( $r ) ) {
						$ok     = false;
						$result = 'remove_failed: ' . $r->get_error_message();
					} else {
						$result = 'removed';
					}
					break;
			}
		}

		// Build fresh inventory to send back so the hub can store updated state.
		$active_plugins = (array) get_option( 'active_plugins', array() );
		$all_plugins    = get_plugins();
		$inventory      = array();
		foreach ( $all_plugins as $bn => $data ) {
			$inventory[] = array(
				'basename' => $bn,
				'name'     => $data['Name']    ?? $bn,
				'version'  => $data['Version'] ?? '',
				'active'   => in_array( $bn, $active_plugins, true ),
			);
		}

		// Also push the new inventory up to the hub so its cached snapshot is fresh.
		$this->hub_client->post( '/api/plugins', array( 'installed_plugins' => $inventory ) );

		return new WP_REST_Response( array(
			'ok'        => $ok,
			'result'    => $result,
			'inventory' => $inventory,
		), $ok ? 200 : 500 );
	}

	public function handle_sync( WP_REST_Request $request ) {
		$targets = $request->get_param( 'targets' );
		if ( ! is_array( $targets ) || empty( $targets ) ) {
			$targets = array( 'heartbeat', 'snippets', 'plugins' );
		}

		$results = array();

		if ( in_array( 'heartbeat', $targets, true ) ) {
			$this->hub_client->send_heartbeat();
			$results['heartbeat'] = 'ok';
		}
		if ( in_array( 'snippets', $targets, true ) ) {
			$this->hub_client->sync_snippets();
			$results['snippets'] = 'ok';
		}
		if ( in_array( 'plugins', $targets, true ) ) {
			$this->plugin_manager->sync();
			$results['plugins'] = 'ok';
		}

		return new WP_REST_Response( array(
			'ok'      => true,
			'version' => WSS_CORE_VERSION,
			'results' => $results,
			'time'    => time(),
		), 200 );
	}
}
