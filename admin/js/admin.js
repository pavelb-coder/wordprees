/**
 * WP Olympiad Admin JavaScript
 */
(function ($) {
    'use strict';

    $(function () {
        var config = window.WPOlympiadAdmin || {};

        // 1. Add Question Button
        $('#wpo-add-new-q-btn').on('click', function () {
            var template = $('#wpo-new-question-template').html();
            if (!template) return;

            var $card = $(template);
            $('.wpo-empty-questions-notice').remove();
            $('#wpo-questions-list').append($card);
            $card.find('.wpo-qcard-body').show();
            $card.find('.wpo-q-title-input').focus();
        });

        // 2. Accordion Toggle
        $(document).on('click', '.wpo-qcard-header', function (e) {
            if ($(e.target).closest('.wpo-btn-del-question, .wpo-drag-handle').length) {
                return;
            }
            var $card = $(this).closest('.wpo-question-card');
            $card.find('.wpo-qcard-body').slideToggle(150);
        });

        // 3. Question Type Selector
        $(document).on('change', '.wpo-q-type-select', function () {
            var type = $(this).val();
            var $card = $(this).closest('.wpo-question-card');
            $card.attr('data-type', type);

            // Update badge in header
            var typeLabel = $(this).find('option:selected').text();
            $card.find('.wpo-qcard-type-badge').text(typeLabel);

            // Toggle panels
            $card.find('.wpo-panel-choices').toggle(type === 'single' || type === 'multi' || type === 'dropdown');
            $card.find('.wpo-panel-boolean').toggle(type === 'boolean');
            $card.find('.wpo-panel-number').toggle(type === 'number');
            $card.find('.wpo-panel-text').toggle(type === 'text');
            $card.find('.wpo-panel-matching').toggle(type === 'matching');
            $card.find('.wpo-panel-ordering').toggle(type === 'ordering');
            $card.find('.wpo-panel-fill_blank').toggle(type === 'fill_blank');
            $card.find('.wpo-panel-slider').toggle(type === 'slider');
            $card.find('.wpo-panel-word_scramble').toggle(type === 'word_scramble');
            $card.find('.wpo-panel-dictation').toggle(type === 'dictation');
            $card.find('.wpo-panel-essay').toggle(type === 'essay');

            // Switch radio vs checkbox for choices
            var inputType = (type === 'multi') ? 'checkbox' : 'radio';
            $card.find('.wpo-correct-check input').attr('type', inputType);
        });

        // 4. Update Header Title on Type
        $(document).on('input', '.wpo-q-title-input', function () {
            var val = $(this).val().trim() || 'Новый вопрос';
            $(this).closest('.wpo-question-card').find('.wpo-qcard-title-text').text(val);
        });

        // 4b. Toggle Required Badge in Header
        $(document).on('change', '.wpo-q-is-required', function () {
            var $card = $(this).closest('.wpo-question-card');
            $card.find('.wpo-qcard-required-badge').toggle($(this).is(':checked'));
        });

        // 4c. Update Group Badge in Header
        $(document).on('input', '.wpo-q-group-name', function () {
            var val = $(this).val().trim();
            var $card = $(this).closest('.wpo-question-card');
            var $badge = $card.find('.wpo-qcard-group-badge');
            $badge.find('.wpo-qcard-group-text').text(val);
            $badge.toggle(Boolean(val));
        });

        // 4d. Group Filter Bar
        $(document).on('click', '.wpo-btn-filter-group', function () {
            $('.wpo-btn-filter-group').removeClass('active');
            $(this).addClass('active');
            var group = $(this).attr('data-group');
            if (!group) {
                $('.wpo-question-card').show();
            } else {
                $('.wpo-question-card').each(function () {
                    var cardGroup = $(this).find('.wpo-q-group-name').val().trim();
                    $(this).toggle(cardGroup === group);
                });
            }
        });

        // 4e. Shared Texts (Общие тексты к заданиям)
        window.wpoSharedTexts = window.wpoSharedTexts || [];

        function findSharedText(id) {
            if (!id || !window.wpoSharedTexts) return null;
            for (var i = 0; i < window.wpoSharedTexts.length; i++) {
                if (String(window.wpoSharedTexts[i].id) === String(id)) {
                    return window.wpoSharedTexts[i];
                }
            }
            return null;
        }

        // Card select change
        $(document).on('change', '.wpo-q-shared-text-select', function () {
            var val = $(this).val();
            var $card = $(this).closest('.wpo-question-card');
            var $badge = $card.find('.wpo-qcard-shared-badge');
            var $prevBtn = $card.find('.wpo-btn-preview-shared-text');
            var $preview = $card.find('.wpo-q-shared-text-preview');

            if (val === 'new') {
                $(this).val('');
                $badge.hide();
                $prevBtn.hide();
                $preview.hide();
                openSharedTextsModal(null, true);
                return;
            }

            if (!val) {
                $badge.hide();
                $prevBtn.hide();
                $preview.hide();
            } else {
                var st = findSharedText(val);
                var title = st ? (st.title || val) : $(this).find('option:selected').text();
                $badge.find('.wpo-qcard-shared-text-title').text(title);
                $badge.show();
                $prevBtn.show();
                $preview.hide();
            }
        });

        // Card quick preview toggle
        $(document).on('click', '.wpo-btn-preview-shared-text', function () {
            var $card = $(this).closest('.wpo-question-card');
            var $preview = $card.find('.wpo-q-shared-text-preview');
            var stId = $card.find('.wpo-q-shared-text-select').val();
            if ($preview.is(':visible')) {
                $preview.slideUp(150);
                return;
            }
            var st = findSharedText(stId);
            if (!st) {
                $preview.html('<em>Общий текст не найден или не сохранён.</em>').slideDown(150);
                return;
            }
            var html = '<div style="font-weight:700; margin-bottom:4px; color:#1e293b;">📖 ' + (st.title || 'Общий текст') + '</div>' +
                '<div style="font-size:12px; line-height:1.6;">' + (st.content || '<em>Текст отсутствует</em>') + '</div>';
            $preview.html(html).slideDown(150);
        });

        // Open modal button
        $(document).on('click', '#wpo-open-shared-texts-btn, .wpo-btn-manage-shared-texts', function () {
            var targetId = null;
            if ($(this).hasClass('wpo-btn-manage-shared-texts')) {
                targetId = $(this).closest('.wpo-question-card').find('.wpo-q-shared-text-select').val() || null;
            }
            openSharedTextsModal(targetId);
        });

        // Close modal
        $(document).on('click', '#wpo-close-shared-modal-btn, #wpo-btn-cancel-shared-modal', function () {
            $('#wpo-shared-texts-modal').fadeOut(150);
        });

        function openSharedTextsModal(targetId, forceNew) {
            renderSharedTextsSidebar(targetId, forceNew);
            $('#wpo-shared-texts-modal').fadeIn(150);
        }

        function renderSharedTextsSidebar(selectId, forceNew) {
            var $list = $('#wpo-st-items-list');
            $list.empty();

            var texts = window.wpoSharedTexts || [];
            if (texts.length === 0 && !forceNew) {
                forceNew = true;
            }

            texts.forEach(function (t, idx) {
                var usageCount = 0;
                $('.wpo-question-card').each(function () {
                    if ($(this).find('.wpo-q-shared-text-select').val() === t.id) {
                        usageCount++;
                    }
                });

                var $item = $('<div class="wpo-st-item" data-id="' + t.id + '">' +
                    '<div class="wpo-st-item-title">📖 ' + (t.title || ('Текст #' + (idx + 1))) + '</div>' +
                    '<div class="wpo-st-item-meta">Используется в ' + usageCount + ' задан.</div>' +
                '</div>');
                $list.append($item);
            });

            if (forceNew) {
                createNewSharedText();
            } else if (selectId) {
                loadSharedTextIntoEditor(selectId);
            } else if (texts.length > 0) {
                loadSharedTextIntoEditor(texts[0].id);
            } else {
                createNewSharedText();
            }
        }

        function createNewSharedText() {
            var newId = 'st_' + Date.now() + '_' + Math.floor(Math.random() * 100);
            $('.wpo-st-item').removeClass('active');
            $('#wpo-cur-st-id').val(newId);
            $('#wpo-cur-st-title').val('Новый общий текст').focus();
            $('#wpo-cur-st-content').val('');
            $('#wpo-cur-st-preview-box').hide();
            $('#wpo-st-no-selection').hide();
            $('#wpo-st-editor-form').show();

            // Uncheck all question checkboxes
            $('.wpo-st-assign-q').prop('checked', false);
        }

        $(document).on('click', '#wpo-btn-new-shared-text', function () {
            saveCurrentEditorStateToMemory();
            createNewSharedText();
        });

        $(document).on('click', '.wpo-st-item', function () {
            var id = $(this).attr('data-id');
            saveCurrentEditorStateToMemory();
            loadSharedTextIntoEditor(id);
        });

        function loadSharedTextIntoEditor(id) {
            $('.wpo-st-item').removeClass('active');
            $('.wpo-st-item[data-id="' + id + '"]').addClass('active');

            var st = findSharedText(id);
            if (!st) {
                $('#wpo-st-editor-form').hide();
                $('#wpo-st-no-selection').show();
                return;
            }

            $('#wpo-st-no-selection').hide();
            $('#wpo-st-editor-form').show();
            $('#wpo-cur-st-id').val(st.id);
            $('#wpo-cur-st-title').val(st.title || '');
            $('#wpo-cur-st-content').val(st.content || '');
            $('#wpo-cur-st-preview-box').hide();

            // Check matching question cards
            $('.wpo-st-assign-q').each(function () {
                var qid = $(this).val();
                var cardVal = $('.wpo-question-card[data-id="' + qid + '"]').find('.wpo-q-shared-text-select').val();
                $(this).prop('checked', cardVal === st.id);
            });
        }

        function saveCurrentEditorStateToMemory() {
            var curId = $('#wpo-cur-st-id').val();
            if (!curId) return;

            var title = $('#wpo-cur-st-title').val().trim() || 'Общий текст';
            var content = $('#wpo-cur-st-content').val();

            var existing = findSharedText(curId);
            if (existing) {
                existing.title = title;
                existing.content = content;
            } else {
                window.wpoSharedTexts.push({
                    id: curId,
                    title: title,
                    content: content
                });
            }
        }

        // Live preview inside modal
        $(document).on('click', '#wpo-cur-st-preview-toggle', function () {
            var $box = $('#wpo-cur-st-preview-box');
            if ($box.is(':visible')) {
                $box.slideUp(150);
            } else {
                var val = $('#wpo-cur-st-content').val().trim();
                $box.html(val ? val : '<em>Текст пуст</em>').slideDown(150);
            }
        });

        // Delete current shared text
        $(document).on('click', '#wpo-btn-delete-cur-st', function () {
            var curId = $('#wpo-cur-st-id').val();
            if (!curId) return;
            if (!confirm('Удалить этот общий текст? Все привязанные вопросы будут откреплены.')) return;

            window.wpoSharedTexts = (window.wpoSharedTexts || []).filter(function (t) {
                return t.id !== curId;
            });

            // Unlink in question cards
            $('.wpo-question-card').each(function () {
                var $sel = $(this).find('.wpo-q-shared-text-select');
                if ($sel.val() === curId) {
                    $sel.val('');
                    $(this).find('.wpo-qcard-shared-badge').hide();
                    $(this).find('.wpo-btn-preview-shared-text').hide();
                }
            });

            renderSharedTextsSidebar();
        });

        // Save shared texts modal via AJAX
        $(document).on('click', '#wpo-btn-save-shared-modal', function () {
            saveCurrentEditorStateToMemory();

            var $btn = $(this);
            $btn.prop('disabled', true).text('Сохранение...');

            var quizId = parseInt($('#wpo-questions-list').attr('data-quiz-id'), 10);
            var curId = $('#wpo-cur-st-id').val();

            // Build assignments dictionary: qid -> shared_text_id
            var assignments = {};
            $('.wpo-st-assign-q').each(function () {
                var qid = $(this).val();
                if ($(this).is(':checked')) {
                    assignments[qid] = curId;
                } else {
                    // If it previously was this curId, unassign
                    var cardVal = $('.wpo-question-card[data-id="' + qid + '"]').find('.wpo-q-shared-text-select').val();
                    if (cardVal === curId) {
                        assignments[qid] = '';
                    }
                }
            });

            $.post(config.ajaxUrl, {
                action: 'wp_olympiad_save_shared_texts',
                nonce: config.nonce,
                quiz_id: quizId,
                shared_texts: window.wpoSharedTexts,
                assignments: assignments
            }, function (res) {
                $btn.prop('disabled', false).text('💾 Сохранить общие тексты');
                if (res.success) {
                    window.wpoSharedTexts = res.data.shared_texts || window.wpoSharedTexts;
                    $('#wpo-shared-texts-count').text(window.wpoSharedTexts.length);

                    // Rebuild all selects across question cards
                    rebuildAllSharedTextSelects(assignments);

                    $('#wpo-shared-texts-modal').fadeOut(150);
                } else {
                    alert(res.data ? res.data.message : 'Ошибка при сохранении общих текстов');
                }
            }).fail(function () {
                $btn.prop('disabled', false).text('💾 Сохранить общие тексты');
                alert('Сетевая ошибка при сохранении');
            });
        });

        function rebuildAllSharedTextSelects(assignments) {
            var texts = window.wpoSharedTexts || [];
            $('.wpo-question-card').each(function () {
                var $card = $(this);
                var qid = $card.attr('data-id');
                var $sel = $card.find('.wpo-q-shared-text-select');
                var currentVal = (assignments && assignments[qid] !== undefined) ? assignments[qid] : $sel.val();

                $sel.empty();
                $sel.append('<option value="">— Без общего текста —</option>');
                texts.forEach(function (t) {
                    var selected = (t.id === currentVal) ? ' selected' : '';
                    $sel.append('<option value="' + t.id + '"' + selected + '>' + (t.title || t.id) + '</option>');
                });
                $sel.append('<option value="new">+ Создать новый общий текст...</option>');

                // Update badge in card header
                var $badge = $card.find('.wpo-qcard-shared-badge');
                var $prevBtn = $card.find('.wpo-btn-preview-shared-text');
                if (currentVal) {
                    var st = findSharedText(currentVal);
                    var stTitle = st ? (st.title || currentVal) : currentVal;
                    $badge.find('.wpo-qcard-shared-text-title').text(stTitle);
                    $badge.show();
                    $prevBtn.show();
                } else {
                    $badge.hide();
                    $prevBtn.hide();
                }
            });
        }

        // 5. Update Points Badge
        $(document).on('input', '.wpo-q-points-input', function () {
            var val = parseInt($(this).val(), 10) || 0;
            $(this).closest('.wpo-question-card').find('.wpo-qcard-points-badge').text(val + ' б.');
        });

        // Text formatting helper
        function wrapTextInTextarea(textarea, beforeTag, afterTag, defaultText) {
            if (!textarea) return;
            var start = textarea.selectionStart;
            var end = textarea.selectionEnd;
            var val = textarea.value;
            var selectedText = val.substring(start, end);
            var insertText = selectedText ? (beforeTag + selectedText + afterTag) : (beforeTag + (defaultText || 'текст') + afterTag);
            textarea.value = val.substring(0, start) + insertText + val.substring(end);
            textarea.focus();
            if (selectedText) {
                textarea.setSelectionRange(start, start + insertText.length);
            } else {
                textarea.setSelectionRange(start + beforeTag.length, start + beforeTag.length + (defaultText || 'текст').length);
            }
            $(textarea).trigger('input');
        }

        // Formatting Toolbar Actions
        $(document).on('click', '.wpo-format-btn', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var $toolbar = $btn.closest('.wpo-format-toolbar');
            var targetSelector = $toolbar.attr('data-target') || '.wpo-q-title-input';
            var $card = $btn.closest('.wpo-question-card');
            var $textarea = $toolbar.siblings(targetSelector);
            if (!$textarea.length) {
                $textarea = $card.find(targetSelector);
            }
            var textarea = $textarea[0];
            if (!textarea) return;

            var tag = $btn.attr('data-tag');
            var action = $btn.attr('data-action');

            if (tag) {
                wrapTextInTextarea(textarea, '<' + tag + '>', '</' + tag + '>', 'текст');
            } else if (action === 'ul') {
                wrapTextInTextarea(textarea, "\n<ul>\n  <li>", "</li>\n</ul>\n", "Пункт списка");
            } else if (action === 'ol') {
                wrapTextInTextarea(textarea, "\n<ol>\n  <li>", "</li>\n</ol>\n", "Шаг 1");
            } else if (action === 'link') {
                var url = prompt('Введите адрес ссылки (URL):', 'https://');
                if (url && url.trim()) {
                    wrapTextInTextarea(textarea, '<a href="' + url.trim() + '" target="_blank">', '</a>', 'текст ссылки');
                }
            } else if ($btn.hasClass('wpo-btn-insert-inline-img')) {
                if (window.wp && window.wp.media) {
                    var imgFrame = wp.media({
                        title: 'Вставить изображение в текст вопроса',
                        button: { text: 'Вставить в текст' },
                        multiple: false,
                        library: { type: 'image' }
                    });
                    imgFrame.on('select', function () {
                        var att = imgFrame.state().get('selection').first().toJSON();
                        var alt = att.alt || att.title || 'Иллюстрация';
                        wrapTextInTextarea(textarea, '<img src="' + att.url + '" alt="' + alt + '">', '', '');
                    });
                    imgFrame.open();
                } else {
                    var customImgUrl = prompt('Введите URL изображения:', 'https://');
                    if (customImgUrl && customImgUrl.trim()) {
                        wrapTextInTextarea(textarea, '<img src="' + customImgUrl.trim() + '" alt="Иллюстрация">', '', '');
                    }
                }
            } else if ($btn.hasClass('wpo-btn-toggle-preview')) {
                var isTitle = targetSelector.indexOf('title') !== -1;
                var $preview = isTitle ? $card.find('.wpo-preview-title') : $card.find('.wpo-preview-content');
                if ($preview.is(':visible')) {
                    $preview.hide();
                    $textarea.show();
                    $btn.removeClass('is-active');
                } else {
                    $preview.html($textarea.val()).show();
                    $textarea.hide();
                    $btn.addClass('is-active');
                }
            }
        });

        // Toggle Extended Content Link
        $(document).on('click', '.wpo-toggle-content-link', function (e) {
            e.preventDefault();
            var $link = $(this);
            var $wrap = $link.siblings('.wpo-content-wrapper');
            $wrap.slideToggle(150, function () {
                if ($wrap.is(':visible')) {
                    $link.text('▾ Скрыть подробное описание задачи');
                } else {
                    $link.text('▸ + Добавить подробное условие / текст / формулы задачи');
                }
            });
        });

        // Question Image Upload & Selection
        $(document).on('click', '.wpo-btn-upload-q-image', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var $card = $btn.closest('.wpo-question-card');

            if (window.wp && window.wp.media) {
                var frame = wp.media({
                    title: 'Выберите или загрузите иллюстрацию к вопросу',
                    button: { text: 'Использовать для вопроса' },
                    multiple: false,
                    library: { type: 'image' }
                });

                frame.on('select', function () {
                    var att = frame.state().get('selection').first().toJSON();
                    $card.find('.wpo-q-image-url').val(att.url).trigger('change');
                    if (!$card.find('.wpo-q-image-caption').val() && (att.caption || att.title)) {
                        $card.find('.wpo-q-image-caption').val(att.caption || att.title);
                    }
                });

                frame.open();
            } else {
                var url = prompt('Введите URL изображения (https://...):', 'https://');
                if (url && url.trim()) {
                    $card.find('.wpo-q-image-url').val(url.trim()).trigger('change');
                }
            }
        });

        $(document).on('click', '.wpo-btn-remove-q-image', function (e) {
            e.preventDefault();
            var $card = $(this).closest('.wpo-question-card');
            $card.find('.wpo-q-image-url').val('').trigger('change');
            $card.find('.wpo-q-image-caption').val('');
        });

        $(document).on('change input', '.wpo-q-image-url', function () {
            var $card = $(this).closest('.wpo-question-card');
            var url = $(this).val().trim();
            if (url) {
                $card.find('.wpo-q-image-thumb').attr('src', url);
                $card.find('.wpo-q-image-preview-box').show();
                $card.find('.wpo-btn-remove-q-image').show();
            } else {
                $card.find('.wpo-q-image-thumb').attr('src', '');
                $card.find('.wpo-q-image-preview-box').hide();
                $card.find('.wpo-btn-remove-q-image').hide();
            }
        });

        // 6. Add Choice Row
        $(document).on('click', '.wpo-btn-add-choice', function () {
            var $card = $(this).closest('.wpo-question-card');
            var qid = $card.attr('data-id') || '0';
            var type = $card.attr('data-type') || 'single';
            var inputType = (type === 'multi') ? 'checkbox' : 'radio';

            var count = $card.find('.wpo-choice-row').length + 1;
            var newId = 'c' + count + '_' + Math.floor(Math.random() * 1000);

            var rowHtml = '<div class="wpo-choice-row" style="display:flex; gap:8px; align-items:center; margin-bottom:8px;">' +
                '<label class="wpo-correct-check">' +
                    '<input type="' + inputType + '" name="choice_correct_' + qid + '" value="' + newId + '">' +
                '</label>' +
                '<input type="text" class="regular-text wpo-choice-text" data-id="' + newId + '" placeholder="Текст варианта ответа..." style="flex:1;">' +
                '<input type="number" step="1" class="small-text wpo-choice-points" placeholder="Баллы" title="Индивидуальный балл за данный вариант" style="width:75px;">' +
                '<button type="button" class="button button-small wpo-btn-del-choice" title="Удалить вариант">✕</button>' +
            '</div>';

            $card.find('.wpo-choices-list').append(rowHtml);
        });

        // 7. Delete Choice Row
        $(document).on('click', '.wpo-btn-del-choice', function () {
            var $list = $(this).closest('.wpo-choices-list');
            if ($list.find('.wpo-choice-row').length > 1) {
                $(this).closest('.wpo-choice-row').remove();
            } else {
                alert('В вопросе должен оставаться хотя бы один вариант ответа.');
            }
        });

        // 7b. Add / Delete Matching Pair (Online Test Pad)
        $(document).on('click', '.wpo-btn-add-matching', function () {
            var $card = $(this).closest('.wpo-question-card');
            var count = $card.find('.wpo-matching-row').length + 1;
            var newId = 'p' + count + '_' + Math.floor(Math.random() * 1000);

            var rowHtml = '<div class="wpo-matching-row" style="display:flex; gap:10px; align-items:center; margin-bottom:8px;">' +
                '<input type="text" class="regular-text wpo-match-left" data-id="' + newId + '" placeholder="Элемент слева (понятие)..." style="flex:1;">' +
                '<span style="font-weight:bold; color:#64748b;">➔</span>' +
                '<input type="text" class="regular-text wpo-match-right" placeholder="Соответствие справа (определение)..." style="flex:1;">' +
                '<input type="number" step="1" class="small-text wpo-match-points" placeholder="Баллы" title="Баллы за сопоставление этой пары" style="width:75px;">' +
                '<button type="button" class="button button-small wpo-btn-del-matching" title="Удалить пару">✕</button>' +
            '</div>';

            $card.find('.wpo-matching-list').append(rowHtml);
        });

        $(document).on('click', '.wpo-btn-del-matching', function () {
            var $list = $(this).closest('.wpo-matching-list');
            if ($list.find('.wpo-matching-row').length > 1) {
                $(this).closest('.wpo-matching-row').remove();
            } else {
                alert('Должна оставаться хотя бы одна пара соответствия.');
            }
        });

        // 7c. Add / Delete Ordering Item (Online Test Pad)
        $(document).on('click', '.wpo-btn-add-ordering', function () {
            var $card = $(this).closest('.wpo-question-card');
            var count = $card.find('.wpo-ordering-row').length + 1;
            var newId = 'ord_' + count + '_' + Math.floor(Math.random() * 1000);

            var rowHtml = '<div class="wpo-ordering-row" style="display:flex; gap:10px; align-items:center; margin-bottom:8px;">' +
                '<span class="wpo-order-num" style="font-weight:700; width:25px;">' + count + '.</span>' +
                '<input type="text" class="large-text wpo-order-text" data-id="' + newId + '" placeholder="Текст этапа или элемента последовательности..." style="flex:1;">' +
                '<input type="number" step="1" class="small-text wpo-order-points" placeholder="Баллы" title="Баллы за верное положение этого элемента" style="width:75px;">' +
                '<button type="button" class="button button-small wpo-btn-del-ordering" title="Удалить элемент">✕</button>' +
            '</div>';

            $card.find('.wpo-ordering-list').append(rowHtml);
        });

        $(document).on('click', '.wpo-btn-del-ordering', function () {
            var $list = $(this).closest('.wpo-ordering-list');
            if ($list.find('.wpo-ordering-row').length > 1) {
                $(this).closest('.wpo-ordering-row').remove();
                // Renumber
                $list.find('.wpo-ordering-row').each(function (i) {
                    $(this).find('.wpo-order-num').text((i + 1) + '.');
                });
            } else {
                alert('В последовательности должен оставаться хотя бы один элемент.');
            }
        });

        // Dynamic toggles for Short Text and Essay auto-check
        $(document).on('change', '.wpo-text-match-mode', function () {
            var $panel = $(this).closest('.wpo-panel-text');
            if ($(this).val() === 'fuzzy') {
                $panel.find('.wpo-text-typo-wrap').slideDown(150);
            } else {
                $panel.find('.wpo-text-typo-wrap').slideUp(150);
            }
        });

        $(document).on('change', '.wpo-essay-auto-check', function () {
            var $panel = $(this).closest('.wpo-panel-essay');
            if ($(this).is(':checked')) {
                $panel.find('.wpo-essay-auto-settings').slideDown(150);
            } else {
                $panel.find('.wpo-essay-auto-settings').slideUp(150);
            }
        });

        // 8. Delete Question via AJAX
        $(document).on('click', '.wpo-btn-del-question', function (e) {
            e.stopPropagation();
            if (!confirm(config.i18n ? config.i18n.deleteConfirm : 'Удалить этот вопрос?')) {
                return;
            }

            var $card = $(this).closest('.wpo-question-card');
            var qid = parseInt($card.attr('data-id'), 10);

            if (qid <= 0) {
                $card.remove();
                return;
            }

            $.post(config.ajaxUrl, {
                action: 'wp_olympiad_delete_question',
                id: qid,
                nonce: config.nonce
            }, function (res) {
                if (res.success) {
                    $card.slideUp(200, function () { $(this).remove(); });
                } else {
                    alert(res.data ? res.data.message : 'Ошибка удаления');
                }
            });
        });

        // 9. Save Question via AJAX
        $(document).on('click', '.wpo-btn-save-q', function () {
            var $card = $(this).closest('.wpo-question-card');
            var $spinner = $card.find('.wpo-save-status');
            var $btn = $(this);

            var qid = parseInt($card.attr('data-id'), 10) || 0;
            var quizId = parseInt($('#wpo-questions-list').attr('data-quiz-id'), 10);
            var qtype = $card.attr('data-type') || 'single';
            var title = $card.find('.wpo-q-title-input').val().trim();
            var points = parseInt($card.find('input[name="points"]').val(), 10);
            if (isNaN(points)) points = 1;
            var penalty = parseInt($card.find('input[name="penalty"]').val(), 10);
            if (isNaN(penalty)) penalty = 0;
            var explanation = $card.find('.wpo-q-explanation').val().trim();

            if (!title) {
                alert('Пожалуйста, введите текст задания.');
                $card.find('.wpo-q-title-input').focus();
                return;
            }

            var content = $card.find('.wpo-q-content-input').length ? $card.find('.wpo-q-content-input').val().trim() : '';
            var imageUrl = $card.find('.wpo-q-image-url').length ? $card.find('.wpo-q-image-url').val().trim() : '';
            var imageCaption = $card.find('.wpo-q-image-caption').length ? $card.find('.wpo-q-image-caption').val().trim() : '';
            var imagePosition = $card.find('.wpo-q-image-position').length ? $card.find('.wpo-q-image-position').val() : 'above';
            var isRequired = $card.find('.wpo-q-is-required').is(':checked') ? 1 : 0;
            var shuffleAnswers = $card.find('.wpo-q-shuffle-answers').is(':checked') ? 1 : 0;
            var groupName = $card.find('.wpo-q-group-name').length ? $card.find('.wpo-q-group-name').val().trim() : '';
            var hidePoints = $card.find('.wpo-q-hide-points').is(':checked');
            var sharedTextId = $card.find('.wpo-q-shared-text-select').length ? $card.find('.wpo-q-shared-text-select').val().trim() : '';
            if (sharedTextId === 'new') sharedTextId = '';

            // Options payload
            var options = {
                explanation: explanation,
                image_url: imageUrl,
                image_caption: imageCaption,
                image_position: imagePosition,
                is_required: isRequired,
                shuffle_answers: shuffleAnswers,
                group_name: groupName,
                show_points: hidePoints ? 0 : 1,
                shared_text_id: sharedTextId
            };

            if (qtype === 'single' || qtype === 'multi' || qtype === 'dropdown') {
                var choices = [];
                $card.find('.wpo-choice-row').each(function () {
                    var cid = $(this).find('.wpo-choice-text').attr('data-id');
                    var ctext = $(this).find('.wpo-choice-text').val().trim();
                    var isCorrect = $(this).find('.wpo-correct-check input').is(':checked') ? 1 : 0;
                    var ptsVal = $(this).find('.wpo-choice-points').val();
                    var pts = (ptsVal !== '' && ptsVal !== undefined && !isNaN(parseInt(ptsVal, 10))) ? parseInt(ptsVal, 10) : null;
                    if (ctext !== '') {
                        choices.push({
                            id: cid,
                            text: ctext,
                            is_correct: isCorrect,
                            points: pts
                        });
                    }
                });
                options.choices = choices;
            } else if (qtype === 'boolean') {
                var boolVal = $card.find('.wpo-bool-choice:checked').val() || 'true';
                options.choices = [
                    { id: 'true', text: 'Верно / Да', is_correct: boolVal === 'true' ? 1 : 0 },
                    { id: 'false', text: 'Неверно / Нет', is_correct: boolVal === 'false' ? 1 : 0 }
                ];
            } else if (qtype === 'number') {
                options.target_value = parseFloat($card.find('.wpo-num-target').val()) || 0;
                options.tolerance = parseFloat($card.find('.wpo-num-tolerance').val()) || 0;
            } else if (qtype === 'text') {
                var rawAns = $card.find('.wpo-text-answers').val().trim();
                options.answers = rawAns ? rawAns.split(',').map(function (s) { return s.trim(); }).filter(Boolean) : [];
                options.match_mode = $card.find('.wpo-text-match-mode').val() || 'exact';
                options.typo_tolerance = parseInt($card.find('.wpo-text-typo-tolerance').val(), 10) || 1;
                options.case_sensitive = $card.find('.wpo-text-case').is(':checked') ? 1 : 0;
                options.ignore_punct = $card.find('.wpo-text-ignore-punct').is(':checked') ? 1 : 0;
                options.ignore_whitespace = $card.find('.wpo-text-ignore-whitespace').is(':checked') ? 1 : 0;
            } else if (qtype === 'essay') {
                options.auto_check = $card.find('.wpo-essay-auto-check').is(':checked') ? 1 : 0;
                var rawKw = $card.find('.wpo-essay-keywords').val().trim();
                options.keywords = rawKw ? rawKw.split(',').map(function (s) { return s.trim(); }).filter(Boolean) : [];
                options.min_keywords = parseInt($card.find('.wpo-essay-min-keywords').val(), 10) || 0;
                options.scoring_mode = $card.find('.wpo-essay-scoring-mode').val() || 'proportional';
                options.min_words = parseInt($card.find('.wpo-essay-min-words').val(), 10) || 0;
                options.auto_pass = $card.find('.wpo-essay-auto-pass').is(':checked') ? 1 : 0;
                options.case_sensitive = $card.find('.wpo-essay-case').is(':checked') ? 1 : 0;
            } else if (qtype === 'matching') {
                var pairs = [];
                $card.find('.wpo-matching-row').each(function () {
                    var pid = $(this).find('.wpo-match-left').attr('data-id');
                    var pleft = $(this).find('.wpo-match-left').val().trim();
                    var pright = $(this).find('.wpo-match-right').val().trim();
                    var ptsVal = $(this).find('.wpo-match-points').val();
                    var pts = (ptsVal !== '' && ptsVal !== undefined && !isNaN(parseInt(ptsVal, 10))) ? parseInt(ptsVal, 10) : null;
                    if (pleft !== '' || pright !== '') {
                        pairs.push({
                            id: pid,
                            left: pleft,
                            right: pright,
                            points: pts
                        });
                    }
                });
                options.pairs = pairs;
            } else if (qtype === 'ordering') {
                var items = [];
                $card.find('.wpo-ordering-row').each(function () {
                    var iid = $(this).find('.wpo-order-text').attr('data-id');
                    var itext = $(this).find('.wpo-order-text').val().trim();
                    var ptsVal = $(this).find('.wpo-order-points').val();
                    var pts = (ptsVal !== '' && ptsVal !== undefined && !isNaN(parseInt(ptsVal, 10))) ? parseInt(ptsVal, 10) : null;
                    if (itext !== '') {
                        items.push({
                            id: iid,
                            text: itext,
                            points: pts
                        });
                    }
                });
                options.items = items;
            } else if (qtype === 'fill_blank') {
                options.template = $card.find('.wpo-blank-template').val().trim();
                options.choice_mode = $card.find('.wpo-blank-choice-mode').val() || 'input';
                options.distractors = $card.find('.wpo-blank-distractors').val().trim();
                options.case_sensitive = $card.find('.wpo-blank-case').is(':checked') ? 1 : 0;
            } else if (qtype === 'slider') {
                options.min = parseFloat($card.find('.wpo-slider-min').val()) || 0;
                options.max = parseFloat($card.find('.wpo-slider-max').val()) || 100;
                options.step = parseFloat($card.find('.wpo-slider-step').val()) || 1;
                options.target_value = parseFloat($card.find('.wpo-slider-target').val()) || 50;
                options.tolerance = parseFloat($card.find('.wpo-slider-tolerance').val()) || 0;
            } else if (qtype === 'word_scramble') {
                var rawWords = $card.find('.wpo-scramble-target').val().trim();
                options.words = rawWords ? rawWords.split(',').map(function (s) { return s.trim(); }).filter(Boolean) : [];
                options.target_word = options.words[0] || '';
                options.extra_letters = $card.find('.wpo-scramble-extra').val().trim();
                options.case_sensitive = $card.find('.wpo-scramble-case').is(':checked') ? 1 : 0;
            } else if (qtype === 'dictation') {
                options.template = $card.find('.wpo-dictation-template').val().trim();
                options.audio_url = $card.find('.wpo-dictation-audio').val().trim();
                options.scoring_mode = $card.find('.wpo-dictation-scoring-mode').val() || 'proportional';
                options.allowed_mistakes = parseInt($card.find('.wpo-dictation-allowed-mistakes').val(), 10) || 0;
            }

            $spinner.addClass('is-active');
            $btn.prop('disabled', true);

            $.post(config.ajaxUrl, {
                action: 'wp_olympiad_save_question',
                id: qid,
                quiz_id: quizId,
                question_type: qtype,
                title: title,
                content: content,
                points: points,
                penalty: penalty,
                options: JSON.stringify(options),
                nonce: config.nonce
            }, function (res) {
                $spinner.removeClass('is-active');
                $btn.prop('disabled', false);

                if (res.success) {
                    $card.attr('data-id', res.data.id);
                    $card.find('input[name="id"]').val(res.data.id);
                    $card.find('.wpo-qcard-index').text('#' + res.data.id);
                    $btn.text('✓ Сохранено').delay(1500).queue(function (next) {
                        $(this).text('💾 Сохранить задание');
                        next();
                    });
                } else {
                    alert(res.data ? res.data.message : 'Ошибка сохранения');
                }
            }).fail(function () {
                $spinner.removeClass('is-active');
                $btn.prop('disabled', false);
                alert('Сетевая ошибка при сохранении');
            });
        });

        // 10. Manual Grading Save in Review View
        $('#wpo-btn-save-review').on('click', function () {
            var $btn = $(this);
            var $spinner = $('.wpo-review-spinner');
            var attemptId = parseInt($('input[name="attempt_id"]').val(), 10);

            var grades = {};
            $('.wpo-response-card').each(function () {
                var respId = $(this).attr('data-resp-id');
                var points = parseInt($(this).find('.wpo-grade-points').val(), 10);
                if (isNaN(points)) points = 0;
                var comment = $(this).find('.wpo-grade-comment').val();
                var isCorrect = $(this).find('.wpo-grade-correct').is(':checked') ? 1 : 0;

                grades[respId] = {
                    points: points,
                    comment: comment,
                    is_correct: isCorrect
                };
            });

            $spinner.addClass('is-active');
            $btn.prop('disabled', true);

            $.post(config.ajaxUrl, {
                action: 'wp_olympiad_save_manual_grade',
                attempt_id: attemptId,
                grades: JSON.stringify(grades),
                nonce: config.nonce
            }, function (res) {
                $spinner.removeClass('is-active');
                $btn.prop('disabled', false);

                if (res.success) {
                    alert(res.data.message);
                    location.reload();
                } else {
                    alert(res.data ? res.data.message : 'Ошибка');
                }
            }).fail(function () {
                $spinner.removeClass('is-active');
                $btn.prop('disabled', false);
                alert('Сетевая ошибка');
            });
        });

        // 10a. Auto check single response in attempt review
        $(document).on('click', '.wpo-btn-auto-grade-single', function () {
            var $btn = $(this);
            var respId = $btn.attr('data-resp-id');
            var $card = $btn.closest('.wpo-response-card');
            var $status = $card.find('.wpo-auto-grade-status[data-resp-id="' + respId + '"]');

            $btn.prop('disabled', true).text('⏳ Проверка...');
            $status.text('');

            $.post(config.ajaxUrl, {
                action: 'wp_olympiad_auto_check_answer',
                response_id: respId,
                nonce: config.nonce
            }, function (res) {
                $btn.prop('disabled', false).text('⚡ Автопроверить ответ');
                if (res.success) {
                    var d = res.data;
                    $card.find('.wpo-grade-points[data-resp-id="' + respId + '"]').val(d.recommended_points);
                    $card.find('.wpo-grade-correct[data-resp-id="' + respId + '"]').prop('checked', d.is_correct === 1);
                    $card.find('.wpo-grade-comment[data-resp-id="' + respId + '"]').val(d.comment);
                    var badgeColor = (d.is_correct === 1) ? '#059669' : (d.recommended_points > 0 ? '#d97706' : '#dc2626');
                    var safeComment = $('<div>').text(d.comment).html();
                    $status.html('<span style="color:' + badgeColor + ';">✓ ' + safeComment + '</span>');
                } else {
                    var safeErr = $('<div>').text(res.data ? res.data.message : 'Ошибка').html();
                    $status.html('<span style="color:#dc2626;">✕ ' + safeErr + '</span>');
                }
            }).fail(function () {
                $btn.prop('disabled', false).text('⚡ Автопроверить ответ');
                $status.html('<span style="color:#dc2626;">✕ Ошибка сети</span>');
            });
        });

        // 10b. Auto check all text & essay responses in current attempt
        $('#wpo-btn-auto-grade-all-text').on('click', function () {
            var $btns = $('.wpo-btn-auto-grade-single');
            if ($btns.length === 0) {
                alert('В этой попытке нет текстовых заданий для автопроверки.');
                return;
            }
            var $mainBtn = $(this);
            $mainBtn.prop('disabled', true).text('⏳ Автопроверка ответов...');
            var queue = $btns.toArray();

            function processNext() {
                if (queue.length === 0) {
                    $mainBtn.prop('disabled', false).text('⚡ Автопроверить все текстовые ответы');
                    alert('Автопроверка всех текстовых ответов завершена! Проверьте начисленные баллы и нажмите «Сохранить оценки».');
                    return;
                }
                var btnEl = queue.shift();
                $(btnEl).trigger('click');
                setTimeout(processNext, 250);
            }
            processNext();
        });

        // 11. Bulk Selection & Deletion of Attempts
        $('#wpo-cb-select-all').on('change', function () {
            var isChecked = $(this).is(':checked');
            $('.wpo-attempt-checkbox').prop('checked', isChecked);
        });

        $('#wpo-bulk-attempts-form').on('submit', function (e) {
            var bulkAction = $('select[name="bulk_action"]').val();
            if (bulkAction === 'bulk_delete') {
                var checkedCount = $('input[name="attempt_ids[]"]:checked').length;
                if (checkedCount === 0) {
                    alert('Пожалуйста, отметьте галочками хотя бы одну попытку для удаления.');
                    e.preventDefault();
                    return false;
                }
                if (!confirm('Вы уверены, что хотите удалить выбранные попытки и все их ответы (' + checkedCount + ' шт.)? Это действие необратимо.')) {
                    e.preventDefault();
                    return false;
                }
            } else if (bulkAction === '-1' || !bulkAction) {
                e.preventDefault();
                return false;
            }
        });

        // 12. Delete Individual Question Response in Review View
        $(document).on('click', '.wpo-btn-del-response', function (e) {
            e.preventDefault();
            if (!confirm('Вы действительно хотите удалить ответ участника на это задание? Набранные баллы будут пересчитаны.')) {
                return;
            }
            var $btn = $(this);
            var respId = parseInt($btn.attr('data-resp-id'), 10);
            var $card = $btn.closest('.wpo-response-card');

            $btn.prop('disabled', true);
            $.post(config.ajaxUrl, {
                action: 'wp_olympiad_delete_response',
                response_id: respId,
                nonce: config.nonce
            }, function (res) {
                if (res.success) {
                    $card.fadeOut(250, function () {
                        $(this).remove();
                    });
                    location.reload();
                } else {
                    alert(res.data ? res.data.message : 'Ошибка при удалении ответа');
                    $btn.prop('disabled', false);
                }
            }).fail(function () {
                alert('Сетевая ошибка при удалении ответа');
                $btn.prop('disabled', false);
            });
        });

        // 13. Password Toggle Button in Settings
        $(document).on('click', '.wpo-toggle-pwd', function (e) {
            e.preventDefault();
            var targetId = $(this).attr('data-target');
            var $input = $('#' + targetId);
            if ($input.length) {
                var currentType = $input.attr('type');
                var newType = (currentType === 'password') ? 'text' : 'password';
                $input.attr('type', newType);
                $(this).text(newType === 'text' ? '🙈' : '👁️');
            }
        });

        // 13b. Toggle Start Page Settings Section Visibility
        $('#enable_intro_page').on('change', function () {
            if ($(this).is(':checked')) {
                $('#wpo-intro-settings-subrows').fadeIn(150);
            } else {
                $('#wpo-intro-settings-subrows').fadeOut(150);
            }
        });

        // Ignore only the copied multi-example hint, never a real /mnt/share
        // or \\server\share path. This mirrors the PHP path handling.
        function networkPathValue($input) {
            if (!$input.length) return '';
            var val = ($input.val() || '').trim();
            var ph = ($input.attr('placeholder') || '').trim();
            var isExample = /\s(?:или|or)\s/i.test(val) &&
                (val.indexOf('server\\share') !== -1 || val.indexOf('/mnt/share') !== -1);
            return !val || val === ph || isExample ? '' : val;
        }

        function getNetworkPath() {
            return networkPathValue($('#network_folder_path'));
        }

        function getBuilderNetworkPath() {
            return networkPathValue($('#wpo-import-network-path'));
        }

        // 14. Test Network Folder Connection (AJAX)
        $('#wpo-btn-test-network').on('click', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var $spinner = $('#wpo-network-test-spinner');
            var $msg = $('#wpo-network-test-message');

            var path = getNetworkPath();

            $btn.prop('disabled', true);
            $spinner.addClass('is-active');
            $msg.hide();

            $.post(config.ajaxUrl, {
                action: 'wp_olympiad_test_network_connection',
                nonce: config.nonce,
                type: $('#network_folder_type').val(),
                path: path,
                user: $('#network_folder_user').val(),
                password: $('#network_folder_password').val()
            }, function (res) {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');
                if (res.data && res.data.editor && window.WPOlympiadEditorStatus) {
                    window.WPOlympiadEditorStatus(res.data.editor);
                }
                if (res.success) {
                    $msg.css({ background: '#f0fdf4', color: '#166534', border: '1px solid #bbf7d0' })
                        .html(res.data.message)
                        .fadeIn();
                } else {
                    $msg.css({ background: '#fef2f2', color: '#991b1b', border: '1px solid #fecaca' })
                        .html('✕ ' + (res.data ? res.data.message : 'Ошибка подключения'))
                        .fadeIn();
                }
            }).fail(function () {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');
                $msg.css({ background: '#fef2f2', color: '#991b1b', border: '1px solid #fecaca' })
                    .html('✕ Сетевая ошибка при попытке проверки папки.')
                    .fadeIn();
            });
        });

        // 15. Run Network Export Now from Quiz Edit Page (AJAX)
        $('#wpo-btn-run-network-export').on('click', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var quizId = parseInt($btn.attr('data-quiz-id'), 10);
            var $spinner = $('#wpo-network-test-spinner');
            var $msg = $('#wpo-network-test-message');

            var path = $('#network_folder_path').val().trim() || $('#network_folder_path').attr('placeholder') || '';

            $btn.prop('disabled', true);
            $spinner.addClass('is-active');
            $msg.hide();

            $.post(config.ajaxUrl, {
                action: 'wp_olympiad_export_to_network',
                nonce: config.nonce,
                quiz_id: quizId,
                override: 1,
                type: $('#network_folder_type').val(),
                path: path,
                user: $('#network_folder_user').val(),
                password: $('#network_folder_password').val()
            }, function (res) {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');
                if (res.success) {
                    var data = res.data || {};
                    var primaryFile = data.primary_file || data.filename || '';
                    var downloadUrl = data.download_url || '';
                    var filesList = data.files_list || [];

                    var html = '✓ <strong>' + data.message + '</strong>';
                    if (primaryFile) {
                        html += '<div style="margin-top:6px; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">' +
                            '<span>Файл: <strong><code>' + primaryFile + '</code></strong></span>';
                        if (downloadUrl) {
                            html += '<a href="' + downloadUrl + '" class="button button-small button-primary" style="margin-left:4px;">📥 Скачать файл</a>';
                        }
                        html += '</div>';
                    }
                    if (filesList && filesList.length > 0) {
                        html += '<div style="font-size:12px; color:#4b5563; margin-top:4px;">Выгруженные файлы: ' + filesList.slice(0, 4).join(', ') + '...</div>';
                    }
                    html += '<div style="font-size:12px; color:#15803d; margin-top:3px;">Файлов: ' + (data.files_count || 1) + ', объём: ' + (Math.round((data.size || 0) / 102.4) / 10) + ' КБ</div>';

                    $msg.css({ background: '#f0fdf4', color: '#166534', border: '1px solid #bbf7d0', padding: '10px 14px', borderRadius: '4px' })
                        .html(html)
                        .fadeIn();
                } else {
                    $msg.css({ background: '#fef2f2', color: '#991b1b', border: '1px solid #fecaca' })
                        .html('✕ ' + (res.data ? res.data.message : 'Ошибка экспорта'))
                        .fadeIn();
                }
            }).fail(function () {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');
                $msg.css({ background: '#fef2f2', color: '#991b1b', border: '1px solid #fecaca' })
                    .html('✕ Сетевая ошибка при выполнении экспорта.')
                    .fadeIn();
            });
        });

        // 15b. Run Network Questions Import Now from Quiz Edit Page (AJAX)
        $('#wpo-btn-run-network-import').on('click', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var quizId = parseInt($btn.attr('data-quiz-id'), 10);
            var $spinner = $('#wpo-network-test-spinner');
            var $msg = $('#wpo-network-test-message');
            var mode = $('#network_auto_import_mode').val() || 'append';
            var file = $('#network_auto_import_file').val() ? $('#network_auto_import_file').val().trim() : '';

            if (mode === 'replace') {
                if (!confirm('Внимание: Выбран режим «Заменять все вопросы». Все существующие задания этой олимпиады будут удалены и перезаписаны из сетевого файла. Продолжить?')) {
                    return;
                }
            }

            var origText = $btn.text();
            $btn.prop('disabled', true).text('⏳ Импорт заданий...');
            if ($spinner.length) $spinner.addClass('is-active');
            $msg.hide();

            var path = $('#network_folder_path').val() ? $('#network_folder_path').val().trim() : '';

            $.post(config.ajaxUrl, {
                action: 'wp_olympiad_run_network_import',
                nonce: config.nonce,
                quiz_id: quizId,
                mode: mode,
                filename: file,
                force: 0, // Check now, but do not append the same file again after a concurrent cron job.
                path: path,
                type: $('#network_folder_type').val(),
                user: $('#network_folder_user').val(),
                password: $('#network_folder_password').val()
            }, function (res) {
                $btn.prop('disabled', false).text(origText);
                if ($spinner.length) $spinner.removeClass('is-active');
                if (res.success && res.data) {
                    var data = res.data;
                    var html = '✓ <strong>' + wpoEsc(data.message) + '</strong>';
                    if (data.filename) {
                        html += '<div style="margin-top:6px; font-size:12px; color:#1e293b;">' +
                            'Файл: <strong><code>' + wpoEsc(data.filename) + '</code></strong> | ' +
                            'Импортировано: <strong>' + (data.imported_count || 0) + '</strong> | ' +
                            'Всего в тесте: <strong>' + (data.total_count || 0) + '</strong>' +
                            '</div>';
                    }
                    $msg.css({ background: '#f0fdf4', color: '#166534', border: '1px solid #bbf7d0', padding: '10px 14px', borderRadius: '4px' })
                        .html(html)
                        .fadeIn();
                } else {
                    $msg.css({ background: '#fef2f2', color: '#991b1b', border: '1px solid #fecaca' })
                        .html('✕ ' + wpoEsc(res.data ? res.data.message : 'Ошибка импорта заданий.'))
                        .fadeIn();
                }
            }).fail(function () {
                $btn.prop('disabled', false).text(origText);
                if ($spinner.length) $spinner.removeClass('is-active');
                $msg.css({ background: '#fef2f2', color: '#991b1b', border: '1px solid #fecaca' })
                    .html('✕ Сетевая ошибка при попытке импорта заданий.')
                    .fadeIn();
            });
        });


        // 15f. Кнопка выгрузки вопросов удалена в 1.0.16 (экспорт вопросов больше не доступен из интерфейса).

        // 16. Questions Import Modal & Handlers (Desktop Upload + Network Folder)
        function scanNetworkImportFiles() {
            var quizId = parseInt($('#wpo-questions-list').attr('data-quiz-id'), 10);
            if (!quizId) return;

            var $sel = $('#wpo-import-discovered-files');
            var $manual = $('#wpo-import-manual-filename');
            var previousAuto = $sel.data('autoSelectedPath');
            if (previousAuto && $manual.val().trim() === previousAuto) $manual.val('');
            $sel.removeData('autoSelectedPath');
            var customPath = getBuilderNetworkPath();
            $sel.html('<option value="">⏳ Поиск файлов заданий в import/...</option>').prop('disabled', true);

            $.post(config.ajaxUrl, {
                action: 'wp_olympiad_scan_network_import',
                nonce: config.nonce,
                quiz_id: quizId,
                path: customPath
            }, function (res) {
                $sel.prop('disabled', false);
                if (res.success && res.data) {
                    var files = res.data.files || [];
                    $sel.empty();

                    if (files.length === 0) {
                        $sel.append('<option value="">— Файлы заданий (.xlsm, .xlsx, .csv, .json, .tsv) не найдены —</option>');
                        // Показываем, что именно находится в подключенной папке
                        if (res.data.diagnostics) {
                            var $hint = $('#wpo-import-scan-diagnostics');
                            if (!$hint.length) {
                                $sel.closest('.wpo-field').append('<p id="wpo-import-scan-diagnostics" style="margin:6px 0 0; font-size:12px; color:#92400e; background:#fef3c7; border:1px solid #fde68a; border-radius:6px; padding:8px 10px;"></p>');
                                $hint = $('#wpo-import-scan-diagnostics');
                            }
                            $hint.text(res.data.diagnostics).show();
                        }
                    } else {
                        $('#wpo-import-scan-diagnostics').hide();
                        $sel.append('<option value="">— Выберите найденный файл для импорта —</option>');
                        files.forEach(function (f) {
                            var sizeKb = Math.round((f.size || 0) / 102.4) / 10;
                            var label = '📄 ' + f.path + ' (' + sizeKb + ' КБ' + (f.modified ? ', ' + f.modified : '') + ')';
                            $('<option>').val(f.path).text(label).appendTo($sel);
                        });
                        // The server applies the same priority as automatic import.
                        var firstMatch = files[0];
                        if (firstMatch) {
                            $sel.val(firstMatch.path).data('autoSelectedPath', firstMatch.path);
                            $manual.val(firstMatch.path);
                        }
                    }
                } else {
                    $sel.html('<option value="">Ошибка сканирования папки</option>');
                }
            }).fail(function () {
                $sel.prop('disabled', false).html('<option value="">Сетевая ошибка при сканировании</option>');
            });
        }

        $(document).on('click', '#wpo-open-import-modal-btn', function () {
            $('#wpo-import-status-box').hide();
            $('#wpo-network-import-modal').fadeIn(150);
        });

        $(document).on('click', '#wpo-close-import-modal-btn, #wpo-btn-cancel-import-modal', function () {
            $('#wpo-network-import-modal').fadeOut(150);
        });

        $(document).on('click', '.wpo-import-tab-btn', function () {
            var tab = $(this).attr('data-tab');
            $('.wpo-import-tab-btn').removeClass('button-primary').addClass('button-secondary');
            $(this).removeClass('button-secondary').addClass('button-primary');
            $('.wpo-import-panel').hide();
            $('#wpo-import-panel-' + tab).show();
            if (tab === 'network') {
                scanNetworkImportFiles();
            }
        });

        $(document).on('change', '#wpo-import-file-input', function () {
            var file = this.files && this.files[0];
            if (file) {
                var sizeKb = Math.round(file.size / 102.4) / 10;
                $('#wpo-import-selected-filename').html('📄 <span style="color:#0284c7;">' + wpoEsc(file.name) + '</span> (' + sizeKb + ' КБ)');
            } else {
                $('#wpo-import-selected-filename').text('Файл не выбран. Нажмите кнопку выше для выбора файла.');
            }
        });

        $(document).on('click', '#wpo-btn-rescan-network', function () {
            scanNetworkImportFiles();
        });

        $(document).on('change', '#wpo-import-discovered-files', function () {
            var val = $(this).val();
            if (val) {
                $(this).data('autoSelectedPath', val);
                $('#wpo-import-manual-filename').val(val);
            }
        });

        $(document).on('click', '#wpo-btn-run-builder-import', function () {
            var quizId = parseInt($('#wpo-questions-list').attr('data-quiz-id'), 10);
            var mode = $('input[name="wpo_import_mode"]:checked').val() || 'append';
            var $status = $('#wpo-import-status-box');
            var $btn = $(this);
            var isUploadTab = $('#wpo-import-panel-upload').is(':visible');

            if (mode === 'replace') {
                if (!confirm('ВНИМАНИЕ! Все текущие вопросы олимпиады будут удалены и заменены вопросами из импортируемого файла. Продолжить?')) {
                    return;
                }
            }

            if (isUploadTab) {
                // Direct file upload from user computer
                var fileInput = document.getElementById('wpo-import-file-input');
                if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
                    alert('Пожалуйста, выберите файл с вопросами (.xlsm, .xlsx, .csv, .json) на вашем компьютере.');
                    return;
                }

                var file = fileInput.files[0];
                var formData = new FormData();
                formData.append('action', 'wp_olympiad_upload_import_file');
                formData.append('nonce', config.nonce);
                formData.append('quiz_id', quizId);
                formData.append('mode', mode);
                formData.append('hide_points', $('#wpo-import-hide-points').is(':checked') ? '1' : '0');
                formData.append('import_file', file);

                $btn.prop('disabled', true).text('Загрузка и импорт...');
                $status.css({ background: '#f8fafc', color: '#334155', border: '1px solid #cbd5e1' })
                    .html('⏳ Чтение файла и импорт вопросов...')
                    .slideDown(150);

                $.ajax({
                    url: config.ajaxUrl,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function (res) {
                        $btn.prop('disabled', false).text('📥 Запустить импорт заданий');
                        if (res.success && res.data) {
                            var msg = '✓ <strong>' + wpoEsc(res.data.message) + '</strong>';
                            if (res.data.details && res.data.details.length > 0) {
                                msg += '<div style="margin-top:6px; font-size:12px; color:#166534; max-height:80px; overflow-y:auto;">' +
                                    res.data.details.slice(0, 5).map(wpoEsc).join('<br>') +
                                    (res.data.details.length > 5 ? '<br><em>... и ещё ' + (res.data.details.length - 5) + '</em>' : '') +
                                '</div>';
                            }
                            msg += '<div style="margin-top:8px; font-size:12px; color:#15803d; font-weight:600;">Страница сейчас обновится...</div>';

                            $status.css({ background: '#f0fdf4', color: '#166534', border: '1px solid #86efac' }).html(msg);

                            setTimeout(function () {
                                location.reload();
                            }, 1400);
                        } else {
                            $status.css({ background: '#fef2f2', color: '#991b1b', border: '1px solid #fca5a5' })
                                .html('✕ <strong>Ошибка импорта:</strong> ' + wpoEsc(res.data ? res.data.message : 'Не удалось распознать задания'));
                        }
                    },
                    error: function () {
                        $btn.prop('disabled', false).text('📥 Запустить импорт заданий');
                        $status.css({ background: '#fef2f2', color: '#991b1b', border: '1px solid #fca5a5' })
                            .html('✕ <strong>Сетевая ошибка</strong> при загрузке файла на сервер.');
                    }
                });
            } else {
                // Import from network share
                var filename = $('#wpo-import-manual-filename').val().trim() || $('#wpo-import-discovered-files').val() || '';
                var customPath = getBuilderNetworkPath();

                if (!filename) {
                    alert('Пожалуйста, выберите или укажите имя файла для импорта из сетевой папки.');
                    return;
                }

                $btn.prop('disabled', true).text('Выполняется импорт...');
                $status.css({ background: '#f8fafc', color: '#334155', border: '1px solid #cbd5e1' })
                    .html('⏳ Чтение шаблона заданий из сетевой папки...')
                    .slideDown(150);

                $.post(config.ajaxUrl, {
                    action: 'wp_olympiad_import_from_network',
                    nonce: config.nonce,
                    quiz_id: quizId,
                    filename: filename,
                    mode: mode,
                    path: customPath,
                    hide_points: $('#wpo-import-hide-points').is(':checked') ? 1 : 0
                }, function (res) {
                    $btn.prop('disabled', false).text('📥 Запустить импорт заданий');
                    if (res.success && res.data) {
                        var msg = '✓ <strong>' + wpoEsc(res.data.message) + '</strong>';
                        if (res.data.details && res.data.details.length > 0) {
                            msg += '<div style="margin-top:6px; font-size:12px; color:#166534; max-height:80px; overflow-y:auto;">' +
                                res.data.details.slice(0, 5).map(wpoEsc).join('<br>') +
                                (res.data.details.length > 5 ? '<br><em>... и ещё ' + (res.data.details.length - 5) + '</em>' : '') +
                            '</div>';
                        }
                        msg += '<div style="margin-top:8px; font-size:12px; color:#15803d; font-weight:600;">Страница сейчас обновится...</div>';

                        $status.css({ background: '#f0fdf4', color: '#166534', border: '1px solid #86efac' })
                            .html(msg);

                        setTimeout(function () {
                            location.reload();
                        }, 1400);
                    } else {
                        $status.css({ background: '#fef2f2', color: '#991b1b', border: '1px solid #fca5a5' })
                            .html('✕ <strong>Ошибка импорта:</strong> ' + wpoEsc(res.data ? res.data.message : 'Неизвестная ошибка'));
                    }
                }).fail(function () {
                    $btn.prop('disabled', false).text('📥 Запустить импорт заданий');
                    $status.css({ background: '#fef2f2', color: '#991b1b', border: '1px solid #fca5a5' })
                        .html('✕ <strong>Сетевая ошибка</strong> при обращении к серверу.');
                });
            }
        });

        /* ------------------------------------------------------------------
         * Проверка файла заданий перед импортом (dry-run, без записи в базу)
         * ------------------------------------------------------------------ */
        function wpoEsc(value) {
            return $('<div>').text(value === undefined || value === null ? '' : String(value)).html();
        }

        function wpoRenderImportReport($target, data) {
            if (!$target || !$target.length) { return; }

            var stats = data.stats || {};
            var report = data.report || [];
            var statusColors = {
                ok:      { bg: '#dcfce7', color: '#15803d', label: '✓ Готово' },
                warning: { bg: '#fef9c3', color: '#a16207', label: '⚠ Внимание' },
                error:   { bg: '#fee2e2', color: '#b91c1c', label: '✕ Ошибка' }
            };

            var html = '<div style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; padding:14px 16px;">';
            html += '<div style="font-weight:700; color:#1e293b; margin-bottom:8px;">🔎 Отчёт о проверке файла: ' + wpoEsc(data.source || '') + '</div>';
            html += '<div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:10px; font-size:12px;">';
            html += '<span style="background:#e2e8f0; color:#334155; padding:3px 10px; border-radius:12px;">Строк: <strong>' + (stats.total || 0) + '</strong></span>';
            html += '<span style="background:#dcfce7; color:#15803d; padding:3px 10px; border-radius:12px;">К импорту: <strong>' + (stats.importable || 0) + '</strong></span>';
            html += '<span style="background:#fef9c3; color:#a16207; padding:3px 10px; border-radius:12px;">Предупреждений: <strong>' + (stats.warnings || 0) + '</strong></span>';
            html += '<span style="background:#fee2e2; color:#b91c1c; padding:3px 10px; border-radius:12px;">Ошибок: <strong>' + (stats.errors || 0) + '</strong></span>';
            html += '</div>';

            if (data.message) {
                html += '<div style="font-size:12px; color:#475569; margin-bottom:10px;">' + wpoEsc(data.message) + '</div>';
            }

            if (report.length > 0) {
                html += '<div style="max-height:340px; overflow:auto; border:1px solid #e2e8f0; border-radius:6px; background:#fff;">';
                html += '<table class="widefat striped" style="border:0; margin:0; font-size:12px;">';
                html += '<thead><tr><th style="width:52px;">Стр.</th><th style="width:110px;">Тип</th><th style="width:96px;">Статус</th><th>Задание и замечания</th></tr></thead><tbody>';

                report.slice(0, 300).forEach(function (row) {
                    var st = statusColors[row.status] || statusColors.warning;
                    var messages = (row.messages || []).map(function (m) {
                        return '<div style="font-size:11px; margin-top:2px;">' + wpoEsc(m) + '</div>';
                    }).join('');
                    html += '<tr>';
                    html += '<td style="text-align:center; color:#64748b;">' + wpoEsc(row.row) + '</td>';
                    html += '<td><code style="font-size:11px;">' + wpoEsc(row.type || '—') + '</code></td>';
                    html += '<td><span style="display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; background:' + st.bg + '; color:' + st.color + ';">' + st.label + '</span></td>';
                    html += '<td><div style="color:#0f172a;">' + wpoEsc(row.title || '') + '</div>' + messages + '</td>';
                    html += '</tr>';
                });

                html += '</tbody></table></div>';
                if (report.length > 300) {
                    html += '<div style="font-size:11px; color:#64748b; margin-top:6px;">Показаны первые 300 строк из ' + report.length + '.</div>';
                }
            }

            html += '</div>';
            $target.html(html).slideDown(150);
        }

        // Проверка файла из сетевой папки на странице олимпиады
        $(document).on('click', '#wpo-btn-validate-import', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var $target = $('#wpo-import-report');
            var $spinner = $('#wpo-network-test-spinner');
            var origText = $btn.text();

            $btn.prop('disabled', true).text('⏳ Проверка файла...');
            if ($spinner.length) $spinner.addClass('is-active');

            $.post(config.ajaxUrl, {
                action: 'wp_olympiad_validate_import_file',
                nonce: config.nonce,
                quiz_id: parseInt($btn.attr('data-quiz-id'), 10) || 0,
                filename: $('#network_auto_import_file').val() || '',
                path: $('#network_folder_path').val() || '',
                type: $('#network_folder_type').val() || '',
                user: $('#network_folder_user').val() || '',
                password: $('#network_folder_password').val() || ''
            }, function (res) {
                $btn.prop('disabled', false).text(origText);
                if ($spinner.length) $spinner.removeClass('is-active');
                if (res.success && res.data) {
                    wpoRenderImportReport($target, res.data);
                } else {
                    $target.html('<div style="background:#fef2f2; color:#991b1b; border:1px solid #fecaca; border-radius:6px; padding:10px 14px; font-size:13px;">✕ ' +
                        wpoEsc(res.data && res.data.message ? res.data.message : 'Не удалось проверить файл заданий.') + '</div>').slideDown(150);
                }
            }).fail(function () {
                $btn.prop('disabled', false).text(origText);
                if ($spinner.length) $spinner.removeClass('is-active');
                $target.html('<div style="background:#fef2f2; color:#991b1b; border:1px solid #fecaca; border-radius:6px; padding:10px 14px; font-size:13px;">✕ Сетевая ошибка при проверке файла.</div>').slideDown(150);
            });
        });

        // Проверка файла в окне импорта конструктора вопросов
        $(document).on('click', '#wpo-btn-validate-builder-import', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var $target = $('#wpo-import-report-box');
            var quizId = parseInt($('#wpo-questions-list').attr('data-quiz-id'), 10) || 0;
            var origText = $btn.text();
            var isUploadTab = $('#wpo-import-panel-upload').is(':visible');
            var fileInput = document.getElementById('wpo-import-file-input');

            $btn.prop('disabled', true).text('⏳ Проверка файла...');
            $target.hide();

            if (isUploadTab) {
                if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
                    $btn.prop('disabled', false).text(origText);
                    alert('Пожалуйста, выберите файл с вопросами (.xlsm, .xlsx, .csv, .json) на вашем компьютере.');
                    return;
                }

                var formData = new FormData();
                formData.append('action', 'wp_olympiad_validate_import_file');
                formData.append('nonce', config.nonce);
                formData.append('quiz_id', quizId);
                formData.append('import_file', fileInput.files[0]);

                $.ajax({
                    url: config.ajaxUrl,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function (res) {
                        $btn.prop('disabled', false).text(origText);
                        if (res.success && res.data) {
                            wpoRenderImportReport($target, res.data);
                        } else {
                            $target.html('<div style="background:#fef2f2; color:#991b1b; border:1px solid #fecaca; border-radius:6px; padding:10px 14px; font-size:13px;">✕ ' +
                                wpoEsc(res.data && res.data.message ? res.data.message : 'Не удалось проверить файл.') + '</div>').slideDown(150);
                        }
                    },
                    error: function () {
                        $btn.prop('disabled', false).text(origText);
                        $target.html('<div style="background:#fef2f2; color:#991b1b; border:1px solid #fecaca; border-radius:6px; padding:10px 14px; font-size:13px;">✕ Сетевая ошибка при проверке файла.</div>').slideDown(150);
                    }
                });
                return;
            }

            var filename = $('#wpo-import-manual-filename').val().trim() || $('#wpo-import-discovered-files').val() || '';
            if (!filename) {
                $btn.prop('disabled', false).text(origText);
                alert('Пожалуйста, выберите или укажите имя файла для проверки из сетевой папки.');
                return;
            }

            $.post(config.ajaxUrl, {
                action: 'wp_olympiad_validate_import_file',
                nonce: config.nonce,
                quiz_id: quizId,
                filename: filename,
                path: getBuilderNetworkPath()
            }, function (res) {
                $btn.prop('disabled', false).text(origText);
                if (res.success && res.data) {
                    wpoRenderImportReport($target, res.data);
                } else {
                    $target.html('<div style="background:#fef2f2; color:#991b1b; border:1px solid #fecaca; border-radius:6px; padding:10px 14px; font-size:13px;">✕ ' +
                        wpoEsc(res.data && res.data.message ? res.data.message : 'Не удалось проверить файл в сетевой папке.') + '</div>').slideDown(150);
                }
            }).fail(function () {
                $btn.prop('disabled', false).text(origText);
                $target.html('<div style="background:#fef2f2; color:#991b1b; border:1px solid #fecaca; border-radius:6px; padding:10px 14px; font-size:13px;">✕ Сетевая ошибка при проверке файла.</div>').slideDown(150);
            });
        });
    });
})(jQuery);

