<?php
namespace WPOlympiad\Tests;
use WPOlympiad\Style;
use WPOlympiad\StyleThemes;
use WPOlympiad\Quiz;
if (!defined('ABSPATH') || !isset($it)) return;
$style_assert = static function (bool $ok, string $why): void { if (!$ok) throw new \RuntimeException($why); };
$style_backup = static function (): array { $old = get_option(StyleThemes::OPTION, null); delete_option(StyleThemes::OPTION); return [$old]; };
$style_restore = static function (array $old): void { delete_option(StyleThemes::OPTION); if ($old[0] !== null) add_option(StyleThemes::OPTION, $old[0], '', false); };

$it('Стилистика+: старые defaults неизменны, новые эффекты выключены, входы и диапазоны безопасны', function () use ($style_assert): bool {
    $d = Style::normalize([]); $style_assert($d['canvas_gradient'] === 'none' && $d['card_opacity'] === 100 && $d['card_blur'] === 0, 'Effects enabled for legacy styles');
    $s = Style::normalize(['canvas_gradient' => 'linear', 'canvas_gradient_angle' => 999, 'card_opacity' => -4, 'card_blur' => 999, 'primary' => ['bad'], 'font' => ['bad'], 'api_key' => 'not-a-style']);
    $style_assert($s['canvas_gradient_angle'] === 360 && $s['card_opacity'] === 40 && $s['card_blur'] === 24 && $s['primary'] === '#2563eb' && !isset($s['api_key']), 'Invalid values not contained');
    $legacy = Style::sanitize_post(['style_canvas_bg_enabled' => 1, 'style_canvas_bg' => '#123456'], ['canvas_gradient' => 'radial', 'card_opacity' => 73, 'card_blur' => 7]);
    $style_assert($legacy['canvas_gradient'] === 'radial' && $legacy['card_opacity'] === 73 && $legacy['card_blur'] === 7, 'Cached old form resets new effects');
    return Style::sanitize_image_url('//name:password@example.invalid/image.png') === '';
});

$it('Стилистика+: линейный/радиальный градиент за картинкой, стекло меняет фон без прозрачности текста', function () use ($style_assert): bool {
    $s = Style::normalize(['canvas_bg' => '#111111', 'canvas_gradient' => 'linear', 'canvas_gradient_angle' => 45, 'canvas_gradient_from' => '#ff0000', 'canvas_gradient_to' => '#0000ff', 'canvas_image' => 'https://example.invalid/picture.png', 'canvas_overlay' => 20, 'card_opacity' => 75, 'card_blur' => 12]);
    $decl = implode(';', Style::canvas_background_declarations($s, true));
    $style_assert(str_contains($decl, 'linear-gradient(45deg,#ff0000,#0000ff)') && strpos($decl, 'url(') < strpos($decl, 'linear-gradient(45deg'), 'Gradient covers the image instead of staying behind it');
    $q = new Quiz(['id' => 91, 'settings' => ['style' => $s]]); $css = Style::build_css($q);
    $style_assert(str_contains($css, 'rgba(255, 255, 255, 0.75)') && str_contains($css, 'backdrop-filter:blur(12px)') && !str_contains($css, 'opacity:0.75'), 'Glass fades text or is absent');
    $s['canvas_gradient'] = 'radial'; return str_contains(Style::canvas_gradient_css($s), 'radial-gradient(ellipse at center,');
});

$it('Стилистика+: JSON переносит только стили, не данные олимпиады; опасный CSS и неверные файлы отклоняются', function () use ($style_assert): bool {
    $data = StyleThemes::document(['primary' => '#112233', 'api_key' => 'secret', 'questions' => ['private']], '<b>My style</b>');
    $style_assert(array_keys($data) === ['format', 'version', 'name', 'style'] && $data['name'] === 'My style' && !isset($data['style']['api_key'], $data['style']['questions']), 'Export leaks metadata');
    $decoded = StyleThemes::parse_document(wp_json_encode($data)); $style_assert(!is_wp_error($decoded) && $decoded['style']['primary'] === '#112233', 'Valid JSON does not roundtrip');
    $style_assert(is_wp_error(StyleThemes::parse_document('{broken')) && is_wp_error(StyleThemes::parse_document(str_repeat('x', StyleThemes::JSON_LIMIT + 1))), 'Invalid/large JSON accepted');
    $data['version'] = 2; $style_assert(is_wp_error(StyleThemes::parse_document(wp_json_encode($data))), 'Unknown document version accepted');
    $css = Style::sanitize_css('@import url("https://example.invalid/unscoped.css"); .x {color:red}</style><script>alert(1)</script>');
    $scoped = Style::scope_css('#wpo-quiz-999 .x{color:red} #wpadminbar{color:red}', '#wpo-quiz-91');
    return !str_contains($css, '@import') && !str_contains($css, '<script') && !str_contains($scoped, '#wpo-quiz-999') && str_contains($scoped, '#wpo-quiz-91 #wpadminbar');
});

