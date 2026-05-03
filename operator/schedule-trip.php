<?php
session_start();
require_once '../includes/config.php';

// Database connection with singleton pattern
function getDatabaseConnection() {
    static $db = null;
    if ($db === null) {
        try {
            $db = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            die("Database connection failed. Please try again later.");
        }
    }
    return $db;
}

// Authentication with CSRF protection
function authenticateUser($requiredType) {
    if (!isset($_SESSION['user_id'], $_SESSION['csrf_token'])) {
        return false;
    }

    $db = getDatabaseConnection();
    $stmt = $db->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    return ($user && $user['user_type'] === $requiredType) ? $user : false;
}

// Get operator details
function getOperatorByUserId($userId) {
    $db = getDatabaseConnection();
    $stmt = $db->prepare("SELECT * FROM operators WHERE user_id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetch();
}

/**
 * Calculates arrival time based on departure time and route duration
 */
function calculateArrivalTime($departureTime, $routeId) {
    try {
        $db = getDatabaseConnection();
        
        // Get route duration from database
        $stmt = $db->prepare("SELECT estimated_duration_min FROM routes WHERE route_id = ?");
        $stmt->execute([$routeId]);
        $route = $stmt->fetch();
        
        if (!$route || empty($route['estimated_duration_min'])) {
            error_log("Failed to get duration for route ID: $routeId");
            return false;
        }
        
        $durationMinutes = (int)$route['estimated_duration_min'];
        $departure = new DateTime($departureTime);
        $departure->add(new DateInterval('PT' . $durationMinutes . 'M'));
        
        return $departure->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        error_log("Error calculating arrival time: " . $e->getMessage());
        return false;
    }
}

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Main logic
$user = authenticateUser('operator');
if (!$user) {
    header('Location: /login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$operator = getOperatorByUserId($user['user_id']);
if (!$operator) {
    die('Operator account not properly configured');
}

// Get all active routes
$routes = [];
try {
    $db = getDatabaseConnection();
    $stmt = $db->prepare("SELECT * FROM routes WHERE is_active = 1 ORDER BY origin_city, destination_city");
    $stmt->execute();
    $routes = $stmt->fetchAll();
} catch (PDOException $e) {
    $errorMessage = 'Failed to load routes: ' . $e->getMessage();
    error_log($errorMessage);
}

// Get operator's active buses
$buses = [];
try {
    $db = getDatabaseConnection();
    $stmt = $db->prepare("
        SELECT b.*, bt.type_name 
        FROM buses b
        JOIN bus_types bt ON b.type_id = bt.type_id
        WHERE b.operator_id = ? AND b.status = 'active'
        ORDER BY b.name
    ");
    $stmt->execute([$operator['operator_id']]);
    $buses = $stmt->fetchAll();
} catch (PDOException $e) {
    $errorMessage = 'Failed to load buses: ' . $e->getMessage();
    error_log($errorMessage);
}

// Get drivers
$drivers = [];
try {
    $db = getDatabaseConnection();
    $stmt = $db->prepare("
        SELECT * FROM drivers 
        WHERE operator_id = ? AND is_active = 1
        ORDER BY first_name, last_name
    ");
    $stmt->execute([$operator['operator_id']]);
    $drivers = $stmt->fetchAll();
} catch (PDOException $e) {
    $errorMessage = 'Failed to load drivers: ' . $e->getMessage();
    error_log($errorMessage);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Verify CSRF token
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception("Invalid request");
        }

        $db = getDatabaseConnection();

        // Handle route addition
        if (isset($_POST['add_route'])) {
            // Validate required fields
            $required = ['origin_city', 'origin_terminal', 'destination_city', 'destination_terminal'];
            foreach ($required as $field) {
                if (empty($_POST[$field])) {
                    throw new Exception("Please fill in all required fields");
                }
            }

            // Prepare data
            $originCity = trim($_POST['origin_city']);
            $originTerminal = trim($_POST['origin_terminal']);
            $destinationCity = trim($_POST['destination_city']);
            $destinationTerminal = trim($_POST['destination_terminal']);
            $distanceKm = !empty($_POST['distance_km']) ? (float)$_POST['distance_km'] : null;
            $durationMin = !empty($_POST['estimated_duration_min']) ? (int)$_POST['estimated_duration_min'] : null;
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            // Insert new route
            $stmt = $db->prepare("
                INSERT INTO routes (
                    origin_city, origin_terminal, destination_city, destination_terminal,
                    distance_km, estimated_duration_min, is_active, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $originCity,
                $originTerminal,
                $destinationCity,
                $destinationTerminal,
                $distanceKm,
                $durationMin,
                $isActive
            ]);

            // If this is an AJAX request, return success
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                echo json_encode(['success' => true]);
                exit;
            }

            // Refresh routes list for regular form submission
            $stmt = $db->prepare("SELECT * FROM routes WHERE is_active = 1 ORDER BY origin_city, destination_city");
            $stmt->execute();
            $routes = $stmt->fetchAll();

            $successMessage = "Route added successfully!";
        }
        
        // Check if this is a driver add request
        if (isset($_POST['add_driver'])) {
            $firstName = trim($_POST['driver_first_name']);
            $lastName = trim($_POST['driver_last_name']);
            $license = trim($_POST['driver_license']);
            $contact = trim($_POST['driver_contact']);
            
            if (empty($firstName) || empty($lastName) || empty($license)) {
                throw new Exception("First name, last name and license are required");
            }
            
            $stmt = $db->prepare("
                INSERT INTO drivers 
                (operator_id, first_name, last_name, license_number, phone_number, is_active, created_at)
                VALUES (?, ?, ?, ?, ?, 1, NOW())
            ");
            $stmt->execute([
                $operator['operator_id'],
                $firstName,
                $lastName,
                $license,
                $contact
            ]);
            
            // Refresh drivers list
            $stmt = $db->prepare("
                SELECT * FROM drivers 
                WHERE operator_id = ? AND is_active = 1
                ORDER BY first_name, last_name
            ");
            $stmt->execute([$operator['operator_id']]);
            $drivers = $stmt->fetchAll();
            
            $successMessage = "Driver added successfully!";
        } else {
            // Handle trip scheduling
            $routeId = (int)$_POST['route_id'];
            $busId = (int)$_POST['bus_id'];
            $driverId = !empty($_POST['driver_id']) ? (int)$_POST['driver_id'] : null;
            $departureTime = $_POST['departure_time'];
            $price = (float)$_POST['price'];
            $recurring = isset($_POST['is_recurring']) ? 1 : 0;
            $daysOfWeek = isset($_POST['days_of_week']) ? implode(',', $_POST['days_of_week']) : '';

            // Calculate arrival time
            $arrivalTime = calculateArrivalTime($departureTime, $routeId);
            if (!$arrivalTime) {
                throw new Exception("Failed to calculate arrival time. Please check route details.");
            }

            // Validate inputs
            if (empty($routeId) || empty($busId) || empty($departureTime)) {
                throw new Exception("All required fields must be filled");
            }

            if (new DateTime($departureTime) >= new DateTime($arrivalTime)) {
                throw new Exception("Arrival time must be after departure time");
            }
            
            // Check if bus is available
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM schedules 
                WHERE bus_id = ? 
                AND (
                    (departure_time < ? AND arrival_time > ?)
                )
            ");
            $stmt->execute([$busId, $arrivalTime, $departureTime]);
            
            if ($stmt->fetchColumn() > 0) {
                throw new Exception("Selected bus is already scheduled for another trip during this time");
            }
            
            // Check if driver is available (if provided)
            if ($driverId) {
                $stmt = $db->prepare("
                    SELECT COUNT(*) FROM schedules 
                    WHERE driver_id = ? 
                    AND (
                        (departure_time < ? AND arrival_time > ?)
                    )
                ");
                $stmt->execute([$driverId, $arrivalTime, $departureTime]);
                
                if ($stmt->fetchColumn() > 0) {
                    throw new Exception("Selected driver is already scheduled for another trip during this time");
                }
            }
            
            // Get bus capacity
            $stmt = $db->prepare("SELECT seat_capacity FROM buses WHERE bus_id = ?");
            $stmt->execute([$busId]);
            $capacity = $stmt->fetchColumn();
            
            if (!$capacity) {
                throw new Exception("Invalid bus selected");
            }
            
            // Insert new schedule
            $stmt = $db->prepare("
                INSERT INTO schedules (
                    route_id, bus_id, driver_id, departure_time, 
                    arrival_time, price, is_recurring, days_of_week, 
                    operator_id, available_seats, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $routeId, 
                $busId, 
                $driverId, 
                $departureTime,
                $arrivalTime, 
                $price, 
                $recurring, 
                $daysOfWeek,
                $operator['operator_id'],
                $capacity
            ]);
            
            $successMessage = "Trip scheduled successfully!";
        }
        
    } catch (Exception $e) {
        // If this is an AJAX request, return error
        if (isset($_POST['add_route']) && !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }
        
        $errorMessage = $e->getMessage();
        error_log("Scheduling error: " . $errorMessage);
    }
}

// Get upcoming trips for display
$upcomingTrips = [];
$pastTrips = [];
try {
    $db = getDatabaseConnection();
    
    // Upcoming trips
    $stmt = $db->prepare("
        SELECT s.*, 
               r.origin_city, r.destination_city, r.origin_terminal, r.destination_terminal,
               b.name as bus_name, b.registration_number,
               CONCAT(d.first_name, ' ', d.last_name) as driver_name,
               (SELECT COUNT(*) FROM bookings WHERE schedule_id = s.schedule_id) as bookings_count
        FROM schedules s
        JOIN routes r ON s.route_id = r.route_id
        JOIN buses b ON s.bus_id = b.bus_id
        LEFT JOIN drivers d ON s.driver_id = d.driver_id
        WHERE s.operator_id = ? AND s.departure_time > NOW()
        ORDER BY s.departure_time ASC
        LIMIT 50
    ");
    $stmt->execute([$operator['operator_id']]);
    $upcomingTrips = $stmt->fetchAll();
    
    // Past trips
    $stmt = $db->prepare("
        SELECT s.*, 
               r.origin_city, r.destination_city, r.origin_terminal, r.destination_terminal,
               b.name as bus_name, b.registration_number,
               CONCAT(d.first_name, ' ', d.last_name) as driver_name,
               (SELECT COUNT(*) FROM bookings WHERE schedule_id = s.schedule_id) as bookings_count,
               (SELECT COUNT(*) FROM bookings WHERE schedule_id = s.schedule_id AND status = 'completed') as completed_count
        FROM schedules s
        JOIN routes r ON s.route_id = r.route_id
        JOIN buses b ON s.bus_id = b.bus_id
        LEFT JOIN drivers d ON s.driver_id = d.driver_id
        WHERE s.operator_id = ? AND s.departure_time <= NOW()
        ORDER BY s.departure_time DESC
        LIMIT 50
    ");
    $stmt->execute([$operator['operator_id']]);
    $pastTrips = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Failed to load trips: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedule New Trip | SmartBusLink</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #4e73df;
            --primary-hover: #3a5bc7;
            --secondary-color: #858796;
            --success-color: #1cc88a;
            --info-color: #36b9cc;
            --warning-color:rgb(246, 111, 62);
            --danger-color: #e74a3b;
            --light-color: #f8f9fc;
            --dark-color: #5a5c69;
            --border-radius: 0.75rem;
            --box-shadow: 0 0.5rem 1.5rem rgba(0, 0, 0, 0.08);
            --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        }
        
        body {
            background-color: #f5f7fa;
            font-family: 'Nunito', sans-serif;
            color: #4a4a4a;
        }
        
        .card {
            border: none;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            transition: var(--transition);
        }
        
        .card:hover {
            box-shadow: 0 0.75rem 2rem rgba(0, 0, 0, 0.12);
            transform: translateY(-3px);
        }
        
        .form-section {
            background-color: white;
            border-radius: var(--border-radius);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--box-shadow);
            transition: var(--transition);
        }
        
        .form-section:hover {
            box-shadow: 0 0.75rem 2rem rgba(0, 0, 0, 0.12);
        }
        
        .day-checkbox {
            position: relative;
            display: inline-block;
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
        }
        
        .day-checkbox input {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .day-checkbox label {
            display: inline-block;
            padding: 0.5rem 1rem;
            border: 1px solid #dee2e6;
            border-radius: 0.5rem;
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.85rem;
        }
        
        .day-checkbox input:checked + label {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
            transform: scale(1.05);
        }
        
        .day-checkbox label:hover {
            background-color: #f8f9fa;
        }
        
        .route-card {
            cursor: pointer;
            transition: var(--transition);
            margin-bottom: 1rem;
            border-left: 4px solid transparent;
        }
        
        .route-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.75rem 2rem rgba(0, 0, 0, 0.12);
        }
        
        .route-card.selected {
            border-left: 4px solid var(--primary-color);
            background-color: rgba(78, 115, 223, 0.05);
        }
        
        .route-details {
            font-size: 0.9rem;
            color: var(--secondary-color);
        }
        
        .route-details span {
            margin-right: 0.75rem;
            display: inline-flex;
            align-items: center;
        }
        
        .route-details i {
            margin-right: 0.3rem;
            font-size: 1rem;
        }
        
        .select2-container--default .select2-selection--single {
            height: 46px;
            padding: 10px 16px;
            border: 1px solid #ced4da;
            border-radius: var(--border-radius);
            transition: var(--transition);
        }
        
        .select2-container--default .select2-selection--single:hover {
            border-color: var(--primary-color);
        }
        
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 44px;
        }
        
        .time-estimate {
            font-size: 0.85rem;
            color: #6c757d;
            margin-top: 0.25rem;
            font-style: italic;
        }
        
        .btn {
            border-radius: 0.5rem;
            padding: 0.5rem 1.25rem;
            transition: var(--transition);
            font-weight: 500;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-primary:hover {
            background-color: var(--primary-hover);
            border-color: var(--primary-hover);
            transform: translateY(-2px);
        }
        
        .btn-outline-primary:hover {
            transform: translateY(-2px);
        }
        
        .btn-lg {
            padding: 0.75rem 1.5rem;
            font-size: 1.1rem;
        }
        
        .form-control, .form-select {
            border-radius: 0.5rem;
            padding: 0.5rem 1rem;
            transition: var(--transition);
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(78, 115, 223, 0.25);
        }
        
        .trip-schedule {
    font-family: 'Segoe UI', sans-serif;
    max-width: 800px;
    margin: 0 auto;
    padding: 20px;
}

.trip-schedule h1 {
    color: #333;
    margin-bottom: 20px;
    font-weight: 600;
}

.nav-tabs {
    border-bottom: 5px solid #dee2e6;
    margin-bottom: 20px;
}

.nav-tabs .nav-link {
    border: none;
    font-weight: 500;
    padding: 0.75rem 1.5rem;
    transition: all 0.3s ease;
    border-radius: 0;
    color: white;
    background: linear-gradient(45deg,rgb(0, 3, 179), #0085e6);
    margin-right: 5px;
    position: relative;
    overflow: hidden;
}

.nav-tabs .nav-link:hover {
    /* Optionally add a slight brightness or shadow on hover */
    filter: brightness(1.1);
    color: white;
}

.nav-tabs .nav-link.active {
    background: linear-gradient(45deg,rgb(0, 3, 179), #0085e6);
    font-weight: 600;
    box-shadow: 0 4px 12px rgba(0, 123, 255, 0.3);
}

/* Optional: Add a subtle animation to the active tab */
.nav-tabs .nav-link.active::after {
    content: '';
    position: absolute;
    bottom: -2px;
    left: 0;
    width: 100%;
    height: 3px;
    background: white;
    animation: slideIn 0.3s ease-out;
}

@keyframes slideIn {
    from { width: 0; }
    to { width: 100%; }
}
        
        .tab-content {
            background-color: white;
            border-radius: 0 0 var(--border-radius) var(--border-radius);
            box-shadow: var(--box-shadow);
        }
        
        .table {
            --bs-table-hover-bg: rgba(78, 115, 223, 0.05);
        }
        
        .table-hover tbody tr {
            transition: var(--transition);
        }
        
        .table-hover tbody tr:hover {
            transform: translateX(5px);
        }
        
        .badge {
            font-weight: 500;
            padding: 0.35em 0.65em;
            border-radius: 0.5rem;
        }
        
        .modal-content {
            border-radius: var(--border-radius);
            border: none;
            box-shadow: 0 1rem 3rem rgba(0, 0, 0, 0.15);
        }
        
        .modal-header {
            border-bottom: none;
            padding-bottom: 0;
        }
        
        .modal-footer {
            border-top: none;
        }
        
        .input-group-text {
            border-radius: 0.5rem 0 0 0.5rem !important;
        }
        
        .animate__animated {
            --animate-duration: 0.5s;
        }
        
        @media (max-width: 768px) {
            .form-section {
                padding: 1.5rem;
            }
            
            .nav-tabs .nav-link {
                padding: 0.5rem 1rem;
                font-size: 0.9rem;
            }
        }
        
        /* Custom animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .fade-in-up {
            animation: fadeInUp 0.6s ease-out forwards;
        }
        
        .delay-1 {
            animation-delay: 0.1s;
        }
        
        .delay-2 {
            animation-delay: 0.2s;
        }
        
        .delay-3 {
            animation-delay: 0.3s;
        }
        
        /* Floating animation */
        @keyframes floating {
            0% { transform: translateY(0px); }
            50% { transform: translateY(-8px); }
            100% { transform: translateY(0px); }
        }
        
        .floating {
            animation: floating 3s ease-in-out infinite;
        }
        
        /* Pulse animation */
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        .pulse {
            animation: pulse 2s infinite;
        }
        
        /* Gradient text */
        .gradient-text {
            background: linear-gradient(45deg, var(--primary-color), var(--info-color));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
    </style>
</head>
<body>
    <div class="container py-4 animate__animated animate__fadeIn">
        <?php include 'header.php'; ?>
        
        <div class="d-flex justify-content-between align-items-center mb-4 fade-in-up">
            <h1 class="h3 mb-0 text-gray-800">Schedule New Trip</h1>
            <div class="floating">
                <i class="bi bi-bus-front fs-1 text-primary"></i>
            </div>
        </div>
        
        <?php if (isset($errorMessage)): ?>
            <div class="alert alert-danger alert-dismissible fade show animate__animated animate__shakeX" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <?= htmlspecialchars($errorMessage) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($successMessage)): ?>
            <div class="alert alert-success alert-dismissible fade show animate__animated animate__bounceIn" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i>
                <?= htmlspecialchars($successMessage) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <form method="post" class="needs-validation" novalidate>
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            
            <div class="row">
                <div class="col-lg-8">
                    <div class="form-section fade-in-up delay-1">
                        <h4 class="mb-4 gradient-text"><i class="bi bi-geo-alt me-2"></i>Route Information</h4>
                        
                        <div class="mb-4">
                            <label for="route_id" class="form-label fw-bold">Select Route</label>
                            <div class="input-group">
                                <select class="form-select select2-route" id="route_id" name="route_id" required>
                                    <option value="">-- Select a route --</option>
                                    <?php foreach ($routes as $route): ?>
                                        <option value="<?= $route['route_id'] ?>" 
                                            data-origin-city="<?= htmlspecialchars($route['origin_city']) ?>"
                                            data-origin-terminal="<?= htmlspecialchars($route['origin_terminal']) ?>"
                                            data-destination-city="<?= htmlspecialchars($route['destination_city']) ?>"
                                            data-destination-terminal="<?= htmlspecialchars($route['destination_terminal']) ?>"
                                            data-distance="<?= $route['distance_km'] ?>"
                                            data-duration="<?= $route['estimated_duration_min'] ?>">
                                            <?= htmlspecialchars($route['origin_city']) ?> (<?= htmlspecialchars($route['origin_terminal']) ?>) 
                                            → 
                                            <?= htmlspecialchars($route['destination_city']) ?> (<?= htmlspecialchars($route['destination_terminal']) ?>)
                                            <?php if ($route['distance_km']): ?>
                                                - <?= $route['distance_km'] ?> km
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="btn btn-outline-primary pulse" type="button" data-bs-toggle="modal" data-bs-target="#addRouteModal">
                                    <i class="bi bi-plus-lg me-1"></i> Add New
                                </button>
                            </div>
                            <div class="invalid-feedback">
                                Please select a route
                            </div>
                        </div>
                        
                        <div id="routeDetails" class="card route-card p-3 mb-3 animate__animated animate__fadeIn" style="display: none;">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <h5 id="routeTitle" class="mb-2"></h5>
                                    <div class="route-details">
                                        <span><i class="bi bi-arrow-right-circle"></i> <span id="routeDistance"></span> km</span>
                                        <span><i class="bi bi-clock"></i> <span id="routeDuration"></span> min</span>
                                        <span><i class="bi bi-geo-alt"></i> <span id="routeOriginTerminal"></span></span>
                                        <span><i class="bi bi-geo-alt-fill"></i> <span id="routeDestinationTerminal"></span></span>
                                    </div>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="changeRouteBtn">
                                    <i class="bi bi-pencil me-1"></i> Change
                                </button>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3 fade-in-up delay-2">
                                <label for="departure_time" class="form-label fw-bold">Departure Time</label>
                                <input type="datetime-local" class="form-control" id="departure_time" name="departure_time" required>
                                <div class="invalid-feedback">
                                    Please provide a departure time
                                </div>
                            </div>
                            <div class="col-md-6 mb-3 fade-in-up delay-2">
                                <label for="arrival_time" class="form-label fw-bold">Arrival Time</label>
                                <input type="datetime-local" class="form-control" id="arrival_time" name="arrival_time" required>
                                <div class="invalid-feedback">
                                    Please provide an arrival time
                                </div>
                                <div id="arrivalTimeEstimate" class="time-estimate" style="display: none;">
                                    Estimated arrival: <span id="estimatedArrivalTime"></span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3 fade-in-up delay-3">
                            <label class="form-label fw-bold">Recurring Trip</label>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="is_recurring" name="is_recurring">
                                <label class="form-check-label" for="is_recurring">This is a recurring trip</label>
                            </div>
                        </div>
                        
                        <div class="mb-3 animate__animated animate__fadeIn" id="daysOfWeekContainer" style="display: none;">
                            <label class="form-label fw-bold">Days of Week</label>
                            <div>
                                <?php 
                                $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                                foreach ($days as $day): ?>
                                    <div class="day-checkbox">
                                        <input type="checkbox" id="day_<?= strtolower($day) ?>" name="days_of_week[]" value="<?= substr($day, 0, 3) ?>">
                                        <label for="day_<?= strtolower($day) ?>"><?= $day ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section fade-in-up delay-3">
                        <h4 class="mb-4 gradient-text"><i class="bi bi-currency-exchange me-2"></i>Pricing</h4>
                        
                        <div class="mb-3">
                            <label for="price" class="form-label fw-bold">Ticket Price (LKR)</label>
                            <div class="input-group">
                                <span class="input-group-text bg-primary text-white">
                                 </i> LKR</span>
                                <input type="number" class="form-control" id="price" name="price" min="0" step="100" required>
                                <div class="invalid-feedback">
                                    Please provide a valid price
                                </div>
                            </div>
                            <small class="text-muted">Price in Sri Lankan Rupees</small>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-4">
                    <div class="form-section fade-in-up delay-2">
                        <h4 class="mb-4 gradient-text"><i class="bi bi-bus-front me-2"></i>Bus Assignment</h4>
                        
                        <div class="mb-3">
                            <label for="bus_id" class="form-label fw-bold">Select Bus</label>
                            <select class="form-select select2-bus" id="bus_id" name="bus_id" required>
                                <option value="">-- Select a bus --</option>
                                <?php foreach ($buses as $bus): ?>
                                    <option value="<?= $bus['bus_id'] ?>">
                                        <?= htmlspecialchars($bus['name']) ?> (<?= htmlspecialchars($bus['registration_number']) ?>)
                                        - <?= htmlspecialchars($bus['type_name']) ?>
                                        - <?= $bus['seat_capacity'] ?> seats
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">
                                Please select a bus
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="driver_id" class="form-label fw-bold">Select Driver (optional)</label>
                            <select class="form-select select2-driver" id="driver_id" name="driver_id">
                                <option value="">-- Select a driver --</option>
                                <?php foreach ($drivers as $driver): ?>
                                    <option value="<?= $driver['driver_id'] ?>">
                                        <?= htmlspecialchars($driver['first_name'] . ' ' . $driver['last_name']) ?>
                                        - License: <?= htmlspecialchars($driver['license_number']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="text-end mb-3">
                            <button type="button" class="btn btn-sm btn-outline-primary pulse" data-bs-toggle="modal" data-bs-target="#addDriverModal">
                                <i class="bi bi-plus-lg me-1"></i> Add New Driver
                            </button>
                        </div>
                    </div>
                    
                    <br><br><br><br><br><br><div class="d-grid mt-3 fade-in-up delay-3">
                        <button type="submit" class="btn btn-primary btn-lg shadow">
                            <i class="bi bi-calendar-plus me-2"></i>Schedule Trip
                        </button>
                    </div>
                </div>
            </div>
        </form>

        <div class="form-section mt-4 animate__animated animate__fadeIn">
            <h4 class="mb-4 gradient-text"><i class="bi bi-calendar-week me-2"></i>Trip Schedule</h4>
            
            <ul class="nav nav-tabs" id="scheduleTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="upcoming-tab" data-bs-toggle="tab" data-bs-target="#upcoming" type="button" role="tab">
                        <i class="bi bi-calendar-plus me-1"></i> Upcoming Trips
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="past-tab" data-bs-toggle="tab" data-bs-target="#past" type="button" role="tab">
                        <i class="bi bi-calendar-check me-1"></i> Past Trips
                    </button>
                </li>
            </ul>
            
            <div class="tab-content p-3 border border-top-0 rounded-bottom" id="scheduleTabsContent">
                <div class="tab-pane fade show active" id="upcoming" role="tabpanel">
                    <?php if (empty($upcomingTrips)): ?>
                        <div class="alert alert-info animate__animated animate__fadeIn">
                            <i class="bi bi-info-circle me-2"></i> No upcoming trips scheduled yet.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Route</th>
                                        <th>Departure</th>
                                        <th>Arrival</th>
                                        <th>Bus</th>
                                        <th>Driver</th>
                                        <th>Price</th>
                                        <th>Bookings</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($upcomingTrips as $trip): ?>
                                        <tr class="animate__animated animate__fadeIn">
                                            <td>
                                                <strong><?= htmlspecialchars($trip['origin_city']) ?> → <?= htmlspecialchars($trip['destination_city']) ?></strong><br>
                                                <small class="text-muted">
                                                    <?= htmlspecialchars($trip['origin_terminal']) ?> to <?= htmlspecialchars($trip['destination_terminal']) ?>
                                                </small>
                                            </td>
                                            <td>
                                                <?= date('M j, Y', strtotime($trip['departure_time'])) ?><br>
                                                <small class="text-muted"><?= date('g:i A', strtotime($trip['departure_time'])) ?></small>
                                                <?php if ($trip['is_recurring']): ?>
                                                    <br><span class="badge bg-info">Recurring</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?= date('M j, Y', strtotime($trip['arrival_time'])) ?><br>
                                                <small class="text-muted"><?= date('g:i A', strtotime($trip['arrival_time'])) ?></small>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars($trip['bus_name']) ?><br>
                                                <small class="text-muted"><?= htmlspecialchars($trip['registration_number']) ?></small>
                                            </td>
                                            <td>
                                                <?= $trip['driver_name'] ? htmlspecialchars($trip['driver_name']) : '<span class="text-muted">Not assigned</span>' ?>
                                            </td>
                                            <td>
                                                Rs. <?= number_format($trip['price'], 2) ?>
                                            </td>
                                            <td>
                                                <?= $trip['bookings_count'] ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <a href="edit-trip.php?id=<?= $trip['schedule_id'] ?>" class="btn btn-outline-primary" title="Edit">
                                                        <i class="bi bi-pencil"></i>
                                                    </a>
                                                    <button class="btn btn-outline-danger cancel-trip-btn" data-id="<?= $trip['schedule_id'] ?>" title="Cancel">
                                                        <i class="bi bi-x-circle"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="tab-pane fade" id="past" role="tabpanel">
                    <?php if (empty($pastTrips)): ?>
                        <div class="alert alert-info animate__animated animate__fadeIn">
                            <i class="bi bi-info-circle me-2"></i> No past trips found.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Route</th>
                                        <th>Departure</th>
                                        <th>Arrival</th>
                                        <th>Bus</th>
                                        <th>Driver</th>
                                        <th>Bookings</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pastTrips as $trip): ?>
                                        <tr class="animate__animated animate__fadeIn">
                                            <td>
                                                <strong><?= htmlspecialchars($trip['origin_city']) ?> → <?= htmlspecialchars($trip['destination_city']) ?></strong><br>
                                                <small class="text-muted">
                                                    <?= htmlspecialchars($trip['origin_terminal']) ?> to <?= htmlspecialchars($trip['destination_terminal']) ?>
                                                </small>
                                            </td>
                                            <td>
                                                <?= date('M j, Y', strtotime($trip['departure_time'])) ?><br>
                                                <small class="text-muted"><?= date('g:i A', strtotime($trip['departure_time'])) ?></small>
                                                <?php if ($trip['is_recurring']): ?>
                                                    <br><span class="badge bg-info">Recurring</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?= date('M j, Y', strtotime($trip['arrival_time'])) ?><br>
                                                <small class="text-muted"><?= date('g:i A', strtotime($trip['arrival_time'])) ?></small>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars($trip['bus_name']) ?><br>
                                                <small class="text-muted"><?= htmlspecialchars($trip['registration_number']) ?></small>
                                            </td>
                                            <td>
                                                <?= $trip['driver_name'] ? htmlspecialchars($trip['driver_name']) : '<span class="text-muted">Not assigned</span>' ?>
                                            </td>
                                            <td>
                                                <?= $trip['completed_count'] ?>/<?= $trip['bookings_count'] ?>
                                                <small class="text-muted">completed</small>
                                            </td>
                                            <td>
                                                <?php 
                                                $now = new DateTime();
                                                $arrivalTime = new DateTime($trip['arrival_time']);
                                                
                                                if ($arrivalTime > $now) {
                                                    echo '<span class="badge bg-warning">In Progress</span>';
                                                } else {
                                                    echo '<span class="badge bg-success">Completed</span>';
                                                }
                                                ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Route Modal -->
    <div class="modal fade" id="addRouteModal" tabindex="-1" aria-labelledby="addRouteModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="addRouteModalLabel"><i class="bi bi-plus-circle me-2"></i>Add New Route</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" id="addRouteForm">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="add_route" value="1">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="origin_city" class="form-label fw-bold">Origin City</label>
                            <input type="text" class="form-control" id="origin_city" name="origin_city" required>
                            <div class="invalid-feedback">
                                Please provide an origin city
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="origin_terminal" class="form-label fw-bold">Origin Terminal</label>
                            <input type="text" class="form-control" id="origin_terminal" name="origin_terminal" required>
                            <div class="invalid-feedback">
                                Please provide an origin terminal
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="destination_city" class="form-label fw-bold">Destination City</label>
                            <input type="text" class="form-control" id="destination_city" name="destination_city" required>
                            <div class="invalid-feedback">
                                Please provide a destination city
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="destination_terminal" class="form-label fw-bold">Destination Terminal</label>
                            <input type="text" class="form-control" id="destination_terminal" name="destination_terminal" required>
                            <div class="invalid-feedback">
                                Please provide a destination terminal
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="distance_km" class="form-label fw-bold">Distance (km)</label>
                                <input type="number" class="form-control" id="distance_km" name="distance_km" step="0.01" min="0">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="estimated_duration_min" class="form-label fw-bold">Duration (minutes)</label>
                                <input type="number" class="form-control" id="estimated_duration_min" name="estimated_duration_min" min="0">
                            </div>
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" checked>
                            <label class="form-check-label fw-bold" for="is_active">Active Route</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Route</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Driver Modal -->
    <div class="modal fade" id="addDriverModal" tabindex="-1" aria-labelledby="addDriverModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="addDriverModalLabel"><i class="bi bi-person-plus me-2"></i>Add New Driver</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" id="addDriverForm">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="add_driver" value="1">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="driver_first_name" class="form-label fw-bold">First Name</label>
                            <input type="text" class="form-control" id="driver_first_name" name="driver_first_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="driver_last_name" class="form-label fw-bold">Last Name</label>
                            <input type="text" class="form-control" id="driver_last_name" name="driver_last_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="driver_license" class="form-label fw-bold">License Number</label>
                            <input type="text" class="form-control" id="driver_license" name="driver_license" required>
                        </div>
                        <div class="mb-3">
                            <label for="driver_contact" class="form-label fw-bold">Contact Number</label>
                            <input type="tel" class="form-control" id="driver_contact" name="driver_contact">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Driver</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Cancel Trip Confirmation Modal -->
    <div class="modal fade" id="cancelTripModal" tabindex="-1" aria-labelledby="cancelTripModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="cancelTripModalLabel"><i class="bi bi-exclamation-triangle me-2"></i>Confirm Trip Cancellation</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to cancel this trip? This action cannot be undone.</p>
                    <p class="text-danger"><strong>Note:</strong> All bookings for this trip will be canceled and passengers will be notified.</p>
                    <input type="hidden" id="tripToCancelId">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmCancelBtn">Cancel Trip</button>
                </div>
            </div>
        </div>
    </div>
    <!-- JavaScript Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <script>
        $(document).ready(function() {
            // Initialize Select2
            $('.select2-route').select2({
                placeholder: "Search for a route...",
                allowClear: true
            });
            
            $('.select2-bus').select2({
                placeholder: "Select a bus...",
                allowClear: true
            });
            
            $('.select2-driver').select2({
                placeholder: "Select a driver...",
                allowClear: true
            });
            
            // Show route details when a route is selected
            $('#route_id').change(function() {
                const selectedOption = $(this).find('option:selected');
                if (selectedOption.val()) {
                    $('#routeTitle').text(selectedOption.text());
                    $('#routeDistance').text(selectedOption.data('distance') || 'N/A');
                    $('#routeDuration').text(selectedOption.data('duration') || 'N/A');
                    $('#routeOriginTerminal').text(selectedOption.data('origin-terminal'));
                    $('#routeDestinationTerminal').text(selectedOption.data('destination-terminal'));
                    $('#routeDetails').show();
                } else {
                    $('#routeDetails').hide();
                }
            });
            
            // Change route button
            $('#changeRouteBtn').click(function() {
                $('#routeDetails').hide();
                $('#route_id').val('').trigger('change');
            });
            
            // Calculate arrival time when departure time or route changes
            function calculateArrivalTime() {
                const routeId = $('#route_id').val();
                if (!routeId) return;
                
                const selectedOption = $('#route_id').find('option:selected');
                const durationMinutes = parseInt(selectedOption.data('duration')) || 180;
                const departure = new Date($('#departure_time').val());
                
                if (isNaN(departure.getTime())) return;
                
                const arrival = new Date(departure.getTime() + durationMinutes * 60000);
                
                // Format for datetime-local input
                const pad = num => num.toString().padStart(2, '0');
                const formattedArrival = `${arrival.getFullYear()}-${pad(arrival.getMonth()+1)}-${pad(arrival.getDate())}T${pad(arrival.getHours())}:${pad(arrival.getMinutes())}`;
                $('#arrival_time').val(formattedArrival);
                
                // Display human-readable estimate
                const options = { 
                    weekday: 'short', 
                    year: 'numeric', 
                    month: 'short', 
                    day: 'numeric',
                    hour: '2-digit', 
                    minute: '2-digit',
                    hour12: true
                };
                $('#estimatedArrivalTime').text(arrival.toLocaleDateString('en-US', options));
                $('#arrivalTimeEstimate').show();
            }

            // Calculate arrival when departure time changes
            $('#departure_time').change(calculateArrivalTime);
            
            // Calculate arrival when route changes
            $('#route_id').change(calculateArrivalTime);
            
            // Toggle days of week based on recurring checkbox
            $('#is_recurring').change(function() {
                $('#daysOfWeekContainer').toggle(this.checked);
            });
            
            // Form submission validation
            $('form').submit(function(e) {
                const departure = new Date($('#departure_time').val());
                const arrival = new Date($('#arrival_time').val());
                
                if (departure >= arrival) {
                    e.preventDefault();
                    $('#arrival_time').addClass('is-invalid');
                    $('#arrival_time').next('.invalid-feedback').text('Arrival time must be after departure time');
                    $('html, body').animate({
                        scrollTop: $('#arrival_time').offset().top - 100
                    }, 500);
                    return false;
                }
            });

            // Handle add route form submission
            $('#addRouteForm').submit(function(e) {
                e.preventDefault();
                
                // Validate required fields
                const requiredFields = ['origin_city', 'origin_terminal', 'destination_city', 'destination_terminal'];
                let isValid = true;
                
                requiredFields.forEach(field => {
                    const input = $(`#${field}`);
                    if (!input.val().trim()) {
                        input.addClass('is-invalid');
                        isValid = false;
                    } else {
                        input.removeClass('is-invalid');
                    }
                });
                
                if (!isValid) {
                    // Scroll to first invalid field
                    $('html, body').animate({
                        scrollTop: $('.is-invalid').first().offset().top - 100
                    }, 500);
                    return;
                }
                
                // Submit via AJAX
                $.ajax({
                    type: 'POST',
                    url: window.location.href,
                    data: $(this).serialize(),
                    success: function(response) {
                        // Close modal and refresh page to see new route
                        $('#addRouteModal').modal('hide');
                        location.reload();
                    },
                    error: function(xhr, status, error) {
                        // Show error message
                        alert('Error adding route: ' + (xhr.responseText || 'Please check your input and try again.'));
                    }
                });
            });

            // Remove invalid class when user starts typing in required fields
            const requiredFields = ['origin_city', 'origin_terminal', 'destination_city', 'destination_terminal'];
            requiredFields.forEach(field => {
                $(`#${field}`).on('input', function() {
                    if ($(this).val().trim()) {
                        $(this).removeClass('is-invalid');
                    }
                });
            });
        });
    </script>
</body>
</html>