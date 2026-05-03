<?php
// Start session and include configuration
session_start();
require_once 'includes/config.php';
require_once 'includes/functions.php';

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get featured buses from database
$featured_buses = getFeaturedBuses(4);
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="SmartBusLink - Book private buses across Sri Lanka or manage your fleet with our complete operator solution">
    <title>SmartBusLink | All Private Bus Booking & Operator Management</title>

    <!-- Favicon -->
    <link rel="icon" href="/assets/images/favicon.ico" type="image/x-icon">
    <link rel="apple-touch-icon" href="/assets/images/apple-touch-icon.png">

    <!-- Preload critical assets -->
    <link rel="preload" href="/assets/css/styles.css" as="style">
    <link rel="preload" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" as="style">

    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link href="/assets/css/styles.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="d-flex flex-column min-vh-100">
    <!-- Navigation -->
    <?php include __DIR__ . '/includes/navbar.php'; ?>

    <!-- Hero Section -->
<section class="hero-section py-5 position-relative bg-light">
    <div class="container">
        <div class="row align-items-center">

            <!-- Text and Form -->
            <div class="col-lg-6 order-lg-1 order-2">
                <h1 class="display-4 fw-bold mb-3 animate-pop-in">
                    Travel Smarter with <span class="text-primary">SmartBusLink</span>
                </h1>
                <p class="lead mb-4 animate-pop-in" style="animation-delay: 0.2s">
                    Book Comfortable Private Buses Across Sri Lanka with Our Seamless Booking Platform
                </p>

                <!-- Search Form -->
                <form action="/bus-booking-website/passenger/search-results.php" method="GET"
                      class="p-4 bg-white shadow rounded-4 border mt-4 animate-fade-in" id="busSearchForm">
                    
                    <!-- CSRF Token -->
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                    <div class="row g-3 align-items-end">
                        <!-- From -->
                        <div class="col-md-6 col-lg-3">
                            <label for="from" class="form-label fw-semibold">From</label>
                            <div class="input-group has-validation">
                                <span class="input-group-text bg-white border-end-0">
                                    <i class="bi bi-geo-alt-fill text-primary"></i>
                                </span>
                                <input type="text" 
                                       class="form-control border-start-0" 
                                       name="from" id="from"
                                       placeholder="Colombo"
                                       required autocomplete="off"
                                       list="cityList">
                                <div class="invalid-feedback">Please select a departure city</div>
                            </div>
                        </div>

                        <!-- To -->
                        <div class="col-md-6 col-lg-3">
                            <label for="to" class="form-label fw-semibold">To</label>
                            <div class="input-group has-validation">
                                <span class="input-group-text bg-white border-end-0">
                                    <i class="bi bi-geo-alt-fill text-success"></i>
                                </span>
                                <input type="text" 
                                       class="form-control border-start-0" 
                                       name="to" id="to"
                                       placeholder="Kandy"
                                       required autocomplete="off"
                                       list="cityList">
                                <div class="invalid-feedback">Please select a destination city</div>
                            </div>
                        </div>

                        <!-- Date -->
                        <div class="col-md-6 col-lg-3">
                            <label for="date" class="form-label fw-semibold">Date</label>
                            <div class="input-group has-validation">
                                <span class="input-group-text bg-white border-end-0">
                                    <i class="bi bi-calendar-check text-danger"></i>
                                </span>
                                <input type="date" 
                                       class="form-control border-start-0"
                                       name="date" id="date"
                                       value="<?= date('Y-m-d') ?>"
                                       min="<?= date('Y-m-d') ?>"
                                       required>
                                <div class="invalid-feedback">Please select a valid date</div>
                            </div>
                        </div>

                        <!-- Submit Button -->
                        <div class="col-md-6 col-lg-3 d-grid">
                            <button class="btn btn-primary btn-lg" type="submit" id="searchButton">
                                <span class="d-flex align-items-center justify-content-center">
                                    <i class="bi bi-search me-2"></i> Search
                                </span>
                            </button>
                        </div>
                    </div>

                    <!-- Datalist -->
                    <datalist id="cityList">
                        <?php foreach ($cities as $city): ?>
                            <option value="<?= htmlspecialchars($city) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </form>
            </div>

            <!-- Hero Image -->
            <div class="col-lg-6 order-lg-2 order-1 text-center">
                <img src="assets/images/bus.svg" 
                     alt="Bus Illustration" 
                     class="img-fluid floating"
                     style="max-height: 400px;">
            </div>
        </div>
    </div>
</section>


    <!-- Featured Buses -->
