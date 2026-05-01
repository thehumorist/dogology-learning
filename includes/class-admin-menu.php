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
            56
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

        // Modules + Lessons: hidden from menu but reachable by direct URL as a fallback
        // during the builder deprecation window. Remove these entirely in the next release.
        add_submenu_page(
            null,
            'Modules',
            'Modules',
            'manage_options',
            'dogology-learning-modules',
            array($this, 'render_modules_page')
        );
        add_submenu_page(
            null,
            'Lessons',
            'Lessons',
            'manage_options',
            'dogology-learning-lessons',
            array($this, 'render_lessons_page')
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

    public function render_modules_page()
    {
        require_once DOGOLOGY_LEARNING_PATH . 'admin/views/modules.php';
    }

    public function render_lessons_page()
    {
        require_once DOGOLOGY_LEARNING_PATH . 'admin/views/lessons.php';
    }

    public function render_settings_page()
    {
        // Simple Settings View inline or include
        require_once DOGOLOGY_LEARNING_PATH . 'admin/views/settings.php';
    }
}
