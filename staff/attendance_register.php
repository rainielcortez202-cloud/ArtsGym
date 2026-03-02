<?php
session_start();
require '../connection.php';

// Authorization Check
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'staff') {
    header("Location: ../login.php");
    exit;
}


// --- PAGINATION & FILTERING ---
$filter = isset($_GET['filter']) && $_GET['filter'] === '7days' ? '7days' : 'today';
$per_page = 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $per_page;

// Build WHERE clause based on filter
if ($filter === 'today') {
    $where_clause = "a.attendance_date = CURRENT_DATE";
} else {
    $where_clause = "a.attendance_date >= CURRENT_DATE - INTERVAL '7 days'";
}

// Count total records
$count_stmt = $pdo->query("
    SELECT COUNT(*) FROM attendance a
    LEFT JOIN users u ON a.user_id = u.id
    WHERE $where_clause
");
$total_records = $count_stmt->fetchColumn();
$total_pages = ceil($total_records / $per_page);

// Fetch paginated attendance records
$attendanceRecords = $pdo->query("
    SELECT a.id, u.full_name, a.attendance_date, a.time_in, a.visitor_name, u.role
    FROM attendance a
    LEFT JOIN users u ON a.user_id = u.id
    WHERE $where_clause
    ORDER BY a.time_in DESC
    LIMIT $per_page OFFSET $offset
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>Attendance | Arts Gym</title>
    
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
            padding: 20px; 
            box-shadow: var(--shadow);
        }
        
        .table { color: var(--text-main); }
        .table thead th { color: var(--text-muted); font-size: 0.75rem; text-transform: uppercase; font-weight: 700; }
        .dark-mode-active .table { --bs-table-color: #fff; --bs-table-bg: transparent; }

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
                    <h5 class="mb-0 fw-800">Attendance Records</h5>
                    <p class="text-muted small mb-0 d-none d-sm-block">Staff View</p>
                </div>
            </div>

            <div class="header-actions">
                <div class="d-none d-md-block">
                    <?php include '../global_clock.php'; ?>
                </div>
            </div>
        </header>

        <div class="card-box">
                        <!-- Filter Buttons -->
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div class="btn-group" role="group">
                                <a href="?filter=today" class="btn btn-sm <?= $filter === 'today' ? 'btn-dark' : 'btn-outline-secondary' ?>">Today</a>
                                <a href="?filter=7days" class="btn btn-sm <?= $filter === '7days' ? 'btn-dark' : 'btn-outline-secondary' ?>">Last 7 Days</a>
                            </div>
                            <span class="text-muted small">Showing <?= count($attendanceRecords) ?> of <?= $total_records ?> records</span>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="m-0 fw-bold">Recent Scans</h6>
                        </div>
                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead>
                                    <tr class="small text-muted">
                                        <th>NAME</th>
                                        <th>TYPE</th>
                                        <th>TIME</th>
                                        <th>DATE</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(count($attendanceRecords) > 0): ?>
                                        <?php foreach($attendanceRecords as $rec): ?>
                                        <tr>
                                            <td class="fw-bold">
                                                <?php if ($rec['visitor_name']): ?>
                                                    <?= htmlspecialchars($rec['visitor_name']) ?>
                                                <?php else: ?>
                                                    <?= htmlspecialchars($rec['full_name']) ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($rec['visitor_name']): ?>
                                                    <span class="badge bg-info text-dark">Daily Walk-in</span>
                                                <?php elseif($rec['role'] == 'staff'): ?>
                                                    <span class="badge bg-secondary">Staff</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">Member</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-dark"><?= date('h:i A', strtotime($rec['time_in'])) ?></span>
                                            </td>
                                            <td class="text-secondary small">
                                                <?= date('M d, Y', strtotime($rec['attendance_date'])) ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="4" class="text-center text-muted">No records today.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                        <div class="d-flex justify-content-center align-items-center gap-2 mt-3 pt-3 border-top">
                            <?php if ($page > 1): ?>
                                <a href="?filter=<?= $filter ?>&page=<?= $page - 1 ?>" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-chevron-left"></i> Previous
                                </a>
                            <?php else: ?>
                                <button class="btn btn-sm btn-outline-secondary" disabled>
                                    <i class="bi bi-chevron-left"></i> Previous
                                </button>
                            <?php endif; ?>

                            <span class="text-muted small">Page <?= $page ?> of <?= $total_pages ?></span>

                            <?php if ($page < $total_pages): ?>
                                <a href="?filter=<?= $filter ?>&page=<?= $page + 1 ?>" class="btn btn-sm btn-outline-secondary">
                                    Next <i class="bi bi-chevron-right"></i>
                                </a>
                            <?php else: ?>
                                <button class="btn btn-sm btn-outline-secondary" disabled>
                                    Next <i class="bi bi-chevron-right"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
        </div>
    </div>

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
    </script>
</body>
</html>