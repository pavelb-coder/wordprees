<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$types = \WPOlympiad\Question::supported_types();
$quiz_shared_texts = $quiz->get_shared_texts();

// Collect all unique groups
$all_groups = [];
foreach ($questions as $question_obj) {
    $grp = trim((string)($question_obj->options['group_name'] ?? ''));
    if ($grp !== '' && !in_array($grp, $all_groups, true)) {
        $all_groups[] = $grp;
    }
}
?>
<div class="wrap wpo-admin-wrap">
    <div class="wpo-header-bar">
        <div>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wp-olympiad')); ?>" class="button button-secondary">
                ← <?php esc_html_e('Ко всем олимпиадам', 'wp-olympiad'); ?>
            </a>
            <h1 style="display:inline-block; margin-left:10px;">
                <?php echo sprintf(esc_html__('Конструктор заданий: %s', 'wp-olympiad'), esc_html($quiz->title)); ?>
            </h1>
        </div>
        <div>
            <button type="button" class="button button-secondary button-large" id="wpo-open-import-modal-btn" style="margin-right:8px;">
                📥 <?php esc_html_e('Импорт заданий', 'wp-olympiad'); ?>
            </button>
            <button type="button" class="button button-secondary button-large" id="wpo-open-shared-texts-btn" style="margin-right:8px;">
                📖 <?php esc_html_e('Общие тексты к заданиям', 'wp-olympiad'); ?> (<span id="wpo-shared-texts-count"><?php echo count($quiz_shared_texts); ?></span>)
            </button>
            <button type="button" class="button button-primary button-large" id="wpo-add-new-q-btn">
                + <?php esc_html_e('Добавить задание', 'wp-olympiad'); ?>
            </button>
        </div>
    </div>

    <?php if (isset($_GET['created'])): ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Олимпиада создана! Теперь добавьте задания.', 'wp-olympiad'); ?></p></div>
    <?php endif; ?>

    <?php $wpo_editor_compact = false; require WP_OLYMPIAD_PATH . 'admin/views/desktop-editor-panel.php'; ?>

    <?php $wpo_sync_compact = true; require WP_OLYMPIAD_PATH . 'admin/views/network-sync-status.php'; ?>

    <!-- Group Filter Bar -->
    <?php if (!empty($all_groups)): ?>
        <div class="wpo-groups-filter-bar" style="margin-bottom:14px; display:flex; gap:8px; align-items:center; flex-wrap:wrap; background:#f8fafc; padding:10px 14px; border-radius:8px; border:1px solid #e2e8f0;">
            <span style="font-size:13px; font-weight:700; color:#334155;">📁 <?php esc_html_e('Разделы олимпиады:', 'wp-olympiad'); ?></span>
            <button type="button" class="button button-small wpo-btn-filter-group active" data-group="">
                <?php esc_html_e('Все задания', 'wp-olympiad'); ?> (<?php echo count($questions); ?>)
            </button>
            <?php foreach ($all_groups as $gname): ?>
                <?php
                $count_in_group = count(array_filter($questions, fn($item) => ($item->options['group_name'] ?? '') === $gname));
                ?>
                <button type="button" class="button button-small wpo-btn-filter-group" data-group="<?php echo esc_attr($gname); ?>">
                    <?php echo esc_html($gname); ?> (<?php echo $count_in_group; ?>)
                </button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Datalist for autocomplete -->
    <datalist id="wpo-group-names-list">
        <?php foreach ($all_groups as $gname): ?>
            <option value="<?php echo esc_attr($gname); ?>">
        <?php endforeach; ?>
    </datalist>

    <div id="wpo-questions-list" data-quiz-id="<?php echo esc_attr((string)$quiz->id); ?>">
        <?php if (empty($questions)): ?>
            <div class="wpo-admin-card wpo-empty-questions-notice">
                <span class="dashicons dashicons-edit-large" style="font-size:40px;height:40px;color:#94a3b8;"></span>
                <h3><?php esc_html_e('В этой олимпиаде пока нет заданий', 'wp-olympiad'); ?></h3>
                <p><?php esc_html_e('Нажмите кнопку «Добавить задание», чтобы создать первый вопрос.', 'wp-olympiad'); ?></p>
            </div>
        <?php else: ?>
            <?php foreach ($questions as $index => $q): ?>
                <?php include WP_OLYMPIAD_PATH . 'admin/views/question-item-card.php'; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Hidden Template for New Question -->
    <template id="wpo-new-question-template">
        <?php
        $q = new \WPOlympiad\Question([
            'id'            => 0,
            'quiz_id'       => $quiz->id,
            'question_type' => 'single',
            'title'         => '',
            'content'       => '',
            'points'        => 1,
            'penalty'       => 0,
            'sort_order'    => 999,
            'options'       => [
                'choices' => [
                    ['id' => 'c1', 'text' => '', 'is_correct' => 1],
                    ['id' => 'c2', 'text' => '', 'is_correct' => 0],
                ],
                'explanation' => '',
            ],
        ]);
        $index = 999;
        include WP_OLYMPIAD_PATH . 'admin/views/question-item-card.php';
        ?>
    </template>

    <!-- Modal: Shared Texts Management -->
    <div id="wpo-shared-texts-modal" class="wpo-modal-overlay" style="display:none;">
        <div class="wpo-modal-dialog">
            <div class="wpo-modal-header">
                <h3>📖 <?php esc_html_e('Общие тексты к заданиям олимпиады', 'wp-olympiad'); ?></h3>
                <button type="button" class="wpo-modal-close" id="wpo-close-shared-modal-btn">&times;</button>
            </div>
            <div class="wpo-modal-body" style="padding:0;">
                <div class="wpo-st-modal-layout" style="display:flex; height:520px;">
                    <!-- Sidebar: List of shared texts -->
                    <div class="wpo-st-sidebar" style="width:280px; border-right:1px solid #e2e8f0; background:#f8fafc; display:flex; flex-direction:column;">
                        <div style="padding:12px; border-bottom:1px solid #e2e8f0;">
                            <button type="button" class="button button-primary widefat" id="wpo-btn-new-shared-text">
                                + <?php esc_html_e('Создать общий текст', 'wp-olympiad'); ?>
                            </button>
                        </div>
                        <div class="wpo-st-list" id="wpo-st-items-list" style="flex:1; overflow-y:auto; padding:8px;">
                            <!-- Dynamically filled list -->
                        </div>
                    </div>

                    <!-- Main Editor Area -->
                    <div class="wpo-st-editor-area" style="flex:1; padding:18px 24px; overflow-y:auto; display:flex; flex-direction:column; gap:14px;">
                        <div id="wpo-st-no-selection" style="display:none; text-align:center; padding:50px 20px; color:#64748b;">
                            <span style="font-size:42px;">📖</span>
                            <h4 style="margin:12px 0 6px;"><?php esc_html_e('Выберите общий текст слева или создайте новый', 'wp-olympiad'); ?></h4>
                            <p style="font-size:13px; max-width:420px; margin:0 auto;"><?php esc_html_e('Общий текст (кейс, фрагмент произведения, условие задачи) может быть привязан к нескольким вопросам олимпиады.', 'wp-olympiad'); ?></p>
                        </div>

                        <div id="wpo-st-editor-form">
                            <input type="hidden" id="wpo-cur-st-id" value="">

                            <div class="wpo-field">
                                <label style="font-weight:700; display:block; margin-bottom:4px;">
                                    <?php esc_html_e('Название / Заголовок общего текста:', 'wp-olympiad'); ?>
                                </label>
                                <input type="text" id="wpo-cur-st-title" class="widefat" placeholder="<?php esc_attr_e('Например: Текст к заданиям 1–4: «О природе электричества»', 'wp-olympiad'); ?>">
                            </div>

                            <div class="wpo-field">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                                    <label style="font-weight:700;">
                                        <?php esc_html_e('Содержимое общего текста (чтение / кейс / фрагмент):', 'wp-olympiad'); ?>
                                    </label>
                                    <button type="button" class="button button-small wpo-format-btn" id="wpo-cur-st-preview-toggle">
                                        👁️ <?php esc_html_e('Предпросмотр', 'wp-olympiad'); ?>
                                    </button>
                                </div>

                                <!-- Formatting Toolbar for Shared Text -->
                                <div class="wpo-format-toolbar" data-target="#wpo-cur-st-content">
                                    <div class="wpo-format-group">
                                        <button type="button" class="wpo-format-btn" data-tag="strong" title="Жирный"><strong>B</strong></button>
                                        <button type="button" class="wpo-format-btn" data-tag="em" title="Курсив"><em>I</em></button>
                                        <button type="button" class="wpo-format-btn" data-tag="u" title="Подчёркнутый"><u>U</u></button>
                                        <button type="button" class="wpo-format-btn" data-tag="mark" title="Маркер"><span style="background:#fef08a; padding:0 3px;">🖍️</span></button>
                                        <button type="button" class="wpo-format-btn" data-tag="blockquote" title="Цитата">❝</button>
                                    </div>
                                    <div class="wpo-format-group">
                                        <button type="button" class="wpo-format-btn" data-action="ul" title="Маркированный список">•</button>
                                        <button type="button" class="wpo-format-btn" data-action="ol" title="Нумерованный список">1.</button>
                                        <button type="button" class="wpo-format-btn" data-action="link" title="Вставить ссылку">🔗</button>
                                        <button type="button" class="wpo-format-btn wpo-btn-insert-inline-img" title="Вставить изображение в текст">🖼️</button>
                                    </div>
                                </div>

                                <textarea id="wpo-cur-st-content" rows="9" class="widefat" style="font-size:13px; line-height:1.6;" placeholder="<?php esc_attr_e('Вставьте или напишите текст для чтения, условие задачи, кейс или исторический документ...', 'wp-olympiad'); ?>"></textarea>
                                <div id="wpo-cur-st-preview-box" class="wpo-q-live-preview typography-body" style="display:none; max-height:220px; overflow-y:auto; padding:12px; background:#fff; border:1px solid #cbd5e1; border-radius:6px; margin-top:6px;"></div>
                            </div>

                            <!-- Questions assignment checklist -->
                            <div class="wpo-field" style="background:#f1f5f9; padding:10px 14px; border-radius:6px; border:1px solid #e2e8f0;">
                                <label style="font-weight:700; color:#334155; display:block; margin-bottom:6px;">
                                    🎯 <?php esc_html_e('Привязать этот текст к заданиям:', 'wp-olympiad'); ?>
                                </label>
                                <div class="wpo-st-questions-checklist" style="display:flex; flex-wrap:wrap; gap:8px; max-height:110px; overflow-y:auto;">
                                    <?php foreach ($questions as $idx => $q_item): ?>
                                        <label style="font-size:12px; display:inline-flex; align-items:center; gap:5px; background:#fff; padding:4px 8px; border:1px solid #cbd5e1; border-radius:4px; cursor:pointer;">
                                            <input type="checkbox" class="wpo-st-assign-q" value="<?php echo esc_attr((string)$q_item->id); ?>" data-qnum="<?php echo $idx + 1; ?>">
                                            <span>№<?php echo $idx + 1; ?> (<?php echo esc_html(mb_strimwidth($q_item->title, 0, 22, '...')); ?>)</span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:10px;">
                                <button type="button" class="button button-link-delete" id="wpo-btn-delete-cur-st">
                                    🗑️ <?php esc_html_e('Удалить этот общий текст', 'wp-olympiad'); ?>
                                </button>
                                <span class="description" style="color:#64748b; font-size:12px;"><?php esc_html_e('Привязанные вопросы открепятся автоматически', 'wp-olympiad'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="wpo-modal-footer">
                <button type="button" class="button button-secondary" id="wpo-btn-cancel-shared-modal">
                    <?php esc_html_e('Закрыть', 'wp-olympiad'); ?>
                </button>
                <button type="button" class="button button-primary button-large" id="wpo-btn-save-shared-modal">
                    💾 <?php esc_html_e('Сохранить общие тексты', 'wp-olympiad'); ?>
                </button>
            </div>
        </div>
    </div>

    <!-- Modal: Network Questions Import -->
    <div id="wpo-network-import-modal" class="wpo-modal-overlay" style="display:none;">
        <div class="wpo-modal-dialog" style="max-width: 680px;">
            <div class="wpo-modal-header">
                <h3>📥 <?php esc_html_e('Импорт заданий (Excel, CSV, JSON)', 'wp-olympiad'); ?></h3>
                <button type="button" class="wpo-modal-close" id="wpo-close-import-modal-btn">&times;</button>
            </div>
            <div class="wpo-modal-body" style="padding: 20px 24px;">
                <?php $wpo_editor_compact = true; require WP_OLYMPIAD_PATH . 'admin/views/desktop-editor-panel.php'; ?>

                <!-- Download templates banner -->
                <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:12px 16px; margin-bottom:18px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                    <div>
                        <strong style="color:#1e40af; display:block; font-size:13px;">📄 <?php esc_html_e('Шаблон для заполнения вопросов:', 'wp-olympiad'); ?></strong>
                        <span style="font-size:12px; color:#475569;"><?php esc_html_e('Все 13 типов заданий: выбор, соответствия, последовательность, пропуски, шкала, анаграмма, диктант, эссе и др. Заголовки можно писать по-русски.', 'wp-olympiad'); ?></span>
                    </div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <a href="<?php echo esc_url(admin_url('admin.php?wpo_action=download_question_template&format=xlsx')); ?>" class="button button-small button-primary" title="<?php esc_attr_e('Стандартный файл Excel с выпадающими списками', 'wp-olympiad'); ?>">
                            📗 Шаблон Excel (.xlsx)
                        </a>
                    </div>
                </div>

                <!-- Source Selection Tabs -->
                <div style="display:flex; gap:10px; margin-bottom:16px; border-bottom:1px solid #e2e8f0; padding-bottom:8px;">
                    <button type="button" class="button button-primary wpo-import-tab-btn" data-tab="upload" id="wpo-import-tab-upload">
                        💻 <?php esc_html_e('Загрузить с компьютера', 'wp-olympiad'); ?>
                    </button>
                    <button type="button" class="button button-secondary wpo-import-tab-btn" data-tab="network" id="wpo-import-tab-network">
                        📁 <?php esc_html_e('Из сетевой папки', 'wp-olympiad'); ?>
                    </button>
                </div>

                <!-- Tab 1: Upload from local computer -->
                <div id="wpo-import-panel-upload" class="wpo-import-panel" style="margin-bottom:16px;">
                    <div style="border:2px dashed #93c5fd; background:#f0f9ff; border-radius:8px; padding:22px; text-align:center;">
                        <span style="font-size:36px; display:block; margin-bottom:8px;">📥</span>
                        <label for="wpo-import-file-input" class="button button-primary" style="font-size:13px; cursor:pointer;">
                            📁 <?php esc_html_e('Выбрать файл (.xlsm, .xlsx, .csv, .json)', 'wp-olympiad'); ?>
                        </label>
                        <input type="file" id="wpo-import-file-input" accept=".xlsm,.xlsx,.csv,.json,.tsv,.txt" style="display:none;">
                        <div id="wpo-import-selected-filename" style="margin-top:10px; font-weight:600; color:#1e293b; font-size:13px;">
                            <?php esc_html_e('Файл не выбран. Нажмите кнопку выше для выбора файла.', 'wp-olympiad'); ?>
                        </div>
                        <div style="font-size:11px; color:#64748b; margin-top:4px;">
                            <?php esc_html_e('Автоматически распознаются все листы книги Excel и кодировка Windows-1251 / UTF-8.', 'wp-olympiad'); ?>
                        </div>
                    </div>
                </div>

                <!-- Tab 2: Network Folder Scanner -->
                <div id="wpo-import-panel-network" class="wpo-import-panel" style="display:none; margin-bottom:16px;">
                    <!-- Network Folder Path Status -->
                    <div class="wpo-field" style="margin-bottom:14px;">
                        <label style="font-weight:700; color:#1e293b; display:block; margin-bottom:4px;">
                            📁 <?php esc_html_e('Сетевая папка источника (хранилище):', 'wp-olympiad'); ?>
                        </label>
                        <?php
                        $net_cfg = \WPOlympiad\NetworkExport::get_quiz_network_config($quiz);
                        ?>
                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            <input type="text" id="wpo-import-network-path" class="widefat" value="<?php echo esc_attr($net_cfg['path']); ?>" placeholder="<?php esc_attr_e('\\\\server\\share\\ или /mnt/share', 'wp-olympiad'); ?>" style="font-family:monospace; font-size:12px; flex:1;">
                            <button type="button" class="button button-secondary" id="wpo-btn-rescan-network" title="<?php esc_attr_e('Найти файлы в сетевой папке', 'wp-olympiad'); ?>">
                                🔍 <?php esc_html_e('Сканировать', 'wp-olympiad'); ?>
                            </button>
                        </div>
                        <span class="description" style="font-size:11px; color:#64748b;">
                            <?php esc_html_e('Сначала ищем файлы непосредственно в import/. Если их нет, проверяем корень хранилища и доступные подпапки как раньше. Простое имя также сначала ищется в import; для файла в корне укажите ./имя. Если подключение уже указывает на import/, указывайте только имя файла.', 'wp-olympiad'); ?>
                        </span>
                    </div>

                    <!-- Discovered / Target File -->
                    <div class="wpo-field">
                        <label style="font-weight:700; color:#1e293b; display:block; margin-bottom:4px;">
                            📄 <?php esc_html_e('Файл шаблона с вопросами в сетевой папке:', 'wp-olympiad'); ?>
                        </label>
                        <div style="display:flex; gap:8px; align-items:center;">
                            <select id="wpo-import-discovered-files" style="flex:1; font-size:13px;">
                                <option value=""><?php esc_html_e('— Поиск файлов в сетевой папке... —', 'wp-olympiad'); ?></option>
                            </select>
                        </div>
                        <div style="margin-top:6px;">
                            <input type="text" id="wpo-import-manual-filename" class="widefat" placeholder="<?php esc_attr_e('Или введите путь относительно хранилища: import/Мои_вопросы.xlsx', 'wp-olympiad'); ?>" style="font-size:12px;">
                        </div>
                    </div>
                </div>

                <!-- Import Mode -->
                <div class="wpo-field" style="margin-bottom:16px; background:#f8fafc; padding:10px 14px; border:1px solid #e2e8f0; border-radius:6px;">
                    <label style="font-weight:700; color:#1e293b; display:block; margin-bottom:6px;">
                        ⚙️ <?php esc_html_e('Режим импорта:', 'wp-olympiad'); ?>
                    </label>
                    <div style="display:flex; gap:20px;">
                        <label style="cursor:pointer; display:inline-flex; align-items:center; gap:6px;">
                            <input type="radio" name="wpo_import_mode" value="append" checked>
                            <span><?php esc_html_e('Добавить к существующим вопросам', 'wp-olympiad'); ?></span>
                        </label>
                        <label style="cursor:pointer; display:inline-flex; align-items:center; gap:6px; color:#b91c1c;">
                            <input type="radio" name="wpo_import_mode" value="replace">
                            <span>⚠️ <?php esc_html_e('Заменить все текущие вопросы', 'wp-olympiad'); ?></span>
                        </label>
                    </div>
                </div>

                <!-- Видимость баллов у импортированных вопросов -->
                <div class="wpo-field" style="margin-bottom:16px; background:#f8fafc; padding:10px 14px; border:1px solid #e2e8f0; border-radius:6px;">
                    <label style="cursor:pointer; display:inline-flex; align-items:center; gap:8px; font-weight:600; color:#1e293b;">
                        <input type="checkbox" id="wpo-import-hide-points" value="1" checked>
                        <span>🙈 <?php esc_html_e('Скрыть баллы за импортированные вопросы в тесте', 'wp-olympiad'); ?></span>
                    </label>
                    <p class="description" style="margin:6px 0 0; font-size:11px; color:#64748b;">
                        <?php esc_html_e('Галочка включена по умолчанию — как у новых заданий в конструкторе: участник не видит баллы и штрафы за импортированные вопросы. Снимите её, чтобы баллы были видны. Для отдельной строки файла видимость задаётся в колонке «extra»: show_points=1.', 'wp-olympiad'); ?>
                    </p>
                </div>

                <!-- Live Status & Results -->
                <div id="wpo-import-status-box" style="display:none; padding:12px 14px; border-radius:6px; font-size:13px; margin-bottom:12px;"></div>
                <div id="wpo-import-report-box" style="display:none; margin-bottom:12px;"></div>
            </div>
            <div class="wpo-modal-footer">
                <button type="button" class="button button-secondary" id="wpo-btn-cancel-import-modal">
                    <?php esc_html_e('Отмена', 'wp-olympiad'); ?>
                </button>
                <button type="button" class="button button-secondary button-large" id="wpo-btn-validate-builder-import" title="<?php esc_attr_e('Проверить файл и показать отчёт по строкам без импорта', 'wp-olympiad'); ?>">
                    ✔ <?php esc_html_e('Проверить файл', 'wp-olympiad'); ?>
                </button>
                <button type="button" class="button button-primary button-large" id="wpo-btn-run-builder-import">
                    📥 <?php esc_html_e('Запустить импорт заданий', 'wp-olympiad'); ?>
                </button>
            </div>
        </div>
    </div>

    <script>
        window.wpoSharedTexts = <?php echo wp_json_encode($quiz_shared_texts, JSON_UNESCAPED_UNICODE); ?>;
    </script>
</div>
