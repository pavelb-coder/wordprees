#!/usr/bin/env node
/**
 * 1.0.27: проверка зеркала прохождения в localStorage на реальном фронтенде.
 *
 * Сценарии: старт → ответ → F5 → сдача, а также три отказа сервера (попытка
 * сдана, сеть лежит, хранилище недоступно). Разметка страницы и ответ /start
 * взяты с живого стенда (fixtures/), а не написаны вручную: иначе проверка
 * могла бы расходиться с тем, что реально отдаёт плагин.
 *
 * Запуск: node tests/e2e/dom-reload-check.mjs   (нужен jsdom)
 *   WPO_JSDOM_BASE=/путь/к/каталогу/с/node_modules/jsdom — если пакете не в этом репозитории.
 */

import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.resolve(HERE, '../..');

function loadJSDOM() {
    const tries = [
        () => createRequire(import.meta.url)('jsdom'),
        () => createRequire(path.join(REPO, 'package.json'))('jsdom'),
        () => createRequire(path.join(process.env.WPO_JSDOM_BASE || '/home/user/qa/deps', 'absent.js'))('jsdom'),
    ];
    for (const t of tries) {
        try {
            return t();
        } catch (e) { /* следующий вариант */ }
    }
    console.error('jsdom не найден: npm i jsdom (в репозитории или в WPO_JSDOM_BASE)');
    process.exit(2);
}

const { JSDOM } = loadJSDOM();

const JS_SRC = fs.readFileSync(path.join(REPO, 'public/js/olympiad-frontend.js'), 'utf8');
const INTRO_HTML = fs.readFileSync(path.join(HERE, 'fixtures/intro.html'), 'utf8').replace(/500/g, '7');
const PAYLOAD = JSON.parse(fs.readFileSync(path.join(HERE, 'fixtures/start-payload.json'), 'utf8'));

const CONFIG = {
    restUrl: 'http://localhost:8127/wp-json/wp-olympiad/v1',
    ajaxUrl: 'http://localhost:8127/wp-admin/admin-ajax.php',
    nonce: 'qa-nonce',
    restNonce: 'qa-rest-nonce',
    i18n: { timeExpired: 'Время вышло!' },
};

let checked = 0;
let failed = 0;

function ok(cond, what, extra = '') {
    checked++;
    if (cond) {
        console.log('[ok]   ' + what);
    } else {
        failed++;
        console.log('[FAIL] ' + what + (extra ? ' -> ' + extra : ''));
    }
}

const tick = (ms) => new Promise((r) => setTimeout(r, ms));

function jsonResponse(status, body) {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: async () => body,
    };
}

/** Дубль сервера: попыток, ответы,codes. Остаток времени задаётся явно. */
function makeServer(opts = {}) {
    const srv = {
        calls: [],
        attempts: new Map(),
        remaining: typeof opts.remaining === 'number' ? opts.remaining : 1150,
        heartbeat: opts.heartbeat || 'alive',   // alive | dead | boom
        submitResult: null,
        timeLimit: typeof opts.timeLimit === 'number' ? opts.timeLimit : 20,
    };

    srv.fetch = async (rawUrl, init) => {
        const url = String(rawUrl);
        const ep = url.includes('/wp-olympiad/v1') ? url.split('/wp-olympiad/v1')[1] : url;
        let body = {};
        try {
            body = init && init.body ? JSON.parse(init.body) : {};
        } catch (e) {
            body = {};
        }

        if (ep.indexOf('/start') === 0) {
            srv.calls.push('start');
            const token = 'tkn' + (srv.attempts.size + 1);
            srv.attempts.set(token, { status: 'in_progress', answers: null });
            return jsonResponse(200, { ...PAYLOAD, token, time_limit: srv.timeLimit });
        }

        if (ep.indexOf('/heartbeat') === 0) {
            srv.calls.push('heartbeat');
            const att = srv.attempts.get(body.token);
            if (srv.heartbeat === 'boom') {
                throw new TypeError('Failed to fetch');
            }
            if (!att || att.status !== 'in_progress' || srv.heartbeat === 'dead') {
                return jsonResponse(404, { code: 'invalid_attempt', message: 'Сессия олимпиады не найдена или уже завершена.' });
            }
            return jsonResponse(200, {
                success: true,
                status: 'ok',
                remaining_seconds: srv.remaining,
                tab_switches: 0,
                disqualified: false,
                message: '',
            });
        }

        if (ep.indexOf('/submit') === 0) {
            srv.calls.push('submit');
            const att = srv.attempts.get(body.token);
            if (!att || att.status !== 'in_progress') {
                return jsonResponse(400, { code: 'already_submitted', message: 'Эта попытка уже была отправлена ранее.' });
            }
            att.status = 'completed';
            att.answers = body.answers;
            srv.submitResult = body.answers;
            return jsonResponse(200, { success: true, mode: 'none', message: 'Ответы приняты.' });
        }

        srv.calls.push('unknown:' + ep);
        return jsonResponse(404, { code: 'no_route', message: 'Такого маршрута нет: ' + ep });
    };

    return srv;
}

