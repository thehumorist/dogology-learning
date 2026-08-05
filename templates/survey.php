<?php
/**
 * Front-end survey page — /101-survey/
 *
 * Hand-written, NOT generated. The first version was produced by regex surgery
 * over the design mock and shipped broken: the `.opts` wrappers were consumed
 * leaving orphan </div>s (which split questions away from their answers) and
 * every section header was dropped. Markup this structural has to be authored.
 *
 * Interaction is CSS-only — :checked plus sibling combinators, no :has(), no
 * :target, no framework — because it runs inside the LINE in-app browser.
 * JavaScript covers only the submit POST.
 */
if (!defined('ABSPATH')) exit;

/**
 * A signed ?t= from the LINE message establishes the session. This is the
 * PRIMARY identity path; LIFF is only a fallback.
 *
 * login_student() writes the cookie with setcookie(), which does NOT populate
 * $_COOKIE for the current request — so re-reading the cookie here always came
 * back empty and the gate showed even though the token was valid. Load the
 * student directly for this request instead.
 */
$student = Dogology_Auth::get_current_student();
if (!$student && !empty($_GET['t'])) {
    $tok_uid = Dogology_Learning_Survey::verify_token(sanitize_text_field(wp_unslash($_GET['t'])));
    if ($tok_uid) {
        Dogology_Auth::login_student($tok_uid);          // persist for later requests
        $dl_db   = new Dogology_Student_DB();
        $student = $dl_db->get_student($tok_uid);        // and use it right now
    }
}
$ctx     = $student ? Dogology_Learning_Survey::context_for((int) $student->id) : null;

$display_name = $student && !empty($student->display_name) ? $student->display_name : '';
$initial      = $display_name !== '' ? mb_substr($display_name, 0, 1, 'UTF-8') : '?';
$topics       = $ctx ? Dogology_Learning_Survey::topics_for($ctx) : array();
$is_unf       = $ctx ? $ctx['is_unfinished'] : false;
$responded    = $student ? Dogology_Learning_Survey::has_responded((int) $student->id) : false;
$done_flag    = !empty($_GET['done']);

$topic_opts = array();
foreach ($topics as $k => $t) $topic_opts[$k] = $t['label'];

/** A labelled set of options inside its own .opts wrapper. */
function dl_srv_set($type, $name, $prefix, array $items)
{
    echo '<div class="opts">' . "\n";
    foreach ($items as $key => $label) {
        printf(
            '<input class="ci" type="%1$s" name="%2$s" value="%3$s" id="%4$s">'
            . '<label class="opt" for="%4$s"><i></i><span>%5$s</span></label>' . "\n",
            esc_attr($type), esc_attr($name), esc_attr($key),
            esc_attr($prefix . '_' . $key), esc_html($label)
        );
    }
    echo '</div>' . "\n";
}

function dl_srv_nav($back, $next, $submit = false)
{
    echo '<div class="nav">';
    if ($back)     printf('<label class="back" for="w%d">ย้อนกลับ</label>', (int) $back);
    if ($submit)   echo '<button type="submit" class="next">ส่งคำตอบ</button>';
    elseif ($next) printf('<label class="next" for="w%d">ถัดไป</label>', (int) $next);
    echo '</div>' . "\n";
}

/**
 * The two paths visit different pages, so nav targets are resolved from the
 * live sequence. Hard-coding them is how a "next" button ends up pointing at a
 * page that is hidden for this student.
 */
$seq = $is_unf ? array(1, 2, 3, 4, 5, 6, 8, 10) : array(1, 2, 3, 4, 5, 6, 7, 8, 9, 10);
$nav = function ($n) use ($seq) {
    $i = array_search($n, $seq, true);
    if ($i === false) return array('back' => 0, 'next' => 0, 'last' => false, 'skip' => true);
    return array(
        'back' => ($i > 0) ? $seq[$i - 1] : 0,
        'next' => ($i < count($seq) - 1) ? $seq[$i + 1] : 0,
        'last' => ($i === count($seq) - 1),
        'skip' => false,
    );
};
?><!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title>แบบสอบถาม Dogology 101</title>
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600;700&family=Noto+Sans+Thai+Looped:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
<?php echo Dogology_Learning_Survey::page_css();

