<?php
declare(strict_types=1);

namespace WPOlympiad\Tests;

use WPOlympiad\Attempt;
use WPOlympiad\Export;
use WPOlympiad\NetworkExport;
use WPOlympiad\Question;
use WPOlympiad\Quiz;

if (!defined('ABSPATH') || !isset($it)) {
    return;
}

$protocol_assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};

$it('Протокол: в новом комплекте и совместимом API есть только основной CSV', function () use ($protocol_assert): bool {
    $id = Quiz::create(['title' => 'Protocol primary fixture', 'status' => 'publish', 'settings' => ['max_attempts' => 0]]);
    try {
        $quiz = Quiz::get($id);
        $protocol_assert($quiz instanceof Quiz, 'Quiz was not created');
        Question::create([
            'quiz_id' => $id,
            'question_type' => 'single',
            'title' => 'Тестовый вопрос протокола',
            'points' => 2,
            'options' => ['choices' => [['id' => 'a', 'text' => 'Да', 'is_correct' => 1]]],
        ]);
        $attempt = Attempt::start($id, ['name' => 'Тестовый участник', 'email' => 'example@example.invalid']);
        $protocol_assert($attempt instanceof Attempt, 'Attempt was not created');
        $attempt->complete(2, 2, true, 'completed');

        $package = NetworkExport::generate_export_files($quiz);
        $primary = 'протоколы/протокол_' . NetworkExport::build_safe_slug($quiz) . '.csv';
        $protocols = array_values(array_filter(array_keys($package['files']), static fn(string $name): bool => str_starts_with($name, 'протоколы/')));
        $protocol_assert($protocols === [$primary] && $package['primary_filename'] === $primary, 'Expected exactly the primary protocol');
        $csv = $package['files'][$primary];
        $protocol_assert($csv === Export::export_quiz_attempts($id, false), 'Primary protocol no longer contains the complete group export');
        $protocol_assert(str_starts_with($csv, "\xEF\xBB\xBF") && str_contains($csv, 'Тестовый участник') && str_contains($csv, 'Тестовый вопрос протокола'), 'Protocol content or UTF-8 BOM changed');
        $protocol_assert(isset($package['files']['вопросы/список_заданий.csv']), 'Question export changed');
        $protocol_assert(!array_filter(array_keys($package['files']), static fn(string $name): bool => str_starts_with($name, 'отчёты/')), 'Report files still generated');
        $attempts = array_filter(array_keys($package['files']), static fn(string $name): bool => str_starts_with($name, 'попытки/'));
        $protocol_assert(count($attempts) === 1, 'Individual attempt export changed');
        $compat = NetworkExport::generate_export_package($quiz);
        return $compat['filename'] === $primary && $compat['protocol_csv'] === $csv && $compat['files_count'] === count($compat['files']);
    } finally {
        Quiz::delete($id);
    }
});

