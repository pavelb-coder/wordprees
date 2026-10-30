<?php
/**
 * WP Olympiad Comprehensive Integration Test Suite
 * Run with: wp eval-file tests/test-suite.php --allow-root
 */

// declare(strict_types=1);

namespace WPOlympiad\Tests;

use WPOlympiad\DB;
use WPOlympiad\Quiz;
use WPOlympiad\Question;
use WPOlympiad\Attempt;
use WPOlympiad\Grader;
use WPOlympiad\AntiCheat;
use WPOlympiad\Shortcodes;
use WPOlympiad\Export;
use WPOlympiad\NetworkExport;
use WPOlympiad\NetworkImport;
use WP_REST_Request;
use WPOlympiad\RestApi;
use WPOlympiad\Style;
use WPOlympiad\ImportTemplate;
use WPOlympiad\Api;
use WPOlympiad\Webhook;

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WPOlympiad\Plugin')) {
    $main_file = dirname(__DIR__) . '/wp-olympiad.php';
    if (file_exists($main_file)) {
        require_once $main_file;
        \WPOlympiad\Plugin::instance();
    }
}

$passed = 0;
$failed = 0;
$total = 0;

$it = function (string $description, callable $fn) use (&$passed, &$failed, &$total): void {
    $total++;
    printf("Тест %2d: %-60s ", $total, $description);
    try {
        $result = $fn();
        if ($result === false) {
            $failed++;
            echo "\033[31m[FAIL]\033[0m\n";
        } else {
            $passed++;
            echo "\033[32m[OK]\033[0m\n";
        }
    } catch (\Throwable $e) {
        $failed++;
        echo "\033[31m[ERROR]\033[0m: " . $e->getMessage() . " (" . basename($e->getFile()) . ":" . $e->getLine() . ")\n";
    }
};


echo "\n=======================================================\n";
echo "    WP OLYMPIAD: СКВОЗНОЙ ИНТЕГРАЦИОННЫЙ ТЕСТ-СЬЮТ    \n";
echo "=======================================================\n\n";

// 1. Database Tables Check
$it("Таблицы БД созданы и существуют", function () {
    global $wpdb;
    $tables = [
        DB::quizzes_table(),
        DB::questions_table(),
        DB::attempts_table(),
        DB::responses_table(),
    ];
    foreach ($tables as $t) {
        $found = $wpdb->get_var("SHOW TABLES LIKE '{$t}'");
        if ($found !== $t) return false;
    }
    return true;
});

// 2. Create Olympiad Quiz
$quiz_id = 0;
$it("Создание олимпиады с параметрами", function () use (&$quiz_id) {
    $quiz_id = Quiz::create([
        'title'       => 'Олимпиада по информатике и математике 2026',
        'slug'        => 'olympiad-cs-math-2026',
        'description' => 'Всероссийская олимпиада для школьников 10-11 классов.',
        'status'      => 'publish',
        'settings'    => [
            'time_limit'        => 30, // 30 минут
            'max_attempts'      => 1,
            'shuffle_questions' => 0,
            'shuffle_answers'   => 0,
            'anti_cheat_blur'   => 1,
            'max_tab_switches'  => 3,
            'show_results_mode' => 'immediate',
        ],
    ]);
    return $quiz_id > 0;
});

$quiz = Quiz::get($quiz_id);

// 3. Question: Single Choice
$q1_id = 0;
$it("Создание вопроса: Один выбор (single) с баллами и штрафом", function () use ($quiz_id, &$q1_id) {
    $q1_id = Question::create([
        'quiz_id'       => $quiz_id,
        'question_type' => 'single',
        'title'         => 'Какова сложность поиска в сбалансированном бинарном дереве (AVL)?',
        'points'        => 2,
        'penalty'       => 1,
        'options'       => [
            'choices' => [
                ['id' => 'c1', 'text' => 'O(1)', 'is_correct' => 0],
                ['id' => 'c2', 'text' => 'O(log N)', 'is_correct' => 1],
                ['id' => 'c3', 'text' => 'O(N)', 'is_correct' => 0],
                ['id' => 'c4', 'text' => 'O(N log N)', 'is_correct' => 0],
            ],
            'explanation' => 'В сбалансированном дереве высота h = O(log N).',
        ],
    ]);
    return $q1_id > 0;
});

// 4. Question: Multiple Choice
$q2_id = 0;
$it("Создание вопроса: Множественный выбор (multi) с частичным начислением", function () use ($quiz_id, &$q2_id) {
    $q2_id = Question::create([
        'quiz_id'       => $quiz_id,
        'question_type' => 'multi',
        'title'         => 'Какие из чисел являются простыми?',
        'points'        => 3.0,
        'penalty'       => 0.0,
        'options'       => [
            'choices' => [
                ['id' => 'p1', 'text' => '2', 'is_correct' => 1],
                ['id' => 'p2', 'text' => '4', 'is_correct' => 0],
                ['id' => 'p3', 'text' => '17', 'is_correct' => 1],
                ['id' => 'p4', 'text' => '31', 'is_correct' => 1],
            ],
            'explanation' => '2, 17 и 31 делятся только на 1 и на себя.',
        ],
    ]);
    return $q2_id > 0;
});

// 5. Question: Number with tolerance
$q3_id = 0;
$it("Создание вопроса: Числовой ответ с погрешностью (tolerance)", function () use ($quiz_id, &$q3_id) {
    $q3_id = Question::create([
        'quiz_id'       => $quiz_id,
        'question_type' => 'number',
        'title'         => 'Вычислите значение числа Пи с точностью до двух знаков после запятой.',
        'points'        => 2.0,
        'penalty'       => 0.0,
        'options'       => [
            'target_value' => 3.14,
            'tolerance'    => 0.01,
            'explanation'  => 'Pi = 3.14159...',
        ],
    ]);
    return $q3_id > 0;
});

// 6. Question: Text with synonyms
$q4_id = 0;
$it("Создание вопроса: Краткий текст с синонимами и регистронезависимостью", function () use ($quiz_id, &$q4_id) {
    $q4_id = Question::create([
        'quiz_id'       => $quiz_id,
        'question_type' => 'text',
        'title'         => 'Назовите столицу Франции.',
        'points'        => 1.0,
        'penalty'       => 0.0,
        'options'       => [
            'answers'        => ['Париж', 'Paris'],
            'case_sensitive' => 0,
            'explanation'    => 'Столица Франции — Париж.',
        ],
    ]);
    return $q4_id > 0;
});

// 7. Question: Essay for manual review
$q5_id = 0;
$it("Создание вопроса: Эссе / Развёрнутое решение (essay)", function () use ($quiz_id, &$q5_id) {
    $q5_id = Question::create([
        'quiz_id'       => $quiz_id,
        'question_type' => 'essay',
        'title'         => 'Докажите, что простых чисел бесконечно много (доказательство Евклида).',
        'points'        => 5.0,
        'penalty'       => 0.0,
        'options'       => [
            'explanation' => 'Предположим конечность: P = p1*p2*...*pn + 1. P не делится ни на одно из pi, следовательно делится на новое простое число.',
        ],
    ]);
    return $q5_id > 0;
});

// 8. Security test: Question::get_public_data()
$it("Безопасность Anti-Cheat: get_public_data() удаляет правильные ответы", function () use ($q1_id, $q3_id, $q4_id) {
    $q1 = Question::get($q1_id);
    $pub1 = $q1->get_public_data(false);
    foreach ($pub1['choices'] as $c) {
        if (isset($c['is_correct'])) return false; // LEAK!
    }

    $q3 = Question::get($q3_id);
    $pub3 = $q3->get_public_data(false);
    if (isset($pub3['target_value']) || isset($pub3['tolerance'])) return false; // LEAK!

    $q4 = Question::get($q4_id);
    $pub4 = $q4->get_public_data(false);
    if (isset($pub4['answers'])) return false; // LEAK!

    return true;
});

// 9. Total points calculation
$it("Расчёт максимального балла олимпиады", function () use ($quiz) {
    $total = $quiz->get_total_points();
    // 2.0 + 3.0 + 2.0 + 1.0 + 5.0 = 13.0
    return abs($total - 13.0) < 0.001;
});

// 10. Date window check
$it("Проверка временного окна олимпиады (is_active_now)", function () use ($quiz) {
    $status = $quiz->is_active_now();
    if (!$status['active']) return false;

    // Simulate future date
    $tz = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
    $future = new \DateTimeImmutable('+2 hours', $tz);
    $quiz->settings['start_time'] = $future->format('Y-m-d H:i:s');
    $future_status = $quiz->is_active_now();
    if ($future_status['active']) return false;

    // Reset back
    $quiz->settings['start_time'] = '';
    return true;
});

// 11. Start Attempt & Token Generation
$attempt1 = null;
$it("Старт попытки (Attempt::start): генерация токена и времени", function () use ($quiz_id, &$attempt1) {
    $attempt1 = Attempt::start($quiz_id, [
        'name'  => 'Алексей Иванов',
        'email' => 'ivanov@example.com',
        'org'   => 'ФМЛ №239, 11 класс',
    ]);
    return $attempt1 && strlen($attempt1->token) === 32 && $attempt1->status === 'in_progress';
});

// 12. Max attempts limitation
$it("Контроль лимита попыток (can_user_attempt)", function () use ($quiz, $attempt1) {
    // Before submission: status is in_progress, can still submit
    // Simulate completed
    $attempt1->complete(10.0, 13.0, true, 'completed');
    $check = $quiz->can_user_attempt(0, 'ivanov@example.com');
    if ($check['allowed']) return false; // must NOT be allowed

    $check_other = $quiz->can_user_attempt(0, 'petrov@example.com');
    if (!$check_other['allowed']) return false; // other email allowed

    // Revert attempt1 to in_progress for further test
    global $wpdb;
    $wpdb->update(DB::attempts_table(), ['status' => 'in_progress'], ['id' => $attempt1->id]);
    $attempt1->status = 'in_progress';
    return true;
});

// 13. Anti-Cheat: Tab switch tracking
$it("Античит: фиксация переключения вкладок (record_tab_switch)", function () use ($attempt1) {
    $switches1 = $attempt1->record_tab_switch();
    $switches2 = $attempt1->record_tab_switch();
    return $switches1 === 1 && $switches2 === 2 && $attempt1->tab_switches === 2;
});

// 14. Anti-Cheat: Heartbeat & Disqualification on switch limit
$it("Античит: дисквалификация при превышении лимита переключений", function () use ($attempt1, $quiz) {
    // max_tab_switches = 3 in settings. Current switches = 2.
    // 3rd switch:
    $res1 = AntiCheat::process_heartbeat($attempt1, $quiz, true);
    if ($res1['disqualified']) return false; // 3 is allowed

    // 4th switch:
    $res2 = AntiCheat::process_heartbeat($attempt1, $quiz, true);
    if (!$res2['disqualified']) return false; // >3 is disqualified!

    // Reset switches for scoring test
    global $wpdb;
    $wpdb->update(DB::attempts_table(), ['tab_switches' => 1, 'status' => 'in_progress'], ['id' => $attempt1->id]);
    $attempt1->tab_switches = 1;
    $attempt1->status = 'in_progress';
    return true;
});

// 15. Server-Side Timer Validation
$it("Серверный таймер: проверка времени сдачи (validate_submission_time)", function () use ($attempt1, $quiz) {
    $val = AntiCheat::validate_submission_time($attempt1, $quiz);
    if (!$val['valid']) return false;

    // Simulate expired attempt (started 2 hours ago)
    global $wpdb;
    $old_time = date('Y-m-d H:i:s', time() - 7200);
    $wpdb->update(DB::attempts_table(), ['started_at' => $old_time], ['id' => $attempt1->id]);
    $attempt1->started_at = $old_time;

    $expired_val = AntiCheat::validate_submission_time($attempt1, $quiz);
    if ($expired_val['valid']) return false; // Must be invalid

    // Restore started_at
    $now = current_time('mysql');
    $wpdb->update(DB::attempts_table(), ['started_at' => $now], ['id' => $attempt1->id]);
    $attempt1->started_at = $now;
    return true;
});

// 16. Grader: Single Choice (Correct)
$it("Оценка (Grader): верный ответ single выбор (+2 б.)", function () use ($q1_id) {
    $q = Question::get($q1_id);
    $res = Grader::grade_question($q, 'c2');
    return $res['is_correct'] === 1 && $res['awarded_points'] === 2;
});

// 17. Grader: Single Choice (Wrong with penalty)
$it("Оценка (Grader): неверный ответ single выбор со штрафом (-1 б.)", function () use ($q1_id) {
    $q = Question::get($q1_id);
    $res = Grader::grade_question($q, 'c1');
    return $res['is_correct'] === 0 && $res['awarded_points'] === -1;
});

// 18. Grader: Multiple Choice (Full credit)
$it("Оценка (Grader): все верные варианты multi (+3 б.)", function () use ($q2_id) {
    $q = Question::get($q2_id);
    $res = Grader::grade_question($q, ['p1', 'p3', 'p4']);
    return $res['is_correct'] === 1 && $res['awarded_points'] === 3;
});

// 19. Grader: Multiple Choice (Partial credit with wrong answer)
$it("Оценка (Grader): пропорциональный балл multi при частичном ответе", function () use ($q2_id) {
    $q = Question::get($q2_id);
    // Correct are p1, p3, p4 (3 total).
    // User picked p1, p3 (2 correct, 0 wrong) -> (2 - 0)/3 * 3 = 2 pts
    $res = Grader::grade_question($q, ['p1', 'p3']);
    return $res['is_correct'] === 0 && $res['awarded_points'] === 2;
});

// 20. Grader: Number (Exact and within tolerance)
$it("Оценка (Grader): числовой ответ 3,1416 в пределах допуска ±0.01", function () use ($q3_id) {
    $q = Question::get($q3_id);
    // Russian format with comma "3,141"
    $res = Grader::grade_question($q, '3,141');
    if ($res['is_correct'] !== 1) return false;

    // Out of tolerance: "3.20"
    $res_wrong = Grader::grade_question($q, '3.20');
    if ($res_wrong['is_correct'] !== 0) return false;

    return true;
});

// 21. Grader: Text with Cyrillic synonyms
$it("Оценка (Grader): текстовый ответ «париж» (нижний регистр)", function () use ($q4_id) {
    $q = Question::get($q4_id);
    $res1 = Grader::grade_question($q, '  париж  ');
    if ($res1['is_correct'] !== 1) return false;

    $res2 = Grader::grade_question($q, 'PARIS');
    if ($res2['is_correct'] !== 1) return false;

    $res3 = Grader::grade_question($q, 'Берлин');
    if ($res3['is_correct'] !== 0) return false;

    return true;
});

// 22. Grader: Essay flags for manual review
$it("Оценка (Grader): эссе помечается needs_manual_review = true", function () use ($q5_id) {
    $q = Question::get($q5_id);
    $res = Grader::grade_question($q, 'Пусть простых чисел конечное множество...');
    return $res['needs_manual_review'] === true && $res['is_correct'] === null;
});

// 23. Full Grading & Responses Storage
$it("Полная проверка попытки (Grader::grade) и сохранение в БД", function () use ($quiz, $attempt1, $q1_id, $q2_id, $q3_id, $q4_id, $q5_id) {
    $user_answers = [
        $q1_id => 'c2',                     // +2
        $q2_id => ['p1', 'p3', 'p4'],       // +3
        $q3_id => '3.14',                   // +2
        $q4_id => 'Париж',                  // +1
        $q5_id => 'Подробное доказательство...', // essay (0 pending review)
    ];

    $grading = Grader::grade($quiz, $attempt1, $user_answers);
    Grader::save_responses($attempt1->id, $grading['responses']);

    $attempt1->complete(
        $grading['total_score'],
        $grading['max_score'],
        $grading['status']
    );

    // Auto score should be 8.0 / 13.0
    // Status should be needs_review because of essay
    return abs($attempt1->score - 8.0) < 0.001 && $attempt1->status === 'needs_review';
});

// 24. Manual Grading by Jury
$it("Ручная проверка жюри (Admin manual review): начисление баллов за эссе", function () use ($attempt1, $q5_id) {
    $responses = $attempt1->get_responses();
    $essay_resp = null;
    foreach ($responses as $r) {
        if ((int)$r['question_id'] === $q5_id) {
            $essay_resp = $r;
            break;
        }
    }
    if (!$essay_resp) return false;

    // Award 4 out of 5 points for essay
    global $wpdb;
    $wpdb->update(DB::responses_table(), [
        'awarded_points'      => 4,
        'is_correct'          => 1,
        'needs_manual_review' => 0,
        'review_comment'      => 'Отличное строгое доказательство, 4 б.',
    ], ['id' => $essay_resp['id']]);

    // Recalculate: 8 + 4 = 12 out of 13
    $new_score = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT SUM(awarded_points) FROM " . DB::responses_table() . " WHERE attempt_id = %d",
        $attempt1->id
    ));

    $attempt1->update_manual_review($new_score, 13, true);
    return $attempt1->score === 12 && !property_exists($attempt1, 'passed') && $attempt1->status === 'completed';
});

// 25. Shortcode Rendering
$it("Шорткод [wp_olympiad id=N] рендерит контейнер и форму", function () use ($quiz_id) {
    $html = do_shortcode('[wp_olympiad id="' . $quiz_id . '"]');
    if (!str_contains($html, 'wpo-quiz-wrapper')) return false;
    if (!str_contains($html, 'Олимпиада по информатике и математике 2026')) return false;
    if (!str_contains($html, 'ФИО Участника')) return false;
    if (!str_contains($html, 'wpo-btn-start')) return false;
    return true;
});

// 26. REST API /start endpoint
$it("REST API POST /wp-olympiad/v1/start", function () use ($quiz_id) {
    $req = new WP_REST_Request('POST', '/wp-olympiad/v1/start');
    $req->set_body_params([
        'quiz_id'           => $quiz_id,
        'participant_name'  => 'Дмитрий Медведев',
        'participant_email' => 'dmitry@example.org',
        'participant_org'   => 'Школа 1535',
    ]);

    $res = RestApi::handle_start($req);
    if (is_wp_error($res)) return false;
    $data = $res->get_data();
    return !empty($data['token']) && count($data['questions']) === 5;
});

// 29. REST API /heartbeat endpoint
$it("REST API POST /wp-olympiad/v1/heartbeat", function () use ($quiz_id) {
    $att = Attempt::start($quiz_id, ['name' => 'Тест Хартбит']);
    $req = new WP_REST_Request('POST', '/wp-olympiad/v1/heartbeat');
    $req->set_body_params([
        'token'        => $att->token,
        'tab_switched' => 1,
    ]);

    $res = RestApi::handle_heartbeat($req);
    if (is_wp_error($res)) return false;
    $data = $res->get_data();
    return $data['tab_switches'] === 1 && $data['status'] === 'ok';
});

// 30. REST API /submit endpoint
$it("REST API POST /wp-olympiad/v1/submit", function () use ($quiz_id, $q1_id, $q4_id) {
    $att = Attempt::start($quiz_id, ['name' => 'Тест Отправка']);
    $req = new WP_REST_Request('POST', '/wp-olympiad/v1/submit');
    $req->set_body_params([
        'token'   => $att->token,
        'answers' => [
            $q1_id => 'c2',
            $q4_id => 'Париж',
        ],
    ]);

    $res = RestApi::handle_submit($req);
    if (is_wp_error($res)) return false;
    $data = $res->get_data();
    return $data['success'] === true && $data['score'] >= 3.0;
});

// 31. Direct URL for external connection
$it("Внешнее подключение: генерация прямой ссылки (get_direct_url)", function () use ($quiz) {
    $url = $quiz->get_direct_url();
    return str_contains($url, 'wpo_runner=' . $quiz->id);
});

// 32. Embed Iframe Code generation
$it("Внешнее подключение: генерация кода iframe (get_embed_code)", function () use ($quiz) {
    $embed = $quiz->get_embed_code(850);
    return str_contains($embed, '<iframe') && str_contains($embed, 'height="850"') && str_contains($embed, 'wpo_runner=' . $quiz->id);
});

// 33. REST API CORS headers
$it("Внешнее подключение: CORS-заголовки для внешних приложений", function () {
    $req = new WP_REST_Request('GET', '/wp-olympiad/v1/leaderboard/1');
    $served = RestApi::add_cors_headers(false, null, $req);
    return $served === false; // filter returns original $served while emitting headers
});

// 34. Webhook Dispatch Payload validation
$it("Внешнее подключение: формирование и отправка вебхука (dispatch_webhook → Webhook)", function () use ($quiz, $attempt1) {
    $original_url = (string)$quiz->get_setting('webhook_url', '');

    $quiz->update_setting('webhook_url', 'https://webhook.site/mock-test');
    add_filter('wpo_webhook_spawn_cron', '__return_false');
    wp_unschedule_hook(Webhook::CRON_HOOK);
    Webhook::clear_log($quiz);

    $captured = false;
    $filter = function ($pre, $args, $url) use (&$captured, $attempt1) {
        if (str_contains($url, 'webhook.site')) {
            $body = json_decode((string)$args['body'], true);
            if ($body && $body['event'] === 'attempt_completed' && (int)($body['attempt']['id'] ?? -1) === (int)$attempt1->id) {
                $captured = true;
                return ['response' => ['code' => 200, 'message' => 'OK'], 'body' => 'ok', 'headers' => [], 'cookies' => [], 'filename' => null];
            }
        }
        return $pre;
    };
    add_filter('pre_http_request', $filter, 10, 3);

    // Старый вход в API теперь ставит событие в очередь WP-Cron.
    RestApi::dispatch_webhook('https://webhook.site/mock-test', $quiz, $attempt1, []);

    $args = null;
    foreach ((array)_get_cron_array() as $hooks) {
        foreach ((array)($hooks[Webhook::CRON_HOOK] ?? []) as $event) {
            if ((int)($event['args'][0] ?? 0) === $quiz->id) {
                $args = $event['args'];
            }
        }
    }

    $queued = is_array($args) && count($args) === 5;
    if ($queued) {
        do_action_ref_array(Webhook::CRON_HOOK, $args);
    }

    $log = Webhook::deliveries(Quiz::get($quiz->id));
    $delivered = $captured
        && !empty($log)
        && ($log[0]['ok'] ?? null) === true
        && ($log[0]['event'] ?? '') === 'attempt_completed';

    wp_unschedule_hook(Webhook::CRON_HOOK);
    remove_filter('pre_http_request', $filter, 10);
    remove_filter('wpo_webhook_spawn_cron', '__return_false');
    Webhook::clear_log(Quiz::get($quiz->id));
    $quiz->update_setting('webhook_url', $original_url);

    return $queued && $delivered;
});

// 35. Question: Dropdown Question Type
$q_drop_id = 0;
$it("Создание и оценка вопроса: Dropdown (выпадающий список)", function () use ($quiz_id, &$q_drop_id) {
    $q_drop_id = Question::create([
        'quiz_id'       => $quiz_id,
        'question_type' => 'dropdown',
        'title'         => 'Какой протокол работает на порту 443?',
        'points'        => 1.0,
        'options'       => [
            'choices' => [
                ['id' => 'd1', 'text' => 'HTTP', 'is_correct' => 0],
                ['id' => 'd2', 'text' => 'HTTPS', 'is_correct' => 1],
                ['id' => 'd3', 'text' => 'FTP', 'is_correct' => 0],
            ],
        ],
    ]);
    if ($q_drop_id <= 0) return false;
    $q = Question::get($q_drop_id);
    $res = Grader::grade_question($q, 'd2');
    return $res['is_correct'] === 1 && abs($res['awarded_points'] - 1.0) < 0.001;
});

// 36. Question: Boolean Question Type
$q_bool_id = 0;
$it("Создание и оценка вопроса: Boolean (Верно/Неверно)", function () use ($quiz_id, &$q_bool_id) {
    $q_bool_id = Question::create([
        'quiz_id'       => $quiz_id,
        'question_type' => 'boolean',
        'title'         => 'Число 1 не является ни простым, ни составным.',
        'points'        => 1.0,
        'options'       => [
            'choices' => [
                ['id' => 'true', 'text' => 'Верно', 'is_correct' => 1],
                ['id' => 'false', 'text' => 'Неверно', 'is_correct' => 0],
            ],
        ],
    ]);
    if ($q_bool_id <= 0) return false;
    $q = Question::get($q_bool_id);
    $res = Grader::grade_question($q, 'true');
    return $res['is_correct'] === 1 && abs($res['awarded_points'] - 1.0) < 0.001;
});

// 38. Template Tags Placeholder Replacement
$it("Шаблонизатор тегов (replace_template_tags): подстановка переменных", function () use ($quiz, $attempt1) {
    $template = 'Участник %NAME% набрал %SCORE% из %MAX_SCORE% б. (%PERCENTAGE%%). Статус: %GRADE%';
    $result = $quiz->replace_template_tags($template, $attempt1);
    return str_contains($result, $attempt1->participant_name) &&
           str_contains($result, '12') &&
           str_contains($result, 'из 13 б.') &&
           str_contains($result, 'Завершено');
});

// 37. The legacy mail entry point is retained but never dispatches notifications.
$it("Email-оповещения отключены: прежний Mailer не отправляет письма", function () use ($quiz, $attempt1) {
    $calls = 0;
    $spy = static function ($pre, $atts) use (&$calls) { $calls++; return true; };
    add_filter('pre_wp_mail', $spy, 10, 2);
    try { \WPOlympiad\Mailer::send_attempt_emails($quiz, $attempt1); return $calls === 0; }
    finally { remove_filter('pre_wp_mail', $spy, 10); }
});

