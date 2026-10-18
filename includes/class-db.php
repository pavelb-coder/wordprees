<?php
declare(strict_types=1);

namespace WPOlympiad;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Database schema management and queries
 */
final class DB
{
    public static function quizzes_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'olympiad_quizzes';
    }

    public static function questions_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'olympiad_questions';
    }

    public static function attempts_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'olympiad_attempts';
    }

    public static function responses_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'olympiad_responses';
    }

    /**
     * Install / migrate tables with dbDelta
     */
    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        $quizzes_table = self::quizzes_table();
        $questions_table = self::questions_table();
        $attempts_table = self::attempts_table();
        $responses_table = self::responses_table();

        $sql = "
CREATE TABLE {$quizzes_table} (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    title varchar(255) NOT NULL,
    slug varchar(200) NOT NULL,
    description text NOT NULL,
    settings longtext NOT NULL,
    status varchar(20) NOT NULL DEFAULT 'publish',
    created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
    updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
    PRIMARY KEY  (id),
    KEY status (status),
    KEY slug (slug)
) {$charset_collate};

CREATE TABLE {$questions_table} (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    quiz_id bigint(20) unsigned NOT NULL,
    question_type varchar(50) NOT NULL DEFAULT 'single',
    title text NOT NULL,
    content longtext NOT NULL,
    points int(11) NOT NULL DEFAULT 1,
    penalty int(11) NOT NULL DEFAULT 0,
    options longtext NOT NULL,
    sort_order int(11) NOT NULL DEFAULT 0,
    PRIMARY KEY  (id),
    KEY quiz_sort (quiz_id,sort_order)
) {$charset_collate};

CREATE TABLE {$attempts_table} (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    quiz_id bigint(20) unsigned NOT NULL,
    user_id bigint(20) unsigned NOT NULL DEFAULT 0,
    token varchar(64) NOT NULL,
    participant_name varchar(255) NOT NULL DEFAULT '',
    participant_email varchar(255) NOT NULL DEFAULT '',
    participant_org varchar(255) NOT NULL DEFAULT '',
    participant_phone varchar(100) NOT NULL DEFAULT '',
    participant_city varchar(150) NOT NULL DEFAULT '',
    started_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
    submitted_at datetime DEFAULT NULL,
    duration_seconds int(11) NOT NULL DEFAULT 0,
    tab_switches int(11) NOT NULL DEFAULT 0,
    score int(11) NOT NULL DEFAULT 0,
    max_score int(11) NOT NULL DEFAULT 0,
    percentage decimal(5,2) NOT NULL DEFAULT 0.00,
    status varchar(20) NOT NULL DEFAULT 'in_progress',
    ip_address varchar(45) NOT NULL DEFAULT '',
    user_agent varchar(255) NOT NULL DEFAULT '',
    created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
    PRIMARY KEY  (id),
    UNIQUE KEY token (token),
    KEY quiz_user (quiz_id,user_id),
    KEY quiz_status_score (quiz_id,status,score,duration_seconds),
    KEY started_at (started_at)
) {$charset_collate};

