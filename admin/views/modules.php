<?php
/**
 * Admin Modules View
 */

if (!defined('ABSPATH')) {
    exit;
}

// Handle Form Submission
$message = '';

if (isset($_POST['dl_save_module']) && wp_verify_nonce($_POST['dl_module_nonce'], 'dl_save_module')) {
    // Server-side validation: parent_course is required. An orphan module with
    // no _dogology_parent_course breaks the builder's tree query and causes
    // any lessons created under it to inherit an empty parent-course (which
    // then fails the player's 1.1.67 ownership check, 403-ing students).
    $parent_course_id = isset($_POST['parent_course']) ? intval($_POST['parent_course']) : 0;
    if (!$parent_course_id) {
        $message = 'Parent course is required. Please select a course before saving.';
    } else {
        $module_id = isset($_POST['module_id']) ? intval($_POST['module_id']) : 0;
        $title = sanitize_text_field($_POST['module_title']);

        $post_data = array(
            'post_title' => $title,
            'post_type' => 'dogology_module',
            'post_status' => 'publish',
            'menu_order' => intval($_POST['module_order']), // Save Order
        );

        if ($module_id > 0) {
            $post_data['ID'] = $module_id;
            $pid = wp_update_post($post_data);
            $action = 'updated';
        } else {
            $pid = wp_insert_post($post_data);
            $action = 'created';
        }

        if (!is_wp_error($pid)) {
            // Save Meta — parent_course_id is guaranteed truthy (validated above).
            update_post_meta($pid, '_dogology_parent_course', $parent_course_id);

            $message = "Module $action successfully.";
        } else {
            $message = 'Error saving module.';
        }
    }
}

// Handle Delete
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    if (wp_verify_nonce($_GET['_wpnonce'], 'delete_module_' . $_GET['id'])) {
        wp_delete_post(intval($_GET['id']), true);
        $message = 'Module deleted.';
    }
}

// Get Data
$editing = null;
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $editing = get_post(intval($_GET['id']));
    $edt_parent = get_post_meta($editing->ID, '_dogology_parent_course', true);
}

// Get All Modules
$modules = get_posts(array(
    'post_type' => 'dogology_module',
    'numberposts' => -1,
    'orderby' => 'menu_order', // Sort by Order
    'order' => 'ASC',
));

// Get All Courses for Dropdown
$courses = get_posts(array('post_type' => 'dogology_course', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC'));
?>

<div class="wrap dogology-learning-wrap">
    <h1>
        <?php _e('Modules', 'dogology-learning'); ?>
    </h1>

    <div class="notice notice-info" style="margin: 10px 0;">
        <p>
            <strong><?php _e('A better editor is available.', 'dogology-learning'); ?></strong>
            <?php _e('The new Course Builder lets you edit modules and lessons inline on a single page per course, with drag-and-drop reordering.', 'dogology-learning'); ?>
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
                <?php echo $editing ? 'Edit Module' : 'Add New Module'; ?>
            </h3>
        </div>
        <form method="post" action="<?php echo admin_url('admin.php?page=dogology-learning-modules'); ?>">
            <?php wp_nonce_field('dl_save_module', 'dl_module_nonce'); ?>
            <?php if ($editing): ?>
                <input type="hidden" name="module_id" value="<?php echo $editing->ID; ?>">
            <?php endif; ?>

            <div class="dl-form-group">
                <label for="module_title">Module Title</label>
                <input type="text" id="module_title" name="module_title"
                    value="<?php echo $editing ? esc_attr($editing->post_title) : ''; ?>" required
                    placeholder="e.g. Module 1: Basics">
            </div>

            <div class="dl-form-group">
                <label for="module_order">Order (0 = First)</label>
                <input type="number" id="module_order" name="module_order"
                    value="<?php echo $editing ? esc_attr($editing->menu_order) : '0'; ?>">
            </div>

            <div class="dl-form-group">
                <label for="parent_course">Belongs to Course</label>
                <select id="parent_course" name="parent_course" required>
                    <option value="">-- Select Course --</option>
                    <?php foreach ($courses as $course): ?>
                        <option value="<?php echo esc_attr($course->ID); ?>" <?php selected($editing ? $edt_parent : '', $course->ID); ?>>
                            <?php echo esc_html($course->post_title); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <p>
                <button type="submit" name="dl_save_module" class="dl-btn dl-btn-primary">
                    <?php echo $editing ? 'Update Module' : 'Create Module'; ?>
                </button>
                <?php if ($editing): ?>
                    <a href="<?php echo admin_url('admin.php?page=dogology-learning-modules'); ?>"
                        class="dl-btn dl-btn-secondary">Cancel</a>
                <?php endif; ?>
            </p>
        </form>
    </div>

    <div class="dl-card">
        <table class="dl-table">
            <thead>
                <tr>
                    <th>Order</th>
                    <th>Title</th>
                    <th>Course</th>
                    <th>Lessons</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($modules): ?>
                    <?php foreach ($modules as $module): ?>
                        <?php
                        $m_course_id = get_post_meta($module->ID, '_dogology_parent_course', true);
                        $m_course = $m_course_id ? get_post($m_course_id) : null;
                        // Count lessons in this module
                        $lesson_count = count(get_posts(array(
                            'post_type' => 'dogology_lesson',
                            'meta_key' => '_dogology_parent_module',
                            'meta_value' => $module->ID,
                            'numberposts' => -1
                        )));
                        ?>
                        <tr>
                            <td>
                                <?php echo intval($module->menu_order); ?>
                            </td>
                            <td><strong>
                                    <?php echo esc_html($module->post_title); ?>
                                </strong></td>
                            <td>
                                <?php echo $m_course ? '<span class="dl-badge dl-badge-pending">📚 ' . esc_html($m_course->post_title) . '</span>' : '-'; ?>
                            </td>
                            <td>
                                <?php echo $lesson_count; ?>
                            </td>
                            <td>
                                <a href="<?php echo admin_url('admin.php?page=dogology-learning-modules&action=edit&id=' . $module->ID); ?>"
                                    class="dl-btn dl-btn-secondary dl-btn-sm">✏️</a>
                                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=dogology-learning-modules&action=delete&id=' . $module->ID), 'delete_module_' . $module->ID); ?>"
                                    class="dl-btn dl-btn-danger dl-btn-sm" onclick="return confirm('Delete module?');">🗑️</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5">No modules yet.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>