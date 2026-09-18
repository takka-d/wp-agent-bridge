<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Explicit, guarded WordPress revision read/restore operations on the existing
 * deterministic v0.9.9 route.
 *
 * Revisions only cover WordPress revision fields (title/content/excerpt). They
 * are not presented as a rollback mechanism for taxonomy, featured media or
 * arbitrary post meta.
 */
final class WP_Agent_Bridge_Post_Revisions
{
    private const ROUTE = '/wpab-v099/v1/operate';
    private const HEALTH = '/wp-agent-bridge/v1/health';
    private const MAX_REVISIONS = 50;
    private const MAX_CONTENT_BYTES = 4194304;

    private const OPERATIONS = [
        'post.revisions.list',
        'post.revisions.get',
        'post.revisions.restore',
    ];

    public static function init(): void
    {
        // V099 policy/concurrency filters run first. Permission has already been
        // granted by the deterministic route before this filter is invoked.
        add_filter('rest_request_before_callbacks', [self::class, 'dispatch'], 74, 3);
        add_filter('rest_request_after_callbacks', [self::class, 'annotate_contract'], 674, 3);
    }

    public static function dispatch($response, array $handler, WP_REST_Request $request)
    {
        if ($response !== null
            || $request->get_route() !== self::ROUTE
            || strtoupper($request->get_method()) !== 'POST'
            || !current_user_can('manage_options')) {
            return $response;
        }
        $json = $request->get_json_params();
        if (!is_array($json)) {
            return $response;
        }
        $operation = is_string($json['operation'] ?? null) ? trim($json['operation']) : '';
        if (!in_array($operation, self::OPERATIONS, true)) {
            return $response;
        }
        $params = isset($json['params']) && is_array($json['params']) ? $json['params'] : [];

        try {
            if ($operation === 'post.revisions.list') {
                return self::operation_response($operation, self::revision_list($params));
            }
            if ($operation === 'post.revisions.get') {
                return self::operation_response($operation, self::revision_get($params));
            }
            return self::operation_response($operation, self::revision_restore($params));
        } catch (Throwable $e) {
            return new WP_Error('wpab_post_revision_exception', $e->getMessage(), [
                'status' => 500,
                'side_effects' => false,
                'type' => get_class($e),
            ]);
        }
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

        if ($route === self::ROUTE) {
            $json = $request->get_json_params();
            if (is_array($json) && ($json['operation'] ?? '') === 'catalog') {
                $ops = isset($data['operations']) && is_array($data['operations']) ? $data['operations'] : [];
                foreach (self::OPERATIONS as $operation) {
                    if (!in_array($operation, $ops, true)) {
                        $ops[] = $operation;
                    }
                }
                $data['operations'] = $ops;
                $data['post_revisions'] = self::contract();
                $rest->set_data($data);
                return $rest;
            }
        }

        if ($route === self::HEALTH) {
            $features = isset($data['features']) && is_array($data['features']) ? $data['features'] : [];
            foreach (['post_revision_inspection', 'guarded_post_revision_restore'] as $feature) {
                if (!in_array($feature, $features, true)) {
                    $features[] = $feature;
                }
            }
            $data['features'] = $features;
            $data['post_revisions'] = self::contract();
            $rest->set_data($data);
            return $rest;
        }

        return $response;
    }

    public static function contract(): array
    {
        return [
            'operations' => self::OPERATIONS,
            'revisioned_fields' => ['title', 'content', 'excerpt'],
            'metadata_taxonomy_featured_media_restored' => false,
            'max_list' => self::MAX_REVISIONS,
            'max_content_bytes' => self::MAX_CONTENT_BYTES,
            'restore_requires' => ['confirm', 'expected_current_revision_fields_hash'],
            'non_draft_restore_requires_confirm_live' => true,
            'backup_before_restore_required' => true,
            'restore_verifies_revision_fields_hash' => true,
        ];
    }

