<?php
session_start();
require_once '../includes/config.php';

if (!isset($_SESSION['username'])) {
    header("Location: ../login.php");
    exit();
}

$username = $_SESSION['username'];

// Get user_id from username
$stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$stmt->bind_result($user_id);
$stmt->fetch();
$stmt->close();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
    $booking_id = intval($_POST['booking_id']);
    $rating = intval($_POST['rating']);
    $comment = trim($_POST['comment']);
    
    // Get bus_id from the booking
    $stmt = $conn->prepare("SELECT bus_id FROM schedules WHERE schedule_id = (SELECT schedule_id FROM bookings WHERE booking_id = ?)");
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $stmt->bind_result($bus_id);
    $stmt->fetch();
    $stmt->close();
    
    // Validate rating
    if ($rating < 1 || $rating > 5) {
        $_SESSION['error'] = "Please select a valid rating (1-5 stars)";
        header("Location: reviews.php");
        exit();
    }
    
    // Check if user has already reviewed this booking
    $stmt = $conn->prepare("SELECT review_id FROM reviews WHERE booking_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $booking_id, $user_id);
    $stmt->execute();
    $stmt->store_result();
    
    if ($stmt->num_rows > 0) {
        $_SESSION['error'] = "You have already reviewed this trip";
        header("Location: reviews.php");
        exit();
    }
    $stmt->close();
    
    // Insert review
    $stmt = $conn->prepare("INSERT INTO reviews (user_id, bus_id, booking_id, rating, comment) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("iiiis", $user_id, $bus_id, $booking_id, $rating, $comment);
    
    if ($stmt->execute()) {
        $_SESSION['success'] = "Thank you for your review!";
    } else {
        $_SESSION['error'] = "Error submitting review. Please try again.";
    }
    
    $stmt->close();
    header("Location: reviews.php");
    exit();
}

// Handle review deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_review'])) {
    $review_id = intval($_POST['review_id']);
    
    // Verify review belongs to user
    $stmt = $conn->prepare("DELETE FROM reviews WHERE review_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $review_id, $user_id);
    
    if ($stmt->execute()) {
        $_SESSION['success'] = "Review deleted successfully";
    } else {
        $_SESSION['error'] = "Error deleting review";
    }
    
    $stmt->close();
    header("Location: reviews.php");
    exit();
}

// Get user's reviews
$sql = "SELECT 
    r.review_id,
    r.rating,
    r.comment,
    r.review_date,
    r.booking_id,
    bus.name AS bus_name,
    bus.registration_number AS bus_number,
    rt.origin_city,
    rt.destination_city
FROM reviews r
JOIN buses bus ON r.bus_id = bus.bus_id
JOIN bookings b ON r.booking_id = b.booking_id
JOIN schedules s ON b.schedule_id = s.schedule_id
JOIN routes rt ON s.route_id = rt.route_id
WHERE r.user_id = ?
ORDER BY r.review_date DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$reviews = [];
while ($row = $result->fetch_assoc()) {
    $reviews[] = $row;
}
$stmt->close();

// Get bookings eligible for review (completed trips in the past)
$sql = "SELECT 
    b.booking_id,
    s.bus_id,
    rt.origin_city,
    rt.destination_city,
    s.departure_time,
    bus.name AS bus_name,
    bus.registration_number AS bus_number
FROM bookings b
JOIN schedules s ON b.schedule_id = s.schedule_id
JOIN routes rt ON s.route_id = rt.route_id
JOIN buses bus ON s.bus_id = bus.bus_id
WHERE b.passenger_id = ?
AND b.booking_status = 'completed'
AND s.departure_time < NOW()
AND b.booking_id NOT IN (SELECT booking_id FROM reviews WHERE user_id = ?)
ORDER BY s.departure_time DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $user_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

$bookings_for_review = [];
while ($row = $result->fetch_assoc()) {
    $bookings_for_review[] = $row;
}
$stmt->close();
?>

<?php include '../includes/header.php'; ?>

<style>
    .review-card {
        border-radius: 10px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        transition: all 0.3s ease;
        margin-bottom: 20px;
    }
    
    .review-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 20px rgba(0,0,0,0.1);
    }
    
    .rating-stars {
        color: #FFD700;
        font-size: 1.2rem;
    }
    
    .route-badge {
        background-color: #f8f9fa;
        color: #495057;
        border-radius: 20px;
        padding: 5px 10px;
        font-size: 0.8rem;
    }
    
    .empty-state {
        text-align: center;
        padding: 40px 20px;
        background-color: #f8f9fa;
        border-radius: 10px;
    }
    
    .star-rating {
        direction: rtl;
        display: inline-block;
    }
    
    .star-rating input[type=radio] {
        display: none;
    }
    
    .star-rating label {
        color: #ddd;
        font-size: 2rem;
        padding: 0 3px;
        cursor: pointer;
    }
    
    .star-rating input[type=radio]:checked ~ label {
        color: #FFD700;
    }
    
    .star-rating label:hover,
    .star-rating label:hover ~ label {
        color: #FFD700;
    }
