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
}
