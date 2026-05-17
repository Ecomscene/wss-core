=== WSS Core ===
Contributors: webshopschool
Tags: core, updater, github
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight base plugin for Webshopschool client websites. Provides a stable foundation with GitHub-based auto-updates.

== Description ==

WSS Core is a base plugin used on Webshopschool client websites. It currently exposes no frontend or admin features — it exists so future functionality can be pushed to all client sites through GitHub releases.

== Changelog ==

= 1.5.0 =
* Added: REST endpoint /wp-json/wss-core/v1/plugin-action for hub-triggered Activate / Deactivate / Remove on any installed plugin.
* Changed: Plugin reconciler no longer enforces active/inactive state in the background. It only installs new hub-pushed plugins. Active state is now controlled by the imperative buttons in the hub.

= 1.4.0 =
* Added: REST endpoint /wp-json/wss-core/v1/sync for hub-triggered force-sync. HMAC-authenticated with the site secret.
* Added: Reports rest_url on registration + heartbeat so the hub knows where to call back.

= 1.3.0 =
* Added: Client now reports its full installed-plugin inventory to the hub on every sync, so the hub site detail page can show every plugin (not just hub-managed ones).
* Added: Hub assignments are now keyed by plugin basename, so you can set active/inactive on plugins that were already installed (e.g. WooCommerce, Yoast, etc.) — not just the ones you uploaded.
* Changed: /api/plugins is now a POST endpoint (body carries the inventory).

= 1.2.0 =
* Added: WSS Plugin Manager. Pulls plugin assignments from the hub every 5 min, installs missing plugins from signed ZIP downloads, and reconciles active/inactive state.
* Added: "Managed plugins" panel + per-plugin last-action results on the Webshopschool dashboard.
* "Force sync now" button now also runs the plugin reconciliation pass.

= 1.1.1 =
* Changed: Default hub URL set to https://core.webshopschool.nl/index.php (PATH_INFO routing for shared-hosting compatibility).

= 1.1.0 =
* Added: WSS Hub client. Auto-registers each install with a central hub, sends a daily heartbeat, and pulls assigned PHP / CSS / JS snippets every 5 minutes.
* Added: Snippet runner with HMAC-verified payloads (PHP via plugins_loaded, CSS in wp_head, JS in wp_head or wp_footer).
* Added: "Hub status" panel on the Webshopschool dashboard page with a force-sync button.

= 1.0.1 =
* Added: Webshopschool dashboard page (test of the auto-update flow).

= 1.0.0 =
* Initial release. Base plugin with GitHub release-based auto-updater.
