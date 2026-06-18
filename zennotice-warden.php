<?php
/**
 * Plugin Name: ZenNotice Warden
 * Plugin URI: https://wordpress.org/plugins/zennotice-warden/
 * Description: Individually hide or block admin notices. AJAX-powered with regex auto-blocking.
 * Version: 1.8.0
 * Author: kruglovnet
 * Author URI: https://profiles.wordpress.org/kruglovnet/
 * Text Domain: zennotice-warden
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

class ZenNoticeWarden {

    private $option_name = 'zennotice_warden_blocked_list';
    private $regex_option_name = 'zennotice_warden_regex_filters';
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

        $blocked_ids = $this->get_blocked_ids();
        $regex_filters = $this->get_regex_filters();
        $pattern = '/<(div|section)[^>]*class="[^"]*(notice|updated|error|update-nag)[^"]*"[^>]*>.*?<\/\1>/is';

        return preg_replace_callback($pattern, function($matches) use ($blocked_ids, $regex_filters) {
            $notice_content = $matches[0];
            $info = $this->get_notice_info($notice_content);

            if (in_array($info['id'], $blocked_ids, true)) {
                return '';
            }

            foreach ($regex_filters as $filter) {
                if (@preg_match($filter['pattern'], $info['text'])) {
                    return '';
                }
            }

            $button_title = esc_attr__('Block this notice', 'zennotice-warden');
            $button_text = mb_substr($info['text'], 0, 500);
            $button = sprintf(
                '<button class="zennotice-warden-toggle" data-id="%s" data-text="%s" title="%s" style="float:right; cursor:pointer; background:none; border:none; color:#cc0000; font-size:18px; line-height:1;">&times;</button>',
                esc_attr($info['id']),
                esc_attr($button_text),
                $button_title
            );

            return preg_replace('/(<\/div>|<\/section>)$/i', $button . '$1', $notice_content);
        }, $buffer);
    }

    private function get_notice_info($content) {
        $text = wp_strip_all_tags($content, true);
        $text = trim(preg_replace('/\s+/', ' ', $text));
        return [
            'id' => md5($text),
            'text' => $text,
        ];
    }

    private function normalize_blocked($blocked) {
        if (empty($blocked) || !is_array($blocked)) {
            return [];
        }

        if (isset($blocked[0]) && is_string($blocked[0])) {
            return array_map(function($id) {
                return ['id' => $id, 'text' => ''];
            }, $blocked);
        }

        return $blocked;
    }

    private function get_blocked_notices() {
        $raw = get_option($this->option_name, []);
        return $this->normalize_blocked($raw);
    }

    private function get_blocked_ids() {
        $notices = $this->get_blocked_notices();
        return array_map(function($n) {
            return $n['id'];
        }, $notices);
    }

    private function get_regex_filters() {
        return get_option($this->regex_option_name, []);
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
        $text = isset($_POST['notice_text']) ? sanitize_text_field(wp_unslash($_POST['notice_text'])) : '';
        $blocked = $this->get_blocked_notices();
        $ids = array_map(function($n) { return $n['id']; }, $blocked);

        if (in_array($id, $ids, true)) {
            $blocked = array_values(array_filter($blocked, function($n) use ($id) {
                return $n['id'] !== $id;
            }));
        } else {
            $blocked[] = [
                'id' => $id,
                'text' => mb_substr($text, 0, 500),
            ];
        }

        update_option($this->option_name, $blocked);
        wp_send_json_success();
    }

    public function enqueue_assets() {
        wp_register_script('zennotice-warden', '', ['jquery'], '1.8.0', true);
        wp_enqueue_script('zennotice-warden');

        wp_add_inline_script('zennotice-warden', '
jQuery(document).on("click", ".zennotice-warden-toggle", function(e) {
    e.preventDefault();
    var btn = jQuery(this);
    var id = btn.data("id");
    var txt = btn.data("text") || "";
    var notice = btn.closest(".notice, .updated, .error, .update-nag");
    jQuery.post(ZenNoticeWardenData.ajax_url, {
        action: ZenNoticeWardenData.action,
        notice_id: id,
        notice_text: txt,
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

        $blocked = $this->get_blocked_notices();
        $regex_filters = $this->get_regex_filters();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            check_admin_referer('zennotice_warden_settings');

            if (isset($_POST['zennotice_warden_unblock_all'])) {
                update_option($this->option_name, []);
                $blocked = [];
                echo '<div class="notice notice-success"><p>' . esc_html__('All notices unblocked.', 'zennotice-warden') . '</p></div>';
            }

            if (isset($_POST['zennotice_warden_unblock'])) {
                $id = sanitize_text_field(wp_unslash($_POST['zennotice_warden_unblock']));
                $blocked = array_values(array_filter($blocked, function($n) use ($id) {
                    return $n['id'] !== $id;
                }));
                update_option($this->option_name, $blocked);
                echo '<div class="notice notice-success"><p>' . esc_html__('Notice unblocked.', 'zennotice-warden') . '</p></div>';
            }

            if (isset($_POST['zennotice_warden_add_regex'])) {
                $pattern = sanitize_text_field(wp_unslash($_POST['zennotice_warden_regex_pattern']));
                $description = sanitize_text_field(wp_unslash($_POST['zennotice_warden_regex_desc']));
                if (!empty($pattern)) {
                    $regex_filters[] = [
                        'pattern' => $pattern,
                        'description' => $description,
                    ];
                    update_option($this->regex_option_name, $regex_filters);
                    echo '<div class="notice notice-success"><p>' . esc_html__('Regex filter added.', 'zennotice-warden') . '</p></div>';
                }
            }

            if (isset($_POST['zennotice_warden_delete_regex'])) {
                $index = intval($_POST['zennotice_warden_delete_regex']);
                if (isset($regex_filters[$index])) {
                    array_splice($regex_filters, $index, 1);
                    update_option($this->regex_option_name, $regex_filters);
                    echo '<div class="notice notice-success"><p>' . esc_html__('Regex filter removed.', 'zennotice-warden') . '</p></div>';
                }
            }
        }

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('ZenNotice Warden', 'zennotice-warden'); ?></h1>

            <h2><?php echo esc_html__('Regex Filters', 'zennotice-warden'); ?></h2>
            <p><?php echo esc_html__('Notices matching these regular expressions will be automatically blocked.', 'zennotice-warden'); ?></p>

            <form method="post" style="margin-bottom: 15px;">
                <?php wp_nonce_field('zennotice_warden_settings'); ?>
                <input type="text" name="zennotice_warden_regex_pattern" placeholder="<?php esc_attr_e('Pattern (e.g. /update available/i)', 'zennotice-warden'); ?>" style="width:300px;" required>
                <input type="text" name="zennotice_warden_regex_desc" placeholder="<?php esc_attr_e('Description (optional)', 'zennotice-warden'); ?>" style="width:200px;">
                <button type="submit" name="zennotice_warden_add_regex" value="1" class="button button-primary"><?php echo esc_html__('Add Filter', 'zennotice-warden'); ?></button>
            </form>

            <?php if (!empty($regex_filters)) : ?>
                <table class="wp-list-table widefat fixed striped" style="margin-bottom:25px;">
                    <thead>
                        <tr>
                            <th scope="col"><?php echo esc_html__('Pattern', 'zennotice-warden'); ?></th>
                            <th scope="col"><?php echo esc_html__('Description', 'zennotice-warden'); ?></th>
                            <th scope="col" width="80"><?php echo esc_html__('Actions', 'zennotice-warden'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($regex_filters as $i => $filter) : ?>
                            <tr>
                                <td><code><?php echo esc_html($filter['pattern']); ?></code></td>
                                <td><?php echo esc_html($filter['description']); ?></td>
                                <td>
                                    <form method="post" style="display:inline;">
                                        <?php wp_nonce_field('zennotice_warden_settings'); ?>
                                        <button type="submit" name="zennotice_warden_delete_regex" value="<?php echo $i; ?>" class="button button-small button-link-delete"><?php echo esc_html__('Delete', 'zennotice-warden'); ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2><?php echo esc_html__('Blocked Notices', 'zennotice-warden'); ?></h2>
            <p><?php echo esc_html__('List of manually blocked admin notices.', 'zennotice-warden'); ?></p>

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
                            <th scope="col" width="280"><?php echo esc_html__('Notice ID', 'zennotice-warden'); ?></th>
                            <th scope="col"><?php echo esc_html__('Content', 'zennotice-warden'); ?></th>
                            <th scope="col" width="80"><?php echo esc_html__('Actions', 'zennotice-warden'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($blocked as $notice) : ?>
                            <tr>
                                <td><code><?php echo esc_html($notice['id']); ?></code></td>
                                <td><?php echo esc_html($notice['text']); ?></td>
                                <td>
                                    <form method="post" style="display:inline;">
                                        <?php wp_nonce_field('zennotice_warden_settings'); ?>
                                        <button type="submit" name="zennotice_warden_unblock" value="<?php echo esc_attr($notice['id']); ?>" class="button button-small">
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
        delete_option($this->regex_option_name);
    }
}

new ZenNoticeWarden();
