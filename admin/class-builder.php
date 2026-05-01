<?php
/**
 * Unified Course Builder — admin page that shows one course as a nested
 * tree of modules and lessons with inline add/edit/reorder.
 *
 * Phase 1a: server-side scaffolding + tree render. Client interactivity
 * (AJAX create/update/delete, drag-and-drop, lesson drawer) arrives in
 * subsequent phases.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Dogology_Learning_Builder
{
    const PAGE_SLUG = 'dogology-learning-builder';

    public function init()
    {
        add_action('admin_menu', [$this, 'register_page'], 20);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function register_page()
    {
        // Hidden submenu: reachable by URL only, not shown in the sidebar.
        // Rendered via parent=null so WP doesn't auto-add it to any menu.
        add_submenu_page(
            null,
            'Course Builder',
            'Course Builder',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    public function enqueue_assets($hook)
    {
        if ($hook !== 'admin_page_' . self::PAGE_SLUG) {
            return;
        }
        // Preload TinyMCE + QuickTags bundles so wp.editor.initialize() works in the lesson drawer.
        wp_enqueue_editor();

        wp_enqueue_style(
            'dl-builder',
            DOGOLOGY_LEARNING_URL . 'admin/css/builder.css',
            [],
            DOGOLOGY_LEARNING_VERSION
        );
        wp_enqueue_script(
            'dl-builder',
            DOGOLOGY_LEARNING_URL . 'admin/js/builder.js',
            ['jquery', 'jquery-ui-sortable', 'editor'],
            DOGOLOGY_LEARNING_VERSION,
            true
        );
        wp_localize_script('dl-builder', 'DL_BUILDER', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('dl_builder'),
        ]);
    }

    public function render()
    {
        $course_id = isset($_GET['course_id']) ? intval($_GET['course_id']) : 0;
        $course = $course_id ? get_post($course_id) : null;

        if (!$course
            || $course->post_type !== 'dogology_course'
            || $course->post_status === 'trash'
        ) {
            echo '<div class="wrap"><h1>Course Builder</h1>';
            echo '<p>Course not found. <a href="' . esc_url(admin_url('admin.php?page=dogology-learning-courses')) . '">Return to Courses</a>.</p>';
            echo '</div>';
            return;
        }

        $tree = self::build_tree($course_id);
        $linked_cohorts = self::linked_cohorts($course_id);
        include DOGOLOGY_LEARNING_PATH . 'admin/views/builder.php';
    }

    /**
     * Fetch the full course tree in two queries (plus postmeta batching by WP core).
     *
     * Returns an array of modules, each with a `lessons` key populated from a
     * single grouped lookup. Lesson postmeta is primed by WP's own postmeta
     * cache when we touch `get_post_meta()` on each lesson downstream.
     */
    public static function build_tree($course_id)
    {
        $modules = get_posts([
            'post_type'   => 'dogology_module',
            'numberposts' => -1,
            'orderby'     => 'menu_order',
            'order'       => 'ASC',
            'meta_key'    => '_dogology_parent_course',
            'meta_value'  => $course_id,
            'post_status' => 'publish',
        ]);

        if (!$modules) {
            return [];
        }

        $module_ids = wp_list_pluck($modules, 'ID');

        $lessons = get_posts([
            'post_type'   => 'dogology_lesson',
            'numberposts' => -1,
            'orderby'     => 'menu_order',
            'order'       => 'ASC',
            'post_status' => 'publish',
            'meta_query'  => [[
                'key'     => '_dogology_parent_module',
                'value'   => $module_ids,
                'compare' => 'IN',
            ]],
        ]);

        // Prime postmeta cache in one call so per-lesson meta reads are hot.
        if ($lessons) {
            update_postmeta_cache(wp_list_pluck($lessons, 'ID'));
        }

        $lessons_by_module = [];
        foreach ($lessons as $lesson) {
            $mid = (int) get_post_meta($lesson->ID, '_dogology_parent_module', true);
            $lessons_by_module[$mid][] = $lesson;
        }

        $tree = [];
        foreach ($modules as $module) {
            $mid = $module->ID;
            $tree[] = [
                'module'  => $module,
                'lessons' => isset($lessons_by_module[$mid]) ? $lessons_by_module[$mid] : [],
            ];
        }
        return $tree;
    }

    /**
     * Return the cohorts that point at this course via linked_course_id.
     * Returns [] if the column doesn't exist yet (migration not run).
     */
    public static function linked_cohorts($course_id)
    {
        global $wpdb;
        $table_cohorts = $wpdb->prefix . 'dogology_cohorts';

        $has_col = (bool) $wpdb->get_var($wpdb->prepare(
            "SHOW COLUMNS FROM $table_cohorts LIKE %s",
            'linked_course_id'
        ));
        if (!$has_col) {
            return [];
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, name FROM $table_cohorts WHERE linked_course_id = %d ORDER BY name ASC",
            $course_id
        ));
    }

    /**
     * URL to the builder for a specific course.
     */
    public static function url($course_id)
    {
        return admin_url('admin.php?page=' . self::PAGE_SLUG . '&course_id=' . intval($course_id));
    }
}
