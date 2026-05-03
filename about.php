<?php
// Start session and include configuration
session_start();
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Set page title
$page_title = "About SmartBusLink - Sri Lanka's Leading Private Bus Booking Platform";

// Generate CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Learn about SmartBusLink - Sri Lanka's premier private bus booking platform connecting passengers with reliable bus operators across the island.">
    <title><?= htmlspecialchars($page_title) ?></title>

    <!-- Favicon -->
    <link rel="icon" href="https://via.placeholder.com/32" type="image/x-icon">
    <link rel="apple-touch-icon" href="https://via.placeholder.com/180">

    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link href="/assets/css/styles.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .about-hero {
            background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%);
        }
        .icon-wrapper {
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .team-member-img {
            height: 250px;
            object-fit: cover;
            width: 100%;
        }
    </style>
</head>
<body class="d-flex flex-column min-vh-100">
    <!-- Navigation -->
    <?php include __DIR__ . '/includes/navbar.php'; ?>

    <!-- Hero Section -->
    <section class="about-hero py-5 text-white">
        <div class="container py-5">
            <div class="row align-items-center">
                <div class="col-lg-6">
                    <h1 class="display-4 fw-bold mb-3">Our Story</h1>
                    <p class="lead mb-4">Revolutionizing private bus travel in Sri Lanka through technology and innovation.</p>
                    <div class="d-flex gap-3">
                        <a href="#mission" class="btn btn-light btn-lg rounded-pill px-4">Our Mission</a>
                        <a href="#team" class="btn btn-outline-light btn-lg rounded-pill px-4">Meet the Team</a>
                    </div>
                </div>
                <div class="col-lg-6 text-center">
                    <img src="assets/images/bus.svg" alt="About SmartBusLink" class="img-fluid rounded-4 shadow" style="max-height: 300px;">
                </div>
            </div>
        </div>
    </section>

    <!-- About Content -->
    <section class="py-5" id="about">
        <div class="container py-4">
            <div class="row g-5 align-items-center">
                <div class="col-lg-6">
                    <h2 class="fw-bold mb-4">Who We Are</h2>
                    <p class="lead text-muted">SmartBusLink is Sri Lanka's fastest-growing private bus booking platform, connecting passengers with reliable bus operators across the island.</p>
                    <p>Founded in 2020, we recognized the challenges faced by both passengers and bus operators in Sri Lanka's private transport sector. Passengers struggled to find reliable booking options, while operators faced difficulties in managing their fleets and filling seats efficiently.</p>
                    <p>Our platform bridges this gap by providing a seamless booking experience for travelers while offering bus operators powerful tools to manage their operations.</p>
                </div>
                <div class="col-lg-6">
                    <div class="card border-0 shadow-lg rounded-4 overflow-hidden">
                        <div class="card-body p-0">
                            <div class="ratio ratio-16x9">
                                <iframe class="embed-responsive-item" src="https://www.youtube.com/embed/dQw4w9WgXcQ" allowfullscreen></iframe>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Mission Section -->
    <section class="py-5 bg-light" id="mission">
        <div class="container py-4">
            <div class="text-center mb-5">
                <h2 class="fw-bold display-5 mb-3">Our Mission & Values</h2>
                <p class="text-muted mx-auto" style="max-width: 700px;">Guiding principles that drive everything we do at SmartBusLink</p>
            </div>
            
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="p-4 bg-white rounded-4 shadow-sm h-100 text-center">
                        <div class="icon-wrapper bg-primary bg-opacity-10 text-primary rounded-circle p-3 mb-3 mx-auto" style="width: 70px; height: 70px;">
                            <i class="bi bi-speedometer2 fs-3"></i>
                        </div>
                        <h4 class="fw-semibold">Efficiency</h4>
                        <p class="text-muted">Streamlining bus travel to save time and reduce hassle for both passengers and operators.</p>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="p-4 bg-white rounded-4 shadow-sm h-100 text-center">
                        <div class="icon-wrapper bg-success bg-opacity-10 text-success rounded-circle p-3 mb-3 mx-auto" style="width: 70px; height: 70px;">
                            <i class="bi bi-shield-check fs-3"></i>
                        </div>
                        <h4 class="fw-semibold">Reliability</h4>
                        <p class="text-muted">Providing dependable services you can trust, with verified operators and real-time tracking.</p>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="p-4 bg-white rounded-4 shadow-sm h-100 text-center">
                        <div class="icon-wrapper bg-warning bg-opacity-10 text-warning rounded-circle p-3 mb-3 mx-auto" style="width: 70px; height: 70px;">
                            <i class="bi bi-people-fill fs-3"></i>
                        </div>
                        <h4 class="fw-semibold">Accessibility</h4>
                        <p class="text-muted">Making private bus travel accessible to everyone with affordable options across Sri Lanka.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Stats Section -->
    <section class="py-5 bg-primary text-white">
        <div class="container py-4">
            <div class="row g-4 text-center">
                <div class="col-md-3">
                    <div class="p-3">
                        <h2 class="display-4 fw-bold mb-2">50,000+</h2>
                        <p class="mb-0">Happy Passengers</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="p-3">
                        <h2 class="display-4 fw-bold mb-2">1,200+</h2>
                        <p class="mb-0">Bus Operators</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="p-3">
                        <h2 class="display-4 fw-bold mb-2">150+</h2>
                        <p class="mb-0">Routes Covered</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="p-3">
                        <h2 class="display-4 fw-bold mb-2">95%</h2>
                        <p class="mb-0">On-time Performance</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Team Section -->
    <section class="py-5" id="team">
        <div class="container py-4">
            <div class="text-center mb-5">
                <h2 class="fw-bold display-5 mb-3">Meet Our Leadership</h2>
                <p class="text-muted mx-auto" style="max-width: 700px;">The passionate team driving innovation in Sri Lanka's private transport sector</p>
            </div>
            
            <div class="row g-4 justify-content-center">
                <div class="col-lg-3 col-md-6">
                    <div class="card border-0 shadow-sm rounded-4 overflow-hidden h-100">
                        <img src="https://images.unsplash.com/photo-1560250097-0b93528c311a?ixlib=rb-1.2.1&auto=format&fit=crop&w=500&q=80" class="team-member-img" alt="CEO">
                        <div class="card-body text-center">
                            <h5 class="card-title mb-1">Rajitha Perera</h5>
                            <p class="text-muted small mb-3">Founder & CEO</p>
                            <div class="d-flex justify-content-center gap-2">
                                <a href="#" class="text-primary"><i class="bi bi-linkedin"></i></a>
                                <a href="#" class="text-primary"><i class="bi bi-twitter"></i></a>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-3 col-md-6">
                    <div class="card border-0 shadow-sm rounded-4 overflow-hidden h-100">
                        <img src="https://images.unsplash.com/photo-1573497019940-1c28c88b4f3e?ixlib=rb-1.2.1&auto=format&fit=crop&w=500&q=80" class="team-member-img" alt="CTO">
                        <div class="card-body text-center">
                            <h5 class="card-title mb-1">Sanjay Fernando</h5>
                            <p class="text-muted small mb-3">CTO</p>
                            <div class="d-flex justify-content-center gap-2">
                                <a href="#" class="text-primary"><i class="bi bi-linkedin"></i></a>
                                <a href="#" class="text-primary"><i class="bi bi-github"></i></a>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-3 col-md-6">
                    <div class="card border-0 shadow-sm rounded-4 overflow-hidden h-100">
                        <img src="https://images.unsplash.com/photo-1573496359142-b8d87734a5a2?ixlib=rb-1.2.1&auto=format&fit=crop&w=500&q=80" class="team-member-img" alt="CMO">
                        <div class="card-body text-center">
                            <h5 class="card-title mb-1">Nimali Silva</h5>
                            <p class="text-muted small mb-3">CMO</p>
                            <div class="d-flex justify-content-center gap-2">
                                <a href="#" class="text-primary"><i class="bi bi-linkedin"></i></a>
                                <a href="#" class="text-primary"><i class="bi bi-instagram"></i></a>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-3 col-md-6">
                    <div class="card border-0 shadow-sm rounded-4 overflow-hidden h-100">
                        <img src="https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?ixlib=rb-1.2.1&auto=format&fit=crop&w=500&q=80" class="team-member-img" alt="COO">
                        <div class="card-body text-center">
                            <h5 class="card-title mb-1">Kamal Bandara</h5>
                            <p class="text-muted small mb-3">COO</p>
                            <div class="d-flex justify-content-center gap-2">
                                <a href="#" class="text-primary"><i class="bi bi-linkedin"></i></a>
                                <a href="#" class="text-primary"><i class="bi bi-facebook"></i></a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="py-5 bg-light">
        <div class="container py-4">
            <div class="row justify-content-center">
                <div class="col-lg-8 text-center">
                    <h2 class="fw-bold mb-4">Ready to Experience Smarter Bus Travel?</h2>
                    <p class="lead text-muted mb-5">Join thousands of satisfied passengers who travel smarter with SmartBusLink</p>
                    <div class="d-flex gap-3 justify-content-center">
                        <a href="/bus-booking-website/passenger/register.php" class="btn btn-primary btn-lg px-4 rounded-pill">Book a Bus Now</a>
                        <a href="/bus-booking-website/request-access.php" class="btn btn-outline-primary btn-lg px-4 rounded-pill">Become an Operator</a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <?php include __DIR__ . '/includes/footer.php'; ?>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <script src="/assets/js/main.js"></script>
</body>
</html>