<?php
// Validate the output of the actual connector-safe Python media preparer.
declare(strict_types=1);

function contract_fail(string $message): void {
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$package = json_decode(stream_get_contents(STDIN), true);
if (!is_array($package) || !isset($package['commands'], $package['tree'], $package['manifest'])) {
    contract_fail('Missing generated connector-safe package.');
}
$commands = $package['commands'];
$tree = $package['tree'];
$manifest = $package['manifest'];
if (!is_array($commands) || !$commands || count($commands) !== count($tree)) {
    contract_fail('Command/tree counts do not match.');
}
$binary = '';
foreach ($commands as $index => $command) {
    if (!is_array($command)
        || ($command['type'] ?? '') !== 'rest'
        || ($command['method'] ?? '') !== 'POST'
        || ($command['route'] ?? '') !== '/wp-agent-bridge-media/v1/upload-chunk'
        || ($command['id'] ?? '') !== ($command['request_id'] ?? '')) {
        contract_fail('Generated chunk command shape is invalid.');
    }
    $body = $command['body'] ?? null;
    if (!is_array($body)
        || (int) ($body['chunk_index'] ?? -1) !== $index
        || (int) ($body['chunk_count'] ?? 0) !== count($commands)
        || ($body['upload_id'] ?? '') !== ($manifest['upload_id'] ?? '')) {
        contract_fail('Generated chunk sequence metadata is invalid.');
    }
    $chunk = base64_decode((string) ($body['data_b64'] ?? ''), true);
    if (!is_string($chunk)
        || strlen($chunk) < 1
        || strlen($chunk) > 8192
        || strlen($chunk) !== (int) ($body['chunk_bytes'] ?? 0)
        || !hash_equals(hash('sha256', $chunk), (string) ($body['chunk_sha256'] ?? ''))) {
        contract_fail('Generated chunk integrity metadata is invalid.');
    }
    $entry = $tree[$index] ?? null;
    if (!is_array($entry)
        || ($entry['path'] ?? '') !== 'wordpress-bridge/commands/pending/' . $command['id'] . '.json'
        || json_decode((string) ($entry['content'] ?? ''), true) !== $command
        || strlen((string) $entry['content']) > 16384) {
        contract_fail('Generated Git pending-command entry is invalid or too large.');
    }
    $binary .= $chunk;
}
if (strlen($binary) !== (int) ($manifest['expected_bytes'] ?? 0)
    || !hash_equals(hash('sha256', $binary), (string) ($manifest['expected_sha256'] ?? ''))) {
    contract_fail('Generated media does not match the declared original.');
}
if (($manifest['mode'] ?? '') !== 'connector-safe-chunk-commands'
    || (int) ($manifest['safe_chunk_decoded_bytes'] ?? 0) !== 8192
    || (int) ($manifest['connector_payload_budget_bytes'] ?? 0) !== 16384
    || (int) ($manifest['max_commands_per_git_push'] ?? 0) !== 20) {
    contract_fail('Connector-safe publication contract is incomplete.');
}

echo "media-client-contract-test: ok\n";
