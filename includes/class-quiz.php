<?php
declare(strict_types=1);

namespace WPOlympiad;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Quiz / Olympiad Model & Data Access
 */
final class Quiz
{
    private static array $runtime_cache = [];

    public int $id = 0;
    public string $title = '';
    public string $slug = '';
    public string $description = '';
    public array $settings = [];
    public string $status = 'publish';
    public string $created_at = '';
    public string $updated_at = '';

    public function __construct(array $data = [])
    {
        if (!empty($data)) {
            $this->id = (int)($data['id'] ?? 0);
            $this->title = (string)($data['title'] ?? '');
            $this->slug = (string)($data['slug'] ?? '');
            $this->description = (string)($data['description'] ?? '');
            $this->status = (string)($data['status'] ?? 'publish');
            $this->created_at = (string)($data['created_at'] ?? '');
            $this->updated_at = (string)($data['updated_at'] ?? '');

            if (isset($data['settings'])) {
                if (is_array($data['settings'])) {
                    $this->settings = $data['settings'];
                } else {
                    $decoded = json_decode((string)$data['settings'], true);
                    $this->settings = is_array($decoded) ? $decoded : [];
                }
            }
        }
        $this->settings = self::without_threshold_settings($this->settings);
        self::normalize_custom_settings($this->settings);
    }

    /** Legacy percentage thresholds/classification are no longer settings. */
    public static function without_threshold_settings(array $settings): array
    {
        unset($settings['pass_percentage'], $settings['show_pass_pct'], $settings['grading_scales']);
        return $settings;
    }

    public static function default_settings(): array
    {
        return [
            'time_limit'          => 45,        // Minutes (0 = unlimited)
            'start_time'          => '',        // Y-m-d H:i
            'end_time'            => '',        // Y-m-d H:i
            'max_attempts'        => 1,         // 1 = strict olympiad, 0 = unlimited
            'rules_strict_for_admins' => 0,    // 1 = apply schedule and attempt limits to organizers too
            'show_schedule_to_participants' => 0, // 1 = print start/end on the participant page
            'shuffle_questions'   => 0,         // 1 = random order
            'shuffle_answers'     => 1,         // 1 = shuffle choices
            'show_question_points'=> 1,         // 1 = show points per question, 0 = hide points during test
            'shared_texts'        => [],        // Shared texts/passages: [['id'=>'st_1','title'=>'...','content'=>'...'], ...]
            'allow_guest'         => 1,         // 1 = allow non-registered participants
            'require_org'         => 1,         // 1 = require school/grade/org
            'require_email'       => 1,         // 1 = require email
            'show_results_mode'   => 'immediate', // immediate | score_only | after_end | manual | none
            'completion_message'  => 'Спасибо за участие! Ваши ответы успешно приняты и сохранены в системе.',
            'anti_cheat_copy'     => 1,         // 1 = disable copy & right click
            'anti_cheat_blur'     => 1,         // 1 = track tab switches
            'max_tab_switches'    => 5,         // 0 = just log, >0 = limit
            'anti_cheat_popup_warnings' => 1,   // 1 = show popup toast & modal warnings on anti-cheat triggers
            'allow_external_embed'=> 1,         // 1 = allow iframe embedding on external sites
            'link_alias'          => '',        // Human readable name used in the public runner link instead of the numeric id
            'webhook_url'         => '',        // External webhook URL for automated results dispatch
            'webhook_secret'      => '',        // HMAC secret for X-WPO-Signature
            'webhook_log'         => [],        // Last webhook deliveries: [['id'=>..., 'ok'=>true, ...], ...]

            // Incoming REST API (integration by per-quiz key)
            'api_enabled'         => 0,         // 1 = allow /external/* requests with X-API-Key
            'api_key'             => '',        // Plain key shown to administrators
            'api_key_hash'        => '',        // sha256 hash used for lookup
            'api_key_created'     => '',        // Y-m-d H:i:s when the key was issued
            'email_participant'   => 0,         // Deprecated: olympiad emails are disabled
            'email_admin'         => 0,         // Deprecated: olympiad emails are disabled
            'admin_email_to'      => '',        // Deprecated recipient retained for data compatibility

            // Network Folder Connection & Auto-Export/Import Settings
            'enable_network_export'        => 0,
            'network_folder_type'          => 'auto',
            'network_folder_path'          => '',
            'network_folder_user'          => '',
            'network_folder_password'      => '',
            'network_auto_export'          => 0,
            'network_auto_import'          => 0,        // 1 = auto sync/import questions from network folder
            'network_auto_import_mode'     => 'append', // append | replace
            'network_auto_import_interval' => 'every_minute', // every_minute | hourly | twicedaily | daily (legacy always supported)
            'network_auto_import_file'     => '',       // specific filename or empty for auto-detect
            'last_network_import'          => [],       // last import audit record
            'last_network_import_hash'     => '',       // md5 hash of imported content
            'last_network_import_time'     => 0,        // last successful changed-file import
            'last_network_check_time'      => 0,        // start of the last actual check, including unchanged/error
            'last_network_check'           => [],       // check result / trigger, distinct from import audit

            // Start / Introductory Page Customization
            'enable_intro_page'        => 1,    // 1 = show intro page with info/form, 0 = direct start
            'intro_badge'              => 'Олимпиада / Тестирование',
            'intro_title'              => '',   // empty = use quiz title
            'intro_banner_url'         => '',   // Optional cover image
            'intro_button_text'        => '🚀 Начать выполнение олимпиады',
            'show_meta_grid'           => 1,    // 1 = show timer, questions count, max score, pass pct
            'show_time_limit'          => 1,
            'show_questions_count'     => 1,
            'show_points'              => 1,

            // Registration Form Fields on Start Page
            'field_name_label'         => 'ФИО Участника:',
            'field_name_placeholder'   => 'Иванов Иван Иванович',
            'field_name_required'      => 1,

            'show_org'                 => 1,
            'require_org'              => 1,
            'field_org_label'          => 'Организация / Школа / Класс:',
            'field_org_placeholder'    => 'Лицей №1, 10 «А» класс',

            'show_email'               => 1,
            'require_email'            => 1,
            'field_email_label'        => 'Контактный E-mail:',
            'field_email_placeholder'  => 'name@example.com',

            'show_phone'               => 0,
            'require_phone'            => 0,
            'field_phone_label'        => 'Номер телефона:',
            'field_phone_placeholder'  => '+7 (999) 000-00-00',

            'show_city'                => 0,
            'require_city'             => 0,
            'field_city_label'         => 'Город / Населённый пункт:',
            'field_city_placeholder'   => 'Москва',

            'require_agreement'        => 0,
            'agreement_text'           => 'Я подтверждаю корректность введённых данных и согласен с правилами проведения олимпиады.',

            // 1.0.21: дополнительные поля анкеты и вывод вопросов по группам.
            'custom_fields'            => [],
            'question_groups'          => [],
            'questions_per_page'       => 0,

            'show_anti_cheat_notice'   => 1,
            'anti_cheat_notice_text'   => '🛡️ Внимание! Во время тестирования ведётся контроль переключения вкладок и активности окна. Пожалуйста, не закрывайте страницу до завершения.',

        ];
    }

