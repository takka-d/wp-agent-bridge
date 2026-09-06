<?php
$root = dirname(__DIR__);
$diag = file_get_contents($root . '/plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-v094-diagnostics.php');
$boot = file_get_contents($root . '/plugin/wp-agent-bridge/takka-wordpress-bridge.php');
if (!is_string($diag) || !is_string($boot)) {
    fwrite(STDERR, "Diagnostics compatibility sources are missing.\n");
    exit(1);
}
$required = [
    "'http.probe'",
    "'http.probe.batch'",
    "'media.file.inspect'",
    "'same_origin_only'=>true",
    "'cookies_sent'=>false",
    "'authorization_header_allowed'=>false",
    'wp_safe_remote_request',
    "'android_chrome_image'",
    "'Sec-Fetch-Dest'=>'image'",
    "'mime_from_magic'",
    "'magic_prefix_hex'",
    "'search'=>",
    "'uploads_directory_only_for_url_lookup'=>true",
];
foreach ($required as $needle) {
    if (strpos($diag, $needle) === false) {
        fwrite(STDERR, "Missing diagnostics requirement: {$needle}\n");
        exit(1);
    }
}
foreach (['authorization','cookie'] as $blocked) {
    if (strpos($diag, "HEADER_ALLOW=['{$blocked}'") !== false) {
        fwrite(STDERR, "Sensitive header unexpectedly allowlisted: {$blocked}\n");
        exit(1);
    }
}
if (strpos($boot, "require_once __DIR__ . '/includes/class-takka-wordpress-bridge-v094-diagnostics.php';") === false
    || strpos($boot, 'TakKa_WordPress_Bridge_V094_Diagnostics::init();') === false) {
    fwrite(STDERR, "Diagnostics module is not bootstrapped.\n");
    exit(1);
}
echo "diagnostics-compat-test: ok\n";
