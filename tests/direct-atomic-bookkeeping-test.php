<?php

define('ABSPATH', __DIR__ . '/');

class WP_Error {
    private string $code;
    private string $message;
    private $data;
    public function __construct($code = '', $message = '', $data = null) {
        $this->code = (string) $code;
        $this->message = (string) $message;
        $this->data = $data;
    }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }

final class TakKa_WordPress_Bridge_Direct_GitHub
{
    public static string $branch = 'wp-agent-bridge-runtime';
    public static string $ref;
    public static array $commits = [];
    public static array $trees = [];
    public static array $blobs = [];
    public static int $ref_updates = 0;
    public static int $successful_ref_updates = 0;
    public static int $blob_creates = 0;
    public static bool $inject_first_ref_conflict = true;

    public static function reset(string $pendingPath, string $pendingContent): string
    {
        self::$ref = str_repeat('a', 40);
        $tree = str_repeat('b', 40);
        $pendingBlob = str_repeat('c', 40);
        self::$blobs = [$pendingBlob => $pendingContent];
        self::$trees = [$tree => [$pendingPath => $pendingBlob]];
        self::$commits = [self::$ref => ['tree' => $tree, 'parent' => null]];
        self::$ref_updates = 0;
        self::$successful_ref_updates = 0;
        self::$blob_creates = 0;
        self::$inject_first_ref_conflict = true;
        return $pendingBlob;
    }

    public static function github_api(string $method, string $endpoint, string $token, $body = null)
    {
        if ($method === 'POST' && preg_match('#/git/blobs$#', $endpoint)) {
            self::$blob_creates++;
            $content = base64_decode((string) ($body['content'] ?? ''), true);
            if (!is_string($content)) {
                return new WP_Error('fake_blob_decode', 'bad blob', ['status' => 400]);
            }
            $sha = sha1('blob-' . self::$blob_creates . '-' . $content);
            self::$blobs[$sha] = $content;
            return ['status' => 201, 'data' => ['sha' => $sha]];
        }
        if ($method === 'GET' && preg_match('#/git/ref/heads/wp-agent-bridge-runtime$#', $endpoint)) {
            return ['status' => 200, 'data' => ['object' => ['sha' => self::$ref]]];
        }
        if ($method === 'GET' && preg_match('#/git/commits/([a-f0-9]{40})$#', $endpoint, $m)) {
            $sha = $m[1];
            if (!isset(self::$commits[$sha])) {
                return new WP_Error('fake_commit_404', 'not found', ['status' => 404]);
            }
            return ['status' => 200, 'data' => ['tree' => ['sha' => self::$commits[$sha]['tree']]]];
        }
        if ($method === 'POST' && preg_match('#/git/trees$#', $endpoint)) {
            $base = (string) ($body['base_tree'] ?? '');
            if (!isset(self::$trees[$base])) {
                return new WP_Error('fake_tree_base', 'bad base tree', ['status' => 422]);
            }
            $paths = self::$trees[$base];
            foreach ((array) ($body['tree'] ?? []) as $entry) {
                $path = (string) ($entry['path'] ?? '');
                if (array_key_exists('sha', $entry) && $entry['sha'] === null) {
                    unset($paths[$path]);
                } elseif (array_key_exists('content', $entry)) {
                    $content = (string) $entry['content'];
                    $blob = sha1('blob ' . strlen($content) . "\0" . $content);
                    self::$blobs[$blob] = $content;
                    $paths[$path] = $blob;
                } else {
                    $paths[$path] = (string) ($entry['sha'] ?? '');
                }
            }
            $sha = sha1('tree-' . json_encode($paths) . '-' . count(self::$trees));
            self::$trees[$sha] = $paths;
            return ['status' => 201, 'data' => ['sha' => $sha]];
        }
        if ($method === 'POST' && preg_match('#/git/commits$#', $endpoint)) {
            $tree = (string) ($body['tree'] ?? '');
            $parent = (string) (($body['parents'][0] ?? ''));
            $sha = sha1('commit-' . $tree . '-' . $parent . '-' . count(self::$commits));
            self::$commits[$sha] = ['tree' => $tree, 'parent' => $parent];
            return ['status' => 201, 'data' => ['sha' => $sha]];
        }
        if ($method === 'PATCH' && preg_match('#/git/refs/heads/wp-agent-bridge-runtime$#', $endpoint)) {
            self::$ref_updates++;
            $next = (string) ($body['sha'] ?? '');
            if (self::$inject_first_ref_conflict) {
                self::$inject_first_ref_conflict = false;
                $oldTree = self::$commits[self::$ref]['tree'];
                $paths = self::$trees[$oldTree];
                $externalBlob = str_repeat('f', 40);
                self::$blobs[$externalBlob] = 'external';
                $paths['wordpress-bridge/workspace/external.txt'] = $externalBlob;
                $externalTree = str_repeat('e', 40);
                $externalCommit = str_repeat('d', 40);
                self::$trees[$externalTree] = $paths;
                self::$commits[$externalCommit] = ['tree' => $externalTree, 'parent' => self::$ref];
                self::$ref = $externalCommit;
                return new WP_Error('fake_ref_conflict', 'Reference update failed', ['status' => 409]);
            }
            if (!isset(self::$commits[$next]) || self::$commits[$next]['parent'] !== self::$ref) {
                return new WP_Error('fake_non_fast_forward', 'not fast forward', ['status' => 422]);
            }
            self::$ref = $next;
            self::$successful_ref_updates++;
            return ['status' => 200, 'data' => ['object' => ['sha' => $next]]];
        }
        return new WP_Error('fake_unknown', $method . ' ' . $endpoint, ['status' => 500]);
    }

