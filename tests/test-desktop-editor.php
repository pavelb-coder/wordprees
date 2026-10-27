<?php
/** Desktop editor integration regression tests. Included by test-suite.php. */
namespace WPOlympiad\Tests;

use WPOlympiad\DesktopEditor;
use WPOlympiad\NetworkExport;
use WPOlympiad\NetworkImport;
use WPOlympiad\Quiz;

if (!defined('ABSPATH') || !isset($it)) { return; }

$editor_root = static function (): string {
    $dir = sys_get_temp_dir() . '/wpo_editor_' . wp_generate_password(12, false);
    wp_mkdir_p($dir . '/import');
    return $dir;
};
$editor_cleanup = static function (string $dir, array $configs = []): void {
    foreach ($configs as $config) {
        $key = DesktopEditor::state_key($config);
        delete_option($key); delete_option($key . '_lock');
    }
    if (is_dir($dir)) {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) { @rmdir($entry->getPathname()); }
            else { @unlink($entry->getPathname()); }
        }
        @rmdir($dir);
    }
};

$it('Редактор ПК: комплект, SHA-256, ZIP, allow-list и контроль повреждённых assets', function (): bool {
    $manifest = DesktopEditor::manifest();
    if (array_keys($manifest) !== ['exe', 'archive', 'source', 'readme', 'license']) return false;
    $bundled = array_values(array_diff(scandir(WP_OLYMPIAD_PATH . 'import'), ['.', '..'])); sort($bundled);
    if ($bundled !== ['QuestionStudio.exe', 'question-studio-source-1.0.0.zip']) return false;
    foreach ($manifest as $format => $expected) {
        $asset = DesktopEditor::asset($format);
        if ($asset['size'] !== filesize($asset['path']) || $asset['sha256'] !== hash_file('sha256', $asset['path'])) return false;
        if (str_contains($asset['name'], '/') || str_contains($asset['name'], '\\')) return false;
    }
    if (file_get_contents(DesktopEditor::asset('exe')['path'], false, null, 0, 2) !== 'MZ') return false;
    foreach (['archive', 'source'] as $format) {
        $zip = new \ZipArchive();
        if ($zip->open(DesktopEditor::asset($format)['path']) !== true) return false;
        if ($zip->numFiles < ($format === 'archive' ? 4 : 10)) return false;
        $zip->close();
    }
    foreach (['../wp-config.php', 'exe/../../a', 'php', ''] as $unknown) {
        try { DesktopEditor::asset($unknown); return false; }
        catch (\InvalidArgumentException $e) { /* Expected allow-list refusal. */ }
        if (DesktopEditor::download_url($unknown) !== '') return false;
    }
    $path = DesktopEditor::asset('readme')['path'];
    $original = file_get_contents($path);
    try {
        file_put_contents($path, str_repeat('!', strlen($original)));
        try { DesktopEditor::asset('readme'); return false; }
        catch (\RuntimeException $e) { if (!str_contains($e->getMessage(), 'повреждён')) return false; }
    } finally { file_put_contents($path, $original); clearstatcache(true, $path); }
    return strlen(DesktopEditor::bundle_id()) === 64 && !str_contains(DesktopEditor::manifest()['readme']['name'], '.json');
});

