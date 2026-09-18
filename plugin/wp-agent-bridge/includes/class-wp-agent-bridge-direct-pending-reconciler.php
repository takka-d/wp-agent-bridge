<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Detailed second-pass reconciler for Direct Runtime pending commands.
 *
 * The v1.1.28 scheduled fallback restored liveness when a GitHub push webhook
 * was missed, but its health summary counted inspected pending files as
 * "processed" even when a file stayed pending without a result. This class
 * runs after that fallback on the same WP-Cron hook, reuses the canonical
 * Direct Runtime executor, and records bounded per-command terminal evidence.
 *
 * It never invents a second transport and never replays a command that is
 * currently in flight. The same request_id is retained so the existing Bridge
 * idempotency/journal safeguards remain authoritative.
 */
final class WP_Agent_Bridge_Direct_Pending_Reconciler
{
    private const CRON_HOOK = 'wpab_direct_reconcile_cron_v4';
    private const LAST_OPTION = 'wpab_direct_reconcile_detail_v5';
    private const MAX_COMMANDS = 20;
    private const MAX_COMMAND_BYTES = 2097152;
    private const MAX_RECOVERY_AGE_SECONDS = 86400;

    public static function init(): void
    {
        add_action(self::CRON_HOOK, [self::class, 'run'], 20);
        add_filter('rest_request_after_callbacks', [self::class, 'annotate_health'], 630, 3);
    }

    public static function run(): void
    {
        $started = microtime(true);
        $connection = WP_Agent_Bridge_Direct_Runtime::connection();
        $repository_id = (int) ($connection['repository_id'] ?? 0);
        $installation_id = (int) ($connection['installation_id'] ?? 0);
        $repository = trim((string) ($connection['repository'] ?? ''));
        $branch = (string) ($connection['runtime_branch'] ?? '');

        if ($repository_id < 1
            || $installation_id < 1
            || !preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repository)
            || $branch !== WP_Agent_Bridge_Direct_Runtime::RUNTIME_BRANCH) {
            self::store([
                'ok' => false,
                'code' => 'wpab_pending_reconciler_connection',
                'message' => 'Direct Runtime connection is incomplete.',
                'duration_ms' => self::elapsed_ms($started),
            ]);
            return;
        }

        $token = WP_Agent_Bridge_Direct_GitHub::installation_token($installation_id, $repository_id);
        if (is_wp_error($token)) {
            self::store_error($token, $started);
            return;
        }

        $entries = WP_Agent_Bridge_Direct_GitHub_Recovery::list_directory(
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
        $total_pending = count($paths);
        $paths = array_slice($paths, 0, self::MAX_COMMANDS);

        $outcomes = [];
        foreach ($paths as $path) {
            $outcomes[] = self::reconcile_path(
                $token,
                $repository,
                $branch,
                $repository_id,
                $installation_id,
                $path
            );
        }

        $failed = 0;
        $unresolved = 0;
        $skipped = 0;
        $recovered = 0;
        foreach ($outcomes as $outcome) {
            if (empty($outcome['ok'])) {
                $failed++;
            }
            if (!empty($outcome['pending']) || !empty($outcome['recovery_required'])) {
                $unresolved++;
            }
            if (!empty($outcome['skipped'])) {
                $skipped++;
            }
            if (!empty($outcome['recovered'])) {
                $recovered++;
            }
        }

        self::store([
            'ok' => $failed === 0 && $unresolved === 0,
            'pending_seen' => $total_pending,
            'inspected' => count($outcomes),
            'deferred' => max(0, $total_pending - count($outcomes)),
            'failed' => $failed,
            'unresolved' => $unresolved,
            'skipped' => $skipped,
            'recovered' => $recovered,
            'commands' => $outcomes,
            'duration_ms' => self::elapsed_ms($started),
        ]);
    }

    public static function annotate_health($response, array $handler, WP_REST_Request $request)
    {
        if ($request->get_route() !== '/wp-agent-bridge/v1/health' || is_wp_error($response)) {
            return $response;
        }
        $rest = rest_ensure_response($response);
        $data = $rest->get_data();
        if (!is_array($data)) {
            return $response;
        }
        $features = isset($data['features']) && is_array($data['features']) ? $data['features'] : [];
        if (!in_array('pending_recovery_terminal_observation', $features, true)) {
            $features[] = 'pending_recovery_terminal_observation';
        }
        $data['features'] = $features;
        $data['pending_recovery_detail'] = get_option(self::LAST_OPTION, null);
        $rest->set_data($data);
        return $rest;
    }

