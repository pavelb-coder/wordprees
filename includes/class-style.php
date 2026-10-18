<?php
declare(strict_types=1);

namespace WPOlympiad;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Style / Appearance engine for the public testing page.
 *
 * Stores a per-quiz design preset (colors, typography, layout, buttons,
 * custom CSS) and renders a scoped <style> block that only affects
 * the test page of that particular quiz.
 */
final class Style
{
    /** Maximum length of the custom CSS field (bytes). */
    public const CUSTOM_CSS_LIMIT = 20000;

    /**
     * Bundled design presets. Each preset holds the full set of style fields.
     *
     * @return array<string, array<string, string>>
     */
    public static function presets(): array
    {
        return [
            'classic' => [
                'label'        => '🔵 Классический синий',
                'primary'      => '#2563eb',
                'primary_hover'=> '#1d4ed8',
                'accent'       => '#64748b',
                'success'      => '#10b981',
                'danger'       => '#ef4444',
                'warning'      => '#f59e0b',
                'bg'           => '#ffffff',
                'surface'      => '#f8fafc',
                'border'       => '#e2e8f0',
                'text'         => '#0f172a',
                'text_muted'   => '#64748b',
                'canvas_bg'    => '',
            ],
            'emerald' => [
                'label'        => '🟢 Изумрудный',
                'primary'      => '#059669',
                'primary_hover'=> '#047857',
                'accent'       => '#0f766e',
                'success'      => '#22c55e',
                'danger'       => '#e11d48',
                'warning'      => '#f59e0b',
                'bg'           => '#ffffff',
                'surface'      => '#f0fdf4',
                'border'       => '#d1fae5',
                'text'         => '#052e16',
                'text_muted'   => '#4b7a63',
                'canvas_bg'    => '',
            ],
            'violet' => [
                'label'        => '🟣 Фиолетовый',
                'primary'      => '#7c3aed',
                'primary_hover'=> '#6d28d9',
                'accent'       => '#a855f7',
                'success'      => '#10b981',
                'danger'       => '#f43f5e',
                'warning'      => '#f59e0b',
                'bg'           => '#ffffff',
                'surface'      => '#faf5ff',
                'border'       => '#ede9fe',
                'text'         => '#2e1065',
                'text_muted'   => '#6d5d8a',
                'canvas_bg'    => '',
            ],
            'crimson' => [
                'label'        => '🔴 Алый',
                'primary'      => '#dc2626',
                'primary_hover'=> '#b91c1c',
                'accent'       => '#f97316',
                'success'      => '#16a34a',
                'danger'       => '#b91c1c',
                'warning'      => '#f59e0b',
                'bg'           => '#ffffff',
                'surface'      => '#fef2f2',
                'border'       => '#fee2e2',
                'text'         => '#450a0a',
                'text_muted'   => '#7f5151',
                'canvas_bg'    => '',
            ],
            'amber' => [
                'label'        => '🟡 Золотой (олимпийский)',
                'primary'      => '#b45309',
                'primary_hover'=> '#92400e',
                'accent'       => '#d97706',
                'success'      => '#15803d',
                'danger'       => '#dc2626',
                'warning'      => '#eab308',
                'bg'           => '#ffffff',
                'surface'      => '#fffbeb',
                'border'       => '#fde68a',
                'text'         => '#451a03',
                'text_muted'   => '#7c5a2b',
                'canvas_bg'    => '',
            ],
            'teal' => [
                'label'        => '🌊 Бирюзовый',
                'primary'      => '#0d9488',
                'primary_hover'=> '#0f766e',
                'accent'       => '#0891b2',
                'success'      => '#22c55e',
                'danger'       => '#ef4444',
                'warning'      => '#f59e0b',
                'bg'           => '#ffffff',
                'surface'      => '#f0fdfa',
                'border'       => '#ccfbf1',
                'text'         => '#042f2e',
                'text_muted'   => '#4b7472',
                'canvas_bg'    => '',
            ],
            'paper' => [
                'label'        => '📜 Печатный (серифный)',
                'primary'      => '#b45309',
                'primary_hover'=> '#92400e',
                'accent'       => '#78350f',
                'success'      => '#15803d',
                'danger'       => '#b91c1c',
                'warning'      => '#a16207',
                'bg'           => '#fffdf7',
                'surface'      => '#f7f1e3',
                'border'       => '#e7dcc4',
                'text'         => '#1c1917',
                'text_muted'   => '#78716c',
                'canvas_bg'    => '#f5efe2',
            ],
            'dark' => [
                'label'        => '🌙 Тёмная тема',
                'primary'      => '#38bdf8',
                'primary_hover'=> '#0ea5e9',
                'accent'       => '#818cf8',
                'success'      => '#34d399',
                'danger'       => '#f87171',
                'warning'      => '#fbbf24',
                'bg'           => '#1e293b',
                'surface'      => '#0f172a',
                'border'       => '#334155',
                'text'         => '#e2e8f0',
                'text_muted'   => '#94a3b8',
                'canvas_bg'    => '#0b1120',
            ],
            'minimal' => [
                'label'        => '⚪ Минимализм (ч/б)',
                'primary'      => '#111827',
                'primary_hover'=> '#000000',
                'accent'       => '#6b7280',
                'success'      => '#15803d',
                'danger'       => '#b91c1c',
                'warning'      => '#a16207',
                'bg'           => '#ffffff',
                'surface'      => '#f9fafb',
                'border'       => '#d1d5db',
                'text'         => '#111827',
                'text_muted'   => '#6b7280',
                'canvas_bg'    => '',
            ],
            'school' => [
                'label'        => '🏫 Школьный',
                'primary'      => '#1d4ed8',
                'primary_hover'=> '#1e40af',
                'accent'       => '#0e7490',
                'success'      => '#16a34a',
                'danger'       => '#dc2626',
                'warning'      => '#ea580c',
                'bg'           => '#ffffff',
                'surface'      => '#eff6ff',
                'border'       => '#bfdbfe',
                'text'         => '#172554',
                'text_muted'   => '#4a5f8a',
                'canvas_bg'    => '#eef4ff',
            ],
        ];
    }

