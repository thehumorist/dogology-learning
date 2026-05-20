<?php
/**
 * Lesson Player Template
 */

if (!defined('ABSPATH')) {
    exit;
}

// 1. Authentication Check
$current_student = Dogology_Auth::get_current_student();
if (!$current_student) {
    wp_redirect(home_url('/student-login'));
    exit;
}

// 2. Get Route Params
// Cast explicitly — get_query_var returns whatever matched the rewrite regex, and
// downstream consumers (get_post, get_post_meta, home_url string interpolation)
// all expect integers. The router regex already constrains these to [0-9]+, but
// casting here makes the contract obvious at the top of the file.
$course_id = (int) get_query_var('course_id');
$lesson_id = (int) get_query_var('lesson_id');

if (!$course_id) {
    wp_redirect(home_url('/my-courses'));
    exit;
}

// 3. Enrollment Check
$student_db = new Dogology_Student_DB();
// Check if user is enrolled in this course (Simple check for now: exists in progress table for THIS course)
// Note: enroll_student usually handles this, but here we just check if they HAVE access.
$enrolled_courses = $student_db->get_student_courses($current_student->id);
$is_enrolled = false;
foreach ($enrolled_courses as $c) {
    $cid = isset($c->ID) ? $c->ID : (isset($c->course_id) ? $c->course_id : 0);
    if ($cid == $course_id) {
        $is_enrolled = true;
        break;
    }
}

if (!$is_enrolled) {
    wp_die('You are not enrolled in this course.', 'Access Denied', array('response' => 403));
}

// 4. Fetch Data & Sort Lessons Globally
$course = get_post($course_id);
if (!$course || $course->post_type !== 'dogology_course') {
    wp_die('Invalid Course.');
}

// Get All Lessons (Raw sort by menu_order)
$raw_lessons = $student_db->get_course_lessons($course_id);

// Prime caches for all lesson IDs: one meta_query satisfies every subsequent
// get_post_meta() in this request (_dogology_parent_module, _dogology_duration,
// _dogology_video_url), and one progress query replaces the per-lesson
// get_lesson_progress() calls in the sidebar loops. Turns ~3N DB queries into 2
// on courses with N lessons.
$lesson_ids = array_map(function ($l) { return (int) $l->ID; }, $raw_lessons);
if (!empty($lesson_ids)) {
    update_meta_cache('post', $lesson_ids);
}
$progress_map = $student_db->get_progress_for_course($current_student->id, $course_id);
$is_lesson_complete = function ($id) use ($progress_map) {
    return isset($progress_map[(int) $id]) && (int) $progress_map[(int) $id]->completed === 1;
};

// Get Modules (Sorted by menu_order)
$course_modules = get_posts(array(
    'post_type' => 'dogology_module',
    'meta_key' => '_dogology_parent_course',
    'meta_value' => $course_id,
    'numberposts' => -1,
    'orderby' => 'menu_order',
    'order' => 'ASC'
));

// Explicit Sort for Modules: Menu Order > Title > ID
// This handles cases where menu_order is 0 for all, ensuring "Module 1" comes before "Module 2"
usort($course_modules, function ($a, $b) {
    if ($a->menu_order == $b->menu_order) {
        return strnatcasecmp($a->post_title, $b->post_title);
    }
    return $a->menu_order - $b->menu_order;
});

// Reorganize Lessons: Group by Module -> Flatten into Global Order
$lessons_by_module = array();
$no_module_lessons = array();

// Bucket lessons
foreach ($raw_lessons as $l) {
    $m_id = get_post_meta($l->ID, '_dogology_parent_module', true);
    if ($m_id) {
        $lessons_by_module[$m_id][] = $l;
    } else {
        $no_module_lessons[] = $l;
    }
}

// Build Logic-Sorted Arrays
$sorted_lessons = array(); // Flat list for Next/Prev
$grouped_lessons = array(); // For Sidebar

foreach ($course_modules as $mod) {
    if (isset($lessons_by_module[$mod->ID])) {
        // Since $raw_lessons was sorted by menu_order... but let's be safe and explicitly sort
        $mod_lessons = $lessons_by_module[$mod->ID];

        // EXPLICT SORT: Ensure lessons within this module are sorted by menu_order
        usort($mod_lessons, function ($a, $b) {
            if ($a->menu_order == $b->menu_order) {
                return strnatcasecmp($a->post_title, $b->post_title); // Fallback to Title
            }
            return $a->menu_order - $b->menu_order;
        });

        $grouped_lessons[$mod->ID] = $mod_lessons;

        foreach ($mod_lessons as $l) {
            $sorted_lessons[] = $l;
        }
    }
}

// Append Orphans at the end
foreach ($no_module_lessons as $l) {
    $sorted_lessons[] = $l;
}

// Assign globally sorted lessons to main variable for downstream logic
$lessons = $sorted_lessons;

// 5. Smart Redirect (If no lesson_id provided)
if (!$lesson_id) {
    $target_lesson = null;

    // Find first uncompleted (progress_map is already loaded above)
    foreach ($sorted_lessons as $l) {
        if (!$is_lesson_complete($l->ID)) {
            $target_lesson = $l;
            break;
        }
    }

    // Fallback: If all completed, start from beginning
    if (!$target_lesson && !empty($sorted_lessons)) {
        $target_lesson = $sorted_lessons[0];
    }

    if ($target_lesson) {
        wp_redirect(home_url("/learn/{$course_id}/{$target_lesson->ID}"));
        exit;
    } else {
        wp_die('No lessons found in this course.');
    }
}

// 6. Current Lesson Validation
$lesson = get_post($lesson_id);
if (!$lesson || $lesson->post_type !== 'dogology_lesson') {
    wp_die('Invalid Lesson.');
}

// Ownership: the lesson MUST belong to the course in the URL. Without this, an
// enrolled student in course A could hit /learn/{A}/{lessonFromB} and render
// course B's video + attachments + description. The sidebar would be empty but
// the content leaks. Read-side twin of the H7 write-side fix in dashboard.php.
if ((int) get_post_meta($lesson_id, '_dogology_parent_course', true) !== $course_id) {
    wp_die('This lesson does not belong to this course.', 'Access Denied', array('response' => 403));
}

$video_url = get_post_meta($lesson_id, '_dogology_video_url', true);
$subtitle = get_post_meta($lesson_id, '_dogology_subtitle', true);
$duration = get_post_meta($lesson_id, '_dogology_duration', true);
$attachment_url = get_post_meta($lesson_id, '_dogology_attachment_url', true);
$attachment_title = get_post_meta($lesson_id, '_dogology_attachment_title', true);
$attachment_subtitle = get_post_meta($lesson_id, '_dogology_attachment_subtitle', true);
$attachment_cta = get_post_meta($lesson_id, '_dogology_attachment_cta', true);
$is_completed = $student_db->get_lesson_progress($current_student->id, $lesson_id);

// Get lesson duration (if stored)
$video_duration = get_post_meta($lesson_id, '_dogology_duration', true);

// Calculate Next Lesson Logic
$next_lesson_url = null;
$is_last_lesson = false;
$found_current = false;
foreach ($lessons as $l) {
    if ($found_current) {
        $next_lesson_url = home_url("/learn/{$course_id}/{$l->ID}");
        break;
    }
    if ($l->ID == $lesson_id) {
        $found_current = true;
    }
}
if ($found_current && !$next_lesson_url) {
    $is_last_lesson = true;
}

// UI Helpers
$ui_logo_url = get_option('dl_logo_url', '');

// Calculate Progress Percent for the Sidebar — uses the primed $progress_map /
// $is_lesson_complete closure from earlier. 1.1.67 missed this loop and still
// hit the DB per-lesson, defeating its own batching fix. Fixed in 1.1.68.
$total_lessons = count($lessons);
$completed_lessons_count = 0;
foreach ($lessons as $l) {
    if ($is_lesson_complete($l->ID)) {
        $completed_lessons_count++;
    }
}
$progress_percent = $total_lessons > 0 ? round(($completed_lessons_count / $total_lessons) * 100) : 0;

// Language Toggle handling (DB > cookie > browser > default)
$current_lang = !empty($current_student->language) ? $current_student->language : 'en';
if (empty($current_student->language) && isset($_COOKIE['dl_lang']) && in_array($_COOKIE['dl_lang'], ['th', 'en'])) {
    $current_lang = $_COOKIE['dl_lang'];
}
if (empty($current_student->language) && !isset($_COOKIE['dl_lang'])) {
    $browser_lang = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2) : 'en';
    $current_lang = ($browser_lang === 'th') ? 'th' : 'en';
}
if (isset($_GET['lang']) && in_array($_GET['lang'], ['th', 'en'])) {
    $current_lang = $_GET['lang'];
    setcookie('dl_lang', $current_lang, [
        'expires' => time() + 3600 * 24 * 30,
        'path' => '/',
        'secure' => is_ssl(),
        'httponly' => true, // Only PHP reads this cookie (verified via grep); JS never touches document.cookie for dl_lang
        'samesite' => 'Lax',
    ]);
    $student_db->update_student($current_student->id, array('language' => $current_lang));
}

// Prev/Next Navigation
$prev_lesson = null;
$next_lesson = null;
for ($i = 0; $i < count($lessons); $i++) {
    if ($lessons[$i]->ID == $lesson_id) {
        $prev_lesson = ($i > 0) ? $lessons[$i - 1] : null;
        $next_lesson = ($i < count($lessons) - 1) ? $lessons[$i + 1] : null;
        break;
    }
}

// User Menu Helpers
$user_initial = strtoupper(substr($current_student->display_name ?: $current_student->email, 0, 1));
$user_name = $current_student->display_name ?: 'Student';
$user_email = $current_student->email;
$has_passkey = !empty($current_student->passkey_id);

