<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Quarantine permanently unexecutable Direct Runtime command files.
 *
 * A malformed JSON command can be committed successfully to the canonical
 * runtime repository but can never reach a WordPress operation. Prior runtime
 * versions returned an in-memory error and left that file in commands/pending,
 * so every later recovery pass saw the same zombie command again.
 *
 * This handler runs before normal scheduled recovery. Invalid JSON is archived,
 * a durable terminal result is written, and only the exact inspected pending
 * blob is removed. No WordPress mutation is attempted.
 */
final class TakKa_WordPress_Bridge_Direct_Invalid_Pending
{
    private const CRON_HOOK = 'takka_bridge_direct_reconcile_cron_v4';
    private const LAST_OPTION = 'takka_bridge_direct_invalid_pending_v6';
    private const MAX_COMMANDS = 20;
    private const MAX_COMMAND_BYTES = 2097152;

    public static function init(): void
    {
        add_action(self::CRON_HOOK, [self::class, 'run'], 1);
        add_filter('rest_request_after_callbacks', [self::class, 'annotate_health'], 625, 3);
    }

    public static function run(): void
    {
        $started = microtime(true);
        $connection = TakKa_WordPress_Bridge_Direct_Runtime::connection();
        $repository_id = (int) ($connection['repository_id'] ?? 0);
        $installation_id = (int) ($connection['installation_id'] ?? 0);
        $repository = trim((string) ($connection['repository'] ?? ''));
        $branch = (string) ($connection['runtime_branch'] ?? '');

        if ($repository_id < 1
            || $installation_id < 1
            || !preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repository)
            || $branch !== TakKa_WordPress_Bridge_Direct_Runtime::RUNTIME_BRANCH) {
            self::store([
                'ok' => false,
                'code' => 'wpab_invalid_pending_connection',
                'message' => 'Direct Runtime connection is incomplete.',
                'duration_ms' => self::elapsed_ms($started),
            ]);
            return;
        }

        $token = TakKa_WordPress_Bridge_Direct_GitHub::installation_token($installation_id, $repository_id);
        if (is_wp_error($token)) {
            self::store_error($token, $started);
            return;
        }

        $entries = TakKa_WordPress_Bridge_Direct_GitHub_Recovery::list_directory(
            $token,
            $repository,
            $branch,
            'wordpress-bridge/commands/pending'
        );
        if (is_wp_error($entries)) {
            self::store_error($entries, $started);
            return;
        }

