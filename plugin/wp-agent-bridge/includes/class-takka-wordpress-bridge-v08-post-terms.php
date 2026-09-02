<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TakKa_WordPress_Bridge_V08_Post_Terms
{
    public static function list(array $params)
    {
        $context = self::context($params);
        if (is_wp_error($context)) return $context;
        $terms = wp_get_object_terms($context['post_id'], $context['taxonomy'], ['orderby' => 'term_id', 'order' => 'ASC']);
        if (is_wp_error($terms)) return $terms;
        return rest_ensure_response([
            'post_id' => $context['post_id'],
            'post_type' => $context['post_type'],
            'taxonomy' => $context['taxonomy'],
            'terms' => array_map([self::class, 'public_term'], $terms),
            'count' => count($terms),
        ]);
    }

    public static function preview(array $params)
    {
        $plan = self::plan($params);
        if (is_wp_error($plan)) return $plan;
        return rest_ensure_response($plan);
    }

    public static function apply(array $params)
    {
        if (empty($params['confirm'])) return new WP_Error('takka_bridge_confirmation_required', 'Post term changes require confirm=true.', ['status' => 400]);
        $plan = self::plan($params);
        if (is_wp_error($plan)) return $plan;
        $expected = isset($params['expected_impact_hash']) && is_string($params['expected_impact_hash']) ? trim($params['expected_impact_hash']) : '';
        if ($expected === '' || !hash_equals((string) $plan['impact_hash'], $expected)) {
            return new WP_Error('takka_bridge_post_term_impact_changed', 'Post term impact changed or expected_impact_hash is missing.', ['status' => 409, 'current_impact_hash' => $plan['impact_hash']]);
        }
        $result = wp_set_object_terms((int) $plan['post_id'], array_map('intval', $plan['after_term_ids']), (string) $plan['taxonomy'], false);
        if (is_wp_error($result)) return $result;
        $after = wp_get_object_terms((int) $plan['post_id'], (string) $plan['taxonomy'], ['fields' => 'ids']);
        if (is_wp_error($after)) return $after;
        $after = array_values(array_unique(array_map('intval', $after)));
        sort($after, SORT_NUMERIC);
        if ($after !== $plan['after_term_ids']) {
            return new WP_Error('takka_bridge_post_term_verify_failed', 'Assigned terms did not match the planned result.', ['status' => 500, 'actual_term_ids' => $after]);
        }
        return rest_ensure_response(['ok' => true] + $plan);
    }

    private static function plan(array $params)
    {
        $context = self::context($params);
        if (is_wp_error($context)) return $context;
        $operation = isset($params['operation']) && is_string($params['operation']) ? strtolower(trim($params['operation'])) : '';
        if (!in_array($operation, ['set', 'add', 'remove'], true)) {
            return new WP_Error('takka_bridge_post_term_operation', 'operation must be set, add, or remove.', ['status' => 400]);
        }
        $targets = self::resolve_targets($params, $context['taxonomy']);
        if (is_wp_error($targets)) return $targets;
        if ($operation !== 'set' && !$targets) return new WP_Error('takka_bridge_post_term_targets_required', 'add/remove requires at least one existing term.', ['status' => 400]);

        $before = wp_get_object_terms($context['post_id'], $context['taxonomy'], ['fields' => 'ids']);
        if (is_wp_error($before)) return $before;
        $before = array_values(array_unique(array_map('intval', $before)));
        sort($before, SORT_NUMERIC);
        $target_ids = array_values(array_unique(array_map('intval', wp_list_pluck($targets, 'term_id'))));
        sort($target_ids, SORT_NUMERIC);

        if ($operation === 'set') $after = $target_ids;
        elseif ($operation === 'add') $after = array_values(array_unique(array_merge($before, $target_ids)));
        else $after = array_values(array_diff($before, $target_ids));
        sort($after, SORT_NUMERIC);

        $impact_hash = hash('sha256', wp_json_encode([$context['post_id'], $context['taxonomy'], $operation, $before, $after]));
        return [
            'post_id' => $context['post_id'],
            'post_type' => $context['post_type'],
            'taxonomy' => $context['taxonomy'],
            'operation' => $operation,
            'requested_terms' => array_map([self::class, 'public_term'], $targets),
            'before_term_ids' => $before,
            'after_term_ids' => $after,
            'changed' => $before !== $after,
            'impact_hash' => $impact_hash,
        ];
    }

    private static function context(array $params)
    {
        $post_id = isset($params['post_id']) ? absint($params['post_id']) : 0;
        if ($post_id < 1) return new WP_Error('takka_bridge_post_id_required', 'post_id is required.', ['status' => 400]);
        $post = get_post($post_id);
        if (!$post) return new WP_Error('takka_bridge_post_not_found', 'Post was not found.', ['status' => 404]);
        if (!current_user_can('edit_post', $post_id)) return new WP_Error('takka_bridge_post_edit_forbidden', 'Connected user cannot edit this post.', ['status' => 403, 'post_id' => $post_id]);
        $taxonomy = isset($params['taxonomy']) && is_string($params['taxonomy']) ? sanitize_key($params['taxonomy']) : '';
        if ($taxonomy === '' || !taxonomy_exists($taxonomy)) return new WP_Error('takka_bridge_taxonomy_not_found', 'Taxonomy was not found.', ['status' => 404, 'taxonomy' => $taxonomy]);
        $tax = get_taxonomy($taxonomy);
        if (!$tax || !in_array($post->post_type, (array) $tax->object_type, true)) return new WP_Error('takka_bridge_taxonomy_post_type', 'Taxonomy is not registered for this post type.', ['status' => 409]);
        $assign_cap = isset($tax->cap->assign_terms) ? $tax->cap->assign_terms : 'edit_posts';
        if (!current_user_can($assign_cap)) return new WP_Error('takka_bridge_taxonomy_assign_forbidden', 'Connected user cannot assign terms in this taxonomy.', ['status' => 403, 'capability' => $assign_cap]);
        return ['post_id' => $post_id, 'post_type' => $post->post_type, 'taxonomy' => $taxonomy];
    }

    private static function resolve_targets(array $params, string $taxonomy)
    {
        if (!array_key_exists('terms', $params)) return [];
        $terms = $params['terms'];
        if (!is_array($terms)) return new WP_Error('takka_bridge_post_terms_array', 'terms must be an array.', ['status' => 400]);
        if (count($terms) > 200) return new WP_Error('takka_bridge_post_terms_limit', 'Too many terms in one operation.', ['status' => 413]);
        $by = isset($params['by']) && is_string($params['by']) ? strtolower(trim($params['by'])) : 'slug';
        if (!in_array($by, ['slug', 'id'], true)) return new WP_Error('takka_bridge_post_terms_by', 'by must be slug or id.', ['status' => 400]);
        $out = [];
        foreach ($terms as $selector) {
            if ($by === 'id') {
                $id = absint($selector);
                $term = $id > 0 ? get_term($id, $taxonomy) : false;
            } else {
                if (!is_string($selector) || trim($selector) === '') return new WP_Error('takka_bridge_post_term_slug', 'Term slugs must be non-empty strings.', ['status' => 400]);
                $term = get_term_by('slug', sanitize_title($selector), $taxonomy);
            }
            if (!$term || is_wp_error($term)) return new WP_Error('takka_bridge_post_term_not_found', 'A requested term does not exist. Terms are never created implicitly.', ['status' => 404, 'selector' => $selector, 'by' => $by, 'taxonomy' => $taxonomy]);
            $out[(int) $term->term_id] = $term;
        }
        ksort($out, SORT_NUMERIC);
        return array_values($out);
    }

    public static function public_term($term): array
    {
        return [
            'id' => (int) $term->term_id,
            'name' => $term->name,
            'slug' => $term->slug,
            'taxonomy' => $term->taxonomy,
            'parent' => (int) $term->parent,
        ];
    }
}
