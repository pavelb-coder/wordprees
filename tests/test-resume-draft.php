<?php
/**
 * 1.0.22: возврат к прерванной попытке, автосохранение черновика, статус «Прервана».
 */

namespace WPOlympiad\Tests;

use WPOlympiad\Attempt;
use WPOlympiad\DB;
use WPOlympiad\Export;
use WPOlympiad\Question;
use WPOlympiad\QuestionGroups;
use WPOlympiad\Quiz;
use WPOlympiad\RestApi;

if (!defined('ABSPATH') || !isset($it)) return;

$qq = static function (bool $ok, string $message, string $extra = ''): void {
    if (!$ok) {
        throw new \RuntimeException($extra !== '' ? $message . ' — ' . $extra : $message);
    }
};

$rd_choices = [
    'choices' => [
        ['id' => 'c1', 'text' => '4', 'is_correct' => 1],
        ['id' => 'c2', 'text' => '5', 'is_correct' => 0],
    ],
];

$rd_user = static function (string $login): int {
    $uid = wp_insert_user([
        'user_login' => $login,
        'user_email' => $login . '@example.invalid',
        'role' => 'subscriber',
        'display_name' => 'Тестовый ' . $login,
        'user_pass' => wp_generate_password(24, true, true),
    ]);

    return is_wp_error($uid) ? 0 : (int)$uid;
};

$rd_drop_user = static function (int $uid): void {
    if ($uid <= 0) {
        return;
    }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($uid);
};

$rd_cleanup = static function (int $quiz_id, ?Attempt $attempt) use ($qq): void {
    global $wpdb;
    if ($attempt instanceof Attempt) {
        $wpdb->delete(DB::responses_table(), ['attempt_id' => (int)$attempt->id], ['%d']);
    }
    $wpdb->delete(DB::attempts_table(), ['quiz_id' => $quiz_id], ['%d']);
    $wpdb->delete(DB::questions_table(), ['quiz_id' => $quiz_id], ['%d']);
};

// ── 200 ─────────────────────────────────────────────────────────────────────
$it('Черновик: сохраняется в попытке, перечитывается и очищается после сдачи', function () use ($qq, $rd_cleanup): bool {
    global $wpdb;
    $previous_user = get_current_user_id();
    wp_set_current_user(0);
    $token = strtolower(wp_generate_password(6, false, false));
    $id = Quiz::create([
        'title' => 'Rd draft ' . $token,
        'status' => 'publish',
        'settings' => ['allow_guest' => 1, 'time_limit' => 0],
    ]);

    $attempt = null;
    try {
        $attempt = Attempt::start($id, ['name' => 'Черновик ' . $token, 'email' => 'rd-' . $token . '@example.invalid']);
        $qq($attempt instanceof Attempt, 'Попытка не создана');

        $fresh = Attempt::get((int)$attempt->id);
        $qq($fresh && $fresh->draft_answers === null, 'У новой попытки черновик не пуст');
        $qq($fresh && $fresh->draft() === ['answers' => [], 'page' => 0, 'flags' => [], 'revision' => 0], 'Пустой черновик читается не как пустой');
        $qq($fresh && $fresh->draft_answer_count() === 0, 'Счётчик пустого черновика враньёт');

        $qq($attempt->save_draft([
            'answers' => [7 => 'c1', 9 => ['a', 'b']],
            'page' => 3,
            'flags' => [7, 7, 11],
        ]) !== false, 'Черновик не сохранён');

        $draft = $attempt->draft();
        $qq($draft['answers'] === [7 => 'c1', 9 => ['a', 'b']], 'Ответы черновика прочитаны неверно', json_encode($draft['answers'], JSON_UNESCAPED_UNICODE));
        $qq($draft['page'] === 3, 'Позиция участника не сохранена');
        $qq($draft['flags'] === [7, 11], 'Флажки не сохранены или не схлопнуты', json_encode($draft['flags']));
        $qq($draft['revision'] === 1, 'Первая ревизия не равна 1', (string)$draft['revision']);
        $qq($attempt->draft_answer_count() === 2, 'Счётчик ответов черновика неверен');

        $attempt->save_draft(['answers' => [7 => 'c2'], 'page' => 4]);
        $qq($attempt->draft()['revision'] === 2, 'Ревизия не выросла при перезаписи');
        $qq($attempt->draft()['answers'] === [7 => 'c2'], 'Черновик не перезаписан целиком');

        $from_db = Attempt::get((int)$attempt->id);
        $qq($from_db && $from_db->draft()['page'] === 4, 'Черновик не дочитывается из базы');
        $qq($from_db && !empty($from_db->draft_updated_at), 'Время автосохранения не записано');

        // Битый JSON в колонке не должен ломать чтение.
        $wpdb->update(DB::attempts_table(), ['draft_answers' => '{not json'], ['id' => (int)$attempt->id], ['%s'], ['%d']);
        $broken = Attempt::get((int)$attempt->id);
        $qq($broken && $broken->draft() === ['answers' => [], 'page' => 0, 'flags' => [], 'revision' => 0], 'Битый черновик не обнулён');
        $qq($broken && $broken->draft_answer_count() === 0, 'Счётчик битого черновика враньёт');

        $qq($attempt->clear_draft(), 'Черновик не очищается');
        $qq(Attempt::get((int)$attempt->id)->draft_answers === null, 'После очистки в базе что-то осталось');

        return true;
    } finally {
        $rd_cleanup($id, $attempt);
        wp_set_current_user($previous_user);
        Quiz::delete($id);
    }
});

