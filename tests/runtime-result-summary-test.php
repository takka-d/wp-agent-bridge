<?php
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
require_once __DIR__ . '/../plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-direct-runtime.php';
$summary = new ReflectionMethod(TakKa_WordPress_Bridge_Direct_Runtime::class, 'summarize_command');
$summary->setAccessible(true);
$sanitize = new ReflectionMethod(TakKa_WordPress_Bridge_Direct_Runtime::class, 'sanitize_result');
$sanitize->setAccessible(true);
$binary = str_repeat('a', 157088);
$encoded = base64_encode($binary);
$command = ['type' => 'operation', 'operation' => 'media.upload.inline', 'params' => [
    'filename' => 'test.png', 'data_b64' => $encoded, 'expected_sha256' => hash('sha256', $binary), 'token' => 'not-for-results',
]];
$result = $summary->invoke(null, $command);
$meta = $result['params']['data_b64'] ?? [];
if (empty($meta['omitted']) || ($meta['decoded_bytes'] ?? null) !== strlen($binary)
    || ($meta['encoded_bytes'] ?? null) !== strlen($encoded)
    || ($meta['sha256'] ?? null) !== hash('sha256', $binary)
    || $result['params']['filename'] !== 'test.png'
    || $result['params']['token'] !== '[redacted]'
    || strlen(json_encode($result)) > 1000) {
    fwrite(STDERR, "Media input was not compacted accurately.\n"); exit(1);
}
foreach (['payload_b64', 'content_b64'] as $key) {
    $nested = $summary->invoke(null, ['params' => ['body' => [$key => $encoded]]]);
    if (empty($nested['params']['body'][$key]['omitted'])) {
        fwrite(STDERR, "Nested input Base64 was echoed.\n"); exit(1);
    }
}
$output = ['data_b64' => $encoded];
if ($sanitize->invoke(null, $output) !== $output) {
    fwrite(STDERR, "Requested result data was incorrectly omitted.\n"); exit(1);
}
echo "runtime-result-summary-test: ok\n";
