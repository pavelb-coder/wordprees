#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""1.0.22: возврат к прерванной попытке, автосохранение черновика, статус «Прервана».

Реальные HTTP-проверки на установленном плагине: страница участника, REST
/start, /save, /resume, /heartbeat, /abandon, /submit, БД (participant_meta и
draft_answers), протокол CSV, страницы админки и грепы по установленной копии.
Заодно регрессирует контракты 1.0.21 (анкета, группы, их лимиты) и 1.0.20
(приём начатой работы при закрытом окне).

Запуск (см. tests/e2e/README.md):
    python3 tests/e2e/e2e-resume-draft.py \
        --wp /home/user/.cache/wpo-test/wordpress \
        --cli /home/user/.cache/wpo-test/wp-cli.phar \
        --base http://localhost:8127
"""

import argparse
import csv
import io
import json
import os
import re
import subprocess
import sys

import requests

HERE = os.path.dirname(os.path.abspath(__file__))

TOTAL = 0
PASSED = 0
FAILED = 0


def check(name, cond, extra=''):
    global TOTAL, PASSED, FAILED
    TOTAL += 1
    if cond:
        PASSED += 1
        print("OK   | " + name)
        return True
    FAILED += 1
    print("FAIL | " + name + (("  <- " + str(extra)) if extra != '' else ''))
    return False


def eq(name, got, want, extra=''):
    return check(name, got == want, (extra + ' ' if extra else '') + 'получено ' + repr(got) + ', ожидалось ' + repr(want))


def true_in(name, haystack, needle):
    return check(name, needle in haystack, 'нет подстроки ' + repr(needle))


class Stand:
    def __init__(self, wp_dir, cli, base, rest=''):
        self.wp = wp_dir
        self.cli = cli
        self.base = base.rstrip('/')
        # Базу REST берём со страницы участника (как это делает браузер): при
        # «простых» constant'ах WordPress отдаёт REST через index.php?rest_route=.
        self.api = rest or self.base + '/index.php?rest_route=/wp-olympiad/v1'

    def php(self, script, *args):
        cmd = ['php', self.cli, 'eval-file', os.path.join(HERE, script)] + [str(a) for a in args] + ['--allow-root']
        proc = subprocess.run(cmd, cwd=self.wp, capture_output=True, text=True, timeout=240)
        if proc.returncode != 0:
            raise RuntimeError(script + ':\n' + proc.stdout + '\n' + proc.stderr)
        return proc.stdout

    def php_json(self, script, *args):
        lines = [ln for ln in self.php(script, *args).strip().splitlines() if ln.strip()]
        return json.loads(lines[-1])

    def api_call(self, path, payload=None, method='POST'):
        res = requests.request(method, self.api + path, json=payload if payload is not None else {}, timeout=40)
        try:
            body = res.json()
        except ValueError:
            body = {}
        return res.status_code, body

    def get(self, url, session=None):
        return (session or requests).get(url, timeout=40, allow_redirects=True)


def data_of(body):
    """Ответ приходит либо плоским (REST), либо в data.message-конверте (admin-ajax)."""
    if isinstance(body, dict) and 'data' in body and isinstance(body['data'], dict) and 'success' in body:
        return body['data']
    return body if isinstance(body, dict) else {}


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--wp', default='/home/user/.cache/wpo-test/wordpress', help='документы WordPress')
    ap.add_argument('--cli', default='/home/user/.cache/wpo-test/wp-cli.phar', help='путь к wp-cli')
    ap.add_argument('--base', default='http://localhost:8127', help='адрес стенда')
    ap.add_argument('--rest', default='', help='база REST, если на странице её не нашлось')
    ap.add_argument('--admin-login', default='admin')
    ap.add_argument('--admin-password', default='admin')
    opts = ap.parse_args()

    st = Stand(opts.wp, opts.cli, opts.base, opts.rest)

    fix = st.php_json('make-quiz.php')
    if fix.get('error'):
        print('Фикстура не создана:', fix)
        return 2

    quiz_id = int(fix['quiz_id'])
    page_id = int(fix['page_id'])
    email = fix['email']
    name = fix['name']
    qids = [int(q['id']) for q in fix['questions']]
    by_title = {q['title']: int(q['id']) for q in fix['questions']}
    page_url = st.base + '/?p=%d' % page_id
    print('фикстура: quiz=%d page=%d вопросы=%s' % (quiz_id, page_id, qids))

    # ── A. Страница участника ───────────────────────────────────────────────
    r = st.get(page_url)
    check('A1. Страница олимпиады открывается', r.status_code == 200, r.status_code)
    html = r.text
    m = re.search(r'"restUrl":"((?:[^"\\]|\\.)*)"', html)
    if m:
        st.api = json.loads('"%s"' % m.group(1))
    check('A1b. База REST найдена на странице', st.api.endswith('/wp-olympiad/v1'), st.api)
    true_in('A2. На странице есть стартовая форма', html, 'wpo-intro-screen')
    true_in('A3. Поля анкеты рендерятся', html, 'name="custom_fields[klass]"')
    true_in('A4. Индикатор автосохранения в разметке', html, 'wpo-draft-state')
    true_in('A5. Гостю не подсказываем про возврат', html, 'data-resumable="0"')
    check('A6. Инструкция группы участнику до старта не показана', 'Практика за 1 минуту' not in html)

    # ── B. Возврат, пока попытки нет ─────────────────────────────────────────
    code, body = st.api_call('/resume', {'quiz_id': quiz_id, 'participant_email': email, 'participant_name': name})
    eq('B1. /resume без попытки: found=false', data_of(body).get('found'), False, 'код %d' % code)

    code, body = st.api_call('/resume', {'quiz_id': quiz_id, 'token': 'nett-nett-nett-nett-nett-nett'})
    eq('B2. /resume с чужим токеном не падает', code, 200)
    eq('B3. /resume с чужим токеном: found=false', data_of(body).get('found'), False)

    # ── C. Старт и черновик ──────────────────────────────────────────────────
    code, body = st.api_call('/start', {
        'quiz_id': quiz_id, 'participant_name': name, 'participant_email': email,
        'agreement': 1, 'participant_org': 'Школа № 12', 'custom_fields': {'klass': '8'},
    })
    eq('C1. Старт успешен', code, 200)
    started = data_of(body)
    tok = started.get('token', '')
    check('C2. Токен выдан', bool(tok), started)
    eq('C3. Постраничный вывод передан', started.get('questions_per_page'), 2)
    eq('C4. Настройки групп переданы', len(started.get('question_groups') or []), 2)
    eq('C5. Порядок вопросов = порядок фикстуры', [int(q['id']) for q in started.get('questions') or []], qids)
    true_in('C6. Описания полей возвращены клиенту', json.dumps(started.get('custom_fields'), ensure_ascii=False), 'Класс')

    code, body = st.api_call('/start', {'quiz_id': quiz_id, 'participant_name': 'Без анкеты', 'participant_email': 'x1-' + email, 'agreement': 1, 'participant_org': 'Школа № 12'})
    eq('C7. Регресс 1.0.21: обязательное поле анкеты по-прежнему требуется', code, 400)
    check('C8. Отказ объясняет, какое поле не заполнено', 'Класс' in json.dumps(body, ensure_ascii=False), body)

    dump = st.php_json('dump-attempt.php', tok)
    eq('D1. Порядок заданий сохранён за попыткой', dump.get('question_order'), qids)

    # ── D. Автосохранение ────────────────────────────────────────────────────
    code, body = st.api_call('/save', {
        'token': tok,
        'answers': {str(qids[0]): 'c1', str(qids[1]): 'c2', '999999': 'чужой вопрос'},
        'page': 1,
        'flags': [qids[2]],
    })
    eq('D2. Черновик принят', code, 200)
    saved = data_of(body)
    eq('D3. Чужой id вопроса отброшен', saved.get('answers_saved'), 2)
    check('D4. Сервер вернул время сохранения', bool(saved.get('saved_at')), saved)

    dump = st.php_json('dump-attempt.php', tok)
    draft = dump.get('draft') or {}
    eq('D5. Черновик лежит в базе (1-й ответ)', draft.get('answers', {}).get(str(qids[0])), 'c1')
    eq('D6. Второй ответ сохранён', (draft.get('answers') or {}).get(str(qids[1])), 'c2')
    check('D6b. Чужой id вопроса в базе не появился', '999999' not in json.dumps(draft.get('answers') or {}), draft.get('answers'))
    eq('D7. Позиция участника сохранена', draft.get('page'), 1)
    eq('D8. Флажок сохранён', draft.get('flags'), [qids[2]])
    eq('D9. Ревизия после первой записи', draft.get('revision'), 1)
    check('D10. Время автосохранения записано', bool(dump.get('draft_updated_at')), dump.get('draft_updated_at'))
    check('D11. Статус попытки не изменился от автосейва', dump.get('status'), 'in_progress')

    code, body = st.api_call('/save', {'token': tok, 'answers': {str(qids[0]): '<script>alert(1)</script>текст'}, 'page': 0})
    dump = st.php_json('dump-attempt.php', tok)
    draft2 = dump.get('draft') or {}
    check('D12. HTML из ответа вырезан', '<script' not in json.dumps(draft2, ensure_ascii=False), draft2)
    eq('D13. Ревизия выросла при перезаписи', draft2.get('revision'), 2)
    eq('D14. Черновик перезаписывается целиком', sorted((draft2.get('answers') or {}).keys()), [str(qids[0])])

    code, body = st.api_call('/save', {'token': 'nett-nett-nett', 'answers': {str(qids[0]): 'c1'}})
    eq('D15. Автосейв по несуществующему токену отклонён', code, 404)

    # ── E. Возврат к живой попытке ────────────────────────────────────────────
    code, body = st.api_call('/resume', {'quiz_id': quiz_id, 'token': tok})
    eq('E1. /resume по токену: 200', code, 200)
    res = data_of(body)
    eq('E2. /resume нашёл попытку', res.get('found'), True)
    eq('E3. /resume вернул тот же токен', res.get('token'), tok)
    eq('E4. Ответ помечен как возврат', res.get('resumed'), True)
    eq('E5. id попытки совпадает', res.get('attempt_id'), dump.get('id'))
    eq('E6. Черновик вернулся очищенным', (res.get('draft') or {}).get('answers', {}).get(str(qids[0])), 'текст')
    eq('E7. Порядок заданий при возврате тот же', [int(q['id']) for q in res.get('questions') or []], qids)
    eq('E8. Постраничный вывод при возврате тот же', res.get('questions_per_page'), 2)
    rem = res.get('remaining_seconds')
    check('E9. Остаток времени считает сервер', isinstance(rem, int) and 3600 - 400 < rem <= 3600, rem)
    eq('E10. Настройки групп вернулись', len(res.get('question_groups') or []), 2)

    code, body = st.api_call('/resume', {'quiz_id': quiz_id, 'participant_email': 'drugoy-' + email})
    eq('E11. Чужой e-mail не находит попытку', data_of(body).get('found'), False)

    st.php('shift-attempt.php', tok, 90)
    code, body = st.api_call('/resume', {'quiz_id': quiz_id, 'token': tok})
    rem2 = data_of(body).get('remaining_seconds')
    check('E12. Возврат не добавляет времени (90 с потрачены)', isinstance(rem2, int) and 3600 - 140 <= rem2 <= 3600 - 85, '%s -> %s' % (rem, rem2))

    # ── F. Группы: heartbeat, отметки и лимит ────────────────────────────────
    code, body = st.api_call('/heartbeat', {'token': tok, 'group': 'Практика'})
    eq('F1. Heartbeat с группой принят', code, 200)
    dump = st.php_json('dump-attempt.php', tok)
    check('F2. Отметка открытия группы сохранена', 'практика' in (dump.get('groups_opened') or {}), dump.get('groups_opened'))

    code, body = st.api_call('/resume', {'quiz_id': quiz_id, 'token': tok})
    elapsed = (data_of(body).get('group_elapsed') or {})
    check('F3. Потраченное время группы возвращается', 'практика' in elapsed and int(elapsed['практика']) >= 0, elapsed)
    check('F4. Группа без отметки времени не получает', 'теория' not in elapsed, elapsed)

    st.php('set-group-stamp.php', tok, 'Практика', 300)
    code, body = st.api_call('/resume', {'quiz_id': quiz_id, 'token': tok})
    elapsed = data_of(body).get('group_elapsed') or {}
    check('F5. Таймер группы продолжается, а не сбрасывается', int(elapsed.get('практика', 0)) >= 290, elapsed)

    answers = {str(q): 'c1' for q in qids}
    code, body = st.api_call('/submit', {'token': tok, 'answers': {int(k): v for k, v in answers.items()}})
    eq('F6. Сдача принята', code, 200)
    sub = data_of(body)
    eq('F7. Просроченная группа обнулена (7 из 11)', sub.get('score'), 7)
    eq('F8. Максимальный балл не изменён', sub.get('max_score'), 11)

    dump = st.php_json('dump-attempt.php', tok)
    by_q = {int(r['question_id']): r for r in dump.get('responses') or []}
    eq('F9. Ответ сданной попытки оценён', by_q[qids[0]]['awarded_points'], 3)
    eq('F10. Ответ просроченной группы не оценён', by_q[qids[2]]['awarded_points'], 0)
    eq('F11. Просроченный ответ ушёл на ручную проверку', by_q[qids[2]]['needs_manual_review'], 1)
    true_in('F12. В комментарии названа группа', by_q[qids[2]]['review_comment'], 'Практика')
    check('F13. Черновик после сдачи очищен', dump.get('draft_raw') in (None, ''), dump.get('draft_raw'))

    code, body = st.api_call('/save', {'token': tok, 'answers': {str(qids[0]): 'c2'}})
    eq('F14. Автосейв в сданную попытку закрыт', code, 404)
    code, body = st.api_call('/resume', {'quiz_id': quiz_id, 'token': tok})
    eq('F15. Сданная попытка не предлагается к возврату', data_of(body).get('found'), False)

    # ── G. «Начать заново» ───────────────────────────────────────────────────
    code, body = st.api_call('/start', {
        'quiz_id': quiz_id, 'participant_name': name, 'participant_email': 'ab-' + email,
        'agreement': 1, 'participant_org': 'Школа № 12', 'custom_fields': {'klass': '9'},
    })
    tok2 = data_of(body).get('token', '')
    check('G1. Новая попытка создана', code == 200 and bool(tok2), body)
    st.api_call('/save', {'token': tok2, 'answers': {str(qids[3]): 'c1'}, 'page': 2})
    dump2 = st.php_json('dump-attempt.php', tok2)
    eq('G2. Черновик новой попытки на месте', (dump2.get('draft') or {}).get('answers', {}).get(str(qids[3])), 'c1')

    code, body = st.api_call('/abandon', {'token': tok2, 'participant_email': 'chuzhoy@example.invalid'})
    eq('G3. Чужой e-mail не может прервать попытку', code, 403)

    code, body = st.api_call('/abandon', {'token': tok2, 'participant_email': 'ab-' + email})
    eq('G4. Отказ от попытки принят', code, 200)
    eq('G5. Ответы сохранены', data_of(body).get('answers_preserved'), True)

    dump2 = st.php_json('dump-attempt.php', tok2)
    eq('G6. Статус попытки — abandoned', dump2.get('status'), 'abandoned')
    eq('G7. Метка статуса — «Прервана»', dump2.get('status_label'), 'Прервана')
    eq('G8. Балл прерванной попытки — 0', dump2.get('score'), 0)
    eq('G9. Максимальный балл проставлен', dump2.get('max_score'), 11)
    answered2 = [r for r in (dump2.get('responses') or []) if r['user_answer'] != '']
    eq('G10. Из черновика в ответы попал ровно один ответ', len(answered2), 1)
    eq('G10b. Сохранён именно ответ участника', answered2[0]['user_answer'], 'c1')
    eq('G10c. Остальные задания зафиксированы пустыми', len(dump2.get('responses') or []), 4)
    eq('G11. Ответ ушёл на ручную проверку', (dump2['responses'][0])['needs_manual_review'], 1)
    true_in('G12. В комментарии сказана причина', (dump2['responses'][0])['review_comment'], 'начал новую')
    check('G13. Черновик после отказа очищен', dump2.get('draft_raw') in (None, ''), dump2.get('draft_raw'))

    code, body = st.api_call('/abandon', {'token': tok2, 'participant_email': 'ab-' + email})
    eq('G14. Прерванная попытка не прерывается повторно', code, 404)
    code, body = st.api_call('/resume', {'quiz_id': quiz_id, 'token': tok2})
    eq('G15. Прерванная попытка не возвращается', data_of(body).get('found'), False)

    # ── H. Возврат после истечения личного лимита ────────────────────────────
    code, body = st.api_call('/start', {
        'quiz_id': quiz_id, 'participant_name': name, 'participant_email': 'xp-' + email,
        'agreement': 1, 'participant_org': 'Школа № 12', 'custom_fields': {'klass': '7'},
    })
    tok3 = data_of(body).get('token', '')
    st.api_call('/save', {'token': tok3, 'answers': {str(qids[0]): 'c1', str(qids[1]): 'c1'}})
    st.php('shift-attempt.php', tok3, 4000)
    code, body = st.api_call('/resume', {'quiz_id': quiz_id, 'token': tok3})
    res3 = data_of(body)
    eq('H1. Просроченная попытка не возвращается как живая', res3.get('found'), False)
    eq('H2. Причина — истечение времени', res3.get('expired'), True)
    eq('H3. Написанное сохранено', res3.get('preserved'), True)
    true_in('H4. Участнику сказано про проверку', res3.get('message') or '', 'организатору')

    dump3 = st.php_json('dump-attempt.php', tok3)
    eq('H5. Попытка закрыта как expired', dump3.get('status'), 'expired')
    eq('H6. Балл нулевой', dump3.get('score'), 0)
    eq('H7. Из черновика сохранены оба ответа', len([r for r in (dump3.get('responses') or []) if r['user_answer'] != '']), 2)
    true_in('H8. Комментарий объясняет причину', (dump3['responses'][0])['review_comment'], 'пока участника не было')
    check('H9. Черновик очищен', dump3.get('draft_raw') in (None, ''), dump3.get('draft_raw'))

    # ── I. Протокол ──────────────────────────────────────────────────────────
    raw = st.php('protocol-csv.php', quiz_id)
    lines = [ln for ln in raw.replace('\ufeff', '').splitlines() if ln.strip()]
    rows = list(csv.reader(io.StringIO('\n'.join(lines)), delimiter=';', quotechar='"'))
    header = rows[0] if rows else []
    data_rows = rows[1:] if len(rows) > 1 else []
    check('I1. Протокол непустой', len(data_rows) >= 3, len(data_rows))
    true_in('I2. Колонка анкеты в протоколе', header[0] + '|'.join(header), 'Анкета: Класс')
    check('I3. Группа подписана в заголовке вопроса', any('Вопрос 1 — Теория:' in (h or '') for h in header), [h for h in header if h.startswith('Вопрос 1')])
    flat = ['|'.join(row) for row in data_rows]
    check('I4. Строка «Прервана» есть в протоколе', any('Прервана' in row for row in flat), flat[:2])
    check('I5. Прерванная попытка видна с ответом', any('Прервана' in row and 'верно' in row for row in flat), flat[:3])
    check('I6. Число колонок равно числу значений', all(len(r) == len(header) for r in data_rows), [len(r) for r in data_rows] + [len(header)])
    eq('I7. Ровно одна строка «Прервана»', sum(1 for row in flat if 'Прервана' in row), 1)
    check('I8. Просроченная попытка тоже в протоколе', any('Истекло время' in row for row in flat), flat[:2])

    # ── J. Админка ───────────────────────────────────────────────────────────
    sess = requests.Session()
    sess.get(st.base + '/wp-login.php', headers={'Cookie': 'wordpress_test_cookie=WP%20Cookie%20check'}, timeout=40)
    login = sess.post(st.base + '/wp-login.php', data={
        'log': opts.admin_login, 'pwd': opts.admin_password,
        'wp-submit': 'Войти', 'redirect_to': st.base + '/wp-admin/', 'testcookie': '1',
    }, headers={'Cookie': 'wordpress_test_cookie=WP%20Cookie%20check'}, timeout=40, allow_redirects=True)
    check('J1. Вход в админку', 'action=logout' in login.text or '/wp-admin/' in login.url, login.url)

    adm = sess.get(st.base + '/wp-admin/admin.php?page=wp-olympiad-attempts&quiz_id=%d' % quiz_id, timeout=40)
    true_in('J2. Список попыток открывается', adm.text, 'wpo-bulk-attempts-form')
    true_in('J3. В списке виден статус «Прервана»', adm.text, 'Прервана')
    true_in('J4. Фильтр по статусу «Прервана» добавлен', adm.text, 'value="abandoned"')

    review_ab = sess.get(st.base + '/wp-admin/admin.php?page=wp-olympiad-review&attempt_id=%d' % dump2['id'], timeout=40)
    check('J5. Прерванная попытка: черновика уже нет (он стал ответом)', 'Несданный черновик' not in review_ab.text, review_ab.status_code)
    true_in('J6. Прерванная попытка: сохранённый ответ виден организатору', review_ab.text, 'c1')

    # Живая попытка с черновиком -> блок «Несданный черновик»
    code, body = st.api_call('/start', {
        'quiz_id': quiz_id, 'participant_name': name, 'participant_email': 'dr-' + email,
        'agreement': 1, 'participant_org': 'Школа № 12', 'custom_fields': {'klass': '8'},
    })
    tok4 = data_of(body).get('token', '')
    st.api_call('/save', {'token': tok4, 'answers': {str(by_title['Т2']): 'c1', str(by_title['П2']): 'текст без выбора'}, 'page': 3})
    dump4 = st.php_json('dump-attempt.php', tok4)
    review_live = sess.get(st.base + '/wp-admin/admin.php?page=wp-olympiad-review&attempt_id=%d' % dump4['id'], timeout=40)
    true_in('J7. Живая попытка: блок несохранённого черновика', review_live.text, 'Несданный черновик')
    true_in('J8. В блоке показан текст задания', review_live.text, 'Т2 ' + fix['token'])
    true_in('J9. Значение из чек-бокса показано вариантом, а не id', review_live.text, 'верно')
    check('J10. Черновик не назван сданным', 'не отправлена' in review_live.text, 'нет поясняющей подписи')

    edit = sess.get(st.base + '/wp-admin/admin.php?page=wp-olympiad-edit&quiz_id=%d' % quiz_id, timeout=40)
    true_in('J11. Регресс 1.0.21: редактор анкеты и групп на месте', edit.text, 'wpo-structures-editor')

    # ── K. Регресс 1.0.20: начатая работа сдаётся при закрытом окне ──────────
    code, body = st.api_call('/start', {
        'quiz_id': quiz_id, 'participant_name': name, 'participant_email': 'late-' + email,
        'agreement': 1, 'participant_org': 'Школа № 12', 'custom_fields': {'klass': '8'},
    })
    tok5 = data_of(body).get('token', '')
    st.api_call('/save', {'token': tok5, 'answers': {str(qids[0]): 'c1'}})
    st.php('set-quiz-status.php', quiz_id, 'draft')

    code, body = st.api_call('/resume', {'quiz_id': quiz_id, 'token': tok5})
    eq('K1. При снятой с публикации олимпиаде возврат работает', data_of(body).get('found'), True)

    code, body = st.api_call('/submit', {'token': tok5, 'answers': {q: 'c1' for q in qids}})
    eq('K2. Регресс 1.0.20: начатая легально работа принята', code, 200)
    eq('K3. Баллы начислены (11, групповые лимиты целы)', data_of(body).get('score'), 11)

    code, body = st.api_call('/start', {'quiz_id': quiz_id, 'participant_name': name, 'participant_email': 'nope-' + email, 'agreement': 1, 'participant_org': 'Школа № 12', 'custom_fields': {'klass': '8'}})
    eq('K4. Новый старт при закрытом окне отклонён', code, 403)
    msg = json.dumps(body, ensure_ascii=False)
    check('K5. Регресс 1.0.19: точного времени в отказе нет', not re.search(r'\d{1,2}:\d{2}', msg), msg[:200])

    st.php('set-quiz-status.php', quiz_id, 'publish')
    code, body = st.api_call('/start', {'quiz_id': quiz_id, 'participant_name': name, 'participant_email': 'yes-' + email, 'agreement': 1, 'participant_org': 'Школа № 12', 'custom_fields': {'klass': '8'}})
    eq('K6. После публикации старт снова работает', code, 200)
    tok6 = data_of(body).get('token', '')

    # ── L. AJAX-мост (когда REST недоступен) ─────────────────────────────────
    ajax = st.base + '/wp-admin/admin-ajax.php'
    nonce_html = st.get(page_url).text
    m = re.search(r'"nonce":"([a-f0-9]{8,})"', nonce_html)
    nonce = m.group(1) if m else ''
    check('L1. Нон ajax на странице найдена', bool(nonce), 'нет config.nonce в разметке')
    if nonce:
        form = {'action': 'wp_olympiad_save_draft', 'nonce': nonce, 'token': tok6,
                'answers': json.dumps({str(qids[0]): 'c1'}), 'page': 2, 'flags': json.dumps([])}
        r = requests.post(ajax, data=form, timeout=40)
        body = r.json() if r.text else {}
        check('L2. Мост wp_olympiad_save_draft отвечает success', bool(body.get('success')), r.text[:200])
        dump6 = st.php_json('dump-attempt.php', tok6)
        eq('L3. Мост действительно записал черновик', (dump6.get('draft') or {}).get('answers', {}).get(str(qids[0])), 'c1')

        r = requests.post(ajax, data={'action': 'wp_olympiad_resume', 'nonce': nonce, 'quiz_id': quiz_id, 'token': tok6}, timeout=40)
        body = r.json() if r.text else {}
        inner = body.get('data') or {}
        eq('L4. Мост wp_olympiad_resume возвращает попытку', inner.get('found'), True)
        eq('L5. Мост несёт черновик возврата', (inner.get('draft') or {}).get('page'), 2)

        r = requests.post(ajax, data={'action': 'wp_olympiad_abandon', 'nonce': nonce, 'token': tok6}, timeout=40)
        body = r.json() if r.text else {}
        check('L6. Мост wp_olympiad_abandon работает', bool((body.get('data') or {}).get('success') or body.get('success')), r.text[:200])
        dump6 = st.php_json('dump-attempt.php', tok6)
        eq('L7. Попытка через мост получила статус «Прервана»', dump6.get('status'), 'abandoned')

    # ── M. Что установлено в файлах ──────────────────────────────────────────
    plug = os.path.join(opts.wp, 'wp-content', 'plugins', 'wp-olympiad')

    def read(*parts):
        with io.open(os.path.join(plug, *parts), encoding='utf-8') as fh:
            return fh.read()

    js = read('public', 'js', 'olympiad-frontend.js')
    for symbol, label in [
        ('beginRun(data, resumed)', 'общий вход раннера для старта и возврата'),
        ('applyResumedState(data)', 'восстановление состояния'),
        ('offerResumeBeforeStart(', 'вопрос о возврате до старта'),
        ('maybeOfferResumeOnLoad()', 'проверка возврата при загрузке страницы'),
        ('showResumeDialog(data)', 'диалог «Продолжить / Начать заново»'),
        ('queueDraftSave()', 'очередь автосохранения'),
        ('flushDraftSave(', 'запись черновика на сервер'),
        ('keepalive: true', 'отправка черновика при закрытии вкладки'),
        ('writeLocalDraft()', 'локальное зеркало черновика'),
        ('localDraftKey(token)', 'зеркало привязано к токену попытки'),
        ("'wpo_resume_v1_'", 'запись токена для возврата'),
        ('detachDraftHooks()', 'снятие обработчиков после сдачи'),
    ]:
        true_in('M1. JS: ' + label, js, symbol)
    check('M2. JS: таймер уважает серверный остаток', 'data.remaining_seconds' in js and 'remainingSeconds = (typeof remaining' in js.replace('  ', ' '), 'нет использования остатка')
    check('M3. JS: после сдачи локальный черновик убирается', 'this.clearLocalDraft();' in js and 'clearResumeToken();' in js)
    check('M4. JS: локальный черновик не льётся в чужую попытку', 'raw.token === this.token' in js)

    css = read('public', 'css', 'olympiad-frontend.css')
    for needle in ['.wpo-draft-state', '.wpo-resume-card', '.wpo-resume-actions', 'is-warn']:
        true_in('M5. CSS: ' + needle, css, needle)

    rest = read('includes', 'class-rest-api.php')
    for needle in ["'/resume'", "'/save'", "'/abandon'", 'handle_resume', 'handle_save_draft', 'handle_abandon',
                   "$payload['found'] = true", 'MAX_DRAFT_TOTAL_LENGTH', 'build_run_payload']:
        true_in('M6. REST: ' + needle, rest, needle)

    att = read('includes', 'class-attempt.php')
    for needle in ['find_resumable', 'belongs_to', 'save_draft', 'clear_draft', 'set_question_order',
                   "'abandoned' => __('Прервана'", 'draft_answers']:
        true_in('M7. Attempt: ' + needle, att, needle)

    db = read('includes', 'class-db.php')
    true_in('M8. Схема: колонка черновика', db, 'draft_answers longtext DEFAULT NULL')
    true_in('M9. Схема: колонка времени автосейва', db, 'draft_updated_at datetime DEFAULT NULL')

    sc = read('includes', 'class-shortcodes.php')
    true_in('M10. Шорткод: подсказка о возврате', sc, 'data-resumable')
    true_in('M11. Шорткод: индикатор автосейва', sc, 'wpo-draft-state')

    ex = read('includes', 'class-export.php')
    true_in('M12. Экспорт: статус «Прервана»', ex, "'abandoned'    => 'Прервана'")

    aj = read('includes', 'class-ajax.php')
    for needle in ['ajax_resume', 'ajax_save_draft', 'ajax_abandon']:
        true_in('M13. Мост: ' + needle, aj, needle)

    # Версию не запиляем числом: проверяем, что заголовок файла и константа
    # совпадают — именно расхождение этих двух мест ломает апдейт-хук и счётчик
    # обновлений, а конкретное число меняется каждый выпуск.
    main_php = read('wp-olympiad.php')
    m_head = re.search(r'\* Version: ([0-9]+(?:\.[0-9]+)*)', main_php)
    m_def = re.search(r"define\('WP_OLYMPIAD_VERSION', '([0-9]+(?:\.[0-9]+)*)'\)", main_php)
    check('M14. Версия плагина: заголовок и константа совпадают',
          bool(m_head) and bool(m_def) and m_head.group(1) == m_def.group(1),
          {'header': m_head and m_head.group(1), 'const': m_def and m_def.group(1)})
    check('M14b. Версия не ниже 1.0.22 (контракт черновика на месте)',
          bool(m_def) and tuple(int(x) for x in m_def.group(1).split('.')) >= (1, 0, 22),
          m_def and m_def.group(1))

    print('\n=======================================')
    print('Проверок: %d, пройдено: %d, отказов: %d' % (TOTAL, PASSED, FAILED))
    print('=======================================')
    return 1 if FAILED else 0


if __name__ == '__main__':
    sys.exit(main())
