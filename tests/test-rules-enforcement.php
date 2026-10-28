<?php
/**
 * 1.0.18: регламент должен работать одинаково предсказуемо — честное расписание,
 * видимый предпросмотр организатора, сдача начатой попытки после окончания приёма.
 */

namespace WPOlympiad\Tests;

use WPOlympiad\Quiz;
use WPOlympiad\Attempt;
use WPOlympiad\AntiCheat;
use WPOlympiad\Shortcodes;

if (!defined('ABSPATH') || !isset($it)) return;

$rules = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new \RuntimeException($message);
    }
};

$it('Расписание: нормализация ввода и отказ неоднозначным датам', function () use ($rules): bool {
    $cases = [
        // [ввод, это_окончание, ожидание]
        ['2026-10-15 10:00', false, '2026-10-15 10:00'],
        ['  2026-10-15 10:00:59 ', false, '2026-10-15 10:00'],
        ['2026-10-15T10:00', false, '2026-10-15 10:00'],
        ['15.10.2026 10:00', false, '2026-10-15 10:00'],
        ['2026-10-15', false, '2026-10-15 00:00'],
        ['2026-10-15', true, '2026-10-15 23:59'],
        ['15.10.2026', true, '2026-10-15 23:59'],
        ['', false, ''],
        ['   ', true, ''],
    ];
    foreach ($cases as [$raw, $end_of_day, $expected]) {
        $got = Quiz::normalize_schedule_time($raw, $end_of_day);
        $rules($got === $expected, sprintf('Ввод «%s» (end=%d) нормализован в «%s», ожидалось «%s»', $raw, (int)$end_of_day, var_export($got, true), $expected));
    }

    // Неоднозначные и невозможные значения не должны молча отключать окно.
    foreach (['10/11/2026 23:59', '2026.10.11 10:00', '31 12 2026', 'не дата', '2026-02-30 10:00', '2026-10-15 24:00', '2026-10-15 10:75'] as $bad) {
        $rules(Quiz::normalize_schedule_time($bad) === null, 'Мусорное значение «' . $bad . '» принято');
    }

    $rules(Quiz::normalize_schedule_time(['x']) === '', 'Массив должен читаться как пустое значение');
    return true;
});

