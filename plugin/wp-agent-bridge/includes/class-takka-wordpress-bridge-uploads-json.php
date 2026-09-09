<?php

if (!defined('ABSPATH')) exit;

/**
 * Guarded, byte-preserving replacement of existing uploads JSON.
 * No public REST route: called only by the authenticated operation router.
 */
final class TakKa_WordPress_Bridge_Uploads_JSON
{
    private const MAX_BYTES = 1048576;

    private static function error(string $code, string $message, int $status = 400)
    {
        return new WP_Error('wpab_uploads_json_' . $code, $message, ['status' => $status]);
    }

    private static function resolve(array $params)
    {
        $relative = $params['path'] ?? null;
        if (!is_string($relative) || $relative === '' || strlen($relative) > 1024
            || preg_match('~[\\\\:\x00-\x1f]~', $relative)
            || substr($relative, 0, 1) === '/'
            || preg_match('~(^|/)(\\.\\.?|)(/|$)~', $relative)
            || strtolower(pathinfo($relative, PATHINFO_EXTENSION)) !== 'json') {
            return self::error('path', 'path must name an existing .json file relative to uploads, without traversal.');
        }
        $uploads = wp_upload_dir();
        $root = empty($uploads['error']) ? realpath($uploads['basedir']) : false;
        if ($root === false) return self::error('root', 'Uploads directory is unavailable.', 503);
        $path = $root;
        foreach (explode('/', $relative) as $part) {
            $path .= DIRECTORY_SEPARATOR . $part;
            if (is_link($path)) return self::error('symlink', 'Symbolic links are not allowed.', 403);
        }
        $real = realpath($path);
        if ($real === false || !is_file($real)) return self::error('missing', 'Existing JSON file not found.', 404);
        if (strpos($real, $root . DIRECTORY_SEPARATOR) !== 0) return self::error('scope', 'File is outside uploads.', 403);
        return ['path' => $relative, 'file' => $real, 'root' => $root,
            'backup_key' => 'wpab_json_previous_' . hash('sha256', $real)];
    }

    private static function valid_content($content)
    {
        if (!is_string($content)) return self::error('content', 'content must be a JSON source string.');
        if (strlen($content) > self::MAX_BYTES) return self::error('size', 'JSON exceeds the 1 MiB limit.', 413);
        // Validate only. Never decode/re-encode the saved source: preserve big
        // integers, object/array identity, escaping and whitespace exactly.
        json_decode($content);
        if (json_last_error() !== JSON_ERROR_NONE) return self::error('invalid', 'Invalid JSON: ' . json_last_error_msg());
        return true;
    }

    private static function source(array $target)
    {
        $handle = @fopen($target['file'], 'rb');
        if (!$handle) return self::error('read', 'Could not read JSON file.', 500);
        try {
            $content = stream_get_contents($handle, self::MAX_BYTES + 1);
        } finally {
            fclose($handle);
        }
        $valid = self::valid_content($content);
        return is_wp_error($valid) ? $valid : $content;
    }

    private static function plan(array $target, array $params)
    {
        $valid = self::valid_content($params['content'] ?? null);
        if (is_wp_error($valid)) return $valid;
        $before = self::source($target);
        if (is_wp_error($before)) return $before;
        $before_sha = hash('sha256', $before);
        $after_sha = hash('sha256', $params['content']);
        return ['path' => $target['path'], 'before_sha256' => $before_sha,
            'after_sha256' => $after_sha, 'before_bytes' => strlen($before),
            'after_bytes' => strlen($params['content']), 'changed' => $before !== $params['content'],
            'plan_hash' => hash('sha256', $target['file'] . "\0" . $before_sha . "\0" . $after_sha),
            '_before' => $before];
    }

