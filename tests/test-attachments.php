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
        // 1.0.37: в пакете файл виден так, как его назвал участник, а не хешем:
        // «вложения/Выгрузчик — <id попытки>/Выгрузчик — 01 — работа.pdf».
        $labels   = Attachments::share_labels($quiz);
        $numbers  = Attachments::question_numbers($quiz);
        $folder   = Attachments::share_folder($bucket, $labels);
        $share_nm = (string)(Attachments::share_names($quiz, $bucket, $labels, $numbers)[$ok['id']] ?? '');
        $at_assert($folder !== $bucket && str_starts_with($folder, 'Выгрузчик'), 'Папка попытки в пакете не читается', $folder);
        $at_assert($share_nm !== '' && basename((string)$ok['rel']) !== $share_nm, 'В пакете файл назван служебным именем', $share_nm);
        $at_assert(str_contains($share_nm, 'работа.pdf'), 'Исходное имя участника из пакета пропало', $share_nm);
        $at_assert(is_file($report['dir'] . '/' . $folder . '/' . $share_nm), 'Файл не лёг в пакет по ожидаемому пути', $report['dir'] . '/' . $folder . '/' . $share_nm);
        $at_assert(hash_file('sha256', $report['dir'] . '/' . $folder . '/' . $share_nm) === (string)$ok['sha256'], 'Скопированный файл не совпадает с оригиналом');
        $at_assert(!is_file($report['dir'] . '/' . $bucket . '/работа.pdf'), 'Старая нечитаемая копия в пакете осталась', (string)$report['dir']);

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
        // 1.0.37: в списке недоставленного — путь и имя ровно такие, каким файл
        // уйдёт на шару: читаемое имя участника, а не служебный хеш.
        $pending_rel = str_replace('\\', '/', (string)($pending[0]['rel'] ?? ''));
        $at_assert(basename($pending_rel) === 'Федор — 01 — ' . basename((string)$file['name']), 'Записан не тот файл', json_encode($pending[0]));
        $at_assert(($pending[0]['share'] ?? '') === $pending_rel, 'Путь на шаре в строке недоставленного потерян', json_encode($pending[0]));
        $at_assert(strpos($pending_rel, $bucket) === false, 'В путь на шаре утекла корзина попытки', $pending_rel);
        $at_assert(strpos($pending_rel, basename((string)$file['rel'])) === false, 'На шару файл назван служебным хешем', $pending_rel);
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
$at_smb_stub = static function (?string $state = null) use ($at_assert): array {
    $dir = sys_get_temp_dir() . '/wpo_smb_stub_' . wp_generate_password(8, false, false);
    $at_assert(wp_mkdir_p($dir), 'Не удалось создать каталог заглушки');
    $helper = WP_OLYMPIAD_PATH . '/tests/smb-client-stub.php';
    $at_assert(is_file($helper), 'Заглушка smbclient не найдена');
    $bin = $dir . '/smbclient';
    $log = $dir . '/calls.log';
    if ($state !== null) {
        putenv('WPO_STUB_STATE=' . $state);
        $_ENV['WPO_STUB_STATE'] = $state;
    }
    file_put_contents($bin, "#!/bin/sh\nexec php " . escapeshellarg($helper) . ' "$@"' . "\n");
    @chmod($bin, 0755);
    $at_assert(is_executable($bin), 'Каталог тестов смонтирован с noexec — проверка невозможна');
    putenv('WPO_STUB_LOG=' . $log);
    $filter = static fn() => $bin;
    add_filter('wpo_smbclient_binary', $filter);
    $cleanup = static function () use ($dir, $bin, $log): void {
        remove_all_filters('wpo_smbclient_binary');
        foreach (['WPO_STUB_MODE', 'WPO_STUB_SIZE', 'WPO_STUB_LOG', 'WPO_STUB_ALL_FAIL', 'WPO_STUB_GLOBAL', 'WPO_STUB_STATUS', 'WPO_STUB_STATE', 'WPO_STUB_EXISTING', 'WPO_STUB_READONLY'] as $k) {
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

$it('1.0.36: отсутствующие папки на share создаются по сегментам, put не получает OBJECT_PATH_NOT_FOUND', function () use ($at_assert, $at_smb_stub, $at_env, $at_unc_host): bool {
    $state = sys_get_temp_dir() . '/wpo_smb_state_' . wp_generate_password(6, false, false) . '.json';
    [, $log, $cleanup] = $at_smb_stub($state);
    try {
        // Ровно картина с сервера: \\host\kvant\olimp существует, а «вложения» в ней нет.
        $at_env(['WPO_STUB_MODE' => 'missingdir', 'WPO_STUB_EXISTING' => 'olimp']);
        $config = ['type' => 'smb', 'path' => '//' . $at_unc_host . '/kvant/olimp', 'user' => '', 'password' => ''];
        $name = 'вложения/olympiad-2/0613669172928f87/6ca192eb137477ebb3b98c99.docx';
        $res = NetworkExport::deliver_files_public([$name => "содержимое вложения\n"], $config);
        $at_assert(!empty($res['success']), 'Выгрузка не удалась: папки не созданы', json_encode($res, JSON_UNESCAPED_UNICODE));
        $at_assert(($res['files'] ?? []) === [$name], 'Файл не записан', json_encode($res, JSON_UNESCAPED_UNICODE));

        $calls = (string)file_get_contents($log);
        // Плагин заходит в папку выгрузки и создаёт недостающее по одному сегменту.
        $at_assert(substr_count($calls, 'mkdir "') >= 4, 'Каталоги не создавались по сегментам', $calls);
        foreach (['mkdir "olimp"', 'mkdir "olimp\вложения"', 'mkdir "olimp\вложения\olympiad-2"', 'mkdir "olimp\вложения\olympiad-2\0613669172928f87"'] as $needle) {
            $at_assert(strpos($calls, $needle) !== false, 'Цепочка каталогов не полная: ' . $needle, $calls);
        }
        $at_assert(substr_count($calls, 'put "') === 1, 'put отправлен не один раз', $calls);

        // Коллизия создания каталога не должна выдаваться за «файл уже есть, снимите защиту».
        $at_assert(stripos((string)($res['message'] ?? ''), 'снимите защиту') === false, 'Коллизия каталога объяснена как защита файла', (string)($res['message'] ?? ''));
        return true;
    } finally {
        $cleanup();
        @unlink($state);
    }
});

$it('1.0.36: OBJECT_PATH_NOT_FOUND и коллизия каталога разбираются как отсутствующая папка, а не как защита файла', function () use ($at_assert): bool {
    $parsed = ['unc' => '\\\\10.200.10.10\\kvant\\olimp', 'service' => '//10.200.10.10/kvant', 'host' => '10.200.10.10', 'share' => 'kvant', 'subdir' => 'olimp'];
    $raw = "NT_STATUS_OBJECT_NAME_COLLISION making remote directory \\olimp\nntstatus_error: NT_STATUS_OBJECT_PATH_NOT_FOUND opening remote file \\olimp\\вложения\\olympiad-2\\6ca192eb137477ebb3b98c99.docx";
    $msg = NetworkExport::translate_smb_error($raw, $parsed, []);
    $at_assert(stripos($msg, 'не найдена на сервере') !== false, 'Отсутствие папки не названо причиной', $msg);
    $at_assert(stripos($msg, 'вложения') !== false, 'Не сказано, какую папку создать', $msg);
    $at_assert(stripos($msg, 'снимите защиту с файла') === false, 'Коллизия каталога выдана за защиту файла', $msg);

    // Коллизия именно файла — по-прежнему называется защитой файла.
    $msg2 = NetworkExport::translate_smb_error("ntstatus_error: NT_STATUS_OBJECT_NAME_COLLISION opening remote file \\olimp\\вложения\\olympiad-2\\отчёт.pdf", $parsed, ['olimp/вложения/olympiad-2/отчёт.pdf']);
    $at_assert(stripos($msg2, 'снимите защиту с файла') !== false, 'Защита файла не названа', $msg2);
    $at_assert(stripos($msg2, 'отчёт.pdf') !== false, 'Имя защищённого файла не приведено', $msg2);
    return true;
});

$it('1.0.36: защищённый «только для чтения» файл с другим содержимым — защита снимается и файл записывается', function () use ($at_assert, $at_smb_stub, $at_env): bool {
    $state = sys_get_temp_dir() . '/wpo_smb_ro_' . wp_generate_password(6, false, false) . '.json';
    [, $log, $cleanup] = $at_smb_stub($state);
    try {
        $config = ['type' => 'smb', 'path' => '//10.200.10.10/kvant/olimp', 'user' => '', 'password' => ''];
        $name = 'вложения/olympiad-2/0613669172928f87/6ca192eb137477ebb3b98c99.docx';
        $first = NetworkExport::deliver_files_public([$name => "первая версия документа\r\n"], $config);
        $at_assert(!empty($first['success']), 'Первая запись не прошла', json_encode($first, JSON_UNESCAPED_UNICODE));
        $at_assert(substr_count((string)file_get_contents($log), 'setmode') === 0, 'Защита снята без необходимости', (string)file_get_contents($log));

        // Файл на share закрыли от перезаписи, а участник принёс другое содержимое.
        $at_env(['WPO_STUB_MODE' => 'readonly', 'WPO_STUB_READONLY' => '1']);
        $res = NetworkExport::deliver_files_public([$name => "исправленная версия документа\r\n"], $config);
        $at_assert(!empty($res['success']), 'Защита не помогла записать новую версию', json_encode($res, JSON_UNESCAPED_UNICODE));
        $at_assert((int)($res['unprotected'] ?? 0) === 1, 'Снятие защиты не отражено в отчёте', json_encode($res, JSON_UNESCAPED_UNICODE));
        $at_assert(stripos((string)($res['message'] ?? ''), 'снят') !== false && stripos((string)($res['message'] ?? ''), 'записаны заново') !== false, 'Отчёт не объясняет, что защита снята', (string)($res['message'] ?? ''));
        $calls = (string)file_get_contents($log);
        $at_assert(preg_match('/setmode "[^"]*6ca192eb137477ebb3b98c99\.docx" -r/', $calls) === 1, 'setmode -р не отправлен на сервер', $calls);
        $at_assert(substr_count($calls, 'put "') === 3, 'Ожидался повторный put после снятия защиты', $calls);

        // Чужое содержимое без коллизии (например, файл занят) — трогать ничего нельзя.
        @unlink($state);
        $at_env(['WPO_STUB_MODE' => 'locked', 'WPO_STUB_SIZE' => '999999']);
        $res2 = NetworkExport::deliver_files_public([$name => "ещё одна версия\r\n"], $config);
        $at_assert(empty($res2['success']), 'Занятый чужой файл признан записанным', json_encode($res2, JSON_UNESCAPED_UNICODE));
        $at_assert(stripos((string)($res2['message'] ?? ''), 'другой размер') !== false, 'Причина расхождения не названа', (string)($res2['message'] ?? ''));
        return true;
    } finally {
        $cleanup();
        @unlink($state);
    }
});

$it('1.0.36: кнопка «Убрать защиту файлов» снимает setmode -r только с файлов плагина', function () use ($at_assert, $at_smb_stub, $at_env, $at_unc_host): bool {
    $state = sys_get_temp_dir() . '/wpo_smb_unp_' . wp_generate_password(6, false, false) . '.json';
    [, $log, $cleanup] = $at_smb_stub($state);
    $id = 0;
    try {
        $id = (int)Quiz::create([
            'title'    => 'Вложения защита ' . wp_generate_password(6, false, false),
            'status'   => 'publish',
            'settings' => [
                'network_folder_type' => 'smb',
                'network_folder_path' => '//' . $at_unc_host . '/kvant/olimp',
                'max_attempts'        => 0,
                'require_org'         => 0,
            ],
        ]);
        $quiz = Quiz::get($id);
        $at_assert(Attachments::mode($quiz) === 'spool', 'Ожидается доставка транспортом');

        // Модель сервера: файл вложений лежит и защищён, чужим файлам плагин не хозяин.
        $slug = (string)\WPOlympiad\NetworkExport::build_safe_slug($quiz);
        $store = Attachments::store($quiz);
        $att = strtolower(str_replace('/', '\\', 'olimp/вложения/' . $slug . '/0613669172928f87/сводный_протокол.csv'));
        $foreign = 'olimp\\import\\questionstudio.exe';
        file_put_contents($state, json_encode([
            'dirs' => ['olimp', 'olimp\\вложения', 'olimp\\вложения\\' . strtolower($slug), 'olimp\\вложения\\' . strtolower($slug) . '\\0613669172928f87', strtolower(str_replace('/', '\\', 'вложения/' . $slug . '/0613669172928f87'))],
            'files' => [
                $att => ['size' => 12],
                $foreign => ['size' => 12],
            ],
            'readonly' => [$att, $foreign],
        ]));
        update_option(Attachments::pending_option($id), [[
            'bucket' => '0613669172928f87',
            'name' => 'сводный_протокол.csv',
            'rel' => '0613669172928f87/сводный_протокол.csv',
            'error' => 'файл защищён',
            'codes' => ['NT_STATUS_OBJECT_NAME_COLLISION'],
        ]], false);
        Attachments::reset_store_cache($id);
        $at_assert(($store['mode'] ?? '') === 'spool', 'Ожидается доставка через транспорт', (string)($store['mode'] ?? ''));

        $res = Attachments::unprotect_share($quiz);
        $at_assert(!empty($res['success']), 'Защита не снята', json_encode($res, JSON_UNESCAPED_UNICODE));
        $at_assert(($res['mode'] ?? '') === 'smb', 'Защита снимается не транспортом', json_encode($res, JSON_UNESCAPED_UNICODE));
        $calls = (string)file_get_contents($log);
        $at_assert($calls !== '' && stripos($calls, 'setmode') !== false, 'Команда setmode на сервер не отправлена', $calls);
        $at_assert(strpos($calls, 'QuestionStudio.exe') === false, 'Плагин трогает чужие файлы на share', $calls);
        $at_assert(stripos($calls, 'сводный_протокол.csv') !== false, 'Имя файла плагина в команде не видно', $calls);
        $at_assert(stripos($calls, '0613669172928f87\\') !== false || stripos($calls, '0613669172928f87\\"') !== false, 'setmode отправлен с косыми чертами', $calls);
        $at_assert(stripos($calls, '-r') !== false, 'Флаг снятия атрибутов не передан', $calls);
        $at_assert((int)($res['unprotected'] ?? 0) === 1, 'Число снятых защит неверно', json_encode($res, JSON_UNESCAPED_UNICODE));
        $st = json_decode((string)file_get_contents($state), true);
        // Заглушка приводит пути к нижнему регистру — сравниваем так же.
        $readonly = array_map('strtolower', array_map('strval', (array)($st['readonly'] ?? [])));
        $left = array_values(array_filter($readonly, static fn($k) => $k !== strtolower($foreign)));
        $at_assert($left === [], 'Защита файла плагина осталась', json_encode($st['readonly'] ?? []));
        $at_assert(in_array(strtolower($foreign), $readonly, true), 'Чужой файл потерял защиту, хотя плагин его не трогает', json_encode($st['readonly'] ?? []));

        // Строки pending тоже участвуют: защита снимается и с того, что не донеслось.
        $att2 = '6ca192eb137477ebb3b98c99.docx';
        $name_csv = 'сводный_протокол.csv';
        update_option(Attachments::pending_option($id), [[
            'bucket' => '0613669172928f87',
            'name' => $att2,
            'rel' => '0613669172928f87/' . $att2,
            'error' => 'файл защищён',
            'codes' => ['NT_STATUS_OBJECT_NAME_COLLISION'],
        ]], false);
        $st = json_decode((string)file_get_contents($state), true);
        $att_key = strtolower(str_replace('/', '\\', 'olimp/вложения/' . $slug . '/0613669172928f87/6ca192eb137477ebb3b98c99.docx'));
        $st['files'][$att_key] = ['size' => 40];
        $st['readonly'] = [$att_key];
        file_put_contents($state, json_encode($st));
        // Часть файлов уже лежала на шаре (отметка о доставке): снятие защиты
        // обязана учитывать оба списка — и недонесённое, и доставленное.
        Attachments::mark_delivered($quiz, [ '0613669172928f87/' . $name_csv => 12 ]);
        $res2 = Attachments::unprotect_share($quiz);
        $at_assert(($res2['total'] ?? 0) >= 2, 'Список защищённых файлов неполный', json_encode($res2, JSON_UNESCAPED_UNICODE));
        $at_assert((int)($res2['unprotected'] ?? 0) >= 1, 'Защита с недоставленного файла не снята', json_encode($res2, JSON_UNESCAPED_UNICODE));
        $at_assert(stripos((string)file_get_contents($log), $att2) !== false, 'Недоставленный файл в команду не попал');

        // Интерфейс: кнопка и обработчик существуют, старая формулировка уходит.
        $repo = (string)(defined('WP_OLYMPIAD_PATH') ? WP_OLYMPIAD_PATH : dirname(__DIR__));
        $view = (string)file_get_contents($repo . '/admin/views/quiz-edit.php');
        $admin = (string)file_get_contents($repo . '/admin/class-admin.php');
        $at_assert(substr_count($view, 'Убрать защиту файлов') === 1, 'Кнопка снятия защиты не одна');
        $at_assert(stripos($view, 'по-прежнему недоступна для записи') === false, 'Старая формулировка «папка недоступна» осталась');
        $at_assert(stripos($view, 'wpo_action=unprotect_attachments') !== false, 'Кнопка не ведёт на обработчик снятия защиты', '');
        $at_assert(stripos($admin, "\$action === 'unprotect_attachments'") !== false && substr_count($admin, 'wpo_unprotect_attachments_') >= 1, 'Обработчик снятия защиты не подключён', '');
        return true;
    } finally {
        $cleanup();
        @unlink($state);
        if ($id) {
            delete_option(Attachments::pending_option($id));
            delete_option(Attachments::delivered_option($id));
            delete_option(Attachments::last_delivery_option($id));
            Attachments::reset_store_cache($id);
            Quiz::delete($id);
        }
    }
});

$it('1.0.36: отказ сервера по одному файлу не записывается на весь список', function () use ($at_assert, $at_smb_stub, $at_unc_host): bool {
    $state = sys_get_temp_dir() . '/wpo_smb_honest_' . wp_generate_password(6, false, false) . '.json';
    [, $log, $cleanup] = $at_smb_stub($state);
    $BS = chr(92);
    $tail = 'вложения' . $BS . 'olympiad-2' . $BS . '0613669172928f87';
    $present = 'olimp' . $BS . $tail . $BS . 'present.docx';
    $absent = 'olimp' . $BS . $tail . $BS . 'absent.docx';
    try {
        // Модель сервера: «present» лежит и защищён, файла «absent» нет вовсе.
        file_put_contents($state, json_encode([
            'dirs'     => ['olimp', 'olimp' . $BS . 'вложения'],
            'files'    => [ strtolower($present) => ['size' => 10] ],
            'readonly' => [ strtolower($present) ],
        ]));
        $config = ['type' => 'smb', 'path' => '//' . $at_unc_host . '/kvant/olimp', 'user' => '', 'password' => ''];
        $res = NetworkExport::smb_unprotect([
            $tail . '/present.docx',
            $tail . '/absent.docx',
        ], $config);
        $dump = json_encode($res, JSON_UNESCAPED_UNICODE);
        $at_assert(substr_count((string)file_get_contents($log), 'setmode') === 2, 'Команды отправлены не по всем файлам', (string)file_get_contents($log));
        $at_assert((int)($res['count'] ?? 0) === 1, 'Подтверждённым считается только тот файл, который сервер отдал', $dump);
        $at_assert((int)($res['failed'] ?? 0) === 1, 'Отказ по второму файлу потерян', $dump);
        $at_assert(!empty($res['success']), 'Частичный успех не распознан', $dump);
        $at_assert(($res['message'] ?? '') !== '', 'О частичном результате молчат', $dump);
        $st = json_decode((string)file_get_contents($state), true);
        $at_assert((array)($st['readonly'] ?? []) === [], 'Защиту не сняли с доступного файла', json_encode($st));

        // Когда не отдан ни один файл — успех объявлять нельзя.
        $res2 = NetworkExport::smb_unprotect([ $tail . '/absent.docx' ], $config);
        $dump2 = json_encode($res2, JSON_UNESCAPED_UNICODE);
        $at_assert(empty($res2['success']), 'Успех при отказе всех файлов', $dump2);
        $at_assert((int)($res2['count'] ?? 0) === 0, 'Счётчик снятых защит завышен', $dump2);
        $at_assert((int)($res2['failed'] ?? 0) === 1, 'Отказ не учтён', $dump2);

        // Интерфейс: частичный результат отличается и от «ок», и от «ошибки».
        $repo = (string)(defined('WP_OLYMPIAD_PATH') ? WP_OLYMPIAD_PATH : dirname(__DIR__));
        $admin = (string)file_get_contents($repo . '/admin/class-admin.php');
        $view = (string)file_get_contents($repo . '/admin/views/quiz-edit.php');
        $at_assert(stripos($admin, "attachment_unprotect=partial") !== false, 'Частичный результат не передаётся в редирект', '');
        $at_assert(stripos($view, "wpo_unp_notice === 'partial'") !== false, 'Частичный результат не показан организатору', '');
        return true;
    } finally {
        $cleanup();
        @unlink($state);
    }
});

$it('1.0.37: на шаре файл называется так, как его назвал участник, а не служебным хешем', function () use ($at_assert, $at_smb_stub, $at_unc_host, $at_file): bool {
    [$bin, $log, $cleanup] = $at_smb_stub();
    $BS = chr(92);
    $id = 0;
    try {
        $at_assert($bin !== '', 'Заглушка не готова');
        $id = (int)Quiz::create([
            'title'    => 'Вложения имена ' . wp_generate_password(6, false, false),
            'status'   => 'publish',
            'settings' => [
                'network_folder_type'   => 'smb',
                'network_folder_path'   => '//' . $at_unc_host . '/kvant/olimp/olympiad-2',
                'max_attempts'          => 0,
                'require_org'           => 0,
                'attachment_max_count'  => 4,
            ],
        ]);
        $qid = (int)Question::create([
            'quiz_id'       => $id,
            'question_type' => Attachments::TYPE,
            'title'         => 'Прикрепите скан титульной страницы.',
            'points'        => 10,
            'penalty'       => 0,
            'options'       => [],
        ]);
        $quiz  = Quiz::get($id);
        $at_assert(Attachments::mode($quiz) === 'spool', 'Ожидается доставка транспортом');
        $slug  = (string)Attachments::store($quiz)['slug'];
        $attempt = Attempt::start($id, ['name' => 'Иванов Иван', 'email' => 'ivan@example.invalid', 'participant_class' => '11']);
        $bucket  = Attachments::bucket($attempt->token);
        $file = Attachments::accept($quiz, $bucket, $qid, [
            'name'     => 'скан: титул (2)?.pdf',
            'tmp_name' => $at_file('pdf'),
            'error'    => UPLOAD_ERR_OK,
            'size'     => 10,
        ], Attachments::limits($quiz));
        $at_assert(is_array($file), 'Файл не принят', is_wp_error($file) ? $file->get_error_message() : '');

        $labels  = Attachments::share_labels($quiz);
        $numbers = Attachments::question_numbers($quiz);
        $folder  = Attachments::share_folder($bucket, $labels);
        $name    = (string)(Attachments::share_names($quiz, $bucket, $labels, $numbers)[(string)$file['id']] ?? '');
        $at_assert($folder === 'Иванов Иван — ' . (int)$attempt->id, 'Папка попытки на шаре собрана не так', $folder);
        $at_assert($name === 'Иванов Иван — 01 — скан титул (2).pdf', 'Имя файла на шаре собрано не так', $name);
        // Служебное имя в хранилище остаётся хешем: по нему нельзя угадать чужую работу.
        $at_assert(preg_match('#^[a-f0-9]{16}/[a-f0-9]{24}\.pdf$#', (string)$file['rel']) === 1, 'Хранилище переименовано', (string)$file['rel']);
        $at_assert((string)$file['name'] === 'скан титул (2).pdf', 'Имя для показа испорчено', (string)$file['name']);

        $submit = new WP_REST_Request('POST', RestApi::NAMESPACE . '/submit');
        $submit->set_body_params(['token' => $attempt->token, 'answers' => [$qid => [(string)$file['id']]]]);
        $sent = RestApi::handle_submit($submit);
        $at_assert(!is_wp_error($sent), 'Сдача отклонена', is_wp_error($sent) ? $sent->get_error_message() : '');

        $calls = (string)file_get_contents($log);
        $target = 'olimp' . $BS . 'olympiad-2' . $BS . 'вложения' . $BS . $slug . $BS . $folder . $BS . $name;
        $at_assert(stripos($calls, '"' . $target . '"') !== false, 'Файл на шару ушёл под другим именем', mb_substr($calls, 0, 600));
        $at_assert(stripos($calls, $bucket) === false, 'Корзина попытки утекла в путь на шаре', mb_substr($calls, 0, 600));
        $at_assert(stripos($calls, basename((string)$file['rel'])) === false, 'На шаре файл назван служебным хешем', mb_substr($calls, 0, 600));
        // olvimp, olvimp\olympiad-2, \вложения, <slug> и читаемая папка попытки.
        $at_assert(substr_count($calls, 'mkdir "') === 5, 'Цепочка каталогов не посегментная', $calls);
        $at_assert(stripos($calls, 'mkdir "olimp' . $BS . 'olympiad-2' . $BS . 'вложения' . $BS . $slug . $BS . $folder . '"') !== false, 'Читаемая папка попытки на шаре не создана', $calls);
        $at_assert(Attachments::pending($quiz) === [], 'Доставленный файл остался в списке недоставленного', json_encode(Attachments::pending($quiz), JSON_UNESCAPED_UNICODE));

        // Отметка о доставке помнит путь именно на шаре — по нему снимается защита.
        $marks = Attachments::delivered_marks(Quiz::get($id));
        $mark = $marks[(string)$file['rel']] ?? null;
        $at_assert(is_array($mark) && (string)($mark['share'] ?? '') === 'вложения/' . $slug . '/' . $folder . '/' . $name, 'Отметка о доставке без пути на шаре', json_encode($mark, JSON_UNESCAPED_UNICODE));
        return true;
    } finally {
        $cleanup();
        if ($id) {
            delete_option(Attachments::pending_option($id));
            delete_option(Attachments::delivered_option($id));
            delete_option(Attachments::last_delivery_option($id));
            Attachments::reset_store_cache($id);
            Quiz::delete($id);
        }
    }
});

$it('1.0.37: одинаковые имена участников не слипаются: « (2)», разные папки, повтор — те же имена', function () use ($at_assert, $at_smb_stub, $at_unc_host, $at_file): bool {
    [$bin, $log, $cleanup] = $at_smb_stub();
    $BS = chr(92);
    $id = 0;
    try {
        $at_assert($bin !== '', 'Заглушка не готова');
        $id = (int)Quiz::create([
            'title'    => 'Вложения дубли ' . wp_generate_password(6, false, false),
            'status'   => 'publish',
            'settings' => [
                'network_folder_type'  => 'smb',
                'network_folder_path'  => '//' . $at_unc_host . '/kvant/olimp/olympiad-2',
                'max_attempts'         => 0,
                'require_org'          => 0,
                'attachment_max_count' => 4,
            ],
        ]);
        $qid = (int)Question::create([
            'quiz_id'       => $id,
            'question_type' => Attachments::TYPE,
            'title'         => 'Прикрепите файл.',
            'points'        => 10,
            'penalty'       => 0,
            'options'       => [],
        ]);
        $quiz = Quiz::get($id);
        $slug = (string)Attachments::store($quiz)['slug'];
        $limits = Attachments::limits($quiz);

        $a1 = Attempt::start($id, ['name' => 'Два', 'email' => 'a@example.invalid', 'participant_class' => '11']);
        $b1 = Attachments::bucket($a1->token);
        $f1 = Attachments::accept($quiz, $b1, $qid, ['name' => 'файл.pdf', 'tmp_name' => $at_file('pdf'), 'error' => UPLOAD_ERR_OK, 'size' => 10], $limits);
        $f2 = Attachments::accept($quiz, $b1, $qid, ['name' => 'файл.pdf', 'tmp_name' => $at_file('pdf'), 'error' => UPLOAD_ERR_OK, 'size' => 11], $limits);
        $a2 = Attempt::start($id, ['name' => 'Два', 'email' => 'b@example.invalid', 'participant_class' => '9']);
        $b2 = Attachments::bucket($a2->token);
        $f3 = Attachments::accept($quiz, $b2, $qid, ['name' => 'файл.pdf', 'tmp_name' => $at_file('pdf'), 'error' => UPLOAD_ERR_OK, 'size' => 12], $limits);
        foreach ([$f1, $f2, $f3] as $one) {
            $at_assert(is_array($one), 'Файл не принят', is_wp_error($one) ? $one->get_error_message() : '');
        }

        $labels  = Attachments::share_labels($quiz);
        $numbers = Attachments::question_numbers($quiz);
        $names1  = Attachments::share_names($quiz, $b1, $labels, $numbers);
        $names2  = Attachments::share_names($quiz, $b2, $labels, $numbers);
        // Кто из двух получит « (2)», предсказуемо (по id записи), а не по составу батча.
        $two = array_values($names1);
        sort($two);
        $at_assert($two === ['Два — 01 — файл (2).pdf', 'Два — 01 — файл.pdf'], 'Одинаковые имена не разошлись', json_encode($names1, JSON_UNESCAPED_UNICODE));
        $at_assert($names1[(string)$f1['id']] !== $names1[(string)$f2['id']], 'Два файла попытки получили одно имя', json_encode($names1, JSON_UNESCAPED_UNICODE));
        $at_assert(($names2[(string)$f3['id']] ?? '') === 'Два — 01 — файл.pdf', 'Сосед по фамилии не получил своё имя в своей папке', json_encode($names2, JSON_UNESCAPED_UNICODE));
        $at_assert(Attachments::share_folder($b1, $labels) !== Attachments::share_folder($b2, $labels), 'Папки двух попыток совпали', Attachments::share_folder($b1, $labels));

        // Имена стабильны: повторный вызов даёт ровно тот же набор.
        $again = Attachments::share_names($quiz, $b1, $labels, $numbers);
        $at_assert($again === $names1, 'Имена поплыли при повторном подсчёте', json_encode([$names1, $again], JSON_UNESCAPED_UNICODE));

        $s1 = new WP_REST_Request('POST', RestApi::NAMESPACE . '/submit');
        $s1->set_body_params(['token' => $a1->token, 'answers' => [$qid => [(string)$f1['id'], (string)$f2['id']]]]);
        $r1 = RestApi::handle_submit($s1);
        $at_assert(!is_wp_error($r1), 'Сдача первой попытки отклонена', is_wp_error($r1) ? $r1->get_error_message() : '');
        $s2 = new WP_REST_Request('POST', RestApi::NAMESPACE . '/submit');
        $s2->set_body_params(['token' => $a2->token, 'answers' => [$qid => [(string)$f3['id']]]]);
        $r2 = RestApi::handle_submit($s2);
        $at_assert(!is_wp_error($r2), 'Сдача второй попытки отклонена', is_wp_error($r2) ? $r2->get_error_message() : '');

        $targets = [];
        preg_match_all('#put "[^"]*" "([^"]+)"#u', (string)file_get_contents($log), $m);
        foreach ($m[1] as $t) {
            $targets[strtolower($t)] = true;
        }
        $root = 'olimp' . $BS . 'olympiad-2' . $BS . 'вложения' . $BS . $slug;
        $want = [
            $root . $BS . Attachments::share_folder($b1, $labels) . $BS . 'Два — 01 — файл.pdf',
            $root . $BS . Attachments::share_folder($b1, $labels) . $BS . 'Два — 01 — файл (2).pdf',
            $root . $BS . Attachments::share_folder($b2, $labels) . $BS . 'Два — 01 — файл.pdf',
        ];
        foreach ($want as $w) {
            $at_assert(isset($targets[strtolower($w)]), 'Файл не ушёл по читаемому пути: ' . $w, implode(' | ', array_keys($targets)));
        }

        // Принудительная повторная доставка — те же имена, без « (3)» и без дублей.
        $before = (int)substr_count((string)file_get_contents($log), 'put "');
        $res = Attachments::redeliver(Quiz::get($id));
        $at_assert(($res['sent'] ?? 0) === 3, 'Принудительная доставка унесла не всё', json_encode($res, JSON_UNESCAPED_UNICODE));
        $after_log = (string)file_get_contents($log);
        $at_assert(substr_count($after_log, 'put "') === $before + 3, 'Запись не равна числу файлов', (string)$before);
        $targets2 = [];
        preg_match_all('#put "[^"]*" "([^"]+)"#u', $after_log, $m2);
        foreach ($m2[1] as $t) {
            $targets2[strtolower($t)] = true;
        }
        $at_assert(array_keys($targets2) === array_keys($targets), 'Повторная доставка переименовала файлы', json_encode([array_keys($targets), array_keys($targets2)], JSON_UNESCAPED_UNICODE));
        return true;
    } finally {
        $cleanup();
        if ($id) {
            delete_option(Attachments::pending_option($id));
            delete_option(Attachments::delivered_option($id));
            delete_option(Attachments::last_delivery_option($id));
            Attachments::reset_store_cache($id);
            Quiz::delete($id);
        }
    }
});

$it('1.0.37: снятие защиты и повтор идут по читаемым путям; старые хеш-файлы на шаре не трогаются', function () use ($at_assert, $at_smb_stub, $at_env, $at_unc_host, $at_file): bool {
    $state = sys_get_temp_dir() . '/wpo_smb_names_' . wp_generate_password(6, false, false) . '.json';
    [, $log, $cleanup] = $at_smb_stub($state);
    $BS = chr(92);
    $id = 0;
    try {
        $id = (int)Quiz::create([
            'title'    => 'Вложения защита имена ' . wp_generate_password(6, false, false),
            'status'   => 'publish',
            'settings' => [
                'network_folder_type'  => 'smb',
                'network_folder_path'  => '//' . $at_unc_host . '/kvant/olimp/olympiad-2',
                'max_attempts'         => 0,
                'require_org'          => 0,
                'attachment_max_count' => 4,
            ],
        ]);
        $qid = (int)Question::create([
            'quiz_id'       => $id,
            'question_type' => Attachments::TYPE,
            'title'         => 'Прикрепите решение.',
            'points'        => 10,
            'penalty'       => 0,
            'options'       => [],
        ]);
        $quiz = Quiz::get($id);
        $slug = (string)Attachments::store($quiz)['slug'];
        $attempt = Attempt::start($id, ['name' => 'Защита Имя', 'email' => 'z@example.invalid', 'participant_class' => '11']);
        $bucket = Attachments::bucket($attempt->token);
        $file = Attachments::accept($quiz, $bucket, $qid, [
            'name'     => 'решение.pdf',
            'tmp_name' => $at_file('pdf'),
            'error'    => UPLOAD_ERR_OK,
            'size'     => 10,
        ], Attachments::limits($quiz));
        $at_assert(is_array($file), 'Файл не принят', is_wp_error($file) ? $file->get_error_message() : '');
        $labels = Attachments::share_labels($quiz);
        $folder = Attachments::share_folder($bucket, $labels);
        $name = (string)(Attachments::share_names($quiz, $bucket, $labels, Attachments::question_numbers($quiz))[(string)$file['id']] ?? '');
        $at_assert($name === 'Защита Имя — 01 — решение.pdf', 'Имя на шаре собрано не так', $name);

        // Модель сервера: файл лежит под читаемым именем и защищён; рядом —
        // старый файл прошлой версии плагина, который плагин переименовывать не вправе.
        $root = 'olimp' . $BS . 'olympiad-2' . $BS . 'вложения' . $BS . $slug;
        $share_key = $root . $BS . $folder . $BS . $name;
        $legacy = $root . $BS . $bucket . $BS . '6ca192eb137477ebb3b98c99.pdf';
        file_put_contents($state, json_encode([
            'dirs'  => ['olimp', 'olimp' . $BS . 'вложения', 'olimp' . $BS . 'вложения' . $BS . $slug],
            'files' => [strtolower($share_key) => ['size' => 10], strtolower($legacy) => ['size' => 40]],
            'readonly' => [strtolower($share_key), strtolower($legacy)],
        ]));
        update_option(Attachments::pending_option($id), [[
            'bucket' => $bucket,
            'name'   => $name,
            'rel'    => 'вложения/' . $slug . '/' . $folder . '/' . $name,
            'share'  => 'вложения/' . $slug . '/' . $folder . '/' . $name,
            'error'  => 'файл защищён',
            'codes'  => ['NT_STATUS_OBJECT_NAME_COLLISION'],
        ]], false);
        Attachments::reset_store_cache($id);

        $res = Attachments::unprotect_share($quiz);
        $at_assert(!empty($res['success']), 'Защита не снята', json_encode($res, JSON_UNESCAPED_UNICODE));
        $at_assert((int)($res['unprotected'] ?? 0) === 1, 'Засчитано не то число снятий', json_encode($res, JSON_UNESCAPED_UNICODE));
        $calls = (string)file_get_contents($log);
        $at_assert(stripos($calls, 'setmode "' . $share_key . '" -r') !== false, 'Защита снимается не с читаемого пути', mb_substr($calls, 0, 600));
        $at_assert(stripos($calls, 'setmode "' . $legacy . '"') === false, 'Плагин трогает файл прошлого релиза', mb_substr($calls, 0, 600));
        $st = json_decode((string)file_get_contents($state), true);
        $readonly = array_map('strval', (array)($st['readonly'] ?? []));
        $at_assert(!in_array(strtolower($share_key), $readonly, true), 'Защита читаемого файла осталась', json_encode($st, JSON_UNESCAPED_UNICODE));
        $at_assert(in_array(strtolower($legacy), $readonly, true), 'Чужой файл потерял защиту', json_encode($st, JSON_UNESCAPED_UNICODE));
        $at_assert(preg_match('#(^|;)\s*(rm|del|rename|mv)\b#i', $calls) === 0, 'Плагин переименовывает или удаляет файлы на шаре', mb_substr($calls, 0, 600));
        return true;
    } finally {
        $cleanup();
        if ($id) {
            delete_option(Attachments::pending_option($id));
            delete_option(Attachments::delivered_option($id));
            delete_option(Attachments::last_delivery_option($id));
            Attachments::reset_store_cache($id);
            Quiz::delete($id);
        }
    }
});

$it('1.0.38: недоставленное донесётся само: планировщик, растущая пауза, тишина после успеха', function () use ($at_assert, $at_smb_stub, $at_env, $at_unc_host, $at_file): bool {
    [$bin, $log, $cleanup] = $at_smb_stub();
    $id = 0;
    try {
        $at_assert($bin !== '', 'Заглушка не готова');
        // Транспорт отвечает отказом на любую запись — ровно картина «шара жива,
        // а файлы не уходят», из-за которой организатор и жаловался на ручную кнопку.
        $at_env(['WPO_STUB_ALL_FAIL' => '1', 'WPO_STUB_STATUS' => 'BAD_NETWORK_NAME']);
        $id = (int)Quiz::create([
            'title'    => 'Вложения автосинхрон ' . wp_generate_password(6, false, false),
            'status'   => 'publish',
            'settings' => [
                'network_folder_type'  => 'smb',
                'network_folder_path'  => '//' . $at_unc_host . '/kvant/olimp/olympiad-2',
                'max_attempts'         => 0,
                'require_org'          => 0,
                'attachment_max_count' => 4,
            ],
        ]);
        $qid = (int)Question::create([
            'quiz_id'       => $id,
            'question_type' => Attachments::TYPE,
            'title'         => 'Прикрепите решение.',
            'points'        => 10,
            'penalty'       => 0,
            'options'       => [],
        ]);
        $quiz = Quiz::get($id);
        $at_assert(Attachments::mode($quiz) === 'spool', 'Ожидается доставка транспортом');
        $attempt = Attempt::start($id, ['name' => 'Авто_sync', 'email' => 'auto@example.invalid', 'participant_class' => '11']);
        $bucket = Attachments::bucket($attempt->token);
        $file = Attachments::accept($quiz, $bucket, $qid, [
            'name'     => 'работа.pdf',
            'tmp_name' => $at_file('pdf'),
            'error'    => UPLOAD_ERR_OK,
            'size'     => 10,
        ], Attachments::limits($quiz));
        $at_assert(is_array($file), 'Файл не принят', is_wp_error($file) ? $file->get_error_message() : '');

        $submit = new WP_REST_Request('POST', RestApi::NAMESPACE . '/submit');
        $submit->set_body_params(['token' => $attempt->token, 'answers' => [$qid => [(string)$file['id']]]]);
        $sent = RestApi::handle_submit($submit);
        $at_assert(!is_wp_error($sent), 'Сдача из-за отказа транспорта отклонена', is_wp_error($sent) ? $sent->get_error_message() : '');

        $pending = Attachments::pending($quiz);
        $at_assert(count($pending) === 1, 'Недоставленный файл не в списке', json_encode($pending, JSON_UNESCAPED_UNICODE));
        $at_assert(Attachments::delivered_marks($quiz) === [], 'Отказ записан как доставка');
        $when = wp_next_scheduled(Attachments::RETRY_HOOK, [$id]);
        $at_assert($when !== false, 'Автоматическая повторная доставка не запланирована', (string)json_encode(Attachments::retry_state($quiz)));
        $at_assert($when >= time() - 5, 'Повтор запланирован в прошлом', (string)$when);
        $state = Attachments::retry_state($quiz);
        $at_assert($state['tries'] === 1 && $state['delay'] === Attachments::RETRY_FLOOR, 'Первая пауза не 5 минут', json_encode($state));

        // Вторая неудача: та же строка списка (без роста), пауза вдвое длиннее.
        $again = Attachments::retry_pending($quiz);
        $at_assert((int)($again['sent'] ?? 0) === 0, 'Отказ посчитан доставкой', json_encode($again, JSON_UNESCAPED_UNICODE));
        $at_assert(count(Attachments::pending($quiz)) === 1, 'Список недоставленного раздувается от попыток', json_encode(Attachments::pending($quiz), JSON_UNESCAPED_UNICODE));
        $state = Attachments::retry_state($quiz);
        $at_assert($state['tries'] === 2, 'Число попыток не выросло', json_encode($state));
        $at_assert($state['delay'] === 2 * Attachments::RETRY_FLOOR, 'Пауза не удвоилась', json_encode($state));

        // Транспорт ожил — автоматический проход доносит файл и гасит планировщик.
        $at_env(['WPO_STUB_ALL_FAIL' => '', 'WPO_STUB_STATUS' => '']);
        $res = Attachments::retry_pending($quiz);
        $at_assert((int)($res['sent'] ?? 0) === 1, 'Автоматический проход не донёс файл', json_encode($res, JSON_UNESCAPED_UNICODE));
        $at_assert(Attachments::pending($quiz) === [], 'Список недоставленного не очищен после успеха', json_encode(Attachments::pending($quiz)));
        $marks = Attachments::delivered_marks($quiz);
        $at_assert(count($marks) === 1 && isset($marks[(string)$file['rel']]), 'Отметка о доставке не появилась', json_encode(array_keys($marks)));
        $at_assert(wp_next_scheduled(Attachments::RETRY_HOOK, [$id]) === false, 'Повтор не снят после успеха', (string)wp_next_scheduled(Attachments::RETRY_HOOK, [$id]));
        $at_assert(Attachments::retry_state($quiz)['tries'] === 0, 'Счётчик попыток не обнулён', json_encode(Attachments::retry_state($quiz)));
        $last = Attachments::last_delivery($quiz);
        $at_assert((int)($last['sent'] ?? 0) >= 1 && ($last['mode'] ?? '') === 'auto', 'Отчёт об автоматической доставке не сохранён', json_encode($last, JSON_UNESCAPED_UNICODE));
        return true;
    } finally {
        $at_env(['WPO_STUB_ALL_FAIL' => '0', 'WPO_STUB_STATUS' => '']);
        $cleanup();
        if ($id) {
            delete_option(Attachments::pending_option($id));
            delete_option(Attachments::delivered_option($id));
            delete_option(Attachments::last_delivery_option($id));
            delete_option(Attachments::retry_option($id));
            Attachments::reset_store_cache($id);
            Quiz::delete($id);
        }
    }
});

$it('1.0.38: ленивая синхронизация при открытии страницы не долбит шару каждые два клика', function () use ($at_assert, $at_smb_stub, $at_env, $at_unc_host, $at_file): bool {
    [$bin, $log, $cleanup] = $at_smb_stub();
    $id = 0;
    try {
        $at_assert($bin !== '', 'Заглушка не готова');
        $at_env(['WPO_STUB_ALL_FAIL' => '1', 'WPO_STUB_STATUS' => 'BAD_NETWORK_NAME']);
        $id = (int)Quiz::create([
            'title'    => 'Вложения лень-синхрон ' . wp_generate_password(6, false, false),
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
            'question_type' => Attachments::TYPE,
            'title'         => 'Прикрепите решение.',
            'points'        => 5,
            'penalty'       => 0,
            'options'       => [],
        ]);
        $quiz = Quiz::get($id);
        $attempt = Attempt::start($id, ['name' => 'Ленивый', 'email' => 'lazy@example.invalid', 'participant_class' => '11']);
        $bucket = Attachments::bucket($attempt->token);
        $file = Attachments::accept($quiz, $bucket, $qid, [
            'name'     => 'лен.pdf',
            'tmp_name' => $at_file('pdf'),
            'error'    => UPLOAD_ERR_OK,
            'size'     => 10,
        ], Attachments::limits($quiz));
        $at_assert(is_array($file), 'Файл не принят');
        // Недоставленное появляется после попытки доставки: без неё списку не
        // откуда взяться, и проверять паузу было бы не на чём.
        $first_try = Attachments::flush_to_share($quiz, $bucket, [(string)$file['id']]);
        $at_assert((int)($first_try['copied'] ?? 0) === 0, 'Отказ не случился', json_encode($first_try, JSON_UNESCAPED_UNICODE));
        $at_assert(count(Attachments::pending($quiz)) === 1, 'Список недоставленного пуст', json_encode(Attachments::pending($quiz), JSON_UNESCAPED_UNICODE));
        $put_before = substr_count((string)file_get_contents($log), 'put "');

        $first = Attachments::sync_pending_now($id, false);
        $at_assert(isset($first['pending']) && (int)$first['pending'] === 1, 'Первый проход не состоялся', json_encode($first, JSON_UNESCAPED_UNICODE));
        $at_assert(substr_count((string)file_get_contents($log), 'put "') > $put_before, 'Проход не тронул транспорт');
        $cooldown = substr_count((string)file_get_contents($log), 'put "');

        // Второй заход сразу же — отказ по паузе, шару не долбим.
        $second = Attachments::sync_pending_now($id, false);
        $at_assert(($second['skipped'] ?? '') === 'cooldown', 'Пауза ленивой синхронизации не выдержана', json_encode($second, JSON_UNESCAPED_UNICODE));
        $at_assert(substr_count((string)file_get_contents($log), 'put "') === $cooldown, 'Команды ушли во время паузы');

        // Принудительно (кнопка, отладка) — можно и сразу.
        $third = Attachments::sync_pending_now($id, true);
        $at_assert(($third['skipped'] ?? '') !== 'cooldown', 'Принудительный проход заблокирован паузой', json_encode($third, JSON_UNESCAPED_UNICODE));
        $at_assert(substr_count((string)file_get_contents($log), 'put "') > $cooldown, 'Принудительный проход не дошёл до транспорта');

        // Пустой список — планировщик чистится сам.
        update_option(Attachments::pending_option($id), [], false);
        $at_assert(Attachments::sync_pending_now($id, true) === [], 'Пустой список должен давать пустой результат');
        $at_assert(wp_next_scheduled(Attachments::RETRY_HOOK, [$id]) === false, 'Событие осталось на пустом списке');
        return true;
    } finally {
        $at_env(['WPO_STUB_ALL_FAIL' => '0', 'WPO_STUB_STATUS' => '']);
        $cleanup();
        if ($id) {
            delete_option(Attachments::pending_option($id));
            delete_option(Attachments::delivered_option($id));
            delete_option(Attachments::last_delivery_option($id));
            delete_option(Attachments::retry_option($id));
            Attachments::reset_store_cache($id);
            Quiz::delete($id);
        }
    }
});

$it('1.0.38: хуки автодоставки подключены к загрузке плагина и переживают отказ планировщика', function () use ($at_assert): bool {
    $repo = (string)(defined('WP_OLYMPIAD_PATH') ? WP_OLYMPIAD_PATH : dirname(__DIR__));
    $boot = (string)file_get_contents($repo . '/wp-olympiad.php');
    $at_assert(stripos($boot, 'Attachments::init()') !== false, 'Attachments::init() не вызывается при загрузке плагина');
    $at_assert(stripos($boot, 'wp_unschedule_hook(\\WPOlympiad\\Attachments::RETRY_HOOK)') !== false, 'События не снимаются при деактивации');
    $at_assert(has_action(Attachments::RETRY_HOOK) !== false, 'Хук планировщика не зарегистрирован', var_export(has_action(Attachments::RETRY_HOOK), true));
    $at_assert(is_int(has_action('admin_init', [Attachments::class, 'maybe_sync_on_admin'])), 'Ленивая синхронизация не подключена к admin_init');
    $at_assert(Attachments::RETRY_FLOOR === 300 && Attachments::RETRY_CEIL === 21600, 'Паузы автоповтора не те');

    // Задержка считается по числу отказов подряд и упирается в потолок.
    $filter = static fn($delay, $quiz, $tries) => $tries >= 3 ? 20 : $delay;
    $id = (int)Quiz::create([
        'title'    => 'Вложения паузы ' . wp_generate_password(6, false, false),
        'status'   => 'publish',
        'settings' => ['network_folder_type' => 'local', 'network_folder_path' => sys_get_temp_dir() . '/wpo_at_' . wp_generate_password(6, false, false), 'max_attempts' => 0],
    ]);
    try {
        $quiz = Quiz::get($id);
        update_option(Attachments::retry_option($id), ['tries' => 0, 'next' => 0, 'last' => 0, 'delay' => 0], false);
        $at_assert(Attachments::retry_delay($quiz) === Attachments::RETRY_FLOOR, 'Нулевая пауза не 5 минут');
        update_option(Attachments::retry_option($id), ['tries' => 8, 'next' => 0, 'last' => 0, 'delay' => 0], false);
        $at_assert(Attachments::retry_delay($quiz) === Attachments::RETRY_CEIL, 'Пауза не упёрлась в потолок', (string)Attachments::retry_delay($quiz));
        add_filter('wpo_attachments_retry_delay', $filter, 10, 3);
        update_option(Attachments::retry_option($id), ['tries' => 3, 'next' => 0, 'last' => 0, 'delay' => 0], false);
        $at_assert(Attachments::retry_delay($quiz) === 60, 'Фильтр паузы не применён', (string)Attachments::retry_delay($quiz));
        remove_filter('wpo_attachments_retry_delay', $filter, 10);

        // Событие планировщика на несуществующую олимпиаду не роняет крон.
        $res = Attachments::handle_retry_event(99999999);
        $at_assert(($res['skipped'] ?? '') === 'no_quiz', 'Событие на удалённую олимпиаду не распознано', json_encode($res));
        return true;
    } finally {
        delete_option(Attachments::retry_option($id));
        Quiz::delete($id);
    }
});

$it('1.0.38: организатору видно, что повтор запланирован, а не «нажмите кнопку»', function () use ($at_assert): bool {
    $repo = (string)(defined('WP_OLYMPIAD_PATH') ? WP_OLYMPIAD_PATH : dirname(__DIR__));
    $view = (string)file_get_contents($repo . '/admin/views/quiz-edit.php');
    $at_assert(stripos($view, 'Плагин донесёт сам') !== false, 'В списке недоставленного не сказано про автоматический повтор');
    $at_assert(stripos($view, 'Attachments::retry_state($quiz)') !== false, 'План повтора не показан');
    $at_assert(stripos($view, 'попыток: %1$d, следующая не раньше чем через %2$d мин.') !== false, 'Счётчик попыток и пауза не выведены');
    $at_assert(stripos($view, 'Повтор — при ближайшей выгрузке протоколов.') === false, 'Старая формулировка «только при выгрузке» осталась');
    return true;
});

$it('1.0.39: папка вложений на шаре создаётся проверкой, без единого сданного файла', function () use ($at_assert, $at_smb_stub, $at_env, $at_unc_host): bool {
    [$bin, $log, $cleanup] = $at_smb_stub();
    $id = 0;
    try {
        $at_assert($bin !== '', 'Заглушка не готова');
        $id = (int)Quiz::create([
            'title'    => 'Вложения папка-проба ' . wp_generate_password(6, false, false),
            'status'   => 'publish',
            'settings' => [
                'network_folder_type' => 'smb',
                'network_folder_path' => '//' . $at_unc_host . '/kvant/olimp/olympiad-2',
                'max_attempts'        => 0,
                'require_org'         => 0,
            ],
        ]);
        $qid = (int)Question::create([
            'quiz_id' => $id, 'question_type' => 'file', 'title' => 'Файл', 'points' => 5, 'penalty' => 0, 'options' => [],
        ]);
        $quiz = Quiz::get($id);
        $at_assert((int)$qid > 0 && $quiz !== null, 'Олимпиада не готова');
        $slug = Attachments::store($quiz)['slug'];
        $at_assert(Attachments::mode($quiz) === 'spool', 'Ожидается доставка транспортом', Attachments::mode($quiz));

        $res = Attachments::provision_share_root($quiz, true);
        $at_assert(!empty($res['ok']), 'Папка не создана', json_encode($res, JSON_UNESCAPED_UNICODE));
        $at_assert(($res['mode'] ?? '') === 'smb', 'Проба пошла не тем транспортом', json_encode($res));
        $at_assert((int)$res['created'] >= 1, 'Счётчик созданных каталогов пуст', json_encode($res));
        $at_assert(str_ends_with((string)$res['target'], '/' . Attachments::DIR . '/' . $slug)
            || str_ends_with((string)$res['target'], '\\' . Attachments::DIR . '\\' . $slug),
            'Цель указана не папкой вложений', (string)$res['target']);
        // «unc» уже содержит подкаталог шары — в цели он не должен повторяться.
        $at_assert(substr_count((string)$res['target'], 'olympiad-2') === 1, 'Подкаталог шары задвоен в цели', (string)$res['target']);

        $calls = (string)@file_get_contents($log);
        foreach ([
            'mkdir "olimp"',
            'mkdir "olimp\\olympiad-2"',
            'mkdir "olimp\\olympiad-2\\' . Attachments::DIR . '"',
            'mkdir "olimp\\olympiad-2\\' . Attachments::DIR . '\\' . $slug . '"',
            'cd "olimp\\olympiad-2\\' . Attachments::DIR . '\\' . $slug . '"',
        ] as $cmd) {
            $at_assert(strpos($calls, $cmd) !== false, 'Команда не ушла на сервер: ' . $cmd, $calls);
        }
        $at_assert(stripos($calls, 'put ') === false, 'Проба папки что-то записала', $calls);

        $state = Attachments::probe_state($quiz);
        $at_assert($state['ok'] && $state['when'] > 0, 'Состояние пробы не записано', json_encode($state, JSON_UNESCAPED_UNICODE));
        $at_assert(count(Attachments::pending($quiz)) === 0, 'Проба создаёт очередь недоставленного', json_encode(Attachments::pending($quiz), JSON_UNESCAPED_UNICODE));
        return true;
    } finally {
        $cleanup();
        if ($id) {
            delete_option(Attachments::probe_option($id));
            delete_option(Attachments::pending_option($id));
            delete_option(Attachments::delivered_option($id));
            delete_option(Attachments::retry_option($id));
            Attachments::reset_store_cache($id);
            Quiz::delete($id);
        }
    }
});

$it('1.0.39: отказ транспорта виден в пробе папки, а частые повторы не долбят шару', function () use ($at_assert, $at_smb_stub, $at_env, $at_unc_host): bool {
    [$bin, $log, $cleanup] = $at_smb_stub();
    $id = 0;
    try {
        $at_env(['WPO_STUB_GLOBAL' => 'ACCESS_DENIED']);
        $id = (int)Quiz::create([
            'title'    => 'Вложения папка-отказ ' . wp_generate_password(6, false, false),
            'status'   => 'publish',
            'settings' => [
                'network_folder_type' => 'smb',
                'network_folder_path' => '//' . $at_unc_host . '/kvant/olimp/olympiad-9',
                'max_attempts'        => 0,
                'require_org'         => 0,
            ],
        ]);
        Question::create(['quiz_id' => $id, 'question_type' => 'file', 'title' => 'Файл', 'points' => 5, 'penalty' => 0, 'options' => []]);
        $quiz = Quiz::get($id);

        $res = Attachments::provision_share_root($quiz, true);
        $at_assert(empty($res['ok']), 'Отказ сервера записан как успех', json_encode($res, JSON_UNESCAPED_UNICODE));
        $at_assert(trim((string)$res['message']) !== '', 'Причина отказа пропала', json_encode($res, JSON_UNESCAPED_UNICODE));
        $at_assert(stripos(implode(',', (array)$res['codes']), 'ACCESS_DENIED') !== false, 'Код сервера не сохранён', json_encode($res));

        // Тот же путь сразу второй раз: новую команду отправлять рано.
        $calls_before = (string)@file_get_contents($log);
        $again = Attachments::provision_share_root($quiz);
        $at_assert(($again['skipped'] ?? '') === 'fresh', 'Неудачная проба повторяется на каждом чихе', json_encode($again, JSON_UNESCAPED_UNICODE));
        $at_assert((string)@file_get_contents($log) === $calls_before, 'Пауза не спасла шару от лишних команд');

        // Кнопка организатора обходит паузу.
        $forced = Attachments::provision_share_root($quiz, true);
        $at_assert(!isset($forced['skipped']), 'Принудительная проба пропущена', json_encode($forced, JSON_UNESCAPED_UNICODE));
        $at_assert((string)@file_get_contents($log) !== $calls_before, 'Принудительная проба не дошла до транспорта');

        // Смена пути сетевой папки — отметка «проверено» больше не действительна.
        $at_env(['WPO_STUB_GLOBAL' => '']);
        $quiz->update(['settings' => [
            'network_folder_type' => 'smb',
            'network_folder_path' => '//' . $at_unc_host . '/kvant/olimp/olympiad-8',
            'max_attempts'        => 0,
            'require_org'         => 0,
        ]]);
        Attachments::reset_store_cache($id);
        $fresh_path = Attachments::provision_share_root($quiz);
        $at_assert(!isset($fresh_path['skipped']), 'Проба не повторилась после смены пути', json_encode($fresh_path, JSON_UNESCAPED_UNICODE));
        $at_assert(!empty($fresh_path['ok']), 'На новом пути папка не создалась', json_encode($fresh_path, JSON_UNESCAPED_UNICODE));
        $at_assert(strpos((string)@file_get_contents($log), 'mkdir "olimp\\olympiad-8"') !== false, 'Новый путь не попал в команды', (string)@file_get_contents($log));
        return true;
    } finally {
        $at_env(['WPO_STUB_GLOBAL' => '']);
        $cleanup();
        if ($id) {
            delete_option(Attachments::probe_option($id));
            delete_option(Attachments::pending_option($id));
            delete_option(Attachments::delivered_option($id));
            delete_option(Attachments::retry_option($id));
            Attachments::reset_store_cache($id);
            Quiz::delete($id);
        }
    }
});

$it('1.0.39: путь, доступный PHP как папка, строится на файловой системе, а не транспортом', function () use ($at_assert, $at_fixture, $at_drop): bool {
    [$root, $id, $qid] = $at_fixture();
    try {
        $quiz = Quiz::get($id);
        $at_assert($quiz !== null, 'Олимпиада не создана');
        $at_assert(Attachments::mode($quiz) === 'share', 'Ожидается прямой доступ к папке', Attachments::mode($quiz));
        $res = Attachments::provision_share_root($quiz, true);
        $at_assert(!empty($res['ok']), 'Папка вложений не готова', json_encode($res, JSON_UNESCAPED_UNICODE));
        $at_assert(($res['mode'] ?? '') === 'filesystem', 'Локальный путь проверен не как путь', json_encode($res));
        $slug = Attachments::store($quiz)['slug'];
        $at_assert(is_dir($root . '/' . Attachments::DIR . '/' . $slug), 'Каталог вложений не создан', $root . '/' . Attachments::DIR . '/' . $slug);

        // И прямое вызов транспортного построителя: цепочка из двух сегментов.
        $made = NetworkExport::ensure_remote_dir(Attachments::DIR . '/глубже/ещё', ['type' => 'local', 'path' => $root]);
        $at_assert(!empty($made['success']), 'Цепочка каталогов не собрана', json_encode($made, JSON_UNESCAPED_UNICODE));
        $at_assert((int)$made['created'] === 2, 'Создано не два каталога', json_encode($made));
        $at_assert(is_dir($root . '/' . Attachments::DIR . '/глубже/ещё'), 'Каталог не появился на диске');
        return true;
    } finally {
        if ($id) {
            delete_option(Attachments::probe_option($id));
            Attachments::reset_store_cache($id);
        }
        $at_drop($root, $id);
    }
});

$it('1.0.39: «нечего везти» говорит причину, а не молчит', function () use ($at_assert, $at_smb_stub, $at_file, $at_unc_host): bool {
    [$bin, $log, $cleanup] = $at_smb_stub();
    $id = 0;
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
            'quiz_id' => $id, 'question_type' => 'file', 'title' => 'Файл', 'points' => 5, 'penalty' => 0, 'options' => [],
        ]);
        $quiz = Quiz::get($id);
        $attempt = Attempt::start($id, ['name' => 'Причина_отказа', 'email' => 'pr@example.invalid', 'participant_class' => '9']);
        $bucket = Attachments::bucket($attempt->token);
        $file = Attachments::accept($quiz, $bucket, $qid, [
            'name' => 'скан.pdf', 'tmp_name' => $at_file('pdf'), 'error' => UPLOAD_ERR_OK, 'size' => 10,
        ], Attachments::limits($quiz));
        $at_assert(is_array($file), 'Файл не принят', is_wp_error($file) ? $file->get_error_message() : '');

        // Файла в рабочей папке нет: доставка не выполнялась, и это сказано словами.
        $void = Attachments::flush_to_share($quiz, $bucket, ['000000000000000000000000']);
        $at_assert((int)$void['missing'] === 1, 'Пропажа файла не замечена', json_encode($void, JSON_UNESCAPED_UNICODE));
        $at_assert(stripos((string)$void['message'], 'рабочей папке') !== false, 'Причина «файл не найден» не сформулирована', json_encode($void, JSON_UNESCAPED_UNICODE));

        // Файл отмечен доставленным: повтор не пишется заново — и это тоже сказано.
        $first = Attachments::flush_to_share($quiz, $bucket, [(string)$file['id']]);
        $at_assert((int)$first['copied'] === 1, 'Первая доставка не состоялась', json_encode($first, JSON_UNESCAPED_UNICODE));
        $second = Attachments::flush_to_share($quiz, $bucket, [(string)$file['id']]);
        $at_assert((int)$second['copied'] === 0 && (int)$second['already'] === 1, 'Повтор посчитан доставкой', json_encode($second, JSON_UNESCAPED_UNICODE));
        $at_assert(stripos((string)$second['message'], 'отмечен') !== false, 'Причина «уже доставлено» не сформулирована', json_encode($second, JSON_UNESCAPED_UNICODE));
        return true;
    } finally {
        $cleanup();
        if ($id) {
            delete_option(Attachments::probe_option($id));
            delete_option(Attachments::pending_option($id));
            delete_option(Attachments::delivered_option($id));
            delete_option(Attachments::last_delivery_option($id));
            delete_option(Attachments::retry_option($id));
            Attachments::reset_store_cache($id);
            Quiz::delete($id);
        }
    }
});

