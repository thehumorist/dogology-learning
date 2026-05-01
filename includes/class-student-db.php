<?php

if (!defined('ABSPATH')) {
    exit;
}

class Dogology_Student_DB
{

    private $table_name;
    private static $healed = false;

    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'dogology_users';

        // SELF-HEAL: Ensure columns exist (Hotfix for existing installs)
        // Gated behind version check and static flag to avoid running on every instantiation
        $heal_version = 'dogology_db_heal_v3';
        if (!self::$healed && !get_transient($heal_version)) {
            $this->heal_column('line_uid',          "ALTER TABLE {$this->table_name} ADD line_uid VARCHAR(255)");
            $this->heal_column('passkey_id',        "ALTER TABLE {$this->table_name} ADD passkey_id VARCHAR(255)");
            $this->heal_column('language',          "ALTER TABLE {$this->table_name} ADD language VARCHAR(10) DEFAULT 'en'");
            $this->heal_column('email_verified_at', "ALTER TABLE {$this->table_name} ADD email_verified_at DATETIME DEFAULT NULL");
            set_transient($heal_version, 1, DAY_IN_SECONDS);
            self::$healed = true;
        }
    }

    /**
     * Add a column if missing. Logs ALTER failures instead of swallowing — prior
     * versions silently ignored errors, masking broken self-heal runs.
     */
    private function heal_column($column, $alter_sql)
    {
        global $wpdb;
        // Backtick the table name defensively; $this->table_name is always
        // wp_dogology_users today but the identifier quoting keeps the query
        // correct if a future prefix ever contains reserved chars.
        $cols = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM `{$this->table_name}` LIKE %s", $column));
        if (!empty($cols)) {
            return;
        }
        $result = $wpdb->query($alter_sql);
        if ($result === false) {
            error_log("[Dogology_Student_DB] self-heal ALTER failed for column '{$column}': " . $wpdb->last_error);
        }
    }

    /**
     * Create a new student
     */
    public function create_student($data)
    {
        global $wpdb;

        $defaults = array(
            'email' => '',
            'display_name' => '',
            'line_uid' => null,
            'language' => 'en',
            'profile_picture' => ''
        );

        $data = wp_parse_args($data, $defaults);

        if (empty($data['email']) && empty($data['line_uid'])) {
            return new WP_Error('missing_data', 'Either Email or LINE UID is required.');
        }

        // Only check duplicate email if email is provided
        if (!empty($data['email'])) {
            $existing = $this->get_student_by_email($data['email']);
            if ($existing) {
                return new WP_Error('duplicate_email', 'Email already exists.');
            }
        }

        $result = $wpdb->insert($this->table_name, $data);

        if (false === $result) {
            return new WP_Error('db_error', 'Could not insert into database.');
        }

        return $wpdb->insert_id;
    }

    /**
     * Update student
     */
    public function update_student($id, $data)
    {
        global $wpdb;
        return $wpdb->update($this->table_name, $data, array('id' => $id));
    }

    public function delete_student($id)
    {
        global $wpdb;
        return $wpdb->delete($this->table_name, array('id' => $id));
    }

    public function get_student($id)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", $id));
    }

    public function get_student_by_email($email)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE email = %s", $email));
    }

    /**
     * Get student by Passkey Hash (Credential ID)
     */
    public function get_student_by_passkey($credential_id)
    {
        global $wpdb;
        // Column existence is guaranteed by the constructor self-heal (runs once per day)
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE passkey_id = %s", $credential_id));
    }

    public function get_students($limit = 20, $offset = 0)
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table_name} ORDER BY created_at DESC LIMIT %d OFFSET %d", $limit, $offset));
    }

    public function count_students()
    {
        global $wpdb;
        return $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
    }

    public function enroll_student($user_id, $course_id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'dogology_progress';

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE user_id = %d AND course_id = %d LIMIT 1",
            $user_id,
            $course_id
        ));

        if ($exists)
            return true;

        return $wpdb->insert($table, array(
            'user_id' => $user_id,
            'course_id' => $course_id,
            'lesson_id' => 0,
            'completed' => 0
        ));
    }

    public function get_student_courses($user_id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'dogology_progress';

        $course_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT course_id FROM $table WHERE user_id = %d",
            $user_id
        ));

        if (empty($course_ids))
            return array();

        return get_posts(array(
            'post_type' => 'dogology_course',
            'post__in' => $course_ids,
            'numberposts' => -1
        ));
    }

    public function remove_enrollment($user_id, $course_id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'dogology_progress';
        return $wpdb->delete($table, array('user_id' => $user_id, 'course_id' => $course_id));
    }

    public function count_enrollments()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'dogology_progress';
        // Count distinct User-Course pairs to handle potential duplicates or lesson-level rows
        return $wpdb->get_var("SELECT COUNT(DISTINCT user_id, course_id) FROM $table");
    }

    public function get_recent_enrollments($limit = 5)
    {
        global $wpdb;
        $table_progress = $wpdb->prefix . 'dogology_progress';
        $table_users = $wpdb->prefix . 'dogology_users';

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT p.user_id, p.course_id, u.display_name, u.email 
             FROM $table_progress p
             JOIN $table_users u ON p.user_id = u.id
             GROUP BY p.user_id, p.course_id
             ORDER BY MAX(p.id) DESC
             LIMIT %d",
            $limit
        ));

        return $results;
    }

    /**
     * Check if a specific lesson is completed
     */
    public function get_lesson_progress($user_id, $lesson_id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'dogology_progress';
        return $wpdb->get_var($wpdb->prepare(
            "SELECT completed FROM $table WHERE user_id = %d AND lesson_id = %d LIMIT 1",
            $user_id,
            $lesson_id
        ));
    }

    /**
     * Update or Insert lesson completion status
     */
    public function update_lesson_progress($user_id, $course_id, $lesson_id, $completed = 1)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'dogology_progress';

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE user_id = %d AND lesson_id = %d LIMIT 1",
            $user_id,
            $lesson_id
        ));

        if ($exists) {
            return $wpdb->update($table, array('completed' => $completed), array('id' => $exists));
        } else {
            return $wpdb->insert($table, array(
                'user_id' => $user_id,
                'course_id' => $course_id,
                'lesson_id' => $lesson_id,
                'completed' => $completed
            ));
        }
    }

    /**
     * Return this student's lesson progress for a single course, keyed by lesson_id.
     * Each value has { completed, updated_at }. Used by admin to render per-lesson status.
     */
    public function get_progress_for_course($user_id, $course_id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'dogology_progress';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT lesson_id, completed, updated_at
             FROM $table
             WHERE user_id = %d AND course_id = %d AND lesson_id > 0",
            $user_id,
            $course_id
        ));
        $by_lesson = [];
        foreach ($rows as $r) {
            $by_lesson[(int) $r->lesson_id] = $r;
        }
        return $by_lesson;
    }

    /**
     * Get all lessons belonging to a course
     */
    public function get_course_lessons($course_id)
    {
        return get_posts(array(
            'post_type' => 'dogology_lesson',
            'meta_key' => '_dogology_parent_course',
            'meta_value' => $course_id,
            'numberposts' => -1,
            'orderby' => 'menu_order', // Sort by Order
            'order' => 'ASC'
        ));
    }
}
