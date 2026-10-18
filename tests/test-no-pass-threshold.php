<?php
/** 1.0.7: score-only results, no pass threshold or binary exam classification. */
namespace WPOlympiad\Tests;
use WPOlympiad\Quiz;
use WPOlympiad\Question;
use WPOlympiad\Attempt;
use WPOlympiad\Grader;
use WPOlympiad\RestApi;
use WPOlympiad\Shortcodes;
use WPOlympiad\Export;
use WPOlympiad\NetworkExport;
use WPOlympiad\Webhook;
use WPOlympiad\DB;
use WP_REST_Request;
if (!defined('ABSPATH') || !isset($it)) return;
$threshold_assert = static function (bool $ok, string $message): void { if (!$ok) throw new \RuntimeException($message); };
$threshold_removed = ['pass_percentage', 'show_pass_pct', 'grading_scales', 'passed', 'grade_label', 'feedback_message'];
$threshold_no_keys = static function (array $data) use (&$threshold_no_keys, $threshold_removed): bool {
    foreach ($data as $key => $value) { if (in_array($key, $threshold_removed, true)) return false; if (is_array($value) && !$threshold_no_keys($value)) return false; } return true;
};
$threshold_fixture = static function (): array {
    $id = Quiz::create(['title' => 'Score-only fixture', 'status' => 'publish', 'settings' => ['allow_guest' => 1, 'max_attempts' => 0, 'time_limit' => 0, 'enable_intro_page' => 0, 'api_enabled' => 1]]);
    $qid = Question::create(['quiz_id' => $id, 'question_type' => 'single', 'title' => '2 + 2', 'points' => 2, 'options' => ['choices' => [['id' => 'a', 'text' => '4', 'is_correct' => 1], ['id' => 'b', 'text' => '5', 'is_correct' => 0]]]]);
    return [$id, $qid];
};
$threshold_submit = static function (int $id, int $qid, string $answer): array {
    $r = new WP_REST_Request('POST'); $r->set_param('quiz_id', $id); $r->set_param('participant_name', 'Score-only participant'); $r->set_param('participant_email', 'score@example.invalid');
    $s = RestApi::handle_start($r); if (is_wp_error($s)) throw new \RuntimeException('Cannot start score-only fixture'); $start = $s->get_data();
    $r = new WP_REST_Request('POST'); $r->set_param('token', $start['token']); $r->set_param('answers', [$qid => $answer]);
    $out = RestApi::handle_submit($r); if (is_wp_error($out)) throw new \RuntimeException('Cannot submit score-only fixture');
    return [$out->get_data(), Attempt::get_by_token($start['token'])];
};

$it('Без проходного процента: нет настройки/плашки/формы, старые значения не возвращаются через модель', function () use ($threshold_assert, $threshold_fixture): bool {
    [$id, $qid] = $threshold_fixture();
    try {
        $defaults = Quiz::default_settings();
        foreach (['pass_percentage', 'show_pass_pct', 'grading_scales'] as $key) $threshold_assert(!array_key_exists($key, $defaults), 'Retired default remains');
        Quiz::get($id)->update_patch(['pass_percentage' => 99, 'show_pass_pct' => 1, 'grading_scales' => [['min' => 0, 'max' => 100, 'label' => 'Old classification']]]);
        $quiz = Quiz::get($id); foreach (['pass_percentage', 'show_pass_pct', 'grading_scales'] as $key) $threshold_assert(!array_key_exists($key, $quiz->settings), 'Model restored a threshold');
        $render = static function ($quiz): string { ob_start(); require WP_OLYMPIAD_PATH . 'admin/views/quiz-edit.php'; return ob_get_clean(); };
        $form = $render($quiz); $page = Shortcodes::render_quiz(['id' => $id]);
        foreach (['name="pass_percentage"', 'name="show_pass_pct"', 'Проходной'] as $word) $threshold_assert(!str_contains($form, $word) && !str_contains($page, $word), 'Threshold still displayed');
        return true;
    } finally { Quiz::delete($id); }
});

$it('Без проходного процента: 0% и 100% дают нейтральное completed, REST/API не содержат passed и классификаций', function () use ($threshold_assert, $threshold_fixture, $threshold_submit, $threshold_no_keys): bool {
    [$id, $qid] = $threshold_fixture();
    try {
        foreach (['b' => 0, 'a' => 100] as $answer => $expected) {
            [$data, $attempt] = $threshold_submit($id, $qid, $answer);
            $threshold_assert($data['status'] === 'completed' && (int)$data['percentage'] === $expected && $threshold_no_keys($data), 'Result is classified or score/percentage lost');
            $threshold_assert(!property_exists($attempt, 'passed') && $attempt->get_status_label() === 'Завершено', 'Attempt keeps a pass flag');
            $key = Quiz::get($id)->generate_api_key(); $r = new WP_REST_Request('GET'); $r->set_header('X-API-Key', $key); $r->set_param('quiz_id', $id); $r->set_param('ref', (string)$attempt->id);
            $threshold_assert(RestApi::api_permission($r) === true, 'API fixture not authorized');
            $result = RestApi::external_result($r); $threshold_assert(!is_wp_error($result) && $threshold_no_keys($result->get_data()), 'External result leaks a retired field');
            $r->set_param('id', $id); $quiz_data = RestApi::external_quiz($r); $threshold_assert(!is_wp_error($quiz_data) && $threshold_no_keys($quiz_data->get_data()), 'External quiz exposes threshold settings');
        }
        return true;
    } finally { RestApi::api_permission(new WP_REST_Request('GET')); Quiz::delete($id); }
});