    /**
     * Available font stacks (key => [label, css stack]).
     *
     * @return array<string, array{label: string, stack: string}>
     */
    public static function fonts(): array
    {
        return [
            'theme'    => ['label' => 'Как в теме сайта (наследовать)', 'stack' => 'inherit'],
            'system'   => ['label' => 'Системный (по умолчанию)', 'stack' => '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif'],
            'inter'    => ['label' => 'Inter / Современный гротеск', 'stack' => '"Inter", "Segoe UI", Roboto, Arial, sans-serif'],
            'rubik'    => ['label' => 'Rubik / Округлый гротеск', 'stack' => '"Rubik", "Segoe UI", Roboto, Arial, sans-serif'],
            'pt'       => ['label' => 'PT Sans / Российский гротеск', 'stack' => '"PT Sans", "Segoe UI", Roboto, Arial, sans-serif'],
            'georgia'  => ['label' => 'Georgia / Книжный сериф', 'stack' => 'Georgia, "Times New Roman", serif'],
            'ptserif'  => ['label' => 'PT Serif / Академический', 'stack' => '"PT Serif", Georgia, "Times New Roman", serif'],
            'verdana'  => ['label' => 'Verdana / Широкий', 'stack' => 'Verdana, Geneva, Tahoma, sans-serif'],
            'trebuchet'=> ['label' => 'Trebuchet MS / Дружелюбный', 'stack' => '"Trebuchet MS", "Segoe UI", Tahoma, sans-serif'],
            'mono'     => ['label' => 'Моноширинный / Технический', 'stack' => 'ui-monospace, SFMono-Regular, Menlo, Consolas, "Courier New", monospace'],
        ];
    }

    /**
     * Shadow presets.
     *
     * @return array<string, array{label: string, value: string}>
     */
    public static function shadows(): array
    {
        return [
            'none'   => ['label' => 'Без тени (плоский дизайн)', 'value' => 'none'],
            'soft'   => ['label' => 'Мягкая', 'value' => '0 2px 6px rgba(15, 23, 42, 0.06)'],
            'medium' => ['label' => 'Средняя (по умолчанию)', 'value' => '0 4px 6px -1px rgba(0, 0, 0, 0.07), 0 2px 4px -2px rgba(0, 0, 0, 0.05)'],
            'strong' => ['label' => 'Выразительная', 'value' => '0 18px 40px -12px rgba(15, 23, 42, 0.28), 0 4px 10px -4px rgba(15, 23, 42, 0.12)'],
        ];
    }

    /**
     * Button styles.
     *
     * @return array<string, string>
     */
    public static function button_styles(): array
    {
        return [
            'solid'    => 'Заливка (по умолчанию)',
            'gradient' => 'Градиент',
            'soft'     => 'Мягкая тонировка',
            'outline'  => 'Контурная',
        ];
    }

    /**
     * How the page background image is fitted into the canvas.
     *
     * @return array<string, string>
     */
    public static function canvas_fits(): array
    {
        return [
            'cover'   => 'Заполнить область (лишнее обрезается)',
            'contain' => 'Вместить картинку целиком',
            'repeat'  => 'Повторять (плитка)',
            'fill'    => 'Растянуть по размеру страницы',
        ];
    }

    /**
     * Anchor of the page background image.
     *
     * @return array<string, string>
     */
    public static function canvas_positions(): array
    {
        return [
            'center' => 'По центру',
            'top'    => 'Сверху',
            'bottom' => 'Снизу',
            'left'   => 'Слева',
            'right'  => 'Справа',
        ];
    }

