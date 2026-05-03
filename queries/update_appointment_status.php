<?php
session_start();
include('../includes/db_connect.php');

// Verify if staff is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
    $_SESSION['error_message'] = "Unauthorized access.";
    header('Location: ../login.php');
    exit();
}

// Get appointment ID and new status from URL parameters
if (isset($_GET['id']) && isset($_GET['status'])) {
    $appointment_id = intval($_GET['id']);
    $new_status = $_GET['status'];

    // Update appointment status in the database
    $stmt = $conn->prepare("UPDATE appointments SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $new_status, $appointment_id);

    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Appointment status updated successfully.";
    } else {
        $_SESSION['error_message'] = "Failed to update appointment status.";
    }
    $stmt->close();
} else {
    $_SESSION['error_message'] = "Invalid request.";
}

// Redirect back to view appointments page
header('Location: ../staff/view_appointments.php');
exit();
?>
