<?php
declare(strict_types=1);

namespace WPOlympiad;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shortcodes Registry and Rendering
 */
final class Shortcodes
{
    public static function init(): void
    {
        add_shortcode('wp_olympiad', [self::class, 'render_quiz']);
        add_shortcode('olympiad', [self::class, 'render_quiz']);

        add_shortcode('wp_olympiad_my_results', [self::class, 'render_my_results']);

        // Handle standalone external connection / direct link & iframe embed
        add_action('template_redirect', [self::class, 'handle_standalone_runner']);
    }

    /**
     * Ссылка «глазами участника» / «вернуться» для того же экрана олимпиады.
     */
    private static function organizer_note_link(Quiz $quiz, bool $enable): string
    {
        $label = $enable
            ? __('Проверить глазами участника', 'wp-olympiad')
            : __('Вернуть обычный вид', 'wp-olympiad');

        return '<a href="' . esc_url(self::participant_preview_url($enable, $quiz)) . '">' . esc_html($label) . '</a>';
    }

    /**
     * URL той же страницы с флагом просмотра регламента глазами участника.
     */
    private static function participant_preview_url(bool $enable, Quiz $quiz): string
    {
        if (function_exists('is_singular') && is_singular() && function_exists('get_permalink')) {
            $base = (string)get_permalink();
            if ($base !== '') {
                return $enable
                    ? add_query_arg('wpo_as_participant', '1', $base)
                    : remove_query_arg('wpo_as_participant', $base);
            }
        }

        $key = $quiz->get_link_alias() !== '' ? $quiz->get_link_alias() : (string)$quiz->id;
        $url = add_query_arg('wpo_runner', $key, home_url('/'));

        return $enable
            ? add_query_arg('wpo_as_participant', '1', $url)
            : remove_query_arg('wpo_as_participant', $url);
    }

    /**
     * Расписание олимпиады для участника: когда начать и до какого момента принимаются ответы.
     */
    private static function schedule_meta_lines(Quiz $quiz): array
    {
        if (!$quiz->shows_schedule_to_participants()) {
            return [];
        }

        $bounds = $quiz->schedule_boundaries();
        $lines = [];

        if ($bounds['start'] instanceof \DateTimeImmutable) {
            $lines[] = sprintf(
                // translators: 1: date and time
                __('Начало: %s', 'wp-olympiad'),
                $bounds['start']->format('d.m.Y H:i')
            );
        }

        if ($bounds['end'] instanceof \DateTimeImmutable) {
            $lines[] = sprintf(
                // translators: 1: date and time
                __('Приём ответов до: %s', 'wp-olympiad'),
                $bounds['end']->format('d.m.Y H:i')
            );
        }

        if ($lines && function_exists('wp_timezone_string') && wp_timezone_string() !== 'UTC') {
            $lines[] = sprintf(
                // translators: 1: timezone label
                __('Часовой пояс: %s', 'wp-olympiad'),
                Quiz::schedule_timezone_label()
            );
        }

        return $lines;
    }

