<?php
session_start();
require_once '../includes/config.php';

if (!isset($_SESSION['username'])) {
    header("Location: ../login.php");
    exit();
}

$username = $_SESSION['username'];

// Get user_id from username (assuming you have a users table)
$stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$stmt->bind_result($user_id);
$stmt->fetch();
$stmt->close();

$sql = "SELECT 
    b.booking_id, 
    r.origin_city, 
    r.destination_city, 
    DATE(s.departure_time) AS travel_date, 
    TIME(s.departure_time) AS travel_time, 
    b.booking_status 
FROM bookings b
JOIN schedules s ON b.schedule_id = s.schedule_id
JOIN routes r ON s.route_id = r.route_id
WHERE b.passenger_id = ?
ORDER BY s.departure_time DESC
LIMIT 5";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$recent_bookings = [];
while ($row = $result->fetch_assoc()) {
    $recent_bookings[] = $row;
}
$stmt->close();

?>

<?php include '../includes/header.php'; ?>

<style>
    :root {
        --primary-gradient: linear-gradient(135deg,rgb(8, 52, 252) 0%,rgb(75, 79, 162) 100%);
        --secondary-gradient: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
        --accent-color:rgb(34, 25, 204);
        --hover-accent:rgb(21, 14, 224);
    }
    
    .dashboard-card {
        transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        border: none;
        border-radius: 12px;
        overflow: hidden;
        position: relative;
        background: white;
    }
    
    .dashboard-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 14px 28px rgba(0,0,0,0.1), 0 10px 10px rgba(0,0,0,0.08);
    }
    
    .dashboard-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: var(--primary-gradient);
    }
    
    .card-icon {
        width: 60px;
        height: 60px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 15px;
        background: var(--secondary-gradient);
        color: var(--accent-color);
        font-size: 24px;
    }
    
    .search-card {
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 10px 30px rgba(0,0,0,0.08);
        border: none;
    }
    
    .gradient-header {
        background: var(--primary-gradient);
        padding: 1.25rem;
    }
    
    .search-btn {
        background: var(--accent-color);
        border: none;
        height: 100%;
        width: 100%;
        transition: all 0.3s;
    }
    
    .search-btn:hover {
        background: var(--hover-accent);
    }
    
    .welcome-section {
        background: white;
        border-radius: 16px;
        padding: 2rem;
        margin-bottom: 2rem;
        box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        position: relative;
        overflow: hidden;
    }
    
    .welcome-section::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 8px;
        background: var(--primary-gradient);
    }
    
    .date-input::-webkit-calendar-picker-indicator {
        filter: invert(0.5);
    }
    
    @media (max-width: 768px) {
        .search-btn {
            height: auto;
            padding: 0.5rem;
        }
    }
</style>

