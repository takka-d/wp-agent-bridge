<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Short-circuits post.revisions.* on rest_pre_dispatch.
 *
 * WordPress' rest_request_before_callbacks filter is observational and did not
 * suppress the v0.9.9 callback in clean integration. Consume the deterministic
 * route's own internal permission token here, then reuse the revision handler.
 */
final class TakKa_WordPress_Bridge_Post_Revisions_Pre_Dispatch
{
    private const ROUTE = '/takka-v099/v1/operate';
    private const OPERATIONS = [
        'post.revisions.list',
        'post.revisions.get',
        'post.revisions.restore',
    ];

    public static function init(): void
    {
        add_filter('rest_pre_dispatch', [self::class, 'dispatch'], 73, 3);
    }

    public static function dispatch($result, WP_REST_Server $server, WP_REST_Request $request)
    {
        if ($result !== null
            || $request->get_route() !== self::ROUTE
            || strtoupper($request->get_method()) !== 'POST') {
            return $result;
        }
        $json = $request->get_json_params();
        if (!is_array($json)) {
            return $result;
        }
        $operation = is_string($json['operation'] ?? null) ? trim($json['operation']) : '';
        if (!in_array($operation, self::OPERATIONS, true)) {
            return $result;
        }

        // rest_pre_dispatch runs before the route permission callback. Reuse
        // v0.9.9's own one-shot internal permission check so this does not turn
        // the deterministic route into a directly callable admin endpoint.
        $permission = TakKa_WordPress_Bridge_V099_Operations::permission();
        if (is_wp_error($permission)) {
            return $permission;
        }
        if ($permission !== true) {
            return new WP_Error('wpab_post_revision_internal_only', 'Revision operations are only callable through the signed Bridge operation path.', [
                'status' => 403,
                'side_effects' => false,
            ]);
        }

        return TakKa_WordPress_Bridge_Post_Revisions::dispatch(null, [], $request);
    }
}