$it('Стилистика+: библиотека save/update/delete, лимит и сохранность настроек олимпиады', function () use ($style_assert, $style_backup, $style_restore): bool {
    $old = $style_backup(); $id = Quiz::create(['title' => 'Theme library isolation']); $before = Quiz::get($id)->settings;
    try {
        $one = StyleThemes::save_theme('Personal theme', ['primary' => '#123456']); $style_assert(!is_wp_error($one), 'Cannot save theme'); $key = $one['theme']['id'];
        $style_assert(is_wp_error(StyleThemes::save_theme('Personal theme', [])), 'Duplicate name silently replaces theme');
        $updated = StyleThemes::save_theme('Personal theme', ['primary' => '#654321'], $key); $style_assert($updated['theme']['style']['primary'] === '#654321', 'Selected theme not updated');
        for ($i = 1; $i < StyleThemes::LIMIT; $i++) { $r = StyleThemes::save_theme('Other theme ' . $i, []); $style_assert(!is_wp_error($r), 'Library filled too early'); }
        $style_assert(is_wp_error(StyleThemes::save_theme('Over limit', [])), 'Library is unbounded');
        $removed = StyleThemes::delete_theme($key); $style_assert(!is_wp_error($removed) && count($removed) === StyleThemes::LIMIT - 1, 'Delete theme failed');
        return Quiz::get($id)->settings === $before;
    } finally { Quiz::delete($id); $style_restore($old); }
});

$it('Стилистика+: CAS библиотеки сохраняет параллельную тему другого администратора', function () use ($style_assert, $style_backup, $style_restore): bool {
    global $wpdb; $old = $style_backup(); add_option(StyleThemes::OPTION, '{}', '', false); $fired = false; $inside = false;
    $race = static function ($sql) use (&$fired, &$inside, $wpdb) {
        if (!$fired && !$inside && str_starts_with($sql, "UPDATE {$wpdb->options} SET option_value=") && str_contains($sql, StyleThemes::OPTION)) {
            $inside = true; $other = ['theme_parallel' => ['id' => 'theme_parallel', 'name' => 'Parallel saved', 'style' => Style::normalize([]), 'updated_at' => current_time('mysql')]];
            $wpdb->update($wpdb->options, ['option_value' => wp_json_encode($other)], ['option_name' => StyleThemes::OPTION]); $inside = false; $fired = true;
        }
        return $sql;
    };
    try {
        add_filter('query', $race); $result = StyleThemes::save_theme('Current saved', []); remove_filter('query', $race);
        $style_assert($fired && !is_wp_error($result) && count($result['themes']) === 2, 'CAS lost parallel theme'); return true;
    } finally { remove_filter('query', $race); $style_restore($old); }
});

$it('Стилистика+: preview/JSON/library защищены cap и nonce, интерфейс содержит три устройства без скриптов в iframe', function () use ($style_assert): bool {
    $uid = get_current_user_id(); $post = $_POST; $request = $_REQUEST; $die = static function ($message = ''): void { if ($message !== '') echo $message; throw new \RuntimeException('style-test-stop'); }; $handler = static function () use ($die) { return $die; };
    add_filter('wp_die_ajax_handler', $handler, PHP_INT_MAX); add_filter('wp_die_handler', $handler, PHP_INT_MAX);
    $call = static function (array $data): string {
        $_POST = $data; $_REQUEST = $data; ob_start();
        try { StyleThemes::ajax(); } catch (\RuntimeException $e) { if ($e->getMessage() !== 'style-test-stop') { ob_end_clean(); throw $e; } }
        return ob_get_clean();
    };
    try {
        wp_set_current_user(0); $denied = json_decode($call(['op' => 'list']), true); $style_assert($denied['success'] === false, 'Anonymous library exposed');
        $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']); wp_set_current_user((int)$admins[0]);
        $style_assert(trim($call(['op' => 'list', 'nonce' => 'invalid'])) === '-1', 'Bad nonce accepted');
        $nonce = wp_create_nonce('wp_olympiad_admin_nonce'); $preview = json_decode($call(['op' => 'preview', 'nonce' => $nonce, 'fields' => wp_slash(wp_json_encode(['style_card_opacity' => 65, 'style_card_blur' => 9]))]), true);
        $style_assert($preview['success'] && str_contains($preview['data']['css'], '#wpo-style-preview') && str_contains($preview['data']['css'], 'blur(9px)'), 'Canonical protected preview failed');
        $view = (string)file_get_contents(WP_OLYMPIAD_PATH . 'admin/views/quiz-edit.php');
        return substr_count($view, 'data-wpo-preview-device=') === 3 && str_contains($view, 'sandbox="allow-same-origin"') && !str_contains($view, 'sandbox="allow-same-origin allow-scripts"');
    } finally { $_POST = $post; $_REQUEST = $request; wp_set_current_user($uid); remove_filter('wp_die_ajax_handler', $handler, PHP_INT_MAX); remove_filter('wp_die_handler', $handler, PHP_INT_MAX); }
});
