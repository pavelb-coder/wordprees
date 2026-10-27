<?php
declare(strict_types=1);

namespace WPOlympiad;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Questions Importer from Network Storage and Templates (CSV / JSON)
 *
 * Supports importing questions into an Olympiad by template directly from:
 * 1. Configured local or mounted network share directories;
 * 2. Windows SMB network shares;
 * 3. FTP and WebDAV cloud/network storages;
 * 4. Structured CSV and JSON templates with full question types support.
 */
final class NetworkImport
{
    public const CRON_HOOK = 'wp_olympiad_network_auto_import_cron';
    public const CRON_RECURRENCE = 'wpo_olympiad_minute';

    public static function init(): void
    {
        add_filter('cron_schedules', [self::class, 'filter_cron_schedules']);
        add_action(self::CRON_HOOK, [self::class, 'run_scheduled_auto_imports']);
        // Repair existing hourly/malformed events too, not only missing events.
        add_action('init', [self::class, 'schedule_cron'], 20);
        NetworkSync::init();
    }

    public static function filter_cron_schedules(array $schedules): array
    {
        $minute = ['interval' => 60, 'display' => __('WP Olympiad — каждую минуту', 'wp-olympiad')];
        $schedules[self::CRON_RECURRENCE] = $minute;
        if (!isset($schedules['every_minute'])) { $schedules['every_minute'] = $minute; }
        return $schedules;
    }

    public static function schedule_cron(): void
    {
        // Activation can run before plugins_loaded initialized this component.
        add_filter('cron_schedules', [self::class, 'filter_cron_schedules']);
        $event = wp_get_scheduled_event(self::CRON_HOOK);
        $count = 0;
        foreach (_get_cron_array() as $hooks) { $count += count($hooks[self::CRON_HOOK] ?? []); }
        if ($count !== 1 || !$event || $event->schedule !== self::CRON_RECURRENCE || (int)($event->interval ?? 0) !== 60 || $event->timestamp > time() + 120) {
            wp_unschedule_hook(self::CRON_HOOK);
            wp_schedule_event(time() + 5, self::CRON_RECURRENCE, self::CRON_HOOK);
        }
    }

    public static function clear_cron(): void
    {
        NetworkSync::cancel_request_check();
        wp_unschedule_hook(self::CRON_HOOK);
        wp_unschedule_hook(NetworkSync::CONTINUE_HOOK);
    }

    public static function run_scheduled_auto_imports(): array
    {
        // Questions always run first. Copying a remote EXE must not starve imports.
        $results = NetworkSync::run('cron');
        $state = get_option(NetworkSync::STATE, []);
        if (empty($state['has_more'])) { NetworkSync::deploy_editors(); }
        return $results;
    }

