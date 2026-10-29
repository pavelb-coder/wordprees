<?php
declare(strict_types=1);

namespace WPOlympiad;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Olympiad Grading & Evaluation Engine
 */
final class Grader
{
    /**
     * Grade an entire attempt given submitted answers
     *
     * @param Quiz $quiz
     * @param Attempt $attempt
     * @param array<int|string, mixed> $user_answers [ question_id => answer_value ]
     * @return array{
     *     total_score: float,
     *     max_score: float,
     *     percentage: float,
     *     status: string,
     *     has_essay: bool,
     *     responses: array<int, array>
     * }
     */
    public static function grade(Quiz $quiz, Attempt $attempt, array $user_answers, array $locked_questions = []): array
    {
        $questions = $quiz->get_questions(false);
        $total_score = 0;
        $max_score = 0;
        $has_essay = false;
        $responses = [];

        foreach ($questions as $q) {
            $max_score += (int)$q->points;
            $raw_answer = $user_answers[$q->id] ?? null;

            $eval = self::grade_question($q, $raw_answer);
            if ($eval['needs_manual_review']) {
                $has_essay = true;
            }

            // Время группы вышло: ответ сохранён, но не оценивается —
            // организатор решает сам на странице проверки работы.
            if (isset($locked_questions[$q->id]) && $raw_answer !== null && $raw_answer !== '' && $raw_answer !== []) {
                $group_label = (string)$locked_questions[$q->id];
                $eval['awarded_points'] = 0;
                $eval['is_correct'] = null;
                $eval['needs_manual_review'] = 1;
                $note = $group_label !== ''
                    ? sprintf(/* translators: %s: group name */ __('Время на группу «%s» истекло — ответ сохранён, но не оценивается.', 'wp-olympiad'), $group_label)
                    : __('Время на группу истекло — ответ сохранён, но не оценивается.', 'wp-olympiad');
                $existing = trim((string)($eval['review_comment'] ?? ''));
                $eval['review_comment'] = $existing !== '' ? $existing . ' ' . $note : $note;
                $has_essay = true;
            }

            $total_score += (int)$eval['awarded_points'];

            $responses[] = [
                'attempt_id'          => $attempt->id,
                'question_id'         => $q->id,
                'user_answer'         => is_array($raw_answer) ? wp_json_encode($raw_answer, JSON_UNESCAPED_UNICODE) : (string)($raw_answer ?? ''),
                'is_correct'          => $eval['is_correct'],
                'awarded_points'      => (int)$eval['awarded_points'],
                'needs_manual_review' => $eval['needs_manual_review'] ? 1 : 0,
                'review_comment'      => (string)($eval['review_comment'] ?? ''),
            ];
        }

        // Score cannot drop below zero
        $total_score = max(0, $total_score);
        $max_score = max(0, $max_score);
        $percentage = ($max_score > 0) ? round(($total_score / $max_score) * 100, 2) : 0.0;


        $status = $has_essay ? 'needs_review' : 'completed';

        return [
            'total_score'         => $total_score,
            'max_score'           => $max_score,
            'percentage'          => $percentage,
            'status'              => $status,
            'has_essay'           => $has_essay,
            'responses'           => $responses,
        ];
    }

    /**
     * Save evaluated responses to the database in an optimized single batch query
     */
    public static function save_responses(int $attempt_id, array $responses): void
    {
        if (empty($responses)) {
            return;
        }

        global $wpdb;
        $table = DB::responses_table();

        // Remove any prior answers for this attempt (idempotency)
        $wpdb->delete($table, ['attempt_id' => $attempt_id], ['%d']);

        $placeholders = [];
        $values = [];

        foreach ($responses as $resp) {
            $placeholders[] = '(%d, %d, %s, %s, %d, %d, %s)';
            $values[] = $attempt_id;
            $values[] = (int)$resp['question_id'];
            $values[] = (string)$resp['user_answer'];
            $values[] = $resp['is_correct'] === null ? null : (int)$resp['is_correct'];
            $values[] = (int)$resp['awarded_points'];
            $values[] = (int)$resp['needs_manual_review'];
            $values[] = (string)($resp['review_comment'] ?? '');
        }

        $query = "INSERT INTO {$table} (attempt_id, question_id, user_answer, is_correct, awarded_points, needs_manual_review, review_comment) VALUES " . implode(', ', $placeholders);
        $wpdb->query($wpdb->prepare($query, ...$values));
    }

