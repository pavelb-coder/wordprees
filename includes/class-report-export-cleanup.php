<?php
declare(strict_types=1);

namespace WPOlympiad;

if (!defined('ABSPATH')) {
    exit;
}

/** Retire only the three previously generated report files for this quiz. */
final class ReportExportCleanup
{
    private const DIRECTORY = 'отчёты';

    /**
     * Run only after a successful full export. There is deliberately no arbitrary
     * path parameter, wildcard deletion, or recursive directory deletion.
     */
    public static function prune(array $config, Quiz $quiz): array
    {
        $slug = NetworkExport::build_safe_slug($quiz);
        if (!preg_match('/\A[a-zA-Z0-9_-]+\z/', $slug)) {
            return self::error(self::DIRECTORY, __('Недопустимое имя олимпиады.', 'wp-olympiad'));
        }
        $names = [
            'манифест_выгрузки_' . $slug . '.txt',
            'руководство_к_протоколам.txt',
            'сводка_результатов.txt',
        ];
        $root = self::local_root($config);
        if ($root !== null) {
            return self::filesystem($root, $names);
        }
        return match (NetworkExport::detect_type($config)) {
            'webdav' => self::webdav($config, $names),
            'ftp' => self::ftp($config, $names),
            'smb', 'filesystem' => self::smb($config, $names),
            default => self::error(self::DIRECTORY, __('Неизвестный тип подключения.', 'wp-olympiad')),
        };
    }

    private static function local_root(array $config): ?string
    {
        $type = NetworkExport::detect_type($config);
        $path = NetworkExport::resolve_local_path((string)($config['path'] ?? ''));
        if ($type === 'filesystem') {
            if (DIRECTORY_SEPARATOR === '/' && str_starts_with($path, '\\\\') && !is_dir($path)) {
                return null;
            }
            return $path;
        }
        return $type === 'smb' && (DIRECTORY_SEPARATOR === '\\' || is_dir($path)) ? $path : null;
    }

    private static function error(string $relative, string $reason): array
    {
        // Diagnostics contain relative names only, never network credentials.
        return ['success' => false, 'message' => sprintf(__('Не удалось убрать старые отчёты «%1$s»: %2$s', 'wp-olympiad'), $relative, $reason)];
    }

    private static function result(array $removed, array $absent, string $directory): array
    {
        return ['success' => true, 'removed' => $removed, 'absent' => $absent, 'directory' => $directory];
    }