$it('Редактор ПК: размещение только 2 файлов, идемпотентность и восстановление без изменения вопросов', function () use ($editor_root, $editor_cleanup): bool {
    $root = $editor_root(); $config = ['path' => $root, 'type' => 'local'];
    $question = $root . '/import/вопросы_olympiad-2.csv';
    $draft = $root . '/import/Мой черновик.wpoq';
    file_put_contents($question, "type;title;points;choices;correct\nsingle;Сохранный вопрос;2;Да|Нет;1\n");
    file_put_contents($draft, '{"draft":"Не изменять"}');
    $hashes = [hash_file('sha256', $question), hash_file('sha256', $draft)];
    $quiz_id = Quiz::create(['title' => 'Редактор / scanner', 'status' => 'publish']);
    try {
        $first = DesktopEditor::deploy($config);
        if (empty($first['success']) || $first['written'] !== 2 || $first['files'] !== ['import/QuestionStudio.exe', 'import/question-studio-source-1.0.0.zip']) return false;
        $old_time = time() - 1000;
        foreach ($first['files'] as $file) { touch($root . '/' . $file, $old_time); }
        $second = DesktopEditor::deploy($config);
        if (empty($second['success']) || $second['written'] !== 0) return false;
        foreach ($first['files'] as $file) {
            clearstatcache(true, $root . '/' . $file);
            if (filemtime($root . '/' . $file) !== $old_time) return false;
        }
        unlink($root . '/import/question-studio-source-1.0.0.zip');
        file_put_contents($root . '/import/QuestionStudio.exe', 'corrupt');
        $repaired = DesktopEditor::deploy($config);
        if (empty($repaired['success']) || $repaired['written'] !== 2) return false;
        if (hash_file('sha256', $root . '/import/QuestionStudio.exe') !== DesktopEditor::asset('exe')['sha256']) return false;
        if (file_exists($root . '/import/question-studio-windows-1.0.0.zip') || file_exists($root . '/import/import')) return false;
        if ($hashes !== [hash_file('sha256', $question), hash_file('sha256', $draft)]) return false;
        $files = NetworkImport::scan_network_folder_for_import_files(Quiz::get($quiz_id), $config);
        return array_column($files, 'path') === ['import/вопросы_olympiad-2.csv'] && empty(glob($root . '/import/.wpo-editor-*'));
    } finally { Quiz::delete($quiz_id); $editor_cleanup($root, [$config]); }
});

$it('Редактор ПК: import/import, UNC/URL пути, lock, частичная запись и symlink-защита', function () use ($editor_root, $editor_cleanup): bool {
    foreach (['/share/import/', '\\\\NAS\\Share\\import\\', 'smb://nas/share/IMPORT', 'https://nas.test/share/import/', 'ftp://nas.test/share/import'] as $path) {
        if (DesktopEditor::import_prefix(['path' => $path]) !== '') return false;
    }
    if (DesktopEditor::import_prefix(['path' => '/share/imports']) !== 'import/') return false;
    $root = $editor_root(); $config = ['path' => $root . '/import', 'type' => 'filesystem'];
    $key = DesktopEditor::state_key($config);
    $real_config = ['path' => $root . '/mnt/share_Василий', 'type' => 'local'];
    try {
        // Real mount names / Cyrillic words containing «или» must not silently
        // redirect installation to the default uploads directory.
        $real = NetworkExport::test_connection($real_config);
        if (empty($real['success']) || empty($real['editor']['success']) || !is_file($real_config['path'] . '/import/QuestionStudio.exe')) return false;
        add_option($key . '_lock', time() + 120, '', false);
        $pending = DesktopEditor::deploy($config);
        if (empty($pending['pending']) || is_file($root . '/import/QuestionStudio.exe')) return false;
        update_option($key . '_lock', time() - 1, false);
        $first = DesktopEditor::deploy($config);
        if (empty($first['success']) || is_dir($root . '/import/import')) return false;
        if (get_option($key . '_lock', null) !== null) return false;
        // An unusable target must not be mistaken for a successful two-file installation.
        unlink($root . '/import/question-studio-source-1.0.0.zip');
        mkdir($root . '/import/question-studio-source-1.0.0.zip');
        $partial = DesktopEditor::deploy($config, true);
        if (!empty($partial['success'])) return false;
        if (empty(DesktopEditor::deploy($config)['cached'])) return false;
        rmdir($root . '/import/question-studio-source-1.0.0.zip');
        if (empty(DesktopEditor::deploy($config, true)['success'])) return false;
        if (function_exists('symlink')) {
            $outside = $root . '/original.txt'; file_put_contents($outside, 'не менять');
            unlink($root . '/import/question-studio-source-1.0.0.zip');
            if (symlink($outside, $root . '/import/question-studio-source-1.0.0.zip')) {
                $blocked = DesktopEditor::deploy($config, true);
                if (!empty($blocked['success']) || file_get_contents($outside) !== 'не менять') return false;
            }
        }
        return empty(glob($root . '/import/.wpo-editor-*'));
    } finally { $editor_cleanup($root, [$config, $real_config]); }
});

