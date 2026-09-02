<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TakKa_WordPress_Bridge_V084_Post_Content
{
    private const MAX_CONTENT_BYTES = 4194304;
    private const MAX_QUERY_BYTES = 4096;
    private const MAX_REPLACEMENT_BYTES = 262144;
    private const MAX_SEARCH_RESULTS = 20;
    private const MAX_CONTEXT_CHARS = 1200;

    public static function inspect(array $params)
    {
        $context = self::context($params);
        if (is_wp_error($context)) return $context;
        return rest_ensure_response(self::public_state($context['post']));
    }

    public static function search(array $params)
    {
        $context = self::context($params);
        if (is_wp_error($context)) return $context;
        $query = isset($params['query']) && is_string($params['query']) ? $params['query'] : '';
        if ($query === '' || strlen($query) > self::MAX_QUERY_BYTES) {
            return new WP_Error('takka_bridge_post_content_query', 'query must be a non-empty string within the search limit.', ['status' => 400]);
        }

        $content = (string) $context['post']->post_content;
        if (strlen($content) > self::MAX_CONTENT_BYTES) {
            return new WP_Error('takka_bridge_post_content_size', 'Post content is too large for Bridge content tooling.', ['status' => 413]);
        }

        $case_sensitive = !empty($params['case_sensitive']);
        $limit = isset($params['limit']) ? max(1, min(self::MAX_SEARCH_RESULTS, (int) $params['limit'])) : 10;
        $context_chars = isset($params['context_chars']) ? max(0, min(self::MAX_CONTEXT_CHARS, (int) $params['context_chars'])) : 320;
        $matches = [];
        $offset = 0;
        $query_bytes = strlen($query);

        while (count($matches) < $limit) {
            $pos = $case_sensitive ? strpos($content, $query, $offset) : stripos($content, $query, $offset);
            if ($pos === false) break;
            $matches[] = [
                'byte_offset' => (int) $pos,
                'before' => self::context_before($content, (int) $pos, $context_chars),
                'match' => substr($content, (int) $pos, $query_bytes),
                'after' => self::context_after($content, (int) $pos + $query_bytes, $context_chars),
            ];
            $offset = (int) $pos + max(1, $query_bytes);
        }

        $total = self::count_occurrences($content, $query, $case_sensitive);
        return rest_ensure_response([
            'post_id' => (int) $context['post']->ID,
            'status' => (string) $context['post']->post_status,
            'modified_gmt' => (string) $context['post']->post_modified_gmt,
            'content_sha256' => hash('sha256', $content),
            'content_bytes' => strlen($content),
            'query_sha256' => hash('sha256', $query),
            'total_matches' => $total,
            'returned' => count($matches),
            'truncated' => $total > count($matches),
            'matches' => $matches,
        ]);
    }

    public static function preview(array $params)
    {
        $plan = self::plan($params);
        if (is_wp_error($plan)) return $plan;
        unset($plan['_after_content']);
        return rest_ensure_response($plan);
    }

    public static function apply(array $params)
    {
        if (empty($params['confirm'])) {
            return new WP_Error('takka_bridge_confirmation_required', 'Post content changes require confirm=true.', ['status' => 400]);
        }
        $plan = self::plan($params);
        if (is_wp_error($plan)) return $plan;

        $expected_before = isset($params['expected_before_sha256']) && is_string($params['expected_before_sha256']) ? trim($params['expected_before_sha256']) : '';
        if ($expected_before === '' || !hash_equals((string) $plan['before_sha256'], $expected_before)) {
            return new WP_Error('takka_bridge_post_content_changed', 'Post content changed after preview or expected_before_sha256 is missing.', [
                'status' => 409,
                'current_before_sha256' => $plan['before_sha256'],
                'current_modified_gmt' => $plan['modified_gmt'],
            ]);
        }
        $expected_plan = isset($params['expected_plan_hash']) && is_string($params['expected_plan_hash']) ? trim($params['expected_plan_hash']) : '';
        if ($expected_plan === '' || !hash_equals((string) $plan['plan_hash'], $expected_plan)) {
            return new WP_Error('takka_bridge_post_content_plan_changed', 'Patch plan changed after preview or expected_plan_hash is missing.', ['status' => 409, 'current_plan_hash' => $plan['plan_hash']]);
        }
        if (!$plan['changed']) {
            return new WP_Error('takka_bridge_post_content_no_change', 'Patch would not change post content.', ['status' => 409]);
        }
        if (!in_array($plan['status'], ['draft', 'pending', 'auto-draft'], true) && empty($params['confirm_live'])) {
            return new WP_Error('takka_bridge_post_content_live_confirmation_required', 'Changing non-draft post content requires confirm_live=true.', ['status' => 400, 'status_value' => $plan['status']]);
        }

        $updated = wp_update_post([
            'ID' => (int) $plan['post_id'],
            'post_content' => $plan['_after_content'],
        ], true);
        if (is_wp_error($updated)) return $updated;

        $post = get_post((int) $plan['post_id']);
        if (!$post) return new WP_Error('takka_bridge_post_not_found_after_update', 'Post disappeared after update.', ['status' => 500]);
        $actual_sha = hash('sha256', (string) $post->post_content);
        if (!hash_equals((string) $plan['after_sha256'], $actual_sha)) {
            return new WP_Error('takka_bridge_post_content_verify_failed', 'Post content did not match the planned result after update.', ['status' => 500, 'actual_sha256' => $actual_sha]);
        }

        return rest_ensure_response([
            'ok' => true,
            'post_id' => (int) $post->ID,
            'status' => (string) $post->post_status,
            'modified_gmt' => (string) $post->post_modified_gmt,
            'before_sha256' => $plan['before_sha256'],
            'after_sha256' => $actual_sha,
            'replacements' => $plan['replacements'],
            'content_bytes' => strlen((string) $post->post_content),
            'plan_hash' => $plan['plan_hash'],
        ]);
    }

    private static function plan(array $params)
    {
        $context = self::context($params);
        if (is_wp_error($context)) return $context;
        $post = $context['post'];
        $content = (string) $post->post_content;
        if (strlen($content) > self::MAX_CONTENT_BYTES) {
            return new WP_Error('takka_bridge_post_content_size', 'Post content is too large for Bridge content tooling.', ['status' => 413]);
        }

        $find = isset($params['find']) && is_string($params['find']) ? $params['find'] : '';
        $replace = isset($params['replace']) && is_string($params['replace']) ? $params['replace'] : '';
        if ($find === '') return new WP_Error('takka_bridge_post_content_find', 'find must be a non-empty string.', ['status' => 400]);
        if (strlen($find) > self::MAX_REPLACEMENT_BYTES || strlen($replace) > self::MAX_REPLACEMENT_BYTES) {
            return new WP_Error('takka_bridge_post_content_patch_size', 'find/replace exceeds the patch size limit.', ['status' => 413]);
        }

        $replace_all = !empty($params['replace_all']);
        $expected_matches = isset($params['expected_matches']) ? max(0, (int) $params['expected_matches']) : 1;
        if (!$replace_all && $expected_matches !== 1) {
            return new WP_Error('takka_bridge_post_content_unique_required', 'Single replacement mode requires expected_matches=1. Use a more specific find string or replace_all=true.', ['status' => 400]);
        }
        $match_count = substr_count($content, $find);
        if ($match_count !== $expected_matches) {
            return new WP_Error('takka_bridge_post_content_match_count', 'Current exact match count differs from expected_matches.', ['status' => 409, 'expected_matches' => $expected_matches, 'actual_matches' => $match_count]);
        }

        $replacements = 0;
        if ($replace_all) {
            $after = str_replace($find, $replace, $content, $replacements);
        } else {
            $after = self::replace_first($content, $find, $replace, $replacements);
        }

        $before_sha = hash('sha256', $content);
        $after_sha = hash('sha256', $after);
        $plan_hash = hash('sha256', wp_json_encode([
            (int) $post->ID,
            (string) $post->post_status,
            (string) $post->post_modified_gmt,
            $before_sha,
            $after_sha,
            hash('sha256', $find),
            hash('sha256', $replace),
            (bool) $replace_all,
            (int) $match_count,
        ]));

        return [
            'post_id' => (int) $post->ID,
            'post_type' => (string) $post->post_type,
            'status' => (string) $post->post_status,
            'modified_gmt' => (string) $post->post_modified_gmt,
            'before_sha256' => $before_sha,
            'after_sha256' => $after_sha,
            'before_bytes' => strlen($content),
            'after_bytes' => strlen($after),
            'match_count' => (int) $match_count,
            'replacements' => (int) $replacements,
            'replace_all' => (bool) $replace_all,
            'changed' => !hash_equals($before_sha, $after_sha),
            'find_sha256' => hash('sha256', $find),
            'replace_sha256' => hash('sha256', $replace),
            'diff_excerpt' => self::diff_excerpt($content, $after),
            'plan_hash' => $plan_hash,
            '_after_content' => $after,
        ];
    }

    private static function context(array $params)
    {
        $post_id = isset($params['post_id']) ? absint($params['post_id']) : 0;
        if ($post_id < 1) return new WP_Error('takka_bridge_post_id_required', 'post_id is required.', ['status' => 400]);
        $post = get_post($post_id);
        if (!$post) return new WP_Error('takka_bridge_post_not_found', 'Post was not found.', ['status' => 404]);
        if (!current_user_can('edit_post', $post_id)) {
            return new WP_Error('takka_bridge_post_content_forbidden', 'Connected user cannot inspect or change this post.', ['status' => 403, 'post_id' => $post_id]);
        }
        return ['post' => $post];
    }

    private static function public_state(WP_Post $post): array
    {
        $content = (string) $post->post_content;
        $blocks = function_exists('parse_blocks') ? parse_blocks($content) : [];
        return [
            'post_id' => (int) $post->ID,
            'post_type' => (string) $post->post_type,
            'status' => (string) $post->post_status,
            'modified_gmt' => (string) $post->post_modified_gmt,
            'content_sha256' => hash('sha256', $content),
            'content_bytes' => strlen($content),
            'top_level_block_count' => is_array($blocks) ? count($blocks) : null,
        ];
    }

    private static function count_occurrences(string $content, string $query, bool $case_sensitive): int
    {
        if ($case_sensitive) return substr_count($content, $query);
        $count = 0;
        $offset = 0;
        $step = max(1, strlen($query));
        while (($pos = stripos($content, $query, $offset)) !== false) {
            $count++;
            $offset = (int) $pos + $step;
        }
        return $count;
    }

    private static function replace_first(string $content, string $find, string $replace, int &$replacements): string
    {
        $pos = strpos($content, $find);
        if ($pos === false) {
            $replacements = 0;
            return $content;
        }
        $replacements = 1;
        return substr($content, 0, $pos) . $replace . substr($content, $pos + strlen($find));
    }

    private static function context_before(string $content, int $byte_pos, int $chars): string
    {
        if ($chars <= 0 || $byte_pos <= 0) return '';
        $prefix = substr($content, 0, $byte_pos);
        if (function_exists('mb_substr')) return (string) mb_substr($prefix, -$chars, null, 'UTF-8');
        return (string) wp_check_invalid_utf8(substr($prefix, -min(strlen($prefix), $chars * 4)), true);
    }

    private static function context_after(string $content, int $byte_pos, int $chars): string
    {
        if ($chars <= 0 || $byte_pos >= strlen($content)) return '';
        $suffix = substr($content, $byte_pos);
        if (function_exists('mb_substr')) return (string) mb_substr($suffix, 0, $chars, 'UTF-8');
        return (string) wp_check_invalid_utf8(substr($suffix, 0, $chars * 4), true);
    }

    private static function diff_excerpt(string $before, string $after): array
    {
        if ($before === $after) return ['before' => '', 'after' => ''];
        $max = min(strlen($before), strlen($after));
        $prefix = 0;
        while ($prefix < $max && $before[$prefix] === $after[$prefix]) $prefix++;

        $before_suffix = strlen($before);
        $after_suffix = strlen($after);
        while ($before_suffix > $prefix && $after_suffix > $prefix && $before[$before_suffix - 1] === $after[$after_suffix - 1]) {
            $before_suffix--;
            $after_suffix--;
        }

        $context = 360;
        $before_start = max(0, $prefix - $context);
        $after_start = max(0, $prefix - $context);
        $before_end = min(strlen($before), $before_suffix + $context);
        $after_end = min(strlen($after), $after_suffix + $context);
        return [
            'before' => (string) wp_check_invalid_utf8(substr($before, $before_start, $before_end - $before_start), true),
            'after' => (string) wp_check_invalid_utf8(substr($after, $after_start, $after_end - $after_start), true),
            'prefix_omitted' => $before_start > 0,
            'suffix_omitted' => $before_end < strlen($before) || $after_end < strlen($after),
        ];
    }
}