    public static function get_content_metadata(string $token, string $repository, string $ref, string $path)
    {
        $commit = $ref === self::$branch ? self::$ref : $ref;
        if (!isset(self::$commits[$commit])) {
            return new WP_Error('fake_ref_404', 'not found', ['status' => 404]);
        }
        $tree = self::$trees[self::$commits[$commit]['tree']];
        if (!isset($tree[$path])) {
            return new WP_Error('fake_content_404', 'not found', ['status' => 404]);
        }
        $sha = $tree[$path];
        $content = self::$blobs[$sha] ?? '';
        return ['sha' => $sha, 'encoding' => 'base64', 'content' => base64_encode($content)];
    }

    public static function get_text_file(string $token, string $repository, string $ref, string $path)
    {
        $meta = self::get_content_metadata($token, $repository, $ref, $path);
        if (is_wp_error($meta)) {
            return $meta;
        }
        return base64_decode((string) $meta['content'], true);
    }
}

function assert_true($condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

require __DIR__ . '/../plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-direct-runtime.php';

$pendingPath = 'wordpress-bridge/commands/pending/atomic-test.json';
$resultPath = 'wordpress-bridge/results/atomic-test.json';
$completedPath = 'wordpress-bridge/commands/completed/atomic-test.json';
$commandRaw = "{\"id\":\"atomic-test\"}\n";
$resultJson = "{\"id\":\"atomic-test\",\"result\":{\"ok\":true}}\n";
$pendingSha = TakKa_WordPress_Bridge_Direct_GitHub::reset($pendingPath, $commandRaw);

$method = new ReflectionMethod(TakKa_WordPress_Bridge_Direct_Runtime::class, 'finalize_command_atomic');
$method->setAccessible(true);
$result = $method->invoke(
    null,
    'token',
    'owner/runtime',
    $pendingPath,
    $pendingSha,
    $resultPath,
    $resultJson,
    $completedPath,
    $commandRaw,
    'atomic-test'
);

assert_true(is_array($result), 'atomic finalization must succeed after a branch race');
assert_true(($result['attempts'] ?? null) === 2, 'branch conflict must be retried against the new head');
assert_true(TakKa_WordPress_Bridge_Direct_GitHub::$ref_updates === 2, 'test must exercise one failed and one successful ref update');
assert_true(TakKa_WordPress_Bridge_Direct_GitHub::$successful_ref_updates === 1, 'bookkeeping itself must move the runtime branch only once');
assert_true(TakKa_WordPress_Bridge_Direct_GitHub::$blob_creates === 0, 'bookkeeping must not issue separate blob creation requests');

$finalCommit = TakKa_WordPress_Bridge_Direct_GitHub::$commits[TakKa_WordPress_Bridge_Direct_GitHub::$ref];
$finalTree = TakKa_WordPress_Bridge_Direct_GitHub::$trees[$finalCommit['tree']];
assert_true(!isset($finalTree[$pendingPath]), 'pending command must be removed in the same final tree');
assert_true(isset($finalTree[$resultPath]), 'result must exist in the final tree');
assert_true(isset($finalTree[$completedPath]), 'completed command must exist in the final tree');
assert_true($finalTree[$completedPath] === $pendingSha, 'completed must reuse the exact verified pending blob');
assert_true(isset($finalTree['wordpress-bridge/workspace/external.txt']), 'retry must preserve the concurrent unrelated branch change');
assert_true(TakKa_WordPress_Bridge_Direct_GitHub::$blobs[$finalTree[$resultPath]] === $resultJson, 'result content must be exact');
assert_true(TakKa_WordPress_Bridge_Direct_GitHub::$blobs[$finalTree[$completedPath]] === $commandRaw, 'completed command content must be exact');

$verify = new ReflectionMethod(TakKa_WordPress_Bridge_Direct_Runtime::class, 'verify_atomic_finalization');
$verify->setAccessible(true);
assert_true($verify->invoke(null, 'token', 'owner/runtime', $resultPath, $resultJson, $completedPath, $commandRaw, $pendingPath) === true,
    'durable atomic bookkeeping must verify as one coherent state');

// A changed pending file must never be deleted or archived as the old command.
$pendingSha = TakKa_WordPress_Bridge_Direct_GitHub::reset($pendingPath, $commandRaw);
$changedBlob = str_repeat('9', 40);
TakKa_WordPress_Bridge_Direct_GitHub::$blobs[$changedBlob] = '{"id":"replacement"}';
TakKa_WordPress_Bridge_Direct_GitHub::$trees[str_repeat('b', 40)][$pendingPath] = $changedBlob;
$conflict = $method->invoke(null, 'token', 'owner/runtime', $pendingPath, $pendingSha,
    $resultPath, $resultJson, $completedPath, $commandRaw, 'atomic-test');
assert_true(is_wp_error($conflict) && $conflict->get_error_code() === 'takka_direct_bookkeeping_pending_conflict',
    'a changed pending command must be rejected before publication');
assert_true(TakKa_WordPress_Bridge_Direct_GitHub::$ref_updates === 0,
    'pending mismatch must not update the branch');

echo "direct atomic bookkeeping: OK\n";
