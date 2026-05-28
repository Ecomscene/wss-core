<?php
/**
 * Hub client: registers the site with the central WSS Hub, sends heartbeats,
 * and pulls assigned snippets on a schedule.
 *
 * @package WSS_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSS_Hub_Client {

	const OPT_SITE_UUID    = 'wss_hub_site_uuid';
	const OPT_SITE_SECRET  = 'wss_hub_site_secret';
	const OPT_LAST_SYNC    = 'wss_hub_last_sync';
	const OPT_LAST_ERROR   = 'wss_hub_last_error';
	const OPT_STATUS       = 'wss_hub_status'; // pending|approved|error
	const OPT_SNIPPETS     = 'wss_hub_snippets';

	const CRON_HEARTBEAT     = 'wss_hub_heartbeat';
	const CRON_SYNC          = 'wss_hub_sync_snippets';
	const CRON_SYNC_PLUGINS  = 'wss_hub_sync_plugins';

	private $hub_url;

	public function __construct() {
		$this->hub_url = untrailingslashit( defined( 'WSS_HUB_URL' ) ? WSS_HUB_URL : WSS_CORE_HUB_URL_DEFAULT );
	}

	public function register() {
		add_action( 'init', array( $this, 'maybe_register_site' ) );

		add_action( self::CRON_HEARTBEAT, array( $this, 'send_heartbeat' ) );
		add_action( self::CRON_SYNC, array( $this, 'sync_snippets' ) );

		add_action( 'wp', array( $this, 'schedule_events' ) );
		add_action( 'admin_init', array( $this, 'schedule_events' ) );
	}

	public function hub_url(): string {
		return $this->hub_url;
	}

	public function activate() {
		$this->schedule_events();
	}

	public function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HEARTBEAT );
		wp_clear_scheduled_hook( self::CRON_SYNC );
		wp_clear_scheduled_hook( self::CRON_SYNC_PLUGINS );
	}

	/**
	 * Public helper so other classes (e.g. WSS_Plugin_Manager) can do signed requests.
	 *
	 * @return array|false Parsed JSON response, or false on failure.
	 */
	public function get( string $path ) {
		return $this->signed_get( $path );
	}

	public function post( string $path, array $body ) {
		return $this->signed_post( $path, $body );
	}

	/**
	 * Stream a signed GET response to a file. Returns the local file path on success.
	 */
	public function download_to_file( string $path ): ?string {
		$uuid   = get_option( self::OPT_SITE_UUID );
		$secret = get_option( self::OPT_SITE_SECRET );
		if ( ! $uuid || ! $secret ) {
			return null;
		}

		$timestamp = time();
		$nonce     = wp_generate_password( 16, false );
		$to_sign   = 'GET' . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n";
		$signature = hash_hmac( 'sha256', $to_sign, $secret );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = wp_tempnam( 'wss-core-' );

		$response = wp_remote_get( $this->hub_url . $path, array(
			'timeout'  => 120,
			'stream'   => true,
			'filename' => $tmp,
			'headers'  => array(
				'Accept'          => 'application/zip,application/octet-stream',
				'User-Agent'      => 'WSS-Core/' . WSS_CORE_VERSION,
				'X-WSS-Site'      => $uuid,
				'X-WSS-Timestamp' => (string) $timestamp,
				'X-WSS-Nonce'     => $nonce,
				'X-WSS-Signature' => $signature,
			),
		) );

		if ( is_wp_error( $response ) ) {
			@unlink( $tmp );
			$this->set_error( 'download ' . $path . ': ' . $response->get_error_message() );
			return null;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			@unlink( $tmp );
			$this->set_error( 'download ' . $path . ': status=' . $code );
			return null;
		}
		return $tmp;
	}

	public function schedule_events() {
		if ( ! wp_next_scheduled( self::CRON_HEARTBEAT ) ) {
			wp_schedule_event( time() + 60, 'daily', self::CRON_HEARTBEAT );
		}
		if ( ! wp_next_scheduled( self::CRON_SYNC ) ) {
			wp_schedule_event( time() + 60, 'wss_5min', self::CRON_SYNC );
		}
		if ( ! wp_next_scheduled( self::CRON_SYNC_PLUGINS ) ) {
			wp_schedule_event( time() + 90, 'wss_5min', self::CRON_SYNC_PLUGINS );
		}
	}

	public static function add_cron_schedule( $schedules ) {
		$schedules['wss_5min'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes (WSS)', 'wss-core' ),
		);
		return $schedules;
	}

	public function maybe_register_site() {
		if ( '' === $this->hub_url ) {
			return;
		}
		if ( get_option( self::OPT_SITE_UUID ) ) {
			return;
		}
		$this->register_site();
	}

	public function register_site() {
		$payload = array(
			'site_url'    => home_url( '/' ),
			'rest_url'    => rest_url(),
			'admin_email' => get_bloginfo( 'admin_email' ),
			'site_name'   => get_bloginfo( 'name' ),
			'wp_version'  => get_bloginfo( 'version' ),
			'php_version' => PHP_VERSION,
			'wss_version' => WSS_CORE_VERSION,
			'timezone'    => wp_timezone_string(),
		);

		$response = wp_remote_post( $this->hub_url . '/api/register', array(
			'timeout' => 15,
			'headers' => array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
				'User-Agent'   => 'WSS-Core/' . WSS_CORE_VERSION,
			),
			'body'    => wp_json_encode( $payload ),
		) );

		if ( is_wp_error( $response ) ) {
			$this->set_error( 'register_failed: ' . $response->get_error_message() );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== (int) $code || empty( $body['uuid'] ) || empty( $body['secret'] ) ) {
			$this->set_error( 'register_failed: status=' . $code );
			return false;
		}

		update_option( self::OPT_SITE_UUID, sanitize_text_field( $body['uuid'] ), false );
		update_option( self::OPT_SITE_SECRET, sanitize_text_field( $body['secret'] ), false );
		update_option( self::OPT_STATUS, isset( $body['status'] ) ? sanitize_text_field( $body['status'] ) : 'pending', false );
		delete_option( self::OPT_LAST_ERROR );
		return true;
	}

	public function send_heartbeat() {
		$uuid   = get_option( self::OPT_SITE_UUID );
		$secret = get_option( self::OPT_SITE_SECRET );
		if ( ! $uuid || ! $secret ) {
			return;
		}

		$active_plugins = (array) get_option( 'active_plugins', array() );
		$theme          = wp_get_theme();

		$payload = array(
			'site_url'            => home_url( '/' ),
			'rest_url'            => rest_url(),
			'site_name'           => get_bloginfo( 'name' ),
			'admin_email'         => get_bloginfo( 'admin_email' ),
			'claude_bridge_token' => (string) get_option( 'claude_bridge_token', '' ),
			'wp_version'     => get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
			'wss_version'    => WSS_CORE_VERSION,
			'active_theme'   => $theme ? $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) : '',
			'active_plugins' => array_values( $active_plugins ),
			'timestamp'      => time(),
		);

		$this->signed_post( '/api/heartbeat', $payload );
	}

	public function sync_snippets() {
		$uuid   = get_option( self::OPT_SITE_UUID );
		$secret = get_option( self::OPT_SITE_SECRET );
		if ( ! $uuid || ! $secret ) {
			return;
		}

		$response = $this->signed_get( '/api/snippets' );
		if ( ! is_array( $response ) || ! isset( $response['snippets'] ) ) {
			return;
		}

		$snippets = array();
		foreach ( (array) $response['snippets'] as $s ) {
			if ( empty( $s['id'] ) || empty( $s['type'] ) || ! isset( $s['code'] ) ) {
				continue;
			}
			$snippets[] = array(
				'id'       => (string) $s['id'],
				'name'     => isset( $s['name'] ) ? (string) $s['name'] : '',
				'type'     => (string) $s['type'],
				'location' => isset( $s['location'] ) ? (string) $s['location'] : 'auto',
				'code'     => (string) $s['code'],
				'active'   => ! empty( $s['active'] ),
				'updated'  => isset( $s['updated_at'] ) ? (string) $s['updated_at'] : '',
			);
		}

		update_option( self::OPT_SNIPPETS, $snippets, false );
		update_option( self::OPT_LAST_SYNC, time(), false );
		if ( isset( $response['status'] ) ) {
			update_option( self::OPT_STATUS, sanitize_text_field( $response['status'] ), false );
		}
	}

	private function signed_get( $path ) {
		return $this->signed_request( 'GET', $path, null );
	}

	private function signed_post( $path, $body ) {
		return $this->signed_request( 'POST', $path, $body );
	}

	private function signed_request( $method, $path, $body ) {
		$uuid   = get_option( self::OPT_SITE_UUID );
		$secret = get_option( self::OPT_SITE_SECRET );
		if ( ! $uuid || ! $secret ) {
			return false;
		}

		$timestamp = time();
		$body_json = $body ? wp_json_encode( $body ) : '';
		$nonce     = wp_generate_password( 16, false );
		$to_sign   = $method . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . $body_json;
		$signature = hash_hmac( 'sha256', $to_sign, $secret );

		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'Content-Type'      => 'application/json',
				'Accept'            => 'application/json',
				'User-Agent'        => 'WSS-Core/' . WSS_CORE_VERSION,
				'X-WSS-Site'        => $uuid,
				'X-WSS-Timestamp'   => (string) $timestamp,
				'X-WSS-Nonce'       => $nonce,
				'X-WSS-Signature'   => $signature,
			),
		);
		if ( 'POST' === $method ) {
			$args['body'] = $body_json;
		}

		$response = wp_remote_request( $this->hub_url . $path, $args );
		if ( is_wp_error( $response ) ) {
			$this->set_error( $method . ' ' . $path . ': ' . $response->get_error_message() );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( 200 !== (int) $code ) {
			// Hub no longer recognises us (likely deleted from hub UI). Clear stored
			// credentials so the next admin/page-load runs maybe_register_site() and
			// re-pairs the site from scratch.
			if ( (int) $code === 401 ) {
				$err = json_decode( $raw, true );
				$err_code = is_array( $err ) ? ( $err['error'] ?? '' ) : '';
				if ( $err_code === 'unknown_site' ) {
					delete_option( self::OPT_SITE_UUID );
					delete_option( self::OPT_SITE_SECRET );
					delete_option( self::OPT_STATUS );
					$this->set_error( 'site missing on hub — credentials cleared, will re-register' );
					return false;
				}
			}
			$this->set_error( $method . ' ' . $path . ': status=' . $code );
			return false;
		}

		// Verify response HMAC if present.
		$resp_sig = wp_remote_retrieve_header( $response, 'x-wss-signature' );
		if ( $resp_sig ) {
			$expected = hash_hmac( 'sha256', $raw, $secret );
			if ( ! hash_equals( $expected, $resp_sig ) ) {
				$this->set_error( $method . ' ' . $path . ': signature mismatch' );
				return false;
			}
		}

		delete_option( self::OPT_LAST_ERROR );
		return is_array( $data ) ? $data : array();
	}

	private function set_error( $message ) {
		update_option( self::OPT_LAST_ERROR, $message, false );
	}

	public function status() {
		return array(
			'hub_url'    => $this->hub_url,
			'uuid'       => get_option( self::OPT_SITE_UUID, '' ),
			'status'     => get_option( self::OPT_STATUS, 'unregistered' ),
			'last_sync'  => (int) get_option( self::OPT_LAST_SYNC, 0 ),
			'last_error' => get_option( self::OPT_LAST_ERROR, '' ),
			'snippets'   => (array) get_option( self::OPT_SNIPPETS, array() ),
		);
	}

	public function force_resync() {
		$this->sync_snippets();
		$this->send_heartbeat();
	}
}