        $paths = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || (string) ($entry['type'] ?? '') !== 'file') {
                continue;
            }
            $name = (string) ($entry['name'] ?? '');
            if (preg_match('/^[A-Za-z0-9._-]{1,120}\.json$/', $name)) {
                $paths[] = 'wordpress-bridge/commands/pending/' . $name;
            }
        }
        sort($paths, SORT_STRING);
        $paths = array_slice($paths, 0, self::MAX_COMMANDS);

        $outcomes = [];
        foreach ($paths as $path) {
            $outcome = self::inspect_path($token, $repository, $branch, $path);
            if ($outcome !== null) {
                $outcomes[] = $outcome;
            }
        }

        $failed = 0;
        $quarantined = 0;
        foreach ($outcomes as $outcome) {
            if (empty($outcome['ok'])) {
                $failed++;
            }
            if (!empty($outcome['quarantined'])) {
                $quarantined++;
            }
        }
        self::store([
            'ok' => $failed === 0,
            'inspected_invalid' => count($outcomes),
            'quarantined' => $quarantined,
            'failed' => $failed,
            'commands' => $outcomes,
            'duration_ms' => self::elapsed_ms($started),
        ]);
    }

    public static function annotate_health($response, array $handler, WP_REST_Request $request)
    {
        if ($request->get_route() !== '/takka-bridge/v1/health' || is_wp_error($response)) {
            return $response;
        }
        $rest = rest_ensure_response($response);
        $data = $rest->get_data();
        if (!is_array($data)) {
            return $response;
        }
        $features = isset($data['features']) && is_array($data['features']) ? $data['features'] : [];
        if (!in_array('invalid_pending_quarantine', $features, true)) {
            $features[] = 'invalid_pending_quarantine';
        }
        $data['features'] = $features;
        $data['invalid_pending_quarantine'] = get_option(self::LAST_OPTION, null);
        $rest->set_data($data);
        return $rest;
    }

    private static function inspect_path(string $token, string $repository, string $branch, string $path): ?array
    {
        $meta = TakKa_WordPress_Bridge_Direct_GitHub::get_content_metadata($token, $repository, $branch, $path);
        if (is_wp_error($meta)) {
            return null;
        }
        $pending_sha = strtolower((string) ($meta['sha'] ?? ''));
        if (!preg_match('/^[a-f0-9]{40,64}$/', $pending_sha)) {
            return [
                'path' => $path,
                'ok' => false,
                'reason' => 'invalid-pending-blob-sha',
            ];
        }

        $raw = TakKa_WordPress_Bridge_Direct_GitHub::get_text_file($token, $repository, $branch, $path);
        if (is_wp_error($raw)) {
            return null;
        }
        if (strlen($raw) > self::MAX_COMMAND_BYTES) {
            return null;
        }

        $command = json_decode($raw, true);
        if (is_array($command)) {
            return null;
        }

        $id = basename($path, '.json');
        if (!preg_match('/^[A-Za-z0-9._-]{1,120}$/', $id)) {
            return [
                'path' => $path,
                'ok' => false,
                'reason' => 'invalid-fallback-id',
            ];
        }

        $invalid_path = 'wordpress-bridge/commands/invalid/' . basename($path);
        $result_path = 'wordpress-bridge/results/' . $id . '.json';
        $result = [
            'id' => $id,
            'request_id' => $id,
            'outcome' => [
                'state' => 'failed',
                'command_execution_finished' => false,
                'operation_ok' => false,
                'status' => 400,
                'reason' => 'invalid_command_json',
            ],
            'command_file' => $path,
            'handled_at' => gmdate('c'),
            'transport' => 'direct-github-webhook',
            'recovery' => [
                'code' => 'takka_bridge_pending_invalid_json',
                'quarantine_path' => $invalid_path,
                'side_effects' => false,
            ],
            'result' => [
                'ok' => false,
                'status' => 400,
                'code' => 'takka_bridge_pending_invalid_json',
                'message' => 'Pending command JSON is invalid and was quarantined without execution.',
            ],
        ];
        $result_json = wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($result_json)) {
            return [
                'path' => $path,
                'id' => $id,
                'ok' => false,
                'reason' => 'result-encode-failed',
            ];
        }
        $result_json .= "\n";

        // Self-healing order: archive first, then terminal result, then delete
        // only the exact pending blob that was inspected. Each step is
        // idempotent and refuses to overwrite different existing content.
        $archived = TakKa_WordPress_Bridge_Direct_GitHub_Recovery::put_if_absent_or_identical(
            $token,
            $repository,
            $branch,
            $invalid_path,
            $raw,
            'WP Bridge: quarantine invalid pending command ' . $id
        );
        if (is_wp_error($archived)) {
            return self::error_outcome($path, $id, 'archive-failed', $archived);
        }

        $written = TakKa_WordPress_Bridge_Direct_GitHub_Recovery::put_if_absent_or_identical(
            $token,
            $repository,
            $branch,
            $result_path,
            $result_json,
            'WP Bridge: record invalid pending command ' . $id
        );
        if (is_wp_error($written)) {
            return self::error_outcome($path, $id, 'result-write-failed', $written);
        }

        $deleted = TakKa_WordPress_Bridge_Direct_GitHub_Recovery::delete_if_matches(
            $token,
            $repository,
            $branch,
            $path,
            $pending_sha,
            'WP Bridge: remove invalid pending command ' . $id
        );
        if (is_wp_error($deleted)) {
            return self::error_outcome($path, $id, 'pending-delete-failed', $deleted);
        }

        return [
            'path' => $path,
            'id' => $id,
            'ok' => true,
            'quarantined' => true,
            'reason' => 'invalid-command-json',
            'result_path' => $result_path,
            'quarantine_path' => $invalid_path,
            'side_effects' => false,
        ];
    }

    private static function error_outcome(string $path, string $id, string $reason, WP_Error $error): array
    {
        $data = $error->get_error_data();
        return [
            'path' => $path,
            'id' => $id,
            'ok' => false,
            'reason' => $reason,
            'error_code' => $error->get_error_code(),
            'error' => $error->get_error_message(),
            'status' => is_array($data) && isset($data['status']) ? (int) $data['status'] : null,
        ];
    }

    private static function elapsed_ms(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    private static function store(array $value): void
    {
        $value['ran_at_gmt'] = gmdate('c');
        update_option(self::LAST_OPTION, $value, false);
    }

    private static function store_error(WP_Error $error, float $started): void
    {
        $data = $error->get_error_data();
        self::store([
            'ok' => false,
            'code' => $error->get_error_code(),
            'message' => $error->get_error_message(),
            'status' => is_array($data) && isset($data['status']) ? (int) $data['status'] : null,
            'duration_ms' => self::elapsed_ms($started),
        ]);
    }
}
