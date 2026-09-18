<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * v0.9.5 server-side compatibility surface.
 *
 * Adds WordPress-side equivalents for structural file inspection,
 * server-rendered page inspection and standalone classic-theme drafting.
 * It intentionally does not claim browser JavaScript/DOM execution.
 */
final class WP_Agent_Bridge_V095_Compat
{
    private const VERSION = '0.9.5';
    private const NS = 'wpab-v095/v1';
    private const ROUTE = '/wpab-v095/v1/manage';
    private const OUTER = '/wp-agent-bridge/v1/execute';
    private const HEALTH = '/wp-agent-bridge/v1/health';
    private const SECRET = 'wpab_secret';
    private const USER = 'wpab_user_id';
    private const SKEW = 300;
    private static $allowed = false;

    private const ACTIONS = [
        'v095.capabilities',
        'theme.file.outline',
        'theme.file.read.range',
        'page.html.inspect',
        'classic_theme.create',
        'classic_theme.list',
        'classic_theme.info',
        'classic_theme.preview_url',
        'classic_theme.publish',
        'classic_theme.discard',
    ];

    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'register']);
        add_filter('rest_pre_dispatch', [self::class, 'prepare'], 65, 3);
        add_filter('rest_request_after_callbacks', [self::class, 'health'], 395, 3);
        add_filter('pre_option_stylesheet', [WP_Agent_Bridge_V095_Classic::class, 'preview_stylesheet'], 5);
        add_filter('pre_option_template', [WP_Agent_Bridge_V095_Classic::class, 'preview_template'], 5);
        add_action('template_redirect', [WP_Agent_Bridge_V095_Classic::class, 'preview_no_cache'], 0);
    }

    public static function register(): void
    {
        register_rest_route(self::NS, '/manage', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'dispatch'],
            'permission_callback' => [self::class, 'permission'],
        ]);
    }

    public static function prepare($result, WP_REST_Server $server, WP_REST_Request $request)
    {
        if ($result !== null
            || $request->get_route() !== self::OUTER
            || strtoupper($request->get_method()) !== 'POST'
            || !self::valid_hmac($request)) {
            return $result;
        }
        $inner = self::inner($request);
        if (!is_array($inner) || ($inner['action'] ?? '') !== 'rest.call') {
            return $result;
        }
        $params = isset($inner['params']) && is_array($inner['params']) ? $inner['params'] : [];
        if (strtoupper((string) ($params['method'] ?? 'GET')) === 'POST'
            && (string) ($params['route'] ?? '') === self::ROUTE) {
            self::$allowed = true;
        }
        return $result;
    }

    public static function permission()
    {
        if (!self::$allowed || !current_user_can('manage_options')) {
            return new WP_Error(
                'wpab_v095_internal_only',
                'This route is only callable through the signed Bridge REST proxy.',
                ['status' => 403]
            );
        }
        self::$allowed = false;
        return true;
    }

    public static function dispatch(WP_REST_Request $request)
    {
        $json = $request->get_json_params();
        if (!is_array($json)) {
            return new WP_Error('wpab_v095_json', 'JSON body is required.', ['status' => 400]);
        }
        $action = is_string($json['action'] ?? null) ? trim($json['action']) : '';
        $params = isset($json['params']) && is_array($json['params']) ? $json['params'] : [];
        if (!in_array($action, self::ACTIONS, true)) {
            return new WP_Error('wpab_v095_action', 'Unknown or blocked v0.9.5 action.', [
                'status' => 400,
                'action' => $action,
            ]);
        }

        try {
            switch ($action) {
                case 'v095.capabilities':
                    return rest_ensure_response(self::capabilities());
                case 'theme.file.outline':
                    return WP_Agent_Bridge_V095_Outline::outline($params);
                case 'theme.file.read.range':
                    return WP_Agent_Bridge_V095_Outline::read_range($params);
                case 'page.html.inspect':
                    return WP_Agent_Bridge_V095_HTML::inspect($params);
                case 'classic_theme.create':
                    return WP_Agent_Bridge_V095_Classic::create($params);
                case 'classic_theme.list':
                    return WP_Agent_Bridge_V095_Classic::list_drafts();
                case 'classic_theme.info':
                    return WP_Agent_Bridge_V095_Classic::info($params);
                case 'classic_theme.preview_url':
                    return WP_Agent_Bridge_V095_Classic::preview_url($params);
                case 'classic_theme.publish':
                    return WP_Agent_Bridge_V095_Classic::publish($params);
                case 'classic_theme.discard':
                    return WP_Agent_Bridge_V095_Classic::discard($params);
            }
        } catch (Throwable $e) {
            return new WP_Error('wpab_v095_exception', $e->getMessage(), [
                'status' => 500,
                'type' => get_class($e),
            ]);
        }
        return new WP_Error('wpab_v095_dispatch', 'Dispatch fell through.', ['status' => 500]);
    }

    public static function health($response, array $handler, WP_REST_Request $request)
    {
        if ($request->get_route() !== self::HEALTH || is_wp_error($response)) {
            return $response;
        }
        $rest = rest_ensure_response($response);
        $data = $rest->get_data();
        if (!is_array($data)) {
            return $response;
        }
        $data['bridge_version'] = self::VERSION;
        $features = isset($data['features']) && is_array($data['features']) ? $data['features'] : [];
        foreach ([
            'theme_file_structural_outline',
            'bounded_theme_line_range_read',
            'same_origin_server_rendered_html_inspection',
            'css_selector_html_extraction',
            'standalone_classic_theme_draft_lifecycle',
        ] as $feature) {
            if (!in_array($feature, $features, true)) {
                $features[] = $feature;
            }
        }
        $data['features'] = $features;
        $rest->set_data($data);
        return $rest;
    }

    private static function capabilities(): array
    {
        return [
            'version' => self::VERSION,
            'internal_route' => self::ROUTE,
            'actions' => self::ACTIONS,
            'outline' => [
                'scopes' => ['active', 'draft'],
                'extensions' => WP_Agent_Bridge_V095_Outline::extensions(),
                'range_read' => true,
            ],
            'html' => [
                'same_origin_only' => true,
                'server_rendered' => true,
                'javascript_executed' => false,
                'css_selector_subset' => true,
                'cookies_sent' => false,
            ],
            'classic_theme' => [
                'standalone_draft' => true,
                'preview_token' => true,
                'publish_by_switch_theme' => true,
                'previous_theme_files_left_intact' => true,
            ],
            'not_a_browser_runtime' => true,
            'arbitrary_shell' => false,
            'arbitrary_wp_cli' => false,
        ];
    }

    private static function valid_hmac(WP_REST_Request $request): bool
    {
        $secret = (string) get_option(self::SECRET, '');
        $user_id = (int) get_option(self::USER, 0);
        if ($secret === '' || $user_id < 1 || !user_can($user_id, 'manage_options')) {
            return false;
        }
        $timestamp = trim((string) $request->get_header('x-wpab-timestamp'));
        $signature = strtolower(trim((string) $request->get_header('x-wpab-signature')));
        if ($timestamp === '' || $signature === '' || !ctype_digit($timestamp)
            || abs(time() - (int) $timestamp) > self::SKEW) {
            return false;
        }
        $payload = $timestamp . "\nPOST\n" . self::OUTER . "\n" . hash('sha256', (string) $request->get_body());
        return hash_equals(hash_hmac('sha256', $payload, $secret), $signature);
    }

    private static function inner(WP_REST_Request $request)
    {
        $outer = json_decode((string) $request->get_body(), true);
        if (!is_array($outer)
            || ($outer['action'] ?? '') !== 'envelope'
            || !isset($outer['params']['payload_b64'])) {
            return null;
        }
        $decoded = base64_decode((string) $outer['params']['payload_b64'], true);
        if (!is_string($decoded)) {
            return null;
        }
        $inner = json_decode($decoded, true);
        return is_array($inner) ? $inner : null;
    }
}
