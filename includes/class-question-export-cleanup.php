<?php
declare(strict_types=1);
namespace WPOlympiad;
if (!defined('ABSPATH')) { exit; }

/** Constrained retirement of TWO old output aliases; never a general file deleter. */
final class QuestionExportCleanup
{
    private const NAMES = ['questions.csv', 'questions.json'];
    private const DIRECTORY = 'вопросы';

    private static function local_root(array $config): ?string
    {
        $type = NetworkExport::detect_type($config); $path = NetworkExport::resolve_local_path((string)($config['path'] ?? ''));
        if ($type === 'filesystem') {
            if (DIRECTORY_SEPARATOR === '/' && str_starts_with($path, '\\\\') && !is_dir($path)) return null;
            return $path;
        }
        if ($type === 'smb' && (DIRECTORY_SEPARATOR === '\\' || is_dir($path))) return $path;
        return null;
    }

    /** Refuse a linked output directory or a replacement file before writing. */
    public static function preflight(array $config, array $files): array
    {
        $root = self::local_root($config);
        if ($root === null) {
            if (in_array(NetworkExport::detect_type($config), ['smb', 'filesystem'], true)) {
                $p = NetworkExport::parse_smb_path((string)($config['path'] ?? ''));
                if (!$p || !function_exists('exec') || !NetworkExport::get_smbclient_binary()) return self::error('вопросы', __('SMB требует доступный smbclient.', 'wp-olympiad'));
                if (strpbrk((string)($config['user'] ?? '') . (string)($config['password'] ?? '') . $p['user'] . $p['password'], "\r\n") !== false) return self::error('вопросы', __('Некорректные реквизиты SMB.', 'wp-olympiad'));
            }
            return ['success' => true];
        }
        $dir = rtrim($root, '/\\') . '/' . self::DIRECTORY;
        if (is_link($dir) || (file_exists($dir) && !is_dir($dir))) return self::error('вопросы', __('Экспортная папка является ссылкой или не является каталогом.', 'wp-olympiad'));
        if (is_dir($dir)) {
            $base = realpath($root); $actual = realpath($dir);
            if ($base === false || $actual === false || !str_starts_with(wp_normalize_path($actual) . '/', rtrim(wp_normalize_path($base), '/') . '/')) return self::error('вопросы', __('Папка выходит за настроенный корень.', 'wp-olympiad'));
        }
        foreach ($files as $name => $_content) {
            if (!str_starts_with($name, self::DIRECTORY . '/')) continue;
            $target = rtrim($root, '/\\') . '/' . $name;
            if (is_link($target) || is_dir($target)) return self::error($name, __('Файл замены является ссылкой или каталогом.', 'wp-olympiad'));
        }
        foreach (self::NAMES as $name) {
            $target = $dir . '/' . $name;
            if (is_dir($target) && !is_link($target)) return self::error(self::DIRECTORY . '/' . $name, __('Это каталог; его содержимое не удаляется.', 'wp-olympiad'));
        }
        return ['success' => true];
    }

    public static function prune(array $config): array
    {
        $root = self::local_root($config);
        if ($root !== null) return self::filesystem($config, $root);
        return match (NetworkExport::detect_type($config)) {
            'webdav' => self::webdav($config),
            'ftp' => self::ftp($config),
            'smb', 'filesystem' => self::smb($config),
            default => self::error('вопросы', __('Неизвестный тип подключения.', 'wp-olympiad')),
        };
    }

    private static function error(string $relative, string $reason): array
    {
        // Only relative paths and protocol status are included, never credentials.
        return ['success' => false, 'message' => sprintf(__('Не удалось убрать старый экспорт «%1$s»: %2$s', 'wp-olympiad'), $relative, $reason)];
    }

