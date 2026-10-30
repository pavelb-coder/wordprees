<?php
declare(strict_types=1);

namespace WPOlympiad;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Protocol and Submissions Exporter (CSV / Excel)
 */
final class Export
{
    /**
     * Generate and stream CSV export for a quiz (includes all questions and user answers)
     */
    public static function export_quiz_attempts(int $quiz_id, bool $do_exit = true): ?string
    {
        if ($do_exit && !current_user_can('manage_options')) {
            wp_die(esc_html__('У вас недостаточно прав для выполнения этой операции.', 'wp-olympiad'));
        }

        $quiz = Quiz::get($quiz_id);
        if (!$quiz) {
            wp_die(esc_html__('Олимпиада не найдена.', 'wp-olympiad'));
        }

        $attempts = Attempt::get_all([
            'quiz_id' => $quiz_id,
            'limit'   => 10000,
            'orderby' => 'score',
            'order'   => 'DESC',
        ]);

        $questions = $quiz->get_questions(false);

        $filename = sprintf('protocol-%s-%s.csv', sanitize_title($quiz->title), date('Y-m-d'));

        if ($do_exit) {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Pragma: no-cache');
            header('Expires: 0');
            $output = fopen('php://output', 'w');
        } else {
            $output = fopen('php://temp', 'r+');
        }

        if ($output === false) {
            return null;
        }

        // Write UTF-8 BOM for Microsoft Excel compatibility
        fwrite($output, "\xEF\xBB\xBF");

        // Header Row with Complete Participant Survey Metadata and Question Columns
        $headers = [
            'ID попытки',
            'ФИО Участника',
            'Email',
            'Телефон',
            'Город / Населённый пункт',
            'Организация / Класс',
            'Дата старта',
            'Дата сдачи',
            'Затрачено времени (мин:сек)',
            'Нарушений (вкладки)',
            'Итого баллов',
            'Макс. балл',
            'Процент (%)',
            'Статус',
            'IP-адрес',
        ];

        // 1.0.21: столбцы дополнительной анкеты — сразу за данными участника.
        $custom_fields = $quiz->get_custom_fields();
        foreach ($custom_fields as $cf_def) {
            $headers[] = sprintf('Анкета: %s', $cf_def['label']);
        }

        foreach ($questions as $idx => $q) {
            $q_num = $idx + 1;
            $q_title_short = mb_substr(strip_tags($q->title), 0, 45, 'UTF-8');
            $group_label = QuestionGroups::normalize_name((string)($q->options['group_name'] ?? ''));
            $group_suffix = $group_label !== '' ? sprintf(' — %s', $group_label) : '';
            $headers[] = sprintf('Вопрос %d%s: %s [Ответ]', $q_num, $group_suffix, $q_title_short);
            $headers[] = sprintf('Вопрос %d: Балл (из %d)', $q_num, (int)$q->points);
        }

        fputcsv($output, $headers, ';', '"', "\\");

        $status_labels = [
            'in_progress'  => 'В процессе',
            'completed'    => 'Завершено',
            'needs_review' => 'На проверке',
            'expired'      => 'Истекло время',
            'abandoned'    => 'Прервана',
            'disqualified' => 'Дисквалифицирован',
        ];

        // Batch fetch all responses for these attempts to prevent N+1 queries
        global $wpdb;
        $attempt_ids = array_column($attempts, 'id');
        $responses_by_attempt = [];
        if (!empty($attempt_ids)) {
            $placeholders = implode(',', array_fill(0, count($attempt_ids), '%d'));
            $table_resp = DB::responses_table();
            $raw_responses = $wpdb->get_results($wpdb->prepare("
                SELECT attempt_id, question_id, user_answer, is_correct, awarded_points, review_comment
                FROM {$table_resp}
                WHERE attempt_id IN ({$placeholders})
            ", ...$attempt_ids), ARRAY_A);

            foreach ($raw_responses as $r) {
                $responses_by_attempt[(int)$r['attempt_id']][(int)$r['question_id']] = $r;
            }
        }

        foreach ($attempts as $att) {
            $duration_m = floor($att->duration_seconds / 60);
            $duration_s = $att->duration_seconds % 60;
            $duration_fmt = sprintf('%02d:%02d', $duration_m, $duration_s);

            $status = $att->get_status_label();

            $row = [
                $att->id,
                $att->participant_name,
                $att->participant_email,
                $att->participant_phone ?: '—',
                $att->participant_city ?: '—',
                $att->participant_org ?: '—',
                $att->started_at,
                $att->submitted_at ?: '—',
                $duration_fmt,
                $att->tab_switches,
                number_format($att->score, 0, '.', ''),
                number_format($att->max_score, 0, '.', ''),
                number_format($att->percentage, 2, '.', ''),
                $status,
                $att->ip_address ?: '—',
            ];

            $cf_values = $att->custom_field_values();
            foreach ($custom_fields as $cf_def) {
                $cf_text = ParticipantFields::format_value($cf_def, $cf_values[$cf_def['id']] ?? '');
                $row[] = $cf_text !== '' ? $cf_text : '—';
            }

            foreach ($questions as $q) {
                $resp = $responses_by_attempt[$att->id][$q->id] ?? null;
                if ($resp) {
                    $row[] = self::format_user_answer($q, (string)$resp['user_answer']);
                    $row[] = number_format((float)$resp['awarded_points'], 0, '.', '');
                } else {
                    $row[] = '—';
                    $row[] = '0';
                }
            }

            fputcsv($output, $row, ';', '"', "\\");
        }

        if (!$do_exit) {
            rewind($output);
            $content = stream_get_contents($output);
            fclose($output);
            return (string)$content;
        }

        fclose($output);
        exit;
    }

    /**
     * Export single attempt answers with detailed question-by-question breakdown
     */
    public static function export_single_attempt(int $attempt_id, bool $do_exit = true): ?string
    {
        if ($do_exit && !current_user_can('manage_options')) {
            wp_die(esc_html__('У вас недостаточно прав для выполнения этой операции.', 'wp-olympiad'));
        }

        $attempt = Attempt::get($attempt_id);
        if (!$attempt) {
            wp_die(esc_html__('Попытка не найдена.', 'wp-olympiad'));
        }

        $quiz = Quiz::get($attempt->quiz_id);
        if (!$quiz) {
            wp_die(esc_html__('Олимпиада не найдена.', 'wp-olympiad'));
        }

        $questions = $quiz->get_questions(false);
        $responses = $attempt->get_responses();
        $responses_map = [];
        foreach ($responses as $r) {
            $responses_map[(int)$r['question_id']] = $r;
        }

        $filename = sprintf(
            'attempt-%d-%s-%s.csv',
            $attempt->id,
            sanitize_title($attempt->participant_name ?: 'participant'),
            date('Y-m-d')
        );

        if ($do_exit) {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Pragma: no-cache');
            header('Expires: 0');
            $output = fopen('php://output', 'w');
        } else {
            $output = fopen('php://temp', 'r+');
        }

        if ($output === false) {
            return null;
        }

        // Write UTF-8 BOM for Microsoft Excel compatibility
        fwrite($output, "\xEF\xBB\xBF");

        // Metadata Header Section with full participant survey data
        fputcsv($output, ['ПРОТОКОЛ ПРОВЕРКИ РАБОТЫ УЧАСТНИКА'], ';', '"', "\\");
        fputcsv($output, ['Олимпиада', $quiz->title], ';', '"', "\\");
        fputcsv($output, ['Участник', $attempt->participant_name], ';', '"', "\\");
        fputcsv($output, ['Email', $attempt->participant_email], ';', '"', "\\");
        fputcsv($output, ['Телефон', $attempt->participant_phone ?: '—'], ';', '"', "\\");
        fputcsv($output, ['Город / Населённый пункт', $attempt->participant_city ?: '—'], ';', '"', "\\");
        fputcsv($output, ['Организация / Класс', $attempt->participant_org ?: '—'], ';', '"', "\\");

        // 1.0.21: ответы дополнительной анкеты этого участника.
        $attempt_cf_values = $attempt->custom_field_values();
        foreach ($quiz->get_custom_fields() as $cf_def) {
            $cf_text = ParticipantFields::format_value($cf_def, $attempt_cf_values[$cf_def['id']] ?? '');
            fputcsv($output, ['Анкета: ' . $cf_def['label'], $cf_text !== '' ? $cf_text : '—'], ';', '"', "\\");
        }
        fputcsv($output, ['IP-адрес', $attempt->ip_address ?: '—'], ';', '"', "\\");
        fputcsv($output, ['Дата старта', $attempt->started_at], ';', '"', "\\");
        fputcsv($output, ['Дата сдачи', $attempt->submitted_at ?: '—'], ';', '"', "\\");
        $duration_m = floor($attempt->duration_seconds / 60);
        $duration_s = $attempt->duration_seconds % 60;
        fputcsv($output, ['Время решения', sprintf('%02d:%02d', $duration_m, $duration_s)], ';', '"', "\\");
        fputcsv($output, ['Переключений вкладок', $attempt->tab_switches], ';', '"', "\\");
        fputcsv($output, ['Итоговый балл', sprintf('%d из %d (%d%%)', round($attempt->score), round($attempt->max_score), round($attempt->percentage))], ';', '"', "\\");
        fputcsv($output, ['Статус работы', $attempt->get_status_label()], ';', '"', "\\");
        fputcsv($output, [], ';', '"', "\\");

        // Questions Table Header
        fputcsv($output, [
            '№',
            'Текст задания',
            'Тип вопроса',
            'Ответ участника',
            'Правильный ответ / Пояснение',
            'Начислено баллов',
            'Макс. балл',
            'Статус ответа',
            'Комментарий проверяющего (жюри)',
        ], ';', '"', "\\");

        $types = Question::supported_types();

        foreach ($questions as $idx => $q) {
            $resp = $responses_map[$q->id] ?? null;
            $user_ans_raw = $resp ? (string)$resp['user_answer'] : '';
            $formatted_ans = $resp ? self::format_user_answer($q, $user_ans_raw) : '—';
            $awarded = $resp ? (int)round((float)$resp['awarded_points']) : 0;
            $is_corr = $resp ? $resp['is_correct'] : null;
            $needs_rev = $resp ? !empty($resp['needs_manual_review']) : false;

            if ($needs_rev) {
                $status_label = 'На проверке';
            } elseif ($is_corr === 1 || ($q->points > 0 && $awarded === (int)$q->points)) {
                $status_label = 'Верно';
            } elseif ($awarded > 0) {
                $status_label = 'Частично';
            } else {
                $status_label = 'Неверно';
            }

            // Solution / explanation
            $solution = $q->options['explanation'] ?? '';
            if (empty($solution)) {
                if ($q->question_type === 'single' || $q->question_type === 'dropdown') {
                    foreach ($q->options['choices'] ?? [] as $c) {
                        if (!empty($c['is_correct'])) {
                            $solution = (string)($c['text'] ?? '');
                            break;
                        }
                    }
                } elseif ($q->question_type === 'multi') {
                    $corr_texts = [];
                    foreach ($q->options['choices'] ?? [] as $c) {
                        if (!empty($c['is_correct'])) {
                            $corr_texts[] = (string)($c['text'] ?? '');
                        }
                    }
                    $solution = implode(', ', $corr_texts);
                } elseif ($q->question_type === 'number') {
                    $solution = (string)($q->options['target_value'] ?? '');
                } elseif ($q->question_type === 'text') {
                    $solution = implode(', ', (array)($q->options['answers'] ?? []));
                }
            }

            fputcsv($output, [
                $idx + 1,
                $q->title,
                $types[$q->question_type]['label'] ?? $q->question_type,
                $formatted_ans,
                $solution ?: '—',
                $awarded,
                (int)$q->points,
                $status_label,
                $resp['review_comment'] ?? '',
            ], ';', '"', "\\");
        }

        if (!$do_exit) {
            rewind($output);
            $content = stream_get_contents($output);
            fclose($output);
            return (string)$content;
        }

        fclose($output);
        exit;
    }

    /**
     * Format raw participant answer into human-readable text for CSV and reports
     */
    public static function format_user_answer(Question $q, string $raw): string
    {
        if ($raw === '' || $raw === '[]' || $raw === '{}') {
            return '—';
        }

        switch ($q->question_type) {
            case 'single':
            case 'dropdown':
                foreach ($q->options['choices'] ?? [] as $ch) {
                    if ((string)($ch['id'] ?? '') === $raw) {
                        return (string)($ch['text'] ?? $raw);
                    }
                }
                return $raw;

            case 'boolean':
                return ($raw === 'true') ? __('Верно', 'wp-olympiad') : __('Неверно', 'wp-olympiad');

            case 'multi':
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $selected_texts = [];
                    $choices_map = [];
                    foreach ($q->options['choices'] ?? [] as $ch) {
                        $choices_map[(string)$ch['id']] = $ch['text'];
                    }
                    foreach ($decoded as $cid) {
                        $selected_texts[] = $choices_map[(string)$cid] ?? (string)$cid;
                    }
                    return implode(', ', $selected_texts);
                }
                return $raw;

            case 'matching':
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $pairs_map = [];
                    foreach ($q->options['pairs'] ?? [] as $p) {
                        $pairs_map[$p['id']] = $p['left'];
                    }
                    $lines = [];
                    foreach ($decoded as $pid => $right_val) {
                        $left_label = $pairs_map[$pid] ?? $pid;
                        $lines[] = "{$left_label} → {$right_val}";
                    }
                    return implode('; ', $lines);
                }
                return $raw;

            case 'ordering':
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $items_map = [];
                    foreach ($q->options['items'] ?? [] as $it) {
                        $items_map[$it['id']] = $it['text'];
                    }
                    $lines = [];
                    $pos = 1;
                    foreach ($decoded as $iid) {
                        $text = $items_map[$iid] ?? $iid;
                        $lines[] = "{$pos}. {$text}";
                        $pos++;
                    }
                    return implode(' → ', $lines);
                }
                return $raw;

            case 'fill_blank':
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $lines = [];
                    $i = 1;
                    foreach ($decoded as $bid => $val) {
                        $lines[] = "[{$i}]: {$val}";
                        $i++;
                    }
                    return implode(', ', $lines);
                }
                return $raw;

