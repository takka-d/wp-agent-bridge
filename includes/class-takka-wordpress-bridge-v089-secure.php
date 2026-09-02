<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TakKa_WordPress_Bridge_V089_Secure
{
    private const VERSION = '0.8.9';
    private const PROTOCOL = 'rsa_aes_gcm_v1';
    private const MANAGE_NAMESPACE = 'takka-v089/v1';
    private const MANAGE_ROUTE = '/takka-v089/v1/manage';
    private const OUTER_ROUTE = '/takka-bridge/v1/execute';
    private const SECURE_ROUTE = '/takka-bridge/v1/secure';
    private const HEALTH_ROUTE = '/takka-bridge/v1/health';
    private const OPTION_SECRET = 'takka_bridge_secret';
    private const OPTION_USER_ID = 'takka_bridge_user_id';
    private const OPTION_PRIVATE_KEY = 'takka_bridge_secure_rsa_private_v1';
    private const OPTION_PUBLIC_KEY = 'takka_bridge_secure_rsa_public_v1';
    private const OPTION_KEY_CREATED = 'takka_bridge_secure_rsa_created_v1';
    private const MAX_CLOCK_SKEW = 300;
    private const MAX_REQUEST_PLAINTEXT_BYTES = 524288;
    private const MAX_RESPONSE_PLAINTEXT_BYTES = 524288;
    private const MAX_CIPHERTEXT_BYTES = 700000;
    private const MAX_REPLY_PUBLIC_KEY_BYTES = 8192;

    private static $internal_allowed = false;
    private static $request_id = '';

    private const ACTIONS = [
        'v089.capabilities',
        'secure.public_key',
    ];

    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_filter('rest_pre_dispatch', [self::class, 'prepare_internal_call'], 55, 3);
        add_filter('rest_request_after_callbacks', [self::class, 'annotate_health'], 380, 3);
    }

    public static function register_routes(): void
    {
        register_rest_route(self::MANAGE_NAMESPACE, '/manage', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'dispatch_manage'],
            'permission_callback' => [self::class, 'internal_permission'],
        ]);

        register_rest_route('takka-bridge/v1', '/secure', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'dispatch_secure'],
            'permission_callback' => [self::class, 'secure_permission'],
        ]);
    }

    public static function prepare_internal_call($result, WP_REST_Server $server, WP_REST_Request $request)
    {
        if ($result !== null || $request->get_route() !== self::OUTER_ROUTE || strtoupper($request->get_method()) !== 'POST') return $result;
        if (!self::valid_hmac($request, self::OUTER_ROUTE, false)) return $result;
        $inner = self::decode_outer_envelope($request);
        if (!is_array($inner) || ($inner['action'] ?? '') !== 'rest.call') return $result;
        $params = isset($inner['params']) && is_array($inner['params']) ? $inner['params'] : [];
        $method = isset($params['method']) ? strtoupper((string) $params['method']) : 'GET';
        $route = isset($params['route']) && is_string($params['route']) ? $params['route'] : '';
        if ($method !== 'POST' || $route !== self::MANAGE_ROUTE) return $result;
        self::$request_id = isset($inner['request_id']) && is_string($inner['request_id']) ? trim($inner['request_id']) : '';
        self::$internal_allowed = true;
        return $result;
    }

    public static function internal_permission()
    {
        if (!self::$internal_allowed || !current_user_can('manage_options')) {
            return new WP_Error('takka_bridge_v089_internal_only', 'This route is only callable through the signed Bridge REST proxy.', ['status' => 403]);
        }
        self::$internal_allowed = false;
        return true;
    }

    public static function secure_permission(WP_REST_Request $request)
    {
        if (!self::crypto_available()) {
            return new WP_Error('takka_bridge_secure_crypto_unavailable', 'Required OpenSSL RSA and AES-GCM functions are unavailable.', ['status' => 501]);
        }
        if (!self::valid_hmac($request, self::SECURE_ROUTE, true)) {
            return new WP_Error('takka_bridge_secure_auth', 'Secure Bridge authentication failed.', ['status' => 401]);
        }
        return true;
    }

    public static function dispatch_manage(WP_REST_Request $request)
    {
        $json = $request->get_json_params();
        if (!is_array($json)) return new WP_Error('takka_bridge_v089_json', 'JSON body is required.', ['status' => 400]);
        $action = isset($json['action']) && is_string($json['action']) ? trim($json['action']) : '';
        if (!in_array($action, self::ACTIONS, true)) {
            return new WP_Error('takka_bridge_v089_unknown_action', 'Unknown or blocked v0.8.9 action.', ['status' => 400, 'action' => $action]);
        }

        try {
            if ($action === 'v089.capabilities') return rest_ensure_response(self::capabilities());
            if ($action === 'secure.public_key') return self::public_key_response();
        } catch (Throwable $e) {
            return new WP_Error('takka_bridge_v089_exception', $e->getMessage(), ['status' => 500, 'type' => get_class($e)]);
        }
        return new WP_Error('takka_bridge_v089_dispatch', 'Dispatch fell through.', ['status' => 500]);
    }

    public static function dispatch_secure(WP_REST_Request $request)
    {
        try {
            $json = $request->get_json_params();
            if (!is_array($json)) return new WP_Error('takka_bridge_secure_json', 'JSON body is required.', ['status' => 400]);

            $protocol = isset($json['protocol']) && is_string($json['protocol']) ? trim($json['protocol']) : '';
            $request_id = isset($json['request_id']) && is_string($json['request_id']) ? trim($json['request_id']) : '';
            $server_key_id = isset($json['server_key_id']) && is_string($json['server_key_id']) ? trim($json['server_key_id']) : '';
            if ($protocol !== self::PROTOCOL) {
                return new WP_Error('takka_bridge_secure_protocol', 'Unsupported secure transport protocol.', ['status' => 400]);
            }
            if ($request_id === '' || strlen($request_id) > 128 || !preg_match('/^[A-Za-z0-9._:-]+$/', $request_id)) {
                return new WP_Error('takka_bridge_secure_request_id', 'request_id is invalid.', ['status' => 400]);
            }

            $keys = self::ensure_server_keys();
            if (is_wp_error($keys)) return $keys;
            if ($server_key_id === '' || !hash_equals($keys['key_id'], $server_key_id)) {
                return new WP_Error('takka_bridge_secure_server_key', 'server_key_id does not match the active secure transport key.', [
                    'status' => 409,
                    'current_server_key_id' => $keys['key_id'],
                ]);
            }

            $reply_public_pem = self::decode_b64_field($json, 'reply_public_key_pem_b64', self::MAX_REPLY_PUBLIC_KEY_BYTES);
            if (is_wp_error($reply_public_pem)) return $reply_public_pem;
            $reply_public = openssl_pkey_get_public($reply_public_pem);
            if ($reply_public === false) {
                return new WP_Error('takka_bridge_secure_reply_key', 'reply_public_key_pem_b64 is not a valid public key.', ['status' => 400]);
            }
            $reply_details = openssl_pkey_get_details($reply_public);
            if (!is_array($reply_details) || ($reply_details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA || (int) ($reply_details['bits'] ?? 0) < 2048) {
                return new WP_Error('takka_bridge_secure_reply_key', 'Reply key must be an RSA public key of at least 2048 bits.', ['status' => 400]);
            }
            $reply_key_sha = hash('sha256', $reply_public_pem);

            $wrapped_key = self::decode_b64_field($json, 'wrapped_key_b64', 1024);
            if (is_wp_error($wrapped_key)) return $wrapped_key;
            $iv = self::decode_b64_field($json, 'iv_b64', 64);
            if (is_wp_error($iv)) return $iv;
            $tag = self::decode_b64_field($json, 'tag_b64', 64);
            if (is_wp_error($tag)) return $tag;
            $ciphertext = self::decode_b64_field($json, 'ciphertext_b64', self::MAX_CIPHERTEXT_BYTES);
            if (is_wp_error($ciphertext)) return $ciphertext;
            if (strlen($iv) !== 12 || strlen($tag) !== 16) {
                return new WP_Error('takka_bridge_secure_gcm_fields', 'AES-GCM IV and tag lengths are invalid.', ['status' => 400]);
            }

            $private = openssl_pkey_get_private($keys['private_pem']);
            if ($private === false) {
                return new WP_Error('takka_bridge_secure_private_key', 'Stored secure transport private key is invalid.', ['status' => 500]);
            }
            $aes_key = '';
            if (!openssl_private_decrypt($wrapped_key, $aes_key, $private, OPENSSL_PKCS1_OAEP_PADDING) || strlen($aes_key) !== 32) {
                return new WP_Error('takka_bridge_secure_unwrap', 'Could not unwrap the request encryption key.', ['status' => 400]);
            }

            $aad = self::request_aad($request_id, $server_key_id, $reply_key_sha);
            $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $aes_key, OPENSSL_RAW_DATA, $iv, $tag, $aad);
            self::memzero($aes_key);
            if (!is_string($plaintext)) {
                return new WP_Error('takka_bridge_secure_decrypt', 'Request ciphertext authentication or decryption failed.', ['status' => 400]);
            }
            if (strlen($plaintext) > self::MAX_REQUEST_PLAINTEXT_BYTES) {
                self::memzero($plaintext);
                return new WP_Error('takka_bridge_secure_request_size', 'Decrypted request exceeds the secure transport size limit.', ['status' => 413]);
            }

            $inner = json_decode($plaintext, true);
            self::memzero($plaintext);
            if (!is_array($inner)) {
                return new WP_Error('takka_bridge_secure_plaintext_json', 'Decrypted request is not valid JSON.', ['status' => 400]);
            }
            if (($inner['request_id'] ?? null) !== $request_id || ($inner['server_key_id'] ?? null) !== $server_key_id || ($inner['reply_public_key_sha256'] ?? null) !== $reply_key_sha) {
                return new WP_Error('takka_bridge_secure_binding', 'Encrypted request metadata does not match the outer secure envelope.', ['status' => 400]);
            }
            $command = isset($inner['command']) && is_array($inner['command']) ? $inner['command'] : null;
            if (!is_array($command)) {
                return new WP_Error('takka_bridge_secure_command', 'Encrypted request is missing command.', ['status' => 400]);
            }

            $inner_result = self::execute_rest_command($command, $request_id);
            $response_plain = wp_json_encode([
                'request_id' => $request_id,
                'server_key_id' => $server_key_id,
                'result' => $inner_result,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($response_plain)) {
                return new WP_Error('takka_bridge_secure_response_json', 'Could not encode encrypted response.', ['status' => 500]);
            }
            if (strlen($response_plain) > self::MAX_RESPONSE_PLAINTEXT_BYTES) {
                $response_plain = wp_json_encode([
                    'request_id' => $request_id,
                    'server_key_id' => $server_key_id,
                    'result' => [
                        'status' => 413,
                        'headers' => [],
                        'data' => [
                            'code' => 'takka_bridge_secure_response_size',
                            'message' => 'Inner response exceeded the secure transport plaintext limit.',
                        ],
                    ],
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }

            $response_key = random_bytes(32);
            $response_iv = random_bytes(12);
            $response_tag = '';
            $response_aad = self::response_aad($request_id, $server_key_id);
            $response_cipher = openssl_encrypt($response_plain, 'aes-256-gcm', $response_key, OPENSSL_RAW_DATA, $response_iv, $response_tag, $response_aad, 16);
            self::memzero($response_plain);
            if (!is_string($response_cipher) || strlen($response_tag) !== 16) {
                self::memzero($response_key);
                return new WP_Error('takka_bridge_secure_response_encrypt', 'Could not encrypt secure response.', ['status' => 500]);
            }
            $response_wrapped_key = '';
            if (!openssl_public_encrypt($response_key, $response_wrapped_key, $reply_public, OPENSSL_PKCS1_OAEP_PADDING)) {
                self::memzero($response_key);
                return new WP_Error('takka_bridge_secure_response_wrap', 'Could not wrap secure response key.', ['status' => 500]);
            }
            self::memzero($response_key);

            return rest_ensure_response([
                'ok' => true,
                'protocol' => self::PROTOCOL,
                'request_id' => $request_id,
                'server_key_id' => $server_key_id,
                'wrapped_key_b64' => base64_encode($response_wrapped_key),
                'iv_b64' => base64_encode($response_iv),
                'tag_b64' => base64_encode($response_tag),
                'ciphertext_b64' => base64_encode($response_cipher),
            ]);
        } catch (Throwable $e) {
            return new WP_Error('takka_bridge_secure_exception', $e->getMessage(), ['status' => 500, 'type' => get_class($e)]);
        }
    }

    public static function annotate_health($response, array $handler, WP_REST_Request $request)
    {
        if ($request->get_route() !== self::HEALTH_ROUTE || is_wp_error($response)) return $response;
        $rest = rest_ensure_response($response);
        $data = $rest->get_data();
        if (!is_array($data)) return $response;
        $data['bridge_version'] = self::VERSION;
        $features = isset($data['features']) && is_array($data['features']) ? $data['features'] : [];
        foreach (['encrypted_git_transport', 'rsa_aes_gcm_secure_envelope', 'encrypted_secure_response'] as $feature) {
            if (!in_array($feature, $features, true)) $features[] = $feature;
        }
        $data['features'] = $features;
        $data['secure_transport_available'] = self::crypto_available();
        $rest->set_data($data);
        return $rest;
    }

    private static function capabilities(): array
    {
        return [
            'version' => self::VERSION,
            'manage_route' => self::MANAGE_ROUTE,
            'secure_route' => self::SECURE_ROUTE,
            'protocol' => self::PROTOCOL,
            'crypto_available' => self::crypto_available(),
            'rsa_bits' => 3072,
            'rsa_padding' => 'OAEP-SHA1',
            'content_cipher' => 'AES-256-GCM',
            'request_plaintext_max_bytes' => self::MAX_REQUEST_PLAINTEXT_BYTES,
            'response_plaintext_max_bytes' => self::MAX_RESPONSE_PLAINTEXT_BYTES,
            'secure_command_types' => ['rest'],
            'actions' => self::ACTIONS,
            'actions_runner_does_not_decrypt' => true,
        ];
    }

    private static function public_key_response()
    {
        if (!self::crypto_available()) {
            return new WP_Error('takka_bridge_secure_crypto_unavailable', 'Required OpenSSL RSA and AES-GCM functions are unavailable.', ['status' => 501]);
        }
        $keys = self::ensure_server_keys();
        if (is_wp_error($keys)) return $keys;
        return rest_ensure_response([
            'protocol' => self::PROTOCOL,
            'server_key_id' => $keys['key_id'],
            'public_key_pem_b64' => base64_encode($keys['public_pem']),
            'rsa_bits' => $keys['bits'],
            'rsa_padding' => 'OAEP-SHA1',
            'content_cipher' => 'AES-256-GCM',
            'created_gmt' => $keys['created_gmt'],
            'request_plaintext_max_bytes' => self::MAX_REQUEST_PLAINTEXT_BYTES,
            'response_plaintext_max_bytes' => self::MAX_RESPONSE_PLAINTEXT_BYTES,
        ]);
    }

    private static function execute_rest_command(array $command, string $request_id): array
    {
        $type = isset($command['type']) && is_string($command['type']) ? trim($command['type']) : '';
        if ($type !== 'rest') {
            return [
                'status' => 400,
                'headers' => [],
                'data' => [
                    'code' => 'takka_bridge_secure_command_type',
                    'message' => 'Secure transport v1 accepts only rest commands.',
                ],
            ];
        }
        $method = isset($command['method']) && is_string($command['method']) ? strtoupper(trim($command['method'])) : 'GET';
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return ['status' => 400, 'headers' => [], 'data' => ['code' => 'takka_bridge_secure_method', 'message' => 'Unsupported REST method.']];
        }
        $route = isset($command['route']) && is_string($command['route']) ? trim($command['route']) : '';
        if ($route === '' || $route[0] !== '/' || strpos($route, '://') !== false || $route === self::SECURE_ROUTE) {
            return ['status' => 400, 'headers' => [], 'data' => ['code' => 'takka_bridge_secure_route', 'message' => 'Invalid or recursive REST route.']];
        }
        $query = isset($command['query']) && is_array($command['query']) ? $command['query'] : [];
        $params = [
            'method' => $method,
            'route' => $route,
            'query' => $query,
        ];
        if (array_key_exists('body', $command)) $params['body'] = $command['body'];
        $inner = [
            'request_id' => $request_id,
            'action' => 'rest.call',
            'params' => $params,
        ];
        return self::execute_inner($inner);
    }

    private static function execute_inner(array $inner): array
    {
        $secret = (string) get_option(self::OPTION_SECRET, '');
        if ($secret === '') {
            return ['status' => 500, 'headers' => [], 'data' => ['code' => 'takka_bridge_secure_secret', 'message' => 'Bridge secret is unavailable.']];
        }
        $payload_json = wp_json_encode($inner, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($payload_json)) {
            return ['status' => 500, 'headers' => [], 'data' => ['code' => 'takka_bridge_secure_inner_json', 'message' => 'Could not encode internal Bridge request.']];
        }
        $envelope = [
            'action' => 'envelope',
            'params' => ['payload_b64' => base64_encode($payload_json)],
        ];
        $body = wp_json_encode($envelope, JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            return ['status' => 500, 'headers' => [], 'data' => ['code' => 'takka_bridge_secure_outer_json', 'message' => 'Could not encode internal Bridge envelope.']];
        }

        $timestamp = (string) time();
        $signature_payload = $timestamp . "\nPOST\n" . self::OUTER_ROUTE . "\n" . hash('sha256', $body);
        $signature = hash_hmac('sha256', $signature_payload, $secret);
        $request = new WP_REST_Request('POST', self::OUTER_ROUTE);
        $request->set_header('content-type', 'application/json');
        $request->set_header('x-takka-timestamp', $timestamp);
        $request->set_header('x-takka-signature', $signature);
        $request->set_body($body);

        $response = rest_do_request($request);
        $rest = rest_ensure_response($response);
        return [
            'status' => $rest->get_status(),
            'headers' => $rest->get_headers(),
            'data' => $rest->get_data(),
        ];
    }

    private static function ensure_server_keys()
    {
        $private_pem = (string) get_option(self::OPTION_PRIVATE_KEY, '');
        $public_pem = (string) get_option(self::OPTION_PUBLIC_KEY, '');
        $created_gmt = (string) get_option(self::OPTION_KEY_CREATED, '');

        if (($private_pem === '') !== ($public_pem === '')) {
            return new WP_Error('takka_bridge_secure_key_state', 'Secure transport key options are incomplete; refusing silent regeneration.', ['status' => 500]);
        }

        if ($private_pem === '') {
            $resource = openssl_pkey_new([
                'private_key_bits' => 3072,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]);
            if ($resource === false) {
                return new WP_Error('takka_bridge_secure_keygen', 'Could not generate RSA secure transport key.', ['status' => 500]);
            }
            $exported = openssl_pkey_export($resource, $generated_private);
            $details = openssl_pkey_get_details($resource);
            if (!$exported || !is_array($details) || !isset($details['key'])) {
                return new WP_Error('takka_bridge_secure_keygen', 'Could not export RSA secure transport key.', ['status' => 500]);
            }
            $private_pem = (string) $generated_private;
            $public_pem = (string) $details['key'];
            $created_gmt = gmdate('Y-m-d H:i:s');
            update_option(self::OPTION_PRIVATE_KEY, $private_pem, false);
            update_option(self::OPTION_PUBLIC_KEY, $public_pem, false);
            update_option(self::OPTION_KEY_CREATED, $created_gmt, false);
        }

        $private = openssl_pkey_get_private($private_pem);
        if ($private === false) {
            return new WP_Error('takka_bridge_secure_private_key', 'Stored secure transport private key is invalid.', ['status' => 500]);
        }
        $details = openssl_pkey_get_details($private);
        if (!is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA || !isset($details['key'])) {
            return new WP_Error('takka_bridge_secure_private_key', 'Stored secure transport key is not RSA.', ['status' => 500]);
        }
        $derived_public = (string) $details['key'];
        if (trim($derived_public) !== trim($public_pem)) {
            return new WP_Error('takka_bridge_secure_key_mismatch', 'Stored secure transport public/private keys do not match.', ['status' => 500]);
        }

        return [
            'private_pem' => $private_pem,
            'public_pem' => $public_pem,
            'key_id' => hash('sha256', $public_pem),
            'bits' => (int) ($details['bits'] ?? 0),
            'created_gmt' => $created_gmt,
        ];
    }

    private static function crypto_available(): bool
    {
        if (!function_exists('openssl_pkey_new') || !function_exists('openssl_pkey_export') || !function_exists('openssl_public_encrypt') || !function_exists('openssl_private_decrypt') || !function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) return false;
        if (!defined('OPENSSL_PKCS1_OAEP_PADDING') || !defined('OPENSSL_KEYTYPE_RSA')) return false;
        $methods = array_map('strtolower', openssl_get_cipher_methods());
        return in_array('aes-256-gcm', $methods, true);
    }

    private static function valid_hmac(WP_REST_Request $request, string $route, bool $set_user): bool
    {
        $secret = (string) get_option(self::OPTION_SECRET, '');
        $user_id = (int) get_option(self::OPTION_USER_ID, 0);
        if ($secret === '' || $user_id < 1 || !user_can($user_id, 'manage_options')) return false;
        $timestamp = trim((string) $request->get_header('x-takka-timestamp'));
        $signature = strtolower(trim((string) $request->get_header('x-takka-signature')));
        if ($timestamp === '' || $signature === '' || !ctype_digit($timestamp)) return false;
        if (abs(time() - (int) $timestamp) > self::MAX_CLOCK_SKEW) return false;
        $payload = $timestamp . "\n" . strtoupper($request->get_method()) . "\n" . $route . "\n" . hash('sha256', (string) $request->get_body());
        $valid = hash_equals(hash_hmac('sha256', $payload, $secret), $signature);
        if ($valid && $set_user) wp_set_current_user($user_id);
        return $valid;
    }

    private static function decode_outer_envelope(WP_REST_Request $request)
    {
        $outer = json_decode((string) $request->get_body(), true);
        if (!is_array($outer) || ($outer['action'] ?? '') !== 'envelope' || !isset($outer['params']['payload_b64'])) return null;
        $decoded = base64_decode((string) $outer['params']['payload_b64'], true);
        if (!is_string($decoded)) return null;
        $inner = json_decode($decoded, true);
        return is_array($inner) ? $inner : null;
    }

    private static function decode_b64_field(array $json, string $field, int $max_bytes)
    {
        $value = isset($json[$field]) && is_string($json[$field]) ? $json[$field] : '';
        if ($value === '') return new WP_Error('takka_bridge_secure_field', $field . ' is required.', ['status' => 400]);
        $decoded = base64_decode($value, true);
        if (!is_string($decoded)) return new WP_Error('takka_bridge_secure_base64', $field . ' is not valid Base64.', ['status' => 400]);
        if (strlen($decoded) > $max_bytes) return new WP_Error('takka_bridge_secure_field_size', $field . ' exceeds its size limit.', ['status' => 413]);
        return $decoded;
    }

    private static function request_aad(string $request_id, string $server_key_id, string $reply_key_sha): string
    {
        return self::PROTOCOL . "\nrequest\n" . $request_id . "\n" . $server_key_id . "\n" . $reply_key_sha;
    }

    private static function response_aad(string $request_id, string $server_key_id): string
    {
        return self::PROTOCOL . "\nresponse\n" . $request_id . "\n" . $server_key_id;
    }

    private static function memzero(&$value): void
    {
        if (is_string($value) && function_exists('sodium_memzero')) sodium_memzero($value);
        else $value = '';
    }
}