    /**
     * Default style settings (preset "classic" + default layout).
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        $preset = self::presets()['classic'];

        return array_merge($preset, [
            'preset'        => 'classic',
            'font'          => 'system',
            'font_size'     => 16,
            'title_size'    => 26,
            'max_width'     => 860,
            'radius'        => 12,
            'card_padding'  => 32,
            'shadow'        => 'medium',
            'button_style'  => 'solid',
            'button_radius' => 8,
            'line_height'   => 1.5,
            'custom_css'    => '',
            // Самый дальний план страницы тестирования: картинка и настройки её вывода
            'canvas_image'          => '',
            'canvas_image_fit'      => 'cover',
            'canvas_image_position' => 'center',
            'canvas_overlay'        => 0,
            'canvas_full_width'     => 0,
            'canvas_gradient' => 'none',
            'canvas_gradient_from' => '#2563eb',
            'canvas_gradient_to' => '#7c3aed',
            'canvas_gradient_angle' => 135,
            'card_opacity' => 100,
            'card_blur' => 0,
        ]);
    }

    /**
     * Resolve stored style settings for a quiz (defaults + saved values).
     *
     * @return array<string, mixed>
     */
    public static function get(Quiz $quiz): array
    {
        $stored = $quiz->get_setting('style', []);
        if (!is_array($stored)) {
            $stored = [];
        }

        return self::normalize($stored);
    }