// =======================================================
// НОВЫЕ ТЕСТЫ: ФУНКЦИОНАЛ ONLINE TEST PAD (Тесты 41 - 49)
// =======================================================

// 41. Matching Question (Online Test Pad)
$q_match_id = 0;
$it("Online Test Pad: Вопрос на установление соответствий (matching)", function () use ($quiz_id, &$q_match_id) {
    $q_match_id = Question::create([
        'quiz_id'       => $quiz_id,
        'question_type' => 'matching',
        'title'         => 'Сопоставьте столицы и государства',
        'points'        => 3,
        'penalty'       => 1,
        'options'       => [
            'pairs' => [
                ['id' => 'p1', 'left' => 'Франция', 'right' => 'Париж'],
                ['id' => 'p2', 'left' => 'Япония', 'right' => 'Токио'],
                ['id' => 'p3', 'left' => 'Италия', 'right' => 'Рим'],
            ],
        ],
    ]);
    if ($q_match_id <= 0) return false;
    $q = Question::get($q_match_id);

    // 100% Correct
    $res1 = Grader::grade_question($q, ['p1' => 'Париж', 'p2' => 'Токио', 'p3' => 'Рим']);
    if ($res1['is_correct'] !== 1 || $res1['awarded_points'] !== 3) return false;

    // Partial Credit (2 out of 3 = 2 pts)
    $res2 = Grader::grade_question($q, ['p1' => 'Париж', 'p2' => 'Берлин', 'p3' => 'Рим']);
    if ($res2['is_correct'] !== 0 || $res2['awarded_points'] !== 2) return false;

    // 0 Correct with penalty
    $res3 = Grader::grade_question($q, ['p1' => 'Лондон', 'p2' => 'Берлин', 'p3' => 'Мадрид']);
    return $res3['awarded_points'] === -1;
});

// 42. Ordering / Sequence Question (Online Test Pad)
$q_order_id = 0;
$it("Online Test Pad: Вопрос на установление последовательности (ordering)", function () use ($quiz_id, &$q_order_id) {
    $q_order_id = Question::create([
        'quiz_id'       => $quiz_id,
        'question_type' => 'ordering',
        'title'         => 'Расположите этапы компиляции программы в правильном порядке',
        'points'        => 4.0,
        'penalty'       => 0.0,
        'options'       => [
            'items' => [
                ['id' => 'ord_1', 'text' => 'Лексический анализ (Токенизация)'],
                ['id' => 'ord_2', 'text' => 'Синтаксический анализ (Парсинг)'],
                ['id' => 'ord_3', 'text' => 'Семантический анализ и оптимизация'],
                ['id' => 'ord_4', 'text' => 'Генерация машинного кода'],
            ],
        ],
    ]);
    if ($q_order_id <= 0) return false;
    $q = Question::get($q_order_id);

    // Perfect sequence
    $res1 = Grader::grade_question($q, ['ord_1', 'ord_2', 'ord_3', 'ord_4']);
    if ($res1['is_correct'] !== 1 || abs($res1['awarded_points'] - 4.0) > 0.01) return false;

    // 2 in correct position: ord_1 and ord_4 (2/4 * 4.0 = 2.0 pts)
    $res2 = Grader::grade_question($q, ['ord_1', 'ord_3', 'ord_2', 'ord_4']);
    return $res2['is_correct'] === 0 && abs($res2['awarded_points'] - 2.0) < 0.01;
});

// 43. Fill in the Blanks Question (Online Test Pad)
$q_blank_id = 0;
$it("Online Test Pad: Заполнение пропусков в тексте (fill_blank)", function () use ($quiz_id, &$q_blank_id) {
    $q_blank_id = Question::create([
        'quiz_id'       => $quiz_id,
        'question_type' => 'fill_blank',
        'title'         => 'Заполните пропуски в предложении',
        'points'        => 2.0,
        'options'       => [
            'template'       => 'В году {12|двенадцать} месяцев, а в сутках {24|двадцать четыре} часа.',
            'case_sensitive' => 0,
        ],
    ]);
    if ($q_blank_id <= 0) return false;
    $q = Question::get($q_blank_id);

    // Full match with synonyms
    $res1 = Grader::grade_question($q, ['blank_1' => 'двенадцать', 'blank_2' => '24']);
    if ($res1['is_correct'] !== 1 || abs($res1['awarded_points'] - 2.0) > 0.01) return false;

    // Partial match (1 out of 2 = 1.0 pt)
    $res2 = Grader::grade_question($q, ['blank_1' => '12', 'blank_2' => '100']);
    return $res2['is_correct'] === 0 && abs($res2['awarded_points'] - 1.0) < 0.01;
});

// 44. Slider / Rating Scale (Online Test Pad)
$q_slider_id = 0;
$it("Online Test Pad: Шкала / Ползунок (slider) с допустимой погрешностью", function () use ($quiz_id, &$q_slider_id) {
    $q_slider_id = Question::create([
        'quiz_id'       => $quiz_id,
        'question_type' => 'slider',
        'title'         => 'Укажите температуру кипения воды при нормальном давлении в градусах Цельсия',
        'points'        => 2,
        'penalty'       => 1,
        'options'       => [
            'min'          => 0,
            'max'          => 200,
            'step'         => 1,
            'target_value' => 100,
            'tolerance'    => 2,
        ],
    ]);
    if ($q_slider_id <= 0) return false;
    $q = Question::get($q_slider_id);

    // Exact or within tolerance (101 is within ±2)
    $res1 = Grader::grade_question($q, '101');
    if ($res1['is_correct'] !== 1 || $res1['awarded_points'] !== 2) return false;

    // Outside tolerance with penalty
    $res2 = Grader::grade_question($q, '85');
    return $res2['awarded_points'] === -1;
});

// 45. Anti-Cheat & Security: Sanitization of Public Payloads for New Types
$it("Безопасность Anti-Cheat: get_public_data() удаляет ключи новых типов", function () use ($q_match_id, $q_order_id, $q_blank_id, $q_slider_id) {
    $qm = Question::get($q_match_id)->get_public_data();
    $qo = Question::get($q_order_id)->get_public_data();
    $qb = Question::get($q_blank_id)->get_public_data();
    $qs = Question::get($q_slider_id)->get_public_data();

    // Matching: right options must exist but pairs mapping removed
    if (!isset($qm['left_items']) || !isset($qm['right_options']) || isset($qm['pairs'])) return false;

    // Ordering: items provided, but correct positions must not be exposed
    if (!isset($qo['items']) || isset($qo['options'])) return false;

    // Fill Blank: solutions inside {...} must be replaced with [[blank:...]]
    if (str_contains($qb['template'], 'двенадцать') || !str_contains($qb['template'], '[[blank:blank_1]]')) return false;

    // Slider: target_value and tolerance must be hidden
    if (isset($qs['target_value']) || isset($qs['tolerance'])) return false;

    return true;
});

// Форматирование ответов участников (format_user_answer)
$it("Форматирование ответов участников (Export::format_user_answer) для всех видов заданий", function () use ($q_match_id, $q_order_id, $q_blank_id) {
    $qm = Question::get($q_match_id);
    $fmt_match = \WPOlympiad\Export::format_user_answer($qm, json_encode(['p1' => 'Париж', 'p2' => 'Токио']));
    if (!str_contains($fmt_match, 'Франция → Париж') || !str_contains($fmt_match, 'Япония → Токио')) return false;

    $qo = Question::get($q_order_id);
    $fmt_order = \WPOlympiad\Export::format_user_answer($qo, json_encode(['ord_1', 'ord_2']));
    if (!str_contains($fmt_order, '1.') || !str_contains($fmt_order, '2.')) return false;

    $qb = Question::get($q_blank_id);
    $fmt_blank = \WPOlympiad\Export::format_user_answer($qb, json_encode(['blank_1' => '12', 'blank_2' => '24']));
    if (!str_contains($fmt_blank, '[1]: 12') || !str_contains($fmt_blank, '[2]: 24')) return false;

    return true;
});

// =======================================================
// НОВЫЕ ТЕСТЫ: РЕДАКТИРОВАНИЕ НАЧАЛЬНОЙ СТРАНИЦЫ (Тесты 50 - 52)
// =======================================================

// 50. Customizing Start / Intro Page Settings
$it("Редактирование начальной страницы (сохранение бейджа, заголовка, кнопки и полей анкеты)", function () use ($quiz) {
    $ok = $quiz->update([
        'settings' => array_merge($quiz->settings, [
            'intro_badge'             => 'Школьный этап олимпиады',
            'intro_title'             => 'Приветствуем участников олимпиады!',
            'intro_banner_url'        => 'https://example.com/banner.jpg',
            'intro_button_text'       => 'Начать решение задач прямо сейчас →',
            'show_meta_grid'          => 1,
            'show_phone'              => 1,
            'require_phone'           => 1,
            'field_phone_label'       => 'Ваш мобильный телефон:',
            'show_city'               => 1,
            'require_city'            => 1,
            'field_city_label'        => 'Город проживания:',
            'require_agreement'       => 1,
            'agreement_text'          => 'Я согласен с регламентом честной игры и обработкой персональных данных.',
            'show_anti_cheat_notice'  => 1,
            'anti_cheat_notice_text'  => 'Внимание! На олимпиаде включена система прокторинга.',
        ]),
    ]);
    if (!$ok) return false;

    // Verify persisted settings
    $refreshed = Quiz::get($quiz->id);
    return $refreshed->get_setting('intro_badge') === 'Школьный этап олимпиады' &&
           $refreshed->get_setting('intro_title') === 'Приветствуем участников олимпиады!' &&
           $refreshed->get_setting('intro_button_text') === 'Начать решение задач прямо сейчас →' &&
           (int)$refreshed->get_setting('require_phone') === 1 &&
           (int)$refreshed->get_setting('require_agreement') === 1;
});

// 51. Rendering Custom Start Page via Shortcode [wp_olympiad]
$it("Рендеринг кастомной начальной страницы: отображение баннера, бейджа, кнопки и полей", function () use ($quiz) {
    $html = Shortcodes::render_quiz(['id' => (string)$quiz->id]);

    $has_badge = str_contains($html, 'Школьный этап олимпиады');
    $has_title = str_contains($html, 'Приветствуем участников олимпиады!');
    $has_banner = str_contains($html, 'https://example.com/banner.jpg');
    $has_btn = str_contains($html, 'Начать решение задач прямо сейчас →');
    $has_phone = str_contains($html, 'Ваш мобильный телефон:');
    $has_city = str_contains($html, 'Город проживания:');
    $has_agreement = str_contains($html, 'Я согласен с регламентом честной игры');
    $has_anti_cheat = str_contains($html, 'Внимание! На олимпиаде включена система прокторинга.');

    return $has_badge && $has_title && $has_banner && $has_btn && $has_phone && $has_city && $has_agreement && $has_anti_cheat;
});

// 52. REST API Validation of Start Page Custom Fields
$it("REST API POST /start: валидация обязательных полей анкеты начальной страницы", function () use ($quiz) {
    // 1. Missing phone must return 400
    $req1 = new WP_REST_Request('POST', '/wp-olympiad/v1/start');
    $req1->set_param('quiz_id', $quiz->id);
    $req1->set_param('participant_name', 'Сидоров Алексей');
    $req1->set_param('participant_email', 'sidorov@example.com');
    $req1->set_param('participant_org', 'Школа 123');
    $req1->set_param('participant_phone', ''); // empty
    $req1->set_param('participant_city', 'Новосибирск');
    $req1->set_param('agreement', 1);

    $res1 = RestApi::handle_start($req1);
    if (!is_wp_error($res1) || $res1->get_error_code() !== 'missing_phone') {
        return false;
    }

    // 2. Missing agreement must return 400
    $req2 = new WP_REST_Request('POST', '/wp-olympiad/v1/start');
    $req2->set_param('quiz_id', $quiz->id);
    $req2->set_param('participant_name', 'Сидоров Алексей');
    $req2->set_param('participant_email', 'sidorov@example.com');
    $req2->set_param('participant_org', 'Школа 123');
    $req2->set_param('participant_phone', '+7 (999) 111-22-33');
    $req2->set_param('participant_city', 'Новосибирск');
    $req2->set_param('agreement', 0); // unchecked

    $res2 = RestApi::handle_start($req2);
    if (!is_wp_error($res2) || $res2->get_error_code() !== 'missing_agreement') {
        return false;
    }

    // 3. Valid all fields -> success
    $req3 = new WP_REST_Request('POST', '/wp-olympiad/v1/start');
    $req3->set_param('quiz_id', $quiz->id);
    $req3->set_param('participant_name', 'Сидоров Алексей');
    $req3->set_param('participant_email', 'sidorov_valid@example.com');
    $req3->set_param('participant_org', 'Школа 123');
    $req3->set_param('participant_phone', '+7 (999) 111-22-33');
    $req3->set_param('participant_city', 'Новосибирск');
    $req3->set_param('agreement', 1);

    $res3 = RestApi::handle_start($req3);
    if (is_wp_error($res3) || empty($res3->get_data()['token'])) {
        return false;
    }

    return true;
});

// =======================================================
// ТЕСТ 53: ОТКЛЮЧЕНИЕ ПОКАЗА РЕЗУЛЬТАТОВ В КОНЦЕ
// =======================================================

$it("Отключение показа результатов в конце (show_results_mode = 'none'): скрытие баллов и вывод кастомного сообщения", function () use ($quiz) {
    // 1. Configure quiz with show_results_mode = 'none'
    $custom_msg = 'Ваша работа успешно отправлена в оргкомитет. Баллы не разглашаются до окончания проверки.';
    $quiz->update([
        'settings' => array_merge($quiz->settings, [
            'show_results_mode'  => 'none',
            'completion_message' => $custom_msg,
        ]),
    ]);

    // 2. Start a new attempt
    $attempt = Attempt::start($quiz->id, [
        'name'  => 'Тестовый Участник 53',
        'email' => 'test53@example.com',
        'org'   => 'Лицей 53',
    ]);
    if (!$attempt) return false;

    // 3. Submit answers to /submit
    $req = new WP_REST_Request('POST', '/wp-olympiad/v1/submit');
    $req->set_param('token', $attempt->token);
    $req->set_param('answers', []);

    $res = RestApi::handle_submit($req);
    if (is_wp_error($res)) return false;

    $data = $res->get_data();

    // Verify results are completely hidden from student response
    if (empty($data['hide_results']) || $data['mode'] !== 'none') return false;
    if (isset($data['score']) || isset($data['percentage']) || isset($data['grade_label']) || isset($data['breakdown'])) {
        return false; // Security leak: scores must NOT be sent to client!
    }

    // Verify custom completion message is returned
    if ($data['message'] !== $custom_msg) return false;

    // Verify DB still holds the attempt results for admin review
    $updated_att = Attempt::get($attempt->id);
    if ($updated_att->status !== 'completed' && $updated_att->status !== 'needs_review') return false;

    return true;
});

// =======================================================
// ТЕСТ 54: БАЛЛЫ ЗА КАЖДЫЙ ПУНКТ ОТВЕТОВ (PER-CHOICE SCORING)
// =======================================================

$it("Баллы за каждый пункт ответов: одиночный/множественный выбор, сопоставление и упорядочивание", function () use ($quiz) {
    // 1. Single Choice with individual points (A = 3, B = 1, C = 0)
    $q_single_id = Question::create([
        'quiz_id'       => $quiz->id,
        'question_type' => 'single',
        'title'         => 'Оцените качество решения задачи',
        'points'        => 1, // should be auto-computed to max choice point = 3
        'options'       => [
            'choices' => [
                ['id' => 'c1', 'text' => 'Идеальное решение с доказательством', 'is_correct' => 1, 'points' => 3],
                ['id' => 'c2', 'text' => 'Частичное решение с вычислительной ошибкой', 'is_correct' => 0, 'points' => 1],
                ['id' => 'c3', 'text' => 'Неверное решение', 'is_correct' => 0, 'points' => 0],
            ],
        ],
    ]);
    if (!$q_single_id) return false;
    $q_single = Question::get($q_single_id);
    if ($q_single->points !== 3) return false;

    // Test grading choice A (3 pts)
    $res_a = Grader::grade_question($q_single, 'c1');
    if ($res_a['awarded_points'] !== 3 || $res_a['is_correct'] !== 1) return false;

    // Test grading choice B (1 pt)
    $res_b = Grader::grade_question($q_single, 'c2');
    if ($res_b['awarded_points'] !== 1) return false;

    // Test grading choice C (0 pts)
    $res_c = Grader::grade_question($q_single, 'c3');
    if ($res_c['awarded_points'] !== 0) return false;

    // Verify public data does not expose choice points to students!
    $pub = $q_single->get_public_data();
    foreach ($pub['choices'] as $ch) {
        if (isset($ch['points'])) return false; // Security leak!
    }

    // 2. Multi Choice with individual points (A = 2, B = 1, C = -1)
    $q_multi_id = Question::create([
        'quiz_id'       => $quiz->id,
        'question_type' => 'multi',
        'title'         => 'Выберите верные утверждения',
        'points'        => 1, // should be auto-computed to sum of positive = 3
        'options'       => [
            'choices' => [
                ['id' => 'm1', 'text' => 'Утверждение 1 (+2 б.)', 'is_correct' => 1, 'points' => 2],
                ['id' => 'm2', 'text' => 'Утверждение 2 (+1 б.)', 'is_correct' => 1, 'points' => 1],
                ['id' => 'm3', 'text' => 'Ловушка / Дистрактор (-1 б.)', 'is_correct' => 0, 'points' => -1],
            ],
        ],
    ]);
    if (!$q_multi_id) return false;
    $q_multi = Question::get($q_multi_id);
    if ($q_multi->points !== 3) return false;

    // Test grading selecting m1 and m2 -> 2 + 1 = 3
    $res_m12 = Grader::grade_question($q_multi, ['m1', 'm2']);
    if ($res_m12['awarded_points'] !== 3 || $res_m12['is_correct'] !== 1) return false;

    // Test grading selecting m1 and m3 -> 2 + (-1) = 1
    $res_m13 = Grader::grade_question($q_multi, ['m1', 'm3']);
    if ($res_m13['awarded_points'] !== 1 || $res_m13['is_correct'] !== 0) return false;

    // 3. Matching with individual points per pair (P1 = 2, P2 = 1, P3 = 2)
    $q_match_id = Question::create([
        'quiz_id'       => $quiz->id,
        'question_type' => 'matching',
        'title'         => 'Сопоставьте страны и столицы',
        'points'        => 1, // auto-computed to sum = 5
        'options'       => [
            'pairs' => [
                ['id' => 'p1', 'left' => 'Франция', 'right' => 'Париж', 'points' => 2],
                ['id' => 'p2', 'left' => 'Италия', 'right' => 'Рим', 'points' => 1],
                ['id' => 'p3', 'left' => 'Испания', 'right' => 'Мадрид', 'points' => 2],
            ],
        ],
    ]);
    if (!$q_match_id) return false;
    $q_match = Question::get($q_match_id);
    if ($q_match->points !== 5) return false;

    // Test partial matching (p1 and p3 correct, p2 wrong) -> 2 + 2 = 4 pts
    $res_match = Grader::grade_question($q_match, [
        'p1' => 'Париж',
        'p2' => 'Милан', // wrong
        'p3' => 'Мадрид',
    ]);
    if ($res_match['awarded_points'] !== 4 || $res_match['is_correct'] !== 0) return false;

    // Clean up temporary test questions
    Question::delete($q_single_id);
    Question::delete($q_multi_id);
    Question::delete($q_match_id);

    return true;
});

// =======================================================
// ТЕСТ 55: ЭКСПОРТ ОТВЕТОВ С ВОПРОСАМИ И ОТВЕТАМИ ПОЛЬЗОВАТЕЛЯ
// =======================================================

$it("Экспорт ответов в CSV: включение списка заданий и детальных ответов участников", function () use ($quiz, $attempt1) {
    wp_set_current_user(1);

    // 1. Test quiz-level export (all participants with dynamic question columns)
    $csv_all = \WPOlympiad\Export::export_quiz_attempts($quiz->id, false);
    if (empty($csv_all)) return false;

    // Check BOM
    if (!str_starts_with($csv_all, "\xEF\xBB\xBF")) return false;

    // Check headers contain Question columns with titles and answer/score tags
    if (!str_contains($csv_all, 'Вопрос 1:') || !str_contains($csv_all, '[Ответ]') || !str_contains($csv_all, 'Балл (из')) {
        return false;
    }

    // Check participant rows contain answers
    if (!str_contains($csv_all, $attempt1->participant_name)) return false;

    // 2. Test single attempt breakdown export
    $csv_single = \WPOlympiad\Export::export_single_attempt($attempt1->id, false);
    if (empty($csv_single)) return false;

    // Check BOM
    if (!str_starts_with($csv_single, "\xEF\xBB\xBF")) return false;

    // Check metadata block
    if (!str_contains($csv_single, 'ПРОТОКОЛ ПРОВЕРКИ РАБОТЫ УЧАСТНИКА') ||
        !str_contains($csv_single, $attempt1->participant_name) ||
        !str_contains($csv_single, $attempt1->participant_email)) {
        return false;
    }

    // Check question breakdown table headers
    if (!str_contains($csv_single, 'Текст задания') ||
        !str_contains($csv_single, 'Ответ участника') ||
        !str_contains($csv_single, 'Правильный ответ / Пояснение') ||
        !str_contains($csv_single, 'Начислено баллов')) {
        return false;
    }

    return true;
});

// =======================================================
// ТЕСТ 56: УДАЛЕНИЕ ОТВЕТОВ И ПОПЫТОК В ПАНЕЛИ УПРАВЛЕНИЯ
// =======================================================

$it("Удаление ответов и попыток: одиночное, массовое, полная очистка и удаление отдельного ответа", function () {
    global $wpdb;
    $tbl_attempts = \WPOlympiad\DB::attempts_table();
    $tbl_resp = \WPOlympiad\DB::responses_table();

    // 1. Create a dedicated test quiz
    $del_quiz_id = Quiz::create([
        'title' => 'Тест удаления ответов',
        'slug'  => 'test-delete-answers',
    ]);
    if (!$del_quiz_id) return false;
    $del_quiz = Quiz::get($del_quiz_id);

    $del_q1_id = Question::create([
        'quiz_id'       => $del_quiz_id,
        'question_type' => 'number',
        'title'         => 'Вопрос 1 на 5 баллов',
        'points'        => 5,
        'options'       => ['target_value' => '42', 'tolerance' => 0],
    ]);
    $del_q2_id = Question::create([
        'quiz_id'       => $del_quiz_id,
        'question_type' => 'number',
        'title'         => 'Вопрос 2 на 3 балла',
        'points'        => 3,
        'options'       => ['target_value' => '100', 'tolerance' => 0],
    ]);

    // 2. Test single attempt deletion (Attempt::delete)
    $att_single = Attempt::start($del_quiz_id, ['name' => 'Участник 1', 'email' => 'p1@test.ru']);
    if (!$att_single) return false;

    // Save responses
    $grading1 = Grader::grade($del_quiz, $att_single, [
        $del_q1_id => '42',
        $del_q2_id => '100',
    ]);
    Grader::save_responses($att_single->id, $grading1['responses']);

    $resp_count = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tbl_resp} WHERE attempt_id = %d", $att_single->id));
    if ($resp_count !== 2) return false;

    // Delete single attempt
    $del_result = Attempt::delete($att_single->id);
    if (!$del_result) return false;

    // Verify attempt is gone from DB
    $att_check = Attempt::get($att_single->id);
    if ($att_check !== null) return false;

    // Verify all responses for this attempt are cascaded and deleted
    $resp_check = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tbl_resp} WHERE attempt_id = %d", $att_single->id));
    if ($resp_check !== 0) return false;

    // 3. Test bulk deletion (Attempt::bulk_delete)
    $att_b1 = Attempt::start($del_quiz_id, ['name' => 'Участник Б1', 'email' => 'b1@test.ru']);
    $att_b2 = Attempt::start($del_quiz_id, ['name' => 'Участник Б2', 'email' => 'b2@test.ru']);
    Grader::save_responses($att_b1->id, Grader::grade($del_quiz, $att_b1, [$del_q1_id => '42'])['responses']);
    Grader::save_responses($att_b2->id, Grader::grade($del_quiz, $att_b2, [$del_q1_id => '42'])['responses']);

    $bulk_count = Attempt::bulk_delete([$att_b1->id, $att_b2->id]);
    if ($bulk_count !== 2) return false;
    if (Attempt::get($att_b1->id) !== null || Attempt::get($att_b2->id) !== null) return false;

    // 4. Test deleting individual response and score recalculation (Attempt::delete_response)
    $att_recalc = Attempt::start($del_quiz_id, ['name' => 'Участник Пересчёт', 'email' => 'recalc@test.ru']);
    $grading_recalc = Grader::grade($del_quiz, $att_recalc, [
        $del_q1_id => '42',   // +5 pts
        $del_q2_id => '100',  // +3 pts
    ]);
    Grader::save_responses($att_recalc->id, $grading_recalc['responses']);
    $att_recalc->complete($grading_recalc['total_score'], $grading_recalc['max_score'], $grading_recalc['status']);

    $att_recalc = Attempt::get($att_recalc->id);
    if ($att_recalc->score !== 8) return false;

    // Get response ID for question 1
    $r1_id = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$tbl_resp} WHERE attempt_id = %d AND question_id = %d",
        $att_recalc->id,
        $del_q1_id
    ));
    if (!$r1_id) return false;

    // Delete single response
    $del_resp_res = Attempt::delete_response($r1_id);
    if (!$del_resp_res) return false;

    // Verify response 1 is deleted
    $r1_check = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$tbl_resp} WHERE id = %d", $r1_id));
    if ($r1_check !== null) return false;

    // Verify attempt score is recalculated from 8 down to 3 points
    $att_after = Attempt::get($att_recalc->id);
    if ($att_after->score !== 3) return false;

    // 5. Test clear all attempts for a quiz (Attempt::delete_by_quiz)
    Attempt::start($del_quiz_id, ['name' => 'Участник Очистка 1', 'email' => 'c1@test.ru']);
    Attempt::start($del_quiz_id, ['name' => 'Участник Очистка 2', 'email' => 'c2@test.ru']);

    $cleared = Attempt::delete_by_quiz($del_quiz_id);
    if ($cleared < 2) return false;

    $rem_count = Attempt::count(['quiz_id' => $del_quiz_id]);
    if ($rem_count !== 0) return false;

    // Cleanup quiz
    Quiz::delete($del_quiz_id);

    return true;
});

