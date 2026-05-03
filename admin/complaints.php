<?php
session_start();
require_once('../includes/config.php');
require_once('../includes/auth.php');

// Check admin privileges before any output
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Database connection
try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Handle status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_status'])) {
        $ticket_id = $_POST['ticket_id'];
        $new_status = $_POST['new_status'];
        $admin_notes = $_POST['admin_notes'] ?? null;
        
        try {
            $stmt = $pdo->prepare("UPDATE support_tickets SET status = ?, admin_notes = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$new_status, $admin_notes, $ticket_id]);
            $_SESSION['success'] = "Ticket status updated successfully!";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Error updating ticket: " . $e->getMessage();
        }
        
        header("Location: complaints.php");
        exit();
    }
}

// Fetch all support tickets with user information
$tickets = [];
try {
    $query = "SELECT 
            t.id, t.subject, t.message, t.priority, t.status, 
            t.created_at, t.updated_at, t.admin_notes,
            u.user_id, u.username, u.email, 
            CONCAT(u.first_name, ' ', u.last_name) AS full_name
          FROM support_tickets t 
          JOIN users u ON t.user_id = u.user_id 
          ORDER BY t.created_at DESC";
    $stmt = $pdo->query($query);
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $_SESSION['error'] = "Error fetching tickets: " . $e->getMessage();
}

