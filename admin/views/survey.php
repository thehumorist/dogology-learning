<?php
/**
 * Admin — Completion Survey: controls + responses.
 */
if (!defined('ABSPATH')) {
    exit;
}

$notice = '';
if (!empty($_POST['dl_survey_nonce']) && wp_verify_nonce($_POST['dl_survey_nonce'], 'dl_survey')) {
    $action = isset($_POST['dl_action']) ? sanitize_key($_POST['dl_action']) : '';

    if ($action === 'toggle_auto') {
        $on = !empty($_POST['auto_on']) ? '1' : '0';
        $was_on = Dogology_Learning_Survey_Blast::auto_enabled();
        update_option(Dogology_Learning_Survey_Blast::OPT_AUTO, $on);
        update_option(Dogology_Learning_Survey_Blast::OPT_DELAY_H, max(0, (int) ($_POST['delay_h'] ?? 24)));
        // Switching it ON sets today as the boundary, so automation covers
        // people who finish FROM NOW, not everyone who ever finished.
        if ($on === '1' && !$was_on) {
            Dogology_Learning_Survey_Blast::mark_auto_start();
        }
        $notice = $on === '1'
            ? 'เปิดการส่งอัตโนมัติแล้ว — คนที่เรียนจบใหม่จะได้รับแบบสอบถามหลังจบ ' . (int) ($_POST['delay_h'] ?? 24) . ' ชั่วโมง'
            : 'ปิดการส่งอัตโนมัติแล้ว';
    }

    if ($action === 'toggle_test') {
        $on  = !empty($_POST['test_on']) ? '1' : '0';
        $uid = (int) ($_POST['test_user'] ?? 0);
        update_option(Dogology_Learning_Survey::OPT_TEST_MODE, $on);
        update_option(Dogology_Learning_Survey::OPT_TEST_USER, $uid);
        $notice = $on === '1'
            ? 'โหมดทดสอบเปิดอยู่ — คำตอบของ student id ' . $uid . ' จะถูกล้างทุกครั้งที่ส่งใหม่ (คนอื่นไม่กระทบ)'
            : 'ปิดโหมดทดสอบแล้ว';
    }

    if ($action === 'reset_test') {
        $uid = Dogology_Learning_Survey::test_user();
        if (!$uid) {
            $notice = 'ยังไม่ได้ตั้ง student id สำหรับทดสอบ';
        } else {
            $r = Dogology_Learning_Survey::purge_response($uid);
            $notice = $r['response_id']
                ? sprintf('ล้างคำตอบของ student %d แล้ว (คำตอบย่อย %d, ออร์เดอร์ %s)',
                    $uid, $r['answers'], $r['order_id'] ?: '—')
                : 'ไม่พบคำตอบของ student ' . $uid;
        }
    }

    if ($action === 'sample') {
        $res = Dogology_Learning_Survey_Blast::send_sample(
            sanitize_text_field($_POST['sample_uid'] ?? ''),
            sanitize_key($_POST['sample_segment'] ?? 'finished')
        );
        $notice = !empty($res['ok'])
            ? 'ส่งตัวอย่างเรียบร้อย ตรวจใน LINE ได้เลย'
            // NOT esc_html here — $notice is escaped once where it is printed.
            // Escaping twice rendered LINE's API errors as literal &quot;.
            : 'ส่งตัวอย่างไม่สำเร็จ: ' . ($res['error'] ?? 'unknown');
    }

    if ($action === 'launch') {
        $segments = array_map('sanitize_key', (array) ($_POST['segments'] ?? array()));
        if (!$segments) {
            $notice = 'ยังไม่ได้เลือกกลุ่มผู้รับ';
        } else {
            $use_email = !empty($_POST['use_email']);
            $r = Dogology_Learning_Survey_Blast::queue($segments, 'blast', $use_email);
            $notice = sprintf(
                'เข้าคิวแล้ว %d คน (ทางอีเมล %d) — ข้าม %d (ตอบไปแล้ว/อยู่ในคิวแล้ว) — ติดต่อไม่ได้เลย %d คน',
                $r['added'], $r['emailed'], $r['skipped'], $r['noline']);
        }
    }

    if ($action === 'schedule') {
        $segments = array_map('sanitize_key', (array) ($_POST['segments'] ?? array()));
        $res = Dogology_Learning_Survey_Blast::schedule_launch(
            sanitize_text_field(str_replace('T', ' ', (string) ($_POST['blast_at'] ?? ''))),
            $segments,
            !empty($_POST['use_email'])
        );
        $notice = !empty($res['ok'])
            ? 'ตั้งเวลายิงไว้ที่ ' . $res['at'] . ' น. แล้ว (กลุ่ม: ' . implode(', ', $segments) . ')'
            : 'ตั้งเวลาไม่สำเร็จ: ' . ($res['error'] ?? 'unknown');
    }

    if ($action === 'unschedule') {
        Dogology_Learning_Survey_Blast::cancel_scheduled();
        $notice = 'ยกเลิกเวลายิงที่ตั้งไว้แล้ว';
    }

    if ($action === 'retry') {
        global $wpdb;
        // Also recover rows stranded in 'sending' — the process died between
        // the claim and the status write, so they were never sent and, before
        // this, could never be retried. The per-row retry key means LINE
        // dedupes any genuine redelivery for 24h, so this is safe.
        $wpdb->query("UPDATE " . Dogology_Learning_Survey_Blast::table() . "
                      SET status='pending', error=NULL
                      WHERE status='failed'
                         OR (status='sending' AND scheduled_for < DATE_SUB(NOW(), INTERVAL 10 MINUTE))");
        Dogology_Learning_Survey_Blast::schedule_tick();
        $notice = 'คิวส่งใหม่สำหรับรายการที่ล้มเหลวและที่ค้างอยู่แล้ว';
    }
}

global $wpdb;
$counts   = Dogology_Learning_Survey_Blast::counts();
$auto_on  = Dogology_Learning_Survey_Blast::auto_enabled();
$delay_h  = Dogology_Learning_Survey_Blast::auto_delay_h();
$pending  = Dogology_Learning_Survey_Blast::pending_count();
$sent     = Dogology_Learning_Survey_Blast::sent_count();
$failed   = Dogology_Learning_Survey_Blast::failed_count()
          + Dogology_Learning_Survey_Blast::stale_sending_count(); // stranded rows count as retryable
$rt       = Dogology_Learning_Survey::responses_table();
$at       = Dogology_Learning_Survey::answers_table();
$total    = (int) $wpdb->get_var("SELECT COUNT(*) FROM $rt");
$responses = $wpdb->get_results("SELECT r.*, u.display_name, u.email
    FROM $rt r LEFT JOIN {$wpdb->prefix}dogology_users u ON u.id = r.user_id
    ORDER BY r.submitted_at DESC LIMIT 100");
$topics = Dogology_Learning_Survey::topics();

// implementation rate per topic — the number the whole survey exists to produce
$applied = $wpdb->get_results("SELECT answer_key, COUNT(*) c FROM $at
    WHERE question_key='applied' GROUP BY answer_key ORDER BY c DESC", ARRAY_A);
$liked   = $wpdb->get_results("SELECT answer_key, COUNT(*) c FROM $at
    WHERE question_key='liked' GROUP BY answer_key ORDER BY c DESC", ARRAY_A);
$fric    = $wpdb->get_results("SELECT answer_key, COUNT(*) c FROM $at
    WHERE question_key='friction' GROUP BY answer_key ORDER BY c DESC", ARRAY_A);
$fopts   = Dogology_Learning_Survey::options('friction');
?>
<div class="wrap dogology-learning-wrap">
  <h1>Completion Survey</h1>

  <?php if ($notice): ?>
    <div class="notice notice-info inline"><p><?php echo esc_html($notice); ?></p></div>
  <?php endif; ?>

  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin:18px 0">
    <div class="dl-card" style="padding:20px;text-align:center">
      <div style="font-size:28px;font-weight:bold;color:#00AB8E"><?php echo number_format($total); ?></div>
      <div style="color:#666;font-size:13px">คำตอบทั้งหมด</div>
    </div>
    <div class="dl-card" style="padding:20px;text-align:center">
      <div style="font-size:28px;font-weight:bold;color:#3b82f6"><?php echo number_format($sent); ?></div>
      <div style="color:#666;font-size:13px">ส่งแล้ว</div>
    </div>
    <div class="dl-card" style="padding:20px;text-align:center">
      <div style="font-size:28px;font-weight:bold;color:#f59e0b"><?php echo number_format($pending); ?></div>
      <div style="color:#666;font-size:13px">รอส่ง</div>
    </div>
    <div class="dl-card" style="padding:20px;text-align:center">
      <div style="font-size:28px;font-weight:bold;color:<?php echo $failed ? '#dc2626' : '#666'; ?>">
        <?php echo number_format($failed); ?></div>
      <div style="color:#666;font-size:13px">ล้มเหลว</div>
    </div>
  </div>

  <!-- ============ CONTROLS ============ -->
  <div class="dl-card" style="margin-bottom:20px">
    <div class="dl-card-header"><h3 class="dl-card-title">ส่งตัวอย่างให้ตัวเอง</h3></div>
    <form method="post" style="padding:16px">
      <?php wp_nonce_field('dl_survey', 'dl_survey_nonce'); ?>
      <input type="hidden" name="dl_action" value="sample">
      <p style="margin-top:0;color:#666">
        ส่งข้อความจริงเข้า LINE ของตัวเองก่อน ไม่แตะคิวและไม่บันทึกลง ledger จึงส่งซ้ำได้เรื่อย ๆ
      </p>
      <input type="text" name="sample_uid" class="regular-text" placeholder="LINE user id (U....)" required>
      <select name="sample_segment">
        <option value="finished">A — เรียนจบไปสักพักแล้ว (blast)</option>
        <option value="auto">B — เพิ่งเรียนจบ (อัตโนมัติ)</option>
        <option value="stalled">C — ยังเรียนไม่จบ</option>
      </select>
      <button class="button button-secondary">ส่งตัวอย่าง</button>
    </form>
  </div>

  <div class="dl-card" style="margin-bottom:20px">
    <div class="dl-card-header"><h3 class="dl-card-title">ส่งอัตโนมัติเมื่อเรียนจบ</h3></div>
    <form method="post" style="padding:16px">
      <?php wp_nonce_field('dl_survey', 'dl_survey_nonce'); ?>
      <input type="hidden" name="dl_action" value="toggle_auto">
      <label style="display:block;margin-bottom:10px">
        <input type="checkbox" name="auto_on" value="1" <?php checked($auto_on); ?>>
        <strong>เปิดการส่งอัตโนมัติ</strong>
      </label>
      <label>หน่วงเวลาหลังเรียนจบ
        <input type="number" name="delay_h" value="<?php echo (int) $delay_h; ?>" min="0" max="720" style="width:80px">
        ชั่วโมง
      </label>
      <button class="button button-primary" style="margin-left:10px">บันทึก</button>
      <p style="color:#666;margin-bottom:0">
        สแกนทุกชั่วโมง ใช้นิยาม "เรียนจบ" แบบ sticky และบันทึกใน ledger
        คนหนึ่งได้รับครั้งเดียวตลอดชีวิต การเพิ่มบทเรียนใหม่จะไม่ทำให้ส่งซ้ำ<br>
        <strong>นับเฉพาะคนที่เรียนจบตั้งแต่วันที่เปิดสวิตช์เป็นต้นไป</strong> คนที่จบไปก่อนหน้านั้นให้ใช้ Blast แทนครับ
        <?php $as = Dogology_Learning_Survey_Blast::auto_since(); if ($as): ?>
          <br>เปิดใช้ตั้งแต่: <?php echo esc_html($as); ?>
        <?php endif; ?>
      </p>
    </form>
  </div>

  <div class="dl-card" style="margin-bottom:20px">
    <div class="dl-card-header"><h3 class="dl-card-title">โหมดทดสอบ</h3></div>
    <form method="post">
      <?php wp_nonce_field('dl_survey', 'dl_survey_nonce'); ?>
      <input type="hidden" name="dl_action" value="toggle_test">
      <label style="display:block;margin-bottom:10px">
        <input type="checkbox" name="test_on" value="1" <?php checked(Dogology_Learning_Survey::test_mode()); ?>>
        <strong>ล้างคำตอบอัตโนมัติทุกครั้งที่ส่ง</strong> (เฉพาะ student ด้านล่าง)
      </label>
      <label>student id สำหรับทดสอบ
        <input type="number" name="test_user" min="0"
               value="<?php echo (int) Dogology_Learning_Survey::test_user(); ?>" style="width:100px">
      </label>
      <button class="button button-primary" style="margin-left:10px">บันทึก</button>
      <button class="button" name="dl_action" value="reset_test" formnovalidate
              style="margin-left:6px">ล้างเดี๋ยวนี้</button>
      <p style="color:#666;margin-bottom:0">
        เปิดไว้ตอนทดสอบเท่านั้น คำตอบเดิมของ student คนนี้ พร้อมออร์เดอร์อีบุ๊กและสิทธิ์ที่ได้จากออร์เดอร์นั้น
        จะถูกลบทุกครั้งที่กดส่งใหม่ ไม่กระทบสิทธิ์คอร์ส 101 และไม่กระทบนักเรียนคนอื่น
        <strong>ปิดก่อนยิง Blast จริง</strong>
      </p>
    </form>
  </div>

  <div class="dl-card" style="margin-bottom:20px">
    <div class="dl-card-header"><h3 class="dl-card-title">ยิง Blast</h3></div>
    <?php /* The confirm belongs to the LAUNCH button alone. On the form it also
             fired for schedule / cancel / retry and for Enter in the date field,
             none of which send anything — which both misleads and trains the
             operator to dismiss it on the one press that matters. */ ?>
    <form method="post" style="padding:16px"
          onsubmit="return (!this.dl_launch_pressed || confirm('ยืนยันส่ง blast ให้กลุ่มที่เลือกเดี๋ยวนี้?'));">
      <?php wp_nonce_field('dl_survey', 'dl_survey_nonce'); ?>
      <input type="hidden" name="dl_action" value="launch">
      <?php
      $sched_at_pre  = Dogology_Learning_Survey_Blast::scheduled_at();
      $sched_seg_pre = Dogology_Learning_Survey_Blast::scheduled_segments();
      // With a schedule saved, the checkboxes must show what is ACTUALLY set.
      // They used to render the finished+near default regardless, so nudging
      // the time and re-saving silently rewrote the audience.
      $def_seg   = $sched_at_pre && $sched_seg_pre ? $sched_seg_pre : array('finished', 'near');
      $def_email = $sched_at_pre ? Dogology_Learning_Survey_Blast::scheduled_email() : true;
      $labels = array(
        'finished'    => 'เรียนจบแล้ว (ได้อีบุ๊ก)',
        'near'        => 'เกือบจบ 76-99% (ได้อีบุ๊ก)',
        'stalled'     => 'เริ่มแล้วแต่หยุดกลางทาง',
        'not_started' => 'ยังไม่เคยเปิดบทเรียนเลย',
      );
      foreach ($labels as $seg => $lbl): ?>
        <label style="display:block;margin-bottom:7px">
          <input type="checkbox" name="segments[]" value="<?php echo esc_attr($seg); ?>"
            <?php checked(in_array($seg, $def_seg, true)); ?>>
          <?php echo esc_html($lbl); ?>
          <strong>(<?php echo (int) ($counts[$seg] ?? 0); ?> คน)</strong>
          <?php $nl = (int) ($counts['no_line_by_segment'][$seg] ?? 0); if ($nl): ?>
            <span style="color:#b45309">— ไม่มี LINE <?php echo $nl; ?> คน</span>
          <?php endif; ?>
        </label>
      <?php endforeach; ?>
      <label style="display:block;margin:12px 0 4px">
        <input type="checkbox" name="use_email" value="1" <?php checked($def_email); ?>>
        ส่งอีเมลให้คนที่ไม่มี LINE (ข้อความเดียวกับ flex)
      </label>
      <p style="color:#666">
        รวมทั้งหมดที่ไม่มี LINE ID <strong><?php echo (int) $counts['no_line']; ?></strong> คน — ติ๊กด้านบนเพื่อส่งทางอีเมลแทน<br>
        กดซ้ำได้ปลอดภัย ระบบข้ามคนที่อยู่ในคิวหรือตอบไปแล้วเสมอ
      </p>
      <?php
      $sched_at  = Dogology_Learning_Survey_Blast::scheduled_at();
      $sched_seg = Dogology_Learning_Survey_Blast::scheduled_segments();
      // Default the picker to the next 19:00 site-time that is still ahead —
      // evening beats lunch for a survey people answer with the dog present.
      $default = date('Y-m-d\T19:00', strtotime(
          (date('H', strtotime(current_time('mysql'))) >= 19 ? 'tomorrow' : 'today'),
          strtotime(current_time('mysql'))));
      ?>
      <p style="margin:14px 0 6px"><strong>ตั้งเวลายิง</strong> (เวลาไทย) หรือกดยิงเดี๋ยวนี้ด้านล่าง</p>
      <input type="datetime-local" name="blast_at"
             value="<?php echo esc_attr($sched_at ? date('Y-m-d\TH:i', strtotime($sched_at)) : $default); ?>">
      <button class="button" name="dl_action" value="schedule" formnovalidate>บันทึกเวลายิง</button>
      <?php if ($sched_at): ?>
        <p style="color:#0F766E;margin:8px 0 0">
          ตั้งไว้: <strong><?php echo esc_html(date('d/m/Y H:i', strtotime($sched_at))); ?> น.</strong>
          — กลุ่ม <?php echo esc_html(implode(', ', $sched_seg)); ?>
          <?php echo Dogology_Learning_Survey_Blast::scheduled_email() ? '(ส่งอีเมลด้วย)' : '(LINE เท่านั้น)'; ?>
          <button class="button-link" name="dl_action" value="unschedule" formnovalidate
                  style="color:#b32d2e;margin-left:8px">ยกเลิก</button>
        </p>
        <?php if (Dogology_Learning_Survey::test_mode()): ?>
          <p style="color:#b32d2e;margin:6px 0 0"><strong>โหมดทดสอบเปิดอยู่ — ระบบจะไม่ยิงตามเวลาที่ตั้งไว้ ปิดก่อนครับ</strong></p>
        <?php endif; ?>
      <?php endif; ?>
      <?php $last = get_option(Dogology_Learning_Survey_Blast::OPT_LAST, ''); if ($last): ?>
        <p style="color:#666;margin:6px 0 0">ครั้งล่าสุด: <?php echo esc_html($last); ?></p>
      <?php endif; ?>

      <p style="margin:16px 0 6px"><strong>หรือยิงเดี๋ยวนี้</strong></p>
      <button class="button button-primary" onclick="this.form.dl_launch_pressed=1">เข้าคิวและเริ่มส่ง</button>
      <?php if ($failed): ?>
        <button class="button" name="dl_action" value="retry"
          formnovalidate>ส่งใหม่เฉพาะที่ล้มเหลว (<?php echo (int) $failed; ?>)</button>
      <?php endif; ?>
    </form>
  </div>

  <!-- ============ AGGREGATES ============ -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px;margin-bottom:20px">
    <?php
    $blocks = array(
      array('เอาไปใช้จริง', $applied, $topics, 'label'),
      array('ชอบที่สุด',    $liked,   $topics, 'label'),
      array('อะไรทำให้หยุด', $fric,   $fopts,  null),
    );
    foreach ($blocks as $b):
      list($title, $rows, $map, $sub) = $b; ?>
      <div class="dl-card">
        <div class="dl-card-header"><h3 class="dl-card-title"><?php echo esc_html($title); ?></h3></div>
        <table class="dl-table">
          <tbody>
          <?php if (!$rows): ?>
            <tr><td style="color:#999">ยังไม่มีข้อมูล</td></tr>
          <?php else: foreach ($rows as $row):
            $k = $row['answer_key'];
            $lbl = $sub ? ($map[$k][$sub] ?? $k) : ($map[$k] ?? $k); ?>
            <tr>
              <td><?php echo esc_html($lbl); ?></td>
              <td style="text-align:right;width:70px"><strong><?php echo (int) $row['c']; ?></strong></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- ============ RESPONSES ============ -->
  <?php
  /* ---------- Single response, in full ----------------------------------
     The table is a scan view: it shows the fields that fit and drops the
     multi-selects entirely, so the answers that took the respondent the
     longest were the ones the operator could not read. */
  $detail_id = isset($_GET['response']) ? (int) $_GET['response'] : 0;
  if ($detail_id):
      $d = $wpdb->get_row($wpdb->prepare(
          "SELECT r.*, u.display_name, u.email, u.line_uid
           FROM $rt r LEFT JOIN {$wpdb->prefix}dogology_users u ON u.id = r.user_id
           WHERE r.id = %d", $detail_id));
      $back = remove_query_arg('response');
      if (!$d): ?>
        <div class="notice notice-error inline"><p>ไม่พบคำตอบนี้</p></div>
        <p><a href="<?php echo esc_url($back); ?>">&larr; กลับไปหน้ารวม</a></p>
      <?php else:
      $rows = $wpdb->get_results($wpdb->prepare(
          "SELECT question_key, answer_key FROM $at WHERE response_id = %d", $detail_id), ARRAY_A) ?: array();
      $picked = array();
      foreach ($rows as $row) $picked[$row['question_key']][] = $row['answer_key'];

      // slug -> Thai label, per question. Stored answers are slugs by design.
      $label_maps = array(
          'applied'  => array_map(function ($t) { return $t['label']; }, $topics) + array('none' => 'ยังไม่ได้ลองอะไรเลย'),
          'liked'    => array_map(function ($t) { return $t['label']; }, $topics),
          'add'      => Dogology_Learning_Survey::options('add'),
          'friction' => Dogology_Learning_Survey::options('friction'),
          'comeback' => Dogology_Learning_Survey::options('comeback'),
      );
      $q_titles = array(
          'applied'  => 'เอาไปใช้จริง',
          'liked'    => 'ส่วนที่ชอบที่สุด',
          'add'      => 'อยากให้เพิ่ม / ปรับ',
          'friction' => 'อะไรทำให้เรียนไม่ต่อเนื่อง',
          'comeback' => 'อะไรจะทำให้กลับมาเรียนต่อ',
      );
      $txt = function ($v) { return $v !== null && $v !== '' ? $v : null; };

      /* Which questions this respondent was ACTUALLY SHOWN. The two paths ask
         different things (see templates/survey.php panel branches), so listing
         the full set for everyone made finisher-only questions look skipped by
         someone who was never asked them. Derived from the stored segment,
         which is always the real one even under a preview override. */
      $unf_resp = in_array($d->segment, array('stalled', 'not_started'), true);
      $asked = $unf_resp
          ? array('ebook_choice','friction','expectation','comeback','add','add_other','outcome','comments')
          : array('ebook_choice','applied','best_topic','liked','worth_rating','add','add_other',
                  'outcome','friction','comments','star_rating','consent');
      $was_asked = function ($k) use ($asked) { return in_array($k, $asked, true); };
      $not_asked = '<span style="color:#cbd2d9;font-size:13px">ไม่ได้ถาม (ไม่อยู่ในชุดคำถามของกลุ่มนี้)</span>';
      ?>
      <div class="dl-card" style="margin-bottom:20px;border:2px solid #00AB8E">
        <div class="dl-card-header" style="display:flex;justify-content:space-between;align-items:center">
          <h3 class="dl-card-title">คำตอบของ <?php echo esc_html($d->display_name ?: '(ไม่ระบุ)'); ?></h3>
          <a class="button" href="<?php echo esc_url($back); ?>">&larr; กลับไปหน้ารวม</a>
        </div>
        <div style="padding:18px">

          <p style="margin:0 0 16px;color:#666;font-size:13px">
            <?php echo esc_html($d->email ?: 'ไม่มีอีเมล'); ?>
            · <?php echo $d->line_uid ? 'มี LINE' : 'ไม่มี LINE'; ?>
            · ตอบเมื่อ <?php echo esc_html($d->submitted_at); ?>
            · student id <?php echo (int) $d->user_id; ?>
          </p>

          <?php /* Snapshot frozen at submit — never recomputed, so it stays true
                   even after the curriculum grows. */ ?>
          <div style="display:flex;gap:22px;flex-wrap:wrap;background:#F8FAFC;border-radius:10px;padding:14px 16px;margin-bottom:18px">
            <div><strong><?php echo esc_html($d->segment); ?></strong><div style="font-size:11px;color:#888">กลุ่ม ณ ตอนตอบ</div></div>
            <div><strong><?php echo (int) $d->lessons_done; ?>/<?php echo (int) $d->lessons_total; ?></strong><div style="font-size:11px;color:#888">บทที่เรียนจบ</div></div>
            <div><strong><?php echo (float) $d->completion_pct; ?>%</strong><div style="font-size:11px;color:#888">ความคืบหน้า</div></div>
            <div><strong>บทที่ <?php echo (int) $d->furthest_position; ?></strong><div style="font-size:11px;color:#888">ไปไกลสุด</div></div>
            <div><strong><?php echo $d->days_since_last_activity === null ? '—' : (int) $d->days_since_last_activity; ?></strong><div style="font-size:11px;color:#888">วันตั้งแต่เรียนครั้งล่าสุด</div></div>
            <div><strong><?php echo $d->days_since_first_touch === null ? '—' : (int) $d->days_since_first_touch; ?></strong><div style="font-size:11px;color:#888">วันตั้งแต่เริ่มเรียน</div></div>
          </div>

          <div style="display:flex;gap:22px;flex-wrap:wrap;margin-bottom:18px">
            <div><strong style="color:#f59e0b;font-size:18px"><?php echo $was_asked('star_rating') ? ($d->star_rating ? str_repeat('★', (int) $d->star_rating) : '—') : '·'; ?></strong><div style="font-size:11px;color:#888">ให้คะแนนโดยรวม<?php echo $was_asked('star_rating') ? '' : ' (ไม่ได้ถาม)'; ?></div></div>
            <div><strong style="font-size:18px"><?php echo $was_asked('worth_rating') ? ($d->worth_rating ? (int) $d->worth_rating . '/5' : '—') : '·'; ?></strong><div style="font-size:11px;color:#888">ความคุ้มค่า<?php echo $was_asked('worth_rating') ? '' : ' (ไม่ได้ถาม)'; ?></div></div>
            <div><strong style="font-size:15px"><?php echo esc_html($d->ebook_choice ?: '—'); ?></strong>
              <div style="font-size:11px;color:<?php echo $d->ebook_granted_at ? '#16a34a' : '#f59e0b'; ?>">
                <?php echo $d->ebook_choice ? ($d->ebook_granted_at ? 'ส่งแล้ว ' . esc_html($d->ebook_granted_at) : 'ยังไม่ได้ส่ง') : 'ไม่ได้เลือก'; ?>
              </div>
            </div>
          </div>

          <?php
          /* EVERY question we asked, answered or not. Skipping the empty ones
             made "they skipped it" indistinguishable from "we never asked",
             which is the difference between a weak signal and no signal. */
          foreach ($q_titles as $qk => $qt):
            $map = $label_maps[$qk] ?? array(); ?>
            <div style="margin-bottom:16px">
              <div style="font-weight:600;margin-bottom:6px"><?php echo esc_html($qt); ?></div>
              <?php if (!$was_asked($qk)): ?>
                <?php echo $not_asked; ?>
              <?php elseif (empty($picked[$qk])): ?>
                <span style="color:#b0b6bd;font-size:13px">ไม่ได้ตอบ</span>
              <?php else: foreach ($picked[$qk] as $ans): ?>
                <span style="display:inline-block;background:rgba(0,171,142,.08);color:#0F766E;
                             border-radius:999px;padding:4px 12px;margin:0 6px 6px 0;font-size:13px">
                  <?php echo esc_html($map[$ans] ?? $ans); ?></span>
              <?php endforeach; endif; ?>
            </div>
          <?php endforeach; ?>

          <?php
          $open = array(
              array('best_topic', 'แล้วเรื่องไหนได้ผลที่สุด', $d->best_topic
                    ? (($topics[$d->best_topic]['label'] ?? $d->best_topic)
                       . ($d->best_reason ? ' — ' . $d->best_reason : '')) : null),
              array('expectation', 'ตอนซื้อคาดหวังอะไร',   $txt($d->expectation)),
              array('outcome',     'ผลลัพธ์กับหมา',        $txt($d->outcome)),
              array('add_other',   'อยากให้เพิ่ม (อื่น ๆ)', $txt($d->add_other)),
              array('comments',    'ความเห็นเพิ่มเติม',     $txt($d->comments)),
          );
          foreach ($open as list($ok_key, $t, $v)): ?>
            <div style="margin-bottom:14px">
              <div style="font-weight:600;margin-bottom:4px"><?php echo esc_html($t); ?></div>
              <?php if (!$was_asked($ok_key)): ?>
                <div><?php echo $not_asked; ?></div>
              <?php elseif ($v === null): ?>
                <div style="color:#b0b6bd;font-size:13px">ไม่ได้ตอบ</div>
              <?php else: ?>
                <div style="background:#fff;border:1px solid #E2E8F0;border-radius:10px;padding:12px 14px;
                            white-space:pre-wrap;line-height:1.7"><?php echo esc_html($v); ?></div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>

          <div style="margin-top:18px;padding-top:16px;border-top:1px solid #eee">
            <div style="font-weight:600;margin-bottom:6px">การนำไปเล่าต่อ</div>
            <?php if ($d->consent_testimonial): ?>
              <p style="margin:0 0 8px;color:#16a34a">✓ ยินยอมให้นำคำตอบไปใช้เล่าต่อ<?php
                echo $d->dog_name ? ' — น้อง' . esc_html($d->dog_name) : ''; ?></p>
              <?php if (!empty($d->photo_attachment_id)):
                $src = wp_get_attachment_image_url((int) $d->photo_attachment_id, 'medium'); ?>
                <?php if ($src): ?>
                  <a href="<?php echo esc_url(wp_get_attachment_url((int) $d->photo_attachment_id)); ?>" target="_blank" rel="noopener">
                    <img src="<?php echo esc_url($src); ?>" alt="" style="max-width:260px;border-radius:12px">
                  </a>
                <?php endif; ?>
              <?php endif; ?>
            <?php elseif (!$was_asked('consent')): ?>
              <p style="margin:0;color:#cbd2d9">ไม่ได้ถาม (กลุ่มที่ยังเรียนไม่จบไม่ถูกถามเรื่องนี้)</p>
            <?php else: ?>
              <p style="margin:0;color:#888">ไม่ได้ให้ความยินยอม — ห้ามนำไปเผยแพร่ครับ</p>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endif;
  endif; ?>

  <div class="dl-card">
    <div class="dl-card-header"><h3 class="dl-card-title">คำตอบล่าสุด</h3></div>
    <table class="dl-table">
      <thead>
        <tr>
          <th>ผู้เรียน</th><th>กลุ่ม</th><th style="text-align:right">ความคืบหน้า</th>
          <th style="text-align:right">ดาว</th><th style="text-align:right">คุ้มค่า</th>
          <th>อีบุ๊ก</th><th>ผลลัพธ์ / ความเห็น</th><th>ยินยอม</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$responses): ?>
        <tr><td colspan="8" style="text-align:center;color:#999">ยังไม่มีคำตอบ</td></tr>
      <?php else: foreach ($responses as $r): ?>
        <tr>
          <td>
            <div><a href="<?php echo esc_url(add_query_arg('response', (int) $r->id)); ?>"><strong><?php
              echo esc_html($r->display_name ?: '(ไม่ระบุ)'); ?></strong></a></div>
            <div style="font-size:12px;color:#999"><?php echo esc_html($r->email); ?></div>
            <div style="font-size:11px;color:#bbb"><?php echo esc_html($r->submitted_at); ?></div>
            <a href="<?php echo esc_url(add_query_arg('response', (int) $r->id)); ?>"
               style="font-size:12px">ดูคำตอบเต็ม &rarr;</a>
          </td>
          <td><span class="dl-badge"><?php echo esc_html($r->segment); ?></span></td>
          <td style="text-align:right">
            <?php echo (int) $r->lessons_done; ?>/<?php echo (int) $r->lessons_total; ?>
            <div style="font-size:11px;color:#999">ถึงบทที่ <?php echo (int) $r->furthest_position; ?></div>
          </td>
          <td style="text-align:right"><?php echo $r->star_rating ? str_repeat('★', (int) $r->star_rating) : '—'; ?></td>
          <td style="text-align:right"><?php echo $r->worth_rating ? (int) $r->worth_rating : '—'; ?></td>
          <td><?php echo esc_html($r->ebook_choice ?: '—'); ?>
            <?php if ($r->ebook_choice && !$r->ebook_granted_at): ?>
              <div style="font-size:11px;color:#f59e0b">ยังไม่ได้ส่ง</div>
            <?php endif; ?>
          </td>
          <td style="max-width:340px">
            <?php if ($r->outcome): ?><div><?php echo esc_html($r->outcome); ?></div><?php endif; ?>
            <?php if ($r->best_reason): ?>
              <div style="font-size:12px;color:#666;margin-top:4px">
                <?php echo esc_html($topics[$r->best_topic]['label'] ?? $r->best_topic); ?>:
                <?php echo esc_html($r->best_reason); ?></div>
            <?php endif; ?>
            <?php if ($r->expectation): ?>
              <div style="font-size:12px;color:#0369a1;margin-top:4px">คาดหวัง: <?php echo esc_html($r->expectation); ?></div>
            <?php endif; ?>
            <?php if ($r->comments): ?>
              <div style="font-size:12px;color:#666;margin-top:4px"><?php echo esc_html($r->comments); ?></div>
            <?php endif; ?>
          </td>
          <td>
            <?php echo $r->consent_testimonial ? '✓ ' . esc_html($r->dog_name) : '—'; ?>
            <?php if (!empty($r->photo_attachment_id)):
              $src = wp_get_attachment_image_url((int) $r->photo_attachment_id, 'thumbnail'); ?>
              <?php if ($src): ?>
                <a href="<?php echo esc_url(wp_get_attachment_url((int) $r->photo_attachment_id)); ?>" target="_blank" rel="noopener">
                  <img src="<?php echo esc_url($src); ?>" alt="" style="display:block;width:56px;height:56px;object-fit:cover;border-radius:8px;margin-top:6px">
                </a>
              <?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
