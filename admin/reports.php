<?php
require_once('../includes/config.php');
require_once('../includes/auth.php');
include 'navbar.php';

// Verify admin access
if (!isAdmin()) {
    header("Location: ../login.php?redirect=admin/reports.php");
    exit();
}

// Date range handling (default: current month)
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'financial';

// Initialize variables
$report_data = [];
$chart_labels = [];
$chart_values = [];

// Generate reports based on type
switch ($report_type) {
    case 'financial':
        // Financial summary report
        $query = "SELECT 
                    SUM(p.amount) as total_revenue,
                    SUM(p.fee_amount) as total_fees,
                    COUNT(b.booking_id) as total_bookings,
                    AVG(p.amount) as avg_booking_value
                  FROM payments p
                  JOIN bookings b ON p.booking_id = b.booking_id
                  WHERE p.payment_status = 'completed' 
                  AND p.payment_date BETWEEN ? AND ?";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ss", $start_date, $end_date);
        $stmt->execute();
        $result = $stmt->get_result();
        $report_data = $result->fetch_assoc();
        $stmt->close();
        
        // Daily revenue chart data
        $chart_query = "SELECT 
                         DATE(p.payment_date) as day,
                         SUM(p.amount) as daily_revenue
                       FROM payments p
                       WHERE p.payment_status = 'completed'
                       AND p.payment_date BETWEEN ? AND ?
                       GROUP BY DATE(p.payment_date)
                       ORDER BY day";
        
        $stmt = $conn->prepare($chart_query);
        $stmt->bind_param("ss", $start_date, $end_date);
        $stmt->execute();
        $chart_result = $stmt->get_result();
        
        while ($row = $chart_result->fetch_assoc()) {
            $chart_labels[] = $row['day'];
            $chart_values[] = $row['daily_revenue'];
        }
        $stmt->close();
        break;
        
    case 'bookings':
        // Booking statistics report
        $query = "SELECT 
                    COUNT(*) as total_bookings,
                    SUM(CASE WHEN b.booking_status = 'completed' THEN 1 ELSE 0 END) as completed_bookings,
                    SUM(CASE WHEN b.booking_status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_bookings,
                    AVG(b.total_amount) as avg_booking_amount
                  FROM bookings b
                  WHERE b.booked_at BETWEEN ? AND ?";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ss", $start_date, $end_date);
        $stmt->execute();
        $result = $stmt->get_result();
        $report_data = $result->fetch_assoc();
        $stmt->close();
        
        // Booking status pie chart data
        $chart_query = "SELECT 
                         b.booking_status,
                         COUNT(*) as status_count
                       FROM bookings b
                       WHERE b.booked_at BETWEEN ? AND ?
                       GROUP BY b.booking_status";
        
        $stmt = $conn->prepare($chart_query);
        $stmt->bind_param("ss", $start_date, $end_date);
        $stmt->execute();
        $chart_result = $stmt->get_result();
        
        while ($row = $chart_result->fetch_assoc()) {
            $chart_labels[] = ucfirst($row['booking_status']);
            $chart_values[] = $row['status_count'];
        }
        $stmt->close();
        break;
        
    case 'operators':
        // Top operators by revenue
        $query = "SELECT 
                    o.company_name,
                    COUNT(b.booking_id) as total_bookings,
                    SUM(p.amount) as total_revenue
                  FROM operators o
                  JOIN buses bs ON o.operator_id = bs.operator_id
                  JOIN bookings b ON bs.bus_id = b.schedule_id
                  JOIN payments p ON b.booking_id = p.booking_id
                  WHERE p.payment_status = 'completed'
                  AND p.payment_date BETWEEN ? AND ?
                  GROUP BY o.operator_id
                  ORDER BY total_revenue DESC
                  LIMIT 10";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ss", $start_date, $end_date);
        $stmt->execute();
        $result = $stmt->get_result();
        $report_data = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        break;
        
    case 'system':
        // System usage statistics
        $query = "SELECT 
                    (SELECT COUNT(*) FROM users) as total_users,
                    (SELECT COUNT(*) FROM users WHERE is_verified = 1) as verified_users,
                    (SELECT COUNT(*) FROM operators) as total_operators,
                    (SELECT COUNT(*) FROM operators WHERE is_approved = 1) as approved_operators,
                    (SELECT COUNT(*) FROM buses) as total_buses,
                    (SELECT COUNT(*) FROM bookings) as total_bookings_alltime";
        
        $stmt = $conn->prepare($query);
        $stmt->execute();
        $result = $stmt->get_result();
        $report_data = $result->fetch_assoc();
        $stmt->close();
        break;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - Admin Reports</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
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
        
        .card {
            border: none;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            transition: var(--transition);
            overflow: hidden;
            background: white;
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 2rem 0 rgba(58, 59, 69, 0.15);
        }
        
        .card-header {
            background: white;
            border-bottom: 1px solid rgba(0,0,0,0.05);
            padding: 1.25rem 1.5rem;
        }
        
        .card-body {
            padding: 1.5rem;
        }
        
        .stat-card {
            border-left: 0.25rem solid;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 100%);
            z-index: 0;
        }
        
        .stat-card.primary {
            border-left-color: var(--primary-color);
            background-color: var(--primary-light);
        }
        
        .stat-card.success {
            border-left-color: var(--secondary-color);
            background-color: var(--secondary-light);
        }
        
        .stat-card.danger {
            border-left-color: var(--danger-color);
            background-color: var(--danger-light);
        }
        
        .stat-card.warning {
            border-left-color: var(--warning-color);
            background-color: var(--warning-light);
        }
        
        .stat-card.info {
            border-left-color: var(--info-color);
            background-color: var(--info-light);
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
        }
        
        .stat-card .card-body {
            position: relative;
            z-index: 1;
        }
        
        .stat-card .icon-wrapper {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: rgba(255,255,255,0.3);
        }
        
        .stat-card.primary .icon-wrapper {
            background-color: rgba(78, 115, 223, 0.1);
        }
        
        .stat-card.success .icon-wrapper {
            background-color: rgba(28, 200, 138, 0.1);
        }
        
        .stat-card.danger .icon-wrapper {
            background-color: rgba(231, 74, 59, 0.1);
        }
        
        .stat-card.warning .icon-wrapper {
            background-color: rgba(246, 194, 62, 0.1);
        }
        
        .stat-card.info .icon-wrapper {
            background-color: rgba(54, 185, 204, 0.1);
        }
        
        .chart-container {
            position: relative;
            height: 300px;
            background: #fff;
            border-radius: var(--border-radius);
            padding: 1rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.05);
        }
        
        .date-range-display {
            background-color: white;
            border-radius: var(--border-radius);
            padding: 12px 20px;
            margin-bottom: 20px;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-size: 0.95rem;
            font-weight: 500;
            color: var(--dark-color);
            box-shadow: var(--box-shadow);
            border-left: 4px solid var(--primary-color);
        }
        
        .report-title {
            border-bottom: 2px solid #eee;
            padding-bottom: 12px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .table-responsive {
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.05);
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
        
        .table-striped tbody tr:nth-of-type(odd) {
            background-color: rgba(0,0,0,0.01);
        }
        
        .progress-thin {
            height: 6px;
            border-radius: 3px;
        }
        
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
        
        .loading-spinner {
            display: none;
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(255, 255, 255, 0.8);
            z-index: 10;
            border-radius: var(--border-radius);
            display: flex;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(2px);
        }
        
        .report-container {
            position: relative;
            min-height: 200px;
        }
        
        .container-fluid {
            padding-left: 25px;
            padding-right: 25px;
        }
        
        main {
            width: 100%;
            padding: 25px;
        }
        
        h1, h2, h3, h4, h5, h6 {
            font-weight: 600;
            color: #2c3e50;
        }
        
        .btn {
            border-radius: var(--border-radius);
            padding: 0.5rem 1.25rem;
            font-weight: 500;
            transition: var(--transition);
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-primary:hover {
            background-color: #3a5bd9;
            border-color: #3a5bd9;
            transform: translateY(-2px);
        }
        
        .form-control, .form-select {
            border-radius: var(--border-radius);
            padding: 0.5rem 0.75rem;
            border: 1px solid #e0e0e0;
            transition: var(--transition);
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(78, 115, 223, 0.2);
        }
        
        .dropdown-menu {
            border-radius: var(--border-radius);
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.1);
            border: none;
        }
        
        .dropdown-item {
            padding: 0.5rem 1rem;
            transition: var(--transition);
        }
        
        .dropdown-item:hover {
            background-color: var(--primary-light);
            color: var(--primary-color);
        }
        
        .modal-content {
            border-radius: var(--border-radius);
            border: none;
            box-shadow: 0 0.5rem 2rem rgba(0, 0, 0, 0.15);
        }
        
        .accordion-button {
            font-weight: 500;
            background-color: white;
        }
        
        .accordion-button:not(.collapsed) {
            background-color: var(--primary-light);
            color: var(--primary-color);
        }
        
        .accordion-item {
            border-radius: var(--border-radius) !important;
            overflow: hidden;
            margin-bottom: 10px;
            border: 1px solid rgba(0,0,0,0.05);
        }
        
        .badge {
            font-weight: 500;
            padding: 0.35em 0.65em;
            border-radius: 50rem;
        }
        
        .page-header {
            border-bottom: 1px solid rgba(0,0,0,0.05);
            padding-bottom: 1rem;
            margin-bottom: 2rem;
        }
        
        .page-header h1 {
            font-weight: 700;
            color: var(--primary-color);
            position: relative;
            display: inline-block;
        }
        
        .page-header h1::after {
            content: "";
            position: absolute;
            bottom: -12px;
            left: 0;
            width: 60px;
            height: 4px;
            background: var(--primary-color);
            border-radius: 2px;
        }
        
        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section-title i {
            font-size: 1.5rem;
            color: var(--primary-color);
        }
        
        @media (max-width: 768px) {
            .chart-container {
                height: 250px;
            }
            
            .container-fluid {
                padding-left: 15px;
                padding-right: 15px;
            }
            
            main {
                padding: 15px;
            }
            
            .date-range-display {
                flex-direction: column;
                align-items: flex-start;
                padding: 10px;
            }
            
            .stat-card .col-auto {
                margin-top: 10px;
            }
        }
        
        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: var(--primary-color);
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #3a5bd9;
        }
        
        /* Tooltip styling */
        .tooltip {
            font-family: inherit;
        }
        
        .tooltip-inner {
            background-color: var(--dark-color);
            font-size: 0.85rem;
            padding: 6px 12px;
            border-radius: var(--border-radius);
        }
        
        .bs-tooltip-auto[data-popper-placement^=top] .tooltip-arrow::before, 
        .bs-tooltip-top .tooltip-arrow::before {
            border-top-color: var(--dark-color);
        }
        
        /* Print styles */
        @media print {
            body {
                background-color: white !important;
                color: black !important;
            }
            
            .card {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
            }
            
            .no-print {
                display: none !important;
            }
            
            .page-header {
                border-bottom: 2px solid #ddd;
            }
        }
    </style>
