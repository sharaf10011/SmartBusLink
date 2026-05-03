<?php
require_once '../includes/config.php';
session_start();

// Redirect if accessed without proper POST data
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['schedule_id'], $_POST['selected_seats'], $_POST['date'])) {
    header('Location: /passenger/search-results.php?error=invalid_access');
    exit();
}

$schedule_id = (int) $_POST['schedule_id'];
$date = htmlspecialchars($_POST['date']);
$selectedSeats = explode(',', $_POST['selected_seats']);

// Validate seats: ensure numeric and > 0
$validSeats = array_filter($selectedSeats, function ($seat) {
    return is_numeric($seat) && (int)$seat > 0;
});

if (empty($validSeats)) {
    header("Location: seat-selection.php?schedule_id=$schedule_id&error=no_seats_selected");
    exit();
}

// Fetch schedule info
$stmt = $conn->prepare("
    SELECT s.departure_time, s.price, b.name AS bus_name, b.registration_number, 
           r.origin_city, r.destination_city
    FROM schedules s
    JOIN buses b ON s.bus_id = b.bus_id
    JOIN routes r ON s.route_id = r.route_id
    WHERE s.schedule_id = ?
");
$stmt->bind_param("i", $schedule_id);
$stmt->execute();
$schedule = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$schedule) {
    header('Location: /passenger/search-results.php?error=invalid_schedule');
    exit();
}

// Calculate total amount
$seatCount = count($validSeats);
$pricePerSeat = $schedule['price']; // Using price from schedules table
$totalAmount = $seatCount * $pricePerSeat;