// ── 201 ─────────────────────────────────────────────────────────────────────
$it('Черновик: чистка присланного — только свои вопросы, только скаляры, с потолками', function () use ($qq, $rd_choices, $rd_cleanup): bool {
    $previous_user = get_current_user_id();
    wp_set_current_user(0);
    $token = strtolower(wp_generate_password(6, false, false));
    $id = Quiz::create([
        'title' => 'Rd clean ' . $token,
        'status' => 'publish',
        'settings' => ['allow_guest' => 1],
    ]);

    try {
        $own = Question::create(['quiz_id' => $id, 'question_type' => 'single', 'title' => 'Своё задание', 'points' => 1, 'options' => $rd_choices]);
        $essay = Question::create(['quiz_id' => $id, 'question_type' => 'text', 'title' => 'Развёрнутый ответ', 'points' => 2, 'options' => []]);
        $quiz = Quiz::get($id);

        $long = str_repeat('ы', RestApi::MAX_DRAFT_VALUE_LENGTH + 5000);
        $huge = [];
        for ($i = 0; $i < RestApi::MAX_DRAFT_ANSWERS + 40; $i++) {
            $huge['99900' . $i] = 'мусор';   // чужие id — все будут отброшены
        }
        $huge[(int)$own] = 'c1';
        $huge[(int)$essay] = $long;

        $clean = RestApi::normalize_draft($quiz, $huge, '12', [(int)$own, 999999, 'x', 12345678], 5);
        $qq(array_keys($clean['answers']) === [(int)$own, (int)$essay], 'Чужие id вопросов не отброшены', json_encode(array_keys($clean['answers'])));
        $qq($clean['answers'][(int)$own] === 'c1', 'Скалярное значение испорчено');
        $qq(mb_strlen($clean['answers'][(int)$essay], 'UTF-8') <= RestApi::MAX_DRAFT_VALUE_LENGTH, 'Длинный ответ не обрезан по лимиту', (string)mb_strlen($clean['answers'][(int)$essay], 'UTF-8'));
        $qq($clean['page'] === 12, 'Позиция не приведена к числу');
        $qq($clean['flags'] === [(int)$own], 'Чужие флажки не отброшены', json_encode($clean['flags']));
        $qq($clean['revision'] === 5, 'Ревизия потеряна');

        // Бюджет на весь черновик: 500 самых длинных ответов — это мегабайты.
        $many_ids = [];
        for ($i = 0; $i < 30; $i++) {
            $many_ids[] = Question::create(['quiz_id' => $id, 'question_type' => 'text', 'title' => 'Эссе ' . $i, 'points' => 1, 'options' => []]);
        }
        $quiz = Quiz::get($id);
        $many = [];
        foreach ($many_ids as $mid) {
            $many[(int)$mid] = str_repeat('д', 9000);
        }
        $budgeted = RestApi::normalize_draft($quiz, $many, 0, []);
        $qq(count($budgeted['answers']) < 30, 'Бюджет размера не сократил число ответов', (string)count($budgeted['answers']));
        $total = 0;
        foreach ($budgeted['answers'] as $value) {
            $total += is_string($value) ? mb_strlen($value) : mb_strlen((string)wp_json_encode($value));
        }
        $qq($total <= RestApi::MAX_DRAFT_TOTAL_LENGTH, 'Бюджет размера черновика не соблюдён', (string)$total);

        // Пустое, булево, вложенные структуры и HTML.
        $mixed = RestApi::normalize_draft($quiz, [
            (int)$own => '',
            (int)$essay => '   ',
        ], -4, []);
        $qq($mixed['answers'] === [], 'Пустые значения сохранены');
        $qq($mixed['page'] === 0, 'Отрицательная позиция не схлопнута в ноль');

        $mixed = RestApi::normalize_draft($quiz, [
            (int)$own => true,
            (int)$essay => ['a', ['b'], 3.5, null, '<script>alert(1)</script>текст'],
        ], 0, []);
        $qq($mixed['answers'][(int)$own] === '1', 'Булево значение не сведено к «1»');
        $qq(json_encode($mixed['answers'][(int)$essay], JSON_UNESCAPED_UNICODE) === '["a","3.5","текст"]', 'Массив ответа почистился неверно', json_encode($mixed['answers'][(int)$essay], JSON_UNESCAPED_UNICODE));

        $nested = RestApi::normalize_draft($quiz, [(int)$own => [['a'], 'ok']], 0, []);
        $qq($nested['answers'][(int)$own] === ['ok'], 'Вложенный элемент не выброшен поштучно', json_encode($nested['answers'][(int)$own] ?? null));

        $grid = RestApi::normalize_draft($quiz, [(int)$essay => ['row1' => 'a', 'плохой ключ' => 'b', 'row-2' => 'c']], 0, []);
        $qq(array_keys($grid['answers'][(int)$essay]) === ['row1', 'row-2'], 'Ключи сетки не фильтруются', json_encode(array_keys($grid['answers'][(int)$essay])));

        $qq(RestApi::normalize_draft($quiz, 'не массив', 'не число', 'не массив', 0)['answers'] === [], 'Мусор вместо answers не пролез');

        return true;
    } finally {
        $rd_cleanup($id, null);
        wp_set_current_user($previous_user);
        Quiz::delete($id);
    }
});

