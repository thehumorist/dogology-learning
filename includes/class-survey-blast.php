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
            email varchar(191) DEFAULT NULL,
            channel varchar(8) NOT NULL DEFAULT 'line',
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
        // accepted_args 1: the chain passes a unique arg to defeat cron dedup.
        add_action(self::TICK_HOOK, array(__CLASS__, 'tick'), 10, 1);
        add_action(self::AUTO_HOOK, array(__CLASS__, 'auto_scan'));
        // Safety net, independent of the auto-send toggle: if the chain ever
        // dies, the hourly scan re-arms a queue that still has work.
        add_action(self::AUTO_HOOK, array(__CLASS__, 'rescue_queue'));
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
        foreach ($rows as $r) {
            // Shape-check, not just non-empty: one live record holds "f", which
            // was picked as the send channel and could only ever 400 — and the
            // student has a perfectly good email we were skipping.
            if (!preg_match('/^U[0-9a-f]{32}$/i', (string) $r['line_uid'])) continue;
            $map[(int) $r['id']] = $r['line_uid'];
        }
        return $map;
    }

    /** user_id => email, for the students LINE can't reach. One query. */
    protected static function emails()
    {
        static $map = null;
        if ($map !== null) return $map;
        global $wpdb;
        $map = array();
        $rows = $wpdb->get_results(
            "SELECT id, email FROM {$wpdb->prefix}dogology_users WHERE email <> ''", ARRAY_A);
        foreach ($rows as $r) $map[(int) $r['id']] = $r['email'];
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
    public static function queue(array $segments, $source = 'blast', $use_email = true)
    {
        global $wpdb;
        $now = current_time('mysql');
        $added = 0; $skipped = 0; $noline = 0; $emailed = 0;
        $lines     = self::line_uids();
        $emails    = self::emails();
        $responded = self::responded_ids();
        foreach (self::audience() as $uid => $ctx) {
            if (!in_array($ctx['segment'], $segments, true)) continue;
            if (isset($responded[(int) $uid])) { $skipped++; continue; }

            // LINE first — it is where these students already talk to us. Email
            // is the fallback for the ones LINE cannot reach at all, who were
            // previously counted as unreachable and never asked.
            $line  = isset($lines[(int) $uid]) ? $lines[(int) $uid] : '';
            $email = isset($emails[(int) $uid]) ? $emails[(int) $uid] : '';
            if ($line) {
                $channel = 'line';
            } elseif ($use_email && $email && is_email($email)) {
                $channel = 'email'; $emailed++;
            } else {
                $noline++; continue;
            }

            $ok = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO " . self::table() . "
                 (survey_key,user_id,line_uid,email,channel,segment,source,lessons_done_at_send,
                  furthest_position_at_send,status,created_at)
                 VALUES (%s,%d,%s,%s,%s,%s,%s,%d,%d,'pending',%s)",
                Dogology_Learning_Survey::SURVEY_KEY, $uid, $line, $email, $channel,
                $ctx['segment'], $source, $ctx['lessons_done'], $ctx['furthest_pos'], $now
            ));
            if ($ok) $added++; else $skipped++;
        }
        if ($added > 0) self::schedule_tick();
        return compact('added', 'skipped', 'noline', 'emailed');
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
     * Rows claimed for sending that never reached a terminal state — the
     * process died between the claim and the status write. Without this they
     * are invisible (no count includes them), unsendable (retry only looked at
     * 'failed'), and they stop the tick chain, which keys on pending only.
     */
    public static function stale_sending_count()
    {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM " . self::table() . "
             WHERE status='sending' AND scheduled_for < DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
    }

    /** Re-queue anything stranded, and re-arm the chain if work remains. */
    public static function rescue_queue()
    {
        global $wpdb;
        $wpdb->query(
            "UPDATE " . self::table() . " SET status='pending'
             WHERE status='sending' AND scheduled_for < DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
        if (self::pending_count() > 0) self::schedule_tick();
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
    /**
     * The words, once. Flex and email both render from this, so the two can
     * never drift — an email that argues differently from the LINE message is
     * the same message twice with two different promises.
     *
     * @return array{hero,sub,title,body,cta,note,c1,c2}
     */
    public static function copy($segment)
    {
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
            // Earlier copy opened with "ไม่ได้จะมาตามให้เรียนต่อครับ", which
            // disowned the follow-up entirely — we DO care that someone stopped.
            // The stance now: stopping is a signal the course failed them, and
            // that is our problem to fix, not their homework to finish.
            $hero  = 'ถ้าเรียนแล้วไม่ได้ไปต่อ';
            $sub   = 'เราอยากรู้ว่าเพราะอะไรครับ';
            $title = 'คอร์ส 101 ยังค้างอยู่ใช่ไหมครับ';
            $body  = "เราสนใจตรงนี้จริง ๆ ครับ เพราะเวลามีคนหยุดกลางทาง ส่วนใหญ่แปลว่าคอร์สยังทำหน้าที่ได้ไม่ดีพอ "
                   . "ไม่ใช่ว่าคุณไม่พยายาม\n\n"
                   . "ตอนนี้เรากำลังจะปรับปรุงคอร์สครั้งใหญ่ และคนที่หยุดกลางทางคือคนที่บอกเราได้ตรงที่สุดว่าติดตรงไหน\n\n"
                   . "ขอเวลาสัก 1 นาที เล่าให้ฟังหน่อยครับว่าอะไรทำให้หยุดเรียนไป เพื่อเราจะได้ปรับปรุงได้ตรงจุดที่สุด "
                   . "และหลังจากนี้เนื้อหาที่เราปรับจะฟรีสำหรับทุกคนที่ซื้อไปแล้วนะครับ";
            $cta   = 'เล่าให้เราฟังหน่อยครับ';
            $note  = 'ตอบได้เลย ไม่ต้องกลับไปเรียนต่อก่อน';
            // Was grey, which read as a downgraded, second-class message.
            $c1    = '#0F766E'; $c2 = '#0076BA';
        }

        return compact('hero', 'sub', 'title', 'body', 'cta', 'note', 'c1', 'c2');
    }

    public static function build_message($segment, $display_name = '', $user_id = 0, $preview = false)
    {
        $url = self::survey_url($user_id, $preview
            ? (($segment === 'stalled' || $segment === 'not_started') ? 'stalled' : 'finished')
            : '');

        extract(self::copy($segment));

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

        // altText is the lock-screen notification — for most recipients it is
        // the ONLY line they read. $title alone ("คอร์ส 101 ยังค้างอยู่ใช่ไหมครับ")
        // strips away the sentence that redeems it and lands as a nag.
        return array(array(
            'type'     => 'flex',
            'altText'  => trim($hero . ' ' . $sub),
            'contents' => $bubble,
        ));
    }

    /** LINE push, reusing the MindMap sender when present. */
    public static function push($line_uid, $messages, $retry_key = '')
    {
        if (class_exists('Dogology_MindMap_Nurture_Sender')) {
            return Dogology_MindMap_Nurture_Sender::push($line_uid, $messages, $retry_key);
        }
        return array('ok' => false, 'error' => 'MindMap sender unavailable (dogology-mindmap inactive?)');
    }

    /**
     * Email twin of the flex bubble, for the students with no LINE id — they
     * were counted and skipped, which meant the one group we can't reach on
     * LINE also never got asked.
     *
     * @return array{subject:string, html:string}
     */
    public static function build_email($segment, $user_id = 0, $preview = false)
    {
        $c   = self::copy($segment);
        $url = self::survey_url($user_id, $preview
            ? (($segment === 'stalled' || $segment === 'not_started') ? 'stalled' : 'finished')
            : '');
        // Always the plain page for email: a liff.line.me link opened from an
        // inbox on desktop is a dead end.
        if (strpos($url, 'liff.line.me') !== false) {
            $path = '/101-survey/';
            if ($user_id) {
                $path = add_query_arg('t', Dogology_Learning_Survey::make_token($user_id), $path);
                if ($preview) {
                    $seg = ($segment === 'stalled' || $segment === 'not_started') ? 'stalled' : 'finished';
                    $path = add_query_arg(array(
                        'pv'  => $seg,
                        'pvs' => Dogology_Learning_Survey::preview_sig($user_id, $seg),
                    ), $path);
                }
            }
            $url = home_url($path);
        }

        // Preferred path: render through the commerce email shell — the same
        // wrapper every order confirmation uses, and the only one proven across
        // the clients our customers actually run. Hand-rolling a second shell
        // is how the first two attempts arrived unstyled.
        if (class_exists('Dogology_Commerce_Orders_API')
            && method_exists('Dogology_Commerce_Orders_API', 'get_email_template')) {

            $inner = '';
            foreach (preg_split("/\n\n+/", $c['body']) as $p) {
                $inner .= "<p style='font-size:16px;line-height:1.7;color:#555555;margin:0 0 16px;'>"
                        . nl2br(esc_html($p)) . "</p>";
            }
            $inner .= "<div style='text-align:center;margin:35px 0 12px;'>"
                    . "<a href='" . esc_url($url) . "' style='background-color:" . esc_attr($c['c2'])
                    . ";color:#ffffff;padding:14px 30px;text-decoration:none;border-radius:50px;"
                    . "font-weight:bold;display:inline-block;'>" . esc_html($c['cta']) . "</a></div>";
            $inner .= "<p style='font-size:13px;color:#999999;text-align:center;margin:0 0 24px;'>"
                    . esc_html($c['note']) . "</p>";
            $inner .= "<p style='font-size:13px;color:#999999;text-align:center;margin:0;'>"
                    . "หากปุ่มไม่ทำงาน สามารถคลิกที่ลิงก์นี้:<br>"
                    . "<a href='" . esc_url($url) . "' style='color:#00AB8E;word-break:break-all;'>"
                    . esc_html($url) . "</a></p>";

            // The flex hero/sub become the heading; the shell renders it.
            $heading = $c['hero'] . ' ' . $c['sub'];
            return array(
                'subject' => $c['title'],
                'html'    => Dogology_Commerce_Orders_API::get_email_template($heading, $inner),
            );
        }

        // Fallback shell, used only if commerce is inactive.
        // TABLE layout with bgcolor attributes, not styled <div>s. Mail clients
        // routinely drop background-color from a div and then auto-scale the
        // text, which is exactly how the first version arrived: no header band,
        // no button, everything huge. bgcolor + table cells survive that.
        $font = "font-family:'Noto Sans Thai',-apple-system,'Helvetica Neue',Tahoma,sans-serif";

        $paras = '';
        foreach (preg_split("/\n\n+/", $c['body']) as $p) {
            $paras .= '<p style="margin:0 0 16px;' . $font
                    . ';font-size:15px;line-height:1.8;color:#475569">' . nl2br(esc_html($p)) . '</p>';
        }

        $head = esc_attr($c['c1']);
        $btn  = esc_attr($c['c2']);
        $u    = esc_url($url);

        $html = '<!doctype html><html><head><meta charset="utf-8">'
          . '<meta name="viewport" content="width=device-width,initial-scale=1">'
          . '<meta name="x-apple-disable-message-reformatting">'
          . '<style>body{margin:0;padding:0;-webkit-text-size-adjust:100%;text-size-adjust:100%}'
          . 'a{text-decoration:none}</style></head>'
          . '<body style="margin:0;padding:0;background-color:#F1F5F9">'
          . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#F1F5F9">'
          . '<tr><td align="center" style="padding:24px 12px">'

          . '<table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0"'
          . ' style="width:100%;max-width:560px;background-color:#ffffff;border-radius:14px;overflow:hidden">'

          // header band
          . '<tr><td bgcolor="' . $head . '" align="center"'
          . ' style="background-color:' . $head . ';padding:26px 24px">'
          . '<div style="' . $font . ';color:#ffffff;font-weight:700;font-size:19px;line-height:1.5">'
          . esc_html($c['hero']) . '</div>'
          . '<div style="' . $font . ';color:#ffffff;font-weight:700;font-size:19px;line-height:1.5">'
          . esc_html($c['sub']) . '</div>'
          . '</td></tr>'

          // body
          . '<tr><td style="padding:26px 24px">'
          . '<p style="margin:0 0 14px;' . $font . ';font-size:17px;font-weight:700;color:#0F172A">'
          . esc_html($c['title']) . '</p>'
          . $paras

          // bulletproof button: a one-cell table with bgcolor, not a styled <a>
          . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center"'
          . ' style="margin:26px auto 10px"><tr>'
          . '<td bgcolor="' . $btn . '" align="center"'
          . ' style="background-color:' . $btn . ';border-radius:999px">'
          . '<a href="' . $u . '" style="display:inline-block;' . $font
          . ';color:#ffffff;font-size:15px;font-weight:700;line-height:1.2;'
          . 'padding:15px 30px;text-decoration:none">' . esc_html($c['cta']) . '</a>'
          . '</td></tr></table>'

          . '<p style="margin:0;text-align:center;' . $font . ';font-size:12px;color:#94A3B8">'
          . esc_html($c['note']) . '</p>'
          . '<p style="margin:22px 0 0;' . $font . ';font-size:12px;color:#94A3B8;line-height:1.7">'
          . 'ถ้าปุ่มกดไม่ได้ เปิดลิงก์นี้ได้เลยครับ<br>'
          . '<a href="' . $u . '" style="color:#0F766E;word-break:break-all">' . esc_html($url) . '</a></p>'
          . '</td></tr>'

          // footer
          . '<tr><td bgcolor="#F8FAFC" align="center"'
          . ' style="background-color:#F8FAFC;padding:16px 24px;' . $font
          . ';font-size:11px;color:#94A3B8">Dogology · โรงเรียนฝึกสุนัข</td></tr>'

          . '</table></td></tr></table></body></html>';

        return array('subject' => $c['title'], 'html' => $html);
    }

    /** @return array{ok:bool,error?:string} */
    public static function send_email($to, $segment, $user_id = 0, $preview = false)
    {
        $to = sanitize_email((string) $to);
        if (!$to || !is_email($to)) return array('ok' => false, 'error' => 'invalid email');
        $m  = self::build_email($segment, $user_id, $preview);
        $ok = wp_mail($to, $m['subject'], $m['html'],
            array('Content-Type: text/html; charset=UTF-8'));
        return $ok ? array('ok' => true) : array('ok' => false, 'error' => 'wp_mail returned false');
    }

    /**
     * X-Line-Retry-Key must be a UUID. A bare 32-char md5 is the right length
     * but the wrong shape, and LINE rejects the whole push with HTTP 400 — so
     * every queued send failed while the ad-hoc samples (which pass no retry
     * key) went through. Deterministic per row, so a retry of the same invite
     * is still deduplicated by LINE for 24h.
     */
    public static function retry_key($row_id)
    {
        $h = md5('dl-survey-' . self::table() . '-' . (int) $row_id);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4)
             . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
    }

    public static function tick($chain_id = '')
    {
        global $wpdb;
        $t = self::table();
        if (!$wpdb->get_var("SELECT GET_LOCK('dogology_survey_tick', 0)")) return;

        // Arm the NEXT tick before sending anything. If PHP dies mid-batch
        // (a 20-row loop at a 10s push timeout can need 200s of wall clock,
        // well past a cron loopback's max_execution_time) the chain must not
        // die with it — the old code scheduled at the end, so one death left
        // the queue stopped forever with no symptom but a stalled counter.
        // The unique arg defeats WP's 10-minute identical-event dedup.
        // Mirrors dogology-mindmap's M4 blast, which learned this the hard way.
        if (self::pending_count() > 0) {
            wp_schedule_single_event(time() + 120, self::TICK_HOOK, array(uniqid('dlsv', true)));
        }

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
                if (($r->channel ?? 'line') === 'email') {
                    $res = self::send_email($r->email, $seg, (int) $r->user_id);
                } else {
                    $res = self::push($r->line_uid,
                        self::build_message($seg, '', (int) $r->user_id), self::retry_key($r->id));
                }

                if (!empty($res['ok'])) {
                    $wpdb->update($t, array('status' => 'sent', 'sent_at' => current_time('mysql'), 'error' => null),
                        array('id' => $r->id, 'status' => 'sending'));
                } else {
                    // A LINE push that fails for a recipient who also has an
                    // email is not a dead end — fall back rather than losing
                    // them. (One live record holds line_uid "f": non-empty, so
                    // it was chosen as the channel, and it can only ever 400.)
                    $err = substr((string) ($res['error'] ?? 'push failed'), 0, 500);
                    if (($r->channel ?? 'line') === 'line' && $r->email && is_email($r->email)) {
                        $res2 = self::send_email($r->email, $seg, (int) $r->user_id);
                        if (!empty($res2['ok'])) {
                            $wpdb->update($t, array(
                                'status'  => 'sent',
                                'channel' => 'email',
                                'sent_at' => current_time('mysql'),
                                'error'   => 'line failed (' . $err . ') — sent by email',
                            ), array('id' => $r->id, 'status' => 'sending'));
                            continue;
                        }
                    }
                    $wpdb->update($t, array('status' => 'failed', 'error' => $err),
                        array('id' => $r->id));
                }
            }
        } finally {
            $wpdb->query("SELECT RELEASE_LOCK('dogology_survey_tick')");
        }
        // The chain was already armed at the top of this tick. Nothing to do
        // here — re-arming would double the cadence.
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
