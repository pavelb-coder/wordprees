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
     *     passed: bool,
     *     status: string,
     *     has_essay: bool,
     *     responses: array<int, array>
     * }
     */
    public static function grade(Quiz $quiz, Attempt $attempt, array $user_answers): array
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

            $total_score += (int)$eval['awarded_points'];

            $responses[] = [
                'attempt_id'          => $attempt->id,
                'question_id'         => $q->id,
                'user_answer'         => is_array($raw_answer) ? wp_json_encode($raw_answer, JSON_UNESCAPED_UNICODE) : (string)($raw_answer ?? ''),
                'is_correct'          => $eval['is_correct'],
                'awarded_points'      => (int)$eval['awarded_points'],
                'needs_manual_review' => $eval['needs_manual_review'] ? 1 : 0,
                'review_comment'      => '',
            ];
        }

        // Score cannot drop below zero
        $total_score = max(0, $total_score);
        $max_score = max(0, $max_score);
        $percentage = ($max_score > 0) ? round(($total_score / $max_score) * 100, 2) : 0.0;

        $pass_percentage = (float)$quiz->get_setting('pass_percentage', 60.0);
        $passed = ($percentage >= $pass_percentage);

        $status = $has_essay ? 'needs_review' : 'completed';

        return [
            'total_score'         => $total_score,
            'max_score'           => $max_score,
            'percentage'          => $percentage,
            'passed'              => $passed,
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

            case 'essay':
                return [
                    'awarded_points'      => 0,
                    'is_correct'          => null,
                    'needs_manual_review' => true,
                ];

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
            return ['awarded_points' => 0, 'is_correct' => 0, 'needs_manual_review' => false];
        }

        $answers = $q->options['answers'] ?? [];
        $case_sensitive = !empty($q->options['case_sensitive']);

        $matched = false;
        foreach ((array)$answers as $valid) {
            $valid = trim((string)$valid);
            if ($case_sensitive) {
                if (hash_equals($valid, $input)) {
                    $matched = true;
                    break;
                }
            } else {
                if (mb_strtolower($valid, 'UTF-8') === mb_strtolower($input, 'UTF-8')) {
                    $matched = true;
                    break;
                }
            }
        }

        if ($matched) {
            return ['awarded_points' => (int)$q->points, 'is_correct' => 1, 'needs_manual_review' => false];
        }

        $penalty = (int)$q->penalty > 0 ? -(int)$q->penalty : 0;
        return ['awarded_points' => $penalty, 'is_correct' => 0, 'needs_manual_review' => false];
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

        if ($correct_blanks === $total_blanks) {
            return ['awarded_points' => (int)$q->points, 'is_correct' => 1, 'needs_manual_review' => false];
        }

        if ($correct_blanks > 0) {
            $fraction = $correct_blanks / $total_blanks;
            $points = (int)round($fraction * (int)$q->points);
            return ['awarded_points' => $points, 'is_correct' => 0, 'needs_manual_review' => false];
        }

        $penalty = (int)$q->penalty > 0 ? -(int)$q->penalty : 0;
        return ['awarded_points' => $penalty, 'is_correct' => 0, 'needs_manual_review' => false];
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
}
