<?php
declare(strict_types=1);

namespace WPOlympiad;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Direct Network Folder & Storage Exporter
 *
 * Supports exporting olympiad submissions and answer protocols directly to:
 * 1. Local mounted network shares (NFS, CIFS/SMB, local NAS mounts, Windows shares);
 * 2. WebDAV cloud/network storage with HTTP Basic Authentication;
 * 3. FTP / FTPS network storage;
 * 4. Direct CSV protocols, question keys and individual attempts without archive compression.
 */
final class NetworkExport
{
    /**
     * Safely sanitize path while preserving Windows backslashes, drive letters, and UNC syntax
     */
    public static function sanitize_path(string $path): string
    {
        $path = trim($path, " \t\n\r\0\x0B\"'");
        // Strip null bytes and control chars, but keep Windows \ and Unix / slashes intact
        $path = str_replace(["\0", "\r", "\n"], '', $path);
        // If it looks like Windows UNC with multiple backslashes or single backslash, normalize UNC
        if (preg_match('#^[\\\\]{1,}[^\\\\]+[\\\\]#', $path)) {
            $path = '\\\\' . preg_replace('#[\\\\]+#', '\\', ltrim($path, '\\'));
        }
        return $path;
    }

    /** Recognize a copied multi-example hint, never a real /mnt/share or Василий path. */
    private static function is_example_path(string $path): bool
    {
        return preg_match('/\s(?:или|or)\s/u', $path) === 1
            && (str_contains($path, '/mnt/share') || str_contains($path, 'server\\share'));
    }

    /**
     * Get default network storage path in the WordPress uploads directory
     */
    public static function get_default_network_path(): string
    {
        $upload = wp_upload_dir();
        $path = wp_normalize_path(rtrim($upload['basedir'], '/\\') . '/olympiad_network_share');
        if (!is_dir($path)) {
            @wp_mkdir_p($path);
            @chmod($path, 0777);
        }
        return $path;
    }

    /**
     * Get public web URL for a file if it resides inside WordPress uploads directory
     */
    public static function get_file_url(string $file_path): ?string
    {
        $upload = wp_upload_dir();
        $basedir = wp_normalize_path($upload['basedir']);
        $norm_file = wp_normalize_path($file_path);

        if (str_starts_with($norm_file, $basedir)) {
            $rel = substr($norm_file, strlen($basedir));
            return rtrim($upload['baseurl'], '/') . '/' . ltrim($rel, '/');
        }

        return null;
    }

    /**
     * Export a quiz's answers directly to the configured network folder (no ZIP archive)
     *
     * @param int $quiz_id
     * @param array $override_config Optional overrides for path/credentials
     * @param bool $include_questions Deprecated since 1.0.16 and ignored: the questions
     *                                 export was removed, so no export writes «вопросы/».
     *                                 The argument is kept so old callers keep working.
     * @return array{
     *     success: bool,
     *     message: string,
     *     destination?: string,
     *     primary_file?: string,
     *     files_count?: int,
     *     files_list?: array<int, string>,
     *     questions_included?: bool,
     *     removed_question_files?: array<int, string>,
     *     questions_directory?: string,
     *     questions_notice?: string,
     *     download_url?: string,
     *     file_url?: string|null,
     *     size?: int
     * }
     */
    public static function export_quiz(int $quiz_id, array $override_config = [], bool $include_questions = false): array
    {
        $quiz = Quiz::get($quiz_id);
        if (!$quiz) {
            return [
                'success' => false,
                'message' => __('Олимпиада не найдена.', 'wp-olympiad'),
            ];
        }

        $config = self::get_quiz_network_config($quiz, $override_config);

        // Generate direct export files (no archive). Question files are no longer exported at all.
        $package = self::generate_export_files($quiz);
        if (!$package['success']) {
            return $package;
        }

        // Deliver files directly to the network destination
        $delivery = self::deliver_files($package['files'], $config);

        if (!$delivery['success']) {
            return $delivery;
        }

        // 1.0.16: the questions export is gone, so its leftovers are retired point by point.
        // Like the report folder this never fails a delivered export - it is reported instead.
        $questions_cleanup = QuestionExportCleanup::prune_after_export($config, $quiz);
        $questions_removed = $questions_cleanup['removed'] ?? [];
        $cleanup = [
            'removed' => array_values(array_filter(
                $questions_removed,
                static fn(string $rel): bool => in_array(basename($rel), ['questions.csv', 'questions.json'], true)
            )),
            'absent'  => $questions_cleanup['absent'] ?? [],
        ];
        $questions_notice = self::questions_cleanup_notice($questions_cleanup, count($questions_removed));

        // The user chose to retire only the three old report files after delivery.
        $report_cleanup = ReportExportCleanup::prune($config, $quiz);
        if (!$report_cleanup['success']) {
            $message = __('Файлы выгружены, но очистка старых отчётов не завершена. ', 'wp-olympiad') . $report_cleanup['message'];
            // Automatic submissions must not fail, but administrators must be able to see the cleanup error.
            set_transient('wpo_last_report_cleanup_error_' . $quiz_id, ['message' => $message, 'timestamp' => current_time('mysql')], DAY_IN_SECONDS * 30);
            return ['success' => false, 'message' => $message, 'report_cleanup' => $report_cleanup];
        }
        delete_transient('wpo_last_report_cleanup_error_' . $quiz_id);
        $reports_notice = match ($report_cleanup['directory']) {
            'not_empty' => __('Папка «отчёты/» сохранена: в ней остались другие файлы.', 'wp-olympiad'),
            'webdav_retained' => __('Папка «отчёты/» сохранена: WebDAV не поддерживает безопасное нерекурсивное удаление каталога.', 'wp-olympiad'),
            'retained' => __('Папка «отчёты/» сохранена: транспорт не подтвердил удаление пустого каталога, удалите его вручную при необходимости.', 'wp-olympiad'),
            'unverified' => __('Старые файлы отчётов не удалены: ', 'wp-olympiad')
                . ($report_cleanup['reason'] ?? __('транспорт не подтвердил безопасность удаления папки.', 'wp-olympiad'))
                . ' ' . __('На выгрузку ответов это не влияет; папку можно убрать вручную или подключить её как смонтированный каталог.', 'wp-olympiad'),
            default => '',
        };

        // Export-only connections also receive the editor, without affecting answer delivery.
        DesktopEditor::ensure_for_quiz($quiz, $override_config);

        // Compatibility hook; never delete untracked files from the connected root.
        // 1.0.32: вместе с протоколами в пакет кладутся и файлы участников —
        // иначе ссылки из CSV ведут в никуда. Ничего не удаляется: копируем найденное.
        $attachment_report = ['copied' => 0, 'missing' => 0, 'transport' => 'none', 'message' => ''];
        $attachment_dest = (string)($delivery['destination'] ?? '');
        // Путь назначения может быть недоступен как путь в ФС (SMB без монтирования,
        // WebDAV, FTP) — тогда export_to сам отдаёт файлы тем же транспортом.
        if ($attachment_dest !== '' || Attachments::mode($quiz) === 'spool') {
            $attachment_report = Attachments::export_to($quiz, Attachments::rows_for_quiz($quiz), $attachment_dest);
        }

        self::cleanup_loose_root_export_files($delivery['destination'] ?? '', $config);

        $download_url = wp_nonce_url(
            admin_url('admin.php?wpo_action=download_network_file&quiz_id=' . $quiz->id . '&file=' . urlencode($package['primary_filename'])),
            'wpo_download_network_' . $quiz->id
        );

        $file_url = self::get_file_url($delivery['destination'] . '/' . $package['primary_filename']);

        $result_data = [
            'success'      => true,
            'message'      => sprintf(
                __('Ответы олимпиады «%1$s» успешно экспортированы напрямую в сетевую папку: %2$s', 'wp-olympiad'),
                $quiz->title,
                $delivery['destination']
            ) . ' ' . __('Файлы заданий этой выгрузкой не создаются: экспорт вопросов удалён.', 'wp-olympiad')
              . ($questions_notice !== '' ? ' ' . $questions_notice : '')
              . ($reports_notice !== '' ? ' ' . $reports_notice : ''),
            'destination'  => $delivery['destination'],
            // 1.0.32: сколько файлов участников попало в пакет и сколько не нашлось.
            'attachments'  => $attachment_report,
            'questions_included' => false,
            'removed_question_files' => $questions_removed,
            'questions_directory' => (string) ($questions_cleanup['directory'] ?? ''),
            'questions_notice' => $questions_notice,
            'primary_file' => $package['primary_filename'],
            'removed_legacy_files' => $cleanup['removed'] ?? [],
            'removed_report_files' => $report_cleanup['removed'],
            'reports_directory' => $report_cleanup['directory'],
            'reports_notice' => $reports_notice,
            'files_count'  => count($package['files']),
            'files_list'   => array_keys($package['files']),
            'download_url' => $download_url,
            'file_url'     => $file_url,
            'size'         => $delivery['size'] ?? $package['size'],
            'timestamp'    => current_time('mysql'),
        ];

        // Save last export metadata in transient
        set_transient('wpo_last_network_export_' . $quiz_id, $result_data, DAY_IN_SECONDS * 30);

        return $result_data;
    }

    /**
     * What the questions retirement has to say about its outcome (never a failure).
     *
     * @param array<string, mixed> $cleanup
     */
    private static function questions_cleanup_notice(array $cleanup, int $removed_count): string
    {
        if (empty($cleanup['success'])) {
            return __('Очистка «вопросы/» не выполнена: ', 'wp-olympiad')
                . ($cleanup['message'] ?? __('транспорт не ответил.', 'wp-olympiad'))
                . ' ' . __('Файлы заданий не удалены, на выгрузку ответов это не влияет.', 'wp-olympiad');
        }
        $directory = (string) ($cleanup['directory'] ?? '');
        if ($directory === 'unverified') {
            return __('Старые файлы заданий не удалены: ', 'wp-olympiad')
                . ($cleanup['reason'] ?? __('транспорт не подтвердил безопасность удаления папки.', 'wp-olympiad'))
                . ' ' . __('На выгрузку ответов это не влияет; папку можно убрать вручную.', 'wp-olympiad');
        }
        if ($removed_count === 0) {
            return $directory === 'removed'
                ? __('Папка «вопросы/» пуста и убрана.', 'wp-olympiad')
                : '';
        }
        $notice = sprintf(
            _n('Старый файл заданий в «вопросы/» удалён (%d).', 'Старые файлы заданий в «вопросы/» удалены (%d).', $removed_count, 'wp-olympiad'),
            $removed_count
        );
        if ($directory === 'removed') {
            $notice .= ' ' . __('Папка «вопросы/» пуста и убрана.', 'wp-olympiad');
        } elseif ($directory === 'webdav_retained') {
            $notice .= ' ' . __('Папка «вопросы/» сохранена: WebDAV не поддерживает безопасное нерекурсивное удаление каталога.', 'wp-olympiad');
        } elseif (in_array($directory, ['not_empty', 'retained'], true)) {
            $notice .= ' ' . __('Папка «вопросы/» сохранена: в ней остались другие файлы.', 'wp-olympiad');
        }
        return $notice;
    }

    /**
     * Get consolidated network configuration from quiz settings
     *
     * @param Quiz $quiz
     * @param array $override
     * @return array{
     *     enabled: bool,
     *     type: string,
     *     path: string,
     *     user: string,
     *     password: string,
     *     auto_export: bool
     * }
     */
    public static function get_quiz_network_config(Quiz $quiz, array $override = []): array
    {
        $raw_path = trim((string)($override['path'] ?? $quiz->get_setting('network_folder_path', '')));
        $path = !empty($raw_path) ? $raw_path : self::get_default_network_path();

        return [
            'enabled'     => isset($override['enabled']) ? (bool)$override['enabled'] : (bool)$quiz->get_setting('enable_network_export', 0),
            'type'        => (string)($override['type'] ?? $quiz->get_setting('network_folder_type', 'auto')),
            'path'        => $path,
            'user'        => trim((string)($override['user'] ?? $quiz->get_setting('network_folder_user', ''))),
            'password'    => (string)($override['password'] ?? $quiz->get_setting('network_folder_password', '')),
            'auto_export' => isset($override['auto_export']) ? (bool)$override['auto_export'] : (bool)$quiz->get_setting('network_auto_export', 0),
        ];
    }

