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
        update_option(Dogology_Learning_Survey_Blast::OPT_AUTO, $on);
        update_option(Dogology_Learning_Survey_Blast::OPT_DELAY_H, max(0, (int) ($_POST['delay_h'] ?? 24)));
        $notice = $on === '1'
            ? 'เปิดการส่งอัตโนมัติแล้ว — คนที่เรียนจบใหม่จะได้รับแบบสอบถามหลังจบ ' . (int) ($_POST['delay_h'] ?? 24) . ' ชั่วโมง'
            : 'ปิดการส่งอัตโนมัติแล้ว';
    }

    if ($action === 'sample') {
        $res = Dogology_Learning_Survey_Blast::send_sample(
            sanitize_text_field($_POST['sample_uid'] ?? ''),
            sanitize_key($_POST['sample_segment'] ?? 'finished')
        );
        $notice = !empty($res['ok'])
            ? 'ส่งตัวอย่างเรียบร้อย ตรวจใน LINE ได้เลย'
            : 'ส่งตัวอย่างไม่สำเร็จ: ' . esc_html($res['error'] ?? 'unknown');
    }

    if ($action === 'launch') {
        $segments = array_map('sanitize_key', (array) ($_POST['segments'] ?? array()));
        if (!$segments) {
            $notice = 'ยังไม่ได้เลือกกลุ่มผู้รับ';
        } else {
            $r = Dogology_Learning_Survey_Blast::queue($segments, 'blast');
            $notice = sprintf('เข้าคิวแล้ว %d คน — ข้าม %d (ตอบไปแล้ว/อยู่ในคิวแล้ว) — ไม่มี LINE %d คน',
                $r['added'], $r['skipped'], $r['noline']);
        }
    }

    if ($action === 'retry') {
        global $wpdb;
        $wpdb->query("UPDATE " . Dogology_Learning_Survey_Blast::table() . "
                      SET status='pending', error=NULL WHERE status='failed'");
        Dogology_Learning_Survey_Blast::schedule_tick();
        $notice = 'คิวส่งใหม่สำหรับรายการที่ล้มเหลวแล้ว';
    }
}

global $wpdb;
$counts   = Dogology_Learning_Survey_Blast::counts();
$auto_on  = Dogology_Learning_Survey_Blast::auto_enabled();
$delay_h  = Dogology_Learning_Survey_Blast::auto_delay_h();
$pending  = Dogology_Learning_Survey_Blast::pending_count();
$sent     = Dogology_Learning_Survey_Blast::sent_count();
$failed   = Dogology_Learning_Survey_Blast::failed_count();
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
        คนหนึ่งได้รับครั้งเดียวตลอดชีวิต การเพิ่มบทเรียนใหม่จะไม่ทำให้ส่งซ้ำ
      </p>
    </form>
  </div>

  <div class="dl-card" style="margin-bottom:20px">
    <div class="dl-card-header"><h3 class="dl-card-title">ยิง Blast</h3></div>
    <form method="post" style="padding:16px"
          onsubmit="return confirm('ยืนยันส่ง blast ให้กลุ่มที่เลือก?');">
      <?php wp_nonce_field('dl_survey', 'dl_survey_nonce'); ?>
      <input type="hidden" name="dl_action" value="launch">
      <?php
      $labels = array(
        'finished'    => 'เรียนจบแล้ว (ได้อีบุ๊ก)',
        'near'        => 'เกือบจบ 76-99% (ได้อีบุ๊ก)',
        'stalled'     => 'เริ่มแล้วแต่หยุดกลางทาง',
        'not_started' => 'ยังไม่เคยเปิดบทเรียนเลย',
      );
      foreach ($labels as $seg => $lbl): ?>
        <label style="display:block;margin-bottom:7px">
          <input type="checkbox" name="segments[]" value="<?php echo esc_attr($seg); ?>"
            <?php checked(in_array($seg, array('finished', 'near'), true)); ?>>
          <?php echo esc_html($lbl); ?>
          <strong>(<?php echo (int) ($counts[$seg] ?? 0); ?> คน)</strong>
        </label>
      <?php endforeach; ?>
      <p style="color:#666">
        ไม่มี LINE ID <strong><?php echo (int) $counts['no_line']; ?></strong> คน — กลุ่มนี้ส่งไม่ได้ ต้องใช้อีเมลแทน<br>
        กดซ้ำได้ปลอดภัย ระบบข้ามคนที่อยู่ในคิวหรือตอบไปแล้วเสมอ
      </p>
      <button class="button button-primary">เข้าคิวและเริ่มส่ง</button>
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
            <div><?php echo esc_html($r->display_name ?: '(ไม่ระบุ)'); ?></div>
            <div style="font-size:12px;color:#999"><?php echo esc_html($r->email); ?></div>
            <div style="font-size:11px;color:#bbb"><?php echo esc_html($r->submitted_at); ?></div>
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
          <td><?php echo $r->consent_testimonial ? '✓ ' . esc_html($r->dog_name) : '—'; ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
