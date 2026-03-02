<?php
// Keeping all your existing PHP logic exactly as provided
session_start();
require '../auth.php';
require '../connection.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

$current_year = date('Y');

/* ===================== DATA FETCHING ===================== */
$total_members = $pdo->query("SELECT COUNT(*) FROM users WHERE role='member' AND status='active'")->fetchColumn();
$expiring_soon = $pdo->query("
    SELECT COUNT(*) 
    FROM users u
    JOIN (SELECT user_id, MAX(expires_at) as latest_expiry FROM sales GROUP BY user_id) s ON u.id = s.user_id
    WHERE u.role = 'member' AND u.status = 'active'
    AND s.latest_expiry::DATE >= CURRENT_DATE AND s.latest_expiry::DATE <= (CURRENT_DATE + INTERVAL '7 days')
")->fetchColumn() ?? 0;

$daily_walkins = $pdo->query("SELECT COUNT(*) FROM walk_ins WHERE visit_date::DATE = CURRENT_DATE")->fetchColumn() ?? 0;
$daily_sales = $pdo->query("SELECT SUM(amount) FROM sales WHERE sale_date::DATE = CURRENT_DATE")->fetchColumn() ?? 0;
$monthly_sales = $pdo->query("SELECT SUM(amount) FROM sales WHERE EXTRACT(MONTH FROM sale_date) = EXTRACT(MONTH FROM CURRENT_DATE) AND EXTRACT(YEAR FROM sale_date) = EXTRACT(YEAR FROM CURRENT_DATE)")->fetchColumn() ?? 0;
$daily_attendance = $pdo->query("SELECT COUNT(DISTINCT user_id) FROM attendance WHERE date = CURRENT_DATE AND user_id IS NOT NULL")->fetchColumn() ?? 0;

$recent_attendance = $pdo->query("
    SELECT a.*, u.full_name 
    FROM attendance a 
    JOIN users u ON a.user_id = u.id 
    WHERE a.date = CURRENT_DATE
    ORDER BY a.time_in DESC 
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

$recent_walkins = $pdo->query("
    SELECT * FROM walk_ins 
    WHERE visit_date::date = CURRENT_DATE
    ORDER BY visit_date DESC 
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

$monthly_sales_data = $pdo->query("SELECT EXTRACT(MONTH FROM sale_date) AS month, SUM(amount) AS total FROM sales WHERE EXTRACT(YEAR FROM sale_date) = $current_year GROUP BY EXTRACT(MONTH FROM sale_date)")->fetchAll(PDO::FETCH_KEY_PAIR);
$monthly_members_data = $pdo->query("SELECT EXTRACT(MONTH FROM created_at) AS month, COUNT(*) AS total FROM users WHERE role='member' AND EXTRACT(YEAR FROM created_at) = $current_year GROUP BY EXTRACT(MONTH FROM created_at)")->fetchAll(PDO::FETCH_KEY_PAIR);

$months = ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"];
$monthly_sales_js = []; $monthly_members_js = [];
foreach(range(1,12) as $m){
    $monthly_sales_js[] = $monthly_sales_data[$m] ?? 0;
    $monthly_members_js[] = $monthly_members_data[$m] ?? 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>Dashboard | Arts Gym</title>
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

        /* Responsive Layout Logic */
        #main {
            margin-left: var(--sidebar-width);
            transition: var(--transition);
            padding: 1.5rem;
            min-height: 100vh;
            width: calc(100% - var(--sidebar-width));
            box-sizing: border-box;
        }

        #main.expanded { margin-left: 80px; width: calc(100% - 80px); }

        /* Fixed Top Header */
        .top-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            gap: 10px;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Action Buttons */
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

        /* Stats Grid Fixes for Mobile */
        .card-box {
            background: var(--bg-card);
            border-radius: var(--card-radius);
            padding: 18px;
            box-shadow: var(--shadow);
            height: 100%;
            transition: var(--transition);
        }
        .stat-label { color: var(--text-muted); font-size: 0.75rem; font-weight: 700; text-transform: uppercase; margin-bottom: 4px; }
        .stat-value { font-size: 1.25rem; font-weight: 800; line-height: 1; }

        /* Chart/Table Cards */
        .chart-card, .table-card {
            background: var(--bg-card);
            border-radius: var(--card-radius);
            padding: 20px;
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
        }
        .chart-container { position: relative; height: 250px; width: 100%; }

        /* Sidebar Responsive Classes */
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
            #sidebar { 
                left: calc(var(--sidebar-width) * -1); 
                position: fixed; 
                z-index: 1100; 
                height: 100vh; 
                width: var(--sidebar-width);
                transition: var(--transition); 
            }
            #sidebar.show { left: 0; }
            .sidebar-overlay {
                display: none; 
                position: fixed; 
                top: 0; 
                left: 0; 
                right: 0; 
                bottom: 0;
                background: rgba(0,0,0,0.4); 
                z-index: 1090; 
                backdrop-filter: blur(4px);
            }
            .sidebar-overlay.show { display: block; }
            
            /* Make stats 2 columns on mobile instead of 1 */
            .row-cols-mobile-2 > * { flex: 0 0 50%; max-width: 50%; }
            .stat-value { font-size: 1.1rem; }
            .top-header { flex-wrap: wrap; }
            .header-title { order: 1; flex: 1; }
            .header-actions { order: 2; }
            .mobile-clock-container { order: 3; width: 100%; margin-top: 10px; }
        }

        /* Mobile Table Scrolling */
        .table-responsive {
            border-radius: 12px;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
    </style>
</head>
<body class="<?= isset($_COOKIE['theme']) && $_COOKIE['theme'] == 'dark' ? 'dark-mode-active' : '' ?>">

<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<?php include '_sidebar.php'; ?>

<div id="main">
    <header class="top-header">
        <div class="header-title d-flex align-items-center gap-3">
            <button class="btn-action" onclick="toggleSidebar()">
                <i class="bi bi-list fs-5"></i>
            </button>
            <div>
                <h5 class="mb-0 fw-800">Overview</h5>
                <p class="text-muted small mb-0 d-none d-sm-block">Gym Analytics</p>
            </div>
        </div>

        <div class="header-actions">
            <?php include '../global_clock.php'; ?>
            <div class="d-none d-md-block">
                <?php include '../global_clock.php'; ?>
            </div>
        </div>

        <!-- Clock on separate line for mobile if needed -->
        <div class="mobile-clock-container d-md-none text-center">
             <?php include '../global_clock.php'; ?>
        </div>
    </header>

    <!-- Stats Grid: 2 cols on mobile, 3 on tablet, 6 on desktop -->
    <div class="row row-cols-2 row-cols-md-3 row-cols-xl-6 g-3 mb-4 row-cols-mobile-2">
        <div class="col">
            <div class="card-box">
                <div class="stat-label">Members</div>
                <div class="stat-value"><?= number_format($total_members); ?></div>
            </div>
        </div>
        <div class="col">
            <div class="card-box">
                <div class="stat-label">Expiring</div>
                <div class="stat-value text-warning"><?= $expiring_soon; ?></div>
            </div>
        </div>
        <div class="col">
            <div class="card-box">
                <div class="stat-label">Daily Sales</div>
                <div class="stat-value text-danger">₱<?= number_format($daily_sales); ?></div>
            </div>
        </div>
        <div class="col">
            <div class="card-box">
                <div class="stat-label">Attendance</div>
                <div class="stat-value"><?= $daily_attendance; ?></div>
            </div>
        </div>
        <div class="col">
            <div class="card-box">
                <div class="stat-label">Walk-ins</div>
                <div class="stat-value"><?= $daily_walkins; ?></div>
            </div>
        </div>
        <div class="col">
            <div class="card-box bg-primary text-white">
                <div class="stat-label text-white-50">Monthly</div>
                <div class="stat-value">₱<?= number_format($monthly_sales / 1000, 1); ?>k</div>
            </div>
        </div>
    </div>

    <!-- Charts -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-xl-8">
            <div class="chart-card">
                <h6 class="fw-bold mb-3">Revenue Analytics</h6>
                <div class="chart-container"><canvas id="revenueChart"></canvas></div>
            </div>
        </div>
        <div class="col-12 col-xl-4">
            <div class="chart-card">
                <h6 class="fw-bold mb-3">New Signups</h6>
                <div class="chart-container"><canvas id="membersChart"></canvas></div>
            </div>
        </div>
    </div>

    <!-- Tables -->
    <div class="row g-3">
        <div class="col-12 col-xl-6">
            <div class="table-card">
                <div class="d-flex justify-content-between mb-3">
                    <h6 class="fw-bold mb-0">Recent Attendance</h6>
                    <a href="attendance.php" class="small fw-bold text-decoration-none">View All</a>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Name</th><th class="text-end">Time</th></tr></thead>
                        <tbody>
                            <?php foreach(array_slice($recent_attendance, 0, 5) as $r): ?>
                            <tr>
                                <td class="py-2 small fw-600"><?= htmlspecialchars($r['full_name']) ?></td>
                                <td class="text-end text-muted small"><?= date('h:i A', strtotime($r['time_in'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-12 col-xl-6">
            <div class="table-card">
                <div class="d-flex justify-content-between mb-3">
                    <h6 class="fw-bold mb-0">Latest Walk-ins</h6>
                    <a href="daily.php" class="small fw-bold text-decoration-none">View All</a>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Visitor</th><th class="text-end">Arrival</th></tr></thead>
                        <tbody>
                            <?php foreach(array_slice($recent_walkins, 0, 5) as $w): ?>
                            <tr>
                                <td class="py-2 small fw-600"><?= htmlspecialchars($w['visitor_name']) ?></td>
                                <td class="text-end text-muted small"><?= date('h:i A', strtotime($w['visit_date'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
    const isDark = document.body.classList.contains('dark-mode-active');
    Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
    Chart.defaults.color = isDark ? '#a3aed0' : '#8e8e93';

    const chartOptions = { 
        maintainAspectRatio: false, 
        responsive: true,
        plugins: { legend: { display: false } }, 
        scales: { 
            y: { grid: { color: isDark ? 'rgba(255,255,255,0.05)' : 'rgba(0,0,0,0.03)', drawBorder: false } }, 
            x: { grid: { display: false } } 
        } 
    };

    new Chart(document.getElementById('revenueChart'), { 
        type: 'line', 
        data: { 
            labels: <?= json_encode($months) ?>, 
            datasets: [{ data: <?= json_encode($monthly_sales_js) ?>, borderColor: '#4361ee', borderWidth: 3, tension: 0.4, fill: true, backgroundColor: 'rgba(67, 97, 238, 0.05)', pointRadius: 0 }] 
        }, 
        options: chartOptions 
    });

    new Chart(document.getElementById('membersChart'), { 
        type: 'bar', 
        data: { 
            labels: <?= json_encode($months) ?>, 
            datasets: [{ data: <?= json_encode($monthly_members_js) ?>, backgroundColor: '#2b3674', borderRadius: 4 }] 
        }, 
        options: chartOptions 
    });

    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const main = document.getElementById('main');

        if (window.innerWidth < 992) {
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
</script>
</body>
</html>

fix the mobile view layout make it better