// =======================================================
// ТЕСТ 51: ПРЯМОЙ ЭКСПОРТ В СЕТЕВУЮ ПАПКУ (БЕЗ АРХИВА)
// =======================================================

$it("Прямой экспорт ответов в сетевую папку (без архивации), корректный путь и авто-выгрузка", function () {
    // 1. Prepare target local network directory
    $net_dir = '/tmp/wpo_network_share_' . uniqid();
    wp_mkdir_p($net_dir);

    // 2. Test resolve_local_path with various path formats
    $def_path = NetworkExport::get_default_network_path();
    if (empty($def_path) || !str_contains($def_path, 'olympiad_network_share')) return false;

    $res_empty = NetworkExport::resolve_local_path('');
    if ($res_empty !== $def_path) return false;

    $res_abs = NetworkExport::resolve_local_path($net_dir);
    if ($res_abs !== $net_dir) return false;

    $res_unc = NetworkExport::resolve_local_path('\\\\\\\\server\\\\share');
    if ($res_unc !== '\\\\server\\share') return false;

    // 3. Create quiz with network export settings
    $net_quiz_id = Quiz::create([
        'title'    => 'Олимпиада Сетевой Экспорт',
        'slug'     => 'test-network-export',
        'settings' => [
            'max_attempts'          => 0,
            'enable_network_export' => 1,
            'network_folder_type'   => 'local',
            'network_folder_path'   => $net_dir,
            'network_auto_export'   => 1,
        ],
    ]);
    if (!$net_quiz_id) return false;
    $net_quiz = Quiz::get($net_quiz_id);

    // Add questions
    Question::create([
        'quiz_id'       => $net_quiz_id,
        'question_type' => 'single',
        'title'         => 'Столица РФ?',
        'points'        => 5,
        'data'          => [
            'options' => [
                ['id' => 'o1', 'text' => 'Москва', 'is_correct' => 1, 'points' => 5],
                ['id' => 'o2', 'text' => 'Казань', 'is_correct' => 0, 'points' => 0],
            ],
        ],
    ]);

    // Create completed attempt with responses
    $att = Attempt::start($net_quiz_id, [
        'name'  => 'Тестовый Участник Сети',
        'email' => 'net_student@test.ru',
        'org'   => 'Гимназия №1',
    ]);
    $att->complete(5, 5, true, 'completed');

    // 4. Test NetworkExport::test_connection()
    $test_res = NetworkExport::test_connection([
        'type' => 'local',
        'path' => $net_dir,
    ]);
    if (!$test_res['success']) return false;

    // Test connection with empty path uses default and succeeds
    $empty_test = NetworkExport::test_connection(['path' => '']);
    if (!$empty_test['success']) return false;

    // 5. Test manual export: NetworkExport::export_quiz()
    $export_res = NetworkExport::export_quiz($net_quiz_id);
    if (!$export_res['success']) return false;
    if (empty($export_res['destination']) || !is_dir($export_res['destination'])) return false;
    // 1.0.16: пакет выгрузки - основной протокол и попытка, файлов заданий в нём нет
    if ($export_res['files_count'] !== 2) return false;
    if (!empty(array_filter($export_res['files_list'], static fn($f) => str_starts_with((string) $f, 'вопросы/')))) return false;
    if (($export_res['questions_included'] ?? null) !== false) return false;

    // 6. Verify NO ZIP archive is created in network folder (direct files only!)
    $zip_files = glob($net_dir . '/*.zip');
    if (!empty($zip_files)) return false;

    // 7. Verify files exist inside organized and Russified subfolders (no loose files in root!)
    $primary_protocol = 'протоколы/протокол_' . NetworkExport::build_safe_slug(Quiz::get($net_quiz_id)) . '.csv';
    if (($export_res['primary_file'] ?? '') !== $primary_protocol) return false;
    if (!file_exists($net_dir . '/' . $primary_protocol)) return false;
    if (count(glob($net_dir . '/протоколы/*.csv')) !== 1) return false;
    if (is_dir($net_dir . '/вопросы')) return false;
    if (file_exists($net_dir . '/отчёты')) return false;

    // Verify non-Russified folders are NOT created
    if (is_dir($net_dir . '/protocols') || is_dir($net_dir . '/questions') || is_dir($net_dir . '/reports') || is_dir($net_dir . '/attempts')) {
        return false;
    }

    $csv_content = file_get_contents($net_dir . '/' . $primary_protocol);
    if ($csv_content === false || !str_contains($csv_content, 'Тестовый Участник Сети')) {
        return false;
    }

    // Verify sanitize_path preserves backslashes and UNC paths
    if (NetworkExport::sanitize_path('\\\\192.168.1.100\\share\\olympiad') !== '\\\\192.168.1.100\\share\\olympiad') return false;
    if (NetworkExport::sanitize_path('D:\\Shares\\Olympiad') !== 'D:\\Shares\\Olympiad') return false;

    // Verify that NO loose export files remain in root outside folders
    $loose_root_protos = glob($net_dir . '/protocol*.csv');
    if (!empty($loose_root_protos)) return false;
    $loose_root_num = glob($net_dir . '/01_*.csv');
    if (!empty($loose_root_num)) return false;
    if (file_exists($net_dir . '/README_MANIFEST.txt')) return false;
    if (file_exists($net_dir . '/answers.csv')) return false;

    // 8. Test transient caching of last export
    $last_meta = get_transient('wpo_last_network_export_' . $net_quiz_id);
    if (empty($last_meta) || empty($last_meta['destination'])) return false;

    // 9. Test Automatic Export on participant submission via REST API
    $req = new WP_REST_Request('POST', '/wp-olympiad/v1/start');
    $req->set_param('quiz_id', $net_quiz_id);
    $req->set_param('participant_name', 'Второй Участник Автовыгрузки');
    $req->set_param('participant_email', 'second_student@test.ru');
    $req->set_param('participant_org', 'Школа №123');
    $start_res = RestApi::handle_start($req);
    if (is_wp_error($start_res)) return false;
    $token = $start_res->get_data()['token'] ?? '';
    if (empty($token)) return false;

    // Submit answers
    $sub_req = new WP_REST_Request('POST', '/wp-olympiad/v1/submit');
    $sub_req->set_param('token', $token);
    $sub_req->set_param('answers', []);
    $submit_res = RestApi::handle_submit($sub_req);
    if ($submit_res->get_status() !== 200) return false;

    // Check that direct CSV files in network folder were updated with new submission
    $updated_csv = file_get_contents($net_dir . '/' . $primary_protocol);
    if (count(glob($net_dir . '/протоколы/*.csv')) !== 1) return false;
    if ($updated_csv === false || !str_contains($updated_csv, 'Второй Участник Автовыгрузки')) {
        return false;
    }

    // Cleanup
    Quiz::delete($net_quiz_id);
    exec('rm -rf ' . escapeshellarg($net_dir));

    return true;
});

// =======================================================
// ТЕСТ 52: АВТОМАТИЧЕСКАЯ ПРОВЕРКА КРАТКОГО ТЕКСТА (SHORT TEXT)
// =======================================================

$it("Автоматическая проверка краткого текста (exact, contains_any, contains_all, fuzzy/Левенштейн, regex)", function () {
    $t_quiz_id = Quiz::create([
        'title'    => 'Тест проверки текста',
        'slug'     => 'test-text-check',
        'settings' => ['max_attempts' => 0],
    ]);
    if (!$t_quiz_id) return false;

    // 1. Exact match with Cyrillic synonyms & punctuation tolerance
    $q_exact_id = Question::create([
        'quiz_id'       => $t_quiz_id,
        'question_type' => 'text',
        'title'         => 'Столица России?',
        'points'        => 2,
        'penalty'       => 0,
        'options'       => [
            'answers'           => ['Москва', 'Moscow'],
            'match_mode'        => 'exact',
            'case_sensitive'    => 0,
            'ignore_punct'      => 1,
            'ignore_whitespace' => 1,
        ],
    ]);
    $q_exact = Question::get($q_exact_id);

    $res1 = Grader::grade_question($q_exact, '  «москва»  ');
    if ($res1['awarded_points'] !== 2 || $res1['is_correct'] !== 1) return false;

    $res2 = Grader::grade_question($q_exact, 'Moscow');
    if ($res2['awarded_points'] !== 2 || $res2['is_correct'] !== 1) return false;

    $res3 = Grader::grade_question($q_exact, 'Казань');
    if ($res3['awarded_points'] !== 0 || $res3['is_correct'] !== 0) return false;

    // 2. Contains any
    $q_any_id = Question::create([
        'quiz_id'       => $t_quiz_id,
        'question_type' => 'text',
        'title'         => 'Какой газ выделяется?',
        'points'        => 3,
        'options'       => [
            'answers'    => ['кислород', 'oxygen', 'O2'],
            'match_mode' => 'contains_any',
        ],
    ]);
    $q_any = Question::get($q_any_id);

    $res_any1 = Grader::grade_question($q_any, 'В ходе реакции активно выделяется чистый кислород.');
    if ($res_any1['awarded_points'] !== 3 || $res_any1['is_correct'] !== 1) return false;

    $res_any2 = Grader::grade_question($q_any, 'Выделяется водород.');
    if ($res_any2['is_correct'] !== 0) return false;

    // 3. Contains all
    $q_all_id = Question::create([
        'quiz_id'       => $t_quiz_id,
        'question_type' => 'text',
        'title'         => 'Перечислите составляющие второго закона Ньютона',
        'points'        => 4,
        'options'       => [
            'answers'    => ['сила', 'масса', 'ускорение'],
            'match_mode' => 'contains_all',
        ],
    ]);
    $q_all = Question::get($q_all_id);

    $res_all1 = Grader::grade_question($q_all, 'Закон связывает массу тела, действующую силу и приобретаемое ускорение');
    if ($res_all1['awarded_points'] !== 4 || $res_all1['is_correct'] !== 1) return false;

    $res_all2 = Grader::grade_question($q_all, 'Тут только масса и сила');
    if ($res_all2['is_correct'] !== 0) return false;

    // 4. Fuzzy match (multibyte Levenshtein distance on Cyrillic)
    $q_fuzzy_id = Question::create([
        'quiz_id'       => $t_quiz_id,
        'question_type' => 'text',
        'title'         => 'Основной закон государства?',
        'points'        => 5,
        'options'       => [
            'answers'        => ['Конституция'],
            'match_mode'     => 'fuzzy',
            'typo_tolerance' => 1,
        ],
    ]);
    $q_fuzzy = Question::get($q_fuzzy_id);

    // 1 typo ("Канституция" vs "Конституция")
    $res_fuzz1 = Grader::grade_question($q_fuzzy, 'Канституция');
    if ($res_fuzz1['awarded_points'] !== 5 || $res_fuzz1['is_correct'] !== 1) return false;

    // 3 typos should fail with tolerance = 1
    $res_fuzz2 = Grader::grade_question($q_fuzzy, 'Консцуция');
    if ($res_fuzz2['is_correct'] !== 0) return false;

    // 5. RegEx pattern
    $q_reg_id = Question::create([
        'quiz_id'       => $t_quiz_id,
        'question_type' => 'text',
        'title'         => 'Год окончания Великой Отечественной войны?',
        'points'        => 2,
        'options'       => [
            'answers'    => ['/^194[1-5]$/'],
            'match_mode' => 'regex',
        ],
    ]);
    $q_reg = Question::get($q_reg_id);

    $res_reg1 = Grader::grade_question($q_reg, '1945');
    if ($res_reg1['awarded_points'] !== 2 || $res_reg1['is_correct'] !== 1) return false;

    $res_reg2 = Grader::grade_question($q_reg, '1917');
    if ($res_reg2['is_correct'] !== 0) return false;

    Quiz::delete($t_quiz_id);
    return true;
});

// =======================================================
// ТЕСТ 53: АВТОМАТИЧЕСКАЯ ПРОВЕРКА РАЗВЕРНУТЫХ ОТВЕТОВ / ЭССЕ
// =======================================================

$it("Автоматическая проверка развёрнутых ответов (essay) по ключевым понятиям и критериям", function () {
    $e_quiz_id = Quiz::create([
        'title'    => 'Тест проверки эссе',
        'slug'     => 'test-essay-check',
        'settings' => ['max_attempts' => 0],
    ]);
    if (!$e_quiz_id) return false;

    // Essay with auto-check enabled, proportional scoring, min words
    $q_essay_id = Question::create([
        'quiz_id'       => $e_quiz_id,
        'question_type' => 'essay',
        'title'         => 'Опишите процесс фотосинтеза',
        'points'        => 6,
        'options'       => [
            'auto_check'   => 1,
            'keywords'     => ['фотосинтез', 'хлорофилл', 'свет', 'углекислый газ'],
            'min_keywords' => 2,
            'scoring_mode' => 'proportional',
            'min_words'    => 5,
            'auto_pass'    => 1,
        ],
    ]);
    $q_essay = Question::get($q_essay_id);

    // 1. Participant covers all 4 keywords with sufficient words
    $text_full = 'В процессе фотосинтеза на свету хлорофилл поглощает углекислый газ для образования органики.';
    $eval_full = Grader::grade_question($q_essay, $text_full);
    if ($eval_full['awarded_points'] !== 6 || $eval_full['is_correct'] !== 1 || $eval_full['needs_manual_review'] !== false) {
        return false;
    }
    if (!str_contains($eval_full['review_comment'], 'найдено ключевых понятий: 4 из 4')) {
        return false;
    }

    // 2. Participant covers 2 of 4 keywords (50% proportional points => 3 points)
    $text_half = 'Растения поглощают углекислый газ на свету.';
    $eval_half = Grader::grade_question($q_essay, $text_half);
    if ($eval_half['awarded_points'] !== 3 || $eval_half['is_correct'] !== 1) {
        return false;
    }

    // 3. Participant answer too short (< 5 words)
    $text_short = 'фотосинтез свет';
    $eval_short = Grader::grade_question($q_essay, $text_short);
    if ($eval_short['awarded_points'] !== 0) {
        return false;
    }

    // 4. Essay with auto_pass = 0: auto-checked but transferred for jury manual confirmation
    $q_essay_manual_id = Question::create([
        'quiz_id'       => $e_quiz_id,
        'question_type' => 'essay',
        'title'         => 'Теорема Пифагора',
        'points'        => 4,
        'options'       => [
            'auto_check'   => 1,
            'keywords'     => ['гипотенуза', 'катет', 'квадрат'],
            'scoring_mode' => 'all_or_nothing',
            'min_keywords' => 2,
            'auto_pass'    => 0, // transfers to jury
        ],
    ]);
    $q_manual = Question::get($q_essay_manual_id);

    $eval_manual = Grader::grade_question($q_manual, 'Квадрат гипотенузы равен сумме квадратов катетов');
    if ($eval_manual['awarded_points'] !== 4 || $eval_manual['needs_manual_review'] !== true) {
        return false;
    }
    if (!str_contains($eval_manual['review_comment'], 'найдено ключевых понятий: 3 из 3')) {
        return false;
    }

    Quiz::delete($e_quiz_id);
    return true;
});

// =======================================================
// ТЕСТ 54: ПОМОЩНИК ПРОВЕРКИ ЖЮРИ И АВТОПРОВЕРКА ОТВЕТОВ
// =======================================================

$it("Помощник проверки жюри (Grader::auto_check_answer_data) и расчет баллов текста", function () {
    $rev_quiz_id = Quiz::create([
        'title'    => 'Тест проверки жюри',
        'slug'     => 'test-review-check',
        'settings' => ['max_attempts' => 0],
    ]);
    if (!$rev_quiz_id) return false;

    $q_id = Question::create([
        'quiz_id'       => $rev_quiz_id,
        'question_type' => 'text',
        'title'         => 'Столица РФ?',
        'points'        => 5,
        'options'       => [
            'answers'    => ['Москва'],
            'match_mode' => 'exact',
        ],
    ]);
    $q = Question::get($q_id);

    $att = Attempt::start($rev_quiz_id, ['name' => 'Студент', 'email' => 'rev@test.ru']);
    $grading = Grader::grade(Quiz::get($rev_quiz_id), $att, [$q_id => 'Москва']);
    Grader::save_responses($att->id, $grading['responses']);

    global $wpdb;
    $tbl_resp = DB::responses_table();
    $resp_id = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$tbl_resp} WHERE attempt_id = %d", $att->id));
    if (!$resp_id) return false;

    // Test Grader::auto_check_answer_data()
    $check_data = Grader::auto_check_answer_data($q, 'Москва');
    if ($check_data['recommended_points'] !== 5 || $check_data['is_correct'] !== 1) return false;
    if (empty($check_data['comment']) || !str_contains($check_data['comment'], 'Москва')) return false;

    // Test with wrong answer
    $check_wrong = Grader::auto_check_answer_data($q, 'Париж');
    if ($check_wrong['recommended_points'] !== 0 || $check_wrong['is_correct'] !== 0) return false;

    Quiz::delete($rev_quiz_id);
    return true;
});

// =======================================================
// ТЕСТ 55: СОСТАВЛЕНИЕ СЛОВА ИЗ БУКВ (WORD SCRAMBLE)
// =======================================================

$it("Новый тип задания: Составление слова из букв (Word Scramble / Анаграмма)", function () {
    $ws_quiz_id = Quiz::create([
        'title'    => 'Тест анаграмм',
        'slug'     => 'test-word-scramble',
        'settings' => ['max_attempts' => 0],
    ]);
    if (!$ws_quiz_id) return false;

    // 1. Create question
    $q_ws_id = Question::create([
        'quiz_id'       => $ws_quiz_id,
        'question_type' => 'word_scramble',
        'title'         => 'Соберите слово из букв: упорядоченная последовательность команд.',
        'points'        => 5,
        'penalty'       => 1,
        'options'       => [
            'target_word'   => 'АЛГОРИТМ',
            'words'         => ['АЛГОРИТМ', 'ALGORITHM'],
            'extra_letters' => 'КН',
            'case_sensitive'=> 0,
        ],
    ]);
    if (!$q_ws_id) return false;
    $q_ws = Question::get($q_ws_id);

    // 2. Anti-cheat public data verification
    $pub = $q_ws->get_public_data(false);
    if (isset($pub['target_word']) || isset($pub['words'])) return false; // LEAK!
    if (!isset($pub['tiles']) || count($pub['tiles']) !== 10) return false; // 8 from АЛГОРИТМ + 2 from КН = 10
    if ($pub['word_length'] !== 8) return false;

    // Ensure all characters are present in tiles
    $chars_in_tiles = array_column($pub['tiles'], 'char');
    sort($chars_in_tiles);
    $expected_chars = ['А', 'Г', 'И', 'К', 'Л', 'М', 'Н', 'О', 'Р', 'Т'];
    sort($expected_chars);
    if ($chars_in_tiles !== $expected_chars) return false;

    // 3. Grader: correct assembled word (uppercase)
    $eval1 = Grader::grade_question($q_ws, 'АЛГОРИТМ');
    if ($eval1['awarded_points'] !== 5 || $eval1['is_correct'] !== 1) return false;

    // 4. Grader: correct assembled word (lowercase)
    $eval2 = Grader::grade_question($q_ws, 'алгоритм');
    if ($eval2['awarded_points'] !== 5 || $eval2['is_correct'] !== 1) return false;

    // 5. Grader: synonym match
    $eval3 = Grader::grade_question($q_ws, 'algorithm');
    if ($eval3['awarded_points'] !== 5 || $eval3['is_correct'] !== 1) return false;

    // 6. Grader: array of tiles/letters input
    $eval4 = Grader::grade_question($q_ws, ['А', 'Л', 'Г', 'О', 'Р', 'И', 'Т', 'М']);
    if ($eval4['awarded_points'] !== 5 || $eval4['is_correct'] !== 1) return false;

    // 7. Grader: wrong word with penalty
    $eval5 = Grader::grade_question($q_ws, 'ЛОГОРИТМ');
    if ($eval5['awarded_points'] !== -1 || $eval5['is_correct'] !== 0) return false;

    // 8. Grader: empty input
    $eval6 = Grader::grade_question($q_ws, '');
    if ($eval6['awarded_points'] !== -1 || $eval6['is_correct'] !== 0) return false;

    // 9. Export formatting
    $export_text = Export::format_user_answer($q_ws, 'АЛГОРИТМ');
    if ($export_text !== 'АЛГОРИТМ') return false;

    // 10. Reviewer assistant check
    $rev_check = Grader::auto_check_answer_data($q_ws, 'АЛГОРИТМ');
    if ($rev_check['recommended_points'] !== 5 || $rev_check['is_correct'] !== 1) return false;

    Quiz::delete($ws_quiz_id);
    return true;
});

// =======================================================
// ТЕСТ 56: ИНТЕРАКТИВНЫЙ ДИКТАНТ (DICTATION)
// =======================================================

$it("Новый тип задания: Интерактивный диктант (орфограммы/пунктограммы, аудирование, оценка)", function () {
    $dict_quiz_id = Quiz::create([
        'title'    => 'Тест интерактивного диктанта',
        'slug'     => 'test-dictation',
        'settings' => ['max_attempts' => 0],
    ]);
    if (!$dict_quiz_id) return false;

    // 1. Create dictation question
    $q_dict_id = Question::create([
        'quiz_id'       => $dict_quiz_id,
        'question_type' => 'dictation',
        'title'         => 'Орфографический диктант: Прогулка по лесу',
        'points'        => 6,
        'options'       => [
            'template'        => 'Шёл {=дождь|дожть}. Мы шли по алле{=е|и}, любуясь пр{=и|е}родой{=,|нет знака} которую мы любим.',
            'audio_url'       => 'https://example.com/audio/dictation.mp3',
            'scoring_mode'    => 'proportional',
            'allowed_mistakes'=> 0,
        ],
    ]);
    if (!$q_dict_id) return false;
    $q_dict = Question::get($q_dict_id);

    // 2. Anti-cheat public data verification
    $pub = $q_dict->get_public_data(false);
    if (!isset($pub['dict_gaps']) || count($pub['dict_gaps']) !== 4) return false;
    if ($pub['total_gaps'] !== 4) return false;
    if (str_contains($pub['template'], '{=дождь')) return false; // LEAK!
    if (!str_contains($pub['template'], '[[dict:d_1]]')) return false;
    if (!str_contains($pub['template'], '[[dict:d_4]]')) return false;
    if ($pub['audio_url'] !== 'https://example.com/audio/dictation.mp3') return false;

    // Verify correct answers are NOT in public options
    foreach ($pub['dict_gaps'] as $gid => $ginfo) {
        if (isset($ginfo['correct'])) return false; // LEAK!
    }

    // 3. Grader: all 4 correct
    $answers_all = [
        'd_1' => 'дождь',
        'd_2' => 'е',
        'd_3' => 'и',
        'd_4' => ',',
    ];
    $eval_all = Grader::grade_question($q_dict, $answers_all);
    if ($eval_all['awarded_points'] !== 6 || $eval_all['is_correct'] !== 1) return false;
    if (!str_contains($eval_all['review_comment'], 'верных орфограмм 4 из 4')) return false;

    // 4. Grader: 2 of 4 correct (50% proportional points => 3 points)
    $answers_half = [
        'd_1' => 'дождь',
        'd_2' => 'и', // wrong
        'd_3' => 'и',
        'd_4' => 'нет знака', // wrong
    ];
    $eval_half = Grader::grade_question($q_dict, $answers_half);
    if ($eval_half['awarded_points'] !== 3) return false;

    // 5. Grader: allowed_mistakes = 1 (1 mistake allowed without deduction)
    $q_dict_tol_id = Question::create([
        'quiz_id'       => $dict_quiz_id,
        'question_type' => 'dictation',
        'title'         => 'Диктант с допуском 1 ошибки',
        'points'        => 6,
        'options'       => [
            'template'        => 'Шёл {=дождь|дожть}. Мы шли по алле{=е|и}, любуясь пр{=и|е}родой{=,|нет знака} которую мы любим.',
            'scoring_mode'    => 'proportional',
            'allowed_mistakes'=> 1,
        ],
    ]);
    $q_dict_tol = Question::get($q_dict_tol_id);
    // 3 out of 4 correct (1 mistake), with allowed_mistakes=1 should award full 6 points!
    $answers_3 = [
        'd_1' => 'дождь',
        'd_2' => 'е',
        'd_3' => 'и',
        'd_4' => 'нет знака', // 1 mistake
    ];
    $eval_tol = Grader::grade_question($q_dict_tol, $answers_3);
    if ($eval_tol['awarded_points'] !== 6) return false;

    // 6. Grader: scoring_mode = all_or_nothing
    $q_dict_aon_id = Question::create([
        'quiz_id'       => $dict_quiz_id,
        'question_type' => 'dictation',
        'title'         => 'Диктант все или ничего',
        'points'        => 6,
        'options'       => [
            'template'        => 'Шёл {=дождь|дожть}. Мы шли по алле{=е|и}, любуясь пр{=и|е}родой{=,|нет знака} которую мы любим.',
            'scoring_mode'    => 'all_or_nothing',
            'allowed_mistakes'=> 0,
        ],
    ]);
    $q_dict_aon = Question::get($q_dict_aon_id);
    $eval_aon_fail = Grader::grade_question($q_dict_aon, $answers_3);
    if ($eval_aon_fail['awarded_points'] !== 0) return false;

    // 7. Export formatting
    $export_str = Export::format_user_answer($q_dict, json_encode($answers_all));
    if (!str_contains($export_str, 'd_1: дождь')) return false;

    // 8. Reviewer assistant auto-check
    $rev_check = Grader::auto_check_answer_data($q_dict, $answers_all);
    if ($rev_check['recommended_points'] !== 6 || $rev_check['is_correct'] !== 1) return false;

    Quiz::delete($dict_quiz_id);
    return true;
});

