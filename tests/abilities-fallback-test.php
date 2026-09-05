<?php

$root = dirname(__DIR__);
$bootstrap = file_get_contents($root . '/plugin/wp-agent-bridge/takka-wordpress-bridge.php');
$ability = file_get_contents($root . '/plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-abilities-fallback.php');

if (!is_string($bootstrap) || !is_string($ability)) {
    fwrite(STDERR, "Could not read WP Agent Bridge ability sources.\n");
    exit(1);
}

$requiredBootstrap = [
    "class-takka-wordpress-bridge-abilities-fallback.php",
    'TakKa_WordPress_Bridge_Abilities_Fallback::init();',
];
foreach ($requiredBootstrap as $needle) {
    if (strpos($bootstrap, $needle) === false) {
        fwrite(STDERR, "Missing abilities fallback bootstrap: {$needle}\n");
        exit(1);
    }
}

$requiredAbility = [
    "wp-agent-bridge/run-command",
    "wp-agent-bridge/status",
    "wp_register_ability_category",
    "wp_register_ability",
    "'public' => true",
    "'show_in_rest' => true",
    "current_user_can('manage_options')",
    "action' => 'rest.call",
    "X-TakKa-Signature",
    "GitHub connector availability is not required",
];
foreach ($requiredAbility as $needle) {
    if (strpos($ability, $needle) === false) {
        fwrite(STDERR, "Missing abilities fallback behavior: {$needle}\n");
        exit(1);
    }
}

echo "abilities-fallback-test: ok\n";
