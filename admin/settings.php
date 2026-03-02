<?php
session_start();
require '../auth.php';
require '../connection.php'; // Must use pgsql driver

// Access Control
if ($_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

$message = '';
$error = '';

// --- DATABASE EXPORT LOGIC ---
if (isset($_POST['export_db'])) {
    if (ob_get_length()) ob_end_clean();
    try {
        $tables = [];
        $query = $pdo->query("SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = 'public'");
        while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
            $tables[] = $row['tablename'];
        }

        $sql_dump = "-- Arts Gym Supabase Backup\n-- Date: " . date('Y-m-d H:i:s') . "\n";
        $sql_dump .= "SET statement_timeout = 0;\nSET client_encoding = 'UTF8';\n";
        $sql_dump .= "SET standard_conforming_strings = on;\nSET session_replication_role = 'replica';\n\n";

        foreach ($tables as $table) {
            $sql_dump .= "-- Table: $table\nTRUNCATE TABLE \"$table\" RESTART IDENTITY CASCADE;\n";
            $res = $pdo->query("SELECT * FROM \"$table\"");
            while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
                $keys = array_keys($row);
                $values = array_values($row);
                $formattedValues = array_map(function($v) use ($pdo) {
                    if ($v === null) return "NULL";
                    if (is_bool($v)) return $v ? 'true' : 'false';
                    return $pdo->quote($v);
                }, $values);
                $sql_dump .= "INSERT INTO \"$table\" (\"" . implode("\", \"", $keys) . "\") VALUES (" . implode(", ", $formattedValues) . ");\n";
            }
            $sql_dump .= "\n";
        }
        $sql_dump .= "SET session_replication_role = 'origin';\n";

        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="artsgym_backup_' . date('Y-m-d_H-i') . '.sql"');
        echo $sql_dump;
        exit;
    } catch (Exception $e) {
        $error = "Export failed: " . $e->getMessage();
    }
}

