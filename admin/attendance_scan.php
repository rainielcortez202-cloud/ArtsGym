<?php
require '../auth.php';
require '../connection.php';
include '../global_clock.php';

// Only staff and admin can access
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['staff','admin'])) {
    header("Location: ../login1.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admin Attendance Scan</title>
<style>
body { margin:0; font-family:Arial, sans-serif; background:#f5f5f5; color:#333; }
header { background:#1c1c1c; color:white; padding:15px 30px; display:flex; align-items:center; }
header h1 { flex:1; font-size:24px; }
.container { padding:20px; text-align:center; }
#qr-reader { margin:20px auto; width:300px; }
#qr-result { font-weight:bold; margin-top:15px; }
.message { padding:10px; border-radius:5px; margin:10px auto; width:300px; }
.success { background:#008000; color:white; }
.error { background:#b00000; color:white; }
.warning { background:#FFA500; color:black; }
</style>
<!-- Load HTML5 QR Code library -->
<script src="https://unpkg.com/html5-qrcode@2.3.7/minified/html5-qrcode.min.js"></script>
</head>
<body>

<header>
    <h1>Attendance Scanner (Admin)</h1>
</header>

<div class="container">
    <h2>Scan Member QR Code</h2>
    <div id="qr-reader"></div>
    <div id="qr-result"></div>
</div>

<!-- Hidden Input for Physical Scanner -->
<input type="text" id="qr-input" style="opacity:0; position:absolute; z-index:-1;" autocomplete="off">

<script>
function showMessage(text, type){
    const resultDiv = document.getElementById('qr-result');
    resultDiv.innerHTML = "<div class='message "+type+"'>"+text+"</div>";
}

let isProcessing = false;

// --- PHYSICAL SCANNER LOGIC ---
const qrInput = document.getElementById('qr-input');
// Keep focus
document.addEventListener('click', () => { qrInput.focus(); });
qrInput.focus();

// Handle "Enter" key
qrInput.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        const code = this.value.trim();
        if (code) {
            handleScan(code);
        }
        this.value = ''; 
    }
});

// --- WEBCAM SCANNER LOGIC ---
function onScanSuccess(decodedText, decodedResult){
    handleScan(decodedText);
}

// --- UNIFIED HANDLER ---
function handleScan(code) {
    if (isProcessing) return;
    isProcessing = true;

    showMessage("Processing...", "success");

    fetch('attendance_endpoint.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ qr_code: code })
    })
    .then(res => res.json())
    .then(data => {
        if(data.status === 'success'){
            showMessage("✅ Recorded: " + data.name, "success");
            setTimeout(() => { isProcessing = false; showMessage("Ready to Scan", "success"); }, 2000);
        } else if (data.status === 'warning') {
            showMessage("⚠️ " + data.message, "warning");
            setTimeout(() => { isProcessing = false; }, 3000);
        } else {
            showMessage("❌ " + data.message, "error");
            setTimeout(() => { isProcessing = false; }, 3000);
        }
    })
    .catch(err => {
        showMessage("Error: " + err, "error");
        isProcessing = false;
    });
}

// Scanner configuration
let html5QrcodeScanner = new Html5QrcodeScanner(
    "qr-reader",
    { fps: 10, qrbox: 250 }
);

html5QrcodeScanner.render(onScanSuccess);

// process any pending code saved before redirect
document.addEventListener('DOMContentLoaded', () => {
    const pending = sessionStorage.getItem('pending_qr');
    if (pending) {
        sessionStorage.removeItem('pending_qr');
        handleScan(pending);
    }
});
</script>

</body>
</html>
