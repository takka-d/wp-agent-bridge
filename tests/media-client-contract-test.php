<?php
// Receive output of the actual Python client and validate with PHP consumers.
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');

final class WP_Error {
    private $code;
    public function __construct($code = '', $message = '', $data = null) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error($value): bool { return $value instanceof WP_Error; }
function current_user_can($capability): bool { return $capability === 'manage_options'; }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
final class WP_REST_Request {
    private $body;
    public function get_method(): string { return 'POST'; }
    public function get_route(): string { return '/takka-v099/v1/operate'; }
    public function get_body(): string { return $this->body; }
    public function set_body(string $body): void { $this->body = $body; }
    public function set_header(string $name, string $value): void {}
}
require_once __DIR__ . '/../plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-v099-policy.php';
require_once __DIR__ . '/../plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-direct-media-auto-path.php';

function contract_fail(string $message): void {
    fwrite(STDERR, $message . "\n");
    exit(1);
}
function consumer(string $name, ...$args) {
    $method = new ReflectionMethod('TakKa_WordPress_Bridge_Direct_Media_Auto_Path', $name);
    $method->setAccessible(true);
    $result = $method->invoke(null, ...$args);
    if (is_wp_error($result)) contract_fail($name . ': ' . $result->get_error_code());
    return $result;
}

$package = json_decode(stream_get_contents(STDIN), true);
if (!is_array($package)) contract_fail('Missing generated package.');
$command = $package['command'];
if ($command['type'] === 'operation') {
    $request = new WP_REST_Request();
    $request->set_body(wp_json_encode($command));
    $result = TakKa_WordPress_Bridge_V099_Policy::apply(null, [], $request);
    if ($result !== null) contract_fail('Generated inline command rejected by the actual policy.');
    $params = $command['params'];
    $binary = base64_decode($params['data_b64'], true);
} else {
    $params = $command['body'];
    $paths = consumer('payload_paths', $params);
    $integrity = consumer('chunk_integrity', $params, $paths);
    $sources = [];
    foreach ($package['tree'] as $entry) $sources[$entry['path']] = $entry['content'];
    $binary = '';
    foreach ($paths as $index => $path) {
        $chunk = consumer('decode_base64', $sources[$path]);
        if (strlen($chunk) !== $integrity[$index]['expected_bytes']
            || hash('sha256', $chunk) !== $integrity[$index]['expected_sha256']) {
            contract_fail('Generated chunk metadata rejected.');
        }
        $binary .= $chunk;
    }
}
if (!is_string($binary) || strlen($binary) !== $params['expected_bytes']
    || hash('sha256', $binary) !== $params['expected_sha256']) {
    contract_fail('Generated media does not match the declared original.');
}
echo "media-client-contract-test: ok\n";
