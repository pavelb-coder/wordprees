<?php
declare(strict_types=1);

namespace WPOlympiad;

use WP_REST_Request;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Per-quiz API key authentication for external integrations.
 *
 * Каждая олимпиада получает собственный ключ. Ключ передаётся внешней системой
 * в заголовке X-API-Key (или Authorization: Bearer <ключ>) и даёт доступ только
 * к данным этой олимпиады.
 */
final class Api
{
    /** Префикс ключа — помогает узнать ключ плагина в логах внешних систем. */
    public const KEY_PREFIX = 'wpo_';

    /** Длина случайной части ключа (после префикса). */
    public const KEY_LENGTH = 36;

    /** Время жизни отметки «последнее использование» (секунды). */
    private const LAST_USED_TTL = 2592000; // 30 дней

    /**
     * Извлечь ключ из заголовков запроса.
     */
    public static function extract_key(WP_REST_Request $request): string
    {
        $key = (string)$request->get_header('x-api-key');

        if ($key === '') {
            $auth = (string)$request->get_header('authorization');
            if ($auth !== '' && preg_match('/^Bearer\s+(.+)$/i', $auth, $matches)) {
                $key = (string)$matches[1];
            }
        }

        $key = trim($key);
        if ($key === '' || strlen($key) > 200) {
            return '';
        }

        // Ключ состоит только из букв, цифр, дефиса, подчёркивания и точки.
        if (!preg_match('/^[A-Za-z0-9_.\-]+$/', $key)) {
            return '';
        }

        return $key;
    }

    /**
     * Сгенерировать новый ключ API.
     */
    public static function generate_key(): string
    {
        return self::KEY_PREFIX . wp_generate_password(self::KEY_LENGTH, false, false);
    }

    /**
     * Хеш ключа для быстрого поиска олимпиады без перебора всех записей.
     */
    public static function hash_key(string $key): string
    {
        return hash('sha256', $key);
    }

    /**
     * Найти олимпиаду по ключу API.
     *
     * @param bool $require_enabled Если false — вернуть олимпиаду даже при выключенном API
     *                              (нужно, чтобы отличить «ключ неверный» от «API отключён»).
     */
    public static function find_quiz_by_key(string $key, bool $require_enabled = true): ?Quiz
    {
        if ($key === '' || strlen($key) < 16) {
            return null;
        }

        global $wpdb;
        $table = DB::quizzes_table();
        $hash = self::hash_key($key);

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE settings LIKE %s ORDER BY id DESC LIMIT 5",
                '%"api_key_hash":"' . $hash . '"%'
            ),
            ARRAY_A
        );

        if (!$row) {
            return null;
        }

        $quiz = new Quiz($row);

        if ($require_enabled && (int)$quiz->get_setting('api_enabled', 0) !== 1) {
            return null;
        }

        $stored = (string)$quiz->get_setting('api_key', '');
        if ($stored === '' || !hash_equals($stored, $key)) {
            return null;
        }

        return $quiz;
    }

    /**
     * Запомнить факт успешного обращения к API (для карточки интеграции).
     */
    public static function touch(Quiz $quiz): void
    {
        set_transient(
            'wpo_api_last_used_' . $quiz->id,
            current_time('mysql'),
            self::LAST_USED_TTL
        );
    }

    /**
     * Когда ключ последний раз использовался (строка MySQL или пустая строка).
     */
    public static function last_used(Quiz $quiz): string
    {
        $value = get_transient('wpo_api_last_used_' . $quiz->id);
        return is_string($value) ? $value : '';
    }

    /**
     * Маска ключа для показа в интерфейсе: wpo_ab12••••••••wxyz
     */
    public static function mask(string $key): string
    {
        if ($key === '') {
            return '';
        }
        if (strlen($key) <= 14) {
            return str_repeat('•', max(4, strlen($key) - 2)) . substr($key, -2);
        }
        return substr($key, 0, 8) . '••••••••' . substr($key, -4);
    }
}
