<?php
/**
 * Executes snippets delivered by the hub.
 *
 * Snippet types:
 *   - php          Runs on plugins_loaded (server-side).
 *   - css          Printed in wp_head inside <style>.
 *   - js_head      Printed in wp_head inside <script>.
 *   - js_footer    Printed in wp_footer inside <script>.
 *
 * @package WSS_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSS_Snippets {

	public function register() {
		add_action( 'plugins_loaded', array( $this, 'run_php_snippets' ), 20 );
		add_action( 'wp_head', array( $this, 'print_head' ), 99 );
		add_action( 'wp_footer', array( $this, 'print_footer' ), 99 );
	}

	private function get_active() {
		$snippets = (array) get_option( WSS_Hub_Client::OPT_SNIPPETS, array() );
		$status   = get_option( WSS_Hub_Client::OPT_STATUS, 'pending' );
		if ( 'approved' !== $status ) {
			return array();
		}
		return array_values( array_filter( $snippets, static function ( $s ) {
			return ! empty( $s['active'] );
		} ) );
	}

	public function run_php_snippets() {
		foreach ( $this->get_active() as $s ) {
			if ( 'php' !== $s['type'] ) {
				continue;
			}
			$this->safe_eval( $s );
		}
	}

	public function print_head() {
		$css = '';
		$js  = '';
		foreach ( $this->get_active() as $s ) {
			if ( 'css' === $s['type'] ) {
				$css .= "/* WSS #" . $s['id'] . " */\n" . $s['code'] . "\n";
			} elseif ( 'js_head' === $s['type'] ) {
				$js .= "/* WSS #" . $s['id'] . " */\n" . $s['code'] . "\n";
			}
		}
		if ( '' !== $css ) {
			echo "<style id=\"wss-core-snippets-css\">\n" . $css . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		if ( '' !== $js ) {
			echo "<script id=\"wss-core-snippets-js-head\">\n" . $js . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	public function print_footer() {
		$js = '';
		foreach ( $this->get_active() as $s ) {
			if ( 'js_footer' === $s['type'] ) {
				$js .= "/* WSS #" . $s['id'] . " */\n" . $s['code'] . "\n";
			}
		}
		if ( '' !== $js ) {
			echo "<script id=\"wss-core-snippets-js-footer\">\n" . $js . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	private function safe_eval( $snippet ) {
		$code = $snippet['code'];
		// Strip a leading <?php if present so eval() doesn't choke.
		$code = preg_replace( '/^\s*<\?php\s*/i', '', $code );
		$code = preg_replace( '/\?>\s*$/', '', $code );

		try {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged
			eval( $code );
		} catch ( \Throwable $e ) {
			error_log( '[WSS Core] Snippet ' . $snippet['id'] . ' fatal: ' . $e->getMessage() );
		}
	}
}
