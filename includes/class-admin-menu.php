<?php

if (!defined('ABSPATH')) {
    exit;
}

class Dogology_Learning_Admin_Menu
{

    public function __construct()
    {
        add_action('admin_menu', array($this, 'register_menu'));
    }

    public function register_menu()
    {
        // Main Menu (Learning)
        add_menu_page(
            'Dogology Learning',
            'Dogology Learning',
            'manage_options',
            'dogology-learning',
            array($this, 'render_dashboard'), // Placeholder for now
            'dashicons-welcome-learn-more',
            // 31 — Dogology block (30-35). Was 56, which dropped Learning far
            // down the sidebar away from the rest of the family.
            31
        );

        // Submenu: Students
        add_submenu_page(
            'dogology-learning',
            'Students',
            'Students',
            'manage_options',
            'dogology-learning-students',
            array($this, 'render_students_page')
        );

        // Submenu: Courses
        add_submenu_page(
            'dogology-learning',
            'Courses',
            'Courses',
            'manage_options',
            'dogology-learning-courses',
            array($this, 'render_courses_page')
        );

        // Submenu: Logins (browser / in-app webview diagnostics)
        add_submenu_page(
            'dogology-learning',
            'Logins',
            'Logins',
            'manage_options',
            'dogology-learning-logins',
            array($this, 'render_logins_page')
        );

        // Modules + Lessons pages REMOVED 2026-08-04. They were the pre-Course-
        // Builder editors, already hidden (parent=null) and kept URL-reachable
        // only "during the builder deprecation window… remove entirely in the
        // next release". That window is long closed: Course Builder replaced
        // them, and the only links to those URLs were inside their own views.
        // Their render methods and admin/views/{modules,lessons}.php went with
        // them — 616 lines of editor that could still write to live courses.

        // Submenu: Completion Survey
        add_submenu_page(
            'dogology-learning',
            'Completion Survey',
            'Survey',
            'manage_options',
            'dogology-learning-survey',
            array($this, 'render_survey_page')
        );

        // Submenu: Settings
        add_submenu_page(
            'dogology-learning',
            'Settings',
            'Settings',
            'manage_options',
            'dogology-learning-settings',
            array($this, 'render_settings_page')
        );
    }

    public function render_survey_page()
    {
        require_once DOGOLOGY_LEARNING_PATH . 'admin/views/survey.php';
    }

    public function render_dashboard()
    {
        require_once DOGOLOGY_LEARNING_PATH . 'admin/views/dashboard.php';
    }

    public function render_students_page()
    {
        require_once DOGOLOGY_LEARNING_PATH . 'admin/views/students.php';
    }

    public function render_courses_page()
    {
        require_once DOGOLOGY_LEARNING_PATH . 'admin/views/courses.php';
    }

    public function render_settings_page()
    {
        // Simple Settings View inline or include
        require_once DOGOLOGY_LEARNING_PATH . 'admin/views/settings.php';
    }

    public function render_logins_page()
    {
        require_once DOGOLOGY_LEARNING_PATH . 'admin/views/logins.php';
    }
}
