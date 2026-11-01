<?php
/**
 * 1.0.29: сброс попытки, если браузер участника был закрыт.
 *
 * Политика прежняя: переключение вкладки и F5 время не останавливают. Новое —
 * только аннулирование попытки при длительном молчании страницы (закрытый браузер),
 * и оно выключено по умолчанию. Ответы сброшенной попытки остаются в базе, а сама
 * попытка не засчитывается в лимит повторов, поэтому участник начинает заново
 * с полным лимитом и чистыми ответами.
 */

namespace WPOlympiad\Tests;

use WPOlympiad\Attempt;
use WPOlympiad\AntiCheat;
use WPOlympiad\DB;
use WPOlympiad\Question;
use WPOlympiad\Quiz;
use WPOlympiad\RestApi;
use WPOlympiad\Shortcodes;
use WP_REST_Request;

if (!defined('ABSPATH') || !isset($it)) return;

$bc = static function (bool $ok, string $message, string $extra = ''): void {
    if (!$ok) {
        throw new \RuntimeException($extra !== '' ? $message . ' — ' . $extra : $message);
    }
};

/**
 * Настройка олимпиады с полным набором полей: Quiz::update() заменяет settings
 * целиком, поэтому каждый раз отдаём весь набор.
 */
$bc_quiz = static function (string $token, array $extra): int {
    return (int)Quiz::create([
        'title'    => 'Закрытие браузера ' . $token,
        'status'   => 'publish',
        'settings' => array_merge([
            'max_attempts'    => 1,
            'time_limit'      => 20,
            'require_org'     => 0,
            'enable_intro_page' => 1,
        ], $extra),
    ]);
};

$bc_shift = static function (string $stored, int $seconds): string {
    // Naive-local, как в плагине: сдвигаем строку, не переводя часовые пояса.
    return gmdate('Y-m-d H:i:s', strtotime($stored) - $seconds);
};

$it('1.0.29: миграция добавляет колонки last_seen_at и closed_seconds', function () use ($bc): bool {
    global $wpdb;
    DB::install();
    $table = DB::attempts_table();
    foreach (['last_seen_at' => 'datetime', 'closed_seconds' => 'int'] as $col => $want) {
        $row = $wpdb->get_row($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $col), ARRAY_A);
        $bc(is_array($row), 'Колонка ' . $col . ' не появилась после dbDelta');
        $bc(stripos((string)$row['Type'], $want) !== false,
            'Колонка ' . $col . ' имеет неожиданный тип', (string)$row['Type']);
    }
    return true;
});

$it('1.0.29: выключенный переключатель — молчание часа ничего не аннулирует', function () use ($bc, $bc_quiz, $bc_shift): bool {
    global $wpdb;
    $token = substr(md5(uniqid('off', true)), 0, 8);
    $quiz_id = $bc_quiz($token, ['reset_on_browser_close' => 0]);
    try {
        $attempt = Attempt::start($quiz_id, ['name' => 'Анна Иванова', 'email' => $token . '@example.invalid', 'org' => 'Школа №1']);
        $bc($attempt instanceof Attempt, 'Попытка не создана');
        $bc($attempt->last_seen_at !== '', 'Attempt::start() не отметил сигнал страницы');
        $id = (int)$attempt->id;

        $wpdb->update(DB::attempts_table(), ['last_seen_at' => $bc_shift($attempt->last_seen_at, 3600)], ['id' => $attempt->id]);
        $fresh = Attempt::get((int)$attempt->id);
        $pause = AntiCheat::browser_close_gap($fresh, Quiz::get($quiz_id));
        $bc($pause['enabled'] === false, 'Выключенная настройка считается включённой');
        $bc($pause['reset'] === false, 'Выключенная настройка требует сброса');

        $result = AntiCheat::process_heartbeat($fresh, Quiz::get($quiz_id));
        $bc(($result['status'] ?? '') === 'ok', 'Heartbeat вернул неожиданный статус', (string)($result['status'] ?? ''));
        $after = Attempt::get((int)$attempt->id);
        $bc($after->status === 'in_progress', 'Попытка аннулирована при выключенном переключателе', $after->status);
        $bc((int)$after->closed_seconds === 0, 'closed_seconds заполнен при выключенном переключателе');
        // Время по-прежнему считается от started_at: сам факт молчания страницы
        // ничего не возвращает и не добавляет. Отстаем ли участник — решает
        // серверное время, а не отметки last_seen_at.
        $started = (string)$wpdb->get_var($wpdb->prepare('SELECT started_at FROM ' . DB::attempts_table() . ' WHERE id = %d', $id));
        $wpdb->update(DB::attempts_table(), ['started_at' => $bc_shift($started, 600)], ['id' => $id]);
        $charged = Attempt::get($id);
        $bc($charged->get_remaining_seconds(20) >= 595 && $charged->get_remaining_seconds(20) <= 605,
            'Выключенный переключатель вернул минуты за время отсутствия',
            (string)$charged->get_remaining_seconds(20));
        return true;
    } finally {
        Quiz::delete($quiz_id);
    }
});

