<?php
/**
 * 1.0.27: зеркало активного прохождения в localStorage.
 *
 * Функция целиком клиентская, поэтому проверка двусторонняя: в JS обязаны
 * быть хуки зеркала и восстановления, а в PHP — по-прежнему не быть серверному
 * черновику, который снимали с поставки в 1.0.26.
 */

namespace WPOlympiad\Tests;

use WPOlympiad\Attempt;
use WPOlympiad\DB;
use WPOlympiad\Question;
use WPOlympiad\Quiz;

if (!defined('ABSPATH') || !isset($it)) return;

$qq = static function (bool $ok, string $message, string $extra = ''): void {
    if (!$ok) {
        throw new \RuntimeException($extra !== '' ? $message . ' — ' . $extra : $message);
    }
};

$it('Фронтенд: зеркало прохождения пишется, читается и снимается после сдачи', function () use ($qq): bool {
    $js = (string)file_get_contents(dirname(__DIR__) . '/public/js/olympiad-frontend.js');
    $qq($js !== '', 'Не читается олимпийский фронтенд');

    $required = [
        "'wpo-run-v1:'",                // ключ зеркала
        'tryRestoreRun()',               // восстановление при загрузке страницы
        'readMirror()',
        'recordMirror()',
        'clearMirror()',
        'startMirror()',
        'stopMirror()',
        'MIRROR_MAX_BYTES',              // порог объёма: не тащим мегабайты в хранилище
        'applyRunData(data, remaining)', // общий код старта и восстановления
        'this.runPayload = data;',       // payload /start запоминается для восстановления
        'initTimer(remaining)',          // остаток времени от сервера, а не новый отсчёт
        'remaining_seconds',
        "addEventListener('pagehide'",   // запись перед выгрузкой страницы
        'this.restorePromise',           // старт ждёт результат восстановления
        'syncRemainingOnReturn()',       // сверка остатка при возврате на вкладку
        'stopRemainingOnReturn()',
        'this.resultsShown = true;',     // после сдачи слушатель больше не стреляет
        'now - this._lastReturnSync < 3000', // и не спамит запросами
    ];
    foreach ($required as $needle) {
        $qq(strpos($js, $needle) !== false, 'Во фронтенде не найдено: ' . $needle);
    }

    // Сданная работа обязана забирать зеркало с собой, иначе следующая
    // перезагрузка вернёт участника в несуществующую попытку.
    // 1.0.28 вставила между этими шагами stopRemainingOnReturn() — проверяем
    // последовательность, допуская снятие слушателей возврата на вкладку.
    $qq(
        preg_match('/stopMirror\(\);\s*\n\s*(?:this\.stopRemainingOnReturn\(\);\s*\n\s*)?this\.clearMirror\(\);\s*\n\s*this\.renderResults\(data\);/', $js) === 1,
        'После успешной сдачи зеркало не снимается'
    );

    // Ошибку «нет такой попытки» серверного черновика здесь быть не может:
    // восстановление проверяет живость попытки существующим /heartbeat.
    // «Попытки нет» и «не удалось спросить» — разные случаи: во втором написанное
    // обязано остаться, поэтому проверка живости идёт мимо apiPost.
    $qq(strpos($js, 'async pingAttempt(token)') !== false, 'Нет отдельной проверки живости попытки');
    $qq(strpos($js, "return { status: 'unknown' };") !== false, 'Сетевой сбой не отделён от отказа сервера');
    $qq(strpos($js, "if (probe.status === 'unknown') {") !== false, 'Восстановление не различает unknown и dead');
    $qq(strpos($js, 'config.restUrl + chr(39) + /heartbeat') === false && strpos($js, "config.restUrl + '/heartbeat'") !== false,
        'Проверка попытки уходит не на /heartbeat');
    // Шесть мест: успех восстановления не чистит, чистят — dead/unknown-исключения,
    // успешная сдача, отсутствие попытки, провал applyRunData и (1.0.29) сброс
    // попытки за закрытый браузер в handleRunClosed().
    $qq(
        substr_count($js, 'this.clearMirror();') === 6,
        'Неожиданное число очисток зеркала',
        (string)substr_count($js, 'this.clearMirror();')
    );

    return true;
});


