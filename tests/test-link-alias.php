<?php
/**
 * 1.0.17: administrator editable name of the direct external link (?wpo_runner=<имя>).
 *
 * Covers normalization, validation/uniqueness, link generation (direct URL and
 * iframe code), runner link resolution with backwards compatible numeric ids and
 * the settings form rendering.
 */

namespace WPOlympiad\Tests;

use WPOlympiad\Quiz;

if (!defined('ABSPATH') || !isset($it)) return;

$alias_assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new \RuntimeException($message);
    }
};

$it('Имя ссылки: нормализация ввода (транслитерация, регистр, дефисы, длина)', function () use ($alias_assert): bool {
    $cases = [
        '  Математика 2026 — 10 «А» класс ' => 'matematika-2026-10-a-klass',
        'Math-2026'                         => 'math-2026',
        'Олимпиада_по_физике'               => 'olimpiada-po-fizike',
        'Ёлки-иголки'                        => 'elki-igolki',
        '--a__b--'                          => 'a-b',
        '!!!'                                => '',
        '   '                                => '',
        'привет, мир! 123'                   => 'privet-mir-123',
    ];
    foreach ($cases as $raw => $expected) {
        $alias_assert(Quiz::normalize_link_alias($raw) === $expected, 'Normalization of «' . $raw . '» is wrong');
    }

    // Массив вместо строки не должен порождать «Array» в адресе.
    $alias_assert(Quiz::normalize_link_alias(['x']) === '', 'Array input is not treated as empty');

    // Длина ограничена, хвостовой дефис отрезан, символы только из разрешённого набора.
    $long = Quiz::normalize_link_alias(str_repeat('a', 200));
    $alias_assert($long === str_repeat('a', Quiz::LINK_ALIAS_MAX_LENGTH), 'Long name is not capped at the limit');
    $cut = Quiz::normalize_link_alias(str_repeat('ab-', 40) . 'хвост');
    $alias_assert(
        strlen($cut) <= Quiz::LINK_ALIAS_MAX_LENGTH
        && !str_ends_with($cut, '-')
        && preg_match(Quiz::LINK_ALIAS_PATTERN, $cut) === 1,
        'Truncated name is invalid: ' . $cut
    );

    return true;
});

$it('Имя ссылки: ввод без допустимых символов не стирает заданное имя', function () use ($alias_assert): bool {
    list($alias, $error) = Quiz::sanitize_link_alias('Математика 2026');
    $alias_assert($alias === 'matematika-2026' && $error === '', 'Valid name is not accepted by sanitize_link_alias');

    list($alias, $error) = Quiz::sanitize_link_alias('');
    $alias_assert($alias === '' && $error === '', 'Empty name must be accepted as "no name"');

    // «Мусорный» ввод — ошибка формата, а не незаметное удаление уже выбранного имени.
    foreach (['%%&/', '— — —', '???'] as $raw) {
        list($alias, $error) = Quiz::sanitize_link_alias($raw);
        $alias_assert($alias === '' && $error === 'invalid', 'Garbage input «' . $raw . '» is not reported as invalid');
    }

    list($alias, $error) = Quiz::sanitize_link_alias(['x']);
    $alias_assert($alias === '' && $error === '', 'Array input is not treated as empty');

    $token = strtolower(wp_generate_password(8, false, false));
    $shared = 'dup-' . $token;
    $owner = Quiz::create(['title' => 'Sanitize owner ' . $token, 'settings' => ['link_alias' => $shared]]);
    $other = Quiz::create(['title' => 'Sanitize other ' . $token]);
    try {
        list($alias, $error) = Quiz::sanitize_link_alias($shared, $other);
        $alias_assert($alias === $shared && $error === 'taken', 'Duplicate name is not reported by sanitize_link_alias');
        list($alias, $error) = Quiz::sanitize_link_alias($shared, $owner);
        $alias_assert($alias === $shared && $error === '', 'Own name must pass sanitize_link_alias for its owner');
        return true;
    } finally {
        Quiz::delete($owner);
        Quiz::delete($other);
    }
});

