<?php
/**
 * Plugin Name: Plugin Reporter
 * Description: Sends plugin information to mijn.kobaltdigital.nl once a day and via a secure REST endpoint.
 * Version: 1.0.3
 * Author: Arne van Hoorn
 */

if (!defined('ABSPATH')) exit;

class PluginReporter
{
    private $default_endpoint = 'https://plugin-reporter.kobaltdigital.nl/api/data';

    public function __construct() {
        add_action('plugin_reporter_send', [$this, 'send_plugin_information']);
        register_activation_hook(__FILE__, [$this, 'schedule']);
        register_deactivation_hook(__FILE__, [$this, 'unschedule']);

        // Admin settings page
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'handle_test_post']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_settings_link']);

        // Secure REST endpoint
        add_action('rest_api_init', function () {
            register_rest_route('plugin-reporter/v1', '/send', [
                'methods'  => 'POST',
                'callback' => [$this, 'send_plugin_information'],
                'permission_callback' => function ($req) {
                    $key = $req->get_header('X-Reporter-Key');
                    return $key && hash_equals($this->get_secret(), $key);
                }
            ]);
        });
    }

    public function schedule() {
        if (!wp_next_scheduled('plugin_reporter_send')) {
            wp_schedule_event(time(), 'daily', 'plugin_reporter_send');
        }
    }

    public function unschedule() {
        wp_clear_scheduled_hook('plugin_reporter_send');
    }

    private function get_endpoint() {
        $endpoint = get_option('plugin_reporter_endpoint', '');
        return !empty($endpoint) ? $endpoint : $this->default_endpoint;
    }

    private function get_secret() {
        return get_option('plugin_reporter_secret', '');
    }

    public function register_settings() {
        register_setting('plugin_reporter_settings', 'plugin_reporter_endpoint', [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => $this->default_endpoint,
        ]);
        register_setting('plugin_reporter_settings', 'plugin_reporter_secret', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
    }

    public function add_admin_menu() {
        add_options_page(
            'Plugin Reporter Settings',
            'Plugin Reporter',
            'manage_options',
            'plugin-reporter',
            [$this, 'render_settings_page']
        );
    }

    public function add_settings_link($links) {
        $settings_link = '<a href="' . admin_url('options-general.php?page=plugin-reporter') . '">' . __('Settings') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    private function collect_plugin_payload() {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $plugins = get_plugins();
        $active  = get_option('active_plugins', []);
        $updates = get_site_transient('update_plugins');

        $data = [];

        foreach ($plugins as $plugin_file => $plugin_data) {
            $slug = dirname($plugin_file);

            $data[] = [
                'slug'    => $slug,
                'title'   => $plugin_data['Name'],
                'version' => $plugin_data['Version'],
                'status'  => in_array($plugin_file, $active) ? 'active' : 'inactive',
                'update'  => isset($updates->response[$plugin_file])
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

    public function handle_test_post() {
        if (!isset($_POST['plugin_reporter_test']) || !check_admin_referer('plugin_reporter_test', 'plugin_reporter_test_nonce')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $payload = $this->collect_plugin_payload();
        $data = $payload['plugins'];

        $response = wp_remote_post($this->get_endpoint(), [
            'method'  => 'POST',
            'headers' => [
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $this->get_secret(),
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

    public function render_settings_page() {
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
                                   value="<?php echo esc_attr(get_option('plugin_reporter_endpoint', $this->default_endpoint)); ?>"
                                   class="regular-text"
                                   placeholder="<?php echo esc_attr($this->default_endpoint); ?>" />
                            <p class="description">The API endpoint where plugin information will be sent. Leave empty to use the default.</p>
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
                </table>

                <?php submit_button('Save Settings'); ?>
            </form>

            <div class="card" style="margin-top: 20px;">
                <h2>Test Connection</h2>
                <p>Click the button below to send a test POST request to the external endpoint.</p>

                <form method="post" action="">
                    <?php wp_nonce_field('plugin_reporter_test', 'plugin_reporter_test_nonce'); ?>
                    <p>
                        <input type="submit" name="plugin_reporter_test" class="button button-primary" value="Run Test Post" />
                    </p>
                </form>
            </div>

            <div class="card" style="margin-top: 20px;">
                <h2>Information</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Current Endpoint</th>
                        <td><code><?php echo esc_html($this->get_endpoint()); ?></code></td>
                    </tr>
                    <tr>
                        <th scope="row">Next Scheduled Run</th>
                        <td>
                            <?php
                            $next_run = wp_next_scheduled('plugin_reporter_send');
                            if ($next_run) {
                                echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_run));
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

    public function send_plugin_information() {
        $payload = $this->collect_plugin_payload();
        $data = $payload['plugins'];

        // Send to external endpoint
        wp_remote_post($this->get_endpoint(), [
            'method'  => 'POST',
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->get_secret(),
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
}

new PluginReporter();

// ---- Plugin Update Checker ----
require_once __DIR__ . '/vendor/autoload.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$updateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/kobalt-digital/wordpress-plugin-reporter',
    __FILE__,
    'plugin-reporter'
);

$updateChecker->setBranch('main');