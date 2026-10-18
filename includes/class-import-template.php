<?php
declare(strict_types=1);

namespace WPOlympiad;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Question import template: canonical schema, aliases and generators.
 *
 * Single source of truth for:
 *  - the CSV / JSON / Excel / TXT templates bundled with the plugin;
 *  - header normalization for uploaded files (Russian and English aliases);
 *  - question-type aliases ("один выбор" → "single");
 *  - the "extra" column with type-specific parameters (min/max/step, audio_url, ...).
 */
final class ImportTemplate
{
    /** Template format version (bumped on incompatible column changes). */
    public const VERSION = '2.0';

    /** Maximum number of questions accepted from a single file. */
    public const MAX_QUESTIONS = 2000;

    /**
     * Canonical column schema (order matters — it defines the CSV layout).
     *
     * @return array<string, array{label: string, hint: string, example: string, width: int, required: bool}>
     */
    public static function columns(): array
    {
        return [
            'type' => [
                'label'    => 'Тип задания',
                'hint'     => 'Код типа: single, multi, dropdown, boolean, number, text, matching, ordering, fill_blank, slider, word_scramble, dictation, essay. Можно писать по-русски: «один выбор», «тест», «соответствие»…',
                'example'  => 'single',
                'width'    => 17,
                'required' => true,
            ],
            'title' => [
                'label'    => 'Текст вопроса',
                'hint'     => 'Формулировка задания. Обязательное поле. Для fill_blank и dictation допускаются пропуски вида {ответ|синоним}.',
                'example'  => 'Какая планета является четвёртой по удалённости от Солнца?',
                'width'    => 42,
                'required' => true,
            ],
            'points' => [
                'label'    => 'Баллы',
                'hint'     => 'Целое число баллов за полностью верный ответ. По умолчанию 1.',
                'example'  => '3',
                'width'    => 9,
                'required' => false,
            ],
            'penalty' => [
                'label'    => 'Штраф',
                'hint'     => 'Сколько баллов снимать за неверный ответ. По умолчанию 0.',
                'example'  => '0',
                'width'    => 9,
                'required' => false,
            ],
            'group_name' => [
                'label'    => 'Раздел',
                'hint'     => 'Раздел / часть олимпиады для группировки заданий и навигатора. Необязательно.',
                'example'  => 'Часть 1: Астрономия',
                'width'    => 24,
                'required' => false,
            ],
            'choices' => [
                'label'    => 'Варианты / пары / элементы',
                'hint'     => 'Варианты через «|». Звёздочка * перед вариантом = правильный. Балл за конкретный пункт: «*Марс^2». Пары: «Франция => Париж». Последовательность: элементы по порядку.',
                'example'  => 'Венера | Земля | *Марс | Юпитер',
                'width'    => 40,
                'required' => false,
            ],
            'correct' => [
                'label'    => 'Ключ ответа',
                'hint'     => 'Альтернатива звёздочке: номер варианта (2), список (1,3), текст ответа, для boolean — да/нет.',
                'example'  => 'Марс',
                'width'    => 22,
                'required' => false,
            ],
            'target_value' => [
                'label'    => 'Число',
                'hint'     => 'Правильное число для number и slider. Допускается запятая: 9,8.',
                'example'  => '9.8',
                'width'    => 12,
                'required' => false,
            ],
            'tolerance' => [
                'label'    => 'Погрешность ±',
                'hint'     => 'Допустимое отклонение для number и slider. 0 — точное совпадение.',
                'example'  => '0.1',
                'width'    => 12,
                'required' => false,
            ],
            'answers' => [
                'label'    => 'Синонимы ответа',
                'hint'     => 'Для text — допустимые варианты ответа через «|». Для word_scramble — слово или слова через «|».',
                'example'  => 'Санкт-Петербург | Петербург | Спб',
                'width'    => 32,
                'required' => false,
            ],
            'explanation' => [
                'label'    => 'Пояснение',
                'hint'     => 'Методический разбор, который показывается после ответа и попадает в протокол.',
                'example'  => 'Четвёртой планетой от Солнца является Марс.',
                'width'    => 34,
                'required' => false,
            ],
            'is_required' => [
                'label'    => 'Обязательный',
                'hint'     => '1 — без ответа нельзя завершить тест, 0 — можно пропустить.',
                'example'  => '1',
                'width'    => 12,
                'required' => false,
            ],
            'content' => [
                'label'    => 'Доп. текст (HTML)',
                'hint'     => 'Дополнительный блок под формулировкой: таблица, список, цитата. Обычный HTML.',
                'example'  => '',
                'width'    => 30,
                'required' => false,
            ],
            'image_url' => [
                'label'    => 'Иллюстрация (URL)',
                'hint'     => 'Полная ссылка на картинку к заданию. Необязательно.',
                'example'  => '',
                'width'    => 26,
                'required' => false,
            ],
            'shared_text_id' => [
                'label'    => 'Общий текст (ID)',
                'hint'     => 'ID общего текста/кейса олимпиады (создаётся в конструкторе). Необязательно.',
                'example'  => '',
                'width'    => 16,
                'required' => false,
            ],
            'template' => [
                'label'    => 'Текст с пропусками',
                'hint'     => 'Только для fill_blank и dictation: текст с пропусками {ответ|синоним}. Если пусто — берётся из колонки «Текст вопроса».',
                'example'  => '',
                'width'    => 34,
                'required' => false,
            ],
            'extra' => [
                'label'    => 'Доп. параметры',
                'hint'     => 'Пары key=value через «;». Ключи: min, max, step, extra_letters, audio_url, scoring, mistakes, mode, distractors, min_words, max_words, keywords, min_keywords, match, typo, shuffle, case_sensitive, show_points (1 — показывать баллы за это задание, по умолчанию они скрыты).',
                'example'  => '',
                'width'    => 30,
                'required' => false,
            ],
        ];
    }

