<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bootstrap-safe public-release hardening.
 *
 * This class intentionally avoids wp_salt() because plugin bootstrap occurs
 * before pluggable.php defines that function. At-rest key material is derived
 * only from wp-config constants that are already available when plugins load.
 */
final class TakKa_WordPress_Bridge_V093_Public_Readiness
{
    private const VERSION = '0.9.3';
    private const MANAGE_NAMESPACE = 'takka-v093/v1';
    private const MANAGE_ROUTE = '/takka-v093/v1/manage';
    private const OUTER_ROUTE = '/takka-bridge/v1/execute';
    private const HEALTH_ROUTE = '/takka-bridge/v1/health';

    private const OPTION_SECRET = 'takka_bridge_secret';
    private const OPTION_USER_ID = 'takka_bridge_user_id';
    private const OPTION_PRIVATE_KEY = 'takka_bridge_secure_rsa_private_v1';
    private const OPTION_PUBLIC_KEY = 'takka_bridge_secure_rsa_public_v1';
    private const OPTION_KEY_CREATED = 'takka_bridge_secure_rsa_created_v1';

    private const ENCRYPTED_PREFIX = 'twbenc1:';
    private const ENCRYPTION_CONTEXT = 'takka-wordpress-bridge-at-rest-v1';
    private const MAX_CLOCK_SKEW = 300;

    private static $internal_allowed = false;
    private static $protecting_option = false;

    private const ACTIONS = [
        'v093.capabilities',
        'secure.key.status',
        'secure.key.rotate',
    ];

    public static function init(): void
    {
        // Migrate raw legacy values before installing read filters.
        self::migrate_legacy_secret(self::OPTION_SECRET);
        self::migrate_legacy_secret(self::OPTION_PRIVATE_KEY);

        add_filter('pre_option_' . self::OPTION_SECRET, [self::class, 'constant_secret_override'], 10, 3);
        add_filter('option_' . self::OPTION_SECRET, [self::class, 'decrypt_secret_option'], 10, 2);
        add_filter('option_' . self::OPTION_PRIVATE_KEY, [self::class, 'decrypt_private_key_option'], 10, 2);
        add_action('added_option', [self::class, 'protect_added_option'], 10, 2);
        add_action('updated_option', [self::class, 'protect_updated_option'], 10, 3);

        add_action('rest_api_init', [self::class, 'register_routes']);
        add_filter('rest_pre_dispatch', [self::class, 'prepare_internal_call'], 58, 3);
        add_filter('rest_request_after_callbacks', [self::class, 'annotate_health'], 525, 3);

        add_action('admin_menu', [self::class, 'admin_menu']);
        add_action('admin_post_takka_bridge_rotate_secure_key', [self::class, 'handle_rotate_secure_key']);
        add_action('admin_post_takka_bridge_generate_key', [self::class, 'block_database_key_rotation_when_constant'], 0);
    }

    public static function constant_secret_override($pre_option, string $option, $default_value)
    {
        if (!defined('TAKKA_BRIDGE_SECRET')) {
            return $pre_option;
        }
        $secret = constant('TAKKA_BRIDGE_SECRET');
        if (!is_string($secret) || strlen($secret) < 32) {
            return '';
        }
        return $secret;
    }

    public static function decrypt_secret_option($value, string $option)
    {
        return self::decrypt_if_needed($value, self::OPTION_SECRET);
    }

    public static function decrypt_private_key_option($value, string $option)
    {
        return self::decrypt_if_needed($value, self::OPTION_PRIVATE_KEY);
    }

    public static function protect_added_option(string $option, $value): void
    {
        self::protect_written_option($option, $value);
    }

    public static function protect_updated_option(string $option, $old_value, $value): void
    {
        self::protect_written_option($option, $value);
    }

    private static function protect_written_option(string $option, $value): void
    {
        if (self::$protecting_option || !in_array($option, [self::OPTION_SECRET, self::OPTION_PRIVATE_KEY], true)) {
            return;
        }
        if (!is_string($value) || $value === '' || self::is_encrypted($value)) {
            return;
        }
        $encrypted = self::encrypt_value($value, $option);
        if (is_string($encrypted)) {
            self::replace_raw_option($option, $encrypted);
        }
    }

