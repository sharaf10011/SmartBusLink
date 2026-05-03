<?php
require_once '../includes/config.php';
require_once '../includes/payment_gateway.php';
require_once '../includes/email_service.php';
session_start();

// Validate session and input
if (!isset($_SESSION['user_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /login.php');
    exit();
}

// Validate CSRF token
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    die("Invalid CSRF token");
}

try {
    $conn->begin_transaction();
    
    // 1. Get and validate input
    $schedule_id = (int)$_POST['schedule_id'];
    $selectedSeats = explode(',', $_POST['selected_seats']);
    $seatCount = count($selectedSeats);
    $passenger_id = $_SESSION['user_id'];
    $passenger_name = $conn->real_escape_string($_POST['full_name']);
    $phone = $conn->real_escape_string($_POST['phone']);
    $email = $conn->real_escape_string($_POST['email'] ?? '');
    $from_location = $conn->real_escape_string($_POST['from'] ?? '');
    $to_location = $conn->real_escape_string($_POST['to'] ?? '');
    $travel_date = $conn->real_escape_string($_POST['date']);

    // 2. Get schedule details with lock
    $stmt = $conn->prepare("
        SELECT s.departure_time, s.price, s.available_seats, 
               r.origin_city, r.destination_city
        FROM schedules s
        JOIN routes r ON s.route_id = r.route_id
        WHERE s.schedule_id = ? AND s.is_active = 1
        FOR UPDATE
    ");
    $stmt->bind_param("i", $schedule_id);
    $stmt->execute();
    $schedule = $stmt->get_result()->fetch_assoc();
    
    if (!$schedule) {
        throw new Exception("Schedule not available");
    }

    // 3. Validate seat availability
    if ($seatCount > $schedule['available_seats']) {
        throw new Exception("Not enough seats available");
    }

    // 4. Calculate total amount
    $total_amount = $seatCount * $schedule['price'];
    $booking_ref = 'BK-' . strtoupper(uniqid());
    $seat_numbers = implode(', ', $selectedSeats);

    // 5. Process payment
    $payment_method = $_POST['payment_method'];
    $transaction_id = null;
    $payment_status = 'pending';

    switch ($payment_method) {
        case 'wallet':
            // Process wallet payment
            $stmt = $conn->prepare("
                UPDATE user_wallets 
                SET balance = balance - ? 
                WHERE user_id = ? AND balance >= ?
            ");
            $stmt->bind_param("dii", $total_amount, $passenger_id, $total_amount);
            $stmt->execute();
            
            if ($stmt->affected_rows === 0) {
                throw new Exception("Insufficient wallet balance");
            }
            
            $transaction_id = 'WALLET-' . uniqid();
            $payment_status = 'paid';
            break;

        case 'credit_card':
            // Process card payment
            $card_data = [
                'number' => str_replace(' ', '', $_POST['card_number']),
                'expiry' => str_replace('-', '', $_POST['expiry_date']),
                'cvv' => $_POST['cvv'],
                'name' => $_POST['card_holder']
            ];
            
            $gateway_response = processGenieBusinessPayment($card_data, $total_amount, $booking_ref);
            
            if (!$gateway_response['success']) {
                throw new Exception("Payment failed: " . $gateway_response['message']);
            }
            
            $transaction_id = $gateway_response['transaction_id'];
            $payment_status = 'paid';
            break;

        default:
            throw new Exception("Invalid payment method");
    }

    // 6. Create booking record
    $stmt = $conn->prepare("
        INSERT INTO bookings (
            passenger_id, passenger_name, phone, email, schedule_id,
            booking_reference, total_amount, booking_status, payment_status,
            payment_method, seat_number, from_location, to_location, travel_date
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'confirmed', ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->bind_param(
        "isssisdssssss",
        $passenger_id, $passenger_name, $phone, $email, $schedule_id,
        $booking_ref, $total_amount, $payment_status, $payment_method,
        $seat_numbers, $from_location, $to_location, $travel_date
    );
    $stmt->execute();
    $booking_id = $stmt->insert_id;

    // 7. Update available seats
    $stmt = $conn->prepare("
        UPDATE schedules 
        SET available_seats = available_seats - ? 
        WHERE schedule_id = ?
    ");
    $stmt->bind_param("ii", $seatCount, $schedule_id);
    $stmt->execute();

    $conn->commit();

    // 8. Send confirmation
    sendBookingConfirmation($email, $booking_ref, [
        'passenger_name' => $passenger_name,
        'booking_ref' => $booking_ref,
        'from_location' => $from_location ?: $schedule['origin_city'],
        'to_location' => $to_location ?: $schedule['destination_city'],
        'travel_date' => $travel_date,
        'seat_numbers' => $seat_numbers,
        'total_amount' => $total_amount
    ]);

    header("Location: booking-confirmation.php?ref=" . urlencode($booking_ref));
    exit();

} catch (Exception $e) {
    $conn->rollback();
    error_log("Payment Error: " . $e->getMessage());
    header("Location: payment.php?error=" . urlencode($e->getMessage()) . 
           "&schedule_id=" . $schedule_id . 
           "&selected_seats=" . urlencode($_POST['selected_seats']));
    exit();
}