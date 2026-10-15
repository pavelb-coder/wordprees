<?php
declare(strict_types=1);

namespace WPOlympiad;

use WP_REST_Request;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Standard WordPress AJAX Handlers (Frontend fallback & Admin AJAX)
 */
final class Ajax
{
    public static function init(): void
    {
        // Public / Participant AJAX
        add_action('wp_ajax_wp_olympiad_start', [self::class, 'ajax_start']);
        add_action('wp_ajax_nopriv_wp_olympiad_start', [self::class, 'ajax_start']);

        add_action('wp_ajax_wp_olympiad_heartbeat', [self::class, 'ajax_heartbeat']);
        add_action('wp_ajax_nopriv_wp_olympiad_heartbeat', [self::class, 'ajax_heartbeat']);

        add_action('wp_ajax_wp_olympiad_submit', [self::class, 'ajax_submit']);
        add_action('wp_ajax_nopriv_wp_olympiad_submit', [self::class, 'ajax_submit']);

        // Admin AJAX
        add_action('wp_ajax_wp_olympiad_save_question', [self::class, 'ajax_save_question']);
        add_action('wp_ajax_wp_olympiad_delete_question', [self::class, 'ajax_delete_question']);
        add_action('wp_ajax_wp_olympiad_reorder_questions', [self::class, 'ajax_reorder_questions']);
        add_action('wp_ajax_wp_olympiad_save_manual_grade', [self::class, 'ajax_save_manual_grade']);
        add_action('wp_ajax_wp_olympiad_delete_attempt', [self::class, 'ajax_delete_attempt']);
        add_action('wp_ajax_wp_olympiad_delete_response', [self::class, 'ajax_delete_response']);
        add_action('wp_ajax_wp_olympiad_test_network_connection', [self::class, 'ajax_test_network_connection']);
        add_action('wp_ajax_wp_olympiad_export_to_network', [self::class, 'ajax_export_to_network']);
    }

    public static function ajax_start(): void
    {
        check_ajax_referer('wp_olympiad_nonce', 'nonce');

        $req = new WP_REST_Request('POST', '/wp-olympiad/v1/start');
        $req->set_param('quiz_id', (int)($_POST['quiz_id'] ?? 0));
        $req->set_param('participant_name', sanitize_text_field($_POST['participant_name'] ?? ''));
        $req->set_param('participant_email', sanitize_email($_POST['participant_email'] ?? ''));
        $req->set_param('participant_org', sanitize_text_field($_POST['participant_org'] ?? ''));

        $res = RestApi::handle_start($req);
        if (is_wp_error($res)) {
            wp_send_json_error(['message' => $res->get_error_message()], $res->get_error_data()['status'] ?? 400);
        }

        wp_send_json_success($res->get_data());
    }

    public static function ajax_heartbeat(): void
    {
        check_ajax_referer('wp_olympiad_nonce', 'nonce');

        $req = new WP_REST_Request('POST', '/wp-olympiad/v1/heartbeat');
        $req->set_param('token', sanitize_text_field($_POST['token'] ?? ''));
        $req->set_param('tab_switched', !empty($_POST['tab_switched']));

        $res = RestApi::handle_heartbeat($req);
        if (is_wp_error($res)) {
            wp_send_json_error(['message' => $res->get_error_message()], $res->get_error_data()['status'] ?? 400);
        }

        wp_send_json_success($res->get_data());
    }

    public static function ajax_submit(): void
    {
        check_ajax_referer('wp_olympiad_nonce', 'nonce');

        $req = new WP_REST_Request('POST', '/wp-olympiad/v1/submit');
        $req->set_param('token', sanitize_text_field($_POST['token'] ?? ''));

        $raw_answers = $_POST['answers'] ?? [];
        if (is_string($raw_answers)) {
            $raw_answers = json_decode(stripslashes($raw_answers), true) ?: [];
        }
        $req->set_param('answers', (array)$raw_answers);

        $res = RestApi::handle_submit($req);
        if (is_wp_error($res)) {
            wp_send_json_error(['message' => $res->get_error_message()], $res->get_error_data()['status'] ?? 400);
        }

        wp_send_json_success($res->get_data());
    }

    /* ---------------- Admin AJAX ---------------- */

