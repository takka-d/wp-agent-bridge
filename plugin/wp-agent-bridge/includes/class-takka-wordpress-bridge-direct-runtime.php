<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Direct runtime: GitHub push webhook -> this WordPress -> GitHub results.
 *
 * No operator-owned relay and no GitHub Actions are used in this path.
 */
final class TakKa_WordPress_Bridge_Direct_Runtime
{
    public const NAMESPACE = 'wp-agent-bridge-runtime/v1';
    public const WEBHOOK_ROUTE = '/wp-agent-bridge-runtime/v1/github-webhook';
    public const RUNTIME_BRANCH = 'wp-agent-bridge-runtime';

    private const OPTION_CONNECTION = 'takka_bridge_direct_connection_v1';
    private const OPTION_BRIDGE_SECRET = 'takka_bridge_secret';
    private const MAX_COMMANDS_PER_PUSH = 20;
    private const MAX_COMMAND_BYTES = 2097152;
    private const DELIVERY_PREFIX = 'takka_bridge_direct_delivery_';

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
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes(): void
    {
        register_rest_route(self::NAMESPACE, '/github-webhook', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'webhook'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function connection(): array
    {
        $value = get_option(self::OPTION_CONNECTION, []);
        return is_array($value) ? $value : [];
    }

    public static function store_connection(array $connection): bool
    {
        $installation_id = isset($connection['installation_id']) ? (int) $connection['installation_id'] : 0;
        $repository_id = isset($connection['repository_id']) ? (int) $connection['repository_id'] : 0;
        $repository = isset($connection['repository']) ? trim((string) $connection['repository']) : '';
        $branch = isset($connection['runtime_branch']) ? (string) $connection['runtime_branch'] : self::RUNTIME_BRANCH;
        if ($installation_id < 1 || $repository_id < 1 || !self::valid_repository($repository) || $branch !== self::RUNTIME_BRANCH) {
            return false;
        }
        update_option(self::OPTION_CONNECTION, [
            'installation_id' => $installation_id,
            'repository_id' => $repository_id,
            'repository' => $repository,
            'runtime_branch' => self::RUNTIME_BRANCH,
            'connected_at_gmt' => gmdate('c'),
            'transport' => 'direct-github-webhook',
        ], false);
        return true;
    }

    public static function clear_connection(): void
    {
        delete_option(self::OPTION_CONNECTION);
    }

    public static function webhook(WP_REST_Request $request)
    {
        $raw = (string) $request->get_body();
        $signature = (string) $request->get_header('x-hub-signature-256');
        if (!TakKa_WordPress_Bridge_Direct_GitHub::verify_webhook($raw, $signature)) {
            return new WP_Error('takka_direct_webhook_signature', 'Invalid GitHub webhook signature.', ['status' => 401]);
        }

        $event = strtolower(trim((string) $request->get_header('x-github-event')));
        if ($event === 'ping') {
            return rest_ensure_response(['ok' => true, 'event' => 'ping', 'transport' => 'direct']);
        }
        if ($event !== 'push') {
            return rest_ensure_response(['ok' => true, 'ignored' => true, 'event' => $event]);
        }

        $delivery = trim((string) $request->get_header('x-github-delivery'));
        $delivery_key = '';
        if ($delivery !== '' && preg_match('/^[A-Za-z0-9-]{8,100}$/', $delivery)) {
            $delivery_key = self::DELIVERY_PREFIX . hash('sha256', $delivery);
            $state = get_transient($delivery_key);
            if ($state === 'processing' || $state === 'done') {
                return rest_ensure_response(['ok' => true, 'duplicate' => true, 'delivery' => $delivery]);
            }
            set_transient($delivery_key, 'processing', 10 * MINUTE_IN_SECONDS);
        }

        try {
            $response = self::process_push($raw);
            if ($delivery_key !== '' && !is_wp_error($response)) {
                set_transient($delivery_key, 'done', HOUR_IN_SECONDS);
            } elseif ($delivery_key !== '') {
                delete_transient($delivery_key);
            }
            return $response;
        } catch (Throwable $e) {
            if ($delivery_key !== '') {
                delete_transient($delivery_key);
            }
            return new WP_Error('takka_direct_webhook_exception', $e->getMessage(), ['status' => 500]);
        }
    }

    private static function process_push(string $raw)
    {
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return new WP_Error('takka_direct_webhook_json', 'Invalid webhook JSON.', ['status' => 400]);
        }
        if (($payload['ref'] ?? '') !== 'refs/heads/' . self::RUNTIME_BRANCH || !empty($payload['deleted'])) {
            return rest_ensure_response(['ok' => true, 'ignored' => true, 'reason' => 'non-runtime push']);
        }

        $connection = self::connection();
        $repository_id = (int) ($payload['repository']['id'] ?? 0);
        $repository = isset($payload['repository']['full_name']) ? (string) $payload['repository']['full_name'] : '';
        $private = isset($payload['repository']['private']) ? (bool) $payload['repository']['private'] : false;
        $installation_id = (int) ($payload['installation']['id'] ?? 0);
        $after = strtolower((string) ($payload['after'] ?? ''));

        if (!$private
            || $repository_id < 1
            || $repository === ''
            || !self::valid_repository($repository)
            || !preg_match('/^[a-f0-9]{40,64}$/', $after)
            || (int) ($connection['repository_id'] ?? 0) !== $repository_id
            || (int) ($connection['installation_id'] ?? 0) !== $installation_id
            || !hash_equals((string) ($connection['repository'] ?? ''), $repository)) {
            return new WP_Error('takka_direct_webhook_mapping', 'Webhook repository does not match this WordPress connection.', ['status' => 404]);
        }

        $paths = self::pending_paths($payload);
        if (!$paths) {
            return rest_ensure_response(['ok' => true, 'processed' => 0]);
        }
        if (count($paths) > self::MAX_COMMANDS_PER_PUSH) {
            return new WP_Error('takka_direct_webhook_too_many', 'Too many pending commands in one push.', ['status' => 413]);
        }

        $token = TakKa_WordPress_Bridge_Direct_GitHub::installation_token($installation_id, $repository_id);
        if (is_wp_error($token)) {
            return $token;
        }

        $outcomes = [];
        foreach ($paths as $path) {
            $outcomes[] = self::process_command($token, $repository, $after, $path);
        }
        return rest_ensure_response([
            'ok' => true,
            'processed' => count($outcomes),
            'commands' => $outcomes,
            'transport' => 'direct-github-webhook',
        ]);
    }

    private static function pending_paths(array $payload): array
    {
        $paths = [];
        foreach ((array) ($payload['commits'] ?? []) as $commit) {
            if (!is_array($commit)) {
                continue;
            }
            $changed = array_merge((array) ($commit['added'] ?? []), (array) ($commit['modified'] ?? []));
            foreach ($changed as $path) {
                if (is_string($path) && preg_match('#^wordpress-bridge/commands/pending/[A-Za-z0-9._-]{1,120}\.json$#', $path)) {
                    $paths[$path] = true;
                }
            }
        }
        return array_keys($paths);
    }

    private static function process_command(string $token, string $repository, string $ref, string $path): array
    {
        $meta = TakKa_WordPress_Bridge_Direct_GitHub::get_content_metadata($token, $repository, $ref, $path);
        if (is_wp_error($meta)) {
            return self::command_error($path, '', $meta->get_error_message());
        }
        $encoded = isset($meta['content']) && is_string($meta['content']) ? preg_replace('/\s+/', '', $meta['content']) : '';
        $raw = $encoded !== '' ? base64_decode($encoded, true) : false;
        if (!is_string($raw)) {
            return self::command_error($path, '', 'Could not decode command content.');
        }
        if (strlen($raw) > self::MAX_COMMAND_BYTES) {
            return self::command_error($path, '', 'Command exceeds 2 MiB.');
        }
        $command = json_decode($raw, true);
        if (!is_array($command)) {
            return self::command_error($path, '', 'Command JSON is invalid.');
        }

        $basename = basename($path, '.json');
        $id = isset($command['id']) ? (string) $command['id'] : $basename;
        $request_id = isset($command['request_id']) ? (string) $command['request_id'] : $id;
        if (!self::valid_id($id) || !self::valid_id($request_id)) {
            return self::command_error($path, '', 'Unsafe id or request_id.');
        }

        $started = microtime(true);
        $result = self::execute_command($command, $request_id);
        $output = [
            'id' => $id,
            'request_id' => $request_id,
            'command_file' => $path,
            'executed_at' => gmdate('c'),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'transport' => 'direct-github-webhook',
            'command' => self::sanitize_result($command),
            'result' => self::sanitize_result($result),
        ];
        $result_json = wp_json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($result_json)) {
            return self::command_error($path, $id, 'Could not encode result JSON.');
        }
        $result_json .= "\n";

        $result_path = 'wordpress-bridge/results/' . $id . '.json';
        $completed_path = 'wordpress-bridge/commands/completed/' . basename($path);
        $write_result = self::put_new_file($token, $repository, $result_path, $result_json, 'WP Bridge: store result ' . $id);
        if (is_wp_error($write_result)) {
            return self::command_error($path, $id, $write_result->get_error_message());
        }
        $write_completed = self::put_new_file($token, $repository, $completed_path, $raw, 'WP Bridge: complete command ' . $id);
        if (is_wp_error($write_completed)) {
            return self::command_error($path, $id, $write_completed->get_error_message());
        }
        $sha = isset($meta['sha']) ? strtolower((string) $meta['sha']) : '';
        $delete = TakKa_WordPress_Bridge_Direct_GitHub::delete_file(
            $token,
            $repository,
            self::RUNTIME_BRANCH,
            $path,
            $sha,
            'WP Bridge: remove pending command ' . $id
        );
        if (is_wp_error($delete)) {
            return self::command_error($path, $id, $delete->get_error_message());
        }
        return [
            'path' => $path,
            'id' => $id,
            'ok' => !empty($result['ok']),
            'status' => $result['status'] ?? null,
        ];
    }

