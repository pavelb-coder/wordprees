<?php
/** 1.0.8 constrained retirement of two legacy output filenames. */
namespace WPOlympiad\Tests;
use WPOlympiad\Quiz;
use WPOlympiad\NetworkExport;
use WPOlympiad\NetworkImport;
use WPOlympiad\QuestionExportCleanup;
if (!defined('ABSPATH') || !isset($it)) return;
$exports_assert = static function (bool $ok, string $why): void { if (!$ok) throw new \RuntimeException($why); };
$exports_fixture = static function (): array {
    $root = sys_get_temp_dir() . '/wpo_export_aliases_' . wp_generate_password(10, false, false); wp_mkdir_p($root . '/вопросы'); wp_mkdir_p($root . '/import');
    $id = Quiz::create(['title' => 'Localized export cleanup', 'status' => 'publish']);
    NetworkImport::import_to_quiz($id, NetworkImport::parse_questions_content(NetworkImport::get_template_csv(), 'owned.csv'), 'replace');
    return [$root, $id];
};
$exports_cleanup = static function (string $root, int $id): void { Quiz::delete($id); exec('rm -rf ' . escapeshellarg($root)); };

$it('Экспорт вопросов: только пять русских файлов, CSV/JSON полностью сохраняют 13 типов', function () use ($exports_assert, $exports_fixture, $exports_cleanup): bool {
    [$root, $id] = $exports_fixture();
    try {
        $quiz = Quiz::get($id); $result = NetworkExport::export_questions_to_network($quiz, ['type' => 'local', 'path' => $root]);
        $exports_assert(!empty($result['success']) && $result['files_count'] === 5, 'Wrong question export manifest');
        $exports_assert(!in_array('вопросы/questions.csv', $result['files'], true) && !in_array('вопросы/questions.json', $result['files'], true), 'English aliases still generated');
        $csv = glob($root . '/вопросы/вопросы_*.csv'); $json = glob($root . '/вопросы/вопросы_*.json');
        $exports_assert(count($csv) === 1 && count($json) === 1, 'Localized lossless files missing');
        $exports_assert(count(NetworkImport::parse_questions_content(file_get_contents($csv[0]), basename($csv[0]))) === 13 && count(NetworkImport::parse_questions_content(file_get_contents($json[0]), basename($json[0]))) === 13, 'Localized export lost question data');
        $package = NetworkExport::generate_export_files($quiz);
        $question_files = array_values(array_filter(array_keys($package['files']), static fn($f) => str_starts_with($f, 'вопросы/')));
        sort($question_files); $manual = $result['files']; sort($manual);
        return $question_files === $manual;
    } finally { $exports_cleanup($root, $id); }
});

$it('Экспорт вопросов: старые два файла удаляются после manual/full, import и посторонние файлы не затронуты', function () use ($exports_assert, $exports_fixture, $exports_cleanup): bool {
    [$root, $id] = $exports_fixture();
    $keep = ['import/questions.csv' => 'CSV учителя', 'import/questions.json' => 'JSON учителя', 'questions.csv' => 'В корне', 'questions.json' => 'В корне JSON', 'вопросы/проект.wpoq' => 'Черновик', 'вопросы/другой.csv' => 'Чужой файл'];
    try {
        foreach ($keep as $name => $value) file_put_contents($root . '/' . $name, $value);
        foreach (['manual', 'full'] as $mode) {
            file_put_contents($root . '/вопросы/questions.csv', 'старый экспорт CSV'); file_put_contents($root . '/вопросы/questions.json', 'старый экспорт JSON');
            $config = ['type' => 'local', 'path' => $root];
            $result = $mode === 'manual' ? NetworkExport::export_questions_to_network(Quiz::get($id), $config) : NetworkExport::export_quiz($id, $config);
            $exports_assert(!empty($result['success']) && count($result['removed_legacy_files']) === 2, 'Old aliases not retired after ' . $mode);
            $exports_assert(!file_exists($root . '/вопросы/questions.csv') && !file_exists($root . '/вопросы/questions.json'), 'Old aliases remain');
            foreach ($keep as $name => $value) $exports_assert(file_get_contents($root . '/' . $name) === $value, 'Unrelated file changed: ' . $name);
        }
        $again = NetworkExport::export_quiz($id, ['type' => 'local', 'path' => $root]);
        return !empty($again['success']) && $again['removed_legacy_files'] === [];
    } finally { $exports_cleanup($root, $id); }
});

$it('Экспорт вопросов: ссылка на каталог не обходится, файл-ссылка удаляется без удаления внешнего оригинала', function () use ($exports_assert, $exports_fixture, $exports_cleanup): bool {
    if (!function_exists('symlink')) return true;
    [$root, $id] = $exports_fixture(); $external = $root . '-external'; wp_mkdir_p($external);
    try {
        file_put_contents($external . '/questions.csv', 'внешний оригинал');
        rmdir($root . '/вопросы'); symlink($external, $root . '/вопросы');
        $blocked = NetworkExport::export_questions_to_network(Quiz::get($id), ['type' => 'local', 'path' => $root]);
        $exports_assert(empty($blocked['success']) && file_get_contents($external . '/questions.csv') === 'внешний оригинал', 'Directory symlink escaped scope');
        unlink($root . '/вопросы'); mkdir($root . '/вопросы'); symlink($external . '/questions.csv', $root . '/вопросы/questions.csv');
        $ok = NetworkExport::export_questions_to_network(Quiz::get($id), ['type' => 'local', 'path' => $root]);
        return !empty($ok['success']) && !is_link($root . '/вопросы/questions.csv') && file_get_contents($external . '/questions.csv') === 'внешний оригинал';
    } finally { $exports_cleanup($root, $id); exec('rm -rf ' . escapeshellarg($external)); }
});