$it('1.0.27 не вернул серверный черновик: откат 1.0.26 держится', function () use ($qq): bool {
    $files = [
        'includes/class-rest-api.php',
        'includes/class-attempt.php',
        'includes/class-ajax.php',
        'includes/class-shortcodes.php',
        'includes/class-db.php',
        'public/css/olympiad-frontend.css',
        'public/js/olympiad-frontend.js',
    ];
    $src = '';
    foreach ($files as $rel) {
        $path = dirname(__DIR__) . '/' . $rel;
        $qq(is_file($path), 'Нет файла ' . $rel);
        $src .= (string)file_get_contents($path);
    }

    foreach ([
        "apiPost('/resume'", "apiPost('/save'", "apiPost('/abandon'",
        'draftState', 'wpo-draft-state', 'wpo-resume-later',
        'Черновик сохранён', 'Решить позже', 'draft_answers', 'draft_updated_at',
        'mark_abandoned', 'resume_attempt', "'abandoned'",
    ] as $forbidden) {
        $qq(strpos($src, $forbidden) === false, 'В поставке снова появилось: ' . $forbidden);
    }

    $routes = (string)file_get_contents(dirname(__DIR__) . '/includes/class-rest-api.php');
    $qq(preg_match("#register_rest_route\(self::NAMESPACE, '/(resume|save|abandon)'#", $routes) !== 1,
        'Зарегистрирован маршрут автосохранения');

    return true;
});
$it('1.0.28: время отсутствия засчитывается — остаток считается от started_at попытки', function () use ($qq): bool {
    global $wpdb;
    $table = DB::attempts_table();
    $token = 'abs' . substr(md5(uniqid('absence', true)), 0, 6);

    $quiz_id = (int)Quiz::create([
        'title'    => 'Отсутствие ' . $token,
        'status'   => 'publish',
        'settings' => ['max_attempts' => 0, 'time_limit' => 20, 'require_org' => 0, 'enable_intro_page' => 1],
    ]);
    Question::create(['quiz_id' => $quiz_id, 'question_type' => 'single', 'title' => 'В1', 'points' => 1]);

    try {
        $attempt = Attempt::start($quiz_id, ['name' => 'Анна Иванова', 'email' => $token . '@example.invalid', 'org' => 'Школа №1']);
        $qq($attempt instanceof Attempt, 'Попытка не создана');
        $id = (int)$attempt->id;

        $stored = (string)$wpdb->get_var($wpdb->prepare("SELECT started_at FROM {$table} WHERE id = %d", $id));
        $qq($stored !== '', 'started_at не записан');

        $shift = static function (int $seconds) use ($stored): string {
            // Naive-local то же, что и в плагине: сдвигаем строку как строку, без переводов
            // между часовыми поясами PHP и сайта.
            return gmdate('Y-m-d H:i:s', strtotime($stored) - $seconds);
        };
        $move = static function (int $seconds) use ($wpdb, $table, $id, $shift): void {
            $wpdb->update($table, ['started_at' => $shift($seconds)], ['id' => $id]);
        };

        $fresh = Attempt::get($id);
        $rem0 = $fresh->get_remaining_seconds(20);
        $qq($rem0 >= 1195 && $rem0 <= 1200, 'Свежая попытка: остаток должен быть почти полным лимитом', (string)$rem0);
        $qq($fresh->get_remaining_seconds(0) === 999999, 'Без личного лимита остаток не должен считаться');

        // 10 минут вне страницы — уходят из лимита, а не возвращаются
        $move(600);
        $after = Attempt::get($id);
        $rem = $after->get_remaining_seconds(20);
        $qq($rem >= 595 && $rem <= 605, '10 минут отсутствия не учтены в отсчёте', (string)$rem);
        $qq($after->is_expired(20, 45) === false, 'Попытка истекла раньше времени');

        // 20 минут + 30 с: минуты кончились, но сдача ещё в грейс-окне
        $move(20 * 60 + 30);
        $grace = Attempt::get($id);
        $qq($grace->get_remaining_seconds(20) === 0, 'Остаток не обнулился по истечении минут');
        $qq($grace->is_expired(20, 45) === false, 'Грейс-секунды на сетевую задержку не работают');

        // 21 минута: лимит выбран полностью, отсутствие не «прощается»
        $move(21 * 60);
        $dead = Attempt::get($id);
        $qq($dead->get_remaining_seconds(20) === 0, 'После полного лимита остаток не нулевой');
        $qq($dead->is_expired(20, 45) === true, 'Истёкшая попытка не помечается как истёкшая');

        // Клиент обязан об этом знать: показ берёт серверный остаток, а не свои часы
        $js = (string)file_get_contents(dirname(__DIR__) . '/public/js/olympiad-frontend.js');
        $qq(strpos($js, 'this.initTimer(remaining)') !== false, 'Старт отсчёта не берёт серверный остаток');
        $qq(strpos($js, 'syncRemainingOnReturn()') !== false, 'При возврате на вкладку остаток не сверяется с сервером');
        $qq(strpos($js, 'remaining_seconds') !== false, 'Данные heartbeat для отсчёта не используются');
    } finally {
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE quiz_id = %d", $quiz_id));
        $wpdb->delete(DB::responses_table(), ['attempt_id' => $id ?? 0]);
        $wpdb->delete(DB::questions_table(), ['quiz_id' => $quiz_id]);
        $wpdb->delete(DB::quizzes_table(), ['id' => $quiz_id]);
    }

    return true;
});
