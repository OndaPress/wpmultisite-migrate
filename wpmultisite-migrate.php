<?php
/**
 * Plugin Name: OndaPress Multisite Migration
 * Plugin URI: https://github.com/bredebs/wpmultisite-migrate
 * Description: A WordPress plugin to migrate from unisite to multisite
 * Version: 1.0.0
 * Author: Bredebs
 * Author URI: https://github.com/bredebs
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wpmultisite-migrate
 * Domain Path: /languages
 * Network: true
 * Requires at least: 5.0
 * Requires PHP: 7.2
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('OPMSM_VERSION', '1.0.0');
define('OPMSM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('OPMSM_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include required files
require_once plugin_dir_path(__FILE__) . 'includes/class-opmsm-db.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-opmsm-admin.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-opmsm-cli.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-opmsm-processor.php';

// Autoloader for plugin classes
spl_autoload_register(function ($class) {
    // Project-specific namespace prefix
    $prefix = 'OPMSM_';
    $base_dir = OPMSM_PLUGIN_DIR . 'includes/';

    // Check if the class uses the namespace prefix
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    // Get the relative class name
    $relative_class = substr($class, $len);

    // Replace namespace separators with directory separators
    $file = $base_dir . 'class-opmsm-' . strtolower(str_replace('_', '-', $relative_class)) . '.php';
    // If the file exists, require it
    if (file_exists($file)) {
        require $file;
    }
});

// Initialize the plugin
function opmsm_init() {
    // Load text domain
    load_plugin_textdomain('wpmultisite-migrate', false, dirname(plugin_basename(__FILE__)) . '/languages');

    // Initialize admin
    if (is_admin()) {
        new OPMSM_Admin();
    }

    // Initialize CLI
    if (defined('WP_CLI') && WP_CLI) {
        new OPMSM_CLI();
    }
}
add_action('plugins_loaded', 'opmsm_init');

// Activation hook
register_activation_hook(__FILE__, 'opmsm_activate');
function opmsm_activate() {
    // Create database tables
    $db = OPMSM_DB::get_instance();
    $db->create_tables();
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'opmsm_deactivate');
function opmsm_deactivate() {
    // Cleanup if needed
} 