<?php
// ajax/get_operator.php
require_once '../../includes/config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

// Validate request method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Only GET requests are allowed']);
    exit;
}

// Validate and sanitize input
$operator_id = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : null;
if (!$operator_id || $operator_id < 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid operator ID']);
    exit;
}

try {
    // Get operator details with related user info
    $stmt = $conn->prepare("
        SELECT o.*, u.user_id, u.status as user_status 
        FROM operators o
        LEFT JOIN users u ON u.email = o.email AND u.user_type = 'operator'
        WHERE o.operator_id = ?
    ");
    
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param('i', $operator_id);
    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Operator not found']);
        exit;
    }

    $operator = $result->fetch_assoc();
    
    // Sanitize output
    $operator = array_map(function($value) {
        return is_string($value) ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : $value;
    }, $operator);
    
    // Format specific fields
    $operator['is_approved'] = (bool)$operator['is_approved'];
    if (isset($operator['rating'])) {
        $operator['rating'] = (float)$operator['rating'];
    }

    echo json_encode([
        'success' => true,
        'operator' => $operator
    ]);
    
} catch (Exception $e) {
    error_log("Error fetching operator ID {$operator_id}: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => DEBUG_MODE ? $e->getMessage() : 'Failed to load operator data'
    ]);
}