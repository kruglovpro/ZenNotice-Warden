<?php
/**
 * Plugin Name: ZenNotice Warden
 * Plugin URI: https://wordpress.org/plugins/zennotice-warden/
 * Description: Hide annoying WordPress admin notices. Block, disable, or auto-hide plugin and system notices. AJAX-powered with regex filters.
 * Version: 1.8.1
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
        $output = '';
        $offset = 0;
        $pattern = '/<(div|section)([^>]*class\s*=\s*([\'"])[^\'"]*(notice|updated|error|update-nag)[^\'"]*\3[^>]*)>/i';

        while (preg_match($pattern, $buffer, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            $match_start = $matches[0][1];
            $tag = $matches[1][0];

            $output .= substr($buffer, $offset, $match_start - $offset);

            $depth = 1;
            $open_tag = '<' . $tag;
            $close_tag = '</' . $tag . '>';
            $search_start = $match_start + strlen($matches[0][0]);
            $notice_end = $search_start;

            while ($depth > 0 && $notice_end < strlen($buffer)) {
                $next_open = strpos($buffer, $open_tag, $search_start);
                $next_close = strpos($buffer, $close_tag, $search_start);

                if ($next_close === false) {
                    $notice_end = strlen($buffer);
                    break;
                }

                if ($next_open !== false && $next_open < $next_close) {
                    $depth++;
                    $search_start = $next_open + strlen($open_tag);
                } else {
                    $depth--;
                    $search_start = $next_close + strlen($close_tag);
                    if ($depth === 0) {
                        $notice_end = $next_close + strlen($close_tag);
                    }
                }
            }

            $notice_content = substr($buffer, $match_start, $notice_end - $match_start);
            $info = $this->get_notice_info($notice_content);

            if (in_array($info['id'], $blocked_ids, true)) {
                $output .= '';
            } else {
                $matched = false;
                foreach ($regex_filters as $filter) {
                    if (@preg_match($filter['pattern'], $info['text'])) {
                        $matched = true;
                        break;
                    }
                }

                if ($matched) {
                    $output .= '';
                } else {
                    $button_title = esc_attr__('Block this notice', 'zennotice-warden');
                    $button_text = mb_substr($info['text'], 0, 500);
                    $button = sprintf(
                        '<button class="zennotice-warden-toggle" data-id="%s" data-text="%s" title="%s" style="float:right; cursor:pointer; background:none; border:none; color:#cc0000; font-size:18px; line-height:1; margin-left:10px;">&times;</button>',
                        esc_attr($info['id']),
                        esc_attr($button_text),
                        $button_title
                    );

                    $close_tag_pos = strrpos($notice_content, $close_tag);
                    if ($close_tag_pos !== false) {
                        $notice_content = substr_replace($notice_content, $button . $close_tag, $close_tag_pos, strlen($close_tag));
                    }

                    $output .= $notice_content;
                }
            }

            $offset = $notice_end;
        }

        $output .= substr($buffer, $offset);
        return $output;
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

    private function validate_regex_pattern($pattern) {
        if (empty($pattern)) {
            return [false, __('Pattern cannot be empty.', 'zennotice-warden')];
        }

        if (strlen($pattern) > 200) {
            return [false, __('Pattern is too long (max 200 characters).', 'zennotice-warden')];
        }

        $nested = '/
            \(                    # opening paren
            (?:                   # start group
                [^()]*            # any non-paren chars
                \(                # look for another opening paren
            )
        /x';
        if (preg_match($nested, $pattern)) {
            return [false, __('Nested groups in patterns may cause performance issues. Please simplify.', 'zennotice-warden')];
        }

        $test_result = @preg_match($pattern, '');
        if ($test_result === false) {
            $error = error_get_last();
            $msg = $error ? preg_replace('/^preg_match\(\):\s*/i', '', $error['message']) : __('Invalid regex pattern.', 'zennotice-warden');
            return [false, $msg];
        }

        $start = microtime(true);
        @preg_match($pattern, str_repeat('a', 25));
        $elapsed = microtime(true) - $start;
        if ($elapsed > 0.05) {
            return [false, __('Pattern is too slow. Please simplify it.', 'zennotice-warden')];
        }

        return [true, ''];
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
        // Always use PHP-side md5 as canonical ID
        $canonical_id = md5(trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($text, true))));

        $blocked = $this->get_blocked_notices();
        $ids = array_map(function($n) { return $n['id']; }, $blocked);

        if (in_array($canonical_id, $ids, true)) {
            $blocked = array_values(array_filter($blocked, function($n) use ($canonical_id) {
                return $n['id'] !== $canonical_id;
            }));
        } else {
            $blocked[] = [
                'id' => $canonical_id,
                'text' => mb_substr($text, 0, 500),
            ];
        }

        update_option($this->option_name, $blocked);
        wp_send_json_success();
    }

    public function enqueue_assets() {
        wp_register_script('zennotice-warden', '', ['jquery'], '1.8.1', true);
        wp_enqueue_script('zennotice-warden');

        // Simple string hash matching PHP's md5 style (first 8 hex chars)
        $inline_js = '
function znw_hash(t) {
    var i, h = 0xdeadbeef;
    for (i = 0; i < t.length; i++) {
        h = ((h << 5) - h) + t.charCodeAt(i);
        h = h & h;
    }
    return (h >>> 0).toString(16);
}

function znw_add_button(notice) {
    if (notice.querySelector(".zennotice-warden-toggle")) return;
    var text = notice.textContent.replace(/\\s+/g, " ").trim();
    if (!text) return;
    var id = znw_hash(text);
    var btn = document.createElement("button");
    btn.className = "zennotice-warden-toggle";
    btn.setAttribute("data-id", id);
    btn.setAttribute("data-text", text.substring(0, 500));
    btn.title = "' . esc_js(__('Block this notice', 'zennotice-warden')) . '";
    btn.innerHTML = "&times;";
    notice.appendChild(btn);
}

document.addEventListener("DOMContentLoaded", function() {
    document.querySelectorAll(".notice, .updated, .error, .update-nag, .message").forEach(znw_add_button);
    var observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(m) {
            m.addedNodes.forEach(function(n) {
                if (n.nodeType === 1) {
                    if (n.matches && n.matches(".notice, .updated, .error, .update-nag, .message")) {
                        znw_add_button(n);
                    }
                    n.querySelectorAll && n.querySelectorAll(".notice, .updated, .error, .update-nag, .message").forEach(znw_add_button);
                }
            });
        });
    });
    observer.observe(document.body, { childList: true, subtree: true });
});

jQuery(document).on("click", ".zennotice-warden-toggle", function(e) {
    e.preventDefault();
    var btn = jQuery(this);
    var id = btn.data("id");
    var txt = btn.data("text") || "";
    var notice = btn.closest(".notice, .updated, .error, .update-nag, .message");
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
';

        wp_add_inline_script('zennotice-warden', $inline_js);

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
                    list($valid, $error) = $this->validate_regex_pattern($pattern);
                    if ($valid) {
                        $regex_filters[] = [
                            'pattern' => $pattern,
                            'description' => $description,
                        ];
                        update_option($this->regex_option_name, $regex_filters);
                        echo '<div class="notice notice-success"><p>' . esc_html__('Regex filter added.', 'zennotice-warden') . '</p></div>';
                    } else {
                        echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
                    }
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
