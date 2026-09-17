<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Guarded compound post change with compensating rollback.
 *
 * This is deliberately not described as a database transaction. WordPress core
 * REST updates can touch the post row, taxonomies, featured media and hooks in
 * separate phases. The Bridge therefore previews the exact relevant state,
 * revalidates a plan hash immediately before mutation, creates a WordPress
 * revision backup for revisioned fields, and compensates/verbosely verifies the
 * durable post state if the compound update fails part-way through.
 */
final class TakKa_WordPress_Bridge_Post_Compound_Change
{
    private const ROUTE = '/takka-v099/v1/operate';
    private const HEALTH = '/takka-bridge/v1/health';
    private const PLAN_VERSION = 1;
    private const MAX_CONTENT_BYTES = 4194304;
    private const MAX_FRAGMENT_BYTES = 262144;

    private const OPERATIONS = [
        'post.change.preview',
        'post.change.apply',
    ];

    private const SAFE_FIELDS = [
        'title',
        'excerpt',
        'slug',
        'status',
        'author',
        'featured_media',
        'comment_status',
        'ping_status',
        'sticky',
        'template',
        'format',
        'categories',
        'tags',
        'parent',
        'menu_order',
    ];

    public static function init(): void
    {
        add_filter('rest_pre_dispatch', [self::class, 'pre_dispatch'], 75, 3);
        add_filter('rest_request_after_callbacks', [self::class, 'annotate_contract'], 676, 3);
    }

    public static function pre_dispatch($result, WP_REST_Server $server, WP_REST_Request $request)
    {
        if ($result !== null
            || $request->get_route() !== self::ROUTE
            || strtoupper($request->get_method()) !== 'POST') {
            return $result;
        }
        $json = $request->get_json_params();
        if (!is_array($json)) {
            return $result;
        }
        $operation = is_string($json['operation'] ?? null) ? trim($json['operation']) : '';
        if (!in_array($operation, self::OPERATIONS, true)) {
            return $result;
        }

        $permission = TakKa_WordPress_Bridge_V099_Operations::permission();
        if (is_wp_error($permission)) {
            return TakKa_WordPress_Bridge_V099_Response_Contract::normalize_operation($permission, [], $request);
        }
        if ($permission !== true) {
            $denied = new WP_Error('wpab_post_change_internal_only', 'Compound post changes are only callable through the signed Bridge operation path.', [
                'status' => 403,
                'side_effects' => false,
            ]);
            return TakKa_WordPress_Bridge_V099_Response_Contract::normalize_operation($denied, [], $request);
        }

        $params = isset($json['params']) && is_array($json['params']) ? $json['params'] : [];
        $handled = $operation === 'post.change.preview'
            ? self::preview($params)
            : self::apply($params);
        return TakKa_WordPress_Bridge_V099_Response_Contract::normalize_operation(
            self::operation_response($operation, $handled),
            [],
            $request
        );
    }

    public static function annotate_contract($response, array $handler, WP_REST_Request $request)
    {
        if (is_wp_error($response)) {
            return $response;
        }
        $rest = rest_ensure_response($response);
        $data = $rest->get_data();
        if (!is_array($data)) {
            return $response;
        }

        if ($request->get_route() === self::ROUTE) {
            $json = $request->get_json_params();
            if (is_array($json) && ($json['operation'] ?? '') === 'catalog') {
                $ops = isset($data['operations']) && is_array($data['operations']) ? $data['operations'] : [];
                foreach (self::OPERATIONS as $operation) {
                    if (!in_array($operation, $ops, true)) {
                        $ops[] = $operation;
                    }
                }
                $data['operations'] = $ops;
                $data['post_compound_change'] = self::contract();
                $rest->set_data($data);
                return $rest;
            }
        }

        if ($request->get_route() === self::HEALTH) {
            $features = isset($data['features']) && is_array($data['features']) ? $data['features'] : [];
            foreach (['post_compound_guarded_change', 'post_compensating_rollback'] as $feature) {
                if (!in_array($feature, $features, true)) {
                    $features[] = $feature;
                }
            }
            $data['features'] = $features;
            $data['post_compound_change'] = self::contract();
            $rest->set_data($data);
            return $rest;
        }

        return $response;
    }

