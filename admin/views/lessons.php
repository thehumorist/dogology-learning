<?php
/**
 * Admin Lessons View
 */

if (!defined('ABSPATH')) {
    exit;
}

// Handle Form Submission
$message = '';

if (isset($_POST['dl_save_lesson']) && wp_verify_nonce($_POST['dl_lesson_nonce'], 'dl_save_lesson')) {
    // Server-side validation: parent_module is required. Without it the lesson
    // has no _dogology_parent_course meta, making it invisible to students
    // (the player's 1.1.67 ownership check 403s lessons with missing parent).
    // The form has `required` client-side but the POST handler must enforce
    // this too — otherwise a stripped form submit creates orphan lessons.
    $parent_module_id = isset($_POST['parent_module']) ? intval($_POST['parent_module']) : 0;
    if (!$parent_module_id) {
        $message = 'Parent module is required. Please select a module before saving.';
    } else {
    $lesson_id = isset($_POST['lesson_id']) ? intval($_POST['lesson_id']) : 0;
    $title = sanitize_text_field($_POST['lesson_title']);
    $description = wp_kses_post($_POST['lesson_description']); // Rich Text

    $post_data = array(
        'post_title' => $title,
        'post_content' => $description, // Save rich text to main content
        'post_type' => 'dogology_lesson',
        'post_status' => 'publish',
        'menu_order' => intval($_POST['lesson_order']), // Save Order
    );

    if ($lesson_id > 0) {
        $post_data['ID'] = $lesson_id;
        $pid = wp_update_post($post_data);
        $action = 'updated';
    } else {
        $pid = wp_insert_post($post_data);
        $action = 'created';
    }

    if (!is_wp_error($pid)) {
        // Save Meta
        update_post_meta($pid, '_dogology_subtitle', sanitize_text_field($_POST['subtitle']));
        update_post_meta($pid, '_dogology_parent_module', $parent_module_id);

        // Also save parent course for easier flat queries.
        // $parent_module_id is guaranteed truthy (validated above).
        $parent_course_id = (int) get_post_meta($parent_module_id, '_dogology_parent_course', true);
        if ($parent_course_id) {
            update_post_meta($pid, '_dogology_parent_course', $parent_course_id);
        }

        update_post_meta($pid, '_dogology_video_url', sanitize_url($_POST['video_url']));
        update_post_meta($pid, '_dogology_duration', sanitize_text_field($_POST['duration']));
        update_post_meta($pid, '_dogology_attachment_url', sanitize_url($_POST['attachment_url']));
        update_post_meta($pid, '_dogology_attachment_title', sanitize_text_field($_POST['attachment_title']));
        update_post_meta($pid, '_dogology_attachment_subtitle', sanitize_text_field($_POST['attachment_subtitle']));
        update_post_meta($pid, '_dogology_attachment_cta', sanitize_text_field($_POST['attachment_cta']));

        $message = "Lesson $action successfully.";
    } else {
        $message = 'Error saving lesson.';
    }
    } // close else (parent_module_id present)
}

// Handle Delete
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    if (wp_verify_nonce($_GET['_wpnonce'], 'delete_lesson_' . $_GET['id'])) {
        wp_delete_post(intval($_GET['id']), true);
        $message = 'Lesson deleted.';
    }
}

// Get Data
$editing = null;
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $editing = get_post(intval($_GET['id']));
    $edt_subtitle = get_post_meta($editing->ID, '_dogology_subtitle', true);
    $edt_parent_module = get_post_meta($editing->ID, '_dogology_parent_module', true);
    // Backward compatibility: check parent course if module is missing? 
    // For now, let's assume we are moving forward.
    $edt_video = get_post_meta($editing->ID, '_dogology_video_url', true);
    $edt_duration = get_post_meta($editing->ID, '_dogology_duration', true);
    $edt_attach = get_post_meta($editing->ID, '_dogology_attachment_url', true);
    $edt_attach_title = get_post_meta($editing->ID, '_dogology_attachment_title', true);
    $edt_attach_subtitle = get_post_meta($editing->ID, '_dogology_attachment_subtitle', true);
    $edt_attach_cta = get_post_meta($editing->ID, '_dogology_attachment_cta', true);
}

