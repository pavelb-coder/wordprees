<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap wpo-admin-wrap">
    <div class="wpo-header-bar">
        <h1>
            <span class="dashicons dashicons-podium"></span> <?php esc_html_e('Рейтинговые таблицы олимпиад', 'wp-olympiad'); ?>
        </h1>
        <?php if ($quiz_id > 0): ?>
            <div>
                <input type="text" class="regular-text code wpo-copyable-input" readonly value='[wp_olympiad_leaderboard id="<?php echo esc_attr((string)$quiz_id); ?>"]' onclick="this.select();" title="<?php esc_attr_e('Шорткод для вставки рейтинга на сайт', 'wp-olympiad'); ?>">
            </div>
        <?php endif; ?>
    </div>

    <!-- Quiz Switcher -->
    <div class="wpo-admin-card" style="margin-bottom:15px; padding:12px 16px;">
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="display:flex; gap:12px; align-items:center;">
            <input type="hidden" name="page" value="wp-olympiad-leaderboard">
            <label for="select_quiz"><strong><?php esc_html_e('Выберите олимпиаду:', 'wp-olympiad'); ?></strong></label>
            <select name="quiz_id" id="select_quiz" onchange="this.form.submit();">
                <?php foreach ($quizzes as $q): ?>
                    <option value="<?php echo esc_attr((string)$q->id); ?>" <?php selected($quiz_id, $q->id); ?>><?php echo esc_html($q->title); ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <div class="wpo-admin-card">
        <?php if (empty($leaderboard)): ?>
            <p class="wpo-empty-notice"><?php esc_html_e('В этой олимпиаде пока нет завершённых попыток для формирования рейтинга.', 'wp-olympiad'); ?></p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 80px;"><?php esc_html_e('Место', 'wp-olympiad'); ?></th>
                        <th><?php esc_html_e('Участник', 'wp-olympiad'); ?></th>
                        <th><?php esc_html_e('Организация / Класс', 'wp-olympiad'); ?></th>
                        <th style="width: 140px;"><?php esc_html_e('Баллы', 'wp-olympiad'); ?></th>
                        <th style="width: 110px;"><?php esc_html_e('Время', 'wp-olympiad'); ?></th>
                        <th style="width: 110px;"><?php esc_html_e('Вкладки', 'wp-olympiad'); ?></th>
                        <th style="width: 100px;"><?php esc_html_e('Детали', 'wp-olympiad'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($leaderboard as $row): ?>
                        <?php
                        $badge = '';
                        if ($row['rank'] === 1) {
                            $badge = '🥇';
                        } elseif ($row['rank'] === 2) {
                            $badge = '🥈';
                        } elseif ($row['rank'] === 3) {
                            $badge = '🥉';
                        }
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo $badge ? $badge . ' ' : ''; ?>#<?php echo esc_html((string)$row['rank']); ?></strong>
                            </td>
                            <td>
                                <strong><?php echo esc_html($row['name']); ?></strong>
                            </td>
                            <td><?php echo esc_html($row['org'] ?: '—'); ?></td>
                            <td>
                                <strong><?php echo esc_html(sprintf('%d / %d', round($row['score']), round($row['max_score']))); ?></strong>
                                <span class="description">(<?php echo esc_html(sprintf('%d%%', round($row['percentage']))); ?>)</span>
                            </td>
                            <td><code><?php echo esc_html($row['duration_formatted']); ?></code></td>
                            <td>
                                <?php if ($row['tab_switches'] > 0): ?>
                                    <span class="wpo-badge wpo-badge-fail">⚠️ <?php echo esc_html((string)$row['tab_switches']); ?></span>
                                <?php else: ?>
                                    <span style="color:#10b981;">0</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-olympiad-review&attempt_id=' . $row['attempt_id'])); ?>" class="button button-small">
                                    <?php esc_html_e('Просмотр', 'wp-olympiad'); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
