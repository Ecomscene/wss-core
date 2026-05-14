=== WSS Core ===
Contributors: webshopschool
Tags: core, updater, github
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight base plugin for Webshopschool client websites. Provides a stable foundation with GitHub-based auto-updates.

== Description ==

WSS Core is a base plugin used on Webshopschool client websites. It currently exposes no frontend or admin features — it exists so future functionality can be pushed to all client sites through GitHub releases.

== Changelog ==

= 1.1.0 =
* Added: WSS Hub client. Auto-registers each install with a central hub, sends a daily heartbeat, and pulls assigned PHP / CSS / JS snippets every 5 minutes.
* Added: Snippet runner with HMAC-verified payloads (PHP via plugins_loaded, CSS in wp_head, JS in wp_head or wp_footer).
* Added: "Hub status" panel on the Webshopschool dashboard page with a force-sync button.

= 1.0.1 =
* Added: Webshopschool dashboard page (test of the auto-update flow).

= 1.0.0 =
* Initial release. Base plugin with GitHub release-based auto-updater.
