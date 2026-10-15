<?php
declare(strict_types=1);

namespace WPOlympiad;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Password-Protected Network Folder & Storage Exporter
 *
 * Supports exporting olympiad submissions and answer matrices to:
 * 1. Local mounted network shares (NFS, CIFS/SMB, local NAS mounts, Windows shares);
 * 2. WebDAV cloud/network storage with HTTP Basic Authentication;
 * 3. FTP / FTPS network storage;
 * 4. Password-protected ZIP archives encrypted with AES-256 standard encryption.
 */
final class NetworkExport
{
    /**
     * Export a quiz's answers to the configured network folder
     *
     * @param int $quiz_id
     * @param array $override_config Optional overrides for path/credentials/encryption
     * @return array{
     *     success: bool,
     *     message: string,
     *     filename?: string,
     *     destination?: string,
     *     is_encrypted?: bool,
     *     files_count?: int,
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

        if (empty($config['path'])) {
            return [
                'success' => false,
                'message' => __('Не указан путь к сетевой папке в настройках олимпиады.', 'wp-olympiad'),
            ];
        }

        // Generate the password-protected ZIP archive package
        $package = self::generate_export_package($quiz, (string)$config['zip_password']);
        if (!$package['success']) {
            return $package;
        }

        // Deliver package to the network destination
        $delivery = self::deliver_file(
            $package['temp_file'],
            $package['filename'],
            $config
        );

        // Clean up temporary local file
        if (file_exists($package['temp_file'])) {
            @unlink($package['temp_file']);
        }

        if (!$delivery['success']) {
            return $delivery;
        }

        $result_data = [
            'success'      => true,
            'message'      => sprintf(
                __('Ответы олимпиады «%1$s» успешно экспортированы в сетевую папку: %2$s%3$s', 'wp-olympiad'),
                $quiz->title,
                $delivery['destination'],
                !empty($config['zip_password']) ? ' (' . __('Архив защищён паролем AES-256', 'wp-olympiad') . ')' : ''
            ),
            'filename'     => $package['filename'],
            'destination'  => $delivery['destination'],
            'is_encrypted' => !empty($config['zip_password']),
            'files_count'  => $package['files_count'],
            'size'         => $package['size'],
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
     *     zip_password: string,
     *     auto_export: bool,
     *     protect_directory: bool
     * }
     */
    public static function get_quiz_network_config(Quiz $quiz, array $override = []): array
    {
        return [
            'enabled'           => isset($override['enabled']) ? (bool)$override['enabled'] : (bool)$quiz->get_setting('enable_network_export', 0),
            'type'              => (string)($override['type'] ?? $quiz->get_setting('network_folder_type', 'auto')),
            'path'              => trim((string)($override['path'] ?? $quiz->get_setting('network_folder_path', ''))),
            'user'              => trim((string)($override['user'] ?? $quiz->get_setting('network_folder_user', ''))),
            'password'          => (string)($override['password'] ?? $quiz->get_setting('network_folder_password', '')),
            'zip_password'      => (string)($override['zip_password'] ?? $quiz->get_setting('export_zip_password', '')),
            'auto_export'       => isset($override['auto_export']) ? (bool)$override['auto_export'] : (bool)$quiz->get_setting('network_auto_export', 0),
            'protect_directory' => isset($override['protect_directory']) ? (bool)$override['protect_directory'] : (bool)$quiz->get_setting('protect_local_directory', 1),
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
        $path = trim($config['path'] ?? '');
        if (empty($path)) {
            return [
                'success' => false,
                'message' => __('Укажите путь к локальной сетевой папке или URL хранилища.', 'wp-olympiad'),
            ];
        }

        $type = self::detect_type($config);

        switch ($type) {
            case 'webdav':
                return self::test_webdav_connection($config);

            case 'ftp':
                return self::test_ftp_connection($config);

            case 'filesystem':
            default:
                return self::test_filesystem_connection($config);
        }
    }

    /**
     * Generate password-protected ZIP archive package
     *
     * @param Quiz $quiz
     * @param string $zip_password
     * @return array{
     *     success: bool,
     *     message?: string,
     *     temp_file?: string,
     *     filename?: string,
     *     files_count?: int,
     *     size?: int
     * }
     */
    public static function generate_export_package(Quiz $quiz, string $zip_password = ''): array
    {
        if (!class_exists('\ZipArchive')) {
            return [
                'success' => false,
                'message' => __('В PHP не установлено расширение ZipArchive (php-zip).', 'wp-olympiad'),
            ];
        }

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
        $readme_text = self::generate_manifest_text($quiz, $total_attempts, !empty($zip_password));

        // Create temporary directory for building the ZIP
        $upload_dir = wp_upload_dir();
        $temp_base = $upload_dir['basedir'] . '/wpo-network-export-temp';
        wp_mkdir_p($temp_base);

        $safe_slug = sanitize_title($quiz->title) ?: 'olympiad-' . $quiz_id;
        $date_suffix = date('Y-m-d_H-i-s');
        $zip_filename = sprintf('wpo-%s-responses-%s.zip', $safe_slug, $date_suffix);
        $zip_path = $temp_base . '/' . $zip_filename;

        $zip = new \ZipArchive();
        $open_res = $zip->open($zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($open_res !== true) {
            return [
                'success' => false,
                'message' => sprintf(__('Ошибка создания ZIP-архива (код %d).', 'wp-olympiad'), $open_res),
            ];
        }

        $has_pwd = !empty($zip_password);
        if ($has_pwd) {
            $zip->setPassword($zip_password);
        }

        $files = [
            '01_protocol_attempts_and_answers.csv' => $protocol_csv,
            '02_questions_and_grading_keys.csv'    => $keys_csv,
            'README_MANIFEST.txt'                  => $readme_text,
        ];

        foreach ($files as $entry_name => $content) {
            $zip->addFromString($entry_name, $content);
            if ($has_pwd) {
                // Apply AES-256 standard encryption if supported, otherwise standard PKWARE
                if (defined('\ZipArchive::EM_AES_256')) {
                    $zip->setEncryptionName($entry_name, \ZipArchive::EM_AES_256);
                } elseif (defined('\ZipArchive::EM_TRAD_PKWARE')) {
                    $zip->setEncryptionName($entry_name, \ZipArchive::EM_TRAD_PKWARE);
                }
            }
        }

        $zip->close();

        if (!file_exists($zip_path)) {
            return [
                'success' => false,
                'message' => __('Не удалось сохранить файл архива во временную директорию.', 'wp-olympiad'),
            ];
        }

        return [
            'success'     => true,
            'temp_file'   => $zip_path,
            'filename'    => $zip_filename,
            'files_count' => count($files),
            'size'        => (int)filesize($zip_path),
        ];
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
                $ans = $d['correct_answer'] ?? '';
                $syns = $d['synonyms'] ?? [];
                if (!empty($syns) && is_array($syns)) {
                    return $ans . ' (синонимы: ' . implode(', ', $syns) . ')';
                }
                return (string)$ans;

            case 'matching':
                $pairs = $d['pairs'] ?? [];
                $out = [];
                foreach ($pairs as $p) {
                    $pts = isset($p['points']) ? " [{$p['points']} б.]" : '';
                    $out[] = ($p['left'] ?? '') . ' → ' . ($p['right'] ?? '') . $pts;
                }
                return implode('; ', $out);

            case 'ordering':
                $items = $d['items'] ?? [];
                $out = [];
                foreach ($items as $idx => $it) {
                    $out[] = ($idx + 1) . '. ' . ($it['text'] ?? '');
                }
                return implode(' → ', $out);

            case 'fill_blank':
                $blanks = $d['blanks'] ?? [];
                $out = [];
                foreach ($blanks as $b_id => $b_data) {
                    $ans = $b_data['answer'] ?? '';
                    $out[] = "{$b_id}: {$ans}";
                }
                return implode(', ', $out);

            case 'slider':
                $val = $d['correct_value'] ?? 0;
                $tol = (float)($d['tolerance'] ?? 0);
                return $tol > 0 ? "{$val} (допуск ±{$tol})" : (string)$val;

            case 'essay':
                return 'Ручная проверка жюри (критерии: ' . ($q->explanation ?: 'по регламенту') . ')';

            default:
                return '—';
        }
    }

    /**
     * Generate text manifest inside the archive
     */
    private static function generate_manifest_text(
        Quiz $quiz,
        int $total_attempts,
        bool $is_encrypted
    ): string {
        $completed_attempts = Attempt::count(['quiz_id' => $quiz->id, 'status' => 'completed']);
        $passed_attempts = Attempt::count(['quiz_id' => $quiz->id, 'passed' => 1]);
        $pass_rate = $completed_attempts > 0 ? round(($passed_attempts / $completed_attempts) * 100, 1) : 0.0;

        $lines = [
            '========================================================================',
            '  WP OLYMPIAD: ОФИЦИАЛЬНЫЙ АРХИВ ПРОТОКОЛОВ И ОТВЕТОВ ТЕСТИРОВАНИЯ      ',
            '========================================================================',
            '',
            'Олимпиада:            ' . $quiz->title,
            'ID олимпиады:         ' . $quiz->id,
            'Дата экспорта:        ' . current_time('mysql'),
            'Защита архива:        ' . ($is_encrypted ? 'ВКЛЮЧЕНА (Пароль, шифрование AES-256)' : 'БЕЗ ПАРОЛЯ'),
            '',
            'СВОДКА УЧАСТИЯ:',
            '------------------------------------------------------------------------',
            'Всего участников:     ' . $total_attempts,
            'Завершили тест:       ' . $completed_attempts,
            'Успешно сдали (зачёт):' . $passed_attempts . ' (' . $pass_rate . '%)',
            '',
            'СОДЕРЖИМОЕ АРХИВА:',
            '------------------------------------------------------------------------',
            '1. 01_protocol_attempts_and_answers.csv — Полный протокол со всеми ответами',
            '   каждого участника по каждому заданию олимпиады (кодировка UTF-8 BOM).',
            '2. 02_questions_and_grading_keys.csv — Перечень всех заданий с правильными',
            '   ключами, штрафами и критериями оценивания жюри.',
            '3. README_MANIFEST.txt — Этот информационный файл.',
            '',
            'ИНСТРУКЦИЯ ПО ОТКРЫТИЮ В MICROSOFT EXCEL:',
            '------------------------------------------------------------------------',
            '- Файлы CSV сформированы с разделителем точка с запятой (;) и кодировкой',
            '  UTF-8 с меткой порядка байтов (BOM), что обеспечивает корректное',
            '  отображение русского языка в Microsoft Excel 2013-2026 без кракозябр.',
            '- Если архив защищён паролем, введите пароль, заданный организатором олимпиады.',
            '',
            '========================================================================',
            'Сформировано модулем WP Olympiad Network Storage Export v1.0.0',
            '========================================================================',
        ];

        return implode("\r\n", $lines);
    }

    /**
     * Deliver file to the target destination based on detected/specified transport
     */
    private static function deliver_file(string $temp_file, string $filename, array $config): array
    {
        $type = self::detect_type($config);

        switch ($type) {
            case 'webdav':
                return self::deliver_webdav($temp_file, $filename, $config);

            case 'ftp':
                return self::deliver_ftp($temp_file, $filename, $config);

            case 'filesystem':
            default:
                return self::deliver_filesystem($temp_file, $filename, $config);
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

        return 'filesystem';
    }

    /**
     * Deliver to local filesystem or mounted network share folder
     */
    private static function deliver_filesystem(string $temp_file, string $filename, array $config): array
    {
        $dest_dir = self::resolve_local_path($config['path']);

        if (!file_exists($dest_dir)) {
            if (!wp_mkdir_p($dest_dir)) {
                return [
                    'success' => false,
                    'message' => sprintf(__('Не удалось создать директорию сетевой папки: %s', 'wp-olympiad'), $dest_dir),
                ];
            }
        }

        if (!is_writable($dest_dir)) {
            return [
                'success' => false,
                'message' => sprintf(__('Директория «%s» недоступна для записи. Проверьте права доступа в операционной системе.', 'wp-olympiad'), $dest_dir),
            ];
        }

        $dest_file = rtrim($dest_dir, '/\\') . DIRECTORY_SEPARATOR . $filename;

        if (!@copy($temp_file, $dest_file)) {
            return [
                'success' => false,
                'message' => sprintf(__('Ошибка копирования файла в сетевую папку: %s', 'wp-olympiad'), $dest_file),
            ];
        }

        // Protect directory from web access if located inside the WordPress document root
        if (!empty($config['protect_directory'])) {
            self::protect_directory_from_web($dest_dir);
        }

        return [
            'success'     => true,
            'destination' => $dest_file,
        ];
    }

    /**
     * Deliver to WebDAV network folder via HTTP PUT
     */
    private static function deliver_webdav(string $temp_file, string $filename, array $config): array
    {
        $url = rtrim($config['path'], '/') . '/' . rawurlencode($filename);
        $file_content = file_get_contents($temp_file);
        if ($file_content === false) {
            return [
                'success' => false,
                'message' => __('Ошибка чтения временного файла выгрузки.', 'wp-olympiad'),
            ];
        }

        $headers = [
            'Content-Type' => 'application/zip',
        ];

        if (!empty($config['user']) || !empty($config['password'])) {
            $headers['Authorization'] = 'Basic ' . base64_encode("{$config['user']}:{$config['password']}");
        }

        $res = wp_remote_request($url, [
            'method'    => 'PUT',
            'headers'   => $headers,
            'body'      => $file_content,
            'timeout'   => 120,
            'sslverify' => false,
        ]);

        if (is_wp_error($res)) {
            return [
                'success' => false,
                'message' => sprintf(__('Ошибка отправки WebDAV: %s', 'wp-olympiad'), $res->get_error_message()),
            ];
        }

        $code = (int)wp_remote_retrieve_response_code($res);
        if (!in_array($code, [200, 201, 204], true)) {
            return [
                'success' => false,
                'message' => sprintf(__('WebDAV сервер вернул ошибку HTTP %1$d: %2$s', 'wp-olympiad'), $code, wp_remote_retrieve_response_message($res)),
            ];
        }

        return [
            'success'     => true,
            'destination' => $url,
        ];
    }

    /**
     * Deliver to FTP network storage
     */
    private static function deliver_ftp(string $temp_file, string $filename, array $config): array
    {
        if (!function_exists('ftp_connect')) {
            return [
                'success' => false,
                'message' => __('В PHP отсутствует расширение FTP (ext-ftp).', 'wp-olympiad'),
            ];
        }

        $parsed = parse_url($config['path']);
        $host = $parsed['host'] ?? $config['path'];
        $port = isset($parsed['port']) ? (int)$parsed['port'] : 21;
        $remote_dir = $parsed['path'] ?? '/';

        $user = $config['user'] ?: ($parsed['user'] ?? 'anonymous');
        $pass = $config['password'] ?: ($parsed['pass'] ?? '');

        $conn = @ftp_connect($host, $port, 15);
        if (!$conn) {
            return [
                'success' => false,
                'message' => sprintf(__('Не удалось подключиться к FTP серверу %s:%d', 'wp-olympiad'), $host, $port),
            ];
        }

        if (!@ftp_login($conn, $user, $pass)) {
            @ftp_close($conn);
            return [
                'success' => false,
                'message' => sprintf(__('Неверный логин или пароль для FTP сервера %s', 'wp-olympiad'), $host),
            ];
        }

        @ftp_pasv($conn, true);

        if ($remote_dir && $remote_dir !== '/') {
            @ftp_chdir($conn, $remote_dir);
        }

        $upload = @ftp_put($conn, $filename, $temp_file, FTP_BINARY);
        @ftp_close($conn);

        if (!$upload) {
            return [
                'success' => false,
                'message' => sprintf(__('Ошибка загрузки файла на FTP сервер: %s', 'wp-olympiad'), $filename),
            ];
        }

        return [
            'success'     => true,
            'destination' => "ftp://{$host}:{$port}/" . ltrim($remote_dir, '/') . '/' . $filename,
        ];
    }

    /**
     * Test local filesystem connection & writability
     */
    private static function test_filesystem_connection(array $config): array
    {
        $dest_dir = self::resolve_local_path($config['path']);

        if (!file_exists($dest_dir)) {
            if (!@wp_mkdir_p($dest_dir)) {
                return [
                    'success' => false,
                    'message' => sprintf(__('Папка не существует и не может быть создана: %s', 'wp-olympiad'), $dest_dir),
                ];
            }
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
            'message' => sprintf(__('✓ Локальная сетевая папка «%s» найдена и полностью доступна для записи.', 'wp-olympiad'), $dest_dir),
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
     * Resolve and normalize local / network filesystem path
     */
    public static function resolve_local_path(string $path): string
    {
        $path = trim($path);

        // Windows UNC path (e.g. \\server\share\folder)
        if (str_starts_with($path, '\\\\') || str_starts_with($path, '//')) {
            return $path;
        }

        // Windows drive letter (e.g. C:\... or D:/...)
        if (preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) {
            return $path;
        }

        // Absolute Unix path
        if (str_starts_with($path, '/')) {
            return $path;
        }

        // Home directory shortcut
        if (str_starts_with($path, '~/')) {
            $home = getenv('HOME') ?: '/home/user';
            return $home . substr($path, 1);
        }

        // Relative path — relative to ABSPATH
        return rtrim(ABSPATH, '/\\') . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }

    /**
     * Protect local folder from direct web access
     */
    private static function protect_directory_from_web(string $dir): void
    {
        $htaccess = rtrim($dir, '/\\') . '/.htaccess';
        if (!file_exists($htaccess)) {
            $rule = "# Block direct web access to olympiad exports\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n";
            @file_put_contents($htaccess, $rule);
        }

        $index = rtrim($dir, '/\\') . '/index.php';
        if (!file_exists($index)) {
            @file_put_contents($index, "<?php\n// Silence is golden.\n");
        }
    }
}
