<?php
/** Export scope: protocols and attempts only; the questions export was removed in 1.0.16. */
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
        // Флаг $include_questions сохранён для совместимости и больше ни на что не влияет
        $legacy_call = NetworkExport::generate_export_files($quiz, true);
        $auto = NetworkExport::generate_export_files($quiz);
        $slug = NetworkExport::build_safe_slug($quiz);
        $scope_assert(isset($auto['files']['протоколы/протокол_' . $slug . '.csv']), 'Основной протокол пропал');
        $q = array_values(array_filter(array_keys($legacy_call['files']), static fn(string $f): bool => str_starts_with($f, 'вопросы/')));
        $a = array_values(array_filter(array_keys($auto['files']), static fn(string $f): bool => str_starts_with($f, 'вопросы/')));
        $scope_assert($q === [] && $a === [], 'Пакет выгрузки всё ещё содержит файлы заданий');
        $scope_assert(array_keys($legacy_call['files']) === array_keys($auto['files']), 'Устаревший флаг меняет состав пакета');
        $scope_assert(($legacy_call['primary_filename'] ?? '') === ($auto['primary_filename'] ?? ''), 'Изменилось имя основного протокола');

        $att = Attempt::start($id, ['name' => 'Участник авто-выгрузки', 'email' => 'auto@example.invalid']);
        $att->complete(5, 5, true, 'completed');
        $res = NetworkExport::export_quiz($id);
        $scope_assert(!empty($res['success']) && ($res['questions_included'] ?? null) === false, 'Авто-выгрузка не прошла: ' . ($res['message'] ?? ''));
        $scope_assert(is_file($root . '/протоколы/протокол_' . $slug . '.csv'), 'Протокол не записан');
        $scope_assert(count(glob($root . '/попытки/попытка_*.csv')) === 1, 'Индивидуальная попытка не записана');
        $scope_assert(!is_dir($root . '/вопросы'), 'Авто-выгрузка создала папку вопросы');
        $scope_assert(count($res['files_list']) === 2, 'Неверный состав авто-выгрузки: ' . count((array) ($res['files_list'] ?? [])));
        $scope_assert(str_contains((string)$res['message'], 'экспорт вопросов удалён'), 'Сообщение не объясняет удаление экспорта вопросов');
        $scope_assert(str_contains((string)file_get_contents($root . '/протоколы/протокол_' . $slug . '.csv'), 'Участник авто-выгрузки'), 'Протокол без данных участника');
        return true;
    } finally { $scope_cleanup($id, $root); }
});

