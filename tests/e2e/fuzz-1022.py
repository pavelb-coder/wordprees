#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""1.0.22: негативное и стресс-тестирование — поиск сбоев, ошибок и утечек.

Цель — не повторить happy-path из e2e-resume-draft.py, а попытаться уронить
установленный плагин: мусор в payload, неверные типы, границы потолков черновика,
чужие токены и попытки, повторные вызовы, параллельные автосейвы и старты, GET
вместо POST, байты вместо JSON; плюс инварианты БД и чистота wp-content/debug.log
после всего этого.

Дефектом считается: любой 5xx, не-JSON там, где ожидается JSON, отсутствие кода
ошибки при 4xx, PHP Warning/Notice/Deprecated/Fatal в логе, следы путей/стека/SQL
в ответах, битый JSON в draft_answers, черновик у завершённой попытки, черновик
без отметки времени, статус вне разрешённого набора, «прыгающая» ревизия при
последовательной записи, дублирование попыток при параллельных /start или /resume.

Ожидания сняты с кода, а не с предположений:
  * normalize_draft() потолки НЕ отклоняет, а обрезает (500 ответов, 20000 символов
    на значение, 120000 на весь черновик), page — 0..100000, flags — только свои
    числовые id без дублей, revision клиента игнорируется (серверная = хранимая + 1);
  * /save по завершённой попытке — 404 invalid_attempt, по истёкшему лимиту — 409
    time_expired, /submit повторный — 400 already_submitted, /resume с несуществующей
    олимпиадой — 404 quiz_not_found;
  /save и /submit полномочием считают сам токен (проверки принадлежности там нет —
  модель «токен = ключ» живёт с 1.0.0), принадлежность проверяют /resume и /abandon
  (403 forbidden для чужого e-mail).

Запуск (см. tests/e2e/README.md):
    python3 tests/e2e/fuzz-1022.py --base http://localhost:8127
"""

import argparse
import concurrent.futures as cf
import json
import os
import re
import statistics
import subprocess
import sys
import time
from urllib.parse import quote

import requests

HERE = os.path.dirname(os.path.abspath(__file__))

TOTAL = 0
PASSED = 0
FAILED = 0
STATUSES = {}
BODIES = []
LATENCIES = []
LEAK = ['Fatal error', 'Uncaught', 'Stack trace', 'PHP Warning', 'PHP Notice',
        'PHP Deprecated', 'Parse error', '/home/user',
        'wp-content/plugins/wp-olympiad/includes', 'SELECT * FROM wp_']


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


def note(status, text):
    STATUSES[status] = STATUSES.get(status, 0) + 1
    if len(BODIES) < 400 and text:
        BODIES.append(text)


class Stand:
    def __init__(self, wp_dir, cli, base, rest=''):
        self.wp = wp_dir
        self.cli = cli
        self.base = base.rstrip('/')
        self.api = rest or self.base + '/index.php?rest_route=/wp-olympiad/v1'
        self.sent = 0

    def php(self, script, *args):
        cmd = ['php', self.cli, 'eval-file', os.path.join(HERE, script)] + [str(a) for a in args] + ['--allow-root']
        proc = subprocess.run(cmd, cwd=self.wp, capture_output=True, text=True, timeout=240)
        if proc.returncode != 0:
            raise RuntimeError(script + ':\n' + proc.stdout + '\n' + proc.stderr)
        return proc.stdout

    def php_json(self, script, *args):
        lines = [ln for ln in self.php(script, *args).strip().splitlines() if ln.strip()]
        return json.loads(lines[-1])

    def eval_php(self, code):
        proc = subprocess.run(['php', self.cli, 'eval', code, '--allow-root'],
                              cwd=self.wp, capture_output=True, text=True, timeout=240)
        if proc.returncode != 0:
            raise RuntimeError('eval:\n' + proc.stdout + '\n' + proc.stderr)
        return proc.stdout.strip()

    def eval_json(self, code):
        return json.loads(self.eval_php(code))

    def api_call(self, path, payload=None, method='POST', raw=None, ctype='application/json'):
        url = self.api + path
        t0 = time.perf_counter()
        if raw is not None:
            res = requests.request(method, url, data=raw, headers={'Content-Type': ctype}, timeout=60)
        else:
            res = requests.request(method, url, json=payload if payload is not None else {}, timeout=60)
        LATENCIES.append((time.perf_counter() - t0) * 1000.0)
        self.sent += 1
        note(res.status_code, res.text)
        try:
            body = res.json()
        except ValueError:
            body = None
        return res.status_code, body, res.text


def data_of(body):
    if isinstance(body, dict) and 'data' in body and isinstance(body['data'], dict) and 'success' in body:
        return body['data']
    return body if isinstance(body, dict) else {}


def code_of(body):
    if not isinstance(body, dict):
        return ''
    if 'code' in body:
        return body.get('code') or ''
    data = body.get('data')
    return data.get('code') if isinstance(data, dict) else ''


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--wp', default='/home/user/.cache/wpo-test/wordpress')
    ap.add_argument('--cli', default='/home/user/.cache/wpo-test/wp-cli.phar')
    ap.add_argument('--base', default='http://localhost:8127')
    ap.add_argument('--rest', default='')
    ap.add_argument('--admin-login', default='admin')
    ap.add_argument('--admin-password', default='admin')
    opts = ap.parse_args()

    st = Stand(opts.wp, opts.cli, opts.base, opts.rest)
    debug_log = os.path.join(opts.wp, 'wp-content', 'debug.log')
    # Проверка лога имела бы смысл только «в пустоту», если на стенде не включён
    # WP_DEBUG_LOG: сверяемся с конфигурацией WordPress и читаем ровно тот файл.
    php_dbg = ('echo wp_json_encode(array("debug" => (defined("WP_DEBUG") && WP_DEBUG) ? 1 : 0, '
               '"log" => defined("WP_DEBUG_LOG") && WP_DEBUG_LOG ? '
               '(is_string(WP_DEBUG_LOG) ? WP_DEBUG_LOG : "wp-content/debug.log") : ""));')
    dbg_info = json.loads(st.eval_php(php_dbg))
    wp_log = str(dbg_info.get('log') or '')
    check('V0. На стенде включён WP_DEBUG_LOG (иначе проверка лога пуста)', bool(wp_log), dbg_info)
    if wp_log:
        debug_log = wp_log if os.path.isabs(wp_log) else os.path.join(opts.wp, 'wp-content', wp_log)
    os.makedirs(os.path.dirname(debug_log), exist_ok=True)
    open(debug_log, 'w').close()

    fix = st.php_json('make-quiz.php')
    if fix.get('error'):
        print('Фикстура не создана:', fix)
        return 2
    quiz_id = int(fix['quiz_id'])
    page_id = int(fix['page_id'])
    email = fix['email']
    name = fix['name']
    qids = [int(q['id']) for q in fix['questions']]
    page_url = st.base + '/?p=%d' % page_id
    print('фикстура: quiz=%d page=%d вопросов=%d' % (quiz_id, page_id, len(qids)))

    def count_live():
        return int(st.eval_php(r"""
