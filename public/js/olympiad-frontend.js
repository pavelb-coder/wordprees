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

            this.initDOM();
            this.bindEvents();
        }

        initDOM() {
            this.introScreen = this.wrapper.querySelector('.wpo-intro-screen');
            this.runnerScreen = this.wrapper.querySelector('.wpo-runner-screen');
            this.resultsScreen = this.wrapper.querySelector('.wpo-results-screen');

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

            if (this.btnPrev) {
                this.btnPrev.addEventListener('click', () => this.navigate(this.currentIndex - 1));
            }
            if (this.btnNext) {
                this.btnNext.addEventListener('click', () => this.navigate(this.currentIndex + 1));
            }
            if (this.btnFinish) {
                this.btnFinish.addEventListener('click', () => this.confirmSubmit());
            }
            if (this.btnFlag) {
                this.btnFlag.addEventListener('click', () => this.toggleFlag());
            }
        }

        async handleStart() {
            const form = this.wrapper.querySelector('.wpo-start-form');
            if (form && !form.checkValidity()) {
                form.reportValidity();
                return;
            }

            const nameInput = this.wrapper.querySelector('input[name="participant_name"]');
            const emailInput = this.wrapper.querySelector('input[name="participant_email"]');
            const orgInput = this.wrapper.querySelector('input[name="participant_org"]');
            const phoneInput = this.wrapper.querySelector('input[name="participant_phone"]');
            const cityInput = this.wrapper.querySelector('input[name="participant_city"]');
            const agreementInput = this.wrapper.querySelector('input[name="agreement"]');

            if (agreementInput && !agreementInput.checked) {
                alert('Необходимо подтвердить согласие с правилами тестирования.');
                agreementInput.focus();
                return;
            }

            const origBtnText = this.startBtn.textContent;

            const payload = {
                quiz_id: this.quizId,
                participant_name: nameInput ? nameInput.value.trim() : '',
                participant_email: emailInput ? emailInput.value.trim() : '',
                participant_org: orgInput ? orgInput.value.trim() : '',
                participant_phone: phoneInput ? phoneInput.value.trim() : '',
                participant_city: cityInput ? cityInput.value.trim() : '',
                agreement: agreementInput ? (agreementInput.checked ? 1 : 0) : 1,
            };

            this.startBtn.disabled = true;
            this.startBtn.textContent = '⏳ Загрузка заданий...';

            try {
                const res = await this.apiPost('/start', payload, 'wp_olympiad_start');
                if (!res.success) {
                    alert(res.message || (res.data && res.data.message) || 'Не удалось начать олимпиаду.');
                    this.startBtn.disabled = false;
                    this.startBtn.textContent = origBtnText;
                    return;
                }

                const data = res.data || res;
                this.token = data.token;
                this.timeLimit = data.time_limit || 0;
                this.questions = data.questions || [];
                this.antiCheat = data.anti_cheat || this.antiCheat;

                if (this.questions.length === 0) {
                    alert('В этой олимпиаде пока нет вопросов.');
                    this.startBtn.disabled = false;
                    return;
                }

                // Switch screen
                this.introScreen.style.display = 'none';
                this.runnerScreen.style.display = 'block';

                if (this.counterTotal) {
                    this.counterTotal.textContent = this.questions.length;
                }

                this.initAntiCheat();
                this.initTimer();
                this.renderNavStrip();
                this.renderQuestion(0);
            } catch (err) {
                console.error(err);
                alert('Сетевая ошибка при запуске олимпиады.');
                this.startBtn.disabled = false;
                this.startBtn.textContent = '🚀 Начать выполнение олимпиады';
            }
        }

        initAntiCheat() {
            if (this.antiCheat.copy) {
                this.wrapper.addEventListener('contextmenu', (e) => e.preventDefault());
                this.wrapper.addEventListener('copy', (e) => e.preventDefault());
                this.wrapper.addEventListener('cut', (e) => e.preventDefault());
                this.wrapper.style.userSelect = 'none';
            }

            if (this.antiCheat.blur) {
                const onTabLeave = () => {
                    if (this.isSubmitting) return;
                    this.tabSwitches++;
                    this.sendHeartbeat(true);
                    this.showModalWarning(
                        `⚠️ Внимание! Зафиксировано переключение вкладки (#${this.tabSwitches}). Это действие записано в протокол олимпиады.`
                    );
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
                const res = await this.apiPost('/heartbeat', {
                    token: this.token,
                    tab_switched: tabSwitched ? 1 : 0
                }, 'wp_olympiad_heartbeat');

                const data = res.data || res;
                if (data.disqualified) {
                    alert(data.message || 'Вы дисквалифицированы за превышение переключений вкладок.');
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

        updateNavState() {
            if (!this.navStrip) return;
            const buttons = this.navStrip.querySelectorAll('.wpo-nav-btn');

            buttons.forEach((btn, idx) => {
                const q = this.questions[idx];
                const isCurrent = idx === this.currentIndex;
                const isAnswered = this.isQuestionAnswered(q.id);
                const isFlagged = this.flagged.has(q.id);

                btn.classList.toggle('active', isCurrent);
                btn.classList.toggle('answered', isAnswered);
                btn.classList.toggle('flagged', isFlagged);
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

            const isLast = this.currentIndex === this.questions.length - 1;
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
            this.renderQuestion(newIndex);
        }

        renderQuestion(index) {
            const q = this.questions[index];
            if (!q || !this.qContainer) return;

            let html = `
                <div class="wpo-question-card-inner" data-id="${q.id}">
                    <div class="wpo-question-prompt">${this.escapeHtml(q.title)}</div>
                    <div class="wpo-question-meta-row">
                        <span class="wpo-points-pill">Баллов: ${Math.round(q.points)}</span>
                        ${q.penalty > 0 ? `<span class="wpo-penalty-pill">Штраф за ошибку: -${Math.round(q.penalty)}</span>` : ''}
                    </div>
            `;

            if (q.content) {
                html += `<div class="wpo-question-content" style="margin-bottom:18px;">${q.content}</div>`;
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
                const savedObj = (savedAns && typeof savedAns === 'object' && !Array.isArray(savedAns)) ? savedAns : {};
                let tmpl = this.escapeHtml(q.template || '');
                tmpl = tmpl.replace(/\[\[blank:(blank_\d+)\]\]/g, (match, bId) => {
                    const currentVal = savedObj[bId] ? this.escapeHtml(savedObj[bId]) : '';
                    return `<input type="text" class="wpo-blank-inline-input" data-blank-id="${bId}" value="${currentVal}" placeholder="введите..." style="display:inline-block; width:140px; padding:4px 8px; border:2px solid #3b82f6; border-radius:6px; font-weight:600; text-align:center; background:#eff6ff; margin:0 4px;">`;
                });
                html += `
                    <div class="wpo-fill-blank-container" style="font-size:16px; line-height:2.2; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:16px;">
                        ${tmpl}
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
            }

            html += '</div>';
            this.qContainer.innerHTML = html;

            this.bindQuestionInputs(q);
            this.updateNavState();
        }

        bindQuestionInputs(q) {
            const inner = this.qContainer.querySelector('.wpo-question-card-inner');
            if (!inner) return;

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
                const inputs = inner.querySelectorAll('.wpo-blank-inline-input');
                inputs.forEach((inp) => {
                    inp.addEventListener('input', () => {
                        const bid = inp.dataset.blankId;
                        if (!this.answers[q.id] || typeof this.answers[q.id] !== 'object' || Array.isArray(this.answers[q.id])) {
                            this.answers[q.id] = {};
                        }
                        this.answers[q.id][bid] = inp.value.trim();
                        this.updateNavState();
                    });
                });
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
            }
        }

        confirmSubmit() {
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
            this.isSubmitting = true;

            clearInterval(this.timerInterval);
            clearInterval(this.heartbeatInterval);

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
                    this.isSubmitting = false;
                    return;
                }

                const data = res.data || res;
                this.renderResults(data);
            } catch (err) {
                console.error(err);
                alert('Сетевая ошибка при отправке результатов.');
                this.isSubmitting = false;
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

            const isPass = !!data.passed;
            const icon = isPass ? '🎉' : '📋';
            const m = Math.floor((data.duration_seconds || 0) / 60);
            const s = (data.duration_seconds || 0) % 60;
            const timeStr = `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;

            let html = `
                <div class="wpo-result-summary">
                    <div class="wpo-result-icon">${icon}</div>
                    <h2>${isPass ? 'Тестирование успешно пройдено!' : 'Тестирование завершено'}</h2>

                    <div class="wpo-score-badge ${isPass ? 'pass' : 'fail'}">
                        ${Math.round(data.score)} / ${Math.round(data.max_score)} (${Math.round(data.percentage)}%)
                    </div>

                    ${data.grade_label ? `<div style="font-size:18px; font-weight:700; color:#1e3a8a; margin-bottom:12px;">🏅 ${this.escapeHtml(data.grade_label)}</div>` : ''}
                    ${data.feedback_message ? `<div style="font-size:15px; color:#475569; max-width:600px; margin:0 auto 20px auto;">${this.escapeHtml(data.feedback_message)}</div>` : ''}

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
                            <div class="val">${data.status === 'needs_review' ? 'На проверке' : (isPass ? 'Зачёт' : 'Не зачёт')}</div>
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
                    ${data.certificate_url ? `
                        <a href="${data.certificate_url}" target="_blank" class="wpo-btn wpo-btn-primary" style="text-decoration:none;">
                            🏅 Распечатать / Скачать диплом
                        </a>
                    ` : ''}
                    <button type="button" class="wpo-btn wpo-btn-outline" onclick="location.reload();">
                        🔄 Вернуться на главную страницу теста
                    </button>
                </div>
            </div>`;

            this.resultsScreen.innerHTML = html;
        }

        showModalWarning(message) {
            const overlay = document.createElement('div');
            overlay.className = 'wpo-modal-overlay';
            overlay.innerHTML = `
                <div class="wpo-modal-card">
                    <h3 style="color:#b45309; margin-top:0;">⚠️ Внимание!</h3>
                    <p style="font-size:15px; margin:16px 0;">${this.escapeHtml(message)}</p>
                    <button type="button" class="wpo-btn wpo-btn-primary" style="padding:8px 20px;">Ясно, продолжить</button>
                </div>
            `;
            overlay.querySelector('button').addEventListener('click', () => {
                overlay.remove();
            });
            document.body.appendChild(overlay);
        }

        async apiPost(endpoint, data, ajaxAction) {
            // First attempt: WP REST API
            if (config.restUrl) {
                try {
                    const res = await fetch(`${config.restUrl}${endpoint}`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': config.nonce || ''
                        },
                        body: JSON.stringify(data)
                    });
                    const json = await res.json();
                    if (res.ok) {
                        return json;
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

            const res = await fetch(config.ajaxUrl, {
                method: 'POST',
                body: formData
            });
            return await res.json();
        }

        escapeHtml(str) {
            if (str === null || str === undefined) return '';
            const div = document.createElement('div');
            div.textContent = String(str);
            return div.innerHTML;
        }
    }

    // Auto-initialize all Olympiad containers on the page
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.wpo-quiz-wrapper').forEach((el) => {
            new OlympiadRunner(el);
        });
    });
})();
