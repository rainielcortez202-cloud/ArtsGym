<?php
session_start();
require '../auth.php';
require '../connection.php';

if ($_SESSION['role'] !== 'member') { 
    header("Location: ../login.php"); 
    exit; 
}

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

// Fetch current user data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Handle form submission (only name and password now; email change is separate)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name']);
    $new_pass  = $_POST['new_password'] ?? '';
    
    $password_changed = !empty($new_pass);
    $should_logout   = false;

    try {
        $pdo->beginTransaction();
        
        $pdo->prepare("UPDATE users SET full_name = ? WHERE id = ?")->execute([$full_name, $user_id]);

        if ($password_changed) {
            // Validate password requirements: 8+ chars, uppercase, lowercase, symbol
            if (strlen($new_pass) < 8) {
                throw new Exception("Password must be at least 8 characters.");
            }
            if (!preg_match('/[A-Z]/', $new_pass)) {
                throw new Exception("Password must contain at least one uppercase letter.");
            }
            if (!preg_match('/[a-z]/', $new_pass)) {
                throw new Exception("Password must contain at least one lowercase letter.");
            }
            if (!preg_match('/[^A-Za-z0-9]/', $new_pass)) {
                throw new Exception("Password must contain at least one symbol.");
            }
            
            $confirm_pass = $_POST['confirm_password'] ?? '';
            if ($new_pass !== $confirm_pass) {
                throw new Exception("Passwords do not match.");
            }
            
            $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hashed, $user_id]);
            $should_logout = true; 
        }
        
        $pdo->commit();

        if ($should_logout) {
            session_destroy();
            header("Location: ../login.php?notice=password_updated");
            exit;
        }

        $_SESSION['name'] = $full_name;
        header("Location: profile.php?success=1");
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $message = $e->getMessage();
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
    
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&family=Oswald:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        :root {
            --primary-red: #e63946;
            --dark-red: #9d0208;
            --bg-body: #f8f9fa;
            --bg-card: #ffffff;
            --text-main: #212529;
            --text-muted: #6c757d;
            --sidebar-width: 260px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        body.dark-mode-active {
            --bg-body: #050505;
            --bg-card: #111111;
            --text-main: #ffffff;
            --text-muted: #b0b0b0;
        }

        body { 
            font-family: 'Poppins', sans-serif; 
            background-color: var(--bg-body); 
            color: var(--text-main);
            transition: var(--transition);
        }

        h1, h2, h3, h5 { font-family: 'Oswald', sans-serif; text-transform: uppercase; letter-spacing: 1px; }

        /* Synchronized Layout */
        #sidebar { width: var(--sidebar-width); height: 100vh; position: fixed; left: 0; top: 0; z-index: 1100; transition: var(--transition); }
        #main {
            margin-left: var(--sidebar-width);
            transition: var(--transition);
            min-height: 100vh;
            padding: 2rem;
            width: calc(100% - var(--sidebar-width));
            box-sizing: border-box;
        }

        #main.expanded { margin-left: 80px; }
        #sidebar.collapsed { width: 80px; }

        @media (max-width: 991.98px) {
            #main { margin-left: 0 !important; padding: 1.5rem; width: 100% !important; }
            #main.expanded { margin-left: 0 !important; width: 100% !important; }
            #sidebar { left: calc(var(--sidebar-width) * -1); position: fixed; z-index: 1100; height: 100vh; width: var(--sidebar-width); transition: var(--transition); }
            #sidebar.show { left: 0; }
            .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1090; }
            .sidebar-overlay.show { display: block; }
            .top-header { flex-wrap: wrap; }
            .header-title { order: 1; flex: 1; }
            .header-actions { order: 2; }
            .mobile-clock-container { order: 3; width: 100%; margin-top: 10px; }
        }

        /* Top Header */
        .top-header {
            background: var(--bg-card);
            padding: 15px 25px;
            border-bottom: 1px solid rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky; top: 0; z-index: 1000;
        }
        .header-actions { display: flex; align-items: center; gap: 10px; }
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

        /* Profile Card */
        .profile-card { 
            background: var(--bg-card); 
            border-radius: 20px; 
            padding: 40px; 
            box-shadow: 0 10px 30px rgba(0,0,0,0.05); 
            max-width: 650px; 
            margin: 20px auto; 
            border: 1px solid rgba(0,0,0,0.05);
        }

        .form-control {
            background-color: var(--bg-body);
            border: 1px solid rgba(0,0,0,0.1);
            color: var(--text-main);
            padding: 12px 16px;
            border-radius: 10px;
            transition: 0.3s;
        }

        .dark-mode-active .form-control {
            border-color: rgba(255,255,255,0.1);
            background-color: rgba(255,255,255,0.05);
            color: white;
        }

        .form-control:focus {
            background-color: var(--bg-body);
            color: var(--text-main);
            border-color: var(--primary-red);
            box-shadow: none;
        }

        .warning-box { 
            background: rgba(230, 57, 70, 0.05); 
            border: 1px solid rgba(230, 57, 70, 0.1); 
            padding: 20px; 
            border-radius: 12px; 
            margin-bottom: 25px; 
        }

        .btn-brand {
            background: linear-gradient(135deg, var(--primary-red), var(--dark-red));
            color: white !important;
            border: none;
            padding: 15px;
            border-radius: 10px;
            font-family: 'Oswald', sans-serif;
            text-transform: uppercase;
            font-weight: bold;
            width: 100%;
            transition: 0.3s;
        }

        .btn-brand:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(230, 57, 70, 0.4);
        }

        .status-input {
            background-color: rgba(0,0,0,0.05) !important;
            border: none;
            font-weight: 600;
        }
        .dark-mode-active .status-input {
            background-color: rgba(255,255,255,0.05) !important;
        }
    </style>
