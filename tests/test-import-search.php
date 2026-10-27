<?php
/** Regression tests for import-first discovery. Included by test-suite.php. */
namespace WPOlympiad\Tests;

use WPOlympiad\NetworkImport;
use WPOlympiad\Quiz;

if (!defined('ABSPATH') || !isset($it)) { return; }

$search_assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new \RuntimeException($message);
};
$search_csv = static fn(string $title): string => "type;title;points;choices;correct\nsingle;{$title};1;Да|Нет;1\n";
$search_root = static function (): string {
    $root = sys_get_temp_dir() . '/wpo_import_search_' . wp_generate_password(12, false, false);
    wp_mkdir_p($root . '/import');
    return $root;
};
$search_cleanup = static function (string $root): void {
    if (is_dir($root)) {
        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iter as $entry) {
            if ($entry->isDir() && !$entry->isLink()) @rmdir($entry->getPathname());
            else @unlink($entry->getPathname());
        }
        @rmdir($root);
    }
};

$it('Поиск заданий: import раньше корня, единый приоритет для проверки и автоимпорта', function () use ($search_assert, $search_csv, $search_root, $search_cleanup): bool {
    $root = $search_root();
    $id = Quiz::create(['title' => 'Приоритет поиска import']);
    $cfg = ['type' => 'local', 'path' => $root];
    try {
        file_put_contents($root . '/questions.csv', $search_csv('Вопрос из корня'));
        file_put_contents($root . '/import/учитель.csv', $search_csv('Рабочий файл import'));
        file_put_contents($root . '/import/QuestionStudio.exe', 'not a question');
        file_put_contents($root . '/import/question-studio-source-1.0.0.zip', 'not a question');
        file_put_contents($root . '/import/README_IMPORT.txt', 'not a question');
        file_put_contents($root . '/import/~$temporary.xlsx', 'not a question');
        $error = null;
        $files = NetworkImport::scan_network_folder_for_import_files(Quiz::get($id), $cfg, $error);
        $search_assert($error === null && array_column($files, 'path') === ['import/учитель.csv'], 'Не выбран файл из import');
        $probe = NetworkImport::analyze_import_file(Quiz::get($id), '', $cfg);
        $search_assert(!empty($probe['success']) && $probe['source'] === 'import/учитель.csv', 'Проверка выбрала другой файл');
        $res = NetworkImport::auto_import_for_quiz(Quiz::get($id), true, $cfg, 'replace', '', true, false);
        $search_assert(!empty($res['success']) && $res['filename'] === 'import/учитель.csv', 'Автоимпорт выбрал другой файл');
        $search_assert(Quiz::get($id)->get_questions(false)[0]->title === 'Рабочий файл import', 'Импортировано неверное содержимое');

        file_put_contents($root . '/import/questions.csv', $search_csv('Стандартное имя'));
        file_put_contents($root . '/import/import.csv', $search_csv('Запасное имя'));
        file_put_contents($root . '/import/questions_template.csv', $search_csv('Образец'));
        $files = NetworkImport::scan_network_folder_for_import_files(Quiz::get($id), $cfg, $error);
        $search_assert(array_column($files, 'path') === ['import/questions.csv', 'import/import.csv', 'import/учитель.csv', 'import/questions_template.csv'], 'Неверный порядок имён');
        $probe = NetworkImport::analyze_import_file(Quiz::get($id), '', $cfg);
        $res = NetworkImport::auto_import_for_quiz(Quiz::get($id), true, $cfg, 'replace', '', true, false);
        $search_assert(($probe['source'] ?? '') === 'import/questions.csv' && ($res['filename'] ?? '') === 'import/questions.csv', 'Проверка и импорт не соблюдают приоритет questions.csv');

        file_put_contents($root . '/import/questions.csv', 'not a question file');
        $probe = NetworkImport::analyze_import_file(Quiz::get($id), '', $cfg);
        $res = NetworkImport::auto_import_for_quiz(Quiz::get($id), true, $cfg, 'replace', '', true, false);
        $search_assert(($probe['source'] ?? '') === 'import/import.csv' && ($res['filename'] ?? '') === 'import/import.csv', 'После неподходящего файла не проверен следующий');
        foreach (['import.csv', 'учитель.csv', 'questions_template.csv'] as $name) file_put_contents($root . '/import/' . $name, 'bad');
        $before = Quiz::get($id)->get_questions(false)[0]->title;
        $res = NetworkImport::auto_import_for_quiz(Quiz::get($id), true, $cfg, 'replace', '', true, false);
        $search_assert(empty($res['success']) && Quiz::get($id)->get_questions(false)[0]->title === $before, 'Неверные файлы import не должны заменять вопросы из корня');
        return true;
    } finally { Quiz::delete($id); $search_cleanup($root); }
});

