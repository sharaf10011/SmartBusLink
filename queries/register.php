<?php
include('../includes/db_connect.php');
session_start();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Sanitize and capture form data
    $full_name = htmlspecialchars($_POST['full_name']);
    $email = htmlspecialchars($_POST['email']);
    $username = htmlspecialchars($_POST['username']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    // Validate passwords match
    if ($password !== $confirm_password) {
        $_SESSION['error_message'] = "Passwords do not match.";
        header("Location: ../register.php");
        exit();
    }

    // Check if the username already exists in the database
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        $_SESSION['error_message'] = "Username already taken. Please choose another one.";
        $stmt->close();
        header("Location: ../register.php");
        exit();
    }

    // Hash the password for security
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // Insert the new patient into the database
    $stmt = $conn->prepare("INSERT INTO users (username, password, role, email, full_name) VALUES (?, ?, 'patient', ?, ?)");
    $stmt->bind_param("ssss", $username, $hashed_password, $email, $full_name);

    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Registration successful! You can now log in.";
        header("Location: ../login.php");
        exit();
    } else {
        $_SESSION['error_message'] = "Registration failed. Please try again later.";
        header("Location: ../register.php");
        exit();
    }

    // Close the statement and database connection
    $stmt->close();
} else {
    // Redirect back to registration if accessed directly
    header("Location: ../register.php");
    exit();
}
?>
