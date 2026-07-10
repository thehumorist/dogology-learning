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
        $my_ebooks[] = $c;
    } else {
        $my_courses[] = $c;
    }
}

// 3c. Catalog — every publicly-listed course/ebook; unpurchased ones render locked.
// (_dogology_public_listed is the per-product visibility ticker, set in Courses admin.)
$catalog_locked_courses = array();
$catalog_locked_ebooks = array();
$listed = get_posts(array(
    'post_type' => 'dogology_course',
    'post_status' => 'publish',
    'numberposts' => -1,
    'orderby' => 'title',
    'order' => 'ASC',
    'meta_key' => '_dogology_public_listed',
    'meta_value' => '1',
));
foreach ($listed as $p) {
    if (in_array((int) $p->ID, $enrolled_ids, true)) {
        continue; // already shown as an enrolled card
    }
    $item = (object) array(
        'id' => $p->ID,
        'title' => $p->post_title,
        'subtitle' => get_post_meta($p->ID, '_dogology_subtitle', true) ?: ($p->post_excerpt ?: ''),
        'thumbnail' => get_the_post_thumbnail_url($p->ID, 'large') ?: 'https://placehold.co/600x400/94a3b8/ffffff?text=' . urlencode($p->post_title),
        'price_label' => get_post_meta($p->ID, '_dogology_price_label', true),
        'sales_url' => Dogology_Ebook::sales_url_for($p->ID),
    );
    if (Dogology_Ebook::is_ebook($p->ID)) {
        $catalog_locked_ebooks[] = $item;
    } else {
        $catalog_locked_courses[] = $item;
    }
}
$has_ebook_section = !empty($my_ebooks) || !empty($catalog_locked_ebooks);
$has_catalog = !empty($catalog_locked_courses) || !empty($catalog_locked_ebooks);

global $wpdb;
// 3d. MindMap result — latest entry for this student's LINE id. Direct table read
// with an existence guard so the dashboard degrades gracefully if the mindmap
// plugin is absent. Email fallback skipped (not indexed; rare case).
$mm_entry = null;
$mm_scores = null;
$mm_table = $wpdb->prefix . 'dogology_mindmap_entries';
$mm_table_exists = (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $mm_table));
if ($mm_table_exists && !empty($current_student->line_uid)) {
    $mm_entry = $wpdb->get_row($wpdb->prepare(
        "SELECT id, archetype_id, access_token, score_data, dog_name, created_at
         FROM $mm_table WHERE user_id = %s ORDER BY created_at DESC LIMIT 1",
        $current_student->line_uid
    ));
    if ($mm_entry && $mm_entry->score_data) {
        $decoded = json_decode($mm_entry->score_data, true);
        $mm_scores = is_array($decoded) ? $decoded : null;
    }
}
// Local archetype name map — deliberately NOT coupled to mindmap plugin classes.
$mm_archetypes = array(
    'watchdog' => array('th' => 'หมาระแวง', 'en' => 'Reactive Watchdog'),
    'rocket'   => array('th' => 'หมาจรวด', 'en' => 'Rocket'),
    'shadow'   => array('th' => 'หมาเงา', 'en' => 'Shadow'),
    'indy'     => array('th' => 'หมาอินดี้', 'en' => 'Indy'),
    'hothead'  => array('th' => 'หมาใจร้อน', 'en' => 'Hothead'),
    'balanced' => array('th' => 'พื้นฐานแน่น', 'en' => 'Balanced'),
);
$mm_name = ($mm_entry && isset($mm_archetypes[$mm_entry->archetype_id])) ? $mm_archetypes[$mm_entry->archetype_id] : null;
$mm_report_url = $mm_entry ? home_url('/dog-mindset-assessment/?entry_id=' . intval($mm_entry->id) . '&token=' . rawurlencode($mm_entry->access_token)) : '';
$mm_quiz_url = home_url('/dog-mindset-assessment/');
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

    <!-- Tailwind -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#00AB8E',
                        secondary: '#0076BA',
                    },
                    fontFamily: {
                        kanit: ['Kanit', 'sans-serif'],
                        body: ['Noto Sans Thai', 'Kanit', 'sans-serif']
                    }
                }
            }
        }
    </script>
    <style>
        html {
            margin-top: 0 !important;
        }

        body {
            font-family: 'Noto Sans Thai', 'Kanit', sans-serif;
            background-color: #f8fafc;
        }

        .font-kanit {
            font-family: 'Kanit', sans-serif;
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
                            _m.className = 'fixed top-5 left-1/2 transform -translate-x-1/2 bg-red-500 text-white px-6 py-3 rounded-full shadow-lg z-50 font-kanit';
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
                            _m2.className = 'fixed top-5 left-1/2 transform -translate-x-1/2 bg-red-500 text-white px-6 py-3 rounded-full shadow-lg z-50 font-kanit';
                            _m2.innerText = '<?php echo $current_lang === "th" ? "เบราว์เซอร์นี้ไม่รองรับ Face ID / Touch ID" : "Face ID/Touch ID not supported on this browser."; ?>';
                            document.body.appendChild(_m2);
                            setTimeout(function(){ _m2.remove(); }, 4000);
                        }
                    }
                };
            })();
    </script>
