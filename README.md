# ZenNotice Warden

Hide annoying WordPress admin notices. Block, disable, or auto-hide plugin and system notices with one click or regex filters.

## Description

**ZenNotice Warden puts you in control of your WordPress dashboard.** Tired of endless plugin notices, update reminders, and system messages cluttering your admin area? This plugin lets you hide any notice with one click — or set up automatic rules to block them forever.

The plugin intercepts all output from `admin_notices` and `network_admin_notices` hooks and adds a block button to each notice. Once clicked, the notice disappears and won't be shown again for the current site.

## Features

- Block individual admin notices
- Supports `notice`, `updated`, `error`, and `update-nag` notice types
- Regex-based auto-blocking — notices matching a pattern are hidden automatically
- Stores blocked notices with content preview in a WordPress option
- Unblock notices via the settings page (Settings → ZenNotice Warden)
- AJAX-based blocking with `manage_options` capability check
- Automatic option cleanup on deactivation
- i18n support via `load_plugin_textdomain`

## Installation

1. Copy the `ZenNotice Warden` folder to `wp-content/plugins/`
2. Log into the WordPress admin panel
3. Go to Plugins and activate `ZenNotice Warden`

## Usage

After activation, a block button appears on each admin notice. Click it to hide the notice and add it to the blocked list.

Manage blocked notices on the **Settings → ZenNotice Warden** page.

## Changelog

### 1.8.0
- Added regex auto-blocking — notices matching custom patterns are hidden automatically
- Added notice content preview in the settings page
- Added regex filter management UI (add/delete patterns)
- Changed data format to store notice text alongside the ID
- Added automatic migration from old data format

### 1.7.0
- Improved notice ID generation (`wp_strip_all_tags` + whitespace normalization)
- Added unblock support (AJAX toggle)
- Added settings page to view and manage blocked notices
- Added option cleanup on plugin deactivation
- Added textdomain loading for translations
- Added `is_admin()` check in constructor
- Fixed permission check order: capabilities checked before nonce
- Improved script registration (dedicated handle)

### 1.6.3
- Initial release

## Requirements

- WordPress 4.0+
- PHP 5.6+
- Tested up to WordPress 7.0

## License

GPLv2 or later

## Author

Sergey Kruglov

https://kruglov.net
