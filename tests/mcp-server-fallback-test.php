<?php

$root = dirname(__DIR__);
$bootstrap = file_get_contents($root . '/plugin/wp-agent-bridge/takka-wordpress-bridge.php');
$mcp = file_get_contents($root . '/plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-mcp-server.php');

if (!is_string($bootstrap) || !is_string($mcp)) {
    fwrite(STDERR, "Could not read WP Agent Bridge MCP fallback sources.\n");
    exit(1);
}

$requiredBootstrap = [
    'class-takka-wordpress-bridge-mcp-server.php',
    'TakKa_WordPress_Bridge_MCP_Server::init();',
];
foreach ($requiredBootstrap as $needle) {
    if (strpos($bootstrap, $needle) === false) {
        fwrite(STDERR, "Missing MCP fallback bootstrap: {$needle}\n");
        exit(1);
    }
}

$requiredMcp = [
    'wp-agent-bridge-mcp-server',
    'wp-agent-bridge-mcp',
    "'server'",
    'WP\\MCP\\Transport\\HttpTransport',
    'wp-agent-bridge/run-command',
    "current_user_can('manage_options')",
    'mcp_adapter_init',
];
foreach ($requiredMcp as $needle) {
    if (strpos($mcp, $needle) === false) {
        fwrite(STDERR, "Missing dedicated MCP fallback behavior: {$needle}\n");
        exit(1);
    }
}

echo "mcp-server-fallback-test: ok\n";
