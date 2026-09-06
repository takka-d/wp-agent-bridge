<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$bootstrap = file_get_contents($root . '/plugin/wp-agent-bridge/takka-wordpress-bridge.php');
$site = file_get_contents($root . '/plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-v096-site.php');
$hook = file_get_contents($root . '/plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-v096-media-hook.php');
$themeFiles = file_get_contents($root . '/plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-v096-theme-files.php');
$identity = file_get_contents($root . '/plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-direct-runtime-identity.php');
$options = file_get_contents($root . '/plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-v08-options.php');

foreach (compact('bootstrap', 'site', 'hook', 'themeFiles', 'identity', 'options') as $name => $value) {
    if (!is_string($value) || $value === '') {
        fwrite(STDERR, "missing source: {$name}\n");
        exit(1);
    }
}

foreach ([
    'class-takka-wordpress-bridge-v096-site.php',
    'class-takka-wordpress-bridge-v096-media-hook.php',
    'class-takka-wordpress-bridge-v096-theme-files.php',
    'TakKa_WordPress_Bridge_V096_Site::init()',
    'TakKa_WordPress_Bridge_V096_Media_Hook::init()',
    'TakKa_WordPress_Bridge_V096_Theme_Files::init()',
] as $needle) {
    if (strpos($bootstrap, $needle) === false) {
        fwrite(STDERR, "bootstrap missing {$needle}\n");
        exit(1);
    }
}

foreach ([
    'site.icon.get',
    'site.icon.set',
    'site.icon.clear',
    'media.upload.capabilities',
    "get_option('site_icon'",
    "update_option('site_icon'",
    "delete_option('site_icon'",
    'expected_current_id',
    'wp_attachment_is_image',
    'recommended_512_or_larger',
    'google_drive_file_after_agent_retrieval',
] as $needle) {
    if (strpos($site, $needle) === false) {
        fwrite(STDERR, "site icon surface missing {$needle}\n");
        exit(1);
    }
}

foreach ([
    '/wp-agent-bridge-runtime/v1/media-upload',
    'set_site_icon',
    'confirm_site_icon',
    'set_uploaded_attachment',
    'uploaded_attachment_retained',
    "current_user_can('manage_options')",
] as $needle) {
    if (strpos($hook, $needle) === false) {
        fwrite(STDERR, "media hook missing {$needle}\n");
        exit(1);
    }
}

foreach ([
    'theme.files.list',
    'theme.files.search',
    'theme.file.read.many',
    'RecursiveDirectoryIterator',
    'MAX_SCAN_BYTES',
    'MAX_READ_ITEMS',
    'TakKa_WordPress_Bridge_V095_Outline::read_range',
] as $needle) {
    if (strpos($themeFiles, $needle) === false) {
        fwrite(STDERR, "theme files surface missing {$needle}\n");
        exit(1);
    }
}

if (strpos($options, 'Surgical option patching currently supports array-valued options only.') === false) {
    fwrite(STDERR, "expected safe scalar-option guard changed unexpectedly\n");
    exit(1);
}

foreach ([
    '## Site Icon / favicon and local or Drive media',
    'site.icon.set',
    'set_site_icon=true',
    'confirm_site_icon=true',
    'Google Drive',
    'retrieve/download the file through the agent/connector first',
] as $needle) {
    if (strpos($identity, $needle) === false) {
        fwrite(STDERR, "runtime guidance missing {$needle}\n");
        exit(1);
    }
}

$combined = $site . "\n" . $hook . "\n" . $themeFiles;
foreach (['shell_exec(', 'passthru(', 'proc_open(', 'popen(', 'system('] as $forbidden) {
    if (strpos($combined, $forbidden) !== false) {
        fwrite(STDERR, "unsafe execution primitive present: {$forbidden}\n");
        exit(1);
    }
}

foreach (['google_client_secret', 'drive_refresh_token', 'drive_access_token'] as $forbidden) {
    if (stripos($combined, $forbidden) !== false) {
        fwrite(STDERR, "WP Agent Bridge must not embed Google Drive credentials: {$forbidden}\n");
        exit(1);
    }
}

echo "Site Icon, connector media, and theme-file batch regression passed.\n";
