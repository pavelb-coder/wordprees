<?php
/** Background scheduler / locking regressions. Included by test-suite.php. */
namespace WPOlympiad\Tests;
use WPOlympiad\DB;
use WPOlympiad\Quiz;
use WPOlympiad\NetworkImport;
use WPOlympiad\NetworkSync;

if (!defined('ABSPATH') || !isset($it)) { return; }
$sync_assert = static function (bool $ok, string $message): void { if (!$ok) { throw new \RuntimeException($message); } };
$sync_csv = static fn(string $title = 'Фоновый вопрос'): string => "type;title;points;choices;correct\nsingle;{$title};2;Да|Нет;1\n";
$sync_fixture = static function (array $patch = []) use ($sync_csv): array {
    $root = sys_get_temp_dir() . '/wpo_sync_' . wp_generate_password(12, false, false);
    wp_mkdir_p($root . '/import'); file_put_contents($root . '/import/questions.csv', $sync_csv());
    $id = Quiz::create(['title' => 'Background import regression', 'settings' => array_merge([
        'network_folder_type' => 'local', 'network_folder_path' => $root, 'network_auto_import' => 1,
        'network_auto_import_interval' => 'every_minute', 'network_auto_import_file' => 'import/questions.csv',
    ], $patch)]);
    return ['id' => $id, 'root' => $root];
};
$sync_cleanup = static function (array $f): void {
    Quiz::delete($f['id']); delete_option(NetworkSync::PREFIX . 'quiz_' . $f['id']);
    if (is_dir($f['root'])) {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($f['root'], \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) { $file->isDir() && !$file->isLink() ? @rmdir($file->getPathname()) : @unlink($file->getPathname()); }
        @rmdir($f['root']);
    }
};
$sync_due = static function (int $id): void { Quiz::get($id)->update_patch(['last_network_check_time' => time() - 61, 'last_network_import_time' => time() - 61]); };

$it('Фоновый импорт: ремонт старого расписания, дубликатов и регистрация до активации', function () use ($sync_assert): bool {
    $collision = static function ($schedules) { $schedules['every_minute'] = ['interval' => 900, 'display' => 'Other plugin']; return $schedules; };
    try {
        NetworkImport::clear_cron();
        wp_schedule_event(time() + 600, 'hourly', NetworkImport::CRON_HOOK);
        wp_schedule_event(time() + 800, 'daily', NetworkImport::CRON_HOOK, ['stale']);
        add_filter('cron_schedules', $collision, 100);
        NetworkImport::schedule_cron(); $event = wp_get_scheduled_event(NetworkImport::CRON_HOOK);
        $sync_assert($event && $event->interval === 60 && $event->schedule === NetworkImport::CRON_RECURRENCE, 'Old hourly recurrence was not repaired');
        $count = 0; foreach (_get_cron_array() as $hooks) { $count += count($hooks[NetworkImport::CRON_HOOK] ?? []); }
        $sync_assert($count === 1, 'Duplicate events remain');
        $sync_assert(wp_get_schedules()['every_minute']['interval'] === 900, 'Colliding third-party recurrence was overwritten');
        wp_schedule_single_event(time() + 5, NetworkSync::CONTINUE_HOOK);
        NetworkImport::clear_cron();
        $sync_assert(!wp_next_scheduled(NetworkImport::CRON_HOOK) && !wp_next_scheduled(NetworkSync::CONTINUE_HOOK), 'Deactivation leaves cron events');
        remove_filter('cron_schedules', [NetworkImport::class, 'filter_cron_schedules']);
        NetworkImport::schedule_cron();
        $sync_assert(wp_get_scheduled_event(NetworkImport::CRON_HOOK)->interval === 60, 'Activation cannot register its own recurrence');
        return true;
    } finally { remove_filter('cron_schedules', $collision, 100); NetworkImport::schedule_cron(); }
});

