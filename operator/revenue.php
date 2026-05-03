<?php
session_start();
require_once '../includes/config.php';

// Database connection (Singleton pattern)
function getDatabaseConnection() {
    static $db = null;
    if ($db === null) {
        try {
            $db = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]
            );
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            header('Content-Type: application/json');
            die(json_encode(['error' => 'Database connection error']));
        }
    }
    return $db;
}

// Get operator info
function getOperatorByUserId($userId) {
    $db = getDatabaseConnection();
    $stmt = $db->prepare("
        SELECT o.*, u.email, u.first_name, u.last_name 
        FROM operators o
        JOIN users u ON o.user_id = u.user_id
        WHERE o.user_id = ? AND u.status = 'Active'
    ");
    $stmt->execute([$userId]);
    return $stmt->fetch();
}

// Session and role validation
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'operator') {
    header('Location: /login.php');
    exit;
}

$operator = getOperatorByUserId($_SESSION['user_id']);
if (!$operator || !$operator['is_approved']) {
    header('Location: /operator/onboarding.php');
    exit;
}

// Time range selection
$validPeriods = ['daily', 'weekly', 'monthly', 'quarterly', 'yearly', 'custom'];
$timePeriod = isset($_GET['period']) && in_array($_GET['period'], $validPeriods)
    ? $_GET['period'] : 'monthly';

$now = new DateTime();
$startDate = $now->format('Y-m-01');
$endDate = $now->format('Y-m-t');

switch ($timePeriod) {
    case 'daily':
        $startDate = $endDate = $now->format('Y-m-d');
        break;
    case 'weekly':
        $startDate = $now->modify('monday this week')->format('Y-m-d');
        $endDate = (new DateTime($startDate))->modify('+6 days')->format('Y-m-d');
        break;
    case 'quarterly':
        $month = (int) $now->format('n');
        $quarterStart = floor(($month - 1) / 3) * 3 + 1;
        $startDate = $now->format("Y-") . str_pad($quarterStart, 2, '0', STR_PAD_LEFT) . "-01";
        $endDate = (new DateTime($startDate))->modify('+2 months')->format('Y-m-t');
        break;
    case 'yearly':
        $startDate = $now->format('Y-01-01');
        $endDate = $now->format('Y-12-31');
        break;
    case 'custom':
        if (!empty($_GET['start_date']) && !empty($_GET['end_date'])) {
            try {
                $s = new DateTime($_GET['start_date']);
                $e = new DateTime($_GET['end_date']);
                if ($s > $e || $s->diff($e)->days > 365) throw new Exception("Invalid range");
                $startDate = $s->format('Y-m-d');
                $endDate = $e->format('Y-m-d');
            } catch (Exception $ex) {
                error_log("Invalid custom range: " . $ex->getMessage());
            }
        }
        break;
}

// Initialize data
$db = getDatabaseConnection();
$revenueData = [];
$payouts = [];
$stats = [
    'total_earnings' => 0,
    'total_payouts' => 0,
    'pending_payouts' => 0,
    'balance' => 0,
    'booking_count' => 0,
    'cancellation_rate' => 0,
    'cancelled_revenue' => 0
];