    public static function execute(string $operation, array $params)
    {
        if (!current_user_can('manage_options')) return self::error('permission', 'Administrator permission required.', 403);
        $target = self::resolve($params);
        if (is_wp_error($target)) return $target;
        if ($operation === 'uploads.json.read') {
            if (($params['version'] ?? 'current') === 'previous') {
                $backup = get_option($target['backup_key'], null);
                if (!is_array($backup) || !isset($backup['content'])) return self::error('backup_missing', 'No previous version exists.', 404);
                $content = $backup['content'];
            } elseif (($params['version'] ?? 'current') === 'current') {
                $content = self::source($target);
            } else {
                return self::error('version', 'version must be current or previous.');
            }
            if (is_wp_error($content)) return $content;
            return ['ok' => true, 'status' => 200, 'data' => [
                'path' => $target['path'], 'version' => $params['version'] ?? 'current',
                'content' => $content, 'bytes' => strlen($content), 'sha256' => hash('sha256', $content),
                'max_bytes' => self::MAX_BYTES]];
        }
        if ($operation === 'uploads.json.write_preview') {
            $plan = self::plan($target, $params);
            if (is_wp_error($plan)) return $plan;
            unset($plan['_before']);
            return ['ok' => true, 'status' => 200, 'data' => $plan];
        }
        if ($operation !== 'uploads.json.write_apply') return self::error('operation', 'Unknown JSON operation.');
        if (($params['confirm'] ?? false) !== true) return self::error('confirm', 'Writing JSON requires confirm=true.');

        // One stable lock inode for Bridge JSON writers. Never unlink it:
        // removing a lock file allows overlapping locks on different inodes.
        $lock_path = $target['root'] . DIRECTORY_SEPARATOR . '.wp-agent-bridge-json.lock';
        if (is_link($lock_path)) return self::error('lock_path', 'Invalid lock path.', 403);
        $lock = @fopen($lock_path, 'c');
        if (!$lock) return self::error('lock', 'Could not open JSON write lock.', 503);
        $locked = false;
        $temp = false;
        try {
            $locked = flock($lock, LOCK_EX | LOCK_NB);
            if (!$locked) return self::error('busy', 'Another JSON write is in progress. Read again before retrying.', 409);
            clearstatcache(true);
            $fresh = self::resolve($params);
            if (is_wp_error($fresh)) return $fresh;
            if ($fresh['file'] !== $target['file']) return self::error('changed_path', 'Target path changed.', 409);
            $plan = self::plan($target, $params);
            if (is_wp_error($plan)) return $plan;
            foreach (['expected_before_sha256' => 'before_sha256', 'expected_plan_hash' => 'plan_hash'] as $input => $field) {
                if (!is_string($params[$input] ?? null) || !hash_equals($plan[$field], $params[$input])) {
                    return self::error('conflict', 'File or proposed content changed, or preview hashes are missing.', 409);
                }
            }
            if ($plan['changed']) {
                // Backup stays in the database, not in a publicly served uploads
                // file. Retain one exact previous version per edited path.
                $backup = ['path' => $target['path'], 'content' => $plan['_before'], 'sha256' => $plan['before_sha256']];
                update_option($target['backup_key'], $backup, false);
                if (get_option($target['backup_key']) !== $backup) return self::error('backup', 'Could not verify previous-version backup.', 500);
                $temp = tempnam(dirname($target['file']), '.wpab-json-');
                if ($temp === false || dirname($temp) !== dirname($target['file'])) return self::error('stage', 'Could not create staging file beside target.', 500);
                $written = file_put_contents($temp, $params['content']);
                if ($written !== strlen($params['content']) || hash_file('sha256', $temp) !== $plan['after_sha256']
                    || !chmod($temp, fileperms($target['file']) & 0777)) {
                    return self::error('stage', 'Staged JSON or file permissions could not be verified.', 500);
                }
                clearstatcache(true);
                $fresh = self::resolve($params);
                if (is_wp_error($fresh) || $fresh['file'] !== $target['file']
                    || hash_file('sha256', $target['file']) !== $plan['before_sha256']) {
                    return self::error('conflict', 'Target changed while staging JSON.', 409);
                }
                if (!rename($temp, $target['file'])) return self::error('publish', 'Could not atomically replace JSON.', 500);
                $temp = false;
            }
            clearstatcache(true, $target['file']);
            if (hash_file('sha256', $target['file']) !== $plan['after_sha256']) return self::error('verify', 'Saved JSON verification failed. Read current and previous versions before retrying.', 500);
            unset($plan['_before']);
            $plan['verified'] = true;
            $plan['previous_version_available'] = is_array(get_option($target['backup_key'], null));
            return ['ok' => true, 'status' => 200, 'data' => $plan];
        } finally {
            if ($temp !== false && is_file($temp)) unlink($temp);
            if ($locked) flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