</head>
<body class="bg-light">
    <div class="container-fluid">
        <main>
            <div class="page-header d-flex justify-content-between align-items-center mb-4">
                <h1 class="animate-on-scroll">
                    </i>System Reports Dashboard
                </h1>
                <div class="btn-toolbar no-print">
                    <div class="btn-group">
                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#helpModal">
                            <i class="bi bi-question-circle me-1"></i> Help Guide
                        </button>
                        <button class="btn btn-sm btn-outline-secondary" id="printReportBtn">
                            <i class="bi bi-printer me-1"></i> Print
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Quick Stats -->
            <div class="row mb-4 animate-on-scroll" style="animation-delay: 0.1s;">
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card stat-card primary h-100">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col">
                                    <div class="text-xs fw-bold text-uppercase mb-1">Total Revenue</div>
                                    <div class="h4 mb-0 fw-bold">
                                        <?php echo isset($report_data['total_revenue']) ? 'LKR ' . number_format($report_data['total_revenue'], 2) : 'N/A'; ?>
                                    </div>
                                    <div class="mt-2 text-muted small">
                                        <span class="text-success">
                                            <i class="bi bi-arrow-up"></i> 12.5%
                                        </span> vs last period
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <div class="icon-wrapper">
                                        <i class="bi bi-currency-exchange fs-3 text-primary"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card stat-card success h-100">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col">
                                    <div class="text-xs fw-bold text-uppercase mb-1">Total Bookings</div>
                                    <div class="h4 mb-0 fw-bold">
                                        <?php echo isset($report_data['total_bookings']) ? $report_data['total_bookings'] : 'N/A'; ?>
                                    </div>
                                    <div class="mt-2 text-muted small">
                                        <span class="text-success">
                                            <i class="bi bi-arrow-up"></i> 8.3%
                                        </span> vs last period
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <div class="icon-wrapper">
                                        <i class="bi bi-ticket-perforated fs-3 text-success"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card stat-card info h-100">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col">
                                    <div class="text-xs fw-bold text-uppercase mb-1">Avg. Booking Value</div>
                                    <div class="h4 mb-0 fw-bold">
                                        <?php echo isset($report_data['avg_booking_value']) ? 'LKR ' . number_format($report_data['avg_booking_value'], 2) : 'N/A'; ?>
                                    </div>
                                    <div class="mt-2 text-muted small">
                                        <span class="text-danger">
                                            <i class="bi bi-arrow-down"></i> 2.4%
                                        </span> vs last period
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <div class="icon-wrapper">
                                        <i class="bi bi-graph-up fs-3 text-info"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card stat-card warning h-100">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col">
                                    <div class="text-xs fw-bold text-uppercase mb-1">Date Range</div>
                                    <div class="h4 mb-0 fw-bold">
                                        <?php echo date('M j', strtotime($start_date)) . ' - ' . date('M j, Y', strtotime($end_date)); ?>
                                    </div>
                                    <div class="mt-2 text-muted small">
                                        <span class="text-info">
                                            <i class="bi bi-calendar3"></i> Custom range
                                        </span>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <div class="icon-wrapper">
                                        <i class="bi bi-calendar-range fs-3 text-warning"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Report Filters -->
            <div class="card mb-4 animate-on-scroll" style="animation-delay: 0.2s;">
                <div class="card-header">
                    <h5 class="m-0"><i class="bi bi-funnel me-2"></i>Report Filters</h5>
                </div>
                <div class="card-body">
                    <form method="get" class="row g-3" id="reportForm">
                        <div class="col-md-3">
                            <label for="report_type" class="form-label">Report Type</label>
                            <select class="form-select" id="report_type" name="report_type">
                                <option value="financial" <?php echo $report_type == 'financial' ? 'selected' : ''; ?>>Financial Summary</option>
                                <option value="bookings" <?php echo $report_type == 'bookings' ? 'selected' : ''; ?>>Booking Statistics</option>
                                <option value="operators" <?php echo $report_type == 'operators' ? 'selected' : ''; ?>>Top Operators</option>
                                <option value="system" <?php echo $report_type == 'system' ? 'selected' : ''; ?>>System Overview</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="start_date" class="form-label">Start Date</label>
                            <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo $start_date; ?>">
                        </div>
                        <div class="col-md-3">
                            <label for="end_date" class="form-label">End Date</label>
                            <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo $end_date; ?>">
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100" id="generateBtn">
                                <span id="generateText">Generate Report</span>
                                <span id="generateSpinner" class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Report Display -->
            <div class="report-container">
    <div class="loading-overlay" id="loadingSpinner">     
                <div class="card mb-4 animate-on-scroll" style="animation-delay: 0.3s;" id="reportCard">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="m-0">
                            <i class="bi bi-<?php 
                                echo $report_type == 'financial' ? 'graph-up' : 
                                ($report_type == 'bookings' ? 'ticket-perforated' : 
                                ($report_type == 'operators' ? 'building' : 'speedometer2')); 
                            ?> me-2"></i>
                            <?php 
                            echo ucfirst($report_type) . " Report";
                            if ($report_type != 'system') {
                                echo " (" . date('M j, Y', strtotime($start_date)) . " to " . date('M j, Y', strtotime($end_date)) . ")";
                            }
                            ?>
                        </h5>
                        <div class="dropdown no-print">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" id="dropdownMenuButton" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-gear me-1"></i> Options
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="dropdownMenuButton">
                                <li><a class="dropdown-item" href="#" id="exportCSV"><i class="bi bi-file-earmark-excel me-2"></i> Export as CSV</a></li>
                                <li><a class="dropdown-item" href="#" id="exportPDF"><i class="bi bi-file-earmark-pdf me-2"></i> Export as PDF</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="#" id="refreshReport"><i class="bi bi-arrow-clockwise me-2"></i> Refresh</a></li>
                            </ul>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if ($report_type == 'financial'): ?>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Metric</th>
                                                    <th class="text-end">Value</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td>Total Revenue</td>
                                                    <td class="text-end fw-bold">LKR <?php echo number_format($report_data['total_revenue'], 2); ?></td>
                                                </tr>
                                                <tr>
                                                    <td>Total Fees</td>
                                                    <td class="text-end fw-bold">LKR <?php echo number_format($report_data['total_fees'], 2); ?></td>
                                                </tr>
                                                <tr>
                                                    <td>Total Bookings</td>
                                                    <td class="text-end fw-bold"><?php echo $report_data['total_bookings']; ?></td>
                                                </tr>
                                                <tr>
                                                    <td>Average Booking Value</td>
                                                    <td class="text-end fw-bold">LKR <?php echo number_format($report_data['avg_booking_value'], 2); ?></td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="chart-container">
                                        <canvas id="revenueChart"></canvas>
                                    </div>
                                </div>
                            </div>
                            
                        <?php elseif ($report_type == 'bookings'): ?>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Metric</th>
                                                    <th class="text-end">Value</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td>Total Bookings</td>
                                                    <td class="text-end fw-bold"><?php echo $report_data['total_bookings']; ?></td>
                                                </tr>
                                                <tr>
                                                    <td>Completed Bookings</td>
                                                    <td class="text-end fw-bold"><?php echo $report_data['completed_bookings']; ?></td>
                                                </tr>
                                                <tr>
                                                    <td>Cancelled Bookings</td>
                                                    <td class="text-end fw-bold"><?php echo $report_data['cancelled_bookings']; ?></td>
                                                </tr>
                                                <tr>
                                                    <td>Average Booking Amount</td>
                                                    <td class="text-end fw-bold">LKR <?php echo number_format($report_data['avg_booking_amount'], 2); ?></td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="chart-container">
                                        <canvas id="bookingsChart"></canvas>
                                    </div>
                                </div>
                            </div>
                            
                        <?php elseif ($report_type == 'operators'): ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Operator</th>
                                            <th class="text-end">Bookings</th>
                                            <th class="text-end">Revenue</th>
                                            <th style="width: 200px;">Revenue Share</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $total_revenue = array_sum(array_column($report_data, 'total_revenue'));
                                        foreach ($report_data as $operator): 
                                            $percentage = $total_revenue > 0 ? ($operator['total_revenue'] / $total_revenue) * 100 : 0;
                                        ?>
                                            <tr>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="flex-shrink-0 me-2">
                                                            <div class="avatar-sm bg-light rounded p-1">
                                                                <span class="text-primary fs-4 fw-bold"><?php echo substr($operator['company_name'], 0, 1); ?></span>
                                                            </div>
                                                        </div>
                                                        <div class="flex-grow-1">
                                                            <h6 class="mb-0"><?php echo htmlspecialchars($operator['company_name']); ?></h6>
                                                            
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="text-end fw-bold"><?php echo $operator['total_bookings']; ?></td>
                                                <td class="text-end fw-bold">LKR <?php echo number_format($operator['total_revenue'], 2); ?></td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="flex-grow-1 me-3">
                                                            <div class="progress progress-thin">
                                                                <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $percentage; ?>%" 
                                                                    aria-valuenow="<?php echo $percentage; ?>" aria-valuemin="0" aria-valuemax="100">
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="flex-shrink-0">
                                                            <small class="fw-bold"><?php echo number_format($percentage, 1); ?>%</small>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                        <?php elseif ($report_type == 'system'): ?>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Metric</th>
                                                    <th class="text-end">Value</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td>Total Users</td>
                                                    <td class="text-end fw-bold"><?php echo $report_data['total_users']; ?></td>
                                                </tr>
                                                <tr>
                                                    <td>Verified Users</td>
                                                    <td class="text-end fw-bold"><?php echo $report_data['verified_users']; ?></td>
                                                </tr>
                                                <tr>
                                                    <td>Total Operators</td>
                                                    <td class="text-end fw-bold"><?php echo $report_data['total_operators']; ?></td>
                                                </tr>
                                                <tr>
                                                    <td>Approved Operators</td>
                                                    <td class="text-end fw-bold"><?php echo $report_data['approved_operators']; ?></td>
                                                </tr>
                                                <tr>
                                                    <td>Total Buses</td>
                                                    <td class="text-end fw-bold"><?php echo $report_data['total_buses']; ?></td>
                                                </tr>
                                                <tr>
                                                    <td>Total Bookings (All Time)</td>
                                                    <td class="text-end fw-bold"><?php echo $report_data['total_bookings_alltime']; ?></td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card h-100">
                                        <div class="card-header">
                                            <h6 class="m-0"><i class="bi bi-heart-pulse me-2"></i>System Health</h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="mb-4">
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span class="fw-bold">User Verification Rate</span>
                                                    <span class="fw-bold">
                                                        <?php $verification_rate = $report_data['total_users'] > 0 ? ($report_data['verified_users'] / $report_data['total_users']) * 100 : 0; ?>
                                                        <?php echo number_format($verification_rate, 1); ?>%
                                                    </span>
                                                </div>
                                                <div class="progress" style="height: 10px;">
                                                    <div class="progress-bar bg-<?php echo $verification_rate > 75 ? 'success' : ($verification_rate > 50 ? 'warning' : 'danger'); ?>" 
                                                        role="progressbar" style="width: <?php echo $verification_rate; ?>%" 
                                                        aria-valuenow="<?php echo $verification_rate; ?>" aria-valuemin="0" aria-valuemax="100">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="mb-4">
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span class="fw-bold">Operator Approval Rate</span>
                                                    <span class="fw-bold">
                                                        <?php $approval_rate = $report_data['total_operators'] > 0 ? ($report_data['approved_operators'] / $report_data['total_operators']) * 100 : 0; ?>
                                                        <?php echo number_format($approval_rate, 1); ?>%
                                                    </span>
                                                </div>
                                                <div class="progress" style="height: 10px;">
                                                    <div class="progress-bar bg-<?php echo $approval_rate > 75 ? 'success' : ($approval_rate > 50 ? 'warning' : 'danger'); ?>" 
                                                        role="progressbar" style="width: <?php echo $approval_rate; ?>%" 
                                                        aria-valuenow="<?php echo $approval_rate; ?>" aria-valuemin="0" aria-valuemax="100">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="alert alert-info bg-light border-0">
                                                <div class="d-flex align-items-center">
                                                    <i class="bi bi-info-circle-fill fs-4 me-3 text-info"></i>
                                                    <div>
                                                        <h6 class="alert-heading mb-1">System Overview</h6>
                                                        <p class="mb-0 small">Shows current snapshot of all data in the system.</p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Export Options -->
            <div class="card animate-on-scroll no-print" style="animation-delay: 0.4s;">
                <div class="card-header">
                    <h5 class="m-0"><i class="bi bi-download me-2"></i>Export Options</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
    <div class="d-grid">
        <a href="export-report.php?type=<?php echo urlencode($report_type); ?>&start=<?php echo urlencode($start_date); ?>&end=<?php echo urlencode($end_date); ?>&format=csv" 
           class="btn btn-outline-secondary export-btn" 
           data-type="csv">
            <i class="bi bi-file-earmark-excel me-2"></i> Export as CSV
        </a>
    </div>