// Translations for Player Menu
$trans = [
    'th' => [
        'menu_dashboard' => 'กลับสู่แดชบอร์ด',
        'menu_logout' => 'ออกจากระบบ',
        'course_content' => 'เนื้อหาคอร์ส',
        'video_lesson' => 'บทเรียนวิดีโอ',
        'reading_lesson' => 'บทเรียนเนื้อหา',
        'next_lesson' => 'บทเรียนถัดไป',
        'back_to_course' => 'กลับสู่คอร์สเรียน',
        'watch_again' => 'ดูซ้ำอีกรอบ',
        'paused' => 'หยุดชั่วคราว',
        'continue_learning' => 'เรียนต่อไหม?',
        'press_play' => 'กดปุ่ม Play เพื่อเรียนต่อ',
        'play_continue' => 'เล่นต่อ',
        'video_coming_soon' => 'วิดีโอกำลังมาเร็วๆ นี้...',
        'mark_complete' => 'มาร์คว่าเรียนแล้ว',
        'completed' => 'เรียนจบแล้ว ✓',
        'processing' => 'กำลังประมวลผล...',
        'download_materials' => 'ดาวน์โหลดเอกสาร',
        'previous' => 'ก่อนหน้า',
        'next' => 'ถัดไป',
        'show_menu' => 'แสดงเมนู',
        'hide_sidebar' => 'ซ่อนเมนู',
        'back_to_main_site' => 'กลับสู่เว็บไซต์หลัก',
        'already_watched' => 'เรียนแล้ว',
        'start_learning' => 'เริ่มเรียน',
        'pause_title' => 'พักเบรกสักครู่... 🐾',
        'pause_desc' => 'คุณกำลังเรียนในหลักสูตร',
        'pause_ready' => 'พร้อมแล้วกดปุ่มด้านล่างเพื่อเรียนต่อได้เลย',
        'resume' => 'เรียนต่อเลย',
        'end_title' => 'จบแล้ว! เก่งมาก',
        'end_desc' => 'ทำเครื่องหมายว่าเรียนจบแล้วไปบทถัดไปได้เลย',
        'js_completed' => 'เรียนจบแล้ว ✓',
        'js_mark_complete' => 'ทำเครื่องหมายว่าเรียนจบ',
        'currently_studying' => 'กำลังเรียน',
        'other_lessons' => 'บทเรียนอื่นๆ',
        'click_to_open' => 'คลิกเพื่อเปิด'
    ],
    'en' => [
        'menu_dashboard' => 'Back to Dashboard',
        'menu_logout' => 'Logout',
        'course_content' => 'Course Content',
        'video_lesson' => 'Video Lesson',
        'reading_lesson' => 'Reading lesson',
        'next_lesson' => 'Next Lesson',
        'back_to_course' => 'Back to Course',
        'watch_again' => 'Watch Again',
        'paused' => 'Paused',
        'continue_learning' => 'Continue Learning?',
        'press_play' => 'Press Play to continue',
        'play_continue' => 'Continue',
        'video_coming_soon' => 'Video coming soon...',
        'mark_complete' => 'Mark as Complete',
        'completed' => 'Completed ✓',
        'processing' => 'Processing...',
        'download_materials' => 'Download Materials',
        'previous' => 'Previous',
        'next' => 'Next',
        'show_menu' => 'Show Menu',
        'hide_sidebar' => 'Hide Sidebar',
        'back_to_main_site' => 'Back to Main Site',
        'already_watched' => 'Watched',
        'start_learning' => 'Start Learning',
        'pause_title' => 'Taking a break... 🐾',
        'pause_desc' => 'You are studying',
        'pause_ready' => 'Press the button below to continue',
        'resume' => 'Continue',
        'end_title' => 'Well done!',
        'end_desc' => 'Mark as complete and move on to the next lesson',
        'js_completed' => 'Completed ✓',
        'js_mark_complete' => 'Mark as Complete',
        'currently_studying' => 'Now Playing',
        'other_lessons' => 'Other Lessons',
        'click_to_open' => 'Click to open'
    ]
];
$t = $trans[$current_lang];

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <link rel="preconnect" href="https://www.youtube.com" crossorigin>
    <link rel="preconnect" href="https://www.google.com" crossorigin>
    <title><?php echo esc_html($lesson->post_title); ?> - <?php bloginfo('name'); ?></title>
    <script data-no-optimize="1" src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#00AB8E',
                        secondary: '#0076BA',
                    },
                    fontFamily: {
                        sans: ['Kanit', 'sans-serif'],
                    }
                }
            }
        }
    </script>
    <script>
        window.dogologyPendingPlay = false;
        window.dogologyPlayer = null;
        window.ytApiReady = false;
        // Distinct from ytApiReady: true only once YT.Player has actually fired
        // onReady, i.e. the postMessage channel is alive. ytApiReady alone means
        // the iframe_api script ran; it doesn't mean the player is reachable.
        window.dogologyApiReady = false;
        window.dogologyAjaxUrl = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
        window.dogologyDiagReported = {};

        // Diagnostic beacon: fires at most once per event name so a stuck player
        // can't spam the endpoint. sendBeacon survives page unload; keepalive
        // fetch is the fallback for browsers without sendBeacon. Errors are
        // swallowed because diagnostics must never be the thing that breaks
        // playback.
        window.dogologyReportDiag = function (evt, detail) {
            if (!evt || window.dogologyDiagReported[evt]) return;
            window.dogologyDiagReported[evt] = true;
            try {
                var fd = new FormData();
                fd.append('action', 'dl_video_diag');
                fd.append('evt', evt);
                fd.append('detail', detail || '');
                fd.append('ua', navigator.userAgent || '');
                if (navigator.sendBeacon) {
                    navigator.sendBeacon(window.dogologyAjaxUrl, fd);
                } else {
                    fetch(window.dogologyAjaxUrl, { method: 'POST', body: fd, keepalive: true });
                }
            } catch (err) { /* never break playback for a diagnostic */ }
        };

        // Early stub: catches the YouTube API callback even if the main script hasn't loaded yet
        window.onYouTubeIframeAPIReady = function () {
            window.ytApiReady = true;
            // If the real initializer is already defined (rare, but handle it), call it
            if (typeof window.initYouTubePlayer === 'function') {
                window.initYouTubePlayer();
            }
        };

        window.handlePosterClick = function (e) {
            if (e) e.stopPropagation();
            var poster = document.getElementById('video-poster');
            if (poster) {
                poster.style.opacity = '0';
                setTimeout(function () { poster.style.display = 'none'; }, 500);
            }
            if (window.dogologyPlayer && typeof window.dogologyPlayer.playVideo === 'function') {
                window.dogologyPlayer.playVideo();
            } else {
                window.dogologyPendingPlay = true;
            }
        };
    </script>
    <link
        href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <style data-no-minify="1" data-no-optimize="1">
        html {
            margin-top: 0 !important;
        }

        #player-content-wrapper.bg-white {
            padding: 0 !important;
        }

        body {
            margin: 0;
            font-family: 'Noto Sans Thai', 'Kanit', sans-serif;
            /* Prevent pull-to-refresh on mobile to avoid accidental page reloads */
            overscroll-behavior-y: none;
        }

        @media (prefers-reduced-motion: reduce) {

            *,
            *::before,
            *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }

        /* Critical CSS (Handles Layout before Tailwind loads) */
        .critical-logo {
            height: 32px !important;
            width: auto !important;
            max-width: 150px !important;
            object-fit: contain;
        }

        /* Fullscreen Fallback */
        body.is-fullscreen-fallback #video-wrapper.is-fullscreen {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            width: 100vw !important;
            height: 100vh !important;
            height: 100dvh !important;
            z-index: 99999 !important;
            background: #000 !important;
        }

        body.is-fullscreen-fallback #video-wrapper.is-fullscreen iframe {
            height: 100vh !important;
            height: 100dvh !important;
        }

        #video-wrapper {
            position: relative;
            z-index: 1;
            background-color: #000;
            width: 100%;
            aspect-ratio: 16/9;
            overflow: hidden;
        }

        /* Aspect-ratio fallback for Samsung Internet <14 and older Android WebViews
           that don't support `aspect-ratio`. Without this the wrapper renders 0px
           tall and the iframe is invisible. Children are already absolutely
           positioned, so the padding-bottom trick works cleanly. */
        @supports not (aspect-ratio: 16/9) {
            #video-wrapper {
                height: 0;
                padding-bottom: 56.25%;
            }
        }

        #player-container,
        #video-poster,
        #video-overlay,
        #video-top-bar,
        #video-pause-overlay,
        #video-end-overlay {
            position: absolute !important;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
        }

        #video-poster {
            z-index: 30;
        }

        #video-click-layer {
            z-index: 20;
        }

        #video-dim-layer {
            z-index: 10;
        }

        #video-top-bar {
            z-index: 40;
        }

        #custom-controls {
            z-index: 50;
        }

        #video-pause-overlay {
            z-index: 55;
        }

        #video-mini-overlay {
            z-index: 55;
        }

        #video-end-overlay {
            z-index: 60;
        }

        #timeline-container {
            z-index: 55;
            /* Higher than controls */
        }

        #video-player {
            width: 100%;
            height: 100%;
        }

        /* Ensure poster covers iframe immediately */
        #video-poster {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .prose ul {
            list-style-type: disc;
            margin-left: 1.5rem;
        }

        .prose ol {
            list-style-type: decimal;
            margin-left: 1.5rem;
        }

        .sidebar-scrollbar::-webkit-scrollbar {
            width: 4px;
        }

        .sidebar-scrollbar::-webkit-scrollbar-track {
            background: transparent;
        }

        .sidebar-scrollbar::-webkit-scrollbar-thumb {
            background: #e2e8f0;
            border-radius: 10px;
        }

        /* Video Overlay Styles */
        #video-overlay {
            pointer-events: auto !important;
            transition: all 0.3s ease !important;
        }

        #video-wrapper.is-playing #video-overlay {
            opacity: 0 !important;
            background: rgba(0, 0, 0, 0) !important;
            pointer-events: none !important;
            /* Let clicks through during playback */
        }

        #video-wrapper.is-playing.user-interacting #video-overlay {
            opacity: 1 !important;
            background: linear-gradient(to bottom, transparent 60%, rgba(0, 0, 0, 0.6)) !important;
            pointer-events: auto !important;
        }

        #video-wrapper.is-paused #video-overlay,
        #video-wrapper.is-ended #video-overlay {
            opacity: 1 !important;
            background: rgba(0, 0, 0, 0.4) !important;
            /* Subtly dim to show the paused frame */
            backdrop-filter: none !important;
            /* Removed blur for a cleaner view of the frame */
            pointer-events: auto !important;
        }

        /* Control Bar Styles */
        #custom-controls {
            opacity: 0 !important;
            transition: opacity 0.3s ease !important;
            pointer-events: none !important;
        }

        #video-wrapper.user-interacting #custom-controls,
        #video-wrapper.is-paused #custom-controls {
            opacity: 1 !important;
            pointer-events: auto !important;
        }

        #video-wrapper.hide-cursor {
            cursor: none !important;
        }

        /* Removed hover rules that block auto-hiding while mouse is stationary */

        /* Poster Overlay */
        #video-poster {
            background-color: #00AB8E;
            /* Subtle Brand CI */
            z-index: 30;
            transition: opacity 0.5s ease;
        }

        /* Top Bar Overlay: Now independent, hides with its own timer.
           Background gradient is declared inline (not just via Tailwind
           `bg-gradient-to-b from-black via-black/80 to-transparent` on the
           element) so the mask paints on first frame even if Tailwind CDN
           hasn't generated utilities yet. Position comes from the shared
           absolute rule above. */
        #video-top-bar {
            opacity: 0 !important;
            transition: opacity 0.5s ease !important;
            pointer-events: none !important;
            z-index: 25;
            background: linear-gradient(to bottom, #000 0%, rgba(0, 0, 0, 0.8) 50%, transparent 100%);
        }

        #video-top-bar.show-bar {
            opacity: 1 !important;
        }

        /* YouTube Logo Shield: Bottom Full Width.
           Position/size are declared here (not just via Tailwind utility classes
           on the element) because YouTube's bottom-right watermark renders
           unconditionally now that modestbranding is deprecated, and the host
           theme no longer pre-ships Tailwind utilities — the CDN runtime can
           race the iframe paint on slow phones, leaving the mask without a
           rectangle. Inline declaration removes that dependency. */
        #video-logo-overlay {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 6rem;
            opacity: 0 !important;
            transition: opacity 0.5s ease !important;
            pointer-events: none !important;
            z-index: 15;
            /* Behind Custom Controls (20) but above Iframe */
            /* Solid bottom fade up */
            background: linear-gradient(to top, #0a0f14 0%, rgba(10, 15, 20, 0.95) 40%, transparent 100%);
        }

        /* Sync the bottom mask with YouTube's own watermark auto-hide pattern.
           YT's bottom-right watermark appears on play start and on any tap or
           hover, then auto-hides during steady idle playback. Our
           `user-interacting` class follows that exact rhythm — added on initial
           play and on every interaction by showControls() / resetHideTimeout(),
           removed by the 3s idle timer. Mirroring the mask to that class means
           the dark strip appears when YT chrome appears and gets out of the
           way when it doesn't. Pause/end states have their own full-screen
           overlays covering this area, so no need to gate on those. */
        #video-wrapper.user-interacting #video-logo-overlay {
            opacity: 1 !important;
        }

        /* Pause Overlay: Hides "More Videos" suggestions at the bottom */
        #video-pause-overlay {
            opacity: 0 !important;
            transition: opacity 0.5s ease !important;
            pointer-events: none !important;
            z-index: 15;
            /* Lighter at top, solid dark footer to hide suggestions shelf */
            background: linear-gradient(to top,
                    #0a0f14 0%,
                    #0a0f14 25%,
                    rgba(10, 15, 20, 0.4) 60%,
                    rgba(10, 15, 20, 0.2) 100%);
            backdrop-filter: blur(4px);
        }

        #video-wrapper.is-paused #video-pause-overlay {
            opacity: 1 !important;
            transition: opacity 0s !important;
            /* Instant show to race YouTube UI */
            pointer-events: auto !important;
        }

        /* End Overlay: Full cover with branding */
        #video-end-overlay {
            opacity: 0 !important;
            transition: opacity 0.5s ease !important;
            pointer-events: none !important;
            z-index: 25;
            /* Solid opaque background with Brand Gradient to block YT */
            background: linear-gradient(135deg, #001a29 0%, #000000 100%) !important;
        }

        #video-wrapper.is-ended #video-end-overlay {
            opacity: 1 !important;
            pointer-events: auto !important;
        }

        @media (hover: hover) {
            #video-wrapper:hover #video-top-bar {
                opacity: 1 !important;
            }
        }

        /* Fullscreen Fallback */
        .is-fullscreen-fallback #video-wrapper {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            width: 100vw !important;
            height: 100vh !important;
            height: 100dvh !important;
            z-index: 9999 !important;
            background: #000 !important;
        }

        .is-fullscreen-fallback body {
            overflow: hidden !important;
        }

        /* 
         * YT MASKING LOGIC (Using Solid Bars in Fullscreen)
         * Only applies when using our fallback CSS fullscreen 
         */
        .is-fullscreen-fallback #video-top-bar {
            /* background-color: #000 !important; <-- REMOVED to allow gradient */
            /* Allow fading in landscape */
        }

        .is-fullscreen-fallback #custom-controls {
            /* background: #000 !important; <-- REMOVED */
            /* Forces solid black over bottom logo */
            /* Allow fading in landscape */
        }

        /* Ensure bottom logo blocker is active if controls are somehow hidden */
        .is-fullscreen-fallback #video-logo-overlay {
            background-color: #000 !important;
            pointer-events: auto !important;
            /* Block clicks to logo */
        }

        /* 
         * GLOBAL BUFFERING MASK (Refined)
         * Hides YouTube UI flash during seek/loading
         * Uses OPAQUE GRADIENTS to cover Title/Logo while looking cool
         */
        #video-wrapper.is-buffering #video-top-bar,
        #video-wrapper.is-seeking #video-top-bar {
            background: linear-gradient(to bottom, #000 0%, #000 60px, transparent 100%) !important;
            opacity: 1 !important;
            transition: none !important;
            z-index: 2147483647 !important;
            pointer-events: auto !important;
        }

        #video-wrapper.is-buffering #custom-controls,
        #video-wrapper.is-seeking #custom-controls {
            background: linear-gradient(to top, #000 0%, #000 60px, transparent 100%) !important;
            opacity: 1 !important;
            transition: none !important;
            z-index: 2147483647 !important;
            pointer-events: auto !important;
        }

        /* Hide the simple logo overlay during buffering since controls gradient covers it better */
        #video-wrapper.is-buffering #video-logo-overlay,
        #video-wrapper.is-seeking #video-logo-overlay {
            opacity: 0 !important;
        }

        /* Fullscreen Landscape: Fit video exactly to visible viewport */
        @media (orientation: landscape) {
            .is-fullscreen-fallback #video-wrapper {
                aspect-ratio: auto !important;
                height: 100vh !important;
                height: 100dvh !important;
                height: -webkit-fill-available !important;
                max-height: 100dvh !important;
            }

            .is-fullscreen-fallback #video-wrapper iframe,
            .is-fullscreen-fallback #video-wrapper #player-container {
                height: 100vh !important;
                height: 100dvh !important;
                height: -webkit-fill-available !important;
                max-height: 100dvh !important;
            }
        }

        /* Fullscreen Portrait: Ensure controls are always visible and clickable */
        @media (orientation: portrait) {

            /* FORCE SOLID BLACK MASK IN PORTRAIT FULLSCREEN ONLY */
            .is-fullscreen-fallback #video-wrapper.is-buffering #video-top-bar,
            .is-fullscreen-fallback #video-wrapper.is-buffering #custom-controls,
            .is-fullscreen-fallback #video-wrapper.is-buffering #video-logo-overlay,
            .is-fullscreen-fallback #video-wrapper.is-seeking #video-top-bar,
            .is-fullscreen-fallback #video-wrapper.is-seeking #custom-controls,
            .is-fullscreen-fallback #video-wrapper.is-seeking #video-logo-overlay {
                background-color: #000 !important;
            }

            .is-fullscreen-fallback #video-wrapper {
                aspect-ratio: auto !important;
            }

            /* In Portrait, controls are in black space, so keep them visible key */
            .is-fullscreen-fallback #video-top-bar {
                opacity: 1 !important;
                /* pointer-events removed to allow clicking through gradient to pause */
            }

            .is-fullscreen-fallback #custom-controls {
                opacity: 1 !important;
                pointer-events: auto !important;
            }

            .is-fullscreen-fallback #custom-controls {
                position: fixed !important;
                bottom: 0 !important;
                left: 0 !important;
                right: 0 !important;
                z-index: 100000 !important;
                padding-bottom: env(safe-area-inset-bottom) !important;
            }

            .is-fullscreen-fallback #timeline-container {
                position: fixed !important;
                bottom: 44px !important;
                left: 0 !important;
                right: 0 !important;
                z-index: 100000 !important;
            }
        }

        #timeline-container {
            height: 6px;
            cursor: pointer;
            transition: height 0.2s;
        }

        #timeline-container:hover {
            height: 10px;
        }

        #timeline-progress {
            width: 0%;
            height: 100%;
            background: #00AB8E;
            position: relative;
        }

        #timeline-handle {
            position: absolute;
            right: -6px;
            top: 50%;
            transform: translateY(-50%);
            width: 12px;
            height: 12px;
            background: #fff;
            border-radius: 50%;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.3);
            display: none;
        }

        #timeline-container:hover #timeline-handle {
            display: block;
        }

        /* Hide default details marker */
        details>summary {
            list-style: none;
        }

        details>summary::-webkit-details-marker {
            display: none;
        }

        /* Elegant Sidebar Transition */
        #player-sidebar {
            transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1), transform 0.3s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.3s ease;
        }

        #player-sidebar.sidebar-collapsed {
            width: 0 !important;
            padding: 0 !important;
            opacity: 0 !important;
            overflow: hidden !important;
            border: none !important;
        }
    </style>
    <!-- Dogology Learning v<?php echo DOGOLOGY_LEARNING_VERSION; ?> -->
    <script async data-no-minify="1" data-no-optimize="1" data-no-defer="1"
        src="https://www.youtube.com/iframe_api?v=<?php echo DOGOLOGY_LEARNING_VERSION; ?>"></script>
    <?php wp_head(); ?>
    <style>
        /* Precision override for external 72px padding */
        html body {
            padding-top: 0 !important;
        }
    </style>
