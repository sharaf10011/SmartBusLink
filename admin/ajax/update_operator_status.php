<?php
// Enable strict error reporting
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0'); // Don't display errors to users
ini_set('log_errors', '1');

// Set headers first to prevent any output issues
header('Content-Type: application/json');

// Start output buffering
ob_start();

try {
    // 1. Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Invalid request method', 405);
    }

    // 2. Start session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // 3. Validate CSRF token
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        throw new RuntimeException('CSRF token validation failed', 403);
    }

    // 4. Validate permissions
    if (empty($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
        throw new RuntimeException('Unauthorized access', 403);
    }

    // 5. Validate and sanitize input
    $operator_id = filter_input(INPUT_POST, 'operator_id', FILTER_VALIDATE_INT);
    $action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_SPECIAL_CHARS);
    $reason = filter_input(INPUT_POST, 'reason', FILTER_SANITIZE_SPECIAL_CHARS) ?? '';

    if (!$operator_id || $operator_id < 1) {
        throw new RuntimeException('Invalid operator ID', 400);
    }

    if (!in_array($action, ['approve', 'reject', 'pending'])) {
        throw new RuntimeException('Invalid action', 400);
    }

    // 6. Database operations
    require_once '../../includes/config.php';
    
    // Verify database connection
    if ($conn->connect_error) {
        throw new RuntimeException('Database connection failed', 500);
    }

    $conn->begin_transaction();

    try {
        // Determine new status
        $new_status = match ($action) {
            'approve' => 1,
            'reject' => -1,
            'pending' => 0
        };

        // Update operator status
        $stmt = $conn->prepare("UPDATE operators SET is_approved = ? WHERE operator_id = ?");
        if (!$stmt) {
            throw new RuntimeException('Failed to prepare statement: ' . $conn->error, 500);
        }
        
        $stmt->bind_param('ii', $new_status, $operator_id);
        if (!$stmt->execute()) {
            throw new RuntimeException('Failed to update operator: ' . $stmt->error, 500);
        }

        // Log the action
        $log_stmt = $conn->prepare(
            "INSERT INTO operator_status_logs 
            (operator_id, admin_id, action, reason) 
            VALUES (?, ?, ?, ?)"
        );
        $log_stmt->bind_param('iiss', $operator_id, $_SESSION['user_id'], $action, $reason);
        $log_stmt->execute();

        // If approving, update related user account
        if ($action === 'approve') {
            $user_stmt = $conn->prepare(
                "UPDATE users 
                SET status = 'Active' 
                WHERE email = (SELECT email FROM operators WHERE operator_id = ?)"
            );
            $user_stmt->bind_param('i', $operator_id);
            $user_stmt->execute();
        }

        $conn->commit();

        // Return success response
        echo json_encode([
            'success' => true,
            'message' => "Operator {$action}d successfully",
            'data' => [
                'operator_id' => $operator_id,
                'new_status' => $new_status
            ]
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (RuntimeException $e) {
    // Clean any output
    ob_end_clean();
    
    // Set proper HTTP status
    http_response_code($e->getCode() >= 400 ? $e->getCode() : 500);
    
    // Log the error
    error_log("Operator status update error [{$e->getCode()}]: {$e->getMessage()}");
    
    // Return error response
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'error_code' => $e->getCode()
    ]);
    
} finally {
    // Ensure no extra output
    if (ob_get_length()) {
        ob_end_flush();
    }
    exit;
}