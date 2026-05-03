<?php
session_start();
require_once '../includes/config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$userId = $_SESSION['user_id'];
$message = '';

// Create uploads directory if it doesn't exist
if (!file_exists('../uploads')) {
    mkdir('../uploads', 0777, true);
}

// Handle profile image upload
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['profile_image'])) {
    $targetDir = "../uploads/";
    $fileName = uniqid() . '_' . basename($_FILES["profile_image"]["name"]); // Add unique ID to filename
    $targetFile = $targetDir . $fileName;
    $uploadOk = 1;
    $imageFileType = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));

    // Check if image file is a actual image
    $check = getimagesize($_FILES["profile_image"]["tmp_name"]);
    if ($check === false) {
        $message = "File is not an image.";
        $uploadOk = 0;
    }

    // Check file size (5MB max)
    if ($_FILES["profile_image"]["size"] > 5000000) {
        $message = "Sorry, your file is too large.";
        $uploadOk = 0;
    }

    // Allow certain file formats
    if($imageFileType != "jpg" && $imageFileType != "png" && $imageFileType != "jpeg" && $imageFileType != "gif" ) {
        $message = "Sorry, only JPG, JPEG, PNG & GIF files are allowed.";
        $uploadOk = 0;
    }

    if ($uploadOk == 1) {
        if (move_uploaded_file($_FILES["profile_image"]["tmp_name"], $targetFile)) {
            // Update database
            $updateSql = "UPDATE users SET profile_image = ? WHERE user_id = ?";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->bind_param("si", $fileName, $userId);
            $updateStmt->execute();
            $message = "Profile image updated successfully!";
        } else {
            $message = "Sorry, there was an error uploading your file. Please check directory permissions.";
        }
    }
}

// Handle wallet top-up
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['topup_amount'])) {
    $amount = floatval($_POST['topup_amount']);
    if ($amount > 0) {
        // Check if wallet exists
        $checkWallet = $conn->prepare("SELECT user_id FROM wallet WHERE user_id = ?");
        $checkWallet->bind_param("i", $userId);
        $checkWallet->execute();
        $checkWallet->store_result();
        
        if ($checkWallet->num_rows > 0) {
            // Update existing wallet
            $updateWallet = $conn->prepare("UPDATE wallet SET balance = balance + ? WHERE user_id = ?");
            $updateWallet->bind_param("di", $amount, $userId);
            $updateWallet->execute();
        } else {
            // Create new wallet
            $insertWallet = $conn->prepare("INSERT INTO wallet (user_id, balance) VALUES (?, ?)");
            $insertWallet->bind_param("id", $userId, $amount);
            $insertWallet->execute();
        }
        
        // Record transaction
        $transaction = $conn->prepare("INSERT INTO wallet_transactions (user_id, amount, type, description) VALUES (?, ?, 'credit', 'Wallet top-up')");
        $transaction->bind_param("id", $userId, $amount);
        $transaction->execute();
        
        $message = "Wallet topped up successfully!";
    } else {
        $message = "Invalid amount entered.";
    }
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    $username = $_POST['username'];
    $firstName = $_POST['first_name'];
    $lastName = $_POST['last_name'];
    $phone = $_POST['phone'];
    $address = $_POST['address'];
    $gender = $_POST['gender'];
    $dob = $_POST['dob'];

    $updateSql = "UPDATE users SET 
                  username = ?,
                  first_name = ?,
                  last_name = ?,
                  phone = ?,
                  address = ?,
                  gender = ?,
                  dob = ?
                  WHERE user_id = ?";
                  
    $updateStmt = $conn->prepare($updateSql);
    $updateStmt->bind_param("sssssssi", $username, $firstName, $lastName, $phone, $address, $gender, $dob, $userId);
    
    if ($updateStmt->execute()) {
        $message = "Profile updated successfully!";
    } else {
        $message = "Error updating profile: " . $conn->error;
    }
}

