<?php
/**
 * Template: Student Logout
 * URL: /student-logout
 * Purpose: Clears PHP session AND ensures LIFF session is cleared before redirecting.
 */

if (!defined('ABSPATH')) {
    exit;
}

// WP Rocket / Caching compatibility
if (!defined('DONOTCACHEPAGE')) {
    define('DONOTCACHEPAGE', true);
}
nocache_headers();

// Get LIFF ID
$liff_id_setting = get_option('dogology_learning_liff_id', '');
?>
<!DOCTYPE html>
<?php
$_lo_lang = 'en';
if (isset($_COOKIE['dl_lang']) && in_array($_COOKIE['dl_lang'], ['th', 'en'])) {
    $_lo_lang = $_COOKIE['dl_lang'];
} elseif (isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) && substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2) === 'th') {
    $_lo_lang = 'th';
}
?>
<html lang="<?php echo $_lo_lang; ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $_lo_lang === 'th' ? 'กำลังออกจากระบบ...' : 'Signing out...'; ?></title>
    <style>
        body {
            font-family: sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
            background: #f8fafc;
            color: #64748b;
        }

        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #00AB8E;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            animation: spin 1s linear infinite;
            margin-bottom: 20px;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }
    </style>
</head>

<body>
    <div style="text-align: center;">
        <div class="spinner" style="margin: 0 auto 20px;"></div>
        <p><?php echo $_lo_lang === 'th' ? 'กำลังออกจากระบบ...' : 'Signing out safely...'; ?></p>
    </div>

    <?php if ($liff_id_setting): ?>
        <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
        <script>
            const LIFF_ID = "<?php echo esc_js($liff_id_setting); ?>";
            const LOGIN_URL = "<?php echo home_url('/student-login?logged_out=1&manual=1'); ?>";
            // 'manual=1' tells auth.php: "Do not auto-login immediately, show the buttons first."

            function done() {
                window.location.replace(LOGIN_URL);
            }

            liff.init({ liffId: LIFF_ID }).then(() => {
                if (liff.isLoggedIn()) {
                    liff.logout();
                }
                // Double check cleanup
                if (!liff.isLoggedIn()) {
                    done();
                } else {
                    // Should not happen, but force redirect anyway
                    done();
                }
            }).catch(err => {
                console.error(err);
                // Even if LIFF fails, we must redirect
                done();
            });

            // Safety timeout in case LIFF hangs (3 sec max)
            setTimeout(done, 3000);
        </script>
    <?php else: ?>
        <script>
            window.location.replace("<?php echo home_url('/student-login'); ?>");
        </script>
    <?php endif; ?>
</body>

</html>