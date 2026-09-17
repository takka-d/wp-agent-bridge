<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$hardeningPath = $root . '/plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-direct-hardening.php';
$identityPath = $root . '/plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-direct-runtime-identity.php';
$bootstrapPath = $root . '/plugin/wp-agent-bridge/takka-wordpress-bridge.php';

$hardening = file_get_contents($hardeningPath);
$identity = file_get_contents($identityPath);
$bootstrap = file_get_contents($bootstrapPath);

foreach (compact('hardening', 'identity', 'bootstrap') as $name => $source) {
    if (!is_string($source) || $source === '') {
        fwrite(STDERR, "Missing source: {$name}\n");
        exit(1);
    }
}

foreach ([
    "private const RECONCILE_CRON_HOOK = 'takka_bridge_direct_reconcile_cron_v4'",
    'private const RECONCILE_CRON_INTERVAL = 120',
    "add_filter('cron_schedules'",
    "add_action('init', [self::class, 'ensure_reconcile_schedule'], 45)",
    "add_action(self::RECONCILE_CRON_HOOK, [self::class, 'scheduled_reconcile'])",
    'wp_next_scheduled(self::RECONCILE_CRON_HOOK)',
    'wp_schedule_event(',
    "'/repos/' . \$repository",
    "'private'",
    'TakKa_WordPress_Bridge_Direct_GitHub_Recovery::branch_sha(',
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

if (strpos($bootstrap, ' * Version: 1.1.28') === false) {
    fwrite(STDERR, "Plugin version was not bumped to 1.1.28.\n");
    exit(1);
}

foreach ([$hardeningPath, $identityPath, $bootstrapPath] as $path) {
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
