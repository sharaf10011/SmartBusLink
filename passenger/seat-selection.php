<?php
require_once '../includes/config.php';

if (!isset($_GET['schedule_id']) || !is_numeric($_GET['schedule_id'])) {
    header('Location: /passenger/search-results.php?error=missing_schedule_id');
    exit();
}

$schedule_id = (int) $_GET['schedule_id'];

// Sanitize query parameters
$from = isset($_GET['from']) ? htmlspecialchars($_GET['from']) : '';
$to = isset($_GET['to']) ? htmlspecialchars($_GET['to']) : '';
$date = isset($_GET['date']) ? htmlspecialchars($_GET['date']) : '';

// Fetch booked seats
$bookedSeats = [];
$stmt = $conn->prepare("SELECT seat_number FROM bookings WHERE schedule_id = ?");
$stmt->bind_param("i", $schedule_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $bookedSeats[] = (int) $row['seat_number'];
}
$stmt->close();

// Fetch blocked seats
$blockedSeats = [];
$stmt = $conn->prepare("
    SELECT seat_number FROM blocked_seats 
    WHERE schedule_id = ? 
    AND is_active = 1
    AND (blocked_until IS NULL OR blocked_until > NOW())
");
$stmt->bind_param("i", $schedule_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $blockedSeats[] = (int) $row['seat_number'];
}
$stmt->close();

// Fetch schedule details
$scheduleStmt = $conn->prepare("
    SELECT s.*, 
           b.name AS bus_name, 
           b.registration_number, 
           b.capacity AS total_seats,
           r.origin_city, 
           r.origin_terminal, 
           r.destination_city, 
           r.destination_terminal,
           r.estimated_duration_min AS duration,
           r.distance_km
    FROM schedules s
    JOIN buses b ON s.bus_id = b.bus_id
    JOIN routes r ON s.route_id = r.route_id
    WHERE s.schedule_id = ?
");
$scheduleStmt->bind_param("i", $schedule_id);
$scheduleStmt->execute();
$scheduleResult = $scheduleStmt->get_result();

if ($scheduleResult->num_rows === 0) {
    header('Location: /passenger/search-results.php?error=schedule_not_found');
    exit();
}

$schedule = $scheduleResult->fetch_assoc();
$scheduleStmt->close();

// Use actual total seats from bus info or fallback
$totalSeats = isset($schedule['total_seats']) ? (int)$schedule['total_seats'] : 48;
$seatsPerRow = 4;

include '../includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Seats - SmartBusLink</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #4361ee;
            --success-color: rgb(34, 179, 77);
            --danger-color: #f72585;
            --warning-color: #ff9800;
            --light-gray: #f8f9fa;
            --dark-gray: #6c757d;
            --border-radius: 12px;
            --box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            --transition: all 0.3s ease;
        }
        
        body {
            background: var(--light-gray);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #333;
        }
        
        .bus-container {
            max-width: 580px;
            margin: 2rem auto;
            background: white;
            padding: 2.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            position: relative;
            overflow: hidden;
        }
        
        .bus-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 8px;
            background: linear-gradient(90deg, var(--primary-color), var(--success-color));
        }
        
        .header-text {
            text-align: center;
            margin-bottom: 1.5rem;
            position: relative;
        }
        
        .header-text h4 {
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }
        
        .header-text p {
            color: var(--dark-gray);
            margin-bottom: 0;
        }
        
        .bus-layout {
            position: relative;
            margin: 2rem 0;
        }
        
        .bus-front {
            text-align: center;
            margin-bottom: 1.5rem;
        }
        
        .bus-front i {
            font-size: 2rem;
            color: var(--primary-color);
        }
        
        .seat-map {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 12px;
            margin-top: 1rem;
        }
        
        .seat {
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            background-color: #e9ecef;
            transition: var(--transition);
            position: relative;
            border: 2px solid transparent;
        }
        
        .seat:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .seat.booked {
            background-color: var(--danger-color);
            color: white;
            cursor: not-allowed;
            opacity: 0.7;
        }
        
        .seat.blocked {
            background-color: var(--warning-color);
            color: white;
            cursor: not-allowed;
        }
        
        .seat.selected {
            background-color: var(--success-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .seat.selected::after {
            content: '\f00c';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            top: -8px;
            right: -8px;
            background: white;
            color: var(--success-color);
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            border: 2px solid var(--success-color);
        }
        
        .legend {
            display: flex;
            justify-content: center;
            gap: 1.5rem;
            margin: 1.5rem 0;
            flex-wrap: wrap;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
        }
        
        .legend .box {
            width: 20px;
            height: 20px;
            border-radius: 4px;
            border: 1px solid rgba(0,0,0,0.1);
        }
        
        .available .box { background-color: #e9ecef; }
        .booked .box { background-color: var(--danger-color); }
        .blocked .box { background-color: var(--warning-color); }
        .selected .box { background-color: var(--success-color); }
        
        .selection-info {
            background: #f1f8ff;
            padding: 1rem;
            border-radius: var(--border-radius);
            margin: 1.5rem 0;
            border-left: 4px solid var(--primary-color);
        }
        
        .selection-info h6 {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--primary-color);
        }
        
        .selection-info p {
            margin-bottom: 0;
            font-size: 0.9rem;
        }
        
        .btn-continue {
            background: var(--primary-color);
            border: none;
            padding: 0.6rem 2rem;
            font-weight: 600;
            transition: var(--transition);
        }
        
        .btn-continue:hover {
            background: #3a56d4;
            transform: translateY(-2px);
        }
        
        .btn-cancel {
            border: 1px solid var(--dark-gray);
            color: var(--dark-gray);
        }
        
        .btn-cancel:hover {
            background: var(--light-gray);
        }
        
        @media (max-width: 576px) {
            .bus-container {
                padding: 1.5rem;
                margin: 1rem;
            }
            
            .seat {
                height: 40px;
                font-size: 0.9rem;
            }
            
            .legend {
                gap: 1rem;
            }
        }
    </style>
</head>
<body>
    <br>
    <br>
    <div class="bus-container">
        <div class="header-text">
            <h4>Select Your Seats</h4>
            <p>Choose your preferred seats for a comfortable journey</p>
        </div>

        <form action="booking.php" method="POST" id="seatForm">
            <input type="hidden" name="schedule_id" value="<?= $schedule_id ?>">
            <input type="hidden" name="selected_seats" id="selectedSeatsInput" required>
            <input type="hidden" name="from" value="<?= $from ?>">
            <input type="hidden" name="to" value="<?= $to ?>">
            <input type="hidden" name="date" value="<?= $date ?>">

            <div class="bus-layout">
                <div class="bus-front">
                    <i class="fas fa-bus"></i>
                    <div class="text-muted small mt-1">Front of Bus</div>
                </div>
                
                <div class="seat-map">
                    <?php
                    for ($i = 1; $i <= $totalSeats; $i++) {
                        if (($i - 1) % $seatsPerRow === 2) {
                            echo '<div class="aisle"></div>';
                        }

                        $isBooked = in_array($i, $bookedSeats);
                        $isBlocked = in_array($i, $blockedSeats);
                        
                        if ($isBooked) {
                            $class = 'seat booked';
                            $title = 'Seat ' . $i . ' (Booked)';
                            $aria = 'aria-disabled="true"';
                        } elseif ($isBlocked) {
                            $class = 'seat blocked';
                            $title = 'Seat ' . $i . ' (Blocked by operator)';
                            $aria = 'aria-disabled="true"';
                        } else {
                            $class = 'seat';
                            $title = 'Seat ' . $i . ' (Available)';
                            $aria = 'aria-label="Seat ' . $i . '"';
                        }

                        echo "<div class='$class' data-seat='$i' title='$title' $aria>$i</div>";
                    }
                    ?>
                </div>
            </div>

            <div class="legend">
                <div class="legend-item available"><div class="box"></div>Available</div>
                <div class="legend-item selected"><div class="box"></div>Selected</div>
                <div class="legend-item booked"><div class="box"></div>Booked</div>
                <div class="legend-item blocked"><div class="box"></div>Blocked</div>
            </div>

            <div class="selection-info">
                <h6><i class="fas fa-info-circle me-2"></i>Your Selection</h6>
                <p id="seatCount">No seats selected yet</p>
            </div>

            <div class="text-center mt-4">
                <button type="submit" class="btn btn-primary btn-continue">
                    <i class="fas fa-arrow-right me-2"></i>Continue Booking
                </button>
                <a href="/bus-booking-website/passenger/dashboard.php?from=<?= $from ?>&to=<?= $to ?>&date=<?= $date ?>" class="btn btn-cancel ms-2">
                    <i class="fas fa-times me-2"></i>Cancel
                </a>
            </div>
        </form>
    </div>

    <script>
        const seatElements = document.querySelectorAll('.seat:not(.booked):not(.blocked)');
        const selectedSeatsInput = document.getElementById('selectedSeatsInput');
        const seatCountDisplay = document.getElementById('seatCount');
        const form = document.getElementById('seatForm');

        let selectedSeats = [];

        function updateSeatCount() {
            if (selectedSeats.length === 0) {
                seatCountDisplay.innerHTML = "<em>No seats selected yet</em>";
            } else {
                seatCountDisplay.innerHTML = `You've selected <strong>${selectedSeats.length}</strong> seat(s): <strong>${selectedSeats.join(', ')}</strong>`;
            }
            selectedSeatsInput.value = selectedSeats.join(',');
        }

        seatElements.forEach(seat => {
            seat.addEventListener('click', () => {
                const seatNumber = parseInt(seat.dataset.seat);
                
                if (selectedSeats.includes(seatNumber)) {
                    // Deselect seat
                    selectedSeats = selectedSeats.filter(s => s !== seatNumber);
                    seat.classList.remove('selected');
                } else {
                    // Select seat (with max limit)
                    if (selectedSeats.length >= 6) {
                        Swal.fire({
                            title: 'Maximum seats reached',
                            text: 'You can select a maximum of 6 seats per booking.',
                            icon: 'warning',
                            confirmButtonColor: '#4361ee'
                        });
                        return;
                    }
                    selectedSeats.push(seatNumber);
                    seat.classList.add('selected');
                    
                    // Add slight animation
                    seat.style.transform = 'scale(1.1)';
                    setTimeout(() => {
                        seat.style.transform = '';
                    }, 300);
                }
                updateSeatCount();
            });
        });

        form.addEventListener('submit', function(e) {
            if (selectedSeats.length === 0) {
                e.preventDefault();
                Swal.fire({
                    title: 'No seats selected',
                    text: 'Please select at least one seat to continue.',
                    icon: 'error',
                    confirmButtonColor: '#4361ee'
                });
            }
        });

        // Initialize
        updateSeatCount();
    </script>

    <!-- SweetAlert for better alerts -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
$conn->close();
?>