<?php
require_once '../includes/config.php';

// Verify user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit();
}

// Get booking reference from URL
$booking_ref = isset($_GET['ref']) ? trim($_GET['ref']) : null;
if (empty($booking_ref)) {
    header('Location: /passenger/booking-history.php?error=invalid_reference');
    exit();
}

// Fetch booking details with schedule information
$stmt = $conn->prepare("
    SELECT 
        b.*,
        s.departure_time,
        s.arrival_time,
        b.name AS bus_name,
        b.registration_number
    FROM bookings b
    JOIN schedules s ON b.schedule_id = s.schedule_id
    JOIN buses b ON s.bus_id = b.bus_id
    WHERE b.booking_reference = ? AND b.passenger_id = ?
    LIMIT 1
");
$stmt->bind_param("si", $booking_ref, $_SESSION['user_id']);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$booking) {
    header('Location: /passenger/booking-history.php?error=booking_not_found');
    exit();
}

// Format dates and times
$travel_date = date('l, F j, Y', strtotime($booking['travel_date']));
$departure_time = date('h:i A', strtotime($booking['departure_time']));
$arrival_time = date('h:i A', strtotime($booking['arrival_time']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Confirmation - <?= htmlspecialchars(SITE_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .ticket-container {
            max-width: 600px;
            margin: 0 auto;
        }
        .ticket {
            border: 2px solid #0d6efd;
            border-radius: 10px;
            overflow: hidden;
        }
        .ticket-header {
            background-color: #0d6efd;
            color: white;
            padding: 15px;
            text-align: center;
        }
        .ticket-body {
            padding: 20px;
        }
        .ticket-qr {
            width: 120px;
            height: 120px;
            background: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            border: 1px dashed #ccc;
        }
        .info-item {
            margin-bottom: 10px;
        }
        .info-label {
            font-weight: 600;
            color: #495057;
        }
        .bus-details {
            background-color: #f8f9fa;
            border-radius: 5px;
            padding: 15px;
            margin-top: 20px;
        }
        @media print {
            body {
                background-color: white;
            }
            .no-print {
                display: none !important;
            }
            .ticket {
                border: none;
                box-shadow: none;
            }
        }
    </style>
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="ticket-container">
            <div class="ticket shadow">
                <div class="ticket-header">
                    <h3><i class="fas fa-check-circle me-2"></i> BOOKING CONFIRMED</h3>
                    <p class="mb-0">Reference: <?= htmlspecialchars($booking['booking_reference']) ?></p>
                </div>
                
                <div class="ticket-body">
                    <!-- QR Code Placeholder -->
                    <div class="ticket-qr">
                        <i class="fas fa-qrcode fa-3x text-muted"></i>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="info-item">
                                <span class="info-label">Passenger:</span>
                                <?= htmlspecialchars($booking['passenger_name']) ?>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Contact:</span>
                                <?= htmlspecialchars($booking['phone']) ?>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Email:</span>
                                <?= htmlspecialchars($booking['email']) ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-item">
                                <span class="info-label">Status:</span>
                                <span class="badge bg-<?= $booking['booking_status'] === 'confirmed' ? 'success' : 'warning' ?>">
                                    <?= ucfirst($booking['booking_status']) ?>
                                </span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Payment:</span>
                                <span class="badge bg-<?= $booking['payment_status'] === 'paid' ? 'success' : 'warning' ?>">
                                    <?= ucfirst($booking['payment_status']) ?>
                                </span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Booked On:</span>
                                <?= date('M j, Y h:i A', strtotime($booking['booked_at'])) ?>
                            </div>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <h5><i class="fas fa-route me-2"></i> Journey Details</h5>
                            <div class="info-item">
                                <span class="info-label">From:</span>
                                <?= htmlspecialchars($booking['from_location']) ?>
                            </div>
                            <div class="info-item">
                                <span class="info-label">To:</span>
                                <?= htmlspecialchars($booking['to_location']) ?>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Date:</span>
                                <?= $travel_date ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h5><i class="fas fa-clock me-2"></i> Schedule</h5>
                            <div class="info-item">
                                <span class="info-label">Departure:</span>
                                <?= $departure_time ?>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Arrival:</span>
                                <?= $arrival_time ?>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Seats:</span>
                                <?= htmlspecialchars($booking['seat_number']) ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bus-details">
                        <h5><i class="fas fa-bus me-2"></i> Bus Details</h5>
                        <div class="info-item">
                            <span class="info-label">Bus Name:</span>
                            <?= htmlspecialchars($booking['bus_name']) ?>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Registration:</span>
                            <?= htmlspecialchars($booking['registration_number']) ?>
                        </div>
                    </div>
                    
                    <div class="mt-4 text-end">
                        <h4>Total Paid: Rs. <?= number_format($booking['total_amount'], 2) ?></h4>
                    </div>
                </div>
            </div>
            
            <div class="d-flex justify-content-between mt-4 no-print">
                <a href="/passenger/booking-history.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-2"></i> Back to Bookings
                </a>
                <div>
                    <button onclick="window.print()" class="btn btn-primary me-2">
                        <i class="fas fa-print me-2"></i> Print Ticket
                    </button>
                    <a href="/passenger/download-ticket.php?ref=<?= urlencode($booking['booking_reference']) ?>" class="btn btn-success">
                        <i class="fas fa-download me-2"></i> Download PDF
                    </a>
                </div>
            </div>
            
            <div class="alert alert-info mt-4 no-print">
                <i class="fas fa-info-circle me-2"></i>
                Please present this ticket (printed or mobile) when boarding the bus.
                Boarding begins 30 minutes before departure.
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Add any JavaScript functionality if needed
        document.addEventListener('DOMContentLoaded', function() {
            // You could add QR code generation here if needed
            // Example: new QRCode(document.querySelector('.ticket-qr'), '<?= $booking['booking_reference'] ?>');
        });
    </script>
</body>
</html>