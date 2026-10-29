<?php
/**
 * 1.0.21: дополнительные поля анкеты регистрации и вывод вопросов по группам.
 */

namespace WPOlympiad\Tests;

use WPOlympiad\Quiz;
use WPOlympiad\Attempt;
use WPOlympiad\Export;
use WPOlympiad\Grader;
use WPOlympiad\Question;
use WPOlympiad\Webhook;
use WPOlympiad\RestApi;
use WPOlympiad\ParticipantFields;
use WPOlympiad\QuestionGroups;

if (!defined('ABSPATH') || !isset($it)) return;

$qq = static function (bool $ok, string $message, string $extra = ''): void {
    if (!$ok) {
        throw new \RuntimeException($extra !== '' ? $message . ' — ' . $extra : $message);
    }
};

$it('Анкета: чистка описаний полей, лимит 10 и отбрасывание мусора', function () use ($qq): bool {
    $raw = [];
    for ($i = 1; $i <= 13; $i++) {
        $raw[] = ['id' => 'cf_' . $i, 'type' => 'text', 'label' => 'Поле ' . $i];
    }
    $clean = ParticipantFields::sanitize_defs($raw);
    $qq(count($clean) === ParticipantFields::MAX_FIELDS, 'Лимит полей анкеты не сработал', (string)count($clean));

    $ids = array_column($clean, 'id');
    $qq(count($ids) === count(array_unique($ids)), 'Id полей анкеты не уникальны', implode(',', $ids));
    $qq(array_unique(array_column($clean, 'type')) === ['text'], 'Типы полей испорчены');

    $clean = ParticipantFields::sanitize_defs([
        ['type' => 'unknown', 'label' => 'Неизвестный тип'],
        ['type' => 'select', 'label' => '   ', 'options' => ['a']],
        ['type' => 'select', 'label' => 'Класс', 'options' => "7\n8\n7\n", 'required' => 1],
        ['type' => 'date', 'label' => 'Дата рождения', 'max_length' => 999999],
        ['id' => 'cf_x', 'type' => 'multiselect', 'label' => 'Кружки', 'options' => ['Математика', 'Физика', '']],
        ['id' => 'cf_x', 'type' => 'number', 'label' => 'Средний балл'],
    ]);

    $qq(count($clean) === 5, 'Пустые метки и мусор не отброшены', (string)count($clean));
    $qq($clean[0]['type'] === 'text', 'Неизвестный тип не сведён к текстовому');
    $qq($clean[0]['id'] === 'cf_1', 'Id не выдан автоматически', $clean[0]['id']);
    $qq($clean[1]['options'] === ['7', '8'], 'Варианты списка разобраны неверно', json_encode($clean[1]['options'], JSON_UNESCAPED_UNICODE));
    $qq((int)$clean[1]['required'] === 1, 'Флаг обязательности потерян');
    $qq((int)$clean[2]['max_length'] === 10, 'Поле «дата» не ограничено форматом', (string)$clean[2]['max_length']);
    $qq($clean[3]['id'] === 'cf_x', 'Заданный id потерян', $clean[3]['id']);
    $qq($clean[4]['id'] !== 'cf_x' && $clean[4]['id'] !== '', 'Дублирующийся id не переименован', $clean[4]['id']);
    $qq($clean[3]['type'] === 'multiselect' && count($clean[3]['options']) === 2, 'Мультивыбор испорчен');

    $long = ParticipantFields::sanitize_defs([['type' => 'text', 'label' => str_repeat('к', 400)]]);
    $qq(mb_strlen($long[0]['label'], 'UTF-8') === ParticipantFields::MAX_LABEL_LENGTH, 'Длинная метка не обрезана');

    return true;
});

