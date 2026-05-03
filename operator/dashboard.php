<?php
session_start();
require_once '../includes/config.php';

// Authentication check
if (!isset($_SESSION['loggedin'])) {
    header("Location: ../login.php");
    exit;
}

$operator_id = $_SESSION['user_id'];

// Get filters
$filter_type = $_GET['filter_type'] ?? 'monthly';
$selected_month = $_GET['month'] ?? date('m');
$selected_year = $_GET['year'] ?? date('Y');
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$filter_text = "";

// Base query
$base_query = "FROM bookings b
JOIN schedules s ON b.schedule_id = s.schedule_id
JOIN buses ON s.bus_id = buses.bus_id
WHERE buses.operator_id = ?";

// Build WHERE clause
$where_clause = "";
$params = [$operator_id];
$param_types = "i";

switch ($filter_type) {
    case 'all_time':
        $filter_text = "Showing all-time data";
        break;

    case 'yearly':
        $where_clause = " AND YEAR(b.booked_at) = ?";
        $params[] = $selected_year;
        $param_types .= "i";
        $filter_text = "Showing data for year $selected_year";
        break;

    case 'monthly':
        $where_clause = " AND MONTH(b.booked_at) = ? AND YEAR(b.booked_at) = ?";
        $params[] = $selected_month;
        $params[] = $selected_year;
        $param_types .= "ii";
        $month_name = date("F", mktime(0, 0, 0, $selected_month, 10));
        $filter_text = "Showing data for $month_name $selected_year";
        break;

    case 'last_30':
        $where_clause = " AND b.booked_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        $filter_text = "Showing data for last 30 days";
        break;

    case 'custom':
        if (!empty($date_from) && !empty($date_to)) {
            $where_clause = " AND DATE(b.booked_at) BETWEEN ? AND ?";
            $params[] = $date_from;
            $params[] = $date_to;
            $param_types .= "ss";
            $filter_text = "Showing data from " . date('M j, Y', strtotime($date_from)) . " to " . date('M j, Y', strtotime($date_to));
        }
        break;
}