try {
    $db->beginTransaction();

    // Revenue breakdown
    $stmt = $db->prepare("
        SELECT 
            date AS booking_date,
            total_bookings AS booking_count,
            total_amount AS daily_revenue,
            confirmed_amount AS confirmed_revenue,
            cancelled_amount AS cancelled_revenue
        FROM operator_revenue
        WHERE operator_id = :operator_id
        AND date BETWEEN :start_date AND :end_date
        ORDER BY date ASC
    ");
    $stmt->execute([
        ':operator_id' => $operator['operator_id'],
        ':start_date' => $startDate,
        ':end_date' => $endDate
    ]);
    $revenueData = $stmt->fetchAll();

    // Stats
    $stmt = $db->prepare("
        SELECT
            SUM(confirmed_amount) AS total_earnings,
            SUM(cancelled_amount) AS cancelled_revenue,
            SUM(total_bookings) AS booking_count
        FROM operator_revenue
        WHERE operator_id = :operator_id
        AND date BETWEEN :start_date AND :end_date
    ");
    $stmt->execute([
        ':operator_id' => $operator['operator_id'],
        ':start_date' => $startDate,
        ':end_date' => $endDate
    ]);
    $row = $stmt->fetch();
    if ($row) $stats = array_merge($stats, $row);

    // Payout stats
    $stmt = $db->prepare("
        SELECT
            SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END) AS total_payouts,
            SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END) AS pending_payouts
        FROM operator_payouts
        WHERE operator_id = :operator_id
        AND created_at BETWEEN :start_date AND :end_date
    ");
    $stmt->execute([
        ':operator_id' => $operator['operator_id'],
        ':start_date' => $startDate . ' 00:00:00',
        ':end_date' => $endDate . ' 23:59:59'
    ]);
    $row = $stmt->fetch();
    if ($row) {
        $stats['total_payouts'] = $row['total_payouts'] ?? 0;
        $stats['pending_payouts'] = $row['pending_payouts'] ?? 0;
    }

    // Calculate balance and cancellation rate
    $stats['balance'] = $stats['total_earnings'] - $stats['total_payouts'] - $stats['pending_payouts'];
    $stats['cancellation_rate'] = ($stats['booking_count'] > 0)
        ? round(($stats['cancelled_revenue'] / $stats['total_earnings']) * 100, 2)
        : 0;

    // Recent payouts
    $stmt = $db->prepare("
        SELECT 
            payout_id,
            reference_number,
            amount,
            payment_method,
            status,
            created_at,
            processed_at
        FROM operator_payouts
        WHERE operator_id = :operator_id
        AND created_at BETWEEN :start_date AND :end_date
        ORDER BY created_at DESC
        LIMIT 100
    ");
    $stmt->execute([
        ':operator_id' => $operator['operator_id'],
        ':start_date' => $startDate . ' 00:00:00',
        ':end_date' => $endDate . ' 23:59:59'
    ]);
    $payouts = $stmt->fetchAll();

    $db->commit();
} catch (PDOException $e) {
    $db->rollBack();
    error_log("Revenue fetch failed: " . $e->getMessage());
    $errorMessage = "Failed to load revenue data. Please try again.";
}

// CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Revenue Report | SmartBusLink</title>
    
    <!-- CSS imports -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
    
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
            --border-radius: 0.75rem;
            --box-shadow: 0 0.5rem 1.5rem rgba(0, 0, 0, 0.08);
        }
        
        body {
            background-color: #f5f7fa;
            font-family: 'Nunito', -apple-system, BlinkMacSystemFont, sans-serif;
        }
        
        .card {
            border: none;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            background-color: #ffffff;
        }
        
        .stat-card {
            border-left: 4px solid;
        }
        
        .stat-card.earnings {
            border-left-color: var(--primary-color);
        }
        
        .stat-card.payouts {
            border-left-color: var(--success-color);
        }
        
        .stat-card.pending {
            border-left-color: var(--warning-color);
        }
        
        .stat-card.balance {
            border-left-color: var(--info-color);
        }
        
        .revenue-chart-container {
            height: 300px;
            min-height: 300px;
        }
        
        .badge-paid {
            background-color: #e8f5e9;
            color: var(--success-color);
            border: 1px solid var(--success-color);
        }
        
        .badge-pending {
            background-color: #fff8e1;
            color: var(--warning-color);
            border: 1px solid var(--warning-color);
        }
        
        .badge-failed {
            background-color: #ffebee;
            color: var(--danger-color);
            border: 1px solid var(--danger-color);
        }
        
        .progress-thin {
            height: 6px;
        }
        
        @media print {
            body * {
                visibility: hidden;
            }
            .print-section, .print-section * {
                visibility: visible;
            }
            .print-section {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
            }
            .no-print {
                display: none !important;
            }
        }

        /* Fix for alignment issues */
        .date-range-picker .form-label {
            margin-bottom: 0.25rem;
        }
        .date-range-picker .form-select, 
        .date-range-picker .form-control {
            padding: 0.375rem 0.75rem;
        }
        .align-self-end {
            align-self: flex-end;
        }

        #revenueChart {
            width: 100% !important;
            height: 100% !important;
        }

        /* Loading spinner */
        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(255, 255, 255, 0.8);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }
        .loading-spinner {
            width: 3rem;
            height: 3rem;
        }
    </style>
