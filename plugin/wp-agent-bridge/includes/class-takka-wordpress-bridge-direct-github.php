<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * GitHub client for the direct-runtime prototype.
 *
 * A site-specific GitHub App private key and webhook secret are stored only on
 * this WordPress installation. Sensitive values are encrypted at rest using
 * AES-256-GCM with key material derived from wp-config salts.
 */
final class TakKa_WordPress_Bridge_Direct_GitHub
{
    private const API_ROOT = 'https://api.github.com';
    private const API_VERSION = '2026-03-10';
    private const OPTION_APP = 'takka_bridge_direct_github_app_v1';
    private const OPTION_PRIVATE_KEY = 'takka_bridge_direct_github_private_key_v1';
    private const OPTION_WEBHOOK_SECRET = 'takka_bridge_direct_github_webhook_secret_v1';
    private const ENCRYPTED_PREFIX = 'twbdg1:';
    private const ENCRYPTION_CONTEXT = 'wp-agent-bridge-direct-github-v1';
    private const TOKEN_TRANSIENT_PREFIX = 'takka_bridge_direct_iat_';

    public static function crypto_available(): bool
    {
        if (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt') || !function_exists('openssl_sign')) {
            return false;
        }
        $methods = array_map('strtolower', openssl_get_cipher_methods());
        return in_array('aes-256-gcm', $methods, true);
    }

    public static function store_app_credentials(int $app_id, string $slug, string $private_key, string $webhook_secret): bool
    {
        if ($app_id < 1 || !preg_match('/^[A-Za-z0-9-]{1,100}$/', $slug)) {
            return false;
        }
        if (strpos($private_key, 'PRIVATE KEY-----') === false || strlen($webhook_secret) < 20) {
            return false;
        }
        $encrypted_key = self::encrypt_value($private_key, self::OPTION_PRIVATE_KEY);
        $encrypted_secret = self::encrypt_value($webhook_secret, self::OPTION_WEBHOOK_SECRET);
        if (!is_string($encrypted_key) || !is_string($encrypted_secret)) {
            return false;
        }

        update_option(self::OPTION_APP, [
            'app_id' => $app_id,
            'slug' => $slug,
            'created_at_gmt' => gmdate('c'),
        ], false);
        update_option(self::OPTION_PRIVATE_KEY, $encrypted_key, false);
        update_option(self::OPTION_WEBHOOK_SECRET, $encrypted_secret, false);
        return true;
    }

    public static function app_config(): array
    {
        $value = get_option(self::OPTION_APP, []);
        return is_array($value) ? $value : [];
    }

    public static function private_key(): string
    {
        $value = get_option(self::OPTION_PRIVATE_KEY, '');
        return is_string($value) ? self::decrypt_value($value, self::OPTION_PRIVATE_KEY) : '';
    }

    public static function webhook_secret(): string
    {
        $value = get_option(self::OPTION_WEBHOOK_SECRET, '');
        return is_string($value) ? self::decrypt_value($value, self::OPTION_WEBHOOK_SECRET) : '';
    }

    public static function clear_credentials(): void
    {
        delete_option(self::OPTION_APP);
        delete_option(self::OPTION_PRIVATE_KEY);
        delete_option(self::OPTION_WEBHOOK_SECRET);
    }

    public static function verify_webhook(string $raw_body, string $signature): bool
    {
        $secret = self::webhook_secret();
        if ($secret === '' || strpos($signature, 'sha256=') !== 0) {
            return false;
        }
        $expected = 'sha256=' . hash_hmac('sha256', $raw_body, $secret);
        return hash_equals($expected, strtolower(trim($signature)));
    }

