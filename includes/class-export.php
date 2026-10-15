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

        // Header Row with Metadata and Question Columns
        $headers = [
            'ID попытки',
            'ФИО Участника',
            'Email',
            'Организация / Класс',
            'Дата старта',
            'Дата сдачи',
            'Затрачено времени (мин:сек)',
            'Нарушений (вкладки)',
            'Итого баллов',
            'Макс. балл',
            'Процент (%)',
            'Статус',
            'Результат',
        ];

        foreach ($questions as $idx => $q) {
            $q_num = $idx + 1;
            $q_title_short = mb_substr(strip_tags($q->title), 0, 45, 'UTF-8');
            $headers[] = sprintf('Вопрос %d: %s [Ответ]', $q_num, $q_title_short);
            $headers[] = sprintf('Вопрос %d: Балл (из %d)', $q_num, (int)$q->points);
        }

        fputcsv($output, $headers, ';', '"', "\\");

        $status_labels = [
            'in_progress'  => 'В процессе',
            'completed'    => 'Завершено',
            'needs_review' => 'На проверке',
            'expired'      => 'Истекло время',
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

            $status = $status_labels[$att->status] ?? $att->status;
            $result = $att->passed ? 'Зачёт / Пройдено' : 'Не зачёт';

            $row = [
                $att->id,
                $att->participant_name,
                $att->participant_email,
                $att->participant_org,
                $att->started_at,
                $att->submitted_at ?: '—',
                $duration_fmt,
                $att->tab_switches,
                number_format($att->score, 0, '.', ''),
                number_format($att->max_score, 0, '.', ''),
                number_format($att->percentage, 2, '.', ''),
                $status,
                $result,
            ];

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

        // Metadata Header Section
        fputcsv($output, ['ПРОТОКОЛ ПРОВЕРКИ РАБОТЫ УЧАСТНИКА'], ';', '"', "\\");
        fputcsv($output, ['Олимпиада', $quiz->title], ';', '"', "\\");
        fputcsv($output, ['Участник', $attempt->participant_name], ';', '"', "\\");
        fputcsv($output, ['Email', $attempt->participant_email], ';', '"', "\\");
        fputcsv($output, ['Организация / Класс', $attempt->participant_org ?: '—'], ';', '"', "\\");
        fputcsv($output, ['Дата старта', $attempt->started_at], ';', '"', "\\");
        fputcsv($output, ['Дата сдачи', $attempt->submitted_at ?: '—'], ';', '"', "\\");
        $duration_m = floor($attempt->duration_seconds / 60);
        $duration_s = $attempt->duration_seconds % 60;
        fputcsv($output, ['Время решения', sprintf('%02d:%02d', $duration_m, $duration_s)], ';', '"', "\\");
        fputcsv($output, ['Переключений вкладок', $attempt->tab_switches], ';', '"', "\\");
        fputcsv($output, ['Итоговый балл', sprintf('%d из %d (%d%%)', round($attempt->score), round($attempt->max_score), round($attempt->percentage))], ';', '"', "\\");
        fputcsv($output, ['Статус зачёта', $attempt->passed ? 'Зачёт' : 'Не зачёт'], ';', '"', "\\");
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

            case 'slider':
            case 'numeric':
            case 'short_text':
            case 'essay':
            default:
                return $raw;
        }
    }
}
