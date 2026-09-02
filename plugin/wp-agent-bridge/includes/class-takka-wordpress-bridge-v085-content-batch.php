<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TakKa_WordPress_Bridge_V085_Content_Batch
{
    private const MAX_CONTENT_BYTES = 4194304;
    private const MAX_OPERATIONS = 50;
    private const MAX_FRAGMENT_BYTES = 262144;

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
            return new WP_Error('takka_bridge_confirmation_required', 'Batch post content changes require confirm=true.', ['status' => 400]);
        }

        $plan = self::plan($params);
        if (is_wp_error($plan)) return $plan;

        $expected_before = isset($params['expected_before_sha256']) && is_string($params['expected_before_sha256']) ? trim($params['expected_before_sha256']) : '';
        if ($expected_before === '' || !hash_equals((string) $plan['before_sha256'], $expected_before)) {
            return new WP_Error('takka_bridge_post_content_changed', 'Post content changed after batch preview or expected_before_sha256 is missing.', [
                'status' => 409,
                'current_before_sha256' => $plan['before_sha256'],
                'current_modified_gmt' => $plan['modified_gmt'],
            ]);
        }

        $expected_plan = isset($params['expected_plan_hash']) && is_string($params['expected_plan_hash']) ? trim($params['expected_plan_hash']) : '';
        if ($expected_plan === '' || !hash_equals((string) $plan['plan_hash'], $expected_plan)) {
            return new WP_Error('takka_bridge_post_content_batch_plan_changed', 'Batch patch plan changed after preview or expected_plan_hash is missing.', [
                'status' => 409,
                'current_plan_hash' => $plan['plan_hash'],
            ]);
        }

        if (!$plan['changed']) {
            return new WP_Error('takka_bridge_post_content_no_change', 'Batch patch would not change post content.', ['status' => 409]);
        }

        if (!in_array($plan['status'], ['draft', 'pending', 'auto-draft'], true) && empty($params['confirm_live'])) {
            return new WP_Error('takka_bridge_post_content_live_confirmation_required', 'Changing non-draft post content requires confirm_live=true.', [
                'status' => 400,
                'status_value' => $plan['status'],
            ]);
        }

        $updated = wp_update_post([
            'ID' => (int) $plan['post_id'],
            'post_content' => $plan['_after_content'],
        ], true);
        if (is_wp_error($updated)) return $updated;

        $post = get_post((int) $plan['post_id']);
        if (!$post) {
            return new WP_Error('takka_bridge_post_not_found_after_update', 'Post disappeared after batch update.', ['status' => 500]);
        }

        $actual_content = (string) $post->post_content;
        $actual_sha = hash('sha256', $actual_content);
        if (!hash_equals((string) $plan['after_sha256'], $actual_sha)) {
            return new WP_Error('takka_bridge_post_content_verify_failed', 'Post content did not match the planned batch result after update.', [
                'status' => 500,
                'actual_sha256' => $actual_sha,
            ]);
        }

        return rest_ensure_response([
            'ok' => true,
            'post_id' => (int) $post->ID,
            'status' => (string) $post->post_status,
            'modified_gmt' => (string) $post->post_modified_gmt,
            'before_sha256' => $plan['before_sha256'],
            'after_sha256' => $actual_sha,
            'before_bytes' => $plan['before_bytes'],
            'after_bytes' => strlen($actual_content),
            'operation_count' => $plan['operation_count'],
            'total_replacements' => $plan['total_replacements'],
            'plan_hash' => $plan['plan_hash'],
        ]);
    }

    private static function plan(array $params)
    {
        $context = self::context($params);
        if (is_wp_error($context)) return $context;
        $post = $context['post'];
        $before = (string) $post->post_content;
        if (strlen($before) > self::MAX_CONTENT_BYTES) {
            return new WP_Error('takka_bridge_post_content_size', 'Post content is too large for Bridge batch tooling.', ['status' => 413]);
        }

        $operations = isset($params['operations']) ? $params['operations'] : null;
        if (!is_array($operations) || !$operations) {
            return new WP_Error('takka_bridge_batch_operations_required', 'operations must be a non-empty array.', ['status' => 400]);
        }
        if (count($operations) > self::MAX_OPERATIONS) {
            return new WP_Error('takka_bridge_batch_operations_limit', 'Too many batch operations.', ['status' => 413, 'max_operations' => self::MAX_OPERATIONS]);
        }

        $working = $before;
        $summaries = [];
        $plan_material = [];
        $total_replacements = 0;

        foreach (array_values($operations) as $index => $operation) {
            if (!is_array($operation)) {
                return new WP_Error('takka_bridge_batch_operation_object', 'Each batch operation must be an object.', ['status' => 400, 'index' => $index]);
            }
            $step = self::apply_operation($working, $operation, (int) $index);
            if (is_wp_error($step)) return $step;
            $working = $step['_after'];
            unset($step['_after']);
            $summaries[] = $step;
            $total_replacements += (int) $step['replacements'];
            $plan_material[] = [
                'type' => $step['type'],
                'expected_matches' => $step['expected_matches'],
                'actual_matches' => $step['actual_matches'],
                'replace_all' => $step['replace_all'],
                'selector_sha256' => $step['selector_sha256'],
                'payload_sha256' => $step['payload_sha256'],
                'before_sha256' => $step['before_sha256'],
                'after_sha256' => $step['after_sha256'],
            ];
        }

        $before_sha = hash('sha256', $before);
        $after_sha = hash('sha256', $working);
        $plan_hash = hash('sha256', wp_json_encode([
            (int) $post->ID,
            (string) $post->post_status,
            (string) $post->post_modified_gmt,
            $before_sha,
            $after_sha,
            $plan_material,
        ]));

        return [
            'post_id' => (int) $post->ID,
            'post_type' => (string) $post->post_type,
            'status' => (string) $post->post_status,
            'modified_gmt' => (string) $post->post_modified_gmt,
            'before_sha256' => $before_sha,
            'after_sha256' => $after_sha,
            'before_bytes' => strlen($before),
            'after_bytes' => strlen($working),
            'operation_count' => count($summaries),
            'total_replacements' => $total_replacements,
            'changed' => !hash_equals($before_sha, $after_sha),
            'operations' => $summaries,
            'diff_excerpt' => self::diff_excerpt($before, $working),
            'plan_hash' => $plan_hash,
            '_after_content' => $working,
        ];
    }

    private static function apply_operation(string $content, array $operation, int $index)
    {
        $type = isset($operation['type']) && is_string($operation['type']) ? strtolower(trim($operation['type'])) : '';
        if (!in_array($type, ['replace', 'insert_before', 'insert_after', 'delete'], true)) {
            return new WP_Error('takka_bridge_batch_operation_type', 'Batch operation type must be replace, insert_before, insert_after, or delete.', [
                'status' => 400,
                'index' => $index,
            ]);
        }

        $selector_key = in_array($type, ['insert_before', 'insert_after'], true) ? 'anchor' : 'find';
        $selector = isset($operation[$selector_key]) && is_string($operation[$selector_key]) ? $operation[$selector_key] : '';
        if ($selector === '') {
            return new WP_Error('takka_bridge_batch_selector_required', $selector_key . ' must be a non-empty string.', ['status' => 400, 'index' => $index]);
        }
        if (strlen($selector) > self::MAX_FRAGMENT_BYTES) {
            return new WP_Error('takka_bridge_batch_selector_size', 'Batch selector exceeds the fragment size limit.', ['status' => 413, 'index' => $index]);
        }

        if ($type === 'replace') {
            $payload = isset($operation['replace']) && is_string($operation['replace']) ? $operation['replace'] : '';
        } elseif ($type === 'delete') {
            $payload = '';
        } else {
            $payload = isset($operation['content']) && is_string($operation['content']) ? $operation['content'] : '';
        }
        if (strlen($payload) > self::MAX_FRAGMENT_BYTES) {
            return new WP_Error('takka_bridge_batch_payload_size', 'Batch payload exceeds the fragment size limit.', ['status' => 413, 'index' => $index]);
        }
        if (in_array($type, ['insert_before', 'insert_after'], true) && $payload === '') {
            return new WP_Error('takka_bridge_batch_insert_empty', 'Insert content must be non-empty.', ['status' => 400, 'index' => $index]);
        }

        $replace_all = !empty($operation['replace_all']);
        $expected_matches = isset($operation['expected_matches']) ? max(0, (int) $operation['expected_matches']) : 1;
        if (!$replace_all && $expected_matches !== 1) {
            return new WP_Error('takka_bridge_batch_unique_required', 'Single-operation mode requires expected_matches=1. Use a more specific selector or replace_all=true.', [
                'status' => 400,
                'index' => $index,
            ]);
        }

        $actual_matches = substr_count($content, $selector);
        if ($actual_matches !== $expected_matches) {
            return new WP_Error('takka_bridge_batch_match_count', 'Current exact match count differs from expected_matches.', [
                'status' => 409,
                'index' => $index,
                'type' => $type,
                'expected_matches' => $expected_matches,
                'actual_matches' => $actual_matches,
            ]);
        }

        if ($type === 'replace') {
            $replacement = $payload;
        } elseif ($type === 'delete') {
            $replacement = '';
        } elseif ($type === 'insert_before') {
            $replacement = $payload . $selector;
        } else {
            $replacement = $selector . $payload;
        }

        $replacements = 0;
        if ($replace_all) {
            $after = str_replace($selector, $replacement, $content, $replacements);
        } else {
            $after = self::replace_first($content, $selector, $replacement, $replacements);
        }

        if ($after === $content) {
            return new WP_Error('takka_bridge_batch_operation_no_change', 'A batch operation would not change content.', ['status' => 409, 'index' => $index]);
        }
        if (strlen($after) > self::MAX_CONTENT_BYTES) {
            return new WP_Error('takka_bridge_post_content_size', 'Batch operation would exceed the content size limit.', ['status' => 413, 'index' => $index]);
        }

        return [
            'index' => $index,
            'type' => $type,
            'expected_matches' => $expected_matches,
            'actual_matches' => $actual_matches,
            'replace_all' => $replace_all,
            'replacements' => (int) $replacements,
            'before_sha256' => hash('sha256', $content),
            'after_sha256' => hash('sha256', $after),
            'before_bytes' => strlen($content),
            'after_bytes' => strlen($after),
            'selector_sha256' => hash('sha256', $selector),
            'selector_bytes' => strlen($selector),
            'payload_sha256' => hash('sha256', $payload),
            'payload_bytes' => strlen($payload),
            '_after' => $after,
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

    private static function diff_excerpt(string $before, string $after): array
    {
        if ($before === $after) return ['before' => '', 'after' => '', 'prefix_omitted' => false, 'suffix_omitted' => false];
        $max = min(strlen($before), strlen($after));
        $prefix = 0;
        while ($prefix < $max && $before[$prefix] === $after[$prefix]) $prefix++;

        $before_suffix = strlen($before);
        $after_suffix = strlen($after);
        while ($before_suffix > $prefix && $after_suffix > $prefix && $before[$before_suffix - 1] === $after[$after_suffix - 1]) {
            $before_suffix--;
            $after_suffix--;
        }

        $context = 500;
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
