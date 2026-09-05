<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Standard WordPress Abilities API fallback for ChatGPT/app clients.
 *
 * This gives WP Agent Bridge a second, GitHub-independent invocation surface.
 * The ability accepts the same compact command shape used by Direct Runtime and
 * dispatches it through the same signed local Bridge REST surface, preserving
 * the existing guards, request-id idempotency and route behavior.
 */
final class TakKa_WordPress_Bridge_Abilities_Fallback
{
    private const OPTION_BRIDGE_SECRET = 'takka_bridge_secret';

    private const V04_ACTIONS = [
        'v04.capabilities', 'plugin.list', 'plugin.install', 'plugin.activate', 'plugin.deactivate',
        'plugin.update', 'plugin.delete', 'theme.manage.list', 'theme.manage.install', 'theme.manage.activate',
        'theme.manage.update', 'theme.manage.delete', 'theme.file.patch', 'media.upload_base64',
        'cron.schedules', 'cron.schedule', 'cron.run', 'cron.unschedule', 'admin.capabilities', 'admin.run',
    ];

    private const V05_ACTIONS = [
        'v05.capabilities', 'idempotency.status', 'db.search_replace.plan', 'db.search_replace.execute',
        'menu.list', 'menu.get', 'menu.create', 'menu.update', 'menu.delete', 'menu.item.upsert',
        'menu.item.delete', 'menu.locations.set', 'updates.status',
    ];

    private const V06_ACTIONS = [
        'v06.capabilities', 'bridge.self_update.status', 'bridge.self_update.apply', 'bridge.self_update.rollback',
    ];

    public static function init(): void
    {
        if (!function_exists('wp_register_ability') || !function_exists('wp_register_ability_category')) {
            return;
        }

        add_action('wp_abilities_api_categories_init', [self::class, 'register_category']);
        add_action('wp_abilities_api_init', [self::class, 'register_abilities']);
    }

    public static function register_category(): void
    {
        wp_register_ability_category(
            'wp-agent-bridge',
            [
                'label' => __('WP Agent Bridge', 'wp-agent-bridge'),
                'description' => __('Guarded WordPress management abilities exposed by WP Agent Bridge.', 'wp-agent-bridge'),
            ]
        );
    }

