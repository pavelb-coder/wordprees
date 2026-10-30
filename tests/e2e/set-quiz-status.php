<?php
/**
 * Публикация/снятие олимпиады (регресс контракта 1.0.20).
 * Запуск: wp eval-file .../set-quiz-status.php <quiz_id> <publish|draft> --allow-root
 */

global $wpdb;

$quiz_id = (int)($args[0] ?? 0);
$status = in_array((string)($args[1] ?? ''), ['publish', 'draft'], true) ? (string)$args[1] : 'publish';

if ($quiz_id <= 0 || !class_exists('\\WPOlympiad\\DB')) {
    echo json_encode(['error' => 'usage: set-quiz-status.php <quiz_id> <publish|draft>']), "\n";
    return;
}

$wpdb->update(\WPOlympiad\DB::quizzes_table(), ['status' => $status], ['id' => $quiz_id], ['%s'], ['%d']);
\WPOlympiad\Quiz::clear_cache($quiz_id);

echo json_encode(['ok' => true, 'quiz_id' => $quiz_id, 'status' => $status]), "\n";
