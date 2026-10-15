<?php
declare(strict_types=1);

namespace WPOlympiad;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Olympiad Analytics & Psychometric Item Analysis Engine
 * Features analogous to Online Test Pad:
 * - Item Response & Difficulty Index (P-value)
 * - Discrimination Index (D-index / Kelley's 27% upper/lower groups)
 * - Distractor Analysis & Frequency distribution
 * - Score Distribution Histograms & Descriptive Statistics (Mean, Median, StdDev)
 * - Pivot Matrix of All Participants x Questions with formatting
 */
final class Stats
{
    /**
     * Get aggregate statistics and per-question item analysis for a quiz
     */
    public static function get_quiz_stats(int $quiz_id): array
    {
        global $wpdb;
        $attempts_table = DB::attempts_table();
        $questions_table = DB::questions_table();
        $responses_table = DB::responses_table();

        $quiz = Quiz::get($quiz_id);
        if (!$quiz) {
            return [];
        }

        // 1. Summary metrics
        $summary = $wpdb->get_row($wpdb->prepare("
            SELECT
                COUNT(*) as total_attempts,
                SUM(CASE WHEN status IN ('completed', 'graded') THEN 1 ELSE 0 END) as completed_attempts,
                AVG(CASE WHEN status IN ('completed', 'graded') THEN score ELSE NULL END) as avg_score,
                MAX(score) as max_achieved,
                MIN(CASE WHEN status IN ('completed', 'graded') THEN score ELSE NULL END) as min_achieved,
                AVG(CASE WHEN status IN ('completed', 'graded') THEN percentage ELSE NULL END) as avg_percentage,
                SUM(CASE WHEN passed = 1 THEN 1 ELSE 0 END) as passed_count,
                AVG(CASE WHEN status IN ('completed', 'graded') THEN duration_seconds ELSE NULL END) as avg_duration,
                SUM(tab_switches) as total_tab_switches
            FROM {$attempts_table}
            WHERE quiz_id = %d
        ", $quiz_id), ARRAY_A);

        $total_completed = (int)($summary['completed_attempts'] ?? 0);
        $passed_count = (int)($summary['passed_count'] ?? 0);
        $pass_rate = $total_completed > 0 ? round(($passed_count / $total_completed) * 100, 1) : 0.0;

        // 2. Fetch completed attempts sorted by score for psychometric metrics (Kelley's 27% groups)
        $completed_attempts = $wpdb->get_results($wpdb->prepare("
            SELECT id, score, percentage
            FROM {$attempts_table}
            WHERE quiz_id = %d AND status IN ('completed', 'graded')
            ORDER BY score DESC
        ", $quiz_id), ARRAY_A);

        $scores = array_map(fn($row) => (float)$row['score'], $completed_attempts);
        $percentages = array_map(fn($row) => (float)$row['percentage'], $completed_attempts);

        $median_score = self::calculate_median($scores);
        $std_dev = self::calculate_std_dev($scores);

        // 3. Score Distribution Buckets (0-20%, 21-40%, 41-60%, 61-80%, 81-100%)
        $distribution = [
            '0-20'   => 0,
            '21-40'  => 0,
            '41-60'  => 0,
            '61-80'  => 0,
            '81-100' => 0,
        ];
        foreach ($percentages as $pct) {
            if ($pct <= 20.0) {
                $distribution['0-20']++;
            } elseif ($pct <= 40.0) {
                $distribution['21-40']++;
            } elseif ($pct <= 60.0) {
                $distribution['41-60']++;
            } elseif ($pct <= 80.0) {
                $distribution['61-80']++;
            } else {
                $distribution['81-100']++;
            }
        }

        // Upper and lower groups for Discrimination Index (Kelley's 27% rule)
        $group_size = max(1, (int)round($total_completed * 0.27));
        $upper_attempt_ids = array_slice(array_column($completed_attempts, 'id'), 0, $group_size);
        $lower_attempt_ids = array_slice(array_column($completed_attempts, 'id'), -$group_size);

        // 4. Fetch all responses for completed attempts
        $all_responses = $wpdb->get_results($wpdb->prepare("
            SELECT r.attempt_id, r.question_id, r.user_answer, r.is_correct, r.awarded_points
            FROM {$responses_table} r
            INNER JOIN {$attempts_table} a ON r.attempt_id = a.id
            WHERE a.quiz_id = %d AND a.status IN ('completed', 'graded')
        ", $quiz_id), ARRAY_A);

        $responses_by_q = [];
        foreach ($all_responses as $r) {
            $responses_by_q[(int)$r['question_id']][] = $r;
        }

        // 5. Per-question item analysis
        $questions = $quiz->get_questions(false);
        $item_analysis = [];

        foreach ($questions as $q) {
            $q_resp = $responses_by_q[$q->id] ?? [];
            $resp_count = count($q_resp);
            $correct_count = 0;
            $total_points_awarded = 0.0;

            $upper_correct = 0;
            $lower_correct = 0;

            // Distractor / frequency tracker
            $choice_counts = [];
            $raw_answers_counts = [];

            foreach ($q_resp as $r) {
                $att_id = (int)$r['attempt_id'];
                $is_corr = ((int)$r['is_correct'] === 1);
                $pts = (float)$r['awarded_points'];

                if ($is_corr) {
                    $correct_count++;
                }
                $total_points_awarded += $pts;

                // Kelley's Discrimination counting
                if (in_array($att_id, $upper_attempt_ids, true) && $is_corr) {
                    $upper_correct++;
                }
                if (in_array($att_id, $lower_attempt_ids, true) && $is_corr) {
                    $lower_correct++;
                }

                // Answer frequency for distractor analysis
                $raw_ans = trim((string)$r['user_answer']);
                if ($raw_ans !== '') {
                    if (in_array($q->question_type, ['single', 'dropdown', 'boolean'], true)) {
                        $choice_counts[$raw_ans] = ($choice_counts[$raw_ans] ?? 0) + 1;
                    } elseif ($q->question_type === 'multi') {
                        $decoded = json_decode($raw_ans, true);
                        if (is_array($decoded)) {
                            foreach ($decoded as $c_id) {
                                $c_str = (string)$c_id;
                                $choice_counts[$c_str] = ($choice_counts[$c_str] ?? 0) + 1;
                            }
                        }
                    } else {
                        // Open / text / number / essay answers
                        $key = mb_substr($raw_ans, 0, 50, 'UTF-8');
                        $raw_answers_counts[$key] = ($raw_answers_counts[$key] ?? 0) + 1;
                    }
                }
            }

            $accuracy = $resp_count > 0 ? round(($correct_count / $resp_count) * 100, 1) : 0.0;
            $avg_points = $resp_count > 0 ? round($total_points_awarded / $resp_count, 2) : 0.0;

            // Difficulty Index (P): percentage of max points earned on average
            $difficulty_index = $q->points > 0 ? round(($avg_points / $q->points) * 100, 1) : 0.0;
            $difficulty_label = 'optimal';
            if ($difficulty_index < 35.0) {
                $difficulty_label = 'hard';
            } elseif ($difficulty_index > 80.0) {
                $difficulty_label = 'easy';
            }

            // Discrimination Index (D = (UpperCorrect - LowerCorrect) / GroupSize)
            $d_index = 0.0;
            $d_status = 'fair';
            if ($group_size > 0 && $resp_count > 0) {
                $d_index = round(($upper_correct - $lower_correct) / $group_size, 2);
                if ($d_index >= 0.40) {
                    $d_status = 'excellent';
                } elseif ($d_index >= 0.30) {
                    $d_status = 'good';
                } elseif ($d_index >= 0.20) {
                    $d_status = 'fair';
                } elseif ($d_index >= 0.00) {
                    $d_status = 'poor';
                } else {
                    $d_status = 'negative'; // Alert: flawed question!
                }
            }

            // Distractor analysis details
            $distractors = [];
            if (in_array($q->question_type, ['single', 'multi', 'dropdown', 'boolean'], true)) {
                $choices = $q->options['choices'] ?? [];
                if ($q->question_type === 'boolean') {
                    $choices = [
                        ['id' => 'true', 'text' => __('Верно / Да', 'wp-olympiad'), 'is_correct' => ($q->options['choices'][0]['is_correct'] ?? 1)],
                        ['id' => 'false', 'text' => __('Неверно / Нет', 'wp-olympiad'), 'is_correct' => ($q->options['choices'][1]['is_correct'] ?? 0)],
                    ];
                }

                foreach ($choices as $ch) {
                    $cid = (string)($ch['id'] ?? '');
                    $ch_count = (int)($choice_counts[$cid] ?? 0);
                    $ch_pct = $resp_count > 0 ? round(($ch_count / $resp_count) * 100, 1) : 0.0;

                    $distractors[] = [
                        'id'         => $cid,
                        'text'       => $ch['text'] ?? $cid,
                        'is_correct' => !empty($ch['is_correct']),
                        'count'      => $ch_count,
                        'percentage' => $ch_pct,
                    ];
                }
            }

            // Top frequent open answers
            arsort($raw_answers_counts);
            $top_answers = [];
            foreach (array_slice($raw_answers_counts, 0, 5, true) as $ans_text => $cnt) {
                $top_answers[] = [
                    'answer'     => $ans_text,
                    'count'      => $cnt,
                    'percentage' => $resp_count > 0 ? round(($cnt / $resp_count) * 100, 1) : 0.0,
                ];
            }

            $item_analysis[] = [
                'id'                   => $q->id,
                'title'                => $q->title,
                'type'                 => $q->question_type,
                'max_points'           => (int)round($q->points),
                'responses'            => $resp_count,
                'correct'              => $correct_count,
                'accuracy'             => $accuracy,
                'avg_points'           => $avg_points,
                'difficulty_index'     => $difficulty_index,
                'difficulty_label'     => $difficulty_label,
                'discrimination_index' => $d_index,
                'discrimination_status'=> $d_status,
                'distractors'          => $distractors,
                'top_answers'          => $top_answers,
            ];
        }

        return [
            'total_attempts'      => (int)($summary['total_attempts'] ?? 0),
            'completed_attempts'  => $total_completed,
            'passed_count'        => $passed_count,
            'pass_rate'           => $pass_rate,
            'avg_score'           => round((float)($summary['avg_score'] ?? 0.0), 2),
            'median_score'        => $median_score,
            'std_dev'             => $std_dev,
            'max_achieved'        => (int)round((float)($summary['max_achieved'] ?? 0)),
            'min_achieved'        => (int)round((float)($summary['min_achieved'] ?? 0)),
            'avg_percentage'      => round((float)($summary['avg_percentage'] ?? 0.0), 1),
            'avg_duration_seconds'=> (int)round((float)($summary['avg_duration'] ?? 0)),
            'total_tab_switches'  => (int)($summary['total_tab_switches'] ?? 0),
            'distribution'        => $distribution,
            'questions'           => $item_analysis,
        ];
    }

    /**
     * Build Pivot Matrix of all participants x questions (Online Test Pad feature)
     */
    public static function get_pivot_matrix(int $quiz_id): array
    {
        global $wpdb;
        $quiz = Quiz::get($quiz_id);
        if (!$quiz) {
            return ['questions' => [], 'attempts' => []];
        }

        $questions = $quiz->get_questions(false);
        $attempts_table = DB::attempts_table();
        $responses_table = DB::responses_table();

        $attempts = $wpdb->get_results($wpdb->prepare("
            SELECT id, participant_name, participant_email, participant_org,
                   started_at, submitted_at, duration_seconds, tab_switches,
                   score, max_score, percentage, passed, status
            FROM {$attempts_table}
            WHERE quiz_id = %d AND status IN ('completed', 'graded', 'needs_review')
            ORDER BY score DESC, duration_seconds ASC
        ", $quiz_id), ARRAY_A);

        if (empty($attempts)) {
            return ['questions' => $questions, 'attempts' => []];
        }

        $attempt_ids = array_column($attempts, 'id');
        $placeholders = implode(',', array_fill(0, count($attempt_ids), '%d'));

        $responses = $wpdb->get_results($wpdb->prepare("
            SELECT attempt_id, question_id, user_answer, is_correct, awarded_points, needs_manual_review, review_comment
            FROM {$responses_table}
            WHERE attempt_id IN ({$placeholders})
        ", ...$attempt_ids), ARRAY_A);

        $matrix = [];
        foreach ($responses as $r) {
            $att_id = (int)$r['attempt_id'];
            $q_id = (int)$r['question_id'];
            $matrix[$att_id][$q_id] = $r;
        }

        $q_map = [];
        foreach ($questions as $q) {
            $q_map[$q->id] = $q;
        }

        $formatted_attempts = [];
        foreach ($attempts as $att) {
            $att_id = (int)$att['id'];
            $user_responses = [];

            foreach ($questions as $q) {
                $raw = $matrix[$att_id][$q->id] ?? null;
                if ($raw) {
                    $user_responses[$q->id] = [
                        'user_answer'      => $raw['user_answer'],
                        'formatted_answer' => self::format_user_answer($q, $raw['user_answer']),
                        'awarded_points'   => (int)round((float)$raw['awarded_points']),
                        'is_correct'       => $raw['is_correct'] !== null ? (int)$raw['is_correct'] : null,
                        'needs_review'     => !empty($raw['needs_manual_review']),
                        'review_comment'   => $raw['review_comment'],
                    ];
                } else {
                    $user_responses[$q->id] = [
                        'user_answer'      => '',
                        'formatted_answer' => '—',
                        'awarded_points'   => 0,
                        'is_correct'       => 0,
                        'needs_review'     => false,
                        'review_comment'   => '',
                    ];
                }
            }

            $att['score'] = (int)round((float)$att['score']);
            $att['max_score'] = (int)round((float)$att['max_score']);
            $att['responses'] = $user_responses;
            $formatted_attempts[] = $att;
        }

        return [
            'questions' => $questions,
            'attempts'  => $formatted_attempts,
        ];
    }

    /**
     * Format raw user answer for readable display in pivot matrix & reports
     */
    public static function format_user_answer(Question $q, string $raw_answer): string
    {
        $raw = trim($raw_answer);
        if ($raw === '') {
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
            case 'number':
            case 'text':
            case 'essay':
            default:
                return $raw;
        }
    }

    private static function calculate_median(array $values): float
    {
        $count = count($values);
        if ($count === 0) {
            return 0.0;
        }
        sort($values);
        $middle = (int)floor($count / 2);
        if ($count % 2 === 1) {
            return (float)$values[$middle];
        }
        return (float)round(($values[$middle - 1] + $values[$middle]) / 2, 2);
    }

    private static function calculate_std_dev(array $values): float
    {
        $count = count($values);
        if ($count <= 1) {
            return 0.0;
        }
        $mean = array_sum($values) / $count;
        $sum_sq = 0.0;
        foreach ($values as $v) {
            $sum_sq += pow($v - $mean, 2);
        }
        return (float)round(sqrt($sum_sq / ($count - 1)), 2);
    }

    /**
     * Get comprehensive IP network monitoring and anti-cheat audit data
     *
     * @return array{
     *     total_unique_ips: int,
     *     shared_ips_count: int,
     *     total_ip_mismatches: int,
     *     rows: array
     * }
     */
    public static function get_ip_monitoring_data(int $quiz_id = 0): array
    {
        global $wpdb;
        $table = DB::attempts_table();

        $where = ["ip_address != ''"];
        $params = [];
        if ($quiz_id > 0) {
            $where[] = 'quiz_id = %d';
            $params[] = $quiz_id;
        }
        $where_sql = implode(' AND ', $where);

        $sql = "
            SELECT 
                ip_address,
                submitted_ip,
                COUNT(*) as attempts_count,
                COUNT(DISTINCT participant_email) as distinct_participants,
                GROUP_CONCAT(DISTINCT participant_name SEPARATOR ', ') as participant_names,
                GROUP_CONCAT(id SEPARATOR ',') as attempt_ids,
                AVG(score) as avg_score,
                MAX(score) as max_score,
                AVG(percentage) as avg_pct,
                MIN(started_at) as first_seen,
                MAX(submitted_at) as last_seen,
                SUM(CASE WHEN submitted_ip != '' AND submitted_ip != ip_address THEN 1 ELSE 0 END) as ip_mismatches
            FROM {$table}
            WHERE {$where_sql}
            GROUP BY ip_address
            ORDER BY attempts_count DESC, last_seen DESC
        ";

        $raw_rows = !empty($params) ? $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);

        $total_unique_ips = count($raw_rows);
        $shared_ips_count = 0;
        $total_ip_mismatches = 0;
        $rows = [];

        foreach ($raw_rows as $row) {
            $att_cnt = (int)$row['attempts_count'];
            $part_cnt = (int)$row['distinct_participants'];
            $mismatches = (int)$row['ip_mismatches'];
            $is_shared = ($att_cnt > 1 || $part_cnt > 1);
            $has_mismatch = ($mismatches > 0);

            if ($is_shared) {
                $shared_ips_count++;
            }
            if ($has_mismatch) {
                $total_ip_mismatches += $mismatches;
            }

            $rows[] = [
                'ip_address'            => (string)$row['ip_address'],
                'submitted_ip'          => (string)($row['submitted_ip'] ?? ''),
                'attempts_count'        => $att_cnt,
                'distinct_participants' => $part_cnt,
                'participant_names'     => (string)($row['participant_names'] ?? ''),
                'attempt_ids'           => array_map('intval', explode(',', (string)($row['attempt_ids'] ?? ''))),
                'avg_score'             => round((float)$row['avg_score'], 1),
                'max_score'             => (int)round((float)$row['max_score']),
                'avg_pct'               => round((float)$row['avg_pct'], 1),
                'first_seen'            => (string)($row['first_seen'] ?? ''),
                'last_seen'             => (string)($row['last_seen'] ?? ''),
                'ip_mismatches'         => $mismatches,
                'is_shared'             => $is_shared,
                'is_suspicious'         => ($is_shared || $has_mismatch),
            ];
        }

        return [
            'total_unique_ips'    => $total_unique_ips,
            'shared_ips_count'    => $shared_ips_count,
            'total_ip_mismatches' => $total_ip_mismatches,
            'rows'                => $rows,
        ];
    }
}