    private static function put_new_file(string $token, string $repository, string $path, string $content, string $message)
    {
        $endpoint = '/repos/' . $repository . '/contents/' . self::encode_path($path);
        return TakKa_WordPress_Bridge_Direct_GitHub::github_api('PUT', $endpoint, $token, [
            'message' => $message,
            'content' => base64_encode($content),
            'branch' => self::RUNTIME_BRANCH,
        ]);
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
            return self::local_bridge_request('POST', '/takka-bridge/v1/execute', [
                'action' => 'rest.call',
                'params' => $params,
            ], $request_id, true);
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
            return self::local_bridge_request('POST', '/takka-bridge/v1/execute', [
                'action' => $action,
                'params' => $params,
            ], $request_id, true);
        }
        if ($type === 'site_get') {
            return self::site_get($command);
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

    private static function site_get(array $command): array
    {
        $path = isset($command['path']) ? (string) $command['path'] : '/';
        if ($path === '' || $path[0] !== '/' || strpos($path, '://') !== false || strpos($path, "\0") !== false) {
            return self::error_result('Invalid site_get path.');
        }
        $url = home_url($path);
        $response = wp_safe_remote_get($url, [
            'timeout' => 30,
            'redirection' => 3,
            'headers' => ['User-Agent' => 'WP-Agent-Bridge-Direct/0.1'],
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
            'url' => $url,
            'data' => ['body' => $body],
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
            return substr($value, 0, 1000000) . '\n[truncated]';
        }
        return $value;
    }

    private static function command_error(string $path, string $id, string $message): array
    {
        return ['path' => $path, 'id' => $id, 'ok' => false, 'error' => $message];
    }

    private static function error_result(string $message): array
    {
        return ['ok' => false, 'status' => 500, 'statusText' => $message, 'data' => ['error' => $message]];
    }

    private static function valid_id(string $id): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9._-]{1,120}$/', $id);
    }

    private static function valid_repository(string $repository): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repository);
    }

    private static function valid_local_route(string $route): bool
    {
        return $route !== ''
            && $route[0] === '/'
            && strpos($route, '://') === false
            && strpos($route, '..') === false
            && strlen($route) <= 300;
    }

    private static function encode_path(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
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