    private static function filesystem(array $config, string $root): array
    {
        $safe = self::preflight($config, []); if (!$safe['success']) return $safe;
        $removed = []; $absent = [];
        foreach (self::NAMES as $name) {
            $rel = self::DIRECTORY . '/' . $name; $path = rtrim($root, '/\\') . '/' . $rel; clearstatcache(true, $path);
            if (!file_exists($path) && !is_link($path)) { $absent[] = $rel; continue; }
            // unlink removes a file symlink itself, never its target.
            if (!is_file($path) && !is_link($path)) return self::error($rel, __('Это не обычный файл.', 'wp-olympiad'));
            if (!@unlink($path)) {
                clearstatcache(true, $path);
                if (!file_exists($path) && !is_link($path)) { $absent[] = $rel; continue; }
                return self::error($rel, __('Нет прав удаления или файл занят.', 'wp-olympiad'));
            }
            $removed[] = $rel;
        }
        return ['success' => true, 'removed' => $removed, 'absent' => $absent];
    }

    private static function webdav(array $config): array
    {
        $base = rtrim((string)$config['path'], '/'); $headers = [];
        if (!empty($config['user']) || !empty($config['password'])) $headers['Authorization'] = 'Basic ' . base64_encode(($config['user'] ?? '') . ':' . ($config['password'] ?? ''));
        $removed = []; $absent = [];
        foreach (self::NAMES as $name) {
            $rel = self::DIRECTORY . '/' . $name;
            $url = $base . '/' . implode('/', array_map('rawurlencode', explode('/', $rel)));
            // A redirect must not move this destructive request to another target.
            $r = wp_remote_request($url, ['method' => 'DELETE', 'headers' => $headers, 'timeout' => 15, 'redirection' => 0, 'sslverify' => false]);
            $code = is_wp_error($r) ? 0 : (int)wp_remote_retrieve_response_code($r);
            if (in_array($code, [404, 410], true)) { $absent[] = $rel; continue; }
            if (!in_array($code, [200, 204], true)) return self::error($rel, 'WebDAV HTTP ' . $code);
            $removed[] = $rel;
        }
        return ['success' => true, 'removed' => $removed, 'absent' => $absent];
    }

    private static function ftp(array $config): array
    {
        if (!function_exists('ftp_connect')) return self::error('вопросы', __('В PHP отсутствует FTP.', 'wp-olympiad'));
        $p = parse_url((string)$config['path']); if (!$p || empty($p['host'])) return self::error('вопросы', __('Некорректный FTP URL.', 'wp-olympiad'));
        $tls = strtolower((string)($p['scheme'] ?? '')) === 'ftps';
        if ($tls && !function_exists('ftp_ssl_connect')) return self::error('вопросы', __('FTPS не поддерживается сервером.', 'wp-olympiad'));
        $c = $tls ? @ftp_ssl_connect($p['host'], (int)($p['port'] ?? 21), 15) : @ftp_connect($p['host'], (int)($p['port'] ?? 21), 15);
        if (!$c) return self::error('вопросы', __('Нет соединения с FTP.', 'wp-olympiad'));
        try {
            $user = ($config['user'] ?? '') ?: rawurldecode((string)($p['user'] ?? 'anonymous'));
            $pass = ($config['password'] ?? '') ?: rawurldecode((string)($p['pass'] ?? ''));
            if (!@ftp_login($c, $user, $pass)) return self::error('вопросы', __('Авторизация FTP отклонена.', 'wp-olympiad'));
            @ftp_pasv($c, true); @ftp_set_option($c, FTP_TIMEOUT_SEC, 15);
            $dir = rawurldecode((string)($p['path'] ?? '/'));
            if (!@ftp_chdir($c, $dir === '' ? '/' : $dir)) return self::error('вопросы', __('Не удалось открыть настроенный корень FTP.', 'wp-olympiad'));
            $list = @ftp_nlist($c, self::DIRECTORY);
            if ($list === false) return self::error('вопросы', __('FTP не подтвердил список экспортной папки.', 'wp-olympiad'));
            $names = array_map(static fn($f) => basename(str_replace('\\', '/', $f)), $list); $removed = []; $absent = [];
            foreach (self::NAMES as $name) {
                $rel = self::DIRECTORY . '/' . $name;
                if (!in_array($name, $names, true)) { $absent[] = $rel; continue; }
                if (!@ftp_delete($c, $rel)) {
                    $fresh = @ftp_nlist($c, self::DIRECTORY);
                    $fresh_names = is_array($fresh) ? array_map(static fn($f) => basename(str_replace('\\', '/', $f)), $fresh) : null;
                    if (is_array($fresh_names) && !in_array($name, $fresh_names, true)) { $absent[] = $rel; continue; }
                    return self::error($rel, __('FTP запретил удаление.', 'wp-olympiad'));
                }
                $removed[] = $rel;
            }
            return ['success' => true, 'removed' => $removed, 'absent' => $absent];
        } finally { @ftp_close($c); }
    }

