<?php
/**
 * Сдвиг начала попытки в прошлое (проверка личных лимитов без ожидания).
 * Запуск: wp eval-file .../shift-attempt.php <token> <секунд> [--groups] --allow-root
 */

global $wpdb;

$token = trim((string)($args[0] ?? ''));
$seconds = (int)($args[1] ?? 0);
$with_groups = in_array('--groups', (array)$args, true);

if ($token === '' || !class_exists('\\WPOlympiad\\Attempt')) {
    echo json_encode(['error' => 'usage: shift-attempt.php <token> <seconds> [--groups]']), "\n";
    return;
}

$attempt = \WPOlympiad\Attempt::get_by_token($token);
if (!$attempt) {
    echo json_encode(['error' => 'not_found']), "\n";
    return;
}

$table = \WPOlympiad\DB::attempts_table();
if (!function_exists('wpo_wall_at')) {
    /**
     * Wall-clock пояса сайта N секунд назад — в тех же единицах, что и плагин
     * (`current_time('mysql')` / `strtotime()` + `current_time('timestamp')`), чтобы
     * сдвиг не зависел от часового пояса PHP и MySQL на стенде.
     */
    function wpo_wall_at($seconds_ago)
    {
        $site_now = (int) strtotime(current_time('mysql'));

        return gmdate('Y-m-d H:i:s', $site_now - (int) $seconds_ago);
    }
}

$started_at = wpo_wall_at($seconds);
$wpdb->update($table, ['started_at' => $started_at], ['id' => (int)$attempt->id], ['%s'], ['%d']);

$moved = [];
if ($with_groups) {
    $attempt = \WPOlympiad\Attempt::get((int)$attempt->id);
    $stamps = $attempt->groups_opened();
    foreach ($stamps as $key => $stamp) {
        $stamps[$key] = wpo_wall_at($seconds);
        $moved[] = $key;
    }
    $raw = json_decode((string)$attempt->participant_meta, true);
    if (!is_array($raw)) {
        $raw = [];
    }
    $raw['groups'] = $stamps;
    $wpdb->update($table, ['participant_meta' => (string)wp_json_encode($raw, JSON_UNESCAPED_UNICODE)], ['id' => (int)$attempt->id], ['%s'], ['%d']);
}

echo json_encode([
    'ok' => true,
    'started_at' => $started_at,
    'groups_shifted' => $moved,
]), "\n";
