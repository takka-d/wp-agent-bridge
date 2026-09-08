<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Move the staged-media fast path onto rest_pre_dispatch.
 *
 * rest_request_before_callbacks is not a reliable callback short-circuit in the
 * observed WordPress execution flow. Returning the successful fast-path upload
 * there allowed the registered legacy media callback to run afterward, after
 * the fast path had already deleted the staged GitHub payload. That second
 * callback then returned a false GitHub 404 even though the attachment had
 * already been created successfully.
 *
 * rest_pre_dispatch explicitly short-circuits route dispatch, so a successful
 * fast-path upload cannot fall through to the legacy callback. The legacy
 * callback remains available when Auto Path deliberately returns null for its
 * bounded 404/405/501 compatibility fallback.
 */
final class TakKa_WordPress_Bridge_Direct_Media_Pre_Dispatch
{
    private const OLD_PRIORITY = 515;
    private const PRE_DISPATCH_PRIORITY = 25;

    public static function init(): void
    {
        remove_filter(
            'rest_request_before_callbacks',
            [TakKa_WordPress_Bridge_Direct_Media_Auto_Path::class, 'maybe_upload'],
            self::OLD_PRIORITY
        );
        add_filter('rest_pre_dispatch', [self::class, 'pre_dispatch'], self::PRE_DISPATCH_PRIORITY, 3);
    }

    public static function pre_dispatch($result, WP_REST_Server $server, WP_REST_Request $request)
    {
        return TakKa_WordPress_Bridge_Direct_Media_Auto_Path::maybe_upload($result, [], $request);
    }
}