$it('Расписание: код причины и видимый обход для администратора', function () use ($rules): bool {
    $previous_user = get_current_user_id();
    wp_set_current_user(0);
    $token = strtolower(wp_generate_password(8, false, false));
    $tz = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
    $now = new \DateTimeImmutable('now', $tz);

    $id = Quiz::create([
        'title' => 'Rules window ' . $token,
        'status' => 'publish',
        'settings' => [
            'start_time' => $now->modify('-2 hours')->format('Y-m-d H:i'),
            'end_time' => $now->modify('+2 hours')->format('Y-m-d H:i'),
        ],
    ]);
    try {
        $status = Quiz::get($id)->is_active_now();
        $rules($status['active'] === true && $status['code'] === '' && $status['admin_preview'] === false, 'Открытое окно должно быть активным без оговорок');

        // После окончания — закрыто для гостя и видно, почему.
        Quiz::get($id)->update(['settings' => ['end_time' => $now->modify('-10 minutes')->format('Y-m-d H:i')]]);
        $closed = Quiz::get($id)->is_active_now();
        $rules($closed['active'] === false && $closed['code'] === 'after_end', 'Окончание приёма не блокирует гостя');
        $rules(str_contains($closed['reason'], 'Приём ответов закрыт'), 'Причина не объясняет, что приём закрыт');

        // Администратор: доступ есть, но обход помечен и объяснён.
        wp_set_current_user(1);
        $admin = Quiz::get($id)->is_active_now();
        $rules($admin['active'] === true, 'Организатор потерял доступ к предпросмотру');
        $rules($admin['admin_preview'] === true, 'Обход регламента для администратора не помечен');
        $rules(str_contains((string)($admin['blocked_reason'] ?? ''), 'Приём ответов закрыт'), 'Организатору не показана причина блокировки участника');
        $rules(Quiz::get($id)->is_active_now(true)['active'] === false, 'Строгий режим не игнорирует обход');
        $rules(Quiz::is_participant_preview() === false, 'Флаг предпросмотра без GET-параметра взведён');

        $_GET['wpo_as_participant'] = '1';
        $rules(Quiz::is_participant_preview() === true, 'Ссылка «глазами участника» не распознана для администратора');
        $rules(Quiz::get($id)->is_active_now(Quiz::is_participant_preview())['active'] === false, 'Предпросмотр глазами участника не применяет регламент');
        unset($_GET['wpo_as_participant']);

        wp_set_current_user(0);
        $_GET['wpo_as_participant'] = '1';
        $rules(Quiz::is_participant_preview() === false, 'Флаг предпросмотра действует для не-администратора');
        unset($_GET['wpo_as_participant']);

        // Строгий режим настройкой — обхода нет ни у кого.
        Quiz::get($id)->update(['settings' => ['rules_strict_for_admins' => 1]]);
        wp_set_current_user(1);
        $strict_admin = Quiz::get($id)->is_active_now();
        $rules($strict_admin['active'] === false && $strict_admin['code'] === 'after_end', 'Строгий регламент не действует на администратора');
        wp_set_current_user(0);

        // Публикация важнее расписания: неопубликованная олимпиада закрыта и без дат.
        Quiz::get($id)->update(['settings' => ['rules_strict_for_admins' => 0, 'start_time' => '', 'end_time' => ''], 'status' => 'draft']);
        $draft = Quiz::get($id)->is_active_now();
        $rules($draft['active'] === false && $draft['code'] === 'not_published', 'Черновик не закрыт для участника');
        wp_set_current_user(1);
        $rules(Quiz::get($id)->is_active_now()['admin_preview'] === true, 'Организатор не видит черновик с пометкой');
        unset($draft);
        return true;
    } finally {
        wp_set_current_user($previous_user);
        unset($_GET['wpo_as_participant']);
        Quiz::delete($id);
    }
});

