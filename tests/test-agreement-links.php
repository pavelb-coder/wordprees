<?php
/**
 * 1.0.31: к согласию участника можно прикрепить ссылки — на правила и на
 * согласие на обработку персональных данных.
 *
 * Номера проверок 215–218; ожидания для фильтра адресов взяты из фактического
 * поведения esc_url_raw на стенде, а не из предположения.
 */

namespace WPOlympiad\Tests;

use WPOlympiad\Quiz;
use WPOlympiad\Shortcodes;

if (!defined('ABSPATH') || !isset($it)) return;

$al = static function (bool $ok, string $message, string $extra = ''): void {
    if (!$ok) {
        throw new \RuntimeException($extra !== '' ? $message . ' — ' . $extra : $message);
    }
};

$al_create = static function (array $settings) {
    $token = substr(md5(uniqid('al', true)), 0, 8);
    return (int)Quiz::create([
        'title'    => 'Ссылки согласия ' . $token,
        'status'   => 'publish',
        'settings' => $settings + ['max_attempts' => 0, 'require_org' => 0],
    ]);
};

$it('1.0.31: под чекбоксом согласия выводятся прикреплённые ссылки', function () use ($al, $al_create): bool {
    $quiz_id = $al_create([
        'enable_intro_page'      => 1,
        'require_agreement'      => 1,
        'agreement_text'         => 'Я согласен с регламентом и обработкой данных',
        'agreement_rules_url'    => 'https://example.invalid/rules',
        'agreement_privacy_url'  => 'https://example.invalid/pd',
        'agreement_privacy_text' => '',
    ]);
    try {
        $html = (string)Shortcodes::render_quiz(['id' => (string)$quiz_id]);
        $al(str_contains($html, 'href="https://example.invalid/rules"'), 'Ссылка на правила не выведена');
        $al(str_contains($html, 'href="https://example.invalid/pd"'), 'Ссылка на обработку данных не выведена');
        $al(str_contains($html, '>Полный текст правил олимпиады</a>'), 'Подпись по умолчанию для правил не подставилась');
        $al(str_contains($html, '>Согласие на обработку персональных данных</a>'), 'Подпись по умолчанию для обработки данных не подставилась');
        $al(substr_count($html, 'target="_blank"') === 2, 'Не у всех ссылок открытие в новой вкладке', (string)substr_count($html, 'target="_blank"'));
        $al(substr_count($html, 'rel="noopener noreferrer"') === 2, 'Ссылки отдаются без rel="noopener noreferrer"');

        // Ссылки обязаны стоять ВНЕ подписи чекбокса: иначе клик по ссылке
        // снимал бы галочку согласия.
        $sign_pos = strpos($html, 'Я согласен с регламентом и обработкой данных');
        $links_pos = strpos($html, 'wpo-agreement-links');
        $close_pos = $links_pos === false ? false : strrpos(substr($html, 0, $links_pos), '</label>');
        $al($sign_pos !== false && $links_pos !== false && $close_pos !== false
            && $sign_pos < $close_pos && $close_pos < $links_pos,
            'Блок ссылок стоит не сразу после подписи чекбокса');
        $al(!str_contains(substr($html, (int)$links_pos, 900), '<label'), 'Внутри блока ссылок оказался <label>');

        // Своя подпись важнее подстановки; теги из неё удалены, кавычки экранированы.
        Quiz::get($quiz_id)->update(['settings' => [
            'enable_intro_page'      => 1,
            'require_agreement'      => 1,
            'agreement_rules_url'    => 'https://example.invalid/rules',
            'agreement_rules_text'   => 'Правила <script>alert(1)</script>',
            'agreement_privacy_url'  => 'https://example.invalid/pd',
            'agreement_privacy_text' => 'Согласие "на обработку"',
        ]]);
        $html2 = (string)Shortcodes::render_quiz(['id' => (string)$quiz_id]);
        $al(str_contains($html2, '>Правила</a>'), 'Своя подпись правил не применена');
        $al(!str_contains($html2, '<script>alert(1)</script>'), 'В подпись ссылки прошёл script');
        $al(str_contains($html2, 'Согласие &quot;на обработку&quot;</a>'), 'Кавычки в подписи не экранированы');

        // Ни одной заполненной ссылки — ни одного <a> в блоке согласия.
        Quiz::get($quiz_id)->update(['settings' => [
            'enable_intro_page'     => 1,
            'require_agreement'     => 1,
            'agreement_rules_url'   => '',
            'agreement_privacy_url' => '',
        ]]);
        $html3 = (string)Shortcodes::render_quiz(['id' => (string)$quiz_id]);
        $al(!str_contains($html3, 'wpo-agreement-links'), 'Пустые ссылки вывели блок');
        $al(str_contains($html3, 'name="agreement"'), 'Исчез сам чекбокс согласия');
        // Байт-нейтральность: без ссылок между подписью чекбокса и закрывающим div
        // не должно появиться ни одной лишней строки.
        $al(str_contains($html3, "</label>\n                            </div>"), 'Разметка без ссылок получила лишние строки');
        return true;
    } finally {
        Quiz::delete($quiz_id);
    }
});