    /** Normalize only an explicit guest-access control; absence means preserve. */
    public static function guest_access_patch(array $form): array
    {
        $marker = $form['wpo_guest_access_present'] ?? null;
        if ($marker !== 1 && $marker !== '1' && !array_key_exists('allow_guest', $form)) return [];
        $raw = $form['allow_guest'] ?? 0;
        if (!is_scalar($raw) && $raw !== null) return [];
        $value = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $value === null ? [] : ['allow_guest' => $value ? 1 : 0];
    }

    /**
     * Legacy neutral completion label; retained for PHP integrations
     *
     * @return array{label: string, message: string}
     */
    public function get_grade_info(float $percentage): array
    {
        // Compatibility only: no score-based threshold or rank classification.
        return ['label' => __('Завершено', 'wp-olympiad'), 'message' => ''];
    }

    /**
     * Replace template placeholder tags (%NAME%, %SCORE%, %GRADE%, etc.)
     */
    public function replace_template_tags(string $text, Attempt $attempt): string
    {
        $m = floor($attempt->duration_seconds / 60);
        $s = $attempt->duration_seconds % 60;
        $timer_str = sprintf('%02d:%02d', $m, $s);

        $date_str = !empty($attempt->submitted_at) ? date_i18n('d.m.Y', strtotime($attempt->submitted_at)) : date_i18n('d.m.Y');

        $replacements = [
            '%NAME%'         => esc_html($attempt->participant_name),
            '%EMAIL%'        => esc_html($attempt->participant_email),
            '%ORG%'          => esc_html($attempt->participant_org),
            '%SCORE%'        => sprintf('%d', round($attempt->score)),
            '%MAX_SCORE%'    => sprintf('%d', round($attempt->max_score)),
            '%PERCENTAGE%'   => sprintf('%d', round($attempt->percentage)),
            '%QUIZ_NAME%'    => esc_html($this->title),
            '%TIMER%'        => $timer_str,
            '%GRADE%'        => esc_html($attempt->get_status_label()),
            '%DATE%'         => $date_str,
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $text);
    }

