<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TakKa_WordPress_Bridge_V087_Table_Locator
{
    private const MAX_CONTENT_BYTES = 4194304;
    private const MAX_TABLES = 100;
    private const MAX_ROWS = 2000;
    private const MAX_CELLS = 100;
    private const MAX_RESULTS = 100;
    private const MAX_QUERY_CHARS = 4096;
    private const MAX_CELL_EXCERPT_CHARS = 240;

    public static function locate(array $params)
    {
        $context = self::context($params);
        if (is_wp_error($context)) return $context;

        $query = isset($params['query']) && is_string($params['query']) ? self::normalize_text($params['query']) : '';
        if ($query === '') {
            return new WP_Error('takka_bridge_table_locator_query', 'query must be a non-empty string.', ['status' => 400]);
        }
        if (self::text_length($query) > self::MAX_QUERY_CHARS) {
            return new WP_Error('takka_bridge_table_locator_query_size', 'query exceeds the locator size limit.', ['status' => 413]);
        }

        $match_mode = isset($params['match_mode']) && is_string($params['match_mode']) ? strtolower(trim($params['match_mode'])) : 'exact';
        if (!in_array($match_mode, ['exact', 'contains'], true)) {
            return new WP_Error('takka_bridge_table_locator_match_mode', 'match_mode must be exact or contains.', ['status' => 400]);
        }
        $case_sensitive = array_key_exists('case_sensitive', $params) ? !empty($params['case_sensitive']) : false;
        $result_limit = isset($params['limit']) ? max(1, min(self::MAX_RESULTS, (int) $params['limit'])) : 20;
        $include_row = !array_key_exists('include_row', $params) || !empty($params['include_row']);

        $post = $context['post'];
        $content = (string) $post->post_content;
        if (strlen($content) > self::MAX_CONTENT_BYTES) {
            return new WP_Error('takka_bridge_table_content_size', 'Post content is too large for Bridge table tooling.', ['status' => 413]);
        }

        $tables = self::parse_tables($content);
        if (is_wp_error($tables)) return $tables;

        $table_filter = array_key_exists('table_index', $params) ? (int) $params['table_index'] : null;
        if ($table_filter !== null && ($table_filter < 0 || $table_filter >= count($tables))) {
            return new WP_Error('takka_bridge_table_index', 'table_index is out of range.', ['status' => 404, 'table_count' => count($tables)]);
        }
        $column_filter = array_key_exists('column', $params) ? (int) $params['column'] : null;
        if ($column_filter !== null && ($column_filter < 0 || $column_filter >= self::MAX_CELLS)) {
            return new WP_Error('takka_bridge_table_locator_column', 'column is out of range.', ['status' => 400]);
        }

        $matches = [];
        $total = 0;
        foreach ($tables as $table_index => $table) {
            if ($table_filter !== null && $table_index !== $table_filter) continue;
            $rows = self::parse_rows($table['html']);
            if (is_wp_error($rows)) return $rows;
            foreach ($rows as $row_index => $row) {
                foreach ($row['cells'] as $column_index => $cell) {
                    if ($column_filter !== null && $column_index !== $column_filter) continue;
                    if (!self::text_matches($cell['text'], $query, $match_mode, $case_sensitive)) continue;
                    $total++;
                    if (count($matches) >= $result_limit) continue;

                    $item = [
                        'table_index' => $table_index,
                        'table_sha256' => hash('sha256', $table['html']),
                        'row_index' => $row_index,
                        'column_index' => $column_index,
                        'cell_count' => count($row['cells']),
                        'matched_text' => self::truncate_text($cell['text'], self::MAX_CELL_EXCERPT_CHARS),
                        'row_sha256' => hash('sha256', $row['html']),
                    ];
                    if ($include_row) {
                        $row_cells = [];
                        foreach ($row['cells'] as $row_cell) {
                            $row_cells[] = self::truncate_text($row_cell['text'], self::MAX_CELL_EXCERPT_CHARS);
                        }
                        $item['row_cells'] = $row_cells;
                    }
                    if (self::text_length($cell['text']) <= self::MAX_QUERY_CHARS) {
                        $item['edit_selector'] = [
                            'table_index' => $table_index,
                            'expected_table_sha256' => hash('sha256', $table['html']),
                            'row_key' => $cell['text'],
                            'key_column' => $column_index,
                            'case_sensitive' => true,
                        ];
                    }
                    $matches[] = $item;
                }
            }
        }

        return rest_ensure_response([
            'post_id' => (int) $post->ID,
            'post_type' => (string) $post->post_type,
            'status' => (string) $post->post_status,
            'modified_gmt' => (string) $post->post_modified_gmt,
            'content_sha256' => hash('sha256', $content),
            'table_count' => count($tables),
            'query_sha256' => hash('sha256', $query),
            'query_chars' => self::text_length($query),
            'match_mode' => $match_mode,
            'case_sensitive' => $case_sensitive,
            'total_matches' => $total,
            'returned' => count($matches),
            'truncated' => $total > count($matches),
            'unique' => $total === 1,
            'matches' => $matches,
        ]);
    }

    public static function smart_preview(array $params)
    {
        $resolved = self::resolve_smart_params($params);
        if (is_wp_error($resolved)) return $resolved;
        $response = TakKa_WordPress_Bridge_V086_Table::preview($resolved['params']);
        return self::decorate_smart_response($response, $resolved);
    }

    public static function smart_apply(array $params)
    {
        $resolved = self::resolve_smart_params($params);
        if (is_wp_error($resolved)) return $resolved;
        $response = TakKa_WordPress_Bridge_V086_Table::apply($resolved['params']);
        return self::decorate_smart_response($response, $resolved);
    }

    private static function resolve_smart_params(array $params)
    {
        $context = self::context($params);
        if (is_wp_error($context)) return $context;
        $post = $context['post'];
        $content = (string) $post->post_content;
        if (strlen($content) > self::MAX_CONTENT_BYTES) {
            return new WP_Error('takka_bridge_table_content_size', 'Post content is too large for Bridge table tooling.', ['status' => 413]);
        }

        $tables = self::parse_tables($content);
        if (is_wp_error($tables)) return $tables;
        $operations = isset($params['operations']) ? $params['operations'] : null;
        if (!is_array($operations) || !$operations) {
            return new WP_Error('takka_bridge_table_operations_required', 'operations must be a non-empty array.', ['status' => 400]);
        }

        $first = reset($operations);
        if (!is_array($first)) {
            return new WP_Error('takka_bridge_table_operation_object', 'Each table operation must be an object.', ['status' => 400, 'index' => 0]);
        }
        $first_key = isset($first['row_key']) && is_string($first['row_key']) ? self::normalize_text($first['row_key']) : '';
        if ($first_key === '') {
            return new WP_Error('takka_bridge_table_row_key', 'The first operation must contain a non-empty row_key.', ['status' => 400, 'index' => 0]);
        }

        $auto_table = !array_key_exists('table_index', $params);
        if ($auto_table) {
            $first_column = array_key_exists('key_column', $first) ? (int) $first['key_column'] : null;
            $first_case = array_key_exists('case_sensitive', $first) ? !empty($first['case_sensitive']) : true;
            $candidates = self::find_exact_matches($tables, $first_key, null, $first_column, $first_case);
            if (count($candidates) !== 1) {
                return self::ambiguous_error('Could not resolve a unique table from the first row_key.', 0, $candidates);
            }
            $table_index = (int) $candidates[0]['table_index'];
        } else {
            $table_index = (int) $params['table_index'];
            if ($table_index < 0 || $table_index >= count($tables)) {
                return new WP_Error('takka_bridge_table_index', 'table_index is out of range.', ['status' => 404, 'table_count' => count($tables)]);
            }
        }

        $resolved_operations = [];
        $resolved_columns = [];
        foreach (array_values($operations) as $index => $operation) {
            if (!is_array($operation)) {
                return new WP_Error('takka_bridge_table_operation_object', 'Each table operation must be an object.', ['status' => 400, 'index' => $index]);
            }
            $row_key = isset($operation['row_key']) && is_string($operation['row_key']) ? self::normalize_text($operation['row_key']) : '';
            if ($row_key === '') {
                return new WP_Error('takka_bridge_table_row_key', 'row_key must be a non-empty string.', ['status' => 400, 'index' => $index]);
            }
            $copy = $operation;
            if (!array_key_exists('key_column', $copy)) {
                $case_sensitive = array_key_exists('case_sensitive', $copy) ? !empty($copy['case_sensitive']) : true;
                $matches = self::find_exact_matches($tables, $row_key, $table_index, null, $case_sensitive);
                if (count($matches) !== 1) {
                    return self::ambiguous_error('Could not resolve a unique key column for row_key in the selected table.', (int) $index, $matches);
                }
                $copy['key_column'] = (int) $matches[0]['column_index'];
            }
            $resolved_columns[] = (int) $copy['key_column'];
            $resolved_operations[] = $copy;
        }

        $resolved_params = $params;
        $resolved_params['table_index'] = $table_index;
        $resolved_params['operations'] = $resolved_operations;
        $current_table_sha = hash('sha256', $tables[$table_index]['html']);
        if (!isset($resolved_params['expected_table_sha256']) || !is_string($resolved_params['expected_table_sha256']) || trim($resolved_params['expected_table_sha256']) === '') {
            $resolved_params['expected_table_sha256'] = $current_table_sha;
        }

        return [
            'params' => $resolved_params,
            'auto_table' => $auto_table,
            'table_index' => $table_index,
            'table_sha256' => $current_table_sha,
            'resolved_key_columns' => $resolved_columns,
        ];
    }

    private static function decorate_smart_response($response, array $resolved)
    {
        if (is_wp_error($response)) return $response;
        $rest = rest_ensure_response($response);
        $data = $rest->get_data();
        if (!is_array($data)) return $response;
        $data['smart_resolution'] = [
            'auto_table' => (bool) $resolved['auto_table'],
            'table_index' => (int) $resolved['table_index'],
            'table_sha256' => (string) $resolved['table_sha256'],
            'resolved_key_columns' => array_values($resolved['resolved_key_columns']),
        ];
        $rest->set_data($data);
        return $rest;
    }

    private static function find_exact_matches(array $tables, string $row_key, ?int $table_filter, ?int $column_filter, bool $case_sensitive): array
    {
        $matches = [];
        foreach ($tables as $table_index => $table) {
            if ($table_filter !== null && $table_index !== $table_filter) continue;
            $rows = self::parse_rows($table['html']);
            if (is_wp_error($rows)) return [];
            foreach ($rows as $row_index => $row) {
                foreach ($row['cells'] as $column_index => $cell) {
                    if ($column_filter !== null && $column_index !== $column_filter) continue;
                    if (!self::text_matches($cell['text'], $row_key, 'exact', $case_sensitive)) continue;
                    $matches[] = [
                        'table_index' => $table_index,
                        'row_index' => $row_index,
                        'column_index' => $column_index,
                    ];
                }
            }
        }
        return $matches;
    }

    private static function ambiguous_error(string $message, int $index, array $matches): WP_Error
    {
        return new WP_Error('takka_bridge_table_smart_target_ambiguous', $message, [
            'status' => 409,
            'index' => $index,
            'matches' => count($matches),
            'candidates' => array_slice($matches, 0, 20),
            'candidates_truncated' => count($matches) > 20,
        ]);
    }

    private static function parse_tables(string $content)
    {
        $count = preg_match_all('/<table\b[^>]*>[\s\S]*?<\/table>/i', $content, $matches, PREG_OFFSET_CAPTURE);
        if ($count === false) {
            return new WP_Error('takka_bridge_table_parse', 'Could not parse table markup.', ['status' => 500]);
        }
        if ($count > self::MAX_TABLES) {
            return new WP_Error('takka_bridge_table_limit', 'Too many tables in post content.', ['status' => 413, 'max_tables' => self::MAX_TABLES]);
        }
        $tables = [];
        foreach ($matches[0] as $match) {
            $tables[] = ['html' => $match[0], 'offset' => (int) $match[1]];
        }
        return $tables;
    }

    private static function parse_rows(string $table)
    {
        $count = preg_match_all('/<tr\b[^>]*>[\s\S]*?<\/tr>/i', $table, $matches, PREG_OFFSET_CAPTURE);
        if ($count === false) {
            return new WP_Error('takka_bridge_table_row_parse', 'Could not parse table rows.', ['status' => 500]);
        }
        if ($count > self::MAX_ROWS) {
            return new WP_Error('takka_bridge_table_row_limit', 'Too many rows in selected table.', ['status' => 413, 'max_rows' => self::MAX_ROWS]);
        }
        $rows = [];
        foreach ($matches[0] as $match) {
            $cells = self::parse_cells($match[0]);
            if (is_wp_error($cells)) return $cells;
            $rows[] = [
                'html' => $match[0],
                'offset' => (int) $match[1],
                'cells' => $cells,
            ];
        }
        return $rows;
    }

    private static function parse_cells(string $row)
    {
        $count = preg_match_all('/<(td|th)\b([^>]*)>([\s\S]*?)<\/\1>/i', $row, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if ($count === false) {
            return new WP_Error('takka_bridge_table_cell_parse', 'Could not parse table cells.', ['status' => 500]);
        }
        if ($count > self::MAX_CELLS) {
            return new WP_Error('takka_bridge_table_cell_limit', 'Too many cells in a table row.', ['status' => 413, 'max_cells' => self::MAX_CELLS]);
        }
        $cells = [];
        foreach ($matches as $match) {
            $inner = $match[3][0];
            $cells[] = [
                'html' => $match[0][0],
                'text' => self::normalize_text($inner),
            ];
        }
        return $cells;
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

    private static function normalize_text(string $value): string
    {
        $text = wp_strip_all_tags($value, true);
        $charset = get_bloginfo('charset');
        if (!is_string($charset) || $charset === '') $charset = 'UTF-8';
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, $charset);
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim(is_string($text) ? $text : '');
    }

    private static function text_matches(string $actual, string $expected, string $mode, bool $case_sensitive): bool
    {
        $actual = self::normalize_text($actual);
        $expected = self::normalize_text($expected);
        if ($mode === 'exact') {
            if ($case_sensitive) return $actual === $expected;
            if (function_exists('mb_strtolower')) return mb_strtolower($actual, 'UTF-8') === mb_strtolower($expected, 'UTF-8');
            return strtolower($actual) === strtolower($expected);
        }
        if ($case_sensitive) return strpos($actual, $expected) !== false;
        if (function_exists('mb_stripos')) return mb_stripos($actual, $expected, 0, 'UTF-8') !== false;
        return stripos($actual, $expected) !== false;
    }

    private static function truncate_text(string $text, int $chars): string
    {
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($text, 'UTF-8') > $chars ? mb_substr($text, 0, $chars, 'UTF-8') . '…' : $text;
        }
        return strlen($text) > $chars ? substr($text, 0, $chars) . '…' : $text;
    }

    private static function text_length(string $text): int
    {
        return function_exists('mb_strlen') ? (int) mb_strlen($text, 'UTF-8') : strlen($text);
    }
}
