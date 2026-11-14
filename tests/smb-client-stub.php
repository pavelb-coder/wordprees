<?php
/**
 * Заглушка smbclient для проверок доставки в сетевую папку: воспроизводит вывод
 * реального клиента, чтобы разбирание результатов транспорта можно было проверить
 * без SAMBA на стенде.
 *
 * Режим задаётся переменными окружения:
 *  - WPO_STUB_ALL_FAIL=1     — ни один put не удаётся;
 *  - WPO_STUB_STATUS=…       — какой NT_STATUS печатать при этом;
 *  - WPO_STUB_GLOBAL=…       — строка с ошибкой, не привязанная к файлу;
 *  - WPO_STUB_MODE=collision|locked|readonly|missingdir — поведение put:
 *      collision  — NT_STATUS_OBJECT_NAME_COLLISION (имя занято);
 *      locked     — NT_STATUS_SHARING_VIOLATION (файл открыт другим процессом);
 *      readonly   — коллизия, пока с файла не сняли защиту («setmode … -r»);
 *      missingdir — на share не создано ни одной папки: каталоги нужно строить по
 *                   сегментам, иначе put отвечает OBJECT_PATH_NOT_FOUND;
 *  - WPO_STUB_SIZE=N         — размер файла для ответа на «ls»;
 *  - WPO_STUB_EXISTING=a;b   — каталоги, которые на share уже есть (в выводе — коллизия);
 *  - WPO_STUB_STATE=<файл>   — состояние «сервера» между вызовами (папки, файлы, защита);
 *  - WPO_STUB_LOG=<файл>     — куда дописывать строку с командами каждого вызова.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$argv = array_slice($argv, 1);
$cmds = '';
for ($i = 0, $n = count($argv); $i < $n; $i++) {
    if ($argv[$i] === '-c' && isset($argv[$i + 1])) {
        $cmds = (string)$argv[$i + 1];
    }
}

$log = (string)(getenv('WPO_STUB_LOG') ?: '');
if ($log !== '') {
    file_put_contents($log, $cmds . "\n", FILE_APPEND);
}

$mode = (string)(getenv('WPO_STUB_MODE') ?: '');
$state_file = (string)(getenv('WPO_STUB_STATE') ?: '');
$size_to_report = getenv('WPO_STUB_SIZE');

// Путь вида «"\olimp\вложения"» — от корня шары, «"olimp"» — относительно cwd.
$to_key = static function (string $p, string $cur): string {
    $p = trim($p, " \t\"'");
    if ($p === '' || $p === '.') {
        return '';
    }
    // Ведущий слэш (любой) — путь от корня шары: так их печатает сам smbclient.
    // Всё остальное разрешается относительно текущего каталога подключения.
    $absolute = $p[0] === '\\' || $p[0] === '/';
    $p = preg_replace('#[\\/]+#', '\\', $p);
    if ($absolute) {
        return strtolower(trim($p, '\\/'));
    }
    if ($cur === '') {
        return strtolower($p);
    }

    return strtolower(preg_replace('#[\\/]+#', '\\', $cur) . '\\' . $p);
};
$parent_of = static function (string $key): string {
    $pos = strrpos($key, '\\');

    return $pos === false ? '' : substr($key, 0, $pos);
};
$basename_of = static function (string $key): string {
    $pos = strrpos($key, '\\');

    return $pos === false ? $key : substr($key, $pos + 1);
};

$state = ['dirs' => [], 'files' => [], 'readonly' => []];
if ($state_file !== '' && is_file($state_file)) {
    $loaded = json_decode((string)file_get_contents($state_file), true);
    if (is_array($loaded)) {
        $state = $loaded + $state;
    }
}
// SMB не различает регистр имён: ключи модели сервера приводим к нижнему регистру.
$state['dirs'] = array_values(array_unique(array_map('strtolower', array_map('strval', (array) $state['dirs']))));
$state['files'] = array_change_key_case((array) $state['files'], CASE_LOWER);
$state['readonly'] = array_values(array_unique(array_map('strtolower', array_map('strval', (array) $state['readonly']))));
// WPO_STUB_READONLY=1 — всё, что уже лежит на share, защищено от перезаписи.
if (getenv('WPO_STUB_READONLY') === '1') {
    foreach (array_keys($state['files']) as $k) {
        if (!in_array($k, $state['readonly'], true)) {
            $state['readonly'][] = $k;
        }
    }
}
foreach (array_filter(array_map('trim', explode(';', (string)(getenv('WPO_STUB_EXISTING') ?: '')))) as $pre) {
    $pre = strtolower(trim(str_replace('/', '\\', $pre), " \t\\\\"));
    if ($pre !== '' && !in_array($pre, $state['dirs'], true)) {
        $state['dirs'][] = $pre;
    }
}

$has_dir = static function (string $key) use (&$state): bool {
    return $key === '' || in_array($key, $state['dirs'], true);
};

preg_match_all('/(mkdir|cd|put|setmode|rm|del|ls)[ ]+"([^"]*)"(?:[ ]+"([^"]*)")?([ ]+[-+][a-z]+)?/', $cmds, $found, PREG_SET_ORDER);

// setmode «+r/-r» передаётся флагами после имени — вытаскиваем их отдельно.
$flags_of = [];
if (preg_match_all('/setmode[ ]+"[^"]*"([ ]+[-+][a-z]+)/i', $cmds, $fm)) {
    foreach ($fm[1] as $idx => $f) {
        $flags_of[$idx] = trim($f);
    }
}

$fail = false;
$cur = '';

if ($found) {
    foreach ($found as $idx => $c) {
        $verb = $c[1];
        if ($verb === 'put' || $verb === 'rm' || $verb === 'del' || $verb === 'setmode' || $verb === 'ls') {
            $target = isset($c[3]) && $c[3] !== '' ? $c[3] : $c[2];
        } else {
            $target = $c[2];
        }
        $key = $to_key($target, $cur);

        if ($verb === 'ls') {
            // WPO_STUB_SIZE задаёт ответ «как есть»: так проверяется реакция плагина
            // на чужой или обрезанный файл на share.
            $reported = ($size_to_report === false || $size_to_report === '') ? null : (int)$size_to_report;
            if ($reported === null && isset($state['files'][$key])) {
                $reported = (int)$state['files'][$key]['size'];
            }
            if ($reported === null) {
                echo 'ntstatus_error: NT_STATUS_NO_SUCH_FILE listing \\' . $key . PHP_EOL;
                continue;
            }
            echo '  ' . $basename_of($key) . "              A        " . $reported . "  Fri Oct 24 10:00:00 2025\n";
            continue;
        }

        if ($verb === 'mkdir') {
            if ($key === '' || $has_dir($key)) {
                echo 'NT_STATUS_OBJECT_NAME_COLLISION making remote directory \\' . $key . PHP_EOL;
                continue;
            }
            if (!$has_dir($parent_of($key))) {
                echo 'ntstatus_error: NT_STATUS_OBJECT_PATH_NOT_FOUND making remote directory \\' . $key . PHP_EOL;
                $fail = true;
                continue;
            }
            $state['dirs'][] = $key;
            $state['dirs'] = array_values(array_unique($state['dirs']));
            continue;
        }

        if ($verb === 'cd') {
            if ($key === '' || $has_dir($key)) {
                $cur = $key;
                continue;
            }
            // Реальный smbclient отвечает «cd \\путь\\: NT_STATUS_OBJECT_NAME_NOT_FOUND»
            // и выходит с кодом 0: по строке и судят, что каталога нет.
            echo 'cd \\' . $key . '\\: NT_STATUS_OBJECT_NAME_NOT_FOUND' . PHP_EOL;
            $fail = true;
            continue;
        }

        if ($verb === 'setmode') {
            if (!isset($state['files'][$key])) {
                echo 'ntstatus_error: NT_STATUS_NO_SUCH_FILE setting mode \\' . $key . PHP_EOL;
                $fail = true;
                continue;
            }
            $flags = trim((string)($c[4] ?? ''));
            if (strpos($flags, '-r') !== false) {
                $state['readonly'] = array_values(array_filter($state['readonly'], static fn($p) => $p !== $key));
            } elseif (strpos($flags, '+r') !== false && !in_array($key, $state['readonly'], true)) {
                $state['readonly'][] = $key;
            }
            continue;
        }

        if ($verb === 'put') {
            $dir = $parent_of($key);
            if (!$has_dir($dir)) {
                echo 'ntstatus_error: NT_STATUS_OBJECT_PATH_NOT_FOUND opening remote file \\' . $key . PHP_EOL;
                $fail = true;
                continue;
            }
            $readonly = in_array($key, $state['readonly'], true);
            if ($mode === 'collision' || ($mode === 'readonly' && $readonly)) {
                echo 'ntstatus_error: NT_STATUS_OBJECT_NAME_COLLISION opening remote file \\' . $key . PHP_EOL;
                $fail = true;
                continue;
            }
            if ($mode === 'locked') {
                echo 'ntstatus_error: NT_STATUS_SHARING_VIOLATION opening remote file \\' . $key . PHP_EOL;
                $fail = true;
                continue;
            }
            if (getenv('WPO_STUB_ALL_FAIL') === '1') {
                echo 'ntstatus_error: NT_STATUS_' . (getenv('WPO_STUB_STATUS') ?: 'SHARING_VIOLATION') . ' opening remote file \\' . $key . PHP_EOL;
                $fail = true;
                continue;
            }
            if (stripos($key, 'QuestionStudio.exe') !== false) {
                echo 'ntstatus_error: NT_STATUS_SHARING_VIOLATION opening remote file \\' . $key . PHP_EOL;
                $fail = true;
                continue;
            }
            $bytes = is_file($c[2]) ? (string)file_get_contents($c[2]) : 'x';
            $state['files'][$key] = ['size' => strlen($bytes)];
            echo 'putting file ' . $c[2] . ' as \\' . $key . ' (100.0%)' . PHP_EOL;
            continue;
        }

        if ($verb === 'rm' || $verb === 'del') {
            unset($state['files'][$key]);
            $state['readonly'] = array_values(array_filter($state['readonly'], static fn($p) => $p !== $key));
            echo 'delete \\' . $key . PHP_EOL;
        }
    }
}

if (getenv('WPO_STUB_GLOBAL') !== false && getenv('WPO_STUB_GLOBAL') !== '') {
    echo 'ntstatus_error: NT_STATUS_' . getenv('WPO_STUB_GLOBAL') . ' (global) ' . PHP_EOL;
    $fail = true;
}

if ($state_file !== '') {
    file_put_contents($state_file, json_encode($state));
}

exit($fail ? 1 : 0);