$it('Экспорт вопросов: ошибка записи замены не удаляет старые данные, каталог с именем файла не удаляется', function () use ($exports_assert, $exports_fixture, $exports_cleanup): bool {
    [$root, $id] = $exports_fixture();
    try {
        $config = ['type' => 'local', 'path' => $root];
        file_put_contents($root . '/вопросы/questions.csv', 'retain old');
        $quiz = Quiz::get($id); $package = NetworkExport::generate_export_files($quiz);
        $localized = current(array_filter(array_keys($package['files']), static fn($f) => str_starts_with($f, 'вопросы/вопросы_') && str_ends_with($f, '.json')));
        mkdir($root . '/' . $localized);
        $failed = NetworkExport::export_questions_to_network($quiz, $config);
        $exports_assert(empty($failed['success']) && file_get_contents($root . '/вопросы/questions.csv') === 'retain old', 'Old data deleted before replacement success');
        rmdir($root . '/' . $localized); mkdir($root . '/вопросы/questions.json'); file_put_contents($root . '/вопросы/questions.json/inside.txt', 'Не трогать');
        $failed = NetworkExport::export_questions_to_network($quiz, $config);
        return empty($failed['success']) && file_get_contents($root . '/вопросы/questions.json/inside.txt') === 'Не трогать';
    } finally { $exports_cleanup($root, $id); }
});

$it('Экспорт вопросов / WebDAV: два точных DELETE только после всех PUT, без редиректов/масок', function () use ($exports_assert): bool {
    $id = Quiz::create(['title' => 'DAV exact cleanup']); $calls = []; $put_fail = false;
    $mock = static function ($pre, $args, $url) use (&$calls, &$put_fail) {
        if (!str_contains($url, 'export-cleanup.invalid')) return $pre;
        $calls[] = ['method' => $args['method'], 'url' => $url, 'args' => $args];
        $code = $args['method'] === 'DELETE' ? 204 : ($args['method'] === 'MKCOL' ? 405 : (($put_fail && str_ends_with($url, '.json')) ? 507 : 201));
        return ['headers' => [], 'body' => '', 'response' => ['code' => $code, 'message' => 'QA'], 'cookies' => []];
    };
    try {
        add_filter('pre_http_request', $mock, 10, 3); $config = ['type' => 'webdav', 'path' => 'https://export-cleanup.invalid/share', 'user' => 'teacher', 'password' => 'fixture-only'];
        $result = NetworkExport::export_questions_to_network(Quiz::get($id), $config);
        $deletes = array_values(array_filter($calls, static fn($c) => $c['method'] === 'DELETE'));
        $expected = array_map(static fn($name) => 'https://export-cleanup.invalid/share/' . rawurlencode('вопросы') . '/' . $name, ['questions.csv', 'questions.json']);
        $exports_assert(!empty($result['success']) && array_column($deletes, 'url') === $expected && $deletes[0]['args']['redirection'] === 0, 'DELETE scope or redirect policy wrong');
        $first_delete = array_search('DELETE', array_column($calls, 'method'), true); $puts_before = array_filter(array_slice($calls, 0, $first_delete), static fn($c) => $c['method'] === 'PUT');
        $exports_assert(count($puts_before) === 5, 'Cleanup ran before full replacement delivery');
        $calls = []; $put_fail = true; $failed = NetworkExport::export_questions_to_network(Quiz::get($id), $config);
        return empty($failed['success']) && !in_array('DELETE', array_column($calls, 'method'), true);
    } finally { remove_filter('pre_http_request', $mock, 10); Quiz::delete($id); }
});

$it('Экспорт вопросов / WebDAV: отсутствие допустимо, отказ/асинхронный ответ не выдаются за удаление', function () use ($exports_assert): bool {
    $id = Quiz::create(['title' => 'DAV cleanup errors']); $delete_code = 404;
    $mock = static function ($pre, $args, $url) use (&$delete_code) {
        if (!str_contains($url, 'export-cleanup-error.invalid')) return $pre;
        $code = $args['method'] === 'DELETE' ? $delete_code : ($args['method'] === 'MKCOL' ? 405 : 201);
        return ['headers' => [], 'body' => '', 'response' => ['code' => $code, 'message' => 'QA'], 'cookies' => []];
    };
    try {
        add_filter('pre_http_request', $mock, 10, 3); $config = ['type' => 'webdav', 'path' => 'https://export-cleanup-error.invalid/share'];
        $ok = NetworkExport::export_questions_to_network(Quiz::get($id), $config); $exports_assert(!empty($ok['success']), 'Missing aliases not idempotent');
        foreach ([403, 202, 301] as $code) {
            $delete_code = $code; $bad = NetworkExport::export_questions_to_network(Quiz::get($id), $config);
            $exports_assert(empty($bad['success']) && str_contains($bad['message'], (string)$code), 'Failed deletion reported success');
        }
        return true;
    } finally { remove_filter('pre_http_request', $mock, 10); Quiz::delete($id); }
});
