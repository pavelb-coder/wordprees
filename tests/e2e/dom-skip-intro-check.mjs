#!/usr/bin/env node
/**
 * 1.0.27: то же зеркало в режиме «без стартовой формы» (data-skip-intro="1").
 *
 * Здесь риск максимальный: страница сама запускает попытку при загрузке, поэтому
 * восстановление обязано опередить авто-старт — иначе каждая перезагрузка
 * заводила бы новую попытку. Проверка отделена от dom-reload-check.mjs, потому что
 * разметка и последовательность событий другие.
 *
 * Запуск: node tests/e2e/dom-skip-intro-check.mjs  (нужен jsdom, см. dom-reload-check.mjs)
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
        try { return t(); } catch (e) { /* следующий вариант */ }
    }
    console.error('jsdom не найден: npm i jsdom (в репозитории или в WPO_JSDOM_BASE)');
    process.exit(2);
}
const { JSDOM } = loadJSDOM();
const LOG = (...a) => console.log(a.map((x) => (typeof x === 'string' ? x : JSON.stringify(x))).join(' '));
const tick = (ms) => new Promise((r) => setTimeout(r, ms));

let checked = 0;
let failed = 0;
function ok(cond, what, extra = '') {
    checked++;
    if (cond) {
        LOG('[ok]   ' + what);
    } else {
        failed++;
        LOG('[FAIL] ' + what + (extra ? ' -> ' + extra : ''));
    }
}

const JS = fs.readFileSync(path.join(REPO, 'public/js/olympiad-frontend.js'), 'utf8');
const PAYLOAD = JSON.parse(fs.readFileSync(path.join(HERE, 'fixtures/start-payload.json'), 'utf8'));
const INTRO = fs.readFileSync(path.join(HERE, 'fixtures/intro.html'), 'utf8')
  .replace(/500/g, '7').replace('data-skip-intro="0"', 'data-skip-intro="1"');

function makeServer(remaining){const srv={calls:[],attempts:new Map(),remaining,submit:null,mode:'alive'};
 srv.fetch=async(u,i)=>{const ep=String(u).split('/wp-olympiad/v1')[1];const b=i&&i.body?JSON.parse(i.body):{};
  srv.calls.push(ep);
  if(ep.startsWith('/start')){ if(srv.mode==='boom') throw new TypeError('fetch fail');
    const tk='tkn'+(srv.attempts.size+1); srv.attempts.set(tk,{status:'in_progress'});
    return {ok:true,status:200,json:async()=>({...PAYLOAD,token:tk,time_limit:20})}; }
  if(ep.startsWith('/heartbeat')){const a=srv.attempts.get(b.token);
    if(!a||a.status!=='in_progress')return{ok:false,status:404,json:async()=>({code:'invalid_attempt',message:'Сессия не найдена.'})};
    return {ok:true,status:200,json:async()=>({success:true,remaining_seconds:srv.remaining,disqualified:false})}; }
  if(ep.startsWith('/submit')){const a=srv.attempts.get(b.token); if(!a||a.status!=='in_progress')return{ok:false,status:400,json:async()=>({message:'уже отправлена'})};
    a.status='completed'; srv.submit=b.answers; return {ok:true,status:200,json:async()=>({success:true,mode:'none'})}; }
  srv.calls.push('unknown:'+ep); return {ok:false,status:404,json:async()=>({message:'нет маршрута'})};};
 return srv;}

function page(srv,store){const errors=[];
 const dom=new JSDOM('<!doctype html><html><body>'+INTRO+'</body></html>',{url:'http://localhost:8127/',runScripts:'dangerously',pretendToBeVisual:true,
  beforeParse(w){w.WPOlympiadConfig={restUrl:'http://localhost:8127/wp-json/wp-olympiad/v1',ajaxUrl:'http://x',nonce:'n',restNonce:'n',i18n:{}};
   w.fetch=(u,i)=>srv.fetch(u,i); w.alert=m=>LOG('ALERT: '+m); w.confirm=()=>true;
   Object.entries(store||{}).forEach(([k,v])=>w.localStorage.setItem(k,v));
   w.addEventListener('error',e=>errors.push(String(e.message))); w.eval(JS);}});
 dom.virtualConsole.on('jsdomError',e=>errors.push('jsdomError: '+e.message));
 return {dom,w:dom.window,d:dom.window.document,errors};}

