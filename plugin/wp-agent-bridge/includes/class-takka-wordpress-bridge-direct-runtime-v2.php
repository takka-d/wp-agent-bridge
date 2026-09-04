<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Recovery wrapper for the self-contained Direct Runtime.
 *
 * The original Direct Runtime remains the executor. This wrapper replaces only
 * the public webhook route and adds durable-queue semantics around it:
 * - every valid push re-scans the current commands/pending directory;
 * - bookkeeping left behind by a GitHub branch race is repaired;
 * - commands whose result was not written are re-dispatched with the same
 *   request_id, relying on the Bridge's completed-response idempotency;
 * - a short WordPress-side lock prevents concurrent recovery workers.
 */
final class TakKa_WordPress_Bridge_Direct_Runtime_V2
{
    private const LOCK_OPTION = 'takka_bridge_direct_reconcile_lock_v2';
    private const MAX_RECOVERY_PER_PUSH = 20;
    private const MAX_COMMAND_BYTES = 2097152;

    public static function init(): void
    {
        // Run after the legacy Direct Runtime registers its route, then replace
        // only that route with the recovery wrapper.
        add_action('rest_api_init', [self::class, 'register_routes'], 30);
    }

    public static function register_routes(): void
    {
        register_rest_route(
            TakKa_WordPress_Bridge_Direct_Runtime::NAMESPACE,
            '/github-webhook',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [self::class, 'webhook'],
                'permission_callback' => '__return_true',
            ],
            true
        );
    }

    public static function webhook(WP_REST_Request $request)
    {
        // The original handler remains authoritative for signature validation,
        // repository mapping validation and execution of paths named by this
        // push. Never scan GitHub before that authentication succeeds.
        $primary = TakKa_WordPress_Bridge_Direct_Runtime::webhook($request);
        if (is_wp_error($primary)) {
            return $primary;
        }

        $event = strtolower(trim((string) $request->get_header('x-github-event')));
        if ($event !== 'push') {
            return $primary;
        }

        $payload = json_decode((string) $request->get_body(), true);
        if (!is_array($payload)
            || ($payload['ref'] ?? '') !== 'refs/heads/' . TakKa_WordPress_Bridge_Direct_Runtime::RUNTIME_BRANCH
            || !empty($payload['deleted'])) {
            return $primary;
        }

        $recovery = self::reconcile_pending($payload);
        $response = rest_ensure_response($primary);
        $data = $response->get_data();
        if (!is_array($data)) {
            $data = ['primary' => $data];
        }
        if (is_wp_error($recovery)) {
            $data['pending_recovery'] = [
                'ok' => false,
                'error' => $recovery->get_error_message(),
                'status' => TakKa_WordPress_Bridge_Direct_GitHub_Recovery::error_status($recovery),
            ];
        } else {
            $data['pending_recovery'] = $recovery;
        }
        $response->set_data($data);
        return $response;
    }

    private static function reconcile_pending(array $payload)
    {
        $lock_token = self::acquire_lock();
        if ($lock_token === null) {
            return ['ok' => true, 'busy' => true, 'processed' => 0, 'deferred' => 0];
        }

        try {
            $connection = TakKa_WordPress_Bridge_Direct_Runtime::connection();
            $repository_id = (int) ($payload['repository']['id'] ?? 0);
            $repository = isset($payload['repository']['full_name']) ? (string) $payload['repository']['full_name'] : '';
            $installation_id = (int) ($payload['installation']['id'] ?? 0);
            $private = isset($payload['repository']['private']) ? (bool) $payload['repository']['private'] : false;

            if (!$private
                || $repository_id < 1
                || $installation_id < 1
                || !self::valid_repository($repository)
                || (int) ($connection['repository_id'] ?? 0) !== $repository_id
                || (int) ($connection['installation_id'] ?? 0) !== $installation_id
                || !hash_equals((string) ($connection['repository'] ?? ''), $repository)) {
                return new WP_Error('wpab_direct_recovery_mapping', 'Recovery push does not match the active Direct Runtime connection.', ['status' => 404]);
            }

            $token = TakKa_WordPress_Bridge_Direct_GitHub::installation_token($installation_id, $repository_id);
            if (is_wp_error($token)) {
                return $token;
            }

            $entries = TakKa_WordPress_Bridge_Direct_GitHub_Recovery::list_directory(
                $token,
                $repository,
                TakKa_WordPress_Bridge_Direct_Runtime::RUNTIME_BRANCH,
                'wordpress-bridge/commands/pending'
            );
            if (is_wp_error($entries)) {
                return $entries;
            }

            $paths = [];
            foreach ($entries as $entry) {
                if (!is_array($entry) || (string) ($entry['type'] ?? '') !== 'file') {
                    continue;
                }
                $name = (string) ($entry['name'] ?? '');
                if (!preg_match('/^[A-Za-z0-9._-]{1,120}\.json$/', $name)) {
                    continue;
                }
                $paths[] = 'wordpress-bridge/commands/pending/' . $name;
            }
            sort($paths, SORT_STRING);

            $deferred = max(0, count($paths) - self::MAX_RECOVERY_PER_PUSH);
            $paths = array_slice($paths, 0, self::MAX_RECOVERY_PER_PUSH);
            $outcomes = [];
            foreach ($paths as $path) {
                $outcomes[] = self::recover_path($token, $repository, $repository_id, $installation_id, $path);
            }

            return [
                'ok' => true,
                'busy' => false,
                'processed' => count($outcomes),
                'deferred' => $deferred,
                'commands' => $outcomes,
            ];
        } finally {
            self::release_lock($lock_token);
        }
    }

    private static function recover_path(
        string $token,
        string $repository,
        int $repository_id,
        int $installation_id,
        string $path
    ): array {
        $meta = TakKa_WordPress_Bridge_Direct_GitHub::get_content_metadata(
            $token,
            $repository,
            TakKa_WordPress_Bridge_Direct_Runtime::RUNTIME_BRANCH,
            $path
        );
        if (is_wp_error($meta)) {
            if (TakKa_WordPress_Bridge_Direct_GitHub_Recovery::error_status($meta) === 404) {
                return ['path' => $path, 'ok' => true, 'skipped' => true, 'reason' => 'no-longer-pending'];
            }
            return self::error_outcome($path, '', $meta->get_error_message());
        }
        $pending_sha = strtolower((string) ($meta['sha'] ?? ''));
        if (!preg_match('/^[a-f0-9]{40,64}$/', $pending_sha)) {
            return self::error_outcome($path, '', 'Pending command has no valid GitHub blob SHA.');
        }

        $raw = TakKa_WordPress_Bridge_Direct_GitHub::get_text_file(
            $token,
            $repository,
            TakKa_WordPress_Bridge_Direct_Runtime::RUNTIME_BRANCH,
            $path
        );
        if (is_wp_error($raw)) {
            return self::error_outcome($path, '', $raw->get_error_message());
        }
        if (strlen($raw) > self::MAX_COMMAND_BYTES) {
            return self::error_outcome($path, '', 'Command exceeds 2 MiB.');
        }

        $command = json_decode($raw, true);
        if (!is_array($command)) {
            return self::error_outcome($path, '', 'Command JSON is invalid.');
        }
        $basename = basename($path, '.json');
        $id = isset($command['id']) ? (string) $command['id'] : $basename;
        $request_id = isset($command['request_id']) ? (string) $command['request_id'] : $id;
        if (!self::valid_id($id) || !self::valid_id($request_id)) {
            return self::error_outcome($path, '', 'Unsafe id or request_id.');
        }

        $result_path = 'wordpress-bridge/results/' . $id . '.json';
        $completed_path = 'wordpress-bridge/commands/completed/' . basename($path);

        $existing_result = TakKa_WordPress_Bridge_Direct_GitHub::get_text_file(
            $token,
            $repository,
            TakKa_WordPress_Bridge_Direct_Runtime::RUNTIME_BRANCH,
            $result_path
        );
        if (!is_wp_error($existing_result)) {
            $repaired = self::repair_bookkeeping(
                $token,
                $repository,
                $path,
                $pending_sha,
                $raw,
                $id,
                $request_id,
                $result_path,
                $completed_path,
                $existing_result
            );
            if (is_wp_error($repaired)) {
                return self::error_outcome($path, $id, $repaired->get_error_message());
            }
            return $repaired;
        }
        if (TakKa_WordPress_Bridge_Direct_GitHub_Recovery::error_status($existing_result) !== 404) {
            return self::error_outcome($path, $id, $existing_result->get_error_message());
        }

        // No GitHub result exists. Re-dispatch the exact current pending command
        // through the original authenticated executor. If WordPress executed it
        // previously, the same request_id is replayed from Bridge idempotency.
        $after = TakKa_WordPress_Bridge_Direct_GitHub_Recovery::branch_sha(
            $token,
            $repository,
            TakKa_WordPress_Bridge_Direct_Runtime::RUNTIME_BRANCH
        );
        if (is_wp_error($after)) {
            return self::error_outcome($path, $id, $after->get_error_message());
        }

        $synthetic_payload = [
            'ref' => 'refs/heads/' . TakKa_WordPress_Bridge_Direct_Runtime::RUNTIME_BRANCH,
            'deleted' => false,
            'after' => $after,
            'repository' => [
                'id' => $repository_id,
                'full_name' => $repository,
                'private' => true,
            ],
            'installation' => ['id' => $installation_id],
            'commits' => [[
                'added' => [$path],
                'modified' => [],
                'removed' => [],
            ]],
        ];
        $synthetic_raw = wp_json_encode($synthetic_payload, JSON_UNESCAPED_SLASHES);
        $secret = TakKa_WordPress_Bridge_Direct_GitHub::webhook_secret();
        if (!is_string($synthetic_raw) || strlen($secret) < 20) {
            return self::error_outcome($path, $id, 'Could not construct authenticated recovery webhook.');
        }

        $synthetic = new WP_REST_Request('POST', TakKa_WordPress_Bridge_Direct_Runtime::WEBHOOK_ROUTE);
        $synthetic->set_header('x-github-event', 'push');
        $synthetic->set_header('x-hub-signature-256', 'sha256=' . hash_hmac('sha256', $synthetic_raw, $secret));
        $synthetic->set_header('content-type', 'application/json');
        $synthetic->set_body($synthetic_raw);
        $executed = TakKa_WordPress_Bridge_Direct_Runtime::webhook($synthetic);
        if (is_wp_error($executed)) {
            return self::error_outcome($path, $id, $executed->get_error_message());
        }

        // If execution created the result but a later GitHub bookkeeping write
        // raced, finish the remaining bookkeeping during this same recovery pass.
        $result_after = TakKa_WordPress_Bridge_Direct_GitHub::get_text_file(
            $token,
            $repository,
            TakKa_WordPress_Bridge_Direct_Runtime::RUNTIME_BRANCH,
            $result_path
        );
        $pending_after = TakKa_WordPress_Bridge_Direct_GitHub::get_content_metadata(
            $token,
            $repository,
            TakKa_WordPress_Bridge_Direct_Runtime::RUNTIME_BRANCH,
            $path
        );

        if (!is_wp_error($result_after) && !is_wp_error($pending_after)) {
            $current_sha = strtolower((string) ($pending_after['sha'] ?? ''));
            if (hash_equals($pending_sha, $current_sha)) {
                $repaired = self::repair_bookkeeping(
                    $token,
                    $repository,
                    $path,
                    $pending_sha,
                    $raw,
                    $id,
                    $request_id,
                    $result_path,
                    $completed_path,
                    $result_after
                );
                if (!is_wp_error($repaired)) {
                    $repaired['reexecuted_or_replayed'] = true;
                    return $repaired;
                }
            }
        }

        if (is_wp_error($pending_after)
            && TakKa_WordPress_Bridge_Direct_GitHub_Recovery::error_status($pending_after) === 404
            && !is_wp_error($result_after)) {
            return [
                'path' => $path,
                'id' => $id,
                'ok' => true,
                'reexecuted_or_replayed' => true,
                'recovered' => true,
            ];
        }

        return [
            'path' => $path,
            'id' => $id,
            'ok' => true,
            'reexecuted_or_replayed' => true,
            'pending' => !is_wp_error($pending_after),
        ];
    }

    private static function repair_bookkeeping(
        string $token,
        string $repository,
        string $pending_path,
        string $pending_sha,
        string $raw_command,
        string $id,
        string $request_id,
        string $result_path,
        string $completed_path,
        string $result_json
    ) {
        $result = json_decode($result_json, true);
        if (!is_array($result)
            || !hash_equals((string) ($result['id'] ?? ''), $id)
            || !hash_equals((string) ($result['request_id'] ?? ''), $request_id)) {
            return new WP_Error(
                'wpab_direct_recovery_result_conflict',
                'Existing result does not match the pending command id/request_id.',
                ['status' => 409, 'path' => $result_path]
            );
        }

        $completed = TakKa_WordPress_Bridge_Direct_GitHub_Recovery::put_if_absent_or_identical(
            $token,
            $repository,
            TakKa_WordPress_Bridge_Direct_Runtime::RUNTIME_BRANCH,
            $completed_path,
            $raw_command,
            'WP Bridge: recover completed command ' . $id
        );
        if (is_wp_error($completed)) {
            return $completed;
        }

        $deleted = TakKa_WordPress_Bridge_Direct_GitHub_Recovery::delete_if_matches(
            $token,
            $repository,
            TakKa_WordPress_Bridge_Direct_Runtime::RUNTIME_BRANCH,
            $pending_path,
            $pending_sha,
            'WP Bridge: remove recovered pending command ' . $id
        );
        if (is_wp_error($deleted)) {
            return $deleted;
        }

        return [
            'path' => $pending_path,
            'id' => $id,
            'ok' => true,
            'recovered' => true,
            'bookkeeping_only' => true,
        ];
    }

    private static function acquire_lock(): ?string
    {
        $token = wp_generate_uuid4();
        $value = ['token' => $token, 'created_at' => time()];
        if (add_option(self::LOCK_OPTION, $value, '', false)) {
            return $token;
        }

        $current = get_option(self::LOCK_OPTION, []);
        $created = is_array($current) ? (int) ($current['created_at'] ?? 0) : 0;
        if ($created > 0 && $created < time() - 90) {
            delete_option(self::LOCK_OPTION);
            if (add_option(self::LOCK_OPTION, $value, '', false)) {
                return $token;
            }
        }
        return null;
    }

    private static function release_lock(string $token): void
    {
        $current = get_option(self::LOCK_OPTION, []);
        if (is_array($current) && hash_equals((string) ($current['token'] ?? ''), $token)) {
            delete_option(self::LOCK_OPTION);
        }
    }

    private static function valid_id(string $id): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9._-]{1,120}$/', $id);
    }

    private static function valid_repository(string $repository): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repository);
    }

    private static function error_outcome(string $path, string $id, string $message): array
    {
        return ['path' => $path, 'id' => $id, 'ok' => false, 'error' => $message];
    }
}
