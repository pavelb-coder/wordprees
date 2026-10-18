<?php
declare(strict_types=1);

namespace WPOlympiad;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Исходящие webhook-уведомления о завершённых попытках.
 *
 * Возможности:
 *  - HMAC-SHA256 подпись тела запроса (X-WPO-Signature) + метка времени;
 *  - асинхронная отправка через WP-Cron (не задерживает участника);
 *  - до 3 попыток доставки с нарастающей паузой;
 *  - журнал последних доставок в настройках олимпиады;
 *  - ручная тестовая отправка из карточки «Внешнее подключение».
 */
final class Webhook
{
    public const CRON_HOOK = 'wpo_webhook_deliver';

    /** Всего попыток доставки (первая + 2 повтора). */
    public const MAX_ATTEMPTS = 3;

    /** Паузы перед повторами в секундах (для 2-й и 3-й попытки). */
    public const RETRY_DELAYS = [60, 300];

    /** Сколько последних доставок хранить в журнале. */
    public const LOG_LIMIT = 12;

    /** Префикс секрета подписи. */
    public const SECRET_PREFIX = 'whsec_';

    public static function init(): void
    {
        add_action(self::CRON_HOOK, [self::class, 'cron_deliver'], 10, 5);
    }

    /**
     * Сгенерировать секрет подписи.
     */
    public static function generate_secret(): string
    {
        return self::SECRET_PREFIX . wp_generate_password(40, false, false);
    }

    /**
     * Секрет подписи олимпиады (создаётся при первом обращении, если отсутствует).
     */
    public static function ensure_secret(Quiz $quiz): string
    {
        $secret = (string)$quiz->get_setting('webhook_secret', '');
        if ($secret === '') {
            $secret = self::generate_secret();
            $quiz->update_setting('webhook_secret', $secret);
        }
        return $secret;
    }

    /**
     * HMAC-SHA256 подпись: sha256=<hmac(timestamp . "." . raw_body, secret)>
     */
    public static function sign(string $secret, int $timestamp, string $body): string
    {
        return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    /**
     * Уникальный идентификатор доставки.
     */
    public static function new_delivery_id(): string
    {
        return 'whd_' . strtolower(wp_generate_password(16, false, false));
    }

    /**
     * Payload события attempt_completed (структура совместима с прежней версией плагина).
     */
    public static function build_payload(Quiz $quiz, Attempt $attempt): array
    {
        return [
            'event'     => 'attempt_completed',
            'quiz'      => [
                'id'           => $quiz->id,
                'title'        => $quiz->title,
                'slug'         => $quiz->slug,
                'total_points' => (int)round($quiz->get_total_points()),
            ],
            'attempt'   => [
                'id'                => $attempt->id,
                'token'             => $attempt->token,
                'participant_name'  => $attempt->participant_name,
                'participant_email' => $attempt->participant_email,
                'participant_org'   => $attempt->participant_org,
                'participant_phone' => $attempt->participant_phone,
                'participant_city'  => $attempt->participant_city,
                'score'             => (int)round($attempt->score),
                'max_score'         => (int)round($attempt->max_score),
                'percentage'        => $attempt->percentage,
                'passed'            => (bool)$attempt->passed,
                'status'            => $attempt->status,
                'duration_seconds'  => $attempt->duration_seconds,
                'tab_switches'      => $attempt->tab_switches,
                'started_at'        => $attempt->started_at,
                'submitted_at'      => $attempt->submitted_at,
            ],
            'timestamp' => time(),
        ];
    }

    /**
     * Вызывается при завершении попытки. Планирует асинхронную доставку.
     *
     * @return array{queued: bool, delivery_id: string}
     */
    public static function on_attempt_completed(Quiz $quiz, Attempt $attempt): array
    {
        $url = trim((string)$quiz->get_setting('webhook_url', ''));
        // Школьные LMS и CRM часто живут в локальной сети — не ограничиваем приватные адреса.
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return ['queued' => false, 'delivery_id' => ''];
        }

        $payload = self::build_payload($quiz, $attempt);
        $delivery_id = self::new_delivery_id();
        $payload['delivery_id'] = $delivery_id;

        self::log($quiz, [
            'id'          => $delivery_id,
            'time'        => current_time('mysql'),
            'event'       => (string)$payload['event'],
            'url'         => $url,
            'attempt_id'  => $attempt->id,
            'try'         => 1,
            'code'        => 0,
            'ok'          => null,
            'message'     => __('В очереди на отправку', 'wp-olympiad'),
            'duration_ms' => 0,
            'test'        => false,
        ]);

        $scheduled = wp_schedule_single_event(
            time(),
            self::CRON_HOOK,
            [$quiz->id, $payload, $attempt->id, $delivery_id, 1]
        );

        if (is_wp_error($scheduled) || $scheduled === false) {
            // Планировщик недоступен — отправляем сразу, чтобы событие не потерялось.
            self::deliver_event($quiz->id, $payload, $attempt->id, $delivery_id, 1);
            return ['queued' => false, 'delivery_id' => $delivery_id];
        }

        // Разбудить WP-Cron сразу, не дожидаясь следующего визита на сайт.
        // Фильтр позволяет отключить фоновый запуск (например, на серверах с внешним планировщиком).
        $spawn = (bool)apply_filters('wpo_webhook_spawn_cron', true, $quiz);
        if ($spawn && !wp_doing_cron() && !(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) && function_exists('spawn_cron')) {
            spawn_cron();
        }

        return ['queued' => true, 'delivery_id' => $delivery_id];
    }

