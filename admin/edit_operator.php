<?php
ob_start();
session_start();

// --- SECURITY HEADERS ---
header("Strict-Transport-Security: max-age=63072000; includeSubDomains; preload");
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");

// --- AUTHENTICATION & PERMISSIONS ---
if (!isset($_SESSION['loggedin']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

require_once '../includes/config.php';
include 'navbar.php';

// --- OPERATOR ID VALIDATION ---
if (!isset($_GET['id']) || !ctype_digit($_GET['id'])) {
    header("Location: manage-operators.php");
    exit;
}

$operatorId = intval($_GET['id']);

// --- FETCH OPERATOR DATA ---
$sql = "SELECT o.*, u.user_id, u.first_name, u.last_name, u.username, u.gender, u.status as user_status 
        FROM operators o 
        JOIN users u ON o.user_id = u.user_id 
        WHERE o.operator_id = ? LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $operatorId);
$stmt->execute();
$result = $stmt->get_result();
$operator = $result->fetch_assoc();
$stmt->close();

if (!$operator) {
    header("Location: manage-operators.php");
    exit;
}

// --- CSRF PROTECTION ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// --- FORM PROCESSING ---
$errors = [];
$form_data = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_operator'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !hash_equals($csrf_token, $_POST['csrf_token'])) {
        die("CSRF token validation failed");
    }

    // Collect and sanitize form data
    $form_data = [
        'company_name' => trim($_POST['company_name'] ?? ''),
        'license_number' => trim($_POST['license_number'] ?? ''),
        'tax_id' => trim($_POST['tax_id'] ?? ''),
        'contact_person' => trim($_POST['contact_person'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
        'address' => trim($_POST['address'] ?? ''),
        'first_name' => trim($_POST['first_name'] ?? ''),
        'last_name' => trim($_POST['last_name'] ?? ''),
        'username' => trim($_POST['username'] ?? ''),
        'gender' => trim($_POST['gender'] ?? ''),
        'is_approved' => isset($_POST['is_approved']) ? 1 : 0
    ];

    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Validation
    $required_fields = [
        'company_name' => 'Company name',
        'license_number' => 'License number',
        'contact_person' => 'Contact person',
        'email' => 'Email',
        'phone' => 'Phone',
        'address' => 'Address',
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'username' => 'Username'
    ];

    foreach ($required_fields as $field => $name) {
        if (empty($form_data[$field])) {
            $errors[] = "$name is required";
        }
    }

    if (!filter_var($form_data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    }

    if ($password && strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters";
    }

    if ($password && $password !== $confirm_password) {
        $errors[] = "Passwords do not match";
    }

    // Check for existing email/username (excluding current operator)
    if (empty($errors)) {
        $check_stmt = $conn->prepare("SELECT user_id FROM users WHERE (email = ? OR username = ?) AND user_id != ?");
        $check_stmt->bind_param("ssi", $form_data['email'], $form_data['username'], $operator['user_id']);
        $check_stmt->execute();
        $check_stmt->store_result();

        if ($check_stmt->num_rows > 0) {
            $errors[] = "Email or username already exists";
        }
        $check_stmt->close();
    }

    // Process update if no errors
    if (empty($errors)) {
        try {
            $conn->begin_transaction();

            // 1. Update users table
            if ($password) {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $user_stmt = $conn->prepare("UPDATE users SET 
                    first_name = ?, last_name = ?, email = ?, password_hash = ?, 
                    phone = ?, gender = ?, address = ?, username = ?, updated_at = NOW() 
                    WHERE user_id = ?");
                $user_stmt->bind_param("ssssssssi",
                    $form_data['first_name'],
                    $form_data['last_name'],
                    $form_data['email'],
                    $hashed_password,
                    $form_data['phone'],
                    $form_data['gender'],
                    $form_data['address'],
                    $form_data['username'],
                    $operator['user_id']
                );
            } else {
                $user_stmt = $conn->prepare("UPDATE users SET 
                    first_name = ?, last_name = ?, email = ?, 
                    phone = ?, gender = ?, address = ?, username = ?, updated_at = NOW() 
                    WHERE user_id = ?");
                $user_stmt->bind_param("sssssssi",
                    $form_data['first_name'],
                    $form_data['last_name'],
                    $form_data['email'],
                    $form_data['phone'],
                    $form_data['gender'],
                    $form_data['address'],
                    $form_data['username'],
                    $operator['user_id']
                );
            }
            
            if (!$user_stmt->execute()) {
                throw new Exception("Failed to update user account");
            }
            $user_stmt->close();

            // 2. Update operators table
            $operator_stmt = $conn->prepare("UPDATE operators SET 
                company_name = ?, contact_person = ?, email = ?, phone = ?, 
                address = ?, license_number = ?, tax_id = ?, is_approved = ?, updated_at = NOW() 
                WHERE operator_id = ?");
                
            $operator_stmt->bind_param("sssssssii",
                $form_data['company_name'],
                $form_data['contact_person'],
                $form_data['email'],
                $form_data['phone'],
                $form_data['address'],
                $form_data['license_number'],
                $form_data['tax_id'],
                $form_data['is_approved'],
                $operatorId
            );
            
            if (!$operator_stmt->execute()) {
                throw new Exception("Failed to update operator profile");
            }
            
            $operator_stmt->close();

            $conn->commit();

            // Clear output buffer before redirect
            while (ob_get_level()) {
                ob_end_clean();
            }

            $_SESSION['success_message'] = "Operator updated successfully!";
            header("Location: manage-operators.php");
            exit;
            
        } catch (Exception $e) {
            $conn->rollback();
            error_log("Update error: " . $e->getMessage());
            $errors[] = "Update failed. Please try again. Error: " . $e->getMessage();
        }
    }
} else {
    // Pre-fill form with existing data if not submitting
    $form_data = [
        'company_name' => $operator['company_name'],
        'license_number' => $operator['license_number'],
        'tax_id' => $operator['tax_id'] ?? '',
        'contact_person' => $operator['contact_person'],
        'email' => $operator['email'],
        'phone' => $operator['phone'],
        'address' => $operator['address'],
        'first_name' => $operator['first_name'],
        'last_name' => $operator['last_name'],
        'username' => $operator['username'],
        'gender' => $operator['gender'],
        'is_approved' => $operator['is_approved']
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Operator - <?php echo htmlspecialchars($operator['company_name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3f37c9;
        }
        body { background-color: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .registration-card { 
            max-width: 800px; 
            margin: 2rem auto; 
            border-radius: 10px; 
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1); 
            border: none;
        }
        .card-header { 
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); 
            color: white; 
            border-radius: 10px 10px 0 0 !important;
            padding: 1.5rem;
        }
        .section-title {
            color: var(--primary-color);
            font-weight: 600;
            margin: 1.5rem 0 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #eee;
        }
        .form-label { font-weight: 500; }
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        .toggle-password { cursor: pointer; }
        .password-match { color: green; display: none; }
        .password-mismatch { color: red; display: none; }
        .is-approved-checkbox .form-check-input {
            width: 2em;
            height: 2em;
        }
    </style>
</head>
<body>
    <div class="container py-4">
        <div class="card registration-card">
            <div class="card-header text-center">
                <h3 class="mb-0"><i class="fas fa-user-edit me-2"></i>Edit Operator</h3>
            </div>
            
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger mx-3 mt-3">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="card-body p-4">
                <form method="POST" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="update_operator" value="1">
                    
                    <h5 class="section-title"><i class="fas fa-building me-2"></i>Company Information</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Company Name *</label>
                            <input type="text" class="form-control" name="company_name" 
                                   value="<?php echo htmlspecialchars($form_data['company_name']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">License Number *</label>
                            <input type="text" class="form-control" name="license_number" 
                                   value="<?php echo htmlspecialchars($form_data['license_number']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Tax ID (Optional)</label>
                            <input type="text" class="form-control" name="tax_id" 
                                   value="<?php echo htmlspecialchars($form_data['tax_id']); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Contact Person *</label>
                            <input type="text" class="form-control" name="contact_person" 
                                   value="<?php echo htmlspecialchars($form_data['contact_person']); ?>" required>
                        </div>
                    </div>
                    
                    <h5 class="section-title"><i class="fas fa-address-book me-2"></i>Contact Information</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Email *</label>
                            <input type="email" class="form-control" name="email" 
                                   value="<?php echo htmlspecialchars($form_data['email']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone *</label>
                            <input type="tel" class="form-control" name="phone" 
                                   value="<?php echo htmlspecialchars($form_data['phone']); ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Address *</label>
                            <input type="text" class="form-control" name="address" 
                                   value="<?php echo htmlspecialchars($form_data['address']); ?>" required>
                        </div>
                    </div>
                    
                    <h5 class="section-title"><i class="fas fa-user-circle me-2"></i>Account Information</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">First Name *</label>
                            <input type="text" class="form-control" name="first_name" 
                                   value="<?php echo htmlspecialchars($form_data['first_name']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Last Name *</label>
                            <input type="text" class="form-control" name="last_name" 
                                   value="<?php echo htmlspecialchars($form_data['last_name']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Username *</label>
                            <input type="text" class="form-control" name="username" 
                                   value="<?php echo htmlspecialchars($form_data['username']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Gender</label>
                            <select class="form-select" name="gender">
                                <option value="">Select Gender</option>
                                <option value="male" <?php echo ($form_data['gender'] === 'male') ? 'selected' : ''; ?>>Male</option>
                                <option value="female" <?php echo ($form_data['gender'] === 'female') ? 'selected' : ''; ?>>Female</option>
                                <option value="other" <?php echo ($form_data['gender'] === 'other') ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Password (leave blank to keep current)</label>
                            <div class="input-group">
                                <input type="password" class="form-control" name="password" id="password">
                                <button type="button" class="btn btn-outline-secondary toggle-password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <small class="text-muted">Minimum 8 characters</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Confirm Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" name="confirm_password" id="confirm_password">
                                <button type="button" class="btn btn-outline-secondary toggle-confirm-password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <small id="password-match-text" class="password-match">Passwords match</small>
                            <small id="password-mismatch-text" class="password-mismatch">Passwords do not match</small>
                        </div>
                        <div class="col-md-6 is-approved-checkbox">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_approved" id="is_approved" 
                                    <?php echo $form_data['is_approved'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="is_approved">Approved Operator</label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                        <a href="manage-operators.php" class="btn btn-secondary me-md-2 px-4">
                            <i class="fas fa-times me-2"></i>Cancel
                        </a>
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="fas fa-save me-2"></i>Update Operator
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle password visibility
        document.querySelectorAll('.toggle-password, .toggle-confirm-password').forEach(button => {
            button.addEventListener('click', function() {
                const input = this.closest('.input-group').querySelector('input');
                const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
                input.setAttribute('type', type);
                this.innerHTML = type === 'password' ? '<i class="fas fa-eye"></i>' : '<i class="fas fa-eye-slash"></i>';
            });
        });
        
        // Password matching validation
        const password = document.getElementById('password');
        const confirmPassword = document.getElementById('confirm_password');
        const matchText = document.getElementById('password-match-text');
        const mismatchText = document.getElementById('password-mismatch-text');
        
        function checkPasswordMatch() {
            if (password.value && confirmPassword.value) {
                if (password.value === confirmPassword.value) {
                    matchText.style.display = 'inline';
                    mismatchText.style.display = 'none';
                } else {
                    matchText.style.display = 'none';
                    mismatchText.style.display = 'inline';
                }
            } else {
                matchText.style.display = 'none';
                mismatchText.style.display = 'none';
            }
        }
        
        password.addEventListener('input', checkPasswordMatch);
        confirmPassword.addEventListener('input', checkPasswordMatch);
        
        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (password && password.length < 8) {
                e.preventDefault();
                alert('Password must be at least 8 characters long');
                return;
            }
            
            if (password && password !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match');
                return;
            }
        });
    </script>
</body>
</html>