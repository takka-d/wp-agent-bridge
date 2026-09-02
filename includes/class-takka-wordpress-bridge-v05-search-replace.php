<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TakKa_WordPress_Bridge_V05_Search_Replace
{
    private const MAX_SR_ROWS = 5000;
    private const MAX_SR_REPLACEMENTS = 50000;
    private const MAX_SR_SAMPLES = 20;

    public static function plan(array $params)
    {
        $plan = self::build_search_replace_plan($params);
        if (is_wp_error($plan)) {
            return $plan;
        }
        $public = $plan;
        unset($public['operations']);
        return rest_ensure_response($public);
    }

    public static function execute(array $params)
    {
        if (empty($params['confirm'])) {
            return new WP_Error('takka_bridge_confirmation_required', 'Search/replace execution requires confirm=true.', ['status' => 400]);
        }
        $expected_hash = isset($params['plan_hash']) && is_string($params['plan_hash']) ? strtolower(trim($params['plan_hash'])) : '';
        if (!preg_match('/^[a-f0-9]{64}$/', $expected_hash)) {
            return new WP_Error('takka_bridge_sr_plan_hash_required', 'A valid plan_hash from db.search_replace.plan is required.', ['status' => 400]);
        }

        $plan = self::build_search_replace_plan($params);
        if (is_wp_error($plan)) {
            return $plan;
        }
        if (!hash_equals($expected_hash, (string) $plan['plan_hash'])) {
            return new WP_Error('takka_bridge_sr_plan_changed', 'Database contents or search/replace parameters changed since the plan was created.', [
                'status' => 409,
                'expected_plan_hash' => $expected_hash,
                'current_plan_hash' => $plan['plan_hash'],
            ]);
        }

        global $wpdb;
        $search = (string) $plan['search'];
        $replace = (string) $plan['replace'];
        $updated_rows = 0;
        $updated_cells = 0;
        $replacement_count = 0;

        $wpdb->query('START TRANSACTION');
        try {
            foreach ($plan['operations'] as $operation) {
                $table = $operation['table'];
                $pk = $operation['primary_key'];
                $id = $operation['row_id'];
                $updates = [];
                foreach ($operation['columns'] as $column => $expected_before_sha) {
                    $current = $wpdb->get_var($wpdb->prepare(
                        "SELECT `{$column}` FROM `{$table}` WHERE `{$pk}` = %s LIMIT 1",
                        (string) $id
                    ));
                    if ($current === null) {
                        throw new RuntimeException("Row disappeared before update: {$table}.{$pk}={$id}");
                    }
                    $current = (string) $current;
                    if (!hash_equals((string) $expected_before_sha, hash('sha256', $current))) {
                        throw new RuntimeException("Cell changed after plan: {$table}.{$column} row {$id}");
                    }
                    [$next, $count, $unsupported] = self::replace_serialized_aware($current, $search, $replace);
                    if ($unsupported) {
                        throw new RuntimeException("Unsupported serialized object encountered in {$table}.{$column} row {$id}");
                    }
                    if ($count > 0) {
                        $updates[$column] = $next;
                        $updated_cells++;
                        $replacement_count += $count;
                    }
                }
                if ($updates) {
                    $result = $wpdb->update($table, $updates, [$pk => $id]);
                    if ($result === false) {
                        throw new RuntimeException("Database update failed for {$table}.{$pk}={$id}: {$wpdb->last_error}");
                    }
                    $updated_rows++;
                }
            }
            $wpdb->query('COMMIT');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('takka_bridge_sr_execute_failed', $e->getMessage(), ['status' => 500]);
        }

        return rest_ensure_response([
            'ok' => true,
            'plan_hash' => $plan['plan_hash'],
            'updated_rows' => $updated_rows,
            'updated_cells' => $updated_cells,
            'replacements' => $replacement_count,
        ]);
    }

    private static function build_search_replace_plan(array $params)
    {
        global $wpdb;
        $search = self::required_string($params, 'search', false);
        if (is_wp_error($search)) {
            return $search;
        }
        if ($search === '') {
            return new WP_Error('takka_bridge_sr_empty_search', 'search must not be empty.', ['status' => 400]);
        }
        if (!array_key_exists('replace', $params) || !is_string($params['replace'])) {
            return new WP_Error('takka_bridge_sr_replace', 'replace must be a string.', ['status' => 400]);
        }
        $replace = $params['replace'];
        $targets = self::normalize_sr_targets($params);
        if (is_wp_error($targets)) {
            return $targets;
        }

        $operations = [];
        $samples = [];
        $matched_rows = 0;
        $matched_cells = 0;
        $replacement_count = 0;
        $unsupported_serialized_objects = 0;

        foreach ($targets as $target) {
            $table = $target['table'];
            $pk = $target['primary_key'];
            $columns = $target['columns'];
            $where = $target['where'];

            $like_parts = [];
            $args = [];
            foreach ($columns as $column) {
                $like_parts[] = "`{$column}` LIKE %s";
                $args[] = '%' . $wpdb->esc_like($search) . '%';
            }
            $where_parts = [];
            foreach ($where as $column => $value) {
                $where_parts[] = "`{$column}` = %s";
                $args[] = (string) $value;
            }
            $select_columns = $columns;
            if (!empty($target['guard_column']) && !in_array($target['guard_column'], $select_columns, true)) {
                $select_columns[] = $target['guard_column'];
            }
            $sql = "SELECT `{$pk}`, " . implode(', ', array_map(static function ($column) {
                return "`{$column}`";
            }, $select_columns)) . " FROM `{$table}` WHERE (" . implode(' OR ', $like_parts) . ')';
            if ($where_parts) {
                $sql .= ' AND ' . implode(' AND ', $where_parts);
            }
            $sql .= ' LIMIT ' . (self::MAX_SR_ROWS + 1);
            $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
            if (!is_array($rows)) {
                return new WP_Error('takka_bridge_sr_query_failed', $wpdb->last_error ?: 'Search/replace plan query failed.', ['status' => 500]);
            }
            if (count($rows) > self::MAX_SR_ROWS) {
                return new WP_Error('takka_bridge_sr_plan_too_large', 'A target matched more rows than the per-plan limit; narrow the targets or where filter.', [
                    'status' => 413,
                    'table' => $table,
                    'max_rows' => self::MAX_SR_ROWS,
                ]);
            }

            foreach ($rows as $row) {
                if (!empty($target['guard_column']) && isset($row[$target['guard_column']]) && self::is_sensitive_key((string) $row[$target['guard_column']])) {
                    continue;
                }
                $row_operation = [
                    'table' => $table,
                    'primary_key' => $pk,
                    'row_id' => $row[$pk],
                    'columns' => [],
                ];
                $row_matched = false;
                foreach ($columns as $column) {
                    $before = isset($row[$column]) ? (string) $row[$column] : '';
                    [$after, $count, $unsupported] = self::replace_serialized_aware($before, $search, $replace);
                    if ($unsupported) {
                        $unsupported_serialized_objects++;
                        continue;
                    }
                    if ($count < 1) {
                        continue;
                    }
                    $row_matched = true;
                    $matched_cells++;
                    $replacement_count += $count;
                    $row_operation['columns'][$column] = hash('sha256', $before);
                    if (count($samples) < self::MAX_SR_SAMPLES) {
                        $samples[] = [
                            'table' => $table,
                            'row_id' => $row[$pk],
                            'column' => $column,
                            'replacements' => $count,
                            'before' => self::excerpt($before),
                            'after' => self::excerpt($after),
                        ];
                    }
                }
                if ($row_matched) {
                    $matched_rows++;
                    $operations[] = $row_operation;
                }
            }
        }

        if ($matched_rows > self::MAX_SR_ROWS || $replacement_count > self::MAX_SR_REPLACEMENTS) {
            return new WP_Error('takka_bridge_sr_plan_too_large', 'Search/replace plan is too large; narrow the targets or where filter.', [
                'status' => 413,
                'matched_rows' => $matched_rows,
                'replacements' => $replacement_count,
            ]);
        }
        if ($unsupported_serialized_objects > 0) {
            return new WP_Error('takka_bridge_sr_unsupported_serialized_object', 'Plan encountered serialized objects that cannot be safely rewritten.', [
                'status' => 409,
                'count' => $unsupported_serialized_objects,
            ]);
        }

        $hash_input = [
            'search' => $search,
            'replace' => $replace,
            'targets' => $targets,
            'operations' => $operations,
        ];
        $plan_hash = hash('sha256', wp_json_encode($hash_input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return [
            'ok' => true,
            'dry_run' => true,
            'search' => $search,
            'replace' => $replace,
            'targets' => $targets,
            'matched_rows' => $matched_rows,
            'matched_cells' => $matched_cells,
            'replacements' => $replacement_count,
            'samples' => $samples,
            'plan_hash' => $plan_hash,
            'operations' => $operations,
        ];
    }

    private static function normalize_sr_targets(array $params)
    {
        global $wpdb;
        $allowed = [
            $wpdb->posts => ['primary_key' => 'ID', 'columns' => ['post_content', 'post_title', 'post_excerpt'], 'guard_column' => null],
            $wpdb->postmeta => ['primary_key' => 'meta_id', 'columns' => ['meta_value'], 'guard_column' => 'meta_key'],
            $wpdb->options => ['primary_key' => 'option_id', 'columns' => ['option_value'], 'guard_column' => 'option_name'],
            $wpdb->comments => ['primary_key' => 'comment_ID', 'columns' => ['comment_author', 'comment_author_url', 'comment_content'], 'guard_column' => null],
            $wpdb->commentmeta => ['primary_key' => 'meta_id', 'columns' => ['meta_value'], 'guard_column' => 'meta_key'],
            $wpdb->terms => ['primary_key' => 'term_id', 'columns' => ['name'], 'guard_column' => null],
            $wpdb->termmeta => ['primary_key' => 'meta_id', 'columns' => ['meta_value'], 'guard_column' => 'meta_key'],
            $wpdb->term_taxonomy => ['primary_key' => 'term_taxonomy_id', 'columns' => ['description'], 'guard_column' => null],
        ];
        $default_tables = [$wpdb->posts, $wpdb->postmeta, $wpdb->options];
        $input = isset($params['targets']) && is_array($params['targets']) ? $params['targets'] : [];
        if (!$input) {
            foreach ($default_tables as $table) {
                $input[] = ['table' => $table];
            }
        }

        $targets = [];
        foreach ($input as $item) {
            if (!is_array($item) || !isset($item['table']) || !is_string($item['table'])) {
                return new WP_Error('takka_bridge_sr_target', 'Each target requires a table.', ['status' => 400]);
            }
            $table = str_replace('{prefix}', $wpdb->prefix, trim($item['table']));
            if (!isset($allowed[$table])) {
                return new WP_Error('takka_bridge_sr_table_blocked', 'Table is not in the search/replace allowlist.', [
                    'status' => 403,
                    'table' => $table,
                ]);
            }
            if ($table === $wpdb->users || $table === $wpdb->usermeta) {
                return new WP_Error('takka_bridge_sr_user_table_blocked', 'User and usermeta tables are blocked.', ['status' => 403]);
            }
            $allowed_columns = $allowed[$table]['columns'];
            $columns = isset($item['columns']) && is_array($item['columns']) ? array_values(array_unique(array_map('strval', $item['columns']))) : $allowed_columns;
            if (!$columns || array_diff($columns, $allowed_columns)) {
                return new WP_Error('takka_bridge_sr_column_blocked', 'One or more columns are not in the search/replace allowlist.', [
                    'status' => 403,
                    'table' => $table,
                    'allowed_columns' => $allowed_columns,
                ]);
            }
            $where = [];
            if (isset($item['where']) && is_array($item['where'])) {
                $schema_columns = self::table_columns($table);
                foreach ($item['where'] as $column => $value) {
                    if (!is_string($column) || !in_array($column, $schema_columns, true) || !is_scalar($value)) {
                        return new WP_Error('takka_bridge_sr_where', 'Invalid where filter.', ['status' => 400]);
                    }
                    if (in_array($column, ['user_login', 'user_pass', 'user_email'], true)) {
                        return new WP_Error('takka_bridge_sr_where_sensitive', 'Sensitive user columns are blocked.', ['status' => 403]);
                    }
                    $where[$column] = $value;
                }
            }
            $targets[] = [
                'table' => $table,
                'primary_key' => $allowed[$table]['primary_key'],
                'columns' => $columns,
                'where' => $where,
                'guard_column' => $allowed[$table]['guard_column'],
            ];
        }
        return $targets;
    }

    private static function table_columns(string $table): array
    {
        global $wpdb;
        $rows = $wpdb->get_results("DESCRIBE `{$table}`", ARRAY_A);
        $columns = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (isset($row['Field'])) {
                    $columns[] = (string) $row['Field'];
                }
            }
        }
        return $columns;
    }

    private static function replace_serialized_aware(string $value, string $search, string $replace): array
    {
        if (is_serialized($value)) {
            $unserialized = @unserialize(trim($value), ['allowed_classes' => false]);
            if ($unserialized === false && trim($value) !== 'b:0;') {
                return [$value, 0, false];
            }
            [$next, $count, $unsupported] = self::replace_recursive($unserialized, $search, $replace);
            if ($unsupported) {
                return [$value, 0, true];
            }
            return [serialize($next), $count, false];
        }
        $count = 0;
        $next = str_replace($search, $replace, $value, $count);
        return [$next, $count, false];
    }

    private static function replace_recursive($value, string $search, string $replace): array
    {
        if (is_string($value)) {
            $count = 0;
            $next = str_replace($search, $replace, $value, $count);
            return [$next, $count, false];
        }
        if (is_array($value)) {
            $count = 0;
            foreach ($value as $key => $child) {
                [$next, $child_count, $unsupported] = self::replace_recursive($child, $search, $replace);
                if ($unsupported) {
                    return [$value, 0, true];
                }
                $value[$key] = $next;
                $count += $child_count;
            }
            return [$value, $count, false];
        }
        if (is_object($value)) {
            if ($value instanceof __PHP_Incomplete_Class) {
                return [$value, 0, true];
            }
            $count = 0;
            foreach (get_object_vars($value) as $key => $child) {
                [$next, $child_count, $unsupported] = self::replace_recursive($child, $search, $replace);
                if ($unsupported) {
                    return [$value, 0, true];
                }
                $value->{$key} = $next;
                $count += $child_count;
            }
            return [$value, $count, false];
        }
        return [$value, 0, false];
    }

    private static function is_sensitive_key(string $key): bool
    {
        return (bool) preg_match('/(?:pass(?:word|wd)?|secret|token|api[_-]?key|private[_-]?key|auth[_-]?key|client[_-]?secret)/i', $key);
    }

    private static function excerpt(string $value): string
    {
        $flat = preg_replace('/\s+/u', ' ', $value);
        if (!is_string($flat)) {
            $flat = $value;
        }
        if (function_exists('mb_strlen') && mb_strlen($flat, 'UTF-8') > 160) {
            return mb_substr($flat, 0, 157, 'UTF-8') . '...';
        }
        return strlen($flat) > 160 ? substr($flat, 0, 157) . '...' : $flat;
    }

    private static function required_string(array $params, string $key, bool $trim = true)
    {
        if (!array_key_exists($key, $params) || !is_string($params[$key])) {
            return new WP_Error('takka_bridge_required_string', "{$key} must be a string.", ['status' => 400]);
        }
        $value = $trim ? trim($params[$key]) : $params[$key];
        if ($trim && $value === '') {
            return new WP_Error('takka_bridge_required_string_empty', "{$key} must not be empty.", ['status' => 400]);
        }
        return $value;
    }
}
