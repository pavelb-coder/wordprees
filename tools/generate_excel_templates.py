#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Build only questions_template.xlsx from the shared 13-type import schema.

The template has dropdowns, column hints and instructions; no VBA or XLSM is
produced. CSV/JSON remain supported user import formats, not bundled samples.
This development tool writes only into this plugin's templates/ directory.
"""
import json
import os
import sys
import xlsxwriter

TOOLS_DIR = os.path.dirname(os.path.abspath(__file__))
PLUGIN_DIR = os.path.dirname(TOOLS_DIR)
SCHEMA_FILE = os.path.join(TOOLS_DIR, 'import-samples.json')
TEMPLATES_DIR = os.path.join(PLUGIN_DIR, 'templates')


def load_schema():
    if not os.path.exists(SCHEMA_FILE):
        sys.exit(
            'Schema file %s not found.\n'
            'Run tools/build_templates.sh first: it dumps the schema from WPOlympiad\\ImportTemplate.' % SCHEMA_FILE
        )
    with open(SCHEMA_FILE, encoding='utf-8') as fh:
        schema = json.load(fh)

    columns = schema['columns']
    samples = schema['samples']
    if not columns or not samples:
        sys.exit('Schema file %s is empty or invalid.' % SCHEMA_FILE)

    keys = [c['key'] for c in columns]
    for sample in samples:
        missing = [k for k in keys if k not in sample]
        if missing:
            sys.exit('Sample "%s" misses columns: %s' % (sample.get('type', '?'), ', '.join(missing)))

    schema['keys'] = keys
    schema['types'] = [s['type'] for s in samples]
    return schema


def col_letter(idx: int) -> str:
    """1-based column index → Excel letter."""
    letters = ''
    while idx > 0:
        idx, rem = divmod(idx - 1, 26)
        letters = chr(65 + rem) + letters
    return letters


def create_excel_workbook(file_path, schema):
    keys = schema['keys']
    columns = schema['columns']
    samples = schema['samples']
    catalog = schema.get('type_catalog', [])
    total_cols = len(keys)

    wb = xlsxwriter.Workbook(file_path)

    # --- FORMATS ---
    fmt_header = wb.add_format({
        'bold': True,
        'bg_color': '#1E3A8A',
        'font_color': '#FFFFFF',
        'font_size': 11,
        'align': 'center',
        'valign': 'vcenter',
        'border': 1,
        'border_color': '#0F172A',
        'text_wrap': True,
    })
    fmt_header_req = wb.add_format({
        'bold': True,
        'bg_color': '#B91C1C',
        'font_color': '#FFFFFF',
        'font_size': 11,
        'align': 'center',
        'valign': 'vcenter',
        'border': 1,
        'border_color': '#7F1D1D',
        'text_wrap': True,
    })
    fmt_data = wb.add_format({'font_size': 10, 'valign': 'top', 'border': 1, 'border_color': '#E2E8F0', 'text_wrap': True})
    fmt_num = wb.add_format({'font_size': 10, 'align': 'center', 'valign': 'top', 'border': 1, 'border_color': '#E2E8F0'})
    fmt_type = wb.add_format({
        'font_size': 10, 'align': 'center', 'valign': 'top', 'bold': True,
        'bg_color': '#F1F5F9', 'border': 1, 'border_color': '#CBD5E1',
    })
    fmt_key = wb.add_format({'font_size': 9, 'font_color': '#64748B', 'valign': 'top', 'border': 1, 'border_color': '#E2E8F0'})

    # --- SHEET 1: ВОПРОСЫ ---
    ws = wb.add_worksheet('Вопросы')
    ws.set_tab_color('#2563EB')
    ws.freeze_panes(1, 2)
    ws.set_row(0, 42)
    ws.set_default_row(30)

    numeric_keys = {'points', 'penalty', 'target_value', 'tolerance', 'is_required'}

    for col_idx, col in enumerate(columns):
        label = col['label'] + (' *' if col.get('required') else '')
        ws.write(0, col_idx, label, fmt_header_req if col.get('required') else fmt_header)
        ws.set_column(col_idx, col_idx, max(9, min(45, int(col.get('width') or 20))))
        ws.write_comment(0, col_idx, '[%s]\n%s' % (col['key'], col.get('hint', '')), {
            'x_scale': 3, 'y_scale': 1.6, 'font_size': 9,
            'author': 'WP Olympiad',
        })

    for row_idx, sample in enumerate(samples, start=1):
        for col_idx, key in enumerate(keys):
            value = sample.get(key, '')
            if key == 'type':
                ws.write(row_idx, col_idx, value, fmt_type)
            elif key in numeric_keys:
                text = str(value).strip()
                if text == '':
                    ws.write_blank(row_idx, col_idx, None, fmt_num)
                elif key == 'target_value' or key == 'tolerance':
                    try:
                        ws.write_number(row_idx, col_idx, float(text.replace(',', '.')), fmt_num)
                    except ValueError:
                        ws.write(row_idx, col_idx, text, fmt_num)
                else:
                    try:
                        ws.write_number(row_idx, col_idx, int(float(text.replace(',', '.'))), fmt_num)
                    except ValueError:
                        ws.write(row_idx, col_idx, text, fmt_num)
            else:
                ws.write(row_idx, col_idx, str(value), fmt_data)

    type_idx = keys.index('type')
    required_idx = keys.index('is_required')
    type_letters = col_letter(type_idx + 1)
    required_letters = col_letter(required_idx + 1)
    last_letter = col_letter(total_cols)

    ws.data_validation('%s2:%s500' % (type_letters, type_letters), {
        'validate': 'list',
        'source': schema['types'],
        'input_title': 'Тип задания',
        'input_message': 'Выберите тип из списка. Можно писать и по-русски: «один выбор», «соответствие», «анаграмма»…',
        'error_title': 'Недопустимый тип задания',
        'error_message': 'Выберите значение из выпадающего списка или введите код типа.',
    })

    ws.data_validation('%s2:%s500' % (required_letters, required_letters), {
        'validate': 'list',
        'source': ['1', '0'],
        'input_title': 'Обязательный вопрос',
        'input_message': '1 — без ответа нельзя завершить тест, 0 — можно пропустить.',
    })

    for numeric_key in ('points', 'penalty'):
        if numeric_key in keys:
            letter = col_letter(keys.index(numeric_key) + 1)
            ws.data_validation('%s2:%s500' % (letter, letter), {
                'validate': 'integer',
                'criteria': '>=',
                'value': 0,
                'error_title': 'Баллы',
                'error_message': 'Введите целое неотрицательное число.',
            })

    ws.autofilter(0, 0, len(samples), total_cols - 1)
    ws.freeze_panes(1, 2)

    # No VBA projects or macro buttons are included in the distributed workbook.

    # --- SHEET 2: ИНСТРУКЦИЯ ---
    ws_info = wb.add_worksheet('Инструкция')
    ws_info.set_tab_color('#059669')
    ws_info.set_column('A:A', 26)
    ws_info.set_column('B:B', 16)
    ws_info.set_column('C:C', 62)
    ws_info.set_column('D:D', 38)

    fmt_title = wb.add_format({'bold': True, 'font_size': 15, 'color': '#1E3A8A'})
    fmt_sub = wb.add_format({'italic': True, 'font_size': 10, 'color': '#475569'})
    fmt_section = wb.add_format({'bold': True, 'font_size': 12, 'bg_color': '#DBEAFE', 'border': 1, 'border_color': '#93C5FD', 'valign': 'vcenter'})
    fmt_table_h = wb.add_format({'bold': True, 'bg_color': '#059669', 'font_color': '#FFFFFF', 'border': 1, 'align': 'center', 'valign': 'vcenter', 'text_wrap': True})
    fmt_cell = wb.add_format({'font_size': 10, 'border': 1, 'border_color': '#E2E8F0', 'valign': 'top', 'text_wrap': True})
    fmt_cell_b = wb.add_format({'bold': True, 'font_size': 10, 'border': 1, 'border_color': '#E2E8F0', 'valign': 'top', 'text_wrap': True})
    fmt_cell_m = wb.add_format({'font_name': 'Consolas', 'font_size': 9, 'border': 1, 'border_color': '#E2E8F0', 'valign': 'top', 'text_wrap': True})
    fmt_warn = wb.add_format({'font_size': 10, 'font_color': '#B91C1C', 'text_wrap': True})

    ws_info.write('A1', 'ШАБЛОН ИМПОРТА ВОПРОСОВ — WP OLYMPIAD', fmt_title)
    ws_info.write('A2', 'Версия формата %s · %d колонок · %d типов заданий. Обязательны только «Тип задания» и «Текст вопроса».'
                  % (schema.get('version', '2.0'), total_cols, len(schema['types'])), fmt_sub)
    ws_info.set_row(1, 18)

    row = 3
    ws_info.write(row, 0, '1. КАК ЗАПОЛНЯТЬ', fmt_section)
    ws_info.merge_range(row, 1, row, 3, '', fmt_section)
    row += 1
    steps = [
        'Откройте лист «Вопросы». Строка 1 — заголовки, её менять нельзя (по ним работает импорт).',
        'Заполните строки со 2-й: минимум — тип задания и текст вопроса. Остальное — по необходимости.',
        'Баллы (points) и штраф (penalty) по умолчанию: 1 и 0. Числа можно писать с запятой: 9,8.',
        'Для проверки используйте «Проверить файл заданий» в WordPress — проверка не меняет базу.',
        'Сохраните заполненную книгу как Мои_вопросы.xlsx. Макросы и преобразование в CSV не требуются.',
        'Положите файл в сетевую папку (каталог import/ или корень) — плагин импортирует его автоматически,' ,
        'либо в админке олимпиады нажмите «✔ Проверить файл заданий», чтобы увидеть отчёт без импорта.',
    ]
    for i, step in enumerate(steps, start=1):
        ws_info.write(row, 0, '%d.' % i, fmt_cell_b)
        ws_info.merge_range(row, 1, row, 3, step, fmt_cell)
        row += 1

    row += 1
    ws_info.write(row, 0, '2. КОЛОНКИ ТАБЛИЦЫ', fmt_section)
    ws_info.merge_range(row, 1, row, 3, 'Порядок колонок важен для CSV. Заголовки можно писать по-русски.', fmt_section)
    row += 1
    for idx, (title, width) in enumerate([('Колонка', 26), ('Код (для CSV)', 16), ('Назначение и правила', 62), ('Пример', 38)]):
        ws_info.write(row, idx, title, fmt_table_h)
    row += 1
    for col in columns:
        ws_info.write(row, 0, col['label'] + (' *' if col.get('required') else ''), fmt_cell_b)
        ws_info.write(row, 1, col['key'], fmt_cell_m)
        ws_info.write(row, 2, col.get('hint', ''), fmt_cell)
        ws_info.write(row, 3, col.get('example', ''), fmt_cell)
        row += 1

    row += 1
    ws_info.write(row, 0, '3. ВСЕ ТИПЫ ЗАДАНИЙ', fmt_section)
    ws_info.merge_range(row, 1, row, 3, 'Код типа можно вводить латиницей или по-русски («один выбор», «пропуски», «шкала»…).', fmt_section)
    row += 1
    for idx, title in enumerate(['Тип задания', 'Код для CSV', 'Что заполнить', 'Особенности оценивания']):
        ws_info.write(row, idx, title, fmt_table_h)
    row += 1

    samples_by_type = {s['type']: s for s in samples}
    catalog_by_code = {c['code']: c for c in catalog}
    for type_code in schema['types']:
        sample = samples_by_type[type_code]
        filled = []
        for field in ('choices', 'correct', 'target_value', 'tolerance', 'answers', 'template', 'extra'):
            if str(sample.get(field, '')).strip() != '':
                filled.append('%s: %s' % (field, sample[field]))
        meta = catalog_by_code.get(type_code, {})
        ws_info.write(row, 0, meta.get('label', type_code), fmt_cell_b)
        ws_info.write(row, 1, type_code, fmt_cell_m)
        ws_info.write(row, 2, '\n'.join(filled) if filled else 'Достаточно текста вопроса и баллов', fmt_cell_m)
        ws_info.write(row, 3, meta.get('description', ''), fmt_cell)
        row += 1

    row += 1
    ws_info.write(row, 0, '4. РАЗМЕТКА ОТВЕТОВ', fmt_section)
    ws_info.merge_range(row, 1, row, 3, '', fmt_section)
    row += 1
    markup = [
        ('Варианты (choices)', 'Вариант 1 | *Вариант 2 | Вариант 3', 'Звёздочка * перед вариантом делает его правильным. Вместо звёздочки можно указать номер варианта в correct: 2 или 1,3.'),
        ('Баллы за пункт', '*Гелий^2 | Кислород | *Неон^2', 'Символ ^ после варианта задаёт вес конкретного правильного пункта (для multi).'),
        ('Соответствия', 'Франция => Париж | Италия => Рим', 'Пары разделяются «=>» (работает также « - », «:»). Пары — через «|».'),
        ('Последовательность', 'Древний мир | Средние века | Новое время', 'Элементы перечисляются сразу в правильном порядке.'),
        ('Пропуски', 'В {=1242|двенадцать сорок втором} году…', 'Пропуск — в фигурных скобках, верный вариант первый или помечен «=». Синонимы — через «|».'),
        ('Диктант', 'Быстро {=ск|згк}ать по {=лест|лезт}нице.', 'Первый вариант в пропуске считается верным, остальные — обманки. Аудио: extra → audio_url=…'),
        ('Верно / неверно', 'correct: да', 'Допустимо: да / нет / 1 / 0 / true / false / верно / неверно.'),
        ('Число и шкала', 'target_value: 9,8 · tolerance: 0,1', 'Ответ засчитывается в диапазоне target ± tolerance. Для шкалы границы: extra → min=0; max=10; step=1'),
        ('Анаграмма', 'answers: КВАНТ | КВАНТЫ', 'Первое слово — целевое; остальные принимаются как альтернативы. Лишние буквы: extra → extra_letters=Ы'),
    ]
    for idx, title in enumerate(['Что настраиваем', 'Как писать', 'Пояснение']):
        ws_info.write(row, idx if idx < 2 else 2, title, fmt_table_h)
    ws_info.merge_range(row, 2, row, 3, 'Пояснение', fmt_table_h)
    row += 1
    for name, syntax, note in markup:
        ws_info.write(row, 0, name, fmt_cell_b)
        ws_info.write(row, 1, syntax, fmt_cell_m)
        ws_info.merge_range(row, 2, row, 3, note, fmt_cell)
        row += 1

    row += 1
    ws_info.write(row, 0, '5. КОЛОНКА «ДОП. ПАРАМЕТРЫ» (extra)', fmt_section)
    ws_info.merge_range(row, 1, row, 3, 'Формат: key=value; key2=value2. Регистр ключей не важен.', fmt_section)
    row += 1
    extra_docs = [
        ('min / max / step', 'min=0; max=100; step=5', 'Границы и шаг шкалы (slider).'),
        ('extra_letters', 'extra_letters=ЫЖЭ', 'Лишние буквы-обманки для анаграммы.'),
        ('audio_url', 'audio_url=https://site.ru/audio.mp3', 'Аудио для диктанта (аудирование).'),
        ('scoring', 'scoring=proportional | all_or_nothing', 'Логика начисления баллов: пропорционально или всё-или-ничего.'),
        ('mistakes', 'mistakes=1', 'Допустимое количество ошибок в диктанте.'),
        ('mode', 'mode=input | dropdown | word_bank', 'Режим заполнения пропусков.'),
        ('distractors', 'distractors=Париж, Рим, Токио', 'Слова-обманки для пропусков через запятую.'),
        ('min_words / max_words', 'min_words=50; max_words=250', 'Ограничение объёма эссе.'),
        ('keywords', 'keywords=сила|противодействие|отдача', 'Ключевые понятия для автопроверки эссе (через «|»).'),
        ('min_keywords', 'min_keywords=2', 'Сколько ключевых понятий должно встретиться.'),
        ('match', 'match=fuzzy', 'Режим сравнения текстового ответа: exact, contains_any, contains_all, fuzzy, regex.'),
        ('typo', 'typo=1', 'Допустимое число опечаток в текстовом ответе (0–10).'),
        ('shuffle', 'shuffle=1', 'Перемешивать варианты ответа.'),
        ('case_sensitive', 'case_sensitive=0', 'Учитывать регистр при проверке.'),
    ]
    for idx, title in enumerate(['Ключ', 'Пример', 'Назначение']):
        ws_info.write(row, idx if idx < 2 else 2, title, fmt_table_h)
    ws_info.merge_range(row, 2, row, 3, 'Назначение', fmt_table_h)
    row += 1
    for key_name, example, note in extra_docs:
        ws_info.write(row, 0, key_name, fmt_cell_m)
        ws_info.write(row, 1, example, fmt_cell_m)
        ws_info.merge_range(row, 2, row, 3, note, fmt_cell)
        row += 1

    row += 1
    ws_info.write(row, 0, '6. ЧАСТЫЕ ОШИБКИ', fmt_section)
    ws_info.merge_range(row, 1, row, 3, '', fmt_section)
    row += 1
    mistakes = [
        'Пустая колонка «Текст вопроса» — строка будет пропущена при импорте.',
        'В типе single не отмечен правильный вариант — плагин назначит правильным первый вариант.',
        'В fill_blank / dictation нет фигурных скобок — задание импортируется как обычный вопрос.',
        'Файл сохранён в ANSI (Windows-1251) — плагин перекодирует автоматически, но надёжнее «CSV UTF-8».',
        'Разделитель в русской локали Excel — «;». Шаблон уже настроен на «;», не меняйте его на «,».',
    ]
    for note in mistakes:
        ws_info.write(row, 0, '•', fmt_cell_b)
        ws_info.merge_range(row, 1, row, 3, note, fmt_warn)
        row += 1

    ws_info.freeze_panes(3, 0)

    # --- SHEET 3: СПРАВОЧНИК ---
    ws_ref = wb.add_worksheet('Справочник')
    ws_ref.set_tab_color('#7C3AED')
    ws_ref.set_column('A:A', 18)
    ws_ref.set_column('B:B', 44)
    ws_ref.set_column('C:C', 70)
    ws_ref.set_column('D:D', 44)

    for idx, title in enumerate(['Код типа', 'Название в плагине', 'Что делает', 'Правильный пример заполнения']):
        ws_ref.write(0, idx, title, fmt_table_h)

    for i, type_code in enumerate(schema['types'], start=1):
        meta = catalog_by_code.get(type_code, {})
        sample = samples_by_type[type_code]
        ws_ref.write(i, 0, type_code, fmt_cell_m)
        ws_ref.write(i, 1, meta.get('label', type_code), fmt_cell_b)
        ws_ref.write(i, 2, meta.get('description', ''), fmt_cell)
        parts = []
        for field in ('title', 'choices', 'correct', 'target_value', 'tolerance', 'answers', 'template', 'extra'):
            if str(sample.get(field, '')).strip() != '':
                parts.append('%s: %s' % (field, sample[field]))
        ws_ref.write(i, 3, '\n'.join(parts), fmt_cell_m)

    ws_ref.freeze_panes(1, 0)
    ws_ref.autofilter(0, 0, len(schema['types']), 3)

    wb.close()
    print('Generated:', file_path)


def main():
    schema = load_schema()
    os.makedirs(TEMPLATES_DIR, exist_ok=True)
    # Remove only old build artifacts, never anything in a user's network share.
    for name in ('questions.csv', 'questions.json', 'questions_template.csv',
                 'questions_template.json', 'questions_template_macros.xlsm'):
        path = os.path.join(TEMPLATES_DIR, name)
        if os.path.lexists(path):
            if os.path.isdir(path) and not os.path.islink(path):
                raise RuntimeError('Obsolete template path is a directory: ' + path)
            os.unlink(path)
    create_excel_workbook(os.path.join(TEMPLATES_DIR, 'questions_template.xlsx'), schema)
    print('XLSX template generated (%d columns, %d types); no network files modified.' % (len(schema['keys']), len(schema['types'])))


if __name__ == '__main__':
    main()
