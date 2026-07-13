<?php

if (!defined('ABSPATH')) {
    exit;
}

class Dogology_Learning_Router
{

    public function init()
    {
        add_action('init', array($this, 'add_rewrite_rules'));
        add_filter('query_vars', array($this, 'add_query_vars'));
        add_filter('template_include', array($this, 'load_template'));
        add_filter('rocket_cache_reject_uri', array($this, 'exclude_from_wp_rocket'));

        // Define WP Rocket bypass constants EARLY (before wp_enqueue_scripts)
        add_action('wp', array($this, 'disable_rocket_on_learning_pages'), 1);

        // Diagnostic beacon: the player posts here when it detects a playback
        // failure mode (API never reported ready, src-swap fallback fired, etc).
        // Logged-in case is unused in practice (students aren't WP users) but
        // registered for completeness so admin/preview sessions don't 0-byte.
        add_action('wp_ajax_dl_video_diag', array($this, 'handle_video_diag'));
        add_action('wp_ajax_nopriv_dl_video_diag', array($this, 'handle_video_diag'));

        // Auto-flush on first load if needed (v6: /learn/ebook-dl/{order}/{course} signed route)
        add_action('init', function () {
            if (!get_option('dogology_learning_permalinks_flushed_v6')) {
                flush_rewrite_rules();
                update_option('dogology_learning_permalinks_flushed_v6', 1);
            }
        }, 99);
    }

    /**
     * Define Custom Routes
     */
    public function add_rewrite_rules()
    {
        // 1. Dashboard: /my-courses
        add_rewrite_rule('^my-courses/?$', 'index.php?dl_route=dashboard', 'top');

        // 2. Login: /student-login
        add_rewrite_rule('^student-login/?$', 'index.php?dl_route=login', 'top');

        // 3-pre. Ebook download: /learn/download/{course_id}
        // MUST be registered before the numeric player rules or ^learn/([0-9]+) shadows it.
        add_rewrite_rule('^learn/download/([0-9]+)/?$', 'index.php?dl_route=download&course_id=$matches[1]', 'top');

        // 3-pre2. Signed, login-free ebook download: /learn/ebook-dl/{order_id}/{course_id}?sig=
        // For post-purchase delivery (success page, LINE flex, email) where the
        // buyer has no LMS session. Auth is the HMAC + a paid-order re-check.
        add_rewrite_rule('^learn/ebook-dl/([0-9]+)/([0-9]+)/?$', 'index.php?dl_route=ebook_dl&order_id=$matches[1]&course_id=$matches[2]', 'top');

        // 3a. Player Root (Smart Redirect): /learn/{course_slug}
        add_rewrite_rule('^learn/([0-9]+)/?$', 'index.php?dl_route=player&course_id=$matches[1]', 'top');

        // 3b. Player Lesson: /learn/{course_slug}/{lesson_slug}
        add_rewrite_rule('^learn/([0-9]+)/([0-9]+)/?$', 'index.php?dl_route=player&course_id=$matches[1]&lesson_id=$matches[2]', 'top');

        // 4. Logout: /student-logout (Custom Route)
        add_rewrite_rule('^student-logout/?$', 'index.php?dl_route=logout', 'top');
    }

    /**
     * Register Query Vars
     */
    public function add_query_vars($vars)
    {
        $vars[] = 'dl_route';
        $vars[] = 'course_id';
        $vars[] = 'lesson_id';
        $vars[] = 'order_id';
        return $vars;
    }

    /**
     * Exclude Learning Routes from WP Rocket Optimization
     */
    public function exclude_from_wp_rocket($urls)
    {
        $urls[] = '/learn/(.*)';
        $urls[] = '/my-courses/?(.*)';
        $urls[] = '/student-login/?(.*)';
        $urls[] = '/student-logout/?(.*)';
        return $urls;
    }

    /**
     * Define WP Rocket bypass constants on Learning pages EARLY
     * (Must fire before wp_enqueue_scripts so WP Rocket doesn't enqueue its lazy load script)
     */
    public function disable_rocket_on_learning_pages()
    {
        if (!get_query_var('dl_route'))
            return;

        if (!defined('DONOTROCKETOPTIMIZE')) {
            define('DONOTROCKETOPTIMIZE', true);
        }
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
    }

