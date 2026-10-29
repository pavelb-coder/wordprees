<?php
/**
 * Группы (разделы) вопросов и вывод их на страницу (1.0.21).
 *
 * Группа — это значение `options['group_name']` у вопроса; оно уже заполняется
 * в карточке задания и приходит из импорта. Здесь хранятся настройки самих
 * групп: как подписать баннер, что показать под ним, сколько минут отводится
 * и в каком порядке группы выводятся участнику.
 */

namespace WPOlympiad;

if (!defined('ABSPATH')) {
    exit;
}

final class QuestionGroups
{
    public const MAX_GROUPS = 20;
    public const MAX_NAME_LENGTH = 120;
    public const MAX_INSTRUCTION_LENGTH = 1000;

    /** Секунды, в которые укладывается задержка сети и автосдача по таймеру. */
    public const GRACE_SECONDS = 60;

    public static function normalize_name(string $raw): string
    {
        $name = preg_replace('/\s+/u', ' ', trim($raw)) ?? '';

        return mb_substr($name, 0, self::MAX_NAME_LENGTH, 'UTF-8');
    }

    /**
     * Ключ сопоставления: регистр и пунктуация в названии группы роли не играют.
     */
    public static function key(string $raw): string
    {
        return mb_strtolower(self::normalize_name($raw), 'UTF-8');
    }

    /**
     * @param array<int,mixed> $raw
     * @return array<int,array<string,mixed>>
     */
    public static function sanitize_defs(array $raw): array
    {
        $clean = [];
        $seen = [];

        foreach (array_values($raw) as $idx => $item) {
            if (!is_array($item) || count($clean) >= self::MAX_GROUPS) {
                continue;
            }

            $name = self::normalize_name((string)($item['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $key = self::key($name);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $title = mb_substr(sanitize_text_field((string)($item['title'] ?? '')), 0, self::MAX_NAME_LENGTH, 'UTF-8');
            $instruction = mb_substr(sanitize_textarea_field((string)($item['instruction'] ?? '')), 0, self::MAX_INSTRUCTION_LENGTH, 'UTF-8');
            $time_limit = isset($item['time_limit']) ? max(0, min(600, (int)$item['time_limit'])) : 0;
            $position = isset($item['position']) ? max(0, (int)$item['position']) : 0;

            $clean[] = [
                'name'        => $name,
                'title'       => $title !== '' ? $title : $name,
                'instruction' => $instruction,
                'time_limit'  => $time_limit,
                'position'    => $position,
            ];
        }

        return $clean;
    }

    /**
     * @param array<int,array<string,mixed>> $defs
     * @return array<string,array<string,mixed>> keyed by group key
     */
    public static function index(array $defs): array
    {
        $index = [];
        foreach ($defs as $def) {
            $index[self::key((string)$def['name'])] = $def;
        }

        return $index;
    }

    /**
     * Названия групп в порядке их появления в списке заданий.
     *
     * @param array<int,object> $questions
     * @return string[]
     */
    public static function names_of(array $questions): array
    {
        $names = [];
        foreach ($questions as $question) {
            $name = self::normalize_name((string)($question->options['group_name'] ?? ''));
            if ($name !== '' && !in_array(self::key($name), array_map([self::class, 'key'], $names), true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Участник идёт по группам: задания одной группы стоят рядом, порядок
     * групп — из настроек (position), иначе по первому появлению. Задания
     * без группы идут в начало и сохраняют относительный порядок.
     *
     * @param array<int,object> $questions
     * @return array<int,object>
     */
    public static function order(array $questions, array $defs): array
    {
        if (!$defs) {
            return $questions;
        }

        $rank = [];
        foreach ($defs as $def) {
            $position = (int)($def['position'] ?? 0);
            if ($position > 0) {
                $rank[self::key((string)$def['name'])] = $position;
            }
        }

        $ungrouped = [];
        $buckets = [];
        $order = [];
        $appearance = 0;

        foreach ($questions as $question) {
            $name = self::normalize_name((string)($question->options['group_name'] ?? ''));
            if ($name === '') {
                $ungrouped[] = $question;
                continue;
            }

            $key = self::key($name);
            if (!isset($buckets[$key])) {
                $buckets[$key] = ['items' => [], 'seen' => $appearance++];
                $order[$key] = isset($rank[$key]) ? $rank[$key] : 1000 + $buckets[$key]['seen'];
            }
            $buckets[$key]['items'][] = $question;
        }

        if (!$buckets) {
            return $questions;
        }

        $keys = array_keys($buckets);
        usort($keys, static function (string $a, string $b) use ($order): int {
            $cmp = $order[$a] <=> $order[$b];

            return $cmp !== 0 ? $cmp : strcmp($a, $b);
        });

        $ordered = $ungrouped;
        foreach ($keys as $key) {
            foreach ($buckets[$key]['items'] as $question) {
                $ordered[] = $question;
            }
        }

        return $ordered;
    }

    /**
     * Сколько минут отводится на группу.
     */
    public static function time_limit_for(array $defs, string $name): int
    {
        if ($name === '') {
            return 0;
        }

        $index = self::index($defs);
        $def = $index[self::key($name)] ?? null;

        return $def ? (int)$def['time_limit'] : 0;
    }

    /**
     * Группы, чьё время вышло: [название => причина].
     *
     * @param array<string,string> $opened group key => «Y-m-d H:i:s» момента открытия
     */
    public static function overdue_groups(array $defs, array $opened, int $now_ts): array
    {
        $overdue = [];
        foreach ($defs as $def) {
            $limit = (int)($def['time_limit'] ?? 0);
            if ($limit <= 0) {
                continue;
            }

            $key = self::key((string)$def['name']);
            if (empty($opened[$key])) {
                // Группу не открывали (или клиент не успел сообщить) — не наказываем.
                continue;
            }

            $opened_ts = strtotime((string)$opened[$key]);
            if ($opened_ts === false) {
                continue;
            }

            if ($now_ts > $opened_ts + $limit * 60 + self::GRACE_SECONDS) {
                $overdue[] = (string)$def['name'];
            }
        }

        return $overdue;
    }

    /**
     * Данные о группах для страницы олимпиады.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function client_payload(array $defs): array
    {
        $payload = [];
        foreach ($defs as $def) {
            $payload[] = [
                'key'         => self::key((string)$def['name']),
                'name'        => (string)$def['name'],
                'title'       => (string)$def['title'],
                'instruction' => (string)$def['instruction'],
                'time_limit'  => (int)$def['time_limit'],
            ];
        }

        return $payload;
    }
}
