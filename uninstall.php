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
    global $wpdb;
    $editor_options = $wpdb->get_col($wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like(\WPOlympiad\DesktopEditor::STATE_PREFIX) . '%'
    ));
    foreach ($editor_options as $editor_option) { delete_option($editor_option); }
    $sync_options = $wpdb->get_col($wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like(\WPOlympiad\NetworkSync::PREFIX) . '%'
    ));
    foreach ($sync_options as $sync_option) { delete_option($sync_option); }
    // Never remove externally deployed editor files, questions or drafts.
}