$it('Протокол: повторная выгрузка обновляет основной файл, старые копии и другие олимпиады сохраняются', function () use ($protocol_assert): bool {
    $root = sys_get_temp_dir() . '/wpo_primary_protocol_' . wp_generate_password(10, false, false);
    wp_mkdir_p($root . '/протоколы');
    wp_mkdir_p($root . '/вопросы');
    $id = Quiz::create(['title' => 'Protocol first fixture', 'status' => 'publish', 'settings' => ['max_attempts' => 0]]);
    $other_id = Quiz::create(['title' => 'Protocol second fixture', 'status' => 'publish', 'settings' => ['max_attempts' => 0]]);
    try {
        $quiz = Quiz::get($id);
        $other = Quiz::get($other_id);
        $protocol_assert($quiz instanceof Quiz && $other instanceof Quiz, 'Quiz fixtures not created');
        $slug = NetworkExport::build_safe_slug($quiz);
        $primary = 'протоколы/протокол_' . $slug . '.csv';
        $other_primary = 'протоколы/протокол_' . NetworkExport::build_safe_slug($other) . '.csv';
        $protocol_assert($primary !== $other_primary, 'Fixture slugs are not distinct');
        $preserve = [
            'протоколы/сводный_протокол.csv' => 'Ручные исправления в старом общем файле',
            'протоколы/ответы_участников.csv' => 'Старые ответы другой олимпиады',
            'протоколы/протокол_' . $slug . '_2026-01-01_09-10-11.csv' => 'Исторический снимок',
            'протоколы/чужой_файл.csv' => 'Чужие данные',
            'вопросы/свои_заметки.txt' => 'Собственные заметки организатора',
        ];
        foreach ($preserve as $path => $content) {
            file_put_contents($root . '/' . $path, $content);
        }
        file_put_contents($root . '/' . $primary, 'Устаревший основной протокол');
        $first_attempt = Attempt::start($id, ['name' => 'Первый участник', 'email' => 'first@example.invalid']);
        $protocol_assert($first_attempt instanceof Attempt, 'First attempt was not created');
        $first_attempt->complete(1, 2, true, 'completed');
        $config = ['enabled' => true, 'type' => 'filesystem', 'path' => $root];
        $result = NetworkExport::export_quiz($id, $config);
        $protocol_assert(!empty($result['success']) && $result['primary_file'] === $primary, 'First export failed');
        $protocol_assert(array_values(array_filter($result['files_list'], static fn(string $name): bool => str_starts_with($name, 'протоколы/'))) === [$primary], 'Extra protocol names in delivery');
        $protocol_assert(str_contains((string)file_get_contents($root . '/' . $primary), 'Первый участник'), 'Primary file was not updated');

        $second_attempt = Attempt::start($id, ['name' => 'Второй участник', 'email' => 'second@example.invalid']);
        $protocol_assert($second_attempt instanceof Attempt, 'Second attempt was not created');
        $second_attempt->complete(2, 2, true, 'completed');
        $result = NetworkExport::export_quiz($id, $config);
        $protocol_assert(!empty($result['success']), 'Repeated export failed');
        $updated = (string)file_get_contents($root . '/' . $primary);
        $protocol_assert(str_contains($updated, 'Первый участник') && str_contains($updated, 'Второй участник'), 'Repeat did not update the same primary file');

        $other_result = NetworkExport::export_quiz($other_id, $config);
        $protocol_assert(!empty($other_result['success']) && file_exists($root . '/' . $other_primary), 'Other quiz primary missing');
        $protocol_assert(file_get_contents($root . '/' . $primary) === $updated, 'Other quiz overwrote the first primary');
        foreach ($preserve as $path => $content) {
            $protocol_assert(file_get_contents($root . '/' . $path) === $content, 'Existing file changed: ' . $path);
        }
        $files = glob($root . '/протоколы/*.csv');
        return is_array($files) && count($files) === 6;
    } finally {
        Quiz::delete($id);
        Quiz::delete($other_id);
        exec('rm -rf ' . escapeshellarg($root));
    }
});

$it('Протокол / WebDAV: один PUT основного CSV без DELETE старых протоколов', function () use ($protocol_assert): bool {
    $id = Quiz::create(['title' => 'Protocol WebDAV fixture', 'status' => 'publish']);
    $calls = [];
    $mock = static function ($pre, $args, $url) use (&$calls) {
        if (!str_starts_with($url, 'https://protocol-export.invalid/share')) {
            return $pre;
        }
        $calls[] = ['method' => $args['method'], 'path' => rawurldecode((string)parse_url($url, PHP_URL_PATH))];
        $code = in_array($args['method'], ['DELETE', 'PROPFIND'], true) ? 404 : ($args['method'] === 'MKCOL' ? 405 : 201);
        return ['headers' => [], 'body' => '', 'response' => ['code' => $code, 'message' => 'QA'], 'cookies' => []];
    };
    try {
        add_filter('pre_http_request', $mock, 10, 3);
        $result = NetworkExport::export_quiz($id, ['type' => 'webdav', 'path' => 'https://protocol-export.invalid/share']);
        $protocol_assert(!empty($result['success']), 'WebDAV export failed');
        $protocol_requests = array_values(array_filter($calls, static fn(array $c): bool => str_starts_with($c['path'], '/share/протоколы/')));
        $expected = '/share/' . $result['primary_file'];
        return $protocol_requests === [['method' => 'PUT', 'path' => $expected]];
    } finally {
        remove_filter('pre_http_request', $mock, 10);
        Quiz::delete($id);
    }
});
