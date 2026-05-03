<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Secure session handling
if (session_status() === PHP_SESSION_NONE) {
    session_start();
    session_regenerate_id(true);
}

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Initialize guest user if not logged in
if (!isset($_SESSION['user_id'])) {
    // Guest user initialization
    $_SESSION['guest_mode'] = true;
    $_SESSION['user_id'] = 'guest_' . bin2hex(random_bytes(8));
} elseif (strpos($_SESSION['user_id'], 'guest_') === 0) {
    // Existing guest session
    $_SESSION['guest_mode'] = true;
} else {
    // Logged-in user - ensure guest_mode is not set
    unset($_SESSION['guest_mode']);
}

// Validate and sanitize input parameters
$from = filter_input(INPUT_GET, 'from', FILTER_SANITIZE_STRING);
$to = filter_input(INPUT_GET, 'to', FILTER_SANITIZE_STRING);
$date = filter_input(INPUT_GET, 'date', FILTER_SANITIZE_STRING);

// Validate required parameters
if (!$from || !$to || !$date) {
    header("Location: /passenger/search-results.php?error=missing_parameters");
    exit();
}

// Validate date format
$dateObj = DateTime::createFromFormat('Y-m-d', $date);
if (!$dateObj) {
    header("Location: /passenger/search-results.php?error=invalid_date");
    exit();
}

// Check if date is in the past
if ($dateObj < new DateTime('today')) {
    header("Location: /passenger/search-results.php?error=past_date");
    exit();
}

// Get min and max prices for the search
$priceQuery = "SELECT MIN(price) as min_price, MAX(price) as max_price FROM schedules 
               WHERE DATE(departure_time) = ? AND is_active = 1";
$priceStmt = $conn->prepare($priceQuery);
$priceStmt->bind_param("s", $date);
$priceStmt->execute();
$priceResult = $priceStmt->get_result();
$priceRow = $priceResult->fetch_assoc();
$minPrice = $priceRow['min_price'] ?? 0;
$maxPrice = $priceRow['max_price'] ?? 10000;

// Prepare SQL with enhanced security
$sql = "SELECT 
    s.schedule_id, 
    b.bus_id,
    b.name AS bus_name,
    b.registration_number,
    b.type_id AS bus_type_id,
    bt.type_name,
    bt.has_ac,
    bt.has_wifi,
    bt.has_toilet,
    b.features AS amenities,
    r.route_id,
    r.origin_city AS origin_city,
    r.destination_city,
    s.departure_time, 
    s.arrival_time, 
    s.price, 
    s.available_seats,
    o.rating AS operator_rating,
    COUNT(rv.review_id) AS review_count,
    TIMESTAMPDIFF(MINUTE, s.departure_time, s.arrival_time) AS duration_minutes
FROM schedules s
JOIN buses b ON s.bus_id = b.bus_id
JOIN bus_types bt ON b.type_id = bt.type_id
JOIN routes r ON s.route_id = r.route_id
JOIN operators o ON b.operator_id = o.operator_id
LEFT JOIN reviews rv ON b.bus_id = rv.bus_id
WHERE r.origin_city LIKE ? 
  AND r.destination_city LIKE ? 
  AND DATE(s.departure_time) = ? 
  AND s.is_active = 1 
  AND b.status = 'active'
GROUP BY s.schedule_id
HAVING s.available_seats > 0
ORDER BY s.departure_time ASC, s.price ASC
LIMIT 50";

$stmt = $conn->prepare($sql);
$searchFrom = "%$from%";
$searchTo = "%$to%";
$stmt->bind_param("sss", $searchFrom, $searchTo, $date);
$stmt->execute();
$result = $stmt->get_result();

// Include header with CSRF token
include '../includes/header.php';
?>

<div class="container py-4">
    <!-- Search Header Section -->
