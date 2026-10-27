<?php
/** 1.0.16: the questions export is gone - its leftovers are retired name by name. */
namespace WPOlympiad\Tests;

use WPOlympiad\NetworkExport;
use WPOlympiad\QuestionExportCleanup;
use WPOlympiad\Quiz;

if (!defined('ABSPATH') || !isset($it)) { return; }

$qrem_assert = static function (bool $ok, string $what): void { if (!$ok) throw new \RuntimeException($what); };
$qrem_share = static function (): array {
    $root = sys_get_temp_dir() . '/wpo_qremove_' . wp_generate_password(12, false, false);
    wp_mkdir_p($root);
    $id = Quiz::create(['title' => 'Questions retirement', 'status' => 'publish', 'settings' => [
        'max_attempts' => 0, 'enable_network_export' => 1, 'network_folder_type' => 'local',
        'network_folder_path' => $root,
    ]]);
    return [$id, $root];
};
$qrem_dispose = static function (int $id, string $root): void {
    Quiz::delete($id);
    $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iter as $e) { if ($e->isDir() && !$e->isLink()) @rmdir($e->getPathname()); else @unlink($e->getPathname()); }
    @rmdir($root);
};

$it('Снятие вопросов: точный список имён, без масок и выхода за папку', function () use ($qrem_assert): bool {
    $id = Quiz::create(['title' => 'Retirement name list', 'status' => 'publish']);
    try {
        $quiz = Quiz::get($id);
        $slug = NetworkExport::build_safe_slug($quiz);
        $names = QuestionExportCleanup::retired_names($quiz);
        $qrem_assert($names === [
            'вопросы_' . $slug . '.csv',
            'вопросы_' . $slug . '.json',
            'критерии_и_ответы_' . $slug . '.csv',
            'список_заданий.csv',
            'правильные_ответы.csv',
            'questions.csv',
            'questions.json',
        ], 'Список имён изменился: ' . json_encode($names, JSON_UNESCAPED_UNICODE));
        foreach ($names as $name) {
            $qrem_assert(!str_contains($name, '*') && !str_contains($name, '/') && !str_contains($name, '..') && !str_contains($name, '\\'), 'Недопустимое имя в списке: ' . $name);
        }
        return true;
    } finally { Quiz::delete($id); }
});

$it('Снятие вопросов: пустая папка убирается, чужие файлы и другая олимпиада остаются', function () use ($qrem_assert, $qrem_share, $qrem_dispose): bool {
    [$id, $root] = $qrem_share();
    try {
        $quiz = Quiz::get($id);
        wp_mkdir_p($root . '/вопросы');
        file_put_contents($root . '/вопросы/критерии_и_ответы_другая-олимпиада.csv', 'чужие критерии');
        $only = NetworkExport::export_quiz($id, ['type' => 'local', 'path' => $root]);
        $qrem_assert(!empty($only['success']) && ($only['questions_directory'] ?? '') === 'not_empty', 'Папка с чужим файлом не сохранена: ' . ($only['message'] ?? ''));
        $qrem_assert(($only['removed_question_files'] ?? []) === [], 'Удалено то, чего не было');
        $qrem_assert((string) ($only['questions_notice'] ?? '') === '', 'Нечего удалять - не о чём предупреждать');
        file_put_contents($root . '/вопросы/список_заданий.csv', 'мой список');
        file_put_contents($root . '/вопросы/правильные_ответы.csv', 'мои ответы');
        $again = NetworkExport::export_quiz($id, ['type' => 'local', 'path' => $root]);
        $qrem_assert(!empty($again['success']) && count($again['removed_question_files'] ?? []) === 2, 'Файлы списков не сняты: ' . json_encode($again['removed_question_files'] ?? []));
        $qrem_assert(file_get_contents($root . '/вопросы/критерии_и_ответы_другая-олимпиада.csv') === 'чужие критерии', 'Удалён файл другой олимпиады');
        $qrem_assert(($again['questions_directory'] ?? '') === 'not_empty', 'Папка удалена вместе с чужим файлом');
        unlink($root . '/вопросы/критерии_и_ответы_другая-олимпиада.csv');
        $last = NetworkExport::export_quiz($id, ['type' => 'local', 'path' => $root]);
        $qrem_assert(($last['questions_directory'] ?? '') === 'removed', 'Пустая папка не убрана: ' . json_encode($last['questions_directory'] ?? ''));
        $qrem_assert(str_contains((string) $last['questions_notice'], 'пуста и убрана'), 'Нет итога об удалении пустой папки: ' . (string) $last['questions_notice']);
        $qrem_assert(!is_dir($root . '/вопросы'), 'Пустая папка «вопросы/» осталась');
        $qrem_assert(count(glob($root . '/протоколы/*.csv')) === 1, 'Протокол выгрузки потерян');
        return true;
    } finally { $qrem_dispose($id, $root); }
});

