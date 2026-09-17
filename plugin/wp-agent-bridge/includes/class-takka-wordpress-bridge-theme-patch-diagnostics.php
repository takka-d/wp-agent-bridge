<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-only diagnostics and match fingerprints for atomic theme.file.patch.
 *
 * The underlying Theme_Patch engine remains strict and all-or-nothing. This
 * layer only adds bounded evidence when an exact match fails and binds the
 * visible preview plan hash to the exact locations that were matched. Fuzzy or
 * normalized candidates are never promoted into a write target automatically.
 */
final class TakKa_WordPress_Bridge_Theme_Patch_Diagnostics
{
    private const V04_ROUTE = '/takka-bridge/v1/manage';
    private const V099_ROUTE = '/takka-v099/v1/operate';
    private const HEALTH_ROUTE = '/takka-bridge/v1/health';
    private const PLAN_VERSION = 2;
    private const CONTEXT_BYTES = 96;
    private const MAX_FINGERPRINTS_PER_PATCH = 8;
    private const MAX_APPROXIMATE_CANDIDATES = 5;
    private const MAX_THEME_CANDIDATES = 5;
    private const MAX_EXCERPT_BYTES = 768;

    public static function init(): void
    {
        // Theme_Patch itself runs at priority 15. Intercept one step earlier so
        // the core engine can remain unchanged while this layer translates the
        // externally visible plan hash and enriches safe failures.
        add_filter('rest_pre_dispatch', [self::class, 'pre_dispatch'], 14, 3);
        add_filter('rest_request_after_callbacks', [self::class, 'annotate_response'], 635, 3);
    }

    public static function pre_dispatch($result, WP_REST_Server $server, WP_REST_Request $request)
    {
        if ($result !== null
            || $request->get_route() !== self::V04_ROUTE
            || strtoupper($request->get_method()) !== 'POST') {
            return $result;
        }

        $params = self::advanced_patch_params($request);
        if ($params === null) {
            return $result;
        }

        $authorized = TakKa_WordPress_Bridge_V04::authorize_request($request);
        if (is_wp_error($authorized)) {
            return $authorized;
        }

        $dry_run = !empty($params['dry_run']);
        if ($dry_run) {
            $preview = TakKa_WordPress_Bridge_Theme_Patch::execute($params);
            if (is_wp_error($preview)) {
                return self::enrich_error($preview, $params);
            }
            return self::decorate_preview($preview, $params);
        }

        $expected_visible_plan = isset($params['expected_plan_hash']) && is_string($params['expected_plan_hash'])
            ? strtolower(trim($params['expected_plan_hash']))
            : '';

        if ($expected_visible_plan !== '') {
            // Re-run the exact patch as a no-write preflight. This gives the
            // engine's native plan hash for the current file while retaining the
            // same expected before/after SHA guards supplied by the caller.
            $preflight_params = $params;
            $preflight_params['dry_run'] = true;
            unset($preflight_params['expected_plan_hash']);
            $preflight = TakKa_WordPress_Bridge_Theme_Patch::execute($preflight_params);
            if (is_wp_error($preflight)) {
                return self::enrich_error($preflight, $preflight_params);
            }
            $preflight_rest = rest_ensure_response($preflight);
            $preflight_data = $preflight_rest->get_data();
            if (!is_array($preflight_data)) {
                return new WP_Error('wpab_theme_patch_diagnostic_preflight', 'Theme patch preflight returned an invalid response.', [
                    'status' => 500,
                    'side_effects' => false,
                ]);
            }

            $fingerprints = self::fingerprint_plan($params, (string) ($preflight_data['before_sha256'] ?? ''));
            if (is_wp_error($fingerprints)) {
                return $fingerprints;
            }
            $engine_plan = strtolower((string) ($preflight_data['plan_hash'] ?? ''));
            $visible_plan = self::visible_plan_hash($engine_plan, (string) $fingerprints['digest']);

            $legacy_plan = hash_equals($engine_plan, $expected_visible_plan);
            if (!$legacy_plan && !hash_equals($visible_plan, $expected_visible_plan)) {
                return new WP_Error('wpab_theme_patch_plan_changed', 'Patch plan or exact match fingerprint changed after preview; no file write was attempted.', [
                    'status' => 409,
                    'expected_plan_hash' => $expected_visible_plan,
                    'current_plan_hash' => $visible_plan,
                    'plan_hash_version' => self::PLAN_VERSION,
                    'current_match_fingerprint_digest' => $fingerprints['digest'],
                    'side_effects' => false,
                ]);
            }

            // The core engine validates its own plan hash. Translate the v2
            // external hash back to that internal hash only after fingerprints
            // have been recomputed from the current file.
            $apply_params = $params;
            $apply_params['expected_plan_hash'] = $engine_plan;
            $applied = TakKa_WordPress_Bridge_Theme_Patch::execute($apply_params);
            if (is_wp_error($applied)) {
                return self::enrich_error($applied, $params);
            }
            return self::decorate_apply($applied, $visible_plan, $fingerprints, !$legacy_plan, $legacy_plan);
        }

        $response = TakKa_WordPress_Bridge_Theme_Patch::execute($params);
        if (is_wp_error($response)) {
            return self::enrich_error($response, $params);
        }
        return $response;
    }

