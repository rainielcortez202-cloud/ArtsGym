<?php
session_start();
require '../auth.php';
require '../connection.php';
require '../includes/status_sync.php';

if ($_SESSION['role'] !== 'admin') { header("Location: ../login.php"); exit; }

$current = basename($_SERVER['PHP_SELF']);
$filter = $_GET['filter'] ?? 'all';

// --- PAGINATION (Max 10 per request) ---
$m_page = isset($_GET['m_page']) ? max(1, (int)$_GET['m_page']) : 1;
$s_page = isset($_GET['s_page']) ? max(1, (int)$_GET['s_page']) : 1;
$limit = 10;
$m_offset = ($m_page - 1) * $limit;
$s_offset = ($s_page - 1) * $limit;

// --- SEARCH LOGIC ---
$m_search = $_GET['m_search'] ?? '';
$s_search = $_GET['s_search'] ?? '';

// --- SORT LOGIC ---
$m_sort = $_GET['m_sort'] ?? 'newest';
$s_sort = $_GET['s_sort'] ?? 'newest';

function getOrderBy($sort) {
    if ($sort === 'oldest') return "u.id ASC";
    if ($sort === 'az') return "u.full_name ASC";
    if ($sort === 'za') return "u.full_name DESC";
    return "u.id DESC"; // newest
}

$m_order = getOrderBy($m_sort);
$s_order = getOrderBy($s_sort);

// --- ROBUST POSTGRESQL MEMBER QUERY USING LATERAL JOIN ---
if ($filter === 'expiring') {
    $m_sql_base = "
        FROM users u 
        JOIN LATERAL (
            SELECT expires_at as latest_expiry 
            FROM sales 
            WHERE user_id = u.id 
            ORDER BY expires_at DESC 
            LIMIT 1
        ) ls ON true 
        WHERE u.role = 'member' AND u.status = 'active'
        AND ls.latest_expiry >= CURRENT_TIMESTAMP AND ls.latest_expiry <= (CURRENT_TIMESTAMP + INTERVAL '7 days')
    ";
    if ($m_search) {
        $m_sql_base .= " AND (u.full_name ILIKE " . $pdo->quote('%' . $m_search . '%') . " OR u.email ILIKE " . $pdo->quote('%' . $m_search . '%') . ")";
    }
    
    $m_sql = "SELECT u.*, ls.latest_expiry " . $m_sql_base . " ORDER BY ls.latest_expiry ASC";
    $m_total = $pdo->query("SELECT COUNT(*) " . $m_sql_base)->fetchColumn();
} else {
    $m_sql_base = "
        FROM users u 
        LEFT JOIN LATERAL (
            SELECT expires_at as latest_expiry 
            FROM sales 
            WHERE user_id = u.id 
            ORDER BY expires_at DESC 
            LIMIT 1
        ) s ON true 
        WHERE u.role = 'member'
    ";
    if ($m_search) {
        $m_sql_base .= " AND (u.full_name ILIKE " . $pdo->quote('%' . $m_search . '%') . " OR u.email ILIKE " . $pdo->quote('%' . $m_search . '%') . ")";
    }
    
    $m_sql = "SELECT u.*, s.latest_expiry " . $m_sql_base . " ORDER BY $m_order";
    $m_total = $pdo->query("SELECT COUNT(*) " . $m_sql_base)->fetchColumn();
}

$s_sql_base = "FROM users u WHERE u.role='staff'";
if ($s_search) {
    $s_sql_base .= " AND (u.full_name ILIKE " . $pdo->quote('%' . $s_search . '%') . " OR u.email ILIKE " . $pdo->quote('%' . $s_search . '%') . ")";
}
$s_total = $pdo->query("SELECT COUNT(*) " . $s_sql_base)->fetchColumn();

$m_total_pages = ceil($m_total / $limit);
$s_total_pages = ceil($s_total / $limit);

$m_sql .= " LIMIT $limit OFFSET $m_offset";
$members = $pdo->query($m_sql)->fetchAll(PDO::FETCH_ASSOC);

