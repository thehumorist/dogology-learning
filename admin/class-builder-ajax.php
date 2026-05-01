<?php
/**
 * Course Builder AJAX endpoints.
 *
 * All endpoints:
 *   - require a valid `dl_builder` nonce under $_POST['nonce']
 *   - require `manage_options` capability
 *   - return JSON via wp_send_json_success / wp_send_json_error
 *
 * Registered actions (both wp_ajax_ variants go here; admin-only):
 *   dl_builder_course_update
 *   dl_builder_module_create | _update | _delete
 *   dl_builder_lesson_create | _update | _delete
 */

if (!defined('ABSPATH')) {
    exit;
}

class Dogology_Learning_Builder_Ajax
{
    const NONCE_ACTION = 'dl_builder';
    const CAP = 'manage_options';

    public function init()
    {
        $actions = [
            'dl_builder_course_update',
            'dl_builder_module_create',
            'dl_builder_module_update',
            'dl_builder_module_delete',
            'dl_builder_lesson_get',
            'dl_builder_lesson_create',
            'dl_builder_lesson_update',
            'dl_builder_lesson_delete',
            'dl_builder_reorder',
        ];
        foreach ($actions as $a) {
            add_action('wp_ajax_' . $a, [$this, $a]);
        }
    }

    private function guard()
    {
        if (!current_user_can(self::CAP)) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : '';
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_send_json_error(['message' => 'Bad nonce'], 400);
        }
    }

    /* ---------- Course ---------- */

    public function dl_builder_course_update()
    {
        $this->guard();
        $id = isset($_POST['course_id']) ? intval($_POST['course_id']) : 0;
        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';

        $post = $id ? get_post($id) : null;
        if (!$post || $post->post_type !== 'dogology_course') {
            wp_send_json_error(['message' => 'Course not found'], 404);
        }
        if ($title === '') {
            wp_send_json_error(['message' => 'Title required'], 400);
        }

        wp_update_post(['ID' => $id, 'post_title' => $title]);
        wp_send_json_success(['id' => $id, 'title' => $title]);
    }

    /* ---------- Module ---------- */

    public function dl_builder_module_create()
    {
        $this->guard();
        $course_id = isset($_POST['course_id']) ? intval($_POST['course_id']) : 0;
        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';

        $course = $course_id ? get_post($course_id) : null;
        if (!$course || $course->post_type !== 'dogology_course') {
            wp_send_json_error(['message' => 'Course not found'], 404);
        }
        if ($title === '') {
            wp_send_json_error(['message' => 'Title required'], 400);
        }

        $next_order = $this->next_menu_order('dogology_module', '_dogology_parent_course', $course_id);

        $pid = wp_insert_post([
            'post_type'   => 'dogology_module',
            'post_status' => 'publish',
            'post_title'  => $title,
            'menu_order'  => $next_order,
        ], true);

        if (is_wp_error($pid)) {
            wp_send_json_error(['message' => $pid->get_error_message()], 500);
        }
        update_post_meta($pid, '_dogology_parent_course', $course_id);

        wp_send_json_success([
            'id'         => $pid,
            'title'      => $title,
            'menu_order' => $next_order,
        ]);
    }

    public function dl_builder_module_update()
    {
        $this->guard();
        $id = isset($_POST['module_id']) ? intval($_POST['module_id']) : 0;
        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';

        $post = $id ? get_post($id) : null;
        if (!$post || $post->post_type !== 'dogology_module') {
            wp_send_json_error(['message' => 'Module not found'], 404);
        }
        if ($title === '') {
            wp_send_json_error(['message' => 'Title required'], 400);
        }

        wp_update_post(['ID' => $id, 'post_title' => $title]);
        wp_send_json_success(['id' => $id, 'title' => $title]);
    }

    public function dl_builder_module_delete()
    {
        $this->guard();
        $id = isset($_POST['module_id']) ? intval($_POST['module_id']) : 0;

        $post = $id ? get_post($id) : null;
        if (!$post || $post->post_type !== 'dogology_module') {
            wp_send_json_error(['message' => 'Module not found'], 404);
        }

        // Cascade: force-delete lessons inside this module.
        // Order: post first, then progress. If wp_delete_post is vetoed by a hook,
        // we skip the progress cleanup for that lesson so state doesn't diverge.
        $lessons = get_posts([
            'post_type'   => 'dogology_lesson',
            'numberposts' => -1,
            'post_status' => 'any',
            'meta_key'    => '_dogology_parent_module',
            'meta_value'  => $id,
            'fields'      => 'ids',
        ]);
        $deleted_lesson_ids = [];
        if ($lessons) {
            foreach ($lessons as $lid) {
                if (wp_delete_post($lid, true)) {
                    $deleted_lesson_ids[] = (int) $lid;
                }
            }
            if (!empty($deleted_lesson_ids)) {
                global $wpdb;
                $placeholders = implode(',', array_fill(0, count($deleted_lesson_ids), '%d'));
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}dogology_progress WHERE lesson_id IN ($placeholders)",
                    $deleted_lesson_ids
                ));
            }
        }
        wp_delete_post($id, true);

        wp_send_json_success(['id' => $id, 'lessons_deleted' => count($deleted_lesson_ids)]);
    }

    /* ---------- Lesson ---------- */

    public function dl_builder_lesson_get()
    {
        $this->guard();
        $id = isset($_POST['lesson_id']) ? intval($_POST['lesson_id']) : 0;

        $post = $id ? get_post($id) : null;
        if (!$post || $post->post_type !== 'dogology_lesson') {
            wp_send_json_error(['message' => 'Lesson not found'], 404);
        }

        wp_send_json_success([
            'id'                  => $id,
            'title'               => $post->post_title,
            'description'         => $post->post_content,
            'subtitle'            => get_post_meta($id, '_dogology_subtitle', true),
            'video_url'           => get_post_meta($id, '_dogology_video_url', true),
            'duration'            => get_post_meta($id, '_dogology_duration', true),
            'attachment_url'      => get_post_meta($id, '_dogology_attachment_url', true),
            'attachment_title'    => get_post_meta($id, '_dogology_attachment_title', true),
            'attachment_subtitle' => get_post_meta($id, '_dogology_attachment_subtitle', true),
            'attachment_cta'      => get_post_meta($id, '_dogology_attachment_cta', true),
        ]);
    }

    public function dl_builder_lesson_create()
    {
        $this->guard();
        $module_id = isset($_POST['module_id']) ? intval($_POST['module_id']) : 0;
        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';

        $module = $module_id ? get_post($module_id) : null;
        if (!$module || $module->post_type !== 'dogology_module') {
            wp_send_json_error(['message' => 'Module not found'], 404);
        }
        if ($title === '') {
            wp_send_json_error(['message' => 'Title required'], 400);
        }

        $course_id = (int) get_post_meta($module_id, '_dogology_parent_course', true);
        $next_order = $this->next_menu_order('dogology_lesson', '_dogology_parent_module', $module_id);

        $pid = wp_insert_post([
            'post_type'   => 'dogology_lesson',
            'post_status' => 'publish',
            'post_title'  => $title,
            'menu_order'  => $next_order,
        ], true);

        if (is_wp_error($pid)) {
            wp_send_json_error(['message' => $pid->get_error_message()], 500);
        }
        update_post_meta($pid, '_dogology_parent_module', $module_id);
        if ($course_id) {
            update_post_meta($pid, '_dogology_parent_course', $course_id);
        }

        wp_send_json_success([
            'id'         => $pid,
            'title'      => $title,
            'menu_order' => $next_order,
        ]);
    }

    /**
     * Update a lesson with full field parity to the legacy lessons.php save block.
     * Accepts: title, description (post_content, filtered HTML), subtitle, video_url,
     * duration, attachment_url, attachment_title, attachment_subtitle, attachment_cta.
     *
     * Omitted fields are left untouched so partial saves are safe.
     */
    public function dl_builder_lesson_update()
    {
        $this->guard();
        $id = isset($_POST['lesson_id']) ? intval($_POST['lesson_id']) : 0;

        $post = $id ? get_post($id) : null;
        if (!$post || $post->post_type !== 'dogology_lesson') {
            wp_send_json_error(['message' => 'Lesson not found'], 404);
        }

        $update = ['ID' => $id];
        if (array_key_exists('title', $_POST)) {
            $title = sanitize_text_field(wp_unslash($_POST['title']));
            if ($title === '') {
                wp_send_json_error(['message' => 'Title required'], 400);
            }
            $update['post_title'] = $title;
        }
        if (array_key_exists('description', $_POST)) {
            $update['post_content'] = wp_kses_post(wp_unslash($_POST['description']));
        }
        if (count($update) > 1) {
            wp_update_post($update);
        }

        $meta_map = [
            'subtitle'            => ['_dogology_subtitle',           'sanitize_text_field'],
            'video_url'           => ['_dogology_video_url',          'sanitize_url'],
            'duration'            => ['_dogology_duration',           'sanitize_text_field'],
            'attachment_url'      => ['_dogology_attachment_url',     'sanitize_url'],
            'attachment_title'    => ['_dogology_attachment_title',   'sanitize_text_field'],
            'attachment_subtitle' => ['_dogology_attachment_subtitle','sanitize_text_field'],
            'attachment_cta'      => ['_dogology_attachment_cta',     'sanitize_text_field'],
        ];
        foreach ($meta_map as $key => [$meta_key, $sanitizer]) {
            if (array_key_exists($key, $_POST)) {
                update_post_meta($id, $meta_key, call_user_func($sanitizer, wp_unslash($_POST[$key])));
            }
        }

        wp_send_json_success([
            'id'       => $id,
            'title'    => get_the_title($id),
            'duration' => get_post_meta($id, '_dogology_duration', true),
        ]);
    }

    public function dl_builder_lesson_delete()
    {
        $this->guard();
        $id = isset($_POST['lesson_id']) ? intval($_POST['lesson_id']) : 0;

        $post = $id ? get_post($id) : null;
        if (!$post || $post->post_type !== 'dogology_lesson') {
            wp_send_json_error(['message' => 'Lesson not found'], 404);
        }

        // Order: post first, then progress. If wp_delete_post is vetoed by a hook,
        // leave progress rows alone so state stays consistent.
        if (!wp_delete_post($id, true)) {
            wp_send_json_error(['message' => 'Lesson could not be deleted'], 500);
        }
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'dogology_progress', ['lesson_id' => $id]);
        wp_send_json_success(['id' => $id]);
    }

    /* ---------- Reorder (modules + lessons, including cross-module moves) ---------- */

    /**
     * Inputs:
     *   entity: 'module' | 'lesson'
     *   parent_id: course_id for modules, module_id for lessons
     *   order[]: ordered list of child IDs after the drag
     *
     * Effect:
     *   - Bulk UPDATE menu_order via CASE WHEN, one SQL round-trip.
     *   - For lessons: also rewrite _dogology_parent_module = parent_id (handles
     *     cross-module drops) and _dogology_parent_course = course of the new module
     *     (stays the same within a course, but kept in sync defensively).
     */
    public function dl_builder_reorder()
    {
        $this->guard();
        global $wpdb;

        $entity = isset($_POST['entity']) ? sanitize_key($_POST['entity']) : '';
        $parent_id = isset($_POST['parent_id']) ? intval($_POST['parent_id']) : 0;
        $order_raw = isset($_POST['order']) ? (array) $_POST['order'] : [];
        $order = array_values(array_filter(array_map('intval', $order_raw)));

        if (!in_array($entity, ['module', 'lesson'], true) || $parent_id <= 0 || empty($order)) {
            wp_send_json_error(['message' => 'Bad request'], 400);
        }

        $post_type = $entity === 'module' ? 'dogology_module' : 'dogology_lesson';

        // Validate: every ID must be a published post of the correct type.
        $placeholders = implode(',', array_fill(0, count($order), '%d'));
        $sql = "SELECT ID FROM {$wpdb->posts}
                WHERE ID IN ($placeholders)
                  AND post_type = %s
                  AND post_status = 'publish'";
        $args = array_merge($order, [$post_type]);
        $found = array_map('intval', $wpdb->get_col($wpdb->prepare($sql, $args)));
        if (count($found) !== count($order)) {
            wp_send_json_error(['message' => 'Some items invalid'], 400);
        }

        // Ownership check: every reordered item must currently belong to the target course.
        // For modules: parent_id IS the course. Every module's _dogology_parent_course
        // must equal parent_id. Rejects cross-course drags entirely.
        // For lessons: parent_id is the receiving module; we derive its course and check
        // each lesson currently lives in a module that belongs to the same course.
        // Cross-module moves WITHIN a course stay allowed (log.md: supported behaviour).
        // Cross-course moves are blocked.
        if ($entity === 'module') {
            foreach ($order as $module_id) {
                $current_course = (int) get_post_meta($module_id, '_dogology_parent_course', true);
                if ($current_course !== $parent_id) {
                    wp_send_json_error(['message' => 'Module does not belong to target course', 'module_id' => $module_id], 403);
                }
            }
        } else {
            $target_course = (int) get_post_meta($parent_id, '_dogology_parent_course', true);
            if ($target_course <= 0) {
                wp_send_json_error(['message' => 'Target module has no course'], 400);
            }
            foreach ($order as $lesson_id) {
                $current_module = (int) get_post_meta($lesson_id, '_dogology_parent_module', true);
                if ($current_module <= 0) {
                    wp_send_json_error(['message' => 'Lesson has no current module', 'lesson_id' => $lesson_id], 400);
                }
                $lesson_course = (int) get_post_meta($current_module, '_dogology_parent_course', true);
                if ($lesson_course !== $target_course) {
                    wp_send_json_error(['message' => 'Lesson does not belong to target course', 'lesson_id' => $lesson_id], 403);
                }
            }
        }

        // Build the CASE WHEN for menu_order. Collect placeholders and args so
        // the entire statement is bound through a single $wpdb->prepare() call
        // (nesting prepare() is unsafe: inner placeholder output can collide
        // with the outer parser and argument counts get misaligned).
        $cases = '';
        $case_args = [];
        foreach ($order as $i => $id) {
            $cases .= 'WHEN %d THEN %d ';
            $case_args[] = (int) $id;
            $case_args[] = (int) $i;
        }
        $in_placeholders = implode(',', array_fill(0, count($order), '%d'));
        $update_sql = "UPDATE {$wpdb->posts}
                       SET menu_order = CASE ID $cases END
                       WHERE ID IN ($in_placeholders)
                         AND post_type = %s";
        $update_args = array_merge($case_args, array_map('intval', $order), [$post_type]);
        $wpdb->query($wpdb->prepare($update_sql, $update_args));

        // For lessons, also re-parent to the receiving module (idempotent on same-module reorder).
        if ($entity === 'lesson') {
            $module = get_post($parent_id);
            if (!$module || $module->post_type !== 'dogology_module') {
                wp_send_json_error(['message' => 'Parent module invalid'], 400);
            }
            $course_id = (int) get_post_meta($parent_id, '_dogology_parent_course', true);
            foreach ($order as $lesson_id) {
                update_post_meta($lesson_id, '_dogology_parent_module', $parent_id);
                if ($course_id) {
                    update_post_meta($lesson_id, '_dogology_parent_course', $course_id);
                }
            }
        }

        wp_send_json_success(['entity' => $entity, 'parent_id' => $parent_id, 'count' => count($order)]);
    }

    /* ---------- Helpers ---------- */

    /**
     * Next menu_order value for a new child under a given parent meta.
     */
    private function next_menu_order($post_type, $parent_meta_key, $parent_id)
    {
        global $wpdb;
        $val = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(p.menu_order)
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE p.post_type = %s
               AND p.post_status = 'publish'
               AND pm.meta_key = %s
               AND pm.meta_value = %d",
            $post_type,
            $parent_meta_key,
            $parent_id
        ));
        return $val === null ? 0 : ((int) $val) + 1;
    }
}