$it('1.0.39: интерфейс сообщает состояние папки вложений и даёт кнопку проверки', function () use ($at_assert): bool {
    $view = (string)file_get_contents(WP_OLYMPIAD_PATH . 'admin/views/quiz-edit.php');
    $admin = (string)file_get_contents(WP_OLYMPIAD_PATH . 'admin/class-admin.php');
    $att = (string)file_get_contents(WP_OLYMPIAD_PATH . 'includes/class-attachments.php');
    foreach ([
        'Проверить папку вложений',
        'Папка вложений на сетевой папке',
        'Папку вложений создать не удалось',
        'wpo_action=probe_attachments',
    ] as $needle) {
        $at_assert(strpos($view, $needle) !== false, 'В форме редактора нет строки: ' . $needle);
    }
    $at_assert(strpos($admin, "'probe_attachments'") !== false, 'Обработчик кнопки не подключён');
    $at_assert(strpos($admin, 'provision_after_save') !== false, 'Проба не запускается после сохранения настроек');
    $at_assert(strpos($att, "public const PROBE_OK_TTL = 43200;") !== false, 'Нет паузы успешной пробы');
    $at_assert(strpos($att, "public const PROBE_FAIL_FLOOR = 300;") !== false, 'Нет паузы неудачной пробы');
    $at_assert(strpos($att, 'wpo_attachment_probe_') !== false, 'Запись состояния пробы не названа');
    $at_assert(Attachments::PROBE_OK_TTL === 43200 && Attachments::PROBE_FAIL_FLOOR === 300, 'Паузы пробы перепутаны');
    return true;
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