    private static function migrate_legacy_secret(string $option): void
    {
        $raw = self::raw_option($option);
        if (!is_string($raw) || $raw === '' || self::is_encrypted($raw)) {
            return;
        }
        $encrypted = self::encrypt_value($raw, $option);
        if (is_string($encrypted)) {
            self::replace_raw_option($option, $encrypted);
        }
    }

    private static function replace_raw_option(string $option, string $stored): void
    {
        self::$protecting_option = true;
        try {
            delete_option($option);
            add_option($option, $stored, '', false);
        } finally {
            self::$protecting_option = false;
        }
    }

    /**
     * Derive an at-rest encryption key from wp-config constants that are
     * available before pluggable.php. At least two independent non-empty values
     * are required; standard WordPress installations provide all four.
     */
    private static function encryption_key()
    {
        if (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) {
            return null;
        }
        $methods = array_map('strtolower', openssl_get_cipher_methods());
        if (!in_array('aes-256-gcm', $methods, true)) {
            return null;
        }

        $parts = [];
        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT'] as $name) {
            if (!defined($name)) {
                continue;
            }
            $value = constant($name);
            if (is_string($value) && $value !== '') {
                $parts[] = $name . '=' . $value;
            }
        }
        if (count($parts) < 2) {
            return null;
        }
        $material = implode("\0", $parts);
        $key = hash_hmac('sha256', self::ENCRYPTION_CONTEXT, $material, true);
        self::memzero($material);
        return $key;
    }

    private static function encrypt_value(string $plaintext, string $option)
    {
        $key = self::encryption_key();
        if (!is_string($key) || strlen($key) !== 32) {
            return null;
        }
        try {
            $iv = random_bytes(12);
        } catch (Throwable $e) {
            self::memzero($key);
            return null;
        }
        $tag = '';
        $aad = self::ENCRYPTION_CONTEXT . "\n" . $option;
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad, 16);
        self::memzero($key);
        if (!is_string($ciphertext) || strlen($tag) !== 16) {
            return null;
        }
        $payload = wp_json_encode([
            'v' => 1,
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'ciphertext' => base64_encode($ciphertext),
        ], JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) {
            return null;
        }
        return self::ENCRYPTED_PREFIX . base64_encode($payload);
    }

    private static function decrypt_if_needed($value, string $option)
    {
        if (!is_string($value) || !self::is_encrypted($value)) {
            return $value;
        }
        $encoded = substr($value, strlen(self::ENCRYPTED_PREFIX));
        $json = base64_decode($encoded, true);
        if (!is_string($json)) {
            return '';
        }
        $payload = json_decode($json, true);
        if (!is_array($payload) || (int) ($payload['v'] ?? 0) !== 1) {
            return '';
        }
        $iv = isset($payload['iv']) && is_string($payload['iv']) ? base64_decode($payload['iv'], true) : false;
        $tag = isset($payload['tag']) && is_string($payload['tag']) ? base64_decode($payload['tag'], true) : false;
        $ciphertext = isset($payload['ciphertext']) && is_string($payload['ciphertext']) ? base64_decode($payload['ciphertext'], true) : false;
        if (!is_string($iv) || strlen($iv) !== 12 || !is_string($tag) || strlen($tag) !== 16 || !is_string($ciphertext)) {
            return '';
        }
        $key = self::encryption_key();
        if (!is_string($key) || strlen($key) !== 32) {
            return '';
        }
        $aad = self::ENCRYPTION_CONTEXT . "\n" . $option;
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad);
        self::memzero($key);
        return is_string($plaintext) ? $plaintext : '';
    }

    private static function is_encrypted(string $value): bool
    {
        return strncmp($value, self::ENCRYPTED_PREFIX, strlen(self::ENCRYPTED_PREFIX)) === 0;
    }

    public static function register_routes(): void
    {
        register_rest_route(self::MANAGE_NAMESPACE, '/manage', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'dispatch_manage'],
            'permission_callback' => [self::class, 'internal_permission'],
        ]);
    }

    public static function prepare_internal_call($result, WP_REST_Server $server, WP_REST_Request $request)
    {
        if ($result !== null || $request->get_route() !== self::OUTER_ROUTE || strtoupper($request->get_method()) !== 'POST') {
            return $result;
        }
        if (!self::valid_hmac($request, self::OUTER_ROUTE, false)) {
            return $result;
        }
        $inner = self::decode_outer_envelope($request);
        if (!is_array($inner) || ($inner['action'] ?? '') !== 'rest.call') {
            return $result;
        }
        $params = isset($inner['params']) && is_array($inner['params']) ? $inner['params'] : [];
        $method = isset($params['method']) ? strtoupper((string) $params['method']) : 'GET';
        $route = isset($params['route']) && is_string($params['route']) ? trim($params['route']) : '';
        if ($method !== 'POST' || $route !== self::MANAGE_ROUTE) {
            return $result;
        }
        self::$internal_allowed = true;
        return $result;
    }

    public static function internal_permission()
    {
        if (!self::$internal_allowed || !current_user_can('manage_options')) {
            return new WP_Error('takka_bridge_v093_internal_only', 'This route is only callable through the signed Bridge REST proxy.', ['status' => 403]);
        }
        self::$internal_allowed = false;
        return true;
    }

    public static function dispatch_manage(WP_REST_Request $request)
    {
        $json = $request->get_json_params();
        if (!is_array($json)) {
            return new WP_Error('takka_bridge_v093_json', 'JSON body is required.', ['status' => 400]);
        }
        $action = isset($json['action']) && is_string($json['action']) ? trim($json['action']) : '';
        $params = isset($json['params']) && is_array($json['params']) ? $json['params'] : [];
        if (!in_array($action, self::ACTIONS, true)) {
            return new WP_Error('takka_bridge_v093_unknown_action', 'Unknown or blocked v0.9.3 action.', ['status' => 400, 'action' => $action]);
        }

        try {
            if ($action === 'v093.capabilities') {
                return rest_ensure_response(self::capabilities());
            }
            if ($action === 'secure.key.status') {
                return rest_ensure_response(self::secure_key_status());
            }
            if ($action === 'secure.key.rotate') {
                return self::secure_key_rotate($params);
            }
        } catch (Throwable $e) {
            return new WP_Error('takka_bridge_v093_exception', $e->getMessage(), ['status' => 500, 'type' => get_class($e)]);
        }
        return new WP_Error('takka_bridge_v093_dispatch', 'Dispatch fell through.', ['status' => 500]);
    }

    private static function capabilities(): array
    {
        return [
            'version' => self::VERSION,
            'actions' => self::ACTIONS,
            'bootstrap_safe_key_derivation' => true,
            'bridge_secret_wp_config_override' => true,
            'option_secret_encryption_at_rest' => self::encryption_key() !== null,
            'secure_private_key_encryption_at_rest' => self::encryption_key() !== null,
            'secure_server_key_rotation' => true,
            'key_rotation_requires_confirm' => true,
        ];
    }

    private static function secure_key_status(): array
    {
        $public = get_option(self::OPTION_PUBLIC_KEY, '');
        $created = get_option(self::OPTION_KEY_CREATED, '');
        $raw_private = self::raw_option(self::OPTION_PRIVATE_KEY);
        $raw_secret = self::raw_option(self::OPTION_SECRET);
        $key_id = is_string($public) && $public !== '' ? hash('sha256', $public) : null;
        $bits = null;
        if (is_string($public) && $public !== '') {
            $resource = openssl_pkey_get_public($public);
            if ($resource !== false) {
                $details = openssl_pkey_get_details($resource);
                if (is_array($details)) {
                    $bits = (int) ($details['bits'] ?? 0);
                }
            }
        }
        return [
            'version' => self::VERSION,
            'server_key_id' => $key_id,
            'created_gmt' => is_string($created) && $created !== '' ? $created : null,
            'rsa_bits' => $bits,
            'bridge_secret_source' => defined('TAKKA_BRIDGE_SECRET') ? 'wp_config_constant' : self::storage_state($raw_secret),
            'secure_private_key_storage' => self::storage_state($raw_private),
            'at_rest_cipher' => self::encryption_key() !== null ? 'AES-256-GCM' : null,
            'key_derivation_source' => 'wp_config_constants',
        ];
    }

    private static function secure_key_rotate(array $params)
    {
        if (empty($params['confirm'])) {
            return new WP_Error('takka_bridge_confirmation_required', 'Secure server key rotation requires confirm=true.', ['status' => 400]);
        }
        if (!function_exists('openssl_pkey_new') || !defined('OPENSSL_KEYTYPE_RSA')) {
            return new WP_Error('takka_bridge_secure_crypto_unavailable', 'OpenSSL RSA support is unavailable.', ['status' => 501]);
        }

        $old_public = get_option(self::OPTION_PUBLIC_KEY, '');
        $old_key_id = is_string($old_public) && $old_public !== '' ? hash('sha256', $old_public) : null;
        $expected = isset($params['expected_key_id']) && is_string($params['expected_key_id']) ? trim($params['expected_key_id']) : '';
        if ($expected !== '' && (!is_string($old_key_id) || !hash_equals($old_key_id, $expected))) {
            return new WP_Error('takka_bridge_secure_key_changed', 'Secure server key changed before rotation.', [
                'status' => 409,
                'current_server_key_id' => $old_key_id,
            ]);
        }

        $resource = openssl_pkey_new([
            'private_key_bits' => 3072,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($resource === false || !openssl_pkey_export($resource, $private_pem)) {
            return new WP_Error('takka_bridge_secure_keygen', 'Could not generate or export a new RSA secure transport key.', ['status' => 500]);
        }
        $details = openssl_pkey_get_details($resource);
        if (!is_array($details) || !isset($details['key'])) {
            self::memzero($private_pem);
            return new WP_Error('takka_bridge_secure_keygen', 'Could not export the new RSA public key.', ['status' => 500]);
        }
        $public_pem = (string) $details['key'];
        $created = gmdate('Y-m-d H:i:s');

        update_option(self::OPTION_PRIVATE_KEY, (string) $private_pem, false);
        update_option(self::OPTION_PUBLIC_KEY, $public_pem, false);
        update_option(self::OPTION_KEY_CREATED, $created, false);
        self::memzero($private_pem);

        $stored_private = self::raw_option(self::OPTION_PRIVATE_KEY);
        if (self::encryption_key() !== null && self::storage_state($stored_private) !== 'encrypted_wp_options') {
            return new WP_Error('takka_bridge_secure_key_storage', 'New secure private key was generated but could not be protected at rest.', ['status' => 500]);
        }

        return rest_ensure_response([
            'ok' => true,
            'previous_server_key_id' => $old_key_id,
            'server_key_id' => hash('sha256', $public_pem),
            'public_key_pem_b64' => base64_encode($public_pem),
            'rsa_bits' => (int) ($details['bits'] ?? 0),
            'created_gmt' => $created,
            'private_key_storage' => self::storage_state($stored_private),
            'old_key_retained' => false,
        ]);
    }

    public static function annotate_health($response, array $handler, WP_REST_Request $request)
    {
        if ($request->get_route() !== self::HEALTH_ROUTE || is_wp_error($response)) {
            return $response;
        }
        $rest = rest_ensure_response($response);
        $data = $rest->get_data();
        if (!is_array($data)) {
            return $response;
        }
        $data['bridge_version'] = self::VERSION;
        $features = isset($data['features']) && is_array($data['features']) ? $data['features'] : [];
        foreach ([
            'public_release_hardening',
            'bootstrap_safe_secret_key_derivation',
            'encrypted_bridge_secret_at_rest',
            'encrypted_secure_private_key_at_rest',
            'wp_config_bridge_secret_override',
            'secure_server_key_rotation',
        ] as $feature) {
            if (!in_array($feature, $features, true)) {
                $features[] = $feature;
            }
        }
        $data['features'] = $features;
        $rest->set_data($data);
        return $rest;
    }

    public static function admin_menu(): void
    {
        add_management_page(
            'TakKa WordPress Bridge Security',
            'TakKa WP Bridge Security',
            'manage_options',
            'takka-wordpress-bridge-security',
            [self::class, 'render_security_page']
        );
    }

    public static function render_security_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $status = self::secure_key_status();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('TakKa WordPress Bridge Security', 'takka-wordpress-bridge'); ?></h1>
            <p><strong><?php echo esc_html__('Bridge version:', 'takka-wordpress-bridge'); ?></strong> <?php echo esc_html(self::VERSION); ?></p>
            <p><strong><?php echo esc_html__('Bridge secret source:', 'takka-wordpress-bridge'); ?></strong> <?php echo esc_html((string) $status['bridge_secret_source']); ?></p>
            <p><strong><?php echo esc_html__('Secure private key storage:', 'takka-wordpress-bridge'); ?></strong> <?php echo esc_html((string) $status['secure_private_key_storage']); ?></p>
            <p><strong><?php echo esc_html__('Secure server key ID:', 'takka-wordpress-bridge'); ?></strong> <code><?php echo esc_html((string) ($status['server_key_id'] ?? 'not generated')); ?></code></p>
            <p><strong><?php echo esc_html__('Secure key created (GMT):', 'takka-wordpress-bridge'); ?></strong> <?php echo esc_html((string) ($status['created_gmt'] ?? 'not generated')); ?></p>
            <p><?php echo esc_html__('Rotating the secure server key makes newly encrypted requests use a new RSA key. The previous private key is not retained by the plugin.', 'takka-wordpress-bridge'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="takka_bridge_rotate_secure_key">
                <input type="hidden" name="expected_key_id" value="<?php echo esc_attr((string) ($status['server_key_id'] ?? '')); ?>">
                <?php wp_nonce_field('takka_bridge_rotate_secure_key'); ?>
                <?php submit_button(__('Rotate Secure Server Key', 'takka-wordpress-bridge'), 'secondary'); ?>
            </form>
        </div>
        <?php
    }

    public static function handle_rotate_secure_key(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden', '', ['response' => 403]);
        }
        check_admin_referer('takka_bridge_rotate_secure_key');
        $expected = isset($_POST['expected_key_id']) ? sanitize_text_field(wp_unslash($_POST['expected_key_id'])) : '';
        $result = self::secure_key_rotate([
            'confirm' => true,
            'expected_key_id' => $expected,
        ]);
        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 500;
            wp_die(esc_html($result->get_error_message()), '', ['response' => $status]);
        }
        wp_safe_redirect(admin_url('tools.php?page=takka-wordpress-bridge-security&rotated=1'));
        exit;
    }

    public static function block_database_key_rotation_when_constant(): void
    {
        if (!defined('TAKKA_BRIDGE_SECRET')) {
            return;
        }
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden', '', ['response' => 403]);
        }
        check_admin_referer('takka_bridge_generate_key');
        wp_die(
            esc_html__('TAKKA_BRIDGE_SECRET is defined in wp-config.php. Remove or change that constant instead of rotating the database copy.', 'takka-wordpress-bridge'),
            '',
            ['response' => 409]
        );
    }

    private static function storage_state($raw): string
    {
        if (!is_string($raw) || $raw === '') {
            return 'not_configured';
        }
        return self::is_encrypted($raw) ? 'encrypted_wp_options' : 'legacy_plaintext_wp_options';
    }

    private static function raw_option(string $option)
    {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            $option
        ));
    }

    private static function valid_hmac(WP_REST_Request $request, string $route, bool $set_user): bool
    {
        $secret = (string) get_option(self::OPTION_SECRET, '');
        $user_id = (int) get_option(self::OPTION_USER_ID, 0);
        if ($secret === '' || $user_id < 1 || !user_can($user_id, 'manage_options')) {
            return false;
        }
        $timestamp = trim((string) $request->get_header('x-takka-timestamp'));
        $signature = strtolower(trim((string) $request->get_header('x-takka-signature')));
        if ($timestamp === '' || $signature === '' || !ctype_digit($timestamp)) {
            return false;
        }
        if (abs(time() - (int) $timestamp) > self::MAX_CLOCK_SKEW) {
            return false;
        }
        $payload = $timestamp . "\n" . strtoupper($request->get_method()) . "\n" . $route . "\n" . hash('sha256', (string) $request->get_body());
        $valid = hash_equals(hash_hmac('sha256', $payload, $secret), $signature);
        if ($valid && $set_user) {
            wp_set_current_user($user_id);
        }
        return $valid;
    }

    private static function decode_outer_envelope(WP_REST_Request $request)
    {
        $outer = json_decode((string) $request->get_body(), true);
        if (!is_array($outer) || ($outer['action'] ?? '') !== 'envelope' || !isset($outer['params']['payload_b64'])) {
            return null;
        }
        $decoded = base64_decode((string) $outer['params']['payload_b64'], true);
        if (!is_string($decoded)) {
            return null;
        }
        $inner = json_decode($decoded, true);
        return is_array($inner) ? $inner : null;
    }

    private static function memzero(&$value): void
    {
        if (is_string($value) && function_exists('sodium_memzero')) {
            sodium_memzero($value);
        } else {
            $value = '';
        }
    }
}