    public static function app_jwt()
    {
        $app = self::app_config();
        $app_id = isset($app['app_id']) ? (int) $app['app_id'] : 0;
        $private_key = self::private_key();
        if ($app_id < 1 || $private_key === '') {
            return new WP_Error('takka_direct_github_credentials', 'Direct GitHub App credentials are incomplete.');
        }

        $now = time();
        $header = self::base64url(wp_json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = self::base64url(wp_json_encode([
            'iat' => $now - 60,
            'exp' => $now + 540,
            'iss' => $app_id,
        ]));
        if ($header === '' || $payload === '') {
            return new WP_Error('takka_direct_github_jwt_encode', 'Could not encode GitHub App JWT.');
        }

        $unsigned = $header . '.' . $payload;
        $signature = '';
        $key = openssl_pkey_get_private($private_key);
        self::memzero($private_key);
        if ($key === false || !openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
            return new WP_Error('takka_direct_github_jwt_sign', 'Could not sign GitHub App JWT.');
        }
        return $unsigned . '.' . self::base64url($signature, true);
    }

    public static function installation_token(int $installation_id, int $repository_id = 0)
    {
        if ($installation_id < 1) {
            return new WP_Error('takka_direct_github_installation', 'Invalid GitHub App installation ID.');
        }
        $cache_key = self::TOKEN_TRANSIENT_PREFIX . $installation_id . '_' . max(0, $repository_id);
        $cached = get_transient($cache_key);
        if (is_array($cached) && !empty($cached['token']) && !empty($cached['expires'])) {
            if ((int) $cached['expires'] > time() + 120) {
                return (string) $cached['token'];
            }
        }

        $jwt = self::app_jwt();
        if (is_wp_error($jwt)) {
            return $jwt;
        }
        $body = [];
        if ($repository_id > 0) {
            $body['repository_ids'] = [$repository_id];
        }
        $response = self::request('POST', '/app/installations/' . $installation_id . '/access_tokens', $jwt, $body, true);
        if (is_wp_error($response)) {
            return $response;
        }
        $data = $response['data'];
        $token = isset($data['token']) && is_string($data['token']) ? $data['token'] : '';
        $expires_at = isset($data['expires_at']) && is_string($data['expires_at']) ? strtotime($data['expires_at']) : false;
        if ($token === '' || $expires_at === false) {
            return new WP_Error('takka_direct_github_token_response', 'GitHub did not return a valid installation token.');
        }
        $ttl = max(60, min(3300, $expires_at - time() - 120));
        set_transient($cache_key, ['token' => $token, 'expires' => $expires_at], $ttl);
        return $token;
    }

    public static function installation_repositories(string $token)
    {
        $response = self::request('GET', '/installation/repositories?per_page=100', $token, null, false);
        if (is_wp_error($response)) {
            return $response;
        }
        $repositories = isset($response['data']['repositories']) && is_array($response['data']['repositories'])
            ? $response['data']['repositories']
            : [];
        return $repositories;
    }

    public static function get_text_file(string $token, string $repository, string $ref, string $path)
    {
        $meta = self::get_content_metadata($token, $repository, $ref, $path);
        if (is_wp_error($meta)) {
            return $meta;
        }
        $encoded = isset($meta['content']) && is_string($meta['content']) ? preg_replace('/\s+/', '', $meta['content']) : '';
        if ($encoded === '') {
            return new WP_Error('takka_direct_github_content', 'GitHub file content is missing.');
        }
        $decoded = base64_decode($encoded, true);
        if (!is_string($decoded)) {
            return new WP_Error('takka_direct_github_content_decode', 'Could not decode GitHub file content.');
        }
        return $decoded;
    }

    public static function get_content_metadata(string $token, string $repository, string $ref, string $path)
    {
        if (!self::valid_repository($repository) || !self::valid_path($path)) {
            return new WP_Error('takka_direct_github_path', 'Invalid GitHub repository or path.');
        }
        $endpoint = '/repos/' . $repository . '/contents/' . self::encode_path($path) . '?ref=' . rawurlencode($ref);
        $response = self::request('GET', $endpoint, $token, null, false);
        if (is_wp_error($response)) {
            return $response;
        }
        return is_array($response['data']) ? $response['data'] : [];
    }

    public static function put_text_file(string $token, string $repository, string $branch, string $path, string $content, string $message)
    {
        if (!self::valid_repository($repository) || !self::valid_path($path)) {
            return new WP_Error('takka_direct_github_path', 'Invalid GitHub repository or path.');
        }
        $body = [
            'message' => $message,
            'content' => base64_encode($content),
            'branch' => $branch,
        ];
        $existing = self::get_content_metadata_optional($token, $repository, $branch, $path);
        if (is_wp_error($existing)) {
            return $existing;
        }
        if (is_array($existing) && !empty($existing['sha'])) {
            $body['sha'] = (string) $existing['sha'];
        }
        $endpoint = '/repos/' . $repository . '/contents/' . self::encode_path($path);
        return self::request('PUT', $endpoint, $token, $body, false);
    }

    public static function delete_file(string $token, string $repository, string $branch, string $path, string $sha, string $message)
    {
        if (!self::valid_repository($repository) || !self::valid_path($path) || !preg_match('/^[a-f0-9]{40,64}$/', strtolower($sha))) {
            return new WP_Error('takka_direct_github_delete', 'Invalid GitHub delete request.');
        }
        $endpoint = '/repos/' . $repository . '/contents/' . self::encode_path($path);
        return self::request('DELETE', $endpoint, $token, [
            'message' => $message,
            'sha' => $sha,
            'branch' => $branch,
        ], false);
    }

    public static function github_api(string $method, string $endpoint, string $token, $body = null)
    {
        return self::request($method, $endpoint, $token, $body, false);
    }

    private static function get_content_metadata_optional(string $token, string $repository, string $ref, string $path)
    {
        $endpoint = '/repos/' . $repository . '/contents/' . self::encode_path($path) . '?ref=' . rawurlencode($ref);
        $response = self::request('GET', $endpoint, $token, null, false, [404]);
        if (is_wp_error($response)) {
            return $response;
        }
        if ((int) $response['status'] === 404) {
            return null;
        }
        return is_array($response['data']) ? $response['data'] : [];
    }

    private static function request(string $method, string $endpoint, string $token, $body, bool $jwt_auth, array $allowed_statuses = [])
    {
        if (strpos($endpoint, '/') !== 0 || strpos($endpoint, '://') !== false) {
            return new WP_Error('takka_direct_github_endpoint', 'Invalid GitHub API endpoint.');
        }
        $url = self::API_ROOT . $endpoint;
        $headers = [
            'Accept' => 'application/vnd.github+json',
            'Authorization' => 'Bearer ' . $token,
            'X-GitHub-Api-Version' => self::API_VERSION,
            'User-Agent' => 'WP-Agent-Bridge-Direct/0.1',
        ];
        $args = [
            'method' => strtoupper($method),
            'timeout' => 30,
            'redirection' => 0,
            'headers' => $headers,
        ];
        if ($body !== null) {
            $encoded = wp_json_encode($body, JSON_UNESCAPED_SLASHES);
            if (!is_string($encoded)) {
                return new WP_Error('takka_direct_github_json', 'Could not encode GitHub API request.');
            }
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = $encoded;
        }

        $attempt = 0;
        do {
            $response = wp_safe_remote_request($url, $args);
            if (is_wp_error($response)) {
                return $response;
            }
            $status = (int) wp_remote_retrieve_response_code($response);
            if (($status === 429 || $status === 403) && $attempt === 0) {
                $retry_after = (int) wp_remote_retrieve_header($response, 'retry-after');
                if ($retry_after > 0 && $retry_after <= 2) {
                    sleep($retry_after);
                    $attempt++;
                    continue;
                }
            }
            break;
        } while ($attempt < 2);

        $raw = (string) wp_remote_retrieve_body($response);
        $data = $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($data)) {
            $data = ['raw' => substr($raw, 0, 4000)];
        }
        if (($status < 200 || $status >= 300) && !in_array($status, $allowed_statuses, true)) {
            $message = isset($data['message']) && is_string($data['message']) ? $data['message'] : 'GitHub API request failed.';
            return new WP_Error('takka_direct_github_http_' . $status, $message, [
                'status' => $status,
                'retry_after' => wp_remote_retrieve_header($response, 'retry-after'),
                'rate_remaining' => wp_remote_retrieve_header($response, 'x-ratelimit-remaining'),
                'rate_reset' => wp_remote_retrieve_header($response, 'x-ratelimit-reset'),
                'jwt_auth' => $jwt_auth,
            ]);
        }
        return [
            'status' => $status,
            'data' => $data,
            'rate_remaining' => wp_remote_retrieve_header($response, 'x-ratelimit-remaining'),
            'rate_reset' => wp_remote_retrieve_header($response, 'x-ratelimit-reset'),
        ];
    }

