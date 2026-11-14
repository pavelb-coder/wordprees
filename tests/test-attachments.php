<?php
/**
 * 1.0.32: тип задания «прикрепить файл».
 *
 * Проверки 219–225. Хранилище — отдельная папка «вложения» в сетевом
 * каталоге олимпиады; в тестах это временный каталог, выгрузка ответов
 * и протоколы не трогают чужие файлы. Загрузка идёт через те же
 * обработчики REST, что и в браузере.
 */

namespace WPOlympiad\Tests;

use WPOlympiad\Attachments;
use WPOlympiad\Attempt;
use WPOlympiad\Export;
use WPOlympiad\Grader;
use WPOlympiad\ImportTemplate;
use WPOlympiad\NetworkExport;
use WPOlympiad\NetworkImport;
use WPOlympiad\Question;
use WPOlympiad\Quiz;
use WPOlympiad\RestApi;
use WP_REST_Request;

if (!defined('ABSPATH') || !isset($it)) return;

$at_assert = static function (bool $ok, string $message, string $extra = ''): void {
    if (!$ok) {
        throw new \RuntimeException($extra !== '' ? $message . ' — ' . $extra : $message);
    }
};

/**
 * Олимпиада с одним заданием нового типа и сетевой папкой в /tmp.
 *
 * @return array{0:string,1:int,2:int} корень сети, id олимпиады, id задания
 */
$at_fixture = static function (array $settings = [], string $hint = ''): array {
    $root = sys_get_temp_dir() . '/wpo_attachments_' . wp_generate_password(10, false, false);
    wp_mkdir_p($root);
    $id = (int)Quiz::create([
        'title'    => 'Вложения ' . wp_generate_password(6, false, false),
        'status'   => 'publish',
        'settings' => $settings + [
            'network_folder_type' => 'local',
            'network_folder_path' => $root,
            'max_attempts'        => 0,
            'require_org'         => 0,
        ],
    ]);
    $qid = Question::create([
        'quiz_id'       => $id,
        'question_type' => 'file',
        'title'         => 'Прикрепите скан титульной страницы.',
        'points'        => 10,
        'penalty'       => 0,
        'options'       => $hint !== '' ? ['upload_hint' => $hint] : [],
    ]);
    return [$root, $id, (int)$qid];
};

$at_drop = static function (string $root, int $id): void {
    if ($id) {
        Quiz::delete($id);
    }
    if ($root !== '' && is_dir($root)) {
        exec('rm -rf ' . escapeshellarg($root));
    }
};

/** Файл для загрузки: всегда новый путь, потому что accept() перемещает его. */
$at_file = static function (string $kind): string {
    static $n = 0;
    $n++;
    switch ($kind) {
        case 'png':
            $bytes = "\x89PNG\r\n\x1a\n";
            foreach ([['IHDR', "\x00\x00\x00\x02\x00\x00\x00\x02\x08\x02\x00\x00\x00"], ['IDAT', "\x78\x9c\x63\x60\x18\x05\x03\xa7\x00\x00\xf9\x04\x02\x02"], ['IEND', '']] as [$type, $data]) {
                $bytes .= pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
            }
            $path = sys_get_temp_dir() . "/wpo_at_png_$n.png";
            file_put_contents($path, $bytes);
            return $path;
        case 'pdf':
            $body = "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
                . "trailer << /Root 1 0 R /Size 3 >>\n%%EOF\n";
            $path = sys_get_temp_dir() . "/wpo_at_pdf_$n.pdf";
            file_put_contents($path, $body);
            return $path;
        case 'txt':
            $path = sys_get_temp_dir() . "/wpo_at_txt_$n.txt";
            file_put_contents($path, "Решение задачи: 42.\n");
            return $path;
        case 'php':
            $path = sys_get_temp_dir() . "/wpo_at_evil_$n.php";
            file_put_contents($path, "<?php echo 1;\n");
            return $path;
        case 'fake_pdf':
            $path = sys_get_temp_dir() . "/wpo_at_fake_$n.pdf";
            file_put_contents($path, "#!/bin/sh\necho payload\n");
            return $path;
        case 'double':
            $path = sys_get_temp_dir() . "/wpo_at_double_$n.pdf.php";
            file_put_contents($path, "<?php echo 1;\n");
            return $path;
    }
    throw new \RuntimeException('неизвестная заготовка файла: ' . $kind);
};

/** POST-параметры как у fetch: тело формы + $_FILES. */
$at_request = static function (string $route, array $body, ?string $file_path, string $name) {
    if ($file_path !== null) {
        $_FILES['file'] = [
            'name'     => $name,
            'type'     => 'application/octet-stream',
            'tmp_name' => $file_path,
            'error'    => UPLOAD_ERR_OK,
            'size'     => (int)filesize($file_path),
        ];
    } else {
        $_FILES = [];
    }
    $req = new WP_REST_Request('POST', RestApi::NAMESPACE . $route);
    $req->set_body_params($body);
    if ($file_path !== null) {
        $req->set_file_params($_FILES);
    }
    return $req;
};

// ---------------------------------------------------------------------------
$it('1.0.32: тип задания зарегистрирован и доходит до участника', function () use ($at_assert, $at_fixture, $at_drop): bool {
    $types = Question::supported_types();
    $at_assert(isset($types['file']), 'Типа file нет в каталоге Question::supported_types()');
    $at_assert(count($types) === 14, 'Заданий должно стать 14', (string)count($types));

    // Словарь русских названий и пример шаблона — тот же тип.
    $at_assert(ImportTemplate::normalize_type('прикрепить файл') === 'file', 'Русское название типа не разбирается');
    $at_assert(ImportTemplate::normalize_type('вложение') === 'file', 'Синоним «вложение» не разбирается');
    $at_assert(in_array('file', array_column(ImportTemplate::samples(), 'type'), true), 'В шаблоне нет примера нового типа');
    $csv_rows = NetworkImport::parse_questions_content(NetworkImport::get_template_csv(), 'template.csv');
    $at_assert(count($csv_rows) === 14, 'CSV-шаблон отдаёт не 14 строк', (string)count($csv_rows));
    $at_assert(in_array('file', array_column($csv_rows, 'question_type'), true), 'В CSV-шаблоне нет строки file');

    // Лимиты и подсказка уезжают в публичные данные задания.
    [$root, $id, $qid] = $at_fixture(['attachment_max_size_mb' => 7, 'attachment_max_count' => 2], 'Только PDF, до 7 МБ');
    try {
        $data = (array)Question::get($qid)->get_public_data();
        $at_assert(isset($data['limits']['max_bytes']) && $data['limits']['max_bytes'] === 7 * 1048576, 'Публичные лимиты не совпадают с настройками олимпиады', json_encode($data['limits'] ?? null, JSON_UNESCAPED_UNICODE));
        $at_assert((int)($data['limits']['max_count'] ?? 0) === 2, 'Публичный лимит количества файлов неверный');
        $at_assert(in_array('pdf', $data['limits']['exts'] ?? [], true), 'В списке расширений нет pdf');
        $at_assert(($data['upload_hint'] ?? '') === 'Только PDF, до 7 МБ', 'Подсказка задания не дошла до участника');
        $at_assert(($data['limits']['hint'] ?? '') !== '', 'Готовая строка ограничений пуста');

        // Ручная оценка: тип обязан вставать в одну очередь с эссе.
        $src = (string)file_get_contents(WP_OLYMPIAD_PATH . 'includes/class-grader.php');
        $at_assert(str_contains($src, 'Attachments::TYPE'), 'Грейдер не знает про новый тип');
        return true;
    } finally {
        $at_drop($root, $id);
    }
});

$it('1.0.32: лимиты олимпиады нормализуются и зажимаются', function () use ($at_assert, $at_fixture, $at_drop): bool {
    [$root, $id, $qid] = $at_fixture([
        'attachment_max_size_mb' => 5000,   // рывок вверх: потолок 100 МБ
        'attachment_max_count'   => 999,    // рывок вверх: потолок 20 файлов
        'attachment_kinds'       => ['bogus', 'image', 'image'],
    ]);
    try {
        $limits = Attachments::limits(Quiz::get($id));
        $at_assert($limits['attachment_max_size_mb'] === 100, 'Максимальный размер не зажат к 100 МБ', (string)$limits['attachment_max_size_mb']);
        $at_assert($limits['attachment_max_count'] === 20, 'Число файлов не зажато к 20', (string)$limits['attachment_max_count']);
        $at_assert($limits['attachment_kinds'] === ['image'], 'Виды файлов не очищены от мусора и дублей', json_encode($limits['attachment_kinds']));
        $at_assert($limits['exts'] === ['png', 'jpg', 'jpeg', 'webp', 'gif'], 'Из вида «изображения» собран не тот список расширений', json_encode($limits['exts']));

        // Ноль и мусор возвращаются к разумным значениям по умолчанию.
        $zero = Attachments::normalize(['attachment_max_size_mb' => 0, 'attachment_max_count' => -3, 'attachment_kinds' => []]);
        $at_assert($zero['attachment_max_size_mb'] === Attachments::DEFAULT_MAX_SIZE_MB, 'Нулевой лимит размера не стал значением по умолчанию');
        $at_assert($zero['attachment_max_count'] === Attachments::DEFAULT_MAX_COUNT, 'Нулевой лимит количества не стал значением по умолчанию');
        $at_assert($zero['attachment_kinds'] === Attachments::default_kinds(), 'Пустые виды не стали видами по умолчанию');

        // Настройки проходят через Quiz::update() — то есть и через форму, и через сетевой импорт.
        Quiz::get($id)->update(['settings' => ['attachment_max_size_mb' => 3, 'attachment_max_count' => 1]]);
        $limits2 = Attachments::limits(Quiz::get($id));
        $at_assert($limits2['attachment_max_size_mb'] === 3 && $limits2['attachment_max_count'] === 1, 'Лимиты не сохранены через update()');
        // Эффективный потолок учитывает ограничение WordPress.
        $at_assert($limits2['effective_bytes'] <= $limits2['max_bytes'], 'Эффективный размер больше заявленного');
        return true;
    } finally {
        $at_drop($root, $id);
    }
});

