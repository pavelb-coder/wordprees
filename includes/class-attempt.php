<?php
declare(strict_types=1);

namespace WPOlympiad;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Olympiad Attempt / Session Model
 */
final class Attempt
{
    public int $id = 0;
    public int $quiz_id = 0;
    public int $user_id = 0;
    public string $token = '';
    public string $participant_name = '';
    public string $participant_email = '';
    public string $participant_org = '';
    public string $participant_phone = '';
    public string $participant_city = '';
    /** JSON: значения дополнительных полей анкеты и отметки открытия групп. */
    public string $participant_meta = '';
    public string $started_at = '';
    public ?string $submitted_at = null;
    public int $duration_seconds = 0;
    public int $tab_switches = 0;
    public int $score = 0;
    public int $max_score = 0;
    public float $percentage = 0.00;
    public string $status = 'in_progress';
    public string $ip_address = '';
    public string $user_agent = '';
    public string $created_at = '';

    public function __construct(array $data = [])
    {
        if (!empty($data)) {
            $this->id = (int)($data['id'] ?? 0);
            $this->quiz_id = (int)($data['quiz_id'] ?? 0);
            $this->user_id = (int)($data['user_id'] ?? 0);
            $this->token = (string)($data['token'] ?? '');
            $this->participant_name = (string)($data['participant_name'] ?? '');
            $this->participant_email = (string)($data['participant_email'] ?? '');
            $this->participant_org = (string)($data['participant_org'] ?? '');
            $this->participant_phone = (string)($data['participant_phone'] ?? '');
            $this->participant_city = (string)($data['participant_city'] ?? '');

            // Fallback: if phone or city are empty, extract from legacy concatenated participant_org
            if ($this->participant_phone === '' && preg_match('/(?:тел|phone):?\s*([^,;)]+)/iu', $this->participant_org, $m)) {
                $this->participant_phone = trim($m[1]);
            }
            if ($this->participant_city === '' && preg_match('/\(([^)]+)\)/u', $this->participant_org, $m)) {
                $this->participant_city = trim($m[1]);
            }

            $this->participant_meta = (string)($data['participant_meta'] ?? '');
            $this->started_at = (string)($data['started_at'] ?? '');
            $this->submitted_at = !empty($data['submitted_at']) ? (string)$data['submitted_at'] : null;
            $this->duration_seconds = (int)($data['duration_seconds'] ?? 0);
            $this->tab_switches = (int)($data['tab_switches'] ?? 0);
            $this->score = (int)round((float)($data['score'] ?? 0));
            $this->max_score = (int)round((float)($data['max_score'] ?? 0));
            $this->percentage = (float)($data['percentage'] ?? 0.00);
            $this->status = self::normalize_status((string)($data['status'] ?? 'in_progress'));
            $this->ip_address = (string)($data['ip_address'] ?? '');
            $this->user_agent = (string)($data['user_agent'] ?? '');
            $this->created_at = (string)($data['created_at'] ?? '');
        }
    }

