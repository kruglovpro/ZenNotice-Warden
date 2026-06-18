=== ZenNotice Warden ===
Contributors: kruglovnet
Tags: admin notices, hide notices, block notices, remove notices, disable notices, admin cleanup, clean dashboard, hide updates, notice control, WordPress notices, hide yoast notices, suppress notices, admin notice manager, dashboard cleaner
Requires at least: 4.0
Tested up to: 7.0
Requires PHP: 5.6
Stable tag: 1.8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Hide annoying WordPress admin notices. Block, disable, or auto-hide plugin and system notices. AJAX-powered with regex filters.

== Description ==

**ZenNotice Warden puts you in control of your WordPress dashboard.** Tired of endless plugin notices, update reminders, and system messages cluttering your admin area? This plugin lets you hide any notice with one click — or set up automatic rules to block them forever.

Unlike plugins that blindly hide all notices, ZenNotice Warden gives you individual control. Each notice gets a block button. Click it → it's gone. Still want it back? Unblock it anytime from the settings page.

= How it works =

The plugin intercepts all output from `admin_notices` and `network_admin_notices` hooks and adds a block button to each notice. Once clicked, the notice disappears and won't be shown again for the current site.

= Features =

* **One-click blocking** — a × button appears on every notice
* **Regex auto-blocking** — notices matching your custom patterns are hidden automatically (e.g. `/update available/i` to hide all update notifications)
* **Full management UI** at Settings → ZenNotice Warden
* **View blocked notices** with content preview — see what you've hidden
* **Unblock anytime** with a single click
* Supports `notice`, `updated`, `error`, and `update-nag` types
* Compatible with multisite (`network_admin_notices`)
* Clean uninstall — removes all data on deactivation
* Translation-ready — Русский, English, and more

= Who is this for? =

* Site owners overwhelmed by plugin notifications
* Developers testing plugins who don't want repeated notices
* Anyone who wants a clean, distraction-free admin area

== Описание на русском ==

**ZenNotice Warden — это полный контроль над уведомлениями в админке WordPress.** Устали от бесконечных сообщений от плагинов, напоминаний об обновлениях и системных уведомлений? Этот плагин позволяет скрыть любое уведомление одним кликом — или настроить автоматические правила для их блокировки.

В отличие от плагинов, которые тупо скрывают все уведомления разом, ZenNotice Warden даёт выборочный контроль. На каждом уведомлении появляется кнопка блокировки. Нажал — исчезло. Передумал? Верни его в любой момент на странице настроек.

**Возможности:**
- Блокировка любых уведомлений одним кликом
- Regex-фильтры — автоматическое скрытие по тексту (например, `/обновление/i`)
- Страница управления всеми заблокированными уведомлениями
- Просмотр содержимого заблокированных уведомлений
- Полная поддержка русского языка
- Работает на мультисайтах
- Чистое удаление — не оставляет мусора в базе данных

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

= Will this break my site? =

No. The plugin only hides notices visually — it doesn't modify any core files or database data. You can unblock any notice at any time.

= Does it work with multisite? =

Yes. It hooks into both `admin_notices` and `network_admin_notices`.

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