const q0=PAYLOAD.questions[0].id, q1=PAYLOAD.questions[1].id;

// A: авто-старт (форма отключена) -> попытка заведена, зеркало есть
const srv=makeServer(1150);
let p=page(srv,{}); await tick(250);
ok(srv.calls.filter(c=>c.startsWith('/start')).length===1,'skipIntro: авто-старт сделал ровно один /start',srv.calls.join(','));
ok(p.d.querySelector('.wpo-runner-screen').style.display!=='none','skipIntro: раннер показан');
let mir=JSON.parse(p.w.localStorage.getItem('wpo-run-v1:7'));
ok(mir&&mir.token==='tkn1','skipIntro: зеркало заведено авто-стартом');
let r=p.d.querySelector(`input[name="wpo_q_${q0}"][value="c2"]`);
if(r){r.checked=true;r.dispatchEvent(new p.w.Event('change',{bubbles:true}));}
await tick(450);
mir=JSON.parse(p.w.localStorage.getItem('wpo-run-v1:7'));
ok(mir&&mir.answers&&mir.answers[q0]==='c2','skipIntro: ответ попал в зеркало');
const store={'wpo-run-v1:7':p.w.localStorage.getItem('wpo-run-v1:7')};
const callsBefore=srv.calls.length; p.dom.window.close();

// B: F5 в режиме авто-старта -> нового /start быть не должно, попытка та же
const srv2=makeServer(300); srv2.attempts.set('tkn1',{status:'in_progress'});
p=page(srv2,store); await tick(400);
ok(srv2.calls.filter(c=>c.startsWith('/start')).length===0,'F5: /start НЕ вызывался при авто-старте (второй попытки нет)',srv2.calls.join(','));
ok(srv2.calls.filter(c=>c.startsWith('/heartbeat')).length>=1,'F5: попытка проверена /heartbeat');
ok(p.d.querySelector('.wpo-runner-screen').style.display!=='none','F5: раннер восстановлен');
ok(p.d.querySelector('.wpo-timer-val').textContent==='05:00','F5: отсчёт от серверного остатка',p.d.querySelector('.wpo-timer-val').textContent);
const r2=p.d.querySelector(`input[name="wpo_q_${q0}"][value="c2"]`);
ok(!!r2&&r2.checked===true,'F5: ответ отмечен в форме');
ok(p.errors.length===0,'F5: без исключений',p.errors.join(' | '));
const f=p.d.querySelector('.wpo-btn-finish'); if(f) f.click(); await tick(300);
ok(srv2.submit&&srv2.submit[q0]==='c2','F5: сдача ушла с восстановленным ответом',JSON.stringify(srv2.submit));
ok(p.w.localStorage.getItem('wpo-run-v1:7')===null,'F5: после сдачи зеркало удалено');
p.dom.window.close();

// C: F5 при мёртвой попытке -> авто-старт обязан состояться (участник не зависнет)
const srv3=makeServer(300);
p=page(srv3,store); await tick(300);
ok(srv3.calls.filter(c=>c.startsWith('/start')).length===1,'попытка сдана: авто-старт всё равно состоялся',srv3.calls.join(','));
ok(p.errors.length===0,'попытка сдана: без исключений',p.errors.join(' | '));
p.dom.window.close();

// D: сеть лежит при авто-старте с зеркалом -> зеркала не теряем
const srv4=makeServer(300); srv4.attempts.set('tkn1',{status:'in_progress'}); srv4.mode='boom';
p=page(srv4,store); await tick(350);
ok(p.w.localStorage.getItem('wpo-run-v1:7')!==null,'сеть лежит: зеркало сохранено при авто-старте');
ok(p.errors.length===0,'сеть лежит: без исключений',p.errors.join(' | '));
p.dom.window.close();

LOG('');
LOG(`проверок: ${checked}, провалов: ${failed}`);
LOG(failed===0?'SKIPINTRO: OK':'SKIPINTRO: ПРОВАЛЫ');
process.exit(failed===0?0:1);
