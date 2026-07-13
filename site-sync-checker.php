<?php
/**
 * Plugin Name: Site Sync Checker
 * Description: Compare pages between a source site feed and the current site to find missing pages.
 * Version: 1.0.6
 * Author: CI Data Works
 * Text Domain: site-sync-checker
 */

if (!defined('ABSPATH')) {
    exit;
}

if (defined('CI_SITE_SYNC_CHECKER_LOADED')) {
    return;
}

define('CI_SITE_SYNC_CHECKER_LOADED', true);

class CI_Site_Sync_Checker_Plugin {
    private $option_name = 'ssc_settings';
    private $rest_namespace = 'site-sync-checker/v1';
    private $batch_size = 25;

    public function __construct() {
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('wp_ajax_ssc_check_batch', array($this, 'ajax_check_batch'));
    }

    public function admin_menu() {
        add_management_page(
            __('Site Sync Checker', 'site-sync-checker'),
            __('Site Sync Checker', 'site-sync-checker'),
            'manage_options',
            'site-sync-checker',
            array($this, 'render_admin_page')
        );
    }

    public function register_settings() {
        register_setting('ssc_settings_group', $this->option_name, array($this, 'sanitize_settings'));
    }

    public function sanitize_settings($input) {
        if (!is_array($input)) {
            $input = array();
        }

        if (isset($input['source_url'])) {
            return array('source_url' => esc_url_raw(trim(wp_unslash($input['source_url']))));
        }

        return array('source_url' => '');
    }

    public function register_rest_routes() {
        if (!function_exists('register_rest_route')) {
            return;
        }

        register_rest_route($this->rest_namespace, '/pages', array(
            'methods' => class_exists('WP_REST_Server') ? WP_REST_Server::READABLE : 'GET',
            'callback' => array($this, 'rest_pages_feed'),
            'permission_callback' => '__return_true',
        ));
    }

