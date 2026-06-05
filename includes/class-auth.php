<?php

if (!defined('ABSPATH')) {
    exit;
}

class Dogology_Auth
{

    private static $cookie_name = 'dogology_token';

    /**
     * Generate Secure Hash
     */
    private static function generate_hash($user_id)
    {
        if (!defined('DOGOLOGY_AUTH_SALT')) {
            wp_die('Critical Error: DOGOLOGY_AUTH_SALT is not defined. Please check security configuration.');
        }
        return hash('sha256', $user_id . DOGOLOGY_AUTH_SALT);
    }

    /**
     * Get Current Student
     */
    public static function get_current_student()
    {
        if (!isset($_COOKIE[self::$cookie_name])) {
            return null;
        }

        $token = sanitize_text_field($_COOKIE[self::$cookie_name]);
        $parts = explode('|', $token);
        if (count($parts) < 2)
            return null;

        $user_id = intval($parts[0]);
        $hash = $parts[1];

        // START: Verify Hash (Security Fix)
        if (!hash_equals(self::generate_hash($user_id), $hash)) {
            // Invalid token
            self::logout();
            return null;
        }
        // END: Verify Hash

        // Fetch User
        $db = new Dogology_Student_DB();
        $student = $db->get_student($user_id);

        return $student;
    }