/** Страница с плагином; store — содержимое localStorage «до» (имитация F5). */
async function openPage(srv, store, extra = {}) {
    const errors = [];
    const alerts = [];
    const dom = new JSDOM('<!doctype html><html><body>' + INTRO_HTML + '</body></html>', {
        url: CONFIG.restUrl.replace('/wp-olympiad/v1', '') + '/',
        runScripts: 'dangerously',
        pretendToBeVisual: true,
        beforeParse(window) {
            window.WPOlympiadConfig = { ...CONFIG, ...(extra.config || {}) };
            window.fetch = (u, i) => srv.fetch(u, i);
            window.alert = (m) => alerts.push(String(m));
            window.confirm = () => true;
            if (extra.breakStorage) {
                const broken = {
                    length: 0,
                    key: () => null,
                    getItem: () => null,
                    setItem: () => { throw new Error('QuotaExceededError'); },
                    removeItem: () => {},
                    clear: () => {},
                };
                Object.defineProperty(window, 'localStorage', { value: broken, configurable: true });
            } else {
                Object.entries(store || {}).forEach(([k, v]) => window.localStorage.setItem(k, v));
            }
            window.addEventListener('error', (ev) => errors.push(String(ev.message || ev.error)));
            window.eval(JS_SRC);
        },
    });
    dom.virtualConsole.on('jsdomError', (e) => errors.push('jsdomError: ' + (e && e.message ? e.message : String(e))));

    await tick(30);
    if (extra.autoStart) {
        fillStartForm(dom.window);
        const btn = dom.window.document.querySelector('.wpo-btn-start');
        if (btn) btn.click();
        await tick(extra.startWait || 120);
    }
    if (extra.afterStart) {
        await extra.afterStart(dom.window);
        await tick(60);
    }

    return { dom, window: dom.window, document: dom.window.document, errors, alerts, errorsSeen: () => errors.slice() };
}

function dumpStorage(window) {
    const out = {};
    const ls = window.localStorage;
    for (let i = 0; i < ls.length; i++) {
        const k = ls.key(i);
        out[k] = ls.getItem(k);
    }
    return out;
}

function readMirror(window, quizId = 7) {
    const raw = window.localStorage.getItem('wpo-run-v1:' + quizId);
    if (!raw) return null;
    try {
        return JSON.parse(raw);
    } catch (e) {
        return { parseError: true };
    }
}

const sel = {
    intro: '.wpo-intro-screen',
    runner: '.wpo-runner-screen',
    results: '.wpo-results-screen',
    timer: '.wpo-timer-val',
    start: '.wpo-btn-start',
    next: '.wpo-btn-next',
    prev: '.wpo-btn-prev',
    finish: '.wpo-btn-finish',
};

const shown = (window, what) => {
    const el = window.document.querySelector(what);
    return !!el && el.style.display !== 'none';
};

