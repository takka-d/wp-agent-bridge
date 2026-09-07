<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Small-command self-update transport.
 *
 * Downloads only the official public source repository at an exact commit,
 * reconstructs the complete plugin manifest locally, verifies the expected
 * aggregate SHA-256, then delegates the actual replacement to the existing
 * full-manifest safety gate.
 */
final class TakKa_WordPress_Bridge_V06_Self_Update_Source
{
    private const SOURCE_REPOSITORY = 'takka-d/wp-agent-bridge';
    private const SOURCE_ARCHIVE_BASE = 'https://codeload.github.com/takka-d/wp-agent-bridge/zip/';
    private const MAX_ARCHIVE_BYTES = 8388608;
    private const MAX_ARCHIVE_ENTRIES = 2000;
    private const MAX_ARCHIVE_UNCOMPRESSED_BYTES = 33554432;
    private const MAX_PLUGIN_FILES = 200;
    private const MAX_PLUGIN_BYTES = 2097152;

    public static function capabilities(): array
    {
        return [
            'source_repository' => self::SOURCE_REPOSITORY,
            'source_transport' => 'pinned-github-codeload',
            'source_commit_pinned' => true,
            'manifest_sha256_required' => true,
            'full_manifest_reconstructed_locally' => true,
            'delegates_to_safe_apply' => true,
            'max_archive_bytes' => self::MAX_ARCHIVE_BYTES,
            'max_plugin_files' => self::MAX_PLUGIN_FILES,
            'max_plugin_decoded_bytes' => self::MAX_PLUGIN_BYTES,
        ];
    }

    public static function apply(array $params)
    {
        if (empty($params['confirm'])) {
            return new WP_Error('takka_bridge_confirmation_required', 'Bridge source self-update requires confirm=true.', ['status' => 400]);
        }

        $source_commit = isset($params['source_commit']) && is_string($params['source_commit'])
            ? strtolower(trim($params['source_commit']))
            : '';
        if (!preg_match('/^[a-f0-9]{40}$/', $source_commit)) {
            return new WP_Error('takka_bridge_self_update_source_commit', 'source_commit must be a full 40-character Git commit SHA.', ['status' => 400]);
        }

        $target_version = isset($params['target_version']) && is_string($params['target_version'])
            ? trim($params['target_version'])
            : '';
        if ($target_version === '' || strlen($target_version) > 64 || !preg_match('/^[0-9A-Za-z][0-9A-Za-z._+-]*$/', $target_version)) {
            return new WP_Error('takka_bridge_self_update_source_version', 'target_version is missing or invalid.', ['status' => 400]);
        }

        $expected_manifest_sha = isset($params['manifest_sha256']) && is_string($params['manifest_sha256'])
            ? strtolower(trim($params['manifest_sha256']))
            : '';
        if (!preg_match('/^[a-f0-9]{64}$/', $expected_manifest_sha)) {
            return new WP_Error('takka_bridge_self_update_source_manifest_sha', 'manifest_sha256 must be a SHA-256 hex digest.', ['status' => 400]);
        }

        $expected_file_count = null;
        if (array_key_exists('expected_file_count', $params)) {
            $expected_file_count = (int) $params['expected_file_count'];
            if ($expected_file_count < 1 || $expected_file_count > self::MAX_PLUGIN_FILES) {
                return new WP_Error('takka_bridge_self_update_source_file_count', 'expected_file_count is outside the supported range.', ['status' => 400]);
            }
        }

        $download = self::download_archive($source_commit);
        if (is_wp_error($download)) {
            return $download;
        }

        $temp_zip = $download['path'];
        $extract_root = '';
        try {
            $package = self::extract_package($temp_zip);
            if (is_wp_error($package)) {
                return $package;
            }
            $extract_root = $package['extract_root'];

            $manifest = self::build_manifest($package['plugin_root']);
            if (is_wp_error($manifest)) {
                return $manifest;
            }
            if ($expected_file_count !== null && count($manifest['files']) !== $expected_file_count) {
                return new WP_Error(
                    'takka_bridge_self_update_source_file_count_mismatch',
                    'Downloaded source package file count does not match expected_file_count.',
                    ['status' => 409, 'expected_file_count' => $expected_file_count, 'actual_file_count' => count($manifest['files'])]
                );
            }
            if (!hash_equals($expected_manifest_sha, $manifest['manifest_sha256'])) {
                return new WP_Error(
                    'takka_bridge_self_update_source_manifest_mismatch',
                    'Downloaded source package manifest does not match manifest_sha256.',
                    ['status' => 409, 'expected_manifest_sha256' => $expected_manifest_sha, 'actual_manifest_sha256' => $manifest['manifest_sha256']]
                );
            }

            $current = self::existing_files();
            if (is_wp_error($current)) {
                return $current;
            }
            $submitted = array_map(static function (array $entry): string {
                return (string) $entry['path'];
            }, $manifest['files']);
            $delete_paths = array_values(array_diff($current, $submitted));
            sort($delete_paths, SORT_STRING);
            if ($delete_paths && (($params['confirm_delete_paths'] ?? null) !== true)) {
                return new WP_Error(
                    'takka_bridge_self_update_delete_confirmation_required',
                    'Downloaded source package would delete existing plugin files; confirm_delete_paths=true is required.',
                    ['status' => 409, 'delete_paths' => $delete_paths]
                );
            }

            $expanded = [
                'confirm' => true,
                'expected_current_version' => isset($params['expected_current_version']) && is_string($params['expected_current_version'])
                    ? trim($params['expected_current_version'])
                    : '',
                'target_version' => $target_version,
                'allow_same_version' => !empty($params['allow_same_version']),
                'full_manifest' => true,
                'manifest_sha256' => $manifest['manifest_sha256'],
                'delete_paths' => $delete_paths,
                'files' => $manifest['files'],
            ];
            if ($delete_paths) {
                $expanded['confirm_delete_paths'] = true;
            }

            $result = TakKa_WordPress_Bridge_V06_Self_Update_Safe::apply($expanded);
            if (is_wp_error($result)) {
                return $result;
            }
            $response = rest_ensure_response($result);
            $data = $response->get_data();
            if (is_array($data)) {
                $data['source_repository'] = self::SOURCE_REPOSITORY;
                $data['source_commit'] = $source_commit;
                $data['source_transport'] = 'pinned-github-codeload';
                $response->set_data($data);
            }
            return $response;
        } finally {
            if ($extract_root !== '') {
                self::remove_tree($extract_root);
            }
            if (is_string($temp_zip) && $temp_zip !== '') {
                @unlink($temp_zip);
            }
        }
    }

