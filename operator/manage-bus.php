<?php
session_start();
require_once '../includes/config.php';

// Database connection
function getDatabaseConnection() {
    static $db = null;
    if ($db === null) {
        try {
            $db = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }
    return $db;
}

// Authentication
function authenticateUser($requiredType) {
    if (!isset($_SESSION['user_id'])) {
        return false;
    }

    $db = getDatabaseConnection();
    $stmt = $db->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return ($user && $user['user_type'] === $requiredType) ? $user : false;
}

// Get operator by user ID
function getOperatorByUserId($userId) {
    $db = getDatabaseConnection();
    $stmt = $db->prepare("SELECT * FROM operators WHERE user_id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Main logic
$user = authenticateUser('operator');
if (!$user) {
    header('Location: /login.php?redirect=operator/available-buses.php');
    exit;
}

$operator = getOperatorByUserId($user['user_id']);
if (!$operator) {
    die('Operator account not properly configured');
}

// Get buses
$buses = [];
try {
    $db = getDatabaseConnection();
    $stmt = $db->prepare("
        SELECT b.*, bt.type_name, bt.has_ac, bt.has_wifi, bt.has_toilet 
        FROM buses b
        JOIN bus_types bt ON b.type_id = bt.type_id
        WHERE b.operator_id = ?
        ORDER BY b.status, b.name
    ");
    $stmt->execute([$operator['operator_id']]);
    $buses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die('Failed to load buses: ' . $e->getMessage());
}

// Handle status change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bus_id']) && isset($_POST['status'])) {
    $busId = intval($_POST['bus_id']);
    $status = in_array($_POST['status'], ['active', 'maintenance', 'inactive']) ? $_POST['status'] : 'active';
    try {
        $db = getDatabaseConnection();
        $stmt = $db->prepare("UPDATE buses SET status = ?, is_active = ? WHERE bus_id = ? AND operator_id = ?");
        $isActive = $status === 'active' ? 1 : 0;
        $stmt->execute([$status, $isActive, $busId, $operator['operator_id']]);
        header("Location: manage-bus.php");
        exit;
    } catch (PDOException $e) {
        die('Failed to update bus status: ' . $e->getMessage());
    }
}
?>

<?php include 'header.php'; ?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Available Buses</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    
    <!-- Bootstrap 5 CSS with dark mode support -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    
    <!-- Custom CSS -->
    <style>
        :root {
            --primary-color: #4e73df;
            --secondary-color: #858796;
            --success-color: #1cc88a;
            --info-color: #36b9cc;
            --warning-color: #f6c23e;
            --danger-color: #e74a3b;
            --light-color: #f8f9fc;
            --dark-color: #5a5c69;
        }
        
        body {
            background-color: #f8f9fc;
            color: #333;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .card {
            border: none;
            border-radius: 0.5rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
            transition: all 0.3s ease;
        }
        
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 0.5rem 2rem 0 rgba(58, 59, 69, 0.2);
        }
        
        .card-header {
            background-color: #f8f9fc;
            border-bottom: 1px solid #e3e6f0;
            padding: 1rem 1.35rem;
            font-weight: 600;
        }
        
        .bus-card {
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .bus-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background-color: var(--primary-color);
            transition: all 0.3s ease;
        }
        
        .bus-card:hover::before {
            width: 6px;
        }
        
        .status-active {
            border-left: 4px solid var(--success-color);
        }
        
        .status-maintenance {
            border-left: 4px solid var(--warning-color);
        }
        
        .status-inactive {
            border-left: 4px solid var(--danger-color);
        }
        
        .feature-badge {
            margin-right: 4px;
            font-size: 0.75rem;
            padding: 0.35em 0.65em;
        }
        
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        .table {
            margin-bottom: 0;
        }
        
        .table th {
            font-weight: 600;
            color: var(--dark-color);
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.5px;
            border-top: none;
            padding: 1rem 1.35rem;
        }
        
        .table td {
            padding: 1rem 1.35rem;
            vertical-align: middle;
            border-top: 1px solid #e3e6f0;
        }
        
        .table-hover tbody tr:hover {
            background-color: rgba(0, 0, 0, 0.02);
        }
        
        .status-select {
            width: 120px;
            cursor: pointer;
            border-radius: 0.35rem;
            padding: 0.25rem 0.5rem;
            border: 1px solid #d1d3e2;
            transition: all 0.3s;
        }
        
        .status-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(78, 115, 223, 0.25);
        }
        
        .btn-action {
            width: 32px;
            height: 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            border-radius: 50%;
        }
        
        .bus-img {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 0.35rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }
        
        /* Animation */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .animate-fade-in {
            animation: fadeIn 0.4s ease-out forwards;
        }
        
        /* Toast notification */
        .toast-container {
            position: fixed;
            bottom: 1.5rem;
            right: 1.5rem;
            z-index: 1100;
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .table th, .table td {
                padding: 0.75rem;
            }
            
            .bus-img {
                width: 45px;
                height: 45px;
            }
            
            .status-select {
                width: 100px;
            }
        }
        
        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }
        
        /* Loading spinner */
        .loading-spinner {
            display: none;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="container py-4">
        <!-- Header with stats -->
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
            <div class="mb-3 mb-md-0">
                <h1 class="h3 mb-2 text-gray-800">Available Buses</h1>
                <p class="mb-0 text-muted">Manage your fleet of buses</p>
            </div>
            <div>
                <a href="add-bus.php" class="btn btn-primary shadow-sm">
                    <i class="bi bi-plus-circle me-2"></i>Add New Bus
                </a>
            </div>
        </div>
        
        <!-- Stats Cards -->
        <div class="row mb-4 g-4">
            <div class="col-md-4">
                <div class="card border-left-success bus-card h-100 animate-fade-in" style="animation-delay: 0.1s">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-uppercase text-success fw-bold mb-1">Active Buses</h6>
                                <h2 class="mb-0 fw-bold">
                                    <?= count(array_filter($buses, fn($bus) => $bus['status'] === 'active')) ?>
                                </h2>
                            </div>
                            <div class="bg-success bg-opacity-10 p-3 rounded">
                                <i class="bi bi-check-circle-fill text-success fs-4"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card border-left-warning bus-card h-100 animate-fade-in" style="animation-delay: 0.2s">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-uppercase text-warning fw-bold mb-1">In Maintenance</h6>
                                <h2 class="mb-0 fw-bold">
                                    <?= count(array_filter($buses, fn($bus) => $bus['status'] === 'maintenance')) ?>
                                </h2>
                            </div>
                            <div class="bg-warning bg-opacity-10 p-3 rounded">
                                <i class="bi bi-tools text-warning fs-4"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card border-left-danger bus-card h-100 animate-fade-in" style="animation-delay: 0.3s">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-uppercase text-danger fw-bold mb-1">Inactive Buses</h6>
                                <h2 class="mb-0 fw-bold">
                                    <?= count(array_filter($buses, fn($bus) => $bus['status'] === 'inactive')) ?>
                                </h2>
                            </div>
                            <div class="bg-danger bg-opacity-10 p-3 rounded">
                                <i class="bi bi-slash-circle-fill text-danger fs-4"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bus Table -->
        <div class="card shadow mb-4 animate-fade-in" style="animation-delay: 0.4s">
            <div class="card-header d-flex flex-column flex-md-row justify-content-between align-items-center py-3">
                <h6 class="m-0 font-weight-bold text-primary">Bus List</h6>
                <div class="mt-2 mt-md-0">
                    <button class="btn btn-sm btn-outline-primary me-2" id="toggleFilters">
                        <i class="bi bi-funnel me-1"></i>Filters
                    </button>
                    <button class="btn btn-sm btn-outline-secondary" id="refreshBtn">
                        <i class="bi bi-arrow-clockwise me-1"></i>Refresh
                    </button>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Bus Details</th>
                                <th>Registration</th>
                                <th>Type</th>
                                <th>Capacity</th>
                                <th>Features</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($buses as $bus): ?>
                                <tr class="status-<?= $bus['status'] ?>">
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <?php if ($bus['image_url']): ?>
                                                <img src="<?= htmlspecialchars($bus['image_url']) ?>" 
                                                     class="bus-img me-3" 
                                                     alt="<?= htmlspecialchars($bus['name']) ?>">
                                            <?php else: ?>
                                                <div class="bus-img me-3 bg-light d-flex align-items-center justify-content-center">
                                                    <i class="bi bi-bus-front text-muted fs-4"></i>
                                                </div>
                                            <?php endif; ?>
                                            <div>
                                                <strong><?= htmlspecialchars($bus['name']) ?></strong>
                                                <div class="text-muted small"><?= htmlspecialchars($bus['model']) ?> (<?= $bus['year'] ?>)</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark">
                                            <?= htmlspecialchars($bus['registration_number']) ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($bus['type_name']) ?></td>
                                    <td>
                                        <span class="badge bg-primary">
                                            <?= $bus['seat_capacity'] ?> seats
                                        </span>
                                    </td>
                                    <td>
                                        <div class="d-flex flex-wrap gap-1">
                                            <?php if ($bus['has_ac']): ?>
                                                <span class="badge bg-info text-dark feature-badge">
                                                    <i class="bi bi-snow2 me-1"></i>AC
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($bus['has_wifi']): ?>
                                                <span class="badge bg-info text-dark feature-badge">
                                                    <i class="bi bi-wifi me-1"></i>WiFi
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($bus['has_toilet']): ?>
                                                <span class="badge bg-info text-dark feature-badge">
                                                    <i class="bi bi-droplet me-1"></i>Toilet
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($bus['features']): ?>
                                                
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <form method="post" class="status-form">
                                            <input type="hidden" name="bus_id" value="<?= $bus['bus_id'] ?>">
                                            <select name="status" class="form-select form-select-sm status-select">
                                                <option value="active" <?= $bus['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                                <option value="maintenance" <?= $bus['status'] === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                                                <option value="inactive" <?= $bus['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                            </select>
                                        </form>
                                    </td>
                                    <td>
                                        <div class="d-flex gap-2">
                                            <a href="edit-bus.php?id=<?= $bus['bus_id'] ?>" 
                                               class="btn btn-sm btn-action btn-outline-primary"
                                               data-bs-toggle="tooltip" 
                                               data-bs-title="Edit Bus">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <form method="post" 
                                                  action="delete-bus.php" 
                                                  onsubmit="return confirm('Are you sure you want to delete this bus?');"
                                                  class="delete-form">
                                                <input type="hidden" name="bus_id" value="<?= $bus['bus_id'] ?>">
                                                <button type="submit" 
                                                        class="btn btn-sm btn-action btn-outline-danger"
                                                        data-bs-toggle="tooltip" 
                                                        data-bs-title="Delete Bus">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (count($buses) === 0): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-5">
                                        <i class="bi bi-bus fs-1 text-muted opacity-50 mb-3"></i>
                                        <h5 class="fw-light">No buses available</h5>
                                        <p class="text-muted">Add your first bus to get started</p>
                                        <a href="add-bus.php" class="btn btn-primary">
                                            <i class="bi bi-plus-circle me-2"></i>Add Bus
                                        </a>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast Notification -->
    <div class="toast-container">
        <div id="statusToast" class="toast align-items-center text-white bg-success border-0" role="alert">
            <div class="d-flex">
                <div class="toast-body">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    Bus status updated successfully
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Custom JavaScript -->
    <script>
        // Initialize tooltips
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
        
        // Status change handler
        document.querySelectorAll('.status-select').forEach(select => {
            select.addEventListener('change', function () {
                const form = this.closest('form');
                const submitBtn = form.querySelector('button[type="submit"]');
                
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<span class="loading-spinner"></span>';
                }
                
                // Simulate network delay for demo
                setTimeout(() => {
                    form.submit();
                    const toast = new bootstrap.Toast(document.getElementById('statusToast'));
                    toast.show();
                    
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = 'Submit';
                    }
                }, 800);
            });
        });
        
        // Toggle filters
        document.getElementById('toggleFilters').addEventListener('click', function() {
            // For demo purposes - would implement actual filter functionality
            const toast = new bootstrap.Toast(document.getElementById('statusToast'));
            document.getElementById('statusToast').classList.remove('bg-success');
            document.getElementById('statusToast').classList.add('bg-info');
            document.querySelector('#statusToast .toast-body').innerHTML = 
                '<i class="bi bi-info-circle-fill me-2"></i>Filter functionality coming soon';
            toast.show();
        });
        
        // Refresh button
        document.getElementById('refreshBtn').addEventListener('click', function() {
            window.location.reload();
        });
        
        // Delete form confirmation
        document.querySelectorAll('.delete-form').forEach(form => {
            form.addEventListener('submit', function(e) {
                if (!confirm('Are you sure you want to delete this bus? This action cannot be undone.')) {
                    e.preventDefault();
                }
            });
        });
    </script>
</body>
</html>