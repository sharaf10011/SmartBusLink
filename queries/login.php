<?php
include('../includes/db_connect.php');
session_start();


ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Sanitize and capture form data
    $username = htmlspecialchars($_POST['username']);
    $password = $_POST['password'];

    // Validate if the user exists
    $stmt = $conn->prepare("SELECT id, password, role FROM users WHERE username = ?");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $stmt->store_result();

    // If user exists
    if ($stmt->num_rows > 0) {
        $stmt->bind_result($id, $hashed_password, $role);
        $stmt->fetch();

        // Verify the password
        if (password_verify($password, $hashed_password)) {
            // Set session variables for logged-in user
            session_regenerate_id(true); // Security measure to prevent session fixation
            $_SESSION['user_id'] = $id;
            $_SESSION['username'] = $username;
            $_SESSION['role'] = $role;

            // Redirect users based on their roles
            switch ($role) {
                case 'admin':
                    header('Location: ../admin/dashboard.php');
                    break;
                case 'staff':
                    header('Location: ../staff/dashboard.php');
                    break;
                case 'patient':
                    header('Location: ../patient/dashboard.php');
                    break;
                default:
                    $_SESSION['error_message'] = "Unknown user role.";
                    header('Location: ../login.php');
                    break;
            }
            exit();
        } else {
            // Incorrect password
            $_SESSION['error_message'] = "Incorrect password. Please try again.";
            header('Location: ../login.php');
            exit();
        }
    } else {
        // No user found
        $_SESSION['error_message'] = "No account found with that username.";
        header('Location: ../login.php');
        exit();
    }

    $stmt->close();
} else {
    // Redirect back to login if accessed directly
    header('Location: ../login.php');
    exit();
}
?>
