<?php

/**
 * Integration with Dogology Commerce
 */
class Dogology_Learning_Integration_Commerce
{
    /**
     * Safe-log keys. Any key not in this allowlist is redacted when $data is an array/object.
     * Keeps IDs and status flags; strips email, line_user_id, display names, order bodies.
     */
    private static $LOG_SAFE_KEYS = [
        'order_id', 'cohort_id', 'course_id', 'user_id', 'enrollment_id',
        'is_on_demand', 'content_type', 'help',
    ];

    /**
     * Reduce a data payload to safe keys only. PII values (email, line_user_id, names) become
     * presence booleans so we still know "was an email provided?" without logging the value.
     */
    private function redact($data)
    {
        if (!is_array($data) && !is_object($data)) {
            return $data;
        }
        $out = [];
        foreach ((array) $data as $k => $v) {
            if (in_array($k, self::$LOG_SAFE_KEYS, true)) {
                $out[$k] = is_scalar($v) ? $v : '[obj]';
            } elseif (in_array($k, ['email', 'line_user_id', 'customer_email', 'customer_name', 'display_name'], true)) {
                $out[$k] = !empty($v) ? 'PROVIDED' : 'MISSING';
            }
        }
        return $out;
    }

    /**
     * Write debug log to SlipOK log file for unified debugging.
     * PII is redacted — only IDs, flags, and presence booleans are persisted.
     */
    private function debug_log($message, $data = null)
    {
        // Use protected log directory (same as Commerce plugin)
        $log_dir = WP_CONTENT_DIR . '/dogology-logs';
        if (!is_dir($log_dir)) {
            wp_mkdir_p($log_dir);
            // Protect directory from web access
            file_put_contents($log_dir . '/.htaccess', "Order deny,allow\nDeny from all\n");
            file_put_contents($log_dir . '/index.php', '<?php // Silence is golden.');
        }

        $log_file = $log_dir . '/slipok-debug.log';
        $timestamp = date('Y-m-d H:i:s');
        $log_entry = "[{$timestamp}] [Learning] {$message}";
        if ($data !== null) {
            $safe = $this->redact($data);
            $log_entry .= ': ' . (is_string($safe) ? $safe : json_encode($safe, JSON_UNESCAPED_UNICODE));
        }
        $log_entry .= "\n";
        file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Initialize hooks
     */
    public function init()
    {
        add_action('dogology_order_approved', array($this, 'handle_order_approved'), 10, 3);
        add_action('dogology_order_line_linked', array($this, 'handle_order_line_linked'), 10, 2);
    }

    /**
     * Handle post-payment LINE linking (Commerce /link-order endpoint).
     *
     * When a guest (email-only) buyer connects their LINE account after the
     * order was already approved, the student profile created at approval
     * time has line_uid NULL. Without this merge, their next LINE login
     * would create a second empty student account. upsert_user matches by
     * email and fills line_uid only when empty, so this is a safe merge.
     *
     * @param int $order_id
     * @param object $order Order row with the freshly-linked line_user_id
     */
    public function handle_order_line_linked($order_id, $order)
    {
        $this->debug_log("handle_order_line_linked CALLED", ['order_id' => $order_id]);

        $user_data = array(
            'line_user_id' => !empty($order->line_user_id) ? trim($order->line_user_id) : null,
            'email' => !empty($order->customer_email) ? trim($order->customer_email) : null,
        );

        if (empty($user_data['line_user_id'])) {
            $this->debug_log("EXITING - no LINE ID on linked order", ['order_id' => $order_id]);
            return;
        }

        $data_helper = new Dogology_Learning_Data();
        $user_id = $data_helper->upsert_user($user_data);

        if ($user_id) {
            $this->debug_log("SUCCESS - line_uid merged into student profile", ['user_id' => $user_id, 'order_id' => $order_id]);
        } else {
            $this->debug_log("FAILED - upsert_user returned falsy", ['order_id' => $order_id]);
        }
    }

    /**
     * Handle Order Approved
     * 
     * @param int $order_id
     * @param object $order
     * @param string $new_status
     */
    public function handle_order_approved($order_id, $order, $new_status = '')
    {
        global $wpdb;

        $this->debug_log("handle_order_approved CALLED", ['order_id' => $order_id]);

        // 1. Validate inputs
        if (empty($order_id) || empty($order)) {
            $this->debug_log("EXITING - empty order_id or order object");
            return;
        }

        // 2. Check if this order is for a Course (Cohort)
        $cohort_id = isset($order->cohort_id) ? intval($order->cohort_id) : 0;
        if (!$cohort_id) {
            $this->debug_log("EXITING - no cohort_id in order");
            return;
        }

        // 3. Check if the Cohort is "On-Demand" (or just linked to a course in general)
        // We need to check the cohort settings.
        $table_cohorts = $wpdb->prefix . 'dogology_cohorts';
        $cohort = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_cohorts WHERE id = %d", $cohort_id));

        if (!$cohort) {
            $this->debug_log("EXITING - cohort not found in database", ['cohort_id' => $cohort_id]);
            return;
        }

        $this->debug_log("Cohort check", [
            'is_on_demand' => $cohort->is_on_demand ?? 'NULL',
            'content_type' => $cohort->content_type ?? 'NULL'
        ]);

        // 4. Filter: Only sync "On-Demand" Cohorts
        // Live Workshops do not use this LMS.
        // We check the 'is_on_demand' flag (added in Commerce v1.0.56)
        if (
            (empty($cohort->is_on_demand) || $cohort->is_on_demand != 1) &&
            (empty($cohort->content_type) || !in_array($cohort->content_type, ['on_demand', 'ebook']))
        ) {
            $this->debug_log("EXITING - Cohort is not On-Demand", ['cohort_id' => $cohort_id]);
            return;
        }

        // 5. Map Cohort -> Course
        // Read linked_course_id directly off the cohort row (migration 1.0.58).
        // Fallback: legacy postmeta lookup, kept during one-release deprecation window.
        $target_course_id = isset($cohort->linked_course_id) ? intval($cohort->linked_course_id) : 0;

        if (!$target_course_id) {
            $legacy = get_posts(array(
                'post_type' => 'dogology_course',
                'meta_key' => '_dogology_linked_cohort_id',
                'meta_value' => $cohort_id,
                'posts_per_page' => 1,
                'post_status' => 'publish',
            ));
            if (!empty($legacy)) {
                $target_course_id = $legacy[0]->ID;
                $this->debug_log("Using legacy postmeta link", [
                    'cohort_id' => $cohort_id,
                    'course_id' => $target_course_id,
                ]);
            }
        }

        if (!$target_course_id) {
            $this->debug_log("EXITING - No course linked to cohort", [
                'cohort_id' => $cohort_id,
                'help' => 'Edit the cohort in Dogology Commerce and set "Linked Learning Course".'
            ]);
            return;
        }

        $this->debug_log("Course found", [
            'cohort_id' => $cohort_id,
            'course_id' => $target_course_id,
        ]);

        // 5. Create/Update User (The "Student")
        $data_helper = new Dogology_Learning_Data();

        $user_data = array(
            'line_user_id' => !empty($order->line_user_id) ? trim($order->line_user_id) : null,
            'email' => !empty($order->customer_email) ? trim($order->customer_email) : null,
            'display_name' => !empty($order->customer_name) ? trim($order->customer_name) : 'Student',
        );

        $this->debug_log("User data", [
            'line_user_id' => !empty($user_data['line_user_id']) ? 'PROVIDED' : 'MISSING',
            'email' => !empty($user_data['email']) ? 'PROVIDED' : 'MISSING',
        ]);

        // Must have at least one identity
        if (empty($user_data['line_user_id']) && empty($user_data['email'])) {
            $this->debug_log("EXITING - no LINE ID or email");
            return;
        }

        $user_id = $data_helper->upsert_user($user_data);

        if (!$user_id) {
            $this->debug_log("FAILED to create/update user", ['order_id' => $order_id]);
            return;
        }

        $this->debug_log("User upserted", ['user_id' => $user_id]);

        // 6. Enroll User
        $enrollment_id = $data_helper->enroll_user($user_id, $target_course_id, $order_id);

        if ($enrollment_id) {
            $this->debug_log("SUCCESS - Enrolled user in course", [
                'user_id' => $user_id,
                'course_id' => $target_course_id,
                'enrollment_id' => $enrollment_id,
                'order_id' => $order_id
            ]);
        } else {
            $this->debug_log("FAILED to enroll user", [
                'user_id' => $user_id,
                'course_id' => $target_course_id
            ]);
        }
    }
}
