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

        // Собственный таймер участка работает независимо от окна.
        Quiz::get($id)->update(['settings' => ['end_time' => '', 'time_limit' => 1]]);
        $over_limit = date('Y-m-d H:i:s', current_time('timestamp') - 600);
        $wpdb->update(\WPOlympiad\DB::attempts_table(), ['started_at' => $over_limit], ['id' => $attempt->id]);
        $attempt->started_at = $over_limit;
        $expired = AntiCheat::validate_submission_time($attempt, Quiz::get($id));
        $rules($expired['valid'] === false && str_contains($expired['error'], 'Время на выполнение'), 'Истёкший личный таймер не блокирует сдачу');

        // Неопубликованная олимпиада не «прощает» сдачу, хотя время окончания не при чём.
        Quiz::get($id)->update(['status' => 'draft']);
        $unpublished = AntiCheat::validate_submission_time($attempt, Quiz::get($id));
        $rules($unpublished['valid'] === false, 'Сдача черновика разрешена');
        return true;
    } finally {
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

$it('Регламент: участник видит расписание на стартовой странице', function () use ($rules): bool {
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
        $html = Shortcodes::render_quiz(['id' => $id]);
        $rules(str_contains($html, 'Начало: 01.11.2026 09:30'), 'Стартовая страница не показывает время начала');
        $rules(str_contains($html, 'Приём ответов до: 01.12.2026 23:59'), 'Стартовая страница не показывает окончание приёма');
        return true;
    } finally {
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