</div>
<div class="col-md-4">
    <div class="d-grid">
        <a href="export-report.php?type=<?php echo urlencode($report_type); ?>&start=<?php echo urlencode($start_date); ?>&end=<?php echo urlencode($end_date); ?>&format=pdf" 
           class="btn btn-outline-danger export-btn" 
           data-type="pdf">
            <i class="bi bi-file-earmark-pdf me-2"></i> Export as PDF
        </a>
    </div>
</div>
                        
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Help Modal -->
    <div class="modal fade" id="helpModal" tabindex="-1" aria-labelledby="helpModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-light">
                    <h5 class="modal-title" id="helpModalLabel"><i class="bi bi-question-circle-fill text-primary me-2"></i>Reports Help Guide</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="accordion" id="helpAccordion">
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="headingOne">
                                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOne" aria-expanded="true" aria-controls="collapseOne">
                                    <i class="bi bi-graph-up me-2"></i> Financial Report
                                </button>
                            </h2>
                            <div id="collapseOne" class="accordion-collapse collapse show" aria-labelledby="headingOne" data-bs-parent="#helpAccordion">
                                <div class="accordion-body">
                                    <div class="d-flex mb-3">
                                        <div class="flex-shrink-0">
                                            <span class="avatar-sm bg-primary bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center">
                                                <i class="bi bi-currency-exchange text-primary fs-4"></i>
                                            </span>
                                        </div>
                                        <div class="flex-grow-1 ms-3">
                                            <h5 class="mb-1">Financial Overview</h5>
                                            <p class="mb-0 text-muted">Track revenue and financial performance metrics</p>
                                        </div>
                                    </div>
                                    <p>The financial report provides an overview of all revenue generated through the system, including:</p>
                                    <ul class="list-group list-group-flush mb-3">
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <span><i class="bi bi-currency-dollar me-2"></i>Total Revenue</span>
                                            <span class="badge bg-primary">Sum of all successful payments</span>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <span><i class="bi bi-percent me-2"></i>Total Fees</span>
                                            <span class="badge bg-primary">System fees collected</span>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <span><i class="bi bi-ticket-perforated me-2"></i>Total Bookings</span>
                                            <span class="badge bg-primary">Number of completed bookings</span>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <span><i class="bi bi-calculator me-2"></i>Average Booking Value</span>
                                            <span class="badge bg-primary">Mean revenue per booking</span>
                                        </li>
                                    </ul>
                                    <div class="alert alert-info bg-light border-0">
                                        <i class="bi bi-lightbulb"></i> The chart shows daily revenue trends for the selected period.
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="headingTwo">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTwo" aria-expanded="false" aria-controls="collapseTwo">
                                    <i class="bi bi-ticket-perforated me-2"></i> Booking Statistics
                                </button>
                            </h2>
                            <div id="collapseTwo" class="accordion-collapse collapse" aria-labelledby="headingTwo" data-bs-parent="#helpAccordion">
                                <div class="accordion-body">
                                    <div class="d-flex mb-3">
                                        <div class="flex-shrink-0">
                                            <span class="avatar-sm bg-success bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center">
                                                <i class="bi bi-ticket-detailed text-success fs-4"></i>
                                            </span>
                                        </div>
                                        <div class="flex-grow-1 ms-3">
                                            <h5 class="mb-1">Booking Analytics</h5>
                                            <p class="mb-0 text-muted">Monitor booking activity and performance</p>
                                        </div>
                                    </div>
                                    <p>The booking statistics report shows key metrics about booking activity:</p>
                                    <ul class="list-group list-group-flush mb-3">
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <span><i class="bi bi-ticket-perforated me-2"></i>Total Bookings</span>
                                            <span class="badge bg-success">All bookings created</span>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <span><i class="bi bi-check-circle me-2"></i>Completed Bookings</span>
                                            <span class="badge bg-success">Successfully fulfilled</span>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <span><i class="bi bi-x-circle me-2"></i>Cancelled Bookings</span>
                                            <span class="badge bg-success">Bookings cancelled</span>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <span><i class="bi bi-calculator me-2"></i>Average Booking Amount</span>
                                            <span class="badge bg-success">Mean value of bookings</span>
                                        </li>
                                    </ul>
                                    <div class="alert alert-info bg-light border-0">
                                        <i class="bi bi-lightbulb"></i> The pie chart visualizes the distribution of booking statuses.
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="headingThree">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseThree" aria-expanded="false" aria-controls="collapseThree">
                                    <i class="bi bi-building me-2"></i> Top Operators
                                </button>
                            </h2>
                            <div id="collapseThree" class="accordion-collapse collapse" aria-labelledby="headingThree" data-bs-parent="#helpAccordion">
                                <div class="accordion-body">
                                    <div class="d-flex mb-3">
                                        <div class="flex-shrink-0">
                                            <span class="avatar-sm bg-warning bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center">
                                                <i class="bi bi-trophy text-warning fs-4"></i>
                                            </span>
                                        </div>
                                        <div class="flex-grow-1 ms-3">
                                            <h5 class="mb-1">Operator Performance</h5>
                                            <p class="mb-0 text-muted">Compare operator contributions</p>
                                        </div>
                                    </div>
                                    <p>This report ranks operators by revenue generated:</p>
                                    <ul class="list-group list-group-flush mb-3">
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <span><i class="bi bi-building me-2"></i>Operator</span>
                                            <span class="badge bg-warning">Company name</span>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <span><i class="bi bi-ticket-perforated me-2"></i>Bookings</span>
                                            <span class="badge bg-warning">Number of bookings</span>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <span><i class="bi bi-currency-dollar me-2"></i>Revenue</span>
                                            <span class="badge bg-warning">Total revenue generated</span>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <span><i class="bi bi-pie-chart me-2"></i>Revenue Share</span>
                                            <span class="badge bg-warning">Percentage of total revenue</span>
                                        </li>
                                    </ul>
                                    <div class="alert alert-info bg-light border-0">
                                        <i class="bi bi-lightbulb"></i> Only shows top 10 operators for the selected period.
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="headingFour">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseFour" aria-expanded="false" aria-controls="collapseFour">
                                    <i class="bi bi-speedometer2 me-2"></i> System Overview
                                </button>
                            </h2>
                            <div id="collapseFour" class="accordion-collapse collapse" aria-labelledby="headingFour" data-bs-parent="#helpAccordion">
                                <div class="accordion-body">
                                    <div class="d-flex mb-3">
                                        <div class="flex-shrink-0">
                                            <span class="avatar-sm bg-info bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center">
                                                <i class="bi bi-heart-pulse text-info fs-4"></i>
                                            </span>
                                        </div>
                                        <div class="flex-grow-1 ms-3">
                                            <h5 class="mb-1">System Health</h5>
                                            <p class="mb-0 text-muted">Monitor platform performance</p>
                                        </div>
                                    </div>
                                    <p>The system overview provides a snapshot of platform health:</p>
                                    <ul class="list-group list-group-flush mb-3">
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <span><i class="bi bi-people me-2"></i>User Metrics</span>
                                            <span class="badge bg-info">Total and verified users</span>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <span><i class="bi bi-buildings me-2"></i>Operator Metrics</span>
                                            <span class="badge bg-info">Total and approved operators</span>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <span><i class="bi bi-bus-front me-2"></i>Buses</span>
                                            <span class="badge bg-info">Total buses in system</span>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <span><i class="bi bi-ticket-perforated me-2"></i>Bookings</span>
                                            <span class="badge bg-info">All-time booking count</span>
                                        </li>
                                    </ul>
                                    <div class="alert alert-info bg-light border-0">
                                        <i class="bi bi-lightbulb"></i> This report shows current counts (not filtered by date range).
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Got it!</button>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Initialize charts based on report type
        <?php if ($report_type == 'financial' && !empty($chart_labels)): ?>
            const revenueCtx = document.getElementById('revenueChart').getContext('2d');
            const revenueChart = new Chart(revenueCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($chart_labels); ?>,
                    datasets: [{
                        label: 'Daily Revenue',
                        data: <?php echo json_encode($chart_values); ?>,
                        backgroundColor: 'rgba(78, 115, 223, 0.7)',
                        borderColor: 'rgba(78, 115, 223, 1)',
                        borderWidth: 1,
                        borderRadius: 6,
                        hoverBackgroundColor: 'rgba(78, 115, 223, 0.9)',
                        hoverBorderColor: 'rgba(78, 115, 223, 1)',
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
                                    return 'Revenue: LKR ' + context.parsed.y.toLocaleString('en-US', {
                                        minimumFractionDigits: 2,
                                        maximumFractionDigits: 2
                                    });
                                }
                            }
                        },
                        datalabels: {
                            display: false
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
            
        <?php elseif ($report_type == 'bookings' && !empty($chart_labels)): ?>
            const bookingsCtx = document.getElementById('bookingsChart').getContext('2d');
            const bookingsChart = new Chart(bookingsCtx, {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode($chart_labels); ?>,
                    datasets: [{
                        data: <?php echo json_encode($chart_values); ?>,
                        backgroundColor: [
                            'rgba(28, 200, 138, 0.7)',
                            'rgba(231, 74, 59, 0.7)',
                            'rgba(246, 194, 62, 0.7)',
                            'rgba(54, 162, 235, 0.7)'
                        ],
                        borderColor: [
                            'rgba(28, 200, 138, 1)',
                            'rgba(231, 74, 59, 1)',
                            'rgba(246, 194, 62, 1)',
                            'rgba(54, 162, 235, 1)'
                        ],
                        borderWidth: 1,
                        hoverBackgroundColor: [
                            'rgba(28, 200, 138, 0.9)',
                            'rgba(231, 74, 59, 0.9)',
                            'rgba(246, 194, 62, 0.9)',
                            'rgba(54, 162, 235, 0.9)'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                usePointStyle: true,
                                padding: 20,
                                font: {
                                    size: 12
                                }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = Math.round((value / total) * 100);
                                    return `${label}: ${value} (${percentage}%)`;
                                }
                            }
                        },
                        datalabels: {
                            formatter: (value, ctx) => {
                                const datapoints = ctx.chart.data.datasets[0].data;
                                const total = datapoints.reduce((total, datapoint) => total + datapoint, 0);
                                const percentage = (value / total * 100).toFixed(1);
                                return percentage + '%';
                            },
                            color: '#fff',
                            font: {
                                weight: 'bold'
                            }
                        }
                    },
                    cutout: '70%',
                    animation: {
                        animateScale: true,
                        animateRotate: true
                    }
                },
                plugins: [ChartDataLabels]
            });
        <?php endif; ?>

        // Add this JavaScript
