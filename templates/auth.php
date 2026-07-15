<?php
/**
 * Template: Student Login
 * URL: /student-login
 */

if (!defined('ABSPATH')) {
    exit;
}

// WP Rocket / Caching compatibility
if (!defined('DONOTCACHEPAGE')) {
    define('DONOTCACHEPAGE', true);
}
// Force Browser to not cache this page
nocache_headers();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// If just logged out, force clear cookie (Double-Tap)
// ONLY on GET request. prevent interference with POST login.
$just_logged_out = isset($_GET['logged_out']) && $_GET['logged_out'] == '1';
if ($just_logged_out && $_SERVER['REQUEST_METHOD'] === 'GET') {
    Dogology_Auth::logout();
}

// PASSKEY BRIDGE: Handle auth_token + trigger_passkey from LIFF -> Safari handoff
$trigger_passkey_js = false;
$js_student = null; // Will be set if passkey bridge is active
if (isset($_GET['auth_token']) && isset($_GET['trigger_passkey'])) {
    $token_parts = explode('|', $_GET['auth_token']); // uid|hash|ts
    if (count($token_parts) === 3) {
        $uid = intval($token_parts[0]);
        $hash = $token_parts[1];
        $ts = intval($token_parts[2]);

        // Verify Hash & Expiry (1 min window) - Note: passkey tokens include 'passkey' in signature
        $check = hash_hmac('sha256', $uid . 'passkey' . $ts, DOGOLOGY_AUTH_SALT);
        if (time() < $ts && hash_equals($check, $hash)) {
            Dogology_Auth::login_student($uid);
            $trigger_passkey_js = true; // Flag to auto-trigger passkey creation in JavaScript

            // Fetch student for JS output (dogologyUser)
            $db = new Dogology_Student_DB();
            $js_student = $db->get_student($uid);
        }
    }
}

$error = '';
$step = isset($_GET['step']) ? $_GET['step'] : 'login';
$email_input = isset($_GET['email']) ? sanitize_email($_GET['email']) : '';

// --- EARLY LANGUAGE DETECTION (for error messages in POST handlers) ---
$_auth_lang = 'en';
if (isset($_COOKIE['dl_lang']) && in_array($_COOKIE['dl_lang'], ['th', 'en'])) {
    $_auth_lang = $_COOKIE['dl_lang'];
} elseif (isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) && substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2) === 'th') {
    $_auth_lang = 'th';
}
$_err = [
    'invalid_token'   => $_auth_lang === 'th' ? 'Token ไม่ถูกต้อง กรุณารีเฟรชหน้า' : 'Invalid security token. Please refresh the page.',
    'invalid_email'   => $_auth_lang === 'th' ? 'อีเมลไม่ถูกต้อง' : 'Invalid email address.',
    'invalid_otp'     => $_auth_lang === 'th' ? 'รหัส OTP ไม่ถูกต้อง' : 'Invalid OTP code.',
    'email_taken'     => $_auth_lang === 'th' ? 'อีเมลนี้ถูกใช้โดยบัญชีอื่นแล้ว' : 'This email is already used by another account.',
    'must_use_line'   => $_auth_lang === 'th' ? 'บัญชีใหม่ต้องลงทะเบียนผ่าน LINE ก่อน' : 'New accounts must register via LINE. Please login with LINE first.',
    'passkey_unknown' => $_auth_lang === 'th' ? 'ไม่พบ Passkey นี้ในระบบ' : 'Passkey not recognized.',
    'not_logged_in'   => $_auth_lang === 'th' ? 'กรุณาเข้าสู่ระบบก่อน' : 'Not logged in.',
    'otp_cooldown'    => $_auth_lang === 'th' ? 'ขอรหัสถี่เกินไป กรุณารอสักครู่แล้วลองใหม่' : 'Too many code requests. Please wait a moment and try again.',
    'recover_not_found' => $_auth_lang === 'th' ? 'ยืนยันอีเมลสำเร็จ แต่ไม่พบคอร์สภายใต้อีเมลนี้ ลองอีเมลอื่นที่อาจใช้ตอนซื้อดูได้เลย' : 'Email verified, but no purchases were found under this email. Try another email you may have used at checkout.',
    'merge_expired'   => $_auth_lang === 'th' ? 'คำขอรวมบัญชีหมดอายุ กรุณาเริ่มใหม่อีกครั้ง' : 'The merge request expired. Please start again.',
    'merge_failed'    => $_auth_lang === 'th' ? 'รวมบัญชีไม่สำเร็จ กรุณาติดต่อทีมงาน Dogology' : 'Account merge failed. Please contact Dogology support.',
    'merge_use_code'  => $_auth_lang === 'th' ? 'เพื่อความปลอดภัย กรุณากรอกรหัส 6 หลักจากอีเมล ในหน้าที่ขอรหัสไว้' : 'For security, please enter the 6-digit code from the email on the page where you requested it.',
];

