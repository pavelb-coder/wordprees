<?php
declare(strict_types=1);
namespace WPOlympiad;

if (!defined('ABSPATH')) { exit; }

/** Scheduler, request watchdog and diagnostics; no unauthenticated import API. */
final class NetworkSync
{
    public const PREFIX = 'wpo_network_sync_';
    public const STATE = self::PREFIX . 'state';
    public const CONTINUE_HOOK = 'wp_olympiad_network_import_continue';
    private static bool $armed = false;
    private static bool $heartbeat_seen = false;
    private static string $source = 'manual';
    /** @var array<int,string> */
    private static array $quiz_locks = [];

    public static function init(): void
    {
        add_action('wp_loaded', [self::class, 'arm_request_check'], 30);
        add_action('shutdown', [self::class, 'finish_request_check'], PHP_INT_MAX);
        add_action(self::CONTINUE_HOOK, [NetworkImport::class, 'run_scheduled_auto_imports']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_heartbeat']);
        add_filter('heartbeat_received', [self::class, 'heartbeat'], 10, 2);
        add_action('heartbeat_tick', [self::class, 'heartbeat_tick'], 10, 0);
        add_action('wp_ajax_wp_olympiad_network_sync_status', [self::class, 'ajax_status']);
    }

    public static function enqueue_heartbeat(): void
    {
        // Any admin screen can wake the watchdog, not only the questions builder.
        if (current_user_can('manage_options')) { wp_enqueue_script('heartbeat'); }
    }

    public static function interval_seconds(Quiz $quiz): int
    {
        switch ((string)$quiz->get_setting('network_auto_import_interval', 'every_minute')) {
            case 'daily': return 86400;
            case 'twicedaily': return 43200;
            case 'hourly': return 3600;
            default: return 60; // every_minute, minute, 1min, legacy always.
        }
    }

    public static function last_check_time(Quiz $quiz): int
    {
        $time = (int)$quiz->get_setting('last_network_check_time', 0);
        if ($time <= 0) { $time = (int)$quiz->get_setting('last_network_import_time', 0); }
        return $time > time() + 300 ? 0 : $time;
    }

    public static function is_due(Quiz $quiz): bool
    {
        return (bool)$quiz->get_setting('network_auto_import', 0)
            && (self::last_check_time($quiz) === 0 || time() - self::last_check_time($quiz) >= self::interval_seconds($quiz));
    }

    /** Atomic locks use compare-and-swap; an expired owner cannot release a new lock. */
    public static function acquire_lock(string $name, int $ttl = 180): ?string
    {
        global $wpdb;
        $value = (string)wp_json_encode(['owner' => wp_generate_uuid4(), 'until' => time() + $ttl]);
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $value
        ));
        if ($inserted !== 1) {
            $old = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
            $decoded = json_decode((string)$old, true);
            if ($old === null || (int)($decoded['until'] ?? 0) >= time()) { return null; }
            $changed = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, $name, $old
            ));
            if ($changed !== 1) { return null; }
        }
        wp_cache_delete($name, 'options'); wp_cache_delete('notoptions', 'options');
        return $value;
    }

    public static function release_lock(string $name, string $value): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $value));
        wp_cache_delete($name, 'options');
    }

    private static function renew_lock(string $name, string $old, int $ttl): ?string
    {
        global $wpdb;
        $new = (string)wp_json_encode(['owner' => (string)(json_decode($old, true)['owner'] ?? ''), 'until' => time() + $ttl]);
        // An identical same-second lease may affect zero rows: verify its value.
        $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $new, $name, $old));
        $actual = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
        if ($actual !== $new) { return null; }
        wp_cache_delete($name, 'options');
        return $new;
    }

    /** Fence writes after slow network I/O; a replaced/expired owner cannot commit. */
    public static function assert_quiz_lock(int $id): void
    {
        $old = self::$quiz_locks[$id] ?? null;
        if ($old === null) { throw new \RuntimeException('Question import requires its quiz lock.'); }
        $new = self::renew_lock(self::PREFIX . 'quiz_' . $id, $old, 300);
        if ($new === null) { throw new \RuntimeException(__('Проверка передана другому запуску; устаревший процесс не изменяет вопросы.', 'wp-olympiad')); }
        self::$quiz_locks[$id] = $new;
    }

    public static function with_quiz_lock(int $id, callable $callback): array
    {
        $name = self::PREFIX . 'quiz_' . $id;
        $token = self::acquire_lock($name, 300);
        if ($token === null) {
            return ['success' => true, 'pending' => true, 'imported_count' => 0,
                'message' => __('Импорт этой олимпиады уже выполняется. Повторный запуск не требуется.', 'wp-olympiad')];
        }
        $started = time(); $quiz = null; self::$quiz_locks[$id] = $token;
        try {
            // The caller may hold a pre-import object. Always re-read its hash under the lock.
            Quiz::clear_cache($id); $quiz = Quiz::get($id);
            if (!$quiz) { return ['success' => false, 'imported_count' => 0, 'message' => __('Олимпиада не найдена.', 'wp-olympiad')]; }
            if (self::$source !== 'manual' && !self::is_due($quiz)) {
                return ['success' => true, 'skipped' => true, 'imported_count' => 0, 'message' => __('Следующая проверка ещё не наступила.', 'wp-olympiad')];
            }
            try { $result = $callback($quiz); }
            catch (\Throwable $e) {
                $result = ['success' => false, 'imported_count' => 0,
                    'message' => __('Ошибка фонового импорта: ', 'wp-olympiad') . $e->getMessage()];
            }
            try { self::assert_quiz_lock($id); }
            catch (\Throwable $e) { return ['success' => true, 'pending' => true, 'imported_count' => 0, 'message' => $e->getMessage()]; }
            // A check is not an import. Record unchanged/error checks too, without
            // advancing the imported content hash or erasing its successful audit.
            $quiz->update_patch([
                'last_network_check_time' => $started,
                'last_network_check' => [
                    'started_at' => $started, 'finished_at' => time(), 'source' => self::$source,
                    'status' => empty($result['success']) ? 'error' : (!empty($result['unchanged']) ? 'unchanged' : 'success'),
                    'message' => (string)($result['message'] ?? ''),
                    'filename' => (string)($result['filename'] ?? ''),
                    'imported_count' => (int)($result['imported_count'] ?? 0),
                ],
            ]);
            return $result;
        } finally {
            self::release_lock($name, self::$quiz_locks[$id] ?? $token);
            unset(self::$quiz_locks[$id]);
        }
    }

    /** Read the tiny watchdog state fresh while holding its lock (persistent caches may lag). */
    private static function fresh_state(): array
    {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::STATE));
        $state = $raw === null ? [] : maybe_unserialize($raw);
        return is_array($state) ? $state : [];
    }

    public static function quiz_ids(): array
    {
        global $wpdb;
        // Quiz::all defaults to 100; per_page was not a supported argument there.
        return array_map('intval', $wpdb->get_col('SELECT id FROM ' . DB::quizzes_table() . ' ORDER BY id ASC'));
    }

    private static function rotate(array $ids, int $cursor): array
    {
        return array_merge(array_values(array_filter($ids, static fn(int $id): bool => $id > $cursor)),
            array_values(array_filter($ids, static fn(int $id): bool => $id <= $cursor)));
    }

    /** The import-only worker. Editor delivery cannot block the next import check. */
    public static function run(string $source = 'cron'): array
    {
        $source = in_array($source, ['request', 'heartbeat', 'cron'], true) ? $source : 'cron';
        $name = self::PREFIX . 'runner_lock'; $token = self::acquire_lock($name);
        if ($token === null) { return []; }
        $previous_source = self::$source;
        try {
            $old = self::fresh_state(); $now = time();
            if ($source !== 'cron' && (int)($old['started_at'] ?? 0) <= $now
                && $now - (int)($old['started_at'] ?? 0) < (!empty($old['has_more']) ? 5 : 60)) { return []; }
            self::$source = $source;
            $state = ['started_at' => $now, 'finished_at' => 0, 'source' => $source,
                'cursor' => (int)($old['cursor'] ?? 0), 'checked' => 0, 'changed' => 0, 'errors' => 0, 'has_more' => false];
            update_option(self::STATE, $state, false);
            $ids = self::rotate(self::quiz_ids(), $state['cursor']); $results = [];
            $started = microtime(true);
            $budget = max(1.0, (float)apply_filters('wpo_network_sync_time_budget', 20.0));
            $max_jobs = max(1, (int)apply_filters('wpo_network_sync_max_jobs', 20));
            foreach ($ids as $index => $id) {
                Quiz::clear_cache($id); $quiz = Quiz::get($id); $state['cursor'] = $id;
                if ($quiz && self::is_due($quiz)) {
                    $result = NetworkImport::auto_import_for_quiz($quiz, false, [], '', '', true, false);
                    $results[$id] = $result; $state['checked']++;
                    if (empty($result['success'])) { $state['errors']++; }
                    if (!empty($result['imported_count'])) { $state['changed']++; }
                }
                if ($index + 1 < count($ids) && ($state['checked'] >= $max_jobs || microtime(true) - $started >= $budget)) {
                    $state['has_more'] = true; break;
                }
            }
            // A network call can outlive the runner lease; never overwrite a new runner's cursor.
            $renewed = self::renew_lock($name, $token, 180);
            if ($renewed === null) { return $results; }
            $token = $renewed;
            $state['finished_at'] = time(); update_option(self::STATE, $state, false);
            if ($state['has_more'] && !wp_next_scheduled(self::CONTINUE_HOOK)) {
                wp_schedule_single_event(time() + 5, self::CONTINUE_HOOK);
            }
            return $results;
        } finally { self::$source = $previous_source; self::release_lock($name, $token); }
    }

    /** Separate, best-effort stage after the import lock has already been released. */
    public static function deploy_editors(): void
    {
        $name = self::PREFIX . 'editor_lock'; $token = self::acquire_lock($name);
        if ($token === null) { return; }
        try {
            $cursor = (int)get_option(self::PREFIX . 'editor_cursor', 0); $start = microtime(true);
            foreach (self::rotate(self::quiz_ids(), $cursor) as $id) {
                $quiz = Quiz::get($id);
                if ($quiz) { DesktopEditor::ensure_for_quiz($quiz); }
                $cursor = $id;
                if (microtime(true) - $start >= 10) { break; }
            }
            $renewed = self::renew_lock($name, $token, 180);
            if ($renewed !== null) { $token = $renewed; update_option(self::PREFIX . 'editor_cursor', $cursor, false); }
        } finally { self::release_lock($name, $token); }
    }

    public static function arm_request_check(): void
    {
        if (wp_doing_cron() || (defined('WP_CLI') && WP_CLI) || wp_installing()
            || !(bool)apply_filters('wpo_network_sync_request_fallback', true)) { return; }
        $state = get_option(self::STATE, []); $state = is_array($state) ? $state : [];
        if (time() - (int)($state['started_at'] ?? 0) >= (!empty($state['has_more']) ? 5 : 60)
            || (int)($state['started_at'] ?? 0) > time()) { self::$armed = true; }
    }

    public static function finish_request_check(): void
    {
        if (!self::$armed) { return; }
        self::$armed = false;
        $previous_abort = ignore_user_abort(true);
        try {
            // When supported, send the completed page/AJAX response before slow network I/O.
            if (function_exists('fastcgi_finish_request') && (bool)apply_filters('wpo_network_sync_finish_response', true)) { @fastcgi_finish_request(); }
            self::run(self::$heartbeat_seen ? 'heartbeat' : 'request');
        } catch (\Throwable $e) {
            // Never append an exception/HTML error page to an already completed response.
            error_log('WP Olympiad: request watchdog failed: ' . $e->getMessage());
        } finally { ignore_user_abort((bool)$previous_abort); }
    }

    public static function cancel_request_check(): void { self::$armed = false; }

    public static function heartbeat_tick(): void
    {
        if (current_user_can('manage_options')) { self::$heartbeat_seen = true; self::arm_request_check(); }
    }

    public static function heartbeat(array $response, array $data): array
    {
        if (!current_user_can('manage_options')) { return $response; }
        self::heartbeat_tick();
        if (isset($data['wpo_network_sync_quiz'])) {
            $response['wpo_network_sync'] = self::status((int)$data['wpo_network_sync_quiz']);
        }
        return $response;
    }

    public static function question_count(int $id): int
    {
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . DB::questions_table() . ' WHERE quiz_id = %d', $id));
    }

    public static function status(int $id = 0): array
    {
        $quiz = $id > 0 ? Quiz::get($id) : null;
        $enabled = $quiz && (bool)$quiz->get_setting('network_auto_import', 0);
        $check = $quiz ? $quiz->get_setting('last_network_check', []) : [];
        $check = is_array($check) ? $check : [];
        $checked = $quiz ? self::last_check_time($quiz) : 0;
        $seconds = $quiz ? self::interval_seconds($quiz) : 60;
        $interval = [60 => __('каждую минуту', 'wp-olympiad'), 3600 => __('каждый час', 'wp-olympiad'), 43200 => __('дважды в день', 'wp-olympiad'), 86400 => __('раз в день', 'wp-olympiad')][$seconds];
        $state = !$enabled ? 'off' : (empty($check) ? 'waiting' : (($check['status'] ?? '') === 'error' ? 'error' : 'ok'));
        $labels = ['manual' => __('ручной запуск / сохранение', 'wp-olympiad'), 'cron' => __('планировщик WP-Cron', 'wp-olympiad'),
            'request' => __('обращение к сайту', 'wp-olympiad'), 'heartbeat' => __('heartbeat админки', 'wp-olympiad')];
        $summary = !$quiz ? __('Сначала сохраните олимпиаду и её сетевое подключение.', 'wp-olympiad')
            : (!$enabled ? __('Автоимпорт вопросов выключен. Включите его выше, если нужен фоновый обмен.', 'wp-olympiad')
            : sprintf(__('Автоимпорт: %s. Конструктор открывать и обновлять не нужно.', 'wp-olympiad'), $interval));
        if ($enabled && !empty($check['message'])) { $summary .= ' ' . (string)$check['message']; }
        $count = $quiz ? self::question_count($id) : 0;
        $revision = $quiz ? hash('sha256', (string)$quiz->get_setting('last_network_import_hash', '') . '|' . (string)$quiz->get_setting('last_network_import_time', 0) . '|' . $count) : '';
        $cron_off = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        return [
            'quiz_id' => $id, 'enabled' => (bool)$enabled, 'state' => $state, 'summary' => $summary,
            'checked_label' => $checked > 0 ? wp_date('d.m.Y H:i:s', $checked) : __('ещё не выполнялась', 'wp-olympiad'),
            'source_label' => $labels[(string)($check['source'] ?? '')] ?? '—',
            'next_label' => !$enabled ? __('не запланирована (автоимпорт выключен)', 'wp-olympiad') : ($checked + $seconds > time() ? wp_date('d.m.Y H:i:s', $checked + $seconds) : __('при ближайшем запуске планировщика / обращении к сайту', 'wp-olympiad')),
            'interval' => $seconds, 'question_count' => $count, 'revision' => $revision,
            'clock_warning' => $cron_off
                ? __('Встроенный WP-Cron отключён. Резервная проверка работает при обращениях к сайту; для работы без обращений нужен внешний планировщик.', 'wp-olympiad')
                : __('WP-Cron и резервная проверка требуют обращений к сайту. Для работы без посетителей настройте внешний планировщик по ссылке ниже.', 'wp-olympiad'),
        ];
    }

    public static function cron_url(): string { return site_url('/wp-cron.php'); }

    public static function ajax_status(): void
    {
        if (!current_user_can('manage_options')) { wp_send_json_error(['message' => __('Доступ запрещён.', 'wp-olympiad')], 403); }
        check_ajax_referer('wp_olympiad_admin_nonce', 'nonce');
        wp_send_json_success(self::status((int)($_POST['quiz_id'] ?? 0)));
    }
}
