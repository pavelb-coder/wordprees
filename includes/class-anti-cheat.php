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
    /**
     * 1.0.29: «браузер закрыт» = разрыв между сигналами страницы длиннее порога.
     *
     * Порог настраивается в олимпиаде, и он заведомо больше и F5 (разрыв в секунды),
     * и фоновой вкладки (браузер прореживает таймеры примерно до раза в минуту, но
     * heartbeat продолжает приходить). Отсчёт — только от серверной отметки
     * last_seen_at, часам клиента мы не доверяем.
     *
     * @return array{enabled:bool,threshold:int,gap:int,reset:bool}
     */
    public static function browser_close_gap(Attempt $attempt, Quiz $quiz): array
    {
        $enabled = (int)$quiz->get_setting('reset_on_browser_close', 0) === 1;
        $threshold = (int)$quiz->get_setting('browser_close_timeout', 120);
        if ($threshold <= 0) {
            // Настройка пуста или обнулена — работаем порогом по умолчанию,
            // а не превращаем сброс в «пауза на 30 секунд».
            $threshold = 120;
        }
        $threshold = max(30, min(6 * HOUR_IN_SECONDS, $threshold));
        if (!$enabled || $attempt->last_seen_at === '' || $attempt->status !== 'in_progress') {
            return ['enabled' => $enabled, 'threshold' => $threshold, 'gap' => 0, 'reset' => false];
        }
        $ref = strtotime($attempt->last_seen_at);
        if ($ref === false || $ref <= 0) {
            return ['enabled' => true, 'threshold' => $threshold, 'gap' => 0, 'reset' => false];
        }
        $gap = max(0, current_time('timestamp') - $ref);
        return [
            'enabled'   => true,
            'threshold' => $threshold,
            'gap'       => $gap,
            'reset'     => $gap >= $threshold,
        ];
    }

    public static function process_heartbeat(Attempt $attempt, Quiz $quiz, bool $tab_switched = false): array
    {
        // 1.0.29: сначала проверяем паузу — если страница молчала дольше порога,
        // попытка аннулируется и отвечать остатком времени уже нечего.
        $pause = self::browser_close_gap($attempt, $quiz);
        if ($pause['reset'] && $attempt->close_as_abandoned((int)$pause['gap'])) {
            return [
                'status'            => 'browser_closed',
                'remaining_seconds' => 0,
                'tab_switches'      => (int)$attempt->tab_switches,
                'disqualified'      => false,
                'closed_after'      => (int)$pause['gap'],
                'message'           => sprintf(
                    __('Страница была закрыта %d мин, попытка сброшена.', 'wp-olympiad'),
                    max(1, (int)round($pause['gap'] / 60))
                ),
            ];
        }
        $attempt->touch_last_seen();
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