include 'navbar.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support Ticket Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <div class="container mt-2">
    <style>
        :root {
            --primary-color: #4e73df;
            --secondary-color: #1cc88a;
            --danger-color: #e74a3b;
            --warning-color: #f6c23e;
            --info-color: #36b9cc;
            --dark-color: #2e384d;
        }
        
        body {
            background-color: #f8f9fc;
            font-family: 'Inter', sans-serif;
        }
        
        .card {
            border: none;
            border-radius: 0.75rem;
            box-shadow: 0 0.5rem 1.5rem rgba(0, 0, 0, 0.08);
            margin-bottom: 1.5rem;
        }
        
        .card-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, #3a56d6 100%);
            color: white;
            border-bottom: none;
            border-radius: 0.75rem 0.75rem 0 0 !important;
        }
        
        .filter-card {
            background-color: #fff;
            border-radius: 0.75rem;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }
        
        .ticket-card {
            transition: all 0.3s ease;
            border-left: 4px solid;
        }
        
        .ticket-open {
            border-left-color: var(--danger-color);
            background-color: rgba(231, 74, 59, 0.05);
        }
        
        .ticket-pending {
            border-left-color: var(--warning-color);
            background-color: rgba(246, 194, 62, 0.05);
        }
        
        .ticket-resolved {
            border-left-color: var(--secondary-color);
            background-color: rgba(28, 200, 138, 0.05);
        }
        
        .status-badge {
            font-size: 0.75rem;
            font-weight: 500;
            padding: 0.35rem 0.75rem;
            border-radius: 100px;
        }
        
        .priority-badge {
            font-size: 0.7rem;
            font-weight: 500;
            padding: 0.3rem 0.6rem;
            border-radius: 100px;
        }
        
        .message-preview {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .search-box {
            position: relative;
        }
        
        .search-box i {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
        }
        
        .search-box input {
            padding-left: 35px;
        }
        
        .page-container {
            padding: 2rem 0;
        }
        
        /* Mobile responsiveness */
        @media (max-width: 992px) {
            .card-header h5 {
                font-size: 1.1rem;
            }
        }
        
        @media (max-width: 768px) {
            .table-responsive {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            
            .table th, .table td {
                white-space: nowrap;
            }
            
            .status-badge, .priority-badge {
                font-size: 0.7rem;
                padding: 0.25rem 0.5rem;
            }
        }
        
        @media (max-width: 576px) {
            body {
                font-size: 0.9rem;
            }
            
            .ticket-card {
                padding: 1rem;
            }
            
            .btn-action {
                width: 30px;
                height: 30px;
                padding: 0;
            }
            
            .filter-card {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid page-container">
        <div class="row mb-4">
            <div class="col">
                <h1 class="h3 fw-bold"><i class="bi bi-headset me-2"></i>Support Ticket Management</h1>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a></li>
                        <li class="breadcrumb-item active"><i class="bi bi-headset me-1"></i>Support Tickets</li>
                    </ol>
                </nav>
            </div>
        </div>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show mb-4">
                <div class="d-flex align-items-center">
                    <i class="bi bi-exclamation-triangle-fill me-2 fs-4"></i>
                    <div><?= $_SESSION['error']; unset($_SESSION['error']); ?></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show mb-4">
                <div class="d-flex align-items-center">
                    <i class="bi bi-check-circle-fill me-2 fs-4"></i>
                    <div><?= $_SESSION['success']; unset($_SESSION['success']); ?></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Filter Section at Top -->
        <div class="filter-card">
            <div class="row">
                <div class="col-md-12">
                    <h5 class="mb-3"><i class="bi bi-funnel me-2"></i>Filters</h5>
                    <form id="filterForm" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Status</label>
                            <select class="form-select" name="status">
                                <option value="all">All Tickets</option>
                                <option value="open">Open</option>
                                <option value="pending">Pending</option>
                                <option value="resolved">Resolved</option>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Priority</label>
                            <select class="form-select" name="priority">
                                <option value="all">All Priorities</option>
                                <option value="low">Low</option>
                                <option value="medium">Medium</option>
                                <option value="high">High</option>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Date Range</label>
                            <input type="text" class="form-control" name="date_range" id="dateRange" placeholder="Select date range">
                        </div>
                        
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-funnel me-1"></i> Apply Filters
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Main Ticket Table -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
                <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>Ticket Overview</h5>
                <div class="d-flex gap-2 mt-2 mt-md-0">
                    <div class="search-box">
                        <i class="bi bi-search"></i>
                        <input type="text" class="form-control" placeholder="Search tickets..." id="searchInput">
                    </div>
                    <button class="btn btn-outline-primary">
                        <i class="bi bi-download me-1"></i> Export
                    </button>
                </div>
            </div>
            <div class="card-body p-0">
                <?php if (empty($tickets)): ?>
                    <div class="empty-state p-4 text-center">
                        <i class="bi bi-headset fs-1 text-muted"></i>
                        <h5 class="mt-3">No support tickets found</h5>
                        <p class="text-muted">When customers submit support requests, they'll appear here</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" id="ticketsTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Ticket ID</th>
                                    <th>Subject</th>
                                    <th>Customer</th>
                                    <th>Priority</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tickets as $ticket): ?>
                                    <tr class="ticket-row" data-status="<?= $ticket['status'] ?>" data-priority="<?= $ticket['priority'] ?>">
                                        <td>#<?= str_pad($ticket['id'], 5, '0', STR_PAD_LEFT) ?></td>
                                        <td>
                                            <div class="fw-semibold"><?= htmlspecialchars($ticket['subject']) ?></div>
                                            <small class="text-muted message-preview"><?= htmlspecialchars($ticket['message']) ?></small>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="flex-shrink-0 bg-primary bg-opacity-10 rounded p-2 me-2">
                                                    <i class="bi bi-person text-primary"></i>
                                                </div>
                                                <div>
                                                    <div class="fw-semibold"><?= htmlspecialchars($ticket['username']) ?></div>
                                                    <small class="text-muted"><?= htmlspecialchars($ticket['email']) ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php 
                                            $priorityClass = '';
                                            if ($ticket['priority'] === 'high') {
                                                $priorityClass = 'bg-danger-subtle text-danger';
                                            } elseif ($ticket['priority'] === 'medium') {
                                                $priorityClass = 'bg-warning-subtle text-warning';
                                            } else {
                                                $priorityClass = 'bg-success-subtle text-success';
                                            }
                                            ?>
                                            <span class="priority-badge <?= $priorityClass ?>">
                                                <?= ucfirst($ticket['priority']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php 
                                            $statusClass = '';
                                            if ($ticket['status'] === 'open') {
                                                $statusClass = 'bg-danger-subtle text-danger';
                                            } elseif ($ticket['status'] === 'pending') {
                                                $statusClass = 'bg-warning-subtle text-warning';
                                            } else {
                                                $statusClass = 'bg-success-subtle text-success';
                                            }
                                            ?>
                                            <span class="status-badge <?= $statusClass ?>">
                                                <?= ucfirst($ticket['status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <small class="d-block"><?= date('M j, Y', strtotime($ticket['created_at'])) ?></small>
                                            <small class="text-muted"><?= date('h:i A', strtotime($ticket['created_at'])) ?></small>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-flex justify-content-end gap-2">
                                                <button class="btn btn-sm btn-action btn-primary-subtle text-primary" 
                                                        data-bs-toggle="modal" data-bs-target="#viewTicketModal" 
                                                        data-ticket-id="<?= $ticket['id'] ?>"
                                                        title="View Ticket">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <button class="btn btn-sm btn-action btn-info-subtle text-info" 
                                                        data-bs-toggle="modal" data-bs-target="#updateStatusModal" 
                                                        data-ticket-id="<?= $ticket['id'] ?>"
                                                        data-current-status="<?= $ticket['status'] ?>"
                                                        title="Update Status">
                                                    <i class="bi bi-pencil-square"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="d-flex justify-content-between align-items-center p-3 border-top">
                        <div class="text-muted">
                            Showing <span class="fw-semibold">1</span> to <span class="fw-semibold"><?= count($tickets) ?></span> of <span class="fw-semibold"><?= count($tickets) ?></span> entries
                        </div>
                        <nav aria-label="Page navigation">
                            <ul class="pagination mb-0">
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
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- View Ticket Modal -->
    <div class="modal fade" id="viewTicketModal" tabindex="-1" aria-labelledby="viewTicketModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewTicketModalLabel">Ticket Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="ticketDetailsContent">
                    <!-- Content will be loaded via AJAX -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Update Status Modal -->
    <div class="modal fade" id="updateStatusModal" tabindex="-1" aria-labelledby="updateStatusModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="complaints.php">
                    <div class="modal-header">
                        <h5 class="modal-title" id="updateStatusModalLabel">Update Ticket Status</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="ticket_id" id="modalTicketId">
                        
                        <div class="mb-3">
                            <label for="new_status" class="form-label fw-semibold">Status</label>
                            <select class="form-select" name="new_status" id="new_status" required>
                                <option value="open">Open</option>
                                <option value="pending">Pending</option>
                                <option value="resolved">Resolved</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="admin_notes" class="form-label fw-semibold">Admin Notes</label>
                            <textarea class="form-control" name="admin_notes" id="admin_notes" rows="3" placeholder="Add any notes or follow-up actions..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_status" class="btn btn-primary">Update Status</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Initialize date range picker
        flatpickr("#dateRange", {
            mode: "range",
            dateFormat: "Y-m-d",
            allowInput: true
        });

        // Initialize modals
        const viewTicketModal = new bootstrap.Modal(document.getElementById('viewTicketModal'));
        const updateStatusModal = new bootstrap.Modal(document.getElementById('updateStatusModal'));
        
        // View Ticket Modal handler
        document.querySelectorAll('[data-bs-target="#viewTicketModal"]').forEach(button => {
            button.addEventListener('click', function() {
                const ticketId = this.getAttribute('data-ticket-id');
                fetch(`get_ticket_details.php?id=${ticketId}`)
                    .then(response => response.text())
                    .then(data => {
                        document.getElementById('ticketDetailsContent').innerHTML = data;
                    })
                    .catch(error => {
                        document.getElementById('ticketDetailsContent').innerHTML = 
                            `<div class="alert alert-danger">Error loading ticket details: ${error}</div>`;
                    });
            });
        });
        
        // Update Status Modal handler
        document.querySelectorAll('[data-bs-target="#updateStatusModal"]').forEach(button => {
            button.addEventListener('click', function() {
                const ticketId = this.getAttribute('data-ticket-id');
                const currentStatus = this.getAttribute('data-current-status');
                
                document.getElementById('modalTicketId').value = ticketId;
                document.getElementById('new_status').value = currentStatus;
            });
        });
        
        // Search functionality
        document.getElementById('searchInput').addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const rows = document.querySelectorAll('#ticketsTable tbody tr');
            
            rows.forEach(row => {
                const subject = row.querySelector('td:nth-child(2)').textContent.toLowerCase();
                const customer = row.querySelector('td:nth-child(3)').textContent.toLowerCase();
                const id = row.querySelector('td:first-child').textContent.toLowerCase();
                
                if (subject.includes(searchTerm) || customer.includes(searchTerm) || id.includes(searchTerm)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
        
        // Filter functionality
        document.getElementById('filterForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const statusFilter = this.elements['status'].value;
            const priorityFilter = this.elements['priority'].value;
            const dateRange = this.elements['date_range'].value;
            const rows = document.querySelectorAll('#ticketsTable tbody tr');
            
            rows.forEach(row => {
                const rowStatus = row.getAttribute('data-status');
                const rowPriority = row.getAttribute('data-priority');
                const rowDate = row.querySelector('td:nth-child(6) small:first-child').textContent;
                
                const statusMatch = statusFilter === 'all' || rowStatus === statusFilter;
                const priorityMatch = priorityFilter === 'all' || rowPriority === priorityFilter;
                let dateMatch = true;
                
                if (dateRange) {
                    const dates = dateRange.split(' to ');
                    const rowDateObj = new Date(rowDate);
                    const startDate = new Date(dates[0]);
                    const endDate = dates[1] ? new Date(dates[1]) : startDate;
                    
                    dateMatch = rowDateObj >= startDate && rowDateObj <= endDate;
                }
                
                if (statusMatch && priorityMatch && dateMatch) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    </script>
</body>
</html>