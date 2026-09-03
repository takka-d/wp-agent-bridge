<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Safety gate around the legacy self-update writer.
 *
 * The underlying writer replaces the submitted files and treats omitted
 * existing files as stale. This wrapper makes omission explicit: callers must
 * declare a full manifest, and every omitted current file must be listed in
 * delete_paths with a separate deletion confirmation.
 */
final class TakKa_WordPress_Bridge_V06_Self_Update_Safe
{
    public static function capabilities(): array
    {
        $caps = TakKa_WordPress_Bridge_V06_Self_Update::capabilities();
        $caps['full_manifest_required'] = true;
        $caps['partial_manifest_allowed'] = false;
        $caps['explicit_delete_paths_required'] = true;
        $caps['confirm_delete_paths_required'] = true;
        $caps['required_php_dependencies_checked'] = true;
        return $caps;
    }

    public static function apply(array $params)
    {
        $validated = self::validate_complete_manifest($params);
        if (is_wp_error($validated)) {
            return $validated;
        }
        return TakKa_WordPress_Bridge_V06_Self_Update::apply($params);
    }

    private static function validate_complete_manifest(array $params)
    {
        if (($params['full_manifest'] ?? null) !== true) {
            return new WP_Error(
                'takka_bridge_self_update_full_manifest_required',
                'Self-update requires full_manifest=true. Partial manifests are not accepted.',
                ['status' => 400]
            );
        }
        if (!isset($params['files']) || !is_array($params['files']) || !$params['files']) {
            return new WP_Error('takka_bridge_self_update_manifest_files', 'files must be a non-empty array.', ['status' => 400]);
        }

        $submitted = [];
        $decoded_php = [];
        foreach ($params['files'] as $entry) {
            if (!is_array($entry) || !isset($entry['path']) || !is_string($entry['path'])) {
                return new WP_Error('takka_bridge_self_update_manifest_entry', 'Each manifest file entry must contain a path.', ['status' => 400]);
            }
            $path = self::normalize_path($entry['path']);
            if ($path === null) {
                return new WP_Error('takka_bridge_self_update_path', 'Manifest contains an invalid file path.', ['status' => 400]);
            }
            if (isset($submitted[$path])) {
                return new WP_Error('takka_bridge_self_update_duplicate_path', 'Manifest contains a duplicate file path.', ['status' => 400, 'path' => $path]);
            }
            $submitted[$path] = true;

            if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'php') {
                $raw = isset($entry['data_b64']) && is_string($entry['data_b64'])
                    ? base64_decode($entry['data_b64'], true)
                    : false;
                if (!is_string($raw)) {
                    return new WP_Error('takka_bridge_self_update_base64', 'Manifest PHP content is not valid Base64.', ['status' => 400, 'path' => $path]);
                }
                $decoded_php[$path] = $raw;
            }
        }
        ksort($submitted, SORT_STRING);

        if (!isset($submitted['takka-wordpress-bridge.php'])) {
            return new WP_Error('takka_bridge_self_update_bootstrap_missing', 'Manifest must contain takka-wordpress-bridge.php.', ['status' => 400]);
        }

        $current = self::existing_files();
        if (is_wp_error($current)) {
            return $current;
        }
        $missing_current = array_values(array_diff($current, array_keys($submitted)));
        sort($missing_current, SORT_STRING);

        $delete_paths = self::normalize_delete_paths($params['delete_paths'] ?? []);
        if (is_wp_error($delete_paths)) {
            return $delete_paths;
        }
        if ($missing_current !== $delete_paths) {
            return new WP_Error(
                'takka_bridge_self_update_manifest_incomplete',
                'Every current plugin file omitted from a full manifest must be explicitly listed in delete_paths.',
                [
                    'status' => 400,
                    'omitted_current_files' => $missing_current,
                    'declared_delete_paths' => $delete_paths,
                ]
            );
        }
        if ($delete_paths && (($params['confirm_delete_paths'] ?? null) !== true)) {
            return new WP_Error(
                'takka_bridge_self_update_delete_confirmation_required',
                'Deleting existing plugin files requires confirm_delete_paths=true.',
                ['status' => 400, 'delete_paths' => $delete_paths]
            );
        }