</style>
<br>
<div class="container py-5"><br>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold"><i class="bi bi-star-fill me-2"></i>My Reviews</h2>
    </div>

    <!-- Display messages -->
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?= $_SESSION['error'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= $_SESSION['success'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <!-- Add Review Section -->
    <?php if (!empty($bookings_for_review)): ?>
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">Add New Review</h5>
        </div>
        <div class="card-body">
            <form method="post" action="reviews.php">
                <div class="mb-3">
                    <label class="form-label">Select Trip</label>
                    <select class="form-select" name="booking_id" required>
                        <option value="">Choose a trip to review</option>
                        <?php foreach ($bookings_for_review as $booking): ?>
                        <option value="<?= $booking['booking_id'] ?>" data-bus-id="<?= $booking['bus_id'] ?>">
                            <?= htmlspecialchars($booking['bus_name']) ?> (<?= htmlspecialchars($booking['bus_number']) ?>) - 
                            <?= htmlspecialchars($booking['origin_city']) ?> to <?= htmlspecialchars($booking['destination_city']) ?> 
                            (<?= date('M d, Y', strtotime($booking['departure_time'])) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="bus_id" id="bus_id">
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Rating</label>
                    <div class="star-rating">
                        <input type="radio" id="star5" name="rating" value="5" required>
                        <label for="star5" title="5 stars">★</label>
                        <input type="radio" id="star4" name="rating" value="4">
                        <label for="star4" title="4 stars">★</label>
                        <input type="radio" id="star3" name="rating" value="3">
                        <label for="star3" title="3 stars">★</label>
                        <input type="radio" id="star2" name="rating" value="2">
                        <label for="star2" title="2 stars">★</label>
                        <input type="radio" id="star1" name="rating" value="1">
                        <label for="star1" title="1 star">★</label>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Your Review</label>
                    <textarea class="form-control" name="comment" rows="3" 
                              placeholder="Share your experience..." required></textarea>
                </div>
                
                <button type="submit" name="submit_review" class="btn btn-primary">Submit Review</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Existing Reviews -->
    <div class="card">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">My Past Reviews</h5>
        </div>
        <div class="card-body">
            <?php if (!empty($reviews)): ?>
                <?php foreach ($reviews as $review): ?>
                <div class="review-card p-4 mb-3">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <span class="route-badge">
                                <?= htmlspecialchars($review['bus_name']) ?> (<?= htmlspecialchars($review['bus_number']) ?>)
                            </span>
                            <span class="route-badge ms-2">
                                <?= htmlspecialchars($review['origin_city']) ?> → <?= htmlspecialchars($review['destination_city']) ?>
                            </span>
                            <span class="text-muted ms-2">
                                Booking #<?= $review['booking_id'] ?>
                            </span>
                        </div>
                        <small class="text-muted">
                            <?= date('M d, Y', strtotime($review['review_date'])) ?>
                        </small>
                    </div>
                    
                    <div class="rating-stars mb-2">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="bi bi-star<?= $i <= $review['rating'] ? '-fill' : '' ?>"></i>
                        <?php endfor; ?>
                    </div>
                    
                    <p><?= nl2br(htmlspecialchars($review['comment'])) ?></p>
                    
                    <div class="d-flex justify-content-end">
                        <form method="post" action="reviews.php" class="d-inline">
                            <input type="hidden" name="review_id" value="<?= $review['review_id'] ?>">
                            <button type="submit" name="delete_review" class="btn btn-sm btn-outline-danger" 
                                    onclick="return confirm('Are you sure you want to delete this review?')">
                                <i class="bi bi-trash"></i> Delete
                            </button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="bi bi-star text-muted" style="font-size: 3rem;"></i>
                    <h5 class="mt-3">No Reviews Yet</h5>
                    <p class="text-muted">You haven't submitted any reviews yet.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Update bus_id when booking is selected
    const bookingSelect = document.querySelector('select[name="booking_id"]');
    const busIdInput = document.getElementById('bus_id');
    
    bookingSelect.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        busIdInput.value = selectedOption.dataset.busId;
    });
});
</script>

<?php include '../includes/footer.php'; ?>