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
function current_user_can($capability): bool { return $capability === 'manage_options'; }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }

final class WP_REST_Request
{
    private $method;
    private $route;
    private $body;
    private $headers = [];
    public function __construct(string $method, string $route)
    {
        $this->method = $method;
        $this->route = $route;
        $this->body = '';
    }
    public function get_method(): string { return $this->method; }
    public function get_route(): string { return $this->route; }
    public function get_body(): string { return $this->body; }
    public function set_body(string $body): void { $this->body = $body; }
    public function set_header(string $name, string $value): void { $this->headers[strtolower($name)] = $value; }
}

require_once __DIR__ . '/../plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-v099-policy.php';

function fail_test(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function request_for(string $operation, array $params = []): WP_REST_Request
{
    $request = new WP_REST_Request('POST', '/takka-v099/v1/operate');
    $request->set_body(json_encode(['operation' => $operation, 'params' => $params], JSON_UNESCAPED_SLASHES));
    return $request;
}

$post_get = request_for('post.get', ['post_id' => 719]);
$result = TakKa_WordPress_Bridge_V099_Policy::apply(null, [], $post_get);
if ($result !== null) fail_test('post.get normalization unexpectedly returned a response.');
$post_get_json = json_decode($post_get->get_body(), true);
$fields = $post_get_json['params']['query']['_fields'] ?? '';
if (!is_string($fields) || strpos($fields, 'featured_media') === false || strpos($fields, 'content') !== false) {
    fail_test('post.get default metadata fields are not bounded correctly.');
}

$post_get_content = request_for('post.get', [
    'post_id' => 719,
    'query' => ['_fields' => 'id,title,content'],
]);
$blocked = TakKa_WordPress_Bridge_V099_Policy::apply(null, [], $post_get_content);
if (!is_wp_error($blocked) || $blocked->get_error_code() !== 'wpab_v099_post_get_content_blocked') {
    fail_test('post.get content request was not blocked.');
}

$post_update = request_for('post.update', ['post_id' => 719, 'fields' => ['content' => '<p>unsafe rewrite</p>']]);
$blocked = TakKa_WordPress_Bridge_V099_Policy::apply(null, [], $post_update);
if (!is_wp_error($blocked) || $blocked->get_error_code() !== 'wpab_v099_content_requires_guarded_patch') {
    fail_test('post.update content rewrite was not blocked.');
}

$binary = 'small-image-test';
$inline = request_for('media.upload.inline', [
    'filename' => 'small.png',
    'data_b64' => base64_encode($binary),
    'expected_bytes' => strlen($binary),
    'expected_sha256' => hash('sha256', $binary),
]);
$ok = TakKa_WordPress_Bridge_V099_Policy::apply(null, [], $inline);
if ($ok !== null) fail_test('Valid inline media was blocked.');

$bad_sha = request_for('media.upload.inline', [
    'filename' => 'small.png',
    'data_b64' => base64_encode($binary),
    'expected_sha256' => str_repeat('0', 64),
]);
$blocked = TakKa_WordPress_Bridge_V099_Policy::apply(null, [], $bad_sha);
if (!is_wp_error($blocked) || $blocked->get_error_code() !== 'wpab_v099_inline_media_sha_mismatch') {
    fail_test('Inline media SHA mismatch was not blocked.');
}

$large = request_for('media.upload.inline', [
    'filename' => 'large.png',
    'data_b64' => base64_encode(str_repeat('A', 1048577)),
]);
$blocked = TakKa_WordPress_Bridge_V099_Policy::apply(null, [], $large);
if (!is_wp_error($blocked) || $blocked->get_error_code() !== 'wpab_v099_inline_media_use_staged') {
    fail_test('Oversized inline media was not routed away from inline transport.');
}

$batch = request_for('readonly.batch', [
    'operations' => [
        ['operation' => 'post.content.read_range', 'params' => ['post_id' => 719, 'start_line' => 1]],
        ['operation' => 'workspace.file.read_range', 'params' => ['path' => 'tool.html', 'start_line' => 1]],
        ['action' => 'site.icon.get', 'params' => []],
    ],
]);
$result = TakKa_WordPress_Bridge_V099_Policy::apply(null, [], $batch);
if ($result !== null) fail_test('readonly.batch alias normalization unexpectedly returned a response.');
$batch_json = json_decode($batch->get_body(), true);
$actions = array_map(static function ($item) { return $item['action'] ?? ''; }, $batch_json['params']['operations'] ?? []);
if ($actions !== ['post.content.read.range', 'workspace.file.read.range', 'site.icon.get']) {
    fail_test('readonly.batch high-level aliases were not normalized.');
}

$unsupported = request_for('readonly.batch', [
    'operations' => [['operation' => 'post.update', 'params' => []]],
]);
$blocked = TakKa_WordPress_Bridge_V099_Policy::apply(null, [], $unsupported);
if (!is_wp_error($blocked) || $blocked->get_error_code() !== 'wpab_v099_batch_unsupported') {
    fail_test('Mutation slipped into deterministic readonly.batch mapping.');
}

echo "operation-policy-test: ok\n";