    private static function revision_list(array $params)
    {
        $post_id = self::post_id($params);
        if (is_wp_error($post_id)) {
            return $post_id;
        }
        $post = self::editable_post($post_id);
        if (is_wp_error($post)) {
            return $post;
        }
        $limit = isset($params['limit']) ? (int) $params['limit'] : 20;
        if ($limit < 1 || $limit > self::MAX_REVISIONS) {
            return new WP_Error('wpab_post_revision_limit', 'limit must be between 1 and 50.', ['status' => 400, 'side_effects' => false]);
        }
        $include_autosaves = !empty($params['include_autosaves']);
        $revisions = wp_get_post_revisions($post_id, ['order' => 'DESC', 'orderby' => 'ID']);
        if (!is_array($revisions)) {
            $revisions = [];
        }
        usort($revisions, static function ($a, $b): int {
            return ((int) $b->ID) <=> ((int) $a->ID);
        });

        $items = [];
        foreach ($revisions as $revision) {
            $autosave = (bool) wp_is_post_autosave($revision);
            if ($autosave && !$include_autosaves) {
                continue;
            }
            $items[] = self::summary($revision);
            if (count($items) >= $limit) {
                break;
            }
        }
        $current = self::current_state($post);
        return [
            'post_id' => $post_id,
            'post_type' => (string) $post->post_type,
            'status' => (string) $post->post_status,
            'current_modified_gmt' => (string) $post->post_modified_gmt,
            'current_content_sha256' => $current['content_sha256'],
            'current_revision_fields_hash' => $current['revision_fields_hash'],
            'returned' => count($items),
            'limit' => $limit,
            'include_autosaves' => $include_autosaves,
            'revisions' => $items,
            'side_effects' => false,
        ];
    }

    private static function revision_get(array $params)
    {
        $revision = self::revision($params);
        if (is_wp_error($revision)) {
            return $revision;
        }
        $post = self::editable_post((int) $revision->post_parent);
        if (is_wp_error($post)) {
            return $post;
        }
        $asserted = self::optional_asserted_post_id($params);
        if (is_wp_error($asserted)) {
            return $asserted;
        }
        if ($asserted !== null && $asserted !== (int) $post->ID) {
            return new WP_Error('wpab_post_revision_parent_conflict', 'revision_id does not belong to the asserted post_id.', [
                'status' => 409,
                'post_id' => $asserted,
                'revision_post_id' => (int) $post->ID,
                'side_effects' => false,
            ]);
        }

        $item = self::summary($revision);
        $item['side_effects'] = false;
        if (!empty($params['include_content'])) {
            $content = (string) $revision->post_content;
            if (strlen($content) > self::MAX_CONTENT_BYTES) {
                return new WP_Error('wpab_post_revision_content_size', 'Revision content exceeds the bounded read limit.', [
                    'status' => 413,
                    'content_bytes' => strlen($content),
                    'max_content_bytes' => self::MAX_CONTENT_BYTES,
                    'side_effects' => false,
                ]);
            }
            $item['content'] = $content;
        }
        return $item;
    }

