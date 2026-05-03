<?php 
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('BASE_URL')) {
    define('BASE_URL', 'http://localhost/smartbuslink');
}

if (!isset($pageTitle)) {
    $pageTitle = 'Admin Panel';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?= htmlspecialchars($pageTitle) ?> | SmartBusLink Admin</title>

    <!-- Bootstrap & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet" />
    <link href="<?= BASE_URL ?>/assets/css/styles.css" rel="stylesheet" />

    <!-- Custom Styles -->
    <style>
        body { padding-top: 70px; }

        /* Blue gradient background */
        .bg-blue-gradient {
            background: linear-gradient(to right,rgb(3, 52, 77),rgb(51, 18, 171));
        }

        /* Navbar brand */
        .navbar-brand span {
            letter-spacing: 0.5px;
        }

        /* Nav links */
        .nav-link {
            display: flex;
            align-items: center;
            gap: 5px;
            color: #fff !important;
            transition: all 0.3s ease;
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

        /* Dropdown styling */
        .dropdown-menu {
            border-radius: 0.5rem;
            box-shadow: 0 0.75rem 1.5rem rgba(0, 0, 0, 0.1);
            opacity: 0;
            transform: translateY(10px);
            transition: all 0.3s ease-in-out;
            display: block !important;
            visibility: hidden;
        }

        .dropdown:hover .dropdown-menu {
            opacity: 1;
            transform: translateY(0px);
            visibility: visible;
        }

        /* Button */
        .btn-outline-light:hover {
            background-color: rgba(255, 255, 255, 0.1);
            border-color: #ffc107;
            color: #ffc107;
        }

        /* Toggler animation */
        .navbar-toggler {
            transition: transform 0.3s ease;
        }
        .navbar-toggler.toggler-open {
            transform: rotate(90deg);
        }
    </style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-blue-gradient shadow-sm fixed-top" id="adminNavbar">
    <div class="container">
        <a class="navbar-brand fw-bold d-flex align-items-center" href="<?= BASE_URL ?>/admin/dashboard.php">
            <i class="bi bi-speedometer2 me-2 fs-4 text-warning"></i>
            <span class="fs-5">SmartBusLink</span>
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarAdmin"
                aria-controls="navbarAdmin" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarAdmin">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], 'dashboard.php') ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/dashboard.php">
                        <i class="bi bi-house-door-fill"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], 'manage_passengers.php') ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/manage_passengers.php">
                        <i class="bi bi-people-fill"></i> Passengers
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], 'manage-operators.php') ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/manage-operators.php">
                        <i class="bi bi-people-fill"></i> Operator
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], 'routes.php') ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/routes.php">
                        <i class="bi bi-map-fill"></i> Routes
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], 'reports.php') ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/reports.php">
                        <i class="bi bi-bar-chart-fill"></i> Reports
                    </a>
                </li>
            </ul>

            <div class="d-flex align-items-center gap-2 ms-lg-3">
                <?php if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] && $_SESSION['user_type'] === 'admin'): ?>
                    <div class="dropdown">
                        <button class="btn btn-outline-light dropdown-toggle d-flex align-items-center gap-2"
                                type="button" id="adminDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-person-circle"></i>
                            <span class="d-none d-md-inline"><?= htmlspecialchars($_SESSION['username']) ?></span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="adminDropdown">
                            <li>
                                <a class="dropdown-item" href="<?= BASE_URL ?>/admin/dashboard.php">
                                    <i class="bi bi-person me-2"></i>Profile
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item text-danger" href="<?= BASE_URL ?>/logout.php">
                                    <i class="bi bi-box-arrow-right me-2"></i>Logout
                                </a>
                            </li>
                        </ul>
                    </div>
                <?php else: ?>
                    <a href="<?= BASE_URL ?>/login.php" class="btn btn-outline-light rounded-pill">Login</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- UX Enhancements -->
<script>
document.addEventListener("DOMContentLoaded", function () {
    const navbarToggler = document.querySelector(".navbar-toggler");
    const navLinks = document.querySelectorAll(".nav-link");

    navLinks.forEach(link => {
        link.addEventListener("click", () => {
            const bsCollapse = bootstrap.Collapse.getInstance(document.getElementById('navbarAdmin'));
            if (bsCollapse && navbarToggler.offsetParent !== null) {
                bsCollapse.hide();
            }
        });
    });

    navbarToggler.addEventListener("click", () => {
        navbarToggler.classList.toggle("toggler-open");
    });
});
</script>

</body>
</html>