    private static function reconcile_path(
        string $token,
        string $repository,
        string $branch,
        int $repository_id,
        int $installation_id,
        string $path
    ): array {
        $meta = WP_Agent_Bridge_Direct_GitHub::get_content_metadata($token, $repository, $branch, $path);
        if (is_wp_error($meta)) {
            if (WP_Agent_Bridge_Direct_GitHub_Recovery::error_status($meta) === 404) {
                return self::summary($path, '', true, ['skipped' => true, 'reason' => 'no-longer-pending']);
            }
            return self::summary($path, '', false, ['reason' => 'pending-metadata-read-failed', 'error' => $meta->get_error_message(), 'recovery_required' => true]);
        }

        $raw = WP_Agent_Bridge_Direct_GitHub::get_text_file($token, $repository, $branch, $path);
        if (is_wp_error($raw)) {
            return self::summary($path, '', false, ['reason' => 'pending-read-failed', 'error' => $raw->get_error_message(), 'recovery_required' => true]);
        }
        if (strlen($raw) > self::MAX_COMMAND_BYTES) {
            return self::summary($path, '', false, ['reason' => 'command-too-large', 'error' => 'Command exceeds 2 MiB.', 'pending' => true]);
        }

        $command = json_decode($raw, true);
        if (!is_array($command)) {
            return self::summary($path, '', false, ['reason' => 'invalid-command-json', 'error' => 'Command JSON is invalid.', 'pending' => true]);
        }
        $id = isset($command['id']) ? (string) $command['id'] : basename($path, '.json');
        $request_id = isset($command['request_id']) ? (string) $command['request_id'] : $id;
        if (!self::valid_id($id) || !self::valid_id($request_id)) {
            return self::summary($path, $id, false, ['reason' => 'invalid-command-id', 'error' => 'Unsafe id or request_id.', 'pending' => true]);
        }

        $result_path = 'wordpress-bridge/results/' . $id . '.json';
        $existing = WP_Agent_Bridge_Direct_GitHub::get_text_file($token, $repository, $branch, $result_path);
        if (!is_wp_error($existing)) {
            return self::summary($path, $id, true, ['reason' => 'result-already-visible', 'recovered' => true]);
        }
        $existing_status = WP_Agent_Bridge_Direct_GitHub_Recovery::error_status($existing);
        if ($existing_status !== 404) {
            return self::summary($path, $id, false, ['reason' => 'result-read-failed', 'error' => $existing->get_error_message(), 'recovery_required' => true]);
        }

        $age = self::command_age($command, $id);
        if ($age === null) {
            $last_modified = WP_Agent_Bridge_Direct_GitHub_Recovery::path_last_modified_timestamp($token, $repository, $branch, $path);
            if (!is_wp_error($last_modified)) {
                $age = max(0, time() - (int) $last_modified);
            }
        }
        if ($age === null) {
            return self::summary($path, $id, true, ['skipped' => true, 'reason' => 'age-unavailable', 'recovery_required' => true]);
        }
        if ($age > self::MAX_RECOVERY_AGE_SECONDS) {
            return self::summary($path, $id, true, ['skipped' => true, 'reason' => 'expired-awaiting-v2-quarantine', 'pending_age_seconds' => $age, 'recovery_required' => true]);
        }

        if (WP_Agent_Bridge_Direct_Runtime::command_inflight($request_id)) {
            return self::summary($path, $id, true, ['skipped' => true, 'reason' => 'command-in-flight', 'pending_age_seconds' => $age, 'recovery_required' => true]);
        }

        $after = WP_Agent_Bridge_Direct_GitHub_Recovery::branch_sha($token, $repository, $branch);
        if (is_wp_error($after)) {
            return self::summary($path, $id, false, ['reason' => 'branch-read-failed', 'error' => $after->get_error_message(), 'recovery_required' => true]);
        }
        $secret = WP_Agent_Bridge_Direct_GitHub::webhook_secret();
        $payload = [
            'ref' => 'refs/heads/' . $branch,
            'deleted' => false,
            'after' => $after,
            'repository' => ['id' => $repository_id, 'full_name' => $repository, 'private' => true],
            'installation' => ['id' => $installation_id],
            'commits' => [[
                'added' => [$path],
                'modified' => [],
                'removed' => [],
            ]],
        ];
        $synthetic_raw = wp_json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($synthetic_raw) || strlen($secret) < 20) {
            return self::summary($path, $id, false, ['reason' => 'synthetic-request-build-failed', 'recovery_required' => true]);
        }

        $request = new WP_REST_Request('POST', WP_Agent_Bridge_Direct_Runtime::WEBHOOK_ROUTE);
        $request->set_header('x-github-event', 'push');
        $request->set_header('x-hub-signature-256', 'sha256=' . hash_hmac('sha256', $synthetic_raw, $secret));
        $request->set_header('content-type', 'application/json');
        $request->set_body($synthetic_raw);
        $executed = WP_Agent_Bridge_Direct_Runtime::webhook($request);
        if (is_wp_error($executed)) {
            return self::summary($path, $id, false, ['reason' => 'synthetic-executor-error', 'error' => $executed->get_error_message(), 'recovery_required' => true]);
        }

