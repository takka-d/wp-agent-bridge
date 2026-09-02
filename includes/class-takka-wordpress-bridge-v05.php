<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * v0.5 reliability and structured administration surface.
 *
 * Adds request-id idempotency across the existing POST bridge routes plus
 * serialized-data-aware DB search/replace, nav-menu management, and update
 * inventory. All writes remain structured and allowlisted.
 */
final class TakKa_WordPress_Bridge_V05
{
    private const VERSION = '0.5.0';
    private const ROUTE_NAMESPACE = 'takka-bridge/v1';
    private const ROUTE = '/takka-bridge/v1/v05';
    private const OPTION_SECRET = 'takka_bridge_secret';
    private const OPTION_USER_ID = 'takka_bridge_user_id';
    private const IDEMPOTENCY_PREFIX = 'takka_bridge_idem_';
    private const IDEMPOTENCY_INDEX = 'takka_bridge_idem_index';
    private const IDEMPOTENCY_TTL = 86400;
    private const IDEMPOTENCY_MAX = 500;
    private const MAX_CLOCK_SKEW = 300;
    private const MAX_ENVELOPE_BYTES = 12582912;
    private const MAX_SR_ROWS = 5000;
    private const MAX_SR_REPLACEMENTS = 50000;
    private const MAX_SR_SAMPLES = 20;

