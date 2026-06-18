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
    private $blocked_plugins_option = 'zennotice_warden_blocked_plugins';
    private $nonce_action = 'zennotice_warden_nonce';
    private $plugin_names_cache = null;

    public function __construct() {
        if (!is_admin()) {
            return;
        }

        add_action('init', [$this, 'load_textdomain']);
        add_action('wp_ajax_zennotice_warden_toggle', [$this, 'toggle_notice']);
        add_action('admin_notices', [$this, 'start_buffer'], -9999);
        add_action('admin_notices', [$this, 'flush_buffer'], 9999);
        add_action('network_admin_notices', [$this, 'start_buffer'], -9999);
        add_action('network_admin_notices', [$this, 'flush_buffer'], 9999);
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
    }

    public function start_buffer() {
        ob_start();
    }

    public function flush_buffer() {
        $html = ob_get_clean();
        if ($html === false || $html === '') {
            return;
        }
        echo $this->filter_notices($html);
    }

    public function filter_notices($html) {
        $blocked_texts = array_map(function($n) { return $n['text']; }, $this->get_blocked_notices());
        $blocked_plugins = $this->get_blocked_plugins();
        $regex_filters = $this->get_regex_filters();
        $patterns = array_map(function($f) { return $f['pattern']; }, $regex_filters);

        $output = '';
        $offset = 0;
        $regex = '/<(div|section)([^>]*class\s*=\s*([\'"])[^\'"]*(notice|updated|error|update-nag)[^\'"]*\3[^>]*)>/i';

        while (preg_match($regex, $html, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $m[0][1];
            $tag = $m[1][0];
            $close_tag = '</' . $tag . '>';
            $head_len = strlen($m[0][0]);
            $search = $start + $head_len;

            $depth = 1;
            $end = $search;
            while ($depth > 0 && $end < strlen($html)) {
                $no = strpos($html, '<' . $tag, $end);
                $nc = strpos($html, $close_tag, $end);
                if ($nc === false) { $end = strlen($html); break; }
                if ($no !== false && $no < $nc) { $depth++; $end = $no + strlen($tag) + 1; }
                else { $depth--; $end = $nc + strlen($close_tag); }
            }

            $output .= substr($html, $offset, $start - $offset);
            $full = substr($html, $start, $end - $start);
            $text = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($full, true)));

            $hide = false;

            foreach ($blocked_texts as $bt) {
                if ($bt === $text) { $hide = true; break; }
            }

            if (!$hide && !empty($blocked_plugins)) {
                $source = $this->detect_notice_source($text);
                if ($source && in_array($source, $blocked_plugins, true)) {
                    $hide = true;
                }
            }

            if (!$hide) {
                foreach ($patterns as $p) {
                    if (@preg_match($p, $text)) { $hide = true; break; }
                }
            }

            if ($hide) {
                $insert = strpos($full, '>') + 1;
                $output .= substr($full, 0, $insert - 1) . ' style="display:none"' . substr($full, $insert - 1);
            } else {
                $output .= $full;
            }

            $offset = $end;
        }

        $output .= substr($html, $offset);
        return $output;
    }

    public function load_textdomain() {
        load_plugin_textdomain('zennotice-warden', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function enqueue_assets() {
        wp_register_script('zennotice-warden', '', ['jquery'], '1.8.1', true);
        wp_enqueue_script('zennotice-warden');

        $blocked = $this->get_blocked_notices();
        $blocked_texts = array_map(function($n) { return $n['text']; }, $blocked);
        $blocked_plugins = $this->get_blocked_plugins();
        $regex_filters = $this->get_regex_filters();
        $regex_patterns = array_map(function($f) { return $f['pattern']; }, $regex_filters);

        $inline_js = '
(function() {
    var BLOCKED_TEXTS = ' . json_encode($blocked_texts) . ';
    var BLOCKED_PLUGINS = ' . json_encode($blocked_plugins) . ';
    var REGEX = ' . json_encode($regex_patterns) . ';
    var NOTICE_SEL = ".notice, .updated, .error, .update-nag, .message";

    var pluginNames = ' . json_encode($this->get_plugin_names_list()) . ';

    function getNoticeText(notice) {
        return notice.textContent.replace(/\s+/g, " ").trim();
    }

    function detectSource(text) {
        var best = "", bestLen = 0;
        for (var i = 0; i < pluginNames.length; i++) {
            var name = pluginNames[i];
            if (!name) continue;
            var idx = text.toLowerCase().indexOf(name.toLowerCase());
            if (idx !== -1 && name.length > bestLen) {
                best = name;
                bestLen = name.length;
            }
        }
        return best;
    }

    function isBlocked(text) {
        for (var i = 0; i < BLOCKED_TEXTS.length; i++) {
            if (BLOCKED_TEXTS[i] === text) return true;
        }
        return false;
    }

    function isPluginBlocked(text) {
        if (!BLOCKED_PLUGINS.length) return false;
        var src = detectSource(text);
        if (!src) return false;
        for (var i = 0; i < BLOCKED_PLUGINS.length; i++) {
            if (BLOCKED_PLUGINS[i] === src) return true;
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

    function addButtons(notice, source) {
        if (notice.querySelector(".znw-toggle")) return;
        var text = getNoticeText(notice);
        if (!text) return;

        var wrap = document.createElement("span");
        wrap.className = "znw-wrap";
        wrap.style.cssText = "float:right;display:flex;gap:4px;margin-left:10px;flex-shrink:0;";

        var btn = document.createElement("button");
        btn.className = "znw-toggle znw-toggle-notice";
        btn.setAttribute("data-text", text.substring(0, 500));
        btn.setAttribute("data-source", source || "");
        btn.textContent = "' . esc_js(__('Block notice', 'zennotice-warden')) . '";
        btn.title = "' . esc_js(__('Hide this notice', 'zennotice-warden')) . '";
        btn.style.cssText = "cursor:pointer;background:var(--wp-components-color-accent,#2271b1);color:#fff;border:none;border-radius:3px;font-size:11px;line-height:1;padding:3px 6px;white-space:nowrap;";
        wrap.appendChild(btn);

        if (source) {
            var pbtn = document.createElement("button");
            pbtn.className = "znw-toggle znw-toggle-plugin";
            pbtn.setAttribute("data-text", text.substring(0, 500));
            pbtn.setAttribute("data-source", source);
            pbtn.textContent = "' . esc_js(__('Block plugin', 'zennotice-warden')) . '";
            pbtn.title = "' . esc_js(__('Block all notices from this plugin', 'zennotice-warden')) . '";
            pbtn.style.cssText = "cursor:pointer;background:#d63638;color:#fff;border:none;border-radius:3px;font-size:11px;line-height:1;padding:3px 6px;white-space:nowrap;";
            wrap.appendChild(pbtn);
        }

        notice.appendChild(wrap);
    }

    function processNotice(notice) {
        var text = getNoticeText(notice);
        if (isBlocked(text) || isPluginBlocked(text) || matchesRegex(text)) {
            notice.style.display = "none";
            return;
        }
        addButtons(notice, detectSource(text));
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
        var src = btn.data("source") || "";
        var notice = btn.closest(NOTICE_SEL);
        var data = {
            action: ZenNoticeWardenData.action,
            notice_text: txt,
            security: ZenNoticeWardenData.nonce
        };
        if (btn.hasClass("znw-toggle-plugin") && src) {
            data.block_plugin = 1;
        }
        jQuery.post(ZenNoticeWardenData.ajax_url, data, function(response) {
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
            $source = $this->detect_notice_source($text);
            $blocked[] = [
                'text'   => mb_substr($text, 0, 500),
                'source' => $source,
            ];
        }

        update_option($this->option_name, $blocked);

        // If block_plugin flag is set, also block the plugin entirely
        if (!empty($_POST['block_plugin']) && !empty($source)) {
            $blocked_plugins = $this->get_blocked_plugins();
            if (!in_array($source, $blocked_plugins, true)) {
                $blocked_plugins[] = $source;
                update_option($this->blocked_plugins_option, $blocked_plugins);
            }
        }

        wp_send_json_success();
    }

    private function get_plugin_names_list() {
        if ($this->plugin_names_cache !== null) {
            return $this->plugin_names_cache;
        }
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $names = [];
        foreach (get_plugins() as $data) {
            $name = trim($data['Name']);
            if ($name) $names[] = $name;
        }
        $this->plugin_names_cache = $names;
        return $names;
    }

    private function detect_notice_source($text) {
        foreach ($this->get_plugin_names_list() as $name) {
            if (mb_stripos($text, $name) !== false) {
                return $name;
            }
        }
        return '';
    }

    private function normalize_blocked($blocked) {
        if (empty($blocked) || !is_array($blocked)) {
            return [];
        }

        $normalized = [];
        foreach ($blocked as $item) {
            if (is_string($item)) {
                $normalized[] = ['text' => $item, 'source' => ''];
            } elseif (is_array($item) && isset($item['text'])) {
                $normalized[] = [
                    'text'   => $item['text'],
                    'source' => isset($item['source']) ? $item['source'] : '',
                ];
            }
        }
        return $normalized;
    }

    private function get_blocked_notices() {
        $raw = get_option($this->option_name, []);
        return $this->normalize_blocked($raw);
    }

    private function get_blocked_plugins() {
        $plugins = get_option($this->blocked_plugins_option, []);
        return is_array($plugins) ? $plugins : [];
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
        $blocked_plugins = $this->get_blocked_plugins();
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

            if (isset($_POST['zennotice_warden_block_plugin'])) {
                $plugin = sanitize_text_field(wp_unslash($_POST['zennotice_warden_block_plugin']));
                if (!empty($plugin) && !in_array($plugin, $blocked_plugins, true)) {
                    $blocked_plugins[] = $plugin;
                    update_option($this->blocked_plugins_option, $blocked_plugins);
                    echo '<div class="notice notice-success"><p>' . sprintf(esc_html__('All notices from "%s" will now be blocked.', 'zennotice-warden'), esc_html($plugin)) . '</p></div>';
                }
            }

            if (isset($_POST['zennotice_warden_unblock_plugin'])) {
                $plugin = sanitize_text_field(wp_unslash($_POST['zennotice_warden_unblock_plugin']));
                $blocked_plugins = array_values(array_filter($blocked_plugins, function($p) use ($plugin) {
                    return $p !== $plugin;
                }));
                update_option($this->blocked_plugins_option, $blocked_plugins);
                echo '<div class="notice notice-success"><p>' . sprintf(esc_html__('Plugin "%s" unblocked.', 'zennotice-warden'), esc_html($plugin)) . '</p></div>';
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

            <h2><?php echo esc_html__('Blocked Plugins', 'zennotice-warden'); ?></h2>
            <p><?php echo esc_html__('All notices from these plugins will be automatically hidden, even if the text changes.', 'zennotice-warden'); ?></p>

            <?php if (empty($blocked_plugins)) : ?>
                <p><em><?php echo esc_html__('No blocked plugins. Click × on a notice, then use "Block plugin" in the list below.', 'zennotice-warden'); ?></em></p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped" style="margin-bottom:25px;">
                    <thead>
                        <tr>
                            <th scope="col"><?php echo esc_html__('Plugin Name', 'zennotice-warden'); ?></th>
                            <th scope="col" style="min-width:120px;"><?php echo esc_html__('Actions', 'zennotice-warden'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($blocked_plugins as $plugin) : ?>
                            <tr>
                                <td><strong><?php echo esc_html($plugin); ?></strong></td>
                                <td>
                                    <form method="post" style="display:inline;">
                                        <?php wp_nonce_field('zennotice_warden_settings'); ?>
                                        <button type="submit" name="zennotice_warden_unblock_plugin" value="<?php echo esc_attr($plugin); ?>" class="button button-small button-link-delete"><?php echo esc_html__('Unblock', 'zennotice-warden'); ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

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
                            <th scope="col" style="min-width:100px;"><?php echo esc_html__('Actions', 'zennotice-warden'); ?></th>
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
                            <th scope="col"><?php echo esc_html__('Plugin / Source', 'zennotice-warden'); ?></th>
                            <th scope="col" style="min-width:120px;"><?php echo esc_html__('Actions', 'zennotice-warden'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($blocked as $i => $notice) : ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($notice['source'] ?: __('Unknown', 'zennotice-warden')); ?></strong>
                                    <br><small style="color:#666;" title="<?php echo esc_attr($notice['text']); ?>"><?php echo esc_html(mb_substr($notice['text'], 0, 120)) . (mb_strlen($notice['text']) > 120 ? '...' : ''); ?></small>
                                </td>
                                <td>
                                    <form method="post" style="display:inline;">
                                        <?php wp_nonce_field('zennotice_warden_settings'); ?>
                                        <button type="submit" name="zennotice_warden_unblock" value="<?php echo $i; ?>" class="button button-small">
                                            <?php echo esc_html__('Unblock', 'zennotice-warden'); ?>
                                        </button>
                                    </form>
                                    <?php if (!empty($notice['source'])) : ?>
                                        <form method="post" style="display:inline;margin-left:4px;">
                                            <?php wp_nonce_field('zennotice_warden_settings'); ?>
                                            <button type="submit" name="zennotice_warden_block_plugin" value="<?php echo esc_attr($notice['source']); ?>" class="button button-small" onclick="return confirm('<?php echo esc_js(sprintf(__('Block all notices from %s?', 'zennotice-warden'), $notice['source'])); ?>')">
                                                <?php echo esc_html__('Block plugin', 'zennotice-warden'); ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
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
        delete_option($this->blocked_plugins_option);
    }
}

new ZenNoticeWarden();