$it('Анкета: проверка значений участника', function () use ($qq): bool {
    $defs = ParticipantFields::sanitize_defs([
        ['id' => 'klass', 'type' => 'select', 'label' => 'Класс', 'options' => ['7', '8', '9'], 'required' => 1],
        ['id' => 'note', 'type' => 'textarea', 'label' => 'Комментарий'],
        ['id' => 'score', 'type' => 'number', 'label' => 'Средний балл', 'required' => 1],
        ['id' => 'birth', 'type' => 'date', 'label' => 'Дата рождения'],
        ['id' => 'clubs', 'type' => 'multiselect', 'label' => 'Кружки', 'options' => ['Математика', 'Физика', 'Химия']],
        ['id' => 'consent', 'type' => 'checkbox', 'label' => 'Согласие на обработку данных', 'required' => 1],
    ]);

    $bad = ParticipantFields::clean_values($defs, [
        'klass' => '11',
        'score' => 'не число',
        'birth' => '31.02.2010',
        'clubs' => ['Математика', 'Астрология'],
    ]);
    $messages = implode(' | ', $bad['errors']);
    $qq(count($bad['errors']) === 4, 'Ошибок анкеты меньше, чем должно быть', $messages);
    $qq(str_contains($messages, 'неизвестный вариант'), 'Не проверен вариант списка: ' . $messages);
    $qq(str_contains($messages, 'нужно ввести число'), 'Число не проверено: ' . $messages);
    $qq(str_contains($messages, 'дата введена неверно'), 'Дата не проверена: ' . $messages);
    $qq(str_contains($messages, 'Не отмечено поле'), 'Обязательный чекбокс не проверен: ' . $messages);
    $qq(($bad['values']['clubs'] ?? null) === ['Математика'], 'Из мультивыбора не отброшено лишнее', json_encode($bad['values']['clubs'] ?? [], JSON_UNESCAPED_UNICODE));

    $empty_required = ParticipantFields::clean_values($defs, []);
    $qq(count($empty_required['errors']) === 3, 'Обязательные поля не требуются корректно', implode(' | ', $empty_required['errors']));

    $good = ParticipantFields::clean_values($defs, [
        'klass' => '9',
        'score' => '4,75',
        'birth' => '01.11.2010',
        'clubs' => 'Физика, Химия',
        'consent' => '1',
        'note' => str_repeat('д', 5000),
    ]);
    $qq($good['errors'] === [], 'Валидная анкета отклонена', implode(' | ', $good['errors']));
    $qq(($good['values']['score'] ?? '') === '4.75', 'Запятая в числе не нормализована', (string)($good['values']['score'] ?? ''));
    $qq(($good['values']['birth'] ?? '') === '2010-11-01', 'Дата не приведена к ISO', (string)($good['values']['birth'] ?? ''));
    $qq(($good['values']['clubs'] ?? null) === ['Физика', 'Химия'], 'Строка вариантов не разобрана', json_encode($good['values']['clubs'] ?? [], JSON_UNESCAPED_UNICODE));
    $qq(mb_strlen((string)$good['values']['note'], 'UTF-8') === 2000, 'Многострочное поле не обрезано по лимиту');

    $optional = ParticipantFields::clean_values($defs, ['klass' => '7', 'score' => '5', 'consent' => '1']);
    $qq($optional['errors'] === [], 'Пустые необязательные поля считаются ошибкой');
    $qq(!array_key_exists('note', $optional['values']), 'Пустое поле попало в значения');

    return true;
});

