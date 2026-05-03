<?php
session_start();
require_once('../includes/config.php');
require_once('../includes/auth.php');

// Check admin privileges before any output
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Database connection
try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Handle form submissions before any output
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_promo'])) {
        // Create new promo code
        $code = strtoupper($_POST['code']);
        $discount_type = $_POST['discount_type'];
        $discount_value = $_POST['discount_value'];
        $min_order = $_POST['min_order'];
        $max_uses = $_POST['max_uses'];
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        try {
            $stmt = $pdo->prepare("INSERT INTO promo_codes 
                (code, discount_type, discount_value, min_order, max_uses, uses_count, start_date, end_date, is_active) 
                VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?)");
            $stmt->execute([$code, $discount_type, $discount_value, $min_order, $max_uses, $start_date, $end_date, $is_active]);
            $_SESSION['success'] = "Promo code created successfully!";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Error creating promo code: " . $e->getMessage();
        }
    } elseif (isset($_POST['update_promo'])) {
    // Update existing promo code - toggle status
    $id = $_POST['id'];
    
    try {
        // First get current status
        $stmt = $pdo->prepare("SELECT is_active FROM promo_codes WHERE id = ?");
        $stmt->execute([$id]);
        $current_status = $stmt->fetchColumn();
        
        // Toggle the status
        $new_status = $current_status ? 0 : 1;
        
        // Update with new status
        $stmt = $pdo->prepare("UPDATE promo_codes SET is_active = ? WHERE id = ?");
        $stmt->execute([$new_status, $id]);
        
        $_SESSION['success'] = "Promo code status updated successfully!";
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error updating promo code status: " . $e->getMessage();
    }
    } elseif (isset($_POST['delete_promo'])) {
        // Delete promo code
        $id = $_POST['id'];
        
        try {
            $stmt = $pdo->prepare("DELETE FROM promo_codes WHERE id = ?");
            $stmt->execute([$id]);
            $_SESSION['success'] = "Promo code deleted successfully!";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Error deleting promo code: " . $e->getMessage();
        }
    }
    
    header("Location: promotions.php");
    exit();
}

// Fetch all promo codes
$promoCodes = [];
try {
    $stmt = $pdo->query("SELECT * FROM promo_codes ORDER BY created_at DESC");
    $promoCodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $_SESSION['error'] = "Error fetching promo codes: " . $e->getMessage();
}

// Function to format LKR currency
function formatLKR($amount) {
    return 'LKR ' . number_format($amount, 2);
}

