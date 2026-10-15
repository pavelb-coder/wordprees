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
    started_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
    submitted_at datetime DEFAULT NULL,
    duration_seconds int(11) NOT NULL DEFAULT 0,
    tab_switches int(11) NOT NULL DEFAULT 0,
    score int(11) NOT NULL DEFAULT 0,
    max_score int(11) NOT NULL DEFAULT 0,
    percentage decimal(5,2) NOT NULL DEFAULT 0.00,
    passed tinyint(1) NOT NULL DEFAULT 0,
    status varchar(20) NOT NULL DEFAULT 'in_progress',
    ip_address varchar(45) NOT NULL DEFAULT '',
    submitted_ip varchar(45) NOT NULL DEFAULT '',
    user_agent varchar(255) NOT NULL DEFAULT '',
    created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
    PRIMARY KEY  (id),
    UNIQUE KEY token (token),
    KEY quiz_user (quiz_id,user_id),
    KEY quiz_status_score (quiz_id,status,score,duration_seconds),
    KEY started_at (started_at),
    KEY ip_address (ip_address)
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

        delete_option('wp_olympiad_db_version');
        delete_option('wp_olympiad_settings');
    }
}
