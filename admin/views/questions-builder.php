<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$types = \WPOlympiad\Question::supported_types();
?>
<div class="wrap wpo-admin-wrap">
    <div class="wpo-header-bar">
        <div>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wp-olympiad')); ?>" class="button button-secondary">
                ← <?php esc_html_e('Ко всем олимпиадам', 'wp-olympiad'); ?>
            </a>
            <h1 style="display:inline-block; margin-left:10px;">
                <?php echo sprintf(esc_html__('Конструктор заданий: %s', 'wp-olympiad'), esc_html($quiz->title)); ?>
            </h1>
        </div>
        <div>
            <button type="button" class="button button-primary button-large" id="wpo-add-new-q-btn">
                + <?php esc_html_e('Добавить задание', 'wp-olympiad'); ?>
            </button>
        </div>
    </div>

    <?php if (isset($_GET['created'])): ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Олимпиада создана! Теперь добавьте задания.', 'wp-olympiad'); ?></p></div>
    <?php endif; ?>

    <div id="wpo-questions-list" data-quiz-id="<?php echo esc_attr((string)$quiz->id); ?>">
        <?php if (empty($questions)): ?>
            <div class="wpo-admin-card wpo-empty-questions-notice">
                <span class="dashicons dashicons-edit-large" style="font-size:40px;height:40px;color:#94a3b8;"></span>
                <h3><?php esc_html_e('В этой олимпиаде пока нет заданий', 'wp-olympiad'); ?></h3>
                <p><?php esc_html_e('Нажмите кнопку «Добавить задание», чтобы создать первый вопрос.', 'wp-olympiad'); ?></p>
            </div>
        <?php else: ?>
            <?php foreach ($questions as $index => $q): ?>
                <?php include WP_OLYMPIAD_PATH . 'admin/views/question-item-card.php'; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Hidden Template for New Question -->
    <template id="wpo-new-question-template">
        <?php
        $q = new \WPOlympiad\Question([
            'id'            => 0,
            'quiz_id'       => $quiz->id,
            'question_type' => 'single',
            'title'         => '',
            'content'       => '',
            'points'        => 1,
            'penalty'       => 0,
            'sort_order'    => 999,
            'options'       => [
                'choices' => [
                    ['id' => 'c1', 'text' => '', 'is_correct' => 1],
                    ['id' => 'c2', 'text' => '', 'is_correct' => 0],
                ],
                'explanation' => '',
            ],
        ]);
        $index = 999;
        include WP_OLYMPIAD_PATH . 'admin/views/question-item-card.php';
        ?>
    </template>
</div>