$it('Имя ссылки: проверка формата и занятости, в т.ч. исключение самой олимпиады', function () use ($alias_assert): bool {
    $alias_assert(Quiz::link_alias_error('') === '', 'Empty name must mean "no name"');
    $alias_assert(Quiz::link_alias_error('math-2026') === '', 'Valid name rejected');
    $alias_assert(Quiz::link_alias_error('12345') === 'numeric', 'Digit-only name must be rejected');
    $alias_assert(Quiz::link_alias_error('Math-2026') === 'invalid', 'Uppercase name must be rejected');
    $alias_assert(Quiz::link_alias_error('-abc') === 'invalid', 'Leading dash must be rejected');
    $alias_assert(Quiz::link_alias_error('a_b') === 'invalid', 'Underscore must be rejected');
    $alias_assert(Quiz::link_alias_error(str_repeat('a', 49)) === 'invalid', 'Overlong name must be rejected');

    $token = strtolower(wp_generate_password(8, false, false));
    $shared = 'shared-' . $token;
    $first = Quiz::create(['title' => 'Alias first ' . $token, 'settings' => ['link_alias' => $shared]]);
    $second = Quiz::create(['title' => 'Alias second ' . $token]);
    try {
        $alias_assert(Quiz::link_alias_taken($shared), 'Taken name is not detected');
        $alias_assert(!Quiz::link_alias_taken($shared, $first), 'Own name counts as taken by somebody else');
        $alias_assert(Quiz::link_alias_taken($shared, $second) === true, 'Name must stay taken when the other olympiad is excluded');
        $alias_assert(Quiz::link_alias_error($shared, $second) === 'taken', 'Conflict must be reported as taken');
        $alias_assert(Quiz::link_alias_error($shared, $first) === '', 'Re-saving own name must be accepted');
        $alias_assert(!Quiz::link_alias_taken('never-used-' . $token), 'Unused name reported as taken');

        // Занятое имя нельзя присвоить второй олимпиаде, а свободное — можно.
        Quiz::get($second)->update(['settings' => ['link_alias' => 'free-' . $token]]);
        $alias_assert(Quiz::get($second)->get_link_alias() === 'free-' . $token, 'Free name was not stored');
        return true;
    } finally {
        Quiz::delete($first);
        Quiz::delete($second);
    }
});

$it('Имя ссылки: адрес и код встраивания используют имя, пустое имя — номер олимпиады', function () use ($alias_assert): bool {
    $token = strtolower(wp_generate_password(8, false, false));
    $id = Quiz::create(['title' => 'Link shape ' . $token]);
    try {
        $quiz = Quiz::get($id);
        $alias_assert(
            $quiz->get_link_alias() === '' && $quiz->get_direct_url() === add_query_arg(['wpo_runner' => $id], home_url('/')),
            'Default link must use the numeric olympiad id'
        );
        $alias_assert(strpos($quiz->get_direct_url(), 'wpo_runner=' . $id) !== false, 'Numeric link missing');

        $named = 'math-' . $token;
        Quiz::get($id)->update(['settings' => ['link_alias' => $named]]);
        $quiz = Quiz::get($id);
        $url = $quiz->get_direct_url();
        $alias_assert($quiz->get_link_alias() === $named, 'Name is not readable back');
        $alias_assert($url === add_query_arg(['wpo_runner' => $named], home_url('/')), 'Named link is not built from the name');
        $alias_assert(strpos($url, (string)$id) === false, 'Numeric id leaked into the named link');
        $alias_assert(
            strpos($quiz->get_embed_code(), '<iframe src="' . esc_url($url) . '"') === 0,
            'iframe code does not embed the named link'
        );

        // Сброс имени возвращает числовую ссылку.
        Quiz::get($id)->update(['settings' => ['link_alias' => '']]);
        $alias_assert(strpos(Quiz::get($id)->get_direct_url(), 'wpo_runner=' . $id) !== false, 'Reset to numeric id failed');

        // Частичное сохранение настроек не трогает имя ссылки.
        Quiz::get($id)->update(['settings' => ['link_alias' => 'keep-' . $token]]);
        Quiz::get($id)->update(['settings' => ['time_limit' => 21]]);
        $after = Quiz::get($id);
        $alias_assert(
            $after->get_link_alias() === 'keep-' . $token && (int)$after->get_setting('time_limit') === 21,
            'Partial settings save changed the link name'
        );

        return true;
    } finally {
        Quiz::delete($id);
    }
});

