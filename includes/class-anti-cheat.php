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

        // Регламент закрывает *старт* попытки, а не сдачу уже начатой работы:
        // иначе снятая с публикации олимпиада или передвинутая дата окончания
        // обнуляли бы реально написанный ответ. Личный лимит времени при этом
        // проверяется выше и остаётся единственным основанием для «time_expired».
        $active_status = $quiz->is_active_now($strict);
        if (!$active_status['active'] && !self::attempt_started_allowed($attempt, $quiz, (string)($active_status['code'] ?? ''))) {
            return [
                'valid' => false,
                'error' => $active_status['reason'],
            ];
        }

        return ['valid' => true, 'error' => ''];
    }

    /**
     * Was this attempt started while the rules allowed it? If yes, the current
     * state of the schedule must not invalidate the submission.
     *
     * Only an attempt begun after the acceptance deadline (for instance created
     * by an organizer who bypasses the rules) is refused — that is not a participant
     * losing work, it is a late start.
     */
    private static function attempt_started_allowed(Attempt $attempt, Quiz $quiz, string $code): bool
    {
        if ($attempt->status !== 'in_progress') {
            return false;
        }

        if ($code !== 'after_end') {
            // «ещё не опубликована» и «ещё не началась» не могут быть виной участника:
            // попытка уже идёт, значит организатор разрешил её начать.
            return true;
        }

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
