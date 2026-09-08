<?php

define('ABSPATH', __DIR__ . '/');

$GLOBALS['wpab_added_filters'] = [];
$GLOBALS['wpab_removed_filters'] = [];

function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['wpab_added_filters'][] = [$hook, $callback, $priority, $accepted_args];
    return true;
}

function remove_filter($hook, $callback, $priority = 10) {
    $GLOBALS['wpab_removed_filters'][] = [$hook, $callback, $priority];
    return true;
}

class WP_REST_Server {}
class WP_REST_Request {}
class WP_REST_Response {
    public $data;
    public function __construct($data = null) { $this->data = $data; }
}

class TakKa_WordPress_Bridge_Direct_Media_Auto_Path {
    public static int $calls = 0;
    public static array $last_handler = ['unexpected'];

    public static function maybe_upload($result, array $handler, WP_REST_Request $request)
    {
        self::$calls++;
        self::$last_handler = $handler;
        return new WP_REST_Response(['ok' => true, 'fast_path' => true]);
    }
}

function assert_true($condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

require __DIR__ . '/../plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-direct-media-pre-dispatch.php';

TakKa_WordPress_Bridge_Direct_Media_Pre_Dispatch::init();

$removed = $GLOBALS['wpab_removed_filters'];
assert_true(count($removed) === 1, 'legacy before-callback hook must be removed exactly once');
assert_true($removed[0][0] === 'rest_request_before_callbacks', 'wrong legacy hook removed');
assert_true($removed[0][1] === [TakKa_WordPress_Bridge_Direct_Media_Auto_Path::class, 'maybe_upload'], 'wrong legacy callback removed');
assert_true($removed[0][2] === 515, 'wrong legacy callback priority removed');

$added = $GLOBALS['wpab_added_filters'];
assert_true(count($added) === 1, 'pre-dispatch hook must be added exactly once');
assert_true($added[0][0] === 'rest_pre_dispatch', 'fast path must use rest_pre_dispatch');
assert_true($added[0][1] === [TakKa_WordPress_Bridge_Direct_Media_Pre_Dispatch::class, 'pre_dispatch'], 'wrong pre-dispatch callback registered');
assert_true($added[0][2] === 25 && $added[0][3] === 3, 'unexpected pre-dispatch priority or arity');

$response = TakKa_WordPress_Bridge_Direct_Media_Pre_Dispatch::pre_dispatch(null, new WP_REST_Server(), new WP_REST_Request());
assert_true($response instanceof WP_REST_Response, 'pre-dispatch wrapper must return the Auto Path response');
assert_true(($response->data['fast_path'] ?? false) === true, 'fast-path response was lost');
assert_true(TakKa_WordPress_Bridge_Direct_Media_Auto_Path::$calls === 1, 'Auto Path must execute once');
assert_true(TakKa_WordPress_Bridge_Direct_Media_Auto_Path::$last_handler === [], 'wrapper must adapt rest_pre_dispatch without leaking handler state');

echo "direct media pre-dispatch short-circuit wiring: OK\n";
