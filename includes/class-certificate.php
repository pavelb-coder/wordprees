<?php
declare(strict_types=1);

namespace WPOlympiad;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Olympiad Diploma & Certificate Generator
 */
final class Certificate
{
    /**
     * Handle certificate view request via template_redirect
     */
    public static function handle_request(): void
    {
        $token = sanitize_text_field($_GET['wpo_certificate'] ?? '');
        if (empty($token)) {
            return;
        }

        $attempt = Attempt::get_by_token($token);
        if (!$attempt || !in_array($attempt->status, ['completed', 'graded'], true)) {
            wp_die(esc_html__('Сертификат не найден или работа ещё не завершена.', 'wp-olympiad'), '', ['response' => 404]);
        }

        $quiz = Quiz::get($attempt->quiz_id);
        if (!$quiz) {
            wp_die(esc_html__('Олимпиада не найдена.', 'wp-olympiad'), '', ['response' => 404]);
        }

        self::render_html($quiz, $attempt);
        exit;
    }

    /**
     * Get public URL for a participant's certificate
     */
    public static function get_url(string $token): string
    {
        return add_query_arg(['wpo_certificate' => $token], home_url('/'));
    }

    /**
     * Render standalone printable diploma page
     */
    public static function render_html(Quiz $quiz, Attempt $attempt): void
    {
        $grade_info = $quiz->get_grade_info($attempt->percentage);
        $degree = $grade_info['label'] ?: ($attempt->passed ? __('Успешно пройдено', 'wp-olympiad') : __('Сертификат участника', 'wp-olympiad'));
        $date_str = !empty($attempt->submitted_at) ? date_i18n('d.m.Y', strtotime($attempt->submitted_at)) : date_i18n('d.m.Y');
        $cert_id = 'DPL-' . strtoupper(substr(md5($attempt->token), 0, 8));

        ?>
        <!DOCTYPE html>
        <html lang="ru">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php echo esc_html($degree); ?> — <?php echo esc_html($attempt->participant_name); ?></title>
            <style>
                @page {
                    size: A4 landscape;
                    margin: 8mm;
                }
                * {
                    box-sizing: border-box;
                }
                body {
                    margin: 0;
                    padding: 20px;
                    background: #f8fafc;
                    font-family: -apple-system, BlinkMacSystemFont, "Georgia", "Times New Roman", serif;
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    justify-content: center;
                    min-height: 100vh;
                }
                .wpo-cert-toolbar {
                    margin-bottom: 20px;
                    display: flex;
                    gap: 12px;
                }
                .wpo-btn-print {
                    background: #2563eb;
                    color: #fff;
                    border: none;
                    padding: 10px 24px;
                    font-size: 15px;
                    font-weight: 600;
                    border-radius: 6px;
                    cursor: pointer;
                    font-family: sans-serif;
                }
                .wpo-btn-print:hover {
                    background: #1d4ed8;
                }
                .wpo-cert-card {
                    width: 280mm;
                    height: 195mm;
                    max-width: 100%;
                    background: #ffffff;
                    border: 8px solid #1e3a8a;
                    border-radius: 8px;
                    padding: 14mm;
                    position: relative;
                    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
                    display: flex;
                    flex-direction: column;
                    justify-content: space-between;
                    text-align: center;
                }
                .wpo-cert-inner-border {
                    border: 2px solid #b45309;
                    height: 100%;
                    padding: 12mm 18mm;
                    display: flex;
                    flex-direction: column;
                    justify-content: space-between;
                    background: radial-gradient(circle, #ffffff 60%, #fffbeb 100%);
                }
                .wpo-cert-header {
                    margin-bottom: 5mm;
                }
                .wpo-cert-emblem {
                    font-size: 40px;
                    line-height: 1;
                    margin-bottom: 2mm;
                }
                .wpo-cert-type {
                    font-size: 32px;
                    font-weight: 800;
                    letter-spacing: 4px;
                    color: #1e3a8a;
                    text-transform: uppercase;
                    margin: 0;
                }
                .wpo-cert-subtitle {
                    font-size: 15px;
                    font-style: italic;
                    color: #78350f;
                    margin-top: 2mm;
                    font-family: sans-serif;
                }
                .wpo-cert-name {
                    font-size: 30px;
                    font-weight: 700;
                    color: #0f172a;
                    margin: 4mm 0;
                    border-bottom: 2px solid #cbd5e1;
                    padding-bottom: 2mm;
                    display: inline-block;
                }
                .wpo-cert-org {
                    font-size: 16px;
                    color: #475569;
                    margin-bottom: 4mm;
                    font-family: sans-serif;
                }
                .wpo-cert-degree {
                    font-size: 24px;
                    font-weight: 700;
                    color: #b45309;
                    margin-bottom: 3mm;
                }
                .wpo-cert-quiz-title {
                    font-size: 18px;
                    font-weight: 600;
                    color: #1e293b;
                    margin-bottom: 4mm;
                }
                .wpo-cert-score {
                    font-size: 15px;
                    color: #334155;
                    font-family: sans-serif;
                }
                .wpo-cert-footer {
                    display: flex;
                    justify-content: space-between;
                    align-items: flex-end;
                    border-top: 1px solid #e2e8f0;
                    padding-top: 4mm;
                    font-family: sans-serif;
                    font-size: 13px;
                    color: #64748b;
                }
                .wpo-cert-seal {
                    font-size: 32px;
                }
                @media print {
                    body {
                        background: none;
                        padding: 0;
                    }
                    .wpo-cert-toolbar {
                        display: none !important;
                    }
                    .wpo-cert-card {
                        box-shadow: none;
                        border-width: 6px;
                        width: 100%;
                        height: 100vh;
                    }
                }
            </style>
        </head>
        <body>
            <div class="wpo-cert-toolbar">
                <button type="button" class="wpo-btn-print" onclick="window.print();">
                    🖨️ Распечатать / Сохранить в PDF
                </button>
            </div>

            <div class="wpo-cert-card">
                <div class="wpo-cert-inner-border">
                    <div class="wpo-cert-header">
                        <div class="wpo-cert-emblem">🏛️</div>
                        <h1 class="wpo-cert-type"><?php esc_html_e('Диплом', 'wp-olympiad'); ?></h1>
                        <div class="wpo-cert-subtitle"><?php esc_html_e('Настоящим подтверждается, что', 'wp-olympiad'); ?></div>
                    </div>

                    <div class="wpo-cert-body">
                        <div class="wpo-cert-name"><?php echo esc_html($attempt->participant_name); ?></div>
                        <?php if ($attempt->participant_org): ?>
                            <div class="wpo-cert-org"><?php echo esc_html($attempt->participant_org); ?></div>
                        <?php endif; ?>

                        <div class="wpo-cert-degree"><?php echo esc_html($degree); ?></div>
                        <div class="wpo-cert-quiz-title"><?php echo esc_html($quiz->title); ?></div>

                        <div class="wpo-cert-score">
                            <?php echo sprintf(
                                esc_html__('Результат: %d из %d баллов (%d%%) • Время решения: %02d:%02d', 'wp-olympiad'),
                                round($attempt->score),
                                round($attempt->max_score),
                                round($attempt->percentage),
                                floor($attempt->duration_seconds / 60),
                                $attempt->duration_seconds % 60
                            ); ?>
                        </div>
                    </div>

                    <div class="wpo-cert-footer">
                        <div>
                            <div><strong><?php esc_html_e('Дата выдачи:', 'wp-olympiad'); ?></strong> <?php echo esc_html($date_str); ?></div>
                            <div style="margin-top:2px; font-size:11px;"><?php esc_html_e('Номер документа:', 'wp-olympiad'); ?> <code><?php echo esc_html($cert_id); ?></code></div>
                        </div>
                        <div class="wpo-cert-seal">🏅</div>
                        <div style="text-align:right;">
                            <div><strong><?php esc_html_e('Организационный комитет', 'wp-olympiad'); ?></strong></div>
                            <div style="font-size:11px;"><?php echo esc_html(get_bloginfo('name')); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </body>
        </html>
        <?php
    }
}