    /**
     * Normalize any (possibly partial / legacy) style array against defaults.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function normalize(array $data): array
    {
        $defaults = self::defaults();
        $out = $defaults;

        foreach ($defaults as $key => $default) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[$key];
            if (!is_scalar($value) && $value !== null) continue;

            switch ($key) {
                case 'preset':
                    // 'custom' is a valid state (manual palette that does not match any preset).
                    $out[$key] = ($value === 'custom' || isset(self::presets()[$value])) ? (string)$value : 'classic';
                    break;
                case 'font':
                    $out[$key] = isset(self::fonts()[$value]) ? (string)$value : 'system';
                    break;
                case 'shadow':
                    $out[$key] = isset(self::shadows()[$value]) ? (string)$value : 'medium';
                    break;
                case 'button_style':
                    $out[$key] = isset(self::button_styles()[$value]) ? (string)$value : 'solid';
                    break;
                case 'font_size':
                    $out[$key] = self::clamp_int($value, 12, 24, 16);
                    break;
                case 'title_size':
                    $out[$key] = self::clamp_int($value, 18, 52, 26);
                    break;
                case 'max_width':
                    $out[$key] = self::clamp_int($value, 480, 1600, 860);
                    break;
                case 'radius':
                    $out[$key] = self::clamp_int($value, 0, 40, 12);
                    break;
                case 'card_padding':
                    $out[$key] = self::clamp_int($value, 8, 64, 32);
                    break;
                case 'button_radius':
                    $out[$key] = self::clamp_int($value, 0, 40, 8);
                    break;
                case 'line_height':
                    $out[$key] = self::clamp_float($value, 1.1, 2.2, 1.5);
                    break;
                case 'custom_css':
                    $out[$key] = self::sanitize_css((string)$value);
                    break;
                case 'canvas_bg':
                    $out[$key] = self::is_empty_color($value) ? '' : self::hex((string)$value, '');
                    break;
                case 'canvas_image':
                    $out[$key] = self::sanitize_image_url((string)$value);
                    break;
                case 'canvas_image_fit':
                    $out[$key] = isset(self::canvas_fits()[(string)$value]) ? (string)$value : 'cover';
                    break;
                case 'canvas_image_position':
                    $out[$key] = isset(self::canvas_positions()[(string)$value]) ? (string)$value : 'center';
                    break;
                case 'canvas_overlay':
                    $out[$key] = self::clamp_int($value, 0, 90, 0);
                    break;
                case 'canvas_gradient':
                    $out[$key] = in_array($value, ['none', 'linear', 'radial'], true) ? $value : 'none';
                    break;
                case 'canvas_gradient_from': case 'canvas_gradient_to':
                    $out[$key] = self::hex((string)$value, $defaults[$key]);
                    break;
                case 'canvas_gradient_angle':
                    $out[$key] = self::clamp_int($value, 0, 360, 135);
                    break;
                case 'card_opacity':
                    $out[$key] = self::clamp_int($value, 40, 100, 100);
                    break;
                case 'card_blur':
                    $out[$key] = self::clamp_int($value, 0, 24, 0);
                    break;
                case 'canvas_full_width':
                    $out[$key] = !empty($value) ? 1 : 0;
                    break;
                case 'label':
                    $out[$key] = mb_substr(sanitize_text_field((string)$value), 0, 80);
                    break;
                default:
                    $out[$key] = self::hex((string)$value, (string)$default);
            }
        }

        return $out;
    }

    /**
     * Sanitize the style fields submitted from the quiz settings form.
     *
     * @param array<string, mixed> $post Raw $_POST array.
     * @return array<string, mixed>
     */
    public static function sanitize_post(array $post, array $existing = []): array
    {
        $defaults = self::defaults();
        $out = ['preset' => 'custom'];

        $preset_key = (string)($post['style_preset'] ?? 'custom');
        if (isset(self::presets()[$preset_key])) {
            $out['preset'] = $preset_key;
        }

        foreach (['primary', 'primary_hover', 'accent', 'success', 'danger', 'warning', 'bg', 'surface', 'border', 'text', 'text_muted'] as $color) {
            $out[$color] = self::hex((string)($post['style_' . $color] ?? ''), (string)$defaults[$color]);
        }

        // Самый дальний план страницы: цвет и/или картинка позади карточки теста.
        $canvas_enabled = !empty($post['style_canvas_bg_enabled']);
        $out['canvas_bg'] = (!$canvas_enabled || self::is_empty_color($post['style_canvas_bg'] ?? ''))
            ? ''
            : self::hex((string)$post['style_canvas_bg'], '');

        $out['canvas_image'] = $canvas_enabled
            ? self::sanitize_image_url((string)($post['style_canvas_image'] ?? ''))
            : '';
        $out['canvas_image_fit'] = isset(self::canvas_fits()[(string)($post['style_canvas_image_fit'] ?? '')])
            ? (string)$post['style_canvas_image_fit']
            : 'cover';
        $out['canvas_image_position'] = isset(self::canvas_positions()[(string)($post['style_canvas_image_position'] ?? '')])
            ? (string)$post['style_canvas_image_position']
            : 'center';
        $out['canvas_overlay'] = self::clamp_int($post['style_canvas_overlay'] ?? 0, 0, 90, 0);
        $out['canvas_full_width'] = !empty($post['style_canvas_full_width']) ? 1 : 0;

        $out['font']          = isset(self::fonts()[(string)($post['style_font'] ?? '')]) ? (string)$post['style_font'] : 'system';
        $out['shadow']        = isset(self::shadows()[(string)($post['style_shadow'] ?? '')]) ? (string)$post['style_shadow'] : 'medium';
        $out['button_style']  = isset(self::button_styles()[(string)($post['style_button_style'] ?? '')]) ? (string)$post['style_button_style'] : 'solid';

        $out['font_size']     = self::clamp_int($post['style_font_size'] ?? 16, 12, 24, 16);
        $out['title_size']    = self::clamp_int($post['style_title_size'] ?? 26, 18, 52, 26);
        $out['max_width']     = self::clamp_int($post['style_max_width'] ?? 860, 480, 1600, 860);
        $out['radius']        = self::clamp_int($post['style_radius'] ?? 12, 0, 40, 12);
        $out['card_padding']  = self::clamp_int($post['style_card_padding'] ?? 32, 8, 64, 32);
        $out['button_radius'] = self::clamp_int($post['style_button_radius'] ?? 8, 0, 40, 8);
        $out['line_height']   = self::clamp_float($post['style_line_height'] ?? 1.5, 1.1, 2.2, 1.5);

        // Missing new controls in an older cached form preserve already saved effects.
        $new = [];
        foreach (['canvas_gradient', 'canvas_gradient_from', 'canvas_gradient_to', 'canvas_gradient_angle', 'card_opacity', 'card_blur'] as $key) {
            $new[$key] = $post['style_' . $key] ?? $existing[$key] ?? $defaults[$key];
        }
        $normalized = self::normalize($new);
        foreach ($new as $key => $_value) $out[$key] = $normalized[$key];
        if (!$canvas_enabled) $out['canvas_gradient'] = 'none';

        $out['custom_css'] = self::sanitize_css((string)($post['style_custom_css'] ?? ''));

        return $out;
    }

