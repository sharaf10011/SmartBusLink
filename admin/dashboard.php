<?php
// Session configuration
session_start([
    'cookie_lifetime' => 1800, // 30 minutes
    'cookie_secure'   => true, // Only send over HTTPS
    'cookie_httponly' => true, // Prevent JavaScript access
    'use_strict_mode' => true  // Prevent session fixation
]);

// Database connection with error handling
require_once '../includes/config.php';
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    die("System maintenance in progress. Please try again later.");
}

// Session timeout (15 minutes)
$inactive_limit = 900;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $inactive_limit) {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit;
}
$_SESSION['last_activity'] = time();

// Validate admin session
if (empty($_SESSION['loggedin'])) {
    header("Location: login.php");
    exit;
}

// Verify user type
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: login.php");
    exit;
}

// Regenerate session ID periodically to prevent fixation
if (!isset($_SESSION['created'])) {
    $_SESSION['created'] = time();
} elseif (time() - $_SESSION['created'] > 1800) {
    session_regenerate_id(true);
    $_SESSION['created'] = time();
}

$adminUsername = $_SESSION['username'];

// Get admin info with prepared statement
$stmt = $conn->prepare("SELECT user_id, email, created_at FROM users WHERE username = ? AND user_type = 'admin'");
if (!$stmt) {
    error_log("Prepare failed: " . $conn->error);
    die("System error. Please try again later.");
}

$stmt->bind_param("s", $adminUsername);
if (!$stmt->execute()) {
    error_log("Execute failed: " . $stmt->error);
    die("System error. Please try again later.");
}

$result = $stmt->get_result();
$adminData = $result->fetch_assoc();
$stmt->close();

if (!$adminData) {
    session_destroy();
    header("Location: login.php");
    exit;
}

// Dashboard statistics with prepared statements
function getStatistic($conn, $query, $params = [], $default = 0) {
    try {
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            error_log("Prepare failed: " . $conn->error);
            return $default;
        }
        
        if ($params) {
            $types = str_repeat('s', count($params));
            $stmt->bind_param($types, ...$params);
        }
        
        if (!$stmt->execute()) {
            error_log("Execute failed: " . $stmt->error);
            return $default;
        }
        
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        return $row ? reset($row) : $default;
    } catch (mysqli_sql_exception $e) {
        error_log("Query failed: " . $e->getMessage());
        return $default;
    }
}

// Check if tables exist function with caching
function tableExists($conn, $tableName) {
    static $existingTables = [];
    
    if (!isset($existingTables[$tableName])) {
        $result = $conn->query("SHOW TABLES LIKE '$tableName'");
        $existingTables[$tableName] = $result->num_rows > 0;
    }
    
    return $existingTables[$tableName];
}

// Get statistics with safe fallbacks
$statistics = [
    'totalPassengers' => getStatistic($conn, "SELECT COUNT(*) FROM passengers"),
    'totalOperators' => getStatistic($conn, "SELECT COUNT(*) FROM operators WHERE is_approved = 1"),
    'pendingOperators' => getStatistic($conn, "SELECT COUNT(*) FROM operators WHERE is_approved = 0"),
    'totalBookings' => getStatistic($conn, "SELECT COUNT(*) FROM bookings"),
    'todayBookings' => getStatistic($conn, "SELECT COUNT(*) FROM bookings WHERE DATE(travel_date) = CURDATE()"),
    'totalRevenue' => getStatistic($conn, "SELECT SUM(amount) FROM payments WHERE payment_status = 'completed'"),
    'monthlyRevenue' => getStatistic($conn, "SELECT SUM(amount) FROM payments WHERE payment_status = 'completed' AND MONTH(payment_date) = MONTH(CURRENT_DATE())"),
    'activeTickets' => getStatistic($conn, "SELECT COUNT(*) FROM support_tickets WHERE status = 'open'"),
    'activeCampaigns' => tableExists($conn, 'promotions') 
        ? getStatistic($conn, "SELECT COUNT(*) FROM promotions WHERE end_date >= CURDATE() AND is_active = 1")
        : 0,
    'totalUsers' => tableExists($conn, 'users')
        ? getStatistic($conn, "SELECT COUNT(*) FROM users WHERE user_type = 'user'")
        : 0,
    'pendingUpdates' => tableExists($conn, 'system_updates')
        ? getStatistic($conn, "SELECT COUNT(*) FROM system_updates WHERE is_applied = 0")
        : 0,
    'newReports' => tableExists($conn, 'reports')
        ? getStatistic($conn, "SELECT COUNT(*) FROM reports WHERE is_viewed = 0")
        : 0
];

