<?php
// admin/manage-operators.php
session_start();

// --- AUTH & PERMISSIONS ---
if (!isset($_SESSION['loggedin']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

require_once '../includes/config.php';
include 'navbar.php';

// --- CSRF TOKEN ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// --- PAGINATION & FILTERS ---
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]);
$limit = 10;
$offset = ($page - 1) * $limit;

$filters = [];
$whereClauses = [];
$params = [];
$types = '';

// Approval status filter
if (isset($_GET['approval_status']) && in_array($_GET['approval_status'], ['approved', 'pending', 'rejected'])) {
    $filters['approval_status'] = $_GET['approval_status'];
    $whereClauses[] = 'is_approved = ?';
    $params[] = $_GET['approval_status'] === 'approved' ? 1 : ($_GET['approval_status'] === 'pending' ? 0 : -1);
    $types .= 'i';
}

// Search filter
if (!empty($_GET['search'])) {
    $searchTerm = '%' . trim($_GET['search']) . '%';
    $whereClauses[] = '(o.company_name LIKE ? OR o.contact_person LIKE ? OR o.email LIKE ? OR o.phone LIKE ? OR o.license_number LIKE ?)';
    array_push($params, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
    $types .= 'sssss';
}

$whereSQL = $whereClauses ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

// --- FETCH OPERATORS ---
$sql = "SELECT o.operator_id, o.company_name, o.contact_person, o.email, o.phone, 
               o.license_number, o.is_approved, o.created_at, o.rating,
               u.user_id, u.status as user_status
        FROM operators o
        JOIN users u ON o.user_id = u.user_id
        $whereSQL
        ORDER BY o.created_at DESC
        LIMIT ?, ?";
$stmt = $conn->prepare($sql);

if ($stmt) {
    // Combine all parameters (search/status first, then pagination)
    $bindParams = $params;
    $bindParams[] = $offset;
    $bindParams[] = $limit;
    
    // Combine all types (search/status first, then pagination)
    $bindTypes = $types . 'ii';
    
    $stmt->bind_param($bindTypes, ...$bindParams);
    $stmt->execute();
    $result = $stmt->get_result();
    $operators = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $operators = [];
    error_log("Failed to prepare operator query: " . $conn->error);
}

// --- FETCH TOTAL COUNT for pagination ---
$countSQL = "SELECT COUNT(*) as total FROM operators o JOIN users u ON o.user_id = u.user_id $whereSQL";
$stmt = $conn->prepare($countSQL);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $totalOperators = $result->fetch_assoc()['total'];
    $stmt->close();
} else {
    $totalOperators = 0;
    error_log("Failed to prepare count query: " . $conn->error);
}

