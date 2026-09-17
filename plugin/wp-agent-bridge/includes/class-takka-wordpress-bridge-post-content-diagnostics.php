<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bounded read-only diagnostics for post.content patch match conflicts.
 *
 * Exact-match guards remain authoritative. Whitespace-normalized and anchor
 * candidates are location hints only and can never become write targets.
 */
final class TakKa_WordPress_Bridge_Post_Content_Diagnostics
{
    private const V084_ROUTE = '/takka-v084/v1/manage';
    private const V099_ROUTE = '/takka-v099/v1/operate';
    private const HEALTH_ROUTE = '/takka-bridge/v1/health';
    private const MAX_CANDIDATES = 5;
    private const MAX_EXCERPT_BYTES = 768;
    private const MAX_ANCHORS = 4;

    public static function init(): void
    {
        add_filter('rest_request_after_callbacks', [self::class, 'enrich_conflict'], 675, 3);
        add_filter('rest_request_after_callbacks', [self::class, 'annotate_contract'], 675, 3);
    }

    public static function enrich_conflict($response, array $handler, WP_REST_Request $request)
    {
        if ($request->get_route() !== self::V084_ROUTE || !is_wp_error($response)) {
            return $response;
        }
        if ($response->get_error_code() !== 'takka_bridge_post_content_match_count') {
            return $response;
        }

        $json = $request->get_json_params();
        if (!is_array($json)) {
            return $response;
        }
        $action = is_string($json['action'] ?? null) ? trim($json['action']) : '';
        if (!in_array($action, ['post.content.patch.preview', 'post.content.patch.apply'], true)) {
            return $response;
        }
        $params = isset($json['params']) && is_array($json['params']) ? $json['params'] : [];
        $diagnostics = self::diagnostics($params);

        $data = $response->get_error_data();
        $data = is_array($data) ? $data : [];
        $data['diagnostics'] = is_wp_error($diagnostics)
            ? [
                'available' => false,
                'read_only' => true,
                'error_code' => $diagnostics->get_error_code(),
                'message' => $diagnostics->get_error_message(),
                'approximate_matches_can_write' => false,
              ]
            : $diagnostics;
        $data['side_effects'] = false;

        return new WP_Error(
            $response->get_error_code(),
            $response->get_error_message(),
            $data
        );
    }

