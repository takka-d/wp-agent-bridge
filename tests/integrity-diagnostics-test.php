<?php

$helperPath = __DIR__ . '/../plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-integrity.php';
$bootstrapPath = __DIR__ . '/../plugin/wp-agent-bridge/takka-wordpress-bridge.php';
$identityPath = __DIR__ . '/../plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-direct-runtime-identity.php';

$helper = file_get_contents($helperPath);
$bootstrap = file_get_contents($bootstrapPath);
$identity = file_get_contents($identityPath);

if (!is_string($helper)) {
    fwrite(STDERR, "Integrity helper is missing.\n");
    exit(1);
}
if (!is_string($bootstrap)) {
    fwrite(STDERR, "Plugin bootstrap is missing.\n");
    exit(1);
}
if (!is_string($identity)) {
    fwrite(STDERR, "Direct Runtime identity guidance is missing.\n");
    exit(1);
}

$requiredHelperMarkers = [
    "'/media-verify'",
    "'chunk_integrity'",
    "'wpab_direct_media_integrity_mismatch'",
    "'require_integrity'",
    "'expected_content_sha256'",
    "'expected_current_sha256'",
    "'expected_current_absent'",
    "'rest_request_before_callbacks'",
    "'rest_request_after_callbacks'",
    "'side_effects' => false",
    "'source_cleanup' => false",
];

foreach ($requiredHelperMarkers as $marker) {
    if (strpos($helper, $marker) === false) {
        fwrite(STDERR, "Integrity helper is missing marker: {$marker}\n");
        exit(1);
    }
}

if (strpos($bootstrap, "require_once __DIR__ . '/includes/class-takka-wordpress-bridge-integrity.php';") === false
    || strpos($bootstrap, 'TakKa_WordPress_Bridge_Integrity::init();') === false) {
    fwrite(STDERR, "Integrity helper is not registered by the plugin bootstrap.\n");
    exit(1);
}

$requiredIdentityMarkers = [
    '## Exact theme asset writes',
    'require_integrity=true',
    'expected_content_sha256',
    'expected_current_sha256',
    'expected_current_absent=true',
    '/wp-agent-bridge-runtime/v1/media-verify',
    'chunk_integrity',
    'chunk_diagnostics',
    'replace only the mismatched staged payload file(s)',
];
foreach ($requiredIdentityMarkers as $marker) {
    if (strpos($identity, $marker) === false) {
        fwrite(STDERR, "Runtime identity guidance is missing marker: {$marker}\n");
        exit(1);
    }
}

foreach ([$helperPath, $identityPath, $bootstrapPath] as $path) {
    $source = file_get_contents($path);
    try {
        $tokens = token_get_all($source, TOKEN_PARSE);
    } catch (ParseError $e) {
        fwrite(STDERR, basename($path) . ' failed PHP parse validation: ' . $e->getMessage() . "\n");
        exit(1);
    }
    if (!is_array($tokens) || !$tokens) {
        fwrite(STDERR, basename($path) . " did not parse as PHP.\n");
        exit(1);
    }
}

echo "integrity-diagnostics-test: ok\n";
