<?php
/**
 * Dump the import template schema (columns, samples, question type catalog) from
 * WPOlympiad\ImportTemplate into tools/import-samples.json.
 *
 * The JSON file is consumed by tools/generate_excel_templates.py so that the Excel
 * workbooks always match the plugin's single source of truth.
 *
 * Run: wp eval-file tools/dump_import_schema.php --path=/path/to/wordpress
 * Or:  tools/build_templates.sh
 */

if (!class_exists('WPOlympiad\\ImportTemplate')) {
    fwrite(STDERR, "WP Olympiad is not loaded — run this file through WP-CLI eval-file.\n");
    exit(1);
}

use WPOlympiad\ImportTemplate;
use WPOlympiad\Question;

$columns = [];
foreach (ImportTemplate::columns() as $key => $meta) {
    $columns[] = [
        'key'      => $key,
        'label'    => $meta['label'] ?? $key,
        'hint'     => $meta['hint'] ?? '',
        'example'  => $meta['example'] ?? '',
        'width'    => (int)($meta['width'] ?? 20),
        'required' => !empty($meta['required']),
    ];
}

$samples = [];
foreach (ImportTemplate::samples() as $type => $row) {
    $row['type'] = $type;
    $samples[] = $row;
}

$type_catalog = [];
foreach (Question::supported_types() as $code => $meta) {
    $type_catalog[] = [
        'code'        => $code,
        'label'       => $meta['label'],
        'description' => $meta['description'],
    ];
}

$data = [
    'version'      => ImportTemplate::VERSION,
    'columns'      => $columns,
    'samples'      => $samples,
    'type_catalog' => $type_catalog,
];

$target = dirname(__DIR__) . '/tools/import-samples.json';
$json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";

if (file_put_contents($target, $json) === false) {
    fwrite(STDERR, "Cannot write {$target}\n");
    exit(1);
}

printf(
    "Schema dumped: %d columns, %d sample rows, %d types → %s\n",
    count($columns),
    count($samples),
    count($type_catalog),
    $target
);
