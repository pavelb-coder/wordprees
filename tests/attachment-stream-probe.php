<?php
/**
 * Дочерний процесс для проверки закрытой отдачи файла вложения.
 * Attachments::stream() завершает запрос через exit, поэтому успешную
 * отдачу нельзя проверить внутри тест-сьюта — только отдельным PHP.
 *
 * Аргументы argv[1..]: путь wp-config, rel, id олимпиады, токен (или "-"), 'stdout'.
 */

define('SHORTINIT', false);
$wp_config = $argv[1] ?? '';
if (!is_file($wp_config)) {
    fwrite(STDERR, "нет wp-config: {$wp_config}\n");
    exit(9);
}
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require_once $wp_config;

use WPOlympiad\RestApi;

$rel = (string)($argv[2] ?? '');
$quiz_id = (int)($argv[3] ?? 0);
$token = (string)($argv[4] ?? '-');
$to_stdout = in_array('stdout', $argv, true);

$abs = \WPOlympiad\Attachments::resolve(\WPOlympiad\Quiz::get($quiz_id), $rel);
if ($to_stdout && !is_wp_error($abs)) {
    // Тот же путь к данным, что отдаёт участник: проверяем содержимое.
    $fp = fopen($abs, 'rb');
    if ($fp) {
        fpassthru($fp);
        fclose($fp);
    }
    // Служебная строка: её сравнение не портят PHP-предупреждения и переводы строк.
    fwrite(STDOUT, "\nWPO-PROBE bytes=" . filesize($abs) . " sha256=" . hash_file('sha256', $abs) . "\n");
    exit(0);
}

$req = new WP_REST_Request('GET', RestApi::NAMESPACE . '/attachment-file');
$req->set_param('rel', $rel);
$req->set_param('quiz_id', (string)$quiz_id);
$req->set_param('name', 'скан.pdf');
if ($token !== '-') {
    $req->set_param('token', $token);
}
$res = RestApi::handle_attachment_file($req);
if (is_wp_error($res)) {
    fwrite(STDOUT, 'WP_Error:' . $res->get_error_code() . "\n");
    exit(1);
}
// Обработчик обязан завершить вывод потоком — сюда мы не доходим.
fwrite(STDOUT, "returned:" . gettype($res) . "\n");
exit(2);