    public static function annotate_response($response, array $handler, WP_REST_Request $request)
    {
        if (is_wp_error($response)) {
            return $response;
        }
        $route = $request->get_route();
        $rest = rest_ensure_response($response);
        $data = $rest->get_data();
        if (!is_array($data)) {
            return $response;
        }

        if ($route === self::V099_ROUTE) {
            $json = $request->get_json_params();
            if (is_array($json) && ($json['operation'] ?? '') === 'catalog') {
                $contract = isset($data['theme_file_patch']) && is_array($data['theme_file_patch'])
                    ? $data['theme_file_patch']
                    : [];
                $contract['plan_hash_version'] = self::PLAN_VERSION;
                $contract['preview_returns'] = [
                    'before_sha256',
                    'after_sha256',
                    'plan_hash',
                    'match_fingerprint_digest',
                    'match_fingerprints',
                    'bounded_diff',
                ];
                $contract['apply_match_verification'] = [
                    'automatic_with_v2_plan_hash' => true,
                    'legacy_v1_plan_hash_accepted' => true,
                    'writes_only_after_exact_revalidation' => true,
                ];
                $contract['match_conflict_diagnostics'] = [
                    'read_only' => true,
                    'exact_match_count' => true,
                    'whitespace_normalized_match_count' => true,
                    'approximate_candidates_max' => self::MAX_APPROXIMATE_CANDIDATES,
                    'same_theme_candidates_max' => self::MAX_THEME_CANDIDATES,
                    'candidate_excerpt_bytes' => self::MAX_EXCERPT_BYTES,
                    'never_auto_apply_approximate_match' => true,
                ];
                $data['theme_file_patch'] = $contract;
                $rest->set_data($data);
                return $rest;
            }
        }

        if ($route === self::HEALTH_ROUTE) {
            $features = isset($data['features']) && is_array($data['features']) ? $data['features'] : [];
            foreach (['theme_patch_match_diagnostics', 'theme_patch_match_fingerprints'] as $feature) {
                if (!in_array($feature, $features, true)) {
                    $features[] = $feature;
                }
            }
            $data['features'] = $features;
            $data['theme_patch_diagnostics'] = [
                'plan_hash_version' => self::PLAN_VERSION,
                'read_only_conflict_diagnostics' => true,
                'approximate_matches_can_write' => false,
                'same_theme_search_bounded' => true,
                'legacy_plan_hash_accepted' => true,
            ];
            $rest->set_data($data);
            return $rest;
        }

        return $response;
    }

    private static function advanced_patch_params(WP_REST_Request $request): ?array
    {
        $json = json_decode((string) $request->get_body(), true);
        if (!is_array($json) || !isset($json['payload_b64']) || !is_string($json['payload_b64'])) {
            return null;
        }
        $decoded = base64_decode(trim($json['payload_b64']), true);
        if (!is_string($decoded)) {
            return null;
        }
        $payload = json_decode($decoded, true);
        if (!is_array($payload) || ($payload['action'] ?? '') !== 'theme.file.patch') {
            return null;
        }
        $params = isset($payload['params']) && is_array($payload['params']) ? $payload['params'] : [];
        if (!array_key_exists('patches', $params)
            && !array_key_exists('expected_plan_hash', $params)
            && !array_key_exists('expected_after_sha256', $params)) {
            return null;
        }
        return $params;
    }