$it('1.0.31: адрес ссылки фильтруется на сохранении и не зависит от дорожки', function () use ($al, $al_create): bool {
    // Ожидаемое снято замером на стенде (wp eval-file /home/user/qa/al-probe.php).
    $cases = [
        ['https://a.ru/x', 'https://a.ru/x'],
        ['  https://a.ru/x  ', 'https://a.ru/x'],
        ['javascript:alert(1)', ''],
        ['JaVaScRiPt:alert(1)', ''],
        ['vbscript:msgbox(1)', ''],
        ['data:text/html,<script>1</script>', ''],
        ['', ''],
        ['mailto:org@example.invalid', 'mailto:org@example.invalid'],
        ['//example.invalid/x', 'https://example.invalid/x'],
        ['example.invalid/rules', 'https://example.invalid/rules'],
        ['HTTPS://A.RU/Y', 'https://A.RU/Y'],
        ['https://a.ru/x?a=1&b=2#h', 'https://a.ru/x?a=1&b=2#h'],
        ['http://a.ru', 'http://a.ru'],
    ];
    foreach ($cases as [$in, $want]) {
        $got = Quiz::normalize_agreement_link($in, '')['url'];
        $al($got === $want, 'Адрес ' . var_export($in, true) . ' стал ' . var_export($got, true) . ', ожидалось ' . var_export($want, true));
    }
    $al(Quiz::normalize_agreement_link('https://a.ru', '  <b>Текст</b>  ')['text'] === 'Текст', 'Из подписи не удалены теги');
    $al(Quiz::normalize_agreement_link('', 'Текст без адреса')['url'] === '', 'Пустой адрес перестал быть пустым');

    // Опасный адрес не должен пережить ни создание, ни обновление настроек.
    $quiz_id = $al_create([
        'require_agreement'    => 1,
        'agreement_rules_url'  => 'javascript:document.write(1)',
        'agreement_privacy_url' => 'https://example.invalid/pd',
    ]);
    try {
        $quiz = Quiz::get($quiz_id);
        $al((string)$quiz->get_setting('agreement_rules_url', 'нет') === '', 'Quiz::create() сохранил опасный адрес', (string)$quiz->get_setting('agreement_rules_url'));
        $links = $quiz->get_agreement_links();
        $al(count($links) === 1 && $links[0]['kind'] === 'privacy', 'get_agreement_links() отдаёт лишнее', json_encode($links, JSON_UNESCAPED_UNICODE));

        $quiz->update(['settings' => [
            'require_agreement'    => 1,
            'agreement_rules_url'  => 'example.invalid/rules',
            'agreement_privacy_url' => '',
        ]]);
        $links = Quiz::get($quiz_id)->get_agreement_links();
        $al(count($links) === 1 && $links[0]['url'] === 'https://example.invalid/rules', 'Quiz::update() не привёл адрес к одному виду', json_encode($links, JSON_UNESCAPED_UNICODE));

        // Олимпиада до 1.0.31 никаких ключей ссылок не имеет — пустой список без ошибок.
        $plain = $al_create(['require_agreement' => 1]);
        try {
            $al(Quiz::get($plain)->get_agreement_links() === [], 'У старой олимпиады ссылки появились из ниоткуда');
            $html = (string)Shortcodes::render_quiz(['id' => (string)$plain]);
            $al(!str_contains($html, 'wpo-agreement-links'), 'Блок ссылок выводится без ссылок');
        } finally {
            Quiz::delete($plain);
        }
        return true;
    } finally {
        Quiz::delete($quiz_id);
    }
});