    public static function contract(): array
    {
        return [
            'operations' => self::OPERATIONS,
            'plan_version' => self::PLAN_VERSION,
            'safe_metadata_fields' => self::SAFE_FIELDS,
            'content_patch' => [
                'exact_match_only' => true,
                'max_content_bytes' => self::MAX_CONTENT_BYTES,
                'max_fragment_bytes' => self::MAX_FRAGMENT_BYTES,
            ],
            'preview_side_effects' => false,
            'apply_requires' => ['confirm', 'expected_plan_hash'],
            'non_draft_or_live_target_requires_confirm_live' => true,
            'unrelated_untouched_metadata_may_change_between_preview_and_apply' => true,
            'revision_backup_for_revisioned_fields' => true,
            'rollback_mode' => 'compensating_rest_update_plus_revision_fallback',
            'database_transaction_claimed' => false,
            'rollback_reports_durable_state_restored' => true,
        ];
    }

    private static function preview(array $params)
    {
        $plan = self::plan($params);
        if (is_wp_error($plan)) {
            return $plan;
        }
        return self::public_plan($plan);
    }

    private static function apply(array $params)
    {
        if (empty($params['confirm'])) {
            return new WP_Error('wpab_post_change_confirmation', 'Compound post change requires confirm=true.', [
                'status' => 400,
                'side_effects' => false,
            ]);
        }
        $expected_plan = isset($params['expected_plan_hash']) && is_string($params['expected_plan_hash'])
            ? strtolower(trim($params['expected_plan_hash']))
            : '';
        if (!preg_match('/^[a-f0-9]{64}$/D', $expected_plan)) {
            return new WP_Error('wpab_post_change_plan_hash', 'expected_plan_hash must be the SHA-256 returned by post.change.preview.', [
                'status' => 400,
                'side_effects' => false,
            ]);
        }

        $plan = self::plan($params);
        if (is_wp_error($plan)) {
            return $plan;
        }
        if (!hash_equals($expected_plan, (string) $plan['plan_hash'])) {
            return new WP_Error('wpab_post_change_plan_changed', 'Relevant post state or the compound change specification changed after preview.', [
                'status' => 409,
                'expected_plan_hash' => $expected_plan,
                'current_plan_hash' => $plan['plan_hash'],
                'current_relevant_state_hash' => $plan['before_relevant_state_hash'],
                'side_effects' => false,
            ]);
        }
        if (empty($plan['changed'])) {
            return new WP_Error('wpab_post_change_no_change', 'Compound change would not modify the post.', [
                'status' => 409,
                'side_effects' => false,
            ]);
        }

        $before_status = (string) $plan['_post']->post_status;
        $target_status = array_key_exists('status', $plan['_desired_fields'])
            ? (string) $plan['_desired_fields']['status']
            : $before_status;
        $live_statuses = ['publish', 'private', 'future'];
        if ((!in_array($before_status, ['draft', 'pending', 'auto-draft'], true)
                || in_array($target_status, $live_statuses, true))
            && empty($params['confirm_live'])) {
            return new WP_Error('wpab_post_change_live_confirmation', 'Changing a live post or targeting a live status requires confirm_live=true.', [
                'status' => 400,
                'current_status' => $before_status,
                'target_status' => $target_status,
                'side_effects' => false,
            ]);
        }

        $before_body = self::rollback_body($plan);
        $backup_revision_id = 0;
        if (self::touches_revisioned_fields($plan)) {
            $backup_revision_id = self::ensure_backup_revision($plan['_post']);
            if ($backup_revision_id < 1) {
                return new WP_Error('wpab_post_change_backup_unavailable', 'Could not establish a WordPress revision rollback point; compound change was blocked.', [
                    'status' => 409,
                    'side_effects' => false,
                ]);
            }
        }

        // Re-check the exact relevant state after preparing the backup. A normal
        // revision save must not mutate the current post, but plugins/hooks can.
        $current = get_post((int) $plan['post_id']);
        if (!$current
            || !hash_equals((string) $plan['before_relevant_state_hash'], self::relevant_state_hash(
                $current,
                array_keys($plan['_before_fields']),
                !empty($plan['_content_patch'])
            ))) {
            return new WP_Error('wpab_post_change_state_changed_during_backup', 'Relevant post state changed while the rollback point was prepared.', [
                'status' => 409,
                'backup_revision_id' => $backup_revision_id ?: null,
                'side_effects' => false,
            ]);
        }

        $apply_body = $plan['_desired_fields'];
        if (!empty($plan['_content_patch'])) {
            $apply_body['content'] = $plan['_after_content'];
        }

        $applied = self::core_update($plan['_post'], $apply_body, 'apply');
        if (is_wp_error($applied)) {
            return self::compensate_failure($plan, $before_body, $backup_revision_id, $applied, 'core_update_error');
        }

        $verified = self::verify_after($plan);
        if (is_wp_error($verified)) {
            return self::compensate_failure($plan, $before_body, $backup_revision_id, $verified, 'verification_error');
        }

        $after_post = get_post((int) $plan['post_id']);
        return [
            'post_id' => (int) $plan['post_id'],
            'post_type' => (string) $plan['post_type'],
            'status' => $after_post ? (string) $after_post->post_status : $target_status,
            'changed_fields' => array_values(array_keys($plan['_desired_fields'])),
            'content_patch_applied' => !empty($plan['_content_patch']),
            'content_replacements' => (int) ($plan['content_replacements'] ?? 0),
            'plan_hash' => $plan['plan_hash'],
            'before_relevant_state_hash' => $plan['before_relevant_state_hash'],
            'after_relevant_state_hash' => $verified['relevant_state_hash'],
            'backup_revision_id' => $backup_revision_id ?: null,
            'rollback_available' => $backup_revision_id > 0 || !empty($before_body),
            'modified_gmt' => $after_post ? (string) $after_post->post_modified_gmt : null,
            'side_effects' => true,
        ];
    }

