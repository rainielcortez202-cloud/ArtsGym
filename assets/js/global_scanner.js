/**
 * Global Scanner Listener
 * Detects HID scanner input (rapid keystrokes ending in Enter)
 * and handles redirection/storage.
 */

(function() {
    let buffer = '';
    let lastKeyTime = Date.now();
    const SCAN_TIMEOUT = 50; // ms between keys for it to be considered a scan
    const MIN_LENGTH = 6;    // Minimum length of a QR code

    function getBasePath() {
        const path = window.location.pathname;
        if (path.includes('/Gym1/')) return '/Gym1/';
        if (path.includes('/ArtsGym-main/')) return '/ArtsGym-main/';
        return '/';
    }

    document.addEventListener('keydown', function(e) {
        const currentTime = Date.now();
        const target = e.target;

        // 1. If user is typing in an input field, DO NOT interfere
        if (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA') {
            return; 
        }

        // 2. Check timing (Scanner types very fast)
        if (currentTime - lastKeyTime > SCAN_TIMEOUT) {
            buffer = ''; // Reset buffer if too slow (manual typing)
        }
        lastKeyTime = currentTime;

        // 3. Handle Enter (End of Scan)
        if (e.key === 'Enter') {
            if (buffer.length >= MIN_LENGTH) {
                handleScan(buffer);
                buffer = ''; // Clear
                e.preventDefault(); // Stop default Enter behavior
            }
        } 
        // 4. Accumulate characters
        else if (e.key.length === 1) { // Ignore special keys like Shift, Ctrl
            buffer += e.key;
        }
    });

    function handleScan(code) {
        console.log("Global Scan Detected:", code);
        const basePath = getBasePath();
        
        // Store the code
        sessionStorage.setItem('pending_qr', code);

        // Visual Feedback
        showToast("QR Code Detected! Processing...");

        const currentPath = window.location.pathname;
        if (currentPath.includes('login.php')) {
            return;
        }
        fetch(basePath + 'login.php?check_session=1', { credentials: 'same-origin' })
            .then(response => response.text())
            .then(text => {
                try {
                    const roleInfo = JSON.parse(text);
                    const role = roleInfo.role;
                    if (!role) {
                        sessionStorage.setItem('pending_redirect', window.location.href);
                        window.location.href = basePath + 'login.php?attendance=1';
                    }
                } catch (e) {
                    sessionStorage.setItem('pending_redirect', window.location.href);
                    window.location.href = basePath + 'login.php?attendance=1';
                }
            })
            .catch(() => {
                sessionStorage.setItem('pending_redirect', window.location.href);
                window.location.href = basePath + 'login.php?attendance=1';
            });
    }

    function showToast(message) {
        // Simple toast
        let toast = document.createElement('div');
        toast.style.position = 'fixed';
        toast.style.top = '20px';
        toast.style.left = '50%';
        toast.style.transform = 'translateX(-50%)';
        toast.style.background = '#e63946';
        toast.style.color = 'white';
        toast.style.padding = '15px 30px';
        toast.style.borderRadius = '30px';
        toast.style.zIndex = '9999';
        toast.style.fontWeight = 'bold';
        toast.style.boxShadow = '0 5px 15px rgba(0,0,0,0.3)';
        toast.innerText = message;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 2000);
    }
})();
