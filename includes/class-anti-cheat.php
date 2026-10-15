<?php
declare(strict_types=1);

namespace WPOlympiad;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Olympiad Anti-Cheat and Integrity Protection System
 */
final class AntiCheat
{
    /**
     * Check if submission is within legal time window and timer duration
     *
     * @return array{valid: bool, error: string}
     */
    public static function validate_submission_time(Attempt $attempt, Quiz $quiz): array
    {
        $time_limit = (int)$quiz->get_setting('time_limit', 0);
        $grace_seconds = 45; // 45 seconds tolerance for network latency

        if ($time_limit > 0 && $attempt->is_expired($time_limit, $grace_seconds)) {
            return [
                'valid' => false,
                'error' => __('Время на выполнение олимпиады истекло. Ответ не может быть принят.', 'wp-olympiad'),
            ];
        }

        // Check overall olympiad window
        $active_status = $quiz->is_active_now();
        if (!$active_status['active']) {
            return [
                'valid' => false,
                'error' => $active_status['reason'],
            ];
        }

        return ['valid' => true, 'error' => ''];
    }

    /**
     * Process heartbeat ping from the browser
     *
     * @return array{
     *     status: string,
     *     remaining_seconds: int,
     *     tab_switches: int,
     *     disqualified: bool,
     *     message: string
     * }
     */
    public static function process_heartbeat(Attempt $attempt, Quiz $quiz, bool $tab_switched = false): array
    {
        $time_limit = (int)$quiz->get_setting('time_limit', 0);
        $remaining = $attempt->get_remaining_seconds($time_limit);

        $switches = $attempt->tab_switches;
        if ($tab_switched) {
            $switches = $attempt->record_tab_switch();
        }

        $max_switches = (int)$quiz->get_setting('max_tab_switches', 0);
        $disqualified = false;
        $message = '';

        if ($max_switches > 0 && $switches > $max_switches) {
            $disqualified = true;
            $attempt->complete(0.0, $quiz->get_total_points(), false, 'disqualified');
            $message = sprintf(
                __('Превышено допустимое количество переключений вкладок (%d). Попытка аннулирована.', 'wp-olympiad'),
                $max_switches
            );
        }

        return [
            'status'            => 'ok',
            'remaining_seconds' => $remaining,
            'tab_switches'      => $switches,
            'disqualified'      => $disqualified,
            'message'           => $message,
        ];
    }

    /**
     * Get real client IP address considering reverse proxies and headers
     */
    public static function get_client_ip(): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_REAL_IP',        // Nginx / Ingress
            'HTTP_X_FORWARDED_FOR',  // Standard proxies / load balancers
            'REMOTE_ADDR',           // Direct connection
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $raw = (string)$_SERVER[$header];
                $parts = explode(',', $raw);
                $ip = trim($parts[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '127.0.0.1';
    }

    /**
     * Validate IP submission limit for a quiz
     *
     * @return array{allowed: bool, error: string, count: int, limit: int}
     */
    public static function can_ip_attempt(Quiz $quiz, string $ip): array
    {
        $limit = (int)$quiz->get_setting('max_attempts_per_ip', 0);
        if ($limit <= 0 || empty($ip)) {
            return ['allowed' => true, 'error' => '', 'count' => 0, 'limit' => $limit];
        }

        global $wpdb;
        $table = DB::attempts_table();
        $count = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE quiz_id = %d AND (ip_address = %s OR submitted_ip = %s) AND status != 'in_progress'",
            $quiz->id,
            $ip,
            $ip
        ));

        if ($count >= $limit) {
            return [
                'allowed' => false,
                'error'   => sprintf(
                    __('Превышен допустимый лимит попыток для вашего IP-адреса (%1$s). Разрешено сдач: %2$d.', 'wp-olympiad'),
                    $ip,
                    $limit
                ),
                'count'   => $count,
                'limit'   => $limit,
            ];
        }

        return ['allowed' => true, 'error' => '', 'count' => $count, 'limit' => $limit];
    }

    /**
     * Verify IP address consistency during the testing session
     *
     * @return array{valid: bool, error: string, changed: bool}
     */
    public static function validate_ip_consistency(Attempt $attempt, Quiz $quiz, string $current_ip): array
    {
        $disallow_change = (bool)$quiz->get_setting('disallow_ip_change', 0);
        $changed = (!empty($attempt->ip_address) && !empty($current_ip) && $attempt->ip_address !== $current_ip);

        if ($changed && $disallow_change) {
            return [
                'valid'   => false,
                'error'   => sprintf(
                    __('Обнаружена несанкционированная смена IP-адреса во время сессии (%1$s → %2$s). Попытка дисквалифицирована античит-системой.', 'wp-olympiad'),
                    $attempt->ip_address,
                    $current_ip
                ),
                'changed' => true,
            ];
        }

        return [
            'valid'   => true,
            'error'   => '',
            'changed' => $changed,
        ];
    }
}