    private static function filesystem(string $root, array $names): array
    {
        $dir = rtrim($root, '/\\') . '/' . self::DIRECTORY;
        clearstatcache(true, $dir);
        if (is_link($dir)) {
            return self::error(self::DIRECTORY, __('Папка отчётов является ссылкой; её содержимое не затрагивается.', 'wp-olympiad'));
        }
        if (!file_exists($dir)) {
            return self::result([], array_map(static fn($name) => self::DIRECTORY . '/' . $name, $names), 'absent');
        }
        if (!is_dir($dir)) {
            return self::error(self::DIRECTORY, __('Это не каталог.', 'wp-olympiad'));
        }
        $base = realpath($root);
        $actual = realpath($dir);
        if ($actual === false) {
            clearstatcache(true, $dir);
            if (!file_exists($dir) && !is_link($dir)) {
                return self::result([], array_map(static fn($name) => self::DIRECTORY . '/' . $name, $names), 'absent');
            }
        }
        if ($base === false || $actual === false || !str_starts_with(wp_normalize_path($actual) . '/', rtrim(wp_normalize_path($base), '/') . '/')) {
            return self::error(self::DIRECTORY, __('Папка выходит за настроенный корень.', 'wp-olympiad'));
        }

        // Preflight every exact target before deleting any of them.
        foreach ($names as $name) {
            $target = $dir . '/' . $name;
            clearstatcache(true, $target);
            if ((file_exists($target) || is_link($target)) && !is_file($target) && !is_link($target)) {
                return self::error(self::DIRECTORY . '/' . $name, __('Это не обычный файл; каталоги не удаляются.', 'wp-olympiad'));
            }
        }
        $removed = [];
        $absent = [];
        foreach ($names as $index => $name) {
            clearstatcache(true, $dir);
            if (!file_exists($dir) && !is_link($dir)) {
                foreach (array_slice($names, $index) as $remaining) $absent[] = self::DIRECTORY . '/' . $remaining;
                return self::result($removed, $absent, 'absent');
            }
            $current = realpath($dir);
            if ($current === false && !file_exists($dir) && !is_link($dir)) {
                foreach (array_slice($names, $index) as $remaining) $absent[] = self::DIRECTORY . '/' . $remaining;
                return self::result($removed, $absent, 'absent');
            }
            if (is_link($dir) || $current !== $actual) {
                return self::error(self::DIRECTORY, __('Папка отчётов изменилась во время очистки.', 'wp-olympiad'));
            }
            $rel = self::DIRECTORY . '/' . $name;
            $target = $dir . '/' . $name;
            clearstatcache(true, $target);
            if (!file_exists($target) && !is_link($target)) {
                $absent[] = $rel;
                continue;
            }
            // unlink deletes a file symlink itself, not its target.
            if (!@unlink($target)) {
                clearstatcache(true, $target);
                if (!file_exists($target) && !is_link($target)) {
                    $absent[] = $rel;
                    continue;
                }
                return self::error($rel, __('Нет прав удаления или файл занят.', 'wp-olympiad'));
            }
            $removed[] = $rel;
        }

        clearstatcache(true, $dir);
        if (!file_exists($dir) && !is_link($dir)) return self::result($removed, $absent, 'absent');
        if (is_link($dir) || realpath($dir) !== $actual) {
            return self::error(self::DIRECTORY, __('Папка отчётов изменилась во время очистки.', 'wp-olympiad'));
        }
        // rmdir is non-recursive and cannot remove a directory containing other files.
        if (@rmdir($dir)) {
            return self::result($removed, $absent, 'removed');
        }
        clearstatcache(true, $dir);
        if (!file_exists($dir) && !is_link($dir)) {
            return self::result($removed, $absent, 'absent');
        }
        if (is_link($dir) || !is_dir($dir)) {
            return self::error(self::DIRECTORY, __('Папка отчётов изменилась во время очистки.', 'wp-olympiad'));
        }
        $children = @scandir($dir);
        if ($children === false) {
            clearstatcache(true, $dir);
            if (!file_exists($dir) && !is_link($dir)) return self::result($removed, $absent, 'absent');
            return self::error(self::DIRECTORY, __('Не удалось проверить содержимое каталога.', 'wp-olympiad'));
        }
        if (array_diff($children, ['.', '..'])) {
            return self::result($removed, $absent, 'not_empty');
        }
        return self::error(self::DIRECTORY, __('Не удалось удалить пустой каталог.', 'wp-olympiad'));
    }

    private static function dav_url(string $base, string $relative): string
    {
        return rtrim($base, '/') . '/' . implode('/', array_map('rawurlencode', explode('/', $relative)));
    }