    /**
     * Canonical column keys in CSV order.
     *
     * @return string[]
     */
    public static function column_keys(): array
    {
        return array_keys(self::columns());
    }

    /**
     * Header aliases: canonical key => list of accepted header spellings.
     *
     * @return array<string, string[]>
     */
    public static function header_aliases(): array
    {
        return [
            'type'           => ['тип', 'тип вопроса', 'тип задания', 'тип заданий', 'type', 'question_type', 'question type', 'вид'],
            'title'          => ['вопрос', 'текст', 'текст вопроса', 'формулировка', 'название', 'задание', 'title', 'question', 'question text'],
            'points'         => ['баллы', 'балл', 'очки', 'вес', 'points', 'score', 'mark'],
            'penalty'        => ['штраф', 'штрафные баллы', 'минус', 'penalty', 'penalty points'],
            'group_name'     => ['раздел', 'часть', 'группа', 'блок', 'тема', 'категория', 'group', 'group_name', 'section', 'category'],
            'choices'        => ['варианты', 'варианты ответов', 'варианты_ответов', 'вариант', 'элементы', 'список', 'пары', 'choices', 'options', 'items'],
            'correct'        => ['правильный', 'правильный ответ', 'правильный_ответ', 'правильные ответы', 'верно', 'ключ', 'ответ верный', 'correct', 'correct answer', 'key'],
            'target_value'   => ['значение', 'число', 'числовое значение', 'правильное число', 'target', 'target_value', 'value', 'number'],
            'tolerance'      => ['погрешность', 'допуск', 'отклонение', 'tolerance', 'delta', '±'],
            'answers'        => ['синонимы', 'ответы', 'ответ', 'допустимые ответы', 'слова', 'answers', 'synonyms', 'accepted answers'],
            'explanation'    => ['пояснение', 'комментарий', 'разбор', 'решение', 'explanation', 'comment', 'feedback'],
            'is_required'    => ['обязательный', 'обязательно', 'обязательность', 'required', 'is_required', 'must answer'],
            'content'        => ['доп', 'дополнительно', 'дополнительный текст', 'контент', 'html', 'content', 'body'],
            'image_url'      => ['иллюстрация', 'картинка', 'изображение', 'image', 'image_url', 'picture'],
            'shared_text_id' => ['общий текст', 'контекст', 'кейс', 'shared', 'shared_text', 'shared_text_id', 'passage'],
            'template'       => ['текст с пропусками', 'шаблон', 'пропуски', 'template', 'blank template'],
            'extra'          => ['доп параметры', 'доп_параметры', 'параметры', 'extra', 'params', 'options extra'],
            // Optional helper columns (accepted from uploaded files, not part of the Excel template).
            'match_mode'     => ['режим сравнения', 'режим проверки', 'сравнение', 'match_mode', 'match mode', 'match'],
            'image_caption'  => ['подпись к картинке', 'подпись', 'image_caption', 'caption'],
            'case_sensitive' => ['регистр', 'учёт регистра', 'case_sensitive', 'case sensitive'],
        ];
    }

    /**
     * Normalize a raw header cell into a canonical column key (or '').
     */
    public static function normalize_header(string $raw): string
    {
        $norm = mb_strtolower(trim($raw));
        $norm = str_replace(["\u{00A0}", '_'], [' ', ' '], $norm);
        // Strip commentary in parentheses: "Тип (type)" → "тип".
        $norm = (string)preg_replace('/\(.*?\)/u', ' ', $norm);
        // Dots inside the header are separators: "доп. текст" → "доп текст".
        $norm = str_replace('.', ' ', $norm);
        $norm = trim((string)preg_replace('/\s+/u', ' ', $norm));
        $norm = trim($norm, " \t:*±");

        if ($norm === '') {
            return '';
        }

        $aliases_map = self::header_aliases();

        // 1. Exact alias match.
        foreach ($aliases_map as $key => $aliases) {
            if (in_array($norm, $aliases, true)) {
                return $key;
            }
        }

        // 2. Canonical key in any spelling.
        $collapsed = str_replace(' ', '_', $norm);
        if (array_key_exists($collapsed, self::columns())) {
            return $collapsed;
        }

        // 3. Composite headers: "Варианты / пары / элементы", "Баллы, очки" — try every part.
        $parts = array_values(array_filter(array_map('trim', preg_split('#[/|,+;]#u', $norm) ?: [])));
        if (count($parts) > 1) {
            foreach ($parts as $part) {
                if ($part === '') {
                    continue;
                }
                foreach ($aliases_map as $key => $aliases) {
                    if (in_array($part, $aliases, true)) {
                        return $key;
                    }
                }
                if (array_key_exists(str_replace(' ', '_', $part), self::columns())) {
                    return str_replace(' ', '_', $part);
                }
            }
        }

        // 4. Prefix match, longest alias wins: "Ключ ответа" → "ключ", "Доп текст" → "доп".
        $best_key = '';
        $best_len = 0;
        foreach ($aliases_map as $key => $aliases) {
            foreach ($aliases as $alias) {
                if ($alias === '' || mb_strlen($alias) > mb_strlen($norm)) {
                    continue;
                }
                if ($norm === $alias || str_starts_with($norm, $alias . ' ')) {
                    $len = mb_strlen($alias);
                    if ($len > $best_len) {
                        $best_len = $len;
                        $best_key = $key;
                    }
                }
            }
        }

        if ($best_key !== '') {
            return $best_key;
        }

        // 5. Suffix match for "текст вопроса" style headers built as "<word> <alias>".
        foreach ($aliases_map as $key => $aliases) {
            foreach ($aliases as $alias) {
                if ($alias === '') {
                    continue;
                }
                if (str_ends_with($norm, ' ' . $alias)) {
                    $len = mb_strlen($alias);
                    if ($len > $best_len) {
                        $best_len = $len;
                        $best_key = $key;
                    }
                }
            }
        }

        return $best_key;
    }

