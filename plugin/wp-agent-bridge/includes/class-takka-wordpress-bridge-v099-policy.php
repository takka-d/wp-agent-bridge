<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Deterministic policy layer for the v0.9.9 operation router.
 *
 * The router intentionally exposes a small high-level vocabulary. This policy
 * removes several failure modes observed in real ChatGPT sessions before the
 * operation reaches the lower-level Bridge surfaces:
 *
 * - metadata reads do not accidentally return an entire post body;
 * - post content cannot be overwritten through the generic field updater;
 * - the inline-media operation is kept on the small-file path and may verify
 *   caller-supplied byte/SHA-256 expectations before WordPress sees the file;
 * - readonly.batch accepts the same high-level operation names used elsewhere
 *   instead of requiring callers to remember a second set of dotted action names.
 */
final class TakKa_WordPress_Bridge_V099_Policy
{
    private const ROUTE = '/takka-v099/v1/operate';
    private const INLINE_PREFERRED_MAX_BYTES = 1048576;

    private const POST_DEFAULT_FIELDS = [
        'id', 'date', 'date_gmt', 'modified', 'modified_gmt', 'slug', 'status',
        'type', 'link', 'title', 'excerpt', 'author', 'featured_media',
        'comment_status', 'ping_status', 'sticky', 'template', 'format',
        'categories', 'tags', 'meta',
    ];

    /** High-level operation name => strict v0.9.8 read-only action. */
    private const READONLY_ALIASES = [
        'plugin.list' => 'plugin.list',
        'self_update.status' => 'bridge.self_update.status',
        'post.content.inspect' => 'post.content.inspect',
        'post.content.search' => 'post.content.search',
        'post.content.read_range' => 'post.content.read.range',
        'theme.files.list' => 'theme.files.list',
        'theme.files.search' => 'theme.files.search',
        'theme.file.read_many' => 'theme.file.read.many',
        'theme.file.outline' => 'theme.file.outline',
        'theme.file.read_range' => 'theme.file.read.range',
        'page.html.inspect' => 'page.html.inspect',
        'media.inspect' => 'media.file.inspect',
        'site.icon.get' => 'site.icon.get',
        'media.upload.capabilities' => 'media.upload.capabilities',
        'workspace.list' => 'workspace.list',
        'workspace.file.get' => 'workspace.file.get',
        'workspace.file.read_range' => 'workspace.file.read.range',
        'workspace.file.search' => 'workspace.file.search',
        'workspace.file.diff' => 'workspace.file.diff',
        'workspace.snapshot.list' => 'workspace.snapshot.list',
    ];

    public static function init(): void
    {
        add_filter('rest_request_before_callbacks', [self::class, 'apply'], 71, 3);
    }

    public static function apply($response, array $handler, WP_REST_Request $request)
    {
        if ($response !== null
            || $request->get_route() !== self::ROUTE
            || strtoupper($request->get_method()) !== 'POST') {
            return $response;
        }
        // The v0.9.9 permission callback has already authenticated the signed
        // outer Bridge request and restored the configured administrator user.
        if (!current_user_can('manage_options')) {
            return $response;
        }

        $raw = (string) $request->get_body();
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return $response;
        }
        $operation = is_string($json['operation'] ?? null) ? trim($json['operation']) : '';
        $params = isset($json['params']) && is_array($json['params']) ? $json['params'] : [];

        if ($operation === 'post.get') {
            $normalized = self::normalize_post_get($params);
            if (is_wp_error($normalized)) return $normalized;
            $json['params'] = $normalized;
            return self::replace_json_body($request, $json, $response);
        }

        if ($operation === 'post.update') {
            $fields = isset($params['fields']) && is_array($params['fields']) ? $params['fields'] : [];
            if (array_key_exists('content', $fields)) {
                return new WP_Error(
                    'wpab_v099_content_requires_guarded_patch',
                    'post.update cannot replace post content. Use post.content.patch_preview then post.content.patch_apply.',
                    ['status' => 400]
                );
            }
            return $response;
        }

        if ($operation === 'media.upload.inline') {
            $verified = self::verify_inline_media($params);
            if (is_wp_error($verified)) return $verified;
            return $response;
        }

        if ($operation === 'readonly.batch') {
            $normalized = self::normalize_readonly_batch($params);
            if (is_wp_error($normalized)) return $normalized;
            $json['params'] = $normalized;
            return self::replace_json_body($request, $json, $response);
        }