$it('Поиск заданий: резервный корень и вложенные каталоги только если import пуст', function () use ($search_assert, $search_csv, $search_root, $search_cleanup): bool {
    $root = $search_root();
    $id = Quiz::create(['title' => 'Резервный поиск']);
    try {
        $cfg = ['type' => 'local', 'path' => $root];
        wp_mkdir_p($root . '/Задания/2026');
        file_put_contents($root . '/Задания/2026/тест.csv', $search_csv('Вложенный файл'));
        file_put_contents($root . '/import/QuestionStudio.exe', 'editor');
        file_put_contents($root . '/import/source.zip', 'source');
        $error = null;
        $files = NetworkImport::scan_network_folder_for_import_files(Quiz::get($id), $cfg, $error);
        $search_assert($error === null && array_column($files, 'path') === ['Задания/2026/тест.csv'], 'Не найден вложенный резервный файл');
        $res = NetworkImport::auto_import_for_quiz(Quiz::get($id), true, $cfg, 'replace', '', true, false);
        $search_assert(!empty($res['success']) && ($res['filename'] ?? '') === 'Задания/2026/тест.csv', 'Резервный автоимпорт не сработал');
        unlink($root . '/import/QuestionStudio.exe'); unlink($root . '/import/source.zip'); rmdir($root . '/import');
        file_put_contents($root . '/questions.csv', $search_csv('Файл в корне'));
        $files = NetworkImport::scan_network_folder_for_import_files(Quiz::get($id), $cfg, $error);
        $search_assert($error === null && ($files[0]['path'] ?? '') === 'questions.csv', 'Отсутствующий import не включает резервный поиск');
        return true;
    } finally { Quiz::delete($id); $search_cleanup($root); }
});

$it('Поиск заданий: путь уже указывает на import, без дублирования import/import', function () use ($search_assert, $search_csv, $search_root, $search_cleanup): bool {
    $root = $search_root();
    $id = Quiz::create(['title' => 'Прямой путь в import']);
    try {
        file_put_contents($root . '/import/Мои вопросы.csv', $search_csv('Прямое подключение'));
        $cfg = ['type' => 'filesystem', 'path' => $root . '/import/'];
        $error = null;
        $files = NetworkImport::scan_network_folder_for_import_files(Quiz::get($id), $cfg, $error);
        $search_assert($error === null && array_column($files, 'path') === ['Мои вопросы.csv'], 'Создан неверный относительный путь');
        $res = NetworkImport::auto_import_for_quiz(Quiz::get($id), true, $cfg, 'replace', '', true, false);
        $search_assert(!empty($res['success']) && $res['filename'] === 'Мои вопросы.csv', 'Не прочитан файл из подключённой import');
        $search_assert(!file_exists($root . '/import/import'), 'Создана лишняя import/import');
        return true;
    } finally { Quiz::delete($id); $search_cleanup($root); }
});