<div class="search-header mb-4"><br>
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center">
        <div class="mb-3 mb-md-0"><br>
            <br>
            <h1 class="h3 mb-1">Available Buses</h1>
            <br>
            <div class="d-flex flex-wrap align-items-center text-muted">
                <span class="me-3">
                    <i class="bi bi-geo-alt-fill text-primary me-1"></i>
                    <?= htmlspecialchars($from) ?> to <?= htmlspecialchars($to) ?>
                </span>
                <span>
                    <i class="bi bi-calendar-check text-primary me-1"></i>
                    <?= date('D, M j, Y', strtotime($date)) ?>
                </span>
            </div>
        </div>
        
        <!-- Move guest mode alert here -->
        <?php if (isset($_SESSION['guest_mode'])): ?>
            <div class="alert alert-info mb-0">
                <i class="bi bi-info-circle"></i> You're browsing in guest mode. 
                <a href="register.php" class="alert-link">Register</a> to save your preferences.
            </div>
        <?php endif; ?>
    </div>
    <hr>
</div>

    <!-- Main Content Section -->
    <div class="row">
        <!-- Filters Column -->
        <div class="col-lg-3 mb-4">
            <div class="card shadow-sm sticky-top" style="top: 20px;">
                <div class="card-body">
                    <h5 class="card-title d-flex justify-content-between align-items-center">
                        <span>Filters</span>
                        <button id="resetFilters" class="btn btn-sm btn-link">Reset All</button>
                    </h5>
                    
                    <!-- Bus Type Filter -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Bus Type</label>
                        <div class="list-group">
                            <?php foreach (['all' => 'All Types', 'ac' => 'AC Buses', 'non-ac' => 'Non-AC', 'luxury' => 'Luxury', 'sleeper' => 'Sleeper'] as $value => $label): ?>
                                <button type="button" class="list-group-item list-group-item-action filter-option <?= $value === 'all' ? 'active' : '' ?>" 
                                        data-filter="bus-type" data-value="<?= $value ?>">
                                    <?= $label ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Price Range Filter -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Price Range (LKR)</label>
                        <div id="priceSlider" class="mb-2"></div>
                        <div class="d-flex justify-content-between">
                            <input type="number" class="form-control form-control-sm me-2" placeholder="Min" id="minPrice" min="0" value="<?= $minPrice ?>">
                            <input type="number" class="form-control form-control-sm" placeholder="Max" id="maxPrice" min="0" value="<?= $maxPrice ?>">
                        </div>
                    </div>
                    
                    <!-- Departure Time Filter -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Departure Time</label>
                        <div class="btn-group-vertical w-100" role="group">
                            <?php foreach ([
                                'all' => 'All Times',
                                'morning' => 'Morning (5AM-12PM)',
                                'afternoon' => 'Afternoon (12PM-5PM)',
                                'evening' => 'Evening (5PM-10PM)',
                                'night' => 'Night (10PM-5AM)'
                            ] as $value => $label): ?>
                                <button type="button" class="btn btn-outline-secondary btn-sm text-start filter-option <?= $value === 'all' ? 'active' : '' ?>" 
                                        data-filter="departure-time" data-value="<?= $value ?>">
                                    <?= $label ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Operator Rating Filter -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Operator Rating</label>
                        <div class="list-group">
                            <?php foreach ([0 => 'All Ratings', 3 => '3+ Stars', 4 => '4+ Stars'] as $value => $label): ?>
                                <button type="button" class="list-group-item list-group-item-action filter-option <?= $value === 0 ? 'active' : '' ?>" 
                                        data-filter="rating" data-value="<?= $value ?>">
                                    <?= $label ?>
                                    <?php if ($value > 0): ?>
                                        <span class="float-end">
                                            <?= str_repeat('<i class="bi bi-star-fill text-warning"></i>', $value) ?>
                                        </span>
                                    <?php endif; ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Amenities Filter -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Amenities</label>
                        <div class="list-group">
                            <?php foreach (['wifi' => 'WiFi', 'charging' => 'Charging Ports', 'toilet' => 'Toilet', 'tv' => 'TV', 'blanket' => 'Blankets'] as $value => $label): ?>
                                <button type="button" class="list-group-item list-group-item-action filter-option" 
                                        data-filter="amenity" data-value="<?= $value ?>">
                                    <?= $label ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="d-grid mt-4">
                        <button id="applyFilters" class="btn btn-primary" type="button">
                            Apply Filters
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Results Column -->
        <div class="col-lg-9">
            <!-- Sort Options -->
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="text-muted small">
                    Showing <span id="resultsCount"><?= $result->num_rows ?></span> of <?= $result->num_rows ?> results
                </div>
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" id="sortDropdown" data-bs-toggle="dropdown">
                        <i class="bi bi-sort-down"></i> Sort By
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item sort-option active" href="#" data-sort="departure-asc">Departure (Earliest First)</a></li>
                        <li><a class="dropdown-item sort-option" href="#" data-sort="departure-desc">Departure (Latest First)</a></li>
                        <li><a class="dropdown-item sort-option" href="#" data-sort="price-asc">Price (Low to High)</a></li>
                        <li><a class="dropdown-item sort-option" href="#" data-sort="price-desc">Price (High to Low)</a></li>
                        <li><a class="dropdown-item sort-option" href="#" data-sort="duration-asc">Duration (Shortest First)</a></li>
                        <li><a class="dropdown-item sort-option" href="#" data-sort="rating-desc">Rating (Highest First)</a></li>
                    </ul>
                </div>
            </div>
            
            <!-- Loading Indicator -->
            <div id="loadingIndicator" class="text-center py-5" style="display: none;">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="mt-2">Filtering results...</p>
            </div>
            
            <!-- Results Grid -->
            <div class="row g-3" id="busResults">
                <?php while ($row = $result->fetch_assoc()): ?>
                    <?php
                    $departureTime = strtotime($row['departure_time']);
                    $arrivalTime = strtotime($row['arrival_time']);
                    $durationSeconds = $arrivalTime - $departureTime;
                    $durationHours = floor($durationSeconds / 3600);
                    $durationMinutes = floor(($durationSeconds % 3600) / 60);
                    $duration = $durationHours . 'h ' . str_pad($durationMinutes, 2, '0', STR_PAD_LEFT) . 'm';

                    $amenities = array_map('trim', explode(',', $row['amenities']));
                    $busType = getBusTypeById($row['bus_type_id']);
                    ?>
                    
                    <div class="col-12 bus-card"
                         data-bus-id="<?= (int)$row['bus_id'] ?>"
                         data-bus-type="<?= $row['has_ac'] ? 'ac' : 'non-ac' ?>"
                         data-bus-category="<?= htmlspecialchars(strtolower($busType)) ?>"
                         data-price="<?= (float)$row['price'] ?>"
                         data-departure="<?= date('H:i', $departureTime) ?>"
                         data-duration="<?= ($durationHours * 60) + $durationMinutes ?>"
                         data-rating="<?= (float)$row['operator_rating'] ?>"
                         data-amenities="<?= htmlspecialchars(json_encode($amenities)) ?>"
                         role="article">
                        <div class="card shadow-sm h-100">
                            <div class="card-body">
                                <div class="row">
                                    <!-- Bus Info Column -->
                                    <div class="col-md-6 mb-3 mb-md-0">
                                        <div class="d-flex align-items-start">
                                            <div class="me-3">
                                                <div class="bus-type-badge bg-<?= $row['has_ac'] ? 'ac' : 'non-ac' ?>">
                                                    <?= $row['has_ac'] ? 'AC' : 'NON-AC' ?>
                                            </div>
                                            </div>
                                            <div>
                                                <h5 class="mb-1"><?= htmlspecialchars($row['bus_name']) ?></h5>
                                                <p class="text-muted small mb-1">
                                                    Reg: <?= htmlspecialchars($row['registration_number']) ?>
                                                </p>
                                                <p class="text-muted small mb-1">
                                                    Operated by: <?= htmlspecialchars($row['origin_city']) ?> → <?= htmlspecialchars($row['destination_city']) ?>
                                                </p>
                                                <span class="badge bg-warning text-dark small">
                                                    <?= number_format($row['operator_rating'], 1) ?> <i class="bi bi-star-fill"></i>
                                                    <small class="ms-1">(<?= $row['review_count'] ?> reviews)</small>
                                                </span>
                                                <div class="mt-2">
                                                    <?php foreach ($amenities as $amenity): ?>
                                                        <span class="badge bg-light text-dark me-1 mb-1">
                                                            <i class="bi bi-check-circle"></i> <?= htmlspecialchars($amenity) ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Schedule Timeline -->
                                    <div class="col-md-3 d-flex flex-column justify-content-center text-center border-start border-end">
                                        <div class="mb-1">
                                            <strong><?= date('H:i', $departureTime) ?></strong><br>
                                            <small class="text-muted">Departure</small>
                                        </div>
                                        <div class="my-2 text-primary">
                                            <i class="bi bi-arrow-down-up fs-5"></i><br>
                                            <small><?= $duration ?></small>
                                        </div>
                                        <div>
                                            <strong><?= date('H:i', $arrivalTime) ?></strong><br>
                                            <small class="text-muted">Arrival</small>
                                        </div>
                                    </div>

                                    <!-- Pricing & Actions -->
