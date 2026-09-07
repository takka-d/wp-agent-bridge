<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

final class WP_Error
{
    private $code;
    private $message;
    private $data;

    public function __construct($code = '', $message = '', $data = null)
    {
        $this->code = (string) $code;
        $this->message = (string) $message;
        $this->data = $data;
    }

    public function get_error_code()
    {
        return $this->code;
    }

    public function get_error_message()
    {
        return $this->message;
    }

    public function get_error_data()
    {
        return $this->data;
    }
}

function is_wp_error($value): bool
{
    return $value instanceof WP_Error;
}

final class TakKa_WordPress_Bridge_Direct_GitHub
{
    public static $calls = [];
    public static $treeEntries = [];
    public static $truncated = false;
    public static $patchError = null;

    public static function github_api(string $method, string $endpoint, string $token, $body = null)
    {
        self::$calls[] = [$method, $endpoint, $body];

        if ($method === 'GET' && strpos($endpoint, '/git/ref/heads/') !== false) {
            return ['data' => ['object' => ['sha' => str_repeat('a', 40)]]];
        }
        if ($method === 'GET' && strpos($endpoint, '/git/commits/') !== false) {
            return ['data' => ['tree' => ['sha' => str_repeat('1', 40)]]];
        }
        if ($method === 'GET' && strpos($endpoint, '/git/trees/') !== false) {
            return ['data' => [
                'sha' => str_repeat('1', 40),
                'truncated' => self::$truncated,
                'tree' => self::$treeEntries,
            ]];
        }
        if ($method === 'POST' && substr($endpoint, -10) === '/git/trees') {
            return ['data' => ['sha' => str_repeat('2', 40)]];
        }
        if ($method === 'POST' && substr($endpoint, -12) === '/git/commits') {
            return ['data' => ['sha' => str_repeat('3', 40)]];
        }
        if ($method === 'PATCH' && strpos($endpoint, '/git/refs/heads/') !== false) {
            if (self::$patchError instanceof WP_Error) {
                return self::$patchError;
            }
            return ['data' => ['object' => ['sha' => str_repeat('3', 40)]]];
        }
        return new WP_Error('unexpected_mock_call', $method . ' ' . $endpoint, ['status' => 500]);
    }
}

require_once __DIR__ . '/../plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-direct-media-auto-path.php';