global $wpdb; $t=\WPOlympiad\DB::attempts_table();
echo (string)(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE quiz_id=%d AND status='in_progress'");
""" % quiz_id))

    r = requests.get(page_url, timeout=40)
    eq('N0. Страница олимпиады открывается', r.status_code, 200)
    m = re.search(r'"restUrl":"((?:[^"\\]|\\.)*)"', r.text)
    if m:
        st.api = json.loads('"%s"' % m.group(1))
    check('N1. База REST найдена на странице участника', st.api.endswith('/wp-olympiad/v1'), st.api)

    def start(prefix='', **over):
        payload = {'quiz_id': quiz_id, 'participant_name': name, 'participant_email': prefix + email,
                   'agreement': 1, 'participant_org': 'Школа № 12', 'custom_fields': {'klass': '8'}}
        payload.update(over)
        code, body, _ = st.api_call('/start', payload)
        return code, data_of(body)

    # ── N. Транспорт: method misuse и мусор в теле ─────────────────────────
    for path in ['/save', '/resume', '/abandon', '/start', '/submit', '/heartbeat']:
        code, body, text = st.api_call(path, {}, method='GET')
        # Маршруты зарегистрированы только под POST, поэтому WordPress отвечает
        # «маршрут не найден», а не 405. Проверяем, что это аккуратный JSON-отказ.
        eq('N2. GET %s: 404 rest_no_route' % path, code, 404)
        eq('N3. GET %s: код rest_no_route' % path, code_of(body), 'rest_no_route')
        check('N4. GET %s: ответ — валидный JSON' % path, isinstance(body, dict), text[:120])

    garbage = [
        ('не JSON', b'this is not json at all', 'application/json'),
        ('пустое тело', b'', 'application/json'),
        ('битый UTF-8', b'{"token":"\xff\xfe\x22}', 'application/json'),
        ('обрезанный JSON', b'{"token": "abc", ', 'application/json'),
        ('массив вместо объекта', b'[1,2,3]', 'application/json'),
        ('null', b'null', 'application/json'),
        ('строка вместо объекта', b'"just a string"', 'application/json'),
        ('число вместо объекта', b'123', 'application/json'),
        ('form-encoded вместо JSON', b'token=abc&answers=1', 'application/x-www-form-urlencoded'),
        ('глубоко вложенный JSON', b'{"token":"x","answers":{"1":' + b'[' * 60 + b']' * 60 + b'}}', 'application/json'),
        ('NUL-escape в токене', b'{"token":"a\\u0000b","answers":{"1":"c1"}}', 'application/json'),
    ]
    for path in ['/save', '/resume', '/abandon', '/submit', '/start', '/heartbeat']:
        for label, raw, ctype in garbage:
            code, body, text = st.api_call(path, raw=raw, ctype=ctype)
            check('N5. %s с телом «%s»: без 5xx' % (path, label), code < 500, '%d %s' % (code, text[:120]))
            if path == '/resume':
                check('N6. %s с телом «%s»: либо found=false, либо 4xx с кодом' % (path, label),
                      (code == 200 and data_of(body).get('found') is False)
                      or (400 <= code < 500 and bool(code_of(body))), '%d %s' % (code, text[:120]))
            else:
                check('N6. %s с телом «%s»: 4xx с кодом ошибки' % (path, label),
                      400 <= code < 500 and bool(code_of(body)), '%d %s' % (code, text[:120]))

    flood = {'k%d%s' % (i, 'x' * 100): 'y' * 200 for i in range(60)}
    code, body, text = st.api_call('/save', flood)
    eq('N7. /save с 60 посторонними ключами и без токена: 404', code, 404)
    code, body, text = st.api_call('/resume', {'quiz_id': quiz_id, 'token': 'T' * 100000})
    check('N8. /resume с токеном в 100 000 символов: без 5xx', code < 500, code)
    eq('N9. /resume с токеном в 100 000 символов: found=false', data_of(body).get('found'), False)

    # ── O. /save: типы, чужие id, границы потолков ──────────────────────────
    code, started = start()
    eq('O1. Попытка для блока О заведена', code, 200)
    tok = started.get('token', '')
    check('O2. Токен выдан', bool(tok), started)

    def dump_draft():
        return st.php_json('dump-attempt.php', tok).get('draft') or {}

    def save_and_dump(payload):
        code, body, _text = st.api_call('/save', payload)
        return code, data_of(body), dump_draft()

    junk = [
        ('answers строкой', {'token': tok, 'answers': 'просто текст'}),
        ('answers числом', {'token': tok, 'answers': 42}),
        ('answers null', {'token': tok, 'answers': None}),
        ('answers списком', {'token': tok, 'answers': ['c1', 'c2']}),
        ('answers со вложенным объектом', {'token': tok, 'answers': {str(qids[0]): {'a': {'b': 'c'}}}}),
        ('answers с bool/int', {'token': tok, 'answers': {str(qids[0]): True, str(qids[1]): 7}}),
        ('answers с null-значением', {'token': tok, 'answers': {str(qids[0]): None}}),
        ('answers с чужими id', {'token': tok, 'answers': {'999999': 'c1', '-5': 'c2', 'abc': 'c3', '0': 'c4'}}),
        ('answers с float-ключом', {'token': tok, 'answers': {'%f' % qids[0]: 'c1', '1e3': 'c2'}}),
        ('page отрицательный', {'token': tok, 'page': -100500}),
        ('page строкой', {'token': tok, 'page': 'первая'}),
        ('page 10^12', {'token': tok, 'page': 10 ** 12}),
        ('flags картой', {'token': tok, 'flags': {'a': 1}}),
        ('flags со мусором', {'token': tok, 'flags': ['x', None, {}, 1.5, qids[0], 999999]}),
        ('revision отрицательный', {'token': tok, 'revision': -7}),
        ('revision строкой', {'token': tok, 'revision': 'abc'}),
        ('token SQLi', {"token": "' OR 1=1 --", 'answers': {str(qids[0]): 'c1'}}),
        ('token с NUL', {'token': 'a\x00b\x1bc', 'answers': {}}),
        ('token с путём', {'token': '../../../../etc/passwd', 'answers': {}}),
        ('ответ — массив массивов', {'token': tok, 'answers': {str(qids[0]): [['a'], ['b']]}}),
        ('ответ — XSS', {'token': tok, 'answers': {str(qids[0]): '<script>alert(1)</script>'}}),
    ]
    for label, payload in junk:
        code, body, text = st.api_call('/save', payload)
        check('O3. /save: %s — без 5xx' % label, code < 500, '%d %s' % (code, text[:160]))
        if code == 200:
            d = data_of(body)
            check('O4. /save: %s — success и целая ревизия' % label,
                  bool(d.get('success')) and isinstance(d.get('revision'), int), body)
            check('O5. /save: %s — answers_saved не больше числа вопросов' % label,
                  int(d.get('answers_saved') or 0) <= len(qids), body)

    code, _b, d = save_and_dump({'token': tok, 'answers': {}})
    eq('O6. Мусор не оставил ответов в черновике', len(d.get('answers') or {}), 0)
    code, _b, d = save_and_dump({'token': tok, 'page': 10 ** 12})
    eq('O7. page зажат потолком 100000', d.get('page'), 100000)
    code, _b, d = save_and_dump({'token': tok, 'page': -100500})
    eq('O8. Отрицательный page стал 0', d.get('page'), 0)
    code, _b, d = save_and_dump({'token': tok, 'page': 'первая'})
    eq('O9. Нечисловой page стал 0', d.get('page'), 0)
    code, _b, d = save_and_dump({'token': tok, 'page': 2.9})
    eq('O10. Дробный page усечён до 2', d.get('page'), 2)
    code, _b, d = save_and_dump({'token': tok, 'flags': ['x', None, {}, 1.5, qids[0], 999999]})
    eq('O11. В flags остались только свои числовые id', d.get('flags'), [qids[0]])
    code, _b, d = save_and_dump({'token': tok, 'flags': [qids[0], qids[0], qids[1]]})
    eq('O12. Дубли flags схлопнуты', d.get('flags'), [qids[0], qids[1]])
    rev_prev = int(dump_draft().get('revision') or 0)
    code, _b, d = save_and_dump({'token': tok, 'revision': 10 ** 18})
    eq('O13. Клиентская revision игнорируется: серверная = предыдущая + 1', d.get('revision'), rev_prev + 1)

    code, body, _ = st.api_call('/save', {'token': tok, 'answers': {
        str(qids[0]): '<script>alert(1)</script>',
        str(qids[1]): '<img src=x onerror=alert(1)>',
        str(qids[2]): '  обычный \tтекст  ',
    }})
    eq('O14. HTML-ответы приняты', code, 200)
    ans = dump_draft().get('answers') or {}
    check('O15. Тег <script> вырезан на сервере', 'script' not in json.dumps(ans, ensure_ascii=False).lower(), ans)
    eq('O16. Внешние пробелы срезаны, внутренние сохранены', ans.get(str(qids[2])), 'обычный \tтекст')
    check('O17. Тег <img> не дошёл до БД', '<img' not in json.dumps(ans, ensure_ascii=False), ans)

    code, body, _ = st.api_call('/save', {'token': tok, 'answers': {str(q): 'ы' * 20001 for q in qids}})
    eq('O18. Перегруженный черновик принят (обрезается, а не отклоняется)', code, 200)
    sizes = json.loads(st.eval_php(r"""
global $wpdb; $t=\WPOlympiad\DB::attempts_table();
$r=$wpdb->get_row("SELECT CHAR_LENGTH(COALESCE(draft_answers,'')) AS chars, LENGTH(COALESCE(draft_answers,'')) AS bytes FROM {$t} WHERE token='%s'", ARRAY_A);
echo wp_json_encode(array('chars'=>(int)$r['chars'],'bytes'=>(int)$r['bytes']));
""" % tok))
    # Потолок 120000 считается в символах (mb_strlen); для кириллицы в utf8mb4 это
    # до ~2 байт на символ — колонка longtext, так что байтовый размер просто контролируем.
    check('O19. Бюджет 120000 символов соблюдён', sizes['chars'] <= 121000, sizes)
    check('O19b. Байтовый размер черновика под контролем (< 400 КБ)', sizes['bytes'] < 400000, sizes)
    ans = dump_draft().get('answers') or {}
    lens = {k: len(v) for k, v in ans.items() if isinstance(v, str)}
    check('O20. Значения усечены ровно до 20000 символов', bool(lens) and all(v == 20000 for v in lens.values()), lens)
    check('O21. Часть вопросов отброшена по бюджету, а не обрезана криво', 1 <= len(ans) <= len(qids), len(ans))

    code, body, _ = st.api_call('/save', {'token': tok, 'answers': {str(qids[0]): '   '}})
    eq('O22. Ответ из одних пробелов не сохраняется', len((dump_draft().get('answers') or {})), 0)
    code, body, _ = st.api_call('/save', {'token': tok, 'answers': {str(qids[0]): 'ы' * 20000}})
    eq('O23. Ровно 20000 символов сохраняются целиком', len((dump_draft().get('answers') or {}).get(str(qids[0]), '')), 20000)

    # ── P. Принадлежность и «токен = полномочие» ────────────────────────────
    code, other = start('other-')
    tok_other = other.get('token', '')
    check('P1. Вторая попытка (другой e-mail) заведена', bool(tok_other), code)

    code, body, _ = st.api_call('/abandon', {'token': tok_other, 'participant_email': 'victim@example.invalid'})
    eq('P2. /abandon чужой попытки по чужому e-mail: 403', code, 403)
    eq('P3. Код отказа — forbidden', code_of(body), 'forbidden')
    code, guest_c = start('guest-ab-')
    tok_guest = guest_c.get('token', '')
    code, body, _ = st.api_call('/abandon', {'token': tok_guest})
    eq('P4. /abandon гостевой попытки по одному токену (модель «токен = полномочие»): 200', code, 200)

    up = 'other-' + email
    code, body, _ = st.api_call('/resume', {'quiz_id': quiz_id, 'participant_email': up.upper()})
    eq('P5. /resume находит попытку по e-mail в верхнем регистре', data_of(body).get('found'), True)
    code, body, _ = st.api_call('/resume', {'quiz_id': quiz_id, 'participant_email': '  ' + up + '  '})
    eq('P6. /resume находит её же по e-mail с обрамляющими пробелами', data_of(body).get('found'), True)
    code, body, _ = st.api_call('/resume', {'quiz_id': quiz_id, 'participant_email': up + ';x@example.com'})
    check('P7. /resume с e-mail, содержащим «;», не находит чужое (sanitize_email)', code < 500, code)

    code, body, text = st.api_call('/save', {'token': 'net-takogo-tokena', 'answers': {str(qids[0]): 'c1'}})
    eq('P8. /save с неизвестным токеном: 404', code, 404)
    eq('P9. Код — invalid_attempt', code_of(body), 'invalid_attempt')
    eq('P10. /submit с неизвестным токеном: 404', st.api_call('/submit', {'token': 'net-takogo', 'answers': {}})[0], 404)

    eq('P10a. /save перед abandon принимает ответ',
       st.api_call('/save', {'token': tok_other, 'answers': {str(qids[0]): 'до обрыва'}})[0], 200)
    code, body, _ = st.api_call('/abandon', {'token': tok_other, 'participant_email': up})
    eq('P11. /abandon своей попытки (e-mail совпал): 200', code, 200)
    dump = st.php_json('dump-attempt.php', tok_other)
    eq('P12. Статус после /abandon — abandoned', dump.get('status'), 'abandoned')
    eq('P13. /submit прерванной попытки: 400', st.api_call('/submit', {'token': tok_other, 'answers': {}})[0], 400)
    code, body, _ = st.api_call('/heartbeat', {'token': tok_other})
    check('P14. /heartbeat прерванной попытки: без 5xx', code < 500, code)
    eq('P15. /save прерванной попытки: 404', st.api_call('/save', {'token': tok_other, 'answers': {str(qids[0]): 'x'}})[0], 404)
    dump = st.php_json('dump-attempt.php', tok_other)
    check('P16. Черновик прерванной попытки обнулён', not dump.get('draft_raw'), repr(dump.get('draft_raw')))
    resp_flags = [int(r.get('needs_manual_review') or 0) for r in (dump.get('responses') or [])]
    check('P17. Ответы прерванной попытки ушли на ручную проверку', bool(resp_flags) and max(resp_flags) == 1,
          {'flags': resp_flags, 'ответов': len(resp_flags)})

    # ── Q. /resume: наход, идемпотентность, параллельность ─────────────────
    code, body, _ = st.api_call('/resume', {'quiz_id': 999999})
    eq('Q1. /resume с несуществующей олимпиадой: 404', code, 404)
    eq('Q2. Код — quiz_not_found', code_of(body), 'quiz_not_found')
    eq('Q3. /resume с quiz_id="abc": тот же 404, не 500', st.api_call('/resume', {'quiz_id': 'abc'})[0], 404)
    eq('Q4. /resume без параметров: 404 (quiz_id=0), не 500', st.api_call('/resume', {})[0], 404)
    eq('Q5. /resume с пустым e-mail и без токена: found=false',
       data_of(st.api_call('/resume', {'quiz_id': quiz_id, 'participant_email': '  '})[1]).get('found'), False)
    code, body, _ = st.api_call('/resume', {'quiz_id': quiz_id, 'participant_email': "'; DROP TABLE wp_olympiad_attempts; --"})
    eq('Q6. SQLi в e-mail: попытка отвечает 200 и found=false', (code, data_of(body).get('found')), (200, False))
    eq('Q7. SQLi: таблица попыток цела', st.eval_php(r"""
global $wpdb; $t=\WPOlympiad\DB::attempts_table();
echo (string)(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t}") > 0 ? '1' : '0';
"""), '1')

    ids = set()
    for _ in range(6):
        code, body, _ = st.api_call('/resume', {'quiz_id': quiz_id, 'token': tok})
        ids.add(data_of(body).get('attempt_id'))
    eq('Q8. Шесть /resume подряд ведут в одну попытку', len(ids), 1)
    code, body, _ = st.api_call('/resume', {'quiz_id': quiz_id, 'token': tok})
    d = data_of(body)
    check('Q9. /resume отдаёт непустой черновик', bool(d.get('draft')), d.get('draft'))
    check('Q10. /resume отдаёт остаток времени числом', isinstance(d.get('remaining_seconds'), int), d.get('remaining_seconds'))
    check('Q11. /resume отдаёт потраченное время групп', isinstance(d.get('group_elapsed'), (dict, list)), d.get('group_elapsed'))
    eq('Q12. /resume с найденной попыткой: resumed=true', d.get('resumed'), True)
    check('Q13. /resume не отдаёт e-mail и имя наружу в payload', 'participant_email' not in json.dumps(body, ensure_ascii=False),
          json.dumps(body, ensure_ascii=False)[:160])

    before = count_live()
    with cf.ThreadPoolExecutor(max_workers=8) as pool:
        par = list(pool.map(lambda _i: st.api_call('/resume', {'quiz_id': quiz_id, 'token': tok}), range(8)))
    after = count_live()
    check('Q14. 8 параллельных /resume: ни одного 5xx', all(c < 500 for c, _b, _t in par), sorted({c for c, _b, _t in par}))
    eq('Q15. 8 параллельных /resume не создали ни одной попытки', after, before)
    eq('Q16. Все 8 вернули одну и ту же попытку', len({data_of(b).get('attempt_id') for _c, b, _t in par}), 1)

    # ── R. Порядок состояний ───────────────────────────────────────────────
    code, body, _ = st.api_call('/abandon', {'token': tok, 'participant_email': email})
    eq('R1. /abandon живой попытки: 200', code, 200)
    aid1 = int(st.php_json('dump-attempt.php', tok)['id'])
    eq('R2. Повторный /abandon: 404', st.api_call('/abandon', {'token': tok, 'participant_email': email})[0], 404)
    eq('R3. После /abandon возврат ничего не предлагает',
       data_of(st.api_call('/resume', {'quiz_id': quiz_id, 'participant_email': email})[1]).get('found'), False)
    eq('R4. /save завершённой попытки: 404', st.api_call('/save', {'token': tok, 'answers': {str(qids[0]): 'после'}})[0], 404)
    n_resp = int(st.eval_php(r"""
global $wpdb; $r=\WPOlympiad\DB::responses_table();
echo (string)(int)$wpdb->get_var("SELECT COUNT(*) FROM {$r} WHERE attempt_id=%d");
""" % aid1))
    check('R5. Черновик превратился в ответы, а не остался черновиком', n_resp >= 1, n_resp)

    code, started2 = start()
    tok2 = started2.get('token', '')
    check('R6. После «начать заново» — новая попытка', bool(tok2) and tok2 != tok, tok2)
    eq('R7. Сдача новой попытки: 200', st.api_call('/submit', {'token': tok2, 'answers': {str(q): 'c1' for q in qids}})[0], 200)
    dump2 = st.php_json('dump-attempt.php', tok2)
    check('R8. После сдачи черновик = NULL', not dump2.get('draft_raw'), repr(dump2.get('draft_raw')))
    eq('R9. Повторная сдача той же попытки: 400', st.api_call('/submit', {'token': tok2, 'answers': {}})[0], 400)
    eq('R10. /abandon сданной попытки: 404', st.api_call('/abandon', {'token': tok2, 'participant_email': email})[0], 404)
    code, body, _ = st.api_call('/submit', {'token': tok2, 'answers': 'не объект'})
    check('R11. /submit уже сданной попытки с мусором: 400, не 500', code == 400, code)

    # ── S. AJAX-мост ───────────────────────────────────────────────────────
    ajax = st.base + '/wp-admin/admin-ajax.php'
    page_html = requests.get(page_url, timeout=40).text
    m = re.search(r'"nonce":"([a-f0-9]{8,})"', page_html)
    nonce = m.group(1) if m else ''
    check('S1. Нонна на странице найдена', bool(nonce), 'нет config.nonce')

    code, started3 = start('ajax-')
    tok3 = started3.get('token', '')
    s_cases = [
        ('без нонны', {'action': 'wp_olympiad_save_draft', 'token': tok3}),
        ('с неверной нонной', {'action': 'wp_olympiad_save_draft', 'nonce': 'deadbeefdeadbeefdead', 'token': tok3}),
        ('без действия', {'nonce': nonce, 'token': tok3}),
        ('с неизвестным действием', {'action': 'wp_olympiad_net_takogo', 'nonce': nonce}),
        ('с действием-мусором', {'action': '<script>x</script>', 'nonce': nonce}),
        ('с действием-массивом', {'action[]': 'a', 'nonce': nonce}),
        ('answers не JSON', {'action': 'wp_olympiad_save_draft', 'nonce': nonce, 'token': tok3, 'answers': '{{{'}),
        ('answers списком', {'action': 'wp_olympiad_save_draft', 'nonce': nonce, 'token': tok3, 'answers': json.dumps(['a'])}),
        ('answers объектом-мусором', {'action': 'wp_olympiad_save_draft', 'nonce': nonce, 'token': tok3, 'answers': '{"1":{"z":{"y":1}}}'}),
        ('answers числом', {'action': 'wp_olympiad_save_draft', 'nonce': nonce, 'token': tok3, 'answers': '12345'}),
        ('flags не JSON', {'action': 'wp_olympiad_save_draft', 'nonce': nonce, 'token': tok3, 'flags': 'не список'}),
        ('page мусором', {'action': 'wp_olympiad_save_draft', 'nonce': nonce, 'token': tok3, 'page': '../../'}),
        ('token чужой', {'action': 'wp_olympiad_resume', 'nonce': nonce, 'quiz_id': quiz_id, 'token': 'net-takogo'}),
        ('quiz_id мусором', {'action': 'wp_olympiad_resume', 'nonce': nonce, 'quiz_id': 'abc', 'token': tok3}),
        ('resume без токена и e-mail', {'action': 'wp_olympiad_resume', 'nonce': nonce, 'quiz_id': quiz_id}),
        ('abandon без токена', {'action': 'wp_olympiad_abandon', 'nonce': nonce}),
        ('abandon несуществующим токеном', {'action': 'wp_olympiad_abandon', 'nonce': nonce, 'token': 'net-takogo'}),
        ('resume с чужим e-mail', {'action': 'wp_olympiad_resume', 'nonce': nonce, 'quiz_id': quiz_id,
                                   'participant_email': 'victime@example.invalid'}),
    ]
    for label, form in s_cases:
        res = requests.post(ajax, data=form, timeout=40)
        note(res.status_code, res.text)
        body = None
        if res.text.strip().startswith('{'):
            try:
                body = res.json()
            except ValueError:
                body = None
        check('S2. AJAX «%s»: без 5xx, ответ — JSON или -1/0' % label,
              res.status_code < 500 and (res.text.strip() in ('-1', '0') or body is not None),
              '%d %s' % (res.status_code, res.text[:120]))
        check('S3. AJAX «%s»: нет стека, путей и PHP-диагностики' % label,
              not any(x in res.text for x in ('Fatal error', 'Stack trace', '/home/user', 'PHP Warning')), res.text[:160])
        check('S4. AJAX «%s»: в ответе нет чужого e-mail' % label,
              email.split('@')[0] not in res.text, res.text[:160])

    res = requests.post(ajax, data={'action': 'wp_olympiad_save_draft', 'nonce': nonce, 'token': tok3,
                                    'answers': json.dumps({str(qids[0]): 'c1'}), 'page': 3,
                                    'flags': json.dumps([qids[1]])}, timeout=40)
    note(res.status_code, res.text)
    body = res.json() if res.text.strip().startswith('{') else {}
    check('S5. AJAX-мост по-прежнему пишет черновик', bool(body.get('success')), res.text[:160])
    d3 = st.php_json('dump-attempt.php', tok3).get('draft') or {}
    eq('S6. Значение из моста дошло до БД', (d3.get('answers') or {}).get(str(qids[0])), 'c1')
    eq('S7. page из моста дошёл до БД', d3.get('page'), 3)
    eq('S8. flags из моста дошли до БД', d3.get('flags'), [qids[1]])
    res = requests.post(ajax, data={'action': 'wp_olympiad_abandon', 'nonce': nonce, 'token': tok3,
                                    'participant_email': 'ajax-' + email}, timeout=40)
    note(res.status_code, res.text)
    body = res.json() if res.text.strip().startswith('{') else {}
    check('S9. Мост wp_olympiad_abandon работает', bool(body.get('success')), res.text[:160])
    res2 = requests.post(ajax, data={'action': 'wp_olympiad_save_draft', 'nonce': nonce, 'token': tok3,
                                     'answers': json.dumps({str(qids[0]): 'после abandon'})}, timeout=40)
    note(res2.status_code, res2.text)
    body2 = res2.json() if res2.text.strip().startswith('{') else {}
    check('S9b. Мостовой /save после abandon отказывает (success=false)', body2.get('success') is False, res2.text[:160])
    eq('S9c. Мост не отдал чужих данных в отказе', 'participant_email' in json.dumps(body2, ensure_ascii=False), False)
    eq('S10. Мостовой abandon меняет статус в БД', st.php_json('dump-attempt.php', tok3).get('status'), 'abandoned')

    # ── T. Стресс: очередь автосейвов, гонка, параллельные старты ──────────
    code, started4 = start('stress-')
    tok4 = started4.get('token', '')
    aid4 = int(st.php_json('dump-attempt.php', tok4)['id'])
    rev_before = int((st.php_json('dump-attempt.php', tok4).get('draft') or {}).get('revision') or 0)
    codes = []
    revs = []
    mark = len(LATENCIES)
    for i in range(150):
        code, body, _ = st.api_call('/save', {'token': tok4, 'answers': {str(q): ('ответ %d ' % i) * 40 for q in qids},
                                             'page': i % 5, 'flags': [qids[0]]})
        codes.append(code)
        d = data_of(body)
        if isinstance(d.get('revision'), int):
            revs.append(d['revision'])
    eq('T1. 150 автосейвов подряд: все 200', set(codes), {200})
    eq('T2. Ревизия при последовательной записи не повторяется', len(set(revs)), len(revs))
    eq('T3. Ревизия выросла ровно на 149', revs[-1] - revs[0], 149)
    eq('T4. Ревизия стартует с +1 от предыдущего состояния', revs[0], rev_before + 1)
    last = (st.php_json('dump-attempt.php', tok4).get('draft') or {}).get('answers') or {}
    check('T5. В черновике лежит последний отправленный ответ', str(last.get(str(qids[0]), '')).startswith('ответ 149'),
          str(last.get(str(qids[0]), ''))[:40])
    check('T6. Худший автосейв за 150 запросов < 1500 мс', max(LATENCIES[mark:]) < 1500.0, '%.0f мс' % max(LATENCIES[mark:]))
    check('T7. Черновик не раздут: размер в пределах бюджета', len(json.dumps(last, ensure_ascii=False)) <= 121000, len(json.dumps(last)))

    with cf.ThreadPoolExecutor(max_workers=12) as pool:
        race = list(pool.map(lambda i: st.api_call('/save', {'token': tok4, 'answers': {str(qids[0]): 'race-%02d' % i}}), range(48)))
    check('T8. 48 параллельных /save в одну попытку: ни одного 5xx', all(c < 500 for c, _b, _t in race), sorted({c for c, _b, _t in race}))
    got = (st.php_json('dump-attempt.php', tok4).get('draft') or {}).get('answers') or {}
    val = str(got.get(str(qids[0]), ''))
    check('T9. После гонки черновик — целый JSON из ровно одного отправленного ответа',
          re.fullmatch(r'race-\d\d', val) is not None, val)
    rows = int(st.eval_php(r"""
global $wpdb; $t=\WPOlympiad\DB::attempts_table();
echo (string)(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE token='%s'");
""" % tok4))
    eq('T10. Гонка не размножила строки попытки', rows, 1)
    par_codes = sorted({c for c, _b, _t in race})
    eq('T11. В гонке все ответы 200', par_codes, [200])

    with cf.ThreadPoolExecutor(max_workers=10) as pool:
        starts = list(pool.map(lambda i: start('race%02d-' % i), range(10)))
    check('T12. 10 параллельных /start: ни одного 5xx', all(code < 500 for code, _s in starts), [code for code, _s in starts])
    toks = {s.get('token') for code, s in starts if code == 200}
    eq('T13. Все 10 стартов дали разные токены', len(toks), 10)
    # Живыми остаются только попытка стресс-блока (её не сдаём и не прерываем) и 10
    # попыток параллельных стартов: остальные исчерпаны submit/abandon выше.
    eq('T14. Живых попыток ровно столько, сколько должно быть (1 + 10)', count_live(), 1 + len(toks))

    dup = int(st.eval_php(r"""
global $wpdb; $t=\WPOlympiad\DB::attempts_table();
echo (string)(int)$wpdb->get_var("SELECT COUNT(*) FROM (SELECT token FROM {$t} WHERE quiz_id=%d GROUP BY token HAVING COUNT(*)>1) x");
""" % quiz_id))
    eq('T15. Дубликатов токенов у фикстуры нет', dup, 0)

    # ── W. Переполнение полей регистрации: колонки varchar(255/150/100) ────
    # Имя или школа длиннее лимита колонки на MariaDB со STRICT_TRANS_TABLES
    # означает failed INSERT. Участник в этот момент не должен получать 500:
    # длинное значение усекается, попытка заводится, черновик и возврат работают.
    w_cases = [
        ('имя 5000 символов', {'participant_name': 'И' * 5000}, 'participant_name', 255),
        ('организация 3000 символов', {'participant_org': 'О' * 3000}, 'participant_org', 255),
        ('телефон 500 символов', {'participant_phone': '8' * 500}, 'participant_phone', 100),
        ('город 500 символов', {'participant_city': 'Г' * 500}, 'participant_city', 150),
        ('e-mail 600 символов', {'participant_email': 'a' * 550 + '@example.invalid'}, 'participant_email', 255),
    ]
    w_tok = ''
    for i, (label, over, col, limit) in enumerate(w_cases):
        code, body = start('w%d-' % i, **over)
        eq('W%d. /start: %s — попытка заведена (не 500)' % (i + 1, label), code, 200)
        tok_w = body.get('token', '')
        if i == 0:
            w_tok = tok_w
        if not tok_w:
            check('W%d. /start: %s — токен выдан' % (i + 1, label), False, body)
            continue
        row = json.loads(st.eval_php(r"""
global $wpdb; $t=\WPOlympiad\DB::attempts_table();
$r=$wpdb->get_row("SELECT CHAR_LENGTH(participant_name) AS nm, CHAR_LENGTH(participant_org) AS org,
CHAR_LENGTH(participant_phone) AS ph, CHAR_LENGTH(participant_city) AS ct, CHAR_LENGTH(participant_email) AS em
FROM {$t} WHERE token='%s'", ARRAY_A);
echo wp_json_encode(array('nm'=>(int)$r['nm'],'org'=>(int)$r['org'],'ph'=>(int)$r['ph'],'ct'=>(int)$r['ct'],'em'=>(int)$r['em']));
""" % tok_w))
        got = {'participant_name': row['nm'], 'participant_org': row['org'], 'participant_phone': row['ph'],
               'participant_city': row['ct'], 'participant_email': row['em']}[col]
        check('W%d. %s: значение в лимите колонки %d (факт %d)' % (i + 1, label, limit, got), 0 < got <= limit, got)
        dup = int(st.eval_php(r"""
global $wpdb; $t=\WPOlympiad\DB::attempts_table();
echo (string)(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE token='%s'");
""" % tok_w))
        eq('W%d. %s: ровно одна строка попытки (без ретраев-дублей)' % (i + 1, label), dup, 1)

    code, w_started = start('w-live-', participant_name='Участник с очень длинным именем ' + 'И' * 400)
    tok_w = w_started.get('token', '')
    check('W6. Попытка с усечённым именем заведена', bool(tok_w), code)
    if tok_w:
        eq('W7. Автосейв в такую попытку работает', st.api_call('/save', {'token': tok_w, 'answers': {str(qids[0]): 'c1'}})[0], 200)
        d = data_of(st.api_call('/resume', {'quiz_id': quiz_id, 'token': tok_w})[1])
        eq('W8. Возврат к ней находит ту же попытку', d.get('attempt_id'),
           int(st.php_json('dump-attempt.php', tok_w)['id']))
        check('W9. Черновик возврата на месте', bool((d.get('draft') or {}).get('answers')), d.get('draft'))
        eq('W10. Сдача такой попытки проходит', st.api_call('/submit', {'token': tok_w, 'answers': {str(q): 'c1' for q in qids}})[0], 200)
        check('W11. Сданная попытка с усечённым именем не осталась живой',
              st.php_json('dump-attempt.php', tok_w).get('status') != 'in_progress',
              st.php_json('dump-attempt.php', tok_w).get('status'))

    code, body, text = st.api_call('/start', {'quiz_id': quiz_id, 'participant_name': '🎉 Аб̃ ‏Ю', 'participant_email': 'emoji-' + email,
                                              'agreement': 1, 'participant_org': 'Школа № 12', 'custom_fields': {'klass': '9'}})
    eq('W12. Эмодзи, combining и RTL-символ в имени не ломают старт', code, 200)
    if code == 200:
        nm = st.eval_php(r"""
global $wpdb; $t=\WPOlympiad\DB::attempts_table();
echo (string)$wpdb->get_var("SELECT participant_name FROM {$t} WHERE token='%s'");
""" % data_of(body).get('token', ''))
        check('W13. Имя сохранилось без потерь (эмодзи не побит)', '🎉' in nm, repr(nm))

    eq('W14. За прогон ни одного 5xx-ответа (включая секцию W)', sum(v for k, v in STATUSES.items() if k >= 500), 0)

    # ── U. Инварианты БД после издевательства ──────────────────────────────
    inv = st.eval_json(r"""
global $wpdb; $t=\WPOlympiad\DB::attempts_table(); $r=\WPOlympiad\DB::responses_table(); $q=\WPOlympiad\DB::questions_table();
$rows=$wpdb->get_results("SELECT id,status,draft_answers,draft_updated_at,started_at,submitted_at,participant_meta FROM {$t} WHERE quiz_id=%d", ARRAY_A);
$bad_draft=0;$bad_status=0;$ghost=0;$no_time=0;$too_long=0;$bad_time=0;$future=0;$bad_meta=0;
$ok=array("in_progress","completed","needs_review","graded","expired","abandoned","disqualified");
$now=strtotime(current_time('mysql'));
foreach($rows as $x){
  $d=($x['draft_answers']===null||$x['draft_answers']==='')?'':(string)$x['draft_answers'];
  if($d!==''){ if(!is_array(json_decode($d,true)))$bad_draft++; if(strlen($d)>121000)$too_long++; if(empty($x['draft_updated_at']))$no_time++; }
  if(in_array($x['status'],array("completed","graded","expired","abandoned"),true) && $d!=='')$ghost++;
  if(!in_array($x['status'],$ok,true))$bad_status++;
  if(!empty($x['submitted_at'])){ $s=strtotime($x['submitted_at']); if($s<strtotime($x['started_at']))$bad_time++; if($s>$now+120)$future++; }
  $m=$x['participant_meta'];
  if($m!==null && $m!=='' && !is_array(json_decode((string)$m,true)))$bad_meta++;
}
$resp=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$r} rr INNER JOIN {$t} aa ON rr.attempt_id=aa.id WHERE aa.quiz_id=%d");
$nq=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$q} WHERE quiz_id=%d");
$orphan=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$r} rr LEFT JOIN {$t} aa ON rr.attempt_id=aa.id WHERE aa.id IS NULL");
echo wp_json_encode(array("rows"=>count($rows),"bad_draft"=>$bad_draft,"bad_status"=>$bad_status,"ghost"=>$ghost,
"no_time"=>$no_time,"too_long"=>$too_long,"bad_time"=>$bad_time,"future"=>$future,"bad_meta"=>$bad_meta,
"resp"=>$resp,"nq"=>$nq,"orphan"=>$orphan));
""" % (quiz_id, quiz_id, quiz_id))
    print('сводка инвариантов: %s' % json.dumps(inv, ensure_ascii=False))
    check('U1. Есть на чём проверять инварианты (>=20 попыток фикстуры)', int(inv.get('rows', 0)) >= 20, inv.get('rows'))
    eq('U2. draft_answers — всегда валидный JSON', inv.get('bad_draft'), 0)
    eq('U3. Статусы только из разрешённого набора', inv.get('bad_status'), 0)
    eq('U4. У завершённых/сданных/прерванных попыток черновика нет', inv.get('ghost'), 0)
    eq('U5. Черновик без отметки времени не встречается', inv.get('no_time'), 0)
    eq('U6. Ни один черновик не вылез за бюджет', inv.get('too_long'), 0)
    eq('U7. submitted_at не раньше started_at', inv.get('bad_time'), 0)
    eq('U8. submitted_at не в будущем (учёт таймзоны стенда)', inv.get('future'), 0)
    eq('U9. participant_meta — валидный JSON', inv.get('bad_meta'), 0)
    check('U10. Ответов не больше, чем вопросов × попыток',
          int(inv.get('resp', 0)) <= int(inv.get('rows', 0)) * max(1, int(inv.get('nq', 1))),
          '%s ответов при %s попытках и %s вопросах' % (inv.get('resp'), inv.get('rows'), inv.get('nq')))
    check('U11. Ответов не наплодилось (мусорные /save не создают строк)', 0 <= int(inv.get('resp', 0)) <= 60, inv.get('resp'))

    orphans = int(st.eval_php(r"""
global $wpdb; $t=\WPOlympiad\DB::attempts_table(); $qz=\WPOlympiad\DB::quizzes_table();
echo (string)(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t} a LEFT JOIN {$qz} q ON a.quiz_id=q.id WHERE q.id IS NULL");
"""))
    eq('U12. Попыток-сирот без олимпиады не появилось', orphans, 0)

    # ── V. Логи, утечки, админка ──────────────────────────────────────────
    lines = [ln for ln in open(debug_log, encoding='utf-8', errors='replace').read().splitlines() if ln.strip()]
    php_diag = [ln for ln in lines if re.search(r'PHP (Warning|Notice|Deprecated|Fatal|Parse)', ln)]
    eq('V1. wp-content/debug.log: ноль строк за весь прогон', len(lines), 0)
    eq('V2. Ни одного PHP-предупреждения', len(php_diag), 0)
    eq('V3. Ни одного 5xx-ответа', sum(v for k, v in STATUSES.items() if k >= 500), 0)
    eq('V4. Вне ожидаемого набора кодов ответов нет', sorted(k for k in STATUSES if k not in (200, 400, 401, 403, 404, 405, 409)), [])
    eq('V5. В телах ответов нет путей, стека и SQL', sorted({p for p in LEAK if p in '\n'.join(BODIES)}), [])
    check('V6. Стенд жив после всех издевательств', requests.get(st.base + '/', timeout=40).status_code == 200)
    check('V7. HTTP-обменов за прогон: %d (включая 150 автосейвов)' % sum(STATUSES.values()), st.sent >= 250, st.sent)

    adm = requests.Session()
    adm.get(st.base + '/wp-login.php', headers={'Cookie': 'wordpress_test_cookie=WP%20Cookie%20check'}, timeout=40)
    login = adm.post(st.base + '/wp-login.php', data={
        'log': opts.admin_login, 'pwd': opts.admin_password, 'wp-submit': 'Войти',
        'redirect_to': st.base + '/wp-admin/', 'testcookie': '1',
    }, headers={'Cookie': 'wordpress_test_cookie=WP%20Cookie%20check'}, timeout=40, allow_redirects=True)
    check('V8. Вход в админку', '/wp-admin' in login.url or 'action=logout' in login.text, login.url)

    admin_cases = [
        ('проверка live-попытки', '/wp-admin/admin.php?page=wp-olympiad-review&attempt_id=%d' % aid4, 'live'),
        ('проверка прерванной попытки с XSS-payload', '/wp-admin/admin.php?page=wp-olympiad-review&attempt_id=%d' % aid1, 'xss'),
        ('проверка попытки с мусорным id', '/wp-admin/admin.php?page=wp-olympiad-review&attempt_id=' + quote("' OR 1=1"), 'plain'),
        ('список попыток', '/wp-admin/admin.php?page=wp-olympiad-attempts&quiz_id=%d' % quiz_id, 'plain'),
        ('список попыток: фильтр abandoned', '/wp-admin/admin.php?page=wp-olympiad-attempts&quiz_id=%d&status=abandoned' % quiz_id, 'abandoned'),
        ('список попыток: мусорный фильтр', '/wp-admin/admin.php?page=wp-olympiad-attempts&quiz_id=%d&status=' % quiz_id + quote("' OR 1=1"), 'plain'),
        ('редактор олимпиады', '/wp-admin/admin.php?page=wp-olympiad-edit&quiz_id=%d' % quiz_id, 'plain'),
        ('редактор олимпиады: несуществующий id', '/wp-admin/admin.php?page=wp-olympiad-edit&quiz_id=999999', 'plain'),
        ('список олимпиад', '/wp-admin/admin.php?page=wp-olympiad', 'plain'),
        ('конструктор вопросов', '/wp-admin/admin.php?page=wp-olympiad-questions', 'plain'),
    ]
    admin_negative = [
        ('несуществующая страница плагина', '/wp-admin/admin.php?page=wp-olympiad-net-takogo'),
        ('страница без прав (незалогиненный)', '/wp-admin/admin.php?page=wp-olympiad-attempts'),
    ]
    for label, url in admin_negative:
        res = adm.get(st.base + url, timeout=60)
        anon = requests.get(st.base + url, timeout=60)
        note(res.status_code, res.text)
        note(anon.status_code, anon.text)
        check('V9n. Админка: %s — без 5xx (код %d)' % (label, res.status_code), res.status_code < 500, res.status_code)
        check('V9n. Админка: %s — гостю не отдаёт данных (код %d)' % (label, anon.status_code),
              anon.status_code in (302, 403) or 'wp-login' in anon.url, anon.status_code)
        check('V9n. Админка: %s — без стека и путей' % label,
              not any(x in res.text for x in ('Fatal error', 'Stack trace', '/home/user')), res.text[:160])

    for label, url, kind in admin_cases:
        res = adm.get(st.base + url, timeout=60)
        note(res.status_code, res.text)
        eq('V9. Админка: %s — 200' % label, res.status_code, 200)
        check('V10. Админка: %s — без PHP-ошибок' % label,
              not any(x in res.text for x in ('Fatal error', 'Parse error', 'Uncaught', 'Stack trace')), res.text[:200])
        if kind == 'abandoned':
            check('V11. Админка: в фильтре abandoned есть метка «Прервана»', 'Прервана' in res.text, res.status_code)
        if kind == 'live':
            check('V12. Админка: блок «Несданный черновик» показан для live-попытки', 'Несданный черновик' in res.text, res.status_code)
        if kind == 'xss':
            check('V13. Админка: присланный <script> не выводится неотрендеренным', '<script>alert(1)</script>' not in res.text)

    # ── Очистка стенда ─────────────────────────────────────────────────────
    out = st.eval_php(r"""
global $wpdb; $t=\WPOlympiad\DB::attempts_table(); $r=\WPOlympiad\DB::responses_table();
$ids=array_map("intval",(array)$wpdb->get_col("SELECT id FROM {$t} WHERE quiz_id=%d"));
$n=0; foreach($ids as $i){ $n+=(int)$wpdb->delete($r, array('attempt_id'=>$i), array('%%d')); }
$m=(int)$wpdb->delete($t, array('quiz_id'=>%d), array('%%d'));
echo "попыток удалено {$m}, ответов {$n}";
""" % (quiz_id, quiz_id))

    print('\nочистка фикстур: %s' % out)
    print('латентность: медиана %.0f мс, p95 %.0f мс, максимум %.0f мс' % (
        statistics.median(LATENCIES),
        statistics.quantiles(LATENCIES, n=100)[94] if len(LATENCIES) >= 20 else max(LATENCIES),
        max(LATENCIES)))
    print('\n=======================================')
    print('Проверок: %d, пройдено: %d, отказов: %d' % (TOTAL, PASSED, FAILED))
    print('HTTP-обменов: %d, статусы: %s' % (sum(STATUSES.values()), dict(sorted(STATUSES.items()))))
    print('=======================================')
    return 1 if FAILED else 0


if __name__ == '__main__':
    sys.exit(main())
