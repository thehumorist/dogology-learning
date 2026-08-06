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
$student  = Dogology_Auth::get_current_student();
$dl_token = '';
if (!$student && !empty($_GET['t'])) {
    $t       = sanitize_text_field(wp_unslash($_GET['t']));
    $tok_uid = Dogology_Learning_Survey::verify_token($t);
    if ($tok_uid) {
        // Deliberately NOT login_student(): that minted a 30-day LMS session,
        // so anyone holding the link owned the whole account — My Courses, the
        // player, every ebook download. The token now buys exactly one thing,
        // this survey, and rides along in the form instead of a cookie.
        $dl_token = $t;
        $dl_db    = new Dogology_Student_DB();
        $student  = $dl_db->get_student($tok_uid);
    }
}
$ctx     = $student ? Dogology_Learning_Survey::context_for((int) $student->id) : null;

// Signed preview override (sample sends only) — makes the page render as the
// segment the sample is advertising instead of the recipient's real progress.
// Rendering only: store() still records the real segment.
if ($ctx && !empty($_GET['pv']) && !empty($_GET['pvs'])) {
    $pv = sanitize_key(wp_unslash($_GET['pv']));
    if (Dogology_Learning_Survey::verify_preview((int) $student->id, $pv, sanitize_text_field(wp_unslash($_GET['pvs'])))) {
        $ctx['segment']       = $pv;
        $ctx['is_unfinished'] = Dogology_Learning_Survey::is_unfinished_segment($pv);
    }
}

$display_name = $student && !empty($student->display_name) ? $student->display_name : '';
$initial      = $display_name !== '' ? mb_substr($display_name, 0, 1, 'UTF-8') : '?';
$topics       = $ctx ? Dogology_Learning_Survey::topics_for($ctx) : array();
$is_unf       = $ctx ? $ctx['is_unfinished'] : false;
$responded    = $student ? Dogology_Learning_Survey::has_responded((int) $student->id) : false;
// Test mode clears the old response on SUBMIT — but the thank-you gate below
// meant the tester could never reach the form to submit again. Show them the
// wizard instead. Scoped to the one nominated test student, so a real
// respondent still gets the gate and cannot answer twice.
if ($responded && $student
    && Dogology_Learning_Survey::test_mode()
    && (int) $student->id === Dogology_Learning_Survey::test_user()) {
    $responded = false;
}
$done_flag    = !empty($_GET['done']);

/**
 * Where this person's book will actually arrive. The email cohort is BY
 * DEFINITION the students with no usable LINE id, and the page was telling
 * them four times that their gift was coming on LINE — including one line
 * stating as fact that we had already sent it there.
 */
$dl_ch = ($student && $student->line_uid) ? ' LINE ' : 'อีเมล';

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