function fail_test(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function private_method(string $name): ReflectionMethod
{
    $method = new ReflectionMethod('TakKa_WordPress_Bridge_Direct_Media_Auto_Path', $name);
    $method->setAccessible(true);
    return $method;
}

$paths = [
    'wordpress-bridge/media/pending/auto-0.b64',
    'wordpress-bridge/media/pending/auto-1.b64',
];
$sha0 = str_repeat('b', 40);
$sha1 = str_repeat('c', 40);
TakKa_WordPress_Bridge_Direct_GitHub::$treeEntries = [
    ['path' => 'README.md', 'type' => 'blob', 'sha' => str_repeat('d', 40)],
    ['path' => $paths[1], 'type' => 'blob', 'sha' => $sha1],
    ['path' => $paths[0], 'type' => 'blob', 'sha' => $sha0],
];

$snapshotMethod = private_method('snapshot_sources');
TakKa_WordPress_Bridge_Direct_GitHub::$calls = [];
$snapshot = $snapshotMethod->invoke(null, 'token', 'test-user/runtime-repo', 'wp-agent-bridge-runtime', $paths, null, false);
if (is_wp_error($snapshot)) {
    fail_test('Automatic path resolution returned an unexpected error: ' . $snapshot->get_error_code());
}
if (($snapshot['head'] ?? '') !== str_repeat('a', 40)
    || ($snapshot['tree_sha'] ?? '') !== str_repeat('1', 40)
    || ($snapshot['sources'][0]['sha'] ?? '') !== $sha0
    || ($snapshot['sources'][1]['sha'] ?? '') !== $sha1) {
    fail_test('Automatic path resolution did not preserve data_paths ordering or snapshot identity.');
}
if (count(TakKa_WordPress_Bridge_Direct_GitHub::$calls) !== 3
    || strpos(TakKa_WordPress_Bridge_Direct_GitHub::$calls[2][1], '?recursive=1') === false) {
    fail_test('Automatic path resolution must use one ref + commit + recursive-tree snapshot, independent of chunk count.');
}

$pinned = $snapshotMethod->invoke(null, 'token', 'test-user/runtime-repo', 'wp-agent-bridge-runtime', $paths, [$sha0, $sha1], false);
if (is_wp_error($pinned)) {
    fail_test('Matching optional data_blob_shas pins should succeed.');
}
$mismatch = $snapshotMethod->invoke(null, 'token', 'test-user/runtime-repo', 'wp-agent-bridge-runtime', $paths, [$sha1, $sha0], false);
if (!is_wp_error($mismatch) || $mismatch->get_error_code() !== 'wpab_direct_media_auto_pin_mismatch') {
    fail_test('Mismatched optional data_blob_shas pins were not rejected.');
}

TakKa_WordPress_Bridge_Direct_GitHub::$treeEntries = [
    ['path' => $paths[0], 'type' => 'blob', 'sha' => $sha0],
];
$missing = $snapshotMethod->invoke(null, 'token', 'test-user/runtime-repo', 'wp-agent-bridge-runtime', $paths, null, false);
if (!is_wp_error($missing) || $missing->get_error_code() !== 'wpab_direct_media_auto_source_missing') {
    fail_test('Missing staged source was not rejected before side effects.');
}

TakKa_WordPress_Bridge_Direct_GitHub::$treeEntries = [
    ['path' => $paths[0], 'type' => 'blob', 'sha' => $sha0],
    ['path' => $paths[1], 'type' => 'blob', 'sha' => $sha1],
];
TakKa_WordPress_Bridge_Direct_GitHub::$truncated = true;
$truncated = $snapshotMethod->invoke(null, 'token', 'test-user/runtime-repo', 'wp-agent-bridge-runtime', $paths, null, false);
TakKa_WordPress_Bridge_Direct_GitHub::$truncated = false;
if (!is_wp_error($truncated) || $truncated->get_error_code() !== 'wpab_direct_media_auto_tree') {
    fail_test('Truncated recursive tree snapshot was not rejected.');
}

$decode = private_method('decode_base64');
$raw = "automatic-media-fast-path\x00\x01";
$decoded = $decode->invoke(null, base64_encode($raw));
if (!is_string($decoded) || $decoded !== $raw) {
    fail_test('Strict staged Base64 decoding failed.');
}

TakKa_WordPress_Bridge_Direct_GitHub::$calls = [];
$sources = [
    ['path' => $paths[0], 'sha' => $sha0],
    ['path' => $paths[1], 'sha' => $sha1],
];
$cleanup = private_method('cleanup_sources_atomic')->invoke(
    null,
    'token',
    'test-user/runtime-repo',
    'wp-agent-bridge-runtime',
    $sources,
    $snapshot
);
if (is_wp_error($cleanup) || empty($cleanup['ok']) || ($cleanup['attempts'] ?? 0) !== 1) {
    fail_test('Common-path atomic cleanup did not reuse the upload snapshot successfully.');
}
if (count(TakKa_WordPress_Bridge_Direct_GitHub::$calls) !== 3) {
    fail_test('Common-path cleanup should need only tree creation, commit creation, and non-force ref update.');
}
$treeBody = TakKa_WordPress_Bridge_Direct_GitHub::$calls[0][2] ?? null;
$commitBody = TakKa_WordPress_Bridge_Direct_GitHub::$calls[1][2] ?? null;
$patchBody = TakKa_WordPress_Bridge_Direct_GitHub::$calls[2][2] ?? null;
$firstDelete = is_array($treeBody) && isset($treeBody['tree'][0]) && is_array($treeBody['tree'][0])
    ? $treeBody['tree'][0]
    : [];
if (!is_array($treeBody)
    || ($treeBody['base_tree'] ?? '') !== str_repeat('1', 40)
    || count($treeBody['tree'] ?? []) !== 2
    || !array_key_exists('sha', $firstDelete)
    || $firstDelete['sha'] !== null
    || !is_array($commitBody)
    || ($commitBody['parents'][0] ?? '') !== str_repeat('a', 40)
    || !is_array($patchBody)
    || ($patchBody['force'] ?? true) !== false) {
    fail_test('Atomic cleanup did not preserve snapshot parent/tree guards and non-force ref update.');
}

$fallback = private_method('legacy_fallback_allowed');
if ($fallback->invoke(null, new WP_Error('takka_direct_github_http_404', 'mock', ['status' => 404])) !== true
    || $fallback->invoke(null, new WP_Error('wpab_direct_media_auto_source_missing', 'mock', ['status' => 409])) !== false) {
    fail_test('Legacy fallback is not restricted to unsupported Git API endpoint failures.');
}

echo "media-auto-path-resolution-test: ok\n";