<div class="container py-5"><br>
    <!-- Welcome Section -->
    <br><div class="welcome-section">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h2 class="fw-bold mb-3">Welcome back, <?= htmlspecialchars($username) ?>! 👋</h2>
                <p class="lead text-muted mb-0">Ready for your next adventure? Find and book your perfect bus trip.</p>
            </div>
            <div class="col-md-4 text-md-end mt-3 mt-md-0">
                <div class="badge bg-light text-primary p-3 fs-6">
                    <i class="bi bi-calendar-check me-2"></i>
                    <?= date('l, F j, Y') ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Search Section -->
    <div class="card search-card mb-5">
        <div class="card-header text-white gradient-header">
            <div class="d-flex align-items-center">
                <i class="bi bi-search me-2 fs-4"></i>
                <h5 class="mb-0">Find Your Perfect Bus</h5>
            </div>
        </div>
        <div class="card-body p-4">
            <form action="search-results.php" method="get" class="row g-3 align-items-end">
                <div class="col-lg-4">
                    <label for="from" class="form-label fw-semibold">Departure</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light"><i class="bi bi-geo-alt text-primary"></i></span>
                        <input type="text" class="form-control rounded-end" id="from" name="from" placeholder="e.g., Colombo" required>
                    </div>
                </div>
                <div class="col-lg-4">
                    <label for="to" class="form-label fw-semibold">Destination</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light"><i class="bi bi-geo-alt-fill text-primary"></i></span>
                        <input type="text" class="form-control rounded-end" id="to" name="to" placeholder="e.g., Kandy" required>
                    </div>
                </div>
                <div class="col-lg-3">
                    <label for="date" class="form-label fw-semibold">Travel Date</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light"><i class="bi bi-calendar3 text-primary"></i></span>
                        <input type="date" class="form-control rounded-end date-input" id="date" name="date" required min="<?= date('Y-m-d') ?>">
                    </div>
                </div>
                <div class="col-lg-1 d-grid">
                    <button type="submit" class="btn search-btn rounded py-2">
                        <i class="bi bi-search fs-5 text-white"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Quick Actions -->
    <h4 class="fw-bold mb-4">Quick Actions</h4>
    <div class="row g-4">
        <div class="col-md-6 col-lg-3">
            <a href="booking-history.php" class="text-decoration-none">
                <div class="dashboard-card p-4 h-100">
                    <div class="card-icon">
                        <i class="bi bi-clock-history"></i>
                    </div>
                    <h5 class="text-center fw-semibold mb-2">Booking History</h5>
                    <p class="text-muted text-center small">View all your past and upcoming trips</p>
                </div>
            </a>
        </div>
        <div class="col-md-6 col-lg-3">
            <a href="wallet.php" class="text-decoration-none">
                <div class="dashboard-card p-4 h-100">
                    <div class="card-icon">
                        <i class="bi bi-wallet2"></i>
                    </div>
                    <h5 class="text-center fw-semibold mb-2">Wallet</h5>
                    <p class="text-muted text-center small">Manage your payments and top-up</p>
                </div>
            </a>
        </div>
        <div class="col-md-6 col-lg-3">
            <a href="profile.php" class="text-decoration-none">
                <div class="dashboard-card p-4 h-100">
                    <div class="card-icon">
                        <i class="bi bi-person-circle"></i>
                    </div>
                    <h5 class="text-center fw-semibold mb-2">Profile</h5>
                    <p class="text-muted text-center small">Update your personal information</p>
                </div>
            </a>
        </div>
        <div class="col-md-6 col-lg-3">
            <a href="reviews.php" class="text-decoration-none">
                <div class="dashboard-card p-4 h-100">
                    <div class="card-icon">
                        <i class="bi bi-star-fill"></i>
                    </div>
                    <h5 class="text-center fw-semibold mb-2">Reviews</h5>
                    <p class="text-muted text-center small">Share your travel experiences</p>
                </div>
            </a>
        </div>
    </div>

    <!-- Recent Bookings (Example Section) -->
    <!-- Recent Bookings (Dynamic) -->
    <div class="mt-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="fw-bold mb-0">Recent Bookings</h4>
            <a href="booking-history.php" class="btn btn-sm btn-outline-primary">View All</a>
        </div>
        <div class="card shadow-sm border-0">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Booking ID</th>
                                <th>Route</th>
                                <th>Date</th>
                                <th>Time</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (count($recent_bookings) > 0): ?>
                            <?php foreach ($recent_bookings as $booking): ?>
                                <tr>
                                    <td>#<?= htmlspecialchars($booking['booking_id']) ?></td>
                                    <td><?= htmlspecialchars($booking['origin_city']) ?> → <?= htmlspecialchars($booking['destination_city']) ?></td>
                                    <td><?= date('M d, Y', strtotime($booking['booking_date'])) ?></td>
                                    <td><?= date('h:i A', strtotime($booking['booking_time'])) ?></td>
                                    <td>
                                        <?php
                                            $status = strtolower($booking['status']);
                                            $badgeClass = 'bg-secondary';
                                            if ($status === 'confirmed') $badgeClass = 'bg-success';
                                            else if ($status === 'pending') $badgeClass = 'bg-warning text-dark';
                                            else if ($status === 'cancelled') $badgeClass = 'bg-danger';

                                            echo "<span class='badge $badgeClass'>" . ucfirst($status) . "</span>";
                                        ?>
                                    </td>
                                    <td><a href="booking-details.php?id=<?= urlencode($booking['booking_id']) ?>" class="btn btn-sm btn-outline-primary">Details</a></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="text-center text-muted">No recent bookings found.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-complete for locations
    const fromInput = document.getElementById('from');
    const toInput = document.getElementById('to');
    const popularLocations = ['Colombo', 'Kandy', 'Galle', 'Jaffna', 'Negombo', 'Anuradhapura'];
    
    // Initialize autocomplete (you would replace this with a real API call)
    [fromInput, toInput].forEach(input => {
        input.addEventListener('focus', function() {
            // In a real app, you would fetch locations from an API
            console.log('Fetching locations...');
        });
    });
    
    // Set today's date as default
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('date').value = today;
    
    // Add animation to cards when they come into view
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('animate__animated', 'animate__fadeInUp');
            }
        });
    }, { threshold: 0.1 });
    
    document.querySelectorAll('.dashboard-card').forEach(card => {
        observer.observe(card);
    });
});
</script>

<!-- Animate.css for animations -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"/>

<?php include '../includes/footer.php'; ?>