<?php
session_start();
require '../auth.php';
require '../connection.php';

if ($_SESSION['role'] !== 'staff') { header("Location: ../login.php"); exit; }

$user_id = $_SESSION['user_id'];
$message = "";
$status = "";

// Helper function to mask email (show first 3 and last 3 chars before @)
function maskEmail($email) {
    if (!$email) return 'N/A';
    $parts = explode("@", $email);
    if (count($parts) < 2) return $email;
    $name = $parts[0];
    $len = strlen($name);
    if ($len <= 6) {
        return str_repeat('*', $len) . "@" . $parts[1];
    }
    $first = substr($name, 0, 3);
    $last = substr($name, -3);
    $middle = str_repeat('*', $len - 6);
    return $first . $middle . $last . "@" . $parts[1];
}

// 1. FETCH CURRENT DATA
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$staff = $stmt->fetch(PDO::FETCH_ASSOC);

// 2. HANDLE UPDATE (only name, contact, password; email change is separate)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name    = trim($_POST['full_name']);
    $new_password = $_POST['new_password'] ?? '';
    
    $update_success   = false;
    $change_logs      = [];
    $password_changed = !empty($new_password);
    $should_logout    = false;

    try {
        $pdo->beginTransaction();
        $stmt_upd = $pdo->prepare("UPDATE users SET full_name = ? WHERE id = ?");
        $stmt_upd->execute([$full_name, $user_id]);
        $_SESSION['name'] = $full_name;

        // Log profile update if name changed
        if ($full_name !== $staff['full_name']) {
            $change_logs[] = "Name: '{$staff['full_name']}' -> '$full_name'";
        }

        $final_log_details = "";
        if (!empty($change_logs)) { $final_log_details = "Changed " . implode(", ", $change_logs) . "."; }

        // Handle password change
        if ($password_changed) {
            // Validate password requirements: 8+ chars, uppercase, lowercase, symbol
            if (strlen($new_password) < 8) {
                $message = "Password must be at least 8 characters.";
                $status = "danger";
                $pdo->rollBack();
            } elseif (!preg_match('/[A-Z]/', $new_password)) {
                $message = "Password must contain at least one uppercase letter.";
                $status = "danger";
                $pdo->rollBack();
            } elseif (!preg_match('/[a-z]/', $new_password)) {
                $message = "Password must contain at least one lowercase letter.";
                $status = "danger";
                $pdo->rollBack();
            } elseif (!preg_match('/[^A-Za-z0-9]/', $new_password)) {
                $message = "Password must contain at least one symbol.";
                $status = "danger";
                $pdo->rollBack();
            } else {
                $confirm_pass = $_POST['confirm_password'] ?? '';
                if ($new_password !== $confirm_pass) {
                    $message = "Passwords do not match.";
                    $status = "danger";
                    $pdo->rollBack();
                } else {
                    $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                    $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hashed, $user_id]);
                    $final_log_details .= ($final_log_details ? " " : "") . "Password was updated.";
                    $update_success = true;
                    $should_logout = true;
                }
            }
        } elseif (!empty($change_logs)) {
            $update_success = true;
        }

        if ($update_success) {
            $stmt_log = $pdo->prepare("INSERT INTO activity_log (user_id, role, action, member_id, details) VALUES (?, 'staff', 'UPDATE_PROFILE', ?, ?)");
            $stmt_log->execute([$user_id, $user_id, $final_log_details]);
            $pdo->commit();
            $message = "Profile updated successfully!";
            $status = "success";
            $staff['full_name'] = $full_name;
        }
        // If password was changed, force logout
        if ($update_success && $should_logout) {
            session_destroy();
            header("Location: ../login.php?notice=password_updated");
            exit;
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $message = "Error updating profile.";
        $status = "danger";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>My Profile | Arts Gym</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-red: #e63946;
            --accent-blue: #4361ee;
            --bg-body: #f4f7fe;
            --bg-card: #ffffff;
            --text-main: #2b3674;
            --text-muted: #a3aed0;
            --sidebar-width: 280px;
            --card-radius: 20px;
            --shadow: 14px 17px 40px 4px rgba(112, 144, 176, 0.08);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        body.dark-mode-active {
            --bg-body: #0b1437;
            --bg-card: #111c44;
            --text-main: #ffffff;
            --text-muted: #a3aed0;
        }

        body { 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            background-color: var(--bg-body); 
            color: var(--text-main);
            transition: var(--transition);
        }

        #main {
            margin-left: var(--sidebar-width);
            transition: var(--transition);
            min-height: 100vh;
            padding: 1.5rem;
            width: calc(100% - var(--sidebar-width));
            box-sizing: border-box;
        }

        #main.expanded { margin-left: 80px; width: calc(100% - 80px); }

        /* Sidebar Layout */
        #sidebar {
            width: var(--sidebar-width);
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            z-index: 1100;
            transition: var(--transition);
        }

        .top-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            gap: 10px;
            flex-wrap: wrap;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn-action {
            background: var(--bg-card);
            border: none;
            box-shadow: var(--shadow);
            border-radius: 12px;
            width: 42px;
            height: 42px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-main);
            cursor: pointer;
            transition: var(--transition);
        }
        .btn-action:hover { background: var(--accent-blue); color: white; }

        .card-box { 
            background: var(--bg-card); 
            border-radius: var(--card-radius); 
            padding: 25px; 
            box-shadow: var(--shadow);
            max-width: 800px;
            margin: 0 auto;
        }

        .form-label {
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-control {
            border-radius: 8px;
            padding: 12px;
            border: 1px solid rgba(0,0,0,0.08);
            background-color: var(--bg-body);
            color: var(--text-main);
        }

        .form-control:focus {
            box-shadow: none;
            border-color: var(--primary-red);
        }

        .dark-mode-active .form-control {
            background-color: #1a1a1a;
            border-color: #333;
            color: white;
        }

        .security-section {
            background: rgba(0,0,0,0.02);
            padding: 20px;
            border-radius: 10px;
            border: 1px dashed rgba(0,0,0,0.1);
        }

        .dark-mode-active .security-section {
            background: rgba(255,255,255,0.02);
            border-color: rgba(255,255,255,0.1);
        }

        @media (max-width: 991.98px) {
            #main { 
                margin-left: 0 !important; 
                width: 100% !important; 
                padding: 1rem; 
            }
            #main.expanded { 
                margin-left: 0 !important; 
                width: 100% !important; 
            }
            #sidebar { left: calc(var(--sidebar-width) * -1); }
            #sidebar.show { left: 0; box-shadow: 10px 0 30px rgba(0,0,0,0.1); }
            .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.4); z-index: 1090; backdrop-filter: blur(4px); }
            .sidebar-overlay.show { display: block; }
            
            .top-header { flex-wrap: wrap; }
            .header-title { order: 1; flex: 1; }
            .header-actions { order: 2; }
        }
    </style>
