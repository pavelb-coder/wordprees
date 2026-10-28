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

    /** Олимпиада, определённая по ключу X-API-Key в текущем запросе. */
    private static ?Quiz $api_quiz = null;

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
                // Эхо Origin (как в ядре WP) — совместимо с Allow-Credentials,
                // который WordPress отправляет для REST-запросов.
                $origin = (string)$request->get_header('origin');
                if ($origin !== '' && preg_match('#^https?://#i', $origin)) {
                    header('Access-Control-Allow-Origin: ' . $origin);
                    header('Vary: Origin', false);
                } else {
                    header('Access-Control-Allow-Origin: *');
                }
                header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
                header('Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce, X-API-Key, X-WPO-Signature, X-WPO-Timestamp, X-WPO-Event, X-WPO-Delivery, X-WPO-Attempt, X-WPO-Test');
                header('Access-Control-Expose-Headers: X-WPO-Delivery, X-WPO-Timestamp, X-WPO-Signature');
                header('Access-Control-Max-Age: 600');
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

        // ── Внешняя API-интеграция: доступ по ключу олимпиады (X-API-Key) ─────
        register_rest_route(self::NAMESPACE, '/external/ping', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'external_ping'],
            'permission_callback' => [self::class, 'api_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/external/quiz/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'external_quiz'],
            'permission_callback' => [self::class, 'api_permission'],
            'args'                => [
                'id' => ['required' => true, 'validate_callback' => static fn($v) => is_numeric($v)],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/external/start', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'external_start'],
            'permission_callback' => [self::class, 'api_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/external/submit', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'external_submit'],
            'permission_callback' => [self::class, 'api_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/external/result/(?P<ref>[A-Za-z0-9_\-]+)', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'external_result'],
            'permission_callback' => [self::class, 'api_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/external/attempts', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'external_attempts'],
            'permission_callback' => [self::class, 'api_permission'],
        ]);
    }

    /**
     * Базовый URL внешней интеграции.
     */
    public static function external_base_url(): string
    {
        return rest_url(self::NAMESPACE . '/external');
    }

    /**
     * Каталог внешних эндпоинтов (для карточки интеграции и /external/ping).
     */
    public static function endpoints_catalog(): array
    {
        $base = self::external_base_url();
        return [
            ['method' => 'GET',  'path' => '/external/ping',           'url' => $base . '/ping',              'title' => __('Проверка ключа и связи', 'wp-olympiad')],
            ['method' => 'GET',  'path' => '/external/quiz/{id}',      'url' => $base . '/quiz/{id}',         'title' => __('Структура олимпиады и вопросы', 'wp-olympiad')],
            ['method' => 'POST', 'path' => '/external/start',          'url' => $base . '/start',             'title' => __('Запуск попытки участника', 'wp-olympiad')],
            ['method' => 'POST', 'path' => '/external/submit',         'url' => $base . '/submit',            'title' => __('Приём ответов и оценка', 'wp-olympiad')],
            ['method' => 'GET',  'path' => '/external/result/{ref}',   'url' => $base . '/result/{ref}',      'title' => __('Результат попытки (ID или токен)', 'wp-olympiad')],
            ['method' => 'GET',  'path' => '/external/attempts',       'url' => $base . '/attempts',          'title' => __('Список попыток олимпиады', 'wp-olympiad')],
        ];
    }

    public static function handle_start(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        // A client-supplied flag or header cannot create an authenticated context.
        return self::start_attempt($request, false);
    }

    private static function start_attempt(WP_REST_Request $request, bool $authenticated_integration): WP_REST_Response|WP_Error
    {
        $quiz_id = (int)$request->get_param('quiz_id');
        $quiz = Quiz::get($quiz_id);

        if (!$quiz) {
            return new WP_Error('quiz_not_found', __('Олимпиада не найдена.', 'wp-olympiad'), ['status' => 404]);
        }

        // Keep the public REST entry point consistent with the rendered page.
        // A validated per-quiz API integration is authenticated independently.
        if (!(bool)$quiz->get_setting('allow_guest', 1) && !is_user_logged_in() && !$authenticated_integration) {
            return new WP_Error('login_required', __('Для участия войдите на сайт или попросите организатора включить гостевой доступ.', 'wp-olympiad'), ['status' => 401]);
        }

        // Active time window check (the organizer preview link evaluates the rules
        // exactly as they apply to a participant).
        $strict_rules = Quiz::is_participant_preview();
        $active = $quiz->is_active_now($strict_rules);
        if (!$active['active']) {
            return new WP_Error('quiz_inactive', $active['reason'], ['status' => 403]);
        }

        $participant_name = sanitize_text_field($request->get_param('participant_name') ?? '');
        $participant_email = sanitize_email($request->get_param('participant_email') ?? '');
        $participant_org = sanitize_text_field($request->get_param('participant_org') ?? '');
        $participant_phone = sanitize_text_field($request->get_param('participant_phone') ?? '');
        $participant_city = sanitize_text_field($request->get_param('participant_city') ?? '');
        $agreement = $request->get_param('agreement');

        $enable_intro_page = (bool)$quiz->get_setting('enable_intro_page', 1);

        if ($enable_intro_page) {
            $show_email = (bool)$quiz->get_setting('show_email', 1);
            $show_org   = (bool)$quiz->get_setting('show_org', 1);
            $show_phone = (bool)$quiz->get_setting('show_phone', 0);
            $show_city  = (bool)$quiz->get_setting('show_city', 0);

            // Validation for required fields based on start page settings (enforced only if field is actually enabled)
            if ((bool)$quiz->get_setting('field_name_required', 1) && empty($participant_name)) {
                return new WP_Error('missing_name', __('Пожалуйста, укажите ФИО участника.', 'wp-olympiad'), ['status' => 400]);
            }

            if ($show_email && (bool)$quiz->get_setting('require_email', 1) && empty($participant_email)) {
                return new WP_Error('missing_email', __('Пожалуйста, укажите ваш контактный e-mail.', 'wp-olympiad'), ['status' => 400]);
            }

            if ($show_org && (bool)$quiz->get_setting('require_org', 1) && empty($participant_org)) {
                return new WP_Error('missing_org', __('Пожалуйста, укажите вашу организацию или класс.', 'wp-olympiad'), ['status' => 400]);
            }

            if ($show_phone && (bool)$quiz->get_setting('require_phone', 0) && empty($participant_phone)) {
                return new WP_Error('missing_phone', __('Пожалуйста, укажите номер телефона.', 'wp-olympiad'), ['status' => 400]);
            }

            if ($show_city && (bool)$quiz->get_setting('require_city', 0) && empty($participant_city)) {
                return new WP_Error('missing_city', __('Пожалуйста, укажите ваш город / населённый пункт.', 'wp-olympiad'), ['status' => 400]);
            }

            if ((bool)$quiz->get_setting('require_agreement', 0) && empty($agreement)) {
                return new WP_Error('missing_agreement', __('Необходимо подтвердить согласие с правилами проведения тестирования.', 'wp-olympiad'), ['status' => 400]);
            }
        } else {
            // When intro page is disabled, auto-populate participant identity if blank
            if (empty($participant_name)) {
                $user = wp_get_current_user();
                $participant_name = ($user && $user->ID) ? $user->display_name : __('Участник', 'wp-olympiad');
            }
            if (empty($participant_email)) {
                $user = wp_get_current_user();
                $participant_email = ($user && $user->ID) ? $user->user_email : '';
            }
        }

        // Attempt limit check
        $user_id = get_current_user_id();
        $attempt_check = $quiz->can_user_attempt($user_id, $participant_email, $strict_rules);
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

        // Start attempt session with all participant questionnaire data
        $attempt = Attempt::start($quiz_id, [
            'name'  => $participant_name,
            'email' => $participant_email,
            'org'   => $participant_org ?: $full_org,
            'phone' => $participant_phone,
            'city'  => $participant_city,
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

        $shared_texts_raw = $quiz->get_shared_texts();
        $shared_texts_dict = [];
        foreach ($shared_texts_raw as $st) {
            $tid = sanitize_key($st['id'] ?? '');
            if ($tid === '') continue;
            $shared_texts_dict[$tid] = [
                'id'      => $tid,
                'title'   => sanitize_text_field($st['title'] ?? ''),
                'content' => wp_kses_post($st['content'] ?? ''),
            ];
        }

        return new WP_REST_Response([
            'success'              => true,
            'token'                => $attempt->token,
            'time_limit'           => (int)$quiz->get_setting('time_limit', 0),
            'show_question_points' => (bool)$quiz->get_setting('show_question_points', 1),
            'questions'            => $questions_data,
            'shared_texts'         => $shared_texts_dict,
            'anti_cheat'=> [
                'copy'             => (bool)$quiz->get_setting('anti_cheat_copy', 1),
                'blur'             => (bool)$quiz->get_setting('anti_cheat_blur', 1),
                'max_tab_switches' => (int)$quiz->get_setting('max_tab_switches', 5),
                'popup_warnings'   => (bool)$quiz->get_setting('anti_cheat_popup_warnings', 1),
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
        $time_val = AntiCheat::validate_submission_time($attempt, $quiz, Quiz::is_participant_preview());
        if (!$time_val['valid']) {
            $declined_message = (string)$time_val['error'];
            if (self::preserve_declined_answers($attempt, $quiz, $request->get_param('answers'))) {
                $declined_message .= ' ' . __(
                    'Написанное сохранено без баллов и передано организатору на проверку.',
                    'wp-olympiad'
                );
            }

            $attempt->complete(0.0, $quiz->get_total_points(), 'expired');
            return new WP_Error('time_expired', $declined_message, ['status' => 400]);
        }

        $user_answers = $request->get_param('answers');
        if (!is_array($user_answers)) {
            $user_answers = [];
        }

        // Validate required questions
        $questions = Question::get_by_quiz($quiz->id);
        $missing_required = [];
        foreach ($questions as $idx => $q) {
            if ($q->is_required()) {
                $ans = $user_answers[$q->id] ?? null;
                $is_empty = false;
                if ($ans === null) {
                    $is_empty = true;
                } elseif (is_string($ans) && trim($ans) === '') {
                    $is_empty = true;
                } elseif (is_numeric($ans)) {
                    $is_empty = false;
                } elseif (is_array($ans)) {
                    if (empty($ans)) {
                        $is_empty = true;
                    } else {
                        $has_val = false;
                        foreach ($ans as $v) {
                            if (is_scalar($v) && trim((string)$v) !== '') {
                                $has_val = true;
                                break;
                            }
                        }
                        if (!$has_val) {
                            $is_empty = true;
                        }
                    }
                }
                if ($is_empty) {
                    $missing_required[] = '#' . ($idx + 1);
                }
            }
        }

        if (!empty($missing_required)) {
            return new WP_Error(
                'required_question_missing',
                sprintf(
                    __('Вы не ответили на обязательные вопросы: %s. Пожалуйста, дайте ответ на них перед завершением работы.', 'wp-olympiad'),
                    implode(', ', $missing_required)
                ),
                ['status' => 400]
            );
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
                $grading['status']
            );

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('submission_failed', __('Ошибка сохранения результатов в базу данных.', 'wp-olympiad'), ['status' => 500]);
        }

        // Dispatch external webhook if configured
        $webhook_url = (string)$quiz->get_setting('webhook_url', '');
        if (!empty($webhook_url)) {
            self::dispatch_webhook($webhook_url, $quiz, $attempt, $grading);
        }

        // Automatic export to password-protected network folder if configured.
        // Since 1.0.16 no export writes «вопросы/»: the questions export was removed, and
        // NetworkExport::export_quiz retires its leftovers instead (protocols and attempts only).
        if (!empty($quiz->get_setting('network_auto_export', 0))) {
            try {
                NetworkExport::export_quiz($quiz->id);
            } catch (\Throwable $e) {
                // Silent catch: network delivery error must never break participant submission
                error_log('WPOlympiad Auto Network Export Error: ' . $e->getMessage());
            }
        }

        // Email notifications are disabled, including legacy quizzes with saved flags.
        // Do not globally filter wp_mail: WordPress account/security mail is unrelated.

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


        $response_data = [
            'success'          => true,
            'status'           => $grading['status'],
            'score'            => (int)round($grading['total_score']),
            'max_score'        => (int)round($grading['max_score']),
            'percentage'       => $grading['percentage'],
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
            // Время окончания участнику не сообщается — только факт, что результаты
            // появятся позже. Точную дату организатор видит в карточке регламента.
            $response_data['message'] = __(
                'Ваш ответ успешно принят! Правильные ответы и итоговые результаты будут опубликованы после окончания олимпиады.',
                'wp-olympiad'
            );
        } elseif ($mode === 'manual' || $grading['has_essay']) {
            $response_data['message'] = __('Ответы приняты. В тесте присутствуют задания с развёрнутым ответом, которые проверяются жюри. Итоговая оценка появится после проверки.', 'wp-olympiad');
        }

        return new WP_REST_Response($response_data, 200);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Внешняя интеграция по ключу олимпиады (X-API-Key)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Permission callback для всех маршрутов /external/*.
     *
     * Администратор сайта может обращаться к API без ключа (cookie + REST nonce),
     * внешние системы обязаны передать ключ в заголовке X-API-Key.
     *
     * @return true|WP_Error
     */
    public static function api_permission(WP_REST_Request $request)
    {
        self::$api_quiz = null;

        if (is_user_logged_in() && current_user_can('manage_options')) {
            return true;
        }

        $key = Api::extract_key($request);
        if ($key === '') {
            return new WP_Error(
                'wpo_api_missing_key',
                __('Не указан ключ API. Передайте его в заголовке X-API-Key или Authorization: Bearer <ключ>.', 'wp-olympiad'),
                ['status' => 401]
            );
        }

        $quiz = Api::find_quiz_by_key($key, false);
        if (!$quiz) {
            return new WP_Error(
                'wpo_api_invalid_key',
                __('Неверный или отозванный ключ API олимпиады.', 'wp-olympiad'),
                ['status' => 401]
            );
        }

        if ((int)$quiz->get_setting('api_enabled', 0) !== 1) {
            return new WP_Error(
                'wpo_api_disabled',
                __('REST API для этой олимпиады отключён. Включите его в карточке «Внешнее подключение и интеграция».', 'wp-olympiad'),
                ['status' => 403]
            );
        }

        self::$api_quiz = $quiz;
        Api::touch($quiz);

        return true;
    }

    /**
     * Определить олимпиаду запроса: по ключу (внешний клиент) или по ID (администратор).
     *
     * @return Quiz|WP_Error
     */
    private static function resolve_api_quiz(WP_REST_Request $request, int $quiz_id = 0): Quiz|WP_Error
    {
        if (self::$api_quiz !== null) {
            if ($quiz_id > 0 && $quiz_id !== self::$api_quiz->id) {
                return new WP_Error(
                    'wpo_api_quiz_mismatch',
                    __('Ключ API принадлежит другой олимпиаде.', 'wp-olympiad'),
                    ['status' => 403]
                );
            }
            return self::$api_quiz;
        }

        if ($quiz_id <= 0) {
            return new WP_Error(
                'wpo_api_quiz_required',
                __('Укажите параметр quiz_id.', 'wp-olympiad'),
                ['status' => 400]
            );
        }

        $quiz = Quiz::get($quiz_id);
        if (!$quiz) {
            return new WP_Error('quiz_not_found', __('Олимпиада не найдена.', 'wp-olympiad'), ['status' => 404]);
        }

        return $quiz;
    }

    /**
     * Отказ в сдаче не должен означать потерю работы. Ответы сохраняются как есть,
     * но без баллов и с пометкой на ручную проверку: попытка остаётся «Истекло
     * время», а организатор видит, что именно написал участник, и может оценить.
     *
     * @return bool сохранены ли ответы
     */
    private static function preserve_declined_answers(Attempt $attempt, Quiz $quiz, mixed $user_answers): bool
    {
        if (!is_array($user_answers) || $user_answers === []) {
            return false;
        }

        try {
            $grading = Grader::grade($quiz, $attempt, $user_answers);
            $responses = [];
            foreach ($grading['responses'] as $resp) {
                $resp['awarded_points'] = 0;
                $resp['is_correct'] = null;
                $resp['needs_manual_review'] = 1;
                $responses[] = $resp;
            }

            if (!$responses) {
                return false;
            }

            Grader::save_responses((int)$attempt->id, $responses);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Краткая карточка олимпиады для внешних систем.
     */
    private static function quiz_summary(Quiz $quiz): array
    {
        $active = $quiz->is_active_now();

        return [
            'id'                   => $quiz->id,
            'title'                => $quiz->title,
            'slug'                 => $quiz->slug,
            'status'               => $quiz->status,
            'questions_count'      => count($quiz->get_questions(false)),
            'total_points'         => (int)round($quiz->get_total_points()),
            'time_limit'           => (int)$quiz->get_setting('time_limit', 0),
            'start_time'           => (string)$quiz->get_setting('start_time', ''),
            'end_time'             => (string)$quiz->get_setting('end_time', ''),
            'max_attempts'         => (int)$quiz->get_setting('max_attempts', 1),
            'is_open'              => (bool)$active['active'],
            'closed_reason'        => $active['active'] ? '' : (string)$active['reason'],
            'direct_url'           => $quiz->get_direct_url(),
            'allow_external_embed' => (bool)$quiz->get_setting('allow_external_embed', 1),
        ];
    }

    /**
     * Публичное представление вопросов (без ключей ответов).
     */
    private static function public_questions(Quiz $quiz): array
    {
        $items = [];
        foreach ($quiz->get_questions(false) as $question) {
            $items[] = $question->get_public_data(false);
        }
        return $items;
    }

    /**
     * Общие тексты (вставки к заданиям).
     */
    private static function shared_texts_dict(Quiz $quiz): array
    {
        $dict = [];
        foreach ($quiz->get_shared_texts() as $st) {
            $id = sanitize_key($st['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $dict[$id] = [
                'id'      => $id,
                'title'   => sanitize_text_field($st['title'] ?? ''),
                'content' => wp_kses_post($st['content'] ?? ''),
            ];
        }
        return $dict;
    }

    /**
     * GET /external/ping — проверка ключа и связи.
     */
    public static function external_ping(WP_REST_Request $request): WP_REST_Response
    {
        $data = [
            'success'         => true,
            'plugin'          => 'wp-olympiad',
            'version'         => WP_OLYMPIAD_VERSION,
            'api_version'     => '1.0',
            'site_url'        => home_url('/'),
            'rest_namespace'  => self::NAMESPACE,
            'server_time'     => current_time('mysql'),
            'site_timezone'   => function_exists('wp_timezone_string') ? wp_timezone_string() : 'UTC',
            'auth'            => [
                'method'   => 'X-API-Key',
                'quiz_id'  => self::$api_quiz ? self::$api_quiz->id : null,
            ],
            'endpoints'       => self::endpoints_catalog(),
        ];

        if (self::$api_quiz !== null) {
            $data['quiz'] = self::quiz_summary(self::$api_quiz);
        }

        return new WP_REST_Response($data, 200);
    }

    /**
     * GET /external/quiz/{id} — структура олимпиады и вопросы.
     *
     * @return WP_REST_Response|WP_Error
     */
    public static function external_quiz(WP_REST_Request $request)
    {
        $quiz_id = (int)$request->get_param('id');
        $quiz = self::resolve_api_quiz($request, $quiz_id);
        if (is_wp_error($quiz)) {
            return $quiz;
        }

        return new WP_REST_Response([
            'success'              => true,
            'quiz'                 => self::quiz_summary($quiz),
            'description'          => $quiz->description,
            'show_question_points' => (bool)$quiz->get_setting('show_question_points', 1),
            'shuffle_questions'    => (bool)$quiz->get_setting('shuffle_questions', 0),
            'shuffle_answers'      => (bool)$quiz->get_setting('shuffle_answers', 1),
            'intro'                => [
                'enabled'      => (bool)$quiz->get_setting('enable_intro_page', 1),
                'badge'        => (string)$quiz->get_setting('intro_badge', ''),
                'title'        => (string)$quiz->get_setting('intro_title', ''),
                'banner_url'   => (string)$quiz->get_setting('intro_banner_url', ''),
                'button_text'  => (string)$quiz->get_setting('intro_button_text', ''),
            ],
            'participant_fields'   => [
                'name'    => ['show' => true, 'required' => (bool)$quiz->get_setting('field_name_required', 1)],
                'email'   => ['show' => (bool)$quiz->get_setting('show_email', 1), 'required' => (bool)$quiz->get_setting('require_email', 1)],
                'org'     => ['show' => (bool)$quiz->get_setting('show_org', 1), 'required' => (bool)$quiz->get_setting('require_org', 1)],
                'phone'   => ['show' => (bool)$quiz->get_setting('show_phone', 0), 'required' => (bool)$quiz->get_setting('require_phone', 0)],
                'city'    => ['show' => (bool)$quiz->get_setting('show_city', 0), 'required' => (bool)$quiz->get_setting('require_city', 0)],
                'agreement' => ['show' => (bool)$quiz->get_setting('require_agreement', 0), 'required' => (bool)$quiz->get_setting('require_agreement', 0)],
            ],
            'shared_texts'         => self::shared_texts_dict($quiz),
            'questions'            => self::public_questions($quiz),
            'links'                => [
                'start'  => rest_url(self::NAMESPACE . '/external/start'),
                'submit' => rest_url(self::NAMESPACE . '/external/submit'),
                'result' => rest_url(self::NAMESPACE . '/external/result/{ref}'),
                'direct' => $quiz->get_direct_url(),
            ],
        ], 200);
    }

    /**
     * POST /external/start — запуск попытки из внешней системы.
     *
     * @return WP_REST_Response|WP_Error
     */
    public static function external_start(WP_REST_Request $request)
    {
        $quiz = self::resolve_api_quiz($request, (int)$request->get_param('quiz_id'));
        if (is_wp_error($quiz)) {
            return $quiz;
        }

        $inner = new WP_REST_Request('POST', '/' . self::NAMESPACE . '/start');
        foreach (['participant_name', 'participant_email', 'participant_org', 'participant_phone', 'participant_city'] as $field) {
            $inner->set_param($field, $request->get_param($field));
        }
        $inner->set_param('quiz_id', $quiz->id);

        if ($request->get_param('agreement') !== null) {
            $inner->set_param('agreement', $request->get_param('agreement'));
        }

        $response = self::start_attempt($inner, self::$api_quiz !== null && self::$api_quiz->id === $quiz->id);
        if (is_wp_error($response)) {
            return $response;
        }

        $data = $response->get_data();
        $attempt = Attempt::get_by_token((string)($data['token'] ?? ''));

        $data['attempt_id']  = $attempt ? $attempt->id : 0;
        $data['quiz_id']     = $quiz->id;
        $data['quiz_title']  = $quiz->title;
        $data['submit_url']  = rest_url(self::NAMESPACE . '/external/submit');
        $data['result_url']  = $attempt ? rest_url(self::NAMESPACE . '/external/result/' . $attempt->id) : '';
        $data['api_version'] = '1.0';

        return new WP_REST_Response($data, 200);
    }

    /**
     * POST /external/submit — приём ответов и оценка попытки.
     *
     * @return WP_REST_Response|WP_Error
     */
    public static function external_submit(WP_REST_Request $request)
    {
        $token = sanitize_text_field((string)($request->get_param('token') ?? ''));

        if ($token === '') {
            $attempt_id = (int)$request->get_param('attempt_id');
            if ($attempt_id > 0) {
                $by_id = Attempt::get($attempt_id);
                $token = $by_id ? $by_id->token : '';
            }
        }

        $attempt = $token !== '' ? Attempt::get_by_token($token) : null;
        if (!$attempt) {
            return new WP_Error(
                'invalid_attempt',
                __('Попытка не найдена. Передайте token, полученный при старте олимпиады, или attempt_id.', 'wp-olympiad'),
                ['status' => 404]
            );
        }

        $quiz = self::resolve_api_quiz($request, $attempt->quiz_id);
        if (is_wp_error($quiz)) {
            return $quiz;
        }

        $inner = new WP_REST_Request('POST', '/' . self::NAMESPACE . '/submit');
        $inner->set_param('token', $attempt->token);
        $inner->set_param('answers', $request->get_param('answers'));

        $response = self::handle_submit($inner);
        if (is_wp_error($response)) {
            return $response;
        }

        $data = $response->get_data();
        $data['attempt_id'] = $attempt->id;
        $data['quiz_id']    = $quiz->id;
        $data['result_url'] = rest_url(self::NAMESPACE . '/external/result/' . $attempt->id);

        return new WP_REST_Response($data, 200);
    }

    /**
     * GET /external/result/{ref} — результат попытки по ID или токену.
     *
     * @return WP_REST_Response|WP_Error
     */
    public static function external_result(WP_REST_Request $request)
    {
        $ref = (string)$request->get_param('ref');
        $attempt = ctype_digit($ref) ? Attempt::get((int)$ref) : Attempt::get_by_token($ref);

        if (!$attempt) {
            return new WP_Error('invalid_attempt', __('Попытка не найдена.', 'wp-olympiad'), ['status' => 404]);
        }

        $quiz = self::resolve_api_quiz($request, $attempt->quiz_id);
        if (is_wp_error($quiz)) {
            return $quiz;
        }

        $questions = [];
        foreach ($quiz->get_questions(false) as $question) {
            $questions[$question->id] = $question;
        }

        $responses = [];
        $correct = 0;
        $needs_review = 0;

        foreach ($attempt->get_responses() as $row) {
            $question = $questions[(int)$row['question_id']] ?? null;
            $is_correct = $row['is_correct'] === null || $row['is_correct'] === '' ? null : (bool)(int)$row['is_correct'];
            if ($is_correct === true) {
                $correct++;
            }
            if (!empty($row['needs_manual_review'])) {
                $needs_review++;
            }

            $responses[] = [
                'question_id'         => (int)$row['question_id'],
                'question_type'       => $question ? $question->question_type : '',
                'title'               => $question ? $question->title : '',
                'points'              => $question ? (float)$question->points : 0.0,
                'user_answer'         => (string)$row['user_answer'],
                'awarded_points'      => (float)$row['awarded_points'],
                'is_correct'          => $is_correct,
                'needs_manual_review' => !empty($row['needs_manual_review']),
                'review_comment'      => (string)($row['review_comment'] ?? ''),
            ];
        }


        return new WP_REST_Response([
            'success'   => true,
            'quiz'      => self::quiz_summary($quiz),
            'attempt'   => [
                'id'                => $attempt->id,
                'token'             => $attempt->token,
                'participant_name'  => $attempt->participant_name,
                'participant_email' => $attempt->participant_email,
                'participant_org'   => $attempt->participant_org,
                'participant_phone' => $attempt->participant_phone,
                'participant_city'  => $attempt->participant_city,
                'status'            => $attempt->status,
                'score'             => (int)$attempt->score,
                'max_score'         => (int)$attempt->max_score,
                'percentage'        => (float)$attempt->percentage,
                'duration_seconds'  => (int)$attempt->duration_seconds,
                'tab_switches'      => (int)$attempt->tab_switches,
                'started_at'        => $attempt->started_at,
                'submitted_at'      => $attempt->submitted_at,
            ],
            'counts'    => [
                'total'        => count($responses),
                'correct'      => $correct,
                'needs_review' => $needs_review,
            ],
            'responses' => $responses,
        ], 200);
    }

    /**
     * GET /external/attempts — список попыток олимпиады.
     *
     * @return WP_REST_Response|WP_Error
     */
    public static function external_attempts(WP_REST_Request $request)
    {
        $quiz = self::resolve_api_quiz($request, (int)$request->get_param('quiz_id'));
        if (is_wp_error($quiz)) {
            return $quiz;
        }

        $limit  = (int)$request->get_param('limit');
        $limit  = $limit > 0 ? min(100, $limit) : 50;
        $offset = max(0, (int)$request->get_param('offset'));
        $status = sanitize_key((string)($request->get_param('status') ?? 'all'));

        if (in_array($status, ['passed', 'failed'], true)) {
            return new WP_Error('wpo_retired_result_filter', __('Фильтр зачёта удалён. Используйте рабочий статус completed или needs_review.', 'wp-olympiad'), ['status' => 400]);
        }

        $args = [
            'quiz_id' => $quiz->id,
            'status'  => $status !== '' ? $status : 'all',
            'limit'   => $limit,
            'offset'  => $offset,
            'orderby' => 'id',
            'order'   => 'DESC',
        ];

        $items = [];
        foreach (Attempt::get_all($args) as $attempt) {
            $items[] = [
                'id'                => $attempt->id,
                'token'             => $attempt->token,
                'participant_name'  => $attempt->participant_name,
                'participant_email' => $attempt->participant_email,
                'participant_org'   => $attempt->participant_org,
                'status'            => $attempt->status,
                'score'             => (int)$attempt->score,
                'max_score'         => (int)$attempt->max_score,
                'percentage'        => (float)$attempt->percentage,
                'duration_seconds'  => (int)$attempt->duration_seconds,
                'started_at'        => $attempt->started_at,
                'submitted_at'      => $attempt->submitted_at,
            ];
        }

        return new WP_REST_Response([
            'success' => true,
            'quiz'    => self::quiz_summary($quiz),
            'total'   => Attempt::count(['quiz_id' => $quiz->id, 'status' => $args['status']]),
            'limit'   => $limit,
            'offset'  => $offset,
            'items'   => $items,
        ], 200);
    }

    /**
     * Dispatch event to external webhook asynchronously.
     *
     * Оставлено для обратной совместимости: фактическая доставка выполняется
     * классом Webhook (подпись HMAC, повторы, журнал).
     */
    public static function dispatch_webhook(string $url, Quiz $quiz, Attempt $attempt, array $grading): void
    {
        Webhook::on_attempt_completed($quiz, $attempt);
    }
}
