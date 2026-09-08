<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * v0.9.9 deterministic high-level operation router.
 *
 * ChatGPT only needs one fixed internal route for the common WP Agent Bridge
 * workflows. Operation names are allowlisted and translated here into the
 * existing guarded actions/core REST routes, so callers do not have to guess
 * versioned routes or embed query strings in route paths.
 */
final class TakKa_WordPress_Bridge_V099_Operations
{
    private const VERSION = '0.9.9';
    private const NS = 'takka-v099/v1';
    private const ROUTE = '/takka-v099/v1/operate';
    private const OUTER = '/takka-bridge/v1/execute';
    private const SECRET = 'takka_bridge_secret';
    private const USER = 'takka_bridge_user_id';
    private const SKEW = 300;

    private static $allowed = false;
    private static $request_id = '';

    private const DIRECT_ACTIONS = [
        'plugin.list' => ['/takka-bridge/v1/manage', 'plugin.list'],
        'media.upload.inline' => ['/takka-bridge/v1/manage', 'media.upload_base64'],
        'self_update.status' => ['/takka-bridge/v1/v06', 'bridge.self_update.status'],
        'self_update.apply' => ['/takka-bridge/v1/v06', 'bridge.self_update.apply'],
        'self_update.rollback' => ['/takka-bridge/v1/v06', 'bridge.self_update.rollback'],
    ];

