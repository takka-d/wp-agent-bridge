<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TakKa_WordPress_Bridge_V095_Classic
{
    private const OPTION = 'takka_bridge_v095_classic_drafts';
    private const PREVIEW_PREFIX = 'takka_bridge_v095_preview_';
    private const MAX_FILES = 1000;
    private const MAX_BYTES = 20971520;

    public static function create(array $params)
    {
        $name = is_string($params['theme_name'] ?? null) ? sanitize_text_field($params['theme_name']) : '';
        if ($name === '') {
            return new WP_Error('takka_bridge_v095_theme_name', 'theme_name is required.', ['status' => 400]);
        }
        $description = is_string($params['description'] ?? null) ? sanitize_text_field($params['description']) : '';
        $id = 'classic-' . gmdate('Ymd-His') . '-' . strtolower(wp_generate_password(6, false, false));
        $slug = 'takka-' . $id;
        $theme_root = realpath(get_theme_root());
        if ($theme_root === false || !is_dir($theme_root)) {
            return new WP_Error('takka_bridge_v095_theme_root', 'Theme root was not found.', ['status' => 500]);
        }
        $target = $theme_root . DIRECTORY_SEPARATOR . $slug;
        if (file_exists($target) || !wp_mkdir_p($target)) {
            return new WP_Error('takka_bridge_v095_theme_dir', 'Could not create classic theme draft directory.', ['status' => 500]);
        }

        $safe_name = str_replace(['*/', "\r", "\n"], ['', ' ', ' '], $name);
        $safe_description = str_replace(['*/', "\r", "\n"], ['', ' ', ' '], $description);
        $files = [
            'style.css' => "/*\nTheme Name: {$safe_name}\nDescription: {$safe_description}\nVersion: 0.1.0\nText Domain: {$slug}\n*/\n\nbody { margin: 0; font-family: system-ui, sans-serif; }\n",
            'functions.php' => "<?php\nif (!defined('ABSPATH')) { exit; }\n\nadd_action('wp_enqueue_scripts', function () {\n    wp_enqueue_style('{$slug}-style', get_stylesheet_uri(), [], wp_get_theme()->get('Version'));\n});\n",
            'header.php' => "<!doctype html>\n<html <?php language_attributes(); ?>>\n<head>\n<meta charset=\"<?php bloginfo('charset'); ?>\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n<?php wp_head(); ?>\n</head>\n<body <?php body_class(); ?>>\n<?php wp_body_open(); ?>\n<header class=\"site-header\"><a href=\"<?php echo esc_url(home_url('/')); ?>\"><?php bloginfo('name'); ?></a></header>\n<main class=\"site-main\">\n",
            'footer.php' => "</main>\n<footer class=\"site-footer\"><?php echo esc_html(get_bloginfo('name')); ?></footer>\n<?php wp_footer(); ?>\n</body>\n</html>\n",
            'index.php' => "<?php get_header(); ?>\n<?php if (have_posts()) : while (have_posts()) : the_post(); ?>\n<article <?php post_class(); ?>>\n<h1><a href=\"<?php the_permalink(); ?>\"><?php the_title(); ?></a></h1>\n<div class=\"entry-content\"><?php the_content(); ?></div>\n</article>\n<?php endwhile; else : ?>\n<p><?php esc_html_e('No posts found.', '{$slug}'); ?></p>\n<?php endif; ?>\n<?php get_footer(); ?>\n",
        ];

        $bytes = 0;
        foreach ($files as $relative => $content) {
            $path = $target . DIRECTORY_SEPARATOR . $relative;
            if (file_put_contents($path, $content, LOCK_EX) !== strlen($content)) {
                self::remove_tree($target);
                return new WP_Error('takka_bridge_v095_theme_write', 'Could not write classic theme draft file.', [
                    'status' => 500,
                    'path' => $relative,
                ]);
            }
            if (strtolower(pathinfo($relative, PATHINFO_EXTENSION)) === 'php') {
                $lint = self::lint_php($content);
                if (is_wp_error($lint)) {
                    self::remove_tree($target);
                    return $lint;
                }
            }
            $bytes += strlen($content);
        }

        wp_clean_themes_cache(true);
        $theme = wp_get_theme($slug);
        if (!$theme->exists() || $theme->errors()) {
            $errors = $theme->errors();
            self::remove_tree($target);
            wp_clean_themes_cache(true);
            return new WP_Error('takka_bridge_v095_theme_invalid', 'Generated classic theme was not recognized by WordPress.', [
                'status' => 500,
                'errors' => is_wp_error($errors) ? $errors->get_error_messages() : null,
            ]);
        }

        $drafts = self::drafts();
        $draft = [
            'id' => $id,
            'slug' => $slug,
            'theme_name' => $name,
            'description' => $description,
            'created_at' => gmdate('c'),
            'modified_at' => gmdate('c'),
            'file_count' => count($files),
            'bytes' => $bytes,
            'source_stylesheet' => get_stylesheet(),
            'source_template' => get_template(),
        ];
        $drafts[$id] = $draft;
        self::save($drafts);
        return rest_ensure_response($draft);
    }

    public static function list_drafts()
    {
        return rest_ensure_response([
            'active_stylesheet' => get_stylesheet(),
            'drafts' => array_values(self::drafts()),
        ]);
    }

    public static function info(array $params)
    {
        $draft = self::require_draft($params);
        if (is_wp_error($draft)) {
            return $draft;
        }
        $root = self::root($draft);
        if (!is_dir($root)) {
            return new WP_Error('takka_bridge_v095_classic_root', 'Classic theme draft directory is missing.', ['status' => 410]);
        }
        $files = self::collect_files($root);
        if (is_wp_error($files)) {
            return $files;
        }
        $lint = self::lint_tree($root);
        return rest_ensure_response([
            'draft' => $draft,
            'root' => basename($root),
            'files' => $files,
            'php_valid' => !is_wp_error($lint),
            'php_error' => is_wp_error($lint) ? $lint->get_error_message() : null,
        ]);
    }

    public static function preview_url(array $params)
    {
        $draft = self::require_draft($params);
        if (is_wp_error($draft)) {
            return $draft;
        }
        try {
            $token = bin2hex(random_bytes(24));
        } catch (Throwable $e) {
            $token = wp_generate_password(48, false, false);
        }
        $ttl = isset($params['ttl']) ? max(300, min(86400, (int) $params['ttl'])) : 7200;
        set_transient(self::PREVIEW_PREFIX . hash('sha256', $token), [
            'draft_id' => $draft['id'],
            'slug' => $draft['slug'],
        ], $ttl);
        $path = is_string($params['path'] ?? null) ? $params['path'] : '/';
        if ($path === '' || $path[0] !== '/' || strpos($path, '://') !== false || preg_match('/[\r\n\0]/', $path)) {
            return new WP_Error('takka_bridge_v095_preview_path', 'Preview path must be a local path beginning with /.', ['status' => 400]);
        }
        return rest_ensure_response([
            'draft_id' => $draft['id'],
            'url' => add_query_arg('wpab_classic_preview', rawurlencode($token), home_url($path)),
            'expires_in' => $ttl,
        ]);
    }

    public static function publish(array $params)
    {
        $draft = self::require_draft($params);
        if (is_wp_error($draft)) {
            return $draft;
        }
        if (empty($params['confirm'])) {
            return new WP_Error('takka_bridge_v095_publish_confirm', 'classic_theme.publish requires confirm=true.', ['status' => 400]);
        }
        $root = self::root($draft);
        $lint = self::lint_tree($root);
        if (is_wp_error($lint)) {
            return $lint;
        }
        wp_clean_themes_cache(true);
        $theme = wp_get_theme($draft['slug']);
        if (!$theme->exists() || $theme->errors()) {
            return new WP_Error('takka_bridge_v095_theme_invalid', 'Classic theme draft is not a valid WordPress theme.', ['status' => 400]);
        }
        $previous = [
            'stylesheet' => get_stylesheet(),
            'template' => get_template(),
            'name' => wp_get_theme()->get('Name'),
        ];
        switch_theme($draft['slug']);
        wp_clean_themes_cache(true);
        wp_cache_flush();
        if (get_stylesheet() !== $draft['slug']) {
            return new WP_Error('takka_bridge_v095_theme_activate', 'WordPress did not activate the generated classic theme.', ['status' => 500]);
        }
        $drafts = self::drafts();
        unset($drafts[$draft['id']]);
        self::save($drafts);
        return rest_ensure_response([
            'ok' => true,
            'draft_id' => $draft['id'],
            'active_stylesheet' => get_stylesheet(),
            'active_template' => get_template(),
            'previous_theme' => $previous,
            'rollback' => 'Use theme.manage.activate with previous_theme.stylesheet to switch back.',
        ]);
    }

    public static function discard(array $params)
    {
        $draft = self::require_draft($params);
        if (is_wp_error($draft)) {
            return $draft;
        }
        if (get_stylesheet() === $draft['slug']) {
            return new WP_Error('takka_bridge_v095_discard_active', 'Cannot discard a currently active classic theme.', ['status' => 409]);
        }
        $root = self::root($draft);
        if (!self::remove_tree($root) && is_dir($root)) {
            return new WP_Error('takka_bridge_v095_discard', 'Could not remove classic theme draft directory.', ['status' => 500]);
        }
        $drafts = self::drafts();
        unset($drafts[$draft['id']]);
        self::save($drafts);
        wp_clean_themes_cache(true);
        return rest_ensure_response(['ok' => true, 'draft_id' => $draft['id']]);
    }

    public static function preview_stylesheet($pre)
    {
        $context = self::preview_context();
        return $context ? $context['slug'] : $pre;
    }

    public static function preview_template($pre)
    {
        $context = self::preview_context();
        return $context ? $context['slug'] : $pre;
    }

    public static function preview_no_cache(): void
    {
        if (!self::preview_context()) {
            return;
        }
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow', true);
    }

    private static function preview_context()
    {
        if (is_admin() || !isset($_GET['wpab_classic_preview']) || !is_string($_GET['wpab_classic_preview'])) {
            return null;
        }
        $token = sanitize_text_field(wp_unslash($_GET['wpab_classic_preview']));
        if ($token === '' || strlen($token) > 100) {
            return null;
        }
        $context = get_transient(self::PREVIEW_PREFIX . hash('sha256', $token));
        if (!is_array($context) || empty($context['draft_id']) || empty($context['slug'])) {
            return null;
        }
        $drafts = self::drafts();
        if (!isset($drafts[$context['draft_id']]) || $drafts[$context['draft_id']]['slug'] !== $context['slug']) {
            return null;
        }
        return $context;
    }

    private static function drafts(): array
    {
        $value = get_option(self::OPTION, []);
        return is_array($value) ? $value : [];
    }

    private static function save(array $drafts): void
    {
        update_option(self::OPTION, $drafts, false);
    }

    private static function require_draft(array $params)
    {
        $id = is_string($params['draft_id'] ?? null) ? trim($params['draft_id']) : '';
        if ($id === '') {
            return new WP_Error('takka_bridge_v095_classic_id', 'draft_id is required.', ['status' => 400]);
        }
        $drafts = self::drafts();
        if (!isset($drafts[$id]) || !is_array($drafts[$id])) {
            return new WP_Error('takka_bridge_v095_classic_missing', 'Classic theme draft was not found.', ['status' => 404]);
        }
        return $drafts[$id];
    }

    private static function root(array $draft): string
    {
        return rtrim(get_theme_root(), '/\\') . DIRECTORY_SEPARATOR . basename((string) $draft['slug']);
    }

    private static function lint_php(string $content)
    {
        try {
            token_get_all($content, TOKEN_PARSE);
        } catch (ParseError $e) {
            return new WP_Error('takka_bridge_v095_theme_php', $e->getMessage(), ['status' => 400]);
        }
        return true;
    }

    private static function lint_tree(string $root)
    {
        if (!is_dir($root)) {
            return new WP_Error('takka_bridge_v095_classic_root', 'Classic theme draft directory is missing.', ['status' => 410]);
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $content = file_get_contents($file->getPathname());
            if (!is_string($content)) {
                return new WP_Error('takka_bridge_v095_theme_read', 'Could not read PHP file.', ['status' => 500]);
            }
            $lint = self::lint_php($content);
            if (is_wp_error($lint)) {
                $lint->add_data(['status' => 400, 'path' => self::relative($root, $file->getPathname())]);
                return $lint;
            }
        }
        return true;
    }

    private static function collect_files(string $root)
    {
        $files = [];
        $bytes = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }
            $bytes += $file->getSize();
            $files[] = [
                'path' => self::relative($root, $file->getPathname()),
                'bytes' => $file->getSize(),
                'modified' => gmdate('c', $file->getMTime()),
            ];
            if (count($files) > self::MAX_FILES || $bytes > self::MAX_BYTES) {
                return new WP_Error('takka_bridge_v095_classic_size', 'Classic theme draft exceeds inspection limits.', ['status' => 413]);
            }
        }
        usort($files, static function (array $a, array $b): int {
            return strcmp($a['path'], $b['path']);
        });
        return $files;
    }

    private static function relative(string $root, string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $path = str_replace('\\', '/', $path);
        return strpos($path, $root . '/') === 0 ? substr($path, strlen($root) + 1) : basename($path);
    }

    private static function remove_tree(string $dir): bool
    {
        if (!file_exists($dir)) {
            return true;
        }
        if (!is_dir($dir) || is_link($dir)) {
            return @unlink($dir);
        }
        $items = scandir($dir);
        if (!is_array($items)) {
            return false;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path) && !is_link($path)) {
                if (!self::remove_tree($path)) {
                    return false;
                }
            } elseif (!@unlink($path)) {
                return false;
            }
        }
        return @rmdir($dir);
    }
}
