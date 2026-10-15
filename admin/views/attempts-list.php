<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap wpo-admin-wrap">
    <div class="wpo-header-bar">
        <h1>
            <span class="dashicons dashicons-clipboard"></span> <?php esc_html_e('Протоколы и результаты попыток', 'wp-olympiad'); ?>
        </h1>
        <?php if ($quiz_id > 0): ?>
            <div style="display:flex; gap:10px; align-items:center;">
                <a href="<?php echo esc_url(admin_url('admin.php?wpo_action=export_csv&quiz_id=' . $quiz_id)); ?>" class="button button-primary">
                    📥 <?php esc_html_e('Экспорт ответов в Excel / CSV', 'wp-olympiad'); ?>
                </a>
                <?php if (!empty($attempts)): ?>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=wp-olympiad-attempts&wpo_action=clear_quiz_attempts&quiz_id=' . $quiz_id), 'wpo_clear_quiz_attempts_' . $quiz_id)); ?>" class="button button-secondary wpo-text-danger" onclick="return confirm('<?php echo esc_js(__('ВНИМАНИЕ! Вы действительно хотите удалить ВСЕ ответы и попытки участников по данной олимпиаде? Это действие необратимо.', 'wp-olympiad')); ?>');">
                        🗑 <?php esc_html_e('Очистить все ответы', 'wp-olympiad'); ?>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if (isset($_GET['deleted'])): ?>
        <div class="notice notice-success is-dismissible" style="margin-bottom:15px;"><p><?php esc_html_e('Попытка и все ответы участника успешно удалены.', 'wp-olympiad'); ?></p></div>
    <?php elseif (isset($_GET['bulk_deleted'])): ?>
        <div class="notice notice-success is-dismissible" style="margin-bottom:15px;"><p><?php echo sprintf(esc_html__('Успешно удалено выбранных попыток/ответов: %d.', 'wp-olympiad'), (int)$_GET['bulk_deleted']); ?></p></div>
    <?php elseif (isset($_GET['cleared'])): ?>
        <div class="notice notice-info is-dismissible" style="margin-bottom:15px;"><p><?php echo sprintf(esc_html__('Все ответы и результаты олимпиады успешно очищены (удалено: %d).', 'wp-olympiad'), (int)$_GET['cleared']); ?></p></div>
    <?php endif; ?>

    <!-- Filter Bar -->
    <div class="wpo-admin-card" style="margin-bottom:15px; padding:12px 16px;">
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
            <input type="hidden" name="page" value="wp-olympiad-attempts">

            <label for="filter_quiz"><strong><?php esc_html_e('Олимпиада:', 'wp-olympiad'); ?></strong></label>
            <select name="quiz_id" id="filter_quiz" onchange="this.form.submit();">
                <option value="0"><?php esc_html_e('— Все олимпиады —', 'wp-olympiad'); ?></option>
                <?php foreach ($quizzes as $q): ?>
                    <option value="<?php echo esc_attr((string)$q->id); ?>" <?php selected($quiz_id, $q->id); ?>><?php echo esc_html($q->title); ?></option>
                <?php endforeach; ?>
            </select>

            <label for="filter_status"><strong><?php esc_html_e('Статус:', 'wp-olympiad'); ?></strong></label>
            <select name="status" id="filter_status" onchange="this.form.submit();">
                <option value=""><?php esc_html_e('— Все статусы —', 'wp-olympiad'); ?></option>
                <option value="completed" <?php selected($status, 'completed'); ?>><?php esc_html_e('Завершено', 'wp-olympiad'); ?></option>
                <option value="needs_review" <?php selected($status, 'needs_review'); ?>><?php esc_html_e('Требует проверки жюри', 'wp-olympiad'); ?></option>
                <option value="in_progress" <?php selected($status, 'in_progress'); ?>><?php esc_html_e('В процессе', 'wp-olympiad'); ?></option>
                <option value="disqualified" <?php selected($status, 'disqualified'); ?>><?php esc_html_e('Дисквалифицирован', 'wp-olympiad'); ?></option>
            </select>

            <button type="submit" class="button"><?php esc_html_e('Применить', 'wp-olympiad'); ?></button>
        </form>
    </div>

    <div class="wpo-admin-card">
        <?php if (empty($attempts)): ?>
            <p class="wpo-empty-notice"><?php esc_html_e('Попыток не найдено.', 'wp-olympiad'); ?></p>
        <?php else: ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=wp-olympiad-attempts' . ($quiz_id > 0 ? '&quiz_id=' . $quiz_id : ''))); ?>" id="wpo-bulk-attempts-form">
                <?php wp_nonce_field('wpo_bulk_attempts_nonce'); ?>
                <input type="hidden" name="quiz_id" value="<?php echo esc_attr((string)$quiz_id); ?>">
                <input type="hidden" name="status" value="<?php echo esc_attr($status); ?>">

                <!-- Bulk Actions Toolbar -->
                <div class="tablenav top" style="margin-bottom:12px; display:flex; justify-content:space-between; align-items:center;">
                    <div class="alignleft actions bulkactions" style="display:flex; gap:8px; align-items:center;">
                        <select name="bulk_action" id="bulk-action-selector-top">
                            <option value="-1"><?php esc_html_e('Массовые действия', 'wp-olympiad'); ?></option>
                            <option value="bulk_delete"><?php esc_html_e('Удалить выбранные', 'wp-olympiad'); ?></option>
                        </select>
                        <button type="submit" class="button action" id="doaction"><?php esc_html_e('Применить', 'wp-olympiad'); ?></button>
                    </div>
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php echo sprintf(esc_html__('Всего попыток: %d', 'wp-olympiad'), count($attempts)); ?></span>
                    </div>
                </div>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <td class="manage-column column-cb check-column" style="width: 32px; padding: 11px 0 0 8px;">
                                <input type="checkbox" id="wpo-cb-select-all">
                            </td>
                            <th style="width: 60px;">ID</th>
                            <th><?php esc_html_e('Участник', 'wp-olympiad'); ?></th>
                            <th><?php esc_html_e('Организация / Класс', 'wp-olympiad'); ?></th>
                            <th style="width: 140px;"><?php esc_html_e('Дата старта', 'wp-olympiad'); ?></th>
                            <th style="width: 90px;"><?php esc_html_e('Время', 'wp-olympiad'); ?></th>
                            <th style="width: 110px;"><?php esc_html_e('Баллы', 'wp-olympiad'); ?></th>
                            <th style="width: 95px;"><?php esc_html_e('Вкладки', 'wp-olympiad'); ?></th>
                            <th style="width: 130px;"><?php esc_html_e('Статус', 'wp-olympiad'); ?></th>
                            <th style="width: 140px; text-align:right;"><?php esc_html_e('Действия', 'wp-olympiad'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($attempts as $att): ?>
                            <?php
                            $m = floor($att->duration_seconds / 60);
                            $s = $att->duration_seconds % 60;
                            ?>
                            <tr>
                                <th class="check-column" style="vertical-align:middle; padding:8px 0 0 8px;">
                                    <input type="checkbox" name="attempt_ids[]" value="<?php echo esc_attr((string)$att->id); ?>" class="wpo-attempt-checkbox">
                                </th>
                                <td><code>#<?php echo esc_html((string)$att->id); ?></code></td>
                                <td>
                                    <strong><?php echo esc_html($att->participant_name); ?></strong>
                                    <?php if ($att->participant_email): ?>
                                        <div class="description"><?php echo esc_html($att->participant_email); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($att->participant_org ?: '—'); ?></td>
                                <td><?php echo esc_html(date_i18n('d.m.Y H:i', strtotime($att->started_at))); ?></td>
                                <td><code><?php echo esc_html(sprintf('%02d:%02d', $m, $s)); ?></code></td>
                                <td>
                                    <strong><?php echo esc_html(sprintf('%d / %d', round($att->score), round($att->max_score))); ?></strong>
                                    <span class="description">(<?php echo esc_html(sprintf('%d%%', round($att->percentage))); ?>)</span>
                                </td>
                                <td>
                                    <?php if ($att->tab_switches > 0): ?>
                                        <span class="wpo-badge wpo-badge-fail" title="<?php esc_attr_e('Количество уходов с вкладки теста', 'wp-olympiad'); ?>">
                                            ⚠️ <?php echo esc_html((string)$att->tab_switches); ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color:#10b981;">0</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($att->status === 'needs_review'): ?>
                                        <span class="wpo-badge wpo-badge-review">🔍 <?php esc_html_e('Проверка', 'wp-olympiad'); ?></span>
                                    <?php elseif ($att->status === 'disqualified'): ?>
                                        <span class="wpo-badge wpo-badge-fail">⛔ <?php esc_html_e('Дисквал.', 'wp-olympiad'); ?></span>
                                    <?php elseif ($att->passed): ?>
                                        <span class="wpo-badge wpo-badge-pass">✓ <?php esc_html_e('Зачёт', 'wp-olympiad'); ?></span>
                                    <?php else: ?>
                                        <span class="wpo-badge wpo-badge-fail">✕ <?php esc_html_e('Не сдан', 'wp-olympiad'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right;">
                                    <div style="display:inline-flex; gap:4px; align-items:center;">
                                        <a href="<?php echo esc_url(admin_url('admin.php?page=wp-olympiad-review&attempt_id=' . $att->id)); ?>" class="button button-small" title="<?php esc_attr_e('Просмотр и проверка ответов', 'wp-olympiad'); ?>">
                                            <?php echo ($att->status === 'needs_review') ? esc_html__('Проверить', 'wp-olympiad') : esc_html__('Детали', 'wp-olympiad'); ?>
                                        </a>
                                        <a href="<?php echo esc_url(admin_url('admin.php?wpo_action=export_attempt_csv&attempt_id=' . $att->id)); ?>" class="button button-small" title="<?php esc_attr_e('Экспорт ответов участника в CSV', 'wp-olympiad'); ?>">
                                            📥
                                        </a>
                                        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=wp-olympiad-attempts&wpo_action=delete_attempt&attempt_id=' . $att->id . ($quiz_id > 0 ? '&quiz_id=' . $quiz_id : '')), 'wpo_delete_attempt_' . $att->id)); ?>" class="button button-small button-link-delete wpo-text-danger" title="<?php esc_attr_e('Удалить эту попытку и все ответы участника', 'wp-olympiad'); ?>" onclick="return confirm('<?php echo esc_js(__('Вы уверены, что хотите удалить эту попытку и все ответы участника? Это действие необратимо.', 'wp-olympiad')); ?>');">
                                            🗑
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </form>
        <?php endif; ?>
    </div>
</div>
