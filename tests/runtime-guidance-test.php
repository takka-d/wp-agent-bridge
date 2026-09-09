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
    'Client tool availability',
    'If absent, use an available tool/plugin discovery facility once',
    'client-side limit',
    'without invoking image generation or editing',
    'unavailable original image bytes',
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

$prompt = TakKa_WordPress_Bridge_Runtime_Guidance::client_prompt('alice/site-runtime', 'wp-agent-bridge-runtime', 'site.example');
$other_prompt = TakKa_WordPress_Bridge_Runtime_Guidance::client_prompt('bob/other-runtime', 'wp-agent-bridge-runtime', 'other.example');
foreach (['alice/site-runtime', 'site.example', 'RUNTIME_CONNECTION.json', 'RUNTIME_CAPABILITIES.json', 'status=canonical', 'operator_relay=false', '記事の変更やアップロードを実行せず'] as $required) {
    if (strpos($prompt, $required) === false) guidance_fail('Client start prompt is missing: ' . $required);
}
if (strpos($other_prompt, 'bob/other-runtime') === false || strpos($other_prompt, 'other.example') === false
    || strpos($other_prompt, 'alice/site-runtime') !== false || strpos($other_prompt, 'site.example') !== false) {
    guidance_fail('Client start prompt leaked a different connection target.');
}
echo "client-start-prompt: ok\n";

require_once __DIR__ . '/runtime-sync-fixture.php';
verify_runtime_sync('TakKa_WordPress_Bridge_Runtime_Guidance', 'takka_bridge_runtime_guidance_synced_version', ['AGENTS.md', 'wordpress-bridge/WEBHOOK_RUNTIME.md', 'wordpress-bridge/prepare-media.py']);

$synced_preparer = $GLOBALS['sync_files']['wordpress-bridge/prepare-media.py'];
$bundled_preparer = file_get_contents(__DIR__ . '/../plugin/wp-agent-bridge/client/prepare-media.py.txt');
if (substr($synced_preparer, strpos($synced_preparer, "\n") + 1) !== $bundled_preparer) {
    guidance_fail('Runtime media preparer differs from the tested bundled implementation.');
}
echo "same-version runtime sync: ok\n";