    private static function download_archive(string $source_commit)
    {
        $response = wp_safe_remote_get(self::SOURCE_ARCHIVE_BASE . rawurlencode($source_commit), [
            'timeout' => 30,
            'redirection' => 2,
            'limit_response_size' => self::MAX_ARCHIVE_BYTES + 1,
            'headers' => [
                'Accept' => 'application/zip',
                'User-Agent' => 'WP-Agent-Bridge-Self-Update',
            ],
        ]);
        if (is_wp_error($response)) {
            return new WP_Error('takka_bridge_self_update_source_download', 'Could not download the pinned WP Agent Bridge source archive.', ['status' => 502, 'error' => $response->get_error_message()]);
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if ($status !== 200 || !is_string($body) || $body === '') {
            return new WP_Error('takka_bridge_self_update_source_http', 'Pinned source archive returned an unexpected HTTP response.', ['status' => 502, 'http_status' => $status]);
        }
        if (strlen($body) > self::MAX_ARCHIVE_BYTES) {
            return new WP_Error('takka_bridge_self_update_source_archive_size', 'Pinned source archive exceeds the byte limit.', ['status' => 413, 'max_bytes' => self::MAX_ARCHIVE_BYTES]);
        }

        $temp = wp_tempnam('wp-agent-bridge-source-' . substr($source_commit, 0, 12) . '.zip');
        if (!is_string($temp) || $temp === '' || file_put_contents($temp, $body) === false) {
            if (is_string($temp) && $temp !== '') {
                @unlink($temp);
            }
            return new WP_Error('takka_bridge_self_update_source_tempfile', 'Could not stage the pinned source archive.', ['status' => 500]);
        }
        return ['path' => $temp, 'bytes' => strlen($body)];
    }

    private static function extract_package(string $archive_path)
    {
        require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
        $archive = new PclZip($archive_path);
        $entries = $archive->listContent();
        if (!is_array($entries) || !$entries) {
            return new WP_Error('takka_bridge_self_update_source_archive', 'Pinned source archive could not be inspected.', ['status' => 502]);
        }
        if (count($entries) > self::MAX_ARCHIVE_ENTRIES) {
            return new WP_Error('takka_bridge_self_update_source_archive_entries', 'Pinned source archive contains too many entries.', ['status' => 413]);
        }

        $uncompressed = 0;
        $prefixes = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = isset($entry['filename']) ? str_replace('\\', '/', (string) $entry['filename']) : '';
            if ($name === '' || $name[0] === '/' || strpos($name, "\0") !== false || preg_match('#(^|/)\.\.(/|$)#', $name)) {
                return new WP_Error('takka_bridge_self_update_source_archive_path', 'Pinned source archive contains an unsafe path.', ['status' => 400]);
            }
            $uncompressed += max(0, (int) ($entry['size'] ?? 0));
            if ($uncompressed > self::MAX_ARCHIVE_UNCOMPRESSED_BYTES) {
                return new WP_Error('takka_bridge_self_update_source_archive_uncompressed', 'Pinned source archive expands beyond the extraction byte limit.', ['status' => 413]);
            }
            if (preg_match('#^([^/]+)/plugin/wp-agent-bridge/takka-wordpress-bridge\.php$#', $name, $match)) {
                $prefixes[$match[1]] = true;
            }
        }
        if (count($prefixes) !== 1) {
            return new WP_Error('takka_bridge_self_update_source_package_root', 'Pinned source archive does not contain exactly one WP Agent Bridge plugin root.', ['status' => 409]);
        }
        $prefix = (string) array_key_first($prefixes);

        $extract_root = trailingslashit(get_temp_dir()) . 'wpab-self-update-' . wp_generate_uuid4();
        if (!wp_mkdir_p($extract_root)) {
            return new WP_Error('takka_bridge_self_update_source_extract_dir', 'Could not create a temporary source extraction directory.', ['status' => 500]);
        }
        $result = $archive->extract(PCLZIP_OPT_PATH, $extract_root, PCLZIP_OPT_REPLACE_NEWER);
        if (!is_array($result) || !$result) {
            self::remove_tree($extract_root);
            return new WP_Error('takka_bridge_self_update_source_extract', 'Could not extract the pinned source archive.', ['status' => 502]);
        }

        $plugin_root = $extract_root . '/' . $prefix . '/plugin/wp-agent-bridge';
        if (!is_dir($plugin_root) || !is_file($plugin_root . '/takka-wordpress-bridge.php')) {
            self::remove_tree($extract_root);
            return new WP_Error('takka_bridge_self_update_source_plugin_root', 'Extracted archive does not contain the expected plugin tree.', ['status' => 409]);
        }
        return ['extract_root' => $extract_root, 'plugin_root' => $plugin_root];
    }