    /** Read a single Depth:0 WebDAV resource, refusing ambiguous or unsafe XML. */
    private static function dav_info(string $url, array $headers, string $relative): array
    {
        $headers['Depth'] = '0';
        $headers['Content-Type'] = 'application/xml; charset=utf-8';
        $response = wp_remote_request($url, [
            'method' => 'PROPFIND', 'headers' => $headers, 'timeout' => 15,
            'redirection' => 0, 'sslverify' => false, 'limit_response_size' => 1048576,
            'body' => '<?xml version="1.0" encoding="utf-8"?><d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/><d:getetag/></d:prop></d:propfind>',
        ]);
        $code = is_wp_error($response) ? 0 : (int)wp_remote_retrieve_response_code($response);
        if (in_array($code, [404, 410], true)) {
            return ['success' => true, 'exists' => false];
        }
        if ($code !== 207 || !class_exists('DOMDocument')) {
            return self::error($relative, sprintf(__('Не удалось безопасно проверить WebDAV (HTTP %d).', 'wp-olympiad'), $code));
        }
        $body = (string)wp_remote_retrieve_body($response);
        if ($body === '' || strlen($body) >= 1048576 || stripos($body, '<!DOCTYPE') !== false) {
            return self::error($relative, __('Недопустимый ответ WebDAV.', 'wp-olympiad'));
        }
        $xml = new \DOMDocument();
        if (!@$xml->loadXML($body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            return self::error($relative, __('Некорректный XML WebDAV.', 'wp-olympiad'));
        }
        $xpath = new \DOMXPath($xml);
        $xpath->registerNamespace('d', 'DAV:');
        $responses = $xpath->query('/d:multistatus/d:response');
        $expected = rtrim(rawurldecode((string)parse_url($url, PHP_URL_PATH)), '/');
        $matched = [];
        foreach ($responses ?: [] as $item) {
            $href = $xpath->query('./d:href', $item)?->item(0)?->textContent ?? '';
            $path = parse_url(trim($href), PHP_URL_PATH);
            if (is_string($path) && rtrim(rawurldecode($path), '/') === $expected) {
                $matched[] = $item;
            }
        }
        if (count($matched) !== 1) {
            return self::error($relative, __('WebDAV не подтвердил точный путь.', 'wp-olympiad'));
        }
        $type = null;
        $etag = '';
        foreach ($xpath->query('./d:propstat', $matched[0]) ?: [] as $propstat) {
            $status = $xpath->query('./d:status', $propstat)?->item(0)?->textContent ?? '';
            if (!preg_match('/\s200(?:\s|$)/', $status)) {
                continue;
            }
            $resource = $xpath->query('./d:prop/d:resourcetype', $propstat)?->item(0);
            if ($resource) {
                $type = ($xpath->query('./d:collection', $resource)?->length ?? 0) > 0 ? 'collection' : 'file';
            }
            $etag_node = $xpath->query('./d:prop/d:getetag', $propstat)?->item(0);
            if ($etag_node) {
                $etag = trim($etag_node->textContent);
            }
        }
        if ($type === null) {
            return self::error($relative, __('WebDAV не подтвердил тип объекта.', 'wp-olympiad'));
        }
        return ['success' => true, 'exists' => true, 'type' => $type, 'etag' => $etag];
    }

    private static function webdav(array $config, array $names): array
    {
        $base = (string)$config['path'];
        $headers = [];
        if (!empty($config['user']) || !empty($config['password'])) {
            $headers['Authorization'] = 'Basic ' . base64_encode(($config['user'] ?? '') . ':' . ($config['password'] ?? ''));
        }
        $directory = self::dav_info(self::dav_url($base, self::DIRECTORY), $headers, self::DIRECTORY);
        if (!$directory['success']) return $directory;
        if (!$directory['exists']) {
            return self::result([], array_map(static fn($name) => self::DIRECTORY . '/' . $name, $names), 'absent');
        }
        if ($directory['type'] !== 'collection') {
            return self::error(self::DIRECTORY, __('WebDAV-объект не является каталогом.', 'wp-olympiad'));
        }
        $existing = [];
        $absent = [];
        foreach ($names as $name) {
            $rel = self::DIRECTORY . '/' . $name;
            $url = self::dav_url($base, $rel);
            $info = self::dav_info($url, $headers, $rel);
            if (!$info['success']) return $info;
            if (!$info['exists']) {
                $absent[] = $rel;
                continue;
            }
            if ($info['type'] !== 'file') {
                return self::error($rel, __('Это каталог; его содержимое не удаляется.', 'wp-olympiad'));
            }
            // A conditional DELETE prevents removing a replacement resource.
            if (!preg_match('/\A"[^"\r\n]*"\z/', $info['etag'])) {
                return self::error($rel, __('WebDAV не предоставил надёжный ETag файла.', 'wp-olympiad'));
            }
            $existing[] = ['relative' => $rel, 'url' => $url, 'etag' => $info['etag']];
        }
        $removed = [];
        foreach ($existing as $file) {
            $response = wp_remote_request($file['url'], [
                'method' => 'DELETE', 'headers' => array_merge($headers, ['If-Match' => $file['etag']]),
                'timeout' => 15, 'redirection' => 0, 'sslverify' => false,
            ]);
            $code = is_wp_error($response) ? 0 : (int)wp_remote_retrieve_response_code($response);
            if (in_array($code, [404, 410], true)) {
                $absent[] = $file['relative'];
            } elseif (in_array($code, [200, 204], true)) {
                $removed[] = $file['relative'];
            } else {
                return self::error($file['relative'], 'WebDAV HTTP ' . $code);
            }
        }
        // WebDAV DELETE on a collection is recursive, even if a prior listing
        // appears empty. Never risk concurrent or hidden user files.
        return self::result($removed, $absent, 'webdav_retained');
    }

    /** Normalize the structured MLSD result; false means the server cannot verify types. */
    private static function ftp_entries($connection, string $path): ?array
    {
        if (!function_exists('ftp_mlsd')) return null;
        $entries = @ftp_mlsd($connection, $path);
        if ($entries === false) return null;
        $by_name = [];
        foreach ($entries as $entry) {
            $name = (string)($entry['name'] ?? '');
            if ($name !== '' && $name !== '.' && $name !== '..') {
                $by_name[$name] = strtolower((string)($entry['type'] ?? ''));
            }
        }
        return $by_name;
    }

    /** Require an unambiguous Unix LIST entry, rather than following an FTP link. */
    private static function ftp_listed_kind($connection, string $name): ?string
    {
        $lines = @ftp_rawlist($connection, '.');
        if ($lines === false) return null;
        $matches = [];
        foreach ($lines as $line) {
            if (preg_match('/(?:^|\s)' . preg_quote($name, '/') . '(?:\s+->\s.*)?\z/u', $line)) {
                $matches[] = $line;
            }
        }
        if (count($matches) !== 1) return null;
        return match ($matches[0][0] ?? '') { 'd' => 'dir', '-' => 'file', default => null };
    }

    private static function ftp(array $config, array $names): array
    {
        if (!function_exists('ftp_connect')) return self::error(self::DIRECTORY, __('В PHP отсутствует FTP.', 'wp-olympiad'));
        $parsed = parse_url((string)$config['path']);
        if (!$parsed || empty($parsed['host'])) return self::error(self::DIRECTORY, __('Некорректный FTP URL.', 'wp-olympiad'));
        $tls = strtolower((string)($parsed['scheme'] ?? '')) === 'ftps';
        if ($tls && !function_exists('ftp_ssl_connect')) return self::error(self::DIRECTORY, __('FTPS не поддерживается сервером.', 'wp-olympiad'));
        $connection = $tls ? @ftp_ssl_connect($parsed['host'], (int)($parsed['port'] ?? 21), 15) : @ftp_connect($parsed['host'], (int)($parsed['port'] ?? 21), 15);
        if (!$connection) return self::error(self::DIRECTORY, __('Нет соединения с FTP.', 'wp-olympiad'));
        try {
            $user = ($config['user'] ?? '') ?: rawurldecode((string)($parsed['user'] ?? 'anonymous'));
            $password = ($config['password'] ?? '') ?: rawurldecode((string)($parsed['pass'] ?? ''));
            if (!@ftp_login($connection, $user, $password)) return self::error(self::DIRECTORY, __('Авторизация FTP отклонена.', 'wp-olympiad'));
            @ftp_pasv($connection, true);
            @ftp_set_option($connection, FTP_TIMEOUT_SEC, 15);
            $base_path = rawurldecode((string)($parsed['path'] ?? '/'));
            if (!@ftp_chdir($connection, $base_path === '' ? '/' : $base_path)) return self::error(self::DIRECTORY, __('Корень FTP недоступен.', 'wp-olympiad'));
            $base = @ftp_pwd($connection);
            if ($base === false) return self::error(self::DIRECTORY, __('FTP не подтвердил корень.', 'wp-olympiad'));
            $root_entries = self::ftp_entries($connection, '.');
            if ($root_entries === null) return self::error(self::DIRECTORY, __('FTP не подтвердил типы файлов (MLSD).', 'wp-olympiad'));
            if (!array_key_exists(self::DIRECTORY, $root_entries)) {
                return self::result([], array_map(static fn($name) => self::DIRECTORY . '/' . $name, $names), 'absent');
            }
            if ($root_entries[self::DIRECTORY] !== 'dir' || self::ftp_listed_kind($connection, self::DIRECTORY) !== 'dir' || !@ftp_chdir($connection, self::DIRECTORY)) {
                return self::error(self::DIRECTORY, __('FTP не подтвердил обычный каталог без ссылки.', 'wp-olympiad'));
            }
            $entries = self::ftp_entries($connection, '.');
            if ($entries === null) return self::error(self::DIRECTORY, __('FTP не подтвердил типы файлов (MLSD).', 'wp-olympiad'));
            foreach ($names as $name) {
                if (isset($entries[$name]) && ($entries[$name] !== 'file' || self::ftp_listed_kind($connection, $name) !== 'file')) {
                    return self::error(self::DIRECTORY . '/' . $name, __('Это не подтверждённый обычный файл; ссылки и каталоги не удаляются.', 'wp-olympiad'));
                }
            }
            $removed = [];
            $absent = [];
            foreach ($names as $name) {
                $rel = self::DIRECTORY . '/' . $name;
                if (!isset($entries[$name])) {
                    $absent[] = $rel;
                    continue;
                }
                if (!@ftp_delete($connection, $name)) {
                    $fresh = self::ftp_entries($connection, '.');
                    if (is_array($fresh) && !isset($fresh[$name])) {
                        $absent[] = $rel;
                        continue;
                    }
                    return self::error($rel, __('FTP запретил удаление.', 'wp-olympiad'));
                }
                $removed[] = $rel;
            }
            $remaining = self::ftp_entries($connection, '.');
            if ($remaining === null || !@ftp_chdir($connection, $base)) return self::error(self::DIRECTORY, __('FTP не подтвердил содержимое каталога.', 'wp-olympiad'));
            if ($remaining) return self::result($removed, $absent, 'not_empty');
            if (@ftp_rmdir($connection, self::DIRECTORY)) return self::result($removed, $absent, 'removed');
            $fresh_root = self::ftp_entries($connection, '.');
            if (is_array($fresh_root) && !isset($fresh_root[self::DIRECTORY])) return self::result($removed, $absent, 'absent');
            if (is_array($fresh_root) && ($fresh_root[self::DIRECTORY] ?? '') === 'dir' && @ftp_chdir($connection, self::DIRECTORY)) {
                $fresh = self::ftp_entries($connection, '.');
                if (is_array($fresh) && $fresh) return self::result($removed, $absent, 'not_empty');
            }
            return self::error(self::DIRECTORY, __('FTP не удалил пустой каталог.', 'wp-olympiad'));
        } finally {
            @ftp_close($connection);
        }
    }

    private static function smb(array $config, array $names): array
    {
        $parsed = NetworkExport::parse_smb_path((string)($config['path'] ?? ''));
        $binary = NetworkExport::get_smbclient_binary();
        if (!$parsed || !$binary || !function_exists('exec')) return self::error(self::DIRECTORY, __('SMB требует корректный путь и smbclient.', 'wp-olympiad'));
        $user = ($config['user'] ?? '') ?: $parsed['user'];
        $password = ($config['password'] ?? '') ?: $parsed['password'];
        if (strpbrk($user . $password, "\r\n") !== false) return self::error(self::DIRECTORY, __('Некорректные реквизиты SMB.', 'wp-olympiad'));
        $authfile = null;
        $auth = '-N';
        try {
            if ($user !== '' || $password !== '') {
                $authfile = tempnam(sys_get_temp_dir(), 'wpo_report_smb_');
                if ($authfile === false) return self::error(self::DIRECTORY, __('Не удалось подготовить SMB-авторизацию.', 'wp-olympiad'));
                @chmod($authfile, 0600);
                $domain = '';
                if (str_contains($user, '\\')) [$domain, $user] = explode('\\', $user, 2);
                elseif (str_contains($user, '/')) [$domain, $user] = explode('/', $user, 2);
                if (@file_put_contents($authfile, "username = {$user}\npassword = {$password}\n" . ($domain !== '' ? "domain = {$domain}\n" : '')) === false) return self::error(self::DIRECTORY, __('Ошибка файла реквизитов SMB.', 'wp-olympiad'));
                $auth = '-A ' . escapeshellarg($authfile);
            }
            $base = escapeshellarg($binary) . ' ' . escapeshellarg($parsed['service']) . ' ' . $auth . ' -t 12';
            if ($parsed['subdir'] !== '') $base .= ' -D ' . escapeshellarg(str_replace('/', '\\', $parsed['subdir']));
            if (is_executable('/usr/bin/timeout')) $base = '/usr/bin/timeout 25 ' . $base;
            $run = static function (string $command) use ($base): array {
                $output = []; $exit = 1;
                exec($base . ' -c ' . escapeshellarg($command) . ' 2>&1', $output, $exit);
                $text = implode("\n", $output);
                preg_match_all('/NT_STATUS_[A-Z0-9_]+/', $text, $matches);
                return [$exit, array_values(array_unique($matches[0])), $text];
            };
            $missing = ['NT_STATUS_NO_SUCH_FILE', 'NT_STATUS_OBJECT_NAME_NOT_FOUND', 'NT_STATUS_OBJECT_PATH_NOT_FOUND'];
            [$code, $errors] = $run('readlink "' . self::DIRECTORY . '"');
            if ($errors && !array_diff($errors, $missing)) {
                return self::result([], array_map(static fn($name) => self::DIRECTORY . '/' . $name, $names), 'absent');
            }
            if ($code === 0 || $errors !== ['NT_STATUS_NOT_A_REPARSE_POINT']) {
                return self::error(self::DIRECTORY, __('SMB не подтвердил каталог без ссылки.', 'wp-olympiad'));
            }
            $present = [];
            $absent = [];
            // Preflight the exact targets: reject links and directories, not just wildcards.
            foreach ($names as $name) {
                $rel = self::DIRECTORY . '/' . $name;
                $remote = self::DIRECTORY . '\\' . $name;
                [$code, $errors] = $run('readlink "' . $remote . '"');
                if ($errors && !array_diff($errors, $missing)) {
                    $absent[] = $rel;
                    continue;
                }
                if ($code === 0 || $errors !== ['NT_STATUS_NOT_A_REPARSE_POINT']) {
                    return self::error($rel, __('SMB не подтвердил файл без ссылки.', 'wp-olympiad'));
                }
                [, , $info] = $run('allinfo "' . $remote . '"');
                if (!preg_match('/^attributes:\s*[^\r\n]*\(([0-9a-f]+)\)/mi', $info, $flags) || (hexdec($flags[1]) & 0x410) !== 0) {
                    return self::error($rel, __('SMB не подтвердил обычный файл.', 'wp-olympiad'));
                }
                $present[] = $name;
            }
            $removed = [];
            foreach ($present as $index => $name) {
                [$dir_code, $dir_errors] = $run('readlink "' . self::DIRECTORY . '"');
                if ($dir_errors && !array_diff($dir_errors, $missing)) {
                    foreach (array_slice($present, $index) as $remaining) $absent[] = self::DIRECTORY . '/' . $remaining;
                    return self::result($removed, $absent, 'absent');
                }
                if ($dir_code === 0 || $dir_errors !== ['NT_STATUS_NOT_A_REPARSE_POINT']) {
                    return self::error(self::DIRECTORY, __('Папка отчётов изменилась во время очистки SMB.', 'wp-olympiad'));
                }
                $rel = self::DIRECTORY . '/' . $name;
                [$code, $errors] = $run('del "' . self::DIRECTORY . '\\' . $name . '"');
                if ($errors && !array_diff($errors, $missing)) {
                    $absent[] = $rel;
                } elseif ($code === 0 && !$errors) {
                    $removed[] = $rel;
                } else {
                    return self::error($rel, __('SMB не подтвердил удаление файла.', 'wp-olympiad'));
                }
            }
            [$dir_code, $dir_errors] = $run('readlink "' . self::DIRECTORY . '"');
            if ($dir_errors && !array_diff($dir_errors, $missing)) return self::result($removed, $absent, 'absent');
            if ($dir_code === 0 || $dir_errors !== ['NT_STATUS_NOT_A_REPARSE_POINT']) {
                return self::error(self::DIRECTORY, __('Папка отчётов изменилась во время очистки SMB.', 'wp-olympiad'));
            }
            // SMB rmdir is non-recursive; a concurrent or hidden file prevents removal.
            [$code, $errors] = $run('rmdir "' . self::DIRECTORY . '"');
            if ($errors && !array_diff($errors, $missing)) return self::result($removed, $absent, 'absent');
            if ($errors && !array_diff($errors, ['NT_STATUS_DIRECTORY_NOT_EMPTY'])) return self::result($removed, $absent, 'not_empty');
            if ($code === 0 && !$errors) return self::result($removed, $absent, 'removed');
            return self::error(self::DIRECTORY, __('SMB не подтвердил удаление пустого каталога.', 'wp-olympiad'));
        } finally {
            if (is_string($authfile)) @unlink($authfile);
        }
    }
}