    private static function revision_restore(array $params)
    {
        if (empty($params['confirm'])) {
            return new WP_Error('wpab_post_revision_confirmation', 'Revision restore requires confirm=true.', ['status' => 400, 'side_effects' => false]);
        }
        $revision = self::revision($params);
        if (is_wp_error($revision)) {
            return $revision;
        }
        $post_id = (int) $revision->post_parent;
        $post = self::editable_post($post_id);
        if (is_wp_error($post)) {
            return $post;
        }
        $asserted = self::optional_asserted_post_id($params);
        if (is_wp_error($asserted)) {
            return $asserted;
        }
        if ($asserted !== null && $asserted !== $post_id) {
            return new WP_Error('wpab_post_revision_parent_conflict', 'revision_id does not belong to the asserted post_id.', [
                'status' => 409,
                'post_id' => $asserted,
                'revision_post_id' => $post_id,
                'side_effects' => false,
            ]);
        }
        if (!in_array((string) $post->post_status, ['draft', 'pending', 'auto-draft'], true) && empty($params['confirm_live'])) {
            return new WP_Error('wpab_post_revision_live_confirmation', 'Restoring a non-draft post requires confirm_live=true.', [
                'status' => 400,
                'status_value' => (string) $post->post_status,
                'side_effects' => false,
            ]);
        }

        $expected_state = isset($params['expected_current_revision_fields_hash']) && is_string($params['expected_current_revision_fields_hash'])
            ? strtolower(trim($params['expected_current_revision_fields_hash']))
            : '';
        if (!preg_match('/^[a-f0-9]{64}$/D', $expected_state)) {
            return new WP_Error('wpab_post_revision_state_guard', 'expected_current_revision_fields_hash must be a SHA-256 from post.revisions.list.', [
                'status' => 400,
                'side_effects' => false,
            ]);
        }
        $before = self::current_state($post);
        if (!hash_equals($expected_state, $before['revision_fields_hash'])) {
            return new WP_Error('wpab_post_revision_state_changed', 'Revisioned post fields changed after inspection; no restore was attempted.', [
                'status' => 409,
                'expected_current_revision_fields_hash' => $expected_state,
                'current_revision_fields_hash' => $before['revision_fields_hash'],
                'current_content_sha256' => $before['content_sha256'],
                'current_modified_gmt' => (string) $post->post_modified_gmt,
                'side_effects' => false,
            ]);
        }
        if (isset($params['expected_current_content_sha256'])) {
            $expected_content = is_string($params['expected_current_content_sha256'])
                ? strtolower(trim($params['expected_current_content_sha256']))
                : '';
            if (!preg_match('/^[a-f0-9]{64}$/D', $expected_content)) {
                return new WP_Error('wpab_post_revision_content_guard', 'expected_current_content_sha256 must be a SHA-256 digest.', ['status' => 400, 'side_effects' => false]);
            }
            if (!hash_equals($expected_content, $before['content_sha256'])) {
                return new WP_Error('wpab_post_revision_content_changed', 'Post content changed after inspection; no restore was attempted.', [
                    'status' => 409,
                    'expected_current_content_sha256' => $expected_content,
                    'current_content_sha256' => $before['content_sha256'],
                    'side_effects' => false,
                ]);
            }
        }
        if (isset($params['expected_current_modified_gmt'])) {
            $expected_modified = is_string($params['expected_current_modified_gmt']) ? trim($params['expected_current_modified_gmt']) : '';
            if ($expected_modified === '' || !hash_equals($expected_modified, (string) $post->post_modified_gmt)) {
                return new WP_Error('wpab_post_revision_modified_changed', 'Post modified_gmt changed after inspection; no restore was attempted.', [
                    'status' => 409,
                    'current_modified_gmt' => (string) $post->post_modified_gmt,
                    'side_effects' => false,
                ]);
            }
        }

        $target_state = self::current_state($revision);
        if (strlen((string) $revision->post_content) > self::MAX_CONTENT_BYTES) {
            return new WP_Error('wpab_post_revision_content_size', 'Target revision content exceeds the restore limit.', [
                'status' => 413,
                'content_bytes' => strlen((string) $revision->post_content),
                'side_effects' => false,
            ]);
        }
        if (hash_equals($before['revision_fields_hash'], $target_state['revision_fields_hash'])) {
            return new WP_Error('wpab_post_revision_no_change', 'The selected revision already matches current revisioned fields.', [
                'status' => 409,
                'side_effects' => false,
            ]);
        }

        $backup_revision_id = wp_save_post_revision($post_id);
        if (is_wp_error($backup_revision_id)) {
            return new WP_Error('wpab_post_revision_backup_failed', $backup_revision_id->get_error_message(), [
                'status' => 500,
                'side_effects' => false,
            ]);
        }
        $backup_revision_id = (int) $backup_revision_id;
        if ($backup_revision_id < 1) {
            $backup_revision_id = self::find_matching_revision($post_id, $before['revision_fields_hash']);
        }
        if ($backup_revision_id < 1) {
            return new WP_Error('wpab_post_revision_backup_unavailable', 'Could not establish a rollback revision for the current post; restore was blocked.', [
                'status' => 409,
                'side_effects' => false,
            ]);
        }

        // Re-check after backup creation. Saving a revision must not change the
        // current post fields, but this also catches an unexpected hook mutation.
        $post = get_post($post_id);
        if (!$post || !hash_equals($before['revision_fields_hash'], self::current_state($post)['revision_fields_hash'])) {
            return new WP_Error('wpab_post_revision_state_changed_during_backup', 'Current post changed while the rollback revision was being prepared.', [
                'status' => 409,
                'backup_revision_id' => $backup_revision_id,
                'side_effects' => false,
            ]);
        }

        $restored = wp_restore_post_revision((int) $revision->ID, ['post_title', 'post_content', 'post_excerpt']);
        if (!$restored || (int) $restored !== $post_id) {
            return new WP_Error('wpab_post_revision_restore_failed', 'WordPress did not restore the selected revision.', [
                'status' => 500,
                'backup_revision_id' => $backup_revision_id,
                'side_effects' => false,
            ]);
        }
        $after_post = get_post($post_id);
        if (!$after_post) {
            return new WP_Error('wpab_post_revision_post_missing_after_restore', 'Post disappeared after revision restore.', [
                'status' => 500,
                'backup_revision_id' => $backup_revision_id,
                'side_effects' => true,
            ]);
        }
        $after = self::current_state($after_post);
        if (!hash_equals($target_state['revision_fields_hash'], $after['revision_fields_hash'])) {
            return new WP_Error('wpab_post_revision_verify_failed', 'Restored revision fields do not match the selected revision.', [
                'status' => 500,
                'backup_revision_id' => $backup_revision_id,
                'expected_revision_fields_hash' => $target_state['revision_fields_hash'],
                'actual_revision_fields_hash' => $after['revision_fields_hash'],
                'side_effects' => true,
            ]);
        }

        return [
            'post_id' => $post_id,
            'target_revision_id' => (int) $revision->ID,
            'backup_revision_id' => $backup_revision_id,
            'restored_fields' => ['title', 'content', 'excerpt'],
            'before_revision_fields_hash' => $before['revision_fields_hash'],
            'after_revision_fields_hash' => $after['revision_fields_hash'],
            'before_content_sha256' => $before['content_sha256'],
            'after_content_sha256' => $after['content_sha256'],
            'modified_gmt' => (string) $after_post->post_modified_gmt,
            'side_effects' => true,
        ];
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

    private static function post_id(array $params)
    {
        $post_id = isset($params['post_id']) ? absint($params['post_id']) : 0;
        if ($post_id < 1) {
            return new WP_Error('wpab_post_revision_post_id', 'post_id is required.', ['status' => 400, 'side_effects' => false]);
        }
        return $post_id;
    }

    private static function optional_asserted_post_id(array $params)
    {
        if (!array_key_exists('post_id', $params)) {
            return null;
        }
        $post_id = absint($params['post_id']);
        if ($post_id < 1) {
            return new WP_Error('wpab_post_revision_post_id', 'post_id must be a positive integer.', ['status' => 400, 'side_effects' => false]);
        }
        return $post_id;
    }

    private static function editable_post(int $post_id)
    {
        $post = get_post($post_id);
        if (!$post || !in_array($post->post_type, ['post', 'page'], true) || $post->post_status === 'trash') {
            return new WP_Error('wpab_post_revision_post_missing', 'Post or page was not found.', ['status' => 404, 'side_effects' => false]);
        }
        if (!current_user_can('edit_post', $post_id)) {
            return new WP_Error('wpab_post_revision_forbidden', 'Connected user cannot edit this post or page.', ['status' => 403, 'side_effects' => false]);
        }
        return $post;
    }

    private static function revision(array $params)
    {
        $revision_id = isset($params['revision_id']) ? absint($params['revision_id']) : 0;
        if ($revision_id < 1) {
            return new WP_Error('wpab_post_revision_id', 'revision_id is required.', ['status' => 400, 'side_effects' => false]);
        }
        $revision = wp_get_post_revision($revision_id);
        if (!$revision || (int) $revision->post_parent < 1) {
            return new WP_Error('wpab_post_revision_missing', 'Revision was not found.', ['status' => 404, 'side_effects' => false]);
        }
        return $revision;
    }

    private static function summary(WP_Post $revision): array
    {
        $state = self::current_state($revision);
        return [
            'revision_id' => (int) $revision->ID,
            'post_id' => (int) $revision->post_parent,
            'author' => (int) $revision->post_author,
            'date_gmt' => (string) $revision->post_date_gmt,
            'modified_gmt' => (string) $revision->post_modified_gmt,
            'autosave' => (bool) wp_is_post_autosave($revision),
            'title_sha256' => hash('sha256', (string) $revision->post_title),
            'excerpt_sha256' => hash('sha256', (string) $revision->post_excerpt),
            'content_sha256' => $state['content_sha256'],
            'content_bytes' => strlen((string) $revision->post_content),
            'revision_fields_hash' => $state['revision_fields_hash'],
        ];
    }

    private static function current_state(WP_Post $post): array
    {
        $content_sha = hash('sha256', (string) $post->post_content);
        $material = wp_json_encode([
            'title_sha256' => hash('sha256', (string) $post->post_title),
            'excerpt_sha256' => hash('sha256', (string) $post->post_excerpt),
            'content_sha256' => $content_sha,
        ], JSON_UNESCAPED_SLASHES);
        return [
            'content_sha256' => $content_sha,
            'revision_fields_hash' => hash('sha256', is_string($material) ? $material : ''),
        ];
    }

    private static function find_matching_revision(int $post_id, string $state_hash): int
    {
        $revisions = wp_get_post_revisions($post_id, ['order' => 'DESC', 'orderby' => 'ID']);
        if (!is_array($revisions)) {
            return 0;
        }
        foreach ($revisions as $revision) {
            if ($revision instanceof WP_Post
                && hash_equals($state_hash, self::current_state($revision)['revision_fields_hash'])) {
                return (int) $revision->ID;
            }
        }
        return 0;
    }
}
