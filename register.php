<?php
// 1. PHP BACKEND LOGIC
error_reporting(E_ALL);
ini_set('display_errors', 1);
require 'connection.php';

// Validate CSRF
validate_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // --- Validation ---
    if (!$full_name || !$email || !$password) {
        echo json_encode(["status" => "error", "message" => "All fields are required."]);
        exit;
    }
    if (strlen($password) < 8) {
        echo json_encode(["status" => "error", "message" => "Password must be at least 8 characters."]);
        exit;
    }
    if (!preg_match('/[A-Z]/', $password)) {
        echo json_encode(["status" => "error", "message" => "Password must contain at least one uppercase letter."]);
        exit;
    }
    if (!preg_match('/[a-z]/', $password)) {
        echo json_encode(["status" => "error", "message" => "Password must contain at least one lowercase letter."]);
        exit;
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        echo json_encode(["status" => "error", "message" => "Password must contain at least one symbol."]);
        exit;
    }

    // --- Check Duplicate ---
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email=?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        echo json_encode(["status" => "error", "message" => "Email already registered."]);
        exit;
    }

    // --- Prepare Data ---
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $qr_code = bin2hex(random_bytes(8));
    $verification_token = bin2hex(random_bytes(16));

    try {
        // --- FIXED SQL: Changed 0 to FALSE for PostgreSQL compatibility ---
        // Also store qr_code into qr_image so Supabase has it for every new user
        $stmt = $pdo->prepare("
            INSERT INTO users (full_name, email, password, role, status, qr_code, qr_image, is_verified, verification_token)
            VALUES (?, ?, ?, 'member', 'active', ?, ?, FALSE, ?)
        ");
        $stmt->execute([$full_name, $email, $hashedPassword, $qr_code, $qr_code, $verification_token]);

        // --- BUILD VERIFICATION LINK (FIXED FOR SUBFOLDERS) ---
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'];

        // This part automatically detects if your file is inside a folder like /Gym1/
        $currentDir = str_replace('\\', '/', dirname($_SERVER['PHP_SELF']));
        if ($currentDir == '/') { $currentDir = ''; }

        $verify_link = "$protocol://$host$currentDir/verify_email.php?token=$verification_token";
        $htmlContent = "<h3>Hi $full_name,</h3><p>Please click the button to verify your account:</p><a href='$verify_link' style='background:#e63946;color:#fff;padding:12px 25px;text-decoration:none;border-radius:5px;display:inline-block;'>VERIFY MY ACCOUNT</a>";

        require_once __DIR__ . '/includes/brevo_send.php';
        $result = brevo_send_email($email, $full_name, "Verify Your Email - Arts Gym", $htmlContent);

        if ($result['success']) {
            echo json_encode(["status" => "success", "message" => "Registration successful! Check your email."]);
        } else {
            echo json_encode(["status" => "error", "message" => "Email failed: " . $result['message']]);
        }

    } catch (PDOException $e) {
        echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register | Arts Gym</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f4f4f4; display: flex; align-items: center; justify-content: center; height: 100vh; font-family: sans-serif; }
        .reg-card { background: white; padding: 30px; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); width: 100%; max-width: 400px; }
        .btn-danger { background-color: #e63946; border: none; }
    </style>
</head>
<body>

    <div class="reg-card">
        <h3 class="text-center fw-bold mb-4">ARTS GYM</h3>
        <form id="registerForm">
            <div class="mb-3">
                <label class="small fw-bold">Full Name</label>
                <input type="text" name="full_name" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="small fw-bold">Email Address</label>
                <input type="email" name="email" class="form-control" required>
            </div>
            <div class="mb-4">
                <label class="small fw-bold">Password</label>
                <input type="password" name="password" class="form-control" placeholder="Min 8 characters" required minlength="8">
            </div>

            <button type="submit" id="regBtn" class="btn btn-danger w-100 fw-bold py-2">
                <span id="btnText">CREATE ACCOUNT</span>
                <div id="btnSpinner" class="spinner-border spinner-border-sm d-none" role="status"></div>
            </button>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const btn = document.getElementById('regBtn');
            const btnText = document.getElementById('btnText');
            const btnSpinner = document.getElementById('btnSpinner');

            btn.disabled = true;
            btnText.innerText = "SENDING...";
            btnSpinner.classList.remove('d-none');

            const formData = new FormData(this);
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                btn.disabled = false;
                btnText.innerText = "CREATE ACCOUNT";
                btnSpinner.classList.add('d-none');

                if (data.status === "success") {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        html: `${data.message}<br><br><strong>Please settle your payment at the counter to log in to the system.</strong>`,
                        confirmButtonColor: '#e63946'
                    }).then(() => {
                        window.location.href = 'login.php';
                    });
                } else {
                    Swal.fire('Error!', data.message, 'error');
                }
            })
            .catch(err => {
                btn.disabled = false;
                btnText.innerText = "CREATE ACCOUNT";
                btnSpinner.classList.add('d-none');
                Swal.fire('Error!', 'Check your internet or database connection.', 'error');
            });
        });
    </script>
</body>
</html>