$it('Варианты ответов в "Заполнении пропусков" (dropdown, word_bank, zero-leak, автопроверка)', function () {
    $fb_quiz_id = Quiz::create([
        'title'       => 'Тест вариантов ответов в пропусках',
        'status'      => 'published',
        'time_limit'  => 0,
        'pass_percent'=> 70,
    ]);

    // 1. Dropdown mode with markup {=ответ|вариант2|вариант3}
    $q_dropdown_id = Question::create([
        'quiz_id'       => $fb_quiz_id,
        'question_type' => 'fill_blank',
        'title'         => 'Столицы (выпадающий список)',
        'points'        => 10,
        'options'       => [
            'choice_mode' => 'dropdown',
            'template'    => '{=Москва|Санкт-Петербург|Казань} — столица {=России|Франции|Германии}.',
            'distractors' => 'Минск, Астана',
        ],
    ]);
    $q_dropdown = Question::get($q_dropdown_id);
    if ($q_dropdown->options['choice_mode'] !== 'dropdown') return false;
    if (count($q_dropdown->options['blanks']) !== 2) return false;

    // Check parsed blanks and correct answers
    $b1 = $q_dropdown->options['blanks'][0];
    $b2 = $q_dropdown->options['blanks'][1];
    if (!in_array('Москва', $b1['answers'], true)) return false;
    if (!in_array('России', $b2['answers'], true)) return false;

    // 2. Zero-leak anti-cheat verification for dropdown
    $pub_dropdown = $q_dropdown->get_public_data();
    if ($pub_dropdown['choice_mode'] !== 'dropdown') return false;
    if (str_contains($pub_dropdown['template'], '{=') || str_contains($pub_dropdown['template'], 'Москва')) return false;
    if (!str_contains($pub_dropdown['template'], '[[blank:blank_1]]')) return false;
    if (!isset($pub_dropdown['blank_options']['blank_1']) || !isset($pub_dropdown['blank_options']['blank_2'])) return false;

    $b1_opts = $pub_dropdown['blank_options']['blank_1'];
    if (!in_array('Москва', $b1_opts, true) || !in_array('Санкт-Петербург', $b1_opts, true)) return false;
    // Ensure no secret signs leaked
    foreach ($b1_opts as $opt) {
        if (str_starts_with($opt, '=') || str_starts_with($opt, '*')) return false;
    }

    // 3. Grader for dropdown
    // 2 of 2 correct => 10 points
    $eval_all = Grader::grade_question($q_dropdown, ['blank_1' => 'Москва', 'blank_2' => 'России']);
    if ($eval_all['awarded_points'] !== 10 || $eval_all['is_correct'] !== 1) return false;
    if (!str_contains($eval_all['review_comment'], '2 из 2')) return false;

    // 1 of 2 correct => 5 points (proportional)
    $eval_half = Grader::grade_question($q_dropdown, ['blank_1' => 'Москва', 'blank_2' => 'Франции']);
    if ($eval_half['awarded_points'] !== 5 || $eval_half['is_correct'] !== 0) return false;
    if (!str_contains($eval_half['review_comment'], '1 из 2')) return false;

    // 4. Word bank mode with distractors
    $q_bank_id = Question::create([
        'quiz_id'       => $fb_quiz_id,
        'question_type' => 'fill_blank',
        'title'         => 'Астрономия (банк слов)',
        'points'        => 8,
        'options'       => [
            'choice_mode' => 'word_bank',
            'template'    => 'Планета {=Земля} обращается вокруг {=Солнца}.',
            'distractors' => 'Луна, Марс, Юпитер',
        ],
    ]);
    $q_bank = Question::get($q_bank_id);
    if ($q_bank->options['choice_mode'] !== 'word_bank') return false;

    // Zero-leak check for word_bank
    $pub_bank = $q_bank->get_public_data();
    if ($pub_bank['choice_mode'] !== 'word_bank') return false;
    if (!isset($pub_bank['word_bank']) || count($pub_bank['word_bank']) < 5) return false;

    $bank_words = array_column($pub_bank['word_bank'], 'text');
    if (!in_array('Земля', $bank_words, true) || !in_array('Солнца', $bank_words, true)) return false;
    if (!in_array('Луна', $bank_words, true) || !in_array('Марс', $bank_words, true)) return false;

    // Grade word bank
    $eval_bank = Grader::grade_question($q_bank, ['blank_1' => 'Земля', 'blank_2' => 'Солнца']);
    if ($eval_bank['awarded_points'] !== 8 || $eval_bank['is_correct'] !== 1) return false;

    // 5. Classic input mode
    $q_input_id = Question::create([
        'quiz_id'       => $fb_quiz_id,
        'question_type' => 'fill_blank',
        'title'         => 'Арифметика (ручной ввод)',
        'points'        => 4,
        'options'       => [
            'choice_mode' => 'input',
            'template'    => 'Два плюс два равно {4|четыре}.',
        ],
    ]);
    $q_input = Question::get($q_input_id);
    $eval_input = Grader::grade_question($q_input, ['blank_1' => 'четыре']);
    if ($eval_input['awarded_points'] !== 4 || $eval_input['is_correct'] !== 1) return false;

    // 6. Export formatting
    $export_str = Export::format_user_answer($q_dropdown, json_encode(['blank_1' => 'Москва', 'blank_2' => 'России']));
    if (!str_contains($export_str, '[1]: Москва') || !str_contains($export_str, '[2]: России')) return false;

    Quiz::delete($fb_quiz_id);
    return true;
});

$it('Иллюстрации к вопросам (image_url, caption, position) и форматирование текста (HTML, kses)', function () {
    $quiz_id = Quiz::create([
        'title'       => 'Тест картинок и форматирования',
        'status'      => 'published',
        'time_limit'  => 0,
        'pass_percent'=> 60,
    ]);

    // 1. Create question with image and rich text formatting
    $q_id = Question::create([
        'quiz_id'       => $quiz_id,
        'question_type' => 'single',
        'title'         => 'Рассмотрите схему на <strong>Рис. 1</strong> и определите силу тока <em>I</em> при <sup>x²</sup>.',
        'content'       => "<p>Подробные указания к решению:</p><ul><li>Сопротивление <code>R = 10 Ом</code></li><li>Напряжение <mark>U = 220 В</mark></li></ul><blockquote>Закон Ома: I = U / R</blockquote>",
        'points'        => 5,
        'options'       => [
            'image_url'      => 'https://example.com/images/circuit.png',
            'image_caption'  => 'Рис. 1. Принципиальная электрическая схема цепи',
            'image_alt'      => 'Электрическая схема цепи',
            'image_position' => 'above',
            'choices'        => [
                ['id' => 'c1', 'text' => '22 А', 'is_correct' => 1],
                ['id' => 'c2', 'text' => '10 А', 'is_correct' => 0],
            ],
        ],
    ]);

    $q = Question::get($q_id);
    if (!$q) return false;

    // Check properties and options
    if ($q->options['image_url'] !== 'https://example.com/images/circuit.png') return false;
    if ($q->options['image_caption'] !== 'Рис. 1. Принципиальная электрическая схема цепи') return false;
    if ($q->options['image_position'] !== 'above') return false;

    // Check rich formatting in title and content
    if (!str_contains($q->title, '<strong>Рис. 1</strong>')) return false;
    if (!str_contains($q->content, '<code>R = 10 Ом</code>')) return false;
    if (!str_contains($q->content, '<mark>U = 220 В</mark>')) return false;
    if (!str_contains($q->content, '<blockquote>Закон Ома')) return false;

    // 2. Public data security check
    $pub = $q->get_public_data();
    if ($pub['image_url'] !== 'https://example.com/images/circuit.png') return false;
    if ($pub['image_caption'] !== 'Рис. 1. Принципиальная электрическая схема цепи') return false;
    if ($pub['image_position'] !== 'above') return false;
    if (!str_contains($pub['title'], '<strong>Рис. 1</strong>')) return false;
    if (!str_contains($pub['content'], '<code>R = 10 Ом</code>')) return false;

    // 3. Security: test malicious tags stripping via wp_kses_post
    $q_sec_id = Question::create([
        'quiz_id'       => $quiz_id,
        'question_type' => 'text',
        'title'         => 'Вопрос <script>alert("hack")</script><strong>безопасный</strong>',
        'content'       => '<img src="javascript:alert(1)" onerror="alert(2)"><p>Текст</p>',
        'options'       => [
            'image_url'     => 'javascript:alert(3)', // Should be filtered out or sanitized by esc_url_raw
        ],
    ]);
    $q_sec = Question::get($q_sec_id);
    if (str_contains($q_sec->title, '<script>')) return false;
    if (!str_contains($q_sec->title, '<strong>безопасный</strong>')) return false;
    if (str_contains($q_sec->content, 'onerror=')) return false;
    if ($q_sec->options['image_url'] !== '') return false; // esc_url_raw removes javascript: protocol

    // 4. Update image position and caption
    $q->update([
        'options' => array_merge($q->options, [
            'image_position' => 'below',
            'image_caption'  => 'Обновлённая схема 2026',
        ]),
    ]);
    $q_updated = Question::get($q_id);
    if ($q_updated->options['image_position'] !== 'below') return false;
    if ($q_updated->options['image_caption'] !== 'Обновлённая схема 2026') return false;

    Quiz::delete($quiz_id);
    return true;
});

$it('Обязательные вопросы (is_required) и фиксация/перемешивание ответов (shuffle_answers)', function () {
    $quiz_id = Quiz::create([
        'title'       => 'Тест обязательных вопросов и перемешивания',
        'status'      => 'publish',
        'time_limit'  => 0,
        'pass_percent'=> 50,
        'settings'    => [
            'shuffle_answers' => 1, // Глобально в олимпиаде включено перемешивание
            'max_attempts'    => 0,
        ],
    ]);

    // 1. Вопрос #1: ОБЯЗАТЕЛЬНЫЙ (is_required = 1)
    $q_req_id = Question::create([
        'quiz_id'       => $quiz_id,
        'question_type' => 'single',
        'title'         => 'Обязательный вопрос #1',
        'points'        => 5,
        'options'       => [
            'is_required' => 1,
            'choices'     => [
                ['id' => 'c1', 'text' => 'Да', 'is_correct' => 1],
                ['id' => 'c2', 'text' => 'Нет', 'is_correct' => 0],
            ],
        ],
    ]);
    $q_req = Question::get($q_req_id);
    if (!$q_req->is_required()) return false;
    $pub_req = $q_req->get_public_data();
    if (empty($pub_req['is_required'])) return false;

    // 2. Вопрос #2: С фиксированным порядком вариантов (shuffle_answers = 0)
    // Например: Вариант 1, Вариант 2, Все вышеперечисленные (порядок критичен!)
    $q_fixed_id = Question::create([
        'quiz_id'       => $quiz_id,
        'question_type' => 'single',
        'title'         => 'Вопрос с фиксированным порядком вариантов',
        'points'        => 5,
        'options'       => [
            'is_required'     => 0,
            'shuffle_answers' => 0, // Отключаем перемешивание для этого вопроса
            'choices'         => [
                ['id' => 'c1', 'text' => 'Пункт А', 'is_correct' => 0],
                ['id' => 'c2', 'text' => 'Пункт Б', 'is_correct' => 0],
                ['id' => 'c3', 'text' => 'Все вышеперечисленные', 'is_correct' => 1],
            ],
        ],
    ]);
    $q_fixed = Question::get($q_fixed_id);
    if ($q_fixed->is_required()) return false;
    if ($q_fixed->should_shuffle(true) !== false) return false;

    // Check that public data preserves exact order even when $shuffle_answers is passed true
    for ($i = 0; $i < 5; $i++) {
        $pub_fixed = $q_fixed->get_public_data(true);
        if ($pub_fixed['shuffle_answers'] !== false) return false;
        if ($pub_fixed['choices'][0]['text'] !== 'Пункт А') return false;
        if ($pub_fixed['choices'][1]['text'] !== 'Пункт Б') return false;
        if ($pub_fixed['choices'][2]['text'] !== 'Все вышеперечисленные') return false;
    }

    // 3. Тест валидации обязательного вопроса при сдаче (REST API)
    $attempt = Attempt::start($quiz_id, [
        'name'  => 'Иван Тестовый',
        'email' => 'ivan@example.com',
    ]);
    if (!$attempt) return false;
    $token = $attempt->token;

    // Попытка сдать без ответа на обязательный вопрос #1
    $req_fail = new WP_REST_Request('POST', '/wp-olympiad/v1/submit');
    $req_fail->set_param('token', $token);
    $req_fail->set_param('answers', [
        $q_fixed_id => 'c3', // отвечен только необязательный вопрос
    ]);
    $res_fail = RestApi::handle_submit($req_fail);
    if (!is_wp_error($res_fail)) return false;
    if ($res_fail->get_error_code() !== 'required_question_missing') return false;
    if (!str_contains($res_fail->get_error_message(), 'обязательные вопросы')) return false;

    // Попытка сдать с ответом на обязательный вопрос #1
    $req_success = new WP_REST_Request('POST', '/wp-olympiad/v1/submit');
    $req_success->set_param('token', $token);
    $req_success->set_param('answers', [
        $q_req_id   => 'c1',
        $q_fixed_id => 'c3',
    ]);
    $res_success = RestApi::handle_submit($req_success);
    if (is_wp_error($res_success)) return false;
    $submit_data = $res_success->get_data();
    if ($submit_data['score'] !== 10) return false;

    Quiz::delete($quiz_id);
    return true;
});

$it('Группировка заданий по разделам (group_name) и скрытие баллов в тесте (show_question_points)', function () {
    $quiz_id = Quiz::create([
        'title'       => 'Олимпиада с разделами и скрытыми баллами',
        'status'      => 'publish',
        'time_limit'  => 0,
        'pass_percent'=> 50,
        'settings'    => [
            'show_question_points' => 0, // Скрывать баллы за задания во время тестирования
            'max_attempts'         => 0,
        ],
    ]);
    $quiz = Quiz::get($quiz_id);
    if ((int)$quiz->get_setting('show_question_points', 1) !== 0) return false;

    // 1. Создание заданий в Разделе 1: «Часть А: Теория»
    $q1_id = Question::create([
        'quiz_id'       => $quiz_id,
        'question_type' => 'single',
        'title'         => 'Вопрос по теории 1',
        'points'        => 3,
        'options'       => [
            'group_name' => 'Часть А: Теория',
            'choices'    => [
                ['id' => 'c1', 'text' => 'Вариант 1', 'is_correct' => 1],
                ['id' => 'c2', 'text' => 'Вариант 2', 'is_correct' => 0],
            ],
        ],
    ]);
    $q2_id = Question::create([
        'quiz_id'       => $quiz_id,
        'question_type' => 'single',
        'title'         => 'Вопрос по теории 2',
        'points'        => 3,
        'options'       => [
            'group_name' => 'Часть А: Теория',
            'choices'    => [
                ['id' => 'c1', 'text' => 'Вариант 1', 'is_correct' => 1],
                ['id' => 'c2', 'text' => 'Вариант 2', 'is_correct' => 0],
            ],
        ],
    ]);

    // 2. Создание заданий в Разделе 2: «Часть Б: Практика» (с индивидуальным показом баллов)
    $q3_id = Question::create([
        'quiz_id'       => $quiz_id,
        'question_type' => 'number',
        'title'         => 'Практическая задача 1',
        'points'        => 10,
        'options'       => [
            'group_name'   => 'Часть Б: Практика',
            'show_points'  => 1, // Индивидуально показываем баллы за эту задачу
            'target_value' => 42,
            'tolerance'    => 0,
        ],
    ]);

    $q1 = Question::get($q1_id);
    $q3 = Question::get($q3_id);

    // Проверка полей
    if ($q1->options['group_name'] !== 'Часть А: Теория') return false;
    if ($q3->options['group_name'] !== 'Часть Б: Практика') return false;

    // 3. Проверка публичных данных
    $pub1 = $q1->get_public_data();
    if ($pub1['group_name'] !== 'Часть А: Теория') return false;
    if ($pub1['show_points'] !== null) return false; // наследует олимпиаду

    $pub3 = $q3->get_public_data();
    if ($pub3['group_name'] !== 'Часть Б: Практика') return false;
    if ($pub3['show_points'] !== true) return false;

    // 4. Проверка REST API POST /start
    $start_req = new WP_REST_Request('POST', '/wp-olympiad/v1/start');
    $start_req->set_param('quiz_id', $quiz_id);
    $start_req->set_param('participant_name', 'Ольга');
    $start_req->set_param('participant_email', 'olga@example.com');
    $start_req->set_param('participant_org', 'Лицей №1');
    $start_res = RestApi::handle_start($start_req);
    if (is_wp_error($start_res)) return false;

    $start_data = $start_res->get_data();
    if ($start_data['show_question_points'] !== false) return false;
    if (count($start_data['questions']) !== 3) return false;
    if ($start_data['questions'][0]['group_name'] !== 'Часть А: Теория') return false;
    if ($start_data['questions'][2]['group_name'] !== 'Часть Б: Практика') return false;

    // 5. Проверка сдачи ответов и начисления баллов
    $submit_req = new WP_REST_Request('POST', '/wp-olympiad/v1/submit');
    $submit_req->set_param('token', $start_data['token']);
    $submit_req->set_param('answers', [
        $q1_id => 'c1',
        $q2_id => 'c1',
        $q3_id => 42,
    ]);
    $submit_res = RestApi::handle_submit($submit_req);
    if (is_wp_error($submit_res)) return false;
    $submit_info = $submit_res->get_data();
    if ($submit_info['score'] !== 16) return false;

    Quiz::delete($quiz_id);
    return true;
});

$it('Общие тексты к заданиям (shared_texts, кейсы/пассажи, привязка к вопросам и REST API)', function () {
    $quiz_id = Quiz::create([
        'title'       => 'Олимпиада с общими текстами к вопросам',
        'status'      => 'publish',
        'time_limit'  => 0,
        'pass_percent'=> 50,
        'settings'    => [
            'max_attempts' => 0,
            'shared_texts' => [
                [
                    'id'      => 'st_doc1',
                    'title'   => 'Текст к заданиям 1–2: Исторический документ',
                    'content' => '<p>В лето 1242 года бысть битва на Чудском озере...</p>',
                ],
                [
                    'id'      => 'st_case2',
                    'title'   => 'Кейс к заданию 3: Экономическая задача',
                    'content' => '<blockquote>Предприятие выпустило 1000 единиц продукции...</blockquote>',
                ],
            ],
        ],
    ]);

    $quiz = Quiz::get($quiz_id);
    if (!$quiz) return false;

    // 1. Проверка получения общих текстов через модель Quiz
    $st_list = $quiz->get_shared_texts();
    if (count($st_list) !== 2) return false;

    $st_doc1 = $quiz->get_shared_text('st_doc1');
    if (!$st_doc1 || $st_doc1['title'] !== 'Текст к заданиям 1–2: Исторический документ') return false;

    // 2. Создание вопросов с привязкой к общему тексту
    $q1_id = Question::create([
        'quiz_id'       => $quiz_id,
        'question_type' => 'single',
        'title'         => 'Вопрос 1 по документу: В каком году произошло событие?',
        'points'        => 5,
        'options'       => [
            'shared_text_id' => 'st_doc1',
            'choices'        => [
                ['id' => 'c1', 'text' => '1242', 'is_correct' => 1],
                ['id' => 'c2', 'text' => '1380', 'is_correct' => 0],
            ],
        ],
    ]);

    $q2_id = Question::create([
        'quiz_id'       => $quiz_id,
        'question_type' => 'single',
        'title'         => 'Вопрос 2 по документу: Где происходили события?',
        'points'        => 5,
        'options'       => [
            'shared_text_id' => 'st_doc1',
            'choices'        => [
                ['id' => 'c1', 'text' => 'Чудское озеро', 'is_correct' => 1],
                ['id' => 'c2', 'text' => 'Куликово поле', 'is_correct' => 0],
            ],
        ],
    ]);

    $q3_id = Question::create([
        'quiz_id'       => $quiz_id,
        'question_type' => 'number',
        'title'         => 'Вопрос 3 по кейсу: Рассчитайте себестоимость единицы',
        'points'        => 10,
        'options'       => [
            'shared_text_id' => 'st_case2',
            'target_value'   => 150,
            'tolerance'      => 0,
        ],
    ]);

    $q4_id = Question::create([
        'quiz_id'       => $quiz_id,
        'question_type' => 'boolean',
        'title'         => 'Автономный вопрос без общего текста',
        'points'        => 2,
        'options'       => [
            'choices' => [
                ['id' => 'true', 'text' => 'Верно', 'is_correct' => 1],
                ['id' => 'false', 'text' => 'Неверно', 'is_correct' => 0],
            ],
        ],
    ]);

    $q1 = Question::get($q1_id);
    $q2 = Question::get($q2_id);
    $q3 = Question::get($q3_id);
    $q4 = Question::get($q4_id);

    // 3. Проверка публичных данных вопросов (Question::get_public_data)
    $pub1 = $q1->get_public_data();
    if ($pub1['shared_text_id'] !== 'st_doc1') return false;
    if (empty($pub1['shared_text']) || $pub1['shared_text']['title'] !== 'Текст к заданиям 1–2: Исторический документ') return false;

    $pub3 = $q3->get_public_data();
    if ($pub3['shared_text_id'] !== 'st_case2') return false;
    if (empty($pub3['shared_text']) || $pub3['shared_text']['title'] !== 'Кейс к заданию 3: Экономическая задача') return false;

    $pub4 = $q4->get_public_data();
    if (!empty($pub4['shared_text_id'])) return false;
    if (isset($pub4['shared_text'])) return false;

    // 4. Проверка REST API POST /wp-olympiad/v1/start
    $start_req = new WP_REST_Request('POST', '/wp-olympiad/v1/start');
    $start_req->set_param('quiz_id', $quiz_id);
    $start_req->set_param('participant_name', 'Игорь');
    $start_req->set_param('participant_email', 'igor@example.com');
    $start_req->set_param('participant_org', 'Гимназия №5');
    $start_res = RestApi::handle_start($start_req);
    if (is_wp_error($start_res)) return false;

    $start_data = $start_res->get_data();
    if (!isset($start_data['shared_texts']['st_doc1'])) return false;
    if (!isset($start_data['shared_texts']['st_case2'])) return false;
    if (count($start_data['questions']) !== 4) return false;
    if ($start_data['questions'][0]['shared_text_id'] !== 'st_doc1') return false;
    if ($start_data['questions'][1]['shared_text_id'] !== 'st_doc1') return false;
    if ($start_data['questions'][2]['shared_text_id'] !== 'st_case2') return false;

    // 5. Проверка сохранения через Quiz::save_shared_texts()
    $save_ok = $quiz->save_shared_texts([
        [
            'id'      => 'st_doc1',
            'title'   => 'Обновлённый исторический документ',
            'content' => '<p>Новая редакция фрагмента летописи...</p>',
        ],
    ]);
    if (!$save_ok) return false;
    $updated_quiz = Quiz::get($quiz_id);
    if (count($updated_quiz->get_shared_texts()) !== 1) return false;
    if ($updated_quiz->get_shared_text('st_doc1')['title'] !== 'Обновлённый исторический документ') return false;

    // 6. Проверка сдачи ответов и начисления баллов
    $submit_req = new WP_REST_Request('POST', '/wp-olympiad/v1/submit');
    $submit_req->set_param('token', $start_data['token']);
    $submit_req->set_param('answers', [
        $q1_id => 'c1',
        $q2_id => 'c1',
        $q3_id => 150,
        $q4_id => 'true',
    ]);
    $submit_res = RestApi::handle_submit($submit_req);
    if (is_wp_error($submit_res)) return false;
    $submit_data = $submit_res->get_data();
    if ($submit_data['score'] !== 22) return false;

    Quiz::delete($quiz_id);
    return true;
});

