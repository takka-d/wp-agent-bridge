<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * v0.9.8 read-only batching for the Direct Runtime.
 *
 * Multiple allowlisted read/search/inspect/status operations can run behind one
 * pending command and one signed webhook. No write, mutation, preview creation,
 * upload, rollback, or generic arbitrary REST operation is exposed here.
 */
final class TakKa_WordPress_Bridge_V098_Read_Batch
{
    private const VERSION = '0.9.8.1';
    private const NS = 'takka-v098/v1';
    private const ROUTE = '/takka-v098/v1/manage';
    private const OUTER = '/takka-bridge/v1/execute';
    private const HEALTH = '/takka-bridge/v1/health';
    private const SECRET = 'takka_bridge_secret';
    private const USER = 'takka_bridge_user_id';
    private const SKEW = 300;

    private const MAX_OPERATIONS = 12;
    private const MAX_OPERATION_PARAMS_BYTES = 262144;
    private const MAX_RESPONSE_BYTES = 1048576;
    private const MAX_TOTAL_MS = 20000;

    private static $allowed = false;
    private static $request_id = '';

    private const ACTIONS = [
        'v098.capabilities',
        'readonly.batch',
    ];

    /** Routes that accept the Bridge payload_b64 action envelope directly. */
    private const DIRECT_ACTION_ROUTES = [
        'v04.capabilities' => '/takka-bridge/v1/manage',
        'plugin.list' => '/takka-bridge/v1/manage',
        'theme.manage.list' => '/takka-bridge/v1/manage',
        'cron.schedules' => '/takka-bridge/v1/manage',
        'admin.capabilities' => '/takka-bridge/v1/manage',
        'v05.capabilities' => '/takka-bridge/v1/v05',
        'idempotency.status' => '/takka-bridge/v1/v05',
        'menu.list' => '/takka-bridge/v1/v05',
        'menu.get' => '/takka-bridge/v1/v05',
        'updates.status' => '/takka-bridge/v1/v05',
        'v06.capabilities' => '/takka-bridge/v1/v06',
        'bridge.self_update.status' => '/takka-bridge/v1/v06',
    ];