    private const ACTIONS = [
        'v05.capabilities',
        'idempotency.status',
        'db.search_replace.plan',
        'db.search_replace.execute',
        'menu.list',
        'menu.get',
        'menu.create',
        'menu.update',
        'menu.delete',
        'menu.item.upsert',
        'menu.item.delete',
        'menu.locations.set',
        'updates.status',
    ];

    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_filter('rest_request_before_callbacks', [self::class, 'idempotency_before'], 95, 3);
        add_filter('rest_request_after_callbacks', [self::class, 'idempotency_after'], 125, 3);
        add_filter('rest_request_after_callbacks', [self::class, 'annotate_health'], 130, 3);
    }

    public static function register_routes(): void
    {
        register_rest_route(self::ROUTE_NAMESPACE, '/v05', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'dispatch'],
            'permission_callback' => [self::class, 'authorize_request'],
        ]);
    }

    public static function annotate_health($response, array $handler, WP_REST_Request $request)
    {
        if ($request->get_route() !== '/takka-bridge/v1/health' || is_wp_error($response)) {
            return $response;
        }
        $rest_response = rest_ensure_response($response);
        $data = $rest_response->get_data();
        if (!is_array($data)) {
            return $response;
        }
        $data['bridge_version'] = self::VERSION;
        $features = isset($data['features']) && is_array($data['features']) ? $data['features'] : [];
        foreach ([
            'request_id_idempotency',
            'serialized_search_replace',
            'nav_menu_management',
            'update_inventory',
        ] as $feature) {
            if (!in_array($feature, $features, true)) {
                $features[] = $feature;
            }
        }
        $data['features'] = $features;
        $rest_response->set_data($data);
        return $rest_response;
    }

    public static function authorize_request(WP_REST_Request $request)
    {
        $secret = (string) get_option(self::OPTION_SECRET, '');
        $user_id = (int) get_option(self::OPTION_USER_ID, 0);
        if ($secret === '' || $user_id < 1) {
            return new WP_Error('takka_bridge_not_configured', 'TakKa WordPress Bridge is not configured.', ['status' => 503]);
        }

        $timestamp = trim((string) $request->get_header('x-takka-timestamp'));
        $signature = strtolower(trim((string) $request->get_header('x-takka-signature')));
        if ($timestamp === '' || $signature === '' || !ctype_digit($timestamp)) {
            return new WP_Error('takka_bridge_invalid_signature_headers', 'Missing or invalid bridge signature headers.', ['status' => 401]);
        }
        if (abs(time() - (int) $timestamp) > self::MAX_CLOCK_SKEW) {
            return new WP_Error('takka_bridge_expired_signature', 'Bridge signature has expired.', ['status' => 401]);
        }

        $body = (string) $request->get_body();
        $payload = $timestamp . "\nPOST\n" . self::ROUTE . "\n" . hash('sha256', $body);
        $expected = hash_hmac('sha256', $payload, $secret);
        if (!hash_equals($expected, $signature)) {
            return new WP_Error('takka_bridge_bad_signature', 'Invalid bridge signature.', ['status' => 401]);
        }

        wp_set_current_user($user_id);
        if (!current_user_can('manage_options')) {
            return new WP_Error('takka_bridge_user_forbidden', 'Bridge user no longer has administrator capability.', ['status' => 403]);
        }
        return true;
    }

    public static function dispatch(WP_REST_Request $request)
    {
        $payload = self::decode_envelope($request);
        if (is_wp_error($payload)) {
            return $payload;
        }
        $action = isset($payload['action']) && is_string($payload['action']) ? trim($payload['action']) : '';
        $params = isset($payload['params']) && is_array($payload['params']) ? $payload['params'] : [];
        if ($action === '' || !in_array($action, self::ACTIONS, true)) {
            return new WP_Error('takka_bridge_v05_unknown_action', 'Unknown or blocked v0.5 action.', [
                'status' => 400,
                'action' => $action,
            ]);
        }

        try {
            switch ($action) {
                case 'v05.capabilities':
                    return rest_ensure_response(self::capabilities());
                case 'idempotency.status':
                    return self::idempotency_status($params);
                case 'db.search_replace.plan':
                    return TakKa_WordPress_Bridge_V05_Search_Replace::plan($params);
                case 'db.search_replace.execute':
                    return TakKa_WordPress_Bridge_V05_Search_Replace::execute($params);
                case 'menu.list':
                    return rest_ensure_response(TakKa_WordPress_Bridge_V05_Admin::menu_list());
                case 'menu.get':
                    return TakKa_WordPress_Bridge_V05_Admin::menu_get($params);
                case 'menu.create':
                    return TakKa_WordPress_Bridge_V05_Admin::menu_create($params);
                case 'menu.update':
                    return TakKa_WordPress_Bridge_V05_Admin::menu_update($params);
                case 'menu.delete':
                    return TakKa_WordPress_Bridge_V05_Admin::menu_delete($params);
                case 'menu.item.upsert':
                    return TakKa_WordPress_Bridge_V05_Admin::menu_item_upsert($params);
                case 'menu.item.delete':
                    return TakKa_WordPress_Bridge_V05_Admin::menu_item_delete($params);
                case 'menu.locations.set':
                    return TakKa_WordPress_Bridge_V05_Admin::menu_locations_set($params);
                case 'updates.status':
                    return rest_ensure_response(TakKa_WordPress_Bridge_V05_Admin::updates_status(!empty($params['refresh'])));
            }
        } catch (Throwable $e) {
            return new WP_Error('takka_bridge_v05_exception', $e->getMessage(), [
                'status' => 500,
                'type' => get_class($e),
            ]);
        }

        return new WP_Error('takka_bridge_v05_dispatch_error', 'v0.5 dispatch fell through unexpectedly.', ['status' => 500]);
    }

    private static function capabilities(): array
    {
        return [
            'version' => self::VERSION,
            'route' => self::ROUTE,
            'actions' => self::ACTIONS,
            'idempotency' => [
                'request_id_in_signed_envelope' => true,
                'retention_seconds' => self::IDEMPOTENCY_TTL,
                'max_records' => self::IDEMPOTENCY_MAX,
                'payload_hash_conflict_detection' => true,
            ],
            'search_replace' => [
                'serialized_data_aware' => true,
                'dry_run_plan_required' => true,
                'plan_hash_required_for_execute' => true,
                'user_tables_blocked' => true,
                'max_rows' => self::MAX_SR_ROWS,
                'max_replacements' => self::MAX_SR_REPLACEMENTS,
            ],
            'arbitrary_sql_write' => false,
            'arbitrary_shell' => false,
        ];
    }

    public static function idempotency_before($response, array $handler, WP_REST_Request $request)
    {
        if ($response !== null || strtoupper($request->get_method()) !== 'POST') {
            return $response;
        }
        if (!in_array($request->get_route(), [
            '/takka-bridge/v1/execute',
            '/takka-bridge/v1/manage',
            self::ROUTE,
        ], true)) {
            return $response;
        }

        $context = self::extract_request_context($request);
        if (is_wp_error($context)) {
            return $context;
        }
        if ($context === null) {
            return $response;
        }

        self::prune_idempotency();
        $option = self::idempotency_option($context['request_id']);
        $existing = get_option($option, null);
        if (is_array($existing)) {
            if (!isset($existing['payload_hash']) || !hash_equals((string) $existing['payload_hash'], $context['payload_hash'])) {
                return self::idempotency_short_circuit_response(
                    'takka_bridge_idempotency_payload_conflict',
                    'request_id was already used for a different payload.',
                    409,
                    ['request_id' => $context['request_id']]
                );
            }
            if (($existing['state'] ?? '') === 'completed') {
                $cached = new WP_REST_Response(
                    $existing['data'] ?? null,
                    isset($existing['status']) ? (int) $existing['status'] : 200,
                    isset($existing['headers']) && is_array($existing['headers']) ? $existing['headers'] : []
                );
                $cached->header('X-TakKa-Idempotent-Replay', '1');
                return $cached;
            }
            $created = isset($existing['created_at']) ? (int) $existing['created_at'] : 0;
            if ($created > 0 && (time() - $created) < 600) {
                return self::idempotency_short_circuit_response(
                    'takka_bridge_idempotency_in_progress',
                    'The same request_id is already in progress.',
                    409,
                    ['request_id' => $context['request_id']]
                );
            }
            update_option($option, self::new_idempotency_lock($context), false);
            self::touch_idempotency_index($option);
            return $response;
        }

        $added = add_option($option, self::new_idempotency_lock($context), '', false);
        if (!$added) {
            return self::idempotency_short_circuit_response(
                'takka_bridge_idempotency_lock_race',
                'Could not acquire idempotency lock.',
                409,
                ['request_id' => $context['request_id']]
            );
        }
        self::touch_idempotency_index($option);
        return $response;
    }

    public static function idempotency_after($response, array $handler, WP_REST_Request $request)
    {
        if (strtoupper($request->get_method()) !== 'POST') {
            return $response;
        }
        if (!in_array($request->get_route(), [
            '/takka-bridge/v1/execute',
            '/takka-bridge/v1/manage',
            self::ROUTE,
        ], true)) {
            return $response;
        }
        $context = self::extract_request_context($request);
        if (is_wp_error($context) || $context === null) {
            return $response;
        }

        $rest = rest_ensure_response($response);
        $response_headers = $rest->get_headers();
        if (isset($response_headers['X-TakKa-Idempotency-Internal'])) {
            return $response;
        }
        $existing = get_option(self::idempotency_option($context['request_id']), null);
        $record = [
            'state' => 'completed',
            'request_id' => $context['request_id'],
            'payload_hash' => $context['payload_hash'],
            'action' => $context['action'],
            'created_at' => is_array($existing) && isset($existing['created_at']) ? (int) $existing['created_at'] : time(),
            'completed_at' => time(),
            'status' => $rest->get_status(),
            'headers' => $rest->get_headers(),
            'data' => $rest->get_data(),
        ];
        update_option(self::idempotency_option($context['request_id']), $record, false);
        self::touch_idempotency_index(self::idempotency_option($context['request_id']));
        return $response;
    }

    private static function idempotency_short_circuit_response(string $code, string $message, int $status, array $extra = []): WP_REST_Response
    {
        $response = new WP_REST_Response([
            'code' => $code,
            'message' => $message,
            'data' => array_merge(['status' => $status], $extra),
        ], $status);
        $response->header('X-TakKa-Idempotency-Internal', '1');
        return $response;
    }

    private static function new_idempotency_lock(array $context): array
    {
        return [
            'state' => 'running',
            'request_id' => $context['request_id'],
            'payload_hash' => $context['payload_hash'],
            'action' => $context['action'],
            'created_at' => time(),
        ];
    }

    private static function extract_request_context(WP_REST_Request $request)
    {
        $outer = json_decode((string) $request->get_body(), true);
        if (!is_array($outer)) {
            return null;
        }

        $decoded = null;
        if ($request->get_route() === '/takka-bridge/v1/execute') {
            if (($outer['action'] ?? null) !== 'envelope' || !isset($outer['params']['payload_b64']) || !is_string($outer['params']['payload_b64'])) {
                return null;
            }
            $decoded = base64_decode($outer['params']['payload_b64'], true);
        } else {
            if (!isset($outer['payload_b64']) || !is_string($outer['payload_b64'])) {
                return null;
            }
            $decoded = base64_decode($outer['payload_b64'], true);
        }
        if (!is_string($decoded)) {
            return null;
        }
        $inner = json_decode($decoded, true);
        if (!is_array($inner)) {
            return null;
        }
        if (!isset($inner['request_id']) || !is_string($inner['request_id']) || trim($inner['request_id']) === '') {
            return null;
        }
        $request_id = trim($inner['request_id']);
        if (strlen($request_id) > 120 || !preg_match('/^[A-Za-z0-9._:-]+$/', $request_id)) {
            return new WP_Error('takka_bridge_invalid_request_id', 'request_id contains invalid characters or is too long.', ['status' => 400]);
        }
        return [
            'request_id' => $request_id,
            'payload_hash' => hash('sha256', $decoded),
            'action' => isset($inner['action']) && is_string($inner['action']) ? $inner['action'] : 'unknown',
        ];
    }

    private static function idempotency_option(string $request_id): string
    {
        return self::IDEMPOTENCY_PREFIX . substr(hash('sha256', $request_id), 0, 40);
    }

    private static function touch_idempotency_index(string $option): void
    {
        $index = get_option(self::IDEMPOTENCY_INDEX, []);
        if (!is_array($index)) {
            $index = [];
        }
        $index[$option] = time();
        update_option(self::IDEMPOTENCY_INDEX, $index, false);
    }

    private static function prune_idempotency(): void
    {
        $index = get_option(self::IDEMPOTENCY_INDEX, []);
        if (!is_array($index) || !$index) {
            return;
        }
        asort($index, SORT_NUMERIC);
        $cutoff = time() - self::IDEMPOTENCY_TTL;
        while ($index && (count($index) > self::IDEMPOTENCY_MAX || (int) reset($index) < $cutoff)) {
            $option = (string) key($index);
            delete_option($option);
            unset($index[$option]);
        }
        update_option(self::IDEMPOTENCY_INDEX, $index, false);
    }

    private static function idempotency_status(array $params)
    {
        $request_id = self::required_string($params, 'request_id');
        if (is_wp_error($request_id)) {
            return $request_id;
        }
        $record = get_option(self::idempotency_option($request_id), null);
        if (!is_array($record)) {
            return new WP_Error('takka_bridge_idempotency_not_found', 'No idempotency record exists for request_id.', ['status' => 404]);
        }
        return rest_ensure_response([
            'request_id' => $request_id,
            'state' => $record['state'] ?? null,
            'action' => $record['action'] ?? null,
            'created_at' => $record['created_at'] ?? null,
            'completed_at' => $record['completed_at'] ?? null,
            'status' => $record['status'] ?? null,
        ]);
    }

    private static function decode_envelope(WP_REST_Request $request)
    {
        $json = $request->get_json_params();
        if (!is_array($json) || !isset($json['payload_b64']) || !is_string($json['payload_b64'])) {
            return new WP_Error('takka_bridge_v05_missing_envelope', 'Missing payload_b64.', ['status' => 400]);
        }
        $payload_b64 = trim($json['payload_b64']);
        if ($payload_b64 === '' || strlen($payload_b64) > self::MAX_ENVELOPE_BYTES) {
            return new WP_Error('takka_bridge_v05_envelope_size', 'Envelope is empty or too large.', ['status' => 413]);
        }
        $decoded = base64_decode($payload_b64, true);
        if (!is_string($decoded)) {
            return new WP_Error('takka_bridge_v05_invalid_base64', 'Envelope is not valid Base64.', ['status' => 400]);
        }
        $payload = json_decode($decoded, true);
        if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('takka_bridge_v05_invalid_json', 'Decoded envelope is not valid JSON.', ['status' => 400]);
        }
        return $payload;
    }

    private static function required_string(array $params, string $key, bool $trim = true)
    {
        if (!array_key_exists($key, $params) || !is_string($params[$key])) {
            return new WP_Error('takka_bridge_required_string', "{$key} must be a string.", ['status' => 400]);
        }
        $value = $trim ? trim($params[$key]) : $params[$key];
        if ($trim && $value === '') {
            return new WP_Error('takka_bridge_required_string_empty', "{$key} must not be empty.", ['status' => 400]);
        }
        return $value;
    }
}
