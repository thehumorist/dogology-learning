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
        // VISIBLE from 2026-08-04. This is the plugin's PRIMARY editing UI —
        // it replaced the old Modules/Lessons pages — yet it was registered
        // with parent=null, so it had no sidebar entry at all and was
        // reachable only by someone who already knew the URL. The screen you
        // use most should not be the one you cannot find.
        // Capture the hook suffix rather than reconstructing it. WordPress
        // derives it from the PARENT: with parent=null it was
        // 'admin_page_<slug>'; under a real parent it becomes
        // '<parent>_page_<slug>'. A hardcoded guess silently breaks the moment
        // the parent changes — which is exactly what happened when this page
        // was given a menu entry: the assets guard still matched the old hook,
        // so CSS, JS and the localized nonce all stopped loading on the one
        // screen they exist for. Storing what add_submenu_page() actually
        // returns cannot drift.
        $this->hook_suffix = add_submenu_page(
            'dogology-learning',
            'Course Builder',
            'Course Builder',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    /** Hook suffix returned by add_submenu_page(); see register_page(). */
    private $hook_suffix = '';

    public function enqueue_assets($hook)
    {
        // empty() not === '': add_submenu_page() returns FALSE when the current
        // user lacks the capability, and false !== '' would slip past a
        // stricter check.
        if (empty($this->hook_suffix) || $hook !== $this->hook_suffix) {
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

        // Arriving with no course_id is the NORMAL case now that this page has
        // a sidebar entry — the menu link cannot know which course you want.
        // Show a picker rather than "Course not found", which made the new menu
        // item look permanently broken.
        if ($course_id === 0) {
            $courses = get_posts([
                'post_type'      => 'dogology_course',
                'post_status'    => ['publish', 'draft', 'private'],
                'posts_per_page' => 100,
                'orderby'        => 'title',
                'order'          => 'ASC',
            ]);
            echo '<div class="wrap"><h1>' . esc_html__('Course Builder', 'dogology-learning') . '</h1>';
            if (empty($courses)) {
                echo '<p>' . esc_html__('No courses yet.', 'dogology-learning') . ' <a href="'
                    . esc_url(admin_url('admin.php?page=dogology-learning-courses')) . '">'
                    . esc_html__('Create one under Courses', 'dogology-learning') . '</a>.</p>';
            } else {
                echo '<p>' . esc_html__('Choose a course to build:', 'dogology-learning') . '</p>';
                echo '<table class="widefat striped" style="max-width:680px"><tbody>';
                foreach ($courses as $c) {
                    $url = admin_url('admin.php?page=' . self::PAGE_SLUG . '&course_id=' . (int) $c->ID);
                    echo '<tr><td>' . esc_html(get_the_title($c) ?: __('(untitled)', 'dogology-learning')) . '</td>';
                    if ($c->post_status !== 'publish') {
                        echo '<td><em>' . esc_html($c->post_status) . '</em></td>';
                    } else {
                        echo '<td></td>';
                    }
                    echo '<td style="text-align:right"><a class="button button-primary" href="' . esc_url($url) . '">'
                        . esc_html__('Build', 'dogology-learning') . '</a></td></tr>';
                }
                echo '</tbody></table>';
            }
            echo '</div>';
            return;
        }

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