    /** Download the single bundled question template; user import formats are unchanged. */
    public static function download_template(string $format = 'xlsx'): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('У вас недостаточно прав для выполнения этой операции.', 'wp-olympiad'), '', ['response' => 403]);
        }
        if (strtolower($format) !== 'xlsx') {
            wp_die(esc_html__('Этот образец удалён из комплекта. Доступен шаблон Excel (.xlsx). Импорт ваших CSV, JSON и XLSM по-прежнему поддерживается.', 'wp-olympiad'), '', ['response' => 410]);
        }
        $content = self::get_template_xlsx();
        if (!str_starts_with($content, "PK\x03\x04")) {
            wp_die(esc_html__('Шаблон Excel недоступен. Переустановите актуальный ZIP плагина.', 'wp-olympiad'), '', ['response' => 503]);
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="questions_template.xlsx"');
        header('Content-Length: ' . strlen($content));
        header('Pragma: no-cache'); header('Expires: 0');
        echo $content;
        exit;
    }

    /** Legacy accessor retained without serving or rebuilding the removed macro template. */
    public static function get_template_xlsm(): string { return ''; }

    /**
     * Get binary content of standard Excel template (.xlsx)
     */
    public static function get_template_xlsx(): string
    {
        $path = WP_OLYMPIAD_PATH . 'templates/questions_template.xlsx';
        if (file_exists($path) && is_readable($path)) {
            $data = @file_get_contents($path);
            if ($data !== false && strlen($data) > 0) {
                return $data;
            }
        }
        return '';
    }

    /**
     * Generate sample CSV template (UTF-8 BOM, ';' delimiter, all 13 question types).
     */
    public static function get_template_csv(): string
    {
        return ImportTemplate::csv();
    }

    /**
     * Generate sample JSON template
     */
    /**
     * Generate sample JSON template (all 13 question types).
     */
    public static function get_template_json(): string
    {
        return ImportTemplate::json();
    }

    /**
     * Get import guide and syntax readme text
     */
    /**
     * Get import guide and syntax readme text
     */
    public static function get_template_readme(): string
    {
        return ImportTemplate::readme();
    }

    /**
     * Find question files in the connected storage. A populated import/ takes
     * precedence; only when it contains no supported files do we use the
     * former search (recursive on a local mount, configured root on a remote
     * connection). $error distinguishes an unreadable import/ from an empty
     * one, so an access failure cannot silently import an unrelated root file.
     *
     * Returned paths are always relative to the configured storage root.
     *
     * @return array<int, array{name: string, path: string, size: int, modified: string, ext: string}>
     */
    public static function scan_network_folder_for_import_files(Quiz $quiz, array $override = [], ?string &$error = null): array
    {
        $error = null;
        $config = NetworkExport::get_quiz_network_config($quiz, $override);
        $type = NetworkExport::detect_type($config);
        if ($type === 'smb' && is_dir(NetworkExport::resolve_local_path((string)$config['path']))) {
            // A Windows share can also be supplied as an already-mounted local path.
            $type = 'filesystem';
        }

        $prefix = DesktopEditor::import_prefix($config);
        $files = self::scan_import_location($config, $type, $prefix, false, $error);
        if ($error !== null) {
            return [];
        }
        if ($files !== []) {
            return self::sort_import_files($files);
        }

        // When the configured root already is import/, do not append import/
        // again. The local legacy search can still inspect its subdirectories.
        if ($type !== 'filesystem' && $prefix === '') {
            return [];
        }
        $files = self::scan_import_location($config, $type, '', $type === 'filesystem', $error);
        return $error === null ? self::sort_import_files($files) : [];
    }

    private static function scan_import_location(array $config, string $type, string $prefix, bool $recursive, ?string &$error): array
    {
        switch ($type) {
            case 'smb':
                return self::scan_smb_folder($config, $prefix, $error);
            case 'webdav':
                return self::scan_webdav_folder($config, $prefix, $error);
            case 'ftp':
                return self::scan_ftp_folder($config, $prefix, $error);
            default:
                return self::scan_filesystem_folder($config, $prefix, $recursive, $error);
        }
    }

    private static function is_import_filename(string $name): bool
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return in_array($ext, ['csv', 'json', 'tsv', 'xlsx', 'xlsm'], true)
            && !preg_match('/(?:readme|manifest|инструкц|~\$)/iu', $name);
    }

    /**
     * A bare configured filename is a search by name: look for that name in
     * import/ first, then retain its old root/common-folder lookup. A path
     * containing a slash is explicit (./file.csv selects the root exactly).
     *
     * @return string|null Relative path, or null if import/ could not be checked.
     */
    private static function resolve_named_import(Quiz $quiz, string $filename, array $override, ?string &$error): ?string
    {
        $error = null;
        if (str_contains($filename, '/') || str_contains($filename, '\\')) return $filename;
        $config = NetworkExport::get_quiz_network_config($quiz, $override);
        $prefix = DesktopEditor::import_prefix($config);
        if ($prefix === '') return $filename;
        $type = NetworkExport::detect_type($config);
        if ($type === 'smb' && is_dir(NetworkExport::resolve_local_path((string)$config['path']))) $type = 'filesystem';
        $files = self::scan_import_location($config, $type, $prefix, false, $error);
        if ($error !== null) return null;

        $match = null;
        foreach (self::sort_import_files($files) as $file) {
            if ((string)$file['name'] === $filename) return (string)$file['path'];
            if (self::filename_match_key((string)$file['name']) === self::filename_match_key($filename)) {
                $match = $match ?? (string)$file['path'];
            }
        }
        return $match ?? $filename;
    }

    /** Keep the existing name priority, consistently across discovery, preview and import. */
    private static function sort_import_files(array $files): array
    {
        $priority = array_flip([
            'questions.csv', 'questions.xlsx', 'questions.xlsm', 'questions.json',
            'import.csv', 'import.xlsx', 'import.xlsm', 'import.json',
            'questions_template_macros.xlsm', 'questions_template.xlsx', 'questions_template.csv',
            'шаблон_вопросов_макросы.xlsm', 'шаблон_вопросов.xlsx', 'шаблон_вопросов.csv',
        ]);
        $key = static function (array $file) use ($priority): array {
            $name = self::filename_match_key((string)$file['name']);
            $general = str_starts_with($name, 'questions') ? 0
                : ((str_starts_with($name, 'тест') || str_starts_with($name, 'test')) ? 1 : 2);
            return [
                self::is_template_like_name($name) ? 1 : 0,
                $priority[$name] ?? 100,
                $general,
                (int)($file['depth'] ?? 0),
                -(int)($file['mtime'] ?? 0),
                self::filename_match_key((string)$file['path']),
                (string)$file['path'],
            ];
        };
        usort($files, static fn(array $a, array $b): int => $key($a) <=> $key($b));
        return $files;
    }

    /** Local/mounted storage: direct import/ first, then the old three-level scan. */
    private static function scan_filesystem_folder(array $config, string $prefix, bool $recursive, ?string &$error): array
    {
        $root = NetworkExport::resolve_local_path((string)($config['path'] ?? ''));
        if (!is_dir($root)) {
            $error = sprintf(__('Путь %s не существует или не виден серверу.', 'wp-olympiad'), $root);
            return [];
        }
        $dir = $prefix !== '' ? rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'import' : $root;
        if (!is_dir($dir)) {
            if ($prefix !== '' && !file_exists($dir) && !is_link($dir)) {
                return []; // No import/ yet: legacy search is allowed.
            }
            $error = __('Путь import существует, но не является доступной папкой.', 'wp-olympiad');
            return [];
        }

        $files = [];
        $skip_dirs = ['$RECYCLE.BIN', 'System Volume Information', '.git', 'node_modules', '.Trash', '.Trashes'];
        $queue = [[$dir, $prefix, 0]];
        while ($queue !== []) {
            [$current, $relative, $depth] = array_shift($queue);
            $entries = @scandir($current);
            if ($entries === false) {
                if ($depth === 0) {
                    $error = __('Не удалось прочитать папку import или подключённое хранилище. Проверьте права доступа.', 'wp-olympiad');
                    return [];
                }
                continue; // Preserve the old behaviour for inaccessible legacy subfolders.
            }
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                $full = rtrim($current, '/\\') . DIRECTORY_SEPARATOR . $entry;
                $name = self::normalize_filename($entry);
                if (is_dir($full)) {
                    if ($recursive && $depth < 3 && !in_array($entry, $skip_dirs, true)) {
                        $queue[] = [$full, $relative . $name . '/', $depth + 1];
                    }
                    continue;
                }
                if (!is_file($full) || !self::is_import_filename($name)) continue;
                $mtime = (int)@filemtime($full);
                $files[] = [
                    'name' => $name, 'path' => $relative . $name,
                    'size' => (int)@filesize($full), 'modified' => $mtime ? date('Y-m-d H:i:s', $mtime) : '',
                    'ext' => strtolower(pathinfo($name, PATHINFO_EXTENSION)), 'mtime' => $mtime, 'depth' => $depth,
                ];
            }
        }
        return $files;
    }

    /** FTP: list the actual import directory rather than only the configured root. */
    private static function scan_ftp_folder(array $config, string $prefix, ?string &$error): array
    {
        if (!function_exists('ftp_connect')) {
            $error = __('Расширение PHP FTP недоступно; проверить папку import не удалось.', 'wp-olympiad');
            return [];
        }
        $url = parse_url((string)($config['path'] ?? ''));
        $host = is_array($url) ? (string)($url['host'] ?? '') : '';
        $port = is_array($url) ? (int)($url['port'] ?? 21) : 21;
        $remote_dir = is_array($url) ? rawurldecode((string)($url['path'] ?? '/')) : '/';
        if ($host === '') {
            $error = __('Некорректный адрес FTP-хранилища.', 'wp-olympiad');
            return [];
        }
        $secure = strtolower((string)($url['scheme'] ?? 'ftp')) === 'ftps';
        if ($secure && !function_exists('ftp_ssl_connect')) {
            $error = __('На сервере недоступно расширение PHP FTPS.', 'wp-olympiad');
            return [];
        }
        $conn = $secure ? @ftp_ssl_connect($host, $port, 8) : @ftp_connect($host, $port, 8);
        if (!$conn) {
            $error = __('Не удалось подключиться к FTP-хранилищу для поиска заданий.', 'wp-olympiad');
            return [];
        }
        try {
            if (!@ftp_login($conn, (string)($config['user'] ?? '') ?: 'anonymous', (string)($config['password'] ?? ''))) {
                $error = __('Не удалось войти в FTP-хранилище. Проверьте логин и пароль.', 'wp-olympiad');
                return [];
            }
            @ftp_pasv($conn, true);
            if (!@ftp_chdir($conn, $remote_dir !== '' ? $remote_dir : '/')) {
                $error = __('Не удалось открыть настроенную FTP-папку.', 'wp-olympiad');
                return [];
            }
            if ($prefix !== '' && !@ftp_chdir($conn, 'import')) {
                // FTP uses the same 550 code for an absent and an unreadable
                // directory. Only fall back when the parent listing confirms
                // that import is absent.
                $parent = @ftp_nlist($conn, '.');
                if (!is_array($parent)) {
                    $error = __('Не удалось проверить наличие папки import на FTP.', 'wp-olympiad');
                    return [];
                }
                foreach ($parent as $item) {
                    if (basename(str_replace('\\', '/', (string)$item)) === 'import') {
                        $error = __('Папка import на FTP найдена, но недоступна для чтения.', 'wp-olympiad');
                        return [];
                    }
                }
                return [];
            }

            $files = [];
            $listing = function_exists('ftp_mlsd') ? @ftp_mlsd($conn, '.') : false;
            if (is_array($listing)) {
                foreach ($listing as $entry) {
                    $raw_name = (string)($entry['name'] ?? '');
                    $name = self::normalize_filename($raw_name);
                    $entry_type = strtolower((string)($entry['type'] ?? ''));
                    if (in_array($entry_type, ['dir', 'cdir', 'pdir'], true)) continue;
                    if ($entry_type !== 'file') {
                        if (self::is_import_filename($name)) {
                            $error = __('FTP-сервер не подтвердил тип найденного файла; укажите путь вручную.', 'wp-olympiad');
                            return [];
                        }
                        continue;
                    }
                    if (!self::is_import_filename($name) || str_contains($raw_name, '/') || str_contains($raw_name, '\\')) continue;
                    $modified_raw = (string)($entry['modify'] ?? '');
                    $modified = preg_match('/^\d{14}/', $modified_raw) ? strtotime(substr($modified_raw, 0, 14) . ' UTC') : false;
                    $mtime = $modified === false ? 0 : $modified;
                    $files[] = [
                        'name' => $name, 'path' => $prefix . $raw_name,
                        'size' => (int)($entry['size'] ?? 0), 'modified' => $mtime ? date('Y-m-d H:i:s', $mtime) : '',
                        'ext' => strtolower(pathinfo($name, PATHINFO_EXTENSION)), 'mtime' => $mtime, 'depth' => 0,
                    ];
                }
                return $files;
            }

            // Older FTP servers without MLSD: accept only entries for which
            // SIZE confirms a regular file; never treat an unknown listing as empty.
            $listing = @ftp_nlist($conn, '.');
            if (!is_array($listing)) {
                $error = __('FTP-сервер не предоставил список файлов папки.', 'wp-olympiad');
                return [];
            }
            foreach ($listing as $item) {
                $raw_name = basename(str_replace('\\', '/', (string)$item));
                $name = self::normalize_filename($raw_name);
                if (!self::is_import_filename($name)) continue;
                $size = @ftp_size($conn, $raw_name);
                if ($size < 0) {
                    $error = __('FTP-сервер не подтвердил тип найденного файла. Укажите путь вручную или включите MLSD/SIZE.', 'wp-olympiad');
                    return [];
                }
                $mtime = (int)@ftp_mdtm($conn, $raw_name);
                if ($mtime < 0) $mtime = 0;
                $files[] = [
                    'name' => $name, 'path' => $prefix . $raw_name,
                    'size' => $size, 'modified' => $mtime ? date('Y-m-d H:i:s', $mtime) : '',
                    'ext' => strtolower(pathinfo($name, PATHINFO_EXTENSION)), 'mtime' => $mtime, 'depth' => 0,
                ];
            }
            return $files;
        } finally {
            @ftp_close($conn);
        }
    }

    /**
     * Имя файла похоже на образец/шаблон, а не на рабочий список заданий.
     */
    public static function is_template_like_name(string $name): bool
    {
        $name = mb_strtolower(self::normalize_filename($name));

        return (bool)preg_match('/(?:template|шаблон|образец|sample|пример)/iu', $name);
    }

    /** Files explicitly named as examples/templates come after working files. */
    public static function is_plugin_template_file(string $path): bool
    {
        $path = str_replace('\\', '/', trim($path));
        return self::is_template_like_name(basename($path));
    }

    /**
     * Describe what actually lies in the connected network folder — used in error messages,
     * so the administrator immediately sees whether the path exists and what files are there.
     */
    public static function describe_network_folder(Quiz $quiz, array $override = []): string
    {
        $config = NetworkExport::get_quiz_network_config($quiz, $override);
        $type = NetworkExport::detect_type($config);
        $raw_path = (string)($config['path'] ?? '');

        if ($type === 'smb') {
            $parsed = NetworkExport::parse_smb_path($raw_path);
            $hint = NetworkExport::get_smbclient_binary()
                ? __('Для доступа используется smbclient.', 'wp-olympiad')
                : __('Утилита smbclient на сервере не найдена — смонтируйте ресурс (sudo mount -t cifs %s /mnt/share) и укажите локальный путь.', 'wp-olympiad');

            return sprintf(
                __('Тип подключения: SMB (%1$s). %2$s', 'wp-olympiad'),
                $parsed['unc'] ?? $raw_path,
                str_contains($hint, '%s') && isset($parsed['service']) ? sprintf($hint, $parsed['service']) : $hint
            );
        }

        if ($type === 'webdav') {
            return sprintf(
                __('Тип подключения: WebDAV/HTTP, адрес: %1$s. Если сервер не отдаёт список файлов (PROPFIND), укажите имя файла вручную в поле «Или введите относительный путь».', 'wp-olympiad'),
                $raw_path
            );
        }

        if ($type === 'ftp') {
            return sprintf(__('Тип подключения: FTP, адрес: %s.', 'wp-olympiad'), $raw_path);
        }

        $dest_dir = NetworkExport::resolve_local_path($raw_path);

        if (!is_dir($dest_dir)) {
            return sprintf(
                __('Путь %s не существует или не виден серверу (для сетевых ресурсов Windows нужен смонтированный каталог, например /mnt/share).', 'wp-olympiad'),
                $dest_dir
            );
        }

        if (!is_readable($dest_dir)) {
            return sprintf(__('Папка %s недоступна для чтения — проверьте права доступа (chmod).', 'wp-olympiad'), $dest_dir);
        }

        $scan = @scandir($dest_dir);
        if ($scan === false) {
            return sprintf(__('Не удалось прочитать содержимое папки %s.', 'wp-olympiad'), $dest_dir);
        }

        $items = array_values(array_filter($scan, static fn(string $f): bool => $f !== '.' && $f !== '..'));
        if (empty($items)) {
            return sprintf(__('Папка %s существует, но пуста.', 'wp-olympiad'), $dest_dir);
        }

        $shown = [];
        foreach (array_slice($items, 0, 12) as $item) {
            $name = self::normalize_filename($item);
            $shown[] = is_dir(rtrim($dest_dir, '/\\') . DIRECTORY_SEPARATOR . $item) ? $name . '/' : $name;
        }

        $total = count($items);
        $suffix = $total > count($shown) ? sprintf(__(' и ещё %d', 'wp-olympiad'), $total - count($shown)) : '';

        $message = sprintf(
            __('Папка %1$s доступна. Содержимое: %2$s%3$s.', 'wp-olympiad'),
            $dest_dir,
            implode(', ', $shown),
            $suffix
        );

        // Подсказка, если в папке лежат книги в неподдерживаемом формате
        $unsupported = [];
        foreach ($items as $item) {
            $ext = strtolower(pathinfo(self::normalize_filename($item), PATHINFO_EXTENSION));
            if (in_array($ext, ['xls', 'ods', 'numbers'], true)) {
                $unsupported[] = self::normalize_filename($item);
            }
        }
        if (!empty($unsupported)) {
            $message .= ' ' . sprintf(
                __('Найдены файлы неподдерживаемого формата (%s) — откройте их в Excel и сохраните как «Книга Excel (.xlsx)».', 'wp-olympiad'),
                implode(', ', array_slice($unsupported, 0, 5))
            );
        }

        return $message;
    }

    /**
     * Прочитать локальный файл, вернув причину неудачи через $reason.
     *
     * @param string $path
     * @param string|null $reason not_found|not_file|not_readable|empty|read_error
     */
    private static function read_local_file(string $path, ?string &$reason = null): ?string
    {
        $reason = null;

        if (!file_exists($path)) {
            return null;
        }
        if (!is_file($path)) {
            $reason = 'not_file';
            return null;
        }
        if (!is_readable($path)) {
            $reason = 'not_readable';
            return null;
        }

        $size = @filesize($path);
        if ($size === 0) {
            $reason = 'empty';
            return null;
        }

        $content = @file_get_contents($path);
        if ($content === false || $content === '') {
            $reason = 'read_error';
            return null;
        }

        return $content;
    }

    /**
     * Человеко-понятное описание причины, по которой файл не прочитан.
     */
    private static function describe_read_error(string $path, string $reason): string
    {
        $name = basename($path);

        switch ($reason) {
            case 'not_file':
                return sprintf(__('По пути «%s» находится каталог, а не файл.', 'wp-olympiad'), $name);
            case 'not_readable':
                return sprintf(__('Файл «%s» найден, но недоступен для чтения. Проверьте права доступа (chmod) к файлу и папке.', 'wp-olympiad'), $name);
            case 'empty':
                return sprintf(__('Файл «%s» пуст (0 байт).', 'wp-olympiad'), $name);
            case 'read_error':
                return sprintf(__('Не удалось прочитать файл «%s» (ошибка чтения с диска).', 'wp-olympiad'), $name);
            default:
                return sprintf(__('Не удалось прочитать файл «%s».', 'wp-olympiad'), $name);
        }
    }

    /**
     * Нормализовать имя файла: Unicode NFC и перекодирование из Windows-1251 при необходимости.
     */
    private static function normalize_filename(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }

        // Имена, полученные из scandir() на Windows-хранилищах, могут быть в CP1251
        if (function_exists('mb_check_encoding') && !mb_check_encoding($name, 'UTF-8')) {
            $converted = @mb_convert_encoding($name, 'UTF-8', 'Windows-1251');
            if (is_string($converted) && $converted !== '' && mb_check_encoding($converted, 'UTF-8')) {
                $name = $converted;
            }
        }

        if (class_exists('\Normalizer')) {
            $normalized = @\Normalizer::normalize($name, \Normalizer::FORM_C);
            if (is_string($normalized) && $normalized !== '') {
                $name = $normalized;
            }
        }

        return $name;
    }

    /**
     * Ключ для регистронезависимого сравнения имён файлов.
     */
    private static function filename_match_key(string $name): string
    {
        $name = self::normalize_filename($name);
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        return function_exists('mb_strtolower') ? mb_strtolower(trim($name), 'UTF-8') : strtolower(trim($name));
    }

    /**
     * Варианты записи имени файла, которые могут существовать на диске.
     *
     * @return array<int, string>
     */
    private static function filename_variants(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return [];
        }

        $variants = [$name];

        if (class_exists('\Normalizer')) {
            foreach ([\Normalizer::FORM_C, \Normalizer::FORM_D] as $form) {
                $normalized = @\Normalizer::normalize($name, $form);
                if (is_string($normalized) && $normalized !== '') {
                    $variants[] = $normalized;
                }
            }
        }

        // Windows-хранилища и SMB-монтирования часто отдают имена в национальной кодировке
        if (function_exists('mb_convert_encoding') && preg_match('/[^\x00-\x7F]/', $name)) {
            foreach (['Windows-1251', 'CP866'] as $encoding) {
                $converted = @mb_convert_encoding($name, $encoding, 'UTF-8');
                if (is_string($converted) && $converted !== '' && $converted !== $name) {
                    $variants[] = $converted;
                }
            }
        }

        return array_values(array_unique($variants));
    }

    /**
     * Найти файл в каталоге по нормализованному имени (регистр и кодировка не важны).
     */
    private static function find_local_file(string $root, string $needle, int $max_depth = 3): ?string
    {
        $target = self::filename_match_key($needle);
        if ($target === '') {
            return null;
        }

        $queue = [[$root, 0]];
        $visited = 0;

        while (!empty($queue)) {
            [$dir, $level] = array_shift($queue);
            $items = @scandir($dir);
            if ($items === false) {
                continue;
            }

            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }

                $full = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $item;

                if (is_dir($full)) {
                    if ($level < $max_depth) {
                        $queue[] = [$full, $level + 1];
                    }
                    continue;
                }

                if (self::filename_match_key($item) === $target) {
                    return $full;
                }
            }

            if (++$visited > 500) {
                break;
            }
        }

        return null;
    }

    /**
     * Read file content from the network storage
     */
    public static function read_network_file(Quiz $quiz, string $relative_path, array $override = [], ?string &$error = null): ?string
    {
        $error = null;
        $relative_path = trim($relative_path);
        if ($relative_path === '') {
            $error = __('Не указано имя файла заданий.', 'wp-olympiad');
            return null;
        }
        foreach (explode('/', str_replace('\\', '/', $relative_path)) as $segment) {
            if ($segment === '..' || rawurldecode($segment) === '..' || str_contains($segment, "\0")) {
                $error = __('Укажите путь к файлу относительно подключённой папки без переходов на уровень выше.', 'wp-olympiad');
                return null;
            }
        }

        $config = NetworkExport::get_quiz_network_config($quiz, $override);
        $type = NetworkExport::detect_type($config);

        switch ($type) {
            case 'ftp':
                return self::read_ftp_file($config, $relative_path, $error);

            case 'webdav':
                return self::read_webdav_file($config, $relative_path, $error);

            case 'smb':
                // Windows-сервер, смонтированный ресурс или доступный smbclient
                $local_error = null;
                $content = self::read_local_network_file($quiz, $relative_path, $config, $local_error);
                if ($content !== null) {
                    return $content;
                }
                $smb_error = null;
                $content = self::read_smb_file($config, $relative_path, $smb_error);
                if ($content !== null) {
                    return $content;
                }
                $error = $smb_error ?: $local_error
                    ?: sprintf(__('Не удалось прочитать файл «%s» из SMB-ресурса.', 'wp-olympiad'), $relative_path);
                return null;

            case 'filesystem':
            default:
                $content = self::read_local_network_file($quiz, $relative_path, $config, $error);
                if ($content === null && ($error === null || $error === '')) {
                    $error = sprintf(__('Не удалось прочитать файл «%s» из сетевой папки.', 'wp-olympiad'), $relative_path);
                }
                return $content;
        }
    }

    /**
     * Read a file from a local (or mounted) folder.
     */
    private static function read_local_network_file(Quiz $quiz, string $relative_path, array $config, ?string &$error = null): ?string
    {
        $error = null;
        $dest_dir = NetworkExport::resolve_local_path($config['path'] ?? '');
        $norm_rel = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, ltrim($relative_path, '/\\'));
        $clean_basename = basename($norm_rel);

        if (!is_dir($dest_dir)) {
            $error = sprintf(
                __('Сетевая папка недоступна: %s. Проверьте путь и права доступа.', 'wp-olympiad'),
                $dest_dir
            );
            return null;
        }

        // A discovered/explicit relative path must stay in that directory: if
        // import/file.csv disappears, do not silently read root/file.csv instead.
        // A bare filename retains its historical search in common directories.
        $has_directory = str_contains($norm_rel, DIRECTORY_SEPARATOR);
        $sub_dirs = $has_directory ? [''] : ['', 'import', 'questions', 'вопросы', 'задания', 'templates'];
        $dirs = [];
        foreach ($sub_dirs as $sub) {
            $dirs[] = $sub === '' ? rtrim($dest_dir, '/\\') : rtrim($dest_dir, '/\\') . DIRECTORY_SEPARATOR . $sub;
        }

        // Варианты имени: исходное, NFC/NFD и перекодировки для Windows-хранилищ (CP1251/CP866)
        $rel_variants  = self::filename_variants($norm_rel);
        $name_variants = $has_directory ? [] : self::filename_variants($clean_basename);

        $reason = null;
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (array_merge($rel_variants, $name_variants) as $variant) {
                $cand = $dir . DIRECTORY_SEPARATOR . $variant;
                $content = self::read_local_file($cand, $reason);
                if ($content !== null) {
                    return $content;
                }
                if ($reason !== null) {
                    $error = self::describe_read_error($cand, $reason);
                    return null;
                }
            }
        }

        // Case/encoding fallback remains within the explicitly named directory;
        // bare names retain the former three-level search.
        $search_dir = $has_directory ? rtrim($dest_dir, '/\\') . DIRECTORY_SEPARATOR . dirname($norm_rel) : $dest_dir;
        $found = is_dir($search_dir) ? self::find_local_file($search_dir, $clean_basename, $has_directory ? 0 : 3) : null;
        if ($found !== null) {
            $content = self::read_local_file($found, $reason);
            if ($content !== null) {
                return $content;
            }
            $error = self::describe_read_error($found, $reason ?: 'not_readable');
            return null;
        }

        $error = sprintf(__('Файл «%s» не найден в сетевой папке %s.', 'wp-olympiad'), $clean_basename, $dest_dir);
        $available = array_values(array_filter(
            self::scan_network_folder_for_import_files($quiz, $config),
            static function (array $f): bool {
                if (!in_array((string)($f['ext'] ?? ''), ['csv', 'xlsx', 'xlsm', 'json', 'tsv'], true)) {
                    return false;
                }
                return !preg_match('/(?:readme|manifest|инструкц)/iu', (string)($f['name'] ?? ''));
            }
        ));
        if (!empty($available)) {
            $names = array_map(static fn(array $f): string => (string)($f['path'] ?? $f['name']), array_slice($available, 0, 8));
            $error .= ' ' . sprintf(__('Доступные файлы заданий: %s.', 'wp-olympiad'), implode(', ', $names));
        } else {
            $error .= ' ' . sprintf(
                __('Файлы заданий (.xlsx, .xlsm, .csv, .json) в папке %s не найдены: она пуста или содержит только посторонние файлы.', 'wp-olympiad'),
                $dest_dir
            );
        }

        return null;
    }

    /**
     * Read a file over FTP.
     */
    private static function read_ftp_file(array $config, string $relative_path, ?string &$error = null): ?string
    {
        $error = null;

        if (!function_exists('ftp_connect')) {
            $error = __('На сервере не включено расширение PHP FTP — чтение файлов по FTP невозможно.', 'wp-olympiad');
            return null;
        }

        $path = (string)($config['path'] ?? '');
        $parsed = parse_url($path);
        $host = (string)($parsed['host'] ?? '');
        $port = (int)($parsed['port'] ?? 21);
        $remote_dir = rawurldecode((string)($parsed['path'] ?? '/'));

        if ($host === '') {
            $error = sprintf(__('Некорректный адрес FTP: %s', 'wp-olympiad'), $path);
            return null;
        }

        $secure = strtolower((string)($parsed['scheme'] ?? 'ftp')) === 'ftps';
        if ($secure && !function_exists('ftp_ssl_connect')) {
            $error = __('На сервере недоступно расширение PHP FTPS.', 'wp-olympiad');
            return null;
        }
        $conn = $secure ? @ftp_ssl_connect($host, $port, 8) : @ftp_connect($host, $port, 8);
        if (!$conn) {
            $error = sprintf(
                __('Не удалось подключиться к FTP-серверу %1$s:%2$d. Проверьте адрес, порт, доступность сервера и настройки файрвола.', 'wp-olympiad'),
                $host,
                $port
            );
            return null;
        }

        $user = (string)($config['user'] ?? '') ?: 'anonymous';
        $pass = (string)($config['password'] ?? '');

        if (!@ftp_login($conn, $user, $pass)) {
            @ftp_close($conn);
            $error = sprintf(__('Не удалось войти на FTP-сервер %s: проверьте логин и пароль.', 'wp-olympiad'), $host);
            return null;
        }

        @ftp_pasv($conn, true);

        $handle = fopen('php://temp', 'r+');
        $full_remote = rtrim($remote_dir, '/') . '/' . ltrim(str_replace('\\', '/', $relative_path), '/');
        $ok = @ftp_fget($conn, $handle, $full_remote, FTP_BINARY);
        @ftp_close($conn);

        if (!$ok) {
            fclose($handle);
            $error = sprintf(__('Файл «%1$s» не найден на FTP-сервере (проверен путь %2$s).', 'wp-olympiad'), $relative_path, $full_remote);
            return null;
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        if (!is_string($content) || $content === '') {
            $error = sprintf(__('Файл «%s» на FTP-сервере пуст.', 'wp-olympiad'), $relative_path);
            return null;
        }

        return $content;
    }

    /**
     * Read a file over WebDAV / HTTP(S).
     */
    private static function read_webdav_file(array $config, string $relative_path, ?string &$error = null): ?string
    {
        $error = null;
        $base = rtrim((string)($config['path'] ?? ''), '/');
        $relative = ltrim(str_replace('\\', '/', $relative_path), '/');
        $parts = explode('/', $relative);
        $url = $base . '/' . implode('/', array_map('rawurlencode', $parts));

        $args = [
            'timeout'     => 25,
            'redirection' => 3,
        ];

        if (!empty($config['user']) || !empty($config['password'])) {
            $args['headers'] = [
                'Authorization' => 'Basic ' . base64_encode((string)$config['user'] . ':' . (string)$config['password']),
            ];
        }

        $response = wp_remote_get($url, $args);
        // Older stored filenames may already be URL-encoded. Try that notation
        // only after the literal filename is confirmed absent (literal % wins).
        if (!is_wp_error($response) && (int)wp_remote_retrieve_response_code($response) === 404 && preg_match('/%[0-9a-f]{2}/i', $relative)) {
            $decoded = array_map('rawurldecode', $parts);
            $safe_legacy = true;
            foreach ($decoded as $part) {
                if ($part === '..' || strpbrk($part, "/\\\0") !== false) { $safe_legacy = false; break; }
            }
            $legacy = $base . '/' . implode('/', array_map('rawurlencode', $decoded));
            if ($safe_legacy && $legacy !== $url) {
                $url = $legacy;
                $response = wp_remote_get($url, $args);
            }
        }
        if (is_wp_error($response)) {
            $error = sprintf(
                __('Не удалось получить файл по адресу %1$s: %2$s', 'wp-olympiad'),
                $url,
                $response->get_error_message()
            );
            return null;
        }

        $code = (int)wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $error = sprintf(
                __('Сервер вернул код %1$d для %2$s. Проверьте путь, логин и пароль к сетевой папке.', 'wp-olympiad'),
                $code,
                $url
            );
            return null;
        }

        $body = (string)wp_remote_retrieve_body($response);
        if ($body === '') {
            $error = sprintf(__('Файл по адресу %s пуст (0 байт).', 'wp-olympiad'), $url);
            return null;
        }

        return $body;
    }

    /**
     * Read a file from a Windows share (SMB) using the smbclient utility.
     */
    private static function read_smb_file(array $config, string $relative_path, ?string &$error = null): ?string
    {
        $error = null;
        $path = (string)($config['path'] ?? '');
        $parsed = NetworkExport::parse_smb_path($path);

        if (!$parsed) {
            $error = sprintf(__('Некорректный путь к сетевой папке Windows (SMB): %s', 'wp-olympiad'), $path);
            return null;
        }

        $smb_bin = NetworkExport::get_smbclient_binary();
        if (!$smb_bin) {
            $error = sprintf(
                __('Сетевая папка «%1$s» — это SMB-ресурс Windows. На этом сервере (Linux) нужна утилита smbclient (sudo apt install smbclient) либо смонтированная папка: sudo mount -t cifs %2$s /mnt/share — после чего укажите в настройках локальный путь /mnt/share.', 'wp-olympiad'),
                $parsed['unc'],
                $parsed['service']
            );
            return null;
        }

        // Удалённый путь внутри ресурса: подкаталог из адреса + относительный путь файла
        $remote = trim(str_replace('\\', '/', (string)$parsed['subdir']), '/');
        $rel = trim(str_replace('\\', '/', $relative_path), '/');
        if ($remote !== '' && str_starts_with($rel, $remote . '/')) {
            $rel = substr($rel, strlen($remote) + 1);
        }
        $remote_file = $remote !== '' ? $remote . '/' . $rel : $rel;

        // Кавычки внутри имени недопустимы — защита от инъекции в командную строку smbclient
        $remote_file = str_replace(['"', "\r", "\n", "\0"], '', $remote_file);

        $tmp_file = tempnam(sys_get_temp_dir(), 'wpo_smb_get_');
        if (!$tmp_file) {
            $error = __('Не удалось создать временный файл для чтения из SMB-папки.', 'wp-olympiad');
            return null;
        }

        $script = 'get "' . $remote_file . '" "' . $tmp_file . '"';
        [$exit_code, $output] = self::smb_run($smb_bin, (string)$parsed['service'], $config, $parsed, $script);

        $content = @file_get_contents($tmp_file);
        @unlink($tmp_file);

        if ($exit_code !== 0 || $content === false || $content === '') {
            $details = trim(implode(' ', array_slice($output, 0, 3)));
            $error = sprintf(
                __('Не удалось прочитать файл «%1$s» из SMB-папки %2$s.%3$s', 'wp-olympiad'),
                $remote_file,
                $parsed['unc'],
                $details !== '' ? ' ' . sprintf(__('Ответ smbclient: %s', 'wp-olympiad'), $details) : ''
            );
            return null;
        }

        return $content;
    }

    /**
     * Run a single smbclient command with temporary credentials.
     *
     * @return array{0: int, 1: array<int, string>}
     */
    private static function smb_run(string $bin, string $service, array $config, array $parsed, string $script): array
    {
        $user = (string)($config['user'] ?? '') ?: (string)($parsed['user'] ?? '');
        $pass = (string)($config['password'] ?? '') ?: (string)($parsed['password'] ?? '');

        $creds_file = '';
        if ($user !== '' || $pass !== '') {
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
            $auth_args = '-A ' . escapeshellarg($creds_file);
        } else {
            $auth_args = '-N';
        }

        $cmd = sprintf(
            '%s %s %s -c %s -t 30 2>&1',
            escapeshellcmd($bin),
            escapeshellarg($service),
            $auth_args,
            escapeshellarg($script)
        );

        $output = [];
        $exit_code = 1;
        exec($cmd, $output, $exit_code);

        if ($creds_file !== '') {
            @unlink($creds_file);
        }

        return [$exit_code, $output];
    }

    /** List one SMB directory; paths returned are relative to the configured root. */
    private static function scan_smb_folder(array $config, string $prefix, ?string &$error): array
    {
        $parsed = NetworkExport::parse_smb_path((string)($config['path'] ?? ''));
        $bin = NetworkExport::get_smbclient_binary();
        if (!$parsed || !$bin) {
            $error = __('Не удалось проверить SMB-папку: проверьте адрес и наличие smbclient.', 'wp-olympiad');
            return [];
        }
        $directory = trim(trim((string)$parsed['subdir'], '/') . '/' . trim($prefix, '/'), '/');
        if (strpbrk($directory, "\"\r\n\0") !== false) {
            $error = __('Недопустимые символы в пути SMB-папки.', 'wp-olympiad');
            return [];
        }
        $target = ($directory !== '' ? $directory . '/' : '') . '*';
        [$exit, $output] = self::smb_run($bin, (string)$parsed['service'], $config, $parsed, 'ls "' . $target . '"');
        $response = implode("\n", $output);
        if ($exit !== 0 || preg_match('/NT_STATUS_[A-Z_]+\s+listing/i', $response)) {
            if ($prefix !== '' && preg_match('/NT_STATUS_(?:OBJECT_NAME_NOT_FOUND|OBJECT_PATH_NOT_FOUND|NO_SUCH_FILE)\b/i', $response)) {
                // Some SMB servers return NO_SUCH_FILE for an empty directory.
                // Ask for the directory itself before treating it as absent.
                [$probe_exit, $probe] = self::smb_run($bin, (string)$parsed['service'], $config, $parsed, 'ls "' . $directory . '"');
                $probe_text = implode("\n", $probe);
                if (preg_match('/NT_STATUS_(?:OBJECT_NAME_NOT_FOUND|OBJECT_PATH_NOT_FOUND|NO_SUCH_FILE)\b/i', $probe_text)) return [];
                if ($probe_exit === 0 && preg_match('/^\s{2}.+?\s+[ADNRHS]*D[ADNRHS]*\s+\d+\s+.+$/mu', $probe_text)) return [];
            }
            $error = __('Не удалось прочитать папку import или подключённое SMB-хранилище. Проверьте путь и права.', 'wp-olympiad');
            return [];
        }

        $files = [];
        foreach ($output as $line) {
            if (!preg_match('/^\s{2}(.+?)\s+([ADNRHS]+)\s+(\d+)\s+(.+)$/u', $line, $m)) continue;
            if (str_contains($m[2], 'D')) continue;
            $raw_name = trim($m[1]);
            $name = self::normalize_filename($raw_name);
            if (!self::is_import_filename($name) || strpbrk($raw_name, "/\\\"\r\n\0") !== false) continue;
            $mtime = strtotime(trim($m[4]));
            $mtime = $mtime === false ? 0 : $mtime;
            $files[] = [
                'name' => $name, 'path' => $prefix . $raw_name,
                'size' => (int)$m[3], 'modified' => trim($m[4]),
                'ext' => strtolower(pathinfo($name, PATHINFO_EXTENSION)), 'mtime' => $mtime, 'depth' => 0,
            ];
        }
        return $files;
    }

    /** List one WebDAV collection with Depth: 1, not just the configured root. */
    private static function scan_webdav_folder(array $config, string $prefix, ?string &$error): array
    {
        $base = rtrim((string)($config['path'] ?? ''), '/');
        if ($base === '') {
            $error = __('Не указан адрес WebDAV-хранилища.', 'wp-olympiad');
            return [];
        }
        $target = $base . '/' . $prefix;
        $args = [
            'method' => 'PROPFIND', 'timeout' => 20,
            'headers' => ['Depth' => '1'],
        ];
        if (!empty($config['user']) || !empty($config['password'])) {
            $args['headers']['Authorization'] = 'Basic ' . base64_encode((string)$config['user'] . ':' . (string)$config['password']);
        }
        $response = wp_remote_request($target, $args);
        if (is_wp_error($response)) {
            $error = __('Не удалось связаться с WebDAV при поиске файлов import.', 'wp-olympiad');
            return [];
        }
        $code = (int)wp_remote_retrieve_response_code($response);
        if (($code === 404 || $code === 410) && $prefix !== '') return [];
        if ($code < 200 || $code >= 300) {
            $error = sprintf(__('WebDAV не предоставил список папки import (HTTP %d). Проверьте путь, права и поддержку PROPFIND.', 'wp-olympiad'), $code);
            return [];
        }
        $body = (string)wp_remote_retrieve_body($response);
        // Validate where the XML extension exists, and do not resolve external entities.
        if (class_exists('DOMDocument')) {
            $xml = new \DOMDocument();
            if (!@$xml->loadXML($body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                $error = __('WebDAV вернул некорректный список файлов папки import.', 'wp-olympiad');
                return [];
            }
        }
        if (!preg_match('~<(?:[\w.-]+:)?multistatus\b[^>]*>~i', $body)
            || !preg_match('~</(?:[\w.-]+:)?multistatus\s*>~i', $body)) {
            $error = __('WebDAV не вернул список файлов в формате Multi-Status (PROPFIND).', 'wp-olympiad');
            return [];
        }
        preg_match_all('~<(?:[\w.-]+:)?response\b[^>]*>(.*?)</(?:[\w.-]+:)?response\s*>~is', $body, $blocks);
        $base_url = parse_url($target);
        $base_path = is_array($base_url) ? (string)($base_url['path'] ?? '/') : '/';
        $segments = static function (string $path): array {
            $parts = array_values(array_filter(explode('/', trim($path, '/')), static fn(string $s): bool => $s !== ''));
            return array_map('rawurldecode', $parts);
        };
        $base_parts = $segments($base_path);
        $files = [];
        foreach ($blocks[1] as $block) {
            if (!preg_match('~<(?:[\w.-]+:)?href\b[^>]*>(.*?)</(?:[\w.-]+:)?href\s*>~is', $block, $match)) continue;
            if (preg_match('~<(?:[\w.-]+:)?collection\b[^>]*/?>~i', $block)) continue;
            $href = html_entity_decode(trim($match[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
            $parsed = parse_url($href);
            if (!is_array($parsed)) continue;
            if (!empty($parsed['host']) && strcasecmp((string)$parsed['host'], (string)($base_url['host'] ?? '')) !== 0) continue;
            $href_path = (string)($parsed['path'] ?? '');
            if ($href_path === '' || str_ends_with($href_path, '/')) continue;
            $href_parts = $segments($href_path);
            if (!str_starts_with($href_path, '/')) $href_parts = array_merge($base_parts, $href_parts);
            if (count($href_parts) !== count($base_parts) + 1 || array_slice($href_parts, 0, count($base_parts)) !== $base_parts) continue;
            $raw_name = (string)end($href_parts);
            if ($raw_name === '.' || $raw_name === '..' || strpbrk($raw_name, "/\\\0\r\n") !== false) continue;
            $name = self::normalize_filename($raw_name);
            if (!self::is_import_filename($name)) continue;
            $size = preg_match('~<(?:[\w.-]+:)?getcontentlength\b[^>]*>\s*(\d+)\s*</~i', $block, $sm) ? (int)$sm[1] : 0;
            $date_text = preg_match('~<(?:[\w.-]+:)?getlastmodified\b[^>]*>(.*?)</~is', $block, $dm) ? trim($dm[1]) : '';
            $mtime = $date_text !== '' ? strtotime($date_text) : false;
            $mtime = $mtime === false ? 0 : $mtime;
            $files[] = [
                'name' => $name, 'path' => $prefix . $raw_name,
                'size' => $size, 'modified' => $mtime ? date('Y-m-d H:i:s', $mtime) : '',
                'ext' => strtolower(pathinfo($name, PATHINFO_EXTENSION)), 'mtime' => $mtime, 'depth' => 0,
            ];
        }
        return $files;
    }

    /**
     * Parse raw string content (CSV, JSON, XLSX, XLSM) into standardized question records
     *
     * @param string $content
     * @param string $filename
     * @return array<int, array>
     */
    /**
     * Parse questions from raw file content (CSV / JSON / XLSX / XLSM).
     *
     * @return array<int, array>
     */
    public static function parse_questions_content(string $content, string $filename = ''): array
    {
        $analysis = self::analyze_questions_content($content, $filename);

        return $analysis['questions'];
    }

    /**
     * Analyse file content and produce an import report (row by row) without touching the database.
     *
     * @return array{questions: array<int, array>, report: array<int, array>, stats: array<string, mixed>}
     */
    public static function analyze_questions_content(string $content, string $filename = ''): array
    {
        if ($content === '') {
            return self::empty_analysis();
        }

        // Excel binary must be checked BEFORE trim() to avoid corrupting the ZIP archive.
        if (str_starts_with($content, "PK\x03\x04") || preg_match('/\.(xlsx|xlsm)$/i', $filename)) {
            return self::analyze_excel_content($content);
        }

        $trimmed = trim($content);
        if ($trimmed === '') {
            return self::empty_analysis();
        }

        if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                $items = (isset($decoded['questions']) && is_array($decoded['questions'])) ? $decoded['questions'] : $decoded;
                return self::analyze_json_items($items);
            }
        }

        return self::analyze_csv_content($content);
    }

    /**
     * Analyse questions stored in a network/uploaded file (used by the admin "check file" button).
     *
     * @return array{success: bool, message: string, stats: array<string, mixed>, report: array<int, array>}
     */
    public static function analyze_import_file(Quiz $quiz, string $relative_path = '', array $override = []): array
    {
        $path = trim($relative_path);
        $candidates = [];
        $first_error = null;
        $scan_error = null;

        if ($path !== '') {
            $path = self::resolve_named_import($quiz, $path, $override, $scan_error) ?? '';
        } else {
            $files = self::scan_network_folder_for_import_files($quiz, $override, $scan_error);
            $candidates = array_values(array_map(static fn(array $f): string => (string)$f['path'], $files));
            $path = $candidates[0] ?? '';
        }

        if ($path === '') {
            return [
                'success' => false,
                'message' => ($scan_error ?: __('В папке import и при резервном поиске не найдено файлов заданий (.xlsx, .xlsm, .csv, .json, .tsv).', 'wp-olympiad'))
                    . ' ' . self::describe_network_folder($quiz, $override),
                'stats'   => self::empty_analysis()['stats'],
                'report'  => [],
            ];
        }

        // При автоопределении перебираем найденные файлы и берём первый, где реально есть задания
        foreach (array_unique(array_filter(array_merge([$path], $candidates))) as $candidate) {
            $read_error = null;
            $content = self::read_network_file($quiz, $candidate, $override, $read_error);

            if ($content === null) {
                $first_error = $first_error ?? ($read_error ?: sprintf(__('Не удалось прочитать файл «%s».', 'wp-olympiad'), $candidate));
                continue;
            }

            $analysis = self::analyze_questions_content($content, $candidate);
            $stats = $analysis['stats'];

            if ((int)$stats['importable'] === 0) {
                $first_error = $first_error ?? ((string)($analysis['notice'] ?? '') !== ''
                    ? sprintf(__('Файл «%1$s»: %2$s', 'wp-olympiad'), $candidate, (string)$analysis['notice'])
                    : sprintf(__('Файл «%1$s» прочитан, но не содержит корректных строк с заданиями.', 'wp-olympiad'), $candidate));
                continue;
            }

            return [
                'success' => true,
                'message' => sprintf(
                    __('Файл «%1$s»: найдено %2$d строк, готовы к импорту %3$d, предупреждений %4$d, ошибок %5$d.', 'wp-olympiad'),
                    $candidate,
                    (int)$stats['total'],
                    (int)$stats['importable'],
                    (int)$stats['warnings'],
                    (int)$stats['errors']
                ),
                'source'  => $candidate,
                'stats'   => $stats,
                'report'  => $analysis['report'],
            ];
        }

        // Ни один файл не подошёл — показываем первую причину и состав папки
        return [
            'success' => false,
            'message' => ($first_error ?? __('В сетевой папке не найдено файлов заданий (.xlsx, .xlsm, .csv, .json).', 'wp-olympiad'))
                . (count($candidates) > 0 ? ' ' . self::describe_network_folder($quiz, $override) : ''),
            'stats'   => self::empty_analysis()['stats'],
            'report'  => [],
        ];
    }

    /**
     * @return array{questions: array, report: array, stats: array<string, mixed>}
     */
    private static function empty_analysis(string $notice = ''): array
    {
        return [
            'questions' => [],
            'report'    => [],
            'notice'    => $notice,
            'stats'     => [
                'total'      => 0,
                'importable' => 0,
                'ok'         => 0,
                'warnings'   => 0,
                'errors'     => 0,
                'types'      => [],
            ],
        ];
    }

    /**
     * Add a row to the report and return its analysis entry.
     *
     * @param array<int, array> $report
     * @param array<int, string> $messages
     * @return array<string, mixed>
     */
    private static function push_report(
        array &$report,
        int $row,
        string $source,
        string $type,
        string $title,
        ?array $question,
        array $messages
    ): array {
        $has_error = false;
        $has_warning = false;

        foreach ($messages as $message) {
            if (str_starts_with($message, '✗')) {
                $has_error = true;
            } else {
                $has_warning = true;
            }
        }

        $short_title = mb_strlen($title) > 70 ? mb_substr($title, 0, 67) . '…' : $title;

        $entry = [
            'row'      => $row,
            'source'   => $source,
            'type'     => $type,
            'title'    => $short_title,
            'status'   => $question === null ? 'error' : ($has_error ? 'error' : ($has_warning ? 'warning' : 'ok')),
            'messages' => $messages,
        ];

        $report[] = $entry;

        return $entry;
    }

    /**
     * Finalize the analysis structure (stats).
     *
     * @param array<int, array> $questions
     * @param array<int, array> $report
     * @return array{questions: array, report: array, stats: array<string, mixed>}
     */
    private static function finalize_analysis(array $questions, array $report): array
    {
        $stats = [
            'total'      => count($report),
            'importable' => count($questions),
            'ok'         => 0,
            'warnings'   => 0,
            'errors'     => 0,
            'types'      => [],
        ];

        foreach ($report as $entry) {
            if ($entry['status'] === 'ok') {
                $stats['ok']++;
            } elseif ($entry['status'] === 'warning') {
                $stats['warnings']++;
            } else {
                $stats['errors']++;
            }
        }

        foreach ($questions as $question) {
            $type = (string)($question['question_type'] ?? 'single');
            $stats['types'][$type] = ($stats['types'][$type] ?? 0) + 1;
        }

        return [
            'questions' => $questions,
            'report'    => $report,
            'stats'     => $stats,
        ];
    }

    /**
     * Build question + diagnostics from a normalized record.
     *
     * @param array<string, mixed> $record
     * @param array<int, string> $messages Diagnostics collected for the row (✗ = error).
     * @return array|null
     */
    private static function process_record(array $record, int $row_number, string $source, array &$messages): ?array
    {
        $raw_title = trim((string)($record['title'] ?? ''));

        if ($raw_title === '') {
            // Row without a title: skip silently only if the row is entirely empty.
            $has_data = false;
            foreach ($record as $value) {
                if (trim((string)$value) !== '') {
                    $has_data = true;
                    break;
                }
            }
            if ($has_data) {
                $messages[] = '✗ ' . __('Не заполнена колонка «title» (текст вопроса) — строка пропущена.', 'wp-olympiad');
            }
            return null;
        }

        $question = self::build_question_from_record($record, $messages);
        if ($question === null) {
            if (empty($messages)) {
                $messages[] = '✗ ' . __('Не удалось разобрать строку.', 'wp-olympiad');
            }
            return null;
        }

        // Hard errors (✗) reject the row; warnings (⚠) import it with a note.
        foreach ($messages as $message) {
            if (str_starts_with($message, '✗')) {
                return null;
            }
        }

        return $question;
    }

    /**
     * Parse binary Excel (.xlsx / .xlsm) spreadsheet into standardized question records
     *
     * @return array<int, array>
     */
    public static function parse_excel_content(string $binary): array
    {
        $analysis = self::analyze_excel_content($binary);

        return $analysis['questions'];
    }

    /**
     * Analyse Excel (.xlsx / .xlsm) content: every sheet is scanned, the first header row wins.
     *
     * @return array{questions: array, report: array, stats: array<string, mixed>}
     */
    public static function analyze_excel_content(string $binary): array
    {
        if (!class_exists('\\ZipArchive') || !class_exists('\\SimpleXMLElement')) {
            return self::empty_analysis(__('на сервере не включены расширения PHP Zip и SimpleXML, поэтому чтение файлов Excel невозможно. Обратитесь к администратору сервера.', 'wp-olympiad'));
        }

        // Старый двоичный формат .xls и книги Excel с паролем — это OLE-контейнеры, а не ZIP
        if (str_starts_with($binary, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
            return self::empty_analysis(__('книга сохранена в старом формате .xls или защищена паролем. Откройте файл в Excel и сохраните его как «Книга Excel (.xlsx)» без пароля, затем повторите импорт.', 'wp-olympiad'));
        }

        // Повреждённый или не Excel-файл с расширением .xlsx
        if (!str_starts_with($binary, "PK\x03\x04")) {
            return self::empty_analysis(__('это не книга Excel 2007+ (.xlsx/.xlsm): не найдена ZIP-структура документа. Проверьте, что файл не повреждён и его расширение соответствует формату.', 'wp-olympiad'));
        }

        $temp_file = tempnam(sys_get_temp_dir(), 'wpo_excel_');
        if (!$temp_file) {
            return self::empty_analysis(__('не удалось создать временный файл на сервере для разбора книги Excel.', 'wp-olympiad'));
        }

        file_put_contents($temp_file, $binary);

        $zip = new \ZipArchive();
        if ($zip->open($temp_file) !== true) {
            @unlink($temp_file);
            return self::empty_analysis(__('не удалось открыть книгу Excel: архив повреждён или файл не является .xlsx/.xlsm.', 'wp-olympiad'));
        }

        // 1. Shared strings
        $strings = [];
        $shared_xml_str = $zip->getFromName('xl/sharedStrings.xml');
        if ($shared_xml_str !== false) {
            $shared_xml = @simplexml_load_string($shared_xml_str);
            if ($shared_xml && isset($shared_xml->si)) {
                foreach ($shared_xml->si as $si) {
                    if (isset($si->t)) {
                        $strings[] = (string)$si->t;
                    } elseif (isset($si->r)) {
                        $parts = [];
                        foreach ($si->r as $run) {
                            $parts[] = (string)($run->t ?? '');
                        }
                        $strings[] = implode('', $parts);
                    } else {
                        $strings[] = '';
                    }
                }
            }
        }

        // 2. Worksheets: prefer sheets that look like the question table
        $sheet_entries = [];
        $sheet_names = [];
        $preferred_sheet = '';
        $wb_xml_str = $zip->getFromName('xl/workbook.xml');
        if ($wb_xml_str !== false) {
            $wb_xml = @simplexml_load_string($wb_xml_str);
            if ($wb_xml && isset($wb_xml->sheets->sheet)) {
                $rels_map = [];
                $rels_xml_str = $zip->getFromName('xl/_rels/workbook.xml.rels');
                if ($rels_xml_str !== false) {
                    $rels_xml = @simplexml_load_string($rels_xml_str);
                    if ($rels_xml && isset($rels_xml->Relationship)) {
                        foreach ($rels_xml->Relationship as $rel) {
                            $rels_map[(string)$rel['Id']] = 'xl/' . ltrim((string)$rel['Target'], '/');
                        }
                    }
                }

                foreach ($wb_xml->sheets->sheet as $sh) {
                    $sh_name = (string)$sh['name'];
                    $r_id = (string)$sh->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                    $target = $rels_map[$r_id] ?? '';
                    if ($target) {
                        $sheet_entries[] = $target;
                        $sheet_names[$target] = $sh_name;
                        if (mb_stripos($sh_name, 'вопрос') !== false || mb_stripos($sh_name, 'quest') !== false || mb_stripos($sh_name, 'задан') !== false) {
                            $preferred_sheet = $target;
                        }
                    }
                }
            }
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#i', $name) && !in_array($name, $sheet_entries, true)) {
                $sheet_entries[] = $name;
                $sheet_names[$name] = $name;
            }
        }

        // Instruction/reference sheets must never be parsed as question tables.
        $skip_pattern = '/(?:инструк|справ|описан|правил|пример|instruction|reference|readme|help|guide|about)/iu';
        $candidates = [];
        foreach ($sheet_entries as $entry) {
            $label = $sheet_names[$entry] ?? $entry;
            if (preg_match($skip_pattern, $label)) {
                continue;
            }
            $candidates[] = $entry;
        }

        if ($preferred_sheet !== '' && in_array($preferred_sheet, $candidates, true)) {
            $candidates = array_values(array_unique(array_merge([$preferred_sheet], $candidates)));
        }

        // If the workbook has a dedicated question sheet, parse only it.
        if ($preferred_sheet !== '' && in_array($preferred_sheet, $candidates, true)) {
            $sheet_entries = [$preferred_sheet];
        } elseif (!empty($candidates)) {
            $sheet_entries = $candidates;
        }

        if (empty($sheet_entries)) {
            $sheet_entries[] = 'xl/worksheets/sheet1.xml';
            $sheet_names['xl/worksheets/sheet1.xml'] = 'Sheet1';
        }

        $questions = [];
        $report = [];

        foreach ($sheet_entries as $sheet_path) {
            $sheet_xml_str = $zip->getFromName($sheet_path);
            if ($sheet_xml_str === false) {
                continue;
            }

            $sheet_xml = @simplexml_load_string($sheet_xml_str);
            if (!$sheet_xml || !isset($sheet_xml->sheetData)) {
                continue;
            }

            $sheet_label = $sheet_names[$sheet_path] ?? $sheet_path;
            $rows = [];

            foreach ($sheet_xml->sheetData->row as $row_xml) {
                $row = [];
                foreach ($row_xml->c as $c) {
                    $ref = (string)$c['r'];
                    if (!preg_match('/^([A-Z]+)(\d+)$/', $ref, $mm)) {
                        continue;
                    }
                    $letters = $mm[1];
                    $col_idx = 0;
                    $len = strlen($letters);
                    for ($j = 0; $j < $len; $j++) {
                        $col_idx = $col_idx * 26 + (ord($letters[$j]) - 64);
                    }
                    $col_idx--;

                    $t = (string)($c['t'] ?? '');
                    $val = isset($c->v) ? (string)$c->v : '';

                    if ($t === 's') {
                        $val = $strings[(int)$val] ?? '';
                    } elseif ($t === 'inlineStr' && isset($c->is->t)) {
                        $val = (string)$c->is->t;
                    }

                    $row[$col_idx] = trim($val);
                }

                if (!empty($row)) {
                    ksort($row);
                    $rows[] = ['number' => (int)$row_xml['r'], 'cells' => $row];
                }
            }

            if (empty($rows)) {
                continue;
            }

            $headers = null;
            $header_row = 0;
            $sheet_questions = [];
            $sheet_report = [];

            foreach ($rows as $row_info) {
                $row = $row_info['cells'];
                $row_number = $row_info['number'];

                if ($headers === null) {
                    $headers = self::map_header_row($row);
                    if ($headers === null) {
                        continue; // not a header row — keep searching
                    }
                    $header_row = $row_number;
                    continue;
                }

                $record = self::zip_row_to_record($headers, $row);
                $messages = [];
                $question = self::process_record($record, $row_number, $sheet_label, $messages);

                if ($question === null && empty($messages)) {
                    continue;
                }

                if ($question !== null) {
                    $sheet_questions[] = $question;
                }

                self::push_report(
                    $sheet_report,
                    $row_number,
                    $sheet_label,
                    (string)($record['type'] ?? ''),
                    (string)($record['title'] ?? ''),
                    $question,
                    $messages
                );
            }

            // Keep a sheet's diagnostics only when it really looks like a questions table:
            // either it produced questions, or its header sits in the first rows and the row
            // has at least four different recognized columns.
            $recognized_columns = $headers === null ? 0 : count(array_unique(array_filter($headers, fn($h) => $h !== '')));
            $is_questions_sheet = !empty($sheet_questions)
                || ($header_row > 0 && $header_row <= 3 && $recognized_columns >= 4);

            if ($is_questions_sheet) {
                $questions = array_merge($questions, $sheet_questions);
                $report = array_merge($report, $sheet_report);
            }
        }

        $zip->close();
        @unlink($temp_file);

        return self::finalize_analysis($questions, $report);
    }

    /**
     * Convert a raw header row into canonical column keys (or null when it is not a header row).
     *
     * @param array<int, string> $row
     * @return array<int, string>|null
     */
    private static function map_header_row(array $row): ?array
    {
        $headers = [];
        $distinct = [];

        foreach ($row as $idx => $cell) {
            $key = ImportTemplate::normalize_header((string)$cell);
            $headers[$idx] = $key;
            if ($key !== '') {
                $distinct[$key] = true;
            }
        }

        // A real question table has at least two DIFFERENT recognized columns and always
        // includes the question text. This keeps instruction/reference sheets out.
        if (count($distinct) < 2 || !isset($distinct['title'])) {
            return null;
        }

        return $headers;
    }

    /**
     * @param array<int, string> $headers
     * @param array<int, string> $row
     * @return array<string, string>
     */
    private static function zip_row_to_record(array $headers, array $row): array
    {
        $record = [];
        foreach ($headers as $idx => $key) {
            if ($key === '') {
                continue;
            }
            $record[$key] = isset($row[$idx]) ? trim((string)$row[$idx]) : '';
        }

        return $record;
    }

    /**
     * Analyse CSV text.
     *
     * @return array{questions: array, report: array, stats: array<string, mixed>}
     */
    private static function analyze_csv_content(string $csv): array
    {
        // Strip UTF-8 BOM
        if (str_starts_with($csv, "\xEF\xBB\xBF")) {
            $csv = substr($csv, 3);
        }

        // Convert Windows-1251 (Russian Excel "ANSI" export) to UTF-8
        if (!mb_check_encoding($csv, 'UTF-8')) {
            if (function_exists('mb_convert_encoding')) {
                $converted = @mb_convert_encoding($csv, 'UTF-8', 'Windows-1251');
                if (is_string($converted) && mb_check_encoding($converted, 'UTF-8')) {
                    $csv = $converted;
                }
            }
        }

        $csv = str_replace(["\r\n", "\r"], "\n", $csv);

        // Detect delimiter: ';', ',' or tab (ignores delimiters inside quotes)
        $first_line = strtok($csv, "\n") ?: '';
        $delimiter = self::detect_delimiter($first_line);

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return self::empty_analysis();
        }
        fwrite($stream, $csv);
        rewind($stream);

        $headers = null;
        $questions = [];
        $report = [];
        $row_number = 0;

        while (($row = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            $row_number++;

            if ($row === [null] || (count($row) === 1 && trim((string)$row[0]) === '')) {
                continue;
            }

            if ($headers === null) {
                $headers = self::map_header_row($row);
                if ($headers === null) {
                    continue;
                }
                continue;
            }

            $record = self::zip_row_to_record($headers, $row);
            $messages = [];
            $question = self::process_record($record, $row_number, 'CSV', $messages);

            if ($question === null && empty($messages)) {
                continue;
            }

            if ($question !== null) {
                $questions[] = $question;
            }

            self::push_report(
                $report,
                $row_number,
                'CSV',
                (string)($record['type'] ?? ''),
                (string)($record['title'] ?? ''),
                $question,
                $messages
            );
        }

        fclose($stream);

        return self::finalize_analysis($questions, $report);
    }

    /**
     * Detect the CSV delimiter outside of quoted sections.
     */
    private static function detect_delimiter(string $line): string
    {
        $counts = [';' => 0, ',' => 0, "\t" => 0];
        $in_quotes = false;
        $len = strlen($line);

        for ($i = 0; $i < $len; $i++) {
            $char = $line[$i];
            if ($char === '"') {
                $in_quotes = !$in_quotes;
                continue;
            }
            if ($in_quotes) {
                continue;
            }
            if (isset($counts[$char])) {
                $counts[$char]++;
            }
        }

        if ($counts["\t"] > $counts[';'] && $counts["\t"] > $counts[',']) {
            return "\t";
        }
        if ($counts[';'] >= $counts[',']) {
            return ';';
        }

        return $counts[','] >= 2 ? ',' : ';';
    }

    /**
     * Analyse a JSON array of question objects.
     *
     * @param array<int, mixed> $items
     * @return array{questions: array, report: array, stats: array<string, mixed>}
     */
    private static function analyze_json_items(array $items): array
    {
        $questions = [];
        $report = [];
        $row = 0;

        foreach ($items as $item) {
            $row++;

            if (!is_array($item)) {
                $report[] = [
                    'row'      => $row,
                    'source'   => 'JSON',
                    'type'     => '',
                    'title'    => '',
                    'status'   => 'error',
                    'messages' => ['✗ ' . __('Элемент JSON не является объектом.', 'wp-olympiad')],
                ];
                continue;
            }

            $messages = [];
            $question = self::normalize_json_question($item, $messages);

            if ($question === null && empty($messages)) {
                continue;
            }

            if ($question !== null) {
                $questions[] = $question;
            }

            self::push_report(
                $report,
                $row,
                'JSON',
                (string)($item['question_type'] ?? ''),
                (string)($item['title'] ?? ''),
                $question,
                $messages
            );
        }

        return self::finalize_analysis($questions, $report);
    }

    /**
     * Normalize a single JSON question object into the internal payload.
     *
     * @param array<string, mixed> $item
     * @param array<int, string> $messages
     * @return array|null
     */
    private static function normalize_json_question(array $item, array &$messages): ?array
    {
        $title = trim((string)($item['title'] ?? ($item['question'] ?? '')));
        if ($title === '') {
            $messages[] = '✗ ' . __('Не заполнено поле «title».', 'wp-olympiad');
            return null;
        }

        $type_raw = (string)($item['question_type'] ?? ($item['type'] ?? 'single'));
        $type = ImportTemplate::normalize_type($type_raw);

        if ($type_raw !== '' && !ImportTemplate::is_known_type($type_raw)) {
            $messages[] = sprintf('⚠ ' . __('Неизвестный тип «%s» — импортирован как «%s».', 'wp-olympiad'), $type_raw, $type);
        }

        $options = is_array($item['options'] ?? null) ? $item['options'] : [];

        // Flatten the most common top-level shortcuts (group_name / is_required / explanation).
        foreach (['group_name', 'is_required', 'explanation', 'shared_text_id'] as $field) {
            if (isset($item[$field]) && !isset($options[$field])) {
                $options[$field] = $item[$field];
            }
        }

        $messages = array_merge($messages, self::validate_question_options($type, $options, $title));

        return [
            'question_type' => $type,
            'title'         => wp_kses_post($title),
            'content'       => wp_kses_post((string)($item['content'] ?? '')),
            'points'        => max(0, (int)round(ImportTemplate::to_float($item['points'] ?? 1, 1.0))),
            'penalty'       => max(0, (int)round(ImportTemplate::to_float($item['penalty'] ?? 0, 0.0))),
            'options'       => $options,
        ];
    }

    /**
     * Validate type-specific payload; returns human readable messages (⚠ warning, ✗ error).
     *
     * @param array<string, mixed> $options
     * @return array<int, string>
     */
    private static function validate_question_options(string $type, array $options, string $title = ''): array
    {
        $messages = [];

        switch ($type) {
            case 'single':
            case 'multi':
            case 'dropdown':
                $choices = (array)($options['choices'] ?? []);
                $correct = 0;
                foreach ($choices as $choice) {
                    if (!empty($choice['is_correct'])) {
                        $correct++;
                    }
                }
                if (count($choices) < 2) {
                    $messages[] = '✗ ' . __('Нужно минимум два варианта ответа в колонке «choices».', 'wp-olympiad');
                }
                if ($correct === 0) {
                    $messages[] = '⚠ ' . __('Не отмечен правильный вариант (звёздочка * в choices или номер в correct) — правильным станет первый.', 'wp-olympiad');
                }
                if ($type === 'single' && $correct > 1) {
                    $messages[] = '⚠ ' . __('Для типа single отмечено несколько верных вариантов — участник сможет выбрать только один.', 'wp-olympiad');
                }
                break;

            case 'number':
            case 'slider':
                if (!isset($options['target_value']) || $options['target_value'] === '' || $options['target_value'] === null) {
                    $messages[] = '✗ ' . __('Не указано правильное число (target_value).', 'wp-olympiad');
                }
                if (isset($options['min'], $options['max']) && (float)$options['min'] >= (float)$options['max']) {
                    $messages[] = '⚠ ' . __('Для шкалы min должен быть меньше max.', 'wp-olympiad');
                }
                break;

            case 'text':
                if (empty($options['answers'])) {
                    $messages[] = '✗ ' . __('Не заданы допустимые ответы (колонка «answers»).', 'wp-olympiad');
                }
                break;

            case 'matching':
                if (count((array)($options['pairs'] ?? [])) < 2) {
                    $messages[] = '✗ ' . __('Нужно минимум две пары в колонке «choices» (пример: Франция => Париж | Италия => Рим).', 'wp-olympiad');
                }
                break;

            case 'ordering':
                if (count((array)($options['items'] ?? [])) < 2) {
                    $messages[] = '✗ ' . __('Нужно минимум два элемента последовательности в колонке «choices».', 'wp-olympiad');
                }
                break;

            case 'fill_blank':
            case 'dictation':
                $template = (string)($options['template'] ?? '');
                if (!preg_match('/\{[^}]+\}/u', $template)) {
                    $messages[] = '✗ ' . __('В тексте нет пропусков вида {ответ|синоним}.', 'wp-olympiad');
                }
                break;

            case 'word_scramble':
                if (empty($options['words'])) {
                    $messages[] = '✗ ' . __('Не задано слово для составления (колонка «answers»).', 'wp-olympiad');
                }
                break;

            case 'boolean':
                $messages = $messages; // no extra validation needed
                break;
        }

        return $messages;
    }

    /**
     * Build standardized question data from a normalized CSV/XLSX record.
     *
     * @param array<string, mixed> $r
     * @param array<int, string> $messages Diagnostics collector.
     * @return array|null
     */
    private static function build_question_from_record(array $r, array &$messages = []): ?array
    {
        $raw_title = trim((string)($r['title'] ?? ''));
        if ($raw_title === '') {
            $messages[] = '✗ ' . __('Не заполнен текст вопроса.', 'wp-olympiad');
            return null;
        }

        $type_raw = trim((string)($r['type'] ?? ''));
        $type = ImportTemplate::normalize_type($type_raw);
        $supported = array_keys(Question::supported_types());

        if (!in_array($type, $supported, true)) {
            $type = 'single';
        }

        if ($type_raw !== '' && !ImportTemplate::is_known_type($type_raw)) {
            $messages[] = sprintf('⚠ ' . __('Неизвестный тип «%s» — импортирован как «%s».', 'wp-olympiad'), $type_raw, $type);
        }

        $extra = ImportTemplate::parse_extra((string)($r['extra'] ?? ''));

        $points = (int)round(ImportTemplate::to_float($r['points'] ?? '', 1.0));
        $points = max(0, $points);
        $penalty = max(0, (int)round(ImportTemplate::to_float($r['penalty'] ?? '', 0.0)));

        $group_name = sanitize_text_field((string)($r['group_name'] ?? ''));
        $explanation = sanitize_textarea_field((string)($r['explanation'] ?? ''));
        $is_required = ImportTemplate::to_bool($r['is_required'] ?? '', false) ? 1 : 0;
        $shared_text_id = sanitize_key((string)($r['shared_text_id'] ?? ''));
        $image_url = esc_url_raw(trim((string)($r['image_url'] ?? '')));
        $template_text = trim((string)($r['template'] ?? ''));

        $case_sensitive = isset($extra['case_sensitive'])
            ? (ImportTemplate::to_bool($extra['case_sensitive']) ? 1 : 0)
            : 0;

        $options = [
            'explanation'    => $explanation,
            'group_name'     => $group_name,
            'is_required'    => $is_required,
            'shared_text_id' => $shared_text_id,
        ];

        // Видимость баллов: по умолчанию импорт скрывает их (см. import_to_quiz()),
        // но конкретную строку можно переопределить колонкой «extra»:
        // show_points=1 — показывать баллы, show_points=0 / hide_points=1 — скрыть.
        if (isset($extra['show_points'])) {
            $options['show_points'] = ImportTemplate::to_bool($extra['show_points'], true) ? 1 : 0;
        } elseif (isset($extra['hide_points'])) {
            $options['show_points'] = ImportTemplate::to_bool($extra['hide_points'], true) ? 0 : 1;
        }

        if ($image_url !== '') {
            $options['image_url'] = $image_url;
            if (!empty($r['image_caption'])) {
                $options['image_caption'] = sanitize_text_field((string)$r['image_caption']);
            }
        }

        if (isset($extra['shuffle'])) {
            $options['shuffle_answers'] = ImportTemplate::to_bool($extra['shuffle'], true) ? 1 : 0;
        }

        // Process choices / answers depending on question type
        switch ($type) {
            case 'single':
            case 'multi':
            case 'dropdown':
                $raw_choices = (string)($r['choices'] ?? '');
                $items = preg_split('/\s*\|\s*/u', $raw_choices) ?: [];
                $correct_raw = mb_strtolower(trim((string)($r['correct'] ?? '')));
                $correct_targets = $correct_raw === '' ? [] : array_map('trim', preg_split('/[,;]+/u', $correct_raw) ?: []);

                $choices = [];
                $position = 0;

                foreach ($items as $item) {
                    $item = trim($item);
                    if ($item === '') {
                        continue;
                    }
                    $position++;

                    $is_correct = 0;
                    $choice_points = null;

                    // Optional per-choice points: "*Марс^2"
                    if (preg_match('/\^(−?-?\d+(?:[.,]\d+)?)\s*$/u', $item, $pm)) {
                        $choice_points = (int)round(ImportTemplate::to_float($pm[1], 0.0));
                        $item = trim(mb_substr($item, 0, mb_strlen($item) - mb_strlen($pm[0])));
                    }

                    if (str_starts_with($item, '*')) {
                        $item = ltrim($item, '* ');
                        $is_correct = 1;
                    }

                    if (!$is_correct && !empty($correct_targets)) {
                        $one_based_index = (string)$position;
                        if (in_array($one_based_index, $correct_targets, true) || in_array(mb_strtolower($item), $correct_targets, true)) {
                            $is_correct = 1;
                        }
                    }

                    $choices[] = [
                        'id'         => 'c' . $position,
                        'text'       => $item,
                        'is_correct' => $is_correct,
                        'points'     => $choice_points,
                    ];
                }

                if ($type === 'single' && !empty($choices) && !in_array(1, array_column($choices, 'is_correct'), true)) {
                    $choices[0]['is_correct'] = 1;
                }

                $options['choices'] = $choices;
                break;

            case 'boolean':
                $c_val = mb_strtolower(trim((string)($r['correct'] ?? '1')));
                $is_true = in_array($c_val, ['1', 'true', 'да', 'верно', 'yes', 'y', 't', '+'], true);
                $options['choices'] = [
                    ['id' => 'true',  'text' => __('Верно / Да', 'wp-olympiad'), 'is_correct' => $is_true ? 1 : 0],
                    ['id' => 'false', 'text' => __('Неверно / Нет', 'wp-olympiad'), 'is_correct' => $is_true ? 0 : 1],
                ];
                break;

            case 'number':
                $options['target_value'] = ImportTemplate::to_float($r['target_value'] ?? '', 0.0);
                $options['tolerance'] = max(0.0, ImportTemplate::to_float($r['tolerance'] ?? '', 0.0));
                break;

            case 'slider':
                $min = ImportTemplate::to_float($extra['min'] ?? 0, 0.0);
                $max = ImportTemplate::to_float($extra['max'] ?? 100, 100.0);
                if ($max <= $min) {
                    $messages[] = '⚠ ' . __('Для шкалы max должен быть больше min — установлены значения 0…100.', 'wp-olympiad');
                    $min = 0.0;
                    $max = 100.0;
                }
                $step = ImportTemplate::to_float($extra['step'] ?? 1, 1.0);
                if ($step <= 0) {
                    $step = 1.0;
                }
                $options['min'] = $min;
                $options['max'] = $max;
                $options['step'] = $step;
                $options['target_value'] = ImportTemplate::to_float($r['target_value'] ?? '', round(($min + $max) / 2, 2));
                $options['tolerance'] = max(0.0, ImportTemplate::to_float($r['tolerance'] ?? '', 0.0));
                break;

            case 'text':
                $raw_ans = (string)($r['answers'] ?? '');
                if ($raw_ans === '') {
                    $raw_ans = (string)($r['correct'] ?? '');
                }
                $ans_list = array_values(array_filter(array_map('trim', preg_split('/\s*\|\s*/u', $raw_ans) ?: [])));
                $options['answers'] = $ans_list;
                $match_mode = mb_strtolower(trim((string)($r['match_mode'] ?? ($extra['match'] ?? 'fuzzy'))));
                $valid_modes = ['exact', 'contains_any', 'contains_all', 'fuzzy', 'regex'];
                $options['match_mode'] = in_array($match_mode, $valid_modes, true) ? $match_mode : 'fuzzy';
                $options['case_sensitive'] = $case_sensitive;
                $options['typo_tolerance'] = isset($extra['typo'])
                    ? max(0, min(10, (int)ImportTemplate::to_float($extra['typo'], 1.0)))
                    : 1;
                break;

            case 'matching':
                $raw_pairs = (string)($r['choices'] ?? '');
                $pairs = [];
                $lines = preg_split('/\s*\|\s*/u', $raw_pairs) ?: [];
                $pidx = 0;

                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }

                    $pair_points = null;
                    if (preg_match('/\^(-?\d+(?:[.,]\d+)?)\s*$/u', $line, $pm)) {
                        $pair_points = (int)round(ImportTemplate::to_float($pm[1], 0.0));
                        $line = trim(mb_substr($line, 0, mb_strlen($line) - mb_strlen($pm[0])));
                    }

                    [$left, $right] = self::split_pair($line);
                    if ($left === '' && $right === '') {
                        continue;
                    }

                    $pidx++;
                    $pairs[] = [
                        'id'     => 'p' . $pidx,
                        'left'   => $left,
                        'right'  => $right,
                        'points' => $pair_points,
                    ];
                }

                $options['pairs'] = $pairs;
                break;

            case 'ordering':
                $raw_items = (string)($r['choices'] ?? '');
                $items = preg_split('/\s*\|\s*/u', $raw_items) ?: [];
                $ordering = [];
                $oidx = 0;

                foreach ($items as $item) {
                    $item = trim($item);
                    if ($item === '') {
                        continue;
                    }
                    $item_points = null;
                    if (preg_match('/\^(-?\d+(?:[.,]\d+)?)\s*$/u', $item, $pm)) {
                        $item_points = (int)round(ImportTemplate::to_float($pm[1], 0.0));
                        $item = trim(mb_substr($item, 0, mb_strlen($item) - mb_strlen($pm[0])));
                    }
                    $oidx++;
                    $ordering[] = [
                        'id'     => 'ord_' . $oidx,
                        'text'   => $item,
                        'points' => $item_points,
                    ];
                }

                $options['items'] = $ordering;
                break;

            case 'fill_blank':
                $template = $template_text !== '' ? $template_text : $raw_title;
                $options['template'] = $template;
                $options['case_sensitive'] = $case_sensitive;
                $mode = mb_strtolower(trim((string)($extra['mode'] ?? 'input')));
                $options['choice_mode'] = in_array($mode, ['input', 'dropdown', 'word_bank'], true) ? $mode : 'input';
                $options['distractors'] = sanitize_text_field((string)($extra['distractors'] ?? ''));
                break;

            case 'dictation':
                $template = $template_text !== '' ? $template_text : $raw_title;
                $options['template'] = $template;
                $options['audio_url'] = esc_url_raw(trim((string)($extra['audio_url'] ?? '')));
                $scoring = mb_strtolower(trim((string)($extra['scoring'] ?? 'proportional')));
                $options['scoring_mode'] = $scoring === 'all_or_nothing' ? 'all_or_nothing' : 'proportional';
                $options['allowed_mistakes'] = max(0, (int)ImportTemplate::to_float($extra['mistakes'] ?? 0, 0.0));
                break;

            case 'word_scramble':
                $raw_words = (string)($r['answers'] ?? '');
                if ($raw_words === '') {
                    $raw_words = (string)($r['correct'] ?? '');
                }
                if ($raw_words === '') {
                    $raw_words = $raw_title;
                }
                $words = array_values(array_filter(array_map('trim', preg_split('/\s*[|,]\s*/u', $raw_words) ?: [])));
                $options['words'] = $words;
                $options['target_word'] = $words[0] ?? '';
                $options['extra_letters'] = (string)preg_replace('/[^\p{L}\p{N}]/u', '', (string)($extra['extra_letters'] ?? ''));
                $options['case_sensitive'] = $case_sensitive;
                break;

            case 'essay':
                $options['min_words'] = max(0, (int)ImportTemplate::to_float($extra['min_words'] ?? 0, 0.0));
                $options['max_words'] = max(0, (int)ImportTemplate::to_float($extra['max_words'] ?? 0, 0.0));
                $keywords_raw = (string)($extra['keywords'] ?? '');
                $options['keywords'] = array_values(array_filter(array_map('trim', preg_split('/\s*[|,;]\s*/u', $keywords_raw) ?: [])));
                $options['min_keywords'] = max(0, (int)ImportTemplate::to_float($extra['min_keywords'] ?? 0, 0.0));
                $options['auto_check'] = !empty($options['keywords']) ? 1 : 0;
                $scoring_essay = mb_strtolower(trim((string)($extra['scoring'] ?? 'proportional')));
                $options['scoring_mode'] = $scoring_essay === 'all_or_nothing' ? 'all_or_nothing' : 'proportional';
                $options['case_sensitive'] = $case_sensitive;
                break;
        }

        $messages = array_merge($messages, self::validate_question_options($type, $options, $raw_title));

        return [
            'question_type' => $type,
            'title'         => $raw_title,
            'content'       => (string)($r['content'] ?? ''),
            'points'        => $points,
            'penalty'       => $penalty,
            'options'       => $options,
        ];
    }

    /**
     * Split a matching pair "Франция => Париж", "Франция - Париж", "Санкт-Петербург: Северная столица".
     * Handles hyphens inside words (Санкт-Петербург) by preferring explicit separators.
     *
     * @return array{0: string, 1: string}
     */
    private static function split_pair(string $line): array
    {
        $separators = ['=>', '->', '→', '—', '–', ' = ', ' - ', ':', '='];

        foreach ($separators as $sep) {
            $pos = mb_strpos($line, $sep);
            if ($pos !== false && $pos > 0) {
                return [
                    trim(mb_substr($line, 0, $pos)),
                    trim(mb_substr($line, $pos + mb_strlen($sep))),
                ];
            }
        }

        // Fallback: split at the last hyphen so "Санкт-Петербург-Москва" keeps the city name intact.
        $pos = mb_strrpos($line, '-');
        if ($pos !== false && $pos > 0) {
            return [
                trim(mb_substr($line, 0, $pos)),
                trim(mb_substr($line, $pos + 1)),
            ];
        }

        return [$line, ''];
    }

    /**
     * @param array<int, mixed> $items
     * @return array<int, array>
     */
    private static function normalize_json_questions(array $items): array
    {
        $messages = [];
        $normalized = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $messages = [];
            $question = self::normalize_json_question($item, $messages);
            if ($question !== null) {
                $normalized[] = $question;
            }
        }

        return $normalized;
    }


    /**
     * Import parsed questions directly into a quiz
     *
     * @param int $quiz_id
     * @param array<int, array> $questions
     * @param string $mode 'append' | 'replace'
     * @return array{success: bool, imported_count: int, message: string, details: array}
     */
    public static function import_to_quiz(int $quiz_id, array $questions, string $mode = 'append', bool $hide_points = true): array
    {
        $quiz = Quiz::get($quiz_id);
        if (!$quiz) {
            return [
                'success'        => false,
                'imported_count' => 0,
                'message'        => __('Олимпиада не найдена.', 'wp-olympiad'),
                'details'        => [],
            ];
        }

        if (empty($questions)) {
            return [
                'success'        => false,
                'imported_count' => 0,
                'message'        => __('Файл не содержит корректных заданий для импорта.', 'wp-olympiad'),
                'details'        => [],
            ];
        }

        if (count($questions) > ImportTemplate::MAX_QUESTIONS) {
            $questions = array_slice($questions, 0, ImportTemplate::MAX_QUESTIONS);
        }

        // Mode replace: remove existing questions for this quiz first
        if ($mode === 'replace') {
            $existing = $quiz->get_questions(false);
            foreach ($existing as $old_q) {
                Question::delete($old_q->id);
            }
        }

        $imported_titles = [];
        $order_offset = ($mode === 'replace') ? 1 : (count($quiz->get_questions(false)) + 1);

        foreach ($questions as $idx => $q_data) {
            $q_data['quiz_id'] = $quiz_id;
            $q_data['sort_order'] = $order_offset + $idx;

            // Баллы за импортированные задания скрыты по умолчанию (show_points = 0).
            // Если файл заданий явно задал видимость для конкретного вопроса — уважаем это.
            $options = is_array($q_data['options'] ?? null) ? $q_data['options'] : [];
            if (!array_key_exists('show_points', $options)) {
                $options['show_points'] = $hide_points ? 0 : null;
            }
            $q_data['options'] = $options;

            $new_id = Question::create($q_data);
            if ($new_id > 0) {
                $imported_titles[] = sprintf('#%d: %s', $new_id, $q_data['title']);
            }
        }

        Quiz::clear_cache($quiz_id);

        return [
            'success'        => true,
            'imported_count' => count($imported_titles),
            'message'        => sprintf(
                __('Успешно импортировано %d заданий в олимпиаду «%s».', 'wp-olympiad'),
                count($imported_titles),
                $quiz->title
            ),
            'details'        => $imported_titles,
        ];
    }

    /**
     * Check network folder and automatically import updated questions into the quiz
     *
     * @param Quiz $quiz
     * @param bool $force Force import even if content hash hasn't changed
     * @param array $override Network configuration override (path, user, password, type)
     * @param string $mode 'append' or 'replace', or empty to use quiz setting
     * @param string $filename Specific file name or empty to auto-detect
     * @return array{success: bool, imported_count: int, total_count: int, filename?: string, mode?: string, unchanged?: bool, message: string}
     */
    public static function auto_import_for_quiz(
        Quiz $quiz,
        bool $force = false,
        array $override = [],
        string $mode = '',
        string $filename = '',
        bool $hide_points = true,
        bool $deploy_editor = true
    ): array {
        $result = NetworkSync::with_quiz_lock($quiz->id, static function (Quiz $fresh) use ($force, $override, $mode, $filename, $hide_points): array {
            return self::auto_import_unlocked($fresh, $force, $override, $mode, $filename, $hide_points);
        });
        if ($deploy_editor && empty($result['pending']) && empty($result['skipped'])) { DesktopEditor::ensure_for_quiz($quiz, $override); }
        return $result;
    }

    private static function auto_import_unlocked(Quiz $quiz, bool $force, array $override, string $mode, string $filename, bool $hide_points): array
    {
        if ($mode === '') {
            $mode = (string)$quiz->get_setting('network_auto_import_mode', 'append');
        }
        if (!in_array($mode, ['append', 'replace'], true)) {
            $mode = 'append';
        }

        if ($filename === '') {
            $filename = trim((string)$quiz->get_setting('network_auto_import_file', ''));
        }

        // An explicit path is exact. A bare filename is first sought in import/,
        // then at its former location; do not select a different filename.
        if ($filename !== '') {
            $scan_error = null;
            $resolved = self::resolve_named_import($quiz, $filename, $override, $scan_error);
            if ($resolved === null) {
                return [
                    'success' => false, 'imported_count' => 0,
                    'total_count' => count($quiz->get_questions(false)),
                    'filename' => $filename,
                    'message' => $scan_error ?: __('Не удалось проверить папку import.', 'wp-olympiad'),
                ];
            }
            return self::import_one_file($quiz, $resolved, $override, $mode, $force, $hide_points);
        }

        $scan_error = null;
        $available = self::scan_network_folder_for_import_files($quiz, $override, $scan_error);
        if (empty($available)) {
            return [
                'success'        => false,
                'imported_count' => 0,
                'total_count'    => count($quiz->get_questions(false)),
                'message'        => ($scan_error ?: __('В папке import и при резервном поиске не найдено файлов заданий (.csv, .xlsx, .xlsm, .json, .tsv). Поместите файл в import или укажите его путь вручную.', 'wp-olympiad'))
                    . ' ' . self::describe_network_folder($quiz, $override),
            ];
        }

        // The scanner applies one consistent name priority for the UI,
        // validation and import. Try examples only after working files.
        $candidates = array_values(array_unique(array_map(static fn(array $f): string => (string)$f['path'], $available)));

        // Пробуем файлы по очереди: первый, где реально есть задания, и становится источником
        $first_error = null;
        $first_result = null;

        foreach ($candidates as $candidate) {
            $result = self::import_one_file($quiz, $candidate, $override, $mode, $force, $hide_points);

            if (!empty($result['success'])) {
                return $result;
            }

            if ($first_result === null) {
                $first_result = $result;
            }
            if ($first_error === null) {
                $first_error = (string)($result['message'] ?? '');
            }
        }

        $fallback = $first_result ?: [
            'success'        => false,
            'imported_count' => 0,
            'total_count'    => count($quiz->get_questions(false)),
            'message'        => __('В сетевой папке не найдено файлов для импорта заданий (.csv, .xlsx, .xlsm, .json).', 'wp-olympiad'),
        ];
        $fallback['message'] = ($first_error ?: (string)$fallback['message']) . ' ' . self::describe_network_folder($quiz, $override);

        return $fallback;
    }

    /**
     * Import a single file from the network folder into the quiz.
     *
     * @return array{success: bool, imported_count: int, total_count: int, filename?: string, mode?: string, unchanged?: bool, message: string}
     */
    private static function import_one_file(Quiz $quiz, string $filename, array $override, string $mode, bool $force, bool $hide_points = true): array
    {

        $read_error = null;
        $content = self::read_network_file($quiz, $filename, $override, $read_error);

        // Читаемо, но внутри только пробелы/переводы строк — это фактически пустой файл
        if ($content !== null && trim($content) === '') {
            $content = null;
            $read_error = sprintf(__('Файл «%s» не содержит данных (только пустые строки).', 'wp-olympiad'), $filename);
        }

        if ($content === null) {
            $message = $read_error ?: sprintf(__('Не удалось прочитать файл «%s» из сетевой папки.', 'wp-olympiad'), $filename);

            // Для локальной/смонтированной папки добавляем сведения о содержимом: так причина
            // видна сразу. Сетевые транспорты (SMB/WebDAV/FTP) описывают себя сами.
            $read_config = NetworkExport::get_quiz_network_config($quiz, $override);
            if (NetworkExport::detect_type($read_config) === 'filesystem'
                && !str_contains($message, 'Папка ') && !str_contains($message, 'Путь ')
                && !str_contains($message, 'Доступные файлы') && !str_contains($message, 'не найдены')) {
                $message .= ' ' . self::describe_network_folder($quiz, $override);
            }

            return [
                'success'        => false,
                'imported_count' => 0,
                'total_count'    => count($quiz->get_questions(false)),
                'message'        => $message,
                'filename'       => $filename,
            ];
        }

        $content_hash = md5($content);
        $last_hash = (string)$quiz->get_setting('last_network_import_hash', '');

        // If file content hasn't changed and not forced, skip re-import
        if (!$force && $last_hash !== '' && $last_hash === $content_hash && NetworkSync::question_count($quiz->id) > 0) {
            return [
                'success'        => true,
                'unchanged'      => true,
                'imported_count' => 0,
                'total_count'    => count($quiz->get_questions(false)),
                'filename'       => $filename,
                'mode'           => $mode,
                'message'        => sprintf(__('Файл «%s» не изменился с момента предыдущего импорта. Синхронизация не требуется.', 'wp-olympiad'), $filename),
            ];
        }

        $analysis = self::analyze_questions_content($content, $filename);
        $parsed = $analysis['questions'];

        if (empty($parsed)) {
            $empty_message = !empty($analysis['notice'])
                ? sprintf(__('Файл «%1$s»: %2$s', 'wp-olympiad'), $filename, (string)$analysis['notice'])
                : sprintf(__('Файл «%s» прочитан, но не содержит корректных строк с заданиями.', 'wp-olympiad'), $filename);

            $err_meta = [
                'timestamp'      => current_time('mysql'),
                'filename'       => $filename,
                'imported_count' => 0,
                'total_count'    => count($quiz->get_questions(false)),
                'mode'           => $mode,
                'hash'           => $content_hash,
                'status'         => 'error',
                'message'        => $empty_message,
            ];
            $quiz->update_setting('last_network_import', $err_meta);
            return [
                'success'        => false,
                'imported_count' => 0,
                'total_count'    => $err_meta['total_count'],
                'filename'       => $filename,
                'mode'           => $mode,
                'message'        => $err_meta['message'],
            ];
        }

        NetworkSync::assert_quiz_lock($quiz->id);
        $res = self::import_to_quiz($quiz->id, $parsed, $mode, $hide_points);
        $total_count = count($quiz->get_questions(false));
        $res['stats'] = $analysis['stats'];
        $res['report'] = $analysis['report'];
        $res['filename'] = $filename;

        if ($res['success']) {
            if (!empty($analysis['stats']['warnings'])) {
                $res['message'] .= ' ' . sprintf(
                    __('Предупреждений при разборе файла: %d (подробности в отчёте импорта).', 'wp-olympiad'),
                    (int)$analysis['stats']['warnings']
                );
            }

            $meta = [
                'timestamp'      => current_time('mysql'),
                'filename'       => $filename,
                'imported_count' => $res['imported_count'],
                'total_count'    => $total_count,
                'mode'           => $mode,
                'hash'           => $content_hash,
                'status'         => 'success',
                'message'        => $res['message'],
            ];
            $quiz->update_setting('last_network_import', $meta);
            $quiz->update_setting('last_network_import_hash', $content_hash);
            $quiz->update_setting('last_network_import_time', time());

            $res['filename'] = $filename;
            $res['mode'] = $mode;
            $res['total_count'] = $total_count;
            $res['timestamp'] = $meta['timestamp'];
        }

        return $res;
    }

    /**
     * Read from network storage and import questions in one step
     *
     * @param int $quiz_id
     * @param string $filename If empty, auto-detects questions.csv or first available file
     * @param string $mode 'append' or 'replace'
     * @param array $override
     * @return array{success: bool, imported_count: int, message: string, filename?: string, details?: array}
     */
    public static function import_from_network(int $quiz_id, string $filename = '', string $mode = 'append', array $override = [], bool $hide_points = true): array
    {
        $quiz = Quiz::get($quiz_id);
        if (!$quiz) {
            return [
                'success'        => false,
                'imported_count' => 0,
                'message'        => __('Олимпиада не найдена.', 'wp-olympiad'),
            ];
        }

        // Normal manual retries are idempotent too; explicit force remains available
        // through auto_import_for_quiz() for intentional administrative re-imports.
        return self::auto_import_for_quiz($quiz, false, $override, $mode, $filename, $hide_points);
    }
}
