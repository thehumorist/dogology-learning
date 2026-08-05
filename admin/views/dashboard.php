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

/**
 * Two completion measures, because they answer different questions and a
 * curriculum change pulls them apart:
 *
 *   finished — completed every lesson that was PUBLISHED AT THE TIME of their
 *     last completion. Sticky: publishing a new lesson does not retroactively
 *     un-finish someone who finished the course as it stood for them.
 *   up_to_date — completed every lesson published right now. Drops when the
 *     curriculum grows; this is the "who should I nudge about the new lesson"
 *     number, and it is the one that recovers as people come back.
 *
 * Reference date is the student's last COMPLETION, not last touch: merely
 * opening the course does not write to wp_dogology_progress, and update_at is
 * ON UPDATE CURRENT_TIMESTAMP, so only real completions move it.
 */
$lesson_dates_by_course = [];   // course_id => sorted list of lesson publish dates
$enrolled_by_course     = [];
$progress_by_course     = [];   // course_id => [ ['done'=>int, 'last'=>string|null], ... ]

if ($courses) {
    $lesson_rows = $wpdb->get_results("
        SELECT pm.meta_value AS course_id, p.post_date
        FROM {$wpdb->postmeta} pm
        JOIN {$wpdb->posts} p ON p.ID = pm.post_id
        WHERE pm.meta_key = '_dogology_parent_course'
          AND p.post_type = 'dogology_lesson'
          AND p.post_status = 'publish'
        ORDER BY p.post_date ASC
    ");
    foreach ($lesson_rows as $row) {
        $lesson_dates_by_course[(int) $row->course_id][] = $row->post_date;
    }

    // One row per student+course: lessons completed, and when they last
    // completed one. MAX() is filtered to completed rows so the lesson_id = 0
    // enrolment marker (completed = 0) never sets the reference date.
    $progress_rows = $wpdb->get_results("
        SELECT course_id,
               user_id,
               SUM(completed) AS done,
               MAX(CASE WHEN completed = 1 THEN updated_at END) AS last_completion
        FROM $table_progress
        GROUP BY course_id, user_id
    ");
    foreach ($progress_rows as $row) {
        $cid = (int) $row->course_id;
        $enrolled_by_course[$cid] = ($enrolled_by_course[$cid] ?? 0) + 1;
        $progress_by_course[$cid][] = [
            'done' => (int) $row->done,
            'last' => $row->last_completion,
        ];
    }
}

$course_stats      = [];
$total_finishers   = 0;
$total_up_to_date  = 0;
foreach ($courses as $course) {
    $cid           = $course->ID;
    $lesson_dates  = $lesson_dates_by_course[$cid] ?? [];
    $total_lessons = count($lesson_dates);
    $enrolled      = $enrolled_by_course[$cid] ?? 0;
    $finished      = 0;
    $up_to_date    = 0;
    $avg_pct       = 0;
    $buckets       = [
        '0'      => 0,
        '1-25'   => 0,
        '26-50'  => 0,
        '51-75'  => 0,
        '76-99'  => 0,
        '100'    => 0,
    ];

    if ($total_lessons > 0 && $enrolled > 0) {
        $sum_pct = 0;
        foreach ($progress_by_course[$cid] as $row) {
            $done     = $row['done'];
            $pct      = min(100, ($done / $total_lessons) * 100);
            $sum_pct += $pct;

            // Distribution bucket. "Not started" is kept separate from
            // "barely started" on purpose — the two call for different
            // interventions, and lumping them hides how big the never-opened
            // group is.
            if ($done <= 0) {
                $buckets['0']++;
            } elseif ($pct >= 100) {
                $buckets['100']++;
            } elseif ($pct <= 25) {
                $buckets['1-25']++;
            } elseif ($pct <= 50) {
                $buckets['26-50']++;
            } elseif ($pct <= 75) {
                $buckets['51-75']++;
            } else {
                $buckets['76-99']++;
            }

            if ($done >= $total_lessons) {
                $up_to_date++;
                $finished++;
                continue;
            }

            // How many lessons existed when they last completed one?
            if ($done > 0 && $row['last']) {
                $available = 0;
                foreach ($lesson_dates as $date) {
                    if ($date > $row['last']) {
                        break;  // sorted ascending — everything after is newer
                    }
                    $available++;
                }
                if ($available > 0 && $done >= $available) {
                    $finished++;
                }
            }
        }
        $avg_pct = round($sum_pct / $enrolled, 1);
    }

    $total_finishers  += $finished;
    $total_up_to_date += $up_to_date;

    $course_stats[] = [
        'name'          => $course->post_title,
        'id'            => $cid,
        'total_lessons' => $total_lessons,
        'enrolled'      => $enrolled,
        'finished'      => $finished,
        'up_to_date'    => $up_to_date,
        'avg_pct'       => $avg_pct,
        'buckets'       => $buckets,
    ];
}

/**
 * Per-lesson completion, in curriculum order, for every course that has both
 * lessons and students.
 *
 * READ AS DROP-OFF, NOT POPULARITY. In a linear course the raw count only
 * measures how far people got, so lesson 1 always "wins" and the last lesson
 * always looks worst. The actionable signal is `drop` — the fall from the
 * previous lesson. A big drop marks where students quit; a near-zero drop late
 * in the course marks a lesson that holds attention. A NEGATIVE drop means more
 * students completed this lesson than the one before it — they skipped ahead to
 * reach it, which is its own signal of pull.
 */
$lesson_breakdown = [];
if (class_exists('Dogology_Learning_Builder')) {
    foreach ($course_stats as $stat) {
        if ($stat['total_lessons'] < 1 || $stat['enrolled'] < 1) {
            continue;
        }

        $counts = [];
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT lesson_id, COUNT(DISTINCT user_id) AS done
             FROM $table_progress
             WHERE course_id = %d AND lesson_id > 0 AND completed = 1
             GROUP BY lesson_id",
            $stat['id']
        ));
        foreach ($rows as $row) {
            $counts[(int) $row->lesson_id] = (int) $row->done;
        }

        $items = [];
        $prev  = null;
        $pos   = 0;
        foreach (Dogology_Learning_Builder::build_tree($stat['id']) as $node) {
            foreach ($node['lessons'] as $lesson) {
                $done = $counts[(int) $lesson->ID] ?? 0;
                $items[] = [
                    'pos'    => ++$pos,
                    'module' => $node['module']->post_title,
                    'title'  => $lesson->post_title,
                    'done'   => $done,
                    'pct'    => $stat['enrolled'] > 0 ? ($done / $stat['enrolled']) * 100 : 0,
                    'drop'   => $prev === null ? null : $prev - $done,
                ];
                $prev = $done;
            }
        }

        if ($items) {
            // Biggest single drop, so it can be called out rather than hunted for.
            $worst = null;
            foreach ($items as $item) {
                if ($item['drop'] !== null && ($worst === null || $item['drop'] > $worst['drop'])) {
                    $worst = $item;
                }
            }
            $lesson_breakdown[] = [
                'course'   => $stat['name'],
                'enrolled' => $stat['enrolled'],
                'items'    => $items,
                'worst'    => $worst,
            ];
        }
    }
}