    /**
     * CSS declarations for the page canvas (farthest plane): color + optional picture.
     *
     * @param array<string, mixed> $style Normalized style settings.
     * @param bool $fixed Pin the picture to the viewport (used for the fullscreen runner page).
     * @return array<int, string>
     */
    public static function canvas_background_declarations(array $style, bool $fixed = false): array
    {
        $decl = [];

        $color = (string)($style['canvas_bg'] ?? '');
        $image = (string)($style['canvas_image'] ?? '');

        if ($color !== '') {
            $decl[] = 'background-color:' . $color;
        }

        $gradient = self::canvas_gradient_css($style);
        if ($gradient !== '') {
            $layers = []; $sizes = []; $repeats = []; $positions = [];
            $overlay = max(0, min(90, (int)($style['canvas_overlay'] ?? 0)));
            if ($overlay > 0) {
                $a = $overlay / 100;
                $layers[] = 'linear-gradient(rgba(0,0,0,' . $a . '),rgba(0,0,0,' . $a . '))';
                $sizes[] = '100% 100%'; $repeats[] = 'no-repeat'; $positions[] = 'center center';
            }
            if ($image !== '') {
                $layers[] = 'url("' . $image . '")';
                $fit = (string)($style['canvas_image_fit'] ?? 'cover');
                $sizes[] = ['contain' => 'contain', 'repeat' => 'auto', 'fill' => '100% 100%'][$fit] ?? 'cover';
                $repeats[] = $fit === 'repeat' ? 'repeat' : 'no-repeat';
                $positions[] = ['top'=>'center top','bottom'=>'center bottom','left'=>'left center','right'=>'right center'][$style['canvas_image_position'] ?? 'center'] ?? 'center center';
            }
            $layers[] = $gradient; $sizes[] = '100% 100%'; $repeats[] = 'no-repeat'; $positions[] = 'center center';
            $decl[] = 'background-image:' . implode(',', $layers);
            $decl[] = 'background-size:' . implode(',', $sizes);
            $decl[] = 'background-repeat:' . implode(',', $repeats);
            $decl[] = 'background-position:' . implode(',', $positions);
            if ($fixed) $decl[] = 'background-attachment:fixed';
            return $decl;
        }
        if ($image === '') {
            return $decl;
        }

        $overlay = max(0, min(90, (int)($style['canvas_overlay'] ?? 0)));
        $layers = [];
        if ($overlay > 0) {
            $alpha = rtrim(rtrim(number_format($overlay / 100, 2, '.', ''), '0'), '.');
            $layers[] = 'linear-gradient(rgba(0,0,0,' . $alpha . '),rgba(0,0,0,' . $alpha . '))';
        }
        $layers[] = 'url("' . $image . '")';
        $decl[] = 'background-image:' . implode(',', $layers);

        switch ((string)($style['canvas_image_fit'] ?? 'cover')) {
            case 'contain':
                $decl[] = 'background-size:contain';
                $decl[] = 'background-repeat:no-repeat';
                break;
            case 'repeat':
                $decl[] = 'background-size:auto';
                $decl[] = 'background-repeat:repeat';
                break;
            case 'fill':
                $decl[] = 'background-size:100% 100%';
                $decl[] = 'background-repeat:no-repeat';
                break;
            case 'cover':
            default:
                $decl[] = 'background-size:cover';
                $decl[] = 'background-repeat:no-repeat';
                break;
        }

        $map = [
            'top'    => 'center top',
            'bottom' => 'center bottom',
            'left'   => 'left center',
            'right'  => 'right center',
            'center' => 'center center',
        ];
        $decl[] = 'background-position:' . ($map[(string)($style['canvas_image_position'] ?? 'center')] ?? 'center center');

        if ($fixed) {
            $decl[] = 'background-attachment:fixed';
        }

        return $decl;
    }

    public static function canvas_gradient_css(array $style): string
    {
        $type = $style['canvas_gradient'] ?? 'none';
        if (!in_array($type, ['linear', 'radial'], true)) return '';
        $from = self::hex((string)($style['canvas_gradient_from'] ?? ''), '#2563eb');
        $to = self::hex((string)($style['canvas_gradient_to'] ?? ''), '#7c3aed');
        return $type === 'radial' ? 'radial-gradient(ellipse at center,' . $from . ',' . $to . ')'
            : 'linear-gradient(' . self::clamp_int($style['canvas_gradient_angle'] ?? 135, 0, 360, 135) . 'deg,' . $from . ',' . $to . ')';
    }

