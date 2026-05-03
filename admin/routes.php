<?php
declare(strict_types=1);

// Security headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

// Session configuration
session_start([
    'cookie_lifetime' => 1800, // 30 minutes
    'cookie_secure'   => true, // Only send over HTTPS
    'cookie_httponly' => true, // Prevent JavaScript access
    'use_strict_mode' => true  // Prevent session fixation
]);

// Include database configuration
require_once __DIR__ . '/../includes/config.php';

// Verify admin access
function verify_admin_access(bool $check_csrf = false): void {
    if (!isset($_SESSION['loggedin'])) {
        header("Location: ../login.php");
        exit;
    }
    
    if ($_SESSION['user_type'] !== 'admin') {
        header("Location: ../dashboard.php");
        exit;
    }
    
    if ($check_csrf && !verify_csrf_token()) {
        die("Invalid CSRF token");
    }
}

// CSRF token verification
function verify_csrf_token(): bool {
    return !empty($_POST['csrf_token']) && 
           hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

// Initialize CSRF token if not exists
if (empty($_SESSION['csrf_token']) || ($_SERVER['REQUEST_METHOD'] === 'GET' && empty($_POST))) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Verify admin access
verify_admin_access();

// Initialize variables
$action = $_GET['action'] ?? 'list';
$route_id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Main controller logic
try {
    switch ($action) {
        case 'create':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                handle_create_route();
            }
            break;
            
        case 'edit':
            if (!isset($route_id)) {
                throw new InvalidArgumentException("Route ID is required");
            }
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                handle_edit_route($route_id);
            } else {
                $route_data = get_route_data($route_id);
            }
            break;
            
        case 'delete':
            if (!isset($route_id)) {
                throw new InvalidArgumentException("Route ID is required");
            }
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                handle_delete_route($route_id);
            } else {
                // Show confirmation page for GET requests
                $route_data = get_route_data($route_id);
            }
            break;
            
        case 'toggle-status':
            if (!isset($route_id)) {
                throw new InvalidArgumentException("Route ID is required");
            }
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                handle_toggle_route_status($route_id);
            } else {
                throw new RuntimeException("Invalid request method for toggle-status");
            }
            break;
            
        default:
            $routes = display_routes_list();
    }
} catch (InvalidArgumentException $e) {
    $error_message = $e->getMessage();
    http_response_code(400);
} catch (RuntimeException $e) {
    $error_message = $e->getMessage();
    http_response_code(500);
} catch (Exception $e) {
    $error_message = "An unexpected error occurred";
    error_log($e->getMessage());
    http_response_code(500);
}
// Include navbar
include 'navbar.php';

// Display messages
if (!empty($success_message)): ?>
    <div class="alert alert-success"><?= escape($success_message) ?></div>
<?php endif; ?>
<?php if (!empty($error_message)): ?>
    <div class="alert alert-danger"><?= escape($error_message) ?></div>
<?php endif; ?>

<?php
// Display appropriate view based on action
switch ($action) {
    case 'create':
    case 'edit':
        include 'routes_form.php';
        break;
    default:
        include 'routes_list.php';
}

// Handler functions
function handle_create_route(): void {
    global $conn;
    
    verify_admin_access(true); // Check CSRF for POST actions
    
    $data = validate_route_data($_POST);
    check_route_uniqueness($data['origin_city'], $data['origin_terminal'], 
                         $data['destination_city'], $data['destination_terminal']);
    
    $stmt = $conn->prepare("INSERT INTO routes (
        origin_city, origin_terminal, 
        destination_city, destination_terminal,
        distance_km, estimated_duration_min, is_active
    ) VALUES (?, ?, ?, ?, ?, ?, ?)");
    
    $stmt->bind_param(
        'ssssdii',
        $data['origin_city'],
        $data['origin_terminal'],
        $data['destination_city'],
        $data['destination_terminal'],
        $data['distance_km'],
        $data['estimated_duration_min'],
        $data['is_active']
    );
    
    if (!$stmt->execute()) {
        throw new RuntimeException("Failed to create route: " . $stmt->error);
    }
    
    $_SESSION['success_message'] = 'Route created successfully!';
    redirect('routes.php');
}