    public static function register_abilities(): void
    {
        wp_register_ability(
            'wp-agent-bridge/run-command',
            [
                'label' => __('Run WP Agent Bridge command', 'wp-agent-bridge'),
                'description' => __('Use this when ChatGPT needs to read or modify this WordPress site through WP Agent Bridge and a direct WordPress/MCP app connection is available. It accepts the same command shape as the GitHub Direct Runtime, so GitHub connector availability is not required.', 'wp-agent-bridge'),
                'category' => 'wp-agent-bridge',
                'input_schema' => [
                    'type' => 'object',
                    'required' => ['command'],
                    'properties' => [
                        'command' => [
                            'type' => 'object',
                            'description' => 'WP Agent Bridge runtime command object. Supported types include health, rest, bridge, and site_get.',
                            'additionalProperties' => true,
                        ],
                        'request_id' => [
                            'type' => 'string',
                            'minLength' => 1,
                            'maxLength' => 120,
                            'pattern' => '^[A-Za-z0-9._-]+$',
                            'description' => 'Optional stable request id. If omitted, a UUID-based id is generated.',
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                'output_schema' => [
                    'type' => 'object',
                    'additionalProperties' => true,
                ],
                'execute_callback' => [self::class, 'run_command'],
                'permission_callback' => [self::class, 'can_run'],
                'meta' => [
                    'public' => true,
                    'show_in_rest' => true,
                    'annotations' => [
                        'readonly' => false,
                        'destructive' => true,
                        'idempotent' => false,
                    ],
                ],
            ]
        );

        wp_register_ability(
            'wp-agent-bridge/status',
            [
                'label' => __('Get WP Agent Bridge status', 'wp-agent-bridge'),
                'description' => __('Use this to verify that WP Agent Bridge is installed and that the standard WordPress Abilities API fallback is available. This does not require the GitHub connector.', 'wp-agent-bridge'),
                'category' => 'wp-agent-bridge',
                'output_schema' => [
                    'type' => 'object',
                    'additionalProperties' => true,
                ],
                'execute_callback' => [self::class, 'status'],
                'permission_callback' => [self::class, 'can_run'],
                'meta' => [
                    'public' => true,
                    'show_in_rest' => true,
                    'annotations' => [
                        'readonly' => true,
                        'destructive' => false,
                        'idempotent' => true,
                    ],
                ],
            ]
        );
    }

    public static function can_run(): bool
    {
        return current_user_can('manage_options');
    }

    public static function status(): array
    {
        $connection = TakKa_WordPress_Bridge_Direct_Runtime::connection();
        return [
            'ok' => true,
            'transport' => 'wordpress-abilities-api',
            'github_runtime_optional_for_this_transport' => true,
            'site' => home_url('/'),
            'runtime_connected' => !empty($connection['repository']) && !empty($connection['installation_id']),
            'abilities' => [
                'wp-agent-bridge/status',
                'wp-agent-bridge/run-command',
            ],
        ];
    }

    public static function run_command($input)
    {
        if (!is_array($input) || !isset($input['command']) || !is_array($input['command'])) {
            return new WP_Error('wpab_ability_command', 'A command object is required.', ['status' => 400]);
        }

        $command = $input['command'];
        $request_id = isset($input['request_id']) ? (string) $input['request_id'] : '';
        if ($request_id === '') {
            $request_id = 'ability-' . str_replace('-', '', wp_generate_uuid4());
        }
        if (!preg_match('/^[A-Za-z0-9._-]{1,120}$/', $request_id)) {
            return new WP_Error('wpab_ability_request_id', 'Unsafe request_id.', ['status' => 400]);
        }

        $result = self::execute_command($command, $request_id);
        return self::sanitize_result($result);
    }

    private static function execute_command(array $command, string $request_id): array
    {
        $type = isset($command['type']) ? (string) $command['type'] : 'rest';

        if ($type === 'health') {
            return self::local_bridge_request('GET', '/takka-bridge/v1/health', null, $request_id, false);
        }

        if ($type === 'rest') {
            $method = strtoupper((string) ($command['method'] ?? 'GET'));
            $route = isset($command['route']) ? (string) $command['route'] : '';
            if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true) || !self::valid_local_route($route)) {
                return self::error_result('Invalid REST command.');
            }
            $params = [
                'method' => $method,
                'route' => $route,
                'query' => isset($command['query']) && is_array($command['query']) ? $command['query'] : [],
            ];
            if (array_key_exists('body', $command)) {
                $params['body'] = $command['body'];
            }
            return self::local_bridge_request(
                'POST',
                '/takka-bridge/v1/execute',
                ['action' => 'rest.call', 'params' => $params],
                $request_id,
                true
            );
        }

        if ($type === 'bridge') {
            $action = isset($command['action']) ? (string) $command['action'] : '';
            if ($action === '') {
                return self::error_result('Bridge command requires action.');
            }
            $params = isset($command['params']) && is_array($command['params']) ? $command['params'] : [];
            if (in_array($action, self::V06_ACTIONS, true)) {
                return self::action_request('/takka-bridge/v1/v06', $action, $params, $request_id);
            }
            if (in_array($action, self::V05_ACTIONS, true)) {
                return self::action_request('/takka-bridge/v1/v05', $action, $params, $request_id);
            }
            if (in_array($action, self::V04_ACTIONS, true)) {
                return self::action_request('/takka-bridge/v1/manage', $action, $params, $request_id);
            }
            return self::local_bridge_request(
                'POST',
                '/takka-bridge/v1/execute',
                ['action' => $action, 'params' => $params],
                $request_id,
                true
            );
        }

        if ($type === 'site_get') {
            $path = isset($command['path']) ? (string) $command['path'] : '/';
            if ($path === '' || $path[0] !== '/' || strpos($path, '://') !== false || strpos($path, "\0") !== false) {
                return self::error_result('Invalid site_get path.');
            }
            $response = wp_safe_remote_get(home_url($path), [
                'timeout' => 30,
                'redirection' => 3,
                'headers' => ['User-Agent' => 'WP-Agent-Bridge-Abilities/1.0'],
            ]);
            if (is_wp_error($response)) {
                return self::error_result($response->get_error_message());
            }
            $status = (int) wp_remote_retrieve_response_code($response);
            $body = (string) wp_remote_retrieve_body($response);
            if (strlen($body) > 500000) {
                $body = substr($body, 0, 500000);
            }
            return [
                'ok' => $status >= 200 && $status < 400,
                'status' => $status,
                'statusText' => wp_remote_retrieve_response_message($response),
                'url' => home_url($path),
                'data' => ['body' => $body],
            ];
        }

        return self::error_result('Unsupported command type: ' . $type);
    }

    private static function action_request(string $route, string $action, array $params, string $request_id): array
    {
        $json = wp_json_encode([
            'request_id' => $request_id,
            'action' => $action,
            'params' => $params,
        ], JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return self::error_result('Could not encode action payload.');
        }
        return self::local_bridge_request('POST', $route, ['payload_b64' => base64_encode($json)], $request_id, false);
    }

    private static function local_bridge_request(string $method, string $route, ?array $body, string $request_id, bool $execute_envelope): array
    {
        $secret = (string) get_option(self::OPTION_BRIDGE_SECRET, '');
        if (strlen($secret) < 32 || !self::valid_local_route($route)) {
            return self::error_result('Local Bridge credentials or route are invalid.');
        }

        $transport = $body;
        if ($execute_envelope && $body !== null) {
            $payload = ['request_id' => $request_id];
            foreach ($body as $key => $value) {
                $payload[$key] = $value;
            }
            $payload_json = wp_json_encode($payload, JSON_UNESCAPED_SLASHES);
            if (!is_string($payload_json)) {
                return self::error_result('Could not encode local Bridge envelope.');
            }
            $transport = [
                'action' => 'envelope',
                'params' => ['payload_b64' => base64_encode($payload_json)],
            ];
        }

        $body_text = $transport === null ? '' : wp_json_encode($transport, JSON_UNESCAPED_SLASHES);
        if (!is_string($body_text)) {
            return self::error_result('Could not encode local Bridge request.');
        }

        $timestamp = (string) time();
        $signature_payload = $timestamp . "\n" . strtoupper($method) . "\n" . $route . "\n" . hash('sha256', $body_text);
        $signature = hash_hmac('sha256', $signature_payload, $secret);

        $request = new WP_REST_Request(strtoupper($method), $route);
        $request->set_header('Accept', 'application/json');
        $request->set_header('X-TakKa-Timestamp', $timestamp);
        $request->set_header('X-TakKa-Signature', $signature);
        if ($transport !== null && !in_array(strtoupper($method), ['GET', 'HEAD'], true)) {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body($body_text);
        }

        $response = rest_do_request($request);
        if (is_wp_error($response)) {
            return self::error_result($response->get_error_message());
        }

        $status = (int) $response->get_status();
        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'statusText' => self::status_text($status),
            'url' => home_url('/wp-json' . $route),
            'data' => $response->get_data(),
        ];
    }

