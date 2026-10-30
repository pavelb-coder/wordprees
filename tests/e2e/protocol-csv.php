<?php
/**
 * Протокол олимпиады в stdout (тот же код, что отдаёт кнопку «Скачать CSV»).
 * Запуск: wp eval-file .../protocol-csv.php <quiz_id> --allow-root
 */

$quiz_id = (int)($args[0] ?? 0);
if ($quiz_id <= 0 || !class_exists('\\WPOlympiad\\Export')) {
    echo json_encode(['error' => 'usage: protocol-csv.php <quiz_id>']), "\n";
    return;
}

$csv = \WPOlympiad\Export::export_quiz_attempts($quiz_id, false);
echo (string)$csv;