        $execution_data = rest_ensure_response($executed)->get_data();
        $target = null;
        if (is_array($execution_data) && isset($execution_data['commands']) && is_array($execution_data['commands'])) {
            foreach ($execution_data['commands'] as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }
                if (($candidate['path'] ?? null) === $path || ($candidate['id'] ?? null) === $id) {
                    $target = $candidate;
                    break;
                }
            }
        }

        $result_after = WP_Agent_Bridge_Direct_GitHub::get_text_file($token, $repository, $branch, $result_path);
        $pending_after = WP_Agent_Bridge_Direct_GitHub::get_content_metadata($token, $repository, $branch, $path);
        $result_visible = !is_wp_error($result_after);
        $pending_visible = !is_wp_error($pending_after);
        $pending_status = is_wp_error($pending_after) ? WP_Agent_Bridge_Direct_GitHub_Recovery::error_status($pending_after) : 200;

        if ($result_visible && $pending_status === 404) {
            return self::summary($path, $id, true, [
                'reason' => 'recovered',
                'recovered' => true,
                'executor_outcome' => self::bounded_executor_outcome($target),
            ]);
        }
        if (is_array($target) && empty($target['ok'])) {
            return self::summary($path, $id, false, [
                'reason' => 'executor-command-failed',
                'error' => isset($target['error']) ? (string) $target['error'] : 'Direct Runtime command failed before a durable result became visible.',
                'status' => isset($target['status']) ? (int) $target['status'] : null,
                'pending' => $pending_visible,
                'result_visible' => $result_visible,
                'pending_age_seconds' => $age,
                'executor_outcome' => self::bounded_executor_outcome($target),
                'recovery_required' => true,
            ]);
        }
        if ($result_visible && $pending_visible) {
            return self::summary($path, $id, false, [
                'reason' => 'result-visible-pending-not-finalized',
                'pending' => true,
                'result_visible' => true,
                'executor_outcome' => self::bounded_executor_outcome($target),
                'recovery_required' => true,
            ]);
        }
        return self::summary($path, $id, false, [
            'reason' => is_array($target) ? 'executor-returned-without-result' : 'target-command-outcome-missing',
            'pending' => $pending_visible,
            'result_visible' => $result_visible,
            'pending_age_seconds' => $age,
            'executor_outcome' => self::bounded_executor_outcome($target),
            'recovery_required' => true,
        ]);
    }

    private static function bounded_executor_outcome($outcome)
    {
        if (!is_array($outcome)) {
            return null;
        }
        $bounded = [];
        foreach (['id', 'path', 'ok', 'status', 'error', 'in_flight', 'journal_replayed', 'atomic_bookkeeping', 'bookkeeping_commit', 'bookkeeping_attempts', 'bookkeeping_verified_after_error'] as $key) {
            if (array_key_exists($key, $outcome)) {
                $bounded[$key] = is_string($outcome[$key]) && strlen($outcome[$key]) > 500
                    ? substr($outcome[$key], 0, 500) . '[truncated]'
                    : $outcome[$key];
            }
        }
        return $bounded;
    }

    private static function summary(string $path, string $id, bool $ok, array $extra): array
    {
        return array_merge([
            'path' => $path,
            'id' => $id,
            'ok' => $ok,
        ], $extra);
    }

    private static function command_age(array $command, string $id): ?int
    {
        $created = $command['created_at'] ?? null;
        $timestamp = 0;
        if (is_int($created) || (is_string($created) && ctype_digit($created))) {
            $timestamp = (int) $created;
        } elseif (is_string($created) && trim($created) !== '') {
            $parsed = strtotime($created);
            $timestamp = $parsed === false ? 0 : $parsed;
        }
        if ($timestamp < 1 && preg_match('/(\d{13})$/', $id, $match)) {
            $timestamp = (int) floor(((int) $match[1]) / 1000);
        }
        if ($timestamp < 1 && preg_match('/(20\d{6})[-_](\d{4,6})$/', $id, $match)) {
            $date = DateTimeImmutable::createFromFormat('!Ymd-His', $match[1] . '-' . str_pad($match[2], 6, '0'), new DateTimeZone('UTC'));
            if ($date instanceof DateTimeImmutable) {
                $timestamp = $date->getTimestamp();
            }
        }
        return $timestamp > 0 ? max(0, time() - $timestamp) : null;
    }

    private static function valid_id(string $id): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9._-]{1,120}$/', $id);
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