// ── 202 ─────────────────────────────────────────────────────────────────────
$it('Поиск несданной попытки: своя находится, чужая и сданная — нет', function () use ($qq, $rd_choices, $rd_cleanup, $rd_user, $rd_drop_user): bool {
    global $wpdb;
    $previous_user = get_current_user_id();
    wp_set_current_user(0);
    $token = strtolower(wp_generate_password(6, false, false));
    $id = Quiz::create([
        'title' => 'Rd find ' . $token,
        'status' => 'publish',
        'settings' => ['allow_guest' => 1],
    ]);

    $alice = 'alice-' . $token . '@example.invalid';
    $bob = 'bob-' . $token . '@example.invalid';

    try {
        $qid = Question::create(['quiz_id' => $id, 'question_type' => 'single', 'title' => 'Задание', 'points' => 1, 'options' => $rd_choices]);

        $qq(Attempt::find_resumable($id, 0, $alice) === null, 'Пустая база отдала попытку');

        $alice_attempt = Attempt::start($id, ['name' => 'Алиса ' . $token, 'email' => $alice]);
        $bob_attempt = Attempt::start($id, ['name' => 'Боб ' . $token, 'email' => $bob]);
        $qq($alice_attempt instanceof Attempt && $bob_attempt instanceof Attempt, 'Попытки не созданы');

        $found = Attempt::find_resumable($id, 0, $alice);
        $qq($found instanceof Attempt && (int)$found->id === (int)$alice_attempt->id, 'Попытка Алисы не найдена');
        $qq((int)Attempt::find_resumable($id, 0, 'ALICE-' . $token . '@EXAMPLE.INVALID')->id === (int)$alice_attempt->id, 'E-mail в другом регистре не подошёл');
        $qq(Attempt::find_resumable($id, 0, $bob) instanceof Attempt, 'Попытка Боба не найдена');
        $qq(Attempt::find_resumable($id, 0, 'нет-такого@example.invalid') === null, 'Сторонний e-mail чужую попытку вернул');
        $qq(Attempt::find_resumable($id, 0, '') === null, 'Гостю без e-mail вернулась чужая попытка');

        // Берётся последняя по id, если у участника их несколько.
        $second = Attempt::start($id, ['name' => 'Алиса ' . $token, 'email' => $alice]);
        $qq((int)Attempt::find_resumable($id, 0, $alice)->id === (int)$second->id, 'Найдена не последняя попытка');

        // Сданная и прерванная попытки больше не возвращаются.
        $second->complete(1.0, 1.0, 'completed');
        $qq((int)Attempt::find_resumable($id, 0, $alice)->id === (int)$alice_attempt->id, 'Зачтённая попытка всё ещё считается несданной');
        $alice_attempt->complete(0.0, 1.0, 'abandoned');
        $qq(Attempt::find_resumable($id, 0, $alice) === null, 'Прерванная попытка вернулась как живая');

        // Попытка вошедшего пользователя ищется по id, а не по чужому e-mail.
        $alice_attempt2 = Attempt::start($id, ['name' => 'Алиса ' . $token, 'email' => $alice]);
        $uid = $rd_user('rd-user-' . $token);
        $qq($uid > 0, 'Тестовый пользователь не создан');
        $wpdb->update(DB::attempts_table(), ['user_id' => $uid], ['id' => (int)$alice_attempt2->id], ['%d'], ['%d']);
        $by_user = Attempt::find_resumable($id, $uid);
        $qq($by_user instanceof Attempt && (int)$by_user->id === (int)$alice_attempt2->id, 'Попытка вошедшего не найдена по id');
        $qq(Attempt::find_resumable($id, $uid + 1) === null, 'Чужая пользовательская попытка найдена по чужому id');

        return true;
    } finally {
        $rd_cleanup($id, null);
        wp_set_current_user($previous_user);
        if (isset($uid)) {
            $rd_drop_user((int)$uid);
        }
        Quiz::delete($id);
    }
});