/**
 * ---------------------------------------------------------------------------
 *  Appearance (Styling) of the testing page — live preview on the quiz editor
 * ---------------------------------------------------------------------------
 */
(function ($) {
    'use strict';

    var config = window.WPOlympiadAdmin || {};
    $(function () {
        if (!$('#wpo-style-card').length) return;

        // ─── Внешнее подключение: ключ API и webhook ───────────────────────
        var apiKeyRevealed = false;
        var webhookSecretRevealed = false;

        function wpoCopyText(text, $btn) {
            var original = $btn ? $btn.text() : '';
            var finish = function () {
                if (!$btn) return;
                $btn.text('✓ Скопировано');
                setTimeout(function () { $btn.text(original); }, 1600);
            };
            var fallback = function () {
                var $tmp = $('<textarea>').val(text).css({ position: 'fixed', top: '-1000px', opacity: 0 }).appendTo('body');
                $tmp[0].select();
                try { document.execCommand('copy'); } catch (e) {}
                $tmp.remove();
                finish();
            };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(finish, fallback);
            } else {
                fallback();
            }
        }

        function getApiKey() {
            return String($('#wpo-api-key').attr('data-key') || '');
        }

        function setApiKey(key, masked) {
            var $input = $('#wpo-api-key');
            if (!$input.length) return;
            key = key || '';
            masked = masked || '';
            $input.attr('data-key', key).attr('data-masked', masked);
            $input.val(apiKeyRevealed ? key : masked);
            $('#wpo-api-key-toggle, #wpo-api-key-copy').prop('disabled', !key);
        }

        function ensureRevokeButton(quizId) {
            if ($('#wpo-api-key-revoke').length) return;
            $('<button type="button" class="button button-link-delete" id="wpo-api-key-revoke">Отозвать</button>')
                .attr('data-quiz-id', quizId)
                .appendTo($('#wpo-api-key').closest('.wpo-api-key-box'));
        }

        // Показать / скрыть ключ API.
        $(document).on('click', '#wpo-api-key-toggle', function () {
            var $input = $('#wpo-api-key');
            var key = String($input.attr('data-key') || '');
            var masked = String($input.attr('data-masked') || '');
            apiKeyRevealed = !apiKeyRevealed;
            $input.val(apiKeyRevealed ? key : masked);
            $(this).text(apiKeyRevealed ? '🙈 Скрыть' : '👁 Показать');
        });

        // Скопировать ключ API.
        $(document).on('click', '#wpo-api-key-copy', function () {
            var key = getApiKey();
            if (key) wpoCopyText(key, $(this));
        });

        // Сгенерировать / перевыпустить ключ API.
        $(document).on('click', '#wpo-api-key-generate', function () {
            var $btn = $(this);
            var op = String($btn.data('op') || 'generate');
            var quizId = parseInt($btn.data('quiz-id'), 10) || 0;
            if (!quizId) return;

            var question = op === 'regenerate'
                ? 'Перевыпустить ключ API? Старый ключ сразу перестанет работать во внешних системах.'
                : 'Сгенерировать ключ API для этой олимпиады?';
            if (!window.confirm(question)) return;

            $btn.prop('disabled', true);
            $.post(config.ajaxUrl, {
                action: 'wp_olympiad_api_key',
                nonce: config.nonce,
                quiz_id: quizId,
                op: op
            }, function (res) {
                $btn.prop('disabled', false);
                if (!res || !res.success) {
                    window.alert(res && res.data && res.data.message ? res.data.message : 'Не удалось создать ключ API.');
                    return;
                }
                apiKeyRevealed = true;
                setApiKey(res.data.key, res.data.masked);
                $('#wpo-api-key-toggle').prop('disabled', false).text('🙈 Скрыть');
                $('#wpo-api-status').html(res.data.status_html || '');
                $('#wpo-api-enabled').prop('checked', true);
                $btn.data('op', 'regenerate').text('♻️ Перевыпустить');
                ensureRevokeButton(quizId);
            }).fail(function () {
                $btn.prop('disabled', false);
                window.alert('Сетевая ошибка при создании ключа API.');
            });
        });

        // Отозвать ключ API.
        $(document).on('click', '#wpo-api-key-revoke', function () {
            if (!window.confirm('Отозвать ключ API? Все внешние интеграции этой олимпиады перестанут работать.')) {
                return;
            }
            var $btn = $(this);
            var quizId = parseInt($btn.data('quiz-id'), 10) || 0;
            $btn.prop('disabled', true);

            $.post(config.ajaxUrl, {
                action: 'wp_olympiad_api_key',
                nonce: config.nonce,
                quiz_id: quizId,
                op: 'revoke'
            }, function (res) {
                if (!res || !res.success) {
                    $btn.prop('disabled', false);
                    window.alert(res && res.data && res.data.message ? res.data.message : 'Не удалось отозвать ключ API.');
                    return;
                }
                apiKeyRevealed = false;
                setApiKey('', '');
                $('#wpo-api-key-toggle').prop('disabled', true).text('👁 Показать');
                $('#wpo-api-status').html('<span style="color:#991b1b;">' + (res.data.status_html || 'Ключ отозван.') + '</span>');
                $('#wpo-api-key-generate').data('op', 'generate').text('⚡ Сгенерировать ключ');
                $('#wpo-api-enabled').prop('checked', false);
                $btn.remove();
            }).fail(function () {
                $btn.prop('disabled', false);
                window.alert('Сетевая ошибка при отзыве ключа API.');
            });
        });

        // Проверка API запросом GET /external/ping.
        $(document).on('click', '#wpo-api-test', function () {
            var $btn = $(this);
            var $spinner = $('#wpo-api-test-spinner');
            var $out = $('#wpo-api-test-result');
            var key = getApiKey();

            if (!key) {
                $out.removeClass('is-ok').addClass('is-fail').text('✕ Сначала сгенерируйте ключ API.').show();
                return;
            }

            $btn.prop('disabled', true);
            $spinner.addClass('is-active');
            $out.hide();

            $.ajax({
                url: (config.restRoot || '') + 'external/ping',
                method: 'GET',
                dataType: 'json',
                headers: { 'X-API-Key': key }
            }).done(function (data) {
                var quiz = data && data.quiz ? data.quiz : null;
                var lines = ['✓ Подключение установлено'];
                lines.push('Сайт: ' + (data.site_url || ''));
                lines.push('Плагин: ' + (data.plugin || 'wp-olympiad') + ' ' + (data.version || ''));
                if (quiz) {
                    lines.push('Олимпиада: #' + quiz.id + ' «' + quiz.title + '»');
                    lines.push('Вопросов: ' + quiz.questions_count + ' · макс. балл: ' + quiz.total_points + ' · открыта: ' + (quiz.is_open ? 'да' : 'нет'));
                }
                $out.removeClass('is-fail').addClass('is-ok').text(lines.join('\n')).show();
            }).fail(function (xhr) {
                var msg = 'HTTP ' + xhr.status;
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    msg += ': ' + xhr.responseJSON.message;
                }
                $out.removeClass('is-ok').addClass('is-fail').text('✕ ' + msg).show();
            }).always(function () {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');
            });
        });

        // Копирование адресов эндпоинтов и примера запроса.
        $(document).on('click', '.wpo-copy-endpoint', function () {
            wpoCopyText(String($(this).data('copy') || ''), $(this));
        });

        $(document).on('click', '#wpo-api-copy-example', function () {
            var text = $('#wpo-api-example').text();
            var key = getApiKey();
            if (key) {
                text = text.replace('<КЛЮЧ_API>', key);
            }
            wpoCopyText(text, $(this));
            if (!key) {
                window.alert('Ключ ещё не сгенерирован — в примере остался плейсхолдер <КЛЮЧ_API>.');
            }
        });

        // ─── Секрет подписи webhook ────────────────────────────────────────
        $(document).on('click', '#wpo-webhook-secret-toggle', function () {
            var $input = $('#wpo-webhook-secret');
            var secret = String($input.attr('data-secret') || '');
            var masked = String($input.attr('data-masked') || '');
            webhookSecretRevealed = !webhookSecretRevealed;
            $input.val(webhookSecretRevealed ? secret : masked);
            $(this).text(webhookSecretRevealed ? '🙈 Скрыть' : '👁 Показать');
        });

        $(document).on('click', '#wpo-webhook-secret-copy', function () {
            var secret = String($('#wpo-webhook-secret').attr('data-secret') || '');
            if (secret) wpoCopyText(secret, $(this));
        });

        $(document).on('click', '#wpo-webhook-secret-generate', function () {
            if (!window.confirm('Перевыпустить секрет подписи? Приёмник нужно обновить, иначе подписи перестанут совпадать.')) {
                return;
            }
            var $btn = $(this);
            var quizId = parseInt($btn.data('quiz-id'), 10) || 0;
            $btn.prop('disabled', true);

            $.post(config.ajaxUrl, {
                action: 'wp_olympiad_webhook_action',
                nonce: config.nonce,
                quiz_id: quizId,
                op: 'regenerate_secret'
            }, function (res) {
                $btn.prop('disabled', false);
                if (!res || !res.success) {
                    window.alert(res && res.data && res.data.message ? res.data.message : 'Не удалось создать секрет.');
                    return;
                }
                var $input = $('#wpo-webhook-secret');
                $input.attr('data-secret', res.data.secret).attr('data-masked', res.data.masked).val(res.data.masked);
                webhookSecretRevealed = false;
                $('#wpo-webhook-secret-toggle, #wpo-webhook-secret-copy').prop('disabled', false);
                $('#wpo-webhook-secret-toggle').text('👁 Показать');
                window.alert(res.data.message);
            }).fail(function () {
                $btn.prop('disabled', false);
                window.alert('Сетевая ошибка при создании секрета.');
            });
        });

        // ─── Тестовая отправка и журнал webhook ────────────────────────────
        function webhookLogRow(entry) {
            var ok = entry.ok;
            var cls = ok === true ? 'wpo-log-ok' : (ok === false ? 'wpo-log-fail' : 'wpo-log-queued');
            var label = ok === true ? '✓ Доставлено' : (ok === false ? '✕ Ошибка' : '… В очереди');
            var code = parseInt(entry.code, 10) || 0;

            var $row = $('<tr>');
            $row.append($('<td>').text(entry.time || '—'));

            var $event = $('<td>').append($('<code>').text(entry.event || ''));
            if (entry.test) {
                $event.append(' ').append($('<span class="wpo-badge">').text('тест'));
            }
            $row.append($event);

            $row.append($('<td>').text(parseInt(entry.try, 10) || 1));
            $row.append($('<td>').text(code > 0 ? code : '—'));
            $row.append($('<td>').text(parseInt(entry.duration_ms, 10) || 0));

            var $result = $('<td>')
                .append($('<span>').addClass(cls).text(label))
                .append('<br>')
                .append($('<span class="description">').text(entry.message || ''));
            $row.append($result);

            return $row;
        }

        $(document).on('click', '#wpo-webhook-test', function () {
            var $btn = $(this);
            var $spinner = $('#wpo-webhook-test-spinner');
            var $out = $('#wpo-webhook-test-result');
            var quizId = parseInt($btn.data('quiz-id'), 10) || 0;

            var currentUrl = String($('#webhook_url').val() || '').trim();
            var savedUrl = String($('#webhook_url').attr('data-saved-url') || '').trim();

            if (!savedUrl) {
                $out.removeClass('is-ok').addClass('is-fail')
                    .text('✕ Сначала укажите URL webhook и сохраните настройки олимпиады.')
                    .show();
                return;
            }
            if (currentUrl !== savedUrl) {
                $out.removeClass('is-ok').addClass('is-fail')
                    .text('✕ URL изменён, но не сохранён. Нажмите «Сохранить изменения» и повторите проверку.')
                    .show();
                return;
            }

            $btn.prop('disabled', true);
            $spinner.addClass('is-active');
            $out.hide();

            $.post(config.ajaxUrl, {
                action: 'wp_olympiad_webhook_action',
                nonce: config.nonce,
                quiz_id: quizId,
                op: 'test'
            }, function (res) {
                if (!res || !res.success) {
                    $out.removeClass('is-ok').addClass('is-fail')
                        .text('✕ ' + (res && res.data && res.data.message ? res.data.message : 'Не удалось отправить webhook.'))
                        .show();
                    return;
                }

                var data = res.data;
                var signature = String(data.signature || '');
                if (signature.length > 30) {
                    signature = signature.substring(0, 30) + '…';
                }
                var lines = [
                    data.ok ? '✓ Webhook доставлен' : '✕ Webhook не доставлен',
                    'HTTP: ' + (parseInt(data.code, 10) || '—'),
                    'Время ответа: ' + (parseInt(data.duration_ms, 10) || 0) + ' мс',
                    'Подпись: ' + (signature || '—'),
                    'Ответ приёмника: ' + (data.message || '')
                ];
                $out.removeClass('is-ok is-fail')
                    .addClass(data.ok ? 'is-ok' : 'is-fail')
                    .text(lines.join('\n'))
                    .show();

                if (data.entry) {
                    $('#wpo-webhook-log-body .wpo-webhook-log-empty').remove();
                    $('#wpo-webhook-log-body').prepend(webhookLogRow(data.entry));
                    $('#wpo-webhook-log-body tr').slice(8).remove();
                }
            }).fail(function () {
                $out.removeClass('is-ok').addClass('is-fail')
                    .text('✕ Сетевая ошибка при отправке тестового webhook.')
                    .show();
            }).always(function () {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');
            });
        });

        $(document).on('click', '#wpo-webhook-clear-log', function () {
            if (!window.confirm('Очистить журнал доставок?')) {
                return;
            }
            var $btn = $(this);
            var quizId = parseInt($btn.data('quiz-id'), 10) || 0;
            $btn.prop('disabled', true);

            $.post(config.ajaxUrl, {
                action: 'wp_olympiad_webhook_action',
                nonce: config.nonce,
                quiz_id: quizId,
                op: 'clear_log'
            }, function (res) {
                $btn.prop('disabled', false);
                if (!res || !res.success) {
                    window.alert(res && res.data && res.data.message ? res.data.message : 'Не удалось очистить журнал.');
                    return;
                }
                $('#wpo-webhook-log-body').html(
                    '<tr class="wpo-webhook-log-empty"><td colspan="6">Доставок пока не было. Завершите попытку или отправьте тестовый webhook.</td></tr>'
                );
            }).fail(function () {
                $btn.prop('disabled', false);
                window.alert('Сетевая ошибка при очистке журнала.');
            });
        });

    });
})(jQuery);