include 'navbar.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Promo Code Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    
    <style>
        :root {
            --primary-color: #4e73df;
            --primary-light: #e8eefc;
            --secondary-color: #1cc88a;
            --secondary-light: #e6f7f1;
            --danger-color: #e74a3b;
            --danger-light: #fbe9e8;
            --warning-color: #f6c23e;
            --warning-light: #fef8e6;
            --info-color: #36b9cc;
            --info-light: #e8f6f9;
            --dark-color: #2e384d;
            --light-color: #f8f9fc;
            --border-radius: 0.75rem;
            --box-shadow: 0 0.5rem 1.5rem rgba(0, 0, 0, 0.08);
        }
        
        body {
            background-color: #f5f7fb;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: var(--dark-color);
        }
        
        .card {
            border: none;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            overflow: hidden;
            background-color: white;
        }
        
        .card-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, #3a56d6 100%);
            color: white;
            padding: 1.25rem 1.5rem;
            border-bottom: none;
        }
        
        .card-header h5 {
            font-weight: 600;
            margin-bottom: 0;
        }
        
        .promo-card {
            transition: all 0.3s ease;
            border-left: 4px solid;
            animation: fadeIn 0.5s ease-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .promo-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 1.5rem rgba(0, 0, 0, 0.12);
        }
        
        .promo-active {
            border-left-color: var(--secondary-color);
            background-color: var(--secondary-light);
        }
        
        .promo-inactive {
            border-left-color: var(--danger-color);
            background-color: var(--danger-light);
        }
        
        .discount-badge {
            font-size: 0.9rem;
            padding: 0.4rem 0.75rem;
            border-radius: 0.5rem;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        
        .table-responsive {
            border-radius: var(--border-radius);
            overflow: hidden;
        }
        
        .table {
            margin-bottom: 0;
        }
        
        .table th {
            background-color: #f8f9fa;
            font-weight: 600;
            color: var(--dark-color);
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.5px;
            padding: 1rem 1.25rem;
            border-bottom-width: 1px;
        }
        
        .table td {
            padding: 1rem 1.25rem;
            vertical-align: middle;
            border-color: #f0f2f7;
        }
        
        .table tbody tr {
            transition: all 0.2s ease;
        }
        
        .table tbody tr:hover {
            background-color: rgba(0, 0, 0, 0.02);
        }
        
        .btn-action {
            width: 34px;
            height: 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.2s ease;
            border: none;
            box-shadow: none;
        }
        
        .btn-action:hover {
            transform: scale(1.1);
        }
        
        .breadcrumb {
            background-color: transparent;
            padding: 0;
            font-size: 0.875rem;
        }
        
        .breadcrumb-item a {
            color: #6c757d;
            text-decoration: none;
            transition: color 0.2s;
        }
        
        .breadcrumb-item a:hover {
            color: var(--primary-color);
        }
        
        .alert {
            border: none;
            border-radius: var(--border-radius);
            padding: 1rem 1.25rem;
            box-shadow: var(--box-shadow);
        }
        
        .animate-delay-1 {
            animation-delay: 0.1s;
        }
        
        .animate-delay-2 {
            animation-delay: 0.2s;
        }
        
        .animate-delay-3 {
            animation-delay: 0.3s;
        }
        
        .form-control, .form-select, .input-group-text {
            border-radius: var(--border-radius);
            padding: 0.5rem 1rem;
            border-color: #e0e3eb;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(78, 115, 223, 0.15);
        }
        
        .input-group-text {
            background-color: #f8f9fa;
        }
        
        .progress {
            border-radius: 100px;
            height: 6px;
            background-color: #f0f2f7;
        }
        
        .progress-bar {
            border-radius: 100px;
        }
        
        .status-badge {
            font-size: 0.75rem;
            font-weight: 500;
            padding: 0.35rem 0.75rem;
            border-radius: 100px;
        }
        
        .search-box {
            position: relative;
            width: 250px;
        }
        
        .search-box .form-control {
            padding-left: 2.5rem;
            border-radius: 100px;
        }
        
        .search-box .bi-search {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
            z-index: 4;
        }
        
        .promo-code-preview {
            background: linear-gradient(135deg, #f5f7fb 0%, #e8eefc 100%);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            text-align: center;
            margin-bottom: 1.5rem;
            border: 1px dashed #d1d7e8;
        }
        
        .promo-code-preview h4 {
            font-family: 'Courier New', monospace;
            background-color: white;
            padding: 0.75rem;
            border-radius: 0.5rem;
            display: inline-block;
            margin-bottom: 0.5rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.05);
        }
        
        .promo-code-preview p {
            color: #6c757d;
            margin-bottom: 0;
            font-size: 0.875rem;
        }
        
        .form-section {
            margin-bottom: 1.5rem;
        }
        
        .form-section-title {
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            margin-bottom: 0.75rem;
            font-weight: 600;
        }
        
        .date-input-group {
            position: relative;
        }
        
        .date-input-group .bi {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
            pointer-events: none;
        }
        
        .empty-state {
            padding: 3rem 2rem;
            text-align: center;
            color: #6c757d;
        }
        
        .empty-state .bi {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #d1d7e8;
        }
        
        .empty-state h5 {
            font-weight: 500;
            margin-bottom: 0.5rem;
        }
        
        .empty-state p {
            margin-bottom: 1.5rem;
        }
        
        @media (max-width: 768px) {
            .card {
                margin-bottom: 1.5rem;
            }
            
            .search-box {
                width: 100%;
                margin-top: 1rem;
            }
        }

        
@media (max-width: 992px) {
    .col-lg-4, .col-lg-8 {
        width: 100%;
    }
    
    .card-header h5 {
        font-size: 1.1rem;
    }
    
    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    
    .table td, .table th {
        padding: 0.75rem;
        white-space: nowrap;
    }
    
    .status-badge, .discount-badge {
        font-size: 0.7rem;
        padding: 0.25rem 0.5rem;
    }
    
    .btn-action {
        width: 30px;
        height: 30px;
    }
}

@media (max-width: 768px) {
    .d-flex.justify-content-between {
        flex-direction: column;
        gap: 1rem;
    }
    
    .search-box {
        width: 100%;
        margin-top: 1rem;
    }
    
    .form-section-title {
        font-size: 0.8rem;
    }
    
    .promo-code-preview {
        padding: 1rem;
    }
    
    .empty-state {
        padding: 2rem 1rem;
    }
}

@media (max-width: 576px) {
    body {
        font-size: 0.9rem;
    }
    
    .card-header {
        padding: 0.75rem 1rem;
    }
    
    .card-body {
        padding: 1rem;
    }
    
    .table td, .table th {
        padding: 0.5rem;
    }
    
    .pagination {
        flex-wrap: wrap;
        justify-content: center;
    }
    
    .page-item {
        margin: 0.25rem;
    }
    
    .form-control, .form-select, .input-group-text {
        padding: 0.4rem 0.75rem;
    }
    
    .date-input-group .bi {
        right: 0.75rem;
    }
}
    </style>
</head>
<body>
    <div class="container mt-5">
        <div class="row mb-4 animate__animated animate__fadeIn">
            <div class="col">
                <div class="d-flex justify-content-between align-items-center flex-wrap">
                    <div>
                        <h1 class="h3 fw-bold text-dark"><i class="bi bi-tag-fill me-2 text-primary"></i>Promo Code Management</h1>
                        <nav aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="dashboard.php" class="text-decoration-none"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a></li>
                                <li class="breadcrumb-item active" aria-current="page"><i class="bi bi-tags me-1"></i>Promotions</li>
                            </ol>
                        </nav>
                    </div>
                    <div class="d-flex align-items-center">
                        <div class="dropdown me-2">
                            <button class="btn btn-outline-primary dropdown-toggle" type="button" id="filterDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-funnel me-1"></i> Filter
                            </button>
                            <ul class="dropdown-menu" aria-labelledby="filterDropdown">
                                <li><a class="dropdown-item" href="#">All Promo Codes</a></li>
                                <li><a class="dropdown-item" href="#">Active Only</a></li>
                                <li><a class="dropdown-item" href="#">Expired</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="#">Most Used</a></li>
                                <li><a class="dropdown-item" href="#">Least Used</a></li>
                            </ul>
                        </div>
                        <button class="btn btn-primary">
                            <i class="bi bi-download me-1"></i> Export
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show animate__animated animate__shakeX mb-4">
                <div class="d-flex align-items-center">
                    <i class="bi bi-exclamation-triangle-fill me-2 fs-4"></i>
                    <div><?= $_SESSION['error']; unset($_SESSION['error']); ?></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show animate__animated animate__fadeIn mb-4">
                <div class="d-flex align-items-center">
                    <i class="bi bi-check-circle-fill me-2 fs-4"></i>
                    <div><?= $_SESSION['success']; unset($_SESSION['success']); ?></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-lg-4 mb-4 animate__animated animate__fadeInLeft animate-delay-1">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-plus-circle me-2"></i>Create New Promo Code</h5>
                    </div>
                    <div class="card-body">
                        <div class="promo-code-preview">
                            <h4 class="text-primary" id="promoPreview">SUMMER25</h4>
                            <p id="discountPreview">25% discount on all orders</p>
                        </div>
                        
                        <form method="POST" id="promoForm">
                            <div class="form-section">
                                <div class="form-section-title">Promo Code Details</div>
                                <div class="mb-3">
                                    <label for="code" class="form-label fw-semibold">Promo Code</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control border-end-0" id="code" name="code" required 
                                               placeholder="e.g., SUMMER25" maxlength="20" oninput="updatePreview()">
                                        <button class="btn btn-outline-secondary border-start-0" type="button" id="generateCode" data-bs-toggle="tooltip" title="Generate random code">
                                            <i class="bi bi-shuffle"></i>
                                        </button>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Discount Type</label>
                                    <div class="d-flex gap-3">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="discount_type" 
                                                   id="percentage" value="percentage" checked onchange="updatePreview()">
                                            <label class="form-check-label" for="percentage">Percentage (%)</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="discount_type" 
                                                   id="fixed" value="fixed" onchange="updatePreview()">
                                            <label class="form-check-label" for="fixed">Fixed Amount (LKR)</label>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="discount_value" class="form-label fw-semibold">Discount Value</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light">LKR</span>
                                        <input type="number" class="form-control" id="discount_value" 
                                               name="discount_value" min="0" step="0.01" required oninput="updatePreview()">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-section">
                                <div class="form-section-title">Usage Rules</div>
                                <div class="mb-3">
                                    <label for="min_order" class="form-label fw-semibold">Minimum Order Amount</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light">LKR</span>
                                        <input type="number" class="form-control" id="min_order" 
                                               name="min_order" min="0" step="0.01" value="0">
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="max_uses" class="form-label fw-semibold">Maximum Uses</label>
                                    <input type="number" class="form-control" id="max_uses" 
                                           name="max_uses" min="0" placeholder="0 for unlimited">
                                </div>
                            </div>
                            
                            <div class="form-section">
                                <div class="form-section-title">Validity Period</div>
                                <div class="row mb-3">
                                    <div class="col-md-6 mb-2 mb-md-0">
                                        <label for="start_date" class="form-label fw-semibold">Start Date</label>
                                        <div class="date-input-group">
                                            <input type="datetime-local" class="form-control" id="start_date" 
                                                   name="start_date" required>
                                            <i class="bi bi-calendar3"></i>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="end_date" class="form-label fw-semibold">End Date</label>
                                        <div class="date-input-group">
                                            <input type="datetime-local" class="form-control" id="end_date" 
                                                   name="end_date">
                                            <i class="bi bi-calendar3"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-check form-switch mb-4">
                                <input class="form-check-input" type="checkbox" role="switch" id="is_active" 
                                       name="is_active" checked>
                                <label class="form-check-label fw-semibold" for="is_active">Active Promo Code</label>
                            </div>
                            
                            <button type="submit" name="create_promo" class="btn btn-primary w-100 py-2 fw-semibold">
                                <i class="bi bi-save me-2"></i> Create Promo Code
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-8 animate__animated animate__fadeInRight animate-delay-2">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
                        <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>Existing Promo Codes</h5>
                        <div class="search-box">
                            <i class="bi bi-search"></i>
                            <input type="text" class="form-control" placeholder="Search promo codes..." id="searchInput">
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($promoCodes)): ?>
                            <div class="empty-state">
                                <i class="bi bi-tags"></i>
                                <h5>No promo codes found</h5>
                                <p>Create your first promo code to start offering discounts to customers</p>
                                <button class="btn btn-primary">
                                    <i class="bi bi-plus-circle me-1"></i> Create Promo Code
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive" style="overflow-x: auto;">
    <table class="table table-hover mb-0" id="promoTable" style="min-width: 1000px;">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="w-25">Code</th>
                                            <th>Discount</th>
                                            <th>Min.Order</th>
                                            <th>Uses</th>
                                            <th>Dates</th>
                                            <th>Status</th>
                                            <th class="text-end">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($promoCodes as $promo): ?>
                                            <tr class="animate__animated animate__fadeIn">
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="flex-shrink-0 bg-primary bg-opacity-10 rounded p-2 me-2">
                                                            <i class="bi bi-tag text-primary"></i>
                                                        </div>
                                                        <div>
                                                            <strong class="d-block"><?= htmlspecialchars($promo['code']) ?></strong>
                                                            <small class="text-muted">
                                                                Created: <?= date('M j, Y', strtotime($promo['created_at'])) ?>
                                                            </small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if ($promo['discount_type'] === 'percentage'): ?>
    <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25 discount-badge">
        <i class="bi bi-percent me-1"></i>
        <?= number_format($promo['discount_value'], 2) ?>%
    </span>