    public function rest_pages_feed($request) {
        $limit = absint($request->get_param('limit'));
        $offset = absint($request->get_param('offset'));

        if ($limit < 1 || $limit > 500) {
            $limit = $this->batch_size;
        }

        $total = $this->count_site_pages();

        return rest_ensure_response(array(
            'site_url' => home_url('/'),
            'generated_at' => current_time('mysql'),
            'limit' => $limit,
            'offset' => $offset,
            'total' => $total,
            'has_more' => $total > ($offset + $limit),
            'pages' => $this->get_site_pages($limit, $offset),
        ));
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = $this->get_settings();
        $source_url = isset($settings['source_url']) ? $settings['source_url'] : '';
        $feed_url = function_exists('rest_url') ? rest_url($this->rest_namespace . '/pages') : home_url('/?rest_route=/' . $this->rest_namespace . '/pages');

        if (isset($_GET['ssc_safe']) && $_GET['ssc_safe'] === '1') {
            ?>
            <div class="wrap">
                <h1><?php esc_html_e('Site Sync Checker Safe Mode', 'site-sync-checker'); ?></h1>
                <p><?php esc_html_e('This mode avoids running the checker UI and only shows lightweight diagnostic data.', 'site-sync-checker'); ?></p>
                <p><input type="url" class="large-text code" readonly value="<?php echo esc_attr($feed_url); ?>" onclick="this.select();" /></p>
                <?php $this->render_debug_panel(); ?>
            </div>
            <?php
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Site Sync Checker', 'site-sync-checker'); ?></h1>
            <p><?php esc_html_e('Install this plugin on both sites. Copy the source feed URL from the old site, then paste it into the new site to check missing pages.', 'site-sync-checker'); ?></p>

            <h2><?php esc_html_e('Source Feed URL', 'site-sync-checker'); ?></h2>
            <p>
                <input type="url" class="large-text code" readonly value="<?php echo esc_attr($feed_url); ?>" onclick="this.select();" />
            </p>
            <p class="description"><?php esc_html_e('Copy this URL from the source site and use it on the target site.', 'site-sync-checker'); ?></p>

            <form method="post" action="options.php">
                <?php settings_fields('ssc_settings_group'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="ssc_source_url"><?php esc_html_e('Original File URL', 'site-sync-checker'); ?></label></th>
                        <td>
                            <input type="url" id="ssc_source_url" name="<?php echo esc_attr($this->option_name); ?>[source_url]" class="large-text" value="<?php echo esc_attr($source_url); ?>" placeholder="https://example.com/wp-json/site-sync-checker/v1/pages" />
                            <p class="description"><?php esc_html_e('Paste the source site feed URL or a compatible JSON file URL.', 'site-sync-checker'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Save Settings', 'site-sync-checker')); ?>
            </form>

            <hr />

            <form method="post" id="ssc_check_form" action="#">
                <input type="hidden" id="ssc_ajax_nonce" value="<?php echo esc_attr(wp_create_nonce('ssc_ajax_check')); ?>" />
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="ssc_check_source_url"><?php esc_html_e('Original File URL', 'site-sync-checker'); ?></label></th>
                        <td>
                            <input type="url" id="ssc_check_source_url" name="ssc_source_url" class="large-text" value="<?php echo esc_attr($source_url); ?>" placeholder="https://example.com/wp-json/site-sync-checker/v1/pages" />
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Check Missing Pages', 'site-sync-checker'), 'primary', 'ssc_check_now', false); ?>
            </form>
            <div id="ssc_ajax_result" style="margin-top:20px;"></div>

            <?php if (isset($_GET['ssc_debug']) && $_GET['ssc_debug'] === '1') : ?>
                <?php $this->render_debug_panel(); ?>
            <?php else : ?>
                <p style="margin-top:20px;">
                    <a href="<?php echo esc_url(add_query_arg('ssc_debug', '1')); ?>"><?php esc_html_e('Show debug information', 'site-sync-checker'); ?></a>
                </p>
            <?php endif; ?>
        </div>
        <script>
        (function() {
            var form = document.getElementById('ssc_check_form');
            var result = document.getElementById('ssc_ajax_result');
            var sourceInput = document.getElementById('ssc_check_source_url');
            var nonceInput = document.getElementById('ssc_ajax_nonce');
            var submitButton = form ? form.querySelector('[type="submit"]') : null;
            var batchSize = <?php echo (int) $this->batch_size; ?>;
            var batchDelay = 250;
            var ajaxEndpoint = <?php echo function_exists('wp_json_encode') ? wp_json_encode(admin_url('admin-ajax.php')) : json_encode(admin_url('admin-ajax.php')); ?>;
            var localPaths = {};
            var missing = [];
            var localCount = 0;
            var sourceCount = 0;

            if (!form || !result || !sourceInput || !nonceInput || !submitButton) {
                return;
            }

            function escapeHtml(value) {
                return String(value || '').replace(/[&<>"']/g, function(character) {
                    return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[character];
                });
            }

            function encodeData(data) {
                var pairs = [];

                Object.keys(data).forEach(function(key) {
                    pairs.push(encodeURIComponent(key) + '=' + encodeURIComponent(data[key]));
                });

                return pairs.join('&');
            }

            function setStatus(message, type) {
                var noticeClass = type === 'error' ? 'notice notice-error' : 'notice notice-info';
                result.innerHTML = '<div class="' + noticeClass + '"><p>' + escapeHtml(message) + '</p></div>';
            }

            function ajaxRequest(data) {
                data.action = 'ssc_check_batch';
                data.nonce = nonceInput.value;

                return new Promise(function(resolve, reject) {
                    var request = new XMLHttpRequest();

                    request.open('POST', ajaxEndpoint, true);
                    request.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');

                    request.onreadystatechange = function() {
                        var response;

                        if (request.readyState !== 4) {
                            return;
                        }

                        try {
                            response = JSON.parse(request.responseText);
                        } catch (error) {
                            reject(new Error('AJAX returned an invalid response.'));
                            return;
                        }

                        if (request.status < 200 || request.status >= 300 || !response || !response.success) {
                            reject(new Error(response && response.data && response.data.message ? response.data.message : 'AJAX request failed.'));
                            return;
                        }

                        resolve(response.data);
                    };

                    request.send(encodeData(data));
                });
            }

            function waitForNextBatch(callback) {
                return new Promise(function(resolve) {
                    window.setTimeout(function() {
                        resolve(callback());
                    }, batchDelay);
                });
            }

            function readLocal(offset) {
                setStatus('Reading current site pages: ' + offset + ' / ' + (localCount || '?'), 'info');

                return ajaxRequest({
                    phase: 'local',
                    offset: offset,
                    limit: batchSize
                }).then(function(data) {
                    localCount = parseInt(data.total, 10) || 0;
                    (data.pages || []).forEach(function(page) {
                        if (page.path) {
                            localPaths[page.path] = true;
                        }
                    });

                    if (data.has_more) {
                        return waitForNextBatch(function() {
                            return readLocal(data.next_offset);
                        });
                    }

                    return waitForNextBatch(function() {
                        return readSource(0);
                    });
                });
            }

            function readSource(offset) {
                setStatus('Checking source pages: ' + sourceCount + ' processed, ' + missing.length + ' missing found.', 'info');

                return ajaxRequest({
                    phase: 'source',
                    source_url: sourceInput.value,
                    offset: offset,
                    limit: batchSize
                }).then(function(data) {
                    (data.pages || []).forEach(function(page) {
                        sourceCount++;

                        if (page.path && !localPaths[page.path]) {
                            missing.push(page);
                        }
                    });

                    if (data.has_more) {
                        return waitForNextBatch(function() {
                            return readSource(data.next_offset);
                        });
                    }

                    renderFinalResult();
                });
            }

            function renderFinalResult() {
                var html = '<h2>Check Result</h2>';
                html += '<p>Source pages: ' + sourceCount + '. Current site pages: ' + localCount + '. Missing pages: ' + missing.length + '.</p>';

                if (!missing.length) {
                    html += '<div class="notice notice-success"><p>No missing pages found.</p></div>';
                } else {
                    html += '<table class="widefat striped"><thead><tr><th>Title</th><th>Path</th><th>Source URL</th></tr></thead><tbody>';
                    missing.forEach(function(page) {
                        html += '<tr><td>' + escapeHtml(page.title) + '</td><td><code>' + escapeHtml(page.path) + '</code></td><td>';

                        if (page.url) {
                            html += '<a href="' + escapeHtml(page.url) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(page.url) + '</a>';
                        }

                        html += '</td></tr>';
                    });
                    html += '</tbody></table>';
                }

                result.innerHTML = html;
                submitButton.disabled = false;
            }

            form.addEventListener('submit', function(event) {
                event.preventDefault();

                localPaths = {};
                missing = [];
                localCount = 0;
                sourceCount = 0;
                submitButton.disabled = true;

                readLocal(0).catch(function(error) {
                    setStatus(error.message, 'error');
                    submitButton.disabled = false;
                });
            });
        })();
        </script>
        <?php
    }

    public function ajax_check_batch() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'site-sync-checker')), 403);
        }

        check_ajax_referer('ssc_ajax_check', 'nonce');

        $phase = isset($_POST['phase']) ? sanitize_text_field(wp_unslash($_POST['phase'])) : '';
        $limit = isset($_POST['limit']) ? absint($_POST['limit']) : $this->batch_size;
        $offset = isset($_POST['offset']) ? absint($_POST['offset']) : 0;

        if ($limit < 1 || $limit > 500) {
            $limit = $this->batch_size;
        }

        if ($phase === 'local') {
            $total = $this->count_site_pages();

            wp_send_json_success(array(
                'total' => $total,
                'offset' => $offset,
                'next_offset' => $offset + $limit,
                'has_more' => $total > ($offset + $limit),
                'pages' => $this->get_site_pages($limit, $offset),
            ));
        }

        if ($phase !== 'source') {
            wp_send_json_error(array('message' => __('Invalid AJAX phase.', 'site-sync-checker')), 400);
        }

        $source_url = isset($_POST['source_url']) ? esc_url_raw(trim(wp_unslash($_POST['source_url']))) : '';

        if ($source_url === '') {
            wp_send_json_error(array('message' => __('Please enter the original file URL first.', 'site-sync-checker')), 400);
        }

        if ($offset === 0) {
            $settings = $this->get_settings();
            $settings['source_url'] = $source_url;
            update_option($this->option_name, $settings);
        }

        $source_host = wp_parse_url($source_url, PHP_URL_HOST);
        $source_host = is_string($source_host) ? strtolower($source_host) : '';
        $is_local_source = in_array($source_host, array('localhost', '127.0.0.1', '::1'), true) || substr($source_host, -13) === '.localtest.me';
        $paged_url = add_query_arg(array(
            'limit' => $limit,
            'offset' => $offset,
        ), $source_url);
        $response = wp_remote_get($paged_url, array(
            'timeout' => 10,
            'redirection' => 3,
            'sslverify' => !$is_local_source,
            'headers' => array('Accept' => 'application/json'),
        ));

        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => $response->get_error_message()), 400);
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status_code < 200 || $status_code >= 300) {
            wp_send_json_error(array('message' => sprintf(__('Source URL returned HTTP status %d.', 'site-sync-checker'), $status_code)), 400);
        }

        $data = json_decode($body, true);

        if (!is_array($data)) {
            wp_send_json_error(array('message' => __('Source URL does not return valid JSON.', 'site-sync-checker')), 400);
        }

        $batch_pages = isset($data['pages']) && is_array($data['pages']) ? $data['pages'] : $data;
        $source_site_url = isset($data['site_url']) ? esc_url_raw($data['site_url']) : '';
        $pages = array();

        foreach ($batch_pages as $page) {
            if (!is_array($page)) {
                continue;
            }

            $path = '';

            if (!empty($page['path'])) {
                $path = $this->normalize_path($page['path']);
            } elseif (!empty($page['url'])) {
                $path = $this->normalize_path($page['url'], $source_site_url);
            } elseif (!empty($page['slug'])) {
                $path = $this->normalize_path($page['slug']);
            }

            if ($path === '') {
                continue;
            }

            $pages[] = array(
                'title' => isset($page['title']) ? wp_strip_all_tags((string) $page['title']) : '',
                'path' => $path,
                'url' => isset($page['url']) ? esc_url_raw($page['url']) : '',
            );
        }

        wp_send_json_success(array(
            'offset' => $offset,
            'next_offset' => $offset + $limit,
            'has_more' => isset($data['has_more']) ? (bool) $data['has_more'] : count($batch_pages) >= $limit,
            'pages' => $pages,
        ));
    }

    private function render_debug_panel() {
        global $wp_version;

        $last_fatal = get_option('ssc_last_fatal_error', array());
        $debug_items = array(
            'Plugin version' => '1.0.5',
            'WordPress version' => isset($wp_version) ? $wp_version : '',
            'PHP version' => PHP_VERSION,
            'Home URL' => home_url('/'),
            'Site URL' => site_url('/'),
            'REST feed URL' => function_exists('rest_url') ? rest_url($this->rest_namespace . '/pages') : home_url('/?rest_route=/' . $this->rest_namespace . '/pages'),
            'WP_DEBUG' => defined('WP_DEBUG') && WP_DEBUG ? 'true' : 'false',
            'WP_DEBUG_DISPLAY' => defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY ? 'true' : 'false',
            'WP_DEBUG_LOG' => defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ? 'true' : 'false',
        );
        ?>
        <h2><?php esc_html_e('Debug Information', 'site-sync-checker'); ?></h2>
        <table class="widefat striped" style="max-width:900px;">
            <tbody>
                <?php foreach ($debug_items as $label => $value) : ?>
                    <tr>
                        <th style="width:220px;"><?php echo esc_html($label); ?></th>
                        <td><code><?php echo esc_html((string) $value); ?></code></td>
                    </tr>
                <?php endforeach; ?>
                <tr>
                    <th><?php esc_html_e('Last fatal error', 'site-sync-checker'); ?></th>
                    <td>
                        <?php if (is_array($last_fatal) && !empty($last_fatal['message'])) : ?>
                            <p><strong><?php echo esc_html($last_fatal['message']); ?></strong></p>
                            <p><code><?php echo esc_html($last_fatal['file'] . ':' . $last_fatal['line']); ?></code></p>
                            <p><?php echo esc_html($last_fatal['time']); ?></p>
                        <?php else : ?>
                            <?php esc_html_e('No fatal error captured by this plugin yet.', 'site-sync-checker'); ?>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    private function get_settings() {
        $settings = get_option($this->option_name, array());

        if (!is_array($settings)) {
            $settings = array();
        }

        if (!isset($settings['source_url'])) {
            $settings['source_url'] = '';
        }

        return $settings;
    }

    private function get_site_pages($limit = -1, $offset = 0) {
        global $wpdb;

        $limit = (int) $limit;
        $offset = (int) $offset;

        if ($limit < 1) {
            $limit = $this->batch_size;
        }

        $pages = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_title, post_name, post_modified_gmt FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s ORDER BY menu_order ASC, post_title ASC LIMIT %d OFFSET %d",
            'page',
            'publish',
            $limit,
            $offset
        ));
        $items = array();

        foreach ($pages as $page) {
            $path = $this->normalize_path(get_page_uri((int) $page->ID));
            $url = home_url($path === '' ? '/' : '/' . $path . '/');
            $items[] = array(
                'id' => (int) $page->ID,
                'title' => wp_strip_all_tags($page->post_title),
                'slug' => $page->post_name,
                'path' => $path,
                'url' => $url,
                'modified' => $page->post_modified_gmt,
            );
        }

        return $items;
    }

    private function count_site_pages() {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s",
            'page',
            'publish'
        ));
    }

    private function normalize_path($value, $base_url = '') {
        $value = trim((string) $value);
        $path = wp_parse_url($value, PHP_URL_PATH);

        if ($path === null || $path === false) {
            $path = $value;
        }

        $path = trim(rawurldecode($path), "/ \t\n\r\0\x0B");

        if ($base_url !== '') {
            $base_path = wp_parse_url($base_url, PHP_URL_PATH);
            $base_path = trim((string) $base_path, "/ \t\n\r\0\x0B");

            if ($base_path !== '' && ($path === $base_path || strpos($path, $base_path . '/') === 0)) {
                $path = ltrim(substr($path, strlen($base_path)), '/');
            }
        }

        return strtolower($path);
    }

}

new CI_Site_Sync_Checker_Plugin();