    private static function decorate_preview($response, array $params)
    {
        $rest = rest_ensure_response($response);
        $data = $rest->get_data();
        if (!is_array($data)) {
            return $response;
        }
        $fingerprints = self::fingerprint_plan($params, (string) ($data['before_sha256'] ?? ''));
        if (is_wp_error($fingerprints)) {
            return $fingerprints;
        }
        $engine_plan = strtolower((string) ($data['plan_hash'] ?? ''));
        $data['plan_hash'] = self::visible_plan_hash($engine_plan, (string) $fingerprints['digest']);
        $data['plan_hash_version'] = self::PLAN_VERSION;
        $data['match_fingerprint_digest'] = $fingerprints['digest'];
        $data['match_fingerprints'] = $fingerprints['patches'];
        $data['match_fingerprint_verified'] = true;
        $data['approximate_matches_used_for_write'] = false;
        $rest->set_data($data);
        return $rest;
    }

    private static function decorate_apply($response, string $visible_plan, array $fingerprints, bool $verified_v2, bool $legacy_plan)
    {
        $rest = rest_ensure_response($response);
        $data = $rest->get_data();
        if (!is_array($data)) {
            return $response;
        }
        $data['plan_hash'] = $visible_plan;
        $data['plan_hash_version'] = self::PLAN_VERSION;
        $data['match_fingerprint_digest'] = $fingerprints['digest'];
        $data['match_fingerprints'] = $fingerprints['patches'];
        $data['match_fingerprint_verified'] = $verified_v2;
        $data['legacy_plan_hash_accepted'] = $legacy_plan;
        $data['approximate_matches_used_for_write'] = false;
        $rest->set_data($data);
        return $rest;
    }

    private static function enrich_error(WP_Error $error, array $params): WP_Error
    {
        if ($error->get_error_code() !== 'wpab_theme_patch_match_conflict') {
            return $error;
        }
        $data = $error->get_error_data();
        $data = is_array($data) ? $data : [];
        $diagnostics = self::conflict_diagnostics($params, $data);
        $data['diagnostics'] = is_wp_error($diagnostics)
            ? [
                'available' => false,
                'error_code' => $diagnostics->get_error_code(),
                'message' => $diagnostics->get_error_message(),
                'read_only' => true,
              ]
            : $diagnostics;
        $data['side_effects'] = false;
        return new WP_Error($error->get_error_code(), $error->get_error_message(), $data);
    }

    private static function conflict_diagnostics(array $params, array $error_data)
    {
        $read = self::read_theme_file($params);
        if (is_wp_error($read)) {
            return $read;
        }
        $patches = self::normalize_patches($params);
        if (is_wp_error($patches)) {
            return $patches;
        }
        $index = isset($error_data['patch_index']) ? (int) $error_data['patch_index'] : 0;
        if (!isset($patches[$index])) {
            return new WP_Error('wpab_theme_patch_diagnostic_index', 'Could not identify the failed patch item.');
        }

        $working = $read['content'];
        for ($i = 0; $i < $index; $i++) {
            $working = self::apply_patch_for_diagnostics($working, $patches[$i]);
            if (is_wp_error($working)) {
                return $working;
            }
        }

        $patch = $patches[$index];
        $find = $patch['find'];
        $exact_count = substr_count($working, $find);
        $normalized_count = self::whitespace_normalized_count($working, $find);
        $anchors = self::anchors($find);
        $approximate = self::approximate_candidates($working, $find, $anchors);
        $scope = isset($params['draft_id']) && is_string($params['draft_id']) && trim($params['draft_id']) !== ''
            ? 'draft'
            : 'active';
        $theme_candidates = self::same_theme_candidates(
            $anchors,
            $scope,
            isset($params['draft_id']) && is_string($params['draft_id']) ? trim($params['draft_id']) : '',
            isset($params['path']) && is_string($params['path']) ? trim($params['path']) : ''
        );

        return [
            'available' => true,
            'read_only' => true,
            'file_sha256' => $read['sha256'],
            'find_sha256' => hash('sha256', $find),
            'find_bytes' => strlen($find),
            'exact_match_count' => $exact_count,
            'whitespace_normalized_match_count' => $normalized_count,
            'approximate_candidates' => $approximate,
            'same_theme_candidates' => $theme_candidates,
            'candidate_search' => [
                'bounded' => true,
                'max_approximate_candidates' => self::MAX_APPROXIMATE_CANDIDATES,
                'max_same_theme_candidates' => self::MAX_THEME_CANDIDATES,
                'max_excerpt_bytes' => self::MAX_EXCERPT_BYTES,
                'anchor_count' => count($anchors),
            ],
            'approximate_matches_can_write' => false,
            'side_effects' => false,
        ];
    }

