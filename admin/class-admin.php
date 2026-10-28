<?php
declare(strict_types=1);

namespace WPOlympiad\Admin;

use WPOlympiad\Quiz;
use WPOlympiad\Style;
use WPOlympiad\Question;
use WPOlympiad\Attempt;
use WPOlympiad\Export;
use WPOlympiad\NetworkExport;
use WPOlympiad\NetworkImport;
use WPOlympiad\DesktopEditor;

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
    }

    public static function enqueue_assets(string $hook): void
    {
        if (!str_contains($hook, 'wp-olympiad')) {
            return;
        }

        wp_enqueue_media();

        // Frontend stylesheet is reused by the live style preview on the quiz edit screen.
        // It must be loaded BEFORE the admin stylesheet so that admin design tokens win.
        $page = isset($_GET['page']) ? sanitize_key((string)$_GET['page']) : '';
        $preview_styles = ($page === 'wp-olympiad-edit');
        if ($preview_styles) {
            wp_enqueue_style(
                'wp-olympiad-frontend',
                WP_OLYMPIAD_URL . 'public/css/olympiad-frontend.css',
                [],
                WP_OLYMPIAD_VERSION
            );
        }

        wp_enqueue_style(
            'wp-olympiad-admin',
            WP_OLYMPIAD_URL . 'admin/css/admin.css',
            $preview_styles ? ['wp-olympiad-frontend'] : [],
            WP_OLYMPIAD_VERSION . '.' . filemtime(WP_OLYMPIAD_PATH . 'admin/css/admin.css')
        );

        wp_enqueue_script(
            'wp-olympiad-admin',
            WP_OLYMPIAD_URL . 'admin/js/admin.js',
            ['jquery', 'heartbeat'],
            WP_OLYMPIAD_VERSION . '.' . filemtime(WP_OLYMPIAD_PATH . 'admin/js/admin.js'),
            true
        );

        if ($preview_styles) {
            wp_enqueue_script('wp-olympiad-style-editor', WP_OLYMPIAD_URL . 'admin/js/style-editor.js', ['jquery', 'wp-olympiad-admin'], WP_OLYMPIAD_VERSION . '.' . filemtime(WP_OLYMPIAD_PATH . 'admin/js/style-editor.js'), true);
        }

        wp_localize_script('wp-olympiad-admin', 'WPOlympiadAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'restRoot' => rest_url('wp-olympiad/v1/'),
            'nonce'   => wp_create_nonce('wp_olympiad_admin_nonce'),
            'styleDefaults' => Style::defaults(),
            'stylePreviewCss' => $preview_styles ? (string)file_get_contents(WP_OLYMPIAD_PATH . 'public/css/olympiad-frontend.css') : '',
            'stylePresets'  => Style::presets(),
            'styleFonts'    => Style::fonts(),
            'styleShadows'  => Style::shadows(),
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
                // Slug is automatic for new quizzes and stable for existing links.
                'description' => wp_kses_post($_POST['description'] ?? ''),
                'status'      => sanitize_key($_POST['status'] ?? 'publish'),
                'settings'    => [
                    'time_limit'        => max(0, (int)($_POST['time_limit'] ?? 0)),
                    'start_time'        => sanitize_text_field($_POST['start_time'] ?? ''),
                    'end_time'          => sanitize_text_field($_POST['end_time'] ?? ''),
                    // Расписание и лимит попыток для организаторов: 0 = привычный предпросмотр.
                    'rules_strict_for_admins' => !empty($_POST['rules_strict_for_admins']) ? 1 : 0,
                    // Расписание участнику по умолчанию не показывается.
                    'show_schedule_to_participants' => !empty($_POST['show_schedule_to_participants']) ? 1 : 0,
                    'max_attempts'      => max(0, (int)($_POST['max_attempts'] ?? 1)),
                    'shuffle_questions' => !empty($_POST['shuffle_questions']) ? 1 : 0,
                    // Retired card: absent/stale POST fields must not reset
                    // shuffle_answers or show_question_points. Quiz merges existing
                    // settings; new quizzes retain their established defaults.
                    'require_org'       => !empty($_POST['require_org']) ? 1 : 0,
                    'require_email'     => !empty($_POST['require_email']) ? 1 : 0,
                    'show_results_mode' => sanitize_key($_POST['show_results_mode'] ?? 'immediate'),
                    'completion_message'=> sanitize_textarea_field($_POST['completion_message'] ?? ''),
                    'anti_cheat_copy'   => !empty($_POST['anti_cheat_copy']) ? 1 : 0,
                    'anti_cheat_blur'   => !empty($_POST['anti_cheat_blur']) ? 1 : 0,
                    'max_tab_switches'  => max(0, (int)($_POST['max_tab_switches'] ?? 5)),
                    'anti_cheat_popup_warnings' => !empty($_POST['anti_cheat_popup_warnings']) ? 1 : 0,
                    'allow_external_embed' => !empty($_POST['allow_external_embed']) ? 1 : 0,
                    // Имя прямой внешней ссылки (пусто = ссылка по номеру олимпиады).
                    'link_alias'  => Quiz::normalize_link_alias($_POST['link_alias'] ?? ''),
                    'api_enabled'       => !empty($_POST['api_enabled']) ? 1 : 0,
                    'webhook_url'       => esc_url_raw($_POST['webhook_url'] ?? ''),
                    // Notifications have been retired. Cached forms cannot re-enable them.
                    'email_participant' => 0,
                    'email_admin'       => 0,
                    'admin_email_to'    => '',

                    // Start / Introductory Page Settings
                    'enable_intro_page'        => !empty($_POST['enable_intro_page']) ? 1 : 0,
                    'intro_badge'              => sanitize_text_field($_POST['intro_badge'] ?? 'Олимпиада / Тестирование'),
                    // Retired title/banner controls are deliberately omitted.
                    // Partial form saves preserve legacy settings; creates use defaults.
                    'intro_button_text'        => sanitize_text_field($_POST['intro_button_text'] ?? '🚀 Начать выполнение олимпиады'),
                    'show_meta_grid'           => !empty($_POST['show_meta_grid']) ? 1 : 0,
                    'show_time_limit'          => !empty($_POST['show_time_limit']) ? 1 : 0,
                    'show_questions_count'     => !empty($_POST['show_questions_count']) ? 1 : 0,
                    'show_points'              => !empty($_POST['show_points']) ? 1 : 0,

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

                    // Network Folder Export Settings
                    'enable_network_export'    => !empty($_POST['enable_network_export']) ? 1 : 0,
                    'network_folder_type'      => sanitize_key($_POST['network_folder_type'] ?? 'auto'),
                    'network_folder_path'      => NetworkExport::sanitize_path($_POST['network_folder_path'] ?? ''),
                    'network_folder_user'      => sanitize_text_field(wp_unslash($_POST['network_folder_user'] ?? '')),
                    'network_folder_password'  => (string)($_POST['network_folder_password'] ?? ''),
                    'network_auto_export'      => !empty($_POST['network_auto_export']) ? 1 : 0,

                    // Network Auto-Import Settings
                    'network_auto_import'          => !empty($_POST['network_auto_import']) ? 1 : 0,
                    'network_auto_import_mode'     => in_array($_POST['network_auto_import_mode'] ?? '', ['append', 'replace'], true) ? $_POST['network_auto_import_mode'] : 'append',
                    'network_auto_import_interval' => sanitize_key($_POST['network_auto_import_interval'] ?? 'every_minute'),
                    'network_auto_import_file'     => sanitize_text_field(wp_unslash($_POST['network_auto_import_file'] ?? '')),

                    // Appearance / Styling of the public testing page
                    'style' => Style::sanitize_post($_POST, ($quiz_id > 0 && ($style_quiz = Quiz::get($quiz_id))) ? Style::get($style_quiz) : []),
                ],
            ];

            // Missing fields in cached/partial forms must not disable guest access.
            $data['settings'] = array_merge($data['settings'], Quiz::guest_access_patch($_POST));

            // A stale cached form without the link name field must not reset a name
            // an administrator has already chosen.
            $link_alias_error = '';
            if (isset($_POST['link_alias']) && is_scalar($_POST['link_alias'])) {
                // Name of the public runner link: checked against the format and against
                // names of other olympiads. On failure the rest of the settings is saved
                // and the previous link name stays untouched.
                list($link_alias_clean, $link_alias_error) = Quiz::sanitize_link_alias($_POST['link_alias'], $quiz_id);
                if ($link_alias_error !== '') {
                    unset($data['settings']['link_alias']);
                } else {
                    $data['settings']['link_alias'] = $link_alias_clean;
                }
            } else {
                unset($data['settings']['link_alias']);
            }

            // Schedule: a value is either stored in the canonical form or not changed
            // at all — an unparseable date must never silently disable the time window.
            $schedule_error = '';
            foreach (['start_time' => false, 'end_time' => true] as $wpo_field => $wpo_end_of_day) {
                if (!isset($_POST[$wpo_field]) || !is_scalar($_POST[$wpo_field])) {
                    unset($data['settings'][$wpo_field]);
                    continue;
                }

                $wpo_posted = trim(sanitize_text_field((string)$_POST[$wpo_field]));
                if ($wpo_posted === '') {
                    continue;
                }

                $wpo_normalized = Quiz::normalize_schedule_time($wpo_posted, $wpo_end_of_day);
                if ($wpo_normalized === null) {
                    if ($schedule_error === '') {
                        $schedule_error = $wpo_field;
                    }
                    unset($data['settings'][$wpo_field]);
                } else {
                    $data['settings'][$wpo_field] = $wpo_normalized;
                }
            }

            // Секрет для подписи webhook создаётся автоматически, как только указан URL.
            if (!empty($data['settings']['webhook_url'])) {
                $existing_secret = '';
                if ($quiz_id > 0) {
                    $existing_quiz = Quiz::get($quiz_id);
                    $existing_secret = $existing_quiz ? $existing_quiz->get_webhook_secret() : '';
                }
                if ($existing_secret === '') {
                    $data['settings']['webhook_secret'] = \WPOlympiad\Webhook::generate_secret();
                }
            }

            if ($quiz_id > 0) {
                $quiz = Quiz::get($quiz_id);
                if ($quiz) {
                    $quiz->update($data);
                    DesktopEditor::ensure_for_quiz($quiz);
                    if (!empty($data['settings']['network_auto_import'])) {
                        NetworkImport::auto_import_for_quiz($quiz, false);
                    }
                    $settings_errors = array_filter([
                        'link_alias_error' => $link_alias_error,
                        'schedule_error'   => $schedule_error,
                    ]);
                    if ($settings_errors) {
                        $settings_errors['page'] = 'wp-olympiad-edit';
                        $settings_errors['quiz_id'] = $quiz_id;
                        wp_safe_redirect(admin_url(add_query_arg($settings_errors, 'admin.php')));
                        exit;
                    }
                    wp_safe_redirect(admin_url('admin.php?page=wp-olympiad&updated=1'));
                    exit;
                }
            } else {
                $new_id = Quiz::create($data);
                $quiz = Quiz::get($new_id);
                if ($quiz) { DesktopEditor::ensure_for_quiz($quiz); }
                if ($quiz && !empty($data['settings']['network_auto_import'])) {
                    NetworkImport::auto_import_for_quiz($quiz, false);
                }
                $settings_errors = array_filter([
                    'link_alias_error' => $link_alias_error,
                    'schedule_error'   => $schedule_error,
                ]);
                if ($settings_errors) {
                    $settings_errors['page'] = 'wp-olympiad-edit';
                    $settings_errors['quiz_id'] = $new_id;
                    wp_safe_redirect(admin_url(add_query_arg($settings_errors, 'admin.php')));
                    exit;
                }
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

        // Export to Network Folder directly
        if ($action === 'export_network' && isset($_GET['quiz_id'])) {
            $quiz_id = (int)$_GET['quiz_id'];
            check_admin_referer('wpo_export_network_' . $quiz_id);
            $res = NetworkExport::export_quiz($quiz_id);
            if ($res['success']) {
                wp_safe_redirect(admin_url('admin.php?page=wp-olympiad-attempts&quiz_id=' . $quiz_id . '&network_exported=1'));
            } else {
                wp_safe_redirect(admin_url('admin.php?page=wp-olympiad-attempts&quiz_id=' . $quiz_id . '&network_error=' . urlencode($res['message'])));
            }
            exit;
        }

        // Direct Download of Exported Network Protocol File
        if ($action === 'download_network_file' && isset($_GET['quiz_id'])) {
            $quiz_id = (int)$_GET['quiz_id'];
            check_admin_referer('wpo_download_network_' . $quiz_id);
            $raw_file = wp_unslash((string)($_GET['file'] ?? 'all'));
            $filename = preg_replace('#[^a-zA-Z0-9_\-\.\/а-яА-ЯёЁ]#u', '', $raw_file);
            if (empty($filename)) {
                $filename = 'all';
            }
            NetworkExport::download_exported_file($quiz_id, $filename);
            exit;
        }

        // Direct download of the single bundled XLSX question template.
        if ($action === 'download_question_template') {
            $format = sanitize_key($_GET['format'] ?? 'xlsx');
            NetworkImport::download_template($format);
            exit;
        }

        // The questions download URL was retired in 1.0.16; direct links answer 410 instead of files.
        if ($action === 'export_questions') {
            wp_die(esc_html__('Экспорт вопросов удалён из плагина. Скачайте комплект файлов в разделе «Результаты» или используйте импорт из XLSX.', 'wp-olympiad'), '', ['response' => 410]);
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

        // Display only. The scheduler / request watchdog imports without opening this screen.
        $questions = $quiz->get_questions(false);
        require_once WP_OLYMPIAD_PATH . 'admin/views/questions-builder.php';
    }

    public static function render_attempts_page(): void
    {
        $quiz_id = (int)($_GET['quiz_id'] ?? 0);
        $status = sanitize_key($_GET['status'] ?? '');
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
}