    /**
     * Question type aliases (Russian and common English spellings) => canonical key.
     *
     * @return array<string, string>
     */
    public static function type_aliases(): array
    {
        $aliases = [
            'один выбор'            => 'single',
            'одиночный выбор'       => 'single',
            'один вариант'          => 'single',
            'радио'                 => 'single',
            'radio'                 => 'single',
            'single_choice'         => 'single',
            'singlechoice'          => 'single',
            'choice'                => 'single',
            'множественный выбор'   => 'multi',
            'множественный'         => 'multi',
            'несколько ответов'     => 'multi',
            'чекбокс'               => 'multi',
            'checkbox'              => 'multi',
            'multiple'              => 'multi',
            'multi_choice'          => 'multi',
            'выпадающий список'     => 'dropdown',
            'список'                => 'dropdown',
            'select'                => 'dropdown',
            'верно неверно'         => 'boolean',
            'верно/неверно'         => 'boolean',
            'да/нет'                => 'boolean',
            'да нет'                => 'boolean',
            'истина/ложь'           => 'boolean',
            'true_false'            => 'boolean',
            'truefalse'             => 'boolean',
            'bool'                  => 'boolean',
            'число'                 => 'number',
            'числовой'              => 'number',
            'числовой ответ'        => 'number',
            'цифра'                 => 'number',
            'numeric'               => 'number',
            'текст'                 => 'text',
            'краткий ответ'         => 'text',
            'краткий текстовый ответ' => 'text',
            'слово'                 => 'text',
            'short answer'          => 'text',
            'соответствие'          => 'matching',
            'соответствия'          => 'matching',
            'установление соответствий' => 'matching',
            'сопоставление'         => 'matching',
            'match'                 => 'matching',
            'последовательность'    => 'ordering',
            'порядок'               => 'ordering',
            'упорядочивание'        => 'ordering',
            'хронология'            => 'ordering',
            'sequence'              => 'ordering',
            'sort'                  => 'ordering',
            'пропуски'              => 'fill_blank',
            'заполнение пропусков'  => 'fill_blank',
            'вставка пропусков'     => 'fill_blank',
            'fill'                  => 'fill_blank',
            'blank'                 => 'fill_blank',
            'cloze'                 => 'fill_blank',
            'шкала'                 => 'slider',
            'ползунок'              => 'slider',
            'диапазон'              => 'slider',
            'scale'                 => 'slider',
            'range'                 => 'slider',
            'анаграмма'             => 'word_scramble',
            'слово из букв'         => 'word_scramble',
            'составление слова'     => 'word_scramble',
            'перемешанные буквы'    => 'word_scramble',
            'scramble'              => 'word_scramble',
            'anagram'               => 'word_scramble',
            'диктант'               => 'dictation',
            'интерактивный диктант' => 'dictation',
            'орфография'            => 'dictation',
            'эссе'                  => 'essay',
            'развёрнутый ответ'     => 'essay',
            'развернутый ответ'     => 'essay',
            'сочинение'             => 'essay',
            'решение'               => 'essay',
            'long answer'           => 'essay',
            'open answer'           => 'essay',
        ];

        // Short canonical keys are always accepted (normalized later by sanitize_key()).
        foreach (array_keys(Question::supported_types()) as $key) {
            $aliases[$key] = $key;
        }

        return $aliases;
    }

    /**
     * Normalize a raw question type cell into a supported canonical type.
     */
    /**
     * Whether the raw type value is a known alias/code (no "typo" warning needed).
     */
    public static function is_known_type(string $raw): bool
    {
        $raw = mb_strtolower(trim($raw));
        if ($raw === '') {
            return true;
        }

        $raw = str_replace(['-', ' '], ['_', '_'], $raw);
        $raw = (string)preg_replace('/_+/', '_', $raw);
        $raw = trim($raw, '_');

        $aliases = self::type_aliases();
        if (isset($aliases[$raw]) || isset($aliases[str_replace('_', ' ', $raw)])) {
            return true;
        }

        $supported = array_keys(Question::supported_types());
        if (in_array($raw, $supported, true)) {
            return true;
        }

        foreach ($supported as $code) {
            $spaced = str_replace('_', ' ', $code);
            if ($raw === $spaced || str_replace('_', ' ', $raw) === $spaced) {
                return true;
            }
        }

        return in_array($raw, ['radio', 'checkbox', 'truefalse', 'true_false'], true);
    }

