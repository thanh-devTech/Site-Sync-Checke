<?php
/**
 * Plugin Name: Site Sync Checker
 * Description: Compare pages between a source site feed and the current site to find missing pages.
 * Version: 1.0.0
 * Author: CI Data Works
 * Text Domain: site-sync-checker
 */

if (!defined('ABSPATH')) {
    exit;
}

class Site_Sync_Checker {
    private $option_name = 'ssc_settings';
    private $rest_namespace = 'site-sync-checker/v1';

    public function __construct() {
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
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
        $settings = $this->get_settings();

        if (isset($input['source_url'])) {
            $settings['source_url'] = esc_url_raw(trim(wp_unslash($input['source_url'])));
        }

        if (!empty($input['regenerate_key'])) {
            $settings['feed_key'] = wp_generate_password(32, false, false);
        }

        if (empty($settings['feed_key'])) {
            $settings['feed_key'] = wp_generate_password(32, false, false);
        }

        return $settings;
    }

    public function register_rest_routes() {
        register_rest_route($this->rest_namespace, '/pages', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'rest_pages_feed'),
            'permission_callback' => '__return_true',
        ));
    }

    public function rest_pages_feed(WP_REST_Request $request) {
        $settings = $this->get_settings();
        $key = sanitize_text_field((string) $request->get_param('key'));

        if (!empty($settings['feed_key']) && !hash_equals($settings['feed_key'], $key)) {
            return new WP_Error('ssc_invalid_key', __('Invalid feed key.', 'site-sync-checker'), array('status' => 403));
        }

        return rest_ensure_response(array(
            'site_url' => home_url('/'),
            'generated_at' => current_time('mysql'),
            'pages' => $this->get_site_pages(),
        ));
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = $this->get_settings();
        $source_url = isset($settings['source_url']) ? $settings['source_url'] : '';
        $feed_url = add_query_arg('key', rawurlencode($settings['feed_key']), rest_url($this->rest_namespace . '/pages'));
        $check_result = null;

        if (isset($_POST['ssc_check_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ssc_check_nonce'])), 'ssc_check_now')) {
            $posted_source_url = isset($_POST['ssc_source_url']) ? esc_url_raw(trim(wp_unslash($_POST['ssc_source_url']))) : $source_url;
            $source_url = $posted_source_url;
            $settings['source_url'] = $source_url;
            update_option($this->option_name, $settings);
            $check_result = $this->check_missing_pages($source_url);
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
                            <input type="url" id="ssc_source_url" name="<?php echo esc_attr($this->option_name); ?>[source_url]" class="large-text" value="<?php echo esc_attr($source_url); ?>" placeholder="https://example.com/wp-json/site-sync-checker/v1/pages?key=..." />
                            <p class="description"><?php esc_html_e('Paste the source site feed URL or a compatible JSON file URL.', 'site-sync-checker'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Feed Key', 'site-sync-checker'); ?></th>
                        <td>
                            <code><?php echo esc_html($settings['feed_key']); ?></code>
                            <label style="display:block;margin-top:8px;">
                                <input type="checkbox" name="<?php echo esc_attr($this->option_name); ?>[regenerate_key]" value="1" />
                                <?php esc_html_e('Regenerate key after saving', 'site-sync-checker'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Save Settings', 'site-sync-checker')); ?>
            </form>

            <hr />

            <form method="post">
                <?php wp_nonce_field('ssc_check_now', 'ssc_check_nonce'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="ssc_check_source_url"><?php esc_html_e('Original File URL', 'site-sync-checker'); ?></label></th>
                        <td>
                            <input type="url" id="ssc_check_source_url" name="ssc_source_url" class="large-text" value="<?php echo esc_attr($source_url); ?>" placeholder="https://example.com/wp-json/site-sync-checker/v1/pages?key=..." />
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Check Missing Pages', 'site-sync-checker'), 'primary', 'ssc_check_now', false); ?>
            </form>

            <?php if (is_array($check_result)) : ?>
                <h2><?php esc_html_e('Check Result', 'site-sync-checker'); ?></h2>
                <?php if (!empty($check_result['error'])) : ?>
                    <div class="notice notice-error"><p><?php echo esc_html($check_result['error']); ?></p></div>
                <?php else : ?>
                    <p>
                        <?php
                        printf(
                            esc_html__('Source pages: %1$d. Current site pages: %2$d. Missing pages: %3$d.', 'site-sync-checker'),
                            (int) $check_result['source_count'],
                            (int) $check_result['local_count'],
                            count($check_result['missing'])
                        );
                        ?>
                    </p>
                    <?php if (empty($check_result['missing'])) : ?>
                        <div class="notice notice-success"><p><?php esc_html_e('No missing pages found.', 'site-sync-checker'); ?></p></div>
                    <?php else : ?>
                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Title', 'site-sync-checker'); ?></th>
                                    <th><?php esc_html_e('Path', 'site-sync-checker'); ?></th>
                                    <th><?php esc_html_e('Source URL', 'site-sync-checker'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($check_result['missing'] as $page) : ?>
                                    <tr>
                                        <td><?php echo esc_html($page['title']); ?></td>
                                        <td><code><?php echo esc_html($page['path']); ?></code></td>
                                        <td>
                                            <?php if (!empty($page['url'])) : ?>
                                                <a href="<?php echo esc_url($page['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($page['url']); ?></a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function get_settings() {
        $settings = get_option($this->option_name, array());

        if (!is_array($settings)) {
            $settings = array();
        }

        if (empty($settings['feed_key'])) {
            $settings['feed_key'] = wp_generate_password(32, false, false);
            update_option($this->option_name, $settings);
        }

        if (!isset($settings['source_url'])) {
            $settings['source_url'] = '';
        }

        return $settings;
    }

    private function get_site_pages() {
        $pages = get_posts(array(
            'post_type' => 'page',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'menu_order title',
            'order' => 'ASC',
            'no_found_rows' => true,
        ));
        $items = array();

        foreach ($pages as $page) {
            $url = get_permalink($page);
            $items[] = array(
                'id' => (int) $page->ID,
                'title' => get_the_title($page),
                'slug' => $page->post_name,
                'path' => $this->normalize_path($url, home_url('/')),
                'url' => $url,
                'modified' => get_post_modified_time('c', false, $page),
            );
        }

        return $items;
    }

    private function check_missing_pages($source_url) {
        if (empty($source_url)) {
            return array('error' => __('Please enter and save the original file URL first.', 'site-sync-checker'));
        }

        $source_host = wp_parse_url($source_url, PHP_URL_HOST);
        $source_host = is_string($source_host) ? strtolower($source_host) : '';
        $is_local_source = in_array($source_host, array('localhost', '127.0.0.1', '::1'), true) || substr($source_host, -13) === '.localtest.me';

        $response = wp_remote_get($source_url, array(
            'timeout' => 20,
            'redirection' => 5,
            'sslverify' => !$is_local_source,
            'headers' => array('Accept' => 'application/json'),
        ));

        if (is_wp_error($response)) {
            return array('error' => $response->get_error_message());
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status_code < 200 || $status_code >= 300) {
            return array('error' => sprintf(__('Source URL returned HTTP status %d.', 'site-sync-checker'), $status_code));
        }

        $data = json_decode($body, true);

        if (!is_array($data)) {
            return array('error' => __('Source URL does not return valid JSON.', 'site-sync-checker'));
        }

        $source_pages = isset($data['pages']) && is_array($data['pages']) ? $data['pages'] : $data;
        $source_site_url = isset($data['site_url']) ? esc_url_raw($data['site_url']) : '';
        $local_pages = $this->get_site_pages();
        $local_paths = array();

        foreach ($local_pages as $page) {
            $local_paths[$page['path']] = true;
        }

        $missing = array();

        foreach ($source_pages as $page) {
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

            if ($path !== '' && empty($local_paths[$path])) {
                $missing[] = array(
                    'title' => isset($page['title']) ? wp_strip_all_tags((string) $page['title']) : '',
                    'path' => $path,
                    'url' => isset($page['url']) ? esc_url_raw($page['url']) : '',
                );
            }
        }

        return array(
            'source_count' => count($source_pages),
            'local_count' => count($local_pages),
            'missing' => $missing,
        );
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

new Site_Sync_Checker();