// --- FORM HANDLERS ---
$_dl_nonce_valid = isset($_POST['_dl_nonce']) && wp_verify_nonce($_POST['_dl_nonce'], 'dl_auth_action');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = isset($_POST['action']) ? $_POST['action'] : '';

    // 1. Send OTP
    if ($action === 'send_otp') {
        if (!$_dl_nonce_valid) {
            if (isset($_POST['is_ajax']) && $_POST['is_ajax'] == '1') {
                echo json_encode(array('success' => false, 'message' => $_err['invalid_token']));
                exit;
            }
            $error = $_err['invalid_token'];
        } else {
        $email = sanitize_email($_POST['email']);
        if (is_email($email)) {
            $current_student = Dogology_Auth::get_current_student();

            // Guest OTP only works for existing students (verify_otp blocks
            // unknown emails anyway) — so for unknown emails we skip the send
            // entirely. Response stays identical to a real send so the form
            // can't be used to bomb arbitrary inboxes or probe which emails
            // have accounts.
            $_dl_db = new Dogology_Student_DB();
            $_dl_known = $current_student || $_dl_db->get_student_by_email($email);

            $_dl_sent = true;
            if ($_dl_known) {
                // Recover-context emails carry the code only (no magic link):
                // the link is useless cross-browser there and scanners can't
                // trigger anything without one.
                $_dl_include_link = !(isset($_POST['context']) && $_POST['context'] === 'recover');
                $_dl_sent = Dogology_Auth::send_otp($email, $current_student ? $current_student->id : 0, $_auth_lang, $_dl_include_link);
            }

            if ($_dl_sent === false) {
                // Rate limited
                if (isset($_POST['is_ajax']) && $_POST['is_ajax'] == '1') {
                    echo json_encode(array('success' => false, 'message' => $_err['otp_cooldown']));
                    exit;
                }
                $error = $_err['otp_cooldown'];
            } else {

            // Handle AJAX Request for Single Page Flow
            if (isset($_POST['is_ajax']) && $_POST['is_ajax'] == '1') {
                echo json_encode(array('success' => true, 'email' => $email));
                exit;
            }

            // Fallback for non-JS
            $redirect_step = isset($_GET['step']) && $_GET['step'] === 'onboarding' ? 'onboarding' : 'otp';
            wp_redirect(add_query_arg(array('step' => $redirect_step, 'email' => $email, 'sent' => 1)));
            exit;
            } // end rate-limit else
        } else {
            if (isset($_POST['is_ajax']) && $_POST['is_ajax'] == '1') {
                echo json_encode(array('success' => false, 'message' => $_err['invalid_email']));
                exit;
            }
            $error = $_err['invalid_email'];
        }
        } // end nonce else
    }

    // 2. Verify OTP
    if ($action === 'verify_otp' && $_dl_nonce_valid) {
        $email = sanitize_email($_POST['email']);
        $otp = sanitize_text_field($_POST['otp']);

        if (Dogology_Auth::verify_otp($email, $otp)) {
            $db = new Dogology_Student_DB();
            $current_student = Dogology_Auth::get_current_student();

            if ($current_student) {
                // Scenario: Onboarding (Logged In User verifying email)
                $existing = $db->get_student_by_email($email);
                $_dl_is_recover = isset($_POST['context']) && $_POST['context'] === 'recover';
                if ($existing && $existing->id != $current_student->id) {
                    // MERGE OFFER: ownership of the email is now proven via OTP,
                    // and it belongs to another account (typically the one holding
                    // the purchases). Offer to merge instead of dead-ending.
                    set_transient('dogology_merge_' . $current_student->id, array(
                        'target_id' => (int) $existing->id,
                        'email' => $email,
                    ), 10 * MINUTE_IN_SECONDS);
                    $merge_url = add_query_arg(array('step' => 'merge', 't' => time()), home_url('/student-login'));
                    if (isset($_POST['is_ajax']) && $_POST['is_ajax'] == '1') {
                        echo json_encode(array('success' => true, 'redirect' => $merge_url));
                        exit;
                    }
                    wp_redirect($merge_url);
                    exit;
                } elseif ($_dl_is_recover) {
                    // Recover flow: email verified, but no OTHER account holds it,
                    // so there is nothing to merge. Do NOT bind the email here —
                    // the user is hunting for purchases, not changing their email.
                    $msg = $_err['recover_not_found'];
                    if (isset($_POST['is_ajax']) && $_POST['is_ajax'] == '1') {
                        echo json_encode(array('success' => false, 'message' => $msg));
                        exit;
                    }
                    $error = $msg;
                    $step = 'recover';
                } else {
                    $db->update_student($current_student->id, array(
                        'email' => $email,
                        'email_verified_at' => current_time('mysql')
                    ));

                    if (isset($_POST['is_ajax']) && $_POST['is_ajax'] == '1') {
                        echo json_encode(array('success' => true, 'redirect' => add_query_arg('step', 'onboarding', home_url('/student-login'))));
                        exit;
                    }

                    // Redirect to onboarding to continue flow (with cache buster)
                    wp_redirect(add_query_arg(array('step' => 'onboarding', 't' => time()), home_url('/student-login')));
                    exit;
                }
            } else {
                // Scenario: Guest Login
                $student = $db->get_student_by_email($email);
                if (!$student) {
                    $msg = $_err['must_use_line'];
                    if (isset($_POST['is_ajax']) && $_POST['is_ajax'] == '1') {
                        echo json_encode(array('success' => false, 'message' => $msg));
                        exit;
                    }
                    // BLOCK NEW EMAIL SIGNUPS
                    $error = $msg;
                    $step = 'login';
                } else {
                    // Update verification (migration)
                    if (empty($student->email_verified_at)) {
                        $db->update_student($student->id, array('email_verified_at' => current_time('mysql')));
                    }
                    Dogology_Auth::login_student($student->id);

                    if (isset($_POST['is_ajax']) && $_POST['is_ajax'] == '1') {
                        echo json_encode(array('success' => true, 'redirect' => home_url('/student-login')));
                        exit;
                    }

                    // Redirect (with cache buster)
                    wp_redirect(add_query_arg('t', time(), home_url('/student-login')));
                    exit;
                }
            }
        } else {
            if (isset($_POST['is_ajax']) && $_POST['is_ajax'] == '1') {
                echo json_encode(array('success' => false, 'message' => $_err['invalid_otp']));
                exit;
            }
            $error = $_err['invalid_otp'];
            // If onboarding, stay on onboarding
            if (isset($_GET['step']) && $_GET['step'] === 'onboarding')
                $step = 'onboarding';
            else
                $step = 'otp';
        }
    }

    // 3. LIFF Login
    if ($action === 'liff_login') {
        $claimed_uid = isset($_POST['line_uid']) ? sanitize_text_field($_POST['line_uid']) : '';
        $line_uid    = $claimed_uid; // default: legacy behaviour (trust the client)
        $incoming_email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';

        // ── S2: server-side LINE identity verification (self-contained) ──────
        // The client posts liff.getIDToken(); verify it against LINE and only
        // trust the VERIFIED sub — never the client-supplied line_uid (which an
        // attacker can forge to take over any account). Pure learning-plugin
        // logic; no platform dependency. Mode is a learning option:
        //   off     → legacy (trust claimed line_uid) — emergency rollback
        //   shadow  → verify + record the outcome, still log in on the claimed
        //             uid (soak: confirm real logins would_accept before enforcing)
        //   enforce → reject unless the verified sub matches; trust ONLY the sub
        // No PII (uid / id_token / sub) is recorded — only the outcome counters.
        $dl_mode = get_option('dogology_learning_liff_verify', 'shadow');
        if ($dl_mode === 'shadow' || $dl_mode === 'enforce') {
            $dl_id_token = isset($_POST['id_token']) ? trim((string) $_POST['id_token']) : '';

            // LINE Login channel_id = the id_token audience. Derived from the
            // configured LIFF id ({channelId}-{suffix}) — same as the QR path.
            $dl_liff_id = get_option('dogology_learning_liff_id', '');
            $dl_channel = (is_string($dl_liff_id) && strpos($dl_liff_id, '-') !== false)
                ? explode('-', $dl_liff_id)[0] : '';

            $dl_verify = ($dl_id_token !== '' && $dl_channel !== '')
                ? Dogology_Auth::verify_line_id_token($dl_id_token, $dl_channel)
                : ['ok' => false, 'error' => 'absent'];

            if (!empty($dl_verify['ok']) && isset($dl_verify['sub'])) {
                $dl_outcome = ($dl_verify['sub'] === $claimed_uid)
                    ? 'would_accept' : 'would_reject_mismatch';
            } else {
                $dl_outcome = ($dl_id_token === '')
                    ? 'would_reject_absent' : 'would_reject_failed';
            }
            Dogology_Auth::record_liff_verify_outcome($dl_outcome);

            if ($dl_mode === 'enforce') {
                if ($dl_outcome !== 'would_accept') {
                    echo json_encode(array('success' => false, 'message' => $_err['invalid_token']));
                    exit;
                }
                $line_uid = (string) $dl_verify['sub']; // trust ONLY the verified identity
            }
        }
        // ─────────────────────────────────────────────────────────────────────

        $db = new Dogology_Student_DB();
        global $wpdb;
        $student = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dogology_users WHERE line_uid = %s", $line_uid));

        if (!$student) {
            // New User -> Create with NULL email to force verification
            $id = $db->create_student(array(
                'line_uid' => $line_uid,
                'email' => null, // Explicitly NULL
                'display_name' => sanitize_text_field($_POST['display_name']),
                'profile_picture' => esc_url_raw($_POST['picture_url'])
            ));

            if (is_wp_error($id)) {
                echo json_encode(array('success' => false, 'message' => $id->get_error_message()));
                exit;
            }
            $student = $db->get_student($id);

            // Prefill helper - store pending email in transient
            if ($incoming_email) {
                set_transient('dogology_prefill_email_' . $id, $incoming_email, 3600);
            }
        }

        if ($student) {
            Dogology_Auth::login_student($student->id);
            echo json_encode(array('success' => true, 'redirect' => Dogology_Auth::get_dashboard_url_with_token($student->id)));
            exit;
        }
    }

    // 4. Passkey Login
    // NOTE: this handler deliberately does NOT verify a nonce today. N1 (the nonce
    // guard) is being deferred and will ship together with H4 (full WebAuthn
    // server-side challenge rewrite) — the challenge itself replaces the nonce as
    // the CSRF control. Until then, this endpoint still looks up by credential_id
    // only. See audit plan + log.md for the H4 design sketch.
    if ($action === 'passkey_login') {
        $credential_id = sanitize_text_field($_POST['credential_id']);
        $db = new Dogology_Student_DB();
        $student = $db->get_student_by_passkey($credential_id);
        if ($student) {
            Dogology_Auth::login_student($student->id);
            echo json_encode(array('success' => true, 'redirect' => Dogology_Auth::get_dashboard_url_with_token($student->id)));
            exit;
        } else {
            echo json_encode(array('success' => false, 'message' => $_err['passkey_unknown']));
            exit;
        }
    }

    // 5. Skip Passkey (Onboarding)
    if ($action === 'skip_passkey' && $_dl_nonce_valid) {
        $current_student = Dogology_Auth::get_current_student();
        if ($current_student) {
            wp_redirect(Dogology_Auth::get_dashboard_url_with_token($current_student->id));
            exit;
        } else {
            wp_redirect(home_url('/student-login'));
            exit;
        }
    }

    // 6. Register Passkey (for Bridge Flow)
    if ($action === 'register_passkey') {
        if (!$_dl_nonce_valid) {
            echo json_encode(array('success' => false, 'message' => $_err['invalid_token']));
            exit;
        }
        $current_student = Dogology_Auth::get_current_student();
        if (!$current_student) {
            echo json_encode(array('success' => false, 'message' => $_err['not_logged_in']));
            exit;
        }
        $credential_id = sanitize_text_field($_POST['credential_id']);
        $db = new Dogology_Student_DB();

        // Dedup: reject if another student already claims this credential.
        // Without this, a leaked credential_id could be re-registered by an attacker.
        $already_taken = $db->get_student_by_passkey($credential_id);
        if ($already_taken && (int) $already_taken->id !== (int) $current_student->id) {
            echo json_encode(array('success' => false, 'message' => $_auth_lang === 'th' ? 'Passkey นี้ถูกใช้โดยบัญชีอื่นแล้ว' : 'This passkey is already registered to another account.'));
            exit;
        }

        $db->update_student($current_student->id, array('passkey_id' => $credential_id));
        echo json_encode(array('success' => true));
        exit;
    }

    // 7. Get Fresh Bridge URL (Ajax)
    if ($action === 'get_bridge_url') {
        if (!$_dl_nonce_valid) {
            echo json_encode(array('success' => false, 'message' => $_err['invalid_token']));
            exit;
        }
        $current_student = Dogology_Auth::get_current_student();
        if (!$current_student) {
            echo json_encode(array('success' => false, 'message' => $_err['not_logged_in']));
            exit;
        }
        $url = Dogology_Auth::get_passkey_bridge_url($current_student->id);
        echo json_encode(array('success' => true, 'url' => $url));
        exit;
    }

    // 8. Confirm Account Merge (current account absorbs into the email account)
    if ($action === 'confirm_merge' && $_dl_nonce_valid) {
        $current_student = Dogology_Auth::get_current_student();
        if (!$current_student) {
            $error = $_err['not_logged_in'];
        } else {
            $pending = get_transient('dogology_merge_' . $current_student->id);
            if (!$pending || empty($pending['target_id'])) {
                $error = $_err['merge_expired'];
            } else {
                $db = new Dogology_Student_DB();
                $survivor_id = (int) $pending['target_id'];
                $result = $db->merge_students($survivor_id, (int) $current_student->id);
                delete_transient('dogology_merge_' . $current_student->id);
                if (is_wp_error($result)) {
                    $error = $_err['merge_failed'];
                } else {
                    // The email just proved ownership via OTP — mark it verified.
                    $survivor = $db->get_student($survivor_id);
                    if ($survivor && empty($survivor->email_verified_at)) {
                        $db->update_student($survivor_id, array('email_verified_at' => current_time('mysql')));
                    }
                    Dogology_Auth::logout();
                    Dogology_Auth::login_student($survivor_id);
                    wp_redirect(Dogology_Auth::get_dashboard_url_with_token($survivor_id));
                    exit;
                }
            }
        }
    }

    // 9. Cancel Merge
    if ($action === 'cancel_merge' && $_dl_nonce_valid) {
        $current_student = Dogology_Auth::get_current_student();
        if ($current_student) {
            delete_transient('dogology_merge_' . $current_student->id);
        }
        wp_redirect(home_url('/my-courses'));
        exit;
    }
}

// 5a. Magic Login (GET) — scanner-proof interstitial.
// Email scanners (Outlook SafeLinks, corporate AV) prefetch GET links and were
// consuming the one-time OTP before the human ever clicked. The GET therefore
// has ZERO side effects: it renders a tiny auto-submitting page, and the real
// verification + login happen only on the POSTed confirmation (5b). Scanners
// don't execute JS or POST. Placed BEFORE the logged-in redirect so a
// logged-in initiator's click also flows through (needed for the merge offer).
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'magic_login') {
    $_ml_fields = array('email', 'otp', 'ts', 'uid', 'sig');
    $_ml_title = $_auth_lang === 'th' ? 'กำลังเข้าสู่ระบบ...' : 'Signing you in...';
    $_ml_btn = $_auth_lang === 'th' ? 'กดเพื่อเข้าสู่ระบบ' : 'Click to sign in';
    ?>
    <!DOCTYPE html>
    <html <?php language_attributes(); ?>>
    <head>
        <meta charset="<?php bloginfo('charset'); ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">
        <title><?php echo esc_html($_ml_title); ?></title>
        <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;600;700&display=swap" rel="stylesheet">
    </head>
    <body style="margin:0; background:#f8fafc; font-family:'Kanit',sans-serif; display:flex; align-items:center; justify-content:center; min-height:100vh;">
        <div style="background:#fff; border:1px solid #f0f0f1; border-radius:16px; padding:40px; max-width:400px; text-align:center; box-shadow:0 10px 40px rgba(0,0,0,0.08);">
            <div style="border:4px solid #f3f3f3; border-top:4px solid #00AB8E; border-radius:50%; width:30px; height:30px; animation:mlspin 1s linear infinite; margin:0 auto 16px;"></div>
            <p style="color:#666; margin:0 0 20px;"><?php echo esc_html($_ml_title); ?></p>
            <form id="ml-confirm" method="post" action="<?php echo esc_url(home_url('/student-login/')); ?>">
                <input type="hidden" name="action" value="magic_login_confirm">
                <?php foreach ($_ml_fields as $_f): ?>
                    <input type="hidden" name="<?php echo esc_attr($_f); ?>" value="<?php echo esc_attr(isset($_GET[$_f]) ? wp_unslash($_GET[$_f]) : ''); ?>">
                <?php endforeach; ?>
                <button type="submit" style="background:#00AB8E; color:#fff; border:none; border-radius:12px; padding:14px 32px; font-weight:700; font-size:16px; cursor:pointer; font-family:inherit;"><?php echo esc_html($_ml_btn); ?></button>
            </form>
        </div>
        <style>@keyframes mlspin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }</style>
        <script>document.getElementById('ml-confirm').submit();</script>
    </body>
    </html>
    <?php
    exit;
}

