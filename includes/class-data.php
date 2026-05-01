<?php

/**
 * Database Helper Class
 */
class Dogology_Learning_Data
{

    private $table_users;
    private $table_enrollments;
    private $table_progress;

    public function __construct()
    {
        global $wpdb;
        $this->table_users = $wpdb->prefix . 'dogology_users';
        $this->table_enrollments = $wpdb->prefix . 'dogology_enrollments';
        $this->table_progress = $wpdb->prefix . 'dogology_progress';
    }

    /**
     * Get user by LINE ID
     */
    public function get_user_by_line_id($line_user_id)
    {
        global $wpdb;
        $sql = $wpdb->prepare("SELECT * FROM {$this->table_users} WHERE line_uid = %s", $line_user_id);
        return $wpdb->get_row($sql);
    }

    /**
     * Create or Update User
     */
    public function upsert_user($data)
    {
        global $wpdb;

        // Map incoming field names to database column names
        // The database uses 'line_uid', but Commerce passes 'line_user_id'
        if (isset($data['line_user_id'])) {
            $data['line_uid'] = $data['line_user_id'];
            unset($data['line_user_id']);
        }

        // IMPORTANT: If line_uid is empty, force it to NULL so MySQL UNIQUE constraint doesn't trip on duplicate empty strings
        if (empty($data['line_uid'])) {
            $data['line_uid'] = null;
        }

        // 1. Try finding by LINE ID
        $existing = null;
        if (!empty($data['line_uid'])) {
            $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_users} WHERE line_uid = %s", $data['line_uid']));
        }

        // 2. If not found, try finding by Email
        if (!$existing && !empty($data['email'])) {
            $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_users} WHERE email = %s", $data['email']));
        }

        // 3. Update or Insert
        if ($existing) {
            // Merge logic: LINE ID is immutable, email can be updated
            $update_data = array();

            // Fill in LINE ID only if the existing record is empty (never overwrite!)
            if (empty($existing->line_uid) && !empty($data['line_uid'])) {
                $update_data['line_uid'] = $data['line_uid'];
            }

            // Always update email if provided (user's latest email takes priority)
            if (!empty($data['email'])) {
                $update_data['email'] = $data['email'];
            }

            // Always update name if provided
            if (!empty($data['display_name'])) {
                $update_data['display_name'] = $data['display_name'];
            }

            if (!empty($update_data)) {
                // If the update trips a UNIQUE constraint (e.g. the email or line_uid we're
                // trying to set is already claimed by a DIFFERENT user), $wpdb->update returns
                // false. Before the guard below, the caller silently continued and the
                // commerce enrollment landed on the wrong account. Now we surface it — the
                // order should be flagged for manual reconciliation, not quietly routed.
                $result = $wpdb->update($this->table_users, $update_data, array('id' => $existing->id));
                if ($result === false) {
                    error_log('[Dogology_Learning_Data] upsert_user merge failed for user ' . $existing->id . ': ' . $wpdb->last_error);
                    return false;
                }
            }

            return $existing->id;
        } else {
            // Remove null values so MySQL DEFAULT NULL applies correctly
            // (avoids $wpdb->insert converting null to '' which can violate UNIQUE constraints)
            $insert_data = array_filter($data, function ($v) { return $v !== null; });
            $result = $wpdb->insert($this->table_users, $insert_data);
            if ($result === false) {
                // Log the database error for debugging
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("Dogology Learning DB Error: " . $wpdb->last_error);
                }
                return false;
            }
            return $wpdb->insert_id;
        }
    }

    /**
     * Enroll user in a course
     */
    public function enroll_user($user_id, $course_id, $order_id = 0)
    {
        global $wpdb;

        // Use dogology_progress table (same as manual enrollment in admin)
        // This is the table the frontend checks for course access
        $table = $wpdb->prefix . 'dogology_progress';

        // Check if already enrolled
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE user_id = %d AND course_id = %d LIMIT 1",
            $user_id,
            $course_id
        ));

        if ($exists) {
            return $exists; // Already enrolled
        }

        $result = $wpdb->insert($table, array(
            'user_id' => $user_id,
            'course_id' => $course_id,
            'lesson_id' => 0,
            'completed' => 0
        ));

        if ($result === false) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("Dogology Learning DB Error (enroll_user): " . $wpdb->last_error);
            }
            return false;
        }

        return $wpdb->insert_id;
    }

    /**
     * Get course IDs a user is enrolled in.
     *
     * Note: returns rows with a `course_id` property only — NOT WP_Post objects.
     * For full post objects use Dogology_Student_DB::get_student_courses().
     * Renamed from get_student_courses() to avoid confusion with that sibling.
     */
    public function get_student_course_ids($user_id)
    {
        global $wpdb;
        // Query dogology_progress (same table enroll_user writes to)
        return $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT course_id FROM {$this->table_progress} WHERE user_id = %d",
            $user_id
        ));
    }
}
