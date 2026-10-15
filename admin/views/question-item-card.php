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
        </div>
        <div class="wpo-qcard-actions">
            <span class="wpo-qcard-points-badge"><?php echo sprintf(esc_html__('%d б.', 'wp-olympiad'), round($q->points)); ?></span>
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
                    <label><strong><?php esc_html_e('Текст задания / Вопрос:', 'wp-olympiad'); ?></strong></label>
                    <textarea name="title" rows="2" class="large-text wpo-q-title-input" required placeholder="<?php esc_attr_e('Сформулируйте условие задачи или вопрос...', 'wp-olympiad'); ?>"><?php echo esc_textarea($q->title); ?></textarea>
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
                    <div class="wpo-field">
                        <label><strong><?php esc_html_e('Правильный ответ (синонимы через запятую):', 'wp-olympiad'); ?></strong></label>
                        <?php
                        $answers_str = is_array($q->options['answers'] ?? null) ? implode(', ', $q->options['answers']) : (string)($q->options['answers'] ?? '');
                        ?>
                        <input type="text" class="large-text wpo-text-answers" value="<?php echo esc_attr($answers_str); ?>" placeholder="<?php esc_attr_e('например: Париж, Paris', 'wp-olympiad'); ?>">
                    </div>
                    <div class="wpo-field">
                        <label>
                            <input type="checkbox" class="wpo-text-case" <?php checked(!empty($q->options['case_sensitive'])); ?>>
                            <?php esc_html_e('Учитывать регистр букв (строчные / заглавные)', 'wp-olympiad'); ?>
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
                            <?php esc_html_e('Выделяйте пропуски фигурными скобками: {правильный_ответ}. Если есть синонимы, разделяйте их чертой: {ответ1|синоним2}. Пример: «В году {12} месяцев, а в неделе {7} дней».', 'wp-olympiad'); ?>
                        </p>
                        <textarea class="large-text wpo-blank-template" rows="4" placeholder="<?php esc_attr_e('Введите текст с {пропусками}...', 'wp-olympiad'); ?>"><?php echo esc_textarea($q->options['template'] ?? ''); ?></textarea>
                    </div>
                    <div class="wpo-field">
                        <label>
                            <input type="checkbox" class="wpo-blank-case" <?php checked(!empty($q->options['case_sensitive'])); ?>>
                            <?php esc_html_e('Учитывать регистр букв при проверке пропусков', 'wp-olympiad'); ?>
                        </label>
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

                <!-- 9. Essay Option -->
                <div class="wpo-type-panel wpo-panel-essay" style="<?php echo $qtype === 'essay' ? '' : 'display:none;'; ?>">
                    <p class="description">
                        ℹ️ <?php esc_html_e('Этот тип заданий предназначен для решений с выкладками или эссе. Ответ проверяется преподавателем/жюри вручную в разделе «Протоколы».', 'wp-olympiad'); ?>
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