    public static function normalize_type(string $raw): string
    {
        $raw = mb_strtolower(trim($raw));
        if ($raw === '') {
            return 'single';
        }

        $raw = str_replace(['-', ' '], ['_', '_'], $raw);
        $raw = (string)preg_replace('/_+/', '_', $raw);
        $raw = trim($raw, '_');

        $aliases = self::type_aliases();

        if (isset($aliases[$raw])) {
            return $aliases[$raw];
        }

        // Try the spaced variant ("один_выбор" → "один выбор").
        $spaced = str_replace('_', ' ', $raw);
        if (isset($aliases[$spaced])) {
            return $aliases[$spaced];
        }

        $supported = array_keys(Question::supported_types());
        if (in_array($raw, $supported, true)) {
            return $raw;
        }

        // Legacy synonyms used by older templates.
        $legacy = [
            'radio'      => 'single',
            'checkbox'   => 'multi',
            'truefalse'  => 'boolean',
            'true_false' => 'boolean',
        ];

        return $legacy[$raw] ?? 'single';
    }

    /**
     * Parse the "extra" column: "min=0; max=100; step=5".
     * Supports both "=" and ":" and both ";" and "|" as pair separators
     * (unless the value looks like a pipe-separated list, e.g. keywords).
     *
     * @return array<string, string>
     */
    public static function parse_extra(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $out = [];
        $parts = preg_split('/[;\n]+/u', $raw) ?: [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (!preg_match('/^([a-zA-Zа-яА-Я_]+)\s*[=:]\s*(.*)$/u', $part, $m)) {
                continue;
            }
            $key = mb_strtolower(trim($m[1]));
            $value = trim($m[2]);
            // Only the first occurrence wins — protects against typo duplicates.
            if (!isset($out[$key])) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * Convert a value from a cell to bool (1/0, да/нет, true/false, +/−).
     */
    public static function to_bool(mixed $value, bool $default = false): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        $norm = mb_strtolower(trim((string)$value));

        return in_array($norm, ['1', 'true', 'yes', 'y', 'да', 'д', 'верно', 'вкл', 'on', '+', 'истина'], true);
    }

    /**
     * Convert a cell to float, tolerating "9,8", "±0.1", spaces and unicode minus.
     */
    public static function to_float(mixed $value, float $default = 0.0): float
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_int($value) || is_float($value)) {
            return (float)$value;
        }
        $clean = str_replace([' ', "\u{00A0}", ',', '±', "\u{2212}", "\u{2013}"], ['', '', '.', '', '-', '-'], (string)$value);
        $clean = trim($clean);

