<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0'); // Don't show errors to users
ini_set('log_errors', '1');
require_once '../../includes/config.php';
require_once '../../includes/functions.php';

// Initialize response array
$response = ['success' => false, 'error' => ''];

try {
    // Set headers and start output buffering
    header('Content-Type: application/json');
    ob_start();

    // Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Only POST requests are allowed', 405);
    }

    // Verify CSRF token
    verify_csrf_token();

    // Validate session and permissions
    session_start();
    if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
        throw new RuntimeException('Unauthorized access', 403);
    }

    // Validate operator ID
    $operator_id = filter_input(INPUT_POST, 'operator_id', FILTER_VALIDATE_INT);
    if (!$operator_id || $operator_id < 1) {
        throw new RuntimeException('Invalid operator ID', 400);
    }

    // Start transaction
    $conn->begin_transaction();

    // 1. Get current operator data
    $current_data = get_current_operator_data($conn, $operator_id);
    
    // 2. Validate all update fields
    $update_data = validate_update_fields($_POST, $current_data);
    
    // 3. Check email uniqueness if changing email
    if ($update_data['email_changed']) {
        check_email_uniqueness($conn, $update_data['fields']['email'], $current_data['email']);
    }
    
    // 4. Update operator record
    update_operator($conn, $operator_id, $update_data);
    
    // 5. Update related user record if email changed
    if ($update_data['email_changed']) {
        update_user_email($conn, $current_data['email'], $update_data['fields']['email']);
    }
    
    // 6. Handle password update if provided
    if (!empty($_POST['password'])) {
        update_operator_password($conn, $operator_id, $_POST['password']);
    }
    
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Operator updated successfully',
        'updated_fields' => array_keys($update_data['fields'])
    ]);

} catch (PDOException $e) {
    $conn->rollback();
    handle_database_error($e);
} catch (RuntimeException $e) {
    $conn->rollback();
    handle_application_error($e);
} finally {
    // Ensure no output buffer issues
    if (ob_get_length()) {
        ob_end_clean();
    }
}

// Helper functions:

function get_current_operator_data(mysqli $conn, int $operator_id): array {
    $stmt = $conn->prepare("SELECT * FROM operators WHERE operator_id = ?");
    $stmt->bind_param('i', $operator_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new RuntimeException('Operator not found', 404);
    }
    
    return $result->fetch_assoc();
}

function validate_update_fields(array $post_data, array $current_data): array {
    $allowed_fields = [
        'company_name' => 'string',
        'contact_person' => 'string', 
        'email' => 'email',
        'phone' => 'string',
        'address' => 'string',
        'license_number' => 'string',
        'is_approved' => 'int'
    ];
    
    $fields = [];
    $types = '';
    $values = [];
    $email_changed = false;
    
    foreach ($allowed_fields as $field => $type) {
        if (!isset($post_data[$field])) continue;
        
        $value = $post_data[$field];
        $value = validate_field($field, $value, $type, $current_data);
        
        if ($field === 'email' && $value !== $current_data['email']) {
            $email_changed = true;
        }
        
        $fields[$field] = $value;
        $types .= $type === 'int' ? 'i' : 's';
        $values[] = $value;
    }
    
    if (empty($fields)) {
        throw new RuntimeException('No valid fields provided for update', 400);
    }
    
    return [
        'fields' => $fields,
        'types' => $types,
        'values' => $values,
        'email_changed' => $email_changed
    ];
}

function validate_field(string $field, $value, string $type, array $current_data) {
    switch ($type) {
        case 'email':
            if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Invalid email format', 400);
            }
            return trim($value);
            
        case 'int':
            if (!in_array((int)$value, [-1, 0, 1], true)) {
                throw new RuntimeException('Invalid approval status (must be -1, 0, or 1)', 400);
            }
            return (int)$value;
            
        case 'string':
            $value = trim($value);
            if ($value === '' && $current_data[$field] !== null) {
                throw new RuntimeException("$field cannot be empty", 400);
            }
            return $value;
            
        default:
            return $value;
    }
}

function check_email_uniqueness(mysqli $conn, string $new_email, string $current_email): void {
    if ($new_email === $current_email) return;
    
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
    $stmt->bind_param('s', $new_email);
    $stmt->execute();
    
    if ($stmt->get_result()->num_rows > 0) {
        throw new RuntimeException('Email already in use by another user', 409);
    }
}

function update_operator(mysqli $conn, int $operator_id, array $update_data): void {
    $set_clause = implode(', ', array_map(
        fn($field) => "$field = ?", 
        array_keys($update_data['fields'])
    ));
    
    $sql = "UPDATE operators SET $set_clause WHERE operator_id = ?";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        throw new RuntimeException('Failed to prepare operator update: ' . $conn->error, 500);
    }
    
    // Add operator_id to values
    $values = $update_data['values'];
    $values[] = $operator_id;
    $types = $update_data['types'] . 'i';
    
    $stmt->bind_param($types, ...$values);
    
    if (!$stmt->execute()) {
        throw new RuntimeException('Failed to execute operator update: ' . $stmt->error, 500);
    }
}

function update_user_email(mysqli $conn, string $old_email, string $new_email): void {
    $stmt = $conn->prepare("UPDATE users SET email = ? WHERE email = ?");
    $stmt->bind_param('ss', $new_email, $old_email);
    
    if (!$stmt->execute()) {
        throw new RuntimeException('Failed to update user email: ' . $stmt->error, 500);
    }
}

function update_operator_password(mysqli $conn, int $operator_id, string $password): void {
    if (strlen($password) < 8) {
        throw new RuntimeException('Password must be at least 8 characters', 400);
    }
    
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE email = (SELECT email FROM operators WHERE operator_id = ?)");
    $stmt->bind_param('si', $hashed_password, $operator_id);
    
    if (!$stmt->execute()) {
        throw new RuntimeException('Failed to update password: ' . $stmt->error, 500);
    }
}

function handle_database_error(PDOException $e): void {
    error_log("Database error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database operation failed',
        'error_code' => $e->getCode(),
        'error_info' => DEBUG_MODE ? $e->getMessage() : null
    ]);
}

function handle_application_error(RuntimeException $e): void {
    error_log("Application error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'error_code' => $e->getCode()
    ]);
}