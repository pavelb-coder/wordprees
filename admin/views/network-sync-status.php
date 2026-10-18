<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }

$wpo_sync_id = isset($quiz) && $quiz instanceof \WPOlympiad\Quiz ? $quiz->id : 0;
$wpo_sync = \WPOlympiad\NetworkSync::status($wpo_sync_id);
$wpo_sync_compact = !empty($wpo_sync_compact);
?>
<section class="wpo-network-sync-status<?php echo $wpo_sync_compact ? ' is-compact' : ''; ?>"
         data-quiz-id="<?php echo esc_attr((string)$wpo_sync_id); ?>"
         data-revision="<?php echo esc_attr($wpo_sync['revision']); ?>"
         data-state="<?php echo esc_attr($wpo_sync['state']); ?>">
    <strong><?php esc_html_e('Фоновый импорт вопросов', 'wp-olympiad'); ?></strong>
    <p class="wpo-sync-summary" aria-live="polite"><?php echo esc_html($wpo_sync['summary']); ?></p>
    <dl class="wpo-sync-facts">
        <div><dt><?php esc_html_e('Последняя проверка:', 'wp-olympiad'); ?></dt><dd class="wpo-sync-checked"><?php echo esc_html($wpo_sync['checked_label']); ?></dd></div>
        <div><dt><?php esc_html_e('Источник запуска:', 'wp-olympiad'); ?></dt><dd class="wpo-sync-source"><?php echo esc_html($wpo_sync['source_label']); ?></dd></div>
        <div><dt><?php esc_html_e('Следующая проверка не ранее:', 'wp-olympiad'); ?></dt><dd class="wpo-sync-next"><?php echo esc_html($wpo_sync['next_label']); ?></dd></div>
        <div><dt><?php esc_html_e('Вопросов в базе:', 'wp-olympiad'); ?></dt><dd class="wpo-sync-count"><?php echo esc_html((string)$wpo_sync['question_count']); ?></dd></div>
    </dl>
    <p class="wpo-sync-updated" hidden>
        <strong><?php esc_html_e('Вопросы в базе изменились в фоне.', 'wp-olympiad'); ?></strong>
        <?php esc_html_e('Идёт редактирование или открыто окно: автоматическая перезагрузка отложена. Сохраните нужные правки, затем обновите список.', 'wp-olympiad'); ?>
        <button type="button" class="button wpo-sync-refresh-list"><?php esc_html_e('Обновить список вопросов', 'wp-olympiad'); ?></button>
    </p>
    <details class="wpo-sync-help" <?php echo !$wpo_sync_compact ? 'open' : ''; ?>>
        <summary><?php esc_html_e('Работа без посетителей / внешний планировщик', 'wp-olympiad'); ?></summary>
        <p class="description wpo-sync-clock-warning"><?php echo esc_html($wpo_sync['clock_warning']); ?></p>
        <p class="description"><?php esc_html_e('В панели хостинга или доверенном внешнем сервисе задайте HTTPS/HTTP GET раз в минуту по адресу ниже. Ссылка запускает штатные задания WordPress и не является открытым API для загрузки вопросов.', 'wp-olympiad'); ?></p>
        <div class="wpo-sync-cron-row">
            <input type="text" class="large-text code wpo-sync-cron-url" readonly aria-label="<?php esc_attr_e('URL WP-Cron для внешнего планировщика', 'wp-olympiad'); ?>" value="<?php echo esc_attr(\WPOlympiad\NetworkSync::cron_url()); ?>">
            <button type="button" class="button wpo-sync-copy-url"><?php esc_html_e('Копировать URL', 'wp-olympiad'); ?></button>
        </div>
        <p class="description"><?php esc_html_e('Не добавляйте doing_wp_cron=1. Если сайт закрыт авторизацией, защитой хостинга или кешем, разрешите планировщику обращаться к wp-cron.php. Без доступа к хостингу передайте эту ссылку администратору сайта.', 'wp-olympiad'); ?></p>
    </details>
</section>