// Global stats (all time)
$stats_query = "SELECT 
    COUNT(*) as total_bookings,
    SUM(CASE WHEN booking_status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_bookings,
    SUM(CASE WHEN booking_status = 'pending' THEN 1 ELSE 0 END) as pending_bookings,
    SUM(CASE WHEN booking_status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_bookings,
    SUM(total_fare) as total_revenue,
    SUM(CASE WHEN payment_status = 'paid' THEN total_fare ELSE 0 END) as paid_revenue
FROM bookings b
JOIN schedules s ON b.schedule_id = s.schedule_id
JOIN buses ON s.bus_id = buses.bus_id
WHERE buses.operator_id = ?";

$stats_stmt = $conn->prepare($stats_query);
$stats_stmt->bind_param("i", $operator_id);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$stats = $stats_result->fetch_assoc();

// Filtered stats (dynamic)
$filtered_stats_query = "SELECT 
    SUM(total_fare) as filtered_revenue,
    SUM(CASE WHEN payment_status = 'paid' THEN total_fare ELSE 0 END) as filtered_paid_revenue
$base_query $where_clause";

$filtered_stats_stmt = $conn->prepare($filtered_stats_query);
$filtered_stats_stmt->bind_param($param_types, ...$params);
$filtered_stats_stmt->execute();
$filtered_stats_result = $filtered_stats_stmt->get_result();
$filtered_stats = $filtered_stats_result->fetch_assoc();

// Recent bookings
$recent_query = "SELECT 
    b.booking_reference, 
    b.passenger_name, 
    b.from_location, 
    b.to_location, 
    b.travel_date, 
    b.total_fare, 
    b.booking_status,
    b.payment_status,
    b.booked_at
FROM bookings b
JOIN schedules s ON b.schedule_id = s.schedule_id
JOIN buses ON s.bus_id = buses.bus_id
WHERE buses.operator_id = ?
ORDER BY b.booked_at DESC
LIMIT 10";

$recent_stmt = $conn->prepare($recent_query);
$recent_stmt->bind_param("i", $operator_id);
$recent_stmt->execute();
$recent_bookings = $recent_stmt->get_result();

// Chart data (filtered)
$chart_query = "SELECT 
    DATE(b.booked_at) as booking_date,
    SUM(b.total_fare) as daily_revenue
$base_query $where_clause
GROUP BY DATE(b.booked_at)
ORDER BY booking_date";

$chart_stmt = $conn->prepare($chart_query);
$chart_stmt->bind_param($param_types, ...$params);
$chart_stmt->execute();
$revenue_data = $chart_stmt->get_result();

$chart_labels = [];
$chart_values = [];
while ($row = $revenue_data->fetch_assoc()) {
    $chart_labels[] = $row['booking_date'];
    $chart_values[] = $row['daily_revenue'];
}

if (empty($chart_labels)) {
    $chart_labels[] = date('Y-m-d');
    $chart_values[] = 0;
}

// Bus status counts
$bus_status_query = "SELECT 
    status, COUNT(*) as count 
FROM buses 
WHERE operator_id = ? 
GROUP BY status";

$bus_status_stmt = $conn->prepare($bus_status_query);
$bus_status_stmt->bind_param("i", $operator_id);
$bus_status_stmt->execute();
$bus_status_result = $bus_status_stmt->get_result();
$bus_status_counts = [
    'active' => 0,
    'maintenance' => 0,
    'inactive' => 0,
    'cancelled' => 0
];
while ($row = $bus_status_result->fetch_assoc()) {
    $bus_status_counts[$row['status']] = $row['count'];
}

// Helper: status color
function getStatusColor($status) {
    return match($status) {
        'confirmed', 'paid' => 'success',
        'pending' => 'warning',
        'cancelled', 'failed' => 'danger',
        'refunded' => 'info',
        default => 'secondary'
    };
}

// Year options (last 10 years)
$current_year = date('Y');
$year_options = [];
for ($i = 0; $i < 10; $i++) {
    $year = $current_year - $i;
    $year_options[$year] = $year;
}

// Month options
$month_options = [
    '01' => 'January', '02' => 'February', '03' => 'March', '04' => 'April',
    '05' => 'May', '06' => 'June', '07' => 'July', '08' => 'August',
    '09' => 'September', '10' => 'October', '11' => 'November', '12' => 'December'
];

// Filter type options
$filter_type_options = [
    'all_time' => 'All Time',
    'yearly' => 'This Year',
    'monthly' => 'By Month',
    'last_30' => 'Last 30 Days',
    'custom' => 'Custom Range'
];

?>


<?php include 'header.php'; ?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Operator Dashboard | SmartBusLink</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <style>
        :root {
            --primary-color: #4e73df;
            --primary-light: #e6eaf9;
            --secondary-color: #1cc88a;
            --secondary-light: #e8f8f1;
            --danger-color: #e74a3b;
            --danger-light: #fce8e6;
            --warning-color: #f6c23e;
            --warning-light: #fef6e6;
            --info-color: #36b9cc;
            --info-light: #e6f4f7;
            --dark-color: #5a5c69;
            --light-color: #f8f9fc;
            --border-radius: 0.5rem;
            --box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.1);
            --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        }
        
        body {
            background-color: #f5f7fb;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            color: #4a4a4a;
        }
        
        .dashboard-header {
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid rgba(0,0,0,0.1);
        }
        
        .dashboard-header h1 {
            font-weight: 700;
            color: var(--primary-color);
            position: relative;
        }
        
        .dashboard-header h1::after {
            content: "";
            position: absolute;
            bottom: -12px;
            left: 0;
            width: 60px;
            height: 4px;
            background: var(--primary-color);
            border-radius: 2px;
        }
        
        .stat-card {
            border: none;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            transition: var(--transition);
            overflow: hidden;
            background: white;
            position: relative;
            border-left: 0.25rem solid;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 2rem 0 rgba(58, 59, 69, 0.15);
        }
        
        .stat-card.primary {
            border-left-color: var(--primary-color);
        }
        
        .stat-card.success {
            border-left-color: var(--secondary-color);
        }
        
        .stat-card.warning {
            border-left-color: var(--warning-color);
        }
        
        .stat-card.danger {
            border-left-color: var(--danger-color);
        }
        
        .stat-card .card-body {
            padding: 1.25rem;
        }
        
        .stat-card h6 {
            font-size: 0.875rem;
            color: var(--dark-color);
            margin-bottom: 0.5rem;
            font-weight: 600;
        }
        
        .stat-card h3 {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0;
        }
        
        .stat-card .icon {
            position: absolute;
            right: 1.25rem;
            top: 1.25rem;
            opacity: 0.2;
            font-size: 3rem;
        }
        
        .revenue-card {
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 1.5rem;
            background: white;
            margin-bottom: 1.5rem;
        }
        
        .revenue-card h6 {
            font-size: 0.875rem;
            color: var(--dark-color);
            margin-bottom: 0.5rem;
            font-weight: 600;
        }
        
        .revenue-card h3 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0;
        }
        
        .chart-container {
            position: relative;
            height: 300px;
            margin-top: 1.5rem;
        }
        
        .recent-bookings {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .recent-bookings h5 {
            font-weight: 600;
            margin-bottom: 1.5rem;
            color: var(--dark-color);
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
            padding: 1rem;
            border-bottom-width: 1px;
        }
        
        .table td {
            padding: 0.75rem 1rem;
            vertical-align: middle;
        }
        
        .table tbody tr:hover {
            background-color: rgba(0,0,0,0.02);
        }
        
        .badge {
            font-weight: 500;
            padding: 0.35em 0.65em;
            border-radius: 50rem;
            font-size: 0.75rem;
        }
        
        .filter-container {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .filter-label {
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 0.5rem;
        }

        /* Bus Status Cards */
        .bus-status-card {
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 1.5rem;
            background: white;
            margin-bottom: 1.5rem;
            position: relative;
            overflow: hidden;
        }
        
        .bus-status-card.active {
            border-left: 4px solid var(--secondary-color);
        }
        
        .bus-status-card.maintenance {
            border-left: 4px solid var(--warning-color);
        }
        
        .bus-status-card.inactive {
            border-left: 4px solid var(--danger-color);
        }
        
        .bus-status-card h5 {
            font-weight: 600;
            margin-bottom: 1rem;
        }
        
        .bus-status-card .count {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .bus-status-card .icon {
            position: absolute;
            right: 1.5rem;
            top: 1.5rem;
            opacity: 0.1;
            font-size: 4rem;
        }
        
        .bus-status-card.active .icon {
            color: var(--secondary-color);
        }
        
        .bus-status-card.maintenance .icon {
            color: var(--warning-color);
        }
        
        .bus-status-card.inactive .icon {
            color: var(--danger-color);
        }
        
        @media (max-width: 768px) {
            .stat-card {
                margin-bottom: 1rem;
            }
            
            .stat-card h3 {
                font-size: 1.5rem;
            }
            
            .chart-container {
                height: 250px;
            }
        }
        
        /* Animation classes */
        .animate-on-scroll {
            opacity: 0;
        }
        
        .fade-in {
            animation: fadeIn 0.5s forwards;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body class="bg-light">
    <div class="container py-4">
        <div class="dashboard-header animate-on-scroll fade-in">
            <h1><i class="fas fa-tachometer-alt me-2"></i>Operator Dashboard</h1>
        </div>

        <!-- Improved Revenue Filter -->
        <div class="filter-container animate-on-scroll fade-in">
            <form method="get" class="row g-3">
                <div class="col-md-3">
                    <label class="filter-label">Filter Type</label>
                    <select class="form-select" name="filter_type" id="filterType">
                        <?php foreach ($filter_type_options as $value => $name): ?>
                            <option value="<?= $value ?>" <?= $filter_type == $value ? 'selected' : '' ?>>
                                <?= $name ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Monthly Filter (shown when monthly is selected) -->
                <div class="col-md-2 filter-option" id="monthFilter" style="<?= $filter_type == 'monthly' ? '' : 'display: none;' ?>">
                    <label class="filter-label">Month</label>
                    <select class="form-select" name="month">
                        <?php foreach ($month_options as $value => $name): ?>
                            <option value="<?= $value ?>" <?= $selected_month == $value ? 'selected' : '' ?>>
                                <?= $name ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Year Filter (shown for monthly and yearly) -->
                <div class="col-md-2 filter-option" id="yearFilter" style="<?= in_array($filter_type, ['monthly', 'yearly']) ? '' : 'display: none;' ?>">
                    <label class="filter-label">Year</label>
                    <select class="form-select" name="year">
                        <?php foreach ($year_options as $year): ?>
                            <option value="<?= $year ?>" <?= $selected_year == $year ? 'selected' : '' ?>>
                                <?= $year ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Custom Date Range (shown when custom is selected) -->
                <div class="col-md-3 filter-option" id="customDateFilter" style="<?= $filter_type == 'custom' ? '' : 'display: none;' ?>">
                    <label class="filter-label">From Date</label>
                    <input type="date" class="form-control" name="date_from" value="<?= $date_from ?>">
                </div>
                <div class="col-md-3 filter-option" id="customDateFilter2" style="<?= $filter_type == 'custom' ? '' : 'display: none;' ?>">
                    <label class="filter-label">To Date</label>
                    <input type="date" class="form-control" name="date_to" value="<?= $date_to ?>">
                </div>
                
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100 me-2">
                        <i class="fas fa-filter me-2"></i> Apply
                    </button>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <a href="?" class="btn btn-outline-secondary w-100">
                        <i class="fas fa-times me-2"></i> Clear
                    </a>
                </div>
                
                <div class="col-12 mt-2">
                    <div class="text-muted small">
                        <i class="fas fa-info-circle me-2"></i><?= $filter_text ?>
                    </div>
                </div>
            </form>
        </div>

        <!-- Booking Stats -->
        <div class="row mb-4 animate-on-scroll fade-in" style="animation-delay: 0.1s;">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="card-body">
                        <i class="fas fa-ticket-alt icon text-muted"></i>
                        <h6>Total Bookings</h6>
                        <h3><?= $stats['total_bookings'] ?? 0 ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card success">
                    <div class="card-body">
                        <i class="fas fa-check-circle icon text-success"></i>
                        <h6>Confirmed</h6>
                        <h3><?= $stats['confirmed_bookings'] ?? 0 ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card warning">
                    <div class="card-body">
                        <i class="fas fa-clock icon text-warning"></i>
                        <h6>Pending</h6>
                        <h3><?= $stats['pending_bookings'] ?? 0 ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card danger">
                    <div class="card-body">
                        <i class="fas fa-times-circle icon text-danger"></i>
                        <h6>Cancelled</h6>
                        <h3><?= $stats['cancelled_bookings'] ?? 0 ?></h3>
                    </div>
                </div>
            </div>
        </div>

        <!-- Available Buses Section -->
        <div class="row mb-4 animate-on-scroll fade-in" style="animation-delay: 0.2s;">
            <div class="col-md-4">
                <div class="bus-status-card active">
                    <i class="fas fa-bus icon"></i>
                    <h5>Active Buses</h5>
                    <div class="count text-success"><?= $bus_status_counts['active'] ?></div>
                    <p class="text-muted mb-0">Currently in service</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="bus-status-card maintenance">
                    <i class="fas fa-tools icon"></i>
                    <h5>In Maintenance</h5>
                    <div class="count text-warning"><?= $bus_status_counts['maintenance'] ?></div>
                    <p class="text-muted mb-0">Undergoing repairs</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="bus-status-card inactive">
                    <i class="fas fa-ban icon"></i>
                    <h5>Inactive Buses</h5>
                    <div class="count text-danger"><?= $bus_status_counts['inactive'] ?></div>
                    <p class="text-muted mb-0">Not in service</p>
                </div>
            </div>
        </div>

        <!-- Revenue Cards -->
        <div class="row mb-4 animate-on-scroll fade-in" style="animation-delay: 0.3s;">
            <div class="col-md-6">
                <div class="revenue-card">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <h6>Total Revenue (LKR)</h6>
                            <h3 class="text-primary">Rs. <?= number_format($stats['total_revenue'] ?? 0, 2) ?></h3>
                            <small class="text-muted">All-time total</small>
                        </div>
                        <div class="flex-shrink-0">
                            <i class="fas fa-wallet fa-2x text-primary opacity-25"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="revenue-card">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <h6>Filtered Revenue (LKR)</h6>
                            <h3 class="text-success">Rs. <?= number_format($filtered_stats['filtered_revenue'] ?? 0, 2) ?></h3>
                            <small class="text-muted"><?= $filter_text ?></small>
                        </div>
                        <div class="flex-shrink-0">
                            <i class="fas fa-money-bill-wave fa-2x text-success opacity-25"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Filter type toggle
        document.getElementById('filterType').addEventListener('change', function() {
            const filterType = this.value;
            
            // Hide all filter options first
            document.querySelectorAll('.filter-option').forEach(el => {
                el.style.display = 'none';
            });
            
            // Show relevant options based on selected filter type
            if (filterType === 'monthly') {
                document.getElementById('monthFilter').style.display = 'block';
                document.getElementById('yearFilter').style.display = 'block';
            } else if (filterType === 'yearly') {
                document.getElementById('yearFilter').style.display = 'block';
            } else if (filterType === 'custom') {
                document.getElementById('customDateFilter').style.display = 'block';
                document.getElementById('customDateFilter2').style.display = 'block';
            }
        });
        
        // Animation on scroll
        document.addEventListener('DOMContentLoaded', function() {
            const animateElements = document.querySelectorAll('.animate-on-scroll');
            
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('fade-in');
                        observer.unobserve(entry.target);
                    }
                });
            }, {
                threshold: 0.1
            });
            
            animateElements.forEach(element => {
                observer.observe(element);
            });
        });
        
        // Revenue chart
        const ctx = document.createElement('canvas');
        ctx.height = 300;
        document.querySelector('.chart-container').appendChild(ctx);
        
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?= json_encode($chart_labels) ?>,
                datasets: [{
                    label: 'Daily Revenue (LKR)',
                    data: <?= json_encode($chart_values) ?>,
                    backgroundColor: 'rgba(78, 115, 223, 0.05)',
                    borderColor: 'rgba(78, 115, 223, 1)',
                    borderWidth: 2,
                    pointBackgroundColor: 'rgba(78, 115, 223, 1)',
                    pointRadius: 3,
                    pointHoverRadius: 5,
                    tension: 0.1,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return 'Rs. ' + context.raw.toLocaleString();
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return 'Rs. ' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>