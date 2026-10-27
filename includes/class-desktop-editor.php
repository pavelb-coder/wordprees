<?php
declare(strict_types=1);
namespace WPOlympiad;

if (!defined('ABSPATH')) { exit; }

/** Ships the offline Windows editor; never executes it on the web server. */
final class DesktopEditor
{
    public const VERSION = '1.0.0';
    public const STATE_PREFIX = 'wpo_editor_deploy_';
    private const RETRY_AFTER = 60;
    private const DEPLOY_FORMATS = ['exe', 'source'];

    public static function init(): void
    {
        add_action('admin_post_wp_olympiad_download_editor', [self::class, 'download']);
        add_action('wp_ajax_wp_olympiad_sync_desktop_editor', [self::class, 'ajax_sync']);
    }

    public static function manifest(): array
    {
        return require WP_OLYMPIAD_PATH . 'includes/desktop-editor-manifest.php';
    }

    /** An explicit allow-list, not a user-supplied path. Verify bytes before serving. */
    public static function asset(string $format): array
    {
        $manifest = self::manifest();
        if (!isset($manifest[$format])) {
            throw new \InvalidArgumentException(__('Неизвестный файл редактора.', 'wp-olympiad'));
        }
        $a = $manifest[$format];
        // Only the program and sources live in import/; optional downloads and
        // their licence remain available from a separate bundled directory.
        $directory = in_array($format, self::DEPLOY_FORMATS, true) ? 'import/' : 'editor-resources/';
        $a['path'] = WP_OLYMPIAD_PATH . $directory . $a['name'];
        clearstatcache(true, $a['path']);
        if (!is_file($a['path']) || !is_readable($a['path'])
            || (int)filesize($a['path']) !== $a['size']
            || !hash_equals($a['sha256'], (string)@hash_file('sha256', $a['path']))) {
            throw new \RuntimeException(sprintf(__('Файл редактора «%s» отсутствует или повреждён. Переустановите полный архив плагина.', 'wp-olympiad'), $a['name']));
        }
        return $a;
    }

    public static function bundle_id(): string
    {
        // Include the deployed subset: older cached states described four files.
        return hash('sha256', self::VERSION . wp_json_encode(self::manifest()) . wp_json_encode(self::DEPLOY_FORMATS));
    }

    public static function download_url(string $format = 'exe'): string
    {
        if (!isset(self::manifest()[$format])) { return ''; }
        return wp_nonce_url(add_query_arg([
            'action' => 'wp_olympiad_download_editor', 'format' => $format,
        ], admin_url('admin-post.php')), 'wpo_download_editor_' . $format);
    }

    public static function download(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Доступ запрещён.', 'wp-olympiad'), '', ['response' => 403]);
        }
        $format = sanitize_key((string)($_GET['format'] ?? 'exe'));
        if (!isset(self::manifest()[$format])) {
            wp_die(esc_html__('Неизвестный файл редактора.', 'wp-olympiad'), '', ['response' => 400]);
        }
        check_admin_referer('wpo_download_editor_' . $format);
        try {
            $a = self::asset($format);
            // Serve the very bytes verified below, not a path that an update
            // could replace between the integrity check and the download.
            $bytes = @file_get_contents($a['path']);
            if ($bytes === false || strlen($bytes) !== $a['size'] || !hash_equals($a['sha256'], hash('sha256', $bytes))) {
                throw new \RuntimeException(__('Файл редактора изменился во время чтения. Повторите скачивание.', 'wp-olympiad'));
            }
        }
        catch (\Throwable $e) { wp_die(esc_html($e->getMessage()), '', ['response' => 503]); }
        while (ob_get_level()) { ob_end_clean(); }
        nocache_headers();
        header('X-Content-Type-Options: nosniff');
        header('Content-Type: ' . (str_ends_with($a['name'], '.zip') ? 'application/zip' : 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . $a['name'] . '"');
        header('Content-Length: ' . $a['size']);
        echo $bytes;
        exit;
    }

    public static function configured(Quiz $quiz, array $override = []): bool
    {
        return trim((string)($override['path'] ?? $quiz->get_setting('network_folder_path', ''))) !== ''
            || (bool)$quiz->get_setting('network_auto_import', 0)
            || (bool)$quiz->get_setting('enable_network_export', 0);
    }

    private static function config(array $config): array
    {
        $config['path'] = trim((string)($config['path'] ?? '')) ?: NetworkExport::get_default_network_path();
        $config['type'] = NetworkExport::detect_type($config);
        $config['user'] = (string)($config['user'] ?? '');
        $config['password'] = (string)($config['password'] ?? '');
        return $config;
    }

