#!/usr/bin/env node
/**
 * DOM-прогон кнопки «Начать выполнение олимпиады» (регресс на 1.0.24).
 *
 *   node tests/e2e/dom-start-check.mjs --base http://localhost:8127 --page 4 [--scenario ...]
 *   node tests/e2e/dom-start-check.mjs --base ... --page 4 --seed-live      # завести живую попытку
 *
 * Сценарии:
 *   start            — чистая анкета: клик по кнопке обязан открыть раннер.
 *   seed-live        — через REST заводит попытку, печатает {"token":...,"email":...}.
 *   reload-continue  — перезагрузка страницы с записью о токене в localStorage:
 *                      диалог возврата → «Продолжить попытку».
 *   reload-restart   — то же, но «Начать заново» (затем клик по большой кнопке).
 *   resume-no-questions — попытка жива, а заданий в олимпиаде не осталось
 *                      (организатор снял их с публикации): «Продолжить» не должно
 *                      убивать клик — обязан либо стартовать заново, либо объяснить отказ.
 *
 * Код 0 — раннер запустился и ни одна ошибка JS не всплыла; 1 — провал; 2 — нет jsdom/сети.
 */
import { readFileSync } from 'node:fs';

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const argv = process.argv.slice(2);
const arg = (name, dflt) => {
  const i = argv.indexOf('--' + name);
  return i >= 0 && argv[i + 1] ? argv[i + 1] : dflt;
};
const flag = (name) => argv.includes('--' + name);

const BASE = arg('base', 'http://localhost:8127').replace(/\/+$/, '');
const PAGE = arg('page', '4');
const SCENARIO = arg('scenario', 'start');
const QUIZ_ID = parseInt(arg('quiz', '1'), 10);
const PLUGIN = arg('plugin', '/home/user/.cache/wpo-test/wordpress/wp-content/plugins/wp-olympiad');
const FRONTEND_JS = readFileSync(PLUGIN + '/public/js/olympiad-frontend.js', 'utf8');
const PAGE_URL = BASE + '/?page_id=' + PAGE;
const WAIT = parseInt(arg('wait', '1500'), 10);
// Обязательные поля анкеты фикстуры (см. tests/e2e/make-quiz.php): select «Класс».
const CUSTOM = JSON.parse(arg('custom', '{"klass":"9"}'));

let JSDOM, VirtualConsole;
try {
  ({ JSDOM, VirtualConsole } = await import('jsdom'));
} catch (e) {
  console.error('jsdom не установлен: npm i jsdom (подробности в tests/e2e/README.md)');
  process.exit(2);
}

const REST = BASE + '/wp-json/wp-olympiad/v1';
const log = (kind, msg) => console.log('· ' + kind + ' | ' + msg);
const newEmail = (tag) => 'dom-' + tag + '-' + Date.now() + '-' + Math.floor(Math.random() * 1e6) + '@example.invalid';

async function rest(path, body) {
  // На стенде без ЧПУ /wp-json отдаёт HTML — тогда повторяем запрос через ?rest_route=.
  const urls = [REST + path, BASE + '/?rest_route=/wp-olympiad/v1' + path];
  let last = { status: 0, json: null };
  for (const url of urls) {
    const r = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body || {}),
    });
    let j = null;
    try { j = await r.json(); } catch (e) { j = null; }
    last = { status: r.status, json: j };
    if (j) return last;
  }
  return last;
}

async function restGet(path) {
  const urls = [REST + path, BASE + '/?rest_route=/wp-olympiad/v1' + path];
  for (const url of urls) {
    const r = await fetch(url);
    try {
      const j = await r.json();
      if (j) return j;
    } catch (e) { /* HTML вместо JSON */ }
  }
  return null;
}

/** Завести живую попытку, вернув {"token","email","attempt_id"}. */
async function seedLiveAttempt(opts = {}) {
  const email = opts.email || newEmail('live');
  const res = await rest('/start', {
    quiz_id: QUIZ_ID,
    participant_name: 'Домен Тест',
    participant_email: email,
    participant_org: 'Школа №1',
    participant_phone: '',
    participant_city: '',
    agreement: 1,
    custom_fields: CUSTOM,
  });
  const data = res.json && (res.json.data || res.json);
  if (res.status !== 200 || !data || !data.token) {
    console.error('не удалось завести попытку: HTTP ' + res.status + ' ' + JSON.stringify(res.json).slice(0, 300));
    process.exit(2);
  }
  return { token: data.token, email, attempt_id: data.attempt_id || data.attemptId || null };
}

