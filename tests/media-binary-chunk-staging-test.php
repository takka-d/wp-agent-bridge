<?php

$bytes = '';
for ($i = 0; $i < 41946; $i++) {
    $bytes .= chr(($i * 131 + 17) & 0xff);
}

$expectedBytes = strlen($bytes);
$expectedSha = hash('sha256', $bytes);
$binaryChunkBytes = 6000;
$payloads = [];

for ($offset = 0; $offset < $expectedBytes; $offset += $binaryChunkBytes) {
    $chunk = substr($bytes, $offset, $binaryChunkBytes);
    $encoded = base64_encode($chunk);
    $payloads[] = $encoded;
}

$rebuilt = '';
foreach ($payloads as $encoded) {
    $decoded = base64_decode($encoded, true);
    if ($decoded === false) {
        fwrite(STDERR, "Strict Base64 decode failed.\n");
        exit(1);
    }
    $rebuilt .= $decoded;
}

if (strlen($rebuilt) !== $expectedBytes) {
    fwrite(STDERR, "Rebuilt byte count mismatch.\n");
    exit(1);
}
if (!hash_equals($expectedSha, hash('sha256', $rebuilt))) {
    fwrite(STDERR, "Rebuilt SHA-256 mismatch.\n");
    exit(1);
}
if (count($payloads) !== 7) {
    fwrite(STDERR, "Unexpected payload count for 41,946-byte regression fixture.\n");
    exit(1);
}

$identity = file_get_contents(__DIR__ . '/../plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-direct-runtime-identity.php');
if (!is_string($identity)
    || strpos($identity, 'ChatGPT-local / conversation-uploaded files') === false
    || strpos($identity, '/wp-agent-bridge-media/v1/upload-chunk') === false
    || strpos($identity, 'This route bypasses `wordpress-bridge/media/pending/`') === false
    || strpos($identity, 'split the ORIGINAL BINARY before Base64-encoding each part') === false) {
    fwrite(STDERR, "Runtime identity guidance does not route local media through the authenticated chunk path.\n");
    exit(1);
}

$hardening = file_get_contents(__DIR__ . '/../plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-direct-hardening.php');
if (!is_string($hardening)
    || strpos($hardening, 'replace_runtime_webhook') === false
    || strpos($hardening, 'serialized_webhook') === false
    || strpos($hardening, 'PRIMARY_LOCK_OPTION') === false
    || strpos($hardening, 'TakKa_WordPress_Bridge_Direct_Runtime_V2::webhook($request)') === false
    || strpos($hardening, "'retryable' => true") === false) {
    fwrite(STDERR, "Direct Runtime primary webhook serialization hardening is missing.\n");
    exit(1);
}

echo "media-binary-chunk-staging-test: ok\n";
