<?php
/**
 * Plugin Name: Plugin Reporter
 * Description: Sends plugin information to mijn.kobaltdigital.nl once a day and via a secure REST endpoint.
 * Version: 1.0.6
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

    public function __construct()
    {
        add_action('plugin_reporter_send', [$this, 'sendPluginInformation']);

        // Admin settings page
        add_action('admin_menu', [$this, 'addAdminMenu']);
        add_action('admin_menu', [$this, 'maybeHideAdminMenus'], 999);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_init', [$this, 'handleTestPost']);
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
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default'           => 'kobaltdigital.nl,alkmaarsch.nl',
        ]);
        register_setting('plugin_reporter_settings', 'plugin_reporter_hide_plugins', [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 0,
        ]);
        register_setting('plugin_reporter_settings', 'plugin_reporter_hide_acf', [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 0,
        ]);
        register_setting('plugin_reporter_settings', 'plugin_reporter_theme', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'default',
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
                'slug'        => $slug,
                'title'       => $plugin_data['Name'],
                'version'     => $plugin_data['Version'],
                'status'      => in_array($plugin_file, $active) ? 'active' : 'inactive',
                'auto_update' => in_array($plugin_file, $auto_update_plugins) ? 1 : 0,
                'update'      => isset($updates->response[$plugin_file])
                    ? $updates->response[$plugin_file]->new_version
                    : false,
            ];
        }

        return [
            'site_url' => site_url(),
            'wordpress_version' => get_bloginfo('version'),
            'plugins'  => $data,
        ];
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
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $this->getSecret(),
            ],
            'body'    => wp_json_encode($payload),
            'timeout' => 20,
            'sslverify' => false,
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
            'sslverify' => false,
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