<?php
/**
 * Plugin Name: Plugin Reporter
 * Description: Sends plugin information to mijn.kobaltdigital.nl once a day and via a secure REST endpoint.
 * Version: 1.2.0
 * Author: Arne van Hoorn
 */

if (!defined('ABSPATH')) {
    exit;
}

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

// Register activation and deactivation hooks before class instantiation
register_activation_hook(__FILE__, 'plugin_reporter_activate');
register_deactivation_hook(__FILE__, 'plugin_reporter_deactivate');

function plugin_reporter_activate() {
    if (!wp_next_scheduled('plugin_reporter_send')) {
        wp_schedule_event(time(), 'daily', 'plugin_reporter_send');
    }
}

function plugin_reporter_deactivate() {
    wp_clear_scheduled_hook('plugin_reporter_send');
}

class PluginReporter
{
    private $default_endpoint = 'https://plugin-reporter.kobaltdigital.nl/api/data';

    /**
     * Base64 Ed25519 public key of the app, used to verify signed commands.
     * Output of `php artisan reporter:generate-signing-key --show-public`.
     * Overridable with PLUGIN_REPORTER_COMMAND_PUBLIC_KEY in wp-config.php.
     */
    const COMMAND_PUBLIC_KEY = '';

    const SIGNATURE_VERSION = 'plugin-reporter-v1';

    const SIGNATURE_MAX_AGE = 300;

    const NONCE_TTL = 600;

    public function __construct()
    {
        add_action('plugin_reporter_send', [$this, 'sendPluginInformation']);

        // Admin settings page
        add_action('admin_menu', [$this, 'addAdminMenu']);
        add_action('admin_menu', [$this, 'maybeHideAdminMenus'], 999);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_init', [$this, 'handleTestPost']);
        add_action('admin_init', [$this, 'handleDownloadPlugins']);
        add_action('admin_init', [$this, 'maybeBlockAdminPages']);
        add_action('admin_init', [$this, 'registerColorSchemes']);
        add_filter('get_user_option_admin_color', [$this, 'enforceAdminColorScheme']);
        add_action('login_enqueue_scripts', [$this, 'enqueueLoginStyles']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'addSettingsLink']);
        add_filter('acf/settings/show_admin', [$this, 'maybeHideAcfAdmin']);

