<?php
// admin/manage_passengers.php
session_start();

// --- AUTH & PERMISSIONS ---
if (!isset($_SESSION['loggedin']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

require_once '../includes/config.php';
include 'navbar.php';

// --- CSRF TOKEN ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// --- PAGINATION & FILTERS ---
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

$filters = [];
$whereClauses = [];
$params = [];

if (!empty($_GET['status'])) {
    $filters['status'] = $_GET['status'];
    $whereClauses[] = 'u.status = ?';
    $params[] = $_GET['status'];
}

if (!empty($_GET['search'])) {
    $searchTerm = '%' . $_GET['search'] . '%';
    $whereClauses[] = '(u.username LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR CAST(u.user_id AS CHAR) LIKE ?)';
    array_push($params, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
}

$whereSQL = $whereClauses ? ('WHERE ' . implode(' AND ', $whereClauses)) : '';

// --- FETCH USERS with wallet balance JOIN ---
$sql = "SELECT u.user_id, u.username, u.email, u.status, u.created_at,
        IFNULL(w.balance, 0) AS wallet_balance
        FROM users u
        LEFT JOIN wallet w ON u.user_id = w.user_id
        $whereSQL
        ORDER BY u.created_at DESC
        LIMIT ?, ?";
$stmt = $conn->prepare($sql);

if ($stmt) {
    $types = str_repeat('s', count($params)) . 'ii';
    $params[] = $offset;
    $params[] = $limit;
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $users = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $users = [];
}

// --- FETCH TOTAL COUNT for pagination ---
$countSQL = "SELECT COUNT(*) as total FROM users u $whereSQL";
$stmt = $conn->prepare($countSQL);
if ($stmt) {
    $bindParams = array_slice($params, 0, -2);
    if (!empty($bindParams)) {
        $types = str_repeat('s', count($bindParams));
        $stmt->bind_param($types, ...$bindParams);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $totalUsers = $result->fetch_assoc()['total'];
    $stmt->close();
} else {
    $totalUsers = 0;
}

$totalPages = ceil($totalUsers / $limit);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Passengers - Admin Panel</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .badge-status {
            font-size: 0.9em;
            font-weight: 600;
        }
        tbody tr:hover {
            background-color: #e9f5ff;
        }
        .modal.fade .modal-dialog {
            transition: transform 0.3s ease-out;
            transform: translateY(-50px);
        }
        .modal.fade.show .modal-dialog {
            transform: translateY(0);
        }
    </style>
</head>
<body>

<div class="container mt-4">
    <!-- Filters Card -->
    <div class="card shadow-sm mb-4">
        <div class="card-header">
            <h5>Filters</h5>
        </div>
        <form method="GET" class="card-body row gy-2 gx-3 align-items-end">
            <div class="col-md-4">
                <label for="search" class="form-label">Search</label>
                <input type="text" class="form-control" id="search" name="search" 
                       placeholder="Search by name, email, phone or ID" 
                       value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <label for="status" class="form-label">Status</label>
                <select class="form-select" id="status" name="status">
                    <option value="">All Statuses</option>
                    <option value="Active" <?= (($_GET['status'] ?? '') === 'Active') ? 'selected' : '' ?>>Active</option>
                    <option value="Suspended" <?= (($_GET['status'] ?? '') === 'Suspended') ? 'selected' : '' ?>>Suspended</option>
                    <option value="Unverified" <?= (($_GET['status'] ?? '') === 'Unverified') ? 'selected' : '' ?>>Unverified</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">Apply</button>
            </div>
            <div class="col-md-2">
                <a href="manage_passengers.php" class="btn btn-outline-secondary w-100">Reset</a>
            </div>
        </form>
    </div>

    <!-- Users Table -->
    <div class="table-responsive shadow-sm">
        <table class="table table-striped table-hover align-middle">
            <thead class="table-primary">
                <tr>
                    <th>User ID</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th>Registered</th>
                    <th>Wallet Balance</th>
                    <th>Edit</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <tr><td colspan="7" class="text-center">No users found matching filters.</td></tr>
                <?php else: ?>
                    <?php foreach ($users as $user): 
                        $status = $user['status'] ?? 'Unknown';
                        $badgeClass = match ($status) {
                            'Active' => 'success',
                            'Suspended' => 'danger',
                            'Unverified' => 'warning',
                            default => 'secondary',
                        };
                    ?>
                    <tr data-user-id="<?= htmlspecialchars($user['user_id']) ?>">
                        <td><?= htmlspecialchars($user['user_id']) ?></td>
                        <td><?= htmlspecialchars($user['username']) ?></td>
                        <td><?= htmlspecialchars($user['email']) ?></td>
                        <td><span class="badge bg-<?= $badgeClass ?> badge-status"><?= htmlspecialchars($status) ?></span></td>
                        <td><?= date('Y-m-d', strtotime($user['created_at'])) ?></td>
                        <td>Rs. <?= number_format((float)$user['wallet_balance'], 2) ?></td>
                        <td>
                            <button class="btn btn-sm btn-primary btn-edit-user" title="Edit User">
                                <i class="fa fa-edit"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <nav aria-label="Page navigation" class="mt-3">
        <ul class="pagination justify-content-center">
            <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">Previous</a>
            </li>
            <?php
            $startPage = max(1, $page - 2);
            $endPage = min($totalPages, $page + 2);

            for ($i = $startPage; $i <= $endPage; $i++): ?>
                <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                </li>
            <?php endfor; ?>

            <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Next</a>
            </li>
        </ul>
    </nav>
</div>

<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" id="editUserForm">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title" id="editUserLabel">Edit User</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
          <input type="hidden" name="user_id" id="editUserId">
          <div class="mb-3">
              <label for="editUsername" class="form-label">Username</label>
              <input type="text" class="form-control" id="editUsername" name="username" required>
          </div>
          <div class="mb-3">
              <label for="editEmail" class="form-label">Email</label>
              <input type="email" class="form-control" id="editEmail" name="email" required>
          </div>
          <div class="mb-3">
              <label for="editStatus" class="form-label">Status</label>
              <select class="form-select" id="editStatus" name="status" required>
                  <option value="Active">Active</option>
                  <option value="Suspended">Suspended</option>
                  <option value="Unverified">Unverified</option>
              </select>
          </div>
          <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
      </div>
      <div class="modal-footer">
          <button type="submit" class="btn btn-primary">Save Changes</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Toast Notification -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1100;">
    <div id="toastNotification" class="toast align-items-center text-white bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body" id="toastMessage"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
$(function() {
    const toastEl = new bootstrap.Toast($('#toastNotification')[0]);

    // Edit user button click
    $('.btn-edit-user').on('click', function() {
        const row = $(this).closest('tr');
        const userId = row.data('user-id');
        const username = row.find('td:nth-child(2)').text();
        const email = row.find('td:nth-child(3)').text();
        const status = row.find('.badge').text();
        
        $('#editUserId').val(userId);
        $('#editUsername').val(username);
        $('#editEmail').val(email);
        $('#editStatus').val(status);
        
        const modal = new bootstrap.Modal(document.getElementById('editUserModal'));
        modal.show();
    });

    // Edit form submission
    $('#editUserForm').on('submit', function(e) {
        e.preventDefault();
        const formData = $(this).serialize();
        
        $.ajax({
            url: 'ajax/update_user.php',
            method: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    showToast('User updated successfully', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast(response.error || 'Failed to update user', 'danger');
                }
            },
            error: function() {
                showToast('Error updating user', 'danger');
            }
        });
    });

    function showToast(message, type = 'success') {
        $('#toastMessage').text(message);
        const toast = $('#toastNotification');
        toast.removeClass('bg-success bg-danger bg-warning').addClass('bg-' + type);
        new bootstrap.Toast(toast[0]).show();
    }
});
</script>
</body>
</html>