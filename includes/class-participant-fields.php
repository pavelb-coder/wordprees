<?php
/**
 * Дополнительные поля анкеты участника (1.0.21).
 *
 * Описание полей живёт в настройках олимпиады (`custom_fields`), значения —
 * в колонке `attempts.participant_meta`. Класс умеет чистить описания,
 * проверять присланные значения и печатать их для выгрузок и уведомлений.
 */

namespace WPOlympiad;

if (!defined('ABSPATH')) {
    exit;
}

final class ParticipantFields
{
    public const MAX_FIELDS = 10;
    public const MAX_LABEL_LENGTH = 120;
    public const MAX_PLACEHOLDER_LENGTH = 200;
    public const MAX_OPTIONS = 40;
    public const MAX_OPTION_LENGTH = 160;

    /** Поля, meanings of which are understood everywhere. */
    public const TYPES = ['text', 'textarea', 'number', 'select', 'multiselect', 'checkbox', 'date'];

    /** Default length caps per type. */
    private const LENGTHS = [
        'text' => 300,
        'textarea' => 2000,
        'number' => 64,
        'select' => 200,
        'multiselect' => 800,
        'checkbox' => 1,
        'date' => 10,
    ];

    public static function label(string $type): string
    {
        switch ($type) {
            case 'textarea':
                return __('Многострочный текст', 'wp-olympiad');
            case 'number':
                return __('Число', 'wp-olympiad');
            case 'select':
                return __('Выпадающий список', 'wp-olympiad');
            case 'multiselect':
                return __('Несколько вариантов', 'wp-olympiad');
            case 'checkbox':
                return __('Да / нет (согласие)', 'wp-olympiad');
            case 'date':
                return __('Дата', 'wp-olympiad');
            case 'text':
            default:
                return __('Одна строка', 'wp-olympiad');
        }
    }

    /**
     * Нормализация описаний полей, заданных администратором.
     *
     * Пустые строки молча отбрасываются, identifiers становятся уникальными,
     * список вариантов обрезается по лимиту.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function sanitize_defs(array $raw): array
    {
        $clean = [];
        $used_ids = [];

        foreach (array_values($raw) as $idx => $item) {
            if (!is_array($item) || count($clean) >= self::MAX_FIELDS) {
                continue;
            }

            $label = mb_substr(sanitize_text_field((string)($item['label'] ?? '')), 0, self::MAX_LABEL_LENGTH, 'UTF-8');
            if (trim($label) === '') {
                continue;
            }

            $type = strtolower(sanitize_key((string)($item['type'] ?? 'text')));
            if (!in_array($type, self::TYPES, true)) {
                $type = 'text';
            }

            $id = sanitize_key((string)($item['id'] ?? ''));
            if ($id === '' || isset($used_ids[$id])) {
                $id = 'cf_' . ($idx + 1);
                $suffix = 1;
                while (isset($used_ids[$id])) {
                    $id = 'cf_' . ($idx + 1) . '_' . (++$suffix);
                }
            }
            $used_ids[$id] = true;

            $options = [];
            if (in_array($type, ['select', 'multiselect'], true) && isset($item['options'])) {
                $options = self::sanitize_options($item['options']);
            }

            $max_length = isset($item['max_length']) ? (int)$item['max_length'] : 0;
            $cap = self::LENGTHS[$type] ?? 300;
            if ($type === 'text' || $type === 'textarea') {
                $max_length = $max_length > 0 ? max(1, min($cap, $max_length)) : $cap;
            } else {
                $max_length = $cap;
            }

            $clean[] = [
                'id'          => $id,
                'type'        => $type,
                'label'       => $label,
                'placeholder' => mb_substr(sanitize_text_field((string)($item['placeholder'] ?? '')), 0, self::MAX_PLACEHOLDER_LENGTH, 'UTF-8'),
                'help'        => mb_substr(sanitize_text_field((string)($item['help'] ?? '')), 0, self::MAX_LABEL_LENGTH, 'UTF-8'),
                'required'    => !empty($item['required']) ? 1 : 0,
                'max_length'  => $max_length,
                'options'     => $options,
            ];
        }

        return $clean;
    }

    /**
     * @param mixed $raw
     * @return string[]
     */
    public static function sanitize_options($raw): array
    {
        if (is_string($raw)) {
            $raw = preg_split('/\R|,/', $raw) ?: [];
        }
        if (!is_array($raw)) {
            return [];
        }

        $options = [];
        foreach ($raw as $option) {
            if (!is_scalar($option)) {
                continue;
            }
            $value = mb_substr(sanitize_text_field((string)$option), 0, self::MAX_OPTION_LENGTH, 'UTF-8');
            if (trim($value) === '' || in_array($value, $options, true)) {
                continue;
            }
            $options[] = $value;
            if (count($options) >= self::MAX_OPTIONS) {
                break;
            }
        }

        return $options;
    }

