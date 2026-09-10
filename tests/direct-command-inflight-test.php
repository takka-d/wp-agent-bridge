<?php

define('ABSPATH', __DIR__ . '/');

$GLOBALS['wpab_test_options'] = [];

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

function get_option($name, $default = false) {
    return array_key_exists($name, $GLOBALS['wpab_test_options'])
        ? $GLOBALS['wpab_test_options'][$name]
        : $default;
}

function add_option($name, $value, $deprecated = '', $autoload = null) {
    if (array_key_exists($name, $GLOBALS['wpab_test_options'])) {
        return false;
    }
    $GLOBALS['wpab_test_options'][$name] = $value;
    return true;
}

function update_option($name, $value, $autoload = null) {
    $GLOBALS['wpab_test_options'][$name] = $value;
    return true;
}

function delete_option($name) {
    if (!array_key_exists($name, $GLOBALS['wpab_test_options'])) {
        return false;
    }
    unset($GLOBALS['wpab_test_options'][$name]);
    return true;
}

function wp_generate_uuid4() {
    static $n = 0;
    $n++;
    return sprintf('00000000-0000-4000-8000-%012d', $n);
}

function assert_true($condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

require __DIR__ . '/../plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-direct-runtime.php';

$acquire = new ReflectionMethod(TakKa_WordPress_Bridge_Direct_Runtime::class, 'acquire_command_inflight');
$acquire->setAccessible(true);
$release = new ReflectionMethod(TakKa_WordPress_Bridge_Direct_Runtime::class, 'release_command_inflight');
$release->setAccessible(true);
$option = new ReflectionMethod(TakKa_WordPress_Bridge_Direct_Runtime::class, 'command_inflight_option');
$option->setAccessible(true);

$request_id = 'media-race-test';
$token = $acquire->invoke(null, $request_id);
assert_true(is_string($token) && $token !== '', 'first owner must acquire the request lock');
assert_true(TakKa_WordPress_Bridge_Direct_Runtime::command_inflight($request_id), 'active request must report in-flight');
assert_true($acquire->invoke(null, $request_id) === null, 'concurrent owner must not acquire the same request lock');

$release->invoke(null, $request_id, 'wrong-token');
assert_true(TakKa_WordPress_Bridge_Direct_Runtime::command_inflight($request_id), 'non-owner release must not clear the request lock');
$release->invoke(null, $request_id, $token);
assert_true(!TakKa_WordPress_Bridge_Direct_Runtime::command_inflight($request_id), 'owner release must clear the request lock');

$option_name = $option->invoke(null, $request_id);
$GLOBALS['wpab_test_options'][$option_name] = ['token' => 'stale-owner', 'created_at' => time() - 601];
assert_true(!TakKa_WordPress_Bridge_Direct_Runtime::command_inflight($request_id), 'stale ownership must not block recovery');
$replacement = $acquire->invoke(null, $request_id);
assert_true(is_string($replacement) && $replacement !== '', 'a stale owner must be replaceable');
assert_true(TakKa_WordPress_Bridge_Direct_Runtime::command_inflight($request_id), 'replacement owner must become active');
$release->invoke(null, $request_id, $replacement);

$store_journal = new ReflectionMethod(TakKa_WordPress_Bridge_Direct_Runtime::class, 'store_command_journal');
$store_journal->setAccessible(true);
$load_journal = new ReflectionMethod(TakKa_WordPress_Bridge_Direct_Runtime::class, 'load_command_journal');
$load_journal->setAccessible(true);
$clear_journal = new ReflectionMethod(TakKa_WordPress_Bridge_Direct_Runtime::class, 'clear_command_journal');
$clear_journal->setAccessible(true);
$journal_option = new ReflectionMethod(TakKa_WordPress_Bridge_Direct_Runtime::class, 'command_journal_option');
$journal_option->setAccessible(true);

$journal_request = 'media-bookkeeping-race';
$journal_id = 'media-bookkeeping-race';
$command_sha = hash('sha256', '{"same":"command"}');
$result_json = json_encode([
    'id' => $journal_id,
    'request_id' => $journal_request,
    'result' => ['ok' => true, 'status' => 200, 'data' => ['attachment_id' => 123]],
], JSON_PRETTY_PRINT) . "\n";

$stored = $store_journal->invoke(null, $journal_request, $journal_id, $command_sha, $result_json);
assert_true($stored === true, 'successful WordPress execution result must be journaled before GitHub bookkeeping');
$loaded = $load_journal->invoke(null, $journal_request, $journal_id, $command_sha);
assert_true(is_array($loaded), 'matching recovery must load the local execution journal');
assert_true(($loaded['output']['result']['data']['attachment_id'] ?? null) === 123, 'journal replay must preserve the original successful result');
assert_true(($loaded['result_json'] ?? '') === $result_json, 'journal replay must preserve the exact result JSON for GitHub');

$conflict = $load_journal->invoke(null, $journal_request, $journal_id, hash('sha256', '{"changed":"command"}'));
assert_true($conflict instanceof WP_Error && $conflict->get_error_code() === 'takka_direct_command_journal_conflict', 'same request_id with changed command content must be rejected');

$clear_journal->invoke(null, $journal_request);
assert_true($load_journal->invoke(null, $journal_request, $journal_id, $command_sha) === null, 'journal must be cleared once the GitHub result is durable');

$journal_option_name = $journal_option->invoke(null, $journal_request);
$GLOBALS['wpab_test_options'][$journal_option_name] = [
    'id' => $journal_id,
    'command_sha256' => $command_sha,
    'created_at' => time() - 86401,
    'result_json' => $result_json,
];
assert_true($load_journal->invoke(null, $journal_request, $journal_id, $command_sha) === null, 'stale journals must expire rather than block future recovery forever');

$v2 = file_get_contents(__DIR__ . '/../plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-direct-runtime-v2.php');
assert_true(is_string($v2) && strpos($v2, 'command_inflight($request_id)') !== false, 'V2 recovery must consult Direct Runtime in-flight ownership');
assert_true(strpos($v2, "'reason' => 'command-in-flight'") !== false, 'V2 recovery must expose the in-flight skip reason');
assert_true(strpos($v2, 'MAX_RECOVERY_AGE_SECONDS = 86400') !== false, 'V2 recovery must bound automatic pending-command recovery age');
assert_true(strpos($v2, "'reason' => 'age-unavailable'") !== false, 'V2 recovery must not re-execute commands whose age cannot be established');
assert_true(strpos($v2, 'commands/expired/') !== false, 'V2 recovery must quarantine expired pending commands');
assert_true(strpos($v2, 'takka_bridge_pending_expired') !== false, 'V2 recovery must record an explicit expired-command result');
assert_true(strpos($v2, 'put_if_absent_or_identical') !== false && strpos($v2, 'delete_if_matches') !== false, 'V2 quarantine must preserve conflict-safe bookkeeping');

echo "direct command in-flight ownership and local journal: OK\n";