$it('1.0.32: вложение принимается, лежит в своей папке и снимается', function () use ($at_assert, $at_fixture, $at_drop, $at_file): bool {
    [$root, $id, $qid] = $at_fixture(['attachment_max_count' => 2]);
    try {
        $quiz = Quiz::get($id);
        $bucket = Attachments::bucket(str_repeat('a', 32));
        $limits = Attachments::limits($quiz);

        $path = $at_file('pdf');
        $entry = Attachments::accept($quiz, $bucket, $qid, [
            'name' => 'решение.pdf', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => (int)filesize($path),
        ], $limits);
        $at_assert(!is_wp_error($entry), 'Валидный PDF не принят', is_wp_error($entry) ? $entry->get_error_message() : 'нет записи');
        $file = is_array($entry) ? $entry : [];
        $at_assert(($file['name'] ?? '') === 'решение.pdf', 'Имя файла потеряно');
        $at_assert(preg_match('#^' . preg_quote($bucket, '#') . '/[a-f0-9]{24}\.pdf$#', (string)($file['rel'] ?? '')) === 1, 'Файл лежит не в папке попытки и не под случайным именем', (string)($file['rel'] ?? '') . ' / ' . $bucket);

        $abs = Attachments::resolve($quiz, (string)$file['rel']);
        $at_assert(!is_wp_error($abs) && is_file((string)$abs), 'Файл не найден по rel');
        $norm = str_replace(chr(92), '/', (string)$abs);
        $at_assert(str_contains($norm, '/вложения/'), 'Файл сохранён вне папки вложений', $norm);
        $at_assert(substr($norm, -strlen('/' . $file['rel'])) === '/' . $file['rel'], 'rel не соответствует месту на диске', $norm);
        $at_assert(str_contains($norm, '/' . $bucket . '/'), 'Файл лежит не в подпапке попытки', $norm);
        $at_assert(!is_dir($root . '/wp-content') && strpos((string)$abs, $root) === 0, 'Хранилище вышло за пределы сетевой папки', (string)$abs);

        // Повторный приём обязан породить новый файл, а не перезаписать.
        $second = Attachments::accept($quiz, $bucket, $qid, [
            'name' => 'решение.pdf', 'tmp_name' => $at_file('pdf'), 'error' => UPLOAD_ERR_OK, 'size' => 10,
        ], $limits);
        $at_assert(!is_wp_error($second) && $second['rel'] !== $file['rel'], 'Второе вложение перезаписало первое');

        $listed = Attachments::list_for($quiz, $bucket, $qid);
        $at_assert(count($listed) === 2, 'В списке не два файла', (string)count($listed));

        $at_assert(Attachments::remove($quiz, $bucket, (string)$file['id'], $qid) === true, 'Файл не удаляется по id');
        $at_assert(!is_file((string)$abs), 'Файл остался на диске после удаления');
        $at_assert(Attachments::remove($quiz, $bucket, (string)$file['id'], $qid) === false, 'Повторное удаление выглядит успешным');
        $at_assert(count(Attachments::list_for($quiz, $bucket, $qid)) === 1, 'Индекс после удаления не сократился');

        // Третий файл — за пределом лимита в два.
        $limits_two = $limits;
        $limits_two['attachment_max_count'] = 1;
        $over = Attachments::accept($quiz, $bucket, $qid, [
            'name' => 'третий.pdf', 'tmp_name' => $at_file('pdf'), 'error' => UPLOAD_ERR_OK, 'size' => 10,
        ], $limits_two);
        $at_assert(is_wp_error($over) && $over->get_error_code() === 'wpo_upload_count', 'Лимит количества файлов не сработал');
        return true;
    } finally {
        $at_drop($root, $id);
    }
});

$it('1.0.32: мусор, скрипты, поддельная сигнатура и размер отбиваются', function () use ($at_assert, $at_fixture, $at_drop, $at_file): bool {
    [$root, $id, $qid] = $at_fixture(['attachment_max_size_mb' => 1]);
    try {
        $quiz = Quiz::get($id);
        $bucket = Attachments::bucket(str_repeat('b', 32));
        $limits = Attachments::limits($quiz);
        $code_of = static function ($result): string {
            return is_wp_error($result) ? $result->get_error_code() : 'accepted';
        };

        $php = Attachments::accept($quiz, $bucket, $qid, ['name' => 'shell.php', 'tmp_name' => $at_file('php'), 'error' => UPLOAD_ERR_OK, 'size' => 16], $limits);
        $at_assert($code_of($php) === 'wpo_upload_type', 'PHP-файл принят', $code_of($php));

        $double = Attachments::accept($quiz, $bucket, $qid, ['name' => 'work.pdf.php', 'tmp_name' => $at_file('double'), 'error' => UPLOAD_ERR_OK, 'size' => 16], $limits);
        $at_assert($code_of($double) !== 'accepted', 'Файл с двойным расширением принят', $code_of($double));

        $text = Attachments::accept($quiz, $bucket, $qid, ['name' => 'page.html', 'tmp_name' => $at_file('php'), 'error' => UPLOAD_ERR_OK, 'size' => 16], $limits);
        $at_assert($code_of($text) === 'wpo_upload_type', 'HTML принимается вопреки белому списку', $code_of($text));

        $fake = Attachments::accept($quiz, $bucket, $qid, ['name' => 'note.pdf', 'tmp_name' => $at_file('fake_pdf'), 'error' => UPLOAD_ERR_OK, 'size' => 24], $limits);
        $at_assert($code_of($fake) === 'wpo_upload_signature', 'Содержимое не проверяется по сигнатуре', $code_of($fake));

        $empty = Attachments::accept($quiz, $bucket, $qid, ['name' => 'zero.pdf', 'tmp_name' => $at_file('pdf'), 'error' => UPLOAD_ERR_OK, 'size' => 0], $limits);
        $at_assert($code_of($empty) === 'wpo_upload_empty', 'Пустой файл принят', $code_of($empty));

        $big_path = $at_file('pdf');
        file_put_contents($big_path, str_repeat('%PDF-1.4 padding ', 200000)); // ~3 МБ при лимите 1 МБ
        $big = Attachments::accept($quiz, $bucket, $qid, ['name' => 'big.pdf', 'tmp_name' => $big_path, 'error' => UPLOAD_ERR_OK, 'size' => (int)filesize($big_path)], $limits);
        $at_assert($code_of($big) === 'wpo_upload_too_large', 'Размер файла не ограничен настройкой олимпиады', $code_of($big));

        $nofile = Attachments::accept($quiz, $bucket, $qid, ['name' => 'x.pdf', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0], $limits);
        $at_assert($code_of($nofile) === 'wpo_upload_error', 'Отсутствующий файл не даёт внятной ошибки', $code_of($nofile));

        // Разрешённый вид «текст» принимает .txt и не принимает чужие расширения.
        $ok_txt = Attachments::accept($quiz, $bucket, $qid, ['name' => 'решение.txt', 'tmp_name' => $at_file('txt'), 'error' => UPLOAD_ERR_OK, 'size' => 24], $limits);
        $at_assert(is_array($ok_txt), 'Обычный TXT отклонён', $code_of($ok_txt));

        // Обход пути в rel не проходит.
        $at_assert(is_wp_error(Attachments::resolve($quiz, '../../etc/passwd')), 'Выход за пределы хранилища разрешён');
        $at_assert(is_wp_error(Attachments::resolve($quiz, 'zz/../../etc/passwd')), 'rel с .. читается');
        return true;
    } finally {
        $at_drop($root, $id);
    }
});