    /**
     * Get direct external link to standalone Olympiad runner
     *
     * The link carries the human readable name (settings «link_alias») when the
     * administrator has chosen one, and the numeric olympiad id otherwise, so
     * links distributed before a name existed keep working untouched.
     */
    public function get_direct_url(): string
    {
        $alias = $this->get_link_alias();

        return add_query_arg(['wpo_runner' => $alias !== '' ? $alias : $this->id], home_url('/'));
    }

    /** Allowed characters for a public link name (ASCII, lowercase, dash safe). */
    public const LINK_ALIAS_PATTERN = '/^[a-z0-9][a-z0-9-]{0,47}$/';
    public const LINK_ALIAS_MAX_LENGTH = 48;

    /** Transliteration used so that a Russian olympiad name yields a readable URL part. */
    private const LINK_ALIAS_TRANSLIT = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e',
        'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
        'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
        'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch',
        'ъ' => '', 'ь' => '', 'ы' => 'y', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
    ];

    /**
     * Turn any administrator input into a safe link name: lowercase, Latin,
     * single dashes, no leading/trailing dash, capped at LINK_ALIAS_MAX_LENGTH.
     */
    public static function normalize_link_alias($raw): string
    {
        if (!is_scalar($raw)) {
            return '';
        }

        $raw = trim((string)$raw);
        if ($raw === '') {
            return '';
        }

        $lower = strtr(mb_strtolower($raw, 'UTF-8'), self::LINK_ALIAS_TRANSLIT);
        $clean = preg_replace('/[^a-z0-9]+/', '-', $lower) ?? '';
        $clean = trim($clean, '-');
        if (strlen($clean) > self::LINK_ALIAS_MAX_LENGTH) {
            $clean = rtrim(substr($clean, 0, self::LINK_ALIAS_MAX_LENGTH), '-');
        }

        return $clean;
    }

    /**
     * Keep a stored link name normalized, no matter who wrote the settings
     * (settings form, REST API or a targeted patch), so the LIKE-based lookup
     * below always finds the alias that get_direct_url() prints.
     */
    private static function normalize_settings_link_alias(array &$settings): void
    {
        if (array_key_exists('link_alias', $settings)) {
            $settings['link_alias'] = self::normalize_link_alias($settings['link_alias']);
        }
    }

    /** Public link name of this olympiad; '' means the link uses the numeric id. */
    public function get_link_alias(): string
    {
        $alias = self::normalize_link_alias((string)$this->get_setting('link_alias', ''));

        return preg_match(self::LINK_ALIAS_PATTERN, $alias) ? $alias : '';
    }

    /**
     * Validate raw administrator input and return [normalized alias, error code].
     *
     * Input that consists only of unusable characters is reported as invalid
     * rather than accepted as an empty value, so a typo cannot silently delete the
     * name an administrator has already chosen.
     *
     * @return array{0:string,1:string}
     */
    public static function sanitize_link_alias($raw, int $exclude_quiz_id = 0): array
    {
        $alias = self::normalize_link_alias($raw);
        if ($alias === '' && trim((string)(is_scalar($raw) ? $raw : '')) !== '') {
            return ['', 'invalid'];
        }

        return [$alias, self::link_alias_error($alias, $exclude_quiz_id)];
    }

    /**
     * Validation result for a link name: '' = acceptable.
     *
     * @return string '' | 'invalid' | 'numeric' | 'taken'
     */
    public static function link_alias_error(string $alias, int $exclude_quiz_id = 0): string
    {
        if ($alias === '') {
            return '';
        }
        if (!preg_match(self::LINK_ALIAS_PATTERN, $alias)) {
            return 'invalid';
        }
        // Digits alone would be ambiguous with the numeric olympiad id already in circulation.
        if (strspn($alias, '0123456789') === strlen($alias)) {
            return 'numeric';
        }

        return self::link_alias_taken($alias, $exclude_quiz_id) ? 'taken' : '';
    }

    /** Whether another olympiad already uses this public link name. */
    public static function link_alias_taken(string $alias, int $exclude_quiz_id = 0): bool
    {
        if ($alias === '') {
            return false;
        }

        global $wpdb;
        $table = DB::quizzes_table();
        $like = '%"link_alias":"' . $wpdb->esc_like($alias) . '"%';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, settings FROM {$table} WHERE settings LIKE %s", $like));
        if (!is_array($rows)) {
            return false;
        }

        foreach ($rows as $row) {
            if ($exclude_quiz_id > 0 && (int)$row->id === $exclude_quiz_id) {
                continue;
            }
            $settings = json_decode((string)$row->settings, true);
            if (!is_array($settings)) {
                continue;
            }
            if (self::normalize_link_alias((string)($settings['link_alias'] ?? '')) === $alias) {
                return true;
            }
        }

        return false;
    }

    /** Find an olympiad by its public link name. */
    public static function get_by_link_alias(string $alias): ?self
    {
        if ($alias === '') {
            return null;
        }

        global $wpdb;
        $table = DB::quizzes_table();
        $like = '%"link_alias":"' . $wpdb->esc_like($alias) . '"%';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id FROM {$table} WHERE settings LIKE %s", $like));
        if (!is_array($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            $quiz = self::get((int)$row->id);
            if ($quiz && $quiz->get_link_alias() === $alias) {
                return $quiz;
            }
        }

        return null;
    }

    /**
     * Resolve the value of the wpo_runner / wpo_embed parameter: an olympiad id
     * (the shape every link created before 1.0.17 uses) or a link name.
     */
    public static function resolve_public_link($raw): ?self
    {
        if (!is_scalar($raw)) {
            return null;
        }

        $key = trim((string)$raw);
        if ($key === '' || strlen($key) > 96 || !preg_match('/^[0-9a-zA-Z_-]+$/', $key)) {
            return null;
        }
        if (preg_match('/^\d{1,10}$/', $key)) {
            return self::get((int)$key);
        }

        $quiz = self::get_by_link_alias($key);
        if ($quiz) {
            return $quiz;
        }

        // Введённое вручную имя может быть в верхнем регистре или с пробелами.
        $normalized = self::normalize_link_alias($key);

        return ($normalized !== '' && $normalized !== $key) ? self::get_by_link_alias($normalized) : null;
    }

    /**
     * Get HTML iframe embed code for external websites
     */
    public function get_embed_code(int $height = 800): string
    {
        $url = esc_url($this->get_direct_url());
        return sprintf(
            '<iframe src="%s" width="100%%" height="%d" frameborder="0" allow="fullscreen" style="border:none;border-radius:12px;box-shadow:0 4px 12px rgba(0,0,0,0.08);"></iframe>',
            $url,
            $height
        );
    }

    public function get_setting(string $key, mixed $default = null): mixed
    {
        $defaults = self::default_settings();
        if (array_key_exists($key, $this->settings)) {
            return $this->settings[$key];
        }
        return $defaults[$key] ?? $default;
    }

    /**
     * Действующий ключ API олимпиады.
     */
    public function get_api_key(): string
    {
        return (string)$this->get_setting('api_key', '');
    }

    /**
     * Есть ли у олимпиады выпущенный ключ API.
     */
    public function has_api_key(): bool
    {
        return $this->get_api_key() !== '';
    }

    /**
     * Маска ключа для интерфейса администратора.
     */
    public function get_api_key_masked(): string
    {
        return Api::mask($this->get_api_key());
    }

    /**
     * Выпустить новый ключ API (и включить API олимпиады).
     */
    public function generate_api_key(bool $force_new = true): string
    {
        $existing = $this->get_api_key();
        if (!$force_new && $existing !== '') {
            return $existing;
        }

        $key = Api::generate_key();
        $this->update_patch([
            'api_key'         => $key,
            'api_key_hash'    => Api::hash_key($key),
            'api_key_created' => current_time('mysql'),
            'api_enabled'     => 1,
        ]);

        return $key;
    }

    /**
     * Отозвать ключ API — все внешние запросы сразу перестают работать.
     */
    public function revoke_api_key(): bool
    {
        return $this->update_patch([
            'api_key'         => '',
            'api_key_hash'    => '',
            'api_key_created' => '',
            'api_enabled'     => 0,
        ]);
    }

    /**
     * Секрет подписи исходящих webhook-событий.
     */
    public function get_webhook_secret(): string
    {
        return (string)$this->get_setting('webhook_secret', '');
    }

    /**
     * Get array of all shared texts configured for this quiz
     */
    /**
     * Чистка новых настроек 1.0.21: поля анкеты и группы вопросов.
     *
     * Вызывается на записи и на чтении, чтобы ни одна дорожка (REST, Ajax,
     * импорт настроек) не сохранила «сырое» значение.
     */
    public static function normalize_custom_settings(array &$settings): void
    {
        if (array_key_exists('custom_fields', $settings)) {
            $settings['custom_fields'] = ParticipantFields::sanitize_defs((array)$settings['custom_fields']);
        }
        if (array_key_exists('question_groups', $settings)) {
            $settings['question_groups'] = QuestionGroups::sanitize_defs((array)$settings['question_groups']);
        }
        if (array_key_exists('questions_per_page', $settings)) {
            $per_page = (int)$settings['questions_per_page'];
            $settings['questions_per_page'] = $per_page > 0 ? min(50, $per_page) : 0;
        }
    }

    /** Дополнительные поля анкеты: описания, готовые к рендеру и проверке. */
    public function get_custom_fields(): array
    {
        return ParticipantFields::sanitize_defs((array)($this->settings['custom_fields'] ?? []));
    }

    /** Настройки групп (разделов) вопросов этой олимпиады. */
    public function get_question_groups(): array
    {
        return QuestionGroups::sanitize_defs((array)($this->settings['question_groups'] ?? []));
    }

    public function has_question_groups(): bool
    {
        return $this->get_question_groups() !== [];
    }

    /** Сколько вопросов показывать на одной странице; 0 — по одному. */
    public function get_questions_per_page(): int
    {
        $per_page = (int)($this->settings['questions_per_page'] ?? 0);

        return $per_page > 0 ? min(50, $per_page) : 0;
    }

    public function get_shared_texts(): array
    {
        $texts = $this->get_setting('shared_texts', []);
        return is_array($texts) ? $texts : [];
    }

    /**
     * Get a specific shared text by ID
     */
    public function get_shared_text(string $id): ?array
    {
        if (empty($id)) {
            return null;
        }
        foreach ($this->get_shared_texts() as $item) {
            if (isset($item['id']) && (string)$item['id'] === $id) {
                return $item;
            }
        }
        return null;
    }

    /**
     * Update shared texts collection for this quiz
     */
    public function save_shared_texts(array $texts): bool
    {
        $clean = [];
        foreach ($texts as $idx => $t) {
            $tid = sanitize_key($t['id'] ?? 'st_' . ($idx + 1));
            $title = sanitize_text_field($t['title'] ?? '');
            $content = wp_kses_post($t['content'] ?? '');
            if ($title === '' && $content === '') {
                continue;
            }
            $clean[] = [
                'id'      => $tid,
                'title'   => $title ?: sprintf(__('Общий текст #%d', 'wp-olympiad'), $idx + 1),
                'content' => $content,
            ];
        }

        $new_settings = $this->settings;
        $new_settings['shared_texts'] = $clean;
        return $this->update(['settings' => $new_settings]);
    }

    public static function get(int $id): ?self
    {
        if (isset(self::$runtime_cache[$id])) {
            return self::$runtime_cache[$id];
        }

        global $wpdb;
        $table = DB::quizzes_table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);
        if ($row) {
            self::$runtime_cache[$id] = new self($row);
            return self::$runtime_cache[$id];
        }
        return null;
    }

    public static function clear_cache(int $id = 0): void
    {
        if ($id > 0) {
            unset(self::$runtime_cache[$id]);
        } else {
            self::$runtime_cache = [];
        }
    }

    public static function get_by_slug(string $slug): ?self
    {
        global $wpdb;
        $table = DB::quizzes_table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE slug = %s", $slug), ARRAY_A);
        return $row ? new self($row) : null;
    }

    /**
     * @return self[]
     */
    public static function all(array $args = []): array
    {
        global $wpdb;
        $table = DB::quizzes_table();
        $status = $args['status'] ?? '';
        $limit = isset($args['limit']) ? (int)$args['limit'] : 100;
        $offset = isset($args['offset']) ? (int)$args['offset'] : 0;

        $where = 'WHERE 1=1';
        $params = [];

        if ($status !== '' && $status !== 'all') {
            $where .= ' AND status = %s';
            $params[] = $status;
        }

        $params[] = $limit;
        $params[] = $offset;

        $sql = "SELECT * FROM {$table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);

        $result = [];
        foreach ($rows as $row) {
            $result[] = new self($row);
        }
        return $result;
    }

    public static function count(string $status = ''): int
    {
        global $wpdb;
        $table = DB::quizzes_table();
        if ($status !== '' && $status !== 'all') {
            return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s", $status));
        }
        return (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }

    public static function create(array $data): int
    {
        global $wpdb;
        $table = DB::quizzes_table();

        $title = sanitize_text_field($data['title'] ?? __('Новая олимпиада', 'wp-olympiad'));
        $slug = sanitize_title($data['slug'] ?? $title);
        if (empty($slug)) {
            $slug = 'olympiad-' . time();
        }
        if (strlen($slug) > 180) {
            $slug = substr($slug, 0, 180);
        }

        // Unique slug check
        $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE slug = %s", $slug));
        if ($exists) {
            $slug = substr($slug, 0, 170) . '-' . wp_generate_password(4, false, false);
        }

        $description = wp_kses_post($data['description'] ?? '');
        $status = sanitize_key($data['status'] ?? 'publish');

        $settings = self::without_threshold_settings(array_merge(self::default_settings(), $data['settings'] ?? []));
        self::normalize_settings_link_alias($settings);
        self::normalize_custom_settings($settings);
        $settings_json = wp_json_encode($settings, JSON_UNESCAPED_UNICODE);

        $now = current_time('mysql');

        $wpdb->insert($table, [
            'title'       => $title,
            'slug'        => $slug,
            'description' => $description,
            'settings'    => $settings_json,
            'status'      => $status,
            'created_at'  => $now,
            'updated_at'  => $now,
        ], ['%s', '%s', '%s', '%s', '%s', '%s', '%s']);

        return (int)$wpdb->insert_id;
    }

    public function update(array $data): bool
    {
        global $wpdb;
        $table = DB::quizzes_table();

        $fields = [];
        $format = [];

        if (isset($data['title'])) {
            $this->title = sanitize_text_field($data['title']);
            $fields['title'] = $this->title;
            $format[] = '%s';
        }
        if (isset($data['slug'])) {
            $this->slug = sanitize_title($data['slug']);
            $fields['slug'] = $this->slug;
            $format[] = '%s';
        }
        if (isset($data['description'])) {
            $this->description = wp_kses_post($data['description']);
            $fields['description'] = $this->description;
            $format[] = '%s';
        }
        if (isset($data['status'])) {
            $this->status = sanitize_key($data['status']);
            $fields['status'] = $this->status;
            $format[] = '%s';
        }
        if (isset($data['settings']) && is_array($data['settings'])) {
            self::normalize_settings_link_alias($data['settings']);
            self::normalize_custom_settings($data['settings']);
            $this->settings = self::without_threshold_settings(array_merge($this->settings, $data['settings']));
            $fields['settings'] = wp_json_encode($this->settings, JSON_UNESCAPED_UNICODE);
            $format[] = '%s';
        }

        $this->updated_at = current_time('mysql');
        $fields['updated_at'] = $this->updated_at;
        $format[] = '%s';

        $res = $wpdb->update($table, $fields, ['id' => $this->id], $format, ['%d']);
        self::clear_cache($this->id);
        return $res !== false;
    }

    public function update_setting(string $key, mixed $val): bool
    {
        return $this->update_patch([$key => $val]);
    }

    /**
     * Точечно обновить отдельные настройки, не затирая параллельные изменения.
     *
     * В отличие от update(), здесь свежие настройки перечитываются из БД и
     * поверх них применяется только патч. Это защищает журнал webhook,
     * отметки авто-импорта и секреты от «отката» устаревшим объектом Quiz.
     */
    public function update_patch(array $patch): bool
    {
        global $wpdb;
        $table = DB::quizzes_table();

        $current_json = $wpdb->get_var($wpdb->prepare(
            "SELECT settings FROM {$table} WHERE id = %d",
            $this->id
        ));

        $settings = $this->settings;
        if ($current_json !== null) {
            $decoded = json_decode((string)$current_json, true);
            if (is_array($decoded)) {
                $settings = $decoded;
            }
        }

        self::normalize_settings_link_alias($patch);
        self::normalize_custom_settings($patch);
        foreach ($patch as $key => $value) {
            $settings[$key] = $value;
        }
        $settings = self::without_threshold_settings($settings);

        $res = $wpdb->update(
            $table,
            [
                'settings'   => wp_json_encode($settings, JSON_UNESCAPED_UNICODE),
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $this->id],
            ['%s', '%s'],
            ['%d']
        );

        if ($res !== false) {
            $this->settings = $settings;
            self::clear_cache($this->id);
        }

        return $res !== false;
    }

    public static function delete(int $id): bool
    {
        global $wpdb;
        self::clear_cache($id);

        // Cascade delete questions, attempts, responses
        $wpdb->query($wpdb->prepare(
            "DELETE r FROM " . DB::responses_table() . " r " .
            "INNER JOIN " . DB::attempts_table() . " a ON r.attempt_id = a.id " .
            "WHERE a.quiz_id = %d",
            $id
        ));
        $wpdb->delete(DB::attempts_table(), ['quiz_id' => $id], ['%d']);
        $wpdb->delete(DB::questions_table(), ['quiz_id' => $id], ['%d']);
        $res = $wpdb->delete(DB::quizzes_table(), ['id' => $id], ['%d']);

        return $res !== false;
    }

    /**
     * Get all questions belonging to this quiz
     *
     * @return Question[]
     */
    public function get_questions(bool $shuffle = false): array
    {
        return Question::get_by_quiz($this->id, $shuffle);
    }

    /**
     * Calculate total maximum score
     */
    public function get_total_points(): int
    {
        global $wpdb;
        $table = DB::questions_table();
        $total = $wpdb->get_var($wpdb->prepare("SELECT SUM(points) FROM {$table} WHERE quiz_id = %d", $this->id));
        return (int)round((float)($total ?? 0));
    }

    /**
     * Check if Olympiad is currently open based on start/end dates
     *
     * Organizers who may manage the site are allowed to open a closed olympiad for
     * checking, but that bypass is now visible: the result carries «admin_preview»
     * and the reason a participant would see. Pass $strict = true (or turn on the
     * «strict rules» setting) to evaluate the rules exactly as for a participant.
     *
     * @return array{active: bool, reason: string, code: string, admin_preview: bool, blocked_reason?: string}
     */
    public function is_active_now(bool $strict = false): array
    {
        $bounds = $this->schedule_boundaries();
        $now = new \DateTimeImmutable('now', $bounds['tz']);

        $block = null;

        if ($this->status !== 'publish' && $this->status !== 'published') {
            $block = [
                'code'   => 'not_published',
                'reason' => __('Олимпиада в настоящее время не опубликована.', 'wp-olympiad'),
            ];
        } elseif ($bounds['start'] !== null && $now < $bounds['start']) {
            $block = [
                'code'   => 'before_start',
                // Точное время участнику не сообщается: организатор сообщает его сам.
                'reason' => __('Олимпиада ещё не началась — вход откроет организатор.', 'wp-olympiad'),
            ];
        } elseif ($bounds['end'] !== null && $now > $bounds['end']) {
            $block = [
                'code'   => 'after_end',
                'reason' => __('Олимпиада завершена. Приём ответов закрыт.', 'wp-olympiad'),
            ];
        }

        if ($block === null) {
            return ['active' => true, 'reason' => '', 'code' => '', 'admin_preview' => false];
        }

        if (!$strict && $this->allows_organizer_bypass() && is_user_logged_in() && current_user_can('manage_options')) {
            return [
                'active'         => true,
                'reason'         => '',
                'code'           => $block['code'],
                'admin_preview'  => true,
                'blocked_reason' => $block['reason'],
            ];
        }

        return ['active' => false, 'reason' => $block['reason'], 'code' => $block['code'], 'admin_preview' => false];
    }

    /**
     * Parsed schedule boundaries in the site timezone.
     *
     * A value that cannot be parsed is ignored instead of blocking everybody — legacy
     * rows may hold free text. The settings form refuses to store such values since
     * 1.0.18, so normally every stored schedule is canonical «Y-m-d H:i».
     *
     * @return array{tz: \DateTimeZone, start: ?\DateTimeImmutable, end: ?\DateTimeImmutable}
     */
    public function schedule_boundaries(): array
    {
        $tz = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');

        $parse = static function ($raw) use ($tz): ?\DateTimeImmutable {
            $raw = trim((string)$raw);
            if ($raw === '') {
                return null;
            }
            try {
                return new \DateTimeImmutable($raw, $tz);
            } catch (\Throwable $e) {
                return null;
            }
        };

        return [
            'tz'    => $tz,
            'start' => $parse($this->get_setting('start_time', '')),
            'end'   => $parse($this->get_setting('end_time', '')),
        ];
    }

    /** Whether schedule and attempt limits are enforced for organizers as well. */
    public function rules_strict_for_admins(): bool
    {
        return (bool)$this->get_setting('rules_strict_for_admins', 0);
    }

    /**
     * Whether the participant page prints the start and the acceptance deadline.
     * Hidden by default: the schedule is compared anyway, it is simply not shown.
     */
    public function shows_schedule_to_participants(): bool
    {
        return (bool)$this->get_setting('show_schedule_to_participants', 0);
    }

    /**
     * Расписание строкой для организатора: на странице олимпиады оно участнику
     * не показывается, но тому, кто его задавал, полезно видеть его сразу.
     */
    public function schedule_organizer_line(): string
    {
        $bounds = $this->schedule_boundaries();
        $parts = [];

        if ($bounds['start'] instanceof \DateTimeImmutable) {
            $parts[] = sprintf(
                // translators: 1: date and time
                __('начало %1$s', 'wp-olympiad'),
                $bounds['start']->format('d.m.Y H:i')
            );
        }

        if ($bounds['end'] instanceof \DateTimeImmutable) {
            $parts[] = sprintf(
                // translators: 1: date and time
                __('приём ответов до %1$s', 'wp-olympiad'),
                $bounds['end']->format('d.m.Y H:i')
            );
        }

        if (!$parts) {
            return '';
        }

        return sprintf(
            // translators: 1: schedule parts, 2: timezone label
            __('Расписание сайта: %1$s (%2$s).', 'wp-olympiad'),
            implode(', ', $parts),
            self::schedule_timezone_label()
        );
    }

    /** Bypass of the rules for people who manage the site (preview of the page). */
    public function allows_organizer_bypass(): bool
    {
        return !$this->rules_strict_for_admins() && !self::is_participant_preview();
    }

    /**
     * The «see it as a participant» switch used on the olympiad page. Only honored
     * for administrators, so for everybody else the request is a no-op.
     */
    public static function is_participant_preview(): bool
    {
        if (!isset($_GET['wpo_as_participant']) || !is_scalar($_GET['wpo_as_participant'])) {
            return false;
        }
        if ((string)$_GET['wpo_as_participant'] !== '1') {
            return false;
        }

        return is_user_logged_in() && current_user_can('manage_options');
    }

    /**
     * Timezone the schedule is measured in, spelled the way an organizer reads it.
     *
     * The form labels used to promise «МСК» unconditionally, while the plugin has
     * always compared the times with the *site* timezone — that mismatch is what
     * made a schedule look ignored.
     */
    public static function schedule_timezone_label(): string
    {
        $tz = function_exists('wp_timezone_string') ? wp_timezone_string() : 'UTC';
        if ($tz === 'Europe/Moscow') {
            return __('МСК', 'wp-olympiad');
        }
        if ($tz === 'UTC') {
            return 'UTC';
        }

        $offset = (new \DateTimeZone($tz))->getOffset(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $sign = $offset < 0 ? '-' : '+';

        return sprintf('%s (UTC%s%d)', $tz, $sign, (int)(abs($offset) / 3600));
    }

    /**
     * Canonical form of a schedule value typed by an administrator.
     *
     * Accepts «Y-m-d H:i[:s]», «Y-m-d», «d.m.Y [H:i]» and the ISO "T" separator.
     * A date without a time is the beginning of that day — or its last minute for
     * the end of the olympiad, so «2026-10-31» no longer closes the contest at 00:00.
     * Slash formats (10/11/2026) are rejected instead of being read the American way.
     *
     * @return string|null canonical «Y-m-d H:i», '' when empty, null when unusable
     */
    public static function normalize_schedule_time($raw, bool $end_of_day = false): ?string
    {
        if (!is_scalar($raw)) {
            return '';
        }

        $value = trim((string)$raw);
        if ($value === '') {
            return '';
        }

        $year = $month = $day = 0;
        $hour = null;
        $minute = null;

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T](\d{1,2}):(\d{2})(?::\d{2})?)?$/', $value, $m)) {
            $year = (int)$m[1];
            $month = (int)$m[2];
            $day = (int)$m[3];
            if (isset($m[4]) && $m[4] !== '') {
                $hour = (int)$m[4];
                $minute = (int)$m[5];
            }
        } elseif (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})(?: (\d{1,2}):(\d{2})(?::\d{2})?)?$/', $value, $m)) {
            $day = (int)$m[1];
            $month = (int)$m[2];
            $year = (int)$m[3];
            if (isset($m[4]) && $m[4] !== '') {
                $hour = (int)$m[4];
                $minute = (int)$m[5];
            }
        } else {
            return null;
        }

        if (!checkdate($month, $day, $year)) {
            return null;
        }

        if ($hour === null) {
            return $end_of_day ? sprintf('%04d-%02d-%02d 23:59', $year, $month, $day)
                               : sprintf('%04d-%02d-%02d 00:00', $year, $month, $day);
        }

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return sprintf('%04d-%02d-%02d %02d:%02d', $year, $month, $day, $hour, $minute);
    }

    /**
     * Check if a participant can attempt this quiz (based on max_attempts)
     *
     * @return array{allowed: bool, reason: string, count: int, admin_preview: bool, blocked_reason?: string}
     */
    public function can_user_attempt(int $user_id, string $email = '', bool $strict = false): array
    {
        $max_attempts = (int)$this->get_setting('max_attempts', 1);
        if ($max_attempts <= 0) {
            return ['allowed' => true, 'reason' => '', 'count' => 0, 'admin_preview' => false];
        }

        global $wpdb;
        $table = DB::attempts_table();

        $count = 0;
        if ($user_id > 0) {
            $count = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE quiz_id = %d AND user_id = %d AND status IN ('completed', 'needs_review', 'graded')",
                $this->id,
                $user_id
            ));
        } elseif (!empty($email)) {
            $count = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE quiz_id = %d AND participant_email = %s AND status IN ('completed', 'needs_review', 'graded')",
                $this->id,
                $email
            ));
        }

        if ($count < $max_attempts) {
            return ['allowed' => true, 'reason' => '', 'count' => $count, 'admin_preview' => false];
        }

        $reason = sprintf(
            __('Вы исчерпали лимит попыток для этой олимпиады (максимум: %d).', 'wp-olympiad'),
            $max_attempts
        );

        // Как и с расписанием, организатор может посмотреть страницу сверх лимита,
        // но обход больше не незаметен, а строгий режим отключает его.
        if (!$strict && $this->allows_organizer_bypass() && $user_id > 0 && user_can($user_id, 'manage_options')) {
            return [
                'allowed'        => true,
                'reason'         => '',
                'count'          => $count,
                'admin_preview'  => true,
                'blocked_reason' => $reason,
            ];
        }

        return ['allowed' => false, 'reason' => $reason, 'count' => $count, 'admin_preview' => false];
    }
}