// Get All Lessons (for table)
$lessons = get_posts(array(
    'post_type' => 'dogology_lesson',
    'numberposts' => -1,
));

// Cache to prevent repetitive DB calls for module orders and course titles
$module_cache = [];
$course_cache = [];

foreach ($lessons as $lesson) {
    $l_module_id = get_post_meta($lesson->ID, '_dogology_parent_module', true);

    // Default fallback values
    $course_title = 'ZZZ'; // Fall to bottom
    $module_order = 99999;

    if ($l_module_id) {
        if (!isset($module_cache[$l_module_id])) {
            $l_module = get_post($l_module_id);
            if ($l_module) {
                // Cache module order
                $module_cache[$l_module_id]['order'] = intval($l_module->menu_order);

                // Fetch & Cache course title
                $l_course_id = get_post_meta($l_module_id, '_dogology_parent_course', true);
                if ($l_course_id) {
                    if (!isset($course_cache[$l_course_id])) {
                        $course_cache[$l_course_id] = get_the_title($l_course_id);
                    }
                    $module_cache[$l_module_id]['course'] = $course_cache[$l_course_id];
                } else {
                    $module_cache[$l_module_id]['course'] = 'ZZZ_Orphaned';
                }
            } else {
                $module_cache[$l_module_id] = ['order' => 99999, 'course' => 'ZZZ_Deleted_Module'];
            }
        }
        $course_title = $module_cache[$l_module_id]['course'];
        $module_order = $module_cache[$l_module_id]['order'];
    }

    $lesson->_cached_course_title = $course_title;
    $lesson->_cached_module_order = $module_order;
}

// Sort the lessons
usort($lessons, function ($a, $b) {
    // 1. Sort by Course Title
    $course_cmp = strcmp($a->_cached_course_title, $b->_cached_course_title);
    if ($course_cmp !== 0)
        return $course_cmp;

    // 2. Sort by Module Order
    if ($a->_cached_module_order !== $b->_cached_module_order) {
        return $a->_cached_module_order - $b->_cached_module_order;
    }

    // 3. Sort by Lesson Order
    return $a->menu_order - $b->menu_order;
});

$courses = get_posts(array('post_type' => 'dogology_course', 'numberposts' => -1));
?>