$it('1.0.29: разрыв дольше порога аннулирует попытку, ответы остаются в базе', function () use ($bc, $bc_quiz, $bc_shift): bool {
    global $wpdb;
    $token = substr(md5(uniqid('gap', true)), 0, 8);
    $quiz_id = $bc_quiz($token, ['reset_on_browser_close' => 1, 'browser_close_timeout' => 120]);
    $qid = (int)Question::create(['quiz_id' => $quiz_id, 'question_type' => 'single', 'title' => 'В1', 'points' => 1]);
    try {
        $attempt = Attempt::start($quiz_id, ['name' => 'Борис Петров', 'email' => $token . '@example.invalid', 'org' => 'Школа №2']);
        $id = (int)$attempt->id;
        $wpdb->insert(DB::responses_table(), [
            'attempt_id'  => $id,
            'question_id' => $qid,
            'user_answer' => 'ответ участника',
            'is_correct'  => null,
            'awarded_points' => 0,
            'needs_manual_review' => 0,
            'review_comment' => '',
        ]);

        $stored = (string)$wpdb->get_var($wpdb->prepare('SELECT last_seen_at FROM ' . DB::attempts_table() . ' WHERE id = %d', $id));
        $wpdb->update(DB::attempts_table(), ['last_seen_at' => $bc_shift($stored, 600)], ['id' => $id]);

        $fresh = Attempt::get($id);
        $pause = AntiCheat::browser_close_gap($fresh, Quiz::get($quiz_id));
        $bc($pause['reset'] === true, 'Разрыв в 10 минут не признан закрытым браузером', (string)$pause['gap']);
        $bc($pause['gap'] >= 595 && $pause['gap'] <= 610, 'Длительность молчания измерена неверно', (string)$pause['gap']);

        $result = AntiCheat::process_heartbeat($fresh, Quiz::get($quiz_id));
        $bc(($result['status'] ?? '') === 'browser_closed', 'Heartbeat не сообщил о сбросе', (string)($result['status'] ?? ''));
        $bc((int)($result['closed_after'] ?? 0) >= 595, 'В ответе нет длительности молчания');

        $after = Attempt::get($id);
        $bc($after->status === 'browser_closed', 'Статус попытки не переведён в сброшенный', $after->status);
        $bc((int)$after->closed_seconds >= 595 && (int)$after->closed_seconds <= 610,
            'closed_seconds не записан для протокола', (string)$after->closed_seconds);
        $bc($after->submitted_at === null || $after->submitted_at === '',
            'Сброшенная попытка помечена как сданная', (string)$after->submitted_at);
        $bc(str_contains($after->get_status_label(), 'Сброшена'),
            'Организатор не видит статус сброса в подписи', $after->get_status_label());

        $kept = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . DB::responses_table() . ' WHERE attempt_id = %d AND user_answer = %s', $id, 'ответ участника'));
        $bc($kept === 1, 'Ответы сброшенной попытки пропали из базы — проверять будет нечего');

        // Повторный heartbeat по тому же токену попытку уже не оживляет.
        $again = AntiCheat::process_heartbeat(Attempt::get($id), Quiz::get($quiz_id));
        $bc(($again['status'] ?? '') !== 'browser_closed', 'Повторный сброс уже сброшенной попытки');
        return true;
    } finally {
        Quiz::delete($quiz_id);
    }
});