$it('Авто-выгрузка: старые файлы заданий снимаются точечно, чужие сохраняются', function () use ($scope_assert, $scope_share, $scope_cleanup): bool {
    [$id, $root] = $scope_share();
    try {
        $slug = NetworkExport::build_safe_slug(Quiz::get($id));
        $att = Attempt::start($id, ['name' => 'Участник снятия', 'email' => 'retire@example.invalid']);
        $att->complete(5, 5, true, 'completed');
        wp_mkdir_p($root . '/вопросы');
        $owned = [
            'вопросы/вопросы_' . $slug . '.csv' => 'Мои актуальные задания',
            'вопросы/список_заданий.csv' => 'Мой список',
            'вопросы/questions.csv' => 'Старый экспорт, тоже снимается',
        ];
        $keep = [
            'вопросы/проект.wpoq' => 'Черновик преподавателя',
            'вопросы/критерии_и_ответы_другой-олимпиады.csv' => 'Другая олимпиада',
        ];
        foreach ($owned as $rel => $bytes) file_put_contents($root . '/' . $rel, $bytes);
        foreach ($keep as $rel => $bytes) file_put_contents($root . '/' . $rel, $bytes);

        $res = NetworkExport::export_quiz($id);
        $scope_assert(!empty($res['success']), 'Авто-выгрузка не прошла: ' . ($res['message'] ?? ''));
        $removed = array_values((array) ($res['removed_question_files'] ?? []));
        sort($removed);
        $scope_assert($removed === ['вопросы/questions.csv', 'вопросы/вопросы_' . $slug . '.csv', 'вопросы/список_заданий.csv'], 'Сняты не те файлы: ' . json_encode($removed, JSON_UNESCAPED_UNICODE));
        foreach ($owned as $rel => $bytes) $scope_assert(!file_exists($root . '/' . $rel), 'Файл выгрузки остался: ' . $rel);
        foreach ($keep as $rel => $bytes) $scope_assert(file_get_contents($root . '/' . $rel) === $bytes, 'Изменён чужой файл: ' . $rel);
        $scope_assert(($res['questions_directory'] ?? '') === 'not_empty', 'Папка с чужими файлами не сохранена');
        $scope_assert(str_contains((string) $res['questions_notice'], 'удалены (3)'), 'Нет итога снятия: ' . (string) $res['questions_notice']);
        $scope_assert(is_file($root . '/протоколы/протокол_' . $slug . '.csv'), 'Протокол выгрузки потерян');
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

$it('Ручная выгрузка и ZIP: заданий нет, сохранённый API по-прежнему пишет пять файлов', function () use ($scope_assert, $scope_share, $scope_cleanup): bool {
    [$id, $root] = $scope_share();
    try {
        $quiz = Quiz::get($id);
        $slug = NetworkExport::build_safe_slug($quiz);
        $att = Attempt::start($id, ['name' => 'Ручной', 'email' => 'manual@example.invalid']);
        $att->complete(5, 5, true, 'completed');
        wp_mkdir_p($root . '/вопросы');
        file_put_contents($root . '/вопросы/questions.csv', 'старый экспорт CSV');
        file_put_contents($root . '/вопросы/questions.json', 'старый экспорт JSON');
        $manual = NetworkExport::export_quiz($id, ['type' => 'local', 'path' => $root]);
        $scope_assert(!empty($manual['success']) && ($manual['questions_included'] ?? null) === false, 'Ручная выгрузка не прошла: ' . ($manual['message'] ?? ''));
        $scope_assert(is_array($manual['files_list']) && count($manual['files_list']) === 2, 'Неверный состав ручной выгрузки: ' . count((array) ($manual['files_list'] ?? [])));
        $scope_assert($manual['removed_legacy_files'] === ['вопросы/questions.csv', 'вопросы/questions.json'], 'Устаревшие имена не убраны выгрузкой');
        $scope_assert(!is_dir($root . '/вопросы'), 'Пустая папка «вопросы/» не убрана');
        $package = NetworkExport::generate_export_package($quiz);
        $names = array_keys($package['files']);
        $scope_assert(!array_filter($names, static fn(string $f): bool => str_starts_with($f, 'вопросы/')), 'Скачиваемый пакет всё ещё содержит задания');
        $scope_assert(in_array('протоколы/протокол_' . $slug . '.csv', $names, true), 'В пакете нет основного протокола');
        $button = NetworkExport::export_questions_to_network($quiz, ['type' => 'local', 'path' => $root]);
        $scope_assert(!empty($button['success']) && $button['files_count'] === 5, 'Сохранённый метод заданий не работает: ' . ($button['message'] ?? ''));
        $scope_assert(is_file($root . '/вопросы/вопросы_' . $slug . '.csv'), 'Метод не записал полный CSV заданий');
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

$it('Интерфейс: кнопки выгрузки вопросов нет, подпись объясняет снятие старых файлов', function () use ($scope_assert): bool {
    $view = (string)file_get_contents(WP_OLYMPIAD_PATH . 'admin/views/quiz-edit.php');
    $js = (string)file_get_contents(WP_OLYMPIAD_PATH . 'admin/js/admin.js');
    $rest = (string)file_get_contents(WP_OLYMPIAD_PATH . 'includes/class-rest-api.php');
    $export = (string)file_get_contents(WP_OLYMPIAD_PATH . 'includes/class-network-export.php');
    $scope_assert(str_contains($view, 'Автоматически выгружать файлы протоколов и попыток'), 'Подпись настройки не обновлена');
    $scope_assert(str_contains($view, 'экспорт вопросов удалён'), 'В описании настройки нет ссылки на удалённый экспорт');
    $scope_assert(!str_contains($view, 'wpo-btn-export-questions-network'), 'Кнопка выгрузки вопросов осталась в интерфейсе');
    $scope_assert(!str_contains($js, 'wpo-btn-export-questions-network'), 'Обработчик кнопки остался в admin.js');
    $scope_assert(str_contains($view, "'questions_notice'"), 'В карточке последней выгрузки нет итога снятия файлов');
    $scope_assert(str_contains($rest, 'NetworkExport::export_quiz($quiz->id)'), 'REST-путь передаёт устаревший флаг объёма');
    $scope_assert(str_contains($export, 'public static function export_quiz(int $quiz_id, array $override_config = [], bool $include_questions = false)'), 'Сигнатура выгрузки не обновлена');
    $scope_assert(substr_count($export, 'QuestionExportCleanup::') === 3, 'Неожиданное число обращений к очистке вопросов');
    $scope_assert(str_contains($export, '@deprecated 1.0.16'), 'У сохранённого метода нет пометки об удалении');
    return true;
});