$it('Поиск заданий: явный относительный путь не подменяется одноимённым файлом', function () use ($search_assert, $search_csv, $search_root, $search_cleanup): bool {
    $root = $search_root();
    $id = Quiz::create(['title' => 'Явный путь']);
    try {
        $cfg = ['type' => 'local', 'path' => $root];
        file_put_contents($root . '/same.csv', $search_csv('Корневой файл'));
        file_put_contents($root . '/import/другой.csv', $search_csv('Другой источник'));
        $error = null;
        $content = NetworkImport::read_network_file(Quiz::get($id), 'import/same.csv', $cfg, $error);
        $search_assert($content === null && $error !== null, 'Отсутствующий import/same.csv подменён корневым');
        $res = NetworkImport::auto_import_for_quiz(Quiz::get($id), true, $cfg, 'replace', 'same.csv', true, false);
        $search_assert(!empty($res['success']) && Quiz::get($id)->get_questions(false)[0]->title === 'Корневой файл', 'Явно выбранный корневой файл не работает');
        file_put_contents($root . '/import/same.csv', $search_csv('Тот же файл в import'));
        $probe = NetworkImport::analyze_import_file(Quiz::get($id), 'same.csv', $cfg);
        $res = NetworkImport::auto_import_for_quiz(Quiz::get($id), true, $cfg, 'replace', 'same.csv', true, false);
        $search_assert(($probe['source'] ?? '') === 'import/same.csv' && ($res['filename'] ?? '') === 'import/same.csv', 'Простое имя не нашлось сначала в import');
        $res = NetworkImport::auto_import_for_quiz(Quiz::get($id), true, $cfg, 'replace', './same.csv', true, false);
        $search_assert(!empty($res['success']) && Quiz::get($id)->get_questions(false)[0]->title === 'Корневой файл', 'Явный ./ путь не выбрал корневой файл');
        file_put_contents($root . '/import/UPPER.csv', $search_csv('Регистр'));
        $error = null;
        $content = NetworkImport::read_network_file(Quiz::get($id), 'import/upper.csv', $cfg, $error);
        $search_assert($content !== null && str_contains($content, 'Регистр'), 'Поиск имени без учёта регистра сломан');
        foreach (['../same.csv', '%2e%2e/same.csv'] as $path) {
            $error = null;
            $search_assert(NetworkImport::read_network_file(Quiz::get($id), $path, $cfg, $error) === null && $error !== null, 'Разрешён выход за пределы хранилища');
        }
        return true;
    } finally { Quiz::delete($id); $search_cleanup($root); }
});

$it('Поиск заданий: недоступная import — ошибка, а не незаметный импорт из корня', function () use ($search_assert, $search_csv, $search_root, $search_cleanup): bool {
    $root = $search_root();
    $id = Quiz::create(['title' => 'Ошибки поиска']);
    try {
        rmdir($root . '/import');
        file_put_contents($root . '/import', 'not a directory');
        file_put_contents($root . '/questions.csv', $search_csv('Не импортировать'));
        $cfg = ['type' => 'local', 'path' => $root];
        $error = null;
        $files = NetworkImport::scan_network_folder_for_import_files(Quiz::get($id), $cfg, $error);
        $search_assert($files === [] && $error !== null, 'Недоступная import ошибочно сочтена пустой');
        $res = NetworkImport::auto_import_for_quiz(Quiz::get($id), true, $cfg, 'replace', '', true, false);
        $search_assert(empty($res['success']) && count(Quiz::get($id)->get_questions(false)) === 0, 'Ошибка сканирования привела к импорту из корня');
        $res = NetworkImport::auto_import_for_quiz(Quiz::get($id), true, $cfg, 'replace', 'questions.csv', true, false);
        $search_assert(empty($res['success']) && count(Quiz::get($id)->get_questions(false)) === 0, 'При ошибке import простое имя выбрало корень');
        $res = NetworkImport::auto_import_for_quiz(Quiz::get($id), true, $cfg, 'replace', './questions.csv', true, false);
        $search_assert(!empty($res['success']) && ($res['filename'] ?? '') === './questions.csv', 'Явный путь не работает при ошибке сканирования');
        return true;
    } finally { Quiz::delete($id); $search_cleanup($root); }
});

