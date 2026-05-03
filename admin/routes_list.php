<?php
$routes = $routes ?? [];
$pagination = $pagination ?? ['current_page' => 1, 'total_pages' => 1, 'total_items' => 0];
?>

<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
    body {
        background: linear-gradient(135deg, #f7f9fc, #e3e8f0);
    }

    .card {
        border: none;
        border-radius: 1rem;
        animation: fadeInUp 0.5s ease;
    }

    .btn-primary, .btn-outline-primary {
        border-radius: 50px;
    }

    .table thead th {
        background: #f1f4f9;
        font-weight: 600;
    }

    .btn-group .btn {
        transition: all 0.3s ease;
    }

    .btn-group .btn:hover {
        transform: scale(1.1);
    }

    @keyframes fadeInUp {
        0% {
            opacity: 0;
            transform: translateY(30px);
        }
        100% {
            opacity: 1;
            transform: translateY(0);
        }
    }
</style>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4 animate__animated animate__fadeInDown">
        <h2 class="fw-bold text-dark">
            <i class="fas fa-map-marked-alt me-2 text-primary"></i>Manage Routes
        </h2>
        <a href="routes.php?action=create" class="btn btn-primary shadow-sm px-4 py-2">
            <i class="fas fa-plus me-1"></i> Add New Route
        </a>
    </div>

    <!-- Route Search Form with Optional Destination -->
<div class="card p-4 rounded-3 shadow-sm border-0" style="background: #fff;">
  <form method="get" action="routes.php" class="d-flex flex-wrap align-items-end gap-3" id="routeSearchForm" novalidate>
    <input type="hidden" name="action" value="list">

    <!-- Required Origin Field -->
    <div class="flex-grow-1" style="min-width: 200px;">
      <label for="originInput" class="form-label small text-muted mb-1 fw-semibold">From <span class="text-danger">*</span></label>
      <div class="input-group has-validation">
        <span class="input-group-text bg-light border-end-0">
          <i class="fas fa-map-marker-alt text-primary"></i>
        </span>
        <input type="search" id="originInput" name="origin" class="form-control border-start-0 ps-2" 
          placeholder="City, station or address" 
          value="<?= htmlspecialchars($_GET['origin'] ?? '') ?>"
          aria-label="Journey origin" 
          autocomplete="off"
          required>
        <div class="invalid-feedback">Please enter starting point</div>
      </div>
    </div>

    <!-- Optional Destination Field -->
    <div class="flex-grow-1" style="min-width: 200px;">
      <label for="destinationInput" class="form-label small text-muted mb-1 fw-semibold">To (optional)</label>
      <div class="input-group">
        <span class="input-group-text bg-light border-end-0">
          <i class="fas fa-flag-checkered text-muted"></i>
        </span>
        <input type="search" id="destinationInput" name="destination" class="form-control border-start-0 ps-2" 
          placeholder="City, station or address (optional)" 
          value="<?= htmlspecialchars($_GET['destination'] ?? '') ?>"
          aria-label="Journey destination" 
          autocomplete="off">
      </div>
    </div>

    <!-- Action Buttons -->
    <div class="d-flex gap-2" style="min-width: 200px;">
      <button class="btn btn-primary flex-grow-1 d-flex align-items-center justify-content-center gap-2 py-2" 
        type="submit">
        <i class="fas fa-search"></i>
        <span>Search</span>
      </button>

      <button class="btn btn-outline-secondary d-flex align-items-center justify-content-center gap-2 py-2" 
        type="button" 
        onclick="resetRouteSearch()"
        aria-label="Clear search form">
        <i class="fas fa-undo"></i>
        <span class="d-none d-sm-inline">Clear</span>
      </button>
    </div>
  </form>
</div><br>

<script>
function resetRouteSearch() {
  const form = document.getElementById('routeSearchForm');
  form.querySelector('#originInput').value = '';
  form.querySelector('#destinationInput').value = '';
  form.submit();
}

// Form validation (only for required fields)
document.getElementById('routeSearchForm').addEventListener('submit', function(event) {
  if (!this.checkValidity()) {
    event.preventDefault();
    event.stopPropagation();
  }
  this.classList.add('was-validated');
}, false);
</script>


    <!-- Routes Table -->
    <div class="card shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Origin</th>
                            <th>Destination</th>
                            <th>Distance</th>
                            <th>Duration</th>
                            <th>Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($routes['routes'])): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    <i class="fas fa-route fa-2x d-block mb-2"></i> No routes found
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($routes['routes'] as $route): ?>
                                <tr class="animate__animated animate__fadeInUp">
                                    <td class="text-muted"><?= escape($route['route_id']) ?></td>
                                    <td>
                                        <strong><?= escape($route['origin_city']) ?></strong><br>
                                        <small class="text-muted"><?= escape($route['origin_terminal']) ?></small>
                                    </td>
                                    <td>
                                        <strong><?= escape($route['destination_city']) ?></strong><br>
                                        <small class="text-muted"><?= escape($route['destination_terminal']) ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark">
                                            <i class="fas fa-road me-1"></i><?= number_format($route['distance_km'], 2) ?> km
                                        </span>
                                    </td>
                                    <td>
    <span class="badge bg-light text-dark">
        <i class="fas fa-clock me-1"></i>
        <?php
            $durationMin = (int)$route['estimated_duration_min'];
            $hours = floor($durationMin / 60);
            $minutes = $durationMin % 60;
            if ($hours > 0) {
                echo "{$hours}h {$minutes}m";
            } else {
                echo "{$minutes}m";
            }
        ?>
    </span>