$it('Структурирование экспорта по папкам без отчётов и уведомления Anti-Cheat', function () {
    // 1. Создание олимпиады с участниками и попытками
    $quiz_id = Quiz::create([
        'title'       => 'Олимпиада Папки Экспорта и Античит',
        'status'      => 'publish',
        'time_limit'  => 30,
        'pass_percent'=> 50,
        'settings'    => [
            'max_attempts'               => 0,
            'anti_cheat_copy'            => 1,
            'anti_cheat_blur'            => 1,
            'max_tab_switches'           => 3,
            'anti_cheat_popup_warnings'  => 1,
        ],
    ]);
    $quiz = Quiz::get($quiz_id);
    if (!$quiz) return false;

    // Проверка настройки popup_warnings
    if ((int)$quiz->get_setting('anti_cheat_popup_warnings', 1) !== 1) return false;

    // Добавление тестового задания
    $q_id = Question::create([
        'quiz_id'       => $quiz_id,
        'question_type' => 'single',
        'title'         => 'Столица Бразилии?',
        'points'        => 10,
        'options'       => [
            'choices' => [
                ['id' => 'c1', 'text' => 'Бразилиа', 'is_correct' => 1],
                ['id' => 'c2', 'text' => 'Рио-де-Жанейро', 'is_correct' => 0],
            ],
        ],
    ]);

    // Создание попытки с ответом
    $att = Attempt::start($quiz_id, [
        'name'  => 'Мария Смирнова',
        'email' => 'maria@example.com',
        'org'   => 'Школа №10',
    ]);
    $att->complete(10, 10, true, 'completed');

    // 2. Проверка генерации структурированных файлов экспорта (NetworkExport::generate_export_files)
    $pkg = NetworkExport::generate_export_files($quiz);
    if (empty($pkg['success']) || empty($pkg['files'])) return false;

    $files = $pkg['files'];
    $keys = array_keys($files);

    // Проверка наличия исключительно русскоязычных структурированных папок:
    $has_ru_proto_dir  = false;
    $has_ru_quest_dir  = false;
    $has_ru_rep_dir    = false;
    $has_ru_att_dir    = false;

    foreach ($keys as $k) {
        if (str_starts_with($k, 'протоколы/')) $has_ru_proto_dir  = true;
        if (str_starts_with($k, 'вопросы/'))   $has_ru_quest_dir  = true;
        if (str_starts_with($k, 'отчёты/'))    $has_ru_rep_dir    = true;
        if (str_starts_with($k, 'попытки/'))   $has_ru_att_dir    = true;

        // Не должно быть нерусифицированных папок
        if (str_starts_with($k, 'protocols/') || str_starts_with($k, 'questions/') ||
            str_starts_with($k, 'reports/') || str_starts_with($k, 'attempts/')) {
            return false;
        }
    }

    // «вопросы/» больше не часть выгрузки, поэтому её в пакете быть не должно
    if (!$has_ru_proto_dir || $has_ru_quest_dir || $has_ru_rep_dir || !$has_ru_att_dir) {
        return false;
    }

    // 3. Проверка экспорта в реальную директорию на диске
    $export_test_dir = '/tmp/wpo_structured_test_' . uniqid();
    wp_mkdir_p($export_test_dir);

    $export_run = NetworkExport::export_quiz($quiz_id, [
        'enabled' => true,
        'type'    => 'filesystem',
        'path'    => $export_test_dir,
    ]);

    if (empty($export_run['success'])) return false;

    // Проверка физического создания структурированных подпапок на диске
    if (!is_dir($export_test_dir . '/протоколы')) return false;
    if (is_dir($export_test_dir . '/вопросы')) return false;
    if (file_exists($export_test_dir . '/отчёты')) return false;
    if (!is_dir($export_test_dir . '/попытки')) return false;

    // Проверка, что нерусифицированные папки не создаются
    if (is_dir($export_test_dir . '/protocols')) return false;
    if (is_dir($export_test_dir . '/questions')) return false;
    if (is_dir($export_test_dir . '/reports')) return false;
    if (is_dir($export_test_dir . '/attempts')) return false;

    // Проверка отсутствия лишних файлов вне папок в корне
    $root_loose_files = array_filter(glob($export_test_dir . '/*'), 'is_file');
    if (!empty($root_loose_files)) return false;

    // Проверка содержимого файлов внутри русских папок
    $proto_files = glob($export_test_dir . '/протоколы/*.csv');
    if (empty($proto_files) || !file_exists($proto_files[0])) return false;
    $proto_content = file_get_contents($proto_files[0]);
    if (!str_contains($proto_content, 'Мария Смирнова')) return false;

    $att_files = glob($export_test_dir . '/попытки/*.csv');
    if (empty($att_files) || !file_exists($att_files[0])) return false;
    $att_content = file_get_contents($att_files[0]);
    if (!str_contains($att_content, 'Столица Бразилии')) return false;

    // 3b. Снятый экспорт вопросов: сохранённый API по-прежнему пишет папку,
    //     а ближайшая успешная выгрузка снимает её файлы точечно, не трогая чужие.
    $slug61 = NetworkExport::build_safe_slug(Quiz::get($quiz_id));
    $button61 = NetworkExport::export_questions_to_network(Quiz::get($quiz_id), ['type' => 'filesystem', 'path' => $export_test_dir]);
    if (empty($button61['success']) || !is_dir($export_test_dir . '/вопросы')) return false;
    file_put_contents($export_test_dir . '/вопросы/черновик_учителя.txt', 'не удалять');
    $retire61 = NetworkExport::export_quiz($quiz_id, ['type' => 'filesystem', 'path' => $export_test_dir]);
    if (empty($retire61['success'])) return false;
    if (is_file($export_test_dir . '/вопросы/список_заданий.csv')) return false;
    if (is_file($export_test_dir . '/вопросы/вопросы_' . $slug61 . '.json')) return false;
    if (file_get_contents($export_test_dir . '/вопросы/черновик_учителя.txt') !== 'не удалять') return false;
    if (($retire61['questions_directory'] ?? '') !== 'not_empty') return false;
    if (count($retire61['removed_question_files'] ?? []) !== 5) return false;

    // 4. Проверка REST API POST /start параметров Anti-Cheat и всплывающих предупреждений
    $start_req = new WP_REST_Request('POST', '/wp-olympiad/v1/start');
    $start_req->set_param('quiz_id', $quiz_id);
    $start_req->set_param('participant_name', 'Алексей');
    $start_req->set_param('participant_email', 'alex@example.com');
    $start_req->set_param('participant_org', 'Класс 11-А');
    $start_res = RestApi::handle_start($start_req);
    if (is_wp_error($start_res)) return false;

    $start_data = $start_res->get_data();
    if (!isset($start_data['anti_cheat']['popup_warnings']) || $start_data['anti_cheat']['popup_warnings'] !== true) {
        return false;
    }
    if ($start_data['anti_cheat']['max_tab_switches'] !== 3) {
        return false;
    }

    // 5. Проверка AntiCheat::process_heartbeat() с фиксацией переключения вкладок
    $alex_att = Attempt::get_by_token($start_data['token']);
    if (!$alex_att) return false;

    // Первое переключение
    $hb1 = AntiCheat::process_heartbeat($alex_att, $quiz, true);
    if ($hb1['tab_switches'] !== 1 || $hb1['disqualified'] !== false) return false;

    // Второе и третье переключение
    $hb2 = AntiCheat::process_heartbeat($alex_att, $quiz, true);
    if ($hb2['tab_switches'] !== 2 || $hb2['disqualified'] !== false) return false;

    $hb3 = AntiCheat::process_heartbeat($alex_att, $quiz, true);
    if ($hb3['tab_switches'] !== 3 || $hb3['disqualified'] !== false) return false;

    // Четвёртое переключение (> max_tab_switches 3) -> дисквалификация
    $hb4 = AntiCheat::process_heartbeat($alex_att, $quiz, true);
    if ($hb4['tab_switches'] !== 4 || $hb4['disqualified'] !== true) return false;
    if (!str_contains($hb4['message'], 'Превышено допустимое количество')) return false;

    // Очистка
    exec('rm -rf ' . escapeshellarg($export_test_dir));
    Quiz::delete($quiz_id);
    return true;
});

$it('Импорт заданий по шаблону из сетевой папки (NetworkImport, CSV/JSON, режимы append/replace)', function () {
    // 1. Проверка генерации шаблонов CSV и JSON
    $tmpl_csv = NetworkImport::get_template_csv();
    if (empty($tmpl_csv) || !str_contains($tmpl_csv, 'single') || !str_contains($tmpl_csv, 'boolean')) {
        return false;
    }

    $parsed_tmpl = NetworkImport::parse_questions_content($tmpl_csv);
    if (count($parsed_tmpl) < 8) return false;

    $tmpl_json = NetworkImport::get_template_json();
    if (empty($tmpl_json) || !str_contains($tmpl_json, 'questions')) return false;

    $parsed_json = NetworkImport::parse_questions_content($tmpl_json);
    if (count($parsed_json) < 3) return false;

    // 2. Подготовка сетевой папки и тестового файла questions.csv
    $net_dir = '/tmp/wpo_net_import_' . uniqid();
    wp_mkdir_p($net_dir);

    $csv_content = "\xEF\xBB\xBF" .
        "type;title;points;penalty;group_name;choices;correct;target_value;tolerance;answers;explanation;is_required\n" .
        "single;Столица Германии?;3;1;География;Мюнхен | *Берлин | Гамбург;Берлин;;;;Столица ФРГ — Берлин;1\n" .
        "multi;Чётные числа?;4;0;Математика;*2 | 3 | *4 | 5;1, 3;;;;2 и 4 делятся на 2 без остатка;0\n" .
        "number;Число Пи с точностью до сотых;5;0;Геометрия;;;3.14;0.01;;Константа π ≈ 3.14159;1\n" .
        "text;Фамилия первого космонавта?;5;0;История;;;;;Гагарин | Юрий Гагарин;Юрий Алексеевич Гагарин;1\n";

    file_put_contents($net_dir . '/questions.csv', $csv_content);

    // 3. Создание олимпиады и сканирование сетевой папки
    $quiz_id = Quiz::create([
        'title'    => 'Олимпиада Импорт из Сети',
        'status'   => 'publish',
        'settings' => [
            'network_folder_type' => 'filesystem',
            'network_folder_path' => $net_dir,
            'max_attempts'        => 0,
        ],
    ]);
    $quiz = Quiz::get($quiz_id);
    if (!$quiz) return false;

    $scanned_files = NetworkImport::scan_network_folder_for_import_files($quiz);
    if (empty($scanned_files)) return false;
    $found_csv = false;
    foreach ($scanned_files as $f) {
        if ($f['name'] === 'questions.csv') {
            $found_csv = true;
            break;
        }
    }
    if (!$found_csv) return false;

    // 4. Импорт вопросов в режиме append (добавление)
    $import_res = NetworkImport::import_from_network($quiz_id, 'questions.csv', 'append');
    if (empty($import_res['success']) || $import_res['imported_count'] !== 4) {
        return false;
    }

    $imported_qs = $quiz->get_questions(false);
    if (count($imported_qs) !== 4) return false;

    // Проверка структуры импортированных вопросов
    $q_geo = $imported_qs[0];
    if ($q_geo->question_type !== 'single' || $q_geo->title !== 'Столица Германии?') return false;
    if ($q_geo->points !== 3 || $q_geo->penalty !== 1) return false;
    if (count($q_geo->options['choices']) !== 3) return false;
    if ($q_geo->options['choices'][1]['text'] !== 'Берлин' || empty($q_geo->options['choices'][1]['is_correct'])) return false;

    $q_num = $imported_qs[2];
    if ($q_num->question_type !== 'number' || (float)$q_num->options['target_value'] !== 3.14) return false;

    // 5. Импорт в режиме replace (замена текущих вопросов новыми)
    $replace_json = wp_json_encode([
        [
            'question_type' => 'boolean',
            'title'         => 'Земля имеет форму геоида.',
            'points'        => 5,
            'options'       => [
                'choices' => [
                    ['id' => 'true', 'text' => 'Да', 'is_correct' => 1],
                    ['id' => 'false', 'text' => 'Нет', 'is_correct' => 0],
                ],
            ],
        ],
        [
            'question_type' => 'number',
            'title'         => 'Сколько дней в високосном году?',
            'points'        => 5,
            'options'       => [
                'target_value' => 366,
                'tolerance'    => 0,
            ],
        ],
    ]);
    file_put_contents($net_dir . '/replacement.json', $replace_json);

    $replace_res = NetworkImport::import_from_network($quiz_id, 'replacement.json', 'replace');
    if (empty($replace_res['success']) || $replace_res['imported_count'] !== 2) return false;

    $replaced_qs = $quiz->get_questions(false);
    if (count($replaced_qs) !== 2) return false; // Старые 4 удалены, остались только 2 новые
    if ($replaced_qs[0]->question_type !== 'boolean') return false;
    if ($replaced_qs[1]->question_type !== 'number') return false;

    // 6. Проверка прохождения тестирования и отправки ответов на импортированные вопросы
    $start_req = new WP_REST_Request('POST', '/wp-olympiad/v1/start');
    $start_req->set_param('quiz_id', $quiz_id);
    $start_req->set_param('participant_name', 'Светлана');
    $start_req->set_param('participant_email', 'sveta@example.com');
    $start_req->set_param('participant_org', 'Лицей №100');
    $start_res = RestApi::handle_start($start_req);
    if (is_wp_error($start_res)) return false;
    $start_data = $start_res->get_data();

    $submit_req = new WP_REST_Request('POST', '/wp-olympiad/v1/submit');
    $submit_req->set_param('token', $start_data['token']);
    $submit_req->set_param('answers', [
        $replaced_qs[0]->id => 'true',
        $replaced_qs[1]->id => 366,
    ]);
    $submit_res = RestApi::handle_submit($submit_req);
    if (is_wp_error($submit_res)) return false;
    $submit_info = $submit_res->get_data();
    if ($submit_info['score'] !== 10) return false;

    // Очистка
    exec('rm -rf ' . escapeshellarg($net_dir));
    Quiz::delete($quiz_id);
    return true;
});

// ---------------------------------------------------------------------------
// 64. Шаблон по скачиванию, без автоматического размещения в сетевой import
// ---------------------------------------------------------------------------
$it('Шаблон в import не размещается; вручную добавленный XLSX импортируется', function (): bool {
    $root = sys_get_temp_dir() . '/wpo_xlsx_deploy_' . wp_generate_password(12, false, false);
    $id = Quiz::create(['title' => 'Ручной Excel-шаблон', 'settings' => ['network_folder_type' => 'local', 'network_folder_path' => $root]]);
    $removed = ['questions.csv', 'questions.json', 'questions_template.csv', 'questions_template.json', 'questions_template_macros.xlsm'];
    try {
        $connection = NetworkExport::test_connection(['type' => 'local', 'path' => $root]);
        if (empty($connection['success']) || !is_file($root . '/import/QuestionStudio.exe')) return false;
        foreach (array_merge($removed, ['questions_template.xlsx', 'README_IMPORT.txt']) as $name) {
            if (file_exists($root . '/import/' . $name)) return false;
        }
        $result = NetworkExport::deploy_import_templates_result(['type' => 'local', 'path' => $root]);
        if (!empty($result['success']) || !str_contains($result['message'], 'отключено')) return false;
        if (file_exists($root . '/import/questions_template.xlsx') || file_exists($root . '/import/README_IMPORT.txt')) return false;
        $xlsx = NetworkImport::get_template_xlsx();
        if (!str_starts_with($xlsx, "PK\x03\x04") || strlen($xlsx) < 5000) return false;
        file_put_contents($root . '/import/questions_template.xlsx', $xlsx); // User-supplied input.
        $quiz = Quiz::get($id);
        $found = NetworkImport::scan_network_folder_for_import_files($quiz);
        if (array_column($found, 'name') !== ['questions_template.xlsx']) return false;
        $import = NetworkImport::import_from_network($id, 'import/questions_template.xlsx', 'append');
        return !empty($import['success']) && $import['imported_count'] === 13 && count(Quiz::get($id)->get_questions(false)) === 13;
    } finally { Quiz::delete($id); exec('rm -rf ' . escapeshellarg($root)); }
});

// ---------------------------------------------------------------------------
// 65. Возможность отключения стартовой страницы (enable_intro_page = 0)
// ---------------------------------------------------------------------------
$it('Отключение стартовой страницы: прямой запуск олимпиады без анкеты', function (): bool {
    $quiz_id = Quiz::create([
        'title'  => 'Олимпиада без стартовой страницы',
        'status' => 'publish',
        'settings' => [
            'enable_intro_page'   => 0,
            'field_name_required' => 1,
            'require_email'       => 1,
        ],
    ]);
    $quiz = Quiz::get($quiz_id);

    // 1. Проверяем сохранение настройки
    if ((int)$quiz->get_setting('enable_intro_page', 1) !== 0) return false;

    // 2. Проверяем рендеринг шорткода: data-skip-intro="1", intro-screen скрыт
    $html = Shortcodes::render_quiz(['id' => (string)$quiz_id]);
    if (!str_contains($html, 'data-skip-intro="1"')) return false;
    if (!str_contains($html, 'wpo-intro-screen" style="overflow:hidden; display:none;"')) return false;
    if (!str_contains($html, 'wpo-runner-loading')) return false;

    // 3. Добавляем вопрос
    Question::create([
        'quiz_id'       => $quiz_id,
        'question_type' => 'boolean',
        'title'         => 'Земля круглая?',
        'points'        => 5,
        'options'       => ['correct_value' => 'true'],
    ]);

    // 4. Проверяем старт через REST API без передачи обязательных полей анкеты
    $req = new WP_REST_Request('POST', '/wp-olympiad/v1/start');
    $req->set_param('quiz_id', $quiz_id);
    // Не передаем имя и email — проверяем, что валидация стартовой страницы не блокирует старт
    $res = RestApi::handle_start($req);
    if (is_wp_error($res)) return false;

    $data = $res->get_data();
    if (empty($data['token']) || count($data['questions']) !== 1) return false;

    Quiz::delete($quiz_id);
    return true;
});

// ---------------------------------------------------------------------------
// 66. Единственный Excel-шаблон без макросов; пользовательский XLSM читается
// ---------------------------------------------------------------------------
$it('Excel-шаблон без макросов: 13 типов, списки, импорт XLSX и пользовательского XLSM', function (): bool {
    $xlsx = NetworkImport::get_template_xlsx();
    if (!str_starts_with($xlsx, "PK\x03\x04") || strlen($xlsx) < 5000 || NetworkImport::get_template_xlsm() !== '') return false;
    $temp = tempnam(sys_get_temp_dir(), 'wpo_macro_compat_');
    $id = 0;
    try {
        file_put_contents($temp, $xlsx); $zip = new \ZipArchive();
        if ($zip->open($temp) !== true) return false;
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $types = $zip->getFromName('[Content_Types].xml');
        if ($zip->locateName('xl/vbaProject.bin') !== false || $sheet === false || !str_contains($sheet, 'dataValidation')) { $zip->close(); return false; }
        // A valid macro-enabled OOXML workbook may have no VBA project. Create
        // this user-file fixture only at runtime; no XLSM sample is distributed.
        $macro_types = str_replace('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml', 'application/vnd.ms-excel.sheet.macroEnabled.main+xml', (string)$types, $replaced);
        if ($replaced !== 1) { $zip->close(); return false; }
        $zip->addFromString('[Content_Types].xml', $macro_types); $zip->close();
        $user_xlsm = (string)file_get_contents($temp);
        $parsed = NetworkImport::parse_questions_content($xlsx, 'Мои_вопросы.xlsx');
        $macro_parsed = NetworkImport::parse_questions_content($user_xlsm, 'Мои_вопросы.xlsm');
        $expected = ['single', 'multi', 'dropdown', 'boolean', 'number', 'text', 'matching', 'ordering', 'fill_blank', 'slider', 'word_scramble', 'dictation', 'essay'];
        if (array_column($parsed, 'question_type') !== $expected || array_column($macro_parsed, 'question_type') !== $expected) return false;
        $id = Quiz::create(['title' => 'XLSX: все типы, без макросов']);
        $result = NetworkImport::import_to_quiz($id, $parsed, 'replace'); $questions = Quiz::get($id)->get_questions(false);
        if (empty($result['success']) || $result['imported_count'] !== 13 || count($questions) !== 13) return false;
        return $questions[0]->points === 3 && $questions[0]->penalty === 1
            && (float)($questions[4]->options['target_value'] ?? 0) === 9.8
            && count($questions[6]->options['pairs'] ?? []) === 4;
    } finally { if ($id) Quiz::delete($id); @unlink($temp); }
});

// ---------------------------------------------------------------------------
// 67. Полные данные анкеты участника в протоколах и авто-парсинг CP1251 CSV
// ---------------------------------------------------------------------------
$it('Полные данные анкеты участника в протоколах и декодирование CP1251 CSV импорта', function (): bool {
    $quiz_id = Quiz::create([
        'title'  => 'Тест протоколов и анкеты участника',
        'status' => 'publish',
        'settings' => [
            'max_attempts' => 0,
        ],
    ]);
    $quiz = Quiz::get($quiz_id);

    // 1. Создание попытки с полными реквизитами анкеты
    $att = Attempt::start($quiz_id, [
        'name'  => 'Смирнова Анна Сергеевна',
        'email' => 'smirnova@gymnasium1.ru',
        'phone' => '+7 (913) 555-01-99',
        'city'  => 'Красноярск',
        'org'   => 'Гимназия №1, 10А класс',
    ]);

    if (!$att) {
        Quiz::delete($quiz_id);
        return false;
    }

    if ($att->participant_name !== 'Смирнова Анна Сергеевна') return false;
    if ($att->participant_email !== 'smirnova@gymnasium1.ru') return false;
    if ($att->participant_phone !== '+7 (913) 555-01-99') return false;
    if ($att->participant_city !== 'Красноярск') return false;
    if ($att->participant_org !== 'Гимназия №1, 10А класс') return false;

    // 2. Проверка экспорта общего протокола олимпиады
    $csv_full = Export::export_quiz_attempts($quiz_id, false);
    if (empty($csv_full)) {
        Attempt::delete($att->id);
        Quiz::delete($quiz_id);
        return false;
    }

    // Проверяем наличие отдельных колонок анкеты в заголовке
    if (!str_contains($csv_full, 'Телефон')) return false;
    if (!str_contains($csv_full, 'Город / Населённый пункт')) return false;
    if (!str_contains($csv_full, 'Организация / Класс')) return false;

    // Проверяем наличие данных анкеты в строке
    if (!str_contains($csv_full, '+7 (913) 555-01-99')) return false;
    if (!str_contains($csv_full, 'Красноярск')) return false;
    if (!str_contains($csv_full, 'Гимназия №1, 10А класс')) return false;

    // 3. Проверка экспорта индивидуального протокола попытки
    $csv_single = Export::export_single_attempt($att->id, false);
    if (empty($csv_single)) {
        Attempt::delete($att->id);
        Quiz::delete($quiz_id);
        return false;
    }

    if (!str_contains($csv_single, 'Телефон') || !str_contains($csv_single, '+7 (913) 555-01-99')) return false;
    if (!str_contains($csv_single, 'Город / Населённый пункт') || !str_contains($csv_single, 'Красноярск')) return false;
    if (!str_contains($csv_single, 'Организация / Класс') || !str_contains($csv_single, 'Гимназия №1, 10А класс')) return false;

    // 4. Проверка декодирования CSV в кодировке Windows-1251 (стандарт русского Excel)
    $utf8_csv = "тип;вопрос;баллы;варианты;правильный\n" .
                "single;Столица Республики Хакасия?;2;Красноярск | *Абакан | Минусинск;Абакан\n";
    $cp1251_csv = iconv('UTF-8', 'WINDOWS-1251//IGNORE', $utf8_csv);

    $parsed_cp1251 = NetworkImport::parse_questions_content($cp1251_csv, 'questions_cp1251.csv');
    if (count($parsed_cp1251) !== 1) {
        Attempt::delete($att->id);
        Quiz::delete($quiz_id);
        return false;
    }

    $q_parsed = $parsed_cp1251[0];
    if ($q_parsed['title'] !== 'Столица Республики Хакасия?') return false;
    if ($q_parsed['question_type'] !== 'single' || $q_parsed['points'] !== 2) return false;

    // Очистка
    Attempt::delete($att->id);
    Quiz::delete($quiz_id);
    return true;
});

