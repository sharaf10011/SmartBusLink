<?php
include('../includes/db_connect.php');
session_start();

// Check if the user is logged in and has a patient role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'patient') {
    $_SESSION['error_message'] = "You must be logged in as a patient to submit feedback.";
    header('Location: ../login.php');
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Sanitize form data
    $patient_id = $_SESSION['user_id'];
    $message = htmlspecialchars($_POST['message']);

    // Check if the feedback message is empty
    if (empty($message)) {
        $_SESSION['error_message'] = "Feedback message cannot be empty.";
        header('Location: ../patient/feedback.php');
        exit();
    }

    // Insert feedback into the database
    $stmt = $conn->prepare("INSERT INTO feedback (patient_id, message) VALUES (?, ?)");
    $stmt->bind_param("is", $patient_id, $message);

    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Thank you for your feedback!";
        header('Location: ../patient/feedback.php');
        exit();
    } else {
        $_SESSION['error_message'] = "Failed to submit feedback. Please try again later.";
        header('Location: ../patient/feedback.php');
        exit();
    }

    $stmt->close();
} else {
    // Redirect to feedback page if accessed directly
    header('Location: ../patient/feedback.php');
    exit();
}
?>
