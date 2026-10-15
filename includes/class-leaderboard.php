<?php
declare(strict_types=1);

namespace WPOlympiad;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Olympiad Leaderboard, Ranking, and Statistics
 */
final class Leaderboard
{
    private const CACHE_PREFIX = 'wp_olympiad_lb_';
    private const CACHE_EXPIRATION = 300; // 5 minutes

    /**
     * Invalidate leaderboard cache for a quiz
     */
    public static function invalidate(int $quiz_id): void
    {
        delete_transient(self::CACHE_PREFIX . $quiz_id);
    }

    /**
     * Get ranked leaderboard
     *
     * @return array<int, array{
     *     rank: int,
     *     attempt_id: int,
     *     name: string,
     *     org: string,
     *     score: float,
     *     max_score: float,
     *     percentage: float,
     *     duration_seconds: int,
     *     duration_formatted: string,
     *     tab_switches: int,
     *     date: string
     * }>
     */
    public static function get_leaderboard(int $quiz_id, int $limit = 50, bool $force = false): array
    {
        $cache_key = self::CACHE_PREFIX . $quiz_id . '_' . $limit;
        if (!$force) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        global $wpdb;
        $table = DB::attempts_table();

        // Optimized query: fetches only completed attempts, ordering by olympiad rules
        $sql = "
            SELECT id, participant_name, participant_org, score, max_score, percentage,
                   duration_seconds, tab_switches, submitted_at
            FROM {$table}
            WHERE quiz_id = %d AND status IN ('completed', 'graded')
            ORDER BY score DESC, duration_seconds ASC, tab_switches ASC, id ASC
            LIMIT %d
        ";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $quiz_id, $limit), ARRAY_A);
        $leaderboard = [];
        $rank = 1;

        foreach ($rows as $row) {
            $duration = (int)$row['duration_seconds'];
            $minutes = floor($duration / 60);
            $seconds = $duration % 60;
            $duration_formatted = sprintf('%02d:%02d', $minutes, $seconds);

            $leaderboard[] = [
                'rank'               => $rank++,
                'attempt_id'         => (int)$row['id'],
                'name'               => esc_html($row['participant_name']),
                'org'                => esc_html($row['participant_org']),
                'score'              => (float)$row['score'],
                'max_score'          => (float)$row['max_score'],
                'percentage'         => (float)$row['percentage'],
                'duration_seconds'   => $duration,
                'duration_formatted' => $duration_formatted,
                'tab_switches'       => (int)$row['tab_switches'],
                'date'               => !empty($row['submitted_at']) ? date_i18n('d.m.Y H:i', strtotime($row['submitted_at'])) : '',
            ];
        }

        set_transient($cache_key, $leaderboard, self::CACHE_EXPIRATION);
        return $leaderboard;
    }

    /**
     * Render HTML table for public or admin leaderboard
     */
    public static function render_html(int $quiz_id, int $limit = 50): string
    {
        $quiz = Quiz::get($quiz_id);
        if (!$quiz) {
            return '<div class="wpo-alert wpo-alert-error">' . esc_html__('Олимпиада не найдена.', 'wp-olympiad') . '</div>';
        }

        $items = self::get_leaderboard($quiz_id, $limit);

        ob_start();
        ?>
        <div class="wpo-leaderboard-container">
            <div class="wpo-leaderboard-header">
                <h3 class="wpo-leaderboard-title">
                    <span class="wpo-icon-trophy">🏆</span>
                    <?php echo sprintf(esc_html__('Рейтинговая таблица: %s', 'wp-olympiad'), esc_html($quiz->title)); ?>
                </h3>
                <span class="wpo-leaderboard-count">
                    <?php echo sprintf(esc_html__('Участников в рейтинге: %d', 'wp-olympiad'), count($items)); ?>
                </span>
            </div>

            <?php if (empty($items)): ?>
                <div class="wpo-empty-notice">
                    <?php esc_html_e('Пока нет завершённых попыток. Станьте первым участником!', 'wp-olympiad'); ?>
                </div>
            <?php else: ?>
                <div class="wpo-table-responsive">
                    <table class="wpo-table wpo-leaderboard-table">
                        <thead>
                            <tr>
                                <th class="wpo-col-rank"><?php esc_html_e('Место', 'wp-olympiad'); ?></th>
                                <th class="wpo-col-name"><?php esc_html_e('Участник', 'wp-olympiad'); ?></th>
                                <th class="wpo-col-org"><?php esc_html_e('Организация / Класс', 'wp-olympiad'); ?></th>
                                <th class="wpo-col-score"><?php esc_html_e('Баллы', 'wp-olympiad'); ?></th>
                                <th class="wpo-col-time"><?php esc_html_e('Время', 'wp-olympiad'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                                <?php
                                $badge = '';
                                $row_class = '';
                                if ($item['rank'] === 1) {
                                    $badge = '🥇';
                                    $row_class = 'wpo-rank-first';
                                } elseif ($item['rank'] === 2) {
                                    $badge = '🥈';
                                    $row_class = 'wpo-rank-second';
                                } elseif ($item['rank'] === 3) {
                                    $badge = '🥉';
                                    $row_class = 'wpo-rank-third';
                                }
                                ?>
                                <tr class="<?php echo esc_attr($row_class); ?>">
                                    <td class="wpo-cell-rank">
                                        <span class="wpo-rank-badge"><?php echo $badge ? $badge . ' ' : ''; ?><?php echo esc_html((string)$item['rank']); ?></span>
                                    </td>
                                    <td class="wpo-cell-name">
                                        <strong><?php echo esc_html($item['name']); ?></strong>
                                    </td>
                                    <td class="wpo-cell-org">
                                        <?php echo esc_html($item['org'] ?: '—'); ?>
                                    </td>
                                    <td class="wpo-cell-score">
                                        <span class="wpo-score-pill">
                                            <?php echo esc_html(sprintf('%d / %d (%d%%)', round($item['score']), round($item['max_score']), round($item['percentage']))); ?>
                                        </span>
                                    </td>
                                    <td class="wpo-cell-time">
                                        <code><?php echo esc_html($item['duration_formatted']); ?></code>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return (string)ob_get_clean();
    }
}
