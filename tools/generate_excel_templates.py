#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Generate Excel question templates for WP Olympiad.

Files produced (into plugin templates/ and mirrored into wp-test):
  1. questions_template_macros.xlsm — macro-enabled workbook (validation, CSV export, sample rows)
  2. questions_template.xlsx        — workbook with dropdowns, hints and instructions

The schema (columns, samples, hints) is NOT duplicated here: it is read from
tools/import-samples.json, which is generated from the plugin's single source of
truth WPOlympiad\\ImportTemplate by tools/build_templates.sh. That keeps the
Excel template, the CSV/JSON templates and the importer in sync.
"""

import json
import os
import shutil
import struct
import subprocess
import sys
import urllib.request

import xlsxwriter

TOOLS_DIR = os.path.dirname(os.path.abspath(__file__))
PLUGIN_DIR = os.path.dirname(TOOLS_DIR)
SCHEMA_FILE = os.path.join(TOOLS_DIR, 'import-samples.json')
TEMPLATES_DIR = os.path.join(PLUGIN_DIR, 'templates')
WP_TEST_TEMPLATES = '/home/user/wp-test/wp-content/plugins/wp-olympiad/templates'


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


def ovba_compress(data: bytes) -> bytes:
    """Compress data using MS-OVBA compression specification."""
    out = bytearray([0x01])
    pos = 0
    while pos < len(data):
        chunk = data[pos : min(pos + 4096, len(data))]
        pos += len(chunk)
        if len(chunk) == 4096:
            header = ((4098 - 3) & 0x0FFF) | (0b011 << 12) | (0 << 15)
            out.extend(struct.pack('<H', header))
            out.extend(chunk)
        else:
            chunk_data = bytearray()
            c_pos = 0
            while c_pos < len(chunk):
                sub = chunk[c_pos : min(c_pos + 8, len(chunk))]
                c_pos += len(sub)
                chunk_data.append(0x00)
                chunk_data.extend(sub)
            chunk_size = len(chunk_data) + 2
            header = ((chunk_size - 3) & 0x0FFF) | (0b011 << 12) | (1 << 15)
            out.extend(struct.pack('<H', header))
            out.extend(chunk_data)
    return bytes(out)


def build_vba_project_bin(vba_code: str) -> str:
    """Build vbaProject.bin with embedded validation and export macros."""
    base_file = '/tmp/base_vbaProject.bin'
    if not os.path.exists(base_file):
        url = 'https://raw.githubusercontent.com/jmcnamara/XlsxWriter/main/examples/vbaProject.bin'
        with urllib.request.urlopen(url) as r:
            with open(base_file, 'wb') as f:
                f.write(r.read())

    ole_dir = '/tmp/ole_build'
    work_dir = '/tmp/ole_work'
    os.system('rm -rf %s %s && mkdir -p %s %s' % (ole_dir, work_dir, ole_dir, work_dir))
    # ms_cfb writes fatChain.bin into the current working directory — keep it out of the plugin.
    subprocess.run(['python3', '-m', 'ms_cfb', '-x', ole_dir, '-f', base_file], check=True, cwd=work_dir)

    for legacy, canonical in (('PROJECT.bin', 'PROJECT'), ('PROJECTwm.bin', 'PROJECTwm')):
        src = os.path.join(ole_dir, legacy)
        if os.path.exists(src):
            os.rename(src, os.path.join(ole_dir, canonical))

    # Code page 1251 (Windows-1251 Cyrillic) in dir.bin
    from oletools.olevba import decompress_stream
    with open(os.path.join(ole_dir, 'VBA/dir.bin'), 'rb') as f:
        dir_comp = f.read()
    dir_raw = bytearray(decompress_stream(dir_comp))
    idx = dir_raw.find(b'\x03\x00')
    if idx != -1:
        struct.pack_into('<H', dir_raw, idx + 6, 1251)
    with open(os.path.join(ole_dir, 'VBA/dir.bin'), 'wb') as f:
        f.write(ovba_compress(bytes(dir_raw)))

    with open(os.path.join(ole_dir, 'VBA/Module1.bin'), 'rb') as f:
        mod1_orig = f.read()

    new_mod1 = mod1_orig[:955] + ovba_compress(vba_code.encode('cp1251'))
    with open(os.path.join(ole_dir, 'VBA/Module1.bin'), 'wb') as f:
        f.write(new_mod1)

    for s in ('__SRP_0.bin', '__SRP_1.bin', '__SRP_2.bin', '__SRP_3.bin'):
        p = os.path.join(ole_dir, 'VBA', s)
        if os.path.exists(p):
            os.remove(p)

    out_bin = '/tmp/olympiad_vbaProject.bin'
    subprocess.run(['python3', '-m', 'ms_cfb', '-c', ole_dir, '-f', out_bin], check=True, cwd=work_dir)
    os.system('rm -rf %s' % work_dir)
    return out_bin


def col_letter(idx: int) -> str:
    """1-based column index → Excel letter."""
    letters = ''
    while idx > 0:
        idx, rem = divmod(idx - 1, 26)
        letters = chr(65 + rem) + letters
    return letters


def build_vba_code(schema) -> str:
    keys = schema['keys']
    types = schema['types']
    idx = {key: i + 1 for i, key in enumerate(keys)}
    last_col = col_letter(len(keys))

    type_case = '|' + '|'.join(types) + '|'
    last_key = keys[-1].upper()

    consts = []
    for key in keys:
        consts.append('Const C_%s As Long = %d' % (key.upper(), idx[key]))

    return '''Attribute VB_Name = "Module1"
Option Explicit

' ==============================================================================
' ШАБЛОН ИМПОРТА ВОПРОСОВ (WP OLYMPIAD) — макросы проверки и экспорта
' Колонки: A..%LAST_COL% (%COUNT% шт.). Типы заданий: %TYPE_COUNT%.
' ==============================================================================

%CONSTS%

' ------------------------------------------------------------------------------
' Нормализация типа задания: русские названия -> код плагина
' ------------------------------------------------------------------------------
Private Function NormalizeType(ByVal t As String) As String
    t = LCase$(Trim$(t))
    t = Replace(t, "ё", "е")
    t = Replace(t, "_", " ")
    t = Replace(t, "  ", " ")
    Select Case t
        Case "single", "radio", "один", "один выбор", "одиночный выбор", "тест", "выбор одного"
            NormalizeType = "single"
        Case "multi", "checkbox", "множественный", "множественный выбор", "несколько ответов"
            NormalizeType = "multi"
        Case "dropdown", "выпадающий", "выпадающий список", "список"
            NormalizeType = "dropdown"
        Case "boolean", "true false", "true/false", "да нет", "да/нет", "верно неверно", "верно/неверно"
            NormalizeType = "boolean"
        Case "number", "numeric", "число", "числовой", "числовой ответ", "задача"
            NormalizeType = "number"
        Case "text", "short text", "текст", "текстовый", "текстовый ответ", "краткий ответ"
            NormalizeType = "text"
        Case "matching", "match", "соответствие", "соответствия", "сопоставление"
            NormalizeType = "matching"
        Case "ordering", "sequence", "последовательность", "порядок", "хронология"
            NormalizeType = "ordering"
        Case "fill blank", "fill_blank", "blank", "cloze", "пропуски", "заполнение пропусков"
            NormalizeType = "fill_blank"
        Case "slider", "scale", "шкала", "ползунок", "диапазон"
            NormalizeType = "slider"
        Case "word scramble", "word_scramble", "scramble", "anagram", "анаграмма", "составление слова", "буквы"
            NormalizeType = "word_scramble"
        Case "dictation", "диктант", "интерактивный диктант"
            NormalizeType = "dictation"
        Case "essay", "long answer", "эссе", "развернутый ответ", "решение", "развёрнутый ответ"
            NormalizeType = "essay"
        Case Else
            NormalizeType = t
    End Select
End Function

Private Function IsNumericCell(ByVal v As Variant) As Boolean
    Dim s As String
    s = Trim$(CStr(v))
    s = Replace(s, ",", ".")
    s = Replace(s, "±", "")
    s = Replace(s, " ", "")
    If s = "" Then
        IsNumericCell = False
    Else
        IsNumericCell = IsNumeric(s)
    End If
End Function

' ------------------------------------------------------------------------------
' ПРОВЕРКА ДАННЫХ ПЕРЕД ИМПОРТОМ
' ------------------------------------------------------------------------------
Sub ValidateQuestions()
    Dim ws As Worksheet: Set ws = ActiveSheet
    Dim lastRow As Long, r As Long, errCount As Long, warnCount As Long
    Dim qType As String, title As String, tpl As String, v As String

    lastRow = ws.Cells(ws.Rows.Count, C_TITLE).End(xlUp).Row
    If lastRow < 2 Then
        MsgBox "Таблица не содержит вопросов для проверки." & vbCrLf & _
               "Заполните строки начиная со 2-й.", vbExclamation, "WP Olympiad — Проверка"
        Exit Sub
    End If

    errCount = 0
    warnCount = 0
    ws.Range(ws.Cells(2, 1), ws.Cells(lastRow, C_%LAST_KEY%)).Interior.Pattern = xlNone

    For r = 2 To lastRow
        qType = NormalizeType(CStr(ws.Cells(r, C_TYPE).Value))
        title = Trim$(CStr(ws.Cells(r, C_TITLE).Value))

        If qType = "" And title = "" Then GoTo NextRow

        ' 1. Тип задания
        If qType = "" Then
            ws.Cells(r, C_TYPE).Interior.Color = RGB(255, 205, 210)
            errCount = errCount + 1
        ElseIf InStr(1, "%TYPE_LIST%", "|" & qType & "|", vbTextCompare) = 0 Then
            ws.Cells(r, C_TYPE).Interior.Color = RGB(255, 205, 210)
            errCount = errCount + 1
        End If

        ' 2. Формулировка
        If title = "" Then
            ws.Cells(r, C_TITLE).Interior.Color = RGB(255, 205, 210)
            errCount = errCount + 1
        End If

        ' 3. Обязательность (0/1)
        v = Trim$(CStr(ws.Cells(r, C_IS_REQUIRED).Value))
        If v <> "" And v <> "0" And v <> "1" Then
            ws.Cells(r, C_IS_REQUIRED).Interior.Color = RGB(255, 205, 210)
            errCount = errCount + 1
        End If

        ' 4. Поля, специфичные для типа
        Select Case qType
            Case "single", "multi", "dropdown", "matching", "ordering"
                If Trim$(CStr(ws.Cells(r, C_CHOICES).Value)) = "" Then
                    ws.Cells(r, C_CHOICES).Interior.Color = RGB(255, 205, 210)
                    errCount = errCount + 1
                End If

            Case "number", "slider"
                If Not IsNumericCell(ws.Cells(r, C_TARGET_VALUE).Value) Then
                    ws.Cells(r, C_TARGET_VALUE).Interior.Color = RGB(255, 205, 210)
                    errCount = errCount + 1
                End If
                If qType = "slider" And Trim$(CStr(ws.Cells(r, C_EXTRA).Value)) = "" Then
                    ws.Cells(r, C_EXTRA).Interior.Color = RGB(255, 235, 156)
                    warnCount = warnCount + 1
                End If

            Case "text"
                If Trim$(CStr(ws.Cells(r, C_ANSWERS).Value)) = "" And Trim$(CStr(ws.Cells(r, C_CORRECT).Value)) = "" Then
                    ws.Cells(r, C_ANSWERS).Interior.Color = RGB(255, 205, 210)
                    errCount = errCount + 1
                End If

            Case "word_scramble"
                If Trim$(CStr(ws.Cells(r, C_ANSWERS).Value)) = "" Then
                    ws.Cells(r, C_ANSWERS).Interior.Color = RGB(255, 205, 210)
                    errCount = errCount + 1
                End If

            Case "boolean"
                If Trim$(CStr(ws.Cells(r, C_CORRECT).Value)) = "" Then
                    ws.Cells(r, C_CORRECT).Interior.Color = RGB(255, 205, 210)
                    errCount = errCount + 1
                End If

            Case "fill_blank", "dictation"
                tpl = Trim$(CStr(ws.Cells(r, C_TEMPLATE).Value))
                If tpl = "" Then tpl = title
                If InStr(tpl, "{") = 0 Or InStr(tpl, "}") = 0 Then
                    ws.Cells(r, C_TITLE).Interior.Color = RGB(255, 205, 210)
                    errCount = errCount + 1
                End If
        End Select

NextRow:
    Next r

    If errCount = 0 Then
        MsgBox "Проверка успешно пройдена!" & vbCrLf & vbCrLf & _
               "Всего заданий проверено: " & (lastRow - 1) & vbCrLf & _
               "Предупреждений: " & warnCount & vbCrLf & _
               "Ошибок не обнаружено. Таблица готова к импорту.", _
               vbInformation, "WP Olympiad — Проверка данных"
    Else
        MsgBox "Обнаружено ошибок: " & errCount & vbCrLf & _
               "Предупреждений: " & warnCount & vbCrLf & vbCrLf & _
               "Ячейки с некорректными данными выделены цветом:" & vbCrLf & _
               "  • красный — ошибка, строка не импортируется;" & vbCrLf & _
               "  • жёлтый — предупреждение, импорт возможен.", _
               vbCritical, "WP Olympiad — Ошибки валидации"
    End If
End Sub

' ------------------------------------------------------------------------------
' ЭКСПОРТ В CSV (UTF-8, разделитель «;») — готов к импорту без правок
' ------------------------------------------------------------------------------
Sub ExportToCSV()
    Dim ws As Worksheet: Set ws = ActiveSheet
    Dim lastRow As Long, lastCol As Long, r As Long, c As Long
    lastRow = ws.Cells(ws.Rows.Count, C_TITLE).End(xlUp).Row
    If lastRow < 2 Then
        MsgBox "Таблица пуста! Нечего экспортировать.", vbExclamation, "WP Olympiad"
        Exit Sub
    End If

    Dim defaultPath As String
    defaultPath = "questions.csv"
    If ws.Parent.Path <> "" Then
        defaultPath = ws.Parent.Path & Application.PathSeparator & "questions.csv"
    End If

    Dim fName As Variant
    fName = Application.GetSaveAsFilename(InitialFileName:=defaultPath, _
               FileFilter:="CSV Files (*.csv), *.csv", _
               Title:="Экспорт вопросов в CSV (WP Olympiad)")
    If fName = False Then Exit Sub

    lastCol = ws.Cells(1, ws.Columns.Count).End(xlToLeft).Column
    If lastCol < %COUNT% Then lastCol = %COUNT%
    If lastCol > %COUNT% Then lastCol = %COUNT%

    Dim adStream As Object
    Set adStream = CreateObject("ADODB.Stream")
    adStream.Type = 2
    adStream.Charset = "utf-8"
    adStream.Open

    Dim lineStr As String, val As String
    For r = 1 To lastRow
        lineStr = ""
        For c = 1 To lastCol
            val = CStr(ws.Cells(r, c).Value)
            val = Replace(val, """", """""")
            If InStr(val, ";") > 0 Or InStr(val, """") > 0 Or InStr(val, vbLf) > 0 Or InStr(val, vbCr) > 0 Then
                val = """" & val & """"
            End If
            If lineStr <> "" Then lineStr = lineStr & ";"
            lineStr = lineStr & val
        Next c
        adStream.WriteText lineStr & vbCrLf
    Next r

    adStream.SaveToFile fName, 2
    adStream.Close

    MsgBox "Экспорт успешно завершён!" & vbCrLf & vbCrLf & _
           "Файл: " & fName & vbCrLf & _
           "Экспортировано заданий: " & (lastRow - 1) & vbCrLf & vbCrLf & _
           "Положите файл в сетевую папку (import/) — плагин импортирует его автоматически.", _
           vbInformation, "WP Olympiad — Экспорт завершён"
