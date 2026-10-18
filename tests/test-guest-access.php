<?php
/** Guest participation/access-mode regression tests, included by test-suite.php. */
namespace WPOlympiad\Tests;
use WPOlympiad\Quiz;
use WPOlympiad\Question;
use WPOlympiad\Attempt;
use WPOlympiad\Shortcodes;
use WPOlympiad\RestApi;
use WP_REST_Request;
if (!defined('ABSPATH') || !isset($it)) return;
$guest_assert = static function (bool $ok, string $message): void { if (!$ok) throw new \RuntimeException($message); };
$guest_question = static function (int $id): int { return Question::create(['quiz_id' => $id, 'question_type' => 'single', 'title' => 'Открытое участие', 'points' => 2, 'options' => ['choices' => [['id' => 'a', 'text' => 'Да', 'is_correct' => 1], ['id' => 'b', 'text' => 'Нет', 'is_correct' => 0]]]]); };
$guest_request = static function (int $id): WP_REST_Request { $r = new WP_REST_Request('POST'); $r->set_param('quiz_id', $id); $r->set_param('participant_name', 'Участник без учётной записи'); $r->set_param('participant_email', 'guest@example.invalid'); $r->set_param('participant_org', 'Тестовый класс'); return $r; };

$it('Гостевой доступ: отсутствующий checkbox не означает запрет, явное переключение и старые значения сохраняются', function () use ($guest_assert): bool {
    $guest_assert(Quiz::default_settings()['allow_guest'] === 1, 'New quizzes should allow guest participation by default');
    $guest_assert(Quiz::guest_access_patch([]) === [] && Quiz::guest_access_patch(['title' => 'Cached form']) === [], 'Missing control became false');
    $guest_assert(Quiz::guest_access_patch(['wpo_guest_access_present' => '1', 'allow_guest' => '1']) === ['allow_guest' => 1], 'Checked guest control not enabled');
    $guest_assert(Quiz::guest_access_patch(['wpo_guest_access_present' => '1']) === ['allow_guest' => 0], 'Explicit unchecked guest control not disabled');
    $guest_assert(Quiz::guest_access_patch(['allow_guest' => '0']) === ['allow_guest' => 0], 'Explicit legacy false not supported');
    $guest_assert(Quiz::guest_access_patch(['allow_guest' => ['unexpected']]) === [] && Quiz::guest_access_patch(['allow_guest' => 'garbage']) === [], 'Malformed guest input changes access');
    foreach ([0, 1] as $value) {
        $id = Quiz::create(['title' => 'Guest cached form preservation', 'settings' => ['allow_guest' => $value]]);
        try {
            Quiz::get($id)->update(['title' => 'Other fields changed', 'settings' => array_merge(['time_limit' => 12], Quiz::guest_access_patch([]))]);
            $guest_assert((int)Quiz::get($id)->get_setting('allow_guest') === $value, 'Partial form changed access mode');
        } finally { Quiz::delete($id); }
    }
    return true;
});

$it('Гостевой доступ: явный переключатель в регламенте, открытый shortcode без логина, закрытый режим сохранён', function () use ($guest_assert, $guest_question): bool {
    $old = get_current_user_id(); wp_set_current_user(0);
    if (!function_exists('submit_button')) require_once ABSPATH . 'wp-admin/includes/template.php';
    $id = Quiz::create(['title' => 'Guest rendered access', 'status' => 'publish', 'settings' => ['allow_guest' => 1, 'max_attempts' => 0]]); $guest_question($id);
    $render = static function ($quiz): string { ob_start(); require WP_OLYMPIAD_PATH . 'admin/views/quiz-edit.php'; return ob_get_clean(); };
    try {
        foreach ([1, 0] as $allowed) {
            Quiz::get($id)->update_setting('allow_guest', $allowed); $quiz = Quiz::get($id); $html = $render($quiz);
            $previous = libxml_use_internal_errors(true); $doc = new \DOMDocument(); $doc->loadHTML('<?xml encoding="UTF-8">' . $html); libxml_clear_errors(); libxml_use_internal_errors($previous); $xp = new \DOMXPath($doc);
            $controls = $xp->query('//*[@id="wpo-rules-card"]//input[@name="allow_guest"]');
            $guest_assert($controls->length === 1 && $controls->item(0)->hasAttribute('checked') === (bool)$allowed, 'Guest access checkbox is missing, duplicated or incorrect');
            $guest_assert($xp->query('//input[@name="wpo_guest_access_present" and @value="1"]')->length === 1, 'Guest control presence marker missing');
            $page = Shortcodes::render_quiz(['id' => $id]);
            $guest_assert(str_contains($page, 'wpo-start-form') === (bool)$allowed, 'Page access does not match stored mode');
            $guest_assert(str_contains($page, 'необходимо авторизоваться') === !$allowed, 'Wrong login requirement on page');
        }
        return true;
    } finally { wp_set_current_user($old); Quiz::delete($id); }
});

