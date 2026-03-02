<?php
require '../auth.php';
require '../connection.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

if ($_SESSION['role'] !== 'member') {
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// --- FETCH DATA BASED ON STEPS ---
$groups = $pdo->query("SELECT * FROM muscle_groups ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$group_id  = intval($_GET['group'] ?? 0);
$muscle_id = intval($_GET['muscle'] ?? 0);

$muscles = [];
if ($group_id) {
    $stmt = $pdo->prepare("SELECT * FROM muscles WHERE muscle_group_id=? ORDER BY id ASC");
    $stmt->execute([$group_id]);
    $muscles = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$exercises = [];
if ($muscle_id) {
    $stmt = $pdo->prepare("SELECT * FROM exercises WHERE muscle_id = ? ORDER BY created_at DESC");
    $stmt->execute([$muscle_id]);
    $exercises = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>Exercise Library | Arts Gym</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&family=Oswald:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        :root {
            --primary-red: #e63946;
            --dark-red: #9d0208;
            --accent-blue: #4361ee;
            --bg-body: #f4f7fe;
            --bg-card: #ffffff;
            --text-main: #2b3674;
            --text-muted: #a3aed0;
            --sidebar-width: 260px;
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
            overflow-x: hidden;
        }

        h1, h2, h3, h4, h5 { font-family: 'Oswald', sans-serif; text-transform: uppercase; letter-spacing: 0.5px; }

        #main {
            margin-left: var(--sidebar-width);
            transition: var(--transition);
            min-height: 100vh;
            padding-bottom: 50px;
            box-sizing: border-box;
        }

        #main.expanded { margin-left: 80px; width: calc(100% - 80px); }

        /* Top Header Styling (Dashboard Match) */
        
        .top-header {
            background: var(--bg-card);
            padding: 12px 20px;
            border-bottom: 1px solid rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 999;
            backdrop-filter: blur(10px);
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

        .content-container {
            padding: 25px;
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Step Title Decoration */
        .section-title-wrapper {
            border-left: 4px solid var(--primary-red);
            padding-left: 15px;
            margin-bottom: 25px;
        }

        .step-tag {
            font-size: 0.75rem;
            color: var(--primary-red);
            font-weight: 700;
            letter-spacing: 1px;
            display: block;
        }

        /* Grid & Cards */
        .exercise-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); 
            gap: 20px; 
        }

        .card-box { 
            background: var(--bg-card); 
            border-radius: 20px; 
            padding: 15px; 
            box-shadow: 0 4px 20px rgba(0,0,0,0.04); 
            border: 1px solid rgba(0,0,0,0.05);
            transition: var(--transition);
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        .card-box:hover { 
            transform: translateY(-8px); 
            box-shadow: 0 12px 30px rgba(230, 57, 70, 0.15);
            border-color: var(--primary-red);
        }

        .img-wrapper {
            width: 100%;
            height: 180px;
            overflow: hidden;
            border-radius: 15px;
            margin-bottom: 15px;
            background: #000;
        }

        .img-wrapper img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }

        .card-box:hover .img-wrapper img {
            transform: scale(1.1);
        }

        /* Exercise Details */
        .exercise-title { font-size: 1.2rem; font-weight: 700; margin-bottom: 8px; color: var(--text-main); }
        .exercise-desc { 
            font-size: 0.85rem; 
            line-height: 1.5; 
            color: var(--text-muted);
            margin-bottom: 20px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        /* Navigation Buttons */
        .btn-gym-action {
            background: var(--primary-red);
            color: white;
            font-family: 'Oswald';
            text-transform: uppercase;
            border: none;
            padding: 10px;
            border-radius: 12px;
            width: 100%;
            text-decoration: none;
            text-align: center;
            font-weight: 600;
            margin-top: auto;
            transition: 0.2s;
        }

        .btn-gym-action:hover {
            background: var(--dark-red);
            color: white;
        }

        .btn-video-guide {
            background: #1a1a1a;
            color: white !important;
            padding: 10px;
            border-radius: 12px;
            font-size: 0.85rem;
            font-family: 'Oswald';
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 10px;
        }

        body.dark-mode-active .btn-video-guide { background: #333; }

        .btn-back-pill {
            border-radius: 50px;
            padding: 5px 15px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 20px;
            border: 1px solid var(--primary-red);
            color: var(--primary-red);
            display: inline-flex;
            align-items: center;
            gap: 5px;
            text-decoration: none;
        }

        .btn-back-pill:hover { background: var(--primary-red); color: white; }

        /* Responsive adjustments */
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
            
            .top-header { flex-wrap: wrap; }
            .header-title { order: 1; flex: 1; }
            .header-actions { order: 2; }
            .mobile-clock-container { order: 3; width: 100%; margin-top: 10px; }

            .content-container { padding: 15px; }
            .exercise-grid { grid-template-columns: repeat(auto-fill, minmax(100%, 1fr)); }
        }

        @media (max-width: 576px) {
            /* smaller tweaks if needed */
        }

        @media (min-width: 576px) and (max-width: 991px) {
            .exercise-grid { grid-template-columns: repeat(2, 1fr); }
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
                    <h5 class="mb-0 fw-800">Training Library</h5>
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

        <div class="content-container">
            <!-- Dynamic Page Header -->
            <div class="section-title-wrapper">
                <span class="step-tag text-uppercase">
                    <?php 
                        if ($muscle_id) echo "Step 3: Specific Exercises";
                        elseif ($group_id) echo "Step 2: Muscle Selection";
                        else echo "Step 1: Target Areas";
                    ?>
                </span>
                <h3 class="fw-bold m-0">
                    <?php 
                        if ($muscle_id) echo "Available Workouts";
                        elseif ($group_id) echo "Choose a Muscle";
                        else echo "Workout Guide";
                    ?>
                </h3>
            </div>

            <!-- Step 1: Muscle Groups -->
            <?php if(!$group_id): ?>
                <div class="exercise-grid">
                    <?php foreach($groups as $g): ?>
                        <div class="card-box">
                            <div class="img-wrapper">
                                <img src="<?= str_replace('../', '', htmlspecialchars($g['image'])) ?>" 
                                     alt="<?= htmlspecialchars($g['name']) ?>"
                                     onerror="this.src='https://via.placeholder.com/400x300?text=Gym+Focus';">
                            </div>
                            <h5 class="exercise-title"><?= htmlspecialchars($g['name']) ?></h5>
                            <a href="?group=<?= $g['id'] ?>" class="btn-gym-action">Explore Area</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Step 2: Muscles -->
            <?php if($group_id && !$muscle_id): ?>
                <a href="exercises.php" class="btn-back-pill">
                    <i class="bi bi-arrow-left"></i> Change Area
                </a>
                <div class="exercise-grid">
                    <?php foreach($muscles as $m): ?>
                        <div class="card-box">
                            <div class="img-wrapper">
                                <img src="<?= str_replace('../', '', htmlspecialchars($m['image'])) ?>" 
                                     alt="<?= htmlspecialchars($m['name']) ?>"
                                     onerror="this.src='https://via.placeholder.com/400x300?text=Muscle';">
                            </div>
                            <h5 class="exercise-title"><?= htmlspecialchars($m['name']) ?></h5>
                            <a href="?group=<?= $group_id ?>&muscle=<?= $m['id'] ?>" class="btn-gym-action">View Exercises</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Step 3: Specific Exercises -->
            <?php if($muscle_id): ?>
                <a href="exercises.php?group=<?= $group_id ?>" class="btn-back-pill">
                    <i class="bi bi-arrow-left"></i> Back to Muscles
                </a>
                <div class="exercise-grid">
                    <?php foreach($exercises as $e): ?>
                        <div class="card-box">
                            <div class="img-wrapper">
                                <img src="<?= htmlspecialchars($e['image_url']) ?: 'https://via.placeholder.com/400x300?text=Exercise' ?>" 
                                     alt="<?= htmlspecialchars($e['name']) ?>">
                            </div>
                            <h5 class="exercise-title"><?= htmlspecialchars($e['name']) ?></h5>
                            <p class="exercise-desc"><?= htmlspecialchars($e['description']) ?></p>
                            
                            <?php if(!empty($e['video_url'])): ?>
                                <a href="<?= htmlspecialchars($e['video_url']) ?>" target="_blank" class="btn-video-guide">
                                    <i class="bi bi-play-circle"></i> Watch Video Guide
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
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
    </script>
</body>
</html>