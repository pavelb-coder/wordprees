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

        // 5. Update Points Badge
        $(document).on('input', '.wpo-q-points-input', function () {
            var val = parseInt($(this).val(), 10) || 0;
            $(this).closest('.wpo-question-card').find('.wpo-qcard-points-badge').text(val + ' б.');
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

            // Options payload
            var options = {
                explanation: explanation
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
                options.answers = rawAns.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
                options.case_sensitive = $card.find('.wpo-text-case').is(':checked') ? 1 : 0;
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
                options.case_sensitive = $card.find('.wpo-blank-case').is(':checked') ? 1 : 0;
            } else if (qtype === 'slider') {
                options.min = parseFloat($card.find('.wpo-slider-min').val()) || 0;
                options.max = parseFloat($card.find('.wpo-slider-max').val()) || 100;
                options.step = parseFloat($card.find('.wpo-slider-step').val()) || 1;
                options.target_value = parseFloat($card.find('.wpo-slider-target').val()) || 50;
                options.tolerance = parseFloat($card.find('.wpo-slider-tolerance').val()) || 0;
            }

            $spinner.addClass('is-active');
            $btn.prop('disabled', true);

            $.post(config.ajaxUrl, {
                action: 'wp_olympiad_save_question',
                id: qid,
                quiz_id: quizId,
                question_type: qtype,
                title: title,
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

        // 14. Test Network Folder Connection (AJAX)
        $('#wpo-btn-test-network').on('click', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var $spinner = $('#wpo-network-test-spinner');
            var $msg = $('#wpo-network-test-message');

            var path = $('#network_folder_path').val().trim();
            if (!path) {
                $msg.css({ background: '#fef2f2', color: '#991b1b', border: '1px solid #fecaca' })
                    .text('Пожалуйста, укажите путь к сетевой папке или URL хранилища.')
                    .show();
                return;
            }

            $btn.prop('disabled', true);
            $spinner.addClass('is-active');
            $msg.hide();

            $.post(config.ajaxUrl, {
                action: 'wp_olympiad_test_network_connection',
                nonce: config.nonce,
                type: $('#network_folder_type').val(),
                path: path,
                user: $('#network_folder_user').val(),
                password: $('#network_folder_password').val(),
                protect_directory: $('#protect_local_directory').is(':checked') ? 1 : 0
            }, function (res) {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');
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

            var path = $('#network_folder_path').val().trim();
            if (!path) {
                $msg.css({ background: '#fef2f2', color: '#991b1b', border: '1px solid #fecaca' })
                    .text('Укажите путь к сетевой папке перед запуском экспорта.')
                    .show();
                return;
            }

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
                password: $('#network_folder_password').val(),
                zip_password: $('#export_zip_password').val()
            }, function (res) {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');
                if (res.success) {
                    var encBadge = res.data.is_encrypted ? ' 🔒 <span style="font-weight:700;">(Защищён паролем AES-256)</span>' : '';
                    $msg.css({ background: '#f0fdf4', color: '#166534', border: '1px solid #bbf7d0' })
                        .html('✓ <strong>' + res.data.message + '</strong><br>Файл: <code>' + res.data.filename + '</code>' + encBadge + '<br>Размер: ' + (Math.round(res.data.size / 102.4) / 10) + ' КБ')
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
    });
})(jQuery);
