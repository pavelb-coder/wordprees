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
$allow_guest = $is_new ? 1 : (int)$quiz->get_setting('allow_guest', 1);
$require_org = $is_new ? 1 : (int)$quiz->get_setting('require_org', 1);
$require_email = $is_new ? 1 : (int)$quiz->get_setting('require_email', 1);
$show_results_mode = $is_new ? 'immediate' : (string)$quiz->get_setting('show_results_mode', 'immediate');
$completion_message = $is_new ? 'Спасибо за участие! Ваши ответы успешно приняты и сохранены в системе.' : (string)$quiz->get_setting('completion_message', 'Спасибо за участие! Ваши ответы успешно приняты и сохранены в системе.');
$anti_cheat_copy = $is_new ? 1 : (int)$quiz->get_setting('anti_cheat_copy', 1);
$anti_cheat_blur = $is_new ? 1 : (int)$quiz->get_setting('anti_cheat_blur', 1);
$max_tab_switches = $is_new ? 5 : (int)$quiz->get_setting('max_tab_switches', 5);
$enable_leaderboard = $is_new ? 1 : (int)$quiz->get_setting('enable_leaderboard', 1);
$allow_external_embed = $is_new ? 1 : (int)$quiz->get_setting('allow_external_embed', 1);
$webhook_url = $is_new ? '' : (string)$quiz->get_setting('webhook_url', '');
$enable_certificate = $is_new ? 1 : (int)$quiz->get_setting('enable_certificate', 1);
$email_participant = $is_new ? 1 : (int)$quiz->get_setting('email_participant', 1);
$email_admin = $is_new ? 0 : (int)$quiz->get_setting('email_admin', 0);
$admin_email_to = $is_new ? '' : (string)$quiz->get_setting('admin_email_to', '');
$direct_url = $is_new ? '' : $quiz->get_direct_url();
$embed_code = $is_new ? '' : $quiz->get_embed_code();

// Network Export Settings
$enable_network_export = $is_new ? 0 : (int)$quiz->get_setting('enable_network_export', 0);
$network_folder_type = $is_new ? 'auto' : (string)$quiz->get_setting('network_folder_type', 'auto');
$network_folder_path = $is_new ? '' : (string)$quiz->get_setting('network_folder_path', '');
$network_folder_user = $is_new ? '' : (string)$quiz->get_setting('network_folder_user', '');
$network_folder_password = $is_new ? '' : (string)$quiz->get_setting('network_folder_password', '');
$export_zip_password = $is_new ? '' : (string)$quiz->get_setting('export_zip_password', '');
$network_auto_export = $is_new ? 0 : (int)$quiz->get_setting('network_auto_export', 0);
$protect_local_directory = $is_new ? 1 : (int)$quiz->get_setting('protect_local_directory', 1);
$last_network_export = !$is_new ? get_transient('wpo_last_network_export_' . $quiz->id) : null;