$it('Редактор ПК: автоподключение, cron при выключенном/отложенном импорте и ручная синхронизация', function () use ($editor_root, $editor_cleanup): bool {
    $root = $editor_root(); $config = ['path' => $root, 'type' => 'local'];
    $quiz_id = Quiz::create(['title' => 'Редактор / cron', 'status' => 'publish', 'settings' => [
        'network_folder_path' => $root, 'network_folder_type' => 'local', 'network_auto_import' => 0,
    ]]);
    file_put_contents($root . '/import/учитель.csv', "type;title;points;choices;correct\nsingle;Автодоставка без автоимпорта?;1;Да|Нет;1\n");
    try {
        $results = NetworkImport::run_scheduled_auto_imports();
        if (isset($results[$quiz_id]) || count(Quiz::get($quiz_id)->get_questions(false)) !== 0) return false;
        if (!is_file($root . '/import/QuestionStudio.exe') || file_exists($root . '/import/questions_template.csv')) return false;
        unlink($root . '/import/QuestionStudio.exe');
        NetworkImport::run_scheduled_auto_imports();
        if (!is_file($root . '/import/QuestionStudio.exe')) return false;
        unlink($root . '/import/question-studio-source-1.0.0.zip');
        $quiz = Quiz::get($quiz_id);
        $quiz->update_setting('network_auto_import', 1);
        $quiz->update_setting('network_auto_import_interval', 'daily');
        $quiz->update_setting('last_network_import_time', time());
        $results = NetworkImport::run_scheduled_auto_imports();
        if (isset($results[$quiz_id]) || !is_file($root . '/import/question-studio-source-1.0.0.zip')) return false;
        unlink($root . '/import/QuestionStudio.exe');
        $manual = NetworkImport::import_from_network($quiz_id, 'import/учитель.csv', 'append');
        if (empty($manual['success']) || $manual['imported_count'] !== 1 || !is_file($root . '/import/QuestionStudio.exe')) return false;
        $connection = NetworkExport::test_connection($config);
        if (empty($connection['success']) || empty($connection['editor']['success'])) return false;
        $source = file_get_contents(WP_OLYMPIAD_PATH . 'admin/class-admin.php');
        return substr_count($source, 'DesktopEditor::ensure_for_quiz($quiz)') >= 2
            && strpos($source, '$quiz->update($data);') < strpos($source, 'DesktopEditor::ensure_for_quiz($quiz)');
    } finally { Quiz::delete($quiz_id); $editor_cleanup($root, [$config]); }
});

$it('Редактор ПК / WebDAV: MKCOL, URL, все PUT, кеш, retry и честная диагностика частичного сбоя', function () use ($editor_cleanup): bool {
    $config = ['type' => 'webdav', 'path' => 'https://editor-nas.invalid/share', 'user' => 'teacher', 'password' => 'editor-secret'];
    $calls = []; $mode = 'ok';
    $mock = static function ($pre, $args, $url) use (&$calls, &$mode) {
        if (!str_contains($url, 'editor-nas.invalid')) return $pre;
        $calls[] = ['url' => $url, 'method' => $args['method'], 'headers' => $args['headers'], 'size' => strlen((string)($args['body'] ?? ''))];
        $code = $args['method'] === 'MKCOL' ? 405 : 201;
        if ($mode === 'partial' && str_contains($url, 'question-studio-source-1.0.0.zip')) $code = 507;
        if ($mode === 'mkcol' && $args['method'] === 'MKCOL') $code = 403;
        if ($mode === 'exe' && str_ends_with($url, '.exe')) $code = 403;
        if ($args['method'] === 'DELETE') $code = 204;
        return ['response' => ['code' => $code, 'message' => 'mock'], 'headers' => [], 'body' => ''];
    };
    add_filter('pre_http_request', $mock, 10, 3);
    try {
        $ok = DesktopEditor::deploy($config);
        if (empty($ok['success']) || $ok['written'] !== 2 || count($calls) !== 3) return false;
        if ($calls[0]['method'] !== 'MKCOL' || $calls[1]['url'] !== 'https://editor-nas.invalid/share/import/QuestionStudio.exe') return false;
        if ($calls[1]['headers']['Authorization'] !== 'Basic ' . base64_encode('teacher:editor-secret')) return false;
        if (str_contains(wp_json_encode(DesktopEditor::status($config)), 'editor-secret')) return false;
        $cached = DesktopEditor::deploy($config);
        if (empty($cached['cached']) || $cached['written'] !== 0 || count($calls) !== 3) return false;
        $mode = 'partial';
        $partial = DesktopEditor::deploy($config, true);
        if (!empty($partial['success']) || $partial['written'] !== 1 || !str_contains($partial['message'], '507')) return false;
        $count = count($calls);
        if (empty(DesktopEditor::deploy($config)['cached']) || count($calls) !== $count) return false;
        $mode = 'ok';
        if (empty(DesktopEditor::deploy($config, true)['success'])) return false;
        $state = DesktopEditor::status($config); $state['bundle'] = 'old';
        update_option(DesktopEditor::state_key($config), $state, false);
        $count = count($calls);
        if (empty(DesktopEditor::deploy($config)['success']) || count($calls) !== $count + 3) return false;
        $mode = 'mkcol';
        $denied = DesktopEditor::deploy($config, true);
        if (!empty($denied['success']) || !str_contains($denied['message'], 'MKCOL')) return false;
        $mode = 'exe';
        $connection = NetworkExport::test_connection($config);
        return !empty($connection['success']) && empty($connection['editor']['success']) && str_contains($connection['message'], '403');
    } finally { remove_filter('pre_http_request', $mock, 10); $editor_cleanup('', [$config]); }
});

