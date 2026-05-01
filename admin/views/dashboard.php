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

?>

<div class="wrap dogology-learning-wrap">
    <h1>Dogology Learning Dashboard</h1>
    
    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-top: 20px; margin-bottom: 20px;">
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