// The "which worked best" list is filtered by the checklist above it, and the
// rules are per-topic — so they have to be emitted for the topics THIS student
// actually reached. A static stylesheet cannot know that set.
foreach (array_keys($topic_opts) as $k) {
    printf("#t_%1\$s:checked ~ .bestwrap #b_%1\$s{display:flex}\n", $k);
}
if ($topic_opts) {
    $sel = array();
    foreach (array_keys($topic_opts) as $k) $sel[] = '#t_' . $k . ':checked ~ .bestwrap .empty';
    echo implode(',', $sel) . "{display:none}\n";
}
// Only the pages this student visits should exist at all.
$hide = array_diff(range(1, 10), $seq);
foreach ($hide as $h) printf(".p%d{display:none !important}\n", $h);
?>
</style>
</head>
<body>

<?php if ($responded || $done_flag): ?>
  <div class="gate">
    <h1>ได้รับคำตอบแล้วครับ</h1>
    <p>ขอบคุณที่สละเวลาตอบนะครับ<br>เราจะส่งอีบุ๊กให้ทาง LINE เร็ว ๆ นี้</p>
    <a class="gatebtn" href="<?php echo esc_url(home_url('/my-courses/')); ?>">กลับไปหน้าคอร์สของฉัน</a>
  </div>

<?php elseif (!$ctx): ?>
  <?php $mm = get_option('dogology_mindmap_settings', array());
        $liff_id = isset($mm['liff_id']) ? trim((string) $mm['liff_id']) : ''; ?>
  <div class="gate" id="gate">
    <h1 id="gate-title">กำลังตรวจสอบข้อมูล...</h1>
    <p id="gate-body">ถ้าค้างอยู่นานเกินไป กดปุ่มด้านล่างเพื่อเข้าสู่ระบบได้เลยครับ</p>
    <a class="gatebtn" href="<?php echo esc_url(home_url('/student-login/')); ?>">เข้าสู่ระบบ</a>
  </div>
  <?php if ($liff_id): ?>
  <script src="https://static.line-login.jp/liff/edge/2/sdk.js"></script>
  <script>
  // Resolve LINE identity into a learning session. The ID token is verified
  // server-side — getProfile() is client-supplied and the reward is a product
  // we sell, so a forged profile must never be enough to claim an ebook.
  (function () {
    function stop(t, b) {
      document.getElementById('gate-title').textContent = t;
      document.getElementById('gate-body').textContent  = b;
    }
    // In an external browser liff.init() may never settle, which left the gate
    // stuck on "checking" forever. Always resolve the UI within 4s.
    var settled = false;
    setTimeout(function () {
      if (!settled) stop('ยังไม่พบข้อมูลผู้เรียน', 'เปิดลิงก์นี้จากแชท LINE ของ Dogology หรือกดเข้าสู่ระบบด้านล่างครับ');
    }, 4000);
    if (typeof liff === 'undefined') { settled = true; stop('ยังไม่พบข้อมูลผู้เรียน', 'เปิดลิงก์นี้จากแชท LINE ของ Dogology หรือกดเข้าสู่ระบบด้านล่างครับ'); return; }
    liff.init({liffId: '<?php echo esc_js($liff_id); ?>'}).then(function () {
      // Only bounce through LINE login when we are actually inside LINE —
      // in an external browser that redirect is a dead end.
      if (!liff.isLoggedIn()) {
        settled = true;
        if (liff.isInClient && liff.isInClient()) { liff.login({redirectUri: location.href}); }
        else { stop('ยังไม่พบข้อมูลผู้เรียน', 'เปิดลิงก์นี้จากแชท LINE ของ Dogology หรือกดเข้าสู่ระบบด้านล่างครับ'); }
        return;
      }
      settled = true;
      return fetch('<?php echo esc_url_raw(rest_url('dogology-learning/v1/survey-liff')); ?>', {
        method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({id_token: liff.getIDToken()})
      }).then(function (r) { return r.json(); }).then(function (j) {
        if (j && j.ok) { location.reload(); return; }
        stop('ยังไม่พบข้อมูลผู้เรียน',
             j && j.error === 'no_student'
               ? 'บัญชี LINE นี้ยังไม่ได้ผูกกับคอร์ส ลองเข้าสู่ระบบด้วยอีเมลที่ใช้ซื้อคอร์สครับ'
               : 'เข้าสู่ระบบก่อนได้เลยครับ');
      });
    }).catch(function () {
      stop('ยังไม่พบข้อมูลผู้เรียน', 'เปิดลิงก์นี้จากแชท LINE ของ Dogology หรือเข้าสู่ระบบก่อนครับ');
    });
  })();
  </script>
  <?php endif; ?>

