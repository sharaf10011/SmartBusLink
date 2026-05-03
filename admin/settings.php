<?php
session_start();
include('../includes/session.php');
include('../includes/config.php');

// Check if the user is logged in and if the user is an admin
if (empty($_SESSION['loggedin']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: login.html");
    exit;
}

// Get the current admin settings from the database
$query = "SELECT * FROM settings WHERE setting_id = 1";
$result = mysqli_query($conn, $query);

if ($result && mysqli_num_rows($result) > 0) {
    $current_settings = mysqli_fetch_assoc($result);
} else {
    // Default values if no settings found
    $current_settings = [
        'system_name' => 'Default System Name',
        'admin_email' => 'admin@example.com',
        'admin_password' => ''
    ];
}


// Handle settings update (e.g., admin credentials or system preferences)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_settings'])) {
    $new_system_name = $_POST['system_name'];
    $new_admin_email = $_POST['admin_email'];

    // Optional: Update password if provided
    if (!empty($_POST['admin_password'])) {
        $new_password = password_hash($_POST['admin_password'], PASSWORD_DEFAULT);
    } else {
        // Keep the old password if not provided
        $new_password = $current_settings['admin_password'];
    }

    // Update the settings in the database
    $update_query = $conn->prepare("UPDATE settings SET system_name = ?, admin_email = ?, admin_password = ? WHERE setting_id = 1");
    $update_query->bind_param("sss", $new_system_name, $new_admin_email, $new_password);

    if ($update_query->execute()) {
        $message = "Settings updated successfully!";
    } else {
        $message = "Error updating settings: " . $update_query->error;
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
</head>

<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="index.html">Care Compass Hospitals</a>
            <a href="dashboard.php" class="btn btn-danger">Back Dashboard</a>
        </div>
    </nav>

    <div class="container mt-5">
        <div class="row">
            <div class="col-md-12 text-center mb-5">
                <h1>System Settings</h1>
                <p class="lead">Here you can manage system settings and update admin credentials.</p>
            </div>
        </div>

        <?php if (isset($message)): ?>
            <div class="alert alert-info"><?php echo $message; ?></div>
        <?php endif; ?>

        <!-- Settings Form -->
        <div class="row">
            <div class="col-md-12 mb-4">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h5 class="card-title">Update System Settings</h5>
                        <form action="settings.php" method="POST">
                            <div class="mb-3">
                                <label for="system_name" class="form-label">System Name</label>
                                <input type="text" class="form-control" id="system_name" name="system_name" value="<?php echo htmlspecialchars($current_settings['system_name']); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="admin_email" class="form-label">Admin Email</label>
                                <input type="email" class="form-control" id="admin_email" name="admin_email" value="<?php echo htmlspecialchars($current_settings['admin_email']); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="admin_password" class="form-label">New Admin Password (Leave blank if not changing)</label>
                                <input type="password" class="form-control" id="admin_password" name="admin_password">
                            </div>
                            <button type="submit" class="btn btn-primary" name="update_settings">Update Settings</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <footer class="bg-dark text-white text-center py-3 mt-5">
        <p>&copy; 2025 Care Compass Hospitals | All Rights Reserved</p>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
