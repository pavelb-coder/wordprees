<?php
/**
 * 1.0.30: потолок личного лимита времени — 4320 минут, и он один для формы,
 * сохранения настроек и сетевого импорта.
 */

namespace WPOlympiad\Tests;

use WPOlympiad\Attempt;
use WPOlympiad\QuestionGroups;
use WPOlympiad\Quiz;

if (!defined('ABSPATH') || !isset($it)) return;

$tl = static function (bool $ok, string $message, string $extra = ''): void {
    if (!$ok) {
        throw new \RuntimeException($extra !== '' ? $message . ' — ' . $extra : $message);
    }
};

$it('1.0.30: лимит времени зажимается к 4320 минутам на сохранении', function () use ($tl): bool {
    $cases = [
        [-5, 0],
        [0, 0],
        ['', 0],
        ['не число', 0],
        [45, 45],
        ['120', 120],
        [4320, 4320],
        [4321, 4320],
        [999999, 4320],
        ['999999', 4320],
    ];
    foreach ($cases as [$in, $want]) {
        $got = Quiz::normalize_time_limit($in);
        $tl($got === $want, 'Нормализация ' . var_export($in, true) . ' дала ' . $got . ', ожидалось ' . $want);
    }
    $tl(Quiz::TIME_LIMIT_MAX_MINUTES === 4320, 'Потолок настройки съехал', (string)Quiz::TIME_LIMIT_MAX_MINUTES);
    // 0 по-прежнему значит «без лимита», а не «ноль минут».
    $tl(Quiz::normalize_time_limit(0) === 0, 'Ноль не должен превращаться в лимит');

    $token = substr(md5(uniqid('tl', true)), 0, 8);
    $quiz_id = (int)Quiz::create([
        'title'    => 'Потолок лимита ' . $token,
        'status'   => 'publish',
        'settings' => ['time_limit' => 999999, 'max_attempts' => 0, 'require_org' => 0],
    ]);
    try {
        $stored = (int)Quiz::get($quiz_id)->get_setting('time_limit', -1);
        $tl($stored === 4320, 'Quiz::create() не зажал лимит', (string)$stored);

        Quiz::get($quiz_id)->update(['settings' => ['time_limit' => 700, 'max_attempts' => 0, 'require_org' => 0]]);
        $tl((int)Quiz::get($quiz_id)->get_setting('time_limit') === 700, 'Обычное значение испорчено');

        Quiz::get($quiz_id)->update(['settings' => ['time_limit' => 5000, 'max_attempts' => 0, 'require_org' => 0]]);
        $tl((int)Quiz::get($quiz_id)->get_setting('time_limit') === 4320, 'Quiz::update() не зажал лимит');

        // Длинный лимит обязан доходить до расчёта остатка, а не обрезаться где-то ещё.
        Quiz::get($quiz_id)->update(['settings' => ['time_limit' => 4320, 'max_attempts' => 0, 'require_org' => 0]]);
        $attempt = Attempt::start($quiz_id, ['name' => 'Лидия Т', 'email' => $token . '@example.invalid', 'org' => 'Школа №1']);
        $tl($attempt instanceof Attempt, 'Попытка не создана');
        $rem = $attempt->get_remaining_seconds((int)Quiz::get($quiz_id)->get_setting('time_limit'));
        $tl($rem >= 4320 * 60 - 5 && $rem <= 4320 * 60, 'Остаток не соответствует длинному лимиту', (string)$rem);
        $tl($attempt->is_expired(4320, 45) === false, 'Свежая попытка с длинным лимитом сразу истекла');

        Quiz::get($quiz_id)->update(['settings' => ['time_limit' => 0, 'max_attempts' => 0, 'require_org' => 0]]);
        $tl((int)Quiz::get($quiz_id)->get_setting('time_limit') === 0, 'Ноль не сохранён');
        $tl($attempt->get_remaining_seconds(0) === 999999, 'Без лимита остаток считается');
        return true;
    } finally {
        Quiz::delete($quiz_id);
    }
});

$it('1.0.30: форма, сохранение и таймеры групп согласованы с потолком', function () use ($tl): bool {
    $root = dirname(__DIR__);
    $view = (string)file_get_contents($root . '/admin/views/quiz-edit.php');
    $admin = (string)file_get_contents($root . '/admin/class-admin.php');

    $tl(preg_match('/name="time_limit".*?max="<\?php echo \(int\)\\\\WPOlympiad\\\\Quiz::TIME_LIMIT_MAX_MINUTES; \?>"/', $view) === 1,
        'Поле формы не берёт потолок из константы');
    $tl(preg_match('/name="time_limit".*max="600"/', $view) !== 1,
        'В форме остался старый потолок 600 минут');
    $tl(str_contains($admin, "Quiz::normalize_time_limit(\$_POST['time_limit'] ?? 0)"),
        'Сохранение настроек олимпиады не использует общую нормализацию');

    // Таймерам групп потолок не поднимали — это осознанный объём изменения,
    // проверка не даст «починить» это молча.
    $defs = QuestionGroups::sanitize_defs([
        ['name' => 'Г1', 'time_limit' => 999999],
    ]);
    $tl(isset($defs[0]['time_limit']) && (int)$defs[0]['time_limit'] === 600,
        'У групп вопросов поехал собственный потолок', json_encode($defs, JSON_UNESCAPED_UNICODE));
    return true;
});
