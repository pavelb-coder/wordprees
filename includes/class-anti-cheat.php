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
    public static function validate_submission_time(Attempt $attempt, Quiz $quiz, bool $strict = false): array
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
        $active_status = $quiz->is_active_now($strict);
        if (!$active_status['active']) {
            // Расписание закрывает *старт* попытки. Если участник начал писать, пока
            // приём был открыт, окончание времени олимпиады не аннулирует его ответ —
            // иначе сдача в последнюю минуту обнуляла бы реально написанную работу.
            if (($active_status['code'] ?? '') === 'after_end' && self::started_within_window($attempt, $quiz)) {
                return ['valid' => true, 'error' => ''];
            }

            return [
                'valid' => false,
                'error' => $active_status['reason'],
            ];
        }

        return ['valid' => true, 'error' => ''];
    }

    /**
     * Whether the attempt was started before the acceptance deadline of the olympiad.
     */
    private static function started_within_window(Attempt $attempt, Quiz $quiz): bool
    {
        $bounds = $quiz->schedule_boundaries();
        if (!($bounds['end'] instanceof \DateTimeImmutable)) {
            return true;
        }

        $started = trim((string)$attempt->started_at);
        if ($started === '') {
            return false;
        }

        try {
            $started_at = new \DateTimeImmutable($started, $bounds['tz']);
        } catch (\Throwable $e) {
            return false;
        }

        return $started_at <= $bounds['end'];
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
            $attempt->complete(0.0, $quiz->get_total_points(), 'disqualified');
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
}