$it('Фоновый импорт: атомарный TTL-lock и запрет удаления блокировки нового владельца', function () use ($sync_assert): bool {
    global $wpdb;
    $name = NetworkSync::PREFIX . 'test_' . wp_generate_password(10, false, false);
    try {
        $first = NetworkSync::acquire_lock($name, 60);
        $sync_assert(is_string($first) && NetworkSync::acquire_lock($name) === null, 'Lock is not exclusive');
        $expired = wp_json_encode(['owner' => 'expired', 'until' => time() - 1]);
        $wpdb->update($wpdb->options, ['option_value' => $expired], ['option_name' => $name]);
        $second = NetworkSync::acquire_lock($name);
        $sync_assert(is_string($second) && $second !== $first, 'Expired lock cannot be taken over');
        NetworkSync::release_lock($name, $first); NetworkSync::release_lock($name, $expired);
        $sync_assert($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name)) === $second, 'Stale owner deleted a new lock');
        NetworkSync::release_lock($name, $second);
        $sync_assert(NetworkSync::acquire_lock($name) !== null, 'Released lock remains held');
        return true;
    } finally { delete_option($name); }
});

$it('Фоновый импорт: устаревшие объекты не дублируют append, параллельный запуск ожидает', function () use ($sync_fixture, $sync_cleanup, $sync_assert): bool {
    global $wpdb; $f = $sync_fixture();
    try {
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . DB::quizzes_table() . ' WHERE id = %d', $f['id']), ARRAY_A);
        $old_a = new Quiz($row); $old_b = new Quiz($row);
        $first = NetworkImport::auto_import_for_quiz($old_a, false, [], '', '', true, false);
        $second = NetworkImport::auto_import_for_quiz($old_b, false, [], '', '', true, false);
        $sync_assert($first['imported_count'] === 1 && !empty($second['unchanged']), 'Stale hash duplicated append');
        $sync_assert(count(Quiz::get($f['id'])->get_questions(false)) === 1, 'Question count duplicated');
        $name = NetworkSync::PREFIX . 'quiz_' . $f['id']; $lock = NetworkSync::acquire_lock($name);
        try {
            $busy = NetworkImport::auto_import_for_quiz($old_b, true, [], '', '', true, false);
            $sync_assert(!empty($busy['pending']) && $busy['imported_count'] === 0, 'Concurrent forced import did not wait');
        } finally { NetworkSync::release_lock($name, $lock); }
        return true;
    } finally { $sync_cleanup($f); }
});

$it('Фоновый импорт: минуты считаются от начала проверки, не от завершения импорта', function () use ($sync_fixture, $sync_cleanup, $sync_assert): bool {
    $f = $sync_fixture();
    try {
        $started = time() - 60;
        Quiz::get($f['id'])->update_patch(['last_network_check_time' => $started, 'last_network_import_time' => time() - 58]);
        $quiz = Quiz::get($f['id']);
        $sync_assert(NetworkSync::last_check_time($quiz) === $started && NetworkSync::is_due($quiz), 'Finish timestamp skips every other minute');
        $quiz->update_patch(['last_network_check_time' => time()]);
        $sync_assert(!NetworkSync::is_due(Quiz::get($f['id'])), 'Fresh check is immediately due');
        return true;
    } finally { $sync_cleanup($f); }
});

$it('Фоновый импорт: неизменённый файл отмечает проверку, но не новый импорт', function () use ($sync_fixture, $sync_cleanup, $sync_due, $sync_assert): bool {
    $f = $sync_fixture();
    try {
        NetworkImport::auto_import_for_quiz(Quiz::get($f['id']), false, [], '', '', true, false);
        $sync_due($f['id']); $before = Quiz::get($f['id']);
        $hash = $before->get_setting('last_network_import_hash'); $audit = $before->get_setting('last_network_import'); $imported = $before->get_setting('last_network_import_time');
        $results = NetworkSync::run('cron'); $quiz = Quiz::get($f['id']); $check = $quiz->get_setting('last_network_check');
        $sync_assert(!empty($results[$f['id']]['unchanged']) && $check['status'] === 'unchanged' && $check['source'] === 'cron', 'Unchanged check is invisible');
        $sync_assert($check['started_at'] === $quiz->get_setting('last_network_check_time') && $check['finished_at'] >= $check['started_at'], 'Incorrect check timestamps');
        $sync_assert($hash === $quiz->get_setting('last_network_import_hash') && $audit === $quiz->get_setting('last_network_import') && $imported === $quiz->get_setting('last_network_import_time'), 'Unchanged file rewrote import audit');
        $sync_assert(!isset(NetworkSync::run('cron')[$f['id']]), 'Unchanged file is checked on every request');
        return true;
    } finally { $sync_cleanup($f); }
});