$it('Расписание: начатая до окончания попытка сдаётся, а не аннулируется', function () use ($rules): bool {
    $previous_user = get_current_user_id();
    wp_set_current_user(0);
    $token = strtolower(wp_generate_password(8, false, false));
    $tz = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
    $now = new \DateTimeImmutable('now', $tz);

    $id = Quiz::create([
        'title' => 'Rules submit ' . $token,
        'status' => 'publish',
        'settings' => [
            'time_limit' => 60,
            'end_time' => $now->modify('+30 minutes')->format('Y-m-d H:i'),
            'allow_guest' => 1,
        ],
    ]);
    try {
        $attempt = Attempt::start($id, ['name' => 'Участник ' . $token, 'email' => 'probe-' . $token . '@example.invalid']);
        $rules($attempt instanceof Attempt, 'Попытка не создана');

        // Участник начал писать пять минут назад, а приём ответов только что закрылся.
        global $wpdb;
        // Времена попыток хранятся в местном времени сайта — сравнивать надо в той же шкале.
        $local_started = date('Y-m-d H:i:s', current_time('timestamp') - 300);
        $wpdb->update(\WPOlympiad\DB::attempts_table(), ['started_at' => $local_started], ['id' => $attempt->id]);
        $attempt->started_at = $local_started;
        Quiz::get($id)->update(['settings' => ['end_time' => $now->modify('-1 minute')->format('Y-m-d H:i')]]);
        $quiz = Quiz::get($id);
        $rules($quiz->is_active_now()['active'] === false, 'Закрытое окно не считается закрытым');

        $after_close = AntiCheat::validate_submission_time($attempt, $quiz);
        $rules($after_close['valid'] === true, 'Начатая вовремя попытка отклонена после окончания приёма: ' . $after_close['error']);

        // Новый старт после окончания по-прежнему невозможен: попытка, начатая
        // уже после дедлайна (например, организатором), не получает снисхождения.
        $late_started = date('Y-m-d H:i:s', current_time('timestamp') + 3600);
        $wpdb->update(\WPOlympiad\DB::attempts_table(), ['started_at' => $late_started], ['id' => $attempt->id]);
        $attempt->started_at = $late_started;
        $late_start = AntiCheat::validate_submission_time($attempt, Quiz::get($id));
        $rules($late_start['valid'] === false, 'Попытка, начатая после дедлайна, принята');
        $back_to_now = date('Y-m-d H:i:s', current_time('timestamp'));
        $wpdb->update(\WPOlympiad\DB::attempts_table(), ['started_at' => $back_to_now], ['id' => $attempt->id]);
        $attempt->started_at = $back_to_now;

        // Снятие с публикации, пока участник писал, тоже не лишает написанного:
        // регламент закрывает старт, а не сдачу начатой работы.
        Quiz::get($id)->update(['status' => 'draft', 'settings' => ['end_time' => '', 'time_limit' => 0]]);
        $just_now = date('Y-m-d H:i:s', current_time('timestamp'));
        $wpdb->update(\WPOlympiad\DB::attempts_table(), ['started_at' => $just_now], ['id' => $attempt->id]);
        $attempt->started_at = $just_now;
        $unpublished = AntiCheat::validate_submission_time($attempt, Quiz::get($id));
        $rules($unpublished['valid'] === true, 'Снятие с публикации лишило написанного: ' . $unpublished['error']);

        // Собственный таймер участника работает независимо от окна и статуса.
        Quiz::get($id)->update(['status' => 'publish', 'settings' => ['time_limit' => 1]]);
        $over_limit = date('Y-m-d H:i:s', current_time('timestamp') - 600);
        $wpdb->update(\WPOlympiad\DB::attempts_table(), ['started_at' => $over_limit], ['id' => $attempt->id]);
        $attempt->started_at = $over_limit;
        $expired = AntiCheat::validate_submission_time($attempt, Quiz::get($id));
        $rules($expired['valid'] === false && str_contains($expired['error'], 'Время на выполнение'), 'Истёкший личный таймер не блокирует сдачу');
        return true;
    } finally {
        global $wpdb;
        $wpdb->delete(\WPOlympiad\DB::attempts_table(), ['quiz_id' => $id], ['%d']);
        wp_set_current_user($previous_user);
        Quiz::delete($id);
    }
});

$it('Регламент: лимит попыток для администратора виден и отключается строгим режимом', function () use ($rules): bool {
    $token = strtolower(wp_generate_password(8, false, false));
    $email = 'limit-' . $token . '@example.invalid';
    $previous_user = get_current_user_id();
    $id = Quiz::create([
        'title' => 'Rules attempts ' . $token,
        'status' => 'publish',
        'settings' => ['max_attempts' => 1, 'allow_guest' => 1],
    ]);
    try {
        wp_set_current_user(1);
        $attempt = Attempt::start($id, ['name' => 'Иван Ограничен', 'email' => $email]);
        $rules($attempt instanceof Attempt, 'Попытка для проверки лимита не создана');
        $attempt->complete(5.0, 10.0, 'completed');

        $guest = Quiz::get($id)->can_user_attempt(0, $email);
        $rules($guest['allowed'] === false && $guest['count'] === 1, 'Лимит попыток не сработал для участника');
        $rules($guest['admin_preview'] === false, 'Гостю приписан организаторский обход');
        $admin = Quiz::get($id)->can_user_attempt(1, $email);
        $rules($admin['allowed'] === true, 'Организатор не может открыть страницу сверх лимита');
        $rules($admin['admin_preview'] === true && $admin['count'] === 1, 'Обход лимита попыток не помечен и не показал счётчик');
        $rules(str_contains((string)($admin['blocked_reason'] ?? ''), 'лимит попыток'), 'Организатору не показана причина для участника');

        $strict = Quiz::get($id)->can_user_attempt(1, $email, true);
        $rules($strict['allowed'] === false, 'Строгий режим не отменил обход лимита попыток');

        Quiz::get($id)->update(['settings' => ['rules_strict_for_admins' => 1]]);
        $by_setting = Quiz::get($id)->can_user_attempt(1, $email);
        $rules($by_setting['allowed'] === false, 'Настройка строгого регламента не действует на лимит попыток');
        return true;
    } finally {
        wp_set_current_user($previous_user);
        Quiz::delete($id);
    }
});