<section class="featured-buses py-5 bg-light">
    <div class="container">
        <div class="section-header mb-5 text-center animate-fade-in">
            <h2 class="fw-bold display-6">🌟 Popular Routes</h2>
            <p class="text-muted fs-6">Most booked routes this month</p>
        </div>

        <div class="row g-4">
            <?php foreach ($featured_buses as $bus): ?>
            <div class="col-lg-3 col-md-6 animate-fade-up">
                <div class="card h-100 border-0 shadow-sm rounded-4 bus-card hover-shadow">
                    <!-- Optional Bus Image -->
                    <div class="position-relative" style="height: 180px; overflow: hidden;">
                        <?php if (!empty($bus['image_url'])): ?>
                            <img src="<?= htmlspecialchars($bus['image_url']) ?>" class="w-100 h-100 object-fit-cover" alt="<?= htmlspecialchars($bus['name']) ?>">
                        <?php else: ?>
                            <div class="d-flex align-items-center justify-content-center bg-secondary bg-opacity-10 w-100 h-100">
                                <i class="bi bi-bus-front-fill text-muted fs-1"></i>
                            </div>
                        <?php endif; ?>

                        <span class="badge bg-success position-absolute top-0 end-0 m-2 rounded-pill px-3 py-1 shadow-sm">
                            <?= rand(4, 5) ?>.<?= rand(0, 9) ?> <i class="bi bi-star-fill ms-1"></i>
                        </span>
                    </div>

                    <div class="card-body p-3">
                        <h5 class="card-title text-dark fw-semibold"><?= htmlspecialchars($bus['name']); ?></h5>
                        <p class="card-text small text-muted mb-2">
                            <i class="bi bi-geo-alt-fill me-1"></i> <?= isset($bus['route']) ? htmlspecialchars($bus['route']) : 'Route not available'; ?><br>
                            <i class="bi bi-clock me-1"></i> <?= htmlspecialchars($bus['departure_time']); ?><br>
                            <i class="bi bi-people-fill me-1"></i> <?= rand(70, 95) ?>% Seats Booked
                        </p>
                    </div>

                    <div class="card-footer bg-white border-0 px-3 pb-3 d-flex justify-content-between align-items-center">
                        <span class="text-primary fw-bold fs-6">LKR <?= number_format($bus['price'], 2); ?></span>
                        <a href="/bus-booking-website/passenger/booking.php?bus_id=<?= isset($bus['id']) ? urlencode($bus['id']) : ''; ?>" class="btn btn-sm btn-primary rounded-pill">Book Now</a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>        
    </div>
</section>

    <!-- How It Works -->
<section class="featured-buses py-5 custom-bg position-relative">
    <div class="container">
        <div class="section-header mb-5 text-center">
            <h2 class="fw-bold animate__animated animate__fadeInDown">How <span class="text-primary">SmartBusLink</span> Works</h2>
            <p class="text-muted animate__animated animate__fadeIn animate__delay-1s">Book your bus in just 3 simple steps</p>
        </div>

        <div class="row g-4 text-center">
            <!-- Step 1 -->
            <div class="col-md-4" data-aos="fade-up" data-aos-delay="100">
                <div class="p-4 bg-white rounded-4 shadow feature-card h-100 transition-transform hover-up">
                    <div class="feature-icon mb-3 fs-1 text-primary">
                        <i class="bi bi-search-heart-fill"></i>
                    </div>
                    <h4 class="fw-semibold">Search</h4>
                    <p class="text-muted">Find buses for your route and date using our smart filters.</p>
                </div>
            </div>

            <!-- Step 2 -->
            <div class="col-md-4" data-aos="fade-up" data-aos-delay="200">
                <div class="p-4 bg-white rounded-4 shadow feature-card h-100 transition-transform hover-up">
                    <div class="feature-icon mb-3 fs-1 text-warning">
                        <i class="bi bi-ticket-perforated-fill"></i>
                    </div>
                    <h4 class="fw-semibold">Book</h4>
                    <p class="text-muted">Choose your bus and seats with our interactive seat layout.</p>
                </div>
            </div>

            <!-- Step 3 -->
            <div class="col-md-4" data-aos="fade-up" data-aos-delay="300">
                <div class="p-4 bg-white rounded-4 shadow feature-card h-100 transition-transform hover-up">
                    <div class="feature-icon mb-3 fs-1 text-success">
                        <i class="bi bi-shield-check"></i>
                    </div>
                    <h4 class="fw-semibold">Pay & Travel</h4>
                    <p class="text-muted">Pay securely and get your e-ticket instantly via email & SMS.</p>
                </div>
            </div>
        </div>
    </div>
</section>


    <!-- Testimonials -->