// 5b. Magic Login Confirmation (POST, from the interstitial above)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'magic_login_confirm') {
    $email = isset($_POST['email']) ? wp_unslash($_POST['email']) : '';
    $otp = isset($_POST['otp']) ? wp_unslash($_POST['otp']) : '';
    $ts = isset($_POST['ts']) ? intval($_POST['ts']) : 0;
    $uid = isset($_POST['uid']) ? intval($_POST['uid']) : 0;
    $sig = isset($_POST['sig']) ? wp_unslash($_POST['sig']) : '';

    // Verify Signature (Robust check including UID)
    $check_sig_v2 = hash_hmac('sha256', $email . '|' . $otp . '|' . $ts . '|' . $uid, DOGOLOGY_AUTH_SALT);
    // Fallback for old links (without UID) - user experience continuity
    $check_sig_v1 = hash_hmac('sha256', $email . '|' . $otp . '|' . $ts, DOGOLOGY_AUTH_SALT);

    $valid_sig = false;
    if (hash_equals($check_sig_v2, $sig)) {
        $valid_sig = true;
    } elseif ($uid === 0 && hash_equals($check_sig_v1, $sig)) {
        // Allow old links if UID is 0 (guest/old format)
        $valid_sig = true;
    }

    if ($ts > time() && $valid_sig) {
        // Valid Link - Verify OTP
        if (Dogology_Auth::verify_otp($email, $otp)) {
            $db = new Dogology_Student_DB();

            // Determine Target User
            // If UID is in link (signed), that is the authoritative user
            $student_to_verify = ($uid > 0) ? $db->get_student($uid) : null;

            // Fallback to currently logged in user if link was generic
            if (!$student_to_verify) {
                $current_student = Dogology_Auth::get_current_student();
                if ($current_student)
                    $student_to_verify = $current_student;
            }

            // Scenario A: Identified User (e.g. Onboarding)
            if ($student_to_verify) {
                // Check if email used by another account
                $existing = $db->get_student_by_email($email);
                if ($existing && $existing->id != $student_to_verify->id) {
                    // MERGE OFFER (magic-link path) — but ONLY if this browser is
                    // already logged in as the account named in the link, i.e. the
                    // person who initiated the OTP clicked their own email. Without
                    // this check, an attacker could trigger an OTP email to a victim
                    // (uid in link = attacker), and the victim clicking it would be
                    // logged into the attacker's account and coaxed into merging —
                    // handing the attacker LINE access to the victim's courses.
                    $_dl_session_student = Dogology_Auth::get_current_student();
                    if ($_dl_session_student && (int) $_dl_session_student->id === (int) $student_to_verify->id) {
                        set_transient('dogology_merge_' . $student_to_verify->id, array(
                            'target_id' => (int) $existing->id,
                            'email' => $email,
                        ), 10 * MINUTE_IN_SECONDS);
                        wp_redirect(add_query_arg(array('step' => 'merge', 't' => time()), home_url('/student-login')));
                        exit;
                    }
                    // Different browser than the one that requested the code (e.g.
                    // requested inside the LINE app, link opened in Safari). Tell
                    // the user to type the code where they asked for it.
                    $error = $_err['merge_use_code'];
                } else {
                    // Update User
                    $db->update_student($student_to_verify->id, array(
                        'email' => $email,
                        'email_verified_at' => current_time('mysql')
                    ));

                    // LOGIN (Force login to match the verified user)
                    Dogology_Auth::login_student($student_to_verify->id);

                    // Redirect to onboarding to continue flow (with cache buster)
                    wp_redirect(add_query_arg(array('step' => 'onboarding', 't' => time()), home_url('/student-login')));
                    exit;
                }
            } else {
                // Scenario B: Guest User (Lookup by email)
                $student = $db->get_student_by_email($email);

                if ($student) {
                    if (empty($student->email_verified_at)) {
                        $db->update_student($student->id, array('email_verified_at' => current_time('mysql')));
                    }
                    Dogology_Auth::login_student($student->id);
                    // Redirect with Token Handoff
                    wp_redirect(Dogology_Auth::get_dashboard_url_with_token($student->id));
                    exit;
                } else {
                    $error = $_err['must_use_line'];
                }
            }
        } else {
            $error = $_auth_lang === 'th' ? 'ลิงก์ไม่ถูกต้องหรือหมดอายุ' : 'Invalid or expired link code.';
        }
    } else {
        $error = $_auth_lang === 'th' ? 'ลิงก์ไม่ถูกต้องหรือหมดอายุ' : 'Invalid or expired link.';
    }
}

// If already logged in (AND NOT just logged out) (AND NOT passkey bridge)
if (!$just_logged_out && !$trigger_passkey_js && ($current_student = Dogology_Auth::get_current_student())) {

    // SOFT GATE: Allow all logged-in users to access dashboard.
    // Email verification warning will be shown via a banner on dashboard.php.

    $is_onboarding = isset($_GET['step']) && in_array($_GET['step'], array('onboarding', 'otp', 'recover', 'merge'), true);

    // If not explicitly on onboarding page, redirect to dashboard
    if (!$is_onboarding) {
        wp_redirect(home_url('/my-courses'));
        exit;
    }

    // Special Case: LIFF Browser + Passkey Setup
    // LIFF cannot do WebAuthn, so skip passkey-related steps (but allow email verify).
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $is_liff = (stripos($ua, 'Line') !== false || stripos($ua, 'LIFF') !== false);
    $onboarding_step = isset($_GET['onboard_step']) ? $_GET['onboard_step'] : '';


    // Note: Email verification continues normally in LIFF
}

$liff_id_setting = get_option('dogology_learning_liff_id', '');
$channel_secret = get_option('dogology_learning_channel_secret', '');

// ** USER AGENT & REDIRECT DETECTION **
$is_line_browser = false;
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
if (stripos($ua, 'Line') !== false || stripos($ua, 'LINE') !== false) {
    $is_line_browser = true;
}

// ** EXTRACT CHANNEL ID FROM LIFF ID **
// LIFF ID format: {ChannelID}-{UUID}
$channel_id = '';
if (!empty($liff_id_setting) && strpos($liff_id_setting, '-') !== false) {
    $parts = explode('-', $liff_id_setting);
    $channel_id = $parts[0];
}

// ** BACKEND LOGIN CALLBACK (Desktop/Manual) **
if (isset($_GET['code']) && isset($_GET['state']) && strpos($_GET['state'], 'dl_qr_') === 0) {
    // This is our manual QR login returning
    if (empty($channel_secret)) {
        $error = $_auth_lang === 'th' ? 'ข้อผิดพลาดระบบ: ไม่ได้ตั้งค่า Channel Secret' : 'System Error: Channel Secret not configured.';
    } elseif (empty($channel_id)) {
        $error = $_auth_lang === 'th' ? 'ข้อผิดพลาดระบบ: รูปแบบ LIFF ID ไม่ถูกต้อง' : 'System Error: Invalid LIFF ID format.';
    } else {
        // Exchange Code
        $redirect_uri = home_url('/student-login');
        if (strpos($redirect_uri, '?') !== false)
            $redirect_uri = explode('?', $redirect_uri)[0]; // Clean URI

        $profile = Dogology_Auth::verify_line_login_code(
            $_GET['code'],
            $redirect_uri,
            $channel_id, // Use Channel ID not LIFF ID
            $channel_secret
        );

        if (is_wp_error($profile)) {
            $error = ($_auth_lang === 'th' ? 'เข้าสู่ระบบไม่สำเร็จ: ' : 'Login Failed: ') . $profile->get_error_message();
        } else {
            // Success! Register/Login User
            $db = new Dogology_Student_DB();
            global $wpdb;
            $student = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dogology_users WHERE line_uid = %s", $profile['line_uid']));

            if (!$student) {
                // Register
                $id = $db->create_student(array(
                    'line_uid' => $profile['line_uid'],
                    'email' => null,
                    'display_name' => $profile['display_name'],
                    'profile_picture' => $profile['picture_url']
                ));
                // Store pending email if available
                if (!empty($profile['email'])) {
                    set_transient('dogology_prefill_email_' . $id, $profile['email'], 3600);
                }
                $student = $db->get_student($id);
            }

            if ($student) {
                Dogology_Auth::login_student($student->id);
                wp_redirect(Dogology_Auth::get_dashboard_url_with_token($student->id));
                exit;
            }
        }
    }
}

// Initial Visibility Logic
$is_returning_from_line = isset($_GET['code']) || isset($_GET['liffClientId']) || isset($_GET['liff.state']);

// EXCEPTION: If we are in 'onboarding' or 'otp' steps, NEVER show the generic "Logging in" loading screen.
// We want the user to see the verification UI.
$is_verification_step = isset($_GET['step']) && in_array($_GET['step'], array('onboarding', 'otp', 'recover', 'merge'), true);

// Show Loading if: LINE Browser OR Returning from LINE Login (but NOT if we just handled a manual QR code which failed)
// AND NOT if we are explicitly acting on a verification step
$show_loading = (!$is_verification_step) && (($is_line_browser && !$just_logged_out) || ($is_returning_from_line && !isset($error)));

// ** GENERATE DESKTOP LOGIN URL **
$desktop_login_url = '#';

if (!$is_line_browser && $channel_id) {
    $redirect_uri = home_url('/student-login');
    if (strpos($redirect_uri, '?') !== false)
        $redirect_uri = explode('?', $redirect_uri)[0];

    $state = 'dl_qr_' . wp_create_nonce('line_login_qr');
    $desktop_login_url = 'https://access.line.me/oauth2/v2.1/authorize?response_type=code' .
        '&client_id=' . $channel_id .
        '&redirect_uri=' . urlencode($redirect_uri) .
        '&state=' . $state .
        '&scope=profile%20openid%20email' .
        '&initial_amr_display=lineqr';
}

// --- LANGUAGE DETECTION (cookie > browser > default) ---
$current_lang = 'en';
if (isset($_COOKIE['dl_lang']) && in_array($_COOKIE['dl_lang'], ['th', 'en'])) {
    $current_lang = $_COOKIE['dl_lang'];
} else {
    $browser_lang = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2) : 'en';
    $current_lang = ($browser_lang === 'th') ? 'th' : 'en';
}
if (isset($_GET['lang']) && in_array($_GET['lang'], ['th', 'en'])) {
    $current_lang = $_GET['lang'];
    setcookie('dl_lang', $current_lang, [
        'expires' => time() + 3600 * 24 * 30,
        'path' => '/',
        'secure' => is_ssl(),
        'httponly' => true, // Only PHP reads this cookie (verified via grep); JS never touches document.cookie for dl_lang
        'samesite' => 'Lax',
    ]);
}

