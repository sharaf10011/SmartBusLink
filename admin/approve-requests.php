<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Admin authentication check
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: /admin/login.php");
    exit;
}

// Process actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'])) {
        $_SESSION['error'] = "Invalid CSRF token";
        header("Location: approve-requests.php");
        exit;
    }
    
    $requestId = (int)$_POST['request_id'];
    $action = $_POST['action'];
    $adminNotes = $conn->real_escape_string($_POST['admin_notes'] ?? '');
    
    // Get request details
    $request = getOperatorRequest($requestId);
    
    if (!$request) {
        $_SESSION['error'] = "Invalid request ID";
        header("Location: approve-requests.php");
        exit;
    }
    
    if ($action === 'approve') {
        // Validate required fields
        $requiredFields = ['company_name', 'contact_person', 'contact_number', 'email'];
        foreach ($requiredFields as $field) {
            if (empty($request[$field])) {
                $_SESSION['error'] = "Cannot approve - missing required field: " . str_replace('_', ' ', $field);
                header("Location: approve-requests.php");
                exit;
            }
        }

        // Create user account first
        $tempPassword = generateRandomString(10);
        $hashedPassword = password_hash($tempPassword, PASSWORD_DEFAULT);
        
        $userData = [
            'first_name' => explode(' ', $request['contact_person'])[0],
            'last_name' => explode(' ', $request['contact_person'])[1] ?? '',
            'email' => $request['email'],
            'password_hash' => $hashedPassword,
            'user_type' => 'operator',
            'phone' => $request['contact_number'],
            'is_verified' => 1,
            'status' => 'Active'
        ];
        
        if (insertRecord('users', $userData)) {
            $userId = $conn->insert_id;
            
            // Create operator record with available data
            $operatorData = [
                'user_id' => $userId,
                'company_name' => $request['company_name'],
                'contact_person' => $request['contact_person'],
                'email' => $request['email'],
                'phone' => $request['contact_number'],
                'fleet_size' => $request['fleet_size'] ?? null,
                'routes' => $request['routes'] ?? null,
                'is_approved' => 1,
                'approval_date' => date('Y-m-d H:i:s')
            ];
            
            if (insertRecord('operators', $operatorData)) {
                // Update request status
                updateRequestStatus($requestId, 'approved', $adminNotes, $_SESSION['user_id']);
                
                // Send email with credentials
                sendApprovalEmail($request['email'], $request['contact_person'], $tempPassword);
                
                $_SESSION['success'] = "Operator account created successfully. Temporary password sent to " . htmlspecialchars($request['email']);
            } else {
                // Rollback user creation if operator creation fails
                $conn->query("DELETE FROM users WHERE user_id = $userId");
                $_SESSION['error'] = "Failed to create operator record: " . $conn->error;
            }
        } else {
            $_SESSION['error'] = "Failed to create user account: " . $conn->error;
        }
    } elseif ($action === 'reject') {
        updateRequestStatus($requestId, 'rejected', $adminNotes, $_SESSION['user_id']);
        sendRejectionEmail($request['email'], $request['contact_person'], $adminNotes);
        $_SESSION['success'] = "Request rejected successfully. Notification sent to " . htmlspecialchars($request['email']);
    } elseif ($action === 'pending') {
        // Change status back to pending
        updateRequestStatus($requestId, 'pending', $adminNotes, $_SESSION['user_id']);
        $_SESSION['success'] = "Request status changed to pending successfully.";
    } elseif ($action === 'complete') {
        // Mark request as completed (additional step after approval)
        updateRequestStatus($requestId, 'completed', $adminNotes, $_SESSION['user_id']);
        $_SESSION['success'] = "Request marked as completed successfully.";
    }
    
    header("Location: approve-requests.php");
    exit;
}

// Get requests
$status = $_GET['status'] ?? 'pending';
$validStatuses = ['pending', 'approved', 'rejected', 'completed', 'all'];
$status = in_array($status, $validStatuses) ? $status : 'pending';

