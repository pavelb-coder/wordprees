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
                <span class="description"><?php esc_html_e('Участник / Организация:', 'wp-olympiad'); ?></span>
                <h4><?php echo esc_html($attempt->participant_name); ?> (<?php echo esc_html($attempt->participant_org ?: '—'); ?>)</h4>
                <div class="description"><?php echo esc_html($attempt->participant_email); ?></div>
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
                    <span class="wpo-badge <?php echo $attempt->passed ? 'wpo-badge-pass' : 'wpo-badge-fail'; ?>">
                        <?php echo $attempt->passed ? esc_html__('Зачёт', 'wp-olympiad') : esc_html__('Не сдан', 'wp-olympiad'); ?>
                    </span>
                </h4>
            </div>
        </div>
    </div>

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