            case 'word_scramble':
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $assembled = '';
                    foreach ($decoded as $val) {
                        $assembled .= is_array($val) ? ($val['char'] ?? '') : (string)$val;
                    }
                    return $assembled ?: $raw;
                }
                return $raw;

            case 'dictation':
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $lines = [];
                    foreach ($decoded as $gid => $val) {
                        $lines[] = "{$gid}: {$val}";
                    }
                    return implode(', ', $lines);
                }
                return $raw;

            case 'slider':
            case 'numeric':
            case 'short_text':
            case 'essay':
            default:
                return $raw;
        }
    }

    /**
     * Export quiz questions as standard CSV (compatible with NetworkImport)
     */
    public static function export_quiz_questions_csv(Quiz $quiz, bool $do_exit = true): ?string
    {
        if ($do_exit && !current_user_can('manage_options')) {
            wp_die(esc_html__('У вас недостаточно прав для выполнения этой операции.', 'wp-olympiad'));
        }

        $questions = $quiz->get_questions(false);
        $filename = sprintf('questions-%s-%s.csv', sanitize_title($quiz->title) ?: 'quiz', date('Y-m-d'));

        if ($do_exit) {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Pragma: no-cache');
            header('Expires: 0');
            $output = fopen('php://output', 'w');
        } else {
            $output = fopen('php://temp', 'r+');
        }

        if ($output === false) {
            return null;
        }

        fwrite($output, "\xEF\xBB\xBF"); // UTF-8 BOM

        // Headers
        fputcsv($output, [
            'type',
            'title',
            'points',
            'penalty',
            'group_name',
            'choices',
            'correct',
            'target_value',
            'tolerance',
            'answers',
            'explanation',
            'is_required',
            'shared_text_id',
            'template',
            'extra',
        ], ';', '"', "\\");

        foreach ($questions as $q) {
            $type = $q->question_type;
            $opts = $q->options;

            $choices_str = '';
            $correct_str = '';
            $target_value = '';
            $tolerance = '';
            $answers_str = '';
            $template_str = '';
            $extra_parts = [];

            switch ($type) {
                case 'single':
                case 'multi':
                case 'dropdown':
                    $choices = (array)($opts['choices'] ?? []);
                    $parts = [];
                    $correct_parts = [];
                    foreach ($choices as $idx => $c) {
                        $is_corr = !empty($c['is_correct']);
                        $text = (string)($c['text'] ?? '');
                        if ($is_corr) {
                            $parts[] = '*' . $text;
                            $correct_parts[] = (string)($idx + 1);
                        } else {
                            $parts[] = $text;
                        }
                    }
                    $choices_str = implode('|', $parts);
                    $correct_str = implode(',', $correct_parts);
                    break;

                case 'boolean':
                    $choices = (array)($opts['choices'] ?? []);
                    $is_true_correct = false;
                    foreach ($choices as $c) {
                        if (($c['id'] ?? '') === 'true' && !empty($c['is_correct'])) {
                            $is_true_correct = true;
                        }
                    }
                    $correct_str = $is_true_correct ? 'верно' : 'неверно';
                    break;

                case 'number':
                case 'numeric':
                    $target_value = (string)($opts['target_value'] ?? '');
                    $tolerance = (string)($opts['tolerance'] ?? '0');
                    break;

                case 'text':
                case 'short_text':
                    $synonyms = (array)($opts['answers'] ?? []);
                    if (empty($synonyms)) {
                        $synonyms = (array)($opts['synonyms'] ?? []);
                        if (!empty($opts['correct_answer'])) {
                            array_unshift($synonyms, $opts['correct_answer']);
                        }
                    }
                    $answers_str = implode('|', array_values(array_unique(array_filter(array_map('strval', $synonyms)))));
                    $extra_parts[] = 'match=' . (string)($opts['match_mode'] ?? 'fuzzy');
                    $extra_parts[] = 'typo=' . (int)($opts['typo_tolerance'] ?? 1);
                    $extra_parts[] = 'case_sensitive=' . (int)($opts['case_sensitive'] ?? 0);
                    break;

                case 'matching':
                    $pairs = (array)($opts['pairs'] ?? []);
                    $pair_strs = [];
                    foreach ($pairs as $p) {
                        $pair_strs[] = ($p['left'] ?? '') . '=>' . ($p['right'] ?? '');
                    }
                    $choices_str = implode('|', $pair_strs);
                    break;

                case 'ordering':
                    $items = (array)($opts['items'] ?? []);
                    $order_strs = [];
                    foreach ($items as $item) {
                        $order_strs[] = (string)($item['text'] ?? $item);
                    }
                    $choices_str = implode('|', $order_strs);
                    break;

                case 'fill_blank':
                    $template_str = (string)($opts['template'] ?? $q->content);
                    $choices_str = $template_str;
                    $blanks = (array)($opts['blanks'] ?? []);
                    $b_parts = [];
                    foreach ($blanks as $b) {
                        $accepted = (array)($b['accepted'] ?? ($b['answers'] ?? []));
                        $b_parts[] = implode('/', array_filter(array_map('strval', $accepted)));
                    }
                    $correct_str = implode('|', $b_parts);
                    $extra_parts[] = 'mode=' . (string)($opts['choice_mode'] ?? 'input');
                    $extra_parts[] = 'distractors=' . (string)($opts['distractors'] ?? '');
                    $extra_parts[] = 'case_sensitive=' . (int)($opts['case_sensitive'] ?? 0);
                    break;

                case 'slider':
                    $target_value = (string)($opts['target_value'] ?? '');
                    $tolerance = (string)($opts['tolerance'] ?? '0');
                    $choices_str = ($opts['min'] ?? 0) . '..' . ($opts['max'] ?? 100);
                    $extra_parts[] = 'min=' . (string)($opts['min'] ?? 0);
                    $extra_parts[] = 'max=' . (string)($opts['max'] ?? 100);
                    $extra_parts[] = 'step=' . (string)($opts['step'] ?? 1);
                    break;

                case 'word_scramble':
                    $correct_str = (string)($opts['target_word'] ?? '');
                    if (!empty($opts['extra_letters'])) {
                        $extra_parts[] = 'extra_letters=' . (string)$opts['extra_letters'];
                    }
                    break;

                case 'dictation':
                    $template_str = (string)($opts['text_with_gaps'] ?? ($opts['template'] ?? ''));
                    $choices_str = $template_str;
                    $extra_parts[] = 'scoring=' . (string)($opts['scoring_mode'] ?? 'proportional');
                    $extra_parts[] = 'mistakes=' . (int)($opts['allowed_mistakes'] ?? 0);
                    if (!empty($opts['audio_url'])) {
                        $extra_parts[] = 'audio_url=' . (string)$opts['audio_url'];
                    }
                    break;

                case 'essay':
                    $answers_str = implode('|', array_filter(array_map('strval', (array)($opts['keywords'] ?? []))));
                    $extra_parts[] = 'min_words=' . (int)($opts['min_words'] ?? 0);
                    $extra_parts[] = 'max_words=' . (int)($opts['max_words'] ?? 0);
                    $extra_parts[] = 'keywords=' . implode(',', array_filter(array_map('strval', (array)($opts['keywords'] ?? []))));
                    $extra_parts[] = 'min_keywords=' . (int)($opts['min_keywords'] ?? 0);
                    $extra_parts[] = 'scoring=' . (string)($opts['scoring_mode'] ?? 'proportional');
                    break;
            }

            // «extra» — строка «ключ=значение;…»: точка с запятой внутри значений недопустима
            $extra_str = '';
            foreach ($extra_parts as $part) {
                $part = str_replace([';', "\n", "\r"], [',', ' ', ' '], (string)$part);
                if (!str_ends_with($part, '=')) {
                    $extra_str .= ($extra_str === '' ? '' : ';') . $part;
                }
            }

            fputcsv($output, [
                $type,
                $q->title,
                $q->points,
                $q->penalty,
                $opts['group_name'] ?? '',
                $choices_str,
                $correct_str,
                $target_value,
                $tolerance,
                $answers_str,
                $opts['explanation'] ?? '',
                $q->is_required() ? '1' : '0',
                $opts['shared_text_id'] ?? '',
                $template_str,
                $extra_str,
            ], ';', '"', "\\");
        }

        if ($do_exit) {
            fclose($output);
            exit;
        }

        rewind($output);
        $content = stream_get_contents($output);
        fclose($output);
        return is_string($content) ? $content : '';
    }

    /**
     * Export quiz questions as JSON (compatible with NetworkImport)
     */
    public static function export_quiz_questions_json(Quiz $quiz, bool $do_exit = true): ?string
    {
        if ($do_exit && !current_user_can('manage_options')) {
            wp_die(esc_html__('У вас недостаточно прав для выполнения этой операции.', 'wp-olympiad'));
        }

        $questions = $quiz->get_questions(false);
        $data = [
            'quiz' => [
                'title'       => $quiz->title,
                'description' => $quiz->description,
            ],
            'questions' => [],
        ];

        foreach ($questions as $q) {
            $data['questions'][] = [
                'type'           => $q->question_type,
                'title'          => $q->title,
                'content'        => $q->content,
                'points'         => $q->points,
                'penalty'        => $q->penalty,
                'sort_order'     => $q->sort_order,
                'options'        => $q->options,
            ];
        }

        $json = (string)wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $filename = sprintf('questions-%s-%s.json', sanitize_title($quiz->title) ?: 'quiz', date('Y-m-d'));

        if ($do_exit) {
            header('Content-Type: application/json; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Pragma: no-cache');
            header('Expires: 0');
            echo $json;
            exit;
        }

        return $json;
    }
}
