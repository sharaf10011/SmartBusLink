<?php
ob_start();
session_start();

// --- AUTHENTICATION & PERMISSIONS ---
if (!isset($_SESSION['loggedin']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

require_once '../includes/config.php';
include 'navbar.php';

// --- CSRF PROTECTION ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// --- FORM PROCESSING ---
$errors = [];
$form_data = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_operator'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
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
        'gender' => trim($_POST['gender'] ?? '')
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

    if (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters";
    }

    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match";
    }

    // Check for existing email/username
    if (empty($errors)) {
        $check_stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? OR username = ?");
        $check_stmt->bind_param("ss", $form_data['email'], $form_data['username']);
        $check_stmt->execute();
        $check_stmt->store_result();

        if ($check_stmt->num_rows > 0) {
            $errors[] = "Email or username already exists";
        }
        $check_stmt->close();
    }

    // Process registration if no errors
    if (empty($errors)) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        // Start transaction for atomic operations
        $conn->begin_transaction();

        try {
            // 1. Insert into users table
            $user_stmt = $conn->prepare("INSERT INTO users 
                (first_name, last_name, email, password_hash, user_type, phone, gender, address, username, status, created_at) 
                VALUES (?, ?, ?, ?, 'operator', ?, ?, ?, ?, 'Active', NOW())");
            
            $user_stmt->bind_param("ssssssss",
                $form_data['first_name'],
                $form_data['last_name'],
                $form_data['email'],
                $hashed_password,
                $form_data['phone'],
                $form_data['gender'],
                $form_data['address'],
                $form_data['username']
            );
            
            if (!$user_stmt->execute()) {
                throw new Exception("Failed to create user account");
            }
            
            $user_id = $conn->insert_id;
            $user_stmt->close();

            // 2. Insert into operators table
            $operator_stmt = $conn->prepare("INSERT INTO operators 
                (user_id, company_name, contact_person, email, phone, address, license_number, tax_id, is_approved, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())");
                
            $operator_stmt->bind_param("isssssss",
                $user_id,
                $form_data['company_name'],
                $form_data['contact_person'],
                $form_data['email'],
                $form_data['phone'],
                $form_data['address'],
                $form_data['license_number'],
                $form_data['tax_id']
            );
            
            if (!$operator_stmt->execute()) {
                throw new Exception("Failed to create operator profile");
            }
            
            $operator_stmt->close();

            // Commit both inserts as a single transaction
            $conn->commit();

// Clear output buffer before redirect
while (ob_get_level()) {
    ob_end_clean();
}

// Set success message and redirect
$_SESSION['success_message'] = "Operator registered successfully!";
header("Location: manage-operators.php");
exit;
            
        } catch (Exception $e) {
            // Roll back both inserts if either fails
            $conn->rollback();
            error_log("Registration error: " . $e->getMessage());
            $errors[] = "Registration failed. Please try again. Error: " . $e->getMessage();
        } finally {
            $conn->close();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register New Operator</title>
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
    </style>
</head>
<body>
    <div class="container py-4">
        <div class="card registration-card">
            <div class="card-header text-center">
                <h3 class="mb-0"><i class="fas fa-user-plus me-2"></i>Register New Operator</h3>
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
                    <input type="hidden" name="register_operator" value="1">
                    
                    <h5 class="section-title"><i class="fas fa-building me-2"></i>Company Information</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Company Name *</label>
                            <input type="text" class="form-control" name="company_name" 
                                   value="<?php echo htmlspecialchars($form_data['company_name'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">License Number *</label>
                            <input type="text" class="form-control" name="license_number" 
                                   value="<?php echo htmlspecialchars($form_data['license_number'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Tax ID (Optional)</label>
                            <input type="text" class="form-control" name="tax_id" 
                                   value="<?php echo htmlspecialchars($form_data['tax_id'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Contact Person *</label>
                            <input type="text" class="form-control" name="contact_person" 
                                   value="<?php echo htmlspecialchars($form_data['contact_person'] ?? ''); ?>" required>
                        </div>
                    </div>
                    
                    <h5 class="section-title"><i class="fas fa-address-book me-2"></i>Contact Information</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Email *</label>
                            <input type="email" class="form-control" name="email" 
                                   value="<?php echo htmlspecialchars($form_data['email'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone *</label>
                            <input type="tel" class="form-control" name="phone" 
                                   value="<?php echo htmlspecialchars($form_data['phone'] ?? ''); ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Address *</label>
                            <input type="text" class="form-control" name="address" 
                                   value="<?php echo htmlspecialchars($form_data['address'] ?? ''); ?>" required>
                        </div>
                    </div>
                    
                    <h5 class="section-title"><i class="fas fa-user-circle me-2"></i>Account Information</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">First Name *</label>
                            <input type="text" class="form-control" name="first_name" 
                                   value="<?php echo htmlspecialchars($form_data['first_name'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Last Name *</label>
                            <input type="text" class="form-control" name="last_name" 
                                   value="<?php echo htmlspecialchars($form_data['last_name'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Username *</label>
                            <input type="text" class="form-control" name="username" 
                                   value="<?php echo htmlspecialchars($form_data['username'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Gender</label>
                            <select class="form-select" name="gender">
                                <option value="">Select Gender</option>
                                <option value="male" <?php echo (isset($form_data['gender']) && $form_data['gender'] === 'male') ? 'selected' : ''; ?>>Male</option>
                                <option value="female" <?php echo (isset($form_data['gender']) && $form_data['gender'] === 'female') ? 'selected' : ''; ?>>Female</option>
                                <option value="other" <?php echo (isset($form_data['gender']) && $form_data['gender'] === 'other') ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Password *</label>
                            <div class="input-group">
                                <input type="password" class="form-control" name="password" id="password" required>
                                <button type="button" class="btn btn-outline-secondary toggle-password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <small class="text-muted">Minimum 8 characters</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Confirm Password *</label>
                            <div class="input-group">
                                <input type="password" class="form-control" name="confirm_password" id="confirm_password" required>
                                <button type="button" class="btn btn-outline-secondary toggle-confirm-password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <small id="password-match-text" class="password-match">Passwords match</small>
                            <small id="password-mismatch-text" class="password-mismatch">Passwords do not match</small>
                        </div>
                    </div>
                    
                    <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                        <a href="manage-operators.php" class="btn btn-secondary me-md-2 px-4">
                            <i class="fas fa-times me-2"></i>Cancel
                        </a>
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="fas fa-save me-2"></i>Register Operator
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
            
            if (password.length < 8) {
                e.preventDefault();
                alert('Password must be at least 8 characters long');
                return;
            }
            
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match');
                return;
            }
        });
    </script>
</body>
</html>