    /**
     * Render standalone fullscreen test runner for direct links and external iframe embedding
     */
    public static function handle_standalone_runner(): void
    {
        $runner = $_GET['wpo_runner'] ?? $_GET['wpo_embed'] ?? null;
        if ($runner === null) {
            return;
        }

        $quiz_id_raw = $_GET['quiz_id'] ?? ($_GET['id'] ?? $runner);
        if (is_array($quiz_id_raw)) {
            return;
        }

        // Explicit «quiz_id» / «id» keep priority for compatibility; otherwise the
        // runner parameter carries either the numeric olympiad id (all links issued
        // before named links existed) or the public link name chosen by an admin.
        $runner_key = trim((string)$quiz_id_raw);
        if ($runner_key === '') {
            return;
        }

        $quiz = Quiz::resolve_public_link($runner_key);
        if (!$quiz) {
            wp_die(esc_html__('Олимпиада не найдена.', 'wp-olympiad'), '', ['response' => 404]);
        }

        // From here on the numeric id of the resolved olympiad is used, no matter
        // whether the link addressed it by id or by its public name.
        $quiz_id = (int)$quiz->id;

        // Allow external embedding in iframes if configured
        if ($quiz->get_setting('allow_external_embed', 1) && !headers_sent()) {
            header_remove('X-Frame-Options');
            header('Content-Security-Policy: frame-ancestors *');
        }

        // Enqueue frontend scripts & styles
        self::enqueue_assets();

        $style_settings = Style::get($quiz);
        $standalone_bg = !empty($style_settings['canvas_bg']) ? (string)$style_settings['canvas_bg'] : '#f1f5f9';

        // Самый дальний план страницы: цвет + картинка из настроек «Стилистика страницы тестирования».
        $body_canvas = Style::canvas_background_declarations($style_settings, true);
        if (empty($body_canvas)) {
            $body_canvas = ['background-color:' . $standalone_bg];
        } elseif (empty($style_settings['canvas_bg'])) {
            // Картинка без явного цвета — подстраховочный светлый фон.
            $body_canvas[] = 'background-color:' . $standalone_bg;
        }

        // Output clean standalone HTML document
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php echo esc_html($quiz->title); ?> — <?php esc_html_e('Олимпиада', 'wp-olympiad'); ?></title>
            <style>
                html, body {
                    margin: 0;
                    padding: 0;
                    <?php echo implode(';', $body_canvas); // значения уже санитизированы: #hex и url("...") без кавычек и пробелов ?>;
                    min-height: 100vh;
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
                }
                .wpo-standalone-container {
                    padding: 20px 15px;
                }
                @media (max-width: 600px) {
                    .wpo-standalone-container {
                        padding: 10px 5px;
                    }
                }
            </style>
            <?php wp_head(); ?>
        </head>
        <body class="wpo-standalone-runner-page">
            <div class="wpo-standalone-container">
                <?php echo self::render_quiz(['id' => $quiz_id]); ?>
            </div>
            <?php wp_footer(); ?>
        </body>
        </html>
        <?php
        exit;
    }

    public static function enqueue_assets(): void
    {
        wp_enqueue_style(
            'wp-olympiad-frontend',
            WP_OLYMPIAD_URL . 'public/css/olympiad-frontend.css',
            [],
            WP_OLYMPIAD_VERSION
        );

        wp_enqueue_script(
            'wp-olympiad-frontend',
            WP_OLYMPIAD_URL . 'public/js/olympiad-frontend.js',
            [],
            WP_OLYMPIAD_VERSION,
            true
        );

        wp_localize_script('wp-olympiad-frontend', 'WPOlympiadConfig', [
            'restUrl'   => esc_url_raw(rest_url(RestApi::NAMESPACE)),
            'ajaxUrl'   => esc_url_raw(admin_url('admin-ajax.php')),
            'nonce'     => wp_create_nonce('wp_olympiad_nonce'),
            'restNonce' => wp_create_nonce('wp_rest'),
            'i18n'      => [
                'submitting'        => __('Отправка ответов...', 'wp-olympiad'),
                'confirmSubmit'     => __('Вы уверены, что хотите завершить олимпиаду и отправить ответы?', 'wp-olympiad'),
                'unansweredWarn'    => __('Внимание: у вас осталось %d неотвеченных вопросов!', 'wp-olympiad'),
                'timeExpired'       => __('Время вышло! Ваши ответы автоматически отправляются...', 'wp-olympiad'),
                'tabSwitchWarning'  => __('Внимание! Зафиксировано переключение вкладки (%d). Это действие записано в протокол.', 'wp-olympiad'),
                'errorOccurred'     => __('Произошла ошибка при отправке. Пожалуйста, попробуйте снова.', 'wp-olympiad'),
                'questionLabel'     => __('Вопрос %1$d из %2$d', 'wp-olympiad'),
                'pointsLabel'       => __('Баллов: %s', 'wp-olympiad'),
                'penaltyLabel'      => __('Штраф за ошибку: %s', 'wp-olympiad'),
            ],
        ]);
    }

