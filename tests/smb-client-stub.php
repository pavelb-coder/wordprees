<?php
/**
 * Заглушка smbclient для тестов 229 и 230: воспроизводит вывод реального клиента,
 * чтобы разбирание результатов доставки можно было проверить без SAMBA на стенде.
 *
 * Режим задаётся переменными окружения:
 *  - WPO_STUB_ALL_FAIL=1   — ни один put не удаётся;
 *  - WPO_STUB_STATUS=…      — какой NT_STATUS печатать при этом;
 *  - WPO_STUB_GLOBAL=…      — строка с ошибкой, не привязанная к файлу;
 *  - WPO_STUB_MODE=collision— put отвечает NT_STATUS_OBJECT_NAME_COLLISION;
 *  - WPO_STUB_SIZE=N        — размер файла для ответа на «ls»;
 *  - WPO_STUB_LOG=<файл>    — куда дописывать строку с командами каждого вызова.
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
$fail = false;

// Ответ на проверку размера: «ls "<имя>» -> строка в формате smbclient.
if (strpos($cmds, 'ls "') !== false && preg_match_all('/ls "([^"]+)"/', $cmds, $ls)) {
    $size = (int)(getenv('WPO_STUB_SIZE') ?: 0);
    foreach ($ls[1] as $name) {
        $base = basename(str_replace('\\', '/', $name));
        echo '  ' . $base . "              A        " . $size . "  Fri Oct 24 10:00:00 2025\n";
    }
    exit(0);
}

echo 'NT_STATUS_OBJECT_NAME_COLLISION making remote directory \\olimp' . PHP_EOL;

if (getenv('WPO_STUB_GLOBAL') !== false && getenv('WPO_STUB_GLOBAL') !== '') {
    echo 'ntstatus_error: NT_STATUS_' . getenv('WPO_STUB_GLOBAL') . ' (global) ' . PHP_EOL;
    $fail = true;
}

if (preg_match_all('/put "([^"]+)" "([^"]+)"/', $cmds, $m, PREG_SET_ORDER)) {
    foreach ($m as $p) {
        $remote = $p[2];
        if ($mode === 'collision') {
            echo 'ntstatus_error: NT_STATUS_OBJECT_NAME_COLLISION opening remote file \\' . $remote . PHP_EOL;
            $fail = true;
            continue;
        }
        if (getenv('WPO_STUB_ALL_FAIL') === '1') {
            echo 'ntstatus_error: NT_STATUS_' . (getenv('WPO_STUB_STATUS') ?: 'SHARING_VIOLATION') . ' opening remote file \\' . $remote . PHP_EOL;
            $fail = true;
            continue;
        }
        if (stripos($remote, 'QuestionStudio.exe') !== false) {
            echo 'ntstatus_error: NT_STATUS_SHARING_VIOLATION opening remote file \\' . $remote . PHP_EOL;
            $fail = true;
            continue;
        }
        echo 'putting file ' . $p[1] . ' as \\' . $remote . ' (100.0%)' . PHP_EOL;
    }
}

exit($fail ? 1 : 0);
