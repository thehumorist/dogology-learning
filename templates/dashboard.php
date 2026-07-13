<?php
/**
 * Template: Student Dashboard
 * URL: /my-courses
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('DONOTCACHEPAGE')) {
    define('DONOTCACHEPAGE', true);
}

// 1. Auth Check
// 1. Auth Check
// Handle Token Handoff (Fix for LIFF dropped cookies)
if (isset($_GET['auth_token'])) {
    $token_parts = explode('|', $_GET['auth_token']); // uid|hash|ts
    if (count($token_parts) === 3) {
        $uid = intval($token_parts[0]);
        $hash = $token_parts[1];
        $ts = intval($token_parts[2]);

        // Verify Hash & Expiry (1 min window)
        $check = hash_hmac('sha256', $uid . $ts, DOGOLOGY_AUTH_SALT);
        if (time() < $ts && hash_equals($check, $hash)) {
            Dogology_Auth::login_student($uid);
            wp_redirect(home_url('/my-courses')); // Clean redirect
            exit;
        }
    }
}

$current_student = Dogology_Auth::get_current_student();
if (!$current_student) {
    wp_redirect(home_url('/student-login'));
    exit;
}

// 2. Language & Translations
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

    // Update DB
    $db_lang = new Dogology_Student_DB();
    $db_lang->update_student($current_student->id, array('language' => $current_lang));

    // Clean URL
    if (empty($_POST)) {
        wp_redirect(remove_query_arg('lang'));
        exit;
    }
}

$trans = [
    'th' => [
        'menu_edit' => 'แก้ไขข้อมูลส่วนตัว',
        'menu_edit_desc' => 'เปลี่ยนชื่อ, อีเมล, รูปโปรไฟล์',
        'menu_faceid' => 'เข้าสู่ระบบด้วยใบหน้า',
        'menu_enabled' => 'เปิดใช้งานแล้ว',
        'menu_enable_btn' => 'เปิดใช้งาน',
        'menu_logout' => 'ออกจากระบบ',
        'course_start' => 'เริ่มเรียน',
        'course_studying' => 'กำลังเรียน',
        'progress_label' => 'ความคืบหน้า',
        'lesson_unit' => 'บทเรียน',
        'modal_title' => 'แก้ไขข้อมูลส่วนตัว',
        'modal_save' => 'บันทึกข้อมูล',
        'modal_upload_hint' => 'คลิกเพื่อเปลี่ยนรูปโปรไฟล์',
        'label_name' => 'ชื่อที่ใช้แสดง',
        'label_email' => 'อีเมล'
    ],
    'en' => [
        'menu_edit' => 'Edit Profile',
        'menu_edit_desc' => 'Change name, email, avatar',
        'menu_faceid' => 'FaceID / TouchID Login',
        'menu_enabled' => 'Enabled',
        'menu_enable_btn' => 'Enable',
        'menu_logout' => 'Logout',
        'course_start' => 'Start Learning',
        'course_studying' => 'Studying',
        'progress_label' => 'Progress',
        'lesson_unit' => 'Lessons',
        'modal_title' => 'Edit Profile',
        'modal_save' => 'Save Changes',
        'modal_upload_hint' => 'Click to change photo',
        'label_name' => 'Display Name',
        'label_email' => 'Email Address'
    ]
];
$t = $trans[$current_lang];

$message_success = '';
$message_error = '';

// 2. Handle Actions (Passkey, Profile Update)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Update Profile
    if (isset($_POST['action']) && $_POST['action'] === 'update_profile') {
        if (!isset($_POST['_dl_nonce']) || !wp_verify_nonce($_POST['_dl_nonce'], 'dl_dashboard_action')) {
            $message_error = $current_lang === 'th' ? 'Token ไม่ถูกต้อง กรุณารีเฟรชหน้าแล้วลองใหม่' : 'Invalid security token. Please refresh and try again.';
        } else {
            $display_name = sanitize_text_field($_POST['display_name']);
            $email = sanitize_email($_POST['email']);

            if (is_email($email) && !empty($display_name)) {
                $db = new Dogology_Student_DB();

                // Handle File Upload (Avatar)
                $avatar_url = $current_student->profile_picture;
                if (!empty($_FILES['profile_image']['name'])) {
                    // Validate file type and size before processing
                    $allowed_types = array('image/jpeg', 'image/png', 'image/gif', 'image/webp');
                    $max_size = 2 * 1024 * 1024; // 2MB
                    $file_size = $_FILES['profile_image']['size'];

                    // Secure MIME validation (replaces client-provided type check)
                    $check = wp_check_filetype_and_ext($_FILES['profile_image']['tmp_name'], $_FILES['profile_image']['name']);
                    $is_valid_type = !empty($check['type']) && in_array($check['type'], $allowed_types);

                    if (!$is_valid_type) {
                        $message_error = $current_lang === 'th' ? 'ประเภทไฟล์ไม่ถูกต้อง รองรับเฉพาะ JPG, PNG, GIF และ WebP' : 'Invalid file type. Only JPG, PNG, GIF, and WebP are allowed.';
                    } elseif ($file_size > $max_size) {
                        $message_error = $current_lang === 'th' ? 'ไฟล์ใหญ่เกินไป ขนาดสูงสุด 2MB' : 'File too large. Maximum size is 2MB.';
                    } else {
                        require_once(ABSPATH . 'wp-admin/includes/image.php');
                        require_once(ABSPATH . 'wp-admin/includes/file.php');
                        require_once(ABSPATH . 'wp-admin/includes/media.php');

                        $attachment_id = media_handle_upload('profile_image', 0);
                        if (!is_wp_error($attachment_id)) {
                            $avatar_url = wp_get_attachment_url($attachment_id);
                        }
                    }
                }

                if (empty($message_error)) {
                    $update_data = array(
                        'display_name' => $display_name,
                        'profile_picture' => $avatar_url
                    );

                    // Only update email if changed; if verified email changes, reset verification
                    if ($email !== $current_student->email) {
                        $update_data['email'] = $email;
                        if (!empty($current_student->email_verified_at)) {
                            $update_data['email_verified_at'] = null;
                        }
                    }

                    $db->update_student($current_student->id, $update_data);
                    $current_student = $db->get_student($current_student->id); // Refresh
                    $message_success = $current_lang === 'th' ? 'บันทึกข้อมูลสำเร็จ!' : 'Profile updated successfully!';
                }
            } else {
                $message_error = $current_lang === 'th' ? 'อีเมลหรือชื่อไม่ถูกต้อง' : 'Invalid email or name.';
            }
        }
    }

    // Register Passkey
    if (isset($_POST['action']) && $_POST['action'] === 'register_passkey') {
        if (!isset($_POST['_dl_nonce']) || !wp_verify_nonce($_POST['_dl_nonce'], 'dl_dashboard_action')) {
            echo json_encode(array('success' => false, 'message' => $current_lang === 'th' ? 'Token ไม่ถูกต้อง' : 'Invalid security token'));
            exit;
        }
        $credential_id = sanitize_text_field($_POST['credential_id']);
        $db = new Dogology_Student_DB();

        // Dedup: reject if another student already claims this credential.
        // Without this, a leaked credential_id could be re-registered by an attacker.
        $already_taken = $db->get_student_by_passkey($credential_id);
        if ($already_taken && (int) $already_taken->id !== (int) $current_student->id) {
            echo json_encode(array('success' => false, 'message' => $current_lang === 'th' ? 'Passkey นี้ถูกใช้โดยบัญชีอื่นแล้ว' : 'This passkey is already registered to another account.'));
            exit;
        }

        $db->update_student($current_student->id, array('passkey_id' => $credential_id));
        echo json_encode(array('success' => true));
        exit;
    }

    // Update Progress (AJAX)
    if (isset($_POST['action']) && $_POST['action'] === 'update_progress') {
        if (!isset($_POST['_dl_nonce']) || !wp_verify_nonce($_POST['_dl_nonce'], 'dl_dashboard_action')) {
            wp_send_json_error($current_lang === 'th' ? 'Token ไม่ถูกต้อง' : 'Invalid security token');
        }
        $course_id = intval($_POST['course_id']);
        $lesson_id = intval($_POST['lesson_id']);
        $completed = intval($_POST['completed']);

        if ($course_id && $lesson_id) {
            $db = new Dogology_Student_DB();

            // Ownership guard: lesson must live in the course the client claims,
            // and the student must actually be enrolled in that course. Without this,
            // a logged-in student can mark any lesson of any course complete by
            // guessing IDs.
            $lesson_course = (int) get_post_meta($lesson_id, '_dogology_parent_course', true);
            if ($lesson_course !== $course_id) {
                wp_send_json_error($current_lang === 'th' ? 'บทเรียนไม่ตรงกับหลักสูตร' : 'Lesson does not belong to this course', 403);
            }
            $enrolled = $db->get_student_courses($current_student->id);
            $enrolled_ids = array_map(function ($c) { return (int) $c->ID; }, $enrolled);
            if (!in_array($course_id, $enrolled_ids, true)) {
                wp_send_json_error($current_lang === 'th' ? 'คุณไม่ได้ลงทะเบียนในหลักสูตรนี้' : 'Not enrolled in this course', 403);
            }

            $db->update_lesson_progress($current_student->id, $course_id, $lesson_id, $completed);

            // Recalculate course progress
            $lessons = $db->get_course_lessons($course_id);
            $total_lessons = count($lessons);
            $completed_count = 0;
            foreach ($lessons as $l) {
                if ($db->get_lesson_progress($current_student->id, $l->ID)) {
                    $completed_count++;
                }
            }
            $percent = $total_lessons > 0 ? round(($completed_count / $total_lessons) * 100) : 0;

            wp_send_json_success(array(
                'progress_percent' => $percent
            ));
        }
        wp_send_json_error();
    }
}

// 3. Fetch Data
$db = new Dogology_Student_DB();
$raw_courses = $db->get_student_courses($current_student->id);

$courses = array();
if ($raw_courses) {
    foreach ($raw_courses as $post) {
        // Fetch Lessons
        $lessons = $db->get_course_lessons($post->ID);
        $total_lessons = count($lessons);
        $first_lesson_id = !empty($lessons) ? $lessons[0]->ID : 0;

        // Calculate Progress
        $completed_count = 0;
        foreach ($lessons as $l) {
            if ($db->get_lesson_progress($current_student->id, $l->ID)) {
                $completed_count++;
            }
        }
        $progress_percent = $total_lessons > 0 ? round(($completed_count / $total_lessons) * 100) : 0;

        $courses[] = (object) array(
            'id' => $post->ID,
            'title' => $post->post_title,
            'subtitle' => get_post_meta($post->ID, '_dogology_subtitle', true) ?: ($post->post_excerpt ?: ($current_lang === 'th' ? 'หลักสูตรออนไลน์จาก Dogology' : 'Online course from Dogology')),
            'progress' => $progress_percent,
            'total_lessons' => $total_lessons,
            'completed_lessons' => $completed_count,
            'thumbnail' => get_the_post_thumbnail_url($post->ID, 'large') ?: 'https://placehold.co/600x400/00AB8E/ffffff?text=' . urlencode($post->post_title),
            'link' => home_url('/learn/' . $post->ID) // Let Player Router handle the "Start / Resume" logic
        );
    }
}

// 3b. Split enrolled items by format — ebooks get a different card (no lessons/progress).
$enrolled_ids = array_map('intval', wp_list_pluck($raw_courses ?: array(), 'ID'));
$my_courses = array();
$my_ebooks = array();
foreach ($courses as $c) {
    if (Dogology_Ebook::is_ebook($c->id)) {
        $c->download = home_url('/learn/download/' . $c->id);
        // ebooks render a portrait cover; the landscape placehold.co fallback
        // from the course build breaks that card — use the grey tile instead
        if ($c->thumbnail && strpos($c->thumbnail, 'placehold.co') !== false) {
            $c->thumbnail = '';
        }
        $my_ebooks[] = $c;
    } else {
        $my_courses[] = $c;
    }
}

// 3c. Store catalog — publicly-listed ('1') and coming-soon ('soon') items the
// student does NOT own. Owned items never appear here (library shows them first).
$catalog_locked_courses = array();
$catalog_locked_ebooks = array();
$listed = get_posts(array(
    'post_type' => 'dogology_course',
    'post_status' => 'publish',
    'numberposts' => -1,
    'orderby' => 'title',
    'order' => 'ASC',
    'meta_query' => array(array(
        'key' => '_dogology_public_listed',
        'value' => array('1', 'soon'),
        'compare' => 'IN',
    )),
));
foreach ($listed as $p) {
    if (in_array((int) $p->ID, $enrolled_ids, true)) {
        continue; // owned — lives in the library section
    }
    $vis = get_post_meta($p->ID, '_dogology_public_listed', true);
    $item = (object) array(
        'id' => $p->ID,
        'title' => $p->post_title,
        'subtitle' => get_post_meta($p->ID, '_dogology_subtitle', true) ?: ($p->post_excerpt ?: ''),
        'thumbnail' => get_the_post_thumbnail_url($p->ID, 'large') ?: '',
        'price_label' => get_post_meta($p->ID, '_dogology_price_label', true),
        'sales_url' => Dogology_Ebook::sales_url_for($p->ID),
        'soon' => ($vis === 'soon'),
        'archetype' => get_post_meta($p->ID, '_dogology_archetype', true),
    );
    if (Dogology_Ebook::is_ebook($p->ID)) {
        $catalog_locked_ebooks[] = $item;
    } else {
        $catalog_locked_courses[] = $item;
    }
}
$has_catalog = !empty($catalog_locked_courses) || !empty($catalog_locked_ebooks);
$library = array_merge($my_courses, $my_ebooks); // owned items, courses first

global $wpdb;
// 3d. MindMap result — latest entry for this student's LINE id. Direct table read
// with an existence guard so the dashboard degrades gracefully if the mindmap
// plugin is absent. Email fallback skipped (not indexed; rare case).
$mm_entries = array();
$mm_table = $wpdb->prefix . 'dogology_mindmap_entries';
$mm_table_exists = (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $mm_table));
// Local archetype name map — deliberately NOT coupled to mindmap plugin classes.
$mm_archetypes = array(
    'watchdog' => array('th' => 'หมาระแวง', 'en' => 'Reactive Watchdog'),
    'rocket'   => array('th' => 'หมาจรวด', 'en' => 'Rocket'),
    'shadow'   => array('th' => 'หมาเงา', 'en' => 'Shadow'),
    'indy'     => array('th' => 'หมาอินดี้', 'en' => 'Indy'),
    'hothead'  => array('th' => 'หมาใจร้อน', 'en' => 'Hothead'),
    'balanced' => array('th' => 'พื้นฐานแน่น', 'en' => 'Balanced'),
);
if ($mm_table_exists && !empty($current_student->line_uid)) {
    // Up to 10 most-recent entries: one LINE account can hold several dogs, or
    // retakes of the same dog. The block shows the latest and offers a selector.
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, archetype_id, access_token, score_data, dog_name, created_at
         FROM $mm_table WHERE user_id = %s ORDER BY created_at DESC LIMIT 10",
        $current_student->line_uid
    ));
    foreach ((array) $rows as $r) {
        if (!isset($mm_archetypes[$r->archetype_id])) {
            continue; // pre-archetype legacy rows can't render the block meaningfully
        }
        $decoded = $r->score_data ? json_decode($r->score_data, true) : null;
        $mm_entries[] = array(
            'id'      => (int) $r->id,
            'dog'     => (string) $r->dog_name,
            'arch'    => $r->archetype_id,
            'th'      => $mm_archetypes[$r->archetype_id]['th'],
            'en'      => $mm_archetypes[$r->archetype_id]['en'],
            'date'    => date_i18n('j M Y', strtotime($r->created_at)),
            'report'  => home_url('/dog-mindset-assessment/?entry_id=' . intval($r->id) . '&token=' . rawurlencode($r->access_token)),
            'scores'  => is_array($decoded) ? $decoded : null,
        );
    }
}
$mm_current = $mm_entries ? $mm_entries[0] : null; // latest entry drives the initial render + badge
$mm_quiz_url = home_url('/dog-mindset-assessment/');
// Signed retake link: lets a known student redo the quiz from a normal browser —
// the mindmap plugin verifies the HMAC, skips its LINE gate, and attaches this
// line_uid to the new entry (see webIdentity in dogology-mindmap.php). 1h expiry.
$mm_retake_url = '';
if ($mm_current && !empty($current_student->line_uid)) {
    $rk_exp = time() + HOUR_IN_SECONDS;
    $rk_sig = hash_hmac('sha256', $current_student->line_uid . '|' . $rk_exp, wp_salt('auth'));
    $mm_retake_url = add_query_arg(array(
        'retake' => rawurlencode($current_student->line_uid),
        'rk_exp' => $rk_exp,
        'rk_sig' => $rk_sig,
    ), $mm_quiz_url);
}
// Radar widget lives in the mindmap plugin (same drawing code as the report).
$mm_radar_js = defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/dogology-mindmap/assets/js/radar-widget.js')
    ? plugins_url('dogology-mindmap/assets/js/radar-widget.js') . '?v=' . DOGOLOGY_LEARNING_VERSION
    : '';

// 4. View Helpers
$user_initial = strtoupper(substr($current_student->display_name ?: $current_student->email, 0, 1));
$user_name = $current_student->display_name ?: 'Student';
$user_email = $current_student->email;
$has_passkey = !empty($current_student->passkey_id);
$is_email_verified = !empty($current_student->email) && !empty($current_student->email_verified_at);

// Check for Passkey creation success (from LIFF Bridge)
$show_passkey_toast = isset($_GET['passkey_created']) && $_GET['passkey_created'] == '1';

// Generate Passkey Bridge URL (for LIFF -> Safari handoff)
$passkey_bridge_url = Dogology_Auth::get_passkey_bridge_url($current_student->id);

// UI Options - Logo
$ui_logo_url = get_option('dl_logo_url', '');

// UI Options - Thai (Defaults)
$ui_dash_title_th = get_option('dl_dash_title', 'ห้องเรียนของฉัน');
$ui_dash_subtitle_th = get_option('dl_dash_subtitle', 'ยินดีต้อนรับกลับสู่การเรียนรู้');
$ui_empty_title_th = get_option('dl_empty_title') ?: 'ยังไม่มีคอร์สเรียน';
$ui_empty_desc_th = get_option('dl_empty_desc') ?: 'คุณเข้าสู่ระบบสำเร็จแล้ว แต่ยังไม่มีคอร์สที่ลงทะเบียนไว้ <br>เลือกดูคอร์สเรียนที่น่าสนใจเพื่อเริ่มฝึกน้องหมากันเถอะ';
$ui_btn_text_th = get_option('dl_btn_text') ?: 'ดูคอร์สเรียนทั้งหมด';

// UI Options - English (Defaults)
$ui_dash_title_en = get_option('dl_dash_title_en', 'My Classroom');
$ui_dash_subtitle_en = get_option('dl_dash_subtitle_en', 'Welcome back to learning');
$ui_empty_title_en = get_option('dl_empty_title_en') ?: 'No Courses Yet';
$ui_empty_desc_en = get_option('dl_empty_desc_en') ?: 'You\'re all set! You don\'t have any courses yet.<br>Browse our courses and start your dog training journey today.';
$ui_btn_text_en = get_option('dl_btn_text_en') ?: 'Browse Courses';

// Shared
$ui_btn_link = get_option('dl_btn_link') ?: '/';

// Determine Final UI Text based on Language
if ($current_lang === 'en') {
    $ui_dash_title = $ui_dash_title_en;
    $ui_dash_subtitle = $ui_dash_subtitle_en;
    $ui_empty_title = $ui_empty_title_en;
    $ui_empty_desc = $ui_empty_desc_en;
    $ui_btn_text = $ui_btn_text_en;
} else {
    $ui_dash_title = $ui_dash_title_th;
    $ui_dash_subtitle = $ui_dash_subtitle_th;
    $ui_empty_title = $ui_empty_title_th;
    $ui_empty_desc = $ui_empty_desc_th;
    $ui_btn_text = $ui_btn_text_th;
}

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>

<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?php echo esc_html($ui_dash_title); ?> - Dogology</title>

    <!-- Fonts -->
    <link
        href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">

    <style>
        html {
            margin-top: 0 !important;
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }
    </style>
    <?php wp_head(); ?>
    <!-- Dashboard stylesheet (Premium Minimalist) — AFTER wp_head so theme CSS
         (zento-child, dogology-theme, etc.) can't repaint our components -->
    <link rel="stylesheet" href="<?php echo esc_url(DOGOLOGY_LEARNING_URL . 'public/css/dl-dashboard.css?v=' . DOGOLOGY_LEARNING_VERSION); ?>">
    <style>
        /* Precision override for external 72px padding */
        html body {
            padding-top: 0 !important;
        }
    </style>

    <!-- Early Passkey Handler (needed before upsell toast renders) -->
    <script>
            var dlNonce = '<?php echo wp_create_nonce('dl_dashboard_action'); ?>';
            (function () {
                var isLiff = /Line/i.test(navigator.userAgent) || /LIFF/i.test(navigator.userAgent);
                var bridgeUrl = '<?php echo esc_url_raw($passkey_bridge_url); ?>';
                var userId = '<?php echo esc_js($current_student->id); ?>';

                // Check if user pressed "Later" before - hide upsell
                if (localStorage.getItem('passkey_upsell_dismissed_' + userId)) {
                    document.addEventListener('DOMContentLoaded', function () {
                        var upsell = document.getElementById('passkey-upsell');
                        if (upsell) upsell.style.display = 'none';
                    });
                }

                // Dismiss upsell and save preference
                window.dismissPasskeyUpsell = function () {
                    localStorage.setItem('passkey_upsell_dismissed_' + userId, '1');
                    var upsell = document.getElementById('passkey-upsell');
                    if (upsell) upsell.style.display = 'none';
                };

                // Inline passkey creation (doesn't depend on passkey.js)
                window.inlineCreatePasskey = async function () {
                    try {
                        var challenge = new Uint8Array(32);
                        window.crypto.getRandomValues(challenge);

                        var options = {
                            challenge: challenge,
                            rp: { name: 'Dogology Learning' },
                            user: {
                                id: Uint8Array.from(userId, function (c) { return c.charCodeAt(0); }),
                                name: window.dogologyUser ? (window.dogologyUser.email || 'user@dogology.org') : 'user@dogology.org',
                                displayName: window.dogologyUser ? (window.dogologyUser.displayName || 'User') : 'User'
                            },
                            pubKeyCredParams: [{ type: 'public-key', alg: -7 }],
                            timeout: 60000,
                            authenticatorSelection: { authenticatorAttachment: 'platform' }
                        };

                        var credential = await navigator.credentials.create({ publicKey: options });
                        var credentialId = btoa(String.fromCharCode.apply(null, new Uint8Array(credential.rawId)));

                        // Save to backend
                        var formData = new FormData();
                        formData.append('action', 'register_passkey');
                        formData.append('credential_id', credentialId);
                        formData.append('_dl_nonce', dlNonce);

                        var response = await fetch(window.location.pathname, { method: 'POST', body: formData });
                        var data = await response.json();

                        if (data.success) {
                            location.reload();
                        } else {
                            throw new Error('Failed to save passkey');
                        }
                    } catch (err) {
                        console.error('Passkey error:', err);
                        throw err;
                    }
                };

                // Universal passkey trigger
                window.triggerPasskey = async function () {
                    var btn = document.querySelector('#passkey-upsell .dl-btn-primary');
                    if (isLiff) {
                        try {
                            if (btn) {
                                btn.disabled = true;
                                btn.innerText = '<?php echo $current_lang === "th" ? "กำลังตั้งค่า..." : "Setting up..."; ?>';
                            }

                            const formData = new FormData();
                            formData.append('action', 'get_bridge_url');
                            const response = await fetch('<?php echo home_url('/student-login'); ?>', { method: 'POST', body: formData });
                            const data = await response.json();

                            if (data.success && data.url) {
                                if (typeof liff !== 'undefined' && liff.openWindow) {
                                    liff.openWindow({ url: data.url, external: true });
                                } else {
                                    window.open(data.url, '_blank');
                                }
                            } else {
                                throw new Error(data.message || 'Failed to get secure link');
                            }
                        } catch (err) {
                            console.error('Bridge error:', err);
                            var _m = document.getElementById('toast-error') || document.createElement('div');
                            _m.id = 'toast-error';
                            _m.className = 'dl-toast dl-toast--error';
                            _m.innerText = '<?php echo $current_lang === "th" ? "ไม่สามารถเริ่มการตั้งค่า" : "Failed to start setup"; ?>';
                            document.body.appendChild(_m);
                            setTimeout(function(){ _m.remove(); }, 4000);
                        } finally {
                            if (btn) {
                                btn.disabled = false;
                                btn.innerText = '<?php echo $current_lang === "th" ? "เปิดใช้งาน" : "Enable"; ?>';
                            }
                        }
                    } else {
                        // In normal browser: Use inline passkey creation
                        if (window.PublicKeyCredential) {
                            window.inlineCreatePasskey().catch(function (err) {
                                console.error('Passkey creation failed:', err);
                            });
                        } else {
                            var _m2 = document.getElementById('toast-error') || document.createElement('div');
                            _m2.id = 'toast-error';
                            _m2.className = 'dl-toast dl-toast--error';
                            _m2.innerText = '<?php echo $current_lang === "th" ? "เบราว์เซอร์นี้ไม่รองรับ Face ID / Touch ID" : "Face ID/Touch ID not supported on this browser."; ?>';
                            document.body.appendChild(_m2);
                            setTimeout(function(){ _m2.remove(); }, 4000);
                        }
                    }
                };
            })();
    </script>