$auth_trans = [
    'th' => [
        'page_title' => 'เข้าสู่ระบบ - Dogology Experience',
        'brand_title' => 'Dogology Experience',
        'brand_subtitle_login' => 'เข้าสู่ระบบเพื่อเริ่มเรียน',
        'brand_subtitle_otp' => 'กรอกรหัสยืนยัน',
        'btn_line' => 'เข้าสู่ระบบด้วย LINE',
        'btn_passkey' => 'เข้าสู่ระบบด้วย Face ID / Touch ID',
        'or' => 'หรือ',
        'btn_email' => 'เข้าสู่ระบบด้วยอีเมล',
        'label_email' => 'อีเมล',
        'btn_send_otp' => 'ส่งรหัส OTP',
        'btn_back_method' => '← เลือกวิธีอื่น',
        'forgot_email' => 'ลืมอีเมล?',
        'rescue_title' => 'กู้คืนบัญชี',
        'rescue_desc' => 'สแกน QR ด้วยโทรศัพท์เพื่อค้นหาบัญชีผ่าน LINE',
        'btn_cancel' => 'ยกเลิก',
        'otp_sent_to' => 'รหัสถูกส่งไปที่',
        'label_otp' => 'กรอกรหัส 6 หลัก',
        'btn_verify' => 'ยืนยัน & เข้าสู่ระบบ',
        'btn_back_login' => '← กลับไปหน้าเข้าสู่ระบบ',
        'onboard_title' => 'เกือบเสร็จแล้ว!',
        'onboard_desc' => 'กรุณายืนยันอีเมลเพื่อรักษาความปลอดภัยของบัญชี',
        'label_your_email' => 'อีเมลของคุณ',
        'btn_change' => 'เปลี่ยน',
        'btn_send_code' => 'ส่งรหัสยืนยัน',
        'onboard_otp_title' => 'ตรวจสอบกล่องจดหมาย',
        'onboard_otp_sent' => 'เราส่งรหัสไปที่',
        'btn_verify_email' => 'ยืนยันอีเมล',
        'btn_change_email' => '← เปลี่ยนอีเมล',
        'passkey_title' => 'เปิดใช้ Face ID?',
        'passkey_desc' => 'เข้าสู่ระบบเร็วขึ้นโดยไม่ต้องรอรหัส OTP',
        'btn_enable_faceid' => 'เปิดใช้ Face ID / Touch ID',
        'btn_skip' => 'ข้ามไปก่อน',
        'liff_loading' => 'กำลังเข้าสู่ระบบด้วย LINE...',
        'passkey_bridge_title' => 'กำลังตั้งค่า Face ID / Touch ID',
        'passkey_bridge_desc' => 'กรุณายืนยันตัวตนเมื่อระบบแจ้ง...',
        'passkey_bridge_fallback' => 'หากไม่มีอะไรเกิดขึ้น',
        'passkey_bridge_link' => 'ไปที่แดชบอร์ด',
        'error_nonce' => 'Token ไม่ถูกต้อง กรุณารีเฟรชหน้า',
        'recover_title' => 'ค้นหาคอร์สที่ซื้อไว้',
        'recover_desc' => 'กรอกอีเมลที่ใช้ตอนซื้อคอร์ส เราจะส่งรหัสยืนยันไปให้ทางอีเมล',
        'merge_title' => 'พบบัญชีของคุณแล้ว!',
        'merge_found_email' => 'บัญชีภายใต้อีเมล',
        'merge_courses_count' => 'มีคอร์สอยู่ %d รายการ',
        'merge_courses_zero' => 'มีบัญชีอยู่ในระบบ แต่ยังไม่มีคอร์ส',
        'merge_explain' => 'หลังจากรวมบัญชีแล้ว จะเข้าสู่ระบบด้วย LINE หรืออีเมลนี้ก็ได้ และเห็นคอร์สทั้งหมดในที่เดียว',
        'btn_merge' => 'รวมบัญชี & ไปที่คอร์สของฉัน',
        'btn_merge_cancel' => 'ไม่ใช่ตอนนี้',
        'iab_notice' => 'กำลังเปิดผ่านแอป Facebook/Instagram อยู่ แนะนำให้กดปุ่ม ⋯ มุมขวาบน แล้วเลือก "เปิดในเบราว์เซอร์" จะได้เข้าเรียนครั้งหน้าโดยไม่ต้องล็อกอินใหม่',
        'iab_copy' => 'คัดลอกลิงก์ไปเปิดใน Chrome / Safari',
        'iab_copied' => 'คัดลอกแล้ว! เปิดเบราว์เซอร์แล้ววางลิงก์',
    ],
    'en' => [
        'page_title' => 'Login - Dogology Experience',
        'brand_title' => 'Dogology Experience',
        'brand_subtitle_login' => 'Sign in to start learning',
        'brand_subtitle_otp' => 'Enter Confirmation Code',
        'btn_line' => 'Login with LINE',
        'btn_passkey' => 'Sign in with Face ID / Touch ID',
        'or' => 'Or',
        'btn_email' => 'Sign in with Email',
        'label_email' => 'Email Address',
        'btn_send_otp' => 'Send OTP Code',
        'btn_back_method' => '← Use another method',
        'forgot_email' => 'Forgot your email?',
        'rescue_title' => 'Account Recovery',
        'rescue_desc' => 'Scan with your phone to find your account via LINE.',
        'btn_cancel' => 'Cancel',
        'otp_sent_to' => 'Code sent to',
        'label_otp' => 'Enter 6-Digit Code',
        'btn_verify' => 'Verify & Login',
        'btn_back_login' => '← Back to Login',
        'onboard_title' => 'Almost Done!',
        'onboard_desc' => 'Please verify your email address to secure your account.',
        'label_your_email' => 'Your Email Address',
        'btn_change' => 'Change',
        'btn_send_code' => 'Send Verification Code',
        'onboard_otp_title' => 'Check Your Inbox',
        'onboard_otp_sent' => 'We sent a code to',
        'btn_verify_email' => 'Verify Email',
        'btn_change_email' => '← Change Email',
        'passkey_title' => 'Enable Face ID?',
        'passkey_desc' => 'Login faster next time without waiting for OTP codes.',
        'btn_enable_faceid' => 'Enable Face ID / Touch ID',
        'btn_skip' => 'Skip for now',
        'liff_loading' => 'Logging in with LINE...',
        'passkey_bridge_title' => 'Setting up Face ID / Touch ID',
        'passkey_bridge_desc' => 'Please authenticate when prompted...',
        'passkey_bridge_fallback' => 'If nothing happens,',
        'passkey_bridge_link' => 'go to dashboard',
        'error_nonce' => 'Invalid security token. Please refresh.',
        'recover_title' => 'Find Your Purchases',
        'recover_desc' => 'Enter the email you used at checkout. We will send you a verification code.',
        'merge_title' => 'We Found Your Account!',
        'merge_found_email' => 'The account under',
        'merge_courses_count' => 'has %d course(s)',
        'merge_courses_zero' => 'exists, but has no courses yet',
        'merge_explain' => 'After merging, you can sign in with LINE or this email and see all your courses in one place.',
        'btn_merge' => 'Merge & Go to My Courses',
        'btn_merge_cancel' => 'Not now',
        'iab_notice' => 'You\'re inside the Facebook/Instagram app. Tap ⋯ (top right) → "Open in browser" so you stay logged in next time.',
        'iab_copy' => 'Copy link to open in Chrome / Safari',
        'iab_copied' => 'Copied! Open your browser and paste.',
    ]
];
$at = $auth_trans[$current_lang];

// FB/IG in-app browser escape strip (2026-07-02). Sessions created inside
// Meta's IAB live only there — next visit from the real browser isn't logged
// in, and passkeys/WebAuthn don't work in the IAB at all. We can't force an
// exit (openExternalBrowser=1 is LINE-only; Meta ignores everything), so we
// instruct + offer copy-link. LINE IAB excluded: LIFF auto-login is the happy
// path there. Same parse_user_agent() the player strip uses.
$_dl_auth_parsed = Dogology_Helpers::parse_user_agent($_SERVER['HTTP_USER_AGENT'] ?? '');
$_dl_show_iab_strip = in_array($_dl_auth_parsed['label'], array('Facebook in-app', 'Instagram in-app'), true);
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>