$it('WebDAV: import-first, кириллица, спецсимволы, относительные пути и реальный GET', function () use ($search_assert, $search_csv): bool {
    $id = Quiz::create(['title' => 'WebDAV поиск']);
    $name = 'Мой + % файл.csv';
    $base = 'https://import-search.invalid/share%20name';
    $cfg = ['type' => 'webdav', 'path' => $base, 'user' => 'teacher', 'password' => 'fixture-password'];
    $calls = [];
    $mock = static function ($pre, $args, $url) use (&$calls, $base, $name, $search_csv) {
        if (!str_starts_with($url, $base)) return $pre;
        $method = (string)($args['method'] ?? 'GET');
        $calls[] = [$method, $url, $args['headers']['Authorization'] ?? ''];
        if ($method === 'PROPFIND' && $url === $base . '/import/') {
            $href = '/share%20name/import/' . rawurlencode($name);
            $body = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:">'
                . '<d:response><d:href>/share%20name/import/</d:href><d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop></d:propstat></d:response>'
                . '<d:response><d:href>' . htmlspecialchars($href, ENT_XML1) . '</d:href><d:propstat><d:prop><d:getcontentlength>123</d:getcontentlength><d:resourcetype/></d:prop></d:propstat></d:response>'
                . '<d:response><d:href>/share%20name/questions.csv</d:href></d:response>'
                . '<d:response><d:href>/share%20name/import/sub/nested.csv</d:href></d:response>'
                . '</d:multistatus>';
            return ['response' => ['code' => 207, 'message' => 'Multi-Status'], 'headers' => [], 'cookies' => [], 'body' => $body];
        }
        if ($method === 'GET' && $url === $base . '/import/' . rawurlencode($name)) {
            return ['response' => ['code' => 200, 'message' => 'OK'], 'headers' => [], 'cookies' => [], 'body' => $search_csv('WebDAV вопрос')];
        }
        return ['response' => ['code' => 500, 'message' => 'Unexpected'], 'headers' => [], 'cookies' => [], 'body' => ''];
    };
    add_filter('pre_http_request', $mock, 10, 3);
    try {
        $error = null;
        $files = NetworkImport::scan_network_folder_for_import_files(Quiz::get($id), $cfg, $error);
        $search_assert($error === null && array_column($files, 'path') === ['import/' . $name] && $files[0]['size'] === 123, 'Некорректный список WebDAV');
        $probe = NetworkImport::analyze_import_file(Quiz::get($id), '', $cfg);
        $res = NetworkImport::auto_import_for_quiz(Quiz::get($id), true, $cfg, 'replace', '', true, false);
        $search_assert(!empty($probe['success']) && $probe['source'] === 'import/' . $name && !empty($res['success']) && $res['filename'] === 'import/' . $name, 'Не прочитан найденный WebDAV файл');
        $search_assert(Quiz::get($id)->get_questions(false)[0]->title === 'WebDAV вопрос', 'Неверное содержимое WebDAV');
        foreach ($calls as [$method, $url, $authorization]) {
            $search_assert($authorization === 'Basic ' . base64_encode('teacher:fixture-password'), 'Не передана авторизация');
            $search_assert(!str_contains($url, 'import/import/'), 'Продублирована import');
        }
        $direct = $cfg; $direct['path'] = $base . '/import/';
        $files = NetworkImport::scan_network_folder_for_import_files(Quiz::get($id), $direct, $error);
        $search_assert($error === null && array_column($files, 'path') === [$name], 'Неверный путь при подключении прямо к import');
        return true;
    } finally { remove_filter('pre_http_request', $mock, 10); Quiz::delete($id); }
});