        return is_numeric($clean) ? (float)$clean : $default;
    }

    /**
     * One sample row per supported question type (used by every template format).
     *
     * @return array<string, array<string, string>>
     */
    public static function samples(): array
    {
        return [
            'single' => [
                'type'        => 'single',
                'title'       => 'Какая планета является четвёртой по удалённости от Солнца?',
                'points'      => '3',
                'penalty'     => '1',
                'group_name'  => 'Часть 1: Астрономия',
                'choices'     => 'Венера | Земля | *Марс | Юпитер',
                'correct'     => '',
                'target_value'=> '',
                'tolerance'   => '',
                'answers'     => '',
                'explanation' => 'Четвёртой планетой от Солнца является Марс.',
                'is_required' => '1',
                'content'     => '',
                'image_url'   => '',
                'shared_text_id' => '',
                'template'    => '',
                'extra'       => '',
            ],
            'multi' => [
                'type'        => 'multi',
                'title'       => 'Какие из перечисленных элементов являются благородными газами?',
                'points'      => '6',
                'penalty'     => '1',
                'group_name'  => 'Часть 1: Химия',
                'choices'     => '*Гелий^2 | Кислород | *Неон^2 | Водород | *Аргон^2',
                'correct'     => '',
                'target_value'=> '',
                'tolerance'   => '',
                'answers'     => '',
                'explanation' => 'Гелий, неон и аргон — благородные газы. Баллы начисляются пропорционально выбранным верным пунктам.',
                'is_required' => '0',
                'content'     => '',
                'image_url'   => '',
                'shared_text_id' => '',
                'template'    => '',
                'extra'       => 'shuffle=1',
            ],
            'dropdown' => [
                'type'        => 'dropdown',
                'title'       => 'He ___ to school every day.',
                'points'      => '2',
                'penalty'     => '0',
                'group_name'  => 'Часть 2: Английский язык',
                'choices'     => 'go | *goes | gone | going',
                'correct'     => '',
                'target_value'=> '',
                'tolerance'   => '',
                'answers'     => '',
                'explanation' => 'Present Simple, 3-е лицо единственного числа: goes.',
                'is_required' => '0',
                'content'     => '',
                'image_url'   => '',
                'shared_text_id' => '',
                'template'    => '',
                'extra'       => 'shuffle=0',
            ],
            'boolean' => [
                'type'        => 'boolean',
                'title'       => 'Скорость света в вакууме составляет около 300 000 км/с.',
                'points'      => '2',
                'penalty'     => '0',
                'group_name'  => 'Часть 1: Физика',
                'choices'     => '',
                'correct'     => 'да',
                'target_value'=> '',
                'tolerance'   => '',
                'answers'     => '',
                'explanation' => 'Скорость света c ≈ 299 792 458 м/с.',
                'is_required' => '0',
                'content'     => '',
                'image_url'   => '',
                'shared_text_id' => '',
                'template'    => '',
                'extra'       => '',
            ],
            'number' => [
                'type'        => 'number',
                'title'       => 'Чему равно стандартное ускорение свободного падения на Земле (м/с²)?',
                'points'      => '5',
                'penalty'     => '0',
                'group_name'  => 'Часть 2: Расчётные задачи',
                'choices'     => '',
                'correct'     => '',
                'target_value'=> '9,8',
                'tolerance'   => '0,1',
                'answers'     => '',
                'explanation' => 'g ≈ 9,80665 м/с²; принимается ответ в диапазоне 9,7–9,9.',
                'is_required' => '1',
                'content'     => '',
                'image_url'   => '',
                'shared_text_id' => '',
                'template'    => '',
                'extra'       => '',
            ],
            'text' => [
                'type'        => 'text',
                'title'       => 'В каком городе находится музей Эрмитаж?',
                'points'      => '4',
                'penalty'     => '0',
                'group_name'  => 'Часть 1: Культура',
                'choices'     => '',
                'correct'     => '',
                'target_value'=> '',
                'tolerance'   => '',
                'answers'     => 'Санкт-Петербург | Петербург | Спб | Ленинград',
                'explanation' => 'Государственный Эрмитаж находится в Санкт-Петербурге.',
                'is_required' => '0',
                'content'     => '',
                'image_url'   => '',
                'shared_text_id' => '',
                'template'    => '',
                'extra'       => 'match=fuzzy; typo=1',
            ],
            'matching' => [
                'type'        => 'matching',
                'title'       => 'Сопоставьте государства и их столицы:',
                'points'      => '6',
                'penalty'     => '0',
                'group_name'  => 'Часть 2: География',
                'choices'     => 'Франция => Париж | Италия => Рим | Япония => Токио | Бразилия => Бразилиа',
                'correct'     => '',
                'target_value'=> '',
                'tolerance'   => '',
                'answers'     => '',
                'explanation' => 'Базовые географические соответствия стран и столиц.',
                'is_required' => '0',
                'content'     => '',
                'image_url'   => '',
                'shared_text_id' => '',
                'template'    => '',
                'extra'       => '',
            ],
            'ordering' => [
                'type'        => 'ordering',
                'title'       => 'Расположите исторические эпохи в правильном хронологическом порядке:',
                'points'      => '5',
                'penalty'     => '0',
                'group_name'  => 'Часть 2: История',
                'choices'     => 'Древний мир | Средние века | Новое время | Новейшее время',
                'correct'     => '',
                'target_value'=> '',
                'tolerance'   => '',
                'answers'     => '',
                'explanation' => 'Классическая периодизация всемирной истории.',
                'is_required' => '0',
                'content'     => '',
                'image_url'   => '',
                'shared_text_id' => '',
                'template'    => '',
                'extra'       => '',
            ],
            'fill_blank' => [
                'type'        => 'fill_blank',
                'title'       => 'В {=1242|двенадцать сорок втором} году произошло Ледовое побоище на {=Чудском|Чудском озере}.',
                'points'      => '5',
                'penalty'     => '0',
                'group_name'  => 'Часть 2: История',
                'choices'     => '',
                'correct'     => '',
                'target_value'=> '',
                'tolerance'   => '',
                'answers'     => '',
                'explanation' => 'Битва на Чудском озере состоялась 5 апреля 1242 года.',
                'is_required' => '0',
                'content'     => '',
                'image_url'   => '',
                'shared_text_id' => '',
                'template'    => '',
                'extra'       => 'mode=input; case_sensitive=0',
            ],
            'slider' => [
                'type'        => 'slider',
                'title'       => 'Укажите, насколько вы согласны с утверждением: «Олимпиада была интересной» (0–10).',
                'points'      => '2',
                'penalty'     => '0',
                'group_name'  => 'Часть 3: Рефлексия',
                'choices'     => '',
                'correct'     => '',
                'target_value'=> '8',
                'tolerance'   => '1',
                'answers'     => '',
                'explanation' => 'Зачётный диапазон 7–9 по десятибалльной шкале.',
                'is_required' => '1',
                'content'     => '',
                'image_url'   => '',
                'shared_text_id' => '',
                'template'    => '',
                'extra'       => 'min=0; max=10; step=1',
            ],
            'word_scramble' => [
                'type'        => 'word_scramble',
                'title'       => 'Составьте слово из букв: ',
                'points'      => '3',
                'penalty'     => '0',
                'group_name'  => 'Часть 1: Русский язык',
                'choices'     => '',
                'correct'     => '',
                'target_value'=> '',
                'tolerance'   => '',
                'answers'     => 'КВАНТ | КВАНТЫ',
                'explanation' => 'Термин «квант» — минимальная порция энергии.',
                'is_required' => '0',
                'content'     => '',
                'image_url'   => '',
                'shared_text_id' => '',
                'template'    => '',
                'extra'       => 'extra_letters=Ы; case_sensitive=0',
            ],
            'dictation' => [
                'type'        => 'dictation',
                'title'       => 'Вставьте пропущенные буквы:',
                'points'      => '6',
                'penalty'     => '0',
                'group_name'  => 'Часть 1: Русский язык',
                'choices'     => '',
                'correct'     => '',
                'target_value'=> '',
                'tolerance'   => '',
                'answers'     => '',
                'explanation' => 'Проверяются орфограммы «безударная гласная в корне» и «непроизносимая согласная».',
                'is_required' => '1',
                'content'     => '',
                'image_url'   => '',
                'shared_text_id' => '',
                'template'    => 'Быстро {=ск|згк}ать по {=лест|лезт}нице.',
                'extra'       => 'scoring=proportional; mistakes=1',
            ],
            'essay' => [
                'type'        => 'essay',
                'title'       => 'Сформулируйте третий закон Ньютона и опишите пример его проявления в технике.',
                'points'      => '10',
                'penalty'     => '0',
                'group_name'  => 'Часть 3: Развёрнутые решения',
                'choices'     => '',
                'correct'     => '',
                'target_value'=> '',
                'tolerance'   => '',
                'answers'     => '',
                'explanation' => 'Сила действия равна силе противодействия (реактивное движение, отдача).',
                'is_required' => '1',
                'content'     => '',
                'image_url'   => '',
                'shared_text_id' => '',
                'template'    => '',
                'extra'       => 'min_words=50; max_words=250; keywords=сила|противодействие|отдача; min_keywords=2',
            ],
        ];
    }

    /**
     * Build CSV text (UTF-8 with BOM, ';' delimiter — Excel friendly).
     */
    public static function csv(): string
    {
        $keys = self::column_keys();

        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            return '';
        }

        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $keys, ';', '"', '');

        foreach (self::samples() as $sample) {
            $row = [];
            foreach ($keys as $key) {
                $row[] = (string)($sample[$key] ?? '');
            }
            fputcsv($out, $row, ';', '"', '');
        }

        rewind($out);
        $csv = (string)stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /**
     * Build JSON template (all supported types, machine-readable schema).
     *
     * @return array<string, mixed>
     */
    public static function json_data(): array
    {
        return [
            'format'         => 'wp-olympiad-questions',
            'template_version' => self::VERSION,
            'olympiad_title' => 'Пример шаблона олимпиадных заданий',
            'encoding'       => 'UTF-8',
            'question_types' => array_keys(Question::supported_types()),
            'columns'        => array_map(static fn(array $c): string => $c['label'], self::columns()),
            'questions'      => [
                [
                    'question_type' => 'single',
                    'title'         => 'Какая планета является четвёртой по удалённости от Солнца?',
                    'points'        => 3,
                    'penalty'       => 1,
                    'group_name'    => 'Часть 1: Астрономия',
                    'is_required'   => 1,
                    'options'       => [
                        'choices' => [
                            ['id' => 'c1', 'text' => 'Венера', 'is_correct' => 0],
                            ['id' => 'c2', 'text' => 'Земля', 'is_correct' => 0],
                            ['id' => 'c3', 'text' => 'Марс', 'is_correct' => 1],
                            ['id' => 'c4', 'text' => 'Юпитер', 'is_correct' => 0],
                        ],
                        'explanation' => 'Четвёртой планетой является Марс.',
                    ],
                ],
                [
                    'question_type' => 'multi',
                    'title'         => 'Какие из перечисленных элементов являются благородными газами?',
                    'points'        => 6,
                    'penalty'       => 1,
                    'group_name'    => 'Часть 1: Химия',
                    'options'       => [
                        'choices' => [
                            ['id' => 'c1', 'text' => 'Гелий', 'is_correct' => 1, 'points' => 2],
                            ['id' => 'c2', 'text' => 'Кислород', 'is_correct' => 0],
                            ['id' => 'c3', 'text' => 'Неон', 'is_correct' => 1, 'points' => 2],
                            ['id' => 'c4', 'text' => 'Аргон', 'is_correct' => 1, 'points' => 2],
                        ],
                        'explanation' => 'Благородные газы: гелий, неон, аргон.',
                    ],
                ],
                [
                    'question_type' => 'dropdown',
                    'title'         => 'He ___ to school every day.',
                    'points'        => 2,
                    'group_name'    => 'Часть 2: Английский язык',
                    'options'       => [
                        'choices' => [
                            ['id' => 'c1', 'text' => 'go', 'is_correct' => 0],
                            ['id' => 'c2', 'text' => 'goes', 'is_correct' => 1],
                            ['id' => 'c3', 'text' => 'gone', 'is_correct' => 0],
                            ['id' => 'c4', 'text' => 'going', 'is_correct' => 0],
                        ],
                        'shuffle_answers' => 0,
                        'explanation'     => 'Present Simple, 3-е лицо единственного числа: goes.',
                    ],
                ],
                [
                    'question_type' => 'boolean',
                    'title'         => 'Скорость света в вакууме составляет около 300 000 км/с.',
                    'points'        => 2,
                    'group_name'    => 'Часть 1: Физика',
                    'options'       => [
                        'choices' => [
                            ['id' => 'true', 'text' => 'Верно / Да', 'is_correct' => 1],
                            ['id' => 'false', 'text' => 'Неверно / Нет', 'is_correct' => 0],
                        ],
                        'explanation' => 'Скорость света c ≈ 299 792 458 м/с.',
                    ],
                ],
                [
                    'question_type' => 'number',
                    'title'         => 'Чему равно стандартное ускорение свободного падения на Земле (м/с²)?',
                    'points'        => 5,
                    'group_name'    => 'Часть 2: Расчётные задачи',
                    'options'       => [
                        'target_value' => 9.8,
                        'tolerance'    => 0.1,
                        'explanation'  => 'g ≈ 9,80665 м/с².',
                    ],
                ],
                [
                    'question_type' => 'text',
                    'title'         => 'В каком городе находится музей Эрмитаж?',
                    'points'        => 4,
                    'group_name'    => 'Часть 1: Культура',
                    'options'       => [
                        'answers'    => ['Санкт-Петербург', 'Петербург', 'Спб'],
                        'match_mode' => 'fuzzy',
                        'explanation' => 'Эрмитаж расположен в Санкт-Петербурге.',
                    ],
                ],
                [
                    'question_type' => 'matching',
                    'title'         => 'Сопоставьте государства и их столицы:',
                    'points'        => 6,
                    'options'       => [
                        'pairs' => [
                            ['id' => 'p1', 'left' => 'Франция', 'right' => 'Париж'],
                            ['id' => 'p2', 'left' => 'Италия', 'right' => 'Рим'],
                            ['id' => 'p3', 'left' => 'Япония', 'right' => 'Токио'],
                        ],
                    ],
                ],
                [
                    'question_type' => 'ordering',
                    'title'         => 'Расположите исторические эпохи в правильном порядке:',
                    'points'        => 5,
                    'options'       => [
                        'items' => [
                            ['id' => 'ord_1', 'text' => 'Древний мир'],
                            ['id' => 'ord_2', 'text' => 'Средние века'],
                            ['id' => 'ord_3', 'text' => 'Новое время'],
                        ],
                    ],
                ],
                [
                    'question_type' => 'fill_blank',
                    'title'         => 'Вставьте пропущенные слова:',
                    'points'        => 5,
                    'options'       => [
                        'template'    => 'В {=1242|двенадцать сорок втором} году произошло Ледовое побоище.',
                        'choice_mode' => 'input',
                    ],
                ],
                [
                    'question_type' => 'slider',
                    'title'         => 'Оцените сложность олимпиады по шкале от 0 до 10.',
                    'points'        => 2,
                    'options'       => [
                        'min'          => 0,
                        'max'          => 10,
                        'step'         => 1,
                        'target_value' => 8,
                        'tolerance'    => 1,
                    ],
                ],
                [
                    'question_type' => 'word_scramble',
                    'title'         => 'Составьте слово из букв.',
                    'points'        => 3,
                    'options'       => [
                        'words'         => ['КВАНТ'],
                        'extra_letters' => 'Ы',
                    ],
                ],
                [
                    'question_type' => 'dictation',
                    'title'         => 'Вставьте пропущенные буквы:',
                    'points'        => 6,
                    'options'       => [
                        'template'       => 'Быстро {=ск|згк}ать по {=лест|лезт}нице.',
                        'scoring_mode'   => 'proportional',
                        'allowed_mistakes' => 1,
                    ],
                ],
                [
                    'question_type' => 'essay',
                    'title'         => 'Сформулируйте третий закон Ньютона и приведите пример.',
                    'points'        => 10,
                    'options'       => [
                        'min_words'    => 50,
                        'max_words'    => 250,
                        'keywords'     => ['сила', 'противодействие', 'отдача'],
                        'min_keywords' => 2,
                        'explanation'  => 'Сила действия равна силе противодействия.',
                    ],
                ],
            ],
        ];
    }

    /**
     * Build JSON template text.
     */
    public static function json(): string
    {
        return (string)wp_json_encode(self::json_data(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Human-readable import guide (also deployed into the network folder).
     */
    public static function readme(): string
    {
        $lines = [];
        $lines[] = '================================================================================';
        $lines[] = '  РУКОВОДСТВО ПО ПОДГОТОВКЕ ВОПРОСОВ ДЛЯ ИМПОРТА (WP OLYMPIAD) — формат ' . self::VERSION;
        $lines[] = '================================================================================';
        $lines[] = '';
        $lines[] = 'БЫСТРЫЙ СТАРТ';
        $lines[] = '  1. Откройте questions_template_macros.xlsm (или .xlsx) либо questions_template.csv.';
        $lines[] = '  2. Заполните строки: обязательны только колонки «type» и «title».';
        $lines[] = '  3. Нажмите «✔ Проверить данные» (в .xlsm) или сохраните файл под именем questions.csv.';
        $lines[] = '  4. Положите файл в сетевую папку (каталог import/ или корень) — плагин импортирует его';
        $lines[] = '     автоматически, либо нажмите «📥 Импортировать вопросы из папки сейчас».';
        $lines[] = '';
        $lines[] = 'ФАЙЛЫ ШАБЛОНОВ';
        $lines[] = '  questions_template_macros.xlsm — Excel с макросами: проверка данных, экспорт CSV, добавление строк';
        $lines[] = '  questions_template.xlsx        — Excel с выпадающими списками и подсказками к каждой колонке';
        $lines[] = '  questions_template.csv         — CSV (UTF-8 BOM, разделитель «;») — самый быстрый способ';
        $lines[] = '  questions_template.json        — структурированный JSON со всеми типами заданий';
        $lines[] = '';
        $lines[] = 'КОЛОНКИ ТАБЛИЦЫ (порядок важен только для CSV)';
        $i = 0;
        foreach (self::columns() as $key => $col) {
            $i++;
            $lines[] = sprintf('  %2d. %-16s %s%s', $i, $key, $col['label'], $col['required'] ? '  (обязательно)' : '');
            $lines[] = '      ' . $col['hint'];
            if ($col['example'] !== '') {
                $lines[] = '      пример: ' . $col['example'];
            }
        }
        $lines[] = '';
        $lines[] = 'РУССКИЕ НАЗВАНИЯ КОЛОНОК И ТИПОВ ПОДДЕРЖИВАЮТСЯ';
        $lines[] = '  Заголовки можно писать по-русски: «Тип», «Вопрос», «Баллы», «Штраф», «Раздел», «Варианты»,';
        $lines[] = '  «Правильный ответ», «Число», «Погрешность», «Синонимы», «Пояснение», «Обязательный».';
        $lines[] = '  Типы: «один выбор», «множественный», «да/нет», «число», «текст», «соответствие»,';
        $lines[] = '  «последовательность», «пропуски», «шкала», «анаграмма», «диктант», «эссе».';
        $lines[] = '';
        $lines[] = 'ВСЕ 13 ТИПОВ ЗАДАНИЙ';
        foreach (self::samples() as $type => $sample) {
            $lines[] = sprintf('  • %-14s %s', $type, $sample['title']);
            foreach (['choices', 'correct', 'target_value', 'tolerance', 'answers', 'template', 'extra'] as $field) {
                if ((string)$sample[$field] !== '') {
                    $lines[] = sprintf('      %-13s %s', $field . ':', (string)$sample[$field]);
                }
            }
        }
        $lines[] = '';
        $lines[] = 'КОЛОНКА extra — ДОПОЛНИТЕЛЬНЫЕ ПАРАМЕТРЫ';
        $lines[] = '  Формат: key=value; key2=value2';
        $lines[] = '  min / max / step          — границы и шаг для slider (пример: min=0; max=100; step=5)';
        $lines[] = '  extra_letters             — лишние буквы-обманки для word_scramble';
        $lines[] = '  audio_url                 — ссылка на аудио для dictation';
        $lines[] = '  scoring                   — proportional | all_or_nothing (dictation, essay)';
        $lines[] = '  mistakes                  — допустимое число ошибок (dictation)';
        $lines[] = '  mode                      — input | dropdown | word_bank (fill_blank)';
        $lines[] = '  distractors               — слова-обманки для fill_blank через запятую';
        $lines[] = '  min_words / max_words     — ограничение объёма эссе';
        $lines[] = '  keywords / min_keywords   — ключевые понятия для автопроверки эссе';
        $lines[] = '  match                     — exact | contains_any | contains_all | fuzzy | regex (text)';
        $lines[] = '  typo                      — допустимое число опечаток в текстовом ответе (0–10)';
        $lines[] = '  shuffle                   — 1/0, перемешивать ли варианты ответа';
        $lines[] = '  case_sensitive            — 1/0, учитывать ли регистр';
        $lines[] = '  show_points               — 1/0, показывать ли баллы за задание в тесте.';
        $lines[] = '                              По умолчанию у импортированных заданий баллы СКРЫТЫ';
        $lines[] = '                              (галочка «Скрыть баллы за этот вопрос в тесте» включена);';
        $lines[] = '                              show_points=1 или hide_points=0 — показать баллы.';
        $lines[] = '';
        $lines[] = 'РАЗМЕТКА ОТВЕТОВ';
        $lines[] = '  single / multi / dropdown : варианты через «|», правильные помечаются «*» или номером в correct';
        $lines[] = '  баллы за конкретный пункт : звёздочка + ^баллы, например *Гелий^2';
        $lines[] = '  matching                  : «Франция => Париж | Италия => Рим» (работает также «Франция - Париж»)';
        $lines[] = '  ordering                  : элементы в правильном порядке через «|»';
        $lines[] = '  fill_blank / dictation    : пропуски в фигурных скобках {=верный|синоним|обманка}';
        $lines[] = '  boolean                   : correct = да / нет (или 1 / 0, true / false)';
        $lines[] = '  number / slider           : target_value + tolerance (запятая в числе допустима)';
        $lines[] = '';
        $lines[] = 'ЧАСТЫЕ ОШИБКИ';
        $lines[] = '  ✗ Пустая колонка «title» — строка будет пропущена.';
        $lines[] = '  ✗ В single/multi/dropdown меньше двух вариантов — строка не импортируется.';
        $lines[] = '  ⚠ В single не отмечен правильный вариант — правильным станет первый вариант.';
        $lines[] = '  ✗ В fill_blank нет фигурных скобок — задание станет обычным текстовым вопросом.';
        $lines[] = '  ✗ Файл сохранён в кодировке ANSI/Windows-1251 — плагин перекодирует автоматически,';
        $lines[] = '    но надёжнее сохранять как «CSV UTF-8 (разделитель — точка с запятой)».';
        $lines[] = '';
        $lines[] = 'ПРОВЕРКА ПЕРЕД ИМПОРТОМ';
        $lines[] = '  В админке: «Подключение к сетевой папке» → «✔ Проверить файл заданий» (или «✔ Проверить файл»';
        $lines[] = '  в окне импорта конструктора) — покажет отчёт по каждой строке без изменения базы.';
        $lines[] = '  ✓ Готово   — строка корректна и будет импортирована;';
        $lines[] = '  ⚠ Внимание — задание импортируется, но есть замечание (проверьте его);';
        $lines[] = '  ✕ Ошибка   — строка НЕ импортируется, причину смотрите в отчёте.';
        $lines[] = '';
        $lines[] = 'ИМПОРТ ИЗ СЕТЕВОЙ ПАПКИ';
        $lines[] = '  Сохраните файл в import/, questions/ или вопросы/ — автоимпорт (по расписанию или при';
        $lines[] = '  сохранении) подхватит его автоматически. Режим append добавляет задания, replace заменяет.';
        $lines[] = '';

        return implode("\n", $lines);
    }
}
