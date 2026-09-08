<?php

define('ABSPATH', __DIR__ . '/');

$GLOBALS['wpab_test_options'] = [];

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

$v2 = file_get_contents(__DIR__ . '/../plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-direct-runtime-v2.php');
assert_true(is_string($v2) && strpos($v2, 'command_inflight($request_id)') !== false, 'V2 recovery must consult Direct Runtime in-flight ownership');
assert_true(strpos($v2, "'reason' => 'command-in-flight'") !== false, 'V2 recovery must expose the in-flight skip reason');

echo "direct command in-flight ownership: OK\n";
