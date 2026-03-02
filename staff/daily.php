<?php
// staff/daily.php
session_start();
require '../auth.php';
require '../connection.php';

// --- AJAX REQUEST HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    header('Content-Type: application/json');
    if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'staff'])) {
        echo json_encode(["status" => "error", "message" => "Unauthorized"]);
        exit;
    }

    $name = trim($_POST['name'] ?? '');
    $daily_rate = 40; 
    try {
        $rate_stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $rate_stmt->execute(['daily_walkin_rate']);
        $rate_result = $rate_stmt->fetch(PDO::FETCH_ASSOC);
        if ($rate_result) { $daily_rate = floatval($rate_result['setting_value']); }
    } catch (PDOException $e) { }

    $amount = $daily_rate;
    if (empty($name)) {
        echo json_encode(["status" => "error", "message" => "Visitor name is required."]);
        exit;
    }

    try {
        $pdo->beginTransaction();
        $stmt_walkin = $pdo->prepare("INSERT INTO walk_ins (visitor_name, amount, checked_in_by) VALUES (?, ?, ?)");
        $stmt_walkin->execute([$name, $amount, $_SESSION['user_id']]);
        
        $stmt_sales = $pdo->prepare("INSERT INTO sales (user_id, amount, sale_date) VALUES (NULL, ?, NOW())");
        $stmt_sales->execute([$amount]);

        $stmt_att = $pdo->prepare("INSERT INTO attendance (user_id, visitor_name, date, time_in, attendance_date) VALUES (NULL, ?, CURRENT_DATE, NOW(), CURRENT_DATE)");
        $stmt_att->execute([$name]);
        
        logActivity($pdo, $_SESSION['user_id'], $_SESSION['role'], 'ADD_WALKIN', "Added walk-in visitor: $name (₱$amount)");

        $pdo->commit();
        echo json_encode(["status" => "success", "message" => "Walk-in recorded!"]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        echo json_encode(["status" => "error", "message" => "Database error."]);
    }
    exit;
}

if ($_SESSION['role'] !== 'staff') { header("Location: ../login.php"); exit; }

$daily_rate = 40;
try {
    $rate_stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $rate_stmt->execute(['daily_walkin_rate']);
    $rate_result = $rate_stmt->fetch(PDO::FETCH_ASSOC);
    if ($rate_result) { $daily_rate = floatval($rate_result['setting_value']); }
} catch (PDOException $e) { }

