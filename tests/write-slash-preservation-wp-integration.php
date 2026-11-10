<?php

/**
 * Clean-WordPress integration: Bridge writes keep literal backslashes.
 *
 * WordPress write functions (wp_update_post, add/update_post_meta,
 * add/update_user_meta, wp_insert_user, wp_update_user, wp_insert_term,
 * wp_update_term and the nav menu helpers) unslash their input. The Bridge
 * receives raw JSON values, so it must slash them first, as the core REST
 * controllers do; otherwise Windows paths, regular expressions and JSON/JS
 * escapes lose their backslashes and "\0" turns into a NUL byte.
 *
 * Run with: wp eval-file tests/write-slash-preservation-wp-integration.php
 */

wp_set_current_user(1);
do_action('rest_api_init');

function ws_fail(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function ws_data($response, string $label): array
{
    if (is_wp_error($response)) {
        ws_fail($label . ' failed: ' . $response->get_error_code() . ' ' . $response->get_error_message() . ' ' . wp_json_encode($response->get_error_data()));
    }
    $data = rest_ensure_response($response)->get_data();
    if (!is_array($data)) {
        ws_fail($label . ' returned a non-object response.');
    }
    return $data;
}

function ws_same(string $label, $expected, $actual): void
{
    if ($expected !== $actual) {
        ws_fail($label . ' changed the value. expected=' . wp_json_encode($expected) . ' actual=' . wp_json_encode($actual));
    }
}

$bs = chr(92);
$path = 'C:' . $bs . 'Users' . $bs . 'me' . $bs . '.codex' . $bs . 'plugins' . $bs;
$regex = '/' . $bs . 'd+' . $bs . '.' . $bs . 'd+/';
// Rich sample for content and meta: path, regex, "\0", escaped quotes, trailing backslash.
$sample = 'path ' . $path . ' regex ' . $regex . ' zero ' . $bs . '0 quote ' . $bs . '"q' . $bs . '" end ' . $bs;
// Plain sample for names and titles, which also pass through sanitize_text_field().
$plain = 'AC' . $bs . 'DC ' . $path;
if (substr_count($sample, $bs) < 10 || substr_count($plain, $bs) < 6) {
    ws_fail('Test fixture does not contain the expected backslashes.');
}

// 1. post.content.batch apply (v0.8.5).
$post_id = wp_insert_post(wp_slash([
    'post_type' => 'post',
    'post_status' => 'draft',
    'post_title' => 'WPAB slash preservation',
    'post_content' => "<p>before</p>\n<pre>" . $sample . "</pre>\n<p>marker-one</p>",
]), true);
if (is_wp_error($post_id)) {
    ws_fail('Fixture post creation failed: ' . $post_id->get_error_message());
}
$before = (string) get_post_field('post_content', $post_id, 'raw');
if (strpos($before, $sample) === false) {
    ws_fail('Fixture post lost its backslashes before any Bridge write.');
}

$operations = [
    ['type' => 'replace', 'find' => 'marker-one', 'replace' => 'marker-two ' . $sample, 'expected_matches' => 1],
];
$preview = ws_data(WP_Agent_Bridge_V085_Content_Batch::preview([
    'post_id' => $post_id,
    'operations' => $operations,
]), 'Batch preview');
$apply = ws_data(WP_Agent_Bridge_V085_Content_Batch::apply([
    'post_id' => $post_id,
    'operations' => $operations,
    'confirm' => true,
    'expected_before_sha256' => $preview['before_sha256'] ?? '',
    'expected_plan_hash' => $preview['plan_hash'] ?? '',
]), 'Batch apply');
clean_post_cache($post_id);
$expected_content = str_replace('marker-one', 'marker-two ' . $sample, $before);
ws_same('Batch apply', $expected_content, (string) get_post_field('post_content', $post_id, 'raw'));
ws_same('Batch apply after_sha256', hash('sha256', $expected_content), $apply['after_sha256'] ?? null);
ws_same('Batch preview after_sha256', $preview['after_sha256'] ?? null, $apply['after_sha256'] ?? null);

// 2. post.meta add/update (v0.8.3), string and nested array values.
ws_data(WP_Agent_Bridge_V083_Post_Meta::update([
    'post_id' => $post_id,
    'key' => 'wpab_slash_string',
    'value' => $sample,
    'confirm' => true,
]), 'Post meta update');
ws_same('Post meta update', $sample, get_post_meta($post_id, 'wpab_slash_string', true));

$array_value = ['nested' => ['path' => $sample, 'regex' => $regex], 'count' => 3, 'flag' => true];
ws_data(WP_Agent_Bridge_V083_Post_Meta::add([
    'post_id' => $post_id,
    'key' => 'wpab_slash_array',
    'value' => $array_value,
    'unique' => true,
    'confirm' => true,
]), 'Post meta add');
ws_same('Post meta add', $array_value, get_post_meta($post_id, 'wpab_slash_array', true));

// 3. Legacy post meta update action.
$legacy = new ReflectionMethod('WP_Agent_Bridge', 'post_meta_update');
$legacy->setAccessible(true);
ws_data($legacy->invoke(null, [
    'post_id' => $post_id,
    'key' => 'wpab_slash_legacy',
    'value' => $sample,
]), 'Legacy post meta update');
ws_same('Legacy post meta update', $sample, get_post_meta($post_id, 'wpab_slash_legacy', true));

// 4. user.meta add/update and user update (v0.7).
ws_data(WP_Agent_Bridge_V07_User_Meta::update([
    'user_id' => 1,
    'key' => 'wpab_slash_user_meta',
    'value' => $sample,
    'confirm' => true,
]), 'User meta update');
ws_same('User meta update', $sample, get_user_meta(1, 'wpab_slash_user_meta', true));

ws_data(WP_Agent_Bridge_V07_Users::update([
    'user_id' => 1,
    'display_name' => $plain,
    'description' => $plain,
    'confirm' => true,
]), 'User update');
clean_user_cache(1);
$user = get_userdata(1);
ws_same('User update display_name', $plain, (string) $user->display_name);
ws_same('User update description', $plain, (string) get_user_meta(1, 'description', true));

// 5. term create/update (v0.7).
$created = ws_data(WP_Agent_Bridge_V07_Terms::create([
    'taxonomy' => 'category',
    'name' => $plain,
    'description' => $plain,
    'confirm' => true,
]), 'Term create');
$term_id = (int) ($created['term']['id'] ?? 0);
if ($term_id < 1) {
    ws_fail('Term create did not return a term id: ' . wp_json_encode($created));
}
clean_term_cache($term_id, 'category');
$term = get_term($term_id, 'category');
ws_same('Term create name', $plain, (string) $term->name);
ws_same('Term create description', $plain, (string) $term->description);

ws_data(WP_Agent_Bridge_V07_Terms::update([
    'taxonomy' => 'category',
    'term_id' => $term_id,
    'name' => $plain . ' updated',
    'description' => $plain . ' updated',
    'confirm' => true,
]), 'Term update');
clean_term_cache($term_id, 'category');
$term = get_term($term_id, 'category');
ws_same('Term update name', $plain . ' updated', (string) $term->name);
ws_same('Term update description', $plain . ' updated', (string) $term->description);

// 6. nav menu create/update and menu item upsert (v0.5).
$menu = ws_data(WP_Agent_Bridge_V05_Admin::menu_create([
    'name' => $plain,
    'confirm' => true,
]), 'Menu create');
$menu_id = (int) ($menu['id'] ?? 0);
if ($menu_id < 1) {
    ws_fail('Menu create did not return a menu id: ' . wp_json_encode($menu));
}
clean_term_cache($menu_id, 'nav_menu');
ws_same('Menu create name', $plain, (string) wp_get_nav_menu_object($menu_id)->name);

ws_data(WP_Agent_Bridge_V05_Admin::menu_update([
    'menu_id' => $menu_id,
    'name' => $plain . ' renamed',
    'description' => $plain,
    'confirm' => true,
]), 'Menu update');
clean_term_cache($menu_id, 'nav_menu');
$menu_object = wp_get_nav_menu_object($menu_id);
ws_same('Menu update name', $plain . ' renamed', (string) $menu_object->name);
ws_same('Menu update description', $plain, (string) $menu_object->description);

$item = ws_data(WP_Agent_Bridge_V05_Admin::menu_item_upsert([
    'menu_id' => $menu_id,
    'type' => 'custom',
    'title' => $plain,
    'url' => 'https://example.test/slash',
    'confirm' => true,
]), 'Menu item upsert');
$item_id = (int) ($item['item_id'] ?? 0);
if ($item_id < 1) {
    ws_fail('Menu item upsert did not return an item id: ' . wp_json_encode($item));
}
clean_post_cache($item_id);
ws_same('Menu item title', $plain, (string) get_post_field('post_title', $item_id, 'raw'));

echo "Bridge writes keep literal backslashes.\n";
