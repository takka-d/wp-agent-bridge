<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TakKa_WordPress_Bridge_V06_Self_Update
{
    private const OPTION_BACKUPS = 'takka_bridge_self_update_backups';
    private const MAX_FILES = 200;
    private const MAX_BYTES = 2097152;
    private const MAX_BACKUPS = 3;

    private const ALLOWED_EXTENSIONS = [
        'php', 'js', 'css', 'json', 'md', 'txt', 'yml', 'yaml', 'html', 'svg',
    ];

    public static function capabilities(): array
    {
        return [
            'plugin_scope_only' => true,
            'signed_manifest' => true,
            'per_file_sha256' => true,
            'manifest_sha256' => true,
            'php_parse_preflight' => true,
            'backup_before_write' => true,
            'automatic_rollback_on_write_failure' => true,
            'retained_backups' => self::MAX_BACKUPS,
            'max_files' => self::MAX_FILES,
            'max_decoded_bytes' => self::MAX_BYTES,
        ];
    }

    public static function status(): array
    {
        $items = [];
        foreach (self::load_backups() as $backup) {
            $items[] = [
                'id' => $backup['id'] ?? null,
                'version' => $backup['version'] ?? null,
                'created_at' => $backup['created_at'] ?? null,
                'reason' => $backup['reason'] ?? null,
                'file_count' => isset($backup['files']) && is_array($backup['files']) ? count($backup['files']) : 0,
            ];
        }
        return [
            'current_version' => self::current_version(),
            'plugin_directory' => basename(self::root()),
            'backups' => $items,
            'max_backups' => self::MAX_BACKUPS,
        ];
    }

    public static function apply(array $params)
    {
        if (empty($params['confirm'])) {
            return new WP_Error('takka_bridge_confirmation_required', 'Bridge self-update requires confirm=true.', ['status' => 400]);
        }

        $current = self::current_version();
        $expected = isset($params['expected_current_version']) && is_string($params['expected_current_version'])
            ? trim($params['expected_current_version'])
            : '';
        if ($expected !== '' && $expected !== $current) {
            return new WP_Error('takka_bridge_self_update_version_conflict', 'Current Bridge version does not match expected_current_version.', [
                'status' => 409,
                'expected_current_version' => $expected,
                'current_version' => $current,
            ]);
        }

        $manifest = self::validate_manifest($params);
        if (is_wp_error($manifest)) {
            return $manifest;
        }
        $target = $manifest['version'];
        if (empty($params['allow_same_version']) && version_compare($target, $current, '<=')) {
            return new WP_Error('takka_bridge_self_update_not_newer', 'Target Bridge version must be newer than the installed version.', [
                'status' => 409,
                'current_version' => $current,
                'target_version' => $target,
            ]);
        }

        $backup = self::capture_backup('pre-update-' . $target);
        if (is_wp_error($backup)) {
            return $backup;
        }

        $apply = self::replace_files($manifest['files']);
        if (is_wp_error($apply)) {
            $restore = self::restore_record($backup);
            if (is_wp_error($restore)) {
                return new WP_Error('takka_bridge_self_update_and_rollback_failed', 'Self-update failed and automatic rollback also failed.', [
                    'status' => 500,
                    'update_error' => $apply->get_error_message(),
                    'rollback_error' => $restore->get_error_message(),
                    'backup_id' => $backup['id'],
                ]);
            }
            return new WP_Error('takka_bridge_self_update_failed_rolled_back', 'Self-update failed; the previous plugin files were restored.', [
                'status' => 500,
                'update_error' => $apply->get_error_message(),
                'backup_id' => $backup['id'],
            ]);
        }

        self::store_backup($backup);
        return rest_ensure_response([
            'ok' => true,
            'from_version' => $current,
            'to_version' => $target,
            'manifest_sha256' => $manifest['manifest_sha256'],
            'file_count' => count($manifest['files']),
            'decoded_bytes' => $manifest['decoded_bytes'],
            'backup_id' => $backup['id'],
            'next_request_loads_new_code' => true,
        ]);
    }

    public static function rollback(array $params)
    {
        if (empty($params['confirm'])) {
            return new WP_Error('takka_bridge_confirmation_required', 'Bridge self-update rollback requires confirm=true.', ['status' => 400]);
        }
        $backups = self::load_backups();
        if (!$backups) {
            return new WP_Error('takka_bridge_self_update_no_backups', 'No self-update backups are available.', ['status' => 404]);
        }

        $backup_id = isset($params['backup_id']) && is_string($params['backup_id']) ? trim($params['backup_id']) : '';
        $selected = null;
        if ($backup_id === '') {
            $selected = $backups[0];
        } else {
            foreach ($backups as $backup) {
                if (($backup['id'] ?? '') === $backup_id) {
                    $selected = $backup;
                    break;
                }
            }
        }
        if (!is_array($selected)) {
            return new WP_Error('takka_bridge_self_update_backup_not_found', 'Requested self-update backup was not found.', ['status' => 404]);
        }

        $current = self::current_version();
        $safety = self::capture_backup('pre-rollback-' . ($selected['version'] ?? 'unknown'));
        if (!is_wp_error($safety)) {
            self::store_backup($safety);
        }
        $restore = self::restore_record($selected);
        if (is_wp_error($restore)) {
            return $restore;
        }

        return rest_ensure_response([
            'ok' => true,
            'from_version' => $current,
            'to_version' => $selected['version'] ?? null,
            'restored_backup_id' => $selected['id'] ?? null,
            'next_request_loads_restored_code' => true,
        ]);
    }

    private static function validate_manifest(array $params)
    {
        if (!isset($params['files']) || !is_array($params['files']) || !$params['files']) {
            return new WP_Error('takka_bridge_self_update_manifest_files', 'files must be a non-empty array.', ['status' => 400]);
        }
        if (count($params['files']) > self::MAX_FILES) {
            return new WP_Error('takka_bridge_self_update_manifest_files', 'Manifest contains too many files.', ['status' => 413]);
        }

        $declared_manifest_sha = isset($params['manifest_sha256']) && is_string($params['manifest_sha256'])
            ? strtolower(trim($params['manifest_sha256']))
            : '';
        if (!preg_match('/^[a-f0-9]{64}$/', $declared_manifest_sha)) {
            return new WP_Error('takka_bridge_self_update_manifest_sha', 'manifest_sha256 must be a SHA-256 hex digest.', ['status' => 400]);
        }

        $files = [];
        $total = 0;
        foreach ($params['files'] as $entry) {
            if (!is_array($entry)) {
                return new WP_Error('takka_bridge_self_update_manifest_entry', 'Each manifest file entry must be an object.', ['status' => 400]);
            }
            $path = isset($entry['path']) && is_string($entry['path']) ? self::normalize_path($entry['path']) : null;
            if ($path === null) {
                return new WP_Error('takka_bridge_self_update_path', 'Manifest contains an invalid file path.', ['status' => 400]);
            }
            if (isset($files[$path])) {
                return new WP_Error('takka_bridge_self_update_duplicate_path', 'Manifest contains a duplicate file path.', ['status' => 400, 'path' => $path]);
            }
            if (!in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::ALLOWED_EXTENSIONS, true)) {
                return new WP_Error('takka_bridge_self_update_extension', 'Manifest contains a blocked file extension.', ['status' => 400, 'path' => $path]);
            }

            $data = isset($entry['data_b64']) && is_string($entry['data_b64'])
                ? base64_decode($entry['data_b64'], true)
                : false;
            if (!is_string($data)) {
                return new WP_Error('takka_bridge_self_update_base64', 'Manifest file content is not valid Base64.', ['status' => 400, 'path' => $path]);
            }
            $total += strlen($data);
            if ($total > self::MAX_BYTES) {
                return new WP_Error('takka_bridge_self_update_size', 'Decoded self-update manifest exceeds the byte limit.', ['status' => 413]);
            }

            $declared_sha = isset($entry['sha256']) && is_string($entry['sha256']) ? strtolower(trim($entry['sha256'])) : '';
            $actual_sha = hash('sha256', $data);
            if (!preg_match('/^[a-f0-9]{64}$/', $declared_sha) || !hash_equals($declared_sha, $actual_sha)) {
                return new WP_Error('takka_bridge_self_update_file_sha', 'Manifest file SHA-256 does not match decoded content.', [
                    'status' => 400,
                    'path' => $path,
                ]);
            }
            if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'php') {
                $valid = self::validate_php($data, $path);
                if (is_wp_error($valid)) {
                    return $valid;
                }
            }
            $files[$path] = [
                'sha256' => $actual_sha,
                'bytes' => strlen($data),
                'data' => $data,
            ];
        }
        ksort($files, SORT_STRING);

        $actual_manifest_sha = self::manifest_sha($files);
        if (!hash_equals($declared_manifest_sha, $actual_manifest_sha)) {
            return new WP_Error('takka_bridge_self_update_manifest_sha_mismatch', 'manifest_sha256 does not match the validated file set.', [
                'status' => 400,
                'expected' => $declared_manifest_sha,
                'actual' => $actual_manifest_sha,
            ]);
        }
        if (!isset($files['takka-wordpress-bridge.php'])) {
            return new WP_Error('takka_bridge_self_update_bootstrap_missing', 'Manifest must contain takka-wordpress-bridge.php.', ['status' => 400]);
        }

        $version = self::header_version($files['takka-wordpress-bridge.php']['data']);
        if ($version === '') {
            return new WP_Error('takka_bridge_self_update_version_missing', 'Could not read Version from plugin bootstrap.', ['status' => 400]);
        }
        $declared_version = isset($params['target_version']) && is_string($params['target_version']) ? trim($params['target_version']) : '';
        if ($declared_version !== '' && $declared_version !== $version) {
            return new WP_Error('takka_bridge_self_update_version_mismatch', 'target_version does not match the plugin bootstrap Version header.', [
                'status' => 400,
                'target_version' => $declared_version,
                'bootstrap_version' => $version,
            ]);
        }
        return [
            'version' => $version,
            'manifest_sha256' => $actual_manifest_sha,
            'decoded_bytes' => $total,
            'files' => $files,
        ];
    }

    private static function validate_php(string $code, string $path)
    {
        if (strpos($code, '<?php') === false && strpos($code, '<?=') === false) {
            return new WP_Error('takka_bridge_self_update_php_open_tag', 'PHP manifest file does not contain a PHP opening tag.', [
                'status' => 400,
                'path' => $path,
            ]);
        }
        try {
            token_get_all($code, TOKEN_PARSE);
        } catch (Throwable $e) {
            return new WP_Error('takka_bridge_self_update_php_syntax', 'PHP syntax validation failed before self-update.', [
                'status' => 400,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }
        return true;
    }

    private static function manifest_sha(array $files): string
    {
        $canonical = '';
        foreach ($files as $path => $entry) {
            $canonical .= $path . "\0" . $entry['sha256'] . "\0" . (string) $entry['bytes'] . "\n";
        }
        return hash('sha256', $canonical);
    }

    private static function capture_backup(string $reason)
    {
        $paths = self::existing_files();
        if (is_wp_error($paths)) {
            return $paths;
        }
        $files = [];
        $total = 0;
        foreach ($paths as $relative) {
            $data = @file_get_contents(self::root() . '/' . $relative);
            if (!is_string($data)) {
                return new WP_Error('takka_bridge_self_update_backup_read', 'Could not read a plugin file for backup.', ['status' => 500, 'path' => $relative]);
            }
            $total += strlen($data);
            if ($total > self::MAX_BYTES) {
                return new WP_Error('takka_bridge_self_update_backup_size', 'Current plugin is too large for the self-update backup limit.', ['status' => 500]);
            }
            $files[$relative] = base64_encode($data);
        }
        ksort($files, SORT_STRING);
        try {
            $nonce = bin2hex(random_bytes(8));
        } catch (Throwable $e) {
            $nonce = wp_generate_password(16, false, false);
        }
        return [
            'id' => gmdate('YmdHis') . '-' . substr(hash('sha256', $nonce . microtime(true)), 0, 16),
            'version' => self::current_version(),
            'created_at' => time(),
            'reason' => sanitize_key($reason),
            'files' => $files,
        ];
    }

    private static function restore_record(array $backup)
    {
        if (!isset($backup['files']) || !is_array($backup['files']) || !$backup['files']) {
            return new WP_Error('takka_bridge_self_update_backup_invalid', 'Backup record is invalid.', ['status' => 500]);
        }
        $files = [];
        foreach ($backup['files'] as $path => $data_b64) {
            $path = self::normalize_path((string) $path);
            $data = is_string($data_b64) ? base64_decode($data_b64, true) : false;
            if ($path === null || !is_string($data)) {
                return new WP_Error('takka_bridge_self_update_backup_invalid', 'Backup record contains an invalid file.', ['status' => 500]);
            }
            $files[$path] = [
                'sha256' => hash('sha256', $data),
                'bytes' => strlen($data),
                'data' => $data,
            ];
        }
        ksort($files, SORT_STRING);
        return self::replace_files($files);
    }

    private static function replace_files(array $files)
    {
        $root = self::root();
        $existing = self::existing_files();
        if (is_wp_error($existing)) {
            return $existing;
        }

        foreach ($files as $relative => $entry) {
            $destination = $root . '/' . $relative;
            if (!is_dir(dirname($destination)) && !wp_mkdir_p(dirname($destination))) {
                return new WP_Error('takka_bridge_self_update_mkdir', 'Could not create plugin directory during self-update.', ['status' => 500, 'path' => dirname($relative)]);
            }
            try {
                $suffix = bin2hex(random_bytes(4));
            } catch (Throwable $e) {
                $suffix = (string) mt_rand(100000, 999999);
            }
            $temporary = $destination . '.takka-new-' . $suffix;
            $written = @file_put_contents($temporary, $entry['data'], LOCK_EX);
            if ($written !== $entry['bytes']) {
                @unlink($temporary);
                return new WP_Error('takka_bridge_self_update_write', 'Could not write a plugin file during self-update.', ['status' => 500, 'path' => $relative]);
            }
            @chmod($temporary, 0644);
            if (!@rename($temporary, $destination)) {
                @unlink($temporary);
                return new WP_Error('takka_bridge_self_update_rename', 'Could not atomically replace a plugin file during self-update.', ['status' => 500, 'path' => $relative]);
            }
            clearstatcache(true, $destination);
            $verify = @hash_file('sha256', $destination);
            if (!is_string($verify) || !hash_equals($entry['sha256'], $verify)) {
                return new WP_Error('takka_bridge_self_update_verify', 'A written plugin file failed SHA-256 verification.', ['status' => 500, 'path' => $relative]);
            }
        }

        $wanted = array_fill_keys(array_keys($files), true);
        foreach ($existing as $relative) {
            if (!isset($wanted[$relative]) && !@unlink($root . '/' . $relative)) {
                return new WP_Error('takka_bridge_self_update_delete_stale', 'Could not delete a stale plugin file during self-update.', ['status' => 500, 'path' => $relative]);
            }
        }
        self::remove_empty_dirs($root, $root);
        return true;
    }

    private static function existing_files()
    {
        $root = self::root();
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

    private static function remove_empty_dirs(string $directory, string $root): void
    {
        $entries = is_dir($directory) ? @scandir($directory) : false;
        if (!is_array($entries)) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                self::remove_empty_dirs($path, $root);
            }
        }
        $remaining = @scandir($directory);
        if ($directory !== $root && is_array($remaining) && count($remaining) === 2) {
            @rmdir($directory);
        }
    }

    private static function store_backup(array $backup): void
    {
        $backups = self::load_backups();
        array_unshift($backups, $backup);
        update_option(self::OPTION_BACKUPS, array_slice($backups, 0, self::MAX_BACKUPS), false);
    }

    private static function load_backups(): array
    {
        $backups = get_option(self::OPTION_BACKUPS, []);
        return is_array($backups) ? array_values(array_filter($backups, 'is_array')) : [];
    }

    private static function current_version(): string
    {
        $bootstrap = @file_get_contents(self::root() . '/takka-wordpress-bridge.php');
        return is_string($bootstrap) ? self::header_version($bootstrap) : '';
    }

    private static function header_version(string $bootstrap): string
    {
        return preg_match('/^[ \t\/*#@]*Version:\s*([^\r\n]+)/mi', $bootstrap, $matches)
            ? trim($matches[1])
            : '';
    }

    private static function root(): string
    {
        return dirname(__DIR__);
    }
}
