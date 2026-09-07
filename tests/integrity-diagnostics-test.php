<?php

$helperPath = __DIR__ . '/../plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-integrity.php';
$bootstrapPath = __DIR__ . '/../plugin/wp-agent-bridge/takka-wordpress-bridge.php';

$helper = file_get_contents($helperPath);
$bootstrap = file_get_contents($bootstrapPath);

if (!is_string($helper)) {
    fwrite(STDERR, "Integrity helper is missing.\n");
    exit(1);
}
if (!is_string($bootstrap)) {
    fwrite(STDERR, "Plugin bootstrap is missing.\n");
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

$tokens = token_get_all($helper, TOKEN_PARSE);
if (!is_array($tokens) || !$tokens) {
    fwrite(STDERR, "Integrity helper did not parse as PHP.\n");
    exit(1);
}

echo "integrity-diagnostics-test: ok\n";
