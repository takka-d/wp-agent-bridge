<?php

$bytes = '';
for ($i = 0; $i < 41946; $i++) {
    $bytes .= chr(($i * 131 + 17) & 0xff);
}

$expectedBytes = strlen($bytes);
$expectedSha = hash('sha256', $bytes);
$binaryChunkBytes = 6000; // Base64 output is exactly 8,000 chars for full chunks.
$payloads = [];

for ($offset = 0; $offset < $expectedBytes; $offset += $binaryChunkBytes) {
    $chunk = substr($bytes, $offset, $binaryChunkBytes);
    $encoded = base64_encode($chunk);
    if (strlen($encoded) > 8000) {
        fwrite(STDERR, "Base64 payload exceeded 8,000 characters.\n");
        exit(1);
    }
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
    || strpos($identity, 'Split the ORIGINAL BINARY') === false
    || strpos($identity, '8,000 Base64 characters') === false
    || strpos($identity, 'read it back by blob SHA') === false) {
    fwrite(STDERR, "Runtime identity guidance does not enforce verified small binary-first chunks.\n");
    exit(1);
}

echo "media-binary-chunk-staging-test: ok\n";
