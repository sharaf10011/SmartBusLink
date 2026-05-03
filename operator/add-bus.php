<?php
session_start();
require_once '../includes/config.php';

// Database connection function (same as in your original file)
function getDatabaseConnection() {
    static $db = null;
    if ($db === null) {
        try {
            $db = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }
    return $db;
}

// Authentication function (same as in your original file)
function authenticateUser($requiredType) {
    if (!isset($_SESSION['user_id'])) {
        return false;
    }

    $db = getDatabaseConnection();
    $stmt = $db->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return ($user && $user['user_type'] === $requiredType) ? $user : false;
}

// Get operator by user ID (same as in your original file)
function getOperatorByUserId($userId) {
    $db = getDatabaseConnection();
    $stmt = $db->prepare("SELECT * FROM operators WHERE user_id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get bus types for dropdown
function getBusTypes() {
    $db = getDatabaseConnection();
    $stmt = $db->query("SELECT * FROM bus_types ORDER BY type_name");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Main logic
$user = authenticateUser('operator');
if (!$user) {
    header('Location: /login.php?redirect=operator/add-bus.php');
    exit;
}

$operator = getOperatorByUserId($user['user_id']);
if (!$operator) {
    die('Operator account not properly configured');
}

$busTypes = getBusTypes();

// Form submission handling
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate and sanitize inputs
    $name = trim($_POST['name'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $year = intval($_POST['year'] ?? date('Y'));
    $registrationNumber = trim($_POST['registration_number'] ?? '');
    $typeId = intval($_POST['type_id'] ?? 0);
    $seatCapacity = intval($_POST['seat_capacity'] ?? 0);
    $features = trim($_POST['features'] ?? '');
    $imageUrl = trim($_POST['image_url'] ?? '');
    $status = in_array($_POST['status'] ?? '', ['active', 'maintenance', 'inactive']) ? $_POST['status'] : 'active';

    // Validate inputs
    if (empty($name)) {
        $errors['name'] = 'Bus name is required';
    }
    
    if (empty($model)) {
        $errors['model'] = 'Bus model is required';
    }
    
    if ($year < 1990 || $year > (date('Y') + 1)) {
        $errors['year'] = 'Invalid year';
    }
    
    if (empty($registrationNumber)) {
        $errors['registration_number'] = 'Registration number is required';
    }
    
    if ($typeId <= 0) {
        $errors['type_id'] = 'Please select a bus type';
    }
    
    if ($seatCapacity <= 0 || $seatCapacity > 100) {
        $errors['seat_capacity'] = 'Seat capacity must be between 1 and 100';
    }
    
    // Process image upload if provided
    $uploadedImageUrl = '';
    if (isset($_FILES['bus_image']) && $_FILES['bus_image']['error'] === UPLOAD_ERR_OK) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
        $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedType = finfo_file($fileInfo, $_FILES['bus_image']['tmp_name']);
        
        if (in_array($detectedType, $allowedTypes)) {
            $uploadDir = '../uploads/buses/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $extension = pathinfo($_FILES['bus_image']['name'], PATHINFO_EXTENSION);
            $filename = 'bus_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
            $destination = $uploadDir . $filename;
            
            if (move_uploaded_file($_FILES['bus_image']['tmp_name'], $destination)) {
                $uploadedImageUrl = '/uploads/buses/' . $filename;
            } else {
                $errors['bus_image'] = 'Failed to upload image';
            }
        } else {
            $errors['bus_image'] = 'Invalid file type. Only JPG, PNG, and GIF are allowed.';
        }
    }
    
    // If no errors, insert into database
    if (empty($errors)) {
        try {
            $db = getDatabaseConnection();
            $stmt = $db->prepare("
                INSERT INTO buses (
                    operator_id, name, model, year, registration_number, 
                    type_id, seat_capacity, features, image_url, status, is_active
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $isActive = $status === 'active' ? 1 : 0;
            $imageToUse = !empty($uploadedImageUrl) ? $uploadedImageUrl : $imageUrl;
            
            $stmt->execute([
                $operator['operator_id'],
                $name,
                $model,
                $year,
                $registrationNumber,
                $typeId,
                $seatCapacity,
                $features,
                $imageToUse,
                $status,
                $isActive
            ]);
            
            $success = true;
            
            // Clear form if success
            if ($success) {
                $_POST = [];
            }
        } catch (PDOException $e) {
            $errors['database'] = 'Failed to add bus: ' . $e->getMessage();
        }
    }
}
?>

<?php include 'header.php'; ?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add New Bus</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-color: #4e73df;
            --secondary-color: #858796;
            --success-color: #1cc88a;
            --info-color: #36b9cc;
            --warning-color: #f6c23e;
            --danger-color: #e74a3b;
            --light-color: #f8f9fc;
            --dark-color: #5a5c69;
        }
        
        body {
            background-color: #f8f9fc;
            color: #333;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .card {
            border: none;
            border-radius: 0.5rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
        }
        
        .form-section {
            background-color: white;
            border-radius: 0.5rem;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.1);
        }
        
        .form-title {
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid #e3e6f0;
        }
        
        .form-label {
            font-weight: 600;
            color: var(--dark-color);
        }
        
        .form-control, .form-select {
            border-radius: 0.35rem;
            padding: 0.75rem 1rem;
            border: 1px solid #d1d3e2;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(78, 115, 223, 0.25);
        }
        
        .is-invalid {
            border-color: var(--danger-color);
        }
        
        .invalid-feedback {
            color: var(--danger-color);
            font-size: 0.85rem;
        }
        
        .preview-image {
            max-width: 100%;
            height: auto;
            border-radius: 0.35rem;
            margin-top: 1rem;
            display: none;
        }
        
        .image-upload-container {
            border: 2px dashed #d1d3e2;
            border-radius: 0.35rem;
            padding: 2rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .image-upload-container:hover {
            border-color: var(--primary-color);
            background-color: rgba(78, 115, 223, 0.05);
        }
        
        .image-upload-icon {
            font-size: 2.5rem;
            color: var(--secondary-color);
            margin-bottom: 1rem;
        }
        
        .toast-container {
            position: fixed;
            bottom: 1.5rem;
            right: 1.5rem;
            z-index: 1100;
        }
        
        @media (max-width: 768px) {
            .form-section {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h3 mb-2 text-gray-800">Add New Bus</h1>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="available-buses.php">Buses</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Add New</li>
                    </ol>
                </nav>
            </div>
            <div>
                <a href="manage-bus.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-2"></i>Back to Buses
                </a>
            </div>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success d-flex align-items-center" role="alert">
                <i class="bi bi-check-circle-fill fs-4 me-3"></i>
                <div>
                    <h5 class="alert-heading mb-1">Bus added successfully!</h5>
                    <p class="mb-0">Your new bus has been added to the fleet.</p>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($errors['database'])): ?>
            <div class="alert alert-danger d-flex align-items-center" role="alert">
                <i class="bi bi-exclamation-triangle-fill fs-4 me-3"></i>
                <div>
                    <h5 class="alert-heading mb-1">Error occurred</h5>
                    <p class="mb-0"><?= htmlspecialchars($errors['database']) ?></p>
                </div>
            </div>
        <?php endif; ?>
        
        <form method="post" enctype="multipart/form-data" class="needs-validation" novalidate>
            <div class="row">
                <div class="col-lg-8">
                    <div class="form-section">
                        <h3 class="form-title">Basic Information</h3>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="name" class="form-label">Bus Name *</label>
                                <input type="text" class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>" 
                                       id="name" name="name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
                                <?php if (isset($errors['name'])): ?>
                                    <div class="invalid-feedback"><?= htmlspecialchars($errors['name']) ?></div>
                                <?php else: ?>
                                    <div class="form-text">e.g. Express Deluxe, City Shuttle</div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="model" class="form-label">Bus Model *</label>
                                <input type="text" class="form-control <?= isset($errors['model']) ? 'is-invalid' : '' ?>" 
                                       id="model" name="model" value="<?= htmlspecialchars($_POST['model'] ?? '') ?>" required>
                                <?php if (isset($errors['model'])): ?>
                                    <div class="invalid-feedback"><?= htmlspecialchars($errors['model']) ?></div>
                                <?php else: ?>
                                    <div class="form-text">e.g. Volvo B9R, Scania K360</div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="year" class="form-label">Manufacturing Year *</label>
                                <input type="number" class="form-control <?= isset($errors['year']) ? 'is-invalid' : '' ?>" 
                                       id="year" name="year" min="1990" max="<?= date('Y') + 1 ?>" 
                                       value="<?= htmlspecialchars($_POST['year'] ?? date('Y')) ?>" required>
                                <?php if (isset($errors['year'])): ?>
                                    <div class="invalid-feedback"><?= htmlspecialchars($errors['year']) ?></div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="registration_number" class="form-label">Registration Number *</label>
                                <input type="text" class="form-control <?= isset($errors['registration_number']) ? 'is-invalid' : '' ?>" 
                                       id="registration_number" name="registration_number" 
                                       value="<?= htmlspecialchars($_POST['registration_number'] ?? '') ?>" required>
                                <?php if (isset($errors['registration_number'])): ?>
                                    <div class="invalid-feedback"><?= htmlspecialchars($errors['registration_number']) ?></div>
                                <?php else: ?>
                                    <div class="form-text">e.g. ABC-1234, XYZ-5678</div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="type_id" class="form-label">Bus Type *</label>
                                <select class="form-select <?= isset($errors['type_id']) ? 'is-invalid' : '' ?>" 
                                        id="type_id" name="type_id" required>
                                    <option value="">Select Bus Type</option>
                                    <?php foreach ($busTypes as $type): ?>
                                        <option value="<?= $type['type_id'] ?>" 
                                            <?= ($_POST['type_id'] ?? '') == $type['type_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($type['type_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (isset($errors['type_id'])): ?>
                                    <div class="invalid-feedback"><?= htmlspecialchars($errors['type_id']) ?></div>
                                <?php else: ?>
                                    <div class="form-text">Select the appropriate bus type</div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="seat_capacity" class="form-label">Seat Capacity *</label>
                                <input type="number" class="form-control <?= isset($errors['seat_capacity']) ? 'is-invalid' : '' ?>" 
                                       id="seat_capacity" name="seat_capacity" min="1" max="100" 
                                       value="<?= htmlspecialchars($_POST['seat_capacity'] ?? '') ?>" required>
                                <?php if (isset($errors['seat_capacity'])): ?>
                                    <div class="invalid-feedback"><?= htmlspecialchars($errors['seat_capacity']) ?></div>
                                <?php else: ?>
                                    <div class="form-text">Total number of seats (1-100)</div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="col-12">
                                <label for="features" class="form-label">Additional Features</label>
                                <textarea class="form-control" id="features" name="features" 
                                          rows="2"><?= htmlspecialchars($_POST['features'] ?? '') ?></textarea>
                                <div class="form-text">Any special features (separate with commas)</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h3 class="form-title">Status & Availability</h3>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Initial Status *</label>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="status" id="status_active" 
                                           value="active" <?= ($_POST['status'] ?? 'active') === 'active' ? 'checked' : '' ?> required>
                                    <label class="form-check-label text-success fw-bold" for="status_active">
                                        <i class="bi bi-check-circle-fill me-1"></i> Active
                                    </label>
                                    <div class="form-text">Bus is ready for service</div>
                                </div>
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="radio" name="status" id="status_maintenance" 
                                           value="maintenance" <?= ($_POST['status'] ?? '') === 'maintenance' ? 'checked' : '' ?>>
                                    <label class="form-check-label text-warning fw-bold" for="status_maintenance">
                                        <i class="bi bi-tools me-1"></i> Maintenance
                                    </label>
                                    <div class="form-text">Bus is undergoing maintenance</div>
                                </div>
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="radio" name="status" id="status_inactive" 
                                           value="inactive" <?= ($_POST['status'] ?? '') === 'inactive' ? 'checked' : '' ?>>
                                    <label class="form-check-label text-danger fw-bold" for="status_inactive">
                                        <i class="bi bi-slash-circle-fill me-1"></i> Inactive
                                    </label>
                                    <div class="form-text">Bus is not available for service</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-4">
                    <div class="form-section">
                        <h3 class="form-title">Bus Image</h3>
                        
                        <div class="mb-3">
                            <label for="image_url" class="form-label">Image URL</label>
                            <input type="url" class="form-control" id="image_url" name="image_url" 
                                   value="<?= htmlspecialchars($_POST['image_url'] ?? '') ?>">
                            <div class="form-text">Or upload an image below</div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="bus_image" class="form-label">Upload Image</label>
                            <input type="file" class="form-control visually-hidden" id="bus_image" name="bus_image" accept="image/*">
                            
                            <div class="image-upload-container" id="imageUploadContainer" onclick="document.getElementById('bus_image').click()">
                                <div class="image-upload-icon">
                                    <i class="bi bi-cloud-arrow-up"></i>
                                </div>
                                <h5 class="mb-2">Click to upload bus image</h5>
                                <p class="text-muted small mb-0">JPG, PNG or GIF (Max 5MB)</p>
                            </div>
                            
                            <?php if (isset($errors['bus_image'])): ?>
                                <div class="text-danger small mt-2"><?= htmlspecialchars($errors['bus_image']) ?></div>
                            <?php endif; ?>
                            
                            <img id="imagePreview" class="preview-image" alt="Preview">
                        </div>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="bi bi-save-fill me-2"></i> Save Bus
                        </button>
                        <button type="reset" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-counterclockwise me-2"></i> Reset Form
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
    
    <!-- Toast Notification -->
    <div class="toast-container">
        <div id="statusToast" class="toast align-items-center text-white bg-success border-0" role="alert">
            <div class="d-flex">
                <div class="toast-body">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    Bus added successfully!
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Custom JavaScript -->
    <script>
        // Image preview functionality
        document.getElementById('bus_image').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(event) {
                    const preview = document.getElementById('imagePreview');
                    preview.src = event.target.result;
                    preview.style.display = 'block';
                    
                    // Update the upload container
                    const container = document.getElementById('imageUploadContainer');
                    container.innerHTML = `
                        <div class="text-center">
                            <img src="${event.target.result}" class="img-fluid rounded" style="max-height: 200px;">
                            <p class="mt-2 mb-0 text-success small">
                                <i class="bi bi-check-circle-fill me-1"></i>
                                ${file.name} (${(file.size / 1024).toFixed(1)} KB)
                            </p>
                            <button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="resetImageUpload(event)">
                                <i class="bi bi-x-circle me-1"></i>Change Image
                            </button>
                        </div>
                    `;
                };
                reader.readAsDataURL(file);
            }
        });
        
        function resetImageUpload(e) {
            e.stopPropagation();
            const input = document.getElementById('bus_image');
            input.value = '';
            
            const container = document.getElementById('imageUploadContainer');
            container.innerHTML = `
                <div class="image-upload-icon">
                    <i class="bi bi-cloud-arrow-up"></i>
                </div>
                <h5 class="mb-2">Click to upload bus image</h5>
                <p class="text-muted small mb-0">JPG, PNG or GIF (Max 5MB)</p>
            `;
            
            document.getElementById('imagePreview').style.display = 'none';
        }
        
        // Form validation
        (function () {
            'use strict'
            
            // Fetch all the forms we want to apply custom Bootstrap validation styles to
            const forms = document.querySelectorAll('.needs-validation')
            
            // Loop over them and prevent submission
            Array.from(forms).forEach(form => {
                form.addEventListener('submit', event => {
                    if (!form.checkValidity()) {
                        event.preventDefault()
                        event.stopPropagation()
                    }
                    
                    form.classList.add('was-validated')
                }, false)
            })
        })()
        
        // Show success toast if form was submitted successfully
        <?php if ($success): ?>
            document.addEventListener('DOMContentLoaded', function() {
                const toast = new bootstrap.Toast(document.getElementById('statusToast'));
                toast.show();
            });
        <?php endif; ?>
    </script>
</body>
</html>