$it('Регламент: расписание участникам по умолчанию скрыто и показывается по настройке', function () use ($rules): bool {
    $token = strtolower(wp_generate_password(8, false, false));
    $id = Quiz::create([
        'title' => 'Rules visible ' . $token,
        'status' => 'publish',
        'settings' => [
            'start_time' => '2026-11-01 09:30',
            'end_time' => Quiz::normalize_schedule_time('01.12.2026', true),
            'allow_guest' => 1,
            'show_meta_grid' => 1,
        ],
    ]);
    try {
        $quiz = Quiz::get($id);
        $rules($quiz->shows_schedule_to_participants() === false, 'Расписание участникам показано по умолчанию');

        $hidden = Shortcodes::render_quiz(['id' => $id]);
        $rules(!str_contains($hidden, 'Начало:'), 'Скрытое расписание всё равно выводится');
        $rules(!str_contains($hidden, 'Приём ответов до:'), 'Скрытое окончание приёма всё равно выводится');
        $rules(!str_contains($hidden, 'Часовой пояс:'), 'Скрытое расписание тянет за собой строку о поясе');

        $quiz->update(['settings' => ['show_schedule_to_participants' => 1]]);
        $shown = Shortcodes::render_quiz(['id' => $id]);
        $rules(str_contains($shown, 'Начало: 01.11.2026 09:30'), 'Стартовая страница не показывает время начала');
        $rules(str_contains($shown, 'Приём ответов до: 01.12.2026 23:59'), 'Стартовая страница не показывает окончание приёма');
        $rules(str_contains($shown, 'Часовой пояс:'), 'Время показано без часового пояса');
        return true;
    } finally {
        Quiz::delete($id);
    }
});

$it('Регламент: участник не получает точное время, организатор — получает', function () use ($rules): bool {
    $previous_user = get_current_user_id();
    wp_set_current_user(0);
    $token = strtolower(wp_generate_password(8, false, false));
    $tz = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
    $now = new \DateTimeImmutable('now', $tz);
    $id = Quiz::create([
        'title' => 'Rules silent ' . $token,
        'status' => 'publish',
        'settings' => [
            'start_time' => $now->modify('-2 hours')->format('Y-m-d H:i'),
            'end_time' => $now->modify('-10 minutes')->format('Y-m-d H:i'),
            'allow_guest' => 1,
            'show_meta_grid' => 1,
        ],
    ]);
    try {
        $quiz = Quiz::get($id);
        $status = $quiz->is_active_now();
        $rules($status['active'] === false && $status['code'] === 'after_end', 'Закрытое окно не распознано');
        $rules(!preg_match('/\d{1,2}:\d{2}|\d{2}\.\d{2}\.\d{4}/', $status['reason']), 'Участнику названо точное время: ' . $status['reason']);
        $rules($status['reason'] === 'Олимпиада завершена. Приём ответов закрыт.', 'Формулировка блокировки изменилась не так, как ожидается: ' . $status['reason']);

        $quiz->update(['settings' => ['start_time' => $now->modify('+2 hours')->format('Y-m-d H:i'), 'end_time' => '']]);
        $before = Quiz::get($id)->is_active_now();
        $rules($before['code'] === 'before_start' && !preg_match('/\d/', $before['reason']), 'До начала участнику названо время: ' . $before['reason']);

        // Расписание без значений — организатору тоже нечего показывать.
        Quiz::get($id)->update(['settings' => ['start_time' => '', 'end_time' => '']]);
        $rules(Quiz::get($id)->schedule_organizer_line() === '', 'Пустое расписание дало строку для организатора');

        // ...а когда оно задано, организатор видит время на странице, участник — нет.
        Quiz::get($id)->update(['settings' => ['start_time' => '2026-11-01 09:30', 'end_time' => '2026-12-01 18:00']]);
        $line = Quiz::get($id)->schedule_organizer_line();
        $rules(str_contains($line, 'начало 01.11.2026 09:30') && str_contains($line, 'приём ответов до 01.12.2026 18:00'), 'Строка для организатора неполная: ' . $line);

        $guest_html = Shortcodes::render_quiz(['id' => $id]);
        $rules(!str_contains($guest_html, 'Расписание сайта:'), 'Гостю показана строка расписания для организатора');

        wp_set_current_user(1);
        $admin_html = Shortcodes::render_quiz(['id' => $id]);
        $rules(str_contains($admin_html, 'Регламент к вам не применяется'), 'Организатору не показано объяснение обхода');
        $rules(str_contains($admin_html, 'Расписание сайта:'), 'Организатор не видит время на своей странице');
        return true;
    } finally {
        wp_set_current_user($previous_user);
        Quiz::delete($id);
    }
});