// ── 203 ─────────────────────────────────────────────────────────────────────
$it('Доступ к попытке: гостевая по токену, пользовательская — только владельцу', function () use ($qq, $rd_cleanup, $rd_user, $rd_drop_user): bool {
    global $wpdb;
    $previous_user = get_current_user_id();
    wp_set_current_user(0);
    $token = strtolower(wp_generate_password(6, false, false));
    $id = Quiz::create([
        'title' => 'Rd own ' . $token,
        'status' => 'publish',
        'settings' => ['allow_guest' => 1],
    ]);

    $guest = null;
    $owned = null;
    try {
        $guest = Attempt::start($id, ['name' => 'Гость ' . $token, 'email' => 'guest-' . $token . '@example.invalid']);
        $owned = Attempt::start($id, ['name' => 'Владелец ' . $token, 'email' => 'own-' . $token . '@example.invalid']);
        $uid = $rd_user('rd-own-' . $token);
        $qq($uid > 0, 'Тестовый пользователь не создан');
        $wpdb->update(DB::attempts_table(), ['user_id' => $uid], ['id' => (int)$owned->id], ['%d'], ['%d']);
        $owned = Attempt::get((int)$owned->id);

        $guest_email = 'guest-' . $token . '@example.invalid';
        $qq($guest->belongs_to(0, ''), 'Гостевая попытка не отдаётся держателю токена');
        $qq($guest->belongs_to(0, strtoupper($guest_email)), 'E-mail в другом регистре не признан своим');
        $qq(!$guest->belongs_to(0, 'vinovnyy@example.invalid'), 'Чужой e-mail получил доступ к гостевой попытке');

        $qq($owned->belongs_to($uid), 'Владелец не узнал свою попытку');
        $qq(!$owned->belongs_to($uid + 7), 'Чужой пользователь получил доступ к попытке');
        $qq(!$owned->belongs_to(0, 'own-' . $token . '@example.invalid'), 'Гостю выдана попытка вошедшего пользователя');

        return true;
    } finally {
        $rd_cleanup($id, null);
        wp_set_current_user($previous_user);
        if (isset($uid)) {
            $rd_drop_user((int)$uid);
        }
        Quiz::delete($id);
    }
});

// ── 204 ─────────────────────────────────────────────────────────────────────
$it('Порядок заданий фиксируется за попыткой и не затирает анкету с группами', function () use ($qq, $rd_cleanup): bool {
    $previous_user = get_current_user_id();
    wp_set_current_user(0);
    $token = strtolower(wp_generate_password(6, false, false));
    $id = Quiz::create([
        'title' => 'Rd order ' . $token,
        'status' => 'publish',
        'settings' => ['allow_guest' => 1],
    ]);

    $attempt = null;
    try {
        $attempt = Attempt::start($id, [
            'name' => 'Порядок ' . $token,
            'email' => 'ro-' . $token . '@example.invalid',
            'custom_fields' => ['klass' => '9'],
        ]);
        $attempt->mark_group_opened('Альфа');

        $attempt->set_question_order([5, '7', 3.0, 0, -2, 'x', [1], 11]);
        $qq($attempt->question_order() === [5, 7, 3, 11], 'Мусор в порядке заданий не отфильтрован', json_encode($attempt->question_order()));

        $fresh = Attempt::get((int)$attempt->id);
        $qq($fresh && $fresh->question_order() === [5, 7, 3, 11], 'Порядок не дочитывается из базы');
        $qq($fresh && $fresh->custom_field_values() === ['klass' => '9'], 'Порядок затёр поля анкеты', json_encode($fresh ? $fresh->custom_field_values() : null));
        $qq($fresh && $fresh->groups_opened() !== [], 'Порядок затёр отметки групп');

        $attempt->set_question_order([]);
        $qq(Attempt::get((int)$attempt->id)->question_order() === [5, 7, 3, 11], 'Пустой список стёр сохранённый порядок');

        $qq($fresh && $fresh->meta()['order'] === [5, 7, 3, 11], 'meta() не отдаёт порядок');

        return true;
    } finally {
        $rd_cleanup($id, $attempt);
        wp_set_current_user($previous_user);
        Quiz::delete($id);
    }
});