    /**
     * Обработчик WP-Cron: доставка и повторные попытки.
     */
    public static function cron_deliver(int $quiz_id, array $payload = [], int $attempt_id = 0, string $delivery_id = '', int $try = 1): void
    {
        self::deliver_event($quiz_id, $payload, $attempt_id, $delivery_id, $try);
    }

    /**
     * Выполнить одну попытку доставки (блокирующий HTTP-запрос).
     *
     * @return array{ok: bool, code: int, message: string, duration_ms: int, signature: string, timestamp: int, try: int}
     */
    public static function deliver_event(
        int $quiz_id,
        array $payload,
        int $attempt_id = 0,
        string $delivery_id = '',
        int $try = 1,
        bool $is_test = false
    ): array {
        $quiz = Quiz::get($quiz_id);
        if (!$quiz) {
            return ['ok' => false, 'code' => 0, 'message' => __('Олимпиада не найдена', 'wp-olympiad'), 'duration_ms' => 0, 'signature' => '', 'timestamp' => 0, 'try' => $try];
        }

        $url = trim((string)$quiz->get_setting('webhook_url', ''));
        if ($url === '') {
            return ['ok' => false, 'code' => 0, 'message' => __('URL webhook не задан', 'wp-olympiad'), 'duration_ms' => 0, 'signature' => '', 'timestamp' => 0, 'try' => $try];
        }

        if ($delivery_id === '') {
            $delivery_id = self::new_delivery_id();
        }
        if (!isset($payload['delivery_id'])) {
            $payload['delivery_id'] = $delivery_id;
        }

        $secret = self::ensure_secret($quiz);
        $body = wp_json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (!is_string($body)) {
            $body = '{}';
        }

        $timestamp = time();
        $signature = self::sign($secret, $timestamp, $body);
        $event = sanitize_key((string)($payload['event'] ?? 'attempt_completed'));

        $started = microtime(true);
        $response = wp_remote_post($url, [
            'timeout'     => 10,
            'blocking'    => true,
            'redirection' => 2,
            'headers'     => [
                'Content-Type'      => 'application/json; charset=utf-8',
                'User-Agent'        => 'WP-Olympiad-Webhook/1.1',
                'X-WPO-Event'       => $event,
                'X-WPO-Delivery'    => $delivery_id,
                'X-WPO-Timestamp'   => (string)$timestamp,
                'X-WPO-Signature'   => $signature,
                'X-WPO-Attempt'     => (string)$attempt_id,
                'X-WPO-Test'        => $is_test ? '1' : '0',
            ],
            'body'        => $body,
        ]);
        $duration_ms = (int)round((microtime(true) - $started) * 1000);

        if (is_wp_error($response)) {
            $code = 0;
            $ok = false;
            $message = $response->get_error_message();
        } else {
            $code = (int)wp_remote_retrieve_response_code($response);
            $ok = $code >= 200 && $code < 300;
            if ($ok) {
                $message = __('Доставлено', 'wp-olympiad');
            } else {
                $snippet = trim(wp_strip_all_tags((string)wp_remote_retrieve_body($response)));
                if (mb_strlen($snippet) > 180) {
                    $snippet = mb_substr($snippet, 0, 180) . '…';
                }
                $message = sprintf(__('HTTP %1$d: %2$s', 'wp-olympiad'), $code, $snippet !== '' ? $snippet : __('пустой ответ', 'wp-olympiad'));
            }
        }

        $retryable = !$ok && ($code === 0 || $code === 429 || $code >= 500);
        $will_retry = $retryable && $try < self::MAX_ATTEMPTS;

        if ($will_retry) {
            $message .= ' ' . sprintf(
                __('Повторная попытка №%d запланирована.', 'wp-olympiad'),
                $try + 1
            );
        } elseif (!$ok && $retryable) {
            $message .= ' ' . __('Повторы исчерпаны.', 'wp-olympiad');
        }

        self::log($quiz, [
            'id'          => $delivery_id,
            'time'        => current_time('mysql'),
            'event'       => $event,
            'url'         => $url,
            'attempt_id'  => $attempt_id,
            'try'         => $try,
            'code'        => $code,
            'ok'          => $ok,
            'message'     => $message,
            'duration_ms' => $duration_ms,
            'test'        => $is_test,
        ]);

        if ($will_retry) {
            $delay = self::RETRY_DELAYS[$try - 1] ?? 300;
            wp_schedule_single_event(
                time() + $delay,
                self::CRON_HOOK,
                [$quiz_id, $payload, $attempt_id, $delivery_id, $try + 1]
            );
        }

        return [
            'ok'          => $ok,
            'code'        => $code,
            'message'     => $message,
            'duration_ms' => $duration_ms,
            'signature'   => $signature,
            'timestamp'   => $timestamp,
            'try'         => $try,
            'delivery_id' => $delivery_id,
        ];
    }

