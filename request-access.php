<?php
session_start();
require_once 'includes/config.php';

// Validation function
function validateRequiredFields($data, $required_fields) {
    $errors = [];
    foreach ($required_fields as $field => $name) {
        if (!isset($data[$field])) {
            $errors[] = "$name is required";
        } elseif (is_string($data[$field]) && trim($data[$field]) === '') {
            $errors[] = "$name cannot be empty";
        } elseif (is_array($data[$field]) && empty($data[$field])) {
            $errors[] = "$name must be selected";
        }
    }
    return $errors;
}

$page_title = "Operator Registration Request";

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Invalid CSRF token";
    } else {
        // Validate and sanitize inputs
        $required_fields = [
            'companyName' => 'Company name',
            'contactPerson' => 'Contact person',
            'email' => 'Email address',
            'phone' => 'Phone number'
        ];
        
        $errors = validateRequiredFields($_POST, $required_fields);
        
        if (!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email format";
        }
        
        if (empty($errors)) {
            // Check if email already exists using prepared statement
            $email = $_POST['email'];
            $stmt = $conn->prepare("SELECT email FROM operator_requests WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $check_email = $stmt->get_result();
            
            if ($check_email->num_rows > 0) {
                $error = "This email already has a pending request";
            } else {
                // Prepare data with variables (fixes the reference issue)
                $companyName = $_POST['companyName'];
                $contactPerson = $_POST['contactPerson'];
                $contactNumber = $_POST['phone'];
                $email = $_POST['email'];
                $fleetSize = isset($_POST['fleetSize']) ? (int)$_POST['fleetSize'] : 0;
                $routes = $_POST['routes'] ?? '';
                $message = $_POST['additionalInfo'] ?? '';
                $current_date = date('Y-m-d H:i:s');
                $status = 'pending';
                
                // Prepare the statement matching your database schema
                $stmt = $conn->prepare("INSERT INTO operator_requests (
                    company_name, contact_person, contact_number, email, 
                    fleet_size, routes, message, request_date, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                
                // Bind parameters using variables (not direct array access)
                $stmt->bind_param(
                    "ssssissss",
                    $companyName,
                    $contactPerson,
                    $contactNumber,
                    $email,
                    $fleetSize,
                    $routes,
                    $message,
                    $current_date,
                    $status
                );
                
                if ($stmt->execute()) {
                    $_SESSION['success'] = "Your registration request has been submitted successfully!";
                    header("Location: request-access.php");
                    exit;
                } else {
                    error_log("Database error: " . $stmt->error);
                    $error = "An error occurred while processing your request. Please try again later.";
                }
            }
        } else {
            $error = implode("<br>", $errors);
        }
    }
}
?>
<?php include 'includes/navbar.php'; ?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/styles.css">
</head>
<body>
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h1 class="h4 mb-0">Operator Registration Request</h1>
                    </div>
                    
                    <div class="card-body">
                        <?php if (isset($error)): ?>
                            <div class="alert alert-danger d-flex align-items-center">
                                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                <div><?= htmlspecialchars($error) ?></div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (isset($_SESSION['success'])): ?>
                            <div class="alert alert-success d-flex align-items-center">
                                <i class="bi bi-check-circle-fill me-2"></i>
                                <div><?= htmlspecialchars($_SESSION['success']) ?></div>
                            </div>
                            <?php unset($_SESSION['success']); ?>
                        <?php endif; ?>
                        
                        <form id="registrationForm" method="POST" class="needs-validation" novalidate>
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="companyName" class="form-label required">Company Name</label>
                                    <input type="text" class="form-control" id="companyName" name="companyName" required
                                        value="<?= isset($_POST['companyName']) ? htmlspecialchars($_POST['companyName']) : '' ?>">
                                    <div class="invalid-feedback">Please provide your company name.</div>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="licenseNumber" class="form-label required">License Number</label>
                                    <input type="text" class="form-control" id="licenseNumber" name="licenseNumber" required
                                        value="<?= isset($_POST['licenseNumber']) ? htmlspecialchars($_POST['licenseNumber']) : '' ?>">
                                    <div class="invalid-feedback">Please provide your license number.</div>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="contactPerson" class="form-label required">Contact Person</label>
                                    <input type="text" class="form-control" id="contactPerson" name="contactPerson" required
                                        value="<?= isset($_POST['contactPerson']) ? htmlspecialchars($_POST['contactPerson']) : '' ?>">
                                    <div class="invalid-feedback">Please provide a contact person.</div>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="email" class="form-label required">Email</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                        <input type="email" class="form-control" id="email" name="email" required
                                            value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>">
                                    </div>
                                    <div class="invalid-feedback">Please provide a valid email address.</div>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="phone" class="form-label required">Phone</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                                        <input type="tel" class="form-control" id="phone" name="phone" required
                                            value="<?= isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : '' ?>">
                                    </div>
                                    <div class="invalid-feedback">Please provide a valid phone number.</div>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="taxId" class="form-label">Tax ID</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-card-text"></i></span>
                                        <input type="text" class="form-control" id="taxId" name="taxId"
                                            value="<?= isset($_POST['taxId']) ? htmlspecialchars($_POST['taxId']) : '' ?>">
                                    </div>
                                </div>
                                
                                <div class="col-12">
                                    <label for="address" class="form-label required">Address</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-geo-alt"></i></span>
                                        <textarea class="form-control" id="address" name="address" rows="2" required><?= 
                                            isset($_POST['address']) ? htmlspecialchars($_POST['address']) : '' ?></textarea>
                                    </div>
                                    <div class="invalid-feedback">Please provide your company address.</div>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="fleetSize" class="form-label">Fleet Size</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-bus-front"></i></span>
                                        <input type="number" class="form-control" id="fleetSize" name="fleetSize" min="1"
                                            value="<?= isset($_POST['fleetSize']) ? htmlspecialchars($_POST['fleetSize']) : '' ?>">
                                    </div>
                                </div>
                                
                                <div class="col-12">
                                    <label for="routes" class="form-label">Routes (comma separated)</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-signpost-split"></i></span>
                                        <textarea class="form-control" id="routes" name="routes" rows="2"><?= 
                                            isset($_POST['routes']) ? htmlspecialchars($_POST['routes']) : '' ?></textarea>
                                    </div>
                                </div>
                                
                                <div class="col-12">
                                    <label for="additionalInfo" class="form-label">Additional Information</label>
                                    <textarea class="form-control" id="additionalInfo" name="additionalInfo" rows="4"><?= 
                                        isset($_POST['additionalInfo']) ? htmlspecialchars($_POST['additionalInfo']) : '' ?></textarea>
                                </div>
                                
                                <div class="col-12 mt-4">
                                    <button type="submit" class="btn btn-primary" id="submitBtn">
                                        <span class="submit-text">Submit Request</span>
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include 'includes/footer.php'; ?>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/scripts.js"></script>
</body>
</html>