CREATE TABLE {$responses_table} (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    attempt_id bigint(20) unsigned NOT NULL,
    question_id bigint(20) unsigned NOT NULL,
    user_answer longtext NOT NULL,
    is_correct tinyint(1) DEFAULT NULL,
    awarded_points int(11) NOT NULL DEFAULT 0,
    needs_manual_review tinyint(1) NOT NULL DEFAULT 0,
    review_comment text NOT NULL,
    PRIMARY KEY  (id),
    KEY attempt_question (attempt_id,question_id),
    KEY needs_review (needs_manual_review)
) {$charset_collate};
";

        dbDelta($sql);
        self::retire_threshold_data();
    }

    public const THRESHOLD_CLEANUP_HOOK = 'wp_olympiad_retire_threshold_data';
    private const THRESHOLD_CURSOR = 'wpo_threshold_cleanup_cursor';
    private const THRESHOLD_DDL_PENDING = 'wpo_threshold_ddl_pending';

    public static function init(): void
    {
        add_action(self::THRESHOLD_CLEANUP_HOOK, [self::class, 'resume_threshold_cleanup']);
        add_action('admin_init', static function (): void {
            if (current_user_can('manage_options')) self::resume_threshold_cleanup();
        });
    }

    /** Retire only obsolete classification data, never answers or numeric scores. */
    public static function retire_threshold_data(): void
    {
        global $wpdb;
        $table = self::attempts_table(); $old = $wpdb->suppress_errors(true);
        $column = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'passed'");
        if ($column !== null && $wpdb->query("ALTER TABLE {$table} DROP COLUMN passed") === false) {
            update_option(self::THRESHOLD_DDL_PENDING, 1, false);
            error_log('WP Olympiad: legacy result column could not be removed; it is not used by the application.');
        } else { delete_option(self::THRESHOLD_DDL_PENDING); }
        $wpdb->query("UPDATE {$table} SET status = 'completed' WHERE status IN ('passed', 'failed')");
        $wpdb->suppress_errors($old);
        if (get_option(self::THRESHOLD_CURSOR, false) === false) update_option(self::THRESHOLD_CURSOR, 0, false);
        self::cleanup_threshold_settings();
    }

    public static function resume_threshold_cleanup(): void
    {
        if (get_option(self::THRESHOLD_DDL_PENDING, false)) { self::retire_threshold_data(); return; }
        if (get_option(self::THRESHOLD_CURSOR, false) !== false) self::cleanup_threshold_settings();
    }

    /** Bounded, compare-and-swap cleanup also works on MySQL without JSON functions. */
    public static function cleanup_threshold_settings(): void
    {
        global $wpdb;
        $cursor = (int)get_option(self::THRESHOLD_CURSOR, 0); $start = microtime(true);
        $budget = max(0.05, min(5.0, (float)apply_filters('wpo_threshold_cleanup_budget', 1.0)));
        $table = self::quizzes_table();
        $patterns = array_map(static fn($key) => '%' . $wpdb->esc_like('"' . $key . '"') . '%', ['pass_percentage', 'show_pass_pct', 'grading_scales']);
        do {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT id, settings FROM {$table} WHERE id > %d AND (settings LIKE %s OR settings LIKE %s OR settings LIKE %s) ORDER BY id LIMIT 100", $cursor, ...$patterns), ARRAY_A);
            if (!$rows) {
                delete_option(self::THRESHOLD_CURSOR); wp_unschedule_hook(self::THRESHOLD_CLEANUP_HOOK); return;
            }
            foreach ($rows as $row) {
                $settings = json_decode((string)$row['settings'], true);
                if (is_array($settings)) {
                    $clean = Quiz::without_threshold_settings($settings);
                    if ($clean !== $settings) {
                        $json = wp_json_encode($clean, JSON_UNESCAPED_UNICODE);
                        $changed = $wpdb->query($wpdb->prepare("UPDATE {$table} SET settings = %s WHERE id = %d AND BINARY settings = BINARY %s", $json, $row['id'], $row['settings']));
                        if ($changed === false) break 2;
                        if ($changed === 0) {
                            // A concurrent writer won the row. Retry it only if it
                            // still contains retired data; never replace newer fields.
                            $fresh = json_decode((string)$wpdb->get_var($wpdb->prepare("SELECT settings FROM {$table} WHERE id = %d", $row['id'])), true);
                            if (is_array($fresh) && Quiz::without_threshold_settings($fresh) !== $fresh) break 2;
                        }
                        Quiz::clear_cache((int)$row['id']);
                    }
                }
                $cursor = (int)$row['id'];
                if (microtime(true) - $start >= $budget) break 2;
            }
        } while (count($rows) === 100);
        if (count($rows) < 100 && $cursor === (int)end($rows)['id']) {
            delete_option(self::THRESHOLD_CURSOR); wp_unschedule_hook(self::THRESHOLD_CLEANUP_HOOK); return;
        }
        update_option(self::THRESHOLD_CURSOR, $cursor, false);
        if (!wp_next_scheduled(self::THRESHOLD_CLEANUP_HOOK)) wp_schedule_single_event(time() + 30, self::THRESHOLD_CLEANUP_HOOK);
    }

    /**
     * Drop all tables (used in uninstall.php)
     */
    public static function drop_tables(): void
    {
        global $wpdb;
        $tables = [
            self::responses_table(),
            self::attempts_table(),
            self::questions_table(),
            self::quizzes_table(),
        ];

        foreach ($tables as $table) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
        }

        delete_option(self::THRESHOLD_CURSOR);
        delete_option(self::THRESHOLD_DDL_PENDING);
        wp_unschedule_hook(self::THRESHOLD_CLEANUP_HOOK);
        delete_option('wp_olympiad_db_version');
        delete_option('wp_olympiad_settings');
    }
}
