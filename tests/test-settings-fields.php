<?php
/** 1.0.6: removal of three admin fields without damaging legacy settings/links. */
namespace WPOlympiad\Tests;
use WPOlympiad\Quiz;
if (!defined('ABSPATH') || !isset($it)) return;
$fields_assert = static function (bool $ok, string $message): void { if (!$ok) throw new \RuntimeException($message); };

$it('Форма настроек: поля кастомного заголовка, обложки и slug удалены без скрытых дублей', function () use ($fields_assert): bool {
    if (!function_exists('submit_button')) require_once ABSPATH . 'wp-admin/includes/template.php';
    $id = Quiz::create(['title' => 'Legacy field visibility', 'slug' => 'legacy-fields-' . strtolower(wp_generate_password(8, false, false)), 'settings' => ['intro_title' => 'Старый заголовок', 'intro_banner_url' => 'https://example.invalid/legacy-banner.png']]);
    $render = static function ($quiz): string { ob_start(); require WP_OLYMPIAD_PATH . 'admin/views/quiz-edit.php'; return ob_get_clean(); };
    try {
        foreach ([null, Quiz::get($id)] as $quiz) {
            $html = $render($quiz); $previous = libxml_use_internal_errors(true); $doc = new \DOMDocument(); $doc->loadHTML('<?xml encoding="UTF-8">' . $html); libxml_clear_errors(); libxml_use_internal_errors($previous); $xp = new \DOMXPath($doc);
            foreach (['slug', 'intro_title', 'intro_banner_url'] as $name) $fields_assert($xp->query('//*[@name="' . $name . '"]')->length === 0, 'Retired input remains: ' . $name);
            foreach (['Кастомный заголовок на странице', 'Баннер / Обложка олимпиады (URL)', 'Ярлык (URL slug)'] as $label) $fields_assert(!str_contains($html, $label), 'Retired label remains');
            foreach (['title', 'intro_badge', 'intro_button_text'] as $name) $fields_assert($xp->query('//*[@name="' . $name . '"]')->length === 1, 'Unrelated title/intro input missing: ' . $name);
            $fields_assert($xp->query('//*[@id="wpo-rules-card"]//input[@name="allow_guest"]')->length === 1, 'Guest access control removed');
        }
        return true;
    } finally { Quiz::delete($id); }
});

$it('Форма настроек: старые slug/заголовок/обложка и ссылки сохраняются, новые slug уникальны автоматически', function () use ($fields_assert): bool {
    $slug = 'stable-old-link-' . strtolower(wp_generate_password(8, false, false));
    $id = Quiz::create(['title' => 'Original name', 'slug' => $slug, 'settings' => ['intro_title' => 'Existing public heading', 'intro_banner_url' => 'https://example.invalid/existing.jpg']]);
    $created = [$id];
    try {
        $before = Quiz::get($id)->get_direct_url();
        Quiz::get($id)->update(['title' => 'New ordinary name', 'settings' => ['time_limit' => 32]]); $after = Quiz::get($id);
        $fields_assert($after->slug === $slug && $after->get_direct_url() === $before, 'Existing link or identifier changed');
        $fields_assert($after->get_setting('intro_title') === 'Existing public heading' && $after->get_setting('intro_banner_url') === 'https://example.invalid/existing.jpg', 'Legacy intro values were reset');
        $title = 'Automatic slug ' . wp_generate_password(9, false, false);
        $a = Quiz::create(['title' => $title]); $created[] = $a; $b = Quiz::create(['title' => $title]); $created[] = $b;
        $qa = Quiz::get($a); $qb = Quiz::get($b);
        $fields_assert($qa->slug !== '' && $qb->slug !== '' && $qa->slug !== $qb->slug, 'Automatic slug is empty or not unique');
        return $qa->get_setting('intro_title') === '' && $qa->get_setting('intro_banner_url') === '';
    } finally { foreach ($created as $qid) Quiz::delete($qid); }
});