$it('1.0.31: поля ссылок стоят в разделе согласия и привязаны к его переключателю', function () use ($al, $al_create): bool {
    $root = dirname(__DIR__);
    $view = (string)file_get_contents($root . '/admin/views/quiz-edit.php');
    $admin = (string)file_get_contents($root . '/admin/class-admin.php');
    $sc = (string)file_get_contents($root . '/includes/class-shortcodes.php');
    $rest = (string)file_get_contents($root . '/includes/class-rest-api.php');

    // Каждое поле формы — ровно один раз, и все четыре стоят внутри раздела согласия.
    $section_start = strpos($view, 'Требовать согласие с правилами');
    $section_end = strpos($view, 'Дополнительные поля и группы');
    $al($section_start !== false && $section_end !== false && $section_end > $section_start, 'Раздел согласия в форме не найден');
    foreach (['agreement_rules_url', 'agreement_rules_text', 'agreement_privacy_url', 'agreement_privacy_text'] as $name) {
        $al(substr_count($view, 'name="' . $name . '"') === 1, 'Поле ' . $name . ' в форме встречается не один раз');
        $at = strpos($view, 'name="' . $name . '"');
        $al($at !== false && $at > $section_start && $at < $section_end, 'Поле ' . $name . ' стоит вне раздела согласия');
        // Ключ встречается дважды: как записываемая настройка и как обращение к $_POST.
        $al(substr_count($admin, "'" . $name . "'") === 2, 'Ключ ' . $name . ' не проходит через санитайз админки', (string)substr_count($admin, "'" . $name . "'"));
        $al(str_contains($admin, "'" . $name . "'       =>") || preg_match("/'" . preg_quote($name, '/') . "'\s*=>/", $admin) === 1,
            'Ключ ' . $name . ' не записывается в настройки из формы');
    }
    $al(str_contains($admin, "esc_url_raw(trim((string)(\$_POST['agreement_rules_url'] ?? '')))"), 'Адрес правил в админке чистится не общим фильтром');
    $al(substr_count($admin, "esc_url_raw(trim((string)(\$_POST['agreement_privacy_url'] ?? '')))") === 1, 'Адрес обработки данных в админке чистится не общим фильтром');
    $al(substr_count($admin, "sanitize_text_field(\$_POST['agreement_rules_text'] ?? '')") === 1
        && substr_count($admin, "sanitize_text_field(\$_POST['agreement_privacy_text'] ?? '')") === 1,
        'Подписи ссылок в админке не проходят через sanitize_text_field');

    // Форма и внешний API завязаны на один и тот же переключатель — иначе они
    // разъедутся при первом же изменении.
    $al(str_contains($sc, '$agreement_links = $req_agreement ? $quiz->get_agreement_links() : [];'),
        'Шорткод не привязывает ссылки к требованию согласия');
    $al(str_contains($rest, "(bool)\$quiz->get_setting('require_agreement', 0) ? \$quiz->get_agreement_links() : []"),
        'Внешний API не привязывает ссылки к требованию согласия');

    // Поведение: согласие не требуется — ссылок в форме нет, хотя они заполнены.
    $quiz_id = $al_create([
        'enable_intro_page'     => 1,
        'require_agreement'     => 0,
        'agreement_rules_url'   => 'https://example.invalid/rules',
        'agreement_privacy_url' => 'https://example.invalid/pd',
    ]);
    try {
        $html = (string)Shortcodes::render_quiz(['id' => (string)$quiz_id]);
        $al(!str_contains($html, 'wpo-agreement-links'), 'Ссылки показаны там, где согласие не требуется');
        $al(!str_contains($html, 'name="agreement"'), 'Без переключателя остался чекбокс согласия');
        $al(count(Quiz::get($quiz_id)->get_agreement_links()) === 2, 'Заполненные ссылки не сохранены при выключенном согласии');
        return true;
    } finally {
        Quiz::delete($quiz_id);
    }
});

