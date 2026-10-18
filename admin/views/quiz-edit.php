<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$is_new = ($quiz === null);
$title = $is_new ? '' : $quiz->title;
$slug = $is_new ? '' : $quiz->slug;
$description = $is_new ? '' : $quiz->description;
$status = $is_new ? 'publish' : $quiz->status;

$time_limit = $is_new ? 45 : (int)$quiz->get_setting('time_limit', 45);
$start_time = $is_new ? '' : (string)$quiz->get_setting('start_time', '');
$end_time = $is_new ? '' : (string)$quiz->get_setting('end_time', '');
$pass_percentage = $is_new ? 60.0 : (float)$quiz->get_setting('pass_percentage', 60.0);
$max_attempts = $is_new ? 1 : (int)$quiz->get_setting('max_attempts', 1);
$shuffle_questions = $is_new ? 0 : (int)$quiz->get_setting('shuffle_questions', 0);
$shuffle_answers = $is_new ? 1 : (int)$quiz->get_setting('shuffle_answers', 1);
$show_question_points = $is_new ? 1 : (int)$quiz->get_setting('show_question_points', 1);
$allow_guest = $is_new ? 1 : (int)$quiz->get_setting('allow_guest', 1);
$require_org = $is_new ? 1 : (int)$quiz->get_setting('require_org', 1);
$require_email = $is_new ? 1 : (int)$quiz->get_setting('require_email', 1);
$show_results_mode = $is_new ? 'immediate' : (string)$quiz->get_setting('show_results_mode', 'immediate');
$completion_message = $is_new ? 'Спасибо за участие! Ваши ответы успешно приняты и сохранены в системе.' : (string)$quiz->get_setting('completion_message', 'Спасибо за участие! Ваши ответы успешно приняты и сохранены в системе.');
$anti_cheat_copy = $is_new ? 1 : (int)$quiz->get_setting('anti_cheat_copy', 1);
$anti_cheat_blur = $is_new ? 1 : (int)$quiz->get_setting('anti_cheat_blur', 1);
$max_tab_switches = $is_new ? 5 : (int)$quiz->get_setting('max_tab_switches', 5);
$anti_cheat_popup_warnings = $is_new ? 1 : (int)$quiz->get_setting('anti_cheat_popup_warnings', 1);
$allow_external_embed = $is_new ? 1 : (int)$quiz->get_setting('allow_external_embed', 1);
$webhook_url = $is_new ? '' : (string)$quiz->get_setting('webhook_url', '');
$direct_url = $is_new ? '' : $quiz->get_direct_url();
$embed_code = $is_new ? '' : $quiz->get_embed_code();

// Внешняя интеграция: ключ API и webhook
$api_enabled = $is_new ? 0 : (int)$quiz->get_setting('api_enabled', 0);
$api_key = $is_new ? '' : $quiz->get_api_key();
$api_key_masked = $is_new ? '' : $quiz->get_api_key_masked();
$api_key_created = $is_new ? '' : (string)$quiz->get_setting('api_key_created', '');
$api_last_used = $is_new ? '' : \WPOlympiad\Api::last_used($quiz);
$webhook_secret = $is_new ? '' : (string)$quiz->get_setting('webhook_secret', '');
$webhook_log = $is_new ? [] : \WPOlympiad\Webhook::deliveries($quiz, 8);
$api_base_url = \WPOlympiad\RestApi::external_base_url();
$api_endpoints_catalog = \WPOlympiad\RestApi::endpoints_catalog();
$quiz_id_for_api = $is_new ? 0 : (int)$quiz->id;

// Network Export Settings
$enable_network_export = $is_new ? 0 : (int)$quiz->get_setting('enable_network_export', 0);
$network_folder_type = $is_new ? 'auto' : (string)$quiz->get_setting('network_folder_type', 'auto');
$default_network_path = \WPOlympiad\NetworkExport::get_default_network_path();
$network_folder_path = $is_new ? $default_network_path : ((string)$quiz->get_setting('network_folder_path', '') ?: $default_network_path);
$network_folder_user = $is_new ? '' : (string)$quiz->get_setting('network_folder_user', '');
$network_folder_password = $is_new ? '' : (string)$quiz->get_setting('network_folder_password', '');
$network_auto_export = $is_new ? 0 : (int)$quiz->get_setting('network_auto_export', 0);
$last_network_export = !$is_new ? get_transient('wpo_last_network_export_' . $quiz->id) : null;

// Network Auto-Import Settings
$network_auto_import = $is_new ? 0 : (int)$quiz->get_setting('network_auto_import', 0);
$network_auto_import_mode = $is_new ? 'append' : (string)$quiz->get_setting('network_auto_import_mode', 'append');
$network_auto_import_interval = $is_new ? 'every_minute' : (string)$quiz->get_setting('network_auto_import_interval', 'every_minute');
if (in_array($network_auto_import_interval, ['always', 'minute', '1min'], true)) { $network_auto_import_interval = 'every_minute'; }
$network_auto_import_file = $is_new ? '' : (string)$quiz->get_setting('network_auto_import_file', '');
$last_network_import = !$is_new ? $quiz->get_setting('last_network_import', []) : null;

// Start / Intro Page settings
$enable_intro_page = $is_new ? 1 : (int)$quiz->get_setting('enable_intro_page', 1);
$intro_badge = $is_new ? 'Олимпиада / Тестирование' : (string)$quiz->get_setting('intro_badge', 'Олимпиада / Тестирование');
$intro_title = $is_new ? '' : (string)$quiz->get_setting('intro_title', '');
$intro_banner_url = $is_new ? '' : (string)$quiz->get_setting('intro_banner_url', '');
$intro_button_text = $is_new ? '🚀 Начать выполнение олимпиады' : (string)$quiz->get_setting('intro_button_text', '🚀 Начать выполнение олимпиады');

$show_meta_grid = $is_new ? 1 : (int)$quiz->get_setting('show_meta_grid', 1);
$show_time_limit = $is_new ? 1 : (int)$quiz->get_setting('show_time_limit', 1);
$show_questions_count = $is_new ? 1 : (int)$quiz->get_setting('show_questions_count', 1);
$show_points = $is_new ? 1 : (int)$quiz->get_setting('show_points', 1);
$show_pass_pct = $is_new ? 1 : (int)$quiz->get_setting('show_pass_pct', 1);

$field_name_label = $is_new ? 'ФИО Участника:' : (string)$quiz->get_setting('field_name_label', 'ФИО Участника:');
$field_name_placeholder = $is_new ? 'Иванов Иван Иванович' : (string)$quiz->get_setting('field_name_placeholder', 'Иванов Иван Иванович');
$field_name_required = $is_new ? 1 : (int)$quiz->get_setting('field_name_required', 1);

$show_org = $is_new ? 1 : (int)$quiz->get_setting('show_org', 1);
$field_org_label = $is_new ? 'Организация / Школа / Класс:' : (string)$quiz->get_setting('field_org_label', 'Организация / Школа / Класс:');
$field_org_placeholder = $is_new ? 'Лицей №1, 10 «А» класс' : (string)$quiz->get_setting('field_org_placeholder', 'Лицей №1, 10 «А» класс');

$show_email = $is_new ? 1 : (int)$quiz->get_setting('show_email', 1);
$field_email_label = $is_new ? 'Контактный E-mail:' : (string)$quiz->get_setting('field_email_label', 'Контактный E-mail:');
$field_email_placeholder = $is_new ? 'name@example.com' : (string)$quiz->get_setting('field_email_placeholder', 'name@example.com');

$show_phone = $is_new ? 0 : (int)$quiz->get_setting('show_phone', 0);
$require_phone = $is_new ? 0 : (int)$quiz->get_setting('require_phone', 0);
$field_phone_label = $is_new ? 'Номер телефона:' : (string)$quiz->get_setting('field_phone_label', 'Номер телефона:');
$field_phone_placeholder = $is_new ? '+7 (999) 000-00-00' : (string)$quiz->get_setting('field_phone_placeholder', '+7 (999) 000-00-00');

$show_city = $is_new ? 0 : (int)$quiz->get_setting('show_city', 0);
$require_city = $is_new ? 0 : (int)$quiz->get_setting('require_city', 0);
$field_city_label = $is_new ? 'Город / Населённый пункт:' : (string)$quiz->get_setting('field_city_label', 'Город / Населённый пункт:');
$field_city_placeholder = $is_new ? 'Москва' : (string)$quiz->get_setting('field_city_placeholder', 'Москва');

$require_agreement = $is_new ? 0 : (int)$quiz->get_setting('require_agreement', 0);
$agreement_text = $is_new ? 'Я подтверждаю корректность введённых данных и согласен с правилами проведения олимпиады.' : (string)$quiz->get_setting('agreement_text', 'Я подтверждаю корректность введённых данных и согласен с правилами проведения олимпиады.');

