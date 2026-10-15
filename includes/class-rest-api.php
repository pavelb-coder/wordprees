<?php
declare(strict_types=1);

namespace WPOlympiad;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WP REST API Endpoints for Olympiad
 */
final class RestApi
{
    public const NAMESPACE = 'wp-olympiad/v1';

    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_filter('rest_pre_serve_request', [self::class, 'add_cors_headers'], 10, 3);
    }

    /**
     * Enable CORS for external connections to Olympiad REST API
     */
    public static function add_cors_headers(bool $served, mixed $result, WP_REST_Request $request): bool
    {
        if (str_starts_with($request->get_route(), '/' . self::NAMESPACE)) {
            if (!headers_sent()) {
                header('Access-Control-Allow-Origin: *');
                header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
                header('Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce');
            }
        }
        return $served;
    }

    public static function register_routes(): void
    {
        // Start Attempt
        register_rest_route(self::NAMESPACE, '/start', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handle_start'],
            'permission_callback' => '__return_true',
        ]);

        // Heartbeat & Anti-cheat
        register_rest_route(self::NAMESPACE, '/heartbeat', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handle_heartbeat'],
            'permission_callback' => '__return_true',
        ]);

        // Submit Answers
        register_rest_route(self::NAMESPACE, '/submit', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handle_submit'],
            'permission_callback' => '__return_true',
        ]);

        // Public Leaderboard
        register_rest_route(self::NAMESPACE, '/leaderboard/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'handle_leaderboard'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function handle_start(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $quiz_id = (int)$request->get_param('quiz_id');
        $quiz = Quiz::get($quiz_id);

        if (!$quiz) {
            return new WP_Error('quiz_not_found', __('Олимпиада не найдена.', 'wp-olympiad'), ['status' => 404]);
        }

        // Active time window check
        $active = $quiz->is_active_now();
        if (!$active['active']) {
            return new WP_Error('quiz_inactive', $active['reason'], ['status' => 403]);
        }

        $participant_name = sanitize_text_field($request->get_param('participant_name') ?? '');
        $participant_email = sanitize_email($request->get_param('participant_email') ?? '');
        $participant_org = sanitize_text_field($request->get_param('participant_org') ?? '');
        $participant_phone = sanitize_text_field($request->get_param('participant_phone') ?? '');
        $participant_city = sanitize_text_field($request->get_param('participant_city') ?? '');
        $agreement = $request->get_param('agreement');

        // Validation for required fields based on start page settings
        if ((bool)$quiz->get_setting('field_name_required', 1) && empty($participant_name)) {
            return new WP_Error('missing_name', __('Пожалуйста, укажите ФИО участника.', 'wp-olympiad'), ['status' => 400]);
        }

        if ((bool)$quiz->get_setting('require_email', 1) && empty($participant_email)) {
            return new WP_Error('missing_email', __('Пожалуйста, укажите ваш контактный e-mail.', 'wp-olympiad'), ['status' => 400]);
        }

        if ((bool)$quiz->get_setting('require_org', 1) && empty($participant_org)) {
            return new WP_Error('missing_org', __('Пожалуйста, укажите вашу организацию или класс.', 'wp-olympiad'), ['status' => 400]);
        }

        if ((bool)$quiz->get_setting('require_phone', 0) && empty($participant_phone)) {
            return new WP_Error('missing_phone', __('Пожалуйста, укажите номер телефона.', 'wp-olympiad'), ['status' => 400]);
        }

        if ((bool)$quiz->get_setting('require_city', 0) && empty($participant_city)) {
            return new WP_Error('missing_city', __('Пожалуйста, укажите ваш город / населённый пункт.', 'wp-olympiad'), ['status' => 400]);
        }

        if ((bool)$quiz->get_setting('require_agreement', 0) && empty($agreement)) {
            return new WP_Error('missing_agreement', __('Необходимо подтвердить согласие с правилами проведения тестирования.', 'wp-olympiad'), ['status' => 400]);
        }

        // Attempt limit check
        $user_id = get_current_user_id();
        $attempt_check = $quiz->can_user_attempt($user_id, $participant_email);
        if (!$attempt_check['allowed']) {
            return new WP_Error('max_attempts_exceeded', $attempt_check['reason'], ['status' => 403]);
        }

        // Compose full organization/contact string
        $full_org = $participant_org;
        if (!empty($participant_city)) {
            $full_org = $full_org ? "{$full_org} ({$participant_city})" : $participant_city;
        }
        if (!empty($participant_phone)) {
            $full_org = $full_org ? "{$full_org}, тел: {$participant_phone}" : "Тел: {$participant_phone}";
        }

        // Start attempt session
        $attempt = Attempt::start($quiz_id, [
            'name'  => $participant_name,
            'email' => $participant_email,
            'org'   => $full_org,
        ]);

        if (!$attempt) {
            return new WP_Error('attempt_failed', __('Не удалось начать олимпиаду. Попробуйте снова.', 'wp-olympiad'), ['status' => 500]);
        }

        // Prepare public questions
        $shuffle_q = (bool)$quiz->get_setting('shuffle_questions', 0);
        $shuffle_a = (bool)$quiz->get_setting('shuffle_answers', 1);

        $questions_raw = $quiz->get_questions($shuffle_q);
        $questions_data = [];

        foreach ($questions_raw as $q) {
            $questions_data[] = $q->get_public_data($shuffle_a);
        }

        return new WP_REST_Response([
            'success'   => true,
            'token'     => $attempt->token,
            'time_limit'=> (int)$quiz->get_setting('time_limit', 0),
            'questions' => $questions_data,
            'anti_cheat'=> [
                'copy'             => (bool)$quiz->get_setting('anti_cheat_copy', 1),
                'blur'             => (bool)$quiz->get_setting('anti_cheat_blur', 1),
                'max_tab_switches' => (int)$quiz->get_setting('max_tab_switches', 5),
            ],
        ], 200);
    }

    public static function handle_heartbeat(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $token = sanitize_text_field($request->get_param('token') ?? '');
        $attempt = Attempt::get_by_token($token);

        if (!$attempt || $attempt->status !== 'in_progress') {
            return new WP_Error('invalid_attempt', __('Сессия олимпиады не найдена или уже завершена.', 'wp-olympiad'), ['status' => 404]);
        }

        $quiz = Quiz::get($attempt->quiz_id);
        if (!$quiz) {
            return new WP_Error('quiz_not_found', __('Олимпиада не найдена.', 'wp-olympiad'), ['status' => 404]);
        }

        $tab_switched = (bool)$request->get_param('tab_switched');
        $result = AntiCheat::process_heartbeat($attempt, $quiz, $tab_switched);

        return new WP_REST_Response($result, 200);
    }

    public static function handle_submit(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $token = sanitize_text_field($request->get_param('token') ?? '');
        $attempt = Attempt::get_by_token($token);

        if (!$attempt) {
            return new WP_Error('invalid_attempt', __('Попытка не найдена.', 'wp-olympiad'), ['status' => 404]);
        }

        if ($attempt->status !== 'in_progress') {
            return new WP_Error('already_submitted', __('Эта попытка уже была отправлена ранее.', 'wp-olympiad'), ['status' => 400]);
        }

        $quiz = Quiz::get($attempt->quiz_id);
        if (!$quiz) {
            return new WP_Error('quiz_not_found', __('Олимпиада не найдена.', 'wp-olympiad'), ['status' => 404]);
        }

        // Validate time and window
        $time_val = AntiCheat::validate_submission_time($attempt, $quiz);
        if (!$time_val['valid']) {
            $attempt->complete(0.0, $quiz->get_total_points(), false, 'expired');
            return new WP_Error('time_expired', $time_val['error'], ['status' => 400]);
        }

        $user_answers = $request->get_param('answers');
        if (!is_array($user_answers)) {
            $user_answers = [];
        }

        // Grade attempt
        $grading = Grader::grade($quiz, $attempt, $user_answers);

        // Atomic DB transaction for saving responses and updating attempt
        global $wpdb;
        $wpdb->query('START TRANSACTION');
        try {
            // Save responses (optimized batch insert)
            Grader::save_responses($attempt->id, $grading['responses']);

            // Update attempt status
            $attempt->complete(
                $grading['total_score'],
                $grading['max_score'],
                $grading['passed'],
                $grading['status']
            );

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('submission_failed', __('Ошибка сохранения результатов в базу данных.', 'wp-olympiad'), ['status' => 500]);
        }

        // Invalidate leaderboard cache
        Leaderboard::invalidate($quiz->id);

        // Dispatch external webhook if configured
        $webhook_url = (string)$quiz->get_setting('webhook_url', '');
        if (!empty($webhook_url)) {
            self::dispatch_webhook($webhook_url, $quiz, $attempt, $grading);
        }

        // Automatic export to password-protected network folder if configured
        if (!empty($quiz->get_setting('network_auto_export', 0))) {
            try {
                NetworkExport::export_quiz($quiz->id);
            } catch (\Throwable $e) {
                // Silent catch: network delivery error must never break participant submission
                error_log('WPOlympiad Auto Network Export Error: ' . $e->getMessage());
            }
        }

        // Send email notifications to participant / admin
        Mailer::send_attempt_emails($quiz, $attempt);

        // Prepare feedback according to quiz show_results_mode
        $mode = (string)$quiz->get_setting('show_results_mode', 'immediate');

        // Mode: completely hide results at the end
        if ($mode === 'none') {
            $comp_msg = (string)$quiz->get_setting('completion_message', '');
            if (empty($comp_msg)) {
                $comp_msg = __('Спасибо за участие! Ваши ответы успешно приняты и сохранены в системе.', 'wp-olympiad');
            }
            return new WP_REST_Response([
                'success'          => true,
                'status'           => $grading['status'],
                'hide_results'     => true,
                'mode'             => 'none',
                'message'          => $comp_msg,
                'duration_seconds' => $attempt->duration_seconds,
            ], 200);
        }

        $grade_info = $quiz->get_grade_info($grading['percentage']);
        $personalized_msg = !empty($grade_info['message']) ? $quiz->replace_template_tags($grade_info['message'], $attempt) : '';
        $cert_url = $quiz->get_setting('enable_certificate', 1) ? Certificate::get_url($attempt->token) : '';

        $response_data = [
            'success'          => true,
            'status'           => $grading['status'],
            'score'            => (int)round($grading['total_score']),
            'max_score'        => (int)round($grading['max_score']),
            'percentage'       => $grading['percentage'],
            'passed'           => $grading['passed'],
            'grade_label'      => $grade_info['label'],
            'feedback_message' => $personalized_msg,
            'certificate_url'  => $cert_url,
            'duration_seconds' => $attempt->duration_seconds,
            'tab_switches'     => $attempt->tab_switches,
            'mode'             => $mode,
        ];

        if ($mode === 'immediate') {
            $questions = $quiz->get_questions(false);
            $breakdown = [];
            $responses_map = [];
            foreach ($grading['responses'] as $r) {
                $responses_map[$r['question_id']] = $r;
            }

            foreach ($questions as $q) {
                $resp = $responses_map[$q->id] ?? null;
                $breakdown[] = [
                    'id'             => $q->id,
                    'title'          => $q->title,
                    'question_type'  => $q->question_type,
                    'points'         => (int)round($q->points),
                    'awarded'        => $resp ? (int)round((float)$resp['awarded_points']) : 0,
                    'is_correct'     => $resp ? $resp['is_correct'] : null,
                    'explanation'    => $q->options['explanation'] ?? '',
                    'choices'        => $q->options['choices'] ?? [],
                    'user_answer'    => $resp ? $resp['user_answer'] : '',
                ];
            }
            $response_data['breakdown'] = $breakdown;
        } elseif ($mode === 'after_end') {
            $end_time = $quiz->get_setting('end_time', '');
            $response_data['message'] = sprintf(
                __('Ваш ответ успешно принят! Правильные ответы и итоговые результаты будут опубликованы после окончания олимпиады: %s.', 'wp-olympiad'),
                $end_time ?: __('после подведения итогов', 'wp-olympiad')
            );
        } elseif ($mode === 'manual' || $grading['has_essay']) {
            $response_data['message'] = __('Ответы приняты. В тесте присутствуют задания с развёрнутым ответом, которые проверяются жюри. Итоговая оценка появится после проверки.', 'wp-olympiad');
        }

        return new WP_REST_Response($response_data, 200);
    }

    public static function handle_leaderboard(WP_REST_Request $request): WP_REST_Response
    {
        $quiz_id = (int)$request->get_param('id');
        $limit = max(1, min(100, (int)($request->get_param('limit') ?? 50)));

        $data = Leaderboard::get_leaderboard($quiz_id, $limit);

        return new WP_REST_Response([
            'success' => true,
            'data'    => $data,
        ], 200);
    }

    /**
     * Dispatch event to external webhook asynchronously
     */
    public static function dispatch_webhook(string $url, Quiz $quiz, Attempt $attempt, array $grading): void
    {
        $payload = [
            'event'            => 'attempt_completed',
            'quiz'             => [
                'id'    => $quiz->id,
                'title' => $quiz->title,
                'slug'  => $quiz->slug,
            ],
            'attempt'          => [
                'id'                => $attempt->id,
                'token'             => $attempt->token,
                'participant_name'  => $attempt->participant_name,
                'participant_email' => $attempt->participant_email,
                'participant_org'   => $attempt->participant_org,
                'score'             => (int)round($attempt->score),
                'max_score'         => (int)round($attempt->max_score),
                'percentage'        => $attempt->percentage,
                'passed'            => (bool)$attempt->passed,
                'status'            => $attempt->status,
                'duration_seconds'  => $attempt->duration_seconds,
                'tab_switches'      => $attempt->tab_switches,
                'started_at'        => $attempt->started_at,
                'submitted_at'      => $attempt->submitted_at,
            ],
            'timestamp'        => time(),
        ];

        wp_remote_post($url, [
            'timeout'   => 5,
            'blocking'  => false, // Non-blocking: will not freeze student browser!
            'headers'   => [
                'Content-Type' => 'application/json',
                'User-Agent'   => 'WP-Olympiad-Webhook/1.0',
            ],
            'body'      => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
    }
}
