<?php
require_once '../includes/config.php';
require_once '../includes/payment_gateway.php';
session_start();

// Security headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit();
}

// Generate CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$user_id = (int)$_SESSION['user_id'];

// Validate required POST data
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['schedule_id'], $_POST['selected_seats'], $_POST['full_name'], $_POST['phone'], $_POST['date'])) {
    header('Location: /passenger/seat-selection.php?schedule_id='.$schedule_id.'&error=invalid_access');
    exit();
}

// Sanitize and validate inputs
$schedule_id = filter_var($_POST['schedule_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$selectedSeats = preg_replace('/[^0-9A-Z,]/', '', $_POST['selected_seats']);
$full_name = filter_var(trim($_POST['full_name']), FILTER_SANITIZE_FULL_SPECIAL_CHARS);
$phone = filter_var(trim($_POST['phone']), FILTER_SANITIZE_FULL_SPECIAL_CHARS);
$email = isset($_POST['email']) ? filter_var(trim($_POST['email']), FILTER_VALIDATE_EMAIL) : '';
$date = filter_var($_POST['date'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
$from = isset($_POST['from']) ? filter_var($_POST['from'], FILTER_SANITIZE_FULL_SPECIAL_CHARS) : '';
$to = isset($_POST['to']) ? filter_var($_POST['to'], FILTER_SANITIZE_FULL_SPECIAL_CHARS) : '';

if (!$schedule_id || empty($selectedSeats)) {
    header('Location: /passenger/search-results.php?error=invalid_input');
    exit();
}

// Fetch schedule and bus info
$stmt = $conn->prepare("
    SELECT s.departure_time, s.price, s.available_seats, 
           b.name AS bus_name, b.registration_number,
           r.origin_city, r.destination_city
    FROM schedules s
    JOIN buses b ON s.bus_id = b.bus_id
    JOIN routes r ON s.route_id = r.route_id
    WHERE s.schedule_id = ? AND s.is_active = 1
");
$stmt->bind_param("i", $schedule_id);
$stmt->execute();
$schedule = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$schedule) {
    header('Location: /passenger/search-results.php?error=invalid_schedule');
    exit();
}

// Calculate total amount
$seatCount = count(explode(',', $selectedSeats));
$pricePerSeat = (float)$schedule['price'];
$totalAmount = $seatCount * $pricePerSeat;

// Check available seats
if ($seatCount > (int)$schedule['available_seats']) {
    header('Location: /passenger/seat-selection.php?schedule_id='.$schedule_id.'&error=not_enough_seats');
    exit();
}

// Initialize wallet
$walletBalance = 0;
$canUseWallet = false;

// Get user's wallet balance with transaction lock
$conn->begin_transaction();
try {
    $wallet_stmt = $conn->prepare("SELECT balance FROM user_wallets WHERE user_id = ? FOR UPDATE");
    $wallet_stmt->bind_param("i", $user_id);
    $wallet_stmt->execute();
    $wallet_result = $wallet_stmt->get_result();

    if ($wallet_result->num_rows > 0) {
        $walletBalance = (float)$wallet_result->fetch_assoc()['balance'];
        $canUseWallet = ($walletBalance >= $totalAmount);
    }
    $wallet_stmt->close();
    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    error_log("Wallet balance check failed: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Payment - SmartBusLink</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
    <style>
        .payment-method-card {
            border: 1px solid #dee2e6;
            border-radius: 10px;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .payment-method-card:hover {
            border-color: #0d6efd;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        }
        .payment-method-card.active {
            border-color: #0d6efd;
            background-color: #f8f9fa;
        }
        .payment-logo {
            height: 30px;
            object-fit: contain;
            margin-right: 10px;
        }
        .card-type-logo {
            height: 25px;
            margin-left: 5px;
        }
        .loading-spinner {
            display: none;
        }
        .processing .loading-spinner {
            display: inline-block;
        }
        .processing .btn-text {
            display: none;
        }
    </style>
</head>
<body class="bg-light">

<div class="container my-5">
    <div class="card shadow-lg">
        <div class="card-header bg-primary text-white">
            <div class="d-flex justify-content-between align-items-center">
                <h4 class="mb-0">Payment</h4>
                
            </div>
        </div>
        <div class="card-body">
            <div class="alert alert-info mb-4">
                <i class="fas fa-info-circle me-2"></i> You're booking <?= $seatCount ?> seat(s) for <?= htmlspecialchars($schedule['bus_name']) ?> (<?= htmlspecialchars($schedule['registration_number']) ?>)
            </div>

            <form action="process-payment.php" method="POST" id="paymentForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="schedule_id" value="<?= htmlspecialchars($schedule_id) ?>">
                <input type="hidden" name="selected_seats" value="<?= htmlspecialchars($selectedSeats) ?>">
                <input type="hidden" name="total_amount" value="<?= htmlspecialchars($totalAmount) ?>">
                <input type="hidden" name="date" value="<?= htmlspecialchars($date) ?>">

                <h5 class="mb-3">Select Payment Method</h5>

                <div class="row mb-4">
                    <!-- Credit Card Option -->
                    <div class="col-md-6 mb-3">
                        <div class="payment-method-card p-3 h-100" onclick="selectPaymentMethod('credit_card')">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="payment_method" id="credit_card" value="credit_card" checked required>
                                <label class="form-check-label d-flex align-items-center" for="credit_card">
                                    <img src="https://cdn-icons-png.flaticon.com/512/196/196578.png" alt="Credit Card" class="payment-logo">
                                    <span>Credit/Debit Card</span>
                                </label>
                            </div>
                            <div class="mt-2">
                                <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/5/5e/Visa_Inc._logo.svg/2560px-Visa_Inc._logo.svg.png" alt="Visa" class="card-type-logo">
                                <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/2/2a/Mastercard-logo.svg/1280px-Mastercard-logo.svg.png" alt="Mastercard" class="card-type-logo">
                                <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/f/fa/American_Express_logo_%282018%29.svg/1200px-American_Express_logo_%282018%29.svg.png" alt="Amex" class="card-type-logo">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Wallet Option -->
                    <div class="col-md-6 mb-3">
                        <div class="payment-method-card p-3 h-100" onclick="<?= $canUseWallet ? "selectPaymentMethod('wallet')" : "" ?>" <?= !$canUseWallet ? 'style="opacity:0.6"' : '' ?>>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="payment_method" id="wallet" value="wallet" <?= $canUseWallet ? '' : 'disabled' ?>>
                                <label class="form-check-label d-flex align-items-center" for="wallet">
                                    
                                    <span>Wallet Payment</span>
                                    <span class="badge bg-<?= $canUseWallet ? 'success' : 'danger' ?> ms-2">
                                        Rs.<?= number_format($walletBalance, 2) ?>
                                    </span>
                                </label>
                            </div>
                            <?php if (!$canUseWallet): ?>
                                <small class="text-danger">Comming Soon</small>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Genie Pay Option -->
                    <div class="col-md-6 mb-3">
                        <div class="payment-method-card p-3 h-100" onclick="selectPaymentMethod('genie_pay')">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="payment_method" id="genie_pay" value="genie_pay">
                                <label class="form-check-label d-flex align-items-center" for="genie_pay">
                                    
                                    <span>Genie Pay</span>
                                </label>
                            </div>
                            <small class="text-muted">Pay via Genie Pay account</small>
                        </div>
                    </div>
                    
                    <!-- Bank Transfer Option -->
                    <div class="col-md-6 mb-3">
                        <div class="payment-method-card p-3 h-100" onclick="selectPaymentMethod('bank_transfer')">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="payment_method" id="bank_transfer" value="bank_transfer">
                                <label class="form-check-label d-flex align-items-center" for="bank_transfer">
                                    <img src="https://cdn-icons-png.flaticon.com/512/2555/2555288.png" alt="Bank Transfer" class="payment-logo">
                                    <span>Bank Transfer</span>
                                </label>
                            </div>
                            <div class="mt-2">
                                
                        </div>
                    </div>
                </div>

                <!-- Credit Card Fields -->
                <div id="creditCardFields">
                    <div class="alert alert-info mb-3">
                        <i class="fas fa-lock me-2"></i> Secure payment processed by Genie Business Gateway
                        <img src="https://www.geniebiz.lk/wp-content/uploads/2021/08/Genie-Pay-Logo.png" alt="Genie Pay" style="height:20px; margin-left:10px;">
                    </div>
                    
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label for="card_number" class="form-label">Card Number</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="card_number" name="card_number" 
                                       pattern="\d{13,19}" placeholder="1234 5678 9012 3456" required />
                                <span class="input-group-text">
                                    <i class="fab fa-cc-visa"></i>
                                    <i class="fab fa-cc-mastercard ms-2"></i>
                                    <i class="fab fa-cc-amex ms-2"></i>
                                </span>
                            </div>
                            <div class="invalid-feedback">Please enter a valid card number</div>
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <label for="card_type" class="form-label">Card Type</label>
                            <select class="form-select" id="card_type" name="card_type" required>
                                <option value="" disabled selected>Select card type</option>
                                <option value="visa">Visa</option>
                                <option value="mastercard">Mastercard</option>
                                <option value="amex">American Express</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="expiry_date" class="form-label">Expiry Date</label>
                            <input type="month" class="form-control" id="expiry_date" name="expiry_date" required />
                            <div class="invalid-feedback">Please enter expiry date</div>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <label for="cvv" class="form-label">CVV</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="cvv" name="cvv" pattern="\d{3,4}" placeholder="123" required />
                                <span class="input-group-text" data-bs-toggle="tooltip" title="3-digit code on back of card">
                                    <i class="fas fa-question-circle"></i>
                                </span>
                            </div>
                            <div class="invalid-feedback">Please enter CVV</div>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <label for="postal_code" class="form-label">Postal Code</label>
                            <input type="text" class="form-control" id="postal_code" name="postal_code" />
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="card_holder" class="form-label">Card Holder Name</label>
                        <input type="text" class="form-control" id="card_holder" name="card_holder" placeholder="As shown on card" required />
                    </div>
                </div>

                <!-- Genie Pay Fields (hidden by default) -->
                <div id="geniePayFields" style="display:none;">
                    <div class="alert alert-info mb-3">
                        <img src="https://www.geniebiz.lk/wp-content/uploads/2021/08/Genie-Pay-Logo.png" alt="Genie Pay" style="height:30px; margin-right:10px;">
                        You will be redirected to Genie Pay secure login page to complete your payment
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Genie Pay Username/Email</label>
                        <input type="email" class="form-control" name="genie_email" placeholder="Your Genie Pay account email">
                    </div>
                </div>

                <!-- Bank Transfer Fields (hidden by default) -->
                <div id="bankTransferFields" style="display:none;">
                    <div class="alert alert-warning">
                        <i class="fas fa-info-circle me-2"></i> Please complete the bank transfer and upload the receipt below
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Bank Details</label>
                        <div class="card bg-light p-3">
                            <p class="mb-1"><strong>Bank Name:</strong> Commercial Bank of Ceylon</p>
                            <p class="mb-1"><strong>Account Name:</strong> SmartBusLink (Pvt) Ltd</p>
                            <p class="mb-1"><strong>Account Number:</strong> 1234567890</p>
                            <p class="mb-1"><strong>Branch:</strong> Colombo Main</p>
                            <p class="mb-0"><strong>Reference:</strong> BUS-<?= strtoupper(uniqid()) ?></p>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="bank_receipt" class="form-label">Upload Payment Receipt</label>
                        <input type="file" class="form-control" id="bank_receipt" name="bank_receipt" accept="image/*,.pdf">
                    </div>
                </div>

                <!-- Terms and conditions -->
                <div class="border-top pt-3 mt-4">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="terms" name="terms" required>
                        <label class="form-check-label" for="terms">
                            I agree to the <a href="/terms.php" target="_blank">Terms and Conditions</a> and <a href="/privacy.php" target="_blank">Privacy Policy</a>
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="save_card" name="save_card">
                        <label class="form-check-label" for="save_card">
                            Save card details for faster payments (securely encrypted)
                        </label>
                    </div>
                </div>

                <div class="d-flex justify-content-between mt-4 pt-2">
              <a href="seat-selection.php?schedule_id=<?= $schedule_id ?>&date=<?= urlencode($date) ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Seat Selection
              </a>
    </a>
    <button type="submit" class="btn btn-primary btn-lg px-4" id="submitBtn">
        <span class="loading-spinner spinner-border spinner-border-sm me-2 d-none" role="status" aria-hidden="true"></span>
        <span class="btn-text">
            <i class="fas fa-lock me-2"></i>Pay Rs.<?= number_format($totalAmount, 2) ?>
        </span>
    </button>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(() => {
    'use strict';

    // Initialize tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Luhn algorithm for card validation
    function luhnCheck(cardNumber) {
        cardNumber = cardNumber.replace(/\s/g, '');
        let sum = 0;
        let alternate = false;
        
        for (let i = cardNumber.length - 1; i >= 0; i--) {
            let digit = parseInt(cardNumber.charAt(i), 10);
            
            if (alternate) {
                digit *= 2;
                if (digit > 9) {
                    digit = (digit % 10) + 1;
                }
            }
            
            sum += digit;
            alternate = !alternate;
        }
        
        return (sum % 10 === 0);
    }

    // Select payment method card
    function selectPaymentMethod(method) {
        document.querySelector(`input[value="${method}"]`).checked = true;
        togglePaymentFields();
        
        // Update card active states
        document.querySelectorAll('.payment-method-card').forEach(card => {
            card.classList.remove('active');
        });
        event.currentTarget.classList.add('active');
    }

    // Toggle payment fields based on selected method
    function togglePaymentFields() {
        const selectedMethod = document.querySelector('input[name="payment_method"]:checked').value;
        
        // Hide all fields first
        document.getElementById('creditCardFields').style.display = 'none';
        document.getElementById('geniePayFields').style.display = 'none';
        document.getElementById('bankTransferFields').style.display = 'none';
        
        // Show relevant fields
        if (selectedMethod === 'credit_card') {
            document.getElementById('creditCardFields').style.display = 'block';
            setCreditCardRequired(true);
        } 
        else if (selectedMethod === 'genie_pay') {
            document.getElementById('geniePayFields').style.display = 'block';
            setCreditCardRequired(false);
        }
        else if (selectedMethod === 'bank_transfer') {
            document.getElementById('bankTransferFields').style.display = 'block';
            setCreditCardRequired(false);
        }
        else {
            setCreditCardRequired(false);
        }
    }
    
    // Set credit card fields as required/not required
    function setCreditCardRequired(required) {
        ['card_number', 'expiry_date', 'cvv', 'card_type', 'card_holder'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.required = required;
        });
    }
    
    // Format card number as user types
    document.getElementById('card_number')?.addEventListener('input', function(e) {
        this.value = this.value.replace(/\s/g, '').replace(/(\d{4})/g, '$1 ').trim();
    });
    
    // Detect card type from number
    document.getElementById('card_number')?.addEventListener('change', function() {
        const cardNumber = this.value.replace(/\s/g, '');
        const cardTypeSelect = document.getElementById('card_type');
        
        if (/^4/.test(cardNumber)) {
            cardTypeSelect.value = 'visa';
        } 
        else if (/^5[1-5]/.test(cardNumber)) {
            cardTypeSelect.value = 'mastercard';
        } 
        else if (/^3[47]/.test(cardNumber)) {
            cardTypeSelect.value = 'amex';
        }
        
        // Validate with Luhn algorithm
        if (!luhnCheck(cardNumber)) {
            this.setCustomValidity('Invalid card number');
        } else {
            this.setCustomValidity('');
        }
    });
    
    // Validate expiry date
    document.getElementById('expiry_date')?.addEventListener('change', function() {
        const [year, month] = this.value.split('-');
        const expiryDate = new Date(year, month - 1);
        const currentDate = new Date();
        
        if (expiryDate < currentDate) {
            this.setCustomValidity('Card has expired');
        } else {
            this.setCustomValidity('');
        }
    });
    
    // Initialize payment method selection
    document.querySelectorAll('input[name="payment_method"]').forEach(method => {
        method.addEventListener('change', togglePaymentFields);
    });
    
    // Initialize on page load
    togglePaymentFields();
    document.querySelector('.payment-method-card').classList.add('active');
    
    // Form validation
    document.getElementById('paymentForm').addEventListener('submit', function(event) {
        if (!this.checkValidity()) {
            event.preventDefault();
            event.stopPropagation();
        } else {
            // Show loading state
            document.getElementById('submitBtn').classList.add('processing');
        }
        
        this.classList.add('was-validated');
    });
})();
</script>

</body>
</html>