    /**
     * Test connection and write permissions to the network destination
     *
     * @param array $config
     * @return array{success: bool, message: string}
     */
    public static function test_connection(array $config): array
    {
        $path = trim((string)($config['path'] ?? ''));
        // Strip placeholder or documentation example path
        if (self::is_example_path($path)) {
            $path = '';
            $config['path'] = '';
        }
        if (empty($path)) {
            $path = self::get_default_network_path();
            $config['path'] = $path;
        }

        $type = self::detect_type($config);

        switch ($type) {
            case 'webdav':
                $res = self::test_webdav_connection($config);
                break;

            case 'ftp':
                $res = self::test_ftp_connection($config);
                break;

            case 'smb':
                $res = self::test_smb_connection($config);
                break;

            case 'filesystem':
            default:
                $res = self::test_filesystem_connection($config);
                break;
        }

        if (!empty($res['success'])) {
            $res['editor'] = DesktopEditor::deploy($config, true);
            $res['message'] = (string)($res['message'] ?? '') . ' ' . (string)($res['editor']['message'] ?? '');
        }
        return $res;
    }

    /**
     * Retained for callers of the former template-deployment API. Templates are
     * now downloaded explicitly; existing files in import/ are never removed.
     */
    public static function deploy_import_templates(array $config, ?string &$error_message = null): bool
    {
        $result = self::deploy_import_templates_result($config);
        $error_message = $result['message'];
        return false;
    }

    /** @return array{success: bool, message: string, files: array} */
    public static function deploy_import_templates_result(array $config): array
    {
        return [
            'success' => false,
            'message' => __('Размещение шаблонов в папку import отключено. Скачайте Excel-шаблон в разделе импорта заданий.', 'wp-olympiad'),
            'files' => [],
        ];
    }

    /**
     * Export quiz questions directly to the network folder in 'вопросы/' (CSV, JSON, keys)
     *
     * @deprecated 1.0.16 The questions export was removed from the plugin interface; answers
     *             and protocols no longer include «вопросы/». The method stays so that custom
     *             scripts and older integrations keep working, and it still retires only the
     *             two legacy aliases instead of the whole folder.
     *
     * @param Quiz $quiz
     * @param array $override
     * @return array{success: bool, message: string, destination?: string, files_count?: int, files?: array<string>}
     */
    public static function export_questions_to_network(Quiz $quiz, array $override = []): array
    {
        $config = self::get_quiz_network_config($quiz, $override);
        $safe_slug = self::build_safe_slug($quiz);
        $keys_csv = self::generate_questions_key_csv($quiz);
        $questions_csv = Export::export_quiz_questions_csv($quiz, false) ?? '';
        $questions_json = Export::export_quiz_questions_json($quiz, false) ?? '';

        $files = [
            'вопросы/список_заданий.csv'                                      => $keys_csv,
            'вопросы/правильные_ответы.csv'                                   => $keys_csv,
            sprintf('вопросы/критерии_и_ответы_%s.csv', $safe_slug)           => $keys_csv,
            sprintf('вопросы/вопросы_%s.csv', $safe_slug)                     => $questions_csv,
            sprintf('вопросы/вопросы_%s.json', $safe_slug)                    => $questions_json,
        ];

        $safe = QuestionExportCleanup::preflight($config, $files);
        if (!$safe['success']) return $safe;
        $res = self::deliver_files_public($files, $config);
        if ($res['success']) {
            $cleanup = QuestionExportCleanup::prune($config);
            if (!$cleanup['success']) return ['success' => false, 'message' => __('Файлы выгружены, но очистка старых имён не завершена. ', 'wp-olympiad') . $cleanup['message'], 'cleanup' => $cleanup];
            DesktopEditor::ensure_for_quiz($quiz, $override);
            $dest = $config['path'] ?: self::get_default_network_path();
            return [
                'success'     => true,
                'message'     => sprintf(__('Задания олимпиады успешно сохранены в сетевую папку «вопросы/» (%d файлов).', 'wp-olympiad'), count($files)) . ' ' . __('Старые questions.csv и questions.json в экспортной папке отсутствуют.', 'wp-olympiad'),
                'destination' => $dest,
                'files_count' => count($files),
                'files'       => array_keys($files),
                'removed_legacy_files' => $cleanup['removed'] ?? [],
            ];
        }

        return $res;
    }

    /**
     * Generate direct export files (CSV protocol and attempts).
     *
     * @param bool $include_questions Deprecated since 1.0.16 and ignored: «вопросы/» is no longer
     *             written by any export, so every package contains protocols and attempts only.
     *
     * @param Quiz $quiz
     * @return array{
     *     success: bool,
     *     message?: string,
     *     files: array<string, string>,
     *     primary_filename: string,
     *     safe_slug: string,
     *     size: int
     * }
     */
    public static function generate_export_files(Quiz $quiz, bool $include_questions = true): array
    {
        $quiz_id = $quiz->id;

        // 1. Generate full responses CSV protocol
        $protocol_csv = Export::export_quiz_attempts($quiz_id, false);
        if ($protocol_csv === null) {
            $protocol_csv = '';
        }

        // 2. Since 1.0.16 no question files belong to an export package («вопросы/» is retired instead).

        // 3. Name the primary file for this quiz.
        $safe_slug = self::build_safe_slug($quiz);

        $primary_file = sprintf('протоколы/протокол_%s.csv', $safe_slug);

        // 4. Detailed individual attempts breakdown in 'попытки/' and 'attempts/' folders
        $attempts = Attempt::get_all(['quiz_id' => $quiz_id, 'limit' => 500]);
        $attempt_files = [];
        foreach ($attempts as $att) {
            $att_content = Export::export_single_attempt($att->id, false);
            if ($att_content !== null) {
                $user_part = sanitize_file_name(remove_accents($att->participant_name ?: 'participant'));
                $user_part = preg_replace('/[^a-zA-Z0-9_\-]/', '', $user_part) ?: 'user';

                // Russian name for individual attempt file
                $clean_ru_name = preg_replace('/[^\p{Cyrillic}\p{Latin}0-9_\-]/u', '_', trim((string)$att->participant_name));
                $clean_ru_name = trim(preg_replace('/_+/', '_', $clean_ru_name), '_');
                if (empty($clean_ru_name)) {
                    $clean_ru_name = 'участник';
                }

                $attempt_files[sprintf('попытки/попытка_%d_%s.csv', $att->id, $clean_ru_name)] = $att_content;
                $attempt_files[sprintf('attempts/attempt_%d_%s.csv', $att->id, $user_part)] = $att_content;
            }
        }

        // Исключительно русскоязычные папки и названия файлов (протоколы, вопросы, попытки)
        $files = [
            // Папка «протоколы»
            $primary_file => $protocol_csv,
        ];

        // Индивидуальные работы участников только в папке «попытки»
        foreach ($attempts as $att) {
            $att_content = Export::export_single_attempt($att->id, false);
            if ($att_content !== null) {
                // Русскоязычное имя файла попытки
                $clean_ru_name = preg_replace('/[^\p{Cyrillic}\p{Latin}0-9_\-]/u', '_', trim((string)$att->participant_name));
                $clean_ru_name = trim(preg_replace('/_+/', '_', $clean_ru_name), '_');
                if (empty($clean_ru_name)) {
                    $clean_ru_name = 'участник';
                }

                $files[sprintf('попытки/попытка_%d_%s.csv', $att->id, $clean_ru_name)] = $att_content;
            }
        }

        $total_size = 0;
        foreach ($files as $content) {
            $total_size += strlen($content);
        }

        return [
            'success'          => true,
            'files'            => $files,
            'primary_filename' => $primary_file,
            'safe_slug'        => $safe_slug,
            'size'             => $total_size,
        ];
    }

    /**
     * Legacy cleanup entry point. Untracked shared files cannot safely be deleted.
     *
     * @param string $dest_dir
     * @param array $config
     * @return void
     */
    public static function cleanup_loose_root_export_files(string $dest_dir, array $config = []): void
    {
        // Kept for API compatibility. Without an ownership manifest a loose file
        // or an old English-named directory cannot safely be identified as ours.
        // New exports already use only their dedicated Russified subdirectories;
        // never delete arbitrary questions, drafts, answers or editor files here.
    }

