<?php
/**
 * Plugin Name: ZenNotice Warden
 * Description: Individually disable, hide, or block admin notices using call-stack analysis.
 * Version: 1.6.3
 * Author: Sergey Kruglov
 * Author URI: https://kruglov.net
 * Text Domain: zennotice-warden
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

class ZenNoticeWarden {

    /**
     * Опция, в которой хранятся идентификаторы заблокированных уведомлений.
     * Идентификатор формируется по HTML-выводу уведомления.
     */
    private $option_name = 'zennotice_warden_blocked_list';

    public function __construct() {
        add_action('wp_ajax_zennotice_warden_toggle', [$this, 'toggle_notice']);
        add_action('admin_notices', [$this, 'process_notices_buffer'], -9999);
        add_action('network_admin_notices', [$this, 'process_notices_buffer'], -9999);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Обрабатывает буфер уведомлений из admin_notices и network_admin_notices.
     * Удаляет свой собственный хук, чтобы избежать рекурсии, затем заново вызывает текущий фильтр.
     */
    public function process_notices_buffer() {
        remove_action(current_filter(), [$this, 'process_notices_buffer'], -9999);

        $callback = [$this, 'analyze_and_filter'];

        ob_start($callback);
        do_action(current_filter());
        echo ob_get_clean();
    }

    /**
     * Анализирует HTML уведомления и добавляет кнопку блокировки.
     * Если уведомление уже заблокировано, возвращает пустой вывод.
     */
    public function analyze_and_filter($buffer) {
        if (empty($buffer)) return $buffer;

        $blocked_notices = get_option($this->option_name, []);
        $pattern = '/<(div|section)[^>]*class="[^"]*(notice|updated|error|update-nag)[^"]*"[^>]*>.*?<\/\1>/is';

        return preg_replace_callback($pattern, function($matches) use ($blocked_notices) {
            $notice_content = $matches[0];
            $notice_id = md5(strip_tags($notice_content));

            if (in_array($notice_id, $blocked_notices)) {
                return '';
            }

            $button_title = esc_attr__('Block this notice', 'zennotice-warden');
            $button = sprintf(
                '<button class="zennotice-warden-block" data-id="%s" title="%s" style="float:right; cursor:pointer; background:none; border:none; color:#cc0000; font-size:18px; line-height:1;">&times;</button>',
                esc_attr($notice_id),
                $button_title
            );

            return preg_replace('/(<\/div>|<\/section>)$/i', $button . '$1', $notice_content);
        }, $buffer);
    }

    public function toggle_notice() {
        check_ajax_referer('zennotice_warden_nonce', 'security');
        if (!current_user_can('manage_options')) {
            wp_send_json_error();
        }

        if (empty($_POST['notice_id'])) {
            wp_send_json_error();
        }

        $id = sanitize_text_field(wp_unslash($_POST['notice_id']));
        $blocked = get_option($this->option_name, []);

        if (!in_array($id, $blocked, true)) {
            $blocked[] = $id;
            update_option($this->option_name, $blocked);
        }

        wp_send_json_success();
    }

    /**
     * Регистрирует и подключает скрипт для кнопки «Block this notice».
     * Передаёт AJAX URL и nonce через локализованные данные.
     */
    public function enqueue_assets() {
        wp_enqueue_script('jquery');
        wp_register_script('zennotice-warden', false, ['jquery'], '1.6.3', true);
        wp_enqueue_script('zennotice-warden');

        $nonce = wp_create_nonce('zennotice_warden_nonce');

        wp_localize_script('zennotice-warden', 'ZenNoticeWardenData', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => $nonce,
            'action'   => 'zennotice_warden_toggle',
        ]);

        wp_add_inline_script('zennotice-warden', "
            jQuery(document).on('click', '.zennotice-warden-block', function(e) {
                e.preventDefault();
                var btn = jQuery(this);
                var id = btn.data('id');
                var notice = btn.closest('.notice, .updated, .error, .update-nag');

                jQuery.post(ZenNoticeWardenData.ajax_url, {
                    action: ZenNoticeWardenData.action,
                    notice_id: id,
                    security: ZenNoticeWardenData.nonce
                }, function(response) {
                    if (response.success) {
                        notice.fadeOut();
                    }
                });
            });
        ");
    }
}

new ZenNoticeWarden();