/** Заполняет обязательные поля стартовой формы: без них валидация не пускает дальше. */
function fillStartForm(window) {
    const doc = window.document;
    doc.querySelectorAll('.wpo-start-form input, .wpo-start-form textarea, .wpo-start-form select').forEach((el) => {
        if (el.type === 'checkbox') {
            el.checked = true;
        } else if (el.type === 'email') {
            el.value = 'mirror@example.invalid';
        } else if (el.type === 'number' || el.inputMode === 'numeric') {
            el.value = el.value || '7';
        } else if (!el.value) {
            el.value = 'Иван Петров';
        }
        el.dispatchEvent(new window.Event('input', { bubbles: true }));
        el.dispatchEvent(new window.Event('change', { bubbles: true }));
    });
}

const qIds = PAYLOAD.questions.map((q) => q.id);

function pickAnswer(window, qid, value) {
    const doc = window.document;
    if (value === '__text__') {
        const el = doc.querySelector('.wpo-input-text, textarea.wpo-input, input.wpo-input-text');
        if (!el) return false;
        el.value = 'a*a';
        el.dispatchEvent(new window.Event('input', { bubbles: true }));
        el.dispatchEvent(new window.Event('change', { bubbles: true }));
        return true;
    }
    const el = doc.querySelector('input[name="wpo_q_' + qid + '"][value="' + value + '"]');
    if (!el) return false;
    el.checked = true;
    el.dispatchEvent(new window.Event('change', { bubbles: true }));
    el.dispatchEvent(new window.Event('input', { bubbles: true }));
    return true;
}

function isChecked(window, qid, value) {
    const el = window.document.querySelector('input[name="wpo_q_' + qid + '"][value="' + value + '"]');
    return !!el && el.checked === true;
}

// ───────────────────────────── сценарии ─────────────────────────────