    public static function state_key(array $config): string
    {
        $c = self::config($config);
        // Credentials are never stored in the state. Their keyed digest invalidates old failures.
        return self::STATE_PREFIX . hash_hmac('sha256', wp_json_encode([
            $c['type'], rtrim($c['path'], '/\\'), $c['user'], $c['password'],
        ]), wp_salt('auth'));
    }

    public static function status(array $config): array
    {
        $state = get_option(self::state_key($config), []);
        return is_array($state) ? $state : [];
    }

    public static function ensure_for_quiz(Quiz $quiz, array $override = [], bool $force = false): array
    {
        if (!self::configured($quiz, $override)) { return ['success' => true, 'skipped' => true]; }
        return self::deploy(NetworkExport::get_quiz_network_config($quiz, $override), $force);
    }

    /** Avoid import/import when the connected root is already the import directory. */
    public static function import_prefix(array $config): string
    {
        $path = (string)($config['path'] ?? '');
        if (preg_match('#^[a-z]+://#i', $path)) { $path = (string)(parse_url($path, PHP_URL_PATH) ?? ''); }
        return strcasecmp(basename(str_replace('\\', '/', rtrim($path, '/\\'))), 'import') === 0 ? '' : 'import/';
    }

    public static function payload(array $config): array
    {
        $files = [];
        foreach (self::DEPLOY_FORMATS as $kind) {
            $a = self::asset($kind);
            $data = @file_get_contents($a['path']);
            if ($data === false || !hash_equals($a['sha256'], hash('sha256', $data))) {
                throw new \RuntimeException(__('Не удалось прочитать комплект редактора.', 'wp-olympiad'));
            }
            $files[self::import_prefix($config) . $a['name']] = $data;
        }
        return $files;
    }

    private static function safe_destination(string $path): string
    {
        return (string)preg_replace('#(://)[^/@]+@#', '$1', $path);
    }

    /** Idempotent installation: only EXE and sources; never delete existing files. */
    public static function deploy(array $config, bool $force = false): array
    {
        $config = self::config($config);
        $key = self::state_key($config);
        $state = self::status($config);
        $bundle = self::bundle_id();
        $local = self::local_root($config);
        if (!$force && ($state['bundle'] ?? '') === $bundle) {
            if (empty($state['success']) && (time() - (int)($state['checked_at'] ?? 0)) < self::RETRY_AFTER) {
                return array_merge($state, ['cached' => true]);
            }
            // Remote binaries are not uploaded every minute. New bundles / explicit repair bypass this.
            if ($local === null && !empty($state['success'])) {
                return array_merge($state, ['cached' => true, 'written' => 0]);
            }
        }
        $lock = $key . '_lock';
        $until = (int)get_option($lock, 0);
        if ($until > 0 && $until < time()) { delete_option($lock); }
        if (!add_option($lock, time() + 120, '', false)) {
            return ['success' => true, 'pending' => true, 'message' => __('Размещение редактора уже выполняется.', 'wp-olympiad')];
        }
        try {
            $files = self::payload($config);
            if ($local !== null) {
                $result = self::deliver_local($files, $local);
            } elseif ($config['type'] === 'webdav') {
                $result = self::deliver_webdav($files, $config);
            } elseif ($config['type'] === 'ftp') {
                $result = self::deliver_ftp($files, $config);
            } else {
                // One SMB put per invocation: a later successful put must not
                // hide a failure of an earlier file in a batched smbclient call.
                $result = ['success' => true, 'written' => 0];
                foreach ($files as $name => $content) {
                    $one = NetworkExport::deliver_files_public([$name => $content], $config);
                    if (empty($one['success'])) {
                        $result = array_merge($one, ['written' => $result['written']]);
                        break;
                    }
                    $result['destination'] = $one['destination'] ?? $config['path'];
                    $result['written']++;
                }
            }
            $ok = !empty($result['success']);
            $result['bundle'] = $bundle;
            $result['version'] = self::VERSION;
            $result['checked_at'] = time();
            $result['destination'] = self::safe_destination((string)($result['destination'] ?? $config['path']));
            $result['files'] = array_keys($files);
            $result['installed_at'] = $ok ? ((int)($result['written'] ?? 0) > 0 ? time() : (int)($state['installed_at'] ?? time())) : (int)($state['installed_at'] ?? 0);
            $result['message'] = $ok
                ? sprintf(__('Редактор Windows %s размещён в папке import. Запустите QuestionStudio.exe на ПК и сохраняйте рядом файлы вопросов для импорта.', 'wp-olympiad'), self::VERSION)
                : __('Не удалось разместить редактор в import: ', 'wp-olympiad') . (string)($result['message'] ?? __('Проверьте права записи.', 'wp-olympiad'));
            if (!$ok || ($state['bundle'] ?? '') !== $bundle || empty($state['success']) || !empty($result['written'])) {
                update_option($key, $result, false);
            }
            return $result;
        } catch (\Throwable $e) {
            $result = ['success' => false, 'bundle' => $bundle, 'version' => self::VERSION, 'checked_at' => time(),
                'message' => __('Не удалось разместить редактор: ', 'wp-olympiad') . $e->getMessage()];
            update_option($key, $result, false);
            return $result;
        } finally { delete_option($lock); }
    }

