<?php
/**
 * Main plugin class.
 *
 * @package WSS_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WSS_Core {

	const GITHUB_OWNER = 'Ecomscene';
	const GITHUB_REPO  = 'wss-core';

	private static $instance = null;

	private $updater = null;

	private $initialized = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function init() {
		if ( $this->initialized ) {
			return;
		}
		$this->initialized = true;

		load_plugin_textdomain(
			'wss-core',
			false,
			dirname( WSS_CORE_BASENAME ) . '/languages'
		);

		$this->updater = new WSS_GitHub_Updater( array(
			'owner'    => self::GITHUB_OWNER,
			'repo'     => self::GITHUB_REPO,
			'file'     => WSS_CORE_FILE,
			'version'  => WSS_CORE_VERSION,
			'basename' => WSS_CORE_BASENAME,
			'slug'     => dirname( WSS_CORE_BASENAME ),
		) );
		$this->updater->register();
	}

	public function updater() {
		return $this->updater;
	}
}
