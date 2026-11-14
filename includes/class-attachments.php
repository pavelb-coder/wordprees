<?php
/**
 * 1.0.32: вложения участников — тип задания «Прикрепить файл».
 *
 * Файлы кладутся в отдельную подпапку сетевой папки олимпиады:
 * «вложения/<slug олимпиады>/<bucket>/<случайное имя>.<расширение>».
 *
 * Если к сетевой папке нет прямого доступа из PHP (SMB без монтирования,
 * WebDAV, FTP), файлы лежат в закрытой рабочей папке сайта и копируются в
 * сетевую папку при сдаче работы и при выгрузке протоколов — тем же
 * транспортом, что и протоколы (см. store()).
 *
 * Наружу файлы отдаются только через PHP после проверки прав, поэтому прямая
 * ссылка ничего не даёт: имя файла непредсказуемо, а каталог закрыт
 * .htaccess и index.php на случай, если сетевая папка лежит внутри uploads.
 *
 * В ответе участника хранится не содержимое, а описание: имя, размер, MIME,
 * SHA-256 и относительный путь. Каталог «вложения» переживает перенос на другой
 * диск, потому что пути в ответах относительные.
 */

namespace WPOlympiad;

defined('ABSPATH') || exit;

class Attachments
{
    /** Код типа задания. */
    public const TYPE = 'file';

    /** Имя отдельной подпапки в сетевой папке олимпиады. */
    public const DIR = 'вложения';

    /** Закрытая рабочая папка на сервере (для сетевых транспортов без пути). */
    public const SPOOL = 'wpo-attachments';

    public const DEFAULT_MAX_SIZE_MB = 10;
    public const MAX_SIZE_MB_LIMIT = 100;
    public const DEFAULT_MAX_COUNT = 3;
    public const MAX_COUNT_LIMIT = 20;

    /** Жёсткий потолок суммарного веса одной попытки — поверх настроек олимпиады. */
    public const ATTEMPT_TOTAL_LIMIT = 104857600;

    /**
     * Виды файлов, которые организатор может разрешить в своей олимпиаде.
     * Принимается только совпадение и расширения, и сигнатуры содержимого.
     */
    public const KINDS = [
        'document' => [
            'label' => 'Документы (PDF, DOCX, DOC, ODT, RTF)',
            'exts'  => ['pdf', 'docx', 'doc', 'odt', 'rtf'],
            'mimes' => [
                'application/pdf',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/msword',
                'application/vnd.oasis.opendocument.text',
                'application/rtf',
                'text/rtf',
            ],
        ],
        'text' => [
            'label' => 'Текст (TXT, MD, CSV)',
            'exts'  => ['txt', 'md', 'csv'],
            'mimes' => ['text/plain', 'text/markdown', 'text/csv'],
        ],
        'image' => [
            'label' => 'Изображения (PNG, JPEG, WebP, GIF)',
            'exts'  => ['png', 'jpg', 'jpeg', 'webp', 'gif'],
            'mimes' => ['image/png', 'image/jpeg', 'image/webp', 'image/gif'],
        ],
        'sheet' => [
            'label' => 'Таблицы (XLSX, XLS, ODS)',
            'exts'  => ['xlsx', 'xls', 'ods'],
            'mimes' => [
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-excel',
                'application/vnd.oasis.opendocument.spreadsheet',
            ],
        ],
        'presentation' => [
            'label' => 'Презентации (PPTX, ODP)',
            'exts'  => ['pptx', 'odp', 'ppt'],
            'mimes' => [
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'application/vnd.oasis.opendocument.presentation',
                'application/vnd.ms-powerpoint',
            ],
        ],
        'archive' => [
            'label' => 'Архивы (ZIP, 7z, RAR) — включать только при необходимости',
            'exts'  => ['zip', '7z', 'rar'],
            'mimes' => ['application/zip', 'application/x-7z-compressed', 'application/vnd.rar', 'application/x-rar-compressed'],
        ],
    ];