$it('Регламент: форма настроек объясняет часовой пояс, строгий режим и ошибку даты', function () use ($rules): bool {
    $token = strtolower(wp_generate_password(8, false, false));
    $id = Quiz::create([
        'title' => 'Rules form ' . $token,
        'status' => 'publish',
        'settings' => ['start_time' => '2026-10-15 10:00', 'end_time' => '2026-10-20 18:00', 'rules_strict_for_admins' => 1],
    ]);
    $render = static function ($quiz): string {
        ob_start();
        require WP_OLYMPIAD_PATH . 'admin/views/quiz-edit.php';
        return ob_get_clean();
    };

    $previous = $_GET['schedule_error'] ?? '__absent__';
    try {
        $html = $render(Quiz::get($id));
        $rules(str_contains($html, 'name="rules_strict_for_admins"'), 'Переключатель строгого регламента отсутствует');
        $checked = preg_match('/<input[^>]*name="rules_strict_for_admins"[^>]*checked[^>]*>/i', $html) === 1;
        $rules($checked, 'Строгий режим не отмечен по сохранённой настройке');
        $hidden_box = preg_match('/<input[^>]*name="show_schedule_to_participants"[^>]*>/i', $html) === 1;
        $rules($hidden_box, 'В форме нет переключателя показа расписания');
        $rules(!preg_match('/<input[^>]*name="show_schedule_to_participants"[^>]*checked[^>]*>/i', $html), 'Расписание участникам включено по умолчанию');
        $rules(str_contains($html, 'Расписание участникам:'), 'Новая настройка подписана нечеловеческим языком');
        $rules(str_contains($html, 'Начало олимпиады ('), 'Подпись поля начала не указывает часовой пояс');
        $rules(str_contains($html, 'Часовой пояс сайта:'), 'Форма не показывает часовой пояс сайта');
        $rules(!str_contains($html, 'Начало олимпиады (МСК):') || str_contains($html, 'Часовой пояс сайта: МСК'), 'Подпись обещает МСК без оговорки о поясе сайта');
        $rules(str_contains($html, '2026-10-15 10:00') && str_contains($html, '2026-10-20 18:00'), 'Сохранённое расписание не возвращается в форму');
        $rules(str_contains($html, 'Написанную работу это не аннулирует'), 'Подпись окончания не объясняет судьбу начатой попытки');
        $rules(strpos($html, 'notice notice-warning inline') === false, 'Предупреждение об ошибке даты показано без ошибки');

        $_GET['schedule_error'] = 'end_time';
        $with_error = $render(Quiz::get($id));
        $rules(str_contains($with_error, 'Значение поля «Окончание олимпиады» не распознано'), 'Форма не сообщает, какое поле не распознано');
        $rules(str_contains($with_error, 'прежнее время сохранено'), 'Форма не говорит, что прежнее значение сохранено');
        return true;
    } finally {
        if ($previous === '__absent__') {
            unset($_GET['schedule_error']);
        } else {
            $_GET['schedule_error'] = $previous;
        }
        Quiz::delete($id);
    }
});