<section class="testimonials py-5 bg-light">
    <div class="container">
        <div class="section-header mb-5 text-center">
            <h2 class="fw-bold">What Our Travelers Say</h2>
            <p class="text-muted">Trusted by thousands of passengers</p>
        </div>
        <div class="row g-4">
            <!-- Testimonial 1 -->
            <div class="col-md-4">
                <div class="p-4 bg-white rounded-4 shadow-sm h-100 animate-fade-up">
                    <div class="d-flex align-items-center mb-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center me-3 text-white"
                             style="width: 60px; height: 60px; background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%); font-weight: 600;">
                            AB
                        </div>
                        <div>
                            <h6 class="mb-0">Alex Brown</h6>
                            <small class="text-muted">Top Reviewer</small>
                        </div>
                    </div>
                    <p class="mb-3 text-muted">"The easiest bus booking experience I've had. Got a great deal on a Colombo to Kandy trip with comfortable seats."</p>
                    <div class="text-warning small">
                        <i class="bi bi-star-fill"></i><i class="bi bi-star-fill"></i>
                        <i class="bi bi-star-fill"></i><i class="bi bi-star-fill"></i>
                        <i class="bi bi-star-fill"></i>
                    </div>
                </div>
            </div>

            <!-- Testimonial 2 -->
            <div class="col-md-4">
                <div class="p-4 bg-white rounded-4 shadow-sm h-100 animate-fade-up">
                    <div class="d-flex align-items-center mb-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center me-3 text-white"
                             style="width: 60px; height: 60px; background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%); font-weight: 600;">
                            NF
                        </div>
                        <div>
                            <h6 class="mb-0">Nimali Fernando</h6>
                            <small class="text-muted">Frequent Rider</small>
                        </div>
                    </div>
                    <p class="mb-3 text-muted">"Love the real-time tracking feature. Could see exactly when my bus would arrive. Very reliable service."</p>
                    <div class="text-warning small">
                        <i class="bi bi-star-fill"></i><i class="bi bi-star-fill"></i>
                        <i class="bi bi-star-fill"></i><i class="bi bi-star-fill"></i>
                        <i class="bi bi-star-half"></i>
                    </div>
                </div>
            </div>

            <!-- Testimonial 3 -->
            <div class="col-md-4">
                <div class="p-4 bg-white rounded-4 shadow-sm h-100 animate-fade-up">
                    <div class="d-flex align-items-center mb-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center me-3 text-white"
                             style="width: 60px; height: 60px; background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%); font-weight: 600;">
                            SP
                        </div>
                        <div>
                            <h6 class="mb-0">Sanjay Patel</h6>
                            <small class="text-muted">Verified Passenger</small>
                        </div>
                    </div>
                    <p class="mb-3 text-muted">"As a frequent traveler, I appreciate the saved passenger details feature. Makes booking so much faster."</p>
                    <div class="text-warning small">
                        <i class="bi bi-star-fill"></i><i class="bi bi-star-fill"></i>
                        <i class="bi bi-star-fill"></i><i class="bi bi-star-fill"></i>
                        <i class="bi bi-star"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>



    <!-- Operator Call to Action -->
    <section class="operator-cta py-5">
    <div class="container">
        <div class="row align-items-center justify-content-between">
            <div class="col-lg-7 mb-4 mb-lg-0">
                <h2 class="fw-bold mb-3">Are You a Bus Operator?</h2>
                <p class="lead text-muted mb-4">Join <strong>SmartBusLink</strong> to manage your fleet, bookings, and payments — all in one place.</p>
                <ul class="list-unstyled">
                    <li class="mb-2 d-flex align-items-center">
                        <i class="bi bi-check-circle-fill text-success fs-5 me-2"></i>
                        <span>Increase your bus occupancy</span>
                    </li>
                    <li class="mb-2 d-flex align-items-center">
                        <i class="bi bi-check-circle-fill text-success fs-5 me-2"></i>
                        <span>Manage schedules and routes easily</span>
                    </li>
                    <li class="mb-2 d-flex align-items-center">
                        <i class="bi bi-check-circle-fill text-success fs-5 me-2"></i>
                        <span>Get paid faster with our secure system</span>
                    </li>
                </ul>
            </div>
            <div class="col-lg-5 text-lg-end text-center">
    <a href="/bus-booking-website/request-access.php" class="btn btn-primary btn-lg px-5 py-3 rounded-pill shadow-sm animate__animated animate__pulse animate__infinite">
        <i class="bi bi-bus-front me-2"></i> Register Your Bus Fleet
    </a>
</div>
        </div>
    </div>
</section>


    <!-- Footer -->
    <?php include __DIR__ . '/includes/footer.php'; ?>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <script src="https://unpkg.com/scrollreveal"></script>
    <script src="/assets/js/main.js"></script>
    
    <script>
        // Simple ScrollReveal animation
        ScrollReveal().reveal('.hero-section, .section-header', { 
            delay: 200,
            origin: 'top',
            distance: '20px',
            easing: 'ease-in-out'
        });
        
        ScrollReveal().reveal('.bus-card, .feature-card', { 
            delay: 300,
            interval: 100,
            origin: 'bottom',
            distance: '20px'
        });
    </script>
</body>
</html>