$it('1.0.32: REST-контракт загрузки, списка, удаления и отдачи файла', function () use ($at_assert, $at_fixture, $at_drop, $at_file, $at_request): bool {
    [$root, $id, $qid] = $at_fixture(['attachment_max_count' => 3]);
    try {
        $quiz = Quiz::get($id);
        $attempt = Attempt::start($id, ['name' => 'Загрузчик', 'email' => 'up@example.invalid']);
        $at_assert($attempt instanceof Attempt, 'Попытка для теста не заведена');
        $token = $attempt->token;

        // 1. Загрузка: ответ 201 с описанием файла.
        $up = RestApi::handle_attachment_upload($at_request('/attachment', ['token' => $token, 'question_id' => $qid], $at_file('pdf'), 'скан.pdf'));
        $at_assert(!is_wp_error($up), 'Загрузка через REST отклонена', is_wp_error($up) ? $up->get_error_message() : '');
        $payload = (array)$up->get_data();
        $at_assert($up->get_status() === 201 && !empty($payload['success']) && isset($payload['file']['id']), 'Ответ загрузки не соответствует контракту', json_encode($payload, JSON_UNESCAPED_UNICODE));
        $at_assert((string)$payload['file']['question_id'] === (string)$qid && (int)$payload['file']['size'] > 0, 'В описании файла нет привязки к заданию или размера', json_encode($payload['file'], JSON_UNESCAPED_UNICODE));
        $file_id = (string)$payload['file']['id'];
        $rel = (string)$payload['file']['rel'];

        // 2. Задание другого типа файл не принимает.
        $other_q = Question::create(['quiz_id' => $id, 'question_type' => 'text', 'title' => 'Обычный вопрос', 'points' => 1]);
        $wrong = RestApi::handle_attachment_upload($at_request('/attachment', ['token' => $token, 'question_id' => $other_q], $at_file('pdf'), 'x.pdf'));
        $at_assert(is_wp_error($wrong) && $wrong->get_error_code() === 'wpo_attachment_type', 'Файл принимается в вопрос другого типа');

        // 3. Чужой токен — доступа к загрузке нет.
        $ghost = RestApi::handle_attachment_upload($at_request('/attachment', ['token' => wp_generate_password(32, false, false), 'question_id' => $qid], $at_file('pdf'), 'y.pdf'));
        $at_assert(is_wp_error($ghost) && $ghost->get_error_code() === 'invalid_attempt', 'Загрузка по несуществующему токену прошла');

        // 4. Список и лимиты для клиента.
        $list = RestApi::handle_attachment_list($at_request('/attachment-list', ['token' => $token, 'question_id' => $qid], null, ''));
        $data = (array)$list->get_data();
        $at_assert(!empty($data['success']) && count($data['files'] ?? []) === 1, 'Список файлов не отдаёт загруженное', json_encode($data, JSON_UNESCAPED_UNICODE));
        $at_assert(!empty($data['limits']['exts']) && in_array('pdf', (array)($data['limits']['exts'] ?? []), true), 'Клиент не получил разрешённые расширения');
        $at_assert(($data['limits']['hint'] ?? '') !== '', 'Клиент не получил строку ограничений');
        $at_assert(($data['files'][0]['rel'] ?? '') === $rel && (int)($data['files'][0]['exists'] ?? 0) === 1, 'В списке другой rel или файл «не найден»');
        $at_assert((int)($data['limits']['max_count'] ?? 0) === 3, 'Лимиты в ответе списка не из настроек олимпиады');

        // 5. Удаление по id и повторный вызов.
        $rm = RestApi::handle_attachment_remove($at_request('/attachment-remove', ['token' => $token, 'question_id' => $qid, 'id' => $file_id], null, ''));
        $at_assert(!is_wp_error($rm) && (int)$rm->get_data()['removed'] === 1, 'Удаление через REST не сработало', json_encode(is_wp_error($rm) ? $rm->get_error_message() : $rm->get_data(), JSON_UNESCAPED_UNICODE));
        $again = RestApi::handle_attachment_remove($at_request('/attachment-remove', ['token' => $token, 'question_id' => $qid, 'id' => $file_id], null, ''));
        $at_assert(!is_wp_error($again) && $again->get_status() === 404, 'Повторное удаление не отдаёт 404');

        // 6. Отдача файла: поток завершает процесс, поэтому успешный путь
        // проверяем дочерним PHP (tests/attachment-stream-probe.php), а
        // отказы — глазами гостя (администратору файл доступен без токена).
        $second = RestApi::handle_attachment_upload($at_request('/attachment', ['token' => $token, 'question_id' => $qid], $at_file('pdf'), 'второй.pdf'));
        $at_assert(!is_wp_error($second), 'Второй файл не принят');
        $rel = (string)((array)$second->get_data())['file']['rel'];
        $probe = WP_OLYMPIAD_PATH . 'tests/attachment-stream-probe.php';
        $at_assert(is_file($probe), 'Нет дочерней пробы для отдачи файла', $probe);
        $owner = get_current_user_id();
        wp_set_current_user(0);
        $cmd = sprintf('php %s %s %s %d %s stdout 2>&1',
            escapeshellarg($probe), escapeshellarg(ABSPATH . 'wp-config.php'),
            escapeshellarg($rel), (int)$id, escapeshellarg($token));
        exec($cmd, $lines_out, $code);
        $body = implode("\n", (array)$lines_out);
        $at_assert($code === 0 && preg_match('/WPO-PROBE bytes=(\d+) sha256=([a-f0-9]{64})/', $body, $m) === 1, 'Владелец попытки не получает файл', 'code=' . $code . ' body=' . mb_substr($body, 0, 200));
        $saved_abs = (string)Attachments::resolve($quiz, $rel);
        $at_assert((int)$m[1] === (int)filesize($saved_abs), 'Размер отданного файла не совпадает с сохранённым', $m[1] . ' / ' . (string)filesize($saved_abs));
        $at_assert($m[2] === hash_file('sha256', $saved_abs), 'Отданный файл не тот, что лежит в хранилище');
        $at_assert(str_contains($body, '%PDF-'), 'В потоке нет содержимого файла', mb_substr($body, 0, 120));

        $stranger = new WP_REST_Request('GET', RestApi::NAMESPACE . '/attachment-file');
        $stranger->set_param('rel', $rel);
        $stranger->set_param('quiz_id', (string)$id);
        $denied = RestApi::handle_attachment_file($stranger);
        $at_assert(is_wp_error($denied) && $denied->get_error_code() === 'wpo_forbidden', 'Файл отдаётся без токена попытки');

        $foreign = new WP_REST_Request('GET', RestApi::NAMESPACE . '/attachment-file');
        foreach (['rel' => $rel, 'quiz_id' => (string)$id, 'token' => wp_generate_password(32, false, false), 'name' => 'второй.pdf'] as $k => $v) {
            $foreign->set_param($k, $v);
        }
        $at_assert(is_wp_error(RestApi::handle_attachment_file($foreign)), 'Файл отдаётся участнику другой попытки');

        $climber = new WP_REST_Request('GET', RestApi::NAMESPACE . '/attachment-file');
        foreach (['rel' => '../../wp-config.php', 'quiz_id' => (string)$id, 'token' => $token, 'name' => 'a.pdf'] as $k => $v) {
            $climber->set_param($k, $v);
        }
        $at_assert(is_wp_error(RestApi::handle_attachment_file($climber)), 'Через rel можно выйти из хранилища');
        wp_set_current_user($owner);

        // 7. Сдача через /submit: вложения уходят в ответ, загрузка после — закрыта.
        $submit = new WP_REST_Request('POST', RestApi::NAMESPACE . '/submit');
        $submit->set_body_params(['token' => $token, 'answers' => [$qid => ['files' => [(string)$second->get_data()['file']['id']]]]]);
        $sent = RestApi::handle_submit($submit);
        $at_assert(!is_wp_error($sent), 'Сдача с приложенным файлом отклонена', is_wp_error($sent) ? $sent->get_error_message() : '');
        $after = RestApi::handle_attachment_upload($at_request('/attachment', ['token' => $token, 'question_id' => $qid], $at_file('pdf'), 'после.pdf'));
        $at_assert(is_wp_error($after) && $after->get_error_code() === 'already_submitted', 'Загрузка возможна после сдачи работы');
        return true;
    } finally {
        wp_set_current_user(1);
        unset($quiz);
        $at_drop($root, $id);
    }
});