document.querySelectorAll('.export-btn').forEach(btn => {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        
        // Show loading state
        const originalHtml = this.innerHTML;
        this.innerHTML = `<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Exporting ${this.dataset.type.toUpperCase()}...`;
        this.disabled = true;
        
        // Make the export request
        fetch(this.href)
            .then(response => {
                if (!response.ok) throw new Error('Export failed');
                return response.blob();
            })
            .then(blob => {
                // Create download link
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `report_${new Date().toISOString().slice(0,10)}.${this.dataset.type}`;
                document.body.appendChild(a);
                a.click();
                window.URL.revokeObjectURL(url);
                a.remove();
            })
            .catch(error => {
                console.error('Export error:', error);
                alert('Export failed. Please try again.');
            })
            .finally(() => {
                // Restore button state
                this.innerHTML = originalHtml;
                this.disabled = false;
            });
    });
});
        
        // Animation on scroll
        document.addEventListener('DOMContentLoaded', function() {
            const animateElements = document.querySelectorAll('.animate-on-scroll');
            
            const animateOnScroll = function() {
                animateElements.forEach(element => {
                    const elementPosition = element.getBoundingClientRect().top;
                    const windowHeight = window.innerHeight;
                    
                    if (elementPosition < windowHeight - 100) {
                        element.classList.add('fade-in');
                    }
                });
            };
            
            // Initial check
            animateOnScroll();
            
            // Check on scroll
            window.addEventListener('scroll', animateOnScroll);
            
            // Form submission with loading indicator
            const reportForm = document.getElementById('reportForm');
            const generateBtn = document.getElementById('generateBtn');
            const generateText = document.getElementById('generateText');
            const generateSpinner = document.getElementById('generateSpinner');
            const loadingSpinner = document.getElementById('loadingSpinner');
            const reportCard = document.getElementById('reportCard');
            
            reportForm.addEventListener('submit', function() {
                generateText.textContent = 'Generating...';
                generateSpinner.classList.remove('d-none');
                generateBtn.disabled = true;
                loadingSpinner.style.display = 'flex';
                reportCard.style.opacity = '0.5';
            });
            
            // Print report
            document.getElementById('printReportBtn').addEventListener('click', function() {
    // Hide elements that shouldn't be printed
    const noPrintElements = document.querySelectorAll('.no-print');
    noPrintElements.forEach(el => el.style.display = 'none');
    
    window.print();
    
    // Restore elements after printing
    setTimeout(() => {
        noPrintElements.forEach(el => el.style.display = '');
    }, 500);
});
            
            // Refresh report
            document.getElementById('refreshReport').addEventListener('click', function(e) {
                e.preventDefault();
                reportForm.submit();
            });
            
            // Export buttons
            document.getElementById('exportCSV').addEventListener('click', function(e) {
                e.preventDefault();
                window.location.href = `export-report.php?type=<?php echo $report_type; ?>&start=<?php echo $start_date; ?>&end=<?php echo $end_date; ?>&format=csv`;
            });
            
            document.getElementById('exportPDF').addEventListener('click', function(e) {
                e.preventDefault();
                window.location.href = `export-report.php?type=<?php echo $report_type; ?>&start=<?php echo $start_date; ?>&end=<?php echo $end_date; ?>&format=pdf`;
            });
            
            // Set max date for end date
            const startDateInput = document.getElementById('start_date');
            const endDateInput = document.getElementById('end_date');
            
            startDateInput.addEventListener('change', function() {
                endDateInput.min = this.value;
                if (new Date(endDateInput.value) < new Date(this.value)) {
                    endDateInput.value = this.value;
                }
            });
            
            endDateInput.addEventListener('change', function() {
                if (new Date(this.value) < new Date(startDateInput.value)) {
                    this.value = startDateInput.value;
                }
            });
            
            // Initialize tooltips
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        });
    </script>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
</body>
</html>