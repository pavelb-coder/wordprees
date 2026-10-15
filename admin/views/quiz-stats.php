<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap wpo-admin-wrap">
    <div class="wpo-header-bar">
        <h1>
            <span class="dashicons dashicons-chart-bar"></span> <?php esc_html_e('Анализ ответов и статистика олимпиады', 'wp-olympiad'); ?>
        </h1>
        <?php if (!empty($stats) && !empty($stats['completed_attempts'])): ?>
            <div style="display:flex; gap:10px;">
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?action=wp_olympiad_action&do=export_csv&quiz_id=' . $quiz_id), 'wp_olympiad_admin')); ?>" class="button button-secondary">
                    📥 <?php esc_html_e('Экспорт протокола (CSV)', 'wp-olympiad'); ?>
                </a>
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?action=wp_olympiad_action&do=export_matrix_csv&quiz_id=' . $quiz_id), 'wp_olympiad_admin')); ?>" class="button button-primary">
                    📑 <?php esc_html_e('Экспорт сводной матрицы в Excel (CSV)', 'wp-olympiad'); ?>
                </a>
            </div>
        <?php endif; ?>
    </div>

    <!-- Quiz Switcher -->
    <div class="wpo-admin-card" style="margin-bottom:20px; padding:12px 16px;">
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="display:flex; gap:12px; align-items:center;">
            <input type="hidden" name="page" value="wp-olympiad-stats">
            <label for="select_quiz_stats"><strong><?php esc_html_e('Выберите олимпиаду для анализа:', 'wp-olympiad'); ?></strong></label>
            <select name="quiz_id" id="select_quiz_stats" onchange="this.form.submit();">
                <?php foreach ($quizzes as $q): ?>
                    <option value="<?php echo esc_attr((string)$q->id); ?>" <?php selected($quiz_id, $q->id); ?>><?php echo esc_html($q->title); ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <?php if (empty($stats) || empty($stats['completed_attempts'])): ?>
        <div class="wpo-admin-card">
            <p class="wpo-empty-notice"><?php esc_html_e('По этой олимпиаде пока нет завершённых попыток для расчёта аналитики.', 'wp-olympiad'); ?></p>
        </div>
    <?php else: ?>
        <!-- KPI Summary Cards -->
        <div class="wpo-grid-4" style="margin-bottom:24px;">
            <div class="wpo-admin-card">
                <span class="description"><?php esc_html_e('Завершённых работ:', 'wp-olympiad'); ?></span>
                <h2 style="margin:6px 0; color:#1e3a8a;"><?php echo esc_html((string)$stats['completed_attempts']); ?></h2>
                <div style="font-size:12px; color:#64748b;"><?php echo sprintf(esc_html__('Всего начато: %d', 'wp-olympiad'), $stats['total_attempts']); ?></div>
            </div>

            <div class="wpo-admin-card">
                <span class="description"><?php esc_html_e('Процент сдачи (Pass Rate):', 'wp-olympiad'); ?></span>
                <h2 style="margin:6px 0; color:#166534;"><?php echo esc_html(sprintf('%.1f%%', $stats['pass_rate'])); ?></h2>
                <div style="font-size:12px; color:#64748b;"><?php echo sprintf(esc_html__('Зачёт получили: %d чел.', 'wp-olympiad'), $stats['passed_count']); ?></div>
            </div>

            <div class="wpo-admin-card">
                <span class="description"><?php esc_html_e('Средний балл / Медиана:', 'wp-olympiad'); ?></span>
                <h2 style="margin:6px 0; color:#2563eb;"><?php echo esc_html(sprintf('%d б. / %d б.', round($stats['avg_score']), round($stats['median_score']))); ?></h2>
                <div style="font-size:12px; color:#64748b;"><?php echo sprintf(esc_html__('Ст. отклонение σ: %1$.2f | Макс: %2$d', 'wp-olympiad'), $stats['std_dev'], round($stats['max_achieved'])); ?></div>
            </div>

            <div class="wpo-admin-card">
                <span class="description"><?php esc_html_e('Среднее время решения:', 'wp-olympiad'); ?></span>
                <h2 style="margin:6px 0; color:#0f172a;">
                    <?php echo esc_html(sprintf('%02d:%02d', floor($stats['avg_duration_seconds'] / 60), $stats['avg_duration_seconds'] % 60)); ?>
                </h2>
                <div style="font-size:12px; color:#991b1b;"><?php echo sprintf(esc_html__('Всего смен вкладок: %d', 'wp-olympiad'), $stats['total_tab_switches']); ?></div>
            </div>
        </div>

        <!-- Tab Navigation (Online Test Pad Style) -->
        <h2 class="nav-tab-wrapper wpo-stats-nav-tabs" style="margin-bottom:20px;">
            <a href="#tab-matrix" class="nav-tab nav-tab-active" data-tab="tab-matrix">
                📑 <?php esc_html_e('Сводная матрица ответов (Сводные данные)', 'wp-olympiad'); ?>
            </a>
            <a href="#tab-items" class="nav-tab" data-tab="tab-items">
                📊 <?php esc_html_e('Анализ заданий и дистракторов (Item Analysis)', 'wp-olympiad'); ?>
            </a>
            <a href="#tab-distribution" class="nav-tab" data-tab="tab-distribution">
                📈 <?php esc_html_e('Распределение баллов и психометрика', 'wp-olympiad'); ?>
            </a>
        </h2>

        <!-- TAB 1: PIVOT MATRIX (Online Test Pad Core Feature) -->
        <div id="tab-matrix" class="wpo-tab-content active">
            <div class="wpo-admin-card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
                    <div>
                        <h2 style="margin:0;"><?php esc_html_e('Сводная таблица ответов участников', 'wp-olympiad'); ?></h2>
                        <p class="description" style="margin:4px 0 0 0;">
                            <?php esc_html_e('Строки — участники, столбцы — вопросы олимпиады. Наведите на ячейку или кликните для просмотра точного ответа.', 'wp-olympiad'); ?>
                        </p>
                    </div>
                    <div style="display:flex; gap:12px; align-items:center; font-size:12px;">
                        <span><span style="display:inline-block; width:12px; height:12px; background:#dcfce7; border:1px solid #16a34a; border-radius:3px;"></span> <?php esc_html_e('Верно', 'wp-olympiad'); ?></span>
                        <span><span style="display:inline-block; width:12px; height:12px; background:#fef9c3; border:1px solid #ca8a04; border-radius:3px;"></span> <?php esc_html_e('Частично', 'wp-olympiad'); ?></span>
                        <span><span style="display:inline-block; width:12px; height:12px; background:#fee2e2; border:1px solid #dc2626; border-radius:3px;"></span> <?php esc_html_e('Неверно / Штраф', 'wp-olympiad'); ?></span>
                        <span><span style="display:inline-block; width:12px; height:12px; background:#dbeafe; border:1px solid #2563eb; border-radius:3px;"></span> <?php esc_html_e('На проверке', 'wp-olympiad'); ?></span>
                    </div>
                </div>

                <div class="wpo-matrix-scroll-wrap" style="overflow-x:auto; max-width:100%; border:1px solid #e2e8f0; border-radius:6px;">
                    <table class="wp-list-table widefat fixed striped wpo-matrix-table" style="min-width:<?php echo esc_attr((string)(400 + count($matrix_data['questions']) * 110)); ?>px;">
                        <thead>
                            <tr>
                                <th style="width:40px; text-align:center;">#</th>
                                <th style="width:180px;"><?php esc_html_e('Участник', 'wp-olympiad'); ?></th>
                                <th style="width:75px; text-align:center;"><?php esc_html_e('Баллы', 'wp-olympiad'); ?></th>
                                <th style="width:65px; text-align:center;"><?php esc_html_e('%', 'wp-olympiad'); ?></th>
                                <th style="width:70px; text-align:center;"><?php esc_html_e('Время', 'wp-olympiad'); ?></th>
                                <?php foreach ($matrix_data['questions'] as $q_idx => $mq): ?>
                                    <th style="width:110px; text-align:center;" title="<?php echo esc_attr($mq->title); ?>">
                                        <div style="font-weight:700;">В<?php echo esc_html((string)($q_idx + 1)); ?></div>
                                        <div style="font-size:11px; font-weight:normal; color:#64748b;"><?php echo esc_html(sprintf('%d б.', round($mq->points))); ?></div>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($matrix_data['attempts'] as $a_idx => $att): ?>
                                <tr>
                                    <td style="text-align:center;"><strong><?php echo esc_html((string)($a_idx + 1)); ?></strong></td>
                                    <td>
                                        <strong><?php echo esc_html($att['participant_name']); ?></strong>
                                        <div style="font-size:11px; color:#64748b;"><?php echo esc_html($att['participant_email']); ?></div>
                                    </td>
                                    <td style="text-align:center;">
                                        <span class="badge" style="background:#e0f2fe; color:#0369a1; padding:2px 6px; border-radius:4px; font-weight:700;">
                                            <?php echo esc_html((string)(int)round($att['score'])); ?>
                                        </span>
                                    </td>
                                    <td style="text-align:center; font-weight:700; color:<?php echo $att['passed'] ? '#166534' : '#991b1b'; ?>;">
                                        <?php echo esc_html(sprintf('%.0f%%', $att['percentage'])); ?>
                                    </td>
                                    <td style="text-align:center; font-size:12px; color:#475569;">
                                        <?php echo esc_html(sprintf('%02d:%02d', floor($att['duration_seconds'] / 60), $att['duration_seconds'] % 60)); ?>
                                    </td>
                                    <?php foreach ($matrix_data['questions'] as $mq): ?>
                                        <?php
                                        $resp = $att['responses'][$mq->id] ?? null;
                                        $pts = $resp ? (float)$resp['awarded_points'] : 0.0;
                                        $is_corr = $resp ? $resp['is_correct'] : 0;
                                        $needs_rev = $resp ? !empty($resp['needs_review']) : false;

                                        $cell_bg = '#f8fafc';
                                        $cell_color = '#334155';
                                        $cell_border = '#cbd5e1';

                                        if ($needs_rev) {
                                            $cell_bg = '#dbeafe';
                                            $cell_color = '#1e40af';
                                            $cell_border = '#93c5fd';
                                        } elseif ($is_corr === 1 || ($mq->points > 0 && abs($pts - $mq->points) < 0.01)) {
                                            $cell_bg = '#dcfce7';
                                            $cell_color = '#166534';
                                            $cell_border = '#86efac';
                                        } elseif ($pts > 0) {
                                            $cell_bg = '#fef9c3';
                                            $cell_color = '#854d0e';
                                            $cell_border = '#fde047';
                                        } else {
                                            $cell_bg = '#fee2e2';
                                            $cell_color = '#991b1b';
                                            $cell_border = '#fca5a5';
                                        }

                                        $tooltip_text = sprintf(
                                            "%s\nОтвет: %s\nБалл: %d / %d",
                                            $mq->title,
                                            $resp ? $resp['formatted_answer'] : '—',
                                            round($pts),
                                            round($mq->points)
                                        );
                                        ?>
                                        <td style="text-align:center; background:<?php echo esc_attr($cell_bg); ?>; border:1px solid <?php echo esc_attr($cell_border); ?>; padding:6px 4px;"
                                            title="<?php echo esc_attr($tooltip_text); ?>"
                                            class="wpo-matrix-cell"
                                            data-answer="<?php echo esc_attr($resp ? $resp['formatted_answer'] : '—'); ?>"
                                            data-question="<?php echo esc_attr($mq->title); ?>"
                                            data-points="<?php echo esc_attr(sprintf('%d / %d', round($pts), round($mq->points))); ?>">
                                            <div style="font-weight:700; color:<?php echo esc_attr($cell_color); ?>; font-size:12px;">
                                                <?php echo esc_html((string)(int)round($pts)); ?>
                                            </div>
                                            <div style="font-size:10px; color:<?php echo esc_attr($cell_color); ?>; max-width:90px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; margin:0 auto;">
                                                <?php echo esc_html($resp ? $resp['formatted_answer'] : '—'); ?>
                                            </div>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background:#f1f5f9; font-weight:700;">
                                <td colspan="5" style="text-align:right;"><?php esc_html_e('Решаемость / Ср. балл:', 'wp-olympiad'); ?></td>
                                <?php foreach ($stats['questions'] as $sq): ?>
                                    <td style="text-align:center;">
                                        <div style="font-size:11px;"><?php echo esc_html(sprintf('%.0f%%', $sq['accuracy'])); ?></div>
                                        <div style="font-size:10px; color:#64748b;"><?php echo esc_html(sprintf('%.2f б.', $sq['avg_points'])); ?></div>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB 2: ITEM ANALYSIS & DISTRACTORS (Online Test Pad Psychometrics) -->
        <div id="tab-items" class="wpo-tab-content" style="display:none;">
            <div class="wpo-admin-card" style="margin-bottom:20px;">
                <h2>📊 <?php esc_html_e('Психометрический анализ заданий (Item Response Analysis)', 'wp-olympiad'); ?></h2>
                <p class="description">
                    <?php esc_html_e('Оценка трудности (Difficulty Index P) и дифференцирующей способности (Discrimination Index D по методу крайних 27% групп Келли). Помогает выявить бракованные задания, формулировки с ловушками и неработающие дистракторы.', 'wp-olympiad'); ?>
                </p>

                <table class="wp-list-table widefat fixed striped" style="margin-top:16px;">
                    <thead>
                        <tr>
                            <th style="width: 45px;">#</th>
                            <th><?php esc_html_e('Задание / Вопрос', 'wp-olympiad'); ?></th>
                            <th style="width: 130px;"><?php esc_html_e('Тип', 'wp-olympiad'); ?></th>
                            <th style="width: 90px; text-align:center;"><?php esc_html_e('Макс.', 'wp-olympiad'); ?></th>
                            <th style="width: 140px; text-align:center;"><?php esc_html_e('Трудность P (%)', 'wp-olympiad'); ?></th>
                            <th style="width: 170px; text-align:center;"><?php esc_html_e('Дискриминативность D', 'wp-olympiad'); ?></th>
                            <th style="width: 100px; text-align:center;"><?php esc_html_e('Ср. балл', 'wp-olympiad'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stats['questions'] as $idx => $q): ?>
                            <?php
                            $diff_color = '#10b981';
                            $diff_text = __('Оптимальная', 'wp-olympiad');
                            if ($q['difficulty_label'] === 'hard') {
                                $diff_color = '#ef4444';
                                $diff_text = __('Трудный', 'wp-olympiad');
                            } elseif ($q['difficulty_label'] === 'easy') {
                                $diff_color = '#3b82f6';
                                $diff_text = __('Легкий', 'wp-olympiad');
                            }

                            $disc_color = '#10b981';
                            $disc_text = __('Отличная', 'wp-olympiad');
                            if ($q['discrimination_status'] === 'good') {
                                $disc_color = '#10b981';
                                $disc_text = __('Хорошая', 'wp-olympiad');
                            } elseif ($q['discrimination_status'] === 'fair') {
                                $disc_color = '#f59e0b';
                                $disc_text = __('Удовл.', 'wp-olympiad');
                            } elseif ($q['discrimination_status'] === 'poor') {
                                $disc_color = '#94a3b8';
                                $disc_text = __('Слабая', 'wp-olympiad');
                            } elseif ($q['discrimination_status'] === 'negative') {
                                $disc_color = '#dc2626';
                                $disc_text = __('⚠️ Отрицательная', 'wp-olympiad');
                            }
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html((string)($idx + 1)); ?></strong></td>
                                <td>
                                    <strong><?php echo esc_html($q['title']); ?></strong>
                                </td>
                                <td><code><?php echo esc_html($q['type']); ?></code></td>
                                <td style="text-align:center;"><?php echo esc_html(sprintf('%d', round($q['max_points']))); ?></td>
                                <td style="text-align:center;">
                                    <div style="font-weight:700; color:<?php echo esc_attr($diff_color); ?>;">
                                        <?php echo esc_html(sprintf('%.1f%%', $q['difficulty_index'])); ?>
                                    </div>
                                    <span style="font-size:11px; color:#64748b;"><?php echo esc_html($diff_text); ?></span>
                                </td>
                                <td style="text-align:center;">
                                    <div style="font-weight:700; color:<?php echo esc_attr($disc_color); ?>;">
                                        <?php echo esc_html(sprintf('%+.2f', $q['discrimination_index'])); ?>
                                    </div>
                                    <span style="font-size:11px; color:<?php echo esc_attr($disc_color); ?>; font-weight:600;">
                                        <?php echo esc_html($disc_text); ?>
                                    </span>
                                </td>
                                <td style="text-align:center;"><strong><?php echo esc_html(sprintf('%.2f', $q['avg_points'])); ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Distractor Analysis Cards for Each Question -->
            <div class="wpo-admin-card">
                <h2>🎯 <?php esc_html_e('Анализ дистракторов и частоты выборов ответов', 'wp-olympiad'); ?></h2>
                <p class="description">
                    <?php esc_html_e('Распределение голосов участников по вариантам ответов. Помогает оценить привлекательность неправильных ответов (дистракторов).', 'wp-olympiad'); ?>
                </p>

                <div class="wpo-distractor-grid" style="display:flex; flex-direction:column; gap:20px; margin-top:16px;">
                    <?php foreach ($stats['questions'] as $q_idx => $q): ?>
                        <div style="border:1px solid #e2e8f0; border-radius:8px; padding:16px; background:#fafafa;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                                <h3 style="margin:0;">
                                    #<?php echo esc_html((string)($q_idx + 1)); ?>. <?php echo esc_html($q['title']); ?>
                                </h3>
                                <div>
                                    <span style="background:#e2e8f0; padding:2px 8px; border-radius:12px; font-size:12px;">
                                        <?php echo esc_html($q['type']); ?>
                                    </span>
                                    <span style="background:#dbeafe; color:#1e40af; padding:2px 8px; border-radius:12px; font-size:12px; margin-left:6px;">
                                        <?php echo sprintf(esc_html__('Решаемость: %.1f%%', 'wp-olympiad'), $q['accuracy']); ?>
                                    </span>
                                </div>
                            </div>

                            <?php if (!empty($q['distractors'])): ?>
                                <div style="display:flex; flex-direction:column; gap:8px;">
                                    <?php foreach ($q['distractors'] as $dst): ?>
                                        <?php
                                        $is_key = $dst['is_correct'];
                                        $bar_bg = $is_key ? '#22c55e' : '#94a3b8';
                                        $row_border = $is_key ? 'border:1px solid #86efac; background:#f0fdf4;' : 'border:1px solid #e2e8f0; background:#fff;';
                                        ?>
                                        <div style="display:flex; align-items:center; gap:12px; padding:8px 12px; border-radius:6px; <?php echo esc_attr($row_border); ?>">
                                            <div style="width:24px; text-align:center;">
                                                <?php echo $is_key ? '<span style="color:#16a34a; font-weight:700;" title="Правильный ответ">✓</span>' : '○'; ?>
                                            </div>
                                            <div style="width:250px; font-weight:<?php echo $is_key ? '700' : '400'; ?>; color:<?php echo $is_key ? '#166534' : '#1e293b'; ?>;">
                                                <?php echo esc_html($dst['text']); ?>
                                            </div>
                                            <div style="flex-grow:1; display:flex; align-items:center; gap:10px;">
                                                <div style="flex-grow:1; height:10px; background:#e2e8f0; border-radius:5px; overflow:hidden;">
                                                    <div style="width:<?php echo esc_attr((string)$dst['percentage']); ?>%; height:100%; background:<?php echo esc_attr($bar_bg); ?>;"></div>
                                                </div>
                                                <span style="min-width:45px; text-align:right; font-weight:700; font-size:12px;">
                                                    <?php echo esc_html(sprintf('%.1f%%', $dst['percentage'])); ?>
                                                </span>
                                                <span style="min-width:55px; text-align:right; font-size:12px; color:#64748b;">
                                                    (<?php echo esc_html((string)$dst['count']); ?> чел.)
                                                </span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php elseif (!empty($q['top_answers'])): ?>
                                <div style="font-size:13px; margin-bottom:6px; font-weight:600; color:#475569;">
                                    <?php esc_html_e('Популярные ответы участников:', 'wp-olympiad'); ?>
                                </div>
                                <table class="widefat" style="background:#fff;">
                                    <thead>
                                        <tr>
                                            <th><?php esc_html_e('Ответ участника', 'wp-olympiad'); ?></th>
                                            <th style="width:100px; text-align:center;"><?php esc_html_e('Выбрали', 'wp-olympiad'); ?></th>
                                            <th style="width:100px; text-align:center;"><?php esc_html_e('Доля (%)', 'wp-olympiad'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($q['top_answers'] as $tans): ?>
                                            <tr>
                                                <td><code><?php echo esc_html($tans['answer']); ?></code></td>
                                                <td style="text-align:center;"><?php echo esc_html((string)$tans['count']); ?> чел.</td>
                                                <td style="text-align:center; font-weight:700;"><?php echo esc_html(sprintf('%.1f%%', $tans['percentage'])); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <p style="color:#64748b; font-size:13px; margin:0;">
                                    <?php esc_html_e('Развёрнутый или индивидуальный ответ (проверяется экспертом вручную).', 'wp-olympiad'); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- TAB 3: SCORE DISTRIBUTION (Online Test Pad Psychometric Distribution) -->
        <div id="tab-distribution" class="wpo-tab-content" style="display:none;">
            <div class="wpo-admin-card" style="margin-bottom:20px;">
                <h2>📈 <?php esc_html_e('Гистограмма распределения результатов участников', 'wp-olympiad'); ?></h2>
                <p class="description">
                    <?php esc_html_e('Распределение итоговых результатов олимпиады по пяти диапазонам баллов.', 'wp-olympiad'); ?>
                </p>

                <div style="display:flex; flex-direction:column; gap:16px; margin-top:20px;">
                    <?php
                    $bucket_labels = [
                        '0-20'   => __('0 – 20% (Неудовлетворительно)', 'wp-olympiad'),
                        '21-40'  => __('21 – 40% (Слабый результат)', 'wp-olympiad'),
                        '41-60'  => __('41 – 60% (Удовлетворительно)', 'wp-olympiad'),
                        '61-80'  => __('61 – 80% (Хороший результат)', 'wp-olympiad'),
                        '81-100' => __('81 – 100% (Отличный результат / Призёры)', 'wp-olympiad'),
                    ];
                    $bucket_colors = [
                        '0-20'   => '#ef4444',
                        '21-40'  => '#f97316',
                        '41-60'  => '#eab308',
                        '61-80'  => '#3b82f6',
                        '81-100' => '#10b981',
                    ];

                    $total_comp = max(1, $stats['completed_attempts']);
                    foreach ($stats['distribution'] as $bkey => $bcount):
                        $bpct = round(($bcount / $total_comp) * 100, 1);
                    ?>
                        <div>
                            <div style="display:flex; justify-content:space-between; margin-bottom:6px; font-weight:600;">
                                <span><?php echo esc_html($bucket_labels[$bkey] ?? $bkey); ?></span>
                                <span><?php echo esc_html(sprintf('%d чел. (%.1f%%)', $bcount, $bpct)); ?></span>
                            </div>
                            <div style="height:24px; background:#e2e8f0; border-radius:6px; overflow:hidden; display:flex;">
                                <div style="width:<?php echo esc_attr((string)$bpct); ?>%; height:100%; background:<?php echo esc_attr($bucket_colors[$bkey] ?? '#3b82f6'); ?>; transition:width 0.4s ease;"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Psychometric Metrics Table -->
            <div class="wpo-admin-card">
                <h2>📐 <?php esc_html_e('Описательные психометрические показатели генеральной совокупности', 'wp-olympiad'); ?></h2>
                <table class="wp-list-table widefat fixed striped" style="margin-top:12px;">
                    <tbody>
                        <tr>
                            <td style="width:300px;"><strong><?php esc_html_e('Среднее арифметическое (Mean):', 'wp-olympiad'); ?></strong></td>
                            <td><?php echo esc_html(sprintf('%.2f баллов (%.1f%%)', $stats['avg_score'], $stats['avg_percentage'])); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Медианный балл (Median):', 'wp-olympiad'); ?></strong></td>
                            <td><?php echo esc_html(sprintf('%.2f баллов', $stats['median_score'])); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Стандартное отклонение (Standard Deviation σ):', 'wp-olympiad'); ?></strong></td>
                            <td><?php echo esc_html(sprintf('%.2f', $stats['std_dev'])); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Размах выборки (Min - Max):', 'wp-olympiad'); ?></strong></td>
                            <td><?php echo esc_html(sprintf('от %.2f до %.2f баллов', $stats['min_achieved'], $stats['max_achieved'])); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Всего успешных сдач (Pass Rate):', 'wp-olympiad'); ?></strong></td>
                            <td><?php echo esc_html(sprintf('%.1f%% (%d из %d участников)', $stats['pass_rate'], $stats['passed_count'], $stats['completed_attempts'])); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var tabs = document.querySelectorAll('.wpo-stats-nav-tabs a');
    var contents = document.querySelectorAll('.wpo-tab-content');

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function (e) {
            e.preventDefault();
            var targetId = tab.getAttribute('data-tab');

            tabs.forEach(function (t) { t.classList.remove('nav-tab-active'); });
            contents.forEach(function (c) { c.style.display = 'none'; });

            tab.classList.add('nav-tab-active');
            var target = document.getElementById(targetId);
            if (target) {
                target.style.display = 'block';
            }
        });
    });
});
</script>