$it('1.0.29: F5 и фоновая вкладка паузой не считаются, отметка сигнала обновляется', function () use ($bc, $bc_quiz, $bc_shift): bool {
    global $wpdb;
    $token = substr(md5(uniqid('short', true)), 0, 8);
    $quiz_id = $bc_quiz($token, ['reset_on_browser_close' => 1, 'browser_close_timeout' => 120]);
    try {
        $attempt = Attempt::start($quiz_id, ['name' => 'Вера Сидорова', 'email' => $token . '@example.invalid', 'org' => 'Школа №3']);
        $id = (int)$attempt->id;

        foreach ([5, 60, 119] as $seconds) {
            $stored = (string)$wpdb->get_var($wpdb->prepare('SELECT last_seen_at FROM ' . DB::attempts_table() . ' WHERE id = %d', $id));
            $wpdb->update(DB::attempts_table(), ['last_seen_at' => $bc_shift($stored, $seconds)], ['id' => $id]);
            $result = AntiCheat::process_heartbeat(Attempt::get($id), Quiz::get($quiz_id));
            $bc(($result['status'] ?? '') === 'ok',
                'Разрыв в ' . $seconds . ' с не должен аннуливать попытку', (string)($result['status'] ?? ''));
            $live = Attempt::get($id);
            $bc($live->status === 'in_progress', 'Попытка аннулирована при разрыве ' . $seconds . ' с', $live->status);
            $now = strtotime(current_time('mysql'));
            $seen = strtotime((string)$live->last_seen_at);
            $bc($seen !== false && abs($now - $seen) <= 5,
                'Heartbeat не обновил отметку последнего сигнала', (string)$live->last_seen_at);
        }
        return true;
    } finally {
        Quiz::delete($quiz_id);
    }
});

$it('1.0.29: после сброса доступна новая попытка с полным лимитом', function () use ($bc, $bc_quiz, $bc_shift): bool {
    global $wpdb;
    $token = substr(md5(uniqid('again', true)), 0, 8);
    $email = $token . '@example.invalid';
    $quiz_id = $bc_quiz($token, ['reset_on_browser_close' => 1, 'browser_close_timeout' => 120]);
    try {
        $first = Attempt::start($quiz_id, ['name' => 'Глеб Новиков', 'email' => $email, 'org' => 'Школа №4']);
        $id = (int)$first->id;
        $stored = (string)$wpdb->get_var($wpdb->prepare('SELECT last_seen_at FROM ' . DB::attempts_table() . ' WHERE id = %d', $id));
        $wpdb->update(DB::attempts_table(), ['last_seen_at' => $bc_shift($stored, 900)], ['id' => $id]);
        AntiCheat::process_heartbeat(Attempt::get($id), Quiz::get($quiz_id));

        // Без аннулирования эта проверка выродилась бы в «вторая попытка
        // и так доступна» — поэтому фиксируем, что первая именно сброшена.
        $bc(Attempt::get($id)->status === 'browser_closed',
            'Первая попытка не сброшена — проверка повторного старта пуста');

        $quiz = Quiz::get($quiz_id);
        $can = $quiz->can_user_attempt(0, $email);
        $bc(($can['allowed'] ?? false) === true, 'Сброшенная попытка засчиталась в лимит повторов', json_encode($can, JSON_UNESCAPED_UNICODE));
        $bc((int)($can['count'] ?? -1) === 0, 'Сброшенная попытка попала в зачёт max_attempts');

        $second = Attempt::start($quiz_id, ['name' => 'Глеб Новиков', 'email' => $email, 'org' => 'Школа №4']);
        $bc($second instanceof Attempt, 'Вторая попытка не создаётся');
        $bc((int)$second->id !== $id, 'Новая попытка не создана, а переиспользована старая');
        $bc($second->token !== $first->token, 'Токен не обновился');
        $rem = $second->get_remaining_seconds(20);
        $bc($rem >= 1195 && $rem <= 1200, 'Новая попытка получила не полный лимит', (string)$rem);
        $bc($second->status === 'in_progress', 'Новая попытка сразу не в процессе', $second->status);
        return true;
    } finally {
        Quiz::delete($quiz_id);
    }
});