    /**
     * Load Custom Templates
     */
    public function load_template($template)
    {
        $route = get_query_var('dl_route');

        if (!$route) {
            return $template;
        }

        // Prevent Caching AND File Optimization on all Learning routes
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        if (!defined('DONOTROCKETOPTIMIZE')) {
            define('DONOTROCKETOPTIMIZE', true);
        }
        nocache_headers();

        // nocache_headers() emits no-cache + must-revalidate but NOT no-store. Some
        // intermediate proxies and bfcache implementations treat the difference as
        // license to serve a stale snapshot anyway, which on the player route shows
        // up as a frozen iframe with a dead postMessage channel. no-store explicitly
        // forbids storage end-to-end.
        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        }

        // Clickjacking protection on auth surfaces (login, dashboard, player, logout).
        // These pages should never be embedded in a third-party frame.
        if (!headers_sent()) {
            header('X-Frame-Options: DENY');
            header("Content-Security-Policy: frame-ancestors 'none'");
        }

        // Signed, login-free ebook download — post-purchase delivery from the
        // success page / LINE flex / email. Auth is the HMAC over order|course
        // plus an independent paid-order re-check, so the link is inert until
        // payment is verified (the manual-review case) and safe if forwarded.
        if ($route === 'ebook_dl') {
            global $wpdb;
            $order_id  = (int) get_query_var('order_id');
            $course_id = (int) get_query_var('course_id');
            $sig       = isset($_GET['sig']) ? sanitize_text_field(wp_unslash($_GET['sig'])) : '';

            if (!Dogology_Ebook::verify_download_sig($order_id, $course_id, $sig)) {
                wp_die(esc_html__('ลิงก์ดาวน์โหลดไม่ถูกต้อง กรุณาเปิดจากข้อความ LINE หรืออีเมลล่าสุด', 'dogology-learning'), 'Dogology', array('response' => 403));
            }

            $course = get_post($course_id);
            if (!$course || $course->post_type !== 'dogology_course' || $course->post_status !== 'publish'
                || !Dogology_Ebook::is_ebook($course_id)) {
                wp_die(esc_html__('ไม่พบ E-Book นี้', 'dogology-learning'), 'Dogology', array('response' => 404));
            }

            // Re-check the order is genuinely paid and bound to this course.
            $orders_t  = $wpdb->prefix . 'dogology_orders';
            $cohorts_t = $wpdb->prefix . 'dogology_cohorts';
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT o.status, o.customer_name, o.customer_email, c.linked_course_id
                 FROM $orders_t o LEFT JOIN $cohorts_t c ON o.cohort_id = c.id
                 WHERE o.id = %d",
                $order_id
            ));
            if (!$row || !in_array($row->status, array('paid', 'deposit_paid'), true)) {
                // Not yet verified (manual-review path). Explain, don't error out.
                wp_die(
                    esc_html__('เรากำลังตรวจสอบการชำระเงินของคุณ เมื่อยืนยันแล้วเราจะส่งลิงก์ดาวน์โหลดให้ทาง LINE และอีเมลทันทีครับ', 'dogology-learning'),
                    'Dogology', array('response' => 402)
                );
            }
            if ((int) $row->linked_course_id !== $course_id) {
                wp_die(esc_html__('ไม่พบ E-Book นี้', 'dogology-learning'), 'Dogology', array('response' => 404));
            }

            // Duck-typed student for the stamper (name → stamp, id → cache key).
            $buyer = (object) array(
                'id'           => 'ord' . $order_id,
                'display_name' => $row->customer_name,
                'email'        => $row->customer_email,
            );
            Dogology_Ebook::stream_for($course_id, $buyer); // exits
        }

        // Ebook download — streams bytes and exits, never returns a template.
        // Order of checks: login → course-is-ebook → enrollment → stream.
        if ($route === 'download') {
            $course_id = (int) get_query_var('course_id');
            $student = Dogology_Auth::get_current_student();
            if (!$student) {
                wp_safe_redirect(home_url('/student-login/?redirect=' . rawurlencode('/learn/download/' . $course_id)));
                exit;
            }
            $course = get_post($course_id);
            if (!$course || $course->post_type !== 'dogology_course' || $course->post_status !== 'publish'
                || !Dogology_Ebook::is_ebook($course_id)) {
                wp_die(esc_html__('ไม่พบ E-Book นี้', 'dogology-learning'), 'Dogology', array('response' => 404));
            }
            $db = new Dogology_Student_DB();
            $enrolled_ids = array_map(function ($c) {
                return (int) $c->ID;
            }, $db->get_student_courses($student->id));
            if (!in_array($course_id, $enrolled_ids, true)) {
                wp_die(esc_html__('บัญชีนี้ยังไม่ได้ซื้อ E-Book เล่มนี้', 'dogology-learning'), 'Dogology', array('response' => 403));
            }
            Dogology_Ebook::stream_for($course_id, $student); // exits
        }

        $new_template = '';

        switch ($route) {
            case 'dashboard':
                $new_template = DOGOLOGY_LEARNING_PATH . 'templates/dashboard.php';
                break;
            case 'login':
                $new_template = DOGOLOGY_LEARNING_PATH . 'templates/auth.php';
                break;
            case 'player':
                // Record the browsing environment of this lesson/video view
                // (deduped per student/browser/day). This is where the YouTube
                // player lives, so it's the browser that matters for playback —
                // and may differ from the one used at login.
                Dogology_Auth::record_session_event();
                $new_template = DOGOLOGY_LEARNING_PATH . 'templates/player.php';
                break;
            case 'logout':
                // Custom Logout: Render a view that clears LIFF session
                Dogology_Auth::logout();
                $new_template = DOGOLOGY_LEARNING_PATH . 'templates/logout.php';
                break;
        }

        if ($new_template && file_exists($new_template)) {
            return $new_template;
        }

        return $template;
    }

    /**
     * Helper for Activation Hook
     */
    public static function flush_rules()
    {
        $router = new self();
        $router->add_rewrite_rules();
        flush_rewrite_rules();
    }

    /**
     * Diagnostic Beacon Handler
     *
     * Receives short event reports from the player when it detects playback
     * failure modes. Stores the last 200 events in an option so we can see
     * whether the mobile-playback fixes are landing on real devices without
     * standing up real analytics.
     *
     * No nonce: this is an unauthenticated diagnostic endpoint by design (the
     * player needs to report even when the user isn't logged in, and beacon
     * requests can't easily carry custom headers). The evt allowlist and the
     * fixed-size ring buffer cap spam impact.
     */
    public function handle_video_diag()
    {
        $allowed_evts = array(
            'yt_api_no_onready',
            'video_src_swap',
            'play_no_state_change',
            'api_never_ready_after_click',
            // Browser-warning strip (player.php): user self-reports playback state.
            // strip_dismissed = "video plays fine" (positive); strip_copy = Samsung
            // user used the copy-to-Chrome escape (negative). detail = variant.
            'strip_dismissed',
            'strip_copy',
        );

        $evt = isset($_POST['evt']) ? sanitize_key(wp_unslash($_POST['evt'])) : '';
        if (!in_array($evt, $allowed_evts, true)) {
            wp_die('', '', array('response' => 400));
        }

        $detail = isset($_POST['detail']) ? sanitize_text_field(wp_unslash($_POST['detail'])) : '';
        $ua = isset($_POST['ua']) ? sanitize_text_field(wp_unslash($_POST['ua'])) : '';

        $entry = array(
            'ts' => time(),
            'evt' => $evt,
            'detail' => substr($detail, 0, 256),
            'ua' => substr($ua, 0, 256),
        );

        $log = get_option('dogology_learning_video_diag', array());
        if (!is_array($log)) {
            $log = array();
        }
        $log[] = $entry;
        if (count($log) > 200) {
            $log = array_slice($log, -200);
        }
        // autoload=false: only read during triage, no need on every request.
        update_option('dogology_learning_video_diag', $log, false);

        wp_die('', '', array('response' => 204));
    }
}
