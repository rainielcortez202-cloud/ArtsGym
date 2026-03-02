<?php
// admin/manage_exercises.php
session_start();
require '../auth.php';
require '../connection.php';

if ($_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

/* GET SELECTIONS */
$group_id  = $_GET['group']  ?? null;
$muscle_id = $_GET['muscle'] ?? null;

/* FETCH GROUPS */
$groups = $pdo->query("SELECT * FROM muscle_groups ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

/* FETCH MUSCLES */
$muscles = [];
if ($group_id) {
    $stmt = $pdo->prepare("SELECT * FROM muscles WHERE muscle_group_id=? ORDER BY id");
    $stmt->execute([$group_id]);
    $muscles = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* ADD / UPDATE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_exercise'])) {
    if (empty($_POST['exercise_id'])) {
        $stmt = $pdo->prepare("
            INSERT INTO exercises
            (muscle_group_id, muscle_id, name, video_url, image_url, description)
            VALUES (?,?,?,?,?,?)
        ");
        $stmt->execute([
            $_POST['muscle_group_id'],
            $_POST['muscle_id'],
            $_POST['name'],
            $_POST['video_url'],
            $_POST['image_url'],
            $_POST['description']
        ]);
    } else {
        $stmt = $pdo->prepare("
            UPDATE exercises SET
                name=?, video_url=?, image_url=?, description=?
            WHERE id=?
        ");
        $stmt->execute([
            $_POST['name'],
            $_POST['video_url'],
            $_POST['image_url'],
            $_POST['description'],
            $_POST['exercise_id']
        ]);
    }
    header("Location: manage_exercises.php?group=$group_id&muscle=$muscle_id");
    exit;
}

/* DELETE */
if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("DELETE FROM exercises WHERE id=?");
    $stmt->execute([$_GET['delete']]);
    header("Location: manage_exercises.php?group=$group_id&muscle=$muscle_id");
    exit;
}

/* FETCH EXERCISES */
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;
$total_pages = 1;

$exercises = [];
if ($muscle_id) {
    $t_stmt = $pdo->prepare("SELECT COUNT(*) FROM exercises WHERE muscle_id=?");
    $t_stmt->execute([$muscle_id]);
    $total = $t_stmt->fetchColumn();
    $total_pages = ceil($total / $limit);

    $stmt = $pdo->prepare("SELECT * FROM exercises WHERE muscle_id=? ORDER BY id DESC LIMIT $limit OFFSET $offset");
    $stmt->execute([$muscle_id]);
    $exercises = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* EDIT */
$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM exercises WHERE id=?");
    $stmt->execute([$_GET['edit']]);
    $edit = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage Exercise | Arts Gym</title>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        :root {
            --primary-red: #e63946;
            --bg-body: #f8f9fa;
            --bg-card: #ffffff;
            --text-main: #1a1a1a;
            --text-muted: #8e8e93;
            --border-color: #f1f1f1;
            --sidebar-width: 260px;
            --card-shadow: 0 10px 30px rgba(0, 0, 0, 0.04);
            --transition: all 0.3s ease;
        }

        body.dark-mode-active {
            --bg-body: #0a0a0a; --bg-card: #121212; --text-main: #f5f5f7;
            --text-muted: #86868b; --border-color: #1c1c1e; --card-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        body { 
            font-family: 'Inter', sans-serif; 
            background-color: var(--bg-body); color: var(--text-main);
            transition: var(--transition); letter-spacing: -0.01em;
        }

        #main {
            margin-left: var(--sidebar-width);
            transition: var(--transition);
            min-height: 100vh;
            padding: 2rem;
        }
        #main.expanded { margin-left: 80px; }

        .top-header { background: transparent; padding: 0 0 2rem 0; display: flex; align-items: center; justify-content: space-between; }

        /* Minimalist Cards */
        .card-custom {
            background: var(--bg-card); border-radius: 20px; padding: 24px;
            box-shadow: var(--card-shadow); border: none; margin-bottom: 24px;
        }

        /* Guided Step Indicator */
        .step-pill {
            display: inline-flex; align-items: center; justify-content: center;
            width: 28px; height: 28px; border-radius: 50%;
            background: rgba(230, 57, 70, 0.1); color: var(--primary-red);
            font-size: 0.75rem; font-weight: 700; margin-right: 12px;
        }

        .section-title { font-size: 0.9rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-main); }

        /* Form Controls */
        .form-label { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); margin-bottom: 6px; }
        
        .form-control-minimal {
            background: var(--bg-body); border: 1px solid var(--border-color);
            border-radius: 12px; padding: 12px 16px; font-weight: 500; font-size: 0.9rem; transition: all 0.2s;
            color: var(--text-main);
        }
        .form-control-minimal:focus {
            background: var(--bg-card); border-color: var(--primary-red);
            box-shadow: 0 0 0 4px rgba(230, 57, 70, 0.1); outline: none;
        }

        /* Buttons */
        .btn-main {
            background: var(--text-main); color: var(--bg-card);
            border: none; border-radius: 12px; padding: 12px 24px;
            font-weight: 600; font-size: 0.85rem; transition: all 0.2s;
        }
        .btn-main:hover { opacity: 0.9; transform: translateY(-1px); }

        /* Table Style */
        .table thead th {
            background: transparent; font-size: 0.7rem; font-weight: 700;
            text-transform: uppercase; color: var(--text-muted);
            padding: 16px 20px; border-bottom: 1px solid var(--border-color);
        }
        .table tbody td { padding: 16px 20px; border-bottom: 1px solid var(--border-color); vertical-align: middle; }

        .media-icon { font-size: 1.1rem; opacity: 0.7; margin-right: 8px; }

        @media (max-width: 991.98px) { #main { margin-left: 0 !important; padding: 1.5rem; } }
    </style>
</head>
<body class="<?= isset($_COOKIE['theme']) && $_COOKIE['theme'] == 'dark' ? 'dark-mode-active' : '' ?>">

    <?php include __DIR__ . '/_sidebar.php'; ?>

    <div id="main">
        <header class="top-header">
            <div>
                <button class="btn btn-light me-2" onclick="toggleSidebar()"><i class="bi bi-list"></i></button>
                <h4 class="mb-0 fw-bold">Exercise Library</h4>
                <p class="text-muted small mb-0">Manage movements and instructions</p>
            </div>
            <div class="d-flex align-items-center gap-3">
                <?php include '../global_clock.php'; ?>
            </div>
        </header>

        <div class="row g-4">
            <!-- Left Column: Navigation -->
            <div class="col-12 col-xl-4">
                <div class="card-custom">
                    <div class="d-flex align-items-center mb-4">
                        <span class="step-pill">1</span>
                        <span class="section-title">Muscle Group</span>
                    </div>
                    <form method="GET">
                        <select class="form-control-minimal w-100" name="group" onchange="this.form.submit()">
                            <option value="">Select Category...</option>
                            <?php foreach($groups as $g): ?>
                                <option value="<?= $g['id'] ?>" <?= $group_id==$g['id']?'selected':'' ?>>
                                    <?= htmlspecialchars($g['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>

                <?php if ($group_id): ?>
                <div class="card-custom">
                    <div class="d-flex align-items-center mb-4">
                        <span class="step-pill">2</span>
                        <span class="section-title">Specific Muscle</span>
                    </div>
                    <form method="GET">
                        <input type="hidden" name="group" value="<?= $group_id ?>">
                        <select class="form-control-minimal w-100" name="muscle" onchange="this.form.submit()">
                            <option value="">Select Muscle...</option>
                            <?php foreach($muscles as $m): ?>
                                <option value="<?= $m['id'] ?>" <?= $muscle_id==$m['id']?'selected':'' ?>>
                                    <?= htmlspecialchars($m['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
                <?php endif; ?>
            </div>

            <!-- Right Column: Content -->
            <div class="col-12 col-xl-8">
                <?php if ($muscle_id): ?>
                <div class="card-custom">
                    <div class="d-flex align-items-center mb-4">
                        <span class="step-pill">3</span>
                        <span class="section-title"><?= $edit ? 'Edit Exercise' : 'Add New Exercise' ?></span>
                    </div>
                    
                    <form method="POST">
                        <input type="hidden" name="exercise_id" value="<?= $edit['id'] ?? '' ?>">
                        <input type="hidden" name="muscle_group_id" value="<?= $group_id ?>">
                        <input type="hidden" name="muscle_id" value="<?= $muscle_id ?>">

                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Exercise Name</label>
                                <input class="form-control-minimal w-100" name="name" placeholder="e.g. Incline DB Press" required value="<?= $edit['name'] ?? '' ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">YouTube Link</label>
                                <input class="form-control-minimal w-100" name="video_url" placeholder="https://..." value="<?= $edit['video_url'] ?? '' ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Image</label>
                                <div class="d-flex gap-2 align-items-center">
                                    <input type="hidden" id="imageUrlHidden" name="image_url" value="<?= htmlspecialchars($edit['image_url'] ?? '') ?>">
                                    <button type="button" class="btn-main" id="chooseImageBtn" title="Choose or upload image">
                                        <i class="bi bi-image"></i>
                                    </button>
                                </div>
                                <input type="file" id="imageFileInput" accept="image/*" style="display:none">
                                <div id="imagePreview" class="mt-3">
                                    <?php if(!empty($edit['image_url'])): ?>
                                        <img src="<?= htmlspecialchars($edit['image_url']) ?>" alt="preview" style="max-height:100px; border-radius:8px;"/>
                                    <?php endif; ?>
                                </div>
                                <small class="text-muted d-block mt-2">Choose an image from your device. Uploaded images are stored in <code>admin/uploads/exercises/</code></small>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Coaching Cues</label>
                                <textarea class="form-control-minimal w-100" name="description" rows="3" placeholder="Explain the technique..."><?= $edit['description'] ?? '' ?></textarea>
                            </div>
                            <div class="col-12 text-end">
                                <button class="btn-main" name="save_exercise">
                                    <?= $edit ? "Update Exercise" : "Create Exercise" ?>
                                </button>
                            </div>
                        </div>
                    </form>

                    <div class="mt-5">
                        <h6 class="section-title mb-4">Existing Exercises</h6>
                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Media</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($exercises as $e): ?>
                                    <tr>
                                        <td class="fw-semibold"><?= htmlspecialchars($e['name']) ?></td>
                                        <td>
                                            <?php if($e['video_url']): ?><i class="bi bi-play-circle media-icon text-danger"></i><?php endif; ?>
                                            <?php if($e['image_url']): ?><i class="bi bi-image media-icon text-muted"></i><?php endif; ?>
                                            <?php if(!$e['video_url'] && !$e['image_url']): ?><span class="text-muted small">None</span><?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <a class="btn btn-sm btn-light border me-1" href="?group=<?= $group_id ?>&muscle=<?= $muscle_id ?>&edit=<?= $e['id'] ?>">
                                                <i class="bi bi-pencil small"></i>
                                            </a>
                                            <a class="btn btn-sm btn-light border text-danger" href="?group=<?= $group_id ?>&muscle=<?= $muscle_id ?>&delete=<?= $e['id'] ?>" onclick="return confirm('Delete this exercise?')">
                                                <i class="bi bi-trash small"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <?php if ($total_pages > 1): ?>
                        <nav class="mt-4">
                            <ul class="pagination pagination-sm justify-content-center mb-0">
                                <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                                    <a class="page-link" href="?group=<?= $group_id ?>&muscle=<?= $muscle_id ?>&page=<?= $page-1 ?>">Previous</a>
                                </li>
                                <?php for($i=1; $i<=$total_pages; $i++): ?>
                                    <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
                                        <a class="page-link" href="?group=<?= $group_id ?>&muscle=<?= $muscle_id ?>&page=<?= $i ?>"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : '' ?>">
                                    <a class="page-link" href="?group=<?= $group_id ?>&muscle=<?= $muscle_id ?>&page=<?= $page+1 ?>">Next</a>
                                </li>
                            </ul>
                        </nav>
                        <?php endif; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="card-custom text-center py-5 d-flex flex-column align-items-center justify-content-center" style="min-height: 400px;">
                    <i class="bi bi-layers text-muted opacity-25" style="font-size: 4rem;"></i>
                    <p class="mt-4 text-muted fw-medium">Select a muscle group to begin managing the database.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Image chooser + upload
        (function(){
            const chooseBtn = document.getElementById('chooseImageBtn');
            const fileInput = document.getElementById('imageFileInput');
            const imageUrlInput = document.getElementById('imageUrlHidden');
            const preview = document.getElementById('imagePreview');

            chooseBtn.addEventListener('click', function(){
                fileInput.click();
            });

            fileInput.addEventListener('change', function(){
                if (!fileInput.files || !fileInput.files[0]) return;
                const f = fileInput.files[0];
                const fd = new FormData();
                fd.append('file', f);

                // show temporary preview
                const reader = new FileReader();
                reader.onload = function(e){
                    preview.innerHTML = '<img src="'+e.target.result+'" style="max-height:100px;border-radius:8px;"/>';
                };
                reader.readAsDataURL(f);

                // upload
                $.ajax({
                    url: 'upload_image.php',
                    method: 'POST',
                    data: fd,
                    contentType: false,
                    processData: false,
                    dataType: 'json'
                }).done(function(res){
                    if (res.status === 'success') {
                        imageUrlInput.value = res.url;
                    } else {
                        alert('Upload error: ' + (res.message||'Unknown'));
                    }
                }).fail(function(){
                    alert('Upload failed');
                });
            });
        })();
    </script>
</body>
</html>