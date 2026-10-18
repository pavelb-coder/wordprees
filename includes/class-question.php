<?php
declare(strict_types=1);

namespace WPOlympiad;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Question Model & Data Access
 */
final class Question
{
    private static array $runtime_cache = [];
    private static array $quiz_questions_cache = [];

    public int $id = 0;
    public int $quiz_id = 0;
    public string $question_type = 'single';
    public string $title = '';
    public string $content = '';
    public int $points = 1;
    public int $penalty = 0;
    public array $options = [];
    public int $sort_order = 0;

    public function __construct(array $data = [])
    {
        if (!empty($data)) {
            $this->id = (int)($data['id'] ?? 0);
            $this->quiz_id = (int)($data['quiz_id'] ?? 0);
            $this->question_type = (string)($data['question_type'] ?? 'single');
            $this->title = (string)($data['title'] ?? '');
            $this->content = (string)($data['content'] ?? '');
            $this->points = max(0, (int)round((float)($data['points'] ?? 1)));
            $this->penalty = max(0, (int)round((float)($data['penalty'] ?? 0)));
            $this->sort_order = (int)($data['sort_order'] ?? 0);

            if (isset($data['options'])) {
                if (is_array($data['options'])) {
                    $this->options = $data['options'];
                } else {
                    $decoded = json_decode((string)$data['options'], true);
                    $this->options = is_array($decoded) ? $decoded : [];
                }
            }
        }
    }

    public static function supported_types(): array
    {
        return [
            'single' => [
                'label'       => __('Один выбор (Radio)', 'wp-olympiad'),
                'description' => __('Один правильный ответ из предложенных вариантов', 'wp-olympiad'),
            ],
            'multi' => [
                'label'       => __('Множественный выбор (Checkbox)', 'wp-olympiad'),
                'description' => __('Несколько правильных ответов с пропорциональным начислением баллов', 'wp-olympiad'),
            ],
            'dropdown' => [
                'label'       => __('Выпадающий список (Dropdown)', 'wp-olympiad'),
                'description' => __('Выбор одного верного ответа из раскрывающегося списка', 'wp-olympiad'),
            ],
            'boolean' => [
                'label'       => __('Верно / Неверно (True/False)', 'wp-olympiad'),
                'description' => __('Определение истинности утверждения (Да/Нет)', 'wp-olympiad'),
            ],
            'number' => [
                'label'       => __('Числовой ответ (Точный или с погрешностью)', 'wp-olympiad'),
                'description' => __('Ввод числа с возможностью указания допустимой погрешности (± delta)', 'wp-olympiad'),
            ],
            'text' => [
                'label'       => __('Краткий текстовый ответ', 'wp-olympiad'),
                'description' => __('Ввод слова/фразы с проверкой синонимов и игнорированием регистра', 'wp-olympiad'),
            ],
            'matching' => [
                'label'       => __('Установление соответствий (Matching)', 'wp-olympiad'),
                'description' => __('Сопоставление элементов левой колонки с элементами правой колонки', 'wp-olympiad'),
            ],
            'ordering' => [
                'label'       => __('Установление последовательности (Sequence)', 'wp-olympiad'),
                'description' => __('Расстановка элементов в правильном хронологическом или логическом порядке', 'wp-olympiad'),
            ],
            'fill_blank' => [
                'label'       => __('Заполнение пропусков (Fill in the blanks)', 'wp-olympiad'),
                'description' => __('Текст с пропусками вида {ответ1|синоним}, заполняемыми участником', 'wp-olympiad'),
            ],
            'slider' => [
                'label'       => __('Шкала / Ползунок (Slider / Scale)', 'wp-olympiad'),
                'description' => __('Выбор числового значения на шкале с проверкой диапазона допуска', 'wp-olympiad'),
            ],
            'word_scramble' => [
                'label'       => __('Составление слова из букв (Анаграмма)', 'wp-olympiad'),
                'description' => __('Сборка слова из перемешанных буквенных плиток с проверкой', 'wp-olympiad'),
            ],
            'dictation' => [
                'label'       => __('Интерактивный диктант', 'wp-olympiad'),
                'description' => __('Текст с орфограммами/пунктограммами вида {=верно|ошибка} и аудированием', 'wp-olympiad'),
            ],
            'essay' => [
                'label'       => __('Развёрнутый ответ / Решение (Эссе)', 'wp-olympiad'),
                'description' => __('Текстовое решение или эссе, оцениваемое жюри/преподавателем вручную', 'wp-olympiad'),
            ],
        ];
    }

