<?php
/**
 * Диагностика доставки вложений участников в сетевую папку (плагин 1.0.38–1.0.41).
 * Ничего не меняет: только читает настройки и состояние.
 *
 * Запуск на вашем сервере (из каталога WordPress):
 *   wp eval-file wp-content/plugins/wp-olympiad/tests/diagnose-attachments.php
 * Только по одной олимпиаде:
 *   WPO_DIAG_QUIZ=123 wp eval-file wp-content/plugins/wp-olympiad/tests/diagnose-attachments.php
 * Скрипт только читает: ничего не создаёт, не удаляет и не меняет.
 * wp-cli нужен — из браузера он не запускается. Вывод приложите целиком.
 */
use WPOlympiad\Attachments;
use WPOlympiad\Quiz;
use WPOlympiad\NetworkExport;

if (!class_exists('WPOlympiad\\Attachments')) {
    $main = WP_PLUGIN_DIR . '/wp-olympiad/wp-olympiad.php';
    if (is_file($main)) { require_once $main; }
}

$ver = defined('WP_OLYMPIAD_VERSION') ? WP_OLYMPIAD_VERSION : 'неизвестна';
echo 'Плагин: ' . $ver . PHP_EOL;
echo 'PHP: ' . PHP_VERSION . ', ос: ' . PHP_OS . PHP_EOL;
echo 'smbclient: ' . var_export(NetworkExport::get_smbclient_binary(), true) . PHP_EOL;
$disabled = (string)ini_get('disable_functions');
echo 'disable_functions содержит exec/proc_open?: '
    . (preg_match('~\b(exec|shell_exec|proc_open|popen|system)\b~', $disabled) ? 'ДА — транспорт не сможет запуститься' : 'нет')
    . ' (значения: ' . ($disabled !== '' ? $disabled : 'пусто') . ')' . PHP_EOL;
echo 'open_basedir: ' . (ini_get('open_basedir') ?: 'не задан') . PHP_EOL;
echo 'рабочая папка вложений: ' . Attachments::SPOOL . ' внутри wp-content, доступна для записи: '
    . (is_writable(WP_CONTENT_DIR) ? 'да' : 'НЕТ') . PHP_EOL;
echo str_repeat('-', 72) . PHP_EOL;

$arg = (string)getenv('WPO_DIAG_QUIZ');
if ($arg === '' && isset($GLOBALS['argv'])) {
    $arg = implode(' ', (array)$GLOBALS['argv']);
    $arg = preg_match('~--quiz=(\d+)~', $arg, $mm) ? $mm[1] : '';
}
$only = (int)$arg;

$quizzes = $only ? array_filter([Quiz::get($only)]) : Quiz::all(['number' => 100]);
if (!$quizzes) {
    echo 'Олимпиад не найдено.' . PHP_EOL;
    return;
}

