<?php
/**
 * Fired when the plugin is deleted.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'wp-olympiad.php';

// Only drop tables if option is set or default cleanup
$settings = get_option('wp_olympiad_settings', []);
$delete_data = $settings['delete_data_on_uninstall'] ?? false;

if ($delete_data) {
    \WPOlympiad\DB::drop_tables();
}
