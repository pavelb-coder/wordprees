/* Style-only editor. Preview uses the SAME PHP CSS builder as the real runner. */
(function ($) {
    'use strict';
    var cfg = window.WPOlympiadAdmin || {}, $card, frame, doc, cssNode, preview;
    var themes = [], timer = null, pending = null, sequence = 0, applying = false;
    var device = 'desktop', sizes = {desktop: [1280, 800], tablet: [768, 1024], mobile: [390, 844]};
    function message(id, text, error) { $(id).text(text || '').toggleClass('is-error', !!error); }
    function failure(xhr) { return xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message || 'Не удалось выполнить действие. Проверьте подключение и повторите.'; }
    function fields() {
        var out = {style_preset: $('#wpo-style-preset').val() || 'custom'};
        $card.find('[data-wpo-style][name]').each(function () { out[this.name] = this.type === 'checkbox' ? (this.checked ? 1 : 0) : $(this).val(); });
        return out;
    }
    function api(op, extra) { return $.post(cfg.ajaxUrl, $.extend({action: 'wp_olympiad_style_theme', nonce: cfg.nonce, op: op}, extra || {})); }
    function resize() {
        if (!frame) return;
        var holder = document.getElementById('wpo-style-device-holder'), size = sizes[device];
        var available = holder.clientWidth || 320, scale = Math.min(1, available / size[0]);
        frame.style.width = size[0] + 'px'; frame.style.height = size[1] + 'px'; frame.style.transform = 'scale(' + scale + ')';
        holder.style.height = Math.ceil(size[1] * scale) + 'px';
        $('#wpo-style-device-size').text(size[0] + ' × ' + size[1] + ' · ' + Math.round(scale * 100) + '%');
        $card.find('[data-wpo-preview-device]').each(function () { var active = $(this).data('wpo-preview-device') === device; $(this).toggleClass('is-active', active).attr('aria-pressed', active ? 'true' : 'false'); });
    }
    function setupPreview() {
        frame = document.getElementById('wpo-style-preview-device'); preview = document.getElementById('wpo-style-preview');
        if (!frame || !preview) return;
        doc = frame.contentDocument;
        doc.open(); doc.write('<!doctype html><html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body></body></html>'); doc.close();
        var base = doc.createElement('style'); base.textContent = cfg.stylePreviewCss || ''; doc.head.appendChild(base);
        cssNode = doc.createElement('style'); cssNode.id = 'wpo-canonical-preview-css'; doc.head.appendChild(cssNode);
        doc.body.appendChild(doc.adoptNode(preview));
        frame.hidden = false; resize();
        if (window.ResizeObserver) new ResizeObserver(resize).observe(document.getElementById('wpo-style-device-holder'));
        $(window).on('resize.wpoStylePreview', resize);
    }
    function ranges() {
        $card.find('input[type=range][data-wpo-style]').each(function () { $(this).siblings('output').text(this.value + ' ' + ($(this).data('unit') || '')); });
        $card.find('[data-hex-for]').each(function () { var key = $(this).data('hex-for'); $(this).val($card.find('[data-wpo-style="' + key + '"]').val()); });
        $('#wpo-style-canvas-gradient-angle').prop('disabled', $('#wpo-style-canvas-gradient').val() !== 'linear');
        $('#wpo-style-presets .wpo-style-preset').removeClass('is-active').filter(function () { return $(this).data('preset') === $('#wpo-style-preset').val(); }).addClass('is-active');
    }
    function render() {
        ranges(); sequence++; var mine = sequence;
        window.clearTimeout(timer); if (pending) pending.abort();
        message('#wpo-style-preview-status', 'Обновление предпросмотра…');
        timer = window.setTimeout(function () {
            pending = api('preview', {fields: JSON.stringify(fields())});
            pending.done(function (r) {
                if (mine !== sequence) return;
                if (!r || !r.success) { message('#wpo-style-preview-status', r && r.data && r.data.message || 'Предпросмотр не обновлён.', true); return; }
                cssNode.textContent = r.data.css;
                doc.body.style.cssText = 'margin:0;padding:20px 15px;min-height:100vh;box-sizing:border-box;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;' + r.data.body_css;
                message('#wpo-style-preview-status', 'Предпросмотр обновлён'); resize();
            }).fail(function (xhr, status) { if (status !== 'abort' && mine === sequence) message('#wpo-style-preview-status', failure(xhr), true); });
        }, 160);
    }
    function expandHex(value) { return /^#[a-f0-9]{3}$/i.test(value) ? '#' + value[1] + value[1] + value[2] + value[2] + value[3] + value[3] : value; }
    function apply(style) {
        applying = true;
        Object.keys(style).forEach(function (key) {
            var $input = $card.find('[data-wpo-style="' + key + '"]').first(); if (!$input.length) return;
            if ($input.attr('type') === 'checkbox') $input.prop('checked', !!style[key]);
            else if ($input.attr('type') === 'color') { if (style[key]) $input.val(expandHex(style[key])); }
            else $input.val(style[key]);
        });
        $('#wpo-style-canvas-enabled').prop('checked', !!style.canvas_bg || !!style.canvas_image || (style.canvas_gradient && style.canvas_gradient !== 'none'));
        $('#wpo-style-preset').val(style.preset || 'custom'); applying = false; imagePreview(); render();
        $card.find('[data-wpo-style]').first().trigger('change');
    }
    function imagePreview() {
        var url = String($('#wpo-style-canvas-image').val() || '').trim(), $box = $('#wpo-style-canvas-image-preview');
        if (url) $box.show().find('img').attr('src', url); else $box.hide().find('img').attr('src', '');
    }
    function fillLibrary(items, selected) {
        themes = items || []; var $sel = $('#wpo-style-theme-select').empty().append($('<option>').val('').text('Выберите сохранённую тему'));
        themes.forEach(function (t) { $sel.append($('<option>').val(t.id).text(t.name)); }); if (selected) $sel.val(selected);
    }
    function selectedTheme() { var id = $('#wpo-style-theme-select').val(); return themes.find(function (t) { return t.id === id; }); }
    function preset(key, reset) {
        var data = $.extend({}, cfg.styleDefaults || {}, (cfg.stylePresets || {})[key] || {});
        if (!reset) { data.custom_css = $('#wpo-style-custom-css').val() || ''; data.canvas_image = $('#wpo-style-canvas-image').val() || ''; }
        data.preset = key; apply(data);
    }
    $(function () {
        $card = $('#wpo-style-card'); if (!$card.length || !cfg.ajaxUrl) return;
        setupPreview(); render(); imagePreview();
        api('list').done(function (r) { if (r.success) fillLibrary(r.data.themes); }).fail(function (r) { message('#wpo-style-theme-status', failure(r), true); });
        $card.on('input change', '[data-wpo-style]', function () {
            if (!applying && $(this).data('wpo-style') !== 'custom_css') $('#wpo-style-preset').val('custom');
            if ($(this).data('wpo-style') === 'canvas_gradient' && this.value !== 'none') $('#wpo-style-canvas-enabled').prop('checked', true);
            if ($(this).data('wpo-style') === 'canvas_image') { if (this.value.trim()) $('#wpo-style-canvas-enabled').prop('checked', true); imagePreview(); }
            render();
        });
        $card.on('input change', '[data-hex-for]', function () {
            var v = String($(this).val() || '').trim(); if (!/^#?([a-f0-9]{3}|[a-f0-9]{6})$/i.test(v)) return;
            if (v[0] !== '#') v = '#' + v;
            $card.find('[data-wpo-style="' + $(this).data('hex-for') + '"]').val(expandHex(v.toLowerCase())).trigger('change');
        });
        $card.on('click', '.wpo-style-preset', function () { var k = $(this).data('preset'); if (k === 'custom') { $('#wpo-style-preset').val(k); ranges(); } else preset(k, false); });
        $('#wpo-style-reset').on('click', function () { if (window.confirm('Сбросить оформление к классическому, включая CSS, фон и эффекты?')) preset('classic', true); });
        $('#wpo-style-glass').on('click', function () {
            $('#wpo-style-card-opacity').val(80); $('#wpo-style-card-blur').val(12); $('#wpo-style-canvas-enabled').prop('checked', true);
            if ($('#wpo-style-canvas-gradient').val() === 'none') $('#wpo-style-canvas-gradient').val('linear');
            $('#wpo-style-preset').val('custom'); $('#wpo-style-card-opacity').trigger('change');
        });
        $card.on('click', '[data-wpo-preview-device]', function () { device = $(this).data('wpo-preview-device'); resize(); });
        $('#wpo-style-canvas-image-pick').on('click', function () {
            if (window.wp && wp.media) {
                var picker = wp.media({title: 'Выберите фон страницы', button: {text: 'Использовать фон'}, multiple: false, library: {type: 'image'}});
                picker.on('select', function () { $('#wpo-style-canvas-image').val(picker.state().get('selection').first().toJSON().url || '').trigger('change'); }); picker.open();
            } else { var url = window.prompt('URL фона https://…', $('#wpo-style-canvas-image').val() || ''); if (url) $('#wpo-style-canvas-image').val(url).trigger('change'); }
        });
        $('#wpo-style-canvas-image-clear').on('click', function () { $('#wpo-style-canvas-image').val('').trigger('change'); });
        $('#wpo-style-theme-select').on('change', function () { var t = selectedTheme(); if (t) $('#wpo-style-theme-name').val(t.name); });
        $('#wpo-style-theme-apply').on('click', function () { var t = selectedTheme(); if (!t) { message('#wpo-style-theme-status', 'Сначала выберите тему.', true); return; } apply(t.style); message('#wpo-style-theme-status', 'Тема применена к форме. Сохраните олимпиаду.'); });
        $('#wpo-style-theme-save').on('click', function () {
            var name = String($('#wpo-style-theme-name').val() || '').trim(); if (!name) { message('#wpo-style-theme-status', 'Укажите название темы.', true); return; }
            var old = themes.find(function (t) { return t.name.toLocaleLowerCase() === name.toLocaleLowerCase(); });
            if (old && !window.confirm('Обновить сохранённую тему «' + old.name + '»?')) return;
            api('save', {name: name, id: old ? old.id : '', fields: JSON.stringify(fields())}).done(function (r) { if (r.success) { fillLibrary(r.data.themes, r.data.theme.id); message('#wpo-style-theme-status', 'Тема сохранена в библиотеке; настройки олимпиады отдельно не изменялись.'); } }).fail(function (r) { message('#wpo-style-theme-status', failure(r), true); });
        });
        $('#wpo-style-theme-delete').on('click', function () {
            var t = selectedTheme(); if (!t || !window.confirm('Удалить тему «' + t.name + '» из библиотеки? Текущее оформление не изменится.')) return;
            api('delete', {id: t.id}).done(function (r) { if (r.success) { fillLibrary(r.data.themes); message('#wpo-style-theme-status', 'Тема удалена из библиотеки, текущее оформление сохранено.'); } }).fail(function (r) { message('#wpo-style-theme-status', failure(r), true); });
        });
        $('#wpo-style-theme-export').on('click', function () {
            api('export', {fields: JSON.stringify(fields()), name: $('#wpo-style-theme-name').val() || ''}).done(function (r) {
                if (!r.success) return; var blob = new Blob([JSON.stringify(r.data, null, 2)], {type: 'application/json;charset=utf-8'}), url = URL.createObjectURL(blob), a = document.createElement('a');
                a.href = url; a.download = 'оформление_олимпиады.json'; document.body.appendChild(a); a.click(); a.remove(); window.setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
                message('#wpo-style-theme-status', 'JSON содержит только оформление. Файлы изображений не встраиваются.');
            }).fail(function (r) { message('#wpo-style-theme-status', failure(r), true); });
        });
        $('#wpo-style-theme-import').on('change', function () {
            var input = this, file = input.files && input.files[0]; if (!file) return;
            if (file.size > 131072) { message('#wpo-style-theme-status', 'Максимальный размер JSON — 128 КБ.', true); input.value = ''; return; }
            file.text().then(function (text) { return api('import', {document: text}); }).then(function (r) { if (r.success) { apply(r.data.style); $('#wpo-style-theme-name').val(r.data.name || ''); message('#wpo-style-theme-status', 'Оформление импортировано в форму. Сохраните олимпиаду или тему.'); } }).catch(function (r) { message('#wpo-style-theme-status', failure(r), true); }).finally(function () { input.value = ''; });
        });
    });
})(jQuery);
