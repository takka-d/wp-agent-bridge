<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

require_once __DIR__ . '/../plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-runtime-guidance.php';

function guidance_fail(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$agents = TakKa_WordPress_Bridge_Runtime_Guidance::agents(
    'owner/runtime-repo',
    'wp-agent-bridge-runtime',
    'example.test',
    '1.1.16'
);
$runtime = TakKa_WordPress_Bridge_Runtime_Guidance::runtime(
    'owner/runtime-repo',
    'wp-agent-bridge-runtime',
    'example.test',
    '1.1.16'
);

foreach ([
    'type=bridge',
    'use `type=operation`',
    'once per verified connection/version',
    '`post.get` is not currently a batch item',
    'leave visual correctness unverified',
    'Preferred pending JSON',
    'Keep one command ID and identical payload',
    '207 read batch is incomplete',
    '/takka-v099/v1/operate',
    'RUNTIME_CAPABILITIES.json',
    'media.upload.inline',
    'post.content.read_range',
    'post.get` is metadata-oriented',
    'post.update` must not replace `content`',
    'expected_bytes',
    'expected_sha256',
    'Nested items may use the same high-level operation names',
    'readonly.batch',
    'GitHub Actions is for source CI/package/release',
    'Never put `?query=...` in a REST `route` field',
    'Do not ask the user',
    'Do not switch normal WordPress work to WPVibe, `takka-d/chatgpt-data`',
    'A visible matching result is the completion barrier',
] as $required) {
    if (strpos($agents, $required) === false) {
        guidance_fail('Canonical AGENTS guidance is missing: ' . $required);
    }
}

foreach ([
    'Choose the batched staged-media route by default',
    'Pending command shape: `type=rest`',
    'unless inline upload actually fails',
    'Media default: batched staged upload',
    'Save this value in the GitHub Actions repository secret',
] as $forbidden) {
    if (strpos($agents, $forbidden) !== false) {
        guidance_fail('Canonical AGENTS guidance contains stale routing text: ' . $forbidden);
    }
}

if (strpos($runtime, '/takka-v099/v1/operate') === false
    || strpos($runtime, 'GitHub Actions is not a command worker') === false
    || strpos($runtime, 'policy layer bounds post reads') === false
    || strpos($runtime, 'Installed Bridge: `1.1.16`') === false) {
    guidance_fail('WEBHOOK_RUNTIME guidance is incomplete.');
}

echo "runtime-guidance-test: ok\n";