    /**
     * Build the scoped stylesheet for one quiz.
     */
    public static function build_css(Quiz $quiz, string $scope = ''): string
    {
        $s = self::get($quiz);
        $scope = $scope !== '' ? $scope : '#wpo-quiz-' . (int)$quiz->id;
        $scope = rtrim($scope, ' ');
        $root = $scope . '.wpo-quiz-wrapper';

        $font_stack = self::fonts()[$s['font']]['stack'] ?? 'inherit';
        $shadow = self::shadows()[$s['shadow']]['value'] ?? 'none';

        $primary = (string)$s['primary'];
        $bg = (string)$s['bg'];

        $vars = [
            '--wpo-primary'      => $primary,
            '--wpo-primary-hover'=> (string)$s['primary_hover'],
            '--wpo-secondary'    => (string)$s['accent'],
            '--wpo-success'      => (string)$s['success'],
            '--wpo-danger'       => (string)$s['danger'],
            '--wpo-warning'      => (string)$s['warning'],
            '--wpo-bg'           => $bg,
            '--wpo-card-bg' => (int)$s['card_opacity'] === 100 ? $bg : self::rgba($bg, (int)$s['card_opacity'] / 100),
            '--wpo-surface'      => (string)$s['surface'],
            '--wpo-border'       => (string)$s['border'],
            '--wpo-text'         => (string)$s['text'],
            '--wpo-text-muted'   => (string)$s['text_muted'],
            '--wpo-radius'       => (int)$s['radius'] . 'px',
            '--wpo-shadow'       => $shadow,
            '--wpo-font-family'  => $font_stack,
            '--wpo-font-size'    => (int)$s['font_size'] . 'px',
            '--wpo-title-size'   => (int)$s['title_size'] . 'px',
            '--wpo-max-width'    => (int)$s['max_width'] . 'px',
            '--wpo-card-padding' => (int)$s['card_padding'] . 'px',
            '--wpo-line-height'  => (string)$s['line_height'],
            '--wpo-btn-radius'   => (int)$s['button_radius'] . 'px',
            '--wpo-primary-soft' => self::rgba($primary, 0.12),
            '--wpo-primary-ring' => self::rgba($primary, 0.25),
            // Derived tinted palette (follows the chosen primary color and works on dark themes).
            '--wpo-primary-50'   => self::mix($bg, $primary, 0.10),
            '--wpo-primary-100'  => self::mix($bg, $primary, 0.18),
            '--wpo-primary-200'  => self::mix($bg, $primary, 0.32),
            '--wpo-primary-300'  => self::mix($bg, $primary, 0.48),
            '--wpo-primary-700'  => self::mix($primary, (string)$s['text'], 0.35),
            '--wpo-primary-800'  => self::mix($primary, (string)$s['text'], 0.50),
            // Status tints derived from the working surface color (dark-theme safe).
            '--wpo-success-bg'     => self::mix($bg, (string)$s['success'], 0.12),
            '--wpo-success-border' => self::mix($bg, (string)$s['success'], 0.32),
            '--wpo-success-text'   => self::mix((string)$s['text'], (string)$s['success'], 0.6),
            '--wpo-danger-bg'      => self::mix($bg, (string)$s['danger'], 0.12),
            '--wpo-danger-border'  => self::mix($bg, (string)$s['danger'], 0.32),
            '--wpo-danger-text'    => self::mix((string)$s['text'], (string)$s['danger'], 0.6),
            '--wpo-warning-bg'     => self::mix($bg, (string)$s['warning'], 0.16),
            '--wpo-warning-border' => self::mix($bg, (string)$s['warning'], 0.38),
            '--wpo-warning-text'   => self::mix((string)$s['text'], (string)$s['warning'], 0.55),
        ];

        $css = $root . '{';
        foreach ($vars as $name => $value) {
            $css .= $name . ':' . $value . ';';
        }
        $css .= '}';

        $css .= $scope . ' .wpo-card{background:var(--wpo-card-bg,var(--wpo-bg));backdrop-filter:' . ((int)$s['card_blur'] > 0 ? 'blur(' . (int)$s['card_blur'] . 'px)' : 'none') . ';-webkit-backdrop-filter:' . ((int)$s['card_blur'] > 0 ? 'blur(' . (int)$s['card_blur'] . 'px)' : 'none') . ';}';

        // Canvas (page) background behind the card: color and/or an image on the farthest plane.
        $canvas_color = (string)$s['canvas_bg'];
        $canvas_image = (string)$s['canvas_image'];
        if ($canvas_color !== '' || $canvas_image !== '' || $s['canvas_gradient'] !== 'none') {
            $decl = self::canvas_background_declarations($s);

            if (!empty($s['canvas_full_width'])) {
                // Фон «от края до края» экрана; карточка остаётся по центру и не растягивается.
                $decl[] = 'width:100vw';
                $decl[] = 'max-width:100vw';
                $decl[] = 'margin-left:calc(50% - 50vw)';
                $decl[] = 'margin-right:calc(50% - 50vw)';
                $decl[] = 'border-radius:0';
                $decl[] = 'padding:32px max(16px, calc((100vw - var(--wpo-max-width, 860px)) / 2))';
            } else {
                $decl[] = 'border-radius:calc(var(--wpo-radius, 12px) + 10px)';
                $decl[] = 'padding:24px 18px';
            }

            $css .= $scope . '{' . implode(';', $decl) . ';}';
            $css .= '@media (max-width: 600px){' . $scope . '{padding:16px 10px;border-radius:0;}}';
        }

        // Buttons.
        $css .= $scope . ' .wpo-btn{border-radius:var(--wpo-btn-radius,10px);}';
        switch ((string)$s['button_style']) {
            case 'gradient':
                $css .= $scope . ' .wpo-btn-primary{background:linear-gradient(135deg,var(--wpo-primary, #2563eb),var(--wpo-primary-hover, #1d4ed8));border-color:transparent;box-shadow:0 6px 16px -6px var(--wpo-primary-ring, rgba(37,99,235,.45));}';
                $css .= $scope . ' .wpo-btn-primary:hover{filter:brightness(1.07);}';
                break;
            case 'soft':
                $css .= $scope . ' .wpo-btn-primary{background:var(--wpo-primary-soft, rgba(37,99,235,.12));color:var(--wpo-primary, #2563eb);border:1px solid var(--wpo-primary-ring, rgba(37,99,235,.25));}';
                $css .= $scope . ' .wpo-btn-primary:hover{background:var(--wpo-primary, #2563eb);color:#fff;border-color:transparent;}';
                break;
            case 'outline':
                $css .= $scope . ' .wpo-btn-primary{background:transparent;color:var(--wpo-primary, #2563eb);border:2px solid var(--wpo-primary, #2563eb);}';
                $css .= $scope . ' .wpo-btn-primary:hover{background:var(--wpo-primary, #2563eb);color:#fff;}';
                break;
            case 'solid':
            default:
                break;
        }

        // Custom CSS (scoped to this quiz only).
        if (trim((string)$s['custom_css']) !== '') {
            $css .= self::scope_css((string)$s['custom_css'], $scope);
        }

        return $css;
    }