$it('Снятие вопросов: каталог или ссылка вместо файла сохраняются, выгрузка успешна', function () use ($qrem_assert, $qrem_share, $qrem_dispose): bool {
    [$id, $root] = $qrem_share();
    $target = sys_get_temp_dir() . '/wpo_qrem_outside_' . wp_generate_password(8, false, false);
    wp_mkdir_p($target);
    try {
        $quiz = Quiz::get($id);
        $slug = NetworkExport::build_safe_slug($quiz);
        file_put_contents($target . '/оригинал.csv', 'внешний оригинал');
        wp_mkdir_p($root . '/вопросы');
        mkdir($root . '/вопросы/список_заданий.csv');
        if (function_exists('symlink')) {
            file_put_contents($target . '/мой.csv', 'данные вне хранилища');
            symlink($target . '/мой.csv', $root . '/вопросы/вопросы_' . $slug . '.csv');
        }
        $res = NetworkExport::export_quiz($id, ['type' => 'local', 'path' => $root]);
        $qrem_assert(!empty($res['success']), 'Выгрузка упала из-за конфликта в «вопросы/»: ' . ($res['message'] ?? ''));
        $qrem_assert(is_dir($root . '/вопросы/список_заданий.csv'), 'Каталог с именем файла удалён');
        $qrem_assert(file_get_contents($target . '/оригинал.csv') === 'внешний оригинал', 'Файл вне папки изменён');
        $qrem_assert(($res['removed_question_files'] ?? []) === [], 'Удалено то, что проверено как небезопасное');
        if (function_exists('symlink')) {
            $qrem_assert(is_link($root . '/вопросы/вопросы_' . $slug . '.csv'), 'Ссылка удалена, хотя в папке есть конфликтный каталог');
            $qrem_assert(file_get_contents($target . '/мой.csv') === 'данные вне хранилища', 'Внешний оригинал по ссылке изменён');
        }
        $qrem_assert(str_contains((string) $res['questions_notice'], 'не выполнена'), 'Нет итога очистки: ' . (string) $res['questions_notice']);
        return true;
    } finally { $qrem_dispose($id, $root); exec('rm -rf ' . escapeshellarg($target)); }
});

$it('Снятие вопросов: имя олимпиады без безопасного слага не даёт удалять лишнего', function () use ($qrem_assert): bool {
    $root = sys_get_temp_dir() . '/wpo_qremove_slug_' . wp_generate_password(10, false, false);
    wp_mkdir_p($root . '/вопросы');
    file_put_contents($root . '/вопросы/questions.csv', 'не удалять');
    $id = Quiz::create(['title' => '!!! ??? ...', 'status' => 'publish', 'settings' => [
        'max_attempts' => 0, 'enable_network_export' => 1, 'network_folder_type' => 'local', 'network_folder_path' => $root,
    ]]);
    try {
        $slug = NetworkExport::build_safe_slug(Quiz::get($id));
        $res = NetworkExport::export_quiz($id, ['type' => 'local', 'path' => $root]);
        $qrem_assert(!empty($res['success']), 'Выгрузка не прошла: ' . ($res['message'] ?? ''));
        $cleanup = QuestionExportCleanup::prune_after_export(['type' => 'local', 'path' => $root], Quiz::get($id));
        $qrem_assert($cleanup['success'] === true && $cleanup['removed'] === [], 'Непроверяемое имя что-то удалило');
        if (preg_match('/\A[a-zA-Z0-9_-]+\z/', $slug)) {
            $qrem_assert(($cleanup['directory'] ?? '') !== 'unverified', 'Проверяемое имя помечено непроверяемым');
        } else {
            $qrem_assert(($cleanup['directory'] ?? '') === 'unverified', 'Непроверяемое имя не остановило очистку');
            $qrem_assert(file_get_contents($root . '/вопросы/questions.csv') === 'не удалять', 'Удалено при недопустимом имени');
        }
        return true;
    } finally {
        Quiz::delete($id);
        exec('rm -rf ' . escapeshellarg($root));
    }
});

$it('Экспорт вопросов: URL скачивания закрыт 410, кнопка и вызов AJAX убраны из интерфейса', function () use ($qrem_assert): bool {
    $admin = (string) file_get_contents(WP_OLYMPIAD_PATH . 'admin/class-admin.php');
    $qrem_assert(str_contains($admin, "=== 'export_questions'") && str_contains($admin, "'response' => 410"), 'URL скачивания вопросов не закрыт кодом 410');
    $qrem_assert(!str_contains($admin, 'Export::export_quiz_questions_csv($quiz);'), 'Прямая отдача файлов вопросов осталась');
    $view = (string) file_get_contents(WP_OLYMPIAD_PATH . 'admin/views/quiz-edit.php');
    $js = (string) file_get_contents(WP_OLYMPIAD_PATH . 'admin/js/admin.js');
    $qrem_assert(!str_contains($view, 'Экспортировать вопросы в сетевую папку'), 'Кнопка выгрузки вопросов осталась в карточке');
    $qrem_assert(!str_contains($js, "action: 'wp_olympiad_export_questions_to_network'"), 'Отправка на AJAX-действие осталась в admin.js');
    $ajax = (string) file_get_contents(WP_OLYMPIAD_PATH . 'includes/class-ajax.php');
    $qrem_assert(str_contains($ajax, 'wp_olympiad_export_questions_to_network') && str_contains($ajax, '@deprecated 1.0.16'), 'AJAX-действие не помечено сохранённым для совместимости');
    $qrem_assert(str_contains((string) file_get_contents(WP_OLYMPIAD_PATH . 'includes/class-network-export.php'), 'Deprecated since 1.0.16 and ignored'), 'Флаг состава пакета не помечен устаревшим');
    return true;
});
