<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TakKa_WordPress_Bridge_V07_Terms
{
    public static function list(array $params)
    {
        $taxonomy = self::taxonomy($params);
        if (is_wp_error($taxonomy)) return $taxonomy;
        $args = [
            'taxonomy' => $taxonomy->name,
            'hide_empty' => !isset($params['hide_empty']) || !empty($params['hide_empty']),
            'number' => max(1, min(500, (int) ($params['number'] ?? 100))),
            'orderby' => 'term_id', 'order' => 'ASC',
        ];
        if (!empty($params['search']) && is_string($params['search'])) $args['search'] = trim($params['search']);
        $terms = get_terms($args);
        if (is_wp_error($terms)) return $terms;
        return rest_ensure_response(['taxonomy' => $taxonomy->name, 'terms' => array_map([self::class, 'data'], $terms), 'count' => count($terms)]);
    }

    public static function get(array $params)
    {
        $taxonomy = self::taxonomy($params);
        if (is_wp_error($taxonomy)) return $taxonomy;
        $term = self::resolve($taxonomy->name, $params);
        return is_wp_error($term) ? $term : rest_ensure_response(self::data($term));
    }

    public static function create(array $params)
    {
        if (empty($params['confirm'])) return self::confirm_error('Term creation requires confirm=true.');
        $taxonomy = self::taxonomy($params);
        if (is_wp_error($taxonomy)) return $taxonomy;
        if (!current_user_can($taxonomy->cap->manage_terms)) return self::forbidden();
        $name = isset($params['name']) && is_string($params['name']) ? trim(wp_strip_all_tags($params['name'])) : '';
        if ($name === '') return new WP_Error('takka_bridge_term_name_required', 'name is required.', ['status' => 400]);
        $args = [];
        if (isset($params['slug']) && is_string($params['slug'])) $args['slug'] = sanitize_title($params['slug']);
        if (isset($params['description']) && is_string($params['description'])) $args['description'] = sanitize_textarea_field($params['description']);
        if ($taxonomy->hierarchical && isset($params['parent'])) $args['parent'] = absint($params['parent']);
        $created = wp_insert_term($name, $taxonomy->name, $args);
        if (is_wp_error($created)) return $created;
        return rest_ensure_response(['ok' => true, 'term' => self::data(get_term((int) $created['term_id'], $taxonomy->name))]);
    }

    public static function update(array $params)
    {
        if (empty($params['confirm'])) return self::confirm_error('Term update requires confirm=true.');
        $taxonomy = self::taxonomy($params);
        if (is_wp_error($taxonomy)) return $taxonomy;
        if (!current_user_can($taxonomy->cap->manage_terms)) return self::forbidden();
        $term = self::resolve($taxonomy->name, $params);
        if (is_wp_error($term)) return $term;
        $args = [];
        if (isset($params['name']) && is_string($params['name'])) $args['name'] = trim(wp_strip_all_tags($params['name']));
        if (isset($params['slug']) && is_string($params['slug'])) $args['slug'] = sanitize_title($params['slug']);
        if (isset($params['description']) && is_string($params['description'])) $args['description'] = sanitize_textarea_field($params['description']);
        if ($taxonomy->hierarchical && isset($params['parent'])) {
            $parent = absint($params['parent']);
            if ($parent === (int) $term->term_id) return new WP_Error('takka_bridge_term_parent_self', 'A term cannot be its own parent.', ['status' => 400]);
            $args['parent'] = $parent;
        }
        if (!$args) return new WP_Error('takka_bridge_term_no_changes', 'No supported term fields were provided.', ['status' => 400]);
        $updated = wp_update_term((int) $term->term_id, $taxonomy->name, $args);
        if (is_wp_error($updated)) return $updated;
        return rest_ensure_response(['ok' => true, 'term' => self::data(get_term((int) $term->term_id, $taxonomy->name))]);
    }

    public static function delete_preview(array $params)
    {
        $taxonomy = self::taxonomy($params);
        if (is_wp_error($taxonomy)) return $taxonomy;
        $term = self::resolve($taxonomy->name, $params);
        if (is_wp_error($term)) return $term;
        return rest_ensure_response(self::impact($taxonomy, $term));
    }

    public static function delete(array $params)
    {
        if (empty($params['confirm'])) return self::confirm_error('Term deletion requires confirm=true.');
        $taxonomy = self::taxonomy($params);
        if (is_wp_error($taxonomy)) return $taxonomy;
        if (!current_user_can($taxonomy->cap->delete_terms)) return self::forbidden();
        $term = self::resolve($taxonomy->name, $params);
        if (is_wp_error($term)) return $term;
        $impact = self::impact($taxonomy, $term);
        if (!empty($params['expected_impact_hash']) && is_string($params['expected_impact_hash']) && !hash_equals($impact['impact_hash'], strtolower(trim($params['expected_impact_hash'])))) {
            return new WP_Error('takka_bridge_term_delete_impact_changed', 'Term deletion impact changed after preview.', ['status' => 409, 'current_impact' => $impact]);
        }
        $deleted = wp_delete_term((int) $term->term_id, $taxonomy->name);
        if (is_wp_error($deleted)) return $deleted;
        return rest_ensure_response(['ok' => (bool) $deleted, 'deleted_term' => self::data($term), 'impact' => $impact]);
    }

    private static function taxonomy(array $params)
    {
        $name = isset($params['taxonomy']) && is_string($params['taxonomy']) ? sanitize_key($params['taxonomy']) : '';
        if ($name === '') return new WP_Error('takka_bridge_taxonomy_required', 'taxonomy is required.', ['status' => 400]);
        $taxonomy = get_taxonomy($name);
        if (!$taxonomy) return new WP_Error('takka_bridge_taxonomy_not_found', 'Taxonomy was not found.', ['status' => 404]);
        if (empty($taxonomy->show_ui) && empty($taxonomy->public)) return new WP_Error('takka_bridge_taxonomy_internal_blocked', 'Internal/private taxonomies are blocked.', ['status' => 403]);
        return $taxonomy;
    }

    private static function resolve(string $taxonomy, array $params)
    {
        $term = isset($params['term_id']) ? get_term(absint($params['term_id']), $taxonomy) : (!empty($params['slug']) && is_string($params['slug']) ? get_term_by('slug', sanitize_title($params['slug']), $taxonomy) : false);
        if (!$term || is_wp_error($term)) return is_wp_error($term) ? $term : new WP_Error('takka_bridge_term_not_found', 'Term was not found.', ['status' => 404]);
        return $term;
    }

    public static function data(WP_Term $term): array
    {
        return ['id' => (int) $term->term_id, 'taxonomy' => $term->taxonomy, 'name' => $term->name, 'slug' => $term->slug, 'description' => $term->description, 'parent' => (int) $term->parent, 'count' => (int) $term->count];
    }

    private static function impact($taxonomy, WP_Term $term): array
    {
        $children = get_terms(['taxonomy' => $taxonomy->name, 'hide_empty' => false, 'parent' => (int) $term->term_id, 'fields' => 'ids']);
        if (is_wp_error($children)) $children = [];
        $impact = ['term' => self::data($term), 'assigned_object_count' => (int) $term->count, 'direct_child_term_count' => count($children)];
        $impact['impact_hash'] = hash('sha256', wp_json_encode($impact, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $impact;
    }

    private static function confirm_error(string $message): WP_Error { return new WP_Error('takka_bridge_confirmation_required', $message, ['status' => 400]); }
    private static function forbidden(): WP_Error { return new WP_Error('takka_bridge_term_forbidden', 'Bridge user cannot manage this taxonomy.', ['status' => 403]); }
}
