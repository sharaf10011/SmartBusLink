<?php
// admin/ajax/update_user.php
header('Content-Type: application/json');
session_start();
require_once '../../includes/config.php';

$response = ['success' => false, 'error' => ''];

try {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        throw new Exception('Invalid CSRF token');
    }

    // Check admin permissions
    if (!isset($_SESSION['loggedin']) || $_SESSION['user_type'] !== 'admin') {
        throw new Exception('Unauthorized access');
    }

    // Validate input
    $required = ['user_id', 'username', 'email', 'status'];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }

    // Sanitize inputs
    $user_id = (int)$_POST['user_id'];
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $status = trim($_POST['status']);

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email format');
    }

    // Check if status is valid
    $validStatuses = ['Active', 'Suspended', 'Unverified'];
    if (!in_array($status, $validStatuses)) {
        throw new Exception('Invalid status value');
    }

    // Update user in database
    $stmt = $conn->prepare("UPDATE users SET username = ?, email = ?, status = ? WHERE user_id = ?");
    if (!$stmt) {
        throw new Exception('Database prepare error: ' . $conn->error);
    }

    $stmt->bind_param("sssi", $username, $email, $status, $user_id);
    
    if (!$stmt->execute()) {
        throw new Exception('Database update failed: ' . $stmt->error);
    }

    $response['success'] = true;
    
} catch (Exception $e) {
    $response['error'] = $e->getMessage();
    error_log("User update error: " . $e->getMessage());
} finally {
    if (isset($stmt)) $stmt->close();
}

echo json_encode($response);
?>