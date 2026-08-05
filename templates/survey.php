<?php
/**
 * Front-end survey page — /101-survey/
 *
 * Rendered from the approved mock so the design cannot drift from what was
 * signed off. Interaction is CSS-only (:checked + sibling combinators): no
 * :has(), no :target, no framework. The only JS is LIFF identity resolution
 * and the submit POST — the form itself works without it.
 */
if (!defined('ABSPATH')) exit;

$student = Dogology_Learning_Auth::get_current_student();
$ctx     = $student ? Dogology_Learning_Survey::context_for((int) $student->id) : null;
$mm      = get_option('dogology_mindmap_settings', array());
$liff_id = isset($mm['liff_id']) ? $mm['liff_id'] : '';

$display_name = $student && !empty($student->display_name) ? $student->display_name : '';
$initial      = $display_name !== '' ? mb_substr($display_name, 0, 1, 'UTF-8') : '?';
$topics       = $ctx ? Dogology_Learning_Survey::topics_for($ctx) : array();
$is_unf       = $ctx ? $ctx['is_unfinished'] : false;
$responded    = $student ? Dogology_Learning_Survey::has_responded((int) $student->id) : false;
?><!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title>แบบสอบถาม Dogology 101</title>
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600;700&family=Noto+Sans+Thai+Looped:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
:root{--head:Kanit,'Noto Sans Thai',Thonburi,sans-serif;--teal:#00AB8E;--teal-deep:#0F766E;--blue:#0076BA;
  --grad:linear-gradient(135deg,#00AB8E 0%,#0076BA 100%);--ink:#0F172A;--body:#475569;
  --muted:#64748B;--line:#EEF0F4;--rule:#E2E8F0;--bg:#FAFAFA;--pad:20px;--tap:48px}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html{-webkit-text-size-adjust:100%}
body{margin:0;font-family:'Noto Sans Thai Looped','TH Sarabun New','TH SarabunPSK',Thonburi,'Noto Sans Thai',sans-serif;background:#fff;color:var(--ink);
  line-height:1.75;font-size:15px;padding-bottom:0}
h1,.step h2,.book h3,.q .lab,.scale .num,

/* every control input is hidden; its <label> is the visible row */
.ci{position:fixed;top:0;left:0;width:1px;height:1px;opacity:0;pointer-events:none}

/* ---- mock chrome ---- */

.mockbar strong{color:#fff;font-weight:600}













/* ---- segment scoping ---- */
.only-unf{display:none}



.unf-title{display:none}



/* ---- wizard ---- */
.panel{display:none}
.prog{position:sticky;top:0;z-index:20;background:#fff;border-bottom:1px solid var(--line)}
.prog .bar{height:3px;background:var(--line)}
.prog .fill{height:100%;width:16.7%;background:var(--grad);transition:width .25s}
.prog .row{display:flex;justify-content:space-between;align-items:center;gap:12px;
  padding:11px var(--pad);font-size:12.5px;color:var(--muted)}
.slbl{color:var(--ink);font-weight:500;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.slbl b{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;
  border-radius:50%;background:var(--grad);color:#fff;font-size:11px;font-weight:600;
  margin-right:7px;vertical-align:-3px}
.slbl > span{display:none}
.slbl{color:var(--ink);font-weight:500;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.slbl b{display:inline-flex;align-items:center;justify-content:center;width:21px;height:21px;
  border-radius:50%;background:var(--grad);color:#fff;font-size:11px;font-weight:600;
  margin-right:7px;vertical-align:-4px}
.nav{position:sticky;bottom:0;z-index:10;display:flex;gap:10px;margin-top:18px;
  background:#fff;border-top:1px solid var(--line);
  padding:12px var(--pad) calc(14px + env(safe-area-inset-bottom))}
.tapgo{margin:16px var(--pad) 0;text-align:center;font-size:12px;color:var(--muted)}
.next{flex:1;display:block;text-align:center;background:var(--grad);color:#fff;border-radius:12px;
  padding:15px;font-size:16px;font-weight:500;cursor:pointer;line-height:1.4}
.back{flex:none;display:inline-flex;align-items:center;justify-content:center;color:var(--muted);
  border:1px solid var(--rule);border-radius:12px;padding:0 20px;font-size:15px;cursor:pointer;background:#fff}

/* ---- hero ---- */
.hero{background:var(--bg);padding:26px var(--pad) 22px;border-bottom:1px solid var(--line)}
.eyebrow{font-size:10.5px;letter-spacing:.14em;text-transform:uppercase;color:var(--teal);font-weight:600;margin:0 0 8px}
h1{margin:0 0 10px;font-size:23px;font-weight:600;line-height:1.45}
h1 .g{background:var(--grad);-webkit-background-clip:text;background-clip:text;color:transparent}
.lede{margin:0;color:var(--body);font-size:14.5px}
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
.step .n{font-size:11.5px;font-weight:600;color:var(--teal);white-space:nowrap}
.step h2{margin:0;font-size:18px;font-weight:600}
.hint{margin:0 0 18px;color:var(--muted);font-size:13px}
.q{margin-bottom:28px;display:flex;flex-direction:column}
.q .lab{font-size:15px;font-weight:500;margin-bottom:4px;line-height:1.55}
.q .sub{font-size:12.5px;color:var(--muted);margin-bottom:11px;line-height:1.6}
.q .sub2{font-size:15px;font-weight:500;margin:18px 0 2px;font-family:var(--head)}

/* ---- option rows: input + label, styled via :checked + label ---- */
.opt{display:flex;align-items:flex-start;gap:11px;border:1px solid var(--line);border-radius:10px;
  padding:13px 14px;min-height:var(--tap);font-size:14px;color:var(--body);cursor:pointer;
  margin-bottom:8px;background:#fff}
.opt i{flex:none;width:19px;height:19px;border:1.5px solid #CBD5E1;border-radius:5px;
  margin-top:3px;display:block;position:relative}
input[type=radio] + .opt i{border-radius:50%}
.ci:checked + .opt{border-color:var(--teal);background:rgba(0,171,142,.06);color:var(--ink)}
.ci:checked + .opt i{border-color:var(--teal);background:var(--teal)}
.ci:checked + .opt i:after{content:"";position:absolute;left:5px;top:1px;width:5px;height:10px;
  border:solid #fff;border-width:0 2px 2px 0;transform:rotate(45deg)}
.bopt{display:none}
#t1:checked ~ .bestwrap #b1{display:flex}
#t2:checked ~ .bestwrap #b2{display:flex}
#t3:checked ~ .bestwrap #b3{display:flex}
#t4:checked ~ .bestwrap #b4{display:flex}
#t5:checked ~ .bestwrap #b5{display:flex}
#t6:checked ~ .bestwrap #b6{display:flex}
#t7:checked ~ .bestwrap #b7{display:flex}
#t8:checked ~ .bestwrap #b8{display:flex}
#t9:checked ~ .bestwrap #b9{display:flex}
#t10:checked ~ .bestwrap #b10{display:flex}
#t11:checked ~ .bestwrap #b11{display:flex}
.empty{margin:0;padding:13px 14px;border:1px dashed var(--rule);border-radius:10px;
  color:var(--muted);font-size:13px;text-align:center}
#t1:checked ~ .bestwrap .empty,#t2:checked ~ .bestwrap .empty,#t3:checked ~ .bestwrap .empty,#t4:checked ~ .bestwrap .empty,#t5:checked ~ .bestwrap .empty,#t6:checked ~ .bestwrap .empty,#t7:checked ~ .bestwrap .empty,#t8:checked ~ .bestwrap .empty,#t9:checked ~ .bestwrap .empty,#t10:checked ~ .bestwrap .empty,#t11:checked ~ .bestwrap .empty{display:none}
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
.book h3{margin:0 0 2px;font-size:16px;font-weight:600;padding-right:34px}
.book .en{margin:0 0 9px;font-size:10.5px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted)}
.book p{margin:0 0 11px;font-size:13.5px;color:var(--body);line-height:1.65}
.book .who{margin:0;font-size:12.5px;color:var(--teal-deep);background:rgba(0,171,142,.08);
  border-radius:8px;padding:9px 11px;line-height:1.6}
.ownlbl{position:absolute;top:14px;right:15px;font-size:10.5px;color:var(--muted);
  background:#F1F5F9;padding:2px 9px;border-radius:12px}

/* ---- scale + stars ---- */
.scale{display:flex;gap:6px}
.scale label{flex:1 1 0;min-width:0;text-align:center;border:1px solid var(--line);border-radius:10px;
  padding:11px 3px;cursor:pointer;background:#fff}
.ci:checked + .sc{border-color:var(--teal);background:rgba(0,171,142,.07)}
.scale .num{display:block;font-size:18px;font-weight:600;color:var(--ink)}
.scale .cap{display:block;font-size:10px;color:var(--muted);line-height:1.35}
.starbox{background:var(--bg);border:1px solid var(--line);border-radius:12px;padding:20px 16px}
.stars{display:flex;flex-direction:row-reverse;justify-content:center}
.stars label{color:#E2E8F0;font-size:34px;line-height:1;padding:5px;cursor:pointer;min-width:var(--tap);text-align:center}
.stars .ci:checked ~ label{color:#F59E0B}
.stars label:hover,.stars label:hover ~ label{color:#FBBF24}
.starcap{text-align:center;font-size:13px;color:var(--muted);margin:10px 0 0}
.note{background:#F0F9FF;border-left:3px solid var(--blue);border-radius:0 9px 9px 0;
  padding:14px 16px;margin:0 0 14px;font-size:13.5px;color:var(--body);line-height:1.75}
.perm{background:var(--bg);border:1px solid var(--line);border-radius:11px;padding:15px}
.upload{display:flex;align-items:center;gap:13px;border:1.5px dashed var(--rule);border-radius:11px;
  padding:14px;cursor:pointer;background:#fff;margin-top:4px}
.upload .ic{font-size:22px;flex:none}
.upload .ut{font-size:12.5px;color:var(--muted);line-height:1.55}
.upload .ut b{color:var(--ink);font-weight:500;font-size:13.5px}
.fine{margin:14px 0 0;text-align:center;font-size:12px;color:var(--muted)}

/* ---- LINE panel ---- */














@media (min-width:600px){:root{--pad:32px}h1{font-size:27px}.books{grid-template-columns:repeat(2,1fr)}.scale .cap{font-size:11.5px}}
@media (min-width:1040px){:root{--pad:40px}
  body{background:#F1F5F9;padding-bottom:60px}
  .shell{max-width:1140px;margin:0 auto;padding:28px 20px 60px;display:grid;
    grid-template-columns:1fr 340px;gap:28px;align-items:start}
  .form{background:#fff;border:1px solid var(--line);border-radius:16px;overflow:hidden}
  
}

#w1:checked ~ .shell .fill{width:10.0%}
#w1:checked ~ .shell .p1{display:flex}

#w2:checked ~ .shell .fill{width:20.0%}
#w2:checked ~ .shell .p2{display:flex}

#w3:checked ~ .shell .fill{width:30.0%}
#w3:checked ~ .shell .p3{display:flex}

#w4:checked ~ .shell .fill{width:40.0%}
#w4:checked ~ .shell .p4{display:flex}

#w5:checked ~ .shell .fill{width:50.0%}
#w5:checked ~ .shell .p5{display:flex}

#w6:checked ~ .shell .fill{width:60.0%}
#w6:checked ~ .shell .p6{display:flex}

#w7:checked ~ .shell .fill{width:70.0%}
#w7:checked ~ .shell .p7{display:flex}
#w8:checked ~ .shell .fill{width:80.0%}
#w8:checked ~ .shell .p8{display:flex}

#w9:checked ~ .shell .fill{width:90.0%}
#w9:checked ~ .shell .p9{display:flex}
#w10:checked ~ .shell .fill{width:100.0%}
#w10:checked ~ .shell .p10{display:flex}

.prog .row{display:none}
.nav .only-fin,.nav .only-unf{}

/* --- compact pass: target is one 667px screen per question --- */
body{font-size:14.5px;line-height:1.65}
.sec{padding:18px var(--pad) 4px}
.hero{padding:18px var(--pad) 16px}
h1{font-size:20px;line-height:1.4;margin:0 0 8px}
.lede{font-size:13.5px}
.who-strip{padding:9px 11px;margin-top:12px}
.who-strip .av{width:32px;height:32px;font-size:13px}
.note{padding:11px 13px;margin:0 0 11px;font-size:12.5px;line-height:1.65}
.hint{margin:0 0 12px;font-size:12.5px}
.q{margin-bottom:0}
.q .lab{font-size:16px;line-height:1.45;margin-bottom:3px}
.q .sub{font-size:12px;margin-bottom:9px}
.q .sub2{font-size:15px;margin:14px 0 2px}
.opt{padding:9px 12px;min-height:0;font-size:13.5px;margin-bottom:6px;gap:9px;line-height:1.45}
.opt i{width:17px;height:17px;margin-top:2px}
.ci:checked + .opt i:after{left:4.5px;top:1px;width:4px;height:9px}
textarea{font-size:16px;padding:11px 13px;line-height:1.55}
.books{gap:8px}
.book{padding:11px 12px}
.cover{width:58px;aspect-ratio:3/4.2}
.book h3{font-size:14.5px}
.book .en{margin:0 0 6px;font-size:9.5px}
.book p{font-size:12.5px;margin:0;line-height:1.5}
.book .who{display:none}
.bookrow{gap:11px}
.scale label{padding:14px 3px}
.scale .num{font-size:20px}
.starbox{padding:14px}
.stars label{font-size:38px;padding:4px}
.perm{padding:12px}
.upload{padding:11px}
.prog .bar{height:4px}

.next{padding:13px;font-size:15px}
.back{padding:0 16px;font-size:14px}
@media (min-height:760px){
  .sec{padding:24px var(--pad) 6px}
  .opt{padding:11px 13px;margin-bottom:7px}
  h1{font-size:23px}
}

/* --- segment scoping for nav buttons ---------------------------------------
   .only-unf{display:none} and .back{display:inline-flex} have the SAME
   specificity (0,1,0) and .back is declared later, so it won and both the
   finished and unfinished buttons rendered together. These rules are scoped
   under .nav (0,2,0) so they outrank the button display rules.             */
.nav .only-unf{display:none}
.nav .only-fin.back{display:inline-flex}
.nav .only-fin.next{display:block;flex:1}



/* same collision class for any question block that still carries the flag */
.q.only-unf{display:none}



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
.bestwrap .sub2,.subq .sub2{margin:0 0 2px;font-size:15px}
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

.gate{max-width:420px;margin:0 auto;padding:48px 24px;text-align:center}
.gate h1{font-size:20px;margin:0 0 10px}
.gate p{color:var(--body);font-size:14.5px}
.gate .next{display:inline-block;margin-top:18px;padding:14px 26px;border-radius:12px;
  background:var(--grad);color:#fff;text-decoration:none}
.sending{opacity:.55;pointer-events:none}
.err{background:#FEF2F2;border:1px solid #FECACA;color:#991B1B;border-radius:10px;
  padding:11px 13px;margin:0 var(--pad) 12px;font-size:13px}
</style>
</head>
<body class="<?php echo $is_unf ? 'mode-unf' : ''; ?>">
<?php if ($responded): ?>
  <div class="gate">
    <h1>ได้รับคำตอบแล้วครับ</h1>
    <p>ขอบคุณที่สละเวลาตอบนะครับ ทีมงานจะติดต่อเรื่องอีบุ๊กทาง LINE อีกครั้ง</p>
    <a class="next" href="<?php echo esc_url(home_url('/my-courses/')); ?>">กลับไปหน้าคอร์สของฉัน</a>
  </div>
<?php elseif (!$ctx): ?>
  <div class="gate" id="gate">
    <h1>ยังไม่พบข้อมูลผู้เรียน</h1>
    <p>เปิดลิงก์นี้จาก LINE เพื่อให้ระบบรู้ว่าเราคือใคร หรือเข้าสู่ระบบก่อนครับ</p>
    <a class="next" href="<?php echo esc_url(home_url('/student-login/')); ?>">เข้าสู่ระบบ</a>
  </div>
<?php else: ?>
<form id="survey-form" method="post">


<input class="ci" type="radio" name="wz" id="w1" checked>
<input class="ci" type="radio" name="wz" id="w2">
<input class="ci" type="radio" name="wz" id="w3">
<input class="ci" type="radio" name="wz" id="w4">
<input class="ci" type="radio" name="wz" id="w5">
<input class="ci" type="radio" name="wz" id="w6">
<input class="ci" type="radio" name="wz" id="w7">
<input class="ci" type="radio" name="wz" id="w8">
<input class="ci" type="radio" name="wz" id="w9">
<input class="ci" type="radio" name="wz" id="w10">

<div class="shell">
  <div class="form">
    <div class="prog"><div class="bar"><div class="fill"></div></div><div class="row"></div></div>
    <section class="panel p1">
      <div class="hero">
        <p class="eyebrow">Dogology 101</p>
        <h1>ก่อนอื่น <span class="g">เลือกอีบุ๊กฟรี 1 เล่ม</span></h1>
        <p class="lede">ตอนนี้เรากำลังจะปรับปรุงคอร์สครั้งใหญ่ สิ่งที่ตอบวันนี้จะกำหนดว่าจะเพิ่มอะไรเข้าไปบ้าง ตอบตรง ๆ ได้เลยครับ ไม่ต้องเกรงใจ</p>
        <div class="who-strip"><div class="av"><?php echo esc_html($initial); ?></div>
          <div class="nm"><b><?php echo esc_html($display_name); ?></b><span>เข้าสู่ระบบด้วย LINE อัตโนมัติ</span></div>
          <span class="ok">ยืนยันแล้ว</span></div>
      </div>
      <div class="sec">
        <p class="note">มีเจ้าของหลายคนบอกเรามาว่า เนื้อหาบางส่วนยังไม่ลึกเท่าที่คาดไว้ เรารับฟังและกำลังวางแผนขยายเนื้อหาครั้งใหญ่ครับ (เนื้อหาที่เพิ่มจะฟรีสำหรับนักเรียนปัจจุบันทุกคน)<br><br>ระหว่างที่ยังทำไม่เสร็จ เรามีอีบุ๊กที่เจาะลึกกว่าเดิมอยู่แล้ว 4 เล่ม และอยากส่งให้ทุกท่านก่อนใคร เลือกได้เลย 1 เล่มฟรีครับ</p>
        <p class="hint">แต่ละเล่มเจาะแต่ละกลุ่มปัญหาพฤติกรรมครับ อ่านคำอธิบายแล้วเลือกเล่มที่ตรงกับหมาของคุณที่สุดได้เลยครับ</p>
<div class="books">
        <input class="ci" type="radio" name="ebook_choice" value="watchdog" id="ebk1"><label class="book" for="ebk1">
          <div class="bookrow">
            <div class="cover"><img src="https://dogology.org/wp-content/uploads/2026/07/watchdog-cover-6a550081e3721.webp" alt="คู่มือเลี้ยงหมาระแวง"></div>
            <div class="bookmain">
              <h3>คู่มือเลี้ยงหมาระแวง</h3>
              <p class="en">The Watchdog</p>
              <p>หมาที่สแกนหาอันตรายตลอดเวลา เห่าคนผ่านหน้าบ้าน ตื่นตัวกับเสียง ระวังคนแปลกหน้า
                 ไม่ใช่หมาดุ แต่เป็นหมาที่ยังไม่รู้สึกปลอดภัยพอ</p>
            </div>
          </div>
          <p class="who">เหมาะกับ: เห่าคนนอกบ้าน หวงพื้นที่ ตกใจง่าย ผ่อนคลายยากเวลามีคนมา</p>
        </label>

        <input class="ci" type="radio" name="ebook_choice" value="rocket" id="ebk2"><label class="book" for="ebk2">
          <div class="bookrow">
            <div class="cover"><img src="https://dogology.org/wp-content/uploads/2026/07/rocket-cover-6a5e24c9b7c22.webp" alt="คู่มือเลี้ยงหมาจรวด"></div>
            <div class="bookmain">
              <h3>คู่มือเลี้ยงหมาจรวด</h3>
              <p class="en">The Rocket</p>
              <p>หมาที่มีพลังเหลือและเบรกไม่ค่อยอยู่ กระโดดใส่คน ดึงสายจูง ตื่นเต้นแล้วลงยาก
                 ปัญหาไม่ใช่พลังงาน แต่คือยังไม่มีปุ่มหยุด</p>
            </div>
          </div>
          <p class="who">เหมาะกับ: ดึงสาย กระโดดใส่แขก อยู่ไม่นิ่ง เรียกไม่ค่อยมา</p>
        </label>

        <label class="book own"><span class="ownlbl">มีแล้ว</span>
          <div class="bookrow">
            <div class="cover"><img src="https://dogology.org/wp-content/uploads/2026/07/hothead-cover-6a5b4361ce2c2.webp" alt="คู่มือเลี้ยงหมาใจร้อน"></div>
            <div class="bookmain">
              <h3>คู่มือเลี้ยงหมาใจร้อน</h3>
              <p class="en">The Hothead</p>
              <p>หมาที่ทนรอไม่ได้ อยากได้แล้วต้องได้เดี๋ยวนี้ เห่าใส่ของที่เอื้อมไม่ถึง
                 หงุดหงิดเวลาถูกกั้น อารมณ์มาไวไปไว</p>
            </div>
          </div>
          <p class="who">เหมาะกับ: เห่าเวลาไม่ได้ดั่งใจ งับสายจูง รอไม่เป็น คับข้องใจง่าย</p>
        </label>

        <input class="ci" type="radio" name="ebook_choice" value="shadow" id="ebk3"><label class="book" for="ebk3">
          <div class="bookrow">
            <div class="cover"><img src="https://dogology.org/wp-content/uploads/2026/07/shadow-cover-6a604959256d8.webp" alt="คู่มือเลี้ยงหมากังวล"></div>
            <div class="bookmain">
              <h3>คู่มือเลี้ยงหมากังวล</h3>
              <p class="en">The Shadow</p>
              <p>หมาขี้กลัว ขาดความมั่นใจ เจอของใหม่แล้วถอย ตกใจง่าย ไม่กล้าลอง
                 ไม่ใช่หมานิสัยไม่ดี แต่เป็นหมาที่ยังไม่มีฐานที่รู้สึกปลอดภัยพอ</p>
            </div>
          </div>
          <p class="who">เหมาะกับ: กลัวเสียงดัง กลัวคนแปลกหน้า ไม่กล้าเข้าหาของใหม่ ต้องการสร้างความมั่นใจทีละขั้น</p>
        </label>
      </div>
      </div>
      <div class="nav"><label class="next" for="w2">ถัดไป</label></div>
    </section>
    <section class="panel p2">
<div class="only-fin">      <div class="sec">
<div class="q">
          <span class="lab">เรื่องไหนที่คุณเอาไปใช้จริงบ้าง *</span>
          <span class="sub">เลือกได้หลายข้อ</span><?php foreach ($topics as $k => $t): ?>
          <input class="ci" type="checkbox" name="applied[]" value="<?php echo esc_attr($k); ?>" id="t_<?php echo esc_attr($k); ?>"><label class="opt" for="t_<?php echo esc_attr($k); ?>"><i></i><span><?php echo esc_html($t['label']); ?></span></label>
          <?php endforeach; ?>
          <input class="ci" type="checkbox" name="applied[]" value="none" id="t_none"><label class="opt" for="t_none"><i></i><span>ยังไม่ได้ลองอะไรเลย</span></label>
          <div class="bestwrap">
            <span class="sub2">แล้วเรื่องไหนได้ผลที่สุด *</span>
            <span class="sub">รายการจะขึ้นตามที่ติ๊กไว้ด้านบน</span>
        <?php foreach ($topics as $k => $t): ?>
            <input class="ci" type="radio" name="best_topic" value="<?php echo esc_attr($k); ?>" id="bi_<?php echo esc_attr($k); ?>"><label class="opt bopt" id="b_<?php echo esc_attr($k); ?>" for="bi_<?php echo esc_attr($k); ?>"><i></i><span><?php echo esc_html($t['label']); ?></span></label>
            <?php endforeach; ?>
            <p class="empty">ติ๊กด้านบนก่อน แล้วรายการจะขึ้นตรงนี้</p>
          </div>
          <div class="subq">
            <span class="sub2">แล้วทำไมถึงเป็นเรื่องนั้น *</span>
            <textarea name="best_reason" rows="3" placeholder="เล่าให้ฟังหน่อยว่าใช้แล้วเกิดอะไรขึ้น"></textarea>
          </div>
          
        </div>
      </div></div><div class="only-unf">      <div class="sec">
<div class="q">
          <span class="lab">มีอะไรที่ทำให้เรียนไม่ต่อเนื่องบ้าง *</span>
          <span class="sub">เลือกได้หลายข้อ ตอบตรง ๆ ได้เลยครับ</span>
      <?php foreach (Dogology_Learning_Survey::options('friction') as $k => $lbl): ?>
          <input class="ci" type="checkbox" name="friction[]" value="<?php echo esc_attr($k); ?>" id="fb_<?php echo esc_attr($k); ?>"><label class="opt" for="fb_<?php echo esc_attr($k); ?>"><i></i><span><?php echo esc_html($lbl); ?></span></label>
          <?php endforeach; ?>
          
        </div>
      </div></div>
      <div class="nav"><label class="back" for="w1">ย้อนกลับ</label><label class="next" for="w3">ถัดไป</label></div>
    </section>
    <section class="panel p3">
<div class="only-fin">      <div class="sec">
<div class="q">
          <span class="lab">เนื้อหาส่วนไหนที่ชอบที่สุด *</span>
          <span class="sub">เลือกได้หลายข้อ ไม่จำเป็นต้องเป็นเรื่องที่เอาไปใช้</span>
      <?php foreach ($topics as $k => $t): ?>
          <input class="ci" type="checkbox" name="liked[]" value="<?php echo esc_attr($k); ?>" id="lk_<?php echo esc_attr($k); ?>"><label class="opt" for="lk_<?php echo esc_attr($k); ?>"><i></i><span><?php echo esc_html($t['label']); ?></span></label>
          <?php endforeach; ?>
          
        </div>
      </div></div><div class="only-unf">      <div class="sec">
<div class="q">
          <span class="lab">ตอนที่ซื้อคอร์ส คุณคาดหวังว่าจะได้อะไร *</span>
          <textarea name="expectation" rows="4" placeholder="ตอนนั้นคิดว่าจะได้อะไรจากคอร์สนี้"></textarea>
          
        </div>
      </div></div>
      <div class="nav"><label class="back" for="w2">ย้อนกลับ</label><label class="next" for="w4">ถัดไป</label></div>
    </section>
    <section class="panel p4">
<div class="only-fin">      <div class="sec">
<div class="q">
          <span class="lab">คอร์สนี้คุ้มค่ากับที่จ่ายไปแค่ไหน *</span>
          <div class="scale">
            <input class="ci" type="radio" name="worth_rating" value="1" id="wo1"><label class="sc" for="wo1"><span class="num">1</span><span class="cap">ไม่คุ้ม</span></label>
            <input class="ci" type="radio" name="worth_rating" value="2" id="wo2"><label class="sc" for="wo2"><span class="num">2</span><span class="cap">คุ้มน้อย</span></label>
            <input class="ci" type="radio" name="worth_rating" value="3" id="wo3"><label class="sc" for="wo3"><span class="num">3</span><span class="cap">พอใช้</span></label>
            <input class="ci" type="radio" name="worth_rating" value="4" id="wo4"><label class="sc" for="wo4"><span class="num">4</span><span class="cap">คุ้ม</span></label>
            <input class="ci" type="radio" name="worth_rating" value="5" id="wo5"><label class="sc" for="wo5"><span class="num">5</span><span class="cap">คุ้มมาก</span></label>
          </div>
          
        </div>
      </div></div><div class="only-unf">      <div class="sec">
<div class="q">
          <span class="lab">อะไรที่จะทำให้คุณกลับมาเรียนต่อ</span>
          <span class="sub">เลือกได้หลายข้อ</span>
      <?php foreach (Dogology_Learning_Survey::options('comeback') as $k => $lbl): ?>
          <input class="ci" type="checkbox" name="comeback[]" value="<?php echo esc_attr($k); ?>" id="bk_<?php echo esc_attr($k); ?>"><label class="opt" for="bk_<?php echo esc_attr($k); ?>"><i></i><span><?php echo esc_html($lbl); ?></span></label>
          <?php endforeach; ?>
          <textarea name="add_other" rows="3" style="margin-top:6px" placeholder="ถ้าเลือกอื่น ๆ หรืออยากขยายความ เขียนตรงนี้ได้เลย"></textarea>
          
        </div>
      </div></div>
      <div class="nav"><label class="back" for="w3">ย้อนกลับ</label><label class="next" for="w5">ถัดไป</label></div>
    </section>
    <section class="panel p5">
      <div class="sec">
<div class="q">
          <span class="lab">อยากให้เพิ่มหรือปรับอะไรในคอร์สบ้าง *</span>
          <span class="sub">เลือกได้หลายข้อ</span>
      <?php foreach (Dogology_Learning_Survey::options('add') as $k => $lbl): ?>
          <input class="ci" type="checkbox" name="add[]" value="<?php echo esc_attr($k); ?>" id="ad_<?php echo esc_attr($k); ?>"><label class="opt" for="ad_<?php echo esc_attr($k); ?>"><i></i><span><?php echo esc_html($lbl); ?></span></label>
          <?php endforeach; ?>
          <textarea name="add_other" rows="3" style="margin-top:6px" placeholder="ถ้าเลือกอื่น ๆ หรืออยากขยายความ เขียนตรงนี้ได้เลย"></textarea>
          
        </div>
      </div>
      <div class="nav"><label class="back" for="w4">ย้อนกลับ</label><label class="next" for="w6">ถัดไป</label></div>
    </section>
    <section class="panel p6">
      <div class="sec">
<div class="q">
          <span class="lab">อะไรที่เรียนจากในคอร์สแล้วช่วยให้หมาของคุณเปลี่ยนไปบ้าง เล่าให้ฟังหน่อย หรือไม่เปลี่ยนไปเลยก็บอกได้</span>
          <span class="sub">เล่าเป็นเหตุการณ์ก็ได้ครับ เช่น เมื่อก่อนเป็นแบบไหน ตอนนี้เป็นแบบไหน</span>
          <textarea name="outcome" rows="4" placeholder="ยิ่งเล่าละเอียด ยิ่งช่วยเราได้มาก"></textarea>
        </div>
      </div>
      <div class="nav"><label class="back" for="w5">ย้อนกลับ</label><label class="next only-fin" for="w7">ถัดไป</label><label class="next only-unf" for="w8">ถัดไป</label></div>
    </section>
    <section class="panel p7">
<div class="only-fin">      <div class="sec">
<div class="q">
          <span class="lab">มีอะไรที่ทำให้เรียนไม่ต่อเนื่องบ้าง</span>
          <span class="sub">เลือกได้หลายข้อ</span>
      <?php foreach (Dogology_Learning_Survey::options('friction') as $k => $lbl): ?>
          <input class="ci" type="checkbox" name="friction[]" value="<?php echo esc_attr($k); ?>" id="fa_<?php echo esc_attr($k); ?>"><label class="opt" for="fa_<?php echo esc_attr($k); ?>"><i></i><span><?php echo esc_html($lbl); ?></span></label>
          <?php endforeach; ?>
          
        </div>
      </div></div>
      <div class="nav"><label class="back" for="w6">ย้อนกลับ</label><label class="next" for="w8">ถัดไป</label></div>
    </section>
    <section class="panel p8">
      <div class="sec">
<div class="q">
          <span class="lab">อยากบอกอะไรเพิ่มเติมไหม</span>
          <textarea name="comments" rows="3" placeholder="อะไรก็ได้"></textarea>
        </div>
      </div>
      <div class="nav"><label class="back only-fin" for="w7">ย้อนกลับ</label><label class="back only-unf" for="w6">ย้อนกลับ</label><label class="next only-fin" for="w9">ถัดไป</label><label class="next only-unf" for="w10">ถัดไป</label></div>
    </section>
    <section class="panel p9">
<div class="only-fin">      <div class="sec">
<div class="q">
          <span class="lab" style="text-align:center">ให้คะแนนคอร์สนี้โดยรวม *</span>
          <div class="starbox">
            <div class="stars">
              <input class="ci" type="radio" name="star_rating" value="5" id="s5"><label for="s5">&#9733;</label>
              <input class="ci" type="radio" name="star_rating" value="4" id="s4"><label for="s4">&#9733;</label>
              <input class="ci" type="radio" name="star_rating" value="3" id="s3"><label for="s3">&#9733;</label>
              <input class="ci" type="radio" name="star_rating" value="2" id="s2"><label for="s2">&#9733;</label>
              <input class="ci" type="radio" name="star_rating" value="1" id="s1"><label for="s1">&#9733;</label>
            </div>
            <p class="starcap">แตะดาวเพื่อให้คะแนน</p>
          </div>
        </div>
      </div></div>
      <div class="nav"><label class="back" for="w8">ย้อนกลับ</label><label class="next" for="w10">ถัดไป</label></div>
    </section>
    <section class="panel p10">
      <div class="sec">
<div class="q">
          <span class="lab">เรื่องของน้องอาจช่วยเจ้าของคนอื่นได้</span>
          <span class="sub">เจ้าของหลายคนกำลังเจอปัญหาแบบเดียวกับที่คุณเคยเจอ
            สิ่งที่คุณเล่ามาอาจเป็นสิ่งที่เขากำลังตามหาอยู่ครับ</span>
          <div class="perm">
            <input class="ci" type="checkbox" name="consent_testimonial" value="1" id="consent"><label class="opt" for="consent" style="margin:0"><i></i><span>ให้ Dogology นำคำตอบไปใช้เล่าต่อได้ เช่น ในเว็บไซต์หรือโซเชียล</span></label>
            <div style="margin-top:12px">
              <span class="sub">น้องหมาชื่ออะไร</span>
              <input type="text" name="dog_name" placeholder="เช่น ข้าวปั้น">
              <span class="sub" style="margin-top:14px">อยากแนบรูปน้องไปด้วยไหม (ไม่บังคับ)</span>
              <label class="upload"><input type="file" accept="image/*" hidden>
                <span class="ic">&#128247;</span>
                <span class="ut"><b>เลือกรูปน้อง</b><br>JPG หรือ PNG ไม่เกิน 5 MB</span></label>
            </div>
          </div>
          
        </div>
      </div>
      <div class="nav"><label class="back only-fin" for="w9">ย้อนกลับ</label><label class="back only-unf" for="w8">ย้อนกลับ</label><button type="submit" class="next">ส่งคำตอบ และรับอีบุ๊ก</button></div>
    </section>
  </div>

  </div>
</form>
<?php endif; ?>

<script>
(function () {
  var form = document.getElementById('survey-form');
  if (!form) return;
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    form.classList.add('sending');
    var fd = new FormData(form), out = {};
    fd.forEach(function (v, k) {
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
<?php if ($liff_id && !$student): ?>
<script src="https://static.line-login.jp/liff/edge/2/sdk.js"></script>
<script>
// LIFF fallback: only runs when there is no learning session yet. The ID token
// is verified server-side before any identity is trusted — getProfile() alone
// is client-supplied and must never grant an ebook.
liff.init({liffId: '<?php echo esc_js($liff_id); ?>'}).then(function () {
  if (!liff.isLoggedIn()) { liff.login(); return; }
  fetch('<?php echo esc_url_raw(rest_url('dogology-learning/v1/survey-liff')); ?>', {
    method: 'POST', credentials: 'same-origin',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({id_token: liff.getIDToken()})
  }).then(function (r) { return r.json(); }).then(function (j) {
    if (j && j.ok) location.reload();
  });
}).catch(function () {});
</script>
<?php endif; ?>
</body>
</html>
