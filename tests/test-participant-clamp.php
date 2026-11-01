<?php
/**
 * 1.0.26: возвращённый из 1.0.23 фикс — усечение значений анкеты по ширине колонок.
 *
 * Страница участника не задаёт maxlength, а колонки attempts узкие: на строгом
 * режиме SQL слишком длинное значение превращало старт в HTTP 500, а аварийная
 * ветка INSERT молча теряла телефон и город.
 */

namespace WPOlympiad\Tests;

use WPOlympiad\Attempt;
use WPOlympiad\DB;
use WPOlympiad\Question;
use WPOlympiad\Quiz;

if (!defined('ABSPATH') || !isset($it)) return;

$qq = static function (bool $ok, string $message, string $extra = ''): void {
    if (!$ok) {
        throw new \RuntimeException($extra !== '' ? $message . ' — ' . $extra : $message);
    }
};

$it('Анкета: значения длиннее колонки усекаются, старт не падает и не теряет поля', function () use ($qq): bool {
    global $wpdb;
    $table = DB::attempts_table();
    $token = substr(md5(uniqid('clamp', true)), 0, 8);
    $email = 'cl-' . $token . '@example.invalid';

    $quiz_id = (int)Quiz::create([
        'title'    => 'Усечение полей ' . $token,
        'status'   => 'publish',
        'settings' => ['max_attempts' => 0, 'time_limit' => 0, 'require_org' => 0, 'enable_intro_page' => 1],
    ]);
    Question::create([
        'quiz_id'       => $quiz_id,
        'question_type' => 'single',
        'title'         => 'В1',
        'points'        => 1,
    ]);

    try {
        $attempt = Attempt::start($quiz_id, [
            'name'  => str_repeat('И', 5000),
            'email' => str_repeat('a', 300) . '@' . str_repeat('b', 290) . '.invalid',
            'org'   => str_repeat('Ш', 3000),
            'phone' => str_repeat('9', 300),
            'city'  => str_repeat('М', 400),
        ]);
        $qq($attempt instanceof Attempt, 'Попытка не создана: старт на длинных полях упал');

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", (int)$attempt->id), ARRAY_A);
        $qq(is_array($row), 'Строка попытки не читается');

        $qq(mb_strlen((string)$row['participant_name'], 'UTF-8') === 255, 'ФИО не усечено до 255 символов', (string)mb_strlen((string)$row['participant_name'], 'UTF-8'));
        $qq(mb_strlen((string)$row['participant_org'], 'UTF-8') === 255, 'Организация не усечена до 255 символов');
        $qq(mb_strlen((string)$row['participant_email'], 'UTF-8') === 255, 'E-mail не усечён до 255 символов');
        $qq((string)$row['participant_name'] === str_repeat('И', 255), 'Усечение исказило содержимое ФИО');
        // Главный смысл фикса: прошёл основной INSERT, а не аварийный без новых колонок.
        $qq(mb_strlen((string)$row['participant_phone'], 'UTF-8') === 100, 'Телефон не усечён до 100 символов');
        $qq(mb_strlen((string)$row['participant_city'], 'UTF-8') === 150, 'Город не усечён до 150 символов');
        $qq((string)$row['participant_phone'] !== '' && (string)$row['participant_city'] !== '',
            'Телефон или город потеряны — сработала аварийная ветка INSERT');
        $qq((string)$row['status'] === 'in_progress', 'Статус созданной попытки испорчен', (string)$row['status']);

        // Короткие значения не должны измениться.
        $short = Attempt::start($quiz_id, ['name' => 'Анна Иванова', 'email' => 'short-' . $token . '@example.invalid', 'org' => 'Школа №1', 'phone' => '+7 900 000-00-00', 'city' => 'Тверь']);
        $qq($short instanceof Attempt, 'Старт с короткими полями сломался');
        $srow = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", (int)$short->id), ARRAY_A);
        $qq((string)$srow['participant_name'] === 'Анна Иванова', 'Короткое ФИО испорчено');
        $qq((string)$srow['participant_city'] === 'Тверь', 'Короткий город испорчен');

        $wpdb->delete($wpdb->prefix . 'olympiad_responses', ['attempt_id' => (int)$attempt->id]);
        $wpdb->delete($wpdb->prefix . 'olympiad_responses', ['attempt_id' => (int)$short->id]);
        $wpdb->delete($table, ['id' => (int)$attempt->id]);
        $wpdb->delete($table, ['id' => (int)$short->id]);
    } catch (\Throwable $e) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE quiz_id = %d", $quiz_id));
        $wpdb->delete($wpdb->prefix . 'olympiad_questions', ['quiz_id' => $quiz_id]);
        $wpdb->delete($wpdb->prefix . 'olympiad_quizzes', ['id' => $quiz_id]);
        throw $e;
    }

    $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE quiz_id = %d", $quiz_id));
    $wpdb->delete($wpdb->prefix . 'olympiad_questions', ['quiz_id' => $quiz_id]);
    $wpdb->delete($wpdb->prefix . 'olympiad_quizzes', ['id' => $quiz_id]);

    return true;
});
