<?php
/** Version 1.0.3 settings/mail regressions; loaded by the full WP-CLI suite. */
namespace WPOlympiad\Tests;
use WPOlympiad\Quiz;
use WPOlympiad\Question;
use WPOlympiad\Attempt;
use WPOlympiad\Mailer;
use WPOlympiad\RestApi;
use WP_REST_Request;
if (!defined('ABSPATH') || !isset($it)) return;
$settings_assert = static function (bool $ok, string $message): void { if (!$ok) throw new \RuntimeException($message); };

$it('Настройки / почта: новые флаги выключены, старый Mailer бездействует, wp_mail WordPress не заблокирован', function () use ($settings_assert): bool {
    $defaults = Quiz::default_settings();
    $settings_assert($defaults['email_participant'] === 0 && $defaults['email_admin'] === 0, 'New quiz mail flags are enabled');
    $id = Quiz::create(['title' => 'Legacy mail flags', 'settings' => ['email_participant' => 1, 'email_admin' => 1, 'admin_email_to' => 'organizer@example.invalid']]);
    $calls = []; $spy = static function ($pre, $args) use (&$calls) { $calls[] = $args; return true; };
    add_filter('pre_wp_mail', $spy, 1, 2);
    try {
        $quiz = Quiz::get($id);
        $settings_assert((int)$quiz->get_setting('email_participant') === 1 && (int)$quiz->get_setting('email_admin') === 1, 'Not actually testing legacy enabled flags');
        Mailer::send_attempt_emails($quiz, new Attempt(['participant_name' => 'Legacy', 'participant_email' => 'participant@example.invalid']));
        $settings_assert($calls === [], 'Compatibility dispatcher sent a notification');
        $settings_assert(wp_mail('system@example.invalid', 'Mock WordPress security mail', 'No external mail is sent by this test') === true, 'WordPress mail was globally disabled');
        return count($calls) === 1 && $calls[0]['subject'] === 'Mock WordPress security mail';
    } finally { remove_filter('pre_wp_mail', $spy, 1); Quiz::delete($id); }
});

$it('Настройки / интерфейс: email-блока нет, один checkbox перемешивания в регламенте, старое значение сохранено', function () use ($settings_assert): bool {
    if (!function_exists('submit_button')) require_once ABSPATH . 'wp-admin/includes/template.php';
    $id = Quiz::create(['title' => 'Relocated shuffle', 'settings' => ['shuffle_questions' => 1, 'email_participant' => 1, 'email_admin' => 1]]);
    $render = static function ($quiz): string { ob_start(); require WP_OLYMPIAD_PATH . 'admin/views/quiz-edit.php'; return ob_get_clean(); };
    try {
        foreach ([null, Quiz::get($id)] as $index => $quiz) {
            $html = $render($quiz); $previous = libxml_use_internal_errors(true); $doc = new \DOMDocument(); $doc->loadHTML('<?xml encoding="UTF-8">' . $html); libxml_clear_errors(); libxml_use_internal_errors($previous); $xp = new \DOMXPath($doc);
            $settings_assert(!str_contains($html, 'Email-оповещения'), 'Removed email card remains');
            $settings_assert(!str_contains($html, 'Рандомизация и отображение'), 'Retired randomization card remains');
            $settings_assert($xp->query('//input[@name="show_question_points"]')->length === 0, 'Retired points control remains');
            $settings_assert($xp->query('//input[@name="require_email"]')->length === 1 && $xp->query('//input[@name="require_org"]')->length === 1, 'Intro requirements are missing or duplicated');
            foreach (['email_participant', 'email_admin', 'admin_email_to'] as $name) $settings_assert($xp->query('//*[@name="' . $name . '"]')->length === 0, 'Removed mail input remains');
            $checks = $xp->query('//input[@name="shuffle_questions"]');
            $settings_assert($checks->length === 1 && $xp->query('//*[@id="wpo-rules-card"]//input[@name="shuffle_questions"]')->length === 1, 'Shuffle is missing, duplicated or outside regulations');
            $settings_assert($checks->item(0)->hasAttribute('checked') === ($index === 1), 'New/old shuffle value is not reflected');
            $settings_assert($xp->query('//input[@name="require_email"]')->length >= 1 && $xp->query('//input[@name="shuffle_answers"]')->length === 0, 'Questionnaire field missing or retired answer control still present');
        }
        return true;
    } finally { Quiz::delete($id); }
});

$it('Настройки / сдача: старые email-флаги не отправляют письма, оценка и контакт участника сохранены', function () use ($settings_assert): bool {
    $id = Quiz::create(['title' => 'Submit without mail', 'status' => 'publish', 'settings' => ['enable_intro_page' => 0, 'time_limit' => 0, 'max_attempts' => 0, 'email_participant' => 1, 'email_admin' => 1, 'admin_email_to' => 'organizer@example.invalid']]);
    $qid = Question::create(['quiz_id' => $id, 'question_type' => 'boolean', 'title' => '2 + 2 = 4', 'points' => 2, 'options' => ['choices' => [['id' => 'true', 'text' => 'Верно', 'is_correct' => 1], ['id' => 'false', 'text' => 'Неверно', 'is_correct' => 0]]]]);
    $calls = 0; $spy = static function ($pre, $args) use (&$calls) { $calls++; return true; }; add_filter('pre_wp_mail', $spy, 1, 2);
    try {
        $start = new WP_REST_Request('POST'); $start->set_param('quiz_id', $id); $start->set_param('participant_name', 'Проверка'); $start->set_param('participant_email', 'participant@example.invalid');
        $response = RestApi::handle_start($start); $settings_assert(!is_wp_error($response), 'Attempt cannot start'); $data = $response->get_data();
        $submit = new WP_REST_Request('POST'); $submit->set_param('token', $data['token']); $submit->set_param('answers', [$qid => 'true']);
        $result = RestApi::handle_submit($submit); $settings_assert(!is_wp_error($result), 'Submission failed'); $attempt = Attempt::get_by_token($data['token']);
        $settings_assert($calls === 0, 'Submission sent legacy notifications');
        return $attempt instanceof Attempt && $attempt->score === 2 && $attempt->participant_email === 'participant@example.invalid' && $attempt->status !== 'in_progress';
    } finally { remove_filter('pre_wp_mail', $spy, 1); Quiz::delete($id); }
});


$it('Удалённая карточка: частичное сохранение не сбрасывает параметры ответов и баллов, defaults сохранены', function () use ($settings_assert): bool {
    $defaults = Quiz::default_settings();
    $settings_assert($defaults['shuffle_answers'] === 1 && $defaults['show_question_points'] === 1, 'Existing defaults were changed');
    foreach ([[0, 0], [0, 1], [1, 0], [1, 1]] as [$answers, $points]) {
        $id = Quiz::create(['title' => 'Removed card settings preservation', 'settings' => ['shuffle_answers' => $answers, 'show_question_points' => $points]]);
        try {
            Quiz::get($id)->update(['title' => 'Renamed without retired inputs', 'settings' => ['shuffle_questions' => 1]]);
            $fresh = Quiz::get($id);
            $settings_assert((int)$fresh->get_setting('shuffle_answers') === $answers && (int)$fresh->get_setting('show_question_points') === $points, 'Retired card values reset by partial save');
            $settings_assert((int)$fresh->get_setting('shuffle_questions') === 1, 'Regulations shuffle no longer saves');
        } finally { Quiz::delete($id); }
    }
    return true;
});
