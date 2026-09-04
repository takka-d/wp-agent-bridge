<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Conflict-safe helpers for the self-contained Direct Runtime.
 *
 * These helpers deliberately use the site-specific GitHub App client only.
 * No operator-owned repository, relay, service, or credential is involved.
 */
final class TakKa_WordPress_Bridge_Direct_GitHub_Recovery
{
    public static function list_directory(string $token, string $repository, string $ref, string $path)
    {
        if (!self::valid_repository($repository) || !self::valid_path($path)) {
            return new WP_Error('wpab_direct_recovery_path', 'Invalid GitHub repository or directory path.');
        }

        $response = TakKa_WordPress_Bridge_Direct_GitHub::github_api(
            'GET',
            '/repos/' . $repository . '/contents/' . self::encode_path(trim($path, '/')) . '?ref=' . rawurlencode($ref),
            $token
        );
        if (is_wp_error($response)) {
            if (self::error_status($response) === 404) {
                return [];
            }
            return $response;
        }
        $data = isset($response['data']) && is_array($response['data']) ? $response['data'] : null;
        if (!is_array($data)) {
            return new WP_Error('wpab_direct_recovery_directory', 'GitHub directory response is invalid.');
        }
        return $data;
    }

    public static function branch_sha(string $token, string $repository, string $branch)
    {
        if (!self::valid_repository($repository) || !preg_match('/^[A-Za-z0-9._\/-]{1,200}$/', $branch)) {
            return new WP_Error('wpab_direct_recovery_branch', 'Invalid GitHub repository or branch.');
        }
        $response = TakKa_WordPress_Bridge_Direct_GitHub::github_api(
            'GET',
            '/repos/' . $repository . '/git/ref/heads/' . rawurlencode($branch),
            $token
        );
        if (is_wp_error($response)) {
            return $response;
        }
        $data = isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
        $sha = isset($data['object']['sha']) ? strtolower((string) $data['object']['sha']) : '';
        if (!preg_match('/^[a-f0-9]{40,64}$/', $sha)) {
            return new WP_Error('wpab_direct_recovery_branch_sha', 'GitHub did not return a valid branch commit SHA.');
        }
        return $sha;
    }

    /**
     * Create a bookkeeping file exactly once. Existing identical content is an
     * idempotent success; different content is never overwritten.
     */
    public static function put_if_absent_or_identical(
        string $token,
        string $repository,
        string $branch,
        string $path,
        string $content,
        string $message
    ) {
        if (!self::valid_repository($repository) || !self::valid_path($path)) {
            return new WP_Error('wpab_direct_recovery_path', 'Invalid GitHub repository or file path.');
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $meta = TakKa_WordPress_Bridge_Direct_GitHub::get_content_metadata($token, $repository, $branch, $path);
            if (!is_wp_error($meta)) {
                $existing = TakKa_WordPress_Bridge_Direct_GitHub::get_text_file($token, $repository, $branch, $path);
                if (!is_wp_error($existing) && hash_equals(hash('sha256', $content), hash('sha256', $existing))) {
                    return [
                        'ok' => true,
                        'idempotent' => true,
                        'path' => $path,
                        'sha' => (string) ($meta['sha'] ?? ''),
                    ];
                }
                return new WP_Error(
                    'wpab_direct_recovery_existing_conflict',
                    'GitHub bookkeeping file already exists with different content.',
                    ['status' => 409, 'path' => $path]
                );
            }
            if (self::error_status($meta) !== 404) {
                return $meta;
            }

            $created = TakKa_WordPress_Bridge_Direct_GitHub::github_api(
                'PUT',
                '/repos/' . $repository . '/contents/' . self::encode_path($path),
                $token,
                [
                    'message' => $message,
                    'content' => base64_encode($content),
                    'branch' => $branch,
                ]
            );
            if (!is_wp_error($created)) {
                return $created;
            }

            // A concurrent commit can move the branch after the metadata GET.
            // Re-check once: if another worker created identical content, the
            // desired end state is already true.
            if ($attempt === 0) {
                usleep(100000);
                continue;
            }
            return $created;
        }

        return new WP_Error('wpab_direct_recovery_write', 'Could not create GitHub bookkeeping file.');
    }

    /**
     * Delete only the exact pending blob that was inspected. Branch movement is
     * retried once; changed content is never deleted accidentally.
     */
    public static function delete_if_matches(
        string $token,
        string $repository,
        string $branch,
        string $path,
        string $expected_sha,
        string $message
    ) {
        $expected_sha = strtolower(trim($expected_sha));
        if (!self::valid_repository($repository)
            || !self::valid_path($path)
            || !preg_match('/^[a-f0-9]{40,64}$/', $expected_sha)) {
            return new WP_Error('wpab_direct_recovery_delete', 'Invalid conflict-safe delete request.');
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $meta = TakKa_WordPress_Bridge_Direct_GitHub::get_content_metadata($token, $repository, $branch, $path);
            if (is_wp_error($meta)) {
                if (self::error_status($meta) === 404) {
                    return ['ok' => true, 'idempotent' => true, 'deleted' => true, 'path' => $path];
                }
                return $meta;
            }

            $current_sha = strtolower((string) ($meta['sha'] ?? ''));
            if (!hash_equals($expected_sha, $current_sha)) {
                return new WP_Error(
                    'wpab_direct_recovery_delete_conflict',
                    'Pending command changed after it was inspected; refusing to delete it.',
                    ['status' => 409, 'path' => $path, 'expected_sha' => $expected_sha, 'current_sha' => $current_sha]
                );
            }

            $deleted = TakKa_WordPress_Bridge_Direct_GitHub::delete_file(
                $token,
                $repository,
                $branch,
                $path,
                $current_sha,
                $message
            );
            if (!is_wp_error($deleted)) {
                return $deleted;
            }
            if ($attempt === 0) {
                usleep(100000);
                continue;
            }
            return $deleted;
        }

        return new WP_Error('wpab_direct_recovery_delete_failed', 'Could not delete pending command after retry.');
    }

    public static function error_status($error): int
    {
        if (!is_wp_error($error)) {
            return 0;
        }
        $data = $error->get_error_data();
        return is_array($data) ? (int) ($data['status'] ?? 0) : 0;
    }

    private static function valid_repository(string $repository): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repository);
    }

    private static function valid_path(string $path): bool
    {
        return $path !== ''
            && strlen($path) <= 500
            && strpos($path, '..') === false
            && strpos($path, "\0") === false;
    }

    private static function encode_path(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', trim($path, '/'))));
    }
}