$it('Редактор ПК: шаблоны и экспорт не удаляют существующие вопросы, черновики и ответы', function () use ($editor_root, $editor_cleanup): bool {
    $root = $editor_root(); $config = ['path' => $root, 'type' => 'filesystem'];
    $user_files = [
        'import/тест.xlsx' => NetworkImport::get_template_xlsx(),
        'import/Черновик.wpoq' => '{"draft":true}',
        'import/вопросы_olympiad-2.csv' => 'вопросы учителя',
        'answers.csv' => 'сохранные ответы',
        'заметки.txt' => 'сохранные заметки',
        'questions/учитель.json' => '{"title":"Чужой файл"}',
    ];
    wp_mkdir_p($root . '/questions');
    foreach ($user_files as $file => $data) file_put_contents($root . '/' . $file, $data);
    $quiz_id = Quiz::create(['title' => 'Редактор / сохранность', 'status' => 'publish']);
    try {
        if (empty(DesktopEditor::deploy($config)['success'])) return false;
        NetworkExport::cleanup_loose_root_export_files($root, $config);
        $message = null;
        if (NetworkExport::deploy_import_templates($config, $message) || !str_contains((string)$message, 'отключено')) return false;
        if (empty(NetworkExport::export_quiz($quiz_id, $config)['success'])) return false;
        foreach ($user_files as $file => $data) {
            if (!is_file($root . '/' . $file) || hash_file('sha256', $root . '/' . $file) !== hash('sha256', $data)) return false;
        }
        return hash_file('sha256', $root . '/import/QuestionStudio.exe') === DesktopEditor::asset('exe')['sha256'];
    } finally { Quiz::delete($quiz_id); $editor_cleanup($root, [$config]); }
});