$it('Без проходного процента: ручные баллы и удаление ответа пересчитывают только числовой итог/статус проверки', function () use ($threshold_assert, $threshold_fixture): bool {
    global $wpdb;
    [$id, $qid] = $threshold_fixture();
    $essay1 = Question::create(['quiz_id' => $id, 'question_type' => 'essay', 'title' => 'Manual 1', 'points' => 3]);
    $essay2 = Question::create(['quiz_id' => $id, 'question_type' => 'essay', 'title' => 'Manual 2', 'points' => 5]);
    try {
        $attempt = Attempt::start($id, ['name' => 'Manual score-only']); $grade = Grader::grade(Quiz::get($id), $attempt, [$qid => 'a', $essay1 => 'Text', $essay2 => 'Text']); Grader::save_responses($attempt->id, $grade['responses']); $attempt->complete($grade['total_score'], $grade['max_score'], $grade['status']);
        $threshold_assert($attempt->status === 'needs_review', 'Manual work prematurely completed');
        $map = []; foreach ($attempt->get_responses() as $r) $map[(int)$r['question_id']] = $r;
        $wpdb->update(DB::responses_table(), ['awarded_points' => 2, 'needs_manual_review' => 0], ['id' => $map[$essay1]['id']]);
        $attempt->update_manual_review(4, 10, 'needs_review'); $threshold_assert($attempt->percentage === 40.0 && $attempt->status === 'needs_review', 'Partial review lost numeric score or pending status');
        Attempt::delete_response((int)$map[$essay2]['id']); $fresh = Attempt::get($attempt->id);
        return $fresh->score === 4 && $fresh->percentage === 40.0 && $fresh->status === 'completed' && !property_exists($fresh, 'passed');
    } finally { Quiz::delete($id); }
});

$it('Без проходного процента: CSV и сетевой манифест содержат баллы/процент, но не зачёт или долю прошедших', function () use ($threshold_assert, $threshold_fixture, $threshold_submit): bool {
    [$id, $qid] = $threshold_fixture(); $root = sys_get_temp_dir() . '/wpo_no_threshold_' . wp_generate_password(10, false, false);
    try {
        [, $attempt] = $threshold_submit($id, $qid, 'b');
        $csv = Export::export_quiz_attempts($id, false); $single = Export::export_single_attempt($attempt->id, false);
        $threshold_assert(str_contains($csv, 'Процент (%)') && str_contains($single, 'Статус работы') && str_contains($single, 'Завершено'), 'Numerical/workflow report missing');
        foreach (['Зачёт', 'зачёт', 'Не сдан', 'Пройдено', 'Проходной'] as $text) $threshold_assert(!str_contains($csv, $text) && !str_contains($single, $text), 'Classification leaked into CSV');
        $result = NetworkExport::export_quiz($id, ['type' => 'local', 'path' => $root]); $threshold_assert(!empty($result['success']), 'Network export failed');
        foreach (glob($root . '/отчёты/*.txt') as $file) {
            $text = (string)file_get_contents($file); $threshold_assert(!str_contains($text, 'Успешно сдали') && !str_contains($text, 'зачёт'), 'Manifest contains a pass rate');
        }
        return true;
    } finally { Quiz::delete($id); exec('rm -rf ' . escapeshellarg($root)); }
});