// Bucket display config, in order. Colour runs cold (dropped off) to warm
// (nearly there) to teal (done), matching the palette already on this screen.
$bucket_labels = [
    '0'     => ['label' => 'Not started', 'color' => '#cbd5e1'],
    '1-25'  => ['label' => '1–25%',       'color' => '#fca5a5'],
    '26-50' => ['label' => '26–50%',      'color' => '#fcd34d'],
    '51-75' => ['label' => '51–75%',      'color' => '#93c5fd'],
    '76-99' => ['label' => '76–99%',      'color' => '#60a5fa'],
    '100'   => ['label' => 'Complete',    'color' => '#00AB8E'],
];

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
                    <th style="text-align:right;">Up to Date</th>
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
                        <td style="text-align:right;<?php echo $stat['up_to_date'] < $stat['finished'] ? ' color:#f59e0b;' : ''; ?>">
                            <?php echo number_format($stat['up_to_date']); ?>
                            <?php if ($stat['up_to_date'] < $stat['finished']): ?>
                                <span title="<?php echo esc_attr(($stat['finished'] - $stat['up_to_date']) . ' finishers have not done the lesson(s) added since'); ?>">&#9888;</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;"><?php echo esc_html($stat['avg_pct']); ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7" style="text-align:center; color:#999;">No published courses yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <p style="padding: 0 16px 14px; margin: 0; color:#999; font-size:12px;">
            <strong>Finished</strong> = completed every lesson that existed when they last studied,
            so adding a lesson never un-finishes anyone.
            <strong>Up to Date</strong> = completed every lesson published right now — the gap between
            the two columns is who to nudge about newly added lessons.
        </p>
    </div>

    <!-- Progress Distribution -->
    <div class="dl-card" style="margin-bottom: 20px;">
        <div class="dl-card-header">
            <h3 class="dl-card-title">Progress Distribution</h3>
        </div>
        <div style="padding: 8px 16px 16px;">
            <?php
            $charted = array_filter($course_stats, function ($stat) {
                return $stat['total_lessons'] > 0 && $stat['enrolled'] > 0;
            });
            ?>
            <?php if ($charted): ?>
                <?php foreach ($charted as $stat): ?>
                    <?php $max = max($stat['buckets']) ?: 1; ?>
                    <div style="margin-bottom: 26px;">
                        <div style="font-weight: 600; margin-bottom: 10px;">
                            <?php echo esc_html($stat['name']); ?>
                            <span style="font-weight: normal; color: #999;">
                                — <?php echo number_format($stat['enrolled']); ?> students,
                                <?php echo number_format($stat['total_lessons']); ?> lessons
                            </span>
                        </div>
                        <?php foreach ($bucket_labels as $key => $meta): ?>
                            <?php
                            $count = $stat['buckets'][$key];
                            $share = $stat['enrolled'] > 0 ? ($count / $stat['enrolled']) * 100 : 0;
                            // Bars are scaled to the biggest bucket so small
                            // buckets stay visible; the % label is the true
                            // share of students, not the bar width.
                            $width = ($count / $max) * 100;
                            ?>
                            <div style="display:flex; align-items:center; gap:10px; margin-bottom:4px;">
                                <div style="width:90px; font-size:12px; color:#666; text-align:right; flex:none;">
                                    <?php echo esc_html($meta['label']); ?>
                                </div>
                                <div style="flex:1; background:#f1f5f9; border-radius:3px; height:20px; position:relative;">
                                    <div style="width:<?php echo esc_attr(round($width, 2)); ?>%; background:<?php echo esc_attr($meta['color']); ?>; height:100%; border-radius:3px; min-width:<?php echo $count > 0 ? '3px' : '0'; ?>;"></div>
                                </div>
                                <div style="width:110px; font-size:12px; color:#666; flex:none;">
                                    <strong><?php echo number_format($count); ?></strong>
                                    <span style="color:#999;">(<?php echo esc_html(round($share, 1)); ?>%)</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
                <p style="margin:0; color:#999; font-size:12px;">
                    Share of enrolled students by how much of the current curriculum they have completed.
                    Bar length is scaled to the largest bucket; the percentage is the true share.
                </p>
            <?php else: ?>
                <p style="color:#999; margin:0;">No course has published lessons and enrolled students yet.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Lesson-by-Lesson Drop-off -->
    <?php foreach ($lesson_breakdown as $course_block): ?>
    <div class="dl-card" style="margin-bottom: 20px;">
        <div class="dl-card-header">
            <h3 class="dl-card-title">
                Lesson-by-Lesson — <?php echo esc_html($course_block['course']); ?>
            </h3>
        </div>
        <?php if ($course_block['worst'] && $course_block['worst']['drop'] > 0): ?>
        <p style="margin:0; padding:12px 16px; background:#fffbeb; border-bottom:1px solid #fde68a; font-size:13px;">
            Biggest drop-off: <strong><?php echo esc_html($course_block['worst']['title']); ?></strong>
            (lesson <?php echo (int) $course_block['worst']['pos']; ?>) —
            <strong><?php echo number_format($course_block['worst']['drop']); ?></strong>
            students stopped here rather than continuing from the previous lesson.
        </p>
        <?php endif; ?>
        <table class="dl-table">
            <thead>
                <tr>
                    <th style="width:34px;">#</th>
                    <th>Lesson</th>
                    <th>Module</th>
                    <th style="width:220px;">Completed</th>
                    <th style="text-align:right;">Students</th>
                    <th style="text-align:right;">Drop</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($course_block['items'] as $item): ?>
                <tr>
                    <td style="color:#999;"><?php echo (int) $item['pos']; ?></td>
                    <td><?php echo esc_html($item['title']); ?></td>
                    <td style="color:#999; font-size:12px;"><?php echo esc_html($item['module']); ?></td>
                    <td>
                        <div style="background:#f1f5f9; border-radius:3px; height:16px;">
                            <div style="width:<?php echo esc_attr(round($item['pct'], 2)); ?>%; background:#00AB8E; height:100%; border-radius:3px;"></div>
                        </div>
                    </td>
                    <td style="text-align:right;">
                        <strong><?php echo number_format($item['done']); ?></strong>
                        <span style="color:#999;">(<?php echo esc_html(round($item['pct'], 1)); ?>%)</span>
                    </td>
                    <td style="text-align:right;">
                        <?php if ($item['drop'] === null): ?>
                            <span style="color:#ccc;">—</span>
                        <?php elseif ($item['drop'] > 0): ?>
                            <span style="color:<?php echo $item['drop'] >= 10 ? '#dc2626' : '#f59e0b'; ?>;">
                                &#8595;<?php echo number_format($item['drop']); ?>
                            </span>
                        <?php elseif ($item['drop'] < 0): ?>
                            <span style="color:#00AB8E;" title="More students completed this than the previous lesson — they skipped ahead to it">
                                &#8593;<?php echo number_format(abs($item['drop'])); ?>
                            </span>
                        <?php else: ?>
                            <span style="color:#999;">0</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p style="padding: 10px 16px 14px; margin: 0; color:#999; font-size:12px;">
            Read the <strong>Drop</strong> column, not the raw counts. In a linear course the count only
            measures how far people got, so lesson 1 always leads. A large drop marks where students quit;
            a small drop late in the course marks a lesson that holds attention. An
            <span style="color:#00AB8E;">&#8593;up arrow</span> means more students completed that lesson
            than the one before it — they skipped ahead to reach it.
        </p>
    </div>
    <?php endforeach; ?>

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
