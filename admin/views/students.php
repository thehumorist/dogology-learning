<?php
$student_db = new Dogology_Student_DB();
$message = '';
$error = '';

/**
 * HANDLE ACTIONS
 */

// 1. Add New Student
if (isset($_POST['action']) && $_POST['action'] === 'add_student' && check_admin_referer('dl_add_student')) {
    $data = array(
        'display_name' => sanitize_text_field($_POST['display_name']),
        'email' => sanitize_email($_POST['email']),
        'line_uid' => sanitize_text_field($_POST['line_uid'])
    );

    $result = $student_db->create_student($data);
    if (is_wp_error($result)) {
        $error = $result->get_error_message();
    } else {
        $message = 'Student created successfully!';
    }
}

// 2. Update Student
if (isset($_POST['action']) && $_POST['action'] === 'update_student' && check_admin_referer('dl_update_student')) {
    $id = intval($_POST['student_id']);
    $data = array(
        'display_name' => sanitize_text_field($_POST['display_name']),
        'email' => sanitize_email($_POST['email']),
        'line_uid' => sanitize_text_field($_POST['line_uid'])
    );

    $existing_student = $student_db->get_student($id);
    if ($existing_student) {
        if (!empty($_POST['email_verified'])) {
            $data['email_verified_at'] = $existing_student->email_verified_at ?: current_time('mysql');
        } else {
            $data['email_verified_at'] = null;
        }
    }

    $student_db->update_student($id, $data);
    $message = 'Student updated.';
    // Redirect to list to exit edit mode, or stay? Let's stay in edit mode for ease.
}

// 3. Delete Student
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    if (wp_verify_nonce($_GET['_wpnonce'] ?? '', 'delete_student_' . $_GET['id'])) {
        $student_db->delete_student(intval($_GET['id']));
        $message = 'Student deleted.';
    } else {
        $error = 'Invalid security token.';
    }
}

// 4. Enroll Course
if (isset($_POST['action']) && $_POST['action'] === 'enroll_course' && check_admin_referer('dl_enroll_course')) {
    $student_id = intval($_POST['student_id']);
    $course_id = intval($_POST['course_id']);

    if ($student_id && $course_id) {
        $res = $student_db->enroll_student($student_id, $course_id);
        $message = 'Course assigned successfully.';
    }
}

// 5. Un-Enroll Course
if (isset($_GET['action']) && $_GET['action'] === 'remove_course') {
    $sid = intval($_GET['student_id']);
    $cid = intval($_GET['course_id']);
    if (wp_verify_nonce($_GET['_wpnonce'] ?? '', 'remove_enrollment_' . $sid . '_' . $cid)) {
        $student_db->remove_enrollment($sid, $cid);
        $message = 'Enrollment removed.';
    } else {
        $error = 'Invalid security token.';
    }
    // Keep user on edit page
    $_GET['action'] = 'edit';
    $_GET['id'] = $sid;
}

/**
 * PREPARE DATA
 */

// Edit Mode?
$edit_student = null;
$enrolled_courses = array();
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $edit_student = $student_db->get_student(intval($_GET['id']));
    if ($edit_student) {
        $enrolled_courses = $student_db->get_student_courses($edit_student->id);
    }
}

// Pagination for List
$page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;
$students = $student_db->get_students($limit, $offset);
$total_students = $student_db->count_students();
$total_pages = ceil($total_students / $limit);

// Get All Courses for Dropdown
$all_courses = get_posts(array(
    'post_type' => 'dogology_course',
    'numberposts' => -1,
    'post_status' => 'publish'
));

?>