$it('Автоматический импорт вопросов из сетевой папки (NetworkAutoImport, append/replace, hash-контроль, cron)', function () {
    $temp_net_dir = sys_get_temp_dir() . '/wpo_auto_import_test_' . uniqid();
    wp_mkdir_p($temp_net_dir);
    wp_mkdir_p($temp_net_dir . '/import');

    // 1. Создаем олимпиаду с настройками автоматического импорта вопросов
    $quiz_id = Quiz::create([
        'title'  => 'Тестовая олимпиада с автоимпортом вопросов',
        'status' => 'publish',
        'settings' => [
            'enable_network_export'        => 1,
            'network_folder_path'          => $temp_net_dir,
            'network_auto_import'          => 1,
            'network_auto_import_mode'     => 'append',
            'network_auto_import_interval' => 'hourly',
            'network_auto_import_file'     => '',
        ],
    ]);

    $quiz = Quiz::get($quiz_id);
    if (!$quiz) return false;

    // 2. Помещаем файл questions.csv в подпапку import/
    $csv_v1 = "тип;вопрос;баллы;варианты;правильный\n" .
              "single;Сколько планет в Солнечной системе?;2;7 | *8 | 9;8\n" .
              "boolean;Вода закипает при 100°C при нормальном давлении?;1;*Да | Нет;Да\n";
    file_put_contents($temp_net_dir . '/import/questions.csv', $csv_v1);

    // 3. Выполняем автоимпорт
    $res1 = NetworkImport::auto_import_for_quiz($quiz, false);
    if (!$res1['success']) return false;
    if ($res1['imported_count'] !== 2) return false;
    if (count($quiz->get_questions(false)) !== 2) return false;

    // Проверяем сохранение метаданных последнего импорта в настройках
    $quiz = Quiz::get($quiz_id);
    $last_meta = $quiz->get_setting('last_network_import');
    if (empty($last_meta) || !is_array($last_meta)) return false;
    if (($last_meta['imported_count'] ?? 0) !== 2) return false;
    if (($last_meta['status'] ?? '') !== 'success') return false;
    if (empty($quiz->get_setting('last_network_import_hash'))) return false;

    // 4. Повторный запуск без изменения файла: должен сработать hash-контроль (unchanged = true, 0 новых вопросов)
    $res2 = NetworkImport::auto_import_for_quiz($quiz, false);
    if (!$res2['success']) return false;
    if (empty($res2['unchanged'])) return false;
    if ($res2['imported_count'] !== 0) return false;
    if (count($quiz->get_questions(false)) !== 2) return false;

    // 5. Обновляем файл в сетевой папке (добавляем еще один вопрос)
    $csv_v2 = $csv_v1 . "numeric;Чему равно число Пи до сотых?;3;;3.14\n";
    file_put_contents($temp_net_dir . '/import/questions.csv', $csv_v2);

    // Запускаем автоимпорт в режиме append: должен обнаружить новый хэш и добавить новые вопросы
    $res3 = NetworkImport::auto_import_for_quiz($quiz, false);
    if (!$res3['success'] || !empty($res3['unchanged'])) return false;
    if ($res3['imported_count'] !== 3) return false;
    // 2 старых + 3 новых = 5
    if (count($quiz->get_questions(false)) !== 5) return false;

    // 6. Проверяем режим полной замены (replace)
    $csv_v3 = "тип;вопрос;баллы;варианты;правильный\n" .
              "single;Столица России?;5;*Москва | Санкт-Петербург | Казань;Москва\n";
    file_put_contents($temp_net_dir . '/import/questions.csv', $csv_v3);

    $quiz->update_setting('network_auto_import_mode', 'replace');
    $res4 = NetworkImport::auto_import_for_quiz($quiz, true, [], 'replace');
    if (!$res4['success']) return false;
    if ($res4['imported_count'] !== 1) return false;
    // В режиме replace старые 5 удалены, остался ровно 1
    if (count($quiz->get_questions(false)) !== 1) return false;

    // 7. Проверяем регистрацию интервала 1 минута (every_minute = 60s) в WP-Cron расписаниях
    $schedules = wp_get_schedules();
    if (!isset($schedules['every_minute']) || $schedules['every_minute']['interval'] !== 60) return false;

    // Проверяем работу фонового воркера с интервалом 1 минута
    $quiz->update_setting('network_auto_import_interval', 'every_minute');
    $quiz->update_setting('last_network_import_time', time() - 120); // 2 минуты назад
    $cron_results = NetworkImport::run_scheduled_auto_imports();
    if (!is_array($cron_results)) return false;

    // Очистка
    Quiz::delete($quiz_id);
    @unlink($temp_net_dir . '/import/questions.csv');
    @rmdir($temp_net_dir . '/import');
    @rmdir($temp_net_dir);
    return true;
});

// ---------------------------------------------------------------------------
// 69. Импорт, экспорт вопросов и проверка подключения к сетевой папке
// ---------------------------------------------------------------------------
$it('Импорт, экспорт вопросов и проверка подключения к сетевой папке (test_connection, export_questions, network import)', function (): bool {
    // 1. Проверка test_connection с пустым путем, путем по умолчанию и с плейсхолдером (\\server\share\ или /mnt/share)
    $test_conn_default = NetworkExport::test_connection(['path' => '']);
    if (empty($test_conn_default['success'])) return false;

    // Плейсхолдер не должен ломать проверку подключения, плагин должен мягко переходить к папке по умолчанию
    $test_conn_placeholder = NetworkExport::test_connection(['path' => '\\\\server\\share\\ или /mnt/share']);
    if (empty($test_conn_placeholder['success'])) return false;

    // 2. Создаем олимпиаду и набор вопросов разных типов
    $quiz_id = Quiz::create([
        'title'       => 'Тест импорта и экспорта заданий олимпиады',
        'status'      => 'publish',
        'settings'    => [
            'enable_network_export' => 1,
        ],
    ]);
    $quiz = Quiz::get($quiz_id);
    if (!$quiz) return false;

    $q1_id = Question::create([
        'quiz_id'       => $quiz_id,
        'question_type' => 'single',
        'title'         => 'Столица Франции',
        'points'        => 3,
        'penalty'       => 1,
        'options'       => [
            'group_name'  => 'География',
            'explanation' => 'Париж - столица Франции.',
            'choices'     => [
                ['id' => 'c1', 'text' => 'Париж', 'is_correct' => 1],
                ['id' => 'c2', 'text' => 'Марсель', 'is_correct' => 0],
                ['id' => 'c3', 'text' => 'Лион', 'is_correct' => 0],
            ],
        ],
    ]);

    $q2_id = Question::create([
        'quiz_id'       => $quiz_id,
        'question_type' => 'number',
        'title'         => 'Число Пи с точностью до сотых',
        'points'        => 5,
        'penalty'       => 0,
        'options'       => [
            'group_name'   => 'Математика',
            'target_value' => 3.14,
            'tolerance'    => 0.01,
        ],
    ]);

    // 3. Тестируем генерацию CSV и JSON экспорта заданий
    $csv_content = Export::export_quiz_questions_csv($quiz, false);
    if (empty($csv_content) || !str_contains($csv_content, 'type;title;points')) return false;
    if (!str_contains($csv_content, 'Столица Франции')) return false;
    if (!str_contains($csv_content, '*Париж')) return false;

    $json_content = Export::export_quiz_questions_json($quiz, false);
    if (empty($json_content) || !str_contains($json_content, '"questions"')) return false;
    $decoded_json = json_decode($json_content, true);
    if (!is_array($decoded_json) || count($decoded_json['questions'] ?? []) !== 2) return false;

    // 4. Тестируем экспорт заданий в сетевую папку (подпапка «вопросы/»)
    $upload_dir = wp_upload_dir();
    $test_network_dir = wp_normalize_path(rtrim($upload_dir['basedir'], '/\\') . '/test_net_q_export_' . time());
    @wp_mkdir_p($test_network_dir);

    $export_res = NetworkExport::export_questions_to_network($quiz, ['path' => $test_network_dir]);
    if (empty($export_res['success'])) return false;

    $csv_relative = current(array_filter($export_res['files'], static fn($f) => str_starts_with($f, 'вопросы/вопросы_') && str_ends_with($f, '.csv')));
    $json_relative = current(array_filter($export_res['files'], static fn($f) => str_starts_with($f, 'вопросы/вопросы_') && str_ends_with($f, '.json')));
    $expected_csv = $test_network_dir . '/' . $csv_relative;
    $expected_json = $test_network_dir . '/' . $json_relative;
    $expected_list = $test_network_dir . '/вопросы/список_заданий.csv';

    if (!file_exists($expected_csv) || !file_exists($expected_json) || !file_exists($expected_list)) {
        return false;
    }

    // 5. Тестируем сканирование сетевой папки на наличие файлов заданий
    $scanned_files = NetworkImport::scan_network_folder_for_import_files($quiz, ['path' => $test_network_dir]);
    $has_questions_csv = false;
    foreach ($scanned_files as $f) {
        if ($f['name'] === basename($csv_relative)) {
            $has_questions_csv = true;
            break;
        }
    }
    if (!$has_questions_csv) return false;

    // 6. Тестируем импорт вопросов из только что экспортированного сетевого файла в новую олимпиаду
    $target_quiz_id = Quiz::create([
        'title'  => 'Приёмник импорта заданий',
        'status' => 'publish',
    ]);
    $target_quiz = Quiz::get($target_quiz_id);
    if (!$target_quiz) return false;

    $import_res = NetworkImport::import_from_network($target_quiz_id, $csv_relative, 'append', ['path' => $test_network_dir]);
    if (empty($import_res['success']) || ($import_res['imported_count'] ?? 0) < 2) return false;

    $target_questions = $target_quiz->get_questions(false);
    if (count($target_questions) !== 2) return false;

    // Очистка
    NetworkExport::delete_directory_recursive($test_network_dir);
    Quiz::delete($quiz_id);
    Quiz::delete($target_quiz_id);
    return true;
});

// ---------------------------------------------------------------------------
$it('Стилистика страницы тестирования: пресеты, санитизация, генерация CSS и вывод темы', function (): bool {
    // 1. Все пресеты содержат полный и корректный набор цветов
    $presets = Style::presets();
    if (count($presets) < 8) return false;
    foreach ($presets as $preset_key => $preset) {
        foreach (['primary', 'primary_hover', 'accent', 'success', 'danger', 'warning', 'bg', 'surface', 'border', 'text', 'text_muted'] as $field) {
            if (!isset($preset[$field])) return false;
            if (Style::hex((string)$preset[$field], '') === '') return false;
        }
    }

    // 2. Санитизация пользовательского ввода
    $sanitized = Style::sanitize_post([
        'style_preset'         => 'dark',
        'style_primary'        => '#FF0000',
        'style_bg'             => 'not-a-color',
        'style_font'           => 'georgia',
        'style_shadow'         => 'strong',
        'style_button_style'   => 'outline',
        'style_font_size'      => '999',
        'style_radius'         => '-50',
        'style_max_width'      => 'abc',
        'style_line_height'    => '9.9',
        'style_custom_css'     => '.x{color:red}</style><script>alert(1)</script>',
        'style_canvas_bg_enabled' => '1',
        'style_canvas_bg'      => '#123456',
    ]);
    if ($sanitized['preset'] !== 'dark') return false;
    if ($sanitized['primary'] !== '#ff0000') return false;
    if ($sanitized['bg'] !== Style::defaults()['bg']) return false;
    if ($sanitized['font'] !== 'georgia' || $sanitized['shadow'] !== 'strong' || $sanitized['button_style'] !== 'outline') return false;
    if ($sanitized['font_size'] !== 24) return false;
    if ($sanitized['radius'] !== 0) return false;
    if ($sanitized['max_width'] !== 860) return false;
    if (abs($sanitized['line_height'] - 2.2) > 0.001) return false;
    if (str_contains($sanitized['custom_css'], '</style') || str_contains($sanitized['custom_css'], '<script')) return false;
    if ($sanitized['canvas_bg'] !== '#123456') return false;

    // Выключенный чекбокс фона сбрасывает цвет
    $no_canvas = Style::sanitize_post(['style_canvas_bg' => '#123456']);
    if ($no_canvas['canvas_bg'] !== '') return false;

    // 3. Ограничение пользовательского CSS рамками олимпиады
    $scoped = Style::scope_css('.a{color:red}@media(max-width:600px){.b{color:blue}}@font-face{font-family:X;src:url(x.woff)}', '#scope');
    if (!str_contains($scoped, '#scope .a{color:red}')) return false;
    if (!str_contains($scoped, '@media(max-width:600px){#scope .b{color:blue}}')) return false;
    if (!str_contains($scoped, '@font-face{font-family:X;src:url(x.woff)}')) return false;
    if (str_contains($scoped, '#scope @font-face')) return false;

    // 4. Сохранение и генерация CSS для олимпиады
    $quiz_id = Quiz::create([
        'title'  => 'Олимпиада с кастомной стилистикой',
        'status' => 'publish',
    ]);
    $quiz = Quiz::get($quiz_id);
    if (!$quiz) return false;

    $quiz->update(['settings' => ['style' => [
        'preset'        => 'custom',
        'primary'       => '#7c3aed',
        'primary_hover' => '#6d28d9',
        'accent'        => '#a855f7',
        'success'       => '#10b981',
        'danger'        => '#ef4444',
        'warning'       => '#f59e0b',
        'bg'            => '#0f172a',
        'surface'       => '#1e293b',
        'border'        => '#334155',
        'text'          => '#e2e8f0',
        'text_muted'    => '#94a3b8',
        'canvas_bg'     => '#0b1120',
        'font'          => 'georgia',
        'font_size'     => 18,
        'title_size'    => 34,
        'max_width'     => 1000,
        'radius'        => 20,
        'card_padding'  => 40,
        'shadow'        => 'strong',
        'button_style'  => 'gradient',
        'button_radius' => 30,
        'line_height'   => 1.7,
        'custom_css'    => '.wpo-quiz-title{letter-spacing:1px}',
    ]]]);

    $quiz = Quiz::get($quiz_id);
    $style = Style::get($quiz);
    if ($style['radius'] !== 20 || $style['font'] !== 'georgia' || $style['primary'] !== '#7c3aed') return false;

    $css = Style::build_css($quiz);
    $needles = [
        '#wpo-quiz-' . $quiz_id . '.wpo-quiz-wrapper{',
        '--wpo-primary:#7c3aed;',
        '--wpo-bg:#0f172a;',
        '--wpo-font-size:18px;',
        '--wpo-title-size:34px;',
        '--wpo-max-width:1000px;',
        '--wpo-card-padding:40px;',
        '--wpo-btn-radius:30px;',
        'Georgia',
        'linear-gradient(135deg',
        '#wpo-quiz-' . $quiz_id . ' .wpo-quiz-title{letter-spacing:1px}',
        '--wpo-primary-50:#',
        '--wpo-success-bg:#',
    ];
    foreach ($needles as $needle) {
        if (!str_contains($css, $needle)) return false;
    }
    if (!str_contains($css, 'background-color:#0b1120')) return false;

    // 5. Тема выводится на странице тестирования и не протекает в другие олимпиады
    $html = Shortcodes::render_quiz(['id' => $quiz_id]);
    if (!str_contains($html, '<style id="wpo-style-' . $quiz_id . '">')) return false;
    if (!str_contains($html, '--wpo-primary:#7c3aed;')) return false;

    $other_id = Quiz::create(['title' => 'Олимпиада со стилем по умолчанию', 'status' => 'publish']);
    $other_quiz = Quiz::get($other_id);
    $other_css = Style::build_css($other_quiz);
    if (str_contains($other_css, '#7c3aed')) return false;
    if (!str_contains($other_css, '--wpo-primary:#2563eb;')) return false;
    if (str_contains($other_css, 'letter-spacing:1px')) return false;

    // 6. Сброс настроек к значениям по умолчанию
    $defaults = Style::normalize([]);
    if ($defaults['max_width'] !== 860 || $defaults['shadow'] !== 'medium') return false;

    Quiz::delete($quiz_id);
    Quiz::delete($other_id);
    return true;
});

// ---------------------------------------------------------------------------
$it('Целостность классов: нет ссылок на несуществующие namespace-классы (fatal-регресс)', function (): bool {
    $root = dirname(__DIR__);
    $files = [];
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php'
            && !str_contains($file->getPathname(), '/tests/')) {
            $files[] = $file->getPathname();
        }
    }
    if (count($files) < 15) return false;

    $problems = [];
    foreach ($files as $file) {
        $src = (string)file_get_contents($file);

        // Убираем комментарии и строковые литералы, чтобы не ловить ложные срабатывания
        $clean = '';
        foreach (token_get_all($src) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) {
                    $clean .= ' ';
                } else {
                    $clean .= $token[1];
                }
            } else {
                $clean .= $token;
            }
        }

        if (!preg_match('/^\s*namespace\s+([^;{]+)/m', $src, $ns_match)) {
            continue;
        }
        $namespace = trim($ns_match[1]);

        $imports = [];
        preg_match_all('/^\s*use\s+([^;]+);/m', $src, $use_match);
        foreach ($use_match[1] as $import) {
            $import = trim($import);
            if (str_starts_with($import, 'function ') || str_starts_with($import, 'const ') || str_contains($import, ',')) {
                continue;
            }
            if (preg_match('/\s+as\s+(\w+)$/i', $import, $alias)) {
                $imports[$alias[1]] = trim(substr($import, 0, -strlen($alias[0])));
            } else {
                $parts = explode('\\', $import);
                $imports[end($parts)] = $import;
            }
        }

        preg_match_all('/(?<![\\\\\w$>])([A-Z][A-Za-z0-9_]*)\s*::/', $clean, $static_refs);
        preg_match_all('/\bnew\s+([A-Z][A-Za-z0-9_]*)\s*[\(;]/', $clean, $new_refs);
        $refs = array_unique(array_merge($static_refs[1], $new_refs[1]));

        foreach ($refs as $ref) {
            if (in_array($ref, ['self', 'static', 'parent'], true)) {
                continue;
            }
            $fqn = $imports[$ref] ?? ($namespace . '\\' . $ref);
            if (class_exists($fqn) || interface_exists($fqn) || trait_exists($fqn) || enum_exists($fqn)) {
                continue;
            }
            if (class_exists($ref) || interface_exists($ref)) {
                continue;
            }
            $problems[] = basename($file) . ': ' . $ref . ' → ' . $fqn;
        }
    }

    if ($problems) {
        echo "\n    " . implode("\n    ", array_unique($problems)) . "\n    ";
        return false;
    }

    // Отдельно проверяем ключевые классы админ-контроллера, чьи импорты ломали панель.
    foreach ([
        'WPOlympiad\\Style'         => dirname(__DIR__) . '/includes/class-style.php',
        'WPOlympiad\\NetworkImport' => dirname(__DIR__) . '/includes/class-network-import.php',
        'WPOlympiad\\ImportTemplate' => dirname(__DIR__) . '/includes/class-import-template.php',
        'WPOlympiad\\Admin\\Admin'  => dirname(__DIR__) . '/admin/class-admin.php',
    ] as $class => $path) {
        if (!file_exists($path)) return false;
        if (!class_exists($class)) return false;
    }

    return true;
});

// ---------------------------------------------------------------------------
$it('Шаблон импорта: 13 типов заданий, русские заголовки, сквозной импорт CSV/XLSX/JSON', function (): bool {
    $supported = array_keys(Question::supported_types());
    if (count($supported) !== 13) return false;

    // 1. Схема шаблона: 17 колонок, порядок совпадает с column_keys(), все типы покрыты
    $columns = ImportTemplate::columns();
    $keys = ImportTemplate::column_keys();
    if (count($keys) < 12 || array_values(array_keys($columns)) !== $keys) return false;
    if ($keys[0] !== 'type' || $keys[1] !== 'title') return false;

    $samples = ImportTemplate::samples();
    if (array_keys($samples) !== $supported) return false;
    foreach ($samples as $type => $sample) {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $sample)) {
                echo "\n    Тип {$type}: нет колонки {$key}\n    ";
                return false;
            }
        }
        if (trim((string)$sample['title']) === '') return false;
    }

    // 2. Все русские заголовки распознаются обратно в канонические ключи
    foreach ($columns as $key => $meta) {
        if (ImportTemplate::normalize_header($meta['label']) !== $key) {
            echo "\n    Заголовок «{$meta['label']}» не распознан как {$key}\n    ";
            return false;
        }
    }
    if (ImportTemplate::normalize_header('Тип вопроса (обязательно)') !== 'type') return false;
    if (ImportTemplate::normalize_header('Варианты / пары / элементы') !== 'choices') return false;

    // 3. Русские названия типов → коды плагина, неизвестный тип → single
    $type_aliases = [
        'один выбор'       => 'single',
        'множественный'    => 'multi',
        'да/нет'           => 'boolean',
        'число'            => 'number',
        'текст'            => 'text',
        'соответствие'     => 'matching',
        'последовательность' => 'ordering',
        'пропуски'         => 'fill_blank',
        'шкала'            => 'slider',
        'анаграмма'        => 'word_scramble',
        'диктант'          => 'dictation',
        'эссе'             => 'essay',
        'radio'            => 'single',
        'checkbox'         => 'multi',
        'cloze'            => 'fill_blank',
    ];
    foreach ($type_aliases as $alias => $expected) {
        if (ImportTemplate::normalize_type($alias) !== $expected) {
            echo "\n    Тип «{$alias}» → " . ImportTemplate::normalize_type($alias) . " (ожидался {$expected})\n    ";
            return false;
        }
    }
    if (ImportTemplate::normalize_type('совершенно неизвестный тип') !== 'single') return false;

    // 4. CSV-шаблон: BOM, разделитель «;», 13 строк, все типы разбираются без ошибок
    $csv = NetworkImport::get_template_csv();
    if (!str_starts_with($csv, "\xEF\xBB\xBF")) return false;
    $header_line = strtok(substr($csv, 3), "\n");
    if ($header_line !== implode(';', $keys)) return false;

    $analysis = NetworkImport::analyze_questions_content($csv, 'template.csv');
    if ($analysis['stats']['total'] !== 13 || $analysis['stats']['importable'] !== 13) {
        echo "\n    CSV: " . json_encode($analysis['stats'], JSON_UNESCAPED_UNICODE) . "\n    ";
        return false;
    }
    if ($analysis['stats']['errors'] !== 0) return false;
    $parsed_types = array_column($analysis['questions'], 'question_type');
    if (array_values(array_unique($parsed_types)) !== $supported) return false;

    // 5. JSON-шаблон: тот же набор типов + метаданные формата
    $json_raw = NetworkImport::get_template_json();
    $json_data = json_decode($json_raw, true);
    if (!is_array($json_data) || ($json_data['format'] ?? '') !== 'wp-olympiad-questions') return false;
    if (count($json_data['questions'] ?? []) !== 13) return false;
    $json_parsed = NetworkImport::parse_questions_content($json_raw, 'template.json');
    if (count($json_parsed) !== 13) return false;

    // 6. README содержит все 13 типов и подсказку по extra
    $readme = NetworkImport::get_template_readme();
    foreach ($supported as $type) {
        if (mb_strpos($readme, $type) === false) return false;
    }
    if (mb_strpos($readme, 'extra') === false) return false;

    // 7. Единственный XLSX-шаблон: все 13 типов, без мусора с листа «Инструкция»
    foreach (['questions_template.xlsx'] as $excel_file) {
        $path = dirname(__DIR__) . '/templates/' . $excel_file;
        if (!file_exists($path)) return false;
        $binary = (string)file_get_contents($path);
        $excel_analysis = NetworkImport::analyze_questions_content($binary, $excel_file);
        if ($excel_analysis['stats']['importable'] !== 13 || $excel_analysis['stats']['errors'] !== 0) {
            echo "\n    {$excel_file}: " . json_encode($excel_analysis['stats'], JSON_UNESCAPED_UNICODE) . "\n    ";
            return false;
        }
        $excel_types = array_column($excel_analysis['questions'], 'question_type');
        if (array_values(array_unique($excel_types)) !== $supported) return false;
    }

    // 8. Сквозной импорт в базу: 13 вопросов, параметры каждого типа сохранены
    $quiz_id = Quiz::create([
        'title'  => 'Олимпиада: полный шаблон (13 типов)',
        'status' => 'draft',
    ]);
    if (!$quiz_id) return false;

    $import = NetworkImport::import_to_quiz($quiz_id, $analysis['questions'], 'replace');
    if (empty($import['success']) || (int)$import['imported_count'] !== 13) return false;

    $stored = Question::get_by_quiz($quiz_id);
    if (count($stored) !== 13) return false;

    $by_type = [];
    foreach ($stored as $question) {
        $by_type[$question->question_type] = $question;
    }
    if (count($by_type) !== 13) return false;

    // single: 4 варианта, один правильный
    $single = $by_type['single'];
    $single_choices = $single->options['choices'] ?? [];
    if (count($single_choices) !== 4) return false;
    if (count(array_filter($single_choices, fn($c) => !empty($c['is_correct']))) !== 1) return false;
    if (($single->options['explanation'] ?? '') === '') return false;

    // multi: три правильных, вес пункта 2 → максимум 6 баллов
    $multi = $by_type['multi'];
    $multi_correct = array_filter($multi->options['choices'] ?? [], fn($c) => !empty($c['is_correct']));
    if (count($multi_correct) !== 3) return false;
    foreach ($multi_correct as $choice) {
        if ((int)($choice['points'] ?? 0) !== 2) return false;
    }
    if ($multi->points !== 6) return false;

    // dropdown: 4 варианта
    if (count($by_type['dropdown']->options['choices'] ?? []) !== 4) return false;

    // boolean: ровно два варианта, правильный = «да»
    $boolean_choices = $by_type['boolean']->options['choices'] ?? [];
    if (count($boolean_choices) !== 2) return false;
    if (empty($boolean_choices[0]['is_correct'])) return false;

    // number: 9.8 ± 0.1
    $number = $by_type['number']->options;
    if (abs(($number['target_value'] ?? 0) - 9.8) > 0.001) return false;
    if (abs(($number['tolerance'] ?? 0) - 0.1) > 0.001) return false;

    // text: 4 синонима, режим сравнения fuzzy
    $text = $by_type['text']->options;
    if (count($text['answers'] ?? []) !== 4) return false;
    if (($text['match_mode'] ?? '') !== 'fuzzy') return false;

    // matching: 4 пары
    $pairs = $by_type['matching']->options['pairs'] ?? [];
    if (count($pairs) !== 4) return false;
    if (($pairs[0]['left'] ?? '') !== 'Франция' || ($pairs[0]['right'] ?? '') !== 'Париж') return false;

    // ordering: 4 элемента в правильном порядке
    $items = $by_type['ordering']->options['items'] ?? [];
    if (count($items) !== 4 || ($items[0]['text'] ?? '') !== 'Древний мир') return false;

    // fill_blank: два пропуска выведены из текста
    $fill = $by_type['fill_blank']->options;
    if (count($fill['blanks'] ?? []) !== 2) return false;
    if (($fill['blanks'][0]['answers'][0] ?? '') !== '1242') return false;
    if (($fill['choice_mode'] ?? '') !== 'input') return false;

    // slider: 0…10 шаг 1, цель 8, допуск 1
    $slider = $by_type['slider']->options;
    if ((float)$slider['min'] !== 0.0 || (float)$slider['max'] !== 10.0) return false;
    if ((float)$slider['step'] !== 1.0) return false;
    if (abs((float)$slider['target_value'] - 8.0) > 0.001) return false;
    if (abs((float)$slider['tolerance'] - 1.0) > 0.001) return false;

    // word_scramble: слово и лишняя буква
    $scramble = $by_type['word_scramble']->options;
    if (($scramble['words'][0] ?? '') !== 'КВАНТ') return false;
    if (($scramble['target_word'] ?? '') !== 'КВАНТ') return false;
    if (($scramble['extra_letters'] ?? '') !== 'Ы') return false;

    // dictation: два пропуска, пропорциональное начисление, 1 допустимая ошибка
    $dictation = $by_type['dictation']->options;
    if (count($dictation['gaps'] ?? []) !== 2) return false;
    if (($dictation['scoring_mode'] ?? '') !== 'proportional') return false;
    if ((int)($dictation['allowed_mistakes'] ?? -1) !== 1) return false;
    if (($dictation['gaps']['d_1']['correct'] ?? '') !== 'ск') return false;

    // essay: объём и ключевые слова
    $essay = $by_type['essay']->options;
    if ((int)$essay['min_words'] !== 50 || (int)$essay['max_words'] !== 250) return false;
    if (count($essay['keywords'] ?? []) !== 3) return false;
    if ((int)$essay['min_keywords'] !== 2) return false;

    // 9. Отчёт о разборе: проблемная строка не мешает импорту остальных
    $broken_csv = "type;title;points;choices;correct;answers;target_value\n"
        . "single;Корректный вопрос?;2;Да | *Нет;;;;\n"
        . "single;;5;А | *Б;;;;\n"
        . "text;Столица России?;3;;;Москва | РФ;\n";
    $broken = NetworkImport::analyze_questions_content($broken_csv, 'broken.csv');
    if ($broken['stats']['total'] !== 3) return false;
    if ($broken['stats']['importable'] !== 2) return false;
    if ($broken['stats']['errors'] !== 1) return false;
    $error_rows = array_values(array_filter($broken['report'], fn($r) => $r['status'] === 'error'));
    if (count($error_rows) !== 1 || (int)$error_rows[0]['row'] !== 3) return false;

    // 10. Проверка файла из сетевой папки без импорта (analyze_import_file)
    $net_dir = '/tmp/wpo_tpl76_' . uniqid();
    wp_mkdir_p($net_dir . '/import');
    file_put_contents($net_dir . '/import/questions_template.csv', $csv);
    $probe = NetworkImport::analyze_import_file(Quiz::get($quiz_id), 'import/questions_template.csv', ['path' => $net_dir, 'type' => 'local']);
    if (empty($probe['success'])) return false;
    if ((int)$probe['stats']['importable'] !== 13) return false;
    if (mb_strpos($probe['message'], '13') === false) return false;

    exec('rm -rf ' . escapeshellarg($net_dir));
    Quiz::delete($quiz_id);
    return true;
});