$today = date('Y-m-d');
$walkins = $pdo->prepare("SELECT * FROM walk_ins WHERE visit_date::DATE = ? ORDER BY visit_date DESC");
$walkins->execute([$today]);
$walkins = $walkins->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Daily Plan | Arts Gym</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Oswald:wght@500;700&display=swap" rel="stylesheet">
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

        #sidebar { 
            width: var(--sidebar-width); 
            height: 100vh; 
            position: fixed; 
            left: 0; 
            top: 0; 
            z-index: 1100; 
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

        /* Action Card */
        .action-card { 
            background: var(--bg-card); 
            border-radius: var(--card-radius); 
            padding: 20px; 
            box-shadow: var(--shadow); 
            margin-bottom: 2rem; 
        }
        
        /* Table Styling */
        .card-table { 
            background: var(--bg-card); 
            border-radius: var(--card-radius); 
            padding: 20px; 
            box-shadow: var(--shadow);
        }
        .table thead th { 
            background: var(--bg-card); 
            color: var(--text-muted); 
            font-size: 0.75rem; 
            text-transform: uppercase; 
            letter-spacing: 0.05em; 
            font-weight: 700; 
            border-bottom: 1px solid rgba(0,0,0,0.05); 
            padding: 15px; 
            position: sticky; 
            top: 0; 
            z-index: 5;
        }
        .table tbody td { padding: 15px; border-bottom: 1px solid rgba(0,0,0,0.05); }
        
        .amount-badge {
            background: rgba(67, 97, 238, 0.1); 
            color: var(--accent-blue);
            padding: 5px 12px; 
            border-radius: 8px; 
            font-weight: 700; 
            font-size: 0.85rem;
        }

        .form-control-custom {
            background: var(--bg-body); 
            border: 1px solid rgba(0,0,0,0.08);
            border-radius: 10px; 
            padding: 12px; 
            font-weight: 500; 
            color: var(--text-main);
        }
        .form-control-custom:focus { border-color: var(--primary-red); box-shadow: none; outline: none; }
        
        .form-control-custom.error { border-color: var(--primary-red); background-color: rgba(230, 57, 70, 0.05); }
        .error-message { color: var(--primary-red); font-size: 0.85rem; font-weight: 500; margin-top: 0.5rem; display: none; }
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
                <h5 class="mb-0 fw-800">Daily Walk-ins</h5>
                <p class="text-muted small mb-0 d-none d-sm-block">Register Visitors</p>
            </div>
        </div>
        <div class="header-actions">
            <div class="d-none d-md-block">
                <?php include '../global_clock.php'; ?>
            </div>
        </div>
    </header>

    <!-- Entry Card -->
    <div class="action-card">
        <div class="row g-3 align-items-end">
            <div class="col-md-5">
                <label class="small fw-bold text-uppercase text-muted mb-2 d-block">Visitor Name</label>
                <input type="text" id="walkinName" class="form-control-custom w-100" placeholder="Enter name...">
                <div class="error-message" id="nameErrorMsg"><i class="bi bi-exclamation-circle me-1"></i>Visitor name is required</div>
            </div>
            <div class="col-md-3">
                <label class="small fw-bold text-uppercase text-muted mb-2 d-block">Walk-in Rate</label>
                <div class="form-control-custom bg-light">₱ <?= number_format($daily_rate, 2) ?></div>
            </div>
            <div class="col-md-4">
                <button class="btn btn-dark w-100 fw-bold py-3" onclick="addWalkin()" style="border-radius: 10px;">
                    <i class="bi bi-plus-lg me-2"></i>Record Entry
                </button>
            </div>
        </div>
    </div>

    <!-- Data Table -->
    <div class="card-table">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <h6 class="fw-bold mb-0">Today's Attendance</h6>
            <div class="d-flex gap-2">
                <input type="text" id="walkinSearch" class="form-control bg-white border-0 shadow-sm" placeholder="Search visitor..." style="width: 250px;">
            </div>
        </div>

        <div class="table-responsive" style="max-height: 500px;">
            <table class="table align-middle" id="walkinTable">
                <thead>
                    <tr>
                        <th>Visitor Name</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th class="text-end">Time In</th>
                    </tr>
                </thead>
                <tbody id="walkinList">
                    <?php if ($walkins): foreach($walkins as $w): ?>
                    <tr>
                        <td class="fw-bold name-cell"><?= htmlspecialchars($w['visitor_name']) ?></td>
                        <td><span class="amount-badge">₱<?= number_format($w['amount'], 2) ?></span></td>
                        <td>
                            <span class="badge bg-success-subtle text-success border px-3">PAID</span>
                        </td>
                        <td class="text-end text-muted small fw-medium">
                            <?= date('h:i A', strtotime($w['visit_date'])) ?>
                        </td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="4" class="text-center py-5 text-muted">No walk-ins recorded today.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<?= csrf_script(); ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
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

    function toggleDarkMode() { 
        const isDark = !document.body.classList.contains('dark-mode-active'); 
        document.cookie = "theme=" + (isDark ? "dark" : "light") + ";path=/;max-age=" + (30*24*60*60); 
        location.reload(); 
    }

    function addWalkin() {
        const nameInput = document.getElementById('walkinName');
        const name = nameInput.value.trim();
        
        if (!name) {
            nameInput.classList.add('error');
            document.getElementById('nameErrorMsg').style.display = 'block';
            nameInput.focus();
            return;
        }
        
        const btn = $('.btn-dark');
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');

        $.post('', { name: name }, function(res) {
            if (res.status === 'success') location.reload();
            else {
                alert(res.message);
                btn.prop('disabled', false).html('<i class="bi bi-plus-lg me-2"></i>Record Entry');
            }
        }, 'json');
    }

    document.getElementById('walkinName').addEventListener('input', function() {
        this.classList.remove('error');
        document.getElementById('nameErrorMsg').style.display = 'none';
    });

    $('#walkinSearch').on('keyup', function() {
        let v = $(this).val().toLowerCase();
        $('#walkinList tr').filter(function() { 
            $(this).toggle($(this).find('.name-cell').text().toLowerCase().indexOf(v) > -1); 
        });
    });
</script>
</body>
</html>