    public static function get(int $id): ?self
    {
        global $wpdb;
        $table = DB::attempts_table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);
        return $row ? new self($row) : null;
    }

    public static function get_by_token(string $token): ?self
    {
        global $wpdb;
        $table = DB::attempts_table();
        $token = sanitize_text_field($token);
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE token = %s", $token), ARRAY_A);
        return $row ? new self($row) : null;
    }

    /**
     * Start a new attempt session
     */
    public static function start(int $quiz_id, array $participant_data): ?self
    {
        global $wpdb;
        $table = DB::attempts_table();

        $token = wp_generate_password(32, false, false);
        $user_id = get_current_user_id();

        $name = sanitize_text_field($participant_data['name'] ?? '');
        $email = sanitize_email($participant_data['email'] ?? '');
        $org = sanitize_text_field($participant_data['org'] ?? '');
        $phone = sanitize_text_field($participant_data['phone'] ?? ($participant_data['participant_phone'] ?? ''));
        $city = sanitize_text_field($participant_data['city'] ?? ($participant_data['participant_city'] ?? ''));

        // 1.0.21: значения дополнительных полей анкеты приходят уже проверенными.
        $meta = [];
        $fields = isset($participant_data['custom_fields']) && is_array($participant_data['custom_fields'])
            ? $participant_data['custom_fields']
            : [];
        if ($fields) {
            $meta['fields'] = $fields;
        }
        $meta_json = $meta ? (string)wp_json_encode($meta, JSON_UNESCAPED_UNICODE) : '';

        if ($user_id > 0 && empty($name)) {
            $user = wp_get_current_user();
            $name = $user->display_name ?: $user->user_login;
            if (empty($email)) {
                $email = $user->user_email;
            }
        }

        $now = current_time('mysql');
        $ip = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '');
        $ua = substr(sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);

        $inserted = $wpdb->insert($table, [
            'quiz_id'           => $quiz_id,
            'user_id'           => $user_id,
            'token'             => $token,
            'participant_name'  => $name,
            'participant_email' => $email,
            'participant_org'   => $org,
            'participant_phone' => $phone,
            'participant_city'  => $city,
            'participant_meta'  => $meta_json,
            'started_at'        => $now,
            'submitted_at'      => null,
            'duration_seconds'  => 0,
            'tab_switches'      => 0,
            'score'             => 0.00,
            'max_score'         => 0.00,
            'percentage'        => 0.00,
            'status'            => 'in_progress',
            'ip_address'        => $ip,
            'user_agent'        => $ua,
            'created_at'        => $now,
        ], ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%f', '%f', '%f', '%s', '%s', '%s', '%s']);

        if (!$inserted) {
            // Fallback retry without new columns if DB schema has not yet migrated
            $inserted = $wpdb->insert($table, [
                'quiz_id'           => $quiz_id,
                'user_id'           => $user_id,
                'token'             => $token,
                'participant_name'  => $name,
                'participant_email' => $email,
                'participant_org'   => $org,
                'started_at'        => $now,
                'submitted_at'      => null,
                'duration_seconds'  => 0,
                'tab_switches'      => 0,
                'score'             => 0.00,
                'max_score'         => 0.00,
                'percentage'        => 0.00,
                'status'            => 'in_progress',
                'ip_address'        => $ip,
                'user_agent'        => $ua,
                'created_at'        => $now,
            ], ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%f', '%f', '%f', '%s', '%s', '%s', '%s']);

            if (!$inserted) {
                return null;
            }
        }

        return self::get((int)$wpdb->insert_id);
    }

    /**
     * Record a tab switch (Anti-Cheat tracker)
     */
    public function record_tab_switch(): int
    {
        global $wpdb;
        $table = DB::attempts_table();

        $this->tab_switches++;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET tab_switches = tab_switches + 1 WHERE id = %d",
            $this->id
        ));

        return $this->tab_switches;
    }

    /**
     * Check if attempt time limit has expired (with grace period for latency)
     */
    public function is_expired(int $time_limit_minutes, int $grace_seconds = 30): bool
    {
        if ($time_limit_minutes <= 0) {
            return false;
        }

        $start_ts = strtotime($this->started_at);
        if ($start_ts === false) {
            return false;
        }

        $now_ts = current_time('timestamp');
        $allowed_seconds = ($time_limit_minutes * 60) + $grace_seconds;

        return ($now_ts - $start_ts) > $allowed_seconds;
    }

    /**
     * Calculate seconds remaining
     */
    public function get_remaining_seconds(int $time_limit_minutes): int
    {
        if ($time_limit_minutes <= 0) {
            return 999999;
        }
        $start_ts = strtotime($this->started_at);
        if ($start_ts === false) {
            return 0;
        }
        $now_ts = current_time('timestamp');
        $elapsed = $now_ts - $start_ts;
        $total = $time_limit_minutes * 60;
        return max(0, $total - $elapsed);
    }

    /**
     * Mark attempt as submitted / completed
     */
    public function complete(float|int $score, float|int $max_score, string|bool $status = 'completed', ?string $legacy_status = null): bool
    {
        global $wpdb;
        // A former positional boolean is accepted but never interpreted as a result.
        $status = is_bool($status) ? ($legacy_status ?? 'completed') : $status;
        $this->submitted_at = current_time('mysql');
        $this->duration_seconds = max(0, (strtotime($this->submitted_at) ?: current_time('timestamp')) - (strtotime($this->started_at) ?: current_time('timestamp')));
        $this->score = max(0, (int)round((float)$score));
        $this->max_score = max(0, (int)round((float)$max_score));
        $this->percentage = $this->max_score > 0 ? round($this->score / $this->max_score * 100, 2) : 0.0;
        $this->status = self::normalize_status($status);
        return $wpdb->update(DB::attempts_table(), [
            'submitted_at' => $this->submitted_at, 'duration_seconds' => $this->duration_seconds,
            'score' => $this->score, 'max_score' => $this->max_score,
            'percentage' => $this->percentage, 'status' => $this->status,
        ], ['id' => $this->id], ['%s', '%d', '%d', '%d', '%f', '%s'], ['%d']) !== false;
    }

    public function update_manual_review(float|int $new_score, float|int $max_score, string|bool $status = 'completed'): bool
    {
        global $wpdb;
        $this->score = max(0, (int)round((float)$new_score));
        $this->max_score = max(0, (int)round((float)$max_score));
        $this->percentage = $this->max_score > 0 ? round($this->score / $this->max_score * 100, 2) : 0.0;
        $this->status = self::normalize_status(is_bool($status) ? 'completed' : $status);
        return $wpdb->update(DB::attempts_table(), [
            'score' => $this->score, 'max_score' => $this->max_score,
            'percentage' => $this->percentage, 'status' => $this->status,
        ], ['id' => $this->id], ['%d', '%d', '%f', '%s'], ['%d']) !== false;
    }

    public static function normalize_status(string $status): string
    {
        return in_array($status, ['passed', 'failed'], true) ? 'completed' : $status;
    }

    public function get_status_label(): string
    {
        return match (self::normalize_status($this->status)) {
            'in_progress' => __('В процессе', 'wp-olympiad'),
            'needs_review' => __('На проверке', 'wp-olympiad'),
            'expired' => __('Истекло время', 'wp-olympiad'),
            'disqualified' => __('Дисквалифицирован', 'wp-olympiad'),
            default => __('Завершено', 'wp-olympiad'),
        };
    }

    /**
     * Всё, что хранится в participant_meta: поля анкеты и отметки групп.
     *
     * @return array{fields: array<string,mixed>, groups: array<string,string>}
     */
    public function meta(): array
    {
        $raw = json_decode((string)$this->participant_meta, true);
        if (!is_array($raw)) {
            $raw = [];
        }

        return [
            'fields' => isset($raw['fields']) && is_array($raw['fields']) ? $raw['fields'] : [],
            'groups' => isset($raw['groups']) && is_array($raw['groups']) ? $raw['groups'] : [],
        ];
    }

    /** @return array<string,mixed> значения дополнительных полей анкеты */
    public function custom_field_values(): array
    {
        return $this->meta()['fields'];
    }

    /** @return array<string,string> ключ группы => время открытия */
    public function groups_opened(): array
    {
        return $this->meta()['groups'];
    }

    /**
     * Запомнить, что участник открыл группу: с этого момента идёт её таймер.
     * Повторные обращения существующую отметку не двигают.
     */
    public function mark_group_opened(string $group_name): bool
    {
        if ($group_name === '') {
            return false;
        }

        $key = QuestionGroups::key($group_name);
        $opened = $this->groups_opened();
        if (isset($opened[$key])) {
            return false;
        }

        $opened[$key] = current_time('mysql');
        $this->patch_meta(['groups' => $opened]);

        return true;
    }

    /**
     * Точка времени, с которой считается лимит группы: отметка открытия,
     * а если её нет — начало попытки.
     */
    public function group_opened_at(string $group_name): int
    {
        $key = QuestionGroups::key($group_name);
        $opened = $this->groups_opened();
        if (!empty($opened[$key])) {
            $stamp = strtotime((string)$opened[$key]);
            if ($stamp !== false) {
                return $stamp;
            }
        }

        return $this->started_at !== '' ? (int)strtotime($this->started_at) : 0;
    }

    /**
     * Обновить participant_meta, не затирая уже записанное.
     *
     * @param array<string,mixed> $patch
     */
    private function patch_meta(array $patch): void
    {
        global $wpdb;
        $table = DB::attempts_table();

        $meta = $this->meta();
        foreach ($patch as $key => $value) {
            $meta[$key] = $value;
        }

        $json = (string)wp_json_encode($meta, JSON_UNESCAPED_UNICODE);
        $this->participant_meta = $json;

        $old = $wpdb->suppress_errors(true);
        $wpdb->update($table, ['participant_meta' => $json], ['id' => $this->id], ['%s'], ['%d']);
        $wpdb->suppress_errors($old);
    }

    /**
     * Get responses recorded for this attempt
     */
    public function get_responses(): array
    {
        global $wpdb;
        $table = DB::responses_table();
        return $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} WHERE attempt_id = %d ORDER BY id ASC", $this->id),
            ARRAY_A
        );
    }

    /**
     * @return self[]
     */
    public static function get_all(array $args = []): array
    {
        global $wpdb;
        $table = DB::attempts_table();

        $where = ['1=1'];
        $params = [];

        if (!empty($args['quiz_id'])) {
            $where[] = 'quiz_id = %d';
            $params[] = (int)$args['quiz_id'];
        }
        if (!empty($args['status']) && $args['status'] !== 'all') {
            $where[] = 'status = %s';
            $params[] = self::normalize_status(sanitize_key($args['status']));
        }
        if (!empty($args['user_id'])) {
            $where[] = 'user_id = %d';
            $params[] = (int)$args['user_id'];
        }

        $where_str = implode(' AND ', $where);
        $limit = isset($args['limit']) ? (int)$args['limit'] : 50;
        $offset = isset($args['offset']) ? (int)$args['offset'] : 0;
        $orderby = $args['orderby'] ?? 'id';
        $order = strtoupper($args['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

        $allowed_order = ['id', 'score', 'percentage', 'duration_seconds', 'started_at', 'submitted_at'];
        if (!in_array($orderby, $allowed_order, true)) {
            $orderby = 'id';
        }

        $params[] = $limit;
        $params[] = $offset;

        $sql = "SELECT * FROM {$table} WHERE {$where_str} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);

        $results = [];
        foreach ($rows as $row) {
            $results[] = new self($row);
        }
        return $results;
    }

    public static function count(array $args = []): int
    {
        global $wpdb;
        $table = DB::attempts_table();

        $where = ['1=1'];
        $params = [];

        if (!empty($args['quiz_id'])) {
            $where[] = 'quiz_id = %d';
            $params[] = (int)$args['quiz_id'];
        }
        if (!empty($args['status']) && $args['status'] !== 'all') {
            $where[] = 'status = %s';
            $params[] = self::normalize_status(sanitize_key($args['status']));
        }

        $where_str = implode(' AND ', $where);
        if (!empty($params)) {
            return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where_str}", ...$params));
        }
        return (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where_str}");
    }

    /**
     * Delete an attempt and all of its question responses
     */
    public static function delete(int $id): bool
    {
        global $wpdb;
        $attempt = self::get($id);
        if (!$attempt) {
            return false;
        }

        $table_attempts = DB::attempts_table();
        $table_responses = DB::responses_table();

        // 1. Delete all question responses for this attempt
        $wpdb->delete($table_responses, ['attempt_id' => $id], ['%d']);

        // 2. Delete the attempt record itself
        $deleted = $wpdb->delete($table_attempts, ['id' => $id], ['%d']);

        return $deleted !== false;
    }

    /**
     * Bulk delete multiple attempts and their responses
     */
    public static function bulk_delete(array $ids): int
    {
        $deleted_count = 0;

        foreach ($ids as $raw_id) {
            $id = (int)$raw_id;
            if ($id <= 0) {
                continue;
            }
            if (self::delete($id)) {
                $deleted_count++;
            }
        }

        return $deleted_count;
    }

    /**
     * Delete all attempts and responses for a specific quiz
     */
    public static function delete_by_quiz(int $quiz_id): int
    {
        global $wpdb;
        $table_attempts = DB::attempts_table();
        $table_responses = DB::responses_table();

        $attempt_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$table_attempts} WHERE quiz_id = %d", $quiz_id));
        if (!empty($attempt_ids)) {
            $placeholders = implode(',', array_fill(0, count($attempt_ids), '%d'));
            $wpdb->query($wpdb->prepare("DELETE FROM {$table_responses} WHERE attempt_id IN ({$placeholders})", ...$attempt_ids));
        }

        $deleted = (int)$wpdb->delete($table_attempts, ['quiz_id' => $quiz_id], ['%d']);

        return $deleted;
    }

    /**
     * Delete a single question response and recalculate attempt score
     */
    public static function delete_response(int $response_id): bool
    {
        global $wpdb;
        $table_responses = DB::responses_table();
        $table_attempts = DB::attempts_table();

        $resp = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_responses} WHERE id = %d", $response_id), ARRAY_A);
        if (!$resp) {
            return false;
        }

        $attempt_id = (int)$resp['attempt_id'];
        $deleted = $wpdb->delete($table_responses, ['id' => $response_id], ['%d']);
        if (!$deleted) {
            return false;
        }

        $attempt = self::get($attempt_id);
        if ($attempt) {
            $remaining = $wpdb->get_results(
                $wpdb->prepare("SELECT awarded_points, needs_manual_review FROM {$table_responses} WHERE attempt_id = %d", $attempt_id),
                ARRAY_A
            );

            $quiz = Quiz::get($attempt->quiz_id);
            $total_score = 0;
            $has_unreviewed = false;
            foreach ($remaining as $r) {
                $total_score += (int)round((float)$r['awarded_points']);
                if (!empty($r['needs_manual_review'])) {
                    $has_unreviewed = true;
                }
            }

            $max_score = $quiz ? (int)round($quiz->get_total_points()) : $attempt->max_score;
            $pct = ($max_score > 0) ? round(($total_score / $max_score) * 100, 2) : 0.0;
            $status = $has_unreviewed ? 'needs_review' : 'completed';

            $wpdb->update($table_attempts, [
                'score'      => $total_score,
                'max_score'  => $max_score,
                'percentage' => $pct,
                'status'     => $status,
            ], ['id' => $attempt_id], ['%d', '%d', '%f', '%s'], ['%d']);
        }

        return true;
    }
}