$it('1.0.32: сдача не засчитывает файл автоматически, лишний id снимается', function () use ($at_assert, $at_fixture, $at_drop, $at_file): bool {
    [$root, $id, $qid] = $at_fixture(['attachment_max_count' => 3]);
    try {
        $quiz = Quiz::get($id);
        $attempt = Attempt::start($id, ['name' => 'Сдатчик', 'email' => 'sub@example.invalid']);
        $bucket = Attachments::bucket($attempt->token);
        $limits = Attachments::limits($quiz);

        $a = Attachments::accept($quiz, $bucket, $qid, ['name' => 'лист1.pdf', 'tmp_name' => $at_file('pdf'), 'error' => UPLOAD_ERR_OK, 'size' => 40], $limits);
        $b = Attachments::accept($quiz, $bucket, $qid, ['name' => 'лист2.pdf', 'tmp_name' => $at_file('pdf'), 'error' => UPLOAD_ERR_OK, 'size' => 40], $limits);
        $at_assert(is_array($a) && is_array($b), 'Файлы для сдачи не приняты');

        // 1) без файла — законный пустой ответ: 0 баллов и никакой ручной проверки.
        $empty = Grader::grade($quiz, $attempt, [], []);
        Grader::save_responses((int)$attempt->id, (array)($empty['responses'] ?? []));
        $at_assert((float)($empty['total_score'] ?? -1) === 0.0, 'Пустая сдача дала баллы', json_encode($empty['total_score'] ?? null));
        $at_assert(empty($empty['has_essay']), 'Сдача без файлов ушла на ручную проверку');
        $at_assert(($empty['status'] ?? '') === 'completed', 'Статус пустой сдачи не completed', (string)($empty['status'] ?? ''));
        $at_assert((int)($empty['responses'][0]['needs_manual_review'] ?? 1) === 0, 'Пустой ответ поставили в очередь ручной проверки');

        // Ответ можно прислать и словарём {files:[...]} — как это делает
        // клиент, — и плоским списком id. Оба варианта одинаковы для сервера.
        $via_dict = Attachments::answer_ids(['files' => [(string)$a['id'], (string)$b['id']]]);
        $at_assert($via_dict === [(string)$a['id'], (string)$b['id']], 'Формат {files:[…]} не разобран', json_encode($via_dict));
        $via_json = Attachments::answer_ids(wp_json_encode(['v' => 1, 'files' => [['id' => $a['id']]]]));
        $at_assert($via_json === [(string)$a['id']], 'JSON с объектами файлов не разобран', json_encode($via_json));

        // 2) с файлами — только ручная проверка, 0 баллов автоматически.
        $kept = [];
        $answers = Attachments::resolve_answers($quiz, $bucket, [$qid => [$a['id'], $b['id'], 'чужой-id']], $kept);
        $at_assert(count($kept) === 2, 'Принятые id собраны неверно', json_encode($kept));
        $decoded = json_decode((string)$answers[$qid], true);
        $at_assert(is_array($decoded) && count($decoded['files'] ?? []) === 2, 'Ответ не превратился в список файлов', json_encode($decoded, JSON_UNESCAPED_UNICODE));
        $at_assert(($decoded['files'][0]['question_id'] ?? 0) === $qid, 'В ответе нет привязки к заданию');
        $at_assert(($decoded['files'][0]['exists'] ?? 0) == 1, 'Файл помечен как отсутствующий');

        $with_files = Grader::grade($quiz, $attempt, $answers, []);
        Grader::save_responses((int)$attempt->id, (array)($with_files['responses'] ?? []));
        $at_assert((float)($with_files['total_score'] ?? -1) === 0.0, 'За факт загрузки начислены баллы', json_encode($with_files['total_score'] ?? null));
        $at_assert((int)($with_files['responses'][0]['needs_manual_review'] ?? 0) === 1, 'Файлы не помечены ручной проверкой');
        $at_assert(!empty($with_files['has_essay']) && ($with_files['status'] ?? '') === 'needs_review', 'Работа с файлами не встала в очередь ручной проверки', json_encode($with_files, JSON_UNESCAPED_UNICODE));

        // 3) прислать текст вместо файлов — нельзя.
        $kept2 = [];
        $hacked = Attachments::resolve_answers($quiz, $bucket, [$qid => 'Я всё решил, поставьте 10'], $kept2);
        $at_assert(!array_key_exists($qid, $hacked), 'Текстовый ответ в задании с файлами сохранился');
        $at_assert($kept2 === [], 'Лишние id попали в очистку каталога');

        // 4) ответ ссылается на чужие id — ответ снимается целиком.
        $kept3 = [];
        $stranger = Attachments::resolve_answers($quiz, Attachments::bucket(str_repeat('c', 32)), [$qid => [$a['id']]], $kept3);
        $at_assert(!array_key_exists($qid, $stranger), 'Чужой bucket принял файлы другой попытки');

        // 5) в протоколе — имя файла, а не JSON.
        // Протокол: ответ обязан быть описaniem файлов, а не JSON и не «—».
        global $wpdb;
        $stored_answer = (string)$wpdb->get_var($wpdb->prepare(
            'SELECT user_answer FROM ' . \WPOlympiad\DB::responses_table() . ' WHERE attempt_id = %d AND question_id = %d',
            (int)$attempt->id, $qid
        ));
        $stored_dec = json_decode($stored_answer, true);
        $at_assert(is_array($stored_dec) && count($stored_dec['files'] ?? []) === 2, 'В базе не два файла', mb_substr($stored_answer, 0, 160));
        $at_assert(!str_contains($stored_answer, 'чужой-id'), 'Посторонний id сохранился в ответе');
        $csv = (string)Export::export_quiz_attempts($id, false);
        $at_assert(str_contains($csv, 'лист1.pdf') && str_contains($csv, 'лист2.pdf'), 'В протоколе нет имён файлов', mb_substr($csv, 0, 240));
        $at_assert(str_contains($csv, 'лист2.pdf'), 'Второй файл пропал из протокола');
        $at_assert(!str_contains($csv, '"v":1'), 'В протоколе остался сырой JSON вложений');


        // 6) purge: непривязанные файлы удаляются, из ответа — нет.
        $purged = Attachments::purge_unreferenced($quiz, $bucket, [(string)$a['id']]);
        $at_assert($purged === 1, 'Незадействованное вложение не удалено', (string)$purged);
        $still = Attachments::list_for($quiz, $bucket, $qid);
        $at_assert(count($still) === 1 && (string)$still[0]['id'] === (string)$a['id'], 'Purge задел файл, на который есть ссылка');
        return true;
    } finally {
        $at_drop($root, $id);
    }
});

$it('1.0.32: сетевая выгрузка копирует файлы, протоколы и шаблоны не ломаются', function () use ($at_assert, $at_fixture, $at_drop, $at_file): bool {
    [$root, $id, $qid] = $at_fixture(['attachment_max_count' => 4]);
    try {
        $quiz = Quiz::get($id);
        $attempt = Attempt::start($id, ['name' => 'Выгрузчик', 'email' => 'net@example.invalid', 'participant_class' => '11А']);
        $bucket = Attachments::bucket($attempt->token);
        $limits = Attachments::limits($quiz);
        $ok = Attachments::accept($quiz, $bucket, $qid, ['name' => 'работа.pdf', 'tmp_name' => $at_file('pdf'), 'error' => UPLOAD_ERR_OK, 'size' => 40], $limits);
        $lost = Attachments::accept($quiz, $bucket, $qid, ['name' => 'пропал.pdf', 'tmp_name' => $at_file('pdf'), 'error' => UPLOAD_ERR_OK, 'size' => 40], $limits);
        $at_assert(is_array($ok) && is_array($lost), 'Файлы для выгрузки не приняты');
        // Сдача — обычным POST /submit: он и подставляет метаданные
        // файлов, и подчищает неиспользованное.
        $submit = new WP_REST_Request('POST', RestApi::NAMESPACE . '/submit');
        $submit->set_body_params(['token' => $attempt->token, 'answers' => [$qid => [$ok['id'], $lost['id']]]]);
        $sent = RestApi::handle_submit($submit);
        $at_assert(!is_wp_error($sent), 'Сдача с вложениями отклонена', is_wp_error($sent) ? $sent->get_error_message() : '');

        $rows = Attachments::rows_for_quiz($quiz);
        $at_assert(count($rows) === 1 && count($rows[0]['files']) === 2, 'Для пакета собран не тот набор файлов', json_encode(array_map(static fn($r) => count($r['files']), $rows)));
        $at_assert((int)($rows[0]['files'][0]['question_id'] ?? 0) === $qid, 'Файл в пакете не привязан к заданию');
        $at_assert((string)$rows[0]['attempt_ref'] === $bucket, 'bucket не совпал с папкой попытки');

        // Файл, которого на диске нет, обязан быть посчитан как пропавший.
        $rows_with_ghost = $rows;
        $rows_with_ghost[] = ['attempt_ref' => $bucket, 'files' => [['rel' => $bucket . '/000000000000000000000000.pdf', 'name' => 'нет-такого.pdf']]];
        $report = Attachments::export_to($quiz, $rows_with_ghost, $root . '/протоколы');
        $at_assert(($report['copied'] ?? 0) === 2, 'Скопировано не то число файлов', json_encode($report));
        $at_assert(($report['missing'] ?? 0) === 1, 'Пропажа файла не учтена', json_encode($report));
        $at_assert(is_file($report['dir'] . '/' . $bucket . '/работа.pdf'), 'Файл не лёг в пакет по ожидаемому пути', (string)$report['dir']);
        $at_assert(hash_file('sha256', $report['dir'] . '/' . $bucket . '/работа.pdf') === (string)$ok['sha256'], 'Скопированный файл не совпадает с оригиналом');

        // Полный прогон выгрузки ответов: вложения рядом с протоколами, старые файлы целы.
        file_put_contents($root . '/протоколы/сводный_протокол.csv', "чужой протокол\n");
        $result = NetworkExport::export_quiz($id, ['type' => 'local', 'path' => $root]);
        $at_assert(!empty($result['success']), 'Сетевая выгрузка не удалась', json_encode($result['message'] ?? [], JSON_UNESCAPED_UNICODE));
        $at_assert(($result['attachments']['copied'] ?? 0) === 2, 'Выгрузка не посчитала вложения', json_encode($result['attachments'] ?? null));
        $at_assert(file_get_contents($root . '/протоколы/сводный_протокол.csv') === "чужой протокол\n", 'Автоочистка тронула чужой протокол');
        $at_assert(is_file((string)Attachments::resolve($quiz, (string)$ok['rel'])), 'Исходное хранилище пострадало при выгрузке');
        $at_assert(count(glob($root . '/протоколы/*.csv')) >= 1, 'Протоколы не на месте');

        // Экспорт ответов отдельного участника: файл отдаётся в описании.
        $single = Export::export_single_attempt((int)$attempt->id, false);
        $at_assert(is_string($single) && str_contains($single, 'работа.pdf'), 'В выгрузке ответов нет имени файла', mb_substr((string)$single, 0, 200));
        $at_assert(!str_contains((string)$single, '"v":1'), 'Выгрузка ответа участника отдаёт сырой JSON');
        return true;
    } finally {
        $at_drop($root, $id);
    }
});