// Bundled desktop editor: downloads are ordinary protected attachments, EXE is never run here.
(function ($) {
    'use strict';
    var config = window.WPOlympiadAdmin || {};
    window.WPOlympiadEditorStatus = function (result) {
        if (!result) return;
        $('.wpo-desktop-editor-status')
            .removeClass('is-success is-error is-pending')
            .addClass(result.pending ? 'is-pending' : (result.success ? 'is-success' : 'is-error'))
            .text(result.message || (result.success ? 'Редактор размещён в import.' : 'Не удалось разместить редактор.'));
    };
    $(document).on('click', '.wpo-editor-sync', function (e) {
        e.preventDefault();
        var $buttons = $('.wpo-editor-sync');
        var data = { action: 'wp_olympiad_sync_desktop_editor', nonce: config.nonce, quiz_id: $(this).data('quiz-id') || 0 };
        if ($('#network_folder_path').length) {
            data.type = $('#network_folder_type').val() || 'auto';
            data.path = $('#network_folder_path').val() || '';
            data.user = $('#network_folder_user').val() || '';
            data.password = $('#network_folder_password').val() || '';
        } else if ($('#wpo-import-network-path').length && ($('#wpo-import-network-path').val() || '').trim()) {
            data.path = $('#wpo-import-network-path').val();
        }
        $buttons.prop('disabled', true);
        window.WPOlympiadEditorStatus({ pending: true, message: 'Размещаем редактор в import…' });
        $.post(config.ajaxUrl, data).done(function (res) {
            window.WPOlympiadEditorStatus(res.data || { success: false, message: 'Сервер не вернул результат размещения.' });
        }).fail(function () {
            window.WPOlympiadEditorStatus({ success: false, message: 'Сетевая ошибка. Проверьте соединение и повторите размещение.' });
        }).always(function () { $buttons.prop('disabled', false); });
    });
})(jQuery);