<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?php echo esc_html($at['page_title']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;600;700&display=swap" rel="stylesheet">
</head>

<body style="margin:0; background:#f8fafc; padding-top: 0 !important;">
    <div id="dl-toast" style="display:none; position:fixed; top:20px; left:50%; transform:translateX(-50%); z-index:9999; padding:12px 24px; border-radius:12px; font-family:'Kanit',sans-serif; font-size:14px; font-weight:600; box-shadow:0 4px 20px rgba(0,0,0,0.15); transition:opacity 0.3s;"></div>
    <script>
    function dlToast(msg, type) {
        var t = document.getElementById('dl-toast');
        t.innerText = msg;
        t.style.background = type === 'error' ? '#ef4444' : '#00AB8E';
        t.style.color = '#fff';
        t.style.display = 'block';
        t.style.opacity = '1';
        clearTimeout(t._tid);
        t._tid = setTimeout(function(){ t.style.opacity='0'; setTimeout(function(){ t.style.display='none'; },300); }, 4000);
    }
    </script>
    <style>
        html {
            margin-top: 0 !important;
        }

        html body {
            padding-top: 0 !important;
        }
    </style>

    <!-- INLINED CSS -->
    <style>
        .dl-login-wrap {
            background: #f8fafc;
            min-height: 100vh;
            padding: max(0px, env(safe-area-inset-top)) 20px 40px;
            display: flex;
            justify-content: center;
            align-items: center;
            flex-direction: column;
            font-family: 'Kanit', sans-serif;
        }

        .dl-login-card {
            background: #fff;
            width: 100%;
            max-width: 440px;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
            border: 1px solid #f0f0f1;
            padding: 40px;
            position: relative;
            overflow: hidden;
            box-sizing: border-box;
        }

        .dl-login-brand {
            text-align: center;
            margin-bottom: 30px;
        }

        .dl-brand-icon {
            width: 64px;
            height: 64px;
            background: #00AB8E;
            color: #fff;
            border-radius: 16px;
            margin: 0 auto 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            font-weight: bold;
            box-shadow: 0 4px 10px rgba(0, 171, 142, 0.3);
        }

        .dl-brand-title {
            font-size: 24px;
            font-weight: 700;
            color: #333;
            margin: 0;
        }

        .dl-brand-subtitle {
            font-size: 14px;
            color: #888;
            margin-top: 4px;
        }

        .dl-btn-social {
            width: 100%;
            padding: 14px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 16px;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            transition: all 0.2s;
            margin-bottom: 12px;
            text-decoration: none;
            box-sizing: border-box;
            white-space: nowrap;
            /* FORCE ONE LINE */
        }

        @media (max-width: 380px) {
            .dl-btn-social {
                font-size: 13px;
                padding: 12px 10px;
                gap: 8px;
            }
        }

        .dl-btn-line {
            background-color: #06C755;
            color: #fff;
            box-shadow: 0 4px 6px rgba(6, 199, 85, 0.2);
        }

        .dl-btn-passkey {
            background-color: #fff;
            color: #333;
            border: 2px solid #f0f0f1;
        }

        .dl-divider {
            position: relative;
            text-align: center;
            margin: 24px 0;
        }

        .dl-divider::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            width: 100%;
            height: 1px;
            background: #e5e7eb;
            z-index: 1;
        }

        .dl-divider span {
            position: relative;
            z-index: 2;
            background: #fff;
            padding: 0 12px;
            color: #9ca3af;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .dl-form-group {
            margin-bottom: 16px;
        }

        .dl-label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: #6b7280;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .dl-input {
            width: 100%;
            padding: 12px 16px;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            font-size: 16px;
            color: #333;
            transition: border-color 0.2s;
            box-sizing: border-box;
        }

        .dl-btn-primary {
            width: 100%;
            padding: 14px;
            background: #0076BA;
            color: #fff;
            border: none;
            border-radius: 12px;
            font-weight: 700;
            font-size: 16px;
            cursor: pointer;
            display: block;
            box-sizing: border-box;
        }

        .dl-text-center {
            text-align: center;
        }

        .dl-mb-4 {
            margin-bottom: 1rem;
        }
    </style>

    <div class="dl-login-wrap">
        <div class="dl-login-card">

            <?php if ($_dl_show_iab_strip): ?>
            <!-- FB/IG in-app browser: instruct escape to real browser (can't be forced). -->
            <div id="dl-iab-strip"
                style="display:flex; flex-direction:column; gap:8px; background:#FEF3C7; color:#92400E; border:1px solid #FCD34D; border-radius:12px; padding:12px 14px; font-size:13px; line-height:1.5; margin:-10px -10px 20px;">
                <span>💡 <?php echo esc_html($at['iab_notice']); ?></span>
                <button type="button" id="dl-iab-copy" data-url="<?php echo esc_attr(home_url('/my-courses')); ?>"
                    style="align-self:flex-start; background:none; border:none; padding:0; color:#92400E; font-weight:700; text-decoration:underline; cursor:pointer; font-size:13px; font-family:inherit;">
                    📋 <?php echo esc_html($at['iab_copy']); ?>
                </button>
            </div>
            <script>
                (function () {
                    var btn = document.getElementById('dl-iab-copy');
                    if (!btn) return;
                    btn.addEventListener('click', function () {
                        var url = btn.getAttribute('data-url');
                        var done = function () { btn.textContent = '✅ <?php echo esc_js($at['iab_copied']); ?>'; };
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            navigator.clipboard.writeText(url).then(done).catch(function () { window.prompt('Copy:', url); });
                        } else { window.prompt('Copy:', url); }
                    });
                })();
            </script>
            <?php endif; ?>

            <!-- Brand -->
            <div class="dl-login-brand">
                <?php
                $logo_url = get_option('dl_logo_url', '');
                if (empty($logo_url)) {
                    $logo_url = plugin_dir_url(dirname(__DIR__) . '/dogology-learning.php') . 'assets/dogology-logo.png';
                }
                ?>
                <img src="<?php echo esc_url($logo_url); ?>" alt="Dogology"
                    style="width: 80px; height: auto; margin-bottom: 1rem;">
                <h2 class="dl-brand-title"><?php echo esc_html($at['brand_title']); ?></h2>
                <p class="dl-brand-subtitle">
                    <?php echo $step === 'otp' ? esc_html($at['brand_subtitle_otp']) : esc_html($at['brand_subtitle_login']); ?>
                </p>
                <!-- Language Switcher -->
                <div style="margin-top: 12px; display: flex; justify-content: center; gap: 4px; background: #f1f5f9; border-radius: 20px; padding: 3px; width: fit-content; margin-left: auto; margin-right: auto;">
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['lang' => 'th'])); ?>"
                        style="padding: 4px 14px; border-radius: 16px; font-size: 12px; font-weight: 700; text-decoration: none; transition: all 0.2s; <?php echo $current_lang === 'th' ? 'background:#fff; color:#00AB8E; box-shadow:0 1px 3px rgba(0,0,0,0.1);' : 'color:#94a3b8;'; ?>">TH</a>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['lang' => 'en'])); ?>"
                        style="padding: 4px 14px; border-radius: 16px; font-size: 12px; font-weight: 700; text-decoration: none; transition: all 0.2s; <?php echo $current_lang === 'en' ? 'background:#fff; color:#00AB8E; box-shadow:0 1px 3px rgba(0,0,0,0.1);' : 'color:#94a3b8;'; ?>">EN</a>
                </div>
            </div>

            <?php if (!empty($error)): ?>
                <div style="background:#fef2f2; border:1px solid #fecaca; color:#dc2626; padding:10px 16px; border-radius:10px; font-size:13px; font-weight:600; margin-bottom:16px; text-align:center;">
                    <?php echo esc_html($error); ?>
                </div>
            <?php endif; ?>

            <!-- LIFF Loading -->
            <!-- Logic: If User Agent is LINE AND not just logged out, we show this. -->
            <div id="liff-loading"
                style="<?php echo ($show_loading && !$trigger_passkey_js) ? 'display:block;' : 'display:none;'; ?> text-align:center;">
                <div class="spinner"
                    style="border:4px solid #f3f3f3; border-top:4px solid #00AB8E; border-radius:50%; width:30px; height:30px; animation:spin 1s linear infinite; margin:0 auto 10px;">
                </div>
                <p style="color:#888;"><?php echo esc_html($at['liff_loading']); ?></p>
            </div>

            <!-- Passkey Bridge Loading -->
            <?php if ($trigger_passkey_js): ?>
                <div id="passkey-bridge-loading" style="text-align:center; padding: 40px 20px;">
                    <div style="font-size: 48px; margin-bottom: 16px;">🔐</div>
                    <h3 style="color:#333; margin-bottom: 8px;"><?php echo esc_html($at['passkey_bridge_title']); ?></h3>
                    <p style="color:#888; margin-bottom: 20px;"><?php echo esc_html($at['passkey_bridge_desc']); ?></p>
                    <div class="spinner"
                        style="border:4px solid #f3f3f3; border-top:4px solid #00AB8E; border-radius:50%; width:30px; height:30px; animation:spin 1s linear infinite; margin:0 auto;">
                    </div>
                    <p style="color:#aaa; font-size: 12px; margin-top: 20px;">
                        <?php echo esc_html($at['passkey_bridge_fallback']); ?> <a href="<?php echo home_url('/my-courses'); ?>" style="color:#00AB8E;"><?php echo esc_html($at['passkey_bridge_link']); ?></a>
                    </p>
                </div>
            <?php endif; ?>
            <style>
                @keyframes spin {
                    0% {
                        transform: rotate(0deg);
                    }

                    100% {
                        transform: rotate(360deg);
                    }
                }
            </style>


            <!-- Forms -->
            <!-- Logic: If User Agent is LINE AND not just logged out, hide. Else show. -->
            <div id="login-forms"
                style="<?php echo ($show_loading || $trigger_passkey_js) ? 'display:none;' : 'display:block;'; ?>">
                <?php if ($step === 'login'): ?>
                    <!-- Login Buttons -->
                    <button id="btn-line-login" class="dl-btn-social dl-btn-line">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path
                                d="M22 10.3C22 5.5 17.5 1.5 11.9 1.5C6.4 1.5 1.9 5.5 1.9 10.3C1.9 14.1 4.3 17.3 8 18.5C8.3 18.6 8.5 18.7 8.6 18.9C8.6 19 8.6 19.3 8.5 19.6L7.9 21.6C7.9 21.6 7.7 22.3 8.4 22.4C9 22.4 12.5 20 15 18.3C19.2 16.9 22 13.9 22 10.3Z" />
                        </svg>
                        <span><?php echo esc_html($at['btn_line']); ?></span>
                    </button>
                    <button id="btn-passkey" class="dl-btn-social dl-btn-passkey"><span>🔑</span><span><?php echo esc_html($at['btn_passkey']); ?></span></button>

                    <div id="email-login-toggle-wrap">
                        <div class="dl-divider"><span><?php echo esc_html($at['or']); ?></span></div>
                        <button type="button" id="btn-show-email" class="dl-btn-social"
                            style="background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;">
                            <span>📧</span><span><?php echo esc_html($at['btn_email']); ?></span>
                        </button>
                    </div>

                    <div id="email-login-form"
                        style="display: none; margin-top: 24px; border-top: 1px solid #f1f5f9; padding-top: 24px;">
                        <form method="post" onsubmit="var b=this.querySelector('button[type=submit]'); b.disabled=true; b.innerText='...';">
                            <input type="hidden" name="action" value="send_otp">
                            <input type="hidden" name="_dl_nonce" value="<?php echo wp_create_nonce('dl_auth_action'); ?>">
                            <div class="dl-form-group">
                                <label class="dl-label"><?php echo esc_html($at['label_email']); ?></label>
                                <input type="email" name="email" class="dl-input" placeholder="example@email.com" required>
                            </div>
                            <button type="submit" class="dl-btn-primary"><?php echo esc_html($at['btn_send_otp']); ?></button>
                        </form>
                        <div style="margin-top: 16px; text-align: center;">
                            <button type="button" id="btn-hide-email"
                                style="background:none; border:none; color:#94a3b8; font-size:13px; font-weight:600; cursor:pointer;"><?php echo esc_html($at['btn_back_method']); ?></button>
                        </div>
                    </div>

                    <!-- Desktop Rescue Flow -->
                    <div id="rescue-flow-trigger" style="margin-top: 16px; text-align: center;">
                        <button type="button" id="btn-show-rescue"
                            style="background:none; border:none; color:#00AB8E; font-size:12px; font-weight:500; cursor:pointer; text-decoration: underline;">
                            <?php echo esc_html($at['forgot_email']); ?>
                        </button>
                    </div>

                    <!-- Rescue QR Modal (Hidden by default) -->
                    <div id="rescue-qr-modal"
                        style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.6); z-index:9999; align-items:center; justify-content:center;">
                        <div
                            style="background:white; border-radius:16px; padding:24px; max-width:320px; text-align:center; font-family:'Kanit',sans-serif;">
                            <h3 style="margin:0 0 8px; color:#333; font-size:18px;"><?php echo esc_html($at['rescue_title']); ?></h3>
                            <p style="color:#666; font-size:13px; margin:0 0 16px;"><?php echo esc_html($at['rescue_desc']); ?></p>
                            <div id="rescue-qr-code"
                                style="width:200px; height:200px; margin:0 auto 16px; background:#f0f0f0; display:flex; align-items:center; justify-content:center; border-radius:8px;">
                                <!-- QR will be generated here -->
                                <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?php echo urlencode('https://liff.line.me/' . $liff_id_setting . '?action=rescue_login'); ?>"
                                    alt="Rescue QR" style="width:180px; height:180px;">
                            </div>
                            <button type="button" id="btn-close-rescue"
                                style="background:#e2e8f0; color:#475569; border:none; padding:10px 24px; border-radius:8px; font-weight:600; cursor:pointer;">
                                <?php echo esc_html($at['btn_cancel']); ?>
                            </button>
                        </div>
                    </div>
                <?php elseif ($step === 'otp'): ?>
                    <form method="post" onsubmit="var b=this.querySelector('button[type=submit]'); b.disabled=true; b.innerText='...';">
                        <input type="hidden" name="action" value="verify_otp">
                        <input type="hidden" name="_dl_nonce" value="<?php echo wp_create_nonce('dl_auth_action'); ?>">
                        <input type="hidden" name="email" value="<?php echo esc_attr($email_input); ?>">
                        <div class="dl-text-center dl-mb-4">
                            <p style="color:#666; font-size:14px; margin-bottom: 5px;"><?php echo esc_html($at['otp_sent_to']); ?>
                                <strong><?php echo esc_html($email_input); ?></strong>
                            </p>
                        </div>
                        <div class="dl-form-group"><label class="dl-label"><?php echo esc_html($at['label_otp']); ?></label><input type="text"
                                name="otp" class="dl-input" placeholder="123456" maxlength="6" inputmode="numeric"
                                autocomplete="one-time-code"
                                oninput="this.value=this.value.replace(/[^0-9]/g,''); if(this.value.length===6){this.closest('form').querySelector('button[type=submit]').click();}"
                                style="text-align:center; letter-spacing:4px; font-size:24px;" required autofocus></div>
                        <button type="submit" class="dl-btn-primary"><?php echo esc_html($at['btn_verify']); ?></button>
                        <div style="margin-top: 16px; text-align: center;">
                            <span id="otp-resend-timer" style="color:#94a3b8; font-size:12px;"></span>
                            <a id="otp-resend-link" href="?step=login&email=<?php echo esc_attr($email_input); ?>"
                                onclick="var f=document.createElement('form');f.method='post';f.innerHTML='<input name=action value=send_otp><input name=email value=<?php echo esc_attr($email_input); ?>><input name=_dl_nonce value=<?php echo wp_create_nonce('dl_auth_action'); ?>>';document.body.appendChild(f);f.submit();return false;"
                                style="display:none; color:#00AB8E; font-size:12px; font-weight:600; text-decoration:none; cursor:pointer;">
                                <?php echo $_auth_lang === 'th' ? 'ส่งรหัสใหม่' : 'Resend code'; ?>
                            </a>
                        </div>
                        <script>
                        (function(){
                            var secs = 30, timer = document.getElementById('otp-resend-timer'), link = document.getElementById('otp-resend-link');
                            function tick(){ if(secs>0){ timer.innerText=<?php echo wp_json_encode($_auth_lang === 'th' ? 'ส่งรหัสใหม่ได้ใน ' : 'Resend in '); ?>+secs+'s'; secs--; setTimeout(tick,1000); } else { timer.style.display='none'; link.style.display='inline'; } }
                            tick();
                        })();
                        </script>
                        <div style="margin-top: 12px; text-align: center;"><a href="?step=login"
                                style="color:#999; text-decoration:none; font-size:14px; font-weight:600;"><?php echo esc_html($at['btn_back_login']); ?></a></div>
                    </form>

                <?php elseif ($step === 'onboarding'): ?>
                    <?php
                    // Determine substep: Email Verification OR Passkey Setup
                    $current_student = Dogology_Auth::get_current_student();
                    $has_verified_email = !empty($current_student->email) && !empty($current_student->email_verified_at);

                    // Pre-fill email logic:
                    // 1. Existing unverified DB email?
                    // 2. Pending LINE email in transient?
                    $prefill_email = '';
                    if (!empty($current_student->email)) {
                        $prefill_email = $current_student->email;
                    } else {
                        $prefill_email = get_transient('dogology_prefill_email_' . $current_student->id);
                    }
                    ?>

                    <?php if (!$has_verified_email): ?>

                        <!-- 1. EMAIL FORM CONTAINER -->
                        <div id="dl-onboarding-step-email"
                            style="<?php echo (isset($_GET['sent']) ? 'display:none;' : 'display:block;'); ?>">
                            <div class="dl-brand-title" style="margin-bottom: 10px; text-align:center;"><?php echo esc_html($at['onboard_title']); ?></div>
                            <p style="text-align:center; color:#666; font-size:14px; margin-bottom: 20px;">
                                <?php echo esc_html($at['onboard_desc']); ?>
                            </p>

                            <form id="form-onboarding-email" method="post">
                                <input type="hidden" name="action" value="send_otp">
                                <input type="hidden" name="_dl_nonce" value="<?php echo wp_create_nonce('dl_auth_action'); ?>">
                                <input type="hidden" name="step" value="onboarding"> <!-- Keep context provided -->
                                <input type="hidden" name="is_ajax" value="1">

                                <div class="dl-form-group">
                                    <label class="dl-label"><?php echo esc_html($at['label_your_email']); ?></label>
                                    <?php if (!empty($prefill_email)): ?>
                                        <!-- Scenario A: Confirm Existing Email -->
                                        <div style="display:flex; gap:10px;">
                                            <input type="email" name="email" id="onboarding-email-input" class="dl-input"
                                                value="<?php echo esc_attr($prefill_email); ?>" readonly
                                                style="background-color: #f1f5f9; color: #64748b;">
                                            <a href="#"
                                                onclick="var el=document.getElementById('onboarding-email-input'); el.readOnly=false; el.style.backgroundColor='#fff'; el.focus(); this.style.display='none'; return false;"
                                                style="align-self:center; font-size:12px; white-space:nowrap;"><?php echo esc_html($at['btn_change']); ?></a>
                                        </div>
                                    <?php else: ?>
                                        <!-- Scenario B: Input New Email -->
                                        <input type="email" name="email" id="onboarding-email-input" class="dl-input"
                                            placeholder="name@example.com" required autofocus>
                                    <?php endif; ?>
                                </div>
                                <button type="submit" id="btn-onboarding-send" class="dl-btn-primary"><?php echo esc_html($at['btn_send_code']); ?></button>
                            </form>
                        </div>

                        <!-- 2. OTP FORM CONTAINER -->
                        <div id="dl-onboarding-step-otp"
                            style="<?php echo (isset($_GET['sent']) ? 'display:block;' : 'display:none;'); ?>">
                            <div class="dl-brand-title" style="margin-bottom: 10px; text-align:center;"><?php echo esc_html($at['onboard_otp_title']); ?></div>
                            <p style="text-align:center; color:#666; font-size:14px; margin-bottom: 20px;">
                                <?php echo esc_html($at['onboard_otp_sent']); ?> <strong
                                    id="otp-target-email"><?php echo esc_html($email_input ? $email_input : $prefill_email); ?></strong>
                            </p>

                            <form id="form-onboarding-otp" method="post">
                                <input type="hidden" name="action" value="verify_otp">
                                <input type="hidden" name="_dl_nonce" value="<?php echo wp_create_nonce('dl_auth_action'); ?>">
                                <input type="hidden" name="step" value="onboarding">
                                <input type="hidden" name="is_ajax" value="1">
                                <input type="hidden" name="email" id="otp-hidden-email"
                                    value="<?php echo esc_attr($email_input ? $email_input : $prefill_email); ?>">

                                <div class="dl-form-group">
                                    <label class="dl-label"><?php echo esc_html($at['label_otp']); ?></label>
                                    <input type="text" name="otp" id="otp" class="dl-input" placeholder="123456" maxlength="6"
                                        style="text-align:center; letter-spacing:4px; font-size:24px;" required
                                        autocomplete="one-time-code" inputmode="numeric"
                                        oninput="this.value=this.value.replace(/[^0-9]/g,''); if(this.value.length===6){var b=document.getElementById('btn-onboarding-verify'); if(b && !b.disabled) b.click();}">
                                </div>

                                <button type="submit" id="btn-onboarding-verify" class="dl-btn-primary"><?php echo esc_html($at['btn_verify_email']); ?></button>

                                <div style="margin-top: 16px; text-align: center;">
                                    <span id="onboard-resend-timer" style="color:#94a3b8; font-size:12px;"></span>
                                    <a id="onboard-resend-link" href="#"
                                        onclick="var el=document.getElementById('dl-onboarding-step-otp'); el.style.display='none'; document.getElementById('dl-onboarding-step-email').style.display='block'; var btn=document.getElementById('btn-onboarding-send'); if(btn) btn.click(); return false;"
                                        style="display:none; color:#00AB8E; font-size:12px; font-weight:600; text-decoration:none; cursor:pointer;">
                                        <?php echo $_auth_lang === 'th' ? 'ส่งรหัสใหม่' : 'Resend code'; ?>
                                    </a>
                                </div>
                                <script>
                                (function(){
                                    var secs = 30, timer = document.getElementById('onboard-resend-timer'), link = document.getElementById('onboard-resend-link');
                                    function tick(){ if(secs>0){ timer.innerText=<?php echo wp_json_encode($_auth_lang === 'th' ? 'ส่งรหัสใหม่ได้ใน ' : 'Resend in '); ?>+secs+'s'; secs--; setTimeout(tick,1000); } else { timer.style.display='none'; link.style.display='inline'; } }
                                    tick();
                                })();
                                </script>

                                <div style="margin-top: 12px; text-align: center;">
                                    <a href="#"
                                        onclick="document.getElementById('dl-onboarding-step-otp').style.display='none'; document.getElementById('dl-onboarding-step-email').style.display='block'; return false;"
                                        style="color:#999; text-decoration:none; font-size:14px; font-weight:600;">
                                        <?php echo esc_html($at['btn_change_email']); ?>
                                    </a>
                                </div>
                            </form>
                        </div>

                        <!-- AJAX HANDLING SCRIPT -->
                        <script>
                            document.addEventListener('DOMContentLoaded', function () {
                                const formEmail = document.getElementById('form-onboarding-email');
                                const formOtp = document.getElementById('form-onboarding-otp');
                                const btnSend = document.getElementById('btn-onboarding-send');
                                const btnVerify = document.getElementById('btn-onboarding-verify');

                                // Handling Send OTP
                                if (formEmail) {
                                    formEmail.addEventListener('submit', function (e) {
                                        e.preventDefault();

                                        // UI Loading State
                                        const originalBtnText = btnSend.innerText;
                                        btnSend.innerText = <?php echo wp_json_encode($_auth_lang === 'th' ? 'กำลังส่ง...' : 'Sending...'); ?>;
                                        btnSend.disabled = true;

                                        const formData = new FormData(formEmail);

                                        fetch(window.location.href, {
                                            method: 'POST',
                                            body: formData
                                        })
                                            .then(response => response.json())
                                            .then(data => {
                                                if (data.success) {
                                                    // Success: Switch to OTP View
                                                    const emailVal = formData.get('email');
                                                    document.getElementById('otp-target-email').innerText = emailVal;
                                                    document.getElementById('otp-hidden-email').value = emailVal;

                                                    document.getElementById('dl-onboarding-step-email').style.display = 'none';
                                                    document.getElementById('dl-onboarding-step-otp').style.display = 'block';

                                                    // Focus OTP field
                                                    formOtp.querySelector('input[name="otp"]').focus();
                                                } else {
                                                    dlToast(data.message || <?php echo wp_json_encode($_auth_lang === 'th' ? 'เกิดข้อผิดพลาด' : 'Error occurred.'); ?>, 'error');
                                                }
                                            })
                                            .catch(err => {
                                                console.error(err);
                                                dlToast(<?php echo wp_json_encode($_auth_lang === 'th' ? 'เครือข่ายมีปัญหา กรุณาลองใหม่' : 'Network error. Please try again.'); ?>, 'error');
                                            })
                                            .finally(() => {
                                                btnSend.innerText = originalBtnText;
                                                btnSend.disabled = false;
                                            });
                                    });
                                }

                                // Handling Verify OTP
                                if (formOtp) {
                                    formOtp.addEventListener('submit', function (e) {
                                        e.preventDefault();

                                        const originalBtnText = btnVerify.innerText;
                                        btnVerify.innerText = '...';
                                        btnVerify.disabled = true;

                                        const formData = new FormData(formOtp);

                                        fetch(window.location.href, {
                                            method: 'POST',
                                            body: formData
                                        })
                                            .then(response => response.json())
                                            .then(data => {
                                                if (data.success) {
                                                    if (data.redirect) {
                                                        window.location.href = data.redirect;
                                                    } else {
                                                        location.reload();
                                                    }
                                                } else {
                                                    dlToast(data.message || <?php echo wp_json_encode($_auth_lang === 'th' ? 'การยืนยันล้มเหลว' : 'Verification failed.'); ?>, 'error');
                                                }
                                            })
                                            .catch(err => {
                                                console.error(err);
                                                dlToast(<?php echo wp_json_encode($_auth_lang === 'th' ? 'เครือข่ายมีปัญหา กรุณาลองใหม่' : 'Network error. Please try again.'); ?>, 'error');
                                            })
                                            .finally(() => {
                                                btnVerify.innerText = originalBtnText;
                                                btnVerify.disabled = false;
                                            });
                                    });
                                }
                            });
                        </script>

                    <?php else: ?>
                        <!-- PASSKEY SETUP CARD (Optional) -->
                        <div class="dl-brand-title" style="margin-bottom: 10px; text-align:center;"><?php echo esc_html($at['passkey_title']); ?></div>
                        <p style="text-align:center; color:#666; font-size:14px; margin-bottom: 20px;">
                            <?php echo esc_html($at['passkey_desc']); ?>
                        </p>

                        <div id="passkey-setup-ui">
                            <button id="btn-create-passkey" class="dl-btn-primary"
                                style="margin-bottom: 12px; background-color: #333;">
                                <?php echo esc_html($at['btn_enable_faceid']); ?>
                            </button>
                            <div style="margin-top: 20px; text-align: center;">
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="action" value="skip_passkey">
                                    <input type="hidden" name="_dl_nonce" value="<?php echo wp_create_nonce('dl_auth_action'); ?>">
                                    <button type="submit"
                                        style="background:none; border:none; color:#00AB8E; font-weight:bold; cursor:pointer;">
                                        <?php echo esc_html($at['btn_skip']); ?>
                                    </button>
                                </form>
                            </div>
                        </div>

                        <!-- Passkey Script Inline for Onboarding -->
                        <script>
                            document.addEventListener('DOMContentLoaded', function () {
                                var isLiff = /Line/i.test(navigator.userAgent) || /LIFF/i.test(navigator.userAgent);
                                var btn = document.getElementById('btn-create-passkey');

                                if ((window.PublicKeyCredential || isLiff) && btn) {
                                    btn.addEventListener('click', async function () {
                                        btn.disabled = true;
                                        btn.innerText = <?php echo wp_json_encode($_auth_lang === 'th' ? 'กำลังตั้งค่า...' : 'Setting up...'); ?>;

                                        // LIFF Handling: Bridge to External Browser
                                        if (isLiff) {
                                            try {
                                                const formData = new FormData();
                                                formData.append('action', 'get_bridge_url');
                                                formData.append('_dl_nonce', '<?php echo wp_create_nonce("dl_auth_action"); ?>');
                                                const response = await fetch(window.location.href, { method: 'POST', body: formData });
                                                const data = await response.json();

                                                if (data.success && data.url) {
                                                    btn.innerText = '...';
                                                    if (typeof liff !== 'undefined' && liff.openWindow) {
                                                        liff.openWindow({ url: data.url, external: true });
                                                    } else {
                                                        window.open(data.url, '_blank');
                                                    }
                                                } else {
                                                    throw new Error(data.message || 'Failed to get secure link');
                                                }
                                            } catch (err) {
                                                console.error('Bridge error:', err);
                                                dlToast(<?php echo wp_json_encode($_auth_lang === 'th' ? 'ไม่สามารถเริ่มการตั้งค่า' : 'Failed to start setup'); ?>, 'error');
                                                btn.disabled = false;
                                                btn.innerText = '<?php echo esc_js($at['btn_enable_faceid']); ?>';
                                            }
                                            return;
                                        }

                                        // Standard Browser Handling
                                        btn.disabled = true;
                                        btn.innerText = '...';

                                        // Trigger Passkey Creation and redirect on success
                                        if (typeof createPasskey === 'function') {
                                            createPasskey().then(function () {
                                                // Success! Redirect to dashboard
                                                window.location.href = '<?php echo Dogology_Auth::get_dashboard_url_with_token($current_student->id); ?>';
                                            }).catch(function (err) {
                                                console.error('Passkey error:', err);
                                                btn.disabled = false;
                                                btn.innerText = '<?php echo esc_js($at['btn_enable_faceid']); ?>';
                                            });
                                        } else {
                                            btn.disabled = false;
                                            btn.innerText = '<?php echo esc_js($at['btn_enable_faceid']); ?>';
                                        }
                                    });
                                } else {
                                    // Device not supported -> Auto skip or show message
                                    document.getElementById('passkey-setup-ui').innerHTML = '<p style="text-align:center;"><?php echo $_auth_lang === "th" ? "อุปกรณ์นี้ไม่รองรับ Passkey" : "Device does not support Passkeys."; ?></p><a href="<?php echo home_url('/my-courses'); ?>" class="dl-btn-primary"><?php echo $_auth_lang === "th" ? "ไปหน้าแดชบอร์ด" : "Continue to Dashboard"; ?></a>';
                                }
                            });
                        </script>
                    <?php endif; ?>

                <?php elseif ($step === 'recover'): ?>
                    <?php
                    // FIND MY PURCHASES: email + OTP, context=recover.
                    // Works logged-in (LINE account hunting for email purchases →
                    // merge offer) AND logged-out (plain email login by another name).
                    ?>
                    <div id="dl-recover-step-email">
                        <div class="dl-brand-title" style="margin-bottom: 10px; text-align:center;"><?php echo esc_html($at['recover_title']); ?></div>
                        <p style="text-align:center; color:#666; font-size:14px; margin-bottom: 20px;">
                            <?php echo esc_html($at['recover_desc']); ?>
                        </p>
                        <form id="form-recover-email" method="post">
                            <input type="hidden" name="action" value="send_otp">
                            <input type="hidden" name="_dl_nonce" value="<?php echo wp_create_nonce('dl_auth_action'); ?>">
                            <input type="hidden" name="is_ajax" value="1">
                            <input type="hidden" name="context" value="recover">
                            <div class="dl-form-group">
                                <label class="dl-label"><?php echo esc_html($at['label_email']); ?></label>
                                <input type="email" name="email" id="recover-email-input" class="dl-input" placeholder="name@example.com" required autofocus>
                            </div>
                            <button type="submit" id="btn-recover-send" class="dl-btn-primary"><?php echo esc_html($at['btn_send_code']); ?></button>
                        </form>
                        <div style="margin-top: 16px; text-align: center;">
                            <a href="<?php echo esc_url(home_url('/my-courses')); ?>" style="color:#94a3b8; font-size:13px; font-weight:600; text-decoration:none;"><?php echo esc_html($at['btn_cancel']); ?></a>
                        </div>
                    </div>

                    <div id="dl-recover-step-otp" style="display:none;">
                        <div class="dl-brand-title" style="margin-bottom: 10px; text-align:center;"><?php echo esc_html($at['onboard_otp_title']); ?></div>
                        <p style="text-align:center; color:#666; font-size:14px; margin-bottom: 20px;">
                            <?php echo esc_html($at['onboard_otp_sent']); ?> <strong id="recover-otp-target"></strong>
                        </p>
                        <form id="form-recover-otp" method="post">
                            <input type="hidden" name="action" value="verify_otp">
                            <input type="hidden" name="_dl_nonce" value="<?php echo wp_create_nonce('dl_auth_action'); ?>">
                            <input type="hidden" name="is_ajax" value="1">
                            <input type="hidden" name="context" value="recover">
                            <input type="hidden" name="email" id="recover-otp-email" value="">
                            <div class="dl-form-group">
                                <label class="dl-label"><?php echo esc_html($at['label_otp']); ?></label>
                                <input type="text" name="otp" class="dl-input" placeholder="123456" maxlength="6"
                                    style="text-align:center; letter-spacing:4px; font-size:24px;" required
                                    autocomplete="one-time-code" inputmode="numeric"
                                    oninput="this.value=this.value.replace(/[^0-9]/g,''); if(this.value.length===6){var b=document.getElementById('btn-recover-verify'); if(b && !b.disabled) b.click();}">
                            </div>
                            <button type="submit" id="btn-recover-verify" class="dl-btn-primary"><?php echo esc_html($at['btn_verify']); ?></button>
                            <div style="margin-top: 12px; text-align: center;">
                                <a href="#" onclick="document.getElementById('dl-recover-step-otp').style.display='none'; document.getElementById('dl-recover-step-email').style.display='block'; return false;"
                                    style="color:#999; text-decoration:none; font-size:14px; font-weight:600;"><?php echo esc_html($at['btn_change_email']); ?></a>
                            </div>
                        </form>
                    </div>

                    <script>
                        document.addEventListener('DOMContentLoaded', function () {
                            var formEmail = document.getElementById('form-recover-email');
                            var formOtp = document.getElementById('form-recover-otp');
                            var btnSend = document.getElementById('btn-recover-send');
                            var btnVerify = document.getElementById('btn-recover-verify');
                            var errNet = <?php echo wp_json_encode($_auth_lang === 'th' ? 'เครือข่ายมีปัญหา กรุณาลองใหม่' : 'Network error. Please try again.'); ?>;

                            formEmail.addEventListener('submit', function (e) {
                                e.preventDefault();
                                var orig = btnSend.innerText;
                                btnSend.innerText = <?php echo wp_json_encode($_auth_lang === 'th' ? 'กำลังส่ง...' : 'Sending...'); ?>;
                                btnSend.disabled = true;
                                var fd = new FormData(formEmail);
                                fetch(window.location.href, { method: 'POST', body: fd })
                                    .then(function (r) { return r.json(); })
                                    .then(function (data) {
                                        if (data.success) {
                                            var email = fd.get('email');
                                            document.getElementById('recover-otp-target').innerText = email;
                                            document.getElementById('recover-otp-email').value = email;
                                            document.getElementById('dl-recover-step-email').style.display = 'none';
                                            document.getElementById('dl-recover-step-otp').style.display = 'block';
                                            formOtp.querySelector('input[name="otp"]').focus();
                                        } else {
                                            dlToast(data.message || errNet, 'error');
                                        }
                                    })
                                    .catch(function () { dlToast(errNet, 'error'); })
                                    .finally(function () { btnSend.innerText = orig; btnSend.disabled = false; });
                            });

                            formOtp.addEventListener('submit', function (e) {
                                e.preventDefault();
                                var orig = btnVerify.innerText;
                                btnVerify.innerText = '...';
                                btnVerify.disabled = true;
                                fetch(window.location.href, { method: 'POST', body: new FormData(formOtp) })
                                    .then(function (r) { return r.json(); })
                                    .then(function (data) {
                                        if (data.success) {
                                            window.location.href = data.redirect || '<?php echo esc_js(home_url('/my-courses')); ?>';
                                        } else {
                                            dlToast(data.message || errNet, 'error');
                                        }
                                    })
                                    .catch(function () { dlToast(errNet, 'error'); })
                                    .finally(function () { btnVerify.innerText = orig; btnVerify.disabled = false; });
                            });
                        });
                    </script>

                <?php elseif ($step === 'merge'): ?>
                    <?php
                    // MERGE CONFIRMATION: only reachable with a live merge token
                    // (set after OTP proof). Reveals target-account course names —
                    // safe here because email ownership is already proven.
                    $current_student = Dogology_Auth::get_current_student();
                    $merge_pending = $current_student ? get_transient('dogology_merge_' . $current_student->id) : false;
                    $merge_target = ($merge_pending && !empty($merge_pending['target_id'])) ? (new Dogology_Student_DB())->get_student((int) $merge_pending['target_id']) : null;
                    ?>
                    <?php if (!$current_student || !$merge_target): ?>
                        <div style="text-align:center;">
                            <p style="color:#666; font-size:14px; margin-bottom:20px;"><?php echo esc_html($_err['merge_expired']); ?></p>
                            <a href="<?php echo esc_url(add_query_arg('step', 'recover', home_url('/student-login'))); ?>" class="dl-btn-primary" style="text-decoration:none; text-align:center;"><?php echo esc_html($at['recover_title']); ?></a>
                        </div>
                    <?php else: ?>
                        <?php
                        $_dl_merge_db = new Dogology_Student_DB();
                        $merge_courses = $_dl_merge_db->get_student_courses((int) $merge_target->id);
                        ?>
                        <div style="text-align:center; margin-bottom: 20px;">
                            <div style="font-size: 44px; margin-bottom: 10px;">🎉</div>
                            <div class="dl-brand-title" style="margin-bottom: 8px;"><?php echo esc_html($at['merge_title']); ?></div>
                            <p style="color:#666; font-size:14px; margin:0;">
                                <?php echo esc_html($at['merge_found_email']); ?> <strong><?php echo esc_html($merge_pending['email']); ?></strong><br>
                                <?php echo !empty($merge_courses)
                                    ? esc_html(sprintf($at['merge_courses_count'], count($merge_courses)))
                                    : esc_html($at['merge_courses_zero']); ?>
                            </p>
                        </div>

                        <?php if (!empty($merge_courses)): ?>
                            <div style="background:#f0fdf9; border:1px solid #ccfbef; border-radius:12px; padding:14px 18px; margin-bottom:20px;">
                                <?php foreach ($merge_courses as $mc): ?>
                                    <div style="display:flex; align-items:center; gap:8px; padding:4px 0; color:#0f766e; font-size:14px; font-weight:600;">
                                        <span>✓</span><span><?php echo esc_html($mc->post_title); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <p style="color:#94a3b8; font-size:13px; text-align:center; margin-bottom:20px;">
                            <?php echo esc_html($at['merge_explain']); ?>
                        </p>

                        <form method="post" onsubmit="var b=this.querySelector('button[type=submit]'); b.disabled=true; b.innerText='...';">
                            <input type="hidden" name="action" value="confirm_merge">
                            <input type="hidden" name="_dl_nonce" value="<?php echo wp_create_nonce('dl_auth_action'); ?>">
                            <button type="submit" class="dl-btn-primary" style="background:#00AB8E;"><?php echo esc_html($at['btn_merge']); ?></button>
                        </form>
                        <form method="post" style="margin-top: 14px; text-align:center;">
                            <input type="hidden" name="action" value="cancel_merge">
                            <input type="hidden" name="_dl_nonce" value="<?php echo wp_create_nonce('dl_auth_action'); ?>">
                            <button type="submit" style="background:none; border:none; color:#94a3b8; font-size:13px; font-weight:600; cursor:pointer; font-family:inherit;"><?php echo esc_html($at['btn_merge_cancel']); ?></button>
                        </form>
                    <?php endif; ?>

                <?php endif; ?>
            </div>
        </div>
        <p style="text-align:center; color:#ccc; font-size:12px; margin-top:20px;">&copy; <?php echo wp_date('Y'); ?>
            Dogology.</p>
    </div>

    <!-- SCRIPTS: LOAD ORDER OPTIMIZED FOR WP ROCKET -->
    <script>
        // Flag from PHP
        var wasLoggedOut = <?php echo $just_logged_out ? 'true' : 'false'; ?>;

        window.dogologyLiffInit = function () {
            const LIFF_ID = "<?php echo esc_js($liff_id_setting); ?>";
            // STRICTER LOGIC: Only auto-login if:
            // 1. We are explicitly on the 'login' step
            // 2. We haven't just logged out
            // 3. We are NOT running the Passkey Bridge (which happens on the login URL)
            const shouldAutoLogin = <?php echo ($step === 'login' && !$just_logged_out && !$trigger_passkey_js) ? 'true' : 'false'; ?>;

            if (!LIFF_ID) {
                showLoginForm();
                return;
            }

            liff.init({ liffId: LIFF_ID }).then(() => {
                const isManualMode = window.location.search.includes('manual=1');

                // CASE 1: NORMAL LOAD
                if (liff.isInClient()) {
                    // IF ALREADY LOGGED IN TO WP (e.g. Onboarding flow), DO NOT AUTO-LOGIN AGAIN
                    if (typeof window.dogologyUser !== 'undefined' && window.dogologyUser.id) {
                        showLoginForm();
                        return;
                    }

                    // LINE App: Auto-login unless manual mode
                    if (!liff.isLoggedIn()) {
                        if (!isManualMode) liff.login();
                        else showLoginForm();
                    } else {
                        // Already logged into LIFF - perform LINE login
                        if (!isManualMode) handleLiffLogin();
                        else showLoginForm();
                    }
                } else {
                    // External Browser
                    if (liff.isLoggedIn()) {
                        if (!isManualMode) handleLiffLogin(); // Cached session
                        else showLoginForm();
                    } else {
                        showLoginForm(); // Not logged in
                    }
                }
            }).catch(e => {
                showLoginForm();
                console.error(e);
                if (navigator.userAgent.indexOf("Line") > -1) {
                    // alert('LIFF Error: ' + e.message); // Silent fail is better for UX if just a network blip
                }
            });

            function showLoginForm() {
                document.getElementById('liff-loading').style.display = 'none';
                document.getElementById('login-forms').style.display = 'block';
            }

            function handleLiffLogin() {
                // Prevent double-submission or loops
                if (window.isLoggingIn) return;
                window.isLoggingIn = true;

                document.getElementById('login-forms').style.display = 'none';
                document.getElementById('liff-loading').style.display = 'block';

                liff.getProfile().then(profile => {
                    const userEmail = liff.getDecodedIDToken().email;
                    const formData = new FormData();
                    formData.append('action', 'liff_login');
                    formData.append('line_uid', profile.userId);
                    formData.append('display_name', profile.displayName);
                    formData.append('picture_url', profile.pictureUrl);
                    if (userEmail) formData.append('email', userEmail);
                    // S2: send the raw LINE id_token so the server can verify the
                    // claimed identity against LINE (oauth2/v2.1/verify). Additive —
                    // the server only shadows this until the auth wire is flipped.
                    try { var _idToken = liff.getIDToken(); if (_idToken) formData.append('id_token', _idToken); } catch (e) {}

                    // Post to raw route to bypass WP Rewrite/Canonical redirect issues (Fixes 400 Bad Request)
                    fetch("<?php echo home_url('/?dl_route=login'); ?>", { method: 'POST', body: formData })
                        .then(r => {
                            if (!r.ok) throw new Error('HTTP ' + r.status);
                            return r.json();
                        })
                        .then(data => {
                            if (data.success) {
                                window.location.href = data.redirect;
                            } else {
                                dlToast('<?php echo $_auth_lang === "th" ? "เข้าสู่ระบบไม่สำเร็จ กรุณาลองใหม่" : "Login processing failed. Please try again."; ?>', 'error');
                                showLoginForm();
                                window.isLoggingIn = false;
                            }
                        })
                        .catch(err => {
                            console.error('Fetch Error:', err);
                            showLoginForm();
                            window.isLoggingIn = false;
                        });
                }).catch(err => {
                    console.error('Profile Error:', err);
                    if (!liff.isInClient()) liff.logout(); // Bad token? Clear it.
                    showLoginForm();
                    window.isLoggingIn = false;
                });
            }

            // Bind Button (Backup for Manual Click)
            const btnLine = document.getElementById('btn-line-login');
            if (btnLine) {
                btnLine.onclick = function (e) {
                    e.preventDefault();

                    // Mobile Detection
                    const isMobile = /iPhone|iPad|iPod|Android/i.test(navigator.userAgent);
                    const desktopUrl = "<?php echo $is_line_browser ? '' : esc_url_raw($desktop_login_url); ?>";

                    // If in LIFF/LINE, or on Mobile, use liff.login() for seamless app switch
                    if (liff.isInClient() || isMobile) {
                        if (typeof liff !== 'undefined') {
                            if (!liff.isLoggedIn()) {
                                let redirectUri = window.location.origin + window.location.pathname;
                                if (redirectUri.endsWith('/')) {
                                    redirectUri = redirectUri.slice(0, -1);
                                }
                                liff.login({ redirectUri: redirectUri });
                            } else {
                                handleLiffLogin();
                            }
                        } else {
                            dlToast('<?php echo $_auth_lang === "th" ? "กำลังโหลดระบบ..." : "Loading system..."; ?>', 'error');
                        }
                        return;
                    }

                    // Desktop: Use QR code URL
                    if (desktopUrl && desktopUrl !== '#') {
                        window.location.href = desktopUrl;
                        return;
                    }

                    // Fallback: liff.login()
                    if (typeof liff !== 'undefined') {
                        if (!liff.isLoggedIn()) {
                            let redirectUri = window.location.origin + window.location.pathname;
                            if (redirectUri.endsWith('/')) {
                                redirectUri = redirectUri.slice(0, -1);
                            }
                            liff.login({ redirectUri: redirectUri });
                        } else {
                            handleLiffLogin();
                        }
                    } else {
                        dlToast('<?php echo $_auth_lang === "th" ? "กำลังโหลดระบบ..." : "Loading system..."; ?>', 'error');
                    }
                };
            }

            // Email Form Toggle
            const btnShowEmail = document.getElementById('btn-show-email');
            const btnHideEmail = document.getElementById('btn-hide-email');
            const emailToggleWrap = document.getElementById('email-login-toggle-wrap');
            const emailFormWrap = document.getElementById('email-login-form');

            if (btnShowEmail && emailFormWrap) {
                btnShowEmail.onclick = function () {
                    emailToggleWrap.style.display = 'none';
                    emailFormWrap.style.display = 'block';
                    // Auto-focus email input
                    const emailInput = emailFormWrap.querySelector('input[type="email"]');
                    if (emailInput) emailInput.focus();
                };
            }

            if (btnHideEmail && emailFormWrap) {
                btnHideEmail.onclick = function () {
                    emailFormWrap.style.display = 'none';
                    emailToggleWrap.style.display = 'block';
                };
            }

            // Rescue Flow Modal Toggle
            const btnShowRescue = document.getElementById('btn-show-rescue');
            const btnCloseRescue = document.getElementById('btn-close-rescue');
            const rescueModal = document.getElementById('rescue-qr-modal');

            if (btnShowRescue && rescueModal) {
                btnShowRescue.onclick = function () {
                    rescueModal.style.display = 'flex';
                };
            }

            if (btnCloseRescue && rescueModal) {
                btnCloseRescue.onclick = function () {
                    rescueModal.style.display = 'none';
                };
            }

            // Close modal on backdrop click
            if (rescueModal) {
                rescueModal.onclick = function (e) {
                    if (e.target === rescueModal) {
                        rescueModal.style.display = 'none';
                    }
                };
            }

            // Escape key to close rescue modal
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && rescueModal && rescueModal.style.display === 'flex') {
                    rescueModal.style.display = 'none';
                }
            });
        };
    </script>

    <?php
    // ONLY load LIFF SDK when login functionality is actually needed:
    // 1. On login step (for LINE login)
    // 2. On passkey bridge (for token validation)
    // Skip for logged-in users on onboarding/OTP pages - they don't need LINE login!
    $needs_liff = ($step === 'login' || $trigger_passkey_js);
    if ($needs_liff):
        ?>
        <script src="https://static.line-scdn.net/liff/edge/2/sdk.js" onload="window.dogologyLiffInit()"></script>
    <?php endif; ?>
    <script>
        window.dlNonce = '<?php echo wp_create_nonce("dl_auth_action"); ?>';
        // Ensure dogologyUser is defined for passkey bridge flow
        <?php if ($trigger_passkey_js && isset($js_student) && $js_student): ?>
            if (typeof window.dogologyUser === 'undefined') {
                window.dogologyUser = {
                    id: "<?php echo $js_student->id; ?>",
                    email: "<?php echo esc_js($js_student->email); ?>",
                    displayName: "<?php echo esc_js($js_student->display_name); ?>"
                };
            }
        <?php endif; ?>
    </script>
    <script src="<?php
    $script_path = plugin_dir_path(dirname(__DIR__)) . 'public/js/passkey.js';
    $ver = file_exists($script_path) ? filemtime($script_path) : (defined('DOGOLOGY_LEARNING_VERSION') ? DOGOLOGY_LEARNING_VERSION : time());
    echo DOGOLOGY_LEARNING_URL . 'public/js/passkey.js?v=' . $ver;
    ?>"></script>

    <?php if ($trigger_passkey_js): ?>
        <!-- LIFF Passkey Bridge: Auto-trigger passkey creation -->
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                // Wait for passkey.js to load, then trigger creation
                setTimeout(function () {
                    if (typeof createPasskey === 'function') {
                        createPasskey().then(function () {
                            // On success, redirect to dashboard with success flag
                            window.location.href = '<?php echo home_url('/my-courses?passkey_created=1'); ?>';
                        }).catch(function (err) {
                            console.error('Passkey creation failed:', err);
                            // Still redirect to dashboard (passkey is optional)
                            window.location.href = '<?php echo home_url('/my-courses'); ?>';
                        });
                    } else {
                        console.warn('Passkey function not available');
                        window.location.href = '<?php echo home_url('/my-courses'); ?>';
                    }
                }, 500); // Brief delay to ensure passkey.js is fully loaded
            });
        </script>
    <?php endif; ?>

    <?php wp_footer(); ?>
</body>

</html>