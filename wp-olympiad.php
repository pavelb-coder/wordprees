<?php
/**
 * Plugin Name: WP Olympiad & Testing Master
 * Plugin URI: https://github.com/wp-olympiad/wp-olympiad
 * Description: Современный высокопроизводительный плагин для проведения олимпиад, экзаменов, контрольных работ и тренировочных тестов на WordPress с защитой от списывания, серверным таймером, рейтинговой таблицей и экспортом протоколов.
 * Version: 1.0.14
 * Author: WP Olympiad Team
 * Author URI: https://arena.ai
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-olympiad
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 8.0
 */

declare(strict_types=1);

namespace WPOlympiad;

if (!defined('ABSPATH')) {
    exit;
}

define('WP_OLYMPIAD_VERSION', '1.0.14');
define('WP_OLYMPIAD_FILE', __FILE__);
define('WP_OLYMPIAD_PATH', plugin_dir_path(__FILE__));
define('WP_OLYMPIAD_URL', plugin_dir_url(__FILE__));

/**
 * Autoloader for WPOlympiad namespace
 */
spl_autoload_register(function (string $class): void {
    $prefix = 'WPOlympiad\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $relative_class = substr($class, strlen($prefix));

    static $class_map = [
        'DB'           => 'includes/class-db.php',
        'Quiz'         => 'includes/class-quiz.php',
        'Question'     => 'includes/class-question.php',
        'Attempt'       => 'includes/class-attempt.php',
        'Grader'        => 'includes/class-grader.php',
        'AntiCheat'     => 'includes/class-anti-cheat.php',
        'Export'        => 'includes/class-export.php',
        'NetworkExport' => 'includes/class-network-export.php',
        'QuestionExportCleanup' => 'includes/class-question-export-cleanup.php',
        'ReportExportCleanup' => 'includes/class-report-export-cleanup.php',
        'StyleThemes' => 'includes/class-style-themes.php',
        'NetworkImport' => 'includes/class-network-import.php',
        'NetworkSync' => 'includes/class-network-sync.php',
        'ImportTemplate' => 'includes/class-import-template.php',
        'DesktopEditor' => 'includes/class-desktop-editor.php',
        'Mailer'        => 'includes/class-mailer.php',
        'RestApi'      => 'includes/class-rest-api.php',
        'Api'          => 'includes/class-api.php',
        'Webhook'      => 'includes/class-webhook.php',
        'Ajax'         => 'includes/class-ajax.php',
        'Shortcodes'   => 'includes/class-shortcodes.php',
        'Admin\\Admin' => 'admin/class-admin.php',
    ];

    if (isset($class_map[$relative_class])) {
        $file = WP_OLYMPIAD_PATH . $class_map[$relative_class];
    } else {
        $slug = strtolower((string)preg_replace('/(?<!^)[A-Z]/', '-$0', str_replace('\\', '/', $relative_class)));
        $file = WP_OLYMPIAD_PATH . 'includes/class-' . $slug . '.php';
    }

    if (file_exists($file)) {
        require_once $file;
    }
});

/**
 * Main Plugin Class
 */
final class Plugin
{
    private static ?Plugin $instance = null;

    public static function instance(): Plugin
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->init_hooks();
    }

    private function init_hooks(): void
    {
        // I18n
        add_action('init', [$this, 'load_textdomain']);

        // Database & Upgrade
        add_action('plugins_loaded', [$this, 'maybe_upgrade_db']);

        // Components
        DB::init();
        Shortcodes::init();
        RestApi::init();
        Webhook::init();
        Ajax::init();
        NetworkImport::init();
        DesktopEditor::init();
        StyleThemes::init();

        if (is_admin()) {
            Admin\Admin::init();
        }
    }

    public function load_textdomain(): void
    {
        load_plugin_textdomain('wp-olympiad', false, dirname(plugin_basename(WP_OLYMPIAD_FILE)) . '/languages');
    }

    public function maybe_upgrade_db(): void
    {
        $current_version = get_option('wp_olympiad_db_version', '0.0.0');
        if (version_compare((string)$current_version, WP_OLYMPIAD_VERSION, '<')) {
            DB::install();
            update_option('wp_olympiad_db_version', WP_OLYMPIAD_VERSION);
        }
    }

    public static function activate(): void
    {
        DB::install();
        update_option('wp_olympiad_db_version', WP_OLYMPIAD_VERSION);
        NetworkImport::schedule_cron();
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        NetworkImport::clear_cron();
        wp_unschedule_hook(DB::THRESHOLD_CLEANUP_HOOK);
        flush_rewrite_rules();
    }
}

register_activation_hook(WP_OLYMPIAD_FILE, ['WPOlympiad\\Plugin', 'activate']);
register_deactivation_hook(WP_OLYMPIAD_FILE, ['WPOlympiad\\Plugin', 'deactivate']);

// Boot plugin
add_action('plugins_loaded', ['WPOlympiad\\Plugin', 'instance'], 5);