$spool_root = rtrim(wp_normalize_path(WP_CONTENT_DIR), '/') . '/' . Attachments::SPOOL;
foreach ($quizzes as $quiz) {
    if (!$quiz instanceof Quiz) { continue; }
    $store = Attachments::store($quiz);
    $pending = Attachments::pending($quiz);
    $marks = Attachments::delivered_marks($quiz);
    $probe = Attachments::probe_state($quiz);
    $retry = Attachments::retry_state($quiz);
    $limits = Attachments::limits($quiz);

    $with_files = 0;
    foreach ((array)$quiz->get_questions(false) as $q) {
        if ((string)($q->question_type ?? '') === Attachments::TYPE) { $with_files++; }
    }

    echo 'Олимпиада #' . (int)$quiz->id . ' «' . $quiz->title . '» (статус ' . $quiz->status . ')' . PHP_EOL;
    echo '  заданий «прикрепить файл»: ' . $with_files . ($with_files === 0 ? '  ← ни одно задание не принимает файлы, вложений и не будет' : '') . PHP_EOL;
    echo '  путь в настройках: ' . var_export((string)$quiz->get_setting('network_folder_path', ''), true)
        . ', тип: ' . var_export((string)$quiz->get_setting('network_folder_type', ''), true) . PHP_EOL;
    echo '  режим хранения: ' . $store['mode'] . ' (транспорт ' . $store['type'] . ', папка на шаре: '
        . (string)($store['share'] !== '' ? $store['share'] : ('через ' . $store['type'] . ' в ' . $store['slug'])) . ')' . PHP_EOL;
    if ((string)$store['note'] !== '') { echo '  заметка: ' . $store['note'] . PHP_EOL; }

    echo '  проба папки вложений: ' . ($probe['when'] > 0
        ? ($probe['ok'] ? 'ok' : 'ПРОВАЛ') . ', ' . date('Y-m-d H:i', (int)$probe['when'])
            . ($probe['message'] !== '' ? ' — ' . $probe['message'] : '')
            . ($probe['codes'] ? ' [' . implode(', ', $probe['codes']) . ']' : '')
            . ($probe['target'] !== '' ? '; цель: ' . $probe['target'] : '')
        : 'ещё не запускалась') . PHP_EOL;
    if ((string)$probe['raw'] !== '') { echo '    ответ сервера: ' . str_replace("\n", ' | ', mb_substr($probe['raw'], 0, 400)) . PHP_EOL; }

    echo '  недоставлено файлов: ' . count($pending) . PHP_EOL;
    foreach (array_slice($pending, 0, 5) as $p) {
        echo '    - ' . (string)($p['share'] ?? $p['rel'] ?? '?') . ' ← ' . (string)($p['error'] ?? 'причина не записана')
            . (empty($p['when']) ? '' : (' (последняя попытка ' . (string)$p['when'] . ')')) . PHP_EOL;
    }
    if (count($pending) > 5) { echo '    … ещё ' . (count($pending) - 5) . PHP_EOL; }

    echo '  отметок «доставлено» в базе: ' . count($marks) . ' (при смене пути транспорта они перестают учитываться)' . PHP_EOL;
    echo '  автоматический повтор: ' . (empty($retry) ? 'не запланирован'
        : ('попыток ' . (int)($retry['tries'] ?? 0) . ', следующий через '
            . max(0, (int)($retry['next'] ?? 0) - time()) . ' c, задержка ' . (int)($retry['delay'] ?? 0) . ' c')) . PHP_EOL;
    echo '  событие планировщика wpo_attachments_retry поставлено: '
        . var_export((bool)wp_next_scheduled('wpo_attachments_retry', [(int)$quiz->id]), true) . PHP_EOL;
    echo '  последняя доставка: ' . wp_json_encode(array_slice(Attachments::last_delivery($quiz), 0, 3), JSON_UNESCAPED_UNICODE) . PHP_EOL;

    // Что реально лежит в рабочей папке сайта (spool).
    $dir = (string)($spool_root . '/' . (string)$store['slug']);
    if (is_dir($dir)) {
        $n = 0; $bytes = 0;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) { if ($f->isFile()) { $n++; $bytes += $f->getSize(); } }
        echo '  в рабочей папке сайта: файлов ' . $n . ' (' . Attachments::human_size($bytes) . ')' . PHP_EOL;
    } else {
        echo '  рабочей папки сайта для этой олимпиады нет: ' . $dir . ' (нет сданных работ с файлами?)' . PHP_EOL;
    }
    echo '  лимиты: ' . $limits['attachment_max_size_mb'] . ' МБ, ' . $limits['attachment_max_count']
        . ' файлов на задание; upload_max_filesize=' . ini_get('upload_max_filesize')
        . ', post_max_size=' . ini_get('post_max_size') . PHP_EOL;
    echo str_repeat('-', 72) . PHP_EOL;
}

echo "Как читать: «недоставлено > 0» — сервер отказывает, причина в строке выше;" . PHP_EOL;
echo "«проба = ПРОВАЛ» — папки вложений нет, доставку проводить некуда;" . PHP_EOL;
echo "«smbclient => NULL» — на сервере нечем возить UNC (apt-get install smbclient);" . PHP_EOL;
echo "«отметок доставлено > 0, а на шаре пусто» — смотрите не ту папку:" . PHP_EOL;
echo "файлы лежат в <путь из настройки>\\вложения\\<" . 'slug' . ">\\<Участник — ID>\\." . PHP_EOL;