$it('Анкета: значения сохраняются в попытке и читаются обратно', function () use ($qq): bool {
    global $wpdb;
    $previous_user = get_current_user_id();
    wp_set_current_user(0);
    $token = strtolower(wp_generate_password(8, false, false));
    $id = Quiz::create([
        'title' => 'Qn values ' . $token,
        'status' => 'publish',
        'settings' => [
            'allow_guest' => 1,
            'custom_fields' => [
                ['id' => 'klass', 'type' => 'select', 'label' => 'Класс', 'options' => ['7', '8'], 'required' => 1],
                ['id' => 'clubs', 'type' => 'multiselect', 'label' => 'Кружки', 'options' => ['Математика', 'Физика']],
                ['id' => 'birth', 'type' => 'date', 'label' => 'Дата рождения'],
            ],
        ],
    ]);

    $attempt = null;
    try {
        $quiz = Quiz::get($id);
        $qq(count($quiz->get_custom_fields()) === 3, 'Настройки анкеты не сохранились');

        $checked = ParticipantFields::clean_values($quiz->get_custom_fields(), [
            'klass' => '8',
            'clubs' => ['Математика'],
            'birth' => '2011-05-04',
        ]);
        $qq($checked['errors'] === [], 'Анкета не прошла собственную проверку');

        $attempt = Attempt::start($id, [
            'name' => 'Участник ' . $token,
            'email' => 'qn-' . $token . '@example.invalid',
            'custom_fields' => $checked['values'],
        ]);
        $qq($attempt instanceof Attempt, 'Попытка не создана');

        $values = $attempt->custom_field_values();
        $qq(($values['klass'] ?? '') === '8', 'Значение списка не сохранено', json_encode($values, JSON_UNESCAPED_UNICODE));
        $qq(($values['clubs'] ?? null) === ['Математика'], 'Мультивыбор не сохранён');
        $qq(($values['birth'] ?? '') === '2011-05-04', 'Дата не сохранена');

        $fresh = Attempt::get((int)$attempt->id);
        $qq($fresh && $fresh->custom_field_values() === $values, 'Значения анкеты не дочитываются из базы');

        $has_column = $wpdb->get_var('SHOW COLUMNS FROM ' . \WPOlympiad\DB::attempts_table() . " LIKE 'participant_meta'");
        $qq($has_column !== null, 'Колонка participant_meta не создана');

        // Пустая анкета не должна превращать поле в «null»-строку.
        $bare = Attempt::start($id, ['name' => 'Без анкеты ' . $token, 'email' => 'qb-' . $token . '@example.invalid']);
        $qq($bare instanceof Attempt && $bare->custom_field_values() === [], 'Пустая анкета прочитана неверно');

        return true;
    } finally {
        foreach ([$attempt] as $row) {
            if ($row instanceof Attempt) {
                $wpdb->delete(\WPOlympiad\DB::responses_table(), ['attempt_id' => (int)$row->id], ['%d']);
            }
        }
        $wpdb->delete(\WPOlympiad\DB::attempts_table(), ['quiz_id' => $id], ['%d']);
        wp_set_current_user($previous_user);
        Quiz::delete($id);
    }
});