async function main() {
    const srv = makeServer({ remaining: 1150 });

    // 1) обычный старт: экран сменился, зеркало заведено
    const p1 = await openPage(srv, {}, { autoStart: true });
    ok(shown(p1.window, sel.runner), 'старт: раннер показан');
    ok(!shown(p1.window, sel.intro), 'старт: стартовая форма скрыта');
    const m1 = readMirror(p1.window);
    ok(!!m1 && m1.v === 1, 'старт: зеркало создано');
    ok(m1 && m1.token === 'tkn1', 'старт: в зеркале токен начатой попытки', m1 && String(m1.token));
    ok(m1 && m1.run && Array.isArray(m1.run.questions) && m1.run.questions.length === 3,
        'старт: в зеркале есть payload с вопросами');
    ok(m1 && m1.run && m1.run.token === 'tkn1', 'старт: payload зеркала принадлежит той же попытке');

    // 2) ответ и переход — в зеркало
    ok(pickAnswer(p1.window, qIds[0], 'c1'), 'ответ:radio выбран');
    await tick(500);
    let m2 = readMirror(p1.window);
    ok(m2 && m2.answers && m2.answers[qIds[0]] === 'c1', 'ответ: зеркало обновлено без запроса к серверу',
        m2 && JSON.stringify(m2.answers));
    const hbBefore = srv.calls.filter((c) => c === 'heartbeat').length;
    ok(srv.calls.indexOf('save') === -1 && srv.calls.filter((c) => c === 'unknown:/save').length === 0,
        'ответ: ни одного обращения к /save (серверного черновика нет)');

    const nextBtn = p1.window.document.querySelector(sel.next);
    if (nextBtn) nextBtn.click();
    await tick(80);
    m2 = readMirror(p1.window);
    ok(m2 && m2.index === 1, 'переход: позиция в зеркале обновлена', m2 && String(m2.index));
    ok(srv.calls.filter((c) => c === 'heartbeat').length === hbBefore,
        'переход: лишнего heartbeat-трафика нет');

    const store = dumpStorage(p1.window);
    ok(Object.keys(store).some((k) => k.indexOf('wpo-run-v1:') === 0), 'хранение: ключ в localStorage лежит');
    p1.dom.window.close();

    // 3) F5: возврат в ту же попытку, без нового /start, с ответом и чужим отсчётом
    const srv2 = makeServer({ remaining: 300 });
    srv2.attempts.set('tkn1', { status: 'in_progress', answers: null });
    const p2 = await openPage(srv2, store, {});
    await tick(200);
    ok(srv2.calls.indexOf('start') === -1, 'F5: /start не вызывался — новая попытка не создаётся',
        srv2.calls.join(','));
    ok(srv2.calls.indexOf('heartbeat') !== -1, 'F5: живость попытки проверена через /heartbeat');
    ok(shown(p2.window, sel.runner), 'F5: участник вернулся в раннер');
    ok(!shown(p2.window, sel.intro), 'F5: стартовая форма не показывается');
    const mRestored = readMirror(p2.window);
    ok(mRestored && mRestored.index === 1, 'F5: восстановлена позиция (тот же вопрос, на котором остановились)',
        mRestored && String(mRestored.index));
    const qbox = p2.window.document.querySelector('.wpo-question-container');
    const qtext = qbox ? qbox.textContent.replace(/\s+/g, ' ') : '';
    ok(qtext.indexOf('Площадь квадрата') !== -1, 'F5: на экране именно второй вопрос, а не первый', qtext.slice(0, 90));
    const prev = p2.window.document.querySelector(sel.prev);
    if (prev) prev.click();
    await tick(80);
    ok(isChecked(p2.window, qIds[0], 'c1'), 'F5: вернулись к первому вопросу — ответ отмечен в форме');
    const nextBack = p2.window.document.querySelector(sel.next);
    if (nextBack) nextBack.click();
    await tick(80);
    const timerText = (p2.window.document.querySelector(sel.timer) || {}).textContent || '';
    ok(timerText === '05:00', 'F5: отсчёт продолжился с серверного остатка (05:00, а не 20:00)', timerText);
    ok(p2.errorsSeen().length === 0, 'F5: без исключений', p2.errorsSeen().join(' | '));

    // 4) дописали ответ и сдали: сервер получил оба ответа, зеркало убрано
    ok(pickAnswer(p2.window, qIds[1], '__text__'), 'сдача: текстовый ответ введён');
    await tick(500);
    const finish = p2.window.document.querySelector(sel.finish);
    if (finish) finish.click();
    await tick(300);
    ok(srv2.calls.indexOf('submit') !== -1, 'сдача: /submit отправлен');
    const sent = srv2.submitResult || {};
    ok(sent[qIds[0]] === 'c1', 'сдача: восстановленный ответ доехал до сервера', JSON.stringify(sent));
    ok(typeof sent[qIds[1]] === 'string' && sent[qIds[1]].indexOf('a*a') !== -1,
        'сдача: новый ответ доехал до сервера', JSON.stringify(sent[qIds[1]]));
    ok(readMirror(p2.window) === null, 'сдача: зеркало удалено');
    ok(shown(p2.window, sel.results), 'сдача: показан экран результатов');
    p2.dom.window.close();

    // 5) F5 после сдачи: обычного старта нет, зеркало не восстановилось
    const storeAfter = { };
    const p3 = await openPage(srv2, storeAfter, {});
    await tick(120);
    ok(shown(p3.window, sel.intro), 'после сдачи: снова стартовая форма');
    ok(srv2.calls.indexOf('start') === -1, 'после сдачи: никакого самопроизвольного старта');
    p3.dom.window.close();

    // 6) попытка уже сдана на сервере (404) — зеркало убираем, старт возможен
    const srv4 = makeServer({ heartbeat: 'dead' });
    const p4 = await openPage(srv4, store, {});
    await tick(220);
    ok(readMirror(p4.window) === null, 'сдана на сервере: устаревшее зеркало удалено');
    ok(shown(p4.window, sel.intro), 'сдана на сервере: показана стартовая форма');
    fillStartForm(p4.window);
    const btn4 = p4.window.document.querySelector(sel.start);
    if (btn4) btn4.click();
    await tick(150);
    ok(srv4.calls.indexOf('start') !== -1, 'сдана на сервере: обычный старт не заблокирован');
    ok(p4.errorsSeen().length === 0, 'сдана на сервере: без исключений', p4.errorsSeen().join(' | '));
    p4.dom.window.close();

    // 7) сеть лежит — зеркало обязано выжить
    const srv5 = makeServer({ heartbeat: 'boom' });
    const p5 = await openPage(srv5, store, {});
    await tick(220);
    ok(readMirror(p5.window) !== null, 'сеть лежит: зеркало сохранено (написанное не теряется)');
    ok(shown(p5.window, sel.intro), 'сеть лежит: показана стартовая форма вместо битого раннера');
    ok(p5.errorsSeen().length === 0, 'сеть лежит: без исключений', p5.errorsSeen().join(' | '));
    p5.dom.window.close();

    // 8) localStorage недоступен (квота/запрет) — прохождение работает как в 1.0.21
    const srv6 = makeServer();
    const p6 = await openPage(srv6, {}, { autoStart: true, breakStorage: true });
    await tick(120);
    ok(shown(p6.window, sel.runner), 'без хранилища: олимпиада начинается');
    ok(pickAnswer(p6.window, qIds[0], 'c2'), 'без хранилища: ответ принимается');
    await tick(420);
    const f6 = p6.window.document.querySelector(sel.finish);
    if (f6) f6.click();
    await tick(300);
    ok(srv6.submitResult && srv6.submitResult[qIds[0]] === 'c2',
        'без хранилища: сдача доходит до сервера', JSON.stringify(srv6.submitResult));
    ok(p6.errorsSeen().length === 0, 'без хранилища: без исключений', p6.errorsSeen().join(' | '));
    p6.dom.window.close();

    // 9) мусор вместо зеркала — не падаем, чистим
    const srv7 = makeServer();
    const p7 = await openPage(srv7, { 'wpo-run-v1:7': '{"v":1,"quiz_id":"7"' }, {});
    await tick(160);
    ok(readMirror(p7.window) === null, 'битое зеркало: запись удалена');
    ok(shown(p7.window, sel.intro), 'битое зеркало: стартовая форма на месте');
    ok(p7.errorsSeen().length === 0, 'битое зеркало: без исключений', p7.errorsSeen().join(' | '));
    p7.dom.window.close();

    // 10) зеркало чужой олимпиады игнорируется
    const srv8 = makeServer();
    const p8 = await openPage(srv8, { 'wpo-run-v1:99': JSON.stringify({ v: 1, quiz_id: 99, token: 'tknX', saved_at: Date.now(), answers: {}, run: PAYLOAD }) }, {});
    await tick(160);
    ok(srv8.calls.length === 0, 'чужое зеркало: ни одного запроса к серверу', srv8.calls.join(','));
    ok(shown(p8.window, sel.intro), 'чужое зеркало: показана стартовая форма');
    p8.dom.window.close();

    // 11) протухшее зеркало (сутки) не восстанавливается
    const srv9 = makeServer();
    const stored = store['wpo-run-v1:7'];
    const stale = stored
        ? JSON.parse(stored)
        : { v: 1, quiz_id: 7, token: 'tkn1', answers: {}, run: PAYLOAD };
    stale.saved_at = Date.now() - 25 * 3600 * 1000;
    const p9 = await openPage(srv9, { 'wpo-run-v1:7': JSON.stringify(stale) }, {});
    await tick(160);
    ok(srv9.calls.indexOf('heartbeat') === -1, 'протухшее зеркало: сервер не дёргается');
    ok(readMirror(p9.window) === null, 'протухшее зеркало: удалено');
    ok(shown(p9.window, sel.intro), 'протухшее зеркало: стартовая форма');
    p9.dom.window.close();

    console.log('\nпроверок: ' + checked + ', провалов: ' + failed);
    console.log(failed === 0 ? 'DOM-ПРОВЕРКА: OK' : 'DOM-ПРОВЕРКА: ПРОВАЛЫ');
    process.exit(failed === 0 ? 0 : 1);
}

main().catch((e) => {
    console.error('харнесс упал: ' + (e && e.stack ? e.stack : e));
    process.exit(1);
});