$it('Сдача начатой работы не зависит от того, чем именно закрыли олимпиаду', function () use ($rules): bool {
    $previous_user = get_current_user_id();
    wp_set_current_user(0);
    $token = strtolower(wp_generate_password(8, false, false));
    $tz = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
    $now = new \DateTimeImmutable('now', $tz);
    $id = Quiz::create([
        'title' => 'Rules decline ' . $token,
        'status' => 'publish',
        'settings' => [
            'time_limit' => 0,
            'start_time' => $now->modify('-2 hours')->format('Y-m-d H:i'),
            'end_time' => $now->modify('+30 minutes')->format('Y-m-d H:i'),
            'allow_guest' => 1,
        ],
    ]);
    global $wpdb;
    try {
        $attempt = Attempt::start($id, ['name' => 'Участник ' . $token, 'email' => 'dec-' . $token . '@example.invalid']);
        $rules($attempt instanceof Attempt, 'Попытка не создана');

        // Пять минут назад участник начал, а теперь олимпиаду закрывают разными способами.
        $started = date('Y-m-d H:i:s', current_time('timestamp') - 300);
        $wpdb->update(\WPOlympiad\DB::attempts_table(), ['started_at' => $started], ['id' => $attempt->id]);
        $attempt->started_at = $started;

        Quiz::get($id)->update(['status' => 'draft']);
        $rules(Quiz::get($id)->is_active_now()['code'] === 'not_published', 'Снятие с публикации не распознано');
        $rules(AntiCheat::validate_submission_time($attempt, Quiz::get($id))['valid'] === true, 'Снятая с публикации олимпиада лишила написанное');

        Quiz::get($id)->update(['status' => 'publish', 'settings' => ['start_time' => $now->modify('+2 hours')->format('Y-m-d H:i')]]);
        $rules(Quiz::get($id)->is_active_now()['code'] === 'before_start', 'Передвинутым началом не тот код причины');
        $rules(AntiCheat::validate_submission_time($attempt, Quiz::get($id))['valid'] === true, 'Передвинутое начало лишило написанное');

        Quiz::get($id)->update(['settings' => [
            'start_time' => $now->modify('-2 hours')->format('Y-m-d H:i'),
            'end_time' => $now->modify('-1 minute')->format('Y-m-d H:i'),
        ]]);
        $rules(AntiCheat::validate_submission_time($attempt, Quiz::get($id))['valid'] === true, 'Окончание приёма лишило начатую работу');

        // Поздний старт (обход правил организатором) прощение не получает.
        $late = date('Y-m-d H:i:s', current_time('timestamp') + 3600);
        $wpdb->update(\WPOlympiad\DB::attempts_table(), ['started_at' => $late], ['id' => $attempt->id]);
        $attempt->started_at = $late;
        $late_val = AntiCheat::validate_submission_time($attempt, Quiz::get($id));
        $rules($late_val['valid'] === false && $late_val['error'] === 'Олимпиада завершена. Приём ответов закрыт.', 'Поздний старт принят как обычная сдача');

        // Личный лимит времени по-прежнему решает всё.
        $started = date('Y-m-d H:i:s', current_time('timestamp') - 600);
        $wpdb->update(\WPOlympiad\DB::attempts_table(), ['started_at' => $started], ['id' => $attempt->id]);
        $attempt->started_at = $started;
        Quiz::get($id)->update(['settings' => ['time_limit' => 1, 'end_time' => $now->modify('+30 minutes')->format('Y-m-d H:i')]]);
        $own = AntiCheat::validate_submission_time($attempt, Quiz::get($id));
        $rules($own['valid'] === false && str_contains($own['error'], 'Время на выполнение'), 'Личный лимит времени перестал блокировать сдачу');
        return true;
    } finally {
        $wpdb->delete(\WPOlympiad\DB::attempts_table(), ['quiz_id' => $id], ['%d']);
        wp_set_current_user($previous_user);
        Quiz::delete($id);
    }
});