        // Secure REST endpoint
        add_action('rest_api_init', function () {
            $permission = function ($req) {
                $key = $req->get_header('X-Reporter-Key');
                return $key && hash_equals($this->getSecret(), $key);
            };

            register_rest_route('plugin-reporter/v1', '/send', [
                'methods'  => 'POST',
                'callback' => [$this, 'sendPluginInformation'],
                'permission_callback' => $permission,
            ]);

            register_rest_route('plugin-reporter/v1', '/status', [
                'methods'  => 'GET',
                'callback' => [$this, 'statusCheck'],
                'permission_callback' => $permission,
            ]);

            register_rest_route('plugin-reporter/v1', '/update-plugin', [
                'methods'  => 'POST',
                'callback' => [$this, 'updatePlugin'],
                'permission_callback' => [$this, 'verifySignedCommand'],
            ]);
        });
    }

    public function schedule()
    {
        if (!wp_next_scheduled('plugin_reporter_send')) {
            wp_schedule_event(time(), 'daily', 'plugin_reporter_send');
        }
    }

    public function unschedule()
    {
        wp_clear_scheduled_hook('plugin_reporter_send');
    }

    private function getEndpoint()
    {
        $endpoint = get_option('plugin_reporter_endpoint', '');
        return !empty($endpoint) ? $endpoint : $this->default_endpoint;
    }

    private function getSecret()
    {
        return get_option('plugin_reporter_secret', '');
    }

    public function registerSettings()
    {
        register_setting('plugin_reporter_settings', 'plugin_reporter_endpoint', [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => $this->default_endpoint,
        ]);
        register_setting('plugin_reporter_settings', 'plugin_reporter_secret', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('plugin_reporter_settings', 'plugin_reporter_allowed_domains', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default' => 'kobaltdigital.nl,alkmaarsch.nl',
        ]);
        register_setting('plugin_reporter_settings', 'plugin_reporter_hide_plugins', [
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 0,
        ]);
        register_setting('plugin_reporter_settings', 'plugin_reporter_hide_acf', [
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 0,
        ]);
        register_setting('plugin_reporter_settings', 'plugin_reporter_theme', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'default',
        ]);
    }

    public function addAdminMenu()
    {
        add_options_page(
            'Plugin Reporter Settings',
            'Plugin Reporter',
            'manage_options',
            'plugin-reporter',
            [$this, 'renderSettingsPage']
        );
    }

    public function addSettingsLink($links)
    {
        $settings_link = '<a href="' . admin_url('options-general.php?page=plugin-reporter') . '">' . __('Settings') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    private function collectPluginPayload()
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $plugins = get_plugins();
        $active  = get_option('active_plugins', []);
        $updates = get_site_transient('update_plugins');
        $auto_update_plugins = (array) get_site_option('auto_update_plugins', []);

        $data = [];

        foreach ($plugins as $plugin_file => $plugin_data) {
            $slug = dirname($plugin_file);

            $data[] = [
                'slug' => $slug,
                'title' => $plugin_data['Name'],
                'version' => $plugin_data['Version'],
                'status' => in_array($plugin_file, $active) ? 'active' : 'inactive',
                'auto_update' => in_array($plugin_file, $auto_update_plugins) ? 1 : 0,
                'update' => isset($updates->response[$plugin_file])
                    ? $updates->response[$plugin_file]->new_version
                    : false,
            ];
        }

        return [
            'site_url' => site_url(),
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'plugins' => $data,
            'themes' => $this->collectThemeData(),
            'wordfence' => $this->collectWordfenceData($plugins, $active),
            'kobalt_admins' => $this->collectKobaltAdmins(),
        ];
    }

    private function collectThemeData(): array
    {
        $active_stylesheet = get_stylesheet();
        $updates = get_site_transient('update_themes');

        $themes = [];
        foreach (wp_get_themes() as $stylesheet => $theme) {
            $themes[] = [
                'slug' => $stylesheet,
                'name' => $theme->get('Name'),
                'version' => $theme->get('Version'),
                'active' => $stylesheet === $active_stylesheet ? 1 : 0,
                'update' => isset($updates->response[$stylesheet]['new_version'])
                    ? $updates->response[$stylesheet]['new_version']
                    : false,
            ];
        }

        return $themes;
    }

    private function collectKobaltAdmins(): array
    {
        $users = get_users([
            'role' => 'administrator',
            'fields' => ['user_login', 'user_email'],
        ]);

        $admins = [];
        foreach ($users as $user) {
            if (!preg_match('/@kobaltdigital\.nl$/i', $user->user_email)) {
                continue;
            }
            $admins[] = [
                'username' => $user->user_login,
                'email' => $user->user_email,
            ];
        }

        return $admins;
    }

    private function collectWordfenceData(array $plugins, array $active): ?array
    {
        global $wpdb;

        $wfls_file = null;
        foreach (array_keys($plugins) as $plugin_file) {
            $slug = dirname($plugin_file);
            if ($slug === 'wordfence-login-security' || $slug === 'wordfence') {
                $wfls_file = $plugin_file;
                if ($slug === 'wordfence-login-security') {
                    break;
                }
            }
        }

        if ($wfls_file === null) {
            return null;
        }

        $is_active = in_array($wfls_file, $active, true);

        $settings = $this->collectWfConfigSettings();

        $table = $wpdb->prefix . 'wfls_2fa_secrets';
        $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;

        if (!$table_exists) {
            return [
                'installed' => true,
                'active' => $is_active,
                'table_exists' => false,
                'settings' => $settings,
            ];
        }

        $user_ids_with_2fa = array_map('intval', $wpdb->get_col("SELECT user_id FROM $table"));

        $users = get_users([
            'capability' => 'edit_posts',
            'fields' => ['ID'],
        ]);

        $without_2fa_by_role = [];
        foreach ($users as $user) {
            if (in_array((int) $user->ID, $user_ids_with_2fa, true)) {
                continue;
            }
            $wp_user = get_userdata($user->ID);
            $roles = $wp_user ? (array) $wp_user->roles : ['none'];
            if (empty($roles)) {
                $roles = ['none'];
            }
            foreach ($roles as $role) {
                $without_2fa_by_role[$role] = ($without_2fa_by_role[$role] ?? 0) + 1;
            }
        }

        return [
            'installed' => true,
            'active' => $is_active,
            'table_exists' => true,
            'eligible_user_count' => count($users),
            'users_without_2fa_by_role' => $without_2fa_by_role,
            'settings' => $settings,
        ];
    }

    private function collectWfConfigSettings(): ?array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'wfconfig';
        $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;

        if (!$table_exists) {
            return null;
        }

        // Flag fields default to 0 when the row is missing or the value is empty.
        $flag_fields = [
            'alertOn_severityLevel',
            'email_summary_enabled',
            'alertOn_wafDeactivated',
            'alertOn_wordfenceDeactivated',
            'wafAlertOnAttacks',
        ];
        $names = array_merge(['alertEmails'], $flag_fields);
        $placeholders = implode(',', array_fill(0, count($names), '%s'));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT name, val FROM $table WHERE name IN ($placeholders)",
                $names
            ),
            ARRAY_A
        );

        $vals = [];
        foreach ($rows as $row) {
            $vals[$row['name']] = $row['val'];
        }

        $settings = [];

        // alertEmails: raw string when present, key omitted otherwise.
        if (isset($vals['alertEmails'])) {
            $settings['alertEmails'] = $vals['alertEmails'];
        }

        // Flag fields: always present, 0 when missing or empty.
        foreach ($flag_fields as $field) {
            $val = $vals[$field] ?? '';
            $settings[$field] = ($val === '' || $val === null) ? 0 : $val;
        }

        return $settings;
    }

    public function handleTestPost()
    {
        if (!isset($_POST['plugin_reporter_test']) || !check_admin_referer('plugin_reporter_test', 'plugin_reporter_test_nonce')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $payload = $this->collectPluginPayload();
        $data = $payload['plugins'];

        $response = wp_remote_post($this->getEndpoint(), [
            'method'  => 'POST',
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $this->getSecret(),
            ],
            'body' => wp_json_encode($payload),
            'timeout' => 20,
            'sslverify' => true,
        ]);

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $error = is_wp_error($response) ? $response->get_error_message() : null;

        $message = '';
        $type = 'error';

        if ($error) {
            $message = 'Test failed: ' . $error;
        } elseif ($status_code >= 200 && $status_code < 300) {
            $message = 'Test successful! Response code: ' . $status_code . '. Sent ' . count($data) . ' plugins.';
            $type = 'success';
        } else {
            $message = 'Test failed with status code: ' . $status_code . '. Response: ' . substr($body, 0, 200);
        }

        // Store message in transient for display after redirect
        set_transient('plugin_reporter_test_message', [
            'message' => $message,
            'type' => $type
        ], 30);

        // Redirect to prevent duplicate processing
        wp_safe_redirect(add_query_arg('test-complete', 'true', admin_url('options-general.php?page=plugin-reporter')));
        exit;
    }

    public function handleDownloadPlugins()
    {
        if (!isset($_POST['plugin_reporter_download']) || !check_admin_referer('plugin_reporter_download', 'plugin_reporter_download_nonce')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        if (!class_exists('ZipArchive')) {
            $this->redirectWithDownloadError('The PHP ZipArchive extension is not available on this server.');
        }

        @set_time_limit(0);

        $plugin_dir = untrailingslashit(WP_PLUGIN_DIR);
        $filename = sanitize_file_name(parse_url(home_url(), PHP_URL_HOST) . '-plugins-' . gmdate('Ymd-His') . '.zip');
        $zip_path = trailingslashit(get_temp_dir()) . $filename;

        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->redirectWithDownloadError('Could not create a zip file in ' . get_temp_dir());
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($plugin_dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relative = ltrim(substr($item->getPathname(), strlen($plugin_dir)), '/\\');
            $relative = str_replace('\\', '/', $relative);

            if ($item->isDir()) {
                $zip->addEmptyDir($relative);
                continue;
            }

            $zip->addFile($item->getPathname(), $relative);
        }

        $zip->close();

        if (!file_exists($zip_path)) {
            $this->redirectWithDownloadError('Zip file was not written.');
        }

        // Make sure the temp file is removed even if the client aborts mid-download.
        register_shutdown_function(function () use ($zip_path) {
            if (file_exists($zip_path)) {
                @unlink($zip_path);
            }
        });

        while (ob_get_level()) {
            ob_end_clean();
        }

        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($zip_path));

        readfile($zip_path);
        @unlink($zip_path);
        exit;
    }

    private function redirectWithDownloadError(string $message): void
    {
        set_transient('plugin_reporter_test_message', [
            'message' => 'Download failed: ' . $message,
            'type' => 'error',
        ], 30);

        wp_safe_redirect(admin_url('options-general.php?page=plugin-reporter'));
        exit;
    }

    public function renderSettingsPage()
    {
        // Display test message if available
        $test_message = get_transient('plugin_reporter_test_message');
        if ($test_message) {
            delete_transient('plugin_reporter_test_message');
            $notice_class = $test_message['type'] === 'success' ? 'notice-success' : 'notice-error';
            ?>
            <div class="notice <?php echo esc_attr($notice_class); ?> is-dismissible">
                <p><?php echo esc_html($test_message['message']); ?></p>
            </div>
            <?php
        }

        settings_errors('plugin_reporter_test');
        ?>
        <div class="wrap">
            <h1>Plugin Reporter Settings</h1>

            <form method="post" action="options.php">
                <?php settings_fields('plugin_reporter_settings'); ?>
                <?php do_settings_sections('plugin_reporter_settings'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="plugin_reporter_endpoint">Endpoint URL</label>
                        </th>
                        <td>
                            <input type="url"
                                    id="plugin_reporter_endpoint"
                                    name="plugin_reporter_endpoint"
                                    value="<?php echo esc_attr(
                                        get_option('plugin_reporter_endpoint', $this->default_endpoint)
                                    ); ?>"
                                    class="regular-text"
                                    placeholder="<?php echo esc_attr($this->default_endpoint); ?>"
                                    style="width: 100%;" />
                            <p class="description">
                                The API endpoint where plugin information will be sent.
                                Leave empty to use the default.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="plugin_reporter_secret">Secret Key <span style="color: red;">*</span></label>
                        </th>
                        <td>
                            <input type="password"
                                   id="plugin_reporter_secret"
                                   name="plugin_reporter_secret"
                                   value="<?php echo esc_attr(get_option('plugin_reporter_secret', '')); ?>"
                                   class="regular-text"
                                   required />
                            <p class="description">The secret key used for authentication. This field is required.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="plugin_reporter_theme">Backend Theme</label>
                        </th>
                        <td>
                            <select id="plugin_reporter_theme" name="plugin_reporter_theme">
                                <option value="default" <?php selected('default', get_option('plugin_reporter_theme', 'default')); ?>>Default</option>
                                <option value="kobalt"  <?php selected('kobalt',  get_option('plugin_reporter_theme', 'default')); ?>>Kobalt</option>
                                <option value="alkmaarsch" <?php selected('alkmaarsch', get_option('plugin_reporter_theme', 'default')); ?>>Alkmaarsch</option>
                            </select>
                            <p class="description">Applies the selected color scheme to all admin users site-wide.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row" colspan="2">
                            <h2 style="margin: 0;">Access Control</h2>
                        </th>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="plugin_reporter_allowed_domains">Allowed Email Domains</label>
                        </th>
                        <td>
                            <input type="text"
                                   id="plugin_reporter_allowed_domains"
                                   name="plugin_reporter_allowed_domains"
                                   value="<?php echo esc_attr(get_option('plugin_reporter_allowed_domains', 'kobaltdigital.nl,alkmaarsch.nl')); ?>"
                                   class="regular-text"
                                   style="width: 100%;" />
                            <p class="description">
                                Comma-separated domain names without @,
                                e.g. <code>kobaltdigital.nl,alkmaarsch.nl</code>.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Hide Plugins menu</th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       name="plugin_reporter_hide_plugins"
                                       value="1"
                                       <?php checked(1, get_option('plugin_reporter_hide_plugins', 0)); ?> />
                                Hide the Plugins menu for users outside the allowed domains.
                            </label>
                        </td>
                    </tr>
                    <?php if (class_exists('ACF') || function_exists('acf_get_settings')) : ?>
                    <tr>
                        <th scope="row">Hide ACF Field Groups menu</th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       name="plugin_reporter_hide_acf"
                                       value="1"
                                       <?php checked(1, get_option('plugin_reporter_hide_acf', 0)); ?> />
                                Hide ACF Field Groups menu for users outside the allowed domains.
                            </label>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>

                <?php submit_button('Save Settings'); ?>
            </form>

            <div class="card" style="margin-top: 20px;">
                <h2>Test Connection</h2>
                <p>Click the button below to send a test POST request to the external endpoint.</p>

                <form method="post" action="">
                    <?php wp_nonce_field('plugin_reporter_test', 'plugin_reporter_test_nonce'); ?>
                    <p>
                        <input
                            type="submit"
                            name="plugin_reporter_test"
                            class="button button-primary"
                            value="Run Test Post" />
                    </p>
                </form>
            </div>

            <div class="card" style="margin-top: 20px;">
                <h2>Download All Plugins</h2>
                <p>Creates a zip archive of the entire <code>wp-content/plugins</code> directory, downloads it, and removes the archive from the server afterwards.</p>

                <form method="post" action="">
                    <?php wp_nonce_field('plugin_reporter_download', 'plugin_reporter_download_nonce'); ?>
                    <p>
                        <input
                            type="submit"
                            name="plugin_reporter_download"
                            class="button button-secondary"
                            value="Download All Plugins" />
                    </p>
                </form>
            </div>

            <div class="card" style="margin-top: 20px;">
                <h2>Information</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Current Endpoint</th>
                        <td><code><?php echo esc_html($this->getEndpoint()); ?></code></td>
                    </tr>
                    <tr>
                        <th scope="row">Status check URL</th>
                        <td>
                            <code><?php echo esc_html(rest_url('plugin-reporter/v1/status')); ?></code>
                            <p class="description">
                                Call this URL with GET and <code>X-Reporter-Key</code> header to verify the plugin is active.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Next Scheduled Run</th>
                        <td>
                            <?php
                            $next_run = wp_next_scheduled('plugin_reporter_send');
                            if ($next_run) {
                                echo esc_html(date_i18n(
                                    get_option('date_format') . ' ' . get_option('time_format'),
                                    $next_run
                                ));
                            } else {
                                echo '<em>Not scheduled</em>';
                            }
                            ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        <?php
    }

    private function isCurrentUserAllowed(): bool
    {
        $user = wp_get_current_user();
        if (empty($user->user_email)) {
            return false;
        }
        $raw     = get_option('plugin_reporter_allowed_domains', 'kobaltdigital.nl,alkmaarsch.nl');
        $domains = array_filter(array_map('trim', explode(',', $raw)));
        foreach ($domains as $domain) {
            $match = '@' . ltrim($domain, '@');
            if (substr($user->user_email, -strlen($match)) === $match) {
                return true;
            }
        }
        return false;
    }

    public function maybeBlockAdminPages(): void
    {
        if ($this->isCurrentUserAllowed()) {
            return;
        }

        global $pagenow;

        if (get_option('plugin_reporter_hide_plugins', 0) && $pagenow === 'plugins.php') {
            wp_safe_redirect(admin_url());
            exit;
        }

        if (get_option('plugin_reporter_hide_acf', 0)) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $post_type = isset($_GET['post_type']) ? sanitize_key($_GET['post_type']) : '';
            if (
                ($pagenow === 'edit.php' || $pagenow === 'post-new.php') &&
                $post_type === 'acf-field-group'
            ) {
                wp_safe_redirect(admin_url());
                exit;
            }
        }
    }

    public function maybeHideAdminMenus(): void
    {
        if (get_option('plugin_reporter_hide_plugins', 0) && !$this->isCurrentUserAllowed()) {
            remove_menu_page('plugins.php');
        }
    }

    public function maybeHideAcfAdmin(bool $show): bool
    {
        if (get_option('plugin_reporter_hide_acf', 0) && !$this->isCurrentUserAllowed()) {
            return false;
        }
        return $show;
    }

    public function enqueueLoginStyles(): void
    {
        $theme = get_option('plugin_reporter_theme', 'default');
        if ($theme === 'default') {
            return;
        }
        $css_file = $theme . '-login.css';
        wp_enqueue_style(
            'plugin-reporter-login-' . $theme,
            plugin_dir_url(__FILE__) . 'assets/css/' . $css_file,
            [],
            '1.0'
        );
    }

    public function registerColorSchemes(): void
    {
        wp_admin_css_color(
            'kobalt',
            __('Kobalt'),
            plugin_dir_url(__FILE__) . 'assets/css/kobalt-color-scheme.css',
            ['#0100FF', '#051432', '#030b1b', '#f24725']
        );
        wp_admin_css_color(
            'alkmaarsch',
            __('Alkmaarsch'),
            plugin_dir_url(__FILE__) . 'assets/css/alkmaarsch-color-scheme.css',
            ['#1C1C1C', '#2E2E2E', '#111111', '#0100FF']
        );
    }

    public function enforceAdminColorScheme(string $color): string
    {
        $theme = get_option('plugin_reporter_theme', 'default');
        if ($theme !== 'default') {
            return $theme;
        }
        return $color;
    }

    public function sendPluginInformation()
    {
        $payload = $this->collectPluginPayload();
        $data = $payload['plugins'];

        // Send to external endpoint
        wp_remote_post($this->getEndpoint(), [
            'method'  => 'POST',
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->getSecret(),
            ],
            'body'    => wp_json_encode($payload),
            'timeout' => 20,
            'sslverify' => true,
        ]);

        return [
            'status'  => 'ok',
            'sent_at' => current_time('mysql'),
            'plugin_count' => count($data)
        ];
    }

    /**
     * REST callback for /status: lets the external endpoint verify this plugin is still active.
     */
    public function statusCheck()
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $plugin_data = get_plugin_data(__FILE__, false, false);
        return [
            'active'  => true,
            'plugin'  => 'plugin-reporter',
            'name'    => $plugin_data['Name'] ?? 'Plugin Reporter',
            'version' => $plugin_data['Version'] ?? '',
            'site_url' => site_url(),
            'checked_at' => current_time('mysql'),
        ];
    }

    /**
     * Permission callback for commands that change the site. Requires the site key, an
     * Ed25519 signature from the app and an unused nonce. Never reveals which check failed.
     */
    public function verifySignedCommand(WP_REST_Request $request)
    {
        $denied = new WP_Error('rest_forbidden', 'Forbidden.', ['status' => 403]);

        if (!$this->isRemoteAddrAllowed()) {
            return $denied;
        }

        $key = (string) $request->get_header('X-Reporter-Key');
        $secret = $this->getSecret();
        if ($key === '' || $secret === '' || !hash_equals($secret, $key)) {
            return $denied;
        }

        $publicKey = $this->getCommandPublicKey();
        if ($publicKey === null) {
            return $denied;
        }

        $timestamp = (string) $request->get_header('X-Reporter-Timestamp');
        $nonce = (string) $request->get_header('X-Reporter-Nonce');
        $signature = base64_decode((string) $request->get_header('X-Reporter-Signature'), true);

        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::SIGNATURE_MAX_AGE) {
            return $denied;
        }

        if (!preg_match('/^[A-Za-z0-9]{32}$/', $nonce) || $signature === false) {
            return $denied;
        }

        $message = implode("\n", [
            self::SIGNATURE_VERSION,
            strtoupper($request->get_method()),
            $request->get_route(),
            $timestamp,
            $nonce,
            hash('sha256', $key),
            hash('sha256', $request->get_body()),
        ]);

        try {
            $valid = sodium_crypto_sign_verify_detached($signature, $message, $publicKey);
        } catch (Throwable $e) {
            $valid = false;
        }

        if (!$valid) {
            return $denied;
        }

        $nonceKey = 'plugin_reporter_nonce_' . $nonce;
        if (get_transient($nonceKey) !== false) {
            return $denied;
        }
        set_transient($nonceKey, 1, self::NONCE_TTL);

        return true;
    }

    /**
     * Decoded app public key, or null when none is configured or it is invalid.
     */
    private function getCommandPublicKey(): ?string
    {
        $encoded = defined('PLUGIN_REPORTER_COMMAND_PUBLIC_KEY')
            ? (string) PLUGIN_REPORTER_COMMAND_PUBLIC_KEY
            : self::COMMAND_PUBLIC_KEY;

        if ($encoded === '' || !function_exists('sodium_crypto_sign_verify_detached')) {
            return null;
        }

        $decoded = base64_decode($encoded, true);

        return $decoded !== false && strlen($decoded) === 32 ? $decoded : null;
    }

    /**
     * Optional IP allowlist via PLUGIN_REPORTER_ALLOWED_IPS. Only REMOTE_ADDR is trusted,
     * forwarded headers can be spoofed.
     */
    private function isRemoteAddrAllowed(): bool
    {
        if (!defined('PLUGIN_REPORTER_ALLOWED_IPS')) {
            return true;
        }

        $allowed = array_filter(array_map('trim', explode(',', (string) PLUGIN_REPORTER_ALLOWED_IPS)));
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';

        return $remote !== '' && in_array($remote, $allowed, true);
    }

    /**
     * REST callback for /update-plugin: updates an installed plugin to the version offered
     * in the update_plugins transient.
     */
    public function updatePlugin(WP_REST_Request $request)
    {
        @set_time_limit(300);
        ignore_user_abort(true);

        $params = $request->get_json_params();
        $slug = is_array($params) ? ($params['plugin'] ?? null) : null;

        if (!is_string($slug) || !preg_match('/^[a-z0-9][a-z0-9._-]*$/', $slug)) {
            return new WP_Error('invalid_plugin', 'Invalid plugin slug.', ['status' => 400]);
        }

        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        $plugins = get_plugins();
        $file = null;
        foreach (array_keys($plugins) as $plugin_file) {
            if (dirname($plugin_file) === $slug) {
                $file = $plugin_file;
                break;
            }
        }

        if ($file === null) {
            return new WP_Error('plugin_not_installed', 'Plugin is not installed.', ['status' => 404]);
        }

        $old = $plugins[$file]['Version'];
        $wasActive = is_plugin_active($file);

        wp_clean_plugins_cache();
        wp_update_plugins();
        $updates = get_site_transient('update_plugins');
        $update = $updates->response[$file] ?? null;

        if (!$update) {
            return [
                'updated' => false,
                'plugin' => $slug,
                'old_version' => $old,
                'new_version' => $old,
                'message' => 'No update available.',
            ];
        }

        if (get_filesystem_method() !== 'direct') {
            return new WP_Error('filesystem_not_direct', 'Filesystem access is not direct, updates need credentials.', ['status' => 500]);
        }

        $skin = new WP_Ajax_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);
        $result = $upgrader->bulk_upgrade([$file]);

        $failed = !$result
            || is_wp_error($result)
            || empty($result[$file])
            || is_wp_error($result[$file])
            || $skin->get_errors()->has_errors();

        if ($failed) {
            $message = $skin->get_error_messages();
            if ($message === '' && is_wp_error($result)) {
                $message = $result->get_error_message();
            } elseif ($message === '' && is_array($result) && is_wp_error($result[$file] ?? null)) {
                $message = $result[$file]->get_error_message();
            }

            return new WP_Error('update_failed', $message !== '' ? $message : 'Plugin update failed.', ['status' => 500]);
        }

        if ($wasActive && !is_plugin_active($file)) {
            activate_plugin($file, '', is_plugin_active_for_network($file), true);
        }

        wp_clean_plugins_cache();
        $new = get_plugin_data(WP_PLUGIN_DIR . '/' . $file, false, false)['Version'];

        // The upgrader cleared the update_plugins transient; refill it so the report
        // does not show every other plugin as up to date.
        wp_update_plugins();
        $this->sendPluginInformation();

        return [
            'updated' => true,
            'plugin' => $slug,
            'old_version' => $old,
            'new_version' => $new,
        ];
    }
}

new PluginReporter();

// ---- Plugin Update Checker ----
require_once __DIR__ . '/vendor/autoload.php';



$updateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/kobalt-digital/wordpress-plugin-reporter',
    __FILE__,
    'plugin-reporter'
);

$updateChecker->setBranch('main');