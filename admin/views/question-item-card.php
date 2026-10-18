<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$qid = (int)$q->id;
$qtype = $q->question_type;
$types = \WPOlympiad\Question::supported_types();
?>
<div class="wpo-question-card" data-id="<?php echo esc_attr((string)$qid); ?>" data-type="<?php echo esc_attr($qtype); ?>">
    <div class="wpo-qcard-header">
        <div class="wpo-qcard-title-group">
            <span class="wpo-drag-handle" title="<?php esc_attr_e('Переместить', 'wp-olympiad'); ?>">☰</span>
            <span class="wpo-qcard-index">#<?php echo esc_html((string)($qid ?: '+')); ?></span>
            <span class="wpo-qcard-type-badge"><?php echo esc_html($types[$qtype]['label'] ?? $qtype); ?></span>
            <strong class="wpo-qcard-title-text"><?php echo esc_html($q->title ?: __('Новый вопрос', 'wp-olympiad')); ?></strong>
            <span class="wpo-qcard-required-badge" style="<?php echo !empty($q->options['is_required']) ? '' : 'display:none;'; ?> color:#dc2626; font-size:14px; font-weight:700; margin-left:2px;" title="<?php esc_attr_e('Обязательный вопрос', 'wp-olympiad'); ?>">*</span>
            <span class="wpo-qcard-group-badge" style="<?php echo !empty($q->options['group_name']) ? '' : 'display:none;'; ?> background:#e0f2fe; color:#0369a1; padding:2px 7px; border-radius:4px; font-size:11px; font-weight:600; margin-left:6px;">📁 <span class="wpo-qcard-group-text"><?php echo esc_html($q->options['group_name'] ?? ''); ?></span></span>
            <span class="wpo-qcard-shared-badge" style="<?php echo !empty($q->options['shared_text_id']) ? '' : 'display:none;'; ?> background:#fef3c7; color:#92400e; padding:2px 7px; border-radius:4px; font-size:11px; font-weight:600; margin-left:6px;">📖 <span class="wpo-qcard-shared-text-title"><?php
                $cur_st_id = $q->options['shared_text_id'] ?? '';
                $cur_st = null;
                if (!empty($quiz_shared_texts)) {
                    foreach ($quiz_shared_texts as $item) {
                        if (($item['id'] ?? '') === $cur_st_id) { $cur_st = $item; break; }
                    }
                }
                echo esc_html($cur_st['title'] ?? __('Общий текст', 'wp-olympiad'));
            ?></span></span>
        </div>
        <div class="wpo-qcard-actions">
            <?php $q_pts_hidden = $qid === 0 ? true : (isset($q->options['show_points']) && empty($q->options['show_points'])); ?>
            <span class="wpo-qcard-points-badge" title="<?php echo $q_pts_hidden ? esc_attr__('Баллы скрыты от участников', 'wp-olympiad') : esc_attr__('Баллы видны участникам', 'wp-olympiad'); ?>"><?php echo sprintf(esc_html__('%d б.', 'wp-olympiad'), round($q->points)); ?><?php if ($q_pts_hidden): ?> <span style="opacity:.6;">🙈</span><?php endif; ?></span>
            <button type="button" class="button button-small wpo-btn-toggle-card">▾</button>
            <button type="button" class="button button-small button-link-delete wpo-btn-del-question" title="<?php esc_attr_e('Удалить вопрос', 'wp-olympiad'); ?>">✕</button>
        </div>
    </div>

    <div class="wpo-qcard-body">
        <form class="wpo-question-form" onsubmit="return false;">
            <input type="hidden" name="id" value="<?php echo esc_attr((string)$qid); ?>">
            <input type="hidden" name="quiz_id" value="<?php echo esc_attr((string)$q->quiz_id); ?>">

            <div class="wpo-grid-2">
                <div class="wpo-field">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                        <label><strong><?php esc_html_e('Текст задания / Вопрос:', 'wp-olympiad'); ?></strong></label>
                        <span class="description" style="font-size:11px;"><?php esc_html_e('Поддерживается форматирование и формулы', 'wp-olympiad'); ?></span>
                    </div>

                    <!-- Formatting Toolbar for Title -->
                    <div class="wpo-format-toolbar" data-target=".wpo-q-title-input">
                        <div class="wpo-format-group">
                            <button type="button" class="wpo-format-btn" data-tag="strong" title="<?php esc_attr_e('Жирный (Bold)', 'wp-olympiad'); ?>"><strong>B</strong></button>
                            <button type="button" class="wpo-format-btn" data-tag="em" title="<?php esc_attr_e('Курсив (Italic)', 'wp-olympiad'); ?>"><em>I</em></button>
                            <button type="button" class="wpo-format-btn" data-tag="u" title="<?php esc_attr_e('Подчёркнутый (Underline)', 'wp-olympiad'); ?>"><u>U</u></button>
                            <button type="button" class="wpo-format-btn" data-tag="s" title="<?php esc_attr_e('Зачёркнутый (Strikethrough)', 'wp-olympiad'); ?>"><s>S</s></button>
                            <button type="button" class="wpo-format-btn" data-tag="mark" title="<?php esc_attr_e('Маркер / Выделение', 'wp-olympiad'); ?>"><span style="background:#fef08a; padding:0 3px;">🖍️</span></button>
                        </div>
                        <div class="wpo-format-group">
                            <button type="button" class="wpo-format-btn" data-tag="sup" title="<?php esc_attr_e('Верхний индекс / Степень (x²)', 'wp-olympiad'); ?>">x²</button>
                            <button type="button" class="wpo-format-btn" data-tag="sub" title="<?php esc_attr_e('Нижний индекс / Формула (x₂)', 'wp-olympiad'); ?>">x₂</button>
                            <button type="button" class="wpo-format-btn" data-tag="code" title="<?php esc_attr_e('Инлайн-код / Формула', 'wp-olympiad'); ?>">&lt;/&gt;</button>
                            <button type="button" class="wpo-format-btn" data-tag="blockquote" title="<?php esc_attr_e('Цитата / Врезка', 'wp-olympiad'); ?>">❝</button>
                        </div>
                        <div class="wpo-format-group">
                            <button type="button" class="wpo-format-btn" data-action="ul" title="<?php esc_attr_e('Маркированный список', 'wp-olympiad'); ?>">•</button>
                            <button type="button" class="wpo-format-btn" data-action="ol" title="<?php esc_attr_e('Нумерованный список', 'wp-olympiad'); ?>">1.</button>
                            <button type="button" class="wpo-format-btn" data-action="link" title="<?php esc_attr_e('Вставить ссылку', 'wp-olympiad'); ?>">🔗</button>
                            <button type="button" class="wpo-format-btn wpo-btn-insert-inline-img" title="<?php esc_attr_e('Вставить изображение в текст', 'wp-olympiad'); ?>">🖼️</button>
                            <button type="button" class="wpo-format-btn wpo-btn-toggle-preview" title="<?php esc_attr_e('Предпросмотр форматирования', 'wp-olympiad'); ?>">👁️</button>
                        </div>
                    </div>

                    <textarea name="title" rows="2" class="large-text wpo-q-title-input" required placeholder="<?php esc_attr_e('Сформулируйте условие задачи или вопрос...', 'wp-olympiad'); ?>"><?php echo esc_textarea($q->title); ?></textarea>
                    <div class="wpo-q-live-preview wpo-preview-title" style="display:none; background:#fff; border:1px solid #cbd5e1; border-radius:6px; padding:10px 14px; margin-bottom:8px; line-height:1.6;"></div>

                    <!-- Collapsible Extended Content -->
                    <div style="margin-top:10px;">
                        <a href="javascript:void(0);" class="wpo-toggle-content-link" style="font-size:12px; font-weight:600; text-decoration:none; color:#2563eb;">
                            <?php echo !empty($q->content) ? '▾ ' . esc_html__('Скрыть подробное описание задачи', 'wp-olympiad') : '▸ ' . esc_html__('+ Добавить подробное условие / текст / формулы задачи', 'wp-olympiad'); ?>
                        </a>
                        <div class="wpo-content-wrapper" style="<?php echo !empty($q->content) ? '' : 'display:none;'; ?> margin-top:8px;">
                            <div class="wpo-format-toolbar" data-target=".wpo-q-content-input">
                                <div class="wpo-format-group">
                                    <button type="button" class="wpo-format-btn" data-tag="strong" title="<?php esc_attr_e('Жирный', 'wp-olympiad'); ?>"><strong>B</strong></button>
                                    <button type="button" class="wpo-format-btn" data-tag="em" title="<?php esc_attr_e('Курсив', 'wp-olympiad'); ?>"><em>I</em></button>
                                    <button type="button" class="wpo-format-btn" data-tag="u" title="<?php esc_attr_e('Подчёркнутый', 'wp-olympiad'); ?>"><u>U</u></button>
                                    <button type="button" class="wpo-format-btn" data-tag="mark" title="<?php esc_attr_e('Маркер', 'wp-olympiad'); ?>"><span style="background:#fef08a; padding:0 3px;">🖍️</span></button>
                                    <button type="button" class="wpo-format-btn" data-tag="sup" title="<?php esc_attr_e('Степень (x²)', 'wp-olympiad'); ?>">x²</button>
                                    <button type="button" class="wpo-format-btn" data-tag="sub" title="<?php esc_attr_e('Индекс (x₂)', 'wp-olympiad'); ?>">x₂</button>
                                    <button type="button" class="wpo-format-btn" data-tag="code" title="<?php esc_attr_e('Код / Формула', 'wp-olympiad'); ?>">&lt;/&gt;</button>
                                    <button type="button" class="wpo-format-btn" data-tag="pre" title="<?php esc_attr_e('Блок кода', 'wp-olympiad'); ?>">{ }</button>
                                    <button type="button" class="wpo-format-btn" data-tag="blockquote" title="<?php esc_attr_e('Цитата', 'wp-olympiad'); ?>">❝</button>
                                    <button type="button" class="wpo-format-btn" data-action="ul" title="<?php esc_attr_e('Список', 'wp-olympiad'); ?>">•</button>
                                    <button type="button" class="wpo-format-btn" data-action="link" title="<?php esc_attr_e('Ссылка', 'wp-olympiad'); ?>">🔗</button>
                                    <button type="button" class="wpo-format-btn wpo-btn-insert-inline-img" title="<?php esc_attr_e('Картинка', 'wp-olympiad'); ?>">🖼️</button>
                                    <button type="button" class="wpo-format-btn wpo-btn-toggle-preview" title="<?php esc_attr_e('Предпросмотр', 'wp-olympiad'); ?>">👁️</button>
                                </div>
                            </div>
                            <textarea name="content" rows="4" class="large-text wpo-q-content-input" placeholder="<?php esc_attr_e('Дополнительное подробное условие, текст задачи, формулы, список или вводная часть...', 'wp-olympiad'); ?>"><?php echo esc_textarea($q->content); ?></textarea>
                            <div class="wpo-q-live-preview wpo-preview-content" style="display:none; background:#fff; border:1px solid #cbd5e1; border-radius:6px; padding:12px; margin-bottom:8px; line-height:1.6;"></div>
                        </div>
                    </div>
                </div>
                <div class="wpo-grid-3">
                    <div class="wpo-field">
                        <label><?php esc_html_e('Тип задания:', 'wp-olympiad'); ?></label>
                        <select name="question_type" class="widefat wpo-q-type-select">
                            <?php foreach ($types as $key => $info): ?>
                                <option value="<?php echo esc_attr($key); ?>" <?php selected($qtype, $key); ?>><?php echo esc_html($info['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="wpo-field">
                        <label><?php esc_html_e('Баллы за верный ответ:', 'wp-olympiad'); ?></label>
                        <input type="number" name="points" step="1" min="0" value="<?php echo esc_attr((string)(int)round($q->points)); ?>" class="widefat wpo-q-points-input">
                    </div>
                    <div class="wpo-field">
                        <label><?php esc_html_e('Штраф за ошибку:', 'wp-olympiad'); ?></label>
                        <input type="number" name="penalty" step="1" min="0" value="<?php echo esc_attr((string)(int)round($q->penalty)); ?>" class="widefat">
                    </div>
                    <div class="wpo-field" style="grid-column: 1 / -1;">
                        <label>📁 <?php esc_html_e('Раздел / Группа заданий (опционально):', 'wp-olympiad'); ?></label>
                        <input type="text" class="widefat wpo-q-group-name" list="wpo-group-names-list" value="<?php echo esc_attr($q->options['group_name'] ?? ''); ?>" placeholder="<?php esc_attr_e('Например: Раздел 1. Теория, Блок А, Часть 1...', 'wp-olympiad'); ?>">
                    </div>

                    <!-- Shared Text / Common Passage Selector -->
                    <div class="wpo-field wpo-q-shared-text-field" style="grid-column: 1 / -1; margin-top: 2px; background: #fdfefe; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 12px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                            <label style="margin:0; font-weight:600; color:#1e293b; display:inline-flex; align-items:center; gap:6px;">
                                <span>📖</span> <?php esc_html_e('Общий текст / Контекст к вопросу:', 'wp-olympiad'); ?>
                            </label>
                            <button type="button" class="button button-small wpo-btn-manage-shared-texts" title="<?php esc_attr_e('Открыть редактор общих текстов', 'wp-olympiad'); ?>">
                                ⚙️ <?php esc_html_e('Управление текстами', 'wp-olympiad'); ?>
                            </button>
                        </div>
                        <div style="display:flex; gap:8px; align-items:center; margin-top:6px; flex-wrap:wrap;">
                            <select class="wpo-q-shared-text-select" style="flex:1; min-width:240px; font-size:13px;">
                                <option value=""><?php esc_html_e('— Без общего текста —', 'wp-olympiad'); ?></option>
                                <?php if (!empty($quiz_shared_texts)): ?>
                                    <?php foreach ($quiz_shared_texts as $st): ?>
                                        <option value="<?php echo esc_attr($st['id']); ?>" <?php selected(($q->options['shared_text_id'] ?? '') === $st['id']); ?>>
                                            <?php echo esc_html($st['title'] ?: $st['id']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <option value="new">+ <?php esc_html_e('Создать новый общий текст...', 'wp-olympiad'); ?></option>
                            </select>
                            <button type="button" class="button button-small wpo-btn-preview-shared-text" style="<?php echo !empty($q->options['shared_text_id']) ? '' : 'display:none;'; ?>" title="<?php esc_attr_e('Быстрый просмотр общего текста', 'wp-olympiad'); ?>">
                                👁️ <?php esc_html_e('Просмотр', 'wp-olympiad'); ?>
                            </button>
                        </div>
                        <div class="wpo-q-shared-text-preview" style="display:none; margin-top:8px; padding:10px; background:#fff; border:1px dashed #cbd5e1; border-radius:6px; font-size:12px; color:#334155; max-height:140px; overflow-y:auto; line-height:1.5;"></div>
                    </div>
                    <div class="wpo-field" style="grid-column: 1 / -1; display:flex; gap:20px; align-items:center; flex-wrap:wrap; margin-top:4px;">
                        <label style="cursor:pointer; user-select:none;">
                            <input type="checkbox" class="wpo-q-is-required" <?php checked(!empty($q->options['is_required'])); ?>>
                            <strong style="color:#dc2626;">* <?php esc_html_e('Обязательный вопрос', 'wp-olympiad'); ?></strong>
                        </label>
                        <label style="cursor:pointer; user-select:none;">
                            <?php $q_shuffle = !isset($q->options['shuffle_answers']) || !empty($q->options['shuffle_answers']); ?>
                            <input type="checkbox" class="wpo-q-shuffle-answers" <?php checked($q_shuffle); ?>>
                            <span>🔀 <?php esc_html_e('Перемешивать варианты ответов', 'wp-olympiad'); ?></span>
                        </label>
                        <label style="cursor:pointer; user-select:none;">
                            <?php
                            // Новые вопросы по умолчанию скрывают баллы от участников (галочка включена сразу)
                            $q_hide_pts = $qid === 0
                                ? true
                                : (isset($q->options['show_points']) && empty($q->options['show_points']));
                            ?>
                            <input type="checkbox" class="wpo-q-hide-points" <?php checked($q_hide_pts); ?>>
                            <span>🙈 <?php esc_html_e('Скрыть баллы за этот вопрос в тесте', 'wp-olympiad'); ?></span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Question Image / Illustration Section -->
            <div class="wpo-q-image-section" style="margin-top:12px; margin-bottom:14px; padding:12px 14px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                    <label><strong>🖼️ <?php esc_html_e('Изображение / Иллюстрация к вопросу:', 'wp-olympiad'); ?></strong></label>
                    <span class="description" style="color:#64748b;"><?php esc_html_e('Схема, карта, график, фото или рисунок (кликните «Медиатека WP» или укажите URL)', 'wp-olympiad'); ?></span>
                </div>
                
                <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                    <!-- Preview Box -->
                    <div class="wpo-q-image-preview-box" style="<?php echo !empty($q->options['image_url']) ? '' : 'display:none;'; ?> width:100px; height:75px; border:1px solid #cbd5e1; border-radius:6px; overflow:hidden; background:#fff; text-align:center; position:relative; flex-shrink:0;">
                        <img src="<?php echo esc_url($q->options['image_url'] ?? ''); ?>" alt="" class="wpo-q-image-thumb" style="width:100%; height:100%; object-fit:contain;">
                    </div>

                    <div style="flex:1; min-width:260px;">
                        <div style="display:flex; gap:8px; margin-bottom:8px;">
                            <input type="text" class="regular-text wpo-q-image-url" value="<?php echo esc_attr($q->options['image_url'] ?? ''); ?>" placeholder="<?php esc_attr_e('https://... или нажмите кнопку «Медиатека WP»', 'wp-olympiad'); ?>" style="flex:1;">
                            <button type="button" class="button button-secondary wpo-btn-upload-q-image" title="<?php esc_attr_e('Выбрать или загрузить картинку в медиатеку', 'wp-olympiad'); ?>">
                                📁 <?php esc_html_e('Медиатека WP', 'wp-olympiad'); ?>
                            </button>
                            <button type="button" class="button button-link-delete wpo-btn-remove-q-image" style="<?php echo !empty($q->options['image_url']) ? '' : 'display:none;'; ?>" title="<?php esc_attr_e('Удалить изображение', 'wp-olympiad'); ?>">
                                ✕ <?php esc_html_e('Удалить', 'wp-olympiad'); ?>
                            </button>
                        </div>

                        <div class="wpo-grid-2">
                            <div>
                                <input type="text" class="widefat wpo-q-image-caption" value="<?php echo esc_attr($q->options['image_caption'] ?? ''); ?>" placeholder="<?php esc_attr_e('Подпись к иллюстрации (например: Рис. 1. Карта древнего мира)...', 'wp-olympiad'); ?>">
                            </div>
                            <div style="display:flex; gap:10px; align-items:center;">
                                <label style="font-size:12px; color:#64748b; white-space:nowrap;"><?php esc_html_e('Расположение:', 'wp-olympiad'); ?></label>
                                <?php $img_pos = $q->options['image_position'] ?? 'above'; ?>
                                <select class="wpo-q-image-position" style="font-size:12px; width:100%;">
                                    <option value="above" <?php selected($img_pos, 'above'); ?>><?php esc_html_e('Над текстом задания', 'wp-olympiad'); ?></option>
                                    <option value="below" <?php selected($img_pos, 'below'); ?>><?php esc_html_e('Под текстом задания', 'wp-olympiad'); ?></option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Dynamic Options Panels -->
            <div class="wpo-type-panel-wrap">
                <!-- 1. Single / Multi / Dropdown Choices Options -->
                <div class="wpo-type-panel wpo-panel-choices" style="<?php echo in_array($qtype, ['single', 'multi', 'dropdown'], true) ? '' : 'display:none;'; ?>">
                    <label><strong><?php esc_html_e('Варианты ответов:', 'wp-olympiad'); ?></strong></label>
                    <p class="description"><?php esc_html_e('Отметьте правильный вариант (или несколько). В поле «Баллы» можно задать персональный балл за каждый пункт (при заполнении вопрос суммирует баллы выбранных ответов).', 'wp-olympiad'); ?></p>

                    <div class="wpo-choices-list">
                        <?php
                        $choices = $q->options['choices'] ?? [
                            ['id' => 'c1', 'text' => '', 'is_correct' => 1],
                            ['id' => 'c2', 'text' => '', 'is_correct' => 0],
                        ];
                        foreach ($choices as $cidx => $choice):
                            $cid = esc_attr($choice['id'] ?? 'c' . ($cidx + 1));
                            $ctext = esc_attr($choice['text'] ?? '');
                            $is_correct = !empty($choice['is_correct']);
                            $ch_pts = isset($choice['points']) && $choice['points'] !== null ? esc_attr((string)(int)round((float)$choice['points'])) : '';
                        ?>
                            <div class="wpo-choice-row" style="display:flex; gap:8px; align-items:center; margin-bottom:8px;">
                                <label class="wpo-correct-check">
                                    <input type="<?php echo $qtype === 'multi' ? 'checkbox' : 'radio'; ?>" name="choice_correct_<?php echo esc_attr((string)$qid); ?>" value="<?php echo $cid; ?>" <?php checked($is_correct, true); ?> title="<?php esc_attr_e('Отметить как правильный', 'wp-olympiad'); ?>">
                                </label>
                                <input type="text" class="regular-text wpo-choice-text" data-id="<?php echo $cid; ?>" value="<?php echo $ctext; ?>" placeholder="<?php esc_attr_e('Текст варианта ответа...', 'wp-olympiad'); ?>" style="flex:1;">
                                <input type="number" step="1" class="small-text wpo-choice-points" value="<?php echo $ch_pts; ?>" placeholder="<?php esc_attr_e('Баллы', 'wp-olympiad'); ?>" title="<?php esc_attr_e('Индивидуальный балл за данный вариант ответа (целое число)', 'wp-olympiad'); ?>" style="width:75px;">
                                <button type="button" class="button button-small wpo-btn-del-choice" title="<?php esc_attr_e('Удалить вариант', 'wp-olympiad'); ?>">✕</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="button button-secondary button-small wpo-btn-add-choice" style="margin-top:8px;">
                        + <?php esc_html_e('Добавить вариант', 'wp-olympiad'); ?>
                    </button>
                </div>

                <!-- 2. Boolean (True / False) Option -->
                <div class="wpo-type-panel wpo-panel-boolean" style="<?php echo $qtype === 'boolean' ? '' : 'display:none;'; ?>">
                    <label><strong><?php esc_html_e('Правильный ответ утверждения:', 'wp-olympiad'); ?></strong></label>
                    <?php
                    $bool_correct = 'true';
                    foreach ($q->options['choices'] ?? [] as $bc) {
                        if (!empty($bc['is_correct'])) {
                            $bool_correct = (string)$bc['id'];
                            break;
                        }
                    }
                    ?>
                    <div style="display:flex; gap:20px; margin-top:8px;">
                        <label style="font-weight:600;">
                            <input type="radio" name="bool_correct_<?php echo esc_attr((string)$qid); ?>" value="true" class="wpo-bool-choice" <?php checked($bool_correct, 'true'); ?>>
                            ✓ <?php esc_html_e('Верно (Да)', 'wp-olympiad'); ?>
                        </label>
                        <label style="font-weight:600;">
                            <input type="radio" name="bool_correct_<?php echo esc_attr((string)$qid); ?>" value="false" class="wpo-bool-choice" <?php checked($bool_correct, 'false'); ?>>
                            ✕ <?php esc_html_e('Неверно (Нет)', 'wp-olympiad'); ?>
                        </label>
                    </div>
                </div>

                <!-- 3. Numeric Option -->
                <div class="wpo-type-panel wpo-panel-number" style="<?php echo $qtype === 'number' ? '' : 'display:none;'; ?>">
                    <div class="wpo-grid-2">
                        <div class="wpo-field">
                            <label><strong><?php esc_html_e('Правильное числовое значение:', 'wp-olympiad'); ?></strong></label>
                            <input type="number" step="any" class="regular-text wpo-num-target" value="<?php echo esc_attr((string)($q->options['target_value'] ?? '0')); ?>">
                        </div>
                        <div class="wpo-field">
                            <label><?php esc_html_e('Допустимая погрешность (± delta):', 'wp-olympiad'); ?></label>
                            <input type="number" step="any" min="0" class="regular-text wpo-num-tolerance" value="<?php echo esc_attr((string)($q->options['tolerance'] ?? '0')); ?>">
                            <p class="description"><?php esc_html_e('Например: 0.05 (ответ 3.14 будет принят в диапазоне от 3.09 до 3.19).', 'wp-olympiad'); ?></p>
                        </div>
                    </div>
                </div>

                <!-- 4. Short Text Option -->
                <div class="wpo-type-panel wpo-panel-text" style="<?php echo $qtype === 'text' ? '' : 'display:none;'; ?>">
                    <div class="wpo-grid-2" style="margin-bottom:12px;">
                        <div class="wpo-field">
                            <label><strong><?php esc_html_e('Режим автоматической проверки текста:', 'wp-olympiad'); ?></strong></label>
                            <?php $match_mode = $q->options['match_mode'] ?? 'exact'; ?>
                            <select class="regular-text wpo-text-match-mode">
                                <option value="exact" <?php selected($match_mode, 'exact'); ?>><?php esc_html_e('Точное совпадение (с эталоном или синонимами)', 'wp-olympiad'); ?></option>
                                <option value="contains_any" <?php selected($match_mode, 'contains_any'); ?>><?php esc_html_e('Содержит хотя бы одно ключевое слово / фразу', 'wp-olympiad'); ?></option>
                                <option value="contains_all" <?php selected($match_mode, 'contains_all'); ?>><?php esc_html_e('Содержит ВСЕ перечисленные ключевые слова', 'wp-olympiad'); ?></option>
                                <option value="fuzzy" <?php selected($match_mode, 'fuzzy'); ?>><?php esc_html_e('Нечёткое совпадение (с допуском опечаток / Левенштейн)', 'wp-olympiad'); ?></option>
                                <option value="regex" <?php selected($match_mode, 'regex'); ?>><?php esc_html_e('Регулярное выражение (RegEx)', 'wp-olympiad'); ?></option>
                            </select>
                        </div>
                        <div class="wpo-field wpo-text-typo-wrap" style="<?php echo $match_mode === 'fuzzy' ? '' : 'display:none;'; ?>">
                            <label><strong><?php esc_html_e('Допустимое число опечаток (символов):', 'wp-olympiad'); ?></strong></label>
                            <input type="number" min="1" max="5" class="small-text wpo-text-typo-tolerance" value="<?php echo esc_attr((string)($q->options['typo_tolerance'] ?? 1)); ?>">
                            <span class="description"><?php esc_html_e('Например: 1 («Масква» засчитается как «Москва»)', 'wp-olympiad'); ?></span>
                        </div>
                    </div>

                    <div class="wpo-field">
                        <label><strong><?php esc_html_e('Правильные ответы / синонимы / шаблоны (через запятую):', 'wp-olympiad'); ?></strong></label>
                        <?php
                        $answers_str = is_array($q->options['answers'] ?? null) ? implode(', ', $q->options['answers']) : (string)($q->options['answers'] ?? '');
                        ?>
                        <input type="text" class="large-text wpo-text-answers" value="<?php echo esc_attr($answers_str); ?>" placeholder="<?php esc_attr_e('например: Париж, Paris', 'wp-olympiad'); ?>">
                    </div>

                    <div class="wpo-field" style="display:flex; gap:20px; flex-wrap:wrap; margin-top:8px;">
                        <label>
                            <input type="checkbox" class="wpo-text-case" <?php checked(!empty($q->options['case_sensitive'])); ?>>
                            <?php esc_html_e('Учитывать регистр букв (строчные / заглавные)', 'wp-olympiad'); ?>
                        </label>
                        <label>
                            <input type="checkbox" class="wpo-text-ignore-punct" <?php checked(!isset($q->options['ignore_punct']) || !empty($q->options['ignore_punct'])); ?>>
                            <?php esc_html_e('Игнорировать знаки препинания и кавычки', 'wp-olympiad'); ?>
                        </label>
                        <label>
                            <input type="checkbox" class="wpo-text-ignore-whitespace" <?php checked(!isset($q->options['ignore_whitespace']) || !empty($q->options['ignore_whitespace'])); ?>>
                            <?php esc_html_e('Нормализовать пробелы и переносы', 'wp-olympiad'); ?>
                        </label>
                    </div>
                </div>

                <!-- 5. Matching (Online Test Pad) -->
                <div class="wpo-type-panel wpo-panel-matching" style="<?php echo $qtype === 'matching' ? '' : 'display:none;'; ?>">
                    <label><strong><?php esc_html_e('Пары для сопоставления (Левая колонка — Правая колонка):', 'wp-olympiad'); ?></strong></label>
                    <p class="description"><?php esc_html_e('Участнику будет предложено для каждого элемента слева выбрать соответствие справа. Можно указать индивидуальный балл за каждую верно сопоставленную пару.', 'wp-olympiad'); ?></p>

                    <div class="wpo-matching-list">
                        <?php
                        $pairs = $q->options['pairs'] ?? [
                            ['id' => 'p1', 'left' => '', 'right' => ''],
                            ['id' => 'p2', 'left' => '', 'right' => ''],
                        ];
                        foreach ($pairs as $pidx => $pair):
                            $pid = esc_attr($pair['id'] ?? 'p' . ($pidx + 1));
                            $pleft = esc_attr($pair['left'] ?? '');
                            $pright = esc_attr($pair['right'] ?? '');
                            $pair_pts = isset($pair['points']) && $pair['points'] !== null ? esc_attr((string)(int)round((float)$pair['points'])) : '';
                        ?>
                            <div class="wpo-matching-row" style="display:flex; gap:10px; align-items:center; margin-bottom:8px;">
                                <input type="text" class="regular-text wpo-match-left" data-id="<?php echo $pid; ?>" value="<?php echo $pleft; ?>" placeholder="<?php esc_attr_e('Элемент слева (понятие)...', 'wp-olympiad'); ?>" style="flex:1;">
                                <span style="font-weight:bold; color:#64748b;">➔</span>
                                <input type="text" class="regular-text wpo-match-right" value="<?php echo $pright; ?>" placeholder="<?php esc_attr_e('Соответствие справа (определение)...', 'wp-olympiad'); ?>" style="flex:1;">
                                <input type="number" step="1" class="small-text wpo-match-points" value="<?php echo $pair_pts; ?>" placeholder="<?php esc_attr_e('Баллы', 'wp-olympiad'); ?>" title="<?php esc_attr_e('Баллы за правильное сопоставление этой пары (целое число)', 'wp-olympiad'); ?>" style="width:75px;">
                                <button type="button" class="button button-small wpo-btn-del-matching" title="<?php esc_attr_e('Удалить пару', 'wp-olympiad'); ?>">✕</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="button button-secondary button-small wpo-btn-add-matching" style="margin-top:6px;">
                        + <?php esc_html_e('Добавить пару соответствия', 'wp-olympiad'); ?>
                    </button>
                </div>

                <!-- 6. Ordering / Sequencing (Online Test Pad) -->
                <div class="wpo-type-panel wpo-panel-ordering" style="<?php echo $qtype === 'ordering' ? '' : 'display:none;'; ?>">
                    <label><strong><?php esc_html_e('Элементы в ПРАВИЛЬНОМ порядке:', 'wp-olympiad'); ?></strong></label>
                    <p class="description"><?php esc_html_e('Задайте элементы в верной последовательности. Можно указать индивидуальный балл за правильное положение каждого элемента.', 'wp-olympiad'); ?></p>

                    <div class="wpo-ordering-list">
                        <?php
                        $items = $q->options['items'] ?? [
                            ['id' => 'ord_1', 'text' => ''],
                            ['id' => 'ord_2', 'text' => ''],
                            ['id' => 'ord_3', 'text' => ''],
                        ];
                        foreach ($items as $iidx => $item):
                            $iid = esc_attr($item['id'] ?? 'ord_' . ($iidx + 1));
                            $itext = esc_attr($item['text'] ?? '');
                            $item_pts = isset($item['points']) && $item['points'] !== null ? esc_attr((string)(int)round((float)$item['points'])) : '';
                        ?>
                            <div class="wpo-ordering-row" style="display:flex; gap:10px; align-items:center; margin-bottom:8px;">
                                <span class="wpo-order-num" style="font-weight:700; width:25px;"><?php echo esc_html((string)($iidx + 1)); ?>.</span>
                                <input type="text" class="large-text wpo-order-text" data-id="<?php echo $iid; ?>" value="<?php echo $itext; ?>" placeholder="<?php esc_attr_e('Текст этапа или элемента последовательности...', 'wp-olympiad'); ?>" style="flex:1;">
                                <input type="number" step="1" class="small-text wpo-order-points" value="<?php echo $item_pts; ?>" placeholder="<?php esc_attr_e('Баллы', 'wp-olympiad'); ?>" title="<?php esc_attr_e('Баллы за правильное место этого элемента (целое число)', 'wp-olympiad'); ?>" style="width:75px;">
                                <button type="button" class="button button-small wpo-btn-del-ordering" title="<?php esc_attr_e('Удалить элемент', 'wp-olympiad'); ?>">✕</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="button button-secondary button-small wpo-btn-add-ordering" style="margin-top:6px;">
                        + <?php esc_html_e('Добавить элемент последовательности', 'wp-olympiad'); ?>
                    </button>
                </div>

                <!-- 7. Fill in the Blanks (Online Test Pad) -->
                <div class="wpo-type-panel wpo-panel-fill_blank" style="<?php echo $qtype === 'fill_blank' ? '' : 'display:none;'; ?>">
                    <div class="wpo-field">
                        <label><strong><?php esc_html_e('Текст задания с пропусками:', 'wp-olympiad'); ?></strong></label>
                        <p class="description">
                            <?php esc_html_e('Выделяйте пропуски фигурными скобками: {правильный_ответ}. Если есть варианты выбора, разделяйте их чертой: {=правильно|вариант2|вариант3}. Пример: «Столица Франции — {=Париж|Лондон|Рим}».', 'wp-olympiad'); ?>
                        </p>
                        <textarea class="large-text wpo-blank-template" rows="4" placeholder="<?php esc_attr_e('Введите текст с {пропусками}...', 'wp-olympiad'); ?>"><?php echo esc_textarea($q->options['template'] ?? ''); ?></textarea>
                    </div>

                    <div class="wpo-grid-3" style="margin-top:10px;">
                        <div class="wpo-field">
                            <label><strong><?php esc_html_e('Формат заполнения пропусков:', 'wp-olympiad'); ?></strong></label>
                            <?php $b_mode = $q->options['choice_mode'] ?? 'input'; ?>
                            <select class="regular-text wpo-blank-choice-mode">
                                <option value="input" <?php selected($b_mode, 'input'); ?>><?php esc_html_e('Текстовое поле ввода (ручной ввод)', 'wp-olympiad'); ?></option>
                                <option value="dropdown" <?php selected($b_mode, 'dropdown'); ?>><?php esc_html_e('Выпадающий список вариантов в каждом пропуске', 'wp-olympiad'); ?></option>
                                <option value="word_bank" <?php selected($b_mode, 'word_bank'); ?>><?php esc_html_e('Банк слов / интерактивные плашки под текстом', 'wp-olympiad'); ?></option>
                            </select>
                        </div>
                        <div class="wpo-field">
                            <label><?php esc_html_e('Дополнительные варианты (дистракторы):', 'wp-olympiad'); ?></label>
                            <input type="text" class="regular-text wpo-blank-distractors" value="<?php echo esc_attr((string)($q->options['distractors'] ?? '')); ?>" placeholder="<?php esc_attr_e('например: Рим, Мадрид, Берлин', 'wp-olympiad'); ?>">
                            <p class="description"><?php esc_html_e('Через запятую. Добавляются к списку вариантов для усложнения.', 'wp-olympiad'); ?></p>
                        </div>
                        <div class="wpo-field" style="display:flex; align-items:center; margin-top:20px;">
                            <label>
                                <input type="checkbox" class="wpo-blank-case" <?php checked(!empty($q->options['case_sensitive'])); ?>>
                                <?php esc_html_e('Учитывать регистр букв при проверке', 'wp-olympiad'); ?>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- 8. Slider / Rating scale (Online Test Pad) -->
                <div class="wpo-type-panel wpo-panel-slider" style="<?php echo $qtype === 'slider' ? '' : 'display:none;'; ?>">
                    <div class="wpo-grid-4">
                        <div class="wpo-field">
                            <label><?php esc_html_e('Минимум шкалы (Min):', 'wp-olympiad'); ?></label>
                            <input type="number" step="any" class="regular-text wpo-slider-min" value="<?php echo esc_attr((string)($q->options['min'] ?? '0')); ?>">
                        </div>
                        <div class="wpo-field">
                            <label><?php esc_html_e('Максимум шкалы (Max):', 'wp-olympiad'); ?></label>
                            <input type="number" step="any" class="regular-text wpo-slider-max" value="<?php echo esc_attr((string)($q->options['max'] ?? '100')); ?>">
                        </div>
                        <div class="wpo-field">
                            <label><?php esc_html_e('Шаг ползунка (Step):', 'wp-olympiad'); ?></label>
                            <input type="number" step="any" min="0.01" class="regular-text wpo-slider-step" value="<?php echo esc_attr((string)($q->options['step'] ?? '1')); ?>">
                        </div>
                        <div class="wpo-field">
                            <label><strong><?php esc_html_e('Правильное значение:', 'wp-olympiad'); ?></strong></label>
                            <input type="number" step="any" class="regular-text wpo-slider-target" value="<?php echo esc_attr((string)($q->options['target_value'] ?? '50')); ?>">
                        </div>
                    </div>
                    <div class="wpo-field" style="margin-top:8px;">
                        <label><?php esc_html_e('Допустимая погрешность (± delta):', 'wp-olympiad'); ?></label>
                        <input type="number" step="any" min="0" class="regular-text wpo-slider-tolerance" value="<?php echo esc_attr((string)($q->options['tolerance'] ?? '0')); ?>">
                    </div>
                </div>

                <!-- 9. Word Scramble Option -->
                <div class="wpo-type-panel wpo-panel-word_scramble" style="<?php echo $qtype === 'word_scramble' ? '' : 'display:none;'; ?>">
                    <div class="wpo-field">
                        <label><strong><?php esc_html_e('Целевое слово (или варианты через запятую):', 'wp-olympiad'); ?></strong></label>
                        <?php
                        $words_str = is_array($q->options['words'] ?? null) ? implode(', ', $q->options['words']) : (string)($q->options['target_word'] ?? '');
                        ?>
                        <input type="text" class="large-text wpo-scramble-target" value="<?php echo esc_attr($words_str); ?>" placeholder="<?php esc_attr_e('например: АЛГОРИТМ или ПАРИЖ, PARIS', 'wp-olympiad'); ?>">
                        <p class="description"><?php esc_html_e('Буквы этого слова будут автоматически перемешаны и представлены участнику в виде интерактивных плиток.', 'wp-olympiad'); ?></p>
                    </div>

                    <div class="wpo-grid-2" style="margin-top:10px;">
                        <div class="wpo-field">
                            <label><strong><?php esc_html_e('Дополнительные (лишние) буквы для усложнения:', 'wp-olympiad'); ?></strong></label>
                            <input type="text" class="regular-text wpo-scramble-extra" value="<?php echo esc_attr((string)($q->options['extra_letters'] ?? '')); ?>" placeholder="<?php esc_attr_e('например: О, П, С или ОПС', 'wp-olympiad'); ?>">
                            <p class="description"><?php esc_html_e('Опционально. Эти буквы будут добавлены к набору плиток, чтобы усложнить составление слова.', 'wp-olympiad'); ?></p>
                        </div>
                        <div class="wpo-field" style="display:flex; align-items:center; margin-top:20px;">
                            <label>
                                <input type="checkbox" class="wpo-scramble-case" <?php checked(!empty($q->options['case_sensitive'])); ?>>
                                <?php esc_html_e('Учитывать регистр букв (строчные / заглавные)', 'wp-olympiad'); ?>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- 10. Dictation Option -->
                <div class="wpo-type-panel wpo-panel-dictation" style="<?php echo $qtype === 'dictation' ? '' : 'display:none;'; ?>">
                    <div class="wpo-field">
                        <label><strong><?php esc_html_e('Текст диктанта с орфограммами / пунктограммами:', 'wp-olympiad'); ?></strong></label>
                        <p class="description">
                            <?php esc_html_e('Заключайте варианты выбора в фигурные скобки через знак | (верный вариант помечайте знаком = или ставьте первым):', 'wp-olympiad'); ?>
                            <br>
                            <code>Шёл {=дождь|дожть}. Мы шли по алле{=е|и}, наслаждаясь прохладой{=,|нет знака} которую так ждали.</code>
                        </p>
                        <textarea class="large-text wpo-dictation-template" rows="5" placeholder="<?php esc_attr_e('Текст диктанта с разметкой {=верно|ошибка}...', 'wp-olympiad'); ?>"><?php echo esc_textarea($q->options['template'] ?? ''); ?></textarea>
                    </div>

                    <div class="wpo-grid-3" style="margin-top:10px;">
                        <div class="wpo-field">
                            <label><strong><?php esc_html_e('URL аудиозаписи диктанта (для аудирования):', 'wp-olympiad'); ?></strong></label>
                            <input type="url" class="regular-text wpo-dictation-audio" value="<?php echo esc_attr((string)($q->options['audio_url'] ?? '')); ?>" placeholder="https://example.com/audio/dictation.mp3">
                            <p class="description"><?php esc_html_e('Опционально. Ссылка на MP3/WAV аудиозапись для прослушивания.', 'wp-olympiad'); ?></p>
                        </div>
                        <div class="wpo-field">
                            <label><strong><?php esc_html_e('Режим начисления баллов:', 'wp-olympiad'); ?></strong></label>
                            <?php $dict_sm = $q->options['scoring_mode'] ?? 'proportional'; ?>
                            <select class="regular-text wpo-dictation-scoring-mode">
                                <option value="proportional" <?php selected($dict_sm, 'proportional'); ?>><?php esc_html_e('Пропорционально верным орфограммам', 'wp-olympiad'); ?></option>
                                <option value="all_or_nothing" <?php selected($dict_sm, 'all_or_nothing'); ?>><?php esc_html_e('Всё или ничего (без ошибок)', 'wp-olympiad'); ?></option>
                            </select>
                        </div>
                        <div class="wpo-field">
                            <label><strong><?php esc_html_e('Допустимо ошибок (без снижения):', 'wp-olympiad'); ?></strong></label>
                            <input type="number" min="0" class="small-text wpo-dictation-allowed-mistakes" value="<?php echo esc_attr((string)($q->options['allowed_mistakes'] ?? 0)); ?>">
                            <p class="description"><?php esc_html_e('0 — строгая оценка.', 'wp-olympiad'); ?></p>
                        </div>
                    </div>
                </div>

                <!-- 11. Essay Option -->
                <div class="wpo-type-panel wpo-panel-essay" style="<?php echo $qtype === 'essay' ? '' : 'display:none;'; ?>">
                    <div class="wpo-field" style="margin-bottom:12px;">
                        <label>
                            <input type="checkbox" class="wpo-essay-auto-check" <?php checked(!empty($q->options['auto_check'])); ?>>
                            <strong>⚡ <?php esc_html_e('Включить автоматическую проверку по ключевым понятиям/критериям', 'wp-olympiad'); ?></strong>
                        </label>
                        <p class="description"><?php esc_html_e('Позволяет автоматически проверять наличие обязательных терминов и формулировок в тексте ответа.', 'wp-olympiad'); ?></p>
                    </div>

                    <div class="wpo-essay-auto-settings" style="<?php echo !empty($q->options['auto_check']) ? '' : 'display:none;'; ?> background:#f8fafc; padding:12px; border-radius:6px; border:1px solid #e2e8f0; margin-bottom:12px;">
                        <div class="wpo-field" style="margin-bottom:10px;">
                            <label><strong><?php esc_html_e('Обязательные ключевые термины / понятия (через запятую):', 'wp-olympiad'); ?></strong></label>
                            <?php
                            $kw_str = is_array($q->options['keywords'] ?? null) ? implode(', ', $q->options['keywords']) : (string)($q->options['keywords'] ?? '');
                            ?>
                            <textarea class="large-text wpo-essay-keywords" rows="2" placeholder="<?php esc_attr_e('например: фотосинтез, хлорофилл, световая фаза, углекислый газ', 'wp-olympiad'); ?>"><?php echo esc_textarea($kw_str); ?></textarea>
                            <p class="description"><?php esc_html_e('Участник должен упомянуть эти понятия или термины в решении / сочинении.', 'wp-olympiad'); ?></p>
                        </div>

                        <div class="wpo-grid-3" style="margin-bottom:10px;">
                            <div class="wpo-field">
                                <label><strong><?php esc_html_e('Мин. число понятий:', 'wp-olympiad'); ?></strong></label>
                                <input type="number" min="0" class="small-text wpo-essay-min-keywords" value="<?php echo esc_attr((string)($q->options['min_keywords'] ?? 0)); ?>">
                                <p class="description"><?php esc_html_e('0 — требовать все указанные термины.', 'wp-olympiad'); ?></p>
                            </div>
                            <div class="wpo-field">
                                <label><strong><?php esc_html_e('Режим начисления баллов:', 'wp-olympiad'); ?></strong></label>
                                <?php $sm = $q->options['scoring_mode'] ?? 'proportional'; ?>
                                <select class="regular-text wpo-essay-scoring-mode">
                                    <option value="proportional" <?php selected($sm, 'proportional'); ?>><?php esc_html_e('Пропорционально найденным терминам', 'wp-olympiad'); ?></option>
                                    <option value="all_or_nothing" <?php selected($sm, 'all_or_nothing'); ?>><?php esc_html_e('Всё или ничего (по достижению порога)', 'wp-olympiad'); ?></option>
                                </select>
                            </div>
                            <div class="wpo-field">
                                <label><strong><?php esc_html_e('Минимум слов в ответе:', 'wp-olympiad'); ?></strong></label>
                                <input type="number" min="0" class="small-text wpo-essay-min-words" value="<?php echo esc_attr((string)($q->options['min_words'] ?? 0)); ?>">
                                <p class="description"><?php esc_html_e('0 — без ограничений по длине.', 'wp-olympiad'); ?></p>
                            </div>
                        </div>

                        <div class="wpo-field" style="display:flex; gap:20px; flex-wrap:wrap;">
                            <label>
                                <input type="checkbox" class="wpo-essay-auto-pass" <?php checked(!empty($q->options['auto_pass'])); ?>>
                                <strong><?php esc_html_e('Автоматически завершать проверку (без обязательного ручного утверждения жюри)', 'wp-olympiad'); ?></strong>
                            </label>
                            <label>
                                <input type="checkbox" class="wpo-essay-case" <?php checked(!empty($q->options['case_sensitive'])); ?>>
                                <?php esc_html_e('Учитывать регистр ключевых слов', 'wp-olympiad'); ?>
                            </label>
                        </div>
                    </div>

                    <p class="description">
                        ℹ️ <?php esc_html_e('При ручной проверке в разделе «Протоколы» эксперту доступна кнопка «⚡ Автопроверить», которая покажет найденные ключевые понятия и рассчитает балл.', 'wp-olympiad'); ?>
                    </p>
                </div>
            </div>

            <!-- Explanation / Solution -->
            <div class="wpo-field" style="margin-top:15px;">
                <label><?php esc_html_e('Пояснение к решению (показывается после завершения тестирования):', 'wp-olympiad'); ?></label>
                <textarea class="large-text wpo-q-explanation" rows="2" placeholder="<?php esc_attr_e('Подробное решение или правильный ответ с объяснением...', 'wp-olympiad'); ?>"><?php echo esc_textarea($q->options['explanation'] ?? ''); ?></textarea>
            </div>

            <div class="wpo-qcard-footer">
                <button type="button" class="button button-primary wpo-btn-save-q">
                    💾 <?php esc_html_e('Сохранить задание', 'wp-olympiad'); ?>
                </button>
                <span class="wpo-save-status spinner" style="float:none;"></span>
            </div>
        </form>
    </div>
</div>