$it('Редактор ПК: скачивание и восстановление защищены правами администратора и nonce', function () use ($editor_root, $editor_cleanup): bool {
    $root = $editor_root(); $config = ['path' => $root, 'type' => 'local'];
    $old_uid = get_current_user_id(); $old_get = $_GET; $old_post = $_POST; $old_request = $_REQUEST;
    if (!defined('DOING_AJAX')) define('DOING_AJAX', true);
    $die_args = [];
    $die = static function ($message = '', $title = '', $args = []) use (&$die_args): void {
        $die_args = is_array($args) ? $args : ['response' => $args];
        throw new \RuntimeException('wpo-editor-expected-stop');
    };
    $handler = static function () use ($die) { return $die; };
    add_filter('wp_die_handler', $handler, PHP_INT_MAX);
    add_filter('wp_die_ajax_handler', $handler, PHP_INT_MAX);
    $invoke = static function (callable $call) {
        ob_start();
        try { $call(); throw new \RuntimeException('Unexpected return'); }
        catch (\RuntimeException $e) { if ($e->getMessage() !== 'wpo-editor-expected-stop') throw $e; }
        finally { $body = ob_get_clean(); }
        return $body;
    };
    try {
        if (has_action('admin_post_wp_olympiad_download_editor', [DesktopEditor::class, 'download']) === false) return false;
        if (has_action('wp_ajax_wp_olympiad_sync_desktop_editor', [DesktopEditor::class, 'ajax_sync']) === false) return false;
        wp_set_current_user(0); $_GET = ['format' => 'exe'];
        $invoke([DesktopEditor::class, 'download']);
        if (($die_args['response'] ?? 0) !== 403) return false;
        $_POST = $_REQUEST = ['path' => $root, 'nonce' => 'bad'];
        $denied = json_decode($invoke([DesktopEditor::class, 'ajax_sync']), true);
        if (!is_array($denied) || !empty($denied['success']) || is_file($root . '/import/QuestionStudio.exe')) return false;
        wp_set_current_user(1);
        $_GET = $_REQUEST = ['format' => 'exe', '_wpnonce' => 'bad'];
        $invoke([DesktopEditor::class, 'download']);
        if (($die_args['response'] ?? 0) !== 403) return false;
        foreach (['exe', 'archive', 'source', 'readme', 'license'] as $format) {
            $query = []; parse_str(parse_url(html_entity_decode(DesktopEditor::download_url($format)), PHP_URL_QUERY), $query);
            if ($query['action'] !== 'wp_olympiad_download_editor' || !wp_verify_nonce($query['_wpnonce'], 'wpo_download_editor_' . $format)) return false;
        }
        $_POST = $_REQUEST = ['path' => $root, 'type' => 'local', 'nonce' => 'invalid'];
        $invoke([DesktopEditor::class, 'ajax_sync']);
        if (is_file($root . '/import/QuestionStudio.exe')) return false;
        $_POST = $_REQUEST = ['path' => $root, 'type' => 'local', 'nonce' => wp_create_nonce('wp_olympiad_admin_nonce')];
        $allowed = json_decode($invoke([DesktopEditor::class, 'ajax_sync']), true);
        return !empty($allowed['success']) && !empty($allowed['data']['success']) && $allowed['data']['written'] === 2;
    } finally {
        remove_filter('wp_die_handler', $handler, PHP_INT_MAX); remove_filter('wp_die_ajax_handler', $handler, PHP_INT_MAX);
        wp_set_current_user($old_uid); $_GET = $old_get; $_POST = $old_post; $_REQUEST = $old_request;
        $editor_cleanup($root, [$config]);
    }
});

$it('Редактор ПК: админка, скачивания, импорт-модалка, статус и обновление кеша JS/CSS', function (): bool {
    $quiz_id = Quiz::create(['title' => 'Редактор / UI', 'status' => 'publish']);
    $quiz = Quiz::get($quiz_id); $questions = [];
    try {
        ob_start(); require WP_OLYMPIAD_PATH . 'admin/views/questions-builder.php'; $html = ob_get_clean();
        if (substr_count($html, 'class="wpo-desktop-editor-panel') !== 2 || substr_count($html, 'wp_olympiad_download_editor') < 6) return false;
        if (str_contains($html, 'wpo-open-export-modal-btn') || str_contains($html, 'wpo-export-questions-modal')) return false;
        if (!str_contains($html, 'Скачать программу (.exe)') || !str_contains($html, 'aria-live="polite"')) return false;
        ob_start(); require WP_OLYMPIAD_PATH . 'admin/views/quiz-edit.php'; $settings = ob_get_clean();
        if (!str_contains($settings, 'wpo-editor-sync') || !str_contains($settings, 'Windows 10/11 x64')) return false;
        if (str_contains($html . $settings, 'wpo-btn-deploy-network-templates') || !str_contains(html_entity_decode($html), 'download_question_template&format=xlsx')) return false;
        $admin = file_get_contents(WP_OLYMPIAD_PATH . 'admin/class-admin.php');
        return str_contains($admin, "filemtime(WP_OLYMPIAD_PATH . 'admin/js/admin.js')")
            && str_contains($admin, "filemtime(WP_OLYMPIAD_PATH . 'admin/css/admin.css')")
            && str_contains(file_get_contents(WP_OLYMPIAD_PATH . 'admin/js/admin.js'), "action: 'wp_olympiad_sync_desktop_editor'");
    } finally { Quiz::delete($quiz_id); }
});