    private static function local_root(array $config): ?string
    {
        if ($config['type'] === 'filesystem') {
            $path = NetworkExport::resolve_local_path($config['path']);
            if (DIRECTORY_SEPARATOR === '/' && str_starts_with($path, '\\\\') && !is_dir($path)) { return null; }
            return $path;
        }
        if ($config['type'] === 'smb') {
            $path = NetworkExport::resolve_local_path($config['path']);
            if (DIRECTORY_SEPARATOR === '\\' || is_dir($path)) { return $path; }
        }
        return null;
    }

    private static function deliver_local(array $files, string $root): array
    {
        $written = 0;
        foreach ($files as $name => $content) {
            $file = rtrim($root, '/\\') . '/' . $name;
            $parent = dirname($file);
            if (is_link($parent) || is_link($file)) {
                throw new \RuntimeException(__('Файл редактора или папка import является символической ссылкой. Выберите обычную папку.', 'wp-olympiad'));
            }
            if (!is_dir($parent) && !wp_mkdir_p($parent)) {
                throw new \RuntimeException(__('Не удалось создать папку import. Проверьте путь и права записи.', 'wp-olympiad'));
            }
            clearstatcache(true, $file);
            if (is_file($file) && filesize($file) === strlen($content)
                && hash_equals(hash('sha256', $content), (string)@hash_file('sha256', $file))) { continue; }
            if (!is_writable($parent)) {
                throw new \RuntimeException(__('Папка import недоступна для записи веб-серверу.', 'wp-olympiad'));
            }
            $tmp = @tempnam($parent, '.wpo-editor-');
            if ($tmp === false) { throw new \RuntimeException(__('Не удалось создать временный файл редактора.', 'wp-olympiad')); }
            try {
                if (@file_put_contents($tmp, $content, LOCK_EX) !== strlen($content)) {
                    throw new \RuntimeException(__('Ошибка записи файла редактора (возможно, недостаточно места).', 'wp-olympiad'));
                }
                @chmod($tmp, str_ends_with($name, '.exe') ? 0755 : 0644);
                if (!@rename($tmp, $file)) {
                    throw new \RuntimeException(__('Не удалось заменить файл редактора. Проверьте права; если EXE открыт на ПК, закройте программу и повторите.', 'wp-olympiad'));
                }
                $written++;
            } finally { if (is_file($tmp)) { @unlink($tmp); } }
        }
        return ['success' => true, 'destination' => $root, 'written' => $written];
    }

    private static function deliver_webdav(array $files, array $config): array
    {
        $base = rtrim($config['path'], '/');
        $headers = [];
        if ($config['user'] !== '' || $config['password'] !== '') {
            $headers['Authorization'] = 'Basic ' . base64_encode($config['user'] . ':' . $config['password']);
        }
        if (self::import_prefix($config) !== '') {
            $r = wp_remote_request($base . '/import/', ['method' => 'MKCOL', 'headers' => $headers, 'timeout' => 12]);
            $code = (int)wp_remote_retrieve_response_code($r);
            if (is_wp_error($r) || !in_array($code, [200, 201, 204, 405], true)) {
                return ['success' => false, 'message' => is_wp_error($r) ? $r->get_error_message() : 'WebDAV MKCOL: HTTP ' . $code];
            }
        }
        $written = 0;
        foreach ($files as $name => $content) {
            $url = $base . '/' . implode('/', array_map('rawurlencode', explode('/', $name)));
            $mime = str_ends_with($name, '.exe') ? 'application/octet-stream' : (str_ends_with($name, '.zip') ? 'application/zip' : 'text/plain; charset=UTF-8');
            $r = wp_remote_request($url, ['method' => 'PUT', 'headers' => array_merge($headers, ['Content-Type' => $mime]), 'body' => $content, 'timeout' => 15]);
            $code = (int)wp_remote_retrieve_response_code($r);
            if (is_wp_error($r) || $code < 200 || $code >= 300) {
                return ['success' => false, 'message' => is_wp_error($r) ? $r->get_error_message() : sprintf('WebDAV %s: HTTP %d', $name, $code), 'written' => $written];
            }
            $written++;
        }
        return ['success' => true, 'destination' => $base, 'written' => $written];
    }