End Sub

' ------------------------------------------------------------------------------
' ДОБАВИТЬ ПУСТУЮ СТРОКУ С ЗАГОТОВКОЙ
' ------------------------------------------------------------------------------
Sub AddSampleRow()
    Dim ws As Worksheet: Set ws = ActiveSheet
    Dim nextRow As Long
    nextRow = ws.Cells(ws.Rows.Count, C_TITLE).End(xlUp).Row + 1
    If nextRow < 2 Then nextRow = 2

    ws.Cells(nextRow, C_TYPE).Value = "single"
    ws.Cells(nextRow, C_TITLE).Value = "Новый вопрос олимпиады #" & (nextRow - 1)
    ws.Cells(nextRow, C_POINTS).Value = 1
    ws.Cells(nextRow, C_PENALTY).Value = 0
    ws.Cells(nextRow, C_GROUP_NAME).Value = "Основной раздел"
    ws.Cells(nextRow, C_CHOICES).Value = "*Правильный вариант | Вариант 2 | Вариант 3"
    ws.Cells(nextRow, C_EXPLANATION).Value = "Пояснение к правильному ответу"
    ws.Cells(nextRow, C_IS_REQUIRED).Value = 1

    ws.Range(ws.Cells(nextRow, 1), ws.Cells(nextRow, %COUNT%)).Interior.Pattern = xlNone
    ws.Cells(nextRow, C_TITLE).Select

    MsgBox "Добавлена новая строка #" & nextRow & ". Заполните параметры задания.", vbInformation, "WP Olympiad"