// --- DATABASE IMPORT LOGIC ---
if (isset($_POST['import_db'])) {
    if ($_FILES['sql_file']['error'] == 0) {
        try {
            $sql = file_get_contents($_FILES['sql_file']['tmp_name']);
            $pdo->exec($sql);
            $message = "Database restored successfully!";
        } catch (Exception $e) {
            $error = "Import failed: " . $e->getMessage();
        }
    } else {
        $error = "Please select a valid .sql file.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Settings | Arts Gym</title>
    <!-- REFERENCE: Unified Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;600;700&family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
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
            overflow-x: hidden;
        }

        h1, h2, h3, h4, h5, h6 { font-family: 'Oswald', sans-serif; text-transform: uppercase; }

        /* REFERENCE: Dashboard Layout Logic */
        #main {
            margin-left: var(--sidebar-width);
            transition: var(--transition);
            min-height: 100vh;
        }

        .top-header {
            background: var(--bg-card);
            padding: 15px 25px;
            border-bottom: 1px solid rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        /* REFERENCE: Dashboard Card Styling */
        .card-box { 
            background: var(--bg-card); 
            border-radius: 12px; 
            padding: 25px; 
            box-shadow: 0 4px 12px rgba(0,0,0,0.03);
            height: 100%;
            border: 1px solid rgba(0,0,0,0.05);
            transition: var(--transition);
        }

        .icon-box { 
            width: 48px; height: 48px; border-radius: 10px; 
            display: flex; align-items: center; justify-content: center; 
            font-size: 20px; margin-bottom: 20px; 
            background: rgba(230, 57, 70, 0.1); color: var(--primary-red); 
        }

        /* REFERENCE: Dashboard Button Styling */
        .btn-red { 
            background: var(--primary-red); color: white; border-radius: 8px; 
            padding: 12px 20px; font-weight: 600; border: none; text-transform: uppercase; 
            font-family: 'Oswald'; transition: var(--transition); width: 100%;
        }
        .btn-red:hover { background: var(--dark-red); transform: translateY(-2px); color: white; }

        .btn-dark-custom {
            background: #212529; color: white; border-radius: 8px;
            padding: 12px 20px; font-weight: 600; border: none; text-transform: uppercase;
            font-family: 'Oswald'; transition: var(--transition); width: 100%;
        }
        .btn-dark-custom:hover { background: #000; transform: translateY(-2px); color: white; }

        .form-control {
            background: var(--bg-body);
            border: 1px solid rgba(0,0,0,0.1);
            color: var(--text-main);
            padding: 10px;
        }
        .dark-mode-active .form-control {
            background: #1a1a1a;
            border-color: #333;
            color: #fff;
        }

        @media (max-width: 991.98px) { #main { margin-left: 0 !important; } }
    </style>
</head>
<body class="<?= isset($_COOKIE['theme']) && $_COOKIE['theme'] == 'dark' ? 'dark-mode-active' : '' ?>">

    <?php include __DIR__ . '/_sidebar.php'; ?>

    <div id="main">
        <header class="top-header">
            <div class="d-flex align-items-center">
                <button class="btn btn-light d-lg-none me-3" onclick="toggleSidebar()">
                    <i class="bi bi-list fs-4"></i>
                </button>
                <div class="d-none d-sm-block">
                    <h5 class="mb-0 fw-bold">Arts Gym Management</h5>
                </div>
            </div>
            <div class="d-flex align-items-center gap-3">
                <?php include '../global_clock.php'; ?>

            </div>
        </header>

        <div class="container-fluid p-3 p-md-4">
            <div class="mb-4">
                <h2 class="fw-bold mb-1">System Settings</h2>
                <p class="text-secondary small fw-bold">DATABASE BACKUP & RECOVERY TOOLS</p>
            </div>

            <!-- Alerts -->
            <?php if ($message): ?>
                <div class="alert alert-success border-0 shadow-sm alert-dismissible fade show mb-4">
                    <i class="bi bi-check-circle-fill me-2"></i><?= $message ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger border-0 shadow-sm alert-dismissible fade show mb-4">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i><?= $error ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="row g-4">
                <!-- EXPORT CARD -->
                <div class="col-12 col-xl-6">
                    <div class="card-box">
                        <div class="icon-box"><i class="bi bi-cloud-download"></i></div>
                        <h4 class="fw-bold mb-2">Export Records</h4>
                        <p class="text-muted small mb-4">Download a full PostgreSQL backup (.sql) of your entire gym system. This includes all active members, transactions, and historical data.</p>
                        <form method="POST">
                            <button type="submit" name="export_db" class="btn-red">
                                <i class="bi bi-file-earmark-arrow-down me-2"></i>Generate Backup
                            </button>
                        </form>
                    </div>
                </div>

                <!-- IMPORT CARD -->
                <div class="col-12 col-xl-6">
                    <div class="card-box" style="border-top: 4px solid var(--primary-red);">
                        <div class="icon-box"><i class="bi bi-cloud-upload"></i></div>
                        <h4 class="fw-bold mb-2">Restore Database</h4>
                        <p class="text-muted small mb-3">Upload an Arts Gym backup file. <span class="text-danger fw-bold">Warning: This will truncate current tables and replace them with backup data.</span></p>
                        <form method="POST" enctype="multipart/form-data">
                            <input type="file" name="sql_file" class="form-control mb-3" accept=".sql" required>
                            <button type="submit" name="import_db" class="btn-dark-custom" onclick="return confirm('WARNING: Are you sure you want to restore? Current data will be overwritten.')">
                                <i class="bi bi-arrow-repeat me-2"></i>Execute Restore
                            </button>
                        </form>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleDarkMode() {
            const isDark = !document.body.classList.contains('dark-mode-active');
            document.cookie = "theme=" + (isDark ? "dark" : "light") + ";path=/;max-age=" + (30*24*60*60);
            location.reload(); 
        }
    </script>
</body>
</html>