/** "ขั้นที่ N" numbered by POSITION in this student's sequence, not by panel id. */
function dl_srv_step($panel, array $seq)
{
    $i = array_search($panel, $seq, true);
    if ($i === false) return '';
    if ($i === count($seq) - 1) return 'ขั้นสุดท้าย';
    return 'ขั้นที่ ' . ($i + 1);
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
// Panel 1 is the ebook picker. Every invited segment is now granted a book
// (Survey::EBOOK_SEGMENTS), so everyone sees it. Keep this list and that
// constant in step: showing the picker to a segment the gate refuses is a
// promise we then break, which is exactly what happened earlier today.
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
    <?php
    // Hand over the ebook here rather than sending them to My Courses to hunt
    // for it — it is the thing they answered for.
    $dl = $student ? Dogology_Learning_Survey::grant_download_url((int) $student->id) : '';
    ?>
    <?php if ($dl): ?>
      <p>ขอบคุณที่สละเวลาตอบนะครับ<br>ดาวน์โหลดอีบุ๊กได้เลย และเราส่งลิงก์ให้ทาง<?php echo $dl_ch; ?>ไว้ด้วยแล้ว</p>
      <a class="gatebtn" id="dlbtn"
         href="<?php echo esc_url(add_query_arg('openExternalBrowser', '1', $dl)); ?>"
         data-raw="<?php echo esc_url($dl); ?>">ดาวน์โหลดอีบุ๊ก</a>
      <p class="fine" style="margin-top:14px">
        ถ้าเปิดไม่ขึ้น กดค้างที่ลิงก์นี้เพื่อคัดลอกแล้วเปิดในเบราว์เซอร์ได้เลยครับ<br>
        <a href="<?php echo esc_url($dl); ?>" style="color:var(--teal-deep);word-break:break-all"><?php echo esc_html($dl); ?></a>
      </p>
      <?php
      // A PDF opened INSIDE the LIFF webview can't be saved — the viewer has no
      // download affordance. openExternalBrowser=1 only breaks out when a link
      // is tapped from a LINE chat; from inside a webview the escape hatch is
      // liff.openWindow({external:true}). The plain href stays as the fallback
      // for real browsers, where none of this applies.
      $dl_liff = trim((string) get_option('dogology_commerce_liff_id', ''));
      if ($dl_liff): ?>
      <?php /* Same SDK host commerce uses. */ ?>
      <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
      <script>
      // Mirrors dogology-commerce templates/success.php, which is the version
      // that actually works in production. Two things learned from it:
      //
      //  1. Do NOT gate the click on liff.init() resolving. Commerce checks
      //     isInClient() at click time and calls openWindow directly. My
      //     earlier version only called openWindow inside init().then(), so a
      //     failed init meant the escape never ran at all.
      //  2. Only init inside a genuine commerce-LIFF tab (the dcLiffCtx marker
      //     commerce's router sets). Commerce's own note: init from another
      //     LIFF's browser triggers LINE's OAuth redirect_uri 400 — which is
      //     the likeliest reason init was rejecting here.
      (function () {
        var b = document.getElementById('dlbtn');
        if (!b) return;

        var inCommerceLiff = false;
        try { inCommerceLiff = sessionStorage.getItem('dcLiffCtx') === '1'; } catch (e) {}
        if (inCommerceLiff && window.liff) {
          try { liff.init({liffId: '<?php echo esc_js($dl_liff); ?>'}).catch(function () {}); } catch (e) {}
        }

        b.addEventListener('click', function (e) {
          if (typeof liff !== 'undefined' && liff.isInClient && liff.isInClient()
              && typeof liff.openWindow === 'function') {
            e.preventDefault();
            // The href already carries openExternalBrowser=1, exactly as
            // commerce passes its own href straight into openWindow.
            try { liff.openWindow({url: b.getAttribute('href'), external: true}); }
            catch (err) { window.location.href = b.getAttribute('href'); }
          }
        });
      })();
      </script>
      <?php endif; ?>
    <?php else: ?>
      <p>ขอบคุณที่สละเวลาตอบนะครับ<br>เราจะส่งอีบุ๊กให้ทาง<?php echo $dl_ch; ?>เร็ว ๆ นี้</p>
    <?php endif; ?>
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
<form id="survey-form" method="post" action="<?php echo esc_url(home_url('/101-survey/')); ?>">
<?php wp_nonce_field('dl_survey_submit', 'dl_survey_nonce'); ?>
<?php if ($dl_token): ?><input type="hidden" name="t" value="<?php echo esc_attr($dl_token); ?>"><?php endif; ?>
<?php /* Carry the SIGNED preview override into the submit, so the server can tell
         "this person was legitimately shown the ebook picker" from a hand-made
         POST. Unsigned or altered values are ignored server-side. */ ?>
<?php if (!empty($_GET['pv']) && !empty($_GET['pvs'])): ?>
  <input type="hidden" name="pv" value="<?php echo esc_attr(sanitize_key(wp_unslash($_GET['pv']))); ?>">
  <input type="hidden" name="pvs" value="<?php echo esc_attr(sanitize_text_field(wp_unslash($_GET['pvs']))); ?>">
<?php endif; ?>
<?php
/**
 * The wizard radios must be SIBLINGS OF .shell — the page rules are
 * `#wN:checked ~ .shell .pN`. They were emitted outside the <form> while
 * .shell sits inside it, so the combinator never matched and every panel
 * stayed hidden: a completely blank page.
 */
?>
<?php
/**
 * The DEFAULT-CHECKED radio must be the first panel of THIS student's
 * sequence, not hard-coded to w1. Panel 1 (the ebook picker) is hidden for
 * unfinished students, and `#w1:checked ~ .shell .p1{display:flex}` is the
 * only rule that shows anything at load — so hard-coding w1 renders a blank
 * wizard for them.
 */
$first = reset($seq);
for ($i = 1; $i <= 10; $i++) {
    printf('<input class="ci" type="radio" name="wz" id="w%d"%s>' . "\n",
        $i, ($i === $first ? ' checked' : ''));
}
?>
<div class="shell">
  <div class="form">
    <div class="prog"><div class="bar"><div class="fill"></div></div></div>

    <!-- 1 — EBOOK -->
    <section class="panel p1">
      <div class="hero">
        <p class="eyebrow">Dogology 101</p>
        <h1>แบบสอบถามผู้เรียน <span class="g">คอร์ส Dogology 101</span></h1>
        <p class="lede">ตอนนี้เรากำลังจะปรับปรุงคอร์ส Dogology 101 ครั้งใหญ่ สิ่งที่คุณตอบวันนี้จะกำหนดว่าจะเพิ่มอะไรเข้าไปบ้าง ตอบตรง ๆ ได้เลยครับ ไม่ต้องเกรงใจ<br><br><b>เลือกอีบุ๊กที่อยากอ่านไว้ก่อนได้เลยครับ 1 เล่ม</b> เดี๋ยวเราส่งให้ทาง<?php echo $dl_ch; ?> ระหว่างนี้อยากชวนคุยเรื่องคอร์สสักสองสามคำถาม ใช้เวลาประมาณ 3 นาทีครับ</p>
        <div class="who-strip">
          <div class="av"><?php echo esc_html($initial); ?></div>
          <div class="nm"><b><?php echo esc_html($display_name ?: 'ผู้เรียน'); ?></b><span><?php echo $student && $student->line_uid ? 'เข้าสู่ระบบด้วย LINE อัตโนมัติ' : 'ยืนยันตัวตนจากลิงก์ในอีเมลแล้ว'; ?></span></div>
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
        <?php /* Feedback from a real run: people expected the book to arrive on
                 selection, and were unclear what the questions would be about.
                 State both, at the moment they press ถัดไป. */ ?>
        <p class="fine" style="margin-top:18px">เลือกเสร็จแล้วกด "ถัดไป" เพื่อคุยกันต่อเรื่องคอร์ส Dogology 101<br>เล่มที่เลือกไว้ เราส่งให้ทาง<?php echo $dl_ch; ?>ครับ</p>
      </div>
      <?php $n = $nav(1); dl_srv_nav($n['back'], $n['next'], $n['last']); ?>
    </section>

    <!-- 2 — APPLIED (finished) / WHAT STOPPED YOU (unfinished) -->
    <section class="panel p2">
      <div class="sec">
        <div class="step"><span class="n"><?php echo dl_srv_step(2, $seq); ?></span><h2><?php echo $is_unf ? 'อะไรทำให้หยุด' : 'สิ่งที่เอาไปใช้จริง'; ?></h2></div>
        <?php if (!$is_unf): ?>
          <div class="q qapplied">
            <span class="lab">จากคอร์ส Dogology 101 เรื่องไหนที่คุณเอาไปใช้จริงบ้าง *</span>
            <span class="sub">เลือกได้หลายข้อ</span>
            <?php /* NOTE: no .opts wrapper here — the checkboxes must stay direct
                     siblings of .bestwrap for the `#t_x:checked ~ .bestwrap` rules. */ ?>
            <?php foreach ($topic_opts as $k => $label): ?>
              <input class="ci" type="checkbox" name="applied[]" value="<?php echo esc_attr($k); ?>" id="t_<?php echo esc_attr($k); ?>"><label class="opt" for="t_<?php echo esc_attr($k); ?>"><i></i><span><?php echo esc_html($label); ?></span></label>
            <?php endforeach; ?>
            <input class="ci" type="checkbox" name="applied[]" value="none" id="t_none"><label class="opt" for="t_none"><i></i><span>ยังไม่ได้ลองอะไรเลย</span></label>
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
        <div class="step"><span class="n"><?php echo dl_srv_step(3, $seq); ?></span><h2><?php echo $is_unf ? 'ความคาดหวังตอนซื้อ' : 'เนื้อหาที่ชอบ'; ?></h2></div>
        <?php if (!$is_unf): ?>
          <div class="q">
            <span class="lab">เนื้อหาส่วนไหนที่ชอบที่สุด *</span>
            <span class="sub">เลือกได้หลายข้อ ไม่จำเป็นต้องเป็นเรื่องที่เอาไปใช้</span>
            <?php dl_srv_set('checkbox', 'liked[]', 'lk', $topic_opts); ?>
          </div>
        <?php else: ?>
          <div class="q">
            <span class="lab">ตอนที่ซื้อคอร์ส Dogology 101 คุณคาดหวังว่าจะได้อะไร *</span>
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
        <div class="step"><span class="n"><?php echo dl_srv_step(4, $seq); ?></span><h2><?php echo $is_unf ? 'ทางกลับมา' : 'ความคุ้มค่า'; ?></h2></div>
        <?php if (!$is_unf): ?>
          <div class="q">
            <span class="lab">คอร์ส Dogology 101 คุ้มค่ากับที่จ่ายไปแค่ไหน *</span>
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
        <div class="step"><span class="n"><?php echo dl_srv_step(5, $seq); ?></span><h2>อยากให้เพิ่มอะไร</h2></div>
        <div class="q">
          <span class="lab">อยากให้เพิ่มหรือปรับอะไรในคอร์ส Dogology 101 บ้าง *</span>
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
        <div class="step"><span class="n"><?php echo dl_srv_step(6, $seq); ?></span><h2>ผลลัพธ์กับหมา</h2></div>
        <div class="q">
          <span class="lab">อะไรที่เรียนจากคอร์ส Dogology 101 แล้วช่วยให้หมาของคุณเปลี่ยนไปบ้าง เล่าให้ฟังหน่อย หรือไม่เปลี่ยนไปเลยก็บอกได้</span>
          <span class="sub">เล่าเป็นเหตุการณ์ก็ได้ครับ เช่น เมื่อก่อนเป็นแบบไหน ตอนนี้เป็นแบบไหน</span>
          <textarea name="outcome" rows="5" placeholder="ยิ่งเล่าละเอียด ยิ่งช่วยเราได้มาก"></textarea>
        </div>
      </div>
      <?php $n = $nav(6); dl_srv_nav($n['back'], $n['next'], $n['last']); ?>
    </section>

    <!-- 7 — FRICTION (finished path only) -->
    <section class="panel p7">
      <div class="sec">
        <div class="step"><span class="n"><?php echo dl_srv_step(7, $seq); ?></span><h2>ความต่อเนื่องในการเรียน</h2></div>
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
        <div class="step"><span class="n"><?php echo dl_srv_step(8, $seq); ?></span><h2>เพิ่มเติม</h2></div>
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
        <div class="step"><span class="n"><?php echo dl_srv_step(9, $seq); ?></span><h2>ให้คะแนนโดยรวม</h2></div>
        <div class="q">
          <span class="lab" style="text-align:center">ให้คะแนนคอร์ส Dogology 101 โดยรวม *</span>
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
        <?php
        /**
         * The testimonial ask (consent + dog name + photo) is for people who
         * got something out of the course. Asking someone who stopped at
         * lesson three to let us tell their story — and to send a photo of
         * their dog — right after they told us where we failed them reads as
         * tone-deaf, and would produce testimonials we could not use anyway.
         */
        if (!$is_unf): ?>
        <div class="q">
          <span class="lab">เรื่องของน้องอาจช่วยเจ้าของคนอื่นได้</span>
          <span class="sub">เจ้าของหลายคนกำลังเจอปัญหาแบบเดียวกับที่คุณเคยเจอ สิ่งที่คุณเล่ามาอาจเป็นสิ่งที่เขากำลังตามหาอยู่ครับ</span>
          <div class="perm">
            <input class="ci" type="checkbox" name="consent_testimonial" value="1" id="consent"><label class="opt" for="consent" style="margin:0"><i></i><span>ให้ Dogology นำคำตอบไปใช้เล่าต่อได้ เช่น ในเว็บไซต์หรือโซเชียล</span></label>
            <div style="margin-top:12px">
              <span class="sub">น้องหมาชื่ออะไร</span>
              <input type="text" name="dog_name" placeholder="เช่น ข้าวปั้น">
            </div>
            <div style="margin-top:12px">
              <span class="sub">รูปน้อง (ถ้ามี) ใช้เมื่อติ๊กยินยอมด้านบนเท่านั้นครับ</span>
              <input class="ci" type="file" id="dogphoto" name="dog_photo" accept="image/*">
              <label class="photopick" for="dogphoto">
                <img id="dogphoto-preview" alt="">
                <span class="ph"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg></span>
                <span id="dogphoto-label">แตะเพื่อเลือกรูป</span>
              </label>
              <input type="hidden" name="photo_attachment_id" id="photo_attachment_id" value="">
            </div>
          </div>
        </div>
        <?php else: ?>
        <div class="q">
          <span class="lab">ขอบคุณที่สละเวลาบอกเราครับ</span>
          <span class="sub">สิ่งที่คุณเล่ามาจะถูกอ่านจริง ๆ และจะถูกใช้ตัดสินใจว่าจะปรับอะไรก่อนครับ</span>
        </div>
        <?php endif; ?>
        <p class="fine">ขอบคุณที่สละเวลานะครับ เดี๋ยวเราส่งอีบุ๊กให้ทาง<?php echo $dl_ch; ?>ครับ</p>
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

  // The photo can't ride the JSON submit, so it uploads the moment it's picked
  // and only its attachment id travels with the answers.
  var photo = document.getElementById('dogphoto');
  if (photo) {
    photo.addEventListener('change', function () {
      var f = photo.files && photo.files[0];
      if (!f) return;
      var lab = document.getElementById('dogphoto-label');
      lab.textContent = 'กำลังอัปโหลด...';
      var fd = new FormData(); fd.append('file', f);
      var tk = form.querySelector('input[name="t"]');
      if (tk) fd.append('t', tk.value);
      fetch('<?php echo esc_url_raw(rest_url('dogology-learning/v1/survey-photo')); ?>', {
        method: 'POST', credentials: 'same-origin', body: fd
      }).then(function (r) { return r.json(); }).then(function (j) {
        if (j && j.ok) {
          document.getElementById('photo_attachment_id').value = j.attachment_id;
          document.getElementById('dogphoto-preview').src = j.url;
          lab.parentNode.classList.add('has');
          lab.textContent = 'แตะเพื่อเปลี่ยนรูป';
        } else { lab.textContent = 'อัปโหลดไม่สำเร็จ แตะเพื่อลองใหม่'; }
      }).catch(function () { lab.textContent = 'อัปโหลดไม่สำเร็จ แตะเพื่อลองใหม่'; });
    });
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    // Nothing enforces an ebook pick, but every closing line promises one —
    // so a respondent who taps past panel 1 gets a promise we silently void.
    // Ask once, and put them back on the picker.
    var picked = form.querySelector('input[name="ebook_choice"]:checked');
    if (!picked) {
      var w1 = document.getElementById('w1');
      if (w1) w1.checked = true;
      window.scrollTo(0, 0);
      alert('ยังไม่ได้เลือกอีบุ๊กครับ เลือก 1 เล่มก่อนส่งคำตอบนะครับ');
      return;
    }
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
      // 'duplicate' means the answers ARE saved — treat it as success rather
      // than sending the student round a retry loop that can only repeat.
      if (j && (j.ok || j.error === 'duplicate')) {
        // Carry the token through. The token deliberately mints no session, so
        // without it the thank-you page cannot identify the student, cannot
        // resolve their grant, and falls back to "we'll send it soon" — the
        // download button, the copyable link and the whole LIFF escape would
        // never render for anyone who arrived by link, which is nearly everyone.
        location.href = '<?php echo esc_url_raw(add_query_arg(
            array('done' => 1), home_url('/101-survey/'))); ?>'
          + (<?php echo $dl_token ? "'&t=' + encodeURIComponent('" . esc_js($dl_token) . "')" : "''"; ?>);
      }
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