    /**
     * Evaluate a single question
     *
     * @return array{
     *     awarded_points: float,
     *     is_correct: ?int,
     *     needs_manual_review: bool
     * }
     */
    public static function grade_question(Question $question, mixed $user_answer): array
    {
        switch ($question->question_type) {
            case 'single':
            case 'dropdown':
            case 'boolean':
                return self::grade_single($question, $user_answer);

            case 'multi':
                return self::grade_multi($question, $user_answer);

            case 'number':
                return self::grade_number($question, $user_answer);

            case 'text':
                return self::grade_text($question, $user_answer);

            case 'matching':
                return self::grade_matching($question, $user_answer);

            case 'ordering':
                return self::grade_ordering($question, $user_answer);

            case 'fill_blank':
                return self::grade_fill_blank($question, $user_answer);

            case 'slider':
                return self::grade_slider($question, $user_answer);

            case 'word_scramble':
                return self::grade_word_scramble($question, $user_answer);

            case 'dictation':
                return self::grade_dictation($question, $user_answer);

            case 'essay':
                return self::grade_essay($question, $user_answer);

            default:
                return [
                    'awarded_points'      => 0,
                    'is_correct'          => 0,
                    'needs_manual_review' => false,
                ];
        }
    }

    private static function grade_single(Question $q, mixed $user_answer): array
    {
        $selected = is_scalar($user_answer) ? trim((string)$user_answer) : '';
        if ($selected === '') {
            return ['awarded_points' => 0, 'is_correct' => 0, 'needs_manual_review' => false];
        }

        // 1. Check if individual choice points are defined
        $has_choice_points = false;
        foreach ($q->options['choices'] ?? [] as $choice) {
            if (isset($choice['points']) && $choice['points'] !== null) {
                $has_choice_points = true;
                break;
            }
        }

        if ($has_choice_points) {
            $awarded = 0;
            $is_corr = 0;
            foreach ($q->options['choices'] ?? [] as $choice) {
                if ((string)$choice['id'] === $selected) {
                    $ch_pts = (int)round((float)($choice['points'] ?? 0));
                    $awarded = $ch_pts;
                    $is_corr = !empty($choice['is_correct']) || ($ch_pts > 0 && $ch_pts === (int)$q->points) ? 1 : 0;
                    break;
                }
            }
            return ['awarded_points' => $awarded, 'is_correct' => $is_corr, 'needs_manual_review' => false];
        }

        // 2. Standard single choice grading
        $correct_id = null;
        foreach ($q->options['choices'] ?? [] as $choice) {
            if (!empty($choice['is_correct'])) {
                $correct_id = (string)$choice['id'];
                break;
            }
        }

        if ($correct_id !== null && hash_equals($correct_id, $selected)) {
            return ['awarded_points' => (int)$q->points, 'is_correct' => 1, 'needs_manual_review' => false];
        }

        $penalty = (int)$q->penalty > 0 ? -(int)$q->penalty : 0;
        return ['awarded_points' => $penalty, 'is_correct' => 0, 'needs_manual_review' => false];
    }

    private static function grade_multi(Question $q, mixed $user_answer): array
    {
        $selected = is_array($user_answer) ? array_map('strval', $user_answer) : [];
        $selected = array_values(array_filter(array_map('trim', $selected)));

        // 1. Check if individual choice points are defined
        $has_choice_points = false;
        foreach ($q->options['choices'] ?? [] as $choice) {
            if (isset($choice['points']) && $choice['points'] !== null) {
                $has_choice_points = true;
                break;
            }
        }

        if ($has_choice_points) {
            $total_pts = 0;
            $choices_map = [];
            foreach ($q->options['choices'] ?? [] as $choice) {
                $choices_map[(string)$choice['id']] = $choice;
            }

            foreach ($selected as $sel_id) {
                if (isset($choices_map[$sel_id])) {
                    $total_pts += (int)round((float)($choices_map[$sel_id]['points'] ?? 0));
                }
            }

            $max_possible = 0;
            foreach ($choices_map as $ch) {
                $p = (int)round((float)($ch['points'] ?? 0));
                if ($p > 0) {
                    $max_possible += $p;
                }
            }

            $is_corr = ($max_possible > 0 && $total_pts === $max_possible) ? 1 : 0;
            return [
                'awarded_points'      => $total_pts,
                'is_correct'          => $is_corr,
                'needs_manual_review' => false,
            ];
        }

        // 2. Standard multi choice proportional grading
        $correct_ids = [];
        $total_choices = count($q->options['choices'] ?? []);

        foreach ($q->options['choices'] ?? [] as $choice) {
            if (!empty($choice['is_correct'])) {
                $correct_ids[] = (string)$choice['id'];
            }
        }

        if (empty($correct_ids)) {
            return ['awarded_points' => 0, 'is_correct' => 0, 'needs_manual_review' => false];
        }

        $correct_selected = count(array_intersect($selected, $correct_ids));
        $wrong_selected = count(array_diff($selected, $correct_ids));
        $total_correct = count($correct_ids);

        // Strict academic olympiad partial scoring: (correct - wrong) / total_correct
        $fraction = max(0.0, ($correct_selected - $wrong_selected) / $total_correct);
        $points = (int)round($fraction * (int)$q->points);

        $is_correct = ($correct_selected === $total_correct && $wrong_selected === 0) ? 1 : 0;

        return [
            'awarded_points'      => $points,
            'is_correct'          => $is_correct,
            'needs_manual_review' => false,
        ];
    }