// ── 205 ─────────────────────────────────────────────────────────────────────
$it('Возврат к попытке: тот же токен, тот же порядок, черновик и остаток времени', function () use ($qq, $rd_choices, $rd_cleanup): bool {
    global $wpdb;
    $previous_user = get_current_user_id();
    wp_set_current_user(0);
    $token = strtolower(wp_generate_password(6, false, false));
    $email = 'rs-' . $token . '@example.invalid';
    $id = Quiz::create([
        'title' => 'Rd resume ' . $token,
        'status' => 'publish',
        'settings' => [
            'allow_guest' => 1,
            'time_limit' => 30,
            'questions_per_page' => 2,
            'shuffle_questions' => 1,
            'question_groups' => [
                ['name' => 'Альфа', 'title' => 'Блок «Альфа»', 'time_limit' => 5, 'position' => 1],
                ['name' => 'Бета', 'time_limit' => 0, 'position' => 2],
            ],
        ],
    ]);

    $attempt = null;
    try {
        $q1 = Question::create(['quiz_id' => $id, 'question_type' => 'single', 'title' => 'А2', 'points' => 1, 'options' => $rd_choices + ['group_name' => 'Альфа']]);
        $q2 = Question::create(['quiz_id' => $id, 'question_type' => 'single', 'title' => 'Б1', 'points' => 1, 'options' => $rd_choices + ['group_name' => 'Бета']]);
        $q3 = Question::create(['quiz_id' => $id, 'question_type' => 'single', 'title' => 'А1', 'points' => 2, 'options' => $rd_choices + ['group_name' => 'Альфа']]);

        $attempt = Attempt::start($id, ['name' => 'Возврат ' . $token, 'email' => $email]);
        // Порядок, «перемешанный» при старте: возврат обязан дать его же, а не новый.
        $attempt->set_question_order([(int)$q3, (int)$q1, (int)$q2]);
        $attempt->save_draft(['answers' => [(int)$q2 => 'c2', (int)$q1 => 'c1'], 'page' => 1, 'flags' => [(int)$q3]]);
        $attempt->mark_group_opened('Альфа');
        $wpdb->update(DB::attempts_table(), ['started_at' => date('Y-m-d H:i:s', time() - 95)], ['id' => (int)$attempt->id], ['%s'], ['%d']);

        $req = new \WP_REST_Request('POST', '/wp-olympiad/v1/resume');
        $req->set_param('quiz_id', $id);
        $req->set_param('participant_email', $email);
        $res = RestApi::handle_resume($req);
        $qq($res instanceof \WP_REST_Response, 'Возврат не удался', $res instanceof \WP_Error ? $res->get_error_message() : gettype($res));

        $data = $res->get_data();
        $qq(($data['found'] ?? false) === true, 'Попытка не найдена');
        $qq(($data['resumed'] ?? false) === true, 'Ответ не помечен как возврат');
        $qq(($data['token'] ?? '') === $attempt->token, 'Выдан другой токен — ответ уйдёт в чужую попытку');
        $qq((int)($data['attempt_id'] ?? 0) === (int)$attempt->id, 'Не тот id попытки');

        $ids = array_map(static fn($q) => (int)$q['id'], (array)($data['questions'] ?? []));
        $qq($ids === [(int)$q3, (int)$q1, (int)$q2], 'Порядок заданий при возврате перемешан заново', json_encode($ids));
        $qq((int)($data['questions_per_page'] ?? 0) === 2, 'Постраничный вывод потерян при возврате');
        $qq(count((array)($data['question_groups'] ?? [])) === 2, 'Настройки групп потеряны при возврате');

        $remaining = (int)($data['remaining_seconds'] ?? -1);
        $qq($remaining > 30 * 60 - 130 && $remaining <= 30 * 60 - 90, 'Остаток времени посчитан неверно', (string)$remaining);

        $draft = (array)($data['draft'] ?? []);
        $qq(($draft['answers'] ?? []) === [(int)$q2 => 'c2', (int)$q1 => 'c1'], 'Черновик не вернулся', json_encode($draft['answers'] ?? [], JSON_UNESCAPED_UNICODE));
        $qq((int)($draft['page'] ?? -1) === 1, 'Позиция не вернулась');
        $qq(($draft['flags'] ?? []) === [(int)$q3], 'Флажки не вернулись');

        $elapsed = (array)($data['group_elapsed'] ?? []);
        $alfa = QuestionGroups::key('Альфа');
        $qq(isset($elapsed[$alfa]) && (int)$elapsed[$alfa] >= 0, 'Открытая группа вернулась без потраченного времени', json_encode($elapsed));
        $qq(!isset($elapsed[QuestionGroups::key('Бета')]), 'Открытой не была — а время группы отдано');

        // Поиск по токену (перезагрузка страницы: e-mail ещё не введён).
        $by_token = new \WP_REST_Request('POST', '/wp-olympiad/v1/resume');
        $by_token->set_param('quiz_id', $id);
        $by_token->set_param('token', $attempt->token);
        $res2 = RestApi::handle_resume($by_token);
        $qq($res2 instanceof \WP_REST_Response && ($res2->get_data()['found'] ?? false) === true, 'Возврат по токену не работает');

        // Чужой e-mail не должен находить чужую попытку даже с её токеном.
        $thief = new \WP_REST_Request('POST', '/wp-olympiad/v1/resume');
        $thief->set_param('token', $attempt->token);
        $thief->set_param('participant_email', 'vory-' . $token . '@example.invalid');
        $res3 = RestApi::handle_resume($thief);
        $qq(($res3->get_data()['found'] ?? true) === false, 'Чужой e-mail получил доступ к попытке по токену');

        return true;
    } finally {
        $rd_cleanup($id, $attempt);
        wp_set_current_user($previous_user);
        Quiz::delete($id);
    }
});