        return $response;
    }

    private static function normalize_post_get(array $params)
    {
        $query = [];
        if (array_key_exists('query', $params)) {
            if (!is_array($params['query'])) {
                return new WP_Error('wpab_v099_post_get_query', 'post.get query must be an object.', ['status' => 400]);
            }
            $query = $params['query'];
        }

        if (!array_key_exists('_fields', $query)) {
            $query['_fields'] = implode(',', self::POST_DEFAULT_FIELDS);
        } elseif (self::fields_include_content($query['_fields'])) {
            return new WP_Error(
                'wpab_v099_post_get_content_blocked',
                'post.get is metadata-oriented and does not return post content. Use post.content.search or post.content.read_range.',
                ['status' => 400]
            );
        }
        $params['query'] = $query;
        return $params;
    }

    private static function fields_include_content($fields): bool
    {
        if (is_string($fields)) {
            $parts = preg_split('/\s*,\s*/', trim($fields));
        } elseif (is_array($fields)) {
            $parts = $fields;
        } else {
            return false;
        }
        foreach ($parts as $part) {
            if (is_string($part) && trim($part) === 'content') return true;
        }
        return false;
    }

    private static function verify_inline_media(array $params)
    {
        if (!isset($params['data_b64']) || !is_string($params['data_b64']) || trim($params['data_b64']) === '') {
            return new WP_Error('wpab_v099_inline_media_data', 'media.upload.inline requires data_b64.', ['status' => 400]);
        }
        $binary = base64_decode(trim($params['data_b64']), true);
        if (!is_string($binary)) {
            return new WP_Error('wpab_v099_inline_media_base64', 'media.upload.inline data_b64 is invalid Base64.', ['status' => 400]);
        }
        $bytes = strlen($binary);
        if ($bytes < 1) {
            return new WP_Error('wpab_v099_inline_media_empty', 'media.upload.inline decoded media is empty.', ['status' => 400]);
        }
        if ($bytes > self::INLINE_PREFERRED_MAX_BYTES) {
            return new WP_Error(
                'wpab_v099_inline_media_use_staged',
                'Decoded media exceeds the deterministic inline threshold. Use staged Media Fast Path.',
                ['status' => 413, 'bytes' => $bytes, 'inline_max_bytes' => self::INLINE_PREFERRED_MAX_BYTES]
            );
        }
        if (isset($params['expected_bytes']) && (int) $params['expected_bytes'] !== $bytes) {
            return new WP_Error(
                'wpab_v099_inline_media_bytes_mismatch',
                'Decoded media byte count does not match expected_bytes.',
                ['status' => 409, 'expected_bytes' => (int) $params['expected_bytes'], 'actual_bytes' => $bytes]
            );
        }
        if (isset($params['expected_sha256']) && is_string($params['expected_sha256']) && trim($params['expected_sha256']) !== '') {
            $expected = strtolower(trim($params['expected_sha256']));
            $actual = hash('sha256', $binary);
            if (!preg_match('/^[a-f0-9]{64}$/', $expected)) {
                return new WP_Error('wpab_v099_inline_media_sha_format', 'expected_sha256 must be a 64-character hex SHA-256.', ['status' => 400]);
            }
            if (!hash_equals($expected, $actual)) {
                return new WP_Error(
                    'wpab_v099_inline_media_sha_mismatch',
                    'Decoded media SHA-256 does not match expected_sha256.',
                    ['status' => 409, 'expected_sha256' => $expected, 'actual_sha256' => $actual]
                );
            }
        }
        return true;
    }

    private static function normalize_readonly_batch(array $params)
    {
        if (!isset($params['operations']) || !is_array($params['operations'])) {
            return $params;
        }
        $normalized = [];
        foreach (array_values($params['operations']) as $index => $operation) {
            if (!is_array($operation)) {
                return new WP_Error('wpab_v099_batch_operation', 'Each readonly.batch operation must be an object.', ['status' => 400, 'index' => $index]);
            }
            $name = '';
            if (isset($operation['operation']) && is_string($operation['operation'])) {
                $name = trim($operation['operation']);
            } elseif (isset($operation['action']) && is_string($operation['action'])) {
                $name = trim($operation['action']);
            }
            if ($name === '') {
                return new WP_Error('wpab_v099_batch_name', 'Each readonly.batch item requires operation or action.', ['status' => 400, 'index' => $index]);
            }

            $action = self::READONLY_ALIASES[$name] ?? null;
            if ($action === null && in_array($name, self::READONLY_ALIASES, true)) {
                // Already using the strict lower-level action name.
                $action = $name;
            }
            if ($action === null) {
                return new WP_Error(
                    'wpab_v099_batch_unsupported',
                    'readonly.batch item is not on the deterministic read-only operation map.',
                    ['status' => 400, 'index' => $index, 'operation' => $name, 'supported' => array_keys(self::READONLY_ALIASES)]
                );
            }

            $item = [
                'action' => $action,
                'params' => isset($operation['params']) && is_array($operation['params']) ? $operation['params'] : [],
            ];
            if (isset($operation['label']) && is_string($operation['label'])) {
                $item['label'] = $operation['label'];
            }
            $normalized[] = $item;
        }
        $params['operations'] = $normalized;
        return $params;
    }

    private static function replace_json_body(WP_REST_Request $request, array $json, $response)
    {
        $encoded = wp_json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            return new WP_Error('wpab_v099_policy_json', 'Could not encode normalized operation request.', ['status' => 500]);
        }
        $request->set_body($encoded);
        $request->set_header('Content-Type', 'application/json');

        // WordPress may already have parsed the JSON body while validating route
        // arguments before this filter runs. set_body() does not invalidate that
        // cached JSON parameter bag, so keep the parsed request parameters in sync
        // with the normalized body as well. Without this, dispatch() can see the
        // pre-policy params even though get_body() shows the rewritten request.
        if (method_exists($request, 'set_param')) {
            if (array_key_exists('operation', $json)) {
                $request->set_param('operation', $json['operation']);
            }
            if (array_key_exists('params', $json)) {
                $request->set_param('params', $json['params']);
            }
        }
        return $response;
    }
}