        $dependency_check = self::validate_required_php_dependencies($decoded_php, $submitted);
        if (is_wp_error($dependency_check)) {
            return $dependency_check;
        }

        return true;
    }

    private static function normalize_delete_paths($value)
    {
        if ($value === null || $value === []) {
            return [];
        }
        if (!is_array($value)) {
            return new WP_Error('takka_bridge_self_update_delete_paths', 'delete_paths must be an array.', ['status' => 400]);
        }
        $paths = [];
        foreach ($value as $path) {
            if (!is_string($path)) {
                return new WP_Error('takka_bridge_self_update_delete_paths', 'delete_paths contains a non-string path.', ['status' => 400]);
            }
            $normalized = self::normalize_path($path);
            if ($normalized === null) {
                return new WP_Error('takka_bridge_self_update_delete_paths', 'delete_paths contains an invalid path.', ['status' => 400, 'path' => $path]);
            }
            $paths[$normalized] = true;
        }
        $paths = array_keys($paths);
        sort($paths, SORT_STRING);
        return $paths;
    }

    private static function validate_required_php_dependencies(array $decoded_php, array $submitted)
    {
        foreach ($decoded_php as $path => $code) {
            // Package files use literal __DIR__ relative requires. Validate the
            // complete closure so a bootstrap cannot reference a file omitted
            // from the submitted package.
            if (!preg_match_all(
                '/\b(?:require|require_once|include|include_once)\s*\(?\s*__DIR__\s*\.\s*[\'\"]([^\'\"]+)[\'\"]\s*\)?\s*;/i',
                $code,
                $matches
            )) {
                continue;
            }
            $base = dirname($path);
            foreach ($matches[1] as $relative) {
                $candidate = ($base === '.' ? '' : $base . '/') . ltrim((string) $relative, '/');
                $candidate = self::normalize_path($candidate);
                if ($candidate === null) {
                    return new WP_Error(
                        'takka_bridge_self_update_required_path_invalid',
                        'A PHP file contains an invalid literal __DIR__ dependency.',
                        ['status' => 400, 'source' => $path, 'dependency' => $relative]
                    );
                }
                if (!isset($submitted[$candidate])) {
                    return new WP_Error(
                        'takka_bridge_self_update_required_file_missing',
                        'Full manifest is missing a PHP file required by the submitted package.',
                        ['status' => 400, 'source' => $path, 'required_file' => $candidate]
                    );
                }
            }
        }
        return true;
    }

    private static function existing_files()
    {
        $root = dirname(__DIR__);
        $files = [];
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            $prefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
            foreach ($iterator as $info) {
                if (!$info->isFile() || $info->isLink()) {
                    continue;
                }
                $absolute = str_replace('\\', '/', $info->getPathname());
                if (strpos($absolute, $prefix) !== 0) {
                    return new WP_Error('takka_bridge_self_update_path_escape', 'Plugin file escaped the expected root.', ['status' => 500]);
                }
                $relative = self::normalize_path(substr($absolute, strlen($prefix)));
                if ($relative === null) {
                    return new WP_Error('takka_bridge_self_update_existing_path', 'Current plugin contains an unsupported path.', ['status' => 500]);
                }
                $files[] = $relative;
            }
        } catch (Throwable $e) {
            return new WP_Error('takka_bridge_self_update_scan', 'Could not scan current plugin files.', ['status' => 500, 'error' => $e->getMessage()]);
        }
        sort($files, SORT_STRING);
        return $files;
    }

    private static function normalize_path(string $path): ?string
    {
        $path = ltrim(str_replace('\\', '/', trim($path)), '/');
        if ($path === '' || strpos($path, "\0") !== false) {
            return null;
        }
        $parts = explode('/', $path);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..' || !preg_match('/^[A-Za-z0-9._-]+$/', $part)) {
                return null;
            }
        }
        return implode('/', $parts);
    }
}