$it('Отказ в сдаче сохраняет написанное организатору на проверку', function () use ($rules): bool {
    $previous_user = get_current_user_id();
    wp_set_current_user(0);
    $token = strtolower(wp_generate_password(8, false, false));
    $id = Quiz::create([
        'title' => 'Rules rescue ' . $token,
        'status' => 'publish',
        'settings' => ['time_limit' => 1, 'allow_guest' => 1, 'show_results_mode' => 'immediate'],
    ]);
    global $wpdb;
    try {
        $qid = \WPOlympiad\Question::create([
            'quiz_id' => $id,
            'question_type' => 'single',
            'title' => 'Сколько будет 2 + 2?',
            'points' => 3,
            'options' => ['choices' => [
                ['id' => 'c1', 'text' => '4', 'is_correct' => 1],
                ['id' => 'c2', 'text' => '5', 'is_correct' => 0],
            ]],
        ]);
        $attempt = Attempt::start($id, ['name' => 'Участник ' . $token, 'email' => 'res-' . $token . '@example.invalid']);
        $rules($attempt instanceof Attempt, 'Попытка не создана');

        // Личное время истекло: сдача отклоняется, но написанное должно сохраниться.
        $late = date('Y-m-d H:i:s', current_time('timestamp') - 900);
        $wpdb->update(\WPOlympiad\DB::attempts_table(), ['started_at' => $late], ['id' => $attempt->id]);

        $request = new \WP_REST_Request('POST', '/wp-olympiad/v1/submit');
        $request->set_param('token', $attempt->token);
        $request->set_param('answers', [$qid => 'c1']);
        $res = \WPOlympiad\RestApi::handle_submit($request);
        $rules(is_wp_error($res) && $res->get_error_code() === 'time_expired', 'Отказ в сдаче не вернулся ошибкой');
        if (is_wp_error($res)) {
            $rules(str_contains($res->get_error_message(), 'передано организатору на проверку'), 'Участнику не сказано, что написанное сохранено: ' . $res->get_error_message());
        }

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT user_answer, awarded_points, is_correct, needs_manual_review FROM ' . \WPOlympiad\DB::responses_table() . ' WHERE attempt_id = %d',
            (int)$attempt->id
        ), ARRAY_A);
        $rules(is_array($row), 'Ответ участника не сохранён при отказе');
        if (is_array($row)) {
            $rules((string)$row['user_answer'] === 'c1', 'Сохранён не тот ответ', (string)$row['user_answer']);
            // save_responses пишет is_correct через %s, поэтому NULL превращается в 0 —
            // важно, что баллов нет, а ответ помечен на ручную проверку.
            $rules((int)$row['awarded_points'] === 0, 'Отказ начислил баллы', (string)$row['awarded_points']);
            $rules((int)$row['needs_manual_review'] === 1, 'Ответ не помечен на ручную проверку');
        }

        $fresh = Attempt::get_by_token($attempt->token);
        $rules($fresh && $fresh->status === 'expired' && (float)$fresh->score === 0.0, 'Попытка не помечена истёкшей без баллов');
        return true;
    } finally {
        $wpdb->delete(\WPOlympiad\DB::responses_table(), ['attempt_id' => (int)($attempt->id ?? 0)], ['%d']);
        $wpdb->delete(\WPOlympiad\DB::attempts_table(), ['quiz_id' => $id], ['%d']);
        $wpdb->delete(\WPOlympiad\DB::questions_table(), ['quiz_id' => $id], ['%d']);
        wp_set_current_user($previous_user);
        Quiz::delete($id);
    }
});