$at_unc_host = '10.200.10.10';
$it('1.0.32: SMB-папка без монтирования — загрузка работает через рабочую папку и доставляется позже', function () use ($at_file, $at_unc_host, $at_assert): bool {
    $id = 0;
    $spool = '';
    try {
        $id = (int)Quiz::create([
            'title'    => 'Вложения SMB ' . wp_generate_password(6, false, false),
            'status'   => 'publish',
            'settings' => [
                // Путь, которого в файловой системе сервера нет: ровно такая папка
                // была на реальном сервере (\\server\share\…).
                'network_folder_type' => 'smb',
                'network_folder_path' => '//' . $at_unc_host . '/kvant/olimp/olympiad-2',
                'max_attempts'        => 0,
                'require_org'         => 0,
            ],
        ]);
        $qid = (int)Question::create([
            'quiz_id'       => $id,
            'question_type' => 'file',
            'title'         => 'Прикрепите решение.',
            'points'        => 10,
            'penalty'       => 0,
            'options'       => [],
        ]);
        $quiz = Quiz::get($id);
        $at_assert($quiz !== null, 'Олимпиада не создана');

        Attachments::reset_store_cache($id);
        $store = Attachments::store($quiz);
        $at_assert($store['mode'] === 'spool', 'Недоступная SMB-папка не переведена в рабочую папку', json_encode($store['mode']));
        $at_assert($store['type'] === 'smb', 'Транспорт определён неверно', (string)$store['type']);
        $at_assert(str_starts_with($store['root'], wp_normalize_path(WP_CONTENT_DIR)), 'Рабочая папка вне wp-content', (string)$store['root']);
        $at_assert(!str_contains($store['root'], '10.200.10.10'), 'Мусорный UNC-путь попал в хранилище', (string)$store['root']);

        // Прежняя ошибка «Не удалось создать папку вложений» исчезла: загрузка проходит.
        $root = Attachments::ensure_root($quiz);
        $at_assert(is_string($root) && is_dir($root), 'Папка вложений не создана', is_wp_error($root) ? $root->get_error_message() : (string)$root);
        $at_assert(is_file($root . '/.htaccess'), 'Рабочая папка не закрыта от листания');
        $spool = (string)$root;

        $attempt = Attempt::start($id, ['name' => 'Федор', 'email' => 'smb@example.invalid', 'participant_class' => '11']);
        $bucket = Attachments::bucket($attempt->token);
        $file = Attachments::accept($quiz, $bucket, $qid, [
            'name'     => 'решение.pdf',
            'tmp_name' => $at_file('pdf'),
            'error'    => UPLOAD_ERR_OK,
            'size'     => 10,
        ], Attachments::limits($quiz));
        $at_assert(is_array($file), 'Файл не принят в рабочую папку', is_wp_error($file) ? $file->get_error_message() : json_encode($file));
        $abs = Attachments::resolve($quiz, (string)$file['rel']);
        $at_assert(!is_wp_error($abs) && is_file((string)$abs), 'Файл не найден в рабочей папке');
        $at_assert(stripos((string)$abs, $at_unc_host) === false, 'Файл ушёл по недоступному пути', (string)$abs);

        // Сдача: недоступный транспорт не роняет её, а оставляет запись о
        // недоставленном (доставку Attempt-обработчик вызывает сам).
        $submit = new WP_REST_Request('POST', RestApi::NAMESPACE . '/submit');
        $submit->set_body_params(['token' => $attempt->token, 'answers' => [$qid => [(string)$file['id']]]]);
        $sent = RestApi::handle_submit($submit);
        $at_assert(!is_wp_error($sent), 'Сдача в режиме spool отклонена', is_wp_error($sent) ? $sent->get_error_message() : '');
        $report = is_wp_error($sent) ? [] : (array)$sent->get_data();
        $pending = Attachments::pending($quiz);
        // smbclient на стенде нет → доставка не могла состояться: файл в списке недоставленного.
        $at_assert(count($pending) === 1, 'Недоставленный файл не учтён', json_encode($pending));
        $at_assert(($pending[0]['error'] ?? '') !== '', 'Причина недоставки не записана');
        $at_assert(basename((string)($pending[0]['rel'] ?? '')) === basename((string)$file['rel']), 'Записан не тот файл', json_encode($pending[0]));
        $at_assert((int)$report['score'] === 0 || !isset($report['score']), 'За факт загрузки начислен балл', json_encode($report));

        // Тот же самый набор, но папка доступна PHP как путь → доставка удаётся,
        // счётчик недоставленного обнуляется, файлы лежат в «вложения/<slug>/<bucket>».
        $local = sys_get_temp_dir() . '/wpo_at_smb_' . wp_generate_password(8, false, false);
        wp_mkdir_p($local);
        $quiz->update(['settings' => ['network_folder_type' => 'local', 'network_folder_path' => $local]]);
        Attachments::reset_store_cache($id);
        $at_assert(Attachments::mode(Quiz::get($id)) === 'share', 'Доступная папка не распознана как прямая');
        $sent2 = Attachments::flush_to_share(Quiz::get($id), $bucket, [(string)$file['id']]);
        $at_assert($sent2['mode'] === 'share', 'Перенос не был выполнен в прямом режиме', json_encode($sent2));
        $at_assert(($sent2['copied'] ?? 0) === 1, 'Файл не доставлен в доступную папку', json_encode($sent2));
        $at_assert(Attachments::pending(Quiz::get($id)) === [], 'Счётчик недоставленного не обнулился');
        $delivered = glob($local . '/вложения/*/*/*') ?: [];
        $at_assert($delivered !== [], 'Доставленный файл не найден в сетевой папке', $local);
        // В хранилище файл лежит под случайным именем — «решение.pdf» только имя для показа.
        $delivered_names = array_map('basename', $delivered);
        $at_assert(in_array(basename((string)$file['rel']), $delivered_names, true), 'Доставлен не тот файл', json_encode($delivered_names));
        $at_assert(hash_file('sha256', $local . '/вложения/' . $store['slug'] . '/' . $bucket . '/' . basename((string)$file['rel'])) === (string)$file['sha256'], 'При доставке содержимое изменилось');

        // Выгрузка протоколов в недоступную папку не падает, а идёт транспортом.
        Attachments::reset_store_cache($id);
        $rows = Attachments::rows_for_quiz(Quiz::get($id));
        $at_assert($rows !== [], 'Строки для пакета не собраны');
        $report = Attachments::export_to(Quiz::get($id), $rows, $spool . '/нет-такой-папки-'.wp_generate_password(4, false, false));
        $at_assert(isset($report['copied'], $report['missing']), 'Отчёт выгрузки пуст', json_encode($report));
        exec('rm -rf ' . escapeshellarg($local));
        return true;
    } finally {
        if ($spool !== '' && is_dir($spool)) {
            exec('rm -rf ' . escapeshellarg($spool));
        }
        if ($id) {
            delete_option(Attachments::pending_option($id));
            Quiz::delete($id);
        }
    }
});

$it('1.0.32: ошибки SMB переводятся по-человечески; коллизия mkdir ошибкой не считается', function () use ($at_assert): bool {
    $unc = str_repeat(chr(92), 2) . '10.200.10.10' . chr(92) . 'kvant' . chr(92) . 'olimp';
    $parsed = NetworkExport::parse_smb_path($unc);
    $at_assert(is_array($parsed), 'Путь SMB не разбирается');
    $at_assert(($parsed['host'] ?? '') === '10.200.10.10', 'Хост определён неверно', json_encode($parsed));
    $at_assert(($parsed['share'] ?? '') === 'kvant', 'Ресурс определён неверно', (string)($parsed['share'] ?? ''));

    $raw = 'NT_STATUS_SHARING_VIOLATION opening remote file ' . chr(92) . 'olimp'
        . chr(92) . 'import' . chr(92) . 'QuestionStudio.exe' . "\n";
    $msg = NetworkExport::translate_smb_error($raw, $parsed, ['import/QuestionStudio.exe']);
    $at_assert(stripos($msg, 'открыт другим') !== false, 'Занятый файл не объяснён', $msg);
    $at_assert(str_contains($msg, 'QuestionStudio.exe'), 'Имя занятого файла не названо', $msg);

    $collision = NetworkExport::translate_smb_error(
        'NT_STATUS_OBJECT_NAME_COLLISION making remote directory ' . chr(92) . 'olimp' . "\n",
        $parsed
    );
    $at_assert($collision !== '', 'Ошибка не переведена совсем', $collision);

    $msg2 = NetworkExport::translate_smb_error("NT_STATUS_LOGON_FAILURE\n", $parsed);
    $at_assert(stripos($msg2, 'авторизации') !== false, 'Ошибка входа не объяснена', $msg2);

    $view = (string)file_get_contents(WP_OLYMPIAD_PATH . '/admin/views/quiz-edit.php');
    $at_assert(str_contains($view, 'wpo_att_store'), 'В настройках олимпиады нет сведений о месте хранения вложений');
    $net = (string)file_get_contents(WP_OLYMPIAD_PATH . '/includes/class-network-export.php');
    $at_assert(str_contains($net, "NT_STATUS_SHARING_VIOLATION"), 'Транспорт не различает занятый файл');
    $at_assert(str_contains($net, "'skipped'"), 'Частичная выгрузка не помечается');
    $at_assert(str_contains($net, "putting file"), 'Не проверяется, какой файл реально записан');
    return true;
});

