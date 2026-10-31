<?php
/**
 * Отметка открытия группы в прошлом (или удаление отметки) — для лимитов групп.
 * Запуск: wp eval-file .../set-group-stamp.php <token> <имя группы> <секунд|clear> --allow-root
 */

global $wpdb;

$token = trim((string)($args[0] ?? ''));
$group = trim((string)($args[1] ?? ''));
$seconds = ($args[2] ?? '0');

if ($token === '' || $group === '' || !class_exists('\\WPOlympiad\\Attempt')) {
    echo json_encode(['error' => 'usage: set-group-stamp.php <token> <group> <seconds|clear>']), "\n";
    return;
}

$attempt = \WPOlympiad\Attempt::get_by_token($token);
if (!$attempt) {
    echo json_encode(['error' => 'not_found']), "\n";
    return;
}

if (!function_exists('wpo_wall_at')) {
    /** См. tests/e2e/shift-attempt.php: время пишется в «стеновых» часах пояса сайта. */
    function wpo_wall_at($seconds_ago)
    {
        $site_now = (int) strtotime(current_time('mysql'));

        return gmdate('Y-m-d H:i:s', $site_now - (int) $seconds_ago);
    }
}

$key = \WPOlympiad\QuestionGroups::key($group);
$raw = json_decode((string)$attempt->participant_meta, true);
if (!is_array($raw)) {
    $raw = [];
}
$stamps = isset($raw['groups']) && is_array($raw['groups']) ? $raw['groups'] : [];

if ((string)$seconds === 'clear') {
    unset($stamps[$key]);
} else {
    $stamps[$key] = wpo_wall_at(max(0, (int)$seconds));
}

$raw['groups'] = $stamps;
$wpdb->update(\WPOlympiad\DB::attempts_table(), ['participant_meta' => (string)wp_json_encode($raw, JSON_UNESCAPED_UNICODE)], ['id' => (int)$attempt->id], ['%s'], ['%d']);
\WPOlympiad\Quiz::clear_cache((int)$attempt->quiz_id);

echo json_encode(['ok' => true, 'key' => $key, 'stamps' => $stamps], JSON_UNESCAPED_UNICODE), "\n";
