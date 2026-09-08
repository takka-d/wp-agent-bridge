<?php

$bytes = '';
for ($i = 0; $i < 41946; $i++) {
    $bytes .= chr(($i * 131 + 17) & 0xff);
}

$expectedBytes = strlen($bytes);
$expectedSha = hash('sha256', $bytes);
$binaryChunkBytes = 6000;
$payloads = [];

for ($offset = 0; $offset < $expectedBytes; $offset += $binaryChunkBytes) {
    $chunk = substr($bytes, $offset, $binaryChunkBytes);
    $encoded = base64_encode($chunk);
    $payloads[] = $encoded;
}

$rebuilt = '';
foreach ($payloads as $encoded) {
    $decoded = base64_decode($encoded, true);
    if ($decoded === false) {
        fwrite(STDERR, "Strict Base64 decode failed.\n");
        exit(1);
    }
    $rebuilt .= $decoded;
}

if (strlen($rebuilt) !== $expectedBytes) {
    fwrite(STDERR, "Rebuilt byte count mismatch.\n");
    exit(1);
}
if (!hash_equals($expectedSha, hash('sha256', $rebuilt))) {
    fwrite(STDERR, "Rebuilt SHA-256 mismatch.\n");
    exit(1);
}
if (count($payloads) !== 7) {
    fwrite(STDERR, "Unexpected payload count for 41,946-byte regression fixture.\n");
    exit(1);
}

$identity = file_get_contents(__DIR__ . '/../plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-direct-runtime-identity.php');
if (!is_string($identity)
    || strpos($identity, "'wordpress-bridge/RUNTIME_CONNECTION.json' => \$marker") === false
    || strpos($identity, "'wordpress-bridge/media/pending/.gitkeep' => ''") === false
    || strpos($identity, "'guidance_owned_by' => TakKa_WordPress_Bridge_Runtime_Guidance::class") === false) {
    fwrite(STDERR, "Runtime identity no longer has the expected canonical-marker-only ownership boundary.\n");
    exit(1);
}
foreach ([
    '## Mandatory fast path',
    'media.upload.inline',
    'Split original binary before Base64',
    'Never put `?query=...` in a REST `route` field',
] as $forbiddenIdentityGuidance) {
    if (strpos($identity, $forbiddenIdentityGuidance) !== false) {
        fwrite(STDERR, "Runtime identity must not own model routing guidance: {$forbiddenIdentityGuidance}\n");
        exit(1);
    }
}

$guidance = file_get_contents(__DIR__ . '/../plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-runtime-guidance.php');
if (!is_string($guidance)) {
    fwrite(STDERR, "Runtime guidance generator is unavailable.\n");
    exit(1);
}
foreach ([
    '## Mandatory fast path',
    'Reuse this repository/branch when already verified in the current task/session',
    'Read `wordpress-bridge/RUNTIME_CAPABILITIES.json` before probing Bridge capabilities',
    'Do not switch normal WordPress work to WPVibe',
    'Small local/conversation media up to 1 MiB decoded: one `media.upload.inline` operation',
    'Larger media: staged Media Fast Path',
    'Split original binary before Base64',
    'publish payloads + pending upload command in one Git commit',
    'use verify-only only for explicit verification or integrity diagnosis',
    'media integrity 409: replace only mismatched staging payloads when identifiable',
] as $requiredGuidance) {
    if (strpos($guidance, $requiredGuidance) === false) {
        fwrite(STDERR, "Runtime guidance is missing staged-media/canonical fast-path marker: {$requiredGuidance}\n");
        exit(1);
    }
}

