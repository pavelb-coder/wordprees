<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$questions_map = [];
foreach ($questions as $q) {
    $questions_map[$q->id] = $q;
}
?>
<div class="wrap wpo-admin-wrap">
    <div class="wpo-header-bar">
        <div style="display:flex; justify-content:space-between; align-items:center; width:100%;">
            <div>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-olympiad-attempts&quiz_id=' . $attempt->quiz_id)); ?>" class="button button-secondary">
                    ← <?php esc_html_e('К списку попыток', 'wp-olympiad'); ?>
                </a>
                <h1 style="display:inline-block; margin-left:10px;">
                    <?php echo sprintf(esc_html__('Проверка попытки #%1$d: %2$s', 'wp-olympiad'), (int)$attempt->id, esc_html($attempt->participant_name)); ?>
                </h1>
            </div>
            <div style="display:flex; gap:8px; align-items:center;">
                <button type="button" class="button button-secondary" id="wpo-btn-auto-grade-all-text">
                    ⚡ <?php esc_html_e('Автопроверить все текстовые ответы', 'wp-olympiad'); ?>
                </button>
                <a href="<?php echo esc_url(admin_url('admin.php?wpo_action=export_attempt_csv&attempt_id=' . $attempt->id)); ?>" class="button button-primary">
                    📥 <?php esc_html_e('Экспорт ответов (CSV)', 'wp-olympiad'); ?>
                </a>
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=wp-olympiad-attempts&wpo_action=delete_attempt&attempt_id=' . $attempt->id . '&quiz_id=' . $attempt->quiz_id), 'wpo_delete_attempt_' . $attempt->id)); ?>" class="button button-secondary wpo-text-danger" onclick="return confirm('<?php echo esc_js(__('Вы уверены, что хотите удалить эту попытку и все её ответы? Это действие необратимо.', 'wp-olympiad')); ?>');">
                    🗑 <?php esc_html_e('Удалить попытку и ответы', 'wp-olympiad'); ?>
                </a>
            </div>
        </div>
    </div>

    <!-- Attempt Meta Card -->
    <div class="wpo-admin-card" style="margin-bottom:20px;">
        <div class="wpo-grid-4">
            <div>
                <span class="description"><?php esc_html_e('Олимпиада:', 'wp-olympiad'); ?></span>
                <h4><?php echo esc_html($quiz ? $quiz->title : ('#' . $attempt->quiz_id)); ?></h4>
            </div>
            <div>
                <span class="description"><?php esc_html_e('Анкета участника:', 'wp-olympiad'); ?></span>
                <h4 style="margin:2px 0;"><?php echo esc_html($attempt->participant_name); ?></h4>
                <div class="description" style="margin-top:2px;">
                    <?php if ($attempt->participant_email): ?>
                        <span>📧 <?php echo esc_html($attempt->participant_email); ?></span>
                    <?php endif; ?>
                    <?php if ($attempt->participant_phone): ?>
                        <span style="margin-left:8px; color:#0284c7;">📞 <?php echo esc_html($attempt->participant_phone); ?></span>
                    <?php endif; ?>
                </div>
                <div class="description" style="margin-top:2px;">
                    <?php if ($attempt->participant_org): ?>
                        <span>🏫 <?php echo esc_html($attempt->participant_org); ?></span>
                    <?php endif; ?>
                    <?php if ($attempt->participant_city): ?>
                        <span style="margin-left:8px; color:#64748b;">📍 <?php echo esc_html($attempt->participant_city); ?></span>
                    <?php endif; ?>
                </div>
                <?php
                $wpo_cf_defs = $quiz ? $quiz->get_custom_fields() : [];
                $wpo_cf_values = $attempt->custom_field_values();
                if ($wpo_cf_defs): ?>
                    <div class="wpo-review-custom-fields" style="margin-top:8px; padding:8px 10px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px;">
                        <span class="description"><strong><?php esc_html_e('Дополнительная анкета:', 'wp-olympiad'); ?></strong></span>
                        <?php foreach ($wpo_cf_defs as $wpo_cf):
                            $wpo_cf_text = \WPOlympiad\ParticipantFields::format_value($wpo_cf, $wpo_cf_values[$wpo_cf['id']] ?? ''); ?>
                            <div style="margin-top:3px; font-size:13px;">
                                <strong><?php echo esc_html($wpo_cf['label']); ?>:</strong>
                                <span><?php echo esc_html($wpo_cf_text !== '' ? $wpo_cf_text : '—'); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div>
                <span class="description"><?php esc_html_e('Время сдачи и нарушения:', 'wp-olympiad'); ?></span>
                <h4>
                    ⏱ <?php echo esc_html(sprintf('%02d:%02d', floor($attempt->duration_seconds / 60), $attempt->duration_seconds % 60)); ?>
                    <?php if ($attempt->tab_switches > 0): ?>
                        <span class="wpo-badge wpo-badge-fail" style="margin-left:8px;">⚠️ Переключений: <?php echo esc_html((string)$attempt->tab_switches); ?></span>
                    <?php endif; ?>
                </h4>
            </div>
            <div>
                <span class="description"><?php esc_html_e('Текущий балл:', 'wp-olympiad'); ?></span>
                <h4 id="wpo-current-score-display">
                    <?php echo esc_html(sprintf('%d / %d (%d%%)', round($attempt->score), round($attempt->max_score), round($attempt->percentage))); ?>
                    <span class="wpo-badge wpo-badge-completed"><?php echo esc_html($attempt->get_status_label()); ?></span>
                </h4>
            </div>
        </div>
    </div>

    <?php
    // 1.0.22: несохранённый черновик виден организатору, но оцениваться не может:
    // работа ещё не отправлена участником.
    $wpo_draft = $attempt->draft();
    $wpo_choice_text = static function (mixed $value, $wpo_q): string {
        $choices = $wpo_q ? (array)($wpo_q->options['choices'] ?? []) : [];
        $items = is_array($value) ? $value : [$value];
        $out = [];
        foreach ($items as $item) {
            $item = (string)$item;
            foreach ($choices as $choice) {
                if (is_array($choice) && ($choice['id'] ?? '') !== '' && (string)$choice['id'] === $item) {
                    $item = (string)($choice['text'] ?? $item);
                    break;
                }
            }
            if ($item !== '') {
                $out[] = $item;
            }
        }

        return implode(', ', $out);
    };
    ?>
    <?php if ($attempt->status === 'in_progress' && !empty($wpo_draft['answers'])): ?>
        <div class="wpo-admin-card" style="margin-bottom:16px; padding:14px 16px; border-left:4px solid #f59e0b;">
            <h3 style="margin:0 0 6px;">📝 <?php esc_html_e('Несданный черновик (автосохранение)', 'wp-olympiad'); ?></h3>
            <p class="description" style="margin:0 0 10px;">
                <?php
                $wpo_draft_when = !empty($attempt->draft_updated_at)
                    ? mysql2date(get_option('date_format') . ' ' . get_option('time_format'), (string)$attempt->draft_updated_at)
                    : '';
                echo esc_html(sprintf(
                    $wpo_draft_when !== ''
                        ? 'Сохранено в %s. Работа не отправлена: эти ответы не оцениваются и в протокол не войдут, пока участник их не сдаст.'
                        : 'Работа не отправлена: эти ответы не оцениваются и в протокол не войдут, пока участник их не сдаст.',
                    $wpo_draft_when
                ));
                ?>
            </p>
            <?php foreach ($wpo_draft['answers'] as $wpo_dqid => $wpo_dval):
                $wpo_dq = $questions_map[(int)$wpo_dqid] ?? null; ?>
                <div style="margin-bottom:6px; font-size:13px;">
                    <strong><?php echo esc_html($wpo_dq ? $wpo_dq->title : ('#' . (int)$wpo_dqid)); ?>:</strong>
                    <span><?php echo esc_html($wpo_choice_text($wpo_dval, $wpo_dq) ?: '—'); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Responses Inspection & Grading Form -->
    <form id="wpo-review-form" onsubmit="return false;">
        <input type="hidden" name="attempt_id" value="<?php echo esc_attr((string)$attempt->id); ?>">

        <?php foreach ($responses as $idx => $r): ?>
            <?php
            $qid = (int)$r['question_id'];
            $q = $questions_map[$qid] ?? null;
            $title = $q ? $q->title : ('Вопрос #' . $qid);
            $type = $q ? $q->question_type : 'unknown';
            $max_points = $q ? (int)round($q->points) : 1;
            $awarded = (int)round((float)$r['awarded_points']);
            $is_essay = ($type === 'essay');
            $user_answer = $r['user_answer'];

            // Decode if JSON
            $decoded_ans = json_decode($user_answer, true);
            if (is_array($decoded_ans)) {
                $user_answer = implode(', ', $decoded_ans);
            }
            ?>
            <div class="wpo-admin-card wpo-response-card" data-resp-id="<?php echo esc_attr((string)$r['id']); ?>" style="margin-bottom:15px;">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:10px;">
                    <div>
                        <span class="wpo-qcard-index">#<?php echo esc_html((string)($idx + 1)); ?></span>
                        <strong style="font-size:15px;"><?php echo esc_html($title); ?></strong>
                        <span class="wpo-badge" style="margin-left:8px;"><?php echo esc_html($type); ?></span>
                        <?php $wpo_q_group = $q ? \WPOlympiad\QuestionGroups::normalize_name((string)($q->options['group_name'] ?? '')) : ''; ?>
                        <?php if ($wpo_q_group !== ''): ?>
                            <span class="wpo-badge" style="margin-left:6px; background:#e0f2fe; color:#0369a1;"><?php echo esc_html($wpo_q_group); ?></span>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex; gap:12px; align-items:center;">
                        <strong><?php esc_html_e('Макс. балл:', 'wp-olympiad'); ?> <?php echo esc_html((string)$max_points); ?></strong>
                        <button type="button" class="button button-small button-link-delete wpo-btn-del-response wpo-text-danger" data-resp-id="<?php echo esc_attr((string)$r['id']); ?>" title="<?php esc_attr_e('Удалить этот ответ', 'wp-olympiad'); ?>">
                            🗑 <?php esc_html_e('Удалить ответ', 'wp-olympiad'); ?>
                        </button>
                    </div>
                </div>

                <div class="wpo-user-answer-box" style="background:#f8fafc; padding:12px; border-radius:6px; border:1px solid #e2e8f0; margin-bottom:12px;">
                    <span class="description"><strong><?php esc_html_e('Ответ участника:', 'wp-olympiad'); ?></strong></span>
                    <div style="margin-top:6px; font-size:14px; white-space:pre-wrap;"><?php echo esc_html($user_answer ?: '—'); ?></div>
                </div>

                <?php if ($q && !empty($q->options['explanation'])): ?>
                    <div class="description" style="margin-bottom:10px;">
                        💡 <strong><?php esc_html_e('Правильное решение / пояснение:', 'wp-olympiad'); ?></strong> <?php echo esc_html($q->options['explanation']); ?>
                    </div>
                <?php endif; ?>

                <?php if ($type === 'text' || $type === 'essay' || $type === 'word_scramble' || $type === 'dictation'): ?>
                    <div class="wpo-auto-grade-panel" style="margin-bottom:10px; display:flex; gap:12px; align-items:center; flex-wrap:wrap; background:#f0fdf4; border:1px solid #bbf7d0; padding:8px 12px; border-radius:6px;">
                        <button type="button" class="button button-secondary button-small wpo-btn-auto-grade-single" data-resp-id="<?php echo esc_attr((string)$r['id']); ?>">
                            ⚡ <?php esc_html_e('Автопроверить ответ', 'wp-olympiad'); ?>
                        </button>
                        <?php if ($type === 'text' && !empty($q->options['answers'])): ?>
                            <span class="description" style="color:#166534;">
                                <?php esc_html_e('Ключи:', 'wp-olympiad'); ?> <strong><?php echo esc_html(implode(', ', (array)$q->options['answers'])); ?></strong>
                                (<?php echo esc_html($q->options['match_mode'] ?? 'exact'); ?>)
                            </span>
                        <?php elseif ($type === 'word_scramble' && (!empty($q->options['words']) || !empty($q->options['target_word']))): ?>
                            <span class="description" style="color:#166534;">
                                <?php esc_html_e('Целевое слово:', 'wp-olympiad'); ?> <strong><?php echo esc_html(implode(', ', (array)($q->options['words'] ?? [$q->options['target_word']]))); ?></strong>
                            </span>
                        <?php elseif ($type === 'dictation' && !empty($q->options['gaps'])): ?>
                            <span class="description" style="color:#166534;">
                                <?php esc_html_e('Орфограмм в диктанте:', 'wp-olympiad'); ?> <strong><?php echo esc_html((string)count($q->options['gaps'])); ?></strong>
                            </span>
                        <?php elseif ($type === 'fill_blank' && !empty($q->options['blanks'])): ?>
                            <span class="description" style="color:#166534;">
                                <?php esc_html_e('Пропусков в тексте:', 'wp-olympiad'); ?> <strong><?php echo esc_html((string)count($q->options['blanks'])); ?></strong>
                                (<?php echo esc_html($q->options['choice_mode'] ?? 'input'); ?>)
                            </span>
                        <?php elseif ($type === 'essay' && !empty($q->options['keywords'])): ?>
                            <span class="description" style="color:#166534;">
                                <?php esc_html_e('Ключевые понятия:', 'wp-olympiad'); ?> <strong><?php echo esc_html(implode(', ', (array)$q->options['keywords'])); ?></strong>
                            </span>
                        <?php endif; ?>
                        <span class="wpo-auto-grade-status" data-resp-id="<?php echo esc_attr((string)$r['id']); ?>" style="font-size:12px; font-weight:600; margin-left:auto;"></span>
                    </div>
                <?php endif; ?>

                <div class="wpo-grading-inputs" style="display:flex; gap:16px; align-items:center; background:#f1f5f9; padding:10px 14px; border-radius:6px;">
                    <div>
                        <label><strong><?php esc_html_e('Начислено баллов:', 'wp-olympiad'); ?></strong></label>
                        <input type="number" step="1" min="0" max="<?php echo esc_attr((string)$max_points); ?>" class="wpo-grade-points small-text" data-resp-id="<?php echo esc_attr((string)$r['id']); ?>" value="<?php echo esc_attr((string)$awarded); ?>">
                    </div>

                    <div style="flex-grow:1;">
                        <label><strong><?php esc_html_e('Комментарий проверяющего (жюри):', 'wp-olympiad'); ?></strong></label>
                        <input type="text" class="large-text wpo-grade-comment" data-resp-id="<?php echo esc_attr((string)$r['id']); ?>" value="<?php echo esc_attr($r['review_comment']); ?>" placeholder="<?php esc_attr_e('Например: Выкладки верные, допущена арифметическая ошибка в последнем действии.', 'wp-olympiad'); ?>">
                    </div>

                    <div>
                        <label>
                            <input type="checkbox" class="wpo-grade-correct" data-resp-id="<?php echo esc_attr((string)$r['id']); ?>" <?php checked(!empty($r['is_correct'])); ?>>
                            <?php esc_html_e('Засчитать как верный', 'wp-olympiad'); ?>
                        </label>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <div style="margin-top:20px;">
            <button type="button" class="button button-primary button-hero" id="wpo-btn-save-review">
                💾 <?php esc_html_e('Сохранить оценки и утвердить результат', 'wp-olympiad'); ?>
            </button>
            <span class="wpo-review-spinner spinner" style="float:none;"></span>
        </div>
    </form>
</div>
