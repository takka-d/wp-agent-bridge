<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Dedicated MCP surface for WP Agent Bridge.
 *
 * The GitHub Direct Runtime remains available, but this server gives ChatGPT
 * and other MCP-aware clients a stable GitHub-independent transport when the
 * official WordPress MCP Adapter is active.
 */
final class TakKa_WordPress_Bridge_MCP_Server
{
    private static $registered = false;

    public static function init(): void
    {
        add_action('mcp_adapter_init', [self::class, 'register_server'], 20, 1);

        // Converge when WP Agent Bridge is loaded after the adapter init hook.
        if (did_action('mcp_adapter_init') && class_exists('WP\\MCP\\Core\\McpAdapter')) {
            self::register_server(\WP\MCP\Core\McpAdapter::instance());
        }
    }

    public static function register_server($adapter = null): void
    {
        if (self::$registered) {
            return;
        }
        if (!class_exists('WP\\MCP\\Core\\McpAdapter')
            || !class_exists('WP\\MCP\\Transport\\HttpTransport')
            || !class_exists('WP\\MCP\\Infrastructure\\ErrorHandling\\ErrorLogMcpErrorHandler')) {
            return;
        }

        if (!is_object($adapter) || !method_exists($adapter, 'create_server')) {
            $adapter = \WP\MCP\Core\McpAdapter::instance();
        }
        if (!is_object($adapter) || !method_exists($adapter, 'create_server')) {
            return;
        }

        $result = $adapter->create_server(
            'wp-agent-bridge-mcp-server',
            'wp-agent-bridge-mcp',
            'server',
            'WP Agent Bridge',
            'Guarded WordPress management through WP Agent Bridge without requiring the GitHub connector in the current chat.',
            '1.0.0',
            [\WP\MCP\Transport\HttpTransport::class],
            \WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
            null,
            ['wp-agent-bridge/run-command'],
            [],
            [],
            [self::class, 'can_access']
        );

        if (!is_wp_error($result)) {
            self::$registered = true;
        }
    }

    public static function can_access(): bool
    {
        return current_user_can('manage_options');
    }
}