/** Забрать HTML страницы, при необходимости вырвать раннер/диалог из-под чужих плагинов. */
async function fetchPageHtml() {
  const r = await fetch(PAGE_URL, { headers: { 'Cache-Control': 'no-cache' } });
  if (r.status !== 200) {
    console.error('страница ' + PAGE_URL + ' недоступна: HTTP ' + r.status);
    process.exit(2);
  }
  return r.text();
}

/**
 * localStorage с подставленной записью о токене: в jsdom хранилище изолировано,
 * а нам нужно воспроизвести состояние «участник перезагрузил страницу посреди попытки».
 */
function installStorageShim(window, record) {
  const store = new Map();
  if (record) store.set('wpo_resume_v1_' + QUIZ_ID, JSON.stringify(record));
  const api = {
    getItem: (k) => (store.has(String(k)) ? store.get(String(k)) : null),
    setItem: (k, v) => { store.set(String(k), String(v)); },
    removeItem: (k) => { store.delete(String(k)); },
    clear: () => store.clear(),
    key: (i) => Array.from(store.keys())[i] ?? null,
    get length() { return store.size; },
  };
  Object.defineProperty(window, 'localStorage', { value: api, configurable: true, writable: true });
  return { api, store };
}

async function runJsdom(html, record, opts) {
  const hangStart = !!(opts && opts.hangStart);
  const events = [];
  const vc = new VirtualConsole();
  const note = (kind) => (m) => events.push({ kind, msg: String(m) });
  vc.on('jsdomError', note('JSDOM ERROR'));
  vc.on('error', note('CONSOLE ERROR'));
  vc.on('warn', note('console.warn'));

  const dom = new JSDOM(html, {
    url: PAGE_URL,
    runScripts: 'dangerously',
    resources: 'usable',
    pretendToBeVisual: true,
    virtualConsole: vc,
    beforeParse(window) {
      if (record) installStorageShim(window, record);
      window.fetch = (input, init) => {
        const url = new URL(typeof input === 'string' ? input : String(input), PAGE_URL).href;
        if (hangStart && (init && init.method === 'POST') && /\/start(\?|$)|action=wp_olympiad_start/.test(url)) {
          return new Promise(() => {}); // сервер молчит forever
        }
        return fetch(url, init);
      };
      window.alert = (m) => { events.push({ kind: 'ALERT', msg: String(m) }); };
      window.confirm = () => true;
      window.addEventListener('error', (e) => note('UNCAUGHT')((e.message || '') + ' @ ' + (e.filename || '') + ':' + (e.lineno || '')));
      window.addEventListener('unhandledrejection', (e) => note('REJECTION')(((e.reason && (e.reason.stack || e.reason.message)) || String(e.reason))));
    },
  });

  const w2 = dom.window;
  // Страховка: если страница не подгрузила свой скрипт, вживляем его исходник вручную.
  await sleep(250);
  if (!w2.document.querySelector('.wpo-quiz-wrapper')) {
    log('ИТОГ', 'обёртка олимпиады отсутствует в DOM');
  } else if (w2.__wpoFrontendLoaded !== true && !w2.document.querySelector('.wpo-question-card-inner') && !w2.document.querySelector('.wpo-resume-card')) {
    const scriptEl = w2.document.createElement('script');
    scriptEl.textContent = FRONTEND_JS;
    w2.document.body.appendChild(scriptEl);
    await sleep(400);
  }
  await new Promise((r) => (w2.document.readyState === 'complete' ? r() : w2.addEventListener('load', r)));
  await sleep(WAIT);
  return { dom, w: w2, events };
}