    private static function plan(array $params)
    {
        $post = self::resolve_post($params);
        if (is_wp_error($post)) {
            return $post;
        }
        if (!current_user_can('edit_post', $post->ID)) {
            return new WP_Error('wpab_post_change_forbidden', 'Connected user cannot edit this post or page.', ['status' => 403, 'side_effects' => false]);
        }

        $fields = isset($params['fields']) ? $params['fields'] : [];
        if (!is_array($fields)) {
            return new WP_Error('wpab_post_change_fields', 'fields must be an object.', ['status' => 400, 'side_effects' => false]);
        }
        if (array_key_exists('content', $fields)) {
            return new WP_Error('wpab_post_change_content_field', 'Use content_patch instead of fields.content.', ['status' => 400, 'side_effects' => false]);
        }

        $before_fields = [];
        $desired_fields = [];
        foreach ($fields as $field => $value) {
            if (!is_string($field) || !in_array($field, self::SAFE_FIELDS, true)) {
                return new WP_Error('wpab_post_change_field_unsupported', 'fields contains an unsupported compound-change field.', [
                    'status' => 400,
                    'field' => $field,
                    'supported_fields' => self::SAFE_FIELDS,
                    'side_effects' => false,
                ]);
            }
            $valid = self::validate_field_for_type($field, $post);
            if (is_wp_error($valid)) {
                return $valid;
            }
            $normalized = self::normalize_desired_field($field, $value, $post);
            if (is_wp_error($normalized)) {
                return $normalized;
            }
            $before_fields[$field] = self::current_field_value($post, $field);
            $desired_fields[$field] = $normalized;
        }
        ksort($before_fields, SORT_STRING);
        ksort($desired_fields, SORT_STRING);

        $content_patch = isset($params['content_patch']) ? $params['content_patch'] : null;
        if ($content_patch !== null && !is_array($content_patch)) {
            return new WP_Error('wpab_post_change_content_patch', 'content_patch must be an object.', ['status' => 400, 'side_effects' => false]);
        }
        if (!$desired_fields && !$content_patch) {
            return new WP_Error('wpab_post_change_empty', 'Provide at least one metadata field or content_patch.', ['status' => 400, 'side_effects' => false]);
        }

        $before_content = (string) $post->post_content;
        $after_content = $before_content;
        $content_summary = null;
        if ($content_patch) {
            $content_summary = self::content_plan($before_content, $content_patch);
            if (is_wp_error($content_summary)) {
                return $content_summary;
            }
            $after_content = $content_summary['_after'];
        }

        $before_field_hashes = [];
        $desired_field_hashes = [];
        foreach ($before_fields as $field => $value) {
            $before_field_hashes[$field] = self::value_hash($value);
            $desired_field_hashes[$field] = self::value_hash($desired_fields[$field]);
        }
        $include_content = $content_summary !== null;
        $before_relevant_state_hash = self::relevant_state_hash($post, array_keys($before_fields), $include_content);

        $plan_material = [
            'version' => self::PLAN_VERSION,
            'post_id' => (int) $post->ID,
            'post_type' => (string) $post->post_type,
            'current_status' => (string) $post->post_status,
            'before_field_hashes' => $before_field_hashes,
            'desired_field_hashes' => $desired_field_hashes,
            'before_relevant_state_hash' => $before_relevant_state_hash,
            'content' => $content_summary === null ? null : [
                'find_sha256' => $content_summary['find_sha256'],
                'replace_sha256' => $content_summary['replace_sha256'],
                'replace_all' => $content_summary['replace_all'],
                'expected_matches' => $content_summary['expected_matches'],
                'actual_matches' => $content_summary['actual_matches'],
                'before_sha256' => $content_summary['before_sha256'],
                'after_sha256' => $content_summary['after_sha256'],
            ],
        ];
        $encoded = wp_json_encode($plan_material, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $plan_hash = hash('sha256', is_string($encoded) ? $encoded : '');

        $metadata_changed = false;
        foreach ($before_fields as $field => $before_value) {
            if (!hash_equals(self::value_hash($before_value), self::value_hash($desired_fields[$field]))) {
                $metadata_changed = true;
                break;
            }
        }
        $content_changed = $content_summary !== null && !hash_equals($content_summary['before_sha256'], $content_summary['after_sha256']);

        return [
            'ok' => true,
            'plan_version' => self::PLAN_VERSION,
            'post_id' => (int) $post->ID,
            'post_type' => (string) $post->post_type,
            'status' => (string) $post->post_status,
            'modified_gmt' => (string) $post->post_modified_gmt,
            'changed_fields' => array_values(array_keys($desired_fields)),
            'before_field_hashes' => $before_field_hashes,
            'desired_field_hashes' => $desired_field_hashes,
            'content_patch' => $content_summary === null ? null : [
                'expected_matches' => $content_summary['expected_matches'],
                'actual_matches' => $content_summary['actual_matches'],
                'replace_all' => $content_summary['replace_all'],
                'before_sha256' => $content_summary['before_sha256'],
                'after_sha256' => $content_summary['after_sha256'],
                'find_sha256' => $content_summary['find_sha256'],
                'replace_sha256' => $content_summary['replace_sha256'],
            ],
            'content_replacements' => $content_summary === null ? 0 : (int) $content_summary['replacements'],
            'before_relevant_state_hash' => $before_relevant_state_hash,
            'plan_hash' => $plan_hash,
            'changed' => $metadata_changed || $content_changed,
            'side_effects' => false,
            '_post' => $post,
            '_before_fields' => $before_fields,
            '_desired_fields' => $desired_fields,
            '_content_patch' => $content_summary,
            '_before_content' => $before_content,
            '_after_content' => $after_content,
        ];
    }

    private static function public_plan(array $plan): array
    {
        foreach (array_keys($plan) as $key) {
            if (strpos($key, '_') === 0) {
                unset($plan[$key]);
            }
        }
        return $plan;
    }

    private static function content_plan(string $before, array $patch)
    {
        if (strlen($before) > self::MAX_CONTENT_BYTES) {
            return new WP_Error('wpab_post_change_content_size', 'Post content exceeds compound-change size limit.', ['status' => 413, 'side_effects' => false]);
        }
        $find = isset($patch['find']) && is_string($patch['find']) ? $patch['find'] : '';
        $replace = isset($patch['replace']) && is_string($patch['replace']) ? $patch['replace'] : null;
        if ($find === '' || $replace === null) {
            return new WP_Error('wpab_post_change_content_patch_fields', 'content_patch requires non-empty find and string replace.', ['status' => 400, 'side_effects' => false]);
        }
        if (strlen($find) > self::MAX_FRAGMENT_BYTES || strlen($replace) > self::MAX_FRAGMENT_BYTES) {
            return new WP_Error('wpab_post_change_fragment_size', 'content_patch find/replace exceeds the fragment limit.', ['status' => 413, 'side_effects' => false]);
        }
        $replace_all = !empty($patch['replace_all']);
        $expected = isset($patch['expected_matches']) ? (int) $patch['expected_matches'] : 1;
        if ($expected < 0 || (!$replace_all && $expected !== 1)) {
            return new WP_Error('wpab_post_change_expected_matches', 'Single replacement mode requires expected_matches=1; replace_all may use another non-negative count.', ['status' => 400, 'side_effects' => false]);
        }
        $actual = substr_count($before, $find);
        if ($actual !== $expected) {
            return new WP_Error('wpab_post_change_match_conflict', 'content_patch exact match count differs from expected_matches.', [
                'status' => 409,
                'expected_matches' => $expected,
                'actual_matches' => $actual,
                'content_sha256' => hash('sha256', $before),
                'side_effects' => false,
            ]);
        }
        $replacements = 0;
        if ($replace_all) {
            $after = str_replace($find, $replace, $before, $replacements);
        } else {
            $pos = strpos($before, $find);
            $after = $pos === false
                ? $before
                : substr($before, 0, $pos) . $replace . substr($before, $pos + strlen($find));
            $replacements = $pos === false ? 0 : 1;
        }
        if (strlen($after) > self::MAX_CONTENT_BYTES) {
            return new WP_Error('wpab_post_change_content_size', 'content_patch would exceed compound-change size limit.', ['status' => 413, 'side_effects' => false]);
        }
        return [
            'expected_matches' => $expected,
            'actual_matches' => $actual,
            'replacements' => $replacements,
            'replace_all' => $replace_all,
            'find_sha256' => hash('sha256', $find),
            'replace_sha256' => hash('sha256', $replace),
            'before_sha256' => hash('sha256', $before),
            'after_sha256' => hash('sha256', $after),
            '_after' => $after,
        ];
    }

    private static function compensate_failure(array $plan, array $before_body, int $backup_revision_id, WP_Error $cause, string $stage): WP_Error
    {
        $current = get_post((int) $plan['post_id']);
        $current_hash = $current
            ? self::relevant_state_hash($current, array_keys($plan['_before_fields']), !empty($plan['_content_patch']))
            : '';
        if ($current_hash !== '' && hash_equals((string) $plan['before_relevant_state_hash'], $current_hash)) {
            $data = $cause->get_error_data();
            $data = is_array($data) ? $data : [];
            $data['status'] = isset($data['status']) && is_numeric($data['status']) ? (int) $data['status'] : 500;
            $data['compound_stage'] = $stage;
            $data['durable_state_changed'] = false;
            $data['durable_state_restored'] = true;
            $data['backup_revision_id'] = $backup_revision_id ?: null;
            $data['side_effects'] = false;
            return new WP_Error($cause->get_error_code(), $cause->get_error_message(), $data);
        }

        $rollback = self::core_update($plan['_post'], $before_body, 'rollback');
        $revision_fallback_used = false;
        $after_rollback = get_post((int) $plan['post_id']);
        $restored = $after_rollback
            && hash_equals((string) $plan['before_relevant_state_hash'], self::relevant_state_hash(
                $after_rollback,
                array_keys($plan['_before_fields']),
                !empty($plan['_content_patch'])
            ));

        if (!$restored && $backup_revision_id > 0 && self::touches_revisioned_fields($plan)) {
            $revision_fallback_used = true;
            wp_restore_post_revision($backup_revision_id, ['post_title', 'post_content', 'post_excerpt']);
            $after_rollback = get_post((int) $plan['post_id']);
            $restored = $after_rollback
                && hash_equals((string) $plan['before_relevant_state_hash'], self::relevant_state_hash(
                    $after_rollback,
                    array_keys($plan['_before_fields']),
                    !empty($plan['_content_patch'])
                ));
        }

        $rollback_error = is_wp_error($rollback) ? [
            'code' => $rollback->get_error_code(),
            'message' => $rollback->get_error_message(),
        ] : null;
        return new WP_Error(
            $restored ? 'wpab_post_change_apply_failed_rolled_back' : 'wpab_post_change_rollback_incomplete',
            $restored
                ? 'Compound post update failed after mutation; the durable post state was restored.'
                : 'Compound post update failed and the Bridge could not fully restore the prior durable post state.',
            [
                'status' => 500,
                'cause_code' => $cause->get_error_code(),
                'cause_message' => $cause->get_error_message(),
                'compound_stage' => $stage,
                'backup_revision_id' => $backup_revision_id ?: null,
                'rollback_error' => $rollback_error,
                'revision_fallback_used' => $revision_fallback_used,
                'durable_state_restored' => $restored,
                // A real mutation attempt occurred; even a fully restored DB
                // state cannot prove that third-party hooks had no side effects.
                'side_effects' => true,
            ]
        );
    }

    private static function verify_after(array $plan)
    {
        $post = get_post((int) $plan['post_id']);
        if (!$post) {
            return new WP_Error('wpab_post_change_verify_missing', 'Post disappeared after compound update.', ['status' => 500]);
        }
        $mismatches = [];
        foreach ($plan['_desired_fields'] as $field => $desired) {
            $actual = self::current_field_value($post, $field);
            if (!hash_equals(self::value_hash($desired), self::value_hash($actual))) {
                $mismatches[] = [
                    'field' => $field,
                    'expected_sha256' => self::value_hash($desired),
                    'actual_sha256' => self::value_hash($actual),
                ];
            }
        }
        if (!empty($plan['_content_patch'])) {
            $actual_content_hash = hash('sha256', (string) $post->post_content);
            if (!hash_equals((string) $plan['_content_patch']['after_sha256'], $actual_content_hash)) {
                $mismatches[] = [
                    'field' => 'content',
                    'expected_sha256' => $plan['_content_patch']['after_sha256'],
                    'actual_sha256' => $actual_content_hash,
                ];
            }
        }
        if ($mismatches) {
            return new WP_Error('wpab_post_change_verify_failed', 'Compound update did not match the planned relevant state.', [
                'status' => 500,
                'mismatches' => $mismatches,
            ]);
        }
        return [
            'relevant_state_hash' => self::relevant_state_hash(
                $post,
                array_keys($plan['_desired_fields']),
                !empty($plan['_content_patch'])
            ),
        ];
    }

    private static function rollback_body(array $plan): array
    {
        $body = $plan['_before_fields'];
        if (!empty($plan['_content_patch'])) {
            $body['content'] = $plan['_before_content'];
        }
        return $body;
    }

    private static function core_update(WP_Post $post, array $body, string $phase)
    {
        if (!$body) {
            return true;
        }
        $route = '/wp/v2/' . ($post->post_type === 'page' ? 'pages/' : 'posts/') . (int) $post->ID;
        $encoded = wp_json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            return new WP_Error('wpab_post_change_encode', 'Could not encode the core REST compound update.', ['status' => 500]);
        }
        $request = new WP_REST_Request('POST', $route);
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('X-WPAB-Compound-Phase', $phase);
        $request->set_body($encoded);
        $response = rest_do_request($request);
        if (is_wp_error($response)) {
            return $response;
        }
        $rest = rest_ensure_response($response);
        if ($rest->get_status() < 200 || $rest->get_status() >= 300) {
            $data = $rest->get_data();
            $code = is_array($data) && is_string($data['code'] ?? null) ? $data['code'] : 'wpab_post_change_core_rest';
            $message = is_array($data) && is_string($data['message'] ?? null) ? $data['message'] : 'Core REST compound update failed.';
            return new WP_Error($code, $message, [
                'status' => $rest->get_status(),
                'core_data' => $data,
            ]);
        }
        return true;
    }

