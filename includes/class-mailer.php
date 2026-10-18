<?php
declare(strict_types=1);
namespace WPOlympiad;
if (!defined('ABSPATH')) { exit; }

/**
 * Compatibility shim for integrations calling the former mail dispatcher.
 * Olympiad result/admin notifications are retired, regardless of legacy flags.
 * No global wp_mail filter is installed; normal WordPress mail is unaffected.
 */
final class Mailer
{
    public static function send_attempt_emails(Quiz $quiz, Attempt $attempt): void
    {
        // Intentionally no-op. Do not revive notifications from cached settings.
    }
}