    private const REST_ACTIONS = [
        'post.content.inspect' => ['/takka-v084/v1/manage', 'post.content.inspect'],
        'post.content.search' => ['/takka-v084/v1/manage', 'post.content.search'],
        'post.content.read_range' => ['/takka-v084/v1/manage', 'post.content.read.range'],
        'post.content.patch_preview' => ['/takka-v084/v1/manage', 'post.content.patch.preview'],
        'post.content.patch_apply' => ['/takka-v084/v1/manage', 'post.content.patch.apply'],

        'diagnostics.http_probe' => ['/takka-v094/v1/manage', 'http.probe'],
        'diagnostics.http_probe_batch' => ['/takka-v094/v1/manage', 'http.probe.batch'],
        'media.inspect' => ['/takka-v094/v1/manage', 'media.file.inspect'],

        'theme.file.outline' => ['/takka-v095/v1/manage', 'theme.file.outline'],
        'theme.file.read_range' => ['/takka-v095/v1/manage', 'theme.file.read.range'],
        'page.html.inspect' => ['/takka-v095/v1/manage', 'page.html.inspect'],

        'theme.files.list' => ['/takka-v096/v1/theme-files', 'theme.files.list'],
        'theme.files.search' => ['/takka-v096/v1/theme-files', 'theme.files.search'],
        'theme.file.read_many' => ['/takka-v096/v1/theme-files', 'theme.file.read.many'],
        'site.icon.get' => ['/takka-v096/v1/manage', 'site.icon.get'],
        'site.icon.set' => ['/takka-v096/v1/manage', 'site.icon.set'],
        'site.icon.clear' => ['/takka-v096/v1/manage', 'site.icon.clear'],
        'media.upload.capabilities' => ['/takka-v096/v1/manage', 'media.upload.capabilities'],

        'workspace.list' => ['/takka-v097/v1/manage', 'workspace.list'],
        'workspace.file.get' => ['/takka-v097/v1/manage', 'workspace.file.get'],
        'workspace.file.read_range' => ['/takka-v097/v1/manage', 'workspace.file.read.range'],
        'workspace.file.search' => ['/takka-v097/v1/manage', 'workspace.file.search'],
        'workspace.file.write' => ['/takka-v097/v1/manage', 'workspace.file.write'],
        'workspace.file.patch' => ['/takka-v097/v1/manage', 'workspace.file.patch'],
        'workspace.file.delete' => ['/takka-v097/v1/manage', 'workspace.file.delete'],
        'workspace.file.diff' => ['/takka-v097/v1/manage', 'workspace.file.diff'],
        'workspace.snapshot.create' => ['/takka-v097/v1/manage', 'workspace.snapshot.create'],
        'workspace.snapshot.list' => ['/takka-v097/v1/manage', 'workspace.snapshot.list'],
        'workspace.snapshot.rollback' => ['/takka-v097/v1/manage', 'workspace.snapshot.rollback'],

        'readonly.batch' => ['/takka-v098/v1/manage', 'readonly.batch'],
    ];

    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'register']);
        add_filter('rest_pre_dispatch', [self::class, 'prepare'], 70, 3);
    }

    public static function register(): void
    {
        register_rest_route(self::NS, '/operate', [
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
        if (strtoupper((string) ($params['method'] ?? 'GET')) !== 'POST'
            || (string) ($params['route'] ?? '') !== self::ROUTE) {
            return $result;
        }
        self::$request_id = isset($inner['request_id']) && is_string($inner['request_id'])
            ? trim($inner['request_id'])
            : '';
        self::$allowed = true;
        return $result;
    }

    public static function permission()
    {
        if (!self::$allowed || !current_user_can('manage_options')) {
            return new WP_Error(
                'takka_bridge_v099_internal_only',
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
            return new WP_Error('takka_bridge_v099_json', 'JSON body is required.', ['status' => 400]);
        }
        $operation = is_string($json['operation'] ?? null) ? trim($json['operation']) : '';
        $params = isset($json['params']) && is_array($json['params']) ? $json['params'] : [];
        if ($operation === 'catalog') {
            return rest_ensure_response(self::catalog());
        }
        if ($operation === '') {
            return new WP_Error('takka_bridge_v099_operation', 'operation is required.', ['status' => 400]);
        }

        try {
            $result = self::execute_operation($operation, $params);
        } catch (Throwable $e) {
            return new WP_Error('takka_bridge_v099_exception', $e->getMessage(), [
                'status' => 500,
                'type' => get_class($e),
                'operation' => $operation,
            ]);
        }
        if (is_wp_error($result)) {
            return $result;
        }
        return rest_ensure_response([
            'ok' => !empty($result['ok']),
            'operation' => $operation,
            'status' => isset($result['status']) ? (int) $result['status'] : null,
            'result' => $result,
        ]);
    }

    public static function catalog(): array
    {
        return [
            'version' => self::VERSION,
            'route' => self::ROUTE,
            'command_shape' => [
                'type' => 'rest',
                'method' => 'POST',
                'route' => self::ROUTE,
                'body' => [
                    'operation' => '<operation>',
                    'params' => '<object>',
                ],
            ],
            'operations' => array_values(array_merge(
                ['health', 'post.get', 'post.update', 'media.delete'],
                array_keys(self::DIRECT_ACTIONS),
                array_keys(self::REST_ACTIONS)
            )),
            'arbitrary_route_allowed' => false,
            'arbitrary_action_allowed' => false,
            'query_must_be_object' => true,
            'post_update_fields_must_be_object' => true,
        ];
    }

    private static function execute_operation(string $operation, array $params)
    {
        if ($operation === 'health') {
            return self::signed_local_request('GET', '/takka-bridge/v1/health', null, false);
        }
        if ($operation === 'post.get') {
            $post_id = self::positive_id($params, 'post_id');
            if (is_wp_error($post_id)) return $post_id;
            $query = self::query_params($params);
            if (is_wp_error($query)) return $query;
            return self::core_rest('GET', '/wp/v2/posts/' . $post_id, $query, null);
        }
        if ($operation === 'post.update') {
            $post_id = self::positive_id($params, 'post_id');
            if (is_wp_error($post_id)) return $post_id;
            $fields = isset($params['fields']) && is_array($params['fields']) ? $params['fields'] : null;
            if ($fields === null || !$fields) {
                return new WP_Error('takka_bridge_v099_fields', 'post.update requires a non-empty fields object.', ['status' => 400]);
            }
            return self::core_rest('POST', '/wp/v2/posts/' . $post_id, [], $fields);
        }
        if ($operation === 'media.delete') {
            $attachment_id = self::positive_id($params, 'attachment_id');
            if (is_wp_error($attachment_id)) return $attachment_id;
            $force = !array_key_exists('force', $params) || !empty($params['force']);
            return self::core_rest('DELETE', '/wp/v2/media/' . $attachment_id, ['force' => $force ? 'true' : 'false'], null);
        }
        if (isset(self::DIRECT_ACTIONS[$operation])) {
            [$route, $action] = self::DIRECT_ACTIONS[$operation];
            return self::action_request($route, $action, $params);
        }
        if (isset(self::REST_ACTIONS[$operation])) {
            [$route, $action] = self::REST_ACTIONS[$operation];
            return self::rest_action_request($route, $action, $params);
        }
        return new WP_Error('takka_bridge_v099_unknown_operation', 'Unknown or blocked operation.', [
            'status' => 400,
            'operation' => $operation,
        ]);
    }

    private static function core_rest(string $method, string $route, array $query, $body)
    {
        $params = [
            'method' => $method,
            'route' => $route,
            'query' => $query,
        ];
        if ($body !== null) {
            $params['body'] = $body;
        }
        $inner = [
            'action' => 'rest.call',
            'params' => $params,
        ];
        return self::signed_local_request(
            'POST',
            self::OUTER,
            $inner,
            true,
            self::child_request_id('rest:' . strtoupper($method) . ':' . $route, $params)
        );
    }

    private static function action_request(string $route, string $action, array $params)
    {
        $json = wp_json_encode([
            'request_id' => self::child_request_id('action:' . $route . ':' . $action, $params),
            'action' => $action,
            'params' => $params,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return new WP_Error('takka_bridge_v099_encode', 'Could not encode action payload.', ['status' => 500]);
        }
        return self::signed_local_request('POST', $route, ['payload_b64' => base64_encode($json)], false);
    }

    private static function rest_action_request(string $route, string $action, array $params)
    {
        return self::core_rest('POST', $route, [], [
            'action' => $action,
            'params' => $params,
        ]);
    }

    private static function query_params(array $params)
    {
        if (!array_key_exists('query', $params)) {
            return [];
        }
        if (!is_array($params['query'])) {
            return new WP_Error('takka_bridge_v099_query', 'query must be an object.', ['status' => 400]);
        }
        return $params['query'];
    }

    private static function positive_id(array $params, string $key)
    {
        $value = isset($params[$key]) ? (int) $params[$key] : 0;
        if ($value < 1) {
            return new WP_Error('takka_bridge_v099_id', $key . ' must be a positive integer.', ['status' => 400, 'key' => $key]);
        }
        return $value;
    }

    /**
     * Nested Bridge calls must not reuse the parent request_id while that parent
     * is still locked by the idempotency layer. Derive a stable child id so a
     * retry of the same high-level operation remains deterministic without
     * colliding with the in-flight parent payload.
     */
    private static function child_request_id(string $scope, array $payload): string
    {
        if (self::$request_id === '') {
            return '';
        }
        $encoded = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            $encoded = '';
        }
        $prefix = substr(self::$request_id, 0, 72);
        return $prefix . ':v099:' . substr(hash('sha256', $scope . "\n" . $encoded), 0, 32);
    }

    private static function signed_local_request(
        string $method,
        string $route,
        ?array $body,
        bool $execute_envelope,
        ?string $request_id = null
    ): array {
        $secret = (string) get_option(self::SECRET, '');
        if (strlen($secret) < 32 || !self::valid_local_route($route)) {
            return self::error_result(500, 'Local Bridge credentials or route are invalid.');
        }

        $transport = $body;
        if ($execute_envelope && $body !== null) {
            $payload = ['request_id' => $request_id === null ? self::$request_id : $request_id];
            foreach ($body as $key => $value) {
                $payload[$key] = $value;
            }
            $payload_json = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($payload_json)) {
                return self::error_result(500, 'Could not encode nested Bridge envelope.');
            }
            $transport = [
                'action' => 'envelope',
                'params' => ['payload_b64' => base64_encode($payload_json)],
            ];
        }
        $body_text = $transport === null ? '' : wp_json_encode($transport, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($body_text)) {
            return self::error_result(500, 'Could not encode local Bridge request.');
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
            return self::error_result(500, $response->get_error_message());
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

    private static function error_result(int $status, string $message): array
    {
        return [
            'ok' => false,
            'status' => $status,
            'statusText' => $message,
            'data' => ['error' => $message],
        ];
    }

    private static function valid_local_route(string $route): bool
    {
        return $route !== ''
            && $route[0] === '/'
            && strpos($route, '://') === false
            && strpos($route, '?') === false
            && strpos($route, '#') === false
            && strpos($route, '..') === false
            && strlen($route) <= 300;
    }

    private static function status_text(int $status): string
    {
        $texts = [
            200 => 'OK', 201 => 'Created', 202 => 'Accepted', 204 => 'No Content', 207 => 'Multi-Status',
            400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found',
            409 => 'Conflict', 410 => 'Gone', 413 => 'Payload Too Large', 416 => 'Range Not Satisfiable',
            422 => 'Unprocessable Entity', 429 => 'Too Many Requests', 500 => 'Internal Server Error',
            503 => 'Service Unavailable',
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
