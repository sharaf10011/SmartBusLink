<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('BASE_URL')) {
    define('BASE_URL', 'http://localhost/smartbuslink');
}

// Optional: Redirect non-operator users
if (!isset($_SESSION['loggedin']) || $_SESSION['user_type'] !== 'operator') {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

if (!isset($pageTitle)) {
    $pageTitle = 'Operator Panel';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?= htmlspecialchars($pageTitle) ?> | SmartBusLink Operator</title>

    <!-- Bootstrap & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet" />
    <link href="<?= BASE_URL ?>/assets/css/styles.css" rel="stylesheet" />

    <style>
        body { padding-top: 70px; }

        .bg-operator-gradient {
            background: linear-gradient(to right, #155799,rgb(29, 7, 79));
        }

        .nav-link {
            color: #fff !important;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .nav-link:hover {
            color: #ffc107 !important;
            transform: translateY(-2px);
        }

        .nav-link.active::after {
            content: '';
            display: block;
            height: 2px;
            background: #ffc107;
            margin-top: 4px;
            width: 100%;
            transition: width 0.4s ease;
        }

        .dropdown-menu {
            border-radius: 0.5rem;
            box-shadow: 0 0.75rem 1.5rem rgba(0, 0, 0, 0.1);
            opacity: 0;
            transform: translateY(10px);
            transition: all 0.3s ease-in-out;
            visibility: hidden;
        }

        .dropdown:hover .dropdown-menu {
            opacity: 1;
            transform: translateY(0px);
            visibility: visible;
        }
    </style>
</head>
<body>

<!-- Operator Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-operator-gradient shadow-sm fixed-top">
    <div class="container">
        <a class="navbar-brand fw-bold d-flex align-items-center" href="<?= BASE_URL ?>/operator/dashboard.php">
            <i class="bi bi-truck-front-fill me-2 text-warning fs-4"></i>
            <span class="fs-5">SmartBusLink</span>
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarOperator"
                aria-controls="navbarOperator" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarOperator">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], 'dashboard.php') ? 'active' : '' ?>" href="<?= BASE_URL ?>/operator/dashboard.php">
                        <i class="bi bi-house-fill"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], 'manage-bus.php') ? 'active' : '' ?>" href="<?= BASE_URL ?>/operator/manage-bus.php">
                        <i class="bi bi-bus-front-fill"></i> Busses
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], 'schedule-trip.php') ? 'active' : '' ?>" href="<?= BASE_URL ?>/operator/schedule-trip.php">
                        <i class="bi bi-calendar-event-fill"></i> Schedules
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], 'booking-list.php') ? 'active' : '' ?>" href="<?= BASE_URL ?>/operator/booking-list.php">
                        <i class="bi bi-journal-check"></i> Bookings
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], 'revenue.php') ? 'active' : '' ?>" href="<?= BASE_URL ?>/operator/revenue.php">
                        <i class="bi bi-graph-up"></i> Revenue
                    </a>
                </li>
                <li class="nav-item">
    <a class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], 'block-seats.php') ? 'active' : '' ?>" href="<?= BASE_URL ?>/operator/block-seats.php">
        <i class="bi bi-lock-fill"></i> Block_Seats
    </a>
</li>  
            </ul>

            <div class="d-flex align-items-center gap-2">
                <?php if (isset($_SESSION['username'])): ?>
                    <div class="dropdown">
                        <button class="btn btn-outline-light dropdown-toggle d-flex align-items-center gap-2"
                                id="operatorDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-person-circle"></i>
                            <span class="d-none d-md-inline"><?= htmlspecialchars($_SESSION['username']) ?></span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="operatorDropdown">
                            <li><a class="dropdown-item" href="<?= BASE_URL ?>/operator/settings.php"><i class="bi bi-gear me-2"></i>Profile</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="<?= BASE_URL ?>/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
