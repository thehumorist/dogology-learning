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

        // Auto-flush on first load if needed
        add_action('init', function () {
            if (!get_option('dogology_learning_permalinks_flushed_v4')) {
                flush_rewrite_rules();
                update_option('dogology_learning_permalinks_flushed_v4', 1);
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
