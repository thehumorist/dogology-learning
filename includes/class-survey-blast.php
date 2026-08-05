<?php
/**
 * Completion Survey — LINE delivery.
 *
 * Mirrors the M4 blast engine in dogology-mindmap rather than inventing a new
 * one: claim-before-push (a crash between push and result-update leaves the row
 * in 'sending', never back in 'pending' where the next tick would re-send), a
 * stable retry key per row so LINE dedupes any redelivery for 24h, and batched
 * cron ticks.
 *
 * THE LEDGER IS THE POINT. UNIQUE(survey_key, user_id) means one invite per
 * person, ever. Without it, "automate for all completions" re-fires the whole
 * finished cohort every time a lesson is published, because completion is
 * relative to the curriculum and the curriculum moves.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Dogology_Learning_Survey_Blast
{
    const BATCH_SIZE   = 20;
    const TICK_HOOK    = 'dogology_survey_blast_tick';
    const AUTO_HOOK    = 'dogology_survey_auto_scan';
    const OPT_AUTO     = 'dogology_survey_auto_send';
    const OPT_DELAY_H  = 'dogology_survey_auto_delay_hours';

    public static function table() { global $wpdb; return $wpdb->prefix . 'dogology_survey_invites'; }

    public static function install()
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $t = self::table();
        dbDelta("CREATE TABLE $t (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            survey_key varchar(64) NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            line_uid varchar(64) DEFAULT NULL,
            segment varchar(16) NOT NULL,
            source varchar(16) NOT NULL DEFAULT 'blast',
            lessons_done_at_send smallint(5) unsigned DEFAULT NULL,
            furthest_position_at_send smallint(5) unsigned DEFAULT NULL,
            status varchar(16) NOT NULL DEFAULT 'pending',
            scheduled_for datetime DEFAULT NULL,
            sent_at datetime DEFAULT NULL,
            responded_at datetime DEFAULT NULL,
            error varchar(500) DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_invite (survey_key, user_id),
            KEY status (status)
        ) " . $wpdb->get_charset_collate() . ";");
    }

    public static function boot()
    {
        add_action(self::TICK_HOOK, array(__CLASS__, 'tick'));
        add_action(self::AUTO_HOOK, array(__CLASS__, 'auto_scan'));
        if (!wp_next_scheduled(self::AUTO_HOOK)) {
            wp_schedule_event(time() + 600, 'hourly', self::AUTO_HOOK);
        }
    }

    public static function auto_enabled()  { return get_option(self::OPT_AUTO, '0') === '1'; }
    public static function auto_delay_h()  { return max(0, (int) get_option(self::OPT_DELAY_H, 24)); }

    /** Everyone with course access, with their computed segment. */
    public static function audience()
    {
        global $wpdb;
        $t = $wpdb->prefix . 'dogology_progress';
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT user_id FROM $t WHERE course_id = %d", Dogology_Learning_Survey::COURSE_ID
        ));
        $out = array();
        foreach ($ids as $uid) {
            $ctx = Dogology_Learning_Survey::context_for((int) $uid);
            if (!$ctx) continue;
            $out[(int) $uid] = $ctx;
        }
        return $out;
    }

    /**
     * user_id => line_uid for the whole audience, in ONE query.
     * This was a per-student SELECT inside two different loops, so an admin page
     * load cost roughly two queries per enrolled student and the Blast button
     * paid it all over again.
     */
    protected static function line_uids()
    {
        static $map = null;
        if ($map !== null) return $map;
        global $wpdb;
        $map = array();
        $rows = $wpdb->get_results(
            "SELECT id, line_uid FROM {$wpdb->prefix}dogology_users WHERE line_uid <> ''", ARRAY_A);
        foreach ($rows as $r) $map[(int) $r['id']] = $r['line_uid'];
        return $map;
    }

    /** Everyone who has already answered, in ONE query. */
    protected static function responded_ids()
    {
        static $set = null;
        if ($set !== null) return $set;
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM " . Dogology_Learning_Survey::responses_table() . " WHERE survey_key = %s",
            Dogology_Learning_Survey::SURVEY_KEY
        ));
        $set = array_flip(array_map('intval', $ids));
        return $set;
    }

    public static function counts()
    {
        $c = array('finished' => 0, 'near' => 0, 'stalled' => 0, 'not_started' => 0, 'no_line' => 0);
        // Per-segment too: a single total does not describe the launch you are
        // about to press, which only ever covers the selected segments.
        $c['no_line_by_segment'] = array('finished' => 0, 'near' => 0, 'stalled' => 0, 'not_started' => 0);
        $line = self::line_uids();
        foreach (self::audience() as $uid => $ctx) {
            $seg = $ctx['segment'];
            $c[$seg] = ($c[$seg] ?? 0) + 1;
            if (empty($line[(int) $uid])) {
                $c['no_line']++;
                $c['no_line_by_segment'][$seg] = ($c['no_line_by_segment'][$seg] ?? 0) + 1;
            }
        }
        return $c;
    }

    /**
     * Queue invites for the given segments. Idempotent: the unique key means a
     * person already queued or sent is silently skipped, so pressing the button
     * twice cannot double-send.
     */
    public static function queue(array $segments, $source = 'blast')
    {
        global $wpdb;
        $now = current_time('mysql');
        $added = 0; $skipped = 0; $noline = 0;
        $lines     = self::line_uids();
        $responded = self::responded_ids();
        foreach (self::audience() as $uid => $ctx) {
            if (!in_array($ctx['segment'], $segments, true)) continue;
            $line = isset($lines[(int) $uid]) ? $lines[(int) $uid] : '';
            if (!$line) { $noline++; continue; }
            if (isset($responded[(int) $uid])) { $skipped++; continue; }
            $ok = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO " . self::table() . "
                 (survey_key,user_id,line_uid,segment,source,lessons_done_at_send,
                  furthest_position_at_send,status,created_at)
                 VALUES (%s,%d,%s,%s,%s,%d,%d,'pending',%s)",
                Dogology_Learning_Survey::SURVEY_KEY, $uid, $line, $ctx['segment'], $source,
                $ctx['lessons_done'], $ctx['furthest_pos'], $now
            ));
            if ($ok) $added++; else $skipped++;
        }
        if ($added > 0) self::schedule_tick();
        return compact('added', 'skipped', 'noline');
    }

    public static function schedule_tick()
    {
        if (!wp_next_scheduled(self::TICK_HOOK)) {
            wp_schedule_single_event(time() + 30, self::TICK_HOOK);
        }
    }

    public static function pending_count()
    {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM " . self::table() . " WHERE status='pending'");
    }
    public static function sent_count()
    {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM " . self::table() . " WHERE status='sent'");
    }
    public static function failed_count()
    {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM " . self::table() . " WHERE status='failed'");
    }

    /**
     * LIFF URL when a LIFF id is configured, plain URL otherwise.
     *
     * This was the plain URL, which is why tapping the button opened the LINE
     * in-app browser WITHOUT a LIFF context — so the page had no LINE identity
     * and every recipient landed on the "ยังไม่พบข้อมูลผู้เรียน" gate. LINE only
     * establishes that context for liff.line.me links. Mirrors how MindMap
     * builds its ebook CTA.
     */
    /**
     * Open inside LINE by borrowing the COMMERCE LIFF app.
     *
     * LINE only keeps a link in its in-app browser for liff.line.me URLs, and
     * we have no LIFF app of our own pointing at the survey. Commerce's router
     * already implements a same-origin redirect (page=ebook&target=/path) with
     * open-redirect guards, built for exactly this reason — so we route through
     * it rather than standing up another LIFF app.
     *
     * The signed token rides along in `target`, so identity still works even if
     * the user's LINE is set to open links externally and LIFF never engages.
     */
    public static function survey_url($user_id = 0, $preview_segment = '')
    {
        $path = '/101-survey/';
        if ($user_id) {
            $path = add_query_arg('t', Dogology_Learning_Survey::make_token($user_id), $path);
        }
        // Samples only: render as the advertised segment instead of the
        // recipient's own progress — which is why every sample opened the
        // non-finisher page. Signed, so it can't be added by hand.
        if ($user_id && $preview_segment) {
            $path = add_query_arg(array(
                'pv'  => $preview_segment,
                'pvs' => Dogology_Learning_Survey::preview_sig($user_id, $preview_segment),
            ), $path);
        }
        $liff = trim((string) get_option('dogology_commerce_liff_id', ''));
        if ($liff !== '') {
            return 'https://liff.line.me/' . rawurlencode($liff)
                 . '?page=ebook&target=' . rawurlencode($path);
        }
        return home_url($path);
    }

    /**
     * Three message variants, because the same words cannot serve someone who
     * finished in March, someone who finished an hour ago, and someone who
     * stopped at lesson three.
     */
    public static function build_message($segment, $display_name = '', $user_id = 0, $preview = false)
    {
        $url = self::survey_url($user_id, $preview
            ? (($segment === 'stalled' || $segment === 'not_started') ? 'stalled' : 'finished')
            : '');

        if ($segment === 'finished' || $segment === 'near') {
            $hero  = 'เนื้อหาใหม่กำลังมาเพิ่ม';
            $sub   = 'และเราอยากฟังความเห็นจากคุณก่อน';
            $title = 'ยังจำคอร์ส 101 ได้ไหมครับ';
            $body  = "ตอนนี้เรากำลังจะปรับปรุงคอร์สครั้งใหญ่ สิ่งที่ตอบวันนี้จะกำหนดว่าจะเพิ่มอะไรเข้าไปบ้าง\n\n"
                   . "มีเจ้าของหลายคนบอกว่าเนื้อหาบางส่วนยังไม่ลึกเท่าที่คาดไว้ เรารับฟังครับ "
                   . "และเนื้อหาที่เพิ่มจะฟรีสำหรับนักเรียนปัจจุบันทุกคน\n\n"
                   . "ระหว่างนี้ เรามีอีบุ๊กเจาะลึก 4 เล่ม อยากส่งให้ก่อนใคร เลือกได้เลย 1 เล่มฟรีครับ";
            $cta   = 'เลือกอีบุ๊ก และตอบแบบสอบถาม';
            $note  = 'ใช้เวลาประมาณ 3 นาที';
            $c1    = '#00AB8E'; $c2 = '#0076BA';
        } elseif ($segment === 'auto') {
            $hero  = 'เรียนจบ Dogology 101';
            $sub   = 'แล้ว 🎉';
            $title = 'ขอบคุณที่เรียนจนจบนะครับ';
            // Closes exactly like the blast: the ebook is offered as a gift, not
            // as payment for answering. "ตอบเสร็จแล้วรับอีบุ๊ก" made the whole
            // message read as a transaction.
            $body  = "ยินดีด้วยครับ จบครบทุกบทแล้ว\n\n"
                   . "เรากำลังจะขยายเนื้อหาครั้งใหญ่ และอยากรู้ว่าคอร์สนี้ใช้ได้จริงแค่ไหนกับหมาของคุณ "
                   . "สิ่งที่ตอบวันนี้จะกำหนดว่าจะเพิ่มอะไรเข้าไปบ้าง "
                   . "และเนื้อหาที่เพิ่มจะฟรีสำหรับนักเรียนปัจจุบันทุกคน\n\n"
                   . "ระหว่างนี้ เรามีอีบุ๊กเจาะลึก 4 เล่ม อยากส่งให้ก่อนใคร เลือกได้เลย 1 เล่มฟรีครับ";
            $cta   = 'เลือกอีบุ๊ก และตอบแบบสอบถาม';
            $note  = 'ใช้เวลาประมาณ 3 นาที';
            $c1    = '#00AB8E'; $c2 = '#0076BA';
        } else {
            $hero  = 'เราอยากรู้ว่า';
            $sub   = 'เราทำอะไรพลาดไป';
            $title = 'คอร์ส 101 ยังค้างอยู่ใช่ไหมครับ';
            $body  = "ไม่ได้จะมาตามให้เรียนต่อครับ\n\n"
                   . "เรากำลังจะปรับปรุงคอร์สครั้งใหญ่ และคนที่หยุดกลางทางคือคนที่บอกเราได้ตรงที่สุดว่าติดตรงไหน\n\n"
                   . "ขอ 1 นาที ตอบคำถามสั้น ๆ ว่าอะไรทำให้หยุด เนื้อหาที่เราปรับจะฟรีสำหรับทุกคนที่ซื้อไปแล้วครับ";
            $cta   = 'บอกเราหน่อย ใช้เวลา 1 นาที';
            $note  = 'ไม่ต้องเรียนต่อก็ตอบได้ครับ';
            $c1    = '#64748B'; $c2 = '#0076BA';
        }

        $bubble = array(
            'type' => 'bubble',
            'header' => array(
                'type' => 'box', 'layout' => 'vertical', 'paddingAll' => '20px',
                'backgroundColor' => $c1,
                'contents' => array(
                    array('type' => 'text', 'text' => $hero, 'color' => '#FFFFFF',
                          'weight' => 'bold', 'size' => 'lg', 'align' => 'center', 'wrap' => true),
                    array('type' => 'text', 'text' => $sub, 'color' => '#FFFFFF',
                          'weight' => 'bold', 'size' => 'lg', 'align' => 'center', 'wrap' => true),
                ),
            ),
            'body' => array(
                'type' => 'box', 'layout' => 'vertical', 'spacing' => 'md',
                'contents' => array(
                    array('type' => 'text', 'text' => $title, 'weight' => 'bold', 'size' => 'md', 'wrap' => true),
                    array('type' => 'text', 'text' => $body, 'size' => 'sm', 'color' => '#475569', 'wrap' => true),
                ),
            ),
            'footer' => array(
                'type' => 'box', 'layout' => 'vertical', 'spacing' => 'sm',
                'contents' => array(
                    array('type' => 'button', 'style' => 'primary', 'color' => $c2, 'height' => 'sm',
                          'action' => array('type' => 'uri', 'label' => $cta, 'uri' => $url)),
                    array('type' => 'text', 'text' => $note, 'size' => 'xxs',
                          'color' => '#94A3B8', 'align' => 'center', 'wrap' => true),
                ),
            ),
        );

        return array(array('type' => 'flex', 'altText' => $title, 'contents' => $bubble));
    }

    /** LINE push, reusing the MindMap sender when present. */
    public static function push($line_uid, $messages, $retry_key = '')
    {
        if (class_exists('Dogology_MindMap_Nurture_Sender')) {
            return Dogology_MindMap_Nurture_Sender::push($line_uid, $messages, $retry_key);
        }
        return array('ok' => false, 'error' => 'MindMap sender unavailable (dogology-mindmap inactive?)');
    }

    public static function retry_key($row_id)
    {
        return substr(md5('dl-survey-' . self::table() . '-' . (int) $row_id), 0, 32);
    }

    public static function tick()
    {
        global $wpdb;
        $t = self::table();
        if (!$wpdb->get_var("SELECT GET_LOCK('dogology_survey_tick', 0)")) return;
        try {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $t WHERE status='pending' ORDER BY id ASC LIMIT %d", self::BATCH_SIZE
            ));
            foreach ((array) $rows as $r) {
                // claim first — a crash after push must not look like 'pending'
                $claimed = $wpdb->update($t,
                    array('status' => 'sending', 'scheduled_for' => current_time('mysql')),
                    array('id' => $r->id, 'status' => 'pending'));
                if (!$claimed) continue;

                $seg = ($r->source === 'auto') ? 'auto' : $r->segment;
                $msg = self::build_message($seg, '', (int) $r->user_id);
                $res = self::push($r->line_uid, $msg, self::retry_key($r->id));

                if (!empty($res['ok'])) {
                    $wpdb->update($t, array('status' => 'sent', 'sent_at' => current_time('mysql'), 'error' => null),
                        array('id' => $r->id, 'status' => 'sending'));
                } else {
                    $wpdb->update($t, array('status' => 'failed',
                        'error' => substr((string) ($res['error'] ?? 'push failed'), 0, 500)),
                        array('id' => $r->id));
                }
            }
        } finally {
            $wpdb->query("SELECT RELEASE_LOCK('dogology_survey_tick')");
        }
        if (self::pending_count() > 0) {
            wp_schedule_single_event(time() + 120, self::TICK_HOOK);
        }
    }

    /**
     * Hourly: queue anyone newly finished, once the delay has elapsed.
     * Uses the sticky finished definition, so publishing a lesson never
     * re-qualifies people who already got their invite.
     */
    public static function auto_scan()
    {
        if (!self::auto_enabled()) return;
        global $wpdb;
        $delay = self::auto_delay_h() * HOUR_IN_SECONDS;
        $now   = time();
        $queued = 0;
        foreach (self::audience() as $uid => $ctx) {
            if ($ctx['segment'] !== 'finished') continue;
            if (!$ctx['last_activity']) continue;
            if (($now - strtotime($ctx['last_activity'])) < $delay) continue;
            if (Dogology_Learning_Survey::has_responded($uid)) continue;
            $line = $wpdb->get_var($wpdb->prepare(
                "SELECT line_uid FROM {$wpdb->prefix}dogology_users WHERE id = %d", $uid));
            if (!$line) continue;
            $ok = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO " . self::table() . "
                 (survey_key,user_id,line_uid,segment,source,lessons_done_at_send,
                  furthest_position_at_send,status,created_at)
                 VALUES (%s,%d,%s,'finished','auto',%d,%d,'pending',%s)",
                Dogology_Learning_Survey::SURVEY_KEY, $uid, $line,
                $ctx['lessons_done'], $ctx['furthest_pos'], current_time('mysql')
            ));
            if ($ok) $queued++;
        }
        if ($queued > 0) self::schedule_tick();
    }

    /** Send one message to a chosen LINE uid, bypassing the queue entirely. */
    public static function send_sample($line_uid, $segment)
    {
        $line_uid = trim((string) $line_uid);
        if ($line_uid === '') return array('ok' => false, 'error' => 'no line_uid');
        // no retry key: a sample must be re-sendable while iterating on copy
        global $wpdb;
        // resolve the recipient so the sample link carries a working token too
        $uid = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}dogology_users WHERE line_uid = %s LIMIT 1", $line_uid));
        return self::push($line_uid, self::build_message($segment, '', $uid, true), '');
    }
}