    /**
     * Render a ready-to-print <style> tag with the quiz design.
     */
    public static function render(Quiz $quiz): string
    {
        $css = self::build_css($quiz);
        if (trim($css) === '') {
            return '';
        }
        return '<style id="wpo-style-' . (int)$quiz->id . '">' . $css . '</style>';
    }

    /**
     * Prefix every selector of a CSS string with the given scope.
     * Supports nested @media / @supports blocks; @font-face and @keyframes are kept global.
     */
    public static function scope_css(string $css, string $scope): string
    {
        $css = trim($css);
        if ($css === '') {
            return '';
        }

        return self::scope_block($css, $scope . ' ');
    }

    private static function scope_block(string $css, string $prefix): string
    {
        $out = '';
        $length = strlen($css);
        $i = 0;

        while ($i < $length) {
            // Whitespace / comments outside of rule sets.
            if (preg_match('/\G(?:\s+|\/\*.*?\*\/)+/s', $css, $m, 0, $i)) {
                $out .= $m[0];
                $i += strlen($m[0]);
                continue;
            }

            $brace = strpos($css, '{', $i);
            if ($brace === false) {
                $out .= substr($css, $i);
                break;
            }

            $prelude = trim(substr($css, $i, $brace - $i));
            $close = self::find_matching_brace($css, $brace);

            if ($close === null) {
                // Unbalanced CSS — append the rest as-is to avoid data loss.
                break;
            }

            $body = substr($css, $brace + 1, $close - $brace - 1);
            $block = substr($css, $i, $close - $i + 1);

            $lower = strtolower($prelude);

            if (str_starts_with($lower, '@media') || str_starts_with($lower, '@supports') || str_starts_with($lower, '@container') || str_starts_with($lower, '@layer')) {
                $out .= $prelude . '{' . self::scope_block($body, $prefix) . '}';
            } elseif (str_starts_with($lower, '@')) {
                // @font-face, @keyframes, @page ... keep global.
                $out .= $block;
            } else {
                $selectors = self::prefix_selectors($prelude, $prefix);
                $out .= $selectors . '{' . $body . '}';
            }

            $i = $close + 1;
        }

        return $out;
    }

    private static function prefix_selectors(string $prelude, string $prefix): string
    {
        $parts = explode(',', $prelude);
        $scoped = [];

        foreach ($parts as $selector) {
            $selector = trim($selector);
            if ($selector === '') {
                continue;
            }
            if (preg_match('/^(html|body|:root)$/i', $selector)) {
                $scoped[] = rtrim($prefix);
                continue;
            }
            $selector = preg_replace('/#wpo-quiz-[0-9]+\b/', rtrim($prefix), $selector);
            $own = rtrim($prefix);
            if ($selector === $own) { $scoped[] = $selector; continue; }
            if (str_starts_with($selector, $own . ' ') || str_starts_with($selector, $own . '>')) {
                $tail = ltrim(substr($selector, strlen($own)));
                // A root-adjacent sibling selector would escape the quiz.
                if ($tail !== '' && ($tail[0] === '+' || $tail[0] === '~')) continue;
                $scoped[] = $selector;
                continue;
            }
            $scoped[] = rtrim($prefix) . ' ' . $selector;
        }

        return implode(',', $scoped);
    }