<?php else: ?>
<form id="survey-form" method="post">
<?php
/**
 * The wizard radios must be SIBLINGS OF .shell — the page rules are
 * `#wN:checked ~ .shell .pN`. They were emitted outside the <form> while
 * .shell sits inside it, so the combinator never matched and every panel
 * stayed hidden: a completely blank page.
 */
?>
<input class="ci" type="radio" name="wz" id="w1" checked>
<?php for ($i = 2; $i <= 10; $i++) printf('<input class="ci" type="radio" name="wz" id="w%d">' . "\n", $i); ?>
<div class="shell">
  <div class="form">
    <div class="prog"><div class="bar"><div class="fill"></div></div></div>

    <!-- 1 — EBOOK -->
    <section class="panel p1">
      <div class="hero">
        <p class="eyebrow">Dogology 101</p>
        <h1>ก่อนอื่น <span class="g">เลือกอีบุ๊กฟรี 1 เล่ม</span></h1>
        <p class="lede">ตอนนี้เรากำลังจะปรับปรุงคอร์สครั้งใหญ่ สิ่งที่ตอบวันนี้จะกำหนดว่าจะเพิ่มอะไรเข้าไปบ้าง ตอบตรง ๆ ได้เลยครับ ไม่ต้องเกรงใจ</p>
        <div class="who-strip">
          <div class="av"><?php echo esc_html($initial); ?></div>
          <div class="nm"><b><?php echo esc_html($display_name ?: 'ผู้เรียน'); ?></b><span>เข้าสู่ระบบด้วย LINE อัตโนมัติ</span></div>
          <span class="ok">ยืนยันแล้ว</span>
        </div>
      </div>
      <div class="sec">
        <p class="note">มีเจ้าของหลายคนบอกเรามาว่า เนื้อหาบางส่วนยังไม่ลึกเท่าที่คาดไว้ เรารับฟังและกำลังวางแผนขยายเนื้อหาครั้งใหญ่ครับ (เนื้อหาที่เพิ่มจะฟรีสำหรับนักเรียนปัจจุบันทุกคน)<br><br>ระหว่างที่ยังทำไม่เสร็จ เรามีอีบุ๊กที่เจาะลึกกว่าเดิมอยู่แล้ว 4 เล่ม และอยากส่งให้ทุกท่านก่อนใคร เลือกได้เลย 1 เล่มฟรีครับ</p>
        <p class="hint">แต่ละเล่มเจาะแต่ละกลุ่มปัญหาพฤติกรรมครับ อ่านคำอธิบายแล้วเลือกเล่มที่ตรงกับหมาของคุณที่สุดได้เลยครับ</p>
        <div class="books">
          <?php foreach (Dogology_Learning_Survey::ebooks() as $slug => $b): ?>
            <input class="ci" type="radio" name="ebook_choice" value="<?php echo esc_attr($slug); ?>" id="ebk_<?php echo esc_attr($slug); ?>">
            <label class="book" for="ebk_<?php echo esc_attr($slug); ?>">
              <div class="bookrow">
                <div class="cover"><img src="<?php echo esc_url($b['cover']); ?>" alt="<?php echo esc_attr($b['title']); ?>" loading="lazy"></div>
                <div class="bookmain">
                  <h3><?php echo esc_html($b['title']); ?></h3>
                  <p class="en"><?php echo esc_html($b['en']); ?></p>
                  <p><?php echo esc_html($b['desc']); ?></p>
                </div>
              </div>
              <p class="who">เหมาะกับ: <?php echo esc_html($b['who']); ?></p>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
      <?php $n = $nav(1); dl_srv_nav($n['back'], $n['next'], $n['last']); ?>
    </section>

    <!-- 2 — APPLIED (finished) / WHAT STOPPED YOU (unfinished) -->
    <section class="panel p2">
      <div class="sec">
        <div class="step"><span class="n">ขั้นที่ 2</span><h2><?php echo $is_unf ? 'อะไรทำให้หยุด' : 'สิ่งที่เอาไปใช้จริง'; ?></h2></div>
        <?php if (!$is_unf): ?>
          <div class="q qapplied">
            <span class="lab">เรื่องไหนที่คุณเอาไปใช้จริงบ้าง *</span>
            <span class="sub">เลือกได้หลายข้อ</span>
            <div class="opts">
              <?php foreach ($topic_opts as $k => $label): ?>
                <input class="ci" type="checkbox" name="applied[]" value="<?php echo esc_attr($k); ?>" id="t_<?php echo esc_attr($k); ?>"><label class="opt" for="t_<?php echo esc_attr($k); ?>"><i></i><span><?php echo esc_html($label); ?></span></label>
              <?php endforeach; ?>
              <input class="ci" type="checkbox" name="applied[]" value="none" id="t_none"><label class="opt" for="t_none"><i></i><span>ยังไม่ได้ลองอะไรเลย</span></label>
            </div>
            <div class="bestwrap">
              <span class="sub2">แล้วเรื่องไหนได้ผลที่สุด *</span>
              <span class="sub">รายการจะขึ้นตามที่ติ๊กไว้ด้านบน</span>
              <div class="opts">
                <?php foreach ($topic_opts as $k => $label): ?>
                  <input class="ci" type="radio" name="best_topic" value="<?php echo esc_attr($k); ?>" id="bi_<?php echo esc_attr($k); ?>"><label class="opt bopt" id="b_<?php echo esc_attr($k); ?>" for="bi_<?php echo esc_attr($k); ?>"><i></i><span><?php echo esc_html($label); ?></span></label>
                <?php endforeach; ?>
              </div>
              <p class="empty">ติ๊กด้านบนก่อน แล้วรายการจะขึ้นตรงนี้</p>
            </div>
            <div class="subq">
              <span class="sub2">แล้วทำไมถึงเป็นเรื่องนั้น *</span>
              <textarea name="best_reason" rows="3" placeholder="เล่าให้ฟังหน่อยว่าใช้แล้วเกิดอะไรขึ้น"></textarea>
            </div>
          </div>
        <?php else: ?>
          <div class="q">
            <span class="lab">มีอะไรที่ทำให้เรียนไม่ต่อเนื่องบ้าง *</span>
            <span class="sub">เลือกได้หลายข้อ ตอบตรง ๆ ได้เลยครับ</span>
            <?php dl_srv_set('checkbox', 'friction[]', 'fr', Dogology_Learning_Survey::options('friction')); ?>
          </div>
        <?php endif; ?>
      </div>
      <?php $n = $nav(2); dl_srv_nav($n['back'], $n['next'], $n['last']); ?>
    </section>

    <!-- 3 — LIKED / EXPECTATION -->
    <section class="panel p3">
      <div class="sec">
        <div class="step"><span class="n">ขั้นที่ 3</span><h2><?php echo $is_unf ? 'ความคาดหวังตอนซื้อ' : 'เนื้อหาที่ชอบ'; ?></h2></div>
        <?php if (!$is_unf): ?>
          <div class="q">
            <span class="lab">เนื้อหาส่วนไหนที่ชอบที่สุด *</span>
            <span class="sub">เลือกได้หลายข้อ ไม่จำเป็นต้องเป็นเรื่องที่เอาไปใช้</span>
            <?php dl_srv_set('checkbox', 'liked[]', 'lk', $topic_opts); ?>
          </div>
        <?php else: ?>
          <div class="q">
            <span class="lab">ตอนที่ซื้อคอร์ส คุณคาดหวังว่าจะได้อะไร *</span>
            <span class="sub">ตอบตามที่คิดตอนนั้นได้เลยครับ</span>
            <textarea name="expectation" rows="5" placeholder="ตอนนั้นคิดว่าจะได้อะไรจากคอร์สนี้"></textarea>
          </div>
        <?php endif; ?>
      </div>
      <?php $n = $nav(3); dl_srv_nav($n['back'], $n['next'], $n['last']); ?>
    </section>

    <!-- 4 — WORTH / COMEBACK -->
    <section class="panel p4">
      <div class="sec">
        <div class="step"><span class="n">ขั้นที่ 4</span><h2><?php echo $is_unf ? 'ทางกลับมา' : 'ความคุ้มค่า'; ?></h2></div>
        <?php if (!$is_unf): ?>
          <div class="q">
            <span class="lab">คอร์สนี้คุ้มค่ากับที่จ่ายไปแค่ไหน *</span>
            <div class="scale">
              <?php $caps = array(1 => 'ไม่คุ้ม', 2 => 'คุ้มน้อย', 3 => 'พอใช้', 4 => 'คุ้ม', 5 => 'คุ้มมาก');
              foreach ($caps as $v => $cap): ?>
                <input class="ci" type="radio" name="worth_rating" value="<?php echo (int) $v; ?>" id="wo<?php echo (int) $v; ?>"><label class="sc" for="wo<?php echo (int) $v; ?>"><span class="num"><?php echo (int) $v; ?></span><span class="cap"><?php echo esc_html($cap); ?></span></label>
              <?php endforeach; ?>
            </div>
          </div>
        <?php else: ?>
          <div class="q">
            <span class="lab">อะไรที่จะทำให้คุณกลับมาเรียนต่อ</span>
            <span class="sub">เลือกได้หลายข้อ</span>
            <?php dl_srv_set('checkbox', 'comeback[]', 'cb', Dogology_Learning_Survey::options('comeback')); ?>
          </div>
        <?php endif; ?>
      </div>
      <?php $n = $nav(4); dl_srv_nav($n['back'], $n['next'], $n['last']); ?>
    </section>

    <!-- 5 — WHAT TO ADD -->
    <section class="panel p5">
      <div class="sec">
        <div class="step"><span class="n">ขั้นที่ 5</span><h2>อยากให้เพิ่มอะไร</h2></div>
        <div class="q">
          <span class="lab">อยากให้เพิ่มหรือปรับอะไรในคอร์สบ้าง *</span>
          <span class="sub">เลือกได้หลายข้อ</span>
          <?php dl_srv_set('checkbox', 'add[]', 'ad', Dogology_Learning_Survey::options('add')); ?>
          <textarea name="add_other" rows="3" style="margin-top:8px" placeholder="ถ้าเลือกอื่น ๆ หรืออยากขยายความ เขียนตรงนี้ได้เลย"></textarea>
        </div>
      </div>
      <?php $n = $nav(5); dl_srv_nav($n['back'], $n['next'], $n['last']); ?>
    </section>

    <!-- 6 — OUTCOME -->
    <section class="panel p6">
      <div class="sec">
        <div class="step"><span class="n">ขั้นที่ 6</span><h2>ผลลัพธ์กับหมา</h2></div>
        <div class="q">
          <span class="lab">อะไรที่เรียนจากในคอร์สแล้วช่วยให้หมาของคุณเปลี่ยนไปบ้าง เล่าให้ฟังหน่อย หรือไม่เปลี่ยนไปเลยก็บอกได้</span>
          <span class="sub">เล่าเป็นเหตุการณ์ก็ได้ครับ เช่น เมื่อก่อนเป็นแบบไหน ตอนนี้เป็นแบบไหน</span>
          <textarea name="outcome" rows="5" placeholder="ยิ่งเล่าละเอียด ยิ่งช่วยเราได้มาก"></textarea>
        </div>
      </div>
      <?php $n = $nav(6); dl_srv_nav($n['back'], $n['next'], $n['last']); ?>
    </section>

    <!-- 7 — FRICTION (finished path only) -->
    <section class="panel p7">
      <div class="sec">
        <div class="step"><span class="n">ขั้นที่ 7</span><h2>ความต่อเนื่องในการเรียน</h2></div>
        <div class="q">
          <span class="lab">มีอะไรที่ทำให้เรียนไม่ต่อเนื่องบ้าง</span>
          <span class="sub">เลือกได้หลายข้อ</span>
          <?php dl_srv_set('checkbox', 'friction[]', 'fa', Dogology_Learning_Survey::options('friction')); ?>
        </div>
      </div>
      <?php $n = $nav(7); dl_srv_nav($n['back'], $n['next'], $n['last']); ?>
    </section>

    <!-- 8 — COMMENTS -->
    <section class="panel p8">
      <div class="sec">
        <div class="step"><span class="n">ขั้นที่ 8</span><h2>เพิ่มเติม</h2></div>
        <div class="q">
          <span class="lab">อยากบอกอะไรเพิ่มเติมไหม</span>
          <textarea name="comments" rows="4" placeholder="อะไรก็ได้"></textarea>
        </div>
      </div>
      <?php $n = $nav(8); dl_srv_nav($n['back'], $n['next'], $n['last']); ?>
    </section>

    <!-- 9 — STARS (finished path only) -->
    <section class="panel p9">
      <div class="sec">
        <div class="step"><span class="n">ขั้นที่ 9</span><h2>ให้คะแนนโดยรวม</h2></div>
        <div class="q">
          <span class="lab" style="text-align:center">ให้คะแนนคอร์สนี้โดยรวม *</span>
          <div class="starbox">
            <div class="stars">
              <?php foreach (array(5, 4, 3, 2, 1) as $v): ?>
                <input class="ci" type="radio" name="star_rating" value="<?php echo (int) $v; ?>" id="s<?php echo (int) $v; ?>"><label for="s<?php echo (int) $v; ?>">&#9733;</label>
              <?php endforeach; ?>
            </div>
            <p class="starcap">แตะดาวเพื่อให้คะแนน</p>
          </div>
        </div>
      </div>
      <?php $n = $nav(9); dl_srv_nav($n['back'], $n['next'], $n['last']); ?>
    </section>

    <!-- 10 — CONSENT -->
    <section class="panel p10">
      <div class="sec">
        <div class="step"><span class="n">ขั้นสุดท้าย</span><h2>ปิดท้าย</h2></div>
        <div class="q">
          <span class="lab">เรื่องของน้องอาจช่วยเจ้าของคนอื่นได้</span>
          <span class="sub">เจ้าของหลายคนกำลังเจอปัญหาแบบเดียวกับที่คุณเคยเจอ สิ่งที่คุณเล่ามาอาจเป็นสิ่งที่เขากำลังตามหาอยู่ครับ</span>
          <div class="perm">
            <input class="ci" type="checkbox" name="consent_testimonial" value="1" id="consent"><label class="opt" for="consent" style="margin:0"><i></i><span>ให้ Dogology นำคำตอบไปใช้เล่าต่อได้ เช่น ในเว็บไซต์หรือโซเชียล</span></label>
            <div style="margin-top:12px">
              <span class="sub">น้องหมาชื่ออะไร</span>
              <input type="text" name="dog_name" placeholder="เช่น ข้าวปั้น">
            </div>
          </div>
        </div>
        <p class="fine">กดส่งแล้วเราจะส่งอีบุ๊กให้ทาง LINE ครับ</p>
      </div>
      <?php $n = $nav(10); dl_srv_nav($n['back'], 0, true); ?>
    </section>

  </div>
</div>
</form>

<script>
(function () {
  var form = document.getElementById('survey-form');
  if (!form) return;
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    form.classList.add('sending');
    var fd = new FormData(form), out = {};
    fd.forEach(function (v, k) {
      if (k === 'wz') return;                       // wizard state, not an answer
      if (k.slice(-2) === '[]') { var b = k.slice(0, -2); (out[b] = out[b] || []).push(v); }
      else { out[k] = v; }
    });
    fetch('<?php echo esc_url_raw(rest_url('dogology-learning/v1/survey')); ?>', {
      method: 'POST', credentials: 'same-origin',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(out)
    }).then(function (r) { return r.json(); }).then(function (j) {
      if (j && j.ok) { location.href = '<?php echo esc_url_raw(home_url('/101-survey/?done=1')); ?>'; }
      else { form.classList.remove('sending'); alert((j && j.message) || 'ส่งไม่สำเร็จ ลองใหม่อีกครั้งครับ'); }
    }).catch(function () {
      form.classList.remove('sending'); alert('ส่งไม่สำเร็จ ลองใหม่อีกครั้งครับ');
    });
  });
})();
</script>
<?php endif; ?>
</body>
</html>