<div class="wrap">
    <h1 class="wp-heading-inline">Students Manager</h1>

    <?php if ($message): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php echo esc_html($message); ?></p>
        </div><?php endif; ?>
    <?php if ($error): ?>
        <div class="notice notice-error is-dismissible">
            <p><?php echo esc_html($error); ?></p>
        </div><?php endif; ?>

    <hr class="wp-header-end">

    <div style="display: flex; gap: 20px; align-items: flex-start;">

        <!-- LEFT: Student List -->
        <div style="flex: 2;">
            <table class="wp-list-table widefat fixed striped table-view-list">
                <thead>
                    <tr>
                        <th width="50">ID</th>
                        <th>Student</th>
                        <th>Contact</th>
                        <th>Language</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($students)): ?>
                        <?php foreach ($students as $st): ?>
                            <tr class="<?php echo ($edit_student && $edit_student->id == $st->id) ? 'active-row' : ''; ?>"
                                style="<?php echo ($edit_student && $edit_student->id == $st->id) ? 'background-color:#e6f7ff;' : ''; ?>">
                                <td>#<?php echo $st->id; ?></td>
                                <td>
                                    <strong><?php echo esc_html($st->display_name); ?></strong>
                                    <?php if (!empty($st->passkey_id)): ?>
                                        <div style="margin-top:4px;">
                                            <span
                                                style="background:#e6fffa; color:#00AB8E; padding:2px 6px; border-radius:4px; font-size:11px; border:1px solid #b2f5ea;">
                                                ✅ FaceID Login Enabled
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo esc_html($st->email); ?>
                                    <?php if (!empty($st->email_verified_at)): ?>
                                        <span class="dashicons dashicons-yes-alt" style="color:#00AB8E; font-size:16px; margin-top:2px;" title="Verified On: <?php echo esc_attr(date_i18n('j M Y, H:i', strtotime($st->email_verified_at))); ?>"></span>
                                    <?php endif; ?>
                                    <br>
                                    <span class="text-xs text-gray-500">LINE:
                                        <?php echo $st->line_uid ? esc_html($st->line_uid) : '-'; ?></span>
                                </td>
                                <td>
                                    <?php
                                    $lang = !empty($st->language) ? strtoupper($st->language) : 'TH';
                                    $bg = $lang === 'TH' ? '#e6fffa' : '#ebf8ff';
                                    $color = $lang === 'TH' ? '#00AB8E' : '#0076BA';
                                    ?>
                                    <span
                                        style="background:<?php echo $bg; ?>; color:<?php echo $color; ?>; padding:2px 6px; border-radius:4px; font-size:11px; font-weight:bold;">
                                        <?php echo esc_html($lang); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="?page=dogology-learning-students&action=edit&id=<?php echo $st->id; ?>"
                                        class="button button-small">Edit / Enroll</a>
                                    <a href="<?php echo wp_nonce_url('?page=dogology-learning-students&action=delete&id=' . $st->id, 'delete_student_' . $st->id); ?>"
                                        class="button button-small button-link-delete"
                                        style="color:#d63638; border-color:#d63638; margin-left:5px;"
                                        onclick="return confirm('Are you sure you want to delete this student? This cannot be undone.');">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5">No students found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <?php
                        echo paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => __('&laquo;'),
                            'next_text' => __('&raquo;'),
                            'total' => $total_pages,
                            'current' => $page
                        ));
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- RIGHT: Form (Add or Edit) -->
        <div style="flex: 1; min-width: 300px;">
            <div class="postbox" style="padding: 15px;">
                <h2 class="hndle">
                    <?php echo $edit_student ? 'Edit Student: ' . esc_html($edit_student->display_name) : 'Add New Student'; ?>
                </h2>

                <form method="post" action="">
                    <?php wp_nonce_field($edit_student ? 'dl_update_student' : 'dl_add_student'); ?>
                    <input type="hidden" name="action"
                        value="<?php echo $edit_student ? 'update_student' : 'add_student'; ?>">
                    <?php if ($edit_student): ?><input type="hidden" name="student_id"
                            value="<?php echo $edit_student->id; ?>"><?php endif; ?>

                    <p>
                        <label><strong>Display Name</strong></label>
                        <input type="text" name="display_name" class="widefat" required
                            value="<?php echo $edit_student ? esc_attr($edit_student->display_name) : ''; ?>">
                    </p>
                    <p>
                        <label><strong>Email Address</strong></label>
                        <input type="email" name="email" class="widefat" required
                            value="<?php echo $edit_student ? esc_attr($edit_student->email) : ''; ?>">
                    </p>
                    <p>
                        <label><strong>LINE User ID</strong></label>
                        <input type="text" name="line_uid" class="widefat" placeholder="U123456..."
                            value="<?php echo $edit_student ? esc_attr($edit_student->line_uid) : ''; ?>">
                    </p>

                    <?php if ($edit_student): ?>
                    <p>
                        <label>
                            <input type="checkbox" name="email_verified" value="1" <?php checked(!empty($edit_student->email_verified_at)); ?>>
                            <strong>Email is Verified</strong>
                        </label>
                    </p>
                    <?php endif; ?>

                    <div style="display:flex; justify-content:space-between; margin-top:20px;">
                        <button type="submit"
                            class="button button-primary button-large"><?php echo $edit_student ? 'Save Changes' : 'Create Student'; ?></button>
                        <?php if ($edit_student): ?>
                            <a href="?page=dogology-learning-students" class="button">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- PROGRESS BOX (Only in Edit Mode) -->
            <?php if ($edit_student && !empty($enrolled_courses)): ?>
                <div class="postbox" style="padding: 15px; border-top: 4px solid #2271b1;">
                    <h2 class="hndle">📊 Progress</h2>
                    <?php foreach ($enrolled_courses as $course):
                        $tree = class_exists('Dogology_Learning_Builder')
                            ? Dogology_Learning_Builder::build_tree($course->ID)
                            : [];
                        $progress = $student_db->get_progress_for_course($edit_student->id, $course->ID);

                        $total = 0; $done = 0; $last_at = null;
                        foreach ($tree as $branch) {
                            foreach ($branch['lessons'] as $lesson) {
                                $total++;
                                $p = isset($progress[$lesson->ID]) ? $progress[$lesson->ID] : null;
                                if ($p && (int) $p->completed === 1) {
                                    $done++;
                                }
                                if ($p && $p->updated_at && (!$last_at || $p->updated_at > $last_at)) {
                                    $last_at = $p->updated_at;
                                }
                            }
                        }
                        $pct = $total > 0 ? round(($done / $total) * 100) : 0;
                    ?>
                        <details class="dl-progress-course" style="margin-bottom: 12px; border: 1px solid #dcdcde; border-radius: 6px;">
                            <summary style="cursor: pointer; padding: 10px 12px; display: flex; gap: 10px; align-items: center; user-select: none;">
                                <strong style="flex: 1;"><?php echo esc_html($course->post_title); ?></strong>
                                <span style="font-variant-numeric: tabular-nums; font-size: 12px; color: #50575e;">
                                    <?php echo $done; ?>/<?php echo $total; ?> · <?php echo $pct; ?>%
                                </span>
                            </summary>
                            <div style="padding: 4px 12px 12px;">
                                <div style="background: #f0f0f1; height: 6px; border-radius: 3px; overflow: hidden; margin-bottom: 10px;">
                                    <div style="background: #2271b1; height: 100%; width: <?php echo $pct; ?>%;"></div>
                                </div>
                                <?php if ($last_at): ?>
                                    <p style="margin: 0 0 10px; color: #8c8f94; font-size: 12px;">
                                        Last activity: <?php echo esc_html(date_i18n('j M Y, H:i', strtotime($last_at))); ?>
                                    </p>
                                <?php endif; ?>
                                <?php if (empty($tree)): ?>
                                    <p class="description">This course has no lessons yet.</p>
                                <?php else: ?>
                                    <?php foreach ($tree as $branch):
                                        $module = $branch['module'];
                                        $lessons = $branch['lessons'];
                                    ?>
                                        <div style="margin-bottom: 10px;">
                                            <div style="font-weight: 600; font-size: 12px; text-transform: uppercase; color: #50575e; margin-bottom: 4px;">
                                                <?php echo esc_html($module->post_title); ?>
                                            </div>
                                            <ul style="list-style: none; margin: 0; padding: 0;">
                                                <?php foreach ($lessons as $lesson):
                                                    $p = isset($progress[$lesson->ID]) ? $progress[$lesson->ID] : null;
                                                    $is_done = $p && (int) $p->completed === 1;
                                                ?>
                                                    <li style="padding: 4px 6px; display: flex; gap: 8px; align-items: center; font-size: 13px;">
                                                        <span style="<?php echo $is_done ? 'color:#1e7e34;' : 'color:#c3c4c7;'; ?>">
                                                            <?php echo $is_done ? '✓' : '○'; ?>
                                                        </span>
                                                        <span style="flex: 1; <?php echo $is_done ? '' : 'color:#50575e;'; ?>">
                                                            <?php echo esc_html($lesson->post_title); ?>
                                                        </span>
                                                        <?php if ($p && $p->updated_at): ?>
                                                            <span style="color:#8c8f94; font-size: 11px;">
                                                                <?php echo esc_html(date_i18n('j M', strtotime($p->updated_at))); ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </details>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- ENROLLMENT BOX (Only in Edit Mode) -->
            <?php if ($edit_student): ?>
                <div class="postbox" style="padding: 15px; border-top: 4px solid #00AB8E;">
                    <h2 class="hndle">🎓 Manual Enrollment</h2>

                    <!-- Assign Form -->
                    <form method="post"
                        style="background:#f9f9f9; padding:10px; border:1px solid #ddd; margin-bottom:15px;">
                        <?php wp_nonce_field('dl_enroll_course'); ?>
                        <input type="hidden" name="action" value="enroll_course">
                        <input type="hidden" name="student_id" value="<?php echo $edit_student->id; ?>">

                        <p style="margin-top:0;"><strong>Assign Course (Gift/Fallback)</strong></p>
                        <div style="display:flex; gap:5px;">
                            <select name="course_id" style="flex:1;">
                                <?php foreach ($all_courses as $course): ?>
                                    <option value="<?php echo $course->ID; ?>"><?php echo esc_html($course->post_title); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="button button-primary">Assign</button>
                        </div>
                    </form>

                    <!-- Enrolled List -->
                    <p><strong>Currently Enrolled:</strong></p>
                    <?php if (!empty($enrolled_courses)): ?>
                        <ul style="list-style: disc; padding-left: 20px;">
                            <?php foreach ($enrolled_courses as $c): ?>
                                <li>
                                    <a href="<?php echo get_edit_post_link($c->ID); ?>"
                                        target="_blank"><?php echo esc_html($c->post_title); ?></a>
                                    <span style="color:#ccc; margin-left:5px;">
                                        (<a href="<?php echo wp_nonce_url('?page=dogology-learning-students&action=remove_course&student_id=' . $edit_student->id . '&course_id=' . $c->ID, 'remove_enrollment_' . $edit_student->id . '_' . $c->ID); ?>"
                                            onclick="return confirm('Remove access to this course?');" class="text-red-500"
                                            style="color:#d63638; text-decoration:none;">Remove</a>)
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="description">No active enrollments.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>