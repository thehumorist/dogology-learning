/**
 * Dogology Passkey (WebAuthn) Handler
 */
document.addEventListener('DOMContentLoaded', function () {

    // Check support (WebAuthn) & Environment (LIFF does not support WebAuthn)
    const isLiff = /Line/i.test(navigator.userAgent) || /LIFF/i.test(navigator.userAgent);

    if (!window.PublicKeyCredential || isLiff) {
        document.querySelectorAll('.btn-passkey-trigger').forEach(el => {
            el.style.display = 'none';
        });

        // Specific UI for LIFF - Show Message
        // Specific UI for LIFF - Show Message
        // REMOVED: auth.php now handles LIFF by opening bridge URL.
        // if (isLiff) { ... }
        return;
    }

    // --- 1. Login Flow ---
    window.startPasskeyLogin = async () => {
        try {
            // Generates a random challenge
            const challenge = new Uint8Array(32);
            window.crypto.getRandomValues(challenge);

            const options = {
                challenge: challenge,
                timeout: 60000,
                userVerification: "preferred"
                // allowCredentials: [] // Allow any key for this domain
            };

            const credential = await navigator.credentials.get({ publicKey: options });

            // Simplified Verification for Prototype
            // We verify the Credential ID exists in our DB
            const credentialId = btoa(String.fromCharCode(...new Uint8Array(credential.rawId)));

            // Send to Backend
            const formData = new FormData();
            formData.append('action', 'passkey_login');
            formData.append('credential_id', credentialId);
            // formData.append('clientDataJSON', ...); // For Full Validation later

            fetch(window.location.href, { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        window.location.href = data.redirect;
                    } else {
                        if (typeof dlToast === 'function') dlToast('Passkey not linked to any account.', 'error');
                        else console.error('Passkey not linked to any account.');
                    }
                });

        } catch (err) {
            console.error("Passkey Login Failed", err);
            // alert("Login Cancelled or Failed");
        }
    };

    // --- 2. Registration Flow (From Dashboard) ---
    window.createPasskey = async () => {
        return new Promise(async (resolve, reject) => {
            try {
                const challenge = new Uint8Array(32);
                window.crypto.getRandomValues(challenge);

                const options = {
                    challenge: challenge,
                    rp: { name: "Dogology Learning" },
                    user: {
                        id: Uint8Array.from(window.dogologyUser ? window.dogologyUser.id : "guest", c => c.charCodeAt(0)),
                        name: window.dogologyUser ? (window.dogologyUser.username || window.dogologyUser.email || "guest@dogology.org") : "guest@dogology.org",
                        displayName: window.dogologyUser ? (window.dogologyUser.displayName || window.dogologyUser.username || "Guest") : "Guest"
                    },
                    pubKeyCredParams: [{ type: "public-key", alg: -7 }],
                    timeout: 60000,
                    authenticatorSelection: { authenticatorAttachment: "platform" } // Force TouchID/FaceID
                };

                const credential = await navigator.credentials.create({ publicKey: options });

                const credentialId = btoa(String.fromCharCode(...new Uint8Array(credential.rawId)));

                // Save to Backend
                const formData = new FormData();
                formData.append('action', 'register_passkey');
                formData.append('credential_id', credentialId);
                if (window.dlNonce) formData.append('_dl_nonce', window.dlNonce);

                fetch(window.location.href, { method: 'POST', body: formData })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            // Don't reload - let caller handle redirect
                            resolve(data);
                        } else {
                            if (typeof dlToast === 'function') dlToast(data.message || 'Failed to save Passkey.', 'error');
                            reject(new Error(data.message || 'Failed to save Passkey'));
                        }
                    })
                    .catch(err => {
                        console.error("Passkey save error", err);
                        reject(err);
                    });

            } catch (err) {
                console.error("Passkey Reg Failed", err);
                reject(err);
            }
        });
    };

    // Bind Login Button
    const btnLogin = document.getElementById('btn-passkey');
    if (btnLogin) {
        btnLogin.addEventListener('click', function (e) {
            e.preventDefault();
            window.startPasskeyLogin();
        });
    }

    // Bind Register Button (Dashboard)
    const btnReg = document.getElementById('btn-register-passkey') || document.getElementById('btn-register-passkey-menu');
    if (btnReg) {
        btnReg.addEventListener('click', function (e) {
            e.preventDefault();
            window.createPasskey();
        });
    }
});