// ---------------------------------------------------------------------------
$it('Файлы заданий из сетевой папки: кириллица, подпапки, регистр, кодировка и понятные ошибки', function (): bool {
    $dir = sys_get_temp_dir() . '/wpo_net_names_' . uniqid();
    wp_mkdir_p($dir . '/Задания');

    $quiz_id = Quiz::create([
        'title'  => 'Тест чтения файлов из сетевой папки',
        'status' => 'publish',
    ]);
    $quiz = Quiz::get($quiz_id);
    if (!$quiz) return false;

    $cfg = ['path' => $dir, 'type' => 'local'];
    $xlsx = NetworkImport::get_template_xlsx();
    if ($xlsx === '') return false;

    // 1. Имя файла: кириллица, подпапка, другой регистр, имя в CP1251
    file_put_contents($dir . '/тест.xlsx', $xlsx);
    file_put_contents($dir . '/Задания/вложенный.xlsx', $xlsx);
    file_put_contents($dir . '/Тест-Регистр.XLSX', $xlsx);
    file_put_contents($dir . '/' . mb_convert_encoding('кириллица-1251.xlsx', 'Windows-1251', 'UTF-8'), $xlsx);
    file_put_contents($dir . '/пустой.xlsx', '');

    if (NetworkImport::read_network_file($quiz, 'тест.xlsx', $cfg) === null) return false;
    if (NetworkImport::read_network_file($quiz, 'вложенный.xlsx', $cfg) === null) return false;
    if (NetworkImport::read_network_file($quiz, 'тест-регистр.xlsx', $cfg) === null) return false;
    if (NetworkImport::read_network_file($quiz, 'кириллица-1251.xlsx', $cfg) === null) return false;

    // 2. Пустой файл: понятная причина вместо общего «не удалось прочитать»
    $err = null;
    if (NetworkImport::read_network_file($quiz, 'пустой.xlsx', $cfg, $err) !== null) return false;
    if ($err === null || mb_strpos($err, 'пуст') === false) return false;

    // 3. Отсутствующий файл: причина + перечень доступных файлов
    $err = null;
    if (NetworkImport::read_network_file($quiz, 'нет-такого.xlsx', $cfg, $err) !== null) return false;
    if ($err === null || mb_strpos($err, 'не найден') === false) return false;
    if (mb_strpos($err, 'тест.xlsx') === false) return false;

    // 4. Сообщения о формате: текст с расширением .xlsx и старый/закрытый паролем .xls
    $broken = NetworkImport::analyze_questions_content('это не excel', 'битый.xlsx');
    if (empty($broken['notice'])) return false;

    $ole = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 512);
    $legacy = NetworkImport::analyze_questions_content($ole, 'старый.xlsx');
    if (empty($legacy['notice']) || mb_strpos($legacy['notice'], 'xls') === false) return false;

    // 5. Реальный импорт по имени из подпапки
    $res = NetworkImport::import_from_network($quiz_id, 'вложенный.xlsx', 'append', $cfg);
    if (empty($res['success']) || (int)($res['imported_count'] ?? 0) < 1) return false;

    exec('rm -rf ' . escapeshellarg($dir));
    Quiz::delete($quiz_id);
    return true;
});

// ---------------------------------------------------------------------------
$it('Новый вопрос: галочка «Скрыть баллы за этот вопрос в тесте» включена по умолчанию', function (): bool {
    // 1. Карточка нового задания рендерится с включённой галочкой и индикатором в шапке
    $q = new Question([
        'id'            => 0,
        'quiz_id'       => 0,
        'question_type' => 'single',
        'title'         => '',
        'content'       => '',
        'points'        => 2,
        'penalty'       => 0,
        'sort_order'    => 999,
        'options'       => ['choices' => [['id' => 'c1', 'text' => '', 'is_correct' => 1]]],
    ]);
    $quiz_shared_texts = [];
    ob_start();
    include WP_OLYMPIAD_PATH . 'admin/views/question-item-card.php';
    $new_card = (string)ob_get_clean();

    if (!preg_match('/class="wpo-q-hide-points"\s+checked/', $new_card)) return false;
    if (mb_strpos($new_card, 'Баллы скрыты от участников') === false) return false;

    // 2. Сохранение вопроса через тот же запрос, что отправляет редактор (show_points = 0)
    $quiz_id = Quiz::create(['title' => 'Тест галочки скрытия баллов', 'status' => 'publish']);
    $new_id = Question::create([
        'quiz_id'       => $quiz_id,
        'question_type' => 'single',
        'title'         => 'Скрытые баллы',
        'points'        => 2,
        'options'       => [
            'show_points' => 0,
            'choices'     => [
                ['id' => 'c1', 'text' => 'Да', 'is_correct' => 1],
                ['id' => 'c2', 'text' => 'Нет', 'is_correct' => 0],
            ],
        ],
    ]);
    $saved = Question::get($new_id);
    if ((int)($saved->options['show_points'] ?? 1) !== 0) return false;
    if (!empty($saved->get_public_data()['show_points'])) return false; // участник не увидит баллы

    // 3. Существующий вопрос без явной настройки наследует настройку олимпиады и не скрывает баллы
    // Карточка читает переменную $q, поэтому подменяем её на «старый» вопрос
    $q = new Question([
        'id'            => 999999, // несуществующий ID: карточка не подгружает данные из БД
        'quiz_id'       => $quiz_id,
        'question_type' => 'single',
        'title'         => 'Наследует настройку',
        'points'        => 1,
        'penalty'       => 0,
        'options'       => ['choices' => [['id' => 'c1', 'text' => 'Да', 'is_correct' => 1]]],
    ]);
    ob_start();
    include WP_OLYMPIAD_PATH . 'admin/views/question-item-card.php';
    $old_card = (string)ob_get_clean();
    if (preg_match('/class="wpo-q-hide-points"\s+checked/', $old_card)) return false;

    Quiz::delete($quiz_id);
    return true;
});

// ---------------------------------------------------------------------------
$it('Сетевая папка: сначала import, затем вложенные каталоги и понятная диагностика', function (): bool {
    $dir = sys_get_temp_dir() . '/wpo_scan_' . uniqid();
    wp_mkdir_p($dir . '/Задания/2026');
    wp_mkdir_p($dir . '/import');
    wp_mkdir_p($dir . '/пусто');
    wp_mkdir_p($dir . '/только_xls');

    $quiz_id = Quiz::create(['title' => 'Тест поиска файлов в сетевой папке', 'status' => 'publish']);
    try {
        $quiz = Quiz::get($quiz_id);
        if (!$quiz) return false;
        $cfg = ['path' => $dir, 'type' => 'local'];
        $xlsx = NetworkImport::get_template_xlsx();
        if ($xlsx === '') return false;

        file_put_contents($dir . '/Задания/2026/тест.xlsx', $xlsx);
        file_put_contents($dir . '/import/questions.csv', "type;title;points;choices;correct\nsingle;Вопрос в import;1;Да|Нет;1\n");
        file_put_contents($dir . '/import/README_IMPORT.txt', 'инструкция');
        $files = NetworkImport::scan_network_folder_for_import_files($quiz, $cfg);
        if (array_column($files, 'path') !== ['import/questions.csv']) return false;
        $probe = NetworkImport::analyze_import_file($quiz, '', $cfg);
        if (empty($probe['success']) || ($probe['source'] ?? '') !== 'import/questions.csv') return false;
        $import = NetworkImport::auto_import_for_quiz($quiz, true, $cfg, 'append', '', true, false);
        if (empty($import['success']) || ($import['filename'] ?? '') !== 'import/questions.csv') return false;

        // Если в import нет входных файлов, работает прежний трёхуровневый поиск.
        unlink($dir . '/import/questions.csv');
        $files = NetworkImport::scan_network_folder_for_import_files($quiz, $cfg);
        if (($files[0]['path'] ?? '') !== 'Задания/2026/тест.xlsx') return false;
        if (in_array('import/README_IMPORT.txt', array_column($files, 'path'), true)) return false;
        $probe = NetworkImport::analyze_import_file($quiz, '', $cfg);
        if (empty($probe['success']) || (int)$probe['stats']['importable'] < 13) return false;
        $import = NetworkImport::auto_import_for_quiz(Quiz::get($quiz_id), true, $cfg, 'append', '', true, false);
        if (empty($import['success']) || ($import['filename'] ?? '') !== 'Задания/2026/тест.xlsx') return false;

        $empty = NetworkImport::analyze_import_file($quiz, '', ['path' => $dir . '/пусто', 'type' => 'local']);
        if (!empty($empty['success']) || mb_strpos($empty['message'], 'пуста') === false) return false;
        $missing = NetworkImport::analyze_import_file($quiz, '', ['path' => $dir . '/нет-такой-папки', 'type' => 'local']);
        if (mb_strpos($missing['message'], 'не существует') === false) return false;
        file_put_contents($dir . '/только_xls/старый.xls', 'x');
        $xls = NetworkImport::analyze_import_file($quiz, '', ['path' => $dir . '/только_xls', 'type' => 'local']);
        return mb_strpos($xls['message'], '.xlsx') !== false;
    } finally {
        exec('rm -rf ' . escapeshellarg($dir));
        Quiz::delete($quiz_id);
    }
});

// 75. Чтение файлов заданий из сетевой папки: локальный путь, WebDAV, SMB, FTP
$it("Чтение файлов заданий из сетевой папки (local/WebDAV/SMB/FTP)", function () {
    $dir = '/tmp/wpo_read_' . uniqid();
    wp_mkdir_p($dir);

    $quiz_id = Quiz::create(['title' => 'Тест чтения файлов заданий', 'status' => 'publish']);
    $quiz = Quiz::get($quiz_id);
    if (!$quiz) return false;

    $cfg = ['path' => $dir, 'type' => 'local'];

    // 1. Точное имя с кириллицей, дефисом и цифрой + BOM (как у файла из Excel)
    $csv = "\xEF\xBB\xBF" . "type;title;points;choices;correct\nsingle;Вопрос из сетевой папки;2;Да|Нет;1\n";
    file_put_contents($dir . '/вопросы_olympiad-2.csv', $csv);

    $first = NetworkImport::import_from_network($quiz_id, 'вопросы_olympiad-2.csv', 'append', $cfg);
    if (empty($first['success'])) {
        echo "\n  ¬ ошибка: " . ($first['message'] ?? '') . "\n";
        exec('rm -rf ' . escapeshellarg($dir));
        Quiz::delete($quiz_id);
        return false;
    }
    if ((int)$first['imported_count'] !== 1) return false;

    // 2. Файл только из пробелов и переводов строк — понятное сообщение, а не общее «не удалось прочитать»
    file_put_contents($dir . '/пустой.csv', "   \r\n\r\n  \n");
    $blank = NetworkImport::import_from_network($quiz_id, 'пустой.csv', 'append', $cfg);
    if (!empty($blank['success'])) return false;
    if (mb_strpos((string)$blank['message'], 'не содержит данных') === false) return false;

    // 3. Полный цикл: экспорт вопросов олимпиады → запись в папку → чтение и импорт без ошибок
    //    Исходная олимпиада создаётся из шаблона (13 типов заданий) — тест не зависит от данных сайта.
    $source_id = Quiz::create(['title' => 'Источник для выгрузки заданий', 'status' => 'publish']);
    $source = Quiz::get($source_id);
    if (!$source) return false;

    file_put_contents($dir . '/шаблон-источник.csv', NetworkImport::get_template_csv());
    $seeded = NetworkImport::import_from_network($source_id, 'шаблон-источник.csv', 'append', $cfg);
    if (empty($seeded['success']) || (int)$seeded['imported_count'] !== 13) {
        echo "\n  ¬ шаблон не импортировался: " . ($seeded['message'] ?? '') . "\n";
        exec('rm -rf ' . escapeshellarg($dir));
        Quiz::delete($quiz_id);
        Quiz::delete($source_id);
        return false;
    }
    $source = Quiz::get($source_id);
    $exported = (string)Export::export_quiz_questions_csv($source, false);
    if ($exported === '') return false;
    file_put_contents($dir . '/вопросы_olympiad-2-выгрузка.csv', $exported);

    $expected = count($source->get_questions(false));
    $probe = NetworkImport::analyze_import_file($quiz, 'вопросы_olympiad-2-выгрузка.csv', $cfg);
    if (empty($probe['success'])) {
        echo "\n  ¬ выгрузка не читается: " . ($probe['message'] ?? '') . "\n";
        exec('rm -rf ' . escapeshellarg($dir));
        Quiz::delete($quiz_id);
        return false;
    }
    if ((int)$probe['stats']['importable'] !== $expected) {
        echo "\n  ¬ готовы к импорту {$probe['stats']['importable']} из {$expected}\n";
        exec('rm -rf ' . escapeshellarg($dir));
        Quiz::delete($quiz_id);
        return false;
    }

    $round = NetworkImport::import_from_network($quiz_id, 'вопросы_olympiad-2-выгрузка.csv', 'append', $cfg);
    if (empty($round['success']) || (int)$round['imported_count'] !== $expected) return false;

    // Проверяем восстановление текста диктанта и ответов «краткого ответа» (раньше терялись)
    $restored = Quiz::get($quiz_id)->get_questions(false);
    $by_type = [];
    foreach ($restored as $q) {
        $by_type[$q->question_type] = $q;
    }
    if (empty($by_type['dictation']->options['text_with_gaps']) && empty($by_type['dictation']->options['template'])) return false;
    if (empty($by_type['text']->options['answers'])) return false;

    // 4. SMB: ресурс недоступен — сообщение содержит транспорт и причину, а не общую фразу
    $smb = NetworkImport::import_from_network($quiz_id, 'вопросы_olympiad-2.csv', 'append', ['path' => '\\\\server\\share', 'type' => 'smb']);
    if (!empty($smb['success'])) return false;
    if (mb_strpos((string)$smb['message'], 'SMB') === false) return false;
    if (mb_strpos((string)$smb['message'], 'Не удалось прочитать файл «вопросы_olympiad-2.csv» из сетевой папки.') !== false) return false;

    // 5. FTP: недоступный сервер — сообщение называет FTP и причину
    $ftp = NetworkImport::import_from_network($quiz_id, 'вопросы_olympiad-2.csv', 'append', ['path' => 'ftp://127.0.0.1:1', 'type' => 'ftp']);
    if (!empty($ftp['success'])) return false;
    if (mb_strpos((string)$ftp['message'], 'FTP') === false) return false;

    // 6. WebDAV: реальная отдача файла по HTTP
    $port = 0;
    $server = null;
    foreach ([18881, 18882, 18883, 18884, 18885] as $candidate) {
        $sock = @fsockopen('127.0.0.1', $candidate, $errno, $errstr, 0.2);
        if ($sock === false) {
            $port = $candidate;
            break;
        }
        fclose($sock);
    }

    $webdav_ok = true;
    if ($port > 0) {
        $log = $dir . '/server.log';
        $server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $dir],
            [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes
        );
        if (!is_resource($server)) {
            return false;
        }

        $ready = false;
        for ($i = 0; $i < 40; $i++) {
            usleep(100000);
            $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($sock !== false) {
                fclose($sock);
                $ready = true;
                break;
            }
        }

        if ($ready) {
            $web_cfg = ['path' => 'http://127.0.0.1:' . $port, 'type' => 'webdav'];
            $web = NetworkImport::import_from_network($quiz_id, 'вопросы_olympiad-2.csv', 'append', $web_cfg);
            if (empty($web['success']) || (int)$web['imported_count'] !== 1) {
                echo "\n  ¬ WebDAV: " . ($web['message'] ?? '') . "\n";
                $webdav_ok = false;
            }

            $web_missing = NetworkImport::import_from_network($quiz_id, 'нет-такого.csv', 'append', $web_cfg);
            if (!empty($web_missing['success'])) {
                $webdav_ok = false;
            } elseif (mb_strpos((string)$web_missing['message'], '404') === false) {
                echo "\n  ¬ WebDAV 404: " . ($web_missing['message'] ?? '') . "\n";
                $webdav_ok = false;
            }
        } else {
            echo "\n  ¬ локальный HTTP-сервер не запустился, шаг WebDAV пропущен\n";
        }

        proc_terminate($server);
        proc_close($server);
    }

    exec('rm -rf ' . escapeshellarg($dir));
    Quiz::delete($quiz_id);
    Quiz::delete($source_id);

    return $webdav_ok;
});

// 76. Фон страницы тестирования на самом дальнем плане: картинка, режимы вписывания, затемнение
$it("Фон страницы тестирования: картинка на дальнем плане, вписывание, затемнение", function (): bool {
    // 1. Значения по умолчанию
    $defaults = Style::defaults();
    foreach (['canvas_image', 'canvas_image_fit', 'canvas_image_position', 'canvas_overlay', 'canvas_full_width'] as $key) {
        if (!array_key_exists($key, $defaults)) return false;
    }
    if ($defaults['canvas_image'] !== '' || $defaults['canvas_image_fit'] !== 'cover') return false;
    if ($defaults['canvas_image_position'] !== 'center' || $defaults['canvas_overlay'] !== 0) return false;
    if (!isset(Style::canvas_fits()['cover'], Style::canvas_fits()['contain'], Style::canvas_fits()['repeat'], Style::canvas_fits()['fill'])) return false;
    if (!isset(Style::canvas_positions()['center'], Style::canvas_positions()['top'])) return false;

    // 2. Санитизация ввода из формы
    $saved = Style::sanitize_post([
        'style_canvas_bg_enabled'      => '1',
        'style_canvas_bg'              => '#0f172a',
        'style_canvas_image'           => 'https://example.com/wp-content/uploads/fon.jpg',
        'style_canvas_image_fit'       => 'contain',
        'style_canvas_image_position'  => 'top',
        'style_canvas_overlay'         => '120',
        'style_canvas_full_width'      => '1',
    ]);
    if ($saved['canvas_image'] !== 'https://example.com/wp-content/uploads/fon.jpg') return false;
    if ($saved['canvas_image_fit'] !== 'contain' || $saved['canvas_image_position'] !== 'top') return false;
    if ($saved['canvas_overlay'] !== 90) return false;   // ограничение 0…90 %
    if ($saved['canvas_full_width'] !== 1) return false;
    if ($saved['canvas_bg'] !== '#0f172a') return false;

    // Неизвестный режим вписывания → cover, отрицательное затемнение → 0
    $fallback = Style::sanitize_post([
        'style_canvas_bg_enabled' => '1',
        'style_canvas_image'      => 'https://example.com/fon.jpg',
        'style_canvas_image_fit'  => 'неизвестно',
        'style_canvas_overlay'    => '-30',
    ]);
    if ($fallback['canvas_image_fit'] !== 'cover' || $fallback['canvas_overlay'] !== 0) return false;

    // Выключенный чекбокс убирает и картинку
    $off = Style::sanitize_post(['style_canvas_image' => 'https://example.com/fon.jpg']);
    if ($off['canvas_image'] !== '') return false;

    // 3. Опасные значения отбрасываются, относительные и протокол-относительные пути сохраняются
    if (Style::sanitize_image_url('javascript:alert(1)') !== '') return false;
    if (Style::sanitize_image_url('data:image/svg+xml;base64,PHN2Zz4=') !== '') return false;
    if (Style::sanitize_image_url('https://example.com/a.jpg" onload="alert(1)') !== '') return false;
    if (Style::sanitize_image_url('/wp-content/uploads/fon.jpg') !== '/wp-content/uploads/fon.jpg') return false;
    if (Style::sanitize_image_url('//cdn.example.com/fon.jpg') !== '//cdn.example.com/fon.jpg') return false;
    if (Style::sanitize_image_url('  https://example.com/fon.jpg  ') !== 'https://example.com/fon.jpg') return false;

    // 4. Старые сохранённые настройки (без новых ключей) нормализуются без ошибок
    $legacy = Style::normalize(['preset' => 'classic', 'canvas_bg' => '#f1f5f9']);
    if ($legacy['canvas_image'] !== '' || $legacy['canvas_image_fit'] !== 'cover' || $legacy['canvas_overlay'] !== 0) return false;
    if ($legacy['canvas_bg'] !== '#f1f5f9') return false;

    $quiz_id = Quiz::create(['title' => 'Олимпиада с картинкой на фоне', 'status' => 'publish']);
    if (!$quiz_id) return false;

    // 5. Картинка без цвета: только image-слой, подложка прозрачная
    $quiz = Quiz::get($quiz_id);
    $quiz->update(['settings' => ['style' => [
        'preset'                   => 'custom',
        'canvas_image'             => 'https://example.com/wp-content/uploads/fon-olimpiady.jpg',
        'canvas_image_fit'         => 'cover',
        'canvas_image_position'    => 'center',
        'canvas_overlay'           => 0,
        'canvas_full_width'        => 0,
    ]]]);
    $quiz = Quiz::get($quiz_id);

    $css = Style::build_css($quiz);
    $scope = '#wpo-quiz-' . $quiz_id;
    if (!str_contains($css, 'background-image:url("https://example.com/wp-content/uploads/fon-olimpiady.jpg")')) return false;
    if (!str_contains($css, 'background-size:cover')) return false;
    if (!str_contains($css, 'background-repeat:no-repeat')) return false;
    if (!str_contains($css, 'background-position:center center')) return false;
    if (str_contains($css, 'linear-gradient(rgba(0,0,0')) return false;   // затемнение выключено
    if (!str_contains($css, $scope . '{background-image:')) return false;
    if (!str_contains($css, 'border-radius:calc(var(--wpo-radius, 12px) + 10px)')) return false;

    // 6. Затемнение, режим «вместить», позиция «сверху», фон от края до края
    $quiz->update(['settings' => ['style' => [
        'preset'                   => 'custom',
        'canvas_bg'                => '#0b1120',
        'canvas_image'             => 'https://example.com/fon.jpg',
        'canvas_image_fit'         => 'contain',
        'canvas_image_position'    => 'top',
        'canvas_overlay'           => 40,
        'canvas_full_width'        => 1,
    ]]]);
    $quiz = Quiz::get($quiz_id);
    $css = Style::build_css($quiz);

    if (!str_contains($css, 'background-color:#0b1120')) return false;
    if (!str_contains($css, 'linear-gradient(rgba(0,0,0,0.4),rgba(0,0,0,0.4)),url("https://example.com/fon.jpg")')) return false;
    if (!str_contains($css, 'background-size:contain')) return false;
    if (!str_contains($css, 'background-position:center top')) return false;
    if (!str_contains($css, 'width:100vw') || !str_contains($css, 'margin-left:calc(50% - 50vw)')) return false;
    if (!str_contains($css, 'padding:32px max(16px, calc((100vw - var(--wpo-max-width, 860px)) / 2))')) return false;

    // 7. Тема выводится на странице тестирования вместе с картинкой
    $html = Shortcodes::render_quiz(['id' => $quiz_id]);
    if (!str_contains($html, 'url("https://example.com/fon.jpg")')) return false;
    if (str_contains($html, 'javascript:')) return false;

    // 8. Общий фон для полноэкранного запуска (html, body) — с закреплением картинки
    $body_decl = Style::canvas_background_declarations(Style::get($quiz), true);
    $body_css = implode(';', $body_decl);
    if (!str_contains($body_css, 'background-image:linear-gradient')) return false;
    if (!str_contains($body_css, 'background-attachment:fixed')) return false;
    if (!str_contains($body_css, 'background-color:#0b1120')) return false;

    // 9. Олимпиада без фона остаётся без правил дальнего плана
    $plain_id = Quiz::create(['title' => 'Олимпиада без фона', 'status' => 'publish']);
    $plain_css = Style::build_css(Quiz::get($plain_id));
    if (str_contains($plain_css, 'background-image')) return false;
    if (str_contains($plain_css, '100vw')) return false;

    Quiz::delete($quiz_id);
    Quiz::delete($plain_id);
    return true;
});

