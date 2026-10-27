<?php
/** XLSX-only sample distribution regressions, included by test-suite.php. */
namespace WPOlympiad\Tests;
use WPOlympiad\NetworkImport;
use WPOlympiad\NetworkExport;
use WPOlympiad\Quiz;
use WPOlympiad\DesktopEditor;

if (!defined('ABSPATH') || !isset($it)) { return; }
$pkg_removed = ['questions.csv', 'questions.json', 'questions_template.csv', 'questions_template.json', 'questions_template_macros.xlsm'];
$pkg_assert = static function (bool $ok, string $message): void { if (!$ok) throw new \RuntimeException($message); };
$pkg_root = static function (): string { $p = sys_get_temp_dir() . '/wpo_xlsx_pkg_' . wp_generate_password(12, false, false); wp_mkdir_p($p . '/import'); return $p; };
$pkg_cleanup = static function (string $p): void { exec('rm -rf ' . escapeshellarg($p)); };

$it('Комплект шаблонов: только XLSX и инструкция; пять удалённых файлов не поставляются', function () use ($pkg_removed, $pkg_assert): bool {
    $names = array_values(array_diff(scandir(WP_OLYMPIAD_PATH . 'templates'), ['.', '..'])); sort($names);
    $pkg_assert($names === ['README_IMPORT.txt', 'questions_template.xlsx'], 'Unexpected bundled template files');
    foreach ($pkg_removed as $name) {
        $pkg_assert(!file_exists(WP_OLYMPIAD_PATH . 'templates/' . $name) && !file_exists(WP_OLYMPIAD_PATH . 'import/' . $name), 'Removed sample remains: ' . $name);
    }
    $builder = (string)file_get_contents(WP_OLYMPIAD_PATH . 'admin/views/questions-builder.php');
    foreach (['csv', 'json', 'xlsm'] as $format) {
        $pkg_assert(!str_contains($builder, 'download_question_template&format=' . $format), 'Removed download link remains: ' . $format);
    }
    $pkg_assert(str_contains($builder, 'download_question_template&format=xlsx'), 'XLSX download link missing');
    foreach (DesktopEditor::manifest() as $format => $asset) { $pkg_assert(DesktopEditor::asset($format)['sha256'] === $asset['sha256'], 'Bundled editor asset changed'); }
    return true;
});

$it('Шаблон: старое размещение отключено и не изменяет существующие файлы import', function () use ($pkg_root, $pkg_cleanup, $pkg_removed, $pkg_assert): bool {
    $root = $pkg_root();
    try {
        $config = ['type' => 'local', 'path' => $root . '/import'];
        $first = NetworkExport::deploy_import_templates_result($config);
        $pkg_assert(empty($first['success']) && ($first['files'] ?? null) === [] && str_contains($first['message'], 'отключено'), 'Legacy deploy is not explicitly disabled');
        $pkg_assert(count(array_diff(scandir($root . '/import'), ['.', '..'])) === 0 && !is_dir($root . '/import/import'), 'Legacy deploy created files');
        $originals = [];
        foreach (array_merge($pkg_removed, ['README_IMPORT.txt', 'questions_template.xlsx', 'README_EDITOR.txt', 'EDITOR_LICENSES.txt']) as $name) {
            $data = 'Пользовательский файл ' . $name; file_put_contents($root . '/import/' . $name, $data); $originals[$name] = $data;
        }
        $message = null;
        $pkg_assert(!NetworkExport::deploy_import_templates($config, $message) && str_contains((string)$message, 'отключено'), 'Legacy wrapper reports success');
        foreach ($originals as $name => $data) { $pkg_assert(file_get_contents($root . '/import/' . $name) === $data, 'Old deploy changed a user file: ' . $name); }
        return true;
    } finally { $pkg_cleanup($root); }
});