$it('Фоновый импорт: ошибки видны, нет повторов в ту же минуту, восстановление автоматическое', function () use ($sync_fixture, $sync_cleanup, $sync_csv, $sync_due, $sync_assert): bool {
    $f = $sync_fixture(['network_auto_import_file' => 'import/missing.csv']);
    try {
        $result = NetworkSync::run('cron'); $quiz = Quiz::get($f['id']);
        $sync_assert(empty($result[$f['id']]['success']) && $quiz->get_setting('last_network_check')['status'] === 'error', 'Read error is not recorded');
        $sync_assert($quiz->get_setting('last_network_import_hash') === '' && $quiz->get_setting('last_network_import_time') === 0, 'Error incorrectly marks file imported');
        $sync_assert(!isset(NetworkSync::run('cron')[$f['id']]), 'Failed NAS is hammered before interval');
        file_put_contents($f['root'] . '/import/missing.csv', $sync_csv('Сеть восстановлена'));
        $sync_due($f['id']); $fixed = NetworkSync::run('cron');
        $sync_assert($fixed[$f['id']]['imported_count'] === 1 && NetworkSync::status($f['id'])['state'] === 'ok', 'Failed import cannot recover');
        return true;
    } finally { $sync_cleanup($f); }
});

$it('Фоновый импорт: минутный default / aliases, явные hourly/daily сохранены, часы не блокируют навечно', function () use ($sync_fixture, $sync_cleanup, $sync_assert): bool {
    $f = $sync_fixture();
    try {
        $sync_assert(Quiz::default_settings()['network_auto_import_interval'] === 'every_minute', 'New quiz is not minute-based');
        foreach (['every_minute' => 60, 'always' => 60, 'minute' => 60, '1min' => 60, 'hourly' => 3600, 'twicedaily' => 43200, 'daily' => 86400] as $name => $seconds) {
            Quiz::get($f['id'])->update_patch(['network_auto_import_interval' => $name, 'last_network_check_time' => time() - 61]);
            $quiz = Quiz::get($f['id']);
            $sync_assert(NetworkSync::interval_seconds($quiz) === $seconds && $quiz->get_setting('network_auto_import_interval') === $name, 'Saved interval changed: ' . $name);
            $sync_assert(NetworkSync::is_due($quiz) === ($seconds === 60), 'Saved interval ignored: ' . $name);
        }
        Quiz::get($f['id'])->update_patch(['last_network_check_time' => time() + 3600]);
        $sync_assert(NetworkSync::is_due(Quiz::get($f['id'])), 'Future timestamp blocks checks indefinitely');
        Quiz::get($f['id'])->update_patch(['network_auto_import' => 0]);
        $sync_assert(!NetworkSync::is_due(Quiz::get($f['id'])), 'Disabled autoimport still runs');
        return true;
    } finally { $sync_cleanup($f); }
});

$it('Фоновый импорт: старая олимпиада за пределами последних 100 тоже импортируется', function () use ($sync_fixture, $sync_cleanup, $sync_assert): bool {
    $f = $sync_fixture(); $dummy = [];
    try {
        for ($i = 0; $i < 105; $i++) { $dummy[] = Quiz::create(['title' => 'Disabled scheduler dummy ' . $i]); }
        $sync_assert(!in_array($f['id'], array_map(static fn($q) => $q->id, Quiz::all()), true), 'Test does not exercise old 100-row cap');
        $sync_assert(in_array($f['id'], NetworkSync::quiz_ids(), true), 'Old quiz is omitted by scheduler');
        $result = NetworkSync::run('cron');
        $sync_assert(($result[$f['id']]['imported_count'] ?? 0) === 1, 'Old quiz was never processed');
        return true;
    } finally { foreach ($dummy as $id) { Quiz::delete($id); } $sync_cleanup($f); }
});

