<?php
// Isolated WordPress/GitHub doubles for same-version stale-contract recovery.
$GLOBALS['sync_options'] = [];
$GLOBALS['sync_transients'] = [];
$GLOBALS['sync_files'] = [];
$GLOBALS['sync_reads'] = 0;
$GLOBALS['sync_writes'] = 0;
$GLOBALS['sync_host'] = 'example.test';
function get_file_data($file, $headers, $context) { return ['Version' => '1.1.21']; }
function get_option($key, $default = '') { return $GLOBALS['sync_options'][$key] ?? $default; }
function update_option($key, $value, $autoload = false) { $GLOBALS['sync_options'][$key] = $value; }
function get_transient($key) { return $GLOBALS['sync_transients'][$key] ?? false; }
function set_transient($key, $value, $ttl) { $GLOBALS['sync_transients'][$key] = $value; }
function delete_transient($key) { unset($GLOBALS['sync_transients'][$key]); }
function home_url($path = '/') { return 'https://' . $GLOBALS['sync_host'] . $path; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function is_wp_error($value) { return false; }
final class TakKa_WordPress_Bridge_Direct_Runtime {
    public const RUNTIME_BRANCH = 'wp-agent-bridge-runtime';
    public static function connection() {
        return ['installation_id' => 1, 'repository_id' => 2,
            'repository' => 'owner/runtime-repo', 'runtime_branch' => self::RUNTIME_BRANCH];
    }
}
final class TakKa_WordPress_Bridge_Direct_GitHub {
    public static function installation_token($installation, $repository) { return 'test'; }
    public static function get_text_file($token, $repo, $branch, $path) {
        $GLOBALS['sync_reads']++;
        return $GLOBALS['sync_files'][$path] ?? 'old generated content';
    }
    public static function put_text_file($token, $repo, $branch, $path, $content, $message) {
        $GLOBALS['sync_writes']++;
        $GLOBALS['sync_files'][$path] = $content;
        return ['ok' => true];
    }
}
function verify_runtime_sync($class, $option, $paths) {
    foreach (['1.1.21', '1.1.21:' . hash('sha256', 'old loaded contract')] as $stale) {
        $GLOBALS['sync_options'][$option] = $stale;
        foreach ($paths as $path) $GLOBALS['sync_files'][$path] = 'old generated content';
        $before = $GLOBALS['sync_writes'];
        $class::maybe_sync();
        if ($GLOBALS['sync_writes'] !== $before + count($paths)) {
            throw new RuntimeException('Same-version stale contract did not synchronize.');
        }
        if ($GLOBALS['sync_options'][$option] === $stale) {
            throw new RuntimeException('Stale version stamp was not replaced.');
        }
    }
    $reads = $GLOBALS['sync_reads'];
    $writes = $GLOBALS['sync_writes'];
    $class::maybe_sync();
    if ($GLOBALS['sync_reads'] !== $reads || $GLOBALS['sync_writes'] !== $writes) {
        throw new RuntimeException('Unchanged contract causes repeated GitHub work.');
    }
    $GLOBALS['sync_host'] = 'reconnected.test';
    $class::maybe_sync();
    foreach ($paths as $path) {
        if (strpos($GLOBALS['sync_files'][$path], 'reconnected.test') === false) {
            throw new RuntimeException('Same-version connection change was not reflected.');
        }
    }
}
