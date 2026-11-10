<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512)
    {
        return json_encode($data, $options, $depth);
    }
}
require_once __DIR__ . '/../plugin/wp-agent-bridge/includes/class-wp-agent-bridge-direct-runtime.php';

function compaction_fail(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
$limit = 2097152;
$compact = new ReflectionMethod(WP_Agent_Bridge_Direct_Runtime::class, 'compact_oversized_output');
$compact->setAccessible(true);

// Large strings are summarized while small decision fields survive.
$big = str_repeat('<rect fill="#f7f9fc" style="fill:#f7f9fc!important;"/>', 30000);
$output = [
    'id' => 'cmd-1',
    'request_id' => 'cmd-1',
    'outcome' => ['state' => 'succeeded', 'operation_ok' => true, 'status' => 200],
    'result' => ['ok' => true, 'status' => 200, 'statusText' => 'OK', 'data' => ['data' => [
        'plan_hash' => str_repeat('a', 64),
        'before_sha256' => str_repeat('b', 64),
        'diff_excerpt' => ['before' => $big, 'after' => $big],
    ]]],
];
$original = json_encode($output, $flags);
if (!is_string($original) || strlen($original) <= $limit) {
    compaction_fail('Fixture must exceed the journal limit.');
}
$compacted = $compact->invoke(null, $output, strlen($original));
$encoded = json_encode($compacted, $flags);
$data = $compacted['result']['data']['data'] ?? [];
if (!is_string($encoded) || strlen($encoded) + 1 > $limit
    || ($data['plan_hash'] ?? '') !== str_repeat('a', 64)
    || empty($data['diff_excerpt']['before']['omitted'])
    || ($data['diff_excerpt']['before']['bytes'] ?? 0) !== strlen($big)
    || ($data['diff_excerpt']['before']['sha256'] ?? '') !== hash('sha256', $big)
    || $compacted['outcome'] !== $output['outcome']
    || ($compacted['result_compacted']['reason'] ?? '') !== 'result_exceeds_journal_limit'
    || !empty($compacted['result_compacted']['result_omitted'])) {
    compaction_fail('Oversized strings were not summarized while preserving small fields and the outcome.');
}

// Results that stay too large after string summarizing keep only the status.
$many = ['ok' => false, 'status' => 409, 'statusText' => 'Conflict', 'data' => array_fill(0, 220000, 'abcdefghij')];
$output = ['id' => 'cmd-2', 'request_id' => 'cmd-2', 'outcome' => ['state' => 'failed'], 'result' => $many];
$compacted = $compact->invoke(null, $output, 9999999);
$encoded = json_encode($compacted, $flags);
if (!is_string($encoded) || strlen($encoded) + 1 > $limit
    || $compacted['result'] !== ['ok' => false, 'status' => 409, 'statusText' => 'Conflict', 'result_omitted' => true]
    || empty($compacted['result_compacted']['result_omitted'])) {
    compaction_fail('A result that cannot be summarized must fall back to its status only.');
}

echo "runtime result compaction ok\n";
