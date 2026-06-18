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

	private $admin = null;

	private $hub_client = null;

	private $snippets = null;

	private $plugin_manager = null;

	private $webhook = null;

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

		$this->hub_client = new WSS_Hub_Client();
		$this->hub_client->register();

		$this->snippets = new WSS_Snippets();
		$this->snippets->register();

		$this->plugin_manager = new WSS_Plugin_Manager( $this->hub_client );
		$this->plugin_manager->register();

		$this->webhook = new WSS_Webhook( $this->hub_client, $this->plugin_manager );
		$this->webhook->register();

		( new WSS_SSO() )->register();

		if ( is_admin() ) {
			$this->admin = new WSS_Admin( $this->hub_client, $this->plugin_manager );
			$this->admin->register();
		}
	}

	public function updater() {
		return $this->updater;
	}

	public function admin() {
		return $this->admin;
	}

	public function hub_client() {
		return $this->hub_client;
	}

	public function snippets() {
		return $this->snippets;
	}

	public function plugin_manager() {
		return $this->plugin_manager;
	}
}