$it('Фоновый импорт: ограниченные пакеты продолжаются справедливо, без голодания старых заданий', function () use ($sync_fixture, $sync_cleanup, $sync_assert): bool {
    $fixtures = [$sync_fixture(), $sync_fixture(), $sync_fixture()];
    $limit = static fn(): int => 1; $state = get_option(NetworkSync::STATE, []);
    try {
        update_option(NetworkSync::STATE, ['cursor' => $fixtures[0]['id'] - 1], false);
        add_filter('wpo_network_sync_max_jobs', $limit);
        foreach ($fixtures as $f) {
            $result = NetworkSync::run('cron');
            $sync_assert(($result[$f['id']]['imported_count'] ?? 0) === 1, 'Cursor skipped a pending quiz');
        }
        $sync_assert((bool)wp_next_scheduled(NetworkSync::CONTINUE_HOOK), 'No continuation event for bounded batch');
        $sync_assert(count(array_filter($fixtures, static fn($f) => count(Quiz::get($f['id'])->get_questions(false)) === 1)) === 3, 'Not all batches finished');
        return true;
    } finally {
        remove_filter('wpo_network_sync_max_jobs', $limit); foreach ($fixtures as $f) { $sync_cleanup($f); }
        update_option(NetworkSync::STATE, $state, false); wp_clear_scheduled_hook(NetworkSync::CONTINUE_HOOK);
    }
});

$it('Фоновый импорт: даже недоступный редактор другого подключения запускается ПОСЛЕ вопросов', function () use ($sync_fixture, $sync_cleanup, $sync_assert): bool {
    $off = $sync_fixture(['network_auto_import' => 0, 'network_folder_type' => 'webdav', 'network_folder_path' => 'https://slow-editor-sync.invalid/share']);
    $q = $sync_fixture(); $calls = 0; $early = false; $cursor = get_option(NetworkSync::PREFIX . 'editor_cursor', 0);
    $mock = static function ($pre, $args, $url) use ($q, &$calls, &$early) {
        if (!str_contains($url, 'slow-editor-sync.invalid')) { return $pre; }
        $calls++; if (count(Quiz::get($q['id'])->get_questions(false)) !== 1) { $early = true; }
        return new \WP_Error('unavailable', 'QA unavailable NAS');
    };
    try {
        update_option(NetworkSync::PREFIX . 'editor_cursor', $off['id'] - 1, false); add_filter('pre_http_request', $mock, 10, 3);
        $result = NetworkImport::run_scheduled_auto_imports();
        $sync_assert(($result[$q['id']]['imported_count'] ?? 0) === 1 && $calls > 0 && !$early, 'Editor contacted before questions, or isolation was not exercised');
        return true;
    } finally {
        remove_filter('pre_http_request', $mock, 10); update_option(NetworkSync::PREFIX . 'editor_cursor', $cursor, false);
        $config = ['type' => 'webdav', 'path' => 'https://slow-editor-sync.invalid/share'];
        delete_option(\WPOlympiad\DesktopEditor::state_key($config));
        $sync_cleanup($off); $sync_cleanup($q);
    }
});