function handle_edit_route(int $route_id): void {
    global $conn;
    
    verify_admin_access(true); // Check CSRF for POST actions
    
    $data = validate_route_data($_POST);
    check_route_uniqueness($data['origin_city'], $data['origin_terminal'], 
                         $data['destination_city'], $data['destination_terminal'], $route_id);
    
    $stmt = $conn->prepare("UPDATE routes SET
        origin_city = ?,
        origin_terminal = ?,
        destination_city = ?,
        destination_terminal = ?,
        distance_km = ?,
        estimated_duration_min = ?,
        is_active = ?,
        updated_at = CURRENT_TIMESTAMP
        WHERE route_id = ?
    ");
    
    $stmt->bind_param(
        'ssssdiii',
        $data['origin_city'],
        $data['origin_terminal'],
        $data['destination_city'],
        $data['destination_terminal'],
        $data['distance_km'],
        $data['estimated_duration_min'],
        $data['is_active'],
        $route_id
    );
    
    if (!$stmt->execute()) {
        throw new RuntimeException("Failed to update route: " . $stmt->error);
    }
    
    $_SESSION['success_message'] = 'Route updated successfully!';
    redirect('routes.php');
}

function handle_delete_route(int $route_id): void {
    global $conn;
    
    verify_admin_access(true); // Check CSRF for GET actions with side effects
    
    // Check if route has associated trips
    $stmt = $conn->prepare("SELECT COUNT(*) FROM trips WHERE route_id = ?");
    $stmt->bind_param('i', $route_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_row();
    
    if ($result[0] > 0) {
        throw new RuntimeException("Cannot delete route with associated trips");
    }
    
    $stmt = $conn->prepare("DELETE FROM routes WHERE route_id = ?");
    $stmt->bind_param('i', $route_id);
    
    if (!$stmt->execute()) {
        throw new RuntimeException("Failed to delete route: " . $stmt->error);
    }
    
    $_SESSION['success_message'] = 'Route deleted successfully!';
    redirect('routes.php');
}

function handle_toggle_route_status(int $route_id): void {
    if (empty($_GET['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_GET['csrf_token'])) {
        die("Invalid CSRF token");
    }

    global $conn;
    verify_admin_access(); // No need to check again since CSRF is validated here

    $stmt = $conn->prepare("UPDATE routes SET is_active = NOT is_active WHERE route_id = ?");
    $stmt->bind_param('i', $route_id);

    if (!$stmt->execute()) {
        throw new RuntimeException("Failed to toggle route status: " . $stmt->error);
    }

    $_SESSION['success_message'] = 'Route status updated!';
    redirect('routes.php');
}

function display_routes_list(): array {
    global $conn;

    $origin = trim($_GET['origin'] ?? '');
    $destination = trim($_GET['destination'] ?? '');
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = 10;
    $offset = ($page - 1) * $limit;

    // Start building query
    $sql = "SELECT SQL_CALC_FOUND_ROWS * FROM routes WHERE 1=1";
    $params = [];
    $types = '';

    if (!empty($origin)) {
        $sql .= " AND origin_city LIKE ?";
        $params[] = '%' . $origin . '%';
        $types .= 's';
    }

    if (!empty($destination)) {
        $sql .= " AND destination_city LIKE ?";
        $params[] = '%' . $destination . '%';
        $types .= 's';
    }

    $sql .= " ORDER BY origin_city, destination_city LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    $types .= 'ii';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $total = $conn->query("SELECT FOUND_ROWS()")->fetch_row()[0];
    $total_pages = ceil($total / $limit);

    return [
        'routes' => $result->fetch_all(MYSQLI_ASSOC),
        'pagination' => [
            'current_page' => $page,
            'total_pages' => $total_pages,
            'total_items' => $total
        ]
    ];
}


function get_route_data(int $route_id): array {
    global $conn;
    
    $stmt = $conn->prepare("SELECT * FROM routes WHERE route_id = ?");
    $stmt->bind_param('i', $route_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new RuntimeException("Route not found");
    }
    
    return $result->fetch_assoc();
}

function validate_route_data(array $post_data): array {
    $data = [
        'origin_city' => trim($post_data['origin_city'] ?? ''),
        'origin_terminal' => trim($post_data['origin_terminal'] ?? ''),
        'destination_city' => trim($post_data['destination_city'] ?? ''),
        'destination_terminal' => trim($post_data['destination_terminal'] ?? ''),
        'distance_km' => filter_var($post_data['distance_km'] ?? 0, FILTER_VALIDATE_FLOAT, 
                                  ['options' => ['min_range' => 0]]),
        'estimated_duration_min' => filter_var($post_data['estimated_duration_min'] ?? 0, FILTER_VALIDATE_INT, 
                                            ['options' => ['min_range' => 0]]),
        'is_active' => isset($post_data['is_active']) ? 1 : 0
    ];

    // Validate required fields
    $required = ['origin_city', 'destination_city'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            throw new InvalidArgumentException(ucfirst(str_replace('_', ' ', $field)) . " is required");
        }
        if (strlen($data[$field]) > 50) {
            throw new InvalidArgumentException(ucfirst(str_replace('_', ' ', $field)) . " must be 50 characters or less");
        }
    }

    if ($data['distance_km'] === false) {
        throw new InvalidArgumentException("Invalid distance value");
    }

    if ($data['estimated_duration_min'] === false) {
        throw new InvalidArgumentException("Invalid duration value");
    }

    return $data;
}

function check_route_uniqueness(string $origin_city, string $origin_terminal, 
                               string $destination_city, string $destination_terminal, 
                               ?int $exclude_id = null): void {
    global $conn;
    
    $sql = "SELECT COUNT(*) FROM routes 
           WHERE origin_city = ? AND origin_terminal = ?
           AND destination_city = ? AND destination_terminal = ?";
    $params = [$origin_city, $origin_terminal, $destination_city, $destination_terminal];
    $types = 'ssss';
    
    if ($exclude_id !== null) {
        $sql .= " AND route_id != ?";
        $params[] = $exclude_id;
        $types .= 'i';
    }
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_row();
    
    if ($result[0] > 0) {
        throw new InvalidArgumentException("A route with these details already exists");
    }
}

function redirect(string $location): void {
    header("Location: $location");
    exit;
}

// HTML output escaping
function escape($data): string {
    if (is_scalar($data)) {
        return htmlspecialchars((string)$data, ENT_QUOTES, 'UTF-8');
    }
    return '';
}