<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Field-level optimistic concurrency for deterministic post.update operations.
 *
 * The existing post.update operation is a partial REST update, so unrelated
 * fields normally coexist. The missing piece was compare-and-swap protection
 * when two independent clients changed the same metadata field from an older
 * read. This layer exposes stable field hashes on post.get/post.update and, when
 * callers send those hashes back, rejects stale overlapping writes before the
 * WordPress REST callback runs.
 *
 * Compatibility: callers that do not yet send a concurrency guard continue to
 * work, but responses explicitly report that the update was unguarded. New
 * clients should prefer expected_field_hashes. Whole-state and modified_gmt
 * guards remain available for fields that cannot be represented safely by the
 * bounded field snapshot.
 */
final class WP_Agent_Bridge_Post_Update_Concurrency
{
    private const ROUTE = '/wpab-v099/v1/operate';
    private const HEALTH = '/wp-agent-bridge/v1/health';
    private const VERSION = 1;

    private const SUPPORTED_FIELDS = [
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
        'date',
        'date_gmt',
    ];

    /** @var array<int,array<string,mixed>> */
    private static $guard_results = [];

    public static function init(): void
    {
        // V099_Policy runs at 71 and may normalize the request first. This runs
        // after permission has succeeded but before the operation callback.
        add_filter('rest_request_before_callbacks', [self::class, 'guard_update'], 72, 3);
        add_filter('rest_request_after_callbacks', [self::class, 'annotate_operation'], 72, 3);
        add_filter('rest_request_after_callbacks', [self::class, 'annotate_health'], 645, 3);
    }

