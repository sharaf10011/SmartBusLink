<?php
session_start();
require_once '../includes/config.php';

// Get operator details by user ID
function getOperatorByUserId($userId, $conn) {
    try {
        $stmt = $conn->prepare("
            SELECT o.*, u.email as user_email, u.first_name, u.last_name 
            FROM operators o
            JOIN users u ON o.user_id = u.user_id
            WHERE o.user_id = ?
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    } catch (Exception $e) {
        error_log("Error getting operator by user ID: " . $e->getMessage());
        return null;
    }
}

// Authentication
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'operator') {
    header('Location: /login.php');
    exit;
}

// Get operator details
$operator = getOperatorByUserId($_SESSION['user_id'], $conn);
if (!$operator) {
    die('Operator account not properly configured');
}

// Handle status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    // Generate CSRF token if not exists
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('Invalid CSRF token');
    }
    
    $bookingId = (int)$_POST['booking_id'];
    $newStatus = $_POST['status'];
    
    try {
        // First verify the booking belongs to this operator
        $stmt = $conn->prepare("
            SELECT 1 FROM bookings b 
            JOIN schedules s ON b.schedule_id = s.schedule_id 
            WHERE b.booking_id = ? AND s.operator_id = ?
        ");
        $stmt->bind_param("ii", $bookingId, $operator['operator_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if (!$result->fetch_assoc()) {
            throw new Exception("Booking not found or not owned by this operator");
        }
        $stmt->close();
        
        // Validate status
        $validStatuses = ['pending', 'confirmed', 'cancelled', 'completed'];
        if (!in_array($newStatus, $validStatuses)) {
            throw new Exception("Invalid status");
        }
        
        // Update booking status
        $stmt = $conn->prepare("UPDATE bookings SET booking_status = ? WHERE booking_id = ?");
        $stmt->bind_param("si", $newStatus, $bookingId);
        $stmt->execute();
        $stmt->close();
        
        // If cancelled, set cancelled_at timestamp
        if ($newStatus === 'cancelled') {
            $stmt = $conn->prepare("UPDATE bookings SET cancelled_at = NOW() WHERE booking_id = ?");
            $stmt->bind_param("i", $bookingId);
            $stmt->execute();
            $stmt->close();
        }
        
        $_SESSION['success_message'] = "Booking status updated successfully!";
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error updating status: " . $e->getMessage();
    }
    
    header("Location: {$_SERVER['PHP_SELF']}");
    exit;
}

// Get all bookings for this operator's trips
$bookings = [];
$searchTerm = '';
$statusFilter = '';

if (isset($_GET['search'])) {
    $searchTerm = trim($_GET['search']);
}

if (isset($_GET['status']) && in_array($_GET['status'], ['pending', 'confirmed', 'cancelled', 'completed'])) {
    $statusFilter = $_GET['status'];
}

try {
    $sql = "SELECT b.*, 
                   s.departure_time, s.arrival_time,
                   r.origin_city, r.destination_city,
                   bus.name as bus_name, bus.registration_number
            FROM bookings b
            JOIN schedules s ON b.schedule_id = s.schedule_id
            JOIN routes r ON s.route_id = r.route_id
            JOIN buses bus ON s.bus_id = bus.bus_id
            WHERE s.operator_id = ?";
    
    $params = [$operator['operator_id']];
    $types = "i";
    
    if (!empty($searchTerm)) {
        $sql .= " AND (b.passenger_name LIKE ? OR b.booking_reference LIKE ? OR b.phone LIKE ?)";
        $searchParam = "%$searchTerm%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $types .= "sss";
    }
    
    if (!empty($statusFilter)) {
        $sql .= " AND b.booking_status = ?";
        $params[] = $statusFilter;
        $types .= "s";
    }
    
    $sql .= " ORDER BY s.departure_time DESC, b.booked_at DESC";
    
    $stmt = $conn->prepare($sql);
    
    // Bind parameters dynamically
    if (count($params) > 0) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $bookings = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Exception $e) {
    $errorMessage = 'Failed to load bookings: ' . $e->getMessage();
    error_log($errorMessage);
}

// Generate CSRF token for the form
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Passenger Manifest | SmartBusLink</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
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
            --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        }
        
        body {
            background-color: #f5f7fa;
            font-family: 'Nunito', sans-serif;
        }
        
        .card {
            border: none;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
        }
        
        .status-badge {
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.35em 0.65em;
            border-radius: 0.5rem;
        }
        
        .status-pending {
            background-color: #f8f9fa;
            color: var(--warning-color);
            border: 1px solid var(--warning-color);
        }
        
        .status-confirmed {
            background-color: #e8f5e9;
            color: var(--success-color);
            border: 1px solid var(--success-color);
        }
        
        .status-cancelled {
            background-color: #ffebee;
            color: var(--danger-color);
            border: 1px solid var(--danger-color);
        }
        
        .status-completed {
            background-color: #e3f2fd;
            color: var(--info-color);
            border: 1px solid var(--info-color);
        }
        
        .table-hover tbody tr {
            transition: var(--transition);
        }
        
        .table-hover tbody tr:hover {
            transform: translateX(2px);
            box-shadow: 0 0.15rem 0.75rem rgba(0, 0, 0, 0.05);
        }
        
        .search-box {
            position: relative;
        }
        
        .search-box .form-control {
            padding-left: 2.5rem;
            border-radius: var(--border-radius);
        }
        
        .search-box .bi {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--secondary-color);
        }
    </style>