End Sub

' ------------------------------------------------------------------------------
' ОЧИСТИТЬ ТАБЛИЦУ (заголовки остаются)
' ------------------------------------------------------------------------------
Sub ClearQuestions()
    Dim ws As Worksheet: Set ws = ActiveSheet
    Dim lastRow As Long
    lastRow = ws.Cells(ws.Rows.Count, C_TITLE).End(xlUp).Row
    If lastRow < 2 Then
        MsgBox "Таблица уже пуста.", vbInformation, "WP Olympiad"
        Exit Sub
    End If

    If MsgBox("Вы действительно хотите удалить все вопросы из таблицы?" & vbCrLf & _
              "Будут очищены строки со 2 по " & lastRow & " (заголовки останутся).", _
              vbYesNo + vbQuestion, "Подтверждение очистки") = vbYes Then
        ws.Range(ws.Cells(2, 1), ws.Cells(lastRow, %COUNT%)).ClearContents
        ws.Range(ws.Cells(2, 1), ws.Cells(lastRow, %COUNT%)).Interior.Pattern = xlNone
        MsgBox "Таблица вопросов очищена.", vbInformation, "WP Olympiad"
    End If
End Sub
''' .replace('%LAST_COL%', last_col) \
    .replace('%COUNT%', str(len(keys))) \
    .replace('%TYPE_COUNT%', str(len(types))) \
    .replace('%CONSTS%', '\n'.join(consts)) \
    .replace('%TYPE_LIST%', type_case) \
    .replace('%TYPE_COUNT%', str(len(types))) \
    .replace('%LAST_KEY%', last_key)


def finalize_vba(code: str) -> str:
    return code.replace('\r\n', '\n').replace('\n', '\r\n')


def create_excel_workbook(file_path, schema, is_macro=False, vba_bin=None):
    keys = schema['keys']
    columns = schema['columns']
    samples = schema['samples']
    catalog = schema.get('type_catalog', [])
    total_cols = len(keys)

    wb = xlsxwriter.Workbook(file_path)
    if is_macro and vba_bin:
        wb.add_vba_project(vba_bin)

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

    buttons_col = total_cols + 1  # one empty spacer column before the macro panel
    if is_macro:
        buttons_letter = col_letter(buttons_col + 1)
        ws.set_column(buttons_col, buttons_col, 25)
        ws.write(0, buttons_col, '⚙ МАКРОСЫ', wb.add_format({'bold': True, 'font_size': 11, 'color': '#1E3A8A', 'align': 'center'}))
        ws.insert_button('%s2' % buttons_letter, {'macro': 'ValidateQuestions', 'caption': '✔ Проверить данные', 'width': 185, 'height': 34})
        ws.insert_button('%s4' % buttons_letter, {'macro': 'ExportToCSV', 'caption': '📥 Экспорт в CSV', 'width': 185, 'height': 34})
        ws.insert_button('%s6' % buttons_letter, {'macro': 'AddSampleRow', 'caption': '➕ Добавить строку', 'width': 185, 'height': 34})
        ws.insert_button('%s8' % buttons_letter, {'macro': 'ClearQuestions', 'caption': '🧹 Очистить таблицу', 'width': 185, 'height': 34})

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
        'Для проверки нажмите «✔ Проверить данные» (в файле .xlsm) — макрос подсветит ошибки.',
        'Сохраните файл как questions.csv в кодировке UTF-8 или нажмите «📥 Экспорт в CSV».',
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

    vba_bin = build_vba_project_bin(finalize_vba(build_vba_code(schema)))

    xlsm_path = os.path.join(TEMPLATES_DIR, 'questions_template_macros.xlsm')
    xlsx_path = os.path.join(TEMPLATES_DIR, 'questions_template.xlsx')

    create_excel_workbook(xlsm_path, schema, is_macro=True, vba_bin=vba_bin)
    create_excel_workbook(xlsx_path, schema, is_macro=False)

    legacy_names = [
        'шаблон_вопросов_макросы.xlsm', 'шаблон_вопросов.xlsx', 'шаблон_вопросов.csv',
        'шаблон_вопросов.json', 'инструкция_по_импорту.txt',
    ]
    for target_dir in (TEMPLATES_DIR, WP_TEST_TEMPLATES):
        if not os.path.isdir(target_dir):
            continue
        for fname in ('questions_template_macros.xlsm', 'questions_template.xlsx'):
            src = os.path.join(TEMPLATES_DIR, fname)
            dst = os.path.join(target_dir, fname)
            if os.path.abspath(src) != os.path.abspath(dst):
                shutil.copyfile(src, dst)
        for legacy in legacy_names:
            path = os.path.join(target_dir, legacy)
            if os.path.exists(path):
                os.remove(path)

    print('Excel templates generated (%d columns, %d types) and deployed.' % (len(schema['keys']), len(schema['types'])))


if __name__ == '__main__':
    main()
