<?php
/** Automatic export delivers protocols and attempts only; questions need the explicit button. */
namespace WPOlympiad\Tests;

use WPOlympiad\Attempt;
use WPOlympiad\NetworkExport;
use WPOlympiad\Question;
use WPOlympiad\Quiz;
use WPOlympiad\RestApi;
use WP_REST_Request;

if (!defined('ABSPATH') || !isset($it)) { return; }

$scope_assert = static function (bool $ok, string $what): void { if (!$ok) throw new \RuntimeException($what); };
$scope_share = static function (array $extra = []): array {
    $root = sys_get_temp_dir() . '/wpo_auto_scope_' . wp_generate_password(12, false, false);
    wp_mkdir_p($root);
    $id = Quiz::create(['title' => 'Объём авто-выгрузки', 'status' => 'publish', 'settings' => array_merge([
        'max_attempts' => 0, 'enable_network_export' => 1, 'network_folder_type' => 'local',
        'network_folder_path' => $root, 'network_auto_export' => 1,
    ], $extra)]);
    Question::create(['quiz_id' => $id, 'question_type' => 'single', 'title' => 'Столица РФ?', 'points' => 5,
        'options' => ['choices' => [
            ['id' => 'o1', 'text' => 'Москва', 'is_correct' => 1],
            ['id' => 'o2', 'text' => 'Казань', 'is_correct' => 0],
        ]]]);
    return [$id, $root];
};
$scope_cleanup = static function (int $id, string $root): void {
    Quiz::delete($id);
    $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iter as $e) { if ($e->isDir() && !$e->isLink()) @rmdir($e->getPathname()); else @unlink($e->getPathname()); }
    @rmdir($root);
};

$it('Авто-выгрузка: пакет без папки вопросы/, протоколы и попытки сохраняются', function () use ($scope_assert, $scope_share, $scope_cleanup): bool {
    [$id, $root] = $scope_share();
    try {
        $quiz = Quiz::get($id);
        $with = NetworkExport::generate_export_files($quiz, true);
        $auto = NetworkExport::generate_export_files($quiz, false);
        $slug = NetworkExport::build_safe_slug($quiz);
        $scope_assert(isset($with['files']['протоколы/протокол_' . $slug . '.csv'], $auto['files']['протоколы/протокол_' . $slug . '.csv']), 'Основной протокол пропал');
        $q = array_values(array_filter(array_keys($with['files']), static fn(string $f): bool => str_starts_with($f, 'вопросы/')));
        $a = array_values(array_filter(array_keys($auto['files']), static fn(string $f): bool => str_starts_with($f, 'вопросы/')));
        $scope_assert(count($q) === 5 && $a === [], 'Авто-пакет всё ещё содержит файлы заданий');
        $scope_assert(($with['primary_filename'] ?? '') === ($auto['primary_filename'] ?? ''), 'Изменилось имя основного протокола');

        $att = Attempt::start($id, ['name' => 'Участник авто-выгрузки', 'email' => 'auto@example.invalid']);
        $att->complete(5, 5, true, 'completed');
        $res = NetworkExport::export_quiz($id, [], false);
        $scope_assert(!empty($res['success']) && ($res['questions_included'] ?? null) === false, 'Авто-выгрузка не прошла: ' . ($res['message'] ?? ''));
        $scope_assert(is_file($root . '/протоколы/протокол_' . $slug . '.csv'), 'Протокол не записан');
        $scope_assert(count(glob($root . '/попытки/попытка_*.csv')) === 1, 'Индивидуальная попытка не записана');
        $scope_assert(!is_dir($root . '/вопросы'), 'Авто-выгрузка создала папку вопросы');
        $scope_assert(str_contains((string)$res['message'], 'отдельная кнопка'), 'Сообщение не объясняет роль кнопки');
        $scope_assert(str_contains((string)file_get_contents($root . '/протоколы/протокол_' . $slug . '.csv'), 'Участник авто-выгрузки'), 'Протокол без данных участника');
        return true;
    } finally { $scope_cleanup($id, $root); }
});

