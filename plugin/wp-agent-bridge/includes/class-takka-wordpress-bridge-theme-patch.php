<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Extends the existing theme.file.patch action with bounded atomic multi-patch
 * support. The v0.4 action remains the storage/write implementation for legacy
 * single replacements; this layer handles multi-edit plans before the callback
 * so large theme assets never need a client-side full-file rewrite.
 */
final class TakKa_WordPress_Bridge_Theme_Patch
{
    private const V04_ROUTE = '/takka-bridge/v1/manage';
    private const MAX_FILE_BYTES = 2097152;
    private const MAX_PATCHES = 32;
    private const MAX_DIFF_BYTES = 12000;
    private const DIFF_CONTEXT_BYTES = 1800;

    public static function init(): void
    {
        add_filter('rest_request_before_callbacks', [self::class, 'intercept'], 72, 3);
    }

    public static function intercept($response, array $handler, WP_REST_Request $request)
    {
        if ($response !== null
            || $request->get_route() !== self::V04_ROUTE
            || strtoupper($request->get_method()) !== 'POST'
            || !current_user_can('manage_options')) {
            return $response;
        }

        $json = $request->get_json_params();
        if (!is_array($json) || !isset($json['payload_b64']) || !is_string($json['payload_b64'])) {
            return $response;
        }
        $decoded = base64_decode(trim($json['payload_b64']), true);
        if (!is_string($decoded)) {
            return $response;
        }
        $payload = json_decode($decoded, true);
        if (!is_array($payload) || ($payload['action'] ?? '') !== 'theme.file.patch') {
            return $response;
        }
        $params = isset($payload['params']) && is_array($payload['params']) ? $payload['params'] : [];

        // Preserve the long-standing v0.4 single-replacement behavior unless a
        // caller opts into the advanced contract. High-level clients use the
        // multi-patch form so preview/apply can stay atomic and bounded.
        if (!array_key_exists('patches', $params)
            && !array_key_exists('expected_plan_hash', $params)
            && !array_key_exists('expected_after_sha256', $params)) {
            return $response;
        }

        return self::execute($params);
    }

