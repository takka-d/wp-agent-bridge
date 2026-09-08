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
final class WP_Post
{
    public $ID = 719;
    public $post_type = 'post';
    public $post_status = 'private';
    public $post_modified_gmt = '2026-09-08 13:00:00';
    public $post_content = '';
}

$GLOBALS['test_post'] = new WP_Post();
function is_wp_error($value): bool { return $value instanceof WP_Error; }
function absint($value): int { return abs((int) $value); }
function current_user_can($capability, $post_id = null): bool { return true; }
function get_post($post_id) { return (int) $post_id === 719 ? $GLOBALS['test_post'] : null; }
function rest_ensure_response($value) { return $value; }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function wp_check_invalid_utf8($value, $strip = false) { return (string) $value; }
function parse_blocks($content): array { return []; }

require_once __DIR__ . '/../plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-v084-post-content.php';

function fail_test(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$GLOBALS['test_post']->post_content = "alpha\nbeta\ngamma\ndelta\nepsilon";
$result = TakKa_WordPress_Bridge_V084_Post_Content::read_range([
    'post_id' => 719,
    'start_line' => 2,
    'max_lines' => 2,
]);
if (is_wp_error($result)
    || ($result['status'] ?? null) !== 'private'
    || ($result['start_line'] ?? null) !== 2
    || ($result['end_line'] ?? null) !== 3
    || ($result['returned_lines'] ?? null) !== 2
    || ($result['content'] ?? null) !== "beta\ngamma"
    || ($result['next_start_line'] ?? null) !== 4
    || !empty($result['eof'])) {
    fail_test('Bounded post content range read returned unexpected data.');
}

$tail = TakKa_WordPress_Bridge_V084_Post_Content::read_range([
    'post_id' => 719,
    'start_line' => 5,
    'max_lines' => 10000,
]);
if (is_wp_error($tail)
    || ($tail['content'] ?? null) !== 'epsilon'
    || ($tail['returned_lines'] ?? null) !== 1
    || empty($tail['eof'])
    || ($tail['next_start_line'] ?? 'not-null') !== null
    || ($tail['max_range_lines'] ?? null) !== 1000
    || ($tail['max_range_bytes'] ?? null) !== 262144) {
    fail_test('Post content range limits or EOF metadata are incorrect.');
}

$beyond = TakKa_WordPress_Bridge_V084_Post_Content::read_range([
    'post_id' => 719,
    'start_line' => 6,
]);
if (!is_wp_error($beyond)
    || $beyond->get_error_code() !== 'takka_bridge_post_content_range'
    || (($beyond->get_error_data()['status'] ?? null) !== 416)) {
    fail_test('Out-of-range post content read was not rejected with 416.');
}

$GLOBALS['test_post']->post_content = str_repeat('x', 262145) . "\nnext";
$too_large_line = TakKa_WordPress_Bridge_V084_Post_Content::read_range([
    'post_id' => 719,
    'start_line' => 1,
    'max_lines' => 1,
]);
if (!is_wp_error($too_large_line)
    || $too_large_line->get_error_code() !== 'takka_bridge_post_content_range_line_too_large'
    || (($too_large_line->get_error_data()['status'] ?? null) !== 413)) {
    fail_test('Single-line read larger than the range byte limit was not rejected.');
}

echo "post-content-range-test: ok\n";
