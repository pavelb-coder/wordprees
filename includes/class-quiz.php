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
    }

    public static function default_settings(): array
    {
        return [
            'time_limit'          => 45,        // Minutes (0 = unlimited)
            'start_time'          => '',        // Y-m-d H:i
            'end_time'            => '',        // Y-m-d H:i
            'pass_percentage'     => 60.0,      // Minimum passing %
            'max_attempts'        => 1,         // 1 = strict olympiad, 0 = unlimited
            'shuffle_questions'   => 0,         // 1 = random order
            'shuffle_answers'     => 1,         // 1 = shuffle choices
            'allow_guest'         => 1,         // 1 = allow non-registered participants
            'require_org'         => 1,         // 1 = require school/grade/org
            'require_email'       => 1,         // 1 = require email
            'show_results_mode'   => 'immediate', // immediate | score_only | after_end | manual | none
            'completion_message'  => 'Спасибо за участие! Ваши ответы успешно приняты и сохранены в системе.',
            'anti_cheat_copy'     => 1,         // 1 = disable copy & right click
            'anti_cheat_blur'     => 1,         // 1 = track tab switches
            'max_tab_switches'    => 5,         // 0 = just log, >0 = limit
            'allow_external_embed'=> 1,         // 1 = allow iframe embedding on external sites
            'webhook_url'         => '',        // External webhook URL for automated results dispatch
            'enable_certificate'  => 1,         // 1 = generate printable / downloadable diploma
            'email_participant'   => 1,         // 1 = send results to participant email
            'email_admin'         => 0,         // 1 = send notification to admin
            'admin_email_to'      => '',        // admin email recipient

            // Network Folder Export Settings
            'enable_network_export'   => 0,
            'network_folder_type'     => 'auto',
            'network_folder_path'     => '',
            'network_folder_user'     => '',
            'network_folder_password' => '',
            'network_auto_export'     => 0,

            // Start / Introductory Page Customization
            'intro_badge'              => 'Олимпиада / Тестирование',
            'intro_title'              => '',   // empty = use quiz title
            'intro_banner_url'         => '',   // Optional cover image
            'intro_button_text'        => '🚀 Начать выполнение олимпиады',
            'show_meta_grid'           => 1,    // 1 = show timer, questions count, max score, pass pct
            'show_time_limit'          => 1,
            'show_questions_count'     => 1,
            'show_points'              => 1,
            'show_pass_pct'            => 1,

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

            'show_anti_cheat_notice'   => 1,
            'anti_cheat_notice_text'   => '🛡️ Внимание! Во время тестирования ведётся контроль переключения вкладок и активности окна. Пожалуйста, не закрывайте страницу до завершения.',

            'grading_scales'      => [
                [
                    'min'     => 90,
                    'max'     => 100,
                    'label'   => 'Победитель олимпиады (Диплом I степени)',
                    'message' => 'Поздравляем, %NAME%! Вы продемонстрировали выдающийся результат (%SCORE% из %MAX_SCORE% б., %PERCENTAGE%%) и стали победителем олимпиады!',
                ],
                [
                    'min'     => 75,
                    'max'     => 89,
                    'label'   => 'Призёр олимпиады (Диплом II степени)',
                    'message' => 'Отличная работа, %NAME%! Ваш результат: %SCORE% из %MAX_SCORE% б. (%PERCENTAGE%%). Вы награждаетесь дипломом призёра II степени!',
                ],
                [
                    'min'     => 60,
                    'max'     => 74,
                    'label'   => 'Призёр олимпиады (Диплом III степени)',
                    'message' => 'Хороший результат, %NAME%! Вы набрали %SCORE% из %MAX_SCORE% б. (%PERCENTAGE%%) и стали призёром III степени.',
                ],
                [
                    'min'     => 0,
                    'max'     => 59,
                    'label'   => 'Грамота участника',
                    'message' => 'Благодарим за участие в олимпиаде, %NAME%! Ваш результат: %SCORE% из %MAX_SCORE% б. (%PERCENTAGE%%).',
                ],
            ],
        ];
    }

    /**
     * Get grade / diploma title and message based on percentage
     *
     * @return array{label: string, message: string}
     */
    public function get_grade_info(float $percentage): array
    {
        $scales = (array)$this->get_setting('grading_scales', []);
        $pct = (int)round($percentage);

        foreach ($scales as $scale) {
            $min = (int)($scale['min'] ?? 0);
            $max = (int)($scale['max'] ?? 100);
            if ($pct >= $min && $pct <= $max) {
                return [
                    'label'   => (string)($scale['label'] ?? ''),
                    'message' => (string)($scale['message'] ?? ''),
                ];
            }
        }

        return [
            'label'   => $percentage >= (float)$this->get_setting('pass_percentage', 60.0) ? __('Пройдено', 'wp-olympiad') : __('Не пройдено', 'wp-olympiad'),
            'message' => '',
        ];
    }

    /**
     * Replace template placeholder tags (%NAME%, %SCORE%, %GRADE%, etc.)
     */
    public function replace_template_tags(string $text, Attempt $attempt): string
    {
        $grade_info = $this->get_grade_info($attempt->percentage);
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
            '%GRADE%'        => esc_html($grade_info['label']),
            '%DATE%'         => $date_str,
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $text);
    }

    /**
     * Get direct external link to standalone Olympiad runner
     */
    public function get_direct_url(): string
    {
        return add_query_arg(['wpo_runner' => $this->id], home_url('/'));
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

        // Unique slug check
        $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE slug = %s", $slug));
        if ($exists) {
            $slug .= '-' . wp_generate_password(4, false, false);
        }

        $description = wp_kses_post($data['description'] ?? '');
        $status = sanitize_key($data['status'] ?? 'publish');

        $settings = array_merge(self::default_settings(), $data['settings'] ?? []);
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
            $this->settings = array_merge($this->settings, $data['settings']);
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
     * @return array{active: bool, reason: string}
     */
    public function is_active_now(): array
    {
        if ($this->status !== 'publish') {
            return [
                'active' => false,
                'reason' => __('Олимпиада в настоящее время не опубликована.', 'wp-olympiad'),
            ];
        }

        $tz = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('now', $tz);
        $start_time = (string)$this->get_setting('start_time', '');
        $end_time = (string)$this->get_setting('end_time', '');

        if (!empty($start_time)) {
            try {
                $start_dt = new \DateTimeImmutable($start_time, $tz);
                if ($now < $start_dt) {
                    return [
                        'active' => false,
                        'reason' => sprintf(
                            __('Олимпиада ещё не началась. Время начала: %s (МСК)', 'wp-olympiad'),
                            $start_dt->format('d.m.Y H:i')
                        ),
                    ];
                }
            } catch (\Throwable) {
                // Ignore parse errors
            }
        }

        if (!empty($end_time)) {
            try {
                $end_dt = new \DateTimeImmutable($end_time, $tz);
                if ($now > $end_dt) {
                    return [
                        'active' => false,
                        'reason' => sprintf(
                            __('Олимпиада завершена %s. Приём ответов закрыт.', 'wp-olympiad'),
                            $end_dt->format('d.m.Y H:i')
                        ),
                    ];
                }
            } catch (\Throwable) {
                // Ignore parse errors
            }
        }

        return ['active' => true, 'reason' => ''];
    }

    /**
     * Check if a participant can attempt this quiz (based on max_attempts)
     *
     * @return array{allowed: bool, reason: string, count: int}
     */
    public function can_user_attempt(int $user_id, string $email = ''): array
    {
        $max_attempts = (int)$this->get_setting('max_attempts', 1);
        if ($max_attempts <= 0) {
            return ['allowed' => true, 'reason' => '', 'count' => 0];
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

        if ($count >= $max_attempts) {
            return [
                'allowed' => false,
                'reason'  => sprintf(
                    __('Вы исчерпали лимит попыток для этой олимпиады (максимум: %d).', 'wp-olympiad'),
                    $max_attempts
                ),
                'count'   => $count,
            ];
        }

        return ['allowed' => true, 'reason' => '', 'count' => $count];
    }
}