$it('Авто-выгрузка: существующие файлы заданий сохраняются без изменений', function () use ($scope_assert, $scope_share, $scope_cleanup): bool {
    [$id, $root] = $scope_share();
    try {
        $quiz = Quiz::get($id);
        $slug = NetworkExport::build_safe_slug($quiz);
        wp_mkdir_p($root . '/вопросы');
        $kept = [
            'вопросы/вопросы_' . $slug . '.csv' => 'Мои актуальные задания',
            'вопросы/список_заданий.csv' => 'Мой список',
            'вопросы/questions.csv' => 'Старый экспорт, не удалять',
            'вопросы/проект.wpoq' => 'Черновик преподавателя',
        ];
        foreach ($kept as $rel => $bytes) file_put_contents($root . '/' . $rel, $bytes);
        $before = [];
        foreach (array_keys($kept) as $rel) { $before[$rel] = [hash_file('sha256', $root . '/' . $rel), filemtime($root . '/' . $rel)]; }
        clearstatcache();
        $stamp = time() - 700;
        foreach (array_keys($kept) as $rel) touch($root . '/' . $rel, $stamp);
        clearstatcache();

        $att = Attempt::start($id, ['name' => 'Первый', 'email' => 'one@example.invalid']);
        $att->complete(5, 5, true, 'completed');
        $res = NetworkExport::export_quiz($id, [], false);
        $scope_assert(!empty($res['success']), 'Авто-выгрузка не прошла: ' . ($res['message'] ?? ''));
        clearstatcache();
        foreach ($kept as $rel => $bytes) {
            $scope_assert(file_get_contents($root . '/' . $rel) === $bytes, 'Изменено содержимое: ' . $rel);
            $scope_assert((int)filemtime($root . '/' . $rel) === $stamp, 'Файл перезаписан: ' . $rel);
        }
        $scope_assert(($res['removed_legacy_files'] ?? []) === [], 'Устаревшие имена удалены без записи заданий');
        return true;
    } finally { $scope_cleanup($id, $root); }
});

$it('REST-сдача с авто-выгрузкой: только протоколы и попытки, вопросы не создаются', function () use ($scope_assert, $scope_share, $scope_cleanup): bool {
    [$id, $root] = $scope_share();
    try {
        $start = new WP_REST_Request('POST', '/wp-olympiad/v1/start');
        $start->set_param('quiz_id', $id);
        $start->set_param('participant_name', 'Участник REST');
        $start->set_param('participant_email', 'rest@example.invalid');
        $start->set_param('participant_org', 'Школа №1');
        $res = RestApi::handle_start($start);
        $scope_assert(!is_wp_error($res), 'Старт попытки не прошёл');
        $submit = new WP_REST_Request('POST', '/wp-olympiad/v1/submit');
        $submit->set_param('token', $res->get_data()['token'] ?? '');
        $submit->set_param('answers', []);
        $done = RestApi::handle_submit($submit);
        $scope_assert(!is_wp_error($done) && (int)$done->get_status() === 200, 'Сдача не прошла');
        $scope_assert(count(glob($root . '/протоколы/*.csv')) === 1, 'Протокол авто-выгрузкой не создан');
        $scope_assert(count(glob($root . '/попытки/*.csv')) === 1, 'Попытка авто-выгрузкой не создана');
        $scope_assert(!is_dir($root . '/вопросы'), 'REST-авто-выгрузка создала папку вопросов');
        $scope_assert(!is_dir($root . '/отчёты'), 'Создана папка отчётов');
        return true;
    } finally { $scope_cleanup($id, $root); }
});

