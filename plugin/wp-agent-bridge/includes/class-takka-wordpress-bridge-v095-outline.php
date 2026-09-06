<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TakKa_WordPress_Bridge_V095_Outline
{
    private const MAX_FILE_BYTES = 2097152;
    private const MAX_ITEMS = 800;
    private const EXTENSIONS = ['php', 'js', 'css', 'html', 'htm', 'json', 'svg', 'txt'];

    public static function extensions(): array
    {
        return self::EXTENSIONS;
    }

    public static function outline(array $params)
    {
        $resolved = self::resolve($params);
        if (is_wp_error($resolved)) {
            return $resolved;
        }
        $content = self::content($resolved['file']);
        if (is_wp_error($content)) {
            return $content;
        }
        $extension = strtolower(pathinfo($resolved['file'], PATHINFO_EXTENSION));
        $limit = isset($params['max_items']) ? max(1, min(self::MAX_ITEMS, (int) $params['max_items'])) : 300;
        $items = self::extract($content, $extension, $limit);
        if (is_wp_error($items)) {
            return $items;
        }
        usort($items, static function (array $a, array $b): int {
            return ((int) ($a['line'] ?? 0)) <=> ((int) ($b['line'] ?? 0));
        });
        $counts = [];
        foreach ($items as $item) {
            $kind = (string) ($item['kind'] ?? 'other');
            $counts[$kind] = ($counts[$kind] ?? 0) + 1;
        }
        return rest_ensure_response([
            'ok' => true,
            'scope' => $resolved['scope'],
            'draft_id' => $resolved['draft_id'],
            'path' => self::relative($resolved['root'], $resolved['file']),
            'extension' => $extension,
            'bytes' => strlen($content),
            'sha256' => hash('sha256', $content),
            'counts' => $counts,
            'items' => $items,
            'truncated' => count($items) >= $limit,
        ]);
    }

    public static function read_range(array $params)
    {
        $resolved = self::resolve($params);
        if (is_wp_error($resolved)) {
            return $resolved;
        }
        $content = self::content($resolved['file']);
        if (is_wp_error($content)) {
            return $content;
        }
        $lines = preg_split('/\R/u', $content);
        if (!is_array($lines)) {
            return new WP_Error('takka_bridge_v095_lines', 'Could not split the file into lines.', ['status' => 500]);
        }
        $total = count($lines);
        $start = isset($params['start_line']) ? max(1, (int) $params['start_line']) : 1;
        if ($start > max(1, $total)) {
            return new WP_Error('takka_bridge_v095_range', 'start_line is beyond the end of the file.', [
                'status' => 416,
                'total_lines' => $total,
            ]);
        }
        $requested_end = isset($params['end_line']) ? max($start, (int) $params['end_line']) : $start + 199;
        $end = min($total, min($requested_end, $start + 499));
        $selected = array_slice($lines, $start - 1, $end - $start + 1);
        $numbered = [];
        foreach ($selected as $offset => $line) {
            $numbered[] = ['line' => $start + $offset, 'text' => $line];
        }
        return rest_ensure_response([
            'ok' => true,
            'scope' => $resolved['scope'],
            'draft_id' => $resolved['draft_id'],
            'path' => self::relative($resolved['root'], $resolved['file']),
            'bytes' => strlen($content),
            'sha256' => hash('sha256', $content),
            'total_lines' => $total,
            'start_line' => $start,
            'end_line' => $end,
            'lines' => $numbered,
            'text' => implode("\n", $selected),
        ]);
    }

    private static function extract(string $content, string $extension, int $limit)
    {
        if ($extension === 'php') {
            return self::php($content, $limit);
        }
        if ($extension === 'js') {
            return self::regex_lines($content, [
                ['class', '/^\s*(?:export\s+)?class\s+([A-Za-z_$][\w$]*)/'],
                ['function', '/^\s*(?:export\s+)?(?:async\s+)?function\s+([A-Za-z_$][\w$]*)\s*\(/'],
                ['function', '/^\s*(?:export\s+)?(?:const|let|var)\s+([A-Za-z_$][\w$]*)\s*=\s*(?:async\s*)?\([^)]*\)\s*=>/'],
                ['custom-element', '/customElements\.define\(\s*[\'\"]([^\'\"]+)/'],
                ['event-listener', '/addEventListener\(\s*[\'\"]([^\'\"]+)/'],
            ], $limit);
        }
        if ($extension === 'css') {
            return self::css($content, $limit);
        }
        if (in_array($extension, ['html', 'htm', 'svg'], true)) {
            return self::markup($content, $limit);
        }
        if ($extension === 'json') {
            $decoded = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return new WP_Error('takka_bridge_v095_json_parse', 'JSON file could not be parsed.', [
                    'status' => 400,
                    'json_error' => json_last_error_msg(),
                ]);
            }
            $items = [];
            if (is_array($decoded)) {
                foreach (array_keys($decoded) as $key) {
                    if (count($items) >= $limit) {
                        break;
                    }
                    $items[] = ['kind' => 'json-key', 'name' => (string) $key, 'line' => null];
                }
            }
            return $items;
        }
        return [];
    }

    private static function php(string $content, int $limit): array
    {
        try {
            $tokens = token_get_all($content, TOKEN_PARSE);
        } catch (ParseError $e) {
            return [['kind' => 'parse-error', 'name' => $e->getMessage(), 'line' => $e->getLine()]];
        }
        $items = [];
        $class_tokens = [T_CLASS, T_INTERFACE, T_TRAIT];
        if (defined('T_ENUM')) {
            $class_tokens[] = constant('T_ENUM');
        }
        for ($i = 0, $count = count($tokens); $i < $count && count($items) < $limit; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                continue;
            }
            $id = $token[0];
            if (in_array($id, $class_tokens, true)) {
                $name = self::next_name($tokens, $i + 1);
                if ($name !== null) {
                    $kind = $id === T_INTERFACE ? 'interface' : ($id === T_TRAIT ? 'trait' : 'class');
                    if (defined('T_ENUM') && $id === constant('T_ENUM')) {
                        $kind = 'enum';
                    }
                    $items[] = ['kind' => $kind, 'name' => $name, 'line' => $token[2]];
                }
            } elseif ($id === T_FUNCTION) {
                $name = self::next_name($tokens, $i + 1);
                if ($name !== null) {
                    $items[] = ['kind' => 'function', 'name' => $name, 'line' => $token[2]];
                }
            } elseif ($id === T_CONST) {
                $name = self::next_name($tokens, $i + 1);
                if ($name !== null) {
                    $items[] = ['kind' => 'const', 'name' => $name, 'line' => $token[2]];
                }
            }
        }
        $left = max(0, $limit - count($items));
        foreach (self::regex_lines($content, [
            ['hook-register', '/\b(?:add_action|add_filter)\s*\(\s*[\'\"]([^\'\"]+)/'],
            ['hook-fire', '/\b(?:do_action|apply_filters)\s*\(\s*[\'\"]([^\'\"]+)/'],
            ['rest-route', '/\bregister_rest_route\s*\(\s*[\'\"]([^\'\"]+)[\'\"]\s*,\s*[\'\"]([^\'\"]+)/'],
            ['post-type', '/\bregister_post_type\s*\(\s*[\'\"]([^\'\"]+)/'],
            ['taxonomy', '/\bregister_taxonomy\s*\(\s*[\'\"]([^\'\"]+)/'],
            ['shortcode', '/\badd_shortcode\s*\(\s*[\'\"]([^\'\"]+)/'],
        ], $left) as $item) {
            $items[] = $item;
        }
        return $items;
    }

    private static function next_name(array $tokens, int $start): ?string
    {
        for ($i = $start, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token)) {
                if ($token[0] === T_STRING) {
                    return $token[1];
                }
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
            } elseif ($token === '&') {
                continue;
            }
            return null;
        }
        return null;
    }

    private static function regex_lines(string $content, array $patterns, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }
        $items = [];
        $lines = preg_split('/\R/u', $content);
        if (!is_array($lines)) {
            return $items;
        }
        foreach ($lines as $index => $line) {
            foreach ($patterns as $pattern) {
                if (count($items) >= $limit) {
                    break 2;
                }
                $matches = [];
                if (@preg_match($pattern[1], $line, $matches) === 1) {
                    $name = isset($matches[2]) && $matches[2] !== '' ? $matches[1] . $matches[2] : ($matches[1] ?? trim($line));
                    $items[] = ['kind' => $pattern[0], 'name' => (string) $name, 'line' => $index + 1];
                }
            }
        }
        return $items;
    }

    private static function css(string $content, int $limit): array
    {
        $items = [];
        $lines = preg_split('/\R/u', $content);
        if (!is_array($lines)) {
            return $items;
        }
        $buffer = '';
        $start = 1;
        foreach ($lines as $index => $line) {
            if ($buffer === '') {
                $start = $index + 1;
            }
            $buffer .= ' ' . trim($line);
            if (strpos($line, '{') === false) {
                if (strlen($buffer) > 4096) {
                    $buffer = '';
                }
                continue;
            }
            $head = trim((string) strstr($buffer, '{', true));
            $buffer = '';
            if ($head === '' || strpos($head, '/*') === 0) {
                continue;
            }
            $kind = 'selector';
            if (strpos($head, '@media') === 0) {
                $kind = 'media';
            } elseif (strpos($head, '@supports') === 0) {
                $kind = 'supports';
            } elseif (preg_match('/^@(?:-\w+-)?keyframes\b/i', $head)) {
                $kind = 'keyframes';
            } elseif ($head[0] === '@') {
                $kind = 'at-rule';
            }
            $items[] = ['kind' => $kind, 'name' => mb_substr($head, 0, 500), 'line' => $start];
            if (count($items) >= $limit) {
                break;
            }
        }
        return $items;
    }

    private static function markup(string $content, int $limit): array
    {
        $items = [];
        $lines = preg_split('/\R/u', $content);
        if (!is_array($lines)) {
            return $items;
        }
        foreach ($lines as $index => $line) {
            if (!preg_match_all('/<(h[1-6]|header|nav|main|section|article|aside|footer|form|script|style|svg)\b([^>]*)>/i', $line, $matches, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($matches as $match) {
                $name = strtolower($match[1]);
                $attrs = (string) ($match[2] ?? '');
                if (preg_match('/\bid=[\'\"]([^\'\"]+)/i', $attrs, $id)) {
                    $name .= '#' . $id[1];
                }
                if (preg_match('/\bclass=[\'\"]([^\'\"]+)/i', $attrs, $class)) {
                    $classes = preg_split('/\s+/', trim($class[1]));
                    if (is_array($classes) && !empty($classes[0])) {
                        $name .= '.' . $classes[0];
                    }
                }
                $items[] = ['kind' => 'landmark', 'name' => $name, 'line' => $index + 1];
                if (count($items) >= $limit) {
                    break 2;
                }
            }
        }
        return $items;
    }

    private static function resolve(array $params)
    {
        $path = is_string($params['path'] ?? null) ? trim($params['path']) : '';
        if ($path === '') {
            return new WP_Error('takka_bridge_v095_path', 'path is required.', ['status' => 400]);
        }
        $scope = is_string($params['scope'] ?? null) ? strtolower(trim($params['scope'])) : 'active';
        $draft_id = null;
        if ($scope === 'active') {
            $root = realpath(get_stylesheet_directory());
        } elseif ($scope === 'draft') {
            $draft_id = is_string($params['draft_id'] ?? null) ? trim($params['draft_id']) : '';
            $drafts = get_option('takka_bridge_draft_themes', []);
            if ($draft_id === '' || !is_array($drafts) || !isset($drafts[$draft_id])) {
                return new WP_Error('takka_bridge_v095_draft', 'Theme draft was not found.', ['status' => 404]);
            }
            $slug = basename((string) ($drafts[$draft_id]['slug'] ?? ''));
            $root = $slug !== '' ? realpath(rtrim(get_theme_root(), '/\\') . DIRECTORY_SEPARATOR . $slug) : false;
        } else {
            return new WP_Error('takka_bridge_v095_scope', 'scope must be active or draft.', ['status' => 400]);
        }
        if ($root === false) {
            return new WP_Error('takka_bridge_v095_theme_root', 'Theme directory was not found.', ['status' => 404]);
        }
        $relative = str_replace('\\', '/', $path);
        if ($relative === '' || $relative[0] === '/' || strpos($relative, "\0") !== false || preg_match('~(^|/)\.\.(/|$)~', $relative)) {
            return new WP_Error('takka_bridge_v095_path_escape', 'Unsafe theme path.', ['status' => 403]);
        }
        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        if (!in_array($extension, self::EXTENSIONS, true)) {
            return new WP_Error('takka_bridge_v095_file_type', 'File type is not supported by structural inspection.', ['status' => 403]);
        }
        $file = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
        if ($file === false || !is_file($file)) {
            return new WP_Error('takka_bridge_v095_file_missing', 'Theme file was not found.', ['status' => 404]);
        }
        $prefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
        if (strpos(str_replace('\\', '/', $file), $prefix) !== 0) {
            return new WP_Error('takka_bridge_v095_path_escape', 'Path escapes the theme directory.', ['status' => 403]);
        }
        return ['scope' => $scope, 'draft_id' => $draft_id, 'root' => $root, 'file' => $file];
    }

    private static function content(string $file)
    {
        $size = filesize($file);
        if ($size === false || $size > self::MAX_FILE_BYTES) {
            return new WP_Error('takka_bridge_v095_file_size', 'Theme file exceeds inspection size limit.', [
                'status' => 413,
                'max_bytes' => self::MAX_FILE_BYTES,
            ]);
        }
        $content = file_get_contents($file);
        return is_string($content) ? $content : new WP_Error('takka_bridge_v095_file_read', 'Could not read theme file.', ['status' => 500]);
    }

    private static function relative(string $root, string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $path = str_replace('\\', '/', $path);
        return strpos($path, $root . '/') === 0 ? substr($path, strlen($root) + 1) : basename($path);
    }
}
