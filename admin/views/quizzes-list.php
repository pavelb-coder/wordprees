<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap wpo-admin-wrap">
    <div class="wpo-header-bar">
        <h1 class="wp-heading-inline">
            <span class="dashicons dashicons-awards"></span> <?php esc_html_e('Олимпиады и тестирование', 'wp-olympiad'); ?>
        </h1>
        <a href="<?php echo esc_url(admin_url('admin.php?page=wp-olympiad-edit')); ?>" class="page-title-action button-primary">
            + <?php esc_html_e('Создать олимпиаду', 'wp-olympiad'); ?>
        </a>
    </div>

    <?php if (isset($_GET['updated'])): ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Настройки олимпиады сохранены.', 'wp-olympiad'); ?></p></div>
    <?php elseif (isset($_GET['deleted'])): ?>
        <div class="notice notice-info is-dismissible"><p><?php esc_html_e('Олимпиада удалена.', 'wp-olympiad'); ?></p></div>
    <?php endif; ?>

    <div class="wpo-admin-card">
        <?php if (empty($quizzes)): ?>
            <div class="wpo-empty-state">
                <span class="dashicons dashicons-welcome-learn-more wpo-empty-icon"></span>
                <h2><?php esc_html_e('Пока нет ни одной олимпиады', 'wp-olympiad'); ?></h2>
                <p><?php esc_html_e('Создайте свою первую олимпиаду или тест, настройте ограничения по времени и добавьте вопросы.', 'wp-olympiad'); ?></p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-olympiad-edit')); ?>" class="button button-primary button-hero">
                    <?php esc_html_e('Создать первую олимпиаду', 'wp-olympiad'); ?>
                </a>
            </div>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 50px;">ID</th>
                        <th><?php esc_html_e('Название', 'wp-olympiad'); ?></th>
                        <th style="width: 140px;"><?php esc_html_e('Вопросы', 'wp-olympiad'); ?></th>
                        <th style="width: 120px;"><?php esc_html_e('Таймер', 'wp-olympiad'); ?></th>
                        <th style="width: 110px;"><?php esc_html_e('Попыток', 'wp-olympiad'); ?></th>
                        <th style="width: 300px;"><?php esc_html_e('Подключение (Шорткод / Ссылка)', 'wp-olympiad'); ?></th>
                        <th style="width: 220px;"><?php esc_html_e('Действия', 'wp-olympiad'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($quizzes as $q): ?>
                        <?php
                        $questions_count = count($q->get_questions(false));
                        $time_limit = (int)$q->get_setting('time_limit', 0);
                        $attempts_count = \WPOlympiad\Attempt::count(['quiz_id' => $q->id]);
                        $direct_url = $q->get_direct_url();
                        $embed_code = $q->get_embed_code();
                        ?>
                        <tr>
                            <td><code>#<?php echo esc_html((string)$q->id); ?></code></td>
                            <td>
                                <strong>
                                    <a class="row-title" href="<?php echo esc_url(admin_url('admin.php?page=wp-olympiad-questions&quiz_id=' . $q->id)); ?>">
                                        <?php echo esc_html($q->title); ?>
                                    </a>
                                </strong>
                                <div class="row-actions">
                                    <span class="edit"><a href="<?php echo esc_url(admin_url('admin.php?page=wp-olympiad-edit&quiz_id=' . $q->id)); ?>"><?php esc_html_e('Параметры', 'wp-olympiad'); ?></a> | </span>
                                    <span class="view"><a href="<?php echo esc_url(admin_url('admin.php?page=wp-olympiad-questions&quiz_id=' . $q->id)); ?>"><?php esc_html_e('Вопросы (' . $questions_count . ')', 'wp-olympiad'); ?></a> | </span>
                                    <span class="external"><a href="<?php echo esc_url($direct_url); ?>" target="_blank">🔗 <?php esc_html_e('Прямая ссылка', 'wp-olympiad'); ?></a> | </span>
                                    <span class="delete">
                                        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=wp-olympiad&wpo_action=delete_quiz&quiz_id=' . $q->id), 'wpo_delete_quiz')); ?>" onclick="return confirm('<?php echo esc_js(__('Удалить олимпиаду и все связанные попытки?', 'wp-olympiad')); ?>');" class="wpo-text-danger">
                                            <?php esc_html_e('Удалить', 'wp-olympiad'); ?>
                                        </a>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-olympiad-questions&quiz_id=' . $q->id)); ?>" class="button button-small">
                                    📝 <?php echo sprintf(esc_html__('%d вопр.', 'wp-olympiad'), $questions_count); ?>
                                </a>
                            </td>
                            <td>
                                <?php if ($time_limit > 0): ?>
                                    <span class="dashicons dashicons-clock" style="font-size:16px;line-height:22px;"></span> <?php echo sprintf(esc_html__('%d мин', 'wp-olympiad'), $time_limit); ?>
                                <?php else: ?>
                                    <span class="wpo-badge"><?php esc_html_e('Без лимита', 'wp-olympiad'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-olympiad-attempts&quiz_id=' . $q->id)); ?>">
                                    <strong><?php echo esc_html((string)$attempts_count); ?></strong>
                                </a>
                            </td>
                            <td>
                                <div style="margin-bottom:6px;">
                                    <input type="text" class="regular-text code wpo-copyable-input" readonly value='[wp_olympiad id="<?php echo esc_attr((string)$q->id); ?>"]' onclick="this.select();" title="<?php esc_attr_e('Шорткод для вставки на страницу WordPress', 'wp-olympiad'); ?>">
                                </div>
                                <div style="display:flex; gap:6px; align-items:center;">
                                    <input type="text" class="regular-text code wpo-copyable-input" readonly value='<?php echo esc_url($direct_url); ?>' onclick="this.select();" title="<?php esc_attr_e('Прямая внешняя ссылка для участников (мессенджеры, рассылки)', 'wp-olympiad'); ?>">
                                    <a href="<?php echo esc_url($direct_url); ?>" target="_blank" class="button button-small" title="<?php esc_attr_e('Открыть внешнее тестирование', 'wp-olympiad'); ?>">↗</a>
                                </div>
                            </td>
                            <td>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-olympiad-questions&quiz_id=' . $q->id)); ?>" class="button button-secondary" title="<?php esc_attr_e('Редактор заданий', 'wp-olympiad'); ?>">
                                    ✏️ <?php esc_html_e('Вопросы', 'wp-olympiad'); ?>
                                </a>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-olympiad-attempts&quiz_id=' . $q->id)); ?>" class="button button-secondary" title="<?php esc_attr_e('Протокол сдачи', 'wp-olympiad'); ?>">
                                    📊 <?php esc_html_e('Итоги', 'wp-olympiad'); ?>
                                </a>
                                <a href="<?php echo esc_url(admin_url('admin.php?wpo_action=export_csv&quiz_id=' . $q->id)); ?>" class="button button-secondary" title="<?php esc_attr_e('Скачать CSV', 'wp-olympiad'); ?>">
                                    📥
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