</head>

<body class="bg-[#f8fafc] text-[#44403c] antialiased min-h-screen flex flex-col">
    <!-- WP Rocket Safeguard: Protect dynamic classes from RUCSS pruning -->
    <div class="hidden is-playing is-paused is-ended is-fullscreen user-interacting" style="display:none !important;"
        aria-hidden="true"></div>
    <!-- OVERLAY BACKDROP (Mobile Only) -->
    <div id="sidebar-backdrop" onclick="toggleSidebar()"
        class="fixed inset-0 bg-black/50 z-[60] hidden transition-opacity lg:hidden backdrop-blur-sm"></div>

    <!-- GLOBAL HEADER -->
    <header class="bg-white border-b border-gray-100 p-4 flex items-center justify-between relative z-40"
        style="padding-top: max(1rem, env(safe-area-inset-top));">
        <div class="max-w-7xl mx-auto w-full flex items-center justify-between">
            <!-- LEFT: Menu Trigger (Mobile) + Back Button + Logo -->
            <div class="flex items-center gap-3">
                <!-- Mobile Menu Button -->
                <button onclick="toggleSidebar()"
                    class="lg:hidden p-2 -ml-2 text-gray-500 hover:text-primary transition rounded-lg hover:bg-gray-50">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                </button>

                <a href="<?php echo home_url('/my-courses'); ?>"
                    class="flex items-center gap-2 hover:opacity-80 transition group">
                    <span class="text-gray-400 group-hover:text-primary transition mr-1 hidden md:inline">&larr;</span>
                    <?php if ($ui_logo_url): ?>
                        <img src="<?php echo esc_url($ui_logo_url); ?>" alt="Logo"
                            class="h-8 w-auto object-contain critical-logo" width="150" height="32" data-no-lazy="1">
                    <?php else: ?>
                        <div
                            class="w-7 h-7 bg-primary rounded-lg flex items-center justify-center text-white font-bold text-base shadow-sm">
                            D</div>
                        <span
                            class="font-bold text-[#44403c] text-base font-kanit tracking-tight hidden md:inline">Dogology</span>
                        <!-- Mobile Text -->
                        <span
                            class="font-bold text-[#44403c] text-base font-kanit tracking-tight md:hidden"><?php echo esc_html($t['course_content']); ?></span>
                    <?php endif; ?>
                </a>
            </div>

            <!-- Language Switcher (Desktop Centered) -->
            <div
                class="absolute left-1/2 transform -translate-x-1/2 hidden md:flex items-center gap-1 bg-gray-50 rounded-full px-1 py-1 border border-gray-100 scale-100">
                <a href="<?php echo esc_url(add_query_arg('lang', 'th')); ?>"
                    class="px-3 py-1 rounded-full text-xs font-bold transition <?php echo $current_lang === 'th' ? 'bg-white shadow-sm text-primary' : 'text-gray-400 hover:text-gray-600'; ?>">TH</a>
                <a href="<?php echo esc_url(add_query_arg('lang', 'en')); ?>"
                    class="px-3 py-1 rounded-full text-xs font-bold transition <?php echo $current_lang === 'en' ? 'bg-white shadow-sm text-primary' : 'text-gray-400 hover:text-gray-600'; ?>">EN</a>
            </div>

            <!-- User Menu Trigger -->
            <div class="relative">
                <button onclick="toggleUserMenu()"
                    class="flex items-center gap-3 cursor-pointer hover:bg-gray-50 pl-2 pr-4 py-1.5 rounded-full border border-transparent hover:border-gray-200 transition group focus:outline-none">
                    <?php if ($current_student->profile_picture): ?>
                        <img src="<?php echo esc_url($current_student->profile_picture); ?>"
                            class="w-8 h-8 rounded-full border border-white shadow-sm object-cover">
                    <?php else: ?>
                        <div
                            class="w-8 h-8 rounded-full bg-[#00AB8E] text-white flex items-center justify-center font-bold text-sm shadow-sm">
                            <?php echo $user_initial; ?>
                        </div>
                    <?php endif; ?>

                    <div class="hidden md:flex flex-col text-right">
                        <span
                            class="text-xs font-bold text-[#44403c] font-kanit group-hover:text-[#00AB8E]"><?php echo esc_html($user_name); ?></span>
                    </div>
                    <span class="text-gray-400 text-xs">▼</span>
                </button>

                <!-- USER MENU DROPDOWN -->
                <div id="user-dropdown"
                    class="absolute right-0 top-full mt-2 w-80 bg-white rounded-xl shadow-xl border border-gray-100 overflow-hidden hidden transform origin-top-right transition-all duration-200 z-50 p-0">
                    <!-- Header -->
                    <div class="bg-gradient-to-r from-[#00AB8E] to-[#0076BA] p-6 text-white text-left">
                        <div class="flex items-center gap-4">
                            <div class="relative shrink-0">
                                <?php if ($current_student->profile_picture): ?>
                                    <img src="<?php echo esc_url($current_student->profile_picture); ?>"
                                        class="w-16 h-16 rounded-full border-4 border-white/20 shadow-sm object-cover block">
                                <?php else: ?>
                                    <div
                                        class="w-16 h-16 bg-white/20 rounded-full flex items-center justify-center text-3xl font-bold backdrop-blur-sm border-4 border-white/20">
                                        <?php echo $user_initial; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="overflow-hidden">
                                <h3 class="font-bold font-kanit text-lg leading-tight truncate">
                                    <?php echo esc_html($user_name); ?>
                                </h3>
                                <p class="text-green-50 text-xs opacity-90 truncate">
                                    <?php echo esc_html($user_email); ?>
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Items -->
                    <div>
                        <!-- Mobile Language Switcher -->
                        <div class="px-6 py-4 border-b border-gray-50 md:hidden flex items-center justify-between">
                            <span
                                class="text-sm font-bold text-gray-500 font-kanit"><?php echo $current_lang === 'th' ? 'ภาษา' : 'Language'; ?></span>
                            <div
                                class="flex items-center gap-1 bg-gray-50 rounded-full px-1 py-1 border border-gray-100">
                                <a href="<?php echo esc_url(add_query_arg('lang', 'th')); ?>"
                                    class="px-3 py-1 rounded-full text-xs font-bold transition <?php echo $current_lang === 'th' ? 'bg-white shadow-sm text-primary' : 'text-gray-400'; ?>">TH</a>
                                <a href="<?php echo esc_url(add_query_arg('lang', 'en')); ?>"
                                    class="px-3 py-1 rounded-full text-xs font-bold transition <?php echo $current_lang === 'en' ? 'bg-white shadow-sm text-primary' : 'text-gray-400'; ?>">EN</a>
                            </div>
                        </div>
                        <a href="<?php echo home_url('/my-courses'); ?>"
                            class="w-full flex items-center gap-3 px-6 py-4 hover:bg-gray-50 transition group border-b border-gray-50">
                            <div
                                class="w-8 h-8 rounded-full bg-blue-50 text-[#0076BA] flex items-center justify-center text-sm shrink-0">
                                🏠</div>
                            <span
                                class="font-bold text-sm font-kanit"><?php echo esc_html($t['menu_dashboard']); ?></span>
                        </a>

                        <a href="<?php echo home_url('/student-logout?t=' . time()); ?>"
                            class="w-full flex items-center gap-3 px-6 py-4 hover:bg-red-50 text-red-500 transition group hover:font-bold">
                            <div
                                class="w-8 h-8 rounded-full bg-red-50 group-hover:bg-white text-red-500 flex items-center justify-center text-sm shrink-0">
                                🚪</div>
                            <span class="font-bold text-sm font-kanit"><?php echo esc_html($t['menu_logout']); ?></span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="flex-1 flex flex-col-reverse lg:flex-row max-w-full lg:max-w-[1600px] mx-auto w-full relative">
        <!-- Removed max-w-[1600px] constraint on mobile, added back only on lg -->

        <!-- SIDEBAR (LEFT) -->
        <!-- OFF-CANVAS on Mobile, FIXED on Desktop -->
        <aside id="player-sidebar"
            class="fixed inset-y-0 left-0 z-[70] w-[300px] bg-gray-50 shadow-2xl transform -translate-x-full transition-transform duration-300 lg:relative lg:translate-x-0 lg:w-[350px] lg:shadow-none lg:z-30 lg:border-r border-gray-100 flex flex-col h-full lg:h-[calc(100vh-73px)] lg:sticky lg:top-[73px]">

            <!-- Mobile Header with Close Button -->
            <div class="p-4 border-b border-gray-100 flex items-center justify-between lg:hidden bg-gray-50">
                <span class="font-bold text-gray-600 font-kanit"><?php echo $t['course_content']; ?></span>
                <button onclick="toggleSidebar()"
                    class="p-2 text-gray-400 hover:text-red-500 transition bg-white rounded-full shadow-sm">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <div class="p-6 bg-gray-50 border-b border-gray-100 relative">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="font-bold text-[#44403c] font-kanit text-lg truncate pr-8">
                        <?php echo esc_html($course->post_title); ?>
                    </h3>
                    <!-- Desktop Collapse Button (Elegant) -->
                    <button onclick="toggleDesktopSidebar()"
                        class="hidden lg:flex absolute top-4 right-4 items-center justify-center p-2 rounded-lg hover:bg-gray-100 text-gray-300 hover:text-gray-500 transition group"
                        title="<?php echo $t['hide_sidebar']; ?>">
                        <svg class="w-5 h-5 transform group-hover:-translate-x-1 transition-transform" fill="none"
                            stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M11 19l-7-7 7-7m8 14l-7-7 7-7" />
                        </svg>
                    </button>
                </div>
                <div class="flex items-center gap-2">
                    <div class="h-1.5 flex-1 bg-gray-100 rounded-full overflow-hidden">
                        <div id="progress-bar"
                            class="h-full bg-gradient-to-r from-primary to-secondary transition-all duration-700"
                            style="width: <?php echo $progress_percent; ?>%"></div>
                    </div>
                    <span id="progress-text"
                        class="text-xs font-bold text-secondary"><?php echo $progress_percent; ?>%</span>
                </div>
            </div>

            <div class="overflow-y-auto sidebar-scrollbar flex-1 p-4 space-y-4">

                <?php
                // 1. Render Modules & their lessons
                foreach ($course_modules as $mod):
                    if (empty($grouped_lessons[$mod->ID]))
                        continue; // Skip empty modules
                    $mod_lessons = $grouped_lessons[$mod->ID];

                    // Check if active lesson is in this module (to auto-open)
                    $is_module_active = false;
                    foreach ($mod_lessons as $ml) {
                        if ($ml->ID == $lesson_id) {
                            $is_module_active = true;
                            break;
                        }
                    }
                    ?>
                    <details class="group/accordion" <?php echo $is_module_active ? 'open' : ''; ?>>
                        <summary
                            class="flex items-center gap-2 cursor-pointer list-none font-bold text-gray-700 hover:text-primary mb-2 select-none">
                            <div
                                class="w-5 h-5 flex items-center justify-center transform transition-transform duration-200 group-open/accordion:rotate-90 text-gray-400">
                                <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M8 5v14l11-7z" />
                                </svg>
                            </div>
                            <span><?php echo esc_html($mod->post_title); ?></span>
                        </summary>
                        <div class="space-y-1 pl-2 border-l-2 border-gray-100 ml-1">
                            <?php foreach ($mod_lessons as $l):
                                $is_active = $l->ID == $lesson_id;
                                $l_completed = $is_lesson_complete($l->ID);
                                $l_dur = get_post_meta($l->ID, '_dogology_duration', true); // hits primed cache, no DB
                                ?>
                                <a href="<?php echo home_url("/learn/{$course_id}/{$l->ID}"); ?>" <?php echo $is_active ? 'id="active-lesson"' : ''; ?>
                                    class="flex items-center gap-3 p-2 rounded-lg transition group/item <?php echo $is_active ? 'bg-white shadow-sm border-l-4 border-primary' : 'hover:bg-gray-200 text-gray-500'; ?> <?php echo $l_completed ? 'opacity-90' : ''; ?>">

                                    <div
                                        class="w-5 h-5 shrink-0 rounded-full flex items-center justify-center text-[8px] <?php echo $is_active ? 'bg-primary text-white shadow-sm' : ($l_completed ? 'bg-green-100 text-green-600' : 'border-2 border-gray-200 text-gray-400'); ?>">
                                        <?php if ($is_active): ?>◀<?php elseif ($l_completed): ?>✓<?php else: ?><?php endif; ?>
                                    </div>

                                    <div class="flex-1 min-w-0">
                                        <div
                                            class="text-xs truncate <?php echo $is_active ? 'font-bold text-gray-900' : 'font-medium group-hover/item:text-primary'; ?>">
                                            <?php echo esc_html($l->post_title); ?>
                                        </div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endforeach; ?>

                <?php
                // 2. Render Orphaned Lessons (if any)
                if (!empty($no_module_lessons)):
                    ?>
                    <div class="space-y-1">
                        <div class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">
                            <?php echo esc_html($t['other_lessons']); ?>
                        </div>
                        <?php foreach ($no_module_lessons as $l):
                            $is_active = $l->ID == $lesson_id;
                            $l_completed = $is_lesson_complete($l->ID);
                            $l_dur = get_post_meta($l->ID, '_dogology_duration', true); // hits primed cache, no DB
                            ?>
                            <a href="<?php echo home_url("/learn/{$course_id}/{$l->ID}"); ?>"
                                class="flex items-center gap-3 p-3 rounded-lg transition group <?php echo $is_active ? 'bg-white shadow-md border-l-4 border-primary' : 'hover:bg-gray-200 hover:shadow-sm text-gray-500'; ?> <?php echo $l_completed ? 'opacity-90' : ''; ?>">

                                <div
                                    class="w-6 h-6 shrink-0 rounded-full flex items-center justify-center text-[10px] <?php echo $is_active ? 'bg-primary text-white shadow-sm' : ($l_completed ? 'bg-green-100 text-green-600' : 'border-2 border-gray-200 text-gray-400'); ?>">
                                    <?php if ($is_active): ?>◀<?php elseif ($l_completed): ?>✓<?php else: ?><?php endif; ?>
                                </div>

                                <div class="flex-1">
                                    <div
                                        class="text-sm <?php echo $is_active ? 'font-bold text-gray-900' : 'font-medium group-hover:text-primary'; ?>">
                                        <?php echo esc_html($l->post_title); ?>
                                    </div>
                                    <div class="text-[10px] <?php echo $is_active ? 'text-primary' : 'text-gray-400'; ?>">
                                        <?php if ($l_dur): ?>
                                            <?php echo esc_html($l_dur); ?>
                                        <?php else: ?>
                                            <?php echo get_post_meta($l->ID, '_dogology_video_url', true) ? $t['video_lesson'] : $t['reading_lesson']; ?>
                                        <?php endif; ?>
                                        <?php if ($is_active)
                                            echo ' • ' . esc_html($t['currently_studying']); ?>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?> <!-- End Orphan Loop -->
            </div>


            <!-- Sidebar Footer (Navigation) -->
            <div class="p-4 border-t border-gray-100 bg-gray-50 flex flex-col gap-2">
                <a href="<?php echo home_url('/my-courses'); ?>"
                    class="flex items-center justify-center gap-2 w-full py-3 bg-white border border-gray-200 text-gray-600 rounded-xl font-bold text-sm hover:border-primary hover:text-primary transition shadow-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" />
                    </svg>
                    <?php echo esc_html($t['menu_dashboard']); ?>
                </a>
                <a href="<?php echo home_url('/'); ?>"
                    class="flex items-center justify-center gap-2 w-full py-3 bg-transparent text-gray-400 font-bold text-xs hover:text-gray-600 transition">
                    <?php echo esc_html($t['back_to_main_site']); ?>
                </a>
            </div>
        </aside>

        <!-- MAIN PLAYER CONTENT -->
        <div id="player-content-wrapper" class="flex-1 flex flex-col bg-white relative transition-all duration-300">
            <!-- Sidebar Toggle Button (Floating) -->
            <button id="btn-expand-sidebar" onclick="toggleDesktopSidebar()"
                class="hidden fixed left-0 top-24 z-[100] bg-white shadow-md border border-gray-100 rounded-r-lg p-2 hover:bg-gray-50 text-gray-400 hover:text-primary transition items-center gap-2 group"
                title="<?php echo $t['show_menu']; ?>" aria-label="<?php echo esc_attr($t['show_menu']); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M13 5l7 7-7 7M5 5l7 7-7 7" />
                </svg>
                <span
                    class="text-xs font-bold max-w-0 overflow-hidden group-hover:max-w-xs transition-all duration-300 whitespace-nowrap"><?php echo $t['show_menu']; ?></span>
            </button>

            <!-- Video Container -->
            <?php if ($video_url): ?>
                <div id="video-wrapper"
                    class="aspect-video bg-black flex items-center justify-center text-white relative group overflow-hidden"
                    style="aspect-ratio: 16/9;">
                    <div id="player-container" class="absolute inset-0 w-full h-full"
                        style="position: absolute; top:0; left:0; width:100%; height:100%;">
                        <iframe id="video-player" src="<?php echo esc_url(Dogology_Helpers::get_embed_url($video_url)); ?>"
                            class="absolute inset-0 w-full h-full pointer-events-none" frameborder="0"
                            allow="autoplay; fullscreen; picture-in-picture; encrypted-media; gyroscope; accelerometer"
                            allowfullscreen playsinline webkit-playsinline fetchpriority="high"
                            loading="eager" data-no-lazy="1" data-skip-lazy="1" style="width:100%; height:100%;"></iframe>
                    </div>

                    <!-- 1. Interaction Layer (Always Top, Invisible, Captures Taps) -->
                    <div id="video-click-layer" class="absolute inset-0 z-20 w-full h-full"
                        style="position: absolute; top:0; left:0; width:100%; height:100%; z-index: 20; cursor: pointer; -webkit-tap-highlight-color: transparent;">
                    </div>

                    <!-- 2. Visual Dimming Layer (For Pause/End states) -->
                    <div id="video-dim-layer"
                        class="absolute inset-0 z-10 w-full h-full pointer-events-none transition-opacity duration-300 opacity-0"
                        style="position: absolute; top:0; left:0; width:100%; height:100%; z-index: 10; pointer-events: none; background: rgba(0,0,0,0.4);">
                    </div>

                    <!-- Rich Context Poster (Start Screen) -->
                    <div id="video-poster" onclick="window.handlePosterClick(event)"
                        class="absolute inset-0 flex flex-col items-center justify-center cursor-pointer bg-[#000] p-8 z-30 transition-opacity duration-500 overflow-hidden">

                        <!-- Brand Gradient Background -->
                        <div
                            class="absolute inset-0 bg-gradient-to-br from-[#001a29] via-[#051014] to-[#000000] opacity-100">
                        </div>

                        <!-- Hero Glow Effect (Behind Content) -->
                        <div
                            class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[500px] h-[500px] bg-[#0076BA]/20 rounded-full blur-[120px] pointer-events-none">
                        </div>

                        <!-- Background Pattern/Effect -->
                        <div class="absolute inset-0 opacity-10 mix-blend-overlay"
                            style="background-image: radial-gradient(circle at 1px 1px, rgba(255,255,255,0.15) 1px, transparent 0); background-size: 24px 24px;">
                        </div>

                        <!-- Content Container -->
                        <div class="relative z-10 flex flex-col items-center text-center max-w-2xl w-full">

                            <!-- Course Label -->
                            <span
                                class="inline-block px-3 py-1 rounded-full bg-primary/10 text-primary text-xs font-bold tracking-wider uppercase mb-4 border border-primary/20">
                                <?php echo esc_html($course->post_title); ?>
                            </span>

                            <!-- Lesson Title -->
                            <h1 class="text-2xl md:text-4xl font-bold text-white font-kanit mb-2 leading-tight">
                                <?php echo esc_html($lesson->post_title); ?>
                            </h1>

                            <!-- Subtitle (Hidden on Mobile to prevent overflow) -->
                            <?php if ($subtitle): ?>
                                <p class="hidden md:block text-lg md:text-xl text-white/60 font-light mb-6 font-kanit">
                                    <?php echo esc_html($subtitle); ?>
                                </p>
                            <?php endif; ?>

                            <!-- Meta Info (Compact on mobile) -->
                            <div class="flex items-center gap-4 text-xs md:text-sm text-gray-400 mb-4 md:mb-8 font-medium">
                                <span class="flex items-center gap-1">
                                    <svg class="w-3 h-3 md:w-4 md:h-4" fill="none" stroke="currentColor"
                                        viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    <?php echo $duration ? esc_html($duration) : ($video_url ? $t['video_lesson'] : $t['reading_lesson']); ?>
                                </span>
                                <?php if ($is_completed): ?>
                                    <span class="flex items-center gap-1 text-green-500">
                                        <svg class="w-3 h-3 md:w-4 md:h-4" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M5 13l4 4L19 7" />
                                        </svg>
                                        <?php echo esc_html($t['already_watched']); ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- Big Play Button (Smaller on mobile) -->
                            <button
                                class="group flex items-center justify-center gap-2 md:gap-3 bg-white text-black px-6 py-3 md:px-8 md:py-4 rounded-full font-bold text-sm md:text-lg hover:scale-105 transition duration-300 shadow-[0_0_40px_rgba(255,255,255,0.2)]">
                                <div
                                    class="w-6 h-6 md:w-8 md:h-8 bg-black text-white rounded-full flex items-center justify-center">
                                    <svg class="w-3 h-3 md:w-4 md:h-4 ml-0.5" fill="currentColor" viewBox="0 0 24 24">
                                        <path d="M8 5v14l11-7z" />
                                    </svg>
                                </div>
                                <?php echo esc_html($t['start_learning']); ?>
                            </button>

                        </div>
                    </div>

                    <!-- Branded Top Bar (Hides YT Title/Share) -->
                    <!-- Reduced padding on mobile to prevent blocking controls -->
                    <div id="video-top-bar"
                        class="absolute top-0 left-0 right-0 bg-gradient-to-b from-black via-black/80 to-transparent px-4 md:px-8 pb-12 md:pb-32 pt-4 md:pt-12 text-white transition-opacity duration-300 pointer-events-none">
                        <div class="flex items-center justify-between pointer-events-auto">
                            <div class="flex flex-col gap-1">
                                <span
                                    class="text-[10px] md:text-xs uppercase tracking-[0.2em] text-primary font-bold opacity-100 font-kanit">Dogology
                                    Community</span>
                                <h2
                                    class="text-base md:text-xl font-medium font-kanit truncate max-w-[280px] md:max-w-2xl text-white/90">
                                    <?php echo esc_html($lesson->post_title); ?>
                                </h2>
                            </div>
                        </div>
                    </div>

                    <!-- YouTube Logo Shield (Bottom Full Width) -->
                    <div id="video-logo-overlay" class="absolute bottom-0 inset-x-0 h-24 pointer-events-none"></div>

                    <!-- Branded Pause Overlay (Hides "More Videos") -->
                    <div id="video-pause-overlay" class="absolute inset-0 flex items-center justify-center p-8">
                        <div
                            class="text-center max-w-md transform transition duration-700 translate-y-4 group-[.is-paused]:translate-y-0 opacity-0 group-[.is-paused]:opacity-100">
                            <div
                                class="inline-flex items-center justify-center w-16 h-16 bg-primary/20 text-primary rounded-2xl mb-6 backdrop-blur-md border border-primary/30">
                                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                                </svg>
                            </div>
                            <h3 class="text-xl md:text-2xl font-bold mb-3 font-kanit text-white">
                                <?php echo esc_html($t['pause_title']); ?>
                            </h3>
                            <p class="text-white/60 text-sm md:text-base mb-8 font-light tracking-wide leading-relaxed">
                                <?php echo esc_html($t['pause_desc']); ?> <span
                                    class="text-primary font-medium"><?php echo esc_html($course->post_title); ?></span><br>
                                <?php echo esc_html($t['pause_ready']); ?>
                            </p>
                            <button onclick="togglePlay()"
                                class="inline-flex items-center gap-2 bg-primary hover:bg-primary-dark text-black px-8 py-3 rounded-full font-bold transition shadow-[0_0_30px_rgba(0,171,142,0.3)]">
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M8 5v14l11-7z" />
                                </svg>
                                <?php echo esc_html($t['resume']); ?>
                            </button>
                        </div>
                    </div>

                    <!-- Timeline (Clickable/Draggable) -->
                    <!-- Knob adjusted: smaller on mobile (w-3 h-3), larger on desktop (md:w-4 md:h-4) -->
                    <!-- Timeline removed (duplicate) -->

                    <!-- Branded End Overlay (Replay / Next Lesson) -->
                    <div id="video-end-overlay"
                        class="absolute inset-0 flex items-center justify-center p-8 overflow-hidden">
                        <!-- Hero Glow Effect for End Screen -->
                        <div
                            class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[600px] h-[600px] bg-[#0076BA]/10 rounded-full blur-[100px] pointer-events-none">
                        </div>

                        <div
                            class="relative z-10 text-center max-w-md transform transition duration-700 translate-y-4 group-[.is-ended]:translate-y-0 opacity-0 group-[.is-ended]:opacity-100">

                            <!-- Success Icon -->
                            <div
                                class="inline-flex items-center justify-center w-20 h-20 bg-green-500/20 text-green-400 rounded-full mb-6 backdrop-blur-md border border-green-500/30">
                                <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3"
                                        d="M5 13l4 4L19 7" />
                                </svg>
                            </div>

                            <h2 class="text-2xl md:text-3xl font-bold mb-4 font-kanit">
                                <?php echo esc_html($t['end_title']); ?>
                            </h2>

                            <!-- Hidden Subtitle on mobile -->
                            <p class="hidden md:block text-white/80 mb-8 font-kanit">
                                <?php echo esc_html($t['end_desc']); ?>
                            </p>

                            <div class="flex flex-col gap-3 w-full max-w-xs mx-auto">
                                <?php if ($next_lesson_url): ?>
                                    <a href="<?php echo esc_url($next_lesson_url); ?>"
                                        class="bg-primary hover:bg-primary-dark text-black px-8 py-3 rounded-full font-bold transition shadow-[0_0_20px_rgba(0,171,142,0.4)] flex items-center justify-center gap-2">
                                        <?php echo $t['next_lesson']; ?>
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M14 5l7 7m0 0l-7 7m7-7H3" />
                                        </svg>
                                    </a>
                                <?php else: ?>
                                    <a href="<?php echo home_url('/my-courses'); ?>"
                                        class="bg-white hover:bg-gray-100 text-black px-8 py-3 rounded-full font-bold transition flex items-center justify-center gap-2">
                                        <?php echo $t['back_to_course']; ?>
                                    </a>
                                <?php endif; ?>
                                <button onclick="togglePlay()"
                                    class="px-6 py-3 rounded-full border border-white/20 text-white hover:bg-white/10 transition font-medium text-sm flex items-center justify-center gap-2">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                    </svg>
                                    <?php echo $t['watch_again']; ?>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Branded Mini-Mode Overlay (for when video is minimized) -->
                    <div id="video-mini-overlay"
                        class="absolute inset-0 flex items-center justify-center p-8 overflow-hidden opacity-0 pointer-events-none transition-opacity duration-300 group-[.is-mini]:opacity-100 group-[.is-mini]:pointer-events-auto">
                        <div class="relative z-10 text-center max-w-md">
                            <div
                                class="inline-flex items-center justify-center w-16 h-16 bg-white/20 text-white rounded-full mb-6 backdrop-blur-md border border-white/30">
                                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <span><?php echo $t['paused']; ?></span>
                            </div>

                            <h2 class="text-xl md:text-3xl font-bold mb-2 font-kanit"><?php echo $t['continue_learning']; ?>
                            </h2>

                            <!-- Hidden subtitle on mobile -->
                            <p class="hidden md:block text-white/80 mb-8 font-kanit"><?php echo $t['press_play']; ?></p>

                            <!-- Mobile spacer -->
                            <div class="h-4 md:hidden"></div>

                            <button onclick="togglePlay()"
                                class="bg-primary hover:bg-primary/90 text-white px-8 py-3 rounded-full font-bold transition flex items-center gap-2 mx-auto">
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M8 5v14l11-7z" />
                                </svg>
                                <?php echo $t['play_continue']; ?>
                            </button>
                        </div>
                    </div>

                    <!-- Custom Controls Overlay -->
                    <div id="video-overlay"
                        class="absolute inset-0 flex items-center justify-center transition-all cursor-pointer z-10">
                        <div id="play-pause-btn"
                            class="w-20 h-20 md:w-24 md:h-24 bg-primary/95 text-white rounded-full flex items-center justify-center shadow-[0_0_50px_rgba(0,0,0,0.5)] ring-8 ring-white/5 hover:ring-white/10 group-hover:scale-110 transition duration-500 ease-out">
                            <!-- Play Icon -->
                            <svg id="icon-play" class="w-10 h-10 md:w-12 md:h-12 ml-1" fill="currentColor"
                                viewBox="0 0 24 24">
                                <path d="M8 5v14l11-7z" />
                            </svg>
                            <!-- Pause Icon -->
                            <svg id="icon-pause" class="w-10 h-10 md:w-12 md:h-12 hidden" fill="currentColor"
                                viewBox="0 0 24 24">
                                <path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z" />
                            </svg>
                        </div>
                    </div>

                    <!-- Bottom Controls Bar -->
                    <div id="custom-controls"
                        class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/80 to-transparent p-4 pt-10 z-20">
                        <!-- Timeline -->
                        <div id="timeline-container" class="relative bg-white/20 rounded-full mb-3">
                            <div id="timeline-progress" class="rounded-full">
                                <div id="timeline-handle"></div>
                            </div>
                        </div>

                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-4">
                                <!-- Re-use Play/Pause but small if needed, or just let overlay handle it -->
                                <button id="btn-play-small" class="text-white hover:text-primary transition">
                                    <svg id="icon-play-small" class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                                        <path d="M8 5v14l11-7z" />
                                    </svg>
                                    <svg id="icon-pause-small" class="w-6 h-6 hidden" fill="currentColor"
                                        viewBox="0 0 24 24">
                                        <path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z" />
                                    </svg>
                                </button>
                                <div class="text-white text-xs font-medium space-x-1">
                                    <span id="current-time">0:00</span>
                                    <span class="opacity-50">/</span>
                                    <span id="duration-time">0:00</span>
                                </div>
                            </div>

                            <button id="btn-fullscreen" class="text-white hover:text-primary transition"
                                aria-label="Fullscreen">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4" />
                                </svg>
                            </button>
                            <div class="relative hidden md:block group/kb">
                                <button
                                    class="text-white/40 hover:text-white transition text-xs font-bold w-6 h-6 rounded border border-white/20 flex items-center justify-center"
                                    aria-label="Keyboard shortcuts">?</button>
                                <div
                                    class="absolute bottom-full right-0 mb-2 bg-black/90 text-white text-[10px] rounded-lg p-3 w-44 hidden group-hover/kb:block pointer-events-none shadow-xl">
                                    <div class="font-bold mb-1.5 text-[11px]">
                                        <?php echo $current_lang === 'th' ? 'ปุ่มลัด' : 'Shortcuts'; ?>
                                    </div>
                                    <div class="space-y-1">
                                        <div class="flex justify-between"><span>Space</span><span
                                                class="opacity-60"><?php echo $current_lang === 'th' ? 'เล่น/หยุด' : 'Play/Pause'; ?></span>
                                        </div>
                                        <div class="flex justify-between"><span>← →</span><span
                                                class="opacity-60"><?php echo $current_lang === 'th' ? 'เลื่อน 5 วิ' : '±5 sec'; ?></span>
                                        </div>
                                        <div class="flex justify-between"><span>F</span><span
                                                class="opacity-60"><?php echo $current_lang === 'th' ? 'เต็มจอ' : 'Fullscreen'; ?></span>
                                        </div>
                                        <div class="flex justify-between"><span>Esc</span><span
                                                class="opacity-60"><?php echo $current_lang === 'th' ? 'ปิดเมนู' : 'Close menu'; ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="p-6 md:p-10 max-w-6xl mx-auto w-full">
                <!-- Meta -->
                <div class="flex items-center gap-2 mb-4">
                    <span
                        class="px-3 py-1 bg-gray-100 text-gray-600 rounded-full text-[10px] font-bold uppercase tracking-wider font-kanit">
                        <?php echo esc_html($course->post_title); ?>
                    </span>
                    <span class="text-gray-300">|</span>
                    <span
                        class="text-primary text-[10px] font-bold uppercase tracking-wider font-kanit"><?php echo $video_url ? $t['video_lesson'] : $t['reading_lesson']; ?></span>
                </div>

                <div class="flex flex-col md:flex-row md:items-start justify-between gap-6 mb-8">
                    <div>
                        <h1 class="text-2xl md:text-3xl font-bold text-[#44403c] leading-tight font-kanit mb-1">
                            <?php echo esc_html($lesson->post_title); ?>
                        </h1>
                        <?php if ($subtitle): ?>
                            <p class="text-gray-500 font-medium"><?php echo esc_html($subtitle); ?></p>
                        <?php endif; ?>
                    </div>

                    <button id="btn-mark-complete" data-completed="<?php echo $is_completed ? '1' : '0'; ?>"
                        class="shrink-0 px-6 py-2.5 <?php echo $is_completed ? 'bg-green-50 border-green-200 text-green-600' : 'bg-gray-50 border-gray-200 text-gray-600'; ?> border font-bold rounded-full hover:shadow-md transition text-sm flex items-center justify-center gap-2 min-w-[220px] font-kanit">
                        <span
                            class="label-idle"><?php echo $is_completed ? $t['completed'] : $t['mark_complete']; ?></span>
                        <span class="label-loading hidden"><?php echo $t['processing']; ?></span>
                    </button>
                </div>

                <!-- Rich Text Description -->
                <div class="prose prose-lg prose-slate max-w-none text-[#4b5563] font-body">
                    <?php echo apply_filters('the_content', $lesson->post_content); ?>
                </div>

                <!-- Attachments -->
                <!-- Attachments -->
                <?php if ($attachment_url): ?>
                    <?php
                    $is_pdf = strpos(strtolower($attachment_url), '.pdf') !== false;
                    $att_icon = $is_pdf ? '📄' : '🔗';
                    $att_label = $is_pdf ? 'PDF' : 'LINK';
                    ?>

                    <!-- Unified Option C (Minimal Integrated - Neutral Container) -->
                    <a href="<?php echo esc_url($attachment_url); ?>" target="_blank"
                        class="mt-6 md:mt-10 block bg-gray-50 hover:bg-gray-100 rounded-xl p-4 md:p-5 border border-gray-100 flex items-center gap-3 md:gap-4 no-underline active:scale-[0.98] transition group">

                        <!-- Icon -->
                        <div
                            class="w-10 h-10 md:w-12 md:h-12 rounded-lg bg-[#0076BA]/10 text-[#0076BA] flex items-center justify-center font-bold text-lg md:text-xl shrink-0 border border-[#0076BA]/20 group-hover:scale-110 transition">
                            <?php echo $att_icon; ?>
                        </div>

                        <!-- Text -->
                        <div class="flex-1 min-w-0">
                            <div class="font-bold text-[#0076BA] text-sm md:text-base truncate pr-2 font-kanit">
                                <?php echo esc_html($attachment_title ?: $t['download_materials']); ?>
                            </div>
                            <div class="text-[10px] md:text-xs text-gray-500 font-medium">
                                <?php echo esc_html($attachment_subtitle ?: $t['click_to_open']); ?>
                            </div>
                        </div>

                        <!-- Arrow Icon -->
                        <div class="text-[#0076BA]/60 group-hover:translate-x-1 transition">
                            <svg class="w-5 h-5 md:w-6 md:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        </div>
                    </a>
                <?php endif; ?>

                <!-- Lesson Navigation -->
                <div class="mt-12 pt-8 border-t border-gray-100 flex flex-col md:flex-row justify-between gap-4">
                    <?php if ($prev_lesson): ?>
                        <a href="<?php echo home_url("/learn/{$course_id}/{$prev_lesson->ID}"); ?>"
                            class="flex flex-col gap-1 p-4 rounded-xl border border-gray-100 hover:border-primary transition group md:min-w-[45%]">
                            <span class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">&larr;
                                <?php echo $t['previous']; ?></span>
                            <span
                                class="font-bold text-[#44403c] font-kanit group-hover:text-primary transition"><?php echo esc_html($prev_lesson->post_title); ?></span>
                        </a>
                    <?php else: ?>
                        <div></div>
                    <?php endif; ?>

                    <?php if ($next_lesson): ?>
                        <a id="btn-next-lesson" href="<?php echo home_url("/learn/{$course_id}/{$next_lesson->ID}"); ?>"
                            class="flex flex-col gap-1 p-4 rounded-xl border border-gray-100 hover:border-primary transition group md:min-w-[45%] text-right">
                            <span
                                class="text-[10px] font-bold text-gray-400 uppercase tracking-widest"><?php echo $t['next']; ?>
                                &rarr;</span>
                            <span
                                class="font-bold text-[#44403c] font-kanit group-hover:text-primary transition"><?php echo esc_html($next_lesson->post_title); ?></span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Floating Back-to-Video Button -->
            <button id="btn-scroll-to-video"
                onclick="var v=document.getElementById('video-wrapper'); if(v){v.scrollIntoView({behavior:'smooth'})}else{window.scrollTo({top:0,behavior:'smooth'})}"
                class="fixed bottom-6 right-6 z-50 bg-primary text-white w-10 h-10 rounded-full shadow-lg flex items-center justify-center opacity-0 pointer-events-none transition-opacity duration-300 hover:bg-[#009980]"
                aria-label="Back to video">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
                </svg>
            </button>
            <script>
                (function () {
                    var btn = document.getElementById('btn-scroll-to-video');
                    if (!btn) return;
                    window.addEventListener('scroll', function () {
                        if (window.scrollY > 400) { btn.style.opacity = '1'; btn.style.pointerEvents = 'auto'; }
                        else { btn.style.opacity = '0'; btn.style.pointerEvents = 'none'; }
                    }, { passive: true });
                })();
            </script>

            <!-- Footer Meta -->
            <footer class="mt-auto p-10 bg-gray-50 text-center border-t border-gray-100">
                <p class="text-xs text-gray-400">&copy; <?php echo wp_date('Y'); ?> Dogology. All Rights Reserved.</p>
            </footer>

        </div>
    </main>

    <script>
        // bfcache safety: Samsung Internet and Safari restore the entire page state
        // on back-navigation. The restored snapshot keeps the JS reference to the
        // YT.Player object but the underlying postMessage channel is dead, so taps
        // silently fail. The object-reference check is unreliable here (it survives
        // bfcache regardless of channel health), so we always force a fresh load on
        // bfcache restore. Costs nothing in playback position — the player has no
        // resume-from-position UX elsewhere in the plugin.
        window.addEventListener('pageshow', function (e) {
            if (e.persisted) {
                location.reload();
            }
        });

        // Diagnostic watchdog: if 8s after the player script runs the YT API
        // still hasn't fired onReady, beacon the failure so we can correlate
        // "video didn't play" reports with concrete browser conditions. 8s is
        // long enough that genuinely slow networks usually complete in window;
        // shorter risks false positives on flaky 3G. Instrumentation only —
        // doesn't change user-visible behavior.
        (function () {
            var iframe = document.getElementById('video-player');
            if (!iframe || !iframe.src || iframe.src.indexOf('youtube.com') === -1) return;
            setTimeout(function () {
                if (!window.dogologyApiReady) {
                    window.dogologyReportDiag(
                        'yt_api_no_onready',
                        'ytApiReady=' + window.ytApiReady + ',player=' + !!window.dogologyPlayer
                    );
                }
            }, 8000);
        })();

        // YouTube IFrame API Logic
        let player;
        let updateInterval;
        let isDragging = false;
        let hideTimeout;
        let topBarTimeout;
        let topBarShownOnce = false;

        // Real YouTube player initializer (called by the head stub OR directly below)
        window.initYouTubePlayer = function () {
            if (player) return; // Already initialized
            const iframe = document.getElementById('video-player');
            if (!iframe || !iframe.src.includes('youtube.com')) return;

            player = new YT.Player('video-player', {
                events: {
                    'onStateChange': onPlayerStateChange,
                    'onReady': onPlayerReady
                }
            });
            window.dogologyPlayer = player;
        };

        // If the YouTube API already fired before this script loaded, initialize now
        if (window.ytApiReady && typeof YT !== 'undefined' && YT.Player) {
            window.initYouTubePlayer();
        }

        function onPlayerReady() {
            window.dogologyApiReady = true;
            startTracking();
            // If user clicked "Start Learning" before API was ready, play now
            if (window.dogologyPendingPlay) {
                window.dogologyPendingPlay = false;
                player.playVideo();
            }
        }

        function onPlayerStateChange(event) {
            const wrapper = document.getElementById('video-wrapper');
            const iconPlay = document.getElementById('icon-play');
            const iconPause = document.getElementById('icon-pause');

            // Small icons
            const iconPlaySmall = document.getElementById('icon-play-small');
            const iconPauseSmall = document.getElementById('icon-pause-small');

            if (event.data == YT.PlayerState.PLAYING) {
                wrapper.classList.remove('is-paused', 'is-ended', 'is-buffering');
                wrapper.classList.add('is-playing');

                // Dim poster
                const poster = document.getElementById('video-poster');
                if (poster) {
                    poster.style.opacity = '0';
                    setTimeout(() => poster.style.display = 'none', 500);
                }

                // Toggle Big Icons
                if (iconPlay) iconPlay.classList.add('hidden');
                if (iconPause) iconPause.classList.remove('hidden');

                // Toggle Small Icons
                if (iconPlaySmall) iconPlaySmall.classList.add('hidden');
                if (iconPauseSmall) iconPauseSmall.classList.remove('hidden');

                startTracking();
                resetHideTimeout(); // Start hiding timer when playing
            } else if (event.data == YT.PlayerState.PAUSED) {
                wrapper.classList.remove('is-playing', 'is-ended', 'is-buffering');
                wrapper.classList.add('is-paused');
                wrapper.classList.remove('hide-cursor'); // Show cursor on pause

                // Toggle Big Icons
                if (iconPlay) iconPlay.classList.remove('hidden');
                if (iconPause) iconPause.classList.add('hidden');

                // Toggle Small Icons
                if (iconPlaySmall) iconPlaySmall.classList.remove('hidden');
                if (iconPauseSmall) iconPauseSmall.classList.add('hidden');

                stopTracking();
                showControls(); // Ensure controls visible when paused
            } else if (event.data == YT.PlayerState.ENDED) {
                wrapper.classList.remove('is-playing', 'is-paused', 'hide-cursor', 'is-buffering');
                wrapper.classList.add('is-ended');

                // Reset Icons to Play
                if (iconPlay) iconPlay.classList.remove('hidden');
                if (iconPause) iconPause.classList.add('hidden');

                if (iconPlaySmall) iconPlaySmall.classList.remove('hidden');
                if (iconPauseSmall) iconPauseSmall.classList.add('hidden');

                stopTracking();
                showControls();
            } else if (event.data == YT.PlayerState.BUFFERING) {
                wrapper.classList.add('is-buffering');
                // Force controls to show during seek/buffer
                showTopBar();
                showControls();
            }
        }

        function startTracking() {
            if (updateInterval) clearInterval(updateInterval);
            updateInterval = setInterval(updateProgress, 500);
        }

        function stopTracking() {
            clearInterval(updateInterval);
        }

        function resetHideTimeout() {
            if (hideTimeout) clearTimeout(hideTimeout);

            const wrapper = document.getElementById('video-wrapper');
            if (!player || typeof player.getPlayerState !== 'function' || player.getPlayerState() !== YT.PlayerState.PLAYING) return;

            showControls();
            wrapper.classList.remove('hide-cursor');

            hideTimeout = setTimeout(() => {
                if (typeof player.getPlayerState === 'function' && player.getPlayerState() === YT.PlayerState.PLAYING) {
                    hideControls();
                    wrapper.classList.add('hide-cursor');

                    // Force hide top bar if it was in its "initial show" phase.
                    // Do NOT fade the logo shield here — its mask has to persist
                    // for the entire playback now that YT's bottom watermark
                    // renders unconditionally. CSS .is-playing rule handles it.
                    const topBar = document.getElementById('video-top-bar');

                    if (topBar && topBar.style.opacity === '1') {
                        topBar.style.setProperty('opacity', '0', 'important');
                        topBarShownOnce = true;
                    }
                }
            }, 3000); // 3 seconds

            // Independent Top Bar Sync: Only shows once at start
            if (!topBarShownOnce) {
                showTopBar();
            }
        }

        function showTopBar() {
            if (topBarShownOnce || topBarTimeout) return; // Don't reset if already scheduled

            const topBar = document.getElementById('video-top-bar');

            if (topBar) {
                topBar.style.setProperty('opacity', '1', 'important');

                // YouTube title bar hides after ~3-5 seconds of play or inactivity.
                // The logo shield is intentionally NOT touched here — its visibility
                // is driven by the .is-playing CSS rule so the bottom YT watermark
                // stays masked for the entire playback.
                topBarTimeout = setTimeout(() => {
                    if (player && typeof player.getPlayerState === 'function' && player.getPlayerState() === YT.PlayerState.PLAYING) {
                        topBar.style.setProperty('opacity', '0', 'important');
                        topBarShownOnce = true; // Mark as shown forever
                    }
                    topBarTimeout = null; // Clear handler reference
                }, 3000);
            }
        }

        // Global access for button clicks
        window.togglePlay = function () {
            if (!player || typeof player.getPlayerState !== 'function') return;
            const state = player.getPlayerState();
            if (state == YT.PlayerState.PLAYING) {
                player.pauseVideo();
            } else {
                player.playVideo();
            }
        };

        function showControls() {
            document.getElementById('video-wrapper').classList.add('user-interacting');
        }

        function hideControls() {
            document.getElementById('video-wrapper').classList.remove('user-interacting');
        }

        function updateProgress() {
            if (!player || !player.getCurrentTime || isDragging) return;

            const currentTime = player.getCurrentTime();
            const duration = player.getDuration();
            if (!duration) return;

            // Pre-emptive End Trigger: Show overlay 0.5s before actual end to race YT
            if (duration - currentTime <= 0.5 && currentTime > 0) {
                const wrapper = document.getElementById('video-wrapper');
                if (!wrapper.classList.contains('is-ended')) {
                    wrapper.classList.add('is-ended');
                    // Also hide controls to be clean
                    wrapper.classList.remove('is-playing', 'hide-cursor');
                    showControls();
                }
            }

            const percent = (currentTime / duration) * 100;

            document.getElementById('timeline-progress').style.width = percent + '%';
            document.getElementById('current-time').innerText = formatTime(currentTime);
            document.getElementById('duration-time').innerText = formatTime(duration);
        }

        function formatTime(seconds) {
            const mins = Math.floor(seconds / 60);
            const secs = Math.floor(seconds % 60);
            return mins + ':' + (secs < 10 ? '0' : '') + secs;
        }

        document.addEventListener('DOMContentLoaded', function () {
            // Scroll active lesson into view in sidebar
            var activeLesson = document.getElementById('active-lesson');
            if (activeLesson) {
                setTimeout(function () {
                    activeLesson.scrollIntoView({ block: 'center', behavior: 'smooth' });
                }, 300);
            }

            const overlay = document.getElementById('video-overlay');
            const wrapper = document.getElementById('video-wrapper');
            const timeline = document.getElementById('timeline-container');
            const btnFullscreen = document.getElementById('btn-fullscreen');
            const btnPlaySmall = document.getElementById('btn-play-small');

            if (wrapper) {
                const handleActivity = function () {
                    resetHideTimeout();
                };

                wrapper.onclick = handleActivity;
                wrapper.ontouchstart = handleActivity;
                wrapper.onmousemove = handleActivity;
            }

            // Click Layer Logic (Moved outside handleSeek to ensure it works immediately)
            const clickLayer = document.getElementById('video-click-layer');
            if (clickLayer) {
                clickLayer.addEventListener('click', (e) => {
                    // Logic: 
                    // 1. If controls hidden -> Show Controls
                    // 2. If controls visible -> Toggle Play

                    const wrapper = document.getElementById('video-wrapper');
                    const isInteracting = wrapper.classList.contains('user-interacting') || !wrapper.classList.contains('hide-cursor');

                    // Specific check: if paused, controls are usually visible but 'user-interacting' might be missing
                    // We trust 'user-interacting' class mainly for auto-hide state.

                    if (!isInteracting && player && typeof player.getPlayerState === 'function' && player.getPlayerState() === YT.PlayerState.PLAYING) {
                        // If playing and hidden -> just show controls
                        showTopBar();
                        showControls();
                        resetHideTimeout();
                    } else {
                        // If paused or controls visible -> Toggle
                        togglePlay();
                        showControls();
                        resetHideTimeout();
                    }
                });
            }

            // Keyboard Shortcuts: Spacebar for Play/Pause
            document.addEventListener('keydown', function (e) {
                // Focus check: only if not typing in an input
                if (e.code === 'Space' && e.target.tagName !== 'INPUT' && e.target.tagName !== 'TEXTAREA') {
                    e.preventDefault();
                    togglePlay();
                }
            });

            if (overlay) {
                overlay.onclick = function (e) {
                    e.stopPropagation();
                    togglePlay();
                };
            }

            if (btnPlaySmall) {
                btnPlaySmall.onclick = function (e) {
                    e.stopPropagation();
                    togglePlay();
                };
            }

            const poster = document.getElementById('video-poster');
            if (poster) {
                poster.setAttribute('onclick', 'handlePosterClick(event)');
            }

            // Seeking Logic
            if (timeline) {
                // Dim Layer Sync (Moved out of handleSeek to prevent leaks)
                const dimLayer = document.getElementById('video-dim-layer');
                if (dimLayer) {
                    const observer = new MutationObserver((mutations) => {
                        mutations.forEach((mutation) => {
                            if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
                                const isPaused = wrapper.classList.contains('is-paused');
                                const isEnded = wrapper.classList.contains('is-ended');
                                dimLayer.style.opacity = (isPaused || isEnded) ? '1' : '0';
                            }
                        });
                    });
                    observer.observe(wrapper, { attributes: true });
                }

                const handleSeek = (e) => {
                    if (!player || typeof player.getDuration !== 'function') return;
                    const rect = timeline.getBoundingClientRect();
                    const x = (e.clientX || (e.touches && e.touches[0] ? e.touches[0].clientX : 0)) - rect.left;
                    const percent = Math.min(Math.max(x / rect.width, 0), 1);
                    const time = player.getDuration() * percent;

                    document.getElementById('timeline-progress').style.width = (percent * 100) + '%';

                    if (typeof showControls === 'function') {
                        showControls();
                        showTopBar(); // Force top bar visible (masked)
                        resetHideTimeout();
                    }

                    // Pre-emptively set buffering state to cover any specific lags
                    document.getElementById('video-wrapper').classList.add('is-buffering');

                    if (typeof player.seekTo === 'function') {
                        player.seekTo(time, true);
                    }
                };

                timeline.onmousedown = (e) => {
                    isDragging = true;
                    document.getElementById('video-wrapper').classList.add('is-seeking');
                    showTopBar();
                    handleSeek(e);
                };

                window.onmousemove = (e) => {
                    if (isDragging) handleSeek(e);
                };

                window.onmouseup = () => {
                    isDragging = false;
                    document.getElementById('video-wrapper').classList.remove('is-seeking');
                };

                // Mobile
                timeline.ontouchstart = (e) => {
                    isDragging = true;
                    document.getElementById('video-wrapper').classList.add('is-seeking');
                    showTopBar();
                    handleSeek(e);
                };
                window.ontouchmove = (e) => {
                    if (isDragging) handleSeek(e);
                };
                window.ontouchend = () => {
                    isDragging = false;
                    document.getElementById('video-wrapper').classList.remove('is-seeking');
                };
            }

            // Fullscreen Logic
            if (btnFullscreen) {
                btnFullscreen.onclick = function (e) {
                    e.stopPropagation();

                    const isFSSupported = !!(wrapper.requestFullscreen || wrapper.webkitRequestFullscreen || wrapper.mozRequestFullScreen || wrapper.msRequestFullscreen);
                    const isFSEnabled = !!(document.fullscreenElement || document.webkitFullscreenElement || document.mozFullScreenElement || document.msFullscreenElement || wrapper.classList.contains('is-fullscreen'));
                    const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;

                    if (!isFSEnabled) {
                        // iOS often blocks iframe fullscreen API, forcing fallback
                        if (isIOS) {
                            document.body.classList.add('is-fullscreen-fallback');
                            wrapper.classList.add('is-fullscreen'); // Force UI update
                        } else if (isFSSupported) {
                            const requestMethod = wrapper.requestFullscreen || wrapper.webkitRequestFullscreen || wrapper.mozRequestFullScreen || wrapper.msRequestFullscreen;
                            requestMethod.call(wrapper).catch(err => {
                                // Fallback if API fails
                                document.body.classList.add('is-fullscreen-fallback');
                                wrapper.classList.add('is-fullscreen');
                            });
                        } else {
                            // Direct Fallback for non-supported browsers
                            document.body.classList.add('is-fullscreen-fallback');
                            wrapper.classList.add('is-fullscreen');
                        }
                    } else {
                        // Exit Fullscreen
                        const exitMethod = document.exitFullscreen || document.webkitExitFullscreen || document.mozCancelFullScreen || document.msExitFullscreen;
                        if (exitMethod) exitMethod.call(document);

                        // Always clear fallback classes just in case
                        document.body.classList.remove('is-fullscreen-fallback');
                        wrapper.classList.remove('is-fullscreen');
                    }
                };
            }

            // Sync Fullscreen Class
            const onFullscreenChange = () => {
                if (document.fullscreenElement || document.webkitFullscreenElement) {
                    wrapper.classList.add('is-fullscreen');
                } else {
                    wrapper.classList.remove('is-fullscreen');
                    document.body.classList.remove('is-fullscreen-fallback');
                }
            };
            document.addEventListener('fullscreenchange', onFullscreenChange);
            document.addEventListener('webkitfullscreenchange', onFullscreenChange);

            const btnComplete = document.getElementById('btn-mark-complete');
            if (btnComplete) {
                btnComplete.onclick = function () {
                    const isCompleted = btnComplete.dataset.completed === '1';
                    const nextState = isCompleted ? 0 : 1;

                    // UI Loading
                    btnComplete.disabled = true;
                    btnComplete.querySelector('.label-idle').classList.add('hidden');
                    btnComplete.querySelector('.label-loading').classList.remove('hidden');

                    const formData = new FormData();
                    formData.append('action', 'update_progress');
                    formData.append('course_id', '<?php echo $course_id; ?>');
                    formData.append('lesson_id', '<?php echo $lesson_id; ?>');
                    formData.append('completed', nextState);
                    formData.append('_dl_nonce', '<?php echo wp_create_nonce("dl_dashboard_action"); ?>');

                    fetch('<?php echo home_url("/?dl_route=dashboard"); ?>', {
                        method: 'POST',
                        body: formData
                    })
                        .then(r => r.json())
                        .then(data => {
                            if (data.success) {
                                btnComplete.dataset.completed = nextState;
                                btnComplete.querySelector('.label-idle').innerText = nextState ? '<?php echo esc_js($t['js_completed']); ?>' : '<?php echo esc_js($t['js_mark_complete']); ?>';

                                // Update Styles
                                if (nextState) {
                                    btnComplete.classList.remove('bg-gray-50', 'text-gray-600', 'border-gray-200');
                                    btnComplete.classList.add('bg-green-50', 'text-green-600', 'border-green-200');
                                } else {
                                    btnComplete.classList.remove('bg-green-50', 'text-green-600', 'border-green-200');
                                    btnComplete.classList.add('bg-gray-50', 'text-gray-600', 'border-gray-200');
                                }

                                // Update Progress Bar (Simple reload or update UI)
                                if (data.progress_percent !== undefined) {
                                    const bar = document.getElementById('progress-bar');
                                    const txt = document.getElementById('progress-text');
                                    if (bar) bar.style.width = data.progress_percent + '%';
                                    if (txt) txt.innerText = data.progress_percent + '%';
                                }
                            }
                        })
                        .catch(err => console.error(err))
                        .finally(() => {
                            btnComplete.disabled = false;
                            btnComplete.querySelector('.label-idle').classList.remove('hidden');
                            btnComplete.querySelector('.label-loading').classList.add('hidden');
                        });
                };
            }

            // Auto-Complete on Next Click
            const btnNext = document.getElementById('btn-next-lesson');
            if (btnNext) {
                btnNext.addEventListener('click', function (e) {
                    const btnComplete = document.getElementById('btn-mark-complete');
                    const isCompleted = btnComplete && btnComplete.dataset.completed === '1';

                    // If already completed, just go
                    if (isCompleted) return;

                    // If not completed, prevent default and mark as valid
                    e.preventDefault();
                    const nextUrl = this.href;

                    // Show some loading indication? Maybe just cursor wait
                    document.body.style.cursor = 'wait';

                    const formData = new FormData();
                    formData.append('action', 'update_progress');
                    formData.append('course_id', '<?php echo $course_id; ?>');
                    formData.append('lesson_id', '<?php echo $lesson_id; ?>');
                    formData.append('completed', '1');
                    formData.append('_dl_nonce', '<?php echo wp_create_nonce("dl_dashboard_action"); ?>');

                    fetch('<?php echo home_url("/?dl_route=dashboard"); ?>', {
                        method: 'POST',
                        body: formData
                    })
                        .then(() => {
                            window.location.href = nextUrl;
                        })
                        .catch(() => {
                            // Fallback navigation
                            window.location.href = nextUrl;
                        });
                });
            }
        });

        // User Menu Toggle
        function toggleUserMenu() {
            const menu = document.getElementById('user-dropdown');
            menu.classList.toggle('hidden');
        }

        // Sidebar Toggle (Mobile)
        function toggleSidebar() {
            const sidebar = document.getElementById('player-sidebar');
            const backdrop = document.getElementById('sidebar-backdrop');
            const isClosed = sidebar.classList.contains('-translate-x-full');

            if (isClosed) {
                sidebar.classList.remove('-translate-x-full');
                backdrop.classList.remove('hidden');
                document.body.style.overflow = 'hidden';
            } else {
                sidebar.classList.add('-translate-x-full');
                backdrop.classList.add('hidden');
                document.body.style.overflow = '';
            }
        }

        // Close menu when clicking outside
        document.addEventListener('click', function (event) {
            const dropdown = document.getElementById('user-dropdown');
            const button = document.querySelector('button[onclick="toggleUserMenu()"]');

            if (dropdown && !dropdown.classList.contains('hidden') && !dropdown.contains(event.target) && !button.contains(event.target)) {
                dropdown.classList.add('hidden');
            }
        });

        // Escape key to close dropdown
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                var dropdown = document.getElementById('user-dropdown');
                if (dropdown && !dropdown.classList.contains('hidden')) { dropdown.classList.add('hidden'); }
            }
        });

        // Global Sidebar Toggle Functions
        window.toggleSidebar = function () {
            const sidebar = document.getElementById('player-sidebar');
            const backdrop = document.getElementById('sidebar-backdrop');
            const isHidden = sidebar.classList.contains('-translate-x-full');

            if (isHidden) {
                sidebar.classList.remove('-translate-x-full');
                backdrop.classList.remove('hidden');
                document.body.style.overflow = 'hidden';
            } else {
                sidebar.classList.add('-translate-x-full');
                backdrop.classList.add('hidden');
                document.body.style.overflow = '';
            }
        };

        window.toggleDesktopSidebar = function () {
            const sidebar = document.getElementById('player-sidebar');
            const btnExpand = document.getElementById('btn-expand-sidebar');

            if (sidebar.classList.contains('sidebar-collapsed')) {
                sidebar.classList.remove('sidebar-collapsed');
                btnExpand.classList.add('hidden');
                btnExpand.classList.remove('flex');
                localStorage.setItem('dl_sidebar_collapsed', '0');
            } else {
                sidebar.classList.add('sidebar-collapsed');
                btnExpand.classList.remove('hidden');
                btnExpand.classList.add('flex');
                localStorage.setItem('dl_sidebar_collapsed', '1');
            }
            // Trigger resize for player to adjust
            setTimeout(() => window.dispatchEvent(new Event('resize')), 300);
        };

        // Restore sidebar state from localStorage (desktop only)
        (function () {
            if (window.innerWidth < 1024) return;
            if (localStorage.getItem('dl_sidebar_collapsed') === '1') {
                var s = document.getElementById('player-sidebar');
                var b = document.getElementById('btn-expand-sidebar');
                if (s && b) { s.classList.add('sidebar-collapsed'); b.classList.remove('hidden'); b.classList.add('flex'); }
            }
        })();
    </script>

    <!-- Offline Banner -->
    <div id="dl-offline-banner"
        style="display:none; position:fixed; bottom:0; left:0; right:0; z-index:9999; background:#ef4444; color:#fff; text-align:center; padding:10px; font-family:'Kanit',sans-serif; font-size:13px; font-weight:600;">
        <?php echo $current_lang === 'th' ? '⚠ ไม่มีการเชื่อมต่ออินเทอร์เน็ต' : '⚠ No internet connection'; ?>
    </div>
    <script>
        (function () {
            var b = document.getElementById('dl-offline-banner');
            if (!b) return;
            window.addEventListener('offline', function () { b.style.display = 'block'; });
            window.addEventListener('online', function () { b.style.display = 'none'; });
            if (!navigator.onLine) b.style.display = 'block';
        })();
    </script>
    <?php wp_footer(); ?>
</body>

</html>