$it('1.0.32: занятый файл на SMB не отменяет выгрузку протоколов', function () use ($at_assert): bool {
    $dir = sys_get_temp_dir() . '/wpo_smb_stub_' . wp_generate_password(8, false, false);
    if (!wp_mkdir_p($dir)) {
        return true;
    }
    // Заглушка smbclient печатает ровно то, что наблюдали на сервере: коллизию mkdir
    // на существующей папке, удачный put протокола и SHARING_VIOLATION на занятом exe.
    $logic = "<?php\n"
        . "\$argv = array_slice(\$argv, 1);\n"
        . "\$cmds = '';\n"
        . "for (\$i = 0; \$i < count(\$argv); \$i++) { if (\$argv[\$i] === '-c' && isset(\$argv[\$i + 1])) { \$cmds = \$argv[\$i + 1]; } }\n"
        . "echo 'NT_STATUS_OBJECT_NAME_COLLISION making remote directory \\\\\\\\olimp' . PHP_EOL;\n"
        . "\$fail = false;\n"
        . "if (getenv('WPO_STUB_GLOBAL') !== false && getenv('WPO_STUB_GLOBAL') !== '') {\n"
        . "    echo 'ntstatus_error: NT_STATUS_' . getenv('WPO_STUB_GLOBAL') . ' (global) ' . PHP_EOL;\n"
        . "    \$fail = true;\n"
        . "}\n"
        . "if (preg_match_all('/put \"([^\"]+)\" \"([^\"]+)\"/', \$cmds, \$m, PREG_SET_ORDER)) {\n"
        . "    foreach (\$m as \$p) {\n"
        . "        if (getenv('WPO_STUB_ALL_FAIL') === '1') {\n"
        . "            echo 'ntstatus_error: NT_STATUS_' . ((getenv('WPO_STUB_STATUS') ?: 'SHARING_VIOLATION')) . ' opening remote file ' . \$p[2] . PHP_EOL;\n"
        . "            \$fail = true;\n"
        . "            continue;\n"
        . "        }\n"
        . "        if (stripos(\$p[2], 'QuestionStudio.exe') !== false) {\n"
        . "            echo 'ntstatus_error: NT_STATUS_SHARING_VIOLATION opening remote file ' . \$p[2] . PHP_EOL;\n"
        . "            \$fail = true;\n"
        . "            continue;\n"
        . "        }\n"
        . "        echo 'putting file ' . \$p[1] . ' as ' . \$p[2] . ' (100.0%)' . PHP_EOL;\n"
        . "    }\n"
        . "} elseif (getenv('WPO_STUB_STATUS') !== false && getenv('WPO_STUB_STATUS') !== '') {\n"
        . "    echo 'ntstatus_error: NT_STATUS_' . getenv('WPO_STUB_STATUS') . PHP_EOL;\n"
        . "    \$fail = true;\n"
        . "}\n"
        . "exit(\$fail ? 1 : 0);\n";
    $php_stub = $dir . '/smbclient.php';
    $sh_stub = $dir . '/smbclient';
    file_put_contents($php_stub, $logic);
    file_put_contents($sh_stub, "#!/bin/sh\nexec php " . escapeshellarg($php_stub) . ' "$@"' . "\n");
    @chmod($php_stub, 0644);
    @chmod($sh_stub, 0755);
    if (!is_executable($sh_stub) || !is_file($php_stub)) {
        exec('rm -rf ' . escapeshellarg($dir));
        return true; // ФС без exec — проверка неприменима
    }
    $add_filter = static fn() => add_filter('wpo_smbclient_binary', static fn() => $sh_stub);
    $add_filter();
    $config = [
        'type'     => 'smb',
        'path'     => '//10.200.10.10/kvant/olimp',
        'user'     => '',
        'password' => '',
    ];
    $files = [
        'сводный_протокол.csv'  => "участник;балл\n",
        'import/QuestionStudio.exe' => 'binary',
    ];
    try {
        $res = NetworkExport::deliver_files_public($files, $config);
        $at_assert(!empty($res['success']), 'Выгрузка не засчитала записанные файлы', json_encode($res, JSON_UNESCAPED_UNICODE));
        $at_assert(in_array('сводный_протокол.csv', (array)($res['files'] ?? []), true), 'Записанный файл не определён', json_encode($res['files'] ?? []));
        $at_assert(($res['skipped'] ?? []) === ['import/QuestionStudio.exe'], 'Занятый файл не помечен пропущенным', json_encode($res, JSON_UNESCAPED_UNICODE));
        $at_assert(stripos((string)($res['message'] ?? ''), 'открыт другим') !== false, 'Причина пропуска не объяснена', (string)($res['message'] ?? ''));
        $at_assert(stripos((string)($res['message'] ?? ''), 'QuestionStudio.exe') !== false, 'Имя занятого файла не названо', (string)($res['message'] ?? ''));

        remove_all_filters('wpo_smbclient_binary');
        $add_filter();

        // Если не записан ни один файл — выгрузка неудачна, а не «успешна с пустотой».
        putenv('WPO_STUB_ALL_FAIL=1');
        $_ENV['WPO_STUB_ALL_FAIL'] = '1';
        $res2 = NetworkExport::deliver_files_public($files, $config);
        $at_assert(empty($res2['success']), 'Пустая выгрузка признана успешной', json_encode($res2, JSON_UNESCAPED_UNICODE));
        $at_assert(stripos((string)($res2['message'] ?? ''), 'открыт другим') !== false, 'Причина отказа не объяснена', (string)($res2['message'] ?? ''));

        // Сбой авторизации не должен теряться за частичным успехом: put-ы «получились»,
        // но в выводе есть не привязанная к файлу строка LOGON_FAILURE — выгрузка неудачна.
        putenv('WPO_STUB_ALL_FAIL');
        putenv('WPO_STUB_GLOBAL=LOGON_FAILURE');
        $res3 = NetworkExport::deliver_files_public($files, $config);
        $at_assert(empty($res3['success']), 'Ошибка входа не помешала признать выгрузку успешной', json_encode($res3, JSON_UNESCAPED_UNICODE));
        $at_assert(stripos((string)($res3['message'] ?? ''), 'авторизации') !== false, 'Ошибка входа не объяснена', (string)($res3['message'] ?? ''));
        return true;
    } finally {
        remove_all_filters('wpo_smbclient_binary');
        putenv('WPO_STUB_ALL_FAIL');
        putenv('WPO_STUB_GLOBAL');
        exec('rm -rf ' . escapeshellarg($dir));
    }
});

/**
 * Заглушка smbclient (tests/smb-client-stub.php) через обёртку: позволяет проверять
 * разбор вывода транспорта без SAMBA на стенде. Возвращает путь к «бинарнику» и путь
 * журнала вызовов.
 *
 * @return array{0:string,1:string,2:callable}
 */
$at_smb_stub = static function () use ($at_assert): array {
    $dir = sys_get_temp_dir() . '/wpo_smb_stub_' . wp_generate_password(8, false, false);
    $at_assert(wp_mkdir_p($dir), 'Не удалось создать каталог заглушки');
    $helper = WP_OLYMPIAD_PATH . '/tests/smb-client-stub.php';
    $at_assert(is_file($helper), 'Заглушка smbclient не найдена');
    $bin = $dir . '/smbclient';
    $log = $dir . '/calls.log';
    file_put_contents($bin, "#!/bin/sh\nexec php " . escapeshellarg($helper) . ' "$@"' . "\n");
    @chmod($bin, 0755);
    $at_assert(is_executable($bin), 'Каталог тестов смонтирован с noexec — проверка невозможна');
    putenv('WPO_STUB_LOG=' . $log);
    $filter = static fn() => $bin;
    add_filter('wpo_smbclient_binary', $filter);
    $cleanup = static function () use ($dir, $bin, $log): void {
        remove_all_filters('wpo_smbclient_binary');
        foreach (['WPO_STUB_MODE', 'WPO_STUB_SIZE', 'WPO_STUB_LOG', 'WPO_STUB_ALL_FAIL', 'WPO_STUB_GLOBAL', 'WPO_STUB_STATUS'] as $k) {
            putenv($k);
        }
        exec('rm -rf ' . escapeshellarg($dir));
    };

    return [$bin, $log, $cleanup];
};

$at_env = static function (array $vars): void {
    foreach ($vars as $key => $val) {
        putenv($key . '=' . $val);
        $_ENV[$key] = (string)$val;
    }
};

$it('1.0.34: коллизия имени при записи на SMB означает «файл уже доставлен»', function () use ($at_assert, $at_smb_stub, $at_env): bool {
    [, $log, $cleanup] = $at_smb_stub();
    try {
        $config = ['type' => 'smb', 'path' => '//10.200.10.10/kvant/olimp', 'user' => '', 'password' => ''];
        $content = "участник;балл\nФёдоров;7\n";

        // Тот же файл доставляют второй раз: Samba отвечает коллизией, файл на месте.
        $at_env(['WPO_STUB_MODE' => 'collision', 'WPO_STUB_SIZE' => (string)strlen($content)]);
        $res = NetworkExport::deliver_files_public(['сводный_протокол.csv' => $content], $config);
        $at_assert(!empty($res['success']), 'Повторная доставка с коллизией признана ошибкой', json_encode($res, JSON_UNESCAPED_UNICODE));
        $at_assert(($res['existing'] ?? []) === ['сводный_протокол.csv'], 'Коллизия не распознана как «уже на месте»', json_encode($res, JSON_UNESCAPED_UNICODE));
        $at_assert(!isset($res['skipped']), 'Доставленный файл попал в пропущенные', json_encode($res, JSON_UNESCAPED_UNICODE));

        // Размер на сервере другой — значит там чужой или обрезанный файл: это настоящая ошибка.
        $at_env(['WPO_STUB_SIZE' => (string)(strlen($content) + 77)]);
        $res2 = NetworkExport::deliver_files_public(['сводный_протокол.csv' => $content], $config);
        $at_assert(empty($res2['success']), 'Расхождение размера не замечено', json_encode($res2, JSON_UNESCAPED_UNICODE));
        $at_assert(stripos((string)($res2['message'] ?? ''), 'в сетевой папке') !== false, 'Причина не объяснена', (string)($res2['message'] ?? ''));
        $at_assert(stripos((string)($res2['message'] ?? ''), 'размер') !== false, 'Расхождение размера не названо', (string)($res2['message'] ?? ''));
        return true;
    } finally {
        $cleanup();
    }
});