</td>

                                    <td>
    <span class="badge rounded-pill bg-<?= $route['is_active'] ? 'success' : 'secondary' ?> px-3 py-2">
        <i class="fas fa-circle me-1 small <?= $route['is_active'] ? 'text-light' : 'text-muted' ?>"></i>
        <?= $route['is_active'] ? 'Active' : 'Inactive' ?>
    </span>
</td>

<td class="text-center">
    <div class="btn-group btn-group-sm" role="group" aria-label="Route Actions">
        <!-- Edit Button -->
        <a href="routes.php?action=edit&id=<?= escape($route['route_id']) ?>" 
           class="btn btn-outline-primary" 
           title="Edit">
            <i class="fas fa-edit"></i>
        </a>

        <!-- Delete Button -->
        <a href="routes.php?action=delete&id=<?= escape($route['route_id']) ?>" 
           class="btn btn-outline-danger" 
           title="Delete"
           onclick="return confirm('Are you sure you want to delete this route?')">
            <i class="fas fa-trash-alt"></i>
        </a>
    </div>
</td>

                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if (
    isset($routes['pagination']) &&
    is_array($routes['pagination']) &&
    $routes['pagination']['total_pages'] > 1
): ?>
    <nav aria-label="Page navigation">
        <ul class="pagination justify-content-center mt-3">
            <?php if ($routes['pagination']['current_page'] > 1): ?>
                <li class="page-item">
                    <a class="page-link" href="?action=list&page=<?= $routes['pagination']['current_page'] - 1 ?>&search=<?= escape($_GET['search'] ?? '') ?>">« Prev</a>
                </li>
            <?php endif; ?>
            <?php for ($i = 1; $i <= $routes['pagination']['total_pages']; $i++): ?>
                <li class="page-item <?= $i == $routes['pagination']['current_page'] ? 'active' : '' ?>">
                    <a class="page-link" href="?action=list&page=<?= $i ?>&search=<?= escape($_GET['search'] ?? '') ?>"><?= $i ?></a>
                </li>
            <?php endfor; ?>
            <?php if ($routes['pagination']['current_page'] < $routes['pagination']['total_pages']): ?>
                <li class="page-item">
                    <a class="page-link" href="?action=list&page=<?= $routes['pagination']['current_page'] + 1 ?>&search=<?= escape($_GET['search'] ?? '') ?>">Next »</a>
                </li>
            <?php endif; ?>
        </ul>
    </nav>
<?php endif; ?>