$it('Анкета: колонки в протоколе, в выгрузке работы и в webhook', function () use ($qq): bool {
    global $wpdb;
    $previous_user = get_current_user_id();
    wp_set_current_user(0);
    $token = strtolower(wp_generate_password(8, false, false));
    $id = Quiz::create([
        'title' => 'Qn protocol ' . $token,
        'status' => 'publish',
        'settings' => [
            'allow_guest' => 1,
            'time_limit' => 0,
            'custom_fields' => [
                ['id' => 'klass', 'type' => 'select', 'label' => 'Класс', 'options' => ['7', '8'], 'required' => 1],
                ['id' => 'note', 'type' => 'text', 'label' => 'Комментарий'],
            ],
        ],
    ]);

    $attempt = null;
    try {
        $qid = Question::create([
            'quiz_id' => $id,
            'question_type' => 'single',
            'title' => 'Сколько будет 2 + 2?',
            'points' => 3,
            'options' => ['group_name' => 'Часть 1', 'choices' => [
                ['id' => 'c1', 'text' => '4', 'is_correct' => 1],
                ['id' => 'c2', 'text' => '5', 'is_correct' => 0],
            ]],
        ]);

        $quiz = Quiz::get($id);
        $values = ParticipantFields::clean_values($quiz->get_custom_fields(), ['klass' => '8', 'note' => ''])['values'];
        $attempt = Attempt::start($id, [
            'name' => 'Протокол ' . $token,
            'email' => 'qp-' . $token . '@example.invalid',
            'custom_fields' => $values,
        ]);
        $qq($attempt instanceof Attempt, 'Попытка не создана');

        $grading = Grader::grade($quiz, $attempt, [$qid => 'c1']);
        Grader::save_responses((int)$attempt->id, $grading['responses']);
        $attempt->complete((float)$grading['total_score'], (float)$grading['max_score'], 'completed');

        $csv = Export::export_quiz_attempts($id, false);
        $qq(is_string($csv) && $csv !== '', 'Протокол не сформирован');
        $lines = array_values(array_filter(explode("\n", (string)$csv), static fn($line) => trim($line) !== ''));
        $qq(count($lines) >= 2, 'В протоколе нет строки участника');

        $headers = str_getcsv($lines[0], ';', '"', '\\');
        $row = str_getcsv($lines[1], ';', '"', '\\');
        $cf_index = array_search('Анкета: Класс', (array)$headers, true);
        $qq($cf_index !== false, 'В шапке протокола нет столбца анкеты', implode('|', array_slice((array)$headers, 0, 20)));
        $qq((string)($row[$cf_index] ?? '') === '8', 'Значение поля не попало в строку протокола', (string)($row[$cf_index] ?? ''));
        $empty_index = array_search('Анкета: Комментарий', (array)$headers, true);
        $qq($empty_index !== false && (string)($row[$empty_index] ?? '') === '—', 'Пустое поле анкеты не отмечено прочерком');
        $qq(count($headers) === count($row), 'Число колонок не совпадает с числом значений', count($headers) . ' vs ' . count($row));

        $group_header = array_filter((array)$headers, static fn($header) => str_starts_with((string)$header, 'Вопрос 1'));
        $group_header = array_values($group_header);
        $qq(isset($group_header[0]) && str_contains((string)$group_header[0], 'Часть 1'), 'Группа задания не подписана в протоколе', json_encode($group_header[0] ?? '', JSON_UNESCAPED_UNICODE));

        $single = (string)Export::export_single_attempt((int)$attempt->id, false);
        $qq(str_contains($single, 'Анкета: Класс'), 'В выгрузке одной работы нет поля анкеты');
        $qq(str_contains($single, 'Анкета: Комментарий'), 'Пустое поле анкеты не попало в выгрузку работы');

        $payload = Webhook::build_payload($quiz, $attempt);
        $qq(($payload['attempt']['custom_fields']['Класс'] ?? '') === '8', 'Webhook не отдаёт поля анкеты', json_encode($payload['attempt']['custom_fields'] ?? [], JSON_UNESCAPED_UNICODE));
        $qq(!array_key_exists('Комментарий', (array)($payload['attempt']['custom_fields'] ?? [])), 'Пустое поле попало в webhook');

        $pairs = RestApi::custom_field_pairs($quiz, $attempt);
        $qq(count($pairs) === 2 && $pairs[0]['label'] === 'Класс' && $pairs[0]['value'] === '8', 'REST-описание попытки не содержит анкету', json_encode($pairs, JSON_UNESCAPED_UNICODE));

        return true;
    } finally {
        if ($attempt instanceof Attempt) {
            $wpdb->delete(\WPOlympiad\DB::responses_table(), ['attempt_id' => (int)$attempt->id], ['%d']);
        }
        $wpdb->delete(\WPOlympiad\DB::attempts_table(), ['quiz_id' => $id], ['%d']);
        $wpdb->delete(\WPOlympiad\DB::questions_table(), ['quiz_id' => $id], ['%d']);
        wp_set_current_user($previous_user);
        Quiz::delete($id);
    }
});