$show_anti_cheat_notice = $is_new ? 1 : (int)$quiz->get_setting('show_anti_cheat_notice', 1);
$anti_cheat_notice_text = $is_new ? '🛡️ Внимание! Во время тестирования ведётся контроль переключения вкладок и активности окна. Пожалуйста, не закрывайте страницу до завершения.' : (string)$quiz->get_setting('anti_cheat_notice_text', '🛡️ Внимание! Во время тестирования ведётся контроль переключения вкладок и активности окна. Пожалуйста, не закрывайте страницу до завершения.');
?>
<div class="wrap wpo-admin-wrap">
    <h1>
        <span class="dashicons dashicons-edit"></span>
        <?php echo $is_new ? esc_html__('Создание новой олимпиады / теста', 'wp-olympiad') : sprintf(esc_html__('Параметры: %s', 'wp-olympiad'), esc_html($title)); ?>
    </h1>

    <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=wp-olympiad')); ?>" class="wpo-settings-form">
        <?php wp_nonce_field('wpo_save_quiz_nonce'); ?>
        <input type="hidden" name="wpo_action" value="save_quiz">
        <input type="hidden" name="quiz_id" value="<?php echo esc_attr((string)($quiz ? $quiz->id : 0)); ?>">

        <div class="wpo-admin-grid">
            <!-- Left Main Column -->
            <div class="wpo-main-col">
                <div class="wpo-card">
                    <h2><?php esc_html_e('Основные сведения', 'wp-olympiad'); ?></h2>

                    <div class="wpo-field">
                        <label for="wpo-title"><strong><?php esc_html_e('Название олимпиады / теста:', 'wp-olympiad'); ?></strong></label>
                        <input type="text" name="title" id="wpo-title" class="large-text" value="<?php echo esc_attr($title); ?>" required placeholder="<?php esc_attr_e('Например: Всероссийская школьная олимпиада по математике — 10 класс', 'wp-olympiad'); ?>">
                    </div>

                    <div class="wpo-field">
                        <label for="wpo-slug"><?php esc_html_e('Ярлык (URL slug):', 'wp-olympiad'); ?></label>
                        <input type="text" name="slug" id="wpo-slug" class="regular-text" value="<?php echo esc_attr($slug); ?>" placeholder="<?php esc_attr_e('olympiad-math-10', 'wp-olympiad'); ?>">
                    </div>

                    <div class="wpo-field">
                        <label for="wpo-description"><?php esc_html_e('Описание и инструкция для участников:', 'wp-olympiad'); ?></label>
                        <?php
                        wp_editor($description, 'description', [
                            'textarea_name' => 'description',
                            'textarea_rows' => 6,
                            'media_buttons' => true,
                            'teeny'         => true,
                        ]);
                        ?>
                    </div>
                </div>

                <!-- 🎨 Редактирование начальной страницы (Start Page) -->
                <div class="wpo-card">
                    <h2>🎨 <?php esc_html_e('Редактирование начальной страницы (Start / Intro Page)', 'wp-olympiad'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('Настройте внешний вид, обложку, информационные блоки, поля анкеты регистрации и кнопку запуска на стартовом экране олимпиады.', 'wp-olympiad'); ?>
                    </p>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Стартовая страница:', 'wp-olympiad'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="enable_intro_page" id="enable_intro_page" value="1" <?php checked($enable_intro_page, 1); ?>>
                                    <strong><?php esc_html_e('Включить стартовую страницу (описание, метаданные и анкета участника)', 'wp-olympiad'); ?></strong>
                                </label>
                                <p class="description">
                                    <?php esc_html_e('Если флажок снят, вступительный экран отключается — тестирование запускается сразу при открытии олимпиады без заполнения анкеты.', 'wp-olympiad'); ?>
                                </p>
                            </td>
                        </tr>
                        <tbody id="wpo-intro-settings-subrows" style="<?php echo !$enable_intro_page ? 'display:none;' : ''; ?>">
                        <tr>
                            <th scope="row"><label for="intro_badge"><?php esc_html_e('Бейдж над заголовком:', 'wp-olympiad'); ?></label></th>
                            <td>
                                <input type="text" name="intro_badge" id="intro_badge" value="<?php echo esc_attr($intro_badge); ?>" class="regular-text" placeholder="<?php esc_attr_e('Олимпиада / Тестирование', 'wp-olympiad'); ?>">
                                <p class="description"><?php esc_html_e('Небольшой ярлык вверху страницы (например: «Школьный этап», «Вступительный экзамен», «Тестирование по физике»).', 'wp-olympiad'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="intro_title"><?php esc_html_e('Кастомный заголовок на странице:', 'wp-olympiad'); ?></label></th>
                            <td>
                                <input type="text" name="intro_title" id="intro_title" value="<?php echo esc_attr($intro_title); ?>" class="large-text" placeholder="<?php esc_attr_e('Оставьте пустым, чтобы использовать название олимпиады', 'wp-olympiad'); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="intro_banner_url"><?php esc_html_e('Баннер / Обложка олимпиады (URL):', 'wp-olympiad'); ?></label></th>
                            <td>
                                <input type="url" name="intro_banner_url" id="intro_banner_url" value="<?php echo esc_attr($intro_banner_url); ?>" class="large-text" placeholder="https://example.com/wp-content/uploads/banner.jpg">
                                <p class="description"><?php esc_html_e('Изображение-баннер, отображаемое в самом верху начальной карточки теста.', 'wp-olympiad'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="intro_button_text"><?php esc_html_e('Текст кнопки запуска:', 'wp-olympiad'); ?></label></th>
                            <td>
                                <input type="text" name="intro_button_text" id="intro_button_text" value="<?php echo esc_attr($intro_button_text); ?>" class="regular-text" placeholder="🚀 Начать выполнение олимпиады">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Информационные плашки регламента:', 'wp-olympiad'); ?></th>
                            <td>
                                <label style="display:block; margin-bottom:8px; font-weight:600;">
                                    <input type="checkbox" name="show_meta_grid" value="1" <?php checked($show_meta_grid, 1); ?>>
                                    <?php esc_html_e('Отображать блок параметров регламента (карточки)', 'wp-olympiad'); ?>
                                </label>
                                <div style="display:grid; grid-template-columns:repeat(2, 1fr); gap:6px; margin-left:20px;">
                                    <label><input type="checkbox" name="show_time_limit" value="1" <?php checked($show_time_limit, 1); ?>> <?php esc_html_e('⏱ Лимит времени', 'wp-olympiad'); ?></label>
                                    <label><input type="checkbox" name="show_questions_count" value="1" <?php checked($show_questions_count, 1); ?>> <?php esc_html_e('📝 Количество вопросов', 'wp-olympiad'); ?></label>
                                    <label><input type="checkbox" name="show_points" value="1" <?php checked($show_points, 1); ?>> <?php esc_html_e('🎯 Максимальный балл', 'wp-olympiad'); ?></label>
                                    <label><input type="checkbox" name="show_pass_pct" value="1" <?php checked($show_pass_pct, 1); ?>> <?php esc_html_e('🏆 Проходной балл', 'wp-olympiad'); ?></label>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Поля анкеты регистрации участника:', 'wp-olympiad'); ?></th>
                            <td>
                                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px;">
                                    <!-- Field 1: Name -->
                                    <div style="margin-bottom:12px; padding-bottom:10px; border-bottom:1px solid #e2e8f0;">
                                        <div style="font-weight:700; margin-bottom:6px;">👤 <?php esc_html_e('ФИО Участника', 'wp-olympiad'); ?></div>
                                        <div style="display:flex; gap:10px;">
                                            <input type="text" name="field_name_label" value="<?php echo esc_attr($field_name_label); ?>" class="regular-text" placeholder="Лейбл: ФИО Участника:">
                                            <input type="text" name="field_name_placeholder" value="<?php echo esc_attr($field_name_placeholder); ?>" class="regular-text" placeholder="Плейсхолдер: Иванов Иван Иванович">
                                            <label style="display:flex; align-items:center; gap:4px; font-size:12px;">
                                                <input type="checkbox" name="field_name_required" value="1" <?php checked($field_name_required, 1); ?>> <?php esc_html_e('Обязательно', 'wp-olympiad'); ?>
                                            </label>
                                        </div>
                                    </div>

                                    <!-- Field 2: Org -->
                                    <div style="margin-bottom:12px; padding-bottom:10px; border-bottom:1px solid #e2e8f0;">
                                        <div style="display:flex; justify-content:space-between; margin-bottom:6px;">
                                            <strong style="display:flex; align-items:center; gap:6px;">
                                                <input type="checkbox" name="show_org" value="1" <?php checked($show_org, 1); ?>>
                                                🏫 <?php esc_html_e('Организация / Школа / Класс / ВУЗ', 'wp-olympiad'); ?>
                                            </strong>
                                            <label style="font-size:12px;">
                                                <input type="checkbox" name="require_org" value="1" <?php checked($require_org, 1); ?>> <?php esc_html_e('Обязательно к заполнению', 'wp-olympiad'); ?>
                                            </label>
                                        </div>
                                        <div style="display:flex; gap:10px;">
                                            <input type="text" name="field_org_label" value="<?php echo esc_attr($field_org_label); ?>" class="regular-text" placeholder="Лейбл">
                                            <input type="text" name="field_org_placeholder" value="<?php echo esc_attr($field_org_placeholder); ?>" class="regular-text" placeholder="Плейсхолдер">
                                        </div>
                                    </div>

                                    <!-- Field 3: Email -->
                                    <div style="margin-bottom:12px; padding-bottom:10px; border-bottom:1px solid #e2e8f0;">
                                        <div style="display:flex; justify-content:space-between; margin-bottom:6px;">
                                            <strong style="display:flex; align-items:center; gap:6px;">
                                                <input type="checkbox" name="show_email" value="1" <?php checked($show_email, 1); ?>>
                                                ✉️ <?php esc_html_e('Электронная почта (E-mail)', 'wp-olympiad'); ?>
                                            </strong>
                                            <label style="font-size:12px;">
                                                <input type="checkbox" name="require_email" value="1" <?php checked($require_email, 1); ?>> <?php esc_html_e('Обязательно к заполнению', 'wp-olympiad'); ?>
                                            </label>
                                        </div>
                                        <div style="display:flex; gap:10px;">
                                            <input type="text" name="field_email_label" value="<?php echo esc_attr($field_email_label); ?>" class="regular-text" placeholder="Лейбл">
                                            <input type="text" name="field_email_placeholder" value="<?php echo esc_attr($field_email_placeholder); ?>" class="regular-text" placeholder="Плейсхолдер">
                                        </div>
                                    </div>

                                    <!-- Field 4: Phone (Online Test Pad) -->
                                    <div style="margin-bottom:12px; padding-bottom:10px; border-bottom:1px solid #e2e8f0;">
                                        <div style="display:flex; justify-content:space-between; margin-bottom:6px;">
                                            <strong style="display:flex; align-items:center; gap:6px;">
                                                <input type="checkbox" name="show_phone" value="1" <?php checked($show_phone, 1); ?>>
                                                📱 <?php esc_html_e('Номер телефона / Контакт', 'wp-olympiad'); ?>
                                            </strong>
                                            <label style="font-size:12px;">
                                                <input type="checkbox" name="require_phone" value="1" <?php checked($require_phone, 1); ?>> <?php esc_html_e('Обязательно к заполнению', 'wp-olympiad'); ?>
                                            </label>
                                        </div>
                                        <div style="display:flex; gap:10px;">
                                            <input type="text" name="field_phone_label" value="<?php echo esc_attr($field_phone_label); ?>" class="regular-text" placeholder="Лейбл">
                                            <input type="text" name="field_phone_placeholder" value="<?php echo esc_attr($field_phone_placeholder); ?>" class="regular-text" placeholder="Плейсхолдер">
                                        </div>
                                    </div>

                                    <!-- Field 5: City (Online Test Pad) -->
                                    <div style="margin-bottom:12px; padding-bottom:10px; border-bottom:1px solid #e2e8f0;">
                                        <div style="display:flex; justify-content:space-between; margin-bottom:6px;">
                                            <strong style="display:flex; align-items:center; gap:6px;">
                                                <input type="checkbox" name="show_city" value="1" <?php checked($show_city, 1); ?>>
                                                🏙️ <?php esc_html_e('Город / Населённый пункт', 'wp-olympiad'); ?>
                                            </strong>
                                            <label style="font-size:12px;">
                                                <input type="checkbox" name="require_city" value="1" <?php checked($require_city, 1); ?>> <?php esc_html_e('Обязательно к заполнению', 'wp-olympiad'); ?>
                                            </label>
                                        </div>
                                        <div style="display:flex; gap:10px;">
                                            <input type="text" name="field_city_label" value="<?php echo esc_attr($field_city_label); ?>" class="regular-text" placeholder="Лейбл">
                                            <input type="text" name="field_city_placeholder" value="<?php echo esc_attr($field_city_placeholder); ?>" class="regular-text" placeholder="Плейсхолдер">
                                        </div>
                                    </div>

                                    <!-- Field 6: Agreement -->
                                    <div>
                                        <div style="margin-bottom:6px;">
                                            <strong style="display:flex; align-items:center; gap:6px;">
                                                <input type="checkbox" name="require_agreement" value="1" <?php checked($require_agreement, 1); ?>>
                                                📋 <?php esc_html_e('Требовать согласие с правилами / обработкой данных', 'wp-olympiad'); ?>
                                            </strong>
                                        </div>
                                        <textarea name="agreement_text" rows="2" class="large-text" placeholder="<?php esc_attr_e('Текст пользовательского соглашения...', 'wp-olympiad'); ?>"><?php echo esc_textarea($agreement_text); ?></textarea>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Предупреждение Anti-Cheat:', 'wp-olympiad'); ?></th>
                            <td>
                                <label style="display:block; margin-bottom:6px;">
                                    <input type="checkbox" name="show_anti_cheat_notice" value="1" <?php checked($show_anti_cheat_notice, 1); ?>>
                                    <?php esc_html_e('Показывать предупреждение о контроле вкладок перед стартом', 'wp-olympiad'); ?>
                                </label>
                                <textarea name="anti_cheat_notice_text" rows="2" class="large-text"><?php echo esc_textarea($anti_cheat_notice_text); ?></textarea>
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </div>

                <div class="wpo-card" id="wpo-rules-card">
                    <h2><?php esc_html_e('Регламент и расписание олимпиады', 'wp-olympiad'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="time_limit"><?php esc_html_e('Лимит времени (минут):', 'wp-olympiad'); ?></label></th>
                            <td>
                                <input type="number" name="time_limit" id="time_limit" value="<?php echo esc_attr((string)$time_limit); ?>" min="0" max="600" step="1" class="small-text">
                                <p class="description"><?php esc_html_e('Время на выполнение после нажатия «Начать». Укажите 0 для неограниченного теста.', 'wp-olympiad'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="start_time"><?php esc_html_e('Начало олимпиады (МСК):', 'wp-olympiad'); ?></label></th>
                            <td>
                                <input type="text" name="start_time" id="start_time" value="<?php echo esc_attr($start_time); ?>" class="regular-text" placeholder="2026-10-15 10:00">
                                <p class="description"><?php esc_html_e('Формат: ГГГГ-ММ-ДД ЧЧ:ММ (до этого времени вход будет заблокирован). Оставьте пустым для мгновенного доступа.', 'wp-olympiad'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="end_time"><?php esc_html_e('Окончание олимпиады (МСК):', 'wp-olympiad'); ?></label></th>
                            <td>
                                <input type="text" name="end_time" id="end_time" value="<?php echo esc_attr($end_time); ?>" class="regular-text" placeholder="2026-10-15 18:00">
                                <p class="description"><?php esc_html_e('После этого времени приём новых попыток прекращается.', 'wp-olympiad'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="pass_percentage"><?php esc_html_e('Проходной процент (%):', 'wp-olympiad'); ?></label></th>
                            <td>
                                <input type="number" name="pass_percentage" id="pass_percentage" value="<?php echo esc_attr((string)$pass_percentage); ?>" min="0" max="100" step="1" class="small-text"> %
                                <p class="description"><?php esc_html_e('Процент набранных баллов для статуса «Зачёт / Пройдено».', 'wp-olympiad'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="max_attempts"><?php esc_html_e('Лимит попыток на участника:', 'wp-olympiad'); ?></label></th>
                            <td>
                                <input type="number" name="max_attempts" id="max_attempts" value="<?php echo esc_attr((string)$max_attempts); ?>" min="0" max="10" step="1" class="small-text">
                                <p class="description"><?php esc_html_e('1 — для строгих олимпиад и экзаменов. 0 — неограниченно.', 'wp-olympiad'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Порядок вопросов:', 'wp-olympiad'); ?></th>
                            <td>
                                <label for="shuffle_questions">
                                    <input type="checkbox" name="shuffle_questions" id="shuffle_questions" value="1" <?php checked($shuffle_questions, 1); ?>>
                                    <?php esc_html_e('Перемешивать порядок вопросов', 'wp-olympiad'); ?>
                                </label>
                                <p class="description"><?php esc_html_e('Вопросы выдаются в случайной последовательности. Если выключено — используется порядок из конструктора.', 'wp-olympiad'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="wpo-card">
                    <h2><?php esc_html_e('Защита от списывания (Anti-Cheat)', 'wp-olympiad'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Контроль вкладок браузера:', 'wp-olympiad'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="anti_cheat_blur" value="1" <?php checked($anti_cheat_blur, 1); ?>>
                                    <?php esc_html_e('Фиксировать потерю фокуса и переключение вкладок участником', 'wp-olympiad'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="max_tab_switches"><?php esc_html_e('Максимум переключений вкладок:', 'wp-olympiad'); ?></label></th>
                            <td>
                                <input type="number" name="max_tab_switches" id="max_tab_switches" value="<?php echo esc_attr((string)$max_tab_switches); ?>" min="0" max="50" step="1" class="small-text">
                                <p class="description"><?php esc_html_e('При превышении лимита попытка автоматически дисквалифицируется. 0 — только протоколировать без дисквалификации.', 'wp-olympiad'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Запрет копирования:', 'wp-olympiad'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="anti_cheat_copy" value="1" <?php checked($anti_cheat_copy, 1); ?>>
                                    <?php esc_html_e('Запретить выделение текста, копирование и контекстное меню на странице теста', 'wp-olympiad'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Всплывающие уведомления:', 'wp-olympiad'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="anti_cheat_popup_warnings" value="1" <?php checked($anti_cheat_popup_warnings, 1); ?>>
                                    <?php esc_html_e('Показывать всплывающие предупреждения (Toast & Модальные окна) при попытке копирования, правого клика или переключения окна', 'wp-olympiad'); ?>
                                </label>
                                <p class="description"><?php esc_html_e('Мгновенно информирует участника о регламенте честной игры и оставшихся попытках переключения.', 'wp-olympiad'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Внешнее подключение и интеграция -->
                <div class="wpo-card" id="wpo-api-card">
                    <h2>🔗 <?php esc_html_e('Внешнее подключение и интеграция', 'wp-olympiad'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('Запуск олимпиады вне WordPress, входящее REST API по ключу олимпиады и исходящие webhook-уведомления для LMS, CRM, Telegram-ботов и Google Таблиц.', 'wp-olympiad'); ?>
                    </p>

                    <!-- 1. Публичные ссылки -->
                    <h3 class="wpo-subhead">🌐 <?php esc_html_e('Публичные ссылки и встраивание', 'wp-olympiad'); ?></h3>
                    <table class="form-table">
                        <?php if (!$is_new): ?>
                            <tr>
                                <th scope="row"><?php esc_html_e('Прямая внешняя ссылка:', 'wp-olympiad'); ?></th>
                                <td>
                                    <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                                        <input type="text" class="large-text code wpo-copyable-input" readonly value="<?php echo esc_url($direct_url); ?>" onclick="this.select();">
                                        <a href="<?php echo esc_url($direct_url); ?>" target="_blank" class="button button-secondary">
                                            ↗ <?php esc_html_e('Открыть', 'wp-olympiad'); ?>
                                        </a>
                                    </div>
                                    <p class="description"><?php esc_html_e('Прямая ссылка на полноэкранное прохождение без шапки и подвала сайта. Отправляйте участникам в Telegram, VK или по email.', 'wp-olympiad'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Код для вставки на внешний сайт (iframe):', 'wp-olympiad'); ?></th>
                                <td>
                                    <textarea class="large-text code wpo-copyable-input" rows="2" readonly onclick="this.select();"><?php echo esc_textarea($embed_code); ?></textarea>
                                    <p class="description"><?php esc_html_e('Вставьте этот HTML-код на любой внешний сайт, в Tilda, Moodle, Stepik, Notion или школьный портал для бесшовного встраивания тестирования.', 'wp-olympiad'); ?></p>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <tr>
                            <th scope="row"><?php esc_html_e('Разрешить внешний iframe:', 'wp-olympiad'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="allow_external_embed" value="1" <?php checked($allow_external_embed, 1); ?>>
                                    <?php esc_html_e('Разрешить встраивание олимпиады через iframe на сторонних доменах (CSP frame-ancestors *)', 'wp-olympiad'); ?>
                                </label>
                            </td>
                        </tr>
                    </table>

                    <!-- 2. Входящее REST API -->
                    <h3 class="wpo-subhead">🧩 <?php esc_html_e('REST API по ключу олимпиады (входящие запросы)', 'wp-olympiad'); ?></h3>
                    <p class="description">
                        <?php esc_html_e('Внешняя система (LMS, Moodle, школьный портал, Telegram-бот, CRM) может получить структуру олимпиады, запустить попытку участника, принять ответы и забрать результаты. Доступ выдаётся по ключу в заголовке X-API-Key и действует только для этой олимпиады.', 'wp-olympiad'); ?>
                    </p>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Включить API:', 'wp-olympiad'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="api_enabled" id="wpo-api-enabled" value="1" <?php checked($api_enabled, 1); ?>>
                                    <strong><?php esc_html_e('Разрешить входящие запросы к /external/* по ключу', 'wp-olympiad'); ?></strong>
                                </label>
                                <p class="description"><?php esc_html_e('Если флажок снят, все внешние запросы отклоняются с кодом 403 даже при верном ключе. Ключ при этом сохраняется и его можно включить снова.', 'wp-olympiad'); ?></p>
                            </td>
                        </tr>
                        <?php if ($is_new): ?>
                            <tr>
                                <th scope="row"><?php esc_html_e('Ключ API:', 'wp-olympiad'); ?></th>
                                <td>
                                    <p class="description">💡 <?php esc_html_e('Сначала сохраните олимпиаду — после этого здесь появятся кнопки генерации ключа и список эндпоинтов.', 'wp-olympiad'); ?></p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <tr>
                                <th scope="row"><?php esc_html_e('Ключ API:', 'wp-olympiad'); ?></th>
                                <td>
                                    <div class="wpo-api-key-box">
                                        <input type="text" id="wpo-api-key" class="large-text code wpo-copyable-input" readonly
                                               value="<?php echo esc_attr($api_key !== '' ? $api_key_masked : ''); ?>"
                                               data-key="<?php echo esc_attr($api_key); ?>"
                                               data-masked="<?php echo esc_attr($api_key_masked); ?>"
                                               placeholder="<?php esc_attr_e('Ключ ещё не сгенерирован', 'wp-olympiad'); ?>"
                                               autocomplete="off" spellcheck="false">
                                        <button type="button" class="button" id="wpo-api-key-toggle" <?php echo $api_key === '' ? 'disabled' : ''; ?>>👁 <?php esc_html_e('Показать', 'wp-olympiad'); ?></button>
                                        <button type="button" class="button" id="wpo-api-key-copy" <?php echo $api_key === '' ? 'disabled' : ''; ?>>📋 <?php esc_html_e('Копировать', 'wp-olympiad'); ?></button>
                                        <button type="button" class="button button-primary" id="wpo-api-key-generate"
                                                data-quiz-id="<?php echo esc_attr((string)$quiz_id_for_api); ?>"
                                                data-op="<?php echo $api_key === '' ? 'generate' : 'regenerate'; ?>">
                                            <?php echo $api_key === '' ? '⚡ ' . esc_html__('Сгенерировать ключ', 'wp-olympiad') : '♻️ ' . esc_html__('Перевыпустить', 'wp-olympiad'); ?>
                                        </button>
                                        <?php if ($api_key !== ''): ?>
                                            <button type="button" class="button button-link-delete" id="wpo-api-key-revoke" data-quiz-id="<?php echo esc_attr((string)$quiz_id_for_api); ?>"><?php esc_html_e('Отозвать', 'wp-olympiad'); ?></button>
                                        <?php endif; ?>
                                    </div>
                                    <p class="description" id="wpo-api-status">
                                        <?php if ($api_key !== ''): ?>
                                            <?php
                                            $created_str = $api_key_created !== '' ? mysql2date('d.m.Y H:i', $api_key_created) : '—';
                                            $last_used_str = $api_last_used !== '' ? mysql2date('d.m.Y H:i', $api_last_used) : __('ещё не использовался', 'wp-olympiad');
                                            ?>
                                            <?php echo wp_kses_post(sprintf(
                                                /* translators: 1: дата создания ключа, 2: дата последнего использования */
                                                __('Создан: <strong>%1$s</strong> · Последнее использование: <strong>%2$s</strong>', 'wp-olympiad'),
                                                esc_html($created_str),
                                                esc_html($last_used_str)
                                            )); ?>
                                        <?php else: ?>
                                            <?php esc_html_e('Ключ ещё не сгенерирован — внешние запросы к API этой олимпиады недоступны.', 'wp-olympiad'); ?>
                                        <?php endif; ?>
                                    </p>
                                    <p class="description"><?php esc_html_e('Ключ привязан только к этой олимпиаде. «Перевыпустить» мгновенно создаёт новый ключ и отключает старый; «Отозвать» полностью закрывает доступ.', 'wp-olympiad'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Эндпоинты:', 'wp-olympiad'); ?></th>
                                <td>
                                    <table class="widefat striped wpo-api-endpoints">
                                        <thead>
                                            <tr>
                                                <th style="width:70px;"><?php esc_html_e('Метод', 'wp-olympiad'); ?></th>
                                                <th style="width:250px;"><?php esc_html_e('Путь', 'wp-olympiad'); ?></th>
                                                <th><?php esc_html_e('Назначение', 'wp-olympiad'); ?></th>
                                                <th style="width:60px;"></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($api_endpoints_catalog as $endpoint): ?>
                                                <tr>
                                                    <td><span class="wpo-method wpo-method-<?php echo esc_attr(strtolower((string)$endpoint['method'])); ?>"><?php echo esc_html((string)$endpoint['method']); ?></span></td>
                                                    <td><code><?php echo esc_html((string)$endpoint['path']); ?></code></td>
                                                    <td><?php echo esc_html((string)$endpoint['title']); ?></td>
                                                    <td style="text-align:right;">
                                                        <button type="button" class="button button-small wpo-copy-endpoint" data-copy="<?php echo esc_attr((string)$endpoint['url']); ?>" title="<?php esc_attr_e('Скопировать адрес', 'wp-olympiad'); ?>"><span class="dashicons dashicons-clipboard" style="font-size:15px;line-height:1.7;"></span></button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                    <p class="description">
                                        <?php echo wp_kses_post(sprintf(
                                            /* translators: %s: базовый адрес REST API */
                                            __('Базовый адрес: %s. Вместо {id} подставьте ID олимпиады, вместо {ref} — ID попытки или её токен.', 'wp-olympiad'),
                                            '<code id="wpo-api-base">' . esc_html($api_base_url) . '</code>'
                                        )); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Проверка подключения:', 'wp-olympiad'); ?></th>
                                <td>
                                    <button type="button" class="button" id="wpo-api-test" data-quiz-id="<?php echo esc_attr((string)$quiz_id_for_api); ?>">🔌 <?php esc_html_e('Проверить API', 'wp-olympiad'); ?></button>
                                    <span class="spinner" id="wpo-api-test-spinner" style="float:none;margin:0 0 0 6px;"></span>
                                    <div class="wpo-api-result" id="wpo-api-test-result" style="display:none;"></div>
                                    <p class="description"><?php esc_html_e('Отправит запрос GET /external/ping с текущим ключом так же, как это сделает внешняя система.', 'wp-olympiad'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Пример запроса:', 'wp-olympiad'); ?></th>
                                <td>
                                    <pre class="wpo-api-example" id="wpo-api-example"><?php
                                    $api_example = "curl -X POST '" . $api_base_url . "/start' \\\n"
                                        . "  -H 'X-API-Key: <КЛЮЧ_API>' \\\n"
                                        . "  -H 'Content-Type: application/json' \\\n"
                                        . "  -d '{\"quiz_id\": " . $quiz_id_for_api . ", \"participant_name\": \"Иванов Иван Иванович\", \"participant_email\": \"ivanov@example.com\", \"participant_org\": \"Лицей №1, 10 класс\"}'";
                                    echo esc_html($api_example);
                                    ?></pre>
                                    <button type="button" class="button button-small" id="wpo-api-copy-example">📋 <?php esc_html_e('Скопировать пример с моим ключом', 'wp-olympiad'); ?></button>
                                    <p class="description"><?php esc_html_e('Ответ содержит token для сдачи ответов и attempt_id для получения результата.', 'wp-olympiad'); ?></p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </table>

                    <!-- 3. Исходящие webhook -->
                    <h3 class="wpo-subhead">📤 <?php esc_html_e('Исходящие Webhook-события', 'wp-olympiad'); ?></h3>
                    <p class="description">
                        <?php esc_html_e('При завершении каждой попытки плагин отправляет POST JSON с баллами и данными участника. Запрос подписывается HMAC-SHA256, повторяется до 3 раз при ошибках, а результат доставки фиксируется в журнале.', 'wp-olympiad'); ?>
                    </p>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="webhook_url"><?php esc_html_e('URL внешнего Webhook:', 'wp-olympiad'); ?></label></th>
                            <td>
                                <input type="url" name="webhook_url" id="webhook_url"
                                       value="<?php echo esc_attr($webhook_url); ?>"
                                       data-saved-url="<?php echo esc_attr($webhook_url); ?>"
                                       class="large-text"
                                       placeholder="https://api.myschool.ru/olympiad-webhook или https://hook.eu2.make.com/...">
                                <p class="description"><?php esc_html_e('Событие attempt_completed: баллы, максимум, процент, статус, ФИО, школа, телефон, город, время начала и завершения. Подходит для Telegram-бота, CRM, Google Таблиц и внешней LMS. Пустое поле отключает отправку.', 'wp-olympiad'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Секрет подписи:', 'wp-olympiad'); ?></th>
                            <td>
                                <?php if ($is_new): ?>
                                    <p class="description">💡 <?php esc_html_e('Секрет создаётся автоматически при сохранении олимпиады с указанным URL webhook.', 'wp-olympiad'); ?></p>
                                <?php else: ?>
                                    <div class="wpo-api-key-box">
                                        <input type="text" id="wpo-webhook-secret" class="large-text code wpo-copyable-input" readonly
                                               value="<?php echo esc_attr($webhook_secret !== '' ? \WPOlympiad\Api::mask($webhook_secret) : ''); ?>"
                                               data-secret="<?php echo esc_attr($webhook_secret); ?>"
                                               data-masked="<?php echo esc_attr($webhook_secret !== '' ? \WPOlympiad\Api::mask($webhook_secret) : ''); ?>"
                                               placeholder="<?php esc_attr_e('Секрет появится после сохранения URL', 'wp-olympiad'); ?>"
                                               autocomplete="off" spellcheck="false">
                                        <button type="button" class="button" id="wpo-webhook-secret-toggle" <?php echo $webhook_secret === '' ? 'disabled' : ''; ?>>👁 <?php esc_html_e('Показать', 'wp-olympiad'); ?></button>
                                        <button type="button" class="button" id="wpo-webhook-secret-copy" <?php echo $webhook_secret === '' ? 'disabled' : ''; ?>>📋 <?php esc_html_e('Копировать', 'wp-olympiad'); ?></button>
                                        <button type="button" class="button" id="wpo-webhook-secret-generate" data-quiz-id="<?php echo esc_attr((string)$quiz_id_for_api); ?>">♻️ <?php esc_html_e('Перевыпустить секрет', 'wp-olympiad'); ?></button>
                                    </div>
                                    <p class="description">
                                        <?php echo wp_kses_post(__('Проверяйте подлинность запроса: заголовок <code>X-WPO-Signature</code> должен совпадать с <code>sha256=HMAC-SHA256(«X-WPO-Timestamp.тело_запроса», секрет)</code>. Метка времени <code>X-WPO-Timestamp</code> защищает от повторной отправки перехваченного запроса.', 'wp-olympiad')); ?>
                                    </p>
                                    <pre class="wpo-api-example"><?php
                                    $signature_example = "<?php\n"
                                        . "\$payload  = file_get_contents('php://input');\n"
                                        . "\$ts       = \$_SERVER['HTTP_X_WPO_TIMESTAMP'] ?? '';\n"
                                        . "\$got      = \$_SERVER['HTTP_X_WPO_SIGNATURE'] ?? '';\n"
                                        . "\$expected = 'sha256=' . hash_hmac('sha256', \$ts . '.' . \$payload, 'ВАШ_СЕКРЕТ');\n"
                                        . "if (!hash_equals(\$expected, \$got)) { http_response_code(401); exit; }\n";
                                    echo esc_html($signature_example);
                                    ?></pre>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if (!$is_new): ?>
                            <tr>
                                <th scope="row"><?php esc_html_e('Тестовая отправка:', 'wp-olympiad'); ?></th>
                                <td>
                                    <button type="button" class="button" id="wpo-webhook-test" data-quiz-id="<?php echo esc_attr((string)$quiz_id_for_api); ?>">🚀 <?php esc_html_e('Отправить тестовый webhook', 'wp-olympiad'); ?></button>
                                    <span class="spinner" id="wpo-webhook-test-spinner" style="float:none;margin:0 0 0 6px;"></span>
                                    <div class="wpo-api-result" id="wpo-webhook-test-result" style="display:none;"></div>
                                    <p class="description"><?php esc_html_e('Используется сохранённый URL. Отправляется событие webhook_test с примером данных участника и настоящей подписью — удобно проверить приёмник перед стартом олимпиады.', 'wp-olympiad'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Журнал доставок:', 'wp-olympiad'); ?></th>
                                <td>
                                    <table class="widefat striped wpo-webhook-log" id="wpo-webhook-log">
                                        <thead>
                                            <tr>
                                                <th style="width:145px;"><?php esc_html_e('Время', 'wp-olympiad'); ?></th>
                                                <th><?php esc_html_e('Событие', 'wp-olympiad'); ?></th>
                                                <th style="width:70px;"><?php esc_html_e('Попытка', 'wp-olympiad'); ?></th>
                                                <th style="width:70px;"><?php esc_html_e('HTTP', 'wp-olympiad'); ?></th>
                                                <th style="width:90px;"><?php esc_html_e('Время, мс', 'wp-olympiad'); ?></th>
                                                <th><?php esc_html_e('Результат', 'wp-olympiad'); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody id="wpo-webhook-log-body">
                                            <?php if (empty($webhook_log)): ?>
                                                <tr class="wpo-webhook-log-empty"><td colspan="6"><?php esc_html_e('Доставок пока не было. Завершите попытку или отправьте тестовый webhook.', 'wp-olympiad'); ?></td></tr>
                                            <?php else: ?>
                                                <?php foreach ($webhook_log as $entry): ?>
                                                    <?php
                                                    $ok = $entry['ok'] ?? null;
                                                    $state_class = $ok === true ? 'wpo-log-ok' : ($ok === false ? 'wpo-log-fail' : 'wpo-log-queued');
                                                    $state_label = $ok === true ? __('✓ Доставлено', 'wp-olympiad') : ($ok === false ? __('✕ Ошибка', 'wp-olympiad') : __('… В очереди', 'wp-olympiad'));
                                                    $entry_time = (string)($entry['time'] ?? '');
                                                    $entry_time_str = $entry_time !== '' ? mysql2date('d.m.Y H:i:s', $entry_time) : '—';
                                                    ?>
                                                    <tr>
                                                        <td><?php echo esc_html($entry_time_str); ?></td>
                                                        <td><code><?php echo esc_html((string)($entry['event'] ?? '')); ?></code><?php if (!empty($entry['test'])): ?> <span class="wpo-badge"><?php esc_html_e('тест', 'wp-olympiad'); ?></span><?php endif; ?></td>
                                                        <td><?php echo esc_html((string)($entry['try'] ?? 1)); ?></td>
                                                        <td><?php echo esc_html(((int)($entry['code'] ?? 0)) > 0 ? (string)(int)$entry['code'] : '—'); ?></td>
                                                        <td><?php echo esc_html((string)(int)($entry['duration_ms'] ?? 0)); ?></td>
                                                        <td>
                                                            <span class="<?php echo esc_attr($state_class); ?>"><?php echo esc_html($state_label); ?></span><br>
                                                            <span class="description"><?php echo esc_html((string)($entry['message'] ?? '')); ?></span>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                    <p style="margin-top:8px;">
                                        <button type="button" class="button button-link-delete" id="wpo-webhook-clear-log" data-quiz-id="<?php echo esc_attr((string)$quiz_id_for_api); ?>"><?php esc_html_e('Очистить журнал', 'wp-olympiad'); ?></button>
                                        <span class="description"><?php esc_html_e('Хранятся последние 12 доставок.', 'wp-olympiad'); ?></span>
                                    </p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </table>
                </div>

                <!-- Подключение к сетевой папке -->
                <div class="wpo-card" id="wpo-network-export-card">
                    <h2>📁 <?php esc_html_e('Подключение к сетевой папке', 'wp-olympiad'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('Автоматическая или ручная прямая выгрузка официальных протоколов (CSV), ответов участников и ключей заданий в сетевую папку (без архивации).', 'wp-olympiad'); ?>
                    </p>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Включить экспорт в сеть:', 'wp-olympiad'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="enable_network_export" id="enable_network_export" value="1" <?php checked($enable_network_export, 1); ?>>
                                    <strong><?php esc_html_e('Активировать возможность выгрузки ответов в сетевую папку', 'wp-olympiad'); ?></strong>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="network_folder_type"><?php esc_html_e('Тип сетевого подключения:', 'wp-olympiad'); ?></label></th>
                            <td>
                                <select name="network_folder_type" id="network_folder_type" class="regular-text">
                                    <option value="auto" <?php selected($network_folder_type, 'auto'); ?>><?php esc_html_e('Автоопределение по пути/протоколу', 'wp-olympiad'); ?></option>
                                    <option value="local" <?php selected($network_folder_type, 'local'); ?>><?php esc_html_e('Локальный путь / Примонтированная папка (NFS / CIFS / D:\Share)', 'wp-olympiad'); ?></option>
                                    <option value="webdav" <?php selected($network_folder_type, 'webdav'); ?>><?php esc_html_e('WebDAV / Облачное сетевое хранилище (Nextcloud, Synology, QNAP)', 'wp-olympiad'); ?></option>
                                    <option value="ftp" <?php selected($network_folder_type, 'ftp'); ?>><?php esc_html_e('FTP / FTPS сервер', 'wp-olympiad'); ?></option>
                                    <option value="smb" <?php selected($network_folder_type, 'smb'); ?>><?php esc_html_e('SMB / Windows Share (через прямой UNC путь или smbclient)', 'wp-olympiad'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="network_folder_path"><?php esc_html_e('Путь к папке или URL хранилища:', 'wp-olympiad'); ?></label></th>
                            <td>
                                <input type="text" name="network_folder_path" id="network_folder_path" value="<?php echo esc_attr($network_folder_path); ?>" class="large-text code" placeholder="<?php echo esc_attr($default_network_path); ?>">
                                <p class="description">
                                    <?php esc_html_e('По умолчанию используется готовая папка в директории загрузок WordPress. Также поддерживаются пути: <code>/mnt/shares/olympiad</code>, <code>\\\\192.168.1.100\\Olympiad\\Results</code>, <code>https://nas.myschool.local/remote.php/webdav/results/</code>, <code>ftp://192.168.1.50/shares/olympiad/</code>', 'wp-olympiad'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="network_folder_user"><?php esc_html_e('Пользователь / Логин сети:', 'wp-olympiad'); ?></label></th>
                            <td>
                                <input type="text" name="network_folder_user" id="network_folder_user" value="<?php echo esc_attr($network_folder_user); ?>" class="regular-text" placeholder="например: olympiad_admin">
                                <p class="description"><?php esc_html_e('Укажите логин для доступа к сетевой папке WebDAV, FTP или SMB (если для папки настроена авторизация).', 'wp-olympiad'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="network_folder_password"><?php esc_html_e('Пароль к сетевой папке:', 'wp-olympiad'); ?></label></th>
                            <td>
                                <div style="display:flex; gap:6px; align-items:center;">
                                    <input type="password" name="network_folder_password" id="network_folder_password" value="<?php echo esc_attr($network_folder_password); ?>" class="regular-text" placeholder="пароль для сетевого доступа">
                                    <button type="button" class="button wpo-toggle-pwd" data-target="network_folder_password" title="<?php esc_attr_e('Показать / скрыть пароль', 'wp-olympiad'); ?>">👁️</button>
                                </div>
                                <p class="description"><?php esc_html_e('Пароль учетной записи сетевой папки (хранится безопасно в БД WordPress).', 'wp-olympiad'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Автоматический экспорт:', 'wp-olympiad'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="network_auto_export" value="1" <?php checked($network_auto_export, 1); ?>>
                                    <?php esc_html_e('Автоматически выгружать файлы протоколов (CSV) напрямую в сетевую папку при каждом завершении тестирования участником', 'wp-olympiad'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Автоматический импорт вопросов:', 'wp-olympiad'); ?></th>
                            <td>
                                <label style="display:block; margin-bottom:8px;">
                                    <input type="checkbox" name="network_auto_import" id="network_auto_import" value="1" <?php checked($network_auto_import, 1); ?>>
                                    <strong><?php esc_html_e('Автоматически импортировать задания из сетевой папки', 'wp-olympiad'); ?></strong>
                                </label>
                                <p class="description">
                                    <?php esc_html_e('Плагин будет автоматически проверять сетевую папку (подкаталог import/ или корень) на наличие обновлённых файлов заданий (Excel .xlsm/.xlsx, .csv, .json) и синхронизировать вопросы олимпиады.', 'wp-olympiad'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr class="wpo-auto-import-row">
                            <th scope="row"><label for="network_auto_import_mode"><?php esc_html_e('Режим импорта заданий:', 'wp-olympiad'); ?></label></th>
                            <td>
                                <select name="network_auto_import_mode" id="network_auto_import_mode" class="regular-text">
                                    <option value="append" <?php selected($network_auto_import_mode, 'append'); ?>><?php esc_html_e('Добавлять к существующим вопросам (Append)', 'wp-olympiad'); ?></option>
                                    <option value="replace" <?php selected($network_auto_import_mode, 'replace'); ?>><?php esc_html_e('Заменять все вопросы олимпиады (Replace)', 'wp-olympiad'); ?></option>
                                </select>
                                <p class="description"><?php esc_html_e('При режиме «Заменять» перед импортом все старые вопросы удаляются, и список формируется заново из файла.', 'wp-olympiad'); ?></p>
                            </td>
                        </tr>
                        <tr class="wpo-auto-import-row">
                            <th scope="row"><label for="network_auto_import_interval"><?php esc_html_e('Периодичность проверки папки:', 'wp-olympiad'); ?></label></th>
                            <td>
                                <select name="network_auto_import_interval" id="network_auto_import_interval" class="regular-text">
                                    <option value="every_minute" <?php selected($network_auto_import_interval, 'every_minute'); ?>><?php esc_html_e('⏱️ Каждую минуту (1 минута / WP-Cron)', 'wp-olympiad'); ?></option>
                                    <option value="hourly" <?php selected($network_auto_import_interval, 'hourly'); ?>><?php esc_html_e('Каждый час (WP-Cron)', 'wp-olympiad'); ?></option>
                                    <option value="twicedaily" <?php selected($network_auto_import_interval, 'twicedaily'); ?>><?php esc_html_e('Дважды в день (WP-Cron)', 'wp-olympiad'); ?></option>
                                    <option value="daily" <?php selected($network_auto_import_interval, 'daily'); ?>><?php esc_html_e('Раз в день (WP-Cron)', 'wp-olympiad'); ?></option>
                                </select>
                                <p class="description"><?php esc_html_e('Синхронизация происходит только если файл в сетевой папке изменился (по содержимому или хэшу).', 'wp-olympiad'); ?></p>
                            </td>
                        </tr>
                        <tr class="wpo-auto-import-row">
                            <th scope="row"><label for="network_auto_import_file"><?php esc_html_e('Имя файла заданий (необязательно):', 'wp-olympiad'); ?></label></th>
                            <td>
                                <input type="text" name="network_auto_import_file" id="network_auto_import_file" value="<?php echo esc_attr($network_auto_import_file); ?>" class="regular-text code" placeholder="Мои_вопросы.xlsx или пусто для автопоиска">
                                <p class="description"><?php esc_html_e('Оставьте пустым для автопоиска вашего файла заданий (.xlsx, .xlsm, .csv или .json).', 'wp-olympiad'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Фоновая синхронизация:', 'wp-olympiad'); ?></th>
                            <td><?php $wpo_sync_compact = false; require WP_OLYMPIAD_PATH . 'admin/views/network-sync-status.php'; ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Программа для вопросов:', 'wp-olympiad'); ?></th>
                            <td><?php $wpo_editor_compact = false; require WP_OLYMPIAD_PATH . 'admin/views/desktop-editor-panel.php'; ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Тестирование и запуск:', 'wp-olympiad'); ?></th>
                            <td>
                                <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                                    <button type="button" class="button" id="wpo-btn-test-network">
                                        🔍 <?php esc_html_e('Проверить доступ к папке', 'wp-olympiad'); ?>
                                    </button>
                                    <button type="button" class="button button-secondary" id="wpo-btn-deploy-network-templates" data-quiz-id="<?php echo esc_attr((string)($quiz ? $quiz->id : 0)); ?>" title="<?php esc_attr_e('Поместить шаблон Excel (.xlsx) и инструкцию в сетевую папку', 'wp-olympiad'); ?>">
                                        📥 <?php esc_html_e('Поместить шаблон Excel в папку', 'wp-olympiad'); ?>
                                    </button>
                                    <?php if (!$is_new): ?>
                                        <button type="button" class="button button-secondary" id="wpo-btn-validate-import" data-quiz-id="<?php echo esc_attr((string)$quiz->id); ?>" title="<?php esc_attr_e('Разобрать файл заданий и показать отчёт по каждой строке, не изменяя базу', 'wp-olympiad'); ?>">
                                            ✔ <?php esc_html_e('Проверить файл заданий', 'wp-olympiad'); ?>
                                        </button>
                                        <button type="button" class="button button-secondary" id="wpo-btn-run-network-import" data-quiz-id="<?php echo esc_attr((string)$quiz->id); ?>">
                                            📥 <?php esc_html_e('Импортировать вопросы из папки сейчас', 'wp-olympiad'); ?>
                                        </button>
                                        <button type="button" class="button button-secondary" id="wpo-btn-export-questions-network" data-quiz-id="<?php echo esc_attr((string)$quiz->id); ?>" title="<?php esc_attr_e('Экспортировать все задания олимпиады в сетевую папку (каталог вопросы/)', 'wp-olympiad'); ?>">
                                            📤 <?php esc_html_e('Экспортировать вопросы в сетевую папку', 'wp-olympiad'); ?>
                                        </button>
                                        <button type="button" class="button button-primary" id="wpo-btn-run-network-export" data-quiz-id="<?php echo esc_attr((string)$quiz->id); ?>">
                                            📤 <?php esc_html_e('Экспортировать в сетевую папку сейчас', 'wp-olympiad'); ?>
                                        </button>
                                    <?php endif; ?>
                                    <span id="wpo-network-test-spinner" class="spinner" style="float:none; margin:0;"></span>
                                </div>
                                <div id="wpo-network-test-message" style="margin-top:10px; display:none; padding:10px 14px; border-radius:6px; font-weight:500;"></div>

                                <div id="wpo-import-report" style="margin-top:12px; display:none;"></div>

                                <?php if (!empty($last_network_export) && is_array($last_network_export)): ?>
                                    <div style="margin-top:12px; padding:10px 14px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:6px; font-size:12px; color:#166534;">
                                        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:4px;">
                                            <strong>🕒 <?php esc_html_e('Последний успешный экспорт:', 'wp-olympiad'); ?> <?php echo esc_html($last_network_export['timestamp'] ?? ''); ?></strong>
                                            <?php if (!empty($last_network_export['primary_file'])): ?>
                                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?wpo_action=download_network_file&quiz_id=' . $quiz->id . '&file=' . urlencode($last_network_export['primary_file'])), 'wpo_download_network_' . $quiz->id)); ?>" class="button button-small" style="vertical-align:middle;">
                                                    📥 <?php esc_html_e('Скачать файл', 'wp-olympiad'); ?>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <code><?php echo esc_html($last_network_export['destination'] ?? ''); ?></code>
                                            <?php if (!empty($last_network_export['primary_file'])): ?>
                                                / <strong><code><?php echo esc_html($last_network_export['primary_file']); ?></code></strong>
                                            <?php endif; ?>
                                            (<?php echo esc_html(sprintf('%.1f КБ', ($last_network_export['size'] ?? 0) / 1024)); ?>)
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($last_network_import) && is_array($last_network_import)): ?>
                                    <div style="margin-top:10px; padding:10px 14px; background:#f0f9ff; border:1px solid #bae6fd; border-radius:6px; font-size:12px; color:#0369a1;">
                                        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:4px;">
                                            <strong>📥 <?php esc_html_e('Последний импорт вопросов из сети:', 'wp-olympiad'); ?> <?php echo esc_html($last_network_import['timestamp'] ?? ''); ?></strong>
                                            <span style="display:inline-block; padding:2px 8px; border-radius:10px; font-weight:600; font-size:11px; background:<?php echo ($last_network_import['status'] ?? '') === 'success' ? '#dcfce7; color:#15803d;' : '#fee2e2; color:#b91c1c;'; ?>">
                                                <?php echo ($last_network_import['status'] ?? '') === 'success' ? esc_html__('Успешно', 'wp-olympiad') : esc_html__('Ошибка', 'wp-olympiad'); ?>
                                            </span>
                                        </div>
                                        <div>
                                            <?php esc_html_e('Файл:', 'wp-olympiad'); ?> <strong><code><?php echo esc_html($last_network_import['filename'] ?? ''); ?></code></strong> |
                                            <?php esc_html_e('Импортировано заданий:', 'wp-olympiad'); ?> <strong><?php echo esc_html((string)($last_network_import['imported_count'] ?? 0)); ?></strong> |
                                            <?php esc_html_e('Всего в олимпиаде:', 'wp-olympiad'); ?> <strong><?php echo esc_html((string)($last_network_import['total_count'] ?? 0)); ?></strong> |
                                            <?php esc_html_e('Режим:', 'wp-olympiad'); ?> <em><?php echo ($last_network_import['mode'] ?? 'append') === 'replace' ? esc_html__('Замена', 'wp-olympiad') : esc_html__('Добавление', 'wp-olympiad'); ?></em>
                                            <?php if (!empty($last_network_import['message'])): ?>
                                                <div style="margin-top:4px; color:#334155;"><?php echo esc_html($last_network_import['message']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Right Sidebar Column -->
            <div class="wpo-side-col">
                <div class="wpo-card">
                    <h3><?php esc_html_e('Публикация', 'wp-olympiad'); ?></h3>
                    <div class="wpo-field">
                        <label for="wpo-status"><?php esc_html_e('Статус:', 'wp-olympiad'); ?></label>
                        <select name="status" id="wpo-status" class="widefat">
                            <option value="publish" <?php selected($status, 'publish'); ?>><?php esc_html_e('Опубликовано', 'wp-olympiad'); ?></option>
                            <option value="draft" <?php selected($status, 'draft'); ?>><?php esc_html_e('Черновик', 'wp-olympiad'); ?></option>
                        </select>
                    </div>

                    <div style="margin-top: 15px;">
                        <button type="submit" class="button button-primary button-large widefat">
                            💾 <?php esc_html_e('Сохранить олимпиаду', 'wp-olympiad'); ?>
                        </button>
                    </div>
                </div>

                <div class="wpo-card">
                    <h3><?php esc_html_e('Показ результатов', 'wp-olympiad'); ?></h3>
                    <div class="wpo-field">
                        <label>
                            <input type="radio" name="show_results_mode" value="immediate" <?php checked($show_results_mode, 'immediate'); ?>>
                            <strong><?php esc_html_e('Сразу показать всё', 'wp-olympiad'); ?></strong>
                        </label>
                        <p class="description" style="margin-bottom: 8px;"><?php esc_html_e('Баллы, ошибки, правильные ответы и пояснения (для тренировок).', 'wp-olympiad'); ?></p>

                        <label>
                            <input type="radio" name="show_results_mode" value="score_only" <?php checked($show_results_mode, 'score_only'); ?>>
                            <strong><?php esc_html_e('Только итоговый балл', 'wp-olympiad'); ?></strong>
                        </label>
                        <p class="description" style="margin-bottom: 8px;"><?php esc_html_e('Скрыть правильные ответы, чтобы их не сливали другим участникам.', 'wp-olympiad'); ?></p>

                        <label>
                            <input type="radio" name="show_results_mode" value="after_end" <?php checked($show_results_mode, 'after_end'); ?>>
                            <strong><?php esc_html_e('После окончания тура', 'wp-olympiad'); ?></strong>
                        </label>
                        <p class="description" style="margin-bottom: 8px;"><?php esc_html_e('Публикация решений только после даты окончания олимпиады.', 'wp-olympiad'); ?></p>

                        <label>
                            <input type="radio" name="show_results_mode" value="manual" <?php checked($show_results_mode, 'manual'); ?>>
                            <strong><?php esc_html_e('Ручная проверка жюри', 'wp-olympiad'); ?></strong>
                        </label>
                        <p class="description" style="margin-bottom: 8px;"><?php esc_html_e('Для олимпиад с открытыми задачами/эссе.', 'wp-olympiad'); ?></p>

                        <label>
                            <input type="radio" name="show_results_mode" value="none" <?php checked($show_results_mode, 'none'); ?>>
                            <strong style="color:#b91c1c;">🚫 <?php esc_html_e('Отключить показ результатов в конце', 'wp-olympiad'); ?></strong>
                        </label>
                        <p class="description" style="margin-bottom: 8px;"><?php esc_html_e('Полностью скрыть баллы, проценты и разбор заданий. Участник увидит только сообщение об успешной сдаче работы.', 'wp-olympiad'); ?></p>

                        <div class="wpo-field" style="margin-top:14px; border-top:1px solid #e2e8f0; padding-top:10px;">
                            <label for="completion_message"><strong><?php esc_html_e('Текст сообщения после завершения:', 'wp-olympiad'); ?></strong></label>
                            <textarea name="completion_message" id="completion_message" rows="3" class="widefat" placeholder="<?php esc_attr_e('Спасибо за участие! Ваши ответы успешно приняты и сохранены в системе.', 'wp-olympiad'); ?>"><?php echo esc_textarea($completion_message); ?></textarea>
                            <p class="description"><?php esc_html_e('Отображается на завершающем экране, если показ результатов отключен или работа принята.', 'wp-olympiad'); ?></p>
                        </div>
                    </div>
                </div>

                <div class="wpo-card">
                    <h3><?php esc_html_e('Рандомизация и отображение', 'wp-olympiad'); ?></h3>
                    <p><label><input type="checkbox" name="shuffle_answers" value="1" <?php checked($shuffle_answers, 1); ?>> <?php esc_html_e('Перемешивать варианты ответов', 'wp-olympiad'); ?></label></p>
                    <p><label><input type="checkbox" name="show_question_points" value="1" <?php checked($show_question_points, 1); ?>> <?php esc_html_e('Показывать баллы за задания в тесте', 'wp-olympiad'); ?></label></p>
                    <p class="description" style="margin-left:22px; font-size:12px; color:#64748b; margin-top:-6px;"><?php esc_html_e('Если снять галочку, стоимость вопросов (баллы) скрывается от участника во время выполнения теста.', 'wp-olympiad'); ?></p>
                    <hr>
                    <p><label><input type="checkbox" name="require_org" value="1" <?php checked($require_org, 1); ?>> <?php esc_html_e('Требовать школу / класс / ВУЗ', 'wp-olympiad'); ?></label></p>
                    <p><label><input type="checkbox" name="require_email" value="1" <?php checked($require_email, 1); ?>> <?php esc_html_e('Требовать E-mail участника', 'wp-olympiad'); ?></label></p>
                </div>
            </div>
        </div>

        <?php
        // ---- Appearance / Styling of the public testing page ----
        $style          = $is_new ? \WPOlympiad\Style::normalize([]) : \WPOlympiad\Style::get($quiz);
        $style_presets  = \WPOlympiad\Style::presets();
        $style_fonts    = \WPOlympiad\Style::fonts();
        $style_shadows  = \WPOlympiad\Style::shadows();
        $style_buttons  = \WPOlympiad\Style::button_styles();
        $style_canvas_fits      = \WPOlympiad\Style::canvas_fits();
        $style_canvas_positions = \WPOlympiad\Style::canvas_positions();
        $style_colors   = [
            'primary'       => ['Основной цвет (кнопки, акценты)', '#2563eb'],
            'primary_hover' => ['Основной цвет при наведении', '#1d4ed8'],
            'accent'        => ['Дополнительный цвет', '#64748b'],
            'success'       => ['Успех / верный ответ', '#10b981'],
            'danger'        => ['Ошибка / неверный ответ', '#ef4444'],
            'warning'       => ['Предупреждение', '#f59e0b'],
            'bg'            => ['Фон карточек', '#ffffff'],
            'surface'       => ['Фон полей и плашек', '#f8fafc'],
            'border'        => ['Цвет рамок и разделителей', '#e2e8f0'],
            'text'          => ['Основной текст', '#0f172a'],
            'text_muted'    => ['Второстепенный текст', '#64748b'],
        ];
        ?>
        <div class="wpo-card" id="wpo-style-card">
            <h2>🎨 <?php esc_html_e('Стилистика страницы тестирования', 'wp-olympiad'); ?></h2>
            <p class="description">
                <?php esc_html_e('Настройте внешний вид страницы, которую видят участники: цвета, шрифт, скругления, тени, стиль кнопок и собственный CSS. Все изменения применяются только к этой олимпиаде и не затрагивают тему сайта.', 'wp-olympiad'); ?>
            </p>

            <input type="hidden" name="style_preset" id="wpo-style-preset" value="<?php echo esc_attr((string)$style['preset']); ?>">

            <div class="wpo-style-presets" id="wpo-style-presets">
                <?php foreach ($style_presets as $preset_key => $preset): ?>
                    <button type="button"
                            class="wpo-style-preset<?php echo $style['preset'] === $preset_key ? ' is-active' : ''; ?>"
                            data-preset="<?php echo esc_attr($preset_key); ?>"
                            data-colors="<?php echo esc_attr((string)wp_json_encode($preset)); ?>"
                            style="--preset-bg:<?php echo esc_attr($preset['bg']); ?>;--preset-primary:<?php echo esc_attr($preset['primary']); ?>;--preset-accent:<?php echo esc_attr($preset['primary_hover']); ?>;">
                        <span class="wpo-style-preset-swatch" aria-hidden="true"></span>
                        <span class="wpo-style-preset-name"><?php echo esc_html($preset['label']); ?></span>
                    </button>
                <?php endforeach; ?>
                <button type="button" class="wpo-style-preset<?php echo $style['preset'] === 'custom' ? ' is-active' : ''; ?>" data-preset="custom" data-colors="{}">
                    <span class="wpo-style-preset-swatch wpo-style-preset-swatch-custom" aria-hidden="true"></span>
                    <span class="wpo-style-preset-name">⚙️ Своя палитра</span>
                </button>
            </div>

            <div class="wpo-style-body">
                <div class="wpo-style-controls">

                    <h3 class="wpo-style-h3">🎨 <?php esc_html_e('Цветовая палитра', 'wp-olympiad'); ?></h3>
                    <div class="wpo-style-colors">
                        <?php foreach ($style_colors as $color_key => $meta): ?>
                            <div class="wpo-style-color-row">
                                <label for="wpo-style-<?php echo esc_attr($color_key); ?>"><?php echo esc_html($meta[0]); ?></label>
                                <div class="wpo-style-color-inputs">
                                    <input type="color"
                                           id="wpo-style-<?php echo esc_attr($color_key); ?>"
                                           name="style_<?php echo esc_attr($color_key); ?>"
                                           value="<?php echo esc_attr((string)$style[$color_key]); ?>"
                                           data-wpo-style="<?php echo esc_attr($color_key); ?>">
                                    <input type="text"
                                           class="wpo-style-hex"
                                           value="<?php echo esc_attr((string)$style[$color_key]); ?>"
                                           data-hex-for="<?php echo esc_attr($color_key); ?>"
                                           maxlength="7"
                                           spellcheck="false"
                                           aria-label="<?php esc_attr_e('HEX-код цвета', 'wp-olympiad'); ?>">
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <h3 class="wpo-style-h3">🔤 <?php esc_html_e('Шрифт и типографика', 'wp-olympiad'); ?></h3>
                    <table class="form-table wpo-style-table">
                        <tr>
                            <th scope="row"><label for="wpo-style-font"><?php esc_html_e('Шрифт страницы:', 'wp-olympiad'); ?></label></th>
                            <td>
                                <select name="style_font" id="wpo-style-font" data-wpo-style="font" class="regular-text">
                                    <?php foreach ($style_fonts as $font_key => $font): ?>
                                        <option value="<?php echo esc_attr($font_key); ?>" data-stack="<?php echo esc_attr($font['stack']); ?>" <?php selected((string)$style['font'], $font_key); ?>><?php echo esc_html($font['label']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php esc_html_e('«Как в теме сайта» — шрифт наследуется от вашей WordPress-темы.', 'wp-olympiad'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wpo-style-font-size"><?php esc_html_e('Размер основного текста:', 'wp-olympiad'); ?></label></th>
                            <td>
                                <div class="wpo-style-range">
                                    <input type="range" name="style_font_size" id="wpo-style-font-size" min="12" max="24" step="1" value="<?php echo esc_attr((string)$style['font_size']); ?>" data-wpo-style="font_size" data-unit="px">
                                    <output><?php echo esc_html((string)$style['font_size']); ?> px</output>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wpo-style-title-size"><?php esc_html_e('Размер заголовка:', 'wp-olympiad'); ?></label></th>
                            <td>
                                <div class="wpo-style-range">
                                    <input type="range" name="style_title_size" id="wpo-style-title-size" min="18" max="52" step="1" value="<?php echo esc_attr((string)$style['title_size']); ?>" data-wpo-style="title_size" data-unit="px">
                                    <output><?php echo esc_html((string)$style['title_size']); ?> px</output>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wpo-style-line-height"><?php esc_html_e('Межстрочный интервал:', 'wp-olympiad'); ?></label></th>
                            <td>
                                <div class="wpo-style-range">
                                    <input type="range" name="style_line_height" id="wpo-style-line-height" min="1.1" max="2.2" step="0.05" value="<?php echo esc_attr((string)$style['line_height']); ?>" data-wpo-style="line_height">
                                    <output><?php echo esc_html((string)$style['line_height']); ?></output>
                                </div>
                            </td>
                        </tr>
                    </table>

                    <h3 class="wpo-style-h3">📐 <?php esc_html_e('Размеры, форма и кнопки', 'wp-olympiad'); ?></h3>
                    <table class="form-table wpo-style-table">
                        <tr>
                            <th scope="row"><label for="wpo-style-max-width"><?php esc_html_e('Ширина рабочей области:', 'wp-olympiad'); ?></label></th>
                            <td>
                                <div class="wpo-style-range">
                                    <input type="range" name="style_max_width" id="wpo-style-max-width" min="480" max="1600" step="10" value="<?php echo esc_attr((string)$style['max_width']); ?>" data-wpo-style="max_width" data-unit="px">
                                    <output><?php echo esc_html((string)$style['max_width']); ?> px</output>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wpo-style-radius"><?php esc_html_e('Скругление карточек:', 'wp-olympiad'); ?></label></th>
                            <td>
                                <div class="wpo-style-range">
                                    <input type="range" name="style_radius" id="wpo-style-radius" min="0" max="40" step="1" value="<?php echo esc_attr((string)$style['radius']); ?>" data-wpo-style="radius" data-unit="px">
                                    <output><?php echo esc_html((string)$style['radius']); ?> px</output>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wpo-style-card-padding"><?php esc_html_e('Внутренние отступы карточки:', 'wp-olympiad'); ?></label></th>
                            <td>
                                <div class="wpo-style-range">
                                    <input type="range" name="style_card_padding" id="wpo-style-card-padding" min="8" max="64" step="2" value="<?php echo esc_attr((string)$style['card_padding']); ?>" data-wpo-style="card_padding" data-unit="px">
                                    <output><?php echo esc_html((string)$style['card_padding']); ?> px</output>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wpo-style-shadow"><?php esc_html_e('Тень карточек:', 'wp-olympiad'); ?></label></th>
                            <td>
                                <select name="style_shadow" id="wpo-style-shadow" data-wpo-style="shadow" class="regular-text">
                                    <?php foreach ($style_shadows as $shadow_key => $shadow): ?>
                                        <option value="<?php echo esc_attr($shadow_key); ?>" data-value="<?php echo esc_attr($shadow['value']); ?>" <?php selected((string)$style['shadow'], $shadow_key); ?>><?php echo esc_html($shadow['label']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wpo-style-button-style"><?php esc_html_e('Стиль основных кнопок:', 'wp-olympiad'); ?></label></th>
                            <td>
                                <select name="style_button_style" id="wpo-style-button-style" data-wpo-style="button_style" class="regular-text">
                                    <?php foreach ($style_buttons as $button_key => $button_label): ?>
                                        <option value="<?php echo esc_attr($button_key); ?>" <?php selected((string)$style['button_style'], $button_key); ?>><?php echo esc_html($button_label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wpo-style-button-radius"><?php esc_html_e('Скругление кнопок:', 'wp-olympiad'); ?></label></th>
                            <td>
                                <div class="wpo-style-range">
                                    <input type="range" name="style_button_radius" id="wpo-style-button-radius" min="0" max="40" step="1" value="<?php echo esc_attr((string)$style['button_radius']); ?>" data-wpo-style="button_radius" data-unit="px">
                                    <output><?php echo esc_html((string)$style['button_radius']); ?> px</output>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Фон страницы (самый дальний план):', 'wp-olympiad'); ?></th>
                            <td>
                                <label style="display:block; margin-bottom:6px;">
                                    <input type="checkbox" name="style_canvas_bg_enabled" value="1" id="wpo-style-canvas-enabled" data-wpo-style="canvas_enabled" <?php checked(!empty($style['canvas_bg']) || !empty($style['canvas_image'])); ?>>
                                    <?php esc_html_e('Показывать фон страницы позади карточки теста', 'wp-olympiad'); ?>
                                </label>
                                <p class="description" style="margin:0 0 8px;">
                                    <?php esc_html_e('Цвет и/или картинка выводятся на самом дальнем плане страницы тестирования — за карточкой с заданиями. Другие страницы сайта не меняются.', 'wp-olympiad'); ?>
                                </p>

                                <div class="wpo-style-color-inputs" style="margin-bottom:4px;">
                                    <input type="color" id="wpo-style-canvas-bg" name="style_canvas_bg" value="<?php echo esc_attr($style['canvas_bg'] !== '' ? (string)$style['canvas_bg'] : '#f1f5f9'); ?>" data-wpo-style="canvas_bg">
                                    <input type="text" class="wpo-style-hex" value="<?php echo esc_attr($style['canvas_bg'] !== '' ? (string)$style['canvas_bg'] : '#f1f5f9'); ?>" data-hex-for="canvas_bg" maxlength="7" spellcheck="false">
                                    <span class="description"><?php esc_html_e('Цвет фона (виден под картинкой и вокруг неё)', 'wp-olympiad'); ?></span>
                                </div>

                                <div style="margin-top:12px;">
                                    <label for="wpo-style-canvas-image" style="display:block; font-weight:600; margin-bottom:4px;">
                                        <?php esc_html_e('Картинка на фоне страницы', 'wp-olympiad'); ?>
                                    </label>
                                    <div style="display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
                                        <input type="text" id="wpo-style-canvas-image" name="style_canvas_image" class="regular-text" data-wpo-style="canvas_image" value="<?php echo esc_attr((string)$style['canvas_image']); ?>" placeholder="https://example.com/fon.jpg" spellcheck="false">
                                        <button type="button" class="button" id="wpo-style-canvas-image-pick">🖼 <?php esc_html_e('Выбрать из медиатеки', 'wp-olympiad'); ?></button>
                                        <button type="button" class="button-link" id="wpo-style-canvas-image-clear"><?php esc_html_e('Убрать', 'wp-olympiad'); ?></button>
                                    </div>
                                    <div id="wpo-style-canvas-image-preview" style="margin-top:8px;<?php echo $style['canvas_image'] === '' ? ' display:none;' : ''; ?>">
                                        <img src="<?php echo esc_url((string)$style['canvas_image']); ?>" alt="" style="max-width:240px; max-height:120px; border:1px solid #dcdcde; border-radius:6px; display:block;">
                                    </div>
                                    <p class="description" style="margin:6px 0 0;">
                                        <?php esc_html_e('Можно загрузить свою картинку или вставить прямую ссылку. Подойдут JPG, PNG, WebP, SVG.', 'wp-olympiad'); ?>
                                    </p>
                                </div>

                                <div style="display:flex; gap:18px; flex-wrap:wrap; margin-top:12px;">
                                    <label style="display:block;">
                                        <span style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e('Как вписать картинку', 'wp-olympiad'); ?></span>
                                        <select name="style_canvas_image_fit" id="wpo-style-canvas-image-fit" data-wpo-style="canvas_image_fit" class="regular-text">
                                            <?php foreach ($style_canvas_fits as $fit_key => $fit_label): ?>
                                                <option value="<?php echo esc_attr($fit_key); ?>" <?php selected((string)$style['canvas_image_fit'], $fit_key); ?>><?php echo esc_html($fit_label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label style="display:block;">
                                        <span style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e('Положение картинки', 'wp-olympiad'); ?></span>
                                        <select name="style_canvas_image_position" id="wpo-style-canvas-image-position" data-wpo-style="canvas_image_position" class="regular-text">
                                            <?php foreach ($style_canvas_positions as $pos_key => $pos_label): ?>
                                                <option value="<?php echo esc_attr($pos_key); ?>" <?php selected((string)$style['canvas_image_position'], $pos_key); ?>><?php echo esc_html($pos_label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                </div>

                                <div class="wpo-style-range" style="margin-top:14px; max-width:420px;">
                                    <label for="wpo-style-canvas-overlay" style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e('Затемнение фона (чтобы текст читался):', 'wp-olympiad'); ?></label>
                                    <input type="range" name="style_canvas_overlay" id="wpo-style-canvas-overlay" min="0" max="90" step="5" value="<?php echo esc_attr((string)$style['canvas_overlay']); ?>" data-wpo-style="canvas_overlay" data-unit="%">
                                    <output><?php echo esc_html((string)$style['canvas_overlay']); ?> %</output>
                                </div>

                                <label style="display:block; margin-top:12px;">
                                    <input type="checkbox" name="style_canvas_full_width" value="1" id="wpo-style-canvas-full-width" data-wpo-style="canvas_full_width" <?php checked(!empty($style['canvas_full_width'])); ?>>
                                    <?php esc_html_e('Фон на всю ширину экрана (от края до края), карточка остаётся по центру', 'wp-olympiad'); ?>
                                </label>
                            </td>
                        </tr>
                    </table>

                    <h3 class="wpo-style-h3">🖌 <?php esc_html_e('Собственный CSS', 'wp-olympiad'); ?></h3>
                    <p class="description" style="margin-top:-6px;">
                        <?php esc_html_e('Дополнительные стили для тонкой настройки. Код автоматически ограничивается этой олимпиадой — другие страницы сайта не изменятся.', 'wp-olympiad'); ?>
                    </p>
                    <textarea name="style_custom_css" id="wpo-style-custom-css" rows="7" class="large-text code" data-wpo-style="custom_css" spellcheck="false" placeholder=".wpo-quiz-title { letter-spacing: 0.5px; }"><?php echo esc_textarea((string)$style['custom_css']); ?></textarea>

                    <p style="margin-top:16px;">
                        <button type="button" class="button" id="wpo-style-reset">↺ <?php esc_html_e('Сбросить оформление к классическому', 'wp-olympiad'); ?></button>
                    </p>
                </div>

                <div class="wpo-style-preview-col">
                    <div class="wpo-style-preview-sticky">
                        <div class="wpo-style-preview-head">
                            <strong>👁 <?php esc_html_e('Живой предпросмотр', 'wp-olympiad'); ?></strong>
                            <span class="wpo-style-preview-hint"><?php esc_html_e('Обновляется при изменении настроек', 'wp-olympiad'); ?></span>
                        </div>
                        <div class="wpo-style-preview-frame">
                            <div class="wpo-quiz-wrapper" id="wpo-style-preview">
                                <div class="wpo-card">
                                    <div class="wpo-badge-tag"><?php esc_html_e('Олимпиада / Тестирование', 'wp-olympiad'); ?></div>
                                    <h2 class="wpo-quiz-title"><?php esc_html_e('Пример олимпиады', 'wp-olympiad'); ?></h2>
                                    <div class="wpo-quiz-description"><?php esc_html_e('Так будет выглядеть страница тестирования для участников.', 'wp-olympiad'); ?></div>
                                    <div class="wpo-meta-grid">
                                        <div class="wpo-meta-item">
                                            <span class="wpo-meta-icon">⏱</span>
                                            <div class="wpo-meta-content">
                                                <span class="wpo-meta-label"><?php esc_html_e('Время:', 'wp-olympiad'); ?></span>
                                                <span class="wpo-meta-val">45 <?php esc_html_e('минут', 'wp-olympiad'); ?></span>
                                            </div>
                                        </div>
                                        <div class="wpo-meta-item">
                                            <span class="wpo-meta-icon">🎯</span>
                                            <div class="wpo-meta-content">
                                                <span class="wpo-meta-label"><?php esc_html_e('Макс. балл:', 'wp-olympiad'); ?></span>
                                                <span class="wpo-meta-val">100</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="wpo-form-group">
                                        <label><?php esc_html_e('ФИО Участника:', 'wp-olympiad'); ?> <span class="wpo-required">*</span></label>
                                        <input type="text" class="wpo-input" placeholder="<?php esc_attr_e('Иванов Иван Иванович', 'wp-olympiad'); ?>" tabindex="-1">
                                    </div>
                                    <div class="wpo-anti-cheat-notice">🛡️ <?php esc_html_e('Ведётся контроль переключения вкладок', 'wp-olympiad'); ?></div>
                                    <button type="button" class="wpo-btn wpo-btn-primary wpo-btn-start" tabindex="-1">🚀 <?php esc_html_e('Начать выполнение олимпиады', 'wp-olympiad'); ?></button>

                                    <div class="wpo-runner-header" style="margin-top:24px;">
                                        <div class="wpo-question-counter"><span class="wpo-counter-current">1</span> / <span class="wpo-counter-total">12</span></div>
                                        <div class="wpo-timer-box"><span class="wpo-timer-icon">⏳</span> <span class="wpo-timer-val">44:12</span></div>
                                    </div>
                                    <div class="wpo-progress-wrap"><div class="wpo-progress-bar" style="width:38%;"></div></div>

                                    <div class="wpo-question-prompt" style="margin-top:16px;"><?php esc_html_e('Пример задания с вариантами ответа', 'wp-olympiad'); ?></div>
                                    <div class="wpo-choices-group">
                                        <label class="wpo-choice-card selected"><input type="radio" name="wpo_preview_option" checked tabindex="-1"><span class="wpo-choice-text"><?php esc_html_e('Вариант ответа 1', 'wp-olympiad'); ?></span></label>
                                        <label class="wpo-choice-card"><input type="radio" name="wpo_preview_option" tabindex="-1"><span class="wpo-choice-text"><?php esc_html_e('Вариант ответа 2', 'wp-olympiad'); ?></span></label>
                                    </div>

                                    <div class="wpo-runner-footer" style="margin-top:16px;">
                                        <button type="button" class="wpo-btn wpo-btn-outline" tabindex="-1"><?php esc_html_e('Назад', 'wp-olympiad'); ?></button>
                                        <button type="button" class="wpo-btn wpo-btn-primary" tabindex="-1"><?php esc_html_e('Далее', 'wp-olympiad'); ?></button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>