$totalPages = ceil($totalOperators / $limit);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Operator Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3f37c9;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --info-color: #17a2b8;
        }
        
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .operator-card {
            border-radius: 10px;
            border: 1px solid #e0e0e0;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .operator-card:hover {
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
            transform: translateY(-5px);
            border-color: var(--primary-color);
        }
        
        .operator-id {
            font-weight: 600;
            color: var(--primary-color);
            background-color: rgba(67, 97, 238, 0.1);
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
        }
        
        .company-name {
            font-weight: 700;
            font-size: 1.2rem;
            color: #2c3e50;
        }
        
        .license-id {
            font-size: 0.85rem;
            color: #6c757d;
            background-color: #f8f9fa;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            display: inline-block;
        }
        
        .contact-info {
            font-size: 0.95rem;
            margin-bottom: 0.5rem;
        }
        
        .status-badge {
            font-size: 0.75rem;
            padding: 0.35em 0.65em;
            border-radius: 50px;
            font-weight: 600;
        }
        
        .badge-approved {
            background-color: rgba(40, 167, 69, 0.1);
            color: var(--success-color);
            border: 1px solid rgba(40, 167, 69, 0.3);
        }
        
        .badge-pending {
            background-color: rgba(255, 193, 7, 0.1);
            color: var(--warning-color);
            border: 1px solid rgba(255, 193, 7, 0.3);
        }
        
        .badge-rejected {
            background-color: rgba(220, 53, 69, 0.1);
            color: var(--danger-color);
            border: 1px solid rgba(220, 53, 69, 0.3);
        }
        
        .rating-stars {
            color: var(--warning-color);
        }
        
        .action-btn {
            width: 36px;
            height: 36px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            transition: all 0.2s ease;
        }
        
        .action-btn:hover {
            transform: scale(1.1);
        }
        
        .card-header-gradient {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
        }
        
        .search-box {
            position: relative;
        }
        
        .search-box .form-control {
            padding-left: 40px;
            border-radius: 8px;
            border: 1px solid #dee2e6;
        }
        
        .search-box .search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
        }
        
        .pagination .page-item.active .page-link {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .pagination .page-link {
            color: var(--primary-color);
            border-radius: 6px;
            margin: 0 3px;
        }
        
        .pagination .page-link:hover {
            background-color: rgba(67, 97, 238, 0.1);
        }
        
        .filter-select {
            border-radius: 8px;
            border: 1px solid #dee2e6;
            cursor: pointer;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #6c757d;
        }
        
        .empty-state i {
            font-size: 4rem;
            color: #dee2e6;
            margin-bottom: 1rem;
        }
        
        .loading-spinner {
            display: none;
            text-align: center;
            padding: 2rem;
        }
        
        .spinner-border {
            width: 3rem;
            height: 3rem;
            color: var(--primary-color);
        }
        
        @media (max-width: 768px) {
            .operator-card {
                padding: 1.25rem;
            }
            
            .mobile-stack {
                flex-direction: column;
            }
            
            .mobile-mb-2 {
                margin-bottom: 0.75rem;
            }
            
            .company-name {
                font-size: 1.1rem;
            }
        }
        
        /* Animation for status change */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .fade-in {
            animation: fadeIn 0.3s ease-out forwards;
        }
    </style>
</head>
<body>
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0 fw-bold text-primary">
                <i class="fas fa-users-cog me-2"></i>Operator Management
            </h2>
            <a href="register_operator.php" class="btn btn-primary">
                <i class="fas fa-plus me-2"></i>Add Operator
            </a>
        </div>

        <!-- Search and Filters -->
        <div class="card mb-4 shadow-sm border-0">
            <div class="card-header card-header-gradient py-3">
                <h5 class="mb-0 text-white"><i class="fas fa-filter me-2"></i>Filters</h5>
            </div>
            <div class="card-body">
                <form id="filterForm" method="get" class="row g-3">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    
                    <div class="col-md-8">
                        <div class="search-box">
                            <i class="fas fa-search search-icon"></i>
                            <input type="text" name="search" class="form-control form-control-lg" 
                                   placeholder="Search operators..." value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <select name="approval_status" class="form-select form-select-lg filter-select">
                            <option value="">All Statuses</option>
                            <option value="approved" <?php echo (isset($_GET['approval_status'])) && $_GET['approval_status'] === 'approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="pending" <?php echo (isset($_GET['approval_status'])) && $_GET['approval_status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="rejected" <?php echo (isset($_GET['approval_status'])) && $_GET['approval_status'] === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        </select>
                    </div>
                    
                    <div class="col-12 text-end">
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="fas fa-filter me-2"></i>Apply Filters
                        </button>
                        <?php if (!empty($_GET['search']) || !empty($_GET['approval_status'])): ?>
                        <a href="manage-operators.php" class="btn btn-outline-secondary ms-2">
                            <i class="fas fa-times me-2"></i>Clear
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- Loading Spinner -->
        <div class="loading-spinner" id="loadingSpinner">
            <div class="spinner-border" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-3 text-muted">Loading operators...</p>
        </div>

        <!-- Operators List -->
        <div id="operatorsContainer">
            <?php if (empty($operators)): ?>
                <div class="card shadow-sm border-0">
                    <div class="card-body empty-state">
                        <i class="fas fa-users-slash"></i>
                        <h4 class="text-muted">No operators found</h4>
                        <p class="mb-4">Try adjusting your search or filter criteria</p>
                        <a href="manage-operators.php" class="btn btn-primary">
                            <i class="fas fa-sync-alt me-2"></i>Reset Filters
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <div class="row" id="operatorsList">
                    <?php foreach ($operators as $operator): ?>
                        <?php
                        $statusClass = '';
                        $statusIcon = '';
                        $statusText = '';
                        
                        if ($operator['is_approved'] == 1) {
                            $statusClass = 'badge-approved';
                            $statusIcon = 'fa-check-circle';
                            $statusText = 'Approved';
                        } elseif ($operator['is_approved'] == 0) {
                            $statusClass = 'badge-pending';
                            $statusIcon = 'fa-clock';
                            $statusText = 'Pending';
                        } else {
                            $statusClass = 'badge-rejected';
                            $statusIcon = 'fa-times-circle';
                            $statusText = 'Rejected';
                        }
                        
                        $rating = $operator['rating'] ?? 0;
                        $fullStars = floor($rating);
                        $hasHalfStar = ($rating - $fullStars) >= 0.5;
                        $emptyStars = 5 - $fullStars - ($hasHalfStar ? 1 : 0);
                        ?>
                        
                        <div class="col-lg-4 col-md-6 mb-4 fade-in">
                            <div class="operator-card p-3 bg-white h-100 shadow-sm">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <span class="operator-id">#<?php echo $operator['operator_id']; ?></span>
                                    <span class="status-badge <?php echo $statusClass; ?>">
                                        <i class="fas <?php echo $statusIcon; ?> me-1"></i><?php echo $statusText; ?>
                                    </span>
                                </div>
                                
                                <div class="company-name mb-1"><?php echo htmlspecialchars($operator['company_name']); ?></div>
                                <div class="license-id mb-3">ID: <?php echo htmlspecialchars($operator['license_number']); ?></div>
                                
                                <div class="contact-info mb-2">
                                    <i class="fas fa-user me-2 text-muted"></i><?php echo htmlspecialchars($operator['contact_person']); ?>
                                </div>
                                <div class="contact-info mb-2">
                                    <i class="fas fa-envelope me-2 text-muted"></i><?php echo htmlspecialchars($operator['email']); ?>
                                </div>
                                <div class="contact-info mb-3">
                                    <i class="fas fa-phone me-2 text-muted"></i><?php echo htmlspecialchars($operator['phone']); ?>
                                </div>
                                
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="rating-stars">
                                            <?php for ($i = 0; $i < $fullStars; $i++): ?>
                                                <i class="fas fa-star"></i>
                                            <?php endfor; ?>
                                            
                                            <?php if ($hasHalfStar): ?>
                                                <i class="fas fa-star-half-alt"></i>
                                            <?php endif; ?>
                                            
                                            <?php for ($i = 0; $i < $emptyStars; $i++): ?>
                                                <i class="far fa-star"></i>
                                            <?php endfor; ?>
                                            
                                            <small class="text-muted ms-1"><?php echo number_format($rating, 1); ?></small>
                                        </div>
                                        <small class="text-muted">Registered: <?php echo date('M Y', strtotime($operator['created_at'])); ?></small>
                                    </div>
                                    <div>
                                        <button class="action-btn btn btn-outline-primary me-2" title="Edit" 
                                                onclick="editOperator(<?php echo $operator['operator_id']; ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="action-btn btn btn-outline-danger" title="Delete" 
                                                onclick="confirmDelete(<?php echo $operator['operator_id']; ?>, '<?php echo htmlspecialchars(addslashes($operator['company_name'])); ?>')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                                
                                <!-- Quick Action Buttons -->
                                <div class="mt-3 pt-2 border-top d-flex justify-content-between">
                                    <?php if ($operator['is_approved'] != 1): ?>
                                        <button class="btn btn-sm btn-success flex-grow-1 me-2" 
                                                onclick="updateStatus(<?php echo $operator['operator_id']; ?>, 'approve')">
                                            <i class="fas fa-check me-1"></i>Approve
                                        </button>
                                    <?php endif; ?>
                                    
                                    <?php if ($operator['is_approved'] != -1): ?>
                                        <button class="btn btn-sm btn-danger flex-grow-1" 
                                                onclick="updateStatus(<?php echo $operator['operator_id']; ?>, 'reject')">
                                            <i class="fas fa-times me-1"></i>Reject
                                        </button>
                                    <?php endif; ?>
                                    
                                    <?php if ($operator['is_approved'] == -1): ?>
                                        <button class="btn btn-sm btn-warning flex-grow-1" 
                                                onclick="updateStatus(<?php echo $operator['operator_id']; ?>, 'pending')">
                                            <i class="fas fa-clock me-1"></i>Set Pending
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <nav aria-label="Page navigation" class="mt-4">
            <ul class="pagination justify-content-center">
                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                        <i class="fas fa-chevron-left me-1"></i>Previous
                    </a>
                </li>
                
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
                
                <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                        Next<i class="fas fa-chevron-right ms-1"></i>
                    </a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Document ready
        document.addEventListener('DOMContentLoaded', function() {
            // Apply fade-in animation to operator cards
            const cards = document.querySelectorAll('.fade-in');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.05}s`;
            });
        });
        
        // Edit operator - redirect to edit page
        function editOperator(operatorId) {
            window.location.href = `edit_operator.php?id=${operatorId}`;
        }
        
        // Confirm delete operator
        function confirmDelete(operatorId, companyName) {
            Swal.fire({
                title: 'Confirm Delete',
                html: `Are you sure you want to delete <strong>${companyName}</strong>?<br>This action cannot be undone.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, delete it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    deleteOperator(operatorId);
                }
            });
        }
        
        // Delete operator
        function deleteOperator(operatorId) {
            const formData = new FormData();
            formData.append('operator_id', operatorId);
            formData.append('csrf_token', '<?php echo $csrf_token; ?>');
            
            fetch('ajax/delete_operator.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Deleted!',
                        text: 'Operator has been deleted',
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: data.message || 'Failed to delete operator'
                    });
                }
            })
            .catch(error => {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'An error occurred while deleting the operator'
                });
                console.error('Error:', error);
            });
        }
        
        // Enhanced operator status update function
        function updateStatus(operatorId, action) {
            // First confirmation dialog
            Swal.fire({
                title: `Confirm ${capitalizeFirstLetter(action)}`,
                html: `Are you sure you want to ${action} this operator?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: `Yes, ${action}`,
                cancelButtonText: 'Cancel',
                showLoaderOnConfirm: true,
                preConfirm: async () => {
                    try {
                        const formData = new FormData();
                        formData.append('operator_id', operatorId);
                        formData.append('action', action);
                        formData.append('csrf_token', '<?php echo $csrf_token; ?>');
                        
                        // Additional step for rejections
                        if (action === 'reject') {
                            const { value: reason, isConfirmed } = await Swal.fire({
                                title: 'Reason for Rejection',
                                input: 'textarea',
                                inputPlaceholder: 'Please explain the reason for rejection...',
                                showCancelButton: true,
                                confirmButtonText: 'Confirm Rejection',
                                inputValidator: (value) => !value && 'Please provide a reason!'
                            });
                            
                            if (!isConfirmed) return Promise.reject('Cancelled');
                            formData.append('reason', reason);
                        }

                        const response = await fetch('ajax/update_operator_status.php', {
                            method: 'POST',
                            body: formData
                        });

                        if (!response.ok) {
                            const errorData = await response.json();
                            throw new Error(errorData.error || 'Server responded with an error');
                        }

                        return await response.json();
                    } catch (error) {
                        console.error('Update error:', error);
                        throw error;
                    }
                },
                allowOutsideClick: () => !Swal.isLoading()
            }).then((result) => {
                if (result.isConfirmed) {
                    handleUpdateResult(result.value, action);
                }
            }).catch((error) => {
                if (error !== 'Cancelled') {
                    showUpdateError(error, action);
                }
            });
        }

        // Helper functions
        function capitalizeFirstLetter(string) {
            return string.charAt(0).toUpperCase() + string.slice(1);
        }

        function handleUpdateResult(data, action) {
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: data.message || `Operator ${action}d successfully`,
                    timer: 1500,
                    showConfirmButton: false
                }).then(() => {
                    // Refresh only the operators list instead of full page reload
                    refreshOperatorsList();
                });
            } else {
                throw new Error(data.error || `Failed to ${action} operator`);
            }
        }

        function showUpdateError(error, action) {
            console.error('Status update failed:', error);
            
            let errorMessage = error.message || `An error occurred while ${action}ing the operator`;
            
            // Special handling for common error cases
            if (error.message.includes('CSRF')) {
                errorMessage = 'Session expired. Please refresh the page and try again.';
            } else if (error.message.includes('database')) {
                errorMessage = 'Database error. Please try again or contact support.';
            }
            
            Swal.fire({
                icon: 'error',
                title: 'Error',
                html: `<div>${errorMessage}</div>
                       <small class="text-muted">Error reference: ${new Date().getTime()}</small>`,
                confirmButtonText: 'OK'
            });
        }

        function refreshOperatorsList() {
            // Show loading indicator
            const container = document.getElementById('operatorsContainer');
            container.innerHTML = `
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            `;
            
            // Reload the operators list via AJAX
            fetch(window.location.pathname + '?ajax=1')
                .then(response => response.text())
                .then(html => {
                    container.innerHTML = html;
                    // Reinitialize any event listeners if needed
                    initOperatorCards();
                })
                .catch(error => {
                    console.error('Refresh failed:', error);
                    location.reload(); // Fallback to full reload
                });
        }
        
        // Filter form submission with loading indicator
        document.getElementById('filterForm').addEventListener('submit', function(e) {
            document.getElementById('loadingSpinner').style.display = 'block';
            document.getElementById('operatorsContainer').style.display = 'none';
        });
    </script>
</body>
</html>