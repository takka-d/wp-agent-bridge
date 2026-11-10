<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
if (!function_exists('wp_check_invalid_utf8')) {
    function wp_check_invalid_utf8($text, $strip = false)
    {
        $text = (string) $text;
        if ($text === '' || preg_match('//u', $text)) return $text;
        if (!$strip) return '';
        return (string) mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }
}
require_once __DIR__ . '/../plugin/wp-agent-bridge/includes/class-wp-agent-bridge-v085-content-batch.php';
require_once __DIR__ . '/../plugin/wp-agent-bridge/includes/class-wp-agent-bridge-v084-post-content.php';

function excerpt_fail(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

// The pre-1.1.42 algorithm, kept here to prove small excerpts are unchanged.
function legacy_excerpt(string $before, string $after, int $context): array
{
    $max = min(strlen($before), strlen($after));
    $prefix = 0;
    while ($prefix < $max && $before[$prefix] === $after[$prefix]) $prefix++;
    $before_suffix = strlen($before);
    $after_suffix = strlen($after);
    while ($before_suffix > $prefix && $after_suffix > $prefix && $before[$before_suffix - 1] === $after[$after_suffix - 1]) {
        $before_suffix--;
        $after_suffix--;
    }
    $before_start = max(0, $prefix - $context);
    $after_start = max(0, $prefix - $context);
    $before_end = min(strlen($before), $before_suffix + $context);
    $after_end = min(strlen($after), $after_suffix + $context);
    return [
        'before' => (string) wp_check_invalid_utf8(substr($before, $before_start, $before_end - $before_start), true),
        'after' => (string) wp_check_invalid_utf8(substr($after, $after_start, $after_end - $after_start), true),
        'prefix_omitted' => $before_start > 0,
        'suffix_omitted' => $before_end < strlen($before) || $after_end < strlen($after),
    ];
}

foreach ([[WP_Agent_Bridge_V085_Content_Batch::class, 500], [WP_Agent_Bridge_V084_Post_Content::class, 360]] as [$class, $context]) {
    $excerpt = new ReflectionMethod($class, 'diff_excerpt');
    $excerpt->setAccessible(true);

    // A local edit keeps the exact previous excerpt.
    $before = str_repeat('前置き<p>abc</p>', 2000) . '<rect fill="#fff" style="fill:#fff!important;"/>' . str_repeat('後書き', 3000);
    $after = str_replace(' fill="#fff"', '', $before);
    $small = $excerpt->invoke(null, $before, $after);
    if ($small !== legacy_excerpt($before, $after, $context) || isset($small['middle_omitted'])) {
        excerpt_fail($class . ': a small excerpt changed shape or content.');
    }

    // replace_all across a large post: changes at both ends of ~3 MB.
    $unit = '<rect x="1" fill="#f7f9fc" stroke="#526171" style="fill:#f7f9fc!important;stroke:#526171!important;"/>鍵盤';
    $before = '<p>先頭</p>' . str_repeat($unit, 30000) . '<p>末尾</p>';
    $after = str_replace(' fill="#f7f9fc" stroke="#526171"', '', $before);
    $large = $excerpt->invoke(null, $before, $after);
    $json = json_encode($large, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (strlen($before) < 3000000 || !is_string($json) || strlen($json) > 65536) {
        excerpt_fail($class . ': a large changed span was not bounded.');
    }
    if (empty($large['middle_omitted']) || $large['changed_before_bytes'] <= $large['changed_after_bytes']
        || $large['window_bytes'] !== 8192 || $large['prefix_omitted'] || $large['suffix_omitted']) {
        excerpt_fail($class . ': bounded excerpt metadata is wrong.');
    }
    foreach (['before', 'after', 'before_tail', 'after_tail'] as $key) {
        if (!is_string($large[$key]) || $large[$key] === '' || strlen($large[$key]) > 8192 || !preg_match('//u', $large[$key])) {
            excerpt_fail($class . ': ' . $key . ' is not a bounded valid UTF-8 window.');
        }
    }
    if (strpos($before, $large['before']) !== 0 || strpos($after, $large['after']) !== 0
        || substr($before, -strlen($large['before_tail'])) !== $large['before_tail']
        || substr($after, -strlen($large['after_tail'])) !== $large['after_tail']) {
        excerpt_fail($class . ': head/tail windows are not taken from the changed span.');
    }

    // A large insertion keeps only the window on the short side.
    $before = str_repeat('a', 1000);
    $after = 'a' . str_repeat('挿入', 20000) . str_repeat('a', 999);
    $insert = $excerpt->invoke(null, $before, $after);
    if (empty($insert['middle_omitted']) || $insert['before'] !== substr($before, 0, 1 + $context) || $insert['before_tail'] !== ''
        || strlen($insert['after']) > 8192 || strlen($insert['after_tail']) > 8192) {
        excerpt_fail($class . ': a one-sided large change was not windowed correctly.');
    }
}

echo "post content diff excerpt bounds ok\n";
