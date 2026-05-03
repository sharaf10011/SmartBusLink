<?php
session_start();
require_once '../includes/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

// Create MySQLi connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Initialize wallet with default values
$wallet = [
    'balance' => 0.00,
    'updated_at' => date('Y-m-d H:i:s') // Current time as default
];

$user_id = $_SESSION['user_id'];
$transactions = [];
$error = '';
$success = '';

// Get wallet information
$query = "SELECT * FROM wallet WHERE user_id = ?";
$stmt = $conn->prepare($query);
if (!$stmt) {
    $error = "Database error: " . $conn->error;
} else {
    $stmt->bind_param("i", $user_id);
    if (!$stmt->execute()) {
        $error = "Error executing query: " . $stmt->error;
    } else {
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $wallet = $result->fetch_assoc();
        } else {
            // Create wallet if it doesn't exist
            $stmt = $conn->prepare("INSERT INTO wallet (user_id, balance) VALUES (?, 0.00)");
            if ($stmt) {
                $stmt->bind_param("i", $user_id);
                if ($stmt->execute()) {
                    $wallet_id = $conn->insert_id;
                    $stmt->close();
                    
                    // Get the newly created wallet
                    $stmt = $conn->prepare("SELECT * FROM wallet WHERE wallet_id = ?");
                    $stmt->bind_param("i", $wallet_id);
                    if ($stmt->execute()) {
                        $result = $stmt->get_result();
                        $wallet = $result->fetch_assoc();
                    } else {
                        $error = "Error fetching new wallet: " . $stmt->error;
                    }
                } else {
                    $error = "Error creating wallet: " . $stmt->error;
                }
            } else {
                $error = "Error preparing statement: " . $conn->error;
            }
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartBusLink - My Wallet</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --success-color: #27ae60;
            --danger-color: #e74c3c;
            --light-bg: #f8f9fa;
        }
        
        body {
            background-color: var(--light-bg);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .wallet-card {
            border-radius: 15px;
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
            border: none;
        }
        
        .wallet-card:hover {
            transform: translateY(-5px);
        }
        
        .wallet-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border-radius: 15px 15px 0 0 !important;
        }
        
        .balance-display {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        
        .transaction-positive {
            background-color: rgba(39, 174, 96, 0.1) !important;
        }
        
        .transaction-negative {
            background-color: rgba(231, 76, 60, 0.1) !important;
        }
        
        .quick-action-btn {
            border-radius: 10px;
            transition: all 0.3s ease;
            padding: 12px 0;
        }
        
        .quick-action-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .last-updated {
            font-size: 0.85rem;
            color: #6c757d;
        }
        
        .recharge-btn {
            background: linear-gradient(135deg, var(--success-color), #2ecc71);
            border: none;
        }
        
        .recharge-btn:hover {
            background: linear-gradient(135deg, #2ecc71, var(--success-color));
        }
        
        @media (max-width: 768px) {
            .balance-display {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="fw-bold text-primary"><i class="bi bi-wallet2"></i> My Wallet</h2>
            <a href="booking-history.php" class="btn btn-outline-primary">
                <i class="bi bi-clock-history"></i> Booking History
            </a>
        </div>
        
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($success)): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($success) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <div class="row g-4">
            <!-- Wallet Balance Card -->
            <div class="col-lg-6">
                <div class="card wallet-card h-100">
                    <div class="card-header wallet-header text-white">
                        <h5 class="mb-0"><i class="bi bi-credit-card"></i> Wallet Balance</h5>
                    </div>
                    <div class="card-body text-center py-4">
                        <div class="balance-display mb-3">
    LKR <?= isset($wallet['balance']) ? number_format($wallet['balance'], 2) : '0.00' ?>
</div>
<p class="last-updated">
    <i class="bi bi-clock"></i> Last updated: <?= isset($wallet['updated_at']) ? date('d M Y H:i', strtotime($wallet['updated_at'])) : date('d M Y H:i') ?>
</p>
                        
                        <form method="post" class="mt-4">
                            <div class="input-group mb-3">
                                <span class="input-group-text bg-light">LKR</span>
                                <input type="number" class="form-control" name="recharge_amount" 
                                       placeholder="Enter amount" min="100" step="100" required>
                                <button class="btn recharge-btn text-white" type="submit">
                                    <i class="bi bi-plus-circle"></i> Recharge
                                </button>
                            </div>
                            <small class="text-muted">Minimum recharge amount: LKR 100.00</small>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Quick Actions Card -->
            <div class="col-lg-6">
                <div class="card wallet-card h-100">
                    <div class="card-header wallet-header text-white">
                        <h5 class="mb-0"><i class="bi bi-lightning-charge"></i> Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-3">
                            
                            <button class="btn btn-outline-info quick-action-btn" data-bs-toggle="modal" data-bs-target="#transferModal">
                                <i class="bi bi-bank"></i> Transfer to Bank
                            </button>
                            <a href="#" class="btn btn-outline-secondary quick-action-btn">
                                <i class="bi bi-share"></i> Send to Friend
                            </a>
                        </div>
                        
                        <div class="mt-4 pt-3 border-top">
                            <h6><i class="bi bi-graph-up"></i> Wallet Statistics</h6>
                            <div class="row text-center mt-3">
                                <div class="col-4">
                                    <div class="text-primary fw-bold">LKR <?= number_format($wallet['balance'], 2) ?></div>
                                    <small class="text-muted">Current</small>
                                </div>
                                <div class="col-4">
                                    <div class="text-success fw-bold">LKR 0.00</div>
                                    <small class="text-muted">This Month</small>
                                </div>
                                <div class="col-4">
                                    <div class="text-danger fw-bold">LKR 0.00</div>
                                    <small class="text-muted">This Year</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Transaction History -->
        <div class="card wallet-card mt-4">
            <div class="card-header wallet-header text-white">
                <h5 class="mb-0"><i class="bi bi-list-check"></i> Recent Transactions</h5>
            </div>
            <div class="card-body">
                <?php if (empty($transactions)): ?>
                    <div class="text-center py-4">
                        <i class="bi bi-wallet2 text-muted" style="font-size: 3rem;"></i>
                        <p class="text-muted mt-3">No transactions found</p>
                        <a href="#" class="btn btn-primary mt-2">
                            <i class="bi bi-plus-circle"></i> Make Your First Transaction
                        </a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th><i class="bi bi-calendar"></i> Date</th>
                                    <th><i class="bi bi-tag"></i> Type</th>
                                    <th><i class="bi bi-cash-stack"></i> Amount</th>
                                    <th><i class="bi bi-card-text"></i> Description</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transactions as $txn): ?>
                                    <tr class="<?= $txn['type'] === 'Recharge' ? 'transaction-positive' : 'transaction-negative' ?>">
                                        <td>
                                            <div class="fw-bold"><?= date('d M Y', strtotime($txn['transaction_date'])) ?></div>
                                            <small class="text-muted"><?= date('H:i', strtotime($txn['transaction_date'])) ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= $txn['type'] === 'Recharge' ? 'success' : 'danger' ?>">
                                                <?= htmlspecialchars($txn['type']) ?>
                                            </span>
                                        </td>
                                        <td class="fw-bold <?= $txn['type'] === 'Recharge' ? 'text-success' : 'text-danger' ?>">
                                            <?= ($txn['type'] === 'Recharge' ? '+' : '-') ?>
                                            LKR <?= number_format($txn['amount'], 2) ?>
                                        </td>
                                        <td><?= htmlspecialchars($txn['description']) ?></td>
                                        <td class="text-end">
                                            <button class="btn btn-sm btn-outline-secondary">
                                                <i class="bi bi-receipt"></i> Receipt
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="text-center mt-3">
                        <a href="#" class="btn btn-outline-primary">
                            <i class="bi bi-clock-history"></i> View All Transactions
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Transfer Modal -->
    <div class="modal fade" id="transferModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header wallet-header text-white">
                    <h5 class="modal-title"><i class="bi bi-bank"></i> Transfer to Bank</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-4">
                        <i class="bi bi-bank text-primary" style="font-size: 3rem;"></i>
                        <h5 class="mt-3">Bank Transfer</h5>
                        <p class="text-muted">Transfer funds to your registered bank account</p>
                    </div>
                    
                    <form>
                        <div class="mb-3">
                            <label class="form-label">Bank Account</label>
                            <select class="form-select">
                                <option selected disabled>Select your bank</option>
                                <option>Bank of Ceylon</option>
                                <option>People's Bank</option>
                                <option>Commercial Bank</option>
                                <option>Hatton National Bank</option>
                                <option>Sampath Bank</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Account Number</label>
                            <input type="text" class="form-control" placeholder="Enter account number">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Amount (LKR)</label>
                            <div class="input-group">
                                <span class="input-group-text">LKR</span>
                                <input type="number" class="form-control" placeholder="Enter amount" min="100">
                            </div>
                            <small class="text-muted">Minimum transfer amount: LKR 100.00</small>
                        </div>
                        
                        <div class="d-grid gap-2 mt-4">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-send-check"></i> Transfer Now
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Add animation to wallet balance
        document.addEventListener('DOMContentLoaded', function() {
            const balanceElement = document.querySelector('.balance-display');
            if (balanceElement) {
                balanceElement.style.opacity = '0';
                balanceElement.style.transform = 'translateY(20px)';
                balanceElement.style.transition = 'all 0.5s ease';
                
                setTimeout(() => {
                    balanceElement.style.opacity = '1';
                    balanceElement.style.transform = 'translateY(0)';
                }, 300);
            }
            
            // Add confirmation for recharge
            const rechargeForm = document.querySelector('form[method="post"]');
            if (rechargeForm) {
                rechargeForm.addEventListener('submit', function(e) {
                    const amount = parseFloat(this.querySelector('input[name="recharge_amount"]').value);
                    if (amount < 100) {
                        e.preventDefault();
                        alert('Minimum recharge amount is LKR 100.00');
                    } else if (!confirm(`Confirm wallet recharge of LKR ${amount.toFixed(2)}?`)) {
                        e.preventDefault();
                    }
                });
            }
        });
    </script>
</body>
</html>