$auto = file_get_contents(__DIR__ . '/../plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-direct-media-auto-path.php');
if (!is_string($auto)
    || strpos($auto, "\$upload['automatic_for_staged_data_paths'] = true") === false
    || strpos($auto, "\$upload['data_blob_shas_optional'] = true") === false
    || strpos($auto, "\$upload['preferred_publish_mode'] = 'single-inline-tree-commit-when-supported'") === false
    || strpos($auto, 'single-recursive-tree-read') === false
    || strpos($auto, 'git-tree-auto-resolve') === false
    || strpos($auto, "array_key_exists('data_blob_shas', \$json)") === false
    || strpos($auto, 'chunk_integrity') === false
    || strpos($auto, "'/git/trees/'") === false
    || strpos($auto, "'/git/blobs/'") === false
    || strpos($auto, 'cleanup_snapshot_reuse') === false) {
    fwrite(STDERR, "Automatic staged-media Git-tree resolution path is incomplete.\n");
    exit(1);
}
if (strpos($auto, "'sha' => null") === false
    || strpos($auto, "['sha' => \$commit_sha, 'force' => false]") === false) {
    fwrite(STDERR, "Automatic staged-media cleanup guards are incomplete.\n");
    exit(1);
}

$bootstrap = file_get_contents(__DIR__ . '/../plugin/wp-agent-bridge/takka-wordpress-bridge.php');
if (!is_string($bootstrap)
    || strpos($bootstrap, 'class-takka-wordpress-bridge-direct-media.php') === false
    || strpos($bootstrap, 'TakKa_WordPress_Bridge_Direct_Media::init()') === false
    || strpos($bootstrap, 'class-takka-wordpress-bridge-direct-media-auto-path.php') === false
    || strpos($bootstrap, 'TakKa_WordPress_Bridge_Direct_Media_Auto_Path::init()') === false) {
    fwrite(STDERR, "Automatic staged-media fast path or legacy fallback is not loaded by the plugin bootstrap.\n");
    exit(1);
}
if (strpos($bootstrap, 'class-takka-wordpress-bridge-direct-media-fast-path.php') !== false
    || strpos($bootstrap, 'TakKa_WordPress_Bridge_Direct_Media_Fast_Path::init()') !== false
    || file_exists(__DIR__ . '/../plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-direct-media-fast-path.php')) {
    fwrite(STDERR, "Redundant caller-pinned media fast path must not remain registered or packaged.\n");
    exit(1);
}

$guard = file_get_contents(__DIR__ . '/../plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-direct-onboarding-guard.php');
if (!is_string($guard)
    || strpos($guard, 'sync_identity_guidance_if_needed') === false
    || strpos($guard, 'IDENTITY_SYNC_VERSION_OPTION') === false
    || strpos($guard, 'IDENTITY_SYNC_RETRY') === false
    || strpos($guard, 'private const IDENTITY_SYNC_VERSION = 3;') === false
    || strpos($guard, 'sync_identity_and_guidance()') === false
    || strpos($guard, 'TakKa_WordPress_Bridge_Direct_Runtime_Identity::sync()') === false
    || strpos($guard, 'TakKa_WordPress_Bridge_Runtime_Guidance::sync()') === false
    || strpos($guard, '10 * MINUTE_IN_SECONDS') === false) {
    fwrite(STDERR, "Existing-runtime identity/guidance refresh guard is incomplete.\n");
    exit(1);
}
if (strpos($guard, 'sync_resolution_guidance') !== false || strpos($guard, 'put_guidance_if_changed') !== false) {
    fwrite(STDERR, "Runtime guidance must be generated by Runtime_Guidance::sync(), not patched after generation.\n");
    exit(1);
}

$hardening = file_get_contents(__DIR__ . '/../plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-direct-hardening.php');
if (!is_string($hardening)
    || strpos($hardening, 'replace_runtime_webhook') === false
    || strpos($hardening, 'serialized_webhook') === false
    || strpos($hardening, 'PRIMARY_LOCK_OPTION') === false
    || strpos($hardening, 'TakKa_WordPress_Bridge_Direct_Runtime_V2::webhook($request)') === false
    || strpos($hardening, "'retryable' => true") === false) {
    fwrite(STDERR, "Direct Runtime primary webhook serialization hardening is missing.\n");
    exit(1);
}

echo "media-binary-chunk-staging-test: ok\n";