$it('Имя ссылки: ссылка участников резолвится по имени и по прежнему номеру', function () use ($alias_assert): bool {
    $token = strtolower(wp_generate_password(8, false, false));
    $named = 'run-' . $token;
    $id = Quiz::create(['title' => 'Runner resolve ' . $token, 'settings' => ['link_alias' => $named]]);
    try {
        // Старые рассылки: числовой адрес обязан продолжать работать.
        $by_id = Quiz::resolve_public_link((string)$id);
        $alias_assert($by_id && $by_id->id === $id, 'Numeric link stopped working');
        $by_id_string = Quiz::resolve_public_link(' ' . $id . ' ');
        $alias_assert($by_id_string && $by_id_string->id === $id, 'Numeric link with spaces is not resolved');

        $by_alias = Quiz::resolve_public_link($named);
        $alias_assert($by_alias && $by_alias->id === $id, 'Named link does not resolve');

        $alias_assert(Quiz::resolve_public_link('no-such-' . $token) === null, 'Unknown name resolves to something');
        $alias_assert(Quiz::resolve_public_link('999999999') === null, 'Unknown id resolves to something');
        $alias_assert(Quiz::resolve_public_link('') === null, 'Empty key resolves to something');
        $alias_assert(Quiz::resolve_public_link(null) === null, 'Null key resolves to something');
        $alias_assert(Quiz::resolve_public_link(['x']) === null, 'Array key resolves to something');
        $alias_assert(Quiz::resolve_public_link('a/b/../../etc') === null, 'Path-like key is not rejected');
        $alias_assert(
            Quiz::resolve_public_link('olimpiada\' or 1=1') === null,
            'SQL-shaped key is not rejected'
        );
        $alias_assert(Quiz::get_by_link_alias('') === null, 'Empty name lookup returned a quiz');

        // Имя сохраняется в нормализованном виде, поэтому находится и по «сырому» вводу.
        $raw = 'RUN-' . strtoupper($token);
        $alias_assert(
            Quiz::resolve_public_link(Quiz::normalize_link_alias($raw)) !== null,
            'Normalized lookup of a stored name failed'
        );

        return true;
    } finally {
        Quiz::delete($id);
    }
});

$it('Имя ссылки: программная запись нормализуется, поиск снисходителен к регистру', function () use ($alias_assert): bool {
    $token = strtolower(wp_generate_password(8, false, false));
    $id = Quiz::create(['title' => 'Programmatic alias ' . $token, 'settings' => ['link_alias' => 'Привет  Мир']]);
    try {
        $quiz = Quiz::get($id);
        $alias_assert($quiz->get_setting('link_alias') === 'privet-mir', 'Programming write is not normalized in settings');
        $alias_assert($quiz->get_link_alias() === 'privet-mir', 'Normalized name is not readable');
        $alias_assert(strpos($quiz->get_direct_url(), 'wpo_runner=privet-mir') !== false, 'Direct URL does not use the stored name');
        $alias_assert(
            Quiz::get_by_link_alias('privet-mir') && Quiz::get_by_link_alias('privet-mir')->id === $id,
            'LIKE-based lookup misses a programmatically stored name'
        );

        // Снисходительность к набранному вручную адресу.
        $alias_assert(Quiz::resolve_public_link('Privet-Mir') !== null, 'Uppercase typed name is not resolved');
        $alias_assert(Quiz::resolve_public_link('  привот-mir') === null, 'Wrong name in Cyrillic must not resolve');

        // Точечное сохранение настройки также нормализует значение.
        Quiz::get($id)->update_setting('link_alias', 'Другое Имя ' . $token);
        $after = Quiz::get($id);
        $alias_assert($after->get_setting('link_alias') === 'drugoe-imya-' . $token, 'update_setting is not normalized');
        $alias_assert(Quiz::resolve_public_link('drugoe-imya-' . $token) !== null, 'Renamed link does not resolve');
        $alias_assert(Quiz::resolve_public_link('privet-mir') === null, 'Replaced name is still resolvable');
        $alias_assert(
            Quiz::link_alias_error('drugoe-imya-' . $token, $id) === '',
            'Own programmatically stored name reported as taken'
        );

        // Запись из REST/патча не может оставить имя, которое ссылка не находит.
        Quiz::get($id)->update(['settings' => ['link_alias' => '  Занято  ']]);
        $stored = (string)Quiz::get($id)->get_setting('link_alias');
        $alias_assert($stored === 'zanyato' && strpos(Quiz::get($id)->get_direct_url(), 'wpo_runner=zanyato') !== false, 'Patch write left an inconsistent name');
        return Quiz::get_by_link_alias('zanyato') !== null;
    } finally {
        Quiz::delete($id);
    }
});