// --- SYNC ONLY VISIBLE MEMBERS ---
if (!empty($members)) {
    foreach ($members as &$m) {
        $calculated_active = ($m['latest_expiry'] && strtotime($m['latest_expiry']) > time());
        $new_status = $calculated_active ? 'active' : 'inactive';
        if ($m['status'] !== $new_status) {
            $pdo->prepare("UPDATE users SET status = ? WHERE id = ?")->execute([$new_status, $m['id']]);
            $m['status'] = $new_status;
        }
    }
}
$staffs  = $pdo->query("SELECT u.* " . $s_sql_base . " ORDER BY $s_order LIMIT $limit OFFSET $s_offset")->fetchAll(PDO::FETCH_ASSOC);

function maskEmailPHP($email) {
    if (!$email) return 'N/A';
    $parts = explode("@", $email);
    if (count($parts) < 2) return $email;
    $name = $parts[0];
    $len = strlen($name);
    if ($len <= 4) { 
        $maskedName = substr($name, 0, 1) . str_repeat('*', max(3, $len - 1)); 
    } else { 
        $maskedName = substr($name, 0, 2) . str_repeat('*', $len - 3) . substr($name, -1); 
    }
    return $maskedName . "@" . $parts[1];
}

// --- AJAX HANDLER FOR LIVE SEARCH ---
if (isset($_GET['ajax_m']) || isset($_GET['ajax_s'])) {
    ob_start();
    if (isset($_GET['ajax_m'])) {
        if (empty($members)) {
            echo '<tr><td colspan="5" class="text-center text-muted py-4">No members found matching "' . htmlspecialchars($m_search) . '"</td></tr>';
        } else {
            foreach($members as $m): 
                $latest = $m['latest_expiry'];
                $is_active = ($m['status'] === 'active');
                $qr_data = $m['qr_code'] ?: $m['id'];
                ?>
                <tr>
                    <td class="fw-bold name-cell"><?= htmlspecialchars($m['full_name']) ?></td>
                    <td style="font-family: monospace; color: #666;"><?= maskEmailPHP($m['email']) ?></td>
                    <td class="text-center"><button class="btn btn-sm btn-outline-dark border-0" onclick="viewQR('<?= $qr_data ?>','<?= addslashes($m['full_name']) ?>')"><i class="bi bi-qr-code fs-5"></i></button></td>
                    <td><span class="badge <?= $is_active?'bg-success-subtle text-success':'bg-danger-subtle text-danger' ?> border px-3"><?= $is_active?'Active':'Inactive' ?></span></td>
                    <td>
                        <?php if (!$is_active): ?>
                            <div class="d-flex gap-2">
                                <select class="form-select form-select-sm rate-select" style="width: 130px;"><option value="400">Student (400)</option><option value="500" selected>Regular (500)</option></select>
                                <button class="btn btn-dark btn-sm fw-bold" onclick="pay(<?= $m['id'] ?>, this)">PAY</button>
                            </div>
                        <?php else: ?>
                            <small class="fw-bold text-success text-uppercase">Until: <?= date('M d, Y', strtotime($m['latest_expiry'])) ?></small>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach;
        }
        $rows = ob_get_clean();
        ob_start();
        if ($m_total_pages > 1): ?>
            <ul class="pagination pagination-sm justify-content-center mb-0">
                <li class="page-item <?= ($m_page <= 1) ? 'disabled' : '' ?>"><a class="page-link" href="?filter=<?= $filter ?>&m_sort=<?= $m_sort ?>&s_sort=<?= $s_sort ?>&m_search=<?= urlencode($m_search) ?>&s_search=<?= urlencode($s_search) ?>&m_page=<?= $m_page-1 ?>&s_page=<?= $s_page ?>">Previous</a></li>
                <?php for($i=1; $i<=$m_total_pages; $i++): ?><li class="page-item <?= ($i == $m_page) ? 'active' : '' ?>"><a class="page-link" href="?filter=<?= $filter ?>&m_sort=<?= $m_sort ?>&s_sort=<?= $s_sort ?>&m_search=<?= urlencode($m_search) ?>&s_search=<?= urlencode($s_search) ?>&m_page=<?= $i ?>&s_page=<?= $s_page ?>"><?= $i ?></a></li><?php endfor; ?>
                <li class="page-item <?= ($m_page >= $m_total_pages) ? 'disabled' : '' ?>"><a class="page-link" href="?filter=<?= $filter ?>&m_sort=<?= $m_sort ?>&s_sort=<?= $s_sort ?>&m_search=<?= urlencode($m_search) ?>&s_search=<?= urlencode($s_search) ?>&m_page=<?= $m_page+1 ?>&s_page=<?= $s_page ?>">Next</a></li>
            </ul>
        <?php endif;
        $pagination = ob_get_clean();
    } else {
        if (empty($staffs)) {
            echo '<tr><td colspan="3" class="text-center text-muted py-4">No staff found matching "' . htmlspecialchars($s_search) . '"</td></tr>';
        } else {
            foreach($staffs as $s): ?>
                <tr>
                    <td class="fw-bold name-cell"><?= htmlspecialchars($s['full_name']) ?></td>
                    <td style="font-family: monospace; color: #666;"><?= maskEmailPHP($s['email']) ?></td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-primary border-0 me-2" onclick="editUser(<?= $s['id'] ?>,'<?= addslashes($s['full_name']) ?>','<?= addslashes($s['email']) ?>','staff','<?= $s['status'] ?>')"><i class="bi bi-pencil-square"></i></button>
                        <button class="btn btn-sm btn-outline-danger border-0" onclick="delUser(<?= $s['id'] ?>)"><i class="bi bi-trash3"></i></button>
                    </td>
                </tr>
            <?php endforeach;
        }
        $rows = ob_get_clean();
        ob_start();
        if ($s_total_pages > 1): ?>
            <ul class="pagination pagination-sm justify-content-center mb-0">
                <li class="page-item <?= ($s_page <= 1) ? 'disabled' : '' ?>"><a class="page-link" href="?filter=<?= $filter ?>&m_sort=<?= $m_sort ?>&s_sort=<?= $s_sort ?>&m_search=<?= urlencode($m_search) ?>&s_search=<?= urlencode($s_search) ?>&m_page=<?= $m_page ?>&s_page=<?= $s_page-1 ?>">Previous</a></li>
                <?php for($i=1; $i<=$s_total_pages; $i++): ?><li class="page-item <?= ($i == $s_page) ? 'active' : '' ?>"><a class="page-link" href="?filter=<?= $filter ?>&m_sort=<?= $m_sort ?>&s_sort=<?= $s_sort ?>&m_search=<?= urlencode($m_search) ?>&s_search=<?= urlencode($s_search) ?>&m_page=<?= $m_page ?>&s_page=<?= $i ?>"><?= $i ?></a></li><?php endfor; ?>
                <li class="page-item <?= ($s_page >= $s_total_pages) ? 'disabled' : '' ?>"><a class="page-link" href="?filter=<?= $filter ?>&m_sort=<?= $m_sort ?>&s_sort=<?= $s_sort ?>&m_search=<?= urlencode($m_search) ?>&s_search=<?= urlencode($s_search) ?>&m_page=<?= $m_page ?>&s_page=<?= $s_page+1 ?>">Next</a></li>
            </ul>
        <?php endif;
        $pagination = ob_get_clean();
    }
    header('Content-Type: application/json');
    echo json_encode(['rows' => $rows, 'pagination' => $pagination]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage Users | Arts Gym</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-red: #e63946; --bg-body: #f8f9fa; --bg-card: #ffffff;
            --text-main: #1a1a1a; --text-muted: #8e8e93; --border-color: #f1f1f1;
            --sidebar-width: 260px; --card-shadow: 0 10px 30px rgba(0, 0, 0, 0.04);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        body.dark-mode-active {
            --bg-body: #0a0a0a; --bg-card: #121212; --text-main: #f5f5f7;
            --text-muted: #86868b; --border-color: #1c1c1e; --card-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        body { 
            font-family: 'Inter', sans-serif; background-color: var(--bg-body); 
            color: var(--text-main); transition: var(--transition); letter-spacing: -0.01em;
        }

        #sidebar { width: var(--sidebar-width); height: 100vh; position: fixed; left: 0; top: 0; z-index: 1100; transition: var(--transition); }
        #main { margin-left: var(--sidebar-width); transition: var(--transition); min-height: 100vh; padding: 2rem; }
        
        .card-table { background: var(--bg-card); border-radius: 20px; padding: 24px; box-shadow: var(--card-shadow); border: none; margin-bottom: 2rem; }
        .table thead th { background: var(--bg-card); color: var(--text-muted); font-size: 0.75rem; text-transform: uppercase; border-bottom: 1px solid var(--border-color); padding: 15px; }
        
        @media (max-width: 991.98px) {
            #main { margin-left: 0 !important; padding: 1.5rem; }
            #sidebar { left: calc(var(--sidebar-width) * -1); }
            #sidebar.show { left: 0; }
        }

        /* --- FIX FOR MODAL VISIBILITY --- */
        .modal {
            z-index: 1150; /* Higher than the sidebar's 1100 */
        }
        .modal-backdrop {
            z-index: 1140; /* Higher than normal, but below the modal */
        }
        /* --- END FIX --- */
    </style>
</head>
<body class="<?= isset($_COOKIE['theme']) && $_COOKIE['theme'] == 'dark' ? 'dark-mode-active' : '' ?>">

<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<?php include '_sidebar.php'; ?>

<div id="main">
    <header class="top-header d-flex justify-content-between align-items-center mb-4">
        <div>
            <button class="btn btn-light me-2 d-lg-none" onclick="toggleSidebar()"><i class="bi bi-list"></i></button>
            <h4 class="mb-0 fw-bold">User Management</h4>
            <p class="text-muted small mb-0">ADMIN PANEL</p>
        </div>
        <?php include '../global_clock.php'; ?>  
    </header>

    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div class="btn-group bg-white p-1 rounded-3 shadow-sm border">
            <a href="?filter=all" class="btn btn-sm <?= $filter=='all'?'btn-danger active':'btn-light' ?> px-4 fw-bold">All Members</a>
            <a href="?filter=expiring" class="btn btn-sm <?= $filter=='expiring'?'btn-danger active':'btn-light' ?> px-4 fw-bold">Expiring Soon</a>
        </div>
        <button class="btn btn-dark btn-sm fw-bold px-3" onclick="openAddModal('member')">Add Member</button>
    </div>

    <!-- MEMBERS TABLE -->
    <div class="card-table">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <h6 class="fw-bold mb-0">Members Directory</h6>
            <div class="d-flex gap-2">
                <input type="text" id="mSearch" class="form-control bg-light border-0" style="width: 250px;" placeholder="Search name..." value="<?= htmlspecialchars($m_search) ?>">
            </div>
        </div>
        <div class="table-responsive">
            <table class="table align-middle" id="mTable">
                <thead>
                    <tr><th>Name</th><th>Email</th><th class="text-center">QR Pass</th><th>Status</th><th>Duration</th></tr>
                </thead>
                <tbody>
                    <?php if(empty($members)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">No members found.</td></tr>
                    <?php else: ?>
                        <?php foreach($members as $m): 
                            $is_active = ($m['status'] === 'active');
                            $qr_data = $m['qr_code'] ?: $m['id'];
                        ?>
                        <tr>
                            <td class="fw-bold"><?= htmlspecialchars($m['full_name']) ?></td>
                            <td style="font-family: monospace;"><?= maskEmailPHP($m['email']) ?></td>
                            <td class="text-center">
                                <button class="btn btn-sm btn-outline-dark border-0" onclick="viewQR('<?= $qr_data ?>','<?= addslashes($m['full_name']) ?>')">
                                    <i class="bi bi-qr-code fs-5"></i>
                                </button>
                            </td>
                            <td><span class="badge <?= $is_active?'bg-success-subtle text-success':'bg-danger-subtle text-danger' ?> border px-3"><?= $is_active?'Active':'Inactive' ?></span></td>
                            <td>
                                <?php if (!$is_active): ?>
                                    <div class="d-flex gap-2">
                                        <select class="form-select form-select-sm rate-select" style="width: 130px;"><option value="400">Student (400)</option><option value="500" selected>Regular (500)</option></select>
                                        <button class="btn btn-dark btn-sm fw-bold" onclick="pay(<?= $m['id'] ?>, this)">PAY</button>
                                    </div>
                                <?php else: ?>
                                    <small class="fw-bold text-success">Until: <?= date('M d, Y', strtotime($m['latest_expiry'])) ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div id="mPagination" class="mt-3"><?php /* Pagination rendered by JS */ ?></div>
    </div>

    <!-- STAFF TABLE -->
    <div class="card-table">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h6 class="fw-bold mb-0">Staff Management</h6>
            <button class="btn btn-dark btn-sm fw-bold" onclick="openAddModal('staff')">Add Staff</button>
        </div>
        <div class="table-responsive">
            <table class="table align-middle" id="sTable">
                <thead><tr><th>Name</th><th>Email</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                    <?php if(empty($staffs)): ?>
                        <tr><td colspan="3" class="text-center text-muted py-4">No staff found.</td></tr>
                    <?php else: ?>
                        <?php foreach($staffs as $s): ?>
                        <tr>
                            <td class="fw-bold"><?= htmlspecialchars($s['full_name']) ?></td>
                            <td><?= maskEmailPHP($s['email']) ?></td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-outline-primary border-0 me-2" onclick="editUser(<?= $s['id'] ?>,'<?= addslashes($s['full_name']) ?>','<?= addslashes($s['email']) ?>','staff','<?= $s['status'] ?>')"><i class="bi bi-pencil-square"></i></button>
                                <button class="btn btn-sm btn-outline-danger border-0" onclick="delUser(<?= $s['id'] ?>)"><i class="bi bi-trash3"></i></button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div id="sPagination" class="mt-3"><?php /* Pagination rendered by JS */ ?></div>
    </div>
</div>

<!-- Add/Edit User Modal -->
<div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 p-4 shadow-lg">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-bold" id="modalTitle">User Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body pt-2">
        <input type="hidden" id="uRole"><input type="hidden" id="uId">
        <div class="mb-3"><label class="small fw-bold">Full Name *</label><input type="text" id="uName" class="form-control"></div>
        <div class="mb-3"><label class="small fw-bold">Email *</label><input type="email" id="uEmail" class="form-control"></div>
        <div class="mb-3 d-none" id="sGrp"><label class="small fw-bold">Status</label><select id="uStatus" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
        <div class="mb-4" id="pGrp">
            <label class="small fw-bold">Password *</label>
            <input type="password" id="uPass" class="form-control" placeholder="Min 8 characters">
        </div>
        <button class="btn btn-danger w-100 fw-bold py-2" id="saveUserBtn">Save Changes</button>
      </div>
    </div>
  </div>
</div>

<!-- QR Modal -->
<div class="modal fade" id="qrModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content text-center p-4">
      <div class="modal-header border-0 pb-0">
        <h6 id="qrName" class="fw-bold mb-0">User QR Code</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <img id="qrImg" src="" class="img-fluid rounded shadow-sm" style="width: 250px;">
      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
    let uM, qM;

    $(document).ready(function() {
        // Correctly initialize Bootstrap Modal instances
        uM = new bootstrap.Modal(document.getElementById('userModal'));
        qM = new bootstrap.Modal(document.getElementById('qrModal'));
    });

    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        sidebar.classList.toggle('show');
        document.getElementById('sidebarOverlay').classList.toggle('show');
    }
    
    function viewQR(c, n) { 
        $('#qrName').text(n); 
        $('#qrImg').attr('src', `../generate_qr.php?data=${encodeURIComponent(c)}`); 
        qM.show(); 
    }

    function openAddModal(r) { 
        $('#userModal form').trigger('reset'); // Reset form fields
        $('#uId').val(''); 
        $('#uRole').val(r);
        $('#pGrp').show(); 
        $('#sGrp').hide(); 
        $('#modalTitle').text('New ' + r.charAt(0).toUpperCase() + r.slice(1));
        $('#saveUserBtn').off('click').on('click', saveCreate); 
        uM.show(); 
    }
    
    function editUser(id,n,e,r,s) { 
        $('#userModal form').trigger('reset');
        $('#uId').val(id); 
        $('#uRole').val(r); 
        $('#uName').val(n); 
        $('#uEmail').val(e); 
        $('#uStatus').val(s);
        $('#pGrp').hide(); 
        $('#sGrp').show(); 
        $('#modalTitle').text('Edit ' + r.charAt(0).toUpperCase() + r.slice(1));
        $('#saveUserBtn').off('click').on('click', saveUpdate); 
        uM.show(); 
    }

    function saveCreate() {
        const data = { action:'create', full_name:$('#uName').val(), email:$('#uEmail').val(), password:$('#uPass').val(), role:$('#uRole').val() };
        $.post('admin_user_actions.php', data, (res) => { 
            if(res.status==='success') { location.reload(); } else { alert(res.message); }
        }, 'json');
    }

    function saveUpdate() {
        const data = { action:'update', id:$('#uId').val(), full_name:$('#uName').val(), email:$('#uEmail').val(), status:$('#uStatus').val() };
        $.post('admin_user_actions.php', data, (res) => { 
            if(res.status==='success') { location.reload(); } else { alert(res.message); }
        }, 'json');
    }

    function pay(id, btn) { 
        const amount = $(btn).siblings('.rate-select').val();
        if(!confirm('Confirm payment of ' + amount + '?')) return;
        $(btn).prop('disabled', true).text('...'); 
        $.post('register_payment.php', { user_id: id, amount: amount, duration: 1 }, (res) => {
            if(res.status==='success') {
                location.reload();
            } else { 
                alert(res.message); 
                $(btn).prop('disabled', false).text('PAY'); 
            }
        }, 'json'); 
    }

    function delUser(id) { 
        if(confirm('Are you sure you want to delete this user? This action cannot be undone.')) {
            $.post('admin_user_actions.php', { action: 'delete', id: id }, () => location.reload()); 
        }
    }
    
    let searchTimer;
    function performSearch(tableType) {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            const searchInput = (tableType === 'member') ? $('#mSearch') : $('#sSearch');
            const searchValue = searchInput.val().trim();
            const url = new URL(window.location.href);
            
            if (tableType === 'member') {
                url.searchParams.set('m_search', searchValue);
                url.searchParams.set('ajax_m', '1');
            } else {
                url.searchParams.set('s_search', searchValue);
                url.searchParams.set('ajax_s', '1');
            }

            $.get(url.toString(), function(res) {
                if (tableType === 'member') {
                    $('#mTable tbody').html(res.rows);
                    $('#mPagination').html(res.pagination);
                } else {
                    $('#sTable tbody').html(res.rows);
                    $('#sPagination').html(res.pagination);
                }
            }, 'json');
        }, 350);
    }

    $('#mSearch').on('keyup', () => performSearch('member'));
    $('#sSearch').on('keyup', () => performSearch('staff'));

    (function() { 
        if (localStorage.getItem('arts-gym-theme') === 'dark') {
            document.body.classList.add('dark-mode-active'); 
        }
    })();
</script>
</body>
</html>