    public static function execute(array $params)
    {
        $path = isset($params['path']) && is_string($params['path']) ? trim($params['path']) : '';
        if ($path === '' || strlen($path) > 4096) {
            return new WP_Error('wpab_theme_patch_path', 'path must be a non-empty theme-relative path.', ['status' => 400]);
        }

        $patches = self::normalize_patches($params);
        if (is_wp_error($patches)) {
            return $patches;
        }

        $context = ['path' => $path];
        if (isset($params['draft_id']) && is_string($params['draft_id']) && trim($params['draft_id']) !== '') {
            $context['draft_id'] = trim($params['draft_id']);
        }
        $read = self::legacy_action('theme.file.read', $context);
        if (is_wp_error($read)) {
            return $read;
        }
        if (!isset($read['content']) || !is_string($read['content'])) {
            return new WP_Error('wpab_theme_patch_read_invalid', 'Theme read response did not contain text content.', ['status' => 500]);
        }

        $before = $read['content'];
        $before_bytes = strlen($before);
        if ($before_bytes > self::MAX_FILE_BYTES) {
            return new WP_Error('wpab_theme_patch_file_too_large', 'Theme file exceeds the atomic patch size limit.', [
                'status' => 413,
                'max_bytes' => self::MAX_FILE_BYTES,
                'actual_bytes' => $before_bytes,
            ]);
        }
        $before_sha = hash('sha256', $before);
        $expected_before = self::optional_sha($params, 'expected_sha256');
        if (is_wp_error($expected_before)) {
            return $expected_before;
        }
        if ($expected_before !== '' && !hash_equals($expected_before, $before_sha)) {
            return new WP_Error('wpab_theme_patch_sha_conflict', 'Theme file changed since it was inspected.', [
                'status' => 409,
                'expected_sha256' => $expected_before,
                'current_sha256' => $before_sha,
            ]);
        }

        $after = $before;
        $results = [];
        $total_replacements = 0;
        foreach ($patches as $index => $patch) {
            $count = 0;
            if ($patch['replace_all']) {
                $after = str_replace($patch['find'], $patch['replace'], $after, $count);
            } else {
                $position = strpos($after, $patch['find']);
                if ($position !== false) {
                    $after = substr($after, 0, $position)
                        . $patch['replace']
                        . substr($after, $position + strlen($patch['find']));
                    $count = 1;
                }
            }
            if ($count !== $patch['expected_replacements']) {
                return new WP_Error('wpab_theme_patch_match_conflict', 'Replacement count did not match expectation; no file write was attempted.', [
                    'status' => 409,
                    'patch_index' => $index,
                    'label' => $patch['label'],
                    'expected_replacements' => $patch['expected_replacements'],
                    'actual_replacements' => $count,
                    'current_sha256' => $before_sha,
                    'side_effects' => false,
                ]);
            }
            $total_replacements += $count;
            $results[] = [
                'index' => $index,
                'label' => $patch['label'],
                'replacements' => $count,
                'replace_all' => $patch['replace_all'],
            ];
        }

        $after_sha = hash('sha256', $after);
        $expected_after = self::optional_sha($params, 'expected_after_sha256');
        if (is_wp_error($expected_after)) {
            return $expected_after;
        }
        if ($expected_after !== '' && !hash_equals($expected_after, $after_sha)) {
            return new WP_Error('wpab_theme_patch_after_sha_conflict', 'Computed patched content does not match expected_after_sha256; no file write was attempted.', [
                'status' => 409,
                'expected_after_sha256' => $expected_after,
                'actual_after_sha256' => $after_sha,
                'side_effects' => false,
            ]);
        }

        $plan_hash = self::plan_hash($path, $context['draft_id'] ?? null, $before_sha, $after_sha, $patches);
        $expected_plan = self::optional_sha($params, 'expected_plan_hash');
        if (is_wp_error($expected_plan)) {
            return $expected_plan;
        }
        if ($expected_plan !== '' && !hash_equals($expected_plan, $plan_hash)) {
            return new WP_Error('wpab_theme_patch_plan_changed', 'Patch plan changed after preview; no file write was attempted.', [
                'status' => 409,
                'expected_plan_hash' => $expected_plan,
                'current_plan_hash' => $plan_hash,
                'side_effects' => false,
            ]);
        }

        $diff = self::bounded_diff($before, $after);
        $dry_run = !empty($params['dry_run']);
        $summary = [
            'ok' => true,
            'path' => $path,
            'draft_id' => $context['draft_id'] ?? null,
            'atomic' => true,
            'patch_count' => count($patches),
            'replacements' => $total_replacements,
            'patches' => $results,
            'before_sha256' => $before_sha,
            'after_sha256' => $after_sha,
            'before_bytes' => $before_bytes,
            'after_bytes' => strlen($after),
            'plan_hash' => $plan_hash,
            'diff' => $diff['text'],
            'diff_truncated' => $diff['truncated'],
            'dry_run' => $dry_run,
            'side_effects' => false,
        ];

        if ($dry_run) {
            return rest_ensure_response($summary);
        }

        // Multi-patch writes must follow a preview. This prevents a caller from
        // applying several independent edits to an unverified large asset.
        if (array_key_exists('patches', $params)
            && ($expected_before === '' || $expected_plan === '')) {
            return new WP_Error('wpab_theme_patch_preview_required', 'Atomic multi-patch apply requires expected_sha256 and expected_plan_hash from a dry-run preview.', [
                'status' => 400,
                'side_effects' => false,
            ]);
        }

        $write = $context;
        $write['content'] = $after;
        if (!empty($params['confirm_active'])) {
            $write['confirm_active'] = true;
        }
        $written = self::legacy_action('theme.file.write', $write);
        if (is_wp_error($written)) {
            return $written;
        }
        if (($written['after_sha256'] ?? '') !== $after_sha) {
            return new WP_Error('wpab_theme_patch_write_verify_failed', 'Theme write returned an unexpected SHA-256.', [
                'status' => 500,
                'expected_after_sha256' => $after_sha,
                'write_after_sha256' => $written['after_sha256'] ?? null,
            ]);
        }

        $summary['write'] = $written;
        $summary['side_effects'] = true;
        $summary['applied'] = true;
        return rest_ensure_response($summary);
    }

    private static function normalize_patches(array $params)
    {
        if (array_key_exists('patches', $params)) {
            if (!is_array($params['patches']) || !$params['patches']) {
                return new WP_Error('wpab_theme_patch_patches', 'patches must be a non-empty array.', ['status' => 400]);
            }
            if (count($params['patches']) > self::MAX_PATCHES) {
                return new WP_Error('wpab_theme_patch_too_many', 'Too many atomic patch items.', [
                    'status' => 413,
                    'max_patches' => self::MAX_PATCHES,
                ]);
            }
            if (array_key_exists('find', $params) || array_key_exists('replace', $params)) {
                return new WP_Error('wpab_theme_patch_ambiguous', 'Do not combine patches with top-level find/replace.', ['status' => 400]);
            }
            $input = array_values($params['patches']);
        } else {
            $input = [[
                'find' => $params['find'] ?? null,
                'replace' => $params['replace'] ?? null,
                'replace_all' => $params['replace_all'] ?? false,
                'expected_replacements' => $params['expected_replacements'] ?? 1,
                'label' => $params['label'] ?? null,
            ]];
        }

        $normalized = [];
        foreach ($input as $index => $patch) {
            if (!is_array($patch)) {
                return new WP_Error('wpab_theme_patch_item', 'Each patch item must be an object.', ['status' => 400, 'patch_index' => $index]);
            }
            $find = $patch['find'] ?? null;
            $replace = $patch['replace'] ?? null;
            if (!is_string($find) || $find === '') {
                return new WP_Error('wpab_theme_patch_find', 'Each patch requires a non-empty find string.', ['status' => 400, 'patch_index' => $index]);
            }
            if (!is_string($replace)) {
                return new WP_Error('wpab_theme_patch_replace', 'Each patch requires a string replace value.', ['status' => 400, 'patch_index' => $index]);
            }
            $expected = array_key_exists('expected_replacements', $patch) ? $patch['expected_replacements'] : 1;
            if (!is_int($expected) && !(is_string($expected) && preg_match('/^[0-9]+$/D', $expected))) {
                return new WP_Error('wpab_theme_patch_expected', 'expected_replacements must be a non-negative integer.', ['status' => 400, 'patch_index' => $index]);
            }
            $expected = (int) $expected;
            $label = isset($patch['label']) && is_string($patch['label']) && trim($patch['label']) !== ''
                ? substr(trim($patch['label']), 0, 120)
                : null;
            $normalized[] = [
                'find' => $find,
                'replace' => $replace,
                'replace_all' => !empty($patch['replace_all']),
                'expected_replacements' => $expected,
                'label' => $label,
            ];
        }
        return $normalized;
    }

