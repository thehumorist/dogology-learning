<?php
/**
 * LIFF Login Template
 *
 * Variables available:
 * $channel_id : The LINE Channel ID
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<!DOCTYPE html>
<?php
$browser_lang = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2) : 'en';
$ll = ($browser_lang === 'th') ? 'th' : 'en';
?>
<html lang="<?php echo $ll; ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $ll === 'th' ? 'กำลังเข้าสู่ระบบ...' : 'Logging in...'; ?></title>
    <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            text-align: center;
            padding-top: 50px;
        }

        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #3498db;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .error {
            color: red;
            margin-top: 20px;
        }
    </style>
</head>

<body>

    <div id="status">
        <div class="spinner"></div>
        <p><?php echo $ll === 'th' ? 'กำลังรักษาความปลอดภัย...' : 'Securing connection...'; ?></p>
    </div>

    <script>
        const LIFF_ID = "<?php echo esc_js($channel_id); ?>"; // NOTE: Usually LIFF ID is distinct from Channel ID, but using same for now or need config.

        async function main() {
            try {
                await liff.init({ liffId: LIFF_ID });

                if (!liff.isLoggedIn()) {
                    liff.login();
                    return;
                }

                const idToken = liff.getIDToken();

                // Send to Backend
                const response = await fetch('/wp-json/dogology-learning/v1/login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id_token: idToken })
                });

                const result = await response.json();

                if (result.success) {
                    const statusEl = document.getElementById('status');
                    statusEl.textContent = '';
                    const successP = document.createElement('p');
                    successP.textContent = <?php echo $ll === "th" ? "'\u0e40\u0e02\u0e49\u0e32\u0e2a\u0e39\u0e48\u0e23\u0e30\u0e1a\u0e1a\u0e2a\u0e33\u0e40\u0e23\u0e47\u0e08!'" : "'Login successful!'"; ?>;
                    statusEl.appendChild(successP);
                    window.location.href = result.redirect_url;
                } else {
                    throw new Error(result.message || 'Login failed');
                }

            } catch (err) {
                const statusEl = document.getElementById('status');
                statusEl.textContent = '';
                const errDiv = document.createElement('div');
                errDiv.className = 'error';
                errDiv.textContent = <?php echo $ll === "th" ? "'\u0e40\u0e01\u0e34\u0e14\u0e02\u0e49\u0e2d\u0e1c\u0e34\u0e14\u0e1e\u0e25\u0e32\u0e14: '" : "'Error: '"; ?> + (err && err.message ? err.message : 'Unknown');
                statusEl.appendChild(errDiv);
                console.error(err);
            }
        }

        // Attempt to auto-run
        if (LIFF_ID) {
            main();
        } else {
            const statusEl = document.getElementById('status');
            statusEl.textContent = '';
            const errDiv = document.createElement('div');
            errDiv.className = 'error';
            errDiv.textContent = 'Configuration Error: Missing LIFF ID.';
            statusEl.appendChild(errDiv);
        }
    </script>
</body>

</html>