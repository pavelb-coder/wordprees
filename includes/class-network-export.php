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
 * 4. Direct CSV protocols, questions keys and audit manifest without archive compression.
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
     * @return array{
     *     success: bool,
     *     message: string,
     *     destination?: string,
     *     primary_file?: string,
     *     files_count?: int,
     *     files_list?: array<int, string>,
     *     download_url?: string,
     *     file_url?: string|null,
     *     size?: int
     * }
     */
    public static function export_quiz(int $quiz_id, array $override_config = []): array
    {
        $quiz = Quiz::get($quiz_id);
        if (!$quiz) {
            return [
                'success' => false,
                'message' => __('Олимпиада не найдена.', 'wp-olympiad'),
            ];
        }

        $config = self::get_quiz_network_config($quiz, $override_config);

        // Generate direct export files (no archive)
        $package = self::generate_export_files($quiz);
        if (!$package['success']) {
            return $package;
        }

        // Deliver files directly to the network destination
        $delivery = self::deliver_files($package['files'], $config);

        if (!$delivery['success']) {
            return $delivery;
        }

        // Export-only connections also receive the editor, without affecting answer delivery.
        DesktopEditor::ensure_for_quiz($quiz, $override_config);

        // Compatibility hook; never delete untracked files from the connected root.
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
            ),
            'destination'  => $delivery['destination'],
            'primary_file' => $package['primary_filename'],
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
     * Deploy question import templates into the network folder on demand
     *
     * @param array $config
     * @param string|null &$error_message Diagnostic message out param
     * @return bool
     */
    public static function deploy_import_templates(array $config, ?string &$error_message = null): bool
    {
        $res = self::deploy_import_templates_result($config);
        if (!$res['success']) {
            $error_message = $res['message'];
            return false;
        }
        return true;
    }

    /**
     * Deploy question import templates with detailed result status and diagnostic reporting
     *
     * @param array $config
     * @return array{success: bool, message: string, destination?: string, files?: array, details?: string}
     */
    public static function deploy_import_templates_result(array $config): array
    {
        try {
            $raw_path = trim((string)($config['path'] ?? ''));
            if ($raw_path === '' || self::is_example_path($raw_path)) {
                $config['path'] = self::get_default_network_path();
            }

            $type = self::detect_type($config);

            // Pre-validate filesystem destinations
            if ($type === 'filesystem') {
                $dest_dir = self::resolve_local_path($config['path']);
                if (!file_exists($dest_dir)) {
                    if (!@wp_mkdir_p($dest_dir)) {
                        return [
                            'success' => false,
                            'message' => sprintf(
                                __('Папка «%s» не существует и не может быть создана веб-сервером. Создайте её вручную в ОС (mkdir -p "%s" && chmod 777 "%s") или оставьте поле пути пустым для использования папки по умолчанию.', 'wp-olympiad'),
                                $dest_dir, $dest_dir, $dest_dir
                            ),
                            'details' => sprintf(__('Директория %s не создана.', 'wp-olympiad'), $dest_dir),
                        ];
                    }
                    @chmod($dest_dir, 0777);
                }

                if (!is_writable($dest_dir)) {
                    $user_info = function_exists('posix_getpwuid') && function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? 'веб-сервер') : 'веб-сервер';
                    return [
                        'success' => false,
                        'message' => sprintf(
                            __('Папка «%s» существует, но защищена от записи для пользователя «%s». Выполните команду: chmod -R 777 "%s" или chown -R %s "%s".', 'wp-olympiad'),
                            $dest_dir, $user_info, $dest_dir, $user_info, $dest_dir
                        ),
                        'details' => sprintf(__('Недостаточно прав на запись в %s.', 'wp-olympiad'), $dest_dir),
                    ];
                }
            }

            $xlsm = NetworkImport::get_template_xlsm();
            $xlsx = NetworkImport::get_template_xlsx();
            $csv = NetworkImport::get_template_csv();
            $json = NetworkImport::get_template_json();
            $readme = NetworkImport::get_template_readme();

            // Шаблоны импорта размещаются внутри папки import/
            // Исключительно латинские/ASCII имена файлов для исключения проблем с кодировками
            $files = [
                'import/questions_template_macros.xlsm' => $xlsm,
                'import/questions_template.xlsx'        => $xlsx,
                'import/questions_template.csv'         => $csv,
                'import/questions_template.json'        => $json,
                'import/questions.csv'                  => $csv,
                'import/questions.json'                 => $json,
                'import/README_IMPORT.txt'              => $readme,
            ];

            // If XLSM or XLSX template binaries are empty on disk, omit empty entries
            $files = array_filter($files, fn($data) => is_string($data) && strlen($data) > 0);

            $res = self::deliver_files($files, $config);

            if (empty($res['success'])) {
                $err = $res['message'] ?? __('Ошибка записи файлов в сетевое хранилище.', 'wp-olympiad');
                return [
                    'success' => false,
                    'message' => sprintf(__('Не удалось поместить шаблоны в сетевую папку «%s»: %s', 'wp-olympiad'), $config['path'], $err),
                    'details' => $err,
                ];
            }

            // No directory-wide cleanup: import may contain the bundled program,
            // teachers' Cyrillic-named questions, drafts and existing results.

            $deployed_dest = $res['destination'] ?? $config['path'];
            return [
                'success'     => true,
                'destination' => $deployed_dest,
                'files'       => array_keys($files),
                'message'     => sprintf(
                    __('Шаблоны для импорта вопросов успешно помещены в сетевую папку (подкаталог import/): questions_template_macros.xlsm, questions_template.xlsx, questions_template.csv, questions_template.json, questions.csv и README_IMPORT.txt. Путь: %s', 'wp-olympiad'),
                    $deployed_dest
                ),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => sprintf(__('Системная ошибка при размещении шаблонов: %s', 'wp-olympiad'), $e->getMessage()),
                'details' => $e->getMessage(),
            ];
        }
    }

    /**
     * Export quiz questions directly to the network folder in 'вопросы/' (CSV, JSON, keys)
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
            'вопросы/questions.csv'                                           => $questions_csv,
            'вопросы/questions.json'                                          => $questions_json,
            'вопросы/список_заданий.csv'                                      => $keys_csv,
            'вопросы/правильные_ответы.csv'                                   => $keys_csv,
            sprintf('вопросы/критерии_и_ответы_%s.csv', $safe_slug)           => $keys_csv,
            sprintf('вопросы/вопросы_%s.csv', $safe_slug)                     => $questions_csv,
            sprintf('вопросы/вопросы_%s.json', $safe_slug)                    => $questions_json,
        ];

        $res = self::deliver_files_public($files, $config);
        if ($res['success']) {
            DesktopEditor::ensure_for_quiz($quiz, $override);
            $dest = $config['path'] ?: self::get_default_network_path();
            return [
                'success'     => true,
                'message'     => sprintf(__('Задания олимпиады успешно сохранены в сетевую папку «вопросы/» (%d файлов).', 'wp-olympiad'), count($files)),
                'destination' => $dest,
                'files_count' => count($files),
                'files'       => array_keys($files),
            ];
        }

        return $res;
    }

    /**
     * Generate direct export files (CSV protocol, questions keys and manifest)
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
    public static function generate_export_files(Quiz $quiz): array
    {
        $quiz_id = $quiz->id;

        // 1. Generate full responses CSV protocol
        $protocol_csv = Export::export_quiz_attempts($quiz_id, false);
        if ($protocol_csv === null) {
            $protocol_csv = '';
        }

        // 2. Generate questions keys & scoring breakdown CSV
        $keys_csv = self::generate_questions_key_csv($quiz);

        // 3. Generate README / Audit manifest
        $total_attempts = Attempt::count(['quiz_id' => $quiz_id]);
        $safe_slug = self::build_safe_slug($quiz);
        $readme_text = self::generate_manifest_text($quiz, $total_attempts, $safe_slug);

        $date_suffix = date('Y-m-d_H-i-s');
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

        $files = [
            // Исключительно русскоязычные папки и названия файлов (протоколы, вопросы, отчёты)
            // Папка «протоколы»
            sprintf('протоколы/протокол_%s.csv', $safe_slug)                   => $protocol_csv,
            'протоколы/сводный_протокол.csv'                                  => $protocol_csv,
            'протоколы/ответы_участников.csv'                                 => $protocol_csv,
            sprintf('протоколы/протокол_%s_%s.csv', $safe_slug, $date_suffix)  => $protocol_csv,

            // Папка «вопросы»
            sprintf('вопросы/критерии_и_ответы_%s.csv', $safe_slug)           => $keys_csv,
            'вопросы/список_заданий.csv'                                      => $keys_csv,
            'вопросы/правильные_ответы.csv'                                   => $keys_csv,
            'вопросы/questions.csv'                                           => (string)(Export::export_quiz_questions_csv($quiz, false) ?? ''),
            'вопросы/questions.json'                                          => (string)(Export::export_quiz_questions_json($quiz, false) ?? ''),

            // Папка «отчёты»
            sprintf('отчёты/манифест_выгрузки_%s.txt', $safe_slug)            => $readme_text,
            'отчёты/руководство_к_протоколам.txt'                             => $readme_text,
            'отчёты/сводка_результатов.txt'                                   => $readme_text,
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

            case 'essay':
                return __('Ручная проверка жюри (развёрнутое решение)', 'wp-olympiad');

            default:
                return '—';
        }
    }

    /**
     * Generate text audit manifest for export package
     */
    private static function generate_manifest_text(
        Quiz $quiz,
        int $total_attempts,
        string $safe_slug = ''
    ): string {
        // «Завершили» = все работы, по которым уже вынесен итог (включая ожидающие жюри)
        $completed_attempts = Attempt::count(['quiz_id' => $quiz->id, 'status' => 'completed'])
            + Attempt::count(['quiz_id' => $quiz->id, 'status' => 'graded'])
            + Attempt::count(['quiz_id' => $quiz->id, 'status' => 'needs_review']);
        $passed_attempts = Attempt::count(['quiz_id' => $quiz->id, 'passed' => 1]);
        $pass_rate = $completed_attempts > 0 ? round(($passed_attempts / $completed_attempts) * 100, 1) : 0.0;
        $slug = !empty($safe_slug) ? $safe_slug : self::build_safe_slug($quiz);

        $lines = [
            '========================================================================',
            '  WP OLYMPIAD: ОФИЦИАЛЬНЫЙ ЭКСПОРТ ПРОТОКОЛОВ И ОТВЕТОВ ТЕСТИРОВАНИЯ     ',
            '========================================================================',
            'Олимпиада:            ' . $quiz->title . ' (ID: ' . $quiz->id . ')',
            'Дата экспорта:        ' . current_time('mysql'),
            'Формат выгрузки:      Прямой экспорт файлов в сетевую папку (без архивации)',
            '',
            'СВОДКА УЧАСТИЯ:',
            '------------------------------------------------------------------------',
            'Всего участников:     ' . $total_attempts,
            'Завершили тест:       ' . $completed_attempts,
            'Успешно сдали (зачёт):' . $passed_attempts . ' (' . $pass_rate . '%)',
            '',
            'СОДЕРЖИМОЕ ВЫГРУЗКИ ПО ПАПКАМ:',
            '------------------------------------------------------------------------',
            '📁 протоколы/ (protocols/):',
            '   - протокол_' . $slug . '.csv — Основной файл протокола с ответами',
            '     участников по каждому заданию олимпиады (кодировка UTF-8 BOM).',
            '   - сводный_протокол.csv, ответы_участников.csv — Сводные таблицы.',
            '',
            '📁 вопросы/ (questions/):',
            '   - критерии_и_ответы_' . $slug . '.csv — Перечень всех заданий с правильными',
            '     ключами, штрафами и критериями оценивания жюри.',
            '   - список_заданий.csv, правильные_ответы.csv — Таблицы ключей.',
            '',
            '📁 попытки/ (attempts/):',
            '   - Детальные индивидуальные протоколы проверки каждого участника.',
            '',
            '📁 отчёты/ (reports/):',
            '   - манифест_выгрузки_' . $slug . '.txt — Этот официальный документ.',
            '   - руководство_к_протоколам.txt — Правила структуры и открытия.',
            '',
            'ИНСТРУКЦИЯ ПО ОТКРЫТИЮ В MICROSOFT EXCEL:',
            '------------------------------------------------------------------------',
            '- Файлы CSV сформированы с разделителем точка с запятой (;) и кодировкой',
            '  UTF-8 с меткой порядка байтов (BOM), что обеспечивает корректное',
            '  отображение русского языка в Microsoft Excel без кракозябр.',
            '',
            '========================================================================',
            'Сформировано модулем WP Olympiad Direct Network Export v1.0.0',
            '========================================================================',
        ];

        return implode("\r\n", $lines);
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
            $res = @file_put_contents($dest_file, $content);
            if ($res !== false) {
                @chmod($dest_file, 0666);
                $written_files[] = $dest_file;
                $total_bytes += $res;
            }
        }

        if (empty($written_files)) {
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
        $base_url = rtrim($config['path'], '/');
        $headers = [];
        if (!empty($config['user']) || !empty($config['password'])) {
            $headers['Authorization'] = 'Basic ' . base64_encode("{$config['user']}:{$config['password']}");
        }

        $written_count = 0;
        $total_bytes = 0;

        foreach ($files as $name => $content) {
            $url = $base_url . '/' . rawurlencode($name);
            $mime = str_ends_with($name, '.csv') ? 'text/csv; charset=UTF-8' : 'text/plain; charset=UTF-8';

            $res = wp_remote_request($url, [
                'method'    => 'PUT',
                'headers'   => array_merge($headers, ['Content-Type' => $mime]),
                'body'      => $content,
                'timeout'   => 30,
                'sslverify' => false,
            ]);

            if (!is_wp_error($res)) {
                $code = (int)wp_remote_retrieve_response_code($res);
                if ($code >= 200 && $code < 300) {
                    $written_count++;
                    $total_bytes += strlen($content);
                }
            }
        }

        if ($written_count === 0) {
            return [
                'success' => false,
                'message' => __('Ошибка отправки файлов на WebDAV сервер.', 'wp-olympiad'),
            ];
        }

        return [
            'success'     => true,
            'destination' => $base_url,
            'size'        => $total_bytes,
        ];
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
        if (!function_exists('ftp_connect')) {
            return [
                'success' => false,
                'message' => __('В PHP не установлено расширение FTP.', 'wp-olympiad'),
            ];
        }

        $parsed = parse_url($config['path']);
        $host = $parsed['host'] ?? $config['path'];
        $port = isset($parsed['port']) ? (int)$parsed['port'] : 21;
        $remote_dir = $parsed['path'] ?? '/';

        $conn = @ftp_connect($host, $port, 15);
        if (!$conn) {
            return [
                'success' => false,
                'message' => sprintf(__('Не удалось подключиться к FTP-серверу %s:%d', 'wp-olympiad'), $host, $port),
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

        @ftp_pasv($conn, true);

        if (!empty($remote_dir) && $remote_dir !== '/') {
            @ftp_chdir($conn, $remote_dir);
        }

        $written_count = 0;
        $total_bytes = 0;

        foreach ($files as $name => $content) {
            $h = fopen('php://temp', 'r+');
            if ($h === false) {
                continue;
            }
            fwrite($h, $content);
            rewind($h);
            $remote_file = rtrim($remote_dir, '/') . '/' . $name;
            $upload = @ftp_fput($conn, $remote_file, $h, FTP_BINARY);
            fclose($h);

            if ($upload) {
                $written_count++;
                $total_bytes += strlen($content);
            }
        }

        @ftp_close($conn);

        if ($written_count === 0) {
            return [
                'success' => false,
                'message' => __('Ошибка загрузки файлов на FTP сервер.', 'wp-olympiad'),
            ];
        }

        return [
            'success'     => true,
            'destination' => "ftp://{$host}:{$port}/" . ltrim($remote_dir, '/'),
            'size'        => $total_bytes,
        ];
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
    public static function translate_smb_error(string $raw_error, array $parsed): string
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
        if (str_contains($raw_error, 'NT_STATUS_HOST_UNREACHABLE')) {
            return sprintf(__('Сетевой хост %s недостижим (NT_STATUS_HOST_UNREACHABLE).', 'wp-olympiad'), $parsed['host']);
        }

        $clean = trim((string)preg_replace('/\s+/', ' ', strip_tags($raw_error)));
        return sprintf(__('Ошибка выгрузки в сетевую папку «%s»: %s', 'wp-olympiad'), $parsed['unc'], $clean ?: 'Неизвестная ошибка SMB');
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

        // Build SMB commands
        $commands = [];
        if (!empty($parsed['subdir'])) {
            $parts = explode('/', trim($parsed['subdir'], '/'));
            foreach ($parts as $p) {
                $commands[] = 'mkdir "' . $p . '"';
                $commands[] = 'cd "' . $p . '"';
            }
        }

        foreach ($written_tmp as $name => $path_tmp) {
            $parent_rel = dirname($name);
            if ($parent_rel !== '.' && !empty($parent_rel)) {
                $commands[] = 'mkdir "' . str_replace('/', '\\', $parent_rel) . '"';
            }
            $commands[] = 'put "' . $path_tmp . '" "' . str_replace('/', '\\', $name) . '"';
        }

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

        // Cleanup temp files
        @unlink($creds_file);
        self::delete_directory_recursive($tmp_dir);

        if ($exit_code === 0) {
            return [
                'success'     => true,
                'destination' => $parsed['unc'],
                'files'       => array_keys($files),
                'size'        => array_sum(array_map('strlen', $files)),
            ];
        }

        $error_msg = self::translate_smb_error(implode("\n", $output_lines), $parsed);
        return [
            'success' => false,
            'message' => $error_msg,
        ];
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
