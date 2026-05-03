<?php
require_once '../includes/config.php';
require_once '../includes/payment_gateway.php';
session_start();

// Get callback data
$transactionId = $_POST['transaction_id'] ?? $_GET['transaction_id'] ?? null;
$status = $_POST['status'] ?? $_GET['status'] ?? null;
$orderId = $_POST['order_id'] ?? $_GET['order_id'] ?? null;

if (!$transactionId || !$status || !$orderId) {
    die("Invalid callback parameters");
}

// Verify the payment with Genie Business
$gateway = new GenieBusinessGateway();
$paymentVerified = $gateway->verifyPayment($transactionId);

if (!$paymentVerified) {
    // Log failed verification
    error_log("Payment verification failed for transaction: $transactionId");
    header("Location: payment-failed.php?transaction_id=$transactionId&reason=verification_failed");
    exit();
}

// Update booking status in database
$conn->begin_transaction();

try {
    $stmt = $conn->prepare("
        UPDATE bookings 
        SET status = 'confirmed', 
            transaction_id = ?,
            updated_at = NOW()
        WHERE booking_reference = ? AND status = 'pending'
    ");
    $stmt->bind_param("ss", $transactionId, $orderId);
    $stmt->execute();
    
    if ($stmt->affected_rows === 0) {
        throw new Exception("Booking not found or already confirmed");
    }
    
    // Record payment transaction
    $paymentStmt = $conn->prepare("
        INSERT INTO payment_transactions 
        (booking_id, payment_method, amount, gateway_reference, status, response_data)
        SELECT booking_id, 'credit_card', total_amount, ?, 'completed', ?
        FROM bookings
        WHERE booking_reference = ?
    ");
    $responseData = json_encode(['status' => $status, 'transaction_id' => $transactionId]);
    $paymentStmt->bind_param("sss", $transactionId, $responseData, $orderId);
    $paymentStmt->execute();
    $paymentStmt->close();
    
    $conn->commit();
    
    // Redirect to success page
    header("Location: booking-confirmation.php?ref=" . urlencode($orderId));
    exit();
    
} catch (Exception $e) {
    $conn->rollback();
    error_log("Callback processing error: " . $e->getMessage());
    header("Location: payment-failed.php?transaction_id=$transactionId&reason=processing_error");
    exit();
}