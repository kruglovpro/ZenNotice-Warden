<?php
/**
 * Plugin Name: ZenNotice Warden
 * Description: Individually disable, hide, or block admin notices using call-stack analysis.
 * Version: 1.7.0
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

    private $option_name = 'zennotice_warden_blocked_list';
    private $nonce_action = 'zennotice_warden_nonce';

    public function __construct() {
        if (!is_admin()) {
            return;
        }

        add_action('init', [$this, 'load_textdomain']);
        add_action('wp_ajax_zennotice_warden_toggle', [$this, 'toggle_notice']);
        add_action('admin_notices', [$this, 'process_notices_buffer'], -9999);
        add_action('network_admin_notices', [$this, 'process_notices_buffer'], -9999);
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
    }

    public function load_textdomain() {
        load_plugin_textdomain('zennotice-warden', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function process_notices_buffer() {
        remove_action(current_filter(), [$this, 'process_notices_buffer'], -9999);

        ob_start([$this, 'analyze_and_filter']);
        do_action(current_filter());
        echo ob_get_clean();
    }

    public function analyze_and_filter($buffer) {
        if (empty($buffer)) {
            return $buffer;
        }

        $blocked_notices = get_option($this->option_name, []);
        $pattern = '/<(div|section)[^>]*class="[^"]*(notice|updated|error|update-nag)[^"]*"[^>]*>.*?<\/\1>/is';

        return preg_replace_callback($pattern, function($matches) use ($blocked_notices) {
            $notice_content = $matches[0];
            $notice_id = $this->get_notice_id($notice_content);

            if (in_array($notice_id, $blocked_notices, true)) {
                return '';
            }

            $button_title = esc_attr__('Block this notice', 'zennotice-warden');
            $button = sprintf(
                '<button class="zennotice-warden-toggle" data-id="%s" title="%s" style="float:right; cursor:pointer; background:none; border:none; color:#cc0000; font-size:18px; line-height:1;">&times;</button>',
                esc_attr($notice_id),
                $button_title
            );

            return preg_replace('/(<\/div>|<\/section>)$/i', $button . '$1', $notice_content);
        }, $buffer);
    }

    private function get_notice_id($content) {
        $text = wp_strip_all_tags($content, true);
        $text = trim(preg_replace('/\s+/', ' ', $text));
        return md5($text);
    }

    public function toggle_notice() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(null, 403);
        }

        check_ajax_referer($this->nonce_action, 'security');

        if (empty($_POST['notice_id'])) {
            wp_send_json_error();
        }

        $id = sanitize_text_field(wp_unslash($_POST['notice_id']));
        $blocked = get_option($this->option_name, []);

        if (in_array($id, $blocked, true)) {
            $blocked = array_values(array_diff($blocked, [$id]));
        } else {
            $blocked[] = $id;
        }

        update_option($this->option_name, $blocked);
        wp_send_json_success();
    }

    public function enqueue_assets() {
        wp_register_script('zennotice-warden', '', ['jquery'], '1.7.0', true);
        wp_enqueue_script('zennotice-warden');

        wp_add_inline_script('zennotice-warden', '
jQuery(document).on("click", ".zennotice-warden-toggle", function(e) {
    e.preventDefault();
    var btn = jQuery(this);
    var id = btn.data("id");
    var notice = btn.closest(".notice, .updated, .error, .update-nag");
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
');

        wp_localize_script('zennotice-warden', 'ZenNoticeWardenData', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce($this->nonce_action),
            'action'   => 'zennotice_warden_toggle',
        ]);
    }

    public function add_settings_page() {
        add_options_page(
            __('ZenNotice Warden', 'zennotice-warden'),
            __('ZenNotice Warden', 'zennotice-warden'),
            'manage_options',
            'zennotice-warden',
            [$this, 'render_settings_page']
        );
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $blocked = get_option($this->option_name, []);

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['zennotice_warden_unblock_all'])) {
            check_admin_referer('zennotice_warden_settings');
            update_option($this->option_name, []);
            $blocked = [];
            echo '<div class="notice notice-success"><p>' . esc_html__('All notices unblocked.', 'zennotice-warden') . '</p></div>';
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['zennotice_warden_unblock'])) {
            check_admin_referer('zennotice_warden_settings');
            $id = sanitize_text_field(wp_unslash($_POST['zennotice_warden_unblock']));
            $blocked = array_values(array_diff($blocked, [$id]));
            update_option($this->option_name, $blocked);
            echo '<div class="notice notice-success"><p>' . esc_html__('Notice unblocked.', 'zennotice-warden') . '</p></div>';
        }

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('ZenNotice Warden', 'zennotice-warden'); ?></h1>
            <p><?php echo esc_html__('List of currently blocked admin notices.', 'zennotice-warden'); ?></p>

            <?php if (empty($blocked)) : ?>
                <p><em><?php echo esc_html__('No blocked notices.', 'zennotice-warden'); ?></em></p>
            <?php else : ?>
                <form method="post" style="margin-bottom: 15px;">
                    <?php wp_nonce_field('zennotice_warden_settings'); ?>
                    <button type="submit" name="zennotice_warden_unblock_all" value="1" class="button button-secondary" onclick="return confirm('<?php echo esc_js(__('Unblock all notices?', 'zennotice-warden')); ?>')">
                        <?php echo esc_html__('Unblock All', 'zennotice-warden'); ?>
                    </button>
                </form>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th scope="col"><?php echo esc_html__('Notice ID', 'zennotice-warden'); ?></th>
                            <th scope="col" width="120"><?php echo esc_html__('Actions', 'zennotice-warden'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($blocked as $id) : ?>
                            <tr>
                                <td><code><?php echo esc_html($id); ?></code></td>
                                <td>
                                    <form method="post" style="display:inline;">
                                        <?php wp_nonce_field('zennotice_warden_settings'); ?>
                                        <button type="submit" name="zennotice_warden_unblock" value="<?php echo esc_attr($id); ?>" class="button button-small">
                                            <?php echo esc_html__('Unblock', 'zennotice-warden'); ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    public function deactivate() {
        delete_option($this->option_name);
    }
}

new ZenNoticeWarden();