    private static function build_manifest(string $root)
    {
        $entries = [];
        $rows = [];
        $total = 0;
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
                    return new WP_Error('takka_bridge_self_update_source_path_escape', 'Downloaded package escaped the expected plugin root.', ['status' => 409]);
                }
                $relative = self::normalize_path(substr($absolute, strlen($prefix)));
                if ($relative === null) {
                    return new WP_Error('takka_bridge_self_update_source_path', 'Downloaded package contains an unsupported plugin path.', ['status' => 409]);
                }
                $data = file_get_contents($info->getPathname());
                if (!is_string($data)) {
                    return new WP_Error('takka_bridge_self_update_source_read', 'Could not read a file from the downloaded package.', ['status' => 500, 'path' => $relative]);
                }
                $total += strlen($data);
                if ($total > self::MAX_PLUGIN_BYTES) {
                    return new WP_Error('takka_bridge_self_update_source_decoded_size', 'Downloaded plugin tree exceeds the self-update decoded byte limit.', ['status' => 413]);
                }
                $sha = hash('sha256', $data);
                $entries[$relative] = ['path' => $relative, 'sha256' => $sha, 'data_b64' => base64_encode($data)];
                $rows[$relative] = ['sha256' => $sha, 'bytes' => strlen($data)];
            }
        } catch (Throwable $e) {
            return new WP_Error('takka_bridge_self_update_source_scan', 'Could not scan the downloaded plugin tree.', ['status' => 500, 'error' => $e->getMessage()]);
        }
        if (!$entries || count($entries) > self::MAX_PLUGIN_FILES) {
            return new WP_Error('takka_bridge_self_update_source_files', 'Downloaded plugin tree contains an invalid number of files.', ['status' => 413, 'file_count' => count($entries)]);
        }

        ksort($entries, SORT_STRING);
        ksort($rows, SORT_STRING);
        $canonical = '';
        foreach ($rows as $path => $row) {
            $canonical .= $path . "\0" . $row['sha256'] . "\0" . (string) $row['bytes'] . "\n";
        }
        return [
            'files' => array_values($entries),
            'manifest_sha256' => hash('sha256', $canonical),
            'decoded_bytes' => $total,
        ];
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
                    return new WP_Error('takka_bridge_self_update_source_current_path_escape', 'Current plugin file escaped the expected root.', ['status' => 500]);
                }
                $relative = self::normalize_path(substr($absolute, strlen($prefix)));
                if ($relative === null) {
                    return new WP_Error('takka_bridge_self_update_source_current_path', 'Current plugin contains an unsupported path.', ['status' => 500]);
                }
                $files[] = $relative;
            }
        } catch (Throwable $e) {
            return new WP_Error('takka_bridge_self_update_source_current_scan', 'Could not scan current plugin files.', ['status' => 500, 'error' => $e->getMessage()]);
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

    private static function remove_tree(string $path): void
    {
        if ($path === '' || !is_dir($path)) {
            return;
        }
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $info) {
                if ($info->isDir() && !$info->isLink()) {
                    @rmdir($info->getPathname());
                } else {
                    @unlink($info->getPathname());
                }
            }
            @rmdir($path);
        } catch (Throwable $e) {
            // Best-effort cleanup only.
        }
    }
}
