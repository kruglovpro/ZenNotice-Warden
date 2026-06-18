=== ZenNotice Warden ===
Contributors: kruglovnet
Tags: admin notices, notices, hide notices, block notices, admin cleanup
Requires at least: 4.0
Tested up to: 7.0
Requires PHP: 5.6
Stable tag: 1.8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Individually hide or block admin notices. AJAX-powered with regex auto-blocking.

== Description ==

ZenNotice Warden gives you full control over WordPress admin notices. Instead of being overwhelmed by notifications from various plugins, you can individually hide any notice with a single click.

= How it works =

The plugin intercepts all output from `admin_notices` and `network_admin_notices` hooks and adds a block button to each notice. Once clicked, the notice disappears and won't be shown again.

= Features =

* Block individual admin notices with one click
* Supports `notice`, `updated`, `error`, and `update-nag` types
* Regex auto-blocking — notices matching your custom patterns are hidden automatically
* Full management UI at Settings → ZenNotice Warden
* View blocked notices with content preview
* Compatible with multisite (`network_admin_notices`)
* Clean uninstall — removes all data on deactivation
* Translation-ready via `load_plugin_textdomain`

== Installation ==

1. Upload the `zennotice-warden` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Go to Settings → ZenNotice Warden to manage blocked notices and regex filters

== Frequently Asked Questions ==

= How do I block a notice? =

After activation, a × button appears on every admin notice. Click it to block it permanently.

= How do I unblock a notice? =

Go to Settings → ZenNotice Warden and click "Unblock" next to the notice. It will reappear on the next page load.

= Can I auto-block notices by text pattern? =

Yes. Go to Settings → ZenNotice Warden → Regex Filters, add a pattern like `/update available/i`, and all matching notices will be hidden automatically.

== Changelog ==

= 1.8.0 =
* Added regex auto-blocking — notices matching custom patterns are hidden automatically
* Added notice content preview in the settings page
* Added regex filter management UI (add/delete patterns)
* Changed data format to store notice text alongside the ID
* Added automatic migration from old data format

= 1.7.0 =
* Improved notice ID generation (wp_strip_all_tags + whitespace normalization)
* Added unblock support (AJAX toggle)
* Added settings page to view and manage blocked notices
* Added option cleanup on plugin deactivation
* Added textdomain loading for translations
* Added is_admin() check in constructor
* Fixed permission check order: capabilities checked before nonce
* Improved script registration (dedicated handle)

= 1.6.3 =
* Initial release

== Upgrade Notice ==

= 1.8.0 =
Adds regex auto-blocking and notice content preview. Existing blocked notices are migrated automatically.