// Live background-import diagnostics; never discard a builder draft automatically.
(function ($) {
    'use strict';
    $(function () {
        var $panels = $('.wpo-network-sync-status');
        if (!$panels.length) return;
        var config = window.WPOlympiadAdmin || {};
        var quizId = parseInt($panels.first().attr('data-quiz-id'), 10) || 0;
        var revision = $panels.first().attr('data-revision') || '';
        var builder = $('#wpo-questions-list').length > 0;
        var dirty = false, reloading = false, pending = false, timer = null;
        function draft() {
            var fields = [];
            $('#wpo-questions-list :input, #wpo-shared-texts-modal :input').each(function () {
                fields.push([this.type, !!this.checked, $(this).val()]);
            });
            return JSON.stringify(fields);
        }
        var initialDraft = builder ? draft() : '';
        function editing() {
            return builder && (dirty || draft() !== initialDraft || $('.wpo-modal-overlay:visible').length > 0 ||
                $(document.activeElement).is('#wpo-questions-list :input, #wpo-shared-texts-modal :input'));
        }
        $(document).on('input.wpoSync change.wpoSync', '#wpo-questions-list :input, #wpo-shared-texts-modal :input', function () { dirty = true; });
        $(document).on('click.wpoSync', '#wpo-add-new-q-btn, .wpo-btn-del-question, .wpo-btn-add-choice, .wpo-btn-del-choice, .wpo-btn-add-matching, .wpo-btn-del-matching, .wpo-btn-add-ordering, .wpo-btn-del-ordering', function () { dirty = true; });
        function refreshList() {
            if (editing()) { $panels.find('.wpo-sync-updated').prop('hidden', false); return; }
            if (!reloading) { reloading = true; window.location.reload(); }
        }
        function applyStatus(data) {
            if (!data || parseInt(data.quiz_id, 10) !== quizId) return;
            $panels.attr('data-state', data.state || 'waiting');
            $panels.find('.wpo-sync-summary').text(data.summary || '');
            $panels.find('.wpo-sync-checked').text(data.checked_label || '');
            $panels.find('.wpo-sync-source').text(data.source_label || '');
            $panels.find('.wpo-sync-next').text(data.next_label || '');
            $panels.find('.wpo-sync-count').text(data.question_count || 0);
            $panels.find('.wpo-sync-clock-warning').text(data.clock_warning || '');
            if (builder && data.enabled && revision && data.revision && revision !== data.revision) {
                if (editing()) { $panels.find('.wpo-sync-updated').prop('hidden', false); }
                else if (!timer) { timer = window.setTimeout(function () { timer = null; refreshList(); }, 800); }
            }
        }
        function poll() {
            if (pending || !quizId || document.hidden || !config.ajaxUrl) return;
            pending = true;
            $.post(config.ajaxUrl, { action: 'wp_olympiad_network_sync_status', nonce: config.nonce, quiz_id: quizId })
                .done(function (result) { if (result && result.success) applyStatus(result.data); })
                .always(function () { pending = false; });
        }
        $(document).on('heartbeat-send.wpoSync', function (event, data) { if (quizId) data.wpo_network_sync_quiz = quizId; });
        $(document).on('heartbeat-tick.wpoSync', function (event, data) { if (data && data.wpo_network_sync) applyStatus(data.wpo_network_sync); });
        // Read-only status request; the ordinary-request watchdog is independently throttled.
        if (quizId) { window.setInterval(poll, 20000); window.setTimeout(poll, 1800); }
        $(document).on('click.wpoSync', '.wpo-sync-refresh-list', function () {
            if (!editing() || window.confirm('Обновить список? Несохранённые правки в конструкторе будут потеряны.')) {
                reloading = true; window.location.reload();
            }
        });
        $(document).on('click.wpoSync', '.wpo-sync-copy-url', function () {
            var $input = $(this).siblings('.wpo-sync-cron-url');
            var $button = $(this), text = $input.val() || '';
            function done() { $button.text('Скопировано'); window.setTimeout(function () { $button.text('Копировать URL'); }, 1800); }
            function selectOnly() { $input.trigger('focus').trigger('select'); }
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(done, selectOnly);
            } else {
                selectOnly();
                try { if (document.execCommand('copy')) done(); } catch (e) { /* Keep the URL selected for manual copy. */ }
            }
        });
    });
})(jQuery);