    public static function guard_update($response, array $handler, WP_REST_Request $request)
    {
        if ($response !== null
            || $request->get_route() !== self::ROUTE
            || strtoupper($request->get_method()) !== 'POST'
            || !current_user_can('manage_options')) {
            return $response;
        }

        $operation = self::request_operation($request);
        if ($operation !== 'post.update') {
            return $response;
        }
        $params = self::request_params($request);
        $fields = isset($params['fields']) && is_array($params['fields']) ? $params['fields'] : [];
        if (!$fields || array_key_exists('content', $fields)) {
            // Existing deterministic policy owns malformed/content errors.
            return $response;
        }

        $has_field_guard = array_key_exists('expected_field_hashes', $params);
        $has_state_guard = array_key_exists('expected_state_hash', $params);
        $has_modified_guard = array_key_exists('expected_modified_gmt', $params);
        $request_key = spl_object_id($request);

        if (!$has_field_guard && !$has_state_guard && !$has_modified_guard) {
            self::$guard_results[$request_key] = [
                'used' => false,
                'mode' => 'none',
                'safe_for_overlapping_multi_client_updates' => false,
            ];
            return $response;
        }

        $post_id = self::strict_post_id($params['post_id'] ?? null);
        if ($post_id < 1) {
            return new WP_Error(
                'wpab_post_update_concurrency_post_id',
                'Guarded post.update requires the verified post_id returned by post.get. Keep target_url too when the user supplied one.',
                ['status' => 400, 'side_effects' => false]
            );
        }
        $post = get_post($post_id);
        if (!$post || !in_array($post->post_type, ['post', 'page'], true)) {
            return new WP_Error('wpab_post_update_concurrency_missing', 'The guarded post or page no longer exists.', [
                'status' => 404,
                'post_id' => $post_id,
                'side_effects' => false,
            ]);
        }
        if (!current_user_can('edit_post', $post_id)) {
            return new WP_Error('wpab_post_update_concurrency_forbidden', 'Connected user cannot edit this post or page.', [
                'status' => 403,
                'post_id' => $post_id,
                'side_effects' => false,
            ]);
        }

        $snapshot = self::snapshot($post);
        $expected_state = self::optional_sha($params, 'expected_state_hash');
        if (is_wp_error($expected_state)) {
            return $expected_state;
        }
        $expected_modified = self::optional_modified($params);
        if (is_wp_error($expected_modified)) {
            return $expected_modified;
        }

        if ($has_field_guard) {
            $expected_fields = $params['expected_field_hashes'];
            if (!is_array($expected_fields) || !$expected_fields) {
                return new WP_Error('wpab_post_update_field_hashes', 'expected_field_hashes must be a non-empty object.', [
                    'status' => 400,
                    'side_effects' => false,
                ]);
            }

            $validated = [];
            foreach ($expected_fields as $field => $hash) {
                if (!is_string($field) || !in_array($field, self::SUPPORTED_FIELDS, true)) {
                    return new WP_Error('wpab_post_update_field_hash_name', 'expected_field_hashes contains an unsupported field.', [
                        'status' => 400,
                        'field' => $field,
                        'supported_fields' => self::SUPPORTED_FIELDS,
                        'side_effects' => false,
                    ]);
                }
                if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', strtolower(trim($hash)))) {
                    return new WP_Error('wpab_post_update_field_hash_format', 'Each expected field hash must be a SHA-256 hex digest.', [
                        'status' => 400,
                        'field' => $field,
                        'side_effects' => false,
                    ]);
                }
                $validated[$field] = strtolower(trim($hash));
            }

            $changed_supported = [];
            $unsupported_changed = [];
            foreach (array_keys($fields) as $field) {
                if (in_array($field, self::SUPPORTED_FIELDS, true)) {
                    $changed_supported[] = $field;
                } else {
                    $unsupported_changed[] = $field;
                }
            }
            $missing = array_values(array_diff($changed_supported, array_keys($validated)));
            if ($missing) {
                return new WP_Error(
                    'wpab_post_update_field_hash_missing',
                    'Field-level concurrency requires a hash for every supported field being changed.',
                    [
                        'status' => 400,
                        'missing_fields' => $missing,
                        'side_effects' => false,
                    ]
                );
            }

            $conflicts = [];
            foreach ($validated as $field => $expected_hash) {
                $actual_hash = (string) ($snapshot['field_hashes'][$field] ?? '');
                if ($actual_hash === '' || !hash_equals($expected_hash, $actual_hash)) {
                    $conflicts[] = [
                        'field' => $field,
                        'expected_sha256' => $expected_hash,
                        'current_sha256' => $actual_hash !== '' ? $actual_hash : null,
                    ];
                }
            }
            if ($conflicts) {
                return new WP_Error(
                    'wpab_post_update_field_conflict',
                    'One or more guarded metadata fields changed after the client read them. No update was attempted.',
                    [
                        'status' => 409,
                        'post_id' => $post_id,
                        'conflicting_fields' => $conflicts,
                        'current_modified_gmt' => $snapshot['modified_gmt'],
                        'current_state_hash' => $snapshot['state_hash'],
                        'side_effects' => false,
                    ]
                );
            }

            // Unknown/complex REST fields (for example registered meta) are not
            // hashed individually because doing so could expose protected data.
            // They need one coarse precondition in addition to field guards.
            if ($unsupported_changed) {
                $coarse = self::verify_coarse_guard($snapshot, $expected_state, $expected_modified);
                if (is_wp_error($coarse)) {
                    $data = $coarse->get_error_data();
                    if (is_array($data)) {
                        $data['unsupported_changed_fields'] = $unsupported_changed;
                        $data['side_effects'] = false;
                        $coarse->add_data($data);
                    }
                    return $coarse;
                }
                if ($coarse === 'none') {
                    return new WP_Error(
                        'wpab_post_update_unsupported_field_guard',
                        'Fields outside the bounded metadata snapshot require expected_state_hash or expected_modified_gmt in addition to expected_field_hashes.',
                        [
                            'status' => 400,
                            'unsupported_changed_fields' => $unsupported_changed,
                            'side_effects' => false,
                        ]
                    );
                }
            }

            self::$guard_results[$request_key] = [
                'used' => true,
                'mode' => 'field_hashes',
                'verified_fields' => array_values(array_keys($validated)),
                'unsupported_changed_fields' => $unsupported_changed,
                'allows_non_overlapping_concurrent_updates' => true,
            ];
            return $response;
        }

