<?php
/**
 * Hub-triggered single-sign-on. The hub generates a short-lived, HMAC-signed
 * URL that lets the operator land directly in wp-admin without typing a
 * password. Same trust boundary as the rest of the hub↔client relationship:
 * if you control the per-site secret, you can issue these.
 *
 * URL shape (sent from the hub):
 *   https://site/?wss_sso=1&ts=…&nonce=…&u=ecomscene&r=/wp-admin/&sig=…
 *
 * Signature payload:
 *   "sso\n{ts}\n{nonce}\n{u}\n{r}"
 *   HMAC-SHA256 with the site secret.
 *
 * @package WSS_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSS_SSO {

	const TTL_SECONDS  = 60;
	const NONCE_PREFIX = 'wss_sso_nonce_';

	public function register() {
		add_action( 'init', array( $this, 'maybe_handle' ), 1 );
	}

	public function maybe_handle() {
		if ( empty( $_GET['wss_sso'] ) ) {
			return;
		}

		$ts       = (int) ( $_GET['ts']    ?? 0 );
		$nonce    = (string) ( $_GET['nonce'] ?? '' );
		$user_in  = (string) ( $_GET['u']     ?? '' );
		$redirect = (string) ( $_GET['r']     ?? '/wp-admin/' );
		$sig      = (string) ( $_GET['sig']   ?? '' );

		if ( ! $ts || ! $nonce || ! $sig || ! $user_in ) {
			$this->bail( 'missing parameters' );
		}
		if ( abs( time() - $ts ) > self::TTL_SECONDS ) {
			$this->bail( 'link expired (>60s old)' );
		}

		$secret = get_option( WSS_Hub_Client::OPT_SITE_SECRET );
		if ( ! $secret ) {
			$this->bail( 'this site is not paired with a WSS Hub' );
		}

		$payload  = 'sso' . "\n" . $ts . "\n" . $nonce . "\n" . $user_in . "\n" . $redirect;
		$expected = hash_hmac( 'sha256', $payload, $secret );
		if ( ! hash_equals( $expected, $sig ) ) {
			$this->bail( 'invalid signature' );
		}

		// Prevent replay: each nonce can only be used once.
		$nonce_key = self::NONCE_PREFIX . md5( $nonce );
		if ( get_transient( $nonce_key ) ) {
			$this->bail( 'this login link has already been used' );
		}
		set_transient( $nonce_key, 1, 5 * MINUTE_IN_SECONDS );

		// Resolve the WP user: login → email → display_name fallback.
		$user = get_user_by( 'login', $user_in );
		if ( ! $user ) {
			$user = get_user_by( 'email', $user_in );
		}
		if ( ! $user ) {
			$this->bail( 'no WP user with login or email "' . esc_html( $user_in ) . '"' );
		}

		// Log in.
		wp_set_current_user( $user->ID, $user->user_login );
		wp_set_auth_cookie( $user->ID, false, is_ssl() );
		do_action( 'wp_login', $user->user_login, $user );

		// Safe redirect target: relative path within this site only.
		if ( ! preg_match( '#^/[A-Za-z0-9_\-/.?=&%]*$#', $redirect ) ) {
			$redirect = '/wp-admin/';
		}
		$target = home_url( $redirect );
		wp_safe_redirect( $target );
		exit;
	}

	private function bail( string $msg ) {
		status_header( 403 );
		nocache_headers();
		echo '<h1>WSS SSO failed</h1><p>' . esc_html( $msg ) . '</p>';
		exit;
	}
}