</head>

<body class="text-[#44403c] antialiased">

    <!-- Notifications -->
    <?php if ($message_success): ?>
        <div id="toast-success"
            class="fixed top-5 left-1/2 transform -translate-x-1/2 bg-green-500 text-white px-6 py-3 rounded-full shadow-lg z-50 font-kanit animate-bounce">
            ✅ <?php echo esc_html($message_success); ?>
        </div>
        <script>setTimeout(() => document.getElementById('toast-success').style.display = 'none', 3000);</script>
    <?php endif; ?>

    <?php if ($message_error): ?>
        <div id="toast-error"
            class="fixed top-5 left-1/2 transform -translate-x-1/2 bg-red-500 text-white px-6 py-3 rounded-full shadow-lg z-50 font-kanit animate-bounce">
            <?php echo esc_html($message_error); ?>
        </div>
        <script>setTimeout(() => document.getElementById('toast-error').style.display = 'none', 4000);</script>
    <?php endif; ?>

    <!-- Passkey Created Toast (from LIFF Bridge) -->
    <?php if ($show_passkey_toast): ?>
        <div id="passkey-toast"
            class="fixed top-5 left-1/2 transform -translate-x-1/2 bg-[#00AB8E] text-white px-6 py-3 rounded-full shadow-lg z-50 font-kanit animate-bounce">
            ✅
            <?php echo $current_lang === 'th' ? 'เปิดใช้ Face ID สำเร็จ! คุณสามารถล็อกอินได้ทุกอุปกรณ์' : 'Face ID enabled! You can now log in on any device.'; ?>
        </div>
        <script>setTimeout(() => document.getElementById('passkey-toast').style.display = 'none', 5000);</script>
    <?php endif; ?>

    <!-- Verify Email Banner (Soft Gate) -->
    <?php if (!$is_email_verified): ?>
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-4">
            <div
                class="bg-amber-50 border border-amber-200 rounded-xl p-4 flex items-center justify-between gap-4 font-kanit">
                <div class="flex items-center gap-3">
                    <span class="text-2xl">📧</span>
                    <div>
                        <p class="text-amber-800 font-medium text-sm">
                            <?php echo $current_lang === 'th' ? 'กรุณายืนยันอีเมลของคุณ' : 'Please verify your email'; ?>
                        </p>
                        <p class="text-amber-600 text-xs">
                            <?php echo $current_lang === 'th' ? 'เพื่อรับลิงก์เอกสารประกอบและใบรับรองหลังจบคอร์ส' : 'To receive course materials and certificates'; ?>
                        </p>
                    </div>
                </div>
                <a href="<?php echo home_url('/student-login?step=onboarding'); ?>"
                    class="bg-amber-500 hover:bg-amber-600 text-white px-4 py-2 rounded-full text-sm font-semibold transition whitespace-nowrap">
                    <?php echo $current_lang === 'th' ? 'ยืนยันเลย' : 'Verify Now'; ?>
                </a>
            </div>
        </div>
    <?php endif; ?>

    <!-- Passkey Upsell Toast (for users without passkey) -->
    <?php if ($is_email_verified && !$has_passkey): ?>
        <div id="passkey-upsell"
            class="fixed bottom-5 left-1/2 transform -translate-x-1/2 bg-white border border-gray-200 rounded-2xl shadow-2xl p-4 z-50 font-kanit max-w-sm w-[90%]">
            <div class="flex items-start gap-3">
                <span class="text-3xl">🔐</span>
                <div class="flex-1">
                    <p class="text-gray-800 font-semibold text-sm">
                        <?php echo $current_lang === 'th' ? 'ล็อกอินเร็วขึ้นด้วย Face ID' : 'Log in faster with Face ID'; ?>
                    </p>
                    <p class="text-gray-500 text-xs mt-1">
                        <?php echo $current_lang === 'th' ? 'ไม่ต้องกรอกรหัสทุกครั้ง ปลอดภัยกว่า' : 'Skip password entry. More secure.'; ?>
                    </p>
                    <div class="flex gap-2 mt-3">
                        <button onclick="window.triggerPasskey && window.triggerPasskey()"
                            class="bg-[#00AB8E] hover:bg-[#009980] text-white px-4 py-2 rounded-full text-xs font-semibold transition">
                            <?php echo $current_lang === 'th' ? 'เปิดใช้งาน' : 'Enable'; ?>
                        </button>
                        <button onclick="window.dismissPasskeyUpsell && window.dismissPasskeyUpsell()"
                            class="text-gray-400 hover:text-gray-600 px-3 py-2 text-xs transition">
                            <?php echo $current_lang === 'th' ? 'ไว้ทีหลัง' : 'Later'; ?>
                        </button>
                    </div>
                </div>
                <button onclick="window.dismissPasskeyUpsell && window.dismissPasskeyUpsell()" aria-label="Dismiss"
                    class="text-gray-300 hover:text-gray-500">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12">
                        </path>
                    </svg>
                </button>
            </div>
        </div>
    <?php endif; ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-0 md:py-12">

        <!-- GLOBAL HEADER -->
        <header
            class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 mb-10 flex items-center justify-between relative z-40 mt-0 md:mt-8"
            style="padding-top: max(1rem, env(safe-area-inset-top));">
            <!-- Logo Area (LINKED) -->
            <a href="<?php echo home_url(); ?>" class="flex items-center gap-2 hover:opacity-80 transition">
                <?php if ($ui_logo_url): ?>
                    <img src="<?php echo esc_url($ui_logo_url); ?>" alt="Logo" class="h-10 w-auto object-contain">
                <?php else: ?>
                    <div
                        class="w-8 h-8 bg-[#00AB8E] rounded-lg flex items-center justify-center text-white font-bold text-lg shadow-sm">
                        D</div>
                    <span class="font-bold text-[#44403c] text-lg font-kanit tracking-tight">Dogology</span>
                <?php endif; ?>
            </a>

            <!-- Language Switcher (CENTERED) -->
            <div
                class="absolute left-1/2 transform -translate-x-1/2 flex items-center gap-2 bg-gray-50 rounded-full px-1 py-1 border border-gray-100">
                <a href="<?php echo esc_url(add_query_arg('lang', 'th')); ?>"
                    class="px-3 py-1 rounded-full text-xs font-bold transition <?php echo $current_lang === 'th' ? 'bg-white shadow-sm text-[#00AB8E]' : 'text-gray-400 hover:text-gray-600'; ?>">
                    TH
                </a>
                <a href="<?php echo esc_url(add_query_arg('lang', 'en')); ?>"
                    class="px-3 py-1 rounded-full text-xs font-bold transition <?php echo $current_lang === 'en' ? 'bg-white shadow-sm text-[#00AB8E]' : 'text-gray-400 hover:text-gray-600'; ?>">
                    EN
                </a>
            </div>

            <!-- User Profile Trigger -->
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
                                        class="w-20 h-20 rounded-full border-4 border-white/20 shadow-sm object-cover block">
                                <?php else: ?>
                                    <div
                                        class="w-20 h-20 bg-white/20 rounded-full flex items-center justify-center text-3xl font-bold backdrop-blur-sm border-4 border-white/20">
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

                    <!-- Items (Full Width / No Gap) -->
                    <div>
                        <!-- Edit Profile -->
                        <button onclick="openProfileModal()"
                            class="w-full text-left flex items-center gap-3 px-6 py-4 hover:bg-gray-50 transition group border-b border-gray-50">
                            <div
                                class="w-8 h-8 rounded-full bg-blue-50 text-[#0076BA] flex items-center justify-center text-sm shrink-0">
                                ✏️</div>
                            <div>
                                <div class="font-bold text-sm font-kanit text-gray-700">
                                    <?php echo esc_html($t['menu_edit']); ?>
                                </div>
                                <div class="text-[10px] text-gray-400"><?php echo esc_html($t['menu_edit_desc']); ?>
                                </div>
                            </div>
                        </button>

                        <!-- FaceID Toggle -->
                        <div
                            class="flex items-center justify-between px-6 py-4 hover:bg-gray-50 transition border-b border-gray-50">
                            <div class="flex items-center gap-3">
                                <div
                                    class="w-8 h-8 rounded-full bg-green-50 text-[#00AB8E] flex items-center justify-center text-sm shrink-0">
                                    🔒</div>
                                <div>
                                    <div class="font-bold text-sm font-kanit text-gray-700">
                                        <?php echo esc_html($t['menu_faceid']); ?>
                                    </div>
                                    <div class="text-[10px] text-gray-400">
                                        <?php echo $has_passkey ? esc_html($t['menu_enabled']) : esc_html($t['menu_faceid']); ?>
                                    </div>
                                </div>
                            </div>
                            <?php if ($has_passkey): ?>
                                <span
                                    class="text-[10px] font-bold text-green-600 bg-green-100 px-2 py-1 rounded"><?php echo esc_html($t['menu_enabled']); ?></span>
                            <?php else: ?>
                                <button id="btn-register-passkey-menu"
                                    class="text-xs bg-[#00AB8E] text-white px-3 py-1.5 rounded-full font-bold hover:bg-[#00967d] transition disabled:opacity-50"><?php echo esc_html($t['menu_enable_btn']); ?></button>
                            <?php endif; ?>
                        </div>

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
        </header>

        <!-- ================= MINDMAP BLOCK ================= -->
        <?php if ($mm_table_exists): ?>
            <?php if ($mm_entry && $mm_name): ?>
                <!-- has result: real radar (same drawing code as the report) + link to the full report -->
                <section class="mb-12">
                    <div class="bg-white rounded-xl overflow-hidden ring-1 ring-gray-100 shadow-[0_10px_15px_-3px_rgba(0,0,0,0.06)]">
                        <div class="flex flex-col sm:flex-row">
                            <div class="sm:w-52 bg-gray-50 border-b sm:border-b-0 sm:border-r border-gray-100 flex flex-col items-center justify-center py-5 px-4">
                                <div class="text-[11px] font-kanit font-bold tracking-widest uppercase text-[#00AB8E] mb-2">
                                    <?php echo $current_lang === 'th' ? 'MindMap ของน้อง' : 'Your dog\'s MindMap'; ?>
                                </div>
                                <canvas id="mm-mini-radar"></canvas>
                            </div>
                            <div class="flex-1 p-5 sm:p-6 flex flex-col sm:flex-row items-start sm:items-center gap-4 text-left">
                                <div class="flex-1">
                                    <div class="text-xs font-kanit tracking-wide text-[#00AB8E] font-bold mb-1">
                                        <?php echo $current_lang === 'th' ? 'ผลแบบประเมิน MindMap' : 'MindMap Assessment Result'; ?>
                                    </div>
                                    <h2 class="font-kanit font-bold text-xl text-[#44403c]">
                                        <?php echo $mm_entry->dog_name ? esc_html(($current_lang === 'th' ? 'น้อง' : '') . $mm_entry->dog_name) . ' ' . ($current_lang === 'th' ? 'คือ' : 'is') . ' ' : ''; ?>
                                        <?php echo esc_html($mm_name['th']); ?>
                                        <span class="text-gray-400 font-normal text-base">(<?php echo esc_html($mm_name['en']); ?>)</span>
                                    </h2>
                                    <p class="text-sm text-gray-500 mt-1">
                                        <?php echo $current_lang === 'th' ? 'ประเมินเมื่อ' : 'Assessed'; ?>
                                        <?php echo esc_html(date_i18n('j M Y', strtotime($mm_entry->created_at))); ?>
                                    </p>
                                </div>
                                <a href="<?php echo esc_url($mm_report_url); ?>"
                                    class="shrink-0 font-kanit px-5 py-2.5 rounded-full bg-gradient-to-r from-[#00AB8E] to-[#0076BA] text-white text-sm font-bold shadow-lg hover:opacity-95 transition">
                                    <?php echo $current_lang === 'th' ? 'ดูผลวิเคราะห์ฉบับเต็ม' : 'View full report'; ?>
                                </a>
                            </div>
                        </div>
                    </div>
                </section>
                <?php if ($mm_radar_js && $mm_scores): ?>
                    <script src="<?php echo esc_url($mm_radar_js); ?>"></script>
                    <script>
                        (function () {
                            if (window.DogologyRadar) {
                                DogologyRadar.render(document.getElementById('mm-mini-radar'),
                                    <?php echo wp_json_encode($mm_scores); ?>, { size: 150 });
                            }
                        })();
                    </script>
                <?php endif; ?>
            <?php else: ?>
                <!-- no result yet: placeholder with the landing hero's static radar SVG -->
                <section class="mb-12">
                    <div class="bg-white rounded-xl ring-1 ring-dashed ring-gray-200 p-5 sm:p-6 flex flex-col sm:flex-row items-start sm:items-center gap-4 text-left shadow-sm">
                        <div class="w-24 h-24 shrink-0 flex items-center justify-center">
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
                        <div class="flex-1">
                            <h2 class="font-kanit font-bold text-lg text-[#44403c]">
                                <?php echo $current_lang === 'th' ? 'ยังไม่รู้ว่าน้องเป็นหมาแบบไหน?' : 'Not sure what kind of dog yours is?'; ?>
                            </h2>
                            <p class="text-sm text-gray-500 mt-0.5">
                                <?php echo $current_lang === 'th' ? 'ทำแบบประเมิน MindMap ฟรี 5 นาที เพื่อดูโปรไฟล์ mindset ของน้อง แล้วเราจะแนะนำสิ่งที่เหมาะกับน้องที่สุด' : 'Take the free 5-minute MindMap assessment to see your dog\'s mindset profile.'; ?>
                            </p>
                        </div>
                        <a href="<?php echo esc_url($mm_quiz_url); ?>"
                            class="shrink-0 font-kanit px-5 py-2.5 rounded-full bg-gradient-to-r from-[#00AB8E] to-[#0076BA] text-white text-sm font-bold shadow-lg hover:opacity-95 transition">
                            <?php echo $current_lang === 'th' ? 'ทำแบบประเมินฟรี' : 'Take the free assessment'; ?>
                        </a>
                    </div>
                </section>
            <?php endif; ?>
        <?php endif; ?>

        <!-- DASHBOARD CONTENT -->
        <?php if (empty($courses) && !$has_catalog): ?>
            <!-- EMPTY STATE (fallback only: nothing enrolled AND nothing listed) -->
            <div class="max-w-lg mx-auto mt-12 md:mt-20 px-4">
                <div class="bg-white rounded-3xl p-8 md:p-12 text-center border border-gray-100 shadow-sm">
                    <div class="w-20 h-20 bg-[#f0fdf9] rounded-full flex items-center justify-center mx-auto mb-6 text-4xl">🎓</div>
                    <h2 class="text-2xl md:text-3xl font-bold text-[#44403c] mb-3 font-kanit"><?php echo esc_html($ui_empty_title); ?></h2>
                    <p class="text-gray-400 text-sm md:text-base mb-8 max-w-sm mx-auto leading-relaxed font-body">
                        <?php echo wp_kses_post($ui_empty_desc); ?>
                    </p>
                    <a href="<?php echo esc_url($ui_btn_link); ?>"
                        class="inline-flex items-center gap-2 px-8 py-3.5 bg-[#00AB8E] text-white font-bold rounded-full shadow-lg hover:shadow-xl hover:-translate-y-0.5 transition font-kanit text-sm md:text-base">
                        <?php echo esc_html($ui_btn_text); ?>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                    </a>
                </div>
                <p class="text-center text-gray-300 text-xs mt-6 font-body">
                    <?php echo $current_lang === 'th' ? 'หากคุณเพิ่งซื้อคอร์ส กรุณารอสักครู่แล้วรีเฟรชหน้านี้' : 'If you just purchased a course, please wait a moment and refresh this page.'; ?>
                </p>
            </div>
        <?php else: ?>
            <!-- TITLE BLOCK -->
            <div class="text-center mb-12">
                <h2 class="text-3xl md:text-4xl font-bold text-[#44403c] mb-2 font-kanit">
                    <?php echo esc_html($ui_dash_title); ?>
                </h2>
                <p class="text-lg text-gray-500 font-body"><?php echo esc_html($ui_dash_subtitle); ?></p>
            </div>

            <!-- ================= COURSES SECTION ================= -->
            <div class="flex items-baseline gap-3 mb-6">
                <h3 class="font-kanit font-bold text-xl text-[#44403c]"><?php echo $current_lang === 'th' ? 'คอร์สเรียน' : 'Courses'; ?></h3>
                <div class="flex-1 h-px bg-gray-200"></div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8 mb-16">
                <?php foreach ($my_courses as $course): ?>

                    <!-- Active/Premium Card Style (Matching Mockup) -->
                    <div
                        class="bg-white rounded-xl overflow-hidden ring-2 ring-inset ring-[#00AB8E] shadow-[0_10px_15px_-3px_rgba(0,0,0,0.1)] transform hover:scale-[1.02] transition duration-300 flex flex-col h-full group p-0">
                        <!-- Thumbnail (Fixed Height h-48 per Mockup) -->
                        <div class="relative h-48 bg-gray-100 overflow-hidden w-full">
                            <img src="<?php echo esc_url($course->thumbnail); ?>"
                                class="w-full h-full object-cover block group-hover:scale-110 transition duration-700">
                            <!-- Badge (Gradient) -->
                            <?php if ($course->progress >= 100): ?>
                                <span
                                    class="absolute top-4 left-4 bg-gradient-to-r from-[#f59e0b] to-[#ef4444] text-white text-xs font-bold px-3 py-1 rounded-full font-kanit shadow-md">
                                    🎉 <?php echo $current_lang === 'th' ? 'เรียนจบแล้ว!' : 'Completed!'; ?>
                                </span>
                            <?php else: ?>
                                <span
                                    class="absolute top-4 left-4 bg-gradient-to-r from-[#00AB8E] to-[#0076BA] text-white text-xs font-bold px-3 py-1 rounded-full font-kanit shadow-md">
                                    <?php echo esc_html($t['course_studying']); ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="p-6 flex flex-col flex-1 text-left">
                            <h3 class="text-2xl font-bold text-[#44403c] mb-1 font-kanit leading-tight">
                                <?php echo esc_html($course->title); ?>
                            </h3>
                            <p class="text-gray-500 mb-6 text-sm line-clamp-2 leading-relaxed">
                                <?php echo esc_html($course->subtitle); ?>
                            </p>

                            <div class="mt-auto">
                                <!-- Progress Stats -->
                                <div class="flex justify-between items-end text-xs font-bold text-[#00AB8E] mb-2 font-kanit">
                                    <span><?php echo esc_html($t['progress_label']); ?>         <?php echo $course->progress; ?>%</span>
                                    <span
                                        class="bg-gray-50 px-2 py-1 rounded"><?php echo $course->completed_lessons; ?>/<?php echo $course->total_lessons . ' ' . esc_html($t['lesson_unit']); ?></span>
                                </div>

                                <!-- Progress Bar -->
                                <div class="h-2 w-full bg-gray-100 rounded-full overflow-hidden mb-6">
                                    <div class="h-full bg-gradient-to-r from-[#00AB8E] to-[#0076BA] rounded-full shadow-sm"
                                        style="width: <?php echo $course->progress; ?>%">
                                    </div>
                                </div>

                                <!-- Action Button (Gradient) -->
                                <a href="<?php echo esc_url($course->link); ?>"
                                    class="block w-full py-3 rounded-full bg-gradient-to-r from-[#00AB8E] to-[#0076BA] text-white font-bold font-kanit text-center hover:opacity-95 transition shadow-lg tracking-wide">
                                    <?php echo esc_html($t['course_start']); ?>
                                </a>
                            </div>
                        </div>
                    </div>

                <?php endforeach; ?>

                <?php foreach ($catalog_locked_courses as $item): ?>
                    <!-- Locked course card: grey ring, lock badge, price + sales link -->
                    <div class="bg-white rounded-xl overflow-hidden ring-1 ring-inset ring-gray-200 shadow-sm flex flex-col h-full group p-0">
                        <div class="relative h-48 bg-gray-100 overflow-hidden w-full">
                            <img src="<?php echo esc_url($item->thumbnail); ?>" class="w-full h-full object-cover block opacity-90">
                            <span class="absolute top-4 left-4 bg-gray-700/80 text-white text-xs font-bold px-3 py-1 rounded-full font-kanit shadow-md">
                                🔒 <?php echo $current_lang === 'th' ? 'ยังไม่ได้ลงทะเบียน' : 'Not enrolled'; ?>
                            </span>
                        </div>
                        <div class="p-6 flex flex-col flex-1 text-left">
                            <h3 class="text-2xl font-bold text-[#44403c] mb-1 font-kanit leading-tight"><?php echo esc_html($item->title); ?></h3>
                            <p class="text-gray-500 mb-6 text-sm line-clamp-2 leading-relaxed"><?php echo esc_html($item->subtitle); ?></p>
                            <div class="mt-auto">
                                <?php if ($item->price_label): ?>
                                    <div class="flex justify-between items-end mb-4">
                                        <span class="font-kanit font-bold text-xl text-[#44403c]"><?php echo esc_html($item->price_label); ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($item->sales_url): ?>
                                    <a href="<?php echo esc_url($item->sales_url); ?>"
                                        class="block w-full py-3 rounded-full border-2 border-[#00AB8E] text-[#00AB8E] font-bold font-kanit text-center hover:bg-[#00AB8E] hover:text-white transition tracking-wide">
                                        <?php echo $current_lang === 'th' ? 'ดูรายละเอียดคอร์ส' : 'View course'; ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- ================= E-BOOK SECTION ================= -->
            <?php if ($has_ebook_section): ?>
                <div class="flex items-baseline gap-3 mb-6">
                    <h3 class="font-kanit font-bold text-xl text-[#44403c]">E-Book</h3>
                    <span class="font-kanit text-xs text-gray-400"><?php echo $current_lang === 'th' ? 'คู่มือประจำ archetype จากผล MindMap' : 'Archetype guides from your MindMap result'; ?></span>
                    <div class="flex-1 h-px bg-gray-200"></div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                    <?php foreach ($my_ebooks as $eb): ?>
                        <!-- Owned ebook: portrait cover, no progress, download button -->
                        <div class="bg-white rounded-xl overflow-hidden ring-2 ring-inset ring-[#00AB8E] shadow-[0_10px_15px_-3px_rgba(0,0,0,0.1)] transform hover:scale-[1.02] transition duration-300 flex flex-col h-full group p-0">
                            <div class="relative bg-gray-50 flex items-center justify-center py-6">
                                <img src="<?php echo esc_url($eb->thumbnail); ?>" class="h-64 w-auto rounded shadow-lg" alt="">
                                <span class="absolute top-4 left-4 bg-gradient-to-r from-[#00AB8E] to-[#0076BA] text-white text-xs font-bold px-3 py-1 rounded-full font-kanit shadow-md tracking-wider">E-BOOK</span>
                            </div>
                            <div class="p-6 flex flex-col flex-1 text-left">
                                <h3 class="text-2xl font-bold text-[#44403c] mb-1 font-kanit leading-tight"><?php echo esc_html($eb->title); ?></h3>
                                <p class="text-gray-500 mb-6 text-sm line-clamp-2 leading-relaxed"><?php echo esc_html($eb->subtitle); ?></p>
                                <div class="mt-auto">
                                    <a href="<?php echo esc_url($eb->download); ?>"
                                        class="block w-full py-3 rounded-full bg-gradient-to-r from-[#00AB8E] to-[#0076BA] text-white font-bold font-kanit text-center hover:opacity-95 transition shadow-lg tracking-wide">
                                        ⬇ <?php echo $current_lang === 'th' ? 'อ่าน / ดาวน์โหลด' : 'Read / Download'; ?>
                                    </a>
                                    <p class="text-center text-[11px] text-gray-400 mt-2">
                                        <?php echo $current_lang === 'th' ? 'ไฟล์ PDF ระบุชื่อของเรา · ดาวน์โหลดซ้ำได้เสมอ' : 'Personalized PDF · re-download anytime'; ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php foreach ($catalog_locked_ebooks as $item): ?>
                        <!-- Locked ebook card -->
                        <div class="bg-white rounded-xl overflow-hidden ring-1 ring-inset ring-gray-200 shadow-sm flex flex-col h-full group p-0">
                            <div class="relative bg-gray-50 flex items-center justify-center py-6">
                                <img src="<?php echo esc_url($item->thumbnail); ?>" class="h-64 w-auto rounded shadow opacity-85" alt="">
                                <span class="absolute top-4 left-4 bg-gray-700/80 text-white text-xs font-bold px-3 py-1 rounded-full font-kanit shadow-md">🔒 E-BOOK</span>
                            </div>
                            <div class="p-6 flex flex-col flex-1 text-left">
                                <h3 class="text-2xl font-bold text-[#44403c] mb-1 font-kanit leading-tight"><?php echo esc_html($item->title); ?></h3>
                                <p class="text-gray-500 mb-6 text-sm line-clamp-2 leading-relaxed"><?php echo esc_html($item->subtitle); ?></p>
                                <div class="mt-auto">
                                    <?php if ($item->price_label): ?>
                                        <div class="flex justify-between items-end mb-4">
                                            <span class="font-kanit font-bold text-xl text-[#44403c]"><?php echo esc_html($item->price_label); ?></span>
                                            <span class="text-xs text-gray-400">PDF</span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($item->sales_url): ?>
                                        <a href="<?php echo esc_url($item->sales_url); ?>"
                                            class="block w-full py-3 rounded-full border-2 border-[#00AB8E] text-[#00AB8E] font-bold font-kanit text-center hover:bg-[#00AB8E] hover:text-white transition tracking-wide">
                                            <?php echo $current_lang === 'th' ? 'ดูรายละเอียด / สั่งซื้อ' : 'Details / Buy'; ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

    </div>

    <!-- PROFILE MODAL -->
    <div id="profile-modal"
        class="fixed inset-0 bg-black/50 z-[100] hidden items-center justify-center p-4 backdrop-blur-sm">
        <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full overflow-hidden animate-[fadeIn_0.2s_ease-out]">
            <div class="bg-gray-50 border-b border-gray-100 p-4 flex justify-between items-center">
                <h3 class="font-bold text-lg font-kanit text-[#44403c]"><?php echo esc_html($t['modal_title']); ?></h3>
                <button onclick="closeProfileModal()" aria-label="Close"
                    class="text-gray-400 hover:text-gray-600 w-8 h-8 flex items-center justify-center rounded-full hover:bg-gray-200 transition">✕</button>
            </div>

            <form method="post" enctype="multipart/form-data" class="p-6 space-y-4" onsubmit="var b=this.querySelector('button[type=submit]'); b.disabled=true; b.innerText='...'">
                <input type="hidden" name="action" value="update_profile">
                <input type="hidden" name="_dl_nonce" value="<?php echo wp_create_nonce('dl_dashboard_action'); ?>">

                <!-- Avatar Upload -->
                <div class="flex flex-col items-center gap-4 mb-6">
                    <div class="relative group cursor-pointer"
                        onclick="document.getElementById('profile_image').click()">
                        <?php if ($current_student->profile_picture): ?>
                            <img id="preview_avatar" src="<?php echo esc_url($current_student->profile_picture); ?>"
                                class="w-24 h-24 rounded-full object-cover border-4 border-white shadow-md">
                        <?php else: ?>
                            <div id="preview_avatar_div"
                                class="w-24 h-24 rounded-full bg-[#00AB8E] text-white flex items-center justify-center text-3xl font-bold shadow-md">
                                <?php echo $user_initial; ?>
                            </div>
                        <?php endif; ?>
                        <div
                            class="absolute inset-0 bg-black/30 rounded-full flex items-center justify-center opacity-0 group-hover:opacity-100 transition">
                            <span
                                class="text-white text-xs font-bold"><?php echo esc_html($t['modal_upload_hint']); ?></span>
                        </div>
                    </div>
                    <input type="file" name="profile_image" id="profile_image" class="hidden" accept="image/*"
                        onchange="previewImage(this)">
                    <p class="text-xs text-gray-400"><?php echo esc_html($t['modal_upload_hint']); ?></p>
                </div>

                <div>
                    <label
                        class="block text-sm font-bold text-gray-700 mb-1"><?php echo esc_html($t['label_name']); ?></label>
                    <input type="text" name="display_name"
                        value="<?php echo esc_attr($current_student->display_name); ?>"
                        class="w-full border border-gray-200 rounded-lg px-4 py-2.5 focus:outline-none focus:border-[#00AB8E] font-kanit"
                        required>
                </div>

                <div>
                    <label
                        class="block text-sm font-bold text-gray-700 mb-1"><?php echo esc_html($t['label_email']); ?></label>
                    <input type="email" name="email" value="<?php echo esc_attr($current_student->email); ?>"
                        class="w-full border border-gray-200 rounded-lg px-4 py-2.5 focus:outline-none focus:border-[#00AB8E] font-kanit <?php echo $is_email_verified ? 'bg-gray-50' : ''; ?>"
                        required>
                    <?php if ($is_email_verified): ?>
                        <p class="text-[10px] text-amber-500 mt-1"><?php echo $current_lang === 'th' ? '⚠ การเปลี่ยนอีเมลจะต้องยืนยันใหม่' : '⚠ Changing email will require re-verification'; ?></p>
                    <?php endif; ?>
                </div>

                <div class="pt-4">
                    <button type="submit"
                        class="w-full bg-[#00AB8E] text-white font-bold py-3 rounded-xl hover:bg-[#00967d] transition shadow-lg"><?php echo esc_html($t['modal_save']); ?></button>
                </div>
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
                            newImg.className = 'w-24 h-24 rounded-full object-cover border-4 border-white shadow-md';
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