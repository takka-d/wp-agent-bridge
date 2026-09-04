<?php

define('ABSPATH', __DIR__ . '/');

class WP_REST_Server {}
class WP_REST_Response {
    public array $data;
    public int $status;
    public function __construct($data = null, $status = 200) { $this->data = (array) $data; $this->status = (int) $status; }
}
class WP_REST_Request {
    private string $method;
    private string $route;
    private array $headers;
    private string $body;
    public function __construct(string $method, string $route, array $headers, string $body) {
        $this->method = $method; $this->route = $route; $this->headers = array_change_key_case($headers, CASE_LOWER); $this->body = $body;
    }
    public function get_method() { return $this->method; }
    public function get_route() { return $this->route; }
    public function get_header($name) { return $this->headers[strtolower((string) $name)] ?? ''; }
    public function get_body() { return $this->body; }
}
class TakKa_WordPress_Bridge_Direct_Runtime {
    public const WEBHOOK_ROUTE = '/wp-agent-bridge-runtime/v1/github-webhook';
    public const RUNTIME_BRANCH = 'wp-agent-bridge-runtime';
    public static function connection(): array {
        return ['repository_id' => 123, 'installation_id' => 456, 'repository' => 'user/runtime'];
    }
}
class TakKa_WordPress_Bridge_Direct_GitHub {
    public static bool $valid = true;
    public static function verify_webhook(string $raw, string $signature): bool { return self::$valid; }
}

require __DIR__ . '/../plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-direct-webhook-loop-guard.php';

function req(array $commits, array $repo = []): WP_REST_Request {
    $payload = [
        'ref' => 'refs/heads/wp-agent-bridge-runtime',
        'deleted' => false,
        'repository' => array_merge(['id' => 123, 'full_name' => 'user/runtime', 'private' => true], $repo),
        'installation' => ['id' => 456],
        'commits' => $commits,
    ];
    return new WP_REST_Request('POST', TakKa_WordPress_Bridge_Direct_Runtime::WEBHOOK_ROUTE, [
        'x-github-event' => 'push',
        'x-hub-signature-256' => 'sha256=test',
    ], json_encode($payload, JSON_UNESCAPED_SLASHES));
}
function assert_true($condition, string $message): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

$server = new WP_REST_Server();

$r = TakKa_WordPress_Bridge_Direct_Webhook_Loop_Guard::pre_dispatch(null, $server, req([
    ['added' => ['wordpress-bridge/media/pending/a.b64'], 'modified' => [], 'removed' => []],
]));
assert_true($r instanceof WP_REST_Response && ($r->data['ignored'] ?? false) === true, 'media-only push must be ignored');

$r = TakKa_WordPress_Bridge_Direct_Webhook_Loop_Guard::pre_dispatch(null, $server, req([
    ['added' => ['wordpress-bridge/results/a.json', 'wordpress-bridge/commands/completed/a.json'], 'modified' => [], 'removed' => ['wordpress-bridge/commands/pending/a.json']],
]));
assert_true($r instanceof WP_REST_Response && ($r->data['ignored'] ?? false) === true, 'bookkeeping-only push must be ignored');

$r = TakKa_WordPress_Bridge_Direct_Webhook_Loop_Guard::pre_dispatch(null, $server, req([
    ['added' => ['wordpress-bridge/commands/pending/new.json'], 'modified' => [], 'removed' => []],
]));
assert_true($r === null, 'new pending command must reach runtime executor');

$r = TakKa_WordPress_Bridge_Direct_Webhook_Loop_Guard::pre_dispatch(null, $server, req([
    ['added' => ['AGENTS.md'], 'modified' => [], 'removed' => []],
]));
assert_true($r === null, 'non-internal push must reach recovery wrapper');

$r = TakKa_WordPress_Bridge_Direct_Webhook_Loop_Guard::pre_dispatch(null, $server, req([
    ['added' => ['wordpress-bridge/media/pending/a.b64'], 'modified' => [], 'removed' => []],
], ['id' => 999]));
assert_true($r === null, 'mapping mismatch must not be acknowledged by guard');

TakKa_WordPress_Bridge_Direct_GitHub::$valid = false;
$r = TakKa_WordPress_Bridge_Direct_Webhook_Loop_Guard::pre_dispatch(null, $server, req([
    ['added' => ['wordpress-bridge/media/pending/a.b64'], 'modified' => [], 'removed' => []],
]));
assert_true($r === null, 'invalid signature must fall through to authoritative handler');

echo "direct webhook loop guard: OK\n";