$it('Гостевой доступ / REST: запрет нельзя обойти параметром, авторизованные участники и валидный API работают', function () use ($guest_assert, $guest_question, $guest_request): bool {
    $old = get_current_user_id(); wp_set_current_user(0);
    $id = Quiz::create(['title' => 'Private mode REST protection', 'status' => 'publish', 'settings' => ['allow_guest' => 0, 'api_enabled' => 1, 'max_attempts' => 0, 'time_limit' => 0]]); $guest_question($id);
    try {
        $r = $guest_request($id); foreach (['allow_guest', 'authenticated_integration', 'user_id'] as $name) $r->set_param($name, 1);
        $blocked = RestApi::handle_start($r); $guest_assert(is_wp_error($blocked) && $blocked->get_error_code() === 'login_required' && $blocked->get_error_data()['status'] === 401, 'Public REST bypasses private mode');
        $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']); wp_set_current_user((int)$admins[0]);
        $guest_assert(!is_wp_error(RestApi::handle_start($guest_request($id))), 'Logged-in participant is rejected'); wp_set_current_user(0);
        $quiz = Quiz::get($id); $key = $quiz->generate_api_key(); $api = $guest_request($id); $api->set_header('X-API-Key', $key);
        $guest_assert(RestApi::api_permission($api) === true, 'Valid per-quiz API key rejected');
        $guest_assert(!is_wp_error(RestApi::external_start($api)), 'Authenticated external integration broken by guest restriction');
        $still_blocked = RestApi::handle_start($r); $guest_assert(is_wp_error($still_blocked), 'API context leaked into public start');
        $bad = $guest_request($id); $guest_assert(is_wp_error(RestApi::api_permission($bad)), 'Anonymous external API allowed');
        return true;
    } finally { RestApi::api_permission(new WP_REST_Request('POST')); wp_set_current_user($old); Quiz::delete($id); }
});

$it('Гостевой доступ: полноценная сдача без аккаунта с анкетой и оценкой, публикация/регламент не обходятся', function () use ($guest_assert, $guest_question, $guest_request): bool {
    $old = get_current_user_id(); wp_set_current_user(0);
    $id = Quiz::create(['title' => 'Guest participation flow', 'status' => 'publish', 'settings' => ['allow_guest' => 1, 'max_attempts' => 0, 'time_limit' => 0]]); $qid = $guest_question($id);
    try {
        $start = RestApi::handle_start($guest_request($id)); $guest_assert(!is_wp_error($start), 'Guest cannot start'); $data = $start->get_data();
        $submit = new WP_REST_Request('POST'); $submit->set_param('token', $data['token']); $submit->set_param('answers', [$qid => 'a']);
        $result = RestApi::handle_submit($submit); $guest_assert(!is_wp_error($result), 'Guest cannot submit'); $attempt = Attempt::get_by_token($data['token']);
        $guest_assert($attempt->user_id === 0 && $attempt->score === 2 && $attempt->participant_email === 'guest@example.invalid', 'Guest identity/score/contact not saved');
        Quiz::get($id)->update(['status' => 'draft']); $guest_assert(is_wp_error(RestApi::handle_start($guest_request($id))), 'Guest flag opens a draft');
        Quiz::get($id)->update(['status' => 'publish', 'settings' => ['start_time' => '2099-01-01 12:00']]);
        $guest_assert(is_wp_error(RestApi::handle_start($guest_request($id))), 'Guest flag bypasses scheduling');
        return true;
    } finally { wp_set_current_user($old); Quiz::delete($id); }
});