        $coarse = self::verify_coarse_guard($snapshot, $expected_state, $expected_modified);
        if (is_wp_error($coarse)) {
            return $coarse;
        }
        self::$guard_results[$request_key] = [
            'used' => true,
            'mode' => $coarse,
            'verified_fields' => [],
            'allows_non_overlapping_concurrent_updates' => false,
        ];
        return $response;
    }

    public static function annotate_operation($response, array $handler, WP_REST_Request $request)
    {
        if ($request->get_route() !== self::ROUTE || is_wp_error($response)) {
            return $response;
        }
        $rest = rest_ensure_response($response);
        $data = $rest->get_data();
        if (!is_array($data)) {
            return $response;
        }

        $operation = self::request_operation($request);
        if ($operation === 'catalog') {
            $data['post_update_concurrency'] = self::contract();
            $rest->set_data($data);
            return $rest;
        }
        if (!in_array($operation, ['post.get', 'post.update'], true)) {
            return $response;
        }

        $params = self::request_params($request);
        $post_id = self::response_post_id($data);
        if ($post_id < 1) {
            $post_id = self::strict_post_id($params['post_id'] ?? null);
        }
        $post = $post_id > 0 ? get_post($post_id) : null;
        if ($post && in_array($post->post_type, ['post', 'page'], true) && current_user_can('edit_post', $post_id)) {
            $data['concurrency'] = self::snapshot($post);
        }
        if ($operation === 'post.update') {
            $key = spl_object_id($request);
            $data['concurrency_guard'] = self::$guard_results[$key] ?? [
                'used' => false,
                'mode' => 'none',
                'safe_for_overlapping_multi_client_updates' => false,
            ];
            unset(self::$guard_results[$key]);
        }
        $rest->set_data($data);
        return $rest;
    }

    public static function annotate_health($response, array $handler, WP_REST_Request $request)
    {
        if ($request->get_route() !== self::HEALTH || is_wp_error($response)) {
            return $response;
        }
        $rest = rest_ensure_response($response);
        $data = $rest->get_data();
        if (!is_array($data)) {
            return $response;
        }
        $features = isset($data['features']) && is_array($data['features']) ? $data['features'] : [];
        foreach (['post_metadata_optimistic_concurrency', 'post_update_field_compare_and_swap'] as $feature) {
            if (!in_array($feature, $features, true)) {
                $features[] = $feature;
            }
        }
        $data['features'] = $features;
        $data['post_update_concurrency'] = self::contract();
        $rest->set_data($data);
        return $rest;
    }

    private static function contract(): array
    {
        return [
            'version' => self::VERSION,
            'operation' => 'post.update',
            'post_get_returns_concurrency_snapshot' => true,
            'preferred_guard' => 'expected_field_hashes',
            'preferred_flow' => ['post.get', 'post.update with expected_field_hashes from post.get.concurrency'],
            'field_level_compare_and_swap' => true,
            'allows_non_overlapping_concurrent_updates' => true,
            'same_field_stale_update_status' => 409,
            'fallback_guards' => ['expected_state_hash', 'expected_modified_gmt'],
            'unsupported_changed_fields_require_coarse_guard' => true,
            'unguarded_update_backward_compatible' => true,
            'supported_fields' => self::SUPPORTED_FIELDS,
        ];
    }

    private static function verify_coarse_guard(array $snapshot, string $expected_state, string $expected_modified)
    {
        if ($expected_state !== '') {
            if (!hash_equals($expected_state, (string) $snapshot['state_hash'])) {
                return new WP_Error(
                    'wpab_post_update_state_conflict',
                    'Post metadata state changed after it was read. No update was attempted.',
                    [
                        'status' => 409,
                        'current_state_hash' => $snapshot['state_hash'],
                        'current_modified_gmt' => $snapshot['modified_gmt'],
                        'side_effects' => false,
                    ]
                );
            }
            return 'state_hash';
        }
        if ($expected_modified !== '') {
            if (!hash_equals($expected_modified, (string) $snapshot['modified_gmt'])) {
                return new WP_Error(
                    'wpab_post_update_modified_conflict',
                    'Post modified_gmt changed after it was read. No update was attempted.',
                    [
                        'status' => 409,
                        'expected_modified_gmt' => $expected_modified,
                        'current_modified_gmt' => $snapshot['modified_gmt'],
                        'current_state_hash' => $snapshot['state_hash'],
                        'side_effects' => false,
                    ]
                );
            }
            return 'modified_gmt';
        }
        return 'none';
    }

    private static function snapshot(WP_Post $post): array
    {
        $values = [
            'title' => (string) $post->post_title,
            'excerpt' => (string) $post->post_excerpt,
            'slug' => (string) $post->post_name,
            'status' => (string) $post->post_status,
            'author' => (int) $post->post_author,
            'featured_media' => (int) get_post_thumbnail_id($post->ID),
            'comment_status' => (string) $post->comment_status,
            'ping_status' => (string) $post->ping_status,
            'sticky' => $post->post_type === 'post' ? is_sticky($post->ID) : false,
            'template' => (string) (get_page_template_slug($post->ID) ?: ''),
            'format' => (string) (get_post_format($post->ID) ?: 'standard'),
            'categories' => self::term_ids($post->ID, 'category'),
            'tags' => self::term_ids($post->ID, 'post_tag'),
            'parent' => (int) $post->post_parent,
            'menu_order' => (int) $post->menu_order,
            'date' => (string) $post->post_date,
            'date_gmt' => (string) $post->post_date_gmt,
        ];
        $field_hashes = [];
        foreach (self::SUPPORTED_FIELDS as $field) {
            $field_hashes[$field] = self::value_hash($values[$field]);
        }
        ksort($field_hashes, SORT_STRING);
        $state_material = wp_json_encode([
            'post_id' => (int) $post->ID,
            'post_type' => (string) $post->post_type,
            'field_hashes' => $field_hashes,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return [
            'version' => self::VERSION,
            'mode' => 'field-hash-v1',
            'post_id' => (int) $post->ID,
            'post_type' => (string) $post->post_type,
            'modified_gmt' => (string) $post->post_modified_gmt,
            'state_hash' => hash('sha256', is_string($state_material) ? $state_material : ''),
            'field_hashes' => $field_hashes,
            'supported_fields' => self::SUPPORTED_FIELDS,
        ];
    }

    private static function term_ids(int $post_id, string $taxonomy): array
    {
        if (!taxonomy_exists($taxonomy)) {
            return [];
        }
        $ids = wp_get_post_terms($post_id, $taxonomy, ['fields' => 'ids']);
        if (is_wp_error($ids) || !is_array($ids)) {
            return [];
        }
        $ids = array_map('intval', $ids);
        sort($ids, SORT_NUMERIC);
        return array_values($ids);
    }

    private static function value_hash($value): string
    {
        $encoded = wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        return hash('sha256', is_string($encoded) ? $encoded : 'null');
    }

    private static function optional_sha(array $params, string $key)
    {
        if (!array_key_exists($key, $params) || $params[$key] === null || $params[$key] === '') {
            return '';
        }
        if (!is_string($params[$key])) {
            return new WP_Error('wpab_post_update_state_hash_format', $key . ' must be a SHA-256 hex digest.', [
                'status' => 400,
                'field' => $key,
                'side_effects' => false,
            ]);
        }
        $value = strtolower(trim($params[$key]));
        if (!preg_match('/^[a-f0-9]{64}$/D', $value)) {
            return new WP_Error('wpab_post_update_state_hash_format', $key . ' must be a SHA-256 hex digest.', [
                'status' => 400,
                'field' => $key,
                'side_effects' => false,
            ]);
        }
        return $value;
    }

    private static function optional_modified(array $params)
    {
        if (!array_key_exists('expected_modified_gmt', $params) || $params['expected_modified_gmt'] === null || $params['expected_modified_gmt'] === '') {
            return '';
        }
        if (!is_string($params['expected_modified_gmt'])) {
            return new WP_Error('wpab_post_update_modified_format', 'expected_modified_gmt must be a non-empty string.', [
                'status' => 400,
                'side_effects' => false,
            ]);
        }
        $value = trim($params['expected_modified_gmt']);
        if ($value === '' || strlen($value) > 64) {
            return new WP_Error('wpab_post_update_modified_format', 'expected_modified_gmt must be a non-empty string within 64 bytes.', [
                'status' => 400,
                'side_effects' => false,
            ]);
        }
        return $value;
    }

    private static function request_operation(WP_REST_Request $request): string
    {
        $operation = $request->get_param('operation');
        return is_string($operation) ? trim($operation) : '';
    }

    private static function request_params(WP_REST_Request $request): array
    {
        $params = $request->get_param('params');
        return is_array($params) ? $params : [];
    }

    private static function strict_post_id($value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value)) {
            $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            return $int === false ? 0 : (int) $int;
        }
        return 0;
    }

    private static function response_post_id(array $data): int
    {
        $candidates = [
            $data['result']['data']['data']['id'] ?? null,
            $data['result']['data']['id'] ?? null,
            $data['result']['id'] ?? null,
            $data['id'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            $id = self::strict_post_id($candidate);
            if ($id > 0) {
                return $id;
            }
        }
        return 0;
    }
}
