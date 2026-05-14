<?php
/**
 * Plugin Name:       WSS Core
 * Plugin URI:        https://github.com/Ecomscene/wss-core
 * Description:       Lightweight base plugin for Webshopschool client websites. Provides a stable foundation with GitHub-based auto-updates.
 * Version:           1.0.1
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

define( 'WSS_CORE_VERSION', '1.0.1' );
define( 'WSS_CORE_FILE', __FILE__ );
define( 'WSS_CORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'WSS_CORE_URL', plugin_dir_url( __FILE__ ) );
define( 'WSS_CORE_BASENAME', plugin_basename( __FILE__ ) );

require_once WSS_CORE_PATH . 'includes/class-wss-core.php';
require_once WSS_CORE_PATH . 'includes/class-wss-github-updater.php';
require_once WSS_CORE_PATH . 'includes/class-wss-admin.php';

add_action( 'plugins_loaded', static function () {
	WSS_Core::instance()->init();
} );

register_activation_hook( __FILE__, static function () {
	delete_site_transient( 'update_plugins' );
	delete_transient( 'wss_core_github_release' );
} );

register_deactivation_hook( __FILE__, static function () {
	delete_transient( 'wss_core_github_release' );
} );
