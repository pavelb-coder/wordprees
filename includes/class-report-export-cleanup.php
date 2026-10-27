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

    /** SMB answers that mean "this exact path does not exist". */
    public const MISSING_STATUSES = [
        'NT_STATUS_NO_SUCH_FILE',
        'NT_STATUS_OBJECT_NAME_NOT_FOUND',
        'NT_STATUS_OBJECT_PATH_NOT_FOUND',
    ];

    /**
     * Parse the `attributes:` line that smbclient prints for `allinfo`. Null means the
     * server did not answer in a usable form.
     */
    public static function smb_attributes(string $text): ?int
    {
        if (preg_match('/attributes:\s*[^\r\n]*\(\s*(?:0x)?([0-9a-f]{1,8})\s*\)/i', $text, $flags)) {
            return (int) hexdec($flags[1]);
        }

        return null;
    }

    /**
     * Classify a directory probe. Only a positive "not a reparse point" answer counts as
     * verified; 'unsupported' means the server cannot tell, so nothing may be deleted.
     *
     * @return string absent|plain|link|unsupported
     */
    public static function judge_smb_directory(int $code, array $errors, string $text): string
    {
        $errors = array_values(array_unique($errors));
        if ($errors && !array_diff($errors, self::MISSING_STATUSES)) {
            return 'absent';
        }
        if ($code !== 0 && $errors === ['NT_STATUS_NOT_A_REPARSE_POINT']) {
            return 'plain';
        }
        if ($code === 0 && preg_match('/->\s*\S/', $text)) {
            return 'link';
        }

        return 'unsupported';
    }

    /**
     * Classify one legacy report file. Reparse and directory attribute bits always refuse
     * the target, and "plain" still requires an answered reparse query.
     *
     * @return string absent|plain|dangerous|unsupported
     */
    public static function judge_smb_target(int $code, array $errors, string $text, ?int $attributes = null): string
    {
        $errors = array_values(array_unique($errors));
        if ($errors && !array_diff($errors, self::MISSING_STATUSES)) {
            return 'absent';
        }
        // 0x400 = reparse point, 0x10 = directory: nothing may be removed through such a target.
        if ($attributes !== null && ($attributes & 0x410) !== 0) {
            return 'dangerous';
        }
        if ($code === 0 && preg_match('/->\s*\S/', $text)) {
            return 'dangerous';
        }
        if ($code !== 0 && $errors === ['NT_STATUS_NOT_A_REPARSE_POINT']) {
            return 'plain';
        }

        return 'unsupported';
    }

    /**
     * The transport could not prove that removal is safe. Nothing is deleted and the export
     * itself is not a failure: only the cosmetic retirement of the old reports is skipped.
     */
    private static function skipped(string $reason): array
    {
        return [
            'success'   => true,
            'removed'   => [],
            'absent'    => [],
            'directory' => 'unverified',
            'reason'    => $reason,
            'message'   => $reason,
        ];
    }


    /**
     * Run only after a successful full export. There is deliberately no arbitrary
     * path parameter, wildcard deletion, or recursive directory deletion.
     */
    public static function prune(array $config, Quiz $quiz): array
    {
        $slug = NetworkExport::build_safe_slug($quiz);
        if (!preg_match('/\A[a-zA-Z0-9_-]+\z/', $slug)) {
            return self::error(self::DIRECTORY, __('Недопустимое имя олимпиады.', 'wp-olympiad'), self::context());
        }
        return self::prune_names($config, self::context(), [
            'манифест_выгрузки_' . $slug . '.txt',
            'руководство_к_протоколам.txt',
            'сводка_результатов.txt',
        ]);
    }

    /**
     * Same safety rules for any export-owned subfolder: an exact list of file names,
     * never a wildcard, and the folder itself is removed only while it is empty.
     *
     * @param array{dir:string,label:string,phrase:string} $ctx wording context
     * @param array<int, string> $names base names inside the folder
     */
    public static function prune_names(array $config, array $ctx, array $names): array
    {
        $root = self::local_root($config);
        if ($root !== null) {
            return self::filesystem($root, $names, $ctx);
        }
        return match (NetworkExport::detect_type($config)) {
            'webdav' => self::webdav($config, $names, $ctx),
            'ftp' => self::ftp($config, $names, $ctx),
            'smb', 'filesystem' => self::smb($config, $names, $ctx),
            default => self::error($ctx['dir'], __('Неизвестный тип подключения.', 'wp-olympiad'), $ctx),
        };
    }

    /** Context of the report folder; other callers build their own. */
    public static function context(string $dir = self::DIRECTORY, string $label = 'отчётов', string $phrase = 'старые отчёты'): array
    {
        return ['dir' => $dir, 'label' => $label, 'phrase' => $phrase];
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

    private static function error(string $relative, string $reason, array $ctx): array
    {
        // Diagnostics contain relative names only, never network credentials.
        return ['success' => false, 'message' => sprintf(__('Не удалось убрать %1$s «%2$s»: %3$s', 'wp-olympiad'), $ctx['phrase'], $relative, $reason)];
    }

    private static function result(array $removed, array $absent, string $directory): array
    {
        return ['success' => true, 'removed' => $removed, 'absent' => $absent, 'directory' => $directory];
    }

    private static function filesystem(string $root, array $names, array $ctx): array
    {
        $dir = rtrim($root, '/\\') . '/' . $ctx['dir'];
        clearstatcache(true, $dir);
        if (is_link($dir)) {
            return self::error($ctx['dir'], sprintf(__('Папка %s является ссылкой; её содержимое не затрагивается.', 'wp-olympiad'), $ctx['label'], $ctx), $ctx);
        }
        if (!file_exists($dir)) {
            return self::result([], array_map(static fn($name) => $ctx['dir'] . '/' . $name, $names), 'absent');
        }
        if (!is_dir($dir)) {
            return self::error($ctx['dir'], __('Это не каталог.', 'wp-olympiad'), $ctx);
        }
        $base = realpath($root);
        $actual = realpath($dir);
        if ($actual === false) {
            clearstatcache(true, $dir);
            if (!file_exists($dir) && !is_link($dir)) {
                return self::result([], array_map(static fn($name) => $ctx['dir'] . '/' . $name, $names), 'absent');
            }
        }
        if ($base === false || $actual === false || !str_starts_with(wp_normalize_path($actual) . '/', rtrim(wp_normalize_path($base), '/') . '/')) {
            return self::error($ctx['dir'], __('Папка выходит за настроенный корень.', 'wp-olympiad'), $ctx);
        }

        // Preflight every exact target before deleting any of them.
        foreach ($names as $name) {
            $target = $dir . '/' . $name;
            clearstatcache(true, $target);
            if ((file_exists($target) || is_link($target)) && !is_file($target) && !is_link($target)) {
                return self::error($ctx['dir'] . '/' . $name, __('Это не обычный файл; каталоги не удаляются.', 'wp-olympiad'), $ctx);
            }
        }
        $removed = [];
        $absent = [];
        foreach ($names as $index => $name) {
            clearstatcache(true, $dir);
            if (!file_exists($dir) && !is_link($dir)) {
                foreach (array_slice($names, $index) as $remaining) $absent[] = $ctx['dir'] . '/' . $remaining;
                return self::result($removed, $absent, 'absent');
            }
            $current = realpath($dir);
            if ($current === false && !file_exists($dir) && !is_link($dir)) {
                foreach (array_slice($names, $index) as $remaining) $absent[] = $ctx['dir'] . '/' . $remaining;
                return self::result($removed, $absent, 'absent');
            }
            if (is_link($dir) || $current !== $actual) {
                return self::error($ctx['dir'], sprintf(__('Папка %s изменилась во время очистки.', 'wp-olympiad'), $ctx['label']), $ctx);
            }
            $rel = $ctx['dir'] . '/' . $name;
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
                return self::error($rel, __('Нет прав удаления или файл занят.', 'wp-olympiad'), $ctx);
            }
            $removed[] = $rel;
        }

        clearstatcache(true, $dir);
        if (!file_exists($dir) && !is_link($dir)) return self::result($removed, $absent, 'absent');
        if (is_link($dir) || realpath($dir) !== $actual) {
            return self::error($ctx['dir'], sprintf(__('Папка %s изменилась во время очистки.', 'wp-olympiad'), $ctx['label']), $ctx);
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
            return self::error($ctx['dir'], sprintf(__('Папка %s изменилась во время очистки.', 'wp-olympiad'), $ctx['label']), $ctx);
        }
        $children = @scandir($dir);
        if ($children === false) {
            clearstatcache(true, $dir);
            if (!file_exists($dir) && !is_link($dir)) return self::result($removed, $absent, 'absent');
            // The report files are already gone; an unreadable folder is only retained.
            return self::result($removed, $absent, 'retained');
        }
        if (array_diff($children, ['.', '..'])) {
            return self::result($removed, $absent, 'not_empty');
        }
        return self::result($removed, $absent, 'retained');
    }

    /** Read the strong entity tag from the response headers; null means "cannot be verified". */
    private static function dav_head_etag(string $url, array $headers): ?string
    {
        $response = wp_remote_request($url, [
            'method' => 'HEAD', 'headers' => $headers, 'timeout' => 15,
            'redirection' => 0, 'sslverify' => false,
        ]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        $etag = trim((string) wp_remote_retrieve_header($response, 'etag'));

        return preg_match('/\A"[^"\r\n]*"\z/', $etag) ? $etag : null;
    }

    private static function dav_url(string $base, string $relative): string
    {
        return rtrim($base, '/') . '/' . implode('/', array_map('rawurlencode', explode('/', $relative)));
    }

    /** Read a single Depth:0 WebDAV resource, refusing ambiguous or unsafe XML. */
    private static function dav_info(string $url, array $headers, string $relative, array $ctx): array
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
            return self::error($relative, sprintf(__('Не удалось безопасно проверить WebDAV (HTTP %d).', 'wp-olympiad'), $code, $ctx), $ctx);
        }
        $body = (string)wp_remote_retrieve_body($response);
        if ($body === '' || strlen($body) >= 1048576 || stripos($body, '<!DOCTYPE') !== false) {
            return self::error($relative, __('Недопустимый ответ WebDAV.', 'wp-olympiad'), $ctx);
        }
        $xml = new \DOMDocument();
        if (!@$xml->loadXML($body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            return self::error($relative, __('Некорректный XML WebDAV.', 'wp-olympiad'), $ctx);
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
            return self::error($relative, __('WebDAV не подтвердил точный путь.', 'wp-olympiad'), $ctx);
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
            return self::error($relative, __('WebDAV не подтвердил тип объекта.', 'wp-olympiad'), $ctx);
        }
        return ['success' => true, 'exists' => true, 'type' => $type, 'etag' => $etag];
    }

    private static function webdav(array $config, array $names, array $ctx): array
    {
        $base = (string)$config['path'];
        $headers = [];
        if (!empty($config['user']) || !empty($config['password'])) {
            $headers['Authorization'] = 'Basic ' . base64_encode(($config['user'] ?? '') . ':' . ($config['password'] ?? ''));
        }
        $directory = self::dav_info(self::dav_url($base, $ctx['dir']), $headers, $ctx['dir'], $ctx);
        if (!$directory['success']) return self::skipped($directory['message']);
        if (!$directory['exists']) {
            return self::result([], array_map(static fn($name) => $ctx['dir'] . '/' . $name, $names), 'absent');
        }
        if ($directory['type'] !== 'collection') {
            return self::error($ctx['dir'], __('WebDAV-объект не является каталогом.', 'wp-olympiad'), $ctx);
        }
        $existing = [];
        $absent = [];
        foreach ($names as $name) {
            $rel = $ctx['dir'] . '/' . $name;
            $url = self::dav_url($base, $rel);
            $info = self::dav_info($url, $headers, $rel, $ctx);
            if (!$info['success']) return self::skipped($info['message']);
            if (!$info['exists']) {
                $absent[] = $rel;
                continue;
            }
            if ($info['type'] !== 'file') {
                return self::error($rel, __('Это каталог; его содержимое не удаляется.', 'wp-olympiad'), $ctx);
            }
            // A conditional DELETE prevents removing a replacement resource.
            // A conditional DELETE prevents removing a replacement resource. Some servers
            // expose the entity tag in the response headers only, so a HEAD probe is tried
            // before giving up; without a strong ETag the file simply stays where it is.
            $etag = (string) $info['etag'];
            if (!preg_match('/\A"[^"\r\n]*"\z/', $etag)) {
                $etag = (string) (self::dav_head_etag($url, $headers) ?? '');
                if ($etag === '') {
                    return self::skipped(sprintf(__('WebDAV не предоставил надёжный ETag файла «%s».', 'wp-olympiad'), $name));
                }
            }
            $existing[] = ['relative' => $rel, 'url' => $url, 'etag' => $etag];
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
                return self::error($file['relative'], 'WebDAV HTTP ' . $code, $ctx);
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
        return match ($matches[0][0] ?? '') { 'd' => 'dir', '-' => 'file', 'l' => 'link', default => null };
    }

    private static function ftp(array $config, array $names, array $ctx): array
    {
        if (!function_exists('ftp_connect')) return self::error($ctx['dir'], __('В PHP отсутствует FTP.', 'wp-olympiad'), $ctx);
        $parsed = parse_url((string)$config['path']);
        if (!$parsed || empty($parsed['host'])) return self::error($ctx['dir'], __('Некорректный FTP URL.', 'wp-olympiad'), $ctx);
        $tls = strtolower((string)($parsed['scheme'] ?? '')) === 'ftps';
        if ($tls && !function_exists('ftp_ssl_connect')) return self::error($ctx['dir'], __('FTPS не поддерживается сервером.', 'wp-olympiad'), $ctx);
        $connection = $tls ? @ftp_ssl_connect($parsed['host'], (int)($parsed['port'] ?? 21), 15) : @ftp_connect($parsed['host'], (int)($parsed['port'] ?? 21), 15);
        if (!$connection) return self::error($ctx['dir'], __('Нет соединения с FTP.', 'wp-olympiad'), $ctx);
        try {
            $user = ($config['user'] ?? '') ?: rawurldecode((string)($parsed['user'] ?? 'anonymous'));
            $password = ($config['password'] ?? '') ?: rawurldecode((string)($parsed['pass'] ?? ''));
            if (!@ftp_login($connection, $user, $password)) return self::error($ctx['dir'], __('Авторизация FTP отклонена.', 'wp-olympiad'), $ctx);
            @ftp_pasv($connection, true);
            @ftp_set_option($connection, FTP_TIMEOUT_SEC, 15);
            $base_path = rawurldecode((string)($parsed['path'] ?? '/'));
            if (!@ftp_chdir($connection, $base_path === '' ? '/' : $base_path)) return self::error($ctx['dir'], __('Корень FTP недоступен.', 'wp-olympiad'), $ctx);
            $base = @ftp_pwd($connection);
            if ($base === false) return self::error($ctx['dir'], __('FTP не подтвердил корень.', 'wp-olympiad'), $ctx);
            $root_entries = self::ftp_entries($connection, '.');
            if ($root_entries === null) return self::skipped(sprintf(__('FTP не отвечает на запрос типов файлов (MLSD); папка «%s/» не изменена.', 'wp-olympiad'), $ctx['dir']));
            if (!array_key_exists($ctx['dir'], $root_entries)) {
                return self::result([], array_map(static fn($name) => $ctx['dir'] . '/' . $name, $names), 'absent');
            }
            $listed_kind = self::ftp_listed_kind($connection, $ctx['dir']);
            if (in_array($listed_kind, ['file', 'link'], true)) {
                return self::error($ctx['dir'], sprintf(__('Папка %s является ссылкой или файлом; её содержимое не затрагивается.', 'wp-olympiad'), $ctx['label'], $ctx), $ctx);
            }
            if ($root_entries[$ctx['dir']] !== 'dir' || !@ftp_chdir($connection, $ctx['dir'])) {
                return self::error($ctx['dir'], __('FTP не подтвердил обычный каталог без ссылки.', 'wp-olympiad'), $ctx);
            }
            if ($listed_kind !== 'dir') {
                return self::skipped(sprintf(__('LIST не подтвердил тип папки «%s»; она не изменена.', 'wp-olympiad'), $ctx['dir']));
            }
            $entries = self::ftp_entries($connection, '.');
            if ($entries === null) return self::skipped(sprintf(__('FTP не отвечает на запрос типов файлов (MLSD); папка «%s/» не изменена.', 'wp-olympiad'), $ctx['dir']));
            foreach ($names as $name) {
                if (!isset($entries[$name])) {
                    continue;
                }
                if ($entries[$name] !== 'file') {
                    return self::error($ctx['dir'] . '/' . $name, __('Это не обычный файл; ссылки и каталоги не удаляются.', 'wp-olympiad'), $ctx);
                }
                $kind = self::ftp_listed_kind($connection, $name);
                if ($kind === null) {
                    return self::skipped(sprintf(__('LIST не подтвердил тип файла «%1$s»; папка «%2$s/» не изменена.', 'wp-olympiad'), $name, $ctx['dir']));
                }
                if ($kind !== 'file') {
                    return self::error($ctx['dir'] . '/' . $name, __('Это не обычный файл; ссылки и каталоги не удаляются.', 'wp-olympiad'), $ctx);
                }
            }
            $removed = [];
            $absent = [];
            foreach ($names as $name) {
                $rel = $ctx['dir'] . '/' . $name;
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
                    return self::error($rel, __('FTP запретил удаление.', 'wp-olympiad'), $ctx);
                }
                $removed[] = $rel;
            }
            $remaining = self::ftp_entries($connection, '.');
            if ($remaining === null || !@ftp_chdir($connection, $base)) return self::result($removed, $absent, 'retained');
            if ($remaining) return self::result($removed, $absent, 'not_empty');
            if (@ftp_rmdir($connection, $ctx['dir'])) return self::result($removed, $absent, 'removed');
            $fresh_root = self::ftp_entries($connection, '.');
            if (is_array($fresh_root) && !isset($fresh_root[$ctx['dir']])) return self::result($removed, $absent, 'absent');
            if (is_array($fresh_root) && ($fresh_root[$ctx['dir']] ?? '') === 'dir' && @ftp_chdir($connection, $ctx['dir'])) {
                $fresh = self::ftp_entries($connection, '.');
                if (is_array($fresh) && $fresh) return self::result($removed, $absent, 'not_empty');
            }
            return self::result($removed, $absent, 'retained');
        } finally {
            @ftp_close($connection);
        }
    }

    private static function smb(array $config, array $names, array $ctx): array
    {
        $parsed = NetworkExport::parse_smb_path((string)($config['path'] ?? ''));
        $binary = NetworkExport::get_smbclient_binary();
        if (!$parsed || !$binary || !function_exists('exec')) return self::error($ctx['dir'], __('SMB требует корректный путь и smbclient.', 'wp-olympiad'), $ctx);
        $user = ($config['user'] ?? '') ?: $parsed['user'];
        $password = ($config['password'] ?? '') ?: $parsed['password'];
        if (strpbrk($user . $password, "\r\n") !== false) return self::error($ctx['dir'], __('Некорректные реквизиты SMB.', 'wp-olympiad'), $ctx);
        $authfile = null;
        $auth = '-N';
        try {
            if ($user !== '' || $password !== '') {
                $authfile = tempnam(sys_get_temp_dir(), 'wpo_report_smb_');
                if ($authfile === false) return self::error($ctx['dir'], __('Не удалось подготовить SMB-авторизацию.', 'wp-olympiad'), $ctx);
                @chmod($authfile, 0600);
                $domain = '';
                if (str_contains($user, '\\')) [$domain, $user] = explode('\\', $user, 2);
                elseif (str_contains($user, '/')) [$domain, $user] = explode('/', $user, 2);
                if (@file_put_contents($authfile, "username = {$user}\npassword = {$password}\n" . ($domain !== '' ? "domain = {$domain}\n" : '')) === false) return self::error($ctx['dir'], __('Ошибка файла реквизитов SMB.', 'wp-olympiad'), $ctx);
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
            $missing = self::MISSING_STATUSES;
            [$code, $errors, $text] = $run('readlink "' . $ctx['dir'] . '"');
            $state = self::judge_smb_directory($code, $errors, $text);
            if ($state === 'absent') {
                return self::result([], array_map(static fn($name) => $ctx['dir'] . '/' . $name, $names), 'absent');
            }
            if ($state === 'link') {
                return self::error($ctx['dir'], sprintf(__('Папка %s является ссылкой; её содержимое не затрагивается.', 'wp-olympiad'), $ctx['label'], $ctx), $ctx);
            }
            if ($state === 'unsupported') {
                return self::skipped(sprintf(__('SMB не отвечает на проверку каталога на ссылку; папка «%s/» не изменена.', 'wp-olympiad'), $ctx['dir']));
            }
            $present = [];
            $absent = [];
            // Preflight the exact targets: reject links and directories, not just wildcards.
            foreach ($names as $name) {
                $rel = $ctx['dir'] . '/' . $name;
                $remote = $ctx['dir'] . '\\' . $name;
                [$code, $errors, $text] = $run('readlink "' . $remote . '"');
                if ($errors && !array_diff($errors, $missing)) {
                    $absent[] = $rel;
                    continue;
                }
                [, , $info] = $run('allinfo "' . $remote . '"');
                $state = self::judge_smb_target($code, $errors, $text, self::smb_attributes($info));
                if ($state === 'dangerous') {
                    return self::error($rel, __('SMB не подтвердил обычный файл: это ссылка или каталог.', 'wp-olympiad'), $ctx);
                }
                if ($state === 'unsupported') {
                    return self::skipped(sprintf(__('SMB не подтвердил тип файла «%1$s»; папка «%2$s/» не изменена.', 'wp-olympiad'), $name, $ctx['dir']));
                }
                $present[] = $name;
            }
            $removed = [];
            foreach ($present as $index => $name) {
            [$dir_code, $dir_errors, $dir_text] = $run('readlink "' . $ctx['dir'] . '"');
            $dir_state = self::judge_smb_directory($dir_code, $dir_errors, $dir_text);
            if ($dir_state === 'absent') {
                foreach (array_slice($present, $index) as $remaining) $absent[] = $ctx['dir'] . '/' . $remaining;
                return self::result($removed, $absent, 'absent');
            }
            if ($dir_state !== 'plain') {
                return self::error($ctx['dir'], sprintf(__('Папка %s изменилась во время очистки SMB.', 'wp-olympiad'), $ctx['label']), $ctx);
            }
                $rel = $ctx['dir'] . '/' . $name;
                [$code, $errors] = $run('del "' . $ctx['dir'] . '\\' . $name . '"');
                if ($errors && !array_diff($errors, $missing)) {
                    $absent[] = $rel;
                } elseif ($code === 0 && !$errors) {
                    $removed[] = $rel;
                } else {
                    return self::error($rel, __('SMB не подтвердил удаление файла.', 'wp-olympiad'), $ctx);
                }
            }
            [$dir_code, $dir_errors, $dir_text] = $run('readlink "' . $ctx['dir'] . '"');
            $dir_state = self::judge_smb_directory($dir_code, $dir_errors, $dir_text);
            if ($dir_state === 'absent') return self::result($removed, $absent, 'absent');
            if ($dir_state !== 'plain') {
                return self::error($ctx['dir'], sprintf(__('Папка %s изменилась во время очистки SMB.', 'wp-olympiad'), $ctx['label']), $ctx);
            }
            // SMB rmdir is non-recursive; a concurrent or hidden file prevents removal.
            [$code, $errors] = $run('rmdir "' . $ctx['dir'] . '"');
            if ($errors && !array_diff($errors, $missing)) return self::result($removed, $absent, 'absent');
            if ($errors && !array_diff($errors, ['NT_STATUS_DIRECTORY_NOT_EMPTY'])) return self::result($removed, $absent, 'not_empty');
            if ($code === 0 && !$errors) return self::result($removed, $absent, 'removed');
            // The report files are already gone; an empty folder that survives is only retained.
            return self::result($removed, $absent, 'retained');
        } finally {
            if (is_string($authfile)) @unlink($authfile);
        }
    }
}