$it('Имя ссылки: поле и предпросмотр в форме настроек, уведомление об ошибке', function () use ($alias_assert): bool {
    $token = strtolower(wp_generate_password(8, false, false));
    $named = 'form-' . $token;
    $id = Quiz::create(['title' => 'Form check ' . $token, 'settings' => ['link_alias' => $named]]);
    $render = static function ($quiz): string {
        ob_start();
        require WP_OLYMPIAD_PATH . 'admin/views/quiz-edit.php';
        return ob_get_clean();
    };

    $errors = [
        'taken'   => 'уже использует другая олимпиада',
        'numeric' => 'только из цифр',
        'invalid' => 'должно начинаться с буквы или цифры',
    ];
    $previous_get = $_GET['link_alias_error'] ?? '__absent__';
    try {
        $html = $render(Quiz::get($id));
        $alias_assert(strpos($html, 'name="link_alias"') !== false, 'Link name input is missing');
        $alias_assert(strpos($html, 'value="' . esc_attr($named) . '"') !== false, 'Link name input does not carry the stored name');
        $alias_assert(strpos($html, 'id="wpo-direct-link"') !== false, 'Direct link preview input is missing');
        $alias_assert(strpos($html, 'wpo_runner=' . $named) !== false, 'Preview link does not use the name');
        $alias_assert(strpos($html, 'id="wpo-link-alias-title"') !== false, '«Из названия» action is missing');
        $alias_assert(strpos($html, 'id="wpo-link-alias-reset"') !== false, 'Reset action is missing for a named olympiad');
        $alias_assert(strpos($html, 'Имя прямой внешней ссылки:') !== false, 'Field label is missing');
        foreach ($errors as $sentence) {
            $alias_assert(strpos($html, $sentence) === false, 'Error notice shown without an error: ' . $sentence);
        }

        $_GET['link_alias_error'] = 'taken';
        $html_taken = $render(Quiz::get($id));
        $alias_assert(
            strpos($html_taken, 'notice notice-warning inline') !== false
            && strpos($html_taken, 'уже использует другая олимпиада') !== false
            && strpos($html_taken, $errors['invalid']) === false && strpos($html_taken, $errors['numeric']) === false,
            '«Name taken» notice is not rendered'
        );
        $_GET['link_alias_error'] = 'numeric';
        $html_numeric = $render(Quiz::get($id));
        $alias_assert(strpos($html_numeric, 'только из цифр') !== false, '«Digits only» notice is not rendered');
        unset($_GET['link_alias_error']);
        $html_clean = $render(Quiz::get($id));
        foreach ($errors as $sentence) {
            $alias_assert(strpos($html_clean, $sentence) === false, 'Notice sticks after the error is gone: ' . $sentence);
        }

        // Новая олимпиада: поле есть, предпросмотр ссылки и iframe — нет, кнопки сброса тоже.
        $html_new = $render(null);
        $alias_assert(strpos($html_new, 'name="link_alias"') !== false, 'Link name input missing on the create form');
        $alias_assert(strpos($html_new, 'id="wpo-link-alias-reset"') === false, 'Reset button offered before the olympiad exists');
        $alias_assert(strpos($html_new, 'id="wpo-direct-link"') === false, 'Direct link preview shown for a new olympiad');
        foreach ($errors as $sentence) {
            $alias_assert(strpos($html_new, $sentence) === false, 'Unexpected notice text on the create form');
        }

        return true;
    } finally {
        if ($previous_get === '__absent__') {
            unset($_GET['link_alias_error']);
        } else {
            $_GET['link_alias_error'] = $previous_get;
        }
        Quiz::delete($id);
    }
});