    private static function valid_repository(string $repository): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repository);
    }

    private static function valid_path(string $path): bool
    {
        return $path !== '' && strlen($path) <= 500 && strpos($path, '..') === false && strpos($path, "\0") === false;
    }

    private static function encode_path(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private static function base64url(string $value, bool $binary = false): string
    {
        if (!$binary && $value === '') {
            return '';
        }
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function encryption_key()
    {
        if (!self::crypto_available()) {
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
        return is_string($payload) ? self::ENCRYPTED_PREFIX . base64_encode($payload) : null;
    }

    private static function decrypt_value(string $stored, string $option): string
    {
        if (strpos($stored, self::ENCRYPTED_PREFIX) !== 0) {
            return '';
        }
        $json = base64_decode(substr($stored, strlen(self::ENCRYPTED_PREFIX)), true);
        if (!is_string($json)) {
            return '';
        }
        $payload = json_decode($json, true);
        if (!is_array($payload) || (int) ($payload['v'] ?? 0) !== 1) {
            return '';
        }
        $iv = isset($payload['iv']) ? base64_decode((string) $payload['iv'], true) : false;
        $tag = isset($payload['tag']) ? base64_decode((string) $payload['tag'], true) : false;
        $ciphertext = isset($payload['ciphertext']) ? base64_decode((string) $payload['ciphertext'], true) : false;
        if (!is_string($iv) || strlen($iv) !== 12 || !is_string($tag) || strlen($tag) !== 16 || !is_string($ciphertext)) {
            return '';
        }
        $key = self::encryption_key();
        if (!is_string($key) || strlen($key) !== 32) {
            return '';
        }
        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            self::ENCRYPTION_CONTEXT . "\n" . $option
        );
        self::memzero($key);
        return is_string($plaintext) ? $plaintext : '';
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
