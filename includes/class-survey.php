<?php
/**
 * Completion Survey — storage, routing and submission.
 *
 * WHY THE SNAPSHOT MATTERS
 * ------------------------
 * A response row is a historical document, not a live view. Progress is frozen
 * into the row at submit time and never recomputed by joining back to
 * dogology_progress. Without that, answers rot: someone who answers at 3/15 and
 * later finishes would have "the intro was too long" attached to a completed
 * student, and publishing a lesson silently changes everyone's denominator
 * (14/14 became 14/15 the day a mid-course lesson was added).
 *
 * KEYS ARE SLUGS, NEVER LABELS
 * ----------------------------
 * Answers store `topic_stress_bucket`, not the Thai sentence. Reword a question
 * and label-keyed data stops being comparable with what came before.
 *
 * POSITION, NOT COUNT
 * -------------------
 * furthest_position is the curriculum position of the last completed lesson.
 * Count and position diverged permanently when a lesson was inserted at
 * position 9 — a student with 14 completions sits at position 15.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Dogology_Learning_Survey
{
    const SURVEY_KEY     = 'dogology-101-completion';
    const SURVEY_VERSION = 1;
    const COURSE_ID      = 5683;

    /** Plain-language topics shown to students, mapped to the lessons they stand for. */
    public static function topics()
    {
        return array(
            'sleep'         => array('label' => 'จัดการเรื่องการนอนของหมา',                  'lessons' => array(5698)),
            'stress_bucket' => array('label' => 'ลดความเครียดสะสม (ถังความเครียด)',            'lessons' => array(5697)),
            'arousal'       => array('label' => 'อ่านอารมณ์และความตื่นตัวของหมาให้ออก',        'lessons' => array(5693)),
            'relationship'  => array('label' => 'ปรับความสัมพันธ์ระหว่างคุณกับหมา',            'lessons' => array(5694)),
            'attachment'    => array('label' => 'รู้ว่าหมาผูกพันกับคุณแบบไหน',                 'lessons' => array(5695)),
            'games'         => array('label' => 'เล่นเกมสร้างความสัมพันธ์',                    'lessons' => array(5696, 6696)),
            'calmness'      => array('label' => 'ฝึกให้หมาสงบ อยู่นิ่งเองได้',                  'lessons' => array(5699)),
            'socialisation' => array('label' => 'พาไปเจอคน เจอที่ใหม่ แบบค่อยเป็นค่อยไป',       'lessons' => array(5700)),
            'optimism'      => array('label' => 'ฝึกให้หมากล้าลองของใหม่ ไม่กลัวง่าย',          'lessons' => array(5701)),
            'root_cause'    => array('label' => 'แก้ปัญหาพฤติกรรมจากต้นเหตุ ไม่ใช่ปลายเหตุ',    'lessons' => array(5702)),
            'worksheet'     => array('label' => 'ทำ worksheet ทำความเข้าใจหมาตัวเอง',          'lessons' => array(5703)),
        );
    }

    public static function options($key)
    {
        $sets = array(
            'add' => array(
                'real_cases'    => 'คลิปจากเคสจริงมากขึ้น',
                'step_by_step'  => 'สาธิตทีละขั้นแบบเห็นภาพชัด',
                'specific'      => 'เจาะลึกปัญหาเฉพาะ เช่น เห่า ดึงสาย หวงของ',
                'weekly_plan'   => 'แผนฝึกรายสัปดาห์ที่ทำตามได้เลย',
                'worksheets'    => 'worksheet หรือใบงานเพิ่ม',
                'live_qa'       => 'ถามตอบสด หรือกลุ่มสำหรับผู้เรียน',
                'short_recaps'  => 'คลิปสั้นสรุปแต่ละบท',
                'production'    => 'คุณภาพภาพและเสียงที่ดีขึ้น',
                'puppy'         => 'เนื้อหาสำหรับลูกหมาโดยเฉพาะ',
                'adult_rescue'  => 'เนื้อหาสำหรับหมาโตหรือหมารับเลี้ยง',
                'other'         => 'อื่น ๆ',
            ),
            'friction' => array(
                'no_time'       => 'ไม่มีเวลา',
                'forgot'        => 'ลืมว่ายังเรียนไม่จบ',
                'slow_start'    => 'เนื้อหาช่วงต้นยาวเกินไป กว่าจะถึงส่วนที่เอาไปใช้ได้',
                'no_entry'      => 'ไม่รู้ว่าต้องเริ่มลงมือทำตรงไหน',
                'production'    => 'คุณภาพงานโปรดักชัน เช่น ภาพ เสียง การตัดต่อ',
                'not_deep'      => 'เนื้อหาไม่ลึกเท่าที่คาดไว้',
                'life_changed'  => 'สถานการณ์ที่บ้านเปลี่ยนไป',
                'access'        => 'เข้าเรียนยาก หาลิงก์ไม่เจอ',
                'other'         => 'อื่น ๆ',
            ),
            'comeback' => array(
                'reminders'     => 'มีคนเตือนเป็นระยะทาง LINE',
                'weekly_plan'   => 'มีแผนรายสัปดาห์ให้ทำตาม',
                'shorter'       => 'คลิปสั้นลง ดูจบได้ในครั้งเดียว',
                'cohort'        => 'มีกำหนดเวลาหรือเรียนพร้อมกันเป็นรุ่น',
                'ask_trainer'   => 'ถามครูฝึกได้เวลาติด',
                'problem_first' => 'เริ่มจากปัญหาของหมาคุณก่อน ไม่ต้องเรียงตามบท',
                'production'    => 'คุณภาพงานโปรดักชันดีขึ้น เช่น ภาพ เสียง การตัดต่อ',
                'not_coming'    => 'คงไม่กลับมาแล้ว',
                'other'         => 'อื่น ๆ',
            ),
        );
        return isset($sets[$key]) ? $sets[$key] : array();
    }

    public static function responses_table() { global $wpdb; return $wpdb->prefix . 'dogology_survey_responses'; }
    public static function answers_table()   { global $wpdb; return $wpdb->prefix . 'dogology_survey_answers'; }

    public static function install()
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $r = self::responses_table();
        $a = self::answers_table();

        dbDelta("CREATE TABLE $r (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            survey_key varchar(64) NOT NULL,
            survey_version smallint(5) unsigned NOT NULL DEFAULT 1,
            user_id bigint(20) unsigned NOT NULL,
            course_id bigint(20) unsigned NOT NULL,
            line_uid varchar(64) DEFAULT NULL,
            segment varchar(16) NOT NULL,
            lessons_total smallint(5) unsigned NOT NULL DEFAULT 0,
            lessons_done smallint(5) unsigned NOT NULL DEFAULT 0,
            furthest_lesson_id bigint(20) unsigned DEFAULT NULL,
            furthest_position smallint(5) unsigned DEFAULT NULL,
            completion_pct decimal(5,1) NOT NULL DEFAULT 0.0,
            days_since_last_activity int(11) DEFAULT NULL,
            days_since_first_touch int(11) DEFAULT NULL,
            star_rating tinyint(3) unsigned DEFAULT NULL,
            worth_rating tinyint(3) unsigned DEFAULT NULL,
            best_topic varchar(64) DEFAULT NULL,
            best_reason text,
            expectation text,
            outcome text,
            add_other text,
            comments text,
            ebook_choice varchar(32) DEFAULT NULL,
            ebook_granted_at datetime DEFAULT NULL,
            consent_testimonial tinyint(1) NOT NULL DEFAULT 0,
            dog_name varchar(120) DEFAULT NULL,
            photo_attachment_id bigint(20) unsigned DEFAULT NULL,
            raw_json longtext,
            submitted_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_user_survey (survey_key, user_id),
            KEY segment (segment)
        ) $charset;");

        dbDelta("CREATE TABLE $a (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            response_id bigint(20) unsigned NOT NULL,
            question_key varchar(48) NOT NULL,
            answer_key varchar(48) NOT NULL,
            answer_text text,
            PRIMARY KEY (id),
            KEY response_id (response_id),
            KEY question (question_key, answer_key)
        ) $charset;");
    }

    /**
     * Lessons in curriculum order: modules by menu_order, lessons by menu_order
     * within their module. Mirrors the player, so "position" means what the
     * student actually saw.
     */
    public static function lessons_in_order($course_id)
    {
        static $cache = array();
        if (isset($cache[$course_id])) return $cache[$course_id];

        $modules = get_posts(array(
            'post_type' => 'dogology_module', 'numberposts' => -1, 'post_status' => 'publish',
            'orderby' => 'menu_order', 'order' => 'ASC',
            'meta_key' => '_dogology_parent_course', 'meta_value' => (int) $course_id,
        ));
        $out = array();
        foreach ($modules as $m) {
            $lessons = get_posts(array(
                'post_type' => 'dogology_lesson', 'numberposts' => -1, 'post_status' => 'publish',
                'orderby' => 'menu_order', 'order' => 'ASC',
                'meta_key' => '_dogology_parent_module', 'meta_value' => $m->ID,
            ));
            foreach ($lessons as $l) {
                $out[] = array('id' => (int) $l->ID, 'title' => $l->post_title);
            }
        }
        $cache[$course_id] = $out;
        return $out;
    }

    /**
     * Everything the page needs about one student, computed once.
     * `seen` is completed lessons PLUS the next one — progress only records
     * completions, so a lesson watched but not marked still taught them
     * something and must be offerable in the topic list.
     */
    public static function context_for($user_id, $course_id = self::COURSE_ID)
    {
        global $wpdb;
        $t = $wpdb->prefix . 'dogology_progress';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT lesson_id, completed, updated_at FROM $t WHERE user_id = %d AND course_id = %d",
            (int) $user_id, (int) $course_id
        ), ARRAY_A);
        if (!$rows) {
            return null; // no access grant → not eligible
        }

        $lessons  = self::lessons_in_order($course_id);
        $total    = count($lessons);
        $doneIds  = array();
        $last     = null;
        $first    = null;
        foreach ($rows as $row) {
            if (!empty($row['completed']) && (int) $row['lesson_id'] > 0) {
                $doneIds[(int) $row['lesson_id']] = true;
                if ($last === null || $row['updated_at'] > $last) $last = $row['updated_at'];
            }
            if ($first === null || $row['updated_at'] < $first) $first = $row['updated_at'];
        }

        $furthestPos = 0; $furthestId = null; $seen = array();
        foreach ($lessons as $i => $l) {
            if (isset($doneIds[$l['id']])) {
                $furthestPos = $i + 1;
                $furthestId  = $l['id'];
            }
        }
        // completed + the one they were on
        foreach ($lessons as $i => $l) {
            if ($i < $furthestPos + 1) $seen[$l['id']] = true;
        }

        $done = count($doneIds);
        $pct  = $total > 0 ? round(min(100, ($done / $total) * 100), 1) : 0;

        // Sticky "finished": everything published when they last completed
        // something. Adding a lesson must never un-finish anyone.
        $finished = false;
        if ($done > 0 && $last) {
            $available = 0;
            foreach (self::lesson_dates($course_id) as $d) {
                if ($d <= $last) $available++;
            }
            $finished = ($available > 0 && $done >= $available);
        }

        $segment = $finished ? 'finished' : ($pct >= 76 ? 'near' : ($done > 0 ? 'stalled' : 'not_started'));

        return array(
            'user_id'        => (int) $user_id,
            'course_id'      => (int) $course_id,
            'lessons'        => $lessons,
            'lessons_total'  => $total,
            'lessons_done'   => $done,
            'furthest_id'    => $furthestId,
            'furthest_pos'   => $furthestPos,
            'completion_pct' => $pct,
            'seen_lesson_ids'=> array_keys($seen),
            'last_activity'  => $last,
            'first_touch'    => $first,
            'segment'        => $segment,
            'is_unfinished'  => ($segment === 'stalled' || $segment === 'not_started'),
        );
    }

    /** Lesson publish dates in curriculum order, memoised. */
    public static function lesson_dates($course_id)
    {
        static $cache = array();
        if (isset($cache[$course_id])) return $cache[$course_id];
        $out = array();
        foreach (self::lessons_in_order($course_id) as $l) {
            $post = get_post($l['id']);
            if ($post) $out[] = $post->post_date;
        }
        sort($out);
        $cache[$course_id] = $out;
        return $out;
    }

    /** Topics whose lessons the student actually reached. */
    public static function topics_for(array $ctx)
    {
        $seen = array_flip($ctx['seen_lesson_ids']);
        $out  = array();
        foreach (self::topics() as $key => $t) {
            foreach ($t['lessons'] as $lid) {
                if (isset($seen[$lid])) { $out[$key] = $t; break; }
            }
        }
        return $out;
    }

    public static function has_responded($user_id)
    {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM " . self::responses_table() . " WHERE survey_key = %s AND user_id = %d",
            self::SURVEY_KEY, (int) $user_id
        ));
    }

    /** Persist one submission. Returns response id, or WP_Error. */
    public static function store($user_id, array $payload)
    {
        global $wpdb;
        $ctx = self::context_for($user_id);
        if (!$ctx) return new WP_Error('no_access', 'ไม่พบสิทธิ์เข้าเรียนคอร์สนี้');
        if (self::has_responded($user_id)) return new WP_Error('duplicate', 'ตอบแบบสอบถามนี้ไปแล้ว');

        $now  = current_time('mysql');
        $days = function ($from) use ($now) {
            if (!$from) return null;
            return (int) floor((strtotime($now) - strtotime($from)) / DAY_IN_SECONDS);
        };
        $txt = function ($k) use ($payload) {
            return isset($payload[$k]) ? sanitize_textarea_field((string) $payload[$k]) : null;
        };
        $int = function ($k, $max) use ($payload) {
            if (!isset($payload[$k]) || $payload[$k] === '') return null;
            $v = (int) $payload[$k];
            return ($v >= 1 && $v <= $max) ? $v : null;
        };

        $line_uid = $wpdb->get_var($wpdb->prepare(
            "SELECT line_uid FROM {$wpdb->prefix}dogology_users WHERE id = %d", (int) $user_id
        ));

        $ok = $wpdb->insert(self::responses_table(), array(
            'survey_key'              => self::SURVEY_KEY,
            'survey_version'          => self::SURVEY_VERSION,
            'user_id'                 => (int) $user_id,
            'course_id'               => $ctx['course_id'],
            'line_uid'                => $line_uid,
            'segment'                 => $ctx['segment'],
            'lessons_total'           => $ctx['lessons_total'],
            'lessons_done'            => $ctx['lessons_done'],
            'furthest_lesson_id'      => $ctx['furthest_id'],
            'furthest_position'       => $ctx['furthest_pos'],
            'completion_pct'          => $ctx['completion_pct'],
            'days_since_last_activity'=> $days($ctx['last_activity']),
            'days_since_first_touch'  => $days($ctx['first_touch']),
            'star_rating'             => $int('star_rating', 5),
            'worth_rating'            => $int('worth_rating', 5),
            'best_topic'              => isset($payload['best_topic']) ? sanitize_key($payload['best_topic']) : null,
            'best_reason'             => $txt('best_reason'),
            'expectation'             => $txt('expectation'),
            'outcome'                 => $txt('outcome'),
            'add_other'               => $txt('add_other'),
            'comments'                => $txt('comments'),
            'ebook_choice'            => isset($payload['ebook_choice']) ? sanitize_key($payload['ebook_choice']) : null,
            'consent_testimonial'     => !empty($payload['consent_testimonial']) ? 1 : 0,
            'dog_name'                => isset($payload['dog_name']) ? sanitize_text_field((string) $payload['dog_name']) : null,
            'raw_json'                => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
            'submitted_at'            => $now,
        ));
        if (!$ok) return new WP_Error('db', 'บันทึกไม่สำเร็จ');
        $rid = (int) $wpdb->insert_id;

        // Multi-selects go to the long table so an implementation rate is a
        // GROUP BY rather than JSON parsing.
        $multi = array(
            'applied'  => array_keys(self::topics()),
            'liked'    => array_keys(self::topics()),
            'add'      => array_keys(self::options('add')),
            'friction' => array_keys(self::options('friction')),
            'comeback' => array_keys(self::options('comeback')),
        );
        foreach ($multi as $q => $valid) {
            if (empty($payload[$q]) || !is_array($payload[$q])) continue;
            foreach ($payload[$q] as $ans) {
                $ans = sanitize_key($ans);
                if (!in_array($ans, $valid, true)) continue;
                $wpdb->insert(self::answers_table(), array(
                    'response_id'  => $rid,
                    'question_key' => $q,
                    'answer_key'   => $ans,
                ));
            }
        }
        return $rid;
    }

    /** Front-end route: /101-survey/ */
    public static function boot()
    {
        add_action('init', function () {
            add_rewrite_rule('^101-survey/?$', 'index.php?dl_survey=1', 'top');
        });
        add_filter('query_vars', function ($v) { $v[] = 'dl_survey'; return $v; });
        add_action('template_redirect', function () {
            if (!get_query_var('dl_survey')) return;
            if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
            if (!defined('DONOTROCKETOPTIMIZE')) define('DONOTROCKETOPTIMIZE', true);
            nocache_headers();
            include DOGOLOGY_LEARNING_PATH . 'templates/survey.php';
            exit;
        });
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));

        // A rewrite rule added in code is inert until the rules are flushed.
        // Flush once per plugin version rather than on every request.
        add_action('init', function () {
            if (get_option('dogology_survey_rewrite_version') !== DOGOLOGY_LEARNING_VERSION) {
                flush_rewrite_rules(false);
                update_option('dogology_survey_rewrite_version', DOGOLOGY_LEARNING_VERSION, false);
            }
        }, 99);
    }

    public static function register_routes()
    {
        register_rest_route('dogology-learning/v1', '/survey', array(
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
            'callback'            => array(__CLASS__, 'handle_submit'),
        ));
        register_rest_route('dogology-learning/v1', '/survey-liff', array(
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
            'callback'            => array(__CLASS__, 'handle_liff'),
        ));
    }

    /**
     * Resolve a LINE identity into a learning session.
     *
     * The ID token is VERIFIED SERVER-SIDE. liff.getProfile() is client-supplied
     * and trivially forged; the reward here is a product we sell, so a forged
     * profile would be a free ebook. Never trust it.
     */
    public static function handle_liff($request)
    {
        global $wpdb;
        $body     = $request->get_json_params();
        $id_token = isset($body['id_token']) ? (string) $body['id_token'] : '';
        if ($id_token === '') {
            return new WP_REST_Response(array('ok' => false, 'error' => 'no_token'), 400);
        }
        $mm        = get_option('dogology_mindmap_settings', array());
        $channel   = isset($mm['liff_id']) ? explode('-', (string) $mm['liff_id'])[0] : '';
        if ($channel === '' || !method_exists('Dogology_Learning_Auth', 'verify_line_id_token')) {
            return new WP_REST_Response(array('ok' => false, 'error' => 'liff_not_configured'), 400);
        }
        $claims = Dogology_Learning_Auth::verify_line_id_token($id_token, $channel);
        if (!$claims || empty($claims['sub'])) {
            return new WP_REST_Response(array('ok' => false, 'error' => 'invalid_token'), 401);
        }
        $user_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}dogology_users WHERE line_uid = %s LIMIT 1", $claims['sub']
        ));
        if (!$user_id) {
            return new WP_REST_Response(array('ok' => false, 'error' => 'no_student'), 404);
        }
        Dogology_Learning_Auth::login_student($user_id);
        return new WP_REST_Response(array('ok' => true), 200);
    }

    public static function handle_submit($request)
    {
        $payload = $request->get_json_params();
        if (!is_array($payload)) $payload = $request->get_params();

        $student = Dogology_Learning_Auth::get_current_student();
        $user_id = $student ? (int) $student->id : 0;
        if (!$user_id) {
            return new WP_REST_Response(array('ok' => false, 'error' => 'not_logged_in'), 401);
        }
        $res = self::store($user_id, $payload);
        if (is_wp_error($res)) {
            return new WP_REST_Response(array('ok' => false, 'error' => $res->get_error_code(),
                'message' => $res->get_error_message()), 400);
        }
        return new WP_REST_Response(array('ok' => true, 'response_id' => $res), 200);
    }
}