// ── 206 ─────────────────────────────────────────────────────────────────────
$it('Автосейв: принимается только живая попытка, после истечения времени — отказ', function () use ($qq, $rd_choices, $rd_cleanup): bool {
    global $wpdb;
    $previous_user = get_current_user_id();
    wp_set_current_user(0);
    $token = strtolower(wp_generate_password(6, false, false));
    $id = Quiz::create([
        'title' => 'Rd save ' . $token,
        'status' => 'publish',
        'settings' => ['allow_guest' => 1, 'time_limit' => 1],
    ]);

    $attempt = null;
    try {
        $qid = Question::create(['quiz_id' => $id, 'question_type' => 'single', 'title' => 'Задание', 'points' => 1, 'options' => $rd_choices]);
        $attempt = Attempt::start($id, ['name' => 'Сейв ' . $token, 'email' => 'sv-' . $token . '@example.invalid']);

        $req = new \WP_REST_Request('POST', '/wp-olympiad/v1/save');
        $req->set_param('token', $attempt->token);
        $req->set_param('answers', [(int)$qid => 'c1', 424242 => 'чужой']);
        $req->set_param('page', 0);
        $res = RestApi::handle_save_draft($req);
        $qq($res instanceof \WP_REST_Response, 'Черновик живой попытки не принят', $res instanceof \WP_Error ? $res->get_error_message() : gettype($res));
        $qq((int)$res->get_data()['answers_saved'] === 1, 'Чужой id вопроса просочился в черновик');
        $reloaded = Attempt::get((int)$attempt->id);
        $qq($reloaded && $reloaded->draft()['answers'] === [(int)$qid => 'c1'], 'Черновик после автосейва не такой', json_encode($reloaded ? $reloaded->draft()['answers'] : null, JSON_UNESCAPED_UNICODE));
        $qq(!empty($reloaded->draft_updated_at), 'Время автосейва не записано');
        $resp_data = $res->get_data();
        $qq((int)$resp_data['revision'] === (int)$reloaded->draft()['revision'],
             'Ответ /save врёт о ревизии черновика',
             $resp_data['revision'] . ' != ' . $reloaded->draft()['revision']);

        // Просроченная попытка черновик уже не принимает.
        $wpdb->update(DB::attempts_table(), ['started_at' => date('Y-m-d H:i:s', time() - 600)], ['id' => (int)$attempt->id], ['%s'], ['%d']);
        $late = RestApi::handle_save_draft($req);
        $qq($late instanceof \WP_Error && $late->get_error_code() === 'time_expired', 'Просроченной попытке разрешили автосейв');
        $late_data = (array)$late->get_error_data();
        $qq((int)($late_data['status'] ?? 0) === 409, 'Отказ автосейва не 409', json_encode($late_data));

        // Сданная попытка — тоже.
        $attempt->complete(1.0, 1.0, 'completed');
        $after = RestApi::handle_save_draft($req);
        $qq($after instanceof \WP_Error && $after->get_error_code() === 'invalid_attempt', 'Черновик пишется в сданную попытку');
        $qq((int)((array)$after->get_error_data())['status'] === 404, 'Отказ сданной попытке не 404');

        $unknown = new \WP_REST_Request('POST', '/wp-olympiad/v1/save');
        $unknown->set_param('token', 'nett-nett-nett');
        $qq(RestApi::handle_save_draft($unknown) instanceof \WP_Error, 'Автосейв по несуществующему токену прошёл');

        return true;
    } finally {
        $rd_cleanup($id, $attempt);
        wp_set_current_user($previous_user);
        Quiz::delete($id);
    }
});

// ── 207 ─────────────────────────────────────────────────────────────────────
$it('Возврат после истечения личного лимита: время не дарится, написанное — организатору', function () use ($qq, $rd_choices, $rd_cleanup): bool {
    global $wpdb;
    $previous_user = get_current_user_id();
    wp_set_current_user(0);
    $token = strtolower(wp_generate_password(6, false, false));
    $email = 'xp-' . $token . '@example.invalid';
    $id = Quiz::create([
        'title' => 'Rd expired ' . $token,
        'status' => 'publish',
        'settings' => ['allow_guest' => 1, 'time_limit' => 1],
    ]);

    $attempt = null;
    try {
        $qid = Question::create(['quiz_id' => $id, 'question_type' => 'single', 'title' => 'Задание', 'points' => 3, 'options' => $rd_choices]);
        $attempt = Attempt::start($id, ['name' => 'Просрочка ' . $token, 'email' => $email]);
        $attempt->save_draft(['answers' => [(int)$qid => 'c1']]);
        $wpdb->update(DB::attempts_table(), ['started_at' => date('Y-m-d H:i:s', time() - 1200)], ['id' => (int)$attempt->id], ['%s'], ['%d']);

        $req = new \WP_REST_Request('POST', '/wp-olympiad/v1/resume');
        $req->set_param('quiz_id', $id);
        $req->set_param('participant_email', $email);
        $res = RestApi::handle_resume($req);
        $qq($res instanceof \WP_REST_Response, 'Возврат просроченной попытки упал ошибкой');

        $data = $res->get_data();
        $qq(($data['found'] ?? true) === false, 'Просроченная попытка вернулась как живая');
        $qq(($data['expired'] ?? false) === true, 'Причина отказа не названа');
        $qq(($data['preserved'] ?? false) === true, 'Написанное не сохранено');
        $qq(str_contains((string)($data['message'] ?? ''), 'передано организатору'), 'В тексте не сказано, что работа ушла организатору', (string)($data['message'] ?? ''));

        $closed = Attempt::get((int)$attempt->id);
        $qq($closed && $closed->status === 'expired', 'Просроченная попытка не закрыта как expired', $closed ? $closed->status : 'null');
        $qq($closed && (int)$closed->score === 0, 'Просроченной попытке начислены баллы');
        $qq($closed && $closed->draft_answers === null, 'Черновик после закрытия не убран');

        $rows = $closed ? $closed->get_responses() : [];
        $qq(count((array)$rows) === 1, 'Ответ просроченной попытки не сохранён', (string)count((array)$rows));
        $row = (array)(($rows[0] ?? []));
        $qq((int)$row['awarded_points'] === 0, 'Сохранённому ответу начислены баллы');
        $qq((int)$row['needs_manual_review'] === 1, 'Сохранённый ответ не помечен на ручную проверку');
        $qq(str_contains((string)$row['review_comment'], 'пока участника не было'), 'Комментарий не объясняет причину', (string)$row['review_comment']);

        // Повторный возврат ничего не находит: попытка уже закрыта.
        $again = RestApi::handle_resume($req);
        $qq(($again->get_data()['found'] ?? true) === false, 'Закрытая попытка снова предлагается к возврату');
        $qq(($again->get_data()['expired'] ?? false) === false, 'За закрытой попыткой снова сообщается про истечение');

        return true;
    } finally {
        $rd_cleanup($id, $attempt);
        wp_set_current_user($previous_user);
        Quiz::delete($id);
    }
});