<?php else: ?>
    <span class="badge bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25 discount-badge">
        LKR <?= number_format($promo['discount_value'], 2) ?>
    </span>
<?php endif; ?>
                                                </td>
                                                <td>
                                                    <?= $promo['min_order'] > 0 ? '<span class="fw-semibold">LKR ' . number_format($promo['min_order'], 2) . '</span>' : '<span class="text-muted">None</span>' ?>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="progress flex-grow-1 me-2" style="height: 6px;">
                                                            <?php 
                                                            $usagePercent = $promo['max_uses'] > 0 ? ($promo['uses_count'] / $promo['max_uses']) * 100 : 0;
                                                            $progressClass = $usagePercent > 80 ? 'bg-danger' : ($usagePercent > 50 ? 'bg-warning' : 'bg-success');
                                                            ?>
                                                            <div class="progress-bar <?= $progressClass ?>" role="progressbar" style="width: <?= min($usagePercent, 100) ?>%" 
                                                                 aria-valuenow="<?= $usagePercent ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                                        </div>
                                                        <small class="text-nowrap">
                                                            <?= $promo['uses_count'] ?>/<?= $promo['max_uses'] > 0 ? $promo['max_uses'] : '∞' ?>
                                                        </small>
                                                    </div>
                                                </td>
                                                <td>
                                                    <small class="d-block">
                                                        <i class="bi bi-calendar3 me-1"></i><?= date('M j', strtotime($promo['start_date'])) ?>
                                                    </small>
                                                    <small class="d-block">
                                                        <i class="bi bi-arrow-right me-1"></i><?= $promo['end_date'] ? date('M j, Y', strtotime($promo['end_date'])) : 'No end' ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <span class="status-badge bg-<?= $promo['is_active'] ? 'success' : 'danger' ?>-subtle text-<?= $promo['is_active'] ? 'success' : 'danger' ?>">
                                                        <i class="bi bi-<?= $promo['is_active'] ? 'check-circle' : 'x-circle' ?> me-1"></i>
                                                        <?= $promo['is_active'] ? 'Active' : 'Inactive' ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <div class="d-flex justify-content-end gap-2">
    
                                                        
                                                        <form method="POST" class="d-inline">
                                                            <input type="hidden" name="id" value="<?= $promo['id'] ?>">
                                                            <input type="hidden" name="is_active" value="<?= $promo['is_active'] ? 0 : 1 ?>">
                                                            <button type="submit" name="update_promo" class="btn btn-sm btn-action btn-<?= $promo['is_active'] ? 'warning' : 'success' ?>-subtle text-<?= $promo['is_active'] ? 'warning' : 'success' ?>" data-bs-toggle="tooltip" title="<?= $promo['is_active'] ? 'Deactivate' : 'Activate' ?>">
                                                                <i class="bi bi-power text-<?= $promo['is_active'] ? 'success' : 'danger' ?>"></i>
                                                            </button>
                                                        </form>
                                                        <form method="POST" class="d-inline">
                                                            <input type="hidden" name="id" value="<?= $promo['id'] ?>">
                                                            
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <div class="d-flex justify-content-between align-items-center p-3 border-top">
                                <div class="text-muted">
                                    Showing <span class="fw-semibold">1</span> to <span class="fw-semibold"><?= count($promoCodes) ?></span> of <span class="fw-semibold"><?= count($promoCodes) ?></span> entries
                                </div>
                                <nav aria-label="Page navigation">
                                    <ul class="pagination mb-0">
                                        <li class="page-item disabled">
                                            <a class="page-link" href="#" tabindex="-1" aria-disabled="true">Previous</a>
                                        </li>
                                        <li class="page-item active"><a class="page-link" href="#">1</a></li>
                                        <li class="page-item"><a class="page-link" href="#">2</a></li>
                                        <li class="page-item"><a class="page-link" href="#">3</a></li>
                                        <li class="page-item">
                                            <a class="page-link" href="#">Next</a>
                                        </li>
                                    </ul>
                                </nav>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Update promo code preview
        function updatePreview() {
            const code = document.getElementById('code').value || 'SUMMER25';
            const discountType = document.querySelector('input[name="discount_type"]:checked').value;
            const discountValue = document.getElementById('discount_value').value || '25';
            
            document.getElementById('promoPreview').textContent = code;
            
            if (discountType === 'percentage') {
                document.getElementById('discountPreview').textContent = 
                    `${discountValue}% discount on all orders`;
            } else {
                document.getElementById('discountPreview').textContent = 
                    `LKR ${discountValue} off on all orders`;
            }
        }
        
        // Generate random promo code
        document.getElementById('generateCode').addEventListener('click', function() {
            const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            let result = '';
            for (let i = 0; i < 6; i++) {
                result += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            document.getElementById('code').value = result;
            updatePreview();
        });
        
        // Initialize tooltips
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
        
        // Search functionality
        document.getElementById('searchInput').addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const rows = document.querySelectorAll('#promoTable tbody tr');
            
            rows.forEach(row => {
                const code = row.querySelector('td:first-child strong').textContent.toLowerCase();
                if (code.includes(searchTerm)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
        
        // Initialize with preview
        document.addEventListener('DOMContentLoaded', updatePreview);
    </script>
</body>
</html>