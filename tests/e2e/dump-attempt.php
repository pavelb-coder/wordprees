<?php
/**
 * Дамп попытки по токену: статус, баллы, черновик и ответы.
 * Запуск: wp eval-file .../dump-attempt.php <token> --allow-root
 */

$token = trim((string)($args[0] ?? ''));
if ($token === '' || !class_exists('\\WPOlympiad\\Attempt')) {
    echo json_encode(['error' => 'usage: dump-attempt.php <token>']), "\n";
    return;
}

$attempt = \WPOlympiad\Attempt::get_by_token($token);
if (!$attempt) {
    echo json_encode(['error' => 'not_found', 'token' => $token]), "\n";
    return;
}

$responses = [];
foreach ((array)$attempt->get_responses() as $row) {
    $responses[] = [
        'id' => (int)$row['id'],
        'question_id' => (int)$row['question_id'],
        'user_answer' => (string)$row['user_answer'],
        'is_correct' => $row['is_correct'] === null ? null : (int)$row['is_correct'],
        'awarded_points' => (int)round((float)$row['awarded_points']),
        'needs_manual_review' => (int)$row['needs_manual_review'],
        'review_comment' => (string)$row['review_comment'],
    ];
}

$draft = $attempt->draft();

echo json_encode([
    'id' => (int)$attempt->id,
    'quiz_id' => (int)$attempt->quiz_id,
    'token' => $attempt->token,
    'status' => $attempt->status,
    'status_label' => $attempt->get_status_label(),
    'score' => (int)$attempt->score,
    'max_score' => (int)$attempt->max_score,
    'started_at' => $attempt->started_at,
    'submitted_at' => $attempt->submitted_at,
    'tab_switches' => (int)$attempt->tab_switches,
    'participant_email' => $attempt->participant_email,
    'custom_fields' => $attempt->custom_field_values(),
    'groups_opened' => $attempt->groups_opened(),
    'question_order' => $attempt->question_order(),
    'draft' => $draft,
    'draft_updated_at' => $attempt->draft_updated_at,
    'draft_raw' => $attempt->draft_answers,
    'responses' => $responses,
], JSON_UNESCAPED_UNICODE), "\n";
