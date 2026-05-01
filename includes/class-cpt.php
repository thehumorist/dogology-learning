<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Custom Post Types Registration
 */
class Dogology_Learning_CPT
{

    public function init()
    {
        add_action('init', array($this, 'register_course_cpt'));
        add_action('init', array($this, 'register_module_cpt'));
        add_action('init', array($this, 'register_lesson_cpt'));
    }

    public function register_course_cpt()
    {
        $labels = array(
            'name' => 'Courses',
            'singular_name' => 'Course',
            'menu_name' => 'Dogology Learning',
            'add_new' => 'Add New Course',
            'add_new_item' => 'Add New Course',
            'edit_item' => 'Edit Course',
            'new_item' => 'New Course',
            'view_item' => 'View Course',
            'search_items' => 'Search Courses',
            'not_found' => 'No courses found',
            'not_found_in_trash' => 'No courses found in Trash',
        );

        $args = array(
            'labels' => $labels,
            'public' => true,
            'has_archive' => true, // /courses/ archive
            'menu_icon' => 'dashicons-welcome-learn-more',
            'supports' => array('title', 'thumbnail', 'editor'), // Internal support needed
            'show_ui' => false, // Hide default UI, we use custom pages
            'show_in_menu' => false,
            'show_in_rest' => false, // Disable Gutenberg/Page Builders
            'rewrite' => array('slug' => 'course'),
            'capability_type' => 'post',
        );

        register_post_type('dogology_course', $args);
    }

    public function register_module_cpt()
    {
        $labels = array(
            'name' => 'Modules',
            'singular_name' => 'Module',
            'menu_name' => 'Modules',
            'add_new' => 'Add New Module',
            'add_new_item' => 'Add New Module',
            'edit_item' => 'Edit Module',
            'new_item' => 'New Module',
            'view_item' => 'View Module',
            'search_items' => 'Search Modules',
            'not_found' => 'No modules found',
            'not_found_in_trash' => 'No modules found in Trash',
        );

        // Modules are never rendered as standalone URLs — they're structural containers
        // inside a course, reached only through /learn/{course}/{lesson}. Keeping public=true
        // would expose /?p=MODULE_ID with raw module content bypassing enrollment gating.
        $args = array(
            'labels' => $labels,
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => false,
            'show_in_menu' => false,
            'supports' => array('title', 'editor', 'page-attributes'), // Added page-attributes for menu_order
            'show_in_rest' => false,
            'rewrite' => false,
            'capability_type' => 'post',
        );

        register_post_type('dogology_module', $args);
    }

    public function register_lesson_cpt()
    {
        $labels = array(
            'name' => 'Lessons',
            'singular_name' => 'Lesson',
            'menu_name' => 'Lessons',
            'add_new' => 'Add New Lesson',
            'add_new_item' => 'Add New Lesson',
            'edit_item' => 'Edit Lesson',
            'new_item' => 'New Lesson',
            'view_item' => 'View Lesson',
            'search_items' => 'Search Lessons',
            'not_found' => 'No lessons found',
            'not_found_in_trash' => 'No lessons found in Trash',
        );

        // Lessons are enrollment-gated — they should ONLY be reachable via the player
        // template at /learn/{course}/{lesson}, which enforces enrollment. Leaving
        // public=true exposed /?p=LESSON_ID as a back door that served lesson content
        // to anyone with the ID.
        $args = array(
            'labels' => $labels,
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => false,
            'show_in_menu' => false,
            'supports' => array('title', 'page-attributes'), // Added page-attributes for menu_order
            'show_in_rest' => false, // Disable Gutenberg/Page Builders
            'rewrite' => false,
            'capability_type' => 'post',
        );

        register_post_type('dogology_lesson', $args);
    }
}