    private static function resolve_post(array $params)
    {
        $post_id = isset($params['post_id']) ? absint($params['post_id']) : 0;
        $target_url = isset($params['target_url']) && is_string($params['target_url']) ? trim($params['target_url']) : '';
        if ($target_url !== '') {
            $parts = wp_parse_url($target_url);
            $home = wp_parse_url(home_url('/'));
            if (!is_array($parts) || !is_array($home)
                || empty($parts['host'])
                || strtolower((string) $parts['host']) !== strtolower((string) ($home['host'] ?? ''))
                || isset($parts['user']) || isset($parts['pass'])) {
                return new WP_Error('wpab_post_change_target_url', 'target_url must resolve to this WordPress site without credentials.', ['status' => 400, 'side_effects' => false]);
            }
            $resolved = (int) url_to_postid($target_url);
            if ($resolved < 1) {
                return new WP_Error('wpab_post_change_target_missing', 'target_url did not resolve to a post or page.', ['status' => 404, 'side_effects' => false]);
            }
            if ($post_id > 0 && $post_id !== $resolved) {
                return new WP_Error('wpab_post_change_target_conflict', 'post_id and target_url identify different objects.', [
                    'status' => 409,
                    'post_id' => $post_id,
                    'resolved_post_id' => $resolved,
                    'side_effects' => false,
                ]);
            }
            $post_id = $resolved;
        }
        if ($post_id < 1) {
            return new WP_Error('wpab_post_change_post_id', 'post_id or target_url is required.', ['status' => 400, 'side_effects' => false]);
        }
        $post = get_post($post_id);
        if (!$post || !in_array($post->post_type, ['post', 'page'], true) || $post->post_status === 'trash') {
            return new WP_Error('wpab_post_change_post_missing', 'Post or page was not found.', ['status' => 404, 'side_effects' => false]);
        }
        return $post;
    }