$it('WebDAV: отсутствующая import разрешает резервный поиск, отказ и плохой XML — нет', function () use ($search_assert, $search_csv): bool {
    $id = Quiz::create(['title' => 'WebDAV ошибки']);
    $base = 'https://import-errors.invalid/share';
    $cfg = ['type' => 'webdav', 'path' => $base];
    $calls = []; $state = 'missing';
    $mock = static function ($pre, $args, $url) use (&$calls, &$state, $base, $search_csv) {
        if (!str_starts_with($url, $base)) return $pre;
        $method = (string)($args['method'] ?? 'GET');
        $calls[] = [$method, $url];
        if ($method === 'PROPFIND' && $url === $base . '/import/') {
            if ($state === 'transport') return new \WP_Error('http_request_failed', 'fixture network failure');
            $code = $state === 'missing' ? 404 : ($state === 'denied' ? 403 : 207);
            return ['response' => ['code' => $code, 'message' => 'fixture'], 'headers' => [], 'cookies' => [], 'body' => $state === 'malformed' ? '<d:multistatus' : ''];
        }
        if ($method === 'PROPFIND' && $url === $base . '/') {
            $body = '<d:multistatus xmlns:d="DAV:"><d:response><d:href>/share/questions.csv</d:href></d:response></d:multistatus>';
            return ['response' => ['code' => 207, 'message' => 'fixture'], 'headers' => [], 'cookies' => [], 'body' => $body];
        }
        if ($method === 'GET' && $url === $base . '/questions.csv') return ['response' => ['code' => 200, 'message' => 'fixture'], 'headers' => [], 'cookies' => [], 'body' => $search_csv('Резервный WebDAV')];
        return ['response' => ['code' => 500, 'message' => 'unexpected'], 'headers' => [], 'cookies' => [], 'body' => ''];
    };
    add_filter('pre_http_request', $mock, 10, 3);
    try {
        $error = null;
        $files = NetworkImport::scan_network_folder_for_import_files(Quiz::get($id), $cfg, $error);
        $search_assert($error === null && array_column($files, 'path') === ['questions.csv'], 'Нет резерва при 404');
        $res = NetworkImport::auto_import_for_quiz(Quiz::get($id), true, $cfg, 'replace', '', true, false);
        $search_assert(!empty($res['success']) && ($res['filename'] ?? '') === 'questions.csv', 'Ошибка резервного импорта');
        foreach (['denied', 'malformed', 'transport'] as $state) {
            $calls = []; $error = null;
            $files = NetworkImport::scan_network_folder_for_import_files(Quiz::get($id), $cfg, $error);
            $search_assert($files === [] && $error !== null, 'Ошибка WebDAV не показана: ' . $state);
            $search_assert(count($calls) === 1 && $calls[0][1] === $base . '/import/', 'При ошибке import просканирован корень: ' . $state);
            $before = Quiz::get($id)->get_questions(false)[0]->title;
            $res = NetworkImport::auto_import_for_quiz(Quiz::get($id), true, $cfg, 'replace', '', true, false);
            $search_assert(empty($res['success']) && Quiz::get($id)->get_questions(false)[0]->title === $before, 'Ошибка доступа заменила вопросы: ' . $state);
        }
        return true;
    } finally { remove_filter('pre_http_request', $mock, 10); Quiz::delete($id); }
});

$it('Сканер в админке: пути отображаются безопасно и ошибка передаётся в диагностику', function () use ($search_assert): bool {
    $js = (string)file_get_contents(WP_OLYMPIAD_PATH . 'admin/js/admin.js');
    $ajax = (string)file_get_contents(WP_OLYMPIAD_PATH . 'includes/class-ajax.php');
    $builder = (string)file_get_contents(WP_OLYMPIAD_PATH . 'admin/views/questions-builder.php');
    $search_assert(str_contains($js, "\$('<option>').val(f.path).text(label)") && !str_contains($js, "'<option value=\"' + f.path"), 'Имя файла вставляется как HTML');
    $search_assert(str_contains($ajax, 'scan_network_folder_for_import_files($quiz, $override, $scan_error)') && str_contains($ajax, '$scan_error ?: NetworkImport::describe_network_folder'), 'Ошибка сканирования скрыта');
    $search_assert(str_contains($builder, 'Сначала ищем файлы непосредственно в import/') && str_contains($builder, 'import/Мои_вопросы.xlsx'), 'Подсказка о папке или пути отсутствует');
    return true;
});
