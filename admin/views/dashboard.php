<?php
/**
 * Admin Dashboard View
 */

if (!defined('ABSPATH')) {
    exit;
}

// Fetch Stats
$db = new Dogology_Student_DB();
$count_students = $db->count_students();
$count_enrollments = $db->count_enrollments();
$count_courses = wp_count_posts('dogology_course')->publish;
$count_lessons = wp_count_posts('dogology_lesson')->publish;
$recent_enrollments = $db->get_recent_enrollments(5);

/**
 * Per-course completion stats.
 *
 * Lessons hang off a course via the `_dogology_parent_course` postmeta, NOT
 * post_parent — same grouped-query shape already used on the Courses screen.
 * Access + progress both live in wp_dogology_progress: the lesson_id = 0 row is
 * the enrolment marker (completed = 0, so it never inflates the lesson tally).
 */
global $wpdb;
$table_progress = $wpdb->prefix . 'dogology_progress';

$courses = get_posts([
    'post_type'      => 'dogology_course',
    'post_status'    => 'publish',
    'posts_per_page' => 500,
    'orderby'        => 'title',
    'order'          => 'ASC',
]);

$lesson_counts_by_course = [];
$enrolled_by_course      = [];
$progress_by_course      = [];

if ($courses) {
    $lesson_rows = $wpdb->get_results("
        SELECT pm.meta_value AS course_id, COUNT(*) AS cnt
        FROM {$wpdb->postmeta} pm
        JOIN {$wpdb->posts} p ON p.ID = pm.post_id
        WHERE pm.meta_key = '_dogology_parent_course'
          AND p.post_type = 'dogology_lesson'
          AND p.post_status = 'publish'
        GROUP BY pm.meta_value
    ");
    foreach ($lesson_rows as $row) {
        $lesson_counts_by_course[(int) $row->course_id] = (int) $row->cnt;
    }

    // One row per student+course: how many lessons they have marked complete.
    $progress_rows = $wpdb->get_results("
        SELECT course_id, user_id, SUM(completed) AS done
        FROM $table_progress
        GROUP BY course_id, user_id
    ");
    foreach ($progress_rows as $row) {
        $cid = (int) $row->course_id;
        $enrolled_by_course[$cid] = ($enrolled_by_course[$cid] ?? 0) + 1;
        $progress_by_course[$cid][] = (int) $row->done;
    }
}

$course_stats     = [];
$total_finishers  = 0;
foreach ($courses as $course) {
    $cid           = $course->ID;
    $total_lessons = $lesson_counts_by_course[$cid] ?? 0;
    $enrolled      = $enrolled_by_course[$cid] ?? 0;
    $finished      = 0;
    $avg_pct       = 0;

    if ($total_lessons > 0 && $enrolled > 0) {
        $sum_pct = 0;
        foreach ($progress_by_course[$cid] as $done) {
            $sum_pct += min(100, ($done / $total_lessons) * 100);
            if ($done >= $total_lessons) {
                $finished++;
            }
        }
        $avg_pct = round($sum_pct / $enrolled, 1);
    }

    $total_finishers += $finished;

    $course_stats[] = [
        'name'          => $course->post_title,
        'id'            => $cid,
        'total_lessons' => $total_lessons,
        'enrolled'      => $enrolled,
        'finished'      => $finished,
        'avg_pct'       => $avg_pct,
    ];
}

?>

<div class="wrap dogology-learning-wrap">
    <h1>Dogology Learning Dashboard</h1>
    
    <div style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 20px; margin-top: 20px; margin-bottom: 20px;">
        <!-- Card 1 -->
        <div class="dl-card" style="padding: 24px; text-align: center;">
            <div style="font-size: 32px; font-weight: bold; color: #00AB8E; margin-bottom: 5px;">
                <?php echo number_format($count_students); ?>
            </div>
            <div style="color: #666; font-size: 14px;">Total Students</div>
        </div>

        <!-- Card 2 -->
        <div class="dl-card" style="padding: 24px; text-align: center;">
            <div style="font-size: 32px; font-weight: bold; color: #3b82f6; margin-bottom: 5px;">
                <?php echo number_format($count_enrollments); ?>
            </div>
            <div style="color: #666; font-size: 14px;">Active Enrollments</div>
        </div>

        <!-- Card 3 -->
        <div class="dl-card" style="padding: 24px; text-align: center;">
            <div style="font-size: 32px; font-weight: bold; color: #f59e0b; margin-bottom: 5px;">
                <?php echo number_format($count_courses); ?>
            </div>
            <div style="color: #666; font-size: 14px;">Published Courses</div>
        </div>

        <!-- Card 4 -->
        <div class="dl-card" style="padding: 24px; text-align: center;">
            <div style="font-size: 32px; font-weight: bold; color: #6366f1; margin-bottom: 5px;">
                <?php echo number_format($count_lessons); ?>
            </div>
            <div style="color: #666; font-size: 14px;">Total Lessons</div>
        </div>

        <!-- Card 5 -->
        <div class="dl-card" style="padding: 24px; text-align: center;">
            <div style="font-size: 32px; font-weight: bold; color: #00AB8E; margin-bottom: 5px;">
                <?php echo number_format($total_finishers); ?>
            </div>
            <div style="color: #666; font-size: 14px;">Students Finished</div>
        </div>
    </div>

    <!-- Completion by Course -->
    <div class="dl-card" style="margin-bottom: 20px;">
        <div class="dl-card-header">
            <h3 class="dl-card-title">Completion by Course</h3>
        </div>
        <table class="dl-table">
            <thead>
                <tr>
                    <th>Course</th>
                    <th style="text-align:right;">Lessons</th>
                    <th style="text-align:right;">Enrolled</th>
                    <th style="text-align:right;">Finished</th>
                    <th style="text-align:right;">Finish Rate</th>
                    <th style="text-align:right;">Avg Progress</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($course_stats): ?>
                    <?php foreach ($course_stats as $stat): ?>
                    <tr>
                        <td><?php echo esc_html($stat['name']); ?></td>
                        <td style="text-align:right;"><?php echo number_format($stat['total_lessons']); ?></td>
                        <td style="text-align:right;"><?php echo number_format($stat['enrolled']); ?></td>
                        <td style="text-align:right; font-weight:bold;"><?php echo number_format($stat['finished']); ?></td>
                        <td style="text-align:right;">
                            <?php echo $stat['enrolled'] > 0
                                ? esc_html(round(($stat['finished'] / $stat['enrolled']) * 100, 1)) . '%'
                                : '—'; ?>
                        </td>
                        <td style="text-align:right;"><?php echo esc_html($stat['avg_pct']); ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6" style="text-align:center; color:#999;">No published courses yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <p style="padding: 0 16px 14px; margin: 0; color:#999; font-size:12px;">
            "Finished" = student has completed every published lesson in that course.
        </p>
    </div>

    <!-- Recent Activity Table -->
    <div class="dl-card">
        <div class="dl-card-header">
            <h3 class="dl-card-title">Recent Activity</h3>
        </div>
        <table class="dl-table">
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Action</th>
                    <th>Course</th>
                </tr>
            </thead>
            <tbody>
                <?php if($recent_enrollments): ?>
                    <?php foreach ($recent_enrollments as $log): ?>
                    <?php $course = get_post($log->course_id); ?>
                    <tr>
                        <td style="display:flex; align-items:center; gap:10px;">
                            <div style="width:30px; height:30px; background:#eee; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:bold; color:#666;">
                                <?php echo strtoupper(substr($log->display_name ?: $log->email, 0, 1)); ?>
                            </div>
                            <div>
                                <div><?php echo esc_html($log->display_name ?: 'Unknown'); ?></div>
                                <div style="font-size:12px; color:#999;"><?php echo esc_html($log->email); ?></div>
                            </div>
                        </td>
                        <td>
                            <span class="dl-badge dl-badge-success">Enrolled</span>
                        </td>
                        <td>
                            <?php echo $course ? esc_html($course->post_title) : '(Deleted Course)'; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="3" style="text-align:center; color:#999;">No recent activity found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