function state(w) {
  const wrapper = w.document.querySelector(`.wpo-quiz-wrapper[data-quiz-id="${QUIZ_ID}"]`);
  if (!wrapper) return { missing: 'wrapper' };
  const intro = wrapper.querySelector('.wpo-intro-screen');
  const runner = wrapper.querySelector('.wpo-runner-screen');
  const btn = wrapper.querySelector('.wpo-btn-start');
  const shown = (el) => !!el && el.style.display !== 'none';
  const overlay = w.document.querySelector('.wpo-modal-overlay');
  const first = wrapper.querySelector('.wpo-start-form') ? wrapper.querySelector('.wpo-start-form').querySelector(':invalid') : null;
  return {
    introVisible: shown(intro),
    runnerVisible: shown(runner),
    cards: wrapper.querySelectorAll('.wpo-question-card-inner').length,
    overlay: !!overlay,
    resumeCard: !!w.document.querySelector('.wpo-resume-card'),
    resumeButtons: w.document.querySelectorAll('.wpo-resume-actions button').length,
    startDisabled: !!btn && btn.disabled === true,
    startText: btn ? btn.textContent.trim().replace(/\s+/g, ' ') : '',
    invalidField: first ? (first.getAttribute('name') || first.tagName) : '',
    timer: ((wrapper.querySelector('.wpo-timer') || {}).textContent || '').trim(),
    resumeKey: w.localStorage ? w.localStorage.getItem('wpo_resume_v1_' + QUIZ_ID) : null,
  };
}

const isErr = (e) => ['UNCAUGHT', 'REJECTION', 'JSDOM ERROR', 'CONSOLE ERROR'].includes(e.kind);

function finish(name, st, events, extraOk = () => true) {
  const errs = events.filter(isErr);
  const alerts = events.filter((e) => e.kind === 'ALERT');
  if (errs.length) log('ОШИБКИ JS', errs.length + ' шт: ' + errs.map((e) => e.kind + ': ' + e.msg.slice(0, 140)).join(' || '));
  if (alerts.length) log('ALERT', alerts.map((e) => e.msg.slice(0, 160)).join(' || '));
  // После успешного старта большая кнопка остаётся disabled — это норма: интро скрыт.
  const ok = st.cards > 0 && st.runnerVisible && !st.introVisible && !st.overlay && errs.length === 0 && extraOk();
  console.log('\nСЦЕНАРИЙ ' + name + ': ' + (ok ? 'OK' : 'ПРОВАЛЕН')
    + ` — карточек: ${st.cards}, раннер виден: ${st.runnerVisible}, интро видно: ${st.introVisible},`
    + ` диалог открыт: ${st.overlay}, кнопка disabled: ${st.startDisabled}, ошибок JS: ${errs.length}, alert: ${alerts.length}`
    + (st.invalidField ? `, невалидное поле: ${st.invalidField}` : ''));
  if (!ok) console.log('  состояние: ' + JSON.stringify(st));
  process.exit(ok ? 0 : 1);
}

async function click(w, sel, waitMs) {
  const el = w.document.querySelector(sel) || (w.document.querySelector(`.wpo-quiz-wrapper[data-quiz-id="${QUIZ_ID}"]`) || {}).querySelector?.(sel);
  if (!el) return false;
  el.click();
  await sleep(waitMs || WAIT);
  return true;
}

function fillForm(w, { email, org = 'Школа №1', name = 'Домен Тест' } = {}) {
  const wrapper = w.document.querySelector(`.wpo-quiz-wrapper[data-quiz-id="${QUIZ_ID}"]`);
  if (!wrapper) {
    log('ПРОВАЛ', `обёртка олимпиады не найдена (quiz=${QUIZ_ID}) — проверьте --quiz и --page`);
    process.exit(1);
  }
  const set = (n, v) => {
    const el = wrapper.querySelector(`[name="${n}"]`);
    if (el) { el.value = v; el.dispatchEvent(new w.Event('input', { bubbles: true })); }
  };
  set('participant_name', name);
  set('participant_email', email);
  set('participant_org', org);
  const agree = wrapper.querySelector('input[name="agreement"]');
  if (agree && !agree.checked) agree.checked = true;
  wrapper.querySelectorAll('select[data-wpo-custom][required]').forEach((s) => {
    if (s.options.length > 1) { s.selectedIndex = 1; s.dispatchEvent(new w.Event('change', { bubbles: true })); }
  });
  wrapper.querySelectorAll('input[type="checkbox"][data-wpo-custom][required]').forEach((c) => { c.checked = true; });
  wrapper.querySelectorAll('input[type="text"][data-wpo-custom][required], textarea[data-wpo-custom][required]').forEach((t) => { t.value = 'тест'; });
  wrapper.querySelectorAll('input[type="number"][data-wpo-custom][required]').forEach((t) => { t.value = '1'; });
  wrapper.querySelectorAll('input[type="date"][data-wpo-custom][required], input[data-wpo-custom][required][inputmode="numeric"]').forEach((t) => { t.value = '2026-11-01'; });
  wrapper.querySelectorAll('select[multiple][data-wpo-custom][required]').forEach((s) => {
    Array.from(s.options).forEach((o, i) => { if (i > 0) o.selected = true; });
    s.dispatchEvent(new w.Event('change', { bubbles: true }));
  });
}

