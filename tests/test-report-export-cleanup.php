<?php
declare(strict_types=1);

namespace WPOlympiad\Tests;

use WPOlympiad\NetworkExport;
use WPOlympiad\Quiz;
use WPOlympiad\RestApi;
use WP_REST_Request;

if (!defined('ABSPATH') || !isset($it)) return;

$reports_assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new \RuntimeException($message);
};
$reports_fixture = static function (): array {
    $root = sys_get_temp_dir() . '/wpo_reports_' . wp_generate_password(10, false, false);
    wp_mkdir_p($root . '/отчёты');
    $id = Quiz::create(['title' => 'Report cleanup fixture', 'status' => 'publish', 'settings' => ['max_attempts' => 0]]);
    $quiz = Quiz::get($id);
    $names = ['манифест_выгрузки_' . NetworkExport::build_safe_slug($quiz) . '.txt', 'руководство_к_протоколам.txt', 'сводка_результатов.txt'];
    foreach ($names as $name) file_put_contents($root . '/отчёты/' . $name, 'Старый отчёт с ручными правками: ' . $name);
    return [$root, $id, $names];
};
$reports_dispose = static function (string $root, int $id): void {
    Quiz::delete($id);
    exec('rm -rf ' . escapeshellarg($root));
};

$it('Отчёты: новый комплект не содержит папку отчётов и файлов заданий', function () use ($reports_assert): bool {
    $id = Quiz::create(['title' => 'No report package']);
    try {
        $pkg = NetworkExport::generate_export_files(Quiz::get($id));
        $reports_assert(!empty($pkg['success']) && isset($pkg['files'][$pkg['primary_filename']]), 'Primary protocol missing');
        $reports_assert(!array_filter(array_keys($pkg['files']), static fn(string $f): bool => str_starts_with($f, 'вопросы/')), 'Question files are still in the package');
        foreach (array_keys($pkg['files']) as $path) {
            $reports_assert(!str_starts_with($path, 'отчёты/') && !str_starts_with($path, 'отчеты/') && !str_starts_with($path, 'reports/'), 'New report file generated');
        }
        return true;
    } finally { Quiz::delete($id); }
});

$it('Отчёты / диск: три точных файла удаляются после экспорта, пустая папка удаляется, повтор безопасен', function () use ($reports_assert, $reports_fixture, $reports_dispose): bool {
    [$root, $id, $names] = $reports_fixture();
    wp_mkdir_p($root . '/import'); wp_mkdir_p($root . '/протоколы');
    $keep = ['import/' . $names[0] => 'входной файл', $names[1] => 'в корне', 'протоколы/сводный_протокол.csv' => 'старый протокол'];
    try {
        foreach ($keep as $path => $content) file_put_contents($root . '/' . $path, $content);
        $config = ['type' => 'filesystem', 'path' => $root];
        $res = NetworkExport::export_quiz($id, $config);
        $reports_assert(!empty($res['success']) && $res['reports_directory'] === 'removed' && count($res['removed_report_files']) === 3, 'Three exact files or empty directory not removed');
        $reports_assert(!file_exists($root . '/отчёты'), 'Empty reports directory remains');
        foreach ($keep as $path => $content) $reports_assert(file_get_contents($root . '/' . $path) === $content, 'Unrelated file changed: ' . $path);
        $again = NetworkExport::export_quiz($id, $config);
        return !empty($again['success']) && $again['reports_directory'] === 'absent' && $again['removed_report_files'] === [];
    } finally { $reports_dispose($root, $id); }
});

$it('Отчёты / диск: посторонние файлы, вложенные папки и манифест другой олимпиады сохраняются', function () use ($reports_assert, $reports_fixture, $reports_dispose): bool {
    [$root, $id, $names] = $reports_fixture();
    try {
        wp_mkdir_p($root . '/отчёты/подпапка');
        $keep = ['отчёты/чужой_файл.txt' => 'свои данные', 'отчёты/манифест_выгрузки_другой.txt' => 'другая олимпиада', 'отчёты/подпапка/' . $names[1] => 'вложенная копия'];
        foreach ($keep as $path => $content) file_put_contents($root . '/' . $path, $content);
        $res = NetworkExport::export_quiz($id, ['type' => 'filesystem', 'path' => $root]);
        $reports_assert(!empty($res['success']) && $res['reports_directory'] === 'not_empty' && !empty($res['reports_notice']), 'Retained folder not reported');
        foreach ($names as $name) $reports_assert(!file_exists($root . '/отчёты/' . $name), 'Legacy report remains');
        foreach ($keep as $path => $content) $reports_assert(file_get_contents($root . '/' . $path) === $content, 'Foreign report changed: ' . $path);
        return is_dir($root . '/отчёты');
    } finally { $reports_dispose($root, $id); }
});