// Fetch user details with additional fields
$userSql = "SELECT username, first_name, last_name, email, phone, address, profile_image, gender, dob FROM users WHERE user_id = ?";
$userStmt = $conn->prepare($userSql);
$userStmt->bind_param("i", $userId);
$userStmt->execute();
$userResult = $userStmt->get_result();
$user = $userResult->fetch_assoc();

// Fetch booking history
$bookingSql = "SELECT 
    b.booking_id,
    b.booking_reference,
    b.seat_number,
    b.total_fare,
    b.from_location,
    b.to_location,
    b.travel_date,
    s.departure_time,
    s.arrival_time,
    o.company_name AS operator_name
FROM bookings b
JOIN schedules s ON b.schedule_id = s.schedule_id
JOIN buses bu ON s.bus_id = bu.bus_id
JOIN operators o ON bu.operator_id = o.operator_id
WHERE b.passenger_id = ?
ORDER BY b.booked_at DESC";
$bookingStmt = $conn->prepare($bookingSql);
$bookingStmt->bind_param("i", $userId);
$bookingStmt->execute();
$bookingResult = $bookingStmt->get_result();

// Fetch wallet balance
$walletSql = "SELECT balance FROM wallet WHERE user_id = ?";
$walletStmt = $conn->prepare($walletSql);
$walletStmt->bind_param("i", $userId);
$walletStmt->execute();
$walletResult = $walletStmt->get_result();
$wallet = $walletResult->fetch_assoc();
?>
<?php include '../includes/header.php'; ?><br><br>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - SmartBusLink</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3f37c9;
            --accent-color: #4cc9f0;
            --light-color: #f8f9fa;
            --dark-color: #212529;
        }
        
        body {
            background-color: #f5f7fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .profile-img-container {
            position: relative;
            width: 150px;
            height: 150px;
            margin: 0 auto;
            transition: all 0.3s ease;
        }
        
        .profile-img-container:hover {
            transform: scale(1.05);
        }
        
        .profile-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }
        
        .upload-btn {
            position: absolute;
            bottom: 0;
            right: 0;
            background: var(--primary-color);
            color: white;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid white;
        }
        
        .upload-btn:hover {
            background: var(--secondary-color);
            transform: scale(1.1);
        }
        
        .nav-pills .nav-link {
            color: var(--dark-color);
            border-radius: 8px;
            margin-bottom: 8px;
            transition: all 0.3s ease;
            padding: 12px 15px;
        }
        
        .nav-pills .nav-link.active {
            background-color: var(--primary-color);
            box-shadow: 0 4px 8px rgba(67, 97, 238, 0.3);
        }
        
        .nav-pills .nav-link:hover:not(.active) {
            background-color: rgba(67, 97, 238, 0.1);
        }
        
        .wallet-card {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            border-radius: 12px;
            border: none;
            transition: all 0.3s ease;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .wallet-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        
        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
            overflow: hidden;
        }
        
        .card:hover {
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        
        .card-header {
            background: var(--primary-color);
            color: white;
            border-bottom: none;
            padding: 15px 20px;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            padding: 10px 20px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(67, 97, 238, 0.3);
        }
        
        .form-control, .form-select {
            border-radius: 8px;
            padding: 12px 15px;
            border: 1px solid #e0e0e0;
            transition: all 0.3s ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(67, 97, 238, 0.25);
        }
        
        .table {
            border-radius: 8px;
            overflow: hidden;
        }
        
        .table th {
            background-color: var(--primary-color);
            color: white;
        }
        
        .table-hover tbody tr:hover {
            background-color: rgba(67, 97, 238, 0.05);
            transform: translateX(5px);
            transition: all 0.3s ease;
        }
        
        .list-group-item {
            border-radius: 8px !important;
            margin-bottom: 8px;
            transition: all 0.3s ease;
            border: 1px solid rgba(0,0,0,0.05);
        }
        
        .list-group-item:hover {
            transform: translateX(5px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
        }
        
        .text-success {
            color: #4caf50 !important;
        }
        
        .text-danger {
            color: #f44336 !important;
        }
        
        .animate__delay-02s {
            animation-delay: 0.2s;
        }
        
        .animate__delay-04s {
            animation-delay: 0.4s;
        }
        
        .floating {
            animation: floating 3s ease-in-out infinite;
        }
        
        @keyframes floating {
            0% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
            100% { transform: translateY(0px); }
        }
        
        .pulse {
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(67, 97, 238, 0.4); }
            70% { box-shadow: 0 0 0 10px rgba(67, 97, 238, 0); }
            100% { box-shadow: 0 0 0 0 rgba(67, 97, 238, 0); }
        }
        
        .progress-bar {
            background-color: var(--primary-color);
        }
        
        .badge-primary {
            background-color: var(--primary-color);
        }
    </style>
</head>
<body>
    <div class="container py-5">
        <?php if ($message): ?>
            <div class="alert alert-info alert-dismissible fade show animate__animated animate__fadeInDown" role="alert">
                <div class="d-flex align-items-center">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    <div><?= $message ?></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Left Sidebar -->
            <div class="col-md-4 mb-4">
                <div class="card shadow-sm animate__animated animate__fadeInLeft">
                    <div class="card-body text-center">
                        <div class="profile-img-container mb-3">
                            <img src="<?= $user['profile_image'] ? '../uploads/' . htmlspecialchars($user['profile_image']) : '../assets/default-user.png' ?>" 
                                 class="profile-img rounded-circle" 
                                 alt="Profile Image">
                            <label for="profileUpload" class="upload-btn" title="Change Photo">
                                <i class="bi bi-camera"></i>
                            </label>
                            <form id="profileForm" method="post" enctype="multipart/form-data" style="display: none;">
                                <input type="file" id="profileUpload" name="profile_image" accept="image/*">
                            </form>
                        </div>
                        <h4 class="mb-1 animate__animated animate__fadeIn animate__delay-02s"><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></h4>
                        <p class="text-muted mb-1 animate__animated animate__fadeIn animate__delay-04s">@<?= htmlspecialchars($user['username']) ?></p>
                        <div class="d-flex justify-content-center mb-3 animate__animated animate__fadeIn animate__delay-04s">
                            <span class="badge bg-primary rounded-pill">Passenger</span>
                        </div>
                        
                        <div class="progress mb-4 animate__animated animate__fadeIn animate__delay-04s" style="height: 6px;">
                            <div class="progress-bar" role="progressbar" style="width: 85%" aria-valuenow="85" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                        
                        <ul class="nav nav-pills flex-column mb-3">
                            <li class="nav-item animate__animated animate__fadeInLeft animate__delay-02s">
                                <a class="nav-link active" href="#personal" data-bs-toggle="pill">
                                    <i class="bi bi-person me-2"></i>Personal Info
                                </a>
                            </li>
                            <li class="nav-item animate__animated animate__fadeInLeft animate__delay-04s">
                                <a class="nav-link" href="#bookings" data-bs-toggle="pill">
                                    <i class="bi bi-ticket me-2"></i>Booking History
                                </a>
                            </li>
                            <li class="nav-item animate__animated animate__fadeInLeft animate__delay-06s">
                                <a class="nav-link" href="#wallet" data-bs-toggle="pill">
                                    <i class="bi bi-wallet me-2"></i>My Wallet
                                </a>
                            </li>
                        </ul>
                        
                        <a href="../logout.php" class="btn btn-outline-danger animate__animated animate__fadeIn animate__delay-1s">
                            <i class="bi bi-box-arrow-right me-1"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="col-md-8">
                <div class="tab-content">
                    <!-- Personal Info Tab -->
                    <div class="tab-pane fade show active" id="personal">
                        <div class="card shadow-sm mb-4 animate__animated animate__fadeInRight">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-person me-2"></i>Personal Information</h5>
                            </div>
                            <div class="card-body">
                                <form method="post" id="profileUpdateForm">
                                    <div class="row mb-3">
                                        <div class="col-md-6 animate__animated animate__fadeIn animate__delay-02s">
                                            <label class="form-label">Username</label>
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="bi bi-person-badge"></i></span>
                                                <input type="text" class="form-control" name="username" value="<?= htmlspecialchars($user['username']) ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6 animate__animated animate__fadeIn animate__delay-04s">
                                            <label class="form-label">Email</label>
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                                <input type="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" readonly>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row mb-3">
                                        <div class="col-md-6 animate__animated animate__fadeIn animate__delay-06s">
                                            <label class="form-label">First Name</label>
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="bi bi-person"></i></span>
                                                <input type="text" class="form-control" name="first_name" value="<?= htmlspecialchars($user['first_name']) ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6 animate__animated animate__fadeIn animate__delay-08s">
                                            <label class="form-label">Last Name</label>
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="bi bi-person"></i></span>
                                                <input type="text" class="form-control" name="last_name" value="<?= htmlspecialchars($user['last_name']) ?>" required>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row mb-3">
                                        <div class="col-md-6 animate__animated animate__fadeIn animate__delay-1s">
                                            <label class="form-label">Phone</label>
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                                                <input type="tel" class="form-control" name="phone" value="<?= htmlspecialchars($user['phone']) ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-6 animate__animated animate__fadeIn animate__delay-1s">
                                            <label class="form-label">Gender</label>
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="bi bi-gender-ambiguous"></i></span>
                                                <select class="form-select" name="gender">
                                                    <option value="">Select Gender</option>
                                                    <option value="male" <?= $user['gender'] == 'male' ? 'selected' : '' ?>>Male</option>
                                                    <option value="female" <?= $user['gender'] == 'female' ? 'selected' : '' ?>>Female</option>
                                                    <option value="other" <?= $user['gender'] == 'other' ? 'selected' : '' ?>>Other</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row mb-3">
                                        <div class="col-md-6 animate__animated animate__fadeIn animate__delay-1s">
                                            <label class="form-label">Date of Birth</label>
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="bi bi-calendar"></i></span>
                                                <input type="date" class="form-control" name="dob" value="<?= htmlspecialchars($user['dob']) ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-6 animate__animated animate__fadeIn animate__delay-1s">
                                            <label class="form-label">Member Since</label>
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="bi bi-clock-history"></i></span>
                                                <input type="text" class="form-control" value="<?= date('F Y', strtotime($user['created_at'] ?? 'now')) ?>" readonly>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="mb-3 animate__animated animate__fadeIn animate__delay-1s">
                                        <label class="form-label">Address</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="bi bi-geo-alt"></i></span>
                                            <textarea class="form-control" name="address" rows="3"><?= htmlspecialchars($user['address']) ?></textarea>
                                        </div>
                                    </div>
                                    <div class="d-grid gap-2 animate__animated animate__fadeIn animate__delay-1s">
                                        <button type="submit" name="update_profile" class="btn btn-primary btn-lg">
                                            <i class="bi bi-save me-1"></i> Save Changes
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Booking History Tab -->
                    <div class="tab-pane fade" id="bookings">
                        <div class="card shadow-sm animate__animated animate__fadeInRight">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-ticket me-2"></i>Booking History</h5>
                            </div>
                            <div class="card-body">
                                <?php if ($bookingResult->num_rows > 0): ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead class="animate__animated animate__fadeIn">
                                                <tr>
                                                    <th>Booking Ref</th>
                                                    <th>Route</th>
                                                    <th>Travel Date</th>
                                                    <th>Departure</th>
                                                    <th>Seat</th>
                                                    <th>Fare</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php while ($b = $bookingResult->fetch_assoc()): ?>
                                                    <tr class="animate__animated animate__fadeIn">
                                                        <td>
                                                            <span class="badge bg-primary"><?= htmlspecialchars($b['booking_reference']) ?></span>
                                                        </td>
                                                        <td>
                                                            <div class="d-flex flex-column">
                                                                <span><?= htmlspecialchars($b['from_location']) ?> 
                                                                <i class="bi bi-arrow-right"></i> 
                                                                <?= htmlspecialchars($b['to_location']) ?></span>
                                                                <small class="text-muted"><?= htmlspecialchars($b['operator_name']) ?></small>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <div class="d-flex flex-column">
                                                                <span><?= date('d M Y', strtotime($b['travel_date'])) ?></span>
                                                                <small class="text-muted"><?= date('D', strtotime($b['travel_date'])) ?></small>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <div class="d-flex flex-column">
                                                                <span><?= date('h:i A', strtotime($b['departure_time'])) ?></span>
                                                                <?php if ($b['arrival_time']): ?>
                                                                    <small class="text-muted">Arrives: <?= date('h:i A', strtotime($b['arrival_time'])) ?></small>
                                                                <?php endif; ?>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-info"><?= htmlspecialchars($b['seat_number']) ?></span>
                                                        </td>
                                                        <td>
                                                            <span class="fw-bold">Rs. <?= number_format($b['total_fare'], 2) ?></span>
                                                        </td>
                                                        <td>
                                                            <div class="btn-group" role="group">
                                                                <a href="download-ticket.php?id=<?= $b['booking_id'] ?>" class="btn btn-sm btn-outline-primary" title="Download Ticket">
                                                                    <i class="bi bi-download"></i>
                                                                </a>
                                                                <button class="btn btn-sm btn-outline-secondary" title="View Details">
                                                                    <i class="bi bi-eye"></i>
                                                                </button>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endwhile; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <nav aria-label="Page navigation" class="mt-3 animate__animated animate__fadeIn">
                                        <ul class="pagination justify-content-center">
                                            <li class="page-item disabled">
                                                <a class="page-link" href="#" tabindex="-1" aria-disabled="true">Previous</a>
                                            </li>
                                            <li class="page-item active"><a class="page-link" href="#">1</a></li>
                                            <li class="page-item"><a class="page-link" href="#">2</a></li>
                                            <li class="page-item"><a class="page-link" href="#">3</a></li>
                                            <li class="page-item">
                                                <a class="page-link" href="#">Next</a>
                                            </li>
                                        </ul>
                                    </nav>
                                <?php else: ?>
                                    <div class="text-center py-4 animate__animated animate__fadeIn">
                                        <div class="floating mb-4">
                                            <i class="bi bi-ticket-perforated display-4 text-muted"></i>
                                        </div>
                                        <h5 class="mt-3">No bookings found</h5>
                                        <p class="text-muted">You haven't made any bookings yet</p>
                                        <a href="dashboard.php" class="btn btn-primary pulse">
                                            <i class="bi bi-bus-front me-1"></i> Book a Bus
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Wallet Tab -->
                    <div class="tab-pane fade" id="wallet">
                        <div class="card shadow-sm mb-4 animate__animated animate__fadeInRight">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-wallet me-2"></i>My Wallet</h5>
                            </div>
                            <div class="card-body">
                                <div class="wallet-card p-4 mb-4 animate__animated animate__fadeIn">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-1">Available Balance</h6>
                                            <h2 class="mb-0">Rs. <?= number_format($wallet['balance'] ?? 0, 2) ?></h2>
                                        </div>
                                        <i class="bi bi-wallet2 display-4 text-primary floating"></i>
                                    </div>
                                </div>
                                
                                <h5 class="mb-3 animate__animated animate__fadeIn">Top Up Wallet</h5>
                                <form method="post" class="animate__animated animate__fadeIn">
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Amount (Rs.)</label>
                                            <div class="input-group">
                                                <span class="input-group-text">Rs.</span>
                                                <input type="number" class="form-control" name="topup_amount" min="100" step="100" required>
                                            </div>
                                            <small class="text-muted">Minimum top-up amount is Rs. 100</small>
                                        </div>
                                        <div class="col-md-6 d-flex align-items-end mb-3">
                                            <button type="submit" class="btn btn-primary w-100">
                                                <i class="bi bi-plus-circle me-1"></i> Add Cash
                                            </button>
                                        </div>
                                    </div>
                                </form>
                                
                                <hr class="animate__animated animate__fadeIn">
                                
                                <h5 class="mb-3 animate__animated animate__fadeIn">Recent Transactions</h5>
                                <div class="list-group animate__animated animate__fadeIn">
                                    <a href="#" class="list-group-item list-group-item-action">
                                        <div class="d-flex w-100 justify-content-between">
                                            <div class="d-flex align-items-center">
                                                <div class="bg-success bg-opacity-10 p-2 rounded me-3">
                                                    <i class="bi bi-arrow-down-circle text-success"></i>
                                                </div>
                                                <div>
                                                    <h6 class="mb-1">Wallet Top-up</h6>
                                                    <small class="text-muted">Today, 10:45 AM</small>
                                                </div>
                                            </div>
                                            <div class="text-end">
                                                <span class="text-success fw-bold">+ Rs. 500.00</span>
                                                <div class="text-muted small">Completed</div>
                                            </div>
                                        </div>
                                    </a>
                                    <a href="#" class="list-group-item list-group-item-action">
                                        <div class="d-flex w-100 justify-content-between">
                                            <div class="d-flex align-items-center">
                                                <div class="bg-danger bg-opacity-10 p-2 rounded me-3">
                                                    <i class="bi bi-arrow-up-circle text-danger"></i>
                                                </div>
                                                <div>
                                                    <h6 class="mb-1">Bus Ticket Payment</h6>
                                                    <small class="text-muted">Yesterday, 2:30 PM</small>
                                                </div>
                                            </div>
                                            <div class="text-end">
                                                <span class="text-danger fw-bold">- Rs. 350.00</span>
                                                <div class="text-muted small">Completed</div>
                                            </div>
                                        </div>
                                    </a>
                                </div>
                                <div class="text-center mt-3 animate__animated animate__fadeIn">
                                    <a href="wallet.php" class="btn btn-outline-primary">
                                        <i class="bi bi-clock-history me-1"></i> View All Transactions
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script>
        $(document).ready(function() {
            // Auto-submit profile image form when file is selected
            $('#profileUpload').change(function() {
                $('#profileForm').submit();
            });
            
            // Form validation for profile update
            $('#profileUpdateForm').submit(function(e) {
                const dob = $('input[name="dob"]').val();
                if (dob) {
                    const dobDate = new Date(dob);
                    const today = new Date();
                    if (dobDate >= today) {
                        alert('Date of Birth must be in the past');
                        e.preventDefault();
                    }
                }
            });
            
            // Add animation to tabs when switching
            $('a[data-bs-toggle="pill"]').on('shown.bs.tab', function(e) {
                const target = $(e.target).attr('href');
                $(target).addClass('animate__animated animate__fadeIn');
                
                // Remove animation class after animation completes
                setTimeout(function() {
                    $(target).removeClass('animate__animated animate__fadeIn');
                }, 1000);
            });
            
            // Add hover effects to cards
            $('.card').hover(
                function() {
                    $(this).addClass('shadow-lg');
                },
                function() {
                    $(this).removeClass('shadow-lg');
                }
            );
            
            // Tooltip initialization
            $('[title]').tooltip();
            
            // Animate elements when they come into view
            function animateOnScroll() {
                $('.animate-on-scroll').each(function() {
                    const elementPosition = $(this).offset().top;
                    const scrollPosition = $(window).scrollTop() + $(window).height();
                    
                    if (elementPosition < scrollPosition) {
                        $(this).addClass('animate__animated animate__fadeInUp');
                    }
                });
            }
            
            // Run once on page load
            animateOnScroll();
            
            // Run on scroll
            $(window).scroll(function() {
                animateOnScroll();
            });
        });
    </script>
</body>
</html>