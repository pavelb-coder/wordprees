<?php
declare(strict_types=1);
namespace WPOlympiad;
use WP_Error;
if (!defined('ABSPATH')) { exit; }

/** A style-only, site-wide library. It never reads question/participant data. */
final class StyleThemes
{
    public const OPTION = 'wpo_style_theme_library';
    public const FORMAT = 'wp-olympiad-style';
    public const LIMIT = 20;
    public const JSON_LIMIT = 131072;

    public static function init(): void { add_action('wp_ajax_wp_olympiad_style_theme', [self::class, 'ajax']); }

    public static function document(array $style, string $name = ''): array
    {
        return ['format' => self::FORMAT, 'version' => 1, 'name' => mb_substr(sanitize_text_field($name), 0, 80), 'style' => Style::normalize($style)];
    }

    public static function parse_document(string $json): array|WP_Error
    {
        if (strlen($json) > self::JSON_LIMIT) return new WP_Error('style_too_large', __('Файл оформления слишком большой (максимум 128 КБ).', 'wp-olympiad'), ['status' => 413]);
        $data = json_decode($json, true);
        if (!is_array($data) || ($data['format'] ?? '') !== self::FORMAT || ($data['version'] ?? null) !== 1 || !isset($data['style']) || !is_array($data['style'])) return new WP_Error('style_bad_format', __('Нужен JSON оформления WP Olympiad версии 1, а не файл вопросов.', 'wp-olympiad'), ['status' => 400]);
        if (isset($data['style']['custom_css']) && (!is_string($data['style']['custom_css']) || strlen($data['style']['custom_css']) > Style::CUSTOM_CSS_LIMIT)) return new WP_Error('style_bad_css', __('CSS в файле оформления недопустим или превышает 20 КБ.', 'wp-olympiad'), ['status' => 400]);
        return self::document($data['style'], is_scalar($data['name'] ?? '') ? (string)($data['name'] ?? '') : '');
    }

    public static function themes(): array
    {
        $raw = get_option(self::OPTION, '{}'); $data = is_string($raw) ? json_decode($raw, true) : [];
        return is_array($data) ? $data : [];
    }