    private static function validate_field_for_type(string $field, WP_Post $post)
    {
        if ($post->post_type === 'page' && in_array($field, ['sticky', 'format', 'categories', 'tags'], true)) {
            return new WP_Error('wpab_post_change_field_type', $field . ' is not supported for pages by the compound operation.', [
                'status' => 400,
                'field' => $field,
                'post_type' => 'page',
                'side_effects' => false,
            ]);
        }
        if ($post->post_type === 'post' && in_array($field, ['parent', 'menu_order'], true)) {
            return new WP_Error('wpab_post_change_field_type', $field . ' is only supported for pages by the compound operation.', [
                'status' => 400,
                'field' => $field,
                'post_type' => 'post',
                'side_effects' => false,
            ]);
        }
        return true;
    }

    private static function normalize_desired_field(string $field, $value, WP_Post $post)
    {
        if (in_array($field, ['title', 'excerpt', 'template'], true)) {
            if (!is_string($value) || strlen($value) > self::MAX_FRAGMENT_BYTES) {
                return new WP_Error('wpab_post_change_field_string', $field . ' must be a string within the fragment limit.', ['status' => 400, 'field' => $field, 'side_effects' => false]);
            }
            return $value;
        }
        if ($field === 'slug') {
            if (!is_string($value) || strlen($value) > 1000) {
                return new WP_Error('wpab_post_change_slug', 'slug must be a string up to 1000 bytes.', ['status' => 400, 'side_effects' => false]);
            }
            return sanitize_title($value);
        }
        if ($field === 'status') {
            if (!is_string($value) || !in_array($value, ['draft', 'pending', 'private', 'publish', 'future'], true)) {
                return new WP_Error('wpab_post_change_status', 'status must be draft, pending, private, publish, or future.', ['status' => 400, 'side_effects' => false]);
            }
            return $value;
        }
        if (in_array($field, ['author', 'featured_media', 'parent', 'menu_order'], true)) {
            if (!(is_int($value) || (is_string($value) && preg_match('/^-?[0-9]+$/D', $value)))) {
                return new WP_Error('wpab_post_change_integer', $field . ' must be an integer.', ['status' => 400, 'field' => $field, 'side_effects' => false]);
            }
            $int = (int) $value;
            if (in_array($field, ['author', 'featured_media', 'parent'], true) && $int < 0) {
                return new WP_Error('wpab_post_change_integer', $field . ' must be zero or positive.', ['status' => 400, 'field' => $field, 'side_effects' => false]);
            }
            if ($field === 'author' && ($int < 1 || !get_user_by('id', $int))) {
                return new WP_Error('wpab_post_change_author', 'author must identify an existing WordPress user.', ['status' => 400, 'side_effects' => false]);
            }
            if ($field === 'featured_media' && $int > 0 && get_post_type($int) !== 'attachment') {
                return new WP_Error('wpab_post_change_featured_media', 'featured_media must be zero or an existing attachment ID.', ['status' => 400, 'side_effects' => false]);
            }
            if ($field === 'parent' && $int === (int) $post->ID) {
                return new WP_Error('wpab_post_change_parent', 'A page cannot be its own parent.', ['status' => 400, 'side_effects' => false]);
            }
            return $int;
        }
        if (in_array($field, ['comment_status', 'ping_status'], true)) {
            if (!is_string($value) || !in_array($value, ['open', 'closed'], true)) {
                return new WP_Error('wpab_post_change_open_closed', $field . ' must be open or closed.', ['status' => 400, 'field' => $field, 'side_effects' => false]);
            }
            return $value;
        }
        if ($field === 'sticky') {
            if (!is_bool($value)) {
                return new WP_Error('wpab_post_change_sticky', 'sticky must be boolean.', ['status' => 400, 'side_effects' => false]);
            }
            return $value;
        }
        if ($field === 'format') {
            if (!is_string($value) || $value === '') {
                return new WP_Error('wpab_post_change_format', 'format must be a non-empty string.', ['status' => 400, 'side_effects' => false]);
            }
            return $value;
        }
        if (in_array($field, ['categories', 'tags'], true)) {
            if (!is_array($value)) {
                return new WP_Error('wpab_post_change_terms', $field . ' must be an array of term IDs.', ['status' => 400, 'field' => $field, 'side_effects' => false]);
            }
            $ids = [];
            foreach ($value as $term_id) {
                $id = absint($term_id);
                if ($id < 1) {
                    return new WP_Error('wpab_post_change_terms', $field . ' must contain positive term IDs only.', ['status' => 400, 'field' => $field, 'side_effects' => false]);
                }
                $ids[$id] = true;
            }
            $ids = array_keys($ids);
            sort($ids, SORT_NUMERIC);
            return array_values(array_map('intval', $ids));
        }
        return new WP_Error('wpab_post_change_field_unsupported', 'Unsupported compound metadata field.', ['status' => 400, 'field' => $field, 'side_effects' => false]);
    }