    /**
     * Set Session
     */
    public static function login_student($user_id)
    {
        // Record the login environment (browser / in-app webview) up front, before
        // anything can early-return. A login is a login even if the cookie can't be
        // set, and this is the single chokepoint every auth path funnels through —
        // LINE, magic code, passkey, and handoff. See record_login_event().
        self::record_login_event($user_id);

        $token = $user_id . '|' . self::generate_hash($user_id);
        if (headers_sent($file, $line)) {
            error_log("[Dogology_Auth] login_student: headers already sent at {$file}:{$line} — cookie not set for user {$user_id}");
            return false;
        }
        setcookie(self::$cookie_name, $token, [
            'expires' => time() + (86400 * 30),
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        return true;
    }

    /**
     * Persist one login-environment row ('login' event).
     */
    private static function record_login_event($user_id)
    {
        self::insert_event($user_id, 'login');
    }

    /**
     * Record a browsing session for an already-logged-in student. Called on
     * lesson/player page loads — the exact moment video playback matters, and
     * the moment the browser may differ from the one used at login (long-lived
     * cookie + opening lessons from inside the LINE/FB webview).
     *
     * Deduped to at most one row per student per browser-label per day via a
     * transient, so a student clicking through 20 lessons writes one row, not 20.
     * Resolves the current student itself; no-op for anonymous requests.
     */
    public static function record_session_event()
    {
        try {
            $student = self::get_current_student();
            if (!$student) {
                return;
            }
            $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
            $parsed = Dogology_Helpers::parse_user_agent($ua);
            $dedup_key = 'dl_seen_' . (int) $student->id . '_' . md5($parsed['label']);
            if (get_transient($dedup_key)) {
                return;
            }
            set_transient($dedup_key, 1, DAY_IN_SECONDS);
            self::insert_event($student->id, 'session');
        } catch (\Throwable $e) {
            // Never break a page render for analytics.
        }
    }

    /**
     * Shared insert for login/session environment rows. Best-effort and fully
     * swallowed: analytics must never block a login or a page render. The table
     * is created by Dogology_Learning_DB_Installer; if it's missing (upgrade not
     * yet run) the insert simply fails silently.
     */
    private static function insert_event($user_id, $event_type)
    {
        try {
            global $wpdb;
            $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 512) : '';
            $parsed = Dogology_Helpers::parse_user_agent($ua);
            $wpdb->insert(
                $wpdb->prefix . 'dogology_login_events',
                array(
                    'user_id'      => (int) $user_id,
                    'event_type'   => $event_type,
                    'ua'           => $ua,
                    'browser'      => $parsed['label'],
                    'is_inapp'     => $parsed['is_inapp'] ? 1 : 0,
                    'ip'           => Dogology_Helpers::client_ip(),
                    'logged_in_at' => current_time('mysql'),
                ),
                array('%d', '%s', '%s', '%s', '%d', '%s', '%s')
            );
        } catch (\Throwable $e) {
            // Never break the request for analytics.
        }
    }

    /**
     * Generate Handoff URL (for redirecting to dashboard with token)
     */
    public static function get_dashboard_url_with_token($user_id)
    {
        $ts = time() + 60; // 60s validity
        $sig = hash_hmac('sha256', $user_id . $ts, DOGOLOGY_AUTH_SALT);
        $token = $user_id . '|' . $sig . '|' . $ts;
        // Explicitly URL-encode token to prevent 400 errors from unencoded pipe chars
        return home_url('/my-courses') . '?auth_token=' . urlencode($token);
    }

    /**
     * Generate Passkey Bridge URL (for LIFF-to-Safari handoff)
     * Opens external browser, auto-logs in, triggers Passkey creation prompt
     */
    public static function get_passkey_bridge_url($user_id)
    {
        $ts = time() + 60; // 60s validity
        $sig = hash_hmac('sha256', $user_id . 'passkey' . $ts, DOGOLOGY_AUTH_SALT);
        $token = $user_id . '|' . $sig . '|' . $ts;
        return add_query_arg(array(
            'auth_token' => $token,
            'trigger_passkey' => '1'
        ), home_url('/student-login'));
    }

    /**
     * Logout
     */
    public static function logout()
    {
        if (!headers_sent()) {
            setcookie(self::$cookie_name, '', [
                'expires' => time() - 3600,
                'path' => '/',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        }
        if (isset($_COOKIE[self::$cookie_name])) {
            unset($_COOKIE[self::$cookie_name]);
        }
    }

    /**
     * Verify LINE Login Code (Backend Flow)
     */
    public static function verify_line_login_code($code, $redirect_uri, $client_id, $client_secret)
    {
        $url = 'https://api.line.me/oauth2/v2.1/token';
        $body = array(
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirect_uri,
            'client_id' => $client_id,
            'client_secret' => $client_secret
        );

        $response = wp_remote_post($url, array(
            'body' => $body,
            'headers' => array('Content-Type' => 'application/x-www-form-urlencoded')
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($data['error'])) {
            return new WP_Error('line_error', $data['error_description'] ?? 'Unknown LINE Error');
        }

        if (empty($data['id_token'])) {
            return new WP_Error('line_error', 'No ID Token returned');
        }

        // Decode ID Token (JWT) - Simple decode without hard signature validation (we trust SSL + direct response)
        // For production strictness we could use a JWT library, but this is standard practice for simple implementations
        $parts = explode('.', $data['id_token']);
        if (count($parts) !== 3) {
            return new WP_Error('line_error', 'Invalid ID Token format');
        }

        $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1])), true);
        if (empty($payload) || empty($payload['sub'])) {
            return new WP_Error('line_error', 'Invalid ID Token payload');
        }

        return array(
            'line_uid' => $payload['sub'],
            'display_name' => $payload['name'] ?? '',
            'picture_url' => $payload['picture'] ?? '',
            'email' => $payload['email'] ?? ''
        );
    }