    public static function annotate_contract($response, array $handler, WP_REST_Request $request)
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
                $data['post_content_patch_diagnostics'] = self::contract();
                $rest->set_data($data);
                return $rest;
            }
        }

        if ($route === self::HEALTH_ROUTE) {
            $features = isset($data['features']) && is_array($data['features']) ? $data['features'] : [];
            if (!in_array('post_content_patch_match_diagnostics', $features, true)) {
                $features[] = 'post_content_patch_match_diagnostics';
            }
            $data['features'] = $features;
            $data['post_content_patch_diagnostics'] = self::contract();
            $rest->set_data($data);
            return $rest;
        }

        return $response;
    }

    public static function contract(): array
    {
        return [
            'read_only' => true,
            'exact_match_count' => true,
            'whitespace_normalized_match_count' => true,
            'approximate_candidates_max' => self::MAX_CANDIDATES,
            'candidate_excerpt_bytes' => self::MAX_EXCERPT_BYTES,
            'approximate_matches_can_write' => false,
            'conflict_side_effects' => false,
        ];
    }

    private static function diagnostics(array $params)
    {
        $post_id = isset($params['post_id']) ? absint($params['post_id']) : 0;
        $find = isset($params['find']) && is_string($params['find']) ? $params['find'] : '';
        if ($post_id < 1 || $find === '') {
            return new WP_Error('wpab_post_content_diagnostic_input', 'Could not identify post_id and find text for diagnostics.');
        }
        $post = get_post($post_id);
        if (!$post || !current_user_can('edit_post', $post_id)) {
            return new WP_Error('wpab_post_content_diagnostic_post', 'Post is unavailable for conflict diagnostics.');
        }
        $content = (string) $post->post_content;
        $exact_count = substr_count($content, $find);
        $normalized_count = self::normalized_count($content, $find);
        $anchors = self::anchors($find);

        return [
            'available' => true,
            'read_only' => true,
            'post_id' => $post_id,
            'current_modified_gmt' => (string) $post->post_modified_gmt,
            'content_sha256' => hash('sha256', $content),
            'content_bytes' => strlen($content),
            'find_sha256' => hash('sha256', $find),
            'find_bytes' => strlen($find),
            'exact_match_count' => $exact_count,
            'whitespace_normalized_match_count' => $normalized_count,
            'approximate_candidates' => self::candidates($content, $anchors),
            'candidate_search' => [
                'bounded' => true,
                'max_candidates' => self::MAX_CANDIDATES,
                'max_excerpt_bytes' => self::MAX_EXCERPT_BYTES,
                'anchor_count' => count($anchors),
            ],
            'approximate_matches_can_write' => false,
            'side_effects' => false,
        ];
    }

    private static function normalized_count(string $content, string $find): int
    {
        $parts = preg_split('/\s+/u', trim($find));
        if (!is_array($parts) || !$parts) {
            return 0;
        }
        $quoted = [];
        foreach ($parts as $part) {
            if ($part !== '') {
                $quoted[] = preg_quote($part, '~');
            }
        }
        if (!$quoted) {
            return 0;
        }
        $pattern = '~' . implode('\\s+', $quoted) . '~u';
        $count = preg_match_all($pattern, $content, $matches);
        return is_int($count) && $count > 0 ? $count : 0;
    }

    private static function anchors(string $find): array
    {
        $tokens = preg_split('/\s+/u', trim($find));
        $candidates = [];
        if (is_array($tokens)) {
            foreach ($tokens as $token) {
                $token = trim((string) $token);
                if (strlen($token) >= 8) {
                    $candidates[$token] = strlen($token);
                }
            }
        }
        arsort($candidates, SORT_NUMERIC);
        $anchors = [];
        foreach (array_keys($candidates) as $token) {
            // Long minified tokens are more useful as bounded prefix/suffix
            // anchors than as one huge exact substring.
            if (strlen($token) > 96) {
                foreach ([substr($token, 0, 48), substr($token, -48)] as $piece) {
                    $piece = (string) wp_check_invalid_utf8($piece, true);
                    if (strlen($piece) >= 8) {
                        $anchors[$piece] = true;
                    }
                }
            } else {
                $anchors[$token] = true;
            }
            if (count($anchors) >= self::MAX_ANCHORS) {
                break;
            }
        }
        if (!$anchors && strlen($find) >= 8) {
            $piece = (string) wp_check_invalid_utf8(substr($find, 0, min(48, strlen($find))), true);
            if (strlen($piece) >= 8) {
                $anchors[$piece] = true;
            }
        }
        return array_keys($anchors);
    }

    private static function candidates(string $content, array $anchors): array
    {
        $rows = [];
        $seen = [];
        foreach ($anchors as $anchor) {
            $offset = 0;
            while (count($rows) < self::MAX_CANDIDATES) {
                $pos = strpos($content, $anchor, $offset);
                if ($pos === false) {
                    break;
                }
                $key = (string) $pos;
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $rows[] = self::excerpt($content, (int) $pos, $anchor);
                }
                $offset = (int) $pos + max(1, strlen($anchor));
            }
            if (count($rows) >= self::MAX_CANDIDATES) {
                break;
            }
        }
        usort($rows, static function (array $a, array $b): int {
            return ((int) $a['estimated_match_offset']) <=> ((int) $b['estimated_match_offset']);
        });
        return $rows;
    }

    private static function excerpt(string $content, int $pos, string $anchor): array
    {
        $half = (int) floor(self::MAX_EXCERPT_BYTES / 2);
        $start = max(0, $pos - $half);
        $length = min(self::MAX_EXCERPT_BYTES, strlen($content) - $start);
        $raw = substr($content, $start, $length);
        $text = (string) wp_check_invalid_utf8($raw, true);
        return [
            'estimated_match_offset' => $pos,
            'line' => substr_count(substr($content, 0, $pos), "\n") + 1,
            'excerpt_start_byte' => $start,
            'excerpt' => $text,
            'excerpt_truncated' => $start > 0 || ($start + $length) < strlen($content),
            'matched_anchor_sha256' => hash('sha256', $anchor),
            'anchor_bytes' => strlen($anchor),
        ];
    }
}