$it('Комплект шаблонов: старые CSV/JSON/XLSM-ссылки дают 410; нет пустого успешного XLSX при повреждении', function () use ($pkg_root, $pkg_cleanup, $pkg_assert): bool {
    $old_user = get_current_user_id(); $root = $pkg_root(); $captured = [];
    $die = static function ($message = '', $title = '', $args = []) use (&$captured): void {
        $captured = ['message' => (string)$message, 'args' => $args]; throw new \RuntimeException('wpo-pkg-expected-stop');
    };
    $handler = static function () use ($die) { return $die; };
    add_filter('wp_die_handler', $handler, PHP_INT_MAX); add_filter('wp_die_ajax_handler', $handler, PHP_INT_MAX);
    $call = static function (string $format) use (&$captured): array {
        $captured = []; ob_start();
        try { NetworkImport::download_template($format); throw new \RuntimeException('Unexpected return'); }
        catch (\RuntimeException $e) { if ($e->getMessage() !== 'wpo-pkg-expected-stop') throw $e; }
        finally { ob_end_clean(); }
        return $captured;
    };
    $path = WP_OLYMPIAD_PATH . 'templates/questions_template.xlsx'; $saved = $path . '.qa-hold';
    try {
        $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']); wp_set_current_user((int)$admins[0]);
        foreach (['csv', 'json', 'xlsm', '../wp-config.php'] as $format) {
            $result = $call($format); $pkg_assert(($result['args']['response'] ?? 0) === 410, 'Removed format still downloadable: ' . $format);
        }
        $pkg_assert(NetworkImport::get_template_xlsm() === '', 'Removed XLSM accessor still serves a file');
        if (!rename($path, $saved)) throw new \RuntimeException('Cannot hold XLSX fixture');
        $res = NetworkExport::deploy_import_templates_result(['type' => 'local', 'path' => $root]);
        $pkg_assert(empty($res['success']) && count(array_diff(scandir($root . '/import'), ['.', '..'])) === 0, 'Missing XLSX reported a partial success');
        $result = $call('xlsx'); $pkg_assert(($result['args']['response'] ?? 0) === 503, 'Missing XLSX was offered as an empty download');
        rename($saved, $path);
        wp_set_current_user(0); $result = $call('xlsx'); $pkg_assert(($result['args']['response'] ?? 0) === 403, 'Anonymous XLSX download is not protected');
        return true;
    } finally {
        if (is_file($saved)) rename($saved, $path);
        wp_set_current_user($old_user); remove_filter('wp_die_handler', $handler, PHP_INT_MAX); remove_filter('wp_die_ajax_handler', $handler, PHP_INT_MAX); $pkg_cleanup($root);
    }
});

$it('Комплект шаблонов: пользовательские questions.csv / questions.json по-прежнему импортируются и экспортируются', function () use ($pkg_root, $pkg_cleanup, $pkg_assert): bool {
    $root = $pkg_root();
    $id = Quiz::create(['title' => 'Поддержка своих CSV/JSON', 'settings' => ['network_folder_type' => 'local', 'network_folder_path' => $root]]);
    try {
        $csv = NetworkImport::get_template_csv(); $json = NetworkImport::get_template_json();
        file_put_contents($root . '/import/questions.csv', $csv); file_put_contents($root . '/import/questions.json', $json);
        $deploy = NetworkExport::deploy_import_templates_result(['type' => 'local', 'path' => $root]);
        $pkg_assert(empty($deploy['success']) && file_get_contents($root . '/import/questions.csv') === $csv && file_get_contents($root . '/import/questions.json') === $json, 'Disabled template placement changed genuine inputs');
        foreach (['questions.csv', 'questions.json'] as $name) {
            $import = NetworkImport::import_from_network($id, 'import/' . $name, 'replace');
            $pkg_assert(!empty($import['success']) && $import['imported_count'] === 13, 'User input format disabled: ' . $name);
        }
        $out = NetworkExport::export_quiz($id, ['type' => 'local', 'path' => $root]);
        // 1.0.16: выгрузка ответов больше не пишет задания, но снимает старые файлы «вопросы/»
        $pkg_assert(!empty($out['success']) && !is_dir($root . '/вопросы') && count(glob($root . '/протоколы/*.csv')) === 1, 'Answer export stopped working after the questions export was removed');
        $pkg_assert(file_get_contents($root . '/import/questions.csv') === $csv && file_get_contents($root . '/import/questions.json') === $json, 'Import files were touched by the retirement');
        wp_mkdir_p($root . '/вопросы');
        file_put_contents($root . '/вопросы/questions.csv', 'старый экспорт CSV');
        file_put_contents($root . '/вопросы/questions.json', 'старый экспорт JSON');
        $retire = NetworkExport::export_quiz($id, ['type' => 'local', 'path' => $root]);
        $pkg_assert(!empty($retire['success']) && !file_exists($root . '/вопросы/questions.csv') && !file_exists($root . '/вопросы/questions.json') && !is_dir($root . '/вопросы'), 'Legacy question aliases survived the retirement');
        $pkg_assert(file_get_contents($root . '/import/questions.csv') === $csv, 'Retirement reached import folder');
        return count(Quiz::get($id)->get_questions(false)) === 13;
    } finally { Quiz::delete($id); $pkg_cleanup($root); }
});


$it('Шаблон / WebDAV: устаревшее размещение не отправляет сетевые запросы', function () use ($pkg_assert): bool {
    $calls = [];
    $mock = static function ($pre, $args, $url) use (&$calls) {
        if (!str_contains($url, 'xlsx-template-qa.invalid')) return $pre;
        $calls[] = ['url' => $url, 'method' => $args['method']];
        return ['response' => ['code' => 201, 'message' => 'QA'], 'headers' => [], 'body' => '', 'cookies' => []];
    };
    try {
        add_filter('pre_http_request', $mock, 10, 3);
        $config = ['type' => 'webdav', 'path' => 'https://xlsx-template-qa.invalid/share', 'user' => 'teacher', 'password' => 'fixture-only'];
        $result = NetworkExport::deploy_import_templates_result($config);
        $pkg_assert(empty($result['success']) && str_contains($result['message'], 'отключено'), 'Disabled WebDAV deployment reported success');
        return $calls === [];
    } finally { remove_filter('pre_http_request', $mock, 10); }
});
