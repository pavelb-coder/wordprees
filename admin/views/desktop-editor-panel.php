<?php
/** Shared editor download / deployment panel: builder, import modal, network settings. */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }
$wpo_editor_quiz_id = isset($quiz) && $quiz ? (int)$quiz->id : 0;
$wpo_editor_config = $wpo_editor_quiz_id ? \WPOlympiad\NetworkExport::get_quiz_network_config($quiz) : ['path' => \WPOlympiad\NetworkExport::get_default_network_path(), 'type' => 'local'];
$wpo_editor_state = \WPOlympiad\DesktopEditor::status($wpo_editor_config);
$wpo_editor_current = !empty($wpo_editor_state['success']) && ($wpo_editor_state['bundle'] ?? '') === \WPOlympiad\DesktopEditor::bundle_id();
?>
<section class="wpo-desktop-editor-panel <?php echo !empty($wpo_editor_compact) ? 'wpo-editor-compact' : ''; ?>" aria-label="<?php esc_attr_e('Редактор вопросов для Windows', 'wp-olympiad'); ?>">
    <div class="wpo-editor-panel-top">
        <div>
            <strong class="wpo-editor-panel-title"><span class="dashicons dashicons-desktop" aria-hidden="true"></span> <?php esc_html_e('Редактор вопросов для ПК', 'wp-olympiad'); ?></strong>
            <span class="wpo-editor-platform">Windows 10/11 x64 · <?php echo esc_html(\WPOlympiad\DesktopEditor::VERSION); ?></span>
        </div>
        <div class="wpo-editor-actions">
            <a class="button button-primary wpo-editor-download" href="<?php echo esc_url(\WPOlympiad\DesktopEditor::download_url('exe')); ?>"><?php esc_html_e('Скачать программу (.exe)', 'wp-olympiad'); ?></a>
            <a class="button wpo-editor-archive" href="<?php echo esc_url(\WPOlympiad\DesktopEditor::download_url('archive')); ?>"><?php esc_html_e('ZIP с инструкцией', 'wp-olympiad'); ?></a>
        </div>
    </div>
    <p class="wpo-editor-workflow"><?php esc_html_e('Создайте вопросы на ПК → «Экспорт → Excel / CSV / JSON» → сохраните файл в import сетевой папки или загрузите через «Импорт заданий».', 'wp-olympiad'); ?></p>
    <?php if (empty($wpo_editor_compact)): ?>
        <p class="description"><?php esc_html_e('Все 13 типов, правильные ответы, баллы, проверка и черновики .wpoq. Установка и Excel не нужны. Баллы по каждому вопросу скрыты по умолчанию. Сам .wpoq в плагин не импортируется.', 'wp-olympiad'); ?></p>
        <p class="description"><?php esc_html_e('При подключении и синхронизации плагин автоматически размещает в import/ программу, инструкцию, лицензии и исходный код. Вопросы и ваши черновики не изменяются. EXE запускается только вами на Windows-ПК, не на сервере.', 'wp-olympiad'); ?></p>
    <?php endif; ?>
    <div class="wpo-editor-panel-bottom">
        <button type="button" class="button wpo-editor-sync" data-quiz-id="<?php echo esc_attr((string)$wpo_editor_quiz_id); ?>"><?php esc_html_e('Обновить редактор в import', 'wp-olympiad'); ?></button>
        <a class="wpo-editor-source" href="<?php echo esc_url(\WPOlympiad\DesktopEditor::download_url('source')); ?>"><?php esc_html_e('Исходный код', 'wp-olympiad'); ?></a>
        <small><?php esc_html_e('Без цифровой подписи: Windows может предупредить о неизвестном издателе.', 'wp-olympiad'); ?></small>
    </div>
    <div class="wpo-desktop-editor-status <?php echo $wpo_editor_current ? 'is-success' : (!empty($wpo_editor_state) && empty($wpo_editor_state['success']) ? 'is-error' : 'is-pending'); ?>" role="status" aria-live="polite">
        <?php if ($wpo_editor_current): ?>
            <?php esc_html_e('Последнее размещение: ', 'wp-olympiad'); ?><code><?php echo esc_html(\WPOlympiad\DesktopEditor::import_prefix($wpo_editor_config) . 'QuestionStudio.exe'); ?></code>
            <?php if (!empty($wpo_editor_state['installed_at'])): ?> · <?php echo esc_html(wp_date('d.m.Y H:i', (int)$wpo_editor_state['installed_at'])); ?><?php endif; ?>
        <?php elseif (!empty($wpo_editor_state) && empty($wpo_editor_state['success'])): ?>
            <?php echo esc_html((string)($wpo_editor_state['message'] ?? '')); ?>
        <?php else: ?>
            <?php esc_html_e('Программа будет помещена в import после подключения / сохранения сетевой папки или при ближайшей синхронизации (WP-Cron).', 'wp-olympiad'); ?>
        <?php endif; ?>
    </div>
</section>
