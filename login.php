<?php
session_start();
require_once 'includes/config.php';

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';

// Handle POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Invalid form submission. Please try again.";
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $error = "Please fill in both fields.";
        } else {
            // Use prepared statements
            $stmt = $conn->prepare("SELECT 
        u.user_id, 
        u.username, 
        u.password_hash, 
        u.user_type, 
        u.is_verified,
        IFNULL(o.is_approved, 1) as operator_approved
    FROM users u
    LEFT JOIN operators o ON u.user_id = o.user_id
    WHERE u.username = ? OR u.email = ?");
            $stmt->bind_param("ss", $username, $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result && $user = $result->fetch_assoc()) {
                if (password_verify($password, $user['password_hash'])) {
                    if ($user['is_verified'] != 1) {
                        $error = "Please verify your email before logging in.";
                    } 
                    // Additional check for operators
                    elseif ($user['user_type'] === 'operator' && $user['operator_approved'] != 1) {
                        $error = "Your operator account is pending approval. Please contact administrator.";
                    } else {
                        // Successful login
                        $_SESSION['loggedin'] = true;
                        $_SESSION['user_id'] = $user['user_id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['user_type'] = $user['user_type'];

                        // Redirect by role
                        switch ($user['user_type']) {
                            case 'admin':
                                header("Location: admin/dashboard.php");
                                exit;
                            case 'operator':
                                header("Location: operator/dashboard.php");
                                exit;
                            case 'passenger':
                            default:
                                header("Location: passenger/dashboard.php");
                                exit;
                        }
                    }
                } else {
                    $error = "Incorrect username or password.";
                }
            } else {
                $error = "Account not found.";
            }
            $stmt->close();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | SmartBusLink</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="/assets/css/styles.css" rel="stylesheet">
    <style>
        .backdrop-blur-sm {
            backdrop-filter: blur(5px);
        }
        .min-vh-100 {
            min-height: 100vh;
        }
    </style>
</head>
<body class="bg-light" style="background: linear-gradient(120deg, #2c3e50, rgb(53, 20, 172)); min-height: 100vh;">
    <div class="container py-5">
        <div class="row justify-content-center align-items-center min-vh-100">
            <div class="col-md-6 col-lg-5">
                <div class="card shadow-lg border-0 rounded-4 bg-white bg-opacity-75 backdrop-blur-sm">
                    <div class="card-body p-5">
                        <div class="text-center mb-4">
                            <h3 class="fw-bold text-primary">SmartBusLink</h3>
                            <p class="text-muted small">Sign in to continue</p>
                        </div>

                        <?php if (!empty($error)): ?>
                            <div class="alert alert-danger alert-dismissible fade show">
                                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                <?= htmlspecialchars($error) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>

                        <form method="post" class="needs-validation" novalidate>
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" id="username" name="username"
                                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required placeholder="Username or Email">
                                <label for="username"><i class="bi bi-person-fill me-2"></i> Username or Email</label>
                            </div>

                            <div class="form-floating mb-3 position-relative">
                                <input type="password" class="form-control" id="password" name="password"
                                       required placeholder="Password">
                                <label for="password"><i class="bi bi-lock-fill me-2"></i> Password</label>
                                <button type="button" class="btn btn-link position-absolute end-0 top-50 translate-middle-y pe-3 text-secondary toggle-password">
                                    <i class="bi bi-eye-fill"></i>
                                </button>
                            </div>

                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="remember" id="remember">
                                    <label class="form-check-label" for="remember">Remember me</label>
                                </div>
                                <a href="forgot-password.php" class="small text-decoration-none text-primary">Forgot password?</a>
                            </div>

                            <button type="submit" class="btn btn-primary w-100 py-2 mb-3">
                                <i class="bi bi-box-arrow-in-right me-2"></i> Sign In
                            </button>

                            <div class="text-center">
                                <small>Don't have an account? <a href="passenger/register.php" class="text-primary text-decoration-none">Sign up</a></small>
                            </div>
                        </form>
                    </div>
                </div>
                <p class="text-center text-white mt-4 small">© <?= date('Y') ?> SmartBusLink. All rights reserved.</p>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle password visibility
        document.querySelectorAll('.toggle-password').forEach(button => {
            button.addEventListener('click', function() {
                const passwordInput = this.closest('.position-relative').querySelector('input');
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                this.innerHTML = type === 'password' ? '<i class="bi bi-eye-fill"></i>' : '<i class="bi bi-eye-slash-fill"></i>';
            });
        });
    </script>
</body>
</html>