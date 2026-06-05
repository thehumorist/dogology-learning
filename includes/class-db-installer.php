<?php

if (!defined('ABSPATH')) {
    exit;
}

class Dogology_Learning_DB_Installer
{

    public static function install()
    {
        global $wpdb;

        $table_users = $wpdb->prefix . 'dogology_users';
        $charset_collate = $wpdb->get_charset_collate();

        $sql_users = "CREATE TABLE $table_users (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            email varchar(191) DEFAULT NULL,
            line_uid varchar(191) DEFAULT NULL,
            passkey_id varchar(255) DEFAULT NULL,
            display_name varchar(255) NOT NULL,
            profile_picture varchar(255) DEFAULT '',
            email_verified_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY email (email),
            UNIQUE KEY line_uid (line_uid),
            KEY passkey_id (passkey_id(191))
        ) $charset_collate;";

        // Enrollments Table (links students to courses)
        $table_enrollments = $wpdb->prefix . 'dogology_enrollments';
        $sql_enrollments = "CREATE TABLE $table_enrollments (
            id bigint(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id bigint(20) UNSIGNED NOT NULL,
            course_id bigint(20) UNSIGNED NOT NULL,
            order_id bigint(20) UNSIGNED DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'active',
            enrolled_at datetime DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY user_course (user_id, course_id),
            KEY user_id (user_id),
            KEY course_id (course_id)
        ) $charset_collate;";

        // Progress Table (Future proofing)
        $table_progress = $wpdb->prefix . 'dogology_progress';
        $sql_progress = "CREATE TABLE $table_progress (
             id bigint(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
             user_id bigint(20) UNSIGNED NOT NULL,
             course_id bigint(20) UNSIGNED NOT NULL,
             lesson_id bigint(20) UNSIGNED NOT NULL,
             completed tinyint(1) DEFAULT 0,
             updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
             UNIQUE KEY user_lesson (user_id, course_id, lesson_id),
             KEY user_id (user_id),
             KEY course_id (course_id),
             KEY lesson_id (lesson_id)
        ) $charset_collate;";

        // Login Events Table — browser/in-app environment per event.
        // event_type = 'login' (the login moment) or 'session' (a logged-in
        // student loading a lesson/player page, deduped to once per browser per
        // day). Sessions are what actually matter for "can't play video", since
        // the long-lived cookie means a student may log in once in Safari but
        // browse lessons inside the LINE webview where the YouTube iframe fails.
        $table_logins = $wpdb->prefix . 'dogology_login_events';
        $sql_logins = "CREATE TABLE $table_logins (
            id bigint(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id bigint(20) UNSIGNED NOT NULL,
            event_type varchar(20) NOT NULL DEFAULT 'login',
            ua varchar(512) DEFAULT '',
            browser varchar(40) DEFAULT '',
            is_inapp tinyint(1) DEFAULT 0,
            ip varchar(45) DEFAULT '',
            logged_in_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            KEY user_id (user_id),
            KEY event_type (event_type),
            KEY logged_in_at (logged_in_at),
            KEY is_inapp (is_inapp)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_users);
        dbDelta($sql_enrollments);
        dbDelta($sql_progress);
        dbDelta($sql_logins);

        // Stamp version on first install so plugins_loaded's upgrade path becomes a no-op.
        update_option('dogology_learning_db_version', DOGOLOGY_LEARNING_VERSION);
    }

    public static function upgrade($old_version)
    {
        global $wpdb;
        $table_users = $wpdb->prefix . 'dogology_users';

        // Version 1.1.59: Fix Web Order Enrollment Bug
        // Convert empty strings to NULL to avoid UNIQUE constraint conflicts for users without LINE ID
        if (version_compare($old_version, '1.1.59', '<')) {
            $wpdb->query("UPDATE $table_users SET line_uid = NULL WHERE line_uid = ''");
            $wpdb->query("ALTER TABLE $table_users MODIFY line_uid varchar(191) NULL DEFAULT NULL");
        }

        // Version 1.1.63: Schema consistency fixes
        if (version_compare($old_version, '1.1.63', '<')) {
            // Fix passkey_id default
            $wpdb->query("UPDATE $table_users SET passkey_id = NULL WHERE passkey_id = ''");
            $wpdb->query("ALTER TABLE $table_users MODIFY passkey_id varchar(255) DEFAULT NULL");

            // Deduplicate progress rows before adding UNIQUE constraint
            $table_progress = $wpdb->prefix . 'dogology_progress';
            $wpdb->query("
                DELETE p1 FROM $table_progress p1
                INNER JOIN $table_progress p2
                WHERE p1.id > p2.id
                AND p1.user_id = p2.user_id
                AND p1.course_id = p2.course_id
                AND p1.lesson_id = p2.lesson_id
            ");
        }

        // Version 1.1.76: add event_type to login-events (login vs browsing session).
        // Explicit ALTER so existing rows are guaranteed to backfill as 'login'
        // (they predate session tracking) regardless of dbDelta's column handling.
        if (version_compare($old_version, '1.1.76', '<')) {
            $table_logins = $wpdb->prefix . 'dogology_login_events';
            $col = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `$table_logins` LIKE %s", 'event_type'));
            if (!$col) {
                $wpdb->query("ALTER TABLE `$table_logins` ADD event_type varchar(20) NOT NULL DEFAULT 'login' AFTER user_id");
                $wpdb->query("ALTER TABLE `$table_logins` ADD KEY event_type (event_type)");
            }
        }

        // Always run install (dbDelta) during upgrades to ensure schema is synced
        self::install();
    }
}
