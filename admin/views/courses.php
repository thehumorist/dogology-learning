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

        $message = "Course $action successfully.";

        // --- Format + catalog fields (ebook support) ---
        $format = (isset($_POST['dl_format']) && $_POST['dl_format'] === 'ebook') ? 'ebook' : 'course';
        update_post_meta($pid, '_dogology_format', $format);
        update_post_meta($pid, '_dogology_public_listed', isset($_POST['dl_public_listed']) ? '1' : '');
        update_post_meta($pid, '_dogology_sales_url', esc_url_raw($_POST['dl_sales_url'] ?? ''));
        update_post_meta($pid, '_dogology_price_label', sanitize_text_field($_POST['dl_price_label'] ?? ''));

        // --- Ebook PDF upload → protected dir (never the public media library) ---
        if ($format === 'ebook' && !empty($_FILES['dl_ebook_pdf']['name'])) {
            $up = wp_handle_upload($_FILES['dl_ebook_pdf'], array(
                'test_form' => false,
                'mimes'     => array('pdf' => 'application/pdf'),
            ));
            if (isset($up['error'])) {
                $message = 'PDF upload failed: ' . $up['error'];
            } else {
                // random suffix: defense-in-depth for servers where .htaccess is
                // ignored (nginx) — the stored URL is unguessable either way
                $base = sanitize_file_name(pathinfo($up['file'], PATHINFO_FILENAME));
                $fname = $base . '-' . wp_generate_password(12, false) . '.pdf';
                $dest = Dogology_Ebook::dir() . '/' . $fname;
                if (@rename($up['file'], $dest)) {
                    // FPDI parse probe: fail loudly at config time, never at buyer download time
                    $probe = Dogology_Ebook::probe($dest);
                    if (is_wp_error($probe)) {
                        @unlink($dest);
                        $message = 'PDF rejected: ' . $probe->get_error_message();
                    } else {
                        update_post_meta($pid, '_dogology_ebook_pdf', $fname);
                        // stamped copies of the old source are stale now
                        array_map('unlink', glob(Dogology_Ebook::dir() . '/cache/' . intval($pid) . '-*.pdf') ?: array());
                        $message = "Course $action + PDF \"$fname\" saved (compatibility probe passed).";
                    }
                } else {
                    @unlink($up['file']);
                    $message = 'Could not move PDF into the protected dir.';
                }
            }
        }

        // On new COURSE create, jump straight into the builder (ebooks have no lessons — stay here).
        if ($action === 'created' && $format !== 'ebook' && !headers_sent()) {
            wp_safe_redirect(admin_url('admin.php?page=dogology-learning-builder&course_id=' . intval($pid)));
            exit;
        }
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
        <form method="post" enctype="multipart/form-data" action="<?php echo admin_url('admin.php?page=dogology-learning-courses'); ?>">
            <?php wp_nonce_field('dl_save_course', 'dl_course_nonce'); ?>
            <?php if ($editing): ?>
                <input type="hidden" name="course_id" value="<?php echo $editing->ID; ?>">
            <?php endif; ?>

            <div class="dl-form-group">
                <label for="course_title">Course Title</label>
                <input type="text" id="course_title" name="course_title"
                    value="<?php echo $editing ? esc_attr($editing->post_title) : ''; ?>" required>
            </div>

            <?php
            $cur_format   = $editing ? (get_post_meta($editing->ID, '_dogology_format', true) ?: 'course') : 'course';
            $cur_pdf      = $editing ? get_post_meta($editing->ID, '_dogology_ebook_pdf', true) : '';
            $cur_listed   = $editing ? get_post_meta($editing->ID, '_dogology_public_listed', true) : '';
            $cur_sales    = $editing ? get_post_meta($editing->ID, '_dogology_sales_url', true) : '';
            $cur_price    = $editing ? get_post_meta($editing->ID, '_dogology_price_label', true) : '';
            ?>
            <div class="dl-form-group">
                <label for="dl_format">Format</label>
                <select id="dl_format" name="dl_format">
                    <option value="course" <?php selected($cur_format, 'course'); ?>>คอร์สเรียน (lessons + player)</option>
                    <option value="ebook" <?php selected($cur_format, 'ebook'); ?>>E-Book (PDF download)</option>
                </select>
            </div>

            <div class="dl-form-group" id="dl_ebook_pdf_group" style="<?php echo $cur_format === 'ebook' ? '' : 'display:none;'; ?>">
                <label for="dl_ebook_pdf">E-Book PDF</label>
                <?php if ($cur_pdf): ?>
                    <p style="margin:0 0 6px;">Current file: <code><?php echo esc_html($cur_pdf); ?></code></p>
                <?php endif; ?>
                <input type="file" id="dl_ebook_pdf" name="dl_ebook_pdf" accept="application/pdf">
                <p class="dl-form-help">Stored in the protected <code>wp-content/dogology-ebooks/</code> dir (never the public media library).
                    Uploading a new file replaces the old one for ALL buyers on their next download — free silent updates.
                    The file is probe-tested for stamping compatibility on save.</p>
            </div>

            <div class="dl-form-group">
                <label>
                    <input type="checkbox" name="dl_public_listed" value="1" <?php checked($cur_listed, '1'); ?>>
                    Show in /my-courses catalog (public listing — unpurchased students see it as locked)
                </label>
            </div>

            <div class="dl-form-group">
                <label for="dl_sales_url">Sales URL <span style="color:#888;font-weight:normal;">(where a locked card's button goes)</span></label>
                <input type="url" id="dl_sales_url" name="dl_sales_url" value="<?php echo esc_attr($cur_sales); ?>" placeholder="https://dogology.org/ebook-watchdog/">
            </div>

            <div class="dl-form-group">
                <label for="dl_price_label">Price Label <span style="color:#888;font-weight:normal;">(display only, e.g. ฿590)</span></label>
                <input type="text" id="dl_price_label" name="dl_price_label" value="<?php echo esc_attr($cur_price); ?>" placeholder="฿590">
            </div>

            <script>
                jQuery(function ($) {
                    $('#dl_format').on('change', function () {
                        $('#dl_ebook_pdf_group').toggle($(this).val() === 'ebook');
                    });
                });
            </script>

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
                        $edit_url = admin_url('admin.php?page=dogology-learning-courses&action=edit&id=' . $course->ID);
                        $is_ebook = get_post_meta($course->ID, '_dogology_format', true) === 'ebook';
                        $row_url = $is_ebook ? $edit_url : $builder_url;
                        ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url($row_url); ?>"><strong><?php echo esc_html($course->post_title); ?></strong></a>
                                <?php if ($is_ebook): ?>
                                    <span class="dl-badge dl-badge-open" style="margin-left:6px;">📖 E-Book</span>
                                <?php endif; ?>
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
                                <?php echo $is_ebook ? '—' : $count_lessons; ?>
                            </td>
                            <td>
                                <?php if (!$is_ebook): ?>
                                    <a href="<?php echo esc_url($builder_url); ?>"
                                        class="dl-btn dl-btn-primary dl-btn-sm">Open Builder</a>
                                <?php endif; ?>
                                <a href="<?php echo esc_url($edit_url); ?>"
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