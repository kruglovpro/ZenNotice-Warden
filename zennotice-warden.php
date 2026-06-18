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
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
    }

    public function load_textdomain() {
        load_plugin_textdomain('zennotice-warden', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function enqueue_assets() {
        wp_register_script('zennotice-warden', '', ['jquery'], '1.8.1', true);
        wp_enqueue_script('zennotice-warden');

        $blocked = $this->get_blocked_notices();
        $blocked_texts = array_map(function($n) { return $n['text']; }, $blocked);
        $regex_filters = $this->get_regex_filters();
        $regex_patterns = array_map(function($f) { return $f['pattern']; }, $regex_filters);

        $inline_js = '
(function() {
    var BLOCKED = ' . json_encode($blocked_texts) . ';
    var REGEX = ' . json_encode($regex_patterns) . ';
    var NOTICE_SEL = ".notice, .updated, .error, .update-nag, .message";

    function getNoticeText(notice) {
        return notice.textContent.replace(/\s+/g, " ").trim();
    }

    function isBlocked(text) {
        for (var i = 0; i < BLOCKED.length; i++) {
            if (BLOCKED[i] === text) return true;
        }
        return false;
    }

    function matchesRegex(text) {
        for (var i = 0; i < REGEX.length; i++) {
            try {
                var re = new RegExp(REGEX[i].slice(1, REGEX[i].lastIndexOf("/")), REGEX[i].slice(REGEX[i].lastIndexOf("/") + 1));
                if (re.test(text)) return true;
            } catch(e) {}
        }
        return false;
    }

    function addButton(notice) {
        if (notice.querySelector(".znw-toggle")) return;
        var text = getNoticeText(notice);
        if (!text) return;
        var btn = document.createElement("button");
        btn.className = "znw-toggle";
        btn.setAttribute("data-text", text.substring(0, 500));
        btn.title = "' . esc_js(__('Block this notice', 'zennotice-warden')) . '";
        btn.innerHTML = "&times;";
        btn.style.cssText = "float:right;cursor:pointer;background:none;border:none;color:#cc0000;font-size:18px;line-height:1;margin-left:10px;padding:0;";
        notice.appendChild(btn);
    }

    function processNotice(notice) {
        var text = getNoticeText(notice);
        if (isBlocked(text) || matchesRegex(text)) {
            notice.style.display = "none";
            return;
        }
        addButton(notice);
    }

    document.addEventListener("DOMContentLoaded", function() {
        document.querySelectorAll(NOTICE_SEL).forEach(processNotice);
    });

    var observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(m) {
            m.addedNodes.forEach(function(n) {
                if (n.nodeType === 1) {
                    if (n.matches && n.matches(NOTICE_SEL)) processNotice(n);
                    if (n.querySelectorAll) n.querySelectorAll(NOTICE_SEL).forEach(processNotice);
                }
            });
        });
    });
    observer.observe(document.body, { childList: true, subtree: true });

    jQuery(document).on("click", ".znw-toggle", function(e) {
        e.preventDefault();
        var btn = jQuery(this);
        var txt = btn.data("text") || "";
        var notice = btn.closest(NOTICE_SEL);
        jQuery.post(ZenNoticeWardenData.ajax_url, {
            action: ZenNoticeWardenData.action,
            notice_text: txt,
            security: ZenNoticeWardenData.nonce
        }, function(response) {
            if (response.success) {
                notice.fadeOut(function() {
                    notice.css("display", "none");
                });
            }
        });
    });
})();
';

        wp_add_inline_script('zennotice-warden', $inline_js);

        wp_localize_script('zennotice-warden', 'ZenNoticeWardenData', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce($this->nonce_action),
            'action'   => 'zennotice_warden_toggle',
        ]);
    }

    public function toggle_notice() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(null, 403);
        }

        check_ajax_referer($this->nonce_action, 'security');

        if (empty($_POST['notice_text'])) {
            wp_send_json_error(['message' => 'Missing notice text']);
        }

        $text = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags(wp_unslash($_POST['notice_text']), true)));
        if (empty($text)) {
            wp_send_json_error(['message' => 'Empty notice text']);
        }

        $blocked = $this->get_blocked_notices();
        $found = false;
        foreach ($blocked as $i => $n) {
            if ($n['text'] === $text) {
                array_splice($blocked, $i, 1);
                $found = true;
                break;
            }
        }

        if (!$found) {
            $blocked[] = [
                'text' => mb_substr($text, 0, 500),
            ];
        }

        update_option($this->option_name, $blocked);
        wp_send_json_success();
    }

    private function normalize_blocked($blocked) {
        if (empty($blocked) || !is_array($blocked)) {
            return [];
        }

        $normalized = [];
        foreach ($blocked as $item) {
            if (is_string($item)) {
                $normalized[] = ['text' => $item];
            } elseif (is_array($item) && isset($item['text'])) {
                $normalized[] = ['text' => $item['text']];
            }
        }
        return $normalized;
    }

    private function get_blocked_notices() {
        $raw = get_option($this->option_name, []);
        return $this->normalize_blocked($raw);
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
                $idx = intval($_POST['zennotice_warden_unblock']);
                if (isset($blocked[$idx])) {
                    array_splice($blocked, $idx, 1);
                    update_option($this->option_name, $blocked);
                    echo '<div class="notice notice-success"><p>' . esc_html__('Notice unblocked.', 'zennotice-warden') . '</p></div>';
                }
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
            <p><?php echo esc_html__('Notices matching these regular expressions will be automatically hidden, even if they appear dynamically.', 'zennotice-warden'); ?></p>

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
            <p><?php echo esc_html__('Hidden admin notices. Click × on a notice to block it, then refresh the page to see it here.', 'zennotice-warden'); ?></p>

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
                            <th scope="col"><?php echo esc_html__('Notice Text', 'zennotice-warden'); ?></th>
                            <th scope="col" width="80"><?php echo esc_html__('Actions', 'zennotice-warden'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($blocked as $i => $notice) : ?>
                            <tr>
                                <td><?php echo esc_html($notice['text']); ?></td>
                                <td>
                                    <form method="post" style="display:inline;">
                                        <?php wp_nonce_field('zennotice_warden_settings'); ?>
                                        <button type="submit" name="zennotice_warden_unblock" value="<?php echo $i; ?>" class="button button-small">
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