    private static function grade_number(Question $q, mixed $user_answer): array
    {
        if ($user_answer === null || $user_answer === '') {
            return ['awarded_points' => 0, 'is_correct' => 0, 'needs_manual_review' => false];
        }

        // Replace comma with dot for Russian decimal input (e.g. "3,14" -> "3.14")
        $clean = str_replace([' ', ','], ['', '.'], (string)$user_answer);
        if (!is_numeric($clean)) {
            $penalty = (int)$q->penalty > 0 ? -(int)$q->penalty : 0;
            return ['awarded_points' => $penalty, 'is_correct' => 0, 'needs_manual_review' => false];
        }

        $val = (float)$clean;
        $target = (float)($q->options['target_value'] ?? 0.0);
        $tolerance = max(0.0, (float)($q->options['tolerance'] ?? 0.0));

        if (abs($val - $target) <= ($tolerance + 1e-9)) {
            return ['awarded_points' => (int)$q->points, 'is_correct' => 1, 'needs_manual_review' => false];
        }

        $penalty = (int)$q->penalty > 0 ? -(int)$q->penalty : 0;
        return ['awarded_points' => $penalty, 'is_correct' => 0, 'needs_manual_review' => false];
    }

    private static function grade_text(Question $q, mixed $user_answer): array
    {
        $input = is_scalar($user_answer) ? trim((string)$user_answer) : '';
        if ($input === '') {
            $penalty = (int)$q->penalty > 0 ? -(int)$q->penalty : 0;
            return [
                'awarded_points'      => $penalty,
                'is_correct'          => 0,
                'needs_manual_review' => false,
                'review_comment'      => __('Ответ не предоставлен', 'wp-olympiad'),
            ];
        }

        $options = $q->options;
        $answers = (array)($options['answers'] ?? []);
        $match_mode = $options['match_mode'] ?? 'exact';
        $case_sensitive = !empty($options['case_sensitive']);
        $ignore_punct = isset($options['ignore_punct']) ? !empty($options['ignore_punct']) : true;
        $ignore_whitespace = isset($options['ignore_whitespace']) ? !empty($options['ignore_whitespace']) : true;
        $typo_tolerance = max(0, min(10, (int)($options['typo_tolerance'] ?? 1)));

        $norm_input = self::normalize_text($input, $case_sensitive, $ignore_punct, $ignore_whitespace);

        $matched = false;
        $match_reason = '';

        if (empty($answers)) {
            return [
                'awarded_points'      => 0,
                'is_correct'          => 0,
                'needs_manual_review' => false,
                'review_comment'      => __('Ключ ответа не настроен', 'wp-olympiad'),
            ];
        }

        switch ($match_mode) {
            case 'exact':
                foreach ($answers as $valid) {
                    $norm_valid = self::normalize_text((string)$valid, $case_sensitive, $ignore_punct, $ignore_whitespace);
                    if ($norm_input === $norm_valid) {
                        $matched = true;
                        $match_reason = sprintf(__('Точное совпадение с «%s»', 'wp-olympiad'), $valid);
                        break;
                    }
                }
                break;

            case 'contains_any':
                foreach ($answers as $valid) {
                    $norm_valid = self::normalize_text((string)$valid, $case_sensitive, $ignore_punct, $ignore_whitespace);
                    if ($norm_valid !== '' && self::text_contains_term($norm_input, $norm_valid)) {
                        $matched = true;
                        $match_reason = sprintf(__('Найдено совпадение по фразе «%s»', 'wp-olympiad'), $valid);
                        break;
                    }
                }
                break;

            case 'contains_all':
                $all_found = true;
                $found_list = [];
                foreach ($answers as $valid) {
                    $norm_valid = self::normalize_text((string)$valid, $case_sensitive, $ignore_punct, $ignore_whitespace);
                    if ($norm_valid !== '' && self::text_contains_term($norm_input, $norm_valid)) {
                        $found_list[] = $valid;
                    } else {
                        $all_found = false;
                    }
                }
                if ($all_found && !empty($answers)) {
                    $matched = true;
                    $match_reason = sprintf(__('Найдены все требуемые фразы (%d из %d)', 'wp-olympiad'), count($found_list), count($answers));
                } else {
                    $match_reason = sprintf(__('Найдено фраз: %d из %d', 'wp-olympiad'), count($found_list), count($answers));
                }
                break;

            case 'fuzzy':
                $best_dist = 999;
                $best_match = '';
                foreach ($answers as $valid) {
                    $norm_valid = self::normalize_text((string)$valid, $case_sensitive, $ignore_punct, $ignore_whitespace);
                    $dist = self::mb_levenshtein($norm_input, $norm_valid);
                    if ($dist < $best_dist) {
                        $best_dist = $dist;
                        $best_match = (string)$valid;
                    }

                    // Also check word-level match if input has multiple words
                    $words = preg_split('/\s+/u', $norm_input, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                    foreach ($words as $w) {
                        $w_dist = self::mb_levenshtein($w, $norm_valid);
                        if ($w_dist < $best_dist) {
                            $best_dist = $w_dist;
                            $best_match = (string)$valid;
                        }
                    }
                }

                if ($best_dist <= $typo_tolerance) {
                    $matched = true;
                    $match_reason = sprintf(__('Совпадение с «%s» (опечаток: %d, допуск: %d)', 'wp-olympiad'), $best_match, $best_dist, $typo_tolerance);
                } else {
                    $match_reason = sprintf(__('Ближайший ключ: «%s» (опечаток: %d, превышает допуск %d)', 'wp-olympiad'), $best_match, $best_dist, $typo_tolerance);
                }
                break;

            case 'regex':
                foreach ($answers as $pattern) {
                    $raw_pattern = trim((string)$pattern);
                    if ($raw_pattern === '') {
                        continue;
                    }
                    if (!preg_match('/^(\/|#|~).*(\/|#|~)[imsxuADSUXJ]*$/', $raw_pattern)) {
                        $flags = $case_sensitive ? 'u' : 'iu';
                        $regex = '/' . str_replace('/', '\/', $raw_pattern) . '/' . $flags;
                    } else {
                        $regex = $raw_pattern;
                    }
                    if (@preg_match($regex, $input)) {
                        $matched = true;
                        $match_reason = sprintf(__('Совпадение по регулярному выражению «%s»', 'wp-olympiad'), $pattern);
                        break;
                    }
                }
                break;
        }

        if ($matched) {
            return [
                'awarded_points'      => (int)$q->points,
                'is_correct'          => 1,
                'needs_manual_review' => false,
                'review_comment'      => '✓ ' . $match_reason,
            ];
        }

        $penalty = (int)$q->penalty > 0 ? -(int)$q->penalty : 0;
        return [
            'awarded_points'      => $penalty,
            'is_correct'          => 0,
            'needs_manual_review' => false,
            'review_comment'      => $match_reason ?: __('Неверный ответ', 'wp-olympiad'),
        ];
    }

    private static function grade_matching(Question $q, mixed $user_answer): array
    {
        $pairs = $q->options['pairs'] ?? [];
        $total_pairs = count($pairs);
        if ($total_pairs === 0) {
            return ['awarded_points' => 0, 'is_correct' => 0, 'needs_manual_review' => false];
        }

        if (is_string($user_answer)) {
            $decoded = json_decode($user_answer, true);
            $answers = is_array($decoded) ? $decoded : [];
        } elseif (is_array($user_answer)) {
            $answers = $user_answer;
        } else {
            $answers = [];
        }

        // 1. Check if individual pair points are defined
        $has_pair_points = false;
        foreach ($pairs as $pair) {
            if (isset($pair['points']) && $pair['points'] !== null) {
                $has_pair_points = true;
                break;
            }
        }

        if ($has_pair_points) {
            $awarded = 0;
            $all_correct = true;
            foreach ($pairs as $pair) {
                $pid = $pair['id'];
                $expected = trim((string)$pair['right']);
                $submitted = isset($answers[$pid]) ? trim((string)$answers[$pid]) : '';
                $is_p_correct = ($submitted !== '' && mb_strtolower($submitted, 'UTF-8') === mb_strtolower($expected, 'UTF-8'));
                if ($is_p_correct) {
                    $awarded += (int)round((float)($pair['points'] ?? 0));
                } else {
                    $all_correct = false;
                }
            }
            return [
                'awarded_points'      => $awarded,
                'is_correct'          => $all_correct ? 1 : 0,
                'needs_manual_review' => false,
            ];
        }

        $correct_count = 0;
        foreach ($pairs as $pair) {
            $pid = $pair['id'];
            $expected = trim((string)$pair['right']);
            $submitted = isset($answers[$pid]) ? trim((string)$answers[$pid]) : '';

            if ($submitted !== '' && mb_strtolower($submitted, 'UTF-8') === mb_strtolower($expected, 'UTF-8')) {
                $correct_count++;
            }
        }

        if ($correct_count === $total_pairs) {
            return ['awarded_points' => (int)$q->points, 'is_correct' => 1, 'needs_manual_review' => false];
        }

        if ($correct_count > 0) {
            $fraction = $correct_count / $total_pairs;
            $points = (int)round($fraction * (int)$q->points);
            return ['awarded_points' => $points, 'is_correct' => 0, 'needs_manual_review' => false];
        }

        $penalty = (int)$q->penalty > 0 ? -(int)$q->penalty : 0;
        return ['awarded_points' => $penalty, 'is_correct' => 0, 'needs_manual_review' => false];
    }

    private static function grade_ordering(Question $q, mixed $user_answer): array
    {
        $items = $q->options['items'] ?? [];
        $total_items = count($items);
        if ($total_items === 0) {
            return ['awarded_points' => 0, 'is_correct' => 0, 'needs_manual_review' => false];
        }

        if (is_string($user_answer)) {
            $decoded = json_decode($user_answer, true);
            $order = is_array($decoded) ? $decoded : [];
        } elseif (is_array($user_answer)) {
            $order = $user_answer;
        } else {
            $order = [];
        }

        $correct_order_ids = array_column($items, 'id');
        $submitted_ids = array_values(array_map('strval', $order));

        // 1. Check if individual item points are defined
        $has_item_points = false;
        foreach ($items as $item) {
            if (isset($item['points']) && $item['points'] !== null) {
                $has_item_points = true;
                break;
            }
        }

        if ($has_item_points) {
            $awarded = 0;
            $all_correct = true;
            for ($i = 0; $i < $total_items; $i++) {
                if (isset($submitted_ids[$i]) && $submitted_ids[$i] === $correct_order_ids[$i]) {
                    $awarded += (int)round((float)($items[$i]['points'] ?? 0));
                } else {
                    $all_correct = false;
                }
            }
            return [
                'awarded_points'      => $awarded,
                'is_correct'          => $all_correct ? 1 : 0,
                'needs_manual_review' => false,
            ];
        }

        $matched_positions = 0;
        for ($i = 0; $i < $total_items; $i++) {
            if (isset($submitted_ids[$i]) && $submitted_ids[$i] === $correct_order_ids[$i]) {
                $matched_positions++;
            }
        }

        if ($matched_positions === $total_items) {
            return ['awarded_points' => (int)$q->points, 'is_correct' => 1, 'needs_manual_review' => false];
        }

        if ($matched_positions > 0) {
            $fraction = $matched_positions / $total_items;
            $points = (int)round($fraction * (int)$q->points);
            return ['awarded_points' => $points, 'is_correct' => 0, 'needs_manual_review' => false];
        }

        $penalty = (int)$q->penalty > 0 ? -(int)$q->penalty : 0;
        return ['awarded_points' => $penalty, 'is_correct' => 0, 'needs_manual_review' => false];
    }

    private static function grade_fill_blank(Question $q, mixed $user_answer): array
    {
        $blanks = $q->options['blanks'] ?? [];
        $total_blanks = count($blanks);
        if ($total_blanks === 0) {
            return ['awarded_points' => 0, 'is_correct' => 0, 'needs_manual_review' => false];
        }

        if (is_string($user_answer)) {
            $decoded = json_decode($user_answer, true);
            $submitted = is_array($decoded) ? $decoded : [];
        } elseif (is_array($user_answer)) {
            $submitted = $user_answer;
        } else {
            $submitted = [];
        }

        $case_sensitive = !empty($q->options['case_sensitive']);
        $correct_blanks = 0;

        foreach ($blanks as $b) {
            $bid = $b['id'];
            $ans_given = isset($submitted[$bid]) ? trim((string)$submitted[$bid]) : '';
            if ($ans_given === '') {
                continue;
            }

            $valid_answers = (array)($b['answers'] ?? []);
            $is_match = false;
            foreach ($valid_answers as $va) {
                $va = trim((string)$va);
                if ($case_sensitive) {
                    if (hash_equals($va, $ans_given)) {
                        $is_match = true;
                        break;
                    }
                } else {
                    if (mb_strtolower($va, 'UTF-8') === mb_strtolower($ans_given, 'UTF-8')) {
                        $is_match = true;
                        break;
                    }
                }
            }

            if ($is_match) {
                $correct_blanks++;
            }
        }

        $comment = sprintf(
            __('Заполнение пропусков: верных ответов %d из %d (%d%%)', 'wp-olympiad'),
            $correct_blanks,
            $total_blanks,
            round(($correct_blanks / $total_blanks) * 100)
        );

        if ($correct_blanks === $total_blanks) {
            return [
                'awarded_points'      => (int)$q->points,
                'is_correct'          => 1,
                'needs_manual_review' => false,
                'review_comment'      => '✓ ' . $comment,
            ];
        }

        if ($correct_blanks > 0) {
            $fraction = $correct_blanks / $total_blanks;
            $points = (int)round($fraction * (int)$q->points);
            return [
                'awarded_points'      => $points,
                'is_correct'          => 0,
                'needs_manual_review' => false,
                'review_comment'      => $comment,
            ];
        }

        $penalty = (int)$q->penalty > 0 ? -(int)$q->penalty : 0;
        return [
            'awarded_points'      => $penalty,
            'is_correct'          => 0,
            'needs_manual_review' => false,
            'review_comment'      => $comment,
        ];
    }

    private static function grade_slider(Question $q, mixed $user_answer): array
    {
        if ($user_answer === null || $user_answer === '') {
            return ['awarded_points' => 0, 'is_correct' => 0, 'needs_manual_review' => false];
        }

        $clean = str_replace([' ', ','], ['', '.'], (string)$user_answer);
        if (!is_numeric($clean)) {
            $penalty = (int)$q->penalty > 0 ? -(int)$q->penalty : 0;
            return ['awarded_points' => $penalty, 'is_correct' => 0, 'needs_manual_review' => false];
        }

        $val = (float)$clean;
        $target = (float)($q->options['target_value'] ?? 50.0);
        $tolerance = max(0.0, (float)($q->options['tolerance'] ?? 0.0));

        if (abs($val - $target) <= ($tolerance + 1e-9)) {
            return ['awarded_points' => (int)$q->points, 'is_correct' => 1, 'needs_manual_review' => false];
        }

        $penalty = (int)$q->penalty > 0 ? -(int)$q->penalty : 0;
        return ['awarded_points' => $penalty, 'is_correct' => 0, 'needs_manual_review' => false];
    }

    private static function grade_word_scramble(Question $q, mixed $user_answer): array
    {
        if (is_array($user_answer)) {
            $assembled = '';
            foreach ($user_answer as $val) {
                if (is_array($val) && isset($val['char'])) {
                    $assembled .= (string)$val['char'];
                } elseif (is_scalar($val)) {
                    $assembled .= (string)$val;
                }
            }
            $input = $assembled;
        } else {
            $input = (string)($user_answer ?? '');
        }

        $clean_input = (string)preg_replace('/[^\p{L}\p{N}]/u', '', $input);
        if ($clean_input === '') {
            $penalty = (int)$q->penalty > 0 ? -(int)$q->penalty : 0;
            return [
                'awarded_points'      => $penalty,
                'is_correct'          => 0,
                'needs_manual_review' => false,
                'review_comment'      => __('Слово не составлено', 'wp-olympiad'),
            ];
        }

        $options = $q->options;
        $words = (array)($options['words'] ?? []);
        if (empty($words) && !empty($options['target_word'])) {
            $words = [(string)$options['target_word']];
        }
        $case_sensitive = !empty($options['case_sensitive']);

        $matched = false;
        $matched_target = '';

        foreach ($words as $target) {
            $clean_target = (string)preg_replace('/[^\p{L}\p{N}]/u', '', (string)$target);
            if ($case_sensitive) {
                if ($clean_input === $clean_target) {
                    $matched = true;
                    $matched_target = (string)$target;
                    break;
                }
            } else {
                if (mb_strtolower($clean_input, 'UTF-8') === mb_strtolower($clean_target, 'UTF-8')) {
                    $matched = true;
                    $matched_target = (string)$target;
                    break;
                }
            }
        }

        if ($matched) {
            return [
                'awarded_points'      => (int)$q->points,
                'is_correct'          => 1,
                'needs_manual_review' => false,
                'review_comment'      => sprintf(__('✓ Верно составлено слово: «%s»', 'wp-olympiad'), $matched_target),
            ];
        }

        $penalty = (int)$q->penalty > 0 ? -(int)$q->penalty : 0;
        return [
            'awarded_points'      => $penalty,
            'is_correct'          => 0,
            'needs_manual_review' => false,
            'review_comment'      => sprintf(__('Неверно составлено слово (введено: «%s», ожидалось: «%s»)', 'wp-olympiad'), $clean_input, implode('» / «', $words)),
        ];
    }

    private static function grade_dictation(Question $q, mixed $user_answer): array
    {
        $gaps = (array)($q->options['gaps'] ?? []);
        $total_gaps = count($gaps);
        if ($total_gaps === 0) {
            return [
                'awarded_points'      => 0,
                'is_correct'          => 0,
                'needs_manual_review' => false,
                'review_comment'      => __('В диктанте не заданы орфограммы', 'wp-olympiad'),
            ];
        }

        if (is_string($user_answer)) {
            $decoded = json_decode($user_answer, true);
            $answers = is_array($decoded) ? $decoded : [];
        } elseif (is_array($user_answer)) {
            $answers = $user_answer;
        } else {
            $answers = [];
        }

        $correct_count = 0;
        $mistakes = [];

        foreach ($gaps as $gid => $gap) {
            $expected = trim((string)($gap['correct'] ?? ''));
            $submitted = isset($answers[$gid]) ? trim((string)$answers[$gid]) : '';

            $is_gap_correct = (mb_strtolower($submitted, 'UTF-8') === mb_strtolower($expected, 'UTF-8'));
            if ($is_gap_correct && $submitted !== '') {
                $correct_count++;
            } else {
                $mistakes[] = sprintf('[%s]: «%s» (ожидалось «%s»)', $gid, $submitted ?: '—', $expected);
            }
        }

        $scoring_mode = $q->options['scoring_mode'] ?? 'proportional';
        $allowed_mistakes = max(0, (int)($q->options['allowed_mistakes'] ?? 0));
        $max_points = (int)$q->points;

        $mistakes_count = $total_gaps - $correct_count;
        $effective_mistakes = max(0, $mistakes_count - $allowed_mistakes);

        if ($scoring_mode === 'all_or_nothing') {
            $awarded = ($effective_mistakes === 0) ? $max_points : 0;
        } else {
            // Proportional
            $effective_correct = max(0, $total_gaps - $effective_mistakes);
            $fraction = $effective_correct / $total_gaps;
            $awarded = (int)round($fraction * $max_points);
        }

        $all_correct = ($mistakes_count === 0);

        $comment_parts = [];
        $comment_parts[] = sprintf(
            __('Диктант: верных орфограмм %d из %d (%d%%)', 'wp-olympiad'),
            $correct_count,
            $total_gaps,
            round(($correct_count / $total_gaps) * 100)
        );
        if (!empty($mistakes) && count($mistakes) <= 5) {
            $comment_parts[] = __('Ошибки:', 'wp-olympiad') . ' ' . implode(', ', $mistakes);
        }

        $comment = implode('. ', $comment_parts);

        return [
            'awarded_points'      => $awarded,
            'is_correct'          => $all_correct ? 1 : ($awarded > 0 ? null : 0),
            'needs_manual_review' => false,
            'review_comment'      => ($all_correct ? '✓ ' : '') . $comment,
        ];
    }

    private static function grade_essay(Question $q, mixed $user_answer): array
    {
        $options = $q->options;
        $auto_check = !empty($options['auto_check']);

        if (!$auto_check) {
            return [
                'awarded_points'      => 0,
                'is_correct'          => null,
                'needs_manual_review' => true,
                'review_comment'      => '',
            ];
        }

        $input = is_scalar($user_answer) ? trim((string)$user_answer) : '';
        if ($input === '') {
            return [
                'awarded_points'      => 0,
                'is_correct'          => 0,
                'needs_manual_review' => false,
                'review_comment'      => __('Ответ не предоставлен', 'wp-olympiad'),
            ];
        }

        $keywords = (array)($options['keywords'] ?? []);
        $case_sensitive = !empty($options['case_sensitive']);
        $ignore_punct = isset($options['ignore_punct']) ? !empty($options['ignore_punct']) : true;
        $scoring_mode = $options['scoring_mode'] ?? 'proportional';
        $min_keywords = max(0, (int)($options['min_keywords'] ?? 0));
        $min_words = max(0, (int)($options['min_words'] ?? 0));
        $auto_pass = !empty($options['auto_pass']);

        $norm_input = self::normalize_text($input, $case_sensitive, $ignore_punct, true);

        // Word count check
        $word_count = count(preg_split('/\s+/u', $norm_input, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $length_ok = ($min_words === 0 || $word_count >= $min_words);

        // Keyword scan
        $found_keywords = [];
        $missing_keywords = [];

        foreach ($keywords as $kw) {
            $kw_clean = trim((string)$kw);
            if ($kw_clean === '') {
                continue;
            }
            $norm_kw = self::normalize_text($kw_clean, $case_sensitive, $ignore_punct, true);
            $matched_kw = ($norm_kw !== '' && self::text_contains_term($norm_input, $norm_kw));

            if ($matched_kw) {
                $found_keywords[] = $kw_clean;
            } else {
                $missing_keywords[] = $kw_clean;
            }
        }

        $total_kw = count($keywords);
        $threshold = ($min_keywords > 0) ? $min_keywords : max(1, $total_kw);
        $meets_keyword_criteria = (count($found_keywords) >= $threshold) && $length_ok;

        $max_points = (int)$q->points;
        $awarded = 0;

        if ($scoring_mode === 'all_or_nothing') {
            $awarded = $meets_keyword_criteria ? $max_points : 0;
        } else {
            // Proportional
            if ($total_kw > 0) {
                $ratio = min(1.0, count($found_keywords) / $total_kw);
                $awarded = (int)round($max_points * $ratio);
            } else {
                $awarded = $length_ok ? $max_points : 0;
            }
            if (!$length_ok) {
                $awarded = 0;
            }
        }

        $comment_parts = [];
        $comment_parts[] = sprintf(
            __('Автопроверка: найдено ключевых понятий: %d из %d', 'wp-olympiad'),
            count($found_keywords),
            $total_kw
        );
        if (!empty($found_keywords)) {
            $comment_parts[] = sprintf(__('Встретились: «%s»', 'wp-olympiad'), implode('», «', $found_keywords));
        }
        if (!empty($missing_keywords) && count($missing_keywords) <= 5) {
            $comment_parts[] = sprintf(__('Не найдены: «%s»', 'wp-olympiad'), implode('», «', $missing_keywords));
        }
        if ($min_words > 0) {
            $comment_parts[] = sprintf(__('Объем текста: %d слов (мин. %d)', 'wp-olympiad'), $word_count, $min_words);
        }

        $comment = implode('. ', $comment_parts);

        if ($auto_pass) {
            return [
                'awarded_points'      => $awarded,
                'is_correct'          => ($awarded > 0) ? 1 : 0,
                'needs_manual_review' => false,
                'review_comment'      => $comment,
            ];
        }

        return [
            'awarded_points'      => $awarded,
            'is_correct'          => ($awarded > 0) ? 1 : 0,
            'needs_manual_review' => true,
            'review_comment'      => $comment,
        ];
    }

    public static function normalize_text(string $text, bool $case_sensitive = false, bool $ignore_punct = true, bool $ignore_whitespace = true): string
    {
        if (!$case_sensitive) {
            $text = mb_strtolower($text, 'UTF-8');
        }
        if ($ignore_punct) {
            $text = (string)preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        }
        if ($ignore_whitespace) {
            $text = (string)preg_replace('/\s+/u', ' ', $text);
        }
        return trim($text);
    }

    public static function mb_levenshtein(string $str1, string $str2): int
    {
        $str1 = trim($str1);
        $str2 = trim($str2);
        if ($str1 === $str2) {
            return 0;
        }
        $chars1 = preg_split('//u', $str1, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $chars2 = preg_split('//u', $str2, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $len1 = count($chars1);
        $len2 = count($chars2);
        if ($len1 === 0) {
            return $len2;
        }
        if ($len2 === 0) {
            return $len1;
        }

        $v0 = range(0, $len2);
        $v1 = array_fill(0, $len2 + 1, 0);

        for ($i = 0; $i < $len1; $i++) {
            $v1[0] = $i + 1;
            for ($j = 0; $j < $len2; $j++) {
                $cost = ($chars1[$i] === $chars2[$j]) ? 0 : 1;
                $v1[$j + 1] = min(
                    $v1[$j] + 1,
                    $v0[$j + 1] + 1,
                    $v0[$j] + $cost
                );
            }
            $v0 = $v1;
        }

        return $v0[$len2];
    }

    public static function text_contains_term(string $norm_text, string $norm_term): bool
    {
        $norm_term = trim($norm_term);
        if ($norm_term === '') {
            return false;
        }
        if (mb_strpos($norm_text, $norm_term) !== false) {
            return true;
        }

        // Check Russian word inflection / stems & word-level fuzzy matching
        if (mb_strpos($norm_term, ' ') === false) {
            $words = preg_split('/\s+/u', $norm_text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $stem = (string)preg_replace('/[аеёиоуыэюяйьъ]+$/u', '', $norm_term);
            $stem_len = mb_strlen($stem, 'UTF-8');
            foreach ($words as $w) {
                if ($stem !== '' && $stem_len >= 3 && mb_strpos($w, $stem) === 0) {
                    return true;
                }
                if (abs(mb_strlen($w, 'UTF-8') - mb_strlen($norm_term, 'UTF-8')) <= 2) {
                    if (self::mb_levenshtein($w, $norm_term) <= 1) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    public static function auto_check_answer_data(Question $q, mixed $user_answer): array
    {
        if ($q->question_type === 'text') {
            $eval = self::grade_text($q, $user_answer);
        } elseif ($q->question_type === 'essay') {
            // If keywords exist, enforce auto_check for inspection
            if (empty($q->options['auto_check']) && !empty($q->options['keywords'])) {
                $q_clone = clone $q;
                $opts = $q_clone->options;
                $opts['auto_check'] = 1;
                $q_clone->options = $opts;
                $eval = self::grade_essay($q_clone, $user_answer);
            } else {
                $eval = self::grade_essay($q, $user_answer);
            }
        } else {
            $eval = self::grade_question($q, $user_answer);
        }

        return [
            'recommended_points' => max(0, (int)($eval['awarded_points'] ?? 0)),
            'max_points'         => (int)$q->points,
            'is_correct'         => !empty($eval['is_correct']) ? 1 : 0,
            'comment'            => (string)($eval['review_comment'] ?? ''),
            'status_label'       => !empty($eval['is_correct']) ? __('Верно', 'wp-olympiad') : __('Неверно / частично', 'wp-olympiad'),
        ];
    }
}
