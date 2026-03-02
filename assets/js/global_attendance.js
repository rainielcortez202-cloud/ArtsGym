/**
 * Global Attendance System (The Brain)
 * Handles HID Scanner input from ANY page.
 */

(function () {
    console.log("Global Attendance System Loaded");

    let buffer = '';
    let lastKeyTime = Date.now();
    const SCAN_TIMEOUT = 100; // ms - Relaxed for screen scanning capabilities
    const MIN_LENGTH = 6;    // Minimum length of a QR code

    // --- UTILS ---
    function showToast(message, type = 'info') {
        const colors = {
            'success': '#10b981', // Emerald 500
            'warning': '#f59e0b', // Amber 500
            'error': '#ef4444', // Red 500
            'info': '#3b82f6'  // Blue 500
        };

        let icon = 'ℹ️';
        if (type === 'success') icon = '✅';
        if (type === 'warning') icon = '⚠️';
        if (type === 'error') icon = '⛔';

        const color = colors[type] || colors['info'];

        // Remove existing toast
        const existing = document.getElementById('global-toast');
        if (existing) existing.remove();

        const toast = document.createElement('div');
        toast.id = 'global-toast';
        toast.style.cssText = `
            position: fixed; top: 30px; left: 50%; transform: translateX(-50%) translateY(-20px);
            background: rgba(255, 255, 255, 0.98); color: #1f2937; padding: 16px 24px;
            border-radius: 12px; z-index: 10000; font-weight: 600;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15); font-family: 'Inter', system-ui, sans-serif;
            border: 1px solid rgba(0,0,0,0.05); display: flex; align-items: center; gap: 12px;
            opacity: 0; transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1); pointer-events: none;
            backdrop-filter: blur(8px); min-width: 300px; justify-content: flex-start;
            border-left: 5px solid ${color};
        `;

        toast.innerHTML = `<span style="font-size: 1.4em; line-height: 1;">${icon}</span> <span style="font-size: 0.95em;">${message}</span>`;
        document.body.appendChild(toast);

        // Animate In
        requestAnimationFrame(() => {
            toast.style.opacity = '1';
            toast.style.transform = 'translateX(-50%) translateY(0)';
        });

        // Animate Out
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(-50%) translateY(-10px)';
            setTimeout(() => toast.remove(), 300);
        }, 3500);
    }

    // --- ACTIONS ---

    function getBasePath() {
        // Detect if we are in a subdirectory like /Gym1/ or /ArtsGym-main/
        // Just return root relative path, or attempt to detect
        const path = window.location.pathname;
        if (path.includes('/Gym1/')) return '/Gym1/';
        if (path.includes('/ArtsGym-main/')) return '/ArtsGym-main/';
        return '/';
    }

    function handleScan(code) {
        console.log("Scanned:", code);
        const basePath = getBasePath();

        function postponeScan() {
            sessionStorage.setItem('pending_qr', code);
        }

        showToast("Pending...", "info");

        fetch(basePath + 'login.php?check_session=1', { credentials: 'same-origin' })
            .then(response => {
                return response.text().then(text => {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error("Invalid JSON from login.php:", text);
                        throw new Error("Invalid JSON response");
                    }
                });
            })
            .then(roleInfo => {
                const role = roleInfo.role;
                if (!role) {
                    postponeScan();
                    sessionStorage.setItem('pending_redirect', window.location.href);
                    showToast("Please login to record attendance", "warning");
                    if (!window.location.href.includes('login.php')) {
                        setTimeout(() => window.location.href = basePath + 'login.php?attendance=1', 1000);
                    }
                    return;
                }

                const endpoint = basePath + 'admin/attendance_endpoint.php';

                showToast("Processing Scan...", "info");

                fetch(endpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ qr_code: code })
                })
                .then(response => {
                        return response.text().then(text => {
                            try {
                                return JSON.parse(text);
                            } catch (e) {
                                return { status: 'not_logged_in', raw: text };
                            }
                        });
                    })
                    .then(data => {
                        if (data.status === 'success') {
                            showToast(`${data.message}<br><small>${data.name || ''}</small>`, 'success');
                            if (window.location.href.includes('attendance')) {
                                setTimeout(() => location.reload(), 1000);
                            }
                        } else if (data.status === 'warning') {
                            showToast(`${data.message}`, 'warning');
                        } else if (data.status === 'not_logged_in') {
                            postponeScan();
                            sessionStorage.setItem('pending_redirect', window.location.href);
                            showToast("Please Login to Record Attendance", "warning");
                            if (!window.location.href.includes('login.php')) {
                                setTimeout(() => window.location.href = basePath + 'login.php?attendance=1', 1000);
                            }
                        } else {
                            showToast(`${data.message}`, 'error');
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        showToast("System Error. See Console.", 'error');
                    });
            })
            .catch(e => {
                    console.error("Attendance scan failed:", e);
                    postponeScan();
                    sessionStorage.setItem('pending_redirect', window.location.href);
                    showToast("Scan failed or login required", "error");
                    if (!window.location.href.includes('login.php')) {
                        setTimeout(() => window.location.href = basePath + 'login.php?attendance=1', 1500);
                    }
                });
    }

    // --- EVENT LISTENER ---
    document.addEventListener('keydown', function (e) {
        const currentTime = Date.now();
        const target = e.target;

        if (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA') {
            return;
        }

        if (currentTime - lastKeyTime > SCAN_TIMEOUT) {
            buffer = '';
        }
        lastKeyTime = currentTime;

        if (e.key === 'Enter') {
            if (buffer.length >= MIN_LENGTH) {
                e.preventDefault();
                const code = buffer;
                buffer = '';
                handleScan(code);
            }
        }
        else if (e.key.length === 1) {
            buffer += e.key;
        }
    });


    // --- ON LOAD: CHECK PENDING ---
    document.addEventListener('DOMContentLoaded', () => {
        const pending = sessionStorage.getItem('pending_qr');
        if (pending) {
            if (!window.location.href.includes('login.php')) {
                console.log("Found pending QR from storage:", pending);
                sessionStorage.removeItem('pending_qr');
                handleScan(pending);
            }
        }
    });

})();