    /** Compare-and-swap option update: simultaneous administrators cannot lose themes. */
    private static function mutate(callable $callback): array|WP_Error
    {
        global $wpdb;
        for ($try = 0; $try < 4; $try++) {
            $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", self::OPTION));
            $data = $raw === null ? [] : json_decode((string)$raw, true);
            if (!is_array($data)) return new WP_Error('style_library_corrupt', __('Не удалось прочитать библиотеку тем. Существующие данные не перезаписаны.', 'wp-olympiad'), ['status' => 409]);
            $next = $callback($data); if (is_wp_error($next)) return $next;
            $json = wp_json_encode($next, JSON_UNESCAPED_UNICODE);
            if ($raw === null) { $ok = add_option(self::OPTION, $json, '', false); }
            elseif ($json === $raw) { return $next; }
            else { $ok = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND BINARY option_value=BINARY %s", $json, self::OPTION, $raw)) === 1; }
            if ($ok) { wp_cache_delete(self::OPTION, 'options'); wp_cache_delete('notoptions', 'options'); return $next; }
        }
        return new WP_Error('style_library_busy', __('Библиотека изменяется другим администратором. Повторите сохранение.', 'wp-olympiad'), ['status' => 409]);
    }

    public static function save_theme(string $name, array $style, string $id = ''): array|WP_Error
    {
        $name = mb_substr(sanitize_text_field($name), 0, 80);
        if ($name === '') return new WP_Error('style_name_required', __('Укажите название темы.', 'wp-olympiad'), ['status' => 400]);
        $style = Style::normalize($style); $chosen = $id !== '' ? $id : 'theme_' . strtolower(wp_generate_password(16, false, false));
        $result = self::mutate(static function (array $data) use ($name, $style, $id, $chosen) {
            if ($id !== '' && !isset($data[$id])) return new WP_Error('style_not_found', __('Выбранная тема больше не существует.', 'wp-olympiad'), ['status' => 404]);
            if ($id === '' && count($data) >= self::LIMIT) return new WP_Error('style_library_full', __('В библиотеке максимум 20 тем. Удалите ненужную.', 'wp-olympiad'), ['status' => 409]);
            foreach ($data as $key => $theme) if ($key !== $id && mb_strtolower((string)$theme['name']) === mb_strtolower($name)) return new WP_Error('style_name_exists', __('Тема с таким названием уже есть. Выберите её для обновления или измените название.', 'wp-olympiad'), ['status' => 409]);
            $data[$chosen] = ['id' => $chosen, 'name' => $name, 'style' => $style, 'updated_at' => current_time('mysql')];
            return $data;
        });
        return is_wp_error($result) ? $result : ['theme' => $result[$chosen], 'themes' => array_values($result)];
    }

    public static function delete_theme(string $id): array|WP_Error
    {
        $result = self::mutate(static function (array $data) use ($id) {
            if (!isset($data[$id])) return new WP_Error('style_not_found', __('Тема уже удалена.', 'wp-olympiad'), ['status' => 404]);
            unset($data[$id]); return $data;
        });
        return is_wp_error($result) ? $result : array_values($result);
    }

    private static function fields(string $json): array|WP_Error
    {
        if (strlen($json) > self::JSON_LIMIT) return new WP_Error('style_too_large', __('Настройки оформления превышают 128 КБ.', 'wp-olympiad'), ['status' => 413]);
        $data = json_decode($json, true); if (!is_array($data)) return new WP_Error('style_bad_fields', __('Некорректные настройки оформления.', 'wp-olympiad'), ['status' => 400]);
        $safe = [];
        foreach ($data as $key => $value) if (str_starts_with((string)$key, 'style_') && (is_scalar($value) || $value === null)) $safe[$key] = $value;
        return Style::sanitize_post($safe);
    }

    public static function preview(array $style): array
    {
        $style = Style::normalize($style);
        $quiz = new Quiz(['id' => 0, 'settings' => ['style' => $style]]);
        $canvas = Style::canvas_background_declarations($style, true);
        if ($style['canvas_bg'] === '') $canvas[] = 'background-color:#f1f5f9';
        return ['style' => $style, 'css' => Style::build_css($quiz, '#wpo-style-preview'), 'body_css' => implode(';', $canvas)];
    }

    public static function ajax(): void
    {
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => __('Доступ запрещён.', 'wp-olympiad')], 403);
        check_ajax_referer('wp_olympiad_admin_nonce', 'nonce');
        $op = sanitize_key($_POST['op'] ?? '');
        if ($op === 'list') { wp_send_json_success(['themes' => array_values(self::themes())]); return; }
        if ($op === 'delete') { $result = self::delete_theme(sanitize_key($_POST['id'] ?? '')); if (!is_wp_error($result)) $result = ['themes' => $result]; }
        elseif ($op === 'import') { $result = self::parse_document(wp_unslash((string)($_POST['document'] ?? ''))); }
        elseif (in_array($op, ['preview', 'export', 'save'], true)) {
            $style = self::fields(wp_unslash((string)($_POST['fields'] ?? '')));
            if (is_wp_error($style)) $result = $style;
            elseif ($op === 'preview') $result = self::preview($style);
            elseif ($op === 'export') $result = self::document($style, wp_unslash((string)($_POST['name'] ?? '')));
            else $result = self::save_theme(wp_unslash((string)($_POST['name'] ?? '')), $style, sanitize_key($_POST['id'] ?? ''));
        } else $result = new WP_Error('style_bad_action', __('Неизвестное действие оформления.', 'wp-olympiad'), ['status' => 400]);
        if (is_wp_error($result)) { wp_send_json_error(['message' => $result->get_error_message()], (int)($result->get_error_data()['status'] ?? 400)); return; }
        wp_send_json_success($result);
    }
}
