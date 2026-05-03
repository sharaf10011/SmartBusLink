<!-- Bootstrap 5 + Animate.css + FontAwesome -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"/>

<!-- Custom Styles -->
<style>
    .card-header {
        background: linear-gradient(90deg, #4a6cf7, #5b8def);
        border-radius: 1rem 1rem 0 0;
    }

    .form-control:focus {
        box-shadow: 0 0 0 0.15rem rgba(74, 108, 247, 0.25);
        border-color: #4a6cf7;
    }

    .btn-primary {
        background: linear-gradient(to right, #4a6cf7, #5b8def);
        border: none;
    }

    .btn-outline-secondary:hover {
        background-color: #e4e6eb;
    }

    /* Enhanced Toggle Switch */
    .custom-toggle {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 16px;
        background-color: #f8f9fa;
        border-radius: 12px;
        width: fit-content;
        transition: all 0.3s ease;
    }

    .custom-toggle:hover {
        background-color: #f1f3f5;
    }

    .toggle-switch {
        position: relative;
        display: inline-block;
        width: 52px;
        height: 28px;
    }

    .toggle-switch input {
        opacity: 0;
        width: 0;
        height: 0;
    }

    .toggle-slider {
        position: absolute;
        cursor: pointer;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: #e9ecef;
        transition: .4s;
        border-radius: 34px;
    }

    .toggle-slider:before {
        position: absolute;
        content: "";
        height: 20px;
        width: 20px;
        left: 4px;
        bottom: 4px;
        background-color: white;
        transition: .4s;
        border-radius: 50%;
        box-shadow: 0 1px 3px rgba(0,0,0,0.2);
    }

    input:checked + .toggle-slider {
        background: linear-gradient(90deg, #4a6cf7, #5b8def);
    }

    input:checked + .toggle-slider:before {
        transform: translateX(24px);
    }

    .toggle-label {
        font-weight: 600;
        color: #212529;
        font-size: 1rem;
        margin-bottom: 0;
    }

    .toggle-status {
        font-size: 0.75rem;
        font-weight: 600;
        padding: 4px 8px;
        border-radius: 4px;
        margin-left: 8px;
        background-color: #e9ecef;
        color: #495057;
        transition: all 0.3s ease;
    }

    input:checked ~ .toggle-status {
        background-color: rgba(74, 108, 247, 0.1);
        color: #4a6cf7;
    }

    /* Enhanced input groups */
    .input-group-text {
        transition: all 0.3s ease;
    }

    .input-group:focus-within .input-group-text {
        background-color: #e9ecef;
    }
</style>

<div class="container mt-5 animate__animated animate__fadeInUp">
    <div class="row justify-content-center">
        <div class="col-lg-10 col-xl-9">
            <div class="card shadow rounded-4 border-0">
                <div class="card-header text-white py-3">
                    <h4 class="mb-0">
                        <i class="fas fa-<?= $action === 'create' ? 'plus-circle' : 'edit' ?> me-2"></i>
                        <?= $action === 'create' ? 'Add New Route' : 'Edit Route' ?>
                    </h4>
                </div>
                <div class="card-body p-4">
                    <form method="post" action="routes.php?action=<?= escape($action) ?><?= isset($route_id) ? '&id=' . escape($route_id) : '' ?>">
                        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">

                        <!-- Origin Fields -->
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="origin_city" class="form-label fw-semibold">Origin City *</label>
                                <div class="input-group shadow-sm">
                                    <span class="input-group-text bg-light"><i class="fas fa-map-marker-alt text-danger"></i></span>
                                    <input type="text" class="form-control" id="origin_city" name="origin_city"
                                           value="<?= escape($route_data['origin_city'] ?? '') ?>" required maxlength="50"
                                           placeholder="Enter origin city">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="origin_terminal" class="form-label fw-semibold">Origin Terminal</label>
                                <div class="input-group shadow-sm">
                                    <span class="input-group-text bg-light"><i class="fas fa-building text-muted"></i></span>
                                    <input type="text" class="form-control" id="origin_terminal" name="origin_terminal"
                                           value="<?= escape($route_data['origin_terminal'] ?? '') ?>" maxlength="100"
                                           placeholder="Enter terminal name">
                                </div>
                            </div>
                        </div>

                        <!-- Destination Fields -->
                        <div class="row g-3 mt-3">
                            <div class="col-md-6">
                                <label for="destination_city" class="form-label fw-semibold">Destination City *</label>
                                <div class="input-group shadow-sm">
                                    <span class="input-group-text bg-light"><i class="fas fa-flag-checkered text-success"></i></span>
                                    <input type="text" class="form-control" id="destination_city" name="destination_city"
                                           value="<?= escape($route_data['destination_city'] ?? '') ?>" required maxlength="50"
                                           placeholder="Enter destination city">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="destination_terminal" class="form-label fw-semibold">Destination Terminal</label>
                                <div class="input-group shadow-sm">
                                    <span class="input-group-text bg-light"><i class="fas fa-building text-muted"></i></span>
                                    <input type="text" class="form-control" id="destination_terminal" name="destination_terminal"
                                           value="<?= escape($route_data['destination_terminal'] ?? '') ?>" maxlength="100"
                                           placeholder="Enter terminal name">
                                </div>
                            </div>
                        </div>

                        <!-- Distance & Duration -->
                        <div class="row g-3 mt-3">
                            <div class="col-md-6">
                                <label for="distance_km" class="form-label fw-semibold">Distance (km)</label>
                                <div class="input-group shadow-sm">
                                    <span class="input-group-text bg-light"><i class="fas fa-road text-muted"></i></span>
                                    <input type="number" step="0.01" min="0" class="form-control" id="distance_km" name="distance_km"
                                           value="<?= escape($route_data['distance_km'] ?? '') ?>"
                                           placeholder="Enter distance in kilometers">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="estimated_duration_min" class="form-label fw-semibold">Estimated Duration (minutes)</label>
                                <div class="input-group shadow-sm">
                                    <span class="input-group-text bg-light"><i class="far fa-clock text-muted"></i></span>
                                    <input type="number" min="0" class="form-control" id="estimated_duration_min" name="estimated_duration_min"
                                           value="<?= escape($route_data['estimated_duration_min'] ?? '') ?>"
                                           placeholder="Enter duration in minutes">
                                </div>
                            </div>
                        </div>

                        <!-- Enhanced Active Route Toggle -->
                        <div class="mt-4">
                            <label class="custom-toggle">
                                <div class="toggle-switch">
                                    <input type="checkbox" id="is_active" name="is_active"
                                           <?= isset($route_data['is_active']) && $route_data['is_active'] ? 'checked' : '' ?>>
                                    <span class="toggle-slider"></span>
                                </div>
                                <span class="toggle-label">Active Route</span>
                                <span class="toggle-status"><?= isset($route_data['is_active']) && $route_data['is_active'] ? 'ACTIVE' : 'INACTIVE' ?></span>
                            </label>
                        </div>

                        <!-- Action Buttons -->
                        <div class="d-flex justify-content-between mt-5">
                            <a href="routes.php" class="btn btn-outline-secondary px-4 py-2 rounded-pill">
                                <i class="fas fa-arrow-left me-2"></i>Back to List
                            </a>
                            <button type="submit" class="btn btn-primary px-5 py-2 rounded-pill shadow-sm">
                                <i class="fas fa-save me-2"></i>Save Route
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Toggle switch interaction
    document.getElementById('is_active').addEventListener('change', function() {
        const statusElement = document.querySelector('.toggle-status');
        if (this.checked) {
            statusElement.textContent = 'ACTIVE';
            statusElement.style.backgroundColor = 'rgba(74, 108, 247, 0.1)';
            statusElement.style.color = '#4a6cf7';
        } else {
            statusElement.textContent = 'INACTIVE';
            statusElement.style.backgroundColor = '#e9ecef';
            statusElement.style.color = '#495057';
        }
    });
</script>