/* ------------------------------------------------------------------ */

if (SCENARIO === 'seed-live') {
  const seeded = await seedLiveAttempt();
  console.log(JSON.stringify(seeded));
  process.exit(0);
}

const html = await fetchPageHtml();

if (SCENARIO === 'start') {
  const { dom, w, events } = await runJsdom(html, null);
  log('ДО', JSON.stringify(state(w)));
  fillForm(w, { email: newEmail('start') });
  await click(w, '.wpo-btn-start', WAIT + 500);
  const st = state(w);
  log('ПОСЛЕ КЛИКА', JSON.stringify(st));
  finish('start', st, events);
}

if (SCENARIO === 'reload-continue' || SCENARIO === 'reload-restart') {
  // --token/--email позволяют подставить попытку, заведённую заранее (нужно для
  // сценариев, где состояние БД меняется после старта).
  const seeded = arg('token', '') ? { token: arg('token', ''), email: arg('email', newEmail('given')) } : await seedLiveAttempt();
  const record = { token: seeded.token, email: seeded.email, savedAt: Date.now() - 60000 };
  const { dom, w, events } = await runJsdom(html, record);
  const before = state(w);
  log('ПОСЛЕ ЗАГРУЗКИ (диалог должен появиться сам)', JSON.stringify(before));
  if (!before.resumeCard) {
    log('ИТОГ', 'диалог возврата не показан — прогон не проверяет нужное состояние');
    console.log('\nСЦЕНАРИЙ ' + SCENARIO + ': ПРОВАЛЕН — нет диалога возврата при живой попытке');
    process.exit(1);
  }
  // 1.0.25: в диалоге ровно две кнопки — «Продолжить попытку» и «Начать заново».
  if (before.resumeButtons !== 2) {
    console.log('\nСЦЕНАРИЙ ' + SCENARIO + ': ПРОВАЛЕН — кнопок в диалоге ' + before.resumeButtons + ', ожидалось две');
    process.exit(1);
  }
  if (SCENARIO === 'reload-restart') {
    await click(w, '.wpo-resume-restart', WAIT);
    fillForm(w, { email: seeded.email });
    if (state(w).startDisabled) {
      log('ИТОГ', 'после «Начать заново» большая кнопка осталась заблокированной');
    }
    await click(w, '.wpo-btn-start', WAIT + 500);
  } else {
    await click(w, '.wpo-resume-continue', WAIT + 500);
  }
  const st = state(w);
  log('ПОСЛЕ ДИАЛОГА', JSON.stringify(st));
  finish(SCENARIO, st, events);
}

if (SCENARIO === 'resume-no-questions') {
  // Живая попытка, а заданий в олимпиаде не осталось (организатор удалил их или снял
  // с публикации). Состояние готовит оператор стенда: см. tests/e2e/README.md.
  // Ожидаем: либо раннер, либо внятное сообщение. Тихого «ничего не произошло» быть не должно.
  const token = arg('token', '');
  const email = arg('email', '');
  if (!token) { console.error('resume-no-questions требует --token и --email живой попытки'); process.exit(2); }
  const probe = await rest('/resume', { quiz_id: QUIZ_ID, token });
  const payload = probe && probe.json ? (probe.json.data || probe.json) : null;
  const qCount = payload && Array.isArray(payload.questions) ? payload.questions.length : -1;
  log('СОСТОЯНИЕ /resume', JSON.stringify({ status: probe.status, found: payload && payload.found, questions: qCount }));
  if (!payload || !payload.found) {
    console.log('\nСЦЕНАРИЙ resume-no-questions: ПРОВАЛЕН — сервер не отдаёт эту попытку как возвратную, состояние не воспроизведено');
    process.exit(1);
  }
  const record = { token, email, savedAt: Date.now() - 60000 };
  const { dom, w, events } = await runJsdom(html, record);
  const loaded = state(w);
  log('ПОСЛЕ ЗАГРУЗКИ', JSON.stringify({ overlay: loaded.overlay, resumeCard: loaded.resumeCard }));
  if (!loaded.overlay) {
    // Диалога нет — тогда проверка на большой кнопке: клик обязан дать раннер или сообщение.
    fillForm(w, { email });
    await click(w, '.wpo-btn-start', WAIT + 500);
  } else {
    await click(w, '.wpo-resume-continue', WAIT + 500);
  }
  const st = state(w);
  const alerts = events.filter((e) => e.kind === 'ALERT');
  const errs = events.filter(isErr);
  const silent = st.cards === 0 && !st.overlay && alerts.length === 0 && errs.length === 0;
  log('ИТОГ', JSON.stringify({ cards: st.cards, alerts: alerts.length, errs: errs.length, overlay: st.overlay }));
  console.log('\nСЦЕНАРИЙ resume-no-questions: ' + (silent
    ? 'ПРОВАЛЕН — нажатие не привело ни к раннеру, ни к сообщению (тихий отказ)'
    : 'OK — тихо не осталось (карточек: ' + st.cards + ', alert: ' + alerts.length + ', ошибок JS: ' + errs.length
      + (alerts.length ? ': ' + alerts[0].msg.slice(0, 90) : '') + ')'));
  process.exit(silent ? 1 : 0);
}