    private static function find_matching_brace(string $css, int $open): ?int
    {
        $depth = 0;
        $length = strlen($css);
        $in_string = false;
        $quote = '';

        for ($i = $open; $i < $length; $i++) {
            $char = $css[$i];

            if ($in_string) {
                if ($char === '\\') {
                    $i++;
                    continue;
                }
                if ($char === $quote) {
                    $in_string = false;
                }
                continue;
            }

            if ($char === '"' || $char === "'") {
                $in_string = true;
                $quote = $char;
                continue;
            }

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * Sanitize a background image URL (http/https, protocol-relative and relative paths).
     * Anything dangerous (javascript:, data:, vbscript:) is dropped to an empty string.
     */
    public static function sanitize_image_url(string $url): string
    {
        $url = trim(wp_unslash($url));
        if ($url === '') {
            return '';
        }

        // CSS-инъекции и опасные схемы не допускаем.
        if (preg_match('/[<>"\'\\()\s]/u', $url) || preg_match('#^(javascript|vbscript|data|file):#i', $url)) {
            return '';
        }

        if (wp_parse_url($url, PHP_URL_USER) !== null || wp_parse_url($url, PHP_URL_PASS) !== null) return '';

        if (str_starts_with($url, '//') || str_starts_with($url, '/') || str_starts_with($url, './') || str_starts_with($url, '../')) {
            return esc_url_raw($url);
        }

        $clean = esc_url_raw($url, ['http', 'https']);
        if ($clean === '' || !preg_match('#^https?://#i', $clean)) {
            return '';
        }

        if (wp_parse_url($clean, PHP_URL_USER) !== null || wp_parse_url($clean, PHP_URL_PASS) !== null) return '';
        return $clean;
    }

    /**
     * Validate a hex color. Supports #rgb, #rrggbb. Empty "none" values
     * are allowed when the fallback is an empty string.
     */
    public static function hex(string $value, string $fallback = '#000000'): string
    {
        $value = trim($value);
        if ($value === '') {
            return $fallback;
        }
        if ($value[0] !== '#') {
            $value = '#' . $value;
        }
        if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value) !== 1) {
            return $fallback;
        }
        return strtolower($value);
    }

    private static function is_empty_color(mixed $value): bool
    {
        if (!is_string($value)) {
            return true;
        }
        $value = trim($value);
        return $value === '' || $value === 'none' || $value === 'transparent';
    }

    /**
     * Convert a hex color to an rgb()/rgba() string.
     */
    public static function rgba(string $hex, float $alpha = 1.0): string
    {
        $hex = ltrim(self::hex($hex, '#000000'), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $alpha = max(0.0, min(1.0, $alpha));

        return sprintf('rgba(%d, %d, %d, %s)', (int)$r, (int)$g, (int)$b, rtrim(rtrim(number_format($alpha, 3, '.', ''), '0'), '.'));
    }

    /**
     * Mix two hex colors: result = base * (1 - weight) + tint * weight.
     */
    public static function mix(string $base, string $tint, float $weight): string
    {
        $weight = max(0.0, min(1.0, $weight));

        $b = self::hex_to_rgb($base);
        $t = self::hex_to_rgb($tint);

        $r = (int)round($b[0] * (1 - $weight) + $t[0] * $weight);
        $g = (int)round($b[1] * (1 - $weight) + $t[1] * $weight);
        $bl = (int)round($b[2] * (1 - $weight) + $t[2] * $weight);

        return sprintf('#%02x%02x%02x', max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $bl)));
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function hex_to_rgb(string $hex): array
    {
        $hex = ltrim(self::hex($hex, '#000000'), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return [
            (int)hexdec(substr($hex, 0, 2)),
            (int)hexdec(substr($hex, 2, 2)),
            (int)hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * Whitelist-safe CSS text: prevents closing the enclosing <style> tag,
     * strips PHP tags and limits the length.
     */
    public static function sanitize_css(string $css): string
    {
        $css = wp_check_invalid_utf8($css);
        $css = str_ireplace(['</style', '<style', '<?php', '<?=', '?>', '<script', '</script>'], '', $css);
        $css = preg_replace('/@import\s+[^;]+;?/i', '', $css);
        $css = preg_replace('/(?:expression\s*\(|javascript\s*:|vbscript\s*:|-moz-binding\s*:|behavior\s*:)/i', '', $css);
        $css = trim($css);

        if (strlen($css) > self::CUSTOM_CSS_LIMIT) {
            $css = substr($css, 0, self::CUSTOM_CSS_LIMIT);
        }

        return $css;
    }

    private static function clamp_int(mixed $value, int $min, int $max, int $fallback): int
    {
        if (!is_numeric($value)) {
            return $fallback;
        }
        return max($min, min($max, (int)$value));
    }

    private static function clamp_float(mixed $value, float $min, float $max, float $fallback): float
    {
        if (!is_numeric($value)) {
            return $fallback;
        }
        $value = (float)$value;
        $value = max($min, min($max, $value));

        return round($value, 2);
    }
}