$it('1.0.31: внешний API отдаёт те же ссылки, что и форма участника', function () use ($al, $al_create): bool {
    $quiz_id = $al_create([
        'enable_intro_page'      => 1,
        'require_agreement'      => 1,
        'agreement_rules_url'    => 'example.invalid/rules',
        'agreement_privacy_url'  => 'https://example.invalid/pd',
        'agreement_privacy_text' => 'Разрешение на обработку ПДн',
    ]);
    try {
        $key = Quiz::get($quiz_id)->generate_api_key(true);
        $call = static function (string $method, string $path, ?string $api_key) {
            $req = new \WP_REST_Request($method, '/wp-olympiad/v1' . $path);
            if ($api_key !== null) {
                $req->set_header('X-API-Key', $api_key);
            }
            return \rest_do_request($req);
        };

        $res = $call('GET', '/external/quiz/' . $quiz_id, $key);
        $al($res->get_status() === 200, 'Внешний запрос олимпиады не прошёл', (string)$res->get_status());
        $data = (array)$res->get_data();
        $agreement = $data['participant_fields']['agreement'] ?? null;
        $al(is_array($agreement), 'В контракте исчез блок agreement');
        $al(($agreement['show'] ?? null) === true && ($agreement['required'] ?? null) === true, 'Флаги show/required съехали');
        $links = $agreement['links'] ?? null;
        $al(is_array($links) && count($links) === 2, 'Внешний API отдал не две ссылки', json_encode($links, JSON_UNESCAPED_UNICODE));
        // Адрес без протокола приведён тем же кодом, что и в форме.
        $al(($links[0]['url'] ?? '') === 'https://example.invalid/rules', 'Ссылка на правила не нормализована', json_encode($links[0] ?? [], JSON_UNESCAPED_UNICODE));
        $al(($links[0]['kind'] ?? '') === 'rules', 'Порядок или вид ссылок в контракте изменился');
        $al(($links[0]['text'] ?? '') === 'Полный текст правил олимпиады', 'Внешний API не подставил подпись по умолчанию');
        $al(($links[1]['text'] ?? '') === 'Разрешение на обработку ПДн', 'Своя подпись во внешнем API потерялась');

        // Те же значения, что видит участник в HTML формы.
        $html = (string)Shortcodes::render_quiz(['id' => (string)$quiz_id]);
        $al(str_contains($html, 'href="https://example.invalid/rules"') && str_contains($html, '>Разрешение на обработку ПДн</a>'),
            'Форма и внешний API разошлись в ссылках');

        // Выключенное согласие — пустой список и там, и там.
        Quiz::get($quiz_id)->update(['settings' => ['require_agreement' => 0, 'enable_intro_page' => 1]]);
        $data2 = (array)$call('GET', '/external/quiz/' . $quiz_id, $key)->get_data();
        $al(($data2['participant_fields']['agreement']['links'] ?? ['x']) === [], 'Выключенное согласие всё равно отдаёт ссылки');
        $al((bool)($data2['participant_fields']['agreement']['show'] ?? true) === false, 'show остался истинным при выключенном согласии');
        return true;
    } finally {
        Quiz::delete($quiz_id);
    }
});