    /**
     * Отправить тестовый webhook синхронно (кнопка в админке).
     */
    public static function send_test(Quiz $quiz): array
    {
        $url = trim((string)$quiz->get_setting('webhook_url', ''));
        if ($url === '') {
            return ['ok' => false, 'code' => 0, 'message' => __('Сначала укажите URL webhook и сохраните настройки.', 'wp-olympiad'), 'duration_ms' => 0, 'signature' => '', 'timestamp' => 0, 'try' => 1];
        }

        $delivery_id = self::new_delivery_id();
        $payload = [
            'event'       => 'webhook_test',
            'test'        => true,
            'delivery_id' => $delivery_id,
            'quiz'        => [
                'id'           => $quiz->id,
                'title'        => $quiz->title,
                'slug'         => $quiz->slug,
                'total_points' => (int)round($quiz->get_total_points()),
            ],
            'attempt'     => [
                'id'                => 0,
                'token'             => 'test_' . strtolower(wp_generate_password(10, false, false)),
                'participant_name'  => __('Тестовый участник', 'wp-olympiad'),
                'participant_email' => 'test@example.com',
                'participant_org'   => __('Тестовая организация', 'wp-olympiad'),
                'participant_phone' => '',
                'participant_city'  => '',
                'score'             => 7,
                'max_score'         => 10,
                'percentage'        => 70.0,
                'passed'            => true,
                'status'            => 'completed',
                'duration_seconds'  => 754,
                'tab_switches'      => 0,
                'started_at'        => current_time('mysql'),
                'submitted_at'      => current_time('mysql'),
            ],
            'timestamp'   => time(),
        ];

        $result = self::deliver_event($quiz->id, $payload, 0, $delivery_id, 1, true);
        $result['entry'] = [
            'id'          => $delivery_id,
            'time'        => current_time('mysql'),
            'event'       => 'webhook_test',
            'url'         => $url,
            'attempt_id'  => 0,
            'try'         => 1,
            'code'        => (int)$result['code'],
            'ok'          => (bool)$result['ok'],
            'message'     => (string)$result['message'],
            'duration_ms' => (int)$result['duration_ms'],
            'test'        => true,
        ];
        return $result;
    }

    /**
     * Добавить/обновить запись журнала доставок.
     */
    public static function log(Quiz $quiz, array $entry): void
    {
        $fresh = Quiz::get($quiz->id);
        if (!$fresh) {
            return;
        }

        $log = $fresh->get_setting('webhook_log', []);
        if (!is_array($log)) {
            $log = [];
        }

        $entry_id = (string)($entry['id'] ?? '');
        $updated = false;

        foreach ($log as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            if ($entry_id !== '' && (string)($row['id'] ?? '') === $entry_id) {
                $log[$index] = array_merge($row, $entry);
                $updated = true;
                break;
            }
        }

        if (!$updated) {
            $log[] = $entry;
        }

        if (count($log) > self::LOG_LIMIT) {
            $log = array_slice($log, -self::LOG_LIMIT);
        }

        $fresh->update_setting('webhook_log', array_values($log));
    }

    /**
     * Последние доставки (сначала новые).
     */
    public static function deliveries(Quiz $quiz, int $limit = 8): array
    {
        $log = $quiz->get_setting('webhook_log', []);
        if (!is_array($log) || empty($log)) {
            return [];
        }

        $log = array_values(array_filter($log, 'is_array'));
        $log = array_reverse($log);

        return array_slice($log, 0, max(1, $limit));
    }

    /**
     * Очистить журнал доставок.
     */
    public static function clear_log(Quiz $quiz): void
    {
        $quiz->update_setting('webhook_log', []);
    }
}
