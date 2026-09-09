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
    private const COMMAND_INFLIGHT_PREFIX = 'takka_bridge_direct_command_inflight_';
    private const COMMAND_INFLIGHT_STALE_SECONDS = 600;
    private const COMMAND_JOURNAL_PREFIX = 'takka_bridge_direct_command_journal_';
    private const COMMAND_JOURNAL_STALE_SECONDS = 86400;
    private const MAX_COMMAND_JOURNAL_BYTES = 2097152;
    private const BOOKKEEPING_MAX_ATTEMPTS = 3;

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

    public static function command_inflight(string $request_id): bool
    {
        if (!self::valid_id($request_id)) {
            return false;
        }
        $current = get_option(self::command_inflight_option($request_id), []);
        if (!is_array($current)) {
            return false;
        }
        $created = (int) ($current['created_at'] ?? 0);
        return $created > 0 && $created >= time() - self::COMMAND_INFLIGHT_STALE_SECONDS;
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
                if (is_string($path) && preg_match('#^wordpress-bridge/commands/pending/[A-Za-z0-9._-]{1,120}\\.json$#', $path)) {
                    $paths[$path] = true;
                }
            }
        }
        return array_keys($paths);
    }

    private static function process_command(string $token, string $repository, string $ref, string $path): array
    {
        $command_started = microtime(true);
        $meta = TakKa_WordPress_Bridge_Direct_GitHub::get_content_metadata($token, $repository, $ref, $path);
        if (is_wp_error($meta)) {
            return self::command_error($path, '', $meta->get_error_message());
        }
        $encoded = isset($meta['content']) && is_string($meta['content']) ? preg_replace('/\\s+/', '', $meta['content']) : '';
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
        $command_sha256 = hash('sha256', $raw);

        $inflight_token = self::acquire_command_inflight($request_id);
        if ($inflight_token === null) {
            return [
                'path' => $path,
                'id' => $id,
                'ok' => true,
                'status' => 202,
                'in_flight' => true,
            ];
        }

        try {
            $journal = self::load_command_journal($request_id, $id, $command_sha256);
            if (is_wp_error($journal)) {
                return self::command_error($path, $id, $journal->get_error_message());
            }

            $journal_replayed = false;
            if (is_array($journal)) {
                $output = $journal['output'];
                $result_json = $journal['result_json'];
                $result = isset($output['result']) && is_array($output['result']) ? $output['result'] : [];
                $journal_replayed = true;
            } else {
                $started = microtime(true);
                $result = self::execute_command($command, $request_id);
                $finished = microtime(true);
                $output = [
                    'id' => $id,
                    'request_id' => $request_id,
                    'command_file' => $path,
                    'executed_at' => gmdate('c'),
                    'duration_ms' => (int) round(($finished - $started) * 1000),
                    'source_commit' => $ref,
                    'timing' => [
                        'command_started_at_ms' => (int) round($command_started * 1000),
                        'execution_started_at_ms' => (int) round($started * 1000),
                        'execution_finished_at_ms' => (int) round($finished * 1000),
                        'pre_execution_ms' => (int) round(($started - $command_started) * 1000),
                        'excludes' => ['webhook_delivery', 'initial_authentication', 'result_publication', 'client_polling'],
                    ],
                    'transport' => 'direct-github-webhook',
                    'command' => self::summarize_command($command),
                    'result' => self::sanitize_result($result),
                ];
                $result_json = wp_json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (!is_string($result_json)) {
                    return self::command_error($path, $id, 'Could not encode result JSON.');
                }
                $result_json .= "\n";

                $stored = self::store_command_journal($request_id, $id, $command_sha256, $result_json);
                if (is_wp_error($stored)) {
                    return self::command_error($path, $id, $stored->get_error_message());
                }
            }

            $result_path = 'wordpress-bridge/results/' . $id . '.json';
            $completed_path = 'wordpress-bridge/commands/completed/' . basename($path);
            $pending_sha = isset($meta['sha']) ? strtolower((string) $meta['sha']) : '';
            $finalized = self::finalize_command_atomic(
                $token,
                $repository,
                $path,
                $pending_sha,
                $result_path,
                $result_json,
                $completed_path,
                $raw,
                $id
            );
            if (is_wp_error($finalized)) {
                return self::command_error($path, $id, $finalized->get_error_message());
            }

            // The result, completed command, and pending deletion are durable in
            // one ref update. Once the result is visible, this command cannot
            // move the runtime branch again during bookkeeping.
            self::clear_command_journal($request_id);

            return [
                'path' => $path,
                'id' => $id,
                'ok' => !empty($result['ok']),
                'status' => $result['status'] ?? null,
                'journal_replayed' => $journal_replayed,
                'atomic_bookkeeping' => true,
                'bookkeeping_commit' => $finalized['commit_sha'] ?? null,
                'bookkeeping_attempts' => $finalized['attempts'] ?? null,
                'bookkeeping_verified_after_error' => !empty($finalized['verified_after_error']),
            ];
        } finally {
            self::release_command_inflight($request_id, $inflight_token);
        }
    }

    private static function finalize_command_atomic(
        string $token,
        string $repository,
        string $pending_path,
        string $expected_pending_sha,
        string $result_path,
        string $result_json,
        string $completed_path,
        string $command_raw,
        string $id
    ) {
        if (!preg_match('/^[a-f0-9]{40,64}$/', $expected_pending_sha)) {
            return new WP_Error('takka_direct_bookkeeping_pending_sha', 'Pending command SHA is invalid.', ['status' => 409]);
        }

        for ($attempt = 1; $attempt <= self::BOOKKEEPING_MAX_ATTEMPTS; $attempt++) {
            $ref = TakKa_WordPress_Bridge_Direct_GitHub::github_api(
                'GET',
                '/repos/' . $repository . '/git/ref/heads/' . rawurlencode(self::RUNTIME_BRANCH),
                $token
            );
            if (is_wp_error($ref)) {
                return $ref;
            }
            $head_sha = strtolower((string) ($ref['data']['object']['sha'] ?? ''));
            if (!preg_match('/^[a-f0-9]{40,64}$/', $head_sha)) {
                return new WP_Error('takka_direct_bookkeeping_head', 'GitHub did not return a valid runtime branch head.', ['status' => 502]);
            }

            $base_commit = TakKa_WordPress_Bridge_Direct_GitHub::github_api(
                'GET',
                '/repos/' . $repository . '/git/commits/' . $head_sha,
                $token
            );
            if (is_wp_error($base_commit)) {
                return $base_commit;
            }
            $base_tree_sha = strtolower((string) ($base_commit['data']['tree']['sha'] ?? ''));
            if (!preg_match('/^[a-f0-9]{40,64}$/', $base_tree_sha)) {
                return new WP_Error('takka_direct_bookkeeping_tree', 'GitHub did not return a valid runtime base tree.', ['status' => 502]);
            }

            // Compare the pending blob against the exact command that was
            // executed. A caller must never delete a command path that changed
            // underneath the in-flight request.
            $pending_meta = TakKa_WordPress_Bridge_Direct_GitHub::get_content_metadata(
                $token,
                $repository,
                $head_sha,
                $pending_path
            );
            if (is_wp_error($pending_meta)) {
                if (self::github_error_status($pending_meta) === 404
                    && self::verify_atomic_finalization(
                        $token,
                        $repository,
                        $result_path,
                        $result_json,
                        $completed_path,
                        $command_raw,
                        $pending_path
                    )) {
                    return [
                        'commit_sha' => $head_sha,
                        'attempts' => $attempt,
                        'verified_after_error' => true,
                    ];
                }
                return $pending_meta;
            }
            $current_pending_sha = strtolower((string) ($pending_meta['sha'] ?? ''));
            if (!preg_match('/^[a-f0-9]{40,64}$/', $current_pending_sha)
                || !hash_equals($expected_pending_sha, $current_pending_sha)) {
                return new WP_Error(
                    'takka_direct_bookkeeping_pending_conflict',
                    'Pending command changed before atomic bookkeeping could complete.',
                    ['status' => 409, 'path' => $pending_path]
                );
            }

            $tree = TakKa_WordPress_Bridge_Direct_GitHub::github_api(
                'POST',
                '/repos/' . $repository . '/git/trees',
                $token,
                [
                    'base_tree' => $base_tree_sha,
                    'tree' => [
                        // Create the result in the tree request and reuse the exact pending
                        // blob for completed; neither needs a separate blob API call.
                        ['path' => $result_path, 'mode' => '100644', 'type' => 'blob', 'content' => $result_json],
                        ['path' => $completed_path, 'mode' => '100644', 'type' => 'blob', 'sha' => $expected_pending_sha],
                        ['path' => $pending_path, 'mode' => '100644', 'type' => 'blob', 'sha' => null],
                    ],
                ]
            );
            if (is_wp_error($tree)) {
                $status = self::github_error_status($tree);
                if (($status === 409 || $status === 422) && $attempt < self::BOOKKEEPING_MAX_ATTEMPTS) {
                    continue;
                }
                return $tree;
            }
            $tree_sha = strtolower((string) ($tree['data']['sha'] ?? ''));
            if (!preg_match('/^[a-f0-9]{40,64}$/', $tree_sha)) {
                return new WP_Error('takka_direct_bookkeeping_tree', 'GitHub did not return a valid bookkeeping tree SHA.', ['status' => 502]);
            }

            $commit = TakKa_WordPress_Bridge_Direct_GitHub::github_api(
                'POST',
                '/repos/' . $repository . '/git/commits',
                $token,
                [
                    'message' => 'WP Bridge: finalize command ' . $id,
                    'tree' => $tree_sha,
                    'parents' => [$head_sha],
                ]
            );
            if (is_wp_error($commit)) {
                return $commit;
            }
            $commit_sha = strtolower((string) ($commit['data']['sha'] ?? ''));
            if (!preg_match('/^[a-f0-9]{40,64}$/', $commit_sha)) {
                return new WP_Error('takka_direct_bookkeeping_commit', 'GitHub did not return a valid bookkeeping commit SHA.', ['status' => 502]);
            }

            $updated = TakKa_WordPress_Bridge_Direct_GitHub::github_api(
                'PATCH',
                '/repos/' . $repository . '/git/refs/heads/' . rawurlencode(self::RUNTIME_BRANCH),
                $token,
                ['sha' => $commit_sha, 'force' => false]
            );
            if (!is_wp_error($updated)) {
                return ['commit_sha' => $commit_sha, 'attempts' => $attempt, 'verified_after_error' => false];
            }

            $status = self::github_error_status($updated);
            if (($status === 409 || $status === 422) && $attempt < self::BOOKKEEPING_MAX_ATTEMPTS) {
                continue;
            }

            // A network failure can happen after GitHub accepted the ref update.
            // Verify the durable tree before reporting failure or replaying the
            // WordPress side effect on a later recovery request.
            if (self::verify_atomic_finalization(
                $token,
                $repository,
                $result_path,
                $result_json,
                $completed_path,
                $command_raw,
                $pending_path
            )) {
                return [
                    'commit_sha' => $commit_sha,
                    'attempts' => $attempt,
                    'verified_after_error' => true,
                ];
            }
            return $updated;
        }

        if (self::verify_atomic_finalization(
            $token,
            $repository,
            $result_path,
            $result_json,
            $completed_path,
            $command_raw,
            $pending_path
        )) {
            return [
                'commit_sha' => null,
                'attempts' => self::BOOKKEEPING_MAX_ATTEMPTS,
                'verified_after_error' => true,
            ];
        }

        return new WP_Error(
            'takka_direct_bookkeeping_conflict',
            'Runtime branch kept moving during atomic command bookkeeping; the journal remains available for recovery.',
            ['status' => 409, 'attempts' => self::BOOKKEEPING_MAX_ATTEMPTS]
        );
    }

    private static function create_git_blob(string $token, string $repository, string $content)
    {
        $response = TakKa_WordPress_Bridge_Direct_GitHub::github_api(
            'POST',
            '/repos/' . $repository . '/git/blobs',
            $token,
            ['content' => base64_encode($content), 'encoding' => 'base64']
        );
        if (is_wp_error($response)) {
            return $response;
        }
        $sha = strtolower((string) ($response['data']['sha'] ?? ''));
        if (!preg_match('/^[a-f0-9]{40,64}$/', $sha)) {
            return new WP_Error('takka_direct_bookkeeping_blob', 'GitHub did not return a valid bookkeeping blob SHA.', ['status' => 502]);
        }
        return $sha;
    }

    private static function verify_atomic_finalization(
        string $token,
        string $repository,
        string $result_path,
        string $result_json,
        string $completed_path,
        string $command_raw,
        string $pending_path
    ): bool {
        $stored_result = TakKa_WordPress_Bridge_Direct_GitHub::get_text_file(
            $token,
            $repository,
            self::RUNTIME_BRANCH,
            $result_path
        );
        if (is_wp_error($stored_result) || !hash_equals($result_json, $stored_result)) {
            return false;
        }
        $stored_completed = TakKa_WordPress_Bridge_Direct_GitHub::get_text_file(
            $token,
            $repository,
            self::RUNTIME_BRANCH,
            $completed_path
        );
        if (is_wp_error($stored_completed) || !hash_equals($command_raw, $stored_completed)) {
            return false;
        }
        $pending = TakKa_WordPress_Bridge_Direct_GitHub::get_content_metadata(
            $token,
            $repository,
            self::RUNTIME_BRANCH,
            $pending_path
        );
        return is_wp_error($pending) && self::github_error_status($pending) === 404;
    }

    private static function github_error_status($error): int
    {
        if (!is_wp_error($error)) {
            return 0;
        }
        $data = $error->get_error_data();
        return is_array($data) && isset($data['status']) ? (int) $data['status'] : 0;
    }

    private static function execute_command(array $command, string $request_id): array
    {
        $type = isset($command['type']) ? (string) $command['type'] : 'rest';
        if ($type === 'operation') {
            if (!is_string($command['operation'] ?? null) || trim($command['operation']) === ''
                || (isset($command['params']) && !is_array($command['params']))) {
                return self::error_result('Operation command requires operation and an object of params.');
            }
            // Reuse the signed, policy-guarded route without asking the caller
            // to reconstruct a REST route, method, or nested envelope.
            $command = [
                'type' => 'rest',
                'method' => 'POST',
                'route' => '/takka-v099/v1/operate',
                'body' => ['operation' => trim($command['operation']), 'params' => $command['params'] ?? []],
            ];
            $type = 'rest';
        }
        if ($type === 'health') {
            return self::local_bridge_request('GET', '/takka-bridge/v1/health', null, $request_id, false);
        }
        if ($type === 'rest') {
            $method = strtoupper((string) ($command['method'] ?? 'GET'));
            $route = isset($command['route']) ? (string) $command['route'] : '';
            if ($route === '/takka-bridge/v1/execute' || strpos($route, '?') !== false || strpos($route, '#') !== false) {
                $message = $route === '/takka-bridge/v1/execute'
                    ? 'Recursive REST proxy blocked. Use type=operation with operation/params, or type=bridge with action/params.'
                    : 'REST route must not contain query strings or fragments. Use the query object or type=operation.';
                return ['ok' => false, 'status' => 400, 'statusText' => 'Bad Request', 'data' => ['error' => $message]];
            }
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
            $result = self::local_bridge_request('POST', '/takka-bridge/v1/execute', [
                'action' => 'rest.call',
                'params' => $params,
            ], $request_id, true);
            if ($route === '/takka-v099/v1/operate') {
                $wrapper = $result['data'] ?? [];
                $payload = $wrapper['data'] ?? [];
                if (isset($wrapper['status']) && (int) $wrapper['status'] >= 400) {
                    $result['ok'] = false;
                    $result['status'] = (int) $wrapper['status'];
                } elseif (is_array($payload) && array_key_exists('ok', $payload) && empty($payload['ok'])) {
                    $result['ok'] = false;
                    $result['status'] = (int) ($payload['status'] ?? 500);
                }
                $result['statusText'] = self::status_text((int) $result['status']);
            }
            return $result;
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

    private static function summarize_command($value, string $key = '')
    {
        // Input payloads are already durable in commands/completed. Echoing
        // them into results makes every status read transfer the media again.
        if (is_string($value) && in_array(strtolower($key), ['data_b64', 'payload_b64', 'content_b64'], true)) {
            $decoded = base64_decode($value, true);
            return [
                'omitted' => true,
                'encoding' => 'base64',
                'encoded_bytes' => strlen($value),
                'decoded_bytes' => is_string($decoded) ? strlen($decoded) : null,
                'sha256' => is_string($decoded) ? hash('sha256', $decoded) : null,
            ];
        }
        if (is_array($value)) {
            foreach ($value as $child_key => $child_value) {
                $value[$child_key] = self::summarize_command($child_value, (string) $child_key);
            }
        }
        return self::sanitize_result($value, $key);
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

    private static function acquire_command_inflight(string $request_id): ?string
    {
        $option = self::command_inflight_option($request_id);
        $token = wp_generate_uuid4();
        $value = ['token' => $token, 'created_at' => time()];
        if (add_option($option, $value, '', false)) {
            return $token;
        }

        $current = get_option($option, []);
        $created = is_array($current) ? (int) ($current['created_at'] ?? 0) : 0;
        if ($created <= 0 || $created < time() - self::COMMAND_INFLIGHT_STALE_SECONDS) {
            delete_option($option);
            if (add_option($option, $value, '', false)) {
                return $token;
            }
        }
        return null;
    }

    private static function release_command_inflight(string $request_id, string $token): void
    {
        $option = self::command_inflight_option($request_id);
        $current = get_option($option, []);
        if (is_array($current) && hash_equals((string) ($current['token'] ?? ''), $token)) {
            delete_option($option);
        }
    }

    private static function command_inflight_option(string $request_id): string
    {
        return self::COMMAND_INFLIGHT_PREFIX . hash('sha256', $request_id);
    }

    private static function load_command_journal(string $request_id, string $id, string $command_sha256)
    {
        $option = self::command_journal_option($request_id);
        $current = get_option($option, null);
        if ($current === null || $current === false) {
            return null;
        }
        if (!is_array($current)) {
            delete_option($option);
            return null;
        }
        $created = (int) ($current['created_at'] ?? 0);
        if ($created <= 0 || $created < time() - self::COMMAND_JOURNAL_STALE_SECONDS) {
            delete_option($option);
            return null;
        }
        $stored_sha = isset($current['command_sha256']) ? strtolower((string) $current['command_sha256']) : '';
        if (!preg_match('/^[a-f0-9]{64}$/', $stored_sha) || !hash_equals($stored_sha, $command_sha256)) {
            return new WP_Error(
                'takka_direct_command_journal_conflict',
                'A completed local Direct Runtime execution exists for the same request_id with different command content.',
                ['status' => 409, 'request_id' => $request_id]
            );
        }
        if (!hash_equals((string) ($current['id'] ?? ''), $id)) {
            return new WP_Error(
                'takka_direct_command_journal_id_conflict',
                'A completed local Direct Runtime execution exists for the same request_id with a different command id.',
                ['status' => 409, 'request_id' => $request_id]
            );
        }
        $result_json = isset($current['result_json']) && is_string($current['result_json']) ? $current['result_json'] : '';
        if ($result_json === '' || strlen($result_json) > self::MAX_COMMAND_JOURNAL_BYTES) {
            return new WP_Error('takka_direct_command_journal_invalid', 'Stored Direct Runtime execution journal is invalid.', ['status' => 500]);
        }
        $output = json_decode($result_json, true);
        if (!is_array($output)
            || !hash_equals((string) ($output['id'] ?? ''), $id)
            || !hash_equals((string) ($output['request_id'] ?? ''), $request_id)) {
            return new WP_Error('takka_direct_command_journal_invalid', 'Stored Direct Runtime execution journal does not match this command.', ['status' => 500]);
        }
        return ['output' => $output, 'result_json' => $result_json];
    }

    private static function store_command_journal(string $request_id, string $id, string $command_sha256, string $result_json)
    {
        if (strlen($result_json) < 1 || strlen($result_json) > self::MAX_COMMAND_JOURNAL_BYTES) {
            return new WP_Error('takka_direct_command_journal_size', 'Direct Runtime result is too large for local recovery journaling.', ['status' => 500]);
        }
        $option = self::command_journal_option($request_id);
        $value = [
            'id' => $id,
            'command_sha256' => $command_sha256,
            'created_at' => time(),
            'result_json' => $result_json,
        ];
        update_option($option, $value, false);
        $verify = get_option($option, null);
        if (!is_array($verify)
            || !hash_equals((string) ($verify['id'] ?? ''), $id)
            || !hash_equals((string) ($verify['command_sha256'] ?? ''), $command_sha256)
            || !hash_equals((string) ($verify['result_json'] ?? ''), $result_json)) {
            return new WP_Error('takka_direct_command_journal_store', 'Could not persist Direct Runtime execution journal before GitHub bookkeeping.', ['status' => 500]);
        }
        return true;
    }

    private static function clear_command_journal(string $request_id): void
    {
        delete_option(self::command_journal_option($request_id));
    }

    private static function command_journal_option(string $request_id): string
    {
        return self::COMMAND_JOURNAL_PREFIX . hash('sha256', $request_id);
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
        return (bool) preg_match('/^[A-Za-z0-9_.-]+\\/[A-Za-z0-9_.-]+$/', $repository);
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
