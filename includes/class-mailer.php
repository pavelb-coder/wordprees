<?php
declare(strict_types=1);

namespace WPOlympiad;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Olympiad Email Notifications Dispatcher
 */
final class Mailer
{
    /**
     * Send email notifications on attempt submission
     */
    public static function send_attempt_emails(Quiz $quiz, Attempt $attempt): void
    {
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $grade_info = $quiz->get_grade_info($attempt->percentage);

        // 1. Participant Email
        $send_participant = (bool)$quiz->get_setting('email_participant', 1);
        if ($send_participant && !empty($attempt->participant_email)) {
            $subject = sprintf(__('Ваш результат в олимпиаде: %s', 'wp-olympiad'), $quiz->title);

            $message = '
                <div style="font-family:sans-serif; max-width:600px; margin:0 auto; padding:20px; border:1px solid #e2e8f0; border-radius:8px;">
                    <h2 style="color:#1e3a8a; margin-top:0;">' . esc_html($quiz->title) . '</h2>
                    <p>Здравствуйте, <strong>' . esc_html($attempt->participant_name) . '</strong>!</p>
                    <p>Вы завершили участие в олимпиаде. Ваши результаты:</p>
                    <div style="background:#f8fafc; padding:15px; border-radius:6px; margin:15px 0;">
                        <div><strong>Статус:</strong> ' . esc_html($grade_info['label']) . '</div>
                        <div><strong>Баллы:</strong> ' . sprintf('%d / %d (%d%%)', round($attempt->score), round($attempt->max_score), round($attempt->percentage)) . '</div>
                        <div><strong>Время выполнения:</strong> ' . sprintf('%02d:%02d', floor($attempt->duration_seconds / 60), $attempt->duration_seconds % 60) . '</div>
                    </div>
            ';

            if (!empty($grade_info['message'])) {
                $personalized = $quiz->replace_template_tags($grade_info['message'], $attempt);
                $message .= '<p style="font-style:italic; color:#334155;">' . wp_kses_post($personalized) . '</p>';
            }

            $message .= '
                    <hr style="border:none; border-top:1px solid #e2e8f0; margin:20px 0;">
                    <div style="font-size:12px; color:#94a3b8;">' . esc_html(get_bloginfo('name')) . '</div>
                </div>
            ';

            wp_mail($attempt->participant_email, $subject, $message, $headers);
        }

        // 2. Admin Notification
        $send_admin = (bool)$quiz->get_setting('email_admin', 0);
        $admin_email = (string)$quiz->get_setting('admin_email_to', '');
        if (empty($admin_email)) {
            $admin_email = get_option('admin_email');
        }

        if ($send_admin && !empty($admin_email)) {
            $admin_subject = sprintf(__('Новая сдача олимпиады: %s (%s)', 'wp-olympiad'), $attempt->participant_name, $quiz->title);
            $admin_body = sprintf(
                __("Участник %s (%s, %s) сдал олимпиаду «%s».\nБаллы: %.1f / %.1f (%d%%)\nНарушений: %d\nСтатус: %s", 'wp-olympiad'),
                $attempt->participant_name,
                $attempt->participant_org ?: '—',
                $attempt->participant_email,
                $quiz->title,
                $attempt->score,
                $attempt->max_score,
                round($attempt->percentage),
                $attempt->tab_switches,
                $attempt->status
            );
            wp_mail($admin_email, $admin_subject, nl2br(esc_html($admin_body)), $headers);
        }
    }
}
