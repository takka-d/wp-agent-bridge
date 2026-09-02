<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TakKa_WordPress_Bridge_V088_Table_Headers
{
    private const MAX_CONTENT_BYTES = 4194304;
    private const MAX_TABLES = 100;
    private const MAX_ROWS = 2000;
    private const MAX_CELLS = 100;
    private const MAX_OPERATIONS = 50;

    public static function inspect(array $params)
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
        $selected = array_key_exists('table_index', $params) ? (int) $params['table_index'] : null;
        if ($selected !== null && ($selected < 0 || $selected >= count($tables))) {
            return new WP_Error('takka_bridge_table_index', 'table_index is out of range.', ['status' => 404, 'table_count' => count($tables)]);
        }

        $items = [];
        foreach ($tables as $table_index => $table) {
            if ($selected !== null && $selected !== $table_index) continue;
            $rows = self::parse_rows($table['html']);
            if (is_wp_error($rows)) return $rows;
            $header = self::detect_header($rows, $params, false);
            if (is_wp_error($header)) return $header;
            $items[] = [
                'table_index' => $table_index,
                'table_sha256' => hash('sha256', $table['html']),
                'row_count' => count($rows),
                'header_detected' => $header !== null,
                'header_row_index' => $header !== null ? $header['row_index'] : null,
                'headers' => $header !== null ? $header['headers'] : [],
                'header_sha256' => $header !== null ? $header['sha256'] : null,
            ];
        }

        return rest_ensure_response([
            'post_id' => (int) $post->ID,
            'post_type' => (string) $post->post_type,
            'status' => (string) $post->post_status,
            'modified_gmt' => (string) $post->post_modified_gmt,
            'content_sha256' => hash('sha256', $content),
            'table_count' => count($tables),
            'tables' => $items,
        ]);
    }

    public static function preview(array $params)
    {
        $resolved = self::resolve($params);
        if (is_wp_error($resolved)) return $resolved;
        $response = TakKa_WordPress_Bridge_V087_Table_Locator::smart_preview($resolved['params']);
        return self::decorate($response, $resolved);
    }

    public static function apply(array $params)
    {
        $resolved = self::resolve($params);
        if (is_wp_error($resolved)) return $resolved;
        $response = TakKa_WordPress_Bridge_V087_Table_Locator::smart_apply($resolved['params']);
        return self::decorate($response, $resolved);
    }

    private static function resolve(array $params)
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
        if (count($operations) > self::MAX_OPERATIONS) {
            return new WP_Error('takka_bridge_table_operations_limit', 'Too many table operations.', ['status' => 413, 'max_operations' => self::MAX_OPERATIONS]);
        }

        $first = reset($operations);
        if (!is_array($first)) {
            return new WP_Error('takka_bridge_table_operation_object', 'Each table operation must be an object.', ['status' => 400, 'index' => 0]);
        }

        $table_index = self::resolve_table_index($params, $tables, $first);
        if (is_wp_error($table_index)) return $table_index;
        $table_index = (int) $table_index;

        $rows = self::parse_rows($tables[$table_index]['html']);
        if (is_wp_error($rows)) return $rows;
        $header = self::detect_header($rows, $params, true);
        if (is_wp_error($header)) return $header;

        $header_case_sensitive = array_key_exists('header_case_sensitive', $params) ? !empty($params['header_case_sensitive']) : false;
        $translated = [];
        $header_targets = [];

        foreach (array_values($operations) as $index => $operation) {
            if (!is_array($operation)) {
                return new WP_Error('takka_bridge_table_operation_object', 'Each table operation must be an object.', ['status' => 400, 'index' => $index]);
            }
            $copy = $operation;
            $type = isset($copy['type']) && is_string($copy['type']) ? strtolower(trim($copy['type'])) : '';

            if (isset($copy['key_header'])) {
                if (!is_string($copy['key_header']) || trim($copy['key_header']) === '') {
                    return new WP_Error('takka_bridge_table_key_header', 'key_header must be a non-empty string.', ['status' => 400, 'index' => $index]);
                }
                $key_column = self::resolve_header_column($header['headers'], $copy['key_header'], $header_case_sensitive, (int) $index, 'key_header');
                if (is_wp_error($key_column)) return $key_column;
                if (array_key_exists('key_column', $copy) && (int) $copy['key_column'] !== $key_column) {
                    return new WP_Error('takka_bridge_table_header_column_conflict', 'key_header and key_column resolve to different columns.', ['status' => 409, 'index' => $index]);
                }
                $copy['key_column'] = $key_column;
                unset($copy['key_header']);
            }

            if ($type === 'set_cell') {
                if (isset($copy['column_header'])) {
                    if (!is_string($copy['column_header']) || trim($copy['column_header']) === '') {
                        return new WP_Error('takka_bridge_table_column_header', 'column_header must be a non-empty string.', ['status' => 400, 'index' => $index]);
                    }
                    $column = self::resolve_header_column($header['headers'], $copy['column_header'], $header_case_sensitive, (int) $index, 'column_header');
                    if (is_wp_error($column)) return $column;
                    if (array_key_exists('column', $copy) && (int) $copy['column'] !== $column) {
                        return new WP_Error('takka_bridge_table_header_column_conflict', 'column_header and column resolve to different columns.', ['status' => 409, 'index' => $index]);
                    }
                    $copy['column'] = $column;
                    $header_targets[] = ['index' => $index, 'header' => self::normalize_text($copy['column_header']), 'column' => $column];
                    unset($copy['column_header']);
                }
            } elseif ($type === 'set_cells') {
                if (isset($copy['cells_by_header'])) {
                    if (isset($copy['cells'])) {
                        return new WP_Error('takka_bridge_table_header_cells_conflict', 'Use either cells or cells_by_header, not both.', ['status' => 400, 'index' => $index]);
                    }
                    if (!is_array($copy['cells_by_header']) || !$copy['cells_by_header']) {
                        return new WP_Error('takka_bridge_table_cells', 'cells_by_header must be a non-empty object.', ['status' => 400, 'index' => $index]);
                    }
                    $numeric = [];
                    foreach ($copy['cells_by_header'] as $name => $spec) {
                        if (!is_string($name) || trim($name) === '') {
                            return new WP_Error('takka_bridge_table_column_header', 'cells_by_header keys must be non-empty header names.', ['status' => 400, 'index' => $index]);
                        }
                        $column = self::resolve_header_column($header['headers'], $name, $header_case_sensitive, (int) $index, 'cells_by_header');
                        if (is_wp_error($column)) return $column;
                        $numeric[$column] = $spec;
                        $header_targets[] = ['index' => $index, 'header' => self::normalize_text($name), 'column' => $column];
                    }
                    ksort($numeric);
                    $copy['cells'] = $numeric;
                    unset($copy['cells_by_header']);
                }
            } elseif ($type === 'insert_row_before' || $type === 'insert_row_after') {
                if (isset($copy['cells_by_header'])) {
                    if (isset($copy['cells'])) {
                        return new WP_Error('takka_bridge_table_header_cells_conflict', 'Use either cells or cells_by_header, not both.', ['status' => 400, 'index' => $index]);
                    }
                    if (!is_array($copy['cells_by_header']) || !$copy['cells_by_header']) {
                        return new WP_Error('takka_bridge_table_cells', 'cells_by_header must be a non-empty object.', ['status' => 400, 'index' => $index]);
                    }
                    $row_values = array_fill(0, count($header['headers']), null);
                    $filled = [];
                    foreach ($copy['cells_by_header'] as $name => $spec) {
                        if (!is_string($name) || trim($name) === '') {
                            return new WP_Error('takka_bridge_table_column_header', 'cells_by_header keys must be non-empty header names.', ['status' => 400, 'index' => $index]);
                        }
                        $column = self::resolve_header_column($header['headers'], $name, $header_case_sensitive, (int) $index, 'cells_by_header');
                        if (is_wp_error($column)) return $column;
                        $row_values[$column] = $spec;
                        $filled[$column] = true;
                        $header_targets[] = ['index' => $index, 'header' => self::normalize_text($name), 'column' => $column];
                    }
                    if (count($filled) !== count($header['headers'])) {
                        $missing = [];
                        foreach ($header['headers'] as $column => $name) {
                            if (!isset($filled[$column])) $missing[] = ['column' => $column, 'header' => $name];
                        }
                        return new WP_Error('takka_bridge_table_insert_headers_incomplete', 'Inserted row must provide every header column.', [
                            'status' => 409,
                            'index' => $index,
                            'missing_headers' => $missing,
                        ]);
                    }
                    $copy['cells'] = array_values($row_values);
                    unset($copy['cells_by_header']);
                }
            }

            $translated[] = $copy;
        }

        $resolved_params = $params;
        $resolved_params['table_index'] = $table_index;
        $resolved_params['operations'] = $translated;
        unset($resolved_params['header_row_index'], $resolved_params['header_case_sensitive']);

        return [
            'params' => $resolved_params,
            'table_index' => $table_index,
            'table_sha256' => hash('sha256', $tables[$table_index]['html']),
            'header_row_index' => $header['row_index'],
            'headers' => $header['headers'],
            'header_sha256' => $header['sha256'],
            'header_targets' => $header_targets,
        ];
    }

    private static function resolve_table_index(array $params, array $tables, array $first)
    {
        if (array_key_exists('table_index', $params)) {
            $table_index = (int) $params['table_index'];
            if ($table_index < 0 || $table_index >= count($tables)) {
                return new WP_Error('takka_bridge_table_index', 'table_index is out of range.', ['status' => 404, 'table_count' => count($tables)]);
            }
            return $table_index;
        }

        $row_key = isset($first['row_key']) && is_string($first['row_key']) ? self::normalize_text($first['row_key']) : '';
        if ($row_key === '') {
            return new WP_Error('takka_bridge_table_row_key', 'The first operation must contain a non-empty row_key when table_index is omitted.', ['status' => 400, 'index' => 0]);
        }
        $case_sensitive = array_key_exists('case_sensitive', $first) ? !empty($first['case_sensitive']) : true;

        $located = TakKa_WordPress_Bridge_V087_Table_Locator::locate([
            'post_id' => isset($params['post_id']) ? $params['post_id'] : 0,
            'query' => $row_key,
            'match_mode' => 'exact',
            'case_sensitive' => $case_sensitive,
            'include_row' => false,
            'limit' => 100,
        ]);
        if (is_wp_error($located)) return $located;
        $data = rest_ensure_response($located)->get_data();
        $matches = is_array($data) && isset($data['matches']) && is_array($data['matches']) ? $data['matches'] : [];

        if (isset($first['key_header']) && is_string($first['key_header']) && trim($first['key_header']) !== '') {
            $filtered = [];
            $header_case_sensitive = array_key_exists('header_case_sensitive', $params) ? !empty($params['header_case_sensitive']) : false;
            foreach ($matches as $match) {
                if (!is_array($match) || !isset($match['table_index'], $match['column_index'])) continue;
                $candidate_index = (int) $match['table_index'];
                if (!isset($tables[$candidate_index])) continue;
                $rows = self::parse_rows($tables[$candidate_index]['html']);
                if (is_wp_error($rows)) return $rows;
                $header = self::detect_header($rows, $params, true);
                if (is_wp_error($header)) return $header;
                $column = self::resolve_header_column($header['headers'], $first['key_header'], $header_case_sensitive, 0, 'key_header', false);
                if (is_wp_error($column)) continue;
                if ((int) $match['column_index'] === (int) $column) $filtered[] = $match;
            }
            $matches = $filtered;
        }

        if (count($matches) !== 1) {
            $candidates = [];
            foreach (array_slice($matches, 0, 20) as $match) {
                if (!is_array($match)) continue;
                $candidates[] = [
                    'table_index' => isset($match['table_index']) ? (int) $match['table_index'] : null,
                    'row_index' => isset($match['row_index']) ? (int) $match['row_index'] : null,
                    'column_index' => isset($match['column_index']) ? (int) $match['column_index'] : null,
                ];
            }
            return new WP_Error('takka_bridge_table_header_target_ambiguous', 'Could not resolve a unique table from the first row_key.', [
                'status' => 409,
                'matches' => count($matches),
                'candidates' => $candidates,
                'candidates_truncated' => count($matches) > 20,
            ]);
        }
        return (int) $matches[0]['table_index'];
    }

    private static function detect_header(array $rows, array $params, bool $required)
    {
        if (array_key_exists('header_row_index', $params)) {
            $row_index = (int) $params['header_row_index'];
            if ($row_index < 0 || !isset($rows[$row_index])) {
                return new WP_Error('takka_bridge_table_header_row', 'header_row_index is out of range.', ['status' => 404, 'row_count' => count($rows)]);
            }
        } else {
            $row_index = null;
            foreach ($rows as $index => $row) {
                foreach ($row['cells'] as $cell) {
                    if ($cell['tag'] === 'th') {
                        $row_index = (int) $index;
                        break 2;
                    }
                }
            }
            if ($row_index === null) {
                if (!$required) return null;
                return new WP_Error('takka_bridge_table_header_not_found', 'No table header row containing <th> cells was found. Supply header_row_index explicitly for non-standard tables.', ['status' => 409]);
            }
        }

        $headers = [];
        foreach ($rows[$row_index]['cells'] as $cell) $headers[] = self::normalize_text($cell['text']);
        if (!$headers) {
            return new WP_Error('takka_bridge_table_header_empty', 'Resolved header row has no cells.', ['status' => 409]);
        }
        return [
            'row_index' => $row_index,
            'headers' => $headers,
            'sha256' => hash('sha256', wp_json_encode($headers)),
        ];
    }

    private static function resolve_header_column(array $headers, string $name, bool $case_sensitive, int $operation_index, string $field, bool $strict = true)
    {
        $needle = self::normalize_text($name);
        $matches = [];
        foreach ($headers as $column => $header) {
            if (self::text_equals((string) $header, $needle, $case_sensitive)) $matches[] = (int) $column;
        }
        if (count($matches) !== 1) {
            return new WP_Error('takka_bridge_table_header_match', 'Header name must identify exactly one column.', [
                'status' => $strict ? 409 : 404,
                'index' => $operation_index,
                'field' => $field,
                'header_sha256' => hash('sha256', $needle),
                'matches' => count($matches),
                'candidate_columns' => $matches,
            ]);
        }
        return $matches[0];
    }

    private static function decorate($response, array $resolved)
    {
        if (is_wp_error($response)) return $response;
        $rest = rest_ensure_response($response);
        $data = $rest->get_data();
        if (!is_array($data)) return $response;
        $data['header_resolution'] = [
            'table_index' => (int) $resolved['table_index'],
            'table_sha256' => (string) $resolved['table_sha256'],
            'header_row_index' => (int) $resolved['header_row_index'],
            'headers' => array_values($resolved['headers']),
            'header_sha256' => (string) $resolved['header_sha256'],
            'targets' => array_values($resolved['header_targets']),
        ];
        $rest->set_data($data);
        return $rest;
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
        foreach ($matches[0] as $match) $tables[] = ['html' => $match[0], 'offset' => (int) $match[1]];
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
            $rows[] = ['html' => $match[0], 'offset' => (int) $match[1], 'cells' => $cells];
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
                'tag' => strtolower($match[1][0]),
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

    private static function text_equals(string $actual, string $expected, bool $case_sensitive): bool
    {
        $actual = self::normalize_text($actual);
        $expected = self::normalize_text($expected);
        if ($case_sensitive) return $actual === $expected;
        if (function_exists('mb_strtolower')) return mb_strtolower($actual, 'UTF-8') === mb_strtolower($expected, 'UTF-8');
        return strtolower($actual) === strtolower($expected);
    }
}