    /**
     * Recursively delete directory
     */
    public static function delete_directory_recursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                self::delete_directory_recursive($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * Download an exported file directly to the admin browser
     *
     * @param int $quiz_id
     * @param string $filename
     */
    public static function download_exported_file(int $quiz_id, string $filename): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('У вас недостаточно прав для выполнения этой операции.', 'wp-olympiad'));
        }

        $quiz = Quiz::get($quiz_id);
        if (!$quiz) {
            wp_die(esc_html__('Олимпиада не найдена.', 'wp-olympiad'));
        }

        $package = self::generate_export_files($quiz);

        // If 'all', download as unencrypted zip archive
        if ($filename === 'all' || $filename === 'archive.zip') {
            $zip_name = sprintf('olympiad-export-%s-%s.zip', $package['safe_slug'], date('Y-m-d'));
            $zip_temp = wp_tempnam($zip_name);
            $zip = new \ZipArchive();
            if ($zip->open($zip_temp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true) {
                foreach ($package['files'] as $name => $content) {
                    $zip->addFromString($name, $content);
                }
                $zip->close();
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . $zip_name . '"');
                header('Content-Length: ' . filesize($zip_temp));
                header('Pragma: no-cache');
                header('Expires: 0');
                readfile($zip_temp);
                @unlink($zip_temp);
                exit;
            }
        }

        $content = null;
        if (isset($package['files'][$filename])) {
            $content = $package['files'][$filename];
        } else {
            // Check filesystem if exists
            $config = self::get_quiz_network_config($quiz);
            $dest_dir = self::resolve_local_path($config['path'] ?? '');
            $file_on_disk = rtrim($dest_dir, '/\\') . DIRECTORY_SEPARATOR . $filename;
            if (file_exists($file_on_disk)) {
                $content = file_get_contents($file_on_disk);
            }
        }

        if ($content === null) {
            wp_die(esc_html__('Запрошенный файл выгрузки не найден.', 'wp-olympiad'));
        }

        $clean_name = sanitize_file_name($filename);
        $mime = str_ends_with($clean_name, '.csv') ? 'text/csv; charset=UTF-8' : 'text/plain; charset=UTF-8';
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $clean_name . '"');
        header('Content-Length: ' . strlen($content));
        header('Pragma: no-cache');
        header('Expires: 0');
        echo $content;
        exit;
    }

    /**
     * Backwards-compatibility wrapper for package generation
     */
    public static function generate_export_package(Quiz $quiz): array
    {
        $files_res = self::generate_export_files($quiz);
        $files_res['temp_file'] = '';
        $files_res['filename'] = $files_res['primary_filename'];
        $files_res['protocol_csv'] = $files_res['files'][$files_res['primary_filename']] ?? '';
        $files_res['files_count'] = count($files_res['files']);

        return $files_res;
    }

    /**
     * Build clean ASCII slug for filenames
     */
    public static function build_safe_slug(Quiz $quiz): string
    {
        $raw = sanitize_file_name(remove_accents($quiz->title));
        $clean = preg_replace('/%[0-9a-f]{2}/i', '', $raw);
        $clean = preg_replace('/[^a-zA-Z0-9_\-]/', '', $clean ?? '');
        $clean = trim((string)$clean, '_-');

        return !empty($clean) ? $clean : 'olympiad-' . $quiz->id;
    }

    /**
     * Generate structured CSV of all questions, types and grading keys
     */
    public static function generate_questions_key_csv(Quiz $quiz): string
    {
        $output = fopen('php://temp', 'r+');
        if ($output === false) {
            return '';
        }

        fwrite($output, "\xEF\xBB\xBF"); // UTF-8 BOM

        fputcsv($output, ['СПИСОК ЗАДАНИЙ, КРИТЕРИИ ОЦЕНИВАНИЯ И ПРАВИЛЬНЫЕ ОТВЕТЫ'], ';', '"', "\\");
        fputcsv($output, ['Олимпиада:', $quiz->title], ';', '"', "\\");
        fputcsv($output, ['Дата генерации:', current_time('mysql')], ';', '"', "\\");
        fputcsv($output, [], ';', '"', "\\");

        fputcsv($output, [
            '№',
            'ID вопроса',
            'Название / Текст задания',
            'Тип задания',
            'Макс. балл',
            'Штрафной балл',
            'Правильный ответ / Критерии оценивания',
            'Пояснение / Комментарий жюри',
        ], ';', '"', "\\");

        $questions = $quiz->get_questions(false);
        $type_labels = [
            'single'     => 'Одиночный выбор',
            'multi'      => 'Множественный выбор',
            'dropdown'   => 'Выпадающий список',
            'boolean'    => 'Верно / Неверно',
            'numeric'    => 'Числовой ответ',
            'short_text' => 'Краткий текст',
            'matching'   => 'Сопоставление (Matching)',
            'ordering'   => 'Последовательность (Ordering)',
            'fill_blank' => 'Пропуски в тексте',
            'slider'     => 'Шкала / Ползунок',
            'word_scramble' => 'Слово из букв',
            'dictation'  => 'Интерактивный диктант',
            'file'       => 'Прикрепление файла',
            'essay'      => 'Развёрнутый ответ / Эссе',
        ];

        foreach ($questions as $idx => $q) {
            $key_str = self::format_question_key_summary($q);

            fputcsv($output, [
                $idx + 1,
                $q->id,
                $q->title,
                $type_labels[$q->question_type] ?? $q->question_type,
                number_format($q->points, 0, '.', ''),
                number_format($q->penalty, 0, '.', ''),
                $key_str,
                (string)($q->options['explanation'] ?? ''),
            ], ';', '"', "\\");
        }

        rewind($output);
        $content = (string)stream_get_contents($output);
        fclose($output);
        return $content;
    }

    /**
     * Format a human-readable correct answer key string for a question
     */
    private static function format_question_key_summary(Question $q): string
    {
        $d = $q->options;

        switch ($q->question_type) {
            case 'single':
            case 'dropdown':
                $opts = $d['options'] ?? [];
                $correct = [];
                foreach ($opts as $o) {
                    if (!empty($o['is_correct'])) {
                        $pts = isset($o['points']) ? " [{$o['points']} б.]" : '';
                        $correct[] = ($o['text'] ?? '') . $pts;
                    }
                }
                return !empty($correct) ? implode(', ', $correct) : '—';

            case 'multi':
                $opts = $d['options'] ?? [];
                $correct = [];
                foreach ($opts as $o) {
                    if (!empty($o['is_correct'])) {
                        $pts = isset($o['points']) ? " [{$o['points']} б.]" : '';
                        $correct[] = ($o['text'] ?? '') . $pts;
                    }
                }
                return !empty($correct) ? implode('; ', $correct) : '—';

            case 'boolean':
                return !empty($d['correct_value']) ? 'Верно (Да)' : 'Неверно (Нет)';

            case 'numeric':
                $val = $d['correct_value'] ?? '';
                $tol = (float)($d['tolerance'] ?? 0.0);
                return $tol > 0 ? "{$val} (±{$tol})" : (string)$val;

            case 'short_text':
                $ans = $d['correct_answers'] ?? [];
                return !empty($ans) ? implode(' | ', $ans) : '—';

            case 'matching':
                $pairs = $d['pairs'] ?? [];
                $lines = [];
                foreach ($pairs as $p) {
                    $lines[] = ($p['left'] ?? '') . ' → ' . ($p['right'] ?? '');
                }
                return !empty($lines) ? implode('; ', $lines) : '—';

            case 'ordering':
                $items = $d['items'] ?? [];
                $lines = [];
                $pos = 1;
                foreach ($items as $it) {
                    $lines[] = "{$pos}. " . ($it['text'] ?? '');
                    $pos++;
                }
                return !empty($lines) ? implode(' → ', $lines) : '—';

            case 'fill_blank':
                $blanks = $d['blanks'] ?? [];
                $lines = [];
                $pos = 1;
                foreach ($blanks as $b) {
                    $correct_vals = $b['correct_answers'] ?? [];
                    $lines[] = "[{$pos}]: " . implode(' | ', $correct_vals);
                    $pos++;
                }
                return !empty($lines) ? implode('; ', $lines) : '—';

            case 'slider':
                $target = $d['target_value'] ?? 0;
                $tol = (float)($d['tolerance'] ?? 0.0);
                return $tol > 0 ? "{$target} (±{$tol})" : (string)$target;

            case 'word_scramble':
                $words = $d['words'] ?? ($d['target_word'] ?? []);
                if (is_array($words)) {
                    return implode(' / ', $words);
                }
                return (string)$words;

            case 'dictation':
                $gaps = $d['gaps'] ?? [];
                $lines = [];
                foreach ($gaps as $gid => $g) {
                    $lines[] = "{$gid}: " . ($g['correct'] ?? '');
                }
                return !empty($lines) ? implode('; ', $lines) : '—';

            case Attachments::TYPE:
                return __('Ручная проверка: приложенные файлы', 'wp-olympiad');

            default:
                return '—';
        }
    }

    /**
     * Public wrapper to deliver an arbitrary map of relative file paths and contents
     *
     * @param array<string, string> $files
     * @param array $config
     * @return array{success: bool, destination?: string, files?: array, size?: int, message?: string}
     */
    public static function deliver_files_public(array $files, array $config): array
    {
        return self::deliver_files($files, $config);
    }

    /**
     * Deliver files to the target destination based on detected transport
     *
     * @param array<string, string> $files
     * @param array $config
     * @return array{success: bool, destination?: string, files?: array, size?: int, message?: string}
     */
    private static function deliver_files(array $files, array $config): array
    {
        $type = self::detect_type($config);

        switch ($type) {
            case 'webdav':
                return self::deliver_webdav($files, $config);

            case 'ftp':
                return self::deliver_ftp($files, $config);

            case 'smb':
                return self::deliver_smb($files, $config);

            case 'filesystem':
            default:
                return self::deliver_filesystem($files, $config);
        }
    }

    /**
     * Detect target transport type
     */
    public static function detect_type(array $config): string
    {
        $type = strtolower((string)($config['type'] ?? 'auto'));
        $path = (string)($config['path'] ?? '');

        if ($type !== 'auto' && in_array($type, ['filesystem', 'local', 'webdav', 'ftp', 'smb'], true)) {
            return ($type === 'local') ? 'filesystem' : $type;
        }

        if (preg_match('#^https?://#i', $path)) {
            return 'webdav';
        }

        if (preg_match('#^ftps?://#i', $path)) {
            return 'ftp';
        }

        // Windows UNC / SMB share: \\host\share, smb://host/share, //host/share
        if (preg_match('#^smb://#i', $path) || preg_match('#^[\\\\]{2,}#', $path) || preg_match('#^//[^/]+/#', $path)) {
            return 'smb';
        }

        return 'filesystem';
    }

    /**
     * Deliver files directly to local filesystem or mounted network share folder
     *
     * @param array<string, string> $files
     * @param array $config
     * @return array{success: bool, destination?: string, files?: array, size?: int, message?: string}
     */
    private static function deliver_filesystem(array $files, array $config): array
    {
        $dest_dir = self::resolve_local_path($config['path'] ?? '');

        // If on Linux and given a Windows UNC path (\\server\share), delegate to SMB transport if not mounted
        if (DIRECTORY_SEPARATOR === '/' && preg_match('#^[\\\\]{2,}#', $dest_dir)) {
            if (!file_exists($dest_dir)) {
                return self::deliver_smb($files, $config);
            }
        }

        if (!file_exists($dest_dir)) {
            if (!wp_mkdir_p($dest_dir)) {
                return [
                    'success' => false,
                    'message' => sprintf(__('Не удалось создать директорию сетевой папки: %s', 'wp-olympiad'), $dest_dir),
                ];
            }
            @chmod($dest_dir, 0777);
        }

        if (!is_writable($dest_dir)) {
            return [
                'success' => false,
                'message' => sprintf(__('Директория «%s» недоступна для записи. Проверьте права доступа в операционной системе.', 'wp-olympiad'), $dest_dir),
            ];
        }

        $written_files = [];
        $total_bytes = 0;

        foreach ($files as $name => $content) {
            $dest_file = rtrim($dest_dir, '/\\') . DIRECTORY_SEPARATOR . wp_normalize_path($name);
            $parent_dir = dirname($dest_file);
            if (!is_dir($parent_dir)) {
                wp_mkdir_p($parent_dir);
                @chmod($parent_dir, 0777);
            }
            $tmp = @tempnam($parent_dir, '.wpo-export-');
            if ($tmp === false) continue;
            try {
                $res = @file_put_contents($tmp, $content, LOCK_EX);
                if ($res === strlen($content) && @rename($tmp, $dest_file)) {
                    @chmod($dest_file, 0666);
                    $written_files[] = $dest_file;
                    $total_bytes += $res;
                }
            } finally { if (is_file($tmp)) @unlink($tmp); }
        }

        if (!$files || count($written_files) !== count($files)) {
            return [
                'success' => false,
                'message' => sprintf(__('Не удалось записать файлы выгрузки в сетевую папку: %s', 'wp-olympiad'), $dest_dir),
            ];
        }

        return [
            'success'     => true,
            'destination' => $dest_dir,
            'files'       => $written_files,
            'size'        => $total_bytes,
        ];
    }

    /**
     * Deliver files to WebDAV network folder via HTTP PUT
     *
     * @param array<string, string> $files
     * @param array $config
     * @return array{success: bool, destination?: string, size?: int, message?: string}
     */
    private static function deliver_webdav(array $files, array $config): array
    {
        $base_url = rtrim($config['path'], '/'); $headers = [];
        if (!empty($config['user']) || !empty($config['password'])) {
            $headers['Authorization'] = 'Basic ' . base64_encode("{$config['user']}:{$config['password']}");
        }
        $created = []; $written = 0; $total_bytes = 0;
        foreach ($files as $name => $content) {
            $segments = explode('/', str_replace('\\', '/', trim($name, '/')));
            if (array_intersect($segments, ['', '.', '..'])) {
                return ['success' => false, 'message' => __('Недопустимый относительный путь файла WebDAV.', 'wp-olympiad')];
            }
            $encoded = array_map('rawurlencode', $segments); $parent = '';
            foreach (array_slice($encoded, 0, -1) as $segment) {
                $parent .= '/' . $segment;
                if (isset($created[$parent])) continue;
                $res = wp_remote_request($base_url . $parent, ['method' => 'MKCOL', 'headers' => $headers, 'timeout' => 30, 'sslverify' => false]);
                $code = is_wp_error($res) ? 0 : (int)wp_remote_retrieve_response_code($res);
                if (!in_array($code, [200, 201, 204, 405], true)) {
                    return ['success' => false, 'message' => sprintf(__('Не удалось создать папку WebDAV (HTTP %d).', 'wp-olympiad'), $code)];
                }
                $created[$parent] = true;
            }
            $extension = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
            $mime = ['csv' => 'text/csv; charset=UTF-8', 'json' => 'application/json; charset=UTF-8',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'txt' => 'text/plain; charset=UTF-8'][$extension] ?? 'application/octet-stream';
            $res = wp_remote_request($base_url . '/' . implode('/', $encoded), [
                'method' => 'PUT', 'headers' => array_merge($headers, ['Content-Type' => $mime]),
                'body' => $content, 'timeout' => 30, 'sslverify' => false,
            ]);
            $code = is_wp_error($res) ? 0 : (int)wp_remote_retrieve_response_code($res);
            if (!in_array($code, [200, 201, 204], true)) {
                return ['success' => false, 'message' => sprintf(__('WebDAV: файл «%s» не записан (HTTP %d). Записано %d из %d файлов.', 'wp-olympiad'), $name, $code, $written, count($files))];
            }
            $written++; $total_bytes += strlen($content);
        }
        return ['success' => $written === count($files) && $written > 0, 'destination' => $base_url, 'size' => $total_bytes];
    }

    /**
     * Deliver files to FTP server
     *
     * @param array<string, string> $files
     * @param array $config
     * @return array{success: bool, destination?: string, size?: int, message?: string}
     */
    private static function deliver_ftp(array $files, array $config): array
    {
        if (!function_exists('ftp_connect')) return ['success' => false, 'message' => __('В PHP отсутствует FTP.', 'wp-olympiad')];
        $p = parse_url((string)$config['path']);
        if (!$p || empty($p['host'])) return ['success' => false, 'message' => __('Некорректный FTP URL.', 'wp-olympiad')];
        $tls = strtolower((string)($p['scheme'] ?? '')) === 'ftps';
        if ($tls && !function_exists('ftp_ssl_connect')) return ['success' => false, 'message' => __('FTPS не поддерживается сервером.', 'wp-olympiad')];
        $c = $tls ? @ftp_ssl_connect($p['host'], (int)($p['port'] ?? 21), 15) : @ftp_connect($p['host'], (int)($p['port'] ?? 21), 15);
        if (!$c) return ['success' => false, 'message' => __('Не удалось подключиться к FTP.', 'wp-olympiad')];
        try {
            $user = ($config['user'] ?? '') ?: rawurldecode((string)($p['user'] ?? 'anonymous'));
            $pass = ($config['password'] ?? '') ?: rawurldecode((string)($p['pass'] ?? ''));
            if (!@ftp_login($c, $user, $pass)) return ['success' => false, 'message' => __('FTP отклонил авторизацию.', 'wp-olympiad')];
            @ftp_pasv($c, true); @ftp_set_option($c, FTP_TIMEOUT_SEC, 20);
            if (!@ftp_chdir($c, '/')) return ['success' => false, 'message' => __('Корень FTP недоступен.', 'wp-olympiad')];
            foreach (explode('/', trim(rawurldecode((string)($p['path'] ?? '')), '/')) as $part) {
                if ($part === '') continue;
                if (!@ftp_chdir($c, $part) && (@ftp_mkdir($c, $part) === false || !@ftp_chdir($c, $part))) return ['success' => false, 'message' => __('Не удалось открыть настроенную папку FTP.', 'wp-olympiad')];
            }
            $base = @ftp_pwd($c); if ($base === false) return ['success' => false, 'message' => __('FTP не подтвердил корневую папку.', 'wp-olympiad')];
            $written = 0; $bytes = 0;
            foreach ($files as $name => $content) {
                if (!@ftp_chdir($c, $base)) return ['success' => false, 'message' => __('Настроенный корень FTP потерян.', 'wp-olympiad')];
                $parts = explode('/', str_replace('\\', '/', $name)); $leaf = array_pop($parts);
                foreach ($parts as $part) {
                    if ($part === '' || $part === '.' || $part === '..') return ['success' => false, 'message' => __('Недопустимый путь FTP.', 'wp-olympiad')];
                    if (!@ftp_chdir($c, $part) && (@ftp_mkdir($c, $part) === false || !@ftp_chdir($c, $part))) return ['success' => false, 'message' => __('Не удалось создать подкаталог FTP.', 'wp-olympiad')];
                }
                $h = fopen('php://temp', 'w+b'); if ($h === false) return ['success' => false, 'message' => __('Не удалось подготовить файл FTP.', 'wp-olympiad')];
                fwrite($h, $content); rewind($h);
                try { $ok = @ftp_fput($c, $leaf, $h, FTP_BINARY); } finally { fclose($h); }
                if (!$ok) return ['success' => false, 'message' => sprintf(__('FTP не записал файл «%s»; старые файлы не очищались.', 'wp-olympiad'), $name)];
                $written++; $bytes += strlen($content);
            }
            return ['success' => $written === count($files), 'destination' => ($tls ? 'ftps://' : 'ftp://') . $p['host'] . ':' . ($p['port'] ?? 21) . '/' . ltrim((string)($p['path'] ?? ''), '/'), 'size' => $bytes];
        } finally { @ftp_close($c); }
    }

    /**
     * Test local filesystem connection & writability
     */
    private static function test_filesystem_connection(array $config): array
    {
        $dest_dir = self::resolve_local_path($config['path'] ?? '');

        // If on Linux and given a Windows UNC path (\\server\share), delegate to SMB transport if not mounted
        if (DIRECTORY_SEPARATOR === '/' && preg_match('#^[\\\\]{2,}#', $dest_dir)) {
            if (!file_exists($dest_dir)) {
                return self::test_smb_connection($config);
            }
        }

        if (!file_exists($dest_dir)) {
            if (!@wp_mkdir_p($dest_dir)) {
                return [
                    'success' => false,
                    'message' => sprintf(__('Папка не существует и не может быть создана: %s', 'wp-olympiad'), $dest_dir),
                ];
            }
            @chmod($dest_dir, 0777);
        }

        if (!is_writable($dest_dir)) {
            return [
                'success' => false,
                'message' => sprintf(__('Папка «%s» существует, но защищена от записи сервером.', 'wp-olympiad'), $dest_dir),
            ];
        }

        $probe_name = '.wpo_probe_' . wp_generate_password(6, false) . '.tmp';
        $probe_file = rtrim($dest_dir, '/\\') . DIRECTORY_SEPARATOR . $probe_name;
        $written = @file_put_contents($probe_file, 'wpo_probe');
        if ($written === false) {
            return [
                'success' => false,
                'message' => sprintf(__('Не удалось записать тестовый файл в папку «%s».', 'wp-olympiad'), $dest_dir),
            ];
        }

        @unlink($probe_file);

        return [
            'success' => true,
            'message' => sprintf(__('✓ Сетевая папка «%s» найдена и полностью доступна для прямой записи файлов.', 'wp-olympiad'), $dest_dir),
        ];
    }

    /**
     * Test WebDAV connection
     */
    private static function test_webdav_connection(array $config): array
    {
        $headers = [];
        if (!empty($config['user']) || !empty($config['password'])) {
            $headers['Authorization'] = 'Basic ' . base64_encode("{$config['user']}:{$config['password']}");
        }

        $res = wp_remote_request($config['path'], [
            'method'    => 'PROPFIND',
            'headers'   => array_merge($headers, ['Depth' => '0']),
            'timeout'   => 15,
            'sslverify' => false,
        ]);

        if (is_wp_error($res)) {
            // Fallback to HEAD/GET
            $res = wp_remote_request($config['path'], [
                'method'    => 'HEAD',
                'headers'   => $headers,
                'timeout'   => 15,
                'sslverify' => false,
            ]);
        }

        if (is_wp_error($res)) {
            return [
                'success' => false,
                'message' => sprintf(__('Ошибка подключения к WebDAV: %s', 'wp-olympiad'), $res->get_error_message()),
            ];
        }

        $code = (int)wp_remote_retrieve_response_code($res);
        if ($code >= 200 && $code < 400) {
            return [
                'success' => true,
                'message' => sprintf(__('✓ WebDAV хранилище отвечает успешно (HTTP %d).', 'wp-olympiad'), $code),
            ];
        }

        if ($code === 401 || $code === 403) {
            return [
                'success' => false,
                'message' => sprintf(__('Ошибка авторизации WebDAV (HTTP %d). Проверьте логин и пароль.', 'wp-olympiad'), $code),
            ];
        }

        return [
            'success' => false,
            'message' => sprintf(__('WebDAV вернул статус HTTP %d', 'wp-olympiad'), $code),
        ];
    }

    /**
     * Test FTP connection
     */
    private static function test_ftp_connection(array $config): array
    {
        if (!function_exists('ftp_connect')) {
            return [
                'success' => false,
                'message' => __('В PHP не установлено расширение FTP.', 'wp-olympiad'),
            ];
        }

        $parsed = parse_url($config['path']);
        $host = $parsed['host'] ?? $config['path'];
        $port = isset($parsed['port']) ? (int)$parsed['port'] : 21;

        $conn = @ftp_connect($host, $port, 10);
        if (!$conn) {
            return [
                'success' => false,
                'message' => sprintf(__('Не удалось подключиться к FTP-хосту %s:%d', 'wp-olympiad'), $host, $port),
            ];
        }

        $user = $config['user'] ?: ($parsed['user'] ?? 'anonymous');
        $pass = $config['password'] ?: ($parsed['pass'] ?? '');

        if (!@ftp_login($conn, $user, $pass)) {
            @ftp_close($conn);
            return [
                'success' => false,
                'message' => __('Неверный логин или пароль для FTP.', 'wp-olympiad'),
            ];
        }

        @ftp_close($conn);
        return [
            'success' => true,
            'message' => sprintf(__('✓ Успешное подключение к FTP серверу %s.', 'wp-olympiad'), $host),
        ];
    }

    /**
     * Parse Windows UNC or SMB URI into structured components
     *
     * @param string $path
     * @return array{host: string, share: string, subdir: string, user: string, password: string, unc: string, service: string}|null
     */
    public static function parse_smb_path(string $path): ?array
    {
        $path = trim($path, " \t\n\r\0\x0B\"'");
        if (empty($path)) {
            return null;
        }

        $user = '';
        $pass = '';

        // smb://[user[:pass]@]host/share[/subdir]
        if (preg_match('#^smb://#i', $path)) {
            $parsed_url = parse_url($path);
            if ($parsed_url && !empty($parsed_url['host'])) {
                $host = $parsed_url['host'];
                $user = $parsed_url['user'] ?? '';
                $pass = $parsed_url['pass'] ?? '';
                $path = '/' . $host . ($parsed_url['path'] ?? '');
            }
        }

        // Normalize all slashes to forward slash
        $norm = str_replace('\\', '/', $path);

        // Filter out empty parts caused by duplicated slashes or trailing slashes
        $parts = array_values(array_filter(explode('/', $norm), 'strlen'));
        if (count($parts) < 2) {
            return null;
        }

        $host = array_shift($parts);
        $share = array_shift($parts);
        $subdir_parts = $parts;
        $subdir = implode('/', $subdir_parts);

        // Clean unc and service
        $unc_sub = !empty($subdir_parts) ? '\\' . implode('\\', $subdir_parts) : '';
        $unc = "\\\\{$host}\\{$share}{$unc_sub}";
        $service = "//{$host}/{$share}";

        return [
            'host'     => $host,
            'share'    => $share,
            'subdir'   => $subdir,
            'user'     => $user,
            'password' => $pass,
            'unc'      => $unc,
            'service'  => $service,
        ];
    }

    /**
     * Find smbclient binary executable path
     */
    public static function get_smbclient_binary(): ?string
    {
        // Путь можно задать явно (например, статический smbclient из сборки или
        // тестовая заглушка): фильтр проверяется раньше поиска по стандартным местам.
        $custom = (string) apply_filters('wpo_smbclient_binary', '');
        if ($custom !== '' && @file_exists($custom) && @is_executable($custom)) {
            return $custom;
        }

        $candidates = [
            '/usr/bin/smbclient',
            '/usr/local/bin/smbclient',
            '/bin/smbclient',
            '/opt/homebrew/bin/smbclient',
            'C:\\Windows\\System32\\smbclient.exe',
        ];
        foreach ($candidates as $p) {
            if (@file_exists($p) && @is_executable($p)) {
                return $p;
            }
        }

        if (function_exists('exec')) {
            $which = @exec('which smbclient 2>/dev/null');
            if (!empty($which) && @file_exists($which) && @is_executable($which)) {
                return $which;
            }
            // If on Linux and sudo is available, try auto-installing
            if (DIRECTORY_SEPARATOR === '/') {
                @exec('sudo -n apt-get install -y smbclient >/dev/null 2>&1');
                $which2 = @exec('which smbclient 2>/dev/null');
                if (!empty($which2) && @file_exists($which2)) {
                    return $which2;
                }
            }
        }

        return null;
    }

    /**
     * Translate raw smbclient error output into friendly message
     *
     * @param string $raw_error
     * @param array $parsed
     * @return string
     */
    /** Имя файла из строки вывода smbclient (последний сегмент удалённого пути). */
    private static function remote_basename(string $line): string
    {
        if (preg_match('/remote file\s+(.+)$/i', $line, $m)) {
            $tail = trim($m[1], " \t\"'");
        } else {
            $parts = preg_split('/\s+/', trim($line));
            $tail = (is_array($parts) && $parts) ? (string) end($parts) : '';
        }
        $tail = trim(str_replace('\\', '/', $tail), '/');

        return $tail === '' ? '' : basename($tail);
    }

    /**
     * Размеры файлов, которые уже лежат на share: -c "ls <имя>" для каждого имени.
     * null — получить список не удалось (тогда проверять нечем).
     *
     * @param array<int,string> $names
     * @return array<string,int>|null
     */
    private static function smb_remote_sizes(array $names, array $parsed, string $auth_args): ?array
    {
        $smb_bin = self::get_smbclient_binary();
        if (!$smb_bin || !$names) {
            return null;
        }
        $commands = [];
        $share_prefix = '';
        if (!empty($parsed['subdir'])) {
            foreach (explode('/', trim((string) $parsed['subdir'], '/')) as $p) {
                $share_prefix .= ($share_prefix === '' ? '' : '\\') . $p;
            }
        }
        foreach ($names as $name) {
            $rel = trim(str_replace('\\\\', '/', (string) $name), '/\\');
            $abs = ($share_prefix === '' ? '' : $share_prefix . '\\') . $rel;
            // Каталоги могли исчезнуть на share — создаём их по сегментам, иначе
            // «ls» ответит OBJECT_PATH_NOT_FOUND и проверка размера станет невозможна.
            // «dirname» не понимает «\» как разделитель: нормализуем путь заранее,
            // иначе цепочка каталогов молча схлопнется в «.".
            foreach (self::smb_mkdir_chain(dirname(str_replace('\\', '/', $abs))) as $mk) {
                $commands[] = $mk;
            }
            $commands[] = 'ls "' . str_replace('/', '\\', $abs) . '"';
        }
        // Одинаковые «mkdir» от разных файлов и от префикса шары сливаются в
        // одну команду: smbclient и так терпит коллизию каталога, но лишний
        // вывод размывает разбор отказа построчно.
        $commands = array_values(array_unique($commands));
        $auth = ($auth_args !== '') ? $auth_args : '-N';
        $full = sprintf(
            '%s %s %s -c %s -t 15 2>&1',
            escapeshellcmd($smb_bin),
            escapeshellarg($parsed['service']),
            $auth,
            escapeshellarg(implode('; ', $commands))
        );
        $lines = [];
        $code = 1;
        @exec($full, $lines, $code);
        if (!$lines) {
            return null;
        }
        $sizes = [];
        foreach ($lines as $line) {
            // Формат smbclient: «  имя  A  1234  Fri Oct 24 10:00:00 2025»
            if (preg_match('/^\s*(\S.*?)\s+[A-Za-z]{1,4}\s+(\d+)\s+\w{3}\s+\w{3}\s+\d+\s+[\d:]+\s+\d{4}\s*$/', $line, $m)) {
                $key = strtolower(str_replace('\\', '/', trim($m[1], '"')));
                $sizes[basename($key)] = (int) $m[2];
                $sizes[$key] = (int) $m[2];
            }
        }

        return $sizes ?: null;
    }

    /** Имя файла для сообщения: без служебных каталогов. */

    private static function friendly_file_label(string $name): string
    {
        $name = str_replace('\\\\', '\/', $name);
        $name = trim($name, '/');

        return $name === '' ? $name : basename($name);
    }

    /** Строка вывода относится к файлу, отказ по которому уже разобран (принят или отложен). */
    private static function line_mentions_blocked(string $line, array $blocked): bool
    {
        if (!$blocked) {
            return false;
        }
        $base = strtolower(self::remote_basename($line));

        return $base !== '' && isset($blocked[$base]);
    }

    /** Строка вывода smbclient относится к одному из наших файлов? */
    private static function line_mentions_any(string $line, array $files, array $written_tmp): bool
    {
        foreach (array_keys($files) as $name) {
            if ($name !== '' && (stripos($line, (string)$name) !== false || stripos($line, str_replace('/', '\\', (string)$name)) !== false)) {
                return true;
            }
        }
        foreach ($written_tmp as $tmp_file) {
            if (strpos($line, (string)$tmp_file) !== false || stripos($line, basename((string)$tmp_file)) !== false) {
                return true;
            }
        }

        return false;
    }

    public static function translate_smb_error(string $raw_error, array $parsed, array $skipped_files = []): string
    {
        if (str_contains($raw_error, 'NT_STATUS_LOGON_FAILURE')) {
            return sprintf(__('Ошибка авторизации SMB (NT_STATUS_LOGON_FAILURE): неверный логин или пароль для доступа к «%s».', 'wp-olympiad'), $parsed['unc']);
        }
        if (str_contains($raw_error, 'NT_STATUS_ACCESS_DENIED')) {
            return sprintf(__('Доступ запрещён (NT_STATUS_ACCESS_DENIED): у пользователя нет прав на запись в сетевую папку «%s».', 'wp-olympiad'), $parsed['unc']);
        }
        if (str_contains($raw_error, 'NT_STATUS_BAD_NETWORK_NAME')) {
            return sprintf(__('Сетевая папка не найдена (NT_STATUS_BAD_NETWORK_NAME): общий ресурс «%s» не существует на сервере %s.', 'wp-olympiad'), $parsed['share'], $parsed['host']);
        }
        if (str_contains($raw_error, 'NT_STATUS_IO_TIMEOUT') || str_contains($raw_error, 'Connection timed out') || str_contains($raw_error, 'timed out')) {
            return sprintf(__('Таймаут подключения (NT_STATUS_IO_TIMEOUT): сетевой хост %s недоступен. Проверьте правильность IP-адреса и сетевое соединение.', 'wp-olympiad'), $parsed['host']);
        }
        if (str_contains($raw_error, 'NT_STATUS_CONNECTION_REFUSED')) {
            return sprintf(__('Соединение отклонено: хост %s не принимает подключения по протоколу SMB (порт 445). Проверьте работу службы общего доступа.', 'wp-olympiad'), $parsed['host']);
        }
        if (str_contains($raw_error, 'NT_STATUS_SHARING_VIOLATION')) {
            return sprintf(
                /* translators: 1: file list, 2: share */
                __('%1$s файл уже открыт другим пользователем или программой на сервере (NT_STATUS_SHARING_VIOLATION) и не может быть перезаписан. Закройте его на %2$s и повторите выгрузку — либо дождитесь, пока его освободит антивирус или индекатор.', 'wp-olympiad'),
                $skipped_files ? sprintf(
                    /* translators: %s: file names */
                    __('Файл «%s»', 'wp-olympiad'),
                    implode('», «', array_map(static fn($n) => self::friendly_file_label($n), array_slice($skipped_files, 0, 3))) . (count($skipped_files) > 3 ? sprintf(__(' и ещё %d', 'wp-olympiad'), count($skipped_files) - 3) : '')
                ) : __('Файл', 'wp-olympiad'),
                $parsed['unc'] ?? $parsed['service'] ?? ''
            );
        }
        if (str_contains($raw_error, 'NT_STATUS_OBJECT_NAME_NOT_FOUND')) {
            return sprintf(
                /* translators: %s: destination path */
                __('На сервере не найден каталог назначения «%s» (NT_STATUS_OBJECT_NAME_NOT_FOUND): путь к сетевой папке указан неверно или подпапку удалили.', 'wp-olympiad'),
                $parsed['unc'] ?? ''
            );
        }
        if (str_contains($raw_error, 'NT_STATUS_OBJECT_PATH_NOT_FOUND')) {
            return sprintf(
                /* translators: %s: share path */
                __('Сетевая папка не найдена на сервере (NT_STATUS_OBJECT_PATH_NOT_FOUND): папка «вложения» внутри «%s» отсутствует или была удалена. Плагин создаёт её и подпапки олимпиады сам при каждой выгрузке — если отказ повторяется, дайте учётной записи право создавать папки в этой сетевой шарке.', 'wp-olympiad'),
                $parsed['unc'] ?? ''
            );
        }
        if (str_contains($raw_error, 'NT_STATUS_OBJECT_NAME_COLLISION') && $skipped_files) {
            return sprintf(
                /* translators: 1: file names, 2: share path */
                __('Файл «%1$s» уже есть в сетевой папке, а перезапись запрещена правами или атрибутом «только для чтения» (NT_STATUS_OBJECT_NAME_COLLISION). Если содержимое файла не менялось, ничего делать не нужно — он на месте. Иначе снимите защиту с файла на %2$s и повторите выгрузку.', 'wp-olympiad'),
                implode('», «', array_map(static fn($n) => self::friendly_file_label($n), array_slice($skipped_files, 0, 3))),
                $parsed['unc'] ?? $parsed['service'] ?? ''
            );
        }
        if (str_contains($raw_error, 'NT_STATUS_ACCOUNT_DISABLED')) {
            return __('Учётная запись для доступа к сетевой папке отключена в Active Directory (NT_STATUS_ACCOUNT_DISABLED).', 'wp-olympiad');
        }
        if (str_contains($raw_error, 'NT_STATUS_PASSWORD_EXPIRED')) {
            return __('Срок действия пароля учётной записи для сетевой папки истёк (NT_STATUS_PASSWORD_EXPIRED): смените пароль и обновите настройки.', 'wp-olympiad');
        }
        if ($skipped_files && str_contains($raw_error, 'NT_STATUS_ACCESS_DENIED')) {
            return sprintf(__('Сетевой хост %s недостижим (NT_STATUS_HOST_UNREACHABLE).', 'wp-olympiad'), $parsed['host']);
        }

        $clean = trim((string)preg_replace('/\s+/', ' ', strip_tags($raw_error)));
        return sprintf(__('Ошибка выгрузки в сетевую папку «%s»: %s', 'wp-olympiad'), $parsed['unc'], $clean ?: 'Неизвестная ошибка SMB');
    }

    /**
     * Команды «mkdir» по каждому сегменту пути: smbclient не умеет создавать вложенные
     * каталоги одной командой, а на отказе от полного пути остаётся только
     * NT_STATUS_OBJECT_PATH_NOT_FOUND при записи файла.
     *
     * @return array<int,string>
     */
    private static function smb_mkdir_chain(string $rel): array
    {
        $rel = trim(str_replace('\\', '/', $rel), '/');
        if ($rel === '' || $rel === '.') {
            return [];
        }
        $parts = array_values(array_filter(explode('/', $rel), 'strlen'));
        $commands = [];
        $current = '';
        foreach ($parts as $part) {
            if ($current === '') {
                $current = $part;
            } else {
                $current .= '\\' . $part;
            }
            $commands[] = 'mkdir "' . $current . '"';
        }

        return $commands;
    }

    /**
     * Создать на сетевой папке цепочку каталогов — без единого файла.
     *
     * Транспорт строит каталоги, только когда есть что класть: пока участник не
     * сдал работу, папки «вложения» на шаре нет. Организатору результат нужен в
     * момент настройки, поэтому проверка вынесена отдельно — она и создаёт
     * папки, и отвечает настоящей причиной отказа.
     *
     * @return array{success:bool,created:int,mode:string,target:string,message:string,codes:array<int,string>,raw:string}
     */
    public static function ensure_remote_dir(string $rel, array $config): array
    {
        $rel = trim(str_replace('\\\\', '/', $rel), '/');
        if ($rel === '' || preg_match('#(^|/)(|\.|\.\.)(/|$)#', $rel) === 1) {
            return [
                'success' => false, 'created' => 0, 'mode' => '', 'target' => '',
                'message' => __('Нельзя создавать каталог с таким относительным путём.', 'wp-olympiad'),
                'codes' => [], 'raw' => '',
            ];
        }

        switch (self::detect_type($config)) {
            case 'smb':
                return self::smb_ensure_dir($rel, $config);
            case 'webdav':
                return self::webdav_ensure_dir($rel, $config);
            case 'ftp':
                return self::ftp_ensure_dir($rel, $config);
        }

        return self::local_ensure_dir($rel, $config);
    }

    /** Каталог там, где путь доступен PHP напрямую: локальный диск или монтирование. */
    private static function local_ensure_dir(string $rel, array $config): array
    {
        $result = ['success' => false, 'created' => 0, 'mode' => 'filesystem', 'target' => '', 'message' => '', 'codes' => [], 'raw' => ''];
        $base = rtrim(str_replace('\\\\', '/', self::resolve_local_path((string)($config['path'] ?? ''))), '/');
        if ($base === '') {
            $result['message'] = __('Сетевая папка не задана: создавать папку вложений негде.', 'wp-olympiad');

            return $result;
        }
        // На Linux UNC-путь — не путь в файловой системе: каталог с именем
        // «\\server\share» рядом с плагином создавать нельзя, идём через smbclient.
        if (DIRECTORY_SEPARATOR === '/' && preg_match('#^[\\\\]{2}#', $base) === 1) {
            return self::smb_ensure_dir($rel, $config);
        }

        $segments = array_values(array_filter(explode('/', $rel), 'strlen'));
        $created = 0;
        $current = $base;
        foreach ($segments as $segment) {
            $current .= '/' . $segment;
            if (is_dir($current)) {
                continue;
            }
            if (!@wp_mkdir_p($current)) {
                $result['target'] = $current;
                $result['message'] = sprintf(
                    /* translators: 1: каталог */
                    __('Каталог «%s» создать не удалось: нет прав на запись или путь недоступен.', 'wp-olympiad'),
                    $current
                );

                return $result;
            }
            @chmod($current, 0777);
            $created++;
        }

        $result['target'] = $current;
        $result['created'] = $created;
        $result['success'] = is_dir($current) && is_writable($current);
        if (!$result['success']) {
            $result['message'] = sprintf(
                /* translators: 1: каталог */
                __('Каталог «%s» есть, но записывать в него нельзя: проверьте права на сетевой папке.', 'wp-olympiad'),
                $current
            );
        }

        return $result;
    }

    /**
     * Цепочка каталогов через smbclient: mkdir по одному сегменту и проверка cd.
     *
     * smbclient выходит с кодом 0, даже когда сервер ответил отказом, а повторный
     * mkdir существующей папки — это OBJECT_NAME_COLLISION, отказом не являющаяся.
     * Поэтому судим по строкам вывода, а итоговый «cd» доказывает, что цепочка
     * на сервере действительно существует.
     */
    private static function smb_ensure_dir(string $rel, array $config): array
    {
        $result = ['success' => false, 'created' => 0, 'mode' => 'smb', 'target' => '', 'message' => '', 'codes' => [], 'raw' => ''];
        $parsed = self::parse_smb_path((string)($config['path'] ?? ''));
        if (!$parsed) {
            $result['message'] = sprintf(
                /* translators: 1: путь */
                __('Некорректный путь к сетевой папке Windows (SMB): %s', 'wp-olympiad'),
                (string)($config['path'] ?? '')
            );

            return $result;
        }
        // Windows пишет по UNC напрямую — smbclient там не нужен.
        if (DIRECTORY_SEPARATOR === '\\') {
            return self::local_ensure_dir($rel, $config);
        }
        $smb_bin = self::get_smbclient_binary();
        if (!$smb_bin) {
            $result['message'] = sprintf(
                /* translators: 1: путь */
                __('Для создания папок на сетевой шаре «%s» с сервера Linux нужна утилита smbclient — на сервере её нет.', 'wp-olympiad'),
                (string)($parsed['unc'] ?? '')
            );

            return $result;
        }

        [$auth_args, $creds_file] = self::smb_auth_args($config, $parsed);
        $prefix = '';
        if (!empty($parsed['subdir'])) {
            foreach (array_filter(explode('/', trim((string)$parsed['subdir'], '/')), 'strlen') as $p) {
                $prefix .= ($prefix === '' ? '' : '\\') . $p;
            }
        }

        $chain = [];
        $current = $prefix;
        foreach (array_filter(explode('/', $rel), 'strlen') as $segment) {
            $current = ($current === '' ? '' : $current . '\\') . $segment;
            $chain[] = $current;
        }
        if (!$chain) {
            @unlink((string)$creds_file);

            return $result;
        }

        $commands = [];
        foreach ($chain as $dir) {
            foreach (self::smb_mkdir_chain($dir) as $mk) {
                $commands[] = $mk;
            }
        }
        $final = str_replace('/', '\\', (string)end($chain));
        $commands[] = 'cd "' . $final . '"';
        $commands = array_values(array_unique($commands));

        // Тайм-аут короткий: проба вызывается и при открытии страницы олимпиады,
        // а недоступная шара не должна вешать администратора на двадцать секунд.
        $full = sprintf(
            '%s %s %s -c %s -t 10 2>&1',
            escapeshellcmd($smb_bin),
            escapeshellarg($parsed['service']),
            $auth_args,
            escapeshellarg(implode('; ', $commands))
        );
        $lines = [];
        $code = 1;
        @exec($full, $lines, $code);
        @unlink((string)$creds_file);

        $blob = implode("\n", $lines);
        // «unc» из parse_smb_path() уже включает подкаталог шары: к нему
        // приписывается только относительный путь, иначе каталог виден дважды.
        $result['target'] = rtrim((string)($parsed['unc'] ?? '\\'), '\\') . '\\' . str_replace('/', '\\', $rel);
        $result['codes'] = self::status_codes($blob);
        $result['raw'] = mb_substr(trim((string)preg_replace('/\s+/', ' ', strip_tags($blob))), 0, 900);

        // Коллизия имени каталога — папка уже есть, это успех. Остальные отказы
        // относим к цепочке: mkdir и cd сервер всегда называет по имени.
        $collisions = 0;
        $refused = [];
        foreach (explode("\n", $blob) as $line) {
            if (stripos($line, 'making remote directory') !== false) {
                if (preg_match('/OBJECT_NAME_COLLISION|OBJECT_NAME_EXISTS|already exists/i', $line) === 1) {
                    $collisions++;
                } else {
                    $refused[] = trim($line);
                }
                continue;
            }
            if (preg_match('/NT_STATUS_[A-Z_]+/i', $line) === 1
                && preg_match('/error|failed|cannot|denied|not_found|no_such|invalid|bad|violation/i', $line) === 1) {
                $refused[] = trim($line);
            }
        }
        $result['created'] = max(0, count($chain) - $collisions);
        $result['success'] = !$refused;
        if (!$result['success']) {
            $result['message'] = self::translate_smb_error(implode("\n", $refused), $parsed);
        }

        return $result;
    }

    /** Папки на WebDAV: MKCOL по сегментам и PROPFIND в конце — «создал» ≠ «есть». */
    private static function webdav_ensure_dir(string $rel, array $config): array
    {
        $result = ['success' => false, 'created' => 0, 'mode' => 'webdav', 'target' => '', 'message' => '', 'codes' => [], 'raw' => ''];
        $base = rtrim((string)($config['path'] ?? ''), '/');
        if ($base === '') {
            $result['message'] = __('Сетевая папка (WebDAV) не задана.', 'wp-olympiad');

            return $result;
        }
        $headers = [];
        if (!empty($config['user']) || !empty($config['password'])) {
            $headers['Authorization'] = 'Basic ' . base64_encode($config['user'] . ':' . $config['password']);
        }

        $created = 0;
        $url = '';
        foreach (array_filter(explode('/', $rel), 'strlen') as $segment) {
            $url .= '/' . rawurlencode($segment);
            $res = wp_remote_request($base . $url, ['method' => 'MKCOL', 'headers' => $headers, 'timeout' => 10, 'sslverify' => false]);
            $code = is_wp_error($res) ? 0 : (int)wp_remote_retrieve_response_code($res);
            if (is_wp_error($res)) {
                $result['message'] = sprintf(
                    /* translators: 1: ошибка */
                    __('WebDAV не отвечает при создании папки «%s».', 'wp-olympiad'),
                    $segment
                );
                $result['raw'] = mb_substr((string)$res->get_error_message(), 0, 900);

                return $result;
            }
            // 405 — каталог уже есть: так отвечают Apache и Nextcloud.
            if (!in_array($code, [200, 201, 204, 405], true)) {
                $result['codes'] = ['HTTP ' . $code];
                $result['message'] = sprintf(
                    /* translators: 1: каталог, 2: код ответа */
                    __('WebDAV отказал создать папку «%1$s» (HTTP %2$d).', 'wp-olympiad'),
                    $segment,
                    $code
                );

                return $result;
            }
            if ($code === 201) {
                $created++;
            }
        }

        $res = wp_remote_request($base . $url, [
            'method' => 'PROPFIND',
            'headers' => array_merge($headers, ['Depth' => '0']),
            'timeout' => 10,
            'sslverify' => false,
            'body' => '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/></d:prop></d:propfind>',
        ]);
        $code = is_wp_error($res) ? 0 : (int)wp_remote_retrieve_response_code($res);
        $result['target'] = $base . $url;
        $result['created'] = $created;
        if (!in_array($code, [207, 200], true)) {
            $result['codes'] = ['HTTP ' . $code];
            $result['message'] = sprintf(
                /* translators: 1: код ответа */
                __('WebDAV не подтвердил, что папка существует (HTTP %d): сервер её принял, но не отдал.', 'wp-olympiad'),
                $code
            );

            return $result;
        }
        $result['success'] = true;

        return $result;
    }

    /** Папки на FTP: заход по сегментам с mkdir там, где нет каталога. */
    private static function ftp_ensure_dir(string $rel, array $config): array
    {
        $result = ['success' => false, 'created' => 0, 'mode' => 'ftp', 'target' => '', 'message' => '', 'codes' => [], 'raw' => ''];
        if (!function_exists('ftp_connect')) {
            $result['message'] = __('В PHP отсутствует расширение FTP.', 'wp-olympiad');

            return $result;
        }
        $p = parse_url((string)($config['path'] ?? ''));
        if (!$p || empty($p['host'])) {
            $result['message'] = __('Некорректный FTP URL сетевой папки.', 'wp-olympiad');

            return $result;
        }
        $tls = strtolower((string)($p['scheme'] ?? '')) === 'ftps';
        if ($tls && !function_exists('ftp_ssl_connect')) {
            $result['message'] = __('FTPS не поддерживается этой сборкой PHP.', 'wp-olympiad');

            return $result;
        }
        $c = $tls ? @ftp_ssl_connect($p['host'], (int)($p['port'] ?? 21), 15) : @ftp_connect($p['host'], (int)($p['port'] ?? 21), 15);
        if (!$c) {
            $result['message'] = sprintf(
                /* translators: 1: хост */
                __('Не удалось подключиться к FTP-серверу «%s».', 'wp-olympiad'),
                (string)$p['host']
            );

            return $result;
        }
        try {
            $user = ($config['user'] ?? '') ?: rawurldecode((string)($p['user'] ?? 'anonymous'));
            $pass = ($config['password'] ?? '') ?: rawurldecode((string)($p['pass'] ?? ''));
            if (!@ftp_login($c, $user, $pass)) {
                $result['message'] = __('FTP отклонил авторизацию.', 'wp-olympiad');

                return $result;
            }
            @ftp_pasv($c, true);
            @ftp_set_option($c, FTP_TIMEOUT_SEC, 10);
            if (!@ftp_chdir($c, '/')) {
                $result['message'] = __('Корень FTP недоступен.', 'wp-olympiad');

                return $result;
            }
            $walk = array_merge(
                array_filter(explode('/', trim(rawurldecode((string)($p['path'] ?? '')), '/')), 'strlen'),
                array_filter(explode('/', $rel), 'strlen')
            );
            $created = 0;
            foreach ($walk as $segment) {
                if (@ftp_chdir($c, $segment)) {
                    continue;
                }
                if (@ftp_mkdir($c, $segment) === false || !@ftp_chdir($c, $segment)) {
                    $result['message'] = sprintf(
                        /* translators: 1: каталог */
                        __('FTP не создал папку «%s»: сервер отказал или нет прав на запись.', 'wp-olympiad'),
                        $segment
                    );

                    return $result;
                }
                $created++;
            }
            $pwd = @ftp_pwd($c);
            $result['created'] = $created;
            $result['target'] = (string)$pwd;
            $result['success'] = is_string($pwd) && $pwd !== '';

            return $result;
        } finally {
            @ftp_close($c);
        }
    }

    /**
     * Снять признак «только для чтения» с файлов на share (setmode … -r).
     *
     * Атрибут трогается только для переданных имён, содержимое файлов не меняется:
     * это ответ на NT_STATUS_OBJECT_NAME_COLLISION, когда файл на сервере лежит
     * защищённым от перезаписи.
     *
     * @param array<int,string> $names имена относительно корня подключения
     * @return array{success: bool, count: int, message: string, codes: array, raw: string, mode: string}
     */
    public static function smb_unprotect(array $names, array $config): array
    {
        $result = ['success' => false, 'count' => 0, 'failed' => 0, 'message' => '', 'codes' => [], 'raw' => '', 'mode' => ''];
        $parsed = self::parse_smb_path((string)($config['path'] ?? ''));
        if (!$parsed) {
            $result['message'] = sprintf(__('Некорректный путь к сетевой папке Windows (SMB): %s', 'wp-olympiad'), $config['path'] ?? '');

            return $result;
        }
        $smb_bin = self::get_smbclient_binary();
        if (!$smb_bin) {
            $result['message'] = __('Для работы с сетевой папкой Windows на сервере Linux требуется утилита smbclient.', 'wp-olympiad');

            return $result;
        }
        [$auth_args, $creds_file] = self::smb_auth_args($config, $parsed);

        $commands = [];
        $prefix = '';
        if (!empty($parsed['subdir'])) {
            foreach (explode('/', trim((string) $parsed['subdir'], '/')) as $p) {
                $prefix .= ($prefix === '' ? '' : '\\') . $p;
            }
        }
        $targets = [];
        foreach (array_values(array_unique(array_filter(array_map('strval', $names)))) as $name) {
            $rel = trim(str_replace('\\\\', '/', (string) $name), '/\\');
            $abs = ($prefix === '' ? '' : $prefix . '\\') . $rel;
            $commands[] = 'setmode "' . str_replace('/', '\\', $abs) . '" -r';
            // Нормализованный путь: по нему узнаём, на какой именно файл
            // сервер ответил отказом, а не приписываем отказ всему списку.
            $targets[] = strtolower(str_replace([ '\\', '/' ], '/', $abs));
        }
        $result['count'] = count($targets);
        if (!$commands) {
            @unlink((string)$creds_file);

            return $result;
        }

        $full = sprintf(
            '%s %s %s -c %s -t 25 2>&1',
            escapeshellcmd($smb_bin),
            escapeshellarg($parsed['service']),
            $auth_args,
            escapeshellarg(implode('; ', $commands))
        );
        $lines = [];
        $code = 1;
        @exec($full, $lines, $code);
        @unlink((string)$creds_file);

        $blob = implode("\n", $lines);
        $result['mode'] = 'smb';
        $result['codes'] = self::status_codes($blob);
        $result['raw'] = mb_substr(trim((string)preg_replace('/\s+/', ' ', strip_tags($blob))), 0, 900);
        // setmode не печатает ничего, когда всё удалось: судим по выводу сервера.
        // Отказ засчитывается только тому файлу, чей путь сервер назвал сам;
        // иначе «сняли защиту» превращается в «отдали команду».
        $blob_norm = strtolower(str_replace([ '\\', '/' ], '/', $blob));
        $refused   = [];
        foreach (explode("\n", $blob_norm) as $line) {
            if (!preg_match('/error|failed|cannot|denied|violation|no_such|not_found|invalid|read.?only|protected/i', $line)) {
                continue;
            }
            foreach ($targets as $norm) {
                if ($norm !== '' && (strpos($line, $norm) !== false || strpos($line, ltrim($norm, '/')) !== false)) {
                    $refused[ $norm ] = true;
                }
            }
        }
        $blind = (bool) preg_match('/error|failed|cannot|denied|violation|no_such|not_found|invalid|read.?only|protected/i', $blob_norm);
        if ($blind && !$refused && count($targets) === 1) {
            $refused[ $targets[0] ] = true; // отказ без пути при одном файле относится к нему
        }
        $result['failed'] = count($refused);
        $result['count'] = max(0, count($targets) - count($refused));
        $result['success'] = $result['count'] > 0;
        if (!$result['success']) {
            $result['message'] = self::translate_smb_error($blob, $parsed);
        } elseif ($result['failed'] > 0) {
            $result['message'] = sprintf(
                /* translators: 1: number of files */
                _n(
                    'Защиту удалось снять только с %1$d файла: остальные сервер не отдал.',
                    'Защиту удалось снять только с %1$d файлов: остальные сервер не отдал.',
                    $result['count'],
                    'wp-olympiad'
                ),
                $result['count']
            );
        }

        return $result;
    }

    /**
     * Файл аргументов авторизации smbclient (создаётся при указанном логине).
     *
     * @return array{0:string,1:string} аргументы и путь к файлу (пустая строка — файла нет)
     */
    private static function smb_auth_args(array $config, array $parsed): array
    {
        $user = !empty($config['user']) ? (string)$config['user'] : (string)($parsed['user'] ?? '');
        $pass = !empty($config['password']) ? (string)$config['password'] : (string)($parsed['password'] ?? '');
        if ($user === '' && $pass === '') {
            return ['-N', ''];
        }

        $domain = '';
        if (str_contains($user, '\\')) {
            [$domain, $user] = explode('\\', $user, 2);
        } elseif (str_contains($user, '/')) {
            [$domain, $user] = explode('/', $user, 2);
        }
        $creds_file = (string)tempnam(sys_get_temp_dir(), 'wpo_smb_creds_');
        $creds_content = "username = {$user}\npassword = {$pass}\n";
        if ($domain !== '') {
            $creds_content .= "domain = {$domain}\n";
        }
        file_put_contents($creds_file, $creds_content);
        @chmod($creds_file, 0600);

        return ['-A ' . escapeshellarg($creds_file), $creds_file];
    }

    /**
     * Deliver files to SMB / Windows Share directly
     *
     * @param array<string, string> $files
     * @param array $config
     * @return array{success: bool, destination?: string, files?: array, size?: int, message?: string}
     */
    private static function deliver_smb(array $files, array $config): array
    {
        $parsed = self::parse_smb_path($config['path'] ?? '');
        if (!$parsed) {
            return [
                'success' => false,
                'message' => sprintf(__('Некорректный путь к сетевой папке Windows (SMB): %s', 'wp-olympiad'), $config['path'] ?? ''),
            ];
        }

        // 1. If running on native Windows OS, write directly to UNC path
        if (DIRECTORY_SEPARATOR === '\\') {
            return self::deliver_filesystem($files, $config);
        }

        // 2. If the path is already mounted in Linux filesystem
        $local_mount = self::resolve_local_path($config['path']);
        if (is_dir($local_mount) && is_writable($local_mount)) {
            return self::deliver_filesystem($files, $config);
        }

        // 3. Use smbclient
        $smb_bin = self::get_smbclient_binary();
        if (!$smb_bin) {
            return [
                'success' => false,
                'message' => sprintf(
                    __('Для прямой выгрузки в Windows-папку «%s» на сервере Linux требуется утилита smbclient. Установите её командой: sudo apt install smbclient (либо смонтируйте папку в файловую систему ОС: sudo mount -t cifs %s /mnt/share).', 'wp-olympiad'),
                    $parsed['unc'],
                    $parsed['service']
                ),
            ];
        }

        // 4. Deliver via smbclient
        $tmp_dir = wp_normalize_path(sys_get_temp_dir() . '/wpo_smb_' . wp_generate_password(8, false));
        if (!wp_mkdir_p($tmp_dir)) {
            return [
                'success' => false,
                'message' => __('Ошибка создания временного каталога для выгрузки SMB.', 'wp-olympiad'),
            ];
        }

        $written_tmp = [];
        foreach ($files as $name => $content) {
            $tmp_file = $tmp_dir . '/' . $name;
            $tmp_parent = dirname($tmp_file);
            if (!is_dir($tmp_parent)) {
                wp_mkdir_p($tmp_parent);
            }
            if (file_put_contents($tmp_file, $content) === strlen($content)) {
                $written_tmp[$name] = $tmp_file;
            }
        }

        if (count($written_tmp) !== count($files)) {
            self::delete_directory_recursive($tmp_dir);
            return [
                'success' => false,
                'message' => __('Ошибка подготовки временных файлов выгрузки.', 'wp-olympiad'),
            ];
        }

        // Credentials file
        $creds_file = tempnam(sys_get_temp_dir(), 'wpo_smb_creds_');
        $user = !empty($config['user']) ? $config['user'] : $parsed['user'];
        $pass = !empty($config['password']) ? $config['password'] : $parsed['password'];

        if (!empty($user) || !empty($pass)) {
            $domain = '';
            if (str_contains($user, '\\')) {
                [$domain, $user] = explode('\\', $user, 2);
            } elseif (str_contains($user, '/')) {
                [$domain, $user] = explode('/', $user, 2);
            }
            $creds_content = "username = {$user}\npassword = {$pass}\n";
            if (!empty($domain)) {
                $creds_content .= "domain = {$domain}\n";
            }
            file_put_contents($creds_file, $creds_content);
            chmod($creds_file, 0600);
            $auth_args = '-A ' . escapeshellarg($creds_file);
        } else {
            $auth_args = '-N';
        }

        // Пути каталогов и файлов отдаём полными от корня шары: относительно cwd
        // разные версии smbclient разбирают их по-своему, и файл уезжает не туда.
        $commands = [];
        $share_prefix = '';
        if (!empty($parsed['subdir'])) {
            $parts = explode('/', trim((string) $parsed['subdir'], '/'));
            foreach ($parts as $p) {
                $share_prefix .= ($share_prefix === '' ? '' : '\\') . $p;
                $commands[] = 'mkdir "' . $share_prefix . '"';
            }
        }

        foreach ($written_tmp as $name => $path_tmp) {
            // smbclient создаёт только один каталог за команду: «mkdir a\b\c» при
            // отсутствующем «b» отвечает OBJECT_PATH_NOT_FOUND, и put некуда класть.
            $rel = trim(str_replace('\\\\', '/', (string) $name), '/\\');
            $abs = ($share_prefix === '' ? '' : $share_prefix . '\\') . $rel;
            // «dirname» не понимает «\» как разделитель: нормализуем путь заранее,
            // иначе цепочка каталогов молча схлопнется в «.".
            foreach (self::smb_mkdir_chain(dirname(str_replace('\\', '/', $abs))) as $mk) {
                $commands[] = $mk;
            }
            $commands[] = 'put "' . $path_tmp . '" "' . str_replace('/', '\\', $abs) . '"';
        }

        // Одинаковые «mkdir» от разных файлов и от префикса шары сливаются в
        // одну команду: smbclient и так терпит коллизию каталога, но лишний
        // вывод размывает разбор отказа построчно.
        $commands = array_values(array_unique($commands));
        $cmd_str = implode('; ', $commands);
        $full_cmd = sprintf(
            '%s %s %s -c %s -t 25 2>&1',
            escapeshellcmd($smb_bin),
            escapeshellarg($parsed['service']),
            $auth_args,
            escapeshellarg($cmd_str)
        );

        $output_lines = [];
        $exit_code = 1;
        exec($full_cmd, $output_lines, $exit_code);

        $blob = implode("\n", $output_lines);

        // Что реально записано: smbclient на каждый файл печатает «putting file <локальный путь> as …».
        $delivered = [];
        foreach ($written_tmp as $name => $tmp_file) {
            if (preg_match('/putting file[^\n]*' . preg_quote($tmp_file, '/') . '[^\n]* as /i', $blob)) {
                $delivered[] = (string)$name;
            }
        }
        $failed = array_values(array_diff(array_map('strval', array_keys($files)), $delivered));

        // Отказ записи по имени или по блокировке — файл на share уже лежит. Samba так
        // отвечает, когда файл существует и защищён от перезаписи (атрибут «только для
        // чтения», запрет удаления), а также когда он открыт другим пользователем,
        // антивирусом или индекатором. Обычно это повторная доставка того же вложения,
        // поэтому сверяем размер: совпал — содержимое на месте, доставлено.
        $existing = [];
        $verified = [];
        $mismatch = [];
        $stale_note = '';
        $blocked = [];
        $file_collisions = [];
        if ($failed) {
            $collision_names = [];
            foreach ($output_lines as $line) {
                if (str_contains($line, 'making remote directory')) {
                    continue; // создание каталога — не про файл
                }
                if (!preg_match('/NT_STATUS_(OBJECT_NAME_COLLISION|OBJECT_NAME_EXISTS|SHARING_VIOLATION|ACCESS_DENIED|FILE_IS_A_DIRECTORY|DELETE_PENDING|LOCK_NOT_GRANTED)/', $line, $mm)) {
                    continue;
                }
                $base = self::remote_basename($line);
                if ($base !== '') {
                    $blocked[strtolower($base)] = 'NT_STATUS_' . $mm[1];
                }
                // Коллизия именно файла (put/open), а не каталога: только она даёт
                // право говорить «файл уже есть, снимите защиту с файла».
                if (($mm[1] === 'OBJECT_NAME_COLLISION' || $mm[1] === 'OBJECT_NAME_EXISTS') && $base !== '') {
                    $collision_names[] = $base;
                }
            }
            foreach ($failed as $name) {
                if (isset($blocked[strtolower(basename(str_replace('\\', '/', (string) $name)))])) {
                    $existing[] = (string) $name;
                }
            }
            if ($existing) {
                $sizes = self::smb_remote_sizes($existing, $parsed, $auth_args);
                foreach ($existing as $name) {
                    $want = strlen((string) ($files[$name] ?? ''));
                    // Имя вида «24 hex.pdf» генерирует плагин: если размеры сверить нечем,
                    // повторной записи не требуется. Обычные имена (протоколы) без сверки
                    // размера не прощаем — там файл мог положить кто-то другой.
                    $ours = preg_match('#/[0-9a-f]{24}\.[a-z0-9]{1,8}$#i', str_replace('\\', '/', $name)) === 1;
                    $key = strtolower(basename(str_replace('\\', '/', (string) $name)));
                    if ($sizes === null || !isset($sizes[$key])) {
                        if ($ours) {
                            $delivered[] = $name;
                        } else {
                            $mismatch[] = $name;
                        }
                        continue;
                    }
                    if ((int) $sizes[$key] === $want) {
                        $delivered[] = $name;
                        $verified[] = $name;
                    } else {
                        $mismatch[] = $name; // чужой или обрезанный файл: запись нужна
                        $stale_note = sprintf(
                            /* translators: 1: file name, 2: size on share, 3: size to write */
                            __('Файл «%1$s» в сетевой папке имеет другой размер: на сервере %2$d байт, у плагина %3$d. Он не перезаписан, потому что доступ к файлу занят (файл открыт другим пользователем, антивирусом или индекатором). Закройте файл на %4$s и повторите выгрузку — либо удалите его вручную.', 'wp-olympiad'),
                            self::friendly_file_label((string) $name),
                            (int) $sizes[$key],
                            $want,
                            $parsed['unc'] ?? $parsed['service'] ?? ''
                        );
                    }
                }
                $failed = array_values(array_diff(array_map('strval', array_keys($files)), $delivered));
            }

            foreach ($collision_names as $cn) {
                if ($cn === '') {
                    continue;
                }
                foreach ($failed as $name) {
                    if (strcasecmp(basename(str_replace('\\', '/', (string) $name)), $cn) === 0) {
                        $file_collisions[] = (string) $name;
                    }
                }
            }
            $file_collisions = array_values(array_unique($file_collisions));
        }

        // Файл на share защищён от перезаписи, а содержимое на сервере отличается от
        // того, что принёс участник: это ровно тот случай, о котором говорит
        // «снимите защиту с файла». Атрибут «только для чтения» снимаем сами (до этого
        // момента содержимое не менялось) и пробуем записать файл ещё раз.
        $unprotect_note = '';
        $unprotected = 0;
        $retry = array_values(array_intersect($mismatch, $file_collisions));
        $retry_blob = '';
        $retry_puts = [];
        if ($retry && $smb_bin) {
            $retry_tmp = $tmp_dir . '/retry';
            if (!wp_mkdir_p($retry_tmp)) {
                $retry = [];
            }
        }
        foreach ($retry as $name) {
            $content = (string) ($files[$name] ?? '');
            $safe = preg_replace('#[^a-zA-Z0-9._-]#u', '_', basename(str_replace('\\', '/', (string) $name)));
            $local = $retry_tmp . '/' . $safe;
            if (@file_put_contents($local, $content) !== strlen($content)) {
                continue;
            }
            $rel = trim(str_replace('\\\\', '/', (string) $name), '/\\');
            $remote = str_replace('/', '\\', (!empty($parsed['subdir']) ? trim((string) $parsed['subdir'], '/') . '\\' : '') . $rel);
            $retry_puts[] = ['local' => $local, 'remote' => $remote, 'name' => (string) $name];
        }
        if ($retry_puts) {
            $cmds = [];
            foreach ($retry_puts as $rp) {
                foreach (self::smb_mkdir_chain(dirname(str_replace('\\', '/', $rp['remote']))) as $mk) {
                    $cmds[] = $mk;
                }
                $cmds[] = 'setmode "' . $rp['remote'] . '" -r';
            }
            foreach ($retry_puts as $rp) {
                $cmds[] = 'put "' . $rp['local'] . '" "' . $rp['remote'] . '"';
            }
            $retry_cmd = sprintf(
                '%s %s %s -c %s -t 25 2>&1',
                escapeshellcmd($smb_bin),
                escapeshellarg($parsed['service']),
                $auth_args,
                escapeshellarg(implode('; ', $cmds))
            );
            $retry_lines = [];
            $retry_code = 1;
            @exec($retry_cmd, $retry_lines, $retry_code);
            $retry_blob = implode("\n", $retry_lines);
            $blob .= "\n" . $retry_blob;
            foreach ($retry_puts as $rp) {
                if (preg_match('/ as \\\\?' . preg_quote($rp['remote'], '/') . '(\\s|$)/i', $retry_blob)) {
                    $delivered[] = $rp['name'];
                    $unprotected++;
                    $mismatch = array_values(array_diff($mismatch, [$rp['name']]));
                    $blocked[strtolower(basename(str_replace('\\', '/', $rp['name'])))] = 'NT_STATUS_UNPROTECTED_AND_WRITTEN';
                }
            }
            $failed = array_values(array_diff(array_map('strval', array_keys($files)), $delivered));
            if (!$mismatch) {
                $stale_note = '';
            }
            if ($unprotected > 0) {
                $unprotect_note = sprintf(
                    /* translators: %d: number of files */
                    __('С файлов снят атрибут «только для чтения» (setmode -r), после чего они записаны заново: %d.', 'wp-olympiad'),
                    $unprotected
                );
            } else {
                $unprotect_note = __('Атрибут «только для чтения» с файлов снят (setmode -r), но сервер всё равно не принял запись: права на саму папку нужно менять на стороне сервера.', 'wp-olympiad');
            }
        }

        // Временные файлы и файл учётных данных больше не нужны (creds нужен был
        // только для проверки размеров на share).
        @unlink($creds_file);
        self::delete_directory_recursive($tmp_dir);

        $unexpected = [];

        foreach ($output_lines as $line) {
            preg_match_all('/NT_STATUS_[A-Z0-9_]+/', $line, $statuses);
            if (!$statuses[0]) continue;
            $directory_exists = str_contains($line, 'making remote directory')
                && !array_diff($statuses[0], ['NT_STATUS_OBJECT_NAME_COLLISION', 'NT_STATUS_OBJECT_NAME_EXISTS']);
            $per_file = ($failed || $existing) && self::line_mentions_any($line, $files, $written_tmp);
            // Отказ по уже принятому файлу (в том числе ответ второй попытки после
            // setmode) не превращает успешную выгрузку в ошибку.
            $known_block = $blocked && self::line_mentions_blocked($line, $blocked);
            if (!$directory_exists && !$per_file && !$known_block) $unexpected = array_merge($unexpected, $statuses[0]);
        }

        // Часть файлов не легла (например, открыт другим пользователем), но остальное
        // доставлено: считаем выгрузку состоявшейся и сообщаем, что пропущено.
        if ($delivered && !$unexpected) {
            $result = [
                'success'     => true,
                'destination' => $parsed['unc'],
                'files'       => $delivered,
                'size'        => array_sum(array_map(static fn($name) => strlen((string)($files[$name] ?? '')), $delivered)),
            ];
            if ($failed) {
                $result['skipped'] = $failed;
                $result['message'] = $stale_note !== ''
                    ? $stale_note
                    : self::translate_smb_error($blob, $parsed, $file_collisions ?: $failed);
                if ($unprotect_note !== '') {
                    $result['message'] = trim((string) $result['message'] . ' ' . $unprotect_note);
                }
            } elseif ($unprotect_note !== '') {
                $result['message'] = $unprotect_note;
            }
            if ($unprotected > 0) {
                $result['unprotected'] = $unprotected;
            }
            // «уже на месте» — это принятые нами записи, а не отказ транспорта.
            $result['existing'] = array_values(array_intersect($existing, $delivered));
            if ($verified) {
                $result['verified'] = $verified;
            }
            if ($mismatch) {
                $result['stale'] = $mismatch;
            }
            if (!$failed && $existing && $verified !== $existing) {
                $already_note = sprintf(
                    /* translators: %d: number of files */
                    __('Уже лежало в сетевой папке, повторная запись не потребовалась: %d.', 'wp-olympiad'),
                    count($existing)
                );
                $result['message'] = trim(($unprotect_note !== '' ? $unprotect_note . ' ' : '') . $already_note);
            }
            $result['codes'] = self::status_codes($blob);
            return $result;
        }

        if ($exit_code === 0 && !$unexpected && !$failed) {
            return [
                'success'     => true,
                'destination' => $parsed['unc'],
                'files'       => array_keys($files),
                'size'        => array_sum(array_map('strlen', $files)),
                'codes'       => self::status_codes($blob),
            ];
        }

        $error_msg = $stale_note !== ''
            ? trim($stale_note . ($unprotect_note !== '' ? ' ' . $unprotect_note : ''))
            : self::translate_smb_error($blob, $parsed, $file_collisions);

        return [
            'success' => false,
            'message' => $error_msg,
            'failed'  => $failed,
            'partial' => $delivered,
            'codes'   => array_values(array_unique(array_merge(self::status_codes($blob), $unexpected))),
            // Что именно ответил сервер — это видно организатору в предупреждении,
            // иначе остаётся только «папка недоступна для записи».
            'raw'     => mb_substr(trim((string) preg_replace('/\s+/', ' ', strip_tags($blob))), 0, 900),
        ];
    }

    /** Все NT_STATUS, упомянутые в выводе smbclient. */
    private static function status_codes(string $blob): array
    {
        preg_match_all('/NT_STATUS_[A-Z0-9_]+/', $blob, $m);

        return array_values(array_unique((array)($m[0] ?? [])));
    }

    /**
     * Test SMB / Windows Share connection
     *
     * @param array $config
     * @return array{success: bool, message: string}
     */
    private static function test_smb_connection(array $config): array
    {
        $parsed = self::parse_smb_path($config['path'] ?? '');
        if (!$parsed) {
            return [
                'success' => false,
                'message' => sprintf(__('Некорректный путь к сетевой папке Windows (SMB): %s. Используйте формат \\\\хост\\папка\\', 'wp-olympiad'), $config['path'] ?? ''),
            ];
        }

        // 1. If running on native Windows OS, check via standard filesystem
        if (DIRECTORY_SEPARATOR === '\\') {
            return self::test_filesystem_connection($config);
        }

        // 2. If path is already mounted locally in Linux
        $local_mount = self::resolve_local_path($config['path']);
        if (is_dir($local_mount) && is_writable($local_mount)) {
            return [
                'success' => true,
                'message' => sprintf(__('✓ Сетевой ресурс смонтирован в файловую систему ОС (%s) и доступен для записи.', 'wp-olympiad'), $local_mount),
            ];
        }

        // 3. Check smbclient utility
        $smb_bin = self::get_smbclient_binary();
        if (!$smb_bin) {
            return [
                'success' => false,
                'message' => sprintf(
                    __('Для прямого подключения к Windows-папке «%s» на сервере Linux требуется утилита smbclient. Установите её командой: sudo apt install smbclient (либо смонтируйте ресурс в ОС: sudo mount -t cifs %s /mnt/share).', 'wp-olympiad'),
                    $parsed['unc'],
                    $parsed['service']
                ),
            ];
        }

        // 4. Quick port check on 445 / 139
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client("tcp://{$parsed['host']}:445", $errno, $errstr, 2.0);
        if (!$fp && $errno !== 110) {
            $fp = @stream_socket_client("tcp://{$parsed['host']}:139", $errno, $errstr, 2.0);
        }
        if ($fp) {
            fclose($fp);
        } else {
            return [
                'success' => false,
                'message' => sprintf(__('Сетевой хост %s недоступен по портам SMB (445 / 139): %s (%d). Проверьте IP-адрес и доступность сервера в локальной сети.', 'wp-olympiad'), $parsed['host'], $errstr ?: 'Connection timed out', $errno),
            ];
        }

        // 5. Test access via smbclient
        $creds_file = tempnam(sys_get_temp_dir(), 'wpo_smb_creds_');
        $user = !empty($config['user']) ? $config['user'] : $parsed['user'];
        $pass = !empty($config['password']) ? $config['password'] : $parsed['password'];

        if (!empty($user) || !empty($pass)) {
            $domain = '';
            if (str_contains($user, '\\')) {
                [$domain, $user] = explode('\\', $user, 2);
            } elseif (str_contains($user, '/')) {
                [$domain, $user] = explode('/', $user, 2);
            }
            $creds_content = "username = {$user}\npassword = {$pass}\n";
            if (!empty($domain)) {
                $creds_content .= "domain = {$domain}\n";
            }
            file_put_contents($creds_file, $creds_content);
            chmod($creds_file, 0600);
            $auth_args = '-A ' . escapeshellarg($creds_file);
        } else {
            $auth_args = '-N';
        }

        $cmd = sprintf(
            '%s %s %s -c %s -t 10 2>&1',
            escapeshellcmd($smb_bin),
            escapeshellarg($parsed['service']),
            $auth_args,
            escapeshellarg('ls')
        );

        $output_lines = [];
        $exit_code = 1;
        exec($cmd, $output_lines, $exit_code);
        @unlink($creds_file);

        if ($exit_code === 0) {
            return [
                'success' => true,
                'message' => sprintf(__('✓ Сетевая папка Windows «%s» успешно найдена и доступна для выгрузки по протоколу SMB!', 'wp-olympiad'), $parsed['unc']),
            ];
        }

        $error_msg = self::translate_smb_error(implode("\n", $output_lines), $parsed);
        return [
            'success' => false,
            'message' => $error_msg,
        ];
    }

    /**
     * Resolve and normalize local / network filesystem path
     */
    public static function resolve_local_path(string $path): string
    {
        $path = trim($path, " \t\n\r\0\x0B\"'");
        if (empty($path) || self::is_example_path($path)) {
            return self::get_default_network_path();
        }

        // Clean up Windows UNC multiple slashes caused by escaping: \\\\ -> \\
        if (preg_match('#^[\\\\]{2,}#', $path)) {
            $path = '\\\\' . preg_replace('#[\\\\]+#', '\\', ltrim($path, '\\'));
            return $path;
        }
        if (preg_match('#^/{2,}#', $path)) {
            $path = '//' . preg_replace('#/+#', '/', ltrim($path, '/'));
            return $path;
        }

        // Windows drive letter (e.g. C:\... or D:/...)
        if (preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) {
            return $path;
        }

        // Absolute Unix path (e.g. /mnt/share, /tmp/share)
        if (str_starts_with($path, '/')) {
            return $path;
        }

        // Home directory shortcut (~/shares)
        if (str_starts_with($path, '~/')) {
            $home = getenv('HOME') ?: '/home/user';
            return rtrim($home, '/\\') . '/' . substr($path, 2);
        }

        // If path starts with wp-content or uploads
        $upload = wp_upload_dir();
        if (str_starts_with($path, 'wp-content/uploads/')) {
            return rtrim(WP_CONTENT_DIR, '/\\') . '/uploads/' . substr($path, 19);
        }
        if (str_starts_with($path, 'wp-content/')) {
            return rtrim(ABSPATH, '/\\') . '/' . $path;
        }

        // Standard relative path -> resolve to uploads directory
        return wp_normalize_path(rtrim($upload['basedir'], '/\\') . '/' . ltrim($path, '/\\'));
    }
}