$it('1.0.29: REST отдаёт 400 attempt_closed — фронтенд молча вернётся на старт', function () use ($bc, $bc_quiz, $bc_shift): bool {
    global $wpdb;
    $token = substr(md5(uniqid('rest', true)), 0, 8);
    $quiz_id = $bc_quiz($token, ['reset_on_browser_close' => 1, 'browser_close_timeout' => 120]);
    try {
        $attempt = Attempt::start($quiz_id, ['name' => 'Дарья К', 'email' => $token . '@example.invalid', 'org' => 'Школа №5']);
        $id = (int)$attempt->id;
        $atoken = (string)$attempt->token;

        $req = new WP_REST_Request('POST', '/wp-olympiad/v1/heartbeat');
        $req->set_param('token', $atoken);
        $ok = RestApi::handle_heartbeat($req);
        $bc($ok instanceof \WP_REST_Response, 'Обычный heartbeat должен отвечать 200', get_class($ok));

        $stored = (string)$wpdb->get_var($wpdb->prepare('SELECT last_seen_at FROM ' . DB::attempts_table() . ' WHERE id = %d', $id));
        $wpdb->update(DB::attempts_table(), ['last_seen_at' => $bc_shift($stored, 400)], ['id' => $id]);

        $req2 = new WP_REST_Request('POST', '/wp-olympiad/v1/heartbeat');
        $req2->set_param('token', $atoken);
        $resp = RestApi::handle_heartbeat($req2);
        $bc($resp instanceof \WP_Error, 'После сброса heartbeat обязан отказаться, а не отвечать 200', get_class($resp));
        $bc($resp->get_error_code() === 'attempt_closed', 'Неожиданный код ошибки', $resp->get_error_code());
        $data = $resp->get_error_data();
        $bc(is_array($data) && (int)($data['status'] ?? 0) === 400,
            'Ожидался HTTP 400 — только он распознаётся фронтом как «не восстанавливаем»');
        // Для уже сброшенной попытки повторный запрос отвечает тем же кодом (404-подобный отказ).
        $req3 = new WP_REST_Request('POST', '/wp-olympiad/v1/heartbeat');
        $req3->set_param('token', $atoken);
        $resp3 = RestApi::handle_heartbeat($req3);
        $bc($resp3 instanceof \WP_Error, 'Heartbeat по сброшенному токену не должен возвращать успех');
        return true;
    } finally {
        Quiz::delete($quiz_id);
    }
});

$it('1.0.29: порог молчания зажимается в безопасные границы', function () use ($bc, $bc_quiz): bool {
    $token = substr(md5(uniqid('clamp', true)), 0, 8);
    $cases = [
        // [значение в настройке, ожидаемый порог]
        [5, 30],
        [100000, 21600],
        [300, 300],
        // 0 означает «не задано» — берём системный адрес по умолчанию
        [0, 120],
    ];
    foreach ($cases as [$set, $want]) {
        $quiz_id = $bc_quiz($token . '_' . $want, [
            'reset_on_browser_close' => 1,
            'browser_close_timeout' => $set,
        ]);
        try {
            $attempt = Attempt::start($quiz_id, ['name' => 'Еж Котов', 'email' => $token . '@example.invalid', 'org' => 'Школа №6']);
            $pause = AntiCheat::browser_close_gap($attempt, Quiz::get($quiz_id));
            $bc((int)$pause['threshold'] === (int)$want,
                'Порог ' . $set . ' не зажат к ' . $want, (string)$pause['threshold']);
        } finally {
            Quiz::delete($quiz_id);
        }
    }
    return true;
});