<div class="wrap dogology-learning-wrap">
    <h1>
        <?php _e('Lessons', 'dogology-learning'); ?>
    </h1>

    <div class="notice notice-info" style="margin: 10px 0;">
        <p>
            <strong><?php _e('A better editor is available.', 'dogology-learning'); ?></strong>
            <?php _e('The new Course Builder lets you edit lessons in a side drawer, reorder them with drag-and-drop, and move them between modules — all without leaving the page.', 'dogology-learning'); ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=dogology-learning-courses')); ?>" class="button button-primary" style="margin-left: 8px;">
                <?php _e('Open Courses', 'dogology-learning'); ?>
            </a>
            <span style="color: #50575e; margin-left: 8px;"><?php _e('This page will be removed in a future release.', 'dogology-learning'); ?></span>
        </p>
    </div>

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
                <?php echo $editing ? 'Edit Lesson' : 'Add New Lesson'; ?>
            </h3>
        </div>
        <form method="post" action="<?php echo admin_url('admin.php?page=dogology-learning-lessons'); ?>">
            <?php wp_nonce_field('dl_save_lesson', 'dl_lesson_nonce'); ?>
            <?php if ($editing): ?>
                <input type="hidden" name="lesson_id" value="<?php echo $editing->ID; ?>">
            <?php endif; ?>

            <div class="dl-form-group">
                <label for="lesson_title">Lesson Title</label>
                <input type="text" id="lesson_title" name="lesson_title"
                    value="<?php echo $editing ? esc_attr($editing->post_title) : ''; ?>" required
                    placeholder="e.g. 1. Introduction">
            </div>

            <div class="dl-form-group">
                <label for="lesson_order">Order (0 = First)</label>
                <input type="number" id="lesson_order" name="lesson_order"
                    value="<?php echo $editing ? esc_attr($editing->menu_order) : '0'; ?>">
            </div>

            <div class="dl-form-group">
                <label for="subtitle">Subtitle</label>
                <input type="text" id="subtitle" name="subtitle"
                    value="<?php echo $editing ? esc_attr($edt_subtitle) : ''; ?>"
                    placeholder="e.g. A brief overview of what we will learn">
            </div>

            <div class="dl-form-group">
                <label for="parent_module">Belongs to Module</label>
                <select id="parent_module" name="parent_module" required>
                    <option value="">-- Select Module --</option>
                    <?php
                    // Group Modules by Course
                    $all_modules = get_posts(array('post_type' => 'dogology_module', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC'));
                    $grouped = [];
                    foreach ($all_modules as $m) {
                        $c_id = get_post_meta($m->ID, '_dogology_parent_course', true);
                        if ($c_id) {
                            $grouped[$c_id][] = $m;
                        } else {
                            $grouped['orphaned'][] = $m;
                        }
                    }

                    // Fetch Courses for labels
                    foreach ($grouped as $c_id => $modules) {
                        if ($c_id === 'orphaned') {
                            echo '<optgroup label="Orphaned Modules">';
                        } else {
                            $course_title = get_the_title($c_id);
                            echo '<optgroup label="' . esc_attr($course_title) . '">';
                        }

                        foreach ($modules as $mod) {
                            ?>
                            <option value="<?php echo esc_attr($mod->ID); ?>" <?php selected($editing ? $edt_parent_module : '', $mod->ID); ?>>
                                <?php echo esc_html($mod->post_title); ?>
                            </option>
                            <?php
                        }
                        echo '</optgroup>';
                    }
                    ?>
                </select>
                <p class="dl-form-help">Lessons must belong to a Module. Create a Module first if none exist.</p>
            </div>

            <div class="dl-form-group">
                <label for="lesson_description">Description / Content</label>
                <?php
                $content = $editing ? $editing->post_content : '';
                wp_editor(
                    $content,
                    'lesson_description',
                    array(
                        'textarea_name' => 'lesson_description',
                        'textarea_rows' => 10,
                        'media_buttons' => false, // Keep it simple? Or true if they want images.
                        'teeny' => true,
                    )
                );
                ?>
                <p class="dl-form-help">This text will appear below the video.</p>
            </div>

            <div class="dl-form-group">
                <label for="video_url">Video URL (YouTube)</label>
                <input type="url" id="video_url" name="video_url"
                    value="<?php echo $editing ? esc_attr($edt_video) : ''; ?>" placeholder="https://youtu.be/...">
            </div>

            <div class="dl-form-group">
                <label for="duration">Duration (e.g. 10:00)</label>
                <input type="text" id="duration" name="duration"
                    value="<?php echo $editing ? esc_attr($edt_duration) : ''; ?>" style="max-width: 100px;">
            </div>

            <div class="dl-form-group">
                <label for="attachment_url">PDF / Attachment URL</label>
                <input type="url" id="attachment_url" name="attachment_url"
                    value="<?php echo $editing ? esc_attr($edt_attach) : ''; ?>" placeholder="https://...">
            </div>

            <div class="dl-form-group">
                <label for="attachment_title">Attachment: Title</label>
                <input type="text" id="attachment_title" name="attachment_title"
                    value="<?php echo $editing ? esc_attr($edt_attach_title) : ''; ?>"
                    placeholder="e.g. Download Material">
            </div>

            <div class="dl-form-group">
                <label for="attachment_subtitle">Attachment: Subtitle</label>
                <input type="text" id="attachment_subtitle" name="attachment_subtitle"
                    value="<?php echo $editing ? esc_attr($edt_attach_subtitle) : ''; ?>"
                    placeholder="e.g. เอกสารประกอบสำหรับบทเรียนนี้">
            </div>

            <div class="dl-form-group">
                <label for="attachment_cta">Attachment: CRM / Button Text</label>
                <input type="text" id="attachment_cta" name="attachment_cta"
                    value="<?php echo $editing ? esc_attr($edt_attach_cta) : ''; ?>" placeholder="e.g. Download">
            </div>

            <p>
                <button type="submit" name="dl_save_lesson" class="dl-btn dl-btn-primary">
                    <?php echo $editing ? 'Update Lesson' : 'Create Lesson'; ?>
                </button>
                <?php if ($editing): ?>
                    <a href="<?php echo admin_url('admin.php?page=dogology-learning-lessons'); ?>"
                        class="dl-btn dl-btn-secondary">Cancel</a>
                <?php endif; ?>
            </p>
        </form>
    </div>

    <div class="dl-card">
        <table class="dl-table">
            <thead>
                <tr>
                    <th>Module</th>
                    <th>Order</th>
                    <th>Title</th>
                    <th>Duration</th>
                    <th>Attachments</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($lessons): ?>
                    <?php foreach ($lessons as $lesson): ?>
                        <?php
                        $l_module_id = get_post_meta($lesson->ID, '_dogology_parent_module', true);
                        $legacy_course_id = null;
                        if ($l_module_id) {
                            $l_module = get_post($l_module_id);
                            if ($l_module) {
                                // Get Course Name via Module
                                $l_course_id = get_post_meta($l_module_id, '_dogology_parent_course', true);
                                $l_course_name = $l_course_id ? get_the_title($l_course_id) : 'Unknown Course';
                            } else {
                                // Module was deleted
                                $legacy_course_id = get_post_meta($lesson->ID, '_dogology_parent_course', true);
                                $l_course_name = $legacy_course_id ? get_the_title($legacy_course_id) . ' (Orphaned)' : '-';
                            }
                        } else {
                            // Legacy Check
                            $l_module = null;
                            $legacy_course_id = get_post_meta($lesson->ID, '_dogology_parent_course', true);
                            $l_course_name = $legacy_course_id ? get_the_title($legacy_course_id) . ' (Legacy)' : '-';
                        }

                        $l_duration = get_post_meta($lesson->ID, '_dogology_duration', true);
                        $l_attach = get_post_meta($lesson->ID, '_dogology_attachment_url', true);
                        ?>
                        <tr>
                            <td>
                                <?php
                                if ($l_module) {
                                    echo '<strong>' . esc_html($l_module->post_title) . '</strong><br><small class="text-gray-500">in ' . esc_html($l_course_name) . '</small>';
                                } else {
                                    echo '<span class="dl-badge dl-badge-danger">No Module</span>';
                                    if ($legacy_course_id)
                                        echo '<br><small>Was in: ' . $l_course_name . '</small>';
                                }
                                ?>
                                    </td>
                                    <td><?php echo intval($lesson->menu_order); ?></td>
                                    <td><strong>
                                            <?php echo esc_html($lesson->post_title); ?>
                   </strong>
                            </td>
                            <td>
                                <?php echo esc_html($l_duration); ?>
                            </td>
                            <td>
                                <?php echo $l_attach ? '📎 Yes' : '-'; ?>
                            </td>
                            <td>
                                <a href="<?php echo admin_url('admin.php?page=dogology-learning-lessons&action=edit&id=' . $lesson->ID); ?>"
                                    class="dl-btn dl-btn-secondary dl-btn-sm">✏️</a>
                                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=dogology-learning-lessons&action=delete&id=' . $lesson->ID), 'delete_lesson_' . $lesson->ID); ?>"
                                    class="dl-btn dl-btn-danger dl-btn-sm" onclick="return confirm('Delete lesson?');">🗑️</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6">No lessons yet.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>