    private static function current_field_value(WP_Post $post, string $field)
    {
        switch ($field) {
            case 'title': return (string) $post->post_title;
            case 'excerpt': return (string) $post->post_excerpt;
            case 'slug': return (string) $post->post_name;
            case 'status': return (string) $post->post_status;
            case 'author': return (int) $post->post_author;
            case 'featured_media': return (int) get_post_thumbnail_id($post->ID);
            case 'comment_status': return (string) $post->comment_status;
            case 'ping_status': return (string) $post->ping_status;
            case 'sticky': return $post->post_type === 'post' ? is_sticky($post->ID) : false;
            case 'template': return (string) (get_page_template_slug($post->ID) ?: '');
            case 'format': return (string) (get_post_format($post->ID) ?: 'standard');
            case 'categories': return self::term_ids($post->ID, 'category');
            case 'tags': return self::term_ids($post->ID, 'post_tag');
            case 'parent': return (int) $post->post_parent;
            case 'menu_order': return (int) $post->menu_order;
        }
        return null;
    }

    private static function term_ids(int $post_id, string $taxonomy): array
    {
        $ids = wp_get_post_terms($post_id, $taxonomy, ['fields' => 'ids']);
        if (is_wp_error($ids) || !is_array($ids)) {
            return [];
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    private static function relevant_state_hash(WP_Post $post, array $fields, bool $include_content): string
    {
        sort($fields, SORT_STRING);
        $hashes = [];
        foreach ($fields as $field) {
            $hashes[$field] = self::value_hash(self::current_field_value($post, $field));
        }
        $material = [
            'post_id' => (int) $post->ID,
            'post_type' => (string) $post->post_type,
            // Status is always a guard because transitioning draft/live state
            // changes the confirmation semantics even when status was untouched.
            'current_status' => (string) $post->post_status,
            'field_hashes' => $hashes,
            'content_sha256' => $include_content ? hash('sha256', (string) $post->post_content) : null,
        ];
        $encoded = wp_json_encode($material, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return hash('sha256', is_string($encoded) ? $encoded : '');
    }

    private static function value_hash($value): string
    {
        $encoded = wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        return hash('sha256', is_string($encoded) ? $encoded : 'null');
    }

    private static function touches_revisioned_fields(array $plan): bool
    {
        return !empty($plan['_content_patch'])
            || array_key_exists('title', $plan['_desired_fields'])
            || array_key_exists('excerpt', $plan['_desired_fields']);
    }

    private static function ensure_backup_revision(WP_Post $post): int
    {
        $state = self::revision_fields_hash($post);
        $saved = wp_save_post_revision((int) $post->ID);
        if (is_wp_error($saved)) {
            return 0;
        }
        if ((int) $saved > 0) {
            return (int) $saved;
        }
        $revisions = wp_get_post_revisions((int) $post->ID, ['order' => 'DESC', 'orderby' => 'ID']);
        if (!is_array($revisions)) {
            return 0;
        }
        foreach ($revisions as $revision) {
            if ($revision instanceof WP_Post && hash_equals($state, self::revision_fields_hash($revision))) {
                return (int) $revision->ID;
            }
        }
        return 0;
    }

    private static function revision_fields_hash(WP_Post $post): string
    {
        $encoded = wp_json_encode([
            'title_sha256' => hash('sha256', (string) $post->post_title),
            'excerpt_sha256' => hash('sha256', (string) $post->post_excerpt),
            'content_sha256' => hash('sha256', (string) $post->post_content),
        ], JSON_UNESCAPED_SLASHES);
        return hash('sha256', is_string($encoded) ? $encoded : '');
    }

    private static function operation_response(string $operation, $payload)
    {
        if (is_wp_error($payload)) {
            return $payload;
        }
        return rest_ensure_response([
            'ok' => true,
            'operation' => $operation,
            'status' => 200,
            'result' => [
                'ok' => true,
                'status' => 200,
                'data' => $payload,
            ],
            'side_effects' => (bool) ($payload['side_effects'] ?? false),
        ]);
    }
}