// ── 208 ─────────────────────────────────────────────────────────────────────
$it('«Начать заново»: попытка получает статус «Прервана» и остаётся в протоколе', function () use ($qq, $rd_choices, $rd_cleanup): bool {
    $previous_user = get_current_user_id();
    wp_set_current_user(0);
    $token = strtolower(wp_generate_password(6, false, false));
    $email = 'ab-' . $token . '@example.invalid';
    $id = Quiz::create([
        'title' => 'Rd abandon ' . $token,
        'status' => 'publish',
        'settings' => ['allow_guest' => 1, 'time_limit' => 0],
    ]);

    $attempt = null;
    try {
        $qid = Question::create(['quiz_id' => $id, 'question_type' => 'single', 'title' => 'Сколько будет 2 + 2?', 'points' => 3, 'options' => $rd_choices]);
        $attempt = Attempt::start($id, ['name' => 'Участник ' . $token, 'email' => $email]);
        $attempt->save_draft(['answers' => [(int)$qid => 'c1'], 'page' => 0]);

        // Чужой e-mail прервать чужую попытку не может.
        $thief = new \WP_REST_Request('POST', '/wp-olympiad/v1/abandon');
        $thief->set_param('token', $attempt->token);
        $thief->set_param('participant_email', 'ne ya@example.invalid');
        $bad = RestApi::handle_abandon($thief);
        $qq($bad instanceof \WP_Error && (int)$bad->get_error_data()['status'] === 403, 'Чужую попытку можно закрыть своей рукой');

        $req = new \WP_REST_Request('POST', '/wp-olympiad/v1/abandon');
        $req->set_param('token', $attempt->token);
        $req->set_param('participant_email', $email);
        $res = RestApi::handle_abandon($req);
        $qq($res instanceof \WP_REST_Response, 'Отказ от попытки не принят', $res instanceof \WP_Error ? $res->get_error_message() : gettype($res));
        $qq(($res->get_data()['answers_preserved'] ?? false) === true, 'Черновик прерванной попытки не сохранён');

        $closed = Attempt::get((int)$attempt->id);
        $qq($closed && $closed->status === 'abandoned', 'Статус не стал abandoned', $closed ? $closed->status : 'null');
        $qq($closed && $closed->get_status_label() === 'Прервана', 'Метка статуса неверна', $closed ? $closed->get_status_label() : '');
        $qq($closed && (int)$closed->score === 0, 'Прерванной попытке начислены баллы');
        $qq($closed && (int)$closed->max_score === 3, 'Максимальный балл прерванной попытки не проставлен');
        $qq($closed && $closed->draft_answers === null, 'Черновик после отказа не убран');
        $qq($closed && $closed->draft_answer_count() === 0, 'Счётчик после очистки враньёт');

        $rows = (array)($closed ? $closed->get_responses() : []);
        $qq(count($rows) === 1, 'Ответы прерванной попытки не сохранены');
        $row = (array)($rows[0] ?? []);
        $qq((int)$row['awarded_points'] === 0, 'Прерванным ответам начислены баллы');
        $qq((int)$row['needs_manual_review'] === 1, 'Прерванные ответы не ушли на ручную проверку');
        $qq(str_contains((string)$row['review_comment'], 'участник начал новую'), 'Комментарий не объясняет причину', (string)$row['review_comment']);

        // Прерванная попытка остаётся в протоколе — со своими ответами и нулём баллов.
        $csv = (string)Export::export_quiz_attempts($id, false);
        $lines = array_values(array_filter(explode("\n", trim($csv)), static fn($l) => $l !== ''));
        $row_csv = str_getcsv($lines[1] ?? '', ';', '"', '\\');
        $qq(str_contains($lines[1] ?? '', 'Прервана'), 'В протоколе нет статуса «Прервана»', (string)($lines[1] ?? ''));
        $joined = implode('|', (array)$row_csv);
        $qq(str_contains($joined, '4') || str_contains($joined, 'c1'), 'Ответ из черновика не попал в протокол', $joined);

        // Повторный отказ от уже закрытой попытки невозможен, как и возврат.
        $again = RestApi::handle_abandon($req);
        $qq($again instanceof \WP_Error && $again->get_error_code() === 'invalid_attempt', 'Закрытую попытку можно прервать ещё раз');

        $resume = new \WP_REST_Request('POST', '/wp-olympiad/v1/resume');
        $resume->set_param('quiz_id', $id);
        $resume->set_param('participant_email', $email);
        $resume_data = RestApi::handle_resume($resume)->get_data();
        $qq(($resume_data['found'] ?? true) === false, 'Прерванная попытка предлагается к возврату');

        // Новая попытка того же участника создаётся как обычно и не наследует черновик.
        $new = Attempt::start($id, ['name' => 'Участник ' . $token, 'email' => $email]);
        $qq($new instanceof Attempt && (int)$new->id !== (int)$closed->id, 'Новая попытка не создана');
        $qq($new->draft()['answers'] === [], 'Черновик прерванной попытки перетёк в новую');
        $rd_cleanup($id, $new);

        return true;
    } finally {
        $rd_cleanup($id, $attempt);
        wp_set_current_user($previous_user);
        Quiz::delete($id);
    }
});

