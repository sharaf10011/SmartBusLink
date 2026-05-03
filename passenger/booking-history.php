<?php
session_start();
require_once '../includes/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

// Create MySQLi connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$user_id = $_SESSION['user_id'];
$bookings = [];
$error = '';

// Corrected query with proper field names from your schema
$query = "
    SELECT 
        b.booking_id,
        b.booking_reference,
        b.booking_status,
        b.payment_status,
        b.total_fare,
        b.booked_at,
        b.travel_date,
        b.from_location,
        b.to_location,
        b.seat_number,
        s.departure_time,
        s.arrival_time,
        bus.registration_number AS bus_number,
        bus.name AS bus_name,
        op.company_name AS operator_name
    FROM 
        bookings b
    JOIN 
        schedules s ON b.schedule_id = s.schedule_id
    JOIN 
        buses bus ON s.bus_id = bus.bus_id
    JOIN 
        operators op ON bus.operator_id = op.operator_id
    WHERE 
        b.passenger_id = ?
    ORDER BY 
        b.travel_date DESC, b.booked_at DESC
";

$stmt = $conn->prepare($query);
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $bookings = $result->fetch_all(MYSQLI_ASSOC);
    } else {
        $error = "Error fetching bookings: " . $stmt->error;
    }
    $stmt->close();
} else {
    $error = "Database error: " . $conn->error;
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartBusLink - Booking History</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --info-color: #17a2b8;
        }
        
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .booking-card {
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
            border: none;
            margin-bottom: 20px;
        }
        
        .booking-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.15);
        }
        
        .status-badge {
            font-size: 0.8rem;
            padding: 5px 10px;
            border-radius: 20px;
        }
        
        .status-confirmed {
            background-color: var(--success-color);
            color: white;
        }
        
        .status-pending {
            background-color: var(--warning-color);
            color: black;
        }
        
        .status-cancelled {
            background-color: var(--danger-color);
            color: white;
        }
        
        .status-completed {
            background-color: var(--info-color);
            color: white;
        }
        
        .payment-paid {
            color: var(--success-color);
            font-weight: bold;
        }
        
        .payment-pending {
            color: var(--warning-color);
            font-weight: bold;
        }
        
        .payment-failed {
            color: var(--danger-color);
            font-weight: bold;
        }
        
        .route-info {
            border-left: 3px solid var(--secondary-color);
            padding-left: 15px;
        }
        
        .travel-date {
            background-color: var(--primary-color);
            color: white;
            padding: 5px 10px;
            border-radius: 5px;
            display: inline-block;
        }
        
        @media (max-width: 768px) {
            .booking-card {
                margin-bottom: 15px;
            }
            
            .route-info {
                border-left: none;
                padding-left: 0;
                border-top: 2px solid var(--secondary-color);
                padding-top: 10px;
                margin-top: 10px;
            }
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="fw-bold text-primary">
                <i class="bi bi-clock-history"></i> Booking History
            </h2>
            <a href="index.php" class="btn btn-outline-primary">
                <i class="bi bi-bus-front"></i> Book New Trip
            </a>
        </div>
        
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (empty($bookings)): ?>
            <div class="text-center py-5">
                <i class="bi bi-calendar-x text-muted" style="font-size: 3rem;"></i>
                <h4 class="mt-3">No Bookings Found</h4>
                <p class="text-muted">You haven't made any bookings yet.</p>
                <a href="dashboard.php" class="btn btn-primary mt-2">
                    <i class="bi bi-search"></i> Find Buses
                </a>
            </div>
        <?php else: ?>
            <div class="row">
                <div class="col-md-8 mx-auto">
                    <div class="mb-3">
                        <input type="text" class="form-control" id="searchBookings" placeholder="Search bookings...">
                    </div>
                    
                    <?php foreach ($bookings as $booking): ?>
                        <div class="card booking-card mb-4">
                            <div class="card-header bg-white">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <span class="travel-date">
                                            <i class="bi bi-calendar"></i> 
                                            <?= date('D, d M Y', strtotime($booking['travel_date'])) ?>
                                        </span>
                                    </div>
                                    <div>
                                        <span class="badge status-badge status-<?= $booking['booking_status'] ?>">
                                            <?= ucfirst($booking['booking_status']) ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-8">
                                        <h5 class="card-title">
                                            <?= htmlspecialchars($booking['from_location']) ?> 
                                            <i class="bi bi-arrow-right"></i> 
                                            <?= htmlspecialchars($booking['to_location']) ?>
                                        </h5>
                                        
                                        <div class="route-info mt-3">
                                            <p class="mb-1">
                                                <i class="bi bi-clock"></i> 
                                                <strong>Departure:</strong> 
                                                <?= date('h:i A', strtotime($booking['departure_time'])) ?>
                                            </p>
                                            <p class="mb-1">
                                                <i class="bi bi-clock-fill"></i> 
                                                <strong>Arrival:</strong> 
                                                <?= date('h:i A', strtotime($booking['arrival_time'])) ?>
                                            </p>
                                            <p class="mb-1">
                                                <i class="bi bi-bus-front"></i> 
                                                <strong>Bus:</strong> 
                                                <?= htmlspecialchars($booking['bus_name']) ?> 
                                                (<?= htmlspecialchars($booking['bus_number']) ?> - <?= htmlspecialchars($booking['operator_name']) ?>)
                                            </p>
                                            <p class="mb-1">
                                                <i class="bi bi-person"></i> 
                                                <strong>Seat:</strong> 
                                                <?= htmlspecialchars($booking['seat_number']) ?>
                                            </p>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-4">
                                        <div class="border-start ps-3">
                                            <p class="mb-2">
                                                <strong>Booking Ref:</strong> 
                                                <?= htmlspecialchars($booking['booking_reference']) ?>
                                            </p>
                                            <p class="mb-2">
                                                <strong>Booked On:</strong> 
                                                <?= date('d M Y h:i A', strtotime($booking['booked_at'])) ?>
                                            </p>
                                            <p class="mb-2">
                                                <strong>Payment:</strong> 
                                                <span class="payment-<?= $booking['payment_status'] ?>">
                                                    <?= ucfirst($booking['payment_status']) ?>
                                                </span>
                                            </p>
                                            <h4 class="text-primary mt-3">
                                                LKR <?= number_format($booking['total_fare'], 2) ?>
                                            </h4>
                                            
                                            <div class="d-grid gap-2 mt-3">
                                                <?php if ($booking['booking_status'] == 'pending' || $booking['booking_status'] == 'confirmed'): ?>
                                                    <button class="btn btn-sm btn-outline-danger cancel-booking" 
                                                            data-booking-id="<?= $booking['booking_id'] ?>">
                                                        <i class="bi bi-x-circle"></i> Cancel
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <button class="btn btn-sm btn-outline-primary view-details" 
                                                        data-booking-id="<?= $booking['booking_id'] ?>">
                                                    <i class="bi bi-receipt"></i> View Details
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Booking Details Modal -->
    <div class="modal fade" id="bookingDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Booking Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="bookingDetailsContent">
                    <!-- Content will be loaded via AJAX -->
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" id="printTicketBtn">
                        <i class="bi bi-printer"></i> Print Ticket
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Search functionality
            document.getElementById('searchBookings').addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                const cards = document.querySelectorAll('.booking-card');
                
                cards.forEach(card => {
                    const text = card.textContent.toLowerCase();
                    card.style.display = text.includes(searchTerm) ? 'block' : 'none';
                });
            });
            
            // View booking details
            document.querySelectorAll('.view-details').forEach(button => {
                button.addEventListener('click', function() {
                    const bookingId = this.getAttribute('data-booking-id');
                    const modal = new bootstrap.Modal(document.getElementById('bookingDetailsModal'));
                    
                    // Load booking details via AJAX
                    fetch(`get-booking-details.php?booking_id=${bookingId}`)
                        .then(response => response.text())
                        .then(data => {
                            document.getElementById('bookingDetailsContent').innerHTML = data;
                        })
                        .catch(error => {
                            document.getElementById('bookingDetailsContent').innerHTML = `
                                <div class="alert alert-danger">
                                    Error loading booking details. Please try again.
                                </div>
                            `;
                        });
                    
                    modal.show();
                });
            });
            
            // Cancel booking
            document.querySelectorAll('.cancel-booking').forEach(button => {
                button.addEventListener('click', function() {
                    const bookingId = this.getAttribute('data-booking-id');
                    if (confirm('Are you sure you want to cancel this booking?')) {
                        // Submit cancellation request
                        fetch('cancel-booking.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: `booking_id=${bookingId}`
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                alert('Booking cancelled successfully!');
                                location.reload();
                            } else {
                                alert('Error: ' + data.message);
                            }
                        })
                        .catch(error => {
                            alert('Error cancelling booking. Please try again.');
                        });
                    }
                });
            });
            
            // Print ticket
            document.getElementById('printTicketBtn').addEventListener('click', function() {
                window.print();
            });
        });
    </script>
</body>
</html>