    /** Internal REST surfaces callable only through the signed Bridge proxy. */
    private const REST_ACTION_ROUTES = [
        'post.content.inspect' => '/takka-v084/v1/manage',
        'post.content.search' => '/takka-v084/v1/manage',
        'post.content.read.range' => '/takka-v084/v1/manage',
        'v097.capabilities' => '/takka-v097/v1/manage',
        'workspace.list' => '/takka-v097/v1/manage',
        'workspace.file.get' => '/takka-v097/v1/manage',
        'workspace.file.read.range' => '/takka-v097/v1/manage',
        'workspace.file.search' => '/takka-v097/v1/manage',
        'workspace.file.diff' => '/takka-v097/v1/manage',
        'workspace.snapshot.list' => '/takka-v097/v1/manage',
        'theme.files.list' => '/takka-v096/v1/theme-files',
        'theme.files.search' => '/takka-v096/v1/theme-files',
        'theme.file.read.many' => '/takka-v096/v1/theme-files',
        'theme.file.outline' => '/takka-v095/v1/manage',
        'theme.file.read.range' => '/takka-v095/v1/manage',
        'page.html.inspect' => '/takka-v095/v1/manage',
        'media.file.inspect' => '/takka-v094/v1/manage',
        'site.icon.get' => '/takka-v096/v1/manage',
        'media.upload.capabilities' => '/takka-v096/v1/manage',
    ];

    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'register']);
        add_filter('rest_pre_dispatch', [self::class, 'prepare'], 69, 3);
        add_filter('rest_request_after_callbacks', [self::class, 'health'], 407, 3);
    }

    public static function register(): void
    {
        register_rest_route(self::NS, '/manage', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'dispatch'],
            'permission_callback' => [self::class, 'permission'],
        ]);
    }

    public static function prepare($result, WP_REST_Server $server, WP_REST_Request $request)
    {
        if ($result !== null
            || $request->get_route() !== self::OUTER
            || strtoupper($request->get_method()) !== 'POST'
            || !self::valid_hmac($request)) {
            return $result;
        }
        $inner = self::inner($request);
        if (!is_array($inner) || ($inner['action'] ?? '') !== 'rest.call') {
            return $result;
        }
        $params = isset($inner['params']) && is_array($inner['params']) ? $inner['params'] : [];
        if (strtoupper((string) ($params['method'] ?? 'GET')) === 'POST'
            && (string) ($params['route'] ?? '') === self::ROUTE) {
            self::$allowed = true;
            self::$request_id = isset($inner['request_id']) && is_string($inner['request_id'])
                ? trim($inner['request_id'])
                : '';
        }
        return $result;
    }

    public static function permission()
    {
        if (!self::$allowed || !current_user_can('manage_options')) {
            return new WP_Error(
                'takka_bridge_v098_internal_only',
                'This route is only callable through the signed Bridge REST proxy.',
                ['status' => 403]
            );
        }
        self::$allowed = false;
        return true;
    }

    public static function dispatch(WP_REST_Request $request)
    {
        $json = $request->get_json_params();
        if (!is_array($json)) {
            return new WP_Error('takka_bridge_v098_json', 'JSON body is required.', ['status' => 400]);
        }
        $action = is_string($json['action'] ?? null) ? trim($json['action']) : '';
        $params = isset($json['params']) && is_array($json['params']) ? $json['params'] : [];
        if (!in_array($action, self::ACTIONS, true)) {
            return new WP_Error('takka_bridge_v098_action', 'Unknown or blocked v0.9.8 action.', [
                'status' => 400,
                'action' => $action,
            ]);
        }
        if ($action === 'v098.capabilities') {
            return rest_ensure_response(self::capabilities());
        }
        return self::readonly_batch($params);
    }

    public static function health($response, array $handler, WP_REST_Request $request)
    {
        if ($request->get_route() !== self::HEALTH || is_wp_error($response)) {
            return $response;
        }
        $rest = rest_ensure_response($response);
        $data = $rest->get_data();
        if (!is_array($data)) {
            return $response;
        }
        $features = isset($data['features']) && is_array($data['features']) ? $data['features'] : [];
        if (!in_array('readonly_batch', $features, true)) {
            $features[] = 'readonly_batch';
        }
        $data['features'] = $features;
        $data['readonly_batch'] = [
            'version' => self::VERSION,
            'route' => self::ROUTE,
            'action' => 'readonly.batch',
            'max_operations' => self::MAX_OPERATIONS,
        ];
        $rest->set_data($data);
        return $rest;
    }

    private static function capabilities(): array
    {
        return [
            'version' => self::VERSION,
            'internal_route' => self::ROUTE,
            'action' => 'readonly.batch',
            'limits' => [
                'max_operations' => self::MAX_OPERATIONS,
                'max_operation_params_bytes' => self::MAX_OPERATION_PARAMS_BYTES,
                'max_response_bytes' => self::MAX_RESPONSE_BYTES,
                'max_total_ms' => self::MAX_TOTAL_MS,
            ],
            'direct_actions' => array_keys(self::DIRECT_ACTION_ROUTES),
            'rest_actions' => array_keys(self::REST_ACTION_ROUTES),
            'mutation_actions_allowed' => false,
            'arbitrary_rest_allowed' => false,
        ];
    }

    private static function readonly_batch(array $params)
    {
        $operations = isset($params['operations']) && is_array($params['operations'])
            ? array_values($params['operations'])
            : [];
        if (!$operations || count($operations) > self::MAX_OPERATIONS) {
            return new WP_Error('takka_bridge_read_batch_operations', 'operations must contain between 1 and ' . self::MAX_OPERATIONS . ' items.', [
                'status' => 400,
                'max_operations' => self::MAX_OPERATIONS,
            ]);
        }

        $prepared = [];
        foreach ($operations as $index => $operation) {
            $valid = self::validate_operation($operation, (int) $index);
            if (is_wp_error($valid)) {
                return $valid;
            }
            $prepared[] = $valid;
        }

        $continue_on_error = !array_key_exists('continue_on_error', $params) || !empty($params['continue_on_error']);
        $started = microtime(true);
        $results = [];
        $failed = 0;
        $stopped_reason = null;

        foreach ($prepared as $index => $operation) {
            $elapsed_ms = (int) round((microtime(true) - $started) * 1000);
            if ($elapsed_ms >= self::MAX_TOTAL_MS) {
                $stopped_reason = 'time_limit';
                break;
            }

            $op_started = microtime(true);
            $subrequest_id = self::subrequest_id(self::$request_id, (int) $index, $operation['action']);
            $result = self::execute_read($operation['action'], $operation['params'], $subrequest_id);
            $op_ms = (int) round((microtime(true) - $op_started) * 1000);
            $ok = !empty($result['ok']);
            if (!$ok) {
                $failed++;
            }

            $entry = [
                'index' => (int) $index,
                'label' => $operation['label'],
                'action' => $operation['action'],
                'ok' => $ok,
                'status' => isset($result['status']) ? (int) $result['status'] : null,
                'duration_ms' => $op_ms,
                'result' => $result,
            ];
            $candidate = $results;
            $candidate[] = $entry;
            $encoded = wp_json_encode($candidate, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($encoded) || strlen($encoded) > self::MAX_RESPONSE_BYTES) {
                $results[] = [
                    'index' => (int) $index,
                    'label' => $operation['label'],
                    'action' => $operation['action'],
                    'ok' => false,
                    'status' => 413,
                    'duration_ms' => $op_ms,
                    'result_omitted' => true,
                    'error' => 'Batch response size limit reached after this read-only operation.',
                ];
                $failed++;
                $stopped_reason = 'response_limit';
                break;
            }
            $results = $candidate;

            if (!$ok && !$continue_on_error) {
                $stopped_reason = 'operation_error';
                break;
            }
            if ((int) round((microtime(true) - $started) * 1000) >= self::MAX_TOTAL_MS
                && $index + 1 < count($prepared)) {
                $stopped_reason = 'time_limit';
                break;
            }
        }

        $completed = count($results);
        $all_completed = $completed === count($prepared) && $stopped_reason === null;
        $all_ok = $all_completed && $failed === 0;
        $data = [
            'ok' => $all_ok,
            'mode' => 'read-only',
            'operation_count' => count($prepared),
            'completed_count' => $completed,
            'failed_count' => $failed,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'stopped_reason' => $stopped_reason,
            'results' => $results,
        ];
        return new WP_REST_Response($data, $all_ok ? 200 : 207);
    }

    private static function validate_operation($operation, int $index)
    {
        if (!is_array($operation)) {
            return new WP_Error('takka_bridge_read_batch_operation', 'Each operation must be an object.', [
                'status' => 400,
                'index' => $index,
            ]);
        }
        $action = is_string($operation['action'] ?? null) ? trim($operation['action']) : '';
        if ($action === ''
            || (!isset(self::DIRECT_ACTION_ROUTES[$action]) && !isset(self::REST_ACTION_ROUTES[$action]))) {
            return new WP_Error('takka_bridge_read_batch_blocked_action', 'Operation action is not on the strict read-only allowlist.', [
                'status' => 400,
                'index' => $index,
                'action' => $action,
            ]);
        }
        $params = isset($operation['params']) && is_array($operation['params']) ? $operation['params'] : [];
        $params_json = wp_json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($params_json) || strlen($params_json) > self::MAX_OPERATION_PARAMS_BYTES) {
            return new WP_Error('takka_bridge_read_batch_params', 'Operation params exceed the per-operation size limit.', [
                'status' => 413,
                'index' => $index,
                'max_bytes' => self::MAX_OPERATION_PARAMS_BYTES,
            ]);
        }
        $label = null;
        if (array_key_exists('label', $operation)) {
            if (!is_string($operation['label']) || strlen($operation['label']) > 80 || preg_match('/[\x00-\x1F\x7F]/', $operation['label'])) {
                return new WP_Error('takka_bridge_read_batch_label', 'Operation label is invalid.', [
                    'status' => 400,
                    'index' => $index,
                ]);
            }
            $label = $operation['label'];
        }
        return ['action' => $action, 'params' => $params, 'label' => $label];
    }

    private static function execute_read(string $action, array $params, string $request_id): array
    {
        if (isset(self::DIRECT_ACTION_ROUTES[$action])) {
            return self::action_request(self::DIRECT_ACTION_ROUTES[$action], $action, $params, $request_id);
        }
        $route = self::REST_ACTION_ROUTES[$action];
        return self::signed_local_request('POST', self::OUTER, [
            'action' => 'rest.call',
            'params' => [
                'method' => 'POST',
                'route' => $route,
                'query' => [],
                'body' => ['action' => $action, 'params' => $params],
            ],
        ], $request_id, true);
    }

    private static function action_request(string $route, string $action, array $params, string $request_id): array
    {
        $json = wp_json_encode([
            'request_id' => $request_id,
            'action' => $action,
            'params' => $params,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return self::error_result('Could not encode read-only action payload.');
        }
        return self::signed_local_request('POST', $route, ['payload_b64' => base64_encode($json)], $request_id, false);
    }

    private static function signed_local_request(string $method, string $route, ?array $body, string $request_id, bool $execute_envelope): array
    {
        $secret = (string) get_option(self::SECRET, '');
        if (strlen($secret) < 32 || !self::valid_local_route($route)) {
            return self::error_result('Local Bridge credentials or route are invalid.');
        }

        $transport = $body;
        if ($execute_envelope && $body !== null) {
            $payload = ['request_id' => $request_id];
            foreach ($body as $key => $value) {
                $payload[$key] = $value;
            }
            $payload_json = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($payload_json)) {
                return self::error_result('Could not encode nested Bridge envelope.');
            }
            $transport = [
                'action' => 'envelope',
                'params' => ['payload_b64' => base64_encode($payload_json)],
            ];
        }
        $body_text = $transport === null ? '' : wp_json_encode($transport, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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

    private static function subrequest_id(string $request_id, int $index, string $action): string
    {
        return 'readbatch-' . substr(hash('sha256', $request_id . '|' . $index . '|' . $action), 0, 40);
    }

    private static function error_result(string $message): array
    {
        return ['ok' => false, 'status' => 500, 'statusText' => $message, 'data' => ['error' => $message]];
    }

    private static function valid_local_route(string $route): bool
    {
        return $route !== ''
            && $route[0] === '/'
            && strpos($route, '://') === false
            && strpos($route, '..') === false
            && strlen($route) <= 300;
    }

    private static function status_text(int $status): string
    {
        $texts = [
            200 => 'OK', 201 => 'Created', 202 => 'Accepted', 204 => 'No Content', 207 => 'Multi-Status',
            400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found',
            409 => 'Conflict', 410 => 'Gone', 413 => 'Payload Too Large', 422 => 'Unprocessable Entity',
            429 => 'Too Many Requests', 500 => 'Internal Server Error', 503 => 'Service Unavailable',
        ];
        return $texts[$status] ?? ('HTTP ' . $status);
    }

    private static function valid_hmac(WP_REST_Request $request): bool
    {
        $secret = (string) get_option(self::SECRET, '');
        $user_id = (int) get_option(self::USER, 0);
        if ($secret === '' || $user_id < 1 || !user_can($user_id, 'manage_options')) {
            return false;
        }
        $timestamp = trim((string) $request->get_header('x-takka-timestamp'));
        $signature = strtolower(trim((string) $request->get_header('x-takka-signature')));
        if ($timestamp === '' || $signature === '' || !ctype_digit($timestamp)
            || abs(time() - (int) $timestamp) > self::SKEW) {
            return false;
        }
        $payload = $timestamp . "\nPOST\n" . self::OUTER . "\n" . hash('sha256', (string) $request->get_body());
        return hash_equals(hash_hmac('sha256', $payload, $secret), $signature);
    }

    private static function inner(WP_REST_Request $request)
    {
        $outer = json_decode((string) $request->get_body(), true);
        if (!is_array($outer)
            || ($outer['action'] ?? '') !== 'envelope'
            || !isset($outer['params']['payload_b64'])) {
            return null;
        }
        $decoded = base64_decode((string) $outer['params']['payload_b64'], true);
        if (!is_string($decoded)) {
            return null;
        }
        $inner = json_decode($decoded, true);
        return is_array($inner) ? $inner : null;
    }
}
