<?php
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
class WP_Error {
    public function __construct(private $code, private $message, private $data = []) {}
    public function get_error_code() { return $this->code; }
    public function get_error_data() { return $this->data; }
    public function get_error_message() { return $this->message; }
}
class WP_REST_Response {
    public function __construct(private $data, private $status = 200) {}
    public function get_data() { return $this->data; }
    public function get_status() { return $this->status; }
}
class WP_REST_Request {
    public string $body = '';
    public function __construct($method, $route) {}
    public function set_header($name, $value) {}
    public function set_body($body) { $this->body = $body; }
}
function is_wp_error($v) { return $v instanceof WP_Error; }
function rest_ensure_response($v) { return $v instanceof WP_REST_Response ? $v : new WP_REST_Response($v); }
function wp_json_encode($v, $flags = 0) { return json_encode($v, $flags); }
function home_url($path = '/') { return 'https://example.test' . $path; }
function get_option($name, $default = null) { return $name === 'wpab_secret' ? str_repeat('a', 64) : $default; }
function get_stylesheet_directory() { return $GLOBALS['theme_test_root']; }
function invoke_private($class, $method, ...$args) { return (new ReflectionMethod($class, $method))->invoke(null, ...$args); }
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
function rest_do_request($request) {
    if (isset($GLOBALS['injected_response'])) return $GLOBALS['injected_response'];
    $outer = json_decode($request->body, true);
    $inner = json_decode(base64_decode($outer['params']['payload_b64']), true);
    $params = $inner['params']['body']['params'];
    $data = invoke_private(WP_Agent_Bridge_V096_Theme_Files::class, 'search_files', $params)->get_data();
    return new WP_REST_Response(['status' => 200, 'data' => $data]);
}
$includes = ($argv[1] ?? __DIR__ . '/../plugin/wp-agent-bridge/includes') . '/';
require_once $includes . 'class-wp-agent-bridge-v095-outline.php';
require_once $includes . 'class-wp-agent-bridge-v096-theme-files.php';
require_once $includes . 'class-wp-agent-bridge-v098-read-batch.php';
require_once $includes . 'class-wp-agent-bridge-direct-runtime.php';
$root = sys_get_temp_dir() . '/wpab-read-' . bin2hex(random_bytes(8));
mkdir($root);
$GLOBALS['theme_test_root'] = $root;
try {
    file_put_contents($root . '/range.txt', implode("\n", array_map(fn($i) => 'line ' . $i, range(1, 700))));
    $range = fn($params) => WP_Agent_Bridge_V095_Outline::read_range(['path' => 'range.txt'] + $params);
    $data = $range(['start_line' => 120, 'max_lines' => 110])->get_data();
    check($data['end_line'] === 229 && count($data['lines']) === 110, 'max_lines=110 must return exactly 110 lines, not default 200');
    check($range(['start_line' => 120, 'end_line' => 220, 'max_lines' => 110])->get_data()['end_line'] === 220, 'Inclusive end_line must constrain max_lines');
    check(count($range([])->get_data()['lines']) === 200, 'Legacy default changed');
    check(count($range(['max_lines' => 9999])->get_data()['lines']) === 500, 'Range cap bypass');
    check($range(['start_line' => 701])->get_error_data()['status'] === 416, 'Beyond EOF must remain an error');
    check(is_wp_error(WP_Agent_Bridge_V095_Outline::read_range(['path' => '../outside.txt'])), 'Traversal accepted');
    $many = invoke_private(WP_Agent_Bridge_V096_Theme_Files::class, 'read_many', ['files' => [['path' => 'range.txt', 'start_line' => 120, 'max_lines' => 110]]])->get_data();
    check($many['files'][0]['end_line'] === 229, 'read_many dropped max_lines');

    $large = json_encode(['padding' => str_repeat('日本語', 45000), 'terms' => 'sample-one sample-two CYP3A4'], JSON_UNESCAPED_UNICODE);
    file_put_contents($root . '/large.json', $large);
    $hash = hash_file('sha256', $root . '/large.json');
    $search = fn($params) => invoke_private(WP_Agent_Bridge_V096_Theme_Files::class, 'search_files', $params)->get_data();
    $hit = $search(['query' => 'CYP3A4', 'pattern' => '*.json']);
    check($hit['returned'] === 1 && strlen($hit['results'][0]['text']) <= 1024, 'Minified JSON search echoed whole file');
    check(strpos($hit['results'][0]['text'], 'CYP3A4') !== false && mb_check_encoding($hit['results'][0]['text'], 'UTF-8'), 'Match or valid UTF-8 lost');
    check($hit['excerpts_truncated'] && !$hit['matches_truncated'], 'Excerpt and match completeness must be separate');
    check($search(['query' => 'CYP3A4', 'pattern' => '*.php'])['returned'] === 0, 'Search ignored pattern');
    $long_query = str_repeat('薬', 42);
    file_put_contents($root . '/utf8.txt', '前後' . $long_query . '末尾');
    $utf8 = $search(['query' => $long_query, 'pattern' => 'utf8.txt', 'max_excerpt_bytes' => 128]);
    check(strpos($utf8['results'][0]['text'], $long_query) !== false, 'UTF-8 boundary rounding clipped a long query');
    file_put_contents($root . '/context.txt', str_repeat('前', 10000) . "\nneedle\n" . str_repeat('後', 10000));
    $context = $search(['query' => 'needle', 'pattern' => 'context.txt']);
    check(strlen($context['results'][0]['before'][0]['text']) <= 1024 && strlen($context['results'][0]['after'][0]['text']) <= 1024, 'Context lines bypassed excerpt budget');
    file_put_contents($root . '/many.txt', str_repeat(str_repeat('x', 3000) . "needle\n", 200));
    $bounded = $search(['query' => 'needle', 'pattern' => 'many.txt', 'max_results' => 200, 'context_lines' => 0]);
    check(strlen(json_encode($bounded['results'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) <= 65536, 'Aggregate search budget exceeded');
    check($bounded['matches_truncated'] && $bounded['stopped_reason'] === 'response_limit', 'Search omission not disclosed');
    check(hash_file('sha256', $root . '/large.json') === $hash, 'Read modified JSON');

    $batch = fn($operations) => invoke_private(WP_Agent_Bridge_V098_Read_Batch::class, 'readonly_batch', ['operations' => $operations]);
    $operations = array_map(fn($q) => ['action' => 'theme.files.search', 'params' => ['query' => $q, 'pattern' => 'large.json']], ['sample-one', 'sample-two', 'CYP3A4']);
    $response = $batch($operations);
    check($response->get_status() === 200 && $response->get_data()['failed_count'] === 0, 'Three minified-JSON searches still overflow batch');
    echo 'Three-search batch bytes: ' . strlen(json_encode($response->get_data(), JSON_UNESCAPED_UNICODE)) . "\n";
    foreach ([200, 400] as $status) {
        $GLOBALS['injected_response'] = new WP_REST_Response(['status' => $status, 'data' => str_repeat('x', 1100000)]);
        $response = $batch($operations);
        $data = $response->get_data();
        check($response->get_status() === 207 && $data['failed_count'] === 1, 'Oversized failed read was counted twice or hidden');
        check($data['execution_failed_count'] === ($status === 400 ? 1 : 0) && $data['result_omitted_count'] === 1 && $data['not_started_count'] === 2, 'Execution failure / omission / not-started classification incorrect');
        $wrapped = ['ok' => false, 'status' => 207, 'data' => ['status' => 200, 'data' => ['operation' => 'readonly.batch', 'result' => ['status' => 207, 'data' => ['status' => 207, 'data' => $data]]]]];
        $outcome = invoke_private(WP_Agent_Bridge_Direct_Runtime::class, 'summarize_outcome', $wrapped);
        check($outcome['state'] === 'partial' && $outcome['reason'] === 'response_limit' && $outcome['omitted_indexes'] === [0] && $outcome['not_started_indexes'] === [1, 2], 'Outcome lost nested partial batch information');
        check($outcome['failed_indexes'] === ($status === 400 ? [0] : []), 'Omitted output incorrectly reported as failed execution');
    }
    unset($GLOBALS['injected_response']);
    echo "theme-read-reliability-test: ok\n";
} finally {
    foreach (glob($root . '/*') as $file) unlink($file);
    rmdir($root);
}
