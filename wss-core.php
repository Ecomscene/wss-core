<?php
/**
 * Plugin Name:       WSS Core
 * Plugin URI:        https://github.com/Ecomscene/wss-core
 * Description:       Base plugin for Webshopschool client websites. Phones home to the WSS Hub for centrally-managed snippets and auto-updates from GitHub.
 * Version:           1.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Webshopschool by Joey
 * Author URI:        https://webshopschool.nl
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wss-core
 * Domain Path:       /languages
 * Update URI:        https://github.com/Ecomscene/wss-core
 *
 * @package WSS_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WSS_CORE_VERSION', '1.1.0' );
define( 'WSS_CORE_FILE', __FILE__ );
define( 'WSS_CORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'WSS_CORE_URL', plugin_dir_url( __FILE__ ) );
define( 'WSS_CORE_BASENAME', plugin_basename( __FILE__ ) );

// Default WSS Hub URL. Override per site by adding to wp-config.php:
//   define( 'WSS_HUB_URL', 'https://hub.example.com' );
if ( ! defined( 'WSS_CORE_HUB_URL_DEFAULT' ) ) {
	define( 'WSS_CORE_HUB_URL_DEFAULT', 'http://localhost:8000' );
}

require_once WSS_CORE_PATH . 'includes/class-wss-core.php';
require_once WSS_CORE_PATH . 'includes/class-wss-github-updater.php';
require_once WSS_CORE_PATH . 'includes/class-wss-admin.php';
require_once WSS_CORE_PATH . 'includes/class-wss-hub-client.php';
require_once WSS_CORE_PATH . 'includes/class-wss-snippets.php';

add_filter( 'cron_schedules', array( 'WSS_Hub_Client', 'add_cron_schedule' ) );

add_action( 'plugins_loaded', static function () {
	WSS_Core::instance()->init();
} );

register_activation_hook( __FILE__, static function () {
	delete_site_transient( 'update_plugins' );
	delete_transient( 'wss_core_github_release' );
	// Schedule hub events.
	if ( class_exists( 'WSS_Hub_Client' ) ) {
		( new WSS_Hub_Client() )->activate();
	}
} );

register_deactivation_hook( __FILE__, static function () {
	delete_transient( 'wss_core_github_release' );
	if ( class_exists( 'WSS_Hub_Client' ) ) {
		( new WSS_Hub_Client() )->deactivate();
	}
} );