    private static function valid_local_route(string $route): bool
    {
        return $route !== ''
            && $route[0] === '/'
            && strpos($route, '://') === false
            && strpos($route, '..') === false
            && strlen($route) <= 300;
    }

    private static function error_result(string $message): array
    {
        return [
            'ok' => false,
            'status' => 500,
            'statusText' => $message,
            'data' => ['error' => $message],
        ];
    }

    private static function sanitize_result($value, string $key = '')
    {
        $sensitive = ['token', 'authorization', 'secret', 'password', 'private_key', 'pem', 'cookie', 'nonce'];
        $lower = strtolower($key);
        foreach ($sensitive as $needle) {
            if ($lower !== '' && strpos($lower, $needle) !== false) {
                return '[redacted]';
            }
        }
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $child_key => $child_value) {
                $clean[$child_key] = self::sanitize_result($child_value, (string) $child_key);
            }
            return $clean;
        }
        if (is_string($value) && strlen($value) > 1000000) {
            return substr($value, 0, 1000000) . "\n[truncated]";
        }
        return $value;
    }

    private static function status_text(int $status): string
    {
        $texts = [
            200 => 'OK', 201 => 'Created', 202 => 'Accepted', 204 => 'No Content',
            400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found',
            409 => 'Conflict', 410 => 'Gone', 413 => 'Payload Too Large', 422 => 'Unprocessable Entity',
            429 => 'Too Many Requests', 500 => 'Internal Server Error', 503 => 'Service Unavailable',
        ];
        return $texts[$status] ?? ('HTTP ' . $status);
    }
}
