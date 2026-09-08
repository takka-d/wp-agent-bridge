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
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
function is_wp_error($value): bool { return $value instanceof WP_Error; }

require_once __DIR__ . '/../plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-v097-workspace.php';

function fail_test(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function workspace_private_method(string $name): ReflectionMethod
{
    $method = new ReflectionMethod('TakKa_WordPress_Bridge_V097_Workspace', $name);
    $method->setAccessible(true);
    return $method;
}

$normalize = workspace_private_method('normalize_relative_path');
$valid = $normalize->invoke(null, 'pov/pov_v21_standardlib_step11.html');
if ($valid !== 'pov/pov_v21_standardlib_step11.html') {
    fail_test('Expected normal HTML workspace path to be accepted.');
}
foreach (['../secret.txt', '.snapshots/x.json', 'pov/tool.php', 'pov/no-extension'] as $bad) {
    $result = $normalize->invoke(null, $bad);
    if (!is_wp_error($result)) {
        fail_test('Unsafe or unsupported workspace path was accepted: ' . $bad);
    }
}

$replace_nth = workspace_private_method('replace_nth');
$replaced = $replace_nth->invoke(null, 'a X b X c X', 'X', 'Y', 2);
if ($replaced !== 'a X b Y c X') {
    fail_test('replace_nth did not replace exactly the requested occurrence.');
}

$diff = workspace_private_method('diff_lines')->invoke(null, "a\nb\nc\nd", "a\nb\nX\nY\nd");
if (($diff['common_prefix_lines'] ?? null) !== 2
    || ($diff['common_suffix_lines'] ?? null) !== 1
    || ($diff['removed_line_count'] ?? null) !== 1
    || ($diff['added_line_count'] ?? null) !== 2
    || ($diff['removed_lines'][0] ?? null) !== 'c'
    || ($diff['added_lines'][0] ?? null) !== 'X') {
    fail_test('Compact line diff did not isolate the changed middle block.');
}

$utf8 = workspace_private_method('valid_utf8');
if ($utf8->invoke(null, '日本語 UTF-8') !== true || $utf8->invoke(null, "\xFF") !== false) {
    fail_test('UTF-8 validation failed.');
}

$snapshot_id = workspace_private_method('valid_snapshot_id');
if ($snapshot_id->invoke(null, '20260908T120000Z-abcdef123456') !== true
    || $snapshot_id->invoke(null, '../bad') !== false) {
    fail_test('Snapshot ID validation failed.');
}

echo "workspace-test: ok\n";