</head>
<body class="<?= isset($_COOKIE['theme']) && $_COOKIE['theme'] == 'dark' ? 'dark-mode-active' : '' ?>">

    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
    <!-- INCLUDE UNIFIED SIDEBAR -->
    <?php include __DIR__ . '/_sidebar.php'; ?>

    <div id="main">
        <!-- Top Header -->
        <header class="top-header">
            <div class="header-title d-flex align-items-center gap-3">
                <button class="btn-action" onclick="toggleSidebar()">
                    <i class="bi bi-list fs-5"></i>
                </button>
                <div>
                    <h5 class="mb-0 fw-800">User Settings</h5>
                </div>
            </div>

            <div class="header-actions">
                <div class="d-none d-md-block">
                    <?php include '../global_clock.php'; ?>
                </div>
            </div>

            <div class="mobile-clock-container d-md-none text-center">
                 <?php include '../global_clock.php'; ?>
            </div>
        </header>

        <div class="container-fluid">
            <div class="text-center mb-4">
                <h2 class="fw-bold mb-1">Account Profile</h2>
                <p class="text-secondary small fw-bold text-uppercase">Manage your personal information</p>
            </div>

            <?php if (isset($_GET['success'])): ?> 
                <div class="alert alert-success border-0 shadow-sm mx-auto mb-4" style="max-width: 650px;">
                    <i class="bi bi-check-circle-fill me-2"></i> Profile name updated successfully!
                </div> 
            <?php endif; ?>
            
            <?php if ($message): ?> 
                <div class="alert alert-danger border-0 shadow-sm mx-auto mb-4" style="max-width: 650px;">
                    <i class="bi bi-exclamation-circle-fill me-2"></i> <?= $message ?>
                </div> 
            <?php endif; ?>

            <div class="profile-card">
                <div class="warning-box d-flex align-items-start gap-3">
                    <i class="bi bi-shield-lock-fill text-danger fs-3"></i>
                    <div>
                        <p class="small m-0 fw-bold text-danger">SECURITY POLICY</p>
                        <p class="small m-0 text-muted">
                            Changing your <b>Password</b> will immediately log you out and require you to log in again.
                            Changing your <b>Email</b> requires approval from your current email, then verification of the new email. You will be logged out after verification.
                        </p>
                    </div>
                </div>

                <form method="POST">
                    <div class="mb-4">
                        <label class="small fw-bold text-uppercase mb-2">Display Name</label>
                        <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($user['full_name']) ?>" required>
                    </div>

                    <div class="mb-4">
                        <label class="small fw-bold text-uppercase mb-2">Email Address</label>
                        <div class="d-flex align-items-center gap-3">
                            <input type="text" class="form-control" value="<?= htmlspecialchars(maskEmail($user['email'])) ?>" disabled style="flex: 1;">
                            <button type="button" class="btn btn-outline-danger" onclick="openEmailChangeModal()">
                                <i class="bi bi-envelope me-1"></i>Request Email Change
                            </button>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="small fw-bold text-uppercase mb-2">New Password</label>
                        <input type="password" name="new_password" id="newPasswordInput" class="form-control" placeholder="Leave blank to keep current" minlength="8">
                        <p class="text-muted mt-2 mb-0" style="font-size: 0.75rem;">
                            Must be 8+ characters with at least one uppercase, one lowercase, and one symbol.
                        </p>
                    </div>
                    
                    <div class="mb-4" id="confirmPasswordGroup" style="display: none;">
                        <label class="small fw-bold text-uppercase mb-2">Confirm Password</label>
                        <input type="password" name="confirm_password" id="confirmPasswordInput" class="form-control" placeholder="Re-enter password" minlength="8">
                    </div>

                    <div class="mb-4">
                        <label class="small fw-bold text-uppercase mb-2">Membership Status</label>
                        <input type="text" class="form-control status-input text-muted" value="Verified Member since <?= date('M d, Y', strtotime($user['created_at'])) ?>" disabled>
                    </div>
                    
                    <button type="submit" class="btn btn-brand py-3 mt-2">
                        Save Changes
                    </button>
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
                        <label class="small fw-bold text-uppercase mb-2">Current Email</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars(maskEmail($user['email'])) ?>" disabled>
                    </div>
                    <div class="mb-3">
                        <label class="small fw-bold text-uppercase mb-2">New Email Address</label>
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
        // Synchronized Dark Mode
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const main = document.getElementById('main');
            const overlay = document.getElementById('overlay');
            const isMobile = window.innerWidth <= 991.98;
            
            if (isMobile) {
                sidebar.classList.toggle('show');
                overlay.classList.toggle('show');
            } else {
                sidebar.classList.toggle('collapsed');
                main.classList.toggle('expanded');
            }
        }

        function toggleDarkMode() {
            const isDark = !document.body.classList.contains('dark-mode-active');
            document.cookie = "theme=" + (isDark ? "dark" : "light") + ";path=/;max-age=" + (30*24*60*60);
            location.reload(); 
        }

        // Email Change Modal
        const emailChangeModal = new bootstrap.Modal(document.getElementById('emailChangeModal'));

        function openEmailChangeModal() {
            document.getElementById('newEmailInput').value = '';
            document.getElementById('emailChangeError').classList.add('d-none');
            emailChangeModal.show();
        }

        // Toggle Sidebar
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const main = document.getElementById('main');
            const overlay = document.getElementById('sidebarOverlay');
            const isMobile = window.innerWidth <= 991.98;
            
            if (isMobile) {
                sidebar.classList.toggle('show');
                overlay.classList.toggle('show');
            } else {
                sidebar.classList.toggle('collapsed');
                main.classList.toggle('expanded');
            }
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