    private static function smb(array $config): array
    {
        $p = NetworkExport::parse_smb_path((string)$config['path']); $bin = NetworkExport::get_smbclient_binary();
        if (!$p || !$bin || !function_exists('exec')) return self::error('вопросы', __('SMB требует корректный путь и доступный smbclient.', 'wp-olympiad'));
        $user = ($config['user'] ?? '') ?: $p['user']; $pass = ($config['password'] ?? '') ?: $p['password'];
        if (strpbrk($user . $pass, "\r\n") !== false) return self::error('вопросы', __('Некорректные реквизиты SMB.', 'wp-olympiad'));
        $authfile = null; $auth = '-N';
        try {
            if ($user !== '' || $pass !== '') {
                $authfile = tempnam(sys_get_temp_dir(), 'wpo_qcleanup_'); if ($authfile === false) return self::error('вопросы', __('Не удалось подготовить SMB-авторизацию.', 'wp-olympiad'));
                @chmod($authfile, 0600); $domain = '';
                if (str_contains($user, '\\')) [$domain, $user] = explode('\\', $user, 2);
                elseif (str_contains($user, '/')) [$domain, $user] = explode('/', $user, 2);
                if (@file_put_contents($authfile, "username = {$user}\npassword = {$pass}\n" . ($domain !== '' ? "domain = {$domain}\n" : '')) === false) return self::error('вопросы', __('Ошибка файла реквизитов SMB.', 'wp-olympiad'));
                $auth = '-A ' . escapeshellarg($authfile);
            }
            $removed = []; $absent = [];
            foreach (self::NAMES as $name) {
                $rel = self::DIRECTORY . '/' . $name;
                $command = 'del "' . str_replace('/', '\\', $rel) . '"';
                $full = escapeshellarg($bin) . ' ' . escapeshellarg($p['service']) . ' ' . $auth . ' -t 12';
                if ($p['subdir'] !== '') $full .= ' -D ' . escapeshellarg(str_replace('/', '\\', $p['subdir']));
                $full .= ' -c ' . escapeshellarg($command) . ' 2>&1';
                if (is_executable('/usr/bin/timeout')) $full = '/usr/bin/timeout 25 ' . $full;
                $out = []; $code = 1; exec($full, $out, $code); $text = implode("\n", $out);
                preg_match_all('/NT_STATUS_[A-Z0-9_]+/', $text, $matches); $errors = array_unique($matches[0]);
                $missing = ['NT_STATUS_NO_SUCH_FILE', 'NT_STATUS_OBJECT_NAME_NOT_FOUND', 'NT_STATUS_OBJECT_PATH_NOT_FOUND'];
                if ($errors && !array_diff($errors, $missing)) { $absent[] = $rel; continue; }
                if ($code !== 0 || $errors) return self::error($rel, __('SMB не подтвердил удаление.', 'wp-olympiad'));
                $removed[] = $rel;
            }
            return ['success' => true, 'removed' => $removed, 'absent' => $absent];
        } finally { if (is_string($authfile)) @unlink($authfile); }
    }
}