$from = $schedule['origin_city'];
$to = $schedule['destination_city'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Passenger Booking - SmartBusLink</title>

  <!-- Bootstrap CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />

  <!-- Google Fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

  <style>
    :root {
      --primary-color: #4361ee;
      --secondary-color: #3f37c9;
      --accent-color: #4cc9f0;
      --success-color: #38b000;
      --light-bg: #f8f9fa;
      --dark-text: #212529;
      --muted-text: #6c757d;
    }
    
    body {
      background: linear-gradient(135deg, #f0f4ff 0%, #e6f2ff 100%);
      font-family: 'Inter', sans-serif;
      min-height: 100vh;
      color: var(--dark-text);
    }

    .fade-in {
      animation: fadeInUp 0.6s ease-out;
    }

    @keyframes fadeInUp {
      0% {
        opacity: 0;
        transform: translateY(20px);
      }
      100% {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .card {
      border-radius: 16px;
      border: none;
      overflow: hidden;
      transition: transform 0.3s ease, box-shadow 0.3s ease;
    }

    .card:hover {
      transform: translateY(-5px);
      box-shadow: 0 12px 24px rgba(0, 0, 0, 0.1);
    }

    .card-header {
      background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
      padding: 1.5rem;
    }

    .card-header h4 {
      font-weight: 600;
      letter-spacing: -0.5px;
      margin: 0;
    }

    .card-body {
      padding: 2rem;
    }

    .form-control {
      border-radius: 8px;
      padding: 0.75rem 1rem;
      border: 1px solid #dee2e6;
      transition: all 0.3s ease;
    }

    .form-control:focus {
      border-color: var(--primary-color);
      box-shadow: 0 0 0 0.2rem rgba(67, 97, 238, 0.15);
    }

    .form-label {
      font-weight: 500;
      margin-bottom: 0.5rem;
      color: var(--dark-text);
    }

    .btn {
      border-radius: 8px;
      padding: 0.75rem 1.5rem;
      font-weight: 600;
      letter-spacing: 0.5px;
      transition: all 0.3s ease;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
    }

    .btn-success {
      background-color: var(--success-color);
      border-color: var(--success-color);
    }

    .btn-success:hover {
      background-color: #2b8a00;
      border-color: #2b8a00;
      transform: translateY(-2px);
    }

    .btn-secondary {
      background-color: #6c757d;
      border-color: #6c757d;
    }

    .btn-secondary:hover {
      background-color: #5a6268;
      border-color: #5a6268;
      transform: translateY(-2px);
    }

    .list-group {
      border-radius: 12px;
      overflow: hidden;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    }

    .list-group-item {
      padding: 1rem 1.5rem;
      border-color: rgba(0, 0, 0, 0.05);
      display: flex;
      align-items: center;
    }

    .list-group-item i {
      margin-right: 12px;
      color: var(--primary-color);
      width: 20px;
      text-align: center;
    }

    .list-group-item strong {
      color: var(--dark-text);
      font-weight: 600;
    }

    .trip-summary-title {
      font-weight: 600;
      color: var(--primary-color);
      margin-bottom: 1.5rem;
      position: relative;
      padding-bottom: 0.5rem;
    }

    .trip-summary-title::after {
      content: '';
      position: absolute;
      bottom: 0;
      left: 0;
      width: 50px;
      height: 3px;
      background: linear-gradient(90deg, var(--primary-color) 0%, var(--accent-color) 100%);
      border-radius: 3px;
    }

    .required-field::after {
      content: '*';
      color: #dc3545;
      margin-left: 4px;
    }

    .invalid-feedback {
      font-size: 0.85rem;
    }

    @media (max-width: 768px) {
      .card-body {
        padding: 1.5rem;
      }
      
      .d-flex.justify-content-between {
        flex-direction: column;
        gap: 1rem;
      }
      
      .btn {
        width: 100%;
      }
    }
  </style>
</head>
<body>

<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-lg-8">
      <div class="card shadow-lg fade-in">
        <div class="card-header text-white">
          <h4 class="mb-0"><i class="fas fa-user-check me-2"></i>Passenger Details</h4>
        </div>
        <div class="card-body">
          <h5 class="trip-summary-title"><i class="fas fa-route me-2"></i>Trip Summary</h5>
          <ul class="list-group mb-4">
            <li class="list-group-item">
              <i class="fas fa-bus"></i>
              <span>Bus: <strong><?= htmlspecialchars($schedule['bus_name']) ?> (<?= htmlspecialchars($schedule['registration_number']) ?>)</strong></span>
            </li>
            <li class="list-group-item">
              <i class="fas fa-route"></i>
              <span>Route: <strong><?= htmlspecialchars($from) ?> → <?= htmlspecialchars($to) ?></strong></span>
            </li>
            <li class="list-group-item">
              <i class="far fa-calendar-alt"></i>
              <span>Date: <strong><?= htmlspecialchars($date) ?></strong> | <i class="far fa-clock"></i> Departure: <strong><?= htmlspecialchars($schedule['departure_time']) ?></strong></span>
            </li>
            <li class="list-group-item">
              <i class="fas fa-chair"></i>
              <span>Seats Selected: <strong><?= implode(', ', $validSeats) ?></strong></span>
            </li>
            <li class="list-group-item">
              <i class="fas fa-tag"></i>
              <span>Price per seat: <strong>Rs. <?= number_format($pricePerSeat, 2) ?></strong></span>
            </li>
            <li class="list-group-item list-group-item-primary">
              <i class="fas fa-money-bill-wave"></i>
              <span>Total Amount: <strong>Rs. <?= number_format($totalAmount, 2) ?></strong></span>
            </li>
          </ul>

          <form action="payment.php" method="POST" novalidate class="needs-validation">
            <!-- Hidden booking details -->
            <input type="hidden" name="schedule_id" value="<?= $schedule_id ?>">
            <input type="hidden" name="selected_seats" value="<?= implode(',', $validSeats) ?>">
            <input type="hidden" name="from" value="<?= htmlspecialchars($from) ?>">
            <input type="hidden" name="to" value="<?= htmlspecialchars($to) ?>">
            <input type="hidden" name="date" value="<?= htmlspecialchars($date) ?>">

            <div class="mb-4">
              <label for="full_name" class="form-label required-field">Passenger Full Name</label>
              <div class="input-group">
                <span class="input-group-text"><i class="fas fa-user"></i></span>
                <input type="text" name="full_name" id="full_name" class="form-control" required minlength="2" maxlength="100" placeholder="Enter full name" />
              </div>
              <div class="invalid-feedback">Please enter a valid full name (min 2 characters).</div>
            </div>

            <div class="mb-4">
              <label for="phone" class="form-label required-field">Contact Number</label>
              <div class="input-group">
                <span class="input-group-text"><i class="fas fa-phone"></i></span>
                <input type="tel" name="phone" id="phone" class="form-control" required pattern="[0-9]{10}" placeholder="e.g. 0771234567" />
              </div>
              <div class="invalid-feedback">Please enter a valid 10-digit phone number.</div>
            </div>

            <div class="mb-4">
              <label for="email" class="form-label">Email Address (optional)</label>
              <div class="input-group">
                <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                <input type="email" name="email" id="email" class="form-control" placeholder="example@mail.com" />
              </div>
              <small class="text-muted">For booking confirmation and updates</small>
            </div>

            <div class="d-flex justify-content-between mt-4 pt-2">
              <a href="seat-selection.php?schedule_id=<?= $schedule_id ?>&date=<?= urlencode($date) ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Seat Selection
              </a>
              <button type="submit" class="btn btn-success">
                Proceed to Payment<i class="fas fa-arrow-right ms-2"></i>
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Bootstrap Bundle with Popper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- Form validation script -->
<script>
(() => {
  'use strict';
  
  // Fetch all the forms we want to apply custom Bootstrap validation styles to
  const forms = document.querySelectorAll('.needs-validation');
  
  // Loop over them and prevent submission
  Array.from(forms).forEach(form => {
    form.addEventListener('submit', event => {
      if (!form.checkValidity()) {
        event.preventDefault();
        event.stopPropagation();
      }
      
      form.classList.add('was-validated');
    }, false);
  });
  
  // Add real-time validation for inputs
  document.querySelectorAll('.form-control').forEach(input => {
    input.addEventListener('input', () => {
      if (input.checkValidity()) {
        input.classList.remove('is-invalid');
        input.classList.add('is-valid');
      } else {
        input.classList.remove('is-valid');
      }
    });
  });
})();
</script>

</body>
</html>