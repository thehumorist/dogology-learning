<?php
/**
 * Plugin Name: Dogology Learning
 * Plugin URI:  https://dogology.org
 * Description: The core learning platform for Dogology. Manages courses, students (custom auth), and progress tracking.
 * Version:     1.1.77
 * Author:      Dogology Dev
 * Text Domain: dogology-learning
 */

if (!defined('ABSPATH')) {
    exit;
}

define('DOGOLOGY_LEARNING_VERSION', '1.1.77');
define('DOGOLOGY_LEARNING_PATH', plugin_dir_path(__FILE__));
define('DOGOLOGY_LEARNING_URL', plugin_dir_url(__FILE__));

// Custom Auth Salt (Decoupled from WP)
if (!defined('DOGOLOGY_AUTH_SALT')) {
    $stored_salt = get_option('dogology_learning_auth_salt');
    if (empty($stored_salt)) {
        $stored_salt = bin2hex(random_bytes(32));
        update_option('dogology_learning_auth_salt', $stored_salt, false);
    }
    define('DOGOLOGY_AUTH_SALT', $stored_salt);
}

// Auto-Loader — Conditional loading to save memory
// Core classes — always needed (small, used by frontend + admin)
require_once DOGOLOGY_LEARNING_PATH . 'includes/class-student-db.php';
require_once DOGOLOGY_LEARNING_PATH . 'includes/class-cpt.php';
require_once DOGOLOGY_LEARNING_PATH . 'includes/class-assets.php';
require_once DOGOLOGY_LEARNING_PATH . 'includes/class-router.php';
require_once DOGOLOGY_LEARNING_PATH . 'includes/class-auth.php';
require_once DOGOLOGY_LEARNING_PATH . 'includes/class-helpers.php';
require_once DOGOLOGY_LEARNING_PATH . 'includes/class-data.php';
require_once DOGOLOGY_LEARNING_PATH . 'includes/class-integration-commerce.php';

// Admin-only classes — skip on frontend requests
if (is_admin()) {
    require_once DOGOLOGY_LEARNING_PATH . 'includes/class-db-installer.php';
    require_once DOGOLOGY_LEARNING_PATH . 'includes/class-admin-menu.php';
    require_once DOGOLOGY_LEARNING_PATH . 'admin/class-builder.php';
    require_once DOGOLOGY_LEARNING_PATH . 'admin/class-builder-ajax.php';
}

// CLI-only — registers the `wp dl-diag` command. Self-guards on WP_CLI so
// the file is harmless if accidentally required from a web request.
if (defined('WP_CLI') && WP_CLI) {
    require_once DOGOLOGY_LEARNING_PATH . 'includes/class-cli.php';
}

// Activation Hook
register_activation_hook(__FILE__, array('Dogology_Learning_DB_Installer', 'install'));
register_activation_hook(__FILE__, array('Dogology_Learning_Router', 'flush_rules')); // Helper if we add it to the class later

// Initialize
add_action('plugins_loaded', function () {
    // Admin-only: DB upgrades and admin menu
    if (is_admin()) {
        // Automated DB Upgrade Check.
        // Default '0.0.0' ensures every historic migration fires on installs that
        // somehow never wrote the option (e.g. plugin files dropped in place
        // without running the activation hook). install() stamps the current
        // version itself, so fresh installs short-circuit harmlessly.
        $current_db_version = get_option('dogology_learning_db_version', '0.0.0');
        if (version_compare($current_db_version, DOGOLOGY_LEARNING_VERSION, '<')) {
            Dogology_Learning_DB_Installer::upgrade($current_db_version);
            update_option('dogology_learning_db_version', DOGOLOGY_LEARNING_VERSION);
        }

        // Init Admin Menu (Page Builder)
        new Dogology_Learning_Admin_Menu();

        // Init Course Builder (hidden submenu page, reached from Courses list)
        (new Dogology_Learning_Builder())->init();
        (new Dogology_Learning_Builder_Ajax())->init();
    }

    // Init CPTs
    $cpt = new Dogology_Learning_CPT();
    $cpt->init();

    // Init Assets (Styles)
    $assets = new Dogology_Learning_Assets();
    add_action('wp_enqueue_scripts', array($assets, 'enqueue_scripts'));
    add_action('admin_enqueue_scripts', array($assets, 'enqueue_admin_scripts'));

    // Init Router (Frontend)
    $router = new Dogology_Learning_Router();
    $router->init();

    // Commerce Integration (Order Approval → Student Enrollment)
    $commerce_integration = new Dogology_Learning_Integration_Commerce();
    $commerce_integration->init();
});
