<?php
declare(strict_types=1);

namespace WPOlympiad\Admin;

use WPOlympiad\Quiz;
use WPOlympiad\Question;
use WPOlympiad\Attempt;
use WPOlympiad\Export;
use WPOlympiad\Leaderboard;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin Area Controller
 */
final class Admin
{
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
        add_action('admin_init', [self::class, 'handle_actions']);
    }

    public static function register_menu(): void
    {
        $capability = 'manage_options';

        add_menu_page(
            __('Олимпиады и тесты', 'wp-olympiad'),
            __('WP Olympiad', 'wp-olympiad'),
            $capability,
            'wp-olympiad',
            [self::class, 'render_quizzes_page'],
            'dashicons-awards',
            30
        );

        add_submenu_page(
            'wp-olympiad',
            __('Все олимпиады', 'wp-olympiad'),
            __('Все олимпиады', 'wp-olympiad'),
            $capability,
            'wp-olympiad',
            [self::class, 'render_quizzes_page']
        );

        add_submenu_page(
            'wp-olympiad',
            __('Добавить олимпиаду', 'wp-olympiad'),
            __('Добавить новую', 'wp-olympiad'),
            $capability,
            'wp-olympiad-edit',
            [self::class, 'render_edit_page']
        );

        add_submenu_page(
            'wp-olympiad',
            __('Конструктор вопросов', 'wp-olympiad'),
            '', // hidden from menu, accessed via quiz list
            $capability,
            'wp-olympiad-questions',
            [self::class, 'render_questions_page']
        );

        add_submenu_page(
            'wp-olympiad',
            __('Протоколы и попытки', 'wp-olympiad'),
            __('Протоколы / Попытки', 'wp-olympiad'),
            $capability,
            'wp-olympiad-attempts',
            [self::class, 'render_attempts_page']
        );

        add_submenu_page(
            'wp-olympiad',
            __('Проверка работы', 'wp-olympiad'),
            '', // hidden from menu, accessed from attempts list
            $capability,
            'wp-olympiad-review',
            [self::class, 'render_review_page']
        );

        add_submenu_page(
            'wp-olympiad',
            __('Лидерборд и рейтинг', 'wp-olympiad'),
            __('Рейтинг участников', 'wp-olympiad'),
            $capability,
            'wp-olympiad-leaderboard',
            [self::class, 'render_leaderboard_page']
        );

        add_submenu_page(
            'wp-olympiad',
            __('Аналитика и статистика', 'wp-olympiad'),
            __('Аналитика / Статистика', 'wp-olympiad'),
            $capability,
            'wp-olympiad-stats',
            [self::class, 'render_stats_page']
        );
    }

    public static function enqueue_assets(string $hook): void
    {
        if (!str_contains($hook, 'wp-olympiad')) {
            return;
        }

        wp_enqueue_style(
            'wp-olympiad-admin',
            WP_OLYMPIAD_URL . 'admin/css/admin.css',
            [],
            WP_OLYMPIAD_VERSION
        );

        wp_enqueue_script(
            'wp-olympiad-admin',
            WP_OLYMPIAD_URL . 'admin/js/admin.js',
            ['jquery'],
            WP_OLYMPIAD_VERSION,
            true
        );

        wp_localize_script('wp-olympiad-admin', 'WPOlympiadAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('wp_olympiad_admin_nonce'),
            'i18n'    => [
                'deleteConfirm' => __('Вы действительно хотите удалить этот элемент? Это действие необратимо.', 'wp-olympiad'),
                'saved'         => __('Изменения успешно сохранены.', 'wp-olympiad'),
                'error'         => __('Произошла ошибка при сохранении.', 'wp-olympiad'),
            ],
        ]);
    }

    public static function handle_actions(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $action = $_POST['wpo_action'] ?? $_GET['wpo_action'] ?? '';

        // Save Quiz
        if ($action === 'save_quiz' && check_admin_referer('wpo_save_quiz_nonce')) {
            $quiz_id = (int)($_POST['quiz_id'] ?? 0);
            $data = [
                'title'       => sanitize_text_field($_POST['title'] ?? ''),
                'slug'        => sanitize_title($_POST['slug'] ?? ''),
                'description' => wp_kses_post($_POST['description'] ?? ''),
                'status'      => sanitize_key($_POST['status'] ?? 'publish'),
                'settings'    => [
                    'time_limit'        => max(0, (int)($_POST['time_limit'] ?? 0)),
                    'start_time'        => sanitize_text_field($_POST['start_time'] ?? ''),
                    'end_time'          => sanitize_text_field($_POST['end_time'] ?? ''),
                    'pass_percentage'   => max(0.0, min(100.0, (float)($_POST['pass_percentage'] ?? 60.0))),
                    'max_attempts'      => max(0, (int)($_POST['max_attempts'] ?? 1)),
                    'shuffle_questions' => !empty($_POST['shuffle_questions']) ? 1 : 0,
                    'shuffle_answers'   => !empty($_POST['shuffle_answers']) ? 1 : 0,
                    'allow_guest'       => !empty($_POST['allow_guest']) ? 1 : 0,
                    'require_org'       => !empty($_POST['require_org']) ? 1 : 0,
                    'require_email'     => !empty($_POST['require_email']) ? 1 : 0,
                    'show_results_mode' => sanitize_key($_POST['show_results_mode'] ?? 'immediate'),
                    'completion_message'=> sanitize_textarea_field($_POST['completion_message'] ?? ''),
                    'anti_cheat_copy'   => !empty($_POST['anti_cheat_copy']) ? 1 : 0,
                    'anti_cheat_blur'   => !empty($_POST['anti_cheat_blur']) ? 1 : 0,
                    'max_tab_switches'  => max(0, (int)($_POST['max_tab_switches'] ?? 5)),
                    'max_attempts_per_ip' => max(0, (int)($_POST['max_attempts_per_ip'] ?? 0)),
                    'disallow_ip_change'  => !empty($_POST['disallow_ip_change']) ? 1 : 0,
                    'monitor_duplicate_ip'=> !empty($_POST['monitor_duplicate_ip']) ? 1 : 0,
                    'enable_leaderboard'=> !empty($_POST['enable_leaderboard']) ? 1 : 0,
                    'allow_external_embed' => !empty($_POST['allow_external_embed']) ? 1 : 0,
                    'webhook_url'       => esc_url_raw($_POST['webhook_url'] ?? ''),
                    'enable_certificate'=> !empty($_POST['enable_certificate']) ? 1 : 0,
                    'email_participant' => !empty($_POST['email_participant']) ? 1 : 0,
                    'email_admin'       => !empty($_POST['email_admin']) ? 1 : 0,
                    'admin_email_to'    => sanitize_email($_POST['admin_email_to'] ?? ''),

                    // Start / Introductory Page Settings
                    'intro_badge'              => sanitize_text_field($_POST['intro_badge'] ?? 'Олимпиада / Тестирование'),
                    'intro_title'              => sanitize_text_field($_POST['intro_title'] ?? ''),
                    'intro_banner_url'         => esc_url_raw($_POST['intro_banner_url'] ?? ''),
                    'intro_button_text'        => sanitize_text_field($_POST['intro_button_text'] ?? '🚀 Начать выполнение олимпиады'),
                    'show_meta_grid'           => !empty($_POST['show_meta_grid']) ? 1 : 0,
                    'show_time_limit'          => !empty($_POST['show_time_limit']) ? 1 : 0,
                    'show_questions_count'     => !empty($_POST['show_questions_count']) ? 1 : 0,
                    'show_points'              => !empty($_POST['show_points']) ? 1 : 0,
                    'show_pass_pct'            => !empty($_POST['show_pass_pct']) ? 1 : 0,

                    // Registration Form Fields on Start Page
                    'field_name_label'         => sanitize_text_field($_POST['field_name_label'] ?? 'ФИО Участника:'),
                    'field_name_placeholder'   => sanitize_text_field($_POST['field_name_placeholder'] ?? 'Иванов Иван Иванович'),
                    'field_name_required'      => !empty($_POST['field_name_required']) ? 1 : 0,

                    'show_org'                 => !empty($_POST['show_org']) ? 1 : 0,
                    'field_org_label'          => sanitize_text_field($_POST['field_org_label'] ?? 'Организация / Школа / Класс:'),
                    'field_org_placeholder'    => sanitize_text_field($_POST['field_org_placeholder'] ?? 'Лицей №1, 10 «А» класс'),

                    'show_email'               => !empty($_POST['show_email']) ? 1 : 0,
                    'field_email_label'        => sanitize_text_field($_POST['field_email_label'] ?? 'Контактный E-mail:'),
                    'field_email_placeholder'  => sanitize_text_field($_POST['field_email_placeholder'] ?? 'name@example.com'),

                    'show_phone'               => !empty($_POST['show_phone']) ? 1 : 0,
                    'require_phone'            => !empty($_POST['require_phone']) ? 1 : 0,
                    'field_phone_label'        => sanitize_text_field($_POST['field_phone_label'] ?? 'Номер телефона:'),
                    'field_phone_placeholder'  => sanitize_text_field($_POST['field_phone_placeholder'] ?? '+7 (999) 000-00-00'),

                    'show_city'                => !empty($_POST['show_city']) ? 1 : 0,
                    'require_city'             => !empty($_POST['require_city']) ? 1 : 0,
                    'field_city_label'         => sanitize_text_field($_POST['field_city_label'] ?? 'Город / Населённый пункт:'),
                    'field_city_placeholder'   => sanitize_text_field($_POST['field_city_placeholder'] ?? 'Москва'),

                    'require_agreement'        => !empty($_POST['require_agreement']) ? 1 : 0,
                    'agreement_text'           => sanitize_textarea_field($_POST['agreement_text'] ?? ''),

                    'show_anti_cheat_notice'   => !empty($_POST['show_anti_cheat_notice']) ? 1 : 0,
                    'anti_cheat_notice_text'   => sanitize_textarea_field($_POST['anti_cheat_notice_text'] ?? ''),
                ],
            ];

            if ($quiz_id > 0) {
                $quiz = Quiz::get($quiz_id);
                if ($quiz) {
                    $quiz->update($data);
                    wp_safe_redirect(admin_url('admin.php?page=wp-olympiad&updated=1'));
                    exit;
                }
            } else {
                $new_id = Quiz::create($data);
                wp_safe_redirect(admin_url('admin.php?page=wp-olympiad-questions&quiz_id=' . $new_id . '&created=1'));
                exit;
            }
        }

        // Delete Quiz
        if ($action === 'delete_quiz' && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'wpo_delete_quiz')) {
            $quiz_id = (int)($_GET['quiz_id'] ?? 0);
            if ($quiz_id > 0) {
                Quiz::delete($quiz_id);
            }
            wp_safe_redirect(admin_url('admin.php?page=wp-olympiad&deleted=1'));
            exit;
        }

        // Delete Single Attempt and its responses
        if ($action === 'delete_attempt' && isset($_GET['_wpnonce'])) {
            $attempt_id = (int)($_GET['attempt_id'] ?? 0);
            if (wp_verify_nonce($_GET['_wpnonce'], 'wpo_delete_attempt_' . $attempt_id)) {
                $quiz_id = (int)($_GET['quiz_id'] ?? 0);
                if ($attempt_id > 0) {
                    Attempt::delete($attempt_id);
                }
                $redirect = admin_url('admin.php?page=wp-olympiad-attempts' . ($quiz_id > 0 ? '&quiz_id=' . $quiz_id : '') . '&deleted=1');
                wp_safe_redirect($redirect);
                exit;
            }
        }

        // Bulk Delete Attempts and their responses
        if (($action === 'bulk_delete_attempts' || (isset($_POST['bulk_action']) && $_POST['bulk_action'] === 'bulk_delete'))
            && check_admin_referer('wpo_bulk_attempts_nonce')) {
            $raw_ids = (array)($_POST['attempt_ids'] ?? []);
            $quiz_id = (int)($_POST['quiz_id'] ?? 0);
            $count = Attempt::bulk_delete($raw_ids);
            $redirect = admin_url('admin.php?page=wp-olympiad-attempts' . ($quiz_id > 0 ? '&quiz_id=' . $quiz_id : '') . '&bulk_deleted=' . $count);
            wp_safe_redirect($redirect);
            exit;
        }

        // Clear All Attempts for a Quiz
        if ($action === 'clear_quiz_attempts' && isset($_GET['_wpnonce'])) {
            $quiz_id = (int)($_GET['quiz_id'] ?? 0);
            if (wp_verify_nonce($_GET['_wpnonce'], 'wpo_clear_quiz_attempts_' . $quiz_id)) {
                $count = Attempt::delete_by_quiz($quiz_id);
                $redirect = admin_url('admin.php?page=wp-olympiad-attempts&quiz_id=' . $quiz_id . '&cleared=' . $count);
                wp_safe_redirect($redirect);
                exit;
            }
        }

        // CSV Protocol Export (with questions and answers)
        if ($action === 'export_csv') {
            $quiz_id = (int)($_GET['quiz_id'] ?? 0);
            Export::export_quiz_attempts($quiz_id);
        }

        // CSV Single Attempt Breakdown Export
        if ($action === 'export_attempt_csv') {
            $attempt_id = (int)($_GET['attempt_id'] ?? 0);
            Export::export_single_attempt($attempt_id);
        }

        // CSV Pivot Matrix Export (Online Test Pad style)
        if ($action === 'export_matrix_csv') {
            $quiz_id = (int)($_GET['quiz_id'] ?? 0);
            Export::export_pivot_matrix($quiz_id);
        }
    }

    public static function render_quizzes_page(): void
    {
        $quizzes = Quiz::all();
        require_once WP_OLYMPIAD_PATH . 'admin/views/quizzes-list.php';
    }

    public static function render_edit_page(): void
    {
        $quiz_id = (int)($_GET['quiz_id'] ?? 0);
        $quiz = $quiz_id > 0 ? Quiz::get($quiz_id) : null;
        require_once WP_OLYMPIAD_PATH . 'admin/views/quiz-edit.php';
    }

    public static function render_questions_page(): void
    {
        $quiz_id = (int)($_GET['quiz_id'] ?? 0);
        $quiz = Quiz::get($quiz_id);
        if (!$quiz) {
            wp_die(__('Олимпиада не найдена.', 'wp-olympiad'));
        }
        $questions = $quiz->get_questions(false);
        require_once WP_OLYMPIAD_PATH . 'admin/views/questions-builder.php';
    }

    public static function render_attempts_page(): void
    {
        $quiz_id = (int)($_GET['quiz_id'] ?? 0);
        $status = sanitize_key($_GET['status'] ?? '');
        $ip_filter = sanitize_text_field($_GET['ip'] ?? '');
        $quizzes = Quiz::all();

        $args = [
            'limit' => 50,
        ];
        if ($quiz_id > 0) {
            $args['quiz_id'] = $quiz_id;
        }
        if (!empty($status)) {
            $args['status'] = $status;
        }
        if (!empty($ip_filter)) {
            $args['ip'] = $ip_filter;
        }

        $attempts = Attempt::get_all($args);
        $total_count = Attempt::count($args);

        require_once WP_OLYMPIAD_PATH . 'admin/views/attempts-list.php';
    }

    public static function render_review_page(): void
    {
        $attempt_id = (int)($_GET['attempt_id'] ?? 0);
        $attempt = Attempt::get($attempt_id);
        if (!$attempt) {
            wp_die(__('Попытка не найдена.', 'wp-olympiad'));
        }
        $quiz = Quiz::get($attempt->quiz_id);
        $responses = $attempt->get_responses();
        $questions = $quiz ? $quiz->get_questions(false) : [];

        require_once WP_OLYMPIAD_PATH . 'admin/views/attempt-review.php';
    }

    public static function render_leaderboard_page(): void
    {
        $quiz_id = (int)($_GET['quiz_id'] ?? 0);
        $quizzes = Quiz::all();

        if ($quiz_id <= 0 && !empty($quizzes)) {
            $quiz_id = $quizzes[0]->id;
        }

        $leaderboard = $quiz_id > 0 ? Leaderboard::get_leaderboard($quiz_id, 100, true) : [];
        require_once WP_OLYMPIAD_PATH . 'admin/views/leaderboard-view.php';
    }

    public static function render_stats_page(): void
    {
        $quiz_id = (int)($_GET['quiz_id'] ?? 0);
        $quizzes = Quiz::all();

        if ($quiz_id <= 0 && !empty($quizzes)) {
            $quiz_id = $quizzes[0]->id;
        }

        $stats = $quiz_id > 0 ? \WPOlympiad\Stats::get_quiz_stats($quiz_id) : [];
        $matrix_data = $quiz_id > 0 ? \WPOlympiad\Stats::get_pivot_matrix($quiz_id) : ['questions' => [], 'attempts' => []];
        $ip_stats = $quiz_id > 0 ? \WPOlympiad\Stats::get_ip_monitoring_data($quiz_id) : [
            'total_unique_ips'    => 0,
            'shared_ips_count'    => 0,
            'total_ip_mismatches' => 0,
            'rows'                => [],
        ];
        require_once WP_OLYMPIAD_PATH . 'admin/views/quiz-stats.php';
    }
}