    /**
     * Не принимается ни при каких настройках: исполняемое, скрипты и разметка,
     * которую браузер мог бы отрисовать как страницу.
     */
    public const NEVER_ALLOW_EXT = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phps', 'phar', 'php-tmpl',
        'pl', 'pm', 'py', 'pyc', 'rb', 'js', 'mjs', 'cjs', 'jsx', 'ts', 'tsx',
        'html', 'htm', 'xhtml', 'shtml', 'svg', 'vm', 'vbs', 'vbe', 'jsb', 'jse', 'wsf', 'wsh',
        'exe', 'com', 'bat', 'cmd', 'msi', 'msp', 'scr', 'dll', 'so', 'dylib', 'jar', 'war',
        'sh', 'bash', 'zsh', 'csh', 'ksh', 'fish', 'ps1', 'psm1', 'awk', 'lua',
        'c', 'cc', 'cpp', 'h', 'hpp', 'go', 'rs', 'java', 'class', 'jsp', 'jspx', 'asp', 'aspx',
        'apk', 'aab', 'deb', 'rpm', 'dmg', 'pkg', 'command', 'workflow', 'action',
        'htaccess', 'htpasswd', 'lnk', 'url', 'iso', 'img', 'vhd', 'vmdk',
    ];

    /** Сигнатуры содержимого: расширение => [[смещение, [допустимые префиксы]], …]. */
    private const SIGNATURES = [
        'pdf'  => [[0, ['%PDF-']]],
        'png'  => [[0, ["\x89PNG\r\n\x1a\n"]]],
        'jpg'  => [[0, ["\xFF\xD8\xFF"]]],
        'jpeg' => [[0, ["\xFF\xD8\xFF"]]],
        'gif'  => [[0, ['GIF87a', 'GIF89a']]],
        'webp' => [[0, ['RIFF']], [8, ['WEBP']]],
        'zip'  => [[0, ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"]]],
        'docx' => [[0, ["PK\x03\x04"]]],
        'xlsx' => [[0, ["PK\x03\x04"]]],
        'pptx' => [[0, ["PK\x03\x04"]]],
        'odt'  => [[0, ["PK\x03\x04"]]],
        'ods'  => [[0, ["PK\x03\x04"]]],
        'odp'  => [[0, ["PK\x03\x04"]]],
        '7z'   => [[0, ["7z\xBC\xAF\x27\x1C"]]],
        'rar'  => [[0, ["Rar!\x1A\x07"]]],
        'doc'  => [[0, ["\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"]]],
        'xls'  => [[0, ["\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"]]],
        'ppt'  => [[0, ["\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"]]],
        'rtf'  => [[0, ['{\\rtf']]],
    ];

    /** Текстовые виды: содержимое не должно походить на скрипт или разметку. */
    private const TEXT_EXTS = ['txt', 'md', 'csv'];

    // ------------------------------------------------------------------ настройки

    /**
     * Единственная точка, где лимиты вложений приводятся к виду, пригодному для
     * хранения. Вызывается из Quiz::normalize_custom_settings(), поэтому форма
     * админки, сетевой импорт и прямое обновление настроек не разойдутся.
     *
     * @return array{attachment_max_size_mb: int, attachment_max_count: int, attachment_kinds: array<int,string>}
     */
    public static function normalize(array $settings): array
    {
        $mb = (int)($settings['attachment_max_size_mb'] ?? self::DEFAULT_MAX_SIZE_MB);
        if ($mb <= 0) {
            $mb = self::DEFAULT_MAX_SIZE_MB;
        }
        $mb = min(self::MAX_SIZE_MB_LIMIT, $mb);

        $count = (int)($settings['attachment_max_count'] ?? self::DEFAULT_MAX_COUNT);
        if ($count <= 0) {
            $count = self::DEFAULT_MAX_COUNT;
        }
        $count = min(self::MAX_COUNT_LIMIT, $count);

        $allowed = array_keys(self::KINDS);
        $raw_kinds = $settings['attachment_kinds'] ?? self::default_kinds();
        $kinds = [];
        foreach ((array)$raw_kinds as $kind) {
            $kind = strtolower(sanitize_key((string)$kind));
            if (in_array($kind, $allowed, true) && !in_array($kind, $kinds, true)) {
                $kinds[] = $kind;
            }
        }
        if ($kinds === []) {
            $kinds = self::default_kinds();
        }

        return [
            'attachment_max_size_mb' => $mb,
            'attachment_max_count'   => $count,
            'attachment_kinds'       => $kinds,
        ];
    }

    /** Виды по умолчанию: документы, текст и изображения. Архивы — только вручную. */
    public static function default_kinds(): array
    {
        return ['document', 'text', 'image'];
    }

    /** Настройки этой олимпиады, готовые к проверке и выводу. */
    public static function limits(?Quiz $quiz): array
    {
        $limits = self::normalize([
            'attachment_max_size_mb' => $quiz ? $quiz->get_setting('attachment_max_size_mb', self::DEFAULT_MAX_SIZE_MB) : self::DEFAULT_MAX_SIZE_MB,
            'attachment_max_count'   => $quiz ? $quiz->get_setting('attachment_max_count', self::DEFAULT_MAX_COUNT) : self::DEFAULT_MAX_COUNT,
            'attachment_kinds'       => $quiz ? $quiz->get_setting('attachment_kinds', self::default_kinds()) : self::default_kinds(),
        ]);

        $exts = [];
        $mimes = [];
        foreach ($limits['attachment_kinds'] as $kind) {
            foreach (self::KINDS[$kind]['exts'] as $ext) {
                if (!in_array($ext, $exts, true)) {
                    $exts[] = $ext;
                }
            }
            foreach (self::KINDS[$kind]['mimes'] as $mime) {
                if (!in_array($mime, $mimes, true)) {
                    $mimes[] = $mime;
                }
            }
        }

        $limits['max_bytes'] = $limits['attachment_max_size_mb'] * 1048576;
        $limits['exts'] = $exts;
        $limits['mimes'] = $mimes;
        // WordPress умеет отрезать раньше нас — считаемся и с его лимитом.
        $wp_limit = function_exists('wp_max_upload_size') ? (int)wp_max_upload_size() : 0;
        $limits['effective_bytes'] = $wp_limit > 0 ? min($limits['max_bytes'], $wp_limit) : $limits['max_bytes'];

        return $limits;
    }

    /** Человекочитаемое перечисление ограничений для подсказки участнику. */
    public static function describe_limits(array $limits): string
    {
        $exts = array_map(static fn(string $e) => '.' . $e, $limits['exts']);

        return sprintf(
            /* translators: 1: count, 2: size in MB, 3: extensions */
            __('До %1$d файлов, каждый не больше %2$d МБ. Разрешённые расширения: %3$s.', 'wp-olympiad'),
            (int)$limits['attachment_max_count'],
            (int)round($limits['max_bytes'] / 1048576),
            implode(', ', $exts)
        );
    }

    // ------------------------------------------------------------------ размещение

    /** @var array<int, array<string, mixed>> решение о месте хранения на время запроса */
    private static array $store_cache = [];

    /** Сброс кеша — нужен тестам и после изменения настроек сетевой папки. */
    public static function reset_store_cache(?int $quiz_id = null): void
    {
        if ($quiz_id === null) {
            self::$store_cache = [];
        } else {
            unset(self::$store_cache[$quiz_id]);
        }
    }

    /**
     * Где физически живут файлы этой олимпиады.
     *
     * - «share» — путь сетевой папки доступен PHP как обычный путь (смонтирован,
     *   либо это Windows и UNC-путь): вложения пишутся прямо в сетевую папку.
     * - «spool» — доступа путём нет (SMB без монтирования, WebDAV, FTP): файлы
     *   лежат в закрытой папке сайта, а в сетевую папку копируются при сдаче
     *   работы и при выгрузке протоколов — тем же транспортом, что и протоколы.
     *
     * @return array{mode: string, type: string, root: string, share: string, spool: string, slug: string, config: array, note: string}
     */
    public static function store(Quiz $quiz): array
    {
        $quiz_id = (int)$quiz->id;
        if (isset(self::$store_cache[$quiz_id])) {
            return self::$store_cache[$quiz_id];
        }

        $config = NetworkExport::get_quiz_network_config($quiz);
        $type   = NetworkExport::detect_type($config);
        $slug   = NetworkExport::build_safe_slug($quiz);
        $share  = self::share_path($config, $slug);
        $spool  = rtrim(wp_normalize_path(WP_CONTENT_DIR), '/') . '/' . self::SPOOL . '/' . $slug;

        $mode = 'share';
        $note = '';
        if ($share === '' || !self::writable_dir($share)) {
            $mode = 'spool';
            $note = $share === ''
                ? sprintf(
                    /* translators: 1: transport */
                    __('Сетевая папка недоступна PHP как путь (%s): вложения собираются в рабочей папке сайта и копируются в сетевую папку при сдаче.', 'wp-olympiad'),
                    $type
                )
                : sprintf(
                    /* translators: 1: путь, 2: транспорт */
                    __('Нет доступа на запись к «%1$s» (транспорт %2$s): вложения собираются в рабочей папке сайта и копируются в сетевую папку при сдаче.', 'wp-olympiad'),
                    $share,
                    $type
                );
        }

        // Ручная переопределение режима: «share» требует, чтобы путь всё-таки был
        // доступен; «spool» запрещает писать в сетевую папку вообще.
        $forced = apply_filters('wpo_attachments_store', '', $quiz, $type);
        if ($forced === 'spool') {
            $mode = 'spool';
        } elseif ($forced === 'share' && $share !== '' && self::writable_dir($share)) {
            $mode = 'share';
            $note = '';
        }

        return self::$store_cache[$quiz_id] = [
            'mode'   => $mode,
            'type'   => $type,
            'root'   => $mode === 'share' ? $share : $spool,
            'share'  => $share,
            'spool'  => $spool,
            'slug'   => $slug,
            'config' => $config,
            'note'   => $note,
        ];
    }
    /**
     * Путь папки вложений внутри сетевой папки (или '' — если путём не добраться).
     *
     * Для локальной или уже смонтированной папки каталог создаётся, если его нет.
     * Для SMB/WebDAV/FTP корень создавать нельзя: его в файловой системе нет, и
     * попытка создать «папку» вида //server/share привела бы к мусору рядом с
     * плагином. Такой путь принимается только когда он уже существует.
     */
    private static function share_path(array $config, string $slug): string
    {
        $type = NetworkExport::detect_type($config);
        $raw = trim((string)($config['path'] ?? ''));
        $resolved = trim((string)NetworkExport::resolve_local_path($raw));
        if ($resolved === '' || self::looks_like_example($resolved)) {
            return '';
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            // Windows: UNC-путь \\server\share\… и путь на диске доступны PHP напрямую.
            $base = rtrim(str_replace('/', '\\', $resolved), '\\');
        } else {
            // Linux/macOS: только настоящий путь в ФС (смонтированная папка). UNC здесь
            // означал бы создание каталога с именем вида "\\server\share" рядом с плагином.
            $base = preg_match('#^/[^/]+#', $resolved) ? rtrim($resolved, '/') : '';
        }
        if ($base === '' || (!is_dir($base) && $type !== 'filesystem')) {
            return '';
        }

        $dir = $base . DIRECTORY_SEPARATOR . self::DIR . DIRECTORY_SEPARATOR . $slug;
        if (!is_dir($dir) && !@wp_mkdir_p($dir)) {
            return '';
        }

        return $dir;
    }

    /**
     * Путь-заглушка из документации («\\server\share\olympiad» и т. п.) организатор
     * мог оставить, не заполнив: создавать по такому каталоги нельзя.
     */
    private static function looks_like_example(string $path): bool
    {
        return preg_match('#^[\\\\/]{2}(server|host|share|storage|path)[\\\\/]#i', $path) === 1;
    }

    /** Каталога нет — создаём; возвращает true, только если в него можно писать. */
    private static function writable_dir(string $dir): bool
    {
        if ($dir === '') {
            return false;
        }
        if (!is_dir($dir) && !@wp_mkdir_p($dir)) {
            return false;
        }

        return is_dir($dir) && is_writable($dir);
    }

    /** Активный корень вложений: сетевая папка или рабочая папка сайта. */
    public static function root_dir(Quiz $quiz): string
    {
        return self::store($quiz)['root'];
    }

    /** «share» — пишем в сетевую папку; «spool» — в рабочую папку с копированием. */
    public static function mode(Quiz $quiz): string
    {
        return self::store($quiz)['mode'];
    }

    /** Куда вложения нужно доставить (путь внутри сетевой папки). */
    public static function share_root(Quiz $quiz): string
    {
        return self::store($quiz)['share'];
    }

    /**
     * Корень + защитные файлы. Возвращает путь; WP_Error — если писать некуда.
     */
    public static function ensure_root(Quiz $quiz): string|\WP_Error
    {
        $store = self::store($quiz);
        $root  = $store['root'];
        if (!self::writable_dir($root)) {
            return new \WP_Error(
                'wpo_attachment_dir',
                sprintf(
                    /* translators: %s: directory */
                    __('Не удалось создать рабочую папку вложений «%s». Проверьте права на запись в папку сайта и доступность сетевой папки.', 'wp-olympiad'),
                    $root
                ),
                ['status' => 507]
            );
        }

        self::guard_dir($root);

        // Если до этого доступ был только через транспорт, работы участников лежат в
        // рабочей папке. Переносим их в активный корень при первом же обращении —
        // иначе список файлов и просмотр попытки перестанут их видеть.
        if ($store['mode'] === 'share' && $store['spool'] !== '' && is_dir($store['spool'])) {
            self::migrate_spool($quiz);
        }

        return $root;
    }

    /** Закрытие прямого доступа к списку файлов (нужно, если каталог попал под uploads). */
    private static function guard_dir(string $dir): void
    {
        $htaccess = $dir . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
        }
        $index = $dir . '/index.php';
        if (!is_file($index)) {
            @file_put_contents($index, "<?php\n// Доступ к списку файлов закрыт.\n");
        }
    }

    /**
     * Имя подпапки попытки: необратимый хеш токена, чтобы по пути нельзя было
     * определить ни id попытки, ни участника.
     */
    public static function bucket(string $token): string
    {
        return substr(hash('sha256', trim($token)), 0, 16);
    }

    /** Каталог данных попытки (создаётся при первой загрузке). */
    public static function bucket_dir(Quiz $quiz, string $bucket): string|\WP_Error
    {
        $root = self::ensure_root($quiz);
        if (is_wp_error($root)) {
            return $root;
        }
        if (!preg_match('/^[a-f0-9]{16}$/', $bucket)) {
            return new \WP_Error('wpo_attachment_bucket', __('Некорректный идентификатор попытки.', 'wp-olympiad'), ['status' => 400]);
        }
        $dir = $root . '/' . $bucket;
        if (!is_dir($dir) && !@wp_mkdir_p($dir)) {
            return new \WP_Error('wpo_attachment_dir', __('Не удалось создать папку попытки для вложений.', 'wp-olympiad'), ['status' => 507]);
        }

        return $dir;
    }

    /** Указатель загруженных файлов попытки: кто, куда и что загрузил. */
    private static function index_file(string $bucket_dir): string
    {
        return $bucket_dir . '/index.json';
    }

    public static function read_index(string $bucket_dir): array
    {
        $file = self::index_file($bucket_dir);
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data) || !is_array($data['files'] ?? null)) {
            return [];
        }

        // В файле индекс — список, наружу отдаётся словарь по id: на него
        // опираются remove()/materialize(), и терять ключи нельзя.
        $by_id = [];
        foreach ($data['files'] as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id = (string)($entry['id'] ?? (string)$key);
            if ($id === '') {
                continue;
            }
            $entry['id'] = $id;
            $by_id[$id] = $entry;
        }

        return $by_id;
    }

    private static function write_index(string $bucket_dir, array $files): void
    {
        $payload = json_encode([
            'v'     => 1,
            'updated_at' => current_time('mysql'),
            'files' => array_values($files),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        @file_put_contents(self::index_file($bucket_dir), $payload, LOCK_EX);
        @chmod(self::index_file($bucket_dir), 0666);
    }

    /**
     * Относительный путь файла (хранится в ответе) -> абсолютный, с проверкой,
     * что он не вышел за пределы корня вложений.
     */
    public static function resolve(Quiz $quiz, string $rel): string|\WP_Error
    {
        $root = self::ensure_root($quiz);
        if (is_wp_error($root)) {
            return $root;
        }
        $rel = str_replace('\\', '/', trim($rel));
        if ($rel === '' || str_contains($rel, '..') || str_starts_with($rel, '/') || preg_match('#^[a-zA-Z]:#', $rel)) {
            return new \WP_Error('wpo_attachment_path', __('Недопустимый путь вложения.', 'wp-olympiad'), ['status' => 400]);
        }
        $abs = wp_normalize_path($root . '/' . $rel);
        $real_root = realpath($root);
        $real_file = realpath($abs);
        if ($real_file === false || $real_root === false || !str_starts_with(wp_normalize_path($real_file), wp_normalize_path($real_root) . '/')) {
            return new \WP_Error('wpo_attachment_missing', __('Файл вложения не найден.', 'wp-olympiad'), ['status' => 404]);
        }
        if (!is_file($real_file)) {
            return new \WP_Error('wpo_attachment_missing', __('Файл вложения не найден.', 'wp-olympiad'), ['status' => 404]);
        }

        return wp_normalize_path($real_file);
    }

    // ------------------------------------------------------------------ приём файла

    /**
     * Принять один загруженный файл. Ожидается запись вида $_FILES:
     * ['name'=>, 'type'=>, 'tmp_name'=>, 'size'=>, 'error'=>].
     *
     * @return array{id: string, name: string, ext: string, size: int, mime: string, sha256: string, rel: string, uploaded_at: string}|WP_Error
     */
    public static function accept(Quiz $quiz, string $bucket, int $question_id, array $file, array $limits): array|\WP_Error
    {
        $error_code = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error_code !== UPLOAD_ERR_OK) {
            return new \WP_Error('wpo_upload_error', self::upload_error_message($error_code), ['status' => 400]);
        }

        $tmp = (string)($file['tmp_name'] ?? '');
        // В веб-контексте принимаем только настоящую POST-загрузку; вне него
        // (CLI-прогоны тестов) путей к temp-файлу из формы нет.
        $real_upload = $tmp !== '' && is_uploaded_file($tmp);
        if ($tmp === '' || (!is_file($tmp) && !$real_upload) || (!$real_upload && PHP_SAPI !== 'cli')) {
            return new \WP_Error('wpo_upload_not_posted', __('Файл загружен некорректно.', 'wp-olympiad'), ['status' => 400]);
        }

        $size = (int)($file['size'] ?? 0);
        if ($size <= 0) {
            return new \WP_Error('wpo_upload_empty', __('Пустой файл не принимается.', 'wp-olympiad'), ['status' => 400]);
        }
        if ($size > (int)$limits['max_bytes']) {
            return new \WP_Error(
                'wpo_upload_too_large',
                sprintf(
                    /* translators: 1: size in MB */
                    __('Файл больше лимита олимпиады в %d МБ.', 'wp-olympiad'),
                    (int)round($limits['max_bytes'] / 1048576)
                ),
                ['status' => 413]
            );
        }

        $name = self::display_name((string)($file['name'] ?? 'file'));
        $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === '' || in_array($ext, self::NEVER_ALLOW_EXT, true) || !in_array($ext, $limits['exts'], true)) {
            return new \WP_Error('wpo_upload_type', sprintf(/* translators: %s: extensions */ __('Такие файлы не разрешены. Допустимо: %s.', 'wp-olympiad'), implode(', ', $limits['exts'])), ['status' => 415]);
        }

        // Second extension check: "work.pdf.php" never reaches the whitelist of the real extension.
        $declared = strtolower((string)pathinfo($name, PATHINFO_FILENAME));
        $inner_ext = strtolower((string)pathinfo($declared, PATHINFO_EXTENSION));
        if ($inner_ext !== '' && in_array($inner_ext, self::NEVER_ALLOW_EXT, true)) {
            return new \WP_Error('wpo_upload_double_ext', __('Файл с двойным расширением не принимается.', 'wp-olympiad'), ['status' => 415]);
        }

        if (!self::sniff($tmp, $ext)) {
            return new \WP_Error('wpo_upload_signature', __('Содержимое файла не соответствует расширению.', 'wp-olympiad'), ['status' => 415]);
        }

        $mime = self::sniffed_mime($tmp, $ext);
        if ($limits['mimes'] && !in_array($mime, $limits['mimes'], true)) {
            return new \WP_Error('wpo_upload_mime', __('Тип файла не разрешён настройками олимпиады.', 'wp-olympiad'), ['status' => 415]);
        }

        $dir = self::bucket_dir($quiz, $bucket);
        if (is_wp_error($dir)) {
            return $dir;
        }

        $index = self::read_index($dir);
        $used_for_question = 0;
        $used_bytes = 0;
        foreach ($index as $entry) {
            $used_bytes += (int)($entry['size'] ?? 0);
            if ((int)($entry['question_id'] ?? 0) === $question_id) {
                $used_for_question++;
            }
        }
        if ($used_for_question >= (int)$limits['attachment_max_count']) {
            return new \WP_Error(
                'wpo_upload_count',
                sprintf(/* translators: %d: count */ __('К этому заданию можно прикрепить не больше %d файлов.', 'wp-olympiad'), (int)$limits['attachment_max_count']),
                ['status' => 413]
            );
        }
        if ($used_bytes + $size > self::ATTEMPT_TOTAL_LIMIT) {
            return new \WP_Error('wpo_upload_quota', __('Суммарный лимит вложений на попытку исчерпан.', 'wp-olympiad'), ['status' => 507]);
        }

        $id = bin2hex(random_bytes(12));
        $stored = $id . '.' . $ext;
        $target = $dir . '/' . $stored;
        $moved = $real_upload ? @move_uploaded_file($tmp, $target) : @rename($tmp, $target);
        if (!$moved) {
            return new \WP_Error('wpo_upload_write', __('Не удалось сохранить файл вложения.', 'wp-olympiad'), ['status' => 507]);
        }
        @chmod($target, 0666);

        $rel = $bucket . '/' . $stored;
        $entry = [
            'id'          => $id,
            'question_id' => $question_id,
            'name'        => $name,
            'ext'         => $ext,
            'size'        => $size,
            'mime'        => $mime,
            'sha256'      => (string)hash_file('sha256', $target),
            'rel'         => $rel,
            'uploaded_at' => current_time('mysql'),
        ];
        $index[$id] = $entry;
        self::write_index($dir, $index);

        return $entry;
    }

    /** Удалить незалитый в индекс файл (участник передумал до отправки). */
    public static function remove(Quiz $quiz, string $bucket, string $id, int $question_id = 0): bool
    {
        $dir = self::bucket_dir($quiz, $bucket);
        if (is_wp_error($dir)) {
            return false;
        }
        $index = self::read_index($dir);
        $entry = $index[$id] ?? null;
        if (!is_array($entry) || ($question_id > 0 && (int)($entry['question_id'] ?? 0) !== $question_id)) {
            return false;
        }
        $abs = self::resolve($quiz, (string)$entry['rel']);
        if (!is_wp_error($abs)) {
            @unlink($abs);
        }
        // Файла больше нет — отметка о его доставке тоже должна исчезнуть.
        self::forget_delivered($quiz, [(string)($entry['rel'] ?? '')]);
        unset($index[$id]);
        self::write_index($dir, $index);

        return true;
    }

    /**
     * Оставить только те файлы, на которые сослались ответы; прочистка не трогает
     * ничего за пределами каталога попытки и ничего не удаляет из ответов.
     *
     * @param array<int,string> $keep id файлов, перечисленные в ответах
     */
    public static function purge_unreferenced(Quiz $quiz, string $bucket, array $keep): int
    {
        $dir = self::bucket_dir($quiz, $bucket);
        if (is_wp_error($dir)) {
            return 0;
        }
        $index = self::read_index($dir);
        $kept = [];
        $removed = 0;
        foreach ($index as $id => $entry) {
            if (in_array((string)$id, array_map('strval', $keep), true)) {
                $kept[$id] = $entry;
                continue;
            }
            $abs = self::resolve($quiz, (string)($entry['rel'] ?? ''));
            if (!is_wp_error($abs)) {
                @unlink($abs);
                self::forget_delivered($quiz, [(string)($entry['rel'] ?? '')]);
                $removed++;
            }
        }
        if ($removed > 0) {
            self::write_index($dir, $kept);
        }

        return $removed;
    }

    /** Файлы, относящиеся к попытке и заданию, в порядке загрузки. */
    public static function list_for(Quiz $quiz, string $bucket, int $question_id = 0): array
    {
        $dir = self::bucket_dir($quiz, $bucket);
        if (is_wp_error($dir)) {
            return [];
        }
        $out = [];
        foreach (self::read_index($dir) as $id => $entry) {
            if ($question_id > 0 && (int)($entry['question_id'] ?? 0) !== $question_id) {
                continue;
            }
            $entry['id'] = (string)$id;
            $out[] = $entry;
        }

        return $out;
    }

    // ------------------------------------------------------------------ вывод

    /** Отдать файл наружу: только как вложение, без превью и без угадывания путей. */
    public static function stream(string $abs, string $name): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $fallback = 'file.' . strtolower((string)pathinfo($abs, PATHINFO_EXTENSION));
        // Заголовки имеет смысл ставить только там, где есть HTTP-ответ.
        if (!headers_sent()) {
            nocache_headers();
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name !== '' ? $name : $fallback) . '"');
            header('X-Content-Type-Options: nosniff');
            header('Content-Length: ' . filesize($abs));
        }
        readfile($abs);
        exit;
    }

    /** Подпись для протокола и страницы результатов: имена, вес, количество. */
    public static function summarize(array $files): string
    {
        $parts = [];
        $bytes = 0;
        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }
            $size = (int)($file['size'] ?? 0);
            $bytes += $size;
            $parts[] = sprintf('%s (%s)', (string)($file['name'] ?? '—'), self::human_size($size));
        }
        if (!$parts) {
            return __('Файлы не приложены', 'wp-olympiad');
        }

        return sprintf(
            /* translators: 1: number of files, 2: total size, 3: file list */
            __('Приложено файлов: %1$d, всего %2$s — %3$s', 'wp-olympiad'),
            count($parts),
            self::human_size($bytes),
            implode('; ', $parts)
        );
    }

    public static function human_size(int $bytes): string
    {
        if ($bytes < 0) {
            $bytes = 0;
        }
        if (function_exists('size_format')) {
            return (string)size_format($bytes, 1);
        }
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024) . ' KB';
        }

        return $bytes . ' B';
    }

    /**
     * Метаданные вложений из сохранённого ответа. Файлы в ответе хранятся
     * описанием, а не содержимым: содержимое лежит в папке «вложения».
     */
    public static function answer_files(mixed $raw): array
    {
        $data = self::decode($raw);
        $files = [];
        foreach ((array)($data['files'] ?? []) as $file) {
            if (is_array($file) && !empty($file['rel'])) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /** Идентификаторы файлов из ответа участника: JSON, массив или список id. */
    public static function answer_ids(mixed $raw): array
    {
        $data = is_array($raw) ? $raw : self::decode($raw);
        $ids = $data['files'] ?? $data;
        if (is_array($ids) && !array_key_exists('id', $ids) && array_keys($ids) !== range(0, count($ids) - 1)) {
            // Не список — значит JSON битый или прислали словарь: ids нет.
            $ids = [];
        }
        if (!is_array($ids)) {
            return [];
        }
        $out = [];
        foreach ($ids as $id) {
            if (is_array($id)) {
                $id = $id['id'] ?? '';
            }
            $id = (string)$id;
            if (preg_match('/^[a-f0-9]{24}$/', $id) && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }

        return $out;
    }

    private static function decode(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        $data = json_decode((string)$raw, true);

        return is_array($data) ? $data : [];
    }

    /**
     * Проверить, что файлы относятся именно к этой попытке и этому заданию.
     * Чужой или чужой попытки id превращается в «файла нет» — подставить чужой
     * файл в свой ответ нельзя.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function materialize(Quiz $quiz, string $bucket, int $question_id, array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $dir = self::bucket_dir($quiz, $bucket);
        if (is_wp_error($dir)) {
            return [];
        }
        $index = self::read_index($dir);
        $root = self::root_dir($quiz);
        $out = [];
        foreach ($ids as $id) {
            $entry = $index[$id] ?? null;
            if (!is_array($entry) || (int)($entry['question_id'] ?? 0) !== $question_id) {
                continue;
            }
            $entry['id'] = (string)$id;
            $entry['exists'] = is_file($root . '/' . (string)($entry['rel'] ?? '')) ? 1 : 0;
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * Заменить в ответах списки id на проверенные метаданные (для заданий нового
     * типа). Ответ без принятых файлов снимается — иначе обязательный вопрос
     * выглядел бы отвеченным пустым списком.
     *
     * @param array<int,string> $kept собранные id: по ним чистится каталог попытки
     * @return array<int|string, mixed>
     */
    public static function resolve_answers(Quiz $quiz, string $bucket, array $answers, array &$kept): array
    {
        $kept = [];
        foreach ($quiz->get_questions(false) as $question) {
            if ($question->question_type !== self::TYPE) {
                continue;
            }
            $raw = $answers[$question->id] ?? null;
            if ($raw === null) {
                continue;
            }
            $ids = self::answer_ids($raw);
            $files = $ids ? self::materialize($quiz, $bucket, (int)$question->id, $ids) : [];
            foreach ($files as $file) {
                $kept[] = (string)$file['id'];
            }
            if (!$files) {
                // Ни одного принятого файла: у такого задания не может быть
                // текстового ответа: присланное мимо формы выбрасывается целиком.
                unset($answers[$question->id]);
                continue;
            }
            $answers[$question->id] = wp_json_encode([
                'v'    => 1,
                'files' => $files,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $answers;
    }

    /**
     * Что копировать в сетевой пакет: попытка (по bucket из пути) -> её файлы.
     *
     * @return array<int, array{attempt_ref: string, files: array}>
     */
    public static function rows_for_quiz(Quiz $quiz): array
    {
        global $wpdb;
        $responses = DB::responses_table();
        $questions = DB::questions_table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.user_answer FROM {$responses} r
             INNER JOIN {$questions} q ON q.id = r.question_id
             WHERE q.quiz_id = %d AND q.question_type = %s",
            (int)$quiz->id,
            self::TYPE
        ), ARRAY_A);

        $by_bucket = [];
        foreach ((array)$rows as $row) {
            foreach (self::answer_files($row['user_answer'] ?? '') as $file) {
                $rel = (string)($file['rel'] ?? '');
                $bucket = strpos($rel, '/') !== false ? substr($rel, 0, strpos($rel, '/')) : '';
                if (!preg_match('/^[a-f0-9]{16}$/', $bucket)) {
                    continue;
                }
                $by_bucket[$bucket][] = $file;
            }
        }

        $out = [];
        foreach ($by_bucket as $bucket => $files) {
            $out[] = ['attempt_ref' => $bucket, 'files' => $files];
        }

        return $out;
    }

    // ------------------------------------------------------------------ внутреннее

    /** Имя файла для показа: без путей, управляющих символов и длиной до 120. */
    public static function display_name(string $name): string
    {
        $name = str_replace(['\\', "\0"], ['/', ''], $name);
        $name = basename($name);
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        $name = str_replace(['"', "'", '<', '>', '|', ':', '*', '?'], '', $name);
        if ($name === '') {
            $name = 'файл';
        }
        if (function_exists('mb_strlen') && mb_strlen($name) > 120) {
            $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
            $name = mb_substr($name, 0, 110) . ($ext !== '' ? '.' . $ext : '');
        }

        return $name;
    }

    /** Проверка сигнатуры содержимого против заявленного расширения. */
    public static function sniff(string $path, string $ext): bool
    {
        if (!is_file($path) || !is_readable($path)) {
            return false;
        }
        if (in_array($ext, self::TEXT_EXTS, true)) {
            $head = (string)fread(fopen($path, 'rb'), 8192);
            if ($head === '') {
                return false;
            }
            // Текст, притворяющийся скриптом или страницей, не проходит.
            if (preg_match('/<\?php|<script|<!doctype\s+html|<html|javascript:/i', $head) === 1) {
                return false;
            }
            return preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $head) !== 1;
        }

        $groups = self::SIGNATURES[$ext] ?? null;
        if ($groups === null) {
            // Для расширений без сигнатуры полагаемся на MIME-определение.
            return true;
        }
        $need = 16;
        foreach ($groups as [$offset, $prefixes]) {
            foreach ($prefixes as $prefix) {
                $need = max($need, (int)$offset + strlen($prefix));
            }
        }
        $fh = fopen($path, 'rb');
        if (!$fh) {
            return false;
        }
        $head = (string)fread($fh, $need);
        fclose($fh);
        foreach ($groups as [$offset, $prefixes]) {
            $hit = false;
            foreach ($prefixes as $prefix) {
                if (substr($head, (int)$offset, strlen($prefix)) === $prefix) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                return false;
            }
        }

        return true;
    }

    /** MIME по содержимому, с запасным вариантом из таблицы видов. */
    public static function sniffed_mime(string $path, string $ext): string
    {
        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $detected = @finfo_file($finfo, $path);
                @finfo_close($finfo);
                if (is_string($detected) && $detected !== '') {
                    return $detected;
                }
            }
        }
        foreach (self::KINDS as $kind) {
            if (in_array($ext, self::KINDS[$kind]['exts'], true)) {
                return self::KINDS[$kind]['mimes'][0] ?? 'application/octet-stream';
            }
        }

        return 'application/octet-stream';
    }

    private static function upload_error_message(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => __('Файл превышает допустимый размер.', 'wp-olympiad'),
            UPLOAD_ERR_PARTIAL => __('Файл загружен частично, попробуйте ещё раз.', 'wp-olympiad'),
            UPLOAD_ERR_NO_FILE => __('Файл не выбран.', 'wp-olympiad'),
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => __('Сервер не смог записать файл.', 'wp-olympiad'),
            UPLOAD_ERR_EXTENSION => __('Загрузку прервал расширение сервера.', 'wp-olympiad'),
            default => __('Файл не принят.', 'wp-olympiad'),
        };
    }

    /**
     * Копирование вложений attempts в пакет сетевой выгрузки. Ничего не удаляет:
     * если файл пропал, в протоколе остаётся имя и подпись «не найден».
     *
     * @param array<int, array{attempt_ref: string, files: array}> $rows
     * @return array{copied: int, missing: int, dir: string}
     */
    public static function export_to(Quiz $quiz, array $rows, string $dest_dir, bool $force = false): array
    {
        $copied = 0;
        $missing = 0;
        $target_root = rtrim($dest_dir, '/\\') . '/' . self::DIR;
        // Доступ путём мог включиться между сдачей и выгрузкой — сначала переносим.
        self::migrate_spool($quiz);
        // Если до папки выгрузки путём не добраться (SMB/WebDAV/FTP без монтирования),
        // файлы отдаются тем же транспортом, что и протоколы. Папка выгрузки может ещё
        // не существовать — тогда важно, что рядом с ней можно писать.
        $local_dest = $dest_dir !== ''
            && (is_dir($dest_dir) || (is_dir(dirname($dest_dir)) && is_writable(dirname($dest_dir))));
        $already_delivered = 0;
        $transport_sizes = [];
        if ($local_dest && !is_dir($dest_dir)) {
            @wp_mkdir_p($dest_dir);
        }
        $store = self::store($quiz);
        $via_transport = [];
        foreach ($rows as $row) {
            $ref = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($row['attempt_ref'] ?? ''));
            if ($ref === '' || empty($row['files'])) {
                continue;
            }
            $target_dir = $target_root . '/' . $ref;
            if ($local_dest && !is_dir($target_dir)) {
                @wp_mkdir_p($target_dir);
            }
            foreach ($row['files'] as $file) {
                $abs = self::resolve($quiz, (string)($file['rel'] ?? ''));
                if (is_wp_error($abs)) {
                    $missing++;
                    continue;
                }
                $safe = self::display_name((string)($file['name'] ?? 'file'));
                if ($local_dest) {
                    $to = $target_dir . '/' . $safe;
                    if (@copy($abs, $to)) {
                        @chmod($to, 0666);
                        $copied++;
                    } else {
                        $missing++;
                    }
                    continue;
                }
                $rel = (string)($file['rel'] ?? '');
                $size = (int)($file['size'] ?? @filesize((string)$abs));
                $key = self::DIR . '/' . $store['slug'] . '/' . $ref . '/' . $safe;
                if (!$force && self::is_delivered($quiz, $rel)) {
                    $already_delivered++;
                    continue;
                }
                $via_transport[$key] = (string)file_get_contents((string)$abs);
                $transport_sizes[$key] = [$rel, $size];
                $copied++;
            }
        }

        $transport_message = '';
        $transport_codes = [];
        $transport_raw = '';
        if ($via_transport) {
            $sent = NetworkExport::deliver_files_public($via_transport, $store['config']);
            if (!empty($sent['success'])) {
                self::mark_delivered($quiz, self::delivered_sizes($sent, $transport_sizes));
            }
            if (empty($sent['success'])) {
                $transport_message = (string)($sent['message'] ?? '');
                $transport_codes = array_values(array_map('strval', (array)($sent['codes'] ?? [])));
                $transport_raw = (string)($sent['raw'] ?? '');
                $missing += count($via_transport);
                $copied -= count($via_transport);
                self::remember_pending($quiz, array_values(array_map(static function (string $rel) {
                    $parts = explode('/', $rel);

                    return ['bucket' => $parts[2] ?? '', 'name' => end($parts), 'rel' => $rel];
                }, array_keys($via_transport))), $transport_message, $transport_codes, $transport_raw);
            } else {
                self::forget_pending($quiz, array_keys($via_transport));
            }
        }

        return [
            'copied'    => $copied,
            'missing'   => $missing,
            'already'   => $already_delivered,
            'codes'     => $transport_codes,
            'raw'       => $transport_raw,
            'dir'       => $target_root,
            'transport' => $local_dest ? 'filesystem' : $store['type'],
            'message'   => $transport_message,
        ];
    }

    /**
     * Перенос того, что накопилось в рабочей папке, в сетевую папку.
     *
     * Нужно, когда доступ путём появился позже: работы участников, принятые в
     * режиме «spool», лежат в папке сайта, и после включения прямой папки их
     * иначе никто не увидит. Переносим файл, затем запись указателя; каталог
     * попытки удаляем, только когда всё сравнялось по размеру.
     *
     * @return array{moved: int, kept: int, skipped: int}
     */
    public static function migrate_spool(Quiz $quiz): array
    {
        $store = self::store($quiz);
        if ($store['mode'] !== 'share' || $store['spool'] === '' || !is_dir($store['spool'])) {
            return ['moved' => 0, 'kept' => 0, 'skipped' => 0];
        }

        $moved = 0;
        $kept = 0;
        $skipped = 0;
        foreach (glob($store['spool'] . '/*') ?: [] as $dir) {
            if (!is_dir($dir) || !preg_match('/[a-f0-9]{16}$/', basename($dir))) {
                $kept++;
                continue;
            }
            $target = rtrim($store['root'], '/\\') . '/' . basename($dir);
            if (!is_dir($target) && !@wp_mkdir_p($target)) {
                $kept++;
                continue;
            }
            self::guard_dir(rtrim($store['root'], '/\\'));

            $copies = [];
            $whole_ok = true;
            foreach (glob($dir . '/*') ?: [] as $file) {
                if (is_dir($file) || basename($file) === 'index.json') {
                    continue;
                }
                $to = $target . '/' . basename($file);
                if (is_file($to) && filesize($to) === filesize($file)) {
                    $skipped++;
                    continue;
                }
                if (@copy($file, $to) && is_file($to) && filesize($to) === filesize($file)) {
                    @chmod($to, 0666);
                    $copies[] = [$file, $to];
                    $moved++;
                } else {
                    $whole_ok = false;
                    $kept++;
                }
            }

            // Указатель: добавляем то, чего в сетевой папке ещё нет.
            $src_index = self::read_index($dir);
            $dst_index = self::read_index($target);
            $changed = false;
            foreach ($src_index as $id => $entry) {
                if (!isset($dst_index[$id])) {
                    $dst_index[$id] = $entry;
                    $changed = true;
                }
            }
            if ($changed) {
                self::write_index($target, $dst_index);
            }

            if ($whole_ok && $copies) {
                foreach ($copies as [$from, ]) {
                    @unlink($from);
                }
                @unlink($dir . '/index.json');
                @rmdir($dir);
            }
        }

        if ($moved || $skipped) {
            self::clear_pending($quiz);
        }

        return ['moved' => $moved, 'kept' => $kept, 'skipped' => $skipped];
    }

    /**
     * Сданная работа: копируем прикреплённые файлы в сетевую папку. Нужен для
     * режима «spool» — пока файлы лежат только на сервере, жюри их не видит.
     *
     * @param array<int,string> $ids
     * @return array{copied: int, missing: int, mode: string, message: string}
     */
    public static function flush_to_share(Quiz $quiz, string $bucket, array $ids): array
    {
        $store = self::store($quiz);
        if ($store['mode'] === 'share') {
            // Прямой доступ мог появиться позже — тогда переносим то, что уже
            // накопилось в рабочей папке, иначе работы участников останутся на сервере.
            $migrated = self::migrate_spool($quiz);

            return [
                'copied'  => $migrated['moved'],
                'missing' => $migrated['kept'],
                'mode'    => 'share',
                'message' => $migrated['kept']
                    ? __('Часть накопленных вложений не перенесена в сетевую папку: нет доступа на запись.', 'wp-olympiad')
                    : '',
            ];
        }

        $files = [];
        $sizes = [];
        $missing = 0;
        $already = 0;
        foreach (array_unique(array_map('strval', $ids)) as $id) {
            $dir = self::bucket_dir($quiz, $bucket);
            if (is_wp_error($dir)) {
                return ['copied' => 0, 'missing' => count($ids), 'mode' => 'spool', 'message' => $dir->get_error_message()];
            }
            $index = self::read_index($dir);
            $entry = $index[$id] ?? null;
            $abs = is_array($entry) ? self::resolve($quiz, (string)($entry['rel'] ?? '')) : new \WP_Error('wpo_attachment_missing');
            if (is_wp_error($abs) || !is_file((string)$abs)) {
                $missing++;
                continue;
            }
            $rel = (string)($entry['rel'] ?? '');
            $size = (int)@filesize((string)$abs);
            // Тот же файл уже лежит в сетевой папке: вторая запись ничего не добавит,
            // а на части конфигураций Samba отвечает на это ошибкой коллизии.
            if (self::is_delivered($quiz, $rel)) {
                $already++;
                continue;
            }
            $key = self::DIR . '/' . $store['slug'] . '/' . $bucket . '/' . basename((string)$abs);
            $files[$key] = (string)file_get_contents((string)$abs);
            $sizes[$key] = [$rel, $size];
        }

        if (!$files) {
            return ['copied' => 0, 'missing' => $missing, 'already' => $already, 'mode' => 'spool', 'message' => ''];
        }

        $sent = NetworkExport::deliver_files_public($files, $store['config']);
        if (!empty($sent['success'])) {
            self::mark_delivered($quiz, self::delivered_sizes($sent, $sizes));
            self::forget_pending($quiz, array_keys($files));

            return [
                'copied'  => count($files),
                'missing' => $missing,
                'already' => $already,
                'mode'    => 'spool',
                'message' => (string)($sent['message'] ?? ''),
                'codes'   => array_values(array_map('strval', (array)($sent['codes'] ?? []))),
                'names'   => array_values(array_map(static fn($n) => basename(str_replace('\\', '/', (string)$n)), array_keys($files))),
            ];
        }

        $message = (string)($sent['message'] ?? '');
        $codes = array_values(array_map('strval', (array)($sent['codes'] ?? [])));
        $raw = (string)($sent['raw'] ?? '');
        self::remember_pending($quiz, array_map(static function (string $rel) use ($bucket) {
            return ['bucket' => $bucket, 'name' => basename($rel), 'rel' => $rel];
        }, array_keys($files)), $message, $codes, $raw);

        return [
            'copied'  => 0,
            'missing' => $missing + count($files),
            'already' => $already,
            'mode'    => 'spool',
            'message' => $message,
            'codes'   => $codes,
            'raw'     => $raw,
            'names'   => array_values(array_map(static fn($n) => basename(str_replace('\\', '/', (string)$n)), array_keys($files))),
        ];
    }

    /**
     * Какие файлы считать доставленными по ответу транспорта. Отметку ставим только для
     * тех, что транспорт подтвердил: записал или нашёл на месте с тем же размером.
     *
     * @param array<string,int> $sizes ключ транспорта => [rel, размер]
     * @return array<string,int>
     */
    private static function delivered_sizes(array $sent, array $sizes): array
    {
        $confirmed = array_merge((array)($sent['files'] ?? []), (array)($sent['existing'] ?? []));
        $out = [];
        foreach ($confirmed as $key) {
            $key = (string)$key;
            if (!isset($sizes[$key])) {
                continue;
            }
            [$rel, $size] = $sizes[$key];
            if ($rel !== '') {
                $out[$rel] = (int)$size;
            }
        }

        return $out;
    }

    // ------------------------------------------------- отметки о доставке в сетевую папку

    /** Имя записи с отметками: `rel` => размер и подпись цели доставки. */
    public static function delivered_option(int $quiz_id): string
    {
        return 'wpo_attachment_delivered_' . $quiz_id;
    }

    /**
     * Подпись цели доставки: транспорт + путь + подпапка олимпиады. Меняются настройки
     * сетевой папки — старые отметки перестают учитываться, файлы отдаются заново.
     */
    private static function delivery_sig(array $store): string
    {
        return substr(hash('sha256', $store['type'] . '|' . $store['share'] . '|' . $store['slug']), 0, 16);
    }

    /** @return array<string, array{size: int, sig: string}> */
    public static function delivered_marks(Quiz $quiz): array
    {
        $data = get_option(self::delivered_option((int) $quiz->id), []);

        return is_array($data) ? $data : [];
    }

    /**
     * Файл уже лежит в сетевой папке — второй раз его не несём.
     *
     * Размер в отметке — справочный: сверять по нему нельзя, потому что в ответе
     * участника может быть записан заявленный размер загрузки, а на диске — фактический.
     * Подлинность файла на сервере проверяет транспорт (размер через «ls» в SMB),
     * а новое вложение всегда получает новый rel, поэтому промахнуться здесь нельзя.
     */
    public static function is_delivered(Quiz $quiz, string $rel): bool
    {
        if ($rel === '') {
            return false;
        }
        $store = self::store($quiz);
        $marks = self::delivered_marks($quiz);

        return isset($marks[$rel]) && (string) ($marks[$rel]['sig'] ?? '') === self::delivery_sig($store);
    }

    /** @param array<string,int> $items rel => размер */
    public static function mark_delivered(Quiz $quiz, array $items): void
    {
        if (!$items) {
            return;
        }
        $store = self::store($quiz);
        $sig = self::delivery_sig($store);
        $marks = self::delivered_marks($quiz);
        foreach ($items as $rel => $size) {
            $marks[(string) $rel] = ['size' => (int) $size, 'sig' => $sig];
        }
        if (count($marks) > 5000) {
            $marks = array_slice($marks, -5000, null, true);
        }
        update_option(self::delivered_option((int) $quiz->id), $marks, false);
    }

    /** @param array<int,string> $rels пустой список — сбросить все отметки олимпиады */
    public static function forget_delivered(Quiz $quiz, array $rels = []): void
    {
        $option = self::delivered_option((int) $quiz->id);
        if (!$rels) {
            delete_option($option);

            return;
        }
        $marks = self::delivered_marks($quiz);
        foreach ($rels as $rel) {
            unset($marks[(string) $rel]);
        }
        if ($marks) {
            update_option($option, $marks, false);
        } else {
            delete_option($option);
        }
    }

    /**
     * Принудительная доставка: снять отметки о доставленном снимаются и все
     * прикреплённые файлы попытки (или все файлы олимпиады) уходят в сетевую папку заново.
     *
     * @return array{sent: int, skipped: int, missing: int, message: string}
     */
    public static function redeliver(Quiz $quiz): array
    {
        self::forget_delivered($quiz);
        self::reset_store_cache((int) $quiz->id);
        $rows = self::rows_for_quiz($quiz);
        $result = ['sent' => 0, 'skipped' => 0, 'missing' => 0, 'already' => 0, 'message' => '', 'codes' => [], 'raw' => '', 'names' => []];
        foreach ($rows as $row) {
            $bucket = (string) ($row['attempt_ref'] ?? '');
            if ($bucket === '' || empty($row['files'])) {
                continue;
            }
            $ids = array_values(array_filter(array_map(static fn(array $f) => (string) ($f['id'] ?? ''), $row['files'])));
            $sent = self::flush_to_share($quiz, $bucket, $ids);
            $result['sent'] += (int) ($sent['copied'] ?? 0);
            $result['missing'] += (int) ($sent['missing'] ?? 0);
            $result['already'] += (int) ($sent['already'] ?? 0);
            foreach (['message', 'raw'] as $key) {
                if ((string) ($sent[$key] ?? '') !== '') {
                    $result[$key] = (string) $sent[$key];
                }
            }
            $result['codes'] = array_values(array_unique(array_merge($result['codes'], array_map('strval', (array) ($sent['codes'] ?? [])))));
            $result['names'] = array_values(array_unique(array_merge($result['names'], array_map('strval', (array) ($sent['names'] ?? [])))));
        }

        // «Папка недоступна для записи» без причины — бесполезное предупреждение:
        // сохраняем ответ транспорта, чтобы он был виден на странице олимпиады.
        $result['pending'] = count(self::pending($quiz));
        self::remember_delivery($quiz, $result);

        return $result;
    }

    /** Последний отчёт о доставке: чем именно ответил транспорт. */
    public static function last_delivery_option(int $quiz_id): string
    {
        return 'wpo_attachment_last_delivery_' . $quiz_id;
    }

    /** @return array<string, mixed> */
    public static function last_delivery(Quiz $quiz): array
    {
        $data = get_option(self::last_delivery_option((int) $quiz->id), []);

        return is_array($data) ? $data : [];
    }

    /** @param array<string, mixed> $report */
    private static function remember_delivery(Quiz $quiz, array $report): void
    {
        update_option(
            self::last_delivery_option((int) $quiz->id),
            [
                'when'    => current_time('mysql'),
                'sent'    => (int) ($report['sent'] ?? $report['copied'] ?? 0),
                'missing' => (int) ($report['missing'] ?? 0),
                'already' => (int) ($report['already'] ?? 0),
                'mode'    => (string) ($report['mode'] ?? ''),
                'message' => mb_substr((string) ($report['message'] ?? ''), 0, 400),
                'codes'   => array_slice(array_values(array_map('strval', (array) ($report['codes'] ?? []))), 0, 6),
                'raw'     => mb_substr((string) ($report['raw'] ?? ''), 0, 900),
                'names'   => array_slice(array_values(array_map('strval', (array) ($report['names'] ?? []))), 0, 8),
                'pending' => (int) ($report['pending'] ?? 0),
            ],
            false
        );
    }

    /** Что не удалось доставить в сетевую папку (список виден организатору). */
    public static function pending_option(int $quiz_id): string
    {
        return 'wpo_attachment_pending_' . $quiz_id;
    }

    /** @return array<int, array<string, mixed>> */
    public static function pending(Quiz $quiz): array
    {
        $data = get_option(self::pending_option((int)$quiz->id), []);

        return is_array($data) ? array_values($data) : [];
    }

    private static function remember_pending(Quiz $quiz, array $items, string $message, array $codes = [], string $raw = ''): void
    {
        $known = self::pending($quiz);
        foreach ($items as $item) {
            $item['when'] = current_time('mysql');
            $item['error'] = mb_substr($message, 0, 300);
            $item['codes'] = array_slice($codes, 0, 4);
            $item['raw'] = mb_substr($raw, 0, 500);
            $known[] = $item;
        }
        update_option(self::pending_option((int)$quiz->id), array_slice($known, -50), false);
    }

    /** Всё доставлено — список недоставленного больше не нужен. */
    private static function clear_pending(Quiz $quiz): void
    {
        if (self::pending($quiz)) {
            delete_option(self::pending_option((int)$quiz->id));
        }
    }

    private static function forget_pending(Quiz $quiz, array $rels): void
    {
        $known = self::pending($quiz);
        if (!$known) {
            return;
        }
        $done = array_map(static fn($rel) => basename((string)$rel), $rels);
        $rest = array_values(array_filter($known, static function (array $item) use ($done) {
            return !in_array((string)($item['name'] ?? basename((string)($item['rel'] ?? ''))), $done, true);
        }));
        if ($rest) {
            update_option(self::pending_option((int)$quiz->id), $rest, false);
        } else {
            delete_option(self::pending_option((int)$quiz->id));
        }
    }
}