    public static function render_quiz(array|string $atts): string
    {
        $atts = shortcode_atts([
            'id' => 0,
        ], (array)$atts, 'wp_olympiad');

        $quiz_id = (int)$atts['id'];
        $quiz = Quiz::get($quiz_id);

        if (!$quiz) {
            return '<div class="wpo-alert wpo-alert-error">' . esc_html__('Олимпиада не найдена.', 'wp-olympiad') . '</div>';
        }

        self::enqueue_assets();

        // Регламент оценивается так же, как для участника, по ссылке «глазами
        // участника»; иначе администратор видит закрытую олимпиаду открытой.
        $strict_rules = Quiz::is_participant_preview();
        $active_status = $quiz->is_active_now($strict_rules);
        $is_logged_in = is_user_logged_in();
        $current_user = wp_get_current_user();

        $user_name = $is_logged_in ? ($current_user->display_name ?: $current_user->user_login) : '';
        $user_email = $is_logged_in ? $current_user->user_email : '';

        // Guest restriction check
        $allow_guest = (bool)$quiz->get_setting('allow_guest', 1);
        if (!$allow_guest && !$is_logged_in) {
            return '<div class="wpo-alert wpo-alert-warning">' . sprintf(
                __('Для участия в олимпиаде необходимо авторизоваться на сайте. Пожалуйста, <a href="%s">войдите в систему</a>.', 'wp-olympiad'),
                esc_url(wp_login_url(get_permalink() ?: home_url('/')))
            ) . '</div>';
        }

        // Attempt limit check for logged-in users
        $organizer_notes = [];
        if ($is_logged_in) {
            $attempt_check = $quiz->can_user_attempt((int)$current_user->ID, $user_email, $strict_rules);
            if (!$attempt_check['allowed']) {
                return '<div class="wpo-alert wpo-alert-warning">' . esc_html($attempt_check['reason']) . '</div>';
            }
            if (!empty($attempt_check['admin_preview'])) {
                $organizer_notes[] = esc_html__(
                    'Лимит попыток для вас не применяется, так как вы администратор сайта. У участника эта страница была бы закрыта.',
                    'wp-olympiad'
                ) . ' ' . self::organizer_note_link($quiz, true);
            }
        }

        if (!empty($active_status['admin_preview'])) {
            $reason = isset($active_status['blocked_reason']) ? (string)$active_status['blocked_reason'] : '';
            $organizer_notes[] = ($reason !== ''
                ? sprintf('%s ', esc_html($reason))
                : '') . esc_html__(
                    'Регламент к вам не применяется, так как вы администратор сайта.',
                    'wp-olympiad'
                ) . ' ' . self::organizer_note_link($quiz, true);
            // Участнику точное время не показывается, организатору — можно.
            $schedule_line = $quiz->schedule_organizer_line();
            if ($schedule_line !== '') {
                $organizer_notes[] = esc_html($schedule_line);
            }
        } elseif ($strict_rules) {
            $organizer_notes[] = esc_html__(
                'Вы смотрите страницу глазами участника: расписание и лимит попыток применяются полностью.',
                'wp-olympiad'
            ) . ' ' . self::organizer_note_link($quiz, false);
        }

        $time_limit = (int)$quiz->get_setting('time_limit', 0);
        $questions_count = count($quiz->get_questions(false));
        $total_points = $quiz->get_total_points();

        // Start Page Customization Variables
        $enable_intro_page = (bool)$quiz->get_setting('enable_intro_page', 1);
        if (isset($atts['show_intro'])) {
            $val = strtolower((string)$atts['show_intro']);
            $enable_intro_page = !in_array($val, ['0', 'false', 'no', 'off'], true);
        }
        if (isset($atts['skip_intro'])) {
            $val = strtolower((string)$atts['skip_intro']);
            if (in_array($val, ['1', 'true', 'yes', 'on'], true)) {
                $enable_intro_page = false;
            }
        }
        $intro_badge = (string)$quiz->get_setting('intro_badge', __('Олимпиада / Тестирование', 'wp-olympiad'));
        $intro_title = (string)$quiz->get_setting('intro_title', '');
        $display_title = !empty($intro_title) ? $intro_title : $quiz->title;
        $intro_banner_url = (string)$quiz->get_setting('intro_banner_url', '');
        $intro_btn_text = (string)$quiz->get_setting('intro_button_text', __('🚀 Начать выполнение олимпиады', 'wp-olympiad'));

        $show_meta = (bool)$quiz->get_setting('show_meta_grid', 1);
        $show_name_req = (bool)$quiz->get_setting('field_name_required', 1);
        $name_label = (string)$quiz->get_setting('field_name_label', __('ФИО Участника:', 'wp-olympiad'));
        $name_ph = (string)$quiz->get_setting('field_name_placeholder', __('Иванов Иван Иванович', 'wp-olympiad'));

        $show_org = (bool)$quiz->get_setting('show_org', 1);
        $org_req = (bool)$quiz->get_setting('require_org', 1);
        $org_label = (string)$quiz->get_setting('field_org_label', __('Организация / Школа / Класс:', 'wp-olympiad'));
        $org_ph = (string)$quiz->get_setting('field_org_placeholder', __('Лицей №1, 10 «А» класс', 'wp-olympiad'));

        $show_email = (bool)$quiz->get_setting('show_email', 1);
        $email_req = (bool)$quiz->get_setting('require_email', 1);
        $email_label = (string)$quiz->get_setting('field_email_label', __('Контактный E-mail:', 'wp-olympiad'));
        $email_ph = (string)$quiz->get_setting('field_email_placeholder', 'name@example.com');

        $show_phone = (bool)$quiz->get_setting('show_phone', 0);
        $phone_req = (bool)$quiz->get_setting('require_phone', 0);
        $phone_label = (string)$quiz->get_setting('field_phone_label', __('Номер телефона:', 'wp-olympiad'));
        $phone_ph = (string)$quiz->get_setting('field_phone_placeholder', '+7 (999) 000-00-00');

        $show_city = (bool)$quiz->get_setting('show_city', 0);
        $city_req = (bool)$quiz->get_setting('require_city', 0);
        $city_label = (string)$quiz->get_setting('field_city_label', __('Город / Населённый пункт:', 'wp-olympiad'));
        $city_ph = (string)$quiz->get_setting('field_city_placeholder', __('Москва', 'wp-olympiad'));

        // 1.0.21: дополнительные поля анкеты, которые добавил организатор.
        $custom_fields = $enable_intro_page ? $quiz->get_custom_fields() : [];

        $req_agreement = (bool)$quiz->get_setting('require_agreement', 0);
        $agreement_text = (string)$quiz->get_setting('agreement_text', __('Я подтверждаю корректность введённых данных и согласен с правилами проведения олимпиады.', 'wp-olympiad'));

        $show_ac = (bool)$quiz->get_setting('show_anti_cheat_notice', 1);
        $ac_text = (string)$quiz->get_setting('anti_cheat_notice_text', __('🛡️ Внимание! Во время тестирования ведётся контроль переключения вкладок и активности окна. Пожалуйста, не закрывайте страницу до завершения.', 'wp-olympiad'));

        ob_start();
        ?>
        <?php echo Style::render($quiz); // Scoped design / appearance of the testing page ?>
        <div class="wpo-quiz-wrapper" id="wpo-quiz-<?php echo esc_attr((string)$quiz_id); ?>" data-quiz-id="<?php echo esc_attr((string)$quiz_id); ?>" data-skip-intro="<?php echo $enable_intro_page ? '0' : '1'; ?>">
            <!-- 1. Intro / Start Screen -->
            <div class="wpo-card wpo-intro-screen" style="overflow:hidden; <?php echo !$enable_intro_page ? 'display:none;' : ''; ?>">
                <?php if (!empty($intro_banner_url)): ?>
                    <div class="wpo-intro-banner-wrap">
                        <img src="<?php echo esc_url($intro_banner_url); ?>" alt="<?php echo esc_attr($display_title); ?>" style="width:100%; max-height:260px; object-fit:cover; display:block;">
                    </div>
                <?php endif; ?>

                <?php if (!empty($intro_badge)): ?>
                    <div class="wpo-badge-tag"><?php echo esc_html($intro_badge); ?></div>
                <?php endif; ?>

                <h2 class="wpo-quiz-title"><?php echo esc_html($display_title); ?></h2>

                <?php if (!empty($quiz->description)): ?>
                    <div class="wpo-quiz-description">
                        <?php echo wp_kses_post(wpautop($quiz->description)); ?>
                    </div>
                <?php endif; ?>

                <?php if ($show_meta): ?>
                    <div class="wpo-meta-grid">
                        <?php if ($quiz->get_setting('show_time_limit', 1)): ?>
                            <div class="wpo-meta-item">
                                <span class="wpo-meta-icon">⏱</span>
                                <div class="wpo-meta-content">
                                    <span class="wpo-meta-label"><?php esc_html_e('Время:', 'wp-olympiad'); ?></span>
                                    <span class="wpo-meta-val">
                                        <?php echo $time_limit > 0 ? sprintf(esc_html__('%d минут', 'wp-olympiad'), $time_limit) : esc_html__('Без ограничения', 'wp-olympiad'); ?>
                                    </span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($quiz->get_setting('show_questions_count', 1)): ?>
                            <div class="wpo-meta-item">
                                <span class="wpo-meta-icon">📝</span>
                                <div class="wpo-meta-content">
                                    <span class="wpo-meta-label"><?php esc_html_e('Вопросов:', 'wp-olympiad'); ?></span>
                                    <span class="wpo-meta-val"><?php echo esc_html((string)$questions_count); ?></span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($quiz->get_setting('show_points', 1)): ?>
                            <div class="wpo-meta-item">
                                <span class="wpo-meta-icon">🎯</span>
                                <div class="wpo-meta-content">
                                    <span class="wpo-meta-label"><?php esc_html_e('Макс. балл:', 'wp-olympiad'); ?></span>
                                    <span class="wpo-meta-val"><?php echo esc_html(sprintf('%d', round($total_points))); ?></span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php foreach (self::schedule_meta_lines($quiz) as $wpo_schedule_line): ?>
                            <div class="wpo-meta-item">
                                <span class="wpo-meta-icon">📅</span>
                                <div class="wpo-meta-content">
                                    <span class="wpo-meta-val"><?php echo esc_html($wpo_schedule_line); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>

                    </div>
                <?php endif; ?>

                <?php foreach ($organizer_notes as $wpo_note): ?>
                    <div class="wpo-alert wpo-alert-warning">
                        <?php echo wp_kses_post($wpo_note); ?>
                    </div>
                <?php endforeach; ?>

                <?php if (!$active_status['active']): ?>
                    <div class="wpo-alert wpo-alert-warning">
                        <?php echo esc_html($active_status['reason']); ?>
                    </div>
                <?php else: ?>
                    <form class="wpo-start-form" onsubmit="return false;">
                        <!-- Name Field -->
                        <div class="wpo-form-group">
                            <label for="wpo-name-<?php echo esc_attr((string)$quiz_id); ?>">
                                <?php echo esc_html($name_label); ?>
                                <?php if ($show_name_req): ?><span class="wpo-required">*</span><?php endif; ?>
                            </label>
                            <input type="text" id="wpo-name-<?php echo esc_attr((string)$quiz_id); ?>" name="participant_name" class="wpo-input" value="<?php echo esc_attr($user_name); ?>" <?php echo $show_name_req ? 'required' : ''; ?> placeholder="<?php echo esc_attr($name_ph); ?>">
                        </div>

                        <!-- Organization Field -->
                        <?php if ($show_org): ?>
                            <div class="wpo-form-group">
                                <label for="wpo-org-<?php echo esc_attr((string)$quiz_id); ?>">
                                    <?php echo esc_html($org_label); ?>
                                    <?php if ($org_req): ?><span class="wpo-required">*</span><?php endif; ?>
                                </label>
                                <input type="text" id="wpo-org-<?php echo esc_attr((string)$quiz_id); ?>" name="participant_org" class="wpo-input" <?php echo $org_req ? 'required' : ''; ?> placeholder="<?php echo esc_attr($org_ph); ?>">
                            </div>
                        <?php endif; ?>

                        <!-- Email Field -->
                        <?php if ($show_email): ?>
                            <div class="wpo-form-group">
                                <label for="wpo-email-<?php echo esc_attr((string)$quiz_id); ?>">
                                    <?php echo esc_html($email_label); ?>
                                    <?php if ($email_req): ?><span class="wpo-required">*</span><?php endif; ?>
                                </label>
                                <input type="email" id="wpo-email-<?php echo esc_attr((string)$quiz_id); ?>" name="participant_email" class="wpo-input" value="<?php echo esc_attr($user_email); ?>" <?php echo $email_req ? 'required' : ''; ?> placeholder="<?php echo esc_attr($email_ph); ?>">
                            </div>
                        <?php endif; ?>

                        <!-- Phone Field -->
                        <?php if ($show_phone): ?>
                            <div class="wpo-form-group">
                                <label for="wpo-phone-<?php echo esc_attr((string)$quiz_id); ?>">
                                    <?php echo esc_html($phone_label); ?>
                                    <?php if ($phone_req): ?><span class="wpo-required">*</span><?php endif; ?>
                                </label>
                                <input type="tel" id="wpo-phone-<?php echo esc_attr((string)$quiz_id); ?>" name="participant_phone" class="wpo-input" <?php echo $phone_req ? 'required' : ''; ?> placeholder="<?php echo esc_attr($phone_ph); ?>">
                            </div>
                        <?php endif; ?>

                        <!-- City Field -->
                        <?php if ($show_city): ?>
                            <div class="wpo-form-group">
                                <label for="wpo-city-<?php echo esc_attr((string)$quiz_id); ?>">
                                    <?php echo esc_html($city_label); ?>
                                    <?php if ($city_req): ?><span class="wpo-required">*</span><?php endif; ?>
                                </label>
                                <input type="text" id="wpo-city-<?php echo esc_attr((string)$quiz_id); ?>" name="participant_city" class="wpo-input" <?php echo $city_req ? 'required' : ''; ?> placeholder="<?php echo esc_attr($city_ph); ?>">
                            </div>
                        <?php endif; ?>

                        <?php if ($custom_fields): ?>
                            <div class="wpo-custom-fields">
                                <p class="wpo-custom-fields-title"><?php esc_html_e('Дополнительные сведения', 'wp-olympiad'); ?></p>
                                <?php foreach ($custom_fields as $wpo_cf): ?>
                                    <?php echo ParticipantFields::control_html($wpo_cf, 'custom_fields'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Agreement Checkbox -->
                        <?php if ($req_agreement): ?>
                            <div class="wpo-form-group" style="margin-top:14px;">
                                <label style="display:flex; align-items:flex-start; gap:8px; cursor:pointer; font-size:13px; color:#475569;">
                                    <input type="checkbox" name="agreement" required style="margin-top:2px;">
                                    <span><?php echo esc_html($agreement_text); ?> <span class="wpo-required">*</span></span>
                                </label>
                            </div>
                        <?php endif; ?>

                        <!-- Anti-Cheat Notice -->
                        <?php if ($show_ac && !empty($ac_text)): ?>
                            <div class="wpo-anti-cheat-notice">
                                <?php echo esc_html($ac_text); ?>
                            </div>
                        <?php endif; ?>

                        <button type="button" class="wpo-btn wpo-btn-primary wpo-btn-start" data-quiz-id="<?php echo esc_attr((string)$quiz_id); ?>">
                            <?php echo esc_html($intro_btn_text); ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <!-- 2. Test Runner Screen (hidden initially if intro is enabled) -->
            <div class="wpo-card wpo-runner-screen" style="<?php echo !$enable_intro_page ? '' : 'display:none;'; ?>">
                <?php if (!$enable_intro_page): ?>
                    <div class="wpo-runner-loading" style="text-align:center; padding:32px; font-weight:500; color:#475569;">
                        ⏳ <?php esc_html_e('Загрузка заданий олимпиады...', 'wp-olympiad'); ?>
                    </div>
                <?php endif; ?>
                <div class="wpo-runner-header">
                    <div class="wpo-question-counter">
                        <span class="wpo-counter-current">1</span> / <span class="wpo-counter-total"><?php echo esc_html((string)$questions_count); ?></span>
                    </div>

                    <?php if ($time_limit > 0): ?>
                        <div class="wpo-timer-box" title="<?php esc_attr_e('Оставшееся время', 'wp-olympiad'); ?>">
                            <span class="wpo-timer-icon">⏳</span>
                            <span class="wpo-timer-val">00:00</span>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Progress Bar -->
                <div class="wpo-progress-wrap">
                    <div class="wpo-progress-bar" style="width: 0%;"></div>
                </div>

                <!-- Question Numbers Navigator -->
                <div class="wpo-nav-strip"></div>

                <!-- Question View Container -->
                <div class="wpo-question-container"></div>

                <!-- Runner Controls -->
                <div class="wpo-runner-footer">
                    <button type="button" class="wpo-btn wpo-btn-outline wpo-btn-prev" disabled>
                        ← <?php esc_html_e('Назад', 'wp-olympiad'); ?>
                    </button>
                    <div class="wpo-footer-center">
                        <button type="button" class="wpo-btn wpo-btn-warning wpo-btn-flag" title="<?php esc_attr_e('Отметить вопрос, чтобы вернуться к нему позже', 'wp-olympiad'); ?>">
                            🔖 <?php esc_html_e('Отметить', 'wp-olympiad'); ?>
                        </button>
                    </div>
                    <button type="button" class="wpo-btn wpo-btn-primary wpo-btn-next">
                        <?php esc_html_e('Далее', 'wp-olympiad'); ?> →
                    </button>
                    <button type="button" class="wpo-btn wpo-btn-success wpo-btn-finish" style="display:none;">
                        ✓ <?php esc_html_e('Завершить олимпиаду', 'wp-olympiad'); ?>
                    </button>
                </div>
            </div>

            <!-- 3. Results Screen (hidden initially) -->
            <div class="wpo-card wpo-results-screen" style="display:none;"></div>
        </div>
        <?php
        return (string)ob_get_clean();
    }

    public static function render_my_results(): string
    {
        if (!is_user_logged_in()) {
            return '<div class="wpo-alert">' . sprintf(
                esc_html__('Пожалуйста, %1$sвойдите на сайт%2$s, чтобы посмотреть историю своих результатов.', 'wp-olympiad'),
                '<a href="' . esc_url(wp_login_url()) . '">',
                '</a>'
            ) . '</div>';
        }

        self::enqueue_assets();

        $user_id = get_current_user_id();
        $attempts = Attempt::get_all([
            'user_id' => $user_id,
            'limit'   => 50,
            'orderby' => 'id',
            'order'   => 'DESC',
        ]);

        ob_start();
        ?>
        <div class="wpo-my-results-container">
            <h3><?php esc_html_e('Мои результаты тестирований', 'wp-olympiad'); ?></h3>
            <?php if (empty($attempts)): ?>
                <p class="wpo-empty-notice"><?php esc_html_e('Вы ещё не принимали участия в тестированиях.', 'wp-olympiad'); ?></p>
            <?php else: ?>
                <div class="wpo-table-responsive">
                    <table class="wpo-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Олимпиада / Тест', 'wp-olympiad'); ?></th>
                                <th><?php esc_html_e('Дата сдачи', 'wp-olympiad'); ?></th>
                                <th><?php esc_html_e('Баллы', 'wp-olympiad'); ?></th>
                                <th><?php esc_html_e('Результат', 'wp-olympiad'); ?></th>
                                <th><?php esc_html_e('Время', 'wp-olympiad'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($attempts as $att): ?>
                                <?php
                                $quiz = Quiz::get($att->quiz_id);
                                $quiz_title = $quiz ? $quiz->title : ('#' . $att->quiz_id);
                                $m = floor($att->duration_seconds / 60);
                                $s = $att->duration_seconds % 60;
                                ?>
                                <tr>
                                    <td><strong><?php echo esc_html($quiz_title); ?></strong></td>
                                    <td><?php echo esc_html($att->submitted_at ? date_i18n('d.m.Y H:i', strtotime($att->submitted_at)) : '—'); ?></td>
                                    <td><?php echo esc_html(sprintf('%d / %d (%d%%)', round($att->score), round($att->max_score), round($att->percentage))); ?></td>
                                    <td>
                                        <span class="wpo-badge wpo-badge-completed"><?php echo esc_html($att->get_status_label()); ?></span>
                                    </td>
                                    <td><code><?php echo esc_html(sprintf('%02d:%02d', $m, $s)); ?></code></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return (string)ob_get_clean();
    }
}