$it('Отчёты / диск: конфликт в «вопросы/» не валит выгрузку, сбой доставки и каталог с именем отчёта остаются ошибками', function () use ($reports_assert, $reports_fixture, $reports_dispose): bool {
    [$root, $id, $names] = $reports_fixture();
    try {
        $quiz = Quiz::get($id); $slug = NetworkExport::build_safe_slug($quiz);
        wp_mkdir_p($root . '/вопросы/вопросы_' . $slug . '.json');
        $conflict = NetworkExport::export_quiz($id, ['type' => 'filesystem', 'path' => $root]);
        $reports_assert(!empty($conflict['success']) && is_dir($root . '/вопросы/вопросы_' . $slug . '.json')
            && str_contains((string) $conflict['message'], 'Очистка «вопросы/» не выполнена'), 'Question folder conflict broke the answer export');
        $reports_assert($conflict['removed_report_files'] === array_map(static fn(string $n): string => 'отчёты/' . $n, $names), 'Reports were not retired by the successful export');
        rmdir($root . '/вопросы/вопросы_' . $slug . '.json'); rmdir($root . '/вопросы');
        wp_mkdir_p($root . '/отчёты');
        foreach ($names as $name) file_put_contents($root . '/отчёты/' . $name, 'восстановленный отчёт ' . $name);
        unlink($root . '/протоколы/протокол_' . $slug . '.csv');
        mkdir($root . '/протоколы/протокол_' . $slug . '.csv');
        $bad = NetworkExport::export_quiz($id, ['type' => 'filesystem', 'path' => $root]);
        $reports_assert(empty($bad['success']), 'Failed delivery reported success');
        foreach ($names as $name) $reports_assert(file_exists($root . '/отчёты/' . $name), 'Report removed before full delivery');
        rmdir($root . '/протоколы/протокол_' . $slug . '.csv');
        unlink($root . '/отчёты/' . $names[0]); mkdir($root . '/отчёты/' . $names[0]);
        file_put_contents($root . '/отчёты/' . $names[0] . '/внутри.txt', 'не удалять');
        $bad = NetworkExport::export_quiz($id, ['type' => 'filesystem', 'path' => $root]);
        return empty($bad['success']) && file_exists($root . '/отчёты/' . $names[1]) && file_get_contents($root . '/отчёты/' . $names[0] . '/внутри.txt') === 'не удалять';
    } finally { $reports_dispose($root, $id); }
});

$it('Отчёты / диск: ссылка на папку блокируется, ссылка на файл удаляется без внешнего оригинала', function () use ($reports_assert, $reports_fixture, $reports_dispose): bool {
    if (!function_exists('symlink')) return true;
    [$root, $id, $names] = $reports_fixture(); $outside = $root . '-outside'; wp_mkdir_p($outside);
    try {
        foreach ($names as $name) { unlink($root . '/отчёты/' . $name); file_put_contents($outside . '/' . $name, 'внешний оригинал'); }
        rmdir($root . '/отчёты'); symlink($outside, $root . '/отчёты');
        $bad = NetworkExport::export_quiz($id, ['type' => 'filesystem', 'path' => $root]);
        $reports_assert(empty($bad['success']) && file_get_contents($outside . '/' . $names[0]) === 'внешний оригинал', 'Directory link followed');
        unlink($root . '/отчёты'); mkdir($root . '/отчёты'); symlink($outside . '/' . $names[0], $root . '/отчёты/' . $names[0]);
        $ok = NetworkExport::export_quiz($id, ['type' => 'filesystem', 'path' => $root]);
        return !empty($ok['success']) && !is_link($root . '/отчёты/' . $names[0]) && file_get_contents($outside . '/' . $names[0]) === 'внешний оригинал';
    } finally { $reports_dispose($root, $id); exec('rm -rf ' . escapeshellarg($outside)); }
});