// Start / Intro Page settings
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
                    </table>
                </div>

                <div class="wpo-card">
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
                    </table>
                </div>

                <!-- Внешнее подключение и интеграция -->
                <div class="wpo-card">
                    <h2>🔗 <?php esc_html_e('Внешнее подключение и интеграция', 'wp-olympiad'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('Ссылки и инструменты для запуска олимпиады вне страниц WordPress (в соцсетях, мессенджерах, сторонних сайтах, LMS и CRM).', 'wp-olympiad'); ?>
                    </p>

                    <table class="form-table">
                        <?php if (!$is_new): ?>
                            <tr>
                                <th scope="row"><?php esc_html_e('Прямая внешняя ссылка:', 'wp-olympiad'); ?></th>
                                <td>
                                    <div style="display:flex; gap:8px; align-items:center;">
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
                        <tr>
                            <th scope="row"><label for="webhook_url"><?php esc_html_e('URL внешнего Webhook:', 'wp-olympiad'); ?></label></th>
                            <td>
                                <input type="url" name="webhook_url" id="webhook_url" value="<?php echo esc_attr($webhook_url); ?>" class="large-text" placeholder="https://api.myschool.ru/olympiad-webhook или https://hook.eu2.make.com/...">
                                <p class="description"><?php esc_html_e('При каждой завершённой попытке плагин автоматически отправит POST JSON с баллами, ФИО, школой и временем на этот адрес (для интеграции с Telegram-ботом, CRM, Google Таблицами или внешней LMS).', 'wp-olympiad'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Экспорт ответов в локальную сетевую папку (защищённую паролем) -->
                <div class="wpo-card" id="wpo-network-export-card">
                    <h2>📁 <?php esc_html_e('Экспорт ответов в локальную сетевую папку (защищённую паролем)', 'wp-olympiad'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('Автоматическая или ручная выгрузка официальных протоколов, сводной матрицы ответов и ключей заданий в сетевую директорию или удалённое хранилище с защитой паролем (Zip AES-256).', 'wp-olympiad'); ?>
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
                                    <option value="smb" <?php selected($network_folder_type, 'smb'); ?>><?php esc_html_e('SMB / Windows Share (через smbclient или прямой UNC путь)', 'wp-olympiad'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="network_folder_path"><?php esc_html_e('Путь к папке или URL хранилища:', 'wp-olympiad'); ?></label></th>
                            <td>
                                <input type="text" name="network_folder_path" id="network_folder_path" value="<?php echo esc_attr($network_folder_path); ?>" class="large-text code" placeholder="/mnt/network_share/olympiad_results или https://nas.local/webdav/protocols/">
                                <p class="description">
                                    <?php esc_html_e('Примеры путей: <code>/mnt/shares/olympiad</code>, <code>\\\\192.168.1.100\\Olympiad\\Results</code>, <code>https://nas.myschool.local/remote.php/webdav/results/</code>, <code>ftp://192.168.1.50/shares/olympiad/</code>', 'wp-olympiad'); ?>
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
                        <tr style="background:#f8fafc; border-left:4px solid #3b82f6;">
                            <th scope="row"><label for="export_zip_password"><?php esc_html_e('Пароль шифрования архива (AES-256):', 'wp-olympiad'); ?></label></th>
                            <td>
                                <div style="display:flex; gap:6px; align-items:center;">
                                    <input type="password" name="export_zip_password" id="export_zip_password" value="<?php echo esc_attr($export_zip_password); ?>" class="regular-text" placeholder="секретный пароль к архиву выгрузки">
                                    <button type="button" class="button wpo-toggle-pwd" data-target="export_zip_password" title="<?php esc_attr_e('Показать / скрыть пароль', 'wp-olympiad'); ?>">👁️</button>
                                </div>
                                <p class="description">
                                    <strong><?php esc_html_e('Ключевая защита конфиденциальности:', 'wp-olympiad'); ?></strong>
                                    <?php esc_html_e('Экспортируемый файл .zip шифруется военным стандартом AES-256. Просмотреть ответы, баллы и протоколы смогут только члены оргкомитета/жюри, знающие этот пароль.', 'wp-olympiad'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Автоматический экспорт:', 'wp-olympiad'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="network_auto_export" value="1" <?php checked($network_auto_export, 1); ?>>
                                    <?php esc_html_e('Автоматически обновлять архив ответов в сетевой папке при каждом завершении тестирования участником', 'wp-olympiad'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Защита веб-доступа (.htaccess):', 'wp-olympiad'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="protect_local_directory" value="1" <?php checked($protect_local_directory, 1); ?>>
                                    <?php esc_html_e('Блокировать прямой доступ из браузера (создавать .htaccess Deny from all, если папка находится внутри сайта)', 'wp-olympiad'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Тестирование и запуск:', 'wp-olympiad'); ?></th>
                            <td>
                                <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                                    <button type="button" class="button" id="wpo-btn-test-network">
                                        🔍 <?php esc_html_e('Проверить доступ к папке', 'wp-olympiad'); ?>
                                    </button>
                                    <?php if (!$is_new): ?>
                                        <button type="button" class="button button-primary" id="wpo-btn-run-network-export" data-quiz-id="<?php echo esc_attr((string)$quiz->id); ?>">
                                            📤 <?php esc_html_e('Экспортировать в сетевую папку сейчас', 'wp-olympiad'); ?>
                                        </button>
                                    <?php endif; ?>
                                    <span id="wpo-network-test-spinner" class="spinner" style="float:none; margin:0;"></span>
                                </div>
                                <div id="wpo-network-test-message" style="margin-top:10px; display:none; padding:10px 14px; border-radius:6px; font-weight:500;"></div>

                                <?php if (!empty($last_network_export) && is_array($last_network_export)): ?>
                                    <div style="margin-top:12px; padding:8px 12px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:6px; font-size:12px; color:#166534;">
                                        <strong>🕒 <?php esc_html_e('Последний успешный экспорт:', 'wp-olympiad'); ?></strong>
                                        <?php echo esc_html($last_network_export['timestamp'] ?? ''); ?> |
                                        <code><?php echo esc_html($last_network_export['destination'] ?? ''); ?></code>
                                        <?php if (!empty($last_network_export['is_encrypted'])): ?>
                                            <span style="color:#0369a1; font-weight:700;">🔒 <?php esc_html_e('Защищён паролем AES-256', 'wp-olympiad'); ?></span>
                                        <?php endif; ?>
                                        (<?php echo esc_html(sprintf('%.1f КБ', ($last_network_export['size'] ?? 0) / 1024)); ?>)
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
                    <h3><?php esc_html_e('Дипломы и Email-оповещения', 'wp-olympiad'); ?></h3>
                    <p>
                        <label>
                            <input type="checkbox" name="enable_certificate" value="1" <?php checked($enable_certificate, 1); ?>>
                            <strong><?php esc_html_e('Генерировать диплом / грамоту', 'wp-olympiad'); ?></strong>
                        </label>
                    </p>
                    <p class="description"><?php esc_html_e('Участник сможет распечатать или скачать диплом со своим результатом и печатью.', 'wp-olympiad'); ?></p>
                    <hr>
                    <p>
                        <label>
                            <input type="checkbox" name="email_participant" value="1" <?php checked($email_participant, 1); ?>>
                            <?php esc_html_e('Отправлять итоги на email участника', 'wp-olympiad'); ?>
                        </label>
                    </p>
                    <p>
                        <label>
                            <input type="checkbox" name="email_admin" value="1" <?php checked($email_admin, 1); ?>>
                            <?php esc_html_e('Уведомлять организатора о сдачах', 'wp-olympiad'); ?>
                        </label>
                    </p>
                    <div class="wpo-field" style="margin-top:10px;">
                        <label for="admin_email_to"><?php esc_html_e('Email организатора:', 'wp-olympiad'); ?></label>
                        <input type="email" name="admin_email_to" id="admin_email_to" value="<?php echo esc_attr($admin_email_to); ?>" class="widefat" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>">
                    </div>
                </div>

                <div class="wpo-card">
                    <h3><?php esc_html_e('Рандомизация и поля', 'wp-olympiad'); ?></h3>
                    <p><label><input type="checkbox" name="shuffle_questions" value="1" <?php checked($shuffle_questions, 1); ?>> <?php esc_html_e('Перемешивать порядок вопросов', 'wp-olympiad'); ?></label></p>
                    <p><label><input type="checkbox" name="shuffle_answers" value="1" <?php checked($shuffle_answers, 1); ?>> <?php esc_html_e('Перемешивать варианты ответов', 'wp-olympiad'); ?></label></p>
                    <hr>
                    <p><label><input type="checkbox" name="require_org" value="1" <?php checked($require_org, 1); ?>> <?php esc_html_e('Требовать школу / класс / ВУЗ', 'wp-olympiad'); ?></label></p>
                    <p><label><input type="checkbox" name="require_email" value="1" <?php checked($require_email, 1); ?>> <?php esc_html_e('Требовать E-mail участника', 'wp-olympiad'); ?></label></p>
                    <p><label><input type="checkbox" name="enable_leaderboard" value="1" <?php checked($enable_leaderboard, 1); ?>> <?php esc_html_e('Включить публичный рейтинг', 'wp-olympiad'); ?></label></p>
                </div>
            </div>
        </div>
    </form>
</div>