    private static function deliver_ftp(array $files, array $config): array
    {
        if (!function_exists('ftp_connect')) { return ['success' => false, 'message' => __('В PHP отсутствует расширение FTP.', 'wp-olympiad')]; }
        $p = parse_url($config['path']);
        if (!$p || empty($p['host'])) { return ['success' => false, 'message' => __('Некорректный URL FTP.', 'wp-olympiad')]; }
        $tls = strtolower((string)($p['scheme'] ?? '')) === 'ftps';
        if ($tls && !function_exists('ftp_ssl_connect')) { return ['success' => false, 'message' => __('На сервере не поддерживается FTPS.', 'wp-olympiad')]; }
        $c = $tls ? @ftp_ssl_connect($p['host'], (int)($p['port'] ?? 21), 12) : @ftp_connect($p['host'], (int)($p['port'] ?? 21), 12);
        if (!$c) { return ['success' => false, 'message' => __('Не удалось подключиться к FTP.', 'wp-olympiad')]; }
        try {
            $user = $config['user'] ?: rawurldecode((string)($p['user'] ?? 'anonymous'));
            $pass = $config['password'] ?: rawurldecode((string)($p['pass'] ?? ''));
            if (!@ftp_login($c, $user, $pass)) { return ['success' => false, 'message' => __('Неверный логин или пароль FTP.', 'wp-olympiad')]; }
            @ftp_pasv($c, true);
            @ftp_set_option($c, FTP_TIMEOUT_SEC, 15);
            if (!@ftp_chdir($c, '/')) { return ['success' => false, 'message' => __('Не удалось открыть корень FTP.', 'wp-olympiad')]; }
            $parts = explode('/', trim(rawurldecode((string)($p['path'] ?? '')), '/'));
            if (self::import_prefix($config) !== '') { $parts[] = 'import'; }
            foreach ($parts as $part) {
                if ($part === '') { continue; }
                if (!@ftp_chdir($c, $part)) {
                    if (@ftp_mkdir($c, $part) === false || !@ftp_chdir($c, $part)) {
                        return ['success' => false, 'message' => __('Не удалось создать или открыть папку import на FTP.', 'wp-olympiad')];
                    }
                }
            }
            $written = 0;
            foreach ($files as $name => $content) {
                $h = fopen('php://temp', 'w+b');
                if ($h === false) { return ['success' => false, 'message' => __('Не удалось подготовить файл FTP.', 'wp-olympiad')]; }
                fwrite($h, $content); rewind($h);
                try { $ok = @ftp_fput($c, basename($name), $h, FTP_BINARY); }
                finally { fclose($h); }
                if (!$ok) { return ['success' => false, 'message' => sprintf(__('Не удалось записать %s на FTP.', 'wp-olympiad'), basename($name)), 'written' => $written]; }
                $written++;
            }
            return ['success' => true, 'destination' => $config['path'], 'written' => $written];
        } finally { @ftp_close($c); }
    }

    public static function ajax_sync(): void
    {
        if (!current_user_can('manage_options')) { wp_send_json_error(['message' => __('Доступ запрещён.', 'wp-olympiad')], 403); }
        check_ajax_referer('wp_olympiad_admin_nonce', 'nonce');
        $id = (int)($_POST['quiz_id'] ?? 0);
        $quiz = $id > 0 ? Quiz::get($id) : null;
        if ($id > 0 && !$quiz) { wp_send_json_error(['message' => __('Олимпиада не найдена.', 'wp-olympiad')], 404); }
        $override = [];
        foreach (['type', 'path', 'user', 'password'] as $k) {
            if (isset($_POST[$k])) { $override[$k] = (string)wp_unslash($_POST[$k]); }
        }
        if (isset($override['path'])) { $override['path'] = NetworkExport::sanitize_path($override['path']); }
        $config = $quiz ? NetworkExport::get_quiz_network_config($quiz, $override) : $override;
        $result = self::deploy($config, true);
        if ($result['success']) { wp_send_json_success($result); }
        wp_send_json_error($result);
    }
}