    private static function optional_sha(array $params, string $key)
    {
        if (!array_key_exists($key, $params) || $params[$key] === null || $params[$key] === '') {
            return '';
        }
        if (!is_string($params[$key])) {
            return new WP_Error('wpab_theme_patch_sha', $key . ' must be a SHA-256 hex digest.', ['status' => 400, 'field' => $key]);
        }
        $value = strtolower(trim($params[$key]));
        if (!preg_match('/^[a-f0-9]{64}$/D', $value)) {
            return new WP_Error('wpab_theme_patch_sha', $key . ' must be a SHA-256 hex digest.', ['status' => 400, 'field' => $key]);
        }
        return $value;
    }

    private static function plan_hash(string $path, ?string $draft_id, string $before_sha, string $after_sha, array $patches): string
    {
        $encoded = wp_json_encode([
            'path' => $path,
            'draft_id' => $draft_id,
            'before_sha256' => $before_sha,
            'after_sha256' => $after_sha,
            'patches' => $patches,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return hash('sha256', is_string($encoded) ? $encoded : '');
    }

    private static function bounded_diff(string $before, string $after): array
    {
        if ($before === $after) {
            return ['text' => '', 'truncated' => false];
        }
        $before_len = strlen($before);
        $after_len = strlen($after);
        $prefix = 0;
        $min = min($before_len, $after_len);
        while ($prefix < $min && $before[$prefix] === $after[$prefix]) {
            $prefix++;
        }
        $suffix = 0;
        while ($suffix < ($min - $prefix)
            && $before[$before_len - 1 - $suffix] === $after[$after_len - 1 - $suffix]) {
            $suffix++;
        }

        $before_change_end = $before_len - $suffix;
        $after_change_end = $after_len - $suffix;
        $start = max(0, $prefix - self::DIFF_CONTEXT_BYTES);
        $before_end = min($before_len, $before_change_end + self::DIFF_CONTEXT_BYTES);
        $after_end = min($after_len, $after_change_end + self::DIFF_CONTEXT_BYTES);
        $before_excerpt = self::text_excerpt($before, $start, $before_end - $start);
        $after_excerpt = self::text_excerpt($after, $start, $after_end - $start);
        $text = '@@ bytes ' . $prefix . ' old-end ' . $before_change_end . ' new-end ' . $after_change_end . " @@\n"
            . '- ' . $before_excerpt . "\n"
            . '+ ' . $after_excerpt;
        $truncated = $start > 0 || $before_end < $before_len || $after_end < $after_len;
        if (strlen($text) > self::MAX_DIFF_BYTES) {
            $text = self::text_excerpt($text, 0, self::MAX_DIFF_BYTES);
            $truncated = true;
        }
        return ['text' => $text, 'truncated' => $truncated];
    }

    private static function text_excerpt(string $text, int $offset, int $length): string
    {
        $part = substr($text, $offset, $length);
        if (!is_string($part)) {
            return '';
        }
        if (function_exists('wp_check_invalid_utf8')) {
            $part = wp_check_invalid_utf8($part, true);
        }
        return $part;
    }

    private static function legacy_action(string $action, array $params)
    {
        $request = new WP_REST_Request('POST', '/takka-bridge/v1/execute');
        $request->set_param('action', $action);
        $request->set_param('params', $params);
        $response = TakKa_WordPress_Bridge::execute($request);
        if (is_wp_error($response)) {
            return $response;
        }
        $rest = rest_ensure_response($response);
        $status = (int) $rest->get_status();
        if ($status >= 400) {
            $data = $rest->get_data();
            return new WP_Error('wpab_theme_patch_legacy_error', 'Internal theme operation failed.', [
                'status' => $status,
                'data' => $data,
            ]);
        }
        $data = $rest->get_data();
        return is_array($data) ? $data : [];
    }
}