$it('Отчёты / WebDAV: только файлы с ETag удаляются после PUT, сам каталог не удаляется рекурсивно', function () use ($reports_assert): bool {
    $id = Quiz::create(['title' => 'DAV report cleanup']);
    $slug = NetworkExport::build_safe_slug(Quiz::get($id));
    $names = ['манифест_выгрузки_' . $slug . '.txt', 'руководство_к_протоколам.txt', 'сводка_результатов.txt'];
    $files = []; foreach ($names as $name) $files['/share/отчёты/' . $name] = 'старые данные';
    $files['/share/отчёты/чужой.txt'] = 'не удалять'; $calls = [];
    $mock = static function ($pre, $args, $url) use (&$calls, &$files) {
        if (!str_starts_with($url, 'https://reports-cleanup.invalid/share')) return $pre;
        $path = rawurldecode((string)parse_url($url, PHP_URL_PATH)); $method = $args['method'];
        $calls[] = ['method' => $method, 'path' => $path, 'args' => $args]; $body = ''; $code = 201;
        if ($method === 'PROPFIND') {
            if ($path === '/share/отчёты' || array_key_exists($path, $files)) {
                $collection = $path === '/share/отчёты';
                $href = htmlspecialchars((string)parse_url($url, PHP_URL_PATH), ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $etag = $collection ? '' : '<d:getetag>&quot;fixture-etag&quot;</d:getetag>';
                $body = '<d:multistatus xmlns:d="DAV:"><d:response><d:href>' . $href . '</d:href><d:propstat><d:prop><d:resourcetype>' . ($collection ? '<d:collection/>' : '') . '</d:resourcetype>' . $etag . '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>';
                $code = 207;
            } else $code = 404;
        } elseif ($method === 'DELETE') {
            if (array_key_exists($path, $files)) {
                if (($args['headers']['If-Match'] ?? '') !== '"fixture-etag"') $code = 412;
                else { unset($files[$path]); $code = 204; }
            } else $code = 404;
        } elseif ($method === 'MKCOL') $code = 405;
        return ['headers' => [], 'body' => $body, 'response' => ['code' => $code, 'message' => 'QA'], 'cookies' => []];
    };
    try {
        add_filter('pre_http_request', $mock, 10, 3);
        $res = NetworkExport::export_quiz($id, ['type' => 'webdav', 'path' => 'https://reports-cleanup.invalid/share']);
        $reports_assert(!empty($res['success']) && $res['reports_directory'] === 'webdav_retained' && count($res['removed_report_files']) === 3 && !empty($res['reports_notice']), 'DAV cleanup failed or directory warning missing');
        foreach ($names as $name) $reports_assert(!isset($files['/share/отчёты/' . $name]), 'Old report remains');
        $reports_assert(($files['/share/отчёты/чужой.txt'] ?? '') === 'не удалять', 'Foreign DAV file changed');
        $report_deletes = array_values(array_filter($calls, static fn($c) => $c['method'] === 'DELETE' && str_starts_with($c['path'], '/share/отчёты/')));
        $reports_assert(count($report_deletes) === 3 && !array_filter($calls, static fn($c) => $c['method'] === 'DELETE' && $c['path'] === '/share/отчёты'), 'Recursive collection DELETE attempted');
        foreach ($report_deletes as $c) $reports_assert($c['args']['redirection'] === 0 && ($c['args']['headers']['If-Match'] ?? '') === '"fixture-etag"', 'Unsafe DAV DELETE');
        $first = array_search($report_deletes[0], $calls, true);
        $puts = array_filter(array_slice($calls, 0, $first), static fn($c) => $c['method'] === 'PUT');
        return count($puts) === $res['files_count'];
    } finally { remove_filter('pre_http_request', $mock, 10); Quiz::delete($id); }
});

$it('Отчёты / WebDAV: без ETag и при ошибке PUT старые файлы не удаляются', function () use ($reports_assert): bool {
    $id = Quiz::create(['title' => 'DAV report safety']); $slug = NetworkExport::build_safe_slug(Quiz::get($id));
    $paths = ['/share/отчёты/манифест_выгрузки_' . $slug . '.txt', '/share/отчёты/руководство_к_протоколам.txt', '/share/отчёты/сводка_результатов.txt'];
    $calls = []; $put_fail = false;
    $mock = static function ($pre, $args, $url) use (&$calls, &$put_fail, $paths) {
        if (!str_starts_with($url, 'https://reports-safety.invalid/share')) return $pre;
        $path = rawurldecode((string)parse_url($url, PHP_URL_PATH)); $method = $args['method'];
        $calls[] = ['method' => $method, 'path' => $path]; $body = '';
        $code = $method === 'DELETE' ? 404 : ($method === 'MKCOL' ? 405 : (($put_fail && $method === 'PUT') ? 507 : 201));
        if ($method === 'PROPFIND' && ($path === '/share/отчёты' || in_array($path, $paths, true))) {
            $href = htmlspecialchars((string)parse_url($url, PHP_URL_PATH), ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $body = '<d:multistatus xmlns:d="DAV:"><d:response><d:href>' . $href . '</d:href><d:propstat><d:prop><d:resourcetype>' . ($path === '/share/отчёты' ? '<d:collection/>' : '') . '</d:resourcetype></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>'; $code = 207;
        }
        return ['headers' => [], 'body' => $body, 'response' => ['code' => $code, 'message' => 'QA'], 'cookies' => []];
    };
    try {
        add_filter('pre_http_request', $mock, 10, 3); $config = ['type' => 'webdav', 'path' => 'https://reports-safety.invalid/share'];
        $bad = NetworkExport::export_quiz($id, $config);
        // Неизвестный ETag = нельзя удалять безопасно, но и выгрузка не считается неудачной.
        $reports_assert(!empty($bad['success']) && ($bad['reports_directory'] ?? '') === 'unverified'
            && str_contains((string)($bad['reports_notice'] ?? ''), 'ETag'), 'Unverifiable ETag must warn instead of failing the export');
        $reports_assert(get_transient('wpo_last_report_cleanup_error_' . $id) === false, 'Unverifiable transport stored a cleanup error');
        $reports_assert(!array_filter($calls, static fn($c) => $c['method'] === 'DELETE' && str_starts_with($c['path'], '/share/отчёты')), 'Unsafe report deletion');
        $calls = []; $put_fail = true; $bad = NetworkExport::export_quiz($id, $config);
        return empty($bad['success']) && !array_filter($calls, static fn($c) => $c['method'] === 'PROPFIND' && str_starts_with($c['path'], '/share/отчёты'));
    } finally { remove_filter('pre_http_request', $mock, 10); Quiz::delete($id); }
});

$it('Отчёты: автоматическая выгрузка после сдачи удаляет старые файлы, не создавая папку снова', function () use ($reports_assert, $reports_fixture, $reports_dispose): bool {
    [$root, $id, $names] = $reports_fixture();
    try {
        $quiz = Quiz::get($id);
        $quiz->update_setting('enable_network_export', 1);
        $quiz->update_setting('network_folder_type', 'local');
        $quiz->update_setting('network_folder_path', $root);
        $quiz->update_setting('network_auto_export', 1);
        $start = new WP_REST_Request('POST', '/wp-olympiad/v1/start');
        $start->set_param('quiz_id', $id); $start->set_param('participant_name', 'Авто тест'); $start->set_param('participant_email', 'auto@example.invalid'); $start->set_param('participant_org', 'Тестовая школа');
        $started = RestApi::handle_start($start); $reports_assert(!is_wp_error($started), 'REST start failed');
        $token = $started->get_data()['token'] ?? '';
        $reports_assert($token !== '', 'No attempt token');
        $submit = new WP_REST_Request('POST', '/wp-olympiad/v1/submit');
        $submit->set_param('token', $token); $submit->set_param('answers', []);
        $done = RestApi::handle_submit($submit);
        $reports_assert(!is_wp_error($done) && $done->get_status() === 200, 'REST submit failed');
        $last = get_transient('wpo_last_network_export_' . $id);
        return !file_exists($root . '/отчёты') && !empty($last['success']) && $last['reports_directory'] === 'removed' && count($last['removed_report_files']) === 3;
    } finally { $reports_dispose($root, $id); }
});

$it('Отчёты: проверка подключения и отдельный экспорт вопросов не удаляют старые отчёты', function () use ($reports_assert, $reports_fixture, $reports_dispose): bool {
    [$root, $id, $names] = $reports_fixture();
    try {
        $config = ['type' => 'filesystem', 'path' => $root];
        $connection = NetworkExport::test_connection($config);
        $questions = NetworkExport::export_questions_to_network(Quiz::get($id), $config);
        $reports_assert(!empty($connection['success']) && !empty($questions['success']), 'Connection or questions export failed');
        foreach ($names as $name) $reports_assert(file_exists($root . '/отчёты/' . $name), 'Non-full export removed a report');
        return true;
    } finally { $reports_dispose($root, $id); }
});

$it('Отчёты / WebDAV: каталог вместо файла и изменение ETag не приводят к неконтролируемому DELETE', function () use ($reports_assert): bool {
    $id = Quiz::create(['title' => 'DAV conflict fixture']);
    $slug = NetworkExport::build_safe_slug(Quiz::get($id));
    $paths = ['/share/отчёты/манифест_выгрузки_' . $slug . '.txt', '/share/отчёты/руководство_к_протоколам.txt', '/share/отчёты/сводка_результатов.txt'];
    $calls = []; $mode = 'collection';
    $mock = static function ($pre, $args, $url) use (&$calls, &$mode, $paths) {
        if (!str_starts_with($url, 'https://reports-conflict.invalid/share')) return $pre;
        $path = rtrim(rawurldecode((string)parse_url($url, PHP_URL_PATH)), '/'); $method = $args['method'];
        $calls[] = ['method' => $method, 'path' => $path]; $body = '';
        $code = $method === 'DELETE' ? (in_array($path, $paths, true) ? 412 : 404) : ($method === 'MKCOL' ? 405 : 201);
        if ($method === 'PROPFIND' && ($path === '/share/отчёты' || in_array($path, $paths, true))) {
            $href = htmlspecialchars((string)parse_url($url, PHP_URL_PATH), ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $collection = $path === '/share/отчёты' || ($mode === 'collection' && $path === $paths[0]);
            $body = '<d:multistatus xmlns:d="DAV:"><d:response><d:href>' . $href . '</d:href><d:propstat><d:prop><d:resourcetype>' . ($collection ? '<d:collection/>' : '') . '</d:resourcetype><d:getetag>&quot;etag&quot;</d:getetag></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>'; $code = 207;
        }
        return ['headers' => [], 'body' => $body, 'response' => ['code' => $code, 'message' => 'QA'], 'cookies' => []];
    };
    try {
        add_filter('pre_http_request', $mock, 10, 3); $config = ['type' => 'webdav', 'path' => 'https://reports-conflict.invalid/share'];
        $bad = NetworkExport::export_quiz($id, $config);
        $report_deletes = array_filter($calls, static fn($c) => $c['method'] === 'DELETE' && str_starts_with($c['path'], '/share/отчёты'));
        $reports_assert(empty($bad['success']) && !$report_deletes, 'Collection was targeted by DELETE');
        $mode = 'race'; $calls = [];
        $bad = NetworkExport::export_quiz($id, $config);
        $report_deletes = array_values(array_filter($calls, static fn($c) => $c['method'] === 'DELETE' && str_starts_with($c['path'], '/share/отчёты')));
        return empty($bad['success']) && str_contains($bad['message'], '412') && count($report_deletes) === 1 && $report_deletes[0]['path'] === $paths[0];
    } finally { remove_filter('pre_http_request', $mock, 10); Quiz::delete($id); }
});

$it('Отчёты: ошибка автоочистки сохраняется для администратора, сдача не ломается, повтор снимает ошибку', function () use ($reports_assert, $reports_dispose): bool {
    $root = sys_get_temp_dir() . '/wpo_reports_auto_error_' . wp_generate_password(10, false, false);
    wp_mkdir_p($root . '/other'); symlink($root . '/other', $root . '/отчёты');
    $id = Quiz::create(['title' => 'Auto report failure', 'status' => 'publish', 'settings' => ['max_attempts' => 0]]);
    try {
        $quiz = Quiz::get($id);
        $quiz->update_setting('enable_network_export', 1); $quiz->update_setting('network_auto_export', 1);
        $quiz->update_setting('network_folder_type', 'local'); $quiz->update_setting('network_folder_path', $root);
        $start = new WP_REST_Request('POST', '/wp-olympiad/v1/start');
        $start->set_param('quiz_id', $id); $start->set_param('participant_name', 'Ошибка отчёта'); $start->set_param('participant_email', 'error@example.invalid'); $start->set_param('participant_org', 'Тестовая организация');
        $started = RestApi::handle_start($start); $reports_assert(!is_wp_error($started), 'REST start failed');
        $submit = new WP_REST_Request('POST', '/wp-olympiad/v1/submit');
        $submit->set_param('token', $started->get_data()['token'] ?? ''); $submit->set_param('answers', []);
        $response = RestApi::handle_submit($submit);
        $reports_assert(!is_wp_error($response) && $response->get_status() === 200, 'Report cleanup failure disrupted participant submission');
        $error = get_transient('wpo_last_report_cleanup_error_' . $id);
        $reports_assert(is_array($error) && str_contains($error['message'] ?? '', 'очистка старых отчётов не завершена'), 'Administrator cannot see automatic cleanup failure');
        unlink($root . '/отчёты'); mkdir($root . '/отчёты');
        $again = NetworkExport::export_quiz($id);
        return !empty($again['success']) && get_transient('wpo_last_report_cleanup_error_' . $id) === false;
    } finally {
        delete_transient('wpo_last_report_cleanup_error_' . $id); $reports_dispose($root, $id);
    }
});

$it('Отчёты: недопустимая проверка транспорта — предупреждение, папка и файлы остаются', function () use ($reports_assert): bool {
    $id = Quiz::create(['title' => 'DAV report unverifiable probe']);
    $slug = NetworkExport::build_safe_slug(Quiz::get($id));
    $paths = ['/share/отчёты/манифест_выгрузки_' . $slug . '.txt', '/share/отчёты/руководство_к_протоколам.txt', '/share/отчёты/сводка_результатов.txt'];
    $calls = [];
    $mock = static function ($pre, $args, $url) use (&$calls, $paths) {
        if (!str_starts_with($url, 'https://reports-unverifiable.invalid/share')) return $pre;
        $path = rtrim(rawurldecode((string)parse_url($url, PHP_URL_PATH)), '/'); $method = $args['method'];
        $calls[] = ['method' => $method, 'path' => $path];
        // Сервер отвечает на PROPFIND папки отчётов некорректно: проверить безопасность нельзя.
        if ($method === 'PROPFIND' && $path === '/share/отчёты') return ['headers' => [], 'body' => '', 'response' => ['code' => 501, 'message' => 'No'], 'cookies' => []];
        if ($method === 'PROPFIND' && in_array($path, $paths, true)) return ['headers' => [], 'body' => '', 'response' => ['code' => 404, 'message' => 'No'], 'cookies' => []];
        if ($method === 'DELETE') return ['headers' => [], 'body' => '', 'response' => ['code' => 404, 'message' => 'No'], 'cookies' => []];
        return ['headers' => [], 'body' => '', 'response' => ['code' => $method === 'MKCOL' ? 405 : 201, 'message' => 'QA'], 'cookies' => []];
    };
    try {
        add_filter('pre_http_request', $mock, 10, 3);
        $res = NetworkExport::export_quiz($id, ['type' => 'webdav', 'path' => 'https://reports-unverifiable.invalid/share']);
        $reports_assert(!empty($res['success']), 'Unverifiable probe failed the export: ' . (string)($res['message'] ?? ''));
        $reports_assert(($res['reports_directory'] ?? '') === 'unverified' && ($res['removed_report_files'] ?? []) === [], 'Cleanup was not reported as skipped');
        $reports_assert(str_contains((string)($res['message'] ?? ''), 'не удалены'), 'Export message lacks the retention notice');
        $reports_assert(get_transient('wpo_last_report_cleanup_error_' . $id) === false, 'Skipped cleanup stored an error');
        $last = get_transient('wpo_last_network_export_' . $id);
        return is_array($last) && !empty($last['success']) && !array_filter($calls, static fn($c) => $c['method'] === 'DELETE' && str_starts_with($c['path'], '/share/отчёты'));
    } finally { remove_filter('pre_http_request', $mock, 10); Quiz::delete($id); }
});

$it('Отчёты / SMB: классификация ответов сервера разделяет отказ и непроверяемый ответ', function () use ($reports_assert): bool {
    $reports_assert(\WPOlympiad\ReportExportCleanup::smb_attributes('  attributes:         D (10)') === 0x10, 'Directory attributes not parsed');
    $reports_assert(\WPOlympiad\ReportExportCleanup::smb_attributes('  attributes:           (80)') === 0x80, 'Normal file attributes not parsed');
    $reports_assert(\WPOlympiad\ReportExportCleanup::smb_attributes('attributes: A (0x20)') === 0x20, 'Prefixed hex not parsed');
    $reports_assert(\WPOlympiad\ReportExportCleanup::smb_attributes('nothing useful') === null, 'Missing attributes treated as a proof');

    $reports_assert(\WPOlympiad\ReportExportCleanup::judge_smb_directory(1, ['NT_STATUS_NOT_A_REPARSE_POINT'], '') === 'plain', 'Samba readlink answer not accepted');
    $reports_assert(\WPOlympiad\ReportExportCleanup::judge_smb_directory(1, ['NT_STATUS_INVALID_DEVICE_REQUEST'], 'NT_STATUS_INVALID_DEVICE_REQUEST linking') === 'unsupported', 'Server without reparse support must be unverifiable');
    $reports_assert(\WPOlympiad\ReportExportCleanup::judge_smb_directory(1, [], '') === 'unsupported', 'Silent refusal treated as a proof');
    $reports_assert(\WPOlympiad\ReportExportCleanup::judge_smb_directory(0, [], '\\share\\отчёты -> other') === 'link', 'Link answer must refuse');
    $reports_assert(\WPOlympiad\ReportExportCleanup::judge_smb_directory(1, ['NT_STATUS_OBJECT_PATH_NOT_FOUND'], '') === 'absent', 'Missing folder must be absent');

    $reports_assert(\WPOlympiad\ReportExportCleanup::judge_smb_target(1, ['NT_STATUS_NOT_A_REPARSE_POINT'], '', 0x80) === 'plain', 'Plain file rejected');
    $reports_assert(\WPOlympiad\ReportExportCleanup::judge_smb_target(1, ['NT_STATUS_NOT_A_REPARSE_POINT'], '', 0x10) === 'dangerous', 'Directory accepted as a file');
    $reports_assert(\WPOlympiad\ReportExportCleanup::judge_smb_target(1, ['NT_STATUS_NOT_A_REPARSE_POINT'], '', 0x410) === 'dangerous', 'Reparse point accepted as a file');
    $reports_assert(\WPOlympiad\ReportExportCleanup::judge_smb_target(0, [], '\\share\\отчёты\\файл.txt -> hidden') === 'dangerous', 'File link accepted');
    $reports_assert(\WPOlympiad\ReportExportCleanup::judge_smb_target(1, ['NT_STATUS_IO_TIMEOUT'], '', null) === 'unsupported', 'Unanswered probe treated as a proof');
    $reports_assert(\WPOlympiad\ReportExportCleanup::judge_smb_target(1, ['NT_STATUS_NO_SUCH_FILE'], '', null) === 'absent', 'Missing file not absent');

    $export = file_get_contents(WP_OLYMPIAD_PATH . 'includes/class-network-export.php');
    $reports_assert(str_contains((string)$export, "'unverified' =>"), 'Export does not explain the skipped cleanup');
    $reports_assert(str_contains((string)$export, "'retained' =>"), 'Export does not explain a retained folder');
    return true;
});