// 77. Импорт вопросов: баллы скрыты по умолчанию (галочка «Скрыть баллы» включена)
$it("Импорт вопросов: галочка «Скрыть баллы за этот вопрос в тесте» включена по умолчанию", function (): bool {
    $csv = "type;title;points;choices;correct;extra\n"
        . "single;Скрытые баллы (по умолчанию);2;Да|Нет;1;\n"
        . "single;Показать баллы явно;2;Да|Нет;1;show_points=1\n"
        . "single;Показать через hide_points;2;Да|Нет;1;hide_points=0\n";

    $quiz_id = Quiz::create(['title' => 'Импорт: баллы скрыты по умолчанию', 'status' => 'publish']);
    if (!$quiz_id) return false;

    // 1. CSV без настроек → баллы скрыты; колонка extra может показать баллы конкретной строке
    $questions = NetworkImport::parse_questions_content($csv, 'import.csv');
    if (count($questions) !== 3) return false;

    $res = NetworkImport::import_to_quiz($quiz_id, $questions, 'replace');
    if (empty($res['success']) || (int)$res['imported_count'] !== 3) return false;

    $by_title = [];
    foreach (Quiz::get($quiz_id)->get_questions(false) as $imported) {
        $by_title[$imported->title] = $imported;
    }
    if (count($by_title) !== 3) return false;

    $hidden_q = $by_title['Скрытые баллы (по умолчанию)'] ?? null;
    if (!$hidden_q) return false;
    if ((int)($hidden_q->options['show_points'] ?? 1) !== 0) return false;           // галочка включена
    if (!empty($hidden_q->get_public_data()['show_points'])) return false;          // участник не видит баллы

    if ((int)($by_title['Показать баллы явно']->options['show_points'] ?? 0) !== 1) return false;
    if (empty($by_title['Показать баллы явно']->get_public_data()['show_points'])) return false;
    if ((int)($by_title['Показать через hide_points']->options['show_points'] ?? 0) !== 1) return false;

    // 2. Карточка импортированного задания в конструкторе: галочка «Скрыть баллы» включена
    $q = Question::get($hidden_q->id);
    $quiz_shared_texts = [];
    ob_start();
    include WP_OLYMPIAD_PATH . 'admin/views/question-item-card.php';
    $card = (string)ob_get_clean();
    if (!preg_match('/class="wpo-q-hide-points"\s+checked/', $card)) return false;

    // 3. Импорт с выключенной галочкой (hide_points = false) — баллы не скрываются
    $quiz2_id = Quiz::create(['title' => 'Импорт с видимыми баллами', 'status' => 'publish']);
    $res2 = NetworkImport::import_to_quiz($quiz2_id, NetworkImport::parse_questions_content($csv, 'import.csv'), 'append', false);
    if ((int)($res2['imported_count'] ?? 0) !== 3) return false;

    foreach (Quiz::get($quiz2_id)->get_questions(false) as $visible) {
        if ($visible->title === 'Скрытые баллы (по умолчанию)' && $visible->options['show_points'] !== null) return false;
        if ($visible->title === 'Показать баллы явно' && (int)$visible->options['show_points'] !== 1) return false;
    }

    // 4. JSON: явный options.show_points уважается, отсутствие поля — баллы скрыты
    $json = wp_json_encode(['questions' => [
        [
            'question_type' => 'boolean',
            'title'         => 'JSON скрыть',
            'options'       => ['choices' => [
                ['id' => 'true', 'text' => 'Да', 'is_correct' => 1],
                ['id' => 'false', 'text' => 'Нет', 'is_correct' => 0],
            ]],
        ],
        [
            'question_type' => 'boolean',
            'title'         => 'JSON показать',
            'options'       => [
                'show_points' => 1,
                'choices'     => [
                    ['id' => 'true', 'text' => 'Да', 'is_correct' => 1],
                    ['id' => 'false', 'text' => 'Нет', 'is_correct' => 0],
                ],
            ],
        ],
    ]], JSON_UNESCAPED_UNICODE);

    $json_questions = NetworkImport::parse_questions_content((string)$json, 'import.json');
    if (count($json_questions) !== 2) return false;
    NetworkImport::import_to_quiz($quiz2_id, $json_questions, 'append');

    foreach (Quiz::get($quiz2_id)->get_questions(false) as $from_json) {
        if ($from_json->title === 'JSON скрыть' && (int)$from_json->options['show_points'] !== 0) return false;
        if ($from_json->title === 'JSON показать' && (int)$from_json->options['show_points'] !== 1) return false;
    }

    // 5. Импорт из сетевой папки (основной сценарий) тоже скрывает баллы
    $dir = sys_get_temp_dir() . '/wpo_import_points_' . uniqid();
    wp_mkdir_p($dir);
    file_put_contents($dir . '/вопросы_olympiad-2.csv', $csv);

    $quiz3_id = Quiz::create(['title' => 'Импорт из сетевой папки: баллы скрыты', 'status' => 'publish']);
    $net = NetworkImport::import_from_network($quiz3_id, 'вопросы_olympiad-2.csv', 'append', ['path' => $dir, 'type' => 'local']);
    if (empty($net['success']) || (int)$net['imported_count'] !== 3) return false;

    foreach (Quiz::get($quiz3_id)->get_questions(false) as $network_q) {
        if ($network_q->title === 'Скрытые баллы (по умолчанию)' && (int)$network_q->options['show_points'] !== 0) return false;
        if ($network_q->title === 'JSON показать') return false; // чужие строки не попали
    }

    // 6. Шаблон импорта документирует show_points
    if (mb_strpos(ImportTemplate::readme(), 'show_points') === false) return false;
    if (mb_strpos((string)ImportTemplate::columns()['extra']['hint'], 'show_points') === false) return false;

    exec('rm -rf ' . escapeshellarg($dir));
    Quiz::delete($quiz_id);
    Quiz::delete($quiz2_id);
    Quiz::delete($quiz3_id);
    return true;
});


// 78. REST API по ключу олимпиады (X-API-Key)
$it("REST API по ключу: структура, старт, сдача ответов, результат и отзыв ключа", function (): bool {
    // Внешние клиенты работают без учётной записи WordPress.
    $previous_user = get_current_user_id();
    wp_set_current_user(0);

    $quiz_id = Quiz::create([
        'title'    => 'API-интеграция: тестовая олимпиада',
        'status'   => 'publish',
        'settings' => [
            'time_limit'         => 0,
            'max_attempts'       => 0,
            'shuffle_questions'  => 0,
            'shuffle_answers'    => 0,
            'show_results_mode'  => 'immediate',
            'enable_intro_page'  => 0,
        ],
    ]);
    if (!$quiz_id) {
        wp_set_current_user($previous_user);
        return false;
    }

    $q1 = Question::create([
        'quiz_id'       => $quiz_id,
        'question_type' => 'single',
        'title'         => 'API: столица России?',
        'points'        => 2,
        'options'       => ['choices' => [
            ['id' => 'c1', 'text' => 'Санкт-Петербург', 'is_correct' => 0],
            ['id' => 'c2', 'text' => 'Москва', 'is_correct' => 1],
        ]],
    ]);

    $q2 = Question::create([
        'quiz_id'       => $quiz_id,
        'question_type' => 'single',
        'title'         => 'API: 2 + 3 = ?',
        'points'        => 3,
        'options'       => ['choices' => [
            ['id' => 'd1', 'text' => '4', 'is_correct' => 0],
            ['id' => 'd2', 'text' => '6', 'is_correct' => 0],
            ['id' => 'd3', 'text' => '5', 'is_correct' => 1],
        ]],
    ]);

    if (!$q1 || !$q2) {
        Quiz::delete($quiz_id);
        wp_set_current_user($previous_user);
        return false;
    }

    $quiz = Quiz::get($quiz_id);
    $key = $quiz->generate_api_key(true);
    $quiz = Quiz::get($quiz_id);

    $check = true;
    $check = $check && strlen($key) >= 32 && str_starts_with($key, Api::KEY_PREFIX);
    $check = $check && (int)$quiz->get_setting('api_enabled', 0) === 1;
    $check = $check && $quiz->get_api_key_masked() !== $key;
    $check = $check && strpos($quiz->get_api_key_masked(), substr($key, 8, 6)) === false;

    $found = Api::find_quiz_by_key($key);
    $check = $check && $found instanceof Quiz && $found->id === $quiz_id;
    $check = $check && Api::find_quiz_by_key('wpo_totally_wrong_key_1234567890') === null;

    $call = function (string $method, string $path, ?string $key_value, array $params = [], array $headers = []) {
        $req = new WP_REST_Request($method, '/wp-olympiad/v1' . $path);
        if ($key_value !== null) {
            $req->set_header('X-API-Key', $key_value);
        }
        foreach ($headers as $h => $v) {
            $req->set_header($h, $v);
        }
        foreach ($params as $pp => $vv) {
            $req->set_param($pp, $vv);
        }
        return rest_do_request($req);
    };

    // 1. ping
    $res = $call('GET', '/external/ping', $key);
    $data = $res->get_data();
    $check = $check && $res->get_status() === 200 && !empty($data['success']);
    $check = $check && (int)($data['quiz']['id'] ?? 0) === $quiz_id;
    $check = $check && (int)($data['quiz']['questions_count'] ?? 0) === 2;
    $check = $check && !empty($data['endpoints']) && count($data['endpoints']) >= 5;

    // 2. структура олимпиады: вопросы без правильных ответов
    $res = $call('GET', '/external/quiz/' . $quiz_id, $key);
    $data = $res->get_data();
    $check = $check && $res->get_status() === 200 && count($data['questions'] ?? []) === 2;
    $first = $data['questions'][0] ?? [];
    $check = $check && isset($first['choices'][0]['id']) && !isset($first['choices'][0]['is_correct']);
    $check = $check && isset($data['shared_texts']) && is_array($data['shared_texts']);
    $check = $check && isset($data['participant_fields']['name']);

    // 3. авторизация
    $res = $call('GET', '/external/ping', 'wpo_bad_key_bad_key_bad_key_bad_key_00');
    $check = $check && $res->get_status() === 401;
    $res = $call('GET', '/external/ping', null);
    $check = $check && $res->get_status() === 401;

    $res = $call('GET', '/external/ping', null, [], ['Authorization' => 'Bearer ' . $key]);
    $check = $check && $res->get_status() === 200;

    // 4. ключ привязан только к своей олимпиаде
    $res = $call('GET', '/external/quiz/' . ($quiz_id + 999), $key);
    $check = $check && $res->get_status() === 403;

    // 5. выключенный API
    $quiz->update_setting('api_enabled', 0);
    $res = $call('GET', '/external/ping', $key);
    $check = $check && $res->get_status() === 403;
    Quiz::get($quiz_id)->update_setting('api_enabled', 1);

    // 6. старт попытки
    $res = $call('POST', '/external/start', $key, [
        'quiz_id'           => $quiz_id,
        'participant_name'  => 'Иванов Иван',
        'participant_email' => 'api-test-' . uniqid() . '@example.com',
    ]);
    $data = $res->get_data();
    $token = (string)($data['token'] ?? '');
    $attempt_id = (int)($data['attempt_id'] ?? 0);
    $check = $check && $res->get_status() === 200 && $token !== '' && $attempt_id > 0;
    $check = $check && count($data['questions'] ?? []) === 2;
    $check = $check && !empty($data['submit_url']) && !empty($data['result_url']);

    // 7. результат до сдачи
    $res = $call('GET', '/external/result/' . $token, $key);
    $check = $check && $res->get_status() === 200;
    $check = $check && ($res->get_data()['attempt']['status'] ?? '') === 'in_progress';

    // 8. сдача ответов
    $answers = [$q1 => 'c2', $q2 => 'd3'];
    $res = $call('POST', '/external/submit', $key, ['token' => $token, 'answers' => $answers]);
    $data = $res->get_data();
    $check = $check && $res->get_status() === 200 && !empty($data['success']);
    $check = $check && (int)($data['score'] ?? -1) === 5 && (int)($data['max_score'] ?? -1) === 5;
    $check = $check && (int)($data['attempt_id'] ?? 0) === $attempt_id;

    // 9. результат после сдачи
    $res = $call('GET', '/external/result/' . $attempt_id, $key);
    $data = $res->get_data();
    $check = $check && $res->get_status() === 200;
    $check = $check && in_array($data['attempt']['status'] ?? '', ['completed', 'graded'], true);
    $check = $check && count($data['responses'] ?? []) === 2;
    $check = $check && (int)($data['counts']['correct'] ?? 0) === 2;
    $check = $check && abs((float)($data['attempt']['percentage'] ?? 0) - 100.0) < 0.01;
    $check = $check && ($data['responses'][0]['title'] ?? '') !== '';

    // 10. список попыток
    $res = $call('GET', '/external/attempts', $key, ['quiz_id' => $quiz_id]);
    $data = $res->get_data();
    $check = $check && $res->get_status() === 200;
    $check = $check && (int)($data['total'] ?? 0) >= 1 && count($data['items'] ?? []) >= 1;

    // 11. отметка последнего использования ключа
    $check = $check && Api::last_used(Quiz::get($quiz_id)) !== '';

    // 12. отзыв ключа закрывает доступ
    $revoked = Quiz::get($quiz_id)->revoke_api_key();
    $res = $call('GET', '/external/ping', $key);
    $check = $check && $revoked && $res->get_status() === 401;

    Quiz::delete($quiz_id);
    wp_set_current_user($previous_user);
    return $check;
});

// 79. Webhook: HMAC-подпись, повторы, журнал и тестовая отправка
$it("Webhook: подпись HMAC, повторные попытки, журнал доставок и тестовый запрос", function (): bool {
    $quiz_id = Quiz::create([
        'title'    => 'Webhook-интеграция: тестовая олимпиада',
        'status'   => 'publish',
        'settings' => ['webhook_url' => 'https://example.test/wpo-hook'],
    ]);
    if (!$quiz_id) {
        return false;
    }

    // Не даём тесту запускать настоящий WP-Cron через loopback.
    add_filter('wpo_webhook_spawn_cron', '__return_false');
    wp_unschedule_hook(Webhook::CRON_HOOK);
    delete_transient('doing_cron');

    $check = true;
    $captured = [];
    $status = 200;
    $filter = function ($pre, $args, $url) use (&$captured, &$status) {
        $captured = ['url' => $url, 'args' => $args];
        return [
            'headers'  => [],
            'body'     => '{"ok":true}',
            'response' => ['code' => $status, 'message' => $status === 200 ? 'OK' : 'Server Error'],
            'cookies'  => [],
            'filename' => null,
        ];
    };
    add_filter('pre_http_request', $filter, 10, 3);

    // 1. Тестовая отправка: 200, подпись и заголовки
    $result = Webhook::send_test(Quiz::get($quiz_id));
    $check = $check && !empty($result['ok']) && (int)$result['code'] === 200;
    $check = $check && ($captured['url'] ?? '') === 'https://example.test/wpo-hook';

    $headers = array_change_key_case((array)($captured['args']['headers'] ?? []), CASE_LOWER);
    $body = (string)($captured['args']['body'] ?? '');
    $decoded = json_decode($body, true);

    $quiz = Quiz::get($quiz_id);
    $secret = $quiz->get_webhook_secret();
    $check = $check && $secret !== '' && str_starts_with($secret, Webhook::SECRET_PREFIX);

    $ts = (int)($headers['x-wpo-timestamp'] ?? 0);
    $expected = 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, $secret);
    $check = $check && $ts > 0 && hash_equals($expected, (string)($headers['x-wpo-signature'] ?? ''));
    $check = $check && ($headers['x-wpo-event'] ?? '') === 'webhook_test';
    $check = $check && ($headers['x-wpo-test'] ?? '') === '1';
    $check = $check && !empty($headers['x-wpo-delivery']);
    $check = $check && ($decoded['event'] ?? '') === 'webhook_test';
    $check = $check && (int)($decoded['quiz']['id'] ?? 0) === $quiz_id;
    $check = $check && (int)($decoded['attempt']['score'] ?? 0) === 7;

    $log = Webhook::deliveries(Quiz::get($quiz_id));
    $check = $check && count($log) === 1 && ($log[0]['ok'] ?? null) === true && (int)($log[0]['code'] ?? 0) === 200;
    $check = $check && !empty($log[0]['test']);

    // 2. Ошибка 500 → запланирован повтор
    wp_unschedule_hook(Webhook::CRON_HOOK);
    Webhook::clear_log(Quiz::get($quiz_id));
    $status = 500;
    $payload = ['event' => 'attempt_completed', 'quiz' => ['id' => $quiz_id], 'attempt' => ['id' => 0], 'timestamp' => time()];
    $fail = Webhook::deliver_event($quiz_id, $payload, 0, '', 1);
    $check = $check && $fail['ok'] === false && (int)$fail['code'] === 500;
    $check = $check && str_contains((string)$fail['message'], 'Повторная попытка');
    $check = $check && !empty($fail['delivery_id']);

    $retry = 0;
    foreach ((array)_get_cron_array() as $timestamp => $hooks) {
        foreach ((array)($hooks[Webhook::CRON_HOOK] ?? []) as $event) {
            if ((int)($event['args'][0] ?? 0) === $quiz_id && (int)($event['args'][4] ?? 0) === 2) {
                $retry++;
            }
        }
    }
    $check = $check && $retry === 1;

    $log = Webhook::deliveries(Quiz::get($quiz_id));
    $check = $check && count($log) === 1 && ($log[0]['ok'] ?? null) === false && (int)$log[0]['try'] === 1;

    // 3. Успешный повтор обновляет ту же запись журнала
    $status = 200;
    Webhook::deliver_event($quiz_id, $payload, 0, (string)$fail['delivery_id'], 2);
    $log = Webhook::deliveries(Quiz::get($quiz_id));
    $check = $check && count($log) === 1 && ($log[0]['ok'] ?? null) === true && (int)$log[0]['try'] === 2;

    // 4. Асинхронная постановка при завершении попытки
    wp_unschedule_hook(Webhook::CRON_HOOK);
    Webhook::clear_log(Quiz::get($quiz_id));
    remove_filter('pre_http_request', $filter, 10);

    $attempt = Attempt::start($quiz_id, ['name' => 'Петров Пётр', 'email' => 'hook@example.com', 'org' => 'Школа №7']);
    $check = $check && $attempt instanceof Attempt;
    if ($attempt instanceof Attempt) {
        $queued = Webhook::on_attempt_completed(Quiz::get($quiz_id), $attempt);
        $check = $check && !empty($queued['queued']) && $queued['delivery_id'] !== '';

        $scheduled = 0;
        foreach ((array)_get_cron_array() as $timestamp => $hooks) {
            foreach ((array)($hooks[Webhook::CRON_HOOK] ?? []) as $event) {
                if ((int)($event['args'][0] ?? 0) === $quiz_id) {
                    $scheduled++;
                }
            }
        }
        $check = $check && $scheduled === 1;

        $queued_log = Webhook::deliveries(Quiz::get($quiz_id));
        $check = $check && isset($queued_log[0]) && is_array($queued_log[0]) && array_key_exists('ok', $queued_log[0]) && $queued_log[0]['ok'] === null;
        $check = $check && (int)($queued_log[0]['attempt_id'] ?? 0) === $attempt->id;
    }

    // 5. Пустой URL — отправка не ставится
    wp_unschedule_hook(Webhook::CRON_HOOK);
    Quiz::get($quiz_id)->update_setting('webhook_url', '');
    $skipped = Webhook::on_attempt_completed(Quiz::get($quiz_id), $attempt instanceof Attempt ? $attempt : new Attempt());
    $check = $check && empty($skipped['queued']);

    // 6. Очистка журнала
    Quiz::get($quiz_id)->update_setting('webhook_url', 'https://example.test/wpo-hook');
    Webhook::clear_log(Quiz::get($quiz_id));
    $check = $check && Webhook::deliveries(Quiz::get($quiz_id)) === [];

    wp_unschedule_hook(Webhook::CRON_HOOK);
    delete_transient('doing_cron');
    remove_filter('wpo_webhook_spawn_cron', '__return_false');
    Quiz::delete($quiz_id);
    return $check;
});

// 80. Точечное обновление настроек не затирает параллельные изменения
$it("Настройки олимпиады: update_setting не затирает журнал webhook устаревшим объектом", function (): bool {
    $quiz_id = Quiz::create(['title' => 'Конкурентное обновление настроек', 'status' => 'publish']);
    if (!$quiz_id) {
        return false;
    }

    global $wpdb;
    $table = DB::quizzes_table();
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $quiz_id), ARRAY_A);
    if (!$row) {
        Quiz::delete($quiz_id);
        return false;
    }

    // «Устаревший» объект, загруженный до изменений (имитация параллельного запроса).
    $stale = new Quiz($row);

    $fresh = Quiz::get($quiz_id);
    $fresh->update_setting('webhook_log', [
        ['id' => 'whd_test', 'event' => 'attempt_completed', 'ok' => true, 'code' => 200],
    ]);
    $fresh->update_setting('api_key_created', '2026-01-01 10:00:00');

    // Устаревший объект сохраняет другой ключ — журнал и дата не должны откатиться.
    $stale->update_setting('api_enabled', 1);

    $reloaded = Quiz::get($quiz_id);
    $log = $reloaded->get_setting('webhook_log', []);

    $check = is_array($log) && count($log) === 1 && ($log[0]['id'] ?? '') === 'whd_test';
    $check = $check && (string)$reloaded->get_setting('api_key_created', '') === '2026-01-01 10:00:00';
    $check = $check && (int)$reloaded->get_setting('api_enabled', 0) === 1;

    Quiz::delete($quiz_id);
    return $check;
});

require __DIR__ . '/test-desktop-editor.php';
require __DIR__ . '/test-network-sync.php';
require __DIR__ . '/test-template-package.php';
require __DIR__ . '/test-settings-mail.php';
require __DIR__ . '/test-guest-access.php';
require __DIR__ . '/test-settings-fields.php';
require __DIR__ . '/test-no-pass-threshold.php';
require __DIR__ . '/test-export-cleanup.php';
require __DIR__ . '/test-style-tools.php';
require __DIR__ . '/test-protocol-export.php';
require __DIR__ . '/test-report-export-cleanup.php';
require __DIR__ . '/test-import-folder.php';
require __DIR__ . '/test-import-search.php';
require __DIR__ . '/test-auto-export-scope.php';
require __DIR__ . '/test-questions-export-removal.php';
require __DIR__ . '/test-link-alias.php';
require __DIR__ . '/test-rules-enforcement.php';
require __DIR__ . '/test-questionnaire-groups.php';
require __DIR__ . '/test-resume-draft.php';

echo "\n=======================================================\n";
printf("ИТОГ ТЕСТИРОВАНИЯ: Пройдено %d из %d тестов (%d%%)\n", $passed, $total, $total ? (int)round(100 * $passed / $total) : 100);
if ($failed > 0) {
    printf("\033[31mОшибок: %d\033[0m\n", $failed);
} else {
    echo "\033[32mВсе проверки успешно пройдены! Ошибок нет.\033[0m\n";
}
echo "=======================================================\n\n";

exit($failed > 0 ? 1 : 0);