$it('Фоновый импорт: потерявший lease процесс не пишет вопросы и не удаляет нового владельца', function () use ($sync_fixture, $sync_cleanup, $sync_csv, $sync_assert): bool {
    global $wpdb;
    $f = $sync_fixture(['network_folder_type' => 'webdav', 'network_folder_path' => 'https://lock-fence-sync.invalid/share']);
    $name = NetworkSync::PREFIX . 'quiz_' . $f['id']; $replacement = wp_json_encode(['owner' => 'other-worker', 'until' => time() + 300]); $read = false;
    $mock = static function ($pre, $args, $url) use (&$read, $name, $replacement, $sync_csv) {
        if (!str_contains($url, 'lock-fence-sync.invalid')) { return $pre; }
        global $wpdb; $read = true;
        $wpdb->update($wpdb->options, ['option_value' => $replacement], ['option_name' => $name]);
        return ['response' => ['code' => 200, 'message' => 'OK'], 'headers' => [], 'body' => $sync_csv('Не должен записаться'), 'cookies' => []];
    };
    try {
        add_filter('pre_http_request', $mock, 10, 3);
        $result = NetworkImport::auto_import_for_quiz(Quiz::get($f['id']), false, [], '', '', true, false);
        $sync_assert($read && !empty($result['pending']) && count(Quiz::get($f['id'])->get_questions(false)) === 0, 'Superseded import committed stale questions');
        $sync_assert($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name)) === $replacement, 'Finally removed new owner');
        $sync_assert(Quiz::get($f['id'])->get_setting('last_network_check_time') === 0, 'Superseded owner overwrote audit');
        return true;
    } finally { remove_filter('pre_http_request', $mock, 10); $sync_cleanup($f); }
});

$it('Фоновый импорт: heartbeat/статус доступны только администратору, без секретов и HTML-инъекций', function () use ($sync_fixture, $sync_cleanup, $sync_assert): bool {
    $f = $sync_fixture(['network_folder_password' => 'DO-NOT-EXPOSE-SYNC-PASSWORD', 'api_key' => 'DO-NOT-EXPOSE-SYNC-API-KEY',
        'last_network_check' => ['source' => 'cron', 'status' => 'error', 'message' => '<script>alert("sync")</script>']]);
    $previous_user = get_current_user_id(); $subscriber = wp_create_user('sync_' . wp_generate_password(10, false, false), wp_generate_password(30));
    try {
        wp_set_current_user(0); $original = ['keep' => true];
        $sync_assert(NetworkSync::heartbeat($original, ['wpo_network_sync_quiz' => $f['id']]) === $original, 'Anonymous heartbeat leaks status');
        wp_set_current_user((int)$subscriber);
        $sync_assert(NetworkSync::heartbeat($original, ['wpo_network_sync_quiz' => $f['id']]) === $original, 'Subscriber heartbeat leaks status');
        $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']); wp_set_current_user((int)$admins[0]);
        $response = NetworkSync::heartbeat($original, ['wpo_network_sync_quiz' => $f['id']]);
        $sync_assert(($response['wpo_network_sync']['quiz_id'] ?? 0) === $f['id'], 'Admin heartbeat has no status');
        $json = wp_json_encode($response); $sync_assert(!str_contains($json, 'DO-NOT-EXPOSE'), 'Status contains saved credentials');
        wp_dequeue_script('heartbeat'); NetworkSync::enqueue_heartbeat();
        $sync_assert(wp_script_is('heartbeat', 'enqueued'), 'Dashboard does not load heartbeat');
        $quiz = Quiz::get($f['id']); $wpo_sync_compact = false; ob_start(); require WP_OLYMPIAD_PATH . 'admin/views/network-sync-status.php'; $html = ob_get_clean();
        $sync_assert(!str_contains($html, '<script>alert(') && str_contains($html, '&lt;script&gt;'), 'Stored status executes HTML');
        $sync_assert(has_action('wp_ajax_nopriv_wp_olympiad_network_sync_status') === false && str_contains(NetworkSync::cron_url(), '/wp-cron.php'), 'Unexpected public status/import endpoint');
        return true;
    } finally {
        wp_set_current_user($previous_user); require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user((int)$subscriber); $sync_cleanup($f);
    }
});