</head>
<body class="<?= isset($_COOKIE['theme']) && $_COOKIE['theme'] == 'dark' ? 'dark-mode-active' : '' ?>">

    <?php include __DIR__ . '/_sidebar.php'; ?>

    <div id="main">
        <header class="top-header">
            <div class="header-title d-flex align-items-center gap-3">
                <button class="btn-action" onclick="toggleSidebar()">
                    <i class="bi bi-list fs-5"></i>
                </button>
                <div>
                    <h5 class="mb-0 fw-800">My Profile</h5>
                    <p class="text-muted small mb-0 d-none d-sm-block">Account Settings</p>
                </div>
            </div>

            <div class="header-actions">

                <div class="d-none d-md-block">
                    <?php include '../global_clock.php'; ?>
                </div>
            </div>
        </header>

        <div style="max-width: 800px; margin: 0 auto;">

            <?php if ($message): ?>
                <div class="alert alert-<?= $status ?> border-0 shadow-sm mx-auto" style="max-width: 800px;">
                    <i class="bi <?= $status === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?> me-2"></i>
                    <?= $message ?>
                </div>
            <?php endif; ?>

            <div class="card-box">
                <form method="POST">
                    <h6 class="mb-4 text-danger fw-bold"><i class="bi bi-person-circle me-2"></i>Personal Information</h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Full Name</label>
                            <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($staff['full_name']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email Address</label>
                            <div class="d-flex align-items-center gap-2">
                                <input type="text" class="form-control" value="<?= htmlspecialchars(maskEmail($staff['email'])) ?>" disabled style="flex: 1;">
                                <button type="button" class="btn btn-outline-danger btn-sm" onclick="openEmailChangeModal()">
                                    <i class="bi bi-envelope me-1"></i>Request Change
                                </button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">System Role</label>
                            <input type="text" class="form-control opacity-75" value="STAFF" readonly>
                        </div>
                    </div>

                    <div class="security-section mt-5">
                        <h6 class="mb-3 fw-bold"><i class="bi bi-shield-lock me-2"></i>Security Update</h6>
                        <div class="row">
                            <div class="col-12">
                                <label class="form-label">New Password</label>
                                <input type="password" name="new_password" id="newPasswordInput" class="form-control" placeholder="Leave blank to keep current password" minlength="8">
                                <div class="form-text mt-2 small">
                                    Must be 8+ characters with at least one uppercase, one lowercase, and one symbol. Changing your password will immediately log you out and require you to sign in again.
                                </div>
                            </div>
                            <div class="col-12" id="confirmPasswordGroup" style="display: none;">
                                <label class="form-label">Confirm Password</label>
                                <input type="password" name="confirm_password" id="confirmPasswordInput" class="form-control" placeholder="Re-enter password" minlength="8">
                            </div>
                        </div>
                    </div>

                    <div class="mt-4">
                        <button type="submit" class="btn btn-danger w-100 fw-bold py-3">
                            <i class="bi bi-cloud-check me-2"></i>UPDATE ACCOUNT SETTINGS
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Email Change Modal -->
    <div class="modal fade" id="emailChangeModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold">Request Email Change</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Current Email</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars(maskEmail($staff['email'])) ?>" disabled>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New Email Address</label>
                        <input type="email" id="newEmailInput" class="form-control" placeholder="Enter new email address" required>
                        <div class="form-text">A confirmation email will be sent to your current email address.</div>
                    </div>
                    <div id="emailChangeError" class="alert alert-danger d-none" role="alert"></div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="submitEmailChange()">
                        <span id="emailChangeBtnText">Request Change</span>
                        <span id="emailChangeSpinner" class="spinner-border spinner-border-sm d-none"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Sidebar Toggle
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const main = document.getElementById('main');

            if (window.innerWidth < 992) {
                const overlay = document.querySelector('.sidebar-overlay') || document.createElement('div');
                overlay.classList.toggle('show');
                sidebar.classList.toggle('show');
            } else {
                sidebar.classList.toggle('collapsed');
                main.classList.toggle('expanded');
            }
        }

        // Dark Mode Toggle
        function toggleDarkMode() {
            const isDark = !document.body.classList.contains('dark-mode-active');
            document.cookie = "theme=" + (isDark ? "dark" : "light") + ";path=/;max-age=" + (30*24*60*60);
            location.reload(); 
        }

        // Show/hide confirm password field
        document.getElementById('newPasswordInput').addEventListener('input', function() {
            const confirmGroup = document.getElementById('confirmPasswordGroup');
            if (this.value.length > 0) {
                confirmGroup.style.display = 'block';
                document.getElementById('confirmPasswordInput').required = true;
            } else {
                confirmGroup.style.display = 'none';
                document.getElementById('confirmPasswordInput').required = false;
                document.getElementById('confirmPasswordInput').value = '';
            }
        });

        // Email Change Modal
        const emailChangeModal = new bootstrap.Modal(document.getElementById('emailChangeModal'));

        function openEmailChangeModal() {
            document.getElementById('newEmailInput').value = '';
            document.getElementById('emailChangeError').classList.add('d-none');
            emailChangeModal.show();
        }

        function submitEmailChange() {
            const newEmail = document.getElementById('newEmailInput').value.trim();
            const errorDiv = document.getElementById('emailChangeError');
            const btn = document.querySelector('#emailChangeModal .btn-danger');
            const btnText = document.getElementById('emailChangeBtnText');
            const spinner = document.getElementById('emailChangeSpinner');

            if (!newEmail || !newEmail.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
                errorDiv.textContent = 'Please enter a valid email address';
                errorDiv.classList.remove('d-none');
                return;
            }

            btn.disabled = true;
            btnText.textContent = 'Sending...';
            spinner.classList.remove('d-none');
            errorDiv.classList.add('d-none');

            $.ajax({
                url: '../request_email_change.php',
                method: 'POST',
                data: { new_email: newEmail },
                dataType: 'json',
                success: function(response) {
                    if (response.status === 'success') {
                        emailChangeModal.hide();
                        alert(response.message);
                        location.reload();
                    } else {
                        errorDiv.textContent = response.message || 'An error occurred';
                        errorDiv.classList.remove('d-none');
                        btn.disabled = false;
                        btnText.textContent = 'Request Change';
                        spinner.classList.add('d-none');
                    }
                },
                error: function() {
                    errorDiv.textContent = 'Network error. Please try again.';
                    errorDiv.classList.remove('d-none');
                    btn.disabled = false;
                    btnText.textContent = 'Request Change';
                    spinner.classList.add('d-none');
                }
            });
        }
    </script>
</body>
</html>