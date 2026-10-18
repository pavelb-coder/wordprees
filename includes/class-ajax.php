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
        add_action('wp_ajax_wp_olympiad_save_shared_texts', [self::class, 'ajax_save_shared_texts']);
        add_action('wp_ajax_wp_olympiad_scan_network_import', [self::class, 'ajax_scan_network_import']);
        add_action('wp_ajax_wp_olympiad_validate_import_file', [self::class, 'ajax_validate_import_file']);
        add_action('wp_ajax_wp_olympiad_import_from_network', [self::class, 'ajax_import_from_network']);
        add_action('wp_ajax_wp_olympiad_upload_import_file', [self::class, 'ajax_upload_import_file']);
        add_action('wp_ajax_wp_olympiad_test_network_connection', [self::class, 'ajax_test_network_connection']);
        add_action('wp_ajax_wp_olympiad_deploy_network_templates', [self::class, 'ajax_deploy_network_templates']);
        add_action('wp_ajax_wp_olympiad_export_questions_to_network', [self::class, 'ajax_export_questions_to_network']);
        add_action('wp_ajax_wp_olympiad_export_to_network', [self::class, 'ajax_export_to_network']);
        add_action('wp_ajax_wp_olympiad_run_network_import', [self::class, 'ajax_run_network_import']);
        add_action('wp_ajax_wp_olympiad_auto_check_answer', [self::class, 'ajax_auto_check_answer']);

        // Внешняя интеграция: ключ API и webhook
        add_action('wp_ajax_wp_olympiad_api_key', [self::class, 'ajax_api_key']);
        add_action('wp_ajax_wp_olympiad_webhook_action', [self::class, 'ajax_webhook_action']);
    }

    public static function ajax_start(): void
    {
        check_ajax_referer('wp_olympiad_nonce', 'nonce');

        $req = new WP_REST_Request('POST', '/wp-olympiad/v1/start');
        $req->set_param('quiz_id', (int)($_POST['quiz_id'] ?? 0));
        $req->set_param('participant_name', sanitize_text_field($_POST['participant_name'] ?? ''));
        $req->set_param('participant_email', sanitize_email($_POST['participant_email'] ?? ''));
        $req->set_param('participant_org', sanitize_text_field($_POST['participant_org'] ?? ''));
        $req->set_param('participant_phone', sanitize_text_field($_POST['participant_phone'] ?? ''));
        $req->set_param('participant_city', sanitize_text_field($_POST['participant_city'] ?? ''));
        $req->set_param('agreement', isset($_POST['agreement']) ? (int)$_POST['agreement'] : 1);

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

    public static function ajax_save_shared_texts(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Доступ запрещён.', 'wp-olympiad')], 403);
        }
        check_ajax_referer('wp_olympiad_admin_nonce', 'nonce');

        $quiz_id = (int)($_POST['quiz_id'] ?? 0);
        $quiz = Quiz::get($quiz_id);
        if (!$quiz) {
            wp_send_json_error(['message' => __('Олимпиада не найдена.', 'wp-olympiad')], 404);
        }

        $raw_texts = $_POST['shared_texts'] ?? [];
        if (is_string($raw_texts)) {
            $raw_texts = json_decode(stripslashes($raw_texts), true) ?: [];
        }

        $quiz->save_shared_texts((array)$raw_texts);

        // Optional assignments: mapping of [question_id => shared_text_id]
        if (!empty($_POST['assignments'])) {
            $raw_assign = $_POST['assignments'];
            if (is_string($raw_assign)) {
                $raw_assign = json_decode(stripslashes($raw_assign), true) ?: [];
            }
            if (is_array($raw_assign)) {
                foreach ($raw_assign as $qid => $st_id) {
                    $q = Question::get((int)$qid);
                    if ($q && $q->quiz_id === $quiz_id) {
                        $opts = $q->options;
                        $opts['shared_text_id'] = sanitize_key((string)$st_id);
                        $q->update(['options' => $opts]);
                    }
                }
            }
        }

        wp_send_json_success([
            'message'      => __('Общие тексты успешно сохранены.', 'wp-olympiad'),
            'shared_texts' => $quiz->get_shared_texts(),
        ]);
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
        $percentage = ($max_score > 0) ? round(($new_score / $max_score) * 100, 2) : 0.0;

        $pending = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE attempt_id = %d AND needs_manual_review = 1", $attempt_id));
        $attempt->update_manual_review($new_score, $max_score, $pending > 0 ? 'needs_review' : 'completed');

        wp_send_json_success([
            'message'    => __('Оценка и комментарии сохранены.', 'wp-olympiad'),
            'score'      => $new_score,
            'max_score'  => (int)round($max_score),
            'percentage' => $percentage,
            'status'     => $attempt->status,
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
            'type'     => sanitize_key($_POST['type'] ?? 'auto'),
            'path'     => NetworkExport::sanitize_path($_POST['path'] ?? ''),
            'user'     => sanitize_text_field(wp_unslash($_POST['user'] ?? '')),
            'password' => (string)($_POST['password'] ?? ''),
        ];

        $res = NetworkExport::test_connection($config);
        if ($res['success']) {
            wp_send_json_success(['message' => $res['message'], 'editor' => $res['editor'] ?? null]);
        } else {
            wp_send_json_error(['message' => $res['message']]);
        }
    }

    public static function ajax_deploy_network_templates(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Доступ запрещён.', 'wp-olympiad')], 403);
        }
        check_ajax_referer('wp_olympiad_admin_nonce', 'nonce');

        $quiz_id = (int)($_POST['quiz_id'] ?? 0);
        $quiz = $quiz_id > 0 ? Quiz::get($quiz_id) : null;
        $use_default = !empty($_POST['use_default']);

        $override = [];
        if (!$use_default && !empty($_POST['path'])) {
            $override['path'] = NetworkExport::sanitize_path($_POST['path']);
        } elseif ($use_default) {
            $override['path'] = NetworkExport::get_default_network_path();
            $override['type'] = 'local';
        }

        if (!$use_default && !empty($_POST['type']) && $_POST['type'] !== 'auto') {
            $override['type'] = sanitize_key($_POST['type']);
        }
        if (!empty($_POST['user'])) {
            $override['user'] = sanitize_text_field(wp_unslash($_POST['user']));
        }
        if (!empty($_POST['password'])) {
            $override['password'] = (string)$_POST['password'];
        }

        $config = $quiz ? NetworkExport::get_quiz_network_config($quiz, $override) : [
            'type'     => sanitize_key($_POST['type'] ?? 'auto'),
            'path'     => NetworkExport::sanitize_path($_POST['path'] ?? ''),
            'user'     => sanitize_text_field(wp_unslash($_POST['user'] ?? '')),
            'password' => (string)($_POST['password'] ?? ''),
        ];

        if (empty($config['path'])) {
            $config['path'] = NetworkExport::get_default_network_path();
        }

        $res = NetworkExport::deploy_import_templates_result($config);
        if ($res['success']) {
            wp_send_json_success([
                'message'      => $res['message'],
                'destination'  => $res['destination'] ?? '',
                'files'        => $res['files'] ?? [],
            ]);
        } else {
            wp_send_json_error([
                'message'      => $res['message'],
                'details'      => $res['details'] ?? '',
                'target_path'  => $config['path'],
                'default_path' => NetworkExport::get_default_network_path(),
            ]);
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
            if (isset($_POST['path'])) $override['path'] = NetworkExport::sanitize_path($_POST['path']);
            if (isset($_POST['type'])) $override['type'] = sanitize_key($_POST['type']);
            if (isset($_POST['user'])) $override['user'] = sanitize_text_field(wp_unslash($_POST['user']));
            if (isset($_POST['password'])) $override['password'] = (string)$_POST['password'];
        }

        $res = NetworkExport::export_quiz($quiz_id, $override);
        if ($res['success']) {
            wp_send_json_success($res);
        } else {
            wp_send_json_error($res);
        }
    }

    /**
     * Dry-run: check a question file (from the network folder or an uploaded file) and
     * return a row-by-row report without modifying the database.
     */
    public static function ajax_validate_import_file(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Доступ запрещён.', 'wp-olympiad')], 403);
        }
        check_ajax_referer('wp_olympiad_admin_nonce', 'nonce');

        $quiz_id = (int)($_POST['quiz_id'] ?? 0);
        $quiz = Quiz::get($quiz_id);
        if (!$quiz) {
            wp_send_json_error(['message' => __('Олимпиада не найдена.', 'wp-olympiad')], 404);
        }

        $override = [];
        if (!empty($_POST['path'])) {
            $override['path'] = NetworkExport::sanitize_path((string)$_POST['path']);
        }
        if (!empty($_POST['type'])) {
            $override['type'] = sanitize_key((string)$_POST['type']);
        }
        if (isset($_POST['user'])) {
            $override['user'] = sanitize_text_field(wp_unslash((string)$_POST['user']));
        }
        if (isset($_POST['password'])) {
            $override['password'] = (string)$_POST['password'];
        }

        // Uploaded file takes priority over the network folder.
        if (!empty($_FILES['import_file']['tmp_name']) && is_uploaded_file($_FILES['import_file']['tmp_name'])) {
            $content = (string)file_get_contents($_FILES['import_file']['tmp_name']);
            $name = sanitize_file_name((string)($_FILES['import_file']['name'] ?? 'upload.csv'));
            $analysis = NetworkImport::analyze_questions_content($content, $name);
            $stats = $analysis['stats'];

            wp_send_json_success([
                'source'  => $name,
                'message' => sprintf(
                    __('Файл «%1$s»: найдено %2$d строк, готовы к импорту %3$d, предупреждений %4$d, ошибок %5$d.', 'wp-olympiad'),
                    $name,
                    (int)$stats['total'],
                    (int)$stats['importable'],
                    (int)$stats['warnings'],
                    (int)$stats['errors']
                ),
                'stats'   => $stats,
                'report'  => $analysis['report'],
            ]);
        }

        $filename = sanitize_text_field(wp_unslash((string)($_POST['filename'] ?? '')));
        $result = NetworkImport::analyze_import_file($quiz, $filename, $override);

        if (empty($result['success']) && empty($result['report'])) {
            wp_send_json_error(['message' => $result['message']], 200);
        }

        wp_send_json_success([
            'source'  => $filename !== '' ? $filename : __('автоопределение', 'wp-olympiad'),
            'message' => $result['message'],
            'stats'   => $result['stats'],
            'report'  => $result['report'],
        ]);
    }

    public static function ajax_scan_network_import(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Доступ запрещён.', 'wp-olympiad')], 403);
        }
        check_ajax_referer('wp_olympiad_admin_nonce', 'nonce');

        $quiz_id = (int)($_POST['quiz_id'] ?? 0);
        $quiz = Quiz::get($quiz_id);
        if (!$quiz) {
            wp_send_json_error(['message' => __('Олимпиада не найдена.', 'wp-olympiad')], 404);
        }

        $override = [];
        if (!empty($_POST['path'])) {
            $override['path'] = NetworkExport::sanitize_path($_POST['path']);
        }
        if (!empty($_POST['type'])) {
            $override['type'] = sanitize_key((string)$_POST['type']);
        }
        if (isset($_POST['user'])) {
            $override['user'] = sanitize_text_field(wp_unslash((string)$_POST['user']));
        }
        if (isset($_POST['password'])) {
            $override['password'] = (string)$_POST['password'];
        }

        $files = NetworkImport::scan_network_folder_for_import_files($quiz, $override);
        $config = NetworkExport::get_quiz_network_config($quiz, $override);

        wp_send_json_success([
            'files'        => $files,
            'network_path' => $config['path'],
            'network_type' => $config['type'],
            'diagnostics'  => empty($files) ? NetworkImport::describe_network_folder($quiz, $override) : '',
        ]);
    }

    public static function ajax_upload_import_file(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Доступ запрещён.', 'wp-olympiad')], 403);
        }
        check_ajax_referer('wp_olympiad_admin_nonce', 'nonce');

        $quiz_id = (int)($_POST['quiz_id'] ?? 0);
        $quiz = Quiz::get($quiz_id);
        if (!$quiz) {
            wp_send_json_error(['message' => __('Олимпиада не найдена.', 'wp-olympiad')], 404);
        }

        if (empty($_FILES['import_file']) || !isset($_FILES['import_file']['tmp_name']) || !is_uploaded_file($_FILES['import_file']['tmp_name'])) {
            wp_send_json_error(['message' => __('Файл не был загружен. Выберите файл для импорта на компьютере.', 'wp-olympiad')], 400);
        }

        $tmp_path = $_FILES['import_file']['tmp_name'];
        $original_name = sanitize_file_name($_FILES['import_file']['name'] ?? 'import.xlsx');
        $mode = sanitize_key($_POST['mode'] ?? 'append');

        $content = @file_get_contents($tmp_path);
        if ($content === false || strlen($content) === 0) {
            wp_send_json_error(['message' => __('Загруженный файл пуст или повреждён.', 'wp-olympiad')], 400);
        }

        $questions_data = NetworkImport::parse_questions_content($content, $original_name);
        if (empty($questions_data)) {
            wp_send_json_error([
                'message' => sprintf(
                    __('В файле «%s» не найдено распознаваемых заданий. Проверьте заголовки колонок (тип, вопрос, баллы, варианты) или скачайте готовый шаблон.', 'wp-olympiad'),
                    $original_name
                )
            ], 400);
        }

        // Галочка «Скрыть баллы за импортированные вопросы в тесте» включена по умолчанию.
        $hide_points = !isset($_POST['hide_points']) || !empty($_POST['hide_points']);
        $import_result = NetworkImport::import_to_quiz($quiz_id, $questions_data, $mode, $hide_points);
        $imported_count = (int)($import_result['imported_count'] ?? 0);

        wp_send_json_success([
            'message'        => sprintf(__('Успешно импортировано заданий: %d', 'wp-olympiad'), $imported_count),
            'imported_count' => $imported_count,
            'hide_points'    => $hide_points ? 1 : 0,
            'source_file'    => $original_name,
            'details'        => array_map(function ($q, $idx) {
                return sprintf('#%d [%s] %s (%d б.)', $idx + 1, $q['type'], mb_substr($q['title'], 0, 40), $q['points']);
            }, array_slice($questions_data, 0, 10), array_keys(array_slice($questions_data, 0, 10))),
        ]);
    }

    public static function ajax_import_from_network(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Доступ запрещён.', 'wp-olympiad')], 403);
        }
        check_ajax_referer('wp_olympiad_admin_nonce', 'nonce');

        $quiz_id = (int)($_POST['quiz_id'] ?? 0);
        $filename = sanitize_text_field(wp_unslash($_POST['filename'] ?? ''));
        $mode = sanitize_key($_POST['mode'] ?? 'append');

        $override = [];
        if (!empty($_POST['path'])) {
            $override['path'] = NetworkExport::sanitize_path($_POST['path']);
        }

        $hide_points = !isset($_POST['hide_points']) || !empty($_POST['hide_points']);
        $res = NetworkImport::import_from_network($quiz_id, $filename, $mode, $override, $hide_points);
        if ($res['success']) {
            wp_send_json_success($res);
        } else {
            wp_send_json_error($res);
        }
    }

    public static function ajax_run_network_import(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Доступ запрещён.', 'wp-olympiad')], 403);
        }
        check_ajax_referer('wp_olympiad_admin_nonce', 'nonce');

        $quiz_id = (int)($_POST['quiz_id'] ?? 0);
        $quiz = Quiz::get($quiz_id);
        if (!$quiz) {
            wp_send_json_error(['message' => __('Олимпиада не найдена.', 'wp-olympiad')], 404);
        }

        $override = [];
        if (!empty($_POST['path'])) $override['path'] = NetworkExport::sanitize_path($_POST['path']);
        if (!empty($_POST['type'])) $override['type'] = sanitize_key($_POST['type']);
        if (isset($_POST['user'])) $override['user'] = sanitize_text_field(wp_unslash($_POST['user']));
        if (isset($_POST['password'])) $override['password'] = (string)$_POST['password'];

        $mode = sanitize_key($_POST['mode'] ?? (string)$quiz->get_setting('network_auto_import_mode', 'append'));
        $filename = sanitize_text_field(wp_unslash($_POST['filename'] ?? (string)$quiz->get_setting('network_auto_import_file', '')));
        $force = !empty($_POST['force']);

        $hide_points = !isset($_POST['hide_points']) || !empty($_POST['hide_points']);
        $res = NetworkImport::auto_import_for_quiz($quiz, $force, $override, $mode, $filename, $hide_points);
        if ($res['success']) {
            wp_send_json_success($res);
        } else {
            wp_send_json_error($res);
        }
    }

    public static function ajax_auto_check_answer(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Доступ запрещён.', 'wp-olympiad')], 403);
        }
        check_ajax_referer('wp_olympiad_admin_nonce', 'nonce');

        $response_id = (int)($_POST['response_id'] ?? 0);
        if ($response_id <= 0) {
            wp_send_json_error(['message' => __('Не указан ID ответа.', 'wp-olympiad')], 400);
        }

        global $wpdb;
        $table = DB::responses_table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $response_id), ARRAY_A);
        if (!$row) {
            wp_send_json_error(['message' => __('Ответ не найден.', 'wp-olympiad')], 404);
        }

        $question = Question::get((int)$row['question_id']);
        if (!$question) {
            wp_send_json_error(['message' => __('Задание не найдено.', 'wp-olympiad')], 404);
        }

        $res = Grader::auto_check_answer_data($question, $row['user_answer']);
        $res['response_id'] = $response_id;

        wp_send_json_success($res);
    }

    public static function ajax_export_questions_to_network(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Доступ запрещён.', 'wp-olympiad')], 403);
        }
        check_ajax_referer('wp_olympiad_admin_nonce', 'nonce');

        $quiz_id = (int)($_POST['quiz_id'] ?? 0);
        $quiz = $quiz_id > 0 ? Quiz::get($quiz_id) : null;
        if (!$quiz) {
            wp_send_json_error(['message' => __('Олимпиада не найдена.', 'wp-olympiad')], 404);
        }

        $override = [
            'type'     => sanitize_key($_POST['type'] ?? 'auto'),
            'path'     => NetworkExport::sanitize_path($_POST['path'] ?? ''),
            'user'     => sanitize_text_field(wp_unslash($_POST['user'] ?? '')),
            'password' => (string)($_POST['password'] ?? ''),
        ];

        $res = NetworkExport::export_questions_to_network($quiz, $override);
        if (!empty($res['success'])) {
            wp_send_json_success($res);
        } else {
            wp_send_json_error($res);
        }
    }

    /**
     * Управление ключом API олимпиады: generate | regenerate | revoke.
     */
    public static function ajax_api_key(): void
    {
        check_ajax_referer('wp_olympiad_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Доступ запрещён.', 'wp-olympiad')], 403);
        }

        $quiz_id = (int)($_POST['quiz_id'] ?? 0);
        $quiz = $quiz_id > 0 ? Quiz::get($quiz_id) : null;
        if (!$quiz) {
            wp_send_json_error(['message' => __('Олимпиада не найдена.', 'wp-olympiad')], 404);
        }

        $op = sanitize_key($_POST['op'] ?? 'generate');

        if ($op === 'revoke') {
            $quiz->revoke_api_key();
            wp_send_json_success([
                'key'          => '',
                'masked'       => '',
                'enabled'      => 0,
                'status_html'  => __('Ключ отозван. Все внешние запросы к API этой олимпиады заблокированы.', 'wp-olympiad'),
                'message'      => __('Ключ API отозван.', 'wp-olympiad'),
            ]);
        }

        if ($op === 'generate' || $op === 'regenerate') {
            $key = $quiz->generate_api_key(true);
            $fresh = Quiz::get($quiz_id);
            $created = $fresh ? (string)$fresh->get_setting('api_key_created', '') : '';

            $now_local = current_time('mysql');
            $created_str = mysql2date('d.m.Y H:i', $created !== '' ? $created : $now_local);

            wp_send_json_success([
                'key'         => $key,
                'masked'      => Api::mask($key),
                'enabled'     => 1,
                'created'     => $created,
                'status_html' => sprintf(
                    /* translators: %s: дата выпуска ключа */
                    __('Новый ключ создан %s. Скопируйте его сейчас — в дальнейшем он отображается только в маске.', 'wp-olympiad'),
                    '<strong>' . esc_html($created_str) . '</strong>'
                ),
                'message'     => __('Ключ API создан.', 'wp-olympiad'),
            ]);
        }

        wp_send_json_error(['message' => __('Неизвестная операция с ключом API.', 'wp-olympiad')], 400);
    }

    /**
     * Действия с webhook: test | regenerate_secret | clear_log.
     */
    public static function ajax_webhook_action(): void
    {
        check_ajax_referer('wp_olympiad_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Доступ запрещён.', 'wp-olympiad')], 403);
        }

        $quiz_id = (int)($_POST['quiz_id'] ?? 0);
        $quiz = $quiz_id > 0 ? Quiz::get($quiz_id) : null;
        if (!$quiz) {
            wp_send_json_error(['message' => __('Олимпиада не найдена.', 'wp-olympiad')], 404);
        }

        $op = sanitize_key($_POST['op'] ?? '');

        if ($op === 'test') {
            $result = Webhook::send_test($quiz);
            $result['entry'] = [
                'time'        => mysql2date('d.m.Y H:i:s', current_time('mysql')),
                'event'       => 'webhook_test',
                'code'        => (int)$result['code'],
                'ok'          => (bool)$result['ok'],
                'try'         => 1,
                'duration_ms' => (int)$result['duration_ms'],
                'message'     => (string)$result['message'],
                'test'        => true,
            ];
            wp_send_json_success($result);
        }

        if ($op === 'regenerate_secret') {
            $secret = Webhook::generate_secret();
            $quiz->update_setting('webhook_secret', $secret);

            wp_send_json_success([
                'secret'  => $secret,
                'masked'  => Api::mask($secret),
                'message' => __('Новый секрет подписи создан. Обновите его в настройках принимающей системы.', 'wp-olympiad'),
            ]);
        }

        if ($op === 'clear_log') {
            Webhook::clear_log($quiz);
            wp_send_json_success(['message' => __('Журнал доставок очищен.', 'wp-olympiad')]);
        }

        wp_send_json_error(['message' => __('Неизвестная операция.', 'wp-olympiad')], 400);
    }
}