// Extract variables for easier use in HTML
extract($statistics, EXTR_SKIP);

// Recent activities with prepared statement
$recentPayments = [];
$stmt = $conn->prepare("
    SELECT p.*, b.booking_reference 
    FROM payments p
    JOIN bookings b ON p.booking_id = b.booking_id
    WHERE p.payment_status = 'completed'
    ORDER BY p.payment_date DESC LIMIT 5");

if ($stmt && $stmt->execute()) {
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $recentPayments[] = $row;
    }
    $stmt->close();
}

// System notifications
$notification_message = "All systems are running smoothly!";
$systemStatus = "operational";
$lastBackup = date('Y-m-d H:i:s', strtotime('-1 day'));

// HTML output should escape all dynamic content
function escape($data) {
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}

include 'navbar.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | SmartBusLink</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --success-gradient: linear-gradient(135deg, #2af598 0%, #009efd 100%);
            --warning-gradient: linear-gradient(135deg, #f6d365 0%, #fda085 100%);
            --danger-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --info-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --dark-gradient: linear-gradient(135deg, #434343 0%, #000000 100%);
        }
        
        body {
            background-color: #f5f7fb;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .main-content {
            padding: 2rem;
            transition: all 0.3s;
        }
        
        .dashboard-header {
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            border: none;
            border-radius: 10px;
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            color: white;
            position: relative;
            z-index: 1;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: var(--gradient);
            z-index: -1;
            opacity: 0.9;
        }
        
        .stat-card-primary::before { --gradient: var(--primary-gradient); }
        .stat-card-success::before { --gradient: var(--success-gradient); }
        .stat-card-warning::before { --gradient: var(--warning-gradient); }
        .stat-card-danger::before { --gradient: var(--danger-gradient); }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        
        .stat-icon {
            font-size: 2.5rem;
            opacity: 0.2;
            position: absolute;
            right: 20px;
            top: 20px;
        }
        
        /* Quick Action Cards */
        .quick-action-card {
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            padding: 1.5rem 1rem;
            border-radius: 12px;
            color: white;
            text-decoration: none;
            overflow: hidden;
            transition: all 0.3s ease;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .quick-action-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
            color: white;
        }

        .action-icon {
            font-size: 1.75rem;
            margin-bottom: 0.75rem;
            transition: all 0.3s ease;
        }

        .quick-action-card:hover .action-icon {
            transform: scale(1.1);
        }

        .quick-action-card h6 {
            font-weight: 600;
            margin-bottom: 0.5rem;
            text-align: center;
        }

        .quick-action-card .badge {
            font-weight: 500;
            padding: 0.25rem 0.5rem;
            border-radius: 50px;
            font-size: 0.75rem;
        }

        .hover-effect {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle at center, rgba(255,255,255,0.3) 0%, rgba(255,255,255,0) 70%);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .quick-action-card:hover .hover-effect {
            opacity: 1;
        }

        /* Gradient Backgrounds */
        .bg-gradient-blue {
            background: linear-gradient(135deg, #4361ee, #3a0ca3);
        }

        .bg-gradient-green {
            background: linear-gradient(135deg, #4cc9f0, #4895ef);
        }

        .bg-gradient-cyan {
            background: linear-gradient(135deg, #38b6ff, #4361ee);
        }

        .bg-gradient-yellow {
            background: linear-gradient(135deg, #ffbe0b, #fb5607);
        }

        .bg-gradient-red {
            background: linear-gradient(135deg, #ef233c, #d90429);
        }

        .bg-gradient-gray {
            background: linear-gradient(135deg, #6c757d, #495057);
        }

        /* Activity Feed */
        .activity-item {
            border-left: 3px solid;
            padding-left: 15px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
            position: relative;
            padding-bottom: 15px;
        }
        
        .activity-item::before {
            content: '';
            position: absolute;
            left: -6px;
            top: 5px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: var(--color);
        }
        
        .activity-item:last-child {
            padding-bottom: 0;
            margin-bottom: 0;
        }
        
        /* System Status */
        .system-status {
            border-radius: 8px;
            background: white;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
        
        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #555;
        }

        /* Ripple Effect */
        .ripple-effect {
            position: absolute;
            border-radius: 50%;
            background-color: rgba(255, 255, 255, 0.4);
            transform: scale(0);
            animation: ripple 0.6s linear;
            pointer-events: none;
        }

        @keyframes ripple {
            to {
                transform: scale(2.5);
                opacity: 0;
            }
        }
    </style>
</head>
<body>
    <div class="main-content">
        <!-- Dashboard Header -->
        <div class="dashboard-header d-flex justify-content-between align-items-center animate__animated animate__fadeIn">
            <div>
                <h1 class="h3 mb-1 text-dark">Dashboard Overview</h1>
                <p class="mb-0 text-muted">Welcome back, <?= htmlspecialchars($adminUsername) ?>! Here's what's happening with your business today.</p>
            </div>
            <div class="d-flex">
                <span class="badge rounded-pill bg-light text-dark me-3 d-flex align-items-center">
                    <span class="dot bg-success me-2"></span>
                    System Operational
                </span>
                <span class="badge rounded-pill bg-light text-dark d-flex align-items-center">
                    <i class="fas fa-database me-2"></i>
                    Last backup: <?= $lastBackup ?>
                </span>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="row g-4 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="stat-card stat-card-primary h-100 animate__animated animate__fadeInUp p-4">
                    <div class="position-relative">
                        <h5 class="text-uppercase text-white-50 mb-2">Passengers</h5>
                        <div class="d-flex align-items-center mb-3">
                            <h2 class="mb-0 me-3 text-white"><?= number_format($totalPassengers) ?></h2>
                            <div class="text-white-50 small">
                                <i class="fas fa-arrow-up me-1"></i> 12.5%
                            </div>
                        </div>
                        <div class="progress bg-white bg-opacity-20" style="height: 6px;">
                            <div class="progress-bar bg-white" role="progressbar" style="width: 75%"></div>
                        </div>
                        <i class="fas fa-users stat-icon text-white"></i>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="stat-card stat-card-success h-100 animate__animated animate__fadeInUp animate__delay-1s p-4">
                    <div class="position-relative">
                        <h5 class="text-uppercase text-white-50 mb-2">Operators</h5>
                        <div class="d-flex align-items-center mb-3">
                            <h2 class="mb-0 me-3 text-white"><?= number_format($totalOperators) ?></h2>
                            <span class="badge bg-white text-dark">+<?= $pendingOperators ?> pending</span>
                        </div>
                        <div class="progress bg-white bg-opacity-20" style="height: 6px;">
                            <div class="progress-bar bg-white" role="progressbar" style="width: 60%"></div>
                        </div>
                        <i class="fas fa-bus stat-icon text-white"></i>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="stat-card stat-card-warning h-100 animate__animated animate__fadeInUp animate__delay-2s p-4">
                    <div class="position-relative">
                        <h5 class="text-uppercase text-white-50 mb-2">Bookings</h5>
                        <div class="d-flex align-items-center mb-3">
                            <h2 class="mb-0 me-3 text-white"><?= number_format($totalBookings) ?></h2>
                            <div class="text-white-50 small">
                                <i class="fas fa-calendar-day me-1"></i> <?= $todayBookings ?> today
                            </div>
                        </div>
                        <div class="progress bg-white bg-opacity-20" style="height: 6px;">
                            <div class="progress-bar bg-white" role="progressbar" style="width: 45%"></div>
                        </div>
                        <i class="fas fa-ticket-alt stat-icon text-white"></i>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="stat-card stat-card-danger h-100 animate__animated animate__fadeInUp animate__delay-3s p-4">
                    <div class="position-relative">
                        <h5 class="text-uppercase text-white-50 mb-2">Revenue</h5>
                        <div class="d-flex align-items-center mb-3">
                            <h2 class="mb-0 me-3 text-white">LKR<?= number_format($totalRevenue, 2) ?></h2>
                            <div class="text-white-50 small">
                                <i class="fas fa-calendar me-1"></i> LKR<?= number_format($monthlyRevenue, 2) ?>
                            </div>
                        </div>
                        <div class="progress bg-white bg-opacity-20" style="height: 6px;">
                            <div class="progress-bar bg-white" role="progressbar" style="width: 85%"></div>
                        </div>
                        <i class="fas fa-dollar-sign stat-icon text-white"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <!-- Quick Navigation -->
<div class="col-lg-8">
    <div class="card border-0 shadow-sm h-100 animate__animated animate__fadeIn">
        <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center py-3 px-4">
            <h5 class="mb-0 fw-semibold text-primary">
                <i class="fas fa-bolt me-2"></i>Quick Actions
            </h5>
            <span class="badge bg-primary bg-opacity-10 text-primary fw-medium py-2 px-3">
                <i class="fas fa-calendar-day me-2"></i><?= date('F j, Y') ?>
            </span>
        </div>
        <div class="card-body p-4">
            <div class="row row-cols-2 row-cols-md-3 g-4">
                <!-- Manage Users -->
                <div class="col">
                    <a href="manage_passengers.php" class="quick-action-card bg-gradient-blue position-relative overflow-hidden">
                        <div class="action-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <h6>Manage Users</h6>
                        <span class="badge bg-white text-primary">
                            <?= isset($totalUsers) ? number_format($totalUsers) : '0' ?> active
                        </span>
                        <div class="hover-effect"></div>
                    </a>
                </div>
                
                <!-- Manage Operators -->
                <div class="col">
                    <a href="manage-operators.php" class="quick-action-card bg-gradient-green position-relative overflow-hidden">
                        <div class="action-icon">
                            <i class="fas fa-bus"></i>
                        </div>
                        <h6>Manage Operators</h6>
                        <span class="badge bg-white text-success">
                            <?= isset($totalOperators) ? number_format($totalOperators) : '0' ?> registered
                        </span>
                        <div class="hover-effect"></div>
                    </a>
                </div>
                
                <!-- View Reports -->
                <div class="col">
                    <a href="reports.php" class="quick-action-card bg-gradient-cyan position-relative overflow-hidden">
                        <div class="action-icon">
                            <i class="fas fa-chart-pie"></i>
                        </div>
                        <h6>View Reports</h6>
                        <span class="badge bg-white text-info">
                            <?= isset($newReports) ? number_format($newReports) : '0' ?> new
                        </span>
                        <div class="hover-effect"></div>
                    </a>
                </div>
                
                <!-- Promo Campaigns -->
                <div class="col">
                    <a href="promotions.php" class="quick-action-card bg-gradient-yellow position-relative overflow-hidden">
                        <div class="action-icon">
                            <i class="fas fa-bullhorn"></i>
                        </div>
                        <h6>Promo Campaigns</h6>
                        <span class="badge bg-white text-warning">
                            <?= isset($activeCampaigns) ? number_format($activeCampaigns) : '0' ?> running
                        </span>
                        <div class="hover-effect"></div>
                    </a>
                </div>
                
                <!-- Complaints -->
                <div class="col">
                    <a href="complaints.php" class="quick-action-card bg-gradient-red position-relative overflow-hidden">
                        <div class="action-icon">
                            <i class="fas fa-comments"></i>
                        </div>
                        <h6>Complaints</h6>
                        <span class="badge bg-white text-danger">
                            <?= isset($activeTickets) ? number_format($activeTickets) : '0' ?> pending
                        </span>
                        <div class="hover-effect"></div>
                    </a>
                </div>
                
                <!-- Approve Requests -->
                <div class="col">
                    <a href="approve-requests.php" class="quick-action-card bg-gradient-gray position-relative overflow-hidden">
                        <div class="action-icon">
                            <i class="fas fa-inbox"></i>
                        </div>
                        <h6>Approve Requests</h6>
                        <div class="hover-effect"></div>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
            
            <!-- Admin Profile -->
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm h-100 animate__animated animate__fadeIn">
                    <div class="card-header bg-white border-0 py-3">
                        <h5 class="mb-0"><i class="fas fa-user-shield text-primary me-2"></i>Profile</h5>
                    </div>
                    <div class="card-body text-center">
                        <div class="mb-3">
                            <img src="https://ui-avatars.com/api/?name=<?= urlencode($adminUsername) ?>&background=4e73df&color=fff&size=128" 
                                 class="rounded-circle border border-4 border-white shadow" 
                                 width="100" alt="Admin Avatar">
                        </div>
                        <h4 class="mb-1"><?= htmlspecialchars($adminUsername) ?></h4>
                        <p class="text-muted mb-3">Administrator</p>
                        
                        <div class="list-group list-group-flush text-start bg-transparent">
                            <div class="list-group-item bg-transparent border-0 px-0 d-flex justify-content-between align-items-center">
                                <span><i class="fas fa-envelope text-primary me-2"></i> Email</span>
                                <span class="text-muted text-truncate ms-2" style="max-width: 150px;"><?= htmlspecialchars($adminData['email'] ?? 'N/A') ?></span>
                            </div>
                            <div class="list-group-item bg-transparent border-0 px-0 d-flex justify-content-between align-items-center">
                                <span><i class="fas fa-calendar-alt text-primary me-2"></i> Member Since</span>
                                <span class="text-muted"><?= date('M Y', strtotime($adminData['created_at'] ?? 'now')) ?></span>
                            </div>
                            <div class="list-group-item bg-transparent border-0 px-0 d-flex justify-content-between align-items-center">
                                <span><i class="fas fa-clock text-primary me-2"></i> Last Login</span>
                                <span class="text-muted"><?= date('M j, H:i', $_SESSION['last_login'] ?? time()) ?></span>
                            </div>
                        </div>
                        
                        <a href="settings.php" class="btn btn-outline-primary mt-3 px-4 rounded-pill">
                            <i class="fas fa-user-cog me-1"></i> Update Profile
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Recent Activities -->
        <div class="row g-4">
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm animate__animated animate__fadeIn">
                    <div class="card-header bg-white border-0 py-3">
                        <h5 class="mb-0"><i class="fas fa-history text-primary me-2"></i> Recent Bookings</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($recentPayments)): ?>
                            <?php foreach ($recentPayments as $payment): ?>
                                <div class="activity-item" style="--color: #4e73df;">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <strong class="d-block">Payment #<?= $payment['payment_id'] ?></strong>
                                            <small class="text-muted">Booking Ref: <?= $payment['booking_reference'] ?></small>
                                        </div>
                                        <span class="text-success fw-bold">LKR<?= number_format($payment['amount'], 2) ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between mt-2">
                                        <small class="text-muted"><i class="far fa-clock me-1"></i> <?= date('M j, H:i', strtotime($payment['payment_date'])) ?></small>
                                        <a href="booking-details.php?id=<?= $payment['booking_id'] ?>" class="text-primary small">View Details <i class="fas fa-arrow-right ms-1"></i></a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center py-4 text-muted">
                                <i class="fas fa-info-circle fa-2x mb-3"></i>
                                <p>No recent payments found</p>
                            </div>
                        <?php endif; ?>
                        <div class="text-end mt-3">
                            <a href="reports.php?filter=payments" class="btn btn-sm btn-primary rounded-pill px-3">
                                View All Payments <i class="fas fa-arrow-right ms-1"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- System Overview -->
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm animate__animated animate__fadeIn">
                    <div class="card-header bg-white border-0 py-3">
                        <h5 class="mb-0"><i class="fas fa-server text-primary me-2"></i> System Health</h5>
                    </div>
                    <div class="card-body">
                        <div class="system-status mb-4">
                            <h6 class="mb-3 d-flex justify-content-between align-items-center">
                                <span>Storage Usage</span>
                                <small class="text-muted">65% used</small>
                            </h6>
                            <div class="progress mb-2" style="height: 8px; border-radius: 4px;">
                                <div class="progress-bar bg-success" role="progressbar" style="width: 65%"></div>
                            </div>
                            <div class="d-flex justify-content-between small text-muted">
                                <span>15.2 GB</span>
                                <span>23.4 GB</span>
                            </div>
                        </div>
                        
                        <div class="system-status mb-4">
                            <h6 class="mb-3 d-flex justify-content-between align-items-center">
                                <span>Server Load</span>
                                <small class="text-muted">30% capacity</small>
                            </h6>
                            <div class="progress mb-2" style="height: 8px; border-radius: 4px;">
                                <div class="progress-bar bg-info" role="progressbar" style="width: 30%"></div>
                            </div>
                            <div class="d-flex justify-content-between small text-muted">
                                <span>Optimal</span>
                                <span>2.4 GHz</span>
                            </div>
                        </div>
                        
                        <div class="system-status mb-3">
                            <h6 class="mb-3 d-flex justify-content-between align-items-center">
                                <span>Memory Usage</span>
                                <small class="text-muted">45% allocated</small>
                            </h6>
                            <div class="progress mb-2" style="height: 8px; border-radius: 4px;">
                                <div class="progress-bar bg-warning" role="progressbar" style="width: 45%"></div>
                            </div>
                            <div class="d-flex justify-content-between small text-muted">
                                <span>3.2 GB</span>
                                <span>7.0 GB</span>
                            </div>
                        </div>
                        
                        <div class="alert alert-warning small mb-0 rounded-pill">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Next maintenance: <?= date('F j', strtotime('+3 days')) ?> at 2:00 AM
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Animation on scroll
            const animatedElements = document.querySelectorAll('.animate__animated');
            
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('animate__fadeIn');
                        observer.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.1 });
            
            animatedElements.forEach(element => {
                observer.observe(element);
            });
            
            // Add ripple effect to quick action cards
            document.querySelectorAll('.quick-action-card').forEach(card => {
                card.addEventListener('click', function(e) {
                    // Remove any existing ripples
                    const existingRipples = this.querySelectorAll('.ripple-effect');
                    existingRipples.forEach(ripple => ripple.remove());
                    
                    // Create new ripple
                    const ripple = document.createElement('span');
                    ripple.classList.add('ripple-effect');
                    
                    // Position ripple
                    const rect = this.getBoundingClientRect();
                    const size = Math.max(rect.width, rect.height);
                    ripple.style.width = ripple.style.height = `${size}px`;
                    ripple.style.left = `${e.clientX - rect.left - size/2}px`;
                    ripple.style.top = `${e.clientY - rect.top - size/2}px`;
                    
                    // Add ripple to card
                    this.appendChild(ripple);
                    
                    // Remove ripple after animation
                    setTimeout(() => {
                        ripple.remove();
                    }, 600);
                });
            });
            
            // Initialize charts would go here
        });
    </script>
</body>
</html>