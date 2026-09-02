<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Direct GitHub onboarding prototype.
 *
 * All GitHub App manifest callbacks terminate at this WordPress installation.
 * No operator-owned onboarding/relay service receives setup secrets, runtime
 * commands, results, article text, or WordPress settings.
 */
final class TakKa_WordPress_Bridge_Onboarding
{
    private const OPTION_LEGACY_CONNECTION = 'takka_bridge_github_connection_v1';
    private const OPTION_SECRET = 'takka_bridge_secret';
    private const OPTION_USER_ID = 'takka_bridge_user_id';
    private const OPTION_PENDING_SETUP = 'takka_bridge_direct_onboarding_pending_v1';
    private const NAMESPACE = 'wp-agent-bridge-onboarding/v2';
    private const RUNTIME_BRANCH = 'wp-agent-bridge-runtime';
    private const MAX_SETUP_AGE = 2 * HOUR_IN_SECONDS;

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'admin_menu']);
        add_action('admin_notices', [self::class, 'admin_notice']);
        add_action('admin_post_takka_bridge_connect_github', [self::class, 'handle_connect']);
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function admin_menu(): void
    {
        add_management_page(
            'WP Agent Bridge - GitHub Setup',
            'WP Agent Bridge',
            'manage_options',
            'takka-wordpress-bridge-connect',
            [self::class, 'render_page']
        );
    }

    public static function admin_notice(): void
    {
        if (!current_user_can('manage_options') || self::is_connected()) {
            return;
        }
        if (isset($_GET['page']) && sanitize_key((string) $_GET['page']) === 'takka-wordpress-bridge-connect') {
            return;
        }
        $url = admin_url('tools.php?page=takka-wordpress-bridge-connect');
        ?>
        <div class="notice notice-info">
            <p><strong>WP Agent Bridge:</strong> GitHub direct connection is not configured. <a class="button button-primary" href="<?php echo esc_url($url); ?>">Connect GitHub</a></p>
        </div>
        <?php
    }

    private static function is_connected(): bool
    {
        $connection = TakKa_WordPress_Bridge_Direct_Runtime::connection();
        $app = TakKa_WordPress_Bridge_Direct_GitHub::app_config();
        return !empty($connection['installation_id'])
            && !empty($connection['repository_id'])
            && !empty($connection['repository'])
            && !empty($app['app_id'])
            && !empty($app['slug'])
            && TakKa_WordPress_Bridge_Direct_GitHub::private_key() !== ''
            && TakKa_WordPress_Bridge_Direct_GitHub::webhook_secret() !== '';
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $connection = TakKa_WordPress_Bridge_Direct_Runtime::connection();
        $app = TakKa_WordPress_Bridge_Direct_GitHub::app_config();
        $connected = self::is_connected();
        $status = isset($_GET['takka_bridge_setup']) ? sanitize_key((string) $_GET['takka_bridge_setup']) : '';
        $repo_name = self::suggested_repository_name();
        $repo_url = add_query_arg([
            'name' => $repo_name,
            'description' => 'Private runtime repository for WP Agent Bridge.',
            'visibility' => 'private',
            'owner' => '@me',
        ], 'https://github.com/new');
        ?>
        <div class="wrap">
            <h1>WP Agent Bridge - GitHub Setup</h1>

            <?php if ($status === 'complete') : ?>
                <div class="notice notice-success"><p>GitHub direct connection completed.</p></div>
            <?php elseif ($status === 'failed') : ?>
                <div class="notice notice-error"><p>GitHub direct connection could not be completed. Check the message shown during setup and try again.</p></div>
            <?php endif; ?>

            <?php if ($connected) : ?>
                <p><strong>Status:</strong> Connected (direct GitHub webhook)</p>
                <p><strong>Repository:</strong> <code><?php echo esc_html((string) $connection['repository']); ?></code></p>
                <p><strong>Runtime branch:</strong> <code><?php echo esc_html((string) ($connection['runtime_branch'] ?? self::RUNTIME_BRANCH)); ?></code></p>
                <p><strong>GitHub App:</strong> <code><?php echo esc_html((string) ($app['slug'] ?? '')); ?></code></p>
                <p><strong>Normal runtime:</strong> ChatGPT &rarr; GitHub &rarr; this WordPress &rarr; GitHub &rarr; ChatGPT.</p>
                <p>No operator-owned relay server or per-command GitHub Actions workflow is used by this transport.</p>
            <?php else : ?>
                <p><strong>Status:</strong> Not connected</p>
                <p>This prototype creates a private GitHub App owned by you. Its webhook points directly to this WordPress site.</p>

                <h2>1. Create the private runtime repository</h2>
                <p>The GitHub form is pre-filled with a private repository named <code><?php echo esc_html($repo_name); ?></code>. Keep it private and create it in the same GitHub account that will own the GitHub App.</p>
                <p><a id="takka-direct-create-repo" class="button" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url($repo_url); ?>">Create private repository on GitHub</a></p>

                <h2>2. Create and install the site-specific GitHub App</h2>
                <p>After the repository exists, continue below. GitHub will show the App registration and installation screens. During installation choose <strong>Only select repositories</strong> and select only <code><?php echo esc_html($repo_name); ?></code>.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="takka_bridge_connect_github">
                    <input type="hidden" name="expected_repository_name" value="<?php echo esc_attr($repo_name); ?>">
                    <?php wp_nonce_field('takka_bridge_connect_github'); ?>

                    <details style="margin:12px 0 16px;max-width:720px">
                        <summary>Organization-owned repository (advanced)</summary>
                        <p>If the repository is owned by a GitHub Organization, enter that Organization name here. Leave empty for your personal GitHub account.</p>
                        <label>Organization <input type="text" name="github_organization" pattern="[A-Za-z0-9-]{1,100}" maxlength="100" autocomplete="off"></label>
                    </details>

                    <?php submit_button('Connect GitHub', 'primary'); ?>
                </form>

                <p><small>No PAT, webhook secret, JSON, branch, or GitHub Actions configuration is entered manually. The GitHub App private key and webhook secret returned by GitHub are encrypted and stored only in this WordPress installation.</small></p>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function handle_connect(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden', '', ['response' => 403]);
        }
        check_admin_referer('takka_bridge_connect_github');

        if (!is_ssl() && strpos(home_url('/'), 'https://') !== 0) {
            wp_die('WP Agent Bridge direct onboarding requires HTTPS.', '', ['response' => 400]);
        }
        if (!TakKa_WordPress_Bridge_Direct_GitHub::crypto_available()) {
            wp_die('OpenSSL with AES-256-GCM and RSA signing is required for direct GitHub App credentials.', '', ['response' => 500]);
        }

        $expected_repo = isset($_POST['expected_repository_name']) ? sanitize_text_field(wp_unslash((string) $_POST['expected_repository_name'])) : '';
        if (!preg_match('/^[A-Za-z0-9_.-]{1,100}$/', $expected_repo)) {
            wp_die('Invalid runtime repository name.', '', ['response' => 400]);
        }
        $organization = isset($_POST['github_organization']) ? trim(sanitize_text_field(wp_unslash((string) $_POST['github_organization']))) : '';
        if ($organization !== '' && !preg_match('/^[A-Za-z0-9-]{1,100}$/', $organization)) {
            wp_die('Invalid GitHub Organization name.', '', ['response' => 400]);
        }

        try {
            $state = bin2hex(random_bytes(32));
            $bridge_secret = (string) get_option(self::OPTION_SECRET, '');
            if (strlen($bridge_secret) < 32) {
                $bridge_secret = bin2hex(random_bytes(32));
                update_option(self::OPTION_SECRET, $bridge_secret, false);
            }
        } catch (Throwable $e) {
            wp_die('Secure random generation failed.', '', ['response' => 500]);
        }
        update_option(self::OPTION_USER_ID, get_current_user_id(), false);

        $pending = [
            'state' => $state,
            'user_id' => get_current_user_id(),
            'created_at' => time(),
            'expected_repository_name' => $expected_repo,
            'organization' => $organization,
            'stage' => 'manifest',
        ];
        self::store_pending($pending);

        // A reconnect starts a new site-specific App. Never carry credentials
        // from an abandoned manifest attempt into a new connection attempt.
        TakKa_WordPress_Bridge_Direct_GitHub::clear_credentials();
        TakKa_WordPress_Bridge_Direct_Runtime::clear_connection();

        $manifest = self::manifest($state);
        $manifest_json = wp_json_encode($manifest, JSON_UNESCAPED_SLASHES);
        if (!is_string($manifest_json)) {
            self::clear_pending();
            wp_die('Could not encode GitHub App manifest.', '', ['response' => 500]);
        }

        $action = $organization === ''
            ? 'https://github.com/settings/apps/new?state=' . rawurlencode($state)
            : 'https://github.com/organizations/' . rawurlencode($organization) . '/settings/apps/new?state=' . rawurlencode($state);

        nocache_headers();
        header('Content-Type: text/html; charset=' . get_option('blog_charset'));
        ?>
        <!doctype html>
        <html>
        <head>
            <meta charset="<?php echo esc_attr(get_option('blog_charset')); ?>">
            <meta name="referrer" content="no-referrer">
            <meta name="robots" content="noindex,nofollow">
            <title>Connecting WP Agent Bridge to GitHub</title>
        </head>
        <body>
            <p>Opening GitHub App registration...</p>
            <form id="wp-agent-bridge-manifest" method="post" action="<?php echo esc_url($action); ?>">
                <input type="hidden" name="manifest" value="<?php echo esc_attr($manifest_json); ?>">
                <noscript><button type="submit">Continue to GitHub</button></noscript>
            </form>
            <script>document.getElementById('wp-agent-bridge-manifest').submit();</script>
        </body>
        </html>
        <?php
        exit;
    }

    public static function register_routes(): void
    {
        register_rest_route(self::NAMESPACE, '/manifest-complete', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [self::class, 'manifest_complete'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NAMESPACE, '/installed', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [self::class, 'installed'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function manifest_complete(WP_REST_Request $request)
    {
        $state = strtolower(trim((string) $request->get_param('state')));
        $code = trim((string) $request->get_param('code'));
        $pending = self::pending($state, 'manifest');
        if (is_wp_error($pending)) {
            return $pending;
        }
        if (!preg_match('/^[A-Za-z0-9_-]{10,200}$/', $code)) {
            return new WP_Error('takka_direct_manifest_code', 'Invalid GitHub manifest code.', ['status' => 400]);
        }

        $response = wp_safe_remote_post('https://api.github.com/app-manifests/' . rawurlencode($code) . '/conversions', [
            'timeout' => 30,
            'redirection' => 0,
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2026-03-10',
                'User-Agent' => 'WP-Agent-Bridge-Direct/0.1',
                'Content-Type' => 'application/json',
            ],
            'body' => '{}',
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($status !== 201 || !is_array($data)) {
            $message = is_array($data) && isset($data['message']) ? (string) $data['message'] : 'GitHub App manifest conversion failed.';
            return new WP_Error('takka_direct_manifest_exchange', $message, ['status' => 502]);
        }

        $app_id = isset($data['id']) ? (int) $data['id'] : 0;
        $slug = isset($data['slug']) ? (string) $data['slug'] : '';
        $pem = isset($data['pem']) ? (string) $data['pem'] : '';
        $webhook_secret = isset($data['webhook_secret']) ? (string) $data['webhook_secret'] : '';
        $owner_login = isset($data['owner']['login']) ? (string) $data['owner']['login'] : '';
        $owner_type = isset($data['owner']['type']) ? (string) $data['owner']['type'] : '';
        $permissions = isset($data['permissions']) && is_array($data['permissions']) ? $data['permissions'] : [];
        $events = isset($data['events']) && is_array($data['events']) ? $data['events'] : [];

        if ($app_id < 1
            || !preg_match('/^[A-Za-z0-9-]{1,100}$/', $slug)
            || !preg_match('/^[A-Za-z0-9-]{1,100}$/', $owner_login)
            || strpos($pem, 'PRIVATE KEY-----') === false
            || strlen($webhook_secret) < 20
            || ($permissions['contents'] ?? '') !== 'write'
            || ($permissions['metadata'] ?? '') !== 'read'
            || !in_array('push', $events, true)) {
            return new WP_Error('takka_direct_manifest_response', 'GitHub returned an unexpected App configuration.', ['status' => 502]);
        }

        $requested_org = (string) ($pending['organization'] ?? '');
        if ($requested_org !== '' && strcasecmp($requested_org, $owner_login) !== 0) {
            return new WP_Error('takka_direct_manifest_owner', 'The GitHub App was created under a different owner than requested.', ['status' => 403]);
        }

        if (!TakKa_WordPress_Bridge_Direct_GitHub::store_app_credentials($app_id, $slug, $pem, $webhook_secret)) {
            return new WP_Error('takka_direct_manifest_store', 'Could not securely store the GitHub App credentials on WordPress.', ['status' => 500]);
        }
        unset($data['pem'], $data['client_secret'], $data['webhook_secret']);
        if (function_exists('sodium_memzero')) {
            sodium_memzero($pem);
            sodium_memzero($webhook_secret);
        } else {
            $pem = '';
            $webhook_secret = '';
        }

        $pending['stage'] = 'install';
        $pending['app_id'] = $app_id;
        $pending['slug'] = $slug;
        $pending['owner_login'] = $owner_login;
        $pending['owner_type'] = $owner_type;
        self::store_pending($pending);

        $install_url = 'https://github.com/apps/' . rawurlencode($slug) . '/installations/new?state=' . rawurlencode($state);
        return self::redirect_response($install_url);
    }

    public static function installed(WP_REST_Request $request)
    {
        $state = strtolower(trim((string) $request->get_param('state')));
        $installation_id = absint($request->get_param('installation_id'));
        $pending = self::pending($state, 'install');
        if (is_wp_error($pending)) {
            return $pending;
        }
        if ($installation_id < 1) {
            return new WP_Error('takka_direct_installation_id', 'GitHub did not provide a valid installation ID.', ['status' => 400]);
        }

        // Do not trust the installation_id query parameter. Authenticate as the
        // newly-created private App, fetch that installation from GitHub, and
        // verify its owner and repository-selection policy before accepting it.
        $jwt = TakKa_WordPress_Bridge_Direct_GitHub::app_jwt();
        if (is_wp_error($jwt)) {
            return $jwt;
        }
        $installation = TakKa_WordPress_Bridge_Direct_GitHub::github_api(
            'GET',
            '/app/installations/' . $installation_id,
            $jwt
        );
        if (is_wp_error($installation)) {
            return $installation;
        }
        $installation_data = isset($installation['data']) && is_array($installation['data']) ? $installation['data'] : [];
        $account_login = isset($installation_data['account']['login']) ? (string) $installation_data['account']['login'] : '';
        $repository_selection = isset($installation_data['repository_selection']) ? (string) $installation_data['repository_selection'] : '';
        if ($account_login === ''
            || strcasecmp($account_login, (string) ($pending['owner_login'] ?? '')) !== 0
            || $repository_selection !== 'selected') {
            return new WP_Error(
                'takka_direct_installation_scope',
                'Install the site-specific GitHub App with Only select repositories, under the same account that owns the App.',
                ['status' => 403]
            );
        }

        $token = TakKa_WordPress_Bridge_Direct_GitHub::installation_token($installation_id);
        if (is_wp_error($token)) {
            return $token;
        }
        $repositories = TakKa_WordPress_Bridge_Direct_GitHub::installation_repositories($token);
        if (is_wp_error($repositories)) {
            return $repositories;
        }
        if (count($repositories) !== 1 || !is_array($repositories[0])) {
            return new WP_Error(
                'takka_direct_repository_count',
                'Select exactly one private runtime repository for this GitHub App installation.',
                ['status' => 400]
            );
        }
        $repository = $repositories[0];
        $repository_id = isset($repository['id']) ? (int) $repository['id'] : 0;
        $full_name = isset($repository['full_name']) ? (string) $repository['full_name'] : '';
        $repo_name = isset($repository['name']) ? (string) $repository['name'] : '';
        $private = isset($repository['private']) ? (bool) $repository['private'] : false;
        $repo_owner = isset($repository['owner']['login']) ? (string) $repository['owner']['login'] : '';
        $expected_name = (string) ($pending['expected_repository_name'] ?? '');

        if ($repository_id < 1
            || !$private
            || $full_name === ''
            || $repo_name !== $expected_name
            || strcasecmp($repo_owner, $account_login) !== 0) {
            return new WP_Error(
                'takka_direct_repository_mismatch',
                'The selected repository must be the private runtime repository created for this setup.',
                ['status' => 400]
            );
        }

        $scoped_token = TakKa_WordPress_Bridge_Direct_GitHub::installation_token($installation_id, $repository_id);
        if (is_wp_error($scoped_token)) {
            return $scoped_token;
        }
        $initialized = self::initialize_runtime_repository($scoped_token, $full_name);
        if (is_wp_error($initialized)) {
            return $initialized;
        }

        if (!TakKa_WordPress_Bridge_Direct_Runtime::store_connection([
            'installation_id' => $installation_id,
            'repository_id' => $repository_id,
            'repository' => $full_name,
            'runtime_branch' => self::RUNTIME_BRANCH,
        ])) {
            return new WP_Error('takka_direct_connection_store', 'Could not store the direct GitHub connection.', ['status' => 500]);
        }

        // The legacy central-relay connection is not used by the direct runtime.
        // Remove it only after the new direct connection is fully initialized.
        delete_option(self::OPTION_LEGACY_CONNECTION);
        self::clear_pending();

        $return_url = add_query_arg('takka_bridge_setup', 'complete', admin_url('tools.php?page=takka-wordpress-bridge-connect'));
        return self::redirect_response($return_url);
    }

    private static function manifest(string $state): array
    {
        $hash = substr(hash('sha256', home_url('/') . "\n" . $state), 0, 10);
        $name = 'WP Agent Bridge ' . $hash;
        return [
            'name' => $name,
            'url' => home_url('/'),
            'description' => 'Site-specific GitHub App for WP Agent Bridge direct WordPress transport.',
            'hook_attributes' => [
                'url' => rest_url(ltrim(TakKa_WordPress_Bridge_Direct_Runtime::WEBHOOK_ROUTE, '/')),
                'active' => true,
            ],
            'redirect_url' => rest_url(self::NAMESPACE . '/manifest-complete'),
            'setup_url' => add_query_arg('state', $state, rest_url(self::NAMESPACE . '/installed')),
            'public' => false,
            'default_permissions' => [
                'contents' => 'write',
                'metadata' => 'read',
            ],
            'default_events' => ['push'],
            'request_oauth_on_install' => false,
            'setup_on_update' => false,
        ];
    }

    private static function pending(string $state, string $stage)
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $state)) {
            return new WP_Error('takka_direct_state', 'Invalid setup state.', ['status' => 400]);
        }
        $pending = get_option(self::OPTION_PENDING_SETUP, []);
        if (!is_array($pending)
            || !isset($pending['state'])
            || !hash_equals((string) $pending['state'], $state)
            || (string) ($pending['stage'] ?? '') !== $stage
            || (int) ($pending['created_at'] ?? 0) < time() - self::MAX_SETUP_AGE) {
            return new WP_Error('takka_direct_state_expired', 'The GitHub setup session is invalid or expired. Start Connect GitHub again.', ['status' => 410]);
        }
        return $pending;
    }

    private static function store_pending(array $pending): void
    {
        update_option(self::OPTION_PENDING_SETUP, $pending, false);
    }

    private static function clear_pending(): void
    {
        delete_option(self::OPTION_PENDING_SETUP);
    }

    private static function initialize_runtime_repository(string $token, string $full_name)
    {
        if (!preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $full_name)) {
            return new WP_Error('takka_direct_runtime_repo', 'Invalid runtime repository name.');
        }
        $repo_path = '/repos/' . self::encode_repository($full_name);
        $repo_response = TakKa_WordPress_Bridge_Direct_GitHub::github_api('GET', $repo_path, $token);
        if (is_wp_error($repo_response)) {
            return $repo_response;
        }
        $repo = isset($repo_response['data']) && is_array($repo_response['data']) ? $repo_response['data'] : [];
        if (empty($repo['private'])) {
            return new WP_Error('takka_direct_runtime_private', 'Runtime repository must remain private.');
        }
        $default_branch = isset($repo['default_branch']) && is_string($repo['default_branch']) && $repo['default_branch'] !== ''
            ? $repo['default_branch']
            : 'main';

        $runtime_ref = self::get_ref_optional($token, $full_name, self::RUNTIME_BRANCH);
        if (is_wp_error($runtime_ref)) {
            return $runtime_ref;
        }
        if ($runtime_ref === null) {
            $default_ref = self::get_ref_optional($token, $full_name, $default_branch);
            if (is_wp_error($default_ref)) {
                return $default_ref;
            }
            if ($default_ref === null) {
                // GitHub's git-ref API returns 409 while the repository is empty.
                // Initialize it through the Contents API without adding Actions/workflow files.
                $init = TakKa_WordPress_Bridge_Direct_GitHub::github_api(
                    'PUT',
                    $repo_path . '/contents/README.md',
                    $token,
                    [
                        'message' => 'Initialize WP Agent Bridge runtime repository',
                        'content' => base64_encode("# WP Agent Bridge runtime\n\nPrivate transport repository. Do not make this repository public.\n"),
                    ]
                );
                if (is_wp_error($init)) {
                    return $init;
                }
                $default_ref = self::get_ref_optional($token, $full_name, $default_branch);
                if (is_wp_error($default_ref) || $default_ref === null) {
                    return is_wp_error($default_ref)
                        ? $default_ref
                        : new WP_Error('takka_direct_default_branch', 'GitHub did not create the default branch.');
                }
            }
            $sha = isset($default_ref['object']['sha']) ? (string) $default_ref['object']['sha'] : '';
            if (!preg_match('/^[a-f0-9]{40,64}$/', strtolower($sha))) {
                return new WP_Error('takka_direct_default_sha', 'Could not determine the default branch commit.');
            }
            $created = TakKa_WordPress_Bridge_Direct_GitHub::github_api(
                'POST',
                $repo_path . '/git/refs',
                $token,
                ['ref' => 'refs/heads/' . self::RUNTIME_BRANCH, 'sha' => $sha]
            );
            if (is_wp_error($created)) {
                return $created;
            }
        }

        $files = [
            'AGENTS.md' => "# WordPress Bridge runtime\n\nFor WordPress reads and writes, use branch `wp-agent-bridge-runtime`.\nCreate commands at `wordpress-bridge/commands/pending/<id>.json` and read results from `wordpress-bridge/results/<id>.json`.\nUse unique `id` and `request_id` values. Respect preview/confirm/SHA/plan/impact guards returned by the Bridge.\n",
            'wordpress-bridge/WEBHOOK_RUNTIME.md' => "# Runtime\n\nTransport: site-specific GitHub App + signed Webhook direct to the user's WordPress.\nBranch: `wp-agent-bridge-runtime`\nPending: `wordpress-bridge/commands/pending/<id>.json`\nResults: `wordpress-bridge/results/<id>.json`\nNormal WordPress commands do not use GitHub Actions or an operator-owned relay.\n",
            'wordpress-bridge/commands/pending/.gitkeep' => '',
            'wordpress-bridge/commands/completed/.gitkeep' => '',
            'wordpress-bridge/results/.gitkeep' => '',
        ];
        foreach ($files as $path => $content) {
            $written = self::put_if_missing($token, $full_name, $path, $content);
            if (is_wp_error($written)) {
                return $written;
            }
        }
        return true;
    }

    private static function get_ref_optional(string $token, string $full_name, string $branch)
    {
        $endpoint = '/repos/' . self::encode_repository($full_name) . '/git/ref/heads/' . rawurlencode($branch);
        $response = TakKa_WordPress_Bridge_Direct_GitHub::github_api('GET', $endpoint, $token);
        if (is_wp_error($response)) {
            $data = $response->get_error_data();
            $status = is_array($data) ? (int) ($data['status'] ?? 0) : 0;
            if (in_array($status, [404, 409], true)) {
                return null;
            }
            return $response;
        }
        return isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
    }

    private static function put_if_missing(string $token, string $full_name, string $path, string $content)
    {
        $endpoint = '/repos/' . self::encode_repository($full_name) . '/contents/' . self::encode_path($path);
        $lookup = TakKa_WordPress_Bridge_Direct_GitHub::github_api(
            'GET',
            $endpoint . '?ref=' . rawurlencode(self::RUNTIME_BRANCH),
            $token
        );
        if (!is_wp_error($lookup)) {
            return true;
        }
        $error_data = $lookup->get_error_data();
        if (!is_array($error_data) || (int) ($error_data['status'] ?? 0) !== 404) {
            return $lookup;
        }
        return TakKa_WordPress_Bridge_Direct_GitHub::github_api('PUT', $endpoint, $token, [
            'message' => 'Initialize WP Agent Bridge runtime: ' . $path,
            'content' => base64_encode($content),
            'branch' => self::RUNTIME_BRANCH,
        ]);
    }

    private static function suggested_repository_name(): string
    {
        $host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $host = preg_replace('/[^a-z0-9]+/', '-', $host);
        $host = trim((string) $host, '-');
        if ($host === '') {
            $host = 'wordpress';
        }
        $host = substr($host, 0, 48);
        $suffix = substr(hash('sha256', home_url('/') . '|wp-agent-bridge-runtime'), 0, 8);
        return 'wordpress-bridge-' . $host . '-' . $suffix;
    }

    private static function encode_repository(string $full_name): string
    {
        $parts = explode('/', $full_name, 2);
        return rawurlencode((string) ($parts[0] ?? '')) . '/' . rawurlencode((string) ($parts[1] ?? ''));
    }

    private static function encode_path(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', trim($path, '/'))));
    }

    private static function redirect_response(string $url)
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return new WP_Error('takka_direct_redirect', 'Invalid setup redirect.', ['status' => 500]);
        }
        $host = strtolower((string) $parts['host']);
        if ($host !== 'github.com' && $host !== strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST))) {
            return new WP_Error('takka_direct_redirect_host', 'Unexpected setup redirect host.', ['status' => 500]);
        }
        $response = new WP_REST_Response(null, 302);
        $response->header('Location', $url);
        $response->header('Cache-Control', 'no-store, max-age=0');
        $response->header('Referrer-Policy', 'no-referrer');
        return $response;
    }
}
