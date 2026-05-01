<?php
/**
 * Admin Courses View
 */

if (!defined('ABSPATH')) {
    exit;
}

// Handle Form Submission
$message = '';
wp_enqueue_media(); // Required for Image Upload

if (isset($_POST['dl_save_course']) && wp_verify_nonce($_POST['dl_course_nonce'], 'dl_save_course')) {
    $course_id = isset($_POST['course_id']) ? intval($_POST['course_id']) : 0;
    $title = sanitize_text_field($_POST['course_title']);

    $post_data = array(
        'post_title' => $title,
        'post_type' => 'dogology_course',
        'post_status' => 'publish',
    );

    if ($course_id > 0) {
        $post_data['ID'] = $course_id;
        $pid = wp_update_post($post_data);
        $action = 'updated';
    } else {
        $pid = wp_insert_post($post_data);
        $action = 'created';
    }

    if (!is_wp_error($pid)) {
        // Save Featured Image
        if (isset($_POST['course_thumbnail_id'])) {
            $thumb_id = intval($_POST['course_thumbnail_id']);
            if ($thumb_id > 0) {
                set_post_thumbnail($pid, $thumb_id);
            } else {
                delete_post_thumbnail($pid);
            }
        }

        // On new-course create, jump the admin straight into the builder.
        if ($action === 'created' && !headers_sent()) {
            wp_safe_redirect(admin_url('admin.php?page=dogology-learning-builder&course_id=' . intval($pid)));
            exit;
        }

        $message = "Course $action successfully.";
    } else {
        $message = 'Error saving course.';
    }
}

// Handle Delete
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    if (wp_verify_nonce($_GET['_wpnonce'], 'delete_course_' . $_GET['id'])) {
        wp_delete_post(intval($_GET['id']), true);
        $message = 'Course deleted.';
    }
}

// Get Data
$editing = null;
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $editing = get_post(intval($_GET['id']));
}

$courses = get_posts(array(
    'post_type' => 'dogology_course',
    'numberposts' => -1,
    'orderby' => 'title',
    'order' => 'ASC',
));

// Look up cohorts linked to each course (many-to-one via wp_dogology_cohorts.linked_course_id).
// Guarded: if the commerce plugin's 1.0.58 migration hasn't run yet, the column is absent.
global $wpdb;
$table_cohorts = $wpdb->prefix . 'dogology_cohorts';
$linked_cohorts_by_course = [];
$has_linked_col = (bool) $wpdb->get_var($wpdb->prepare(
    "SHOW COLUMNS FROM $table_cohorts LIKE %s",
    'linked_course_id'
));
if ($courses && $has_linked_col) {
    $course_ids = wp_list_pluck($courses, 'ID');
    $placeholders = implode(',', array_fill(0, count($course_ids), '%d'));
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, name, linked_course_id FROM $table_cohorts
         WHERE linked_course_id IN ($placeholders)",
        $course_ids
    ));
    foreach ($rows as $r) {
        $linked_cohorts_by_course[$r->linked_course_id][] = $r;
    }
}

