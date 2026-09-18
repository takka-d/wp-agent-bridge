<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

$GLOBALS['bootstrap_options'] = [];
$GLOBALS['bootstrap_transients'] = [];
$GLOBALS['bootstrap_files'] = [
    'wp-agent-bridge-runtime:AGENTS.md' => "# stale agents\nBridge: `1.1.36`\n",
    'wp-agent-bridge-runtime:wordpress-bridge/RUNTIME_CONNECTION.json' => json_encode([
        'schema' => 1,
        'status' => 'canonical',
        'transport' => 'direct-github-webhook',
        'repository' => 'owner/runtime-repo',
        'runtime_branch' => 'wp-agent-bridge-runtime',
        'site_host' => 'example.test',
        'ownership' => 'user-owned',
        'operator_relay' => false,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
    'wp-agent-bridge-runtime:wordpress-bridge/RUNTIME_CAPABILITIES.json' => "{\n  \"bridge_version\": \"1.1.36\", \"stale\": true\n}\n",
];
$GLOBALS['bootstrap_writes'] = [];

final class WP_Error
{
    private string $code;
    private string $message;
    private $data;

    public function __construct(string $code, string $message, $data = null)
    {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }

    public function get_error_data() { return $this->data; }
    public function get_error_message(): string { return $this->message; }
}

function is_wp_error($value): bool { return $value instanceof WP_Error; }
function get_option($key, $default = '') { return $GLOBALS['bootstrap_options'][$key] ?? $default; }
function update_option($key, $value, $autoload = false): void { $GLOBALS['bootstrap_options'][$key] = $value; }
function get_transient($key) { return $GLOBALS['bootstrap_transients'][$key] ?? false; }
function set_transient($key, $value, $ttl): void { $GLOBALS['bootstrap_transients'][$key] = $value; }
function delete_transient($key): void { unset($GLOBALS['bootstrap_transients'][$key]); }
function home_url($path = '/'): string { return 'https://example.test' . $path; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function get_file_data($file, $headers, $context): array { return ['Version' => '1.1.37']; }
function add_action($hook, $callback, $priority = 10): void {}

final class TakKa_WordPress_Bridge_Direct_Runtime
{
    public const RUNTIME_BRANCH = 'wp-agent-bridge-runtime';

    public static function connection(): array
    {
        return [
            'installation_id' => 1,
            'repository_id' => 2,
            'repository' => 'owner/runtime-repo',
            'runtime_branch' => self::RUNTIME_BRANCH,
        ];
    }
}

final class TakKa_WordPress_Bridge_Runtime_Guidance
{
    public static function agents(string $repository, string $branch, string $host, string $version): string
    {
        return "# WP Agent Bridge runtime — CANONICAL\n\n"
            . "Site: `{$host}`\nRepository: `{$repository}`\nBranch: `{$branch}`\nBridge: `{$version}`\n\n"
            . "Every runtime read/write explicitly uses branch/ref `{$branch}`.\n";
    }
}

final class TakKa_WordPress_Bridge_Runtime_Capabilities
{
    public static function catalog(string $version, string $repository, string $branch, string $host): array
    {
        return [
            'schema' => 2,
            'bridge_version' => $version,
            'runtime' => [
                'repository' => $repository,
                'branch' => $branch,
                'site_host' => $host,
                'bootstrap' => ['explicit_ref_required_for_runtime_io' => true],
            ],
            'features' => ['default_branch_bootstrap_mirror' => true],
        ];
    }
}

final class TakKa_WordPress_Bridge_Post_Concurrency_Runtime_Guidance
{
    public static function enrich_agents(string $agents): string
    {
        return rtrim($agents) . "\n<!-- concurrency-final -->\n";
    }

    public static function enrich_capabilities(array $catalog): array
    {
        $catalog['features']['post_update_field_compare_and_swap'] = true;
        return $catalog;
    }
}

final class TakKa_WordPress_Bridge_Post_Reliability_Runtime_Guidance
{
    public static function enrich_agents(string $agents): string
    {
        return rtrim($agents) . "\n<!-- reliability-final -->\n";
    }

    public static function enrich_capabilities(array $catalog): array
    {
        $catalog['features']['post_revision_rollback'] = true;
        return $catalog;
    }
}

final class TakKa_WordPress_Bridge_Direct_GitHub
{
    public static function installation_token($installation, $repository): string
    {
        return 'token';
    }

    public static function github_api(string $method, string $endpoint, string $token, $body = null)
    {
        if ($method === 'GET' && $endpoint === '/repos/owner/runtime-repo') {
            return ['status' => 200, 'data' => ['default_branch' => 'main']];
        }
        return new WP_Error('unexpected_api', 'Unexpected API request.', ['status' => 500]);
    }

    public static function get_text_file(string $token, string $repository, string $branch, string $path)
    {
        $key = $branch . ':' . $path;
        if (!array_key_exists($key, $GLOBALS['bootstrap_files'])) {
            return new WP_Error('not_found', 'Not found.', ['status' => 404]);
        }
        return $GLOBALS['bootstrap_files'][$key];
    }

    public static function put_text_file(string $token, string $repository, string $branch, string $path, string $content, string $message)
    {
        $key = $branch . ':' . $path;
        $GLOBALS['bootstrap_files'][$key] = $content;
        $GLOBALS['bootstrap_writes'][] = $key;
        return ['ok' => true];
    }
}

final class TakKa_WordPress_Bridge_Direct_GitHub_Recovery
{
    public static function error_status($error): int
    {
        if (!($error instanceof WP_Error)) return 0;
        $data = $error->get_error_data();
        return is_array($data) ? (int) ($data['status'] ?? 0) : 0;
    }
}

require_once __DIR__ . '/../plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-runtime-bootstrap.php';

function fail_bootstrap(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$result = TakKa_WordPress_Bridge_Runtime_Bootstrap::sync();
if (is_wp_error($result) || empty($result['ok'])) {
    fail_bootstrap('Runtime bootstrap sync failed.');
}
if (($result['default_branch'] ?? null) !== 'main'
    || ($result['runtime_branch'] ?? null) !== 'wp-agent-bridge-runtime'
    || empty($result['mutations_require_runtime_branch'])) {
    fail_bootstrap('Runtime bootstrap result metadata is incomplete.');
}

$marker = $GLOBALS['bootstrap_files']['wp-agent-bridge-runtime:wordpress-bridge/RUNTIME_CONNECTION.json'];
$caps = $GLOBALS['bootstrap_files']['wp-agent-bridge-runtime:wordpress-bridge/RUNTIME_CAPABILITIES.json'];
$agents = $GLOBALS['bootstrap_files']['wp-agent-bridge-runtime:AGENTS.md'];

if (strpos($agents, 'Bridge: `1.1.37`') === false
    || strpos($agents, '<!-- concurrency-final -->') === false
    || strpos($agents, '<!-- reliability-final -->') === false
    || strpos($agents, '# stale agents') !== false) {
    fail_bootstrap('Canonical AGENTS was not recomposed from current code.');
}
$decoded_caps = json_decode($caps, true);
if (!is_array($decoded_caps)
    || ($decoded_caps['bridge_version'] ?? '') !== '1.1.37'
    || empty($decoded_caps['features']['post_update_field_compare_and_swap'])
    || empty($decoded_caps['features']['post_revision_rollback'])
    || !empty($decoded_caps['stale'])) {
    fail_bootstrap('Canonical capabilities were not recomposed from current code.');
}

$expected = [
    'wp-agent-bridge-runtime:RUNTIME_CONNECTION.json' => $marker,
    'wp-agent-bridge-runtime:RUNTIME_CAPABILITIES.json' => $caps,
    'main:AGENTS.md' => $agents,
    'main:RUNTIME_CONNECTION.json' => $marker,
    'main:RUNTIME_CAPABILITIES.json' => $caps,
    'main:wordpress-bridge/RUNTIME_CONNECTION.json' => $marker,
    'main:wordpress-bridge/RUNTIME_CAPABILITIES.json' => $caps,
];
foreach ($expected as $key => $value) {
    if (($GLOBALS['bootstrap_files'][$key] ?? null) !== $value) {
        fail_bootstrap('Missing or incorrect bootstrap mirror: ' . $key);
    }
}

$readme = $GLOBALS['bootstrap_files']['main:README.md'] ?? '';
foreach ([
    'default branch is only a read-only bootstrap landing page',
    'Canonical runtime branch: `wp-agent-bridge-runtime`',
    'explicitly use branch/ref `wp-agent-bridge-runtime`',
    'A 404 from an omitted ref or a wrong path is not proof',
    'wordpress-bridge/commands/pending/<id>.json',
    'Do not write commands to the default branch',
] as $required) {
    if (strpos($readme, $required) === false) {
        fail_bootstrap('Bootstrap README is missing: ' . $required);
    }
}

foreach (array_keys($GLOBALS['bootstrap_files']) as $key) {
    if (strpos($key, 'main:wordpress-bridge/commands/') === 0
        || strpos($key, 'main:wordpress-bridge/results/') === 0) {
        fail_bootstrap('Command/result data must never be mirrored to the default branch.');
    }
}

$write_count = count($GLOBALS['bootstrap_writes']);
$result2 = TakKa_WordPress_Bridge_Runtime_Bootstrap::sync();
if (is_wp_error($result2) || count($GLOBALS['bootstrap_writes']) !== $write_count) {
    fail_bootstrap('Unchanged final composition should not rewrite files.');
}

echo "runtime-bootstrap-test: ok\n";