$it('1.0.29: фронтенд реагирует на сброс без окон и плашек', function () use ($bc): bool {
    $js = (string)file_get_contents(dirname(__DIR__) . '/public/js/olympiad-frontend.js');
    foreach ([
        'handleRunClosed()',
        "res.code === 'attempt_closed'",
        'code: json.code ||',
        'this.stopRemainingOnReturn();',
        'this.pauseSubmitTimers();',
    ] as $needle) {
        $bc(str_contains($js, $needle), 'Во фронтенде нет обязательного фрагмента: ' . $needle);
    }

    $start = strpos($js, 'handleRunClosed() {');
    $bc($start !== false, 'handleRunClosed не найден');
    $body = substr($js, $start, 1400);
    $body = substr($body, 0, (int)strpos($body . "\n        }", "\n        }"));
    $bc(str_contains($body, 'this.clearMirror();'), 'Сброшенная попытка оставляет зеркало в localStorage');
    $bc(str_contains($body, 'this.stopMirror();'), 'Зеркало не останавливается при сбросе');
    $bc(str_contains($body, "this.introScreen.style.display = ''"), 'Участник не возвращается на стартовую форму');
    $bc(!str_contains($body, 'alert(') && !str_contains($body, 'confirm('),
        'Сброс показывает диалог, а его быть не должно');
    // Участник не получает текстового уведомления о причине сброса.
    $bc(!str_contains($js, 'Попытка сброшена'), 'Во фронтенде появился баннер о сбросе');
    return true;
});

$it('1.0.29: сброшенная попытка не показывается участнику в «Мои результаты»', function () use ($bc, $bc_quiz, $bc_shift): bool {
    global $wpdb;
    $token = substr(md5(uniqid('myres', true)), 0, 8);
    $user_id = (int)$wpdb->get_var('SELECT ID FROM ' . $wpdb->users . ' ORDER BY ID ASC LIMIT 1');
    $bc($user_id > 0, 'Нет ни одного пользователя для проверки «Мои результаты»');
    $quiz_id = $bc_quiz($token, ['reset_on_browser_close' => 1, 'browser_close_timeout' => 120]);
    $previous_user = get_current_user_id();
    try {
        wp_set_current_user($user_id);
        $first = Attempt::start($quiz_id, ['name' => 'Ирина П', 'email' => $token . '@example.invalid', 'org' => 'Школа №7']);
        $id = (int)$first->id;
        $stored = (string)$wpdb->get_var($wpdb->prepare('SELECT last_seen_at FROM ' . DB::attempts_table() . ' WHERE id = %d', $id));
        $wpdb->update(DB::attempts_table(), ['last_seen_at' => $bc_shift($stored, 600)], ['id' => $id]);
        AntiCheat::process_heartbeat(Attempt::get($id), Quiz::get($quiz_id));
        $bc(Attempt::get($id)->status === 'browser_closed', 'Попытка не была сброшена — проверка пуста');

        $html_reset = Shortcodes::render_my_results();
        $bc(!str_contains($html_reset, 'Сброшена'), 'Участнику показано уведомление о сброшенной попытке');
        $bc(str_contains($html_reset, 'Вы ещё не принимали участия'),
            'Сброшенная попытка осталась в списке «Мои результаты»');

        // Сданная попытка рядом обязана остаться — иначе проверка ничего не доказывает.
        $second = Attempt::start($quiz_id, ['name' => 'Ирина П', 'email' => $token . '@example.invalid', 'org' => 'Школа №7']);
        $bc($second instanceof Attempt, 'Вторая попытка не создана');
        $second->complete(2.0, 3.0, 'completed');
        $html_both = Shortcodes::render_my_results();
        $bc(str_contains($html_both, 'Завершено'), 'Сданная попытка пропала из «Мои результаты»');
        $bc(!str_contains($html_both, 'Сброшена'), 'Сброшенная попытка показалась рядом со сданной');
        return true;
    } finally {
        wp_set_current_user($previous_user);
        Quiz::delete($quiz_id);
    }
});