<div class="col-md-3 d-flex flex-column justify-content-between align-items-center text-center">
    <div>
        <h4 class="mb-1 text-primary">LKR <?= number_format($row['price'], 2) ?></h4>
        <p class="text-muted small mb-2"><?= $row['available_seats'] ?> seats left</p>
    </div>
    <?php if (!isset($_SESSION['user_id']) || $_SESSION['user_id'] === '' || strpos($_SESSION['user_id'], 'guest_') === 0): ?>
        <!-- For Guest Users - Direct link to register page -->
        <a href="register.php" class="btn btn-primary btn-sm w-100">
            Book Now
        </a>
    <?php else: ?>
        <!-- For Logged-in Users - Actual booking link -->
        <a href="seat-selection.php?schedule_id=<?= $row['schedule_id'] ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>" 
           class="btn btn-primary btn-sm w-100">
            Book Now
        </a>
    <?php endif; ?>
</div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
            
            <!-- No Results Message -->
            <div class="text-center py-5 d-none" id="noResultsMessage">
                <i class="bi bi-search display-4 text-muted mb-3"></i>
                <h4>No buses match your filters</h4>
                <p class="mb-3">Try adjusting your filters or search for a different date</p>
                
            </div>
            
            <!-- Pagination -->
            <?php if ($result->num_rows > 50): ?>
                <nav class="mt-4">
                    <ul class="pagination justify-content-center">
                        <li class="page-item disabled">
                            <a class="page-link" href="#" tabindex="-1">Previous</a>
                        </li>
                        <li class="page-item active"><a class="page-link" href="#">1</a></li>
                        <li class="page-item"><a class="page-link" href="#">2</a></li>
                        <li class="page-item"><a class="page-link" href="#">3</a></li>
                        <li class="page-item">
                            <a class="page-link" href="#">Next</a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- JavaScript -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    const busCards = Array.from(document.querySelectorAll('.bus-card'));
    const filters = {
        'bus-type': 'all',
        'price-min': parseFloat(document.getElementById('minPrice').value),
        'price-max': parseFloat(document.getElementById('maxPrice').value),
        'departure-time': 'all',
        'rating': 0,
        'amenities': []
    };
    const resultsCount = document.getElementById('resultsCount');
    const noResultsMessage = document.getElementById('noResultsMessage');
    const busResults = document.getElementById('busResults');
    const loadingIndicator = document.getElementById('loadingIndicator');

    // Helper: Departure Time Range Mapping
    function getDepartureRange(value) {
        switch (value) {
            case 'morning': return [5, 12];
            case 'afternoon': return [12, 17];
            case 'evening': return [17, 22];
            case 'night': return [22, 24.01, 0, 5]; // spans two ranges
            default: return [0, 24];
        }
    }

    // Filter UI buttons
    document.querySelectorAll('.filter-option').forEach(button => {
        button.addEventListener('click', function () {
            const type = this.dataset.filter;
            const value = this.dataset.value;

            if (type === 'amenity') {
                const amenities = filters.amenities;
                const index = amenities.indexOf(value);
                if (index > -1) {
                    amenities.splice(index, 1);
                    this.classList.remove('active');
                } else {
                    amenities.push(value);
                    this.classList.add('active');
                }
            } else {
                filters[type] = (type === 'rating') ? parseFloat(value) : value;
                document.querySelectorAll(`.filter-option[data-filter="${type}"]`)
                    .forEach(btn => btn.classList.remove('active'));
                this.classList.add('active');
            }
        });
    });

    // Reset Filters
    document.getElementById('resetFilters').addEventListener('click', () => {
        filters['bus-type'] = 'all';
        filters['departure-time'] = 'all';
        filters['rating'] = 0;
        filters['amenities'] = [];
        filters['price-min'] = parseFloat(document.getElementById('minPrice').defaultValue);
        filters['price-max'] = parseFloat(document.getElementById('maxPrice').defaultValue);
        document.querySelectorAll('.filter-option').forEach(btn => btn.classList.remove('active'));
        document.querySelectorAll('.filter-option[data-value="all"], .filter-option[data-value="0"]').forEach(btn => btn.classList.add('active'));
        document.getElementById('minPrice').value = filters['price-min'];
        document.getElementById('maxPrice').value = filters['price-max'];
        applyFilters();
    });

    // Apply Filters
    document.getElementById('applyFilters').addEventListener('click', () => {
        filters['price-min'] = parseFloat(document.getElementById('minPrice').value);
        filters['price-max'] = parseFloat(document.getElementById('maxPrice').value);
        applyFilters();
    });

    // Sort Options
    document.querySelectorAll('.sort-option').forEach(option => {
        option.addEventListener('click', function (e) {
            e.preventDefault();
            document.querySelectorAll('.sort-option').forEach(opt => opt.classList.remove('active'));
            this.classList.add('active');
            sortResults(this.dataset.sort);
        });
    });

    // Core Filtering Logic
    function applyFilters() {
        loadingIndicator.style.display = 'block';
        busResults.style.display = 'none';
        noResultsMessage.classList.add('d-none');

        setTimeout(() => {
            let visibleCount = 0;

            busCards.forEach(card => {
                const price = parseFloat(card.dataset.price);
                const type = card.dataset.busType;
                const depTime = parseFloat(card.dataset.departure.split(':')[0]);
                const rating = parseFloat(card.dataset.rating);
                const amenities = JSON.parse(card.dataset.amenities.toLowerCase());

                let match = true;

                if (filters['bus-type'] !== 'all' && type !== filters['bus-type']) match = false;
                if (price < filters['price-min'] || price > filters['price-max']) match = false;

                const timeRange = getDepartureRange(filters['departure-time']);
                if (filters['departure-time'] === 'night') {
                    if (!(depTime >= 22 || depTime < 5)) match = false;
                } else if (depTime < timeRange[0] || depTime >= timeRange[1]) {
                    if (filters['departure-time'] !== 'all') match = false;
                }

                if (rating < filters['rating']) match = false;

                for (const required of filters['amenities']) {
                    if (!amenities.includes(required)) {
                        match = false;
                        break;
                    }
                }

                card.style.display = match ? 'block' : 'none';
                if (match) visibleCount++;
            });

            resultsCount.textContent = visibleCount;

            if (visibleCount === 0) {
                noResultsMessage.classList.remove('d-none');
            }

            busResults.style.display = '';
            loadingIndicator.style.display = 'none';
        }, 300); // fake delay for smoother UX
    }

    // Sorting Function
    function sortResults(criteria) {
        const container = document.getElementById('busResults');
        const cards = Array.from(container.querySelectorAll('.bus-card'))
            .filter(card => card.style.display !== 'none');

        cards.sort((a, b) => {
            const valA = extractSortValue(a, criteria);
            const valB = extractSortValue(b, criteria);
            if (valA < valB) return -1;
            if (valA > valB) return 1;
            return 0;
        });

        if (criteria.endsWith('-desc')) cards.reverse();

        cards.forEach(card => container.appendChild(card));
    }

    function extractSortValue(card, criteria) {
        switch (criteria) {
            case 'departure-asc':
            case 'departure-desc':
                return parseFloat(card.dataset.departure.replace(':', '.'));
            case 'price-asc':
            case 'price-desc':
                return parseFloat(card.dataset.price);
            case 'duration-asc':
                return parseInt(card.dataset.duration);
            case 'rating-desc':
                return parseFloat(card.dataset.rating);
            default:
                return 0;
        }
    }

    // Initial Setup
    applyFilters();
});

</script>

<?php include '../includes/footer.php'; ?>