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

    /* A student is only "stalled" once BOTH are true: untouched for a full
       month, and owned for a full month. Operator's rule — two weeks of
       quiet is a busy fortnight, not someone who gave up. See context_for(). */
    const ACTIVE_IDLE_DAYS  = 30;
    const ACTIVE_OWNED_DAYS = 30;

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


    /** The four archetype ebooks, as shown on their live product pages. */
    public static function ebooks()
    {
        return array(
            'watchdog' => array(
                'title' => 'คู่มือเลี้ยงหมาระแวง', 'en' => 'The Watchdog',
                'cover' => 'https://dogology.org/wp-content/uploads/2026/07/watchdog-cover-6a550081e3721.webp',
                'desc'  => 'หมาที่สแกนหาอันตรายตลอดเวลา เห่าคนผ่านหน้าบ้าน ตื่นตัวกับเสียง ระวังคนแปลกหน้า ไม่ใช่หมาดุ แต่เป็นหมาที่ยังไม่รู้สึกปลอดภัยพอ',
                'who'   => 'เห่าคนนอกบ้าน หวงพื้นที่ ตกใจง่าย ผ่อนคลายยากเวลามีคนมา',
            ),
            'rocket' => array(
                'title' => 'คู่มือเลี้ยงหมาจรวด', 'en' => 'The Rocket',
                'cover' => 'https://dogology.org/wp-content/uploads/2026/07/rocket-cover-6a5e24c9b7c22.webp',
                'desc'  => 'หมาที่มีพลังเหลือและเบรกไม่ค่อยอยู่ กระโดดใส่คน ดึงสายจูง ตื่นเต้นแล้วลงยาก ปัญหาไม่ใช่พลังงาน แต่คือยังไม่มีปุ่มหยุด',
                'who'   => 'ดึงสาย กระโดดใส่แขก อยู่ไม่นิ่ง เรียกไม่ค่อยมา',
            ),
            'hothead' => array(
                'title' => 'คู่มือเลี้ยงหมาใจร้อน', 'en' => 'The Hothead',
                'cover' => 'https://dogology.org/wp-content/uploads/2026/07/hothead-cover-6a5b4361ce2c2.webp',
                'desc'  => 'หมาที่ทนรอไม่ได้ อยากได้แล้วต้องได้เดี๋ยวนี้ เห่าใส่ของที่เอื้อมไม่ถึง หงุดหงิดเวลาถูกกั้น อารมณ์มาไวไปไว',
                'who'   => 'เห่าเวลาไม่ได้ดั่งใจ งับสายจูง รอไม่เป็น คับข้องใจง่าย',
            ),
            'shadow' => array(
                'title' => 'คู่มือเลี้ยงหมากังวล', 'en' => 'The Shadow',
                'cover' => 'https://dogology.org/wp-content/uploads/2026/07/shadow-cover-6a604959256d8.webp',
                'desc'  => 'หมาขี้กลัว ขาดความมั่นใจ เจอของใหม่แล้วถอย ตกใจง่าย ไม่กล้าลอง ไม่ใช่หมานิสัยไม่ดี แต่เป็นหมาที่ยังไม่มีฐานที่รู้สึกปลอดภัยพอ',
                'who'   => 'กลัวเสียงดัง กลัวคนแปลกหน้า ไม่กล้าเข้าหาของใหม่ ต้องการสร้างความมั่นใจทีละขั้น',
            ),
        );
    }

    /** Page stylesheet, lifted from the approved mock. */
    public static function page_css()
    {
        return <<<'DLCSS'

:root{--head:Kanit,'Noto Sans Thai',Thonburi,sans-serif;--teal:#00AB8E;--teal-deep:#0F766E;--blue:#0076BA;
  --grad:linear-gradient(135deg,#00AB8E 0%,#0076BA 100%);--ink:#0F172A;--body:#475569;
  --muted:#64748B;--line:#EEF0F4;--rule:#E2E8F0;--bg:#FAFAFA;--pad:20px;--tap:48px}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html{-webkit-text-size-adjust:100%}
body{margin:0;font-family:'Noto Sans Thai Looped','TH Sarabun New','TH SarabunPSK',Thonburi,'Noto Sans Thai',sans-serif;background:#fff;color:var(--ink);
  line-height:1.75;font-size:16.5px;padding-bottom:0}
h1,.step h2,.book h3,.q .lab,.scale .num{font-family:var(--head)}

/* every control input is hidden; its <label> is the visible row */
.ci{position:fixed;top:0;left:0;width:1px;height:1px;opacity:0;pointer-events:none}

/* ---- mock chrome ---- */

.mockbar strong{color:#fff;font-weight:600}

/* ---- segment scoping ---- */

/* ---- wizard ---- */
.panel{display:none}
.prog{position:sticky;top:0;z-index:20;background:#fff;border-bottom:1px solid var(--line)}
.prog .bar{height:3px;background:var(--line)}
.prog .fill{height:100%;width:16.7%;background:var(--grad);transition:width .25s}
.prog .row{display:flex;justify-content:space-between;align-items:center;gap:12px;
  padding:11px var(--pad);font-size:13.5px;color:var(--muted)}

.nav{position:sticky;bottom:0;z-index:10;display:flex;gap:10px;margin-top:18px;
  background:#fff;border-top:1px solid var(--line);
  padding:12px var(--pad) calc(14px + env(safe-area-inset-bottom))}
.tapgo{margin:16px var(--pad) 0;text-align:center;font-size:13px;color:var(--muted)}
.next{flex:1;display:block;text-align:center;background:var(--grad);color:#fff;border-radius:12px;
  padding:15px;font-size:16px;font-weight:500;cursor:pointer;line-height:1.4}
.back{flex:none;display:inline-flex;align-items:center;justify-content:center;color:var(--muted);
  border:1px solid var(--rule);border-radius:12px;padding:0 20px;font-size:15px;cursor:pointer;background:#fff}

/* ---- hero ---- */
.hero{background:var(--bg);padding:26px var(--pad) 22px;border-bottom:1px solid var(--line)}
.eyebrow{font-size:10.5px;letter-spacing:.14em;text-transform:uppercase;color:var(--teal);font-weight:600;margin:0 0 8px}
h1{margin:0 0 10px;font-size:23px;font-weight:600;line-height:1.45}
h1 .g{background:var(--grad);-webkit-background-clip:text;background-clip:text;color:transparent}
.lede{margin:0;color:var(--body);font-size:16px}
.who-strip{display:flex;align-items:center;gap:11px;background:#fff;border:1px solid var(--line);
  border-radius:12px;padding:11px 13px;margin-top:16px}
.who-strip .av{width:38px;height:38px;border-radius:50%;background:var(--grad);flex:none;
  display:flex;align-items:center;justify-content:center;color:#fff;font-weight:600}
.who-strip .nm{flex:1;min-width:0}
.who-strip .nm b{display:block;font-size:14px;font-weight:500}
.who-strip .nm span{display:block;font-size:11.5px;color:var(--muted)}
.who-strip .ok{font-size:11px;color:var(--teal-deep);background:rgba(0,171,142,.1);
  padding:3px 9px;border-radius:11px;white-space:nowrap}

/* ---- sections ---- */
.sec{padding:26px var(--pad)}
.step{display:flex;align-items:baseline;gap:9px;margin:0 0 5px}
.step .n{font-size:12.5px;font-weight:600;color:var(--teal);white-space:nowrap}
.step h2{margin:0;font-size:20px;font-weight:600}
.hint{margin:0 0 18px;color:var(--muted);font-size:14.5px}
.q{margin-bottom:28px;display:flex;flex-direction:column}
.q .lab{font-size:17px;font-weight:500;margin-bottom:4px;line-height:1.55}
.q .sub{font-size:14px;color:var(--muted);margin-bottom:11px;line-height:1.6}
.q .sub2{font-size:16.5px;font-weight:500;margin:18px 0 2px;font-family:var(--head)}

/* ---- option rows: input + label, styled via :checked + label ---- */
.opt{display:flex;align-items:flex-start;gap:11px;border:1px solid var(--line);border-radius:10px;
  padding:13px 14px;min-height:var(--tap);font-size:15.5px;color:var(--body);cursor:pointer;
  margin-bottom:8px;background:#fff}
.opt i{flex:none;width:19px;height:19px;border:1.5px solid #CBD5E1;border-radius:5px;
  margin-top:3px;display:block;position:relative}
input[type=radio] + .opt i{border-radius:50%}
.ci:checked + .opt{border-color:var(--teal);background:rgba(0,171,142,.06);color:var(--ink)}
.ci:checked + .opt i{border-color:var(--teal);background:var(--teal)}
.ci:checked + .opt i:after{content:"";position:absolute;left:5px;top:1px;width:5px;height:10px;
  border:solid #fff;border-width:0 2px 2px 0;transform:rotate(45deg)}

.empty{margin:0;padding:13px 14px;border:1px dashed var(--rule);border-radius:10px;
  color:var(--muted);font-size:14px;text-align:center}

textarea,input[type=text]{width:100%;border:1px solid var(--rule);border-radius:10px;padding:13px 14px;
  font-family:inherit;font-size:16px;color:var(--ink);resize:vertical;line-height:1.7;background:#fff}
textarea:focus,input[type=text]:focus{outline:none;border-color:var(--teal)}

/* ---- ebooks ---- */
.books{display:grid;grid-template-columns:1fr;gap:12px}
.book{display:block;border:1.5px solid var(--line);border-radius:12px;padding:17px 16px;
  background:#fff;position:relative;cursor:pointer}
.ci:checked + .book{border-color:var(--teal);background:rgba(0,171,142,.06);
  box-shadow:0 0 0 1px var(--teal)}
.ci:checked + .book .bookmain h3{color:var(--teal-deep)}
.ci:checked + .book:before{content:"เลือกเล่มนี้";position:absolute;left:12px;bottom:-9px;
  background:var(--teal);color:#fff;font-size:10.5px;padding:2px 9px;border-radius:10px}
.ci:checked + .book:after{content:"\2713";position:absolute;top:14px;right:15px;color:var(--teal);font-weight:700}
.book.own{opacity:.5;background:#FAFAFA}
.bookrow{display:flex;gap:14px;align-items:flex-start}
.cover{width:76px;flex:none;border-radius:6px;overflow:hidden;background:#F1F5F9;
  box-shadow:0 2px 8px rgba(15,23,42,.14);aspect-ratio:3/4.2}
.cover img{width:100%;height:100%;object-fit:cover;display:block}
.bookmain{flex:1;min-width:0}
.book h3{margin:0 0 2px;font-size:17.5px;font-weight:600;padding-right:34px}
.book .en{margin:0 0 9px;font-size:10.5px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted)}
.book p{margin:0 0 11px;font-size:15px;color:var(--body);line-height:1.65}
.book .who{margin:0;font-size:13.5px;color:var(--teal-deep);background:rgba(0,171,142,.08);
  border-radius:8px;padding:9px 11px;line-height:1.6}
.ownlbl{position:absolute;top:14px;right:15px;font-size:10.5px;color:var(--muted);
  background:#F1F5F9;padding:2px 9px;border-radius:12px}

/* ---- scale + stars ---- */
.scale{display:flex;gap:6px}
.scale label{flex:1 1 0;min-width:0;text-align:center;border:1px solid var(--line);border-radius:10px;
  padding:11px 3px;cursor:pointer;background:#fff}
.ci:checked + .sc{border-color:var(--teal);background:rgba(0,171,142,.07)}
.scale .num{display:block;font-size:18px;font-weight:600;color:var(--ink)}
.scale .cap{display:block;font-size:11.5px;color:var(--muted);line-height:1.35}
.starbox{background:var(--bg);border:1px solid var(--line);border-radius:12px;padding:20px 16px}
.stars{display:flex;flex-direction:row-reverse;justify-content:center}
.stars label{color:#E2E8F0;font-size:34px;line-height:1;padding:5px;cursor:pointer;min-width:var(--tap);text-align:center}
.stars .ci:checked ~ label{color:#F59E0B}
.stars label:hover,.stars label:hover ~ label{color:#FBBF24}
.starcap{text-align:center;font-size:14px;color:var(--muted);margin:10px 0 0}
.note{background:#F0F9FF;border-left:3px solid var(--blue);border-radius:0 9px 9px 0;
  padding:14px 16px;margin:0 0 14px;font-size:15px;color:var(--body);line-height:1.75}
.perm{background:var(--bg);border:1px solid var(--line);border-radius:11px;padding:15px}
.upload{display:flex;align-items:center;gap:13px;border:1.5px dashed var(--rule);border-radius:11px;
  padding:14px;cursor:pointer;background:#fff;margin-top:4px}
.upload .ic{font-size:22px;flex:none}
.upload .ut{font-size:13.5px;color:var(--muted);line-height:1.55}
.upload .ut b{color:var(--ink);font-weight:500;font-size:14.5px}
.fine{margin:14px 0 0;text-align:center;font-size:13.5px;color:var(--muted)}

/* ---- LINE panel ---- */

@media (min-width:600px){:root{--pad:32px}h1{font-size:27px}.books{grid-template-columns:repeat(2,1fr)}.scale .cap{font-size:11.5px}}
@media (min-width:1040px){:root{--pad:40px}
  body{background:#F1F5F9;padding-bottom:60px}
  .shell{max-width:1140px;margin:0 auto;padding:28px 20px 60px;display:grid;
    grid-template-columns:1fr 340px;gap:28px;align-items:start}
  .form{background:#fff;border:1px solid var(--line);border-radius:16px;overflow:hidden}
  
}

.prog .row{display:none}

.sec{padding:18px var(--pad) 4px}
.hero{padding:18px var(--pad) 16px}
h1{font-size:20px;line-height:1.4;margin:0 0 8px}
.lede{font-size:15.5px}
.who-strip{padding:9px 11px;margin-top:12px}
.who-strip .av{width:32px;height:32px;font-size:13px}
.note{padding:11px 13px;margin:0 0 11px;font-size:14px;line-height:1.65}
.hint{margin:0 0 12px;font-size:14px}
.q{margin-bottom:0}
.q .lab{font-size:17.5px;line-height:1.45;margin-bottom:3px}
.q .sub{font-size:13.5px;margin-bottom:9px}
.q .sub2{font-size:16.5px;margin:14px 0 2px}
.opt{padding:9px 12px;min-height:0;font-size:15px;margin-bottom:6px;gap:9px;line-height:1.45}
.opt i{width:17px;height:17px;margin-top:2px}
.ci:checked + .opt i:after{left:4.5px;top:1px;width:4px;height:9px}
textarea{font-size:16px;padding:11px 13px;line-height:1.55}
.books{gap:8px}
.book{padding:11px 12px}
.cover{width:58px;aspect-ratio:3/4.2}
.book h3{font-size:16px}
.book .en{margin:0 0 6px;font-size:10.5px}
.book p{font-size:14px;margin:0;line-height:1.5}
.book .who{display:none}
.bookrow{gap:11px}
.scale label{padding:14px 3px}
.scale .num{font-size:22px}
.starbox{padding:14px}
.stars label{font-size:38px;padding:4px}
.perm{padding:12px}
.upload{padding:11px}
.prog .bar{height:4px}

.next{padding:13px;font-size:16px}
.back{padding:0 16px;font-size:15px}
@media (min-height:760px){
  .sec{padding:24px var(--pad) 6px}
  .opt{padding:11px 13px;margin-bottom:7px}
  h1{font-size:23px}
}

/* NOTE: two orphaned selector fragments (`.nav` and `.q`, left behind when an
   earlier same-specificity fix was rewritten) used to sit here. Each was
   followed by a comment, so the parser fused them with the rule below into
   `.nav .q .panel{...}` — a selector that matches nothing, silently killing the
   panel-height rule. The corrective layer further down masked it. Both dead
   fragments are gone; the height rule now stands on its own.                */

/* --- stable screen height ---------------------------------------------------
   A short question used to leave the buttons floating mid-screen while a long
   one pushed them off. The panel now always fills the viewport and the nav is
   pinned to the bottom of it, so ถัดไป lands in the same place every time. */
.panel{flex-direction:column;min-height:calc(100vh - 4px);min-height:calc(100svh - 4px)}
/* Short questions: panel is exactly one screen tall and .nav{margin-top:auto}
   parks the buttons at the bottom edge, so they are always in view without
   scrolling. Long questions simply grow past it. */
.panel{height:auto}
.panel > .sec,.panel > .hero{flex:none}
.nav{margin-top:auto}
@media (min-width:1040px){.panel{min-height:0}.nav{position:static;margin-top:24px;border-top:1px solid var(--line)}}

/* --- sub-question banding -------------------------------------------------
   Screens that carry more than one ask (Q1: what you applied → which worked
   best → why) band the follow-ups on a tinted panel so each reads as its own
   question instead of one long undifferentiated form. */
.bestwrap,.subq{background:rgba(0,171,142,.05);border:1px solid rgba(0,171,142,.18);
  border-radius:12px;padding:14px 13px;margin-top:16px;display:flex;flex-direction:column}
.bestwrap .sub2,.subq .sub2{color:var(--teal-deep)}
.subq + .subq,.bestwrap + .subq{margin-top:10px}
.bestwrap .sub2,.subq .sub2{margin:0 0 2px;font-size:16.5px}
.bestwrap .sub,.subq .sub{margin-bottom:9px}
.bestwrap .opt,.subq .opt{background:#fff}
.bestwrap .empty{background:#fff}
.subq textarea{background:#fff}

/* --- white-on-white fix ----------------------------------------------------
   Option rows were #FFF on a #FFF page, so the only thing separating a tap
   target from the page was a 1px hairline. Follows the house pattern instead:
   the question surface is the warm grey, every control sits on white. */
.panel,.sec,.hero{background:var(--bg)}
.opt{background:#fff;border-color:#E4E8EE}
.ci:checked + .opt{background:#fff;border-color:var(--teal);box-shadow:0 0 0 1px var(--teal)}
textarea,input[type=text]{background:#fff}
.perm,.starbox{background:#fff;border:1px solid var(--line)}
.empty{background:#fff}
.book{background:#fff}
.book.own{background:#F3F4F6}
.nav{background:var(--bg);border-top:1px solid var(--rule)}
.prog{background:var(--bg)}
.bestwrap,.subq{background:rgba(0,171,142,.06);border-color:rgba(0,171,142,.2)}

/* wizard: one panel visible at a time */
.panel{display:none}
#w1:checked ~ .shell .p1{display:flex}
#w2:checked ~ .shell .p2{display:flex}
#w3:checked ~ .shell .p3{display:flex}
#w4:checked ~ .shell .p4{display:flex}
#w5:checked ~ .shell .p5{display:flex}
#w6:checked ~ .shell .p6{display:flex}
#w7:checked ~ .shell .p7{display:flex}
#w8:checked ~ .shell .p8{display:flex}
#w9:checked ~ .shell .p9{display:flex}
#w10:checked ~ .shell .p10{display:flex}
#w1:checked ~ .shell .fill{width:10%}
#w2:checked ~ .shell .fill{width:20%}
#w3:checked ~ .shell .fill{width:30%}
#w4:checked ~ .shell .fill{width:40%}
#w5:checked ~ .shell .fill{width:50%}
#w6:checked ~ .shell .fill{width:60%}
#w7:checked ~ .shell .fill{width:70%}
#w8:checked ~ .shell .fill{width:80%}
#w9:checked ~ .shell .fill{width:90%}
#w10:checked ~ .shell .fill{width:100%}
.bopt{display:none}
.gate{max-width:420px;margin:0 auto;padding:56px 24px;text-align:center}
.gate h1{font-size:22px;margin:0 0 12px}
.gate p{color:var(--body);font-size:16px;line-height:1.8}
.gatebtn{display:inline-block;margin-top:20px;padding:14px 26px;border-radius:12px;
  background:var(--grad);color:#fff;text-decoration:none;font-weight:500}
.sending{opacity:.55;pointer-events:none}
.prog .row{display:none}


/* ==========================================================================
   MOBILE-FIRST BASELINE — appended last so it wins.

   History, because the comment that used to sit here was wrong and cost a day:
   this block was added to repair a supposedly-damaged @media (min-width:1040px)
   wrapper. A later audit proved the wrapper was intact — the real culprit was a
   fused selector list that hid every heading. These rules are kept because they
   are a correct, explicit mobile-first baseline, NOT because anything upstream
   is broken. The `.panel` line that used to be here was removed: it shadowed
   the real rule above and threw away its calc(100vh) fallback for browsers
   without svh.
   ========================================================================== */
.shell{display:block;max-width:none;margin:0;padding:0}
.form{background:transparent;border:0;border-radius:0;overflow:visible}
.opts{display:flex;flex-direction:column;gap:8px}
.bestwrap{display:flex;flex-direction:column;background:rgba(0,171,142,.06);
  border:1px solid rgba(0,171,142,.2);border-radius:12px;padding:14px 13px;margin-top:16px}
.bestwrap .opt,.subq .opt{background:#fff}
.q{display:flex;flex-direction:column;margin-bottom:26px}

/* Photo picker — deliberately NOT the .opt row style: reusing it drew a
   checkbox, which reads as "tick to agree", not "tap to add a picture". */
.photopick{display:flex;align-items:center;gap:11px;min-height:var(--tap);
  padding:10px 12px;margin-top:6px;background:#fff;border:1px dashed var(--rule);
  border-radius:12px;cursor:pointer;font-size:15px;color:var(--body)}
.photopick .ph{display:flex;align-items:center;justify-content:center;
  width:38px;height:38px;flex:none;border-radius:10px;
  background:rgba(0,171,142,.08);color:var(--teal-deep)}
.photopick img{display:none;width:44px;height:44px;flex:none;object-fit:cover;border-radius:10px}
.photopick.has img{display:block}
.photopick.has .ph{display:none}
.photopick.has{border-style:solid;border-color:var(--teal)}
.books{display:grid;grid-template-columns:1fr;gap:10px}
.sec{padding:20px var(--pad) 4px}
img{max-width:100%;height:auto}
body{overflow-x:hidden}

@media (min-width:1040px){
  .shell{max-width:720px;margin:0 auto;padding:28px 20px 60px}
  .form{background:#fff;border:1px solid var(--line);border-radius:16px;overflow:hidden}
  .panel{min-height:0}
  .nav{position:static;margin-top:24px}
  .books{grid-template-columns:repeat(2,1fr);gap:14px}
}

DLCSS;
    }

    /**
     * Per-recipient signed token.
     *
     * LIFF cannot carry this: the only configured LIFF id belongs to MindMap and
     * its endpoint resolves to the assessment, so liff.line.me/<id> would open
     * the wrong page entirely. A signed token in the link is what M4 already
     * does, needs no LINE context, and works in any browser.
     */
    const TOKEN_TTL_DAYS = 14;

    public static function make_token($user_id, $issued = 0)
    {
        $uid = (int) $user_id;
        // Day-resolution issue stamp, bound INTO the signature so it can't be
        // edited. Without it the link never expired and never could be revoked.
        $day = $issued ? (int) $issued : (int) floor(time() / DAY_IN_SECONDS);
        return $uid . '.' . $day . '.'
            . substr(hash_hmac('sha256', 'survey|' . $uid . '|' . $day, DOGOLOGY_AUTH_SALT), 0, 24);
    }

    /**
     * Signed segment override for sample sends.
     *
     * The page shape is derived from the recipient's REAL progress, so all
     * three sample variants rendered the non-finisher page when sent to an
     * operator who has not finished the course — correct behaviour, useless
     * for previewing. This lets a sample link say "render as if finished".
     * Signed so it cannot be self-issued: the ebook path is behind it.
     * Affects RENDERING ONLY — a stored response always records the real
     * segment from context_for().
     */
    public static function preview_sig($user_id, $segment)
    {
        return substr(hash_hmac('sha256', 'survey-preview|' . (int) $user_id . '|' . $segment,
            DOGOLOGY_AUTH_SALT), 0, 16);
    }
    public static function verify_preview($user_id, $segment, $sig)
    {
        if (!$user_id || !$segment || !$sig) return false;
        return hash_equals(self::preview_sig($user_id, $segment), (string) $sig);
    }

    /**
     * @return int Student id, or 0 if malformed, forged, or expired.
     */
    public static function verify_token($token)
    {
        $parts = explode('.', (string) $token);
        if (count($parts) !== 3) return 0;             // legacy 2-part tokens are dead
        $uid = (int) $parts[0];
        $day = (int) $parts[1];
        if ($uid <= 0 || $day <= 0) return 0;
        if (!hash_equals(self::make_token($uid, $day), (string) $token)) return 0;

        $age = (int) floor(time() / DAY_IN_SECONDS) - $day;
        if ($age < 0 || $age > self::TOKEN_TTL_DAYS) return 0;

        return $uid;
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

        /**
         * "Stalled" must mean STOPPED, not merely unfinished.
         *
         * With no recency test, someone who bought this morning and watched
         * lesson 1 counted as stalled — and was sent "คอร์สยังค้างอยู่ใช่ไหมครับ /
         * เวลามีคนหยุดกลางทาง" hours after paying. A person still working
         * through the course is ACTIVE; there is nothing to ask them yet and
         * nothing they did wrong.
         *
         * Two conditions, because either alone is wrong: someone who bought
         * long ago but studied yesterday is active, and someone who bought
         * yesterday and has not opened it since is not stalled either — they
         * have barely had the chance.
         */
        $days_idle = $last  ? floor((time() - strtotime($last))  / DAY_IN_SECONDS) : null;
        $days_owned= $first ? floor((time() - strtotime($first)) / DAY_IN_SECONDS) : null;
        $is_active = ($days_idle !== null && $days_idle < self::ACTIVE_IDLE_DAYS)
                  || ($days_owned !== null && $days_owned < self::ACTIVE_OWNED_DAYS);

        if ($finished) {
            $segment = 'finished';
        } elseif ($pct >= 76) {
            $segment = 'near';
        } elseif ($is_active) {
            $segment = 'active';        // never invited: still in the middle of it
        } elseif ($done > 0) {
            $segment = 'stalled';
        } else {
            $segment = 'not_started';
        }

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
            'is_unfinished'  => in_array($segment, array('stalled', 'not_started', 'active'), true),
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
    /**
     * Grant any response that chose a book and never received one.
     *
     * Delivery is best-effort at submit time (a push failure must never fail a
     * saved response), and an eligibility rule can change under a stored row —
     * both leave a student holding a promise. Runs hourly so neither becomes
     * permanent, and it is safe to repeat: grant_ebook() short-circuits on
     * ebook_granted_at.
     *
     * @return int Number granted.
     */
    public static function grant_pending()
    {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM " . self::responses_table() . "
             WHERE survey_key = %s AND ebook_choice IS NOT NULL AND ebook_choice <> ''
               AND ebook_granted_at IS NULL LIMIT 50",
            self::SURVEY_KEY
        ));
        $n = 0;
        foreach ($ids as $rid) {
            try {
                if (!is_wp_error(self::grant_ebook((int) $rid))) $n++;
            } catch (\Throwable $e) {
                error_log('[dogology-survey] grant_pending failed for ' . (int) $rid . ': ' . $e->getMessage());
            }
        }
        return $n;
    }

    /**
     * Does this segment walk the shorter, unfinished path?
     *
     * SINGLE SOURCE OF TRUTH. This was duplicated as an inline in_array() in
     * the template and again in the admin detail view, and when 'active' was
     * added the copies drifted: the admin decided an active respondent was a
     * finisher and labelled the questions they HAD answered as never asked,
     * hiding real answers.
     */
    public static function is_unfinished_segment($segment)
    {
        return in_array($segment, array('stalled', 'not_started', 'active'), true);
    }

    /** @return string|null best_topic, only if the student also ticked it in `applied`. */
    protected static function best_topic(array $payload)
    {
        $best = self::in_registry($payload, 'best_topic', array_keys(self::topics()));
        if ($best === null) return null;
        $applied = isset($payload['applied']) && is_array($payload['applied'])
            ? array_map('sanitize_key', $payload['applied']) : array();
        return in_array($best, $applied, true) ? $best : null;
    }

    /** @return string|null The value only if it is a known key. */
    protected static function in_registry(array $payload, $field, array $valid)
    {
        if (!isset($payload[$field]) || !is_scalar($payload[$field])) return null;
        $v = sanitize_key((string) $payload[$field]);
        return in_array($v, $valid, true) ? $v : null;
    }

    /**
     * The verbatim payload, kept as a safety net for questions the columns do
     * not model. Bounded and shallow: it is unvalidated client input, so a
     * nested or oversized body must not become an unbounded row.
     */
    protected static function raw_json(array $payload)
    {
        // Plumbing never gets archived: the nonce is a credential, and the rest
        // are navigation/preview machinery that would only confuse an export.
        $drop = array('t', 'wz', 'pv', 'pvs', 'dl_survey_nonce', '_wp_http_referer', 'photo_attachment_id');
        $flat = array();
        foreach ($payload as $k => $v) {
            if (in_array($k, $drop, true)) continue;
            $k = substr(sanitize_key((string) $k), 0, 48);
            if ($k === '') continue;
            if (is_array($v)) {
                $flat[$k] = array_map(function ($x) {
                    return is_scalar($x) ? substr((string) $x, 0, 500) : '';
                }, array_slice($v, 0, 40));
            } elseif (is_scalar($v)) {
                $flat[$k] = substr((string) $v, 0, 2000);
            }
        }
        $json = wp_json_encode($flat, JSON_UNESCAPED_UNICODE);
        return strlen($json) > 60000 ? substr($json, 0, 60000) : $json;
    }

    /**
     * @return int|null Attachment id if this student uploaded it AND consented
     *                  to us using their answers, else null.
     *
     * The photo sits beside the consent checkbox but was stored independently,
     * so a photo could be attached to a response we are not allowed to publish.
     * A picture of someone's dog is the most personal thing this form collects;
     * it should never outlive the permission. Without consent the upload is
     * deleted outright rather than kept unreferenced.
     */
    protected static function own_attachment(array $payload, $user_id)
    {
        $id = isset($payload['photo_attachment_id']) ? (int) $payload['photo_attachment_id'] : 0;
        if ($id <= 0) return null;

        $owner = (int) get_post_meta($id, '_dl_survey_student', true);
        if ($owner !== (int) $user_id) return null;

        // The consent question was removed 2026-08-06, so consent_testimonial
        // is now always 0. Deleting on "no consent" would therefore destroy
        // EVERY photo a student shares. The photo is kept; what changes is
        // that nothing collected from here on may be published — see the note
        // in templates/survey.php.
        return $id;
    }

    const OPT_TEST_MODE = 'dogology_learning_survey_test_mode';
    const OPT_TEST_USER = 'dogology_learning_survey_test_user';

    public static function test_mode()  { return get_option(self::OPT_TEST_MODE, '0') === '1'; }
    public static function test_user()  { return (int) get_option(self::OPT_TEST_USER, 0); }

    /**
     * Wipe one student's response so the survey can be taken again: the
     * response, its answers, the invite-ledger row, the ฿0 grant order, and
     * the LMS access that grant created — scoped to the ebook's own course, so
     * a real 101 enrolment is never touched.
     *
     * @return array What was removed.
     */
    public static function purge_response($user_id)
    {
        global $wpdb;
        $out = array('response_id' => 0, 'answers' => 0, 'order_id' => 0, 'course_id' => 0);

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM " . self::responses_table() . " WHERE survey_key = %s AND user_id = %d",
            self::SURVEY_KEY, (int) $user_id
        ));
        if (!$row) return $out;

        $rid = (int) $row->id;
        $out['response_id'] = $rid;

        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT id, cohort_id FROM {$wpdb->prefix}dogology_orders WHERE order_number = %s", 'SG-' . $rid
        ));
        if ($order) {
            $out['order_id']  = (int) $order->id;
            $out['course_id'] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT linked_course_id FROM {$wpdb->prefix}dogology_cohorts WHERE id = %d",
                (int) $order->cohort_id));
        }

        $out['answers'] = (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM " . self::answers_table() . " WHERE response_id = %d", $rid));
        $wpdb->delete(self::responses_table(), array('id' => $rid));
        $wpdb->delete($wpdb->prefix . 'dogology_survey_invites',
            array('survey_key' => self::SURVEY_KEY, 'user_id' => (int) $user_id));
        if ($out['order_id']) {
            $wpdb->delete($wpdb->prefix . 'dogology_orders', array('id' => $out['order_id']));
            if ($out['course_id']) {
                $wpdb->delete($wpdb->prefix . 'dogology_progress',
                    array('user_id' => (int) $user_id, 'course_id' => $out['course_id']));
            }
        }
        return $out;
    }

    public static function store($user_id, array $payload)
    {
        // Test mode: the operator retests this flow constantly, and the
        // duplicate guard otherwise makes every run a one-shot. Scoped to ONE
        // nominated student id so it can never wipe a real customer's answers,
        // and off unless explicitly enabled in the admin screen.
        if (self::test_mode() && self::test_user() && (int) $user_id === self::test_user()) {
            self::purge_response((int) $user_id);
        }

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
            // Checked against the registries, not just sanitised: these are
            // client-supplied, feed reporting and the ebook grant, and anything
            // over 64 chars would have overflowed the column.
            // Reconciled against `applied`: un-ticking a topic hides its
            // best-topic radio but leaves it checked, so a student who changed
            // their mind could submit a "most effective" topic they never said
            // they tried — corrupting the exact cross-tab this survey exists for.
            'best_topic'              => self::best_topic($payload),
            'best_reason'             => $txt('best_reason'),
            'expectation'             => $txt('expectation'),
            'outcome'                 => $txt('outcome'),
            'add_other'               => $txt('add_other'),
            'comments'                => $txt('comments'),
            'ebook_choice'            => self::in_registry($payload, 'ebook_choice', array_keys(self::ebooks())),
            'consent_testimonial'     => !empty($payload['consent_testimonial']) ? 1 : 0,
            'dog_name'                => isset($payload['dog_name']) ? sanitize_text_field((string) $payload['dog_name']) : null,
            // Only honour an attachment this same student uploaded — the id is
            // client-supplied, and without the check any media id would attach.
            'photo_attachment_id'     => self::own_attachment($payload, $user_id),
            'raw_json'                => self::raw_json($payload),
            'submitted_at'            => $now,
        ));
        if (!$ok) {
            // has_responded() above is check-then-act; the UNIQUE key is what
            // actually decides a double-tap. Ask it who lost, so the loser gets
            // "already answered" instead of a generic failure they'd retry.
            if (self::has_responded($user_id)) {
                return new WP_Error('duplicate', 'ตอบแบบสอบถามนี้ไปแล้ว');
            }
            return new WP_Error('db', 'บันทึกไม่สำเร็จ');
        }
        $rid = (int) $wpdb->insert_id;

        // Multi-selects go to the long table so an implementation rate is a
        // GROUP BY rather than JSON parsing.
        $multi = array(
            // 'none' = "ยังไม่ได้ลองอะไรเลย" — a real answer, not an absence.
            'applied'  => array_merge(array_keys(self::topics()), array('none')),
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
        self::after_store($rid, (int) $user_id, self::effective_segment($payload, $user_id, $ctx['segment']));
        return $rid;
    }

    /* ------------------------------------------------------------------
     * Ebook grant
     * ---------------------------------------------------------------- */

    /**
     * ebook_choice slug -> commerce cohort id. The cohorts are the same ebook
     * cohorts the MindMap funnel sells (product "MindMap Ebooks"); the product
     * id is resolved from the cohort row at runtime so it is never duplicated
     * here. Filterable so a cohort can be re-pointed without a deploy.
     */
    public static function ebook_cohort_map()
    {
        return apply_filters('dogology_survey_ebook_cohorts', array(
            'watchdog' => 10,
            'hothead'  => 11,
            'rocket'   => 12,
            'shadow'   => 14,
        ));
    }

    /**
     * Everything that must happen once a response is safely on disk: stamp the
     * invite ledger so a chase can tell responders from non-responders, then
     * deliver the promised ebook. Deliberately never fails the submission —
     * the student's answers are already saved and a delivery problem is an
     * operator problem, not theirs.
     */
    /**
     * Segments offered an ebook.
     *
     * Was finished+near only. Opened to every invited segment 2026-08-05 —
     * the book is a thank-you for answering, not a reward for finishing, and
     * the people who stopped are the ones whose answers we most need. The gate
     * still exists so a response with no legitimate picker can't mint a grant.
     */
    /**
     * EVERY segment that can reach the picker must be listed here. The page
     * shows panel 1 to everyone, so any segment missing from this list gets
     * shown the book, promised it, and refused — which is exactly what
     * happened when 'active' was added as a segment and not added here.
     * If you add a segment, add it here in the same commit.
     */
    const EBOOK_SEGMENTS = array('finished', 'near', 'stalled', 'not_started', 'active');

    /**
     * Which segment the student was actually SHOWN — the real one, unless a
     * valid signed preview override was in play. The stored row always keeps
     * the real segment (the data must stay honest); this only decides whether
     * the ebook picker they saw was legitimate.
     */
    protected static function effective_segment(array $payload, $user_id, $real)
    {
        if (empty($payload['pv']) || empty($payload['pvs'])) return $real;
        $pv = sanitize_key((string) $payload['pv']);
        return self::verify_preview((int) $user_id, $pv, (string) $payload['pvs']) ? $pv : $real;
    }

    protected static function after_store($response_id, $user_id, $segment = '')
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}dogology_survey_invites SET responded_at = %s
             WHERE survey_key = %s AND user_id = %d AND responded_at IS NULL",
            current_time('mysql'), self::SURVEY_KEY, (int) $user_id
        ));

        // Delivery must never take down a submission that is already saved.
        // grant_ebook() calls into commerce and fires listeners; a fatal there
        // used to surface as a REST 500, so the student saw "ส่งไม่สำเร็จ",
        // retried, hit 'duplicate', and never reached the download screen —
        // while their answers sat safely in the table the whole time.
        try {
            $grant = self::grant_ebook((int) $response_id, $segment);
        } catch (\Throwable $e) {
            error_log('[dogology-survey] grant threw for response ' . (int) $response_id
                . ': ' . $e->getMessage());
            return;
        }
        if (is_wp_error($grant)) {
            // Admin still shows "ยังไม่ได้ส่ง" for this row; the log says why.
            error_log('[dogology-survey] ebook grant skipped for response '
                . (int) $response_id . ': ' . $grant->get_error_code()
                . ' — ' . $grant->get_error_message());
        }
    }

    /**
     * Create the ฿0 commerce order that carries the ebook entitlement and push
     * the same LINE + email delivery a paid ebook order gets.
     *
     * Deliberately NOT routed through create_order()/auto_confirm_payment():
     * those fire Meta CAPI Purchase and GA4 purchase events, and a giveaway is
     * not a sale. The order is tagged meta.survey_grant so it can be filtered
     * out of revenue, and final_amount is 0 so any SUM() already reads right.
     *
     * Idempotent on two levels: ebook_granted_at short-circuits a repeat call,
     * and the deterministic order_number ("SG-<response_id>") hits the UNIQUE
     * key if two requests race, so a retry can never mint a second order.
     *
     * @return int|WP_Error Order id, or WP_Error describing why nothing was granted.
     */
    public static function grant_ebook($response_id, $segment = '')
    {
        global $wpdb;

        $r = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::responses_table() . " WHERE id = %d", (int) $response_id
        ));
        if (!$r)                  return new WP_Error('no_response', 'ไม่พบคำตอบ');
        if ($r->ebook_granted_at) return new WP_Error('already_granted', 'ส่งไปแล้ว');
        if (!$r->ebook_choice)    return new WP_Error('no_choice', 'ไม่ได้เลือกเล่ม');

        // The ebook is offered to finishers and near-finishers only. Until now
        // the grant fired on the mere presence of ebook_choice, so a hand-made
        // POST from any enrolled student earned a free book. $segment is what
        // the page legitimately showed them, not a client-supplied value.
        $segment = $segment ?: $r->segment;
        if (!in_array($segment, self::EBOOK_SEGMENTS, true)) {
            return new WP_Error('not_eligible', 'กลุ่มนี้ไม่ได้รับอีบุ๊ก (' . $segment . ')');
        }

        $map = self::ebook_cohort_map();
        if (empty($map[$r->ebook_choice])) {
            return new WP_Error('unmapped', 'ยังไม่ได้ผูกเล่มนี้กับรุ่นในระบบขาย');
        }
        $cohort_id = (int) $map[$r->ebook_choice];

        $cohort = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dogology_cohorts WHERE id = %d", $cohort_id
        ));
        if (!$cohort || $cohort->content_type !== 'ebook') {
            return new WP_Error('bad_cohort', 'รุ่นนี้ไม่ใช่ ebook');
        }

        $student = $wpdb->get_row($wpdb->prepare(
            "SELECT id, display_name, email, line_uid FROM {$wpdb->prefix}dogology_users WHERE id = %d",
            (int) $r->user_id
        ));
        if (!$student) return new WP_Error('no_student', 'ไม่พบนักเรียน');
        if (!$student->line_uid && !$student->email) {
            return new WP_Error('no_channel', 'ไม่มีทั้ง LINE และอีเมลสำหรับส่ง');
        }

        $orders = $wpdb->prefix . 'dogology_orders';
        $number = 'SG-' . (int) $response_id;
        $now    = current_time('mysql');

        $wpdb->insert($orders, array(
            'order_number'    => $number,
            'product_id'      => (int) $cohort->product_id,
            'cohort_id'       => $cohort_id,
            'customer_name'   => $student->display_name ?: 'นักเรียน Dogology 101',
            'customer_email'  => $student->email ?: '',
            'line_user_id'    => $student->line_uid ?: '',
            'original_amount' => 0,
            'final_amount'    => 0,
            'status'          => 'paid',
            'meta'            => wp_json_encode(array(
                'survey_grant'       => true,
                'survey_key'         => self::SURVEY_KEY,
                'survey_response_id' => (int) $response_id,
                'granted_at'         => $now,
                // A giveaway is not a sale, so no Purchase may ever be sent for
                // it. Skipping dogology_order_approved was NOT enough:
                // Orders_API::retry_failed_purchase_capi() is a cron sweep that
                // every 5 minutes picks up any paid order whose meta does not
                // say a Purchase was already handled, and fires Meta CAPI + GA4
                // for it. A ฿0 grant looks exactly like that — which is how
                // SG-1, SG-2 and SG-5 each put a zero-value Purchase into the
                // data the ad algorithm optimises on. These are the two flags
                // that sweep's WHERE clause excludes on.
                'capi_purchase_sent'    => true,
                'capi_purchase_gave_up' => true,
            ), JSON_UNESCAPED_UNICODE),
            'created_at'      => $now,
        ));

        // Lost the race (or a retry): the winner's order is the grant.
        $order_id = (int) $wpdb->insert_id;
        if (!$order_id) {
            $order_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $orders WHERE order_number = %s", $number
            ));
            if (!$order_id) return new WP_Error('order_failed', 'สร้างออร์เดอร์ไม่สำเร็จ');
        }

        // Stamp BEFORE delivering: a push that throws must not leave the row
        // ungranted, or the next call mints a second order for the same person.
        $wpdb->update(self::responses_table(),
            array('ebook_granted_at' => $now), array('id' => (int) $response_id));

        // Grant LMS access. Inserting a paid row is not enough — course/ebook
        // entitlement is granted by the integration listening on this action,
        // which is why the first grants were downloadable from the flex link
        // but never appeared in My Courses.
        $full = $wpdb->get_row($wpdb->prepare(
            "SELECT o.*, p.name AS product_name, c.name AS cohort_name, c.content_type
             FROM $orders o
             LEFT JOIN {$wpdb->prefix}dogology_products p ON o.product_id = p.id
             LEFT JOIN {$wpdb->prefix}dogology_cohorts  c ON o.cohort_id  = c.id
             WHERE o.id = %d", $order_id
        ));
        if ($full) {
            // Call the entitlement listener DIRECTLY rather than firing
            // dogology_order_approved. That action is bridged to the platform
            // event bus as 'order.paid', which fans out to GA4 Measurement
            // Protocol and Meta CAPI — so every giveaway was injecting a
            // zero-value Purchase into the conversion data the ad algorithm
            // learns from (telemetry confirmed it for SG-1 and SG-2). It also
            // reaches the GCal listener, which has no business here.
            // Access is what we actually want; nothing else on that hook is.
            if (class_exists('Dogology_Learning_Integration_Commerce')) {
                $integration = new Dogology_Learning_Integration_Commerce();
                $integration->handle_order_approved($order_id, $full, 'paid');
            }
        }

        if (class_exists('Dogology_Commerce_Orders_API')) {
            // ONE channel, not both. The old comment claimed each helper
            // no-ops when its channel is missing — true — but most students
            // have LINE *and* an email, so both fired and the book was
            // delivered twice. LINE is where these students already talk to
            // us; email is the fallback for the ones LINE cannot reach.
            $sent_line = $student->line_uid
                ? Dogology_Commerce_Orders_API::send_payment_confirmation_line($order_id)
                : false;
            if (!$sent_line && $student->email) {
                Dogology_Commerce_Orders_API::send_order_email($order_id, 'payment_received');
            }
        }

        return $order_id;
    }

    /**
     * Signed download link for the ebook this student was just granted, so the
     * thank-you screen hands over the thing they answered for instead of
     * bouncing them to My Courses to go looking for it.
     *
     * @return string '' when there is no grant to point at.
     */
    public static function grant_download_url($user_id)
    {
        global $wpdb;
        if (!class_exists('Dogology_Ebook')) return '';

        $rid = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM " . self::responses_table() . "
             WHERE survey_key = %s AND user_id = %d AND ebook_granted_at IS NOT NULL",
            self::SURVEY_KEY, (int) $user_id
        ));
        if (!$rid) return '';

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT o.id, c.linked_course_id
             FROM {$wpdb->prefix}dogology_orders o
             LEFT JOIN {$wpdb->prefix}dogology_cohorts c ON o.cohort_id = c.id
             WHERE o.order_number = %s", 'SG-' . $rid
        ));
        if (!$row || empty($row->linked_course_id)) return '';

        return Dogology_Ebook::signed_download_url((int) $row->id, (int) $row->linked_course_id);
    }

    /** Front-end route: /101-survey/ */
    public static function boot()
    {
        add_action('init', function () {
            add_rewrite_rule('^101-survey/?$', 'index.php?dl_survey=1', 'top');
        });
        add_filter('query_vars', function ($v) { $v[] = 'dl_survey'; return $v; });
        // Belt and braces: define the bypass constants as early as `wp` so
        // Rocket never queues its optimisation for this request. By
        // template_redirect it is already too late for some of them.
        add_action('wp', function () {
            if (!get_query_var('dl_survey')) return;
            if (!defined('DONOTCACHEPAGE'))      define('DONOTCACHEPAGE', true);
            if (!defined('DONOTROCKETOPTIMIZE')) define('DONOTROCKETOPTIMIZE', true);
            if (!defined('DONOTMINIFY'))         define('DONOTMINIFY', true);
            if (!defined('DONOTASYNCCSS'))       define('DONOTASYNCCSS', true);
        }, 1);
        add_action('template_redirect', function () {
            if (!get_query_var('dl_survey')) return;
            if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
            if (!defined('DONOTROCKETOPTIMIZE')) define('DONOTROCKETOPTIMIZE', true);
            nocache_headers();
            self::maybe_handle_post();
            // A template fatal took the whole route down with a bare 500 and no
            // message. Fail visibly but gracefully instead, and record it.
            try {
                include DOGOLOGY_LEARNING_PATH . 'templates/survey.php';
            } catch (\Throwable $e) {
                error_log('[dogology-survey] template error: ' . $e->getMessage());
                status_header(500);
                echo '<!doctype html><meta charset="utf-8"><title>ขออภัย</title>'
                   . '<div style="font-family:sans-serif;padding:40px;text-align:center">'
                   . '<h1 style="font-size:20px">ระบบขัดข้องชั่วคราว</h1>'
                   . '<p>ลองใหม่อีกครั้งในสักครู่ครับ</p></div>';
            }
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
        register_rest_route('dogology-learning/v1', '/survey-photo', array(
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
            'callback'            => array(__CLASS__, 'handle_photo'),
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
        if ($channel === '' || !method_exists('Dogology_Auth', 'verify_line_id_token')) {
            return new WP_REST_Response(array('ok' => false, 'error' => 'liff_not_configured'), 400);
        }
        $claims = Dogology_Auth::verify_line_id_token($id_token, $channel);
        if (!$claims || empty($claims['sub'])) {
            return new WP_REST_Response(array('ok' => false, 'error' => 'invalid_token'), 401);
        }
        $user_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}dogology_users WHERE line_uid = %s LIMIT 1", $claims['sub']
        ));
        if (!$user_id) {
            return new WP_REST_Response(array('ok' => false, 'error' => 'no_student'), 404);
        }
        Dogology_Auth::login_student($user_id);
        return new WP_REST_Response(array('ok' => true), 200);
    }

    /**
     * Native form POST — the baseline, not the enhancement.
     *
     * The page submits over fetch(); if that script is ever stripped (minifier,
     * CSP, a browser that chokes on it) the browser falls back to a real POST.
     * Without a handler here that POST re-rendered an empty wizard and ten
     * screens of answers were gone with no error. Now the two paths do the same
     * thing, and JS is only saving a page load.
     */
    protected static function maybe_handle_post()
    {
        if (empty($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') return;
        // Identity first. The signed token IS the credential here and it is
        // verified independently; the nonce only guards against a cross-site
        // POST. Bailing on a stale nonce threw away ten screens of answers —
        // the exact failure this handler was written to prevent — and WP
        // nonces expire in 12–24h, which a link opened in a LINE webview and
        // submitted the next morning will hit.
        $user_id = self::actor(isset($_POST['t']) ? sanitize_text_field(wp_unslash($_POST['t'])) : '');
        if (!$user_id) return;

        $nonce_ok = !empty($_POST['dl_survey_nonce'])
            && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dl_survey_nonce'])), 'dl_survey_submit');
        // No nonce AND no token would be an unauthenticated cross-site post.
        // With a valid token we know who this is, so accept the answers.
        if (!$nonce_ok && empty($_POST['t'])) return;

        // The wizard's own state radio is navigation, not an answer; `t` is a
        // credential, not one either.
        $payload = wp_unslash($_POST);
        unset($payload['wz'], $payload['t'], $payload['dl_survey_nonce'], $payload['_wp_http_referer']);

        // Native POST names multi-selects "applied[]"; PHP already gives us the
        // array under "applied", so the shapes match what store() expects.
        $res = self::store($user_id, $payload);
        $arg = is_wp_error($res) ? array('err' => $res->get_error_code()) : array('done' => 1);
        // Same as the JS path: without the token the thank-you page cannot
        // identify the student and so cannot offer their download.
        if (!empty($_POST['t'])) {
            $arg['t'] = sanitize_text_field(wp_unslash($_POST['t']));
        }
        wp_safe_redirect(add_query_arg($arg, home_url('/101-survey/')));
        exit;
    }

    /**
     * Dog photo upload. Uploaded on pick rather than with the answers, because
     * the submit is JSON and a file can't ride in it — only the resulting
     * attachment id travels with the response.
     *
     * Gated on a real student session and images only: this writes to the media
     * library, so an open endpoint would be a public file drop.
     */
    public static function handle_photo($request)
    {
        $user_id = self::actor(isset($_POST['t']) ? sanitize_text_field(wp_unslash($_POST['t'])) : '');
        if (!$user_id) {
            return new WP_REST_Response(array('ok' => false, 'error' => 'not_logged_in'), 401);
        }
        if (empty($_FILES['file'])) {
            return new WP_REST_Response(array('ok' => false, 'error' => 'no_file'), 400);
        }
        $file = $_FILES['file'];
        if (!empty($file['size']) && $file['size'] > 8 * MB_IN_BYTES) {
            return new WP_REST_Response(array('ok' => false, 'error' => 'too_large'), 400);
        }
        $check = wp_check_filetype(isset($file['name']) ? $file['name'] : '');
        if (empty($check['type']) || strpos($check['type'], 'image/') !== 0) {
            return new WP_REST_Response(array('ok' => false, 'error' => 'not_image'), 400);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $id = media_handle_upload('file', 0, array(
            'post_title' => 'Survey photo — student ' . (int) $user_id,
        ), array('test_form' => false));
        if (is_wp_error($id)) {
            return new WP_REST_Response(array('ok' => false, 'error' => 'upload_failed',
                'message' => $id->get_error_message()), 400);
        }
        update_post_meta($id, '_dl_survey_student', (int) $user_id);

        return new WP_REST_Response(array(
            'ok' => true, 'attachment_id' => (int) $id,
            'url' => wp_get_attachment_image_url($id, 'thumbnail') ?: wp_get_attachment_url($id),
        ), 200);
    }

    /**
     * Who is submitting: a real LMS session (LIFF path), or the signed survey
     * token carried by the form (link path). The token authorises this survey
     * and nothing else — see the note in the template about why it no longer
     * mints a session.
     */
    protected static function actor($token = '')
    {
        $student = Dogology_Auth::get_current_student();
        if ($student) return (int) $student->id;
        return $token ? (int) self::verify_token($token) : 0;
    }

    public static function handle_submit($request)
    {
        $payload = $request->get_json_params();
        if (!is_array($payload)) $payload = $request->get_params();

        $user_id = self::actor(isset($payload['t']) ? (string) $payload['t'] : '');
        if (!$user_id) {
            return new WP_REST_Response(array('ok' => false, 'error' => 'not_logged_in'), 401);
        }
        // Credentials and form plumbing, not answers — and the nonce in
        // particular must not be archived into raw_json, which gets exported.
        // NOTE: pv/pvs stay — effective_segment() needs them to decide whether
        // the ebook picker the student saw was legitimate. raw_json() strips
        // them, along with the nonce, before anything is archived.
        unset($payload['t'], $payload['wz'], $payload['dl_survey_nonce'],
              $payload['_wp_http_referer']);
        $res = self::store($user_id, $payload);
        if (is_wp_error($res)) {
            return new WP_REST_Response(array('ok' => false, 'error' => $res->get_error_code(),
                'message' => $res->get_error_message()), 400);
        }
        return new WP_REST_Response(array('ok' => true, 'response_id' => $res), 200);
    }
}
