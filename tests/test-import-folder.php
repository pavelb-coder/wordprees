<?php
declare(strict_types=1);

namespace WPOlympiad\Tests;

use WPOlympiad\Ajax;
use WPOlympiad\DesktopEditor;
use WPOlympiad\NetworkExport;
use WPOlympiad\Quiz;

if (!defined('ABSPATH') || !isset($it)) return;

$it('import: новый комплект содержит два файла, старые инструкции, шаблоны и данные сохраняются', function (): bool {
    $root = sys_get_temp_dir() . '/wpo_import_keep_' . wp_generate_password(10, false, false);
    wp_mkdir_p($root . '/import/свои-файлы');
    $preserve = [
        'README_EDITOR.txt' => 'Моя изменённая инструкция редактора',
        'EDITOR_LICENSES.txt' => 'Моя копия лицензий',
        'question-studio-windows-1.0.0.zip' => 'Сохранённый старый архив',
        'README_IMPORT.txt' => 'Моя инструкция к заданиям',
        'questions_template.xlsx' => 'Моя заполненная книга',
        'Мои вопросы.csv' => 'Вопросы организатора',
        'Проект.wpoq' => 'Черновик организатора',
        'свои-файлы/документ.txt' => 'Личные материалы',
    ];
    foreach ($preserve as $name => $bytes) file_put_contents($root . '/import/' . $name, $bytes);
    $config = ['type' => 'local', 'path' => $root];
    $id = Quiz::create(['title' => 'Import keep fixture', 'status' => 'publish']);
    try {
        $first = DesktopEditor::deploy($config);
        if (empty($first['success']) || $first['written'] !== 2 || $first['files'] !== ['import/QuestionStudio.exe', 'import/question-studio-source-1.0.0.zip']) return false;
        foreach (['exe', 'source'] as $format) {
            $asset = DesktopEditor::asset($format);
            if (!hash_equals($asset['sha256'], hash_file('sha256', $root . '/import/' . $asset['name']))) return false;
        }
        $again = DesktopEditor::deploy($config, true);
        if (empty($again['success']) || $again['written'] !== 0) return false;
        $connection = NetworkExport::test_connection($config);
        if (empty($connection['success']) || empty($connection['editor']['success'])) return false;
        $export = NetworkExport::export_quiz($id, $config);
        if (empty($export['success'])) return false;
        $disabled = NetworkExport::deploy_import_templates_result($config);
        if (!empty($disabled['success'])) return false;
        foreach ($preserve as $name => $bytes) {
            if (!is_file($root . '/import/' . $name) || file_get_contents($root . '/import/' . $name) !== $bytes) return false;
        }
        return !is_dir($root . '/import/import') && empty(glob($root . '/import/.wpo-editor-*'));
    } finally {
        Quiz::delete($id);
        $key = DesktopEditor::state_key($config); delete_option($key); delete_option($key . '_lock');
        exec('rm -rf ' . escapeshellarg($root));
    }
});

$it('import / WebDAV: прежний кеш четырёх файлов обновляется двумя PUT без удаления', function (): bool {
    $config = ['type' => 'webdav', 'path' => 'https://two-import.invalid/share', 'user' => 'teacher', 'password' => 'dummy-only'];
    $legacy_manifest = DesktopEditor::manifest();
    // Exact metadata from the 1.0.11 bundle before the guide was revised.
    $legacy_manifest['readme']['size'] = 3445;
    $legacy_manifest['readme']['sha256'] = '373f8b118686b0a18690e50585a76da660b6030cfa248ebf30b427af1554546a';
    $old = hash('sha256', DesktopEditor::VERSION . wp_json_encode($legacy_manifest));
    if ($old === DesktopEditor::bundle_id()) return false;
    $key = DesktopEditor::state_key($config);
    update_option($key, ['success' => true, 'bundle' => $old, 'checked_at' => time()], false);
    $calls = [];
    $mock = static function ($pre, $args, $url) use (&$calls) {
        if (!str_starts_with($url, 'https://two-import.invalid/share')) return $pre;
        $calls[] = [$args['method'], $url];
        return ['response' => ['code' => $args['method'] === 'MKCOL' ? 405 : 201, 'message' => 'QA'], 'headers' => [], 'body' => '', 'cookies' => []];
    };
    try {
        add_filter('pre_http_request', $mock, 10, 3);
        $result = DesktopEditor::deploy($config);
        if (empty($result['success']) || $result['written'] !== 2 || !empty($result['cached'])) return false;
        $expected = [
            ['MKCOL', 'https://two-import.invalid/share/import/'],
            ['PUT', 'https://two-import.invalid/share/import/QuestionStudio.exe'],
            ['PUT', 'https://two-import.invalid/share/import/question-studio-source-1.0.0.zip'],
        ];
        if ($calls !== $expected) return false;
        $again = DesktopEditor::deploy($config);
        return !empty($again['cached']) && $again['written'] === 0 && $calls === $expected;
    } finally { remove_filter('pre_http_request', $mock, 10); delete_option($key); delete_option($key . '_lock'); }
});

$it('import: прежний AJAX размещения шаблона сообщает об отключении и не создаёт файлы', function (): bool {
    $root = sys_get_temp_dir() . '/wpo_import_ajax_' . wp_generate_password(10, false, false);
    wp_mkdir_p($root . '/import');
    file_put_contents($root . '/import/README_IMPORT.txt', 'не менять');
    $old_user = get_current_user_id(); $old_post = $_POST; $old_request = $_REQUEST;
    $die = static function (): void { throw new \RuntimeException('wpo-import-expected-stop'); };
    $handler = static function () use ($die) { return $die; };
    add_filter('wp_die_ajax_handler', $handler, PHP_INT_MAX);
    try {
        $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
        wp_set_current_user((int)$admins[0]);
        $_POST = $_REQUEST = ['path' => $root, 'type' => 'local', 'nonce' => wp_create_nonce('wp_olympiad_admin_nonce')];
        ob_start();
        try { Ajax::ajax_deploy_network_templates(); return false; }
        catch (\RuntimeException $e) { if ($e->getMessage() !== 'wpo-import-expected-stop') throw $e; }
        finally { $body = ob_get_clean(); }
        $result = json_decode((string)$body, true);
        return is_array($result) && empty($result['success']) && str_contains($result['data']['message'] ?? '', 'отключено')
            && array_values(array_diff(scandir($root . '/import'), ['.', '..'])) === ['README_IMPORT.txt']
            && file_get_contents($root . '/import/README_IMPORT.txt') === 'не менять';
    } finally {
        remove_filter('wp_die_ajax_handler', $handler, PHP_INT_MAX);
        wp_set_current_user($old_user); $_POST = $old_post; $_REQUEST = $old_request;
        exec('rm -rf ' . escapeshellarg($root));
    }
});