$it('Без проходного процента: webhook/test/старый retry очищены до подписи, HMAC сохраняется', function () use ($threshold_assert, $threshold_fixture, $threshold_submit, $threshold_no_keys): bool {
    [$id, $qid] = $threshold_fixture(); $captured = null;
    $mock = static function ($pre, $args, $url) use (&$captured) {
        if (!str_contains($url, 'no-threshold.invalid')) return $pre;
        $captured = $args; return ['headers' => [], 'body' => '{}', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []];
    };
    try {
        [, $attempt] = $threshold_submit($id, $qid, 'a');
        $quiz = Quiz::get($id); $quiz->update_setting('webhook_url', 'https://no-threshold.invalid/hook'); $quiz = Quiz::get($id);
        $payload = Webhook::build_payload($quiz, $attempt); $threshold_assert($threshold_no_keys($payload), 'New webhook has retired metadata');
        $payload['attempt']['passed'] = true; $payload['attempt']['grade_label'] = 'Legacy'; $payload['attempt']['status'] = 'passed'; $payload['quiz']['pass_percentage'] = 60;
        add_filter('pre_http_request', $mock, 10, 3); $sent = Webhook::deliver_event($id, $payload, $attempt->id);
        $body = json_decode($captured['body'], true); $threshold_assert($sent['ok'] && $threshold_no_keys($body) && $body['attempt']['status'] === 'completed', 'Legacy queued payload still classified');
        $secret = Quiz::get($id)->get_webhook_secret(); $signature = Webhook::sign($secret, (int)$captured['headers']['X-WPO-Timestamp'], $captured['body']);
        $threshold_assert($signature === $captured['headers']['X-WPO-Signature'], 'Payload cleanup invalidated HMAC');
        $test = Webhook::send_test(Quiz::get($id));
        $threshold_assert($test['ok'] && $threshold_no_keys(json_decode($captured['body'], true)), 'Test webhook still has a pass flag');
        return true;
    } finally { remove_filter('pre_http_request', $mock, 10); Quiz::delete($id); }
});

$it('Без проходного процента: миграция убирает старый столбец/ключи, но сохраняет ответы, оценки и контакты', function () use ($threshold_assert, $threshold_fixture, $threshold_submit): bool {
    global $wpdb;
    [$id, $qid] = $threshold_fixture();
    try {
        [, $attempt] = $threshold_submit($id, $qid, 'a'); $answers = $attempt->get_responses(); $score = $attempt->score; $percentage = $attempt->percentage;
        $table = DB::attempts_table(); $wpdb->query("ALTER TABLE {$table} ADD COLUMN passed tinyint(1) NOT NULL DEFAULT 0");
        $wpdb->update($table, ['passed' => 1, 'status' => 'passed'], ['id' => $attempt->id]);
        $settings = Quiz::get($id)->settings; $settings['pass_percentage'] = 99; $settings['show_pass_pct'] = 1; $settings['grading_scales'] = [['label' => 'Legacy award']]; $settings['retained_custom_setting'] = 'keep me';
        $wpdb->update(DB::quizzes_table(), ['settings' => wp_json_encode($settings)], ['id' => $id]);
        delete_option('wpo_threshold_cleanup_cursor'); DB::retire_threshold_data();
        $threshold_assert($wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'passed'") === null, 'Old result column remains');
        $fresh = Attempt::get($attempt->id); $threshold_assert($fresh->score === $score && $fresh->percentage === $percentage && $fresh->get_responses() === $answers && $fresh->status === 'completed', 'Migration damaged attempt data');
        $stored = json_decode($wpdb->get_var($wpdb->prepare('SELECT settings FROM ' . DB::quizzes_table() . ' WHERE id=%d', $id)), true);
        return !array_key_exists('pass_percentage', $stored) && !array_key_exists('show_pass_pct', $stored) && !array_key_exists('grading_scales', $stored) && $stored['retained_custom_setting'] === 'keep me' && $fresh->participant_email === 'score@example.invalid';
    } finally { Quiz::delete($id); }
});

$it('Без проходного процента: CAS-миграция не затирает параллельные настройки и продолжает очистку', function () use ($threshold_assert, $threshold_fixture): bool {
    global $wpdb;
    [$id, $qid] = $threshold_fixture(); $table = DB::quizzes_table(); $fired = false; $inside = false;
    $raw = Quiz::get($id)->settings; $raw['pass_percentage'] = 87; $raw['retained_concurrent_value'] = 'before';
    $wpdb->update($table, ['settings' => wp_json_encode($raw)], ['id' => $id]);
    $race = static function ($sql) use (&$fired, &$inside, $id, $table, $raw, $wpdb) {
        if (!$inside && !$fired && str_starts_with($sql, "UPDATE {$table} SET settings =") && str_contains($sql, "WHERE id = {$id} AND BINARY settings")) {
            $inside = true; $fresh = $raw; $fresh['retained_concurrent_value'] = 'newer writer';
            $wpdb->update($table, ['settings' => wp_json_encode($fresh)], ['id' => $id]);
            $fired = true; $inside = false;
        }
        return $sql;
    };
    try {
        delete_option('wpo_threshold_cleanup_cursor'); add_filter('query', $race);
        DB::cleanup_threshold_settings(); remove_filter('query', $race);
        $threshold_assert($fired && get_option('wpo_threshold_cleanup_cursor', false) !== false, 'CAS conflict did not retain a continuation');
        DB::cleanup_threshold_settings();
        $stored = json_decode($wpdb->get_var($wpdb->prepare("SELECT settings FROM {$table} WHERE id=%d", $id)), true);
        return !isset($stored['pass_percentage']) && $stored['retained_concurrent_value'] === 'newer writer' && get_option('wpo_threshold_cleanup_cursor', false) === false;
    } finally { remove_filter('query', $race); Quiz::delete($id); }
});