    public static function get(int $id): ?self
    {
        if (isset(self::$runtime_cache[$id])) {
            return self::$runtime_cache[$id];
        }

        global $wpdb;
        $table = DB::questions_table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);
        if ($row) {
            self::$runtime_cache[$id] = new self($row);
            return self::$runtime_cache[$id];
        }
        return null;
    }

    public static function clear_cache(int $id = 0): void
    {
        self::$quiz_questions_cache = [];
        if ($id > 0) {
            unset(self::$runtime_cache[$id]);
        } else {
            self::$runtime_cache = [];
        }
    }

    /**
     * @return self[]
     */
    public static function get_by_quiz(int $quiz_id, bool $shuffle = false): array
    {
        if (!$shuffle && isset(self::$quiz_questions_cache[$quiz_id])) {
            return self::$quiz_questions_cache[$quiz_id];
        }

        global $wpdb;
        $table = DB::questions_table();
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} WHERE quiz_id = %d ORDER BY sort_order ASC, id ASC", $quiz_id),
            ARRAY_A
        );

        $questions = [];
        foreach ($rows as $row) {
            $questions[] = new self($row);
        }

        if (!$shuffle) {
            self::$quiz_questions_cache[$quiz_id] = $questions;
            return $questions;
        }

        if (count($questions) > 1) {
            shuffle($questions);
        }

        return $questions;
    }

    public static function create(array $data): int
    {
        global $wpdb;
        $table = DB::questions_table();

        $quiz_id = (int)($data['quiz_id'] ?? 0);
        $question_type = sanitize_key($data['question_type'] ?? 'single');
        $title = wp_kses_post($data['title'] ?? '');
        $content = wp_kses_post($data['content'] ?? '');
        $points = max(0, (int)round((float)($data['points'] ?? 1)));
        $penalty = max(0, (int)round((float)($data['penalty'] ?? 0)));
        $sort_order = (int)($data['sort_order'] ?? 0);

        $options = self::sanitize_options($question_type, $data['options'] ?? []);
        $points = self::compute_max_points($question_type, $points, $options);
        $options_json = wp_json_encode($options, JSON_UNESCAPED_UNICODE);

        $wpdb->insert($table, [
            'quiz_id'       => $quiz_id,
            'question_type' => $question_type,
            'title'         => $title,
            'content'       => $content,
            'points'        => $points,
            'penalty'       => $penalty,
            'options'       => $options_json,
            'sort_order'    => $sort_order,
        ], ['%d', '%s', '%s', '%s', '%d', '%d', '%s', '%d']);

        self::clear_cache();
        return (int)$wpdb->insert_id;
    }

    public function update(array $data): bool
    {
        global $wpdb;
        $table = DB::questions_table();

        $fields = [];
        $format = [];

        if (isset($data['question_type'])) {
            $this->question_type = sanitize_key($data['question_type']);
            $fields['question_type'] = $this->question_type;
            $format[] = '%s';
        }
        if (isset($data['title'])) {
            $this->title = wp_kses_post($data['title']);
            $fields['title'] = $this->title;
            $format[] = '%s';
        }
        if (isset($data['content'])) {
            $this->content = wp_kses_post($data['content']);
            $fields['content'] = $this->content;
            $format[] = '%s';
        }
        if (isset($data['points'])) {
            $this->points = max(0, (int)round((float)$data['points']));
            $fields['points'] = $this->points;
            $format[] = '%d';
        }
        if (isset($data['penalty'])) {
            $this->penalty = max(0, (int)round((float)$data['penalty']));
            $fields['penalty'] = $this->penalty;
            $format[] = '%d';
        }
        if (isset($data['sort_order'])) {
            $this->sort_order = (int)$data['sort_order'];
            $fields['sort_order'] = $this->sort_order;
            $format[] = '%d';
        }
        if (isset($data['options']) && is_array($data['options'])) {
            $this->options = self::sanitize_options($this->question_type, $data['options']);
            $this->points = self::compute_max_points($this->question_type, $this->points, $this->options);
            $fields['points'] = $this->points;
            $fields['options'] = wp_json_encode($this->options, JSON_UNESCAPED_UNICODE);
            $format[] = '%s';
        }

        $res = $wpdb->update($table, $fields, ['id' => $this->id], $format, ['%d']);
        self::clear_cache($this->id);
        return $res !== false;
    }

    public static function compute_max_points(string $type, int $base_points, array $options): int
    {
        switch ($type) {
            case 'single':
            case 'dropdown':
            case 'boolean':
                $has_pts = false;
                $max_pt = 0;
                foreach ($options['choices'] ?? [] as $ch) {
                    if (isset($ch['points']) && $ch['points'] !== null) {
                        $has_pts = true;
                        $max_pt = max($max_pt, (int)round((float)$ch['points']));
                    }
                }
                return $has_pts ? $max_pt : $base_points;

            case 'multi':
                $has_pts = false;
                $sum_pts = 0;
                foreach ($options['choices'] ?? [] as $ch) {
                    if (isset($ch['points']) && $ch['points'] !== null) {
                        $has_pts = true;
                        $p_val = (int)round((float)$ch['points']);
                        if ($p_val > 0) {
                            $sum_pts += $p_val;
                        }
                    }
                }
                return $has_pts ? $sum_pts : $base_points;

            case 'matching':
                $has_pts = false;
                $sum_pts = 0;
                foreach ($options['pairs'] ?? [] as $p) {
                    if (isset($p['points']) && $p['points'] !== null) {
                        $has_pts = true;
                        $sum_pts += (int)round((float)$p['points']);
                    }
                }
                return $has_pts ? $sum_pts : $base_points;

            case 'ordering':
                $has_pts = false;
                $sum_pts = 0;
                foreach ($options['items'] ?? [] as $it) {
                    if (isset($it['points']) && $it['points'] !== null) {
                        $has_pts = true;
                        $sum_pts += (int)round((float)$it['points']);
                    }
                }
                return $has_pts ? $sum_pts : $base_points;

            default:
                return $base_points;
        }
    }

    public static function delete(int $id): bool
    {
        global $wpdb;
        self::clear_cache($id);
        $wpdb->delete(DB::responses_table(), ['question_id' => $id], ['%d']);
        $res = $wpdb->delete(DB::questions_table(), ['id' => $id], ['%d']);
        return $res !== false;
    }

    public static function sanitize_options(string $type, array $options): array
    {
        $clean = [];
        $clean['explanation'] = wp_kses_post($options['explanation'] ?? '');
        $clean['image_url'] = esc_url_raw($options['image_url'] ?? '');
        $clean['image_caption'] = sanitize_text_field($options['image_caption'] ?? '');
        $clean['image_alt'] = sanitize_text_field($options['image_alt'] ?? '');
        $clean['image_position'] = in_array($options['image_position'] ?? '', ['above', 'below'], true) ? $options['image_position'] : 'above';
        $clean['is_required'] = !empty($options['is_required']) ? 1 : 0;
        $clean['shuffle_answers'] = isset($options['shuffle_answers']) ? (!empty($options['shuffle_answers']) ? 1 : 0) : 1;
        $clean['group_name'] = sanitize_text_field($options['group_name'] ?? '');
        $clean['show_points'] = isset($options['show_points']) && $options['show_points'] !== '' ? (!empty($options['show_points']) ? 1 : 0) : null;
        $clean['shared_text_id'] = sanitize_key($options['shared_text_id'] ?? '');

        switch ($type) {
            case 'single':
            case 'multi':
            case 'dropdown':
            case 'boolean':
                $clean['choices'] = [];
                $raw_choices = $options['choices'] ?? [];
                if (is_array($raw_choices)) {
                    foreach ($raw_choices as $idx => $choice) {
                        $id = sanitize_key($choice['id'] ?? 'c' . ($idx + 1));
                        $text = sanitize_text_field($choice['text'] ?? '');
                        if (empty($text) && !isset($choice['text'])) {
                            continue;
                        }
                        $is_correct = !empty($choice['is_correct']) ? 1 : 0;
                        $ch_points = (isset($choice['points']) && $choice['points'] !== '' && is_numeric($choice['points']))
                            ? (int)round((float)$choice['points'])
                            : null;
                        $clean['choices'][] = [
                            'id'         => $id,
                            'text'       => $text,
                            'is_correct' => $is_correct,
                            'points'     => $ch_points,
                        ];
                    }
                }
                break;

            case 'number':
                $clean['target_value'] = isset($options['target_value']) ? (float)$options['target_value'] : 0.0;
                $clean['tolerance'] = isset($options['tolerance']) ? max(0.0, (float)$options['tolerance']) : 0.0;
                break;

            case 'text':
                $raw_answers = $options['answers'] ?? [];
                if (is_string($raw_answers)) {
                    $raw_answers = preg_split('/[\r\n,]+/u', $raw_answers, -1, PREG_SPLIT_NO_EMPTY);
                }
                $clean['answers'] = array_values(array_filter(array_map('trim', array_map('sanitize_text_field', (array)$raw_answers))));
                $valid_modes = ['exact', 'contains_any', 'contains_all', 'fuzzy', 'regex'];
                $match_mode = sanitize_key($options['match_mode'] ?? 'exact');
                $clean['match_mode'] = in_array($match_mode, $valid_modes, true) ? $match_mode : 'exact';
                $clean['case_sensitive'] = !empty($options['case_sensitive']) ? 1 : 0;
                $clean['ignore_punct'] = isset($options['ignore_punct']) ? (!empty($options['ignore_punct']) ? 1 : 0) : 1;
                $clean['ignore_whitespace'] = isset($options['ignore_whitespace']) ? (!empty($options['ignore_whitespace']) ? 1 : 0) : 1;
                $clean['typo_tolerance'] = max(0, min(10, (int)($options['typo_tolerance'] ?? 1)));
                break;

            case 'essay':
                $clean['min_words'] = isset($options['min_words']) ? max(0, (int)$options['min_words']) : 0;
                $clean['max_words'] = isset($options['max_words']) ? max(0, (int)$options['max_words']) : 0;
                $clean['auto_check'] = !empty($options['auto_check']) ? 1 : 0;
                $raw_kw = $options['keywords'] ?? [];
                if (is_string($raw_kw)) {
                    $raw_kw = preg_split('/[\r\n,]+/u', $raw_kw, -1, PREG_SPLIT_NO_EMPTY);
                }
                $clean['keywords'] = array_values(array_filter(array_map('trim', array_map('sanitize_text_field', (array)$raw_kw))));
                $clean['min_keywords'] = max(0, (int)($options['min_keywords'] ?? 0));
                $clean['scoring_mode'] = ($options['scoring_mode'] ?? 'proportional') === 'all_or_nothing' ? 'all_or_nothing' : 'proportional';
                $clean['case_sensitive'] = !empty($options['case_sensitive']) ? 1 : 0;
                $clean['ignore_punct'] = isset($options['ignore_punct']) ? (!empty($options['ignore_punct']) ? 1 : 0) : 1;
                $clean['auto_pass'] = !empty($options['auto_pass']) ? 1 : 0;
                break;

            case 'matching':
                $clean['pairs'] = [];
                $raw_pairs = $options['pairs'] ?? [];
                if (is_array($raw_pairs)) {
                    foreach ($raw_pairs as $idx => $pair) {
                        $pid = sanitize_key($pair['id'] ?? 'p' . ($idx + 1));
                        $left = sanitize_text_field($pair['left'] ?? '');
                        $right = sanitize_text_field($pair['right'] ?? '');
                        if ($left === '' && $right === '') {
                            continue;
                        }
                        $pair_pts = (isset($pair['points']) && $pair['points'] !== '' && is_numeric($pair['points']))
                            ? (int)round((float)$pair['points'])
                            : null;
                        $clean['pairs'][] = [
                            'id'     => $pid,
                            'left'   => $left,
                            'right'  => $right,
                            'points' => $pair_pts,
                        ];
                    }
                }
                break;

            case 'ordering':
                $clean['items'] = [];
                $raw_items = $options['items'] ?? [];
                if (is_array($raw_items)) {
                    foreach ($raw_items as $idx => $item) {
                        $iid = sanitize_key($item['id'] ?? 'ord_' . ($idx + 1));
                        $text = sanitize_text_field($item['text'] ?? (is_string($item) ? $item : ''));
                        if ($text === '') {
                            continue;
                        }
                        $item_pts = (isset($item['points']) && $item['points'] !== '' && is_numeric($item['points']))
                            ? (int)round((float)$item['points'])
                            : null;
                        $clean['items'][] = [
                            'id'     => $iid,
                            'text'   => $text,
                            'points' => $item_pts,
                        ];
                    }
                }
                break;

            case 'fill_blank':
                $template = wp_kses_post($options['template'] ?? '');
                $clean['template'] = $template;
                $clean['case_sensitive'] = !empty($options['case_sensitive']) ? 1 : 0;
                $choice_mode = sanitize_key($options['choice_mode'] ?? 'input');
                $clean['choice_mode'] = in_array($choice_mode, ['input', 'dropdown', 'word_bank'], true) ? $choice_mode : 'input';

                $raw_distractors = $options['distractors'] ?? '';
                if (is_array($raw_distractors)) {
                    $raw_distractors = implode(', ', $raw_distractors);
                }
                $clean['distractors'] = sanitize_text_field($raw_distractors);

                // Extract blanks from template: {=answer1|option2|option3} or {answer1|synonym}
                $blanks = [];
                if (preg_match_all('/\{([^}]+)\}/u', $template, $matches, PREG_OFFSET_CAPTURE)) {
                    foreach ($matches[1] as $b_idx => $match) {
                        $bid = 'blank_' . ($b_idx + 1);
                        $raw_ans = explode('|', $match[0]);
                        $answers = [];
                        $options_list = [];
                        $has_explicit_correct = false;

                        foreach ($raw_ans as $opt_idx => $opt_raw) {
                            $opt_clean = trim(sanitize_text_field($opt_raw));
                            if ($opt_clean === '') continue;

                            if (str_starts_with($opt_clean, '=') || str_starts_with($opt_clean, '*')) {
                                $val = ltrim($opt_clean, '=*');
                                $answers[] = $val;
                                $options_list[] = $val;
                                $has_explicit_correct = true;
                            } else {
                                $options_list[] = $opt_clean;
                                if ($clean['choice_mode'] === 'input') {
                                    $answers[] = $opt_clean;
                                } else {
                                    if ($opt_idx === 0 && !$has_explicit_correct) {
                                        $answers[] = $opt_clean;
                                    }
                                }
                            }
                        }

                        if (empty($answers) && !empty($options_list)) {
                            $answers = [$options_list[0]];
                        }

                        $blanks[] = [
                            'id'           => $bid,
                            'answers'      => array_values(array_unique($answers)),
                            'options_list' => array_values(array_unique($options_list)),
                        ];
                    }
                }
                $clean['blanks'] = $blanks;
                break;

            case 'slider':
                $clean['min'] = isset($options['min']) ? (float)$options['min'] : 0.0;
                $clean['max'] = isset($options['max']) ? (float)$options['max'] : 100.0;
                $clean['step'] = isset($options['step']) && (float)$options['step'] > 0 ? (float)$options['step'] : 1.0;
                $clean['target_value'] = isset($options['target_value']) ? (float)$options['target_value'] : 50.0;
                $clean['tolerance'] = isset($options['tolerance']) ? max(0.0, (float)$options['tolerance']) : 0.0;
                break;

            case 'word_scramble':
                $raw_words = $options['words'] ?? ($options['target_word'] ?? []);
                if (is_string($raw_words)) {
                    $raw_words = preg_split('/[\r\n,]+/u', $raw_words, -1, PREG_SPLIT_NO_EMPTY);
                }
                $words = array_values(array_filter(array_map('trim', array_map('sanitize_text_field', (array)$raw_words))));
                $clean['words'] = $words;
                $clean['target_word'] = $words[0] ?? '';

                $raw_extra = $options['extra_letters'] ?? '';
                if (is_array($raw_extra)) {
                    $raw_extra = implode('', $raw_extra);
                }
                $clean_extra = (string)preg_replace('/[^\p{L}\p{N}]/u', '', (string)$raw_extra);
                $clean['extra_letters'] = $clean_extra;
                $clean['case_sensitive'] = !empty($options['case_sensitive']) ? 1 : 0;
                break;

            case 'dictation':
                $template = wp_kses_post($options['template'] ?? '');
                $clean['template'] = $template;
                $clean['audio_url'] = esc_url_raw($options['audio_url'] ?? '');
                $clean['scoring_mode'] = ($options['scoring_mode'] ?? 'proportional') === 'all_or_nothing' ? 'all_or_nothing' : 'proportional';
                $clean['allowed_mistakes'] = max(0, (int)($options['allowed_mistakes'] ?? 0));

                $gaps = [];
                if (preg_match_all('/\{([^}]+)\}/u', $template, $matches, PREG_OFFSET_CAPTURE)) {
                    foreach ($matches[1] as $idx => $match) {
                        $raw = $match[0];
                        $gid = 'd_' . ($idx + 1);
                        $parts = array_map('trim', explode('|', $raw));
                        $correct = '';
                        $parsed_options = [];

                        foreach ($parts as $p_idx => $p) {
                            if (str_starts_with($p, '=') || str_starts_with($p, '*')) {
                                $clean_p = ltrim($p, '=*');
                                $correct = $clean_p;
                                $parsed_options[] = $clean_p;
                            } else {
                                if ($p_idx === 0 && $correct === '') {
                                    $correct = $p;
                                }
                                $parsed_options[] = $p;
                            }
                        }
                        $parsed_options = array_values(array_unique($parsed_options));
                        $gaps[$gid] = [
                            'id'      => $gid,
                            'correct' => $correct,
                            'options' => $parsed_options,
                        ];
                    }
                }
                $clean['gaps'] = $gaps;
                break;
        }

        return $clean;
    }

    public function is_required(): bool
    {
        return !empty($this->options['is_required']);
    }

    public function should_shuffle(bool $quiz_default = true): bool
    {
        if (isset($this->options['shuffle_answers'])) {
            return !empty($this->options['shuffle_answers']);
        }
        return $quiz_default;
    }

    /**
     * Return public sanitized payload for active test takers.
     * CRITICAL SECURITY: Removes all answers and hints so students cannot cheat!
     */
    public function get_public_data(bool $shuffle_answers = false): array
    {
        $effective_shuffle = $this->should_shuffle($shuffle_answers);

        $data = [
            'id'              => $this->id,
            'question_type'   => $this->question_type,
            'title'           => $this->title,
            'content'         => $this->content,
            'points'          => $this->points,
            'penalty'         => $this->penalty,
            'sort_order'      => $this->sort_order,
            'is_required'     => $this->is_required(),
            'shuffle_answers' => $effective_shuffle,
            'group_name'      => sanitize_text_field($this->options['group_name'] ?? ''),
            'show_points'     => isset($this->options['show_points']) ? (bool)$this->options['show_points'] : null,
            'shared_text_id'  => sanitize_key($this->options['shared_text_id'] ?? ''),
        ];

        $st_id = sanitize_key($this->options['shared_text_id'] ?? '');
        if ($st_id !== '') {
            $quiz = Quiz::get($this->quiz_id);
            if ($quiz) {
                $st = $quiz->get_shared_text($st_id);
                if ($st) {
                    $data['shared_text'] = [
                        'id'      => $st['id'],
                        'title'   => sanitize_text_field($st['title'] ?? ''),
                        'content' => wp_kses_post($st['content'] ?? ''),
                    ];
                }
            }
        }

        if (!empty($this->options['image_url'])) {
            $data['image_url'] = esc_url_raw($this->options['image_url']);
            $data['image_caption'] = sanitize_text_field($this->options['image_caption'] ?? '');
            $data['image_alt'] = sanitize_text_field($this->options['image_alt'] ?? '');
            $data['image_position'] = $this->options['image_position'] ?? 'above';
        }

        switch ($this->question_type) {
            case 'single':
            case 'multi':
            case 'dropdown':
            case 'boolean':
                $choices = [];
                foreach ($this->options['choices'] ?? [] as $choice) {
                    $choices[] = [
                        'id'   => $choice['id'],
                        'text' => $choice['text'],
                    ];
                }
                if ($effective_shuffle && count($choices) > 1 && $this->question_type !== 'boolean') {
                    shuffle($choices);
                }
                $data['choices'] = $choices;
                break;

            case 'number':
                $data['placeholder'] = __('Введите числовое значение...', 'wp-olympiad');
                break;

            case 'text':
                $data['placeholder'] = __('Введите краткий ответ...', 'wp-olympiad');
                break;

            case 'essay':
                $data['placeholder'] = __('Введите подробное решение или эссе...', 'wp-olympiad');
                $data['min_words'] = $this->options['min_words'] ?? 0;
                $data['max_words'] = $this->options['max_words'] ?? 0;
                break;

            case 'matching':
                $pairs = $this->options['pairs'] ?? [];
                $left_items = [];
                $right_options = [];

                foreach ($pairs as $p) {
                    $left_items[] = [
                        'id'   => $p['id'],
                        'text' => $p['left'],
                    ];
                    if (!empty($p['right'])) {
                        $right_options[] = $p['right'];
                    }
                }
                $right_options = array_values(array_unique($right_options));
                if ($effective_shuffle && count($right_options) > 1) {
                    shuffle($right_options);
                }

                $data['left_items'] = $left_items;
                $data['right_options'] = $right_options;
                break;

            case 'ordering':
                $items = $this->options['items'] ?? [];
                $public_items = [];
                foreach ($items as $item) {
                    $public_items[] = [
                        'id'   => $item['id'],
                        'text' => $item['text'],
                    ];
                }
                // Randomize initial display order
                if (count($public_items) > 1) {
                    shuffle($public_items);
                }
                $data['items'] = $public_items;
                break;

            case 'fill_blank':
                $template = $this->options['template'] ?? '';
                $choice_mode = $this->options['choice_mode'] ?? 'input';
                $blanks = (array)($this->options['blanks'] ?? []);
                $count = 0;

                // Parse distractors
                $raw_dist = trim((string)($this->options['distractors'] ?? ''));
                $distractor_list = [];
                if ($raw_dist !== '') {
                    $distractor_list = array_values(array_filter(array_map('trim', explode(',', $raw_dist))));
                }

                // Global list of options across all blanks
                $all_blank_answers = [];
                foreach ($blanks as $b) {
                    foreach ((array)($b['options_list'] ?? ($b['answers'] ?? [])) as $ans) {
                        if ($ans !== '') {
                            $all_blank_answers[] = $ans;
                        }
                    }
                }
                $all_options_pool = array_values(array_unique(array_merge($all_blank_answers, $distractor_list)));

                $blank_options = [];
                $sanitized_template = (string)preg_replace_callback('/\{([^}]+)\}/u', function () use (&$count, $blanks, $choice_mode, $all_options_pool, &$blank_options, $effective_shuffle) {
                    $count++;
                    $bid = 'blank_' . $count;
                    if ($choice_mode === 'dropdown') {
                        $b_info = $blanks[$count - 1] ?? null;
                        $specific_opts = (array)($b_info['options_list'] ?? []);
                        $opts_for_this = (count($specific_opts) > 1) ? $specific_opts : $all_options_pool;
                        if ($effective_shuffle && count($opts_for_this) > 1) {
                            shuffle($opts_for_this);
                        }
                        $blank_options[$bid] = array_values(array_unique($opts_for_this));
                    }
                    return '[[blank:' . $bid . ']]';
                }, $template);

                $data['template'] = $sanitized_template;
                $data['blanks_count'] = $count;
                $data['choice_mode'] = $choice_mode;

                if ($choice_mode === 'dropdown') {
                    $data['blank_options'] = $blank_options;
                } elseif ($choice_mode === 'word_bank') {
                    $bank = $all_options_pool;
                    if ($effective_shuffle && count($bank) > 1) {
                        shuffle($bank);
                    }
                    $bank_items = [];
                    foreach ($bank as $idx => $word) {
                        $bank_items[] = [
                            'id'   => 'wb_' . ($idx + 1),
                            'text' => $word,
                        ];
                    }
                    $data['word_bank'] = $bank_items;
                }
                break;

            case 'slider':
                $min = (float)($this->options['min'] ?? 0.0);
                $max = (float)($this->options['max'] ?? 100.0);
                $step = (float)($this->options['step'] ?? 1.0);
                $data['min'] = $min;
                $data['max'] = $max;
                $data['step'] = $step;
                $data['default_value'] = round(($min + $max) / 2, 2);
                break;

            case 'word_scramble':
                $target = trim((string)($this->options['target_word'] ?? ($this->options['words'][0] ?? '')));
                $extra = trim((string)($this->options['extra_letters'] ?? ''));

                $chars = preg_split('//u', $target, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $extra_chars = preg_split('//u', $extra, -1, PREG_SPLIT_NO_EMPTY) ?: [];

                $all_letters = array_merge($chars, $extra_chars);
                if (empty($this->options['case_sensitive'])) {
                    $all_letters = array_map(static fn($c) => mb_strtoupper($c, 'UTF-8'), $all_letters);
                }

                shuffle($all_letters);

                $tiles = [];
                foreach ($all_letters as $idx => $char) {
                    $tiles[] = [
                        'id'   => 'tile_' . ($idx + 1),
                        'char' => $char,
                    ];
                }

                $data['tiles'] = $tiles;
                $data['word_length'] = mb_strlen($target, 'UTF-8');
                $data['has_extra'] = !empty($extra_chars);
                $data['placeholder'] = __('Составьте слово, выбирая буквы...', 'wp-olympiad');
                break;

            case 'dictation':
                $template = (string)($this->options['template'] ?? '');
                $gaps = (array)($this->options['gaps'] ?? []);
                $public_gaps = [];
                $count = 0;

                $public_template = (string)preg_replace_callback('/\{([^}]+)\}/u', function() use (&$count, $gaps, &$public_gaps) {
                    $count++;
                    $gid = 'd_' . $count;
                    $gap_data = $gaps[$gid] ?? null;
                    $opts = $gap_data['options'] ?? [];
                    if (count($opts) > 1) {
                        shuffle($opts);
                    }
                    $public_gaps[$gid] = [
                        'id'      => $gid,
                        'options' => $opts,
                    ];
                    return '[[dict:' . $gid . ']]';
                }, $template);

                $data['template'] = $public_template;
                $data['dict_gaps'] = $public_gaps;
                $data['audio_url'] = esc_url_raw($this->options['audio_url'] ?? '');
                $data['total_gaps'] = $count;
                break;
        }

        return $data;
    }
}