    /**
     * Проверка присланной анкеты.
     *
     * @param array<int,array<string,mixed>> $defs
     * @param array<string,mixed>            $posted
     * @return array{values: array<string,string|array<int,string>>, errors: string[]}
     */
    public static function clean_values(array $defs, array $posted): array
    {
        $values = [];
        $errors = [];

        foreach ($defs as $def) {
            $id = (string)$def['id'];
            $label = (string)$def['label'];
            $type = (string)$def['type'];
            $raw = $posted[$id] ?? ($posted['cf_' . $id] ?? '');

            if ($type === 'multiselect') {
                $allowed = (array)($def['options'] ?? []);
                $given = self::normalize_multi_input($raw);
                $picked = [];
                foreach ($given as $item) {
                    if (!is_scalar($item)) {
                        continue;
                    }
                    $item = sanitize_text_field((string)$item);
                    if ($item !== '' && in_array($item, $allowed, true) && !in_array($item, $picked, true)) {
                        $picked[] = $item;
                    }
                }
                if ($picked && count($picked) > self::MAX_OPTIONS) {
                    $picked = array_slice($picked, 0, self::MAX_OPTIONS);
                }

                if (empty($picked)) {
                    if (!empty($def['required'])) {
                        $errors[] = sprintf(/* translators: %s: field label */ __('Не выбрано ни одного варианта в поле «%s».', 'wp-olympiad'), $label);
                        continue;
                    }
                    continue;
                }

                $values[$id] = $picked;
                continue;
            }

            if ($type === 'checkbox') {
                $checked = is_bool($raw) ? $raw : !in_array((string)$raw, ['', '0', 'off', 'no', 'false'], true);
                if (!$checked && !empty($def['required'])) {
                    $errors[] = sprintf(/* translators: %s: field label */ __('Не отмечено поле «%s».', 'wp-olympiad'), $label);
                    continue;
                }
                $values[$id] = $checked ? '1' : '';
                continue;
            }

            $value = is_scalar($raw) ? trim((string)$raw) : '';
            if ($value === '') {
                if (!empty($def['required'])) {
                    $errors[] = sprintf(/* translators: %s: field label */ __('Не заполнено поле «%s».', 'wp-olympiad'), $label);
                }
                continue;
            }

            switch ($type) {
                case 'number':
                    $normalized = str_replace(["\u{00A0}", ' '], '', $value);
                    $normalized = str_replace(',', '.', $normalized);
                    if (!is_numeric($normalized) || !is_finite((float)$normalized)) {
                        $errors[] = sprintf(/* translators: %s: field label */ __('В поле «%s» нужно ввести число.', 'wp-olympiad'), $label);
                        continue 2;
                    }
                    $value = (string)($normalized + 0);
                    break;

                case 'date':
                    $date = self::parse_date($value);
                    if ($date === '') {
                        $errors[] = sprintf(/* translators: %s: field label */ __('В поле «%s» дата введена неверно — нужен вид 2026-11-01 или 01.11.2026.', 'wp-olympiad'), $label);
                        continue 2;
                    }
                    $value = $date;
                    break;

                case 'select':
                    $allowed = (array)($def['options'] ?? []);
                    if ($allowed && !in_array($value, $allowed, true)) {
                        $errors[] = sprintf(/* translators: %s: field label */ __('В поле «%s» выбран неизвестный вариант.', 'wp-olympiad'), $label);
                        continue 2;
                    }
                    break;

                case 'textarea':
                    $value = sanitize_textarea_field($value);
                    break;

                case 'text':
                default:
                    $value = sanitize_text_field($value);
                    break;
            }

            $cap = (int)($def['max_length'] ?? self::LENGTHS['text']);
            if ($cap > 0 && $type !== 'date' && $type !== 'number') {
                $value = mb_substr($value, 0, $cap, 'UTF-8');
            }
            if ($value === '') {
                if (!empty($def['required'])) {
                    $errors[] = sprintf(/* translators: %s: field label */ __('Не заполнено поле «%s».', 'wp-olympiad'), $label);
                }
                continue;
            }

            $values[$id] = $value;
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * Вход мультивыбора: массив, JSON-строка или перечисление через запятую.
     *
     * @param mixed $raw
     * @return array<int,string>
     */
    public static function normalize_multi_input($raw): array
    {
        if (is_array($raw)) {
            return array_values(array_map(static fn($item) => is_scalar($item) ? trim((string)$item) : '', $raw));
        }

        if (!is_scalar($raw) || (string)$raw === '') {
            return [];
        }

        $text = trim((string)$raw);
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return array_values(array_map(static fn($item) => is_scalar($item) ? trim((string)$item) : '', $decoded));
        }

        return array_values(array_filter(
            array_map('trim', preg_split('/[\n;|,]+/u', $text) ?: []),
            static fn($item) => $item !== ''
        ));
    }

    /**
     * Дата из ввода администратора или участника: ГГГГ-ММ-ДД либо ДД.ММ.ГГГГ.
     */
    public static function parse_date(string $raw): string
    {
        $raw = trim($raw);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $raw, $m)) {
            return checkdate((int)$m[2], (int)$m[3], (int)$m[1])
                ? sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3])
                : '';
        }
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $raw, $m)) {
            return checkdate((int)$m[2], (int)$m[1], (int)$m[3])
                ? sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1])
                : '';
        }

        return '';
    }

    /**
     * Значение для людей: в CSV, письмах и карточке проверки работы.
     *
     * @param mixed $value
     */
    public static function format_value(array $def, $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map(static fn($item) => sanitize_text_field((string)$item), $value));
        }

        return $value === null ? '' : (string)$value;
    }

    /**
     * Разметка поля для анкеты на странице олимпиады.
     */
    public static function control_html(array $def, string $name_prefix = ''): string
    {
        $id = (string)($def['id'] ?? '');
        $type = (string)($def['type'] ?? 'text');
        $label = (string)($def['label'] ?? '');
        $placeholder = (string)($def['placeholder'] ?? '');
        $help = (string)($def['help'] ?? '');
        $required = !empty($def['required']);
        $name = $name_prefix !== '' ? $name_prefix . '[' . $id . ']' : $id;
        $field_id = 'wpo-cf-' . esc_attr($id);
        $star = $required ? ' <span class="wpo-required">*</span>' : '';
        $req_attr = $required ? ' required' : '';
        $max_length = (int)($def['max_length'] ?? 0);
        $length_attr = ($type === 'text' || $type === 'textarea') && $max_length > 0 ? ' maxlength="' . (int)$max_length . '"' : '';

        $control = '';
        switch ($type) {
            case 'textarea':
                $control = '<textarea id="' . $field_id . '" name="' . esc_attr($name) . '" rows="3" class="wpo-input"' . $req_attr
                    . ' placeholder="' . esc_attr($placeholder) . '"' . $length_attr . ' data-wpo-custom="' . esc_attr($id) . '"></textarea>';
                break;

            case 'number':
                $control = '<input type="number" step="any" id="' . $field_id . '" name="' . esc_attr($name) . '" class="wpo-input"' . $req_attr
                    . ' placeholder="' . esc_attr($placeholder) . '" data-wpo-custom="' . esc_attr($id) . '">';
                break;

            case 'date':
                $control = '<input type="text" inputmode="numeric" id="' . $field_id . '" name="' . esc_attr($name) . '" class="wpo-input"' . $req_attr
                    . ' placeholder="' . esc_attr($placeholder !== '' ? $placeholder : '2026-11-01') . '" data-wpo-custom="' . esc_attr($id) . '">';
                break;

            case 'select':
            case 'multiselect':
                $options = '<option value="">' . esc_html__('— выберите —', 'wp-olympiad') . '</option>';
                foreach ((array)($def['options'] ?? []) as $option) {
                    $options .= '<option value="' . esc_attr((string)$option) . '">' . esc_html((string)$option) . '</option>';
                }
                $multiple = $type === 'multiselect' ? ' multiple size="4"' : '';
                $name_out = $type === 'multiselect' ? $name . '[]' : $name;
                $control = '<select id="' . $field_id . '" name="' . esc_attr($name_out) . '" class="wpo-input"' . $req_attr . $multiple
                    . ' data-wpo-custom="' . esc_attr($id) . '">' . $options . '</select>';
                break;

            case 'checkbox':
                $control = '<label class="wpo-cf-check" for="' . $field_id . '"><input type="checkbox" id="' . $field_id . '" name="' . esc_attr($name) . '" value="1"' . $req_attr
                    . ' data-wpo-custom="' . esc_attr($id) . '"> <span>' . esc_html($placeholder !== '' ? $placeholder : $label) . '</span></label>';
                break;

            case 'text':
            default:
                $control = '<input type="text" id="' . $field_id . '" name="' . esc_attr($name) . '" class="wpo-input"' . $req_attr
                    . ' placeholder="' . esc_attr($placeholder) . '"' . $length_attr . ' data-wpo-custom="' . esc_attr($id) . '">';
                break;
        }

        $title = $type === 'checkbox' ? '' : '<label for="' . $field_id . '">' . esc_html($label) . $star . '</label>';
        $hint = $help !== '' ? '<p class="description">' . esc_html($help) . '</p>' : '';

        return '<div class="wpo-form-group wpo-cf-' . esc_attr($type) . '" data-wpo-custom-field="' . esc_attr($id) . '">'
            . $title . $control . $hint . '</div>';
    }
}