</head>
<body>
    <!-- Loading overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner-border loading-spinner text-primary" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
    </div>

    <div class="container py-4 print-section">
        <?php include 'header.php'; ?>
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3 mb-0 text-gray-800">
                <i class="bi bi-graph-up me-2"></i>Revenue Report
            </h1>
            <div class="no-print">
                <button class="btn btn-primary me-2" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i> Print
                </button>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#requestPayoutModal" <?= $stats['balance'] < 1000 ? 'disabled' : '' ?>>
                    <i class="bi bi-cash-coin me-1"></i> Request Payout
                </button>
            </div>
        </div>
        
        <!-- Error message display -->
        <?php if (isset($errorMessage)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?= htmlspecialchars($errorMessage) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>
        
        <!-- Date Range Picker -->
        <div class="card mb-4 no-print">
            <div class="card-body">
                <form method="get" class="date-range-picker" id="dateRangeForm">
                    <div class="row g-3 align-items-center">
                        <div class="col-md-2">
                            <label class="form-label small">Period</label>
                            <select name="period" class="form-select" id="periodSelect">
                                <option value="daily" <?= $timePeriod === 'daily' ? 'selected' : '' ?>>Daily</option>
                                <option value="weekly" <?= $timePeriod === 'weekly' ? 'selected' : '' ?>>Weekly</option>
                                <option value="monthly" <?= $timePeriod === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                                <option value="quarterly" <?= $timePeriod === 'quarterly' ? 'selected' : '' ?>>Quarterly</option>
                                <option value="yearly" <?= $timePeriod === 'yearly' ? 'selected' : '' ?>>Yearly</option>
                                <option value="custom" <?= $timePeriod === 'custom' ? 'selected' : '' ?>>Custom</option>
                            </select>
                        </div>
                        
                        <?php if ($timePeriod === 'custom'): ?>
                        <div class="col-md-3">
                            <label class="form-label small">From</label>
                            <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($startDate) ?>" id="startDateInput">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small">To</label>
                            <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($endDate) ?>" id="endDateInput">
                        </div>
                        <?php endif; ?>
                        
                        <div class="col-md-2 align-self-end">
                            <button type="submit" class="btn btn-primary w-100" id="applyFilterBtn">
                                <i class="bi bi-filter me-1"></i> Apply
                            </button>
                        </div>
                        <div class="col-md-2 align-self-end">
                            <a href="revenue.php" class="btn btn-outline-secondary w-100">
                                <i class="bi bi-arrow-counterclockwise me-1"></i> Reset
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Report Summary -->
        <div class="card mb-4">
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h5 class="mb-3">Report Summary</h5>
                        <div class="mb-2">
                            <span class="text-muted">Operator:</span>
                            <strong><?= htmlspecialchars($operator['company_name']) ?></strong>
                        </div>
                        <div class="mb-2">
                            <span class="text-muted">Period:</span>
                            <strong><?= date('M j, Y', strtotime($startDate)) ?> to <?= date('M j, Y', strtotime($endDate)) ?></strong>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <h5 class="mb-3">Performance Overview</h5>
                        <div class="mb-2">
                            <span class="text-muted">Cancellation Rate:</span>
                            <strong><?= htmlspecialchars($stats['cancellation_rate']) ?>%</strong>
                            <div class="progress progress-thin mt-1">
                                <div class="progress-bar bg-danger" role="progressbar" 
                                     style="width: <?= htmlspecialchars($stats['cancellation_rate']) ?>%" 
                                     aria-valuenow="<?= htmlspecialchars($stats['cancellation_rate']) ?>" 
                                     aria-valuemin="0" 
                                     aria-valuemax="100"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Stats Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card stat-card earnings h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-uppercase text-muted small mb-0">Total Earnings</h6>
                                <h3 class="mb-0">Rs. <?= number_format($stats['total_earnings'], 2) ?></h3>
                            </div>
                            <div class="bg-primary bg-opacity-10 p-3 rounded">
                                <i class="bi bi-currency-exchange text-primary fs-4"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card payouts h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-uppercase text-muted small mb-0">Total Payouts</h6>
                                <h3 class="mb-0">Rs. <?= number_format($stats['total_payouts'], 2) ?></h3>
                            </div>
                            <div class="bg-success bg-opacity-10 p-3 rounded">
                                <i class="bi bi-cash-coin text-success fs-4"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card pending h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-uppercase text-muted small mb-0">Pending Payouts</h6>
                                <h3 class="mb-0">Rs. <?= number_format($stats['pending_payouts'], 2) ?></h3>
                            </div>
                            <div class="bg-warning bg-opacity-10 p-3 rounded">
                                <i class="bi bi-clock-history text-warning fs-4"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card balance h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-uppercase text-muted small mb-0">Available Balance</h6>
                                <h3 class="mb-0">Rs. <?= number_format($stats['balance'], 2) ?></h3>
                            </div>
                            <div class="bg-info bg-opacity-10 p-3 rounded">
                                <i class="bi bi-wallet2 text-info fs-4"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Revenue Chart -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Revenue Trend</h5>
            </div>
            <div class="card-body">
                <div class="revenue-chart-container">
                    <canvas id="revenueChart"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Revenue Breakdown -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Revenue Breakdown</h5>
                <div class="no-print d-flex gap-2">
                    <button class="btn btn-sm btn-outline-primary" onclick="exportRevenueData()">
                        <i class="bi bi-download me-1"></i> Export
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="revenueTable">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Bookings</th>
                                <th>Total Revenue (LKR)</th>
                                <th>Confirmed (LKR)</th>
                                <th>Cancelled (LKR)</th>
                                <th>Net Revenue (LKR)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($revenueData)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">
                                        <i class="bi bi-exclamation-circle me-2"></i> No revenue data available for the selected period.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($revenueData as $row): ?>
                                    <tr>
                                        <td><?= date('M j, Y', strtotime($row['booking_date'])) ?></td>
                                        <td><?= htmlspecialchars($row['booking_count']) ?></td>
                                        <td>Rs. <?= number_format($row['daily_revenue'], 2) ?></td>
                                        <td>Rs. <?= number_format($row['confirmed_revenue'], 2) ?></td>
                                        <td>Rs. <?= number_format($row['cancelled_revenue'], 2) ?></td>
                                        <td>Rs. <?= number_format($row['confirmed_revenue'] - $row['cancelled_revenue'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Payout History -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Payout History</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="payoutsTable">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Reference</th>
                                <th>Amount (LKR)</th>
                                <th>Method</th>
                                <th>Status</th>
                                <th>Processed At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($payouts)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">
                                        <i class="bi bi-exclamation-circle me-2"></i> No payout history available
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($payouts as $payout): ?>
                                    <tr>
                                        <td><?= date('M j, Y', strtotime($payout['created_at'])) ?></td>
                                        <td><?= htmlspecialchars($payout['reference_number']) ?></td>
                                        <td>Rs. <?= number_format($payout['amount'], 2) ?></td>
                                        <td><?= ucfirst($payout['payment_method']) ?></td>
                                        <td>
                                            <?php if ($payout['status'] === 'paid'): ?>
                                                <span class="badge badge-paid rounded-pill">Paid</span>
                                            <?php elseif ($payout['status'] === 'pending'): ?>
                                                <span class="badge badge-pending rounded-pill">Pending</span>
                                            <?php else: ?>
                                                <span class="badge badge-failed rounded-pill">Failed</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?= $payout['processed_at'] ? date('M j, Y', strtotime($payout['processed_at'])) : 'N/A' ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    
    <!-- Request Payout Modal -->
    <div class="modal fade" id="requestPayoutModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Request Payout</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="payoutRequestForm">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Available Balance</label>
                            <input type="text" class="form-control" value="Rs. <?= number_format($stats['balance'], 2) ?>" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Amount to Withdraw (LKR)</label>
                            <input type="number" class="form-control" name="amount" 
                                   max="<?= $stats['balance'] ?>" 
                                   min="1000" 
                                   step="100"
                                   required>
                            <small class="text-muted">Minimum withdrawal: Rs. 1,000.00</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Payment Method</label>
                            <select class="form-select" name="payment_method" required>
                                <option value="">Select method</option>
                                <option value="bank">Bank Transfer</option>
                                <option value="paypal">PayPal</option>
                            </select>
                        </div>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="submitPayoutBtn">
                            Request Payout
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- JavaScript imports -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    
    <script>
        // Initialize DataTables
        $(document).ready(function() {
            $('#revenueTable').DataTable({
                order: [[0, 'desc']],
                dom: '<"top"f>rt<"bottom"lip><"clear">',
                language: {
                    search: "_INPUT_",
                    searchPlaceholder: "Search revenue...",
                }
            });
            
            $('#payoutsTable').DataTable({
                order: [[0, 'desc']],
                dom: '<"top"f>rt<"bottom"lip><"clear">',
                language: {
                    search: "_INPUT_",
                    searchPlaceholder: "Search payouts...",
                },
                columnDefs: [
                    { targets: [2], type: 'num' } // Ensure amount column is sorted numerically
                ]
            });
        });

        // Initialize Revenue Chart
        document.addEventListener('DOMContentLoaded', function() {
            const revenueCtx = document.getElementById('revenueChart').getContext('2d');
            
            const chartData = {
                labels: <?php echo json_encode(array_column($revenueData, 'booking_date')); ?>,
                datasets: [{
                    label: 'Total Revenue',
                    data: <?php echo json_encode(array_column($revenueData, 'daily_revenue')); ?>,
                    backgroundColor: 'rgba(78, 115, 223, 0.7)',
                    borderColor: 'rgba(78, 115, 223, 1)',
                    borderWidth: 1,
                    borderRadius: 6,
                    hoverBackgroundColor: 'rgba(78, 115, 223, 0.9)',
                    hoverBorderColor: 'rgba(78, 115, 223, 1)',
                }, {
                    label: 'Net Revenue',
                    data: <?php echo json_encode(array_map(function($item) {
                        return $item['confirmed_revenue'] - $item['cancelled_revenue'];
                    }, $revenueData)); ?>,
                    backgroundColor: 'rgba(28, 200, 138, 0.7)',
                    borderColor: 'rgba(28, 200, 138, 1)',
                    borderWidth: 1,
                    borderRadius: 6,
                    hoverBackgroundColor: 'rgba(28, 200, 138, 0.9)',
                    hoverBorderColor: 'rgba(28, 200, 138, 1)',
                }]
            };
            
            const revenueChart = new Chart(revenueCtx, {
                type: 'bar',
                data: chartData,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                usePointStyle: true,
                                padding: 20
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': LKR ' + context.parsed.y.toLocaleString('en-US', {
                                        minimumFractionDigits: 2,
                                        maximumFractionDigits: 2
                                    });
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return 'Rs ' + value.toLocaleString('en-US', {
                                        minimumFractionDigits: 2,
                                        maximumFractionDigits: 2
                                    });
                                }
                            },
                            grid: {
                                drawBorder: false,
                                color: 'rgba(0, 0, 0, 0.05)'
                            },
                            title: {
                                display: true,
                                text: 'Amount',
                                color: '#6c757d'
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            },
                            title: {
                                display: true,
                                text: 'Date',
                                color: '#6c757d'
                            }
                        }
                    }
                }
            });
            
            // Period selector change handler
            document.getElementById('periodSelect').addEventListener('change', function() {
                if (this.value === 'custom') {
                    document.getElementById('startDateInput').closest('.col-md-3').style.display = 'block';
                    document.getElementById('endDateInput').closest('.col-md-3').style.display = 'block';
                } else {
                    document.getElementById('startDateInput').closest('.col-md-3').style.display = 'none';
                    document.getElementById('endDateInput').closest('.col-md-3').style.display = 'none';
                }
            });
            
            // Form submission handler
            document.getElementById('dateRangeForm').addEventListener('submit', function() {
                document.getElementById('loadingOverlay').style.display = 'flex';
            });
            
            // Payout form submission
            document.getElementById('payoutRequestForm').addEventListener('submit', function(e) {
                e.preventDefault();
                const submitBtn = document.getElementById('submitPayoutBtn');
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Processing...';
                
                // Here you would typically make an AJAX request to submit the form
                // For demo purposes, we'll just show a success message after a delay
                setTimeout(function() {
                    alert('Payout request submitted successfully!');
                    bootstrap.Modal.getInstance(document.getElementById('requestPayoutModal')).hide();
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = 'Request Payout';
                }, 1500);
            });
        });
        
        function exportRevenueData() {
            // Here you would typically make an AJAX request to export the data
            alert('Export functionality would be implemented here');
        }
    </script>
</body>
</html>