$it('1.0.34: доставленный файл не переписывается заново, а «Доставить сейчас» переносит его принудительно', function () use ($at_assert, $at_smb_stub, $at_env, $at_file, $at_unc_host): bool {
    [$bin, $log, $cleanup] = $at_smb_stub();
    $id = 0;
    $spool_dir = '';
    try {
        $at_assert($bin !== '', 'Заглушка не готова');
        $id = (int)Quiz::create([
            'title'    => 'Вложения повтор ' . wp_generate_password(6, false, false),
            'status'   => 'publish',
            'settings' => [
                'network_folder_type' => 'smb',
                'network_folder_path' => '//' . $at_unc_host . '/kvant/olimp/olympiad-2',
                'max_attempts'        => 0,
                'require_org'         => 0,
            ],
        ]);
        $qid = (int)Question::create([
            'quiz_id'       => $id,
            'question_type' => 'file',
            'title'         => 'Прикрепите скан.',
            'points'        => 10,
            'penalty'       => 0,
            'options'       => [],
        ]);
        $quiz = Quiz::get($id);
        $at_assert(Attachments::mode($quiz) === 'spool', 'Ожидается режим рабочей папки');
        $spool_dir = (string)Attachments::store($quiz)['spool'];

        $attempt = Attempt::start($id, ['name' => 'Повтор', 'email' => 'again@example.invalid', 'participant_class' => '11']);
        $bucket = Attachments::bucket($attempt->token);
        $file = Attachments::accept($quiz, $bucket, $qid, [
            'name'     => 'скан.pdf',
            'tmp_name' => $at_file('pdf'),
            'error'    => UPLOAD_ERR_OK,
            'size'     => 10,
        ], Attachments::limits($quiz));
        $at_assert(is_array($file), 'Файл не принят', is_wp_error($file) ? $file->get_error_message() : '');

        $submit = new WP_REST_Request('POST', RestApi::NAMESPACE . '/submit');
        $submit->set_body_params(['token' => $attempt->token, 'answers' => [$qid => [(string)$file['id']]]]);
        $sent = RestApi::handle_submit($submit);
        $at_assert(!is_wp_error($sent), 'Сдача отклонена', is_wp_error($sent) ? $sent->get_error_message() : '');

        $calls = (int)substr_count((string)file_get_contents($log), 'put "');
        $at_assert($calls >= 1, 'После сдачи файл не был отправлен ни разу', (string)$calls);
        $at_assert(Attachments::pending($quiz) === [], 'Доставленный файл остался в списке недоставленного', json_encode(Attachments::pending($quiz), JSON_UNESCAPED_UNICODE));
        $marks = Attachments::delivered_marks(Quiz::get($id));
        $at_assert(isset($marks[(string)$file['rel']]), 'Отметка о доставке не сохранена', json_encode(array_keys($marks)));

        // Вторая выгрузка протоколов не должна заново писать тот же файл.
        $before = (int)substr_count((string)file_get_contents($log), 'put "');
        $report = Attachments::export_to(Quiz::get($id), Attachments::rows_for_quiz(Quiz::get($id)), '');
        $at_assert(($report['already'] ?? 0) === 1, 'Повторная выгрузка не учла доставленный файл', json_encode($report, JSON_UNESCAPED_UNICODE));
        $after = (int)substr_count((string)file_get_contents($log), 'put "');
        $at_assert($after === $before, 'Файл переписан повторно', $before . ' -> ' . $after);

        // Принудительная доставка (кнопка «Доставить сейчас») снимает отметки и пишет заново.
        $res = Attachments::redeliver(Quiz::get($id));
        $at_assert(($res['sent'] ?? 0) === 1, 'Принудительная доставка не сработала', json_encode($res, JSON_UNESCAPED_UNICODE));
        $forced = (int)substr_count((string)file_get_contents($log), 'put "');
        $at_assert($forced === $after + 1, 'Принудительная доставка не вызвала запись', $after . ' -> ' . $forced);
        $at_assert(count(Attachments::delivered_marks(Quiz::get($id))) === 1, 'Отметка не восстановлена после переноса');

        // Снятая отметка (файл удалили) тоже должна позволять доставку заново.
        Attachments::forget_delivered(Quiz::get($id));
        $at_assert(Attachments::delivered_marks(Quiz::get($id)) === [], 'Отметки не сброшены');
        $again = Attachments::export_to(Quiz::get($id), Attachments::rows_for_quiz(Quiz::get($id)), '');
        $at_assert(($again['already'] ?? 0) === 0, 'Без отметки файл снова посчитан доставленным', json_encode($again, JSON_UNESCAPED_UNICODE));

        $admin = (string)file_get_contents(WP_OLYMPIAD_PATH . '/admin/class-admin.php');
        $view = (string)file_get_contents(WP_OLYMPIAD_PATH . '/admin/views/quiz-edit.php');
        $at_assert(str_contains($admin, "redeliver_attachments"), 'В админке нет обработчика принудительной доставки');
        $at_assert(str_contains($view, 'wpo_redeliver_attachments_'), 'В форме нет кнопки принудительной доставки');
        return true;
    } finally {
        $cleanup();
        if ($id) {
            delete_option(Attachments::pending_option($id));
            delete_option(Attachments::delivered_option($id));
            if ($spool_dir !== '' && is_dir($spool_dir)) {
                exec('rm -rf ' . escapeshellarg($spool_dir));
            }
            Quiz::delete($id);
        }
    }
});

$it('1.0.35: занятый другим процессом файл с тем же размером считается доставленным', function () use ($at_assert, $at_smb_stub, $at_env): bool {
    [, , $cleanup] = $at_smb_stub();
    try {
        $config = ['type' => 'smb', 'path' => '//10.200.10.10/kvant/olimp', 'user' => '', 'password' => ''];
        $content = "участник;балл\nФёдоров;7\n";
        $docx = '6ca192eb137477ebb3b98c99.docx';

        // Файл на share открыт (Word, антивирус), но лежит он наш: размер совпадает.
        $at_env(['WPO_STUB_MODE' => 'locked', 'WPO_STUB_SIZE' => (string) strlen($content)]);
        $res = NetworkExport::deliver_files_public(['вложения/olimp/abcd1234abcd1234abcd1234/' . $docx => $content], $config);
        $at_assert(!empty($res['success']), 'Занятый файл с верным размером не засчитан', json_encode($res, JSON_UNESCAPED_UNICODE));
        $at_assert(($res['existing'] ?? []) === ['вложения/olimp/abcd1234abcd1234abcd1234/' . $docx], 'Файл не помечен как уже лежащий на месте', json_encode($res, JSON_UNESCAPED_UNICODE));
        $at_assert(($res['verified'] ?? []) !== [], 'Размер не был сверен', json_encode($res, JSON_UNESCAPED_UNICODE));
        $at_assert(!isset($res['skipped']), 'Доставленный файл попал в пропущенные', json_encode($res, JSON_UNESCAPED_UNICODE));

        // Размер другой — файл не наш (или обрезан): это настоящая ошибка, и она объяснена.
        $at_env(['WPO_STUB_SIZE' => (string) (strlen($content) + 4096)]);
        $res2 = NetworkExport::deliver_files_public(['вложения/olimp/abcd1234abcd1234abcd1234/' . $docx => $content], $config);
        $at_assert(empty($res2['success']), 'Расхождение размера не замечено', json_encode($res2, JSON_UNESCAPED_UNICODE));
        $at_assert(stripos((string)($res2['message'] ?? ''), 'другой размер') !== false, 'Причина не объяснена', (string)($res2['message'] ?? ''));
        $at_assert(in_array('NT_STATUS_SHARING_VIOLATION', (array)($res2['codes'] ?? []), true), 'Код сервера не сохранён', json_encode($res2, JSON_UNESCAPED_UNICODE));
        $at_assert(stripos((string)($res2['raw'] ?? ''), 'SHARING_VIOLATION') !== false, 'Сырой вывод транспорта не передан', (string)($res2['raw'] ?? ''));
        return true;
    } finally {
        $cleanup();
    }
});