$it('Группы: порядок вопросов и настройки вывода', function () use ($qq): bool {
    $defs = QuestionGroups::sanitize_defs([
        ['name' => 'Часть 2. Практика', 'position' => 1],
        ['name' => 'Часть 1. Теория', 'position' => 2],
    ]);

    $specs = [
        [1, 'Часть 1. Теория', 1],
        [2, '', 2],
        [3, 'Часть 2. Практика', 3],
        [4, 'Часть 1. Теория', 4],
        [5, 'Новый блок', 5],
    ];

    $questions = [];
    foreach ($specs as [$qid, $group, $order]) {
        $question = new \stdClass();
        $question->id = $qid;
        $question->sort_order = $order;
        $question->options = ['group_name' => $group];
        $questions[] = $question;
    }

    $ordered = QuestionGroups::order($questions, $defs);
    $ids = array_map(static fn($question) => $question->id, $ordered);
    $qq($ids === [2, 3, 1, 4, 5], 'Задания выстроены не по группам', implode(',', $ids));

    $names = QuestionGroups::names_of($questions);
    $qq($names === ['Часть 1. Теория', 'Часть 2. Практика', 'Новый блок'], 'Порядок появления групп определён неверно', implode('|', $names));

    $untouched = QuestionGroups::order($questions, []);
    $qq(array_map(static fn($question) => $question->id, $untouched) === [1, 2, 3, 4, 5], 'Пустые настройки групп перестроили вопросы');

    // Порядок без явных позиций — по первому появлению группы.
    $by_appearance = QuestionGroups::order($questions, [['name' => 'Часть 1. Теория', 'time_limit' => 15]]);
    $qq(array_map(static fn($question) => $question->id, $by_appearance) === [2, 1, 4, 3, 5], 'Группы без позиций выстроены неверно', implode(',', array_map(static fn($question) => $question->id, $by_appearance)));

    return true;
});