</head>
<body>
    <div class="container py-4">
        <?php include 'header.php'; ?>
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3 mb-0 text-gray-800">
                <i class="bi bi-people-fill me-2"></i>Passenger Manifest
            </h1>
            <div class="d-flex">
                <form method="get" class="me-3 search-box">
                    <i class="bi bi-search"></i>
                    <input type="text" class="form-control" name="search" placeholder="Search passengers..." value="<?= htmlspecialchars($searchTerm) ?>">
                </form>
                <div class="dropdown">
                    <button class="btn btn-outline-secondary dropdown-toggle" type="button" id="statusFilterDropdown" data-bs-toggle="dropdown">
                        <i class="bi bi-funnel me-1"></i>
                        <?= empty($statusFilter) ? 'All Statuses' : ucfirst($statusFilter) ?>
                    </button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="?status=">All Statuses</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="?status=pending">Pending</a></li>
                        <li><a class="dropdown-item" href="?status=confirmed">Confirmed</a></li>
                        <li><a class="dropdown-item" href="?status=cancelled">Cancelled</a></li>
                        <li><a class="dropdown-item" href="?status=completed">Completed</a></li>
                    </ul>
                </div>
            </div>
        </div>
        
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <?= htmlspecialchars($_SESSION['error_message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i>
                <?= htmlspecialchars($_SESSION['success_message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Booking Ref</th>
                                <th>Passenger</th>
                                <th>Contact</th>
                                <th>Route</th>
                                <th>Departure</th>
                                <th>Seat</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($bookings)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4">
                                        <i class="bi bi-emoji-frown fs-1 text-muted"></i>
                                        <p class="mt-2">No bookings found</p>
                                        <a href="?" class="btn btn-sm btn-outline-primary">Clear filters</a>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($bookings as $booking): ?>
                                    <tr>
                                        <td>
                                            <span class="badge bg-light text-dark"><?= htmlspecialchars($booking['booking_reference']) ?></span>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($booking['passenger_name']) ?></strong>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($booking['phone']) ?><br>
                                            <small class="text-muted"><?= htmlspecialchars($booking['email']) ?></small>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($booking['origin_city']) ?> → <?= htmlspecialchars($booking['destination_city']) ?>
                                        </td>
                                        <td>
                                            <?= date('M j, Y', strtotime($booking['departure_time'])) ?><br>
                                            <small class="text-muted"><?= date('g:i A', strtotime($booking['departure_time'])) ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary"><?= htmlspecialchars($booking['seat_number']) ?></span>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?= $booking['booking_status'] ?>">
                                                <?= ucfirst($booking['booking_status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                                    <i class="bi bi-gear"></i>
                                                </button>
                                                <ul class="dropdown-menu">
                                                    <li>
                                                        <form method="post" class="px-2">
                                                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                            <input type="hidden" name="booking_id" value="<?= $booking['booking_id'] ?>">
                                                            <div class="mb-2">
                                                                <label class="form-label small">Update Status</label>
                                                                <select name="status" class="form-select form-select-sm">
                                                                    <option value="pending" <?= $booking['booking_status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                                                                    <option value="confirmed" <?= $booking['booking_status'] === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                                                                    <option value="cancelled" <?= $booking['booking_status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                                                    <option value="completed" <?= $booking['booking_status'] === 'completed' ? 'selected' : '' ?>>Completed</option>
                                                                </select>
                                                            </div>
                                                            <button type="submit" name="update_status" class="btn btn-sm btn-primary w-100">
                                                                Update
                                                            </button>
                                                        </form>
                                                    </li>
                                                    <li><hr class="dropdown-divider"></li>
                                                    <li>
                                                        <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#detailsModal<?= $booking['booking_id'] ?>">
                                                            <i class="bi bi-eye me-2"></i>View Details
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <a class="dropdown-item" href="/operator/print-ticket.php?id=<?= $booking['booking_id'] ?>" target="_blank">
                                                            <i class="bi bi-printer me-2"></i>Print Ticket
                                                        </a>
                                                    </li>
                                                </ul>
                                            </div>
                                            
                                            <!-- Details Modal -->
                                            <div class="modal fade" id="detailsModal<?= $booking['booking_id'] ?>" tabindex="-1">
                                                <div class="modal-dialog modal-lg">
                                                    <div class="modal-content">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">Booking Details</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <div class="row">
                                                                <div class="col-md-6">
                                                                    <h6>Passenger Information</h6>
                                                                    <ul class="list-group list-group-flush mb-3">
                                                                        <li class="list-group-item d-flex justify-content-between">
                                                                            <span>Name:</span>
                                                                            <span><?= htmlspecialchars($booking['passenger_name']) ?></span>
                                                                        </li>
                                                                        <li class="list-group-item d-flex justify-content-between">
                                                                            <span>Phone:</span>
                                                                            <span><?= htmlspecialchars($booking['phone']) ?></span>
                                                                        </li>
                                                                        <li class="list-group-item d-flex justify-content-between">
                                                                            <span>Email:</span>
                                                                            <span><?= htmlspecialchars($booking['email']) ?></span>
                                                                        </li>
                                                                        <li class="list-group-item d-flex justify-content-between">
                                                                            <span>Seat Number:</span>
                                                                            <span><?= htmlspecialchars($booking['seat_number']) ?></span>
                                                                        </li>
                                                                    </ul>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <h6>Trip Information</h6>
                                                                    <ul class="list-group list-group-flush">
                                                                        <li class="list-group-item d-flex justify-content-between">
                                                                            <span>Route:</span>
                                                                            <span><?= htmlspecialchars($booking['origin_city']) ?> to <?= htmlspecialchars($booking['destination_city']) ?></span>
                                                                        </li>
                                                                        <li class="list-group-item d-flex justify-content-between">
                                                                            <span>Departure:</span>
                                                                            <span><?= date('M j, Y g:i A', strtotime($booking['departure_time'])) ?></span>
                                                                        </li>
                                                                        <li class="list-group-item d-flex justify-content-between">
                                                                            <span>Arrival:</span>
                                                                            <span><?= date('M j, Y g:i A', strtotime($booking['arrival_time'])) ?></span>
                                                                        </li>
                                                                        <li class="list-group-item d-flex justify-content-between">
                                                                            <span>Bus:</span>
                                                                            <span><?= htmlspecialchars($booking['bus_name']) ?> (<?= htmlspecialchars($booking['registration_number']) ?>)</span>
                                                                        </li>
                                                                    </ul>
                                                                </div>
                                                            </div>
                                                            <div class="row mt-3">
                                                                <div class="col-md-6">
                                                                    <h6>Payment Information</h6>
                                                                    <ul class="list-group list-group-flush">
                                                                        <li class="list-group-item d-flex justify-content-between">
                                                                            <span>Total Amount:</span>
                                                                            <span>Rs. <?= number_format($booking['total_amount'], 2) ?></span>
                                                                        </li>
                                                                        <li class="list-group-item d-flex justify-content-between">
                                                                            <span>Payment Status:</span>
                                                                            <span class="text-capitalize"><?= $booking['payment_status'] ?></span>
                                                                        </li>
                                                                        <li class="list-group-item d-flex justify-content-between">
                                                                            <span>Payment Method:</span>
                                                                            <span><?= $booking['payment_method'] ? htmlspecialchars($booking['payment_method']) : 'N/A' ?></span>
                                                                        </li>
                                                                    </ul>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <h6>Booking Information</h6>
                                                                    <ul class="list-group list-group-flush">
                                                                        <li class="list-group-item d-flex justify-content-between">
                                                                            <span>Booking Reference:</span>
                                                                            <span><?= htmlspecialchars($booking['booking_reference']) ?></span>
                                                                        </li>
                                                                        <li class="list-group-item d-flex justify-content-between">
                                                                            <span>Booking Status:</span>
                                                                            <span class="text-capitalize"><?= $booking['booking_status'] ?></span>
                                                                        </li>
                                                                        <li class="list-group-item d-flex justify-content-between">
                                                                            <span>Booked At:</span>
                                                                            <span><?= date('M j, Y g:i A', strtotime($booking['booked_at'])) ?></span>
                                                                        </li>
                                                                    </ul>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                            <a href="/operator/print-ticket.php?id=<?= $booking['booking_id'] ?>" target="_blank" class="btn btn-primary">
                                                                <i class="bi bi-printer me-1"></i>Print Ticket
                                                            </a>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-submit search form when typing stops
        let searchTimer;
        const searchInput = document.querySelector('input[name="search"]');
        
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => {
                this.form.submit();
            }, 500);
        });
    </script>
</body>
</html>
<?php
$conn->close();
?>