$it('1.0.35: страница олимпиады показывает причину недоставки, а не только «папка недоступна»', function () use ($at_assert, $at_smb_stub, $at_env, $at_file, $at_unc_host): bool {
    [, $log, $cleanup] = $at_smb_stub();
    $id = 0;
    $spool_dir = '';
    try {
        $id = (int)Quiz::create([
            'title'    => 'Вложения причина ' . wp_generate_password(6, false, false),
            'status'   => 'publish',
            'settings' => [
                'network_folder_type' => 'smb',
                'network_folder_path' => '//' . $at_unc_host . '/kvant/olimp/olympiad-2',
                'max_attempts'        => 0,
                'require_org'         => 0,
            ],
        ]);
        $qid = (int)Question::create([
            'quiz_id'       => $id,
            'question_type' => 'file',
            'title'         => 'Прикрепите отчёт.',
            'points'        => 10,
            'penalty'       => 0,
            'options'       => [],
        ]);
        $quiz = Quiz::get($id);
        $spool_dir = (string)Attachments::store($quiz)['spool'];
        $attempts = [];
        $files = [];
        for ($k = 0; $k < 2; $k++) {
            $attempt = Attempt::start($id, ['name' => 'Участник ' . $k, 'email' => 'why' . $k . '@example.invalid', 'participant_class' => '11']);
            $attempts[] = $attempt;
            $bucket = Attachments::bucket($attempt->token);
            $files[$k] = Attachments::accept($quiz, $bucket, $qid, [
                'name'     => 'отчёт-' . $k . '.pdf',
                'tmp_name' => $at_file('pdf'),
                'error'    => UPLOAD_ERR_OK,
                'size'     => 10,
            ], Attachments::limits($quiz));
            $at_assert(is_array($files[$k]), 'Файл не принят', is_wp_error($files[$k]) ? $files[$k]->get_error_message() : '');
            $submit = new WP_REST_Request('POST', RestApi::NAMESPACE . '/submit');
            $submit->set_body_params(['token' => $attempt->token, 'answers' => [$qid => [(string)$files[$k]['id']]]]);
            $sent = RestApi::handle_submit($submit);
            $at_assert(!is_wp_error($sent), 'Сдача отклонена', is_wp_error($sent) ? $sent->get_error_message() : '');
        }
        $at_assert(Attachments::pending($quiz) === [], 'Доставленное осталось в списке недоставленного', json_encode(Attachments::pending($quiz), JSON_UNESCAPED_UNICODE));

        // Теперь транспорт начинает отвечать «файл открыт, размер не тот» — недоставка
        // обязана быть видна с причиной: код и вывод сервера, а не только «папка недоступна».
        $at_env(['WPO_STUB_MODE' => 'locked', 'WPO_STUB_SIZE' => '999999']);
        $res = Attachments::redeliver(Quiz::get($id));
        $at_assert(($res['sent'] ?? 0) === 0, 'Недоставленное засчитано доставленным', json_encode($res, JSON_UNESCAPED_UNICODE));
        $at_assert(($res['missing'] ?? 0) === 2, 'Число недоставленных файлов неверно', json_encode($res, JSON_UNESCAPED_UNICODE));
        $at_assert(stripos((string)($res['message'] ?? ''), 'другой размер') !== false, 'Причина в отчёте не сформулирована', (string)($res['message'] ?? ''));
        $at_assert(in_array('NT_STATUS_SHARING_VIOLATION', (array)($res['codes'] ?? []), true), 'Код сервера в отчёте потерян', json_encode($res, JSON_UNESCAPED_UNICODE));

        $last = Attachments::last_delivery(Quiz::get($id));
        $at_assert(($last['message'] ?? '') !== '' && str_contains((string)($last['message'] ?? ''), 'другой размер'), 'Отчёт о последней попытке не сохранён', json_encode($last, JSON_UNESCAPED_UNICODE));
        $at_assert(($last['pending'] ?? 0) === 2, 'Список недоставленного в отчёте неверен', json_encode($last, JSON_UNESCAPED_UNICODE));
        $at_assert(in_array('NT_STATUS_SHARING_VIOLATION', (array)($last['codes'] ?? []), true), 'Коды сервера в отчёте не видны');
        $at_assert(stripos((string)($last['raw'] ?? ''), 'SHARING_VIOLATION') !== false, 'Сырой вывод транспорта в отчёт не попал');
        $at_assert(count((array)($last['names'] ?? [])) === 2, 'Имена файлов в отчёте потеряны', json_encode($last['names'] ?? []));

        $pending = Attachments::pending(Quiz::get($id));
        $at_assert(count($pending) === 2, 'В предупреждении должно быть два файла', json_encode($pending));
        $at_assert(in_array('NT_STATUS_SHARING_VIOLATION', (array)($pending[0]['codes'] ?? []), true), 'Код сервера не сохранён у записи');

        // Когда транспорт отвечает «занято, но размер наш» — предупреждение исчезает,
        // а повторная выгрузка уже не пишет файл заново.
        $at_env(['WPO_STUB_SIZE' => (string) (int)filesize((string)Attachments::resolve(Quiz::get($id), (string)$files[0]['rel']))]);
        $res2 = Attachments::redeliver(Quiz::get($id));
        $at_assert(($res2['sent'] ?? 0) === 2, 'Файлы с верным размером не засчитаны доставленными', json_encode($res2, JSON_UNESCAPED_UNICODE));
        $at_assert(Attachments::pending(Quiz::get($id)) === [], 'Предупреждение не исчезло после успешной сверки');
        $before = (int)substr_count((string)file_get_contents($log), 'put "');
        $report = Attachments::export_to(Quiz::get($id), Attachments::rows_for_quiz(Quiz::get($id)), '');
        $at_assert(($report['already'] ?? 0) === 2, 'Выгрузка снова понесла доставленное', json_encode($report, JSON_UNESCAPED_UNICODE));
        $at_assert((int)substr_count((string)file_get_contents($log), 'put "') === $before, 'Лишняя запись на сервер после успешной доставки');

        $view = (string)file_get_contents(WP_OLYMPIAD_PATH . '/admin/views/quiz-edit.php');
        foreach (['wpo_att_last', 'Коды сервера', 'Что ответил сервер', 'attachment_delivery'] as $needle) {
            $at_assert(str_contains($view, (string)$needle), 'В настройках нет вывода причины недоставки: ' . $needle);
        }
        return true;
    } finally {
        $cleanup();
        if ($spool_dir !== '' && is_dir($spool_dir)) {
            exec('rm -rf ' . escapeshellarg($spool_dir));
        }
        if ($id) {
            delete_option(Attachments::pending_option($id));
            delete_option(Attachments::delivered_option($id));
            delete_option(Attachments::last_delivery_option($id));
            Quiz::delete($id);
        }
    }
});

$it('1.0.32: форма редактора и просмотр попытки показывают вложения', function () use ($at_assert, $at_fixture, $at_drop, $at_file): bool {
    $repo = WP_OLYMPIAD_PATH;
    $card = (string)file_get_contents($repo . '/admin/views/question-item-card.php');
    $admin_js = (string)file_get_contents($repo . '/admin/js/admin.js');
    $review = (string)file_get_contents($repo . '/admin/views/attempt-review.php');
    $quiz_view = (string)file_get_contents($repo . '/admin/views/quiz-edit.php');

    $at_assert(str_contains($card, 'wpo-panel-file'), 'В карточке задания нет панели нового типа');
    $at_assert(str_contains($card, 'wpo-file-upload-hint'), 'В панели нет поля подсказки');
    $at_assert(str_contains($admin_js, "qtype === 'file'"), 'Админка не переключает панель типа');
    $at_assert(str_contains($admin_js, 'upload_hint'), 'Подсказка не собирается в опции задания');
    $at_assert(str_contains($quiz_view, 'attachment_max_size_mb') && str_contains($quiz_view, 'attachment_max_count'), 'В настройках олимпиады нет полей лимитов');
    $at_assert(str_contains($review, 'answer_files'), 'Просмотр попытки не разбирает ответ-вложения');
    $at_assert(str_contains($review, 'attachment-file'), 'В просмотре нет ссылки на закрытую отдачу файла');

    // Рендер просмотра попытки: список файлов с ссылкой и пометка про пропавший.
    [$root, $id, $qid] = $at_fixture([]);
    try {
        $quiz = Quiz::get($id);
        $attempt = Attempt::start($id, ['name' => 'Обозреватель', 'email' => 'rev@example.invalid']);
        $bucket = Attachments::bucket($attempt->token);
        $kept = [];
        $one = Attachments::accept($quiz, $bucket, $qid, ['name' => 'отчёт.pdf', 'tmp_name' => $at_file('pdf'), 'error' => UPLOAD_ERR_OK, 'size' => 40], Attachments::limits($quiz));
        $gone = Attachments::accept($quiz, $bucket, $qid, ['name' => 'приложение.pdf', 'tmp_name' => $at_file('pdf'), 'error' => UPLOAD_ERR_OK, 'size' => 40], Attachments::limits($quiz));
        $answers = Attachments::resolve_answers($quiz, $bucket, [$qid => [$one['id'], $gone['id']]], $kept);
        $graded = Grader::grade($quiz, $attempt, $answers, []);
        Grader::save_responses((int)$attempt->id, (array)($graded['responses'] ?? []));
        @unlink((string)Attachments::resolve($quiz, (string)$gone['rel']));

        $questions = $quiz->get_questions(false);
        $responses = $attempt->get_responses();
        ob_start();
        include $repo . '/admin/views/attempt-review.php';
        $html = (string)ob_get_clean();
        $at_assert(str_contains($html, 'отчёт.pdf'), 'Файл участника не показан в просмотре', mb_substr($html, 0, 200));
        $at_assert(str_contains($html, 'приложение.pdf'), 'Пропажа не показана под своим именем');
        $at_assert(str_contains($html, 'Скачать') || substr_count($html, '<a href=') >= 1, 'Файлы отдаются без ссылки');
        $at_assert(substr_count($html, 'attachment-file') >= 1, 'Ссылки на скачивание нет');
        $at_assert(str_contains($html, 'файл не найден'), 'Пропажа файла не помечена');
        $at_assert(!str_contains($html, '"v":1'), 'В просмотре остался сырой JSON');
        return true;
    } finally {
        $at_drop($root, $id);
    }
});