</head>

<body class="dl-dash">

    <!-- Notifications -->
    <?php if ($message_success): ?>
        <div id="toast-success" class="dl-toast">
            ✅ <?php echo esc_html($message_success); ?>
        </div>
        <script>setTimeout(() => document.getElementById('toast-success').style.display = 'none', 3000);</script>
    <?php endif; ?>

    <?php if ($message_error): ?>
        <div id="toast-error" class="dl-toast dl-toast--error">
            <?php echo esc_html($message_error); ?>
        </div>
        <script>setTimeout(() => document.getElementById('toast-error').style.display = 'none', 4000);</script>
    <?php endif; ?>

    <!-- Passkey Created Toast (from LIFF Bridge) -->
    <?php if ($show_passkey_toast): ?>
        <div id="passkey-toast" class="dl-toast">
            ✅
            <?php echo $current_lang === 'th' ? 'เปิดใช้ Face ID สำเร็จ! คุณสามารถล็อกอินได้ทุกอุปกรณ์' : 'Face ID enabled! You can now log in on any device.'; ?>
        </div>
        <script>setTimeout(() => document.getElementById('passkey-toast').style.display = 'none', 5000);</script>
    <?php endif; ?>

    <!-- Verify Email Banner (Soft Gate) -->
    <?php if (!$is_email_verified): ?>
        <div class="dl-banner">
            <div class="dl-banner-inner">
                <span style="font-size:1.5rem">📧</span>
                <div class="dl-banner-text">
                    <div class="dl-banner-title">
                        <?php echo $current_lang === 'th' ? 'กรุณายืนยันอีเมลของคุณ' : 'Please verify your email'; ?>
                    </div>
                    <div class="dl-banner-sub">
                        <?php echo $current_lang === 'th' ? 'เพื่อรับลิงก์เอกสารประกอบและใบรับรองหลังจบคอร์ส' : 'To receive course materials and certificates'; ?>
                    </div>
                </div>
                <a href="<?php echo home_url('/student-login?step=onboarding'); ?>" class="dl-btn dl-btn--primary">
                    <?php echo $current_lang === 'th' ? 'ยืนยันเลย' : 'Verify Now'; ?>
                </a>
            </div>
        </div>
    <?php endif; ?>

    <!-- Passkey Upsell Toast (for users without passkey) -->
    <?php if ($is_email_verified && !$has_passkey): ?>
        <div id="passkey-upsell" class="dl-banner">
            <div class="dl-banner-inner">
                <span style="font-size:1.5rem">🔐</span>
                <div class="dl-banner-text">
                    <div class="dl-banner-title">
                        <?php echo $current_lang === 'th' ? 'ล็อกอินเร็วขึ้นด้วย Face ID' : 'Log in faster with Face ID'; ?>
                    </div>
                    <div class="dl-banner-sub">
                        <?php echo $current_lang === 'th' ? 'ไม่ต้องกรอกรหัสทุกครั้ง ปลอดภัยกว่า' : 'Skip password entry. More secure.'; ?>
                    </div>
                </div>
                <button onclick="window.triggerPasskey && window.triggerPasskey()" class="dl-btn dl-btn--primary">
                    <?php echo $current_lang === 'th' ? 'เปิดใช้งาน' : 'Enable'; ?>
                </button>
                <button onclick="window.dismissPasskeyUpsell && window.dismissPasskeyUpsell()"
                    style="background:none;border:none;cursor:pointer;font-size:0.8rem;color:#B45309;padding:8px 10px">
                    <?php echo $current_lang === 'th' ? 'ไว้ทีหลัง' : 'Later'; ?>
                </button>
                <button onclick="window.dismissPasskeyUpsell && window.dismissPasskeyUpsell()" aria-label="Dismiss"
                    style="background:none;border:none;cursor:pointer;color:#B45309;padding:4px;display:inline-flex">
                    <svg style="width:20px;height:20px" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12">
                        </path>
                    </svg>
                </button>
            </div>
        </div>
    <?php endif; ?>

    <div class="dl-wrap">

        <!-- GLOBAL HEADER -->
        <header class="dl-header"
            style="padding-top: max(1rem, env(safe-area-inset-top));">
            <!-- Logo Area (LINKED) -->
            <a href="<?php echo home_url(); ?>" class="dl-logo">
                <?php if ($ui_logo_url): ?>
                    <img src="<?php echo esc_url($ui_logo_url); ?>" alt="Logo">
                <?php else: ?>
                    <div class="dl-logo-mark">
                        D</div>
                    <span class="dl-logo-name">Dogology</span>
                <?php endif; ?>
            </a>

            <!-- Language Switcher (CENTERED) -->
            <div class="dl-lang">
                <a href="<?php echo esc_url(add_query_arg('lang', 'th')); ?>"
                    class="<?php echo $current_lang === 'th' ? 'is-active' : ''; ?>">
                    TH
                </a>
                <a href="<?php echo esc_url(add_query_arg('lang', 'en')); ?>"
                    class="<?php echo $current_lang === 'en' ? 'is-active' : ''; ?>">
                    EN
                </a>
            </div>

            <!-- User Profile Trigger -->
            <div style="position:relative">
                <button onclick="toggleUserMenu()" class="dl-user-btn">
                    <?php if ($current_student->profile_picture): ?>
                        <img src="<?php echo esc_url($current_student->profile_picture); ?>" class="dl-avatar">
                    <?php else: ?>
                        <div class="dl-avatar">
                            <?php echo $user_initial; ?>
                        </div>
                    <?php endif; ?>

                    <span class="dl-user-name"><?php echo esc_html($user_name); ?></span>
                    <span class="dl-caret">▼</span>
                </button>

                <!-- USER MENU DROPDOWN -->
                <div id="user-dropdown" class="dl-dropdown hidden">
                    <!-- Header -->
                    <div class="dl-dropdown-head">
                        <?php if ($current_student->profile_picture): ?>
                            <img src="<?php echo esc_url($current_student->profile_picture); ?>" class="dl-avatar">
                        <?php else: ?>
                            <div class="dl-avatar">
                                <?php echo $user_initial; ?>
                            </div>
                        <?php endif; ?>
                        <div class="dl-dropdown-who">
                            <h3 class="dl-dropdown-name">
                                <?php echo esc_html($user_name); ?>
                            </h3>
                            <p class="dl-dropdown-email">
                                <?php echo esc_html($user_email); ?>
                            </p>
                        </div>
                    </div>

                    <!-- Items (Full Width / No Gap) -->
                    <div>
                        <!-- Edit Profile -->
                        <button onclick="openProfileModal()" class="dl-dropdown-item">
                            <div class="dl-dropdown-item-main">
                                <div class="dl-dropdown-ic">
                                    ✏️</div>
                                <div>
                                    <div class="dl-dropdown-label">
                                        <?php echo esc_html($t['menu_edit']); ?>
                                    </div>
                                    <div class="dl-dropdown-sub"><?php echo esc_html($t['menu_edit_desc']); ?>
                                    </div>
                                </div>
                            </div>
                        </button>

                        <!-- FaceID Toggle -->
                        <div class="dl-dropdown-item">
                            <div class="dl-dropdown-item-main">
                                <div class="dl-dropdown-ic">
                                    🔒</div>
                                <div>
                                    <div class="dl-dropdown-label">
                                        <?php echo esc_html($t['menu_faceid']); ?>
                                    </div>
                                    <div class="dl-dropdown-sub">
                                        <?php echo $has_passkey ? esc_html($t['menu_enabled']) : esc_html($t['menu_faceid']); ?>
                                    </div>
                                </div>
                            </div>
                            <?php if ($has_passkey): ?>
                                <span class="dl-chip-on"><?php echo esc_html($t['menu_enabled']); ?></span>
                            <?php else: ?>
                                <button id="btn-register-passkey-menu" class="dl-btn dl-btn--primary"
                                    style="padding:7px 16px;font-size:0.75rem"><?php echo esc_html($t['menu_enable_btn']); ?></button>
                            <?php endif; ?>
                        </div>

                        <a href="<?php echo home_url('/student-logout?t=' . time()); ?>"
                            class="dl-dropdown-item dl-dropdown-item--danger">
                            <div class="dl-dropdown-item-main">
                                <div class="dl-dropdown-ic">
                                    🚪</div>
                                <span class="dl-dropdown-label"><?php echo esc_html($t['menu_logout']); ?></span>
                            </div>
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <!-- ================= MINDMAP BLOCK ================= -->
        <?php if ($mm_table_exists): ?>
            <?php if ($mm_current): ?>
                <!-- has result: real radar (same drawing code as the report) + link to the full report.
                     Multiple entries (several dogs / retakes) → selector switches the whole block client-side. -->
                <section class="dl-mm">
                    <div class="dl-mm-flex">
                        <div class="dl-mm-radar">
                            <div class="dl-mm-radar-label">
                                <?php echo $current_lang === 'th' ? 'MindMap ของน้อง' : 'Your dog\'s MindMap'; ?>
                            </div>
                            <canvas id="mm-mini-radar"></canvas>
                        </div>
                        <div class="dl-mm-main">
                            <div class="dl-mm-info">
                                <div class="dl-mm-eyebrow-row">
                                    <div class="dl-eyebrow">
                                        <?php echo $current_lang === 'th' ? 'ผลแบบประเมิน MindMap' : 'MindMap Assessment Result'; ?>
                                    </div>
                                    <?php if (count($mm_entries) > 1): ?>
                                        <select id="mm-entry-select" class="dl-mm-select">
                                            <?php foreach ($mm_entries as $i => $e): ?>
                                                <option value="<?php echo $i; ?>">
                                                    <?php echo esc_html(($e['dog'] ?: '-') . ' · ' . $e['date']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                </div>
                                <h2 class="dl-mm-title" id="mm-entry-title">
                                    <?php echo $mm_current['dog'] ? esc_html(($current_lang === 'th' ? 'น้อง' : '') . $mm_current['dog']) . ' · ' : ''; ?>
                                    <?php echo esc_html($mm_current['th']); ?>
                                    <small>(<?php echo esc_html($mm_current['en']); ?>)</small>
                                </h2>
                                <p class="dl-mm-date" id="mm-entry-date">
                                    <?php echo $current_lang === 'th' ? 'ประเมินเมื่อ' : 'Assessed'; ?>
                                    <?php echo esc_html($mm_current['date']); ?>
                                </p>
                            </div>
                            <div class="dl-mm-actions">
                                <a href="<?php echo esc_url($mm_current['report']); ?>" id="mm-entry-report"
                                    class="dl-btn dl-btn--primary">
                                    <?php echo $current_lang === 'th' ? 'ดูผลวิเคราะห์ฉบับเต็ม' : 'View full report'; ?>
                                </a>
                                <?php if ($mm_retake_url): ?>
                                    <a href="<?php echo esc_url($mm_retake_url); ?>"
                                        class="dl-btn dl-btn--ghost">
                                        <?php echo $current_lang === 'th' ? 'ทำแบบประเมินอีกครั้ง' : 'Retake assessment'; ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </section>
                <?php if ($mm_radar_js): ?>
                    <script src="<?php echo esc_url($mm_radar_js); ?>"></script>
                    <script>
                        (function () {
                            // JSON_HEX_TAG & co. stop a dog_name containing a closing
                            // script tag from breaking out of this inline script element.
                            var entries = <?php echo wp_json_encode($mm_entries, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
                            var isTh = <?php echo $current_lang === 'th' ? 'true' : 'false'; ?>;
                            function show(i) {
                                var e = entries[i];
                                if (!e) return;
                                if (window.DogologyRadar && e.scores) {
                                    DogologyRadar.render(document.getElementById('mm-mini-radar'), e.scores, { size: 150 });
                                }
                                // dog_name is user-entered — build the title with
                                // textContent, never innerHTML, so markup can't execute.
                                var lead = e.dog ? ((isTh ? 'น้อง' : '') + e.dog + ' · ') : '';
                                var titleEl = document.getElementById('mm-entry-title');
                                titleEl.textContent = lead + e.th + ' ';
                                var small = document.createElement('small');
                                small.textContent = '(' + e.en + ')';
                                titleEl.appendChild(small);
                                document.getElementById('mm-entry-date').textContent =
                                    (isTh ? 'ประเมินเมื่อ ' : 'Assessed ') + e.date;
                                document.getElementById('mm-entry-report').setAttribute('href', e.report);
                            }
                            var sel = document.getElementById('mm-entry-select');
                            if (sel) sel.addEventListener('change', function () { show(parseInt(this.value, 10)); });
                            show(0);
                        })();
                    </script>
                <?php endif; ?>
            <?php else: ?>
                <!-- no result yet: placeholder with the landing hero's static radar SVG -->
                <section class="dl-mm dl-mm--empty">
                        <div class="dl-mm-svg">
                            <svg viewBox="0 0 800 800" xmlns="http://www.w3.org/2000/svg" style="width:100%;height:auto;display:block">
                                <path d="M 400 400 L 400.00 92.80 A 307.20 307.20 0 0 1 517.56 116.18 Z" fill="#F67A72" stroke="white" stroke-width="2"/>
                                <path d="M 400 400 L 532.26 80.71 A 345.60 345.60 0 0 1 644.38 155.62 Z" fill="#F06192" stroke="white" stroke-width="2"/>
                                <path d="M 400 400 L 562.92 237.08 A 230.40 230.40 0 0 1 612.86 311.83 Z" fill="#BA67C8" stroke="white" stroke-width="2"/>
                                <path d="M 400 400 L 541.91 341.22 A 153.60 153.60 0 0 1 553.60 400.00 Z" fill="#9375CB" stroke="white" stroke-width="2"/>
                                <path d="M 400 400 L 668.80 400.00 A 268.80 268.80 0 0 1 648.34 502.87 Z" fill="#7785CB" stroke="white" stroke-width="2"/>
                                <path d="M 400 400 L 577.38 473.48 A 192.00 192.00 0 0 1 535.76 535.76 Z" fill="#63B5F8" stroke="white" stroke-width="2"/>
                                <path d="M 400 400 L 617.22 617.22 A 307.20 307.20 0 0 1 517.56 683.82 Z" fill="#4EC2F8" stroke="white" stroke-width="2"/>
                                <path d="M 400 400 L 444.09 506.43 A 115.20 115.20 0 0 1 400.00 515.20 Z" fill="#4BCFE1" stroke="white" stroke-width="2"/>
                                <path d="M 400 400 L 400.00 630.40 A 230.40 230.40 0 0 1 311.83 612.86 Z" fill="#4AB5AB" stroke="white" stroke-width="2"/>
                                <path d="M 400 400 L 297.13 648.34 A 268.80 268.80 0 0 1 209.93 590.07 Z" fill="#81C782" stroke="white" stroke-width="2"/>
                                <path d="M 400 400 L 182.78 617.22 A 307.20 307.20 0 0 1 116.18 517.56 Z" fill="#B9D995" stroke="white" stroke-width="2"/>
                                <path d="M 400 400 L 222.62 473.48 A 192.00 192.00 0 0 1 208.00 400.00 Z" fill="#DCE673" stroke="white" stroke-width="2"/>
                                <path d="M 400 400 L 246.40 400.00 A 153.60 153.60 0 0 1 258.09 341.22 Z" fill="#FFF275" stroke="white" stroke-width="2"/>
                                <path d="M 400 400 L 187.14 311.83 A 230.40 230.40 0 0 1 237.08 237.08 Z" fill="#FFD251" stroke="white" stroke-width="2"/>
                                <path d="M 400 400 L 209.93 209.93 A 268.80 268.80 0 0 1 297.13 151.66 Z" fill="#FFB54B" stroke="white" stroke-width="2"/>
                                <path d="M 400 400 L 282.44 116.18 A 307.20 307.20 0 0 1 400.00 92.80 Z" fill="#FF8964" stroke="white" stroke-width="2"/>
                                <circle cx="400" cy="400" r="96.00" fill="none" stroke="rgba(255,255,255,0.4)" stroke-width="2"/>
                                <circle cx="400" cy="400" r="192.00" fill="none" stroke="rgba(255,255,255,0.4)" stroke-width="2"/>
                                <circle cx="400" cy="400" r="288.00" fill="none" stroke="rgba(255,255,255,0.4)" stroke-width="2"/>
                                <circle cx="400" cy="400" r="384.00" fill="none" stroke="rgba(255,255,255,0.4)" stroke-width="2"/>
                            </svg>
                        </div>
                        <div class="dl-mm-info">
                            <h2>
                                <?php echo $current_lang === 'th' ? 'ยังไม่รู้ว่าน้องเป็นหมาแบบไหน?' : 'Not sure what kind of dog yours is?'; ?>
                            </h2>
                            <p>
                                <?php echo $current_lang === 'th' ? 'ทำแบบประเมิน MindMap ฟรี 5 นาที เพื่อดูโปรไฟล์ mindset ของน้อง แล้วเราจะแนะนำสิ่งที่เหมาะกับน้องที่สุด' : 'Take the free 5-minute MindMap assessment to see your dog\'s mindset profile.'; ?>
                            </p>
                        </div>
                        <a href="<?php echo esc_url($mm_quiz_url); ?>" class="dl-btn dl-btn--primary">
                            <?php echo $current_lang === 'th' ? 'ทำแบบประเมินฟรี' : 'Take the free assessment'; ?>
                        </a>
                </section>
            <?php endif; ?>
        <?php endif; ?>

        <!-- DASHBOARD CONTENT -->
        <?php if (empty($courses) && !$has_catalog): ?>
            <!-- EMPTY STATE (fallback only: nothing enrolled AND nothing listed) -->
            <div class="dl-empty">
                <div class="dl-empty-card">
                    <div class="dl-empty-ic">🎓</div>
                    <h2><?php echo esc_html($ui_empty_title); ?></h2>
                    <p>
                        <?php echo wp_kses_post($ui_empty_desc); ?>
                    </p>
                    <a href="<?php echo esc_url($ui_btn_link); ?>" class="dl-btn dl-btn--primary">
                        <?php echo esc_html($ui_btn_text); ?>
                        <svg style="width:16px;height:16px" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                    </a>
                </div>
                <p class="dl-empty-foot">
                    <?php echo $current_lang === 'th' ? 'หากคุณเพิ่งซื้อคอร์ส กรุณารอสักครู่แล้วรีเฟรชหน้านี้' : 'If you just purchased a course, please wait a moment and refresh this page.'; ?>
                </p>
            </div>
        <?php else: ?>
            <!-- TITLE BLOCK -->
            <div class="dl-page-title">
                <h1>
                    <?php echo esc_html($ui_dash_title); ?>
                </h1>
                <p><?php echo esc_html($ui_dash_subtitle); ?></p>
            </div>

            <!-- ================= LIBRARY: everything owned, first ================= -->
            <?php if (!empty($library)): ?>
            <div class="dl-section-head">
                <h2><?php echo $current_lang === 'th' ? 'ของฉัน' : 'My Library'; ?></h2>
                <span class="dl-section-rule"></span>
            </div>

            <div class="dl-grid">
                <?php foreach ($my_courses as $course): ?>

                    <!-- Active/Premium Card Style (Matching Mockup) -->
                    <div class="dl-card">
                        <!-- Thumbnail -->
                        <div class="dl-card-media">
                            <img src="<?php echo esc_url($course->thumbnail); ?>">
                            <!-- Badge -->
                            <?php if ($course->progress >= 100): ?>
                                <span class="dl-tag dl-tag--done">
                                    🎉 <?php echo $current_lang === 'th' ? 'เรียนจบแล้ว!' : 'Completed!'; ?>
                                </span>
                            <?php else: ?>
                                <span class="dl-tag dl-tag--active">
                                    <?php echo esc_html($t['course_studying']); ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="dl-card-body">
                            <h3 class="dl-card-title">
                                <?php echo esc_html($course->title); ?>
                            </h3>
                            <p class="dl-card-sub">
                                <?php echo esc_html($course->subtitle); ?>
                            </p>

                            <div class="dl-card-foot">
                                <!-- Progress Stats -->
                                <div class="dl-progress-meta">
                                    <span><?php echo esc_html($t['progress_label']); ?>         <?php echo $course->progress; ?>%</span>
                                    <span class="dl-progress-count"><?php echo $course->completed_lessons; ?>/<?php echo $course->total_lessons . ' ' . esc_html($t['lesson_unit']); ?></span>
                                </div>

                                <!-- Progress Bar -->
                                <div class="dl-progress-track">
                                    <div class="dl-progress-fill"
                                        style="width: <?php echo $course->progress; ?>%">
                                    </div>
                                </div>

                                <!-- Action Button -->
                                <a href="<?php echo esc_url($course->link); ?>"
                                    class="dl-btn dl-btn--primary dl-btn--block">
                                    <?php echo esc_html($t['course_start']); ?>
                                </a>
                            </div>
                        </div>
                    </div>

                <?php endforeach; ?>

                <?php foreach ($my_ebooks as $eb): ?>
                    <!-- Owned ebook (library): portrait cover, no progress, download button -->
                    <div class="dl-card">
                        <div class="dl-card-media dl-card-media--book">
                            <?php if ($eb->thumbnail): ?>
                                <img src="<?php echo esc_url($eb->thumbnail); ?>" alt="">
                            <?php else: ?>
                                <div class="dl-book-placeholder"><?php echo esc_html($eb->title); ?></div>
                            <?php endif; ?>
                            <span class="dl-tag dl-tag--active">E-BOOK</span>
                        </div>
                        <div class="dl-card-body">
                            <h3 class="dl-card-title"><?php echo esc_html($eb->title); ?></h3>
                            <p class="dl-card-sub"><?php echo esc_html($eb->subtitle); ?></p>
                            <div class="dl-card-foot">
                                <a href="<?php echo esc_url($eb->download); ?>"
                                    class="dl-btn dl-btn--primary dl-btn--block">
                                    ⬇ <?php echo $current_lang === 'th' ? 'อ่าน / ดาวน์โหลด' : 'Read / Download'; ?>
                                </a>
                                <p class="dl-card-hint">
                                    <?php echo $current_lang === 'th' ? 'ไฟล์ PDF ระบุชื่อ · ดาวน์โหลดซ้ำได้เสมอ' : 'Personalized PDF · re-download anytime'; ?>
                                </p>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- ================= STORE: everything not owned ================= -->
            <?php if ($has_catalog): ?>
                <div class="dl-store-head<?php echo empty($library) ? ' dl-store-head--solo' : ''; ?>">
                    <h2>
                        <?php echo $current_lang === 'th' ? 'คอร์สและ E-Book จาก Dogology' : 'Courses & E-Books from Dogology'; ?>
                    </h2>
                </div>

                <?php if (!empty($catalog_locked_courses)): ?>
                    <div class="dl-section-head">
                        <h2><?php echo $current_lang === 'th' ? 'คอร์สเรียน' : 'Courses'; ?></h2>
                        <span class="dl-section-rule"></span>
                    </div>
                    <div class="dl-grid">
                        <?php foreach ($catalog_locked_courses as $item): ?>
                            <div class="dl-card dl-card--locked<?php echo $item->soon ? ' dl-card--soon' : ''; ?>">
                                <div class="dl-card-media">
                                    <?php if ($item->thumbnail): ?>
                                        <img src="<?php echo esc_url($item->thumbnail); ?>">
                                    <?php else: ?>
                                        <div style="width:100%;height:100%;background:#F1F5F9;display:flex;align-items:center;justify-content:center">
                                            <span style="color:#94A3B8"><?php echo esc_html($item->title); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($item->soon): ?>
                                        <span class="dl-tag dl-tag--soon"><?php echo $current_lang === 'th' ? 'เร็ว ๆ นี้' : 'Coming soon'; ?></span>
                                    <?php else: ?>
                                        <span class="dl-tag dl-tag--locked">🔒 <?php echo $current_lang === 'th' ? 'ยังไม่ได้ลงทะเบียน' : 'Not enrolled'; ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="dl-card-body">
                                    <h3 class="dl-card-title"><?php echo esc_html($item->title); ?></h3>
                                    <p class="dl-card-sub"><?php echo esc_html($item->subtitle); ?></p>
                                    <div class="dl-card-foot">
                                        <?php if ($item->soon): ?>
                                            <span class="dl-soon-note"><?php echo $current_lang === 'th' ? 'กำลังเตรียม...' : 'In preparation...'; ?></span>
                                        <?php else: ?>
                                            <?php if ($item->price_label): ?>
                                                <div class="dl-price-row">
                                                    <span class="dl-price"><?php echo esc_html($item->price_label); ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($item->sales_url): ?>
                                                <a href="<?php echo esc_url($item->sales_url); ?>"
                                                    class="dl-btn dl-btn--ghost dl-btn--block">
                                                    <?php echo $current_lang === 'th' ? 'ดูรายละเอียดคอร์ส' : 'View course'; ?>
                                                </a>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($catalog_locked_ebooks)): ?>
                    <div class="dl-section-head">
                        <h2>E-Book</h2>
                        <span class="dl-section-note"><?php echo $current_lang === 'th' ? 'คู่มือประจำ archetype จากผล MindMap' : 'Archetype guides from your MindMap result'; ?></span>
                        <span class="dl-section-rule"></span>
                    </div>
                    <div class="dl-grid">
                        <?php foreach ($catalog_locked_ebooks as $item): ?>
                            <?php $recommended = !$item->soon && $mm_current && $item->archetype && $item->archetype === $mm_current['arch']; ?>
                            <div class="dl-card <?php echo $item->soon ? 'dl-card--locked dl-card--soon' : ($recommended ? 'dl-card--recommended' : 'dl-card--locked'); ?>">
                                <div class="dl-card-media dl-card-media--book">
                                    <?php if ($item->thumbnail): ?>
                                        <img src="<?php echo esc_url($item->thumbnail); ?>" alt="">
                                    <?php else: ?>
                                        <div class="dl-book-placeholder"><?php echo esc_html($item->title); ?></div>
                                    <?php endif; ?>
                                    <?php if ($item->soon): ?>
                                        <span class="dl-tag dl-tag--soon"><?php echo $current_lang === 'th' ? 'เร็ว ๆ นี้' : 'Coming soon'; ?></span>
                                    <?php elseif ($recommended): ?>
                                        <span class="dl-tag dl-tag--recommended">
                                            ★ <?php echo $current_lang === 'th' ? 'แนะนำสำหรับ' . $mm_current['th'] : 'For your ' . $mm_current['en']; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="dl-tag dl-tag--locked">🔒 E-BOOK</span>
                                    <?php endif; ?>
                                </div>
                                <div class="dl-card-body">
                                    <h3 class="dl-card-title"><?php echo esc_html($item->title); ?></h3>
                                    <p class="dl-card-sub"><?php echo esc_html($item->subtitle); ?></p>
                                    <div class="dl-card-foot">
                                        <?php if ($item->soon): ?>
                                            <span class="dl-soon-note"><?php echo $current_lang === 'th' ? 'กำลังเขียน...' : 'Being written...'; ?></span>
                                        <?php else: ?>
                                            <?php if ($item->price_label): ?>
                                                <div class="dl-price-row">
                                                    <span class="dl-price"><?php echo esc_html($item->price_label); ?></span>
                                                    <span class="dl-price-note">PDF</span>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($item->sales_url): ?>
                                                <a href="<?php echo esc_url($item->sales_url); ?>"
                                                    class="dl-btn <?php echo $recommended ? 'dl-btn--primary' : 'dl-btn--ghost'; ?> dl-btn--block">
                                                    <?php echo $current_lang === 'th' ? 'ดูรายละเอียด / สั่งซื้อ' : 'Details / Buy'; ?>
                                                </a>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>

    </div>

    <!-- PROFILE MODAL -->
    <div id="profile-modal" class="dl-modal-backdrop is-open hidden">
        <div class="dl-modal">
            <div style="display:flex;justify-content:space-between;align-items:center">
                <h3><?php echo esc_html($t['modal_title']); ?></h3>
                <button onclick="closeProfileModal()" aria-label="Close"
                    style="background:none;border:none;cursor:pointer;font-size:1rem;color:#64748B;width:32px;height:32px;border-radius:50%">✕</button>
            </div>

            <form method="post" enctype="multipart/form-data" onsubmit="var b=this.querySelector('button[type=submit]'); b.disabled=true; b.innerText='...'">
                <input type="hidden" name="action" value="update_profile">
                <input type="hidden" name="_dl_nonce" value="<?php echo wp_create_nonce('dl_dashboard_action'); ?>">

                <!-- Avatar Upload -->
                <div style="display:flex;flex-direction:column;align-items:center;gap:10px;margin:10px 0 20px">
                    <div style="position:relative;cursor:pointer"
                        onclick="document.getElementById('profile_image').click()"
                        onmouseover="this.lastElementChild.style.opacity='1'"
                        onmouseout="this.lastElementChild.style.opacity='0'">
                        <?php if ($current_student->profile_picture): ?>
                            <img id="preview_avatar" src="<?php echo esc_url($current_student->profile_picture); ?>"
                                class="dl-avatar" style="width:96px;height:96px">
                        <?php else: ?>
                            <div id="preview_avatar_div" class="dl-avatar" style="width:96px;height:96px;font-size:1.9rem">
                                <?php echo $user_initial; ?>
                            </div>
                        <?php endif; ?>
                        <div style="position:absolute;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.3);border-radius:50%;display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity 0.2s ease">
                            <span style="color:#fff;font-size:0.72rem;font-weight:700"><?php echo esc_html($t['modal_upload_hint']); ?></span>
                        </div>
                    </div>
                    <input type="file" name="profile_image" id="profile_image" class="hidden" accept="image/*"
                        onchange="previewImage(this)">
                    <p style="font-size:0.72rem;color:#94A3B8"><?php echo esc_html($t['modal_upload_hint']); ?></p>
                </div>

                <div>
                    <label><?php echo esc_html($t['label_name']); ?></label>
                    <input type="text" name="display_name"
                        value="<?php echo esc_attr($current_student->display_name); ?>"
                        required>
                </div>

                <div>
                    <label><?php echo esc_html($t['label_email']); ?></label>
                    <input type="email" name="email" value="<?php echo esc_attr($current_student->email); ?>"
                        <?php echo $is_email_verified ? 'style="background:#F8FAFC"' : ''; ?>
                        required>
                    <?php if ($is_email_verified): ?>
                        <p style="font-size:0.68rem;color:#D97706;margin-top:4px"><?php echo $current_lang === 'th' ? '⚠ การเปลี่ยนอีเมลจะต้องยืนยันใหม่' : '⚠ Changing email will require re-verification'; ?></p>
                    <?php endif; ?>
                </div>

                <button type="submit" class="dl-btn dl-btn--primary dl-btn--block"><?php echo esc_html($t['modal_save']); ?></button>
            </form>
        </div>
    </div>

    <!-- JS -->
    <script>
        function toggleUserMenu() {
            const menu = document.getElementById('user-dropdown');
            menu.classList.toggle('hidden');
        }

        function openProfileModal() {
            document.getElementById('profile-modal').classList.remove('hidden');
            document.getElementById('profile-modal').classList.add('flex');
            document.getElementById('user-dropdown').classList.add('hidden'); // Close menu
        }

        function closeProfileModal() {
            document.getElementById('profile-modal').classList.add('hidden');
            document.getElementById('profile-modal').classList.remove('flex');
        }

        // Focus trap for profile modal
        document.addEventListener('keydown', function(e) {
            if (e.key !== 'Tab') return;
            var modal = document.getElementById('profile-modal');
            if (!modal || modal.classList.contains('hidden')) return;
            var focusable = modal.querySelectorAll('button, input, [tabindex]:not([tabindex="-1"])');
            if (!focusable.length) return;
            var first = focusable[0], last = focusable[focusable.length - 1];
            if (e.shiftKey) { if (document.activeElement === first) { e.preventDefault(); last.focus(); } }
            else { if (document.activeElement === last) { e.preventDefault(); first.focus(); } }
        });

        function previewImage(input) {
            if (input.files && input.files[0]) {
                var reader = new FileReader();
                reader.onload = function (e) {
                    var img = document.getElementById('preview_avatar');
                    if (img) {
                        img.src = e.target.result;
                    } else {
                        var div = document.getElementById('preview_avatar_div');
                        if (div) {
                            var newImg = document.createElement('img');
                            newImg.id = 'preview_avatar';
                            newImg.src = e.target.result;
                            newImg.className = 'dl-avatar';
                            newImg.style.cssText = 'width:96px;height:96px';
                            div.parentNode.replaceChild(newImg, div);
                        }
                    }
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function (event) {
            const dropdown = document.getElementById('user-dropdown');
            const button = document.querySelector('button[onclick="toggleUserMenu()"]');
            const modal = document.getElementById('profile-modal');

            // Close dropdown
            if (dropdown && !dropdown.classList.contains('hidden') && !dropdown.contains(event.target) && !button.contains(event.target)) {
                dropdown.classList.add('hidden');
            }

            // Close modal on backdrop click
            if (modal && !modal.classList.contains('hidden') && event.target === modal) {
                closeProfileModal();
            }
        });

        // Escape key to close dropdown and modal
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                var dropdown = document.getElementById('user-dropdown');
                if (dropdown && !dropdown.classList.contains('hidden')) { dropdown.classList.add('hidden'); return; }
                var modal = document.getElementById('profile-modal');
                if (modal && !modal.classList.contains('hidden')) { closeProfileModal(); }
            }
        });
    </script>
    <script>
        window.dogologyUser = {
            id: "<?php echo $current_student->id; ?>",
            email: "<?php echo esc_js($current_student->email); ?>",
            username: "<?php echo esc_js(!empty($current_student->email) ? $current_student->email : (!empty($current_student->line_uid) ? $current_student->line_uid : 'user_' . $current_student->id)); ?>",
            displayName: "<?php echo esc_js($current_student->display_name); ?>"
        };
    </script>
    <script src="<?php
    $script_path = plugin_dir_path(dirname(__DIR__)) . 'public/js/passkey.js';
    $ver = file_exists($script_path) ? filemtime($script_path) : (defined('DOGOLOGY_LEARNING_VERSION') ? DOGOLOGY_LEARNING_VERSION : time());
    echo DOGOLOGY_LEARNING_URL . 'public/js/passkey.js?v=' . $ver;
    ?>"></script>

    <!-- Menu Button Passkey Binding (uses triggerPasskey from head) -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var menuBtn = document.getElementById('btn-register-passkey-menu');
            if (menuBtn) {
                menuBtn.onclick = function (e) {
                    e.preventDefault();
                    window.triggerPasskey();
                };
            }
        });
    </script>
    <!-- Offline Banner -->
    <div id="dl-offline-banner" style="display:none; position:fixed; bottom:0; left:0; right:0; z-index:9999; background:#ef4444; color:#fff; text-align:center; padding:10px; font-family:'Kanit',sans-serif; font-size:13px; font-weight:600;">
        <?php echo $current_lang === 'th' ? '⚠ ไม่มีการเชื่อมต่ออินเทอร์เน็ต' : '⚠ No internet connection'; ?>
    </div>
    <script>
    (function(){
        var b = document.getElementById('dl-offline-banner');
        if (!b) return;
        window.addEventListener('offline', function(){ b.style.display='block'; });
        window.addEventListener('online', function(){ b.style.display='none'; });
        if (!navigator.onLine) b.style.display='block';
    })();
    </script>
    <?php wp_footer(); ?>
</body>

</html>