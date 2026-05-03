<?php
include('../includes/db_connect.php');
session_start();

// Check if the user is logged in and is a patient
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'patient') {
    $_SESSION['error_message'] = "You must be logged in as a patient to schedule an appointment.";
    header('Location: ../login.php');
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Sanitize form inputs
    $patient_id = $_SESSION['user_id'];
    $staff_id = intval($_POST['staff_id']); // Staff ID should be an integer
    $appointment_date = htmlspecialchars($_POST['appointment_date']);
    $reason = htmlspecialchars($_POST['reason']);

    // Validate the form inputs
    if (empty($appointment_date) || empty($reason)) {
        $_SESSION['error_message'] = "All fields are required.";
        header('Location: ../patient/appointments.php');
        exit();
    }

    // Check if staff member exists
    $staff_check = $conn->prepare("SELECT id FROM users WHERE id = ? AND role = 'staff'");
    $staff_check->bind_param("i", $staff_id);
    $staff_check->execute();
    $staff_check->store_result();

    if ($staff_check->num_rows === 0) {
        $_SESSION['error_message'] = "Selected staff member does not exist.";
        header('Location: ../patient/appointments.php');
        exit();
    }

    // Insert appointment into the database
    $stmt = $conn->prepare("INSERT INTO appointments (patient_id, staff_id, appointment_date, reason, status) VALUES (?, ?, ?, ?, 'Pending')");
    $stmt->bind_param("iiss", $patient_id, $staff_id, $appointment_date, $reason);

    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Appointment scheduled successfully. Await confirmation.";
        header('Location: ../patient/appointments.php');
        exit();
    } else {
        $_SESSION['error_message'] = "Failed to schedule appointment. Please try again later.";
        header('Location: ../patient/appointments.php');
        exit();
    }

    $stmt->close();
} else {
    // Redirect to appointments page if accessed directly
    header('Location: ../patient/appointments.php');
    exit();
}
?>