if (SCENARIO === 'reload-dismiss') {
  // Esc по диалогу = «решить позже»: попытка обязана остаться живой, кнопка — рабочей.
  const seeded = arg('token', '')
    ? { token: arg('token', ''), email: arg('email', newEmail('given')) }
    : await seedLiveAttempt();
  const record = { token: seeded.token, email: seeded.email, savedAt: Date.now() - 60000 };
  const { dom, w, events } = await runJsdom(html, record);
  const opened = state(w);
  if (!opened.resumeCard && !opened.overlay) {
    console.log('\nСЦЕНАРИЙ reload-dismiss: ПРОВАЛЕН — диалог возврата не показан');
    process.exit(1);
  }
  if (opened.resumeButtons !== 2) {
    console.log('\nСЦЕНАРИЙ reload-dismiss: ПРОВАЛЕН — кнопок в диалоге ' + opened.resumeButtons + ', ожидалось две');
    process.exit(1);
  }
  w.document.dispatchEvent(new w.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
  await sleep(600);
  const dismissed = state(w);
  log('ПОСЛЕ ESC', JSON.stringify(dismissed));
  if (dismissed.overlay) { console.log('\nСЦЕНАРИЙ reload-dismiss: ПРОВАЛЕН — диалог не закрылся'); process.exit(1); }
  if (dismissed.startDisabled) {
    console.log('\nСЦЕНАРИЙ reload-dismiss: ПРОВАЛЕН — после Esc кнопка осталась заблокированной');
    process.exit(1);
  }
  fillForm(w, { email: seeded.email });
  await click(w, '.wpo-btn-start', WAIT + 500);
  const st = state(w);
  log('ПОСЛЕ ПОВТОРНОГО КЛИКА', JSON.stringify(st));
  log('ПОПЫТКА СЦЕНАРИЯ', JSON.stringify(seeded));
  finish('reload-dismiss', st, events);
}

if (SCENARIO === 'slow-start') {
  // Сервер не отвечает: кнопка должна вернуться в работу с внятным сообщением.
  const { dom, w, events } = await runJsdom(html, null, { hangStart: true });
  fillForm(w, { email: newEmail('slow') });
  await click(w, '.wpo-btn-start', 1200);
  const stuck = state(w);
  log('ЧЕРЕЗ 1.2 с', JSON.stringify({ startDisabled: stuck.startDisabled, startText: stuck.startText }));
  await sleep(27000);
  const st = state(w);
  log('ЧЕРЕЗ 28 с', JSON.stringify({ startDisabled: st.startDisabled, startText: st.startText }));
  const alerts = events.filter((e) => e.kind === 'ALERT').map((e) => e.msg);
  const ok = st.startDisabled === false && alerts.some((m) => /не отвечает/i.test(m));
  console.log('\nСЦЕНАРИЙ slow-start: ' + (ok
    ? 'OK — кнопка разблокирована, сообщение получено: ' + alerts.join(' | ').slice(0, 120)
    : 'ПРОВАЛЕН — disabled=' + st.startDisabled + ', alert=' + JSON.stringify(alerts).slice(0, 200)));
  process.exit(ok ? 0 : 1);
}

console.error('неизвестный сценарий: ' + SCENARIO);
process.exit(2);