$it('Группы: лимит времени группы и обнуление ответов', function () use ($qq): bool {
    global $wpdb;
    $previous_user = get_current_user_id();
    wp_set_current_user(0);
    $token = strtolower(wp_generate_password(8, false, false));
    $id = Quiz::create([
        'title' => 'Groups limit ' . $token,
        'status' => 'publish',
        'settings' => [
            'allow_guest' => 1,
            'time_limit' => 0,
            'question_groups' => [
                ['name' => 'Теория', 'time_limit' => 20],
                ['name' => 'Практика', 'time_limit' => 0],
            ],
        ],
    ]);

    $attempt = null;
    try {
        $theory = Question::create([
            'quiz_id' => $id,
            'question_type' => 'single',
            'title' => 'Вопрос из теории',
            'points' => 4,
            'options' => ['group_name' => 'Теория', 'choices' => [
                ['id' => 't1', 'text' => 'верно', 'is_correct' => 1],
                ['id' => 't2', 'text' => 'неверно', 'is_correct' => 0],
            ]],
        ]);
        $practice = Question::create([
            'quiz_id' => $id,
            'question_type' => 'single',
            'title' => 'Вопрос из практики',
            'points' => 5,
            'options' => ['group_name' => 'Практика', 'choices' => [
                ['id' => 'p1', 'text' => 'верно', 'is_correct' => 1],
                ['id' => 'p2', 'text' => 'неверно', 'is_correct' => 0],
            ]],
        ]);

        $quiz = Quiz::get($id);
        $defs = $quiz->get_question_groups();
        $qq(count($defs) === 2, 'Настройки групп не сохранились');
        $qq(QuestionGroups::time_limit_for($defs, 'Теория') === 20, 'Лимит группы прочитан неверно');
        $qq(QuestionGroups::time_limit_for($defs, 'Практика') === 0, 'Группе без лимита выдано время');
        $qq(QuestionGroups::time_limit_for($defs, '   тЕория  ') === 20, 'Название группы с регистром и пробелами не сопоставлено');

        $attempt = Attempt::start($id, ['name' => 'Группы ' . $token, 'email' => 'gl-' . $token . '@example.invalid']);
        $qq($attempt instanceof Attempt, 'Попытка не создана');

        $now = time();
        $opened = [QuestionGroups::key('Теория') => date('Y-m-d H:i:s', $now - 100)];
        $qq(QuestionGroups::overdue_groups($defs, $opened, $now) === [], 'Группа помечена просроченной раньше срока');

        $opened[QuestionGroups::key('Теория')] = date('Y-m-d H:i:s', $now - (20 * 60 + 120));
        $overdue = QuestionGroups::overdue_groups($defs, $opened, $now);
        $qq($overdue === ['Теория'], 'Просроченная группа не определена', json_encode($overdue, JSON_UNESCAPED_UNICODE));
        $qq(QuestionGroups::overdue_groups($defs, [], $now + 100000) === [], 'Группа без отметки открытия наказана временем');

        $grading = Grader::grade($quiz, $attempt, [$theory => 't1', $practice => 'p1'], [$theory => 'Теория']);
        $by_question = [];
        foreach ($grading['responses'] as $row) {
            $by_question[(int)$row['question_id']] = $row;
        }
        $qq((int)$by_question[$theory]['awarded_points'] === 0, 'Просроченной группе начислены баллы');
        $qq((int)$by_question[$theory]['needs_manual_review'] === 1, 'Ответ просроченной группы не передан на проверку');
        $qq(str_contains((string)$by_question[$theory]['review_comment'], 'Теория'), 'В комментарии не указано название группы', (string)$by_question[$theory]['review_comment']);
        $qq((int)$by_question[$practice]['awarded_points'] === 5, 'Соседняя группа пострадала');
        $qq((int)$grading['total_score'] === 5, 'Итоговый балл учёл просроченную группу', (string)$grading['total_score']);
        $qq((int)$grading['max_score'] === 9, 'Максимальный балл изменён из-за лимита группы');

        // Без locked-карты оценивание работает как раньше.
        $plain = Grader::grade($quiz, $attempt, [$theory => 't1', $practice => 'p1']);
        $qq((int)$plain['total_score'] === 9, 'Оценивание без просроченных групп сломалось', (string)$plain['total_score']);

        // Отметка открытия группы живёт в participant_meta и не двигается повторно.
        $qq($attempt->mark_group_opened('Практика'), 'Первое открытие группы не записано');
        $stamps = $attempt->groups_opened();
        $qq(isset($stamps[QuestionGroups::key('Практика')]), 'Отметка открытия не сохранена');
        $qq(!$attempt->mark_group_opened('Практика'), 'Повторное открытие двигает отметку');
        $qq($attempt->mark_group_opened('') === false, 'Пустое название группы записано');

        $fresh = Attempt::get((int)$attempt->id);
        $qq($fresh && $fresh->groups_opened() === $stamps, 'Отметка группы не дочитывается из базы');
        $qq($fresh && $fresh->group_opened_at('Практика') > 0, 'Время открытия группы не определено');
        $qq($fresh && $fresh->custom_field_values() === [], 'Групповые отметки затёрли анкету');

        return true;
    } finally {
        if ($attempt instanceof Attempt) {
            $wpdb->delete(\WPOlympiad\DB::responses_table(), ['attempt_id' => (int)$attempt->id], ['%d']);
        }
        $wpdb->delete(\WPOlympiad\DB::attempts_table(), ['quiz_id' => $id], ['%d']);
        $wpdb->delete(\WPOlympiad\DB::questions_table(), ['quiz_id' => $id], ['%d']);
        wp_set_current_user($previous_user);
        Quiz::delete($id);
    }
});

