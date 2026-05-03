<?php
session_start();
require '../includes/config.php';

// Generate CSRF token if not already present
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = "Invalid CSRF token. Please refresh the page and try again.";
    }

    // Sanitize and validate input
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if ($first_name === '') $errors[] = "First name is required.";
    if ($last_name === '') $errors[] = "Last name is required.";
    
    if ($username === '') {
        $errors[] = "Username is required.";
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
        $errors[] = "Username must be 3-20 characters and contain only letters, numbers, and underscores.";
    }

    if ($email === '') {
        $errors[] = "Email is required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format.";
    }

    if ($password === '' || $confirm_password === '') {
        $errors[] = "Password and confirmation are required.";
    } elseif ($password !== $confirm_password) {
        $errors[] = "Passwords do not match.";
    } elseif (
        strlen($password) < 8 ||
        !preg_match('/[A-Z]/', $password) ||
        !preg_match('/[a-z]/', $password) ||
        !preg_match('/[0-9]/', $password) ||
        !preg_match('/[\W]/', $password)
    ) {
        $errors[] = "Password must be at least 8 characters and include an uppercase letter, lowercase letter, number, and special character.";
    }

    if (empty($errors)) {
        // Check for duplicate email
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $errors[] = "Email is already registered.";
        }
        $stmt->close();

        // Check for duplicate username
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $errors[] = "Username is already taken.";
        }
        $stmt->close();

        // Insert user and passenger only if no errors
        if (empty($errors)) {
            $password_hash = password_hash($password, PASSWORD_BCRYPT);
            $user_type = 'passenger';
            $is_verified = 0;

            $stmt = $conn->prepare("INSERT INTO users (first_name, last_name, username, email, password_hash, user_type, is_verified, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param("ssssssi", $first_name, $last_name, $username, $email, $password_hash, $user_type, $is_verified);

            if ($stmt->execute()) {
                $new_user_id = $conn->insert_id;
                $full_name = $first_name . ' ' . $last_name;
                $stmt->close();

                // Insert into passengers table
                $stmt = $conn->prepare("INSERT INTO passengers (user_id, full_name) VALUES (?, ?)");
                $stmt->bind_param("is", $new_user_id, $full_name);
                $stmt->execute();
                $stmt->close();

                // Clear CSRF token
                unset($_SESSION['csrf_token']);

                $success = "Account created successfully! <a href='login.php'>Login here</a>.";
            } else {
                $errors[] = "Something went wrong. Please try again later.";
                $stmt->close();
            }
        }
    }
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Register | SmartBusLink</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/styles.css" rel="stylesheet">
</head>
<body class="bg-light" style="background: linear-gradient(120deg, #2c3e50, rgb(53, 20, 172)); min-height: 100vh;">
    <div class="container py-5">
        <div class="row justify-content-center align-items-center min-vh-100">
            <div class="col-md-6 col-lg-5">
                <div class="card shadow rounded-4 bg-white bg-opacity-90 backdrop-blur-sm">
                    <div class="card-body p-5">
                        <div class="text-center mb-4">
                            <h3 class="fw-bold text-primary">SmartBusLink</h3>
                            <p class="text-muted small">Create Your Passenger Account</p>
                        </div>

                        <?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <h5 class="alert-heading">Please fix these errors:</h5>
        <ul class="mb-0">
            <?php foreach ($errors as $err): ?>
                <li><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php elseif ($success): ?>
    <div class="alert alert-success">
        <h5 class="alert-heading">Success!</h5>
        <div><?= $success ?></div>
    </div>
<?php endif; ?>

<form method="post" novalidate>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control <?= in_array("First name is required.", $errors) ? 'is-invalid' : '' ?>"
                                       id="first_name" name="first_name" placeholder="First Name" required
                                       value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>">
                                <label for="first_name"><i class="bi bi-person-fill me-2"></i> First Name</label>
                            </div>

                            <div class="form-floating mb-3">
                                <input type="text" class="form-control <?= in_array("Last name is required.", $errors) ? 'is-invalid' : '' ?>"
                                       id="last_name" name="last_name" placeholder="Last Name" required
                                       value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>">
                                <label for="last_name"><i class="bi bi-person-fill me-2"></i> Last Name</label>
                            </div>

                            <div class="form-floating mb-3">
                                <input type="text" class="form-control <?= in_array("Username is required.", $errors) ? 'is-invalid' : '' ?>"
                                       id="username" name="username" placeholder="Username" required
                                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                                <label for="username"><i class="bi bi-person-badge-fill me-2"></i> Username</label>
                            </div>

                            <div class="form-floating mb-3">
                                <input type="email" class="form-control <?= in_array("Email is required.", $errors) || in_array("Invalid email format.", $errors) ? 'is-invalid' : '' ?>"
                                       id="email" name="email" placeholder="Email" required
                                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                                <label for="email"><i class="bi bi-envelope-fill me-2"></i> Email</label>
                            </div>

                            <div class="form-floating mb-3 position-relative">
                                <input type="password" class="form-control <?= in_array("Password and confirmation are required.", $errors) || in_array("Passwords do not match.", $errors) ? 'is-invalid' : '' ?>"
                                       id="password" name="password" placeholder="Password" required>
                                <label for="password"><i class="bi bi-lock-fill me-2"></i> Password</label>
                            </div>

                            <div class="form-floating mb-3 position-relative">
                                <input type="password" class="form-control <?= in_array("Passwords do not match.", $errors) ? 'is-invalid' : '' ?>"
                                       id="confirm_password" name="confirm_password" placeholder="Confirm Password" required>
                                <label for="confirm_password"><i class="bi bi-shield-lock-fill me-2"></i> Confirm Password</label>
                            </div>

                            <button type="submit" class="btn btn-primary w-100 py-2 mb-3">
                                <i class="bi bi-person-plus-fill me-2"></i> Register
                            </button>

                            <div class="text-center">
                                <small>Already have an account? <a href="../login.php" class="text-decoration-none text-primary">Sign in</a></small>
                            </div>
                        </form>
                    </div>
                </div>
                <p class="text-center text-white mt-4 small">© <?= date('Y') ?> SmartBusLink. All rights reserved.</p>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
