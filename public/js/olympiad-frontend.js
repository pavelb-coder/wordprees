/**
 * WP Olympiad Frontend Runner
 * Pure Vanilla JavaScript (ES6+), zero dependencies
 */
(function () {
    'use strict';

    const config = window.WPOlympiadConfig || {};

    class OlympiadRunner {
        constructor(wrapper) {
            this.wrapper = wrapper;
            this.quizId = parseInt(wrapper.dataset.quizId, 10);
            this.token = '';
            this.timeLimit = 0;
            this.remainingSeconds = 0;
            this.timerInterval = null;
            this.heartbeatInterval = null;
            this.questions = [];
            this.currentIndex = 0;
            this.answers = {};
            this.flagged = new Set();
            this.tabSwitches = 0;
            this.isSubmitting = false;
            this.antiCheat = { copy: true, blur: true, max_tab_switches: 5 };
            this.sharedTexts = {};
            this.collapsedSharedTexts = new Set();
            // 1.0.21: вывод по группам вопросов и постраничный показ пачками.
            this.questionsPerPage = 1;
            this.pages = [];
            this.questionGroups = [];
            this.groupDefs = {};
            this.groupOpened = {};
            this.expiredGroups = {};
            this.groupTimerInterval = null;

            this.initDOM();
            this.bindEvents();
        }

        initDOM() {
            this.introScreen = this.wrapper.querySelector('.wpo-intro-screen');
            this.runnerScreen = this.wrapper.querySelector('.wpo-runner-screen');
            this.resultsScreen = this.wrapper.querySelector('.wpo-results-screen');
            this.skipIntro = this.wrapper.dataset.skipIntro === '1';

            this.startBtn = this.wrapper.querySelector('.wpo-btn-start');
            this.navStrip = this.wrapper.querySelector('.wpo-nav-strip');
            this.qContainer = this.wrapper.querySelector('.wpo-question-container');
            this.progressBar = this.wrapper.querySelector('.wpo-progress-bar');
            this.timerBox = this.wrapper.querySelector('.wpo-timer-box');
            this.timerVal = this.wrapper.querySelector('.wpo-timer-val');

            this.counterCurrent = this.wrapper.querySelector('.wpo-counter-current');
            this.counterTotal = this.wrapper.querySelector('.wpo-counter-total');

            this.btnPrev = this.wrapper.querySelector('.wpo-btn-prev');
            this.btnNext = this.wrapper.querySelector('.wpo-btn-next');
            this.btnFinish = this.wrapper.querySelector('.wpo-btn-finish');
            this.btnFlag = this.wrapper.querySelector('.wpo-btn-flag');
        }

        bindEvents() {
            if (this.startBtn) {
                this.startBtn.addEventListener('click', () => this.handleStart());
            }

            if (this.skipIntro) {
                // Auto-start testing immediately without showing intro screen
                this.handleStart();
            }

            if (this.btnPrev) {
                this.btnPrev.addEventListener('click', () => this.navigate(this.currentIndex - this.pageStep));
            }
            if (this.btnNext) {
                this.btnNext.addEventListener('click', () => this.navigate(this.currentIndex + this.pageStep));
            }
            if (this.btnFinish) {
                this.btnFinish.addEventListener('click', () => this.confirmSubmit());
            }
            if (this.btnFlag) {
                this.btnFlag.addEventListener('click', () => this.toggleFlag());
            }

            if (this.runnerScreen) {
                this.runnerScreen.addEventListener('click', (e) => {
                    const toggleBtn = e.target.closest('.wpo-shared-passage-toggle');
                    if (toggleBtn) {
                        const box = toggleBtn.closest('.wpo-shared-passage-box');
                        if (!box) return;
                        const stId = box.dataset.stId || 'default';
                        const body = box.querySelector('.wpo-shared-passage-body');
                        const isCollapsed = box.classList.contains('is-collapsed');
                        if (isCollapsed) {
                            box.classList.remove('is-collapsed');
                            if (body) body.style.display = 'block';
                            const txt = toggleBtn.querySelector('.wpo-st-toggle-text');
                            const icon = toggleBtn.querySelector('.wpo-st-toggle-icon');
                            if (txt) txt.textContent = 'Свернуть';
                            if (icon) icon.textContent = '▴';
                            this.collapsedSharedTexts.delete(stId);
                        } else {
                            box.classList.add('is-collapsed');
                            if (body) body.style.display = 'none';
                            const txt = toggleBtn.querySelector('.wpo-st-toggle-text');
                            const icon = toggleBtn.querySelector('.wpo-st-toggle-icon');
                            if (txt) txt.textContent = 'Развернуть';
                            if (icon) icon.textContent = '▾';
                            this.collapsedSharedTexts.add(stId);
                        }
                    }
                });
            }
        }

        async handleStart() {
            if (!this.skipIntro) {
                const form = this.wrapper.querySelector('.wpo-start-form');
                if (form && !form.checkValidity()) {
                    form.reportValidity();
                    return;
                }

                const agreementInput = this.wrapper.querySelector('input[name="agreement"]');
                if (agreementInput && !agreementInput.checked) {
                    alert('Необходимо подтвердить согласие с правилами тестирования.');
                    agreementInput.focus();
                    return;
                }
            }

            const nameInput = this.wrapper.querySelector('input[name="participant_name"]');
            const emailInput = this.wrapper.querySelector('input[name="participant_email"]');
            const orgInput = this.wrapper.querySelector('input[name="participant_org"]');
            const phoneInput = this.wrapper.querySelector('input[name="participant_phone"]');
            const cityInput = this.wrapper.querySelector('input[name="participant_city"]');
            const agreementInput = this.wrapper.querySelector('input[name="agreement"]');

            // 1.0.21: значения дополнительных полей анкеты.
            const customValues = {};
            this.wrapper.querySelectorAll('[data-wpo-custom]').forEach((el) => {
                const key = el.getAttribute('data-wpo-custom');
                if (!key) return;
                if (el.type === 'checkbox') {
                    customValues[key] = el.checked ? '1' : '';
                } else if (el.type === 'select-multiple') {
                    customValues[key] = Array.from(el.options).filter((o) => o.selected).map((o) => o.value).filter((v) => v !== '');
                } else {
                    customValues[key] = typeof el.value === 'string' ? el.value.trim() : el.value;
                }
            });

            const origBtnText = this.startBtn ? this.startBtn.textContent : '';

            const payload = {
                quiz_id: this.quizId,
                participant_name: nameInput ? nameInput.value.trim() : '',
                participant_email: emailInput ? emailInput.value.trim() : '',
                participant_org: orgInput ? orgInput.value.trim() : '',
                participant_phone: phoneInput ? phoneInput.value.trim() : '',
                participant_city: cityInput ? cityInput.value.trim() : '',
                agreement: agreementInput ? (agreementInput.checked ? 1 : 0) : 1,
                custom_fields: customValues,
            };

            if (this.startBtn) {
                this.startBtn.disabled = true;
                this.startBtn.textContent = '⏳ Загрузка заданий...';
            }

            try {
                const res = await this.apiPost('/start', payload, 'wp_olympiad_start');
                if (!res.success) {
                    const msg = res.message || (res.data && res.data.message) || 'Не удалось начать олимпиаду.';
                    if (this.skipIntro && this.runnerScreen) {
                        this.runnerScreen.innerHTML = `<div class="wpo-alert wpo-alert-error" style="padding:24px; text-align:center; color:#991b1b; background:#fef2f2; border:1px solid #fecaca; border-radius:8px;"><strong>Ошибка:</strong> ${msg}</div>`;
                    } else {
                        alert(msg);
                        if (this.startBtn) {
                            this.startBtn.disabled = false;
                            this.startBtn.textContent = origBtnText;
                        }
                    }
                    return;
                }

                const data = res.data || res;
                this.token = data.token;
                this.timeLimit = data.time_limit || 0;
                this.showQuestionPoints = data.show_question_points !== undefined ? Boolean(data.show_question_points) : true;
                this.questions = data.questions || [];
                this.sharedTexts = data.shared_texts || {};
                this.antiCheat = data.anti_cheat || this.antiCheat;
                const perPage = parseInt(data.questions_per_page, 10);
                this.questionsPerPage = perPage > 1 ? perPage : 1;
                this.questionGroups = Array.isArray(data.question_groups) ? data.question_groups : [];
                this.groupDefs = {};
                this.questionGroups.forEach((g) => {
                    if (g && g.key) this.groupDefs[g.key] = g;
                });
                this.buildPages();

                if (this.questions.length === 0) {
                    const noQMsg = 'В этой олимпиаде пока нет вопросов.';
                    if (this.skipIntro && this.runnerScreen) {
                        this.runnerScreen.innerHTML = `<div class="wpo-alert wpo-alert-info" style="padding:24px; text-align:center; color:#1e40af; background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px;">${noQMsg}</div>`;
                    } else {
                        alert(noQMsg);
                        if (this.startBtn) this.startBtn.disabled = false;
                    }
                    return;
                }

                // Switch screen
                if (this.introScreen) this.introScreen.style.display = 'none';
                if (this.runnerScreen) this.runnerScreen.style.display = 'block';

                const loadingEl = this.runnerScreen ? this.runnerScreen.querySelector('.wpo-runner-loading') : null;
                if (loadingEl) loadingEl.style.display = 'none';

                if (this.counterTotal) {
                    this.counterTotal.textContent = this.questions.length;
                }

                this.initAntiCheat();
                this.initTimer();
                this.initGroupTimers();
                this.renderNavStrip();
                this.renderQuestion(0);
                this.openCurrentGroup();
            } catch (err) {
                console.error(err);
                const errMsg = 'Сетевая ошибка при запуске олимпиады.';
                if (this.skipIntro && this.runnerScreen) {
                    this.runnerScreen.innerHTML = `<div class="wpo-alert wpo-alert-error" style="padding:24px; text-align:center; color:#991b1b; background:#fef2f2; border:1px solid #fecaca; border-radius:8px;">${errMsg}</div>`;
                } else {
                    alert(errMsg);
                    if (this.startBtn) {
                        this.startBtn.disabled = false;
                        this.startBtn.textContent = '🚀 Начать выполнение олимпиады';
                    }
                }
            }
        }

        initAntiCheat() {
            // Anti-cheat toast notifications & event throttling
            this.lastToastTimes = {};

            if (this.antiCheat.copy) {
                // Prevent context menu
                this.wrapper.addEventListener('contextmenu', (e) => {
                    e.preventDefault();
                    if (this.antiCheat.popup_warnings !== false) {
                        this.showAntiCheatToast('🔒', 'Контекстное меню заблокировано', 'Использование контекстного меню запрещено регламентом олимпиады.');
                    }
                });

                // Prevent copy & cut
                this.wrapper.addEventListener('copy', (e) => {
                    e.preventDefault();
                    if (this.antiCheat.popup_warnings !== false) {
                        this.showAntiCheatToast('🚫', 'Копирование запрещено', 'Копирование материалов и вопросов олимпиады запрещено регламентом честной игры.');
                    }
                });

                this.wrapper.addEventListener('cut', (e) => {
                    e.preventDefault();
                    if (this.antiCheat.popup_warnings !== false) {
                        this.showAntiCheatToast('✂️', 'Вырезание текста запрещено', 'Вырезание материалов теста заблокировано.');
                    }
                });

                this.wrapper.style.userSelect = 'none';

                // Prevent shortcut keys (F12, Ctrl+U, Ctrl+P, Ctrl+S, DevTools)
                window.addEventListener('keydown', (e) => {
                    if (this.isSubmitting || !this.token) return;
                    const isF12 = e.key === 'F12';
                    const isCtrlU = e.ctrlKey && (e.key === 'u' || e.key === 'U');
                    const isCtrlP = e.ctrlKey && (e.key === 'p' || e.key === 'P');
                    const isCtrlS = e.ctrlKey && (e.key === 's' || e.key === 'S');
                    const isDevTools = e.ctrlKey && e.shiftKey && ['i', 'I', 'j', 'J', 'c', 'C'].includes(e.key);

                    if (isF12 || isCtrlU || isCtrlP || isCtrlS || isDevTools) {
                        e.preventDefault();
                        if (this.antiCheat.popup_warnings !== false) {
                            this.showAntiCheatToast('⌨️', 'Клавиша заблокирована', 'Использование служебных горячих клавиш и инструментов разработчика запрещено.');
                        }
                    }
                });
            }

            if (this.antiCheat.blur) {
                let lastLeaveTime = 0;
                const onTabLeave = () => {
                    if (this.isSubmitting || !this.token) return;
                    const now = Date.now();
                    // Debounce so visibilitychange and blur together fire only once per event
                    if (now - lastLeaveTime < 2000) return;
                    lastLeaveTime = now;

                    this.tabSwitches++;
                    this.sendHeartbeat(true);

                    if (this.antiCheat.popup_warnings !== false) {
                        const maxSwitches = parseInt(this.antiCheat.max_tab_switches, 10) || 0;
                        this.showModalWarning(
                            `Зафиксирован уход со страницы олимпиады (#${this.tabSwitches}). Пожалуйста, оставайтесь на вкладке теста до отправки работы.`,
                            this.tabSwitches,
                            maxSwitches
                        );
                    }
                };

                document.addEventListener('visibilitychange', () => {
                    if (document.hidden) {
                        onTabLeave();
                    }
                });

                window.addEventListener('blur', () => {
                    onTabLeave();
                });
            }

            // Regular heartbeat every 30 seconds
            this.heartbeatInterval = setInterval(() => {
                this.sendHeartbeat(false);
            }, 30000);
        }

        async sendHeartbeat(tabSwitched) {
            if (!this.token || this.isSubmitting) return;
            try {
                const hbPayload = {
                    token: this.token,
                    tab_switched: tabSwitched ? 1 : 0,
                };
                const currentGroup = this.groupOf(this.currentIndex);
                if (currentGroup) hbPayload.group = currentGroup;
                const res = await this.apiPost('/heartbeat', hbPayload, 'wp_olympiad_heartbeat');

                const data = res.data || res;
                if (data.disqualified) {
                    this.showDisqualificationModal(data.message || 'Вы дисквалифицированы за превышение переключений вкладок.');
                    this.submitAnswers(true);
                }
            } catch (e) {
                // Ignore transient network errors during heartbeat
            }
        }

        initTimer() {
            if (this.timeLimit <= 0) {
                if (this.timerBox) this.timerBox.style.display = 'none';
                return;
            }

            this.remainingSeconds = this.timeLimit * 60;
            this.updateTimerDisplay();

            this.timerInterval = setInterval(() => {
                this.remainingSeconds--;
                this.updateTimerDisplay();

                if (this.remainingSeconds <= 0) {
                    clearInterval(this.timerInterval);
                    alert(config.i18n ? config.i18n.timeExpired : 'Время вышло! Ваши ответы автоматически отправляются...');
                    this.submitAnswers(true);
                }
            }, 1000);
        }

        updateTimerDisplay() {
            if (!this.timerVal) return;
            const m = Math.floor(this.remainingSeconds / 60);
            const s = this.remainingSeconds % 60;
            this.timerVal.textContent = `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;

            if (this.timerBox) {
                if (this.remainingSeconds <= 60) {
                    this.timerBox.className = 'wpo-timer-box danger';
                } else if (this.remainingSeconds <= 300) {
                    this.timerBox.className = 'wpo-timer-box warning';
                } else {
                    this.timerBox.className = 'wpo-timer-box';
                }
            }
        }

        renderNavStrip() {
            if (!this.navStrip) return;
            this.navStrip.innerHTML = '';

            const hasGroups = this.questions.some((q) => q.group_name && q.group_name.trim());

            if (hasGroups) {
                let lastGroup = null;
                let groupBtnsWrap = null;

                this.questions.forEach((q, idx) => {
                    const grp = (q.group_name && q.group_name.trim()) ? q.group_name.trim() : 'Общие задания';
                    if (grp !== lastGroup) {
                        lastGroup = grp;
                        const sectionWrap = document.createElement('div');
                        sectionWrap.className = 'wpo-nav-section-wrap';
                        sectionWrap.innerHTML = `<div class="wpo-nav-section-title">📁 ${this.escapeHtml(grp)}</div><div class="wpo-nav-section-buttons"></div>`;
                        this.navStrip.appendChild(sectionWrap);
                        groupBtnsWrap = sectionWrap.querySelector('.wpo-nav-section-buttons');
                    }

                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'wpo-nav-btn';
                    btn.textContent = idx + 1;
                    btn.dataset.index = idx;
                    btn.addEventListener('click', () => {
                        this.navigate(idx);
                    });
                    groupBtnsWrap.appendChild(btn);
                });
            } else {
                this.questions.forEach((q, idx) => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'wpo-nav-btn';
                    btn.textContent = idx + 1;
                    btn.dataset.index = idx;

                    btn.addEventListener('click', () => {
                        this.navigate(idx);
                    });

                    this.navStrip.appendChild(btn);
                });
            }
        }

        updateNavState() {
            if (!this.navStrip) return;
            const buttons = this.navStrip.querySelectorAll('.wpo-nav-btn');

            buttons.forEach((btn, idx) => {
                const q = this.questions[idx];
                const isCurrent = idx === this.currentIndex;
                const isAnswered = this.isQuestionAnswered(q.id);
                const isFlagged = this.flagged.has(q.id);
                const isReq = Boolean(q.is_required);

                btn.classList.toggle('active', isCurrent);
                btn.classList.toggle('answered', isAnswered);
                btn.classList.toggle('flagged', isFlagged);
                btn.classList.toggle('is-required', isReq);
                btn.classList.toggle('req-pending', isReq && !isAnswered);
            });

            // Update Progress Bar
            const answeredCount = this.questions.filter((q) => this.isQuestionAnswered(q.id)).length;
            const pct = Math.round((answeredCount / this.questions.length) * 100);
            if (this.progressBar) {
                this.progressBar.style.width = `${pct}%`;
            }

            // Update Counter
            if (this.counterCurrent) {
                this.counterCurrent.textContent = this.currentIndex + 1;
            }

            // Update Navigation Buttons
            if (this.btnPrev) {
                this.btnPrev.disabled = this.currentIndex === 0;
            }
            if (this.counterTotal && this.questionsPerPage > 1 && this.pages.length) {
                const page = this.currentPageIndex;
                this.counterTotal.textContent = `${this.questions.length} (стр. ${page + 1} из ${this.pages.length})`;
            }

            const isLast = this.currentIndex + this.pageStep >= this.questions.length;
            if (this.btnNext) {
                this.btnNext.style.display = isLast ? 'none' : 'inline-flex';
            }
            if (this.btnFinish) {
                this.btnFinish.style.display = isLast ? 'inline-flex' : 'none';
            }

            // Update Flag Button
            const currentQ = this.questions[this.currentIndex];
            if (this.btnFlag && currentQ) {
                this.btnFlag.classList.toggle('active', this.flagged.has(currentQ.id));
            }
        }

        isQuestionAnswered(qid) {
            const val = this.answers[qid];
            if (val === undefined || val === null) return false;
            if (Array.isArray(val)) return val.length > 0;
            if (typeof val === 'object') {
                return Object.keys(val).length > 0 && Object.values(val).some((v) => String(v).trim() !== '');
            }
            return String(val).trim() !== '';
        }

        toggleFlag() {
            const currentQ = this.questions[this.currentIndex];
            if (!currentQ) return;

            if (this.flagged.has(currentQ.id)) {
                this.flagged.delete(currentQ.id);
            } else {
                this.flagged.add(currentQ.id);
            }
            this.updateNavState();
        }

        navigate(newIndex) {
            if (newIndex < 0 || newIndex >= this.questions.length) return;
            this.currentIndex = newIndex;
            this.renderCurrent();
        }

        /** Шаг перелистывания: один вопрос или вся страница пачки. */
        get pageStep() {
            return this.questionsPerPage > 1 ? this.questionsPerPage : 1;
        }

        buildPages() {
            this.pages = [];
            if (this.questionsPerPage <= 1) return;
            for (let i = 0; i < this.questions.length; i += this.questionsPerPage) {
                const slice = [];
                for (let j = i; j < Math.min(i + this.questionsPerPage, this.questions.length); j++) {
                    slice.push(j);
                }
                this.pages.push(slice);
            }
        }

        get currentPageIndex() {
            if (this.questionsPerPage <= 1) return this.currentIndex;
            return Math.min(
                this.pages.length - 1,
                Math.max(0, Math.floor(this.currentIndex / this.questionsPerPage))
            );
        }

        renderCurrent() {
            if (this.questionsPerPage > 1) {
                this.renderPage(this.currentPageIndex);
                return;
            }
            this.renderQuestion(this.currentIndex);
            this.openCurrentGroup();
        }

        renderPage(pageIndex) {
            if (!this.qContainer) return;
            const indexes = this.pages[pageIndex];
            if (!indexes || !indexes.length) return;

            this.currentIndex = indexes[0];

            let html = '';
            indexes.forEach((idx) => {
                html += `<div class="wpo-page-slot" data-q-index="${idx}"></div>`;
            });
            this.qContainer.innerHTML = html;

            this.qContainer.querySelectorAll('.wpo-page-slot').forEach((slot) => {
                const idx = parseInt(slot.getAttribute('data-q-index'), 10);
                if (!isNaN(idx)) this.renderQuestion(idx, slot);
            });

            this.updateNavState();
            this.openCurrentGroup();
        }

        /** Название группы текущего задания ('' — вне групп). */
        groupOf(index) {
            const q = this.questions[index];
            if (!q || !q.group_name) return '';
            return String(q.group_name).trim();
        }

        groupKey(name) {
            return String(name || '').replace(/\s+/g, ' ').trim().toLowerCase();
        }

        isFirstOfGroup(index) {
            const name = this.groupOf(index);
            if (name === '') return false;
            return this.groupOf(index - 1) !== name;
        }

        /** С этого момента на сервере идёт таймер группы. */
        openCurrentGroup() {
            this.openGroup(this.groupOf(this.currentIndex));
        }

        openGroup(name) {
            if (!name) return;
            const key = this.groupKey(name);
            if (!this.groupDefs[key] || this.groupOpened[key]) return;
            this.groupOpened[key] = Date.now();
            this.apiPost('/heartbeat', { token: this.token, group: name }, 'wp_olympiad_heartbeat').catch(() => {});
        }

        renderQuestion(index, root) {
            const q = this.questions[index];
            const container = root || this.qContainer;
            if (!q || !container) return;

            const renderImageHtml = () => {
                if (!q.image_url) return '';
                const altText = this.escapeHtml(q.image_alt || q.image_caption || 'Иллюстрация к вопросу');
                const captionHtml = q.image_caption ? `<figcaption class="wpo-question-image-caption">${this.escapeHtml(q.image_caption)}</figcaption>` : '';
                return `
                    <figure class="wpo-question-image-wrap">
                        <img src="${this.escapeHtml(q.image_url)}" alt="${altText}" class="wpo-question-image" loading="lazy" data-zoomable="true" title="Нажмите для увеличения">
                        ${captionHtml}
                    </figure>
                `;
            };

            const imgPos = q.image_position || 'above';
            const imgHtml = renderImageHtml();
            const isReq = Boolean(q.is_required);
            const reqStar = isReq ? '<span class="wpo-required-star" title="Обязательный вопрос">*</span>' : '';
            const reqPill = isReq ? '<span class="wpo-required-pill">Обязательный</span>' : '';

            // Check if points should be displayed
            let showPts = this.showQuestionPoints !== false;
            if (q.show_points !== undefined && q.show_points !== null) {
                showPts = Boolean(q.show_points);
            }
            const pointsPill = showPts ? `<span class="wpo-points-pill">Баллов: ${Math.round(q.points)}</span>` : '';
            const penaltyPill = (showPts && q.penalty > 0) ? `<span class="wpo-penalty-pill">Штраф за ошибку: -${Math.round(q.penalty)}</span>` : '';

            // Question group badge
            const groupBadge = (q.group_name && q.group_name.trim()) ? `<div class="wpo-question-group-badge">📁 ${this.escapeHtml(q.group_name.trim())}</div>` : '';

            // 1.0.21: шапка группы — название, инструкция и отсчёт времени группы.
            const groupNameHere = this.groupOf(index);
            const groupDefHere = groupNameHere ? this.groupDefs[this.groupKey(groupNameHere)] : null;
            let groupBannerHtml = '';
            if (groupNameHere && this.isFirstOfGroup(index) && groupDefHere) {
                const gTitle = groupDefHere.title || groupNameHere;
                const gLimit = parseInt(groupDefHere.time_limit, 10) || 0;
                const gInstruction = groupDefHere.instruction
                    ? `<div class="wpo-group-instruction">${this.escapeHtml(groupDefHere.instruction)}</div>`
                    : '';
                const gTimer = gLimit > 0
                    ? `<span class="wpo-group-timer" data-group-timer="${this.escapeHtml(this.groupKey(groupNameHere))}"></span>`
                    : '';
                groupBannerHtml = `<div class="wpo-group-banner" data-group-key="${this.escapeHtml(this.groupKey(groupNameHere))}" data-group-name="${this.escapeHtml(groupNameHere)}">
                    <div class="wpo-group-banner-head">
                        <span class="wpo-group-banner-title">📁 ${this.escapeHtml(gTitle)}</span>
                        ${gTimer}
                    </div>
                    ${gInstruction}
                </div>`;
            }

            // Question shared text / common passage
            let sharedText = q.shared_text;
            if (!sharedText && q.shared_text_id && this.sharedTexts) {
                sharedText = this.sharedTexts[q.shared_text_id];
            }
            let sharedTextHtml = '';
            if (sharedText && (sharedText.content || sharedText.title)) {
                const stId = String(sharedText.id || q.shared_text_id || 'default');
                const isCollapsed = this.collapsedSharedTexts.has(stId);
                sharedTextHtml = `
                    <div class="wpo-shared-passage-box ${isCollapsed ? 'is-collapsed' : ''}" data-st-id="${this.escapeHtml(stId)}">
                        <div class="wpo-shared-passage-header">
                            <div class="wpo-shared-passage-title-wrap">
                                <span class="wpo-shared-passage-icon">📖</span>
                                <span class="wpo-shared-passage-tag">ОБЩИЙ ТЕКСТ К ЗАДАНИЯМ</span>
                                <strong class="wpo-shared-passage-title">${this.escapeHtml(sharedText.title || 'Текст к заданию')}</strong>
                            </div>
                            <button type="button" class="wpo-shared-passage-toggle" title="Свернуть или развернуть общий текст">
                                <span class="wpo-st-toggle-text">${isCollapsed ? 'Развернуть' : 'Свернуть'}</span>
                                <span class="wpo-st-toggle-icon">${isCollapsed ? '▾' : '▴'}</span>
                            </button>
                        </div>
                        <div class="wpo-shared-passage-body" style="${isCollapsed ? 'display:none;' : ''}">
                            <div class="wpo-shared-passage-content typography-body">${sharedText.content}</div>
                        </div>
                    </div>
                `;
            }

            let html = `
                <div class="wpo-question-card-inner" data-id="${q.id}" data-group-key="${this.escapeHtml(this.groupKey(groupNameHere))}">
                    ${groupBannerHtml}
                    ${groupBadge}
                    ${sharedTextHtml}
                    <div class="wpo-question-prompt">${q.title} ${reqStar}</div>
                    <div class="wpo-question-meta-row">
                        ${pointsPill}
                        ${penaltyPill}
                        ${reqPill}
                    </div>
            `;

            if (imgPos === 'above' && imgHtml) {
                html += imgHtml;
            }

            if (q.content) {
                html += `<div class="wpo-question-content" style="margin-bottom:18px;">${q.content}</div>`;
            }

            if (imgPos === 'below' && imgHtml) {
                html += imgHtml;
            }

            // Input Fields
            const savedAns = this.answers[q.id];

            if (q.question_type === 'single' || q.question_type === 'multi') {
                const isMulti = q.question_type === 'multi';
                html += '<div class="wpo-choices-group">';
                (q.choices || []).forEach((c) => {
                    let isChecked = false;
                    if (isMulti) {
                        isChecked = Array.isArray(savedAns) && savedAns.includes(c.id);
                    } else {
                        isChecked = savedAns === c.id;
                    }

                    html += `
                        <label class="wpo-choice-card ${isChecked ? 'selected' : ''}">
                            <input type="${isMulti ? 'checkbox' : 'radio'}"
                                   name="wpo_q_${q.id}"
                                   value="${c.id}"
                                   ${isChecked ? 'checked' : ''}>
                            <span class="wpo-choice-text">${this.escapeHtml(c.text)}</span>
                        </label>
                    `;
                });
                html += '</div>';
            } else if (q.question_type === 'dropdown') {
                html += `
                    <div class="wpo-form-group">
                        <select class="wpo-input wpo-select-dropdown">
                            <option value="">-- Выберите правильный вариант --</option>
                            ${(q.choices || []).map((c) => `
                                <option value="${c.id}" ${savedAns === c.id ? 'selected' : ''}>
                                    ${this.escapeHtml(c.text)}
                                </option>
                            `).join('')}
                        </select>
                    </div>
                `;
            } else if (q.question_type === 'boolean') {
                html += '<div class="wpo-choices-group" style="display:flex; flex-direction:row; gap:16px;">';
                const boolChoices = [
                    { id: 'true', text: '✓ Верно / Да' },
                    { id: 'false', text: '✕ Неверно / Нет' }
                ];
                boolChoices.forEach((c) => {
                    const isChecked = savedAns === c.id;
                    html += `
                        <label class="wpo-choice-card ${isChecked ? 'selected' : ''}" style="flex:1; justify-content:center; text-align:center;">
                            <input type="radio" name="wpo_q_${q.id}" value="${c.id}" ${isChecked ? 'checked' : ''}>
                            <span class="wpo-choice-text" style="font-weight:700;">${this.escapeHtml(c.text)}</span>
                        </label>
                    `;
                });
                html += '</div>';
            } else if (q.question_type === 'number') {
                html += `
                    <div class="wpo-form-group">
                        <input type="number" step="any"
                               class="wpo-input wpo-input-number"
                               value="${savedAns !== undefined ? this.escapeHtml(String(savedAns)) : ''}"
                               placeholder="${q.placeholder || 'Введите число...'}">
                    </div>
                `;
            } else if (q.question_type === 'text') {
                html += `
                    <div class="wpo-form-group">
                        <input type="text"
                               class="wpo-input wpo-input-text"
                               value="${savedAns !== undefined ? this.escapeHtml(String(savedAns)) : ''}"
                               placeholder="${q.placeholder || 'Введите ответ...'}">
                    </div>
                `;
            } else if (q.question_type === 'essay') {
                html += `
                    <div class="wpo-form-group">
                        <textarea class="wpo-textarea"
                                  placeholder="${q.placeholder || 'Введите подробное решение...'}">${savedAns !== undefined ? this.escapeHtml(String(savedAns)) : ''}</textarea>
                    </div>
                `;
            } else if (q.question_type === 'matching') {
                html += '<div class="wpo-matching-group" style="display:flex; flex-direction:column; gap:12px;">';
                const leftItems = q.left_items || [];
                const rightOpts = q.right_options || [];
                const savedObj = (savedAns && typeof savedAns === 'object' && !Array.isArray(savedAns)) ? savedAns : {};

                leftItems.forEach((item) => {
                    const currentChoice = savedObj[item.id] || '';
                    html += `
                        <div class="wpo-matching-item-row" style="display:flex; align-items:center; gap:12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 14px;">
                            <div style="flex:1; font-weight:600; color:#1e293b;">${this.escapeHtml(item.text)}</div>
                            <div style="font-weight:700; color:#64748b;">➔</div>
                            <div style="flex:1;">
                                <select class="wpo-input wpo-matching-select" data-pair-id="${this.escapeHtml(item.id)}">
                                    <option value="">-- Выберите соответствие --</option>
                                    ${rightOpts.map((opt) => `
                                        <option value="${this.escapeHtml(opt)}" ${currentChoice === opt ? 'selected' : ''}>
                                            ${this.escapeHtml(opt)}
                                        </option>
                                    `).join('')}
                                </select>
                            </div>
                        </div>
                    `;
                });
                html += '</div>';
            } else if (q.question_type === 'ordering') {
                html += '<div class="wpo-ordering-group" style="display:flex; flex-direction:column; gap:10px;">';
                let orderedItems = [];
                const originalItems = q.items || [];
                const itemsMap = {};
                originalItems.forEach((it) => { itemsMap[it.id] = it; });

                if (Array.isArray(savedAns) && savedAns.length === originalItems.length) {
                    orderedItems = savedAns.map((id) => itemsMap[id]).filter(Boolean);
                } else {
                    orderedItems = originalItems;
                }

                orderedItems.forEach((it, idx) => {
                    html += `
                        <div class="wpo-ordering-item-row" data-id="${this.escapeHtml(it.id)}" style="display:flex; align-items:center; gap:12px; background:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; padding:10px 14px;">
                            <span class="wpo-order-pos-badge" style="background:#2563eb; color:#fff; font-weight:700; width:28px; height:28px; border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:13px;">${idx + 1}</span>
                            <span class="wpo-order-item-title" style="flex:1; font-weight:600; color:#1e293b;">${this.escapeHtml(it.text)}</span>
                            <div style="display:flex; gap:6px;">
                                <button type="button" class="wpo-btn-order-move wpo-btn-order-up" style="background:#e2e8f0; border:none; border-radius:4px; padding:6px 10px; cursor:pointer; font-weight:700;" title="Вверх">▲</button>
                                <button type="button" class="wpo-btn-order-move wpo-btn-order-down" style="background:#e2e8f0; border:none; border-radius:4px; padding:6px 10px; cursor:pointer; font-weight:700;" title="Вниз">▼</button>
                            </div>
                        </div>
                    `;
                });
                html += '</div>';
            } else if (q.question_type === 'fill_blank') {
                const choiceMode = q.choice_mode || 'input';
                const savedObj = (savedAns && typeof savedAns === 'object' && !Array.isArray(savedAns)) ? savedAns : {};
                let tmpl = this.escapeHtml(q.template || '');

                if (choiceMode === 'dropdown') {
                    const blankOpts = q.blank_options || {};
                    tmpl = tmpl.replace(/\[\[blank:(blank_\d+)\]\]/g, (match, bId) => {
                        const currentVal = savedObj[bId] !== undefined ? String(savedObj[bId]) : '';
                        const opts = Array.isArray(blankOpts[bId]) ? blankOpts[bId] : [];
                        let optionsHtml = '<option value="">-- выберите вариант --</option>';
                        opts.forEach((opt) => {
                            const optStr = String(opt);
                            const isSel = (optStr === currentVal) ? 'selected' : '';
                            optionsHtml += `<option value="${this.escapeHtml(optStr)}" ${isSel}>${this.escapeHtml(optStr)}</option>`;
                        });
                        return `<select class="wpo-blank-inline-select" data-blank-id="${bId}">${optionsHtml}</select>`;
                    });
                } else if (choiceMode === 'word_bank') {
                    tmpl = tmpl.replace(/\[\[blank:(blank_\d+)\]\]/g, (match, bId) => {
                        const currentVal = savedObj[bId] !== undefined ? String(savedObj[bId]).trim() : '';
                        const hasWord = Boolean(currentVal);
                        return `<span class="wpo-blank-slot ${hasWord ? 'has-word' : 'is-empty'}" data-blank-id="${bId}" tabindex="0"><span class="wpo-blank-slot-text">${hasWord ? this.escapeHtml(currentVal) : '___'}</span><button type="button" class="wpo-blank-slot-clear" title="Убрать слово" ${hasWord ? '' : 'style="display:none;"'}>×</button></span>`;
                    });
                } else {
                    tmpl = tmpl.replace(/\[\[blank:(blank_\d+)\]\]/g, (match, bId) => {
                        const currentVal = savedObj[bId] ? this.escapeHtml(savedObj[bId]) : '';
                        return `<input type="text" class="wpo-blank-inline-input" data-blank-id="${bId}" value="${currentVal}" placeholder="введите..." autocomplete="off">`;
                    });
                }

                let bankHtml = '';
                if (choiceMode === 'word_bank') {
                    const bankItems = Array.isArray(q.word_bank) ? q.word_bank : [];
                    bankHtml = `
                        <div class="wpo-blank-bank-panel">
                            <div class="wpo-blank-bank-header">
                                <span class="wpo-blank-bank-title">📦 Банк вариантов (нажмите на вариант для вставки в пропуск):</span>
                                <button type="button" class="wpo-blank-bank-reset-btn">Сбросить все</button>
                            </div>
                            <div class="wpo-blank-bank-chips">
                                ${bankItems.map((item) => {
                                    return `<button type="button" class="wpo-blank-word-chip" data-word-id="${this.escapeHtml(item.id || '')}" data-word="${this.escapeHtml(item.text)}">${this.escapeHtml(item.text)}</button>`;
                                }).join('')}
                            </div>
                        </div>
                    `;
                }

                html += `
                    <div class="wpo-fill-blank-wrapper" data-choice-mode="${choiceMode}">
                        <div class="wpo-fill-blank-container">
                            ${tmpl}
                        </div>
                        ${bankHtml}
                    </div>
                `;
            } else if (q.question_type === 'slider') {
                const min = q.min !== undefined ? q.min : 0;
                const max = q.max !== undefined ? q.max : 100;
                const step = q.step !== undefined ? q.step : 1;
                const currentVal = savedAns !== undefined ? savedAns : (q.default_value !== undefined ? q.default_value : min);

                html += `
                    <div class="wpo-slider-container" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:20px;">
                        <div style="text-align:center; margin-bottom:12px;">
                            <span style="font-size:13px; color:#64748b; font-weight:600;">Выбранное значение:</span>
                            <div class="wpo-slider-badge" style="font-size:28px; font-weight:800; color:#2563eb;">${currentVal}</div>
                        </div>
                        <div style="display:flex; align-items:center; gap:16px;">
                            <span style="font-weight:700; color:#64748b; min-width:40px;">${min}</span>
                            <input type="range" class="wpo-slider-range-input" min="${min}" max="${max}" step="${step}" value="${currentVal}" style="flex:1; cursor:pointer;">
                            <span style="font-weight:700; color:#64748b; min-width:40px; text-align:right;">${max}</span>
                        </div>
                    </div>
                `;
            } else if (q.question_type === 'word_scramble') {
                const tiles = q.tiles || [];
                const wordLen = q.word_length || tiles.length;

                html += `
                    <div class="wpo-scramble-widget" data-qid="${q.id}">
                        <div class="wpo-scramble-slots-container" style="margin-bottom:20px; text-align:center;">
                            <div style="font-size:13px; color:#64748b; font-weight:600; margin-bottom:10px;">
                                Собранное слово (нажимайте на буквы или вводите с клавиатуры):
                            </div>
                            <div class="wpo-scramble-slots">
                                <!-- Slots populated dynamically -->
                            </div>
                        </div>

                        <div class="wpo-scramble-bank-container" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:18px; margin-bottom:14px; text-align:center;">
                            <div style="font-size:13px; color:#64748b; font-weight:600; margin-bottom:12px;">
                                Доступные буквы:
                            </div>
                            <div class="wpo-scramble-bank">
                                ${tiles.map((t) => `
                                    <button type="button" class="wpo-letter-chip" data-tile-id="${t.id}" data-char="${this.escapeHtml(t.char)}">
                                        ${this.escapeHtml(t.char)}
                                    </button>
                                `).join('')}
                            </div>
                        </div>

                        <div style="display:flex; justify-content:center; gap:12px;">
                            <button type="button" class="wpo-btn-scramble-backspace wpo-btn-secondary" style="padding:8px 16px; border-radius:6px; cursor:pointer; font-weight:600;">
                                ⌫ Стереть букву
                            </button>
                            <button type="button" class="wpo-btn-scramble-reset wpo-btn-secondary" style="padding:8px 16px; border-radius:6px; cursor:pointer; font-weight:600;">
                                ↺ Сбросить всё
                            </button>
                        </div>
                    </div>
                `;
            } else if (q.question_type === 'dictation') {
                const savedObj = (savedAns && typeof savedAns === 'object' && !Array.isArray(savedAns)) ? savedAns : {};
                let tmpl = this.escapeHtml(q.template || '');
                tmpl = tmpl.replace(/\[\[dict:(d_\d+)\]\]/g, (match, dId) => {
                    const gapInfo = (q.dict_gaps && q.dict_gaps[dId]) ? q.dict_gaps[dId] : null;
                    const opts = gapInfo ? gapInfo.options : [];
                    const currentVal = savedObj[dId] ? this.escapeHtml(savedObj[dId]) : '';
                    return `
                        <select class="wpo-dict-inline-select" data-dict-id="${dId}">
                            <option value="">...</option>
                            ${opts.map((opt) => `
                                <option value="${this.escapeHtml(opt)}" ${currentVal === opt ? 'selected' : ''}>
                                    ${this.escapeHtml(opt)}
                                </option>
                            `).join('')}
                        </select>
                    `;
                });

                html += `
                    <div class="wpo-dictation-widget" data-qid="${q.id}">
                        ${q.audio_url ? `
                            <div class="wpo-dictation-audio-wrap" style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:12px 16px; margin-bottom:16px; display:flex; align-items:center; gap:12px;">
                                <span style="font-size:22px;">🎧</span>
                                <div style="flex:1;">
                                    <div style="font-size:12px; font-weight:700; color:#1e40af; margin-bottom:4px;">Аудиозапись диктанта (нажмите Play для прослушивания текста):</div>
                                    <audio controls src="${this.escapeHtml(q.audio_url)}" style="width:100%; height:36px;"></audio>
                                </div>
                            </div>
                        ` : ''}

                        <div class="wpo-dictation-container" style="font-size:17px; line-height:2.4; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:20px; color:#1e293b;">
                            ${tmpl}
                        </div>
                    </div>
                `;
            }

            html += '</div>';
            container.innerHTML = html;

            this.bindQuestionInputs(q, container);
            this.applyGroupLock(container);
            this.updateGroupTimers();
            this.updateNavState();
        }

        /** Задания группы с истёкшим временем становятся неактивными. */
        applyGroupLock(scope) {
            const root = scope || this.qContainer;
            if (!root) return;
            const expired = this.expiredGroups || {};
            root.querySelectorAll('.wpo-question-card-inner').forEach((card) => {
                const key = card.getAttribute('data-group-key') || '';
                if (!key || !expired[key]) return;
                card.classList.add('is-group-expired');
                card.querySelectorAll('input, select, textarea, button').forEach((el) => {
                    el.disabled = true;
                });
            });
        }

        initGroupTimers() {
            if (this.groupTimerInterval) return;
            if (!this.questionGroups.length) return;
            this.groupTimerInterval = setInterval(() => this.updateGroupTimers(), 1000);
        }

        stopGroupTimers() {
            if (this.groupTimerInterval) {
                clearInterval(this.groupTimerInterval);
                this.groupTimerInterval = null;
            }
        }

        updateGroupTimers() {
            if (!this.qContainer || !this.questionGroups.length) return;
            const now = Date.now();
            const that = this;
            this.qContainer.querySelectorAll('.wpo-group-banner').forEach((banner) => {
                const key = banner.getAttribute('data-group-key') || '';
                const def = that.groupDefs[key];
                if (!def) return;
                const limit = (parseInt(def.time_limit, 10) || 0) * 60;
                if (limit <= 0) return;

                if (!that.groupOpened[key]) {
                    that.groupOpened[key] = now;
                    that.apiPost('/heartbeat', { token: that.token, group: def.name }, 'wp_olympiad_heartbeat').catch(() => {});
                }

                const spent = Math.floor((now - that.groupOpened[key]) / 1000);
                const left = limit - spent;
                const out = banner.querySelector('[data-group-timer]');
                if (out) {
                    if (left > 0) {
                        const m = Math.floor(left / 60);
                        const sec = left % 60;
                        out.textContent = `⏳ Осталось ${String(m).padStart(2, '0')}:${String(sec).padStart(2, '0')}`;
                        out.className = left <= 60 ? 'wpo-group-timer danger' : 'wpo-group-timer';
                    } else {
                        out.textContent = '⛔ Время группы вышло';
                        out.className = 'wpo-group-timer danger';
                    }
                }

                if (left <= 0 && !that.expiredGroups[key]) {
                    that.expiredGroups[key] = true;
                    banner.classList.add('is-expired');
                    that.applyGroupLock(that.qContainer);
                    const msg = `Время на группу «${def.title || def.name}» вышло. Ответы этой группы сохранятся, но не пойдут в зачёт — их проверит организатор.`;
                    if (typeof that.showAntiCheatToast === 'function') {
                        that.showAntiCheatToast('⛔', 'Время группы вышло', msg);
                    } else {
                        alert(msg);
                    }
                }
            });
        }

        bindQuestionInputs(q, root) {
            const inner = (root || this.qContainer).querySelector('.wpo-question-card-inner');
            if (!inner) return;

            // Image zoom lightbox handler
            inner.querySelectorAll('.wpo-question-image, .wpo-question-content img').forEach((img) => {
                img.style.cursor = 'zoom-in';
                img.addEventListener('click', () => {
                    this.showImageLightbox(img.src, img.alt);
                });
            });

            if (q.question_type === 'single' || q.question_type === 'boolean') {
                const radios = inner.querySelectorAll('input[type="radio"]');
                radios.forEach((r) => {
                    r.addEventListener('change', () => {
                        this.answers[q.id] = r.value;
                        inner.querySelectorAll('.wpo-choice-card').forEach((card) => card.classList.remove('selected'));
                        r.closest('.wpo-choice-card').classList.add('selected');
                        this.updateNavState();
                    });
                });
            } else if (q.question_type === 'dropdown') {
                const select = inner.querySelector('.wpo-select-dropdown');
                if (select) {
                    select.addEventListener('change', () => {
                        this.answers[q.id] = select.value;
                        this.updateNavState();
                    });
                }
            } else if (q.question_type === 'multi') {
                const checks = inner.querySelectorAll('input[type="checkbox"]');
                checks.forEach((c) => {
                    c.addEventListener('change', () => {
                        const selected = Array.from(checks).filter((i) => i.checked).map((i) => i.value);
                        this.answers[q.id] = selected;
                        c.closest('.wpo-choice-card').classList.toggle('selected', c.checked);
                        this.updateNavState();
                    });
                });
            } else if (q.question_type === 'number') {
                const numInput = inner.querySelector('.wpo-input-number');
                numInput.addEventListener('input', () => {
                    this.answers[q.id] = numInput.value.trim();
                    this.updateNavState();
                });
            } else if (q.question_type === 'text') {
                const txtInput = inner.querySelector('.wpo-input-text');
                txtInput.addEventListener('input', () => {
                    this.answers[q.id] = txtInput.value.trim();
                    this.updateNavState();
                });
            } else if (q.question_type === 'essay') {
                const area = inner.querySelector('.wpo-textarea');
                area.addEventListener('input', () => {
                    this.answers[q.id] = area.value.trim();
                    this.updateNavState();
                });
            } else if (q.question_type === 'matching') {
                const selects = inner.querySelectorAll('.wpo-matching-select');
                selects.forEach((sel) => {
                    sel.addEventListener('change', () => {
                        const pid = sel.dataset.pairId;
                        if (!this.answers[q.id] || typeof this.answers[q.id] !== 'object' || Array.isArray(this.answers[q.id])) {
                            this.answers[q.id] = {};
                        }
                        this.answers[q.id][pid] = sel.value;
                        this.updateNavState();
                    });
                });
            } else if (q.question_type === 'ordering') {
                const group = inner.querySelector('.wpo-ordering-group');
                if (group) {
                    const updateOrderAnswer = () => {
                        const rows = Array.from(group.querySelectorAll('.wpo-ordering-item-row'));
                        const newOrder = rows.map((r) => r.dataset.id);
                        this.answers[q.id] = newOrder;
                        rows.forEach((r, idx) => {
                            const badge = r.querySelector('.wpo-order-pos-badge');
                            if (badge) badge.textContent = idx + 1;
                        });
                        this.updateNavState();
                    };

                    if (!this.answers[q.id] || !Array.isArray(this.answers[q.id])) {
                        updateOrderAnswer();
                    }

                    group.addEventListener('click', (e) => {
                        const btnUp = e.target.closest('.wpo-btn-order-up');
                        const btnDown = e.target.closest('.wpo-btn-order-down');
                        if (!btnUp && !btnDown) return;

                        const row = e.target.closest('.wpo-ordering-item-row');
                        if (!row) return;

                        if (btnUp && row.previousElementSibling) {
                            group.insertBefore(row, row.previousElementSibling);
                            updateOrderAnswer();
                        } else if (btnDown && row.nextElementSibling) {
                            group.insertBefore(row.nextElementSibling, row);
                            updateOrderAnswer();
                        }
                    });
                }
            } else if (q.question_type === 'fill_blank') {
                const choiceMode = q.choice_mode || 'input';

                if (!this.answers[q.id] || typeof this.answers[q.id] !== 'object' || Array.isArray(this.answers[q.id])) {
                    this.answers[q.id] = {};
                }

                if (choiceMode === 'dropdown') {
                    const selects = inner.querySelectorAll('.wpo-blank-inline-select');
                    selects.forEach((sel) => {
                        sel.addEventListener('change', () => {
                            const bid = sel.dataset.blankId;
                            this.answers[q.id][bid] = sel.value;
                            this.updateNavState();
                        });
                    });
                } else if (choiceMode === 'word_bank') {
                    const wrapper = inner.querySelector('.wpo-fill-blank-wrapper');
                    if (!wrapper) return;

                    const slots = Array.from(wrapper.querySelectorAll('.wpo-blank-slot'));
                    const chips = Array.from(wrapper.querySelectorAll('.wpo-blank-word-chip'));
                    const resetBtn = wrapper.querySelector('.wpo-blank-bank-reset-btn');

                    let activeSlot = null;
                    let activeChip = null;

                    const syncChipStates = () => {
                        const currentAns = this.answers[q.id] || {};
                        const usedCounts = {};
                        Object.values(currentAns).forEach((val) => {
                            const v = String(val || '').trim();
                            if (v) {
                                usedCounts[v] = (usedCounts[v] || 0) + 1;
                            }
                        });

                        const tempCounts = Object.assign({}, usedCounts);
                        chips.forEach((chip) => {
                            const word = chip.dataset.word || '';
                            if (tempCounts[word] && tempCounts[word] > 0) {
                                chip.classList.add('is-used');
                                tempCounts[word]--;
                            } else {
                                chip.classList.remove('is-used');
                            }
                        });
                    };

                    const clearSlot = (slot) => {
                        const bid = slot.dataset.blankId;
                        delete this.answers[q.id][bid];
                        const textSpan = slot.querySelector('.wpo-blank-slot-text');
                        const clearBtn = slot.querySelector('.wpo-blank-slot-clear');
                        if (textSpan) textSpan.textContent = '___';
                        if (clearBtn) clearBtn.style.display = 'none';
                        slot.classList.remove('has-word', 'is-active');
                        slot.classList.add('is-empty');
                        syncChipStates();
                        this.updateNavState();
                    };

                    const fillSlot = (slot, word) => {
                        const bid = slot.dataset.blankId;
                        this.answers[q.id][bid] = word;
                        const textSpan = slot.querySelector('.wpo-blank-slot-text');
                        const clearBtn = slot.querySelector('.wpo-blank-slot-clear');
                        if (textSpan) textSpan.textContent = word;
                        if (clearBtn) clearBtn.style.display = 'inline-flex';
                        slot.classList.add('has-word');
                        slot.classList.remove('is-empty', 'is-active');
                        activeSlot = null;
                        if (activeChip) {
                            activeChip.classList.remove('is-active');
                            activeChip = null;
                        }
                        syncChipStates();
                        this.updateNavState();
                    };

                    slots.forEach((slot) => {
                        slot.addEventListener('click', (e) => {
                            const clearBtn = e.target.closest('.wpo-blank-slot-clear');
                            if (clearBtn || slot.classList.contains('has-word')) {
                                e.stopPropagation();
                                clearSlot(slot);
                                return;
                            }

                            if (activeChip && !activeChip.classList.contains('is-used')) {
                                fillSlot(slot, activeChip.dataset.word || '');
                                return;
                            }

                            if (activeSlot === slot) {
                                slot.classList.remove('is-active');
                                activeSlot = null;
                            } else {
                                slots.forEach((s) => s.classList.remove('is-active'));
                                slot.classList.add('is-active');
                                activeSlot = slot;
                            }
                        });
                    });

                    chips.forEach((chip) => {
                        chip.addEventListener('click', () => {
                            if (chip.classList.contains('is-used')) return;

                            const word = chip.dataset.word || '';

                            if (activeSlot && !activeSlot.classList.contains('has-word')) {
                                fillSlot(activeSlot, word);
                                return;
                            }

                            const firstEmpty = slots.find((s) => s.classList.contains('is-empty'));
                            if (firstEmpty) {
                                fillSlot(firstEmpty, word);
                                return;
                            }

                            if (activeChip === chip) {
                                chip.classList.remove('is-active');
                                activeChip = null;
                            } else {
                                chips.forEach((c) => c.classList.remove('is-active'));
                                chip.classList.add('is-active');
                                activeChip = chip;
                            }
                        });
                    });

                    if (resetBtn) {
                        resetBtn.addEventListener('click', () => {
                            this.answers[q.id] = {};
                            slots.forEach((slot) => {
                                const textSpan = slot.querySelector('.wpo-blank-slot-text');
                                const clearBtn = slot.querySelector('.wpo-blank-slot-clear');
                                if (textSpan) textSpan.textContent = '___';
                                if (clearBtn) clearBtn.style.display = 'none';
                                slot.classList.remove('has-word', 'is-active');
                                slot.classList.add('is-empty');
                            });
                            chips.forEach((c) => c.classList.remove('is-used', 'is-active'));
                            activeSlot = null;
                            activeChip = null;
                            this.updateNavState();
                        });
                    }

                    syncChipStates();
                } else {
                    const inputs = inner.querySelectorAll('.wpo-blank-inline-input');
                    inputs.forEach((inp) => {
                        inp.addEventListener('input', () => {
                            const bid = inp.dataset.blankId;
                            this.answers[q.id][bid] = inp.value.trim();
                            this.updateNavState();
                        });
                    });
                }
            } else if (q.question_type === 'slider') {
                const slider = inner.querySelector('.wpo-slider-range-input');
                const badge = inner.querySelector('.wpo-slider-badge');
                if (slider) {
                    slider.addEventListener('input', () => {
                        if (badge) badge.textContent = slider.value;
                        this.answers[q.id] = parseFloat(slider.value);
                        this.updateNavState();
                    });
                    if (this.answers[q.id] === undefined) {
                        this.answers[q.id] = parseFloat(slider.value);
                    }
                }
            } else if (q.question_type === 'word_scramble') {
                const widget = inner.querySelector('.wpo-scramble-widget');
                if (!widget) return;

                const tiles = q.tiles || [];
                const wordLen = q.word_length || tiles.length;
                const slotsContainer = widget.querySelector('.wpo-scramble-slots');
                const bankContainer = widget.querySelector('.wpo-scramble-bank');
                const btnBackspace = widget.querySelector('.wpo-btn-scramble-backspace');
                const btnReset = widget.querySelector('.wpo-btn-scramble-reset');

                let placedTiles = [];

                // Restore prior answer if exists
                const existingAns = this.answers[q.id];
                if (existingAns && typeof existingAns === 'string') {
                    const neededChars = existingAns.split('');
                    const usedTileIds = new Set();
                    neededChars.forEach((ch) => {
                        const tile = tiles.find((t) => t.char.toUpperCase() === ch.toUpperCase() && !usedTileIds.has(t.id));
                        if (tile) {
                            usedTileIds.add(tile.id);
                            placedTiles.push(tile);
                        }
                    });
                }

                const renderState = () => {
                    let slotsHtml = '';
                    const totalSlots = Math.max(wordLen, placedTiles.length);
                    for (let i = 0; i < totalSlots; i++) {
                        const tile = placedTiles[i];
                        if (tile) {
                            slotsHtml += `
                                <div class="wpo-scramble-slot filled" data-idx="${i}" data-tile-id="${tile.id}" title="Нажмите, чтобы убрать букву">
                                    <span class="wpo-slot-char">${this.escapeHtml(tile.char)}</span>
                                </div>
                            `;
                        } else {
                            slotsHtml += `
                                <div class="wpo-scramble-slot empty" data-idx="${i}">
                                    <span class="wpo-slot-placeholder">_</span>
                                </div>
                            `;
                        }
                    }
                    slotsContainer.innerHTML = slotsHtml;

                    const usedSet = new Set(placedTiles.map((t) => t.id));
                    bankContainer.querySelectorAll('.wpo-letter-chip').forEach((chip) => {
                        const tid = chip.dataset.tileId;
                        if (usedSet.has(tid)) {
                            chip.classList.add('used');
                            chip.setAttribute('disabled', 'disabled');
                        } else {
                            chip.classList.remove('used');
                            chip.removeAttribute('disabled');
                        }
                    });

                    const assembledWord = placedTiles.map((t) => t.char).join('');
                    this.answers[q.id] = assembledWord;
                    this.updateNavState();
                };

                const placeTile = (tile) => {
                    placedTiles.push(tile);
                    renderState();
                };

                const removeTileAt = (idx) => {
                    if (idx >= 0 && idx < placedTiles.length) {
                        placedTiles.splice(idx, 1);
                        renderState();
                    }
                };

                bankContainer.addEventListener('click', (e) => {
                    const chip = e.target.closest('.wpo-letter-chip');
                    if (chip && !chip.classList.contains('used')) {
                        const tid = chip.dataset.tileId;
                        const tile = tiles.find((t) => t.id === tid);
                        if (tile) {
                            placeTile(tile);
                        }
                    }
                });

                slotsContainer.addEventListener('click', (e) => {
                    const slot = e.target.closest('.wpo-scramble-slot.filled');
                    if (slot) {
                        const idx = parseInt(slot.dataset.idx, 10);
                        removeTileAt(idx);
                    }
                });

                if (btnBackspace) {
                    btnBackspace.addEventListener('click', () => {
                        if (placedTiles.length > 0) {
                            removeTileAt(placedTiles.length - 1);
                        }
                    });
                }

                if (btnReset) {
                    btnReset.addEventListener('click', () => {
                        placedTiles = [];
                        renderState();
                    });
                }

                const handleKeyDown = (e) => {
                    if (e.target && (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA')) {
                        return;
                    }
                    if (e.key === 'Backspace') {
                        if (placedTiles.length > 0) {
                            removeTileAt(placedTiles.length - 1);
                            e.preventDefault();
                        }
                        return;
                    }
                    if (e.key && e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
                        const pressed = e.key.toUpperCase();
                        const usedSet = new Set(placedTiles.map((t) => t.id));
                        const available = tiles.find((t) => t.char.toUpperCase() === pressed && !usedSet.has(t.id));
                        if (available) {
                            placeTile(available);
                            e.preventDefault();
                        }
                    }
                };

                if (this._scrambleKeyHandler) {
                    window.removeEventListener('keydown', this._scrambleKeyHandler);
                }
                this._scrambleKeyHandler = handleKeyDown;
                window.addEventListener('keydown', handleKeyDown);

                renderState();
            } else if (q.question_type === 'dictation') {
                const widget = inner.querySelector('.wpo-dictation-widget');
                if (!widget) return;
                const selects = widget.querySelectorAll('.wpo-dict-inline-select');
                selects.forEach((sel) => {
                    sel.addEventListener('change', () => {
                        const did = sel.dataset.dictId;
                        if (!this.answers[q.id] || typeof this.answers[q.id] !== 'object' || Array.isArray(this.answers[q.id])) {
                            this.answers[q.id] = {};
                        }
                        this.answers[q.id][did] = sel.value;
                        this.updateNavState();
                    });
                });
            }
        }

        confirmSubmit() {
            // First check for missing REQUIRED questions!
            const unansweredReq = [];
            this.questions.forEach((q, idx) => {
                if (q.is_required && !this.isQuestionAnswered(q.id)) {
                    unansweredReq.push({ q, index: idx, num: idx + 1 });
                }
            });

            if (unansweredReq.length > 0) {
                const numsStr = unansweredReq.map((item) => '#' + item.num).join(', ');
                alert(`Невозможно завершить тестирование!\n\nВы не ответили на обязательные задания: ${numsStr}.\nПожалуйста, дайте ответ на все обязательные вопросы перед отправкой.`);
                this.navigate(unansweredReq[0].index);
                return;
            }

            const unanswered = this.questions.filter((q) => !this.isQuestionAnswered(q.id)).length;
            let msg = 'Вы уверены, что хотите завершить олимпиаду и отправить работу на проверку?';
            if (unanswered > 0) {
                msg = `Внимание! У вас осталось неотвеченных заданий: ${unanswered}.\nВы действительно хотите завершить работу?`;
            }

            if (confirm(msg)) {
                this.submitAnswers(false);
            }
        }

        async submitAnswers(forced) {
            if (this.isSubmitting) return;

            // Enforce required questions check unless forced by timer expiration
            if (!forced) {
                const unansweredReq = this.questions.filter((q) => q.is_required && !this.isQuestionAnswered(q.id));
                if (unansweredReq.length > 0) {
                    const firstIdx = this.questions.indexOf(unansweredReq[0]);
                    alert('Пожалуйста, ответьте на все обязательные вопросы перед отправкой!');
                    if (firstIdx !== -1) this.navigate(firstIdx);
                    return;
                }
            }

            this.isSubmitting = true;
            const finishLabel = this.btnFinish ? this.btnFinish.textContent : '';

            this.pauseSubmitTimers();

            if (this.btnFinish) {
                this.btnFinish.disabled = true;
                this.btnFinish.textContent = 'Отправка...';
            }

            try {
                const res = await this.apiPost('/submit', {
                    token: this.token,
                    answers: this.answers,
                }, 'wp_olympiad_submit');

                if (!res.success) {
                    alert(res.message || (res.data && res.data.message) || 'Ошибка при отправке ответов.');
                    this.afterFailedSubmit(finishLabel);
                    return;
                }

                const data = res.data || res;
                this.renderResults(data);
            } catch (err) {
                console.error(err);
                alert('Сетевая ошибка при отправке результатов. Проверьте соединение и нажмите «Завершить» ещё раз — ответы сохранены на этой странице.');
                this.afterFailedSubmit(finishLabel);
            }
        }

        // Отправка не должна быть точкой невозврата: если сервер не принял работу
        // (сеть моргнула, страница устарела), участник обязан иметь возможность
        // отправить её повторно, не потеряв ответы.
        pauseSubmitTimers() {
            if (this.timerInterval) {
                clearInterval(this.timerInterval);
                this.timerInterval = null;
            }
            this.stopGroupTimers();
            if (this.heartbeatInterval) {
                clearInterval(this.heartbeatInterval);
                this.heartbeatInterval = null;
            }
        }

        afterFailedSubmit(finishLabel) {
            this.isSubmitting = false;

            if (this.btnFinish) {
                this.btnFinish.disabled = false;
                this.btnFinish.textContent = finishLabel || 'Завершить';
            }

            this.resumeSubmitTimers();
        }

        resumeSubmitTimers() {
            this.initGroupTimers();
            if (!this.timerInterval && this.timeLimit > 0 && this.remainingSeconds > 0) {
                this.updateTimerDisplay();
                this.timerInterval = setInterval(() => {
                    this.remainingSeconds--;
                    this.updateTimerDisplay();

                    if (this.remainingSeconds <= 0) {
                        clearInterval(this.timerInterval);
                        this.timerInterval = null;
                        alert(config.i18n ? config.i18n.timeExpired : 'Время вышло! Ваши ответы автоматически отправляются...');
                        this.submitAnswers(true);
                    }
                }, 1000);
            }

            if (!this.heartbeatInterval) {
                this.heartbeatInterval = setInterval(() => {
                    this.sendHeartbeat(false);
                }, 30000);
            }
        }

        renderResults(data) {
            this.runnerScreen.style.display = 'none';
            this.resultsScreen.style.display = 'block';

            // Complete disabling of results at the end
            if (data.hide_results || data.mode === 'none') {
                const message = data.message || 'Спасибо за участие! Ваши ответы успешно приняты и сохранены в системе.';
                this.resultsScreen.innerHTML = `
                    <div class="wpo-result-summary" style="padding:40px 20px; text-align:center;">
                        <div class="wpo-result-icon" style="font-size:64px; margin-bottom:16px;">✅</div>
                        <h2 style="margin-bottom:12px; color:#1e293b;">Тестирование завершено</h2>
                        <div style="font-size:16px; color:#475569; max-width:580px; margin:0 auto 24px auto; line-height:1.6;">
                            ${this.escapeHtml(message)}
                        </div>
                        <div style="margin-top:24px;">
                            <button type="button" class="wpo-btn wpo-btn-outline" onclick="location.reload();">
                                🔄 Вернуться на главную страницу теста
                            </button>
                        </div>
                    </div>
                `;
                return;
            }

            const icon = '📋';
            const m = Math.floor((data.duration_seconds || 0) / 60);
            const s = (data.duration_seconds || 0) % 60;
            const timeStr = `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;

            let html = `
                <div class="wpo-result-summary">
                    <div class="wpo-result-icon">${icon}</div>
                    <h2>Тестирование завершено</h2>

                    <div class="wpo-score-badge completed">
                        ${Math.round(data.score)} / ${Math.round(data.max_score)} (${Math.round(data.percentage)}%)
                    </div>


                    <div class="wpo-result-stats-grid">
                        <div class="wpo-stat-card">
                            <div class="val">${timeStr}</div>
                            <div class="lbl">Время решения</div>
                        </div>
                        <div class="wpo-stat-card">
                            <div class="val">${data.tab_switches || 0}</div>
                            <div class="lbl">Переключений вкладок</div>
                        </div>
                        <div class="wpo-stat-card">
                            <div class="val">${data.status === 'needs_review' ? 'На проверке' : 'Завершено'}</div>
                            <div class="lbl">Итоговый статус</div>
                        </div>
                    </div>
            `;

            if (data.message) {
                html += `<div class="wpo-alert wpo-alert-warning" style="margin-top:20px;">${this.escapeHtml(data.message)}</div>`;
            }

            // Question breakdown if immediate mode
            if (data.breakdown && Array.isArray(data.breakdown)) {
                html += `<div class="wpo-breakdown-list"><h3>Разбор заданий:</h3>`;
                data.breakdown.forEach((item, idx) => {
                    const isCorrect = item.is_correct === 1;
                    const isManual = item.is_correct === null;
                    const statusClass = isManual ? '' : (isCorrect ? 'correct' : 'incorrect');

                    html += `
                        <div class="wpo-breakdown-item ${statusClass}">
                            <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                                <strong>#${idx + 1}. ${this.escapeHtml(item.title)}</strong>
                                <span class="wpo-badge ${isCorrect ? 'wpo-badge-pass' : 'wpo-badge-fail'}">
                                    ${Math.round(item.awarded)} / ${Math.round(item.points)} б.
                                </span>
                            </div>
                            <div style="font-size:14px; margin-bottom:6px;">
                                <strong>Ваш ответ:</strong> ${this.escapeHtml(item.user_answer || '—')}
                            </div>
                            ${item.explanation ? `<div class="wpo-explanation-box">💡 ${this.escapeHtml(item.explanation)}</div>` : ''}
                        </div>
                    `;
                });
                html += '</div>';
            }

            html += `
                <div style="margin-top:30px; display:flex; gap:12px; justify-content:center; flex-wrap:wrap;">
                    <button type="button" class="wpo-btn wpo-btn-outline" onclick="location.reload();">
                        🔄 Вернуться на главную страницу теста
                    </button>
                </div>
            </div>`;

            this.resultsScreen.innerHTML = html;
        }

        showAntiCheatToast(icon, title, message) {
            // Prevent toast spam
            const toastKey = `${icon}_${title}`;
            const now = Date.now();
            if (this.lastToastTimes[toastKey] && now - this.lastToastTimes[toastKey] < 1500) {
                return;
            }
            this.lastToastTimes[toastKey] = now;

            let container = document.querySelector('.wpo-anticheat-toasts-container');
            if (!container) {
                container = document.createElement('div');
                container.className = 'wpo-anticheat-toasts-container';
                document.body.appendChild(container);
            }

            const toast = document.createElement('div');
            toast.className = 'wpo-anticheat-toast';
            toast.innerHTML = `
                <div class="wpo-toast-icon">${icon}</div>
                <div class="wpo-toast-body">
                    <div class="wpo-toast-title">${this.escapeHtml(title)}</div>
                    <div class="wpo-toast-msg">${this.escapeHtml(message)}</div>
                </div>
                <button type="button" class="wpo-toast-close" title="Закрыть">&times;</button>
            `;

            const removeToast = () => {
                toast.classList.add('is-hiding');
                setTimeout(() => toast.remove(), 250);
            };

            toast.querySelector('.wpo-toast-close').addEventListener('click', removeToast);
            container.appendChild(toast);

            setTimeout(removeToast, 3500);
        }

        showModalWarning(message, switchCount, maxSwitches) {
            // Remove any existing warning overlay
            const existing = document.querySelector('.wpo-anticheat-modal-overlay');
            if (existing) existing.remove();

            const overlay = document.createElement('div');
            overlay.className = 'wpo-modal-overlay wpo-anticheat-modal-overlay';
            const remaining = (maxSwitches && maxSwitches > 0) ? Math.max(0, maxSwitches - switchCount) : null;

            overlay.innerHTML = `
                <div class="wpo-modal-card wpo-anticheat-modal-card">
                    <div class="wpo-anticheat-badge-wrap">
                        <span class="wpo-anticheat-pill">🛡️ СИСТЕМА ПРОКТОРИНГА</span>
                    </div>
                    <div class="wpo-anticheat-modal-icon">⚠️</div>
                    <h3 style="color:#b45309; margin:10px 0 8px; font-size:18px;">Зафиксирована потеря фокуса окна!</h3>
                    <p style="font-size:14px; line-height:1.5; color:#334155; margin:0 0 16px;">${this.escapeHtml(message)}</p>

                    <div class="wpo-anticheat-stats-bar">
                        <div class="wpo-ac-stat-item">
                            <span class="wpo-ac-stat-label">Нарушений:</span>
                            <strong class="wpo-ac-stat-val">${switchCount || 1}</strong>
                        </div>
                        ${maxSwitches > 0 ? `
                        <div class="wpo-ac-stat-item">
                            <span class="wpo-ac-stat-label">Лимит:</span>
                            <strong class="wpo-ac-stat-val">${maxSwitches}</strong>
                        </div>
                        <div class="wpo-ac-stat-item ${remaining <= 1 ? 'is-danger' : ''}">
                            <span class="wpo-ac-stat-label">Осталось:</span>
                            <strong class="wpo-ac-stat-val">${remaining}</strong>
                        </div>
                        ` : ''}
                    </div>

                    <p style="font-size:12px; color:#64748b; margin:12px 0 16px;">
                        Пожалуйста, оставайтесь на вкладке олимпиады. Все переключения записываются в протокол.
                    </p>

                    <button type="button" class="wpo-btn wpo-btn-primary wpo-btn-resume" style="padding:9px 24px; width:100%; font-weight:700;">
                        Я понимаю, вернуться к тестированию
                    </button>
                </div>
            `;

            overlay.querySelector('.wpo-btn-resume').addEventListener('click', () => {
                overlay.remove();
            });
            document.body.appendChild(overlay);
        }

        showDisqualificationModal(message) {
            const existing = document.querySelector('.wpo-anticheat-modal-overlay');
            if (existing) existing.remove();

            const overlay = document.createElement('div');
            overlay.className = 'wpo-modal-overlay wpo-anticheat-modal-overlay is-disqualified';
            overlay.innerHTML = `
                <div class="wpo-modal-card wpo-anticheat-modal-card">
                    <div class="wpo-anticheat-badge-wrap">
                        <span class="wpo-anticheat-pill is-danger">⛔ ДИСКВАЛИФИКАЦИЯ</span>
                    </div>
                    <div class="wpo-anticheat-modal-icon" style="color:#dc2626;">🚫</div>
                    <h3 style="color:#b91c1c; margin:10px 0 8px; font-size:18px;">Превышен лимит переключений вкладок</h3>
                    <p style="font-size:14px; line-height:1.5; color:#334155; margin:0 0 16px;">
                        ${this.escapeHtml(message)}
                    </p>
                    <button type="button" class="wpo-btn wpo-btn-primary wpo-btn-disq-close" style="padding:9px 24px; width:100%; background:#dc2626; border-color:#b91c1c;">
                        Перейти к результатам
                    </button>
                </div>
            `;

            overlay.querySelector('.wpo-btn-disq-close').addEventListener('click', () => {
                overlay.remove();
            });
            document.body.appendChild(overlay);
        }

        async apiPost(endpoint, data, ajaxAction) {
            // First attempt: WP REST API
            if (config.restUrl) {
                try {
                    const headers = {
                        'Content-Type': 'application/json'
                    };
                    if (config.restNonce) {
                        headers['X-WP-Nonce'] = config.restNonce;
                    }
                    const res = await fetch(`${config.restUrl}${endpoint}`, {
                        method: 'POST',
                        headers: headers,
                        body: JSON.stringify(data)
                    });
                    const json = await res.json();
                    if (res.ok) {
                        return json;
                    } else if (res.status === 400 || res.status === 403 || res.status === 404) {
                        return {
                            success: false,
                            message: json.message || (json.data && json.data.message) || 'Ошибка валидации данных.'
                        };
                    }
                } catch (e) {
                    // Fallback to AJAX
                }
            }

            // Fallback: Admin AJAX
            const formData = new URLSearchParams();
            formData.append('action', ajaxAction);
            formData.append('nonce', config.nonce);

            for (const key in data) {
                if (typeof data[key] === 'object') {
                    formData.append(key, JSON.stringify(data[key]));
                } else {
                    formData.append(key, data[key]);
                }
            }

            try {
                const res = await fetch(config.ajaxUrl, {
                    method: 'POST',
                    body: formData
                });
                const json = await res.json();
                if (json.success) {
                    return json;
                } else {
                    return {
                        success: false,
                        message: (json.data && json.data.message) || json.message || 'Ошибка выполнения операции.'
                    };
                }
            } catch (err) {
                return {
                    success: false,
                    message: 'Сетевая ошибка при обращении к серверу.'
                };
            }
        }

        escapeHtml(str) {
            if (str === null || str === undefined) return '';
            const div = document.createElement('div');
            div.textContent = String(str);
            return div.innerHTML;
        }

        showImageLightbox(src, alt) {
            let overlay = document.querySelector('.wpo-lightbox-overlay');
            if (!overlay) {
                overlay = document.createElement('div');
                overlay.className = 'wpo-lightbox-overlay';
                overlay.innerHTML = `
                    <div class="wpo-lightbox-backdrop"></div>
                    <div class="wpo-lightbox-content">
                        <img class="wpo-lightbox-img" src="" alt="">
                        <button type="button" class="wpo-lightbox-close" title="Закрыть (Esc)">✕</button>
                    </div>
                `;
                document.body.appendChild(overlay);

                overlay.addEventListener('click', (e) => {
                    if (e.target.closest('.wpo-lightbox-close') || e.target.classList.contains('wpo-lightbox-backdrop')) {
                        overlay.classList.remove('is-active');
                    }
                });

                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape' && overlay.classList.contains('is-active')) {
                        overlay.classList.remove('is-active');
                    }
                });
            }

            const img = overlay.querySelector('.wpo-lightbox-img');
            img.src = src;
            img.alt = alt || '';
            overlay.classList.add('is-active');
        }
    }

    // Auto-initialize all Olympiad containers on the page
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.wpo-quiz-wrapper').forEach((el) => {
            new OlympiadRunner(el);
        });
    });
})();
