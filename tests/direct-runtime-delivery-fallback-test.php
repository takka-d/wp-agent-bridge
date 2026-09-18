<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$hardeningPath = $root . '/plugin/wp-agent-bridge/includes/class-wp-agent-bridge-direct-hardening.php';
$invalidPath = $root . '/plugin/wp-agent-bridge/includes/class-wp-agent-bridge-direct-invalid-pending.php';
$reconcilerPath = $root . '/plugin/wp-agent-bridge/includes/class-wp-agent-bridge-direct-pending-reconciler.php';
$identityPath = $root . '/plugin/wp-agent-bridge/includes/class-wp-agent-bridge-direct-runtime-identity.php';
$bootstrapPath = $root . '/plugin/wp-agent-bridge/wp-agent-bridge.php';

$hardening = file_get_contents($hardeningPath);
$invalid = file_get_contents($invalidPath);
$reconciler = file_get_contents($reconcilerPath);
$identity = file_get_contents($identityPath);
$bootstrap = file_get_contents($bootstrapPath);

foreach (compact('hardening', 'invalid', 'reconciler', 'identity', 'bootstrap') as $name => $source) {
    if (!is_string($source) || $source === '') {
        fwrite(STDERR, "Missing source: {$name}\n");
        exit(1);
    }
}

foreach ([
    "private const RECONCILE_CRON_HOOK = 'wpab_direct_reconcile_cron_v4'",
    'private const RECONCILE_CRON_INTERVAL = 120',
    "add_filter('cron_schedules'",
    "add_action('init', [self::class, 'ensure_reconcile_schedule'], 45)",
    "add_action(self::RECONCILE_CRON_HOOK, [self::class, 'scheduled_reconcile'])",
    'wp_next_scheduled(self::RECONCILE_CRON_HOOK)',
    'wp_schedule_event(',
    "'/repos/' . \$repository",
    "'private'",
    'WP_Agent_Bridge_Direct_GitHub_Recovery::branch_sha(',
    "'commits' => []",
    'self::serialized_webhook($request)',
    "'scheduled_pending_recovery'",
    "'fallback' => 'wp-cron-pending-reconcile'",
    "'wp_cron_disabled'",
] as $needle) {
    if (strpos($hardening, $needle) === false) {
        fwrite(STDERR, "Direct Runtime scheduled recovery is missing: {$needle}\n");
        exit(1);
    }
}

foreach ([
    "private const CRON_HOOK = 'wpab_direct_reconcile_cron_v4'",
    "add_action(self::CRON_HOOK, [self::class, 'run'], 1)",
    "'wordpress-bridge/commands/invalid/'",
    "'wpab_pending_invalid_json'",
    "'command_execution_finished' => false",
    "'side_effects' => false",
    'put_if_absent_or_identical(',
    'delete_if_matches(',
    "'invalid_pending_quarantine'",
] as $needle) {
    if (strpos($invalid, $needle) === false) {
        fwrite(STDERR, "Invalid pending quarantine is missing: {$needle}\n");
        exit(1);
    }
}

foreach ([
    "private const CRON_HOOK = 'wpab_direct_reconcile_cron_v4'",
    "add_action(self::CRON_HOOK, [self::class, 'run'], 20)",
    "'pending_recovery_detail'",
    "'executor-command-failed'",
    "'executor-returned-without-result'",
    "'target-command-outcome-missing'",
    "'result-visible-pending-not-finalized'",
    "'pending_recovery_terminal_observation'",
    "'recovery_required' => true",
    'WP_Agent_Bridge_Direct_Runtime::command_inflight(',
    'WP_Agent_Bridge_Direct_Runtime::webhook($request)',
] as $needle) {
    if (strpos($reconciler, $needle) === false) {
        fwrite(STDERR, "Detailed pending reconciler is missing: {$needle}\n");
        exit(1);
    }
}

foreach ([
    "'transport' => 'direct-github-webhook'",
    "'primary' => 'github-push-webhook'",
    "'fallback' => 'wp-cron-pending-reconcile'",
    "'same_transport' => true",
    "'fallback_interval_seconds' => 120",
    "'operator_relay' => false",
] as $needle) {
    if (strpos($identity, $needle) === false) {
        fwrite(STDERR, "Canonical runtime identity is missing delivery recovery metadata: {$needle}\n");
        exit(1);
    }
}

if (!preg_match('/^ \* Version: ([0-9]+\.[0-9]+\.[0-9]+)$/m', $bootstrap, $version_match)
    || version_compare($version_match[1], '1.1.30', '<')) {
    fwrite(STDERR, "Plugin bootstrap version predates pending recovery support.\n");
    exit(1);
}

foreach ([
    "class-wp-agent-bridge-direct-invalid-pending.php",
    'WP_Agent_Bridge_Direct_Invalid_Pending::init()',
    "class-wp-agent-bridge-direct-pending-reconciler.php",
    'WP_Agent_Bridge_Direct_Pending_Reconciler::init()',
] as $needle) {
    if (strpos($bootstrap, $needle) === false) {
        fwrite(STDERR, "Plugin bootstrap is missing pending recovery marker: {$needle}\n");
        exit(1);
    }
}

foreach ([$hardeningPath, $invalidPath, $reconcilerPath, $identityPath, $bootstrapPath] as $path) {
    $source = file_get_contents($path);
    try {
        $tokens = token_get_all((string) $source, TOKEN_PARSE);
    } catch (ParseError $e) {
        fwrite(STDERR, basename($path) . ' failed PHP parse validation: ' . $e->getMessage() . "\n");
        exit(1);
    }
    if (!is_array($tokens) || !$tokens) {
        fwrite(STDERR, basename($path) . " did not parse as PHP.\n");
        exit(1);
    }
}

echo "direct-runtime-delivery-fallback-test: ok\n";
