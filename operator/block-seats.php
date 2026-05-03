<?php
session_start();
require_once '../includes/config.php';

// Check if operator is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

// Get operator details
$operator_id = $_SESSION['user_id'];
$stmt = $conn->prepare("
    SELECT o.operator_id, o.company_name, u.first_name, u.last_name 
    FROM operators o
    JOIN users u ON o.user_id = u.user_id
    WHERE o.user_id = ?
");
$stmt->bind_param("i", $operator_id);
$stmt->execute();
$result = $stmt->get_result();
$operator = $result->fetch_assoc();
$stmt->close();

if (!$operator) {
    die('Operator account not found or not properly configured');
}

// Handle seat blocking
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['block_seats'])) {
    $schedule_id = (int)$_POST['schedule_id'];
    $bus_id = (int)$_POST['bus_id'];
    $seat_numbers = array_map('trim', explode(',', $_POST['seat_numbers']));
    $reason = $conn->real_escape_string($_POST['reason'] ?? '');
    $block_until = !empty($_POST['block_until']) ? $_POST['block_until'] : null;
    
    $errors = [];
    $success_count = 0;
    
    foreach ($seat_numbers as $seat_number) {
        if (empty($seat_number)) continue;
        
        // Check if seat is already booked
        $stmt = $conn->prepare("
            SELECT 1 FROM bookings 
            WHERE schedule_id = ? AND seat_number = ? 
            AND booking_status NOT IN ('cancelled', 'failed')
        ");
        $stmt->bind_param("is", $schedule_id, $seat_number);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $errors[] = "Seat $seat_number is already booked";
            $stmt->close();
            continue;
        }
        $stmt->close();
        
        // Check if seat is already blocked
        $stmt = $conn->prepare("
            SELECT 1 FROM blocked_seats 
            WHERE schedule_id = ? AND seat_number = ? AND is_active = 1
            AND (blocked_until IS NULL OR blocked_until > NOW())
        ");
        $stmt->bind_param("is", $schedule_id, $seat_number);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $errors[] = "Seat $seat_number is already blocked";
            $stmt->close();
            continue;
        }
        $stmt->close();
        
        // Block the seat
        $stmt = $conn->prepare("
            INSERT INTO blocked_seats 
            (schedule_id, bus_id, seat_number, operator_id, reason, blocked_until)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("iisiss", 
            $schedule_id,
            $bus_id,
            $seat_number,
            $operator['operator_id'],
            $reason,
            $block_until
        );
        
        if ($stmt->execute()) {
            $success_count++;
        } else {
            $errors[] = "Failed to block seat $seat_number: " . $conn->error;
        }
        $stmt->close();
    }
    
    if ($success_count > 0) {
        $_SESSION['success'] = "Successfully blocked $success_count seat(s)";
    }
    if (!empty($errors)) {
        $_SESSION['error'] = implode("<br>", $errors);
    }
    
    header("Location: block-seats.php?schedule_id=$schedule_id");
    exit;
}

// Handle seat unblocking
if (isset($_GET['unblock'])) {
    $block_id = (int)$_GET['unblock'];
    $schedule_id = isset($_GET['schedule_id']) ? (int)$_GET['schedule_id'] : 0;
    
    // Verify the operator owns this block
    $stmt = $conn->prepare("
        UPDATE blocked_seats SET is_active = 0 
        WHERE block_id = ? AND operator_id = ?
    ");
    $stmt->bind_param("ii", $block_id, $operator['operator_id']);
    
    if ($stmt->execute()) {
        $_SESSION['success'] = "Seat unblocked successfully";
    } else {
        $_SESSION['error'] = "Error unblocking seat: " . $conn->error;
    }
    $stmt->close();
    
    header("Location: block-seats.php?schedule_id=$schedule_id");
    exit;
}

// Get schedules for this operator
$schedules = [];
$stmt = $conn->prepare("
    SELECT s.schedule_id, s.bus_id, s.departure_time, s.arrival_time,
           r.origin_city, r.destination_city, b.name as bus_name,
           b.capacity as total_seats
    FROM schedules s
    JOIN routes r ON s.route_id = r.route_id
    JOIN buses b ON s.bus_id = b.bus_id
    WHERE s.operator_id = ?
    ORDER BY s.departure_time DESC
");
$stmt->bind_param("i", $operator['operator_id']);
$stmt->execute();
$result = $stmt->get_result();
$schedules = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get current blocked seats for selected schedule
$schedule_id = isset($_GET['schedule_id']) ? (int)$_GET['schedule_id'] : 0;
$blocked_seats = [];
$schedule_details = null;

if ($schedule_id > 0) {
    // Get schedule details
    $stmt = $conn->prepare("
        SELECT s.*, r.origin_city, r.destination_city, b.name as bus_name,
               b.capacity as total_seats, b.registration_number
        FROM schedules s
        JOIN routes r ON s.route_id = r.route_id
        JOIN buses b ON s.bus_id = b.bus_id
        WHERE s.schedule_id = ?
    ");
    $stmt->bind_param("i", $schedule_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $schedule_details = $result->fetch_assoc();
    $stmt->close();
    
    // Get blocked seats
    $stmt = $conn->prepare("
        SELECT bs.*, u.first_name, u.last_name, o.company_name
        FROM blocked_seats bs
        JOIN operators o ON bs.operator_id = o.operator_id
        JOIN users u ON o.user_id = u.user_id
        WHERE bs.schedule_id = ? AND bs.is_active = 1
        AND (bs.blocked_until IS NULL OR bs.blocked_until > NOW())
        ORDER BY bs.blocked_at DESC
    ");
    $stmt->bind_param("i", $schedule_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $blocked_seats = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seat Blocking - SmartBusLink</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <!-- Animate.css -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <!-- Custom CSS -->
    <style>
        :root {
            --primary-color: #4361ee;
            --primary-light: #e0e7ff;
            --secondary-color: rgb(50, 68, 202);
            --success-color: #4cc9f0;
            --danger-color: #f72585;
            --danger-light: #fee2e2;
            --warning-color: #f8961e;
            --warning-light: #fef3c7;
            --info-color: #4895ef;
            --light-color: #f8f9fa;
            --dark-color: #212529;
            --gray-color: #6c757d;
            --border-radius: 0.5rem;
            --border-radius-sm: 0.25rem;
            --box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
            --box-shadow-sm: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            --transition: all 0.3s ease;
        }

        body {
            background-color: #f5f7fa;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            color: var(--dark-color);
            line-height: 1.6;
            padding-top: 70px;
        }

        .text-gradient {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .card {
            border: none;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow-sm);
            transition: var(--transition);
            overflow: hidden;
            margin-bottom: 1.5rem;
            background-color: white;
            display: flex;
            flex-direction: column;
            min-height: auto;
        }

        .card-body {
            padding: 1.5rem;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .card-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 1rem 1.25rem;
            border-bottom: none;
            font-weight: 600;
            flex-shrink: 0;
        }

        .card-header.bg-info {
            background: linear-gradient(135deg, var(--info-color), #3b82f6) !important;
        }

        .card:hover {
            transform: translateY(-3px);
            box-shadow: var(--box-shadow);
        }

        @media (max-width: 768px) {
            .card-header {
                padding: 0.75rem 1rem;
            }
            
            .card-body {
                padding: 1rem;
            }
        }

        .form-card .card-body {
            padding-bottom: 0;
        }

        .form-card .card-body form {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .form-card .card-body button[type="submit"] {
            margin-top: auto;
            align-self: flex-end;
            width: 100%;
        }

        .btn {
            font-weight: 500;
            border-radius: var(--border-radius);
            padding: 0.5rem 1.25rem;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin: 0.25rem;
        }

        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .btn-primary:hover {
            background-color: var(--secondary-color);
            transform: translateY(-2px);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .btn-outline-primary {
            color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .btn-outline-primary:hover {
            background-color: var(--primary-color);
            color: white;
        }

        .btn-danger {
            background-color: var(--danger-color);
            border-color: var(--danger-color);
        }

        .btn-danger:hover {
            background-color: #dc2626;
            transform: translateY(-2px);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .btn-sm {
            padding: 0.25rem 0.75rem;
            font-size: 0.875rem;
        }

        .form-control, .form-select {
            border-radius: var(--border-radius);
            padding: 0.5rem 0.75rem;
            border: 1px solid #d1d5db;
            transition: var(--transition);
            margin-bottom: 1rem;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(67, 97, 238, 0.25);
        }

        .seat-map-container {
            background-color: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--box-shadow-sm);
            margin-bottom: 1.5rem;
        }

        .seat-map {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(60px, 1fr));
            gap: 10px;
            margin: 1.5rem 0;
        }

        @media (max-width: 768px) {
            .seat-map {
                grid-template-columns: repeat(auto-fill, minmax(50px, 1fr));
            }
            
            .col-lg-5, .col-lg-7 {
                padding: 0 10px;
            }
            
            .card-body {
                padding: 1rem;
            }
        }

        .seat {
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--border-radius-sm);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            position: relative;
            border: 2px solid transparent;
            box-shadow: var(--box-shadow-sm);
        }

        .seat:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .seat-available {
            background-color: white;
            border: 2px solid var(--primary-color);
            color: var(--primary-color);
        }

        .seat-booked {
            background-color: var(--danger-color);
            color: white;
            cursor: not-allowed;
        }

        .seat-blocked {
            background-color: var(--warning-color);
            color: white;
            cursor: not-allowed;
        }

        .seat-selected {
            background-color: var(--success-color);
            color: white;
            border-color: var(--primary-color);
            animation: pulse 1.5s infinite;
        }

        .legend {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }

        .legend-item {
            display: inline-flex;
            align-items: center;
            padding: 0.5rem 1rem;
            background-color: white;
            border-radius: 50px;
            box-shadow: var(--box-shadow-sm);
            font-size: 0.875rem;
        }

        .legend-color {
            width: 20px;
            height: 20px;
            border-radius: 4px;
            margin-right: 8px;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        @keyframes fadeOut {
            to { opacity: 0; }
        }

        .floating-alert {
            position: fixed;
            top: 80px;
            right: 20px;
            z-index: 9999;
            animation: slideInRight 0.5s, fadeOut 0.5s 2.5s forwards;
            max-width: 350px;
            margin: 0;
        }

        .schedule-card {
            border-left: 4px solid var(--primary-color);
            transition: var(--transition);
            background-color: white;
            padding: 1.25rem;
            border-radius: var(--border-radius-sm);
            margin-bottom: 1.5rem;
            box-shadow: var(--box-shadow-sm);
        }

        .schedule-card:hover {
            border-left: 4px solid var(--success-color);
            transform: translateY(-2px);
        }

        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .table {
            margin-bottom: 0;
            border-collapse: separate;
            border-spacing: 0;
        }

        .table th {
            font-weight: 600;
            color: var(--gray-color);
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.5px;
            border-top: none;
            padding: 1rem 1.35rem;
            background-color: #f9fafb;
        }

        .table td {
            padding: 1rem 1.35rem;
            vertical-align: middle;
            border-top: 1px solid #e5e7eb;
        }

        .table-hover tbody tr {
            transition: var(--transition);
        }

        .table-hover tbody tr:hover {
            background-color: #f9fafb;
            transform: translateX(2px);
        }

        .badge {
            font-weight: 500;
            padding: 0.35em 0.65em;
            border-radius: var(--border-radius-sm);
        }

        .modal-content {
            border: none;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--box-shadow);
        }

        .cursor-pointer {
            cursor: pointer;
        }

        .text-muted {
            color: var(--gray-color) !important;
        }

        .fw-medium {
            font-weight: 500;
        }

        .rounded-lg {
            border-radius: var(--border-radius);
        }

        .shadow-sm {
            box-shadow: var(--box-shadow-sm);
        }

        .loading-spinner {
            display: inline-block;
            width: 1rem;
            height: 1rem;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
            margin-right: 0.5rem;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    
    <div class="container py-4">
        <?php if (isset($_SESSION['error'])): ?>
            <div class="floating-alert alert alert-danger alert-dismissible fade show animate__animated animate__slideInRight">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <?= $_SESSION['error'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="floating-alert alert alert-success alert-dismissible fade show animate__animated animate__slideInRight">
                <i class="bi bi-check-circle-fill me-2"></i>
                <?= $_SESSION['success'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        
        <div class="d-flex justify-content-between align-items-center mb-4 animate__animated animate__fadeIn">
            <div>
                <h2 class="mb-0">
                    <i class="bi bi-lock-fill text-primary me-2"></i>
                    <span class="text-gradient">Seat Blocking Management</span>
                </h2>
                <p class="text-muted mb-0">Manage seat availability for your bus schedules</p>
            </div>
            <div>
                <a href="dashboard.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Back to Dashboard
                </a>
            </div>
        </div>
        
        <div class="row animate__animated animate__fadeInUp">
            <div class="col-lg-5 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h4 class="mb-0"><i class="bi bi-plus-circle me-2"></i>Block New Seats</h4>
                    </div>
                    <div class="card-body">
                        <form method="post" id="blockSeatsForm">
                            <div class="mb-3">
                                <label class="form-label fw-medium">Select Schedule</label>
                                <select name="schedule_id" class="form-select" required id="scheduleSelect">
                                    <option value="">-- Select Schedule --</option>
                                    <?php foreach ($schedules as $schedule): ?>
                                        <?php $selected = $schedule['schedule_id'] == $schedule_id ? 'selected' : ''; ?>
                                        <option value="<?= $schedule['schedule_id'] ?>" 
                                                data-bus-id="<?= $schedule['bus_id'] ?>"
                                                <?= $selected ?>>
                                            <?= htmlspecialchars($schedule['origin_city']) ?> → 
                                            <?= htmlspecialchars($schedule['destination_city']) ?> - 
                                            <?= date('M j, Y g:i A', strtotime($schedule['departure_time'])) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="bus_id" id="bus_id" value="">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-medium">Seat Numbers</label>
                                <input type="text" name="seat_numbers" class="form-control" 
                                       placeholder="e.g., 1, 2, 5-8, 12" required
                                       id="seatNumbersInput">
                                <small class="text-muted">Enter seat numbers separated by commas or ranges</small>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-medium">Reason (Optional)</label>
                                <input type="text" name="reason" class="form-control" 
                                       placeholder="E.g., Maintenance, VIP reservation">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-medium">Block Until (Optional)</label>
                                <input type="datetime-local" name="block_until" class="form-control">
                                <small class="text-muted">Leave empty for indefinite blocking</small>
                            </div>
                            
                            <button type="submit" name="block_seats" class="btn btn-primary w-100 py-2">
                                <i class="bi bi-lock-fill me-2"></i> Block Seats
                            </button>
                        </form>
                    </div>
                </div>
                
                <div class="card mt-4">
                    <div class="card-header bg-info text-white">
                        <h4 class="mb-0"><i class="bi bi-info-circle me-2"></i>Legend & Instructions</h4>
                    </div>
                    <div class="card-body">
                        <div class="legend mb-3">
                            <div class="legend-item">
                                <div class="legend-color seat-available"></div>
                                <span>Available</span>
                            </div>
                            <div class="legend-item">
                                <div class="legend-color seat-booked"></div>
                                <span>Booked</span>
                            </div>
                            <div class="legend-item">
                                <div class="legend-color seat-blocked"></div>
                                <span>Blocked</span>
                            </div>
                        </div>
                        <div class="alert alert-light shadow-sm">
                            <h6 class="fw-medium"><i class="bi bi-lightbulb me-2"></i>Tips:</h6>
                            <ul class="mb-0">
                                <li>You can block multiple seats at once (max 10)</li>
                                <li>Use commas to separate seat numbers</li>
                                <li>Use hyphens for ranges (e.g., 5-8)</li>
                                <li>Blocked seats cannot be booked by passengers</li>
                                <li>Set an expiration date for temporary blocks</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-7">
                <div class="card h-100">
                    <div class="card-header">
                        <h4 class="mb-0"><i class="bi bi-list-check me-2"></i>Currently Blocked Seats</h4>
                    </div>
                    <div class="card-body">
                        <?php if ($schedule_id > 0 && $schedule_details): ?>
                            <div class="schedule-card">
                                <h5 class="fw-bold mb-2">
                                    <i class="bi bi-bus-front me-2"></i>
                                    <?= htmlspecialchars($schedule_details['origin_city']) ?> → 
                                    <?= htmlspecialchars($schedule_details['destination_city']) ?>
                                </h5>
                                <div class="d-flex flex-wrap gap-3">
                                    <div>
                                        <small class="text-muted">Departure:</small>
                                        <div class="fw-medium"><?= date('M j, Y g:i A', strtotime($schedule_details['departure_time'])) ?></div>
                                    </div>
                                    <div>
                                        <small class="text-muted">Bus:</small>
                                        <div class="fw-medium"><?= htmlspecialchars($schedule_details['bus_name']) ?> (<?= htmlspecialchars($schedule_details['registration_number']) ?>)</div>
                                    </div>
                                    <div>
                                        <small class="text-muted">Capacity:</small>
                                        <div class="fw-medium"><?= $schedule_details['total_seats'] ?> seats</div>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if (empty($blocked_seats)): ?>
                                <div class="text-center py-5">
                                    <i class="bi bi-emoji-frown display-4 text-muted mb-3"></i>
                                    <h5>No seats currently blocked</h5>
                                    <p class="text-muted">Select seats to block from the form on the left</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Seat #</th>
                                                <th>Blocked By</th>
                                                <th>Reason</th>
                                                <th>Blocked At</th>
                                                <th>Block Until</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($blocked_seats as $block): ?>
                                                <tr class="animate__animated animate__fadeIn">
                                                    <td>
                                                        <span class="badge bg-warning text-dark fs-6"><?= htmlspecialchars($block['seat_number']) ?></span>
                                                    </td>
                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            <div class="me-2">
                                                                <i class="bi bi-person-circle fs-4 text-primary"></i>
                                                            </div>
                                                            <div>
                                                                <div class="fw-medium"><?= htmlspecialchars($block['company_name']) ?></div>
                                                                <small class="text-muted"><?= htmlspecialchars($block['first_name'] . ' ' . $block['last_name']) ?></small>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td><?= htmlspecialchars($block['reason'] ?? 'N/A') ?></td>
                                                    <td><?= date('M j, g:i A', strtotime($block['blocked_at'])) ?></td>
                                                    <td>
                                                        <?= $block['blocked_until'] ? 
                                                            date('M j, g:i A', strtotime($block['blocked_until'])) : 
                                                            '<span class="badge bg-secondary">Indefinite</span>' ?>
                                                    </td>
                                                    <td>
                                                        <button class="btn btn-sm btn-danger unblock-btn" 
                                                                data-block-id="<?= $block['block_id'] ?>"
                                                                data-schedule-id="<?= $schedule_id ?>"
                                                                data-seat-number="<?= htmlspecialchars($block['seat_number']) ?>">
                                                            <i class="bi bi-unlock me-1"></i> Unblock
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="bi bi-calendar-event display-4 text-primary mb-3"></i>
                                <h5>No schedule selected</h5>
                                <p class="text-muted">Please select a schedule to view blocked seats</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="unblockModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle-fill me-2"></i>Confirm Unblock</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to unblock this seat? This will make it available for booking again.</p>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <strong>Seat #<span id="unblockSeatNumber"></span></strong> will be unblocked
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <a href="#" id="confirmUnblock" class="btn btn-danger">
                        <i class="bi bi-unlock me-1"></i> Confirm Unblock
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        document.getElementById('scheduleSelect').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const busIdInput = document.getElementById('bus_id');
            
            if (selectedOption && selectedOption.dataset.busId) {
                busIdInput.value = selectedOption.dataset.busId;
                
                if (this.value) {
                    window.location.href = `block-seats.php?schedule_id=${this.value}`;
                }
            } else {
                busIdInput.value = '';
            }
        });
        
        const initialSchedule = document.querySelector('select[name="schedule_id"] option[selected]');
        if (initialSchedule) {
            document.getElementById('bus_id').value = initialSchedule.dataset.busId;
        }
        
        document.querySelectorAll('.unblock-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const blockId = this.getAttribute('data-block-id');
                const scheduleId = this.getAttribute('data-schedule-id');
                const seatNumber = this.getAttribute('data-seat-number');
                
                document.getElementById('unblockSeatNumber').textContent = seatNumber;
                
                document.getElementById('confirmUnblock').href = 
                    `block-seats.php?unblock=${blockId}&schedule_id=${scheduleId}`;
                
                const modal = new bootstrap.Modal(document.getElementById('unblockModal'));
                modal.show();
            });
        });
        
        document.getElementById('blockSeatsForm').addEventListener('submit', function(e) {
            const seatNumbers = document.getElementById('seatNumbersInput').value.trim();
            
            if (!seatNumbers) {
                e.preventDefault();
                Swal.fire({
                    title: 'Seat Numbers Required',
                    text: 'Please enter at least one seat number to block',
                    icon: 'warning',
                    confirmButtonColor: '#4361ee',
                    confirmButtonText: 'OK'
                });
            }
        });
        
        setTimeout(() => {
            const alerts = document.querySelectorAll('.floating-alert');
            alerts.forEach(alert => {
                alert.classList.add('animate__fadeOut');
                setTimeout(() => alert.remove(), 500);
            });
        }, 3000);
        
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('animate__fadeInUp');
                }
            });
        }, { threshold: 0.1 });
        
        document.querySelectorAll('.card').forEach(card => {
            observer.observe(card);
        });
    </script>
</body>
</html>
<?php
$conn->close();
?>