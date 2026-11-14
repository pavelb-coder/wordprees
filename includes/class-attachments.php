<?php
/**
 * 1.0.32: вложения участников — тип задания «Прикрепить файл».
 *
 * Файлы кладутся в отдельную подпапку сетевой папки олимпиады:
 * «вложения/<slug олимпиады>/<bucket>/<случайное имя>.<расширение>».
 *
 * Если к сетевой папке нет прямого доступа из PHP (SMB без монтирования,
 * WebDAV, FTP), файлы лежат в закрытой рабочей папке сайта и копируются в
 * сетевую папку при сдаче работы, при автоматическом повторе и при выгрузке
 * протоколов — тем же
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

    /** Хук планировщика: донести вложения, которые не ушли в сетевую папку. */
    public const RETRY_HOOK = 'wpo_attachments_retry';

    /** Первая пауза между автоматическими попытками и её предел. */
    public const RETRY_FLOOR = 300;
    public const RETRY_CEIL = 21600;

    /** Запись результата проверки папки вложений на сетевой папке. */
    public const PROBE_OK_TTL = 43200;
    /** Неудачную пробу повторяем не чаще, чем раз в пять минут. */
    public const PROBE_FAIL_FLOOR = 300;

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
    /** @var array<int, array<string, array{id:int,name:string}>> */
    private static array $share_labels_cache = [];

    /** Сброс кеша — нужен тестам и после изменения настроек сетевой папки. */
    public static function reset_store_cache(?int $quiz_id = null): void
    {
        if ($quiz_id === null) {
            self::$store_cache = [];
            self::$share_labels_cache = [];
        } else {
            unset(self::$store_cache[$quiz_id]);
            unset(self::$share_labels_cache[$quiz_id]);
        }
    }

    /**
     * Где физически живут файлы этой олимпиады.
     *
     * - «share» — путь сетевой папки доступен PHP как обычный путь (смонтирован,
     *   либо это Windows и UNC-путь): вложения пишутся прямо в сетевую папку.
     * - «spool» — доступа путём нет (SMB без монтирования, WebDAV, FTP): файлы
     *   лежат в закрытой папке сайта, а в сетевую папку копируются при сдаче
     *   работы, при автоматическом повторе и при выгрузке протоколов — тем же
     *   транспортом, что и протоколы.
     *
     * @return array{mode: string, type: string, root: string, share: string, spool: string, slug: string, config: array, note: string}
     */
    /**
     * Хуки автоматической синхронизации вложений.
     *
     * Планировщика WordPress может и не быть (блокировка wp-cron, крон «по
     * требованию» только на хитах), поэтому попытка донести недоставленное
     * делается и когда организатор открывает страницу олимпиады.
     */
    public static function init(): void
    {
        add_action(self::RETRY_HOOK, static function (int $quiz_id): void {
            self::handle_retry_event($quiz_id);
        }, 10, 1);
        add_action('admin_init', [self::class, 'maybe_sync_on_admin'], 20);
    }

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
                    __('Сетевая папка недоступна PHP как путь (%s) — штатный режим для UNC, WebDAV и FTP: вложения лежат в рабочей папке сайта, а на шару их кладёт транспорт при сдаче, при автоматическом повторе и по кнопке «Доставить сейчас».', 'wp-olympiad'),
                    $type
                )
                : sprintf(
                    /* translators: 1: путь, 2: транспорт */
                    __('Нет доступа на запись к «%1$s» (транспорт %2$s): вложения остаются в рабочей папке сайта; на шару они уедут, как только доступ появится — доставка пробуется при сдаче, при автоматическом повторе и по кнопке «Доставить сейчас».', 'wp-olympiad'),
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

    /**
     * Имя для сетевой шары: без символов, которые Windows запрещает в файлах,
     * без управляющих последовательностей и без завершающих точек и пробелов
     * (их Windows отрезает молча, и файл «теряется»).
     */
    public static function share_sanitize(string $name, int $cap = 120): string
    {
        $name = str_replace(['\\', '/', ':', '*', '?', '"', '<', '>', '|', "\0"], ' ', $name);
        $name = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $name) ?? '';
        $name = preg_replace('/\s+/u', ' ', $name) ?? '';
        $name = trim($name, " .\t");
        if ($name === '' || preg_match('/^(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i', $name)) {
            $name = ($name === '' ? 'файл' : $name) . '_';
        }
        $cap = max(16, $cap);
        if (function_exists('mb_strlen') ? mb_strlen($name) > $cap : strlen($name) > $cap) {
            $ext = (string)pathinfo($name, PATHINFO_EXTENSION);
            $ext = $ext !== '' && strlen($ext) <= 8 ? '.' . $ext : '';
            $keep = max(4, $cap - ($ext !== '' ? mb_strlen($ext) : 0));
            $name = trim(mb_substr($name, 0, $keep), " .\t") . $ext;
        }

        return $name;
    }

    /**
     * Подписи попыток олимпиады: корзина (хеш токена) => id попытки и имя
     * участника. Нужны только для читаемых имён на шаре; по хешу попытки
     * восстановить что-либо нельзя, поэтому берёмся за таблицу попыток, а не
     * за путь.
     *
     * @return array<string, array{id:int,name:string}>
     */
    public static function share_labels(Quiz $quiz): array
    {
        $quiz_id = (int)$quiz->id;
        if (isset(self::$share_labels_cache[$quiz_id])) {
            return self::$share_labels_cache[$quiz_id];
        }
        global $wpdb;
        $table = DB::attempts_table();
        $rows = (array)$wpdb->get_results($wpdb->prepare(
            "SELECT id, token, participant_name FROM {$table} WHERE quiz_id = %d",
            $quiz_id
        ), ARRAY_A);
        $out = [];
        foreach ($rows as $row) {
            $token = (string)($row['token'] ?? '');
            if ($token === '') {
                continue;
            }
            $bucket = self::bucket($token);
            $id = (int)($row['id'] ?? 0);
            $known = $out[$bucket] ?? null;
            // Теоретически одна корзина может достаться двум попыткам: берём
            // первую по id, чтобы имя на шаре не менялось от выгрузки к выгрузке.
            if ($known === null || $id < (int)$known['id']) {
                $out[$bucket] = ['id' => $id, 'name' => (string)($row['participant_name'] ?? '')];
            }
        }

        return self::$share_labels_cache[$quiz_id] = $out;
    }

    /** Папка попытки на шаре: «Иванов Иван — 12»; попытку не нашли — прежняя корзина. */
    public static function share_folder(string $bucket, array $labels): string
    {
        $bucket = trim($bucket);
        $label = $labels[$bucket] ?? null;
        if (!is_array($label)) {
            return $bucket;
        }
        $who = self::share_sanitize((string)($label['name'] ?? ''), 60);
        if ($who === '' || $who === 'файл_') {
            $who = 'без имени';
        }
        $id = (int)($label['id'] ?? 0);
        $folder = $id > 0 ? $who . ' — ' . $id : $who;
        $folder = trim($folder, " .\t");

        return $folder !== '' ? $folder : $bucket;
    }

    /** Порядковые номера заданий олимпиады: id задания => 1, 2, 3… */
    public static function question_numbers(Quiz $quiz): array
    {
        $out = [];
        $n = 0;
        foreach ($quiz->get_questions(false) as $question) {
            $out[(int)$question->id] = ++$n;
        }

        return $out;
    }

    /**
     * Имя файла на шаре: «Иванов Иван — 03 — скан работы.pdf».
     *
     * Основа — то, как файл назвал участник (`index.json` хранит его в `name`);
     * спереди — участник и номер задания, потому что на шаре работы лежат рядом
     * и «скан.pdf» без контекста не отличить от «скан.pdf» соседа.
     */
    public static function share_name(array $entry, array $labels, array $numbers, string $bucket = ''): string
    {
        $rel = str_replace('\\', '/', (string)($entry['rel'] ?? ''));
        if ($bucket === '' && strpos($rel, '/') !== false) {
            $bucket = substr($rel, 0, strpos($rel, '/'));
        }
        $label = $labels[$bucket] ?? null;
        $parts = [];
        if (is_array($label)) {
            $who = self::share_sanitize((string)($label['name'] ?? ''), 60);
            if ($who !== '' && $who !== 'файл_') {
                $parts[] = $who;
            }
        }
        $no = (int)($numbers[(int)($entry['question_id'] ?? 0)] ?? 0);
        if ($no > 0) {
            $parts[] = sprintf('%02d', $no);
        }
        $orig = self::display_name((string)($entry['name'] ?? 'файл'));
        $name = ($parts ? implode(' — ', $parts) . ' — ' : '') . $orig;

        // 120 символов — с запасом под «\\server\share\olimp\вложения\…":
        // Windows считает длиной весь путь, а не одно имя.
        return self::share_sanitize($name, 120);
    }

    /**
     * Имена всех вложений попытки — стабильные: считываются из индекса папки и
     * нумеруются по id записи, а не по составу очередного батча доставки. Иначе
     * один и тот же файл при повторной выгрузке уехал бы под «скан (2).pdf».
     *
     * @return array<string,string> id вложения => имя файла на шаре
     */
    public static function share_names(Quiz $quiz, string $bucket, array $labels, array $numbers): array
    {
        $dir = self::bucket_dir($quiz, $bucket);
        if (is_wp_error($dir)) {
            return [];
        }
        $index = self::read_index($dir);
        $ids = array_keys($index);
        sort($ids);
        $used = [];
        $out  = [];
        foreach ($ids as $id) {
            $base = self::share_name($index[$id], $labels, $numbers, $bucket);
            if ($base === '') {
                $base = 'файл';
            }
            $cand = $base;
            $n = 2;
            while (isset($used[strtolower($cand)])) {
                $cand = self::name_with_suffix($base, $n++);
            }
            $used[strtolower($cand)] = true;
            $out[(string)$id] = $cand;
        }

        return $out;
    }

    /** Вставить « (N)» перед расширением. */
    private static function name_with_suffix(string $name, int $n): string
    {
        if (preg_match('#^(.*)\.([A-Za-z0-9]{1,8})$#u', $name, $m)) {
            return trim($m[1]) . ' (' . $n . ').' . $m[2];
        }

        return $name . ' (' . $n . ')';
    }

    /** Не допустить коллизии имён внутри одного батча: «скан (2).pdf». */
    private static function unique_share_key(array $taken, string $key): string
    {
        if (!isset($taken[$key])) {
            return $key;
        }
        $dir  = (string)dirname($key);
        $base = basename($key);
        $cand = $key;
        for ($n = 2; $n < 100; $n++) {
            $cand = ($dir === '.' ? '' : $dir . '/') . self::name_with_suffix($base, $n);
            if (!isset($taken[$cand])) {
                return $cand;
            }
        }

        return ($dir === '.' ? '' : $dir . '/') . $base . '.' . substr(hash('crc32b', $key), 0, 6);
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

        // Выгрузка протоколов — тот момент, когда жюри уже ждёт работы на шаре:
        // заодно доносим всё, что числится недоставленным. Отметки о доставленном
        // при этом не сбрасываются — повтор касается только недостающих файлов.
        if (self::pending($quiz) !== []) {
            self::retry_pending($quiz);
        }
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
        $via_bucket    = [];
        $labels        = self::share_labels($quiz);
        $numbers       = self::question_numbers($quiz);
        foreach ($rows as $row) {
            $ref = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($row['attempt_ref'] ?? ''));
            if ($ref === '' || empty($row['files'])) {
                continue;
            }
            $names      = self::share_names($quiz, $ref, $labels, $numbers);
            $target_dir = $target_root . '/' . self::share_folder($ref, $labels);
            if ($local_dest && !is_dir($target_dir)) {
                @wp_mkdir_p($target_dir);
            }
            foreach ($row['files'] as $file) {
                $abs = self::resolve($quiz, (string)($file['rel'] ?? ''));
                if (is_wp_error($abs)) {
                    $missing++;
                    continue;
                }
                $safe = (string)($names[(string)($file['id'] ?? '')] ?? '');
                if ($safe === '') {
                    $safe = self::share_name($file, $labels, $numbers, $ref) ?: self::display_name((string)($file['name'] ?? 'file'));
                }
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
                $key = self::unique_share_key($via_transport, self::DIR . '/' . $store['slug'] . '/'
                    . self::share_folder($ref, $labels) . '/' . $safe);
                $via_bucket[$key] = $ref;
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
                self::remember_pending($quiz, array_values(array_map(static function (string $rel) use ($via_bucket) {
                    return [
                        'bucket' => (string)($via_bucket[$rel] ?? ''),
                        'name'   => basename($rel),
                        'rel'    => $rel,
                        'share'  => $rel,
                    ];
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
        $labels  = self::share_labels($quiz);
        $numbers = self::question_numbers($quiz);
        $names   = self::share_names($quiz, $bucket, $labels, $numbers);
        $folder  = self::DIR . '/' . $store['slug'] . '/' . self::share_folder($bucket, $labels);
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
            // На шаре файл называется так, как его назвал участник (с подписью
            // «участник — номер задания»): «0613669172928f87/скан.pdf» жюри
            // не читается, а «Иванов Иван — 03 — скан.pdf» читается.
            $share_name = (string)($names[(string)$id] ?? '');
            if ($share_name === '') {
                $share_name = self::share_sanitize(basename((string)$abs), 120);
            }
            $key = self::unique_share_key($files, $folder . '/' . $share_name);
            $files[$key] = (string)file_get_contents((string)$abs);
            $sizes[$key] = [$rel, $size];
        }

        if (!$files) {
            // Молча «ничего не доставлено» — то, из-за чего организатор не может
            // понять, почему папка на шаре не появилась: причины бывают разные.
            $why = '';
            if ($missing > 0) {
                $why = sprintf(
                    /* translators: 1: число файлов */
                    _n(
                        '%1$d файл не найден в рабочей папке сайта: доставлять нечего, сетевая папка не тронута.',
                        '%1$d файлов не найдено в рабочей папке сайта: доставлять нечего, сетевая папка не тронута.',
                        $missing,
                        'wp-olympiad'
                    ),
                    $missing
                );
            } elseif ($already > 0) {
                $why = sprintf(
                    /* translators: 1: число файлов */
                    _n(
                        '%1$d файл отмечен доставленным ранее — повторная запись не выполнялась. Если на шаре его нет, нажмите «Доставить сейчас».',
                        '%1$d файлов отмечены доставленными ранее — повторная запись не выполнялась. Если на шаре их нет, нажмите «Доставить сейчас».',
                        $already,
                        'wp-olympiad'
                    ),
                    $already
                );
            }

            return ['copied' => 0, 'missing' => $missing, 'already' => $already, 'mode' => 'spool', 'message' => $why];
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
        // «share» — путь ровно такой, каким он лёг на шару: по нему снимается
        // защита и по нему же идёт повторная доставка, без повторной сборки имён.
        self::remember_pending($quiz, array_map(static function (string $rel) use ($bucket) {
            return ['bucket' => $bucket, 'name' => basename($rel), 'rel' => $rel, 'share' => $rel];
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
                $out[$rel] = ['size' => (int)$size, 'share' => (string)$key];
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
            if (is_array($size)) {
                $mark = ['size' => (int)($size['size'] ?? 0), 'sig' => $sig];
                if (isset($size['share']) && (string)$size['share'] !== '') {
                    $mark['share'] = (string)$size['share'];
                }
                $marks[(string)$rel] = $mark;
                continue;
            }
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

    /**
     * Снять защиту «только для чтения» с файлов олимпиады в сетевой папке.
     *
     * Задевает только те имена, которые принёс сам плагин (вложения attempts и
     * реестр доставленного): чужие файлы на share не трогаются никогда.
     *
     * @return array{success: bool, unprotected: int, total: int, mode: string, message: string, raw: string, names: array}
     */
    public static function unprotect_share(Quiz $quiz): array
    {
        $store = self::store($quiz);
        $result = ['success' => false, 'unprotected' => 0, 'failed' => 0, 'total' => 0, 'mode' => '', 'message' => '', 'raw' => '', 'names' => []];
        $names = [];
        $labels = self::share_labels($quiz);
        foreach (self::pending($quiz) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $share = trim(str_replace('\\', '/', (string)($item['share'] ?? '')), '/');
            if ($share !== '') {
                $names[] = $share;
                continue;
            }
            $bucket = (string) ($item['bucket'] ?? '');
            $name = (string) ($item['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $names[] = self::DIR . '/' . $store['slug'] . '/'
                . ($bucket !== '' ? self::share_folder($bucket, $labels) . '/' : '') . $name;
        }
        foreach (self::delivered_marks($quiz) as $rel => $mark) {
            $rel = str_replace('\\', '/', (string) $rel);
            $share = trim(str_replace('\\', '/', (string)(is_array($mark) ? ($mark['share'] ?? '') : '')), '/');
            if ($share !== '') {
                $names[] = $share;
                continue;
            }
            // Отметки старых версий: путь на шаре собирается из корзины попытки.
            $bucket = strpos($rel, '/') !== false ? substr($rel, 0, strpos($rel, '/')) : '';
            $names[] = self::DIR . '/' . $store['slug'] . '/'
                . (preg_match('#^[a-f0-9]{16}/#', $rel) ? self::share_folder($bucket, $labels) . '/' . substr($rel, strlen($bucket) + 1) : basename($rel));
        }
        $names = array_values(array_unique(array_filter($names)));
        $result['total'] = count($names);
        if (!$names) {
            $result['message'] = __('Снимать защиту не с чего: плагин не знает ни об одном файле этой олимпиады в сетевой папке.', 'wp-olympiad');

            return $result;
        }

        if ($store['share'] !== '' && self::writable_dir($store['share'])) {
            $count = 0;
            foreach ($names as $name) {
                $tail = preg_replace('#^' . preg_quote(self::DIR . '/' . $store['slug'] . '/', '#') . '([^/]+/)?#', '', str_replace('\\', '/', $name));
                $abs = rtrim($store['share'], '/\\') . '/' . $tail;
                if (is_file($abs)) {
                    @chmod($abs, 0666);
                    if (!self::is_readonly($abs)) {
                        $count++;
                    }
                }
            }
            $result['mode'] = 'filesystem';
            $result['unprotected'] = $count;
            $result['failed'] = max(0, $result['total'] - $count);
            $result['success'] = $count > 0;
            $result['names'] = array_slice(array_map(static fn($n) => basename(str_replace('\\', '/', (string) $n)), $names), 0, 8);
            if (!$count) {
                $result['message'] = __('Ни одного файла плагина по этому пути не найдено: папка выгрузки доступна только по сети, а не путём. Воспользуйтесь «Доставить сейчас» — защита снимается автоматически при несовпадении содержимого.', 'wp-olympiad');
            }

            return $result;
        }

        if ($store['type'] !== 'smb') {
            $result['mode'] = 'unsupported';
            $result['message'] = sprintf(
                /* translators: 1: transport, 2: share path */
                __('Сетевая папка «%2$s» работает через транспорт %1$s: снять атрибут «только для чтения» можно только на самом сервере.', 'wp-olympiad'),
                $store['type'],
                (string) ($store['config']['path'] ?? '')
            );

            return $result;
        }

        $sent = NetworkExport::smb_unprotect($names, $store['config']);
        $result['mode'] = 'smb';
        $result['unprotected'] = (int) ($sent['count'] ?? 0);
        $result['failed'] = (int) ($sent['failed'] ?? 0);
        $result['success'] = !empty($sent['success']);
        $result['message'] = (string) ($sent['message'] ?? '');
        $result['raw'] = (string) ($sent['raw'] ?? '');
        $result['codes'] = array_values(array_map('strval', (array) ($sent['codes'] ?? [])));
        $result['names'] = array_slice(array_map(static fn($n) => basename(str_replace('\\', '/', (string) $n)), $names), 0, 8);
        self::reset_store_cache((int) $quiz->id);

        return $result;
    }

    /** Файл помечен «только для чтения»? */
    private static function is_readonly(string $path): bool
    {
        clearstatcache(true, $path);

        return DIRECTORY_SEPARATOR === '\\' ? !@is_writeable($path) : ((fileperms($path) & 0o200) === 0);
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
        // Одна строка на файл, а не по строке на каждую неудачную попытку:
        // автоматический повтор вызвал бы рост списка до бесконечности.
        $by_key = [];
        foreach ($known as $n => $item_old) {
            $key = (string)(is_array($item_old) ? ($item_old['rel'] ?? $item_old['name'] ?? '') : '');
            if ($key !== '') {
                $by_key[$key] = $n;
            }
        }
        foreach ($items as $item) {
            $item['when'] = current_time('mysql');
            $item['error'] = mb_substr($message, 0, 300);
            $item['codes'] = array_slice($codes, 0, 4);
            $item['raw'] = mb_substr($raw, 0, 500);
            $key = (string)($item['rel'] ?? $item['name'] ?? '');
            if ($key !== '' && isset($by_key[$key])) {
                $known[$by_key[$key]] = $item;
                continue;
            }
            if ($key !== '') {
                $by_key[$key] = count($known);
            }
            $known[] = $item;
        }
        update_option(self::pending_option((int)$quiz->id), array_slice($known, -50), false);
        // Недоставленное не должно ждать, пока организатор нажмёт кнопку или
        // выгрузит протоколы: планируем повтор с растущей паузой.
        self::schedule_retry($quiz);
    }

    /** Всё доставлено — список недоставленного больше не нужен. */
    private static function clear_pending(Quiz $quiz): void
    {
        if (self::pending($quiz)) {
            delete_option(self::pending_option((int)$quiz->id));
        }
        if (self::pending($quiz) === []) {
            self::clear_retry($quiz);
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
            self::clear_retry($quiz);
        }
    }

    // ------------------------------------------------- автоматическая повторная доставка

    /** Запись планировщика повторных доставок для олимпиады. */
    public static function retry_option(int $quiz_id): string
    {
        return 'wpo_attachment_retry_' . $quiz_id;
    }

    /**
     * Состояние автоповтора: сколько попыток сделано, когда следующая и с какой паузой.
     *
     * @return array{tries:int,next:int,last:int,lazy:int,delay:int}
     */
    public static function retry_state(Quiz $quiz): array
    {
        $data = get_option(self::retry_option((int)$quiz->id), []);
        if (!is_array($data)) {
            $data = [];
        }

        return [
            'tries' => max(0, (int)($data['tries'] ?? 0)),
            'next'  => (int)($data['next'] ?? 0),
            'last'  => (int)($data['last'] ?? 0),
            'lazy'  => (int)($data['lazy'] ?? 0),
            'delay' => (int)($data['delay'] ?? self::RETRY_FLOOR),
        ];
    }

    /** Пауза перед следующей попыткой: 5, 10, 20, 40 … минут, но не больше шести часов. */
    public static function retry_delay(Quiz $quiz): int
    {
        $tries = self::retry_state($quiz)['tries'];
        // 0 отказов подряд → 5 минут, 1 → 10, 2 → 20 … и не дольше шести часов.
        $delay = (int)min(self::RETRY_CEIL, self::RETRY_FLOOR * (2 ** min(10, max(0, $tries))));

        return max(60, (int)apply_filters('wpo_attachments_retry_delay', $delay, $quiz, $tries));
    }

    /** Запланировать автоматическую попытку донести недоставленные вложения. */
    public static function schedule_retry(Quiz $quiz): void
    {
        $quiz_id = (int)$quiz->id;
        if ($quiz_id <= 0) {
            return;
        }
        $state = self::retry_state($quiz);
        $tries = (int)$state['tries'] + 1;
        $delay = self::retry_delay($quiz);
        $next = time() + $delay;
        update_option(self::retry_option($quiz_id), [
            'tries' => $tries,
            'next'  => $next,
            'last'  => time(),
            // «lazy» — часы админской страницы: отказ транспорта не должен
            // лишать организатора шанса донести работу, когда крон не работает.
            'lazy'  => (int)($state['lazy'] ?? 0),
            'delay' => $delay,
        ], false);

        if (self::cron_available() && !wp_next_scheduled(self::RETRY_HOOK, [$quiz_id])) {
            wp_schedule_single_event($next, self::RETRY_HOOK, [$quiz_id]);
        }
    }

    /** Есть ли у сайта планировщик: без него автоповтор сводится к ленивой пробе. */
    private static function cron_available(): bool
    {
        return function_exists('wp_next_scheduled') && function_exists('wp_schedule_single_event')
            && (!defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON);
    }

    /** Доставлено — планировщик и счётчик пауз больше не нужны. */
    public static function clear_retry(Quiz $quiz): void
    {
        $quiz_id = (int)$quiz->id;
        delete_option(self::retry_option($quiz_id));
        if (self::cron_available()) {
            $when = wp_next_scheduled(self::RETRY_HOOK, [$quiz_id]);
            if ($when !== false) {
                wp_unschedule_event($when, self::RETRY_HOOK, [$quiz_id]);
            }
        }
    }

    /** Опция с результатом проверки (и создания) папки вложений. */
    public static function probe_option(int $quiz_id): string
    {
        return 'wpo_attachment_probe_' . $quiz_id;
    }

    /**
     * Что известно о папке вложений на сетевой папке.
     *
     * @return array{ok:bool,when:int,mode:string,target:string,message:string,codes:array<int,string>,raw:string,fingerprint:string,created:int}
     */
    public static function probe_state(Quiz $quiz): array
    {
        $data = get_option(self::probe_option((int)$quiz->id), []);
        if (!is_array($data)) {
            $data = [];
        }

        return [
            'ok'          => !empty($data['ok']),
            'when'        => (int)($data['when'] ?? 0),
            'mode'        => (string)($data['mode'] ?? ''),
            'target'      => (string)($data['target'] ?? ''),
            'message'     => (string)($data['message'] ?? ''),
            'codes'       => array_values(array_map('strval', (array)($data['codes'] ?? []))),
            'raw'         => (string)($data['raw'] ?? ''),
            'fingerprint' => (string)($data['fingerprint'] ?? ''),
            'created'     => (int)($data['created'] ?? 0),
        ];
    }

    /**
     * Создать на сетевой папке каталог вложений и сказать, как это вышло.
     *
     * Транспорт строит каталоги только когда везёт файлы, поэтому без этой
     * пробы папка «вложения» появляется на шаре в момент первой удачной сдачи —
     * а организатору нужно видеть результат сразу после настройки пути.
     * Успешную пробу повторяем раз в {@see PROBE_OK_TTL}, неудачную — раз в
     * {@see PROBE_FAIL_FLOOR}; $force обходит паузу (кнопка, сохранение настроек).
     *
     * @return array<string,mixed>
     */
    public static function provision_share_root(Quiz $quiz, bool $force = false): array
    {
        $store = self::store($quiz);
        $config = (array)($store['config'] ?? []);
        $path = trim((string)($config['path'] ?? ''));
        if ($path === '' || self::looks_like_example(NetworkExport::resolve_local_path($path))) {
            return [
                'ok' => false, 'when' => 0, 'skipped' => 'no_config', 'created' => 0,
                'mode' => '', 'target' => '', 'codes' => [], 'raw' => '',
                'message' => __('Сетевая папка для олимпиады не задана.', 'wp-olympiad'),
                'fingerprint' => '',
            ];
        }

        $fingerprint = md5(NetworkExport::detect_type($config) . '|' . $path . '|' . (string)($config['user'] ?? ''));
        $stored = self::probe_state($quiz);
        $ttl = $stored['ok'] ? self::PROBE_OK_TTL : self::PROBE_FAIL_FLOOR;
        if (!$force && $stored['when'] > 0 && $stored['fingerprint'] === $fingerprint && time() - $stored['when'] < $ttl) {
            return $stored + ['skipped' => 'fresh'];
        }

        if ($store['mode'] === 'share') {
            // Путь доступен PHP как папка: share_path() уже создал «вложения/<олимпиада>».
            $share = (string)$store['share'];
            $res = [
                'success' => $share !== '' && is_dir($share) && is_writable($share),
                'created' => 0,
                'mode'    => 'filesystem',
                'target'  => $share,
                'message' => '',
                'codes'   => [],
                'raw'     => '',
            ];
            if (!$res['success']) {
                $res['message'] = sprintf(
                    /* translators: 1: путь */
                    __('Папка вложений «%s» недоступна для записи: проверьте права на сетевой папке.', 'wp-olympiad'),
                    $share
                );
            }
        } else {
            $res = NetworkExport::ensure_remote_dir(self::DIR . '/' . (string)$store['slug'], $config);
        }

        $record = [
            'ok'          => !empty($res['success']),
            'when'        => time(),
            'created'     => (int)($res['created'] ?? 0),
            'mode'        => (string)($res['mode'] ?? ''),
            'target'      => (string)($res['target'] ?? ''),
            'message'     => (string)($res['message'] ?? ''),
            'codes'       => array_values(array_map('strval', (array)($res['codes'] ?? []))),
            'raw'         => mb_substr(trim((string)($res['raw'] ?? '')), 0, 900),
            'fingerprint' => $fingerprint,
        ];
        update_option(self::probe_option((int)$quiz->id), $record, false);

        return $record;
    }

    /**
     * Проба после сохранения настроек: имеет смысл только если в олимпиаде есть
     * задания «прикрепить файл» — иначе папку вложений никто не наполнит.
     *
     * @return array<string,mixed>
     */
    public static function provision_after_save(Quiz $quiz): array
    {
        global $wpdb;
        $table = DB::questions_table();
        $with_files = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE quiz_id = %d AND question_type = %s",
            (int)$quiz->id,
            self::TYPE
        ));
        if ($with_files === 0) {
            return ['ok' => false, 'when' => 0, 'skipped' => 'no_file_questions', 'created' => 0,
                'mode' => '', 'target' => '', 'codes' => [], 'raw' => '', 'message' => '', 'fingerprint' => ''];
        }

        return self::provision_share_root($quiz, true);
    }

    /** Обработчик события планировщика. */
    public static function handle_retry_event(int $quiz_id): array
    {
        $quiz = $quiz_id > 0 ? Quiz::get($quiz_id) : null;
        if ($quiz === null) {
            delete_option(self::retry_option($quiz_id));
            delete_option(self::probe_option($quiz_id));

            return ['skipped' => 'no_quiz', 'sent' => 0, 'pending' => 0];
        }
        if (self::pending($quiz) === []) {
            self::clear_retry($quiz);

            return ['skipped' => 'clean', 'sent' => 0, 'pending' => 0];
        }
        if (!self::cron_available()) {
            return ['skipped' => 'no_cron', 'sent' => 0, 'pending' => count(self::pending($quiz))];
        }
        $result = self::retry_pending($quiz);
        if (self::pending($quiz) !== []) {
            // Отказ уже поставил в очередь новую попытку (это делает remember_pending);
            // здесь добавляем её только если причин для ожидания больше нет.
            self::schedule_retry($quiz);
        } else {
            self::clear_retry($quiz);
        }

        return $result;
    }

    /**
     * Донести всё, что числится недоставленным, не сбрасывая отметок о доставленном.
     *
     * В отличие от «Доставить сейчас» (redeliver) этот проход не переписывает то,
     * что на шаре уже лежит: автоматическая синхронизация не должна плодить записи.
     */
    public static function retry_pending(Quiz $quiz): array
    {
        $result = ['sent' => 0, 'missing' => 0, 'already' => 0, 'skipped' => 0, 'message' => '', 'codes' => [], 'raw' => '', 'names' => [], 'pending' => 0];
        $buckets = [];
        foreach (self::pending($quiz) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $bucket = (string)($item['bucket'] ?? '');
            if (preg_match('/^[a-f0-9]{16}$/', $bucket)) {
                $buckets[] = $bucket;
            }
        }
        if (!$buckets) {
            // Строки прежних версий без корзины: берём все папки попыток хранилища.
            $root = (string)(self::store($quiz)['root'] ?? '');
            if ($root !== '') {
                foreach (glob(rtrim($root, '/\\') . '/*') ?: [] as $dir) {
                    if (is_dir($dir) && preg_match('/^[a-f0-9]{16}$/', basename($dir))) {
                        $buckets[] = basename($dir);
                    }
                }
            }
        }

        foreach (array_values(array_unique($buckets)) as $bucket) {
            $dir = self::bucket_dir($quiz, $bucket);
            if (is_wp_error($dir)) {
                $result['missing']++;
                continue;
            }
            $ids = [];
            foreach (self::read_index($dir) as $id => $entry) {
                $rel = (string)($entry['rel'] ?? '');
                if ($rel !== '' && !self::is_delivered($quiz, $rel)) {
                    $ids[] = (string)$id;
                }
            }
            if (!$ids) {
                continue;
            }
            $sent = self::flush_to_share($quiz, $bucket, $ids);
            $result['sent'] += (int)($sent['copied'] ?? 0);
            $result['missing'] += (int)($sent['missing'] ?? 0);
            $result['already'] += (int)($sent['already'] ?? 0);
            foreach (['message', 'raw'] as $key) {
                if ((string)($sent[$key] ?? '') !== '') {
                    $result[$key] = (string)$sent[$key];
                }
            }
            $result['codes'] = array_values(array_unique(array_merge($result['codes'], array_map('strval', (array)($sent['codes'] ?? [])))));
            $result['names'] = array_values(array_unique(array_merge($result['names'], array_map('strval', (array)($sent['names'] ?? [])))));
        }

        // Списку недоставленного можно доверять только вместе с отметками: файл мог
        // уйти на шару, а строка остаться (обрезанный вывод транспорта) — чистим её.
        $rest = self::pending($quiz);
        $result['pending'] = count($rest);
        if ($result['sent'] > 0 && !$rest) {
            self::clear_retry($quiz);
        }
        self::remember_delivery($quiz, $result + ['mode' => 'auto']);

        return $result;
    }

    /**
     * Ленивая синхронизация: организатор открыл страницу олимпиады — плагин
     * пробует донести недоставленное, если с прошлой пробы прошло достаточно.
     *
     * @return array<string,mixed> пустой массив — проходить нечем или рано
     */
    public static function sync_pending_now(int $quiz_id, bool $force = false): array
    {
        $quiz = $quiz_id > 0 ? Quiz::get($quiz_id) : null;
        if ($quiz === null) {
            return [];
        }
        if (self::pending($quiz) === []) {
            self::clear_retry($quiz);

            return [];
        }
        $state = self::retry_state($quiz);
        if (!$force && $state['lazy'] > 0 && time() - $state['lazy'] < self::RETRY_FLOOR) {
            return ['skipped' => 'cooldown', 'sent' => 0, 'pending' => count(self::pending($quiz))];
        }
        update_option(self::retry_option($quiz_id), array_merge($state, [
            'tries' => (int)($state['tries'] ?? 0),
            'lazy'  => time(),
        ]), false);

        return self::retry_pending($quiz);
    }

    /** Точка входа для админской страницы (без тяжёлых проверок на каждом хите). */
    public static function maybe_sync_on_admin(): void
    {
        if (!is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }
        if (defined('WP_CLI') && WP_CLI) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- только чтение query-аргументов страницы
        if (!isset($_GET['page']) || sanitize_key(wp_unslash((string)$_GET['page'])) !== 'wp-olympiad-edit') {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $quiz_id = isset($_GET['quiz_id']) ? (int)$_GET['quiz_id'] : 0;
        if ($quiz_id <= 0) {
            return;
        }
        $result = self::sync_pending_now($quiz_id);
        // О недоставленном transporte уже сказано выше; здесь папка: если её
        // ещё не удавалось создать, проба повторится не чаще, чем раз в 5 минут.
        if (empty($result['skipped'])) {
            $quiz = Quiz::get($quiz_id);
            if ($quiz !== null) {
                self::provision_share_root($quiz);
            }
        }
    }
}
