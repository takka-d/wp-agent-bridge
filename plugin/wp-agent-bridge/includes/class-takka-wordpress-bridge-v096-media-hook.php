<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Optional post-upload behavior for Direct Runtime media uploads.
 *
 * The normal media-upload route remains unchanged for ordinary files. When a
 * command explicitly includes set_site_icon=true + confirm_site_icon=true,
 * the successfully created attachment is assigned as the WordPress Site Icon.
 */
final class TakKa_WordPress_Bridge_V096_Media_Hook
{
    private const MEDIA_ROUTE = '/wp-agent-bridge-runtime/v1/media-upload';

    public static function init(): void
    {
        add_filter('rest_pre_dispatch', [self::class, 'preflight'], 68, 3);
        add_filter('rest_request_after_callbacks', [self::class, 'after_upload'], 565, 3);
    }

    public static function preflight($result, WP_REST_Server $server, WP_REST_Request $request)
    {
        if ($result !== null
            || $request->get_route() !== self::MEDIA_ROUTE
            || strtoupper($request->get_method()) !== 'POST'
            || !current_user_can('manage_options')) {
            return $result;
        }
        $json = $request->get_json_params();
        if (!is_array($json) || empty($json['set_site_icon'])) {
            return $result;
        }
        if (empty($json['confirm_site_icon'])) {
            return new WP_Error(
                'wpab_direct_media_site_icon_confirmation_required',
                'Setting the uploaded image as Site Icon requires confirm_site_icon=true.',
                ['status' => 400]
            );
        }
        return $result;
    }

    public static function after_upload($response, array $handler, WP_REST_Request $request)
    {
        if ($request->get_route() !== self::MEDIA_ROUTE
            || strtoupper($request->get_method()) !== 'POST'
            || is_wp_error($response)) {
            return $response;
        }
        $json = $request->get_json_params();
        if (!is_array($json) || empty($json['set_site_icon'])) {
            return $response;
        }

        $rest = rest_ensure_response($response);
        $data = $rest->get_data();
        if (!is_array($data) || empty($data['ok']) || empty($data['id'])) {
            return $response;
        }

        $site_icon = TakKa_WordPress_Bridge_V096_Site::set_uploaded_attachment((int) $data['id'], $json);
        if (is_wp_error($site_icon)) {
            $data['site_icon_set'] = false;
            $data['site_icon_error'] = [
                'code' => $site_icon->get_error_code(),
                'message' => $site_icon->get_error_message(),
                'data' => $site_icon->get_error_data(),
            ];
            $data['uploaded_attachment_retained'] = true;
            $rest->set_data($data);
            return $rest;
        }

        $site_icon_rest = rest_ensure_response($site_icon);
        $data['site_icon_set'] = true;
        $data['site_icon'] = $site_icon_rest->get_data();
        $rest->set_data($data);

        if (class_exists('TakKa_WordPress_Bridge_V07_Audit')) {
            TakKa_WordPress_Bridge_V07_Audit::record(
                'runtime-media-upload-site-icon',
                'site.icon.set',
                [
                    'attachment_id' => (int) $data['id'],
                    'expected_current_id' => array_key_exists('expected_site_icon_id', $json)
                        ? (int) $json['expected_site_icon_id']
                        : null,
                    'source' => 'runtime-media-upload',
                ],
                $site_icon
            );
        }
        return $rest;
    }
}