$whereClause = $status === 'all' ? "" : "WHERE status = '$status'";
$requests = $conn->query("SELECT * FROM operator_requests $whereClause ORDER BY request_date DESC");

// Helper functions
function getOperatorRequest($id) {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM operator_requests WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function updateRequestStatus($id, $status, $notes, $adminId) {
    global $conn;
    $stmt = $conn->prepare("UPDATE operator_requests SET status = ?, admin_notes = ?, processed_date = NOW(), processed_by = ? WHERE id = ?");
    $stmt->bind_param("ssii", $status, $notes, $adminId, $id);
    return $stmt->execute();
}

function sendApprovalEmail($email, $name, $password) {
    $subject = "Your Operator Account Has Been Approved";
    $message = "Dear $name,\n\n";
    $message .= "Your operator account has been approved.\n";
    $message .= "You can now login using this temporary password: $password\n";
    $message .= "Please change your password after logging in.\n\n";
    $message .= "Login URL: " . SITE_URL . "/operator/login.php\n\n";
    $message .= "Regards,\nSmartBusLink Team";
    
    return mail($email, $subject, $message);
}

function sendRejectionEmail($email, $name, $reason) {
    $subject = "Your Operator Registration Request";
    $message = "Dear $name,\n\n";
    $message .= "We regret to inform you that your operator registration request has been rejected.\n";
    if (!empty($reason)) {
        $message .= "Reason: $reason\n\n";
    }
    $message .= "If you believe this was in error, please contact our support team.\n\n";
    $message .= "Regards,\nSmartBusLink Team";
    
    return mail($email, $subject, $message);
}

function getStatusBadge($status) {
    switch ($status) {
        case 'approved': return 'success';
        case 'rejected': return 'danger';
        case 'completed': return 'info';
        default: return 'warning';
    }
}

function formatDate($date) {
    return date('M j, Y g:i a', strtotime($date));
}

function getAdminName($adminId) {
    global $conn;
    if (!$adminId) return 'System';
    $stmt = $conn->prepare("SELECT CONCAT(first_name, ' ', last_name) as name FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $adminId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    return $result ? $result['name'] : 'Unknown';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approve Operator Requests - SmartBusLink Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <style>
        .card {
            box-shadow: 0 0 12px rgba(0, 0, 0, 0.05);
            border-radius: 1rem;
        }

        .table-hover tbody tr:hover {
            background-color: #f9f9fc;
            transition: background-color 0.3s;
        }

        .badge {
            font-size: 0.9rem;
            padding: 0.4em 0.6em;
            border-radius: 0.6rem;
        }

        .alert {
            margin-bottom: 1.5rem;
        }

        h5 {
            font-weight: 600;
            margin: 0;
        }

        .table td, .table th {
            vertical-align: middle;
        }
        
        .status-filter .nav-link {
            color: #6c757d;
            font-weight: 500;
            border-radius: 0.5rem;
            padding: 0.5rem 1rem;
            margin-right: 0.5rem;
        }
        
        .status-filter .nav-link.active {
            color: #fff;
            background-color: #0d6efd;
        }
        
        .action-btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
            margin-right: 0.3rem;
        }
        
        .dropdown-menu {
            min-width: 10rem;
        }
        
        .dropdown-item {
            padding: 0.25rem 1rem;
        }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>
    
    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-lg-11">
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center bg-light">
                        <h5 class="text-primary"><i class="bi bi-person-check me-2"></i>Operator Requests</h5>
                        <div>
                            <a href="approve-requests.php?status=all" class="btn btn-sm btn-outline-secondary">View All</a>
                        </div>
                    </div>
                    
                    <div class="card-body">
                        <?php if (isset($_SESSION['error'])): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <?= htmlspecialchars($_SESSION['error']) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                            <?php unset($_SESSION['error']); ?>
                        <?php endif; ?>

                        <?php if (isset($_SESSION['success'])): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <?= htmlspecialchars($_SESSION['success']) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                            <?php unset($_SESSION['success']); ?>
                        <?php endif; ?>

                        <ul class="nav status-filter mb-4">
                            <li class="nav-item">
                                <a class="nav-link <?= $status === 'pending' ? 'active' : '' ?>" href="approve-requests.php?status=pending">Pending</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= $status === 'approved' ? 'active' : '' ?>" href="approve-requests.php?status=approved">Approved</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= $status === 'rejected' ? 'active' : '' ?>" href="approve-requests.php?status=rejected">Rejected</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= $status === 'completed' ? 'active' : '' ?>" href="approve-requests.php?status=completed">Completed</a>
                            </li>
                        </ul>

                        <?php if ($requests->num_rows > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Company</th>
                                            <th>Contact</th>
                                            <th>Email</th>
                                            <th>Fleet Size</th>
                                            <th>Request Date</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($request = $requests->fetch_assoc()): ?>
                                            <tr>
                                                <td><strong><?= htmlspecialchars($request['company_name']) ?></strong></td>
                                                <td>
                                                    <?= htmlspecialchars($request['contact_person']) ?><br>
                                                    <small class="text-muted"><?= htmlspecialchars($request['contact_number']) ?></small>
                                                </td>
                                                <td><?= htmlspecialchars($request['email']) ?></td>
                                                <td><?= htmlspecialchars($request['fleet_size']) ?></td>
                                                <td><?= formatDate($request['request_date']) ?></td>
                                                <td>
                                                    <span class="badge bg-<?= getStatusBadge($request['status']) ?>">
                                                        <?= ucfirst($request['status']) ?>
                                                    </span>
                                                    <?php if ($request['processed_date']): ?>
                                                        <br>
                                                        <small class="text-muted">by <?= getAdminName($request['processed_by']) ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="dropdown">
                                                        <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" id="actionDropdown<?= $request['id'] ?>" data-bs-toggle="dropdown" aria-expanded="false">
                                                            Actions
                                                        </button>
                                                        <ul class="dropdown-menu" aria-labelledby="actionDropdown<?= $request['id'] ?>">
                                                            <li>
                                                                <button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#detailModal<?= $request['id'] ?>">
                                                                    <i class="bi bi-eye me-2"></i>View Details
                                                                </button>
                                                            </li>
                                                            <?php if ($request['status'] === 'pending'): ?>
                                                                <li>
                                                                    <form method="post" action="approve-requests.php" class="d-inline">
                                                                        <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                                                        <input type="hidden" name="action" value="approve">
                                                                        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                                                        <button type="submit" class="dropdown-item text-success">
                                                                            <i class="bi bi-check-circle me-2"></i>Approve
                                                                        </button>
                                                                    </form>
                                                                </li>
                                                                <li>
                                                                    <button class="dropdown-item text-danger" data-bs-toggle="modal" data-bs-target="#rejectModal<?= $request['id'] ?>">
                                                                        <i class="bi bi-x-circle me-2"></i>Reject
                                                                    </button>
                                                                </li>
                                                            <?php elseif ($request['status'] === 'approved'): ?>
                                                                <li>
                                                                    <form method="post" action="approve-requests.php" class="d-inline">
                                                                        <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                                                        <input type="hidden" name="action" value="complete">
                                                                        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                                                        <button type="submit" class="dropdown-item text-info">
                                                                            <i class="bi bi-check-all me-2"></i>Mark Complete
                                                                        </button>
                                                                    </form>
                                                                </li>
                                                            <?php elseif ($request['status'] === 'rejected' || $request['status'] === 'completed'): ?>
                                                                <li>
                                                                    <form method="post" action="approve-requests.php" class="d-inline">
                                                                        <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                                                        <input type="hidden" name="action" value="pending">
                                                                        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                                                        <button type="submit" class="dropdown-item text-warning">
                                                                            <i class="bi bi-arrow-counterclockwise me-2"></i>Reopen
                                                                        </button>
                                                                    </form>
                                                                </li>
                                                            <?php endif; ?>
                                                        </ul>
                                                    </div>
                                                    
                                                    <!-- Detail Modal -->
                                                    <div class="modal fade" id="detailModal<?= $request['id'] ?>" tabindex="-1" aria-labelledby="detailModalLabel<?= $request['id'] ?>" aria-hidden="true">
                                                        <div class="modal-dialog modal-lg">
                                                            <div class="modal-content">
                                                                <div class="modal-header">
                                                                    <h5 class="modal-title" id="detailModalLabel<?= $request['id'] ?>">Request Details</h5>
                                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                                </div>
                                                                <div class="modal-body">
                                                                    <div class="row">
                                                                        <div class="col-md-6">
                                                                            <h6>Company Information</h6>
                                                                            <p><strong>Name:</strong> <?= htmlspecialchars($request['company_name']) ?></p>
                                                                            <p><strong>Contact Person:</strong> <?= htmlspecialchars($request['contact_person']) ?></p>
                                                                            <p><strong>Email:</strong> <?= htmlspecialchars($request['email']) ?></p>
                                                                            <p><strong>Phone:</strong> <?= htmlspecialchars($request['contact_number']) ?></p>
                                                                        </div>
                                                                        <div class="col-md-6">
                                                                            <h6>Operational Details</h6>
                                                                            <p><strong>Fleet Size:</strong> <?= htmlspecialchars($request['fleet_size']) ?></p>
                                                                            <p><strong>Routes:</strong> <?= htmlspecialchars($request['routes']) ?></p>
                                                                            <p><strong>Request Date:</strong> <?= formatDate($request['request_date']) ?></p>
                                                                            <?php if ($request['processed_date']): ?>
                                                                                <p><strong>Processed Date:</strong> <?= formatDate($request['processed_date']) ?></p>
                                                                                <p><strong>Processed By:</strong> <?= getAdminName($request['processed_by']) ?></p>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                    </div>
                                                                    <?php if (!empty($request['admin_notes'])): ?>
                                                                        <div class="mt-3">
                                                                            <h6>Admin Notes</h6>
                                                                            <div class="card bg-light p-3">
                                                                                <?= nl2br(htmlspecialchars($request['admin_notes'])) ?>
                                                                            </div>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                </div>
                                                                <div class="modal-footer">
                                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    
                                                    <!-- Reject Modal -->
                                                    <div class="modal fade" id="rejectModal<?= $request['id'] ?>" tabindex="-1" aria-labelledby="rejectModalLabel<?= $request['id'] ?>" aria-hidden="true">
                                                        <div class="modal-dialog">
                                                            <div class="modal-content">
                                                                <div class="modal-header">
                                                                    <h5 class="modal-title" id="rejectModalLabel<?= $request['id'] ?>">Reject Request</h5>
                                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                                </div>
                                                                <form method="post" action="approve-requests.php">
                                                                    <div class="modal-body">
                                                                        <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                                                        <input type="hidden" name="action" value="reject">
                                                                        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                                                        
                                                                        <div class="mb-3">
                                                                            <label for="rejectReason<?= $request['id'] ?>" class="form-label">Reason for Rejection</label>
                                                                            <textarea class="form-control" id="rejectReason<?= $request['id'] ?>" name="admin_notes" rows="3" required></textarea>
                                                                            <div class="form-text">This will be sent to the operator.</div>
                                                                        </div>
                                                                    </div>
                                                                    <div class="modal-footer">
                                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                        <button type="submit" class="btn btn-danger">Confirm Rejection</button>
                                                                    </div>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>No <?= htmlspecialchars($status) ?> requests found.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Enable Bootstrap tooltips
        const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        tooltipTriggerList.forEach(el => new bootstrap.Tooltip(el));
    </script>
</body>
</html>