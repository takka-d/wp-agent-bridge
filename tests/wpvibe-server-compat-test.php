<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$bootstrap = file_get_contents($root . '/plugin/wp-agent-bridge/takka-wordpress-bridge.php');
$compat = file_get_contents($root . '/plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-v095-compat.php');
$outline = file_get_contents($root . '/plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-v095-outline.php');
$html = file_get_contents($root . '/plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-v095-html.php');
$classic = file_get_contents($root . '/plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-v095-classic.php');
$identity = file_get_contents($root . '/plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-direct-runtime-identity.php');

foreach (compact('bootstrap', 'compat', 'outline', 'html', 'classic', 'identity') as $name => $value) {
    if (!is_string($value) || $value === '') {
        fwrite(STDERR, "missing source: {$name}\n");
        exit(1);
    }
}

$must_boot = [
    'class-takka-wordpress-bridge-v095-outline.php',
    'class-takka-wordpress-bridge-v095-html.php',
    'class-takka-wordpress-bridge-v095-classic.php',
    'class-takka-wordpress-bridge-v095-compat.php',
    'TakKa_WordPress_Bridge_V095_Compat::init()',
];
foreach ($must_boot as $needle) {
    if (strpos($bootstrap, $needle) === false) {
        fwrite(STDERR, "bootstrap missing {$needle}\n");
        exit(1);
    }
}

foreach ([
    'theme.file.outline',
    'theme.file.read.range',
    'page.html.inspect',
    'classic_theme.create',
    'classic_theme.preview_url',
    'classic_theme.publish',
    'classic_theme.discard',
    'javascript_executed',
    'not_a_browser_runtime',
] as $needle) {
    if (strpos($compat, $needle) === false) {
        fwrite(STDERR, "compat surface missing {$needle}\n");
        exit(1);
    }
}

foreach (['token_get_all', 'register_rest_route', 'customElements', 'addEventListener', 'start_line', 'end_line'] as $needle) {
    if (strpos($outline, $needle) === false) {
        fwrite(STDERR, "outline surface missing {$needle}\n");
        exit(1);
    }
}

foreach (['wp_safe_remote_get', 'same_origin', "'cookies' => []", 'DOMDocument', 'DOMXPath', 'javascript_executed', 'false'] as $needle) {
    if (strpos($html, $needle) === false) {
        fwrite(STDERR, "html inspection missing {$needle}\n");
        exit(1);
    }
}
if (strpos($html, 'takka_bridge_v095_cross_origin') === false) {
    fwrite(STDERR, "cross-origin guard missing\n");
    exit(1);
}

foreach (['takka_bridge_v095_classic_drafts', 'wpab_classic_preview', 'switch_theme', 'previous_theme', 'Cannot discard a currently active classic theme'] as $needle) {
    if (strpos($classic, $needle) === false) {
        fwrite(STDERR, "classic theme surface missing {$needle}\n");
        exit(1);
    }
}

$combined = $compat . "\n" . $outline . "\n" . $html . "\n" . $classic;
foreach (['shell_exec(', 'passthru(', 'proc_open(', 'popen(', 'system('] as $forbidden) {
    if (strpos($combined, $forbidden) !== false) {
        fwrite(STDERR, "unsafe execution primitive present: {$forbidden}\n");
        exit(1);
    }
}

foreach (['WPVibe compatibility', 'theme.file.outline', 'page.html.inspect', 'classic_theme.create'] as $needle) {
    if (strpos($identity, $needle) === false) {
        fwrite(STDERR, "runtime guidance missing {$needle}\n");
        exit(1);
    }
}

echo "WPVibe server compatibility regression passed.\n";