$it('Ручная полная выгрузка и кнопка заданий: вопросы пишутся и чистятся как раньше', function () use ($scope_assert, $scope_share, $scope_cleanup): bool {
    [$id, $root] = $scope_share();
    try {
        $quiz = Quiz::get($id);
        $slug = NetworkExport::build_safe_slug($quiz);
        $att = Attempt::start($id, ['name' => 'Ручной', 'email' => 'manual@example.invalid']);
        $att->complete(5, 5, true, 'completed');
        wp_mkdir_p($root . '/вопросы');
        file_put_contents($root . '/вопросы/questions.csv', 'старый экспорт CSV');
        file_put_contents($root . '/вопросы/questions.json', 'старый экспорт JSON');
        $manual = NetworkExport::export_quiz($id);
        $scope_assert(!empty($manual['success']) && ($manual['questions_included'] ?? null) === true, 'Ручная выгрузка не включила задания');
        $scope_assert(is_array($manual['files_list']) && count($manual['files_list']) === 7, 'Неверный состав ручной выгрузки: ' . count((array)($manual['files_list'] ?? [])));
        $scope_assert(!file_exists($root . '/вопросы/questions.csv') && !file_exists($root . '/вопросы/questions.json'), 'Старые имена не убраны ручной выгрузкой');
        $scope_assert(is_file($root . '/вопросы/список_заданий.csv'), 'Ручная выгрузка не записала вопросы');
        $button = NetworkExport::export_questions_to_network($quiz, ['type' => 'local', 'path' => $root]);
        $scope_assert(!empty($button['success']) && $button['files_count'] === 5, 'Кнопка заданий не сработала: ' . ($button['message'] ?? ''));
        $scope_assert(is_file($root . '/вопросы/вопросы_' . $slug . '.csv'), 'Кнопка не записала полный CSV заданий');
        $package = NetworkExport::generate_export_package($quiz);
        $names = array_keys($package['files']);
        $scope_assert(in_array('вопросы/список_заданий.csv', $names, true), 'Скачиваемый пакет потерял вопросы');
        return true;
    } finally { $scope_cleanup($id, $root); }
});

$it('Авто-выгрузка: редактор в import и снятие старых отчётов работают и без заданий', function () use ($scope_assert, $scope_share, $scope_cleanup): bool {
    [$id, $root] = $scope_share();
    try {
        $slug = NetworkExport::build_safe_slug(Quiz::get($id));
        wp_mkdir_p($root . '/отчёты');
        file_put_contents($root . '/отчёты/манифест_выгрузки_' . $slug . '.txt', 'старый манифест');
        file_put_contents($root . '/отчёты/чужой-файл.txt', 'не удалять');
        $att = Attempt::start($id, ['name' => 'Отчёты', 'email' => 'reports@example.invalid']);
        $att->complete(5, 5, true, 'completed');
        $res = NetworkExport::export_quiz($id, [], false);
        $scope_assert(!empty($res['success']), 'Авто-выгрузка не прошла: ' . ($res['message'] ?? ''));
        $scope_assert(in_array('отчёты/манифест_выгрузки_' . $slug . '.txt', $res['removed_report_files'] ?? [], true), 'Старый манифест не убран');
        $scope_assert(file_get_contents($root . '/отчёты/чужой-файл.txt') === 'не удалять', 'Удалён чужой файл отчётов');
        $scope_assert($res['reports_directory'] === 'not_empty', 'Папка с чужими файлами не сохранена');
        $scope_assert(is_file($root . '/import/QuestionStudio.exe'), 'Редактор не размещён при авто-выгрузке');
        return true;
    } finally { $scope_cleanup($id, $root); }
});

$it('Настройки: подпись объясняет, что авто-выгрузка не трогает задания', function () use ($scope_assert): bool {
    $view = (string)file_get_contents(WP_OLYMPIAD_PATH . 'admin/views/quiz-edit.php');
    $rest = (string)file_get_contents(WP_OLYMPIAD_PATH . 'includes/class-rest-api.php');
    $export = (string)file_get_contents(WP_OLYMPIAD_PATH . 'includes/class-network-export.php');
    $scope_assert(str_contains($view, 'Автоматически выгружать файлы протоколов и попыток'), 'Подпись настройки не обновлена');
    $scope_assert(str_contains($view, 'для них предназначена кнопка «Экспортировать вопросы в сетевую папку»'), 'Нет указания на отдельную кнопку');
    $scope_assert(str_contains($rest, 'NetworkExport::export_quiz($quiz->id, [], false)'), 'REST-путь не передаёт ограничение объёма');
    $scope_assert(str_contains($export, 'public static function export_quiz(int $quiz_id, array $override_config = [], bool $include_questions = true)'), 'Сигнатура выгрузки не обновлена');
    $scope_assert(substr_count($export, 'QuestionExportCleanup::') === 4, 'Очистка имён вопросов не защищена флагом');
    $scope_assert(str_contains($view, "'questions_included', \$last_network_export") && str_contains($view, 'этой выгрузкой не создавались и не изменялись'), 'В карточке последней выгрузки нет пояснения про кнопку');
    return true;
});