// ── 209 ─────────────────────────────────────────────────────────────────────
$it('Старт с переполненными полями: значения усечены по лимиту колонки, попытка заведена', function () use ($qq, $rd_choices, $rd_cleanup): bool {
    global $wpdb;
    $previous_user = get_current_user_id();
    wp_set_current_user(0);
    $token = strtolower(wp_generate_password(6, false, false));
    $id = Quiz::create([
        'title' => 'Rd overflow ' . $token,
        'status' => 'publish',
        'settings' => ['allow_guest' => 1, 'time_limit' => 30],
    ]);

    $attempt = null;
    try {
        $qid = (int)Question::create(['quiz_id' => $id, 'question_type' => 'single', 'title' => 'Задание', 'points' => 1, 'options' => $rd_choices]);

        $attempt = Attempt::start($id, [
            'name'  => str_repeat('И', 5000),
            'email' => str_repeat('a', 600) . '@example.invalid',
            'org'   => str_repeat('О', 3000),
            'phone' => str_repeat('8', 500),
            'city'  => str_repeat('Г', 500),
        ]);
        $qq($attempt instanceof Attempt, 'Старт с длинными полями не создал попытку');

        $row = $wpdb->get_row("SELECT participant_name, participant_email, participant_org, participant_phone, participant_city FROM "
            . DB::attempts_table() . " WHERE id=" . (int)$attempt->id, ARRAY_A);
        $qq(is_array($row) && $row !== [], 'Строка попытки не найдена');

        $qq(mb_strlen((string)$row['participant_name'], 'UTF-8') === 255, 'Имя не усечено до 255 символов', (string)mb_strlen((string)$row['participant_name'], 'UTF-8'));
        $qq(mb_strlen((string)$row['participant_org'], 'UTF-8') === 255, 'Организация не усечена до 255 символов', (string)mb_strlen((string)$row['participant_org'], 'UTF-8'));
        $qq(mb_strlen((string)$row['participant_email'], 'UTF-8') === 255, 'E-mail не усечён до 255 символов', (string)mb_strlen((string)$row['participant_email'], 'UTF-8'));
        $qq(mb_strlen((string)$row['participant_phone'], 'UTF-8') === 100, 'Телефон не усечён до 100 символов', (string)mb_strlen((string)$row['participant_phone'], 'UTF-8'));
        $qq(mb_strlen((string)$row['participant_city'], 'UTF-8') === 150, 'Город не усечён до 150 символов', (string)mb_strlen((string)$row['participant_city'], 'UTF-8'));

        $qq((int)$wpdb->get_var("SELECT COUNT(*) FROM " . DB::attempts_table() . " WHERE quiz_id=" . (int)$id) === 1, 'Попыток больше одной');

        // Черновик и возврат с усечённой идентификацией работают как обычно.
        $saved = $attempt->save_draft(['answers' => [(int)$qid => 'c1'], 'page' => 1, 'flags' => [], 'revision' => 0]);
        $qq($saved, 'Черновик в такую попытку не сохраняется');
        $found = Attempt::find_resumable($id, 0, strtolower((string)$row['participant_email']));
        $qq($found instanceof Attempt && (int)$found->id === (int)$attempt->id, 'Возврат не находит попытку по усечённому e-mail');

        return true;
    } finally {
        $rd_cleanup($id, $attempt);
        wp_set_current_user($previous_user);
        Quiz::delete($id);
    }
});
