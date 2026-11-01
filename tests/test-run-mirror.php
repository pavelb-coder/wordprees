<?php
/**
 * 1.0.27: зеркало активного прохождения в localStorage.
 *
 * Функция целиком клиентская, поэтому проверка двусторонняя: в JS обязаны
 * быть хуки зеркала и восстановления, а в PHP — по-прежнему не быть серверному
 * черновику, который снимали с поставки в 1.0.26.
 */

namespace WPOlympiad\Tests;

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
    ];
    foreach ($required as $needle) {
        $qq(strpos($js, $needle) !== false, 'Во фронтенде не найдено: ' . $needle);
    }

    // Сданная работа обязана забирать зеркало с собой, иначе следующая
    // перезагрузка вернёт участника в несуществующую попытку.
    $qq(
        preg_match('/stopMirror\(\);\s*\n\s*this\.clearMirror\(\);\s*\n\s*this\.renderResults\(data\);/', $js) === 1,
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
    $qq(
        substr_count($js, 'this.clearMirror();') === 5,
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