$it('Фоновый импорт: общий throttle между frontend/heartbeat, разрешённый следующий тик', function () use ($sync_fixture, $sync_cleanup, $sync_csv, $sync_due, $sync_assert): bool {
    $f = $sync_fixture(); $state = get_option(NetworkSync::STATE, []);
    try {
        delete_option(NetworkSync::STATE); $first = NetworkSync::run('request');
        $sync_assert(($first[$f['id']]['imported_count'] ?? 0) === 1, 'First request does not import');
        file_put_contents($f['root'] . '/import/questions.csv', $sync_csv('Следующий тик'));
        $sync_due($f['id']);
        $sync_assert(NetworkSync::run('request') === [] && NetworkSync::run('heartbeat') === [], 'Watchdog ignores global minute throttle');
        $new_state = get_option(NetworkSync::STATE); $new_state['started_at'] = time() - 61; update_option(NetworkSync::STATE, $new_state, false);
        $next = NetworkSync::run('heartbeat'); $quiz = Quiz::get($f['id']);
        $sync_assert(($next[$f['id']]['imported_count'] ?? 0) === 1 && count($quiz->get_questions(false)) === 2, 'Next heartbeat check does not run');
        $sync_assert($quiz->get_setting('last_network_check')['source'] === 'heartbeat', 'Heartbeat trigger is not recorded');
        return true;
    } finally { update_option(NetworkSync::STATE, $state, false); $sync_cleanup($f); }
});

$it('Фоновый импорт: обычный ручной повтор идемпотентен, пустой список восстанавливается, явный force сохранён', function () use ($sync_fixture, $sync_cleanup, $sync_assert): bool {
    global $wpdb; $f = $sync_fixture();
    try {
        $first = NetworkImport::import_from_network($f['id'], 'import/questions.csv');
        $second = NetworkImport::import_from_network($f['id'], 'import/questions.csv');
        $sync_assert($first['imported_count'] === 1 && !empty($second['unchanged']) && count(Quiz::get($f['id'])->get_questions(false)) === 1, 'Normal manual retry duplicates a file');
        $wpdb->delete(DB::questions_table(), ['quiz_id' => $f['id']]); Quiz::clear_cache($f['id']);
        $restore = NetworkImport::import_from_network($f['id'], 'import/questions.csv');
        $sync_assert($restore['imported_count'] === 1, 'Cleared quiz cannot restore an unchanged network file');
        $force = NetworkImport::auto_import_for_quiz(Quiz::get($f['id']), true, [], 'append', 'import/questions.csv', true, false);
        $sync_assert($force['imported_count'] === 1 && count(Quiz::get($f['id'])->get_questions(false)) === 2, 'Explicit intentional force no longer works');
        return true;
    } finally { $sync_cleanup($f); }
});

$it('Фоновый импорт: потерянный lease воркера не откатывает курсор и не снимает чужую блокировку', function () use ($sync_fixture, $sync_cleanup, $sync_assert): bool {
    global $wpdb; $f = $sync_fixture(); $old_state = get_option(NetworkSync::STATE, []);
    $name = NetworkSync::PREFIX . 'runner_lock'; $new_owner = wp_json_encode(['owner' => 'qa-new-runner', 'until' => time() + 300]);
    $marker = ['source' => 'new-runner-marker', 'cursor' => $f['id'] + 100, 'started_at' => time()];
    $replace = static function ($budget) use ($name, $new_owner, $marker) {
        global $wpdb;
        $wpdb->update($wpdb->options, ['option_value' => $new_owner], ['option_name' => $name]);
        update_option(NetworkSync::STATE, $marker, false);
        return $budget;
    };
    try {
        add_filter('wpo_network_sync_time_budget', $replace); $result = NetworkSync::run('cron');
        $sync_assert(($result[$f['id']]['imported_count'] ?? 0) === 1, 'Per-quiz work did not complete under its own valid lock');
        $sync_assert(get_option(NetworkSync::STATE) === $marker, 'Expired runner overwrote the new cursor');
        $sync_assert($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name)) === $new_owner, 'Expired runner released new owner');
        return true;
    } finally {
        remove_filter('wpo_network_sync_time_budget', $replace); delete_option($name); update_option(NetworkSync::STATE, $old_state, false); $sync_cleanup($f);
    }
});
