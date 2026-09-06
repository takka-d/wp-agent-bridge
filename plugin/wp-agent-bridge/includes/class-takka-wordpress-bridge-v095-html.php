<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TakKa_WordPress_Bridge_V095_HTML
{
    private const MAX_URL = 4096;
    private const MAX_BODY = 1048576;
    private const MAX_OUTPUT = 200000;
    private const MAX_REDIRECTS = 5;
    private const MAX_MATCHES = 30;

    public static function inspect(array $params)
    {
        $raw = is_string($params['url'] ?? null) ? trim($params['url']) : home_url('/');
        $url = self::same_origin($raw, null);
        if (is_wp_error($url)) {
            return $url;
        }
        $timeout = isset($params['timeout']) ? max(1, min(20, (int) $params['timeout'])) : 10;
        $max_bytes = isset($params['max_body_bytes']) ? max(1024, min(self::MAX_BODY, (int) $params['max_body_bytes'])) : self::MAX_BODY;
        $max_chars = isset($params['max_chars']) ? max(1000, min(self::MAX_OUTPUT, (int) $params['max_chars'])) : 50000;
        $current = $url;
        $redirects = [];
        $response = null;
        $start = microtime(true);

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $response = wp_safe_remote_get($current, [
                'timeout' => $timeout,
                'redirection' => 0,
                'reject_unsafe_urls' => true,
                'cookies' => [],
                'headers' => [
                    'User-Agent' => 'WP-Agent-Bridge/0.9.5',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                ],
                'limit_response_size' => $max_bytes + 1,
            ]);
            if (is_wp_error($response)) {
                return new WP_Error('takka_bridge_v095_html_http', $response->get_error_message(), [
                    'status' => 502,
                    'url' => $current,
                    'inner_code' => $response->get_error_code(),
                ]);
            }
            $status = (int) wp_remote_retrieve_response_code($response);
            $location = (string) wp_remote_retrieve_header($response, 'location');
            if ($status >= 300 && $status < 400 && $location !== '') {
                if ($hop >= self::MAX_REDIRECTS) {
                    return new WP_Error('takka_bridge_v095_html_redirect_limit', 'Redirect limit reached.', ['status' => 508]);
                }
                $next = self::same_origin($location, $current);
                if (is_wp_error($next)) {
                    return new WP_Error('takka_bridge_v095_html_redirect_blocked', 'Redirect left the WordPress origin.', [
                        'status' => 400,
                        'from' => $current,
                        'location' => $location,
                    ]);
                }
                $redirects[] = ['url' => $current, 'status' => $status, 'location' => $next];
                $current = $next;
                continue;
            }
            break;
        }

        if (!is_array($response)) {
            return new WP_Error('takka_bridge_v095_html_empty', 'HTML request returned no response.', ['status' => 502]);
        }
        $body = (string) wp_remote_retrieve_body($response);
        $truncated = strlen($body) > $max_bytes;
        if ($truncated) {
            $body = substr($body, 0, $max_bytes);
        }
        $content_type = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
        if ($content_type !== '' && strpos($content_type, 'html') === false && strpos($content_type, 'xml') === false) {
            return new WP_Error('takka_bridge_v095_not_html', 'Response is not HTML/XML content.', [
                'status' => 415,
                'content_type' => $content_type,
            ]);
        }
        $html = (string) wp_check_invalid_utf8($body, true);
        $selector = is_string($params['selector'] ?? null) ? trim($params['selector']) : '';
        $selection = null;
        if ($selector !== '') {
            $selection = self::select($html, $selector, isset($params['max_matches'])
                ? max(1, min(self::MAX_MATCHES, (int) $params['max_matches']))
                : 10, $max_chars);
            if (is_wp_error($selection)) {
                return $selection;
            }
        }
        $headers = wp_remote_retrieve_headers($response);
        $header_array = is_array($headers)
            ? $headers
            : (is_object($headers) && method_exists($headers, 'getAll') ? $headers->getAll() : (array) $headers);

        return rest_ensure_response([
            'ok' => true,
            'requested_url' => $url,
            'final_url' => $current,
            'status' => (int) wp_remote_retrieve_response_code($response),
            'headers' => $header_array,
            'content_type' => $content_type !== '' ? $content_type : null,
            'received_body_bytes' => strlen($body),
            'body_truncated' => $truncated,
            'html' => $selector === '' ? mb_substr($html, 0, $max_chars) : null,
            'html_truncated_for_output' => $selector === '' ? mb_strlen($html) > $max_chars : null,
            'selector' => $selector !== '' ? $selector : null,
            'match_count' => $selection !== null ? $selection['match_count'] : null,
            'matches' => $selection !== null ? $selection['matches'] : null,
            'redirects' => $redirects,
            'timing_ms' => (int) round((microtime(true) - $start) * 1000),
            'server_rendered' => true,
            'javascript_executed' => false,
            'cookies_sent' => false,
        ]);
    }

    private static function select(string $html, string $selector, int $max_matches, int $max_chars)
    {
        if (!class_exists('DOMDocument') || !class_exists('DOMXPath')) {
            return new WP_Error('takka_bridge_v095_dom_unavailable', 'DOM extension is not available.', ['status' => 501]);
        }
        $xpath_expr = self::css_to_xpath($selector);
        if (is_wp_error($xpath_expr)) {
            return $xpath_expr;
        }
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return new WP_Error('takka_bridge_v095_dom_parse', 'HTML could not be parsed.', ['status' => 400]);
        }
        $nodes = @(new DOMXPath($dom))->query($xpath_expr);
        if ($nodes === false) {
            return new WP_Error('takka_bridge_v095_selector_query', 'Selector could not be evaluated.', ['status' => 400]);
        }
        $matches = [];
        $used = 0;
        for ($i = 0; $i < $nodes->length && count($matches) < $max_matches; $i++) {
            $fragment = $dom->saveHTML($nodes->item($i));
            if (!is_string($fragment)) {
                continue;
            }
            $remaining = max(0, $max_chars - $used);
            if ($remaining < 1) {
                break;
            }
            $shown = mb_substr($fragment, 0, $remaining);
            $matches[] = [
                'index' => $i,
                'html' => $shown,
                'truncated' => mb_strlen($fragment) > mb_strlen($shown),
            ];
            $used += mb_strlen($shown);
        }
        return ['match_count' => $nodes->length, 'matches' => $matches];
    }

    private static function css_to_xpath(string $selector)
    {
        if ($selector === '' || strlen($selector) > 1000 || preg_match('/[\r\n\0]/', $selector)) {
            return new WP_Error('takka_bridge_v095_selector', 'Selector is empty or invalid.', ['status' => 400]);
        }
        if (strpos($selector, ',') !== false || strpos($selector, ':') !== false
            || strpos($selector, '+') !== false || strpos($selector, '~') !== false) {
            return new WP_Error(
                'takka_bridge_v095_selector_subset',
                'Use only tag, #id, .class, [attr], [attr=value], descendant, or child selectors.',
                ['status' => 400]
            );
        }
        $normalized = preg_replace('/\s*>\s*/', ' > ', trim($selector));
        $parts = preg_split('/\s+/', (string) $normalized);
        if (!is_array($parts) || !$parts) {
            return new WP_Error('takka_bridge_v095_selector', 'Selector could not be parsed.', ['status' => 400]);
        }
        $xpath = '.';
        $axis = '//';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if ($part === '>') {
                $axis = '/';
                continue;
            }
            $simple = self::simple($part);
            if (is_wp_error($simple)) {
                return $simple;
            }
            $xpath .= $axis . $simple;
            $axis = '//';
        }
        return $xpath;
    }

    private static function simple(string $part)
    {
        $tag = '*';
        $predicates = [];
        if (preg_match('/^[A-Za-z][A-Za-z0-9_-]*/', $part, $match)) {
            $tag = strtolower($match[0]);
            $part = substr($part, strlen($match[0]));
        } elseif (isset($part[0]) && $part[0] === '*') {
            $part = substr($part, 1);
        }
        while ($part !== '') {
            if ($part[0] === '#') {
                if (!preg_match('/^#([A-Za-z_][A-Za-z0-9_-]*)/', $part, $m)) {
                    return new WP_Error('takka_bridge_v095_selector_id', 'Invalid id selector.', ['status' => 400]);
                }
                $predicates[] = '@id=' . self::literal($m[1]);
                $part = substr($part, strlen($m[0]));
                continue;
            }
            if ($part[0] === '.') {
                if (!preg_match('/^\.([A-Za-z_][A-Za-z0-9_-]*)/', $part, $m)) {
                    return new WP_Error('takka_bridge_v095_selector_class', 'Invalid class selector.', ['status' => 400]);
                }
                $predicates[] = 'contains(concat(" ", normalize-space(@class), " "), ' . self::literal(' ' . $m[1] . ' ') . ')';
                $part = substr($part, strlen($m[0]));
                continue;
            }
            if ($part[0] === '[') {
                if (!preg_match('/^\[([A-Za-z_:][A-Za-z0-9_:.\-]*)(?:=([\'\"]?)([^\]\'\"]*)\2)?\]/', $part, $m)) {
                    return new WP_Error('takka_bridge_v095_selector_attr', 'Invalid attribute selector.', ['status' => 400]);
                }
                $predicates[] = isset($m[3]) && $m[3] !== '' ? '@' . $m[1] . '=' . self::literal($m[3]) : '@' . $m[1];
                $part = substr($part, strlen($m[0]));
                continue;
            }
            return new WP_Error('takka_bridge_v095_selector_subset', 'Unsupported selector fragment.', [
                'status' => 400,
                'fragment' => $part,
            ]);
        }
        return $tag . ($predicates ? '[' . implode(' and ', $predicates) . ']' : '');
    }

    private static function literal(string $value): string
    {
        if (strpos($value, "'") === false) {
            return "'" . $value . "'";
        }
        if (strpos($value, '"') === false) {
            return '"' . $value . '"';
        }
        $parts = explode("'", $value);
        $pieces = [];
        foreach ($parts as $index => $part) {
            if ($index > 0) {
                $pieces[] = '"\'"';
            }
            if ($part !== '') {
                $pieces[] = "'" . $part . "'";
            }
        }
        return 'concat(' . implode(',', $pieces) . ')';
    }

    private static function same_origin(string $candidate, ?string $base)
    {
        $candidate = trim($candidate);
        if ($candidate === '' || strlen($candidate) > self::MAX_URL || preg_match('/[\r\n\0]/', $candidate)) {
            return new WP_Error('takka_bridge_v095_url', 'Invalid URL.', ['status' => 400]);
        }
        $home = wp_parse_url(home_url('/'));
        if (!is_array($home) || empty($home['scheme']) || empty($home['host'])) {
            return new WP_Error('takka_bridge_v095_home', 'Could not resolve WordPress origin.', ['status' => 500]);
        }
        $origin = $home['scheme'] . '://' . $home['host'] . (isset($home['port']) ? ':' . (int) $home['port'] : '');
        if (strpos($candidate, '//') === 0) {
            $candidate = $home['scheme'] . ':' . $candidate;
        } elseif (strpos($candidate, '/') === 0) {
            $candidate = $origin . $candidate;
        } elseif (!preg_match('#^https?://#i', $candidate)) {
            if ($base === null) {
                $candidate = home_url('/' . ltrim($candidate, '/'));
            } else {
                $parts = wp_parse_url($base);
                if (!is_array($parts)) {
                    return new WP_Error('takka_bridge_v095_base', 'Invalid base URL.', ['status' => 400]);
                }
                $base_origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
                $dir = preg_replace('#/[^/]*$#', '/', (string) ($parts['path'] ?? '/'));
                $candidate = $base_origin . $dir . $candidate;
            }
        }
        $parts = wp_parse_url($candidate);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return new WP_Error('takka_bridge_v095_url_parse', 'Could not parse URL.', ['status' => 400]);
        }
        $scheme = strtolower((string) $parts['scheme']);
        $home_scheme = strtolower((string) $home['scheme']);
        $host = strtolower(rtrim((string) $parts['host'], '.'));
        $home_host = strtolower(rtrim((string) $home['host'], '.'));
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        $home_port = isset($home['port']) ? (int) $home['port'] : ($home_scheme === 'https' ? 443 : 80);
        if (!in_array($scheme, ['http', 'https'], true) || $scheme !== $home_scheme || $host !== $home_host || $port !== $home_port) {
            return new WP_Error('takka_bridge_v095_cross_origin', 'HTML inspection is restricted to the WordPress site origin.', ['status' => 400]);
        }
        return esc_url_raw($candidate);
    }
}