$it('Группы: чистка настроек, лимит 20 и регистронезависимые дубликаты', function () use ($qq): bool {
    $raw = [];
    for ($i = 1; $i <= 24; $i++) {
        $raw[] = ['name' => 'Группа ' . $i, 'time_limit' => 30];
    }
    $clean = QuestionGroups::sanitize_defs($raw);
    $qq(count($clean) === QuestionGroups::MAX_GROUPS, 'Лимит групп не сработал', (string)count($clean));

    $clean = QuestionGroups::sanitize_defs([
        ['name' => '  Блок   А ', 'title' => '', 'instruction' => str_repeat('и', 3000), 'time_limit' => 9999, 'position' => 3],
        ['name' => 'блок а', 'time_limit' => 5],
        ['name' => '', 'time_limit' => 5],
        ['name' => 'Блок Б', 'time_limit' => -7, 'position' => -4],
    ]);
    $qq(count($clean) === 2, 'Дубликаты по регистру и пустые названия не схлопнуты', (string)count($clean));
    $qq($clean[0]['name'] === 'Блок А', 'Название группы не нормализовано', $clean[0]['name']);
    $qq($clean[0]['title'] === 'Блок А', 'Пустой заголовок не заменён названием');
    $qq(mb_strlen((string)$clean[0]['instruction'], 'UTF-8') === QuestionGroups::MAX_INSTRUCTION_LENGTH, 'Инструкция не обрезана');
    $qq((int)$clean[0]['time_limit'] === 600, 'Лимит группы не ограничен сверху', (string)$clean[0]['time_limit']);
    $qq((int)$clean[0]['position'] === 3, 'Порядок группы потерян');
    $qq((int)$clean[1]['time_limit'] === 0 && (int)$clean[1]['position'] === 0, 'Отрицательные значения не сведены к нулю');

    $payload = QuestionGroups::client_payload($clean);
    $qq(count($payload) === 2 && $payload[0]['key'] === QuestionGroups::key('Блок А'), 'Данные для страницы олимпиады собраны неверно');

    $normalized = QuestionGroups::normalize_name("  Часть\t1.  Теория \n");
    $qq($normalized === 'Часть 1. Теория', 'Нормализация названия работает неверно', $normalized);

    return true;
});

$it('Настройки: вопросы на странице, совместимость и сохранение через форму', function () use ($qq): bool {
    global $wpdb;
    $token = strtolower(wp_generate_password(8, false, false));
    $id = Quiz::create([
        'title' => 'Structures settings ' . $token,
        'status' => 'draft',
        'settings' => [
            'questions_per_page' => 999,
            'custom_fields' => [['type' => 'text', 'label' => 'Класс']],
            'question_groups' => [['name' => 'А', 'time_limit' => 10]],
        ],
    ]);

    try {
        $quiz = Quiz::get($id);
        $qq($quiz->get_questions_per_page() === 50, 'Вопросы на странице не ограничены 50', (string)$quiz->get_questions_per_page());
        $qq(count($quiz->get_custom_fields()) === 1 && count($quiz->get_question_groups()) === 1, 'Новые настройки не прочитаны');
        $qq($quiz->has_question_groups(), 'Флаг наличия групп не сработал');

        $quiz->update(['settings' => ['questions_per_page' => 0]]);
        $reloaded = Quiz::get($id);
        $qq($reloaded->get_questions_per_page() === 0, 'Режим «по одному вопросу» не сохраняется');
        $qq(count($reloaded->get_custom_fields()) === 1, 'Частичное сохранение настроек стёрло анкету');
        $qq(count($reloaded->get_question_groups()) === 1, 'Частичное сохранение настроек стёрло группы');

        // Старые олимпиады без новых ключей живут с пустыми настройками.
        $wpdb->update(\WPOlympiad\DB::quizzes_table(), ['settings' => '{"time_limit":30}'], ['id' => $id]);
        Quiz::clear_cache($id);
        $legacy = Quiz::get($id);
        $qq(
            $legacy->get_custom_fields() === [] && $legacy->get_question_groups() === [] && $legacy->get_questions_per_page() === 0,
            'Отсутствие новых ключей сломало чтение настроек'
        );

        return true;
    } finally {
        Quiz::delete($id);
    }
});
