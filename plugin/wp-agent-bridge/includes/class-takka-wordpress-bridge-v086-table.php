<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TakKa_WordPress_Bridge_V086_Table
{
    private const MAX_CONTENT_BYTES = 4194304;
    private const MAX_TABLES = 100;
    private const MAX_ROWS = 2000;
    private const MAX_CELLS = 100;
    private const MAX_OPERATIONS = 50;
    private const MAX_CELL_BYTES = 262144;
    private const MAX_INSPECT_ROWS = 200;
    private const MAX_INSPECT_CELL_CHARS = 240;

    public static function inspect(array $params)
    {
        $context = self::context($params);
        if (is_wp_error($context)) return $context;

        $content = (string) $context['post']->post_content;
        if (strlen($content) > self::MAX_CONTENT_BYTES) {
            return new WP_Error('takka_bridge_table_content_size', 'Post content is too large for Bridge table tooling.', ['status' => 413]);
        }

        $tables = self::parse_tables($content);
        if (is_wp_error($tables)) return $tables;

        $selected = array_key_exists('table_index', $params) ? (int) $params['table_index'] : null;
        if ($selected !== null && ($selected < 0 || $selected >= count($tables))) {
            return new WP_Error('takka_bridge_table_index', 'table_index is out of range.', ['status' => 404, 'table_count' => count($tables)]);
        }

        $include_rows = !empty($params['include_rows']);
        $row_limit = isset($params['row_limit']) ? max(1, min(self::MAX_INSPECT_ROWS, (int) $params['row_limit'])) : 100;
        $items = [];

        foreach ($tables as $index => $table) {
            if ($selected !== null && $index !== $selected) continue;
            $rows = self::parse_rows($table['html']);
            if (is_wp_error($rows)) return $rows;

            $max_columns = 0;
            foreach ($rows as $row) $max_columns = max($max_columns, count($row['cells']));

            $item = [
                'table_index' => $index,
                'table_sha256' => hash('sha256', $table['html']),
                'table_bytes' => strlen($table['html']),
                'row_count' => count($rows),
                'max_columns' => $max_columns,
            ];

            if ($include_rows) {
                $row_items = [];
                foreach (array_slice($rows, 0, $row_limit) as $row_index => $row) {
                    $cell_texts = [];
                    foreach ($row['cells'] as $cell) {
                        $cell_texts[] = self::truncate_text($cell['text'], self::MAX_INSPECT_CELL_CHARS);
                    }
                    $row_items[] = [
                        'row_index' => $row_index,
                        'cell_count' => count($row['cells']),
                        'cells' => $cell_texts,
                    ];
                }
                $item['rows'] = $row_items;
                $item['rows_truncated'] = count($rows) > count($row_items);
            }
            $items[] = $item;
        }

        return rest_ensure_response([
            'post_id' => (int) $context['post']->ID,
            'post_type' => (string) $context['post']->post_type,
            'status' => (string) $context['post']->post_status,
            'modified_gmt' => (string) $context['post']->post_modified_gmt,
            'content_sha256' => hash('sha256', $content),
            'content_bytes' => strlen($content),
            'table_count' => count($tables),
            'tables' => $items,
            'rows_included' => $include_rows,
        ]);
    }

    public static function preview(array $params)
    {
        $translation = self::translate($params);
        if (is_wp_error($translation)) return $translation;

        $response = TakKa_WordPress_Bridge_V085_Content_Batch::preview($translation['batch_params']);
        if (is_wp_error($response)) return $response;
        $rest = rest_ensure_response($response);
        $data = $rest->get_data();
        if (!is_array($data)) return $response;

        $data['table_index'] = $translation['table_index'];
        $data['table_before_sha256'] = $translation['table_before_sha256'];
        $data['table_after_sha256'] = $translation['table_after_sha256'];
        $data['semantic_operation_count'] = count($translation['semantic_operations']);
        $data['semantic_operations'] = $translation['semantic_operations'];
        $rest->set_data($data);
        return $rest;
    }

    public static function apply(array $params)
    {
        if (empty($params['confirm'])) {
            return new WP_Error('takka_bridge_confirmation_required', 'Table changes require confirm=true.', ['status' => 400]);
        }

        $translation = self::translate($params);
        if (is_wp_error($translation)) return $translation;

        $batch = $translation['batch_params'];
        $batch['expected_before_sha256'] = isset($params['expected_before_sha256']) && is_string($params['expected_before_sha256']) ? trim($params['expected_before_sha256']) : '';
        $batch['expected_plan_hash'] = isset($params['expected_plan_hash']) && is_string($params['expected_plan_hash']) ? trim($params['expected_plan_hash']) : '';
        $batch['confirm'] = true;
        if (!empty($params['confirm_live'])) $batch['confirm_live'] = true;

        $response = TakKa_WordPress_Bridge_V085_Content_Batch::apply($batch);
        if (is_wp_error($response)) return $response;
        $rest = rest_ensure_response($response);
        $data = $rest->get_data();
        if (!is_array($data)) return $response;

        $data['table_index'] = $translation['table_index'];
        $data['table_before_sha256'] = $translation['table_before_sha256'];
        $data['table_after_sha256'] = $translation['table_after_sha256'];
        $data['semantic_operation_count'] = count($translation['semantic_operations']);
        $rest->set_data($data);
        return $rest;
    }

    private static function translate(array $params)
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
        $table_index = isset($params['table_index']) ? (int) $params['table_index'] : 0;
        if ($table_index < 0 || $table_index >= count($tables)) {
            return new WP_Error('takka_bridge_table_index', 'table_index is out of range.', ['status' => 404, 'table_count' => count($tables)]);
        }

        $table_before = $tables[$table_index]['html'];
        $table_before_sha = hash('sha256', $table_before);
        $expected_table = isset($params['expected_table_sha256']) && is_string($params['expected_table_sha256']) ? trim($params['expected_table_sha256']) : '';
        if ($expected_table !== '' && !hash_equals($table_before_sha, $expected_table)) {
            return new WP_Error('takka_bridge_table_changed', 'Selected table changed after inspection or preview.', [
                'status' => 409,
                'current_table_sha256' => $table_before_sha,
            ]);
        }

        if (substr_count($content, $table_before) !== 1) {
            return new WP_Error('takka_bridge_table_not_unique', 'Selected table markup is not unique in the post; refusing ambiguous replacement.', ['status' => 409]);
        }

        $operations = isset($params['operations']) ? $params['operations'] : null;
        if (!is_array($operations) || !$operations) {
            return new WP_Error('takka_bridge_table_operations_required', 'operations must be a non-empty array.', ['status' => 400]);
        }
        if (count($operations) > self::MAX_OPERATIONS) {
            return new WP_Error('takka_bridge_table_operations_limit', 'Too many table operations.', ['status' => 413, 'max_operations' => self::MAX_OPERATIONS]);
        }

        $working = $table_before;
        $summaries = [];
        foreach (array_values($operations) as $index => $operation) {
            if (!is_array($operation)) {
                return new WP_Error('takka_bridge_table_operation_object', 'Each table operation must be an object.', ['status' => 400, 'index' => $index]);
            }
            $step = self::apply_semantic_operation($working, $operation, (int) $index);
            if (is_wp_error($step)) return $step;
            $working = $step['_after'];
            unset($step['_after']);
            $summaries[] = $step;
        }

        if ($working === $table_before) {
            return new WP_Error('takka_bridge_table_no_change', 'Table operations would not change the selected table.', ['status' => 409]);
        }

        return [
            'batch_params' => [
                'post_id' => (int) $post->ID,
                'operations' => [[
                    'type' => 'replace',
                    'find' => $table_before,
                    'replace' => $working,
                    'expected_matches' => 1,
                ]],
            ],
            'table_index' => $table_index,
            'table_before_sha256' => $table_before_sha,
            'table_after_sha256' => hash('sha256', $working),
            'semantic_operations' => $summaries,
        ];
    }

    private static function apply_semantic_operation(string $table, array $operation, int $index)
    {
        $type = isset($operation['type']) && is_string($operation['type']) ? strtolower(trim($operation['type'])) : '';
        if (!in_array($type, ['set_cell', 'set_cells', 'insert_row_before', 'insert_row_after', 'delete_row'], true)) {
            return new WP_Error('takka_bridge_table_operation_type', 'Table operation type must be set_cell, set_cells, insert_row_before, insert_row_after, or delete_row.', ['status' => 400, 'index' => $index]);
        }

        $row_key = isset($operation['row_key']) && is_string($operation['row_key']) ? self::normalize_text($operation['row_key']) : '';
        if ($row_key === '') {
            return new WP_Error('takka_bridge_table_row_key', 'row_key must be a non-empty string.', ['status' => 400, 'index' => $index]);
        }
        $key_column = isset($operation['key_column']) ? (int) $operation['key_column'] : 0;
        if ($key_column < 0 || $key_column >= self::MAX_CELLS) {
            return new WP_Error('takka_bridge_table_key_column', 'key_column is out of range.', ['status' => 400, 'index' => $index]);
        }
        $case_sensitive = array_key_exists('case_sensitive', $operation) ? !empty($operation['case_sensitive']) : true;

        $rows = self::parse_rows($table);
        if (is_wp_error($rows)) return $rows;
        $matches = [];
        foreach ($rows as $row_index => $row) {
            if (!isset($row['cells'][$key_column])) continue;
            if (self::text_equals($row['cells'][$key_column]['text'], $row_key, $case_sensitive)) {
                $matches[] = $row_index;
            }
        }
        if (count($matches) !== 1) {
            return new WP_Error('takka_bridge_table_row_match', 'row_key must identify exactly one row in the selected table.', [
                'status' => 409,
                'index' => $index,
                'matches' => count($matches),
                'key_column' => $key_column,
            ]);
        }

        $row_index = $matches[0];
        $row = $rows[$row_index];
        $before_row = $row['html'];
        $after_row = $before_row;
        $target_columns = [];

        if ($type === 'set_cell') {
            $column = isset($operation['column']) ? (int) $operation['column'] : -1;
            if ($column < 0 || !isset($row['cells'][$column])) {
                return new WP_Error('takka_bridge_table_column', 'column is out of range for the matched row.', ['status' => 400, 'index' => $index, 'column' => $column]);
            }
            $spec = [
                'content' => isset($operation['content']) && is_string($operation['content']) ? $operation['content'] : '',
                'format' => isset($operation['format']) && is_string($operation['format']) ? $operation['format'] : 'text',
            ];
            $after_row = self::replace_row_cells($before_row, [$column => $spec]);
            if (is_wp_error($after_row)) return $after_row;
            $target_columns[] = $column;
        } elseif ($type === 'set_cells') {
            $cells = isset($operation['cells']) ? $operation['cells'] : null;
            if (!is_array($cells) || !$cells) {
                return new WP_Error('takka_bridge_table_cells', 'set_cells requires a non-empty cells object or array.', ['status' => 400, 'index' => $index]);
            }
            $map = [];
            foreach ($cells as $column => $spec) {
                if (is_int($column)) {
                    $column_index = $column;
                } elseif (is_string($column) && ctype_digit($column)) {
                    $column_index = (int) $column;
                } else {
                    return new WP_Error('takka_bridge_table_column', 'set_cells keys must be numeric column indexes.', ['status' => 400, 'index' => $index]);
                }
                if ($column_index < 0 || !isset($row['cells'][$column_index])) {
                    return new WP_Error('takka_bridge_table_column', 'A set_cells column is out of range for the matched row.', ['status' => 400, 'index' => $index, 'column' => $column_index]);
                }
                $map[$column_index] = $spec;
                $target_columns[] = $column_index;
            }
            $after_row = self::replace_row_cells($before_row, $map);
            if (is_wp_error($after_row)) return $after_row;
            sort($target_columns);
        } elseif ($type === 'insert_row_before' || $type === 'insert_row_after') {
            $cells = isset($operation['cells']) ? $operation['cells'] : null;
            if (!is_array($cells) || !$cells) {
                return new WP_Error('takka_bridge_table_cells', 'Row insertion requires a non-empty cells array.', ['status' => 400, 'index' => $index]);
            }
            if (count($cells) !== count($row['cells'])) {
                return new WP_Error('takka_bridge_table_insert_columns', 'Inserted row must have the same number of cells as the anchor row.', [
                    'status' => 409,
                    'index' => $index,
                    'expected_columns' => count($row['cells']),
                    'actual_columns' => count($cells),
                ]);
            }
            $map = [];
            foreach (array_values($cells) as $column => $spec) $map[$column] = $spec;
            $new_row = self::replace_row_cells($before_row, $map);
            if (is_wp_error($new_row)) return $new_row;
            if ($new_row === $before_row) {
                return new WP_Error('takka_bridge_table_insert_no_change', 'Inserted row must differ from the anchor row.', ['status' => 409, 'index' => $index]);
            }
            $insert_at = $type === 'insert_row_before' ? $row['offset'] : $row['offset'] + strlen($before_row);
            $after_table = substr_replace($table, $new_row, $insert_at, 0);
            return self::semantic_summary($index, $type, $row_key, $key_column, $row_index, [], $table, $after_table);
        } else {
            $after_table = substr_replace($table, '', $row['offset'], strlen($before_row));
            return self::semantic_summary($index, $type, $row_key, $key_column, $row_index, [], $table, $after_table);
        }

        if (is_string($after_row) && strlen($after_row) > self::MAX_CELL_BYTES * self::MAX_CELLS) {
            return new WP_Error('takka_bridge_table_row_size', 'Updated row is too large.', ['status' => 413, 'index' => $index]);
        }
        if ($after_row === $before_row) {
            return new WP_Error('takka_bridge_table_operation_no_change', 'Table operation would not change the matched row.', ['status' => 409, 'index' => $index]);
        }
        $after_table = substr_replace($table, $after_row, $row['offset'], strlen($before_row));
        return self::semantic_summary($index, $type, $row_key, $key_column, $row_index, $target_columns, $table, $after_table);
    }

    private static function replace_row_cells(string $row_html, array $map)
    {
        $cells = self::parse_cells($row_html);
        if (is_wp_error($cells)) return $cells;
        $replacements = [];
        foreach ($map as $column => $spec) {
            $column = (int) $column;
            if (!isset($cells[$column])) {
                return new WP_Error('takka_bridge_table_column', 'Column is out of range while building row.', ['status' => 400, 'column' => $column]);
            }
            $replacement = self::render_cell_content($spec);
            if (is_wp_error($replacement)) return $replacement;
            if (strlen($replacement) > self::MAX_CELL_BYTES) {
                return new WP_Error('takka_bridge_table_cell_size', 'Cell content exceeds the size limit.', ['status' => 413, 'column' => $column]);
            }
            $replacements[] = [
                'offset' => $cells[$column]['inner_offset'],
                'length' => $cells[$column]['inner_length'],
                'content' => $replacement,
            ];
        }
        usort($replacements, function ($a, $b) { return $b['offset'] <=> $a['offset']; });
        $out = $row_html;
        foreach ($replacements as $replacement) {
            $out = substr_replace($out, $replacement['content'], $replacement['offset'], $replacement['length']);
        }
        return $out;
    }

    private static function render_cell_content($spec)
    {
        if (is_string($spec) || is_int($spec) || is_float($spec)) {
            return esc_html((string) $spec);
        }
        if (!is_array($spec)) {
            return new WP_Error('takka_bridge_table_cell_spec', 'Cell value must be a string/number or an object with content and format.', ['status' => 400]);
        }
        $content = isset($spec['content']) && is_string($spec['content']) ? $spec['content'] : '';
        $format = isset($spec['format']) && is_string($spec['format']) ? strtolower(trim($spec['format'])) : 'text';
        if ($format === 'text') return esc_html($content);
        if ($format === 'html') return wp_kses_post($content);
        return new WP_Error('takka_bridge_table_cell_format', 'Cell format must be text or html.', ['status' => 400]);
    }

    private static function semantic_summary(int $index, string $type, string $row_key, int $key_column, int $row_index, array $target_columns, string $before, string $after): array
    {
        return [
            'index' => $index,
            'type' => $type,
            'row_key_sha256' => hash('sha256', $row_key),
            'row_key_chars' => self::text_length($row_key),
            'key_column' => $key_column,
            'matched_row_index' => $row_index,
            'target_columns' => array_values($target_columns),
            'before_table_sha256' => hash('sha256', $before),
            'after_table_sha256' => hash('sha256', $after),
            '_after' => $after,
        ];
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
                'tag' => strtolower($match[1][0]),
                'html' => $match[0][0],
                'offset' => (int) $match[0][1],
                'inner_html' => $inner,
                'inner_offset' => (int) $match[3][1],
                'inner_length' => strlen($inner),
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
