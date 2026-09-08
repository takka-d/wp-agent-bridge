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
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }

require_once __DIR__ . '/../plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-v098-read-batch.php';

function fail_test(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function batch_private_method(string $name): ReflectionMethod
{
    $method = new ReflectionMethod('TakKa_WordPress_Bridge_V098_Read_Batch', $name);
    $method->setAccessible(true);
    return $method;
}

$capabilities = batch_private_method('capabilities')->invoke(null);
if (($capabilities['version'] ?? null) !== '0.9.8.1'
    || ($capabilities['limits']['max_operations'] ?? null) !== 12
    || !in_array('post.content.inspect', $capabilities['rest_actions'] ?? [], true)
    || !in_array('post.content.search', $capabilities['rest_actions'] ?? [], true)
    || !in_array('post.content.read.range', $capabilities['rest_actions'] ?? [], true)
    || !empty($capabilities['mutation_actions_allowed'])
    || !empty($capabilities['arbitrary_rest_allowed'])) {
    fail_test('Read-only batch capabilities are not strict, bounded, or missing post-content reads.');
}

$validate = batch_private_method('validate_operation');
$allowed = $validate->invoke(null, [
    'label' => 'camera search',
    'action' => 'workspace.file.search',
    'params' => ['query' => 'camera'],
], 0);
if (is_wp_error($allowed)
    || ($allowed['action'] ?? null) !== 'workspace.file.search'
    || ($allowed['label'] ?? null) !== 'camera search') {
    fail_test('Allowlisted workspace read was rejected.');
}

$post_range = $validate->invoke(null, [
    'label' => 'post source range',
    'action' => 'post.content.read.range',
    'params' => ['post_id' => 719, 'start_line' => 1, 'max_lines' => 100],
], 0);
if (is_wp_error($post_range) || ($post_range['action'] ?? null) !== 'post.content.read.range') {
    fail_test('Allowlisted post content range read was rejected.');
}

foreach ([
    'workspace.file.write',
    'workspace.file.patch',
    'workspace.file.delete',
    'workspace.snapshot.create',
    'workspace.snapshot.rollback',
    'post.content.patch.preview',
    'post.content.patch.apply',
    'site.icon.set',
    'site.icon.clear',
    'bridge.self_update.apply',
    'bridge.self_update.rollback',
    'classic_theme.create',
    'classic_theme.publish',
    'classic_theme.discard',
    'http.probe',
    'rest.call',
] as $blocked_action) {
    $blocked = $validate->invoke(null, ['action' => $blocked_action, 'params' => []], 1);
    if (!is_wp_error($blocked) || $blocked->get_error_code() !== 'takka_bridge_read_batch_blocked_action') {
        fail_test('Mutating, generic, or non-approved action was accepted: ' . $blocked_action);
    }
}

$oversized = $validate->invoke(null, [
    'action' => 'workspace.file.search',
    'params' => ['query' => str_repeat('x', 262200)],
], 2);
if (!is_wp_error($oversized) || $oversized->get_error_code() !== 'takka_bridge_read_batch_params') {
    fail_test('Oversized operation params were not rejected.');
}

$subrequest = batch_private_method('subrequest_id');
$id1 = $subrequest->invoke(null, 'outer-request', 0, 'workspace.file.search');
$id2 = $subrequest->invoke(null, 'outer-request', 1, 'workspace.file.search');
if ($id1 === $id2
    || !preg_match('/^readbatch-[a-f0-9]{40}$/', $id1)
    || strlen($id1) > 120) {
    fail_test('Batch subrequest IDs are not stable bounded unique IDs.');
}

echo "read-only-batch-test: ok\n";
