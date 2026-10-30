<?php
/**
 * Фикстура для e2e-resume-draft.py (1.0.22): олимпиада с анкетой, двумя группами
 * и постраничным выводом. Печатает JSON: quiz_id, page_id, token, questions.
 *
 * Запуск: wp eval-file wp-content/plugins/wp-olympiad/tests/e2e/make-quiz.php --allow-root
 */

global $wpdb;

if (!class_exists('\\WPOlympiad\\Quiz')) {
    echo json_encode(['error' => 'plugin_inactive']), "\n";
    return;
}

$quizzes = \WPOlympiad\DB::quizzes_table();
$attempts = \WPOlympiad\DB::attempts_table();
$responses = \WPOlympiad\DB::responses_table();
$questions_t = \WPOlympiad\DB::questions_table();

// Старые фикстуры этой же проверки удаляем вместе с их попытками: тесты должны
// быть перезапускаемыми, а чужие данные стенда здесь не живут.
$old_ids = array_map('intval', (array)$wpdb->get_col("SELECT id FROM {$quizzes} WHERE title LIKE 'E2E resume %'"));
foreach ($old_ids as $old_id) {
    $attempt_ids = array_map('intval', (array)$wpdb->get_col($wpdb->prepare("SELECT id FROM {$attempts} WHERE quiz_id = %d", $old_id)));
    foreach ($attempt_ids as $aid) {
        $wpdb->delete($responses, ['attempt_id' => $aid], ['%d']);
    }
    $wpdb->delete($attempts, ['quiz_id' => $old_id], ['%d']);
    $wpdb->delete($questions_t, ['quiz_id' => $old_id], ['%d']);
    $wpdb->delete($quizzes, ['id' => $old_id], ['%d']);
}
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->posts} WHERE post_type = 'page' AND post_title LIKE %s", 'E2E resume page %'));

$token = strtolower(wp_generate_password(6, false, false));

$quiz_id = \WPOlympiad\Quiz::create([
    'title' => 'E2E resume ' . $token,
    'status' => 'publish',
    'settings' => [
        'allow_guest' => 1,
        'enable_intro_page' => 1,
        'show_email' => 1,
        'require_email' => 1,
        'field_name_required' => 1,
        'max_attempts' => 0,
        'time_limit' => 60,
        'shuffle_questions' => 0,
        'shuffle_answers' => 0,
        'questions_per_page' => 2,
        'show_results_mode' => 'manual',
        'anti_cheat_popup_warnings' => 0,
        'network_auto_export' => 0,
        'webhook_url' => '',
        'question_groups' => [
            ['name' => 'Теория', 'title' => 'Раздел «Теория»', 'instruction' => 'Сначала теория.', 'time_limit' => 0, 'position' => 1],
            ['name' => 'Практика', 'title' => 'Раздел «Практика»', 'instruction' => 'Практика за 1 минуту.', 'time_limit' => 1, 'position' => 2],
        ],
        'custom_fields' => [
            ['id' => 'klass', 'type' => 'select', 'label' => 'Класс', 'options' => ['7', '8', '9'], 'required' => 1],
            ['id' => 'note', 'type' => 'text', 'label' => 'Комментарий'],
        ],
    ],
]);

$choices = [
    'choices' => [
        ['id' => 'c1', 'text' => 'верно', 'is_correct' => 1],
        ['id' => 'c2', 'text' => 'неверно', 'is_correct' => 0],
    ],
];

$defs = [
    ['Т1', 'Теория', 3],
    ['Т2', 'Теория', 4],
    ['П1', 'Практика', 2],
    ['П2', 'Практика', 2],
];

$made = [];
foreach ($defs as $i => $def) {
    [$title, $group, $points] = $def;
    $made[] = [
        'id' => (int)\WPOlympiad\Question::create([
            'quiz_id' => $quiz_id,
            'question_type' => 'single',
            'title' => $title . ' ' . $token,
            'points' => $points,
            'sort_order' => $i,
            'options' => $choices + ['group_name' => $group],
        ]),
        'title' => $title,
        'group' => $group,
        'points' => $points,
    ];
}

$page_id = (int)wp_insert_post([
    'post_title' => 'E2E resume page ' . $token,
    'post_content' => '[wp_olympiad id="' . (int)$quiz_id . '"]',
    'post_status' => 'publish',
    'post_type' => 'page',
]);

echo json_encode([
    'quiz_id' => (int)$quiz_id,
    'page_id' => $page_id,
    'token' => $token,
    'email' => 'e2e-' . $token . '@example.invalid',
    'name' => 'Участник ' . $token,
    'questions' => $made,
], JSON_UNESCAPED_UNICODE), "\n";