    /**
     * Send OTP
     */
    public static function send_otp($email, $user_id = 0, $lang = 'en')
    {
        // Generate 6 digit code
        $otp = random_int(100000, 999999);

        // Store in transient (5 mins)
        set_transient('dogology_otp_' . md5($email), $otp, 300);

        // Generate Magic Link
        $expiry = time() + 300; // 5 mins
        // Include user_id in signature for robust identification
        $signature = hash_hmac('sha256', $email . '|' . $otp . '|' . $expiry . '|' . $user_id, DOGOLOGY_AUTH_SALT);
        $magic_link = add_query_arg(array(
            'action' => 'magic_login',
            'email' => $email, // add_query_arg handles encoding
            'otp' => $otp,
            'ts' => $expiry,
            'uid' => $user_id,
            'sig' => $signature
        ), home_url('/student-login/'));

        // --- BILINGUAL TEXT ---
        $is_th = ($lang === 'th');
        $subject = $is_th ? 'รหัสยืนยัน Dogology: ' . $otp : 'Your Dogology Code: ' . $otp;
        $txt_heading = 'Dogology Experience';
        $txt_code = $is_th ? 'นี่คือรหัสเข้าสู่ระบบของคุณ:' : 'Here is your login code:';
        $txt_btn = $is_th ? 'เข้าสู่ระบบทันที &rarr;' : 'Login Instantly &rarr;';
        $txt_expire = $is_th ? 'รหัสและลิงก์นี้จะหมดอายุภายใน 5 นาที' : 'This code and link expire in 5 minutes.';
        $txt_footer = '&copy; ' . date('Y') . ' Dogology.';
        $btn_url = esc_url($magic_link);

        // --- TABLE-BASED EMAIL TEMPLATE (Outlook + Gmail + Apple Mail compatible) ---
        $message = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>' . esc_html($subject) . '</title>
</head>
<body style="margin:0; padding:0; background-color:#f8fafc; -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%;">
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#f8fafc;" bgcolor="#f8fafc">
        <tr>
            <td align="center" style="padding:40px 20px;">

                <!--[if mso]><table role="presentation" cellpadding="0" cellspacing="0" border="0" width="440"><tr><td><![endif]-->
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:440px; background-color:#ffffff; border:1px solid #e2e8f0; border-radius:16px;" bgcolor="#ffffff">
                    <tr>
                        <td style="padding:40px 30px; text-align:center; font-family:\'Kanit\', -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif;">

                            <!-- Brand -->
                            <h2 style="color:#00AB8E; margin:0 0 24px 0; font-size:24px; font-weight:700; line-height:1.3;">' . esc_html($txt_heading) . '</h2>

                            <!-- Subtitle -->
                            <p style="color:#475569; font-size:16px; line-height:1.5; margin:0 0 24px 0;">' . esc_html($txt_code) . '</p>

                            <!-- OTP Code -->
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                                <tr>
                                    <td align="center" style="padding:20px 0 28px 0;">
                                        <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="background-color:#f0fdf9; padding:20px 32px; border-radius:12px; font-family:\'Courier New\', Courier, monospace; font-size:36px; font-weight:700; color:#00AB8E; letter-spacing:8px; text-align:center;" bgcolor="#f0fdf9">' . $otp . '</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <!-- CTA Button -->
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                                <tr>
                                    <td align="center" style="padding:0 0 28px 0;">
                                        <!--[if mso]>
                                        <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="' . $btn_url . '" style="height:48px;v-text-anchor:middle;width:220px;" arcsize="50%" fillcolor="#00AB8E" stroke="f">
                                            <w:anchorlock/>
                                            <center style="color:#ffffff;font-family:sans-serif;font-size:16px;font-weight:bold;">' . ($is_th ? 'เข้าสู่ระบบทันที →' : 'Login Instantly →') . '</center>
                                        </v:roundrect>
                                        <![endif]-->
                                        <!--[if !mso]><!-->
                                        <a href="' . $btn_url . '" style="background-color:#00AB8E; color:#ffffff; padding:14px 32px; text-decoration:none; border-radius:99px; font-weight:700; display:inline-block; font-size:16px; line-height:1; mso-hide:all;">' . $txt_btn . '</a>
                                        <!--<![endif]-->
                                    </td>
                                </tr>
                            </table>

                            <!-- Expiry Note -->
                            <p style="color:#94a3b8; font-size:13px; line-height:1.5; margin:0;">' . esc_html($txt_expire) . '</p>

                        </td>
                    </tr>
                </table>
                <!--[if mso]></td></tr></table><![endif]-->

                <!-- Footer -->
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:440px;">
                    <tr>
                        <td style="padding:24px 0 0 0; text-align:center; color:#cbd5e1; font-size:12px; font-family:-apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif;">' . $txt_footer . '</td>
                    </tr>
                </table>

            </td>
        </tr>
    </table>
</body>
</html>';

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'Bcc: nattawut@dogology.org'
        );

        // ACTUAL SEND
        wp_mail($email, $subject, $message, $headers);

        return $otp;
    }

    /**
     * Verify OTP
     */
    public static function verify_otp($email, $input_otp)
    {

        $stored = get_transient('dogology_otp_' . md5($email));
        if ($stored !== false && hash_equals((string) $stored, (string) $input_otp)) {
            delete_transient('dogology_otp_' . md5($email));
            return true;
        }
        return false;
    }
}