    public static function ajax_save_question(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Доступ запрещён.', 'wp-olympiad')], 403);
        }
        check_ajax_referer('wp_olympiad_admin_nonce', 'nonce');

        $id = (int)($_POST['id'] ?? 0);
        $quiz_id = (int)($_POST['quiz_id'] ?? 0);
        $question_type = sanitize_key($_POST['question_type'] ?? 'single');
        $title = wp_kses_post($_POST['title'] ?? '');
        $content = wp_kses_post($_POST['content'] ?? '');
        $points = max(0, (int)round((float)($_POST['points'] ?? 1)));
        $penalty = max(0, (int)round((float)($_POST['penalty'] ?? 0)));
        $sort_order = (int)($_POST['sort_order'] ?? 0);

        $raw_options = $_POST['options'] ?? [];
        if (is_string($raw_options)) {
            $raw_options = json_decode(stripslashes($raw_options), true) ?: [];
        }

        $data = [
            'quiz_id'       => $quiz_id,
            'question_type' => $question_type,
            'title'         => $title,
            'content'       => $content,
            'points'        => $points,
            'penalty'       => $penalty,
            'options'       => $raw_options,
            'sort_order'    => $sort_order,
        ];

        if ($id > 0) {
            $question = Question::get($id);
            if (!$question) {
                wp_send_json_error(['message' => __('Вопрос не найден.', 'wp-olympiad')], 404);
            }
            $question->update($data);
            wp_send_json_success(['id' => $id, 'message' => __('Вопрос успешно обновлён.', 'wp-olympiad')]);
        } else {
            $new_id = Question::create($data);
            wp_send_json_success(['id' => $new_id, 'message' => __('Вопрос успешно добавлен.', 'wp-olympiad')]);
        }
    }

    public static function ajax_delete_question(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Доступ запрещён.', 'wp-olympiad')], 403);
        }
        check_ajax_referer('wp_olympiad_admin_nonce', 'nonce');

        $id = (int)($_POST['id'] ?? 0);
        Question::delete($id);
        wp_send_json_success(['message' => __('Вопрос удалён.', 'wp-olympiad')]);
    }

    public static function ajax_reorder_questions(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Доступ запрещён.', 'wp-olympiad')], 403);
        }
        check_ajax_referer('wp_olympiad_admin_nonce', 'nonce');

        $order = $_POST['order'] ?? [];
        if (is_array($order) && !empty($order)) {
            global $wpdb;
            $table = DB::questions_table();
            $wpdb->query('START TRANSACTION');
            try {
                foreach ($order as $index => $qid) {
                    $wpdb->update($table, ['sort_order' => (int)$index], ['id' => (int)$qid], ['%d'], ['%d']);
                }
                $wpdb->query('COMMIT');
            } catch (\Throwable $e) {
                $wpdb->query('ROLLBACK');
            }
        }

        wp_send_json_success(['message' => __('Порядок сохранён.', 'wp-olympiad')]);
    }

    public static function ajax_save_manual_grade(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Доступ запрещён.', 'wp-olympiad')], 403);
        }
        check_ajax_referer('wp_olympiad_admin_nonce', 'nonce');

        $attempt_id = (int)($_POST['attempt_id'] ?? 0);
        $attempt = Attempt::get($attempt_id);
        if (!$attempt) {
            wp_send_json_error(['message' => __('Попытка не найдена.', 'wp-olympiad')], 404);
        }

        $grades = $_POST['grades'] ?? [];
        if (is_string($grades)) {
            $grades = json_decode(stripslashes($grades), true) ?: [];
        }

        global $wpdb;
        $table = DB::responses_table();

        foreach ($grades as $resp_id => $item) {
            $awarded = max(0, (int)round((float)($item['points'] ?? 0)));
            $comment = sanitize_textarea_field($item['comment'] ?? '');
            $is_correct = !empty($item['is_correct']) ? 1 : 0;

            $wpdb->update($table, [
                'awarded_points'      => $awarded,
                'is_correct'          => $is_correct,
                'needs_manual_review' => 0,
                'review_comment'      => $comment,
            ], ['id' => (int)$resp_id, 'attempt_id' => $attempt_id], ['%d', '%d', '%d', '%s'], ['%d', '%d']);
        }

        // Recalculate total score
        $new_score = (int)round((float)$wpdb->get_var($wpdb->prepare(
            "SELECT SUM(awarded_points) FROM {$table} WHERE attempt_id = %d",
            $attempt_id
        )));

        $quiz = Quiz::get($attempt->quiz_id);
        $max_score = $quiz ? $quiz->get_total_points() : $attempt->max_score;
        $pass_pct = $quiz ? (float)$quiz->get_setting('pass_percentage', 60.0) : 60.0;
        $percentage = ($max_score > 0) ? round(($new_score / $max_score) * 100, 2) : 0.0;
        $passed = ($percentage >= $pass_pct);

        $attempt->update_manual_review($new_score, $max_score, $passed);

        wp_send_json_success([
            'message'    => __('Оценка и комментарии сохранены.', 'wp-olympiad'),
            'score'      => $new_score,
            'max_score'  => (int)round($max_score),
            'percentage' => $percentage,
            'passed'     => $passed,
        ]);
    }

    public static function ajax_delete_attempt(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Доступ запрещён.', 'wp-olympiad')], 403);
        }
        check_ajax_referer('wp_olympiad_admin_nonce', 'nonce');

        $attempt_id = (int)($_POST['attempt_id'] ?? 0);
        if ($attempt_id <= 0) {
            wp_send_json_error(['message' => __('Неверный ID попытки.', 'wp-olympiad')]);
        }

        $res = Attempt::delete($attempt_id);
        if ($res) {
            wp_send_json_success(['message' => __('Попытка и все ответы успешно удалены.', 'wp-olympiad')]);
        } else {
            wp_send_json_error(['message' => __('Не удалось удалить попытку.', 'wp-olympiad')]);
        }
    }

    public static function ajax_delete_response(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Доступ запрещён.', 'wp-olympiad')], 403);
        }
        check_ajax_referer('wp_olympiad_admin_nonce', 'nonce');

        $response_id = (int)($_POST['response_id'] ?? 0);
        if ($response_id <= 0) {
            wp_send_json_error(['message' => __('Неверный ID ответа.', 'wp-olympiad')]);
        }

        $res = Attempt::delete_response($response_id);
        if ($res) {
            wp_send_json_success(['message' => __('Ответ на задание успешно удалён.', 'wp-olympiad')]);
        } else {
            wp_send_json_error(['message' => __('Не удалось удалить ответ.', 'wp-olympiad')]);
        }
    }

    public static function ajax_test_network_connection(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Доступ запрещён.', 'wp-olympiad')], 403);
        }
        check_ajax_referer('wp_olympiad_admin_nonce', 'nonce');

        $config = [
            'type'              => sanitize_key($_POST['type'] ?? 'auto'),
            'path'              => sanitize_text_field($_POST['path'] ?? ''),
            'user'              => sanitize_text_field($_POST['user'] ?? ''),
            'password'          => (string)($_POST['password'] ?? ''),
            'protect_directory' => !empty($_POST['protect_directory']),
        ];

        $res = NetworkExport::test_connection($config);
        if ($res['success']) {
            wp_send_json_success(['message' => $res['message']]);
        } else {
            wp_send_json_error(['message' => $res['message']]);
        }
    }

    public static function ajax_export_to_network(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Доступ запрещён.', 'wp-olympiad')], 403);
        }
        check_ajax_referer('wp_olympiad_admin_nonce', 'nonce');

        $quiz_id = (int)($_POST['quiz_id'] ?? 0);
        if ($quiz_id <= 0) {
            wp_send_json_error(['message' => __('Не указан ID олимпиады.', 'wp-olympiad')]);
        }

        $override = [];
        if (!empty($_POST['override'])) {
            if (isset($_POST['path'])) $override['path'] = sanitize_text_field($_POST['path']);
            if (isset($_POST['type'])) $override['type'] = sanitize_key($_POST['type']);
            if (isset($_POST['user'])) $override['user'] = sanitize_text_field($_POST['user']);
            if (isset($_POST['password'])) $override['password'] = (string)$_POST['password'];
            if (isset($_POST['zip_password'])) $override['zip_password'] = (string)$_POST['zip_password'];
        }

        $res = NetworkExport::export_quiz($quiz_id, $override);
        if ($res['success']) {
            wp_send_json_success($res);
        } else {
            wp_send_json_error($res);
        }
    }
}