// One grouped query for lesson counts per course (replaces N+1 get_posts loop).
$lesson_counts_by_course = [];
if ($courses) {
    $counts = $wpdb->get_results("
        SELECT pm.meta_value AS course_id, COUNT(*) AS cnt
        FROM {$wpdb->postmeta} pm
        JOIN {$wpdb->posts} p ON p.ID = pm.post_id
        WHERE pm.meta_key = '_dogology_parent_course'
          AND p.post_type = 'dogology_lesson'
          AND p.post_status = 'publish'
        GROUP BY pm.meta_value
    ");
    foreach ($counts as $c) {
        $lesson_counts_by_course[(int) $c->course_id] = (int) $c->cnt;
    }
}
?>

<div class="wrap dogology-learning-wrap">
    <h1>
        <?php _e('Courses', 'dogology-learning'); ?>
    </h1>

    <?php if ($message): ?>
        <div class="notice notice-success inline">
            <p>
                <?php echo esc_html($message); ?>
            </p>
        </div>
    <?php endif; ?>

    <div class="dl-card">
        <div class="dl-card-header">
            <h3 class="dl-card-title">
                <?php echo $editing ? 'Edit Course' : 'Add New Course'; ?>
            </h3>
        </div>
        <form method="post" action="<?php echo admin_url('admin.php?page=dogology-learning-courses'); ?>">
            <?php wp_nonce_field('dl_save_course', 'dl_course_nonce'); ?>
            <?php if ($editing): ?>
                <input type="hidden" name="course_id" value="<?php echo $editing->ID; ?>">
            <?php endif; ?>

            <div class="dl-form-group">
                <label for="course_title">Course Title</label>
                <input type="text" id="course_title" name="course_title"
                    value="<?php echo $editing ? esc_attr($editing->post_title) : ''; ?>" required>
            </div>

            <?php if ($editing):
                $linked_here = isset($linked_cohorts_by_course[$editing->ID]) ? $linked_cohorts_by_course[$editing->ID] : [];
            ?>
                <div class="dl-form-group">
                    <label>Linked Cohorts</label>
                    <?php if ($linked_here): ?>
                        <p style="margin: 0;">
                            <?php foreach ($linked_here as $lc): ?>
                                <span class="dl-badge dl-badge-open" style="margin-right:6px;">📦 <?php echo esc_html($lc->name); ?></span>
                            <?php endforeach; ?>
                        </p>
                    <?php else: ?>
                        <p style="margin: 0; color: #888;">No cohorts linked yet.</p>
                    <?php endif; ?>
                    <p class="dl-form-help">Manage links in Dogology Commerce → Cohorts. Any number of cohorts can auto-enroll buyers into this course.</p>
                </div>
            <?php endif; ?>

            <!-- Featured Image Upload -->
            <div class="dl-form-group">
                <label>Featured Image</label>
                <?php
                $thumb_id = $editing ? get_post_thumbnail_id($editing->ID) : '';
                $thumb_url = $thumb_id ? wp_get_attachment_url($thumb_id) : '';
                ?>
                <input type="hidden" name="course_thumbnail_id" id="course_thumbnail_id"
                    value="<?php echo esc_attr($thumb_id); ?>">

                <div id="course_thumbnail_preview" style="margin-bottom: 10px;">
                    <?php if ($thumb_url): ?>
                        <img src="<?php echo esc_url($thumb_url); ?>"
                            style="max-width: 200px; height: auto; border-radius: 8px;">
                    <?php endif; ?>
                </div>

                <button type="button" class="button" id="upload_course_image_btn">Select Image</button>
                <button type="button" class="button" id="remove_course_image_btn"
                    style="color: #a00; <?php echo $thumb_id ? '' : 'display:none;'; ?>">Remove</button>
            </div>

            <script>
                jQuery(document).ready(function ($) {
                    $('#upload_course_image_btn').click(function (e) {
                        e.preventDefault();
                        var image = wp.media({
                            title: 'Upload Image',
                            multiple: false
                        }).open()
                            .on('select', function (e) {
                                var uploaded_image = image.state().get('selection').first();
                                var image_url = uploaded_image.toJSON().url;
                                var image_id = uploaded_image.toJSON().id;

                                $('#course_thumbnail_id').val(image_id);
                                $('#course_thumbnail_preview').html('<img src="' + image_url + '" style="max-width: 200px; height: auto; border-radius: 8px;">');
                                $('#remove_course_image_btn').show();
                            });
                    });

                    $('#remove_course_image_btn').click(function () {
                        $('#course_thumbnail_id').val('');
                        $('#course_thumbnail_preview').html('');
                        $(this).hide();
                    });
                });
            </script>

            <p>
                <button type="submit" name="dl_save_course" class="dl-btn dl-btn-primary">
                    <?php echo $editing ? 'Update Course' : 'Create Course'; ?>
                </button>
                <?php if ($editing): ?>
                    <a href="<?php echo admin_url('admin.php?page=dogology-learning-courses'); ?>"
                        class="dl-btn dl-btn-secondary">Cancel</a>
                <?php endif; ?>
            </p>
        </form>
    </div>

    <div class="dl-card">
        <table class="dl-table">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Linked Cohorts</th>
                    <th>Lessons</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($courses): ?>
                    <?php foreach ($courses as $course): ?>
                        <?php
                        $linked_here = isset($linked_cohorts_by_course[$course->ID]) ? $linked_cohorts_by_course[$course->ID] : [];
                        $count_lessons = isset($lesson_counts_by_course[$course->ID]) ? $lesson_counts_by_course[$course->ID] : 0;
                        $builder_url = admin_url('admin.php?page=dogology-learning-builder&course_id=' . $course->ID);
                        ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url($builder_url); ?>"><strong><?php echo esc_html($course->post_title); ?></strong></a>
                            </td>
                            <td>
                                <?php if ($linked_here): ?>
                                    <?php foreach ($linked_here as $lc): ?>
                                        <span class="dl-badge dl-badge-open" style="margin-right:4px;">📦 <?php echo esc_html($lc->name); ?></span>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo $count_lessons; ?>
                            </td>
                            <td>
                                <a href="<?php echo esc_url($builder_url); ?>"
                                    class="dl-btn dl-btn-primary dl-btn-sm">Open Builder</a>
                                <a href="<?php echo admin_url('admin.php?page=dogology-learning-courses&action=edit&id=' . $course->ID); ?>"
                                    class="dl-btn dl-btn-secondary dl-btn-sm">✏️</a>
                                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=dogology-learning-courses&action=delete&id=' . $course->ID), 'delete_course_' . $course->ID); ?>"
                                    class="dl-btn dl-btn-danger dl-btn-sm" onclick="return confirm('Delete course?');">🗑️</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4">No courses yet.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>