    private static function fingerprint_plan(array $params, string $expected_before_sha)
    {
        $read = self::read_theme_file($params);
        if (is_wp_error($read)) {
            return $read;
        }
        if ($expected_before_sha !== '' && !hash_equals(strtolower($expected_before_sha), strtolower($read['sha256']))) {
            return new WP_Error('wpab_theme_patch_fingerprint_stale', 'Theme file changed while match fingerprints were being computed.', [
                'status' => 409,
                'expected_sha256' => strtolower($expected_before_sha),
                'current_sha256' => $read['sha256'],
                'side_effects' => false,
            ]);
        }
        $patches = self::normalize_patches($params);
        if (is_wp_error($patches)) {
            return $patches;
        }

        $working = $read['content'];
        $rows = [];
        foreach ($patches as $index => $patch) {
            $all_count = substr_count($working, $patch['find']);
            $effective_count = $patch['replace_all'] ? $all_count : ($all_count > 0 ? 1 : 0);
            if ($effective_count !== $patch['expected_replacements']) {
                return new WP_Error('wpab_theme_patch_fingerprint_match_changed', 'Theme match count changed while fingerprints were being computed.', [
                    'status' => 409,
                    'patch_index' => $index,
                    'expected_replacements' => $patch['expected_replacements'],
                    'actual_replacements' => $effective_count,
                    'side_effects' => false,
                ]);
            }
            $offsets = self::first_match_offsets($working, $patch['find'], self::MAX_FINGERPRINTS_PER_PATCH);
            if (!$patch['replace_all'] && $offsets) {
                $offsets = [$offsets[0]];
            }
            $fingerprints = [];
            foreach ($offsets as $offset) {
                $fingerprints[] = self::match_fingerprint($working, $patch['find'], $offset);
            }
            $row = [
                'index' => $index,
                'label' => $patch['label'],
                'match_count' => $effective_count,
                'exact_occurrences_before_patch' => $all_count,
                'fingerprints' => $fingerprints,
                'fingerprints_truncated' => $effective_count > count($fingerprints),
            ];
            $row['fingerprint_digest'] = hash('sha256', (string) wp_json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $rows[] = $row;
            $working = self::apply_patch_for_diagnostics($working, $patch);
            if (is_wp_error($working)) {
                return $working;
            }
        }

        $digest_source = [
            'version' => self::PLAN_VERSION,
            'path' => isset($params['path']) ? (string) $params['path'] : '',
            'draft_id' => isset($params['draft_id']) ? (string) $params['draft_id'] : null,
            'before_sha256' => $read['sha256'],
            'patches' => array_map(static function (array $row): array {
                return [
                    'index' => $row['index'],
                    'match_count' => $row['match_count'],
                    'exact_occurrences_before_patch' => $row['exact_occurrences_before_patch'],
                    'fingerprint_digest' => $row['fingerprint_digest'],
                ];
            }, $rows),
        ];
        return [
            'digest' => hash('sha256', (string) wp_json_encode($digest_source, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'patches' => $rows,
        ];
    }

    private static function visible_plan_hash(string $engine_plan, string $fingerprint_digest): string
    {
        return hash('sha256', 'wpab-theme-patch-plan-v' . self::PLAN_VERSION . "\n" . $engine_plan . "\n" . $fingerprint_digest);
    }

    private static function read_theme_file(array $params)
    {
        $path = isset($params['path']) && is_string($params['path']) ? trim($params['path']) : '';
        if ($path === '') {
            return new WP_Error('wpab_theme_patch_diagnostic_path', 'Theme patch diagnostics require a path.', ['status' => 400]);
        }
        $read_params = ['path' => $path];
        if (isset($params['draft_id']) && is_string($params['draft_id']) && trim($params['draft_id']) !== '') {
            $read_params['draft_id'] = trim($params['draft_id']);
        }
        $request = new WP_REST_Request('POST', '/takka-bridge/v1/execute');
        $request->set_param('action', 'theme.file.read');
        $request->set_param('params', $read_params);
        $response = TakKa_WordPress_Bridge::execute($request);
        if (is_wp_error($response)) {
            return $response;
        }
        $data = rest_ensure_response($response)->get_data();
        if (!is_array($data) || !isset($data['content']) || !is_string($data['content'])) {
            return new WP_Error('wpab_theme_patch_diagnostic_read', 'Theme file could not be read for diagnostics.', ['status' => 500]);
        }
        return [
            'content' => $data['content'],
            'sha256' => hash('sha256', $data['content']),
        ];
    }

    private static function normalize_patches(array $params)
    {
        if (array_key_exists('patches', $params)) {
            if (!is_array($params['patches']) || !$params['patches']) {
                return new WP_Error('wpab_theme_patch_diagnostic_patches', 'patches must be a non-empty array.', ['status' => 400]);
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
        $out = [];
        foreach ($input as $index => $patch) {
            if (!is_array($patch) || !is_string($patch['find'] ?? null) || ($patch['find'] ?? '') === '' || !is_string($patch['replace'] ?? null)) {
                return new WP_Error('wpab_theme_patch_diagnostic_item', 'Invalid patch item for diagnostics.', ['status' => 400, 'patch_index' => $index]);
            }
            $expected = array_key_exists('expected_replacements', $patch) ? (int) $patch['expected_replacements'] : 1;
            $out[] = [
                'find' => $patch['find'],
                'replace' => $patch['replace'],
                'replace_all' => !empty($patch['replace_all']),
                'expected_replacements' => $expected,
                'label' => isset($patch['label']) && is_string($patch['label']) ? substr(trim($patch['label']), 0, 120) : null,
            ];
        }
        return $out;
    }

    private static function apply_patch_for_diagnostics(string $content, array $patch)
    {
        if ($patch['replace_all']) {
            $count = 0;
            $next = str_replace($patch['find'], $patch['replace'], $content, $count);
        } else {
            $offset = strpos($content, $patch['find']);
            $count = $offset === false ? 0 : 1;
            $next = $offset === false
                ? $content
                : substr($content, 0, $offset) . $patch['replace'] . substr($content, $offset + strlen($patch['find']));
        }
        if ($count !== $patch['expected_replacements']) {
            return new WP_Error('wpab_theme_patch_diagnostic_sequence', 'Earlier patch state no longer matches the failed patch sequence.', [
                'status' => 409,
                'expected_replacements' => $patch['expected_replacements'],
                'actual_replacements' => $count,
                'side_effects' => false,
            ]);
        }
        return $next;
    }

    private static function first_match_offsets(string $content, string $needle, int $limit): array
    {
        $out = [];
        $offset = 0;
        $step = max(1, strlen($needle));
        while (count($out) < $limit) {
            $found = strpos($content, $needle, $offset);
            if ($found === false) {
                break;
            }
            $out[] = $found;
            $offset = $found + $step;
        }
        return $out;
    }

    private static function match_fingerprint(string $content, string $needle, int $offset): array
    {
        $before_start = max(0, $offset - self::CONTEXT_BYTES);
        $before = substr($content, $before_start, $offset - $before_start);
        $after_start = $offset + strlen($needle);
        $after = substr($content, $after_start, self::CONTEXT_BYTES);
        return [
            'match_offset' => $offset,
            'matched_text_sha256' => hash('sha256', $needle),
            'before_context_sha256' => hash('sha256', is_string($before) ? $before : ''),
            'after_context_sha256' => hash('sha256', is_string($after) ? $after : ''),
            'context_bytes' => self::CONTEXT_BYTES,
        ];
    }

    private static function whitespace_normalized_count(string $content, string $find): int
    {
        $normalized_find = preg_replace('/\s+/u', ' ', trim($find));
        $normalized_content = preg_replace('/\s+/u', ' ', $content);
        if (!is_string($normalized_find) || $normalized_find === '' || !is_string($normalized_content)) {
            return 0;
        }
        return substr_count($normalized_content, $normalized_find);
    }

    private static function anchors(string $find): array
    {
        $tokens = preg_split('/\s+/u', trim($find));
        $tokens = is_array($tokens) ? $tokens : [];
        usort($tokens, static function ($a, $b): int {
            return strlen((string) $b) <=> strlen((string) $a);
        });
        $out = [];
        foreach ($tokens as $token) {
            if (!is_string($token) || strlen($token) < 8) {
                continue;
            }
            $pieces = [];
            if (strlen($token) <= 96) {
                $pieces[] = $token;
            } else {
                $pieces[] = substr($token, 0, 64);
                $pieces[] = substr($token, max(0, intdiv(strlen($token), 2) - 32), 64);
                $pieces[] = substr($token, -64);
            }
            foreach ($pieces as $piece) {
                if ($piece !== '' && !in_array($piece, $out, true)) {
                    $out[] = $piece;
                    if (count($out) >= 3) {
                        return $out;
                    }
                }
            }
        }
        if (!$out) {
            $trimmed = trim($find);
            if ($trimmed !== '') {
                $out[] = substr($trimmed, 0, min(64, strlen($trimmed)));
            }
        }
        return $out;
    }

    private static function approximate_candidates(string $content, string $find, array $anchors): array
    {
        $candidates = [];
        foreach ($anchors as $anchor) {
            $anchor_in_find = strpos($find, $anchor);
            $anchor_in_find = $anchor_in_find === false ? 0 : $anchor_in_find;
            $offset = 0;
            for ($n = 0; $n < 3; $n++) {
                $found = strpos($content, $anchor, $offset);
                if ($found === false) {
                    break;
                }
                $estimated = max(0, $found - $anchor_in_find);
                $key = (string) intdiv($estimated, 8);
                if (!isset($candidates[$key])) {
                    $candidates[$key] = self::candidate_excerpt($content, $estimated, $anchor, $anchor_in_find);
                    if (count($candidates) >= self::MAX_APPROXIMATE_CANDIDATES) {
                        break 2;
                    }
                }
                $offset = $found + max(1, strlen($anchor));
            }
        }
        return array_values($candidates);
    }

    private static function candidate_excerpt(string $content, int $estimated_start, string $anchor, int $anchor_in_find): array
    {
        $start = max(0, $estimated_start - 160);
        $length = min(self::MAX_EXCERPT_BYTES, strlen($content) - $start);
        $text = substr($content, $start, $length);
        if (!is_string($text)) {
            $text = '';
        }
        if (function_exists('wp_check_invalid_utf8')) {
            $text = wp_check_invalid_utf8($text, true);
        }
        return [
            'estimated_match_offset' => $estimated_start,
            'excerpt_start_byte' => $start,
            'excerpt' => $text,
            'excerpt_truncated' => $start > 0 || ($start + $length) < strlen($content),
            'matched_anchor_sha256' => hash('sha256', $anchor),
            'anchor_offset_in_find' => $anchor_in_find,
        ];
    }

    private static function same_theme_candidates(array $anchors, string $scope, string $draft_id, string $current_path): array
    {
        if (!$anchors) {
            return [];
        }
        $query = $anchors[0];
        $params = [
            'scope' => $scope,
            'query' => $query,
            'case_sensitive' => true,
            'max_results' => self::MAX_THEME_CANDIDATES,
            'context_lines' => 0,
            'max_excerpt_bytes' => self::MAX_EXCERPT_BYTES,
        ];
        if ($scope === 'draft') {
            $params['draft_id'] = $draft_id;
        }
        $request = new WP_REST_Request('POST', '/takka-v096/v1/theme-files');
        $request->set_header('content-type', 'application/json');
        $request->set_body((string) wp_json_encode([
            'action' => 'theme.files.search',
            'params' => $params,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $response = TakKa_WordPress_Bridge_V096_Theme_Files::dispatch($request);
        if (is_wp_error($response)) {
            return [[
                'search_error' => $response->get_error_code(),
                'message' => $response->get_error_message(),
            ]];
        }
        $data = rest_ensure_response($response)->get_data();
        if (!is_array($data) || !isset($data['results']) || !is_array($data['results'])) {
            return [];
        }
        $out = [];
        foreach ($data['results'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'path' => $row['path'] ?? null,
                'same_file' => isset($row['path']) && (string) $row['path'] === $current_path,
                'line' => $row['line'] ?? null,
                'excerpt' => $row['text'] ?? '',
                'excerpt_start_byte' => $row['text_start_byte'] ?? null,
                'excerpt_truncated' => !empty($row['text_truncated']),
                'anchor_sha256' => hash('sha256', $query),